<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * @package Open_Migration
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove the working directory and every backup inside it.
$owpm_upload_dir = wp_upload_dir();
$owpm_backup_dir = trailingslashit( $owpm_upload_dir['basedir'] ) . 'open-migration-unlimited-site-transfer-backup/';

if ( is_dir( $owpm_backup_dir ) ) {
	$owpm_delete = function ( $path ) use ( &$owpm_delete ) {
		if ( is_file( $path ) || is_link( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			return;
		}

		$items = @scandir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false !== $items ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$owpm_delete( trailingslashit( $path ) . $item );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $path );
	};

	$owpm_delete( untrailingslashit( $owpm_backup_dir ) );
}

// Remove our options and any leftover transfer secrets.
delete_option( 'owpm_current_export' );
delete_option( 'owpm_domain_change' );

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_owpm_secret_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_owpm_secret_' ) . '%'
	)
);
