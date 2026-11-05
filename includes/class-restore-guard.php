<?php
/**
 * Keeps a site stable while it is being restored.
 *
 * During a restore the database and the plugin/theme files are replaced in pieces,
 * over many requests. If WordPress loaded the restored plugins and theme on those
 * requests, one half-copied or PHP-incompatible plugin would stop the restore and
 * show a white screen. A small must-use plugin prevents that while the restore runs:
 *
 *  - every request loads only Slice Backup and no theme code;
 *  - visitors get a short "back in a few minutes" page (HTTP 503, not indexed), after
 *    WP-Cron has had its chance to keep the restore moving;
 *  - this server's address, upload folder and database version stay in effect, so the
 *    admin can follow (and cancel) the restore and the backup folder never moves;
 *  - only Slice Backup's own cron events run, and automatic updates wait.
 *
 * The compatibility check then decides which restored plugins and theme are safe to
 * load, and the guard is removed. A restore that stops making progress is ended safely
 * by Slice Backup (problem plugins switched off) before the guard goes away.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Installs and removes the temporary restore guard.
 */
final class RestoreGuard {
    /** File name in the must-use plugins folder. Loads before other must-use plugins. */
    public const FILE = '0-slice-backup-restore-guard.php';

    /** Settings of the current restore, read by the guard at runtime (not PHP, so never cached by OPcache). */
    public const CONFIG_FILE = 'slice-backup-restore-guard.json';

    /** Marker that identifies files written by this plugin. */
    private const SIGNATURE = 'slice-backup-restore-guard';

    /** A restore with no progress for this long is ended safely. */
    public const MAX_IDLE_SECONDS = 7200;

    /** After this long without progress the restore is driven from any request (cron, visitors). */
    public const KICK_AFTER_SECONDS = 90;

    public static function dir(): string {
        $dir = defined('WPMU_PLUGIN_DIR') ? (string) WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
        return wp_normalize_path(untrailingslashit($dir));
    }

    public static function path(): string {
        return self::dir() . '/' . self::FILE;
    }

    public static function configPath(): string {
        return self::dir() . '/' . self::CONFIG_FILE;
    }

    private static function flag(Job $job): string {
        return wp_normalize_path($job->workDir() . '/guard.flag');
    }

    /**
     * Whether a guard is installed on this site.
     */
    public static function isInstalled(): bool {
        return is_file(self::path());
    }

    /**
     * Seconds since the restore last made progress (heartbeat), or null without a flag.
     */
    public static function idleSeconds(Job $job): ?int {
        $flag = self::flag($job);
        clearstatcache(true, $flag);
        return is_file($flag) ? max(0, time() - (int) filemtime($flag)) : null;
    }

    /**
     * Sets up the guard for a restore job. Returns false (and records a warning) when the
     * must-use plugins folder is not writable; the restore then runs without it.
     */
    public static function install(Job $job): bool {
        $config = array(
            'signature'  => self::SIGNATURE,
            'job'        => $job->id,
            'flag'       => self::flag($job),
            'basename'   => plugin_basename(SLICE_BACKUP_FILE),
            'multisite'  => is_multisite(),
            'restNs'     => RestController::NAMESPACE_V1,
            'restPrefix' => rest_get_url_prefix(),
            'cronHook'   => MigratorEngine::TICK_HOOK,
            'maxAge'     => DAY_IN_SECONDS,
        );
        if (!is_multisite()) {
            // This server's address and upload folder stay in effect while the restore runs.
            $config['siteUrl']       = (string) get_option('siteurl');
            $config['homeUrl']       = (string) get_option('home');
            $config['uploadPath']    = (string) get_option('upload_path');
            $config['uploadUrlPath'] = (string) get_option('upload_url_path');
        }
        $job->data['guardConfig'] = $config;

        if (!Storage::filesystem()->put_contents($config['flag'], (string) time(), 0644)) {
            $job->warnings[] = __('The restore guard could not be set up, so restored plugins may load during the restore.', 'slice-backup');
            return false;
        }
        if (!self::writeFiles($config)) {
            $job->warnings[] = sprintf(
                /* translators: %s: folder path. */
                __('The restore guard could not be written to %s (folder not writable), so restored plugins may load during the restore.', 'slice-backup'),
                self::dir()
            );
            return false;
        }
        $job->data['guard'] = true;
        return true;
    }

    /**
     * Writes the guard and its settings (both atomically).
     *
     * @param array<string, mixed> $config
     */
    private static function writeFiles(array $config): bool {
        $fs  = Storage::filesystem();
        $dir = self::dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (!is_dir($dir)) {
            return false;
        }

        foreach (array(self::configPath() => (string) wp_json_encode($config), self::path() => self::template()) as $file => $contents) {
            if (is_file($file) && (string) file_get_contents($file) === $contents) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                continue;
            }
            $tmp = $file . '.tmp';
            if (!$fs->put_contents($tmp, $contents, 0644) || !$fs->move($tmp, $file, true)) {
                wp_delete_file($tmp);
                return false;
            }
            if (function_exists('opcache_invalidate') && substr($file, -4) === '.php') {
                @opcache_invalidate($file, true); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }
        return true;
    }

    /**
     * Shows that the restore is making progress, and puts the guard back if something removed it.
     */
    public static function heartbeat(Job $job): void {
        if ($job->type !== 'restore' || empty($job->data['guard'])) {
            return;
        }
        $flag = self::flag($job);
        if (is_file($flag)) {
            @touch($flag); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        } else {
            Storage::filesystem()->put_contents($flag, (string) time(), 0644);
        }
        if (!is_file(self::path()) || !is_file(self::configPath())) {
            self::writeFiles((array) ($job->data['guardConfig'] ?? array()));
        }
    }

    /**
     * Removes the guard (when it is ours) and the job's flag.
     */
    public static function remove(?Job $job = null): void {
        foreach (array(self::path(), self::configPath()) as $file) {
            if (!is_file($file)) {
                continue;
            }
            $head = (string) file_get_contents($file, false, null, 0, 600); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            if (strpos($head, self::SIGNATURE) !== false) {
                wp_delete_file($file);
                if (function_exists('opcache_invalidate') && substr($file, -4) === '.php') {
                    @opcache_invalidate($file, true); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                }
            }
        }
        if ($job !== null) {
            wp_delete_file(self::flag($job));
            $job->data['guard'] = false;
        }
    }

    /**
     * Turns off the guard's filters in the current request (used when a restore ends,
     * so this request sees and writes the real restored settings).
     */
    public static function disableInThisRequest(): void {
        $hooks = array(
            'pre_option_active_plugins',
            'pre_site_option_active_sitewide_plugins',
            'pre_update_option_active_plugins',
            'pre_update_site_option_active_sitewide_plugins',
            'pre_option_upload_path',
            'pre_option_upload_url_path',
            'pre_option_db_version',
            'pre_option_siteurl',
            'pre_option_home',
            // Only Slice Backup is loaded in a guarded request, so these hold only the guard's filters.
            'template',
            'stylesheet',
        );
        foreach ($hooks as $hook) {
            remove_all_filters($hook);
        }
    }

    /**
     * The must-use plugin. Plain PHP 7.4, the same for every restore, no dependency on this
     * plugin's classes. It reads the restore's settings from the JSON file next to it.
     */
    private static function template(): string {
        return <<<'GUARD'
<?php
/**
 * Plugin Name: Slice Backup restore guard
 * Description: Temporary (slice-backup-restore-guard). Added by Slice Backup while a restore runs: loads only Slice Backup and shows visitors a maintenance page, so half-restored plugins and themes cannot break the site. Removed automatically when the restore ends.
 */

if (!defined('ABSPATH')) {
	exit;
}

(static function () {
	$configFile = __DIR__ . '/slice-backup-restore-guard.json';
	$config     = is_file($configFile) ? json_decode((string) file_get_contents($configFile), true) : null;
	$flag       = is_array($config) && !empty($config['flag']) ? (string) $config['flag'] : '';
	$plugin     = is_array($config) && !empty($config['basename']) ? WP_PLUGIN_DIR . '/' . $config['basename'] : '';

	// The restore is over (or Slice Backup is gone, or a day without any progress): remove the guard.
	clearstatcache();
	if ($flag === '' || !is_file($flag) || !is_file($plugin) || (time() - (int) filemtime($flag)) > (int) ($config['maxAge'] ?? 86400)) {
		foreach (array(__FILE__, $configFile) as $file) {
			if (is_file($file) && is_writable($file)) {
				unlink($file);
			}
		}
		return;
	}

	define('SLICE_BACKUP_RESTORE_GUARD', (string) ($config['job'] ?? '1'));

	$uri     = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
	$path    = (string) parse_url($uri, PHP_URL_PATH);
	$script  = isset($_SERVER['SCRIPT_FILENAME']) ? basename((string) $_SERVER['SCRIPT_FILENAME']) : '';
	$ns      = (string) $config['restNs'];
	$route   = isset($_GET['rest_route']) ? '/' . ltrim((string) $_GET['rest_route'], '/') : '';
	$isRest  = strpos($route, '/' . $ns . '/') === 0
		|| (bool) preg_match('#/' . preg_quote((string) $config['restPrefix'], '#') . '/' . preg_quote($ns, '#') . '(/|$)#', $path);
	$allowed = (defined('WP_CLI') && WP_CLI)
		|| (defined('DOING_AJAX') && DOING_AJAX)
		|| (defined('DOING_CRON') && DOING_CRON)
		|| (defined('WP_ADMIN') && WP_ADMIN)
		|| $script === 'wp-login.php'
		|| $isRest;

	// Every request: load only Slice Backup. Restored plugins may be incomplete or
	// incompatible until the restore ends, and their settings must not be overwritten.
	$basename = (string) $config['basename'];
	$keepOld  = static function ($value, $old) {
		return $old;
	};
	if (!empty($config['multisite'])) {
		add_filter('pre_site_option_active_sitewide_plugins', static function () use ($basename) {
			return array($basename => time());
		}, PHP_INT_MAX);
		add_filter('pre_update_site_option_active_sitewide_plugins', $keepOld, PHP_INT_MAX, 2);
	}
	add_filter('pre_option_active_plugins', static function () use ($basename, $config) {
		return empty($config['multisite']) ? array($basename) : array();
	}, PHP_INT_MAX);
	add_filter('pre_update_option_active_plugins', $keepOld, PHP_INT_MAX, 2);

	// Load no theme code. The real theme is checked and switched on when the restore ends.
	$noTheme = static function () {
		return 'slice-backup-no-theme';
	};
	add_filter('template', $noTheme, PHP_INT_MAX);
	add_filter('stylesheet', $noTheme, PHP_INT_MAX);
	add_filter('validate_current_theme', '__return_false', PHP_INT_MAX);

	// The restored database may come from another WordPress version: no upgrade screen mid-restore.
	add_filter('pre_option_db_version', static function () {
		return isset($GLOBALS['wp_db_version']) ? (string) $GLOBALS['wp_db_version'] : false;
	}, PHP_INT_MAX);

	// Keep this server's address and upload folder until the restore has rewritten them.
	foreach (array('siteurl' => 'siteUrl', 'home' => 'homeUrl', 'upload_path' => 'uploadPath', 'upload_url_path' => 'uploadUrlPath') as $option => $key) {
		if (array_key_exists($key, $config)) {
			$value = (string) $config[$key];
			add_filter('pre_option_' . $option, static function () use ($value) {
				return $value;
			}, PHP_INT_MAX);
		}
	}

	// No automatic updates while files are being replaced.
	add_filter('automatic_updater_disabled', '__return_true', PHP_INT_MAX);

	// WP-Cron: run only Slice Backup's own events. Other plugins' events stay queued for later.
	if (defined('DOING_CRON') && DOING_CRON) {
		$hook = (string) $config['cronHook'];
		add_filter('pre_get_ready_cron_jobs', static function () use ($hook) {
			$ready = array();
			$crons = function_exists('_get_cron_array') ? _get_cron_array() : array();
			foreach ((array) $crons as $timestamp => $hooks) {
				if ($timestamp > microtime(true)) {
					break;
				}
				if (isset($hooks[$hook])) {
					$ready[$timestamp] = array($hook => $hooks[$hook]);
				}
			}
			return $ready;
		}, PHP_INT_MAX);
	}

	if ($allowed) {
		return;
	}

	// Visitors: WordPress loads (only Slice Backup, which keeps a stalled restore moving),
	// WP-Cron gets its turn, then the maintenance page is sent.
	add_action('wp_loaded', static function () {
		if (!headers_sent()) {
			$protocol = isset($_SERVER['SERVER_PROTOCOL']) && preg_match('#^HTTP/[0-9.]+$#', (string) $_SERVER['SERVER_PROTOCOL']) ? (string) $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
			header($protocol . ' 503 Service Unavailable', true, 503);
			header('Retry-After: 120');
			header('Content-Type: text/html; charset=utf-8');
			header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
			header('X-Robots-Tag: noindex');
		}
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Briefly unavailable</title></head>'
			. '<body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:34em;margin:18vh auto;padding:0 1.2em;color:#1d2327;line-height:1.5">'
			. '<h1 style="font-size:1.5em;font-weight:600">Briefly unavailable for maintenance</h1>'
			. '<p>This site is being restored. Please check back in a few minutes.</p></body></html>';
		exit;
	}, PHP_INT_MAX);
})();

GUARD;
    }
}
