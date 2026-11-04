<?php
/**
 * Plugin Name: Open Migration - Unlimited Site Transfer & Backup
 * Plugin URI:  https://dicecodes.com/open-migration/
 * Description: Move, clone, back up and restore a WordPress site with no upload size limit. URLs, server paths and table prefixes are converted automatically.
 * Version:     1.5.0
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

define( 'OWPM_VERSION', '1.5.0' );
define( 'OWPM_PLUGIN_FILE', __FILE__ );
define( 'OWPM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OWPM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once OWPM_PLUGIN_DIR . 'includes/class-owpm-fs.php';
require_once OWPM_PLUGIN_DIR . 'includes/class-owpm-rollback.php';
require_once OWPM_PLUGIN_DIR . 'includes/class-owpm-search-replace.php';
require_once OWPM_PLUGIN_DIR . 'includes/class-owpm-admin.php';
require_once OWPM_PLUGIN_DIR . 'includes/class-owpm-export.php';
require_once OWPM_PLUGIN_DIR . 'includes/class-owpm-import.php';
require_once OWPM_PLUGIN_DIR . 'includes/class-owpm-api.php';
require_once OWPM_PLUGIN_DIR . 'includes/class-owpm-domain.php';
require_once OWPM_PLUGIN_DIR . 'includes/class-owpm-verify.php';

/**
 * Boot the plugin.
 *
 * Hooked to plugins_loaded so every class registers its actions in one place,
 * before admin_menu, admin_enqueue_scripts, wp_ajax_* and rest_api_init fire.
 *
 * The function_exists() wrapper is not redundant with the OWPM_VERSION guard
 * above. PHP binds an unconditional top-level function at compile time, so the
 * early return never gets the chance to stop the declaration -- if a second
 * copy of this plugin is ever present in another folder, loading it is a fatal
 * "Cannot redeclare" before WordPress can deactivate anything, and the site is
 * down on every request including wp-admin. Wrapping it makes the declaration
 * conditional, which is what actually prevents that.
 */
if ( ! function_exists( 'owpm_bootstrap' ) ) {
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
		new OWPM_Verify();
	}
}
add_action( 'plugins_loaded', 'owpm_bootstrap' );

/**
 * Create and harden the backups directory on activation.
 *
 * Guarded for the same reason as owpm_bootstrap() above.
 */
if ( ! function_exists( 'owpm_activate' ) ) {
	function owpm_activate() {
		OWPM_FS::init_backup_dir();
	}
}
register_activation_hook( __FILE__, 'owpm_activate' );

/**
 * Clear any half-finished job state when the plugin is switched off.
 *
 * Guarded for the same reason as owpm_bootstrap() above.
 */
if ( ! function_exists( 'owpm_deactivate' ) ) {
	function owpm_deactivate() {
		delete_option( 'owpm_current_export' );
		delete_option( 'owpm_domain_change' );
	}
}
register_deactivation_hook( __FILE__, 'owpm_deactivate' );
