<?php
/**
 * Email and webhook notifications for scheduled backups.
 *
 * @package WpBundleMigrator
 */

declare(strict_types=1);

namespace WpBundleMigrator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email and webhook notifications for scheduled backups.
 */
final class Notifier {
    /**
     * @param array<string, mixed>|null $result
     */
    public static function backupResult(bool $success, Job $job, ?array $result): void {
        update_site_option(
            Scheduler::OPTION_LAST,
            array(
                'time'   => time(),
                'status' => $success ? 'done' : 'failed',
                'error'  => $success ? '' : $job->error,
                'file'   => $success ? (string) ($result['bundleFile'] ?? '') : '',
                'size'   => $success ? (string) ($result['formattedSize'] ?? '') : '',
            )
        );

        $message = $success
            ? sprintf(
                /* translators: 1: file name, 2: size. */
                __('Scheduled backup created: %1$s (%2$s).', 'wp-bundle-migrator'),
                (string) ($result['bundleFile'] ?? ''),
                (string) ($result['formattedSize'] ?? '')
            )
            : sprintf(
                /* translators: %s: error message. */
                __('Scheduled backup failed: %s', 'wp-bundle-migrator'),
                $job->error
            );

        self::send($success, $message, array('job' => $job->id, 'result' => $result, 'error' => $job->error, 'warnings' => $job->warnings));
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function send(bool $success, string $message, array $details): void {
        $schedule = Plugin::settings()['schedule'];
        $site     = (string) get_option('siteurl');

        $email = (string) $schedule['notify_email'];
        $when  = (string) $schedule['notify_on'];
        if (is_email($email) && ($when === 'always' || (!$success && $when === 'failure'))) {
            $subject = sprintf(
                /* translators: 1: site host, 2: status. */
                __('[%1$s] Backup %2$s', 'wp-bundle-migrator'),
                wp_parse_url($site, PHP_URL_HOST),
                $success ? __('succeeded', 'wp-bundle-migrator') : __('FAILED', 'wp-bundle-migrator')
            );
            $body = $message . "\n\n" . sprintf(
                /* translators: %s: admin URL. */
                __('Manage backups: %s', 'wp-bundle-migrator'),
                Plugin::adminUrl('backups')
            );
            wp_mail($email, $subject, $body);
        }

        $webhook = (string) $schedule['webhook'];
        if ($webhook !== '' && wp_http_validate_url($webhook)) {
            // wp_safe_remote_post refuses private/internal addresses (SSRF protection).
            wp_safe_remote_post(
                $webhook,
                array(
                    'timeout' => 10,
                    'headers' => array('Content-Type' => 'application/json'),
                    'body'    => (string) wp_json_encode(
                        array(
                            'event'   => $success ? 'backup.succeeded' : 'backup.failed',
                            'site'    => $site,
                            'message' => $message,
                            'time'    => gmdate('c'),
                            'details' => $details,
                        )
                    ),
                )
            );
        }
    }
}
