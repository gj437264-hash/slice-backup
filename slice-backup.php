<?php
/**
 * Plugin Name:       Slice Backup – Backup, Restore & Migration
 * Plugin URI:        https://github.com/gj437264-hash/slice-backup
 * Description:       Back up, restore and migrate WordPress in small resumable steps — encrypted, scheduled, and sent to your own cloud storage, SFTP server, Dropbox or another site.
 * Version:           1.0.0-beta
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            WP Migration Architecture Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       slice-backup
 * Domain Path:       /languages
 * Network:           true
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

define('SLICE_BACKUP_VERSION', '1.0.0-beta');
define('SLICE_BACKUP_FILE', __FILE__);
define('SLICE_BACKUP_PATH', plugin_dir_path(__FILE__));
define('SLICE_BACKUP_URL', plugin_dir_url(__FILE__));
define('SLICE_BACKUP_MIN_PHP', '7.4.0');
define('SLICE_BACKUP_MIN_WP', '6.2');

// Stop early on old PHP instead of failing with a parse error in the class files.
if (version_compare(PHP_VERSION, SLICE_BACKUP_MIN_PHP, '<')) {
    $slice_backup_php_notice = static function () {
        echo '<div class="notice notice-error"><p>' . esc_html(
            sprintf(
                /* translators: 1: required PHP version, 2: current PHP version. */
                __('Slice Backup requires PHP %1$s or newer. This server runs PHP %2$s.', 'slice-backup'),
                SLICE_BACKUP_MIN_PHP,
                PHP_VERSION
            )
        ) . '</p></div>';
    };
    add_action('admin_notices', $slice_backup_php_notice);
    add_action('network_admin_notices', $slice_backup_php_notice);
    return;
}

require_once SLICE_BACKUP_PATH . 'includes/class-db-util.php';
require_once SLICE_BACKUP_PATH . 'includes/class-storage.php';
require_once SLICE_BACKUP_PATH . 'includes/class-crypto.php';
require_once SLICE_BACKUP_PATH . 'includes/class-job.php';
require_once SLICE_BACKUP_PATH . 'includes/class-vendor.php';
require_once SLICE_BACKUP_PATH . 'includes/interface-destination.php';
require_once SLICE_BACKUP_PATH . 'includes/class-s3-client.php';
require_once SLICE_BACKUP_PATH . 'includes/class-s3-destination.php';
require_once SLICE_BACKUP_PATH . 'includes/class-sftp-destination.php';
require_once SLICE_BACKUP_PATH . 'includes/class-dropbox-error.php';
require_once SLICE_BACKUP_PATH . 'includes/class-dropbox-destination.php';
require_once SLICE_BACKUP_PATH . 'includes/class-cloud.php';
require_once SLICE_BACKUP_PATH . 'includes/class-peer-error.php';
require_once SLICE_BACKUP_PATH . 'includes/class-peer.php';
require_once SLICE_BACKUP_PATH . 'includes/class-scheduler.php';
require_once SLICE_BACKUP_PATH . 'includes/class-retention.php';
require_once SLICE_BACKUP_PATH . 'includes/class-notifier.php';
require_once SLICE_BACKUP_PATH . 'includes/class-security-sanitizer.php';
require_once SLICE_BACKUP_PATH . 'includes/class-recursive-search-replace.php';
require_once SLICE_BACKUP_PATH . 'includes/class-compatibility-inspector.php';
require_once SLICE_BACKUP_PATH . 'includes/class-chunked-database-dumper.php';
require_once SLICE_BACKUP_PATH . 'includes/class-streaming-archiver.php';
require_once SLICE_BACKUP_PATH . 'includes/class-migrator-engine.php';
require_once SLICE_BACKUP_PATH . 'includes/class-system-check.php';
require_once SLICE_BACKUP_PATH . 'includes/class-ajax-controller.php';
require_once SLICE_BACKUP_PATH . 'includes/class-rest-controller.php';
require_once SLICE_BACKUP_PATH . 'includes/class-plugin.php';

if (defined('WP_CLI') && WP_CLI) {
    require_once SLICE_BACKUP_PATH . 'includes/class-cli-command.php';
}

register_activation_hook(__FILE__, array(Plugin::class, 'activate'));
register_deactivation_hook(__FILE__, array(Plugin::class, 'deactivate'));

add_action('plugins_loaded', array(Plugin::class, 'init'));
