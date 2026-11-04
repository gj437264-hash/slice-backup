<?php
/**
 * Bundle storage: the protected folder, file naming, listing, locking and downloads.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the folder where bundles and temporary files live.
 */
final class Storage {
    public const DIR_NAME = 'slice-backup-temp';

    private const LOCK_OPTION = 'slice_backup_lock';
    private const LOCK_TTL    = 7200;

    /** @var string|null */
    private static $dir = null;

    /**
     * Absolute path of the storage folder. Creates and protects it on first use.
     *
     * @throws \RuntimeException When the folder cannot be created.
     */
    public static function dir(): string {
        if (self::$dir !== null) {
            return self::$dir;
        }

        $uploads = self::mainSiteUploads();
        $dir     = wp_normalize_path(trailingslashit((string) $uploads['basedir']) . self::DIR_NAME);

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new \RuntimeException(
                esc_html(sprintf(
                    /* translators: %s: folder path. */
                    __('Could not create the storage folder %s. Make sure wp-content/uploads is writable.', 'slice-backup'),
                    $dir
                ))
            );
        }

        self::protect($dir);
        self::$dir = $dir;
        return $dir;
    }

    /**
     * Public URL of the storage folder (used only to test that it is NOT reachable).
     */
    public static function url(): string {
        $uploads = self::mainSiteUploads();
        return trailingslashit((string) $uploads['baseurl']) . self::DIR_NAME;
    }

    /**
     * Upload dir info of the main site, so every network site shares one storage folder.
     *
     * @return array<string, mixed>
     */
    private static function mainSiteUploads(): array {
        $switched = false;
        if (is_multisite() && !is_main_site()) {
            switch_to_blog(get_main_site_id());
            $switched = true;
        }
        $uploads = wp_upload_dir(null, false);
        if ($switched) {
            restore_current_blog();
        }
        return $uploads;
    }

    /**
     * Writes deny-all rules for Apache and IIS plus empty index files.
     * Nginx ignores these files; the System tab tests and explains that case.
     */
    private static function protect(string $dir): void {
        $files = array(
            '.htaccess'  => "# Slice Backup: deny all web access to backups.\n"
                . "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<security>\n\t\t\t<requestFiltering>\n\t\t\t\t<fileExtensions allowUnlisted=\"false\" />\n\t\t\t</requestFiltering>\n\t\t</security>\n\t</system.webServer>\n</configuration>\n",
            'index.php'  => "<?php\n// Silence is golden.\n",
            'index.html' => '',
        );

        foreach ($files as $name => $content) {
            $path = $dir . '/' . $name;
            $current = is_file($path) ? (string) file_get_contents($path) : null;
            if ($current === null || ($name === '.htaccess' && strpos($current, 'Require all denied') === false)) {
                file_put_contents($path, $content);
            }
        }
    }

    /**
     * Whether a file name is an acceptable bundle name (no paths, known extension).
     */
    public static function isBundleName(string $name): bool {
        return $name !== ''
            && $name === sanitize_file_name($name)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(zip|slicebk)$/i', $name) === 1;
    }

    /**
     * Resolves a bundle name to its absolute path.
     *
     * @throws \InvalidArgumentException When the name is invalid.
     * @throws \RuntimeException When the file does not exist.
     */
    public static function path(string $name): string {
        if (!self::isBundleName($name)) {
            throw new \InvalidArgumentException(esc_html__('Invalid bundle file name.', 'slice-backup'));
        }
        $path = self::dir() . '/' . $name;
        if (!is_file($path)) {
            throw new \RuntimeException(
                esc_html(/* translators: %s: file name. */
                sprintf(__('Bundle not found: %s', 'slice-backup'), $name))
            );
        }
        return $path;
    }

    /**
     * A new, hard-to-guess bundle file name, e.g. example-com-full-20260101-120000-k3j9x0a1b2c4.zip.
     */
    public static function newBundleName(string $type, bool $scheduled = false): string {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $slug = sanitize_title($host !== '' ? $host : 'site');
        $type = sanitize_key($type);

        return sprintf(
            '%s-%s%s-%s-%s.zip',
            $slug,
            $scheduled ? 'auto-' : '',
            $type !== '' ? $type : 'bundle',
            gmdate('Ymd-His'),
            strtolower(wp_generate_password(12, false))
        );
    }

    /**
     * Absolute path for a new temporary file inside the storage folder.
     */
    public static function tempFile(string $prefix, string $extension): string {
        return self::dir() . '/' . $prefix . '-' . strtolower(wp_generate_password(20, false)) . '.' . $extension;
    }

    /**
     * Lists stored bundles, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listBundles(): array {
        $dir   = self::dir();
        $items = array();

        foreach ((array) scandir($dir) as $file) {
            if (!is_string($file) || !self::isBundleName($file)) {
                continue;
            }
            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }

            $size  = self::filesize($path);
            $mtime = (int) filemtime($path);

            $items[] = array(
                'filename'      => $file,
                'sizeBytes'     => $size,
                'formattedSize' => size_format($size, 1),
                'created'       => $mtime,
                'createdDate'   => wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $mtime),
                'downloadUrl'   => self::downloadUrl($file),
                'encrypted'     => Crypto::isEncryptedFile($path),
                'scheduled'     => strpos($file, '-auto-') !== false,
                'manifest'      => self::summarizeManifest(self::readManifest($path)),
            );
        }

        usort(
            $items,
            static function (array $a, array $b): int {
                return $b['created'] <=> $a['created'];
            }
        );

        return $items;
    }

    /**
     * Reads bundle.json from an archive without extracting it.
     *
     * @return array<string, mixed>|null
     */
    public static function readManifest(string $path): ?array {
        if (!class_exists('ZipArchive')) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $json = $zip->getFromName('bundle.json');
        $zip->close();

        if (!is_string($json)) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Normalises a manifest into the small shape the UI needs.
     *
     * @param array<string, mixed>|null $manifest
     * @return array<string, mixed>|null
     */
    public static function summarizeManifest(?array $manifest): ?array {
        if ($manifest === null) {
            return null;
        }

        $map = array(
            'database'   => 'db',
            'uploads'    => 'uploads',
            'plugins'    => 'plugins',
            'themes'     => 'themes',
            'mu_plugins' => 'mu_plugins',
            'languages'  => 'languages',
            'root_files' => 'root_files',
            'wp_core'    => 'wp_core',
        );

        $components = array();
        $raw        = isset($manifest['components']) && is_array($manifest['components']) ? $manifest['components'] : array();
        foreach ($map as $manifestKey => $key) {
            if (!empty($raw[$manifestKey])) {
                $components[] = $key;
            }
        }

        $multisite = isset($manifest['multisite']) && is_array($manifest['multisite']) ? $manifest['multisite'] : array();

        return array(
            'siteurl'       => isset($manifest['siteurl']) ? esc_url_raw((string) $manifest['siteurl']) : '',
            'home'          => isset($manifest['home']) ? esc_url_raw((string) $manifest['home']) : '',
            'wpVersion'     => isset($manifest['wp_version']) ? sanitize_text_field((string) $manifest['wp_version']) : '',
            'phpVersion'    => isset($manifest['php_version']) ? sanitize_text_field((string) $manifest['php_version']) : '',
            'pluginVersion' => isset($manifest['plugin_version']) ? sanitize_text_field((string) $manifest['plugin_version']) : (isset($manifest['version']) ? sanitize_text_field((string) $manifest['version']) : ''),
            'createdAt'     => isset($manifest['created_at']) ? sanitize_text_field((string) $manifest['created_at']) : '',
            'tablePrefix'   => isset($manifest['table_prefix']) ? sanitize_key((string) $manifest['table_prefix']) : '',
            'components'    => $components,
            'isMultisite'   => !empty($multisite['is_multisite']),
            'multisiteMode' => isset($multisite['multisite_mode']) ? sanitize_key((string) $multisite['multisite_mode']) : 'entire_network',
            'subsiteId'     => isset($multisite['subsite_id']) ? (int) $multisite['subsite_id'] : 0,
            'standalone'    => !empty($multisite['convert_subsite_standalone']),
            'networkDomain' => isset($multisite['network_domain']) ? sanitize_text_field((string) $multisite['network_domain']) : '',
        );
    }

    /**
     * Deletes a bundle.
     */
    public static function delete(string $name): bool {
        $path = self::path($name);
        wp_delete_file($path);
        return !file_exists($path);
    }

    /**
     * Keeps only the newest $keep bundles that were exported from this site.
     * Bundles uploaded from other sites are never deleted automatically.
     *
     * @return string[] Deleted file names.
     */
    public static function applyRetention(int $keep, string $protect = ''): array {
        if ($keep <= 0) {
            return array();
        }

        $siteUrl = untrailingslashit((string) get_option('siteurl'));
        $own     = array();
        foreach (self::listBundles() as $bundle) {
            if ($bundle['scheduled']) {
                continue; // Scheduled backups follow the schedule's own retention rules.
            }
            $manifest = $bundle['manifest'];
            if ($bundle['encrypted'] && self::isOwnName($bundle['filename'])) {
                $own[] = $bundle['filename'];
            } elseif (is_array($manifest) && untrailingslashit((string) $manifest['siteurl']) === $siteUrl) {
                $own[] = $bundle['filename'];
            }
        }

        $deleted = array();
        foreach (array_slice($own, $keep) as $file) {
            if ($file !== $protect && self::delete($file)) {
                $deleted[] = $file;
            }
        }
        return $deleted;
    }

    /**
     * Appends one piece of an incoming upload (browser or another site) and, when the
     * last piece arrives, validates the bundle and stores it.
     *
     * @param string|null $tmpFile Uploaded temp file, or null to use $data.
     * @return array{complete: bool, received?: float, filename?: string, expected?: float}
     *         'expected' is set when the offset does not match (the caller should resume from there).
     * @throws \InvalidArgumentException|\RuntimeException On invalid input or I/O errors.
     */
    public static function receiveChunk(string $uploadId, float $offset, float $total, string $name, ?string $tmpFile, string $data = ''): array {
        $uploadId = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $uploadId));
        $name     = sanitize_file_name($name);
        if (strlen($uploadId) < 16 || strlen($uploadId) > 64) {
            throw new \InvalidArgumentException(esc_html__('Invalid upload ID.', 'slice-backup'));
        }
        if (!preg_match('/\.(zip|slicebk)$/i', $name)) {
            throw new \InvalidArgumentException(esc_html__('Only .zip and .slicebk bundles can be uploaded.', 'slice-backup'));
        }
        if ($total <= 0) {
            throw new \InvalidArgumentException(esc_html__('The file is empty.', 'slice-backup'));
        }

        $part    = self::dir() . '/' . $uploadId . '.part';
        $current = is_file($part) ? self::filesize($part) : 0.0;
        if (abs($offset - $current) > 0.5) {
            return array('complete' => false, 'expected' => $current);
        }

        if ($tmpFile !== null) {
            $in  = fopen($tmpFile, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            $out = fopen($part, 'ab');    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            $ok  = $in && $out && stream_copy_to_stream($in, $out) !== false;
            if ($in) {
                fclose($in); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            }
            if ($out) {
                fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            }
        } else {
            $ok = file_put_contents($part, $data, FILE_APPEND) !== false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }
        if (!$ok) {
            throw new \RuntimeException(esc_html__('Could not write the uploaded data. Check free disk space.', 'slice-backup'));
        }

        clearstatcache(true, $part);
        $received = self::filesize($part);
        if ($received < $total) {
            return array('complete' => false, 'received' => $received);
        }
        if ($received > $total) {
            wp_delete_file($part);
            throw new \RuntimeException(esc_html__('The uploaded file is larger than expected. Please upload it again.', 'slice-backup'));
        }

        if (Crypto::isEncryptedFile($part)) {
            $name = (string) preg_replace('/\.zip$/i', '.slicebk', $name);
        } else {
            if (!class_exists('ZipArchive')) {
                wp_delete_file($part);
                throw new \RuntimeException(esc_html__('The PHP zip extension (ZipArchive) is required. Ask your host to enable it.', 'slice-backup'));
            }
            $zip = new \ZipArchive();
            if ($zip->open($part) !== true) {
                wp_delete_file($part);
                throw new \InvalidArgumentException(esc_html__('The uploaded file is not a valid zip archive.', 'slice-backup'));
            }
            $hasContent = $zip->locateName('bundle.json') !== false || $zip->locateName('database.sql') !== false;
            $zip->close();
            if (!$hasContent) {
                wp_delete_file($part);
                throw new \InvalidArgumentException(esc_html__('This zip file is not a Slice Backup bundle (bundle.json is missing).', 'slice-backup'));
            }
            $name = (string) preg_replace('/\.slicebk$/i', '.zip', $name);
        }

        $final = wp_unique_filename(self::dir(), $name);
        if (!rename($part, self::dir() . '/' . $final)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            throw new \RuntimeException(esc_html__('Could not save the uploaded bundle.', 'slice-backup'));
        }
        return array('complete' => true, 'filename' => $final);
    }

    /**
     * Reads a byte range of a stored bundle (used when another site pulls a bundle).
     *
     * @throws \RuntimeException When the file cannot be read.
     */
    public static function readRange(string $name, float $offset, int $length): string {
        $path   = self::path($name);
        $handle = fopen($path, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$handle) {
            throw new \RuntimeException(esc_html__('Could not read the bundle.', 'slice-backup'));
        }
        Crypto::seek($handle, $offset);
        $data = (string) fread($handle, max(1, min($length, 16777216))); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        return $data;
    }

    /**
     * Whether a bundle name was generated by this site (encrypted bundles have no readable manifest).
     */
    private static function isOwnName(string $name): bool {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $slug = sanitize_title($host !== '' ? $host : 'site');
        return (bool) preg_match('/^' . preg_quote($slug, '/') . '-[a-z0-9-]+-\d{8}-\d{6}-[a-z0-9]{12}\.(zip|slicebk)$/', $name);
    }

    /**
     * Removes leftovers from interrupted runs. Call only while holding the lock.
     */
    public static function cleanupStale(): void {
        $dir = self::dir();
        $now = time();

        foreach ((array) scandir($dir) as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;

            if (strpos($entry, 'extract_') === 0 && is_dir($path)) {
                self::removeDirectory($path);
            } elseif (preg_match('/^database-[a-z0-9]+\.sql$/', $entry) && is_file($path)) {
                wp_delete_file($path);
            } elseif (substr($entry, -5) === '.part' && is_file($path) && ($now - (int) filemtime($path)) > DAY_IN_SECONDS) {
                wp_delete_file($path);
            }
        }
    }

    /**
     * Recursively removes a directory.
     */
    public static function removeDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        self::filesystem()->rmdir($dir, true);
    }

    /**
     * Direct-access WordPress filesystem for the plugin's own working folder.
     *
     * The working folder is created by PHP itself, so direct access is always
     * the correct method; no credentials prompt is ever needed.
     */
    public static function filesystem(): \WP_Filesystem_Direct {
        static $fs = null;
        if (null === $fs) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            $fs = new \WP_Filesystem_Direct(null);
        }
        return $fs;
    }

    /**
     * Prevents two exports/restores from running at the same time.
     *
     * @throws \RuntimeException When another operation holds the lock.
     */
    public static function acquireLock(string $operation): void {
        $existing = get_site_option(self::LOCK_OPTION);
        if (is_array($existing) && (time() - (int) ($existing['time'] ?? 0)) > self::LOCK_TTL) {
            delete_site_option(self::LOCK_OPTION);
        }

        if (!add_site_option(self::LOCK_OPTION, array('op' => $operation, 'time' => time()))) {
            throw new \RuntimeException(esc_html__('Another backup or restore is already running. Wait for it to finish, then try again.', 'slice-backup'));
        }
    }

    /**
     * Releases the operation lock.
     */
    public static function releaseLock(): void {
        delete_site_option(self::LOCK_OPTION);
    }

    /**
     * Nonce-protected admin-ajax download URL for a bundle.
     */
    public static function downloadUrl(string $name): string {
        return add_query_arg(
            array(
                'action' => 'slice_backup_download',
                'file'   => rawurlencode($name),
                'nonce'  => wp_create_nonce('slice_backup_download'),
            ),
            admin_url('admin-ajax.php')
        );
    }

    /**
     * File size that stays correct above 2 GB on 32-bit PHP builds.
     */
    public static function filesize(string $path): float {
        if (!is_file($path)) {
            return 0.0;
        }

        $size = 0.0;
        $raw  = @filesize($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ($raw !== false && $raw >= 0) {
            $size = (float) sprintf('%u', $raw);
        }

        $fp = @fopen($path, 'rb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ($fp) {
            if (fseek($fp, 0, SEEK_END) === 0) {
                $tell = ftell($fp);
                if ($tell !== false && $tell > 0) {
                    $size = max($size, (float) sprintf('%u', $tell));
                }
            }
            fclose($fp); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }

        return $size;
    }

    /**
     * Streams a file to the browser in 2 MB chunks with HTTP Range (resume) support.
     * Ends the request.
     */
    public static function streamFile(string $filePath): void {
        if (!is_file($filePath) || !is_readable($filePath)) {
            wp_die(esc_html__('The requested bundle does not exist or cannot be read.', 'slice-backup'), '', array('response' => 404));
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running step; each step is still time-budgeted.
        }
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        @ini_set('zlib.output_compression', 'Off'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- compression would corrupt the binary download.

        // Output buffers would hold the whole file in memory.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $fileSize = self::filesize($filePath);
        $fp       = fopen($filePath, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$fp) {
            wp_die(esc_html__('Unable to open the bundle for reading.', 'slice-backup'), '', array('response' => 500));
        }

        $start   = 0.0;
        $end     = $fileSize > 0.0 ? $fileSize - 1.0 : 0.0;
        $partial = false;

        $range = isset($_SERVER['HTTP_RANGE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_RANGE'])) : '';
        if ($range !== '' && preg_match('/bytes=\s*(\d*)-(\d*)/i', $range, $m)) {
            if ($m[1] !== '') {
                $start = (float) $m[1];
                if ($m[2] !== '') {
                    $end = min((float) $m[2], $fileSize - 1.0);
                }
            } elseif ($m[2] !== '') {
                $start = max(0.0, $fileSize - (float) $m[2]);
            }

            if ($start > $end || $start >= $fileSize) {
                status_header(416);
                header('Content-Range: bytes */' . sprintf('%.0f', $fileSize));
                fclose($fp); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                exit;
            }
            $partial = true;
        }

        $length = max(0.0, $end - $start + 1.0);

        nocache_headers();
        header('X-Accel-Buffering: no');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');
        if ($partial) {
            status_header(206);
            header('Content-Range: bytes ' . sprintf('%.0f-%.0f/%.0f', $start, $end, $fileSize));
        } else {
            status_header(200);
        }
        header('Content-Length: ' . sprintf('%.0f', $length));

        if ($start > 0.0) {
            // Seek in 1 GB steps so offsets above 2 GB work on 32-bit builds.
            $remaining = $start;
            while ($remaining > 0.0) {
                $step = (int) min($remaining, 1073741824.0);
                fseek($fp, $step, SEEK_CUR);
                $remaining -= $step;
            }
        }

        $chunk = 2097152;
        $left  = $length;
        while ($left > 0.0 && !feof($fp)) {
            if (connection_status() !== CONNECTION_NORMAL) {
                break;
            }
            $buffer = fread($fp, (int) min((float) $chunk, $left)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            if ($buffer === false || $buffer === '') {
                break;
            }
            echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file download.
            flush();
            $left -= strlen($buffer);
        }

        fclose($fp); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }
}
