<?php
/**
 * Export: database dump + wp-content archive, built in resumable batches.
 *
 * @package Open_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OWPM_Export {

	/** Marker written between statements in the SQL dump. */
	const SQL_DELIMITER = '/* OWPM_EOS */';

	/** Option holding the in-progress export state. */
	const STATE_OPTION = 'owpm_current_export';

	private $temp_dir;

	public function __construct() {
		$this->temp_dir = OWPM_FS::backup_dir();

		add_action( 'wp_ajax_owpm_start_export', array( $this, 'ajax_start_export' ) );
		add_action( 'wp_ajax_owpm_export_db', array( $this, 'ajax_export_db' ) );
		add_action( 'wp_ajax_owpm_export_files', array( $this, 'ajax_export_files' ) );
		add_action( 'wp_ajax_owpm_finish_export', array( $this, 'ajax_finish_export' ) );
		add_action( 'wp_ajax_owpm_get_backups', array( $this, 'ajax_get_backups' ) );
		add_action( 'wp_ajax_owpm_delete_backup', array( $this, 'ajax_delete_backup' ) );

		// Backups are streamed through PHP so the directory can stay fully locked down.
		add_action( 'admin_post_owpm_download_backup', array( $this, 'handle_download' ) );
	}

	/**
	 * Shared nonce + capability gate for every export endpoint.
	 */
	private function guard() {
		check_ajax_referer( 'owpm_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do that.', 'open-migration-unlimited-site-transfer-backup' ), 403 );
		}

		OWPM_FS::raise_limits();
	}

	/**
	 * Load the export state, or bail with a clean error.
	 */
	private function require_state() {
		$state = get_option( self::STATE_OPTION );

		if ( ! is_array( $state ) || empty( $state['id'] ) || empty( $state['path'] ) || empty( $state['zip_file'] ) ) {
			wp_send_json_error( __( 'No export is currently in progress. Please start a new export.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		return $state;
	}

	/* ---------------------------------------------------------------------
	 * Step 1: initialise
	 * ------------------------------------------------------------------ */

	public function ajax_start_export() {
		$this->guard();

		if ( is_multisite() ) {
			wp_send_json_error( __( 'Multisite networks are not supported yet. Please export a single site install.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_send_json_error( __( 'The PHP ZipArchive extension is required but is not enabled on this server.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		// Clear any half-finished export so we never mix two runs together.
		$previous = get_option( self::STATE_OPTION );
		if ( is_array( $previous ) && ! empty( $previous['path'] ) && OWPM_FS::is_inside( $previous['path'], $this->temp_dir ) ) {
			OWPM_FS::delete( $previous['path'] );
		}
		delete_option( self::STATE_OPTION );

		OWPM_FS::init_backup_dir();

		// 32 random chars keeps the archive name unguessable even if a host ignores .htaccess.
		$backup_id   = 'backup_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 32, false );
		$export_path = $this->temp_dir . $backup_id;

		if ( ! wp_mkdir_p( $export_path ) ) {
			wp_send_json_error( __( 'Could not create the backup working directory. Check permissions on wp-content/uploads.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$tables = OWPM_FS::get_site_tables();
		if ( empty( $tables ) ) {
			OWPM_FS::delete( $export_path );
			wp_send_json_error( __( 'No database tables were found for this site.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$tables = $this->order_tables_for_restore( $tables );

		// Walk wp-content once and cache the result, instead of re-scanning per batch.
		$manifest    = $export_path . '/manifest.txt';
		$total_files = $this->build_manifest( $manifest );

		if ( false === $total_files ) {
			OWPM_FS::delete( $export_path );
			wp_send_json_error( __( 'Could not read the wp-content directory.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		update_option(
			self::STATE_OPTION,
			array(
				'id'              => $backup_id,
				'path'            => $export_path,
				'zip_file'        => $this->temp_dir . $backup_id . '.zip',
				'sql_file'        => $export_path . '/database.sql',
				'manifest'        => $manifest,
				'db_tables'       => $tables,
				'db_table_index'  => 0,
				'db_row_offset'   => 0,
				'db_order_by'     => '',
				'db_where'        => '',
				'db_rows_limit'   => 500,
				'manifest_offset' => 0,
				'files_done'      => 0,
				'total_files'     => $total_files,
				'skipped_files'   => 0,
				'started'         => time(),
			),
			false
		);

		wp_send_json_success(
			array(
				'backup_id'    => $backup_id,
				'total_files'  => $total_files,
				'total_tables' => count( $tables ),
			)
		);
	}

	/**
	 * How many rows to fetch at once from a given table.
	 *
	 * A fixed row count is dangerous: 500 rows of a page-builder `posts` table can
	 * be hundreds of megabytes in a single result set. Sizing the batch from the
	 * table's average row length keeps each fetch to roughly a fixed number of
	 * bytes instead.
	 */
	private function rows_per_batch( $table ) {
		global $wpdb;

		$avg = 0;

		if ( defined( 'DB_NAME' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$avg = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT AVG_ROW_LENGTH FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s LIMIT 1',
					DB_NAME,
					$table
				)
			);
		}

		if ( $avg < 1 ) {
			return 500;
		}

		/**
		 * Filter the approximate bytes fetched per database batch.
		 *
		 * @param int $bytes Default 2MB.
		 */
		$target = (int) apply_filters( 'owpm_export_batch_bytes', 2097152 );

		return max( 1, min( 500, (int) floor( $target / $avg ) ) );
	}

	/**
	 * Optional WHERE clause for a table's rows.
	 *
	 * Transients are a cache, not content. Exporting them bloats the archive and
	 * restores the source site's stale cached data onto the destination, including
	 * its update checks. The underscores are escaped so LIKE treats them literally.
	 */
	private function row_filter( $table ) {
		global $wpdb;

		if ( $table === $wpdb->prefix . 'options' ) {
			return " WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'";
		}

		return '';
	}

	/**
	 * Put the options table last in the dump.
	 *
	 * The destination replays the dump in this order, and `active_plugins` lives in
	 * the options table. Restoring it early would swap in the source site's plugin
	 * list part-way through, potentially deactivating this plugin and leaving the
	 * remaining import steps with no AJAX handlers registered. Keeping it last means
	 * the destination's own options stay intact until the very end of the restore.
	 *
	 * @param string[] $tables Table names.
	 * @return string[]
	 */
	private function order_tables_for_restore( $tables ) {
		global $wpdb;

		$options_table = $wpdb->prefix . 'options';
		$rest          = array();
		$last          = array();

		foreach ( $tables as $table ) {
			if ( $table === $options_table ) {
				$last[] = $table;
			} else {
				$rest[] = $table;
			}
		}

		return array_merge( $rest, $last );
	}

	/**
	 * Write every exportable file path to a manifest file.
	 *
	 * Normally that is everything under the content directory. When uploads have
	 * been relocated outside it, they are walked as a second root -- otherwise the
	 * backup would silently contain no media at all.
	 *
	 * @return int|false Number of files, or false on failure.
	 */
	private function build_manifest( $manifest_path ) {
		$handle = @fopen( $manifest_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return false;
		}

		$count = 0;
		$roots = array( OWPM_FS::content_dir() );

		$external_uploads = OWPM_FS::external_uploads_dir();
		if ( '' !== $external_uploads ) {
			$roots[] = $external_uploads;
		}

		try {
			foreach ( $roots as $root ) {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveCallbackFilterIterator(
						new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ),
						array( $this, 'manifest_filter' )
					),
					RecursiveIteratorIterator::LEAVES_ONLY,
					RecursiveIteratorIterator::CATCH_GET_CHILD
				);

				foreach ( $iterator as $file ) {
					if ( ! $file->isFile() || $file->isLink() ) {
						continue;
					}

					fwrite( $handle, $file->getPathname() . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					++$count;
				}
			}
		} catch ( Exception $e ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $count;
	}

	/**
	 * Decide whether a path belongs in the archive.
	 *
	 * Excluding the backup directory is critical: it lives inside wp-content, so
	 * without this the archive would recursively swallow itself and every previous
	 * backup on the server.
	 *
	 * @param SplFileInfo $current Current item.
	 */
	public function manifest_filter( $current ) {
		$path = str_replace( '\\', '/', $current->getPathname() );

		$content = untrailingslashit( OWPM_FS::content_dir() );

		$excluded = array(
			rtrim( str_replace( '\\', '/', $this->temp_dir ), '/' ),
			$content . '/cache',
			$content . '/upgrade',
			$content . '/upgrade-temp-backup',
			$content . '/ai1wm-backups',
			$content . '/updraft',
		);

		/**
		 * Filter absolute directory paths excluded from the export.
		 *
		 * @param string[] $excluded Directories to skip.
		 */
		$excluded = apply_filters( 'owpm_export_excluded_dirs', $excluded );

		foreach ( $excluded as $skip ) {
			$skip = rtrim( str_replace( '\\', '/', $skip ), '/' );
			if ( $path === $skip || 0 === strpos( $path, $skip . '/' ) ) {
				return false;
			}
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Step 2: database dump (resumable across requests)
	 * ------------------------------------------------------------------ */

	public function ajax_export_db() {
		$this->guard();

		global $wpdb;

		$state = $this->require_state();

		$tables      = isset( $state['db_tables'] ) ? (array) $state['db_tables'] : array();
		$table_index = (int) $state['db_table_index'];
		$row_offset  = (int) $state['db_row_offset'];
		$total       = count( $tables );

		if ( $table_index >= $total ) {
			wp_send_json_success(
				array(
					'status'   => 'complete',
					'progress' => 100,
					'current'  => __( 'Database export complete.', 'open-migration-unlimited-site-transfer-backup' ),
				)
			);
		}

		$handle = @fopen( $state['sql_file'], 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			wp_send_json_error( __( 'Could not write the database dump file.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$started   = microtime( true );
		$budget    = OWPM_FS::batch_time_budget();
		$current   = '';
		$iteration = 0;

		while ( $table_index < $total ) {
			// Checked at the top of the loop so that the early `continue` paths
			// below (unusable table name, missing CREATE statement) cannot bypass
			// it and run the request past its budget without ever yielding.
			if ( $iteration++ > 0 && ( microtime( true ) - $started ) > $budget ) {
				break;
			}

			$table         = $tables[ $table_index ];
			$escaped_table = OWPM_FS::esc_table( $table );
			$current       = $table;

			if ( ! $escaped_table ) {
				++$table_index;
				$row_offset = 0;
				continue;
			}

			// Table header is written once, before the first row batch.
			if ( 0 === $row_offset ) {
				// $escaped_table comes from esc_table(), which returns false for anything that
				// is not a bare identifier. Table names cannot be bound as prepare() values.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$create = $wpdb->get_row( "SHOW CREATE TABLE {$escaped_table}", ARRAY_N );

				if ( empty( $create[1] ) ) {
					++$table_index;
					continue;
				}

				$this->write_statement( $handle, "DROP TABLE IF EXISTS {$escaped_table}" );
				$this->write_statement( $handle, $create[1] );

				// Resolve these once per table, not once per batch.
				$state['db_order_by']   = OWPM_FS::order_by_clause( $escaped_table );
				$state['db_where']      = $this->row_filter( $table );
				$state['db_rows_limit'] = $this->rows_per_batch( $table );
			}

			$order_by   = isset( $state['db_order_by'] ) ? (string) $state['db_order_by'] : '';
			$where      = isset( $state['db_where'] ) ? (string) $state['db_where'] : '';
			$rows_limit = isset( $state['db_rows_limit'] ) ? max( 1, (int) $state['db_rows_limit'] ) : 500;

			// The ORDER BY keeps the OFFSET window stable between requests, so a
			// write to this table mid-export cannot make us skip or repeat a row.
			// Both interpolated parts are validated identifiers: $escaped_table from
			// esc_table() and $order_by from order_by_clause(), which builds its list
			// from esc_column(). The two bound values use placeholders.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$escaped_table}{$where}{$order_by} LIMIT %d OFFSET %d", $rows_limit, $row_offset ),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( empty( $rows ) ) {
				++$table_index;
				$row_offset = 0;
			} else {
				foreach ( $rows as $row ) {
					$values = array();
					foreach ( $row as $value ) {
						if ( is_null( $value ) ) {
							$values[] = 'NULL';
						} else {
							// esc_sql() escapes newlines, so each INSERT stays on one line.
							//
							// It also ends in wpdb::add_placeholder_escape(), which swaps
							// every % for a per-process random token like {a1b2...}. wpdb
							// strips that again on the way into a query, but we are writing
							// to a file, so it would be baked into the dump -- and the
							// importing process generates a different token, so it never
							// matches and the literal {a1b2...} lands in the database in
							// place of the %. Undo it here, while we still can.
							$values[] = "'" . $wpdb->remove_placeholder_escape( esc_sql( $value ) ) . "'";
						}
					}

					$this->write_statement( $handle, "INSERT INTO {$escaped_table} VALUES (" . implode( ',', $values ) . ')' );
				}

				$row_offset += count( $rows );
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$state['db_table_index'] = $table_index;
		$state['db_row_offset']  = $row_offset;
		update_option( self::STATE_OPTION, $state, false );

		$progress = $total > 0 ? (int) round( ( $table_index / $total ) * 100 ) : 100;

		if ( $table_index >= $total ) {
			wp_send_json_success(
				array(
					'status'   => 'complete',
					'progress' => 100,
					'current'  => __( 'Database export complete.', 'open-migration-unlimited-site-transfer-backup' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'status'   => 'processing',
				'progress' => min( 99, $progress ),
				'current'  => $current,
			)
		);
	}

	/**
	 * Append one statement plus the delimiter marker.
	 *
	 * The delimiter (rather than splitting on ";\n") is what makes the dump safe to
	 * re-parse when a CREATE TABLE spans lines or a value contains a semicolon.
	 */
	private function write_statement( $handle, $sql ) {
		$sql = rtrim( trim( $sql ), ';' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $handle, $sql . ";\n" . self::SQL_DELIMITER . "\n" );
	}

	/* ---------------------------------------------------------------------
	 * Step 3: file archive (resumable across requests)
	 * ------------------------------------------------------------------ */

	public function ajax_export_files() {
		$this->guard();

		$state = $this->require_state();

		$zip      = new ZipArchive();
		$is_first = ( 0 === (int) $state['files_done'] && 0 === (int) $state['manifest_offset'] );
		$flags    = $is_first ? ( ZipArchive::CREATE | ZipArchive::OVERWRITE ) : ZipArchive::CREATE;

		if ( true !== $zip->open( $state['zip_file'], $flags ) ) {
			wp_send_json_error( __( 'Could not open the backup archive for writing.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		if ( $is_first ) {
			if ( file_exists( $state['sql_file'] ) ) {
				$zip->addFile( $state['sql_file'], 'database.sql' );
			}
			$zip->addFromString( 'metadata.json', wp_json_encode( $this->build_metadata( $state ) ) );
		}

		$handle = @fopen( $state['manifest'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			$zip->close();
			wp_send_json_error( __( 'The backup manifest is missing. Please start the export again.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		if ( $state['manifest_offset'] > 0 ) {
			fseek( $handle, (int) $state['manifest_offset'] );
		}

		$started     = microtime( true );
		$budget      = OWPM_FS::batch_time_budget();
		$batch_limit = 500;
		$added       = 0;
		$last_file   = '';
		$content_dir = OWPM_FS::content_dir();
		$content_rel = OWPM_FS::content_name();

		// Empty on the usual layout, in which case the check below never matches
		// and every file is stored relative to the content directory as before.
		$uploads_dir = OWPM_FS::external_uploads_dir();

		// $processed counts every manifest line consumed, not just files added to
		// the zip. Files that vanished since the manifest was built still cost a
		// stat() call, so they must count towards the batch limit -- otherwise a
		// long run of stale entries loops until the request is killed, and no
		// progress is ever persisted.
		$processed = 0;

		while ( $processed < $batch_limit ) {
			// Checked at the top so the early `continue` paths below cannot bypass
			// it. The first iteration always runs, so a batch never does nothing.
			if ( $processed > 0 && ( microtime( true ) - $started ) > $budget ) {
				break;
			}

			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}

			++$processed;

			$file_path = rtrim( $line, "\r\n" );

			if ( '' === $file_path ) {
				continue;
			}

			// The file may have been deleted since the manifest was built.
			if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
				$state['skipped_files'] = (int) $state['skipped_files'] + 1;
				$state['files_done']    = (int) $state['files_done'] + 1;
				continue;
			}

			$normalized = str_replace( '\\', '/', $file_path );

			if ( '' !== $uploads_dir && 0 === strpos( $normalized, $uploads_dir ) ) {
				// Relocated uploads: stored under a reserved folder so the importer
				// can put them wherever the destination keeps its uploads.
				$relative   = substr( $normalized, strlen( $uploads_dir ) );
				$local_name = OWPM_FS::EXTERNAL_UPLOADS_ENTRY . '/' . ltrim( $relative, '/' );
			} else {
				$relative   = str_replace( $content_dir, '', $normalized );
				$local_name = $content_rel . '/' . ltrim( $relative, '/' );
			}

			if ( $zip->addFile( $file_path, $local_name ) ) {
				$last_file = $local_name;
				++$added;
			} else {
				$state['skipped_files'] = (int) $state['skipped_files'] + 1;
			}

			$state['files_done'] = (int) $state['files_done'] + 1;
		}

		$state['manifest_offset'] = ftell( $handle );
		$eof                      = feof( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( true !== $zip->close() ) {
			wp_send_json_error( __( 'Failed to write the backup archive. The server may be out of disk space.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		update_option( self::STATE_OPTION, $state, false );

		$total    = max( 1, (int) $state['total_files'] );
		$progress = min( 100, (int) round( ( (int) $state['files_done'] / $total ) * 100 ) );
		$complete = ( $eof || (int) $state['files_done'] >= (int) $state['total_files'] );

		wp_send_json_success(
			array(
				'status'       => $complete ? 'complete' : 'processing',
				'progress'     => $complete ? 100 : $progress,
				'current_file' => $complete ? __( 'Done', 'open-migration-unlimited-site-transfer-backup' ) : $last_file,
				'files_done'   => (int) $state['files_done'],
				'total_files'  => (int) $state['total_files'],
				'added'        => $added,
				'skipped'      => (int) $state['skipped_files'],
			)
		);
	}

	/**
	 * Data the destination site needs to rewrite URLs and paths automatically.
	 */
	private function build_metadata( $state ) {
		global $wpdb;

		return array(
			'owpm_version' => OWPM_VERSION,
			'generated'    => gmdate( 'c' ),
			'source_url'   => untrailingslashit( site_url() ),
			'source_home'  => untrailingslashit( home_url() ),
			'source_path'  => OWPM_FS::root_dir(),
			'content_dir'  => OWPM_FS::content_name(),
			'table_prefix' => $wpdb->prefix,
			'charset'      => $wpdb->charset,
			'collate'      => $wpdb->collate,
			'wp_version'   => get_bloginfo( 'version' ),
			'php_version'  => PHP_VERSION,
			'multisite'    => is_multisite() ? 1 : 0,
			'backup_id'    => $state['id'],
		);
	}

	/* ---------------------------------------------------------------------
	 * Step 4: finalise
	 * ------------------------------------------------------------------ */

	public function ajax_finish_export() {
		$this->guard();

		$state = $this->require_state();

		if ( ! file_exists( $state['zip_file'] ) ) {
			wp_send_json_error( __( 'The backup archive could not be found after packaging.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		// Remove the working directory (SQL dump + manifest), keeping only the .zip.
		if ( OWPM_FS::is_inside( $state['path'], $this->temp_dir ) ) {
			OWPM_FS::delete( $state['path'] );
		}

		delete_option( self::STATE_OPTION );

		// The directory stays deny-all; downloads are streamed by handle_download().
		OWPM_FS::protect_dir( $this->temp_dir );

		$name = basename( $state['zip_file'] );

		wp_send_json_success(
			array(
				'backup_id'     => $state['id'],
				'name'          => $name,
				'size'          => size_format( (int) filesize( $state['zip_file'] ) ),
				'download_url'  => $this->download_url( $name ),
				'skipped_files' => (int) $state['skipped_files'],
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Backups tab
	 * ------------------------------------------------------------------ */

	public function ajax_get_backups() {
		$this->guard();

		OWPM_FS::init_backup_dir();

		$files   = glob( $this->temp_dir . '*.zip' );
		$backups = array();

		if ( $files ) {
			foreach ( $files as $file ) {
				$mtime = (int) filemtime( $file );
				$name  = basename( $file );

				$backups[] = array(
					'name'         => $name,
					'size'         => size_format( (int) filesize( $file ) ),
					'mtime'        => $mtime,
					'date'         => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $mtime ),
					'download_url' => $this->download_url( $name ),
				);
			}

			// Sort on the raw timestamp; re-parsing a localised date string is unreliable.
			usort(
				$backups,
				function ( $a, $b ) {
					return $b['mtime'] - $a['mtime'];
				}
			);
		}

		wp_send_json_success( $backups );
	}

	public function ajax_delete_backup() {
		$this->guard();

		// The nonce is verified by guard() at the top of this method.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$file = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
		$file = basename( $file );

		if ( '' === $file || '.zip' !== strtolower( substr( $file, -4 ) ) ) {
			wp_send_json_error( __( 'Invalid backup file name.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$filepath = $this->temp_dir . $file;

		if ( ! file_exists( $filepath ) || ! OWPM_FS::is_inside( $filepath, $this->temp_dir ) ) {
			wp_send_json_error( __( 'Backup file not found.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		if ( ! OWPM_FS::delete( $filepath ) ) {
			wp_send_json_error( __( 'Could not delete the backup file. Check file permissions.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		wp_send_json_success( __( 'Backup deleted.', 'open-migration-unlimited-site-transfer-backup' ) );
	}

	/* ---------------------------------------------------------------------
	 * Authenticated download
	 * ------------------------------------------------------------------ */

	private function download_url( $name ) {
		// The nonce is added by hand rather than with wp_nonce_url(), which runs its
		// result through esc_html(). That is right for a URL printed into markup, but
		// this one is handed to JavaScript as JSON and put in an href from there, so
		// the &amp; entities survive into the request. PHP then splits the query
		// string on the & inside them and sees "amp;_wpnonce" instead of "_wpnonce",
		// and the download fails as an expired link.
		return add_query_arg(
			array(
				'action'   => 'owpm_download_backup',
				'file'     => rawurlencode( $name ),
				'_wpnonce' => wp_create_nonce( 'owpm_download_' . $name ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Stream a backup to the browser.
	 *
	 * Serving through PHP means the backups directory can remain deny-all, which
	 * matters on Nginx where .htaccess is never read.
	 */
	public function handle_download() {
		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$file = basename( $file );

		check_admin_referer( 'owpm_download_' . $file );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download backups.', 'open-migration-unlimited-site-transfer-backup' ), 403 );
		}

		if ( '' === $file || '.zip' !== strtolower( substr( $file, -4 ) ) ) {
			wp_die( esc_html__( 'Invalid backup file name.', 'open-migration-unlimited-site-transfer-backup' ), 400 );
		}

		$path = $this->temp_dir . $file;

		if ( ! file_exists( $path ) || ! OWPM_FS::is_inside( $path, $this->temp_dir ) ) {
			wp_die( esc_html__( 'Backup file not found.', 'open-migration-unlimited-site-transfer-backup' ), 404 );
		}

		OWPM_FS::raise_limits();

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		// Drop any buffering so a multi-gigabyte file never lands in memory.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			wp_die( esc_html__( 'Could not read the backup file.', 'open-migration-unlimited-site-transfer-backup' ), 500 );
		}

		while ( ! feof( $handle ) ) {
			// Streamed in blocks so a multi-gigabyte archive never enters memory;
			// WP_Filesystem has no streaming read. Raw binary, so no escaping applies.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread
			echo fread( $handle, 1048576 );
			flush();
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
