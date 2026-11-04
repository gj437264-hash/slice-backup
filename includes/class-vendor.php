<?php
/**
 * Loads bundled third-party libraries only when they are needed.
 *
 * vendor/phpseclib               phpseclib 3.0.58 (MIT) - SSH/SFTP.
 * vendor/constant_time_encoding  paragonie/constant_time_encoding 2.8.2 (MIT) - phpseclib dependency.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lazy autoloader for bundled libraries.
 */
final class Vendor {
    /** @var bool */
    private static $registered = false;

    /**
     * Registers PSR-4 autoloading for phpseclib. If another plugin already loaded
     * phpseclib 3, its copy is used and ours is never included.
     *
     * @throws \RuntimeException When the library cannot be loaded.
     */
    public static function phpseclib(): void {
        if (self::$registered || class_exists('\phpseclib3\Net\SFTP')) {
            return;
        }
        self::$registered = true;

        $map = array(
            'phpseclib3\\'            => SLICE_BACKUP_PATH . 'vendor/phpseclib/',
            'ParagonIE\\ConstantTime\\' => SLICE_BACKUP_PATH . 'vendor/constant_time_encoding/src/',
        );
        spl_autoload_register(
            static function (string $class) use ($map): void {
                foreach ($map as $prefix => $dir) {
                    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
                        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                        if (is_file($file)) {
                            require_once $file;
                        }
                        return;
                    }
                }
            }
        );

        try {
            require_once SLICE_BACKUP_PATH . 'vendor/phpseclib/bootstrap.php';
        } catch (\Throwable $e) {
            throw new \RuntimeException(__('SFTP is not available on this server: ', 'slice-backup') . $e->getMessage());
        }
    }
}
