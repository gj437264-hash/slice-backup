<?php
/**
 * Path jail that prevents "Zip Slip" directory traversal (CWE-22) during extraction.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Archive path validation.
 */
final class SecuritySanitizer {
    /**
     * Resolves an archive entry name inside $baseDirectory, or throws if it would escape it.
     *
     * @throws \RuntimeException When the entry is unsafe.
     */
    public static function assertPathInJail(string $relativePath, string $baseDirectory): string {
        if (strpos($relativePath, "\0") !== false) {
            throw new \RuntimeException(sprintf('Null byte in archive entry: %s', $relativePath));
        }

        $clean = str_replace('\\', '/', $relativePath);

        // Absolute paths and Windows drive letters / stream wrappers are never valid entries.
        if (preg_match('#^(/|[A-Za-z]:|[A-Za-z][A-Za-z0-9+.-]*://)#', $clean)) {
            throw new \RuntimeException(sprintf('Absolute path in archive entry: %s', $relativePath));
        }

        $parts = array();
        foreach (explode('/', $clean) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new \RuntimeException(sprintf('Directory traversal in archive entry: %s', $relativePath));
            }
            $parts[] = $part;
        }

        $base   = rtrim(wp_normalize_path((string) (realpath($baseDirectory) ?: $baseDirectory)), '/');
        $target = $base . '/' . implode('/', $parts);

        $isWindows   = DIRECTORY_SEPARATOR === '\\';
        $haystack    = $isWindows ? strtolower($target) : $target;
        $needle      = $isWindows ? strtolower($base . '/') : $base . '/';
        if (strpos($haystack . '/', $needle) !== 0) {
            throw new \RuntimeException(sprintf('Archive entry escapes the extraction folder: %s', $relativePath));
        }

        return $target;
    }
}
