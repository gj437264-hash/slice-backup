<?php
/**
 * Scheduled backups, GFS retention and notifications.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runs backups on a schedule through WP-Cron.
 */
final class Scheduler {
    public const HOOK         = 'slice_backup_scheduled_backup';
    public const OPTION_LAST  = 'slice_backup_schedule_last';

    public static function init(): void {
        add_filter('cron_schedules', array(__CLASS__, 'intervals')); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
        add_action(self::HOOK, array(__CLASS__, 'run'));
        add_action(MigratorEngine::TICK_HOOK, array(MigratorEngine::class, 'cronTick'));
    }

    /**
     * @param array<string, array<string, mixed>> $schedules
     * @return array<string, array<string, mixed>>
     */
    public static function intervals(array $schedules): array {
        $schedules['slice_backup_weekly']  = array('interval' => WEEK_IN_SECONDS, 'display' => __('Once a week', 'slice-backup'));
        $schedules['slice_backup_monthly'] = array('interval' => 30 * DAY_IN_SECONDS, 'display' => __('Once every 30 days', 'slice-backup'));
        return $schedules;
    }

    /**
     * @return array<string, string>
     */
    public static function frequencies(): array {
        return array(
            'twicedaily'   => __('Every 12 hours', 'slice-backup'),
            'daily'        => __('Daily', 'slice-backup'),
            'slice_backup_weekly'  => __('Weekly', 'slice-backup'),
            'slice_backup_monthly' => __('Monthly', 'slice-backup'),
        );
    }

    /**
     * Re-creates the cron event from the saved settings.
     */
    public static function reschedule(): void {
        wp_clear_scheduled_hook(self::HOOK);
        $schedule = Plugin::settings()['schedule'];
        if (empty($schedule['enabled'])) {
            return;
        }
        wp_schedule_event(self::firstRun($schedule), (string) $schedule['frequency'], self::HOOK);
    }

    /**
     * Next run time for the chosen time of day (site time zone) and weekday.
     *
     * @param array<string, mixed> $schedule
     */
    public static function firstRun(array $schedule): int {
        $tz  = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);
        list($h, $m) = array_map('intval', explode(':', (string) $schedule['time'] . ':0'));
        $next = $now->setTime($h, $m);

        if ($schedule['frequency'] === 'slice_backup_weekly') {
            $days = ((int) $schedule['weekday'] - (int) $next->format('w') + 7) % 7;
            $next = $next->modify('+' . $days . ' days');
            if ($next <= $now) {
                $next = $next->modify('+7 days');
            }
        } elseif ($next <= $now) {
            $next = $next->modify('+1 day');
        }
        return $next->getTimestamp();
    }

    public static function nextRun(): ?int {
        $next = wp_next_scheduled(self::HOOK);
        return $next ? (int) $next : null;
    }

    /**
     * Cron callback: starts a backup job and lets it continue in the background.
     */
    public static function run(): void {
        $schedule = Plugin::settings()['schedule'];
        if (empty($schedule['enabled'])) {
            return;
        }

        $options = array(
            'export_type'        => (string) $schedule['preset'],
            'encrypt'            => !empty($schedule['encrypt']),
            'upload'             => !empty($schedule['upload']),
            'delete_local'       => !empty($schedule['upload']) && !empty($schedule['delete_local']),
            'exclude_spam'       => true,
            'exclude_transients' => true,
        );

        try {
            $job = MigratorEngine::startExport($options, 'schedule');
        } catch (\Throwable $e) {
            update_site_option(self::OPTION_LAST, array('time' => time(), 'status' => 'failed', 'error' => $e->getMessage(), 'file' => ''));
            Notifier::send(false, __('The scheduled backup could not start.', 'slice-backup') . ' ' . $e->getMessage(), array());
            return;
        }

        MigratorEngine::tick($job, (float) Plugin::settings()['step_seconds']);
    }
}
