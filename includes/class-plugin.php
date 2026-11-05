<?php
/**
 * Plugin bootstrap: hooks, admin page, assets, settings and capability checks.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class.
 */
final class Plugin {
    public const OPTION_SETTINGS   = 'slice_backup_settings';
    public const OPTION_REPORT     = 'slice_backup_last_report';
    public const OPTION_DB_VERSION = 'slice_backup_db_version';
    public const PAGE_SLUG         = 'slice-backup';

    /** @var string */
    private static $hookSuffix = '';

    /**
     * Registers all hooks. Runs on plugins_loaded.
     */
    public static function init(): void {
        self::maybeUpgrade();

        add_action(is_multisite() ? 'network_admin_menu' : 'admin_menu', array(__CLASS__, 'registerMenu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueueAssets'));

        $basename = plugin_basename(SLICE_BACKUP_FILE);
        add_filter((is_multisite() ? 'network_admin_' : '') . 'plugin_action_links_' . $basename, array(__CLASS__, 'actionLinks'));

        AjaxController::init();
        RestController::init();
        Scheduler::init();

        try {
            MigratorEngine::watchRestore();
        } catch (\Throwable $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            // Never let the restore watcher break a page load.
        }

        if (defined('WP_CLI') && WP_CLI && class_exists(CliCommand::class)) {
            \WP_CLI::add_command('slice-backup', CliCommand::class);
        }
    }

    /**
     * Activation: create and protect the storage folder.
     */
    public static function activate(): void {
        try {
            Storage::dir();
        } catch (\Throwable $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            // Shown on the System tab instead of breaking activation.
        }
        Scheduler::reschedule();
    }

    /**
     * Deactivation: stop scheduled events. Bundles and settings are kept.
     */
    public static function deactivate(): void {
        $job = Job::active();
        if ($job !== null && $job->type === 'restore') {
            // A restore is replacing this site: ask it to stop safely instead of pulling the
            // guard away mid-restore. While the guard is active, plugin activation changes are
            // not saved, so Slice Backup stays active until the restore has ended.
            $job->requestCancel();
            return;
        }
        Storage::releaseLock();
        RestoreGuard::remove();
        wp_clear_scheduled_hook(Scheduler::HOOK);
        wp_unschedule_hook(MigratorEngine::TICK_HOOK);
    }

    /**
     * Plain-text message of an exception.
     *
     * Exception messages are HTML-escaped when thrown. This turns them back into plain
     * text for places that are not HTML: JSON for the admin screen (rendered with
     * textContent), the REST API, WP-CLI, emails and stored job status.
     */
    public static function errorText(\Throwable $e): string {
        return html_entity_decode($e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Capability required for everything. On multisite only Super Admins may use the plugin,
     * because a bundle contains (and a restore overwrites) the whole network.
     */
    public static function capability(): string {
        $default = is_multisite() ? 'manage_network_options' : 'manage_options';
        return (string) apply_filters('slice_backup_required_capability', $default);
    }

    public static function currentUserCan(): bool {
        return current_user_can(self::capability());
    }

    public static function adminUrl(string $tab = ''): string {
        $url = is_multisite()
            ? network_admin_url('admin.php?page=' . self::PAGE_SLUG)
            : admin_url('tools.php?page=' . self::PAGE_SLUG);
        return $tab !== '' ? $url . '#' . $tab : $url;
    }

    public static function registerMenu(): void {
        $title = __('Slice Backup', 'slice-backup');

        if (is_multisite()) {
            self::$hookSuffix = (string) add_menu_page($title, __('Slice Backup', 'slice-backup'), self::capability(), self::PAGE_SLUG, array(__CLASS__, 'renderPage'), 'dashicons-migrate', 81);
        } else {
            self::$hookSuffix = (string) add_management_page($title, __('Slice Backup', 'slice-backup'), self::capability(), self::PAGE_SLUG, array(__CLASS__, 'renderPage'));
        }
    }

    /**
     * @param string[] $links
     * @return string[]
     */
    public static function actionLinks(array $links): array {
        array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url(self::adminUrl()), esc_html__('Open', 'slice-backup')));
        return $links;
    }

    public static function renderPage(): void {
        if (!self::currentUserCan()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'slice-backup'), '', array('response' => 403));
        }

        $view = self::viewData();
        require SLICE_BACKUP_PATH . 'views/admin-page.php';
    }

    public static function enqueueAssets(string $hook): void {
        if (self::$hookSuffix === '' || $hook !== self::$hookSuffix) {
            return;
        }

        wp_enqueue_style('slicebk-admin', SLICE_BACKUP_URL . 'assets/css/admin.css', array('dashicons'), SLICE_BACKUP_VERSION);
        wp_enqueue_script('slicebk-admin', SLICE_BACKUP_URL . 'assets/js/admin.js', array('wp-i18n'), SLICE_BACKUP_VERSION, true);
        wp_set_script_translations('slicebk-admin', 'slice-backup', SLICE_BACKUP_PATH . 'languages');

        $config = array(
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('slice_backup_admin'),
            'siteUrl'       => untrailingslashit((string) get_option('siteurl')),
            'homeUrl'       => untrailingslashit(home_url()),
            'loginUrl'      => wp_login_url(self::adminUrl()),
            'isMultisite'   => is_multisite(),
            'networkDomain' => is_multisite() && defined('DOMAIN_CURRENT_SITE') ? (string) DOMAIN_CURRENT_SITE : '',
            'chunkSize'     => self::uploadChunkSize(),
            'components'    => self::components(),
            'presets'       => MigratorEngine::PRESETS,
            'settings'      => self::publicSettings(),
            'providers'     => Cloud::providers(),
            'cloudReady'    => Cloud::isConfigured(),
            'cryptoReady'   => Crypto::available(),
            'pollSeconds'   => 2,
        );

        wp_add_inline_script('slicebk-admin', 'window.sliceBackupConfig = ' . wp_json_encode($config) . ';', 'before');
    }

    /**
     * Bytes per upload request: below the server's upload limit, capped at 8 MB.
     */
    public static function uploadChunkSize(): int {
        $max = (int) wp_max_upload_size();
        if ($max <= 0) {
            $max = 2 * MB_IN_BYTES;
        }
        return (int) max(256 * KB_IN_BYTES, min(8 * MB_IN_BYTES, (int) floor($max * 0.9)));
    }

    /**
     * Component labels used by the UI.
     *
     * @return array<string, array{label: string, description: string, icon: string}>
     */
    public static function components(): array {
        return array(
            'db'         => array(
                'label'       => __('Database', 'slice-backup'),
                'description' => __('Posts, pages, users, settings and plugin data', 'slice-backup'),
                'icon'        => 'dashicons-database',
            ),
            'uploads'    => array(
                'label'       => __('Media library', 'slice-backup'),
                'description' => __('Everything in wp-content/uploads', 'slice-backup'),
                'icon'        => 'dashicons-format-image',
            ),
            'plugins'    => array(
                'label'       => __('Plugins', 'slice-backup'),
                'description' => __('wp-content/plugins (this plugin is left out)', 'slice-backup'),
                'icon'        => 'dashicons-admin-plugins',
            ),
            'themes'     => array(
                'label'       => __('Themes', 'slice-backup'),
                'description' => __('wp-content/themes', 'slice-backup'),
                'icon'        => 'dashicons-admin-appearance',
            ),
            'mu_plugins' => array(
                'label'       => __('Must-use plugins', 'slice-backup'),
                'description' => __('wp-content/mu-plugins', 'slice-backup'),
                'icon'        => 'dashicons-admin-generic',
            ),
            'languages'  => array(
                'label'       => __('Translations', 'slice-backup'),
                'description' => __('wp-content/languages', 'slice-backup'),
                'icon'        => 'dashicons-translation',
            ),
            'root_files' => array(
                'label'       => __('Server config files', 'slice-backup'),
                'description' => __('wp-config.php, .htaccess, robots.txt and similar', 'slice-backup'),
                'icon'        => 'dashicons-media-code',
            ),
            'wp_core'    => array(
                'label'       => __('WordPress core', 'slice-backup'),
                'description' => __('wp-admin and wp-includes. Not needed if the destination already runs WordPress', 'slice-backup'),
                'icon'        => 'dashicons-wordpress',
            ),
        );
    }

    /**
     * Default settings.
     *
     * @return array<string, mixed>
     */
    private static function defaults(): array {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        return array(
            'chunk_size'      => 2500,
            'retention_count' => 5,
            'step_seconds'    => 20,
            'encryption'      => array(
                'by_default'        => false,
                'passphrase_sealed' => '',
            ),
            'cloud'           => array(
                'provider'          => '',
                'access_key'        => '',
                'secret_key_sealed' => '',
                'bucket'            => '',
                'region'            => '',
                'endpoint'          => '',
                'account_id'        => '',
                'path_style'        => true,
                'path'              => 'slice-backup/' . sanitize_title($host !== '' ? $host : 'site'),
                'auto_upload'       => false,
                'delete_local'      => false,
                // SFTP.
                'sftp_host'            => '',
                'sftp_port'            => 22,
                'sftp_user'            => '',
                'sftp_auth'            => 'password',
                'sftp_password_sealed' => '',
                'sftp_key_sealed'      => '',
                'sftp_key_pass_sealed' => '',
                'sftp_fingerprint'     => '',
                // Dropbox.
                'dropbox_app_key'        => '',
                'dropbox_refresh_sealed' => '',
                'dropbox_account'        => '',
            ),
            'peer'            => array(
                'receive_enabled' => false,
                'key_id'          => '',
                'secret_sealed'   => '',
                'expires'         => 0,
            ),
            'schedule'        => array(
                'enabled'      => false,
                'frequency'    => 'daily',
                'time'         => '03:00',
                'weekday'      => 0,
                'preset'       => 'full',
                'encrypt'      => false,
                'upload'       => false,
                'delete_local' => false,
                'keep_daily'   => 7,
                'keep_weekly'  => 4,
                'keep_monthly' => 6,
                'notify_email' => (string) get_site_option('admin_email', get_option('admin_email')),
                'notify_on'    => 'failure',
                'webhook'      => '',
            ),
        );
    }

    /**
     * Saved settings merged with defaults and clamped to valid values.
     *
     * @return array<string, mixed>
     */
    public static function settings(): array {
        $saved    = get_site_option(self::OPTION_SETTINGS, array());
        $saved    = is_array($saved) ? $saved : array();
        $defaults = self::defaults();

        $s = array_merge($defaults, $saved);
        foreach (array('encryption', 'cloud', 'schedule', 'peer') as $group) {
            $s[$group] = array_merge($defaults[$group], isset($saved[$group]) && is_array($saved[$group]) ? $saved[$group] : array());
        }

        $s['chunk_size']      = max(500, min(10000, (int) $s['chunk_size']));
        $s['retention_count'] = max(0, min(100, (int) $s['retention_count']));
        $s['step_seconds']    = max(5, min(120, (int) $s['step_seconds']));
        return $s;
    }

    /**
     * Saves one settings section from user input. Secret fields are only replaced
     * when a new value is entered.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> The new settings.
     * @throws \InvalidArgumentException On invalid values.
     */
    public static function saveSettings(array $input, string $section = 'general'): array {
        $s    = self::settings();
        $bool = static function ($key) use ($input): bool {
            return MigratorEngine::truthy($input[$key] ?? false);
        };

        switch ($section) {
            case 'general':
                $s['chunk_size']      = max(500, min(10000, absint($input['chunk_size'] ?? 2500)));
                $s['retention_count'] = max(0, min(100, absint($input['retention_count'] ?? 5)));
                $s['step_seconds']    = max(5, min(120, absint($input['step_seconds'] ?? 20)));
                break;

            case 'encryption':
                $s['encryption']['by_default'] = $bool('by_default');
                $passphrase = (string) ($input['passphrase'] ?? '');
                if (!empty($input['clear_passphrase'])) {
                    $s['encryption']['passphrase_sealed'] = '';
                } elseif ($passphrase !== '') {
                    if (strlen($passphrase) < 8) {
                        throw new \InvalidArgumentException(esc_html__('The passphrase must be at least 8 characters.', 'slice-backup'));
                    }
                    $s['encryption']['passphrase_sealed'] = Crypto::seal($passphrase);
                }
                break;

            case 'cloud':
                $providers = Cloud::providers();
                $provider  = sanitize_key((string) ($input['provider'] ?? ''));
                $s['cloud']['provider']     = isset($providers[$provider]) ? $provider : '';
                $s['cloud']['access_key']   = sanitize_text_field((string) ($input['access_key'] ?? ''));
                $s['cloud']['bucket']       = sanitize_text_field((string) ($input['bucket'] ?? ''));
                $s['cloud']['region']       = sanitize_text_field((string) ($input['region'] ?? ''));
                $s['cloud']['endpoint']     = esc_url_raw((string) ($input['endpoint'] ?? ''));
                $s['cloud']['account_id']   = sanitize_text_field((string) ($input['account_id'] ?? ''));
                $s['cloud']['path_style']   = $bool('path_style');
                $s['cloud']['path']         = trim(sanitize_text_field((string) ($input['path'] ?? '')), '/');
                $s['cloud']['auto_upload']  = $bool('auto_upload');
                $s['cloud']['delete_local'] = $bool('delete_local');
                $secret = (string) ($input['secret_key'] ?? '');
                if ($secret !== '') {
                    $s['cloud']['secret_key_sealed'] = Crypto::seal($secret);
                }

                // SFTP: changing the server forgets the pinned host key.
                $host = trim(sanitize_text_field((string) ($input['sftp_host'] ?? '')));
                $port = max(1, min(65535, absint($input['sftp_port'] ?? 22)));
                if ($host !== $s['cloud']['sftp_host'] || $port !== (int) $s['cloud']['sftp_port'] || !empty($input['sftp_forget_key'])) {
                    $s['cloud']['sftp_fingerprint'] = '';
                }
                $fingerprint = sanitize_text_field((string) ($input['sftp_fingerprint'] ?? ''));
                if ($fingerprint !== '' && empty($input['sftp_forget_key']) && preg_match('#^SHA256:[A-Za-z0-9+/]{43}$#', $fingerprint)) {
                    $s['cloud']['sftp_fingerprint'] = $fingerprint;
                }
                $s['cloud']['sftp_host'] = $host;
                $s['cloud']['sftp_port'] = $port;
                $s['cloud']['sftp_user'] = sanitize_text_field((string) ($input['sftp_user'] ?? ''));
                $s['cloud']['sftp_auth'] = ($input['sftp_auth'] ?? '') === 'key' ? 'key' : 'password';
                foreach (array('sftp_password', 'sftp_key', 'sftp_key_pass') as $field) {
                    $value = (string) ($input[$field] ?? '');
                    if ($value !== '') {
                        $s['cloud'][$field . '_sealed'] = Crypto::seal($field === 'sftp_key' ? trim($value) . "\n" : $value);
                    }
                }

                // Dropbox: a new app key requires connecting again.
                $appKey = sanitize_text_field((string) ($input['dropbox_app_key'] ?? ''));
                if ($appKey !== $s['cloud']['dropbox_app_key']) {
                    $s['cloud']['dropbox_refresh_sealed'] = '';
                    $s['cloud']['dropbox_account']        = '';
                }
                $s['cloud']['dropbox_app_key'] = $appKey;

                if ($s['cloud']['provider'] === '') {
                    foreach (array('secret_key_sealed', 'sftp_password_sealed', 'sftp_key_sealed', 'sftp_key_pass_sealed', 'dropbox_refresh_sealed') as $sealed) {
                        $s['cloud'][$sealed] = '';
                    }
                    $s['cloud']['sftp_fingerprint'] = '';
                    $s['cloud']['dropbox_account']  = '';
                }
                break;

            case 'schedule':
                $frequency = sanitize_key((string) ($input['frequency'] ?? 'daily'));
                $time      = (string) ($input['time'] ?? '03:00');
                $preset    = sanitize_key((string) ($input['preset'] ?? 'full'));
                $notifyOn  = sanitize_key((string) ($input['notify_on'] ?? 'failure'));
                $email     = sanitize_email((string) ($input['notify_email'] ?? ''));
                $webhook   = esc_url_raw((string) ($input['webhook'] ?? ''));

                $s['schedule'] = array(
                    'enabled'      => $bool('enabled'),
                    'frequency'    => isset(Scheduler::frequencies()[$frequency]) ? $frequency : 'daily',
                    'time'         => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '03:00',
                    'weekday'      => max(0, min(6, (int) ($input['weekday'] ?? 0))),
                    'preset'       => isset(MigratorEngine::PRESETS[$preset]) ? $preset : 'full',
                    'encrypt'      => $bool('encrypt'),
                    'upload'       => $bool('upload'),
                    'delete_local' => $bool('delete_local'),
                    'keep_daily'   => max(1, min(365, absint($input['keep_daily'] ?? 7))),
                    'keep_weekly'  => max(0, min(104, absint($input['keep_weekly'] ?? 4))),
                    'keep_monthly' => max(0, min(120, absint($input['keep_monthly'] ?? 6))),
                    'notify_email' => $email,
                    'notify_on'    => in_array($notifyOn, array('never', 'failure', 'always'), true) ? $notifyOn : 'failure',
                    'webhook'      => $webhook,
                );
                if ($s['schedule']['enabled'] && $s['schedule']['encrypt'] && self::storedPassphrase() === '') {
                    throw new \InvalidArgumentException(esc_html__('Save an encryption passphrase under Settings > Encryption before scheduling encrypted backups.', 'slice-backup'));
                }
                if ($s['schedule']['enabled'] && $s['schedule']['upload'] && !Cloud::isConfigured()) {
                    throw new \InvalidArgumentException(esc_html__('Set up cloud storage before scheduling uploads.', 'slice-backup'));
                }
                break;
        }

        update_site_option(self::OPTION_SETTINGS, $s);
        if ($section === 'schedule') {
            Scheduler::reschedule();
        }
        return $s;
    }

    /**
     * The saved encryption passphrase. A SLICE_BACKUP_ENCRYPTION_PASSPHRASE constant in
     * wp-config.php takes precedence, so the passphrase need not be in the database.
     */
    public static function storedPassphrase(): string {
        if (defined('SLICE_BACKUP_ENCRYPTION_PASSPHRASE') && is_string(SLICE_BACKUP_ENCRYPTION_PASSPHRASE) && SLICE_BACKUP_ENCRYPTION_PASSPHRASE !== '') {
            return SLICE_BACKUP_ENCRYPTION_PASSPHRASE;
        }
        return Crypto::open((string) self::settings()['encryption']['passphrase_sealed']);
    }

    /**
     * Settings safe to send to the browser (secrets replaced by "is set" flags).
     *
     * @return array<string, mixed>
     */
    public static function publicSettings(): array {
        $s = self::settings();
        $s['encryption']['has_passphrase']  = self::storedPassphrase() !== '';
        $s['encryption']['from_constant']   = defined('SLICE_BACKUP_ENCRYPTION_PASSPHRASE');
        $has = static function (string $sealed): bool {
            return $sealed !== '' && Crypto::open($sealed) !== '';
        };
        $s['cloud']['has_secret']        = $has((string) $s['cloud']['secret_key_sealed']);
        $s['cloud']['has_sftp_password'] = $has((string) $s['cloud']['sftp_password_sealed']);
        $s['cloud']['has_sftp_key']      = $has((string) $s['cloud']['sftp_key_sealed']);
        $s['cloud']['dropbox_connected'] = $has((string) $s['cloud']['dropbox_refresh_sealed']);
        $s['peer']['has_key']            = $has((string) $s['peer']['secret_sealed']) && (int) $s['peer']['expires'] > time();
        unset(
            $s['encryption']['passphrase_sealed'],
            $s['cloud']['secret_key_sealed'],
            $s['cloud']['sftp_password_sealed'],
            $s['cloud']['sftp_key_sealed'],
            $s['cloud']['sftp_key_pass_sealed'],
            $s['cloud']['dropbox_refresh_sealed'],
            $s['peer']['secret_sealed']
        );
        return $s;
    }

    /**
     * Stores the SFTP host key fingerprint seen on the first connection (trust on first use).
     */
    public static function pinSftpFingerprint(string $fingerprint): void {
        $s = self::settings();
        if ((string) $s['cloud']['sftp_fingerprint'] === '') {
            $s['cloud']['sftp_fingerprint'] = $fingerprint;
            update_site_option(self::OPTION_SETTINGS, $s);
        }
    }

    /**
     * Stores the Dropbox refresh token after the user connected their account.
     */
    public static function saveDropboxConnection(string $refreshToken, string $account): void {
        $s = self::settings();
        $s['cloud']['dropbox_refresh_sealed'] = Crypto::seal($refreshToken);
        $s['cloud']['dropbox_account']        = sanitize_text_field($account);
        update_site_option(self::OPTION_SETTINGS, $s);
    }

    /**
     * Writes the whole settings array (used by Peer for key management).
     *
     * @param array<string, mixed> $settings
     */
    public static function replaceSettings(array $settings): void {
        update_site_option(self::OPTION_SETTINGS, $settings);
    }

    /**
     * Data for the admin page template.
     *
     * @return array<string, mixed>
     */
    public static function viewData(): array {
        $subsites = array();
        if (is_multisite()) {
            foreach ((array) get_sites(array('number' => 500)) as $site) {
                $subsites[] = array(
                    'id'   => (int) $site->blog_id,
                    'name' => (string) $site->blogname,
                    'url'  => get_site_url((int) $site->blog_id),
                );
            }
        }

        $storageError = '';
        try {
            $storage = Storage::dir();
        } catch (\Throwable $e) {
            $storage      = '';
            $storageError = self::errorText($e);
        }

        return array(
            'siteUrl'      => (string) get_option('siteurl'),
            'isMultisite'  => is_multisite(),
            'subsites'     => $subsites,
            'settings'     => self::publicSettings(),
            'providers'    => Cloud::providers(),
            'frequencies'  => Scheduler::frequencies(),
            'nextRun'      => Scheduler::nextRun(),
            'lastRun'      => get_site_option(Scheduler::OPTION_LAST),
            'cloudReady'   => Cloud::isConfigured(),
            'cloudLabel'   => Cloud::describe(),
            'cryptoReady'  => Crypto::available(),
            'components'   => self::components(),
            'storageDir'   => $storage,
            'storageError' => $storageError,
            'maxUpload'    => size_format(wp_max_upload_size()),
            'checks'       => SystemCheck::run(),
            'hasReport'    => is_array(get_site_option(self::OPTION_REPORT)),
            'rollback'     => RestoreRollback::info(),
        );
    }

    /**
     * One-time data migrations between versions.
     */
    private static function maybeUpgrade(): void {
        if (get_site_option(self::OPTION_DB_VERSION) === SLICE_BACKUP_VERSION) {
            return;
        }

        // Future data migrations between versions go here.

        update_site_option(self::OPTION_DB_VERSION, SLICE_BACKUP_VERSION);
    }
}
