<?php
/**
 * Dropbox destination using the user's own Dropbox app (App key + OAuth 2 PKCE).
 *
 * No app secret is needed or stored: the user authorizes the app in Dropbox, pastes the
 * one-time code back, and the plugin keeps only an (encrypted) refresh token.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bundles in a Dropbox folder.
 */
final class DropboxDestination implements Destination {
    private const CHUNK          = 8388608;
    private const API            = 'https://api.dropboxapi.com/2/';
    private const CONTENT        = 'https://content.dropboxapi.com/2/';
    private const TOKEN_URL      = 'https://api.dropboxapi.com/oauth2/token';
    private const AUTHORIZE_URL  = 'https://www.dropbox.com/oauth2/authorize';
    private const TOKEN_TRANSIENT = 'slice_backup_dropbox_access';

    /** @var string */
    private $appKey;
    /** @var string */
    private $refreshToken;
    /** @var string */
    private $dir;

    /**
     * @param array<string, mixed> $config Cloud settings with decrypted secrets.
     * @throws \InvalidArgumentException When not connected yet.
     */
    public function __construct(array $config) {
        $this->appKey       = trim((string) $config['dropbox_app_key']);
        $this->refreshToken = (string) $config['dropbox_refresh'];
        if ($this->appKey === '') {
            throw new \InvalidArgumentException(esc_html__('Enter your Dropbox app key.', 'slice-backup'));
        }
        if ($this->refreshToken === '') {
            throw new \InvalidArgumentException(esc_html__('Connect your Dropbox account first.', 'slice-backup'));
        }
        $path      = trim(str_replace('\\', '/', (string) $config['path']), '/');
        $this->dir = $path === '' ? '' : '/' . $path;
    }

    /* OAuth -------------------------------------------------------------- */

    /**
     * URL where the user approves the app. The verifier must be kept for exchangeCode().
     */
    public static function authorizeUrl(string $appKey, string $verifier): string {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        return add_query_arg(
            array(
                'client_id'             => rawurlencode($appKey),
                'response_type'         => 'code',
                'token_access_type'     => 'offline',
                'code_challenge'        => $challenge,
                'code_challenge_method' => 'S256',
            ),
            self::AUTHORIZE_URL
        );
    }

    public static function newVerifier(): string {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '='); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /**
     * Exchanges the code shown by Dropbox for a refresh token.
     *
     * @return array{refresh: string, account: string}
     * @throws \RuntimeException When Dropbox rejects the code.
     */
    public static function exchangeCode(string $appKey, string $code, string $verifier): array {
        $data = self::tokenRequest(
            array(
                'grant_type'    => 'authorization_code',
                'code'          => trim($code),
                'client_id'     => $appKey,
                'code_verifier' => $verifier,
            )
        );
        if (empty($data['refresh_token'])) {
            throw new \RuntimeException(esc_html__('Dropbox did not return a refresh token. Make sure the app uses "Scoped access".', 'slice-backup'));
        }
        if (!empty($data['access_token'])) {
            set_site_transient(self::TOKEN_TRANSIENT, Crypto::seal((string) $data['access_token']), max(60, (int) ($data['expires_in'] ?? 14400) - 300));
        }

        $account = '';
        try {
            $info    = self::rpc((string) $data['access_token'], 'users/get_current_account', null);
            $account = trim((string) ($info['name']['display_name'] ?? '') . ' (' . (string) ($info['email'] ?? '') . ')');
        } catch (\Throwable $e) {
            $account = '';
        }
        return array('refresh' => (string) $data['refresh_token'], 'account' => $account);
    }

    /**
     * @param array<string, string> $body
     * @return array<string, mixed>
     * @throws \RuntimeException On errors.
     */
    private static function tokenRequest(array $body): array {
        $response = wp_remote_post(self::TOKEN_URL, array('timeout' => 30, 'body' => $body));
        if (is_wp_error($response)) {
            throw new \RuntimeException(esc_html(sprintf(__('Could not reach Dropbox: %s', 'slice-backup'), $response->get_error_message()))); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ((int) wp_remote_retrieve_response_code($response) !== 200 || !is_array($data)) {
            $message = is_array($data) ? (string) ($data['error_description'] ?? $data['error'] ?? '') : '';
            throw new \RuntimeException(
                esc_html(/* translators: %s: Dropbox error. */
                sprintf(__('Dropbox rejected the authorization: %s. Start the connection again and paste the new code.', 'slice-backup'), $message !== '' ? $message : 'HTTP ' . wp_remote_retrieve_response_code($response)))
            );
        }
        return $data;
    }

    /**
     * A valid short-lived access token (cached, refreshed when it expires).
     *
     * @throws \RuntimeException When the refresh token was revoked.
     */
    private function accessToken(bool $force = false): string {
        $cached = $force ? false : get_site_transient(self::TOKEN_TRANSIENT);
        if (is_string($cached) && ($token = Crypto::open($cached)) !== '') {
            return $token;
        }
        try {
            $data = self::tokenRequest(array('grant_type' => 'refresh_token', 'refresh_token' => $this->refreshToken, 'client_id' => $this->appKey));
        } catch (\Throwable $e) {
            throw new \RuntimeException(esc_html__('Dropbox access was revoked or expired. Reconnect Dropbox under Settings > Cloud storage.', 'slice-backup'));
        }
        $token = (string) $data['access_token'];
        set_site_transient(self::TOKEN_TRANSIENT, Crypto::seal($token), max(60, (int) ($data['expires_in'] ?? 14400) - 300));
        return $token;
    }

    /* HTTP --------------------------------------------------------------- */

    /**
     * RPC endpoint (JSON in, JSON out).
     *
     * @param array<string, mixed>|null $args
     * @return array<string, mixed>
     * @throws DropboxError On API errors.
     */
    private static function rpc(string $token, string $endpoint, ?array $args): array {
        $response = wp_remote_post(
            self::API . $endpoint,
            array(
                'timeout' => 60,
                'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'),
                'body'    => $args === null ? 'null' : (string) wp_json_encode($args),
            )
        );
        return self::decode($response);
    }

    /**
     * Content endpoint (arguments in the Dropbox-API-Arg header, raw body).
     *
     * @param array<string, mixed>  $arg
     * @param array<string, string> $headers
     * @return array{json: array<string, mixed>, body: string}
     * @throws DropboxError On API errors.
     */
    private static function content(string $token, string $endpoint, array $arg, string $body, array $headers = array(), bool $rawResult = false): array {
        $base = array(
            'Authorization'   => 'Bearer ' . $token,
            'Dropbox-API-Arg' => (string) wp_json_encode($arg),
        );
        if (!$rawResult) {
            // Downloads must not send a Content-Type header.
            $base['Content-Type'] = 'application/octet-stream';
        }
        $response = wp_remote_post(
            self::CONTENT . $endpoint,
            array(
                'timeout' => 300,
                'headers' => array_merge($base, $headers),
                'body'    => $body,
            )
        );
        if ($rawResult) {
            if (is_wp_error($response)) {
                self::decode($response);
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code !== 200 && $code !== 206) {
                self::decode($response);
            }
            return array('json' => array(), 'body' => (string) wp_remote_retrieve_body($response));
        }
        return array('json' => self::decode($response), 'body' => '');
    }

    /**
     * @param array<string, mixed>|\WP_Error $response
     * @return array<string, mixed>
     * @throws DropboxError On errors.
     */
    private static function decode($response): array {
        if (is_wp_error($response)) {
            $error = DropboxError::make(
                /* translators: %s: error message. */
                sprintf(__('Could not reach Dropbox: %s', 'slice-backup'), $response->get_error_message()),
                0,
                ''
            );
            throw $error;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if ($code === 200) {
            return is_array($data) ? $data : array();
        }
        $summary = is_array($data) ? (string) ($data['error_summary'] ?? '') : trim(wp_strip_all_tags($body));
        // DropboxError::make() escapes the message.
        $error = DropboxError::make(
            /* translators: 1: HTTP status, 2: Dropbox error summary. */
            sprintf(__('Dropbox error (HTTP %1$d): %2$s', 'slice-backup'), $code, $summary !== '' ? $summary : __('unknown error', 'slice-backup')),
            $code,
            $summary,
            is_array($data) ? $data : array()
        );
        throw $error;
    }

    /**
     * Calls the API, refreshing the access token once if it expired.
     *
     * @template T
     * @param callable(string): T $call
     * @return T
     */
    private function call(callable $call) {
        try {
            return $call($this->accessToken());
        } catch (DropboxError $e) {
            if ($e->status === 401) {
                return $call($this->accessToken(true));
            }
            throw $e;
        }
    }

    private function path(string $name): string {
        return $this->dir . '/' . $name;
    }

    /* Destination -------------------------------------------------------- */

    public function test(): string {
        $account = $this->call(
            static function (string $t): array {
                return self::rpc($t, 'users/get_current_account', null);
            }
        );
        $path = $this->path('.slicebk-connection-test-' . strtolower(wp_generate_password(8, false)));
        $this->call(
            static function (string $t) use ($path): array {
                return self::content($t, 'files/upload', array('path' => $path, 'mode' => 'overwrite', 'mute' => true), 'Slice Backup connection test');
            }
        );
        $this->call(
            static function (string $t) use ($path): array {
                return self::rpc($t, 'files/delete_v2', array('path' => $path));
            }
        );
        return sprintf(
            /* translators: 1: account name, 2: folder. */
            __('Connected to the Dropbox account of %1$s. Writing and deleting files in %2$s works.', 'slice-backup'),
            (string) ($account['name']['display_name'] ?? '?'),
            'Apps/…' . ($this->dir !== '' ? $this->dir : '/')
        );
    }

    public function listBundles(): array {
        $items = array();
        try {
            $page = $this->call(
                function (string $t): array {
                    return self::rpc($t, 'files/list_folder', array('path' => $this->dir, 'limit' => 2000));
                }
            );
        } catch (DropboxError $e) {
            if (strpos($e->summary, 'not_found') !== false) {
                return array();
            }
            throw $e;
        }

        for ($i = 0; $i < 50; $i++) {
            foreach ((array) ($page['entries'] ?? array()) as $entry) {
                if (($entry['.tag'] ?? '') === 'file' && Storage::isBundleName((string) $entry['name'])) {
                    $items[] = array(
                        'filename' => (string) $entry['name'],
                        'size'     => (float) ($entry['size'] ?? 0),
                        'modified' => (int) strtotime((string) ($entry['server_modified'] ?? '')),
                    );
                }
            }
            if (empty($page['has_more'])) {
                break;
            }
            $cursor = (string) $page['cursor'];
            $page   = $this->call(
                static function (string $t) use ($cursor): array {
                    return self::rpc($t, 'files/list_folder/continue', array('cursor' => $cursor));
                }
            );
        }
        return $items;
    }

    public function delete(string $filename): void {
        $path = $this->path($filename);
        try {
            $this->call(
                static function (string $t) use ($path): array {
                    return self::rpc($t, 'files/delete_v2', array('path' => $path));
                }
            );
        } catch (DropboxError $e) {
            if (strpos($e->summary, 'not_found') === false) {
                throw $e;
            }
        }
    }

    public function uploadStep(string $path, string $filename, array &$state, float $deadline): bool {
        $size   = Storage::filesize($path);
        $target = $this->path($filename);
        $commit = array('path' => $target, 'mode' => 'overwrite', 'autorename' => false, 'mute' => true);

        if ($size <= self::CHUNK) {
            $body = (string) file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $this->call(
                static function (string $t) use ($commit, $body): array {
                    return self::content($t, 'files/upload', $commit, $body);
                }
            );
            $state['offset'] = $size;
            return true;
        }

        $handle = fopen($path, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$handle) {
            throw new \RuntimeException(esc_html__('Could not read the bundle for uploading.', 'slice-backup'));
        }

        try {
            if (empty($state['session'])) {
                $data   = (string) fread($handle, self::CHUNK); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                $result = $this->call(
                    static function (string $t) use ($data): array {
                        return self::content($t, 'files/upload_session/start', array('close' => false), $data);
                    }
                );
                $state = array('session' => (string) $result['json']['session_id'], 'offset' => (float) strlen($data));
            }

            $first = true;
            while ((float) $state['offset'] < $size && ($first || microtime(true) < $deadline)) {
                $first  = false;
                $offset = (float) $state['offset'];
                Crypto::seek($handle, $offset);
                $data   = (string) fread($handle, self::CHUNK); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                $cursor = array('session_id' => (string) $state['session'], 'offset' => (int) $offset);
                $last   = $offset + strlen($data) >= $size;

                try {
                    if ($last) {
                        $this->call(
                            static function (string $t) use ($cursor, $commit, $data): array {
                                return self::content($t, 'files/upload_session/finish', array('cursor' => $cursor, 'commit' => $commit), $data);
                            }
                        );
                    } else {
                        $this->call(
                            static function (string $t) use ($cursor, $data): array {
                                return self::content($t, 'files/upload_session/append_v2', array('cursor' => $cursor, 'close' => false), $data);
                            }
                        );
                    }
                } catch (DropboxError $e) {
                    // Resume from the offset Dropbox actually has (an earlier request may have succeeded).
                    $correct = $e->data['error']['correct_offset'] ?? ($e->data['error']['lookup_failed']['correct_offset'] ?? null);
                    if (is_numeric($correct)) {
                        $state['offset'] = (float) $correct;
                        continue;
                    }
                    throw $e;
                }
                $state['offset'] = $offset + strlen($data);
            }
        } finally {
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }

        return (float) $state['offset'] >= $size;
    }

    public function abortUpload(array $state): void {
        // Unfinished upload sessions expire on their own after 7 days.
    }

    public function downloadStep(string $filename, string $target, array &$state, float $deadline): bool {
        $path = $this->path($filename);
        if (!isset($state['size'])) {
            try {
                $meta = $this->call(
                    static function (string $t) use ($path): array {
                        return self::rpc($t, 'files/get_metadata', array('path' => $path));
                    }
                );
            } catch (DropboxError $e) {
                /* translators: %s: file name. */
                throw new \RuntimeException(esc_html(sprintf(__('%s was not found in Dropbox.', 'slice-backup'), $filename)));
            }
            file_put_contents($target, ''); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            $state = array('size' => (float) ($meta['size'] ?? 0), 'offset' => 0.0);
        }

        $first = true;
        while ((float) $state['offset'] < (float) $state['size'] && ($first || microtime(true) < $deadline)) {
            $first = false;
            $start = (float) $state['offset'];
            $end   = min((float) $state['size'], $start + self::CHUNK) - 1;
            $range = sprintf('bytes=%.0f-%.0f', $start, $end);
            $data  = $this->call(
                static function (string $t) use ($path, $range): array {
                    return self::content($t, 'files/download', array('path' => $path), '', array('Range' => $range), true);
                }
            );
            if ($data['body'] === '') {
                throw new \RuntimeException(esc_html__('Dropbox returned an empty response.', 'slice-backup'));
            }
            Cloud::appendChunk($target, $data['body']);
            $state['offset'] = $start + strlen($data['body']);
        }
        return (float) $state['offset'] >= (float) $state['size'];
    }
}
