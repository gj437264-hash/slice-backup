<?php
/**
 * Detailed log of what a job did, kept as a report for later.
 *
 * Every step, timing, recovered crash, compatibility finding and action taken
 * (plugins switched off, theme changed or downloaded, tables and folders swapped)
 * is written to a log file in the job folder. When the job ends the log is kept in
 * the protected backup folder, where it can be downloaded from the admin screen.
 * Actions the site owner may need to follow up on are also collected in a short list
 * that is shown with the restore report.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Job log and saved reports.
 */
final class RestoreLog {
    public const INFO    = 'info';
    public const ACTION  = 'action';
    public const WARNING = 'warning';
    public const ERROR   = 'error';

    /** Saved reports kept in the backup folder. */
    private const KEEP_REPORTS = 20;

    /** Actions listed in the on-screen report (the log file has all of them). */
    private const MAX_ACTIONS = 200;

    private static function file(Job $job): string {
        return $job->workDir() . '/job.log';
    }

    /**
     * Adds a line to the job's log. Actions, warnings and errors are also kept for the
     * on-screen report, together with the recommended follow-up.
     *
     * @param array<string, mixed> $context Extra details (written as JSON).
     */
    public static function add(Job $job, string $level, string $message, array $context = array(), string $followUp = ''): void {
        $line = sprintf(
            "[%s UTC] %-7s %-9s %s%s%s\n",
            gmdate('Y-m-d H:i:s'),
            strtoupper($level),
            $job->step() !== '' ? $job->step() : '-',
            $message,
            empty($context) ? '' : ' ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $followUp !== '' ? "\n" . str_repeat(' ', 38) . '→ ' . $followUp : ''
        );
        file_put_contents(self::file($job), $line, FILE_APPEND); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

        if ($level !== self::INFO) {
            $actions = (array) ($job->data['actions'] ?? array());
            if (count($actions) < self::MAX_ACTIONS) {
                $actions[] = array(
                    'level'    => $level,
                    'message'  => $message,
                    'followUp' => $followUp,
                    'time'     => time(),
                );
                $job->data['actions'] = $actions;
            }
        }
    }

    /**
     * Keeps the log in the backup folder (job folders are temporary) and returns its name.
     */
    public static function persist(Job $job, string $status = ''): string {
        $source = self::file($job);
        if (!is_file($source)) {
            return '';
        }
        $dir = self::reportsDir();
        if ($dir === '') {
            return '';
        }

        $name   = sprintf('%s-%s-%s.log', $job->type, gmdate('Ymd-His', $job->created ?: time()), substr($job->id, -8));
        $header = sprintf(
            "Slice Backup %s report\nJob: %s\nStarted: %s UTC\nEnded: %s UTC\nResult: %s%s\nSite: %s\nPHP %s, WordPress %s, memory limit %s\n%s\n\n",
            $job->type,
            $job->id,
            gmdate('Y-m-d H:i:s', $job->created ?: time()),
            gmdate('Y-m-d H:i:s'),
            $status !== '' ? $status : $job->status,
            $job->error !== '' ? ' (' . $job->error . ')' : '',
            (string) ($job->options['_siteUrl'] ?? home_url()),
            PHP_VERSION,
            get_bloginfo('version'),
            MemoryBudget::limitLabel(),
            str_repeat('=', 72)
        );

        $target = $dir . '/' . $name;
        $out    = fopen($target, 'wb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $in     = fopen($source, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$out || !$in) {
            return '';
        }
        fwrite($out, $header); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        stream_copy_to_stream($in, $out);

        $actions = array_filter((array) ($job->data['actions'] ?? array()), static function ($a): bool {
            return ($a['followUp'] ?? '') !== '';
        });
        if (!empty($actions)) {
            fwrite($out, "\n" . str_repeat('=', 72) . "\nTO DO\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            foreach ($actions as $i => $action) {
                fwrite($out, sprintf("%d. %s\n   → %s\n", $i + 1, $action['message'], $action['followUp'])); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            }
        }
        fclose($in); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        self::prune();
        $job->data['logFile'] = $name;
        return $name;
    }

    /**
     * The folder for saved reports (inside the protected backup folder).
     */
    public static function reportsDir(): string {
        $dir = Storage::dir() . '/reports';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return '';
        }
        if (!is_file($dir . '/index.php')) {
            file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }
        return $dir;
    }

    /**
     * Full path of a saved report.
     *
     * @throws \InvalidArgumentException For invalid or missing names.
     */
    public static function path(string $name): string {
        if (!preg_match('/^[a-z]+-\d{8}-\d{6}-[a-z0-9]{8}\.log$/', $name) || !is_file(self::reportsDir() . '/' . $name)) {
            throw new \InvalidArgumentException(esc_html__('That report was not found.', 'slice-backup'));
        }
        return self::reportsDir() . '/' . $name;
    }

    /**
     * Keeps only the newest reports.
     */
    private static function prune(): void {
        $files = glob(self::reportsDir() . '/*.log');
        if (!is_array($files) || count($files) <= self::KEEP_REPORTS) {
            return;
        }
        usort(
            $files,
            static function (string $a, string $b): int {
                return (int) filemtime($b) <=> (int) filemtime($a);
            }
        );
        foreach (array_slice($files, self::KEEP_REPORTS) as $old) {
            wp_delete_file($old);
        }
    }
}
