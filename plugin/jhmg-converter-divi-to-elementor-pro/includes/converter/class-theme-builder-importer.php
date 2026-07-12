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
 *
 * parse_theme_builder_layouts() below is ALSO a port: it used to live on
 * free's DiviParser (class-divi-parser.php), but free's Task 6 trim deletes
 * it there (free now throws on et_theme_builder JSON — see
 * DiviParser::parse_json()/parse_layouts()). This importer was the only
 * caller, so the method moved here rather than being deleted outright. It
 * still uses free's DiviParser::parse_shortcodes() (unaffected by the trim)
 * for the actual shortcode → DiviNode tree parsing.
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
		$layouts = $this->parse_theme_builder_layouts( $json );
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

	/**
	 * Parse an et_theme_builder export and return per-layout data with role info.
	 *
	 * Each entry in the returned array describes one layout (header, footer, body,
	 * or unknown) and the DiviNode tree parsed from its shortcode content.
	 *
	 * Ported verbatim from free's former DiviParser::parse_theme_builder_layouts()
	 * — see the class docblock above.
	 *
	 * @return array<int, array{role: string, id: string, nodes: \DiviElementorConverter\Converter\DiviNode[]}>
	 * @throws \InvalidArgumentException on invalid JSON or wrong context.
	 */
	public function parse_theme_builder_layouts( string $json ): array {
		$data = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new \InvalidArgumentException( 'Invalid JSON: ' . esc_html( json_last_error_msg() ) );
		}

		if ( ( $data['context'] ?? '' ) !== 'et_theme_builder' ) {
			throw new \InvalidArgumentException(
				'JSON context is not et_theme_builder (got: ' . esc_html( $data['context'] ?? 'none' ) . ')'
			);
		}

		// Build layout_id → role map from the templates list.
		$role_map = [];
		foreach ( $data['templates'] ?? [] as $template ) {
			foreach ( $template['layouts'] ?? [] as $role => $info ) {
				$id = (string) ( $info['id'] ?? 0 );
				if ( $id !== '0' && ! isset( $role_map[ $id ] ) ) {
					$role_map[ $id ] = $role; // 'header', 'footer', 'body'
				}
			}
		}

		$results = [];
		$seen    = [];
		foreach ( $data['layouts'] ?? [] as $id => $layout ) {
			$id = (string) $id;
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;

			$nodes = [];
			foreach ( $layout['data'] ?? [] as $shortcode_str ) {
				if ( is_string( $shortcode_str ) ) {
					$nodes = array_merge( $nodes, $this->parser->parse_shortcodes( $shortcode_str ) );
				}
			}

			$results[] = [
				'role'  => $role_map[ $id ] ?? 'unknown',
				'id'    => $id,
				'nodes' => $nodes,
			];
		}

		return $results;
	}
}
