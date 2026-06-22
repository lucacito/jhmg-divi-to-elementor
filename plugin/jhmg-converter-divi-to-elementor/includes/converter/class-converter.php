<?php

namespace DiviElementorConverter\Converter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates the full Divi → Elementor conversion and creates a WP draft page.
 */
class Converter {
	private DiviParser       $parser;
	private ElementorBuilder $builder;

	public function __construct() {
		$this->parser  = new DiviParser();
		$this->builder = new ElementorBuilder();
	}

	/**
	 * Convert an et_theme_builder JSON export into Elementor header/footer/body posts.
	 *
	 * Headers and footers are created as elementor_library posts with the
	 * appropriate _elementor_template_type meta. Body layouts become draft pages.
	 *
	 * @param  string $json Raw contents of a Divi Theme Builder export JSON file.
	 * @return array<int, array{role: string, id: string, post_id: int}> One entry per converted layout.
	 * @throws \InvalidArgumentException If the JSON is malformed or not et_theme_builder.
	 * @throws \RuntimeException         If a WP post insert fails.
	 */
	public function convert_theme_builder( string $json ): array {
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
		// template types require Elementor Pro's Theme Builder to be registered.
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
	 * Convert a Divi JSON export string into an Elementor draft page.
	 *
	 * @param  string $json  Raw contents of a Divi export JSON file.
	 * @param  string $title Optional page title; defaults to a timestamped name.
	 * @return int           ID of the newly created draft page.
	 * @throws \InvalidArgumentException If the JSON is malformed.
	 * @throws \RuntimeException         If conversion produces no data or WP insert fails.
	 */
	public function convert( string $json, string $title = '' ): int {
		$divi_nodes     = $this->parser->parse_json( $json );
		$elementor_data = $this->builder->build( $divi_nodes );

		if ( empty( $elementor_data ) ) {
			throw new \RuntimeException(
				esc_html__( 'No Elementor sections were generated. The file may not contain a valid Divi layout.', 'jhmg-converter-for-divi-to-elementor' )
			);
		}

		$post_id = wp_insert_post(
			[
				'post_title'   => $title !== '' ? $title : 'Converted from Divi – ' . current_time( 'Y-m-d H:i' ),
				'post_status'  => 'draft',
				'post_type'    => 'page',
				'post_content' => '',
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( esc_html( $post_id->get_error_message() ) );
		}

		// Elementor expects the data double-slashed before storing.
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elementor_data ) ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_version', '3.21.0' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );

		return $post_id;
	}
}
