<?php
/**
 * Paginated MySQL dumper for single sites and multisite networks.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

// A backup tool must read every table directly and uncached (SHOW CREATE TABLE is a read,
// not a schema change). Identifiers go through %i placeholders.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.SlowDBQuery.slow_db_query_meta_key

/**
 * Writes the WordPress tables to an SQL file, a few thousand rows at a time.
 */
class ChunkedDatabaseDumper {
    /** Maximum size of one INSERT statement, kept well under common max_allowed_packet values. */
    private const MAX_STATEMENT_BYTES = 1048576;

    /** Network-level tables that a standalone single-site export must not carry. */
    private const NETWORK_TABLES = array('blogs', 'blogmeta', 'blog_versions', 'registration_log', 'signups', 'site', 'sitemeta');

    /** @var int */
    private $chunkSize;

    /** @var int[] */
    private $siteIds = array();

    /**
     * @param int|null $chunkSize Rows per query. Defaults to the saved setting.
     */
    public function __construct(?int $chunkSize = null) {
        $settings        = Plugin::settings();
        $size            = $chunkSize ?? (int) $settings['chunk_size'];
        $this->chunkSize = max(100, (int) apply_filters('slice_backup_chunk_size', $size));
    }

    /**
     * Dumps the selected tables into a new SQL file.
     *
     * @param array<string, mixed> $options multisite_mode, subsite_id, convert_subsite_standalone, exclude_* flags.
     * @return string Path of the SQL file.
     * @throws \RuntimeException On write errors.
     */
    public function dumpDatabase(array $options = array()): string {
        $targetFile = Storage::tempFile('database', 'sql');
        $state      = $this->begin($options, $targetFile);
        try {
            while (!$this->step($state, $options, microtime(true) + 3600)) {
                // Keep going until every table is written.
            }
        } catch (\Throwable $e) {
            wp_delete_file($targetFile);
            throw $e;
        }
        return $targetFile;
    }

    /**
     * Starts a resumable dump: writes the header and returns the state for step().
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws \RuntimeException When there is nothing to export or the file cannot be written.
     */
    public function begin(array $options, string $targetFile): array {
        $this->siteIds = DbUtil::siteIds();
        $tables        = $this->resolveTablesToDump($options);
        if (empty($tables)) {
            throw new \RuntimeException(esc_html__('No WordPress tables were found to export.', 'slice-backup'));
        }

        $header = "-- Slice Backup SQL export\n-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";
        if (file_put_contents($targetFile, $header) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            throw new \RuntimeException(esc_html__('Could not create the temporary database file. Check free disk space and folder permissions.', 'slice-backup'));
        }

        return array(
            'file'    => $targetFile,
            'tables'  => $tables,
            'ti'      => 0,
            'created' => false,
            'last'    => null,
            'offset'  => 0,
            'rows'    => 0,
        );
    }

    /**
     * Writes rows until the deadline. Returns true when all tables are done.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @throws \RuntimeException On database or disk errors.
     */
    public function step(array &$state, array $options, float $deadline): bool {
        $this->siteIds = DbUtil::siteIds();

        $handle = fopen((string) $state['file'], 'ab'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$handle) {
            throw new \RuntimeException(esc_html__('Could not write the temporary database file.', 'slice-backup'));
        }

        try {
            while ($state['ti'] < count($state['tables'])) {
                $table = $state['tables'][$state['ti']];
                if ($this->dumpTable($handle, (string) $table['actual'], (string) $table['target'], $options, $state, $deadline)) {
                    $state['ti']++;
                    $state['created'] = false;
                    $state['last']    = null;
                    $state['offset']  = 0;
                }
                if (microtime(true) >= $deadline) {
                    break;
                }
            }

            if ($state['ti'] >= count($state['tables'])) {
                $this->write($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
                return true;
            }
            return false;
        } finally {
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }
    }

    /**
     * @param resource $handle
     * @throws \RuntimeException When the disk is full.
     */
    private function write($handle, string $data): void {
        if (fwrite($handle, $data) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            throw new \RuntimeException(esc_html__('Writing the database export failed. The disk may be full.', 'slice-backup'));
        }
    }

    /**
     * Picks the tables for single site, whole network, or one network site.
     *
     * @param array<string, mixed> $options
     * @return array<int, array{actual: string, target: string}>
     */
    private function resolveTablesToDump(array $options): array {
        global $wpdb;

        $base       = (string) $wpdb->base_prefix;
        $all        = DbUtil::tables($base);
        $mode       = $options['multisite_mode'] ?? 'entire_network';
        $subsiteId  = (int) ($options['subsite_id'] ?? 0);
        $standalone = !empty($options['convert_subsite_standalone']);

        if (!is_multisite() || $mode !== 'subsite' || $subsiteId < 1) {
            return array_map(
                static function (string $t): array {
                    return array('actual' => $t, 'target' => $t);
                },
                $all
            );
        }

        $tables = array();
        foreach ($all as $table) {
            $rest   = substr($table, strlen($base));
            $blogId = $this->blogIdOf($rest);

            if ($subsiteId === 1) {
                // Main site: everything that does not belong to another network site.
                if ($blogId !== null) {
                    continue;
                }
                if ($standalone && in_array($rest, self::NETWORK_TABLES, true)) {
                    continue;
                }
                $tables[] = array('actual' => $table, 'target' => $table);
                continue;
            }

            if ($blogId === $subsiteId) {
                $withoutId = substr($rest, strlen($subsiteId . '_'));
                $tables[]  = array(
                    'actual' => $table,
                    'target' => $standalone ? $base . $withoutId : $table,
                );
            } elseif ($blogId === null && in_array($rest, array('users', 'usermeta'), true)) {
                $tables[] = array('actual' => $table, 'target' => $table);
            }
        }

        return $tables;
    }

    /**
     * Returns the network site ID encoded in an unprefixed table name ("5_posts" => 5),
     * or null. Only IDs of real sites count, so plugin tables like "2fa_codes" are not confused.
     */
    private function blogIdOf(string $unprefixed): ?int {
        if (!empty($this->siteIds) && preg_match('/^(\d+)_/', $unprefixed, $m)) {
            $id = (int) $m[1];
            if (in_array($id, $this->siteIds, true)) {
                return $id;
            }
        }
        return null;
    }

    /**
     * The core table "role" (posts, comments, options...) of a table name.
     */
    private function tableRole(string $table): string {
        global $wpdb;

        $rest   = substr($table, strlen((string) $wpdb->base_prefix));
        $blogId = $this->blogIdOf($rest);
        if ($blogId !== null) {
            $rest = substr($rest, strlen($blogId . '_'));
        }
        return $rest;
    }

    /**
     * Dumps (part of) one table. Returns true when the table is complete.
     *
     * @param resource             $handle
     * @param array<string, mixed> $options
     * @param array<string, mixed> $state   Uses created, last, offset, rows.
     */
    private function dumpTable($handle, string $actual, string $target, array $options, array &$state, float $deadline): bool {
        global $wpdb;

        if (empty($state['created'])) {
            $create = $wpdb->get_row($wpdb->prepare('SHOW CREATE TABLE %i', $actual), ARRAY_N);
            if (!is_array($create) || !isset($create[1])) {
                return true;
            }

            $createSql = (string) $create[1];
            if ($actual !== $target) {
                $renamed   = preg_replace('/^CREATE TABLE `' . preg_quote($actual, '/') . '`/', 'CREATE TABLE ' . DbUtil::ident($target), $createSql, 1);
                $createSql = is_string($renamed) ? $renamed : $createSql;
            }

            $this->write($handle, 'DROP TABLE IF EXISTS ' . DbUtil::ident($target) . ";\n");
            $this->write($handle, $createSql . ";\n\n");
            $state['created'] = true;
        }

        $filters = $this->rowFilters($this->tableRole($actual), $options);
        $pk      = DbUtil::primaryKey($actual);
        $last    = $state['last'];
        $offset  = (int) $state['offset'];

        $chunks = 0;
        while (true) {
            if ($chunks++ > 0 && microtime(true) >= $deadline) {
                $state['last']   = $last;
                $state['offset'] = $offset;
                return false;
            }
            if (function_exists('set_time_limit')) {
                @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running step; each step is still time-budgeted.
            }

            $where = $filters;
            if ($pk !== null && $last !== null) {
                $where[] = $pk['numeric']
                    ? $wpdb->prepare('%i > %d', $pk['column'], $last)
                    : $wpdb->prepare('%i > %s', $pk['column'], $last);
            }

            // Every piece is built with $wpdb->prepare(); the WHERE pieces are fixed strings or prepared above.
            $sql = $wpdb->prepare('SELECT * FROM %i', $actual);
            if (!empty($where)) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            if ($pk !== null) {
                $sql .= $wpdb->prepare(' ORDER BY %i ASC LIMIT %d', $pk['column'], $this->chunkSize);
            } else {
                $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $this->chunkSize, $offset);
            }

            $rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Assembled from prepared fragments above.
            if (empty($rows) && $wpdb->last_error !== '') {
                throw new \RuntimeException(
                    esc_html(/* translators: 1: table name, 2: database error. */
                    sprintf(__('Reading table %1$s failed: %2$s', 'slice-backup'), $actual, $wpdb->last_error))
                );
            }
            if (empty($rows)) {
                break;
            }

            $count = count($rows);
            $this->writeInserts($handle, $actual, $target, $rows, $options);
            $state['rows'] = (int) ($state['rows'] ?? 0) + $count;

            if ($pk !== null) {
                $lastRow = end($rows);
                $last    = $pk['numeric'] ? (int) $lastRow[$pk['column']] : (string) $lastRow[$pk['column']];
            } else {
                $offset += $count;
            }

            unset($rows);
            DbUtil::releaseMemory();

            if ($count < $this->chunkSize) {
                break;
            }
        }

        return true;
    }

    /**
     * Optional WHERE clauses for the "skip spam / revisions / transients" options.
     *
     * @param array<string, mixed> $options
     * @return string[]
     */
    private function rowFilters(string $role, array $options): array {
        global $wpdb;

        $filters = array();
        if ($role === 'comments' && !empty($options['exclude_spam'])) {
            $filters[] = "comment_approved NOT IN ('spam', 'trash')";
        }
        if ($role === 'posts' && !empty($options['exclude_revisions'])) {
            $filters[] = "post_type <> 'revision' AND post_status <> 'trash'";
        }
        if ($role === 'options' && !empty($options['exclude_transients'])) {
            $filters[] = $wpdb->prepare(
                'option_name NOT LIKE %s AND option_name NOT LIKE %s',
                $wpdb->esc_like('_transient_') . '%',
                $wpdb->esc_like('_site_transient_') . '%'
            );
        }
        return $filters;
    }

    /**
     * Writes rows as INSERT statements no bigger than MAX_STATEMENT_BYTES.
     *
     * @param resource                          $handle
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>              $options
     */
    private function writeInserts($handle, string $actual, string $target, array $rows, array $options): void {
        $first = reset($rows);
        if (!is_array($first)) {
            return;
        }

        $columns = array_keys($first);
        $head    = 'INSERT INTO ' . DbUtil::ident($target) . ' (' . implode(', ', array_map(array(DbUtil::class, 'ident'), $columns)) . ") VALUES\n";
        $role    = $this->tableRole($actual);

        $buffer = array();
        $bytes  = 0;
        foreach ($rows as $row) {
            $row = $this->transformRow($role, $row, $options);
            if ($row === null) {
                continue;
            }

            $values = array();
            foreach ($columns as $col) {
                $values[] = $this->quote($row[$col]);
            }
            $line = '(' . implode(', ', $values) . ')';

            if (!empty($buffer) && ($bytes + strlen($line)) > self::MAX_STATEMENT_BYTES) {
                $this->write($handle, $head . implode(",\n", $buffer) . ";\n\n");
                $buffer = array();
                $bytes  = 0;
            }
            $buffer[] = $line;
            $bytes   += strlen($line) + 2;
        }

        if (!empty($buffer)) {
            $this->write($handle, $head . implode(",\n", $buffer) . ";\n\n");
        }
    }

    /**
     * Adjusts rows when a network site is converted into a standalone site.
     * Returns null to drop the row.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    private function transformRow(string $role, array $row, array $options): ?array {
        global $wpdb;

        $subsiteId = (int) ($options['subsite_id'] ?? 0);
        if (empty($options['convert_subsite_standalone']) || $subsiteId < 1 || !is_multisite()) {
            return $row;
        }

        $base       = (string) $wpdb->base_prefix;
        $sitePrefix = $base . $subsiteId . '_';

        // wp_5_user_roles -> wp_user_roles.
        if ($role === 'options' && $subsiteId > 1 && isset($row['option_name']) && strpos((string) $row['option_name'], $sitePrefix) === 0) {
            $row['option_name'] = $base . substr((string) $row['option_name'], strlen($sitePrefix));
            return $row;
        }

        // Keep only this site's per-site user meta (capabilities, user level, ...), renamed to the base prefix.
        if ($role === 'usermeta' && isset($row['meta_key']) && strpos((string) $row['meta_key'], $base) === 0) {
            $key  = (string) $row['meta_key'];
            $rest = substr($key, strlen($base));
            $blog = $this->blogIdOf($rest);

            if ($subsiteId > 1) {
                if ($blog === $subsiteId) {
                    $row['meta_key'] = $base . substr($rest, strlen($subsiteId . '_'));
                    return $row;
                }
                return null; // Main-site or other-site keys.
            }

            return $blog === null ? $row : null; // Main site: drop other sites' keys.
        }

        return $row;
    }

    /**
     * Quotes one value for the SQL file. Newlines are escaped, so every row stays on one line.
     *
     * @param mixed $value
     */
    private function quote($value): string {
        global $wpdb;

        if ($value === null) {
            return 'NULL';
        }

        $value = (string) $value;
        if ($wpdb->dbh instanceof \mysqli) {
            // esc_sql() uses the connection's charset-aware escaping. It also replaces "%" with a
            // placeholder that is normally removed when a query runs; this text goes to a file instead.
            return "'" . $wpdb->remove_placeholder_escape(esc_sql($value)) . "'";
        }

        return "'" . strtr(
            $value,
            array(
                '\\'   => '\\\\',
                "\0"   => '\\0',
                "\n"   => '\\n',
                "\r"   => '\\r',
                "'"    => "\\'",
                '"'    => '\\"',
                "\x1a" => '\\Z',
            )
        ) . "'";
    }
}
