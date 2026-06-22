<?php

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Satisfy the guard at the top of every plugin file.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/fake-abspath/' );
}

// Custom autoloader that mirrors the plugin's class-*.php / strtolower-subdir convention.
spl_autoload_register( function ( string $class ): void {
	$prefix = 'DiviElementorConverter\\';

	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}

	$relative   = ltrim( substr( $class, strlen( $prefix ) ), '\\' );
	$parts      = explode( '\\', $relative );
	$class_name = array_pop( $parts );
	$base       = dirname( __DIR__ ) . '/plugin/jhmg-converter-divi-to-elementor/includes/';
	$dir        = $base . ( $parts ? implode( '/', array_map( 'strtolower', $parts ) ) . '/' : '' );
	$file       = 'class-' . strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $class_name ) ) . '.php';
	$path       = $dir . $file;

	if ( ! file_exists( $path ) && empty( $parts ) ) {
		$path = $base . 'helpers/' . $file;
	}

	if ( file_exists( $path ) ) {
		require_once $path;
	}
} );
