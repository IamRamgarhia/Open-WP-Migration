<?php
/**
 * Snapshot of the destination database, taken before an import overwrites it.
 *
 * The point is simple: an import that fails part-way should never be the end of
 * the story. Up to now a half-replayed dump left the site with whatever had
 * been written so far and no way back, which is the one failure a migration
 * tool must not have.
 *
 * The snapshot renames rather than copies. RENAME TABLE is a metadata operation
 * -- near instant, no disk space, and an exact copy by definition. Dumping the
 * database to a file would need as much free space as the database, take as
 * long as an export, and have to resume across requests like one. Renaming has
 * none of those problems.
 *
 * The restore then creates its own fresh tables under the live prefix, and the
 * originals sit beside them untouched until the import either finishes (drop
 * them) or fails (rename them back).
 *
 * @package Open_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OWPM_Rollback {

	/**
	 * Prefix the snapshot tables are parked under.
	 *
	 * Deliberately does not begin with the site's own prefix: OWPM_FS::get_site_tables()
	 * scopes to $wpdb->prefix, and a snapshot that looked like a site table would
	 * be exported, replaced and dropped along with the real ones.
	 */
	const PREFIX = 'owpmbk_';

	/**
	 * File holding the current snapshot record.
	 *
	 * Deliberately not an option. wp_options is one of the tables this class
	 * renames away, so anything stored there becomes unreadable the moment a
	 * snapshot exists -- which is precisely when it has to be read. It also has
	 * to outlive a fatal error mid-import, which rules out anything in memory.
	 * The backup directory is already deny-all (see OWPM_FS::protect_dir).
	 */
	const STATE_FILE = '.owpm-rollback.json';

	/** MySQL's hard limit on an identifier. */
	const MAX_TABLE_NAME = 64;

	/**
	 * Where a clean install parks the folders it used to delete outright.
	 *
	 * The same reasoning as RENAME TABLE, applied to files. Moving a folder
	 * within one filesystem is a metadata operation: instant, no extra disk, and
	 * exactly reversible. Copying wp-content first would need as much free space
	 * as the site and take as long as an export.
	 *
	 * This sits inside the backup directory, which is already deny-all and on
	 * the same filesystem as wp-content/plugins.
	 *
	 * The directory existing *is* the record. Nothing is written to the state
	 * file about it, because the parking happens during extraction and the state
	 * file is not written until the database phase -- so an import that died in
	 * between would have left a state file claiming nothing and a pile of the
	 * operator's plugins in a hidden folder with no way to find out.
	 */
	const PARKED_DIR = 'parked/';

	/** Folders a clean install may park. Nothing else is ever moved. */
	const PARKED_AREAS = array( 'themes', 'plugins' );

	public static function parked_dir() {
		return OWPM_FS::backup_dir() . self::PARKED_DIR;
	}

	/**
	 * Move one theme or plugin folder aside instead of deleting it.
	 *
	 * Before this, "clean install" meant the operator's themes and plugins were
	 * gone for good: the database snapshot could put the options back but had
	 * nothing to say about files, so a rollback handed back a database
	 * referencing plugins that no longer existed. Parking makes the restore
	 * point cover both halves of the site.
	 *
	 * @param string $path Absolute folder to park.
	 * @param string $area One of PARKED_AREAS.
	 * @return bool
	 */
	public static function park_file( $path, $area ) {
		if ( ! in_array( $area, self::PARKED_AREAS, true ) || ! is_dir( $path ) || is_link( $path ) ) {
			return false;
		}

		$dir = self::parked_dir() . $area . '/';

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		OWPM_FS::protect_dir( self::parked_dir() );

		$target = $dir . basename( $path );

		if ( file_exists( $target ) ) {
			// Already parked by an earlier attempt. That copy predates this one,
			// so it is the one that represents the site before any of this began.
			return OWPM_FS::delete( $path );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- see the note on PARKED_DIR: an atomic same-filesystem rename is the entire point, and WP_Filesystem::move() falls back to copy-then-delete on any non-direct transport, which would need as much free disk as the folder and stop being reversible half way. WP_Filesystem also wants credentials, and this runs mid-import where there is nobody to ask. A failed park is reported to the caller, which leaves the folder in place rather than deleting it.
		return (bool) @rename( $path, $target );
	}

	/** How many folders are currently parked. */
	public static function files_parked() {
		$count = 0;

		foreach ( self::PARKED_AREAS as $area ) {
			foreach ( self::parked_entries( $area ) as $ignored ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @param string $area One of PARKED_AREAS.
	 * @return string[] Folder names.
	 */
	private static function parked_entries( $area ) {
		$dir = self::parked_dir() . $area;

		if ( ! is_dir( $dir ) ) {
			return array();
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable directory means nothing to restore.
		$entries = @scandir( $dir );

		if ( false === $entries ) {
			return array();
		}

		$found = array();

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			if ( is_dir( $dir . '/' . $entry ) ) {
				$found[] = $entry;
			}
		}

		return $found;
	}

	/**
	 * Put parked themes and plugins back where they came from.
	 *
	 * @return int Folders restored.
	 */
	public static function restore_files() {
		$content  = OWPM_FS::content_dir();
		$restored = 0;

		foreach ( self::PARKED_AREAS as $area ) {
			foreach ( self::parked_entries( $area ) as $entry ) {
				$from = self::parked_dir() . $area . '/' . $entry;
				$to   = $content . $area . '/' . $entry;

				// Never move a copy of this plugin over the running one: that
				// pulls the code out from under the request doing the restoring,
				// which is the fault that took a site down in 1.3.16.
				if ( 'plugins' === $area && 0 === strpos( strtolower( $entry ), 'open-migration' ) ) {
					continue;
				}

				if ( ! OWPM_FS::is_inside( $to, $content ) ) {
					continue;
				}

				// The archive may have brought its own folder of the same name.
				// This is a rollback, so the original wins.
				if ( file_exists( $to ) ) {
					OWPM_FS::delete( $to );
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- the counterpart of park_file(); same reasoning. Counted, and anything that fails stays parked for another attempt rather than being lost.
				if ( @rename( $from, $to ) ) {
					++$restored;
				}
			}
		}

		self::discard_files();

		return $restored;
	}

	/** Throw away parked folders. Called once the import is known to be good. */
	public static function discard_files() {
		$dir = self::parked_dir();

		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$count = self::files_parked();

		OWPM_FS::delete( $dir );

		return $count;
	}

	/**
	 * Park the site's current tables out of the way.
	 *
	 * @param string $file_id Import this snapshot belongs to.
	 * @return array{taken:bool,tables:int,reason:string}
	 */
	public static function take( $file_id ) {
		global $wpdb;

		// Deliberately the table record and not exists(), which also counts
		// parked files. A clean install parks folders during extraction, so by
		// the time this runs exists() is already true for the very import asking
		// for the snapshot -- and reading it here meant no clean install ever got
		// a database snapshot at all. Only an earlier *table* snapshot is a
		// reason to stand down.
		if ( self::has_table_snapshot() ) {
			// A previous import left one behind. Keep it: it is older, which makes
			// it the one that represents the site before any of this started.
			return array(
				'taken'  => false,
				'tables' => 0,
				'reason' => 'existing',
			);
		}

		$tables = OWPM_FS::get_site_tables();

		if ( empty( $tables ) ) {
			return array(
				'taken'  => false,
				'tables' => 0,
				'reason' => 'no_tables',
			);
		}

		foreach ( $tables as $table ) {
			if ( strlen( self::PREFIX . $table ) > self::MAX_TABLE_NAME ) {
				// Renaming would produce an invalid identifier. Better to run without
				// a snapshot than to half-make one.
				return array(
					'taken'  => false,
					'tables' => 0,
					'reason' => 'name_too_long',
				);
			}
		}

		// Read before the rename: afterwards there is no options table to read.
		//
		// These four are what it takes for WordPress to stay usable while the
		// real options table is parked, and the set was arrived at the hard way:
		//
		//   siteurl / home  is_blog_installed() answers yes, so wp_not_installed()
		//                   does not send every visitor to install.php. That was
		//                   the 1.5.1 security fix.
		//   db_version      wp-admin compares it against $wp_db_version and
		//                   redirects to upgrade.php when they differ. Absent, it
		//                   differed from everything, so the dashboard was
		//                   unreachable for as long as the snapshot existed.
		//   active_plugins  with no value, WordPress loads NO plugins -- this one
		//                   included. That left no admin page and no "Restore this
		//                   site" button in the one state where they are the point,
		//                   and no wp_ajax_owpm_import_db handler either, so an
		//                   import whose dump needed a second request could not
		//                   continue past this line.
		$siteurl = (string) get_option( 'siteurl' );
		$home    = (string) get_option( 'home' );

		$renamed = array();

		foreach ( $tables as $table ) {
			$from = OWPM_FS::esc_table( $table );
			$to   = OWPM_FS::esc_table( self::PREFIX . $table );

			if ( ! $from || ! $to ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $from, $to, $live, $parked and $table all come from OWPM_FS::esc_table(), which returns false for anything outside [A-Za-z0-9_$-] and backticks what is left, so no quote, backtick, space or semicolon can reach the statement. prepare() cannot bind an identifier -- %s would quote the table name into a string literal. The names are read from SHOW TABLES, never from a request.
			$ok = $wpdb->query( "RENAME TABLE {$from} TO {$to}" );

			if ( false === $ok ) {
				// Undo the ones already moved rather than leave the site split in two.
				self::rename_back( $renamed );

				return array(
					'taken'  => false,
					'tables' => 0,
					'reason' => 'rename_failed',
				);
			}

			$renamed[] = $table;
		}

		// Critical. With every table parked, WordPress finds no options table,
		// is_blog_installed() returns false, and wp_not_installed() redirects
		// every visitor to wp-admin/install.php -- where anyone at all can set
		// up a new site on this database and make themselves its administrator.
		// That window would last for the whole database restore, minutes on a
		// large site, and needs no credentials to walk through.
		//
		// A stand-in options table carrying siteurl is enough for
		// is_blog_installed() to return true, so the installer stays shut. The
		// dump drops and recreates this table like any other, and a rollback
		// drops it before renaming the real one home.
		self::create_installed_marker( $siteurl, $home );

		self::write_state(
			array(
				'file_id' => (string) $file_id,
				'tables'  => $renamed,
				'taken'   => time(),
				'siteurl' => $siteurl,
				'home'    => $home,
			)
		);

		self::write_recovery_note( $renamed );

		return array(
			'taken'  => true,
			'tables' => count( $renamed ),
			'reason' => '',
		);
	}

	/**
	 * Leave instructions on disk for the case where the dashboard is gone.
	 *
	 * Everything else here assumes someone can reach wp-admin and press a
	 * button. The failure this is for is the one where they cannot: a fatal
	 * during the import takes the whole site down, including the screen with
	 * the restore button on it. A note in the backup folder is reachable over
	 * FTP or a file manager when nothing else is, and it carries the exact SQL
	 * for this specific site -- real table names, no guessing.
	 *
	 * Plain text on purpose: it has to be readable in whatever the host's file
	 * manager opens it with.
	 *
	 * @param string[] $tables Live table names now parked under PREFIX.
	 */
	private static function write_recovery_note( $tables ) {
		$dir = OWPM_FS::backup_dir();

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$restore = '';
		$discard = '';

		// Written as a path relative to the site root, because the only tool
		// available to someone locked out of wp-admin is an FTP client or the
		// host's file manager, and both start there.
		$parked_path = str_replace(
			str_replace( '\\', '/', ABSPATH ),
			'',
			str_replace( '\\', '/', self::parked_dir() )
		);

		foreach ( $tables as $table ) {
			$restore .= "DROP TABLE IF EXISTS `{$table}`;\n";
			$discard .= 'DROP TABLE IF EXISTS `' . self::PREFIX . $table . "`;\n";
		}

		foreach ( $tables as $table ) {
			$restore .= 'RENAME TABLE `' . self::PREFIX . $table . "` TO `{$table}`;\n";
		}

		$note = "OPEN MIGRATION - HOW TO GET THIS SITE BACK\n"
			. 'Written ' . gmdate( 'Y-m-d H:i:s' ) . " UTC, when an import started.\n"
			. str_repeat( '=', 64 ) . "\n\n"
			. "If the import finished and the site is fine, ignore this file. It is\n"
			. "removed automatically once an import completes.\n\n"
			. "If the site is broken, work down this list. Stop when it loads.\n\n"
			. "1. TURN OFF ALL PLUGINS WITHOUT THE DASHBOARD\n"
			. "   Over FTP or your host's file manager, rename the folder\n"
			. "       wp-content/plugins\n"
			. "   to\n"
			. "       wp-content/plugins-off\n"
			. "   Reload the site. WordPress cannot load any plugin it cannot find, so\n"
			. "   if a plugin is the problem the site comes back. Log in, rename the\n"
			. "   folder back, then activate plugins one at a time to find the culprit.\n\n"
			. "2. IF IT IS THE THEME\n"
			. "   Rename wp-content/themes/<your-theme> the same way. WordPress falls\n"
			. "   back to a default theme.\n\n"
			. "3. SEE THE ACTUAL ERROR\n"
			. "   Add these lines to wp-config.php, above the line that says\n"
			. "   \"That's all, stop editing\":\n\n"
			. "       define( 'WP_DEBUG', true );\n"
			. "       define( 'WP_DEBUG_LOG', true );\n"
			. "       define( 'WP_DEBUG_DISPLAY', false );\n\n"
			. "   Reload the site once, then read wp-content/debug.log.\n\n"
			. "4. PUT THE DATABASE BACK AS IT WAS\n"
			. "   This import parked your original tables rather than deleting them.\n"
			. '   They are still there, under the prefix "' . self::PREFIX . "\".\n\n"
			. "   Easiest way: log in to wp-admin, open Open Migration, and use the\n"
			. "   \"Restore this site\" button on the Import tab.\n\n"
			. "   If you cannot log in, open phpMyAdmin (or Adminer) on this database,\n"
			. "   go to the SQL tab, and run exactly this:\n\n"
			. $restore . "\n"
			. "   That deletes what the import created and moves your original tables\n"
			. "   back. Take a database export first if you can -- it costs a minute\n"
			. "   and this step cannot be undone.\n\n"
			. "5. YOUR THEMES AND PLUGINS, IF YOU CHOSE \"CLEAN INSTALL\"\n"
			. "   They were moved aside, not deleted. They are in:\n\n"
			. '       ' . $parked_path . "\n\n"
			. "   Inside that you will find themes/ and plugins/, each holding the\n"
			. "   folders as they were. To put one back by hand, move its folder into\n"
			. "   wp-content/themes or wp-content/plugins. The \"Restore this site\"\n"
			. "   button does all of them at once if you can reach wp-admin.\n\n"
			. "6. WHEN EVERYTHING IS FINE AGAIN\n"
			. "   The parked copies are only taking up space. Remove them with:\n\n"
			. $discard . "\n"
			. str_repeat( '=', 64 ) . "\n"
			. "Docs: https://dicecodes.com/open-migration/\n"
			. "Support: https://wordpress.org/support/plugin/open-migration-unlimited-site-transfer-backup/\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a failed note must not stop the import.
		@file_put_contents( $dir . 'READ-ME-IF-THE-SITE-IS-BROKEN.txt', $note );
	}

	/**
	 * Put the site back as it was.
	 *
	 * Drops whatever the import managed to create, then moves the originals home.
	 *
	 * @return array{restored:bool,tables:int}
	 */
	public static function restore() {
		global $wpdb;

		// Files first, and deliberately not gated on the table snapshot. A clean
		// install parks folders during extraction, which happens before the
		// database phase takes its snapshot -- so an import that died in between
		// has parked files and no table record at all. That is precisely the
		// state that needs rescuing, and the old early return walked straight
		// past it.
		$files = self::restore_files();

		$snapshot = self::read_state();

		if ( ! is_array( $snapshot ) || empty( $snapshot['tables'] ) ) {
			return array(
				'restored' => ( $files > 0 ),
				'tables'   => 0,
				'files'    => $files,
			);
		}

		foreach ( $snapshot['tables'] as $table ) {
			$live = OWPM_FS::esc_table( $table );

			if ( ! $live ) {
				continue;
			}

			// Dropping tables is the operation, not a mistake: this is the step
			// that clears what a failed import wrote before the originals are
			// renamed back over it.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $from, $to, $live, $parked and $table all come from OWPM_FS::esc_table(), which returns false for anything outside [A-Za-z0-9_$-] and backticks what is left, so no quote, backtick, space or semicolon can reach the statement. prepare() cannot bind an identifier -- %s would quote the table name into a string literal. The names are read from SHOW TABLES, never from a request.
			$wpdb->query( "DROP TABLE IF EXISTS {$live}" );
		}

		$count = self::rename_back( $snapshot['tables'] );

		self::clear_state();
		OWPM_FS::delete( OWPM_FS::backup_dir() . 'READ-ME-IF-THE-SITE-IS-BROKEN.txt' );

		// The options table has just been swapped underneath us, so anything read
		// earlier in this request is stale.
		wp_cache_flush();

		return array(
			'restored' => true,
			'tables'   => $count,
			'files'    => $files,
		);
	}

	/**
	 * Throw the snapshot away. Called once an import has finished successfully.
	 *
	 * @return int Tables dropped.
	 */
	public static function discard() {
		global $wpdb;

		// Parked folders are dropped whether or not a table record exists: the
		// two are written at different stages and either can be present alone.
		self::discard_files();

		$snapshot = self::read_state();

		if ( ! is_array( $snapshot ) || empty( $snapshot['tables'] ) ) {
			self::clear_state();
			return 0;
		}

		$dropped = 0;

		foreach ( $snapshot['tables'] as $table ) {
			$parked = OWPM_FS::esc_table( self::PREFIX . $table );

			if ( ! $parked ) {
				continue;
			}

			// Discarding a snapshot means dropping the parked copies; there is
			// no non-schema way to give the disk space back.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $from, $to, $live, $parked and $table all come from OWPM_FS::esc_table(), which returns false for anything outside [A-Za-z0-9_$-] and backticks what is left, so no quote, backtick, space or semicolon can reach the statement. prepare() cannot bind an identifier -- %s would quote the table name into a string literal. The names are read from SHOW TABLES, never from a request.
			if ( false !== $wpdb->query( "DROP TABLE IF EXISTS {$parked}" ) ) {
				++$dropped;
			}
		}

		self::clear_state();

		// The recovery note describes tables that no longer exist. Leaving it
		// would send someone to phpMyAdmin to run SQL against nothing.
		OWPM_FS::delete( OWPM_FS::backup_dir() . 'READ-ME-IF-THE-SITE-IS-BROKEN.txt' );

		return $dropped;
	}

	/**
	 * Is there a snapshot waiting to be restored or discarded?
	 */
	/**
	 * Is a database snapshot recorded? Parked files do not count.
	 *
	 * Separate from exists() because the two callers want different questions
	 * answered. take() must know whether the *database* is already snapshotted;
	 * the UI must know whether there is anything at all to restore.
	 */
	private static function has_table_snapshot() {
		$snapshot = self::read_state();

		return is_array( $snapshot ) && ! empty( $snapshot['tables'] );
	}

	public static function exists() {
		if ( self::has_table_snapshot() ) {
			return true;
		}

		// Parked themes and plugins are a restore point in their own right. An
		// import that failed during extraction has those and nothing else, and
		// reporting "nothing to restore" there would leave the operator's files
		// sitting in a hidden folder with no button to bring them back.
		return self::files_parked() > 0;
	}

	/**
	 * Details for the notice shown when an import did not finish.
	 *
	 * @return array{tables:int,taken:int,siteurl:string}|false
	 */
	public static function details() {
		$snapshot = self::read_state();
		$files    = self::files_parked();

		if ( ! is_array( $snapshot ) || empty( $snapshot['tables'] ) ) {
			// Files alone still count. See exists().
			if ( $files < 1 ) {
				return false;
			}

			// The parked directory's own timestamp. There is no state file to read
			// one from -- parking happens during extraction, before the database
			// phase writes it -- and a zero here reaches human_time_diff() as the
			// epoch, which renders as "56 years ago".
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable directory just means no timestamp.
			$when = @filemtime( self::parked_dir() );

			return array(
				'tables'  => 0,
				'files'   => $files,
				'taken'   => $when ? (int) $when : time(),
				'siteurl' => '',
			);
		}

		return array(
			'tables'  => count( $snapshot['tables'] ),
			'files'   => $files,
			'taken'   => isset( $snapshot['taken'] ) ? (int) $snapshot['taken'] : 0,
			'siteurl' => isset( $snapshot['siteurl'] ) ? (string) $snapshot['siteurl'] : '',
		);
	}

	/**
	 * Stand up a minimal options table so WordPress still sees an installed site.
	 *
	 * is_blog_installed() returns true as soon as it can read siteurl, and
	 * wp_not_installed() only redirects to the installer when that is false. So
	 * this table does not have to be complete or correct -- it has to exist and
	 * answer that one question, for the few minutes between parking the real
	 * tables and the dump recreating them.
	 *
	 * Mirrors core's options schema so the dump's own CREATE TABLE replaces it
	 * without complaint. autoload uses 'yes', which every version reads,
	 * including 6.6 and later that write 'on'.
	 *
	 * @param string $siteurl Address read before the rename.
	 * @param string $home    Home address read before the rename.
	 */
	private static function create_installed_marker( $siteurl, $home ) {
		global $wpdb, $wp_db_version;

		$table = OWPM_FS::esc_table( $wpdb->options );

		if ( ! $table ) {
			return;
		}

		$charset = $wpdb->get_charset_collate();

		// $table is a backticked identifier from esc_table() and $charset comes
		// from wpdb itself. Neither can be bound as a placeholder -- prepare()
		// binds values, not identifiers or charset clauses. Spans several lines,
		// so disable/enable rather than a single-line ignore.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				option_id bigint(20) unsigned NOT NULL auto_increment,
				option_name varchar(191) NOT NULL default '',
				option_value longtext NOT NULL,
				autoload varchar(20) NOT NULL default 'yes',
				PRIMARY KEY  (option_id),
				UNIQUE KEY option_name (option_name)
			) {$charset}"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Only this plugin. Loading the destination's other plugins against a
		// database that is currently one table wide is how a rescue turns into a
		// fatal, and third-party code is not needed to finish an import or press
		// a restore button. The real list comes back with the dump, or with the
		// rollback.
		$self = 'open-migration-unlimited-site-transfer-backup/open-migration-unlimited-site-transfer-backup.php';

		if ( defined( 'OWPM_PLUGIN_DIR' ) ) {
			$folder = basename( untrailingslashit( OWPM_PLUGIN_DIR ) );
			$self   = $folder . '/open-migration-unlimited-site-transfer-backup.php';
		}

		// $wp_db_version, not the live value: wp-admin redirects to upgrade.php
		// unless they match exactly, and a dashboard that redirects is a
		// dashboard with no restore button on it.
		$db_version = isset( $wp_db_version ) ? (string) $wp_db_version : (string) get_option( 'db_version' );

		foreach ( array(
			'siteurl'        => $siteurl,
			'home'           => $home,
			'blogname'       => __( 'Migration in progress', 'open-migration-unlimited-site-transfer-backup' ),
			'db_version'     => $db_version,
			'active_plugins' => serialize( array( $self ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the options table stores PHP-serialised values; this is the format WordPress will read it back in.
		) as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- only the table name is interpolated, and it is a backticked identifier from esc_table(); every value below is bound by prepare().
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a validated identifier.
					"INSERT INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'yes')
						ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
					$name,
					$value
				)
			);
		}
	}

	/**
	 * Path of the snapshot record.
	 */
	private static function state_path() {
		return OWPM_FS::backup_dir() . self::STATE_FILE;
	}

	/**
	 * @param array $state Snapshot record.
	 */
	private static function write_state( $state ) {
		OWPM_FS::init_backup_dir();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- checked by the caller through read_state().
		@file_put_contents( self::state_path(), wp_json_encode( $state ), LOCK_EX );
	}

	/**
	 * @return array|false
	 */
	private static function read_state() {
		$path = self::state_path();

		if ( ! file_exists( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a missing or unreadable file means no snapshot.
		$raw = @file_get_contents( $path );

		if ( false === $raw ) {
			return false;
		}

		$state = json_decode( $raw, true );

		if ( ! is_array( $state ) || empty( $state['tables'] ) ) {
			return false;
		}

		// The record can outlive what it describes: someone restores a database
		// by hand, moves the site, or drops the parked tables in phpMyAdmin.
		// Left alone it would block every future snapshot and offer a rollback
		// that cannot work, so check the tables are really still there.
		if ( ! self::parked_tables_exist( $state['tables'] ) ) {
			self::clear_state();
			return false;
		}

		return $state;
	}

	/**
	 * Is at least one parked table still in the database?
	 *
	 * @param string[] $tables Live table names.
	 */
	private static function parked_tables_exist( $tables ) {
		global $wpdb;

		foreach ( $tables as $table ) {
			$parked = self::PREFIX . $table;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $parked ) ) );

			if ( $found ) {
				return true;
			}
		}

		return false;
	}

	private static function clear_state() {
		OWPM_FS::delete( self::state_path() );
	}

	/**
	 * Move parked tables back to their live names.
	 *
	 * @param string[] $tables Live table names.
	 * @return int How many came back.
	 */
	private static function rename_back( $tables ) {
		global $wpdb;

		$count = 0;

		foreach ( $tables as $table ) {
			$parked = OWPM_FS::esc_table( self::PREFIX . $table );
			$live   = OWPM_FS::esc_table( $table );

			if ( ! $parked || ! $live ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $from, $to, $live, $parked and $table all come from OWPM_FS::esc_table(), which returns false for anything outside [A-Za-z0-9_$-] and backticks what is left, so no quote, backtick, space or semicolon can reach the statement. prepare() cannot bind an identifier -- %s would quote the table name into a string literal. The names are read from SHOW TABLES, never from a request.
			if ( false !== $wpdb->query( "RENAME TABLE {$parked} TO {$live}" ) ) {
				++$count;
			}
		}

		return $count;
	}
}
