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
    public function begin(array $pairs): array {
        $pairs = array_filter(
            $pairs,
            static function ($to, $from): bool {
                return is_string($from) && $from !== '' && $from !== $to;
            },
            ARRAY_FILTER_USE_BOTH
        );

        return array(
            'pairs'  => $pairs,
            'tables' => empty($pairs) ? array() : DbUtil::tables(),
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

                $where = $match;
                if ($last !== null) {
                    $where .= ' AND ' . ($pk['numeric']
                        ? $wpdb->prepare('%i > %d', $pk['column'], $last)
                        : $wpdb->prepare('%i > %s', $pk['column'], $last));
                }

                $sql  = $select . ' WHERE ' . $where . $wpdb->prepare(' ORDER BY %i ASC LIMIT %d', $pk['column'], self::ROWS_PER_QUERY);
                $rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Assembled from prepared fragments above.
                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    $changes = array();
                    foreach ($columns as $col) {
                        if (!isset($row[$col]) || !is_string($row[$col])) {
                            continue;
                        }
                        $updated = $this->replaceString($row[$col], $pairs);
                        if ($updated !== $row[$col]) {
                            $changes[$col] = $updated;
                        }
                    }
                    if (!empty($changes)) {
                        if ($wpdb->update($table, $changes, array($pk['column'] => $row[$pk['column']])) === false) {
                            $stats['errors']++;
                        } else {
                            $stats['rowsUpdated']++;
                        }
                    }
                }

                $lastRow = end($rows);
                $last    = $pk['numeric'] ? (int) $lastRow[$pk['column']] : (string) $lastRow[$pk['column']];
                $count   = count($rows);
                unset($rows);
                DbUtil::releaseMemory();

                if ($count < self::ROWS_PER_QUERY) {
                    break;
                }
            }
        }

        return true;
    }

    /**
     * Updates the network domain in the multisite tables (exact host match only).
     */
    public function replaceMultisiteNetworkDomain(string $oldDomain, string $newDomain): void {
        global $wpdb;

        $old = (string) preg_replace('#^https?://#i', '', untrailingslashit(trim($oldDomain)));
        $new = (string) preg_replace('#^https?://#i', '', untrailingslashit(trim($newDomain)));
        if ($old === '' || $new === '' || $old === $new) {
            return;
        }

        $base   = (string) $wpdb->base_prefix;
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
                $content = $this->replaceString(substr($s, $start, $length), $pairs);
                $out    .= 's:' . strlen($content) . ':"' . $content . '";';
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
