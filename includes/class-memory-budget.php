<?php
/**
 * How much data one batch may hold in memory.
 *
 * Batches are sized in bytes, not rows, so a table of 300 KB posts and a table of
 * 100-byte rows both stay inside the same small, flat memory footprint.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Memory budget for one batch of rows.
 */
final class MemoryBudget {
    /** Largest batch, whatever the memory limit. */
    private const MAX_BATCH_BYTES = 8388608;

    /** Smallest batch budget; a single larger row is still processed alone. */
    private const MIN_BATCH_BYTES = 262144;

    /**
     * Fraction applied after a crash (1, 1/2, 1/4 ...). Set by the job engine for each tick.
     *
     * @var float
     */
    private static $factor = 1.0;

    public static function setFactor(float $factor): void {
        self::$factor = max(1 / 1024, min(1.0, $factor));
    }

    public static function factor(): float {
        return self::$factor;
    }

    /**
     * PHP memory limit in bytes (PHP_INT_MAX when unlimited).
     */
    public static function limit(): int {
        $raw = (string) ini_get('memory_limit');
        if ($raw === '' || $raw === '-1') {
            return PHP_INT_MAX;
        }
        $bytes = (int) wp_convert_hr_to_bytes($raw);
        return $bytes > 0 ? $bytes : PHP_INT_MAX;
    }

    /**
     * Bytes one batch may load. A row is copied a few times while it is processed
     * (database driver, PHP array, escaped output), so only a sixth of the free
     * memory is handed out.
     */
    public static function batchBytes(): int {
        $limit = self::limit();
        $free  = $limit === PHP_INT_MAX ? self::MAX_BATCH_BYTES * 6 : max(0, $limit - memory_get_usage(true));
        $bytes = (int) min(self::MAX_BATCH_BYTES, max(self::MIN_BATCH_BYTES, $free / 6));
        return max(65536, (int) ($bytes * self::$factor));
    }

    /**
     * Upper bound for rows per query after a crash.
     */
    public static function rowCap(int $preferred): int {
        return max(1, (int) floor($preferred * self::$factor));
    }

    /**
     * Picks how many rows of a measured list fit the budget.
     *
     * @param array<int, array{k: mixed, b: mixed}> $sizes Row keys and byte sizes, in key order.
     * @return int Number of rows to take (at least 1 when the list is not empty).
     */
    public static function fit(array $sizes): int {
        $budget = self::batchBytes();
        $total  = 0;
        $take   = 0;
        foreach ($sizes as $row) {
            $bytes = (int) $row['b'] + 64;
            if ($take > 0 && $total + $bytes > $budget) {
                break;
            }
            $total += $bytes;
            $take++;
        }
        return $take;
    }

    /**
     * Human-readable memory limit for messages.
     */
    public static function limitLabel(): string {
        $raw = (string) ini_get('memory_limit');
        return ($raw === '' || $raw === '-1') ? __('unlimited', 'slice-backup') : $raw;
    }
}
