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
	 * Park the site's current tables out of the way.
	 *
	 * @param string $file_id Import this snapshot belongs to.
	 * @return array{taken:bool,tables:int,reason:string}
	 */
	public static function take( $file_id ) {
		global $wpdb;

		if ( self::exists() ) {
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
		$siteurl = (string) get_option( 'siteurl' );
		$home    = (string) get_option( 'home' );

		$renamed = array();

		foreach ( $tables as $table ) {
			$from = OWPM_FS::esc_table( $table );
			$to   = OWPM_FS::esc_table( self::PREFIX . $table );

			if ( ! $from || ! $to ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
			. "5. WHEN EVERYTHING IS FINE AGAIN\n"
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

		$snapshot = self::read_state();

		if ( ! is_array( $snapshot ) || empty( $snapshot['tables'] ) ) {
			return array(
				'restored' => false,
				'tables'   => 0,
			);
		}

		foreach ( $snapshot['tables'] as $table ) {
			$live = OWPM_FS::esc_table( $table );

			if ( ! $live ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		);
	}

	/**
	 * Throw the snapshot away. Called once an import has finished successfully.
	 *
	 * @return int Tables dropped.
	 */
	public static function discard() {
		global $wpdb;

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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	public static function exists() {
		$snapshot = self::read_state();

		return is_array( $snapshot ) && ! empty( $snapshot['tables'] );
	}

	/**
	 * Details for the notice shown when an import did not finish.
	 *
	 * @return array{tables:int,taken:int,siteurl:string}|false
	 */
	public static function details() {
		$snapshot = self::read_state();

		if ( ! is_array( $snapshot ) || empty( $snapshot['tables'] ) ) {
			return false;
		}

		return array(
			'tables'  => count( $snapshot['tables'] ),
			'taken'   => isset( $snapshot['taken'] ) ? (int) $snapshot['taken'] : 0,
			'siteurl' => isset( $snapshot['siteurl'] ) ? (string) $snapshot['siteurl'] : '',
		);
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

		return is_array( $state ) ? $state : false;
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false !== $wpdb->query( "RENAME TABLE {$parked} TO {$live}" ) ) {
				++$count;
			}
		}

		return $count;
	}
}
