<?php

namespace DiviElementorConverter\Pro\Admin;

use DiviElementorConverter\Admin\BatchImporter;
use DiviElementorConverter\Pro\Converter\ThemeBuilderImporter;
use DiviElementorConverter\Pro\Licensing\LicenseClient;
use DiviElementorConverter\Pro\Licensing\LicensePage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro Tools page: uncapped multi-file/multi-layout batch conversion, accepting
 * both single-page (et_builder/et_builder_layouts) and Theme Builder
 * (et_theme_builder) Divi JSON exports. Also hosts the License tab when a
 * LicenseClient is supplied (soft enforcement — see Licensing\LicenseClient).
 *
 * All dispatch identifiers are jhmgcofop_-prefixed on purpose. Free's
 * AdminPage::handle_post() hooks admin_init unscoped, runs BEFORE Pro's
 * (free registers plugins_loaded at priority 10, Pro at 20), and dispatches
 * purely on the POST action / GET action params. Reusing free's identifiers
 * would let free intercept every form submitted on this page. handle_post()
 * additionally scopes itself to this page's own submissions as a second,
 * independent guard.
 */
class ProPage {

	const MENU_SLUG           = 'jhmgcofop-converter';
	const IMPORT_NONCE_NAME   = 'jhmgcofop_import_nonce';
	const IMPORT_NONCE_ACTION = 'jhmgcofop_import';

	private ?LicensePage $license_page;

	public function __construct( ?LicenseClient $license = null ) {
		$this->license_page = $license ? new LicensePage( $license ) : null;
	}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_post' ] );
	}

	// ------------------------------------------------------------------
	// Menu
	// ------------------------------------------------------------------

	public function register_menu(): void {
		add_management_page(
			__( 'Divi to Elementor Converter Pro', 'jhmg-converter-divi-to-elementor-pro' ),
			__( 'Divi → Elementor Pro', 'jhmg-converter-divi-to-elementor-pro' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	// ------------------------------------------------------------------
	// Router
	// ------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'jhmg-converter-divi-to-elementor-pro' ) );
		}

		$action = sanitize_key( $_GET['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $action === 'batch_result' ) {
			$this->render_batch_result();
			return;
		}

		$allowed_tabs = $this->license_page ? [ 'import', 'license' ] : [ 'import' ];
		$tab = sanitize_key( $_GET['tab'] ?? 'import' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, $allowed_tabs, true ) ) {
			$tab = 'import';
		}

		if ( ! $this->license_page ) {
			$this->render_list();
			return;
		}

		$base_url = admin_url( 'tools.php?page=' . self::MENU_SLUG );
		?>
		<div class="wrap jhmgcofop-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Divi to Elementor Converter — Pro', 'jhmg-converter-divi-to-elementor-pro' ); ?></h1>

			<nav class="nav-tab-wrapper jhmgcofop-nav-tabs">
				<a href="<?php echo esc_url( $base_url . '&tab=import' ); ?>"
				   class="nav-tab<?php echo $tab === 'import' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Import', 'jhmg-converter-divi-to-elementor-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( $base_url . '&tab=license' ); ?>"
				   class="nav-tab<?php echo $tab === 'license' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'License', 'jhmg-converter-divi-to-elementor-pro' ); ?>
				</a>
			</nav>

			<div class="jhmgcofop-tab-content">
				<?php if ( $tab === 'license' ) : ?>
					<?php $this->license_page->render(); ?>
				<?php else : ?>
					<?php $this->render_import_section(); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// POST dispatcher
	// ------------------------------------------------------------------

	public function handle_post(): void {
		// Page-scoped: other admin pages (including the free plugin's own)
		// also hook admin_init and must not be double-processed here.
		$page = sanitize_key( $_GET['page'] ?? ( $_POST['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only routing check, each handler verifies its own nonce
		if ( $page !== self::MENU_SLUG ) {
			return;
		}

		$action = sanitize_key( $_POST['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- each handler verifies its own nonce
		if ( $action === 'jhmgcofop_import' ) {
			$this->handle_import();
		}
		if ( $action === 'jhmgcofop_save_license' && $this->license_page ) {
			$this->license_page->handle_post();
		}

		$jhmgcofop_action = sanitize_key( $_GET['jhmgcofop_action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- handler verifies its own nonce
		if ( $jhmgcofop_action === 'publish' ) {
			$this->handle_publish();
		}
		if ( in_array( $jhmgcofop_action, [ 'deactivate_license', 'refresh_license' ], true ) && $this->license_page ) {
			$this->license_page->handle_post();
		}
	}

	// ------------------------------------------------------------------
	// Import handler
	// ------------------------------------------------------------------

	private function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'jhmg-converter-divi-to-elementor-pro' ) );
		}

		check_admin_referer( self::IMPORT_NONCE_ACTION, self::IMPORT_NONCE_NAME );

		$raw = isset( $_FILES['jhmgcofop_import_files'] ) && is_array( $_FILES['jhmgcofop_import_files'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			? $_FILES['jhmgcofop_import_files'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: null;

		if ( ! $raw ) {
			wp_die( esc_html__( 'No file was uploaded.', 'jhmg-converter-divi-to-elementor-pro' ) );
		}

		// Normalise single-file and multi-file $_FILES shapes into a list.
		if ( is_array( $raw['name'] ) ) {
			$files = [];
			foreach ( $raw['name'] as $i => $name ) {
				$files[] = [
					'name'     => $name,
					'tmp_name' => $raw['tmp_name'][ $i ],
					'error'    => $raw['error'][ $i ],
				];
			}
		} else {
			$files = [ $raw ];
		}

		$post_type = sanitize_key( $_POST['jhmgcofop_post_type'] ?? 'page' );
		if ( ! in_array( $post_type, [ 'page', 'post' ], true ) ) {
			$post_type = 'page';
		}

		$post_status = sanitize_key( $_POST['jhmgcofop_post_status'] ?? 'draft' );
		if ( ! in_array( $post_status, [ 'draft', 'publish' ], true ) ) {
			$post_status = 'draft';
		}

		$batch_importer = new BatchImporter();
		$tb_importer    = new ThemeBuilderImporter();
		$results        = [];

		foreach ( $files as $file ) {
			if ( $file['error'] !== UPLOAD_ERR_OK ) {
				$results[] = [
					'title'   => sanitize_file_name( $file['name'] ),
					'post_id' => 0,
					'success' => false,
					'error'   => $this->upload_error_message( (int) $file['error'] ),
				];
				continue;
			}

			$json = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( $json === false ) {
				$results[] = [
					'title'   => sanitize_file_name( $file['name'] ),
					'post_id' => 0,
					'success' => false,
					'error'   => __( 'Could not read the uploaded file.', 'jhmg-converter-divi-to-elementor-pro' ),
				];
				continue;
			}

			// et_theme_builder exports route through ThemeBuilderImporter (header/
			// footer/body); everything else (et_builder, et_builder_layouts) goes
			// through the free BatchImporter, uncapped via jhmgcofo_max_layouts.
			$decoded = json_decode( $json, true );
			$context = is_array( $decoded ) ? (string) ( $decoded['context'] ?? '' ) : '';

			if ( $context === 'et_theme_builder' ) {
				try {
					$tb_results = $tb_importer->import( $json );
				} catch ( \Throwable $e ) {
					$results[] = [
						'title'   => sanitize_file_name( $file['name'] ),
						'post_id' => 0,
						'success' => false,
						'error'   => $e->getMessage(),
					];
					continue;
				}
				foreach ( $tb_results as $tb_result ) {
					$results[] = [
						'title'   => ucfirst( $tb_result['role'] ),
						'post_id' => $tb_result['post_id'],
						'success' => true,
						'error'   => '',
					];
				}
				continue;
			}

			$file_results = $batch_importer->import( $json, $file['name'], [
				'post_type'   => $post_type,
				'post_status' => $post_status,
			] );
			$results = array_merge( $results, $file_results );
		}

		$import_id = $this->generate_import_id();
		set_transient( 'jhmgcofop_batch_' . $import_id, $results, HOUR_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'      => self::MENU_SLUG,
					'action'    => 'batch_result',
					'import_id' => $import_id,
				],
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	private function handle_publish(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'jhmg-converter-divi-to-elementor-pro' ) );
		}

		$post_id   = absint( wp_unslash( $_GET['post_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below
		$import_id = sanitize_key( $_GET['import_id'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( 'jhmgcofop_publish_' . $post_id );

		if ( $post_id <= 0 ) {
			wp_die( esc_html__( 'Invalid post ID.', 'jhmg-converter-divi-to-elementor-pro' ) );
		}

		wp_update_post( [
			'ID'          => $post_id,
			'post_status' => 'publish',
		] );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'      => self::MENU_SLUG,
					'action'    => 'batch_result',
					'import_id' => $import_id,
				],
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	private function generate_import_id(): string {
		return function_exists( 'wp_generate_uuid4' )
			? wp_generate_uuid4()
			: bin2hex( random_bytes( 16 ) );
	}

	private function upload_error_message( int $code ): string {
		$messages = [
			UPLOAD_ERR_INI_SIZE   => __( 'File exceeds server upload limit.', 'jhmg-converter-divi-to-elementor-pro' ),
			UPLOAD_ERR_FORM_SIZE  => __( 'File exceeds form upload limit.', 'jhmg-converter-divi-to-elementor-pro' ),
			UPLOAD_ERR_PARTIAL    => __( 'File was only partially uploaded.', 'jhmg-converter-divi-to-elementor-pro' ),
			UPLOAD_ERR_NO_FILE    => __( 'No file was selected.', 'jhmg-converter-divi-to-elementor-pro' ),
			UPLOAD_ERR_NO_TMP_DIR => __( 'Server is missing a temporary folder.', 'jhmg-converter-divi-to-elementor-pro' ),
			UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file to server.', 'jhmg-converter-divi-to-elementor-pro' ),
			UPLOAD_ERR_EXTENSION  => __( 'Upload stopped by server extension.', 'jhmg-converter-divi-to-elementor-pro' ),
		];
		/* translators: %d is the numeric upload error code */
		return $messages[ $code ] ?? sprintf( __( 'Unknown upload error (code %d).', 'jhmg-converter-divi-to-elementor-pro' ), (int) $code );
	}

	// ------------------------------------------------------------------
	// Main view
	// ------------------------------------------------------------------

	private function render_list(): void {
		?>
		<div class="wrap jhmgcofop-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Divi to Elementor Converter — Pro', 'jhmg-converter-divi-to-elementor-pro' ); ?></h1>
			<?php $this->render_import_section(); ?>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Import view
	// ------------------------------------------------------------------

	private function render_import_section(): void {
		?>
		<div class="jhmgcofop-import-section">
			<h2><?php esc_html_e( 'Import Divi Layout JSON', 'jhmg-converter-divi-to-elementor-pro' ); ?></h2>
			<p class="jhmgcofop-description">
				<?php esc_html_e( 'Upload one or more Divi Builder JSON exports. Single-layout, multi-layout (Builder Library), and Theme Builder (header/footer) exports are all supported and converted in one run.', 'jhmg-converter-divi-to-elementor-pro' ); ?>
			</p>

			<form method="post" enctype="multipart/form-data" action="" class="jhmgcofop-import-form">
				<?php wp_nonce_field( self::IMPORT_NONCE_ACTION, self::IMPORT_NONCE_NAME ); ?>
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<input type="hidden" name="action" value="jhmgcofop_import">

				<div class="jhmgcofop-import-fields">
					<div class="jhmgcofop-import-field">
						<label for="jhmgcofop_import_files">
							<strong><?php esc_html_e( 'Files', 'jhmg-converter-divi-to-elementor-pro' ); ?></strong>
						</label>
						<input type="file" id="jhmgcofop_import_files" name="jhmgcofop_import_files[]" accept=".json" multiple required>
						<p class="description"><?php esc_html_e( 'Accepted: one or more .json files (Divi layout, Builder Library, or Theme Builder exports)', 'jhmg-converter-divi-to-elementor-pro' ); ?></p>
					</div>

					<div class="jhmgcofop-import-field">
						<label for="jhmgcofop_post_type">
							<strong><?php esc_html_e( 'Create as', 'jhmg-converter-divi-to-elementor-pro' ); ?></strong>
						</label>
						<select id="jhmgcofop_post_type" name="jhmgcofop_post_type">
							<option value="page"><?php esc_html_e( 'Page', 'jhmg-converter-divi-to-elementor-pro' ); ?></option>
							<option value="post"><?php esc_html_e( 'Post', 'jhmg-converter-divi-to-elementor-pro' ); ?></option>
						</select>
					</div>

					<div class="jhmgcofop-import-field">
						<label for="jhmgcofop_post_status">
							<strong><?php esc_html_e( 'Status', 'jhmg-converter-divi-to-elementor-pro' ); ?></strong>
						</label>
						<select id="jhmgcofop_post_status" name="jhmgcofop_post_status">
							<option value="draft"><?php esc_html_e( 'Draft (recommended)', 'jhmg-converter-divi-to-elementor-pro' ); ?></option>
							<option value="publish"><?php esc_html_e( 'Published', 'jhmg-converter-divi-to-elementor-pro' ); ?></option>
						</select>
					</div>
				</div>

				<div class="jhmgcofop-import-submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Import and Convert', 'jhmg-converter-divi-to-elementor-pro' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Batch result view
	// ------------------------------------------------------------------

	private function render_batch_result(): void {
		$import_id = sanitize_key( $_GET['import_id'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $import_id === '' ) {
			wp_die( esc_html__( 'No import ID provided.', 'jhmg-converter-divi-to-elementor-pro' ) );
		}

		$results = get_transient( 'jhmgcofop_batch_' . $import_id );

		if ( ! is_array( $results ) ) {
			wp_die( esc_html__( 'Import results not found or expired. Results are kept for one hour.', 'jhmg-converter-divi-to-elementor-pro' ) );
		}

		$total     = count( $results );
		$succeeded = count( array_filter( $results, fn( $r ) => $r['success'] ) );
		$failed    = $total - $succeeded;
		?>
		<div class="wrap jhmgcofop-wrap">
			<h1><?php esc_html_e( 'Conversion Results', 'jhmg-converter-divi-to-elementor-pro' ); ?></h1>

			<div class="jhmgcofop-result-actions">
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ); ?>" class="button">
					&larr; <?php esc_html_e( 'Back to converter', 'jhmg-converter-divi-to-elementor-pro' ); ?>
				</a>
			</div>

			<div class="jhmgcofop-batch-summary">
				<span class="jhmgcofop-summary-stat jhmgcofop-summary-stat--total">
					<?php
					/* translators: %d is the number of layouts processed */
					printf( esc_html__( '%d layout(s) processed', 'jhmg-converter-divi-to-elementor-pro' ), absint( $total ) );
					?>
				</span>
				<?php if ( $succeeded > 0 ) : ?>
				<span class="jhmgcofop-summary-stat jhmgcofop-summary-stat--ok">
					<?php
					/* translators: %d is the number of successfully converted layouts */
					printf( esc_html__( '%d converted', 'jhmg-converter-divi-to-elementor-pro' ), absint( $succeeded ) );
					?>
				</span>
				<?php endif; ?>
				<?php if ( $failed > 0 ) : ?>
				<span class="jhmgcofop-summary-stat jhmgcofop-summary-stat--fail">
					<?php
					/* translators: %d is the number of layouts that failed */
					printf( esc_html__( '%d failed', 'jhmg-converter-divi-to-elementor-pro' ), absint( $failed ) );
					?>
				</span>
				<?php endif; ?>
			</div>

			<table class="wp-list-table widefat fixed striped jhmgcofop-batch-table">
				<thead>
					<tr>
						<th class="column-title column-primary"><?php esc_html_e( 'Title', 'jhmg-converter-divi-to-elementor-pro' ); ?></th>
						<th class="column-status"><?php esc_html_e( 'Status', 'jhmg-converter-divi-to-elementor-pro' ); ?></th>
						<th class="column-actions"><?php esc_html_e( 'Actions', 'jhmg-converter-divi-to-elementor-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $results as $result ) : ?>
					<tr>
						<td class="column-title column-primary">
							<strong><?php echo esc_html( $result['title'] ?: __( '(no title)', 'jhmg-converter-divi-to-elementor-pro' ) ); ?></strong>
						</td>
						<td class="column-status">
							<?php if ( $result['success'] ) : ?>
								<span class="jhmgcofop-status jhmgcofop-status--converted">&#10003; <?php esc_html_e( 'Converted', 'jhmg-converter-divi-to-elementor-pro' ); ?></span>
							<?php else : ?>
								<span class="jhmgcofop-status jhmgcofop-status--error">&#10007; <?php esc_html_e( 'Failed', 'jhmg-converter-divi-to-elementor-pro' ); ?></span>
								<?php if ( ! empty( $result['error'] ) ) : ?>
									<br><small class="jhmgcofop-error-msg"><?php echo esc_html( $result['error'] ); ?></small>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td class="column-actions">
							<?php if ( $result['success'] && $result['post_id'] > 0 ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $result['post_id'] ) ); ?>" class="button button-small">
									<?php esc_html_e( 'Edit in Elementor', 'jhmg-converter-divi-to-elementor-pro' ); ?>
								</a>
								<a href="<?php echo esc_url( (string) get_permalink( $result['post_id'] ) ); ?>" class="button button-small" target="_blank" rel="noopener">
									<?php esc_html_e( 'Preview', 'jhmg-converter-divi-to-elementor-pro' ); ?>
								</a>
								<?php if ( get_post_status( $result['post_id'] ) !== 'publish' ) :
									$publish_url = wp_nonce_url(
										add_query_arg(
											[
												'page'             => self::MENU_SLUG,
												'action'           => 'batch_result',
												'import_id'        => $import_id,
												'jhmgcofop_action' => 'publish',
												'post_id'          => $result['post_id'],
											],
											admin_url( 'tools.php' )
										),
										'jhmgcofop_publish_' . $result['post_id']
									);
								?>
									<a href="<?php echo esc_url( $publish_url ); ?>" class="button button-small button-primary">
										<?php esc_html_e( 'Publish', 'jhmg-converter-divi-to-elementor-pro' ); ?>
									</a>
								<?php else : ?>
									<span class="jhmgcofop-published-label">&#10003; <?php esc_html_e( 'Published', 'jhmg-converter-divi-to-elementor-pro' ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
