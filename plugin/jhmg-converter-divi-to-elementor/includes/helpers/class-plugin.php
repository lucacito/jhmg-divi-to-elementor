<?php

namespace DiviElementorConverter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin {
    private static ?Plugin $instance = null;

    public static function instance(): Plugin {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init(): void {
        add_action( 'plugins_loaded', [ $this, 'register_hooks' ] );
    }

    public function register_hooks(): void {
        ( new \DiviElementorConverter\Shortcodes() )->init();

        if ( is_admin() ) {
            ( new \DiviElementorConverter\Admin\AdminPage() )->init();
            add_action( 'admin_init', [ $this, 'maybe_redirect_on_activation' ] );
        }
    }

    public function maybe_redirect_on_activation(): void {
        if ( ! get_transient( 'jhmgcofo_activation_redirect' ) ) {
            return;
        }
        delete_transient( 'jhmgcofo_activation_redirect' );

        // Skip during bulk activation or network admin.
        if ( isset( $_GET['activate-multi'] ) || is_network_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        wp_safe_redirect( admin_url( 'tools.php?page=' . \DiviElementorConverter\Admin\AdminPage::MENU_SLUG ) );
        exit;
    }
}
