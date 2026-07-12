<?php

namespace DiviElementorConverter\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin {
    private static ?Plugin $instance = null;

    public static function instance(): Plugin {
        return self::$instance ??= new self();
    }

    public function init(): void {
        // Priority 20: after the free plugin's own plugins_loaded hook (10).
        add_action( 'plugins_loaded', [ $this, 'register_hooks' ], 20 );
    }

    public function register_hooks(): void {
        if ( ! class_exists( \DiviElementorConverter\Plugin::class ) ) {
            add_action( 'admin_notices', [ $this, 'render_missing_free_notice' ] );
            return;
        }

        add_filter( 'jhmgcofo_pro_active', '__return_true' );

        // TODO: Wire Pro features here in later tasks (batch conversion, WooCommerce widget mapping, Divi Theme Builder import, license client, etc.).
    }

    public function render_missing_free_notice(): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__( 'JHMG Converter Pro requires the free "JHMG Converter For Divi to Elementor" plugin. Please install and activate it.', 'jhmg-converter-divi-to-elementor-pro' );
        echo '</p></div>';
    }
}
