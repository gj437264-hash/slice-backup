<?php
/**
 * Cloud storage hub: provider list, configuration and a facade over the active destination.
 *
 * Users bring their own storage account; the plugin never resells or proxies storage.
 *
 * @package WpBundleMigrator
 */

declare(strict_types=1);

namespace WpBundleMigrator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cloud destinations.
 */
final class Cloud {
    /**
     * Providers. "kind" selects the implementation; {region}/{account} are endpoint placeholders.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function providers(): array {
        return array(
            's3'      => array(
                'kind'      => 's3',
                'label'     => 'Amazon S3',
                'endpoint'  => 'https://s3.{region}.amazonaws.com',
                'region'    => 'us-east-1',
                'pathStyle' => false,
                'fields'    => array('region'),
                'links'     => array(
                    'signup'  => 'https://aws.amazon.com/s3/',
                    'pricing' => 'https://aws.amazon.com/s3/pricing/',
                    'keys'    => 'https://docs.aws.amazon.com/IAM/latest/UserGuide/id_credentials_access-keys.html',
                ),
            ),
            'r2'      => array(
                'kind'      => 's3',
                'label'     => 'Cloudflare R2',
                'endpoint'  => 'https://{account}.r2.cloudflarestorage.com',
                'region'    => 'auto',
                'pathStyle' => true,
                'fields'    => array('account'),
                'links'     => array(
                    'signup'  => 'https://www.cloudflare.com/developer-platform/products/r2/',
                    'pricing' => 'https://developers.cloudflare.com/r2/pricing/',
                    'keys'    => 'https://developers.cloudflare.com/r2/api/tokens/',
                ),
            ),
            'gcs'     => array(
                'kind'      => 's3',
                'label'     => 'Google Cloud Storage',
                'endpoint'  => 'https://storage.googleapis.com',
                'region'    => 'auto',
                'pathStyle' => true,
                'fields'    => array(),
                'links'     => array(
                    'signup'  => 'https://cloud.google.com/storage',
                    'pricing' => 'https://cloud.google.com/storage/pricing',
                    'keys'    => 'https://cloud.google.com/storage/docs/authentication/managing-hmackeys',
                ),
            ),
            'wasabi'  => array(
                'kind'      => 's3',
                'label'     => 'Wasabi',
                'endpoint'  => 'https://s3.{region}.wasabisys.com',
                'region'    => 'us-east-1',
                'pathStyle' => true,
                'fields'    => array('region'),
                'links'     => array(
                    'signup'  => 'https://wasabi.com/',
                    'pricing' => 'https://wasabi.com/pricing',
                    'keys'    => 'https://docs.wasabi.com/docs/creating-a-user-account-and-access-key',
                ),
            ),
            'b2'      => array(
                'kind'      => 's3',
                'label'     => 'Backblaze B2',
                'endpoint'  => 'https://s3.{region}.backblazeb2.com',
                'region'    => 'us-west-004',
                'pathStyle' => true,
                'fields'    => array('region'),
                'links'     => array(
                    'signup'  => 'https://www.backblaze.com/cloud-storage',
                    'pricing' => 'https://www.backblaze.com/cloud-storage/pricing',
                    'keys'    => 'https://www.backblaze.com/docs/cloud-storage-create-and-manage-app-keys',
                ),
            ),
            'spaces'  => array(
                'kind'      => 's3',
                'label'     => 'DigitalOcean Spaces',
                'endpoint'  => 'https://{region}.digitaloceanspaces.com',
                'region'    => 'nyc3',
                'pathStyle' => false,
                'fields'    => array('region'),
                'links'     => array(
                    'signup'  => 'https://www.digitalocean.com/products/spaces',
                    'pricing' => 'https://www.digitalocean.com/pricing/spaces-object-storage',
                    'keys'    => 'https://docs.digitalocean.com/products/spaces/how-to/manage-access/',
                ),
            ),
            'minio'   => array(
                'kind'      => 's3',
                'label'     => __('MinIO / other S3-compatible', 'wp-bundle-migrator'),
                'endpoint'  => '',
                'region'    => 'us-east-1',
                'pathStyle' => true,
                'fields'    => array('endpoint', 'region', 'pathStyle'),
                'links'     => array(
                    'signup'  => 'https://min.io/',
                    'pricing' => '',
                    'keys'    => 'https://min.io/docs/minio/linux/administration/identity-access-management/minio-user-management.html',
                ),
            ),
            'sftp'    => array(
                'kind'   => 'sftp',
                'label'  => __('SFTP / SSH server', 'wp-bundle-migrator'),
                'fields' => array(),
                'links'  => array(
                    'keys' => 'https://www.ssh.com/academy/ssh/keygen',
                ),
            ),
            'dropbox' => array(
                'kind'   => 'dropbox',
                'label'  => 'Dropbox',
                'fields' => array(),
                'links'  => array(
                    'signup'  => 'https://www.dropbox.com/register',
                    'pricing' => 'https://www.dropbox.com/plans',
                    'keys'    => 'https://www.dropbox.com/developers/apps/create',
                ),
            ),
        );
    }

    public static function kind(string $provider): string {
        $providers = self::providers();
        return (string) ($providers[$provider]['kind'] ?? '');
    }

    /**
     * Cloud settings with secrets decrypted.
     *
     * @return array<string, mixed>
     */
    public static function config(): array {
        $cloud = Plugin::settings()['cloud'];
        foreach (array('secret_key', 'sftp_password', 'sftp_key', 'sftp_key_pass', 'dropbox_refresh') as $secret) {
            $cloud[$secret] = Crypto::open((string) ($cloud[$secret . '_sealed'] ?? ''));
            unset($cloud[$secret . '_sealed']);
        }
        return $cloud;
    }

    public static function isConfigured(): bool {
        $c = self::config();
        switch (self::kind((string) $c['provider'])) {
            case 's3':
                return $c['bucket'] !== '' && $c['access_key'] !== '' && $c['secret_key'] !== '';
            case 'sftp':
                return $c['sftp_host'] !== '' && $c['sftp_user'] !== '' && ($c['sftp_password'] !== '' || $c['sftp_key'] !== '');
            case 'dropbox':
                return $c['dropbox_app_key'] !== '' && $c['dropbox_refresh'] !== '';
        }
        return false;
    }

    /**
     * The destination for the given (or saved) configuration.
     *
     * @param array<string, mixed>|null $config
     * @throws \InvalidArgumentException When the configuration is incomplete.
     */
    public static function destination(?array $config = null): Destination {
        $c = $config ?? self::config();
        switch (self::kind((string) $c['provider'])) {
            case 's3':
                return new S3Destination($c);
            case 'sftp':
                return new SftpDestination($c);
            case 'dropbox':
                return new DropboxDestination($c);
        }
        throw new \InvalidArgumentException(__('Choose a storage provider.', 'wp-bundle-migrator'));
    }

    /**
     * Human-readable name of the configured destination, e.g. "Wasabi · my-bucket".
     */
    public static function describe(): string {
        $c         = self::config();
        $providers = self::providers();
        $label     = (string) ($providers[$c['provider']]['label'] ?? '');
        switch (self::kind((string) $c['provider'])) {
            case 's3':
                return $label . ' · ' . $c['bucket'];
            case 'sftp':
                return 'SFTP · ' . $c['sftp_user'] . '@' . $c['sftp_host'];
            case 'dropbox':
                return 'Dropbox' . ($c['dropbox_account'] !== '' ? ' · ' . $c['dropbox_account'] : '');
        }
        return $label;
    }

    /**
     * Folder for S3 keys, always ending in "/" (or empty).
     *
     * @param array<string, mixed>|null $config
     */
    public static function prefix(?array $config = null): string {
        $c      = $config ?? self::config();
        $prefix = trim(str_replace('\\', '/', (string) $c['path']), '/');
        return $prefix === '' ? '' : $prefix . '/';
    }

    /**
     * @param array<string, mixed> $config
     * @return array{message: string, fingerprint: string}
     * @throws \Throwable On failure, with a readable message.
     */
    public static function test(array $config): array {
        $destination = self::destination($config);
        $message     = $destination->test();
        return array(
            'message'     => $message,
            'fingerprint' => $destination instanceof SftpDestination ? $destination->fingerprint : '',
        );
    }

    /**
     * @return array<int, array{filename: string, size: float, modified: int}>
     */
    public static function listBundles(): array {
        return self::destination()->listBundles();
    }

    public static function delete(string $filename): void {
        if (!Storage::isBundleName($filename)) {
            throw new \InvalidArgumentException(__('Invalid bundle file name.', 'wp-bundle-migrator'));
        }
        self::destination()->delete($filename);
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function uploadStep(string $path, string $filename, array &$state, float $deadline): bool {
        return self::destination()->uploadStep($path, $filename, $state, $deadline);
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function abortUpload(array $state): void {
        try {
            self::destination()->abortUpload($state);
        } catch (\Throwable $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            // Best effort.
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function downloadStep(string $filename, string $target, array &$state, float $deadline): bool {
        return self::destination()->downloadStep($filename, $target, $state, $deadline);
    }

    /**
     * Appends downloaded bytes to a file.
     *
     * @throws \RuntimeException When the disk is full.
     */
    public static function appendChunk(string $target, string $data): void {
        if (file_put_contents($target, $data, FILE_APPEND) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            throw new \RuntimeException(__('Could not save the downloaded data. The disk may be full.', 'wp-bundle-migrator'));
        }
    }

    /**
     * Retries transient failures (network errors, 5xx) up to 3 times.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     * @throws \Throwable The last error.
     */
    public static function withRetry(callable $operation) {
        $attempt = 0;
        while (true) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                $attempt++;
                $transient = (bool) preg_match('/HTTP 5\d\d|Could not connect|Could not reach|timed out/i', $e->getMessage());
                if (!$transient || $attempt >= 3) {
                    throw $e;
                }
                sleep($attempt * 2);
            }
        }
    }
}
