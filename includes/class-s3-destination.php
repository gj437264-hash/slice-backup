<?php
/**
 * S3-compatible destination (Amazon S3, R2, Google Cloud Storage, Wasabi, B2, Spaces, MinIO).
 *
 * @package WpBundleMigrator
 */

declare(strict_types=1);

namespace WpBundleMigrator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bundles in an S3-compatible bucket.
 */
final class S3Destination implements Destination {
    /** S3 requires at least 5 MiB for every part except the last. */
    public const PART_SIZE = 8388608;

    /** @var S3Client */
    private $client;
    /** @var string */
    private $prefix;
    /** @var string */
    private $label;
    /** @var string */
    private $bucket;

    /**
     * @param array<string, mixed> $config Cloud settings with decrypted secrets.
     * @throws \InvalidArgumentException When the configuration is incomplete.
     */
    public function __construct(array $config) {
        $providers = Cloud::providers();
        $provider  = $providers[$config['provider']] ?? null;
        if ($provider === null || ($provider['kind'] ?? '') !== 's3') {
            throw new \InvalidArgumentException(__('Choose a storage provider.', 'wp-bundle-migrator'));
        }
        foreach (array('bucket' => __('bucket', 'wp-bundle-migrator'), 'access_key' => __('access key', 'wp-bundle-migrator'), 'secret_key' => __('secret key', 'wp-bundle-migrator')) as $field => $label) {
            if (trim((string) ($config[$field] ?? '')) === '') {
                /* translators: %s: field name. */
                throw new \InvalidArgumentException(sprintf(__('The %s is missing.', 'wp-bundle-migrator'), $label));
            }
        }

        $isCustom = $config['provider'] === 'minio';
        $region   = trim((string) $config['region']) !== '' ? trim((string) $config['region']) : (string) $provider['region'];
        $endpoint = $isCustom ? trim((string) $config['endpoint']) : (string) $provider['endpoint'];
        if ($config['provider'] === 'r2') {
            $account = (string) preg_replace('/[^a-f0-9]/', '', strtolower((string) $config['account_id']));
            if ($account === '') {
                throw new \InvalidArgumentException(__('The Cloudflare account ID is missing.', 'wp-bundle-migrator'));
            }
            $endpoint = str_replace('{account}', $account, $endpoint);
        }
        $endpoint = str_replace('{region}', rawurlencode($region), $endpoint);
        if ($endpoint === '' || !preg_match('#^https?://#i', $endpoint)) {
            throw new \InvalidArgumentException(__('Enter the endpoint URL of your storage server, for example https://minio.example.com:9000', 'wp-bundle-migrator'));
        }
        if (!$isCustom && !wp_http_validate_url($endpoint)) {
            throw new \InvalidArgumentException(__('The storage endpoint is not a valid URL.', 'wp-bundle-migrator'));
        }

        $this->bucket = trim((string) $config['bucket']);
        $this->label  = (string) $provider['label'];
        $this->prefix = Cloud::prefix($config);
        $this->client = new S3Client(
            $endpoint,
            $region,
            $this->bucket,
            trim((string) $config['access_key']),
            (string) $config['secret_key'],
            $isCustom ? !empty($config['path_style']) : (bool) $provider['pathStyle']
        );
    }

    public function test(): string {
        $key = $this->prefix . '.wpbm-connection-test-' . strtolower(wp_generate_password(8, false));
        $this->client->putObject($key, 'WP Bundle Migrator connection test', 'text/plain');
        $found = false;
        foreach ($this->client->listObjects($key) as $item) {
            if ($item['key'] === $key) {
                $found = true;
            }
        }
        $this->client->deleteObject($key);
        if (!$found) {
            throw new \RuntimeException(__('The test file was written but did not show up in the bucket listing. Check the key\'s list permission.', 'wp-bundle-migrator'));
        }
        return sprintf(
            /* translators: 1: provider name, 2: bucket name. */
            __('Connected to %1$s bucket "%2$s". Writing, listing and deleting files all work.', 'wp-bundle-migrator'),
            $this->label,
            $this->bucket
        );
    }

    public function listBundles(): array {
        $items = array();
        foreach ($this->client->listObjects($this->prefix) as $object) {
            $name = substr($object['key'], strlen($this->prefix));
            if (strpos($name, '/') === false && Storage::isBundleName($name)) {
                $items[] = array('filename' => $name, 'size' => $object['size'], 'modified' => $object['modified']);
            }
        }
        return $items;
    }

    public function delete(string $filename): void {
        $this->client->deleteObject($this->prefix . $filename);
    }

    public function uploadStep(string $path, string $filename, array &$state, float $deadline): bool {
        $key  = $this->prefix . $filename;
        $size = Storage::filesize($path);

        if ($size <= self::PART_SIZE) {
            $this->client->putObject($key, (string) file_get_contents($path)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $state['offset'] = $size;
            return true;
        }

        if (empty($state['uploadId'])) {
            $state = array('uploadId' => $this->client->createMultipart($key), 'key' => $key, 'offset' => 0.0, 'part' => 1, 'etags' => array());
        }

        $handle = fopen($path, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$handle) {
            throw new \RuntimeException(__('Could not read the bundle for uploading.', 'wp-bundle-migrator'));
        }
        $client = $this->client;
        try {
            $first = true;
            while ((float) $state['offset'] < $size && ($first || microtime(true) < $deadline)) {
                $first = false;
                Crypto::seek($handle, (float) $state['offset']);
                $body   = (string) fread($handle, self::PART_SIZE); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                $number = (int) $state['part'];
                $id     = (string) $state['uploadId'];
                $etag   = Cloud::withRetry(
                    static function () use ($client, $key, $id, $number, $body): string {
                        return $client->uploadPart($key, $id, $number, $body);
                    }
                );
                $state['etags'][(string) $number] = $etag;
                $state['part']   = $number + 1;
                $state['offset'] = (float) $state['offset'] + strlen($body);
            }
        } finally {
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }

        if ((float) $state['offset'] < $size) {
            return false;
        }
        $etags = array();
        foreach ((array) $state['etags'] as $number => $etag) {
            $etags[(int) $number] = (string) $etag;
        }
        $this->client->completeMultipart($key, (string) $state['uploadId'], $etags);
        return true;
    }

    public function abortUpload(array $state): void {
        if (!empty($state['uploadId']) && !empty($state['key'])) {
            $this->client->abortMultipart((string) $state['key'], (string) $state['uploadId']);
        }
    }

    public function downloadStep(string $filename, string $target, array &$state, float $deadline): bool {
        $key    = $this->prefix . $filename;
        $client = $this->client;

        if (!isset($state['size'])) {
            $size = $client->headObject($key);
            if ($size === null) {
                /* translators: %s: file name. */
                throw new \RuntimeException(sprintf(__('%s was not found in cloud storage.', 'wp-bundle-migrator'), $filename));
            }
            file_put_contents($target, ''); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            $state = array('size' => $size, 'offset' => 0.0);
        }

        $size  = (float) $state['size'];
        $first = true;
        while ((float) $state['offset'] < $size && ($first || microtime(true) < $deadline)) {
            $first = false;
            $start = (float) $state['offset'];
            $end   = min($size, $start + self::PART_SIZE) - 1;
            $body  = Cloud::withRetry(
                static function () use ($client, $key, $start, $end): string {
                    return $client->getRange($key, $start, $end);
                }
            );
            Cloud::appendChunk($target, $body);
            $state['offset'] = $start + strlen($body);
        }
        return (float) $state['offset'] >= $size;
    }
}
