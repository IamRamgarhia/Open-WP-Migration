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
			wp_send_json_error( __( 'You do not have permission to do that.', 'open-migration' ), 403 );
		}

		global $wpdb;

		$backup_dir = OWPM_FS::backup_dir();
		$probe_dir  = is_dir( $backup_dir ) ? $backup_dir : WP_CONTENT_DIR . '/uploads';

		$free_space = @disk_free_space( WP_CONTENT_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$db_size    = $this->database_size();

		$checks = array();

		$checks[] = array(
			'label'  => __( 'ZipArchive extension', 'open-migration' ),
			'value'  => class_exists( 'ZipArchive' ) ? __( 'Available', 'open-migration' ) : __( 'Missing', 'open-migration' ),
			'state'  => class_exists( 'ZipArchive' ) ? 'ok' : 'error',
			'hint'   => class_exists( 'ZipArchive' ) ? '' : __( 'Required to create and read backups. Ask your host to enable the PHP zip extension.', 'open-migration' ),
		);

		$checks[] = array(
			'label' => __( 'PHP version', 'open-migration' ),
			'value' => PHP_VERSION,
			'state' => version_compare( PHP_VERSION, '7.2', '>=' ) ? 'ok' : 'error',
			'hint'  => version_compare( PHP_VERSION, '7.2', '>=' ) ? '' : __( 'PHP 7.2 or newer is required.', 'open-migration' ),
		);

		$writable = is_dir( $probe_dir ) ? wp_is_writable( $probe_dir ) : wp_is_writable( dirname( $probe_dir ) );

		$checks[] = array(
			'label' => __( 'Backup folder writable', 'open-migration' ),
			'value' => $writable ? __( 'Yes', 'open-migration' ) : __( 'No', 'open-migration' ),
			'state' => $writable ? 'ok' : 'error',
			'hint'  => $writable ? '' : __( 'wp-content/uploads is not writable, so backups cannot be created.', 'open-migration' ),
		);

		$checks[] = array(
			'label' => __( 'Database size', 'open-migration' ),
			'value' => $db_size > 0 ? size_format( $db_size ) : __( 'Unknown', 'open-migration' ),
			'state' => 'info',
			'hint'  => '',
		);

		// A backup needs room for the archive alongside the site it is copying.
		$space_state = 'ok';
		$space_hint  = '';

		if ( $free_space && $db_size > 0 && $free_space < ( $db_size * 3 ) ) {
			$space_state = 'warn';
			$space_hint  = __( 'Free space is low relative to your database. Delete old backups before exporting.', 'open-migration' );
		} elseif ( ! $free_space ) {
			$space_state = 'info';
		}

		$checks[] = array(
			'label' => __( 'Free disk space', 'open-migration' ),
			'value' => $free_space ? size_format( $free_space ) : __( 'Unknown', 'open-migration' ),
			'state' => $space_state,
			'hint'  => $space_hint,
		);

		$checks[] = array(
			'label' => __( 'Upload limit bypass', 'open-migration' ),
			/* translators: %s: chunk size, e.g. 2 MB. */
			'value' => sprintf( __( 'Active (%s chunks)', 'open-migration' ), size_format( $this->chunk_size() ) ),
			'state' => 'ok',
			'hint'  => sprintf(
				/* translators: %s: the server's upload_max_filesize value. */
				__( 'Your server limit is %s, but chunking means archive size does not matter.', 'open-migration' ),
				size_format( wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) ) )
			),
		);

		if ( is_multisite() ) {
			$checks[] = array(
				'label' => __( 'Multisite', 'open-migration' ),
				'value' => __( 'Not supported', 'open-migration' ),
				'state' => 'error',
				'hint'  => __( 'This plugin supports single-site installs only.', 'open-migration' ),
			);
		}

		$blocking = 0;
		foreach ( $checks as $check ) {
			if ( 'error' === $check['state'] ) {
				$blocking++;
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
			__( 'Open Migration', 'open-migration' ),
			__( 'Open Migration', 'open-migration' ),
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
			esc_html__( 'Migrate', 'open-migration' )
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
			esc_html__( 'Documentation & support', 'open-migration' )
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
			'confirmImport'   => __( "This will OVERWRITE this site's database and files with the contents of the backup.\n\nThis cannot be undone. Continue?", 'open-migration' ),
			'confirmDelete'   => __( 'This permanently removes the archive from this server. Any copy you have already downloaded is unaffected.', 'open-migration' ),
			'noZipSupport'    => __( 'The PHP ZipArchive extension is not enabled on this server, so exports and imports cannot run. Please ask your host to enable it.', 'open-migration' ),
			'multisite'       => __( 'Multisite networks are not supported yet.', 'open-migration' ),
			'notZip'          => __( 'Please choose a .zip backup file.', 'open-migration' ),
			'noToken'         => __( 'Please paste a migration token.', 'open-migration' ),
			'noDomain'        => __( 'Please enter the new site address.', 'open-migration' ),
			'continueAnyway'  => __( 'Continue with the import anyway?', 'open-migration' ),
			'importCancelled' => __( 'Import cancelled.', 'open-migration' ),
			'serverError'     => __( 'Server error', 'open-migration' ),
			'unknownError'    => __( 'Unknown error', 'open-migration' ),
			'networkError'    => __( 'The connection to the server failed. Check your internet connection and try again.', 'open-migration' ),
			'loadingBackups'  => __( 'Loading backups…', 'open-migration' ),
			'noBackups'       => __( 'No backups found on this server.', 'open-migration' ),
			'backupsFailed'   => __( 'Failed to load backups.', 'open-migration' ),
			'deleting'        => __( 'Deleting…', 'open-migration' ),
			'download'        => __( 'Download', 'open-migration' ),
			'delete'          => __( 'Delete', 'open-migration' ),
			'colName'         => __( 'Backup Name', 'open-migration' ),
			'colDate'         => __( 'Date', 'open-migration' ),
			'colSize'         => __( 'Size', 'open-migration' ),
			'colActions'      => __( 'Actions', 'open-migration' ),
			'copy'            => __( 'Copy Token', 'open-migration' ),
			'copied'          => __( 'Copied!', 'open-migration' ),
			'tokenTitle'      => __( 'Your Migration Token', 'open-migration' ),
			'tokenNote'       => __( 'Valid for 24 hours. Paste this into the destination site and click Start Pull Transfer.', 'open-migration' ),
			'reloginNeeded'   => __( 'You are now signed out because the imported database replaced this site\'s user accounts. Log in with the username and password from the ORIGINAL site.', 'open-migration' ),
			'reloginDone'     => __( 'You are still signed in. Please review your Permalinks settings and re-save them.', 'open-migration' ),
			'importDone'      => __( 'Import complete.', 'open-migration' ),
			'modalTitle'       => __( 'Please confirm', 'open-migration' ),
			'modalNotice'      => __( 'Open Migration', 'open-migration' ),
			'modalOk'          => __( 'OK', 'open-migration' ),
			'modalCancel'      => __( 'Cancel', 'open-migration' ),
			'modalContinue'    => __( 'Continue', 'open-migration' ),
			'confirmImportTitle' => __( 'Overwrite this site?', 'open-migration' ),
			'confirmImportBtn'   => __( 'Overwrite this site', 'open-migration' ),
			'confirmDomainTitle' => __( 'Change the site address?', 'open-migration' ),
			'confirmDomainBtn'   => __( 'Change domain', 'open-migration' ),
			'confirmDeleteTitle' => __( 'Delete this backup?', 'open-migration' ),
			'confirmDeleteBtn'   => __( 'Delete backup', 'open-migration' ),
			'compatTitle'        => __( 'Check before continuing', 'open-migration' ),
			'confirmDomain'   => __( 'This rewrites every reference across the whole database and cannot be undone. Continue?', 'open-migration' ),
			'confirmDomainFrom' => __( 'Change this site’s address?', 'open-migration' ),
			'dnsChecking'     => __( 'Checking whether that address reaches this server…', 'open-migration' ),
			'dnsWarnMismatch' => __( 'WARNING: the DNS check said that address does NOT point to this server. Continuing will take this site offline.', 'open-migration' ),
			'dnsWarnUnchecked' => __( 'You have not confirmed that this address points to this server. Use "Check DNS" first if you are unsure.', 'open-migration' ),
			'domainDone'      => __( 'Domain changed.', 'open-migration' ),
			'domainRelogin'   => __( 'You are now signed out of this address. Open the new one and log in again, then re-save Settings → Permalinks.', 'open-migration' ),
			'domainGoTo'      => __( 'Go to the new address', 'open-migration' ),
			'rowsUpdated'     => __( 'database rows updated', 'open-migration' ),
		);
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
				<p><?php echo wp_kses( $body, array( 'code' => array(), 'strong' => array(), 'em' => array() ) ); ?></p>
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'open-migration' ), 403 );
		}

		global $wpdb;

		$host = wp_parse_url( site_url(), PHP_URL_HOST );
		?>
		<div class="wrap owpm-wrap">

			<noscript>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Open Migration needs JavaScript enabled to run.', 'open-migration' ); ?></p>
				</div>
			</noscript>

			<div class="owpm-shell" id="owpm-app-root">

				<header class="owpm-header">
					<div class="owpm-brand">
						<span class="owpm-logo dashicons dashicons-migrate" aria-hidden="true"></span>
						<div>
							<h1><?php esc_html_e( 'Open Migration', 'open-migration' ); ?></h1>
							<p><?php esc_html_e( 'Move, clone and restore this site. No upload size limits.', 'open-migration' ); ?></p>
						</div>
					</div>
					<div class="owpm-meta">
						<span class="owpm-chip" title="<?php esc_attr_e( 'The site you are currently working on', 'open-migration' ); ?>">
							<span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
							<?php echo esc_html( $host ); ?>
						</span>
						<span class="owpm-chip owpm-chip-quiet">
							<?php
							/* translators: %s: database table prefix. */
							echo esc_html( sprintf( __( 'prefix %s', 'open-migration' ), $wpdb->prefix ) );
							?>
						</span>
						<span class="owpm-chip owpm-chip-quiet">v<?php echo esc_html( OWPM_VERSION ); ?></span>
					</div>
				</header>

				<nav class="owpm-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Migration tools', 'open-migration' ); ?>">
					<button type="button" class="owpm-tab active" data-target="export" role="tab" aria-selected="true" aria-controls="panel-export">
						<span class="dashicons dashicons-download" aria-hidden="true"></span>
						<?php esc_html_e( 'Export', 'open-migration' ); ?>
					</button>
					<button type="button" class="owpm-tab" data-target="import" role="tab" aria-selected="false" aria-controls="panel-import">
						<span class="dashicons dashicons-upload" aria-hidden="true"></span>
						<?php esc_html_e( 'Import', 'open-migration' ); ?>
					</button>
					<button type="button" class="owpm-tab" data-target="site-to-site" role="tab" aria-selected="false" aria-controls="panel-site-to-site">
						<span class="dashicons dashicons-networking" aria-hidden="true"></span>
						<?php esc_html_e( 'Site-to-Site', 'open-migration' ); ?>
					</button>
					<button type="button" class="owpm-tab" data-target="domain" role="tab" aria-selected="false" aria-controls="panel-domain">
						<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
						<?php esc_html_e( 'Change Domain', 'open-migration' ); ?>
					</button>
					<button type="button" class="owpm-tab" data-target="backups" role="tab" aria-selected="false" aria-controls="panel-backups">
						<span class="dashicons dashicons-database" aria-hidden="true"></span>
						<?php esc_html_e( 'Backups', 'open-migration' ); ?>
					</button>
				</nav>

				<div class="owpm-panels">

					<?php /* ---------------------------------------------- EXPORT */ ?>
					<section id="panel-export" class="owpm-panel active" role="tabpanel" tabindex="0">
						<div class="owpm-panel-head">
							<h2><?php esc_html_e( 'Export this site', 'open-migration' ); ?></h2>
							<?php $this->docs_link( 'export', __( 'Export documentation', 'open-migration' ) ); ?>
							<p class="owpm-lead">
								<?php esc_html_e( 'Package the database and everything in wp-content into a single .zip file you can download, archive, or restore onto another WordPress install.', 'open-migration' ); ?>
							</p>
						</div>

						<div class="owpm-status-card" id="owpm-system-status">
							<div class="owpm-status-head">
								<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
								<strong><?php esc_html_e( 'Server check', 'open-migration' ); ?></strong>
								<span class="owpm-status-summary" id="owpm-status-summary"><?php esc_html_e( 'Checking…', 'open-migration' ); ?></span>
							</div>
							<div class="owpm-status-grid" id="owpm-status-grid"></div>
						</div>

						<div class="owpm-actions">
							<button type="button" class="owpm-btn owpm-btn-primary" id="owpm-start-export">
								<?php esc_html_e( 'Start Export', 'open-migration' ); ?>
							</button>
						</div>

						<?php $this->progress_block( 'export' ); ?>

						<?php $this->accordion_open( __( 'How it works, and what is included', 'open-migration' ) ); ?>
							<ol class="owpm-steps">
								<?php
								$this->step(
									1,
									__( 'Click Start Export', 'open-migration' ),
									__( 'Your database is dumped and your files are zipped in small batches, so the request never times out — even on a large site.', 'open-migration' )
								);
								$this->step(
									2,
									__( 'Wait for it to finish', 'open-migration' ),
									__( 'Keep this tab open. Progress is shown above and you can see exactly which file is being packaged.', 'open-migration' )
								);
								$this->step(
									3,
									__( 'Download the .zip', 'open-migration' ),
									__( 'The archive also stays on this server under the <code>Backups</code> tab until you delete it.', 'open-migration' )
								);
								?>
							</ol>

							<div class="owpm-included">
								<div class="owpm-included-col">
									<h4><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'Included', 'open-migration' ); ?></h4>
									<ul>
										<li><?php esc_html_e( 'All database tables for this site', 'open-migration' ); ?></li>
										<li><?php esc_html_e( 'Themes and plugins', 'open-migration' ); ?></li>
										<li><?php esc_html_e( 'Uploads and media library', 'open-migration' ); ?></li>
										<li><?php esc_html_e( 'Site URL, path and prefix, for automatic conversion', 'open-migration' ); ?></li>
									</ul>
								</div>
								<div class="owpm-included-col">
									<h4><span class="dashicons dashicons-dismiss" aria-hidden="true"></span> <?php esc_html_e( 'Not included', 'open-migration' ); ?></h4>
									<ul>
										<li><?php esc_html_e( 'wp-config.php and your database password', 'open-migration' ); ?></li>
										<li><?php esc_html_e( 'WordPress core files', 'open-migration' ); ?></li>
										<li><?php esc_html_e( 'Cache, upgrade and transient data', 'open-migration' ); ?></li>
										<li><?php esc_html_e( 'Other plugins’ backup folders', 'open-migration' ); ?></li>
									</ul>
								</div>
							</div>
						<?php $this->accordion_close(); ?>
					</section>

					<?php /* ---------------------------------------------- IMPORT */ ?>
					<section id="panel-import" class="owpm-panel" role="tabpanel" tabindex="0">
						<div class="owpm-panel-head">
							<h2><?php esc_html_e( 'Import a site', 'open-migration' ); ?></h2>
							<?php $this->docs_link( 'import', __( 'Import documentation', 'open-migration' ) ); ?>
							<p class="owpm-lead">
								<?php esc_html_e( 'Restore an Open Migration .zip onto this install. You do not need to prepare anything first — a brand new WordPress works fine.', 'open-migration' ); ?>
							</p>
						</div>

						<div class="owpm-alert owpm-alert-danger">
							<span class="dashicons dashicons-warning" aria-hidden="true"></span>
							<div>
								<strong><?php esc_html_e( 'This replaces the current site.', 'open-migration' ); ?></strong>
								<p>
									<?php
									printf(
										/* translators: %s: the current site host name. */
										esc_html__( 'Everything on %s — its posts, users, themes, plugins and media — will be overwritten by the contents of the archive. This cannot be undone. Export a backup of this site first if you might need it.', 'open-migration' ),
										'<code>' . esc_html( $host ) . '</code>'
									);
									?>
								</p>
							</div>
						</div>

						<div class="owpm-upload-area" id="owpm-drop-zone">
							<span class="dashicons dashicons-cloud-upload owpm-upload-icon" aria-hidden="true"></span>
							<p class="owpm-drop-title"><?php esc_html_e( 'Drag &amp; drop your backup here', 'open-migration' ); ?></p>
							<p class="owpm-drop-sub"><?php esc_html_e( 'Accepts .zip files of any size', 'open-migration' ); ?></p>
							<button type="button" class="owpm-btn owpm-btn-secondary" id="owpm-trigger-file">
								<?php esc_html_e( 'Select File', 'open-migration' ); ?>
							</button>
							<input type="file" id="owpm-import-file" accept=".zip,application/zip" />
						</div>

						<?php $this->progress_block( 'import' ); ?>

						<?php $this->accordion_open( __( 'What happens during an import', 'open-migration' ) ); ?>
							<ol class="owpm-steps">
								<?php
								$this->step(
									1,
									__( 'Add your backup file', 'open-migration' ),
									__( 'Drag the <code>.zip</code> onto the box above, or click Select File. It is uploaded in small chunks, so your host’s upload limit does not apply.', 'open-migration' )
								);
								$this->step(
									2,
									__( 'The restore runs automatically', 'open-migration' ),
									__( 'Files are extracted, the database is restored, and then the old site URL, server path and table prefix are converted to match this install.', 'open-migration' )
								);
								$this->step(
									3,
									__( 'Log in with the old site’s account', 'open-migration' ),
									__( 'The archive brings its own user accounts, so your current login stops working. Then open Settings → Permalinks and click Save once.', 'open-migration' )
								);
								?>
							</ol>
						<?php $this->accordion_close(); ?>
					</section>

					<?php /* ---------------------------------------------- SITE TO SITE */ ?>
					<section id="panel-site-to-site" class="owpm-panel" role="tabpanel" tabindex="0">
						<div class="owpm-panel-head">
							<h2><?php esc_html_e( 'Direct site-to-site transfer', 'open-migration' ); ?></h2>
							<?php $this->docs_link( 'site-to-site', __( 'Site-to-site documentation', 'open-migration' ) ); ?>
							<p class="owpm-lead">
								<?php esc_html_e( 'Move a site straight from one server to another without downloading anything. The destination pulls the archive over the WordPress REST API.', 'open-migration' ); ?>
							</p>
						</div>

						<div class="owpm-alert owpm-alert-info">
							<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
							<div>
								<p><?php esc_html_e( 'Use the blue box on the OLD site, then the orange box on the NEW site. The old site’s REST API must be reachable from the new server.', 'open-migration' ); ?></p>
							</div>
						</div>

						<div class="owpm-box owpm-box-blue">
							<h3><span class="owpm-step-n">1</span> <?php esc_html_e( 'On the OLD site — generate a token', 'open-migration' ); ?></h3>
							<p><?php esc_html_e( 'This builds a full backup here, then gives you a one-time token that is valid for 24 hours.', 'open-migration' ); ?></p>
							<button type="button" class="owpm-btn owpm-btn-secondary" id="owpm-generate-token">
								<?php esc_html_e( 'Build Backup &amp; Generate Token', 'open-migration' ); ?>
							</button>
							<?php $this->progress_block( 'token' ); ?>
							<div id="owpm-token-output"></div>
						</div>

						<div class="owpm-box owpm-box-orange">
							<h3><span class="owpm-step-n">2</span> <?php esc_html_e( 'On the NEW site — paste the token', 'open-migration' ); ?></h3>
							<p><?php esc_html_e( 'Paste the token from the old site. This server downloads the archive and restores it, replacing everything currently here.', 'open-migration' ); ?></p>
							<label class="screen-reader-text" for="owpm-import-token"><?php esc_html_e( 'Migration token', 'open-migration' ); ?></label>
							<input type="text" id="owpm-import-token" placeholder="<?php esc_attr_e( 'Paste migration token here…', 'open-migration' ); ?>" autocomplete="off" spellcheck="false" />
							<button type="button" class="owpm-btn owpm-btn-primary" id="owpm-start-pull">
								<?php esc_html_e( 'Start Pull Transfer', 'open-migration' ); ?>
							</button>
							<?php $this->progress_block( 'pull' ); ?>
						</div>
					</section>

					<?php /* ---------------------------------------------- CHANGE DOMAIN */ ?>
					<section id="panel-domain" class="owpm-panel" role="tabpanel" tabindex="0">
						<div class="owpm-panel-head">
							<h2><?php esc_html_e( 'Change this site’s domain', 'open-migration' ); ?></h2>
							<?php $this->docs_link( 'change-domain', __( 'Domain change documentation', 'open-migration' ) ); ?>
							<p class="owpm-lead">
								<?php esc_html_e( 'Rewrites every reference to this site’s current address across the whole database — posts, options, widgets, page-builder data and serialised values included.', 'open-migration' ); ?>
							</p>
						</div>

						<div class="owpm-alert owpm-alert-info">
							<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
							<div>
								<strong><?php esc_html_e( 'Migrated to a new host before pointing DNS? This is the step you need.', 'open-migration' ); ?></strong>
								<p>
									<?php esc_html_e( 'If you imported this site while it was still on a temporary address (for example site.yourhost.com, a staging subdomain, or a raw IP), the database is full of that temporary address. WordPress only ever sees the hostname a visitor requested, so it cannot tell that DNS has since been pointed at the real domain — it will keep redirecting visitors back to the temporary one until you make the change here.', 'open-migration' ); ?>
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
								<span class="owpm-swap-label"><?php esc_html_e( 'Current address', 'open-migration' ); ?></span>
								<code class="owpm-swap-url" id="owpm-domain-current"><?php echo esc_html( $current_url ); ?></code>
							</div>
							<span class="owpm-swap-arrow dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
							<div class="owpm-swap-side">
								<span class="owpm-swap-label"><?php esc_html_e( 'New address', 'open-migration' ); ?></span>
								<code class="owpm-swap-url owpm-swap-new" id="owpm-domain-preview">&mdash;</code>
							</div>
						</div>

						<?php if ( ! empty( $candidates ) ) : ?>
							<div class="owpm-suggestions" id="owpm-domain-suggestions">
								<span class="owpm-suggestions-title">
									<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
									<?php esc_html_e( 'Detected addresses', 'open-migration' ); ?>
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
								<?php esc_html_e( 'No previous address could be detected automatically, so type the new one below. That is normal if this site was not imported with this plugin — once an import runs, the address it came from is remembered here.', 'open-migration' ); ?>
							</p>
						<?php endif; ?>

						<div class="owpm-domain-form">
							<label class="screen-reader-text" for="owpm-new-domain"><?php esc_html_e( 'New address', 'open-migration' ); ?></label>
							<input type="text" id="owpm-new-domain" placeholder="https://example.com"
								autocomplete="off" spellcheck="false"
								<?php echo $first ? 'value="' . esc_attr( $first ) . '"' : ''; ?> />
							<button type="button" class="owpm-btn owpm-btn-secondary" id="owpm-check-domain">
								<?php esc_html_e( 'Check DNS', 'open-migration' ); ?>
							</button>
							<button type="button" class="owpm-btn owpm-btn-primary" id="owpm-run-domain">
								<?php esc_html_e( 'Change Domain', 'open-migration' ); ?>
							</button>
						</div>

						<div class="owpm-dns-result" id="owpm-dns-result"></div>

						<?php $this->progress_block( 'domain' ); ?>

						<?php $this->accordion_open( __( 'How this works, and what to do first', 'open-migration' ) ); ?>
							<ol class="owpm-steps">
								<?php
								$this->step(
									1,
									__( 'Point your DNS at this server first', 'open-migration' ),
									__( 'Finish the DNS change at your registrar and wait until the real domain actually loads this site. Use <strong>Check DNS</strong> above to confirm before you commit.', 'open-migration' )
								);
								$this->step(
									2,
									__( 'Take a backup', 'open-migration' ),
									__( 'This rewrites data across every table and there is no undo. If you enter the wrong address and can still reach the site, you can run it again in reverse.', 'open-migration' )
								);
								$this->step(
									3,
									__( 'Sign in again on the new domain', 'open-migration' ),
									__( 'Your login cookie belongs to the old address, so you will need to log in once at the new one. Then re-save Settings → Permalinks.', 'open-migration' )
								);
								?>
							</ol>
						<?php $this->accordion_close(); ?>
					</section>

					<?php /* ---------------------------------------------- BACKUPS */ ?>
					<section id="panel-backups" class="owpm-panel" role="tabpanel" tabindex="0">
						<div class="owpm-panel-head">
							<h2><?php esc_html_e( 'Backups on this server', 'open-migration' ); ?></h2>
							<?php $this->docs_link( 'backups', __( 'Backup documentation', 'open-migration' ) ); ?>
							<p class="owpm-lead">
								<?php
								printf(
									/* translators: %s: backup directory path. */
									esc_html__( 'Stored in %s. That folder is locked down and downloads are served through WordPress after a permission check, so nobody can grab your backups by guessing the URL.', 'open-migration' ),
									'<code>wp-content/uploads/owpm-backups/</code>'
								);
								?>
							</p>
						</div>
						<div id="owpm-backups-container"></div>
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
						esc_html__( 'Open Migration is free and open source, built by %s.', 'open-migration' ),
						'<a href="https://dicecodes.com/" target="_blank" rel="noopener noreferrer">DiceCodes</a>'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}
}
