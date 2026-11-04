<?php
/**
 * Small database helpers shared by the dumper, importer and search-replace engine.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

// A backup tool must read the site's tables directly and uncached; table and column
// names come from SHOW TABLES / SHOW COLUMNS and are passed through %i placeholders.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Database utility helpers.
 */
final class DbUtil {
    /**
     * Lists base tables (not views) that start with the given prefix.
     *
     * @param string|null $prefix Table prefix. Defaults to $wpdb->base_prefix.
     * @return string[]
     */
    public static function tables(?string $prefix = null): array {
        global $wpdb;

        $prefix = $prefix ?? (string) $wpdb->base_prefix;
        $rows   = $wpdb->get_results('SHOW FULL TABLES', ARRAY_N);
        $tables = array();

        foreach ((array) $rows as $row) {
            if (!is_array($row) || !isset($row[0], $row[1])) {
                continue;
            }
            if (strtoupper((string) $row[1]) !== 'BASE TABLE') {
                continue;
            }
            $name = (string) $row[0];
            if ($prefix === '' || strpos($name, $prefix) === 0) {
                $tables[] = $name;
            }
        }

        sort($tables);
        return $tables;
    }

    /**
     * Quotes an SQL identifier (table or column name).
     */
    public static function ident(string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Returns the single-column primary key of a table, or null if the table has
     * no primary key or a composite one (keyset pagination is unsafe in that case).
     *
     * @return array{column: string, numeric: bool}|null
     */
    public static function primaryKey(string $table): ?array {
        global $wpdb;

        $keys = $wpdb->get_results($wpdb->prepare('SHOW KEYS FROM %i WHERE Key_name = %s', $table, 'PRIMARY'));
        if (!is_array($keys) || count($keys) !== 1 || !isset($keys[0]->Column_name)) {
            return null;
        }

        $column = (string) $keys[0]->Column_name;
        foreach (self::columns($table) as $col) {
            if ($col['name'] === $column) {
                return array(
                    'column'  => $column,
                    'numeric' => (bool) preg_match('/^(tiny|small|medium|big)?int\b/i', $col['type']),
                );
            }
        }

        return null;
    }

    /**
     * Lists the columns of a table.
     *
     * @return array<int, array{name: string, type: string}>
     */
    public static function columns(string $table): array {
        global $wpdb;

        $rows    = $wpdb->get_results($wpdb->prepare('SHOW COLUMNS FROM %i', $table));
        $columns = array();
        foreach ((array) $rows as $row) {
            if (isset($row->Field, $row->Type)) {
                $columns[] = array(
                    'name' => (string) $row->Field,
                    'type' => strtolower((string) $row->Type),
                );
            }
        }
        return $columns;
    }

    /**
     * Whether a column type can hold text that may contain URLs.
     */
    public static function isTextType(string $type): bool {
        return (bool) preg_match('/^((var)?char|(tiny|medium|long)?text|json)\b/i', $type);
    }

    /**
     * Lists all options tables (the main one plus one per network site).
     *
     * @return string[]
     */
    public static function optionsTables(): array {
        global $wpdb;

        $base   = (string) $wpdb->base_prefix;
        $result = array();
        foreach (self::tables($base) as $table) {
            $rest = substr($table, strlen($base));
            if ($rest === 'options' || preg_match('/^\d+_options$/', $rest)) {
                $result[] = $table;
            }
        }
        return $result;
    }

    /**
     * Frees wpdb result buffers so long loops keep a flat memory profile.
     */
    public static function releaseMemory(): void {
        global $wpdb;

        $wpdb->flush();
        if (defined('SAVEQUERIES') && SAVEQUERIES) {
            $wpdb->queries = array();
        }
    }

    /**
     * Whether the database server knows a collation.
     */
    public static function supportsCollation(string $collation): bool {
        global $wpdb;

        $found = $wpdb->get_var($wpdb->prepare('SHOW COLLATION LIKE %s', $collation));
        return is_string($found) && $found !== '';
    }

    /**
     * IDs of all sites in the network (empty on single site).
     *
     * @return int[]
     */
    public static function siteIds(): array {
        if (!is_multisite() || !function_exists('get_sites')) {
            return array();
        }
        return array_map('intval', (array) get_sites(array('fields' => 'ids', 'number' => 0)));
    }
}
