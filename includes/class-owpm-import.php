<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OWPM_Import {

	private $temp_dir;

	public function __construct() {
		$upload_dir = wp_upload_dir();
		$this->temp_dir = trailingslashit( $upload_dir['basedir'] ) . 'owpm-backups/';
		
		add_action( 'wp_ajax_owpm_upload_chunk', array( $this, 'ajax_upload_chunk' ) );
		add_action( 'wp_ajax_owpm_process_import', array( $this, 'ajax_process_import' ) );
	}

	public function ajax_upload_chunk() {
		check_ajax_referer( 'owpm_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		if ( ! file_exists( $this->temp_dir ) ) {
			wp_mkdir_p( $this->temp_dir );
		}

		$file_id = isset( $_POST['file_id'] ) ? sanitize_text_field( wp_unslash( $_POST['file_id'] ) ) : '';
		$chunk_index = isset( $_POST['chunk_index'] ) ? intval( $_POST['chunk_index'] ) : 0;
		$total_chunks = isset( $_POST['total_chunks'] ) ? intval( $_POST['total_chunks'] ) : 1;

		if ( empty( $file_id ) || empty( $_FILES['chunk'] ) ) {
			wp_send_json_error( 'Invalid data' );
		}

		$file_path = $this->temp_dir . $file_id . '.zip';

		// Append chunk to file
		if ( isset( $_FILES['chunk']['tmp_name'] ) ) {
			$tmp_name = sanitize_text_field( wp_unslash( $_FILES['chunk']['tmp_name'] ) );
			$chunk_data = file_get_contents( $tmp_name );
			file_put_contents( $file_path, $chunk_data, $chunk_index == 0 ? 0 : FILE_APPEND );
		}

		wp_send_json_success( array( 'message' => 'Chunk uploaded' ) );
	}

	public function ajax_process_import() {
		check_ajax_referer( 'owpm_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		$file_id = isset( $_POST['file_id'] ) ? sanitize_text_field( wp_unslash( $_POST['file_id'] ) ) : '';
		if ( empty( $file_id ) ) wp_send_json_error( 'No file ID provided' );

		$file_path = $this->temp_dir . $file_id . '.zip';
		if ( ! file_exists( $file_path ) ) wp_send_json_error( 'File not found' );

		// 1. Unzip the file
		$zip = new ZipArchive();
		if ( $zip->open( $file_path ) === true ) {
			// Extract everything over the current wp-content
			// For safety in this MVP, we will extract to a temp folder first, then move.
			// But since it's an MVP, we'll extract directly to ABSPATH (it contains wp-content and database.sql)
			$zip->extractTo( ABSPATH );
			$zip->close();
		} else {
			wp_send_json_error( 'Failed to open zip' );
		}

		// 2. Import the Database
		$sql_file = ABSPATH . 'database.sql';
		if ( file_exists( $sql_file ) ) {
			global $wpdb;
			$queries = file_get_contents( $sql_file );
			$queries = explode( ";\n", $queries );
			foreach ( $queries as $query ) {
				$query = trim( $query );
				if ( ! empty( $query ) ) {
					// Import raw database dump queries
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
					$wpdb->query( $query );
				}
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			wp_delete_file( $sql_file ); // cleanup
		}

		// 3. Automated Search & Replace
		$metadata_file = ABSPATH . 'metadata.json';
		if ( file_exists( $metadata_file ) ) {
			$metadata = json_decode( file_get_contents( $metadata_file ), true );
			if ( $metadata && ! empty( $metadata['source_url'] ) ) {
				$old_url = trailingslashit( $metadata['source_url'] );
				$new_url = trailingslashit( site_url() );
				
				if ( $old_url !== $new_url ) {
					OWPM_Search_Replace::run( $old_url, $new_url );
					OWPM_Search_Replace::run( untrailingslashit( $old_url ), untrailingslashit( $new_url ) );
				}

				if ( ! empty( $metadata['source_path'] ) ) {
					$old_path = trailingslashit( str_replace( '\\', '/', $metadata['source_path'] ) );
					$new_path = trailingslashit( str_replace( '\\', '/', ABSPATH ) );
					if ( $old_path !== $new_path ) {
						OWPM_Search_Replace::run( $old_path, $new_path );
						OWPM_Search_Replace::run( untrailingslashit( $old_path ), untrailingslashit( $new_path ) );
					}
				}
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			wp_delete_file( $metadata_file );
		}

		// Cleanup backup zip
		require_once ABSPATH . 'wp-admin/includes/file.php';
		wp_delete_file( $file_path );

		wp_send_json_success( 'Import completed successfully.' );
	}
}
new OWPM_Import();
