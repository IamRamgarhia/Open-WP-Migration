<?php
/**
 * Shared filesystem, path-safety and environment helpers.
 *
 * Everything that touches the disk goes through here so that path handling,
 * directory hardening and cleanup behave identically in every code path.
 *
 * @package Open_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OWPM_FS {

	/**
	 * Folder inside uploads that holds backups. Named after the plugin slug, as
	 * the directory guidelines recommend for plugin-created files.
	 */
	const BACKUP_DIR_NAME = 'open-migration-unlimited-site-transfer-backup';

	/**
	 * Top-level folder inside the archive holding uploads that lived outside the
	 * content directory on the source. Restored into whatever wp_upload_dir()
	 * reports on the destination, so a relocated uploads folder on either side
	 * lands in the right place.
	 */
	const EXTERNAL_UPLOADS_ENTRY = 'owpm-uploads';

	/**
	 * Seconds a single request may run for once limits are raised.
	 *
	 * Deliberately a number rather than 0. The work here is already batched --
	 * a batch yields to the browser after batch_time_budget() seconds -- so
	 * unlimited execution time was never what kept an export alive; it only
	 * removed the ceiling that stops a wedged request occupying a worker for
	 * ever. The ceiling is generous because one indivisible unit of work, such
	 * as copying a single very large file, can outlast a whole batch budget.
	 */
	const TIME_LIMIT = 900;

	/**
	 * An unguessable file or folder name for something that must not be read
	 * over HTTP.
	 *
	 * The backup directory lives under uploads, which is web-served, and the
	 * guards written into it by protect_dir() are Apache and IIS only. Nginx
	 * ignores .htaccess entirely, so on that stack the only thing standing
	 * between a sensitive file and the open web is whether its address can be
	 * worked out.
	 *
	 * wp_hash() is keyed on the site's own salts, so these names cannot be
	 * derived from the plugin's source the way a fixed name can. Somebody with
	 * FTP or a file manager still sees them in the directory listing, which is
	 * the only place they are needed.
	 *
	 * @param string $purpose Stable label for the thing being named.
	 * @return string Hex name, no path and no extension.
	 */
	public static function secret_name( $purpose ) {
		return wp_hash( 'owpm-file|' . (string) $purpose );
	}

	/**
	 * Absolute path (trailing slash) of the working/backup directory.
	 *
	 * Always resolved through wp_upload_dir() rather than assembled from
	 * WP_CONTENT_DIR, so it stays correct on installs with a custom uploads path.
	 */
	public static function backup_dir() {
		$upload_dir = wp_upload_dir();

		return trailingslashit( $upload_dir['basedir'] ) . self::BACKUP_DIR_NAME . '/';
	}

	/**
	 * Path of the uploads directory itself, or '' if WordPress cannot report one.
	 */
	public static function uploads_dir() {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return '';
		}

		return trailingslashit( $upload_dir['basedir'] );
	}

	/**
	 * Absolute path (trailing slash) of the content directory.
	 *
	 * WP_CONTENT_DIR is the API for this: it is the documented way to locate the
	 * content directory, and it already reflects a relocated wp-content. It is
	 * read here and nowhere else, so there is a single place to audit.
	 *
	 * This is never used to find the plugin's own files -- those come from
	 * plugin_dir_path()/OWPM_PLUGIN_DIR -- nor to find uploads, which come from
	 * wp_upload_dir(). It identifies the directory being archived and restored.
	 */
	public static function content_dir() {
		return trailingslashit( str_replace( '\\', '/', WP_CONTENT_DIR ) );
	}

	/**
	 * Name of the content directory, e.g. 'wp-content' or a renamed one.
	 */
	public static function content_name() {
		return basename( untrailingslashit( self::content_dir() ) );
	}

	/**
	 * Absolute path (trailing slash) of the plugins directory.
	 *
	 * WP_PLUGIN_DIR is the documented way to find it and already accounts for a
	 * relocated plugins folder. Read here and nowhere else, like content_dir().
	 * Used to check whether a plugin a restored database claims is active
	 * actually exists on this server -- not to locate this plugin's own files,
	 * which come from OWPM_PLUGIN_DIR.
	 */
	public static function plugins_dir() {
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			return trailingslashit( str_replace( '\\', '/', WP_PLUGIN_DIR ) );
		}

		return self::content_dir() . 'plugins/';
	}

	/**
	 * Absolute path (trailing slash) of the WordPress root.
	 *
	 * Recorded in the archive so absolute paths stored in the source database can
	 * be rewritten to the destination's root on restore. ABSPATH is the only way
	 * to obtain it; get_home_path() reports the home directory, which differs when
	 * WordPress lives in a subdirectory, and is admin-only.
	 */
	public static function root_dir() {
		return trailingslashit( str_replace( '\\', '/', ABSPATH ) );
	}

	/**
	 * Uploads directory, but only when it sits outside the content directory.
	 *
	 * Uploads can be relocated with the UPLOADS constant or the upload_path
	 * option. When that happens, walking the content directory alone misses every
	 * uploaded file, so the export has to treat uploads as a second root. Returns
	 * '' on the usual layout, where uploads are already covered.
	 */
	public static function external_uploads_dir() {
		$uploads = self::uploads_dir();

		if ( '' === $uploads ) {
			return '';
		}

		$uploads = trailingslashit( str_replace( '\\', '/', $uploads ) );

		if ( 0 === strpos( $uploads, self::content_dir() ) ) {
			return '';
		}

		return $uploads;
	}

	/**
	 * Create the working directory and make sure it is not web readable.
	 *
	 * Downloads are served through PHP (see OWPM_Export::handle_download), so the
	 * directory itself never needs to be publicly reachable.
	 */
	public static function init_backup_dir() {
		$dir = self::backup_dir();

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		self::protect_dir( $dir );

		return $dir;
	}

	/**
	 * Drop deny-all guards for Apache 2.2, Apache 2.4, IIS and directory listing.
	 */
	public static function protect_dir( $dir ) {
		$dir = trailingslashit( $dir );

		if ( ! is_dir( $dir ) ) {
			return;
		}

		// Apache 2.4 uses mod_authz_core; 2.2 needs the legacy directives. Ship both.
		$htaccess = "# Open Migration - deny all direct access\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
			. "Options -Indexes\n";

		self::put_contents_if_changed( $dir . '.htaccess', $htaccess );

		$web_config = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
			. "<configuration>\n\t<system.webServer>\n\t\t<authorization>\n"
			. "\t\t\t<deny users=\"*\" />\n"
			. "\t\t</authorization>\n\t</system.webServer>\n</configuration>\n";

		self::put_contents_if_changed( $dir . 'web.config', $web_config );
		self::put_contents_if_changed( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
		self::put_contents_if_changed( $dir . 'index.html', '' );
	}

	/**
	 * Write only when the content differs, so we do not churn the disk on every request.
	 */
	private static function put_contents_if_changed( $path, $content ) {
		if ( file_exists( $path ) ) {
			$current = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $current && $current === $content ) {
				return;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		@file_put_contents( $path, $content, LOCK_EX );
	}

	/**
	 * Reduce an untrusted identifier to characters that can never escape a directory.
	 *
	 * Note: sanitize_text_field() does NOT strip slashes or dots, so it is not
	 * sufficient on its own for anything used to build a filesystem path.
	 */
	public static function sanitize_id( $id ) {
		$id = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $id );
		return substr( (string) $id, 0, 128 );
	}

	/**
	 * Confirm that $path really lives inside $base.
	 *
	 * The path does not have to exist yet. During an import most destination
	 * directories (themes, plugins, uploads/2024/…) are still missing, so this
	 * resolves the deepest ancestor that does exist with realpath() — which
	 * collapses symlinks — and then appends the remaining segments literally.
	 * Using realpath() on the full path would return false and reject every
	 * legitimate file on a fresh install.
	 */
	public static function is_inside( $path, $base ) {
		$real_base = realpath( $base );
		if ( ! $real_base ) {
			return false;
		}

		$real_base = rtrim( str_replace( '\\', '/', $real_base ), '/' );

		$path = str_replace( '\\', '/', (string) $path );
		if ( '' === $path ) {
			return false;
		}

		// Peel off trailing segments until we reach something on disk.
		$suffix = array();
		$probe  = $path;

		while ( '' !== $probe && ! file_exists( $probe ) ) {
			$parent = str_replace( '\\', '/', dirname( $probe ) );
			if ( $parent === $probe ) {
				break;
			}
			array_unshift( $suffix, basename( $probe ) );
			$probe = $parent;
		}

		$real = realpath( $probe );
		if ( ! $real ) {
			return false;
		}

		$resolved = rtrim( str_replace( '\\', '/', $real ), '/' );

		foreach ( $suffix as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				$resolved = rtrim( str_replace( '\\', '/', dirname( $resolved ) ), '/' );
				continue;
			}

			$resolved .= '/' . $segment;
		}

		return $resolved === $real_base || 0 === strpos( $resolved, $real_base . '/' );
	}

	/**
	 * Recursively delete a file or directory using plain PHP.
	 *
	 * WP_Filesystem() can return false on hosts that require FTP/SSH credentials,
	 * which used to leave callers dereferencing a non-object. This never does.
	 */
	public static function delete( $path ) {
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return true;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			return @unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$items = @scandir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Return value is checked; an unsuppressed warning would corrupt the JSON body of the AJAX response.
		if ( false !== $items ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				self::delete( trailingslashit( $path ) . $item );
			}
		}

		// WP_Filesystem is deliberately not used here: it returns false on hosts
		// that require FTP credentials, and this helper must work unconditionally
		// during cleanup.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
		return @rmdir( $path );
	}

	/**
	 * Move a file, falling back to copy+unlink when rename() fails across volumes.
	 */
	public static function move( $from, $to ) {
		$dir = dirname( $to );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( file_exists( $to ) && ! is_dir( $to ) ) {
			@unlink( $to ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
		}

		// rename() is an atomic same-volume move; WP_Filesystem::move() cannot be
		// relied on because WP_Filesystem() may be unavailable on this host.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( @rename( $from, $to ) ) {
			return true;
		}

		if ( @copy( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Return value is checked; an unsuppressed warning would corrupt the JSON body of the AJAX response.
			@unlink( $from ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			return true;
		}

		return false;
	}

	/**
	 * Give long-running export/import batches the headroom they need.
	 *
	 * Only the steps that genuinely need it call this. It used to raise the
	 * limit to 0 -- no limit at all -- for every step including a two-megabyte
	 * upload chunk, which is a normal short write that any default limit
	 * already covers. Removing the ceiling site-wide for work that does not
	 * need it is what the Plugins Team objected to, and they were right: a
	 * host that caps execution time does so deliberately, and a wedged request
	 * with no limit holds a PHP worker until the server kills it.
	 *
	 * @param int $seconds Headroom to ask for. Clamped; never unlimited.
	 */
	public static function raise_limits( $seconds = self::TIME_LIMIT ) {
		if ( function_exists( 'set_time_limit' ) ) {
			$disabled = explode( ',', (string) ini_get( 'disable_functions' ) );
			$disabled = array_map( 'trim', $disabled );
			if ( ! in_array( 'set_time_limit', $disabled, true ) ) {
				/**
				 * Filter the per-request execution ceiling for heavy steps.
				 *
				 * @param int $seconds Default OWPM_FS::TIME_LIMIT.
				 */
				$seconds = (int) apply_filters( 'owpm_time_limit', (int) $seconds );

				// A floor as well as a ceiling: a filter returning 0 would be
				// read by PHP as "no limit", which is the thing being fixed.
				// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
				@set_time_limit( max( 30, min( 3600, $seconds ) ) );
			}
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Seconds a single AJAX batch is allowed to run before it yields to the browser.
	 *
	 * Clamped to a sane range: a filter returning 0 or a negative number would
	 * otherwise mean "yield after every item" in some loops and "never yield" in
	 * others, and an unbounded value would defeat the batching entirely.
	 */
	public static function batch_time_budget() {
		/**
		 * Filter the per-request time budget for export/import batches.
		 *
		 * @param int $seconds Default 15.
		 */
		$seconds = (int) apply_filters( 'owpm_batch_time_budget', 15 );

		// 0 is legitimate and means "yield after each unit of work" in every loop.
		return max( 0, min( 300, $seconds ) );
	}

	/**
	 * Escape a table name for direct interpolation.
	 *
	 * Returns false for anything that is not a plain identifier, so a malformed
	 * name can never be spliced into a query.
	 */
	public static function esc_table( $table ) {
		if ( ! is_string( $table ) || ! preg_match( '/^[A-Za-z0-9_$\-]+$/', $table ) ) {
			return false;
		}
		return '`' . $table . '`';
	}

	/**
	 * Escape a column name for interpolation, or false if it is not a plain identifier.
	 */
	public static function esc_column( $column ) {
		if ( ! is_string( $column ) || '' === $column || false !== strpos( $column, '`' ) ) {
			return false;
		}
		return '`' . $column . '`';
	}

	/**
	 * Normalised column list for a table: name, type and whether it is a key part.
	 *
	 * @param string $escaped_table Already-escaped table name from esc_table().
	 * @return array{all:string[],primary:string[],types:array<string,string>}
	 */
	public static function table_columns( $escaped_table ) {
		global $wpdb;

		$out = array(
			'all'     => array(),
			'primary' => array(),
			'types'   => array(),
		);

		// $escaped_table comes from esc_table(), which returns false for anything
		// that is not a bare identifier, so it can never carry injectable content.
		// Table names cannot be bound as prepare() values.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$columns = $wpdb->get_results( "DESCRIBE {$escaped_table}" );

		if ( ! is_array( $columns ) ) {
			return $out;
		}

		foreach ( $columns as $column ) {
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( ! isset( $column->Field ) || ! self::esc_column( $column->Field ) ) {
				continue;
			}

			$field = $column->Field;

			$out['all'][]           = $field;
			$out['types'][ $field ] = isset( $column->Type ) ? strtolower( (string) $column->Type ) : '';

			// Collect every PRI column: composite primary keys are real.
			if ( isset( $column->Key ) && 'PRI' === $column->Key ) {
				$out['primary'][] = $field;
			}
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		return $out;
	}

	/**
	 * A deterministic ORDER BY clause for paginating a table.
	 *
	 * LIMIT/OFFSET without an ORDER BY has no guaranteed row order, so paging
	 * across separate requests on a live site can skip or repeat rows. Ordering by
	 * the primary key makes the window stable; tables without one fall back to
	 * ordering by every column, which is slower but still deterministic.
	 *
	 * @return string Clause beginning with " ORDER BY", or '' if it cannot be built.
	 */
	public static function order_by_clause( $escaped_table ) {
		$columns = self::table_columns( $escaped_table );
		$keys    = ! empty( $columns['primary'] ) ? $columns['primary'] : $columns['all'];

		if ( empty( $keys ) ) {
			return '';
		}

		$parts = array();

		foreach ( $keys as $column ) {
			$escaped = self::esc_column( $column );
			if ( $escaped ) {
				$parts[] = $escaped;
			}
		}

		return $parts ? ' ORDER BY ' . implode( ', ', $parts ) : '';
	}

	/**
	 * All tables belonging to this WordPress install only.
	 *
	 * `SHOW TABLES` returns every table in the database, which on shared databases
	 * means other sites' data. Always scope to the current prefix.
	 */
	public static function get_site_tables() {
		global $wpdb;

		$like = $wpdb->esc_like( $wpdb->prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		if ( ! is_array( $tables ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$tables,
				function ( $table ) {
					return (bool) self::esc_table( $table );
				}
			)
		);
	}
}
