<?php

namespace DiviElementorConverter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers frontend shortcodes produced by the converter.
 *
 * [jhmg_nav_menu id="2"] renders a WordPress navigation menu by menu ID.
 * Elementor's free "Shortcode" widget executes this on the frontend, so no
 * Elementor Pro or HFE nav-menu widget is needed.
 */
class Shortcodes {

	public function init(): void {
		add_shortcode( 'jhmg_nav_menu', [ $this, 'nav_menu' ] );
	}

	/**
	 * @param array<string,string> $atts
	 */
	public function nav_menu( array $atts ): string {
		$atts = shortcode_atts(
			[
				'id'    => 0,
				'class' => 'jhmg-nav-menu',
			],
			$atts,
			'jhmg_nav_menu'
		);

		$output = wp_nav_menu(
			[
				'menu'       => (int) $atts['id'],
				'menu_class' => $atts['class'],
				'echo'       => false,
			]
		);

		return $output !== false ? $output : '';
	}
}
