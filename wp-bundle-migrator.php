<?php
/**
 * Plugin Name:       WP Bundle Migrator
 * Plugin URI:        https://github.com/gj437264-hash/wp-bundle-migrator-main
 * Description:       Back up, migrate and restore WordPress sites and multisite networks. Resumable background jobs, AES-256 encryption, your own cloud storage (S3, R2, Google Cloud, Wasabi, B2, Spaces, MinIO, SFTP, Dropbox), direct site-to-site transfer, scheduled backups with GFS retention, serialization-safe URL replacement and a PHP compatibility check.
 * Version:           1.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            WP Migration Architecture Team
 * Author URI:        https://wp-bundle-migrator.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-bundle-migrator
 * Domain Path:       /languages
 * Network:           true
 *
 * @package WpBundleMigrator
 */

declare(strict_types=1);

namespace WpBundleMigrator;

if (!defined('ABSPATH')) {
    exit;
}

define('WPBM_VERSION', '1.3.0');
define('WPBM_FILE', __FILE__);
define('WPBM_PATH', plugin_dir_path(__FILE__));
define('WPBM_URL', plugin_dir_url(__FILE__));
define('WPBM_MIN_PHP', '7.4.0');
define('WPBM_MIN_WP', '5.8');

// Stop early on old PHP instead of failing with a parse error in the class files.
if (version_compare(PHP_VERSION, WPBM_MIN_PHP, '<')) {
    $wpbm_php_notice = static function () {
        echo '<div class="notice notice-error"><p>' . esc_html(
            sprintf(
                /* translators: 1: required PHP version, 2: current PHP version. */
                __('WP Bundle Migrator requires PHP %1$s or newer. This server runs PHP %2$s.', 'wp-bundle-migrator'),
                WPBM_MIN_PHP,
                PHP_VERSION
            )
        ) . '</p></div>';
    };
    add_action('admin_notices', $wpbm_php_notice);
    add_action('network_admin_notices', $wpbm_php_notice);
    return;
}

require_once WPBM_PATH . 'includes/class-db-util.php';
require_once WPBM_PATH . 'includes/class-storage.php';
require_once WPBM_PATH . 'includes/class-crypto.php';
require_once WPBM_PATH . 'includes/class-job.php';
require_once WPBM_PATH . 'includes/class-vendor.php';
require_once WPBM_PATH . 'includes/interface-destination.php';
require_once WPBM_PATH . 'includes/class-s3-client.php';
require_once WPBM_PATH . 'includes/class-s3-destination.php';
require_once WPBM_PATH . 'includes/class-sftp-destination.php';
require_once WPBM_PATH . 'includes/class-dropbox-error.php';
require_once WPBM_PATH . 'includes/class-dropbox-destination.php';
require_once WPBM_PATH . 'includes/class-cloud.php';
require_once WPBM_PATH . 'includes/class-peer-error.php';
require_once WPBM_PATH . 'includes/class-peer.php';
require_once WPBM_PATH . 'includes/class-scheduler.php';
require_once WPBM_PATH . 'includes/class-retention.php';
require_once WPBM_PATH . 'includes/class-notifier.php';
require_once WPBM_PATH . 'includes/class-security-sanitizer.php';
require_once WPBM_PATH . 'includes/class-recursive-search-replace.php';
require_once WPBM_PATH . 'includes/class-compatibility-inspector.php';
require_once WPBM_PATH . 'includes/class-chunked-database-dumper.php';
require_once WPBM_PATH . 'includes/class-streaming-archiver.php';
require_once WPBM_PATH . 'includes/class-migrator-engine.php';
require_once WPBM_PATH . 'includes/class-system-check.php';
require_once WPBM_PATH . 'includes/class-ajax-controller.php';
require_once WPBM_PATH . 'includes/class-rest-controller.php';
require_once WPBM_PATH . 'includes/class-plugin.php';

if (defined('WP_CLI') && WP_CLI) {
    require_once WPBM_PATH . 'includes/class-cli-command.php';
}

register_activation_hook(__FILE__, array(Plugin::class, 'activate'));
register_deactivation_hook(__FILE__, array(Plugin::class, 'deactivate'));

add_action('plugins_loaded', array(Plugin::class, 'init'));
