<?php
/**
 * REST API for headless automation (authenticate with Application Passwords).
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Routes under /wp-json/slice-backup/v1/.
 */
final class RestController {
    public const NAMESPACE_V1 = 'slice-backup/v1';

    public static function init(): void {
        add_action('rest_api_init', array(__CLASS__, 'registerRoutes'));
        add_filter('rest_pre_serve_request', array(__CLASS__, 'serveRaw'), 10, 3);
    }

    public static function registerRoutes(): void {
        $permission = array(__CLASS__, 'checkPermission');

        register_rest_route(
            self::NAMESPACE_V1,
            '/export',
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => array(__CLASS__, 'export'),
                'permission_callback' => $permission,
            )
        );
        register_rest_route(
            self::NAMESPACE_V1,
            '/restore',
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => array(__CLASS__, 'restore'),
                'permission_callback' => $permission,
                'args'                => array(
                    'filename' => array(
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_file_name',
                    ),
                ),
            )
        );
        register_rest_route(
            self::NAMESPACE_V1,
            '/backups',
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array(__CLASS__, 'listBundles'),
                'permission_callback' => $permission,
            )
        );
        register_rest_route(
            self::NAMESPACE_V1,
            '/delete_backup',
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => array(__CLASS__, 'delete'),
                'permission_callback' => $permission,
                'args'                => array(
                    'file' => array(
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_file_name',
                    ),
                ),
            )
        );
        register_rest_route(
            self::NAMESPACE_V1,
            '/settings',
            array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => array(__CLASS__, 'getSettings'),
                    'permission_callback' => $permission,
                ),
                array(
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => array(__CLASS__, 'saveSettings'),
                    'permission_callback' => $permission,
                ),
            )
        );
        // Site-to-site transfer routes (HMAC-signed with the transfer key).
        $signed = array(Peer::class, 'verify');
        foreach (array('ping', 'upload', 'restore', 'export', 'download') as $route) {
            register_rest_route(
                self::NAMESPACE_V1,
                '/peer/' . $route,
                array(
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => array(__CLASS__, 'peer' . ucfirst($route)),
                    'permission_callback' => $signed,
                )
            );
        }
        // Job progress is authorized by the job's own token: a restore replaces the
        // database (and with it the transfer key), but the sender must keep following it.
        register_rest_route(
            self::NAMESPACE_V1,
            '/peer/job',
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => array(__CLASS__, 'peerJob'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE_V1,
            '/status',
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array(__CLASS__, 'status'),
                'permission_callback' => $permission,
            )
        );
    }

    /**
     * Cookie-authenticated requests are nonce-checked by WordPress core; other auth
     * methods (Application Passwords) are handled by core as well.
     *
     * @return true|\WP_Error
     */
    public static function checkPermission() {
        if (Plugin::currentUserCan()) {
            return true;
        }
        return new \WP_Error('rest_forbidden', __('You do not have permission to do this.', 'slice-backup'), array('status' => rest_authorization_required_code()));
    }

    /**
     * @return array<string, mixed>
     */
    private static function params(\WP_REST_Request $request): array {
        $params = $request->get_json_params();
        if (!is_array($params) || empty($params)) {
            $params = $request->get_params();
        }
        return is_array($params) ? $params : array();
    }

    private static function error(\Throwable $e): \WP_Error {
        return new \WP_Error('slice_backup_error', Plugin::errorText($e), array('status' => $e instanceof \InvalidArgumentException ? 400 : 500));
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public static function export(\WP_REST_Request $request) {
        try {
            $result                = (new MigratorEngine())->executeExport(self::params($request));
            $result['downloadUrl'] = Storage::downloadUrl($result['bundleFile']);
            return rest_ensure_response($result);
        } catch (\Throwable $e) {
            return self::error($e);
        }
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public static function restore(\WP_REST_Request $request) {
        try {
            $params = self::params($request);
            return rest_ensure_response((new MigratorEngine())->executeRestore((string) $request->get_param('filename'), $params));
        } catch (\Throwable $e) {
            return self::error($e);
        }
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public static function listBundles() {
        try {
            return rest_ensure_response(array('success' => true, 'backups' => Storage::listBundles()));
        } catch (\Throwable $e) {
            return self::error($e);
        }
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public static function delete(\WP_REST_Request $request) {
        try {
            $deleted = Storage::delete((string) $request->get_param('file'));
            return rest_ensure_response(array('success' => $deleted));
        } catch (\Throwable $e) {
            return self::error($e);
        }
    }

    /**
     * @return \WP_REST_Response
     */
    public static function getSettings() {
        return rest_ensure_response(Plugin::settings());
    }

    /**
     * @return \WP_REST_Response
     */
    public static function saveSettings(\WP_REST_Request $request) {
        return rest_ensure_response(array('success' => true, 'settings' => Plugin::saveSettings(self::params($request))));
    }

    /* Site-to-site transfer ------------------------------------------------ */

    /**
     * @return \WP_REST_Response
     */
    public static function peerPing() {
        global $wpdb;
        return rest_ensure_response(
            array(
                'site'        => (string) get_option('siteurl'),
                'name'        => (string) get_bloginfo('name'),
                'version'     => SLICE_BACKUP_VERSION,
                'wp'          => get_bloginfo('version'),
                'php'         => PHP_VERSION,
                'multisite'   => is_multisite(),
                'prefix'      => (string) $wpdb->base_prefix,
                'chunkSize'   => min(Peer::CHUNK, Plugin::uploadChunkSize()),
                'busy'        => Job::active() !== null,
                'keyExpires'  => (int) Plugin::settings()['peer']['expires'],
            )
        );
    }

    /**
     * Receives one piece of a pushed bundle (raw body).
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public static function peerUpload(\WP_REST_Request $request) {
        try {
            $result = Storage::receiveChunk(
                (string) $request->get_param('upload_id'),
                (float) $request->get_param('offset'),
                (float) $request->get_param('total'),
                (string) $request->get_param('filename'),
                null,
                (string) $request->get_body()
            );
            if (isset($result['expected'])) {
                return new \WP_Error('slice_backup_offset', 'offset_mismatch', array('status' => 409, 'expected' => $result['expected']));
            }
            return rest_ensure_response($result);
        } catch (\Throwable $e) {
            return self::error($e);
        }
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public static function peerRestore(\WP_REST_Request $request) {
        try {
            $params  = (array) $request->get_json_params();
            $options = array(
                'isolate_conflicts' => !empty($params['isolate_conflicts']),
                'passphrase'        => (string) ($params['passphrase'] ?? ''),
            );
            foreach (MigratorEngine::COMPONENTS as $key) {
                $options['restore_' . $key] = !empty($params['restore_' . $key]);
            }
            $job = MigratorEngine::startRestore(sanitize_file_name((string) ($params['filename'] ?? '')), $options, 'peer');
            MigratorEngine::scheduleBackgroundTick($job);
            return rest_ensure_response(array('id' => $job->id, 'token' => $job->token));
        } catch (\Throwable $e) {
            return self::error($e);
        }
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public static function peerExport(\WP_REST_Request $request) {
        try {
            $params = (array) $request->get_json_params();
            $job    = MigratorEngine::startExport(
                array(
                    'export_type'        => sanitize_key((string) ($params['export_type'] ?? 'full')),
                    'exclude_spam'       => true,
                    'exclude_transients' => true,
                ),
                'peer'
            );
            MigratorEngine::scheduleBackgroundTick($job);
            return rest_ensure_response(array('id' => $job->id, 'token' => $job->token));
        } catch (\Throwable $e) {
            return self::error($e);
        }
    }

    /**
     * Advances and reports a job started by the other site.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public static function peerJob(\WP_REST_Request $request) {
        $params = (array) $request->get_json_params();
        $job    = Job::load(sanitize_text_field((string) ($params['id'] ?? '')));
        if ($job === null || !$job->checkToken((string) ($params['token'] ?? ''))) {
            return new \WP_Error('slice_backup_peer_denied', __('Unknown or expired job.', 'slice-backup'), array('status' => 403));
        }
        if ($job->status === 'running') {
            $job = MigratorEngine::tick($job, min(25.0, (float) Plugin::settings()['step_seconds']));
        }
        return rest_ensure_response($job->toArray());
    }

    /**
     * Sends a byte range of a bundle (raw body) so the other site can pull it.
     *
     * @return \WP_Error|void
     */
    public static function peerDownload(\WP_REST_Request $request) {
        try {
            $data = Storage::readRange(sanitize_file_name((string) $request->get_param('filename')), (float) $request->get_param('offset'), (int) $request->get_param('length'));
        } catch (\Throwable $e) {
            return self::error($e);
        }
        $response = new \WP_REST_Response($data, 200);
        $response->header('Content-Type', 'application/octet-stream');
        $response->header('X-Slice-Backup-Raw', '1');
        return $response;
    }

    /**
     * Sends peer/download responses as raw bytes instead of JSON.
     *
     * @param bool              $served
     * @param \WP_HTTP_Response $result
     */
    public static function serveRaw($served, $result, \WP_REST_Request $request): bool {
        if ($served || !($result instanceof \WP_REST_Response) || $request->get_route() !== '/' . self::NAMESPACE_V1 . '/peer/download') {
            return (bool) $served;
        }
        $headers = $result->get_headers();
        if (empty($headers['X-Slice-Backup-Raw']) || !is_string($result->get_data())) {
            return (bool) $served;
        }
        header('Content-Length: ' . strlen($result->get_data()));
        echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary bundle data.
        return true;
    }

    /**
     * @return \WP_REST_Response
     */
    public static function status() {
        return rest_ensure_response(
            array(
                'status'       => 'ready',
                'version'      => SLICE_BACKUP_VERSION,
                'php_version'  => PHP_VERSION,
                'is_multisite' => is_multisite(),
                'checks'       => SystemCheck::run(),
            )
        );
    }
}
