<?php
/**
 * Plugin Name: Open Migration - Unlimited Site Transfer & Backup
 * Plugin URI:  https://dicecodes.com/open-migration/
 * Description: Move, clone, back up and restore a WordPress site with no upload size limit. URLs, server paths and table prefixes are converted automatically.
 * Version:     1.3.13
 * Requires at least: 5.6
 * Requires PHP: 7.2
 * Author:      DiceCodes
 * Author URI:  https://dicecodes.com/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: open-migration-unlimited-site-transfer-backup
 *
 * @package Open_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Guard against a double include.
if ( defined( 'OWPM_VERSION' ) ) {
	return;
}

define( 'OWPM_VERSION', '1.3.13' );
define( 'OWPM_PLUGIN_FILE', __FILE__ );
define( 'OWPM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OWPM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once OWPM_PLUGIN_DIR . 'includes/class-owpm-fs.php';
require_once OWPM_PLUGIN_DIR . 'includes/class-owpm-search-replace.php';
require_once OWPM_PLUGIN_DIR . 'includes/class-owpm-admin.php';
require_once OWPM_PLUGIN_DIR . 'includes/class-owpm-export.php';
require_once OWPM_PLUGIN_DIR . 'includes/class-owpm-import.php';
require_once OWPM_PLUGIN_DIR . 'includes/class-owpm-api.php';
require_once OWPM_PLUGIN_DIR . 'includes/class-owpm-domain.php';

/**
 * Boot the plugin.
 *
 * Hooked to plugins_loaded so every class registers its actions in one place,
 * before admin_menu, admin_enqueue_scripts, wp_ajax_* and rest_api_init fire.
 */
function owpm_bootstrap() {
	static $booted = false;

	if ( $booted ) {
		return;
	}

	$booted = true;

	new OWPM_Admin();
	new OWPM_Export();
	new OWPM_Import();
	new OWPM_API();
	new OWPM_Domain();
}
add_action( 'plugins_loaded', 'owpm_bootstrap' );

/**
 * Create and harden the backups directory on activation.
 */
function owpm_activate() {
	OWPM_FS::init_backup_dir();
}
register_activation_hook( __FILE__, 'owpm_activate' );

/**
 * Clear any half-finished job state when the plugin is switched off.
 */
function owpm_deactivate() {
	delete_option( 'owpm_current_export' );
	delete_option( 'owpm_domain_change' );
}
register_deactivation_hook( __FILE__, 'owpm_deactivate' );
