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
                    unset($state['noKeyLimit']);
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

        $cap     = MemoryBudget::rowCap($this->chunkSize);
        $columns = $pk !== null ? array_column(DbUtil::columns($actual), 'name') : array();
        $chunks  = 0;
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
                $where[] = $this->keyAfter($pk, $last);
            }

            $limit  = $cap;
            $final  = false;
            $window = null;
            if ($pk !== null && !empty($columns)) {
                // Measure first, then load only as many rows as fit the memory budget.
                $window = $this->measureWindow($actual, $pk, $columns, $where, $cap);
                if ($window === null) {
                    break;
                }
                if ($window['rows'] === 1 && $window['first'] > self::hugeRowBytes()) {
                    // One very large row: stream it in pieces instead of loading it.
                    $this->exportHugeRow($handle, $actual, $target, $pk, $window['upper'], $columns, $options);
                    $state['rows'] = (int) ($state['rows'] ?? 0) + 1;
                    $last          = $window['upper'];
                    if ($window['final']) {
                        break;
                    }
                    continue;
                }
                $where[] = $pk['numeric']
                    ? $wpdb->prepare('%i <= %d', $pk['column'], $window['upper'])
                    : $wpdb->prepare('%i <= %s', $pk['column'], $window['upper']);
                $limit = $window['rows'];
                $final = $window['final'];
            } elseif ($pk === null) {
                // No key to measure by: start with one row, then size batches by the largest row seen.
                $limit = max(1, min($cap, (int) ($state['noKeyLimit'] ?? 1)));
            }

            // Every piece is built with $wpdb->prepare(); the WHERE pieces are fixed strings or prepared above.
            $sql = $wpdb->prepare('SELECT * FROM %i', $actual);
            if (!empty($where)) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            if ($pk !== null) {
                $sql .= $wpdb->prepare(' ORDER BY %i ASC LIMIT %d', $pk['column'], $limit);
            } else {
                $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $limit, $offset);
            }

            $rows  = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Assembled from prepared fragments above.
            $error = (string) $wpdb->last_error;
            // Drop wpdb's own copy of the result; $rows is now the only one.
            DbUtil::releaseMemory();
            if (empty($rows) && $error !== '') {
                throw new \RuntimeException(
                    esc_html(/* translators: 1: table name, 2: database error. */
                    sprintf(__('Reading table %1$s failed: %2$s', 'slice-backup'), $actual, $error))
                );
            }
            if (empty($rows)) {
                // The measured rows vanished in the meantime (deleted by the live site): this
                // window is empty, but rows after it still need exporting.
                if ($window !== null && !$window['final']) {
                    $last = $window['upper'];
                    continue;
                }
                break;
            }

            $count   = count($rows);
            $lastRow = end($rows);
            $lastKey = $pk !== null ? ($pk['numeric'] ? (int) $lastRow[$pk['column']] : (string) $lastRow[$pk['column']]) : null;
            unset($lastRow);

            $largest       = $this->writeInserts($handle, $target, $rows, $options, $this->tableRole($actual));
            $state['rows'] = (int) ($state['rows'] ?? 0) + $count;
            unset($rows);

            if ($pk !== null) {
                $last = $lastKey;
            } else {
                $offset += $count;
                $state['noKeyLimit'] = max(1, min($cap, (int) floor(MemoryBudget::batchBytes() / max(1, $largest))));
            }

            if ($final || ($pk === null && $count < $limit) || ($pk !== null && empty($columns) && $count < $limit)) {
                break;
            }
        }

        return true;
    }

    /**
     * WHERE fragment for "key after the last exported row".
     *
     * @param array{column: string, numeric: bool} $pk
     * @param int|string                           $last
     */
    private function keyAfter(array $pk, $last): string {
        global $wpdb;
        return $pk['numeric']
            ? $wpdb->prepare('%i > %d', $pk['column'], $last)
            : $wpdb->prepare('%i > %s', $pk['column'], $last);
    }

    /**
     * Looks at the sizes of the next rows (keys and byte lengths only) and picks how many
     * fit the memory budget. Returns null when there are no more rows.
     *
     * @param array{column: string, numeric: bool} $pk
     * @param string[]                             $columns
     * @param string[]                             $where   Prepared fragments.
     * @return array{upper: int|string, rows: int, final: bool}|null
     */
    private function measureWindow(string $table, array $pk, array $columns, array $where, int $cap): ?array {
        global $wpdb;

        $lengths = array();
        foreach ($columns as $col) {
            $lengths[] = $wpdb->prepare('COALESCE(LENGTH(%i), 0)', $col);
        }
        $sql = $wpdb->prepare('SELECT %i AS k, (', $pk['column']) . implode(' + ', $lengths) . $wpdb->prepare(') AS b FROM %i', $table);
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= $wpdb->prepare(' ORDER BY %i ASC LIMIT %d', $pk['column'], $cap);

        $sizes = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Assembled from prepared fragments above.
        $error = (string) $wpdb->last_error;
        DbUtil::releaseMemory();
        if (empty($sizes)) {
            if ($error !== '') {
                throw new \RuntimeException(
                    esc_html(/* translators: 1: table name, 2: database error. */
                    sprintf(__('Reading table %1$s failed: %2$s', 'slice-backup'), $table, $error))
                );
            }
            return null;
        }

        $take  = MemoryBudget::fit($sizes);
        $upper = $sizes[$take - 1]['k'];
        return array(
            'upper' => $pk['numeric'] ? (int) $upper : (string) $upper,
            'rows'  => $take,
            'first' => (int) $sizes[0]['b'],
            'final' => $take === count($sizes) && count($sizes) < $cap,
        );
    }

    /** Characters per piece when a large value is streamed (at most 2 MB in UTF-8). */
    private const PIECE_CHARS = 524288;

    /**
     * Rows larger than this are streamed in pieces instead of loaded whole.
     */
    private static function hugeRowBytes(): int {
        return MB_IN_BYTES;
    }

    /**
     * Writes one very large row without ever holding it in memory: an INSERT with the
     * small columns (large ones empty), then one UPDATE ... CONCAT() per piece of each
     * large column. Each piece only appends when the column has exactly the length it
     * had before that piece, so replaying statements after a crash cannot duplicate data.
     * Every statement stays small, which also keeps it under max_allowed_packet.
     *
     * @param resource                             $handle
     * @param array{column: string, numeric: bool} $pk
     * @param int|string                           $key
     * @param string[]                             $columns
     * @param array<string, mixed>                 $options
     */
    private function exportHugeRow($handle, string $actual, string $target, array $pk, $key, array $columns, array $options): void {
        global $wpdb;

        $byKey = $pk['numeric'] ? $wpdb->prepare(' WHERE %i = %d', $pk['column'], $key) : $wpdb->prepare(' WHERE %i = %s', $pk['column'], $key);

        $lengthSql = array();
        foreach ($columns as $i => $col) {
            $lengthSql[] = $wpdb->prepare('CHAR_LENGTH(%i) AS %i', $col, 'c' . $i);
        }
        $lengths = $wpdb->get_row('SELECT ' . implode(', ', $lengthSql) . $wpdb->prepare(' FROM %i', $actual) . $byKey, ARRAY_N); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Assembled from prepared fragments above.
        DbUtil::releaseMemory();
        if (!is_array($lengths)) {
            return;
        }

        $small = array();
        $large = array();
        foreach ($columns as $i => $col) {
            if ($lengths[$i] !== null && (int) $lengths[$i] > self::PIECE_CHARS) {
                $large[$col] = (int) $lengths[$i];
            } else {
                $small[] = $col;
            }
        }

        $row = array();
        if (!empty($small)) {
            $fields = array();
            foreach ($small as $col) {
                $fields[] = $wpdb->prepare('%i', $col);
            }
            $row = (array) $wpdb->get_row('SELECT ' . implode(', ', $fields) . $wpdb->prepare(' FROM %i', $actual) . $byKey, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Assembled from prepared fragments above.
            DbUtil::releaseMemory();
        }
        foreach (array_keys($large) as $col) {
            $row[$col] = '';
        }
        $row = $this->transformRow($this->tableRole($actual), $row, $options);
        if ($row === null) {
            return;
        }

        $values = array();
        foreach ($columns as $col) {
            $values[] = $this->quote($row[$col] ?? null);
        }
        $table = DbUtil::ident($target);
        $this->write($handle, 'INSERT INTO ' . $table . ' (' . implode(', ', array_map(array(DbUtil::class, 'ident'), $columns)) . ") VALUES\n(" . implode(', ', $values) . ");\n\n");
        unset($values, $row);

        $keySql = $pk['numeric'] ? (string) (int) $key : "'" . $this->escape((string) $key) . "'";
        $where  = ' WHERE ' . DbUtil::ident($pk['column']) . ' = ' . $keySql;
        foreach ($large as $col => $length) {
            $ident = DbUtil::ident($col);
            for ($offset = 1; $offset <= $length; $offset += self::PIECE_CHARS) {
                $piece = $wpdb->get_var($wpdb->prepare('SELECT SUBSTRING(%i, %d, %d) FROM %i', $col, $offset, self::PIECE_CHARS, $actual) . $byKey); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Assembled from prepared fragments above.
                DbUtil::releaseMemory();
                if (!is_string($piece) || $piece === '') {
                    break;
                }
                $this->write($handle, 'UPDATE ' . $table . ' SET ' . $ident . ' = CONCAT(' . $ident . ", '");
                $this->write($handle, $this->escape($piece));
                $this->write($handle, "')" . $where . ' AND CHAR_LENGTH(' . $ident . ') = ' . ($offset - 1) . ";\n\n");
                unset($piece);
            }
        }
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
     * Writes rows as INSERT statements no bigger than MAX_STATEMENT_BYTES (a single larger
     * row gets a statement of its own). Rows go to disk one at a time and are freed as they
     * are written, so memory holds at most one escaped row on top of the batch.
     *
     * @param resource                          $handle
     * @param array<int, array<string, mixed>> $rows    Emptied while writing.
     * @param array<string, mixed>              $options
     * @return int Size in bytes of the largest row written.
     */
    private function writeInserts($handle, string $target, array &$rows, array $options, string $role): int {
        $first = reset($rows);
        if (!is_array($first)) {
            return 0;
        }

        $columns = array_keys($first);
        $head    = 'INSERT INTO ' . DbUtil::ident($target) . ' (' . implode(', ', array_map(array(DbUtil::class, 'ident'), $columns)) . ") VALUES\n";
        unset($first);

        $open    = false;
        $bytes   = 0;
        $largest = 0;
        foreach (array_keys($rows) as $index) {
            $row = $this->transformRow($role, $rows[$index], $options);
            unset($rows[$index]);
            if ($row === null) {
                continue;
            }

            $size = 2;
            foreach ($columns as $col) {
                $size += $row[$col] === null ? 4 : strlen((string) $row[$col]) + 4;
            }
            $largest = max($largest, $size);

            // Escaped size decides where a statement ends (escaping can double binary data).
            $line = null;
            if ($size < 262144) {
                $values = array();
                foreach ($columns as $col) {
                    $values[] = $this->quote($row[$col]);
                }
                $line = implode(', ', $values);
                unset($values);
                $rowBytes = strlen($line) + 4;
            } else {
                $rowBytes = 2 * $size;
            }

            if ($open && ($bytes + $rowBytes) > self::MAX_STATEMENT_BYTES) {
                $this->write($handle, ";\n\n");
                $open  = false;
                $bytes = 0;
            }
            $this->write($handle, $open ? ",\n(" : $head . '(');
            $open = true;

            if ($line !== null) {
                // Small row: one write.
                $this->write($handle, $line);
                $bytes += $rowBytes;
                unset($line);
            } else {
                // Large row: one value at a time, so it is never copied whole more than once.
                foreach ($columns as $i => $col) {
                    if ($i > 0) {
                        $this->write($handle, ', ');
                    }
                    $value = $row[$col];
                    unset($row[$col]);
                    if ($value === null) {
                        $this->write($handle, 'NULL');
                        $bytes += 6;
                        continue;
                    }
                    $escaped = $this->escape((string) $value);
                    unset($value);
                    $this->write($handle, "'");
                    $this->write($handle, $escaped);
                    $this->write($handle, "'");
                    $bytes += strlen($escaped) + 4;
                    unset($escaped);
                }
            }
            unset($row);
            $this->write($handle, ')');
        }

        if ($open) {
            $this->write($handle, ";\n\n");
        }
        return $largest;
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
        if ($value === null) {
            return 'NULL';
        }
        return "'" . $this->escape((string) $value) . "'";
    }

    /**
     * Escapes a value for a quoted SQL string literal.
     */
    private function escape(string $value): string {
        global $wpdb;

        if ($wpdb->dbh instanceof \mysqli) {
            // esc_sql() uses the connection's charset-aware escaping. It also replaces "%" with a
            // placeholder that is normally removed when a query runs; this text goes to a file instead.
            return $wpdb->remove_placeholder_escape(esc_sql($value));
        }

        return strtr(
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
        );
    }
}
