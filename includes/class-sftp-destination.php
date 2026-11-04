<?php
/**
 * SFTP destination (any SSH server), using the bundled phpseclib library.
 *
 * The server's host key fingerprint is pinned on the first successful connection
 * (trust on first use); later connections to a server with a different key are refused,
 * which protects against man-in-the-middle attacks.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bundles on an SFTP server.
 */
final class SftpDestination implements Destination {
    private const CHUNK   = 4194304;
    private const TIMEOUT = 30;

    /** @var array<string, mixed> */
    private $config;
    /** @var \phpseclib3\Net\SFTP|null */
    private $sftp = null;
    /** @var string */
    private $dir;
    /** @var string Fingerprint seen on the last connection. */
    public $fingerprint = '';

    /**
     * @param array<string, mixed> $config Cloud settings with decrypted secrets.
     * @throws \InvalidArgumentException When the configuration is incomplete.
     */
    public function __construct(array $config) {
        if (trim((string) $config['sftp_host']) === '') {
            throw new \InvalidArgumentException(esc_html__('The SFTP host is missing.', 'slice-backup'));
        }
        if (trim((string) $config['sftp_user']) === '') {
            throw new \InvalidArgumentException(esc_html__('The SFTP username is missing.', 'slice-backup'));
        }
        $auth = (string) $config['sftp_auth'] === 'key' ? 'key' : 'password';
        if ($auth === 'key' && trim((string) $config['sftp_key']) === '') {
            throw new \InvalidArgumentException(esc_html__('Paste the private key for SFTP.', 'slice-backup'));
        }
        if ($auth === 'password' && (string) $config['sftp_password'] === '') {
            throw new \InvalidArgumentException(esc_html__('The SFTP password is missing.', 'slice-backup'));
        }

        $this->config = $config;
        $path         = trim(str_replace('\\', '/', (string) $config['path']));
        $this->dir    = $path === '' ? '.' : rtrim($path, '/');
    }

    /**
     * SHA256 fingerprint in OpenSSH format ("SHA256:...").
     */
    public static function fingerprint(string $hostKey): string {
        $parts = explode(' ', trim($hostKey));
        $blob  = base64_decode($parts[1] ?? $parts[0], true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        if (!is_string($blob) || $blob === '') {
            $blob = $hostKey;
        }
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '='); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /**
     * @throws \RuntimeException On connection, host key or login failure.
     */
    private function connect(): \phpseclib3\Net\SFTP {
        if ($this->sftp !== null && $this->sftp->isConnected()) {
            return $this->sftp;
        }
        Vendor::phpseclib();

        $host = trim((string) $this->config['sftp_host']);
        $port = max(1, min(65535, (int) ($this->config['sftp_port'] ?: 22)));
        $sftp = new \phpseclib3\Net\SFTP($host, $port, self::TIMEOUT);

        try {
            $hostKey = $sftp->getServerPublicHostKey();
        } catch (\Throwable $e) {
            $hostKey = false;
        }
        if (!is_string($hostKey) || $hostKey === '') {
            throw new \RuntimeException(
                esc_html(/* translators: 1: host, 2: port. */
                sprintf(__('Could not connect to %1$s on port %2$d. Check the host, port and the server\'s firewall.', 'slice-backup'), $host, $port))
            );
        }

        $this->fingerprint = self::fingerprint($hostKey);
        $pinned            = (string) ($this->config['sftp_fingerprint'] ?? '');
        if ($pinned !== '' && !hash_equals($pinned, $this->fingerprint)) {
            throw new \RuntimeException(
                esc_html(sprintf(
                    /* translators: 1: expected fingerprint, 2: received fingerprint. */
                    __('The SFTP server\'s host key has changed (expected %1$s, got %2$s). This can mean someone is intercepting the connection. If you changed the server on purpose, click "Forget host key" and test again.', 'slice-backup'),
                    $pinned,
                    $this->fingerprint
                ))
            );
        }

        $user = (string) $this->config['sftp_user'];
        if ((string) $this->config['sftp_auth'] === 'key') {
            try {
                $passphrase = (string) $this->config['sftp_key_pass'];
                $credential = \phpseclib3\Crypt\PublicKeyLoader::load((string) $this->config['sftp_key'], $passphrase !== '' ? $passphrase : false);
            } catch (\Throwable $e) {
                throw new \RuntimeException(esc_html__('The private key could not be read. Paste the whole key (OpenSSH or PEM format) and check its passphrase.', 'slice-backup'));
            }
        } else {
            $credential = (string) $this->config['sftp_password'];
        }

        if (!$sftp->login($user, $credential)) {
            throw new \RuntimeException(esc_html__('SFTP login failed. Check the username and password or key.', 'slice-backup'));
        }

        if ($pinned === '') {
            Plugin::pinSftpFingerprint($this->fingerprint);
            $this->config['sftp_fingerprint'] = $this->fingerprint;
        }

        $this->sftp = $sftp;
        return $sftp;
    }

    private function ensureDir(\phpseclib3\Net\SFTP $sftp): void {
        if ($this->dir !== '.' && !$sftp->is_dir($this->dir) && !$sftp->mkdir($this->dir, -1, true)) {
            /* translators: %s: folder path. */
            throw new \RuntimeException(esc_html(sprintf(__('Could not create the folder %s on the SFTP server. Check the path and permissions.', 'slice-backup'), $this->dir)));
        }
    }

    private function remote(string $name): string {
        return $this->dir . '/' . $name;
    }

    /**
     * Runs an SFTP operation, reconnecting once if the connection dropped.
     *
     * @template T
     * @param callable(\phpseclib3\Net\SFTP): T $operation
     * @return T
     */
    private function run(callable $operation) {
        try {
            return $operation($this->connect());
        } catch (\Throwable $e) {
            if ($this->sftp !== null && $this->sftp->isConnected()) {
                throw $e;
            }
            $this->sftp = null;
            return $operation($this->connect());
        }
    }

    public function test(): string {
        $sftp = $this->connect();
        $this->ensureDir($sftp);
        $name = '.slicebk-connection-test-' . strtolower(wp_generate_password(8, false));
        if (!$sftp->put($this->remote($name), 'Slice Backup connection test')) {
            throw new \RuntimeException(esc_html__('Connected, but writing a test file failed. Check that the user can write to the folder.', 'slice-backup'));
        }
        $found = in_array($name, (array) $sftp->nlist($this->dir), true);
        $sftp->delete($this->remote($name));
        if (!$found) {
            throw new \RuntimeException(esc_html__('The test file was written but could not be listed.', 'slice-backup'));
        }
        return sprintf(
            /* translators: 1: host, 2: fingerprint. */
            __('Connected to %1$s. Writing, listing and deleting files all work. Server fingerprint %2$s is now trusted.', 'slice-backup'),
            (string) $this->config['sftp_host'],
            $this->fingerprint
        );
    }

    public function listBundles(): array {
        $sftp  = $this->connect();
        $list  = $sftp->rawlist($this->dir);
        $items = array();
        foreach ((array) $list as $name => $attrs) {
            $name = (string) $name;
            if (!Storage::isBundleName($name) || !is_array($attrs) || (int) ($attrs['type'] ?? 1) !== 1) {
                continue;
            }
            $items[] = array('filename' => $name, 'size' => (float) ($attrs['size'] ?? 0), 'modified' => (int) ($attrs['mtime'] ?? 0));
        }
        return $items;
    }

    public function delete(string $filename): void {
        $sftp = $this->connect();
        if ($sftp->file_exists($this->remote($filename)) && !$sftp->delete($this->remote($filename))) {
            throw new \RuntimeException(esc_html__('The file could not be deleted on the SFTP server.', 'slice-backup'));
        }
    }

    public function uploadStep(string $path, string $filename, array &$state, float $deadline): bool {
        $size = Storage::filesize($path);
        $part = $this->remote($filename . '.part');

        if (empty($state['started'])) {
            $this->ensureDir($this->connect());
            $state = array('started' => true, 'offset' => 0.0, 'part' => $part);
        } else {
            // Trust what actually arrived on the server (the last request may have died after writing).
            $remoteSize = $this->run(
                static function ($sftp) use ($part) {
                    return $sftp->filesize($part);
                }
            );
            if (is_numeric($remoteSize)) {
                $state['offset'] = min((float) $remoteSize, $size);
            }
        }

        $handle = fopen($path, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$handle) {
            throw new \RuntimeException(esc_html__('Could not read the bundle for uploading.', 'slice-backup'));
        }
        try {
            $first = true;
            while (((float) $state['offset'] < $size || $size === 0.0) && ($first || microtime(true) < $deadline)) {
                $first  = false;
                $offset = (float) $state['offset'];
                Crypto::seek($handle, $offset);
                $data = (string) fread($handle, self::CHUNK); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                $ok   = $this->run(
                    static function ($sftp) use ($part, $data, $offset) {
                        // Offset 0 truncates any stale partial file; later pieces write in place.
                        return $offset > 0
                            ? $sftp->put($part, $data, \phpseclib3\Net\SFTP::SOURCE_STRING, (int) $offset)
                            : $sftp->put($part, $data, \phpseclib3\Net\SFTP::SOURCE_STRING);
                    }
                );
                if (!$ok) {
                    throw new \RuntimeException(esc_html__('Writing to the SFTP server failed. The disk may be full or the connection dropped.', 'slice-backup'));
                }
                $state['offset'] = $offset + strlen($data);
                if ($size === 0.0) {
                    break;
                }
            }
        } finally {
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }

        if ((float) $state['offset'] < $size) {
            return false;
        }

        $final = $this->remote($filename);
        $this->run(
            static function ($sftp) use ($part, $final): bool {
                if ($sftp->file_exists($final)) {
                    $sftp->delete($final);
                }
                if (!$sftp->rename($part, $final)) {
                    throw new \RuntimeException(esc_html__('The upload finished but the file could not be renamed on the SFTP server.', 'slice-backup'));
                }
                return true;
            }
        );
        return true;
    }

    public function abortUpload(array $state): void {
        if (!empty($state['part'])) {
            try {
                $this->connect()->delete((string) $state['part']);
            } catch (\Throwable $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                // Best effort.
            }
        }
    }

    public function downloadStep(string $filename, string $target, array &$state, float $deadline): bool {
        $remote = $this->remote($filename);
        if (!isset($state['size'])) {
            $size = $this->run(
                static function ($sftp) use ($remote) {
                    return $sftp->filesize($remote);
                }
            );
            if (!is_numeric($size)) {
                /* translators: %s: file name. */
                throw new \RuntimeException(esc_html(sprintf(__('%s was not found on the SFTP server.', 'slice-backup'), $filename)));
            }
            file_put_contents($target, ''); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            $state = array('size' => (float) $size, 'offset' => 0.0);
        }

        $first = true;
        while ((float) $state['offset'] < (float) $state['size'] && ($first || microtime(true) < $deadline)) {
            $first  = false;
            $offset = (int) $state['offset'];
            $data   = $this->run(
                static function ($sftp) use ($remote, $offset) {
                    return $sftp->get($remote, false, $offset, self::CHUNK);
                }
            );
            if (!is_string($data) || $data === '') {
                throw new \RuntimeException(esc_html__('Reading from the SFTP server failed.', 'slice-backup'));
            }
            Cloud::appendChunk($target, $data);
            $state['offset'] = (float) $offset + strlen($data);
        }
        return (float) $state['offset'] >= (float) $state['size'];
    }
}
