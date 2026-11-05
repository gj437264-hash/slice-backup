<?php
/**
 * Builds bundle archives in resumable steps:
 *  1. scan:    walk the selected folders and write a file list to disk;
 *  2. archive: add files from the list to the zip, a batch per request.
 * Memory stays flat because neither step holds the whole file list in memory.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bundle archive builder.
 */
class StreamingArchiver {
    /** Already-compressed formats are stored as-is, which is much faster. */
    private const STORE_EXTENSIONS = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'zip', 'gz', 'tgz', 'bz2', 'xz', 'rar', '7z', 'mp3', 'm4a', 'ogg', 'mp4', 'm4v', 'mov', 'webm', 'pdf', 'woff', 'woff2');

    /** Files per zip flush: libzip keeps queued files open until close(). */
    private const FILES_PER_BATCH = 500;

    /** Root-level files carried by the "server config files" component. */
    public const ROOT_FILES_PATTERN = '/^(wp-config\.php|\.htaccess|robots\.txt|web\.config|favicon\.ico|ads\.txt|app-ads\.txt|google[a-z0-9]+\.html|BingSiteAuth\.xml|sitemap[A-Za-z0-9_-]*\.xml)$/i';

    /**
     * Builds the bundle.json manifest.
     *
     * @param array<string, bool>  $components
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function manifest(array $components, array $context, bool $hasDatabase, string $origin): array {
        global $wpdb;

        $mode       = $context['multisite_mode'] ?? 'entire_network';
        $subsiteId  = (int) ($context['subsite_id'] ?? 0);
        $standalone = !empty($context['convert_subsite_standalone']);
        $isSubsite  = is_multisite() && $mode === 'subsite' && $subsiteId > 0;

        return array(
            'format'         => 'slice-backup-v1',
            'plugin_version' => SLICE_BACKUP_VERSION,
            'version'        => SLICE_BACKUP_VERSION,
            'wp_version'     => get_bloginfo('version'),
            'php_version'    => PHP_VERSION,
            'mysql_version'  => $wpdb->db_version(),
            'siteurl'        => $isSubsite ? get_site_url($subsiteId) : (string) get_option('siteurl'),
            'home'           => $isSubsite ? get_home_url($subsiteId) : (string) get_option('home'),
            'table_prefix'   => (string) $wpdb->base_prefix,
            'created_at'     => gmdate('c'),
            'origin'         => $origin,
            'components'     => array(
                'database'   => $hasDatabase,
                'uploads'    => !empty($components['uploads']),
                'plugins'    => !empty($components['plugins']),
                'themes'     => !empty($components['themes']),
                'mu_plugins' => !empty($components['mu_plugins']),
                'languages'  => !empty($components['languages']),
                'root_files' => !empty($components['root_files']),
                'wp_core'    => !empty($components['wp_core']),
            ),
            'multisite'      => array(
                'is_multisite'               => is_multisite() && !($isSubsite && $standalone),
                'multisite_mode'             => $isSubsite ? 'subsite' : 'entire_network',
                'subsite_id'                 => $isSubsite ? $subsiteId : null,
                'convert_subsite_standalone' => $isSubsite && $standalone,
                'network_domain'             => is_multisite() && defined('DOMAIN_CURRENT_SITE') ? (string) DOMAIN_CURRENT_SITE : '',
            ),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Step 1: scan                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, bool>  $components
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function beginScan(array $components, array $context, string $listFile): array {
        $mode       = $context['multisite_mode'] ?? 'entire_network';
        $subsiteId  = (int) ($context['subsite_id'] ?? 0);
        $standalone = !empty($context['convert_subsite_standalone']);
        $isSubsite  = is_multisite() && $mode === 'subsite' && $subsiteId > 0;

        $uploads   = wp_upload_dir(null, false);
        $uploadDir = rtrim(wp_normalize_path((string) $uploads['basedir']), '/');
        $selfDir   = rtrim(wp_normalize_path(SLICE_BACKUP_PATH), '/');
        $storage   = Storage::dir();

        $queue    = array();
        $excludes = array();
        $add      = static function (string $dir, string $prefix, string $component, array $exclude) use (&$queue, &$excludes): void {
            $dir = rtrim(wp_normalize_path($dir), '/');
            if ($dir === '' || !is_dir($dir) || !is_readable($dir)) {
                return;
            }
            /** Filters absolute paths left out of a bundle component. */
            $exclude = (array) apply_filters('slice_backup_export_exclude_paths', $exclude, $component);
            $excludes[$component] = array_values(array_unique(array_merge(
                $excludes[$component] ?? array(),
                array_map(
                    static function ($p): string {
                        return rtrim(wp_normalize_path((string) $p), '/');
                    },
                    $exclude
                )
            )));
            $queue[] = array($dir, $prefix, $component);
        };

        if (!empty($components['uploads'])) {
            if ($isSubsite && $subsiteId > 1) {
                $siteDir = $uploadDir . '/sites/' . $subsiteId;
                $legacy  = wp_normalize_path(WP_CONTENT_DIR . '/blogs.dir/' . $subsiteId . '/files');
                $add(is_dir($siteDir) ? $siteDir : $legacy, $standalone ? 'uploads' : 'uploads/sites/' . $subsiteId, 'uploads', array($storage));
            } elseif ($isSubsite) {
                $add($uploadDir, 'uploads', 'uploads', array($storage, $uploadDir . '/sites'));
            } else {
                $add($uploadDir, 'uploads', 'uploads', array($storage));
            }
        }
        if (!empty($components['themes'])) {
            $add(get_theme_root(), 'themes', 'themes', array());
        }
        if (!empty($components['plugins'])) {
            $add(WP_PLUGIN_DIR, 'plugins', 'plugins', array($selfDir));
        }
        if (!empty($components['mu_plugins'])) {
            $add(defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins', 'mu-plugins', 'mu_plugins', array(RestoreGuard::path(), RestoreGuard::configPath()));
        }
        if (!empty($components['languages'])) {
            $add(defined('WP_LANG_DIR') ? WP_LANG_DIR : WP_CONTENT_DIR . '/languages', 'languages', 'languages', array());
        }
        if (!empty($components['wp_core'])) {
            $add(ABSPATH . 'wp-admin', 'core/wp-admin', 'wp_core', array());
            $add(ABSPATH . WPINC, 'core/' . WPINC, 'wp_core', array());
        }

        // Single root-level files go straight into the list.
        $lines = '';
        $files = 0;
        $bytes = 0.0;
        foreach ((array) scandir(ABSPATH) as $file) {
            if (!is_string($file) || !is_file(ABSPATH . $file) || !is_readable(ABSPATH . $file)) {
                continue;
            }
            if (!empty($components['root_files']) && preg_match(self::ROOT_FILES_PATTERN, $file)) {
                $lines .= self::line('root/' . $file, ABSPATH . $file);
                $files++;
                $bytes += (float) filesize(ABSPATH . $file);
            }
            if (!empty($components['wp_core']) && $file !== 'wp-config.php' && preg_match('/^(index|xmlrpc|wp-[a-z-]+)\.php$/', $file)) {
                $lines .= self::line('core/' . $file, ABSPATH . $file);
                $files++;
                $bytes += (float) filesize(ABSPATH . $file);
            }
        }
        file_put_contents($listFile, $lines); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

        return array(
            'list'     => $listFile,
            'queue'    => $queue,
            'excludes' => $excludes,
            'files'    => $files,
            'bytes'    => $bytes,
        );
    }

    /**
     * Walks folders from the queue until the deadline. Returns true when done.
     *
     * @param array<string, mixed> $state
     */
    public static function scanStep(array &$state, float $deadline): bool {
        $first = true;
        while (!empty($state['queue']) && ($first || microtime(true) < $deadline)) {
            $first = false;
            list($dir, $prefix, $component) = array_shift($state['queue']);
            $exclude = $state['excludes'][$component] ?? array();
            $entries = @scandir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if (!is_array($entries)) {
                continue;
            }

            $lines = 'D' . "\t" . $prefix . "\n";
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || strpbrk($entry, "\t\n\r") !== false) {
                    continue;
                }
                $path = $dir . '/' . $entry;
                foreach ($exclude as $ex) {
                    if ($path === $ex || strpos($path, $ex . '/') === 0) {
                        continue 2;
                    }
                }
                if (is_dir($path)) {
                    if (!is_link($path)) {
                        $state['queue'][] = array($path, $prefix . '/' . $entry, $component);
                    }
                } elseif (is_file($path) && is_readable($path)) {
                    $lines .= self::line($prefix . '/' . $entry, $path);
                    $state['files']++;
                    $state['bytes'] += (float) @filesize($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                }
            }
            file_put_contents((string) $state['list'], $lines, FILE_APPEND); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }
        return empty($state['queue']);
    }

    private static function line(string $zipPath, string $absPath): string {
        return 'F' . "\t" . $zipPath . "\t" . wp_normalize_path($absPath) . "\n";
    }

    /* ------------------------------------------------------------------ */
    /* Step 2: archive                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public static function beginArchive(string $listFile, ?string $sqlFile, array $manifest, string $archivePath, int $totalFiles, float $totalBytes): array {
        return array(
            'list'       => $listFile,
            'sql'        => $sqlFile,
            'manifest'   => $manifest,
            'archive'    => $archivePath,
            'pos'        => 0,
            'started'    => false,
            'added'      => 0,
            'addedBytes' => 0.0,
            'total'      => $totalFiles,
            'totalBytes' => $totalBytes,
        );
    }

    /**
     * Adds the next batch of files. Returns true when the archive is complete.
     *
     * @param array<string, mixed> $state
     * @throws \RuntimeException When the archive cannot be written.
     */
    public static function archiveStep(array &$state, float $deadline, float $byteBudget): bool {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException(esc_html__('The PHP zip extension (ZipArchive) is required. Ask your host to enable it.', 'slice-backup'));
        }

        $zip   = new \ZipArchive();
        $flags = empty($state['started']) ? \ZipArchive::CREATE | \ZipArchive::OVERWRITE : 0;
        if ($zip->open((string) $state['archive'], $flags) !== true) {
            throw new \RuntimeException(esc_html__('Could not open the bundle file for writing. Check free disk space and folder permissions.', 'slice-backup'));
        }

        if (empty($state['started'])) {
            $zip->addFromString('bundle.json', (string) wp_json_encode($state['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if (!empty($state['sql']) && is_file((string) $state['sql'])) {
                $zip->addFile((string) $state['sql'], 'database.sql');
            }
            $state['started'] = true;
        }

        $list = fopen((string) $state['list'], 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$list) {
            $zip->close();
            throw new \RuntimeException(esc_html__('The file list for the bundle is missing.', 'slice-backup'));
        }
        fseek($list, (int) $state['pos']);

        $batchFiles = 0;
        $batchBytes = 0.0;
        $eof        = false;
        while (true) {
            if ($batchFiles > 0 && ($batchFiles >= self::FILES_PER_BATCH || $batchBytes >= $byteBudget || microtime(true) >= $deadline)) {
                break;
            }
            $line = fgets($list);
            if ($line === false) {
                $eof = true;
                break;
            }
            $state['pos'] = ftell($list);
            $parts        = explode("\t", rtrim($line, "\n"));

            if ($parts[0] === 'D' && isset($parts[1])) {
                $zip->addEmptyDir($parts[1]);
            } elseif ($parts[0] === 'F' && isset($parts[1], $parts[2]) && is_readable($parts[2])) {
                if ($zip->addFile($parts[2], $parts[1])) {
                    $extension = strtolower(pathinfo($parts[1], PATHINFO_EXTENSION));
                    if (in_array($extension, self::STORE_EXTENSIONS, true) && method_exists($zip, 'setCompressionName')) {
                        $zip->setCompressionName($parts[1], \ZipArchive::CM_STORE);
                    }
                    $size        = (float) @filesize($parts[2]); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                    $batchBytes += $size;
                    $batchFiles++;
                    $state['added']++;
                    $state['addedBytes'] += $size;
                }
            }
        }
        fclose($list); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        if (!$zip->close()) {
            throw new \RuntimeException(esc_html__('Could not write to the bundle. The disk may be full.', 'slice-backup'));
        }

        return $eof;
    }
}
