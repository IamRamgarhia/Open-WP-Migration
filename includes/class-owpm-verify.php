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

	/** Example pages to name per foreign host, so the operator can look before rewriting. */
	const SAMPLES_PER_HOST = 4;

	public function __construct() {
		add_action( 'wp_ajax_owpm_verify', array( $this, 'ajax_verify' ) );
		add_action( 'wp_ajax_owpm_fix_links', array( $this, 'ajax_fix_links' ) );
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

		$checks[] = $this->check_foreign_domains();
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

	private function result( $label, $status, $detail, $count = 0, $extra = array() ) {
		return array_merge(
			array(
				'label'  => $label,
				'status' => $status,
				'detail' => $detail,
				'count'  => (int) $count,
			),
			(array) $extra
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

	/**
	 * Find other domains your own content links to.
	 *
	 * The check above only runs when an import recorded where this site came
	 * from. An import that crashed before finishing records nothing, which is
	 * exactly the situation where something is most likely to have been left
	 * pointing at the old site -- so the one case that needs checking is the one
	 * that gets skipped.
	 *
	 * This needs no record. It reads the links in published content and reports
	 * any host that is not this one. A site that was migrated and not fully
	 * converted shows up immediately as a pile of links to its old address.
	 * External links are normal, so this reports rather than warns, and the
	 * operator recognises their own old domain at a glance.
	 *
	 * Each host is returned with a few of the pages it appears on, so the
	 * operator can read the content before deciding to rewrite anything.
	 */
	private function check_foreign_domains() {
		global $wpdb;

		$self = $this->self_hosts();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT ID, post_title, post_content FROM {$wpdb->posts}
				WHERE post_status = 'publish'
				AND post_content LIKE '%href=%'
				LIMIT 2000",
			ARRAY_A
		);

		$hosts   = array();
		$samples = array();
		$broken  = false;

		foreach ( (array) $rows as $row ) {
			$content = isset( $row['post_content'] ) ? $row['post_content'] : '';
			$post_id = isset( $row['ID'] ) ? (int) $row['ID'] : 0;
			// Tilde delimiter on purpose. The character class has to contain # so
			// a match stops at a fragment, and with # as the delimiter too, PCRE
			// ends the pattern there: preg_match_all() then returns false for
			// every row and this check quietly reports that every link is fine.
			// It did exactly that on a site with twelve links to its old domain.
			if ( ! preg_match_all( '~href=["\']https?://([^/"\'?#]+)~i', (string) $content, $matches ) ) {
				if ( PREG_NO_ERROR !== preg_last_error() ) {
					$broken = true;
				}

				continue;
			}

			foreach ( $matches[1] as $host ) {
				$host = strtolower( $host );

				if ( '' === $host || in_array( $host, $self, true ) ) {
					continue;
				}

				$hosts[ $host ] = isset( $hosts[ $host ] ) ? $hosts[ $host ] + 1 : 1;

				if ( ! isset( $samples[ $host ] ) ) {
					$samples[ $host ] = array();
				}

				// Keyed by post ID so a page linking to the same host ten times is
				// still named once.
				if ( $post_id && ! isset( $samples[ $host ][ $post_id ] )
					&& count( $samples[ $host ] ) < self::SAMPLES_PER_HOST ) {
					$samples[ $host ][ $post_id ] = isset( $row['post_title'] ) ? (string) $row['post_title'] : '';
				}
			}
		}

		if ( $broken ) {
			// A check that could not run must not report a clean bill of health.
			return $this->result(
				__( 'Links to other domains', 'open-migration-unlimited-site-transfer-backup' ),
				'warn',
				__( 'This check could not be completed, so treat the result as unknown rather than clear. Please report it so it can be fixed.', 'open-migration-unlimited-site-transfer-backup' )
			);
		}

		if ( empty( $hosts ) ) {
			return $this->result(
				__( 'Links to other domains', 'open-migration-unlimited-site-transfer-backup' ),
				'ok',
				__( 'Every link in your published content points at this site.', 'open-migration-unlimited-site-transfer-backup' )
			);
		}

		arsort( $hosts );

		$payload = array();

		foreach ( array_slice( $hosts, 0, 20, true ) as $host => $count ) {
			$pages = array();
			$found = isset( $samples[ $host ] ) ? $samples[ $host ] : array();

			foreach ( $found as $post_id => $title ) {
				$pages[] = array(
					'id'    => (int) $post_id,
					'title' => ( '' !== trim( (string) $title ) )
						? (string) $title
						: sprintf(
							/* translators: %d: post ID of an untitled page. */
							__( 'Untitled (#%d)', 'open-migration-unlimited-site-transfer-backup' ),
							(int) $post_id
						),
					'edit'  => (string) get_edit_post_link( (int) $post_id, 'raw' ),
					'view'  => (string) get_permalink( (int) $post_id ),
				);
			}

			$payload[] = array(
				'host'  => (string) $host,
				'count' => (int) $count,
				'pages' => $pages,
			);
		}

		return $this->result(
			__( 'Links to other domains', 'open-migration-unlimited-site-transfer-backup' ),
			'info',
			__( 'Links to other people\'s sites are perfectly normal. What matters is whether you recognise the site you migrated from below — those links still walk your visitors back to it. Check the example pages first, then rewrite the ones that should point here.', 'open-migration-unlimited-site-transfer-backup' ),
			array_sum( $hosts ),
			array( 'hosts' => $payload )
		);
	}

	/**
	 * Point every reference to one host at this site instead.
	 *
	 * The Change Domain tab cannot do this, and the distinction matters. That
	 * tab rewrites *from this site's own address* to a new one, which is the
	 * right tool when the site is moving. It is no use for the case this
	 * handles: the move already happened, siteurl is correct, and the content is
	 * still full of absolute links to where the site used to live -- because the
	 * import died before the replace pass ran, or the pages were written on the
	 * old domain and brought over by hand. Until now the only advice this plugin
	 * could offer for that was advice it could not carry out.
	 *
	 * Batched, because it walks every table once per replacement pair.
	 */
	public function ajax_fix_links() {
		check_ajax_referer( 'owpm_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do that.', 'open-migration-unlimited-site-transfer-backup' ), 403 );
		}

		OWPM_FS::raise_limits();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer() above.
		$host = isset( $_POST['host'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['host'] ) ) ) : '';

		if ( ! preg_match( '~^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$~', $host ) ) {
			wp_send_json_error( __( 'That does not look like a host name.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		if ( in_array( $host, $this->self_hosts(), true ) ) {
			wp_send_json_error( __( 'That is already this site’s address, so there is nothing to change.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$pairs = $this->rewrite_pairs( $host );

		if ( empty( $pairs ) ) {
			wp_send_json_error( __( 'Nothing to replace for that host.', 'open-migration-unlimited-site-transfer-backup' ) );
		}

		$total = count( $pairs );

		$needles = array();

		foreach ( $pairs as $pair_values ) {
			$needles[] = $pair_values[0];
		}

		// A dry run first, always. The operator confirms against the real number
		// of rows this will change, not against an estimate, and a host that
		// turns out to match nothing stops here instead of walking every table
		// on the site to achieve nothing.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer() above.
		if ( ! empty( $_POST['preview'] ) ) {
			$found = OWPM_Search_Replace::preview( $needles, OWPM_FS::get_site_tables() );

			if ( $found['rows'] < 1 && empty( $found['skipped'] ) ) {
				wp_send_json_error(
					sprintf(
						/* translators: %s: host name. */
						__( 'Nothing in the database mentions %s any more, so there is nothing to rewrite.', 'open-migration-unlimited-site-transfer-backup' ),
						$host
					)
				);
			}

			wp_send_json_success(
				array(
					'rows'       => (int) $found['rows'],
					'tables'     => $found['tables'],
					'skipped'    => $found['skipped'],
					'recognised' => $this->is_known_previous_host( $host ),
					'host'       => $host,
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above. Clamped below.
		$pair = isset( $_POST['pair'] ) ? max( 0, (int) $_POST['pair'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$table = isset( $_POST['table'] ) ? max( 0, (int) $_POST['table'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$rows = isset( $_POST['rows'] ) ? max( 0, (int) $_POST['rows'] ) : 0;

		if ( $pair >= $total ) {
			wp_send_json_success(
				array(
					'done' => true,
					'rows' => $rows,
				)
			);
		}

		$tables  = OWPM_FS::get_site_tables();
		$budget  = OWPM_FS::batch_time_budget();
		$started = microtime( true );

		$state = array(
			'table_index' => $table,
			'offset'      => $offset,
			'rows'        => $rows,
			'done'        => false,
		);

		while ( $pair < $total ) {
			$left = $budget - ( microtime( true ) - $started );

			if ( $left <= 0 ) {
				break;
			}

			$state = OWPM_Search_Replace::run_batch( $pairs[ $pair ][0], $pairs[ $pair ][1], $tables, $state, $left );

			if ( empty( $state['done'] ) ) {
				// Yielded part way through this pair. Resume where it stopped.
				break;
			}

			++$pair;

			$state['table_index'] = 0;
			$state['offset']      = 0;
			$state['done']        = false;
		}

		$complete = ( $pair >= $total );
		$left     = array();

		if ( $complete ) {
			// Rows were changed with direct SQL, so anything already cached is stale.
			wp_cache_flush();

			// Check the work rather than announce it. If anything still mentions
			// the host -- a table with no primary key, a column type the replacer
			// will not touch -- the operator is told, instead of being shown a
			// tick over a site that is still half wrong.
			$remaining = OWPM_Search_Replace::preview( $needles, OWPM_FS::get_site_tables() );

			$left = array_values( array_merge( $remaining['tables'], $remaining['skipped'] ) );
		}

		wp_send_json_success(
			array(
				'done'      => $complete,
				'pair'      => (int) $pair,
				'pairs'     => $total,
				'table'     => (int) $state['table_index'],
				'offset'    => (int) $state['offset'],
				'rows'      => (int) $state['rows'],
				'remaining' => $left,
				'progress'  => $total > 0 ? (int) round( ( $pair / $total ) * 100 ) : 100,
			)
		);
	}

	/**
	 * This site's own authority, in every form a link may spell it.
	 *
	 * The href pattern captures host *and* port, because that is what appears
	 * in a URL. wp_parse_url( ..., PHP_URL_HOST ) drops the port, so comparing
	 * the two made a site served on a port -- localhost:8099, or any staging
	 * install behind a non-standard one -- fail to recognise itself: it listed
	 * its own domain as foreign and offered to rewrite its own links.
	 *
	 * @return string[] Lower-cased, host first.
	 */
	private function self_hosts() {
		$site = (string) get_option( 'siteurl' );
		$host = strtolower( (string) wp_parse_url( $site, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return array();
		}

		$hosts = array( $host );
		$port  = wp_parse_url( $site, PHP_URL_PORT );

		if ( $port ) {
			$hosts[] = $host . ':' . (int) $port;
		}

		return $hosts;
	}

	/**
	 * Authority this site is served on, port included, for use as a
	 * replacement target.
	 */
	private function self_authority() {
		$hosts = $this->self_hosts();

		// Last entry carries the port when there is one.
		return empty( $hosts ) ? '' : (string) end( $hosts );
	}

	/**
	 * Is this a host the site is recorded as having come from?
	 *
	 * Decides how loudly to warn. Rewriting the address a migration came from is
	 * routine housekeeping. Rewriting a host this site has no recorded
	 * relationship with is how someone turns every outbound link on their site
	 * into a link to themselves, so that case gets the blunt warning rather than
	 * the reassuring one.
	 *
	 * A false here means "not recorded", not "unsafe" -- an import that crashed
	 * records nothing, which is exactly when this tool is needed most.
	 */
	private function is_known_previous_host( $host ) {
		$record = get_option( 'owpm_last_import' );

		if ( ! is_array( $record ) || empty( $record['source_url'] ) ) {
			return false;
		}

		$source = strtolower( (string) wp_parse_url( (string) $record['source_url'], PHP_URL_HOST ) );

		return ( '' !== $source && $source === $host );
	}

	/**
	 * Every textual form a link to $host can take, mapped to this site.
	 *
	 * Both schemes, because content written on an http site and migrated to an
	 * https one carries http links that no https-only pass would touch.
	 */
	private function rewrite_pairs( $host ) {
		$site = untrailingslashit( (string) get_option( 'siteurl' ) );
		// Port included. Without it a protocol-relative link on a site served on
		// a port would be rewritten to the bare host and stop resolving.
		$self = $this->self_authority();
		$bare = '//' . $host;

		$pairs = array();

		foreach ( array( 'https://', 'http://' ) as $scheme ) {
			foreach ( OWPM_Search_Replace::build_url_pairs( $scheme . $host, $site ) as $pair ) {
				$pairs[ $pair[0] ] = $pair;
			}
		}

		// build_url_pairs() includes the protocol-relative form, and keyed
		// insertion puts it ahead of the http:// pair. Run it there and
		// "http://old.example.com" is matched by "//old.example.com" first,
		// leaving an http:// link on what may well be an https site. So it is
		// pulled out and re-appended: scheme-qualified forms first, bare last.
		unset( $pairs[ $bare ] );

		if ( $self ) {
			$pairs[ $bare ] = array( $bare, '//' . $self );
		}

		// A pair that replaces a string with itself would walk every table for
		// nothing, and run_batch() treats it as already finished anyway.
		return array_values(
			array_filter(
				$pairs,
				static function ( $pair ) {
					return '' !== $pair[0] && $pair[0] !== $pair[1];
				}
			)
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

		$files = isset( $snapshot['files'] ) ? (int) $snapshot['files'] : 0;

		// Only mentions what is actually held. Announcing "0 database table(s)"
		// for a restore point made entirely of parked files reads as a fault in
		// the restore point rather than a description of it.
		$held = array();

		if ( (int) $snapshot['tables'] > 0 ) {
			$held[] = sprintf(
				/* translators: %d: number of database tables held. */
				_n( '%d database table', '%d database tables', (int) $snapshot['tables'], 'open-migration-unlimited-site-transfer-backup' ),
				(int) $snapshot['tables']
			);
		}

		if ( $files > 0 ) {
			$held[] = sprintf(
				/* translators: %d: number of theme or plugin folders a clean install moved aside. */
				_n( '%d theme or plugin folder moved aside by a clean install', '%d theme and plugin folders moved aside by a clean install', $files, 'open-migration-unlimited-site-transfer-backup' ),
				$files
			);
		}

		$detail = sprintf(
			/* translators: %s: what the restore point holds. */
			__( 'A restore point from before an import is still stored: %s.', 'open-migration-unlimited-site-transfer-backup' ),
			implode( __( ' and ', 'open-migration-unlimited-site-transfer-backup' ), $held )
		);

		if ( (int) $snapshot['tables'] < 1 ) {
			$detail .= ' ' . __( 'That import stopped while unpacking, so the database was never touched.', 'open-migration-unlimited-site-transfer-backup' );
		}

		$detail .= ' ' . __( 'Use "Restore this site" on the Import tab if this site is wrong, or discard it to free the space.', 'open-migration-unlimited-site-transfer-backup' );

		return $this->result(
			__( 'Unfinished import', 'open-migration-unlimited-site-transfer-backup' ),
			'warn',
			$detail,
			(int) $snapshot['tables'] + $files
		);
	}
}
