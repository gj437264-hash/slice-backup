<?php
/**
 * AES-256-GCM encryption for bundles and for secrets stored in the database.
 *
 * Bundle format (".slicebk"):
 *   "SLICEBK1" | uint32 header length | header JSON | chunk*
 *   chunk = uint32 ciphertext length | ciphertext | 16-byte GCM tag
 * Each chunk encrypts up to 1 MiB with nonce = 8-byte random prefix || uint32 chunk index,
 * and authenticates (header || chunk index || final flag) as additional data, so chunks
 * cannot be reordered, dropped or the file truncated without detection.
 * The key is derived from the passphrase with PBKDF2-SHA256.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Encryption helpers.
 */
final class Crypto {
    public const MAGIC        = 'SLICEBK1';
    private const CIPHER      = 'aes-256-gcm';
    private const CHUNK       = 1048576;
    private const ITERATIONS  = 310000;
    private const META_INDEX  = 0xFFFFFFFF;

    public static function available(): bool {
        return function_exists('openssl_encrypt')
            && in_array(self::CIPHER, array_map('strtolower', (array) openssl_get_cipher_methods()), true);
    }

    /**
     * @throws \RuntimeException When OpenSSL is missing.
     */
    private static function requireAvailable(): void {
        if (!self::available()) {
            throw new \RuntimeException(__('Encryption needs the PHP OpenSSL extension with AES-256-GCM. Ask your host to enable it.', 'slice-backup'));
        }
    }

    public static function isEncryptedFile(string $path): bool {
        $handle = @fopen($path, 'rb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$handle) {
            return false;
        }
        $magic = fread($handle, 8); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        return $magic === self::MAGIC;
    }

    /* ------------------------------------------------------------------ */
    /* Bundle encryption (resumable)                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Encrypts $source into $target, a piece at a time. Returns true when finished.
     *
     * @param array<string, mixed> $state    Resumable state, initially empty.
     * @param array<string, mixed> $manifest Stored encrypted in the header so details can be shown before restoring.
     * @throws \RuntimeException On I/O errors.
     */
    public static function encryptStep(string $source, string $target, string $passphrase, array $manifest, array &$state, float $deadline): bool {
        self::requireAvailable();
        if ($passphrase === '') {
            throw new \RuntimeException(__('An encryption passphrase is required.', 'slice-backup'));
        }

        $size = Storage::filesize($source);
        $key  = null;

        if (empty($state['header'])) {
            $salt   = random_bytes(16);
            $prefix = random_bytes(8);
            $key    = self::deriveKey($passphrase, $salt, self::ITERATIONS);

            $metaPlain = (string) wp_json_encode($manifest);
            $metaTag   = '';
            $metaCt    = openssl_encrypt($metaPlain, self::CIPHER, $key, OPENSSL_RAW_DATA, $prefix . pack('N', self::META_INDEX), $metaTag, 'meta');

            $header = (string) wp_json_encode(
                array(
                    'v'      => 1,
                    'cipher' => self::CIPHER,
                    'kdf'    => 'pbkdf2-sha256',
                    'iter'   => self::ITERATIONS,
                    'salt'   => base64_encode($salt), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
                    'prefix' => base64_encode($prefix), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
                    'chunk'  => self::CHUNK,
                    'meta'   => base64_encode((string) $metaCt . $metaTag), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
                )
            );

            file_put_contents($target, self::MAGIC . pack('N', strlen($header)) . $header); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            $state = array('header' => $header, 'offset' => 0.0, 'index' => 0);
        }

        $header = (string) $state['header'];
        $meta   = json_decode($header, true);
        $key    = $key ?? self::deriveKey($passphrase, (string) base64_decode((string) $meta['salt']), (int) $meta['iter']); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $prefix = (string) base64_decode((string) $meta['prefix']); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

        $in  = fopen($source, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $out = fopen($target, 'ab'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$in || !$out) {
            throw new \RuntimeException(__('Could not open the files for encryption.', 'slice-backup'));
        }
        self::seek($in, (float) $state['offset']);

        $done  = false;
        $first = true;
        while ($first || microtime(true) < $deadline) {
            $first = false;
            $plain = (string) fread($in, self::CHUNK); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $state['offset'] = (float) $state['offset'] + strlen($plain);
            $final = $state['offset'] >= $size;
            if ($plain === '' && !$final) {
                throw new \RuntimeException(__('The bundle changed while it was being encrypted.', 'slice-backup'));
            }
            $index = (int) $state['index'];
            $tag   = '';
            $ct    = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $prefix . pack('N', $index), $tag, $header . pack('N', $index) . ($final ? "\x01" : "\x00"));
            if ($ct === false) {
                throw new \RuntimeException(__('Encryption failed.', 'slice-backup'));
            }
            if (fwrite($out, pack('N', strlen($ct)) . $ct . $tag) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
                throw new \RuntimeException(__('Writing the encrypted bundle failed. The disk may be full.', 'slice-backup'));
            }
            $state['index'] = $index + 1;
            if ($final) {
                $done = true;
                break;
            }
        }

        fclose($in);  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        return $done;
    }

    /**
     * Decrypts $source into $target, a piece at a time. Returns true when finished.
     *
     * @param array<string, mixed> $state
     * @throws \RuntimeException On a wrong passphrase, tampering or truncation.
     */
    public static function decryptStep(string $source, string $target, string $passphrase, array &$state, float $deadline): bool {
        self::requireAvailable();

        $in = fopen($source, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$in) {
            throw new \RuntimeException(__('Could not open the encrypted bundle.', 'slice-backup'));
        }
        $info   = self::readHeader($in);
        $key    = self::deriveKey($passphrase, $info['salt'], $info['iter']);
        $size   = Storage::filesize($source);

        if (empty($state)) {
            file_put_contents($target, ''); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            $state = array('offset' => (float) $info['dataStart'], 'index' => 0);
        }

        $out = fopen($target, 'ab'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$out) {
            fclose($in); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            throw new \RuntimeException(__('Could not write the decrypted bundle.', 'slice-backup'));
        }
        self::seek($in, (float) $state['offset']);

        $done  = false;
        $first = true;
        try {
            while ($first || microtime(true) < $deadline) {
                $first  = false;
                $lenRaw = fread($in, 4); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                if (!is_string($lenRaw) || strlen($lenRaw) !== 4) {
                    throw new \RuntimeException(__('The encrypted bundle is truncated.', 'slice-backup'));
                }
                $len = (int) unpack('N', $lenRaw)[1];
                if ($len > self::CHUNK + 64) {
                    throw new \RuntimeException(__('The encrypted bundle is damaged.', 'slice-backup'));
                }
                $ct  = $len > 0 ? (string) fread($in, $len) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                $tag = (string) fread($in, 16); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                if (strlen($ct) !== $len || strlen($tag) !== 16) {
                    throw new \RuntimeException(__('The encrypted bundle is truncated.', 'slice-backup'));
                }

                $next  = (float) $state['offset'] + 4 + $len + 16;
                $final = $next >= $size;
                $index = (int) $state['index'];
                $plain = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $info['prefix'] . pack('N', $index), $tag, $info['header'] . pack('N', $index) . ($final ? "\x01" : "\x00"));
                if ($plain === false) {
                    throw new \RuntimeException(
                        $index === 0
                            ? __('Wrong passphrase, or the bundle was modified.', 'slice-backup')
                            : __('The encrypted bundle is damaged or was modified.', 'slice-backup')
                    );
                }
                fwrite($out, $plain); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
                $state['offset'] = $next;
                $state['index']  = $index + 1;
                if ($final) {
                    $done = true;
                    break;
                }
            }
        } finally {
            fclose($in);  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }

        return $done;
    }

    /**
     * Decrypts the manifest stored in the header (fast passphrase check).
     *
     * @return array<string, mixed>
     * @throws \RuntimeException On a wrong passphrase.
     */
    public static function readEncryptedManifest(string $path, string $passphrase): array {
        self::requireAvailable();
        $in = fopen($path, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$in) {
            throw new \RuntimeException(__('Could not open the encrypted bundle.', 'slice-backup'));
        }
        $info = self::readHeader($in);
        fclose($in); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        $key   = self::deriveKey($passphrase, $info['salt'], $info['iter']);
        $blob  = $info['meta'];
        $plain = strlen($blob) >= 16
            ? openssl_decrypt(substr($blob, 0, -16), self::CIPHER, $key, OPENSSL_RAW_DATA, $info['prefix'] . pack('N', self::META_INDEX), substr($blob, -16), 'meta')
            : false;
        if ($plain === false) {
            throw new \RuntimeException(__('Wrong passphrase.', 'slice-backup'));
        }
        $data = json_decode($plain, true);
        return is_array($data) ? $data : array();
    }

    /**
     * @param resource $handle
     * @return array{header: string, salt: string, prefix: string, iter: int, meta: string, dataStart: int}
     * @throws \RuntimeException When the header is invalid.
     */
    private static function readHeader($handle): array {
        $magic = fread($handle, 8); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $lenRaw = fread($handle, 4); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        if ($magic !== self::MAGIC || !is_string($lenRaw) || strlen($lenRaw) !== 4) {
            throw new \RuntimeException(__('This is not an encrypted Slice Backup bundle.', 'slice-backup'));
        }
        $len    = (int) unpack('N', $lenRaw)[1];
        $header = $len > 0 && $len < 65536 ? (string) fread($handle, $len) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $meta   = json_decode($header, true);
        if (!is_array($meta) || ($meta['cipher'] ?? '') !== self::CIPHER || (int) ($meta['v'] ?? 0) !== 1) {
            throw new \RuntimeException(__('Unsupported encrypted bundle format.', 'slice-backup'));
        }
        // phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        return array(
            'header'    => $header,
            'salt'      => (string) base64_decode((string) $meta['salt']),
            'prefix'    => (string) base64_decode((string) $meta['prefix']),
            'iter'      => max(100000, (int) $meta['iter']),
            'meta'      => (string) base64_decode((string) ($meta['meta'] ?? '')),
            'dataStart' => 12 + $len,
        );
        // phpcs:enable
    }

    private static function deriveKey(string $passphrase, string $salt, int $iterations): string {
        return hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);
    }

    /**
     * Seeks to a float offset in 1 GB steps (works above 2 GB on 32-bit PHP).
     *
     * @param resource $handle
     */
    public static function seek($handle, float $offset): void {
        fseek($handle, 0, SEEK_SET);
        while ($offset > 0) {
            $step = (int) min($offset, 1073741824.0);
            fseek($handle, $step, SEEK_CUR);
            $offset -= $step;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Secrets at rest (cloud keys, saved passphrase)                      */
    /* ------------------------------------------------------------------ */

    /**
     * Encrypts a secret with a key derived from this site's wp-config.php salts, so a
     * database dump alone does not reveal it. Returns '' for ''.
     */
    public static function seal(string $plain): string {
        if ($plain === '') {
            return '';
        }
        self::requireAvailable();
        $nonce = random_bytes(12);
        $tag   = '';
        $ct    = openssl_encrypt($plain, self::CIPHER, self::siteKey(), OPENSSL_RAW_DATA, $nonce, $tag, 'slicebk-secret');
        return 'v1:' . base64_encode($nonce . $tag . (string) $ct); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /**
     * Reverses seal(). Returns '' when the value cannot be decrypted (e.g. the salts changed).
     */
    public static function open(string $sealed): string {
        if (strpos($sealed, 'v1:') !== 0 || !self::available()) {
            return '';
        }
        $raw = base64_decode(substr($sealed, 3), true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        if (!is_string($raw) || strlen($raw) < 28) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, self::siteKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'slicebk-secret');
        return is_string($plain) ? $plain : '';
    }

    private static function siteKey(): string {
        return hash_hmac('sha256', 'slice-backup-secrets', wp_salt('auth') . wp_salt('secure_auth'), true);
    }
}
