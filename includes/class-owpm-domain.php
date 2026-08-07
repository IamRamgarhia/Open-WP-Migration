<?php
/**
 * Change the site's domain after the fact.
 *
 * The common case this exists for: you migrate onto a new host while DNS still
 * points elsewhere, so the import runs against the host's temporary address
 * (something like site.hostingcompany.com or an IP). Once DNS is switched to the
 * real domain, the database still refers to the temporary one everywhere and
 * WordPress redirects visitors straight back to it.
 *
 * WordPress can only ever see the hostname a request arrived on, so it cannot
 * detect that DNS has changed. This has to be an explicit action, which is what
 * this class provides.
 *
 * @package Open_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OWPM_Domain {

	const STATE_OPTION = 'owpm_domain_change';

	/** Transient holding the one-shot token used to prove DNS reaches this server. */
	const DNS_TOKEN = 'owpm_dns_token';

	public function __construct() {
		add_action( 'wp_ajax_owpm_domain_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_owpm_domain_step', array( $this, 'ajax_step' ) );
		add_action( 'wp_ajax_owpm_domain_check', array( $this, 'ajax_check' ) );
		add_action( 'init', array( $this, 'maybe_answer_dns_check' ) );
	}

	/**
	 * Addresses this site might want to switch to, gathered from wherever a trace
	 * of the original domain survives.
	 *
	 * A single source is not enough. Once an import finishes, every reference to
	 * the old domain has been rewritten, so the only record left is whatever we
	 * deliberately kept. But sites arrive here by other routes too — restored by
	 * a host's migration tool, copied by hand, or running on a staging URL forced
	 * through wp-config — and each of those leaves a different trace.
	 *
	 * @return array[] Each: url, label, detail.
	 */
	public static function detect_candidates() {
		global $wpdb;

		$current    = untrailingslashit( site_url() );
		$candidates = array();

		// Every source below reports something actually recorded somewhere. Guessing
		// was tried -- staging-hostname inference, and scanning content and post
		// GUIDs for other domains -- and on real sites each one ranked a wrong
		// domain first: an ancient domain the site had long left behind, the host's
		// own domain, and a third-party site whose images had been hotlinked. This
		// field rewrites the entire database, so a confident-looking wrong answer is
		// far worse than no answer. Only facts are offered here.
		$add = function ( $url, $label, $detail ) use ( &$candidates, $current ) {
			$url = untrailingslashit( trim( (string) $url ) );

			if ( '' === $url || $url === $current || ! wp_parse_url( $url, PHP_URL_HOST ) ) {
				return;
			}

			// First source to mention an address wins; they are added in order of
			// how much we trust them.
			foreach ( $candidates as $existing ) {
				if ( $existing['url'] === $url ) {
					return;
				}
			}

			$candidates[] = array(
				'url'    => $url,
				'label'  => $label,
				'detail' => $detail,
			);
		};

		// 1. Recorded by our own import. The most reliable source when present.
		$last = get_option( 'owpm_last_import' );

		if ( is_array( $last ) && ! empty( $last['source_url'] ) ) {
			$add(
				$last['source_url'],
				__( 'The site this was imported from', 'open-migration' ),
				__( 'Recorded when the backup was restored onto this install.', 'open-migration' )
			);
		}

		// 2. The raw siteurl/home rows. When a staging site forces WP_SITEURL or
		// WP_HOME in wp-config, site_url() returns the constant while the database
		// still holds the live domain -- which is exactly the address wanted here.
		foreach ( array( 'siteurl', 'home' ) as $option ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$raw = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option )
			);

			if ( $raw ) {
				$add(
					$raw,
					__( 'Stored in the database', 'open-migration' ),
					sprintf(
						/* translators: %s: the option name, siteurl or home. */
						__( 'The %s option still holds this address, but wp-config.php is overriding it.', 'open-migration' ),
						$option
					)
				);
			}
		}

		// 3. Any backup archive still on this server records where it came from.
		foreach ( self::backup_source_urls() as $url ) {
			$add(
				$url,
				__( 'From a backup on this server', 'open-migration' ),
				__( 'Read from the metadata inside an archive in your Backups tab.', 'open-migration' )
			);
		}

		return $candidates;
	}



	/**
	 * Source URLs recorded inside the archives sitting in the backups folder.
	 *
	 * Only the central directory and one small entry are read per archive, so this
	 * stays cheap even for multi-gigabyte backups. Capped at the newest few.
	 */
	private static function backup_source_urls() {
		$urls = array();

		if ( ! class_exists( 'ZipArchive' ) ) {
			return $urls;
		}

		$files = glob( OWPM_FS::backup_dir() . '*.zip' );

		if ( ! $files ) {
			return $urls;
		}

		// Newest first.
		usort(
			$files,
			function ( $a, $b ) {
				return filemtime( $b ) - filemtime( $a );
			}
		);

		foreach ( array_slice( $files, 0, 5 ) as $file ) {
			$zip = new ZipArchive();

			if ( true !== $zip->open( $file ) ) {
				continue;
			}

			$raw = $zip->getFromName( 'metadata.json' );
			$zip->close();

			if ( false === $raw ) {
				continue;
			}

			$meta = json_decode( $raw, true );

			if ( is_array( $meta ) && ! empty( $meta['source_url'] ) ) {
				$urls[] = (string) $meta['source_url'];
			}
		}

		return $urls;
	}

	/**
	 * Answer the DNS probe below.
	 *
	 * Responds only to a token this site generated moments ago, and returns
	 * nothing but a fixed marker.
	 */
	public function maybe_answer_dns_check() {
		if ( empty( $_GET['owpm_dns_check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token    = sanitize_text_field( wp_unslash( $_GET['owpm_dns_check'] ) );
		$expected = get_transient( self::DNS_TOKEN );

		if ( ! is_string( $expected ) || '' === $expected || '' === $token ) {
			return;
		}

		if ( ! hash_equals( $expected, $token ) ) {
			return;
		}

		header( 'Content-Type: text/plain' );
		echo 'OWPM_DNS_OK';
		exit;
	}

	/**
	 * Check whether the new address already resolves to this server.
	 *
	 * This is the single most valuable safeguard in the whole tab: changing the
	 * address before DNS has propagated leaves the site unreachable at both the
	 * old and the new domain. We prove it by requesting the new address with a
	 * one-time token and seeing whether our own marker comes back.
	 */
	public function ajax_check() {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw     = isset( $_POST['new_url'] ) ? wp_unslash( $_POST['new_url'] ) : '';
		$new_url = $this->normalize_url( $raw );

		if ( is_wp_error( $new_url ) ) {
			wp_send_json_error( $new_url->get_error_message() );
		}

		if ( untrailingslashit( site_url() ) === $new_url ) {
			wp_send_json_error( __( 'That is already this site’s address.', 'open-migration' ) );
		}

		$token = wp_generate_password( 32, false );
		set_transient( self::DNS_TOKEN, $token, 2 * MINUTE_IN_SECONDS );

		$response = wp_remote_get(
			add_query_arg( 'owpm_dns_check', $token, $new_url . '/' ),
			array(
				'timeout'     => 15,
				'sslverify'   => false,
				'redirection' => 2,
			)
		);

		delete_transient( self::DNS_TOKEN );

		if ( is_wp_error( $response ) ) {
			wp_send_json_success(
				array(
					'state'   => 'unknown',
					'url'     => $new_url,
					'message' => sprintf(
						/* translators: %s: the address that was tested. */
						__( 'Could not reach %s from this server. That may just mean your host blocks outgoing requests, so it is not proof of a problem — but confirm the address loads this site in your browser before continuing.', 'open-migration' ),
						$new_url
					),
				)
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( false !== strpos( $body, 'OWPM_DNS_OK' ) ) {
			wp_send_json_success(
				array(
					'state'   => 'ok',
					'url'     => $new_url,
					'message' => sprintf(
						/* translators: %s: the address that was tested. */
						__( '%s already points to this server. Safe to continue.', 'open-migration' ),
						$new_url
					),
				)
			);
		}

		wp_send_json_success(
			array(
				'state'   => 'mismatch',
				'url'     => $new_url,
				'message' => sprintf(
					/* translators: %s: the address that was tested. */
					__( '%s responded, but it is not this site. DNS is probably still pointing at your old host. Changing the address now would take this site offline.', 'open-migration' ),
					$new_url
				),
			)
		);
	}

	private function guard() {
		check_ajax_referer( 'owpm_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do that.', 'open-migration' ), 403 );
		}

		OWPM_FS::raise_limits();
	}

	/**
	 * Normalise whatever the user typed into a usable site URL.
	 *
	 * @return string|WP_Error
	 */
	private function normalize_url( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return new WP_Error( 'empty', __( 'Please enter the new site address.', 'open-migration' ) );
		}

		// Reject before esc_url_raw() gets a chance to "fix" the input. It strips
		// and percent-encodes rather than failing, so "ex ample.com" would come back
		// as the perfectly valid-looking https://ex%20ample.com. Silently accepting
		// a mangled address here would rewrite the whole database to a domain that
		// does not exist, which is precisely the mistake this tab must prevent.
		if ( preg_match( '/\s/', $raw ) ) {
			return new WP_Error( 'invalid', __( 'A web address cannot contain spaces. Check for a typo.', 'open-migration' ) );
		}

		// A scheme is "word://". Anything else with a colon is either a host:port,
		// which is fine, or a scheme we will not accept.
		if ( preg_match( '#^([a-z][a-z0-9+.\-]*)://#i', $raw, $scheme_match ) ) {
			$scheme = strtolower( $scheme_match[1] );

			if ( 'http' !== $scheme && 'https' !== $scheme ) {
				return new WP_Error(
					'invalid',
					sprintf(
						/* translators: %s: the scheme the user typed, e.g. "ftp". */
						__( '“%s://” is not supported. A site address must use http:// or https://.', 'open-migration' ),
						$scheme
					)
				);
			}
		} elseif ( preg_match( '#^([a-z][a-z0-9+.\-]*):(.*)$#i', $raw, $colon_match ) && ! preg_match( '/^\d+([\/?#].*)?$/', $colon_match[2] ) ) {
			// "htp://typo.com" lands here (the // became part of the remainder), as
			// does "javascript:alert(1)". "localhost:8080" does not, because what
			// follows the colon is a port.
			return new WP_Error(
				'invalid',
				sprintf(
					/* translators: %s: the prefix the user typed, e.g. "htp". */
					__( '“%s:” is not a valid address prefix. Use http:// or https://, or just type the domain on its own.', 'open-migration' ),
					strtolower( $colon_match[1] )
				)
			);
		} else {
			// Accept a bare "example.com" or "localhost:8080".
			$raw = 'https://' . ltrim( $raw, '/' );
		}

		$parts = wp_parse_url( $raw );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'invalid', __( 'That address is missing a domain name.', 'open-migration' ) );
		}

		$host = strtolower( $parts['host'] );

		if ( ! $this->is_valid_host( $host ) ) {
			return new WP_Error(
				'invalid',
				sprintf(
					/* translators: %s: the host portion of what the user typed. */
					__( '“%s” is not a valid domain name. Enter something like example.com. Internationalised domains must be entered in their punycode (xn--) form.', 'open-migration' ),
					$parts['host']
				)
			);
		}

		$scheme = ! empty( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		$clean  = $scheme . '://' . $host;

		if ( ! empty( $parts['port'] ) ) {
			$clean .= ':' . (int) $parts['port'];
		}

		// A subdirectory install is legitimate; a query string or fragment is not.
		if ( ! empty( $parts['path'] ) && '/' !== $parts['path'] ) {
			$path = '/' . trim( $parts['path'], '/' );

			if ( preg_match( '#(^|/)\.\.?(/|$)#', $path ) ) {
				return new WP_Error( 'invalid', __( 'That address contains a relative path segment, which is not valid in a site address.', 'open-migration' ) );
			}

			$clean .= $path;
		}

		return untrailingslashit( $clean );
	}

	/**
	 * Is this a plausible hostname, IP address, or "localhost"?
	 */
	private function is_valid_host( $host ) {
		if ( '' === $host || strlen( $host ) > 253 ) {
			return false;
		}

		// esc_url_raw()-style escaping must never reach us as a "valid" host.
		if ( false !== strpos( $host, '%' ) || false !== strpos( $host, '_' ) ) {
			return false;
		}

		if ( 'localhost' === $host ) {
			return true;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		// Bracketed IPv6, as it appears in a URL.
		if ( '[' === substr( $host, 0, 1 ) && ']' === substr( $host, -1 ) ) {
			return (bool) filter_var( substr( $host, 1, -1 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
		}

		// Otherwise require dot-separated labels and a real TLD, so a bare
		// "example" is rejected rather than treated as a domain.
		return (bool) preg_match(
			'/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
			$host
		);
	}

	public function ajax_start() {
		$this->guard();

		// The nonce is verified by guard() above. normalize_url() validates and
		// rebuilds the value with esc_url_raw(), which is stricter than
		// sanitize_text_field() would be for a URL.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_url = isset( $_POST['new_url'] ) ? wp_unslash( $_POST['new_url'] ) : '';

		$new_url = $this->normalize_url( $raw_url );

		if ( is_wp_error( $new_url ) ) {
			wp_send_json_error( $new_url->get_error_message() );
		}

		$old_url  = untrailingslashit( site_url() );
		$old_home = untrailingslashit( home_url() );

		if ( $old_url === $new_url ) {
			wp_send_json_error( __( 'That is already this site’s address, so there is nothing to change.', 'open-migration' ) );
		}

		// home_url() usually shares the site_url host; keep them in step.
		$new_home = $new_url;

		if ( $old_home !== $old_url ) {
			// Preserve a home URL that deliberately differs (WordPress in a subdirectory).
			$new_home = str_replace( $old_url, $new_url, $old_home );
		}

		$pairs = OWPM_Search_Replace::build_url_pairs( $old_url, $new_url, $old_home, $new_home );

		if ( empty( $pairs ) ) {
			wp_send_json_error( __( 'Nothing to replace between those two addresses.', 'open-migration' ) );
		}

		update_option(
			self::STATE_OPTION,
			array(
				'old_url'  => $old_url,
				'new_url'  => $new_url,
				'old_home' => $old_home,
				'new_home' => $new_home,
				'pairs'    => $pairs,
				'index'    => 0,
				'rows'     => 0,
				'state'    => $this->fresh_state(),
				'started'  => time(),
			),
			false
		);

		wp_send_json_success(
			array(
				'old_url' => $old_url,
				'new_url' => $new_url,
				'passes'  => count( $pairs ),
			)
		);
	}

	private function fresh_state() {
		return array(
			'table_index' => 0,
			'offset'      => 0,
			'rows'        => 0,
			'done'        => false,
		);
	}

	public function ajax_step() {
		$this->guard();

		$job = get_option( self::STATE_OPTION );

		if ( ! is_array( $job ) || empty( $job['pairs'] ) ) {
			wp_send_json_error( __( 'No domain change is in progress. Please start again.', 'open-migration' ) );
		}

		$pairs   = (array) $job['pairs'];
		$total   = count( $pairs );
		$index   = (int) $job['index'];
		$tables  = OWPM_FS::get_site_tables();
		$started = microtime( true );
		$budget  = OWPM_FS::batch_time_budget();

		while ( $index < $total ) {
			$pair  = $pairs[ $index ];
			$state = OWPM_Search_Replace::run_batch( $pair[0], $pair[1], $tables, $job['state'], $budget );

			if ( ! empty( $state['done'] ) ) {
				$job['rows']  = (int) $job['rows'] + (int) $state['rows'];
				$job['state'] = $this->fresh_state();
				$index++;
			} else {
				$job['state'] = $state;
			}

			if ( ( microtime( true ) - $started ) > $budget ) {
				break;
			}
		}

		$job['index'] = $index;
		$complete     = ( $index >= $total );

		if ( ! $complete ) {
			update_option( self::STATE_OPTION, $job, false );

			wp_send_json_success(
				array(
					'status'   => 'processing',
					'progress' => min( 99, (int) round( ( $index / max( 1, $total ) ) * 100 ) ),
					'rows'     => (int) $job['rows'],
				)
			);
		}

		// The replace pass rewrites the siteurl/home rows too, but set them
		// explicitly so the switch is complete even if those rows were unusual.
		update_option( 'siteurl', $job['new_url'] );
		update_option( 'home', $job['new_home'] );

		delete_option( self::STATE_OPTION );

		wp_cache_flush();

		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}

		wp_send_json_success(
			array(
				'status'   => 'complete',
				'progress' => 100,
				'rows'     => (int) $job['rows'],
				'old_url'  => $job['old_url'],
				'new_url'  => $job['new_url'],
				'admin_url' => trailingslashit( $job['new_url'] ) . 'wp-admin/',
			)
		);
	}
}
