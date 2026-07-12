<?php

namespace DiviElementorConverter\Pro\Converter;

use DiviElementorConverter\Converter\DiviParser;
use DiviElementorConverter\Converter\ElementorBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports a Divi Theme Builder JSON export (et_theme_builder context) as
 * Elementor header/footer/body posts.
 *
 * Ported from the free plugin's dead Converter::convert_theme_builder()
 * (includes/converter/class-converter.php ~:32-73) — that method is
 * unreachable from any free UI; Pro is now its only caller.
 */
class ThemeBuilderImporter {
	private DiviParser       $parser;
	private ElementorBuilder $builder;

	public function __construct() {
		$this->parser  = new DiviParser();
		$this->builder = new ElementorBuilder();
	}

	/**
	 * @param  string $json Raw contents of a Divi Theme Builder export JSON file.
	 * @return array<int, array{role: string, id: string, post_id: int}> One entry per converted layout.
	 * @throws \InvalidArgumentException If the JSON is malformed or not et_theme_builder.
	 * @throws \RuntimeException         If a WP post insert fails.
	 */
	public function import( string $json ): array {
		$layouts = $this->parser->parse_theme_builder_layouts( $json );
		$results = [];

		foreach ( $layouts as $layout ) {
			$elementor_data = $this->builder->build( $layout['nodes'] );

			if ( empty( $elementor_data ) ) {
				continue;
			}

			$role = $layout['role'];

			$post_id = wp_insert_post(
				[
					'post_title'  => ucfirst( $role ) . ' (converted from Divi – ' . current_time( 'Y-m-d H:i' ) . ')',
					'post_status' => 'draft',
					'post_type'   => in_array( $role, [ 'header', 'footer' ], true ) ? 'elementor_library' : 'page',
				],
				true
			);

			if ( is_wp_error( $post_id ) ) {
				throw new \RuntimeException( esc_html( $post_id->get_error_message() ) );
			}

			update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elementor_data ) ) );
			update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
			update_post_meta( $post_id, '_elementor_version', '3.21.0' );
			// Use wp-page for all roles in the WP post meta — "header"/"footer" as
			// template types require Elementor Pro's Theme Builder to be registered
			// on the destination site.
			update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );

			$results[] = [
				'role'    => $role,
				'id'      => $layout['id'],
				'post_id' => $post_id,
			];
		}

		return $results;
	}
}
