<?php
/**
 * Direct site-to-site transfer.
 *
 * The source site issues a short-lived token for a completed backup; the
 * destination site exchanges that token for the archive over a REST endpoint and
 * then feeds it into the normal import pipeline.
 *
 * @package Open_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OWPM_API {

	/** Identifies the token format, so a future version can change it safely. */
	const TOKEN_PREFIX = 'owpm1';

	/** Not present in a URL-encoded value, a backup id, or a generated secret. */
	const TOKEN_SEPARATOR = '~';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_ajax_owpm_generate_token', array( $this, 'ajax_generate_token' ) );
		add_action( 'wp_ajax_owpm_pull_site', array( $this, 'ajax_pull_site' ) );
	}

	public function register_routes() {
		register_rest_route(
			'owpm/v1',
			'/download/(?P<backup_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'api_download_backup' ),
				// Authentication is the per-backup secret checked inside the callback.
				'permission_callback' => '__return_true',
				'args'                => array(
					'backup_id' => array(
						'required'          => true,
						'sanitize_callback' => array( 'OWPM_FS', 'sanitize_id' ),
					),
					'secret'    => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Source site: issue a token
	 * ------------------------------------------------------------------ */

	public function ajax_generate_token() {
		check_ajax_referer( 'owpm_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do that.', 'open-migration-unlimited-site-transfer-backup' ), 403 );
		}

		// sanitize_id() is stricter than sanitize_text_field(): it strips everything
		// outside [A-Za-z0-9_-], which is what makes this safe to use in a path.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$backup_id = isset( $_POST['backup_id'] ) ? OWPM_FS::sanitize_id( wp_unslash( $_POST['backup_id'] ) ) : '';

		if ( '' === $backup_id ) {
			wp_send_json_error( __( 'No backup was specified.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$dir  = OWPM_FS::backup_dir();
		$path = $dir . $backup_id . '.zip';

		// Never hand out a token for an archive that does not exist yet: the
		// destination would race the export and pull a truncated file.
		if ( ! file_exists( $path ) || ! OWPM_FS::is_inside( $path, $dir ) ) {
			wp_send_json_error( __( 'That backup could not be found. Finish the export before generating a token.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		// Advisory only. This is a loopback request, and plenty of hosts block a
		// site from calling itself while still being perfectly reachable from the
		// outside, so a failure here must not prevent the transfer.
		$warning = $this->rest_is_reachable()
			? ''
			: __( 'This server could not reach its own REST API. That is often harmless, but if the pull fails on the destination, use the Export and Import tabs instead.', 'open-migration-unlimited-site-transfer-backup' );

		$secret = wp_generate_password( 48, false );
		set_transient( 'owpm_secret_' . $backup_id, $secret, DAY_IN_SECONDS );

		// A plain, delimited, human-readable token. Deliberately not base64: the
		// directory guidelines discourage anything that resembles obfuscation, and
		// a reviewer or user should be able to read exactly what they are pasting
		// between two sites. Only the URL is encoded, because it contains '/'.
		$token = implode(
			self::TOKEN_SEPARATOR,
			array(
				self::TOKEN_PREFIX,
				rawurlencode( untrailingslashit( site_url() ) ),
				$backup_id,
				$secret,
				(int) filesize( $path ),
				time(),
			)
		);

		wp_send_json_success(
			array(
				'token'   => $token,
				'size'    => size_format( (int) filesize( $path ) ),
				'expires' => DAY_IN_SECONDS,
				'warning' => $warning,
			)
		);
	}

	/**
	 * Read a migration token back into its parts.
	 *
	 * Everything here arrives from whoever pasted the token, so each field is
	 * validated by its caller before use.
	 *
	 * @return array|false
	 */
	private function parse_token( $token ) {
		$parts = explode( self::TOKEN_SEPARATOR, trim( (string) $token ) );

		if ( count( $parts ) < 4 || self::TOKEN_PREFIX !== $parts[0] ) {
			return false;
		}

		$url = rawurldecode( $parts[1] );

		if ( '' === $url || '' === $parts[2] || '' === $parts[3] ) {
			return false;
		}

		return array(
			'url'       => $url,
			'backup_id' => $parts[2],
			'secret'    => $parts[3],
			'size'      => isset( $parts[4] ) ? (int) $parts[4] : 0,
			'issued'    => isset( $parts[5] ) ? (int) $parts[5] : 0,
		);
	}

	private function rest_is_reachable() {
		$response = wp_remote_get(
			rest_url( 'owpm/v1' ),
			array(
				'timeout'   => 10,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		// 404 means the namespace exists but has no index route; both are fine.
		$code = (int) wp_remote_retrieve_response_code( $response );

		return $code > 0 && $code < 500;
	}

	/* ---------------------------------------------------------------------
	 * Source site: serve the archive
	 * ------------------------------------------------------------------ */

	public function api_download_backup( WP_REST_Request $request ) {
		$backup_id = OWPM_FS::sanitize_id( $request->get_param( 'backup_id' ) );
		$secret    = (string) $request->get_param( 'secret' );

		$stored = get_transient( 'owpm_secret_' . $backup_id );

		// hash_equals() throws on a non-string in PHP 8, so check both sides first.
		if ( '' === $backup_id || '' === $secret || ! is_string( $stored ) || '' === $stored ) {
			return new WP_Error( 'owpm_unauthorized', __( 'Invalid or expired migration token.', 'open-migration-unlimited-site-transfer-backup' ), array( 'status' => 401 ) );
		}

		if ( ! hash_equals( $stored, $secret ) ) {
			return new WP_Error( 'owpm_unauthorized', __( 'Invalid or expired migration token.', 'open-migration-unlimited-site-transfer-backup' ), array( 'status' => 401 ) );
		}

		$dir       = OWPM_FS::backup_dir();
		$file_path = $dir . $backup_id . '.zip';

		if ( ! file_exists( $file_path ) || ! OWPM_FS::is_inside( $file_path, $dir ) ) {
			return new WP_Error( 'owpm_not_found', __( 'Backup file not found.', 'open-migration-unlimited-site-transfer-backup' ), array( 'status' => 404 ) );
		}

		OWPM_FS::raise_limits();

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $file_path ) . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		header( 'X-Content-Type-Options: nosniff' );

		// Stream in 1MB blocks: readfile() would try to buffer the whole archive.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$handle = @fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return new WP_Error( 'owpm_unreadable', __( 'Backup file could not be read.', 'open-migration-unlimited-site-transfer-backup' ), array( 'status' => 500 ) );
		}

		while ( ! feof( $handle ) ) {
			// Block streaming: WP_Filesystem cannot read incrementally, and buffering a
			// whole archive would exhaust memory. Raw binary, so no escaping applies.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread
			echo fread( $handle, 1048576 );
			flush();
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Destination site: pull the archive
	 * ------------------------------------------------------------------ */

	public function ajax_pull_site() {
		check_ajax_referer( 'owpm_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do that.', 'open-migration-unlimited-site-transfer-backup' ), 403 );
		}

		OWPM_FS::raise_limits();

		$token = isset( $_POST['token'] ) ? trim( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' === $token ) {
			wp_send_json_error( __( 'Please paste a migration token.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$decoded = $this->parse_token( $token );

		if ( ! $decoded ) {
			wp_send_json_error( __( 'That token is not valid. Copy the whole token from the source site and try again.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		// Everything in the token is attacker-controllable, so validate all of it.
		$source_url = esc_url_raw( (string) $decoded['url'] );
		$backup_id  = OWPM_FS::sanitize_id( $decoded['backup_id'] );
		$secret     = sanitize_text_field( (string) $decoded['secret'] );

		if ( '' === $backup_id || '' === $secret ) {
			wp_send_json_error( __( 'That token is not valid.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		if ( ! $source_url || ! wp_http_validate_url( $source_url ) ) {
			wp_send_json_error( __( 'The source site address in that token is not a valid URL.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		if ( untrailingslashit( $source_url ) === untrailingslashit( site_url() ) ) {
			wp_send_json_error( __( 'That token was generated on this same site. Use it on the destination site instead.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$dir = OWPM_FS::init_backup_dir();

		// backup_id is now guaranteed to be [A-Za-z0-9_-] only, so it cannot escape $dir.
		$local_file = $dir . $backup_id . '.zip';

		if ( ! OWPM_FS::is_inside( $local_file, $dir ) ) {
			wp_send_json_error( __( 'Invalid download target.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		OWPM_FS::delete( $local_file );

		$download_url = add_query_arg(
			array( 'secret' => rawurlencode( $secret ) ),
			trailingslashit( $source_url ) . 'wp-json/owpm/v1/download/' . $backup_id
		);

		$response = wp_remote_get(
			$download_url,
			array(
				'timeout'  => 900,
				'stream'   => true,
				'filename' => $local_file,
				'headers'  => array( 'Accept' => 'application/zip' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			OWPM_FS::delete( $local_file );
			wp_send_json_error( sprintf( /* translators: %s: error message. */ __( 'Could not reach the source site: %s', 'open-migration-unlimited-site-transfer-backup' ), $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			OWPM_FS::delete( $local_file );

			if ( 401 === $status ) {
				wp_send_json_error( __( 'The source site rejected the token. It may have expired — generate a fresh one.', 'open-migration-unlimited-site-transfer-backup' ) );
			}
			if ( 404 === $status ) {
				wp_send_json_error( __( 'The source site no longer has that backup. Run the export again on the source site.', 'open-migration-unlimited-site-transfer-backup' ) );
			}

			wp_send_json_error( sprintf( /* translators: %d: HTTP status code. */ __( 'The source site responded with HTTP %d.', 'open-migration-unlimited-site-transfer-backup' ), $status ) );
		}

		if ( ! file_exists( $local_file ) || filesize( $local_file ) < 100 ) {
			OWPM_FS::delete( $local_file );
			wp_send_json_error( __( 'The downloaded backup was empty. Check that the source site can serve large files.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		// Confirm we received an archive and not an HTML error page.
		$handle = @fopen( $local_file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$magic = $handle ? fread( $handle, 4 ) : '';
		if ( $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		if ( "PK\x03\x04" !== $magic && "PK\x05\x06" !== $magic ) {
			OWPM_FS::delete( $local_file );
			wp_send_json_error( __( 'The source site returned something that is not a backup archive. Check that its REST API is publicly reachable.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$expected = isset( $decoded['size'] ) ? (int) $decoded['size'] : 0;
		$actual   = (int) filesize( $local_file );

		if ( $expected > 0 && $actual !== $expected ) {
			OWPM_FS::delete( $local_file );
			wp_send_json_error( __( 'The download was incomplete. Please try the transfer again.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		wp_send_json_success(
			array(
				'file_id' => $backup_id,
				'size'    => size_format( $actual ),
			)
		);
	}
}
