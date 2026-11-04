<?php
/**
 * Post-migration health check.
 *
 * After a migration the question is always the same: did it actually work, or
 * is something still pointing at the old site? "The pages load" is not an
 * answer -- a stale URL in a widget or a page builder's serialised settings can
 * sit unnoticed for weeks and then show up as a broken image or a link that
 * walks a visitor back to the site you just moved away from.
 *
 * Everything here is read-only and reports counts. Nothing is changed without
 * the operator asking for it on the Change Domain tab.
 *
 * @package Open_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OWPM_Verify {

	/** Rows to look at per table when counting stale references. */
	const SCAN_LIMIT = 200000;

	public function __construct() {
		add_action( 'wp_ajax_owpm_verify', array( $this, 'ajax_verify' ) );
	}

	public function ajax_verify() {
		check_ajax_referer( 'owpm_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do that.', 'open-migration-unlimited-site-transfer-backup' ), 403 );
		}

		OWPM_FS::raise_limits();

		wp_send_json_success(
			array(
				'checks'  => $this->run(),
				'old_url' => $this->previous_url(),
			)
		);
	}

	/**
	 * The address this site was migrated from, if we recorded one.
	 */
	private function previous_url() {
		$record = get_option( 'owpm_last_import' );

		if ( is_array( $record ) && ! empty( $record['source_url'] ) ) {
			$source = untrailingslashit( (string) $record['source_url'] );

			// Only interesting while it differs from where we are now.
			if ( untrailingslashit( (string) get_option( 'siteurl' ) ) !== $source ) {
				return $source;
			}
		}

		return '';
	}

	/**
	 * @return array[] Each: label, status (ok|warn|fail|info), detail, count.
	 */
	public function run() {
		$checks = array();

		$checks[] = $this->check_addresses();

		$old = $this->previous_url();

		if ( '' !== $old ) {
			$checks[] = $this->check_stale_urls( $old );
		}

		$checks[] = $this->check_active_plugins();
		$checks[] = $this->check_active_theme();
		$checks[] = $this->check_uploads();
		$checks[] = $this->check_permalinks();
		$checks[] = $this->check_serialised();
		$checks[] = $this->check_admin_email();
		$checks[] = $this->check_search_engines();
		$checks[] = $this->check_leftover_snapshot();

		return array_values( array_filter( $checks ) );
	}

	private function result( $label, $status, $detail, $count = 0 ) {
		return array(
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
			'count'  => (int) $count,
		);
	}

	/** siteurl and home should both be this site. */
	private function check_addresses() {
		$siteurl = untrailingslashit( (string) get_option( 'siteurl' ) );
		$home    = untrailingslashit( (string) get_option( 'home' ) );
		$host    = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';

		if ( '' === $siteurl || '' === $home ) {
			return $this->result(
				__( 'Site address', 'open-migration-unlimited-site-transfer-backup' ),
				'fail',
				__( 'WordPress has no site address recorded. Set it on Settings → General.', 'open-migration-unlimited-site-transfer-backup' )
			);
		}

		if ( $host && false === strpos( $siteurl, $host ) ) {
			return $this->result(
				__( 'Site address', 'open-migration-unlimited-site-transfer-backup' ),
				'fail',
				sprintf(
					/* translators: 1: stored site address, 2: address being used now. */
					__( 'WordPress thinks this site lives at %1$s, but you are reading this at %2$s. Use the Change Domain tab to correct it.', 'open-migration-unlimited-site-transfer-backup' ),
					$siteurl,
					$host
				)
			);
		}

		return $this->result(
			__( 'Site address', 'open-migration-unlimited-site-transfer-backup' ),
			'ok',
			sprintf(
				/* translators: %s: the site address. */
				__( 'Set to %s.', 'open-migration-unlimited-site-transfer-backup' ),
				$siteurl
			)
		);
	}

	/**
	 * Count rows anywhere in the database still mentioning the old address.
	 *
	 * This is the check that matters most. A migration that reports success can
	 * still leave references behind -- inside a serialised blob that failed to
	 * unserialise, in a table a plugin created after the replace pass ran, or in
	 * content that was edited during the migration.
	 */
	private function check_stale_urls( $old_url ) {
		global $wpdb;

		$host   = wp_parse_url( $old_url, PHP_URL_HOST );
		$needle = $host ? $host : $old_url;
		$total  = 0;
		$where  = array();

		foreach ( OWPM_FS::get_site_tables() as $table ) {
			$escaped = OWPM_FS::esc_table( $table );

			if ( ! $escaped ) {
				continue;
			}

			$columns = OWPM_FS::table_columns( $table );
			$clauses = array();
			$args    = array();

			foreach ( $columns as $column ) {
				$col = OWPM_FS::esc_column( $column );

				if ( ! $col ) {
					continue;
				}

				$clauses[] = "{$col} LIKE %s";
				$args[]    = '%' . $wpdb->esc_like( $needle ) . '%';
			}

			if ( empty( $clauses ) ) {
				continue;
			}

			$sql = "SELECT COUNT(*) FROM {$escaped} WHERE " . implode( ' OR ', $clauses );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$found = (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );

			if ( $found > 0 ) {
				$total  += $found;
				$where[] = $table . ' (' . $found . ')';
			}
		}

		if ( 0 === $total ) {
			return $this->result(
				__( 'References to the old address', 'open-migration-unlimited-site-transfer-backup' ),
				'ok',
				sprintf(
					/* translators: %s: the previous site address. */
					__( 'Nothing in the database still mentions %s.', 'open-migration-unlimited-site-transfer-backup' ),
					$needle
				)
			);
		}

		return $this->result(
			__( 'References to the old address', 'open-migration-unlimited-site-transfer-backup' ),
			'warn',
			sprintf(
				/* translators: 1: number of rows, 2: old host name, 3: list of tables. */
				__( '%1$d row(s) still mention %2$s: %3$s. Run the Change Domain tab with the old address to clear them — it handles serialised data correctly, which a plain find-and-replace does not.', 'open-migration-unlimited-site-transfer-backup' ),
				$total,
				$needle,
				implode( ', ', array_slice( $where, 0, 8 ) )
			),
			$total
		);
	}

	/** Every active plugin should exist on disk. */
	private function check_active_plugins() {
		$active = get_option( 'active_plugins' );

		if ( ! is_array( $active ) ) {
			return null;
		}

		$dir     = OWPM_FS::plugins_dir();
		$missing = array();

		foreach ( $active as $plugin_file ) {
			if ( ! file_exists( $dir . ltrim( (string) $plugin_file, '/' ) ) ) {
				$missing[] = (string) $plugin_file;
			}
		}

		if ( empty( $missing ) ) {
			return $this->result(
				__( 'Active plugins', 'open-migration-unlimited-site-transfer-backup' ),
				'ok',
				sprintf(
					/* translators: %d: number of active plugins. */
					_n( '%d active plugin, present on disk.', '%d active plugins, all present on disk.', count( $active ), 'open-migration-unlimited-site-transfer-backup' ),
					count( $active )
				)
			);
		}

		return $this->result(
			__( 'Active plugins', 'open-migration-unlimited-site-transfer-backup' ),
			'fail',
			sprintf(
				/* translators: %s: comma separated list of plugin files. */
				__( 'These are marked active but are not installed: %s. WordPress will try to load them on every request. Deactivate them on the Plugins screen.', 'open-migration-unlimited-site-transfer-backup' ),
				implode( ', ', $missing )
			),
			count( $missing )
		);
	}

	private function check_active_theme() {
		$stylesheet = (string) get_option( 'stylesheet' );
		$theme      = wp_get_theme( $stylesheet );

		if ( ! $theme->exists() ) {
			return $this->result(
				__( 'Active theme', 'open-migration-unlimited-site-transfer-backup' ),
				'fail',
				sprintf(
					/* translators: %s: theme folder name. */
					__( 'The active theme (%s) is not installed. The site cannot render until you pick one that is.', 'open-migration-unlimited-site-transfer-backup' ),
					$stylesheet
				)
			);
		}

		return $this->result(
			__( 'Active theme', 'open-migration-unlimited-site-transfer-backup' ),
			'ok',
			$theme->get( 'Name' ) . ' ' . $theme->get( 'Version' )
		);
	}

	/** Uploads should exist, be writable, and match the current address. */
	private function check_uploads() {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return $this->result(
				__( 'Uploads folder', 'open-migration-unlimited-site-transfer-backup' ),
				'fail',
				(string) $uploads['error']
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- a read-only probe; WP_Filesystem would need credentials for a check that writes nothing.
		if ( ! is_writable( $uploads['basedir'] ) ) {
			return $this->result(
				__( 'Uploads folder', 'open-migration-unlimited-site-transfer-backup' ),
				'fail',
				sprintf(
					/* translators: %s: uploads directory path. */
					__( '%s is not writable, so new media cannot be added.', 'open-migration-unlimited-site-transfer-backup' ),
					$uploads['basedir']
				)
			);
		}

		$host     = wp_parse_url( get_option( 'siteurl' ), PHP_URL_HOST );
		$url_host = wp_parse_url( $uploads['baseurl'], PHP_URL_HOST );

		if ( $host && $url_host && $host !== $url_host ) {
			return $this->result(
				__( 'Uploads folder', 'open-migration-unlimited-site-transfer-backup' ),
				'warn',
				sprintf(
					/* translators: %s: uploads base URL. */
					__( 'Media URLs still point at %s. Check upload_path and upload_url_path on Settings → Media.', 'open-migration-unlimited-site-transfer-backup' ),
					$uploads['baseurl']
				)
			);
		}

		return $this->result(
			__( 'Uploads folder', 'open-migration-unlimited-site-transfer-backup' ),
			'ok',
			__( 'Writable, and serving from this domain.', 'open-migration-unlimited-site-transfer-backup' )
		);
	}

	/** Pretty permalinks need the rewrite rules rebuilding after a move. */
	private function check_permalinks() {
		$structure = get_option( 'permalink_structure' );

		if ( '' === $structure ) {
			return $this->result(
				__( 'Permalinks', 'open-migration-unlimited-site-transfer-backup' ),
				'info',
				__( 'Using plain permalinks, so there are no rewrite rules to rebuild.', 'open-migration-unlimited-site-transfer-backup' )
			);
		}

		$rules = get_option( 'rewrite_rules' );

		if ( empty( $rules ) ) {
			return $this->result(
				__( 'Permalinks', 'open-migration-unlimited-site-transfer-backup' ),
				'warn',
				__( 'The rewrite rules are empty, which usually means every page except the home page returns 404. Open Settings → Permalinks and press Save Changes once.', 'open-migration-unlimited-site-transfer-backup' )
			);
		}

		return $this->result(
			__( 'Permalinks', 'open-migration-unlimited-site-transfer-backup' ),
			'ok',
			sprintf(
				/* translators: %d: number of rewrite rules. */
				__( '%d rewrite rules in place.', 'open-migration-unlimited-site-transfer-backup' ),
				count( (array) $rules )
			)
		);
	}

	/**
	 * Look for option values that no longer unserialise.
	 *
	 * This is the damage a naive search-and-replace does: it changes the text
	 * inside a serialised string without fixing the length prefix, and PHP then
	 * discards the whole value. Theme settings and page-builder layouts vanish.
	 */
	private function check_serialised() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_value LIKE 'a:%' OR option_value LIKE 'O:%' LIMIT 5000",
			ARRAY_A
		);

		$broken = array();

		foreach ( (array) $rows as $row ) {
			$value = $row['option_value'];

			if ( ! is_serialized( $value ) ) {
				continue;
			}

			// allowed_classes => false means no object is ever instantiated, which is
			// the mitigation for untrusted serialised data. The value comes from this
			// site's own options table, and a corrupt one is exactly what is being
			// looked for, so the failure is the result rather than an error.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, PHPCompatibility.FunctionUse.NewFunctionParameters
			if ( false === @unserialize( $value, array( 'allowed_classes' => false ) ) && 'b:0;' !== $value ) {
				$broken[] = $row['option_name'];
			}
		}

		if ( empty( $broken ) ) {
			return $this->result(
				__( 'Serialised settings', 'open-migration-unlimited-site-transfer-backup' ),
				'ok',
				__( 'Every stored setting still reads back correctly.', 'open-migration-unlimited-site-transfer-backup' )
			);
		}

		return $this->result(
			__( 'Serialised settings', 'open-migration-unlimited-site-transfer-backup' ),
			'fail',
			sprintf(
				/* translators: %s: comma separated option names. */
				__( 'These settings are corrupt and WordPress will read them as empty: %s. That is the signature of a find-and-replace run without serialisation support. Restoring the backup again and letting this plugin do the replacement will fix it.', 'open-migration-unlimited-site-transfer-backup' ),
				implode( ', ', array_slice( $broken, 0, 10 ) )
			),
			count( $broken )
		);
	}

	private function check_admin_email() {
		$email  = (string) get_option( 'admin_email' );
		$host   = wp_parse_url( get_option( 'siteurl' ), PHP_URL_HOST );
		$parts  = explode( '@', $email );
		$domain = count( $parts ) === 2 ? strtolower( end( $parts ) ) : '';

		if ( $domain && $host && false === strpos( strtolower( $host ), $domain ) && false === strpos( $domain, 'gmail.' ) ) {
			return $this->result(
				__( 'Admin email', 'open-migration-unlimited-site-transfer-backup' ),
				'info',
				sprintf(
					/* translators: %s: admin email address. */
					__( 'Still set to %s, which came from the site you migrated. Worth checking it is the address you want notifications sent to.', 'open-migration-unlimited-site-transfer-backup' ),
					$email
				)
			);
		}

		return $this->result(
			__( 'Admin email', 'open-migration-unlimited-site-transfer-backup' ),
			'ok',
			$email
		);
	}

	/** A staging site's "discourage search engines" setting travels with the database. */
	private function check_search_engines() {
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			return $this->result(
				__( 'Search engine visibility', 'open-migration-unlimited-site-transfer-backup' ),
				'warn',
				__( 'This site is set to discourage search engines. That setting travelled with the database — if this is the live site, turn it off on Settings → Reading.', 'open-migration-unlimited-site-transfer-backup' )
			);
		}

		return $this->result(
			__( 'Search engine visibility', 'open-migration-unlimited-site-transfer-backup' ),
			'ok',
			__( 'Search engines are allowed to index this site.', 'open-migration-unlimited-site-transfer-backup' )
		);
	}

	private function check_leftover_snapshot() {
		$snapshot = OWPM_Rollback::details();

		if ( ! $snapshot ) {
			return null;
		}

		return $this->result(
			__( 'Unfinished import', 'open-migration-unlimited-site-transfer-backup' ),
			'warn',
			sprintf(
				/* translators: %d: number of tables held in the snapshot. */
				__( 'A snapshot of %d table(s) from before an import is still stored. Restore it from the Import tab if this site is wrong, or discard it to free the space.', 'open-migration-unlimited-site-transfer-backup' ),
				$snapshot['tables']
			),
			$snapshot['tables']
		);
	}
}
