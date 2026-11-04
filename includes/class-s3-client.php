<?php
/**
 * Minimal S3-compatible client (AWS Signature Version 4) built on the WordPress HTTP API.
 * Works with Amazon S3, Cloudflare R2, Google Cloud Storage (HMAC keys), Wasabi,
 * Backblaze B2, DigitalOcean Spaces and MinIO.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * S3 API requests.
 */
final class S3Client {
    /** @var string e.g. https://s3.us-east-1.amazonaws.com */
    private $endpoint;
    /** @var string */
    private $region;
    /** @var string */
    private $bucket;
    /** @var string */
    private $accessKey;
    /** @var string */
    private $secretKey;
    /** @var bool */
    private $pathStyle;
    /** @var callable|null Used by tests to replace the clock. */
    public $clock = null;

    public function __construct(string $endpoint, string $region, string $bucket, string $accessKey, string $secretKey, bool $pathStyle) {
        $this->endpoint  = untrailingslashit($endpoint);
        $this->region    = $region !== '' ? $region : 'us-east-1';
        $this->bucket    = $bucket;
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->pathStyle = $pathStyle;
    }

    /**
     * Sends a signed request.
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @return array{code: int, body: string, headers: array<string, string>}
     * @throws \RuntimeException On network errors.
     */
    public function request(string $method, string $key, array $query = array(), string $body = '', array $headers = array(), int $timeout = 120): array {
        $signed = $this->sign($method, $key, $query, $body, $headers);

        $response = wp_remote_request(
            $signed['url'],
            array(
                'method'      => $method,
                'headers'     => $signed['headers'],
                'body'        => $body,
                'timeout'     => $timeout,
                'redirection' => 0,
            )
        );
        if (is_wp_error($response)) {
            throw new \RuntimeException(
                esc_html(/* translators: %s: error message. */
                sprintf(__('Could not connect to the storage service: %s', 'slice-backup'), $response->get_error_message()))
            );
        }

        $out = array();
        foreach ((array) wp_remote_retrieve_headers($response) as $name => $value) {
            $out[strtolower((string) $name)] = is_array($value) ? (string) end($value) : (string) $value;
        }

        return array(
            'code'    => (int) wp_remote_retrieve_response_code($response),
            'body'    => (string) wp_remote_retrieve_body($response),
            'headers' => $out,
        );
    }

    /**
     * Builds the URL and SigV4 headers. Every header passed in is signed.
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @return array{url: string, headers: array<string, string>, canonical: string}
     */
    public function sign(string $method, string $key, array $query, string $body, array $headers = array()): array {
        $parts  = wp_parse_url($this->endpoint);
        $scheme = $parts['scheme'] ?? 'https';
        $host   = (string) ($parts['host'] ?? '');
        if (!empty($parts['port'])) {
            $host .= ':' . $parts['port'];
        }
        $basePath = rtrim((string) ($parts['path'] ?? ''), '/');

        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
        if ($this->pathStyle) {
            $uri = $basePath . '/' . rawurlencode($this->bucket) . ($key !== '' ? '/' . $encodedKey : '');
        } else {
            $host = $this->bucket . '.' . $host;
            $uri  = $basePath . '/' . $encodedKey;
        }
        if ($uri === '') {
            $uri = '/';
        }

        ksort($query, SORT_STRING);
        $pairs = array();
        foreach ($query as $name => $value) {
            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }
        $canonicalQuery = implode('&', $pairs);

        $time        = $this->clock ? (int) call_user_func($this->clock) : time();
        $amzDate     = gmdate('Ymd\THis\Z', $time);
        $date        = gmdate('Ymd', $time);
        $payloadHash = hash('sha256', $body);

        $all = array('host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate);
        foreach ($headers as $name => $value) {
            $all[strtolower($name)] = trim((string) $value);
        }
        ksort($all, SORT_STRING);

        $canonicalHeaders = '';
        foreach ($all as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }
        $signedHeaders = implode(';', array_keys($all));

        $canonical = implode("\n", array($method, $uri, $canonicalQuery, $canonicalHeaders, $signedHeaders, $payloadHash));
        $scope     = $date . '/' . $this->region . '/s3/aws4_request';
        $toSign    = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonical);

        $kDate    = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $toSign, $kSigning);

        $all['authorization'] = sprintf('AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s', $this->accessKey, $scope, $signedHeaders, $signature);
        unset($all['host']);

        return array(
            'url'       => $scheme . '://' . $host . $uri . ($canonicalQuery !== '' ? '?' . $canonicalQuery : ''),
            'headers'   => $all,
            'canonical' => $canonical,
        );
    }

    /**
     * Turns an S3 error response into a readable message.
     *
     * @param array{code: int, body: string, headers: array<string, string>} $response
     */
    public static function errorMessage(array $response): string {
        $code    = '';
        $message = '';
        if ($response['body'] !== '' && strpos($response['body'], '<') !== false) {
            $xml = self::xml($response['body']);
            if ($xml !== null) {
                $code    = (string) ($xml->Code ?? '');
                $message = (string) ($xml->Message ?? '');
            }
        }
        $hints = array(
            'InvalidAccessKeyId'    => __('The access key ID is wrong.', 'slice-backup'),
            'SignatureDoesNotMatch' => __('The secret key is wrong (or the region does not match the bucket).', 'slice-backup'),
            'NoSuchBucket'          => __('The bucket does not exist.', 'slice-backup'),
            'AccessDenied'          => __('Access denied. Give the key permission to list, read, write and delete objects in this bucket.', 'slice-backup'),
            'AuthorizationHeaderMalformed' => __('The region is wrong for this bucket.', 'slice-backup'),
            'PermanentRedirect'     => __('The bucket is in a different region. Set the correct region.', 'slice-backup'),
        );
        $hint = $hints[$code] ?? '';
        $text = trim($code . ($message !== '' ? ': ' . $message : ''));

        return sprintf(
            /* translators: 1: HTTP status, 2: error details. */
            __('Storage service error (HTTP %1$d) %2$s', 'slice-backup'),
            $response['code'],
            trim($hint . ' ' . ($text !== '' ? '[' . $text . ']' : ''))
        );
    }

    /**
     * Parses XML without network access or entity expansion.
     */
    public static function xml(string $body): ?\SimpleXMLElement {
        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $xml instanceof \SimpleXMLElement ? $xml : null;
    }

    /**
     * @param array{code: int, body: string, headers: array<string, string>} $response
     * @throws \RuntimeException When the status is not 2xx.
     */
    private static function expectOk(array $response): void {
        if ($response['code'] < 200 || $response['code'] >= 300) {
            throw new \RuntimeException(esc_html(self::errorMessage($response)));
        }
    }

    /* Operations --------------------------------------------------------- */

    public function putObject(string $key, string $body, string $contentType = 'application/octet-stream'): void {
        self::expectOk($this->request('PUT', $key, array(), $body, array('content-type' => $contentType)));
    }

    public function deleteObject(string $key): void {
        $response = $this->request('DELETE', $key);
        if ($response['code'] !== 404) {
            self::expectOk($response);
        }
    }

    /**
     * @return array<int, array{key: string, size: float, modified: int}>
     */
    public function listObjects(string $prefix): array {
        $items  = array();
        $marker = '';
        for ($page = 0; $page < 100; $page++) {
            $query = array('prefix' => $prefix, 'max-keys' => '1000');
            if ($marker !== '') {
                $query['marker'] = $marker;
            }
            $response = $this->request('GET', '', $query);
            self::expectOk($response);
            $xml = self::xml($response['body']);
            if ($xml === null) {
                throw new \RuntimeException(esc_html__('The storage service returned an unreadable listing.', 'slice-backup'));
            }
            $last = '';
            foreach ($xml->Contents as $object) {
                $last    = (string) $object->Key;
                $items[] = array(
                    'key'      => $last,
                    'size'     => (float) $object->Size,
                    'modified' => (int) strtotime((string) $object->LastModified),
                );
            }
            $truncated = strtolower((string) $xml->IsTruncated) === 'true';
            if (!$truncated) {
                break;
            }
            $marker = (string) ($xml->NextMarker ?? '') !== '' ? (string) $xml->NextMarker : $last;
            if ($marker === '') {
                break;
            }
        }
        return $items;
    }

    public function headObject(string $key): ?float {
        $response = $this->request('HEAD', $key);
        if ($response['code'] === 404) {
            return null;
        }
        self::expectOk($response);
        return (float) ($response['headers']['content-length'] ?? 0);
    }

    /**
     * Downloads bytes [$start, $end] of an object.
     */
    public function getRange(string $key, float $start, float $end): string {
        $response = $this->request('GET', $key, array(), '', array('range' => sprintf('bytes=%.0f-%.0f', $start, $end)), 180);
        self::expectOk($response);
        return $response['body'];
    }

    public function createMultipart(string $key): string {
        $response = $this->request('POST', $key, array('uploads' => ''), '', array('content-type' => 'application/octet-stream'));
        self::expectOk($response);
        $xml = self::xml($response['body']);
        $id  = $xml !== null ? (string) $xml->UploadId : '';
        if ($id === '') {
            throw new \RuntimeException(esc_html__('The storage service did not start the upload.', 'slice-backup'));
        }
        return $id;
    }

    public function uploadPart(string $key, string $uploadId, int $number, string $body): string {
        $response = $this->request('PUT', $key, array('partNumber' => (string) $number, 'uploadId' => $uploadId), $body, array(), 300);
        self::expectOk($response);
        $etag = $response['headers']['etag'] ?? '';
        if ($etag === '') {
            throw new \RuntimeException(esc_html__('The storage service did not confirm an uploaded part.', 'slice-backup'));
        }
        return $etag;
    }

    /**
     * @param array<int, string> $etags Part number => ETag.
     */
    public function completeMultipart(string $key, string $uploadId, array $etags): void {
        ksort($etags);
        $xml = '<CompleteMultipartUpload>';
        foreach ($etags as $number => $etag) {
            $xml .= '<Part><PartNumber>' . (int) $number . '</PartNumber><ETag>' . esc_html($etag) . '</ETag></Part>';
        }
        $xml .= '</CompleteMultipartUpload>';

        $response = $this->request('POST', $key, array('uploadId' => $uploadId), $xml, array('content-type' => 'application/xml'), 300);
        self::expectOk($response);
        // S3 can report an error inside a 200 response.
        if (strpos($response['body'], '<Error>') !== false) {
            throw new \RuntimeException(esc_html(self::errorMessage(array('code' => 500, 'body' => $response['body'], 'headers' => array()))));
        }
    }

    public function abortMultipart(string $key, string $uploadId): void {
        try {
            $this->request('DELETE', $key, array('uploadId' => $uploadId));
        } catch (\Throwable $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            // Best effort; providers also expire incomplete uploads.
        }
    }
}
