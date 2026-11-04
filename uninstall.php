<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes settings and temporary files. Stored bundles are deleted only when the
 * SLICE_BACKUP_DELETE_BUNDLES_ON_UNINSTALL constant is true, so backups are not lost by accident.
 *
 * @package SliceBackup
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$slice_backup_options = array(
    'slice_backup_settings',
    'slice_backup_last_report',
    'slice_backup_db_version',
    'slice_backup_lock',
    'slice_backup_schedule_last',
    'slice_backup_retention_error',
);
foreach ($slice_backup_options as $slice_backup_option) {
    delete_option($slice_backup_option);
    delete_site_option($slice_backup_option);
}
delete_site_transient('slice_backup_web_probe');
delete_site_transient('slice_backup_dropbox_access');
wp_clear_scheduled_hook('slice_backup_scheduled_backup');
wp_unschedule_hook('slice_backup_job_tick');

if (is_multisite()) {
    switch_to_blog(get_main_site_id());
}
$slice_backup_uploads = wp_upload_dir(null, false);
if (is_multisite()) {
    restore_current_blog();
}

$slice_backup_dir = wp_normalize_path(trailingslashit($slice_backup_uploads['basedir']) . 'slice-backup-temp');
if (!is_dir($slice_backup_dir)) {
    return;
}

$slice_backup_delete_bundles = defined('SLICE_BACKUP_DELETE_BUNDLES_ON_UNINSTALL') && SLICE_BACKUP_DELETE_BUNDLES_ON_UNINSTALL;
$slice_backup_items          = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($slice_backup_dir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);

foreach ($slice_backup_items as $slice_backup_item) {
    $slice_backup_path = $slice_backup_item->getPathname();
    $slice_backup_name = $slice_backup_item->getFilename();
    $slice_backup_is_bundle = (bool) preg_match('/\.(zip|slicebk)$/i', $slice_backup_name) && dirname(wp_normalize_path($slice_backup_path)) === $slice_backup_dir;

    if ($slice_backup_is_bundle && !$slice_backup_delete_bundles) {
        continue;
    }
    if ($slice_backup_item->isDir() && !$slice_backup_item->isLink()) {
        @rmdir($slice_backup_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    } else {
        @unlink($slice_backup_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }
}

if ($slice_backup_delete_bundles) {
    @rmdir($slice_backup_dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
} else {
    // Keep the web protection for the bundles that remain.
    file_put_contents(
        $slice_backup_dir . '/.htaccess',
        "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n"
    );
    file_put_contents($slice_backup_dir . '/index.php', "<?php\n// Silence is golden.\n");
}
