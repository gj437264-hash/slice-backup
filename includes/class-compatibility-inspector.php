<?php
/**
 * Safe extraction plus a plugin/theme compatibility check for the destination server.
 *
 * @package WpBundleMigrator
 */

declare(strict_types=1);

namespace WpBundleMigrator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extracts archives defensively and finds plugins/themes that would crash on this PHP version.
 *
 * Severity model:
 *  - conflict: would stop the site from loading (requires a newer PHP, or uses syntax that
 *    PHP 8 refuses to compile). Plugins are deactivated; an affected active theme is switched.
 *  - warning:  calls a function removed in PHP 8 (each(), create_function()). Only the code
 *    path that calls it fails, so it is reported but left active.
 */
class CompatibilityInspector {
    private const MAX_FILES_PER_PACKAGE = 800;
    private const MAX_FILE_BYTES        = 1572864;
    private const TIME_BUDGET_SECONDS   = 25;
    private const SKIP_DIRS             = array('node_modules', 'tests', 'test', '.git', '.github', 'bower_components');

    /** @var float */
    private $deadline = 0.0;

    /**
     * Extracts every entry, skipping (and reporting) unreadable ones instead of aborting.
     * Aborts before writing anything if any entry tries to escape the target folder.
     *
     * @return array{success: bool, corrupted_skipped: string[]}
     * @throws \RuntimeException When the archive contains a path traversal entry.
     */
    public function resilientExtract(\ZipArchive $zip, string $targetDir): array {
        $targetDir = rtrim(wp_normalize_path($targetDir), '/');
        wp_mkdir_p($targetDir);

        $count = $zip->numFiles;
        for ($i = 0; $i < $count; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            try {
                SecuritySanitizer::assertPathInJail($name, $targetDir);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    /* translators: %s: archive entry name. */
                    sprintf(__('Unsafe archive: the entry "%s" points outside the extraction folder. Restore stopped.', 'wp-bundle-migrator'), $name)
                );
            }
        }

        $skipped = array();
        for ($i = 0; $i < $count; $i++) {
            if ($i % 200 === 0 && function_exists('set_time_limit')) {
                @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }

            $name = $zip->getNameIndex($i);
            if ($name === false) {
                $skipped[] = sprintf('#%d', $i);
                continue;
            }

            $path = SecuritySanitizer::assertPathInJail($name, $targetDir);
            if (substr($name, -1) === '/') {
                wp_mkdir_p($path);
                continue;
            }
            wp_mkdir_p(dirname($path));

            $in = $zip->getStream($name);
            if (!$in) {
                $skipped[] = $name;
                continue;
            }
            $out = fopen($path, 'wb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            if (!$out) {
                fclose($in); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                $skipped[] = $name;
                continue;
            }

            $ok = true;
            while (!feof($in)) {
                $chunk = fread($in, 1048576); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                if ($chunk === false) {
                    $ok = false;
                    break;
                }
                if ($chunk !== '' && fwrite($out, $chunk) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
                    fclose($in);  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                    fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                    throw new \RuntimeException(__('Extraction failed while writing to disk. The disk may be full.', 'wp-bundle-migrator'));
                }
            }
            fclose($in);  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

            if (!$ok) {
                @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                $skipped[] = $name;
            }
        }

        return array(
            'success'           => true,
            'corrupted_skipped' => $skipped,
        );
    }

    /**
     * Resumable extraction. Validates every entry name on the first call, then extracts
     * entries until the deadline. Returns true when finished.
     *
     * @param array<string, mixed> $state
     * @throws \RuntimeException On unsafe archives or disk errors.
     */
    public function extractStep(string $zipPath, string $targetDir, array &$state, float $deadline): bool {
        $zip  = new \ZipArchive();
        $code = $zip->open($zipPath);
        if ($code !== true) {
            throw new \RuntimeException(
                /* translators: %s: zip error code. */
                sprintf(__('Could not open the bundle (zip error %s). The file may be incomplete or is not a zip archive.', 'wp-bundle-migrator'), (string) $code)
            );
        }

        $targetDir = rtrim(wp_normalize_path($targetDir), '/');
        $count     = $zip->numFiles;

        try {
            if (empty($state['validated'])) {
                wp_mkdir_p($targetDir);
                for ($i = 0; $i < $count; $i++) {
                    $name = $zip->getNameIndex($i);
                    if ($name === false) {
                        continue;
                    }
                    try {
                        SecuritySanitizer::assertPathInJail($name, $targetDir);
                    } catch (\Throwable $e) {
                        throw new \RuntimeException(
                            /* translators: %s: archive entry name. */
                            sprintf(__('Unsafe archive: the entry "%s" points outside the extraction folder. Restore stopped.', 'wp-bundle-migrator'), $name)
                        );
                    }
                }
                $state = array('validated' => true, 'index' => 0, 'total' => $count, 'skipped' => array());
            }

            $first = true;
            while ((int) $state['index'] < $count && ($first || microtime(true) < $deadline)) {
                $first = false;
                $i     = (int) $state['index'];
                $state['index'] = $i + 1;

                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    $state['skipped'][] = sprintf('#%d', $i);
                    continue;
                }
                $path = SecuritySanitizer::assertPathInJail($name, $targetDir);
                if (substr($name, -1) === '/') {
                    wp_mkdir_p($path);
                    continue;
                }
                wp_mkdir_p(dirname($path));

                $in = $zip->getStream($name);
                $out = $in ? fopen($path, 'wb') : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
                if (!$in || !$out) {
                    if ($in) {
                        fclose($in); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                    }
                    $state['skipped'][] = $name;
                    continue;
                }

                $ok = true;
                while (!feof($in)) {
                    $chunk = fread($in, 1048576); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                    if ($chunk === false) {
                        $ok = false;
                        break;
                    }
                    if ($chunk !== '' && fwrite($out, $chunk) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
                        fclose($in);  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                        throw new \RuntimeException(__('Extraction failed while writing to disk. The disk may be full.', 'wp-bundle-migrator'));
                    }
                }
                fclose($in);  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                if (!$ok) {
                    @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                    $state['skipped'][] = $name;
                }
            }
        } finally {
            $zip->close();
        }

        return (int) $state['index'] >= $count;
    }

    /**
     * Audits the extracted plugins and themes against this server's PHP and WordPress versions.
     *
     * @param array<string, mixed> $manifest
     * @param array<string, bool>  $scope    Which components will be restored: plugins, themes.
     * @return array<string, mixed>
     */
    public function inspectCompatibility(string $extractDir, array $manifest, array $scope): array {
        $this->deadline = microtime(true) + self::TIME_BUDGET_SECONDS;

        $sourcePhp = isset($manifest['php_version']) ? (string) $manifest['php_version'] : '';
        $targetPhp = PHP_VERSION;
        $items     = array();

        if (!empty($scope['plugins']) && is_dir($extractDir . '/plugins')) {
            foreach ($this->subdirectories($extractDir . '/plugins') as $slug) {
                $item = $this->auditPlugin($slug, $extractDir . '/plugins/' . $slug, $targetPhp);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        if (!empty($scope['themes']) && is_dir($extractDir . '/themes')) {
            foreach ($this->subdirectories($extractDir . '/themes') as $slug) {
                $items[] = $this->auditTheme($slug, $extractDir . '/themes/' . $slug, $targetPhp);
            }
        }

        $summary = array(
            'totalInspected'  => count($items),
            'compatibleCount' => 0,
            'warningCount'    => 0,
            'conflictCount'   => 0,
        );
        foreach ($items as $item) {
            $summary[$item['status'] . 'Count']++;
        }

        // Show problems first.
        $order = array('conflict' => 0, 'warning' => 1, 'compatible' => 2);
        usort(
            $items,
            static function (array $a, array $b) use ($order): int {
                return ($order[$a['status']] <=> $order[$b['status']]) ?: strcasecmp($a['name'], $b['name']);
            }
        );

        $sourceMajor = (int) $sourcePhp;
        $targetMajor = (int) $targetPhp;

        return array(
            'sourcePhp'       => $sourcePhp !== '' ? $sourcePhp : __('Unknown', 'wp-bundle-migrator'),
            'targetPhp'       => $targetPhp,
            'isMajorPhpShift' => $sourcePhp !== '' && $sourceMajor < 8 && $targetMajor >= 8,
            'timedOut'        => microtime(true) > $this->deadline,
            'summary'         => $summary,
            'items'           => $items,
        );
    }

    /**
     * Deactivates conflicting plugins and moves the site off a conflicting active theme.
     * Must run after the database import so it edits the imported settings.
     *
     * @param array<string, mixed> $report Updated in place with the action taken per item.
     */
    public function applyShield(array &$report, bool $isolate): void {
        if (empty($report['items'])) {
            return;
        }

        $plugins = array();
        $themes  = array();
        foreach ($report['items'] as $index => $item) {
            if ($item['status'] !== 'conflict') {
                continue;
            }
            if ($item['type'] === 'plugin') {
                $plugins[$item['slug']] = $index;
            } else {
                $themes[$item['slug']] = $index;
            }
        }

        if (!$isolate) {
            foreach (array_merge($plugins, $themes) as $index) {
                $report['items'][$index]['actionTaken'] = __('Left unchanged because automatic deactivation was turned off. This may cause a fatal error.', 'wp-bundle-migrator');
            }
            return;
        }

        if (!empty($plugins)) {
            $deactivated = $this->deactivatePlugins(array_keys($plugins));
            foreach ($plugins as $slug => $index) {
                if (in_array($slug, $deactivated, true)) {
                    $report['items'][$index]['actionTaken'] = __('Deactivated so the site can load. Its files and settings were restored unchanged.', 'wp-bundle-migrator');
                    $this->writeNotice($slug, (string) $report['items'][$index]['issue']);
                } else {
                    $report['items'][$index]['actionTaken'] = __('Restored. It was not active, so nothing was changed.', 'wp-bundle-migrator');
                }
            }
        }

        if (!empty($themes)) {
            wp_cache_delete('alloptions', 'options');
            $active   = array((string) get_option('stylesheet'), (string) get_option('template'));
            $affected = array_intersect($active, array_keys($themes));

            $fallback = empty($affected) ? null : $this->fallbackTheme(array_keys($themes));
            if ($fallback !== null) {
                switch_theme($fallback);
            }

            foreach ($themes as $slug => $index) {
                if (!in_array($slug, $affected, true)) {
                    $report['items'][$index]['actionTaken'] = __('Restored. It is not the active theme, so nothing was changed.', 'wp-bundle-migrator');
                } elseif ($fallback !== null) {
                    $report['items'][$index]['actionTaken'] = sprintf(
                        /* translators: %s: theme folder name. */
                        __('Was the active theme. Switched to "%s" so the site can load.', 'wp-bundle-migrator'),
                        $fallback
                    );
                } else {
                    $report['items'][$index]['actionTaken'] = __('Active theme, but no compatible fallback theme is installed. Install a default theme and activate it.', 'wp-bundle-migrator');
                }
            }
        }
    }

    /**
     * @return string[] Sub-folder names.
     */
    private function subdirectories(string $dir): array {
        $result = array();
        foreach ((array) scandir($dir) as $entry) {
            if (is_string($entry) && $entry !== '.' && $entry !== '..' && is_dir($dir . '/' . $entry)) {
                $result[] = $entry;
            }
        }
        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function auditPlugin(string $slug, string $dir, string $targetPhp): ?array {
        $main = $this->findMainPluginFile($dir, $slug);
        if ($main === null) {
            return null;
        }

        $headers = get_file_data(
            $main,
            array(
                'Name'        => 'Plugin Name',
                'RequiresPHP' => 'Requires PHP',
                'RequiresWP'  => 'Requires at least',
            )
        );
        $name = $headers['Name'] !== '' ? $headers['Name'] : $slug;

        return $this->evaluate('plugin', $slug, $name, basename($main), $dir, $headers, $targetPhp);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditTheme(string $slug, string $dir, string $targetPhp): array {
        $headers = array('Name' => '', 'RequiresPHP' => '', 'RequiresWP' => '');
        if (is_readable($dir . '/style.css')) {
            $headers = get_file_data(
                $dir . '/style.css',
                array(
                    'Name'        => 'Theme Name',
                    'RequiresPHP' => 'Requires PHP',
                    'RequiresWP'  => 'Requires at least',
                )
            );
        }
        $name = $headers['Name'] !== '' ? $headers['Name'] : $slug;

        return $this->evaluate('theme', $slug, $name, 'style.css', $dir, $headers, $targetPhp);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function evaluate(string $type, string $slug, string $name, string $file, string $dir, array $headers, string $targetPhp): array {
        $item = array(
            'name'           => $name,
            'slug'           => $slug,
            'type'           => $type,
            'status'         => 'compatible',
            'issue'          => __('No problems found.', 'wp-bundle-migrator'),
            'recommendation' => '',
            'file'           => $file,
            'actionTaken'    => __('Restored.', 'wp-bundle-migrator'),
        );

        $requiresPhp = trim((string) $headers['RequiresPHP']);
        if ($requiresPhp !== '' && version_compare($targetPhp, $requiresPhp, '<')) {
            $item['status']         = 'conflict';
            $item['issue']          = sprintf(
                /* translators: 1: required PHP version, 2: current PHP version. */
                __('Requires PHP %1$s or newer, but this server runs PHP %2$s.', 'wp-bundle-migrator'),
                $requiresPhp,
                $targetPhp
            );
            $item['recommendation'] = sprintf(
                /* translators: %s: PHP version. */
                __('Upgrade the server to PHP %s, or install an older release of this package.', 'wp-bundle-migrator'),
                $requiresPhp
            );
            return $item;
        }

        if (version_compare($targetPhp, '8.0.0', '>=')) {
            $finding = $this->scanDirectory($dir);
            if ($finding !== null) {
                $item['status']         = $finding['severity'] === 'fatal' ? 'conflict' : 'warning';
                $item['issue']          = $finding['issue'];
                $item['file']           = $finding['file'] . ($finding['line'] > 0 ? ':' . $finding['line'] : '');
                $item['recommendation'] = __('Update to a release that supports PHP 8, or ask the developer for a fix.', 'wp-bundle-migrator');
                return $item;
            }
        }

        $requiresWp = trim((string) $headers['RequiresWP']);
        if ($requiresWp !== '' && version_compare(get_bloginfo('version'), $requiresWp, '<')) {
            $item['status']         = 'warning';
            $item['issue']          = sprintf(
                /* translators: %s: WordPress version. */
                __('Requires WordPress %s or newer.', 'wp-bundle-migrator'),
                $requiresWp
            );
            $item['recommendation'] = __('Update WordPress on this site.', 'wp-bundle-migrator');
        }

        return $item;
    }

    /**
     * Scans PHP files of one plugin/theme. Returns the most severe finding, or null.
     *
     * @return array{severity: string, issue: string, file: string, line: int}|null
     */
    private function scanDirectory(string $dir): ?array {
        $dir   = rtrim(wp_normalize_path($dir), '/');
        $skip  = self::SKIP_DIRS;
        $files = 0;
        $warn  = null;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $current) use ($skip): bool {
                        return !($current->isDir() && in_array(strtolower($current->getFilename()), $skip, true));
                    }
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            foreach ($iterator as $file) {
                if (microtime(true) > $this->deadline || $files >= self::MAX_FILES_PER_PACKAGE) {
                    break;
                }
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php' || $file->getSize() > self::MAX_FILE_BYTES) {
                    continue;
                }
                $files++;

                $finding = $this->scanFile($file->getPathname());
                if ($finding === null) {
                    continue;
                }
                $finding['file'] = ltrim(substr(wp_normalize_path($file->getPathname()), strlen($dir)), '/');
                if ($finding['severity'] === 'fatal') {
                    return $finding;
                }
                if ($warn === null) {
                    $warn = $finding;
                }
            }
        } catch (\Throwable $e) {
            return $warn;
        }

        return $warn;
    }

    /**
     * Uses PHP's own tokenizer, so comments, strings and inline JavaScript (for example
     * jQuery's $.each()) never cause false positives.
     *
     * @return array{severity: string, issue: string, file: string, line: int}|null
     */
    private function scanFile(string $path): ?array {
        $code = file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if (!is_string($code) || $code === '') {
            return null;
        }

        // Cheap pre-filter before tokenizing.
        $maybeCurly = preg_match('/\$[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\s*\{/', $code) === 1;
        $maybeCall  = stripos($code, 'create_function') !== false || preg_match('/\beach\s*\(/i', $code) === 1;
        if ((!$maybeCurly && !$maybeCall) || !function_exists('token_get_all')) {
            return null;
        }

        $tokens = @token_get_all($code); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $ignore = array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT);
        $sig    = array();
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (!in_array($token[0], $ignore, true)) {
                    $sig[] = array($token[0], $token[1], (int) $token[2]);
                }
            } else {
                $sig[] = array(0, $token, 0);
            }
        }

        // Tokens that make "$var {" legal: property declarations and PHP 8.4 property hooks.
        $declarationPrev = self::tokenIds(array('T_PUBLIC', 'T_PROTECTED', 'T_PRIVATE', 'T_VAR', 'T_STATIC', 'T_READONLY', 'T_STRING', 'T_ARRAY', 'T_CALLABLE', 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_ABSTRACT', 'T_FINAL'));
        // Tokens before "each(" that mean it is not a call to the removed global function.
        $notGlobalCall = self::tokenIds(array('T_OBJECT_OPERATOR', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_DOUBLE_COLON', 'T_FUNCTION', 'T_NEW', 'T_CONST', 'T_NS_SEPARATOR', 'T_USE'));

        $declared = array('each' => false, 'create_function' => false);
        $n        = count($sig);
        for ($i = 0; $i < $n - 1; $i++) {
            if ($sig[$i][0] === T_FUNCTION && $sig[$i + 1][0] === T_STRING) {
                $fn = strtolower($sig[$i + 1][1]);
                if (isset($declared[$fn])) {
                    $declared[$fn] = true;
                }
            }
        }

        $fqName  = defined('T_NAME_FULLY_QUALIFIED') ? constant('T_NAME_FULLY_QUALIFIED') : -1;
        $warning = null;

        for ($i = 0; $i < $n; $i++) {
            list($id, $text, $line) = $sig[$i];
            $next = $sig[$i + 1] ?? null;
            $prev = $sig[$i - 1] ?? null;

            if ($maybeCurly && $id === T_VARIABLE && $next !== null && $next[0] === 0 && $next[1] === '{') {
                if ($prev === null || (!in_array($prev[0], $declarationPrev, true) && $prev[1] !== '?')) {
                    return array(
                        'severity' => 'fatal',
                        'issue'    => sprintf(
                            /* translators: %s: PHP variable name. */
                            __('Uses the curly-brace offset syntax (%s{…}) that PHP 8 no longer accepts. The file cannot be loaded and would crash the site.', 'wp-bundle-migrator'),
                            $text
                        ),
                        'file'     => '',
                        'line'     => $line,
                    );
                }
            }

            if ($warning === null && $maybeCall && ($id === T_STRING || $id === $fqName) && $next !== null && $next[1] === '(') {
                $fn = strtolower(ltrim($text, '\\'));
                if (isset($declared[$fn]) && !$declared[$fn]) {
                    if ($id === T_STRING && $prev !== null && in_array($prev[0], $notGlobalCall, true)) {
                        continue;
                    }
                    $warning = array(
                        'severity' => 'warning',
                        'issue'    => sprintf(
                            /* translators: %s: PHP function name. */
                            __('Calls %s(), which was removed in PHP 8. The feature that uses it will fail with an error.', 'wp-bundle-migrator'),
                            $fn
                        ),
                        'file'     => '',
                        'line'     => $line,
                    );
                }
            }
        }

        return $warning;
    }

    /**
     * Maps token constant names to values, ignoring ones this PHP version does not define.
     *
     * @param string[] $names
     * @return int[]
     */
    private static function tokenIds(array $names): array {
        $ids = array();
        foreach ($names as $name) {
            if (defined($name)) {
                $ids[] = (int) constant($name);
            }
        }
        return $ids;
    }

    private function findMainPluginFile(string $dir, string $slug): ?string {
        $candidate = $dir . '/' . $slug . '.php';
        if (is_file($candidate) && $this->hasPluginHeader($candidate)) {
            return $candidate;
        }
        foreach ((array) scandir($dir) as $entry) {
            if (is_string($entry) && substr($entry, -4) === '.php' && $this->hasPluginHeader($dir . '/' . $entry)) {
                return $dir . '/' . $entry;
            }
        }
        return null;
    }

    private function hasPluginHeader(string $file): bool {
        $head = file_get_contents($file, false, null, 0, 8192); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        return is_string($head) && stripos($head, 'Plugin Name:') !== false;
    }

    /**
     * Removes plugins from active_plugins on every site, and from network-active plugins.
     *
     * @param string[] $slugs Plugin folder names.
     * @return string[] Slugs that were active somewhere and are now deactivated.
     */
    private function deactivatePlugins(array $slugs): array {
        global $wpdb;

        $matches = static function (string $plugin) use ($slugs): ?string {
            foreach ($slugs as $slug) {
                if (strpos($plugin, $slug . '/') === 0 || $plugin === $slug . '.php') {
                    return $slug;
                }
            }
            return null;
        };

        $deactivated = array();

        foreach (DbUtil::optionsTables() as $table) {
            $row = $wpdb->get_row($wpdb->prepare('SELECT option_id, option_value FROM ' . DbUtil::ident($table) . ' WHERE option_name = %s', 'active_plugins')); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if (!$row) {
                continue;
            }
            $active = @unserialize((string) $row->option_value, array('allowed_classes' => false)); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
            if (!is_array($active)) {
                continue;
            }

            $kept = array();
            foreach ($active as $plugin) {
                $hit = is_string($plugin) ? $matches($plugin) : null;
                if ($hit !== null) {
                    $deactivated[] = $hit;
                } else {
                    $kept[] = $plugin;
                }
            }
            if (count($kept) !== count($active)) {
                $wpdb->update($table, array('option_value' => serialize(array_values($kept))), array('option_id' => $row->option_id)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
            }
        }

        if (is_multisite()) {
            $sitemeta = $wpdb->base_prefix . 'sitemeta';
            $row      = $wpdb->get_row($wpdb->prepare('SELECT meta_id, meta_value FROM ' . DbUtil::ident($sitemeta) . ' WHERE meta_key = %s', 'active_sitewide_plugins')); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if ($row) {
                $network = @unserialize((string) $row->meta_value, array('allowed_classes' => false)); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
                if (is_array($network)) {
                    $kept = array();
                    foreach ($network as $plugin => $time) {
                        $hit = $matches((string) $plugin);
                        if ($hit !== null) {
                            $deactivated[] = $hit;
                        } else {
                            $kept[$plugin] = $time;
                        }
                    }
                    if (count($kept) !== count($network)) {
                        $wpdb->update($sitemeta, array('meta_value' => serialize($kept)), array('meta_id' => $row->meta_id)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                    }
                }
            }
        }

        wp_cache_flush();
        return array_values(array_unique($deactivated));
    }

    private function writeNotice(string $slug, string $issue): void {
        $folder = WP_PLUGIN_DIR . '/' . $slug;
        if (!is_dir($folder)) {
            return;
        }
        $text = "WP Bundle Migrator - plugin deactivated during restore\n"
            . "=======================================================\n"
            . "Plugin: {$slug}\n"
            . 'Server PHP version: ' . PHP_VERSION . "\n"
            . "Reason: {$issue}\n\n"
            . "The plugin files and settings were restored unchanged. Update the plugin to a\n"
            . "version that supports this PHP version, then reactivate it under Plugins.\n";
        file_put_contents($folder . '/MIGRATOR_SAFETY_NOTICE.txt', $text);
    }

    /**
     * Finds an installed, error-free theme that is not in $exclude, preferring the default theme.
     *
     * @param string[] $exclude
     */
    private function fallbackTheme(array $exclude): ?string {
        $candidates = array();
        if (defined('WP_DEFAULT_THEME')) {
            $candidates[] = (string) WP_DEFAULT_THEME;
        }

        $themes = wp_get_themes(array('errors' => false));
        $names  = array_keys($themes);
        rsort($names);
        foreach ($names as $name) {
            if (strpos((string) $name, 'twenty') === 0) {
                $candidates[] = (string) $name;
            }
        }
        foreach ($names as $name) {
            $candidates[] = (string) $name;
        }

        foreach (array_unique($candidates) as $candidate) {
            if (in_array($candidate, $exclude, true) || !isset($themes[$candidate])) {
                continue;
            }
            $theme = $themes[$candidate];
            if ($theme->parent() && in_array($theme->get_template(), $exclude, true)) {
                continue;
            }
            return $candidate;
        }
        return null;
    }
}
