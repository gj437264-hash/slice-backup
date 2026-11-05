<?php
/**
 * Serialization-safe search & replace across the database.
 *
 * Serialized PHP values are rewritten by a small parser that fixes string lengths
 * without ever calling unserialize(), so no objects are instantiated (CWE-502).
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

// Rewriting URLs during a migration must update every table directly and uncached;
// identifiers go through %i placeholders.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Replaces URLs (or any strings) in every text column of the WordPress tables.
 */
class RecursiveSearchReplace {
    private const ROWS_PER_QUERY = 1000;
    private const MAX_DEPTH      = 512;

    /** @var int */
    private $skippedSerialized = 0;

    /**
     * Called with the state after every finished window, so the caller can save the position
     * (a crash must not apply the same replacement twice).
     *
     * @var callable|null
     */
    private $onWindow = null;

    /**
     * @param callable|null $onWindow Receives the state array after each window.
     */
    public function setCheckpoint(?callable $onWindow): void {
        $this->onWindow = $onWindow;
    }

    /**
     * Builds the replacement pairs for a URL move: the URL itself, its JSON-escaped form
     * (used by the block editor and page builders), and the other http/https scheme.
     *
     * @return array<string, string>
     */
    public static function urlPairs(string $oldUrl, string $newUrl): array {
        $oldUrl = untrailingslashit($oldUrl);
        $newUrl = untrailingslashit($newUrl);
        if ($oldUrl === '' || $newUrl === '' || $oldUrl === $newUrl) {
            return array();
        }

        $pairs = array($oldUrl => $newUrl);

        if (strpos($oldUrl, 'https://') === 0) {
            $pairs['http://' . substr($oldUrl, 8)] = $newUrl;
        } elseif (strpos($oldUrl, 'http://') === 0) {
            $pairs['https://' . substr($oldUrl, 7)] = $newUrl;
        }

        foreach ($pairs as $from => $to) {
            $escapedFrom = str_replace('/', '\\/', $from);
            if ($escapedFrom !== $from) {
                $pairs[$escapedFrom] = str_replace('/', '\\/', $to);
            }
        }

        return $pairs;
    }

    /**
     * Runs the replacement over every table with the WordPress prefix.
     *
     * @param array<string, string> $pairs Map of search => replace.
     * @return array<string, mixed> Statistics.
     */
    public function replaceInDatabase(array $pairs): array {
        $state = $this->begin($pairs);
        while (!$this->step($state, microtime(true) + 3600)) {
            // Continue until all tables are processed.
        }
        return $state['stats'];
    }

    /**
     * Starts a resumable replacement.
     *
     * @param array<string, string> $pairs
     * @return array<string, mixed>
     */
    public function begin(array $pairs, ?array $tables = null): array {
        $pairs = array_filter(
            $pairs,
            static function ($to, $from): bool {
                return is_string($from) && $from !== '' && $from !== $to;
            },
            ARRAY_FILTER_USE_BOTH
        );

        return array(
            'pairs'  => $pairs,
            'tables' => empty($pairs) ? array() : ($tables ?? DbUtil::tables()),
            'ti'     => 0,
            'last'   => null,
            'stats'  => array(
                'tables'            => 0,
                'rowsUpdated'       => 0,
                'tablesWithoutKey'  => array(),
                'skippedSerialized' => 0,
                'errors'            => 0,
            ),
        );
    }

    /**
     * Processes tables until the deadline. Returns true when done.
     *
     * @param array<string, mixed> $state
     */
    public function step(array &$state, float $deadline): bool {
        $first = true;
        while ($state['ti'] < count($state['tables'])) {
            if (!$first && microtime(true) >= $deadline) {
                return false;
            }
            $first = false;
            $this->skippedSerialized = 0;
            $done = $this->replaceInTable((string) $state['tables'][$state['ti']], $state, $deadline);
            $state['stats']['skippedSerialized'] += $this->skippedSerialized;
            if (!$done) {
                return false;
            }
            $state['ti']++;
            $state['last'] = null;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function replaceInTable(string $table, array &$state, float $deadline): bool {
        global $wpdb;

        $pairs = $state['pairs'];
        $stats = &$state['stats'];

        $pk = DbUtil::primaryKey($table);
        if ($pk === null) {
            $stats['tablesWithoutKey'][] = $table;
            return true;
        }

        $columns = array();
        foreach (DbUtil::columns($table) as $col) {
            if ($col['name'] !== $pk['column'] && DbUtil::isTextType($col['type'])) {
                $columns[] = $col['name'];
            }
        }
        if (empty($columns)) {
            return true;
        }

        if ($state['last'] === null) {
            $stats['tables']++;
        }

        {
            // Every fragment is produced by $wpdb->prepare() with %i for identifiers.
            $likes = array();
            foreach ($columns as $col) {
                foreach (array_keys($pairs) as $from) {
                    $likes[] = $wpdb->prepare('%i LIKE %s', $col, '%' . $wpdb->esc_like((string) $from) . '%');
                }
            }
            $match  = '(' . implode(' OR ', $likes) . ')';
            $fields = array();
            foreach (array_merge(array($pk['column']), $columns) as $col) {
                $fields[] = $wpdb->prepare('%i', $col);
            }
            $select = 'SELECT ' . implode(', ', $fields) . $wpdb->prepare(' FROM %i', $table);

            // Sizes of the matching rows' text columns, used to load only what fits the memory budget.
            $lengths = array();
            foreach ($columns as $col) {
                $lengths[] = $wpdb->prepare('COALESCE(LENGTH(%i), 0)', $col);
            }
            $measure = $wpdb->prepare('SELECT %i AS k, (', $pk['column']) . implode(' + ', $lengths) . $wpdb->prepare(') AS b FROM %i', $table);
            $cap     = MemoryBudget::rowCap(self::ROWS_PER_QUERY);

            $last   = $state['last'];
            $chunks = 0;
            while (true) {
                if ($chunks++ > 0 && microtime(true) >= $deadline) {
                    $state['last'] = $last;
                    return false;
                }
                if (function_exists('set_time_limit')) {
                    @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running step; each step is still time-budgeted.
                }

                $after = '';
                if ($last !== null) {
                    $after = ' AND ' . ($pk['numeric']
                        ? $wpdb->prepare('%i > %d', $pk['column'], $last)
                        : $wpdb->prepare('%i > %s', $pk['column'], $last));
                }

                $sizes = $wpdb->get_results($measure . ' WHERE ' . $match . $after . $wpdb->prepare(' ORDER BY %i ASC LIMIT %d', $pk['column'], $cap), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Assembled from prepared fragments above.
                $this->failOnDbError($table);
                DbUtil::releaseMemory();
                if (empty($sizes)) {
                    break;
                }
                $take  = MemoryBudget::fit($sizes);
                $upper = $pk['numeric'] ? (int) $sizes[$take - 1]['k'] : (string) $sizes[$take - 1]['k'];
                $final = $take === count($sizes) && count($sizes) < $cap;
                $first = (int) $sizes[0]['b'];
                unset($sizes);

                if ($take === 1 && $first > max(MB_IN_BYTES, (int) (MemoryBudget::batchBytes() / 2))) {
                    // One very large row: replace column by column, inside the database where possible.
                    $result = $this->replaceInLargeRow($table, $pk, $upper, $columns, $pairs);
                    if ($result === false) {
                        $stats['errors']++;
                    } elseif ($result) {
                        $stats['rowsUpdated']++;
                    }
                    $last          = $upper;
                    $state['last'] = $last;
                    if ($this->onWindow !== null) {
                        ($this->onWindow)($state);
                    }
                    if ($final) {
                        break;
                    }
                    continue;
                }

                $until = $pk['numeric']
                    ? $wpdb->prepare(' AND %i <= %d', $pk['column'], $upper)
                    : $wpdb->prepare(' AND %i <= %s', $pk['column'], $upper);
                $sql   = $select . ' WHERE ' . $match . $after . $until . $wpdb->prepare(' ORDER BY %i ASC', $pk['column']);
                $rows  = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Assembled from prepared fragments above.
                $this->failOnDbError($table);
                DbUtil::releaseMemory();

                foreach (array_keys((array) $rows) as $index) {
                    $row = $rows[$index];
                    unset($rows[$index]);
                    $changes = array();
                    foreach ($columns as $col) {
                        if (!isset($row[$col]) || !is_string($row[$col])) {
                            continue;
                        }
                        $updated = $this->replaceString($row[$col], $pairs);
                        if ($updated !== $row[$col]) {
                            $changes[$col] = $updated;
                        }
                        unset($updated);
                    }
                    if (!empty($changes)) {
                        if ($wpdb->update($table, $changes, array($pk['column'] => $row[$pk['column']])) === false) {
                            $stats['errors']++;
                        } else {
                            $stats['rowsUpdated']++;
                        }
                        DbUtil::releaseMemory();
                    }
                    unset($row, $changes);
                }
                unset($rows);

                $last          = $upper;
                $state['last'] = $last;
                if ($this->onWindow !== null) {
                    ($this->onWindow)($state);
                }
                if ($final) {
                    break;
                }
            }
        }

        return true;
    }

    /**
     * A failed read must not be mistaken for "no more rows" (old addresses would stay behind).
     *
     * @throws \RuntimeException With the database error.
     */
    private function failOnDbError(string $table): void {
        global $wpdb;
        $error = (string) $wpdb->last_error;
        if ($error !== '') {
            throw new \RuntimeException(
                esc_html(/* translators: 1: table name, 2: database error. */
                sprintf(__('Updating addresses in table %1$s failed: %2$s', 'slice-backup'), $table, $error))
            );
        }
    }

    /** Values up to this many characters are replaced in PHP; longer ones are handled in pieces. */
    private const LARGE_VALUE_CHARS = 1048576;

    /**
     * Replaces in one very large row without loading the whole row:
     *  - small columns are loaded one at a time and replaced in PHP as usual;
     *  - large plain-text columns (HTML, JSON) are replaced by the database with REPLACE(),
     *    so they never enter PHP memory;
     *  - large serialized columns must be loaded, because their string lengths need recounting.
     *
     * @param array{column: string, numeric: bool} $pk
     * @param int|string                           $key
     * @param string[]                             $columns
     * @param array<string, string>                $pairs
     * @return bool|null True when the row changed, null when unchanged, false on a database error.
     */
    private function replaceInLargeRow(string $table, array $pk, $key, array $columns, array $pairs) {
        global $wpdb;

        $byKey = $pk['numeric'] ? $wpdb->prepare(' WHERE %i = %d', $pk['column'], $key) : $wpdb->prepare(' WHERE %i = %s', $pk['column'], $key);

        // Longest search string first, like strtr().
        uksort(
            $pairs,
            static function ($a, $b): int {
                return strlen((string) $b) <=> strlen((string) $a);
            }
        );

        $changed = null;
        foreach ($columns as $col) {
            $likes = array();
            foreach (array_keys($pairs) as $from) {
                $likes[] = $wpdb->prepare('%i LIKE %s', $col, '%' . $wpdb->esc_like((string) $from) . '%');
            }
            $sql = $wpdb->prepare('SELECT CHAR_LENGTH(%i) AS len, SUBSTRING(%i, 1, 16) AS head, ', $col, $col)
                . $wpdb->prepare('SUBSTRING(%i, CHAR_LENGTH(%i), 1) AS tail, (', $col, $col) . implode(' OR ', $likes) . ') AS hit'
                . $wpdb->prepare(' FROM %i', $table) . $byKey;
            $info = $wpdb->get_row($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Assembled from prepared fragments above.
            DbUtil::releaseMemory();
            if (!is_array($info) || empty($info['hit']) || $info['len'] === null) {
                continue;
            }

            $length     = (int) $info['len'];
            $serialized = (bool) preg_match('/^(?:[aOC]:\d+:|s:\d+:")/', (string) $info['head']) && in_array((string) $info['tail'], array(';', '}'), true);

            if ($length > self::LARGE_VALUE_CHARS && !$serialized) {
                foreach ($pairs as $from => $to) {
                    $result = $wpdb->query($wpdb->prepare('UPDATE %i SET %i = REPLACE(%i, %s, %s)', $table, $col, $col, (string) $from, (string) $to) . $byKey); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Assembled from prepared fragments above.
                    DbUtil::releaseMemory();
                    if ($result === false) {
                        return false;
                    }
                }
                $changed = true;
                continue;
            }

            if ($serialized && $length > self::LARGE_VALUE_CHARS && $wpdb->dbh instanceof \mysqli) {
                // Serialized: lengths must be recounted, so it is parsed in PHP, but streamed
                // through temporary files so only one copy of the value is in memory.
                $result = $this->replaceSerializedStreaming($table, $col, $pk, $key, $byKey, $length, $pairs);
                if ($result === false) {
                    return false;
                }
                if ($result) {
                    $changed = true;
                }
                continue;
            }

            // Loaded in pieces, so the database driver never holds a second full copy.
            $value = $this->fetchInPieces($table, $col, $byKey, $length);
            if ($value === null) {
                continue;
            }
            $updated = $this->replaceString($value, $pairs);
            if ($updated === $value) {
                continue;
            }
            unset($value);
            $result = $this->writeLargeValue($table, $col, $pk, $key, $updated);
            unset($updated);
            DbUtil::releaseMemory();
            if (!$result) {
                return false;
            }
            $changed = true;
        }
        return $changed;
    }

    /** Characters read per query when a large value is loaded in pieces. */
    private const PIECE_CHARS = 524288;

    /**
     * File the top-level serialized rewrite flushes its output to (streaming mode), or null.
     *
     * @var resource|null
     */
    private $sink = null;

    /**
     * Rewrites one large serialized value on MySQL with about one copy of it in memory:
     * read in pieces into a temporary file, loaded once at its exact size, parsed with the
     * output flushed to a second file every megabyte, then streamed back to the server.
     *
     * @param array{column: string, numeric: bool} $pk
     * @param int|string                           $key
     * @param array<string, string>                $pairs
     * @return bool|null True when changed, null when left unchanged, false on a database error.
     */
    private function replaceSerializedStreaming(string $table, string $col, array $pk, $key, string $byKey, int $length, array $pairs) {
        global $wpdb;

        $inPath  = Storage::tempFile('replace-in', 'tmp');
        $outPath = Storage::tempFile('replace-out', 'tmp');
        $sink    = null;
        try {
            $in = fopen($inPath, 'wb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            if (!$in) {
                return false;
            }
            for ($offset = 1; $offset <= $length; $offset += self::PIECE_CHARS) {
                $piece = $wpdb->get_var($wpdb->prepare('SELECT SUBSTRING(%i, %d, %d) FROM %i', $col, $offset, self::PIECE_CHARS, $table) . $byKey); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Assembled from prepared fragments above.
                DbUtil::releaseMemory();
                if (!is_string($piece)) {
                    fclose($in); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                    return false;
                }
                fwrite($in, $piece); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
                unset($piece);
            }
            fclose($in); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

            $value = (string) file_get_contents($inPath); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            wp_delete_file($inPath);

            $found = false;
            foreach (array_keys($pairs) as $from) {
                if (strpos($value, (string) $from) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found || !is_serialized($value)) {
                return null;
            }

            $sink = fopen($outPath, 'w+b'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            if (!$sink) {
                return false;
            }
            $this->sink = $sink;
            $pos        = 0;
            $out        = '';
            $ok         = $this->walk($value, $pos, strlen($value), $pairs, $out, 0) && trim(substr($value, $pos)) === '';
            $this->sink = null;
            if (!$ok) {
                $this->skippedSerialized++;
                return null;
            }
            fwrite($sink, $out . substr($value, $pos)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            unset($value, $out);

            $stmt = $wpdb->dbh->prepare('UPDATE ' . DbUtil::ident($table) . ' SET ' . DbUtil::ident($col) . ' = ? WHERE ' . DbUtil::ident($pk['column']) . ' = ?');
            if (!($stmt instanceof \mysqli_stmt)) {
                return false;
            }
            $blob = null;
            $id   = $key;
            $stmt->bind_param($pk['numeric'] ? 'bi' : 'bs', $blob, $id);
            rewind($sink);
            while (!feof($sink)) {
                $chunk = (string) fread($sink, MB_IN_BYTES); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                if ($chunk !== '') {
                    $stmt->send_long_data(0, $chunk);
                }
            }
            $ok = $stmt->execute();
            $stmt->close();
            return $ok ? true : false;
        } finally {
            $this->sink = null;
            if (is_resource($sink)) {
                fclose($sink); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            }
            wp_delete_file($inPath);
            wp_delete_file($outPath);
        }
    }

    /**
     * Reads a large value in pieces, so the database driver never holds a second full copy.
     */
    private function fetchInPieces(string $table, string $col, string $byKey, int $chars): ?string {
        global $wpdb;

        $value = '';
        for ($offset = 1; $offset <= max(1, $chars); $offset += self::PIECE_CHARS) {
            $piece = $wpdb->get_var($wpdb->prepare('SELECT SUBSTRING(%i, %d, %d) FROM %i', $col, $offset, self::PIECE_CHARS, $table) . $byKey); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Assembled from prepared fragments above.
            DbUtil::releaseMemory();
            if (!is_string($piece)) {
                return null;
            }
            $value .= $piece;
            unset($piece);
        }
        return $value;
    }

    /**
     * Saves a large value. On MySQL it is streamed to the server in 1 MB pieces through a
     * prepared statement, so no escaped copy of the whole value is built in PHP.
     *
     * @param array{column: string, numeric: bool} $pk
     * @param int|string                           $key
     */
    private function writeLargeValue(string $table, string $col, array $pk, $key, string $value): bool {
        global $wpdb;

        if ($wpdb->dbh instanceof \mysqli && $value !== '') {
            $stmt = $wpdb->dbh->prepare('UPDATE ' . DbUtil::ident($table) . ' SET ' . DbUtil::ident($col) . ' = ? WHERE ' . DbUtil::ident($pk['column']) . ' = ?');
            if ($stmt instanceof \mysqli_stmt) {
                $blob = null;
                $id   = $key;
                $stmt->bind_param($pk['numeric'] ? 'bi' : 'bs', $blob, $id);
                $length = strlen($value);
                for ($offset = 0; $offset < $length; $offset += MB_IN_BYTES) {
                    $stmt->send_long_data(0, substr($value, $offset, MB_IN_BYTES));
                }
                $ok = $stmt->execute();
                $stmt->close();
                return $ok;
            }
        }
        return $wpdb->update($table, array($col => $value), array($pk['column'] => $key)) !== false;
    }

    /**
     * Updates the network domain in the multisite tables (exact host match only).
     */
    public function replaceMultisiteNetworkDomain(string $oldDomain, string $newDomain, ?string $tablePrefix = null): void {
        global $wpdb;

        $old = (string) preg_replace('#^https?://#i', '', untrailingslashit(trim($oldDomain)));
        $new = (string) preg_replace('#^https?://#i', '', untrailingslashit(trim($newDomain)));
        if ($old === '' || $new === '' || $old === $new) {
            return;
        }

        $base   = $tablePrefix ?? (string) $wpdb->base_prefix;
        $tables = DbUtil::tables($base);

        if (in_array($base . 'site', $tables, true)) {
            $wpdb->query($wpdb->prepare('UPDATE %i SET domain = %s WHERE domain = %s', $base . 'site', $new, $old));
        }

        if (in_array($base . 'blogs', $tables, true)) {
            // Exact match (main site / subdirectory installs).
            $wpdb->query($wpdb->prepare('UPDATE %i SET domain = %s WHERE domain = %s', $base . 'blogs', $new, $old));
            // Subdomains: shop.old.com -> shop.new.com.
            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET domain = CONCAT(LEFT(domain, CHAR_LENGTH(domain) - %d), %s) WHERE domain LIKE %s',
                    $base . 'blogs',
                    strlen($old),
                    $new,
                    '%.' . $wpdb->esc_like($old)
                )
            );
        }

        if (in_array($base . 'sitemeta', $tables, true)) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT meta_id, meta_value FROM %i WHERE meta_value LIKE %s', $base . 'sitemeta', '%' . $wpdb->esc_like($old) . '%'));
            foreach ((array) $rows as $row) {
                $updated = $this->replaceString((string) $row->meta_value, array('//' . $old => '//' . $new));
                if ($updated !== $row->meta_value) {
                    $wpdb->update($base . 'sitemeta', array('meta_value' => $updated), array('meta_id' => $row->meta_id)); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                }
            }
        }
    }

    /**
     * Back-compat helper: replaces in a string or (recursively) an array.
     *
     * @param mixed $data
     * @return mixed
     */
    public function replace($data, string $old, string $new) {
        if ($old === '' || $old === $new) {
            return $data;
        }
        if (is_string($data)) {
            return $this->replaceString($data, array($old => $new));
        }
        if (is_array($data)) {
            $out = array();
            foreach ($data as $key => $value) {
                $out[is_string($key) ? str_replace($old, $new, $key) : $key] = $this->replace($value, $old, $new);
            }
            return $out;
        }
        return $data;
    }

    /**
     * Replaces inside a string, keeping serialized data valid.
     *
     * @param array<string, string> $pairs
     */
    public function replaceString(string $value, array $pairs): string {
        $found = false;
        foreach (array_keys($pairs) as $from) {
            if (strpos($value, (string) $from) !== false) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return $value;
        }

        if (is_serialized($value)) {
            $result = $this->replaceSerialized($value, $pairs);
            if ($result === null) {
                // Malformed serialized data: leave it untouched rather than corrupt it further.
                $this->skippedSerialized++;
                return $value;
            }
            return $result;
        }

        return strtr($value, $pairs);
    }

    /**
     * Rewrites a serialized value, recomputing every string length. Returns null on parse errors.
     *
     * @param array<string, string> $pairs
     */
    private function replaceSerialized(string $data, array $pairs): ?string {
        $pos = 0;
        $out = '';
        if (!$this->walk($data, $pos, strlen($data), $pairs, $out, 0)) {
            return null;
        }
        // Allow trailing whitespace only.
        if (trim(substr($data, $pos)) !== '') {
            return null;
        }
        return $out . substr($data, $pos);
    }

    /**
     * Copies one serialized token from $s at $p into $out, replacing inside strings.
     *
     * @param array<string, string> $pairs
     */
    private function walk(string $s, int &$p, int $len, array $pairs, string &$out, int $depth): bool {
        if ($depth > self::MAX_DEPTH || $p >= $len) {
            return false;
        }
        // Streaming mode: hand finished output to the file instead of growing a second copy.
        if ($this->sink !== null && strlen($out) >= MB_IN_BYTES) {
            fwrite($this->sink, $out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            $out = '';
        }

        switch ($s[$p]) {
            case 'N':
                if (substr($s, $p, 2) !== 'N;') {
                    return false;
                }
                $out .= 'N;';
                $p   += 2;
                return true;

            case 'b':
            case 'i':
            case 'd':
            case 'r':
            case 'R':
                if (!isset($s[$p + 1]) || $s[$p + 1] !== ':') {
                    return false;
                }
                $end = strpos($s, ';', $p);
                if ($end === false) {
                    return false;
                }
                $out .= substr($s, $p, $end - $p + 1);
                $p    = $end + 1;
                return true;

            case 's':
                if (!preg_match('/\Gs:(\d+):"/', $s, $m, 0, $p)) {
                    return false;
                }
                $length = (int) $m[1];
                $start  = $p + strlen($m[0]);
                if ($start + $length + 2 > $len || substr($s, $start + $length, 2) !== '";') {
                    return false;
                }
                // Nested serialized data inside this string is rewritten in memory, not to the file.
                $sink       = $this->sink;
                $this->sink = null;
                $content    = $this->replaceString(substr($s, $start, $length), $pairs);
                $this->sink = $sink;
                $out       .= 's:' . strlen($content) . ':"' . $content . '";';
                $p       = $start + $length + 2;
                return true;

            case 'a':
                if (!preg_match('/\Ga:(\d+):\{/', $s, $m, 0, $p)) {
                    return false;
                }
                $out .= $m[0];
                $p   += strlen($m[0]);
                return $this->walkMembers($s, $p, $len, $pairs, $out, $depth, (int) $m[1]);

            case 'O':
                if (!preg_match('/\GO:(\d+):"([^"]*)":(\d+):\{/', $s, $m, 0, $p) || strlen($m[2]) !== (int) $m[1]) {
                    return false;
                }
                $out .= $m[0];
                $p   += strlen($m[0]);
                return $this->walkMembers($s, $p, $len, $pairs, $out, $depth, (int) $m[3]);

            case 'C':
                // Custom-serialized object: opaque payload, copied verbatim.
                if (!preg_match('/\GC:(\d+):"([^"]*)":(\d+):\{/', $s, $m, 0, $p)) {
                    return false;
                }
                $total = strlen($m[0]) + (int) $m[3] + 1;
                if ($p + $total > $len || $s[$p + $total - 1] !== '}') {
                    return false;
                }
                $out .= substr($s, $p, $total);
                $p   += $total;
                return true;

            case 'E':
                // Enum case (PHP 8.1+): copied verbatim.
                if (!preg_match('/\GE:(\d+):"/', $s, $m, 0, $p)) {
                    return false;
                }
                $total = strlen($m[0]) + (int) $m[1] + 2;
                if ($p + $total > $len || substr($s, $p + $total - 2, 2) !== '";') {
                    return false;
                }
                $out .= substr($s, $p, $total);
                $p   += $total;
                return true;
        }

        return false;
    }

    /**
     * Walks $count key/value pairs followed by a closing brace.
     *
     * @param array<string, string> $pairs
     */
    private function walkMembers(string $s, int &$p, int $len, array $pairs, string &$out, int $depth, int $count): bool {
        for ($i = 0; $i < $count * 2; $i++) {
            if (!$this->walk($s, $p, $len, $pairs, $out, $depth + 1)) {
                return false;
            }
        }
        if ($p >= $len || $s[$p] !== '}') {
            return false;
        }
        $out .= '}';
        $p++;
        return true;
    }
}
