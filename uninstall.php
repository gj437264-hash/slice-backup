<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes settings and temporary files. Stored bundles are deleted only when the
 * WPBM_DELETE_BUNDLES_ON_UNINSTALL constant is true, so backups are not lost by accident.
 *
 * @package WpBundleMigrator
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$wpbm_options = array(
    'wpbm_settings',
    'wpbm_last_report',
    'wpbm_db_version',
    'wpbm_lock',
    'wpbm_schedule_last',
    'wpbm_retention_error',
    // Options from 1.0.x.
    'wp_bundle_migrator_settings',
    'wp_bundle_active_extensions',
    'wp_bundle_migrator_last_conflict_report',
);
foreach ($wpbm_options as $wpbm_option) {
    delete_option($wpbm_option);
    delete_site_option($wpbm_option);
}
delete_site_transient('wpbm_web_probe');
delete_site_transient('wpbm_dropbox_access');
wp_clear_scheduled_hook('wpbm_scheduled_backup');
wp_unschedule_hook('wpbm_job_tick');
delete_transient('wp_bundle_migrator_lock');

if (is_multisite()) {
    switch_to_blog(get_main_site_id());
}
$wpbm_uploads = wp_upload_dir(null, false);
if (is_multisite()) {
    restore_current_blog();
}

$wpbm_dir = wp_normalize_path(trailingslashit($wpbm_uploads['basedir']) . 'wp-bundle-migrator-temp');
if (!is_dir($wpbm_dir)) {
    return;
}

$wpbm_delete_bundles = defined('WPBM_DELETE_BUNDLES_ON_UNINSTALL') && WPBM_DELETE_BUNDLES_ON_UNINSTALL;
$wpbm_items          = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($wpbm_dir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);

foreach ($wpbm_items as $wpbm_item) {
    $wpbm_path = $wpbm_item->getPathname();
    $wpbm_name = $wpbm_item->getFilename();
    $wpbm_is_bundle = (bool) preg_match('/\.(zip|wpbundle)$/i', $wpbm_name) && dirname(wp_normalize_path($wpbm_path)) === $wpbm_dir;

    if ($wpbm_is_bundle && !$wpbm_delete_bundles) {
        continue;
    }
    if ($wpbm_item->isDir() && !$wpbm_item->isLink()) {
        @rmdir($wpbm_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    } else {
        @unlink($wpbm_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }
}

if ($wpbm_delete_bundles) {
    @rmdir($wpbm_dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
} else {
    // Keep the web protection for the bundles that remain.
    file_put_contents(
        $wpbm_dir . '/.htaccess',
        "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n"
    );
    file_put_contents($wpbm_dir . '/index.php', "<?php\n// Silence is golden.\n");
}
