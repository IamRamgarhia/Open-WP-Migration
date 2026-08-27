<?php
/**
 * Import: chunked upload, safe extraction, resumable DB restore and automatic
 * URL / path / table-prefix conversion.
 *
 * The import runs as a sequence of short AJAX steps so that huge archives never
 * hit a PHP timeout. Restoring the database replaces wp_users and the session
 * tokens, so the login cookie stops validating part-way through. The step that
 * finishes the restore therefore signs the operator straight back in and returns
 * a fresh nonce, and everything after it authenticates normally. A one-time key
 * exists only as a fallback for the case where the operator's account is not
 * present in the restored data at all (see authorize()).
 *
 * @package Open_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OWPM_Import {

	const SQL_DELIMITER = '/* OWPM_EOS */';
	const SESSION_TTL   = 21600; // 6 hours.

	private $temp_dir;

	public function __construct() {
		$this->temp_dir = OWPM_FS::backup_dir();

		add_action( 'wp_ajax_owpm_upload_chunk', array( $this, 'ajax_upload_chunk' ) );
		add_action( 'wp_ajax_owpm_import_prepare', array( $this, 'ajax_prepare' ) );

		// Also registered for logged-out requests, as a fallback.
		//
		// Restoring the database replaces wp_users and the session tokens in
		// wp_usermeta, so the operator's login cookie stops validating mid-import.
		// ajax_db() handles the normal case by signing them straight back in and
		// returning a fresh nonce, so the remaining steps run fully authenticated.
		//
		// That is not always possible: the archive may simply not contain the
		// operator's account. Without a fallback the import would stop there and
		// leave the site half-restored, so these remain reachable via a one-time
		// key. Each handler calls authorize() first, which requires either a valid
		// nonce plus manage_options, or a 64-character random key that exists only
		// while an import an administrator explicitly started is running. Only a
		// SHA-256 hash of the key is written to disk, it is compared with
		// hash_equals(), it expires after six hours, and it is deleted when the
		// import finishes or is aborted. The file_id must match as well.
		//
		// Deliberately no lockout-after-N-failures: the key has ~380 bits of
		// entropy so guessing is not the threat, and a lockout would instead let
		// anyone abort a legitimate in-progress migration by sending junk.
		foreach ( array( 'owpm_import_extract', 'owpm_import_db', 'owpm_import_replace', 'owpm_import_finalize', 'owpm_import_abort' ) as $action ) {
			$method = 'ajax_' . str_replace( 'owpm_import_', '', $action );
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, $method ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Session handling
	 * ------------------------------------------------------------------ */

	private function session_path( $file_id ) {
		return $this->temp_dir . '.owpm-session-' . $file_id . '.json';
	}

	private function read_session( $file_id ) {
		$path = $this->session_path( $file_id );

		if ( ! file_exists( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		$raw = @file_get_contents( $path );
		if ( false === $raw ) {
			return false;
		}

		$data = json_decode( $raw, true );

		return is_array( $data ) ? $data : false;
	}

	private function write_session( $file_id, $session ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		return false !== @file_put_contents( $this->session_path( $file_id ), wp_json_encode( $session ), LOCK_EX );
	}

	private function delete_session( $file_id ) {
		OWPM_FS::delete( $this->session_path( $file_id ) );
	}

	/**
	 * Authorise an import step.
	 *
	 * Accepts either a normal admin request (nonce + capability) or a valid,
	 * unexpired session key. The key only exists while an import that an
	 * administrator explicitly started is running, and it is deleted on completion.
	 */
	private function authorize( $file_id ) {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( $nonce && wp_verify_nonce( $nonce, 'owpm_nonce' ) && current_user_can( 'manage_options' ) ) {
			return true;
		}

		$key = isset( $_POST['import_key'] ) ? sanitize_text_field( wp_unslash( $_POST['import_key'] ) ) : '';

		if ( '' === $key || '' === $file_id ) {
			return false;
		}

		$session = $this->read_session( $file_id );

		if ( ! $session || empty( $session['key_hash'] ) || empty( $session['expires'] ) ) {
			return false;
		}

		if ( time() > (int) $session['expires'] ) {
			$this->delete_session( $file_id );
			return false;
		}

		// Only a hash is ever written to disk. The session file lives in a
		// deny-all directory, but on a misconfigured server (Nginx ignores
		// .htaccess) reading it still must not yield a usable credential.
		return hash_equals( (string) $session['key_hash'], hash( 'sha256', $key ) );
	}

	/**
	 * Read + validate the file id from the request.
	 */
	private function request_file_id() {
		// The nonce (or the session key) is verified by the calling handler via
		// check_ajax_referer() or authorize(); this helper only reads the value.
		// sanitize_id() is stricter than sanitize_text_field(): it strips everything
		// outside [A-Za-z0-9_-], which is exactly what makes the result path-safe.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_POST['file_id'] ) ? wp_unslash( $_POST['file_id'] ) : '';

		return OWPM_FS::sanitize_id( $raw );
	}

	private function deny() {
		wp_send_json_error( __( 'This import request could not be verified. Please start the import again.', 'open-migration-unlimited-site-transfer-backup' ), 403 );
	}

	/* ---------------------------------------------------------------------
	 * Step 1: chunked upload
	 * ------------------------------------------------------------------ */

	public function ajax_upload_chunk() {
		check_ajax_referer( 'owpm_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do that.', 'open-migration-unlimited-site-transfer-backup' ), 403 );
		}

		OWPM_FS::raise_limits();
		OWPM_FS::init_backup_dir();

		$file_id      = $this->request_file_id();
		$chunk_index  = isset( $_POST['chunk_index'] ) ? absint( $_POST['chunk_index'] ) : 0;
		$total_chunks = isset( $_POST['total_chunks'] ) ? max( 1, absint( $_POST['total_chunks'] ) ) : 1;

		if ( '' === $file_id ) {
			wp_send_json_error( __( 'Invalid upload identifier.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		if ( empty( $_FILES['chunk'] ) || ! isset( $_FILES['chunk']['tmp_name'] ) ) {
			wp_send_json_error( __( 'No data was received for this chunk.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		// tmp_name is generated by PHP, not by the client, and is validated below
		// with is_uploaded_file(). Running it through sanitize_text_field() would
		// corrupt legitimate temp paths without adding any protection.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tmp_name = $_FILES['chunk']['tmp_name'];
		$error    = isset( $_FILES['chunk']['error'] ) ? (int) $_FILES['chunk']['error'] : UPLOAD_ERR_OK;

		if ( UPLOAD_ERR_OK !== $error ) {
			wp_send_json_error( sprintf( /* translators: %d: PHP upload error code. */ __( 'The chunk failed to upload (PHP error %d).', 'open-migration-unlimited-site-transfer-backup' ), $error ) );
		}

		if ( ! is_uploaded_file( $tmp_name ) ) {
			wp_send_json_error( __( 'The uploaded chunk could not be verified.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$file_path = $this->temp_dir . $file_id . '.zip';

		if ( ! OWPM_FS::is_inside( $file_path, $this->temp_dir ) ) {
			wp_send_json_error( __( 'Invalid upload target.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$mode   = ( 0 === $chunk_index ) ? 'wb' : 'ab';
		$target = @fopen( $file_path, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $target ) {
			wp_send_json_error( __( 'Could not write to the backup directory. Check permissions on wp-content/uploads.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$source = @fopen( $tmp_name, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $source ) {
			fclose( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_send_json_error( __( 'Could not read the uploaded chunk.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$written = stream_copy_to_stream( $source, $target );

		fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( false === $written ) {
			wp_send_json_error( __( 'Writing the chunk to disk failed. The server may be out of space.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		// Reject anything that is not actually a ZIP as early as possible.
		if ( 0 === $chunk_index && ! $this->looks_like_zip( $file_path ) ) {
			OWPM_FS::delete( $file_path );
			wp_send_json_error( __( 'That file is not a valid .zip archive.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		wp_send_json_success(
			array(
				'chunk_index'  => $chunk_index,
				'total_chunks' => $total_chunks,
				'bytes'        => (int) $written,
			)
		);
	}

	private function looks_like_zip( $path ) {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$magic = fread( $handle, 4 );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return "PK\x03\x04" === $magic || "PK\x05\x06" === $magic || "PK\x07\x08" === $magic;
	}

	/* ---------------------------------------------------------------------
	 * Step 2: prepare
	 * ------------------------------------------------------------------ */

	public function ajax_prepare() {
		check_ajax_referer( 'owpm_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do that.', 'open-migration-unlimited-site-transfer-backup' ), 403 );
		}

		OWPM_FS::raise_limits();

		global $wpdb;

		if ( is_multisite() ) {
			wp_send_json_error( __( 'Multisite networks are not supported yet.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_send_json_error( __( 'The PHP ZipArchive extension is required but is not enabled on this server.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$file_id = $this->request_file_id();
		if ( '' === $file_id ) {
			wp_send_json_error( __( 'Invalid import identifier.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$zip_path = $this->temp_dir . $file_id . '.zip';

		if ( ! file_exists( $zip_path ) || ! OWPM_FS::is_inside( $zip_path, $this->temp_dir ) ) {
			wp_send_json_error( __( 'The uploaded backup file could not be found.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$zip  = new ZipArchive();
		$open = $zip->open( $zip_path );

		if ( true !== $open ) {
			wp_send_json_error( __( 'The backup archive is corrupt or could not be opened.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		// numFiles is ZipArchive's own property; its name is not ours to choose.
		$total_entries = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		// metadata.json tells us the source URL, path and table prefix.
		$metadata = array();
		$raw_meta = $zip->getFromName( 'metadata.json' );
		if ( false !== $raw_meta ) {
			$decoded = json_decode( $raw_meta, true );
			if ( is_array( $decoded ) ) {
				$metadata = $decoded;
			}
		}

		$has_sql = ( false !== $zip->locateName( 'database.sql' ) );
		$zip->close();

		if ( ! $has_sql ) {
			wp_send_json_error( __( 'This archive does not contain a database.sql file, so it cannot be imported.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		if ( ! empty( $metadata['multisite'] ) ) {
			wp_send_json_error( __( 'This backup came from a multisite network, which is not supported yet.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$work_dir = $this->temp_dir . $file_id . '-work';
		OWPM_FS::delete( $work_dir );
		wp_mkdir_p( $work_dir );

		$current_user = wp_get_current_user();

		// Handed to the browser once, in the response below; only the hash is stored.
		$import_key = wp_generate_password( 64, false );

		$session = array(
			'file_id'        => $file_id,
			'key_hash'       => hash( 'sha256', $import_key ),
			'expires'        => time() + self::SESSION_TTL,
			'created'        => time(),
			'zip_path'       => $zip_path,
			'work_dir'       => $work_dir,
			'total_entries'  => $total_entries,
			'extract_offset' => 0,
			'extracted'      => 0,
			'skipped'        => 0,
			'sql_offset'     => 0,
			'sql_mode'       => 'marker',
			'statements'     => 0,
			'db_done'        => 0,
			'errors'         => array(),
			'metadata'       => $metadata,
			'source_content' => ! empty( $metadata['content_dir'] ) ? (string) $metadata['content_dir'] : 'wp-content',
			'old_prefix'     => ! empty( $metadata['table_prefix'] ) ? (string) $metadata['table_prefix'] : '',
			'new_prefix'     => $wpdb->prefix,
			'old_url'        => ! empty( $metadata['source_url'] ) ? untrailingslashit( (string) $metadata['source_url'] ) : '',
			'old_path'       => ! empty( $metadata['source_path'] ) ? (string) $metadata['source_path'] : '',
			// Captured before the restore, because site_url() will read the OLD value afterwards.
			'new_url'        => untrailingslashit( site_url() ),
			'new_home'       => untrailingslashit( home_url() ),
			'new_path'       => OWPM_FS::root_dir(),
			'user_login'     => $current_user ? $current_user->user_login : '',
		);

		if ( ! $this->write_session( $file_id, $session ) ) {
			wp_send_json_error( __( 'Could not create the import session file. Check permissions on wp-content/uploads.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		wp_send_json_success(
			array(
				'import_key'    => $import_key,
				'total_entries' => $total_entries,
				'source_url'    => $session['old_url'],
				'target_url'    => $session['new_url'],
				'prefix_change' => ( $session['old_prefix'] && $session['old_prefix'] !== $session['new_prefix'] ),
				'notices'       => $this->compatibility_notices( $metadata ),
			)
		);
	}

	/**
	 * Non-blocking warnings about differences between the two installs.
	 *
	 * A database from a newer WordPress carries a newer schema and db_version.
	 * WordPress upgrades a database forward automatically, but never downgrades
	 * it, so restoring a newer dump onto older core can leave the site broken in
	 * ways that are hard to diagnose.
	 */
	private function compatibility_notices( $metadata ) {
		$notices = array();

		$source_wp = isset( $metadata['wp_version'] ) ? (string) $metadata['wp_version'] : '';
		$target_wp = get_bloginfo( 'version' );

		if ( $source_wp && version_compare( $source_wp, $target_wp, '>' ) ) {
			$notices[] = sprintf(
				/* translators: 1: source WordPress version, 2: this site's WordPress version. */
				__( 'This backup came from WordPress %1$s, but this site runs %2$s. Update this site to %1$s or newer before importing, otherwise the restored database will be newer than the WordPress files running it.', 'open-migration-unlimited-site-transfer-backup' ),
				$source_wp,
				$target_wp
			);
		}

		$source_php = isset( $metadata['php_version'] ) ? (string) $metadata['php_version'] : '';

		if ( $source_php && version_compare( $source_php, PHP_VERSION, '>' ) ) {
			$notices[] = sprintf(
				/* translators: 1: source PHP version, 2: this server's PHP version. */
				__( 'The source site ran PHP %1$s and this server runs %2$s. Some of the restored themes or plugins may require the newer version.', 'open-migration-unlimited-site-transfer-backup' ),
				$source_php,
				PHP_VERSION
			);
		}

		return $notices;
	}

	/* ---------------------------------------------------------------------
	 * Step 3: extract (resumable)
	 * ------------------------------------------------------------------ */

	public function ajax_extract() {
		$file_id = $this->request_file_id();

		if ( ! $this->authorize( $file_id ) ) {
			$this->deny();
		}

		OWPM_FS::raise_limits();

		$session = $this->read_session( $file_id );
		if ( ! $session ) {
			wp_send_json_error( __( 'The import session has expired. Please start again.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $session['zip_path'] ) ) {
			wp_send_json_error( __( 'The backup archive could not be opened.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$total     = (int) $session['total_entries'];
		$index     = (int) $session['extract_offset'];
		$started   = microtime( true );
		$budget    = OWPM_FS::batch_time_budget();
		$last      = '';
		$iteration = 0;

		while ( $index < $total ) {
			// Checked at the top so the early `continue` paths below -- directory
			// entries, entries rejected by map_entry(), failed extractions -- cannot
			// bypass it. A large archive has many of those, and skipping the check
			// would let a batch run past its budget and lose all its progress if the
			// request were killed before the session is written below.
			if ( $iteration++ > 0 && ( microtime( true ) - $started ) > $budget ) {
				break;
			}

			$stat = $zip->statIndex( $index );
			++$index;

			if ( ! $stat || ! isset( $stat['name'] ) ) {
				continue;
			}

			$name = $stat['name'];
			$dest = $this->map_entry( $name, $session );

			if ( false === $dest ) {
				$session['skipped'] = (int) $session['skipped'] + 1;
				continue;
			}

			// Directory entry.
			if ( '/' === substr( $name, -1 ) ) {
				wp_mkdir_p( $dest );
				continue;
			}

			if ( ! $this->extract_entry( $zip, $name, $dest ) ) {
				$session['skipped'] = (int) $session['skipped'] + 1;
				$this->add_error( $session, sprintf( /* translators: %s: file name inside the archive. */ __( 'Could not extract %s', 'open-migration-unlimited-site-transfer-backup' ), $name ) );
				continue;
			}

			$session['extracted'] = (int) $session['extracted'] + 1;
			$last                 = $name;
		}

		$zip->close();

		$session['extract_offset'] = $index;
		$this->write_session( $file_id, $session );

		$complete = ( $index >= $total );
		$progress = $total > 0 ? (int) round( ( $index / $total ) * 100 ) : 100;

		wp_send_json_success(
			array(
				'status'       => $complete ? 'complete' : 'processing',
				'progress'     => $complete ? 100 : min( 99, $progress ),
				'current_file' => $complete ? __( 'Done', 'open-migration-unlimited-site-transfer-backup' ) : $last,
				'extracted'    => (int) $session['extracted'],
				'skipped'      => (int) $session['skipped'],
			)
		);
	}

	/**
	 * Translate an archive entry name into an absolute destination path.
	 *
	 * This is the zip-slip guard: anything absolute, containing "..", or outside the
	 * small whitelist of expected roots is rejected outright rather than sanitised.
	 *
	 * @return string|false Absolute path, or false to skip the entry.
	 */
	private function map_entry( $name, $session ) {
		$name = str_replace( '\\', '/', (string) $name );

		if ( '' === $name || false !== strpos( $name, "\0" ) ) {
			return false;
		}

		// Absolute paths and Windows drive letters.
		if ( '/' === $name[0] || preg_match( '#^[A-Za-z]:/#', $name ) ) {
			return false;
		}

		foreach ( explode( '/', $name ) as $segment ) {
			if ( '..' === $segment ) {
				return false;
			}
		}

		$source_content = trim( (string) $session['source_content'], '/' );
		if ( '' === $source_content ) {
			$source_content = 'wp-content';
		}

		$work_dir = trailingslashit( $session['work_dir'] );

		if ( 'database.sql' === $name ) {
			return $work_dir . 'database.sql';
		}

		if ( 'metadata.json' === $name ) {
			return $work_dir . 'metadata.json';
		}

		// Uploads that lived outside the content directory on the source. They are
		// restored into whatever wp_upload_dir() reports here, so an archive from a
		// relocated-uploads install lands correctly on a standard one and the other
		// way round.
		$external = OWPM_FS::EXTERNAL_UPLOADS_ENTRY . '/';

		if ( 0 === strpos( $name, $external ) ) {
			$relative = substr( $name, strlen( $external ) );
			$uploads  = OWPM_FS::uploads_dir();

			if ( '' === $relative || '' === $uploads ) {
				return false;
			}

			// Never restore a backup folder on top of the one we are reading from.
			if ( 0 === strpos( $relative, OWPM_FS::BACKUP_DIR_NAME . '/' ) ) {
				return false;
			}

			$dest = $uploads . $relative;

			if ( ! OWPM_FS::is_inside( $dest, $uploads ) ) {
				return false;
			}

			return $dest;
		}

		if ( 0 !== strpos( $name, $source_content . '/' ) ) {
			return false;
		}

		$relative = substr( $name, strlen( $source_content ) + 1 );

		if ( '' === $relative ) {
			return false;
		}

		// Never let the archive overwrite the running plugin or our own working
		// files. The mu-plugins entry is legacy: versions before 1.2.0 created that
		// loader, so archives made by them can still contain it.
		$protected = array(
			'plugins/' . basename( untrailingslashit( OWPM_PLUGIN_DIR ) ) . '/',
			'uploads/' . OWPM_FS::BACKUP_DIR_NAME . '/',
			'mu-plugins/owpm-import-loader.php',
		);

		foreach ( $protected as $skip ) {
			if ( 0 === strpos( $relative, $skip ) || rtrim( $skip, '/' ) === $relative ) {
				return false;
			}
		}

		$content = OWPM_FS::content_dir();
		$dest    = $content . $relative;

		// Belt and braces: confirm the resolved path really is under wp-content.
		if ( ! OWPM_FS::is_inside( $dest, $content ) ) {
			return false;
		}

		return $dest;
	}

	/**
	 * Stream one entry out of the archive without loading it into memory.
	 */
	private function extract_entry( $zip, $name, $dest ) {
		$dir = dirname( $dest );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$source = $zip->getStream( $name );
		if ( ! $source ) {
			return false;
		}

		$target = @fopen( $dest, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $target ) {
			fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}

		$copied = stream_copy_to_stream( $source, $target );

		fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return false !== $copied;
	}

	private function add_error( &$session, $message ) {
		if ( ! isset( $session['errors'] ) || ! is_array( $session['errors'] ) ) {
			$session['errors'] = array();
		}

		// Keep the log bounded so the session file cannot grow without limit.
		if ( count( $session['errors'] ) < 50 ) {
			$session['errors'][] = $message;
		}
	}

	/* ---------------------------------------------------------------------
	 * Step 4: database restore (resumable)
	 * ------------------------------------------------------------------ */

	public function ajax_db() {
		$file_id = $this->request_file_id();

		if ( ! $this->authorize( $file_id ) ) {
			$this->deny();
		}

		OWPM_FS::raise_limits();

		global $wpdb;

		$session = $this->read_session( $file_id );
		if ( ! $session ) {
			wp_send_json_error( __( 'The import session has expired. Please start again.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$sql_file = trailingslashit( $session['work_dir'] ) . 'database.sql';

		if ( ! file_exists( $sql_file ) ) {
			wp_send_json_error( __( 'The database dump is missing from the archive.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$size   = (int) filesize( $sql_file );
		$handle = @fopen( $sql_file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			wp_send_json_error( __( 'The database dump could not be read.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		// Detect the dump format once, so dumps from older versions still import.
		if ( 0 === (int) $session['sql_offset'] ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$probe               = fread( $handle, 65536 );
			$session['sql_mode'] = ( false !== strpos( (string) $probe, self::SQL_DELIMITER ) ) ? 'marker' : 'semicolon';
			rewind( $handle );
		}

		fseek( $handle, (int) $session['sql_offset'] );

		$wpdb->hide_errors();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );

		$started = microtime( true );
		$budget  = OWPM_FS::batch_time_budget();
		$buffer  = '';
		$marker  = ( 'marker' === $session['sql_mode'] );

		while ( true ) {
			$line = fgets( $handle );

			if ( false === $line ) {
				// Flush any trailing statement that had no delimiter.
				if ( '' !== trim( $buffer ) ) {
					$this->run_statement( trim( $buffer ), $session );
					$session['statements'] = (int) $session['statements'] + 1;
				}
				$session['sql_offset'] = $size;
				break;
			}

			$is_end = $marker
				? ( self::SQL_DELIMITER === rtrim( $line, "\r\n" ) )
				: ( ';' === substr( rtrim( $line, "\r\n" ), -1 ) );

			if ( ! $marker ) {
				$buffer .= $line;
			} elseif ( ! $is_end ) {
				$buffer .= $line;
			}

			if ( ! $is_end ) {
				continue;
			}

			$statement  = trim( $buffer );
			$buffer     = '';
			$is_options = false;

			if ( '' !== $statement ) {
				$is_options = $this->targets_options_table( $statement, $session );

				$this->run_statement( $statement, $session );
				$session['statements'] = (int) $session['statements'] + 1;
			}

			$session['sql_offset'] = ftell( $handle );

			// Never yield in the middle of the options table. Between its DROP and
			// its last INSERT the site has no active_plugins value, so a request that
			// stopped there would come back with this plugin unloaded and no AJAX
			// handlers registered to finish the import. Exports put the options table
			// last precisely so this window is small.
			if ( $is_options ) {
				continue;
			}

			if ( ( microtime( true ) - $started ) > $budget ) {
				break;
			}
		}

		$complete = ( (int) $session['sql_offset'] >= $size ) || feof( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $complete ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

			$session['sql_offset'] = $size;
			$session['db_done']    = 1;

			// Everything below must happen in this same request: the moment the
			// options table came from the old site, this install is pointing at the
			// old URL and may not even have this plugin marked active.
			$this->post_restore_repair( $session );

			// The restored users table is now in place, so sign the operator back
			// in immediately. The remaining steps then authenticate normally.
			$fresh_nonce = $this->refresh_session( $session );
		}

		$this->write_session( $file_id, $session );

		$progress = $size > 0 ? (int) round( ( (int) $session['sql_offset'] / $size ) * 100 ) : 100;

		wp_send_json_success(
			array(
				'status'     => $complete ? 'complete' : 'processing',
				'progress'   => $complete ? 100 : min( 99, $progress ),
				'statements' => (int) $session['statements'],
				'errors'     => count( (array) $session['errors'] ),
				'nonce'      => isset( $fresh_nonce ) ? $fresh_nonce : '',
			)
		);
	}

	/**
	 * Does this statement operate on the options table?
	 *
	 * Checked against both the source and destination prefixes, because the
	 * statement has not been rewritten yet when this runs.
	 */
	private function targets_options_table( $statement, $session ) {
		foreach ( array( (string) $session['new_prefix'], (string) $session['old_prefix'] ) as $prefix ) {
			if ( '' === $prefix ) {
				continue;
			}

			if ( false !== stripos( $statement, '`' . $prefix . 'options`' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Execute one dump statement, rewriting the table prefix when it differs.
	 */
	private function run_statement( $statement, &$session ) {
		global $wpdb;

		$statement = rtrim( trim( $statement ), ';' );

		if ( '' === $statement || 0 === strpos( $statement, '--' ) || 0 === strpos( $statement, '/*' ) ) {
			return;
		}

		$statement = $this->rewrite_prefix( $statement, $session );

		// A database dump is by definition raw SQL that has to be replayed verbatim;
		// there is no placeholder form for it. The file is one this plugin wrote, and
		// only an administrator who passed the capability check can start an import.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $wpdb->query( $statement );

		if ( false !== $result || '' === $wpdb->last_error ) {
			return;
		}

		$error = $wpdb->last_error;

		// Older MySQL/MariaDB often rejects utf8mb4_0900_* collations from MySQL 8.
		if ( false !== stripos( $error, 'collation' ) || false !== stripos( $error, 'character set' ) ) {
			$retry = $this->downgrade_charset( $statement );

			if ( $retry !== $statement ) {
				// Same dump statement, retried with a charset this server supports.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$result = $wpdb->query( $retry );

				if ( false !== $result ) {
					return;
				}

				$error = $wpdb->last_error;
			}
		}

		$this->add_error( $session, substr( $error . ' — ' . substr( $statement, 0, 120 ), 0, 300 ) );
	}

	/**
	 * Swap the source site's table prefix for this install's prefix.
	 */
	private function rewrite_prefix( $statement, $session ) {
		$old = (string) $session['old_prefix'];
		$new = (string) $session['new_prefix'];

		if ( '' === $old || $old === $new ) {
			return $statement;
		}

		$pattern = '/^(DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?|CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?|INSERT\s+INTO\s+|REPLACE\s+INTO\s+|ALTER\s+TABLE\s+|LOCK\s+TABLES\s+|TRUNCATE\s+TABLE\s+)`'
			. preg_quote( $old, '/' ) . '/i';

		// Callback form so a prefix containing $ or \ cannot corrupt the replacement.
		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $new ) {
				return $matches[1] . '`' . $new;
			},
			$statement,
			1
		);
	}

	/**
	 * Rewrite a CREATE TABLE to charset/collation this server definitely supports.
	 */
	private function downgrade_charset( $statement ) {
		global $wpdb;

		$charset = $wpdb->charset ? $wpdb->charset : 'utf8mb4';
		$collate = $wpdb->collate ? $wpdb->collate : $charset . '_unicode_ci';

		$statement = preg_replace( '/COLLATE[= ]+[A-Za-z0-9_]+/i', 'COLLATE=' . $collate, $statement );
		$statement = preg_replace( '/(DEFAULT\s+)?CHARSET[= ]+[A-Za-z0-9_]+/i', 'DEFAULT CHARSET=' . $charset, $statement );
		$statement = preg_replace( '/CHARACTER SET [A-Za-z0-9_]+/i', 'CHARACTER SET ' . $charset, $statement );

		return $statement;
	}

	/**
	 * Immediately after the restore: make this install usable again.
	 *
	 * Runs in the same request as the last SQL batch, because until siteurl and
	 * active_plugins are corrected the site is pointing at the source domain.
	 */
	private function post_restore_repair( &$session ) {
		global $wpdb;

		$new_prefix = (string) $session['new_prefix'];
		$old_prefix = (string) $session['old_prefix'];

		$options  = OWPM_FS::esc_table( $new_prefix . 'options' );
		$usermeta = OWPM_FS::esc_table( $new_prefix . 'usermeta' );

		// Option and meta keys embed the prefix (e.g. wp_user_roles, wp_capabilities).
		if ( $old_prefix && $old_prefix !== $new_prefix ) {
			if ( $options ) {
				// Table name comes from esc_table(), which rejects anything that is not a bare
				// identifier; every value below is bound through prepare().
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$options} SET option_name = CONCAT(%s, SUBSTRING(option_name, %d)) WHERE option_name LIKE %s",
						$new_prefix,
						strlen( $old_prefix ) + 1,
						$wpdb->esc_like( $old_prefix ) . '%'
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			}

			if ( $usermeta ) {
				// Table name comes from esc_table(), which rejects anything that is not a bare
				// identifier; every value below is bound through prepare().
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$usermeta} SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s",
						$new_prefix,
						strlen( $old_prefix ) + 1,
						$wpdb->esc_like( $old_prefix ) . '%'
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			}
		}

		if ( ! $options ) {
			return;
		}

		// Remember what the source site thought its URL was; metadata may be absent.
		// Table name comes from esc_table(), which rejects anything that is not a bare
				// identifier; every value below is bound through prepare().
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$restored_site = $wpdb->get_var( "SELECT option_value FROM {$options} WHERE option_name = 'siteurl' LIMIT 1" );
		// Table name comes from esc_table(), which rejects anything that is not a bare
				// identifier; every value below is bound through prepare().
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$restored_home = $wpdb->get_var( "SELECT option_value FROM {$options} WHERE option_name = 'home' LIMIT 1" );

		if ( $restored_site ) {
			$session['old_url'] = untrailingslashit( $restored_site );
		}
		if ( $restored_home ) {
			$session['old_home'] = untrailingslashit( $restored_home );
		} elseif ( ! empty( $session['old_url'] ) ) {
			$session['old_home'] = $session['old_url'];
		}

		// Point the install back at this domain right away.
		$this->set_raw_option( $options, 'siteurl', $session['new_url'] );
		$this->set_raw_option( $options, 'home', $session['new_home'] );

		// A custom upload_path from the source would move wp_upload_dir() out from
		// under our own session files, so reset it to the WordPress default.
		$this->set_raw_option( $options, 'upload_path', '' );
		$this->set_raw_option( $options, 'upload_url_path', '' );

		// The source site's active_plugins may not include this plugin.
		$this->ensure_self_active( $options );

		wp_cache_flush();
	}

	/**
	 * The value this options table uses to mean "autoload this".
	 *
	 * WordPress 6.6 replaced 'yes'/'no' in the autoload column with 'on'/'off'.
	 * Core still reads the legacy values, but hardcoding either one would be wrong
	 * in one direction or the other. Since this runs against a table that has just
	 * been restored from another site, the correct answer is whatever that data
	 * already uses -- so read it rather than assume.
	 */
	private function autoload_value( $options_table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$existing = $wpdb->get_var( "SELECT autoload FROM {$options_table} WHERE autoload IN ('on','yes') LIMIT 1" );

		if ( 'on' === $existing || 'yes' === $existing ) {
			return $existing;
		}

		// Empty table: 'yes' is understood by every version of WordPress to date,
		// including those that write 'on'.
		return 'yes';
	}

	private function set_raw_option( $options_table, $name, $value ) {
		global $wpdb;

		// Table name comes from esc_table(), which rejects anything that is not a bare
				// identifier; every value below is bound through prepare().
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$options_table} WHERE option_name = %s LIMIT 1", $name ) );

		if ( $exists ) {
			// Table name comes from esc_table(), which rejects anything that is not a bare
				// identifier; every value below is bound through prepare().
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query( $wpdb->prepare( "UPDATE {$options_table} SET option_value = %s WHERE option_name = %s", $value, $name ) );
		} else {
			// Table name comes from esc_table(), which rejects anything that is not a
			// bare identifier; every value is bound through prepare().
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$options_table} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
					$name,
					$value,
					$this->autoload_value( $options_table )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}
	}

	private function ensure_self_active( $options_table ) {
		global $wpdb;

		$self = plugin_basename( OWPM_PLUGIN_FILE );

		// Table name comes from esc_table(), which rejects anything that is not a bare
				// identifier; every value below is bound through prepare().
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$options_table} WHERE option_name = %s LIMIT 1", 'active_plugins' ) );

		$active = $raw ? maybe_unserialize( $raw ) : array();
		if ( ! is_array( $active ) ) {
			$active = array();
		}

		if ( ! in_array( $self, $active, true ) ) {
			$active[] = $self;
			$this->set_raw_option( $options_table, 'active_plugins', serialize( $active ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		}
	}

	/* ---------------------------------------------------------------------
	 * Step 5: automatic URL / path conversion (resumable)
	 * ------------------------------------------------------------------ */

	/**
	 * Rewrite every reference to the old site so the new install is self-consistent.
	 *
	 * This is the step that makes "upload the old zip to a fresh WordPress and it
	 * just works" true: the restored database still points at the source domain and
	 * source server path until this runs.
	 */
	public function ajax_replace() {
		$file_id = $this->request_file_id();

		if ( ! $this->authorize( $file_id ) ) {
			$this->deny();
		}

		OWPM_FS::raise_limits();

		$session = $this->read_session( $file_id );
		if ( ! $session ) {
			wp_send_json_error( __( 'The import session has expired. Please start again.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		// Build the pair list once, after the restore, so it uses the real old URL.
		if ( ! isset( $session['replacements'] ) ) {
			$session['replacements'] = $this->build_replacements( $session );
			$session['rep_index']    = 0;
			$session['rep_rows']     = 0;
			$session['rep_state']    = array(
				'table_index' => 0,
				'offset'      => 0,
				'rows'        => 0,
				'done'        => false,
			);
		}

		$pairs = (array) $session['replacements'];
		$total = count( $pairs );

		if ( 0 === $total ) {
			$session['rep_index'] = 0;
			$this->write_session( $file_id, $session );

			wp_send_json_success(
				array(
					'status'   => 'complete',
					'progress' => 100,
					'rows'     => 0,
					'current'  => __( 'No URL changes were needed.', 'open-migration-unlimited-site-transfer-backup' ),
				)
			);
		}

		$tables  = OWPM_FS::get_site_tables();
		$started = microtime( true );
		$budget  = OWPM_FS::batch_time_budget();
		$index   = (int) $session['rep_index'];

		while ( $index < $total ) {
			$pair  = $pairs[ $index ];
			$state = $session['rep_state'];

			$state = OWPM_Search_Replace::run_batch( $pair[0], $pair[1], $tables, $state, $budget );

			if ( ! empty( $state['done'] ) ) {
				$session['rep_rows'] = (int) $session['rep_rows'] + (int) $state['rows'];
				++$index;
				$session['rep_state'] = array(
					'table_index' => 0,
					'offset'      => 0,
					'rows'        => 0,
					'done'        => false,
				);
			} else {
				$session['rep_state'] = $state;
			}

			if ( ( microtime( true ) - $started ) > $budget ) {
				break;
			}
		}

		$session['rep_index'] = $index;
		$this->write_session( $file_id, $session );

		$complete = ( $index >= $total );
		$progress = (int) round( ( $index / max( 1, $total ) ) * 100 );

		wp_send_json_success(
			array(
				'status'   => $complete ? 'complete' : 'processing',
				'progress' => $complete ? 100 : min( 99, $progress ),
				'rows'     => (int) $session['rep_rows'],
				'current'  => $complete
					? __( 'URL rewrite complete.', 'open-migration-unlimited-site-transfer-backup' )
					: sprintf( /* translators: 1: current pass, 2: total passes. */ __( 'Rewriting references (pass %1$d of %2$d)', 'open-migration-unlimited-site-transfer-backup' ), $index + 1, $total ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Step 6: finalise — options, caches, cleanup
	 * ------------------------------------------------------------------ */

	public function ajax_finalize() {
		$file_id = $this->request_file_id();

		if ( ! $this->authorize( $file_id ) ) {
			$this->deny();
		}

		OWPM_FS::raise_limits();

		$session = $this->read_session( $file_id );
		if ( ! $session ) {
			wp_send_json_error( __( 'The import session has expired. Please start again.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$replaced = isset( $session['rep_rows'] ) ? (int) $session['rep_rows'] : 0;

		// Make sure the canonical options survived the replace pass.
		update_option( 'siteurl', $session['new_url'] );
		update_option( 'home', ! empty( $session['new_home'] ) ? $session['new_home'] : $session['new_url'] );
		update_option( 'upload_path', '' );
		update_option( 'upload_url_path', '' );

		delete_option( 'owpm_current_export' );

		// Remember where this site came from. If the import ran against a host's
		// temporary address, the Change Domain tab offers this back as a one-click
		// suggestion instead of making the user retype a domain from memory.
		update_option(
			'owpm_last_import',
			array(
				'source_url' => (string) $session['old_url'],
				'target_url' => (string) $session['new_url'],
				'when'       => time(),
			),
			false
		);

		// Rebuild caches and rewrite rules for the new domain.
		wp_cache_flush();
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}

		$relogin = is_user_logged_in() ? true : $this->restore_login( $session );

		// Cleanup: archive, working directory, session and the temporary mu-plugin.
		OWPM_FS::delete( $session['zip_path'] );
		OWPM_FS::delete( $session['work_dir'] );

		$errors = array_values( (array) $session['errors'] );
		$this->delete_session( $file_id );

		wp_send_json_success(
			array(
				'old_url'       => $session['old_url'],
				'new_url'       => $session['new_url'],
				'rows_replaced' => $replaced,
				'files'         => (int) $session['extracted'],
				'statements'    => (int) $session['statements'],
				'errors'        => $errors,
				'relogin'       => $relogin,
			)
		);
	}

	/**
	 * Every old→new string pair worth rewriting for this import.
	 */
	private function build_replacements( $session ) {
		return OWPM_Search_Replace::build_url_pairs(
			isset( $session['old_url'] ) ? $session['old_url'] : '',
			isset( $session['new_url'] ) ? $session['new_url'] : '',
			isset( $session['old_home'] ) ? $session['old_home'] : '',
			isset( $session['new_home'] ) ? $session['new_home'] : '',
			isset( $session['old_path'] ) ? $session['old_path'] : '',
			isset( $session['new_path'] ) ? $session['new_path'] : ''
		);
	}

	/**
	 * Log the operator back in if their account still exists and is an admin.
	 *
	 * Restoring the database replaces wp_users, which invalidates the current
	 * session. Only re-issue a cookie for a user who genuinely has manage_options
	 * in the restored data.
	 */
	private function restore_login( $session ) {
		if ( empty( $session['user_login'] ) ) {
			return false;
		}

		$user = get_user_by( 'login', $session['user_login'] );

		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			return false;
		}

		// wp_set_auth_cookie() only sends headers; $_COOKIE still holds the old
		// value, so wp_get_session_token() -- and therefore any nonce created
		// afterwards -- would refer to a session that no longer exists. Mirroring
		// the new cookie into $_COOKIE keeps this request consistent with what the
		// browser is about to store.
		$capture = function ( $cookie ) {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
		};

		add_action( 'set_logged_in_cookie', $capture );

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false );

		remove_action( 'set_logged_in_cookie', $capture );

		return true;
	}

	/**
	 * Sign the operator back in as soon as the restored users table allows it.
	 *
	 * Without this, every step after the database restore has to fall back to the
	 * one-time key, because the login cookie stopped validating the moment
	 * wp_users was replaced. Re-establishing the session here means the remaining
	 * steps authenticate normally, with a nonce and a capability check, and the
	 * key is only ever needed when the operator's account does not exist in the
	 * restored data at all.
	 *
	 * @return string Fresh nonce for the browser to use, or '' if we could not
	 *                re-authenticate and the key must carry the rest.
	 */
	private function refresh_session( $session ) {
		if ( ! $this->restore_login( $session ) ) {
			return '';
		}

		// Created after wp_set_current_user(), so it is bound to the new session.
		return wp_create_nonce( 'owpm_nonce' );
	}

	/* ---------------------------------------------------------------------
	 * Abort / cleanup
	 * ------------------------------------------------------------------ */

	public function ajax_abort() {
		$file_id = $this->request_file_id();

		if ( ! $this->authorize( $file_id ) ) {
			$this->deny();
		}

		$session = $this->read_session( $file_id );

		if ( $session ) {
			OWPM_FS::delete( $session['zip_path'] );
			OWPM_FS::delete( $session['work_dir'] );
		}

		$this->delete_session( $file_id );

		wp_send_json_success( __( 'Import cancelled.', 'open-migration-unlimited-site-transfer-backup' ) );
	}
}
