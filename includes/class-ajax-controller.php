<?php
/**
 * admin-ajax endpoints used by the admin screen.
 *
 * Every handler checks the capability and a nonce before reading input, except the
 * job tick, which is authorized by the job's random token (so a restore can keep
 * running after the database, and with it the user's session, has been replaced).
 *
 * @package WpBundleMigrator
 */

declare(strict_types=1);

namespace WpBundleMigrator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handlers.
 */
final class AjaxController {
    private const ACTIONS = array(
        'wpbm_job_start'     => 'jobStart',
        'wpbm_job_status'    => 'jobStatus',
        'wpbm_job_cancel'    => 'jobCancel',
        'wpbm_list'          => 'listBundles',
        'wpbm_inspect'       => 'inspect',
        'wpbm_delete'        => 'delete',
        'wpbm_upload_chunk'  => 'uploadChunk',
        'wpbm_save_settings' => 'saveSettings',
        'wpbm_estimate'      => 'estimate',
        'wpbm_report'        => 'lastReport',
        'wpbm_probe'         => 'probe',
        'wpbm_download'      => 'download',
        'wpbm_cloud_test'    => 'cloudTest',
        'wpbm_cloud_list'    => 'cloudList',
        'wpbm_cloud_delete'  => 'cloudDelete',
        'wpbm_schedule_now'  => 'scheduleNow',
        'wpbm_peer_key'      => 'peerKey',
        'wpbm_peer_revoke'   => 'peerRevoke',
        'wpbm_peer_check'    => 'peerCheck',
        'wpbm_dropbox_start' => 'dropboxStart',
        'wpbm_dropbox_finish' => 'dropboxFinish',
    );

    public static function init(): void {
        foreach (self::ACTIONS as $action => $method) {
            add_action('wp_ajax_' . $action, array(__CLASS__, $method));
        }
        add_action('wp_ajax_wpbm_job_tick', array(__CLASS__, 'jobTick'));
        add_action('wp_ajax_nopriv_wpbm_job_tick', array(__CLASS__, 'jobTick'));
    }

    private static function guard(): void {
        if (!Plugin::currentUserCan()) {
            wp_send_json_error(array('message' => __('You do not have permission to do this.', 'wp-bundle-migrator')), 403);
        }
        if (!check_ajax_referer('wpbm_admin', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Your session has expired. Reload the page and try again.', 'wp-bundle-migrator'), 'code' => 'bad_nonce'), 403);
        }
    }

    /**
     * Reads a POST field. Call only after guard() (or a token check).
     */
    private static function post(string $key, string $default = ''): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard() or by job token.
        return isset($_POST[$key]) && is_scalar($_POST[$key]) ? (string) wp_unslash($_POST[$key]) : $default; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by callers.
    }

    private static function flag(string $key, bool $default = false): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
        return isset($_POST[$key]) ? MigratorEngine::truthy(self::post($key)) : $default;
    }

    private static function fail(\Throwable $e): void {
        $status = $e instanceof \InvalidArgumentException ? 400 : 500;
        wp_send_json_error(array('message' => $e->getMessage()), $status);
    }

    /**
     * @return array<string, mixed>
     */
    private static function jobPayload(Job $job, bool $withToken): array {
        $data = $job->toArray();
        if ($withToken) {
            $data['token'] = $job->token;
        }
        return $data;
    }

    /* Jobs ---------------------------------------------------------------- */

    public static function jobStart(): void {
        self::guard();

        try {
            $type = sanitize_key(self::post('type'));
            switch ($type) {
                case 'export':
                    $options = array(
                        'export_type'                => sanitize_key(self::post('export_type', 'full')),
                        'multisite_mode'             => self::post('multisite_mode') === 'subsite' ? 'subsite' : 'entire_network',
                        'subsite_id'                 => absint(self::post('subsite_id', '0')),
                        'convert_subsite_standalone' => self::flag('convert_subsite_standalone'),
                        'exclude_spam'               => self::flag('exclude_spam'),
                        'exclude_revisions'          => self::flag('exclude_revisions'),
                        'exclude_transients'         => self::flag('exclude_transients'),
                        'encrypt'                    => self::flag('encrypt'),
                        'passphrase'                 => self::post('passphrase'),
                        'upload'                     => self::flag('upload'),
                        'delete_local'               => self::flag('delete_local'),
                        'push_key'                   => self::flag('push') ? self::post('push_key') : '',
                        'push_restore'               => self::flag('push_restore'),
                    );
                    foreach (MigratorEngine::COMPONENTS as $key) {
                        $options['include_' . $key] = self::flag('include_' . $key);
                    }
                    $job = MigratorEngine::startExport($options);
                    break;

                case 'restore':
                    $options = array(
                        'old_url'            => esc_url_raw(self::post('old_url')),
                        'new_url'            => esc_url_raw(self::post('new_url')),
                        'old_network_domain' => sanitize_text_field(self::post('old_network_domain')),
                        'new_network_domain' => sanitize_text_field(self::post('new_network_domain')),
                        'isolate_conflicts'  => self::flag('isolate_conflicts', true),
                        'passphrase'         => self::post('passphrase'),
                    );
                    foreach (MigratorEngine::COMPONENTS as $key) {
                        $options['restore_' . $key] = self::flag('restore_' . $key);
                    }
                    $job = MigratorEngine::startRestore(sanitize_file_name(self::post('filename')), $options);
                    break;

                case 'upload':
                    $job = MigratorEngine::startUpload(sanitize_file_name(self::post('filename')));
                    break;

                case 'download':
                    $job = MigratorEngine::startDownload(sanitize_file_name(self::post('filename')));
                    break;

                case 'pull':
                    $job = MigratorEngine::startPull(self::post('key'), array('export_type' => sanitize_key(self::post('export_type', 'full'))));
                    break;

                default:
                    throw new \InvalidArgumentException(__('Unknown job type.', 'wp-bundle-migrator'));
            }

            MigratorEngine::scheduleBackgroundTick($job);
            wp_send_json_success(self::jobPayload($job, true));
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    /**
     * Advances the active job. Authorized by the job token (no nonce, works without a session).
     */
    public static function jobTick(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Authorized by the job's secret token below.
        $id    = isset($_POST['job']) ? sanitize_text_field(wp_unslash((string) $_POST['job'])) : '';
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash((string) $_POST['token'])) : '';
        // phpcs:enable

        $job = Job::load($id);
        if ($job === null || !$job->checkToken($token)) {
            wp_send_json_error(array('message' => __('Unknown or expired job.', 'wp-bundle-migrator')), 403);
        }

        if ($job->status === 'running') {
            $job = MigratorEngine::tick($job, (float) Plugin::settings()['step_seconds']);
        }
        wp_send_json_success(self::jobPayload($job, false));
    }

    /**
     * The active job (with its token, so a reloaded page can keep driving it), or a finished job by ID.
     */
    public static function jobStatus(): void {
        self::guard();
        $id  = sanitize_text_field(self::post('job'));
        $job = $id !== '' ? Job::load($id) : Job::active();
        wp_send_json_success(array('job' => $job !== null ? self::jobPayload($job, $job->status === 'running') : null));
    }

    public static function jobCancel(): void {
        self::guard();
        $job = Job::active();
        if ($job !== null) {
            MigratorEngine::cancel($job);
        }
        wp_send_json_success(array('cancelled' => $job !== null));
    }

    public static function scheduleNow(): void {
        self::guard();
        try {
            if (!Plugin::settings()['schedule']['enabled']) {
                throw new \InvalidArgumentException(__('Turn on scheduled backups first.', 'wp-bundle-migrator'));
            }
            $schedule = Plugin::settings()['schedule'];
            $job      = MigratorEngine::startExport(
                array(
                    'export_type'        => (string) $schedule['preset'],
                    'encrypt'            => !empty($schedule['encrypt']),
                    'upload'             => !empty($schedule['upload']),
                    'delete_local'       => !empty($schedule['upload']) && !empty($schedule['delete_local']),
                    'exclude_spam'       => true,
                    'exclude_transients' => true,
                ),
                'schedule'
            );
            MigratorEngine::scheduleBackgroundTick($job);
            wp_send_json_success(self::jobPayload($job, true));
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    /* Bundles -------------------------------------------------------------- */

    public static function listBundles(): void {
        self::guard();

        try {
            $bundles = Storage::listBundles();
            $total   = 0.0;
            foreach ($bundles as $bundle) {
                $total += (float) $bundle['sizeBytes'];
            }
            wp_send_json_success(
                array(
                    'bundles'        => $bundles,
                    'totalSize'      => size_format($total, 1),
                    'currentSiteUrl' => untrailingslashit((string) get_option('siteurl')),
                    'cloudReady'     => Cloud::isConfigured(),
                )
            );
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    public static function inspect(): void {
        global $wpdb;
        self::guard();

        try {
            $name      = sanitize_file_name(self::post('filename'));
            $path      = Storage::path($name);
            $encrypted = Crypto::isEncryptedFile($path);
            $warnings  = array();
            $raw       = null;

            if ($encrypted) {
                $passphrase = self::post('passphrase');
                if ($passphrase === '') {
                    $passphrase = Plugin::storedPassphrase();
                }
                if ($passphrase === '') {
                    wp_send_json_success(array('filename' => $name, 'formattedSize' => size_format(Storage::filesize($path), 1), 'encrypted' => true, 'needsPassphrase' => true, 'manifest' => null, 'warnings' => array()));
                }
                try {
                    $raw = Crypto::readEncryptedManifest($path, $passphrase);
                } catch (\Throwable $e) {
                    wp_send_json_success(array('filename' => $name, 'formattedSize' => size_format(Storage::filesize($path), 1), 'encrypted' => true, 'needsPassphrase' => true, 'passphraseError' => $e->getMessage(), 'manifest' => null, 'warnings' => array()));
                }
            } else {
                $raw = Storage::readManifest($path);
            }

            $manifest = Storage::summarizeManifest($raw);

            if ($manifest === null) {
                $warnings[] = __('This file has no bundle.json manifest, so its contents and source site are unknown. It may not be a WP Bundle Migrator bundle.', 'wp-bundle-migrator');
            } else {
                if ($manifest['isMultisite'] && !is_multisite()) {
                    $warnings[] = __('This bundle was made from a multisite network, but this site is a single site. Only the main site will work after restoring.', 'wp-bundle-migrator');
                }
                if (!$manifest['isMultisite'] && is_multisite()) {
                    $warnings[] = __('This bundle is from a single site, but this is a multisite network. Restoring the database replaces the main site and the network\'s users table.', 'wp-bundle-migrator');
                }
                if ($manifest['multisiteMode'] === 'subsite' && !$manifest['standalone']) {
                    $warnings[] = __('This bundle holds one network site plus the whole network\'s users table. Restoring it overwrites all network users.', 'wp-bundle-migrator');
                }
                if ($manifest['tablePrefix'] !== '' && $manifest['tablePrefix'] !== $wpdb->base_prefix) {
                    $warnings[] = sprintf(
                        /* translators: 1: bundle table prefix, 2: this site's prefix. */
                        __('The bundle uses table prefix "%1$s"; it will be converted to this site\'s prefix "%2$s" automatically.', 'wp-bundle-migrator'),
                        $manifest['tablePrefix'],
                        $wpdb->base_prefix
                    );
                }
                if ($manifest['phpVersion'] !== '' && (int) $manifest['phpVersion'] < 8 && (int) PHP_VERSION >= 8) {
                    $warnings[] = sprintf(
                        /* translators: 1: source PHP version, 2: this server's PHP version. */
                        __('The source site ran PHP %1$s and this server runs PHP %2$s. Old plugins or themes may not work; the compatibility check will deactivate any that would crash the site.', 'wp-bundle-migrator'),
                        $manifest['phpVersion'],
                        PHP_VERSION
                    );
                }
                if ($manifest['wpVersion'] !== '' && version_compare($manifest['wpVersion'], get_bloginfo('version'), '>') && !in_array('wp_core', $manifest['components'], true)) {
                    $warnings[] = sprintf(
                        /* translators: 1: bundle WordPress version, 2: this site's WordPress version. */
                        __('The bundle is from WordPress %1$s, which is newer than this site (%2$s). Update WordPress first, or the database may not match.', 'wp-bundle-migrator'),
                        $manifest['wpVersion'],
                        get_bloginfo('version')
                    );
                }
            }

            wp_send_json_success(
                array(
                    'filename'      => $name,
                    'formattedSize' => size_format(Storage::filesize($path), 1),
                    'encrypted'     => $encrypted,
                    'manifest'      => $manifest,
                    'warnings'      => $warnings,
                )
            );
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    public static function delete(): void {
        self::guard();
        try {
            $name = sanitize_file_name(self::post('file'));
            if (!Storage::delete($name)) {
                throw new \RuntimeException(__('The file could not be deleted. Check the folder permissions.', 'wp-bundle-migrator'));
            }
            wp_send_json_success(array('deleted' => $name));
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    /**
     * Receives one piece of an upload and appends it (see the readme for the protocol).
     */
    public static function uploadChunk(): void {
        self::guard();

        try {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Verified in guard(); tmp_name is a server path.
            $tmp = isset($_FILES['chunk']['tmp_name']) ? (string) $_FILES['chunk']['tmp_name'] : '';
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new \InvalidArgumentException(__('No upload data was received. The piece may exceed the server upload limit.', 'wp-bundle-migrator'));
            }

            $result = Storage::receiveChunk(self::post('upload_id'), (float) self::post('offset', '0'), (float) self::post('total_size', '0'), self::post('filename'), $tmp);
            if (isset($result['expected'])) {
                wp_send_json_error(array('code' => 'offset_mismatch', 'expected' => $result['expected'], 'message' => __('Upload out of sync; resuming.', 'wp-bundle-migrator')), 409);
            }
            wp_send_json_success($result);
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    /* Settings ------------------------------------------------------------- */

    public static function saveSettings(): void {
        self::guard();

        try {
            $section = sanitize_key(self::post('section', 'general'));
            $input   = array();
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
            foreach (array_keys($_POST) as $key) {
                $input[(string) $key] = self::post((string) $key);
            }
            Plugin::saveSettings($input, $section);
            wp_send_json_success(
                array(
                    'settings'   => Plugin::publicSettings(),
                    'nextRun'    => Scheduler::nextRun(),
                    'cloudReady' => Cloud::isConfigured(),
                    'message'    => __('Settings saved.', 'wp-bundle-migrator'),
                )
            );
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    public static function cloudTest(): void {
        self::guard();

        try {
            $saved  = Cloud::config();
            // Secret fields left empty mean "keep the saved value".
            $secret = static function (string $field) use ($saved): string {
                $value = self::post($field);
                return $value !== '' ? $value : (string) ($saved[$field] ?? '');
            };
            $host   = trim(sanitize_text_field(self::post('sftp_host')));
            $port   = max(1, min(65535, absint(self::post('sftp_port', '22'))));
            $config = array(
                'provider'         => sanitize_key(self::post('provider')),
                'access_key'       => sanitize_text_field(self::post('access_key')),
                'secret_key'       => $secret('secret_key'),
                'bucket'           => sanitize_text_field(self::post('bucket')),
                'region'           => sanitize_text_field(self::post('region')),
                'endpoint'         => esc_url_raw(self::post('endpoint')),
                'account_id'       => sanitize_text_field(self::post('account_id')),
                'path_style'       => self::flag('path_style', true),
                'path'             => sanitize_text_field(self::post('path')),
                'sftp_host'        => $host,
                'sftp_port'        => $port,
                'sftp_user'        => sanitize_text_field(self::post('sftp_user')),
                'sftp_auth'        => self::post('sftp_auth') === 'key' ? 'key' : 'password',
                'sftp_password'    => $secret('sftp_password'),
                'sftp_key'         => $secret('sftp_key'),
                'sftp_key_pass'    => $secret('sftp_key_pass'),
                // A changed host must be verified from scratch.
                'sftp_fingerprint' => ($host === $saved['sftp_host'] && $port === (int) $saved['sftp_port'] && !self::flag('sftp_forget_key')) ? (string) $saved['sftp_fingerprint'] : '',
                'dropbox_app_key'  => sanitize_text_field(self::post('dropbox_app_key')),
                'dropbox_refresh'  => (string) $saved['dropbox_refresh'],
                'dropbox_account'  => (string) $saved['dropbox_account'],
            );
            wp_send_json_success(Cloud::test($config));
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    public static function cloudList(): void {
        self::guard();
        try {
            if (!Cloud::isConfigured()) {
                wp_send_json_success(array('configured' => false, 'items' => array()));
            }
            $items = array();
            foreach (Cloud::listBundles() as $object) {
                $items[] = array(
                    'filename'      => $object['filename'],
                    'sizeBytes'     => $object['size'],
                    'formattedSize' => size_format($object['size'], 1),
                    'modified'      => $object['modified'],
                    'modifiedDate'  => wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $object['modified']),
                );
            }
            wp_send_json_success(
                array(
                    'configured' => true,
                    'items'      => $items,
                    'label'      => Cloud::describe(),
                )
            );
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    public static function cloudDelete(): void {
        self::guard();
        try {
            Cloud::delete(sanitize_file_name(self::post('file')));
            wp_send_json_success(array('deleted' => true));
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    /* Site-to-site transfer ------------------------------------------------ */

    public static function peerKey(): void {
        self::guard();
        $key = Peer::generateKey(absint(self::post('hours', '24')));
        wp_send_json_success(array('key' => $key, 'expires' => (int) Plugin::settings()['peer']['expires']));
    }

    public static function peerRevoke(): void {
        self::guard();
        Peer::revoke();
        wp_send_json_success(array('revoked' => true));
    }

    public static function peerCheck(): void {
        self::guard();
        try {
            $info = Peer::ping(Peer::parseKey(self::post('key')));
            wp_send_json_success(
                array(
                    'site'    => (string) ($info['site'] ?? ''),
                    'name'    => (string) ($info['name'] ?? ''),
                    'version' => (string) ($info['version'] ?? ''),
                    'wp'      => (string) ($info['wp'] ?? ''),
                    'php'     => (string) ($info['php'] ?? ''),
                    'busy'    => !empty($info['busy']),
                )
            );
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    /* Dropbox -------------------------------------------------------------- */

    /**
     * Starts the Dropbox connection: remembers a PKCE verifier and returns the approval URL.
     */
    public static function dropboxStart(): void {
        self::guard();
        $appKey = sanitize_text_field(self::post('app_key'));
        if ($appKey === '') {
            wp_send_json_error(array('message' => __('Enter your Dropbox app key first.', 'wp-bundle-migrator')), 400);
        }
        $verifier = DropboxDestination::newVerifier();
        set_transient('wpbm_dropbox_pkce_' . get_current_user_id(), Crypto::seal($verifier . '|' . $appKey), 30 * MINUTE_IN_SECONDS);
        wp_send_json_success(array('url' => DropboxDestination::authorizeUrl($appKey, $verifier)));
    }

    /**
     * Finishes the Dropbox connection with the code the user pasted.
     */
    public static function dropboxFinish(): void {
        self::guard();
        try {
            $stored = Crypto::open((string) get_transient('wpbm_dropbox_pkce_' . get_current_user_id()));
            if ($stored === '' || strpos($stored, '|') === false) {
                throw new \InvalidArgumentException(__('The connection request expired. Click "Connect Dropbox" again.', 'wp-bundle-migrator'));
            }
            list($verifier, $appKey) = explode('|', $stored, 2);
            $result = DropboxDestination::exchangeCode($appKey, sanitize_text_field(self::post('code')), $verifier);
            delete_transient('wpbm_dropbox_pkce_' . get_current_user_id());

            $s                             = Plugin::settings();
            $s['cloud']['provider']        = 'dropbox';
            $s['cloud']['dropbox_app_key'] = $appKey;
            Plugin::replaceSettings($s);
            Plugin::saveDropboxConnection($result['refresh'], $result['account']);

            wp_send_json_success(
                array(
                    'account' => $result['account'],
                    /* translators: %s: Dropbox account name. */
                    'message' => sprintf(__('Dropbox connected: %s', 'wp-bundle-migrator'), $result['account'] !== '' ? $result['account'] : __('account linked', 'wp-bundle-migrator')),
                )
            );
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    /* Misc ----------------------------------------------------------------- */

    public static function estimate(): void {
        self::guard();
        wp_send_json_success(array('sizes' => SystemCheck::estimateSizes()));
    }

    public static function lastReport(): void {
        self::guard();
        $report = get_site_option(Plugin::OPTION_REPORT);
        wp_send_json_success(array('report' => is_array($report) ? $report : null));
    }

    public static function probe(): void {
        self::guard();
        wp_send_json_success(array('row' => SystemCheck::webAccessProbe(self::flag('force'))));
    }

    /**
     * Streams a bundle. Uses its own nonce because the link is opened by the browser.
     */
    public static function download(): void {
        if (!Plugin::currentUserCan()) {
            wp_die(esc_html__('You do not have permission to download bundles.', 'wp-bundle-migrator'), '', array('response' => 403));
        }
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'wpbm_download')) {
            wp_die(esc_html__('This download link has expired. Reload the Backups page and try again.', 'wp-bundle-migrator'), '', array('response' => 403));
        }

        $name = isset($_GET['file']) && is_scalar($_GET['file']) ? sanitize_file_name(wp_unslash((string) $_GET['file'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        try {
            $path = Storage::path($name);
        } catch (\Throwable $e) {
            wp_die(esc_html($e->getMessage()), '', array('response' => 404));
        }

        Storage::streamFile($path);
    }
}
