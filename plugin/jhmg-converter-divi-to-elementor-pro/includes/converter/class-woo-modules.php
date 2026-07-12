<?php

namespace DiviElementorConverter\Pro\Converter;

use DiviElementorConverter\Converter\DiviNode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps Divi WooCommerce modules (`wc_*`) to Elementor Pro's WooCommerce widgets.
 *
 * Hooked onto the free plugin's `jhmgcofo_convert_module` filter (see
 * Plugin::register_hooks()), which ElementorBuilder::convert_module() applies
 * BEFORE its own WIDGET_MAP lookup. When this callback returns an array,
 * convert_module() uses it as the widget verbatim — none of ElementorBuilder's
 * later spacing/border/box-shadow/etc. mixing runs — so every value returned
 * here must already be a complete Elementor widget array.
 *
 * Mirrors free ElementorBuilder::WIDGET_MAP's wc_* entries
 * (class-elementor-builder.php:63-74) and their widget_settings() cases
 * (~:882-895), plus its uid() id-generation helper (~:1738-1741).
 */
class WooModules {

	/** Divi wc_* module tag → Elementor Pro WooCommerce widgetType. */
	private const WIDGET_MAP = [
		'wc_title'            => 'woocommerce-product-title',
		'wc_images'           => 'woocommerce-product-images',
		'wc_price'            => 'woocommerce-product-price',
		'wc_description'      => 'woocommerce-product-short-description',
		'wc_add_to_cart'      => 'woocommerce-product-add-to-cart',
		'wc_rating'           => 'woocommerce-product-rating',
		// Elementor Pro has no standalone reviews widget; reviews render inside the Product Data Tabs widget.
		'wc_reviews'          => 'woocommerce-product-data-tabs',
		'wc_breadcrumb'       => 'woocommerce-breadcrumb',
		'wc_additional_info'  => 'woocommerce-product-additional-information',
		'wc_related_products' => 'woocommerce-product-related',
		'wc_cart_notice'      => 'html',
	];

	/**
	 * `jhmgcofo_convert_module` filter callback.
	 *
	 * @param  mixed    $value Current filtered value (null unless another callback already matched).
	 * @param  DiviNode $node  The Divi module node being converted.
	 * @return mixed           A full Elementor widget array for wc_* tags, otherwise $value unchanged.
	 */
	public function maybe_convert( $value, DiviNode $node ) {
		$widget_type = self::WIDGET_MAP[ $node->tag ] ?? null;

		if ( $widget_type === null ) {
			return $value;
		}

		return [
			'id'         => $this->uid(),
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => $this->widget_settings( $node->tag ),
			'elements'   => [],
		];
	}

	/** Mirrors free ElementorBuilder::widget_settings() wc_* cases. */
	private function widget_settings( string $tag ): array {
		if ( $tag === 'wc_cart_notice' ) {
			return [ 'html' => '[woocommerce_cart]' ];
		}

		// WooCommerce widgets render from WC product context; no extra settings needed.
		return [];
	}

	/** Generate a 7-character lowercase hex ID (matches Elementor's format; same
	 *  algorithm as free ElementorBuilder::uid()). */
	private function uid(): string {
		return substr( md5( uniqid( '', true ) ), 0, 7 );
	}
}
