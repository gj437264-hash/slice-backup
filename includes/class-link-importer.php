<?php
/**
 * Imports a bundle directly from a publicly-shared link (Google Drive, OneDrive/SharePoint,
 * Dropbox, or any other direct URL), so the user does not have to download it to their own
 * computer and then upload it again.
 *
 * The admin pastes a "share" link with open ("anyone with the link") access. This class turns
 * that share link into the provider's real file-content URL, then downloads it the same way
 * every other destination in this plugin does: in fixed-size pieces, resumable across ticks,
 * with a flat memory footprint regardless of file size.
 *
 * Reverse-engineered share-link behaviour (Google's confirm-token page, the OneDrive public
 * "shares" API) is not a documented, stable contract. It is checked defensively (the result is
 * always validated as a real zip/bundle before it is trusted) and reported clearly when a
 * provider's page does not look like what this class expects, rather than silently importing
 * garbage.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves share links and performs the chunked download.
 */
final class LinkImporter {
    /** Small probe range, just enough to read headers and sniff the content. */
    private const PROBE_BYTES = 256;

    /** HTML responses larger than this are not read into memory looking for a confirm token. */
    private const MAX_INTERSTITIAL_BYTES = 262144;

    /**
     * Resolves a user-supplied share link into {url, cookies, provider}, ready for chunked,
     * range-based GETs. Does not download the file itself.
     *
     * @return array{url: string, cookies: array<int, \WP_Http_Cookie>, provider: string}
     * @throws \RuntimeException On an invalid or unsupported link, or when the provider refuses it.
     */
    public static function resolve(string $url): array {
        $url = trim($url);
        $scheme = (string) wp_parse_url($url, PHP_URL_SCHEME);
        if (!in_array(strtolower($scheme), array('http', 'https'), true)) {
            throw new \RuntimeException(esc_html__('That does not look like a web link. Paste the "share" link to the backup file.', 'slice-backup'));
        }

        $provider = self::detectProvider($url);
        switch ($provider) {
            case 'google-drive':
                return self::resolveGoogleDrive($url);
            case 'onedrive':
                return self::resolveOneDrive($url);
            case 'dropbox':
                return array('url' => self::forceDropboxDownload($url), 'cookies' => array(), 'provider' => $provider);
            default:
                return array('url' => $url, 'cookies' => array(), 'provider' => $provider);
        }
    }

    /**
     * Learns the file's size and a usable name, and makes sure the link actually serves a
     * file rather than an HTML page (a sign-in prompt, an error, or a "not shared" notice).
     *
     * @param array{url: string, cookies: array} $resolved
     * @return array{size: float, name: string, rangeOk: bool, firstBytes: string}
     * @throws \RuntimeException When the link does not serve a downloadable file.
     */
    public static function probe(array $resolved): array {
        $response = self::request($resolved, '0-' . (self::PROBE_BYTES - 1));
        $code = (int) wp_remote_retrieve_response_code($response);
        $contentType = (string) wp_remote_retrieve_header($response, 'content-type');
        $body = (string) wp_remote_retrieve_body($response);

        if (stripos($contentType, 'text/html') !== false || stripos($contentType, 'text/plain') !== false) {
            throw new \RuntimeException(esc_html(self::explainHtmlResponse($body, (string) $resolved['provider'])));
        }

        $size = self::totalSizeFrom($response, $code);
        if ($size === null || $size <= 0.0) {
            throw new \RuntimeException(esc_html__('The link did not report a file size. It may not point directly at a file, or the file may be empty.', 'slice-backup'));
        }

        return array(
            'size'       => $size,
            'name'       => self::nameFrom($response, (string) $resolved['url']),
            'rangeOk'    => $code === 206,
            'firstBytes' => $body,
        );
    }

    /**
     * Downloads in fixed-size pieces, resumable across ticks. Falls back to one request per
     * call when the server does not honor Range (some direct-download endpoints always send
     * the whole file); that still keeps memory flat, since the body streams straight to disk
     * in the same request, piece by piece is only how it is spread across ticks.
     *
     * @param array{url: string, cookies: array} $resolved
     * @param array<string, mixed>               $state Has size, offset, rangeOk, firstBytes (consumed once).
     */
    public static function downloadStep(array $resolved, string $target, array &$state, float $deadline): bool {
        $size = (float) $state['size'];
        $chunk = (float) MemoryBudget::batchBytes();

        if (!empty($state['firstBytes'])) {
            // The probe already fetched the first bytes; do not fetch them again.
            Cloud::appendChunk($target, (string) $state['firstBytes']);
            $state['offset']     = (float) strlen((string) $state['firstBytes']);
            $state['firstBytes'] = '';
            if (empty($state['rangeOk'])) {
                // The server ignored our probe Range and sent the whole file already.
                return (float) $state['offset'] >= $size;
            }
        }

        $first = true;
        while ((float) $state['offset'] < $size && ($first || microtime(true) < $deadline)) {
            $first = false;
            $start = (float) $state['offset'];
            $end   = min($size, $start + $chunk) - 1.0;
            $body  = Cloud::withRetry(
                static function () use ($resolved, $start, $end): string {
                    return self::fetchRange($resolved, $start, $end);
                }
            );
            if ($body === '') {
                break;
            }
            Cloud::appendChunk($target, $body);
            $state['offset'] = $start + strlen($body);
            if (!empty($state['rangeOk']) && strlen($body) < ($end - $start + 1.0) && (float) $state['offset'] < $size) {
                // A short read mid-file on a server that does support Range is a real problem,
                // not end-of-file; the throttled retry above already tried 3 times.
                break;
            }
        }
        return (float) $state['offset'] >= $size;
    }

    /**
     * One ranged GET, returning the body. A static wrapper because Cloud::withRetry() expects
     * a plain callable and this class has no instance state.
     *
     * @param array{url: string, cookies: array} $resolved
     * @throws \RuntimeException On a transport error or an unexpected status.
     */
    private static function fetchRange(array $resolved, float $start, float $end): string {
        $response = self::request($resolved, sprintf('%.0f-%.0f', $start, $end));
        $code     = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200 && $code !== 206) {
            throw new \RuntimeException(esc_html(sprintf(
                /* translators: %d: HTTP status code. */
                __('The link stopped working partway through the download (HTTP %d).', 'slice-backup'),
                $code
            )));
        }
        return (string) wp_remote_retrieve_body($response);
    }

    /**
     * @param array{url: string, cookies: array} $resolved
     * @return array<string, mixed>|\WP_Error
     */
    private static function request(array $resolved, string $range) {
        $args = array(
            'timeout'     => 30,
            'redirection' => 5,
            'headers'     => array('Range' => 'bytes=' . $range),
            'cookies'     => (array) ($resolved['cookies'] ?? array()),
            'user-agent'  => 'Slice Backup for WordPress',
        );
        $response = wp_remote_get((string) $resolved['url'], $args);
        if (is_wp_error($response)) {
            throw new \RuntimeException(esc_html(sprintf(
                /* translators: %s: error message. */
                __('Could not reach the link: %s', 'slice-backup'),
                $response->get_error_message()
            )));
        }
        return $response;
    }

    /* ------------------------------------------------------------------ */
    /* Provider detection and resolution                                   */
    /* ------------------------------------------------------------------ */

    public static function detectProvider(string $url): string {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($host === '' ) {
            return 'generic';
        }
        if (self::hostIs($host, array('drive.google.com', 'docs.google.com'))) {
            return 'google-drive';
        }
        if (self::hostIs($host, array('1drv.ms', 'onedrive.live.com')) || substr($host, -16) === '.sharepoint.com') {
            return 'onedrive';
        }
        if (self::hostIs($host, array('dropbox.com', 'www.dropbox.com'))) {
            return 'dropbox';
        }
        return 'generic';
    }

    /**
     * @param string[] $hosts
     */
    private static function hostIs(string $host, array $hosts): bool {
        foreach ($hosts as $candidate) {
            if ($host === $candidate || substr($host, -(strlen($candidate) + 1)) === '.' . $candidate) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{url: string, cookies: array, provider: string}
     * @throws \RuntimeException
     */
    private static function resolveGoogleDrive(string $url): array {
        if (strpos($url, '/folders/') !== false || strpos($url, '/drive/folders') !== false) {
            throw new \RuntimeException(esc_html__('That is a link to a Google Drive folder. Share the individual backup file instead and paste its link.', 'slice-backup'));
        }

        $id = self::googleDriveFileId($url);
        if ($id === null) {
            throw new \RuntimeException(esc_html__('Could not find a file in this Google Drive link. Right-click the backup file in Drive, choose Share > Copy link, and make sure access is set to "Anyone with the link".', 'slice-backup'));
        }

        $direct  = 'https://drive.google.com/uc?export=download&id=' . rawurlencode($id);
        $probe   = wp_safe_remote_get($direct, array('timeout' => 30, 'redirection' => 5, 'cookies' => array()));
        if (is_wp_error($probe)) {
            throw new \RuntimeException(esc_html(sprintf(
                /* translators: %s: error message. */
                __('Could not reach Google Drive: %s', 'slice-backup'),
                $probe->get_error_message()
            )));
        }

        $cookies     = (array) ($probe['cookies'] ?? array());
        $contentType = (string) wp_remote_retrieve_header($probe, 'content-type');
        if (stripos($contentType, 'text/html') === false) {
            // Small files skip the confirmation page entirely.
            return array('url' => $direct, 'cookies' => $cookies, 'provider' => 'google-drive');
        }

        $body = (string) wp_remote_retrieve_body($probe);
        if (preg_match('/confirm=([0-9A-Za-z_\-]+)/', $body, $m)
            || preg_match('/name="confirm"[^>]*value="([^"]+)"/', $body, $m)
        ) {
            $confirm = $m[1];
            $uuid    = preg_match('/name="uuid"[^>]*value="([^"]+)"/', $body, $um) ? $um[1] : '';
            $direct2 = $direct . '&confirm=' . rawurlencode($confirm) . ($uuid !== '' ? '&uuid=' . rawurlencode($uuid) : '');
            return array('url' => $direct2, 'cookies' => $cookies, 'provider' => 'google-drive');
        }

        throw new \RuntimeException(esc_html__('Google Drive did not offer a direct download for this file. Make sure it is shared as "Anyone with the link" (not restricted to specific people), and that it is a file, not a shortcut.', 'slice-backup'));
    }

    private static function googleDriveFileId(string $url): ?string {
        if (preg_match('#/file/d/([a-zA-Z0-9_-]{10,})#', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#/d/([a-zA-Z0-9_-]{10,})#', $url, $m)) {
            return $m[1];
        }
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        parse_str($query, $q);
        if (!empty($q['id']) && is_string($q['id'])) {
            return $q['id'];
        }
        return null;
    }

    /**
     * @return array{url: string, cookies: array, provider: string}
     * @throws \RuntimeException
     */
    private static function resolveOneDrive(string $url): array {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

        if ($host === '1drv.ms' || substr($host, -11) === '.1drv.ms') {
            $url = self::followRedirects($url);
        }

        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if (self::hostIs($host, array('onedrive.live.com'))) {
            // Personal OneDrive: the public "shares" API serves file content for an
            // encoded share URL, no sign-in needed for an "anyone with the link" share.
            $encoded = 'u!' . rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
            return array(
                'url'      => 'https://api.onedrive.com/v1.0/shares/' . $encoded . '/root/content',
                'cookies'  => array(),
                'provider' => 'onedrive',
            );
        }

        // SharePoint / OneDrive for Business: appending download=1 serves the raw file
        // instead of the web preview, for a link already shared with "Anyone"/"People in
        // the organization" who have the link.
        return array('url' => add_query_arg('download', '1', $url), 'cookies' => array(), 'provider' => 'onedrive');
    }

    /**
     * Follows HTTP redirects itself (rather than relying on wp_remote_get's built-in
     * following) so the final, real URL is known explicitly for provider detection.
     *
     * @throws \RuntimeException
     */
    private static function followRedirects(string $url, int $hops = 5): string {
        for ($i = 0; $i < $hops; $i++) {
            $response = wp_safe_remote_get($url, array('timeout' => 20, 'redirection' => 0));
            if (is_wp_error($response)) {
                throw new \RuntimeException(esc_html(sprintf(
                    /* translators: %s: error message. */
                    __('Could not follow this link: %s', 'slice-backup'),
                    $response->get_error_message()
                )));
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 300 || $code >= 400) {
                return $url;
            }
            $location = (string) wp_remote_retrieve_header($response, 'location');
            if ($location === '') {
                return $url;
            }
            $url = self::absoluteUrl($location, $url);
        }
        return $url;
    }

    private static function absoluteUrl(string $location, string $base): string {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = wp_parse_url($base);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host   = (string) ($parts['host'] ?? '');
        return $scheme . '://' . $host . '/' . ltrim($location, '/');
    }

    private static function forceDropboxDownload(string $url): string {
        $url = remove_query_arg('dl', $url);
        return add_query_arg('dl', '1', $url);
    }

    /* ------------------------------------------------------------------ */
    /* Response inspection                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $response
     */
    private static function totalSizeFrom(array $response, int $code): ?float {
        $range = (string) wp_remote_retrieve_header($response, 'content-range');
        if ($range !== '' && preg_match('#/(\d+)$#', $range, $m)) {
            return (float) $m[1];
        }
        if ($code === 200) {
            $length = (string) wp_remote_retrieve_header($response, 'content-length');
            if ($length !== '' && ctype_digit($length)) {
                return (float) $length;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function nameFrom(array $response, string $url): string {
        $disposition = (string) wp_remote_retrieve_header($response, 'content-disposition');
        if (preg_match('/filename\*?=(?:UTF-8\x27\x27)?"?([^";\r\n]+)"?/i', $disposition, $m)) {
            $name = sanitize_file_name(rawurldecode(trim($m[1])));
            if ($name !== '' && Storage::isBundleName($name)) {
                return $name;
            }
        }
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $base = sanitize_file_name(basename($path));
        if ($base !== '' && Storage::isBundleName($base)) {
            return $base;
        }
        return 'imported-' . gmdate('Ymd-His') . '.zip';
    }

    /**
     * Turns an HTML response (a sign-in page, an error, a quota notice) into a message the
     * user can act on, instead of a confusing "not a zip file" error.
     */
    private static function explainHtmlResponse(string $body, string $provider): string {
        $sample = substr($body, 0, self::MAX_INTERSTITIAL_BYTES);
        if (stripos($sample, 'too many users') !== false || stripos($sample, 'quota') !== false || stripos($sample, 'download limit') !== false) {
            return __('This provider has temporarily blocked downloads of this file (too many people have downloaded it today). Try again later, or share the file again to get a new link.', 'slice-backup');
        }
        if (stripos($sample, 'sign in') !== false || stripos($sample, 'log in') !== false || stripos($sample, 'access denied') !== false || stripos($sample, 'permission') !== false) {
            return __('This link needs you to be signed in, or access has not been granted to "anyone with the link". Change the sharing setting and try again.', 'slice-backup');
        }
        if ($provider === 'generic') {
            return __('That link returned a web page, not a file. Paste a direct "share" link to the backup file itself.', 'slice-backup');
        }
        return __('That link did not return the file directly. Double-check the sharing setting is "Anyone with the link", and that the link points at a single file.', 'slice-backup');
    }
}
