<?php

namespace DiviElementorConverter\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Autoloader {
    public static function register(): void {
        spl_autoload_register( [ self::class, 'autoload' ] );
    }

    public static function autoload( string $class ): void {
        $prefix = 'DiviElementorConverter\\Pro\\';

        if ( str_starts_with( $class, $prefix ) ) {
            $relative_class = ltrim( substr( $class, strlen( $prefix ) ), '\\' );
            $parts = explode( '\\', $relative_class );
            $class_name = array_pop( $parts );
            $directory = JHMGCOFOP_PLUGIN_DIR . 'includes/';

            if ( ! empty( $parts ) ) {
                $directory .= implode( '/', array_map( 'strtolower', $parts ) ) . '/';
            }

            $file_name = 'class-' . strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $class_name ) ) . '.php';
            $path = $directory . $file_name;

            if ( ! file_exists( $path ) && empty( $parts ) ) {
                $path = JHMGCOFOP_PLUGIN_DIR . 'includes/helpers/' . $file_name;
            }

            if ( file_exists( $path ) ) {
                require_once $path;
            }
        }
    }
}

Autoloader::register();
