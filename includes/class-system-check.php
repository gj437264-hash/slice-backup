<?php
/**
 * Real server diagnostics and size estimates.
 *
 * @package WpBundleMigrator
 */

declare(strict_types=1);

namespace WpBundleMigrator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Environment checks shown on the System tab.
 */
final class SystemCheck {
    private const PROBE_TRANSIENT = 'wpbm_web_probe';

    /**
     * @return array<int, array{id: string, label: string, value: string, status: string, help: string}>
     */
    public static function run(): array {
        global $wpdb;

        $rows = array();

        $php       = PHP_VERSION;
        $phpStatus = version_compare($php, WPBM_MIN_PHP, '<') ? 'fail' : (version_compare($php, '8.1', '<') ? 'warn' : 'ok');
        $rows[]    = self::row(
            'php',
            __('PHP version', 'wp-bundle-migrator'),
            $php,
            $phpStatus,
            $phpStatus === 'warn' ? __('Supported, but this PHP version no longer receives security updates. Ask your host for PHP 8.1 or newer.', 'wp-bundle-migrator') : ''
        );

        $rows[] = self::row('wp', __('WordPress version', 'wp-bundle-migrator'), get_bloginfo('version'), version_compare(get_bloginfo('version'), WPBM_MIN_WP, '<') ? 'fail' : 'ok');

        $zip    = class_exists('ZipArchive');
        $rows[] = self::row(
            'zip',
            __('PHP zip extension', 'wp-bundle-migrator'),
            $zip ? __('Enabled', 'wp-bundle-migrator') : __('Missing', 'wp-bundle-migrator'),
            $zip ? 'ok' : 'fail',
            $zip ? '' : __('Required to create and restore bundles. Ask your host to enable the "zip" PHP extension.', 'wp-bundle-migrator')
        );

        $crypto = Crypto::available();
        $rows[] = self::row(
            'openssl',
            __('Encryption (OpenSSL AES-256-GCM)', 'wp-bundle-migrator'),
            $crypto ? __('Available', 'wp-bundle-migrator') : __('Missing', 'wp-bundle-migrator'),
            $crypto ? 'ok' : 'warn',
            $crypto ? '' : __('Encrypted bundles and stored cloud keys need the PHP OpenSSL extension.', 'wp-bundle-migrator')
        );

        $sockets = function_exists('fsockopen') && strpos((string) ini_get('disable_functions'), 'fsockopen') === false;
        $rows[]  = self::row(
            'sftp',
            __('SFTP support', 'wp-bundle-migrator'),
            $sockets ? __('Available (bundled phpseclib)', 'wp-bundle-migrator') : __('Unavailable', 'wp-bundle-migrator'),
            $sockets ? 'ok' : 'warn',
            $sockets
                ? (extension_loaded('gmp') || extension_loaded('openssl') ? '' : __('Works, but the PHP gmp or openssl extension would make connections faster.', 'wp-bundle-migrator'))
                : __('The host disabled fsockopen(), so this server cannot open SFTP connections.', 'wp-bundle-migrator')
        );

        $cronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $next         = Scheduler::nextRun();
        $overdue      = $next !== null && $next < time() - HOUR_IN_SECONDS;
        $cronValue    = $cronDisabled ? __('Disabled in wp-config.php (needs a server cron job)', 'wp-bundle-migrator') : __('Enabled', 'wp-bundle-migrator');
        if ($next !== null) {
            $cronValue .= ' · ' . sprintf(
                /* translators: %s: date and time. */
                __('next backup %s', 'wp-bundle-migrator'),
                wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $next)
            );
        }
        $rows[] = self::row(
            'cron',
            __('WP-Cron (scheduled backups)', 'wp-bundle-migrator'),
            $cronValue,
            $overdue ? 'fail' : ($cronDisabled ? 'warn' : 'ok'),
            $overdue
                ? __('A scheduled backup is more than an hour late, so WP-Cron is not running. Add a server cron job that calls wp-cron.php every 5 minutes.', 'wp-bundle-migrator')
                : __('WP-Cron runs when people visit the site. On low-traffic sites, add a server cron job that calls wp-cron.php every 5 minutes so schedules run on time.', 'wp-bundle-migrator')
        );

        $tokenizer = function_exists('token_get_all');
        $rows[]    = self::row(
            'tokenizer',
            __('PHP tokenizer extension', 'wp-bundle-migrator'),
            $tokenizer ? __('Enabled', 'wp-bundle-migrator') : __('Missing', 'wp-bundle-migrator'),
            $tokenizer ? 'ok' : 'warn',
            $tokenizer ? '' : __('Without it, the PHP 8 code check during restore is skipped.', 'wp-bundle-migrator')
        );

        try {
            $dir      = Storage::dir();
            $writable = wp_is_writable($dir);
            $rows[]   = self::row(
                'storage',
                __('Backup folder', 'wp-bundle-migrator'),
                self::relativePath($dir),
                $writable ? 'ok' : 'fail',
                $writable ? '' : __('The folder is not writable. Fix its permissions so bundles can be saved.', 'wp-bundle-migrator')
            );

            $free = function_exists('disk_free_space') ? @disk_free_space($dir) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if ($free !== false) {
                $rows[] = self::row(
                    'disk',
                    __('Free disk space', 'wp-bundle-migrator'),
                    size_format((float) $free, 1),
                    $free < GB_IN_BYTES ? 'warn' : 'ok',
                    $free < GB_IN_BYTES ? __('A restore needs room for the bundle plus its extracted contents (about twice the bundle size).', 'wp-bundle-migrator') : ''
                );
            }
        } catch (\Throwable $e) {
            $rows[] = self::row('storage', __('Backup folder', 'wp-bundle-migrator'), __('Unavailable', 'wp-bundle-migrator'), 'fail', $e->getMessage());
        }

        $memory      = (string) ini_get('memory_limit');
        $memoryBytes = $memory === '-1' ? PHP_INT_MAX : wp_convert_hr_to_bytes($memory);
        $rows[]      = self::row(
            'memory',
            __('PHP memory limit', 'wp-bundle-migrator'),
            $memory === '-1' ? __('Unlimited', 'wp-bundle-migrator') : $memory,
            $memoryBytes < 128 * MB_IN_BYTES ? 'warn' : 'ok',
            $memoryBytes < 128 * MB_IN_BYTES ? __('128 MB or more is recommended.', 'wp-bundle-migrator') : ''
        );

        $time   = (int) ini_get('max_execution_time');
        $rows[] = self::row(
            'time',
            __('Max execution time', 'wp-bundle-migrator'),
            $time === 0 ? __('Unlimited', 'wp-bundle-migrator') : sprintf(
                /* translators: %d: seconds. */
                _n('%d second', '%d seconds', $time, 'wp-bundle-migrator'),
                $time
            ),
            'ok',
            sprintf(
                /* translators: %d: seconds. */
                __('Backups and restores run in steps of %d seconds each and resume automatically, so server and proxy time limits do not stop them.', 'wp-bundle-migrator'),
                (int) Plugin::settings()['step_seconds']
            )
        );

        $rows[] = self::row(
            'upload',
            __('Upload limit per request', 'wp-bundle-migrator'),
            size_format(wp_max_upload_size()),
            'info',
            __('Bundles of any size are uploaded in smaller pieces, so this limit does not cap the bundle size.', 'wp-bundle-migrator')
        );

        $packet = (int) $wpdb->get_var('SELECT @@max_allowed_packet');
        if ($packet > 0) {
            $rows[] = self::row(
                'packet',
                __('MySQL max_allowed_packet', 'wp-bundle-migrator'),
                size_format($packet),
                $packet < 2 * MB_IN_BYTES ? 'warn' : 'ok',
                $packet < 2 * MB_IN_BYTES ? __('Very large single values (for example big serialized options) may fail to import.', 'wp-bundle-migrator') : ''
            );
        }

        $rows[] = self::row('db', __('Database server', 'wp-bundle-migrator'), (string) $wpdb->db_server_info(), 'info');

        $rows[] = self::row(
            'multisite',
            __('Multisite', 'wp-bundle-migrator'),
            is_multisite()
                ? sprintf(
                    /* translators: %d: number of sites. */
                    _n('Network with %d site', 'Network with %d sites', (int) get_blog_count(), 'wp-bundle-migrator'),
                    (int) get_blog_count()
                )
                : __('Single site', 'wp-bundle-migrator'),
            'info'
        );

        return $rows;
    }

    /**
     * Tries to download a test file from the backup folder over HTTP. If that works,
     * backups are publicly downloadable (typical on Nginx, which ignores .htaccess).
     *
     * @return array{id: string, label: string, value: string, status: string, help: string}
     */
    public static function webAccessProbe(bool $force = false): array {
        $label  = __('Backups hidden from the web', 'wp-bundle-migrator');
        $cached = get_site_transient(self::PROBE_TRANSIENT);
        if (!$force && is_array($cached)) {
            return $cached;
        }

        try {
            $dir = Storage::dir();
        } catch (\Throwable $e) {
            return self::row('probe', $label, __('Unknown', 'wp-bundle-migrator'), 'warn', $e->getMessage());
        }

        $name  = 'probe-' . strtolower(wp_generate_password(16, false)) . '.txt';
        $token = wp_generate_password(24, false);
        file_put_contents($dir . '/' . $name, $token);

        $response = wp_remote_get(
            Storage::url() . '/' . $name,
            array(
                'timeout'     => 8,
                'redirection' => 0,
                'sslverify'   => (bool) apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
            )
        );
        @unlink($dir . '/' . $name); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        if (is_wp_error($response)) {
            $row = self::row(
                'probe',
                $label,
                __('Could not test', 'wp-bundle-migrator'),
                'warn',
                __('The server could not make a request to itself. Check manually that files in the backup folder cannot be opened in a browser.', 'wp-bundle-migrator')
            );
        } elseif ((int) wp_remote_retrieve_response_code($response) === 200 && trim((string) wp_remote_retrieve_body($response)) === $token) {
            $row = self::row(
                'probe',
                $label,
                __('No - publicly reachable', 'wp-bundle-migrator'),
                'fail',
                sprintf(
                    /* translators: %s: Nginx config snippet. */
                    __('Your web server ignores the .htaccess protection (common with Nginx). Anyone who guesses a file name could download a backup. Ask your host to add: %s', 'wp-bundle-migrator'),
                    'location ^~ ' . wp_parse_url(Storage::url(), PHP_URL_PATH) . '/ { deny all; }'
                )
            );
        } else {
            $row = self::row('probe', $label, __('Yes', 'wp-bundle-migrator'), 'ok');
        }

        set_site_transient(self::PROBE_TRANSIENT, $row, 12 * HOUR_IN_SECONDS);
        return $row;
    }

    /**
     * Approximate size of each component. Stops counting files after a time budget.
     *
     * @return array<string, array{bytes: float, partial: bool}>
     */
    public static function estimateSizes(): array {
        global $wpdb;

        $deadline = microtime(true) + 8.0;
        $sizes    = array();

        $db             = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s',
                DB_NAME,
                $wpdb->esc_like((string) $wpdb->base_prefix) . '%'
            )
        );
        $sizes['db'] = array('bytes' => (float) $db, 'partial' => false);

        $uploads = wp_upload_dir(null, false);
        $storage = '';
        try {
            $storage = Storage::dir();
        } catch (\Throwable $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            // Nothing to exclude.
        }

        $dirs = array(
            'uploads'    => array(array((string) $uploads['basedir']), array($storage)),
            'plugins'    => array(array(WP_PLUGIN_DIR), array(untrailingslashit(WPBM_PATH))),
            'themes'     => array(array(get_theme_root()), array()),
            'mu_plugins' => array(array(defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins'), array()),
            'languages'  => array(array(defined('WP_LANG_DIR') ? WP_LANG_DIR : WP_CONTENT_DIR . '/languages'), array()),
            'wp_core'    => array(array(ABSPATH . 'wp-admin', ABSPATH . WPINC), array()),
        );

        foreach ($dirs as $key => $spec) {
            $bytes   = 0.0;
            $partial = false;
            foreach ($spec[0] as $path) {
                $result   = self::directorySize((string) $path, $spec[1], $deadline);
                $bytes   += $result['bytes'];
                $partial  = $partial || $result['partial'];
            }
            $sizes[$key] = array('bytes' => $bytes, 'partial' => $partial);
        }

        $root = 0.0;
        foreach ((array) scandir(ABSPATH) as $file) {
            if (is_string($file) && preg_match(StreamingArchiver::ROOT_FILES_PATTERN, $file) && is_file(ABSPATH . $file)) {
                $root += (float) filesize(ABSPATH . $file);
            }
        }
        $sizes['root_files'] = array('bytes' => $root, 'partial' => false);

        return $sizes;
    }

    /**
     * @param string[] $exclude
     * @return array{bytes: float, partial: bool}
     */
    private static function directorySize(string $dir, array $exclude, float $deadline): array {
        $dir = rtrim(wp_normalize_path($dir), '/');
        if (!is_dir($dir)) {
            return array('bytes' => 0.0, 'partial' => false);
        }

        $exclude = array_filter(
            array_map(
                static function (string $path): string {
                    return rtrim(wp_normalize_path($path), '/');
                },
                $exclude
            )
        );

        $bytes = 0.0;
        $count = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $current) use ($exclude): bool {
                        $path = wp_normalize_path($current->getPathname());
                        foreach ($exclude as $ex) {
                            if ($path === $ex || strpos($path, $ex . '/') === 0) {
                                return false;
                            }
                        }
                        return true;
                    }
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $bytes += (float) $file->getSize();
                }
                if (++$count % 1000 === 0 && microtime(true) > $deadline) {
                    return array('bytes' => $bytes, 'partial' => true);
                }
            }
        } catch (\Throwable $e) {
            return array('bytes' => $bytes, 'partial' => true);
        }

        return array('bytes' => $bytes, 'partial' => false);
    }

    private static function relativePath(string $path): string {
        $abs = wp_normalize_path(ABSPATH);
        return strpos($path, $abs) === 0 ? '/' . ltrim(substr($path, strlen($abs)), '/') : $path;
    }

    /**
     * @return array{id: string, label: string, value: string, status: string, help: string}
     */
    private static function row(string $id, string $label, string $value, string $status, string $help = ''): array {
        return array(
            'id'     => $id,
            'label'  => $label,
            'value'  => $value,
            'status' => $status,
            'help'   => $help,
        );
    }
}
