#!/usr/bin/env php
<?php
/**
 * CLI converter: Divi JSON export → Elementor JSON file(s).
 *
 * Usage:
 *   php bin/convert.php <input.json> [output-dir]
 *
 * Output dir defaults to ./output. One JSON file is written per layout:
 *   - et_builder / et_builder_layouts  → output/page-<id>.json (or page.json)
 *   - et_theme_builder                 → output/header-<id>.json, footer-<id>.json, body-<id>.json …
 *
 * The output JSON can be imported into Elementor via its built-in template importer,
 * or applied to a page via WP-CLI:
 *   wp post meta update <post-id> _elementor_data "$(cat output/header-987510830.json)"
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Bootstrap: load classes without WordPress.
// ---------------------------------------------------------------------------

$bootstrap = __DIR__ . '/../tests/bootstrap.php';
if ( ! file_exists( $bootstrap ) ) {
	fwrite( STDERR, "Run `composer install` first.\n" );
	exit( 1 );
}
require $bootstrap;

// Stub the WordPress functions used only by Converter (not needed for CLI output).
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post(): int { return 0; }
	function update_post_meta(): void {}
	function wp_slash( string $s ): string { return $s; }
	function wp_json_encode( $data ): string { return (string) json_encode( $data ); }
	function current_time(): string { return date( 'Y-m-d H:i' ); }
	function is_wp_error(): bool { return false; }
	function __( string $text ): string { return $text; }
}

use DiviElementorConverter\Converter\DiviParser;
use DiviElementorConverter\Converter\ElementorBuilder;

// ---------------------------------------------------------------------------
// Argument parsing.
// ---------------------------------------------------------------------------

$args = array_slice( $argv, 1 );
if ( count( $args ) < 1 || in_array( $args[0], [ '-h', '--help' ], true ) ) {
	echo "Usage: php bin/convert.php <input.json> [output-dir]\n";
	echo "  output-dir defaults to ./output\n";
	exit( 0 );
}

$input_file = $args[0];
$output_dir = $args[1] ?? __DIR__ . '/../output';

if ( ! file_exists( $input_file ) ) {
	fwrite( STDERR, "Input file not found: {$input_file}\n" );
	exit( 1 );
}

if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0755, true ) ) {
	fwrite( STDERR, "Could not create output directory: {$output_dir}\n" );
	exit( 1 );
}

// ---------------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------------

function elementor_envelope( string $title, array $content ): array {
	return [
		'version'       => '0.4',
		'title'         => $title,
		'type'          => 'page',
		'content'       => $content,
		'page_settings' => (object) [],
	];
}

// ---------------------------------------------------------------------------
// Parse + build.
// ---------------------------------------------------------------------------

$raw_content = file_get_contents( $input_file );
$parser      = new DiviParser();
$builder     = new ElementorBuilder();
$written     = [];

// .txt files are treated as raw Divi shortcode strings (not JSON exports).
if ( strtolower( pathinfo( $input_file, PATHINFO_EXTENSION ) ) === 'txt' ) {
	echo "Context: raw shortcodes\n";

	$nodes          = $parser->parse_shortcodes( $raw_content );
	$elementor_data = $builder->build( $nodes );

	if ( empty( $elementor_data ) ) {
		fwrite( STDERR, "Conversion produced no output. Check that the file contains valid Divi shortcodes.\n" );
		exit( 1 );
	}

	$base        = pathinfo( $input_file, PATHINFO_FILENAME );
	$output_path = $output_dir . '/' . $base . '-elementor.json';

	$output = elementor_envelope( $base, $elementor_data );
	file_put_contents( $output_path, json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	$written[] = $output_path;

	echo "  → " . basename( $output_path ) . " (" . count( $elementor_data ) . " sections)\n";
	echo "\nDone. 1 file written to: {$output_dir}/\n";
	exit( 0 );
}

$json    = $raw_content;
$decoded = json_decode( $json, true );

if ( json_last_error() !== JSON_ERROR_NONE ) {
	fwrite( STDERR, "Invalid JSON: " . json_last_error_msg() . "\n" );
	exit( 1 );
}

$context = $decoded['context'] ?? '';

echo "Context: {$context}\n";

if ( $context === 'et_theme_builder' ) {
	// One file per layout (header, footer, body, unknown).
	$layouts = $parser->parse_theme_builder_layouts( $json );

	foreach ( $layouts as $entry ) {
		$elementor_data = $builder->build( $entry['nodes'] );

		if ( empty( $elementor_data ) ) {
			echo "  [{$entry['id']}] role={$entry['role']} — skipped (no output)\n";
			continue;
		}

		$role     = $entry['role'];
		$filename = "{$role}-{$entry['id']}.json";
		$output_path = $output_dir . '/' . $filename;

		// Wrap in Elementor's template import envelope.
		// We use type=page for all roles: the standard importer (free + Pro) always
		// accepts it, whereas "header"/"footer" are only valid when Elementor Pro's
		// Theme Builder module is active. The content is structurally identical; the
		// type field only affects which library tab the template appears under.
		$output = [
			'version'       => '0.4',
			'title'         => ucfirst( $role ) . ' (converted from Divi)',
			'type'          => 'page',
			'content'       => $elementor_data,
			'page_settings' => (object) [],
		];

		file_put_contents( $output_path, json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$written[] = $output_path;

		$section_count = count( $elementor_data );
		echo "  [{$entry['id']}] role={$role} → {$filename} ({$section_count} sections)\n";
	}
} else {
	// et_builder or et_builder_layouts: single combined output.
	$nodes          = $parser->parse_json( $json );
	$elementor_data = $builder->build( $nodes );

	if ( empty( $elementor_data ) ) {
		fwrite( STDERR, "Conversion produced no output. Check that the file contains a valid Divi layout.\n" );
		exit( 1 );
	}

	$base        = pathinfo( $input_file, PATHINFO_FILENAME );
	$output_path = $output_dir . '/' . $base . '-elementor.json';

	$output = elementor_envelope( $base, $elementor_data );
	file_put_contents( $output_path, json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	$written[] = $output_path;

	echo "  → " . basename( $output_path ) . " (" . count( $elementor_data ) . " sections)\n";
}

echo "\nDone. " . count( $written ) . " file(s) written to: {$output_dir}/\n";
