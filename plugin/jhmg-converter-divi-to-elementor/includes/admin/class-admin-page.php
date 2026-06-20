<?php

namespace DiviElementorConverter\Admin;

use DiviElementorConverter\Premium\PremiumManager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminPage {
    const MENU_SLUG           = 'dec-converter';
    const IMPORT_NONCE_NAME   = 'dec_import_nonce';
    const IMPORT_NONCE_ACTION = 'dec_import';
    const ACTIVATE_NONCE_ACTION = 'dec_activate_premium';

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_post' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ] );
    }

    public function enqueue_admin_styles( string $hook ): void {
        if ( strpos( $hook, self::MENU_SLUG ) === false ) {
            return;
        }
        wp_register_style( 'dec-admin', false, [], DEC_PLUGIN_VERSION );
        wp_enqueue_style( 'dec-admin' );
        wp_add_inline_style( 'dec-admin', $this->inline_css() );
    }

    public function register_menu(): void {
        add_management_page(
            __( 'Divi to Elementor Converter', 'jhmg-converter-divi-to-elementor' ),
            __( 'Divi → Elementor', 'jhmg-converter-divi-to-elementor' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'jhmg-converter-divi-to-elementor' ) );
        }

        $action = sanitize_key( $_GET['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( $action === 'result' ) {
            $this->render_result();
        } else {
            $this->render_main();
        }
    }

    public function handle_post(): void {
        $action = sanitize_key( $_POST['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( $action === 'dec_import' ) {
            $this->handle_import();
        }

        if ( $action === 'dec_activate_premium' ) {
            $this->handle_activate_premium();
        }
    }

    private function handle_import(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'jhmg-converter-divi-to-elementor' ) );
        }

        check_admin_referer( self::IMPORT_NONCE_ACTION, self::IMPORT_NONCE_NAME );

        $upload = isset( $_FILES['dec_import_file'] ) && is_array( $_FILES['dec_import_file'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            ? $_FILES['dec_import_file'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : null;

        if ( ! $upload || $upload['error'] !== UPLOAD_ERR_OK ) {
            wp_die( esc_html__( 'No file was uploaded or an upload error occurred.', 'jhmg-converter-divi-to-elementor' ) );
        }

        // Conversion logic will be wired here once parsers are built.
        wp_die( esc_html__( 'Converter not yet implemented — stay tuned!', 'jhmg-converter-divi-to-elementor' ) );
    }

    private function handle_activate_premium(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'jhmg-converter-divi-to-elementor' ) );
        }
        check_admin_referer( self::ACTIVATE_NONCE_ACTION, 'dec_activate_nonce' );
        PremiumManager::activate();
        wp_safe_redirect( add_query_arg(
            [ 'page' => self::MENU_SLUG, 'dec_notice' => 'premium_activated' ],
            admin_url( 'tools.php' )
        ) );
        exit;
    }

    private function render_main(): void {
        $notice = sanitize_key( $_GET['dec_notice'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap dec-wrap">
            <h1><?php esc_html_e( 'Divi to Elementor Converter', 'jhmg-converter-divi-to-elementor' ); ?></h1>

            <?php if ( $notice === 'premium_activated' ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Premium activated.', 'jhmg-converter-divi-to-elementor' ); ?></p>
            </div>
            <?php endif; ?>

            <div class="dec-card">
                <h2><?php esc_html_e( 'Convert a Divi Layout', 'jhmg-converter-divi-to-elementor' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Upload a Divi JSON layout export. The plugin will convert it into Elementor page data and create a draft page ready to edit.', 'jhmg-converter-divi-to-elementor' ); ?>
                </p>

                <form method="post" enctype="multipart/form-data" action="" class="dec-import-form">
                    <?php wp_nonce_field( self::IMPORT_NONCE_ACTION, self::IMPORT_NONCE_NAME ); ?>
                    <input type="hidden" name="action" value="dec_import">

                    <div class="dec-field">
                        <label for="dec_import_file">
                            <strong><?php esc_html_e( 'Divi Export File', 'jhmg-converter-divi-to-elementor' ); ?></strong>
                        </label>
                        <input type="file" id="dec_import_file" name="dec_import_file" accept=".json" required>
                        <p class="description"><?php esc_html_e( 'Upload a Divi layout JSON export.', 'jhmg-converter-divi-to-elementor' ); ?></p>
                    </div>

                    <button type="submit" class="button button-primary">
                        <?php esc_html_e( 'Convert to Elementor', 'jhmg-converter-divi-to-elementor' ); ?>
                    </button>
                </form>
            </div>

            <?php if ( ! PremiumManager::is_active() ) : ?>
            <div class="dec-card dec-card--premium">
                <h2><?php esc_html_e( 'Premium', 'jhmg-converter-divi-to-elementor' ); ?></h2>
                <p><?php esc_html_e( 'Unlock bulk conversion, global styles migration, and more.', 'jhmg-converter-divi-to-elementor' ); ?></p>
                <form method="post" action="">
                    <?php wp_nonce_field( self::ACTIVATE_NONCE_ACTION, 'dec_activate_nonce' ); ?>
                    <input type="hidden" name="action" value="dec_activate_premium">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e( 'Upgrade to Premium', 'jhmg-converter-divi-to-elementor' ); ?>
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_result(): void {
        ?>
        <div class="wrap dec-wrap">
            <h1><?php esc_html_e( 'Conversion Result', 'jhmg-converter-divi-to-elementor' ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ); ?>" class="button">
                &larr; <?php esc_html_e( 'Back to converter', 'jhmg-converter-divi-to-elementor' ); ?>
            </a>
        </div>
        <?php
    }

    private function inline_css(): string {
        return '
            .dec-wrap { max-width: 900px; }
            .dec-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px 24px; margin: 20px 0; }
            .dec-card--premium { border-color: #9c27b0; }
            .dec-field { margin-bottom: 16px; }
            .dec-field label { display: block; margin-bottom: 6px; }
            .dec-import-form .button { margin-top: 8px; }
        ';
    }
}
