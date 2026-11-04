<?php
/**
 * Site-to-site transfer: push a bundle to another WordPress site, or pull one from it.
 *
 * The receiving site generates a transfer key (URL + key ID + 256-bit secret) that expires.
 * Every request is signed with HMAC-SHA256 over the method, route, timestamp, a random nonce
 * and the body hash; requests older than 5 minutes or with a reused nonce are rejected.
 *
 * @package WpBundleMigrator
 */

declare(strict_types=1);

namespace WpBundleMigrator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Transfer keys, request signing and the HTTP client for site-to-site transfers.
 */
final class Peer {
    public const PREFIX      = 'wpbm1.';
    private const MAX_SKEW   = 300;
    public const CHUNK       = 8388608;

    /* Keys (receiving side) ------------------------------------------------ */

    /**
     * Creates a new transfer key for this site, replacing any previous one.
     */
    public static function generateKey(int $hours): string {
        $hours  = max(1, min(168, $hours));
        $id     = strtolower(wp_generate_password(12, false));
        $secret = self::b64url(random_bytes(32));

        $s         = Plugin::settings();
        $s['peer'] = array(
            'receive_enabled' => true,
            'key_id'          => $id,
            'secret_sealed'   => Crypto::seal($secret),
            'expires'         => time() + $hours * HOUR_IN_SECONDS,
        );
        Plugin::replaceSettings($s);

        return self::PREFIX . self::b64url(
            (string) wp_json_encode(
                array(
                    'u' => rest_url(RestController::NAMESPACE_V1 . '/'),
                    'k' => $id,
                    's' => $secret,
                )
            )
        );
    }

    public static function revoke(): void {
        $s         = Plugin::settings();
        $s['peer'] = array('receive_enabled' => false, 'key_id' => '', 'secret_sealed' => '', 'expires' => 0);
        Plugin::replaceSettings($s);
    }

    /**
     * @return array{url: string, id: string, secret: string}
     * @throws \InvalidArgumentException When the key is malformed.
     */
    public static function parseKey(string $key): array {
        $key = trim(preg_replace('/\s+/', '', $key) ?? '');
        if (strpos($key, self::PREFIX) !== 0) {
            throw new \InvalidArgumentException(__('This is not a transfer key. Generate one on the other site under Settings > Site-to-site transfer.', 'wp-bundle-migrator'));
        }
        $json = base64_decode(strtr(substr($key, strlen(self::PREFIX)), '-_', '+/'), true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || empty($data['u']) || empty($data['k']) || empty($data['s']) || !wp_http_validate_url((string) $data['u']) && !preg_match('#^https?://#', (string) $data['u'])) {
            throw new \InvalidArgumentException(__('The transfer key is damaged. Copy it again.', 'wp-bundle-migrator'));
        }
        return array('url' => trailingslashit((string) $data['u']), 'id' => (string) $data['k'], 'secret' => (string) $data['s']);
    }

    /* Verification (receiving side) --------------------------------------- */

    /**
     * REST permission callback for signed peer routes.
     *
     * @return true|\WP_Error
     */
    public static function verify(\WP_REST_Request $request) {
        $deny = static function (string $message) {
            return new \WP_Error('wpbm_peer_denied', $message, array('status' => 403));
        };

        $peer = Plugin::settings()['peer'];
        if (empty($peer['receive_enabled']) || (string) $peer['key_id'] === '') {
            return $deny(__('Site-to-site transfers are not enabled on this site.', 'wp-bundle-migrator'));
        }
        if ((int) $peer['expires'] < time()) {
            return $deny(__('The transfer key has expired. Generate a new one on this site.', 'wp-bundle-migrator'));
        }

        $id        = (string) $request->get_header('x_wpbm_key');
        $timestamp = (int) $request->get_header('x_wpbm_time');
        $nonce     = (string) $request->get_header('x_wpbm_nonce');
        $signature = (string) $request->get_header('x_wpbm_signature');

        if (!hash_equals((string) $peer['key_id'], $id)) {
            return $deny(__('Unknown transfer key.', 'wp-bundle-migrator'));
        }
        if (abs(time() - $timestamp) > self::MAX_SKEW) {
            return $deny(__('The request is too old or the two servers\' clocks differ by more than 5 minutes.', 'wp-bundle-migrator'));
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce)) {
            return $deny(__('Invalid request.', 'wp-bundle-migrator'));
        }

        $secret   = Crypto::open((string) $peer['secret_sealed']);
        $expected = self::signature($secret, $request->get_method(), self::routeName($request->get_route()), $timestamp, $nonce, (string) $request->get_body(), self::canonicalQuery($request->get_query_params()));
        if ($secret === '' || !hash_equals($expected, $signature)) {
            return $deny(__('Invalid transfer signature. Check that the key was copied completely.', 'wp-bundle-migrator'));
        }

        // Single use: a captured request cannot be replayed.
        $seen = 'wpbm_peer_n_' . md5($nonce);
        if (get_site_transient($seen)) {
            return $deny(__('This request was already used.', 'wp-bundle-migrator'));
        }
        set_site_transient($seen, 1, 2 * self::MAX_SKEW);

        return true;
    }

    private static function routeName(string $route): string {
        $prefix = '/' . RestController::NAMESPACE_V1 . '/peer/';
        return strpos($route, $prefix) === 0 ? substr($route, strlen($prefix)) : $route;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function canonicalQuery(array $query): string {
        ksort($query);
        $parts = array();
        foreach ($query as $k => $v) {
            if (is_scalar($v)) {
                $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
            }
        }
        return implode('&', $parts);
    }

    public static function signature(string $secret, string $method, string $route, int $timestamp, string $nonce, string $body, string $query): string {
        $payload = implode("\n", array(strtoupper($method), $route, $query, (string) $timestamp, $nonce, hash('sha256', $body)));
        return hash_hmac('sha256', $payload, $secret);
    }

    /* Client (sending side) ------------------------------------------------ */

    /**
     * Sends a signed request to the other site.
     *
     * @param array{url: string, id: string, secret: string} $key
     * @param array<string, scalar>                         $query
     * @return array{code: int, json: array<string, mixed>, body: string}
     * @throws PeerError On network or remote errors.
     */
    public static function request(array $key, string $route, string $body = '', array $query = array(), bool $raw = false, int $timeout = 120): array {
        $timestamp = time();
        $nonce     = self::b64url(random_bytes(18));
        $qs        = self::canonicalQuery($query);
        $headers   = array(
            'X-WPBM-Key'       => $key['id'],
            'X-WPBM-Time'      => (string) $timestamp,
            'X-WPBM-Nonce'     => $nonce,
            'X-WPBM-Signature' => self::signature($key['secret'], 'POST', $route, $timestamp, $nonce, $body, $qs),
            'Content-Type'     => $raw ? 'application/octet-stream' : 'application/json',
        );
        $url = $key['url'] . 'peer/' . $route . ($qs !== '' ? '?' . $qs : '');

        $response = wp_remote_post(
            $url,
            array(
                'timeout'     => $timeout,
                'redirection' => 0,
                'headers'     => $headers,
                'body'        => $body,
                'sslverify'   => (bool) apply_filters('wpbm_peer_sslverify', true),
            )
        );
        if (is_wp_error($response)) {
            throw new PeerError(
                /* translators: %s: error. */
                sprintf(__('Could not reach the other site: %s', 'wp-bundle-migrator'), $response->get_error_message()),
                0
            );
        }

        $code   = (int) wp_remote_retrieve_response_code($response);
        $text   = (string) wp_remote_retrieve_body($response);
        $json   = json_decode($text, true);
        $isJson = is_array($json);

        if ($code >= 200 && $code < 300) {
            return array('code' => $code, 'json' => $isJson ? $json : array(), 'body' => $text);
        }

        $message = $isJson ? (string) ($json['message'] ?? '') : '';
        if ($code === 404 && $message === '') {
            $message = __('The other site does not have WP Bundle Migrator 1.2 or newer active, or its REST API is blocked.', 'wp-bundle-migrator');
        }
        throw new PeerError(
            /* translators: 1: HTTP status, 2: message. */
            sprintf(__('The other site refused the request (HTTP %1$d): %2$s', 'wp-bundle-migrator'), $code, $message !== '' ? $message : wp_strip_all_tags(substr($text, 0, 200))),
            $code,
            $isJson ? $json : array()
        );
    }

    /**
     * Checks a key and returns the other site's details.
     *
     * @param array{url: string, id: string, secret: string} $key
     * @return array<string, mixed>
     */
    public static function ping(array $key): array {
        return self::request($key, 'ping', '{}', array(), false, 20)['json'];
    }

    /**
     * Seals a pasted key for storage inside a job.
     */
    public static function sealKey(string $key): string {
        self::parseKey($key);
        return Crypto::seal(trim($key));
    }

    /**
     * @return array{url: string, id: string, secret: string}
     */
    public static function openKey(string $sealed): array {
        return self::parseKey(Crypto::open($sealed));
    }

    private static function b64url(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }
}
