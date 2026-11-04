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
		add_action( 'wp_ajax_owpm_slim_preview', array( $this, 'ajax_slim_preview' ) );
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

	/**
	 * Say exactly what "slim down the backup" would leave out, before it runs.
	 *
	 * Read-only, and the number is counted rather than estimated. An operator
	 * being asked to drop rows from their backup should see how many and from
	 * where, not a reassuring adjective -- and a category that turns out to
	 * account for nothing is worth knowing about too, because then there is no
	 * reason to tick it.
	 */
	public function ajax_slim_preview() {
		global $wpdb;

		$this->guard();

		$rows  = array();
		$total = 0;
		$bytes = 0;

		foreach ( self::slim_categories() as $category ) {
			$found = 0;

			foreach ( OWPM_FS::get_site_tables() as $table ) {
				$clauses = self::slim_clauses( $table, array( $category ) );

				if ( empty( $clauses ) ) {
					continue;
				}

				$escaped = OWPM_FS::esc_table( $table );

				if ( ! $escaped ) {
					continue;
				}

				// The clauses say what to keep, so what would be left out is
				// everything that fails them.
				$sql = "SELECT COUNT(*) FROM {$escaped} WHERE NOT ( " . implode( ' AND ', $clauses ) . ' )';

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$count = (int) $wpdb->get_var( $sql );

				if ( $count < 1 ) {
					continue;
				}

				$found += $count;
				$bytes += $count * $this->average_row_bytes( $table );
			}

			$rows[ $category ] = $found;
			$total            += $found;
		}

		wp_send_json_success(
			array(
				'categories' => $rows,
				'rows'       => $total,
				'size'       => $bytes > 0 ? size_format( $bytes ) : '',
			)
		);
	}

	/**
	 * Rough bytes per row, for turning a row count into something meaningful.
	 *
	 * MySQL's own estimate. Approximate by nature, which is why it is only ever
	 * shown next to an exact row count rather than on its own.
	 */
	private function average_row_bytes( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );

		if ( ! is_array( $status ) || empty( $status['Avg_row_length'] ) ) {
			return 0;
		}

		return (int) $status['Avg_row_length'];
	}

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

		// Categories the operator asked to leave out of the archive.
		//
		// wp_unslash() is not sanitising -- it only undoes WordPress's own
		// slashes -- so the value is now run through sanitize_text_field() as
		// well. What actually makes it safe to build SQL from is
		// sanitize_slim(), which reduces it to keys slim_clauses() knows; this
		// is the belt to that pair of braces, and it is what the static
		// analysis was right to ask for.
		//
		// The field crosses the wire as a comma-separated string.
		$raw_slim = '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by guard() at the top of this method.
		if ( isset( $_POST['slim'] ) && is_string( $_POST['slim'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
			$raw_slim = sanitize_text_field( wp_unslash( $_POST['slim'] ) );
		}

		$slim = self::sanitize_slim( $raw_slim );

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
				'slim'            => $slim,
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
	private function row_filter( $table, $slim = array() ) {
		global $wpdb;

		if ( $table === $wpdb->options ) {
			return " WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'";
		}

		$clauses = self::slim_clauses( $table, $slim );

		return empty( $clauses ) ? '' : ' WHERE ' . implode( ' AND ', $clauses );
	}

	/**
	 * Categories a backup may leave out, and what each one means per table.
	 *
	 * Deliberately done here, at export, rather than by cleaning a site up.
	 * That is the whole safety argument: this only decides what is *copied into
	 * the archive*. The site being exported is read and never written, so an
	 * operator who ticks the wrong box has a smaller backup, not a damaged
	 * site -- and the untouched original is still there to export again.
	 *
	 * Every category is something WordPress or the plugin that made it builds
	 * again by itself. Revisions start accumulating from the next edit,
	 * transients from the next cache miss. Nothing here is content a visitor
	 * can see.
	 *
	 * The clauses say what to KEEP, because that is what a dump's WHERE needs.
	 * Identifiers come from $wpdb and the categories are whitelisted by
	 * sanitize_slim(), so nothing here is interpolated from a request.
	 *
	 * @param string   $table Table being dumped.
	 * @param string[] $slim  Category keys, already sanitised.
	 * @return string[] SQL conditions, to be ANDed.
	 */
	private static function slim_clauses( $table, $slim ) {
		global $wpdb;

		$slim = (array) $slim;

		if ( empty( $slim ) ) {
			return array();
		}

		$want    = array_fill_keys( $slim, true );
		$clauses = array();

		// A row in a meta or relationship table is kept or dropped according to
		// whether its parent survives. Dropping revisions from wp_posts but
		// keeping their postmeta would leave the bloat behind under a different
		// name, and orphaned meta that no longer means anything.
		// Only these two child tables are touched, and only ever by their own
		// parent's primary key.
		//
		// wp_term_relationships is deliberately left alone. Its object_id is not
		// guaranteed to be a post: a plugin may attach a taxonomy to users or to
		// its own object type, and those rows carry an id from a different
		// sequence. Matching them against wp_posts would drop a legitimate row
		// whenever the two sequences happened to collide. Revisions are never
		// given term relationships by core anyway, so the gain was nil and the
		// risk was not.
		$children = array(
			$wpdb->postmeta    => array( $wpdb->posts, 'post_id', 'ID', 'p' ),
			$wpdb->commentmeta => array( $wpdb->comments, 'comment_id', 'comment_ID', 'c' ),
		);

		/**
		 * "Keep this row unless its parent matches $condition."
		 *
		 * @param string $child     Child table name.
		 * @param string $condition Condition on the parent alias.
		 * @param bool   $negate    False asks for the opposite: keep only rows
		 *                          whose parent exists at all.
		 */
		$parent_check = function ( $child, $condition, $negate = true ) use ( $children ) {
			list( $parent, $child_key, $parent_key, $alias ) = $children[ $child ];

			return ( $negate ? 'NOT EXISTS' : 'EXISTS' )
				. ' ( SELECT 1 FROM `' . $parent . '` ' . $alias
				. ' WHERE ' . $alias . '.`' . $parent_key . '` = `' . $child . '`.`' . $child_key . '`'
				. ' AND ' . $condition . ' )';
		};

		if ( $table === $wpdb->posts ) {
			if ( isset( $want['revisions'] ) ) {
				$clauses[] = "post_type <> 'revision'";
			}
			if ( isset( $want['trashed'] ) ) {
				// auto-draft is an abandoned "Add New Post": never published, never
				// visible, and WordPress discards them itself after a week.
				$clauses[] = "post_status NOT IN ( 'trash', 'auto-draft' )";
			}
		}

		if ( $table === $wpdb->postmeta ) {
			if ( isset( $want['revisions'] ) ) {
				$clauses[] = $parent_check( $table, "p.post_type = 'revision'" );
			}
			if ( isset( $want['trashed'] ) ) {
				$clauses[] = $parent_check( $table, "p.post_status IN ( 'trash', 'auto-draft' )" );
			}
			if ( isset( $want['orphans'] ) ) {
				$clauses[] = $parent_check( $table, '1 = 1', false );
			}
		}

		if ( $table === $wpdb->comments ) {
			if ( isset( $want['spam'] ) ) {
				$clauses[] = "comment_approved <> 'spam'";
			}
			if ( isset( $want['trashed'] ) ) {
				$clauses[] = "comment_approved <> 'trash'";
			}
		}

		if ( $table === $wpdb->commentmeta ) {
			if ( isset( $want['spam'] ) ) {
				$clauses[] = $parent_check( $table, "c.comment_approved = 'spam'" );
			}
			if ( isset( $want['trashed'] ) ) {
				$clauses[] = $parent_check( $table, "c.comment_approved = 'trash'" );
			}
			if ( isset( $want['orphans'] ) ) {
				$clauses[] = $parent_check( $table, '1 = 1', false );
			}
		}

		return $clauses;
	}

	/**
	 * Every category this plugin knows how to leave out.
	 *
	 * @return string[]
	 */
	public static function slim_categories() {
		return array( 'revisions', 'trashed', 'spam', 'orphans' );
	}

	/**
	 * Keep only categories this plugin actually implements.
	 *
	 * The whitelist is what makes slim_clauses() safe to build SQL from: an
	 * unknown key never reaches it.
	 *
	 * @param mixed $raw Whatever arrived in the request.
	 * @return string[]
	 */
	public static function sanitize_slim( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();

		foreach ( $raw as $key ) {
			$key = strtolower( trim( (string) $key ) );

			if ( in_array( $key, self::slim_categories(), true ) ) {
				$clean[ $key ] = true;
			}
		}

		return array_keys( $clean );
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
				$state['db_where']      = $this->row_filter( $table, isset( $state['slim'] ) ? (array) $state['slim'] : array() );
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
