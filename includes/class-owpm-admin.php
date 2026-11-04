<?php
/**
 * Admin screen registration and asset loading.
 *
 * @package Open_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OWPM_Admin {

	const PAGE_SLUG = 'open-migration';

	/** Documentation home. Every in-plugin help link is built from this. */
	const DOCS_URL = 'https://dicecodes.com/open-migration/';

	/**
	 * Link to a specific section of the documentation.
	 *
	 * Deep-linking per tab means the help link lands on the part the user is
	 * actually looking at, rather than the top of a long page.
	 *
	 * @param string $anchor Section id on the docs page, without the '#'.
	 * @param string $label  Visible link text.
	 */
	private function docs_link( $anchor, $label ) {
		printf(
			'<a class="owpm-docs-link" href="%s#%s" target="_blank" rel="noopener noreferrer">'
			. '<span class="dashicons dashicons-book-alt" aria-hidden="true"></span>%s</a>',
			esc_url( self::DOCS_URL ),
			esc_attr( $anchor ),
			esc_html( $label )
		);
	}

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_owpm_system_status', array( $this, 'ajax_system_status' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OWPM_PLUGIN_FILE ), array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/**
	 * Server compatibility report shown before the user starts a migration.
	 *
	 * Surfacing this up front is cheaper than letting someone discover a missing
	 * extension or a full disk halfway through a multi-gigabyte export.
	 */
	public function ajax_system_status() {
		check_ajax_referer( 'owpm_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do that.', 'open-migration-unlimited-site-transfer-backup' ), 403 );
		}

		global $wpdb;

		// Both resolved through wp_upload_dir(); assembling an uploads path from
		// Assembling an uploads path by hand is wrong on installs that relocate it.
		$backup_dir = OWPM_FS::backup_dir();
		$uploads    = OWPM_FS::uploads_dir();
		$probe_dir  = is_dir( $backup_dir ) ? $backup_dir : $uploads;

		$free_space = $probe_dir ? @disk_free_space( $probe_dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$db_size    = $this->database_size();

		$checks = array();

		$checks[] = array(
			'label' => __( 'ZipArchive extension', 'open-migration-unlimited-site-transfer-backup' ),
			'value' => class_exists( 'ZipArchive' ) ? __( 'Available', 'open-migration-unlimited-site-transfer-backup' ) : __( 'Missing', 'open-migration-unlimited-site-transfer-backup' ),
			'state' => class_exists( 'ZipArchive' ) ? 'ok' : 'error',
			'hint'  => class_exists( 'ZipArchive' ) ? '' : __( 'Required to create and read backups. Ask your host to enable the PHP zip extension.', 'open-migration-unlimited-site-transfer-backup' ),
		);

		$checks[] = array(
			'label' => __( 'PHP version', 'open-migration-unlimited-site-transfer-backup' ),
			'value' => PHP_VERSION,
			'state' => version_compare( PHP_VERSION, '7.2', '>=' ) ? 'ok' : 'error',
			'hint'  => version_compare( PHP_VERSION, '7.2', '>=' ) ? '' : __( 'PHP 7.2 or newer is required.', 'open-migration-unlimited-site-transfer-backup' ),
		);

		$writable = is_dir( $probe_dir ) ? wp_is_writable( $probe_dir ) : wp_is_writable( dirname( $probe_dir ) );

		$checks[] = array(
			'label' => __( 'Backup folder writable', 'open-migration-unlimited-site-transfer-backup' ),
			'value' => $writable ? __( 'Yes', 'open-migration-unlimited-site-transfer-backup' ) : __( 'No', 'open-migration-unlimited-site-transfer-backup' ),
			'state' => $writable ? 'ok' : 'error',
			'hint'  => $writable ? '' : __( 'wp-content/uploads is not writable, so backups cannot be created.', 'open-migration-unlimited-site-transfer-backup' ),
		);

		$checks[] = array(
			'label' => __( 'Database size', 'open-migration-unlimited-site-transfer-backup' ),
			'value' => $db_size > 0 ? size_format( $db_size ) : __( 'Unknown', 'open-migration-unlimited-site-transfer-backup' ),
			'state' => 'info',
			'hint'  => '',
		);

		// A backup needs room for the archive alongside the site it is copying.
		$space_state = 'ok';
		$space_hint  = '';

		if ( $free_space && $db_size > 0 && $free_space < ( $db_size * 3 ) ) {
			$space_state = 'warn';
			$space_hint  = __( 'Free space is low relative to your database. Delete old backups before exporting.', 'open-migration-unlimited-site-transfer-backup' );
		} elseif ( ! $free_space ) {
			$space_state = 'info';
		}

		$checks[] = array(
			'label' => __( 'Free disk space', 'open-migration-unlimited-site-transfer-backup' ),
			'value' => $free_space ? size_format( $free_space ) : __( 'Unknown', 'open-migration-unlimited-site-transfer-backup' ),
			'state' => $space_state,
			'hint'  => $space_hint,
		);

		$checks[] = array(
			'label' => __( 'Upload limit bypass', 'open-migration-unlimited-site-transfer-backup' ),
			/* translators: %s: chunk size, e.g. 2 MB. */
			'value' => sprintf( __( 'Active (%s chunks)', 'open-migration-unlimited-site-transfer-backup' ), size_format( $this->chunk_size() ) ),
			'state' => 'ok',
			'hint'  => sprintf(
				/* translators: %s: the server's upload_max_filesize value. */
				__( 'Your server limit is %s, but chunking means archive size does not matter.', 'open-migration-unlimited-site-transfer-backup' ),
				size_format( wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) ) )
			),
		);

		if ( is_multisite() ) {
			$checks[] = array(
				'label' => __( 'Multisite', 'open-migration-unlimited-site-transfer-backup' ),
				'value' => __( 'Not supported', 'open-migration-unlimited-site-transfer-backup' ),
				'state' => 'error',
				'hint'  => __( 'This plugin supports single-site installs only.', 'open-migration-unlimited-site-transfer-backup' ),
			);
		}

		$blocking = 0;
		foreach ( $checks as $check ) {
			if ( 'error' === $check['state'] ) {
				++$blocking;
			}
		}

		wp_send_json_success(
			array(
				'checks'   => $checks,
				'blocking' => $blocking,
				'prefix'   => $wpdb->prefix,
				'site_url' => untrailingslashit( site_url() ),
			)
		);
	}

	/**
	 * Total size of this site's tables, or 0 when the host restricts access.
	 */
	private function database_size() {
		global $wpdb;

		if ( ! defined( 'DB_NAME' ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$size = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			)
		);

		return $size ? (int) $size : 0;
	}

	public function add_admin_menu() {
		add_menu_page(
			__( 'Open Migration', 'open-migration-unlimited-site-transfer-backup' ),
			__( 'Open Migration', 'open-migration-unlimited-site-transfer-backup' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' ),
			'dashicons-migrate',
			30
		);
	}

	public function action_links( $links ) {
		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Migrate', 'open-migration-unlimited-site-transfer-backup' )
		);

		array_unshift( $links, $link );

		return $links;
	}

	/**
	 * Extra links on this plugin's own row of the Plugins screen.
	 *
	 * Scoped to this plugin only -- the filter passes every plugin's file, so the
	 * guard matters. Nothing is added to any other plugin's row.
	 */
	public function row_meta( $links, $file ) {
		if ( plugin_basename( OWPM_PLUGIN_FILE ) !== $file ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( self::DOCS_URL ),
			esc_html__( 'Documentation & support', 'open-migration-unlimited-site-transfer-backup' )
		);

		return $links;
	}

	public function enqueue_scripts( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// The UI uses dashicons for the upload and help glyphs.
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'owpm-admin-css', OWPM_PLUGIN_URL . 'assets/css/admin.css', array( 'dashicons' ), OWPM_VERSION );
		// No jQuery dependency: the script uses fetch and plain DOM APIs only.
		wp_enqueue_script( 'owpm-admin-js', OWPM_PLUGIN_URL . 'assets/js/admin.js', array(), OWPM_VERSION, true );

		wp_localize_script(
			'owpm-admin-js',
			'owpm_ajax',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'owpm_nonce' ),
				'chunk_size' => $this->chunk_size(),
				'site_url'   => untrailingslashit( site_url() ),
				'zip_ok'     => class_exists( 'ZipArchive' ) ? 1 : 0,
				'multisite'  => is_multisite() ? 1 : 0,
				'i18n'       => $this->strings(),
			)
		);
	}

	/**
	 * Upload chunk size, kept safely under the server's own POST limits.
	 */
	private function chunk_size() {
		$limits = array( 2 * 1024 * 1024 );

		foreach ( array( 'upload_max_filesize', 'post_max_size' ) as $setting ) {
			$bytes = wp_convert_hr_to_bytes( ini_get( $setting ) );
			if ( $bytes > 0 ) {
				// Leave headroom for the other POST fields and multipart overhead.
				$limits[] = (int) ( $bytes * 0.8 );
			}
		}

		$size = min( $limits );

		// Never go below 256KB, or huge uploads turn into thousands of requests.
		return max( 262144, $size );
	}

	/**
	 * Translatable strings used by the JavaScript UI.
	 */
	private function strings() {
		return array(
			'confirmImport'      => __( "This will OVERWRITE this site's database and files with the contents of the backup.\n\nThis cannot be undone. Continue?", 'open-migration-unlimited-site-transfer-backup' ),
			'confirmDelete'      => __( 'This permanently removes the archive from this server. Any copy you have already downloaded is unaffected.', 'open-migration-unlimited-site-transfer-backup' ),
			'noZipSupport'       => __( 'The PHP ZipArchive extension is not enabled on this server, so exports and imports cannot run. Please ask your host to enable it.', 'open-migration-unlimited-site-transfer-backup' ),
			'multisite'          => __( 'Multisite networks are not supported yet.', 'open-migration-unlimited-site-transfer-backup' ),
			'notZip'             => __( 'Please choose a .zip backup file.', 'open-migration-unlimited-site-transfer-backup' ),
			'noToken'            => __( 'Please paste a migration token.', 'open-migration-unlimited-site-transfer-backup' ),
			'noDomain'           => __( 'Please enter the new site address.', 'open-migration-unlimited-site-transfer-backup' ),
			'continueAnyway'     => __( 'Continue with the import anyway?', 'open-migration-unlimited-site-transfer-backup' ),
			'importCancelled'    => __( 'Import cancelled.', 'open-migration-unlimited-site-transfer-backup' ),
			'serverError'        => __( 'Server error', 'open-migration-unlimited-site-transfer-backup' ),
			'unknownError'       => __( 'Unknown error', 'open-migration-unlimited-site-transfer-backup' ),
			'networkError'       => __( 'The connection to the server failed. Check your internet connection and try again.', 'open-migration-unlimited-site-transfer-backup' ),
			'loadingBackups'     => __( 'Loading backups…', 'open-migration-unlimited-site-transfer-backup' ),
			'noBackups'          => __( 'No backups found on this server.', 'open-migration-unlimited-site-transfer-backup' ),
			'backupsFailed'      => __( 'Failed to load backups.', 'open-migration-unlimited-site-transfer-backup' ),
			'deleting'           => __( 'Deleting…', 'open-migration-unlimited-site-transfer-backup' ),
			'download'           => __( 'Download', 'open-migration-unlimited-site-transfer-backup' ),
			'delete'             => __( 'Delete', 'open-migration-unlimited-site-transfer-backup' ),
			'colName'            => __( 'Backup Name', 'open-migration-unlimited-site-transfer-backup' ),
			'colDate'            => __( 'Date', 'open-migration-unlimited-site-transfer-backup' ),
			'colSize'            => __( 'Size', 'open-migration-unlimited-site-transfer-backup' ),
			'colActions'         => __( 'Actions', 'open-migration-unlimited-site-transfer-backup' ),
			'copy'               => __( 'Copy Token', 'open-migration-unlimited-site-transfer-backup' ),
			'copied'             => __( 'Copied!', 'open-migration-unlimited-site-transfer-backup' ),
			'tokenTitle'         => __( 'Your Migration Token', 'open-migration-unlimited-site-transfer-backup' ),
			'tokenNote'          => __( 'Valid for 24 hours. Paste this into the destination site and click Start Pull Transfer.', 'open-migration-unlimited-site-transfer-backup' ),
			'reloginNeeded'      => __( 'You are now signed out because the imported database replaced this site\'s user accounts. Log in with the username and password from the ORIGINAL site.', 'open-migration-unlimited-site-transfer-backup' ),
			'reloginDone'        => __( 'You are still signed in. Please review your Permalinks settings and re-save them.', 'open-migration-unlimited-site-transfer-backup' ),
			'importDone'         => __( 'Import complete.', 'open-migration-unlimited-site-transfer-backup' ),
			'modalTitle'         => __( 'Please confirm', 'open-migration-unlimited-site-transfer-backup' ),
			'modalNotice'        => __( 'Open Migration', 'open-migration-unlimited-site-transfer-backup' ),
			'modalOk'            => __( 'OK', 'open-migration-unlimited-site-transfer-backup' ),
			'modalCancel'        => __( 'Cancel', 'open-migration-unlimited-site-transfer-backup' ),
			'modalContinue'      => __( 'Continue', 'open-migration-unlimited-site-transfer-backup' ),
			'confirmImportTitle' => __( 'Overwrite this site?', 'open-migration-unlimited-site-transfer-backup' ),
			'confirmImportBtn'   => __( 'Overwrite this site', 'open-migration-unlimited-site-transfer-backup' ),
			'confirmDomainTitle' => __( 'Change the site address?', 'open-migration-unlimited-site-transfer-backup' ),
			'confirmDomainBtn'   => __( 'Change domain', 'open-migration-unlimited-site-transfer-backup' ),
			'confirmDeleteTitle' => __( 'Delete this backup?', 'open-migration-unlimited-site-transfer-backup' ),
			'confirmDeleteBtn'   => __( 'Delete backup', 'open-migration-unlimited-site-transfer-backup' ),
			'compatTitle'        => __( 'Check before continuing', 'open-migration-unlimited-site-transfer-backup' ),
			/* translators: %d: how many slim-down options are ticked. */
			'slimChosen'         => __( '%d selected', 'open-migration-unlimited-site-transfer-backup' ),
			'slimCounting'       => __( 'Counting…', 'open-migration-unlimited-site-transfer-backup' ),
			/* translators: %d: number of database rows in this category. */
			'slimRows'           => __( '%d row(s) here', 'open-migration-unlimited-site-transfer-backup' ),
			'slimNone'           => __( 'nothing to leave out', 'open-migration-unlimited-site-transfer-backup' ),
			/* translators: 1: number of rows, 2: approximate size in brackets, or empty. */
			'slimTotal'          => __( '%1$d row(s) would be left out%2$s.', 'open-migration-unlimited-site-transfer-backup' ),
			'slimClean'          => __( 'Nothing to leave out — this database is already tidy.', 'open-migration-unlimited-site-transfer-backup' ),
			'blockedTitle'       => __( 'This import would break the site', 'open-migration-unlimited-site-transfer-backup' ),
			'importBlocked'      => __( 'Import stopped. Nothing has been changed.', 'open-migration-unlimited-site-transfer-backup' ),
			'confirmCleanTitle'  => __( 'Move themes and plugins aside, then overwrite?', 'open-migration-unlimited-site-transfer-backup' ),
			'confirmClean'       => __( 'Clean install is selected. Every theme and plugin on this site is moved aside before the backup is restored, so the site comes up with only what the backup contains. They are kept as part of the restore point, not deleted — “Restore this site” puts them back. They are removed for good only once the import finishes successfully.', 'open-migration-unlimited-site-transfer-backup' ),
			'confirmCleanBtn'    => __( 'Clean install and overwrite', 'open-migration-unlimited-site-transfer-backup' ),
			'rollbackTitle'      => __( 'Put this site back?', 'open-migration-unlimited-site-transfer-backup' ),
			'rollbackBody'       => __( 'Your original database will be restored, and any theme or plugin a clean install moved aside will be put back. Files the backup overwrote in place are not covered — those came from the archive and stay. This cannot be undone.', 'open-migration-unlimited-site-transfer-backup' ),
			'rollbackBtn'        => __( 'Restore this site', 'open-migration-unlimited-site-transfer-backup' ),
			'rollbackRunning'    => __( 'Restoring…', 'open-migration-unlimited-site-transfer-backup' ),
			'discardTitle'       => __( 'Discard the saved copy?', 'open-migration-unlimited-site-transfer-backup' ),
			'discardBody'        => __( 'The copy of your database from before the import will be deleted. You will no longer be able to roll back.', 'open-migration-unlimited-site-transfer-backup' ),
			'discardBtn'         => __( 'Discard', 'open-migration-unlimited-site-transfer-backup' ),
			'confirmDomain'      => __( 'This rewrites every reference across the whole database and cannot be undone. Continue?', 'open-migration-unlimited-site-transfer-backup' ),
			'confirmDomainFrom'  => __( 'Change this site’s address?', 'open-migration-unlimited-site-transfer-backup' ),
			'dnsChecking'        => __( 'Checking whether that address reaches this server…', 'open-migration-unlimited-site-transfer-backup' ),
			'dnsWarnMismatch'    => __( 'WARNING: the DNS check said that address does NOT point to this server. Continuing will take this site offline.', 'open-migration-unlimited-site-transfer-backup' ),
			'dnsWarnUnchecked'   => __( 'You have not confirmed that this address points to this server. Use "Check DNS" first if you are unsure.', 'open-migration-unlimited-site-transfer-backup' ),
			'domainDone'         => __( 'Domain changed.', 'open-migration-unlimited-site-transfer-backup' ),
			'domainRelogin'      => __( 'You are now signed out of this address. Open the new one and log in again, then re-save Settings → Permalinks.', 'open-migration-unlimited-site-transfer-backup' ),
			'domainGoTo'         => __( 'Go to the new address', 'open-migration-unlimited-site-transfer-backup' ),
			'rowsUpdated'        => __( 'database rows updated', 'open-migration-unlimited-site-transfer-backup' ),
			'verifyRun'          => __( 'Run checks', 'open-migration-unlimited-site-transfer-backup' ),
			'verifyRunning'      => __( 'Checking…', 'open-migration-unlimited-site-transfer-backup' ),
			'hostLinks'          => __( 'link(s)', 'open-migration-unlimited-site-transfer-backup' ),
			'hostFix'            => __( 'Point these at this site', 'open-migration-unlimited-site-transfer-backup' ),
			'hostFixTitle'       => __( 'Rewrite links to this host?', 'open-migration-unlimited-site-transfer-backup' ),
			'hostFixBtn'         => __( 'Rewrite links', 'open-migration-unlimited-site-transfer-backup' ),
			'hostFixCounting'    => __( 'Counting what this would change…', 'open-migration-unlimited-site-transfer-backup' ),
			/* translators: %s: the host name. Appears more than once. */
			'hostFixKnown'       => __( 'This site is recorded as having been migrated from %s, so these are almost certainly links that should point here now.', 'open-migration-unlimited-site-transfer-backup' ),
			/* translators: %s: the host name. Appears more than once. */
			'hostFixUnknown'     => __( 'This site has no record of ever being at %s. If that is a site you link to on purpose, rewriting will turn every one of those links into a link to your own site.', 'open-migration-unlimited-site-transfer-backup' ),
			/* translators: 1: number of database rows, 2: comma separated table names with counts. */
			'hostFixCount'       => __( '%1$d database row(s) will change, across: %2$s.', 'open-migration-unlimited-site-transfer-backup' ),
			/* translators: %s: comma separated table names with counts. */
			'hostFixSkipped'     => __( 'These tables also contain it but cannot be rewritten automatically, because they have no primary key to address a row by: %s. You will need to edit those by hand.', 'open-migration-unlimited-site-transfer-backup' ),
			'hostFixUndo'        => __( 'This cannot be undone. If you are not sure, take a backup from the Export tab first.', 'open-migration-unlimited-site-transfer-backup' ),
			/* translators: %s: comma separated table names with counts. */
			'hostFixLeft'        => __( 'Finished, but these still mention it and need a look by hand: %s', 'open-migration-unlimited-site-transfer-backup' ),
			/* translators: %d: percentage complete. */
			'hostFixWorking'     => __( 'Rewriting… %d%%', 'open-migration-unlimited-site-transfer-backup' ),
			/* translators: %d: number of database rows changed. */
			'hostFixDone'        => __( '%d row(s) updated.', 'open-migration-unlimited-site-transfer-backup' ),
		);
	}

	/**
	 * The "slim down the backup" options, for any tab that builds an archive.
	 *
	 * Rendered twice: on Export, and on the Site-to-Site tab where the old site
	 * builds the archive the new one pulls. Both are exports, so both get the
	 * choice.
	 *
	 * Factored out rather than duplicated because of what the single copy did:
	 * Site-to-Site shares runExport(), so it was already honouring whatever was
	 * ticked over on the Export tab -- an operator on this tab had their
	 * archive quietly slimmed by boxes on a screen they were not looking at.
	 * Controls that affect a run belong on the tab that starts it.
	 *
	 * @param string $scope Short identifier; scopes the fieldset for the script.
	 */
	private function slim_fieldset( $scope ) {
		$options = array(
			'revisions' => array(
				__( 'Post revisions', 'open-migration-unlimited-site-transfer-backup' ),
				__( 'Every saved draft of every edit, and their metadata. Often the largest single thing in a database. WordPress starts keeping new ones from your next edit.', 'open-migration-unlimited-site-transfer-backup' ),
			),
			'trashed'   => array(
				__( 'Trashed and abandoned items', 'open-migration-unlimited-site-transfer-backup' ),
				__( 'Posts and comments in the trash, and auto-drafts left behind by opening the editor without saving. WordPress deletes both itself after a while.', 'open-migration-unlimited-site-transfer-backup' ),
			),
			'spam'      => array(
				__( 'Spam comments', 'open-migration-unlimited-site-transfer-backup' ),
				__( 'Comments marked as spam, and their metadata.', 'open-migration-unlimited-site-transfer-backup' ),
			),
			'orphans'   => array(
				__( 'Orphaned metadata', 'open-migration-unlimited-site-transfer-backup' ),
				__( 'Rows left behind by posts and comments that no longer exist — usually from plugins removed long ago. Nothing can read them.', 'open-migration-unlimited-site-transfer-backup' ),
			),
		);
		?>
		<details class="owpm-accordion owpm-slim-accordion">
			<summary>
				<?php esc_html_e( 'Slim down the backup (optional)', 'open-migration-unlimited-site-transfer-backup' ); ?>
				<?php
				/*
				 * A count in the summary, because the panel is closed by default
				 * and a choice you cannot see is a choice you forget you made.
				 * Filled in by the script whenever a box changes.
				 */
				?>
				<span class="owpm-slim-chosen" data-scope="<?php echo esc_attr( $scope ); ?>"></span>
			</summary>
		<fieldset class="owpm-slim" data-scope="<?php echo esc_attr( $scope ); ?>">
			<legend class="screen-reader-text"><?php esc_html_e( 'Slim down the backup (optional)', 'open-migration-unlimited-site-transfer-backup' ); ?></legend>
			<p class="owpm-slim-lead">
				<?php esc_html_e( 'Leave rebuildable clutter out of the archive, for a smaller file and a faster, tidier import. This only changes what gets copied — nothing on this site is altered or deleted, so you can export again at any time.', 'open-migration-unlimited-site-transfer-backup' ); ?>
			</p>

			<?php foreach ( $options as $key => $copy ) : ?>
				<label class="owpm-slim-item">
					<input type="checkbox" class="owpm-slim-box" value="<?php echo esc_attr( $key ); ?>" />
					<span>
						<strong><?php echo esc_html( $copy[0] ); ?></strong>
						<?php echo esc_html( $copy[1] ); ?>
						<em class="owpm-slim-count" data-slim-count="<?php echo esc_attr( $key ); ?>"></em>
					</span>
				</label>
			<?php endforeach; ?>

			<div class="owpm-slim-actions">
				<button type="button" class="owpm-btn owpm-btn-secondary owpm-slim-preview">
					<?php esc_html_e( 'Show me what this leaves out', 'open-migration-unlimited-site-transfer-backup' ); ?>
				</button>
				<span class="owpm-slim-summary" role="status"></span>
			</div>

			<p class="owpm-slim-note">
				<?php esc_html_e( 'Transients — WordPress\'s own temporary cache — are always left out, whatever you choose here. Media files, uploads and settings are never affected by these options.', 'open-migration-unlimited-site-transfer-backup' ); ?>
			</p>
		</fieldset>
		</details>
		<?php
	}

	/**
	 * What to do with themes and plugins already on the destination.
	 *
	 * Rendered on Import and on the Site-to-Site tab's pull step, which is an
	 * import by another route and had no way to ask for a clean install.
	 *
	 * The radio group name carries the scope: two groups sharing a name would
	 * behave as one, so choosing "clean install" here would silently clear the
	 * other tab's choice.
	 *
	 * @param string $scope Short identifier, used in the radio group name.
	 */
	private function import_mode_fieldset( $scope ) {
		$group = 'owpm_import_mode_' . $scope;
		?>
		<fieldset class="owpm-modes" data-scope="<?php echo esc_attr( $scope ); ?>">
			<legend><?php esc_html_e( 'What to do with themes and plugins already here', 'open-migration-unlimited-site-transfer-backup' ); ?></legend>

			<label class="owpm-mode">
				<input type="radio" name="<?php echo esc_attr( $group ); ?>" value="keep" checked="checked" />
				<span>
					<strong><?php esc_html_e( 'Keep them (recommended)', 'open-migration-unlimited-site-transfer-backup' ); ?></strong>
					<?php esc_html_e( 'The archive is restored over the top. Anything this site has that the backup does not is left in place, deactivated.', 'open-migration-unlimited-site-transfer-backup' ); ?>
				</span>
			</label>

			<label class="owpm-mode">
				<input type="radio" name="<?php echo esc_attr( $group ); ?>" value="clean" />
				<span>
					<strong><?php esc_html_e( 'Clean install', 'open-migration-unlimited-site-transfer-backup' ); ?></strong>
					<?php esc_html_e( 'Move every theme and plugin on this site aside first, so it ends up matching the backup exactly. They are held as part of the restore point until the import succeeds, so this is reversible. Media files, uploads and must-use plugins are never touched.', 'open-migration-unlimited-site-transfer-backup' ); ?>
				</span>
			</label>

			<p class="owpm-mode-warning" hidden>
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<?php esc_html_e( 'If the import then fails, this site has no themes or plugins until you either finish it or press “Restore this site”, which puts them back. They are deleted for good only once the import has finished successfully.', 'open-migration-unlimited-site-transfer-backup' ); ?>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Render one progress bar + status line pair.
	 */
	private function progress_block( $prefix ) {
		?>
		<div class="owpm-progress-wrapper" id="<?php echo esc_attr( $prefix ); ?>-progress-wrapper">
			<div class="owpm-progress-bar" id="<?php echo esc_attr( $prefix ); ?>-progress-bar"></div>
		</div>
		<div class="owpm-status-text" id="<?php echo esc_attr( $prefix ); ?>-status-text" role="status" aria-live="polite"></div>
		<?php
	}

	/**
	 * Render a numbered step.
	 */
	private function step( $number, $title, $body ) {
		?>
		<li class="owpm-step">
			<span class="owpm-step-n"><?php echo esc_html( $number ); ?></span>
			<div class="owpm-step-body">
				<strong><?php echo esc_html( $title ); ?></strong>
				<p>
				<?php
				echo wp_kses(
					$body,
					array(
						'code'   => array(),
						'strong' => array(),
						'em'     => array(),
					)
				);
				?>
					</p>
			</div>
		</li>
		<?php
	}

	/**
	 * Open a collapsible section.
	 *
	 * Reference material lives in these so that the status and the primary action
	 * stay visible without scrolling. Native <details> keeps it keyboard
	 * accessible and working with no JavaScript.
	 */
	private function accordion_open( $label, $open = false ) {
		?>
		<details class="owpm-accordion"<?php echo $open ? ' open' : ''; ?>>
			<summary><?php echo esc_html( $label ); ?></summary>
			<div class="owpm-accordion-body">
		<?php
	}

	private function accordion_close() {
		?>
			</div>
		</details>
		<?php
	}

	/**
	 * The whole screen is rendered server-side so that every string is
	 * translatable and escaped; JavaScript only attaches behaviour.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'open-migration-unlimited-site-transfer-backup' ), 403 );
		}

		global $wpdb;

		$host = wp_parse_url( site_url(), PHP_URL_HOST );
		?>
		<div class="wrap owpm-wrap">

			<noscript>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Open Migration needs JavaScript enabled to run.', 'open-migration-unlimited-site-transfer-backup' ); ?></p>
				</div>
			</noscript>

			<?php
			/*
			 * WordPress moves every admin notice to just after the first <h1> in
			 * .wrap unless it finds a .wp-header-end marker. Our <h1> lives inside
			 * a dark header, so other plugins' notices were landing in there and
			 * rendering near-invisible -- LiteSpeed's "Purged all caches" was
			 * white text on a white box. This marker is the supported way to say
			 * where notices belong.
			 */
			?>
			<hr class="wp-header-end" />

			<div class="owpm-shell" id="owpm-app-root">

				<header class="owpm-header">
					<div class="owpm-brand">
						<span class="owpm-logo dashicons dashicons-migrate" aria-hidden="true"></span>
						<div>
							<h1><?php esc_html_e( 'Open Migration', 'open-migration-unlimited-site-transfer-backup' ); ?></h1>
							<p><?php esc_html_e( 'Move, clone and restore this site. No upload size limits.', 'open-migration-unlimited-site-transfer-backup' ); ?></p>
						</div>
					</div>
					<div class="owpm-meta">
						<span class="owpm-chip" title="<?php esc_attr_e( 'The site you are currently working on', 'open-migration-unlimited-site-transfer-backup' ); ?>">
							<span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
							<?php echo esc_html( $host ); ?>
						</span>
						<span class="owpm-chip owpm-chip-quiet">
							<?php
							/* translators: %s: database table prefix. */
							echo esc_html( sprintf( __( 'prefix %s', 'open-migration-unlimited-site-transfer-backup' ), $wpdb->prefix ) );
							?>
						</span>
						<span class="owpm-chip owpm-chip-quiet">v<?php echo esc_html( OWPM_VERSION ); ?></span>
					</div>
				</header>

				<nav class="owpm-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Migration tools', 'open-migration-unlimited-site-transfer-backup' ); ?>">
					<button type="button" class="owpm-tab active" data-target="export" role="tab" aria-selected="true" aria-controls="panel-export">
						<span class="dashicons dashicons-download" aria-hidden="true"></span>
						<?php esc_html_e( 'Export', 'open-migration-unlimited-site-transfer-backup' ); ?>
					</button>
					<button type="button" class="owpm-tab" data-target="import" role="tab" aria-selected="false" aria-controls="panel-import">
						<span class="dashicons dashicons-upload" aria-hidden="true"></span>
						<?php esc_html_e( 'Import', 'open-migration-unlimited-site-transfer-backup' ); ?>
					</button>
					<button type="button" class="owpm-tab" data-target="site-to-site" role="tab" aria-selected="false" aria-controls="panel-site-to-site">
						<span class="dashicons dashicons-networking" aria-hidden="true"></span>
						<?php esc_html_e( 'Site-to-Site', 'open-migration-unlimited-site-transfer-backup' ); ?>
					</button>
					<button type="button" class="owpm-tab" data-target="domain" role="tab" aria-selected="false" aria-controls="panel-domain">
						<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
						<?php esc_html_e( 'Change Domain', 'open-migration-unlimited-site-transfer-backup' ); ?>
					</button>
					<button type="button" class="owpm-tab" data-target="backups" role="tab" aria-selected="false" aria-controls="panel-backups">
						<span class="dashicons dashicons-database" aria-hidden="true"></span>
						<?php esc_html_e( 'Backups', 'open-migration-unlimited-site-transfer-backup' ); ?>
					</button>
					<button type="button" class="owpm-tab" data-target="verify" role="tab" aria-selected="false" aria-controls="panel-verify">
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<?php esc_html_e( 'Verify', 'open-migration-unlimited-site-transfer-backup' ); ?>
					</button>
				</nav>

				<div class="owpm-panels">

					<?php /* ---------------------------------------------- EXPORT */ ?>
					<section id="panel-export" class="owpm-panel active" role="tabpanel" tabindex="0">
						<div class="owpm-panel-head">
							<h2><?php esc_html_e( 'Export this site', 'open-migration-unlimited-site-transfer-backup' ); ?></h2>
							<?php $this->docs_link( 'export', __( 'Export documentation', 'open-migration-unlimited-site-transfer-backup' ) ); ?>
							<p class="owpm-lead">
								<?php esc_html_e( 'Package the database and everything in wp-content into a single .zip file you can download, archive, or restore onto another WordPress install.', 'open-migration-unlimited-site-transfer-backup' ); ?>
							</p>
						</div>

						<div class="owpm-status-card" id="owpm-system-status">
							<div class="owpm-status-head">
								<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
								<strong><?php esc_html_e( 'Server check', 'open-migration-unlimited-site-transfer-backup' ); ?></strong>
								<span class="owpm-status-summary" id="owpm-status-summary"><?php esc_html_e( 'Checking…', 'open-migration-unlimited-site-transfer-backup' ); ?></span>
							</div>
							<div class="owpm-status-grid" id="owpm-status-grid"></div>
						</div>

						<?php $this->slim_fieldset( 'export' ); ?>

						<div class="owpm-actions">
							<button type="button" class="owpm-btn owpm-btn-primary" id="owpm-start-export">
								<?php esc_html_e( 'Start Export', 'open-migration-unlimited-site-transfer-backup' ); ?>
							</button>
						</div>

						<?php $this->progress_block( 'export' ); ?>

						<?php $this->accordion_open( __( 'How it works, and what is included', 'open-migration-unlimited-site-transfer-backup' ) ); ?>
							<ol class="owpm-steps">
								<?php
								$this->step(
									1,
									__( 'Click Start Export', 'open-migration-unlimited-site-transfer-backup' ),
									__( 'Your database is dumped and your files are zipped in small batches, so the request never times out — even on a large site.', 'open-migration-unlimited-site-transfer-backup' )
								);
								$this->step(
									2,
									__( 'Wait for it to finish', 'open-migration-unlimited-site-transfer-backup' ),
									__( 'Keep this tab open. Progress is shown above and you can see exactly which file is being packaged.', 'open-migration-unlimited-site-transfer-backup' )
								);
								$this->step(
									3,
									__( 'Download the .zip', 'open-migration-unlimited-site-transfer-backup' ),
									__( 'The archive also stays on this server under the <code>Backups</code> tab until you delete it.', 'open-migration-unlimited-site-transfer-backup' )
								);
								?>
							</ol>

							<div class="owpm-included">
								<div class="owpm-included-col">
									<h4><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'Included', 'open-migration-unlimited-site-transfer-backup' ); ?></h4>
									<ul>
										<li><?php esc_html_e( 'All database tables for this site', 'open-migration-unlimited-site-transfer-backup' ); ?></li>
										<li><?php esc_html_e( 'Themes and plugins', 'open-migration-unlimited-site-transfer-backup' ); ?></li>
										<li><?php esc_html_e( 'Uploads and media library', 'open-migration-unlimited-site-transfer-backup' ); ?></li>
										<li><?php esc_html_e( 'Site URL, path and prefix, for automatic conversion', 'open-migration-unlimited-site-transfer-backup' ); ?></li>
									</ul>
								</div>
								<div class="owpm-included-col">
									<h4><span class="dashicons dashicons-dismiss" aria-hidden="true"></span> <?php esc_html_e( 'Not included', 'open-migration-unlimited-site-transfer-backup' ); ?></h4>
									<ul>
										<li><?php esc_html_e( 'wp-config.php and your database password', 'open-migration-unlimited-site-transfer-backup' ); ?></li>
										<li><?php esc_html_e( 'WordPress core files', 'open-migration-unlimited-site-transfer-backup' ); ?></li>
										<li><?php esc_html_e( 'Cache, upgrade and transient data', 'open-migration-unlimited-site-transfer-backup' ); ?></li>
										<li><?php esc_html_e( 'Other plugins’ backup folders', 'open-migration-unlimited-site-transfer-backup' ); ?></li>
									</ul>
								</div>
							</div>
						<?php $this->accordion_close(); ?>
					</section>

					<?php /* ---------------------------------------------- IMPORT */ ?>
					<section id="panel-import" class="owpm-panel" role="tabpanel" tabindex="0">
						<div class="owpm-panel-head">
							<h2><?php esc_html_e( 'Import a site', 'open-migration-unlimited-site-transfer-backup' ); ?></h2>
							<?php $this->docs_link( 'import', __( 'Import documentation', 'open-migration-unlimited-site-transfer-backup' ) ); ?>
							<p class="owpm-lead">
								<?php esc_html_e( 'Restore an Open Migration .zip onto this install. You do not need to prepare anything first — a brand new WordPress works fine.', 'open-migration-unlimited-site-transfer-backup' ); ?>
							</p>
						</div>

						<?php $snapshot = OWPM_Rollback::details(); ?>
						<?php if ( $snapshot ) : ?>
							<div class="owpm-alert owpm-alert-warning" id="owpm-rollback-banner">
								<span class="dashicons dashicons-backup" aria-hidden="true"></span>
								<div>
									<strong><?php esc_html_e( 'An earlier import did not finish.', 'open-migration-unlimited-site-transfer-backup' ); ?></strong>
									<p>
										<?php
										/*
										 * Describes what is actually held, which is not always both.
										 *
										 * A clean install parks folders during extraction; the database
										 * snapshot is not taken until the database phase begins. An import
										 * that died in between leaves parked files and no tables, and this
										 * paragraph then read "0 table(s), 56 years ago" -- a zero timestamp
										 * measured by human_time_diff() from the epoch.
										 */
										$held = array();

										if ( (int) $snapshot['tables'] > 0 ) {
											$held[] = sprintf(
												/* translators: %d: number of database tables held. */
												_n( '%d database table', '%d database tables', (int) $snapshot['tables'], 'open-migration-unlimited-site-transfer-backup' ),
												(int) $snapshot['tables']
											);
										}

										if ( ! empty( $snapshot['files'] ) ) {
											$held[] = sprintf(
												/* translators: %d: number of theme or plugin folders held. */
												_n( '%d theme or plugin folder', '%d theme and plugin folders', (int) $snapshot['files'], 'open-migration-unlimited-site-transfer-backup' ),
												(int) $snapshot['files']
											);
										}

										printf(
											/* translators: 1: what is held, 2: how long ago. */
											esc_html__( 'A restore point from before that import is still here — %1$s, set aside %2$s ago. If this site is not right you can put it back; if everything is fine, discard it to free the space.', 'open-migration-unlimited-site-transfer-backup' ),
											esc_html( implode( __( ' and ', 'open-migration-unlimited-site-transfer-backup' ), $held ) ),
											esc_html( human_time_diff( (int) $snapshot['taken'] ) )
										);
										?>
									</p>
									<?php if ( (int) $snapshot['tables'] < 1 ) : ?>
										<p>
											<?php esc_html_e( 'That import stopped while it was still unpacking files, so it never reached the database — this site\'s own database was never touched and needs no restoring.', 'open-migration-unlimited-site-transfer-backup' ); ?>
										</p>
									<?php endif; ?>
									<p>
										<button type="button" class="owpm-btn owpm-btn-primary" id="owpm-do-rollback">
											<?php esc_html_e( 'Restore this site', 'open-migration-unlimited-site-transfer-backup' ); ?>
										</button>
										<button type="button" class="owpm-btn owpm-btn-secondary" id="owpm-drop-rollback">
											<?php esc_html_e( 'Discard the copy', 'open-migration-unlimited-site-transfer-backup' ); ?>
										</button>
									</p>
								</div>
							</div>
						<?php endif; ?>

						<div class="owpm-alert owpm-alert-danger">
							<span class="dashicons dashicons-warning" aria-hidden="true"></span>
							<div>
								<strong><?php esc_html_e( 'This replaces the current site.', 'open-migration-unlimited-site-transfer-backup' ); ?></strong>
								<p>
									<?php
									printf(
										/* translators: %s: the current site host name. */
										esc_html__( 'Everything on %s — its posts, users, themes, plugins and media — will be overwritten by the contents of the archive. This cannot be undone. Export a backup of this site first if you might need it.', 'open-migration-unlimited-site-transfer-backup' ),
										'<code>' . esc_html( $host ) . '</code>'
									);
									?>
								</p>
							</div>
						</div>

						<div class="owpm-upload-area" id="owpm-drop-zone">
							<span class="dashicons dashicons-cloud-upload owpm-upload-icon" aria-hidden="true"></span>
							<p class="owpm-drop-title"><?php esc_html_e( 'Drag &amp; drop your backup here', 'open-migration-unlimited-site-transfer-backup' ); ?></p>
							<p class="owpm-drop-sub"><?php esc_html_e( 'Accepts .zip files of any size', 'open-migration-unlimited-site-transfer-backup' ); ?></p>
							<button type="button" class="owpm-btn owpm-btn-secondary" id="owpm-trigger-file">
								<?php esc_html_e( 'Select File', 'open-migration-unlimited-site-transfer-backup' ); ?>
							</button>
							<input type="file" id="owpm-import-file" accept=".zip,application/zip" />
						</div>

						<?php $this->import_mode_fieldset( 'upload' ); ?>

						<?php $this->progress_block( 'import' ); ?>

						<?php $this->accordion_open( __( 'What happens during an import', 'open-migration-unlimited-site-transfer-backup' ) ); ?>
							<ol class="owpm-steps">
								<?php
								$this->step(
									1,
									__( 'Add your backup file', 'open-migration-unlimited-site-transfer-backup' ),
									__( 'Drag the <code>.zip</code> onto the box above, or click Select File. It is uploaded in small chunks, so your host’s upload limit does not apply.', 'open-migration-unlimited-site-transfer-backup' )
								);
								$this->step(
									2,
									__( 'The restore runs automatically', 'open-migration-unlimited-site-transfer-backup' ),
									__( 'Files are extracted, the database is restored, and then the old site URL, server path and table prefix are converted to match this install.', 'open-migration-unlimited-site-transfer-backup' )
								);
								$this->step(
									3,
									__( 'Log in with the old site’s account', 'open-migration-unlimited-site-transfer-backup' ),
									__( 'The archive brings its own user accounts, so your current login stops working. Then open Settings → Permalinks and click Save once.', 'open-migration-unlimited-site-transfer-backup' )
								);
								?>
							</ol>
						<?php $this->accordion_close(); ?>
					</section>

					<?php /* ---------------------------------------------- SITE TO SITE */ ?>
					<section id="panel-site-to-site" class="owpm-panel" role="tabpanel" tabindex="0">
						<div class="owpm-panel-head">
							<h2><?php esc_html_e( 'Direct site-to-site transfer', 'open-migration-unlimited-site-transfer-backup' ); ?></h2>
							<?php $this->docs_link( 'site-to-site', __( 'Site-to-site documentation', 'open-migration-unlimited-site-transfer-backup' ) ); ?>
							<p class="owpm-lead">
								<?php esc_html_e( 'Move a site straight from one server to another without downloading anything. The destination pulls the archive over the WordPress REST API.', 'open-migration-unlimited-site-transfer-backup' ); ?>
							</p>
						</div>

						<div class="owpm-alert owpm-alert-info">
							<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
							<div>
								<p><?php esc_html_e( 'Use the blue box on the OLD site, then the orange box on the NEW site. The old site’s REST API must be reachable from the new server.', 'open-migration-unlimited-site-transfer-backup' ); ?></p>
							</div>
						</div>

						<div class="owpm-box owpm-box-blue">
							<h3><span class="owpm-step-n">1</span> <?php esc_html_e( 'On the OLD site — generate a token', 'open-migration-unlimited-site-transfer-backup' ); ?></h3>
							<p><?php esc_html_e( 'This builds a full backup here, then gives you a one-time token that is valid for 24 hours.', 'open-migration-unlimited-site-transfer-backup' ); ?></p>
							<?php $this->slim_fieldset( 's2s' ); ?>

							<?php
							/*
							 * Primary, like "Start Pull Transfer" below it. These are
							 * the one action of their step and they run on different
							 * machines -- the blue box on the old site, the orange on
							 * the new -- so neither is subordinate to the other.
							 * Outlining this one made the first half of the job look
							 * like the optional half.
							 */
							?>
							<button type="button" class="owpm-btn owpm-btn-primary" id="owpm-generate-token">
								<?php esc_html_e( 'Build Backup &amp; Generate Token', 'open-migration-unlimited-site-transfer-backup' ); ?>
							</button>
							<?php $this->progress_block( 'token' ); ?>
							<div id="owpm-token-output"></div>
						</div>

						<div class="owpm-box owpm-box-orange">
							<h3><span class="owpm-step-n">2</span> <?php esc_html_e( 'On the NEW site — paste the token', 'open-migration-unlimited-site-transfer-backup' ); ?></h3>
							<p><?php esc_html_e( 'Paste the token from the old site. This server downloads the archive and restores it, replacing everything currently here.', 'open-migration-unlimited-site-transfer-backup' ); ?></p>
							<label class="screen-reader-text" for="owpm-import-token"><?php esc_html_e( 'Migration token', 'open-migration-unlimited-site-transfer-backup' ); ?></label>
							<input type="text" id="owpm-import-token" placeholder="<?php esc_attr_e( 'Paste migration token here…', 'open-migration-unlimited-site-transfer-backup' ); ?>" autocomplete="off" spellcheck="false" />
							<?php $this->import_mode_fieldset( 'pull' ); ?>

							<button type="button" class="owpm-btn owpm-btn-primary" id="owpm-start-pull">
								<?php esc_html_e( 'Start Pull Transfer', 'open-migration-unlimited-site-transfer-backup' ); ?>
							</button>
							<?php $this->progress_block( 'pull' ); ?>
						</div>
					</section>

					<?php /* ---------------------------------------------- CHANGE DOMAIN */ ?>
					<section id="panel-domain" class="owpm-panel" role="tabpanel" tabindex="0">
						<div class="owpm-panel-head">
							<h2><?php esc_html_e( 'Change this site’s domain', 'open-migration-unlimited-site-transfer-backup' ); ?></h2>
							<?php $this->docs_link( 'change-domain', __( 'Domain change documentation', 'open-migration-unlimited-site-transfer-backup' ) ); ?>
							<p class="owpm-lead">
								<?php esc_html_e( 'Rewrites every reference to this site’s current address across the whole database — posts, options, widgets, page-builder data and serialised values included.', 'open-migration-unlimited-site-transfer-backup' ); ?>
							</p>
						</div>

						<div class="owpm-alert owpm-alert-info">
							<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
							<div>
								<strong><?php esc_html_e( 'Migrated to a new host before pointing DNS? This is the step you need.', 'open-migration-unlimited-site-transfer-backup' ); ?></strong>
								<p>
									<?php esc_html_e( 'If you imported this site while it was still on a temporary address (for example site.yourhost.com, a staging subdomain, or a raw IP), the database is full of that temporary address. WordPress only ever sees the hostname a visitor requested, so it cannot tell that DNS has since been pointed at the real domain — it will keep redirecting visitors back to the temporary one until you make the change here.', 'open-migration-unlimited-site-transfer-backup' ); ?>
								</p>
							</div>
						</div>

						<?php
						$current_url = untrailingslashit( site_url() );
						// Detection only ever returns addresses recorded somewhere, so
						// the best one is safe to pre-fill.
						$candidates = OWPM_Domain::detect_candidates();
						$first      = ! empty( $candidates ) ? $candidates[0]['url'] : '';
						?>

						<div class="owpm-swap">
							<div class="owpm-swap-side">
								<span class="owpm-swap-label"><?php esc_html_e( 'Current address', 'open-migration-unlimited-site-transfer-backup' ); ?></span>
								<code class="owpm-swap-url" id="owpm-domain-current"><?php echo esc_html( $current_url ); ?></code>
							</div>
							<span class="owpm-swap-arrow dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
							<div class="owpm-swap-side">
								<span class="owpm-swap-label"><?php esc_html_e( 'New address', 'open-migration-unlimited-site-transfer-backup' ); ?></span>
								<code class="owpm-swap-url owpm-swap-new" id="owpm-domain-preview">&mdash;</code>
							</div>
						</div>

						<?php if ( ! empty( $candidates ) ) : ?>
							<div class="owpm-suggestions" id="owpm-domain-suggestions">
								<span class="owpm-suggestions-title">
									<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
									<?php esc_html_e( 'Detected addresses', 'open-migration-unlimited-site-transfer-backup' ); ?>
								</span>
								<?php foreach ( $candidates as $candidate ) : ?>
									<button type="button" class="owpm-candidate owpm-use-domain"
										data-url="<?php echo esc_attr( $candidate['url'] ); ?>"
										title="<?php echo esc_attr( $candidate['detail'] ); ?>">
										<code><?php echo esc_html( $candidate['url'] ); ?></code>
										<span class="owpm-candidate-src"><?php echo esc_html( $candidate['label'] ); ?></span>
									</button>
								<?php endforeach; ?>
							</div>
						<?php else : ?>
							<p class="owpm-no-candidates">
								<?php esc_html_e( 'No previous address could be detected automatically, so type the new one below. That is normal if this site was not imported with this plugin — once an import runs, the address it came from is remembered here.', 'open-migration-unlimited-site-transfer-backup' ); ?>
							</p>
						<?php endif; ?>

						<div class="owpm-domain-form">
							<label class="screen-reader-text" for="owpm-new-domain"><?php esc_html_e( 'New address', 'open-migration-unlimited-site-transfer-backup' ); ?></label>
							<input type="text" id="owpm-new-domain" placeholder="https://example.com"
								autocomplete="off" spellcheck="false"
								<?php echo $first ? 'value="' . esc_attr( $first ) . '"' : ''; ?> />
							<button type="button" class="owpm-btn owpm-btn-secondary" id="owpm-check-domain">
								<?php esc_html_e( 'Check DNS', 'open-migration-unlimited-site-transfer-backup' ); ?>
							</button>
							<button type="button" class="owpm-btn owpm-btn-primary" id="owpm-run-domain">
								<?php esc_html_e( 'Change Domain', 'open-migration-unlimited-site-transfer-backup' ); ?>
							</button>
						</div>

						<div class="owpm-dns-result" id="owpm-dns-result"></div>

						<?php $this->progress_block( 'domain' ); ?>

						<?php $this->accordion_open( __( 'How this works, and what to do first', 'open-migration-unlimited-site-transfer-backup' ) ); ?>
							<ol class="owpm-steps">
								<?php
								$this->step(
									1,
									__( 'Point your DNS at this server first', 'open-migration-unlimited-site-transfer-backup' ),
									__( 'Finish the DNS change at your registrar and wait until the real domain actually loads this site. Use <strong>Check DNS</strong> above to confirm before you commit.', 'open-migration-unlimited-site-transfer-backup' )
								);
								$this->step(
									2,
									__( 'Take a backup', 'open-migration-unlimited-site-transfer-backup' ),
									__( 'This rewrites data across every table and there is no undo. If you enter the wrong address and can still reach the site, you can run it again in reverse.', 'open-migration-unlimited-site-transfer-backup' )
								);
								$this->step(
									3,
									__( 'Sign in again on the new domain', 'open-migration-unlimited-site-transfer-backup' ),
									__( 'Your login cookie belongs to the old address, so you will need to log in once at the new one. Then re-save Settings → Permalinks.', 'open-migration-unlimited-site-transfer-backup' )
								);
								?>
							</ol>
						<?php $this->accordion_close(); ?>
					</section>

					<?php /* ---------------------------------------------- BACKUPS */ ?>
					<section id="panel-backups" class="owpm-panel" role="tabpanel" tabindex="0">
						<div class="owpm-panel-head">
							<h2><?php esc_html_e( 'Backups on this server', 'open-migration-unlimited-site-transfer-backup' ); ?></h2>
							<?php $this->docs_link( 'backups', __( 'Backup documentation', 'open-migration-unlimited-site-transfer-backup' ) ); ?>
							<p class="owpm-lead">
								<?php
								printf(
									/* translators: %s: backup directory path. */
									esc_html__( 'Stored in %s. That folder is locked down and downloads are served through WordPress after a permission check, so nobody can grab your backups by guessing the URL.', 'open-migration-unlimited-site-transfer-backup' ),
									'<code>wp-content/uploads/open-migration-unlimited-site-transfer-backup/</code>'
								);
								?>
							</p>
						</div>
						<div id="owpm-backups-container"></div>
					</section>

					<section id="panel-verify" class="owpm-panel" role="tabpanel" tabindex="0">
						<div class="owpm-panel-head">
							<h2><?php esc_html_e( 'Check this site after a migration', 'open-migration-unlimited-site-transfer-backup' ); ?></h2>
							<?php $this->docs_link( 'after-import', __( 'Post-migration documentation', 'open-migration-unlimited-site-transfer-backup' ) ); ?>
							<p class="owpm-lead">
								<?php esc_html_e( 'A site that loads is not proof the migration finished cleanly. This looks for the things that go wrong quietly — references still pointing at the old address, settings corrupted by a find-and-replace, plugins marked active that are not installed. The checks only read; nothing changes unless you press one of the buttons they offer.', 'open-migration-unlimited-site-transfer-backup' ); ?>
							</p>
						</div>

						<button type="button" class="owpm-btn owpm-btn-primary" id="owpm-run-verify">
							<?php esc_html_e( 'Run checks', 'open-migration-unlimited-site-transfer-backup' ); ?>
						</button>

						<div id="owpm-verify-results"></div>
					</section>

				</div>

				<?php
				/*
				 * Attribution, deliberately scoped.
				 *
				 * Directory guideline 10 forbids putting links or credits on the
				 * public-facing site without the user opting in, and guideline 11
				 * forbids advertising or nagging in the dashboard. So this appears
				 * only inside this plugin's own screen, which the user has chosen to
				 * open. Nothing is ever printed on the front end, and there is no
				 * notice anywhere else in wp-admin.
				 */
				?>
				<p class="owpm-credit">
					<?php
					printf(
						/* translators: %s: link to the plugin author's website. */
						esc_html__( 'Open Migration is free and open source, built by %s.', 'open-migration-unlimited-site-transfer-backup' ),
						'<a href="https://dicecodes.com/" target="_blank" rel="noopener noreferrer">DiceCodes</a>'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}
}
