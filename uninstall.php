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

require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
$slice_backup_fs = new WP_Filesystem_Direct(null);

if ($slice_backup_delete_bundles) {
    $slice_backup_fs->rmdir($slice_backup_dir, true);
    return;
}

// Remove everything except finished bundles in the top folder.
foreach ((array) $slice_backup_fs->dirlist($slice_backup_dir, true, false) as $slice_backup_name => $slice_backup_entry) {
    $slice_backup_path = $slice_backup_dir . '/' . $slice_backup_name;
    if ('d' === $slice_backup_entry['type']) {
        $slice_backup_fs->rmdir($slice_backup_path, true);
    } elseif (!preg_match('/\.(zip|slicebk)$/i', (string) $slice_backup_name)) {
        wp_delete_file($slice_backup_path);
    }
}

// Keep the web protection for the bundles that remain.
$slice_backup_fs->put_contents(
    $slice_backup_dir . '/.htaccess',
    "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
    0644
);
$slice_backup_fs->put_contents($slice_backup_dir . '/index.php', "<?php\n// Silence is golden.\n", 0644);
