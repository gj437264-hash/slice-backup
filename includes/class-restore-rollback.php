<?php
/**
 * The safety copy kept after a restore, so the restore can be undone.
 *
 * A restore never writes over the live database or plugin/theme folders while it
 * works: it fills staging tables and folders, then swaps them in at the end. The
 * tables and folders it replaced are kept (renamed) as a safety copy. "Undo" swaps
 * them back; "Delete safety copy" frees the space. Only the most recent restore's
 * copy is kept.
 *
 * Not covered: uploads, languages, WordPress core and root files, which a restore
 * copies over the existing files.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

// Swapping and dropping whole tables is the purpose of this class.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

/**
 * Undo and clean-up of a restore.
 */
final class RestoreRollback {
    /**
     * Start of the hidden folder name (inside plugins/, themes/) holding replaced folders.
     * Each restore adds a random suffix, so the safety copy never sits at a path anyone can
     * guess, and prepareHelperDir() blocks web access to it as well.
     */
    public const OLD_DIR_PREFIX = '.slice-backup-old-';

    /** Hidden folder (inside plugins/, themes/) holding a restore's new folders until the swap. */
    public const STAGING_DIR = '.slice-backup-staging';

    /** Hidden folder used briefly while undoing. */
    private const TRASH_DIR = '.slice-backup-trash';

    /** Site option holding the safety-copy record. */
    public const OPTION = 'slice_backup_rollback';

    /**
     * The safety-copy record lives in the database, not in a file: it is privileged data
     * (it names the tables and folders an undo would swap back) and belongs nowhere a
     * visitor could reach.
     *
     * @return array<string, mixed>|null
     */
    public static function load(): ?array {
        $record = get_site_option(self::OPTION, null);
        if ($record === null) {
            $record = self::importLegacyFile();
        }
        return is_array($record) ? $record : null;
    }

    /**
     * @param array<string, mixed> $record
     */
    public static function save(array $record): void {
        update_site_option(self::OPTION, $record);
    }

    /**
     * Forgets the safety copy (after an undo or a discard).
     */
    private static function forget(): void {
        update_site_option(self::OPTION, false);
    }

    /**
     * Version 1.1.0 and earlier kept this record in rollback.json in the backup folder.
     * Read it once, move it into the database and delete the file.
     *
     * @return array<string, mixed>|false
     */
    private static function importLegacyFile() {
        $record = false;
        try {
            $file = Storage::dir() . '/rollback.json';
            if (is_file($file)) {
                $data = json_decode((string) file_get_contents($file), true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                wp_delete_file($file);
                if (is_array($data)) {
                    $record = $data;
                }
            }
        } catch (\Throwable $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            // No backup folder (yet): there is nothing to carry over.
        }
        update_site_option(self::OPTION, $record);
        return $record;
    }

    /**
     * A fresh, unguessable folder name for this restore's safety copy.
     */
    public static function newOldDirName(): string {
        return self::OLD_DIR_PREFIX . strtolower(wp_generate_password(16, false));
    }

    /**
     * Creates one of this plugin's hidden working folders and blocks direct web access to it.
     */
    public static function prepareHelperDir(string $dir): void {
        Storage::protectDirectory($dir);
    }

    /**
     * Deletes every safety-copy folder left in $root, whatever its random suffix.
     */
    public static function purgeOldDirs(string $root): void {
        foreach (is_dir($root) ? (array) scandir($root) : array() as $entry) {
            if (!is_string($entry) || strpos($entry, self::OLD_DIR_PREFIX) !== 0) {
                continue;
            }
            self::delete($root . '/' . $entry);
        }
        // Older versions used one fixed name.
        self::delete($root . '/.slice-backup-old');
    }

    /**
     * Summary for the admin screen, or null when there is no safety copy.
     *
     * @return array<string, mixed>|null
     */
    public static function info(): ?array {
        $record = self::load();
        if ($record === null) {
            return null;
        }
        $tables  = array_filter((array) ($record['tables'] ?? array()), static function ($t): bool {
            return !empty($t['old']);
        });
        $folders = array_filter((array) ($record['folders'] ?? array()), static function ($f): bool {
            return !empty($f['old']);
        });
        return array(
            'created' => (int) ($record['created'] ?? 0),
            'when'    => wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) ($record['created'] ?? 0)),
            'bundle'  => (string) ($record['bundle'] ?? ''),
            'tables'  => count($tables),
            'folders' => count($folders),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Tables                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Renames tables. On MySQL all renames happen in one atomic statement: either all
     * of them or none.
     *
     * @param array<int, array{0: string, 1: string}> $pairs From => to, in order.
     * @throws \RuntimeException When the database refuses.
     */
    public static function renameTables(array $pairs): void {
        global $wpdb;
        if (empty($pairs)) {
            return;
        }
        if ($wpdb->dbh instanceof \mysqli) {
            $parts = array();
            foreach ($pairs as $pair) {
                $parts[] = DbUtil::ident($pair[0]) . ' TO ' . DbUtil::ident($pair[1]);
            }
            if ($wpdb->query('RENAME TABLE ' . implode(', ', $parts)) === false) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers quoted by DbUtil::ident().
                throw new \RuntimeException(esc_html(sprintf(
                    /* translators: %s: database error. */
                    __('Swapping the database tables failed: %s', 'slice-backup'),
                    $wpdb->last_error
                )));
            }
            return;
        }
        // Other database drivers: one table at a time.
        foreach ($pairs as $pair) {
            if ($wpdb->query('ALTER TABLE ' . DbUtil::ident($pair[0]) . ' RENAME TO ' . DbUtil::ident($pair[1])) === false) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers quoted by DbUtil::ident().
                throw new \RuntimeException(esc_html(sprintf(
                    /* translators: %s: database error. */
                    __('Swapping the database tables failed: %s', 'slice-backup'),
                    $wpdb->last_error
                )));
            }
        }
    }

    /**
     * @param string[] $tables
     */
    public static function dropTables(array $tables): void {
        global $wpdb;
        foreach ($tables as $table) {
            if ($table !== '' && DbUtil::isInternalTable($table)) {
                $wpdb->query('DROP TABLE IF EXISTS ' . DbUtil::ident($table)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifier quoted by DbUtil::ident().
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Folders                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Moves a file or folder (same file system, so it is a quick rename).
     */
    public static function move(string $from, string $to): bool {
        if (!file_exists($from)) {
            return false;
        }
        $parent = dirname($to);
        $name   = basename($parent);
        if (strpos($name, self::OLD_DIR_PREFIX) === 0 || $name === self::TRASH_DIR) {
            // The safety copy has to live next to the live folders so the swap is a single
            // rename, so block direct web access to the replaced (possibly vulnerable) code.
            self::prepareHelperDir($parent);
        } else {
            wp_mkdir_p($parent);
        }
        if (file_exists($to)) {
            self::delete($to);
        }
        return @rename($from, $to); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
    }

    public static function delete(string $path): void {
        if (is_dir($path) && !is_link($path)) {
            Storage::removeDirectory($path);
        } elseif (file_exists($path) || is_link($path)) {
            wp_delete_file($path);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Undo / discard                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Puts the site back as it was before the last restore.
     *
     * @return array{tables: int, folders: int}
     * @throws \RuntimeException When there is nothing to undo or the swap fails.
     */
    public static function undo(): array {
        $record = self::load();
        if ($record === null) {
            throw new \RuntimeException(esc_html__('There is no safety copy to go back to.', 'slice-backup'));
        }
        if (Job::active() !== null) {
            throw new \RuntimeException(esc_html__('A backup or restore is running. Wait for it to finish first.', 'slice-backup'));
        }
        self::prepare();

        // Tables: restored ones go aside, the kept originals come back, in one atomic rename.
        $trash = DbUtil::internalPrefix('s');
        $pairs = array();
        $drop  = array();
        foreach ((array) ($record['tables'] ?? array()) as $table) {
            $live = (string) $table['live'];
            $away = $trash . substr($live, strlen((string) $record['basePrefix']));
            $pairs[] = array($live, $away);
            $drop[]  = $away;
            if (!empty($table['old'])) {
                $pairs[] = array((string) $table['old'], $live);
            }
        }
        self::renameTables($pairs);
        self::dropTables($drop);

        // Folders: the same, one folder at a time.
        $folders = 0;
        foreach ((array) ($record['folders'] ?? array()) as $folder) {
            $live = (string) $folder['live'];
            $away = dirname($live) . '/' . self::TRASH_DIR . '/' . basename($live);
            if (file_exists($live)) {
                self::move($live, $away);
            }
            if (!empty($folder['old']) && file_exists((string) $folder['old'])) {
                self::move((string) $folder['old'], $live);
            }
            self::delete($away);
            $folders++;
        }
        self::tidy($record);
        self::forget();
        wp_cache_flush();

        return array('tables' => count((array) ($record['tables'] ?? array())), 'folders' => $folders);
    }

    /**
     * Deletes the safety copy to free space.
     *
     * @return array{tables: int, folders: int}
     */
    public static function discard(): array {
        $record = self::load();
        if ($record === null) {
            return array('tables' => 0, 'folders' => 0);
        }
        self::prepare();

        $tables = array();
        foreach ((array) ($record['tables'] ?? array()) as $table) {
            if (!empty($table['old'])) {
                $tables[] = (string) $table['old'];
            }
        }
        self::dropTables($tables);

        $folders = 0;
        foreach ((array) ($record['folders'] ?? array()) as $folder) {
            if (!empty($folder['old'])) {
                self::delete((string) $folder['old']);
                $folders++;
            }
        }
        self::tidy($record);
        self::forget();

        return array('tables' => count($tables), 'folders' => $folders);
    }

    private static function prepare(): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Deleting a large safety copy can take a while.
        }
        ignore_user_abort(true);
    }

    /**
     * Removes the empty helper folders.
     *
     * @param array<string, mixed> $record
     */
    private static function tidy(array $record): void {
        $dirs = array();
        foreach ((array) ($record['folders'] ?? array()) as $folder) {
            $dirs[dirname((string) $folder['live']) . '/' . self::TRASH_DIR] = true;
            if (!empty($folder['old'])) {
                $dirs[dirname((string) $folder['old'])] = true;
            }
        }
        foreach (array_keys($dirs) as $dir) {
            self::removeIfEmpty($dir);
        }
    }

    /**
     * Removes a helper folder once nothing but its web-access protection is left in it.
     */
    private static function removeIfEmpty(string $dir): void {
        if (!is_dir($dir) || strpos(basename($dir), '.slice-backup-') !== 0) {
            return;
        }
        $left = array_diff(
            (array) scandir($dir),
            array_merge(array('.', '..'), Storage::PROTECTION_FILES)
        );
        if (count($left) === 0) {
            Storage::removeDirectory($dir);
        }
    }
}
