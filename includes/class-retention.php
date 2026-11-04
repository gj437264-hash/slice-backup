<?php
/**
 * Grandfather-father-son retention for scheduled backups.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Grandfather-father-son retention for scheduled backups.
 */
final class Retention {
    /**
     * Picks which items to delete: keeps the newest item of each of the last $daily days,
     * $weekly ISO weeks and $monthly months.
     *
     * @param array<int, array{name: string, time: int}> $items
     * @return string[] Names to delete.
     */
    public static function gfs(array $items, int $daily, int $weekly, int $monthly): array {
        usort(
            $items,
            static function (array $a, array $b): int {
                return $b['time'] <=> $a['time'];
            }
        );

        $keep = array();
        foreach (array(array('Y-m-d', $daily), array('o-W', $weekly), array('Y-m', $monthly)) as $rule) {
            list($format, $limit) = $rule;
            $seen = array();
            foreach ($items as $item) {
                if (count($seen) >= $limit) {
                    break;
                }
                $period = wp_date($format, $item['time']);
                if (!isset($seen[$period])) {
                    $seen[$period]       = true;
                    $keep[$item['name']] = true;
                }
            }
        }

        $delete = array();
        foreach ($items as $item) {
            if (!isset($keep[$item['name']])) {
                $delete[] = $item['name'];
            }
        }
        return $delete;
    }

    /**
     * Time a scheduled bundle was created, from its name (…-auto-…-YYYYMMDD-HHMMSS-…).
     */
    public static function timeFromName(string $name, int $fallback): int {
        if (preg_match('/-(\d{8})-(\d{6})-[a-z0-9]+\.(zip|slicebk)$/', $name, $m)) {
            $time = strtotime($m[1] . 'T' . $m[2] . 'Z');
            if ($time !== false) {
                return $time;
            }
        }
        return $fallback;
    }

    /**
     * Applies GFS retention to scheduled bundles, locally and in cloud storage.
     *
     * @return string[] Deleted names.
     */
    public static function applySchedule(): array {
        $schedule = Plugin::settings()['schedule'];
        $daily    = max(1, (int) $schedule['keep_daily']);
        $weekly   = max(0, (int) $schedule['keep_weekly']);
        $monthly  = max(0, (int) $schedule['keep_monthly']);
        $deleted  = array();

        $local = array();
        foreach (Storage::listBundles() as $bundle) {
            if (strpos($bundle['filename'], '-auto-') !== false) {
                $local[] = array('name' => $bundle['filename'], 'time' => self::timeFromName($bundle['filename'], (int) $bundle['created']));
            }
        }
        foreach (self::gfs($local, $daily, $weekly, $monthly) as $name) {
            try {
                if (Storage::delete($name)) {
                    $deleted[] = $name;
                }
            } catch (\Throwable $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                // Already gone.
            }
        }

        if (Cloud::isConfigured()) {
            try {
                $remote = array();
                foreach (Cloud::listBundles() as $object) {
                    if (strpos($object['filename'], '-auto-') !== false) {
                        $remote[] = array('name' => $object['filename'], 'time' => self::timeFromName($object['filename'], $object['modified']));
                    }
                }
                foreach (self::gfs($remote, $daily, $weekly, $monthly) as $name) {
                    Cloud::delete($name);
                    $deleted[] = 'cloud:' . $name;
                }
            } catch (\Throwable $e) {
                update_site_option('slice_backup_retention_error', Plugin::errorText($e));
            }
        }

        return $deleted;
    }
}
