<?php

namespace DiviElementorConverter\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminPage {
    const MENU_SLUG           = 'jhmgcofo-converter';
    const IMPORT_NONCE_NAME   = 'jhmgcofo_import_nonce';
    const IMPORT_NONCE_ACTION = 'jhmgcofo_import';

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_post' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ] );
    }

    public function enqueue_admin_styles( string $hook ): void {
        if ( strpos( $hook, self::MENU_SLUG ) === false ) {
            return;
        }
        wp_register_style( 'jhmgcofo-admin', false, [], JHMGCOFO_PLUGIN_VERSION );
        wp_enqueue_style( 'jhmgcofo-admin' );
        wp_add_inline_style( 'jhmgcofo-admin', $this->inline_css() );
    }

    // ------------------------------------------------------------------
    // Menu
    // ------------------------------------------------------------------

    public function register_menu(): void {
        add_management_page(
            __( 'Divi to Elementor Converter', 'jhmg-converter-for-divi-to-elementor' ),
            __( 'Divi → Elementor', 'jhmg-converter-for-divi-to-elementor' ),
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
            wp_die( esc_html__( 'You do not have permission to access this page.', 'jhmg-converter-for-divi-to-elementor' ) );
        }

        $action = sanitize_key( $_GET['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( $action === 'batch_result' ) {
            $this->render_batch_result();
        } else {
            $this->render_list();
        }
    }

    // ------------------------------------------------------------------
    // POST dispatcher
    // ------------------------------------------------------------------

    public function handle_post(): void {
        $action = sanitize_key( $_POST['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- each handler verifies its own nonce
        if ( $action === 'jhmgcofo_import' ) {
            $this->handle_import();
        }

        $jhmgcofo_action = sanitize_key( $_GET['jhmgcofo_action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- handler verifies its own nonce
        if ( $jhmgcofo_action === 'publish' ) {
            $this->handle_publish();
        }
    }

    // ------------------------------------------------------------------
    // Import handler
    // ------------------------------------------------------------------

    private function handle_import(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'jhmg-converter-for-divi-to-elementor' ) );
        }

        check_admin_referer( self::IMPORT_NONCE_ACTION, self::IMPORT_NONCE_NAME );

        $raw = isset( $_FILES['jhmgcofo_import_file'] ) && is_array( $_FILES['jhmgcofo_import_file'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            ? $_FILES['jhmgcofo_import_file'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : null;

        if ( ! $raw ) {
            wp_die( esc_html__( 'No file was uploaded.', 'jhmg-converter-for-divi-to-elementor' ) );
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

        $post_type = sanitize_key( $_POST['jhmgcofo_post_type'] ?? 'page' );
        if ( ! in_array( $post_type, [ 'page', 'post' ], true ) ) {
            $post_type = 'page';
        }

        $post_status = sanitize_key( $_POST['jhmgcofo_post_status'] ?? 'draft' );
        if ( ! in_array( $post_status, [ 'draft', 'publish' ], true ) ) {
            $post_status = 'draft';
        }

        $importer = new BatchImporter();
        $results  = [];

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
                    'error'   => __( 'Could not read the uploaded file.', 'jhmg-converter-for-divi-to-elementor' ),
                ];
                continue;
            }

            $file_results = $importer->import( $json, $file['name'], [
                'post_type'   => $post_type,
                'post_status' => $post_status,
            ] );
            $results = array_merge( $results, $file_results );
        }

        $import_id = $this->generate_import_id();
        set_transient( 'jhmgcofo_batch_' . $import_id, $results, HOUR_IN_SECONDS );

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
            wp_die( esc_html__( 'Insufficient permissions.', 'jhmg-converter-for-divi-to-elementor' ) );
        }

        $post_id   = absint( wp_unslash( $_GET['post_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below
        $import_id = sanitize_key( $_GET['import_id'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        check_admin_referer( 'jhmgcofo_publish_' . $post_id );

        if ( $post_id <= 0 ) {
            wp_die( esc_html__( 'Invalid post ID.', 'jhmg-converter-for-divi-to-elementor' ) );
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
            UPLOAD_ERR_INI_SIZE   => __( 'File exceeds server upload limit.', 'jhmg-converter-for-divi-to-elementor' ),
            UPLOAD_ERR_FORM_SIZE  => __( 'File exceeds form upload limit.', 'jhmg-converter-for-divi-to-elementor' ),
            UPLOAD_ERR_PARTIAL    => __( 'File was only partially uploaded.', 'jhmg-converter-for-divi-to-elementor' ),
            UPLOAD_ERR_NO_FILE    => __( 'No file was selected.', 'jhmg-converter-for-divi-to-elementor' ),
            UPLOAD_ERR_NO_TMP_DIR => __( 'Server is missing a temporary folder.', 'jhmg-converter-for-divi-to-elementor' ),
            UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file to server.', 'jhmg-converter-for-divi-to-elementor' ),
            UPLOAD_ERR_EXTENSION  => __( 'Upload stopped by server extension.', 'jhmg-converter-for-divi-to-elementor' ),
        ];
        /* translators: %d is the numeric upload error code */
        return $messages[ $code ] ?? sprintf( __( 'Unknown upload error (code %d).', 'jhmg-converter-for-divi-to-elementor' ), (int) $code );
    }

    // ------------------------------------------------------------------
    // Main view
    // ------------------------------------------------------------------

    private function render_list(): void {
        ?>
        <div class="wrap dec-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Divi to Elementor Converter', 'jhmg-converter-for-divi-to-elementor' ); ?></h1>
            <div class="dec-layout-with-sidebar">
                <div class="dec-layout-main">
                    <?php $this->render_import_section(); ?>
                </div>
                <aside class="dec-layout-sidebar">
                    <?php $this->render_sidebar(); ?>
                </aside>
            </div>
        </div>
        <?php
    }

    private function render_sidebar(): void {
        ?>
        <div class="dec-sidebar">

            <div class="dec-sidebar-card dec-sidebar-card--promo">
                <h3 class="dec-sidebar-card-title"><?php esc_html_e( 'Also by JHMG', 'jhmg-converter-for-divi-to-elementor' ); ?></h3>
                <div class="dec-sidebar-promo">
                    <div class="dec-sidebar-promo-icon">⇄</div>
                    <div>
                        <strong class="dec-sidebar-promo-name"><?php esc_html_e( 'Elementor to Divi Converter', 'jhmg-converter-for-divi-to-elementor' ); ?></strong>
                        <p class="dec-sidebar-promo-desc"><?php esc_html_e( 'Need to go the other direction? Convert Elementor layouts to Divi with our companion plugin.', 'jhmg-converter-for-divi-to-elementor' ); ?></p>
                        <a href="https://wordpress.org/plugins/jhmg-converter-for-elementor-to-divi/"
                           class="button dec-sidebar-promo-link"
                           target="_blank"
                           rel="noopener noreferrer">
                            <?php esc_html_e( 'View on WordPress.org →', 'jhmg-converter-for-divi-to-elementor' ); ?>
                        </a>
                    </div>
                </div>
            </div>

            <div class="dec-sidebar-card dec-sidebar-card--donate">
                <h3 class="dec-sidebar-card-title"><?php esc_html_e( 'Enjoying the Plugin?', 'jhmg-converter-for-divi-to-elementor' ); ?></h3>
                <p class="dec-sidebar-donate-desc"><?php esc_html_e( 'If this plugin saved you time, consider showing your appreciation with a small donation. It helps keep the plugin maintained and improved.', 'jhmg-converter-for-divi-to-elementor' ); ?></p>
                <form action="https://www.paypal.com/donate" method="post" target="_top" class="dec-sidebar-donate-form">
                    <input type="hidden" name="hosted_button_id" value="PNMHRFF94M2Y2">
                    <input type="image" src="https://www.paypal.com/en_US/i/btn/btn_donate_LG.gif" border="0" name="submit"
                        title="<?php esc_attr_e( 'PayPal - The safer, easier way to pay online!', 'jhmg-converter-for-divi-to-elementor' ); ?>"
                        alt="<?php esc_attr_e( 'Donate with PayPal button', 'jhmg-converter-for-divi-to-elementor' ); ?>">
                </form>
            </div>

        </div>
        <?php
    }

    // ------------------------------------------------------------------
    // Import view
    // ------------------------------------------------------------------

    private function render_import_section(): void {
        ?>
        <div class="dec-import-section">
            <h2><?php esc_html_e( 'Import Divi Layout JSON', 'jhmg-converter-for-divi-to-elementor' ); ?></h2>
            <p class="dec-description">
                <?php esc_html_e( 'Upload one or more Divi Builder JSON exports. Single-layout and multi-layout (Builder Library) exports are both supported — each layout becomes its own Elementor draft page.', 'jhmg-converter-for-divi-to-elementor' ); ?>
            </p>

            <form method="post" enctype="multipart/form-data" action="" class="dec-import-form">
                <?php wp_nonce_field( self::IMPORT_NONCE_ACTION, self::IMPORT_NONCE_NAME ); ?>
                <input type="hidden" name="action" value="jhmgcofo_import">

                <div class="dec-import-fields">
                    <div class="dec-import-field">
                        <label for="jhmgcofo_import_file">
                            <strong><?php esc_html_e( 'File', 'jhmg-converter-for-divi-to-elementor' ); ?></strong>
                        </label>
                        <input type="file" id="jhmgcofo_import_file" name="jhmgcofo_import_file[]" accept=".json" multiple required>
                        <p class="description"><?php esc_html_e( 'Accepted: one or more .json files (Divi layout or Builder Library exports)', 'jhmg-converter-for-divi-to-elementor' ); ?></p>
                    </div>

                    <div class="dec-import-field">
                        <label for="jhmgcofo_post_type">
                            <strong><?php esc_html_e( 'Create as', 'jhmg-converter-for-divi-to-elementor' ); ?></strong>
                        </label>
                        <select id="jhmgcofo_post_type" name="jhmgcofo_post_type">
                            <option value="page"><?php esc_html_e( 'Page', 'jhmg-converter-for-divi-to-elementor' ); ?></option>
                            <option value="post"><?php esc_html_e( 'Post', 'jhmg-converter-for-divi-to-elementor' ); ?></option>
                        </select>
                    </div>

                    <div class="dec-import-field">
                        <label for="jhmgcofo_post_status">
                            <strong><?php esc_html_e( 'Status', 'jhmg-converter-for-divi-to-elementor' ); ?></strong>
                        </label>
                        <select id="jhmgcofo_post_status" name="jhmgcofo_post_status">
                            <option value="draft"><?php esc_html_e( 'Draft (recommended)', 'jhmg-converter-for-divi-to-elementor' ); ?></option>
                            <option value="publish"><?php esc_html_e( 'Published', 'jhmg-converter-for-divi-to-elementor' ); ?></option>
                        </select>
                    </div>
                </div>

                <div class="dec-import-submit">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e( 'Import and Convert', 'jhmg-converter-for-divi-to-elementor' ); ?>
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
            wp_die( esc_html__( 'No import ID provided.', 'jhmg-converter-for-divi-to-elementor' ) );
        }

        $results = get_transient( 'jhmgcofo_batch_' . $import_id );

        if ( ! is_array( $results ) ) {
            wp_die( esc_html__( 'Import results not found or expired. Results are kept for one hour.', 'jhmg-converter-for-divi-to-elementor' ) );
        }

        $total     = count( $results );
        $succeeded = count( array_filter( $results, fn( $r ) => $r['success'] ) );
        $failed    = $total - $succeeded;
        ?>
        <div class="wrap dec-wrap">
            <h1><?php esc_html_e( 'Conversion Results', 'jhmg-converter-for-divi-to-elementor' ); ?></h1>

            <div class="dec-result-actions">
                <a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ); ?>" class="button">
                    &larr; <?php esc_html_e( 'Back to converter', 'jhmg-converter-for-divi-to-elementor' ); ?>
                </a>
            </div>

            <div class="dec-batch-summary">
                <span class="dec-summary-stat dec-summary-stat--total">
                    <?php
                    /* translators: %d is the number of layouts processed */
                    printf( esc_html__( '%d layout(s) processed', 'jhmg-converter-for-divi-to-elementor' ), absint( $total ) );
                    ?>
                </span>
                <?php if ( $succeeded > 0 ) : ?>
                <span class="dec-summary-stat dec-summary-stat--ok">
                    <?php
                    /* translators: %d is the number of successfully converted layouts */
                    printf( esc_html__( '%d converted', 'jhmg-converter-for-divi-to-elementor' ), absint( $succeeded ) );
                    ?>
                </span>
                <?php endif; ?>
                <?php if ( $failed > 0 ) : ?>
                <span class="dec-summary-stat dec-summary-stat--fail">
                    <?php
                    /* translators: %d is the number of layouts that failed */
                    printf( esc_html__( '%d failed', 'jhmg-converter-for-divi-to-elementor' ), absint( $failed ) );
                    ?>
                </span>
                <?php endif; ?>
            </div>

            <table class="wp-list-table widefat fixed striped dec-batch-table">
                <thead>
                    <tr>
                        <th class="column-title column-primary"><?php esc_html_e( 'Title', 'jhmg-converter-for-divi-to-elementor' ); ?></th>
                        <th class="column-status"><?php esc_html_e( 'Status', 'jhmg-converter-for-divi-to-elementor' ); ?></th>
                        <th class="column-actions"><?php esc_html_e( 'Actions', 'jhmg-converter-for-divi-to-elementor' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $results as $result ) : ?>
                    <tr>
                        <td class="column-title column-primary">
                            <strong><?php echo esc_html( $result['title'] ?: __( '(no title)', 'jhmg-converter-for-divi-to-elementor' ) ); ?></strong>
                        </td>
                        <td class="column-status">
                            <?php if ( $result['success'] ) : ?>
                                <span class="dec-status dec-status--converted">&#10003; <?php esc_html_e( 'Converted', 'jhmg-converter-for-divi-to-elementor' ); ?></span>
                            <?php else : ?>
                                <span class="dec-status dec-status--error">&#10007; <?php esc_html_e( 'Failed', 'jhmg-converter-for-divi-to-elementor' ); ?></span>
                                <?php if ( ! empty( $result['error'] ) ) : ?>
                                    <br><small class="dec-error-msg"><?php echo esc_html( $result['error'] ); ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="column-actions">
                            <?php if ( $result['success'] && $result['post_id'] > 0 ) : ?>
                                <a href="<?php echo esc_url( get_edit_post_link( $result['post_id'] ) ); ?>" class="button button-small">
                                    <?php esc_html_e( 'Edit in Elementor', 'jhmg-converter-for-divi-to-elementor' ); ?>
                                </a>
                                <a href="<?php echo esc_url( (string) get_permalink( $result['post_id'] ) ); ?>" class="button button-small" target="_blank" rel="noopener">
                                    <?php esc_html_e( 'Preview', 'jhmg-converter-for-divi-to-elementor' ); ?>
                                </a>
                                <?php if ( get_post_status( $result['post_id'] ) !== 'publish' ) :
                                    $publish_url = wp_nonce_url(
                                        add_query_arg(
                                            [
                                                'page'            => self::MENU_SLUG,
                                                'action'          => 'batch_result',
                                                'import_id'       => $import_id,
                                                'jhmgcofo_action' => 'publish',
                                                'post_id'         => $result['post_id'],
                                            ],
                                            admin_url( 'tools.php' )
                                        ),
                                        'jhmgcofo_publish_' . $result['post_id']
                                    );
                                ?>
                                    <a href="<?php echo esc_url( $publish_url ); ?>" class="button button-small button-primary">
                                        <?php esc_html_e( 'Publish', 'jhmg-converter-for-divi-to-elementor' ); ?>
                                    </a>
                                <?php else : ?>
                                    <span class="dec-published-label">&#10003; <?php esc_html_e( 'Published', 'jhmg-converter-for-divi-to-elementor' ); ?></span>
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

    // ------------------------------------------------------------------
    // Styles
    // ------------------------------------------------------------------

    private function inline_css(): string {
        return '
.dec-wrap { max-width: 1200px; }
.dec-description { margin: 8px 0 20px; color: #555; }

/* Import section */
.dec-import-section { background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #2271b1; border-radius: 3px; padding: 20px 24px; margin: 0; }
.dec-import-section h2 { margin: 0 0 6px; font-size: 15px; }
.dec-import-form { margin-top: 16px; }
.dec-import-fields { display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-end; }
.dec-import-field { display: flex; flex-direction: column; gap: 4px; }
.dec-import-field label strong { display: block; margin-bottom: 2px; }
.dec-import-field input[type="file"] { padding: 4px 0; }
.dec-import-field .description { margin: 4px 0 0; font-size: 11px; color: #757575; }
.dec-import-submit { display: flex; align-items: flex-end; padding-bottom: 2px; margin-top: 16px; }

/* Status badges */
.dec-status { font-weight: 600; }
.dec-status--converted { color: #2e7d32; }
.dec-status--error     { color: #c62828; }
.dec-error-msg         { color: #c62828; font-size: 11px; }

/* Batch summary */
.dec-batch-summary { display: flex; gap: 12px; margin: 16px 0 20px; flex-wrap: wrap; }
.dec-summary-stat { display: inline-flex; align-items: center; padding: 6px 14px; border-radius: 3px; font-weight: 600; font-size: 13px; }
.dec-summary-stat--total { background: #f0f0f1; color: #3c434a; }
.dec-summary-stat--ok    { background: #d1e7dd; color: #0a3622; }
.dec-summary-stat--fail  { background: #f8d7da; color: #58151c; }

/* Batch table */
.dec-batch-table .column-status  { width: 150px; }
.dec-batch-table .column-actions { width: 260px; }

/* Publish action */
.dec-published-label { color: #2e7d32; font-size: 12px; font-weight: 600; }

/* Result actions */
.dec-result-actions { display: flex; gap: 8px; margin: 16px 0 24px; flex-wrap: wrap; }

/* ================================================================
   TWO-COLUMN LAYOUT WITH SIDEBAR
   ================================================================ */

.dec-layout-with-sidebar { display: flex; gap: 24px; align-items: flex-start; margin-top: 16px; }
.dec-layout-main { flex: 1; min-width: 0; }
.dec-layout-sidebar { width: 280px; flex-shrink: 0; }

/* Sidebar container */
.dec-sidebar { display: flex; flex-direction: column; gap: 16px; }
.dec-sidebar-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; box-shadow: 0 1px 6px rgba(0,0,0,.05); }
.dec-sidebar-card--promo  { border-top: 3px solid #2271b1; }
.dec-sidebar-card--donate { border-top: 3px solid #f97316; }
.dec-sidebar-card-title   { margin: 0 0 12px; font-size: 14px; font-weight: 700; color: #1e293b; }

/* Promo block */
.dec-sidebar-promo      { display: flex; gap: 12px; align-items: flex-start; }
.dec-sidebar-promo-icon { font-size: 26px; flex-shrink: 0; line-height: 1; color: #2271b1; }
.dec-sidebar-promo-name { display: block; font-size: 13px; font-weight: 700; color: #1e293b; margin-bottom: 6px; }
.dec-sidebar-promo-desc { margin: 0 0 10px; font-size: 12px; color: #64748b; line-height: 1.5; }
.dec-sidebar-promo-link.button { font-size: 12px; }

/* Donate block */
.dec-sidebar-donate-desc { margin: 0 0 12px; font-size: 12px; color: #64748b; line-height: 1.5; }
.dec-sidebar-donate-form { display: flex; justify-content: center; }
        ';
    }
}
