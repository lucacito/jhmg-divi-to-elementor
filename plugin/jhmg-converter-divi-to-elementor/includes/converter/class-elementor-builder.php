<?php

namespace DiviElementorConverter\Converter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts a DiviNode tree into Elementor _elementor_data JSON structure.
 *
 * Mapping strategy:
 *   et_pb_section  → one Elementor section per et_pb_row it contains
 *   et_pb_row      → Elementor section (top-level or inner)
 *   et_pb_column   → Elementor column  (width derived from Divi `type` attr)
 *   et_pb_*        → Elementor widget  (see WIDGET_MAP)
 */
class ElementorBuilder {

	/** Divi module tag → Elementor widgetType */
	private const WIDGET_MAP = [
		'text'                    => 'text-editor',
		'heading'                 => 'heading',
		'button'                  => 'button',
		'image'                   => 'image',
		'video'                   => 'video',
		'code'                    => 'html',
		'divider'                 => 'divider',
		'spacer'                  => 'spacer',
		'cta'                     => 'text-editor',
		'blurb'                   => 'icon-box',
		'testimonial'             => 'testimonial',
		'accordion'               => 'accordion',
		'toggle'                  => 'accordion',
		'tabs'                    => 'tabs',
		'gallery'                 => 'image-gallery',
		'number_counter'          => 'counter',
		'circle_counter'          => 'progress',
		'counters'                => 'counter',
		'countdown_timer'         => 'countdown',
		'icon'                    => 'icon',
		'login'                   => 'login',
		'search'                  => 'search-form',
		'sidebar'                 => 'sidebar',
		'blog'                    => 'posts',
		'contact_form'            => 'shortcode',
		'social_media_follow'     => 'social-icons',
		'map'                     => 'google-maps',
		'audio'                   => 'soundcloud',
		// slider is handled as a standalone section — see build_slider_section().
		'post_nav'                => 'post-navigation',
		'post_title'              => 'theme-post-title',
		'post_content'            => 'theme-post-content',
		'comments'                => 'post-comments',
		'team_member'             => 'icon-box',
		'pricing_tables'          => 'html',
		'portfolio'               => 'portfolio',
		'filterable_portfolio'    => 'portfolio',
		'menu'                    => 'ekit-nav-menu',
		'fullwidth_menu'          => 'ekit-nav-menu',
		'signup'                  => 'html',
		'video_slider'            => 'video',
		// WooCommerce modules map to Elementor Pro WooCommerce widgets.
		'wc_title'                => 'woocommerce-product-title',
		'wc_images'               => 'woocommerce-product-images',
		'wc_price'                => 'woocommerce-product-price',
		'wc_description'          => 'woocommerce-product-short-description',
		'wc_add_to_cart'          => 'woocommerce-product-add-to-cart',
		'wc_rating'               => 'woocommerce-product-rating',
		'wc_reviews'              => 'woocommerce-product-comments',
		'wc_breadcrumb'           => 'woocommerce-breadcrumb',
		'wc_additional_info'      => 'woocommerce-product-additional-info',
		'wc_related_products'     => 'woocommerce-product-related',
		'wc_cart_notice'          => 'html',
	];

	/**
	 * FontAwesome 5 unicode codepoint (hex, no prefix) → FA class suffix (no "fa-" prefix).
	 * Covers the most common icons found in Divi layouts; unrecognised codepoints fall
	 * back to an empty icon value so the blurb still renders its title/description.
	 */
	private const FA_ICON_MAP = [
		'f000' => 'glass',          'f002' => 'search',
		'f004' => 'heart',          'f005' => 'star',
		'f007' => 'user',           'f00c' => 'check',
		'f00d' => 'times',          'f013' => 'cog',
		'f015' => 'home',           'f019' => 'download',
		'f01a' => 'upload',         'f023' => 'lock',
		'f025' => 'headphones',     'f03a' => 'list',
		'f041' => 'map-marker',     'f044' => 'edit',
		'f04b' => 'play',           'f053' => 'chevron-left',
		'f054' => 'chevron-right',  'f057' => 'times-circle',
		'f058' => 'check-circle',   'f059' => 'question-circle',
		'f05a' => 'info-circle',    'f060' => 'arrow-left',
		'f061' => 'arrow-right',    'f062' => 'arrow-up',
		'f063' => 'arrow-down',     'f06a' => 'exclamation-circle',
		'f071' => 'exclamation-triangle',
		'f077' => 'chevron-up',     'f078' => 'chevron-down',
		'f080' => 'bar-chart',      'f086' => 'comments',
		'f091' => 'trophy',         'f095' => 'phone',
		'f099' => 'twitter',        'f09a' => 'facebook',
		'f09b' => 'github',         'f0c0' => 'users',
		'f0c9' => 'bars',           'f0e0' => 'envelope',
		'f0e1' => 'linkedin',       'f0f3' => 'bell',
		'f11b' => 'gamepad',        'f14a' => 'check-square',
		'f16d' => 'instagram',      'f167' => 'youtube',
		'f1da' => 'history',        'f1f8' => 'trash',
		'f3c5' => 'map-marker-alt', 'f48b' => 'gem',
		'f0b1' => 'briefcase',      'f0b0' => 'filter',
		'f19c' => 'university',     'f559' => 'file-contract',
	];

	/** Divi column type string → integer percentage width for Elementor. */
	private const COLUMN_WIDTHS = [
		'4_4' => 100,
		'3_4' => 75,
		'2_3' => 67,
		'2_4' => 50,
		'1_2' => 50,
		'1_3' => 33,
		'1_4' => 25,
		'1_6' => 17,
		'5_6' => 83,
		'1_5' => 20,
		'2_5' => 40,
		'3_5' => 60,
		'4_5' => 80,
	];

	// -----------------------------------------------------------------------
	// Public API
	// -----------------------------------------------------------------------

	/**
	 * Build Elementor JSON array from a list of top-level DiviNodes.
	 *
	 * @param  DiviNode[] $nodes
	 * @return array<int, array<string, mixed>>  Ready to pass to json_encode.
	 */
	public function build( array $nodes ): array {
		$sections = [];
		foreach ( $nodes as $node ) {
			if ( $node->tag === 'section' ) {
				foreach ( $this->convert_section( $node ) as $s ) {
					$sections[] = $s;
				}
			}
		}
		return $sections;
	}

	// -----------------------------------------------------------------------
	// Structure converters
	// -----------------------------------------------------------------------

	/** One Divi section → N Elementor sections (one per et_pb_row, plus one per slider). */
	private function convert_section( DiviNode $section ): array {
		$result       = [];
		$content_rows = []; // rows that produce widgets (after slider-hoisting)

		foreach ( $section->children as $child ) {
			if ( ! in_array( $child->tag, [ 'row', 'row_inner' ], true ) ) {
				continue;
			}
			// Hoist slider modules to their own sections before the normal row.
			foreach ( $child->children as $col ) {
				if ( ! in_array( $col->tag, [ 'column', 'column_inner' ], true ) ) {
					continue;
				}
				foreach ( $col->children as $module ) {
					if ( $module->tag === 'slider' ) {
						$result[] = $this->build_slider_section( $module, $section );
					}
				}
			}
			$columns = $this->convert_columns( $child );
			foreach ( $columns as $col ) {
				if ( ! empty( $col['elements'] ) ) {
					$content_rows[] = [ 'row' => $child, 'columns' => $columns ];
					break;
				}
			}
		}

		if ( empty( $content_rows ) ) {
			return $result;
		}

		if ( count( $content_rows ) === 1 ) {
			// Single-row: flat Elementor section (original behaviour).
			$section_id = $this->uid();
			$columns    = $content_rows[0]['columns'];
			$result[] = [
				'id'       => $section_id,
				'elType'   => 'section',
				'isInner'  => false,
				'settings' => $this->section_settings( $section, $content_rows[0]['row'], true ),
				'elements' => $columns,
			];
			return $result;
		}

		// Multi-row: one outer section carries the Divi-section background/margin; each row
		// becomes an inner section so the background does not repeat per-row.
		$outer_settings = $this->outer_section_settings( $section );
		$inner_sections = [];
		foreach ( $content_rows as $entry ) {
			$section_id = $this->uid();
			$columns    = $entry['columns'];
			$inner_sections[] = [
				'id'       => $section_id,
				'elType'   => 'section',
				'isInner'  => true,
				'settings' => $this->inner_section_settings( $entry['row'] ),
				'elements' => $columns,
			];
		}
		$result[] = [
			'id'       => $this->uid(),
			'elType'   => 'section',
			'isInner'  => false,
			'settings' => $outer_settings,
			'elements' => [
				[
					'id'       => $this->uid(),
					'elType'   => 'column',
					'settings' => [ '_column_size' => 100, '_inline_size' => null ],
					'elements' => $inner_sections,
				],
			],
		];
		return $result;
	}

	/** Settings for the outer Elementor section that represents a multi-row Divi section. */
	private function outer_section_settings( DiviNode $section ): array {
		$settings = [];
		$this->apply_background( $settings, $section );
		$this->apply_gradient( $settings, $section );
		$this->apply_spacing( $settings, $section, false, true ); // margin only
		$this->apply_shape_dividers( $settings, $section );
		$this->apply_min_height( $settings, $section );
		$this->apply_box_shadow( $settings, $section );
		$this->apply_responsive_visibility( $settings, $section );
		$this->apply_identity( $settings, $section );
		$this->apply_custom_css( $settings, $section );
		// Apply Divi section default top/bottom padding (4%) when none is explicit.
		if ( ! isset( $settings['padding'] ) ) {
			$settings['padding'] = [
				'unit'     => '%',
				'top'      => '4',
				'right'    => '0',
				'bottom'   => '4',
				'left'     => '0',
				'isLinked' => false,
			];
		}
		return $settings;
	}

	/** Settings for an inner Elementor section that represents a single Divi row. */
	private function inner_section_settings( DiviNode $row ): array {
		$settings = [];
		$this->apply_background( $settings, $row );
		$this->apply_gradient( $settings, $row );
		$this->apply_spacing( $settings, $row );
		$this->apply_min_height( $settings, $row );
		$settings['gap'] = $this->map_gap(
			$row->attr( 'use_custom_gutter' ) === 'on' ? $row->attr( 'gutter_width' ) : '3'
		);
		$link_url = $row->attr( 'link_option_url' );
		if ( $link_url !== '' ) {
			$settings['link'] = [
				'url'         => $this->sanitize_dynamic_value( $link_url ),
				'is_external' => $row->attr( 'url_new_window' ) === 'blank',
			];
		}
		$this->apply_box_shadow( $settings, $row );
		$this->apply_responsive_visibility( $settings, $row );
		$this->apply_identity( $settings, $row );
		$this->apply_custom_css( $settings, $row );
		// Apply Divi row default top/bottom padding (27px) when none is explicit.
		if ( ! isset( $settings['padding'] ) && $row->attr( 'custom_padding' ) === '' ) {
			$settings['padding'] = [
				'unit'     => 'px',
				'top'      => '27',
				'right'    => '0',
				'bottom'   => '27',
				'left'     => '0',
				'isLinked' => false,
			];
		}
		return $settings;
	}

	/** One et_pb_row → array of Elementor column elements. */
	private function convert_columns( DiviNode $row ): array {
		$columns = [];
		foreach ( $row->children as $child ) {
			if ( ! in_array( $child->tag, [ 'column', 'column_inner' ], true ) ) {
				continue;
			}
			$col_id = $this->uid();

			$col_settings = [
				'_column_size' => self::COLUMN_WIDTHS[ $child->attr( 'type', '4_4' ) ] ?? 100,
				'_inline_size' => null,
			];
			$this->apply_background( $col_settings, $child );
			$this->apply_gradient( $col_settings, $child );
			$this->apply_spacing( $col_settings, $child );
			$this->apply_min_height( $col_settings, $child );
			$this->apply_border_radius( $col_settings, $child );
			$this->apply_box_shadow( $col_settings, $child );
			$this->apply_responsive_visibility( $col_settings, $child );
			$this->apply_identity( $col_settings, $child );
			$this->apply_custom_css( $col_settings, $child, ' .elementor-widget-wrap' );

			$widgets = $this->convert_widgets( $child );

			$columns[] = [
				'id'       => $col_id,
				'elType'   => 'column',
				'settings' => $col_settings,
				'elements' => $widgets,
			];
		}
		return $columns;
	}

	/** One et_pb_column → array of Elementor widget elements. */
	private function convert_widgets( DiviNode $col ): array {
		$widgets = [];
		foreach ( $col->children as $module ) {
			$widget = $this->convert_module( $module );
			if ( $widget !== null ) {
				$widgets[] = $widget;
			}
		}
		return $widgets;
	}

	/** Map a single Divi module to an Elementor widget array (or null to skip). */
	private function convert_module( DiviNode $node ): ?array {
		// Structural tags that should never appear directly under a column.
		if ( in_array( $node->tag, [ 'section', 'row', 'row_inner', 'column', 'column_inner' ], true ) ) {
			return null;
		}
		// Sliders are hoisted to standalone sections by convert_section(); skip here.
		if ( $node->tag === 'slider' ) {
			return null;
		}

		$widget_type = self::WIDGET_MAP[ $node->tag ] ?? null;

		$widget_id = $this->uid();
		if ( $widget_type !== null ) {
			$settings = $this->widget_settings( $node->tag, $node, $widget_id );
		} else {
			// Unknown module: preserve raw HTML/content so nothing is silently dropped.
			$widget_type = 'html';
			$content     = $node->content;
			if ( $content === '' ) {
				$content = sprintf( '<!-- Unconverted Divi module: %s -->', htmlspecialchars( $node->tag, ENT_QUOTES, 'UTF-8' ) );
			}
			$settings = [ 'html' => $this->close_html_comments( $content ) ];
		}

		$this->apply_spacing( $settings, $node );
		// For button widgets, apply_spacing sets outer wrapper `padding` from custom_padding,
		// but Divi's custom_padding on buttons means inner button padding (already mapped to
		// button_padding in widget_settings). Remove the duplicate outer wrapper padding.
		if ( $node->tag === 'button' && isset( $settings['padding'] ) ) {
			unset( $settings['padding'] );
		}
		$this->apply_border_radius( $settings, $node );
		$this->apply_box_shadow( $settings, $node, '_box_shadow' );
		$this->apply_widget_background( $settings, $node );
		$this->apply_responsive_visibility( $settings, $node );
		$this->apply_identity( $settings, $node );
		$this->apply_advanced_position( $settings, $node );
		$this->apply_custom_css( $settings, $node );

		return [
			'id'         => $widget_id,
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => $settings,
			'elements'   => [],
		];
	}

	// -----------------------------------------------------------------------
	// Settings mappers
	// -----------------------------------------------------------------------

	/**
	 * Build Elementor section settings from a Divi section + its child row.
	 * Row-level values take precedence over section-level ones.
	 * Section-level spacing and shape dividers are only applied on the first row
	 * to avoid duplicating outer padding/margin across every Elementor section
	 * produced from a multi-row Divi section.
	 */
	private function section_settings( DiviNode $section, ?DiviNode $row = null, bool $is_first_row = true ): array {
		$settings = [];

		// Background: section base, then row overwrites.
		$this->apply_background( $settings, $section );
		if ( $row !== null ) {
			$this->apply_background( $settings, $row );
		}
		// Gradient: same precedence rule.
		$this->apply_gradient( $settings, $section );
		if ( $row !== null ) {
			$this->apply_gradient( $settings, $row );
		}
		// Section margin (outer spacing) → first row only.
		if ( $is_first_row ) {
			$this->apply_spacing( $settings, $section, false, true );
			$this->apply_shape_dividers( $settings, $section );
			$this->apply_min_height( $settings, $section );
		}
		if ( $row !== null ) {
			$this->apply_spacing( $settings, $row );
			// When Divi has no explicit padding on either the section or the row, apply a
			// combined default (Divi section default 4% + row default 27px ≈ use 4% so it
			// stays responsive). Only applies in the single-row path; multi-row uses
			// outer_section_settings() + inner_section_settings() which handle this separately.
			if ( ! isset( $settings['padding'] ) ) {
				$sec_pad = $section->attr( 'custom_padding' );
				$row_pad = $row->attr( 'custom_padding' );
				if ( $sec_pad === '' && $row_pad === '' ) {
					$settings['padding'] = [
						'unit'     => '%',
						'top'      => '4',
						'right'    => '0',
						'bottom'   => '4',
						'left'     => '0',
						'isLinked' => false,
					];
				}
			}
			$this->apply_min_height( $settings, $row );
			// Column gap from row gutter.
			$settings['gap'] = $this->map_gap(
				$row->attr( 'use_custom_gutter' ) === 'on' ? $row->attr( 'gutter_width' ) : '3'
			);
		}
		// Clickable section: link_option_url on row takes precedence over section.
		$link_url = $row !== null ? $row->attr( 'link_option_url' ) : '';
		if ( $link_url === '' ) {
			$link_url = $section->attr( 'link_option_url' );
		}
		if ( $link_url !== '' ) {
			$settings['link'] = [
				'url'         => $this->sanitize_dynamic_value( $link_url ),
				'is_external' => $section->attr( 'url_new_window' ) === 'blank',
			];
		}
		// Box shadow: row overrides section when both are set.
		$this->apply_box_shadow( $settings, $section );
		if ( $row !== null ) {
			$this->apply_box_shadow( $settings, $row );
		}
		// OR-combine section and row visibility.
		$nodes = $row !== null ? [ $section, $row ] : [ $section ];
		$this->apply_responsive_visibility( $settings, ...$nodes );
		if ( $row !== null ) {
			$this->apply_identity( $settings, $row );
			$this->apply_custom_css( $settings, $row );
		} else {
			$this->apply_identity( $settings, $section );
			$this->apply_custom_css( $settings, $section );
		}
		return $settings;
	}

	/** Map a Divi module's attributes to Elementor widget settings. */
	private function widget_settings( string $tag, DiviNode $node, string $widget_id = '' ): array {
		switch ( $tag ) {
			case 'text':
				$s = [ 'editor' => $node->content !== '' ? $node->content : '<p></p>' ];
				$this->apply_typography( $s, $node, 'typography', 'text' );
				$this->apply_text_paragraph_css( $s, $node );
				$this->apply_text_heading_css( $s, $node );
				return $s;

			case 'heading':
				$level = $node->attr( 'title_level', 'h2' );
				$s     = [
					'title'       => $node->attr( 'title' ),
					'header_size' => $level,
				];
				$align = $node->attr( 'text_orientation' ) ?: $node->attr( 'header_text_align' );
				if ( $align !== '' ) {
					$s['align'] = $align;
				}
				// Color: generic attribute, then per-level fallback (header_2_text_color etc.).
				$color = $node->attr( 'header_text_color' );
				if ( $color === '' ) {
					$level_num = preg_replace( '/[^0-9]/', '', $level );
					if ( $level_num !== '' ) {
						$color = $node->attr( "header_{$level_num}_text_color" );
					}
				}
				if ( $color !== '' ) {
					$s['title_color'] = $color;
				}
				// Typography: generic heading prefix, then per-level override.
				$this->apply_typography( $s, $node, 'title_typography', 'header' );
				$level_num = preg_replace( '/[^0-9]/', '', $level );
				if ( $level_num !== '' ) {
					$this->apply_typography( $s, $node, 'title_typography', "header_{$level_num}" );
				}
				return $s;

			case 'button':
				$url = $node->attr( 'button_url' ) ?: $node->attr( 'button_link' ) ?: $node->attr( 'href' );
				$s   = [
					'text' => $node->attr( 'button_text' ),
					'link' => [
						'url'         => $this->sanitize_dynamic_value( $url ),
						'is_external' => in_array( $node->attr( 'url_new_window' ), [ 'blank', 'on' ], true ),
					],
				];
				$align = $node->attr( 'button_alignment' );
				if ( $align !== '' ) {
					$s['align'] = $align;
				}
				// Inner button padding (Divi custom_padding = button element padding, not wrapper).
				$btn_pad = $node->attr( 'custom_padding' );
				if ( $btn_pad !== '' ) {
					$parsed = $this->parse_spacing( $btn_pad );
					if ( $parsed !== null ) {
						$s['button_padding'] = $parsed;
					}
				}
				if ( $node->attr( 'custom_button' ) === 'on' ) {
					$this->apply_typography( $s, $node, 'typography', 'button' );
					// Divi uses button_text_size (not button_font_size); override what apply_typography may have missed.
					$btn_size = $node->attr( 'button_text_size' );
					if ( $btn_size !== '' ) {
						$parsed = $this->parse_size_value( $btn_size );
						if ( $parsed !== null ) {
							$s['typography_font_size'] = $parsed;
						}
					}
					$color = $node->attr( 'button_text_color' );
					if ( $color !== '' ) {
						$s['button_text_color'] = $color;
					}
					$bg = $node->attr( 'button_bg_color' );
					if ( $bg !== '' ) {
						$s['background_color'] = $bg;
					}
					$bw = $node->attr( 'button_border_width' );
					if ( $bw !== '' ) {
						$num = (string) (float) preg_replace( '/[^0-9.]/', '', $bw );
						$s['border_border'] = 'solid';
						$s['border_width']  = [ 'unit' => 'px', 'top' => $num, 'right' => $num, 'bottom' => $num, 'left' => $num, 'isLinked' => true ];
					}
					$bc = $node->attr( 'button_border_color' );
					if ( $bc !== '' ) {
						$s['border_color'] = $bc;
					}
					$br = $node->attr( 'button_border_radius' );
					if ( $br !== '' ) {
						$num  = (string) (float) preg_replace( '/[^0-9.]/', '', $br );
						$unit = preg_replace( '/[0-9.]/', '', $br ) ?: 'px';
						$s['border_radius'] = [ 'unit' => $unit, 'top' => $num, 'right' => $num, 'bottom' => $num, 'left' => $num, 'isLinked' => true ];
					}
					// Hover states. Divi stores hover_enabled as "on" or "on|desktop" etc.
					if ( str_starts_with( $node->attr( 'button_bg_color__hover_enabled' ), 'on' ) ) {
						$hover_bg = $node->attr( 'button_bg_color__hover' );
						if ( $hover_bg !== '' ) {
							$s['background_color_hover'] = $hover_bg;
						}
					}
					if ( str_starts_with( $node->attr( 'button_text_color__hover_enabled' ), 'on' ) ) {
						$hover_color = $node->attr( 'button_text_color__hover' );
						if ( $hover_color !== '' ) {
							$s['button_text_color_hover'] = $hover_color;
						}
					}
					if ( str_starts_with( $node->attr( 'button_border_color__hover_enabled' ), 'on' ) ) {
						$hover_border = $node->attr( 'button_border_color__hover' );
						if ( $hover_border !== '' ) {
							$s['border_color_hover'] = $hover_border;
						}
					}
				}
				return $s;

			case 'image':
				$src = $this->sanitize_dynamic_value( $node->attr( 'src' ) );
				// Real Divi exports use `alt`; `alt_text` kept as fallback for test compatibility.
				$alt = $node->attr( 'alt' ) ?: $node->attr( 'alt_text' );
				$url = $this->sanitize_dynamic_value( $node->attr( 'url' ) );
				$s   = [
					'image'   => [ 'url' => $src, 'alt' => $alt ],
					'link_to' => $url !== '' ? 'custom' : '',
					'link'    => [
						'url'         => $url,
						'is_external' => in_array( $node->attr( 'url_new_window' ), [ 'blank', 'on' ], true ),
					],
				];
				$align = $node->attr( 'align' ) ?: $node->attr( 'module_alignment' );
				if ( $align !== '' ) {
					$s['align'] = $align;
				}
				$max_width = $node->attr( 'max_width' );
				if ( $max_width !== '' ) {
					$num  = (float) preg_replace( '/[^0-9.]/', '', $max_width );
					$unit = preg_replace( '/[0-9.]/', '', $max_width ) ?: 'px';
					$s['width'] = [ 'size' => $num, 'unit' => $unit ];
				}
				return $s;

			case 'video':
				return [
					'video_type'  => 'youtube',
					'youtube_url' => $node->attr( 'src' ),
				];

			case 'code':
				return [ 'html' => $this->close_html_comments( $node->content ) ];

			case 'divider':
				$s = [
					'style'  => 'solid',
					'weight' => [ 'size' => 1, 'unit' => 'px' ],
					'width'  => [ 'size' => 100, 'unit' => '%' ],
				];
				$color = $node->attr( 'color' );
				if ( $color !== '' ) {
					$s['color'] = $color;
				}
				$weight = $node->attr( 'divider_weight' );
				if ( $weight !== '' ) {
					$num = (float) ( preg_replace( '/[^0-9.]/', '', $weight ) ?: '1' );
					$s['weight'] = [ 'size' => $num, 'unit' => 'px' ];
				}
				$max_width = $node->attr( 'max_width' );
				if ( $max_width !== '' ) {
					$num  = (float) preg_replace( '/[^0-9.]/', '', $max_width );
					$unit = preg_replace( '/[0-9.]/', '', $max_width ) ?: 'px';
					$s['width'] = [ 'size' => $num, 'unit' => $unit ];
				}
				return $s;

			case 'spacer':
				return [ 'space' => [ 'size' => 50, 'unit' => 'px' ] ];

			case 'cta':
				return [
					'editor' => sprintf(
						'<h2>%s</h2><p>%s</p>',
						htmlspecialchars( $node->attr( 'title' ), ENT_QUOTES, 'UTF-8' ),
						$node->content
					),
				];

			case 'blurb':
				$placement_map = [ 'left' => 'left', 'right' => 'right', 'top' => 'top', 'bottom' => 'top' ];
				$s = [
					'title_text'       => $node->attr( 'title' ),
					'description_text' => $node->content !== '' ? $node->content : $node->attr( 'body' ),
					'position'         => $placement_map[ $node->attr( 'icon_placement' ) ] ?? 'top',
				];
				// Icon vs image mode.
				if ( $node->attr( 'use_icon' ) !== 'off' ) {
					$s['icon'] = $this->parse_divi_icon( $node->attr( 'font_icon' ) );
					$icon_color = $node->attr( 'icon_color' );
					if ( $icon_color !== '' ) {
						$s['primary_color'] = $icon_color;
					}
					$icon_size = $node->attr( 'icon_font_size' );
					if ( $icon_size === '' ) {
						$icon_size = $node->attr( 'use_icon_font_size' ) === 'on' ? $node->attr( 'icon_font_size' ) : '';
					}
					if ( $icon_size !== '' ) {
						$parsed = $this->parse_size_value( $icon_size );
						if ( $parsed !== null ) {
							$s['icon_size'] = $parsed;
						}
					}
				} else {
					// Image mode: use image_url or image attribute.
					$img = $node->attr( 'image' ) ?: $node->attr( 'image_url' );
					if ( $img !== '' ) {
						$s['image'] = [ 'url' => $this->sanitize_dynamic_value( $img ), 'id' => 0 ];
					}
				}
				// Title typography and color.
				$title_color = $node->attr( 'header_text_color' );
				if ( $title_color !== '' ) {
					$s['title_color'] = $title_color;
				}
				$this->apply_typography( $s, $node, 'title_typography', 'header' );
				// Description typography and color.
				$desc_color = $node->attr( 'body_text_color' ) ?: $node->attr( 'text_text_color' );
				if ( $desc_color !== '' ) {
					$s['description_color'] = $desc_color;
				}
				$this->apply_typography( $s, $node, 'description_typography', 'body' );
				// URL on the whole blurb.
				$url = $node->attr( 'url' );
				if ( $url !== '' ) {
					$s['link'] = [ 'url' => $this->sanitize_dynamic_value( $url ) ];
				}
				return $s;

			case 'testimonial':
				$s = [
					'testimonial_content' => $node->content,
					'testimonial_name'    => $node->attr( 'author' ),
					'testimonial_job'     => $node->attr( 'job_title' ),
					'testimonial_company' => $node->attr( 'company' ),
					'testimonial_image'   => [ 'url' => $node->attr( 'portrait_url' ) ],
				];
				$align = $node->attr( 'module_alignment' ) ?: $node->attr( 'text_orientation' );
				if ( in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
					$s['testimonial_alignment'] = $align;
				}
				return $s;

			case 'accordion':
				return [
					'tabs' => array_map(
						static fn( DiviNode $c ) => [
							'tab_title'   => $c->attr( 'title' ),
							'tab_content' => $c->content,
						],
						array_filter( $node->children, static fn( DiviNode $c ) => $c->tag === 'accordion_item' )
					),
				];

			case 'toggle':
				return [
					'tabs' => [
						[
							'tab_title'   => $node->attr( 'title' ),
							'tab_content' => $node->content,
						],
					],
				];

			case 'tabs':
				return [
					'tabs' => array_map(
						static fn( DiviNode $c ) => [
							'tab_title'   => $c->attr( 'title' ),
							'tab_content' => $c->content,
						],
						array_filter( $node->children, static fn( DiviNode $c ) => $c->tag === 'tab' )
					),
				];

			case 'gallery':
				$ids = array_filter( array_map( 'trim', explode( ',', $node->attr( 'gallery_ids' ) ) ) );
				return [
					'gallery' => array_values(
						array_map( static fn( $id ) => [ 'id' => (int) $id ], $ids )
					),
				];

			case 'social_media_follow':
				$icons = [];
				foreach ( $node->children as $c ) {
					if ( $c->tag !== 'social_media_follow_network' ) {
						continue;
					}
					$icons[] = [
						'social_icon' => $this->social_fa_icon( $c->attr( 'social_network' ) ),
						'link'        => [ 'url' => $c->attr( 'url' ) ],
					];
				}
				return [ 'social_icon_list' => $icons ];

			case 'map':
				return [
					'address' => $node->attr( 'address' ),
					'zoom'    => [ 'size' => (int) ( $node->attr( 'zoom_level' ) ?: 12 ) ],
				];

			case 'countdown_timer':
				return [ 'due_date' => $node->attr( 'date_and_time' ) ];

			case 'number_counter':
			case 'circle_counter':
			case 'counters':
				return [
					'starting_number' => 0,
					'ending_number'   => (int) $node->attr( 'percent' ),
					'title'           => [ 'text' => $node->attr( 'title' ) ],
				];

			case 'icon':
				return [ 'icon' => $node->attr( 'font_icon' ), 'view' => 'default' ];

			case 'login':
				return [ 'show_register' => $node->attr( 'show_register' ) === 'on' ? 'yes' : '' ];

			case 'search':
				return [];

			case 'contact_form':
				return [ 'html' => '[contact-form-7]' ];

			case 'menu':
			case 'fullwidth_menu':
				// ElementsKit Lite's ekit-nav-menu widget uses the menu slug/ID as its
				// selector value. wp_get_nav_menu_items() accepts numeric IDs so passing
				// Divi's menu_id integer works even though the control stores slugs.
				return [ 'elementskit_nav_menu' => (string) (int) $node->attr( 'menu_id' ) ];

			case 'sidebar':
				return [ 'sidebar' => 'sidebar-1' ];

			case 'blog':
				return [
					'posts_per_page' => (int) ( $node->attr( 'posts_number' ) ?: 10 ),
					'post_type'      => 'post',
					'skin'           => 'classic',
				];

			case 'audio':
				return [ 'url' => $node->attr( 'audio' ), 'skin' => 'minimal' ];

			case 'team_member':
				return [
					'title'       => $node->attr( 'name' ),
					'description' => $node->content,
					'image'       => [ 'url' => $node->attr( 'image_url' ) ],
				];

			case 'pricing_tables':
				return [ 'html' => $this->render_pricing_tables( $node ) ];

			case 'portfolio':
			case 'filterable_portfolio':
				return [
					'posts_per_page' => (int) ( $node->attr( 'posts_number' ) ?: 10 ),
					'post_type'      => 'project',
				];

			case 'signup':
				$provider = $node->attr( 'provider' );
				$title    = $node->attr( 'title' );
				return [
					'html' => sprintf(
						'<!-- Email opt-in form%s%s — reconnect to your email provider after import. -->',
						$provider !== '' ? " (provider: {$provider})" : '',
						$title !== '' ? ", title: \"{$title}\"" : ''
					),
				];

			case 'post_content':
				// Elementor Pro's theme-post-content widget needs no settings.
				return [];

			case 'video_slider':
				// Use the first video item as a single video widget.
				foreach ( $node->children as $c ) {
					if ( $c->tag === 'video_slider_item' && $c->attr( 'src' ) !== '' ) {
						return [
							'video_type'  => 'youtube',
							'youtube_url' => $c->attr( 'src' ),
						];
					}
				}
				return [];

			// WooCommerce widgets render from WC product context; no extra settings needed.
			case 'wc_title':
			case 'wc_images':
			case 'wc_price':
			case 'wc_description':
			case 'wc_add_to_cart':
			case 'wc_rating':
			case 'wc_reviews':
			case 'wc_breadcrumb':
			case 'wc_additional_info':
			case 'wc_related_products':
				return [];

			case 'wc_cart_notice':
				return [ 'html' => '[woocommerce_cart]' ];

			default:
				return [];
		}
	}

	/**
	 * Convert a Divi slider to a standalone Elementor section using the first slide only.
	 * Background image from the slide is set on the section; heading, body, and button
	 * become individual widgets inside a single full-width column.
	 */
	private function build_slider_section( DiviNode $slider, DiviNode $parent_section ): array {
		$first_slide = null;
		foreach ( $slider->children as $c ) {
			if ( $c->tag === 'slide' ) {
				$first_slide = $c;
				break;
			}
		}

		$section_settings = [];
		$this->apply_background( $section_settings, $parent_section );
		if ( $first_slide !== null ) {
			$this->apply_background( $section_settings, $first_slide );
		}
		$this->apply_identity( $section_settings, $slider );
		$this->apply_custom_css( $section_settings, $slider );

		$widgets = [];
		if ( $first_slide !== null ) {
			$heading = $first_slide->attr( 'heading' );
			if ( $heading !== '' ) {
				$widgets[] = [
					'id'         => $this->uid(),
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => [ 'title' => $heading, 'header_size' => 'h2' ],
					'elements'   => [],
				];
			}
			if ( $first_slide->content !== '' ) {
				$widgets[] = [
					'id'         => $this->uid(),
					'elType'     => 'widget',
					'widgetType' => 'text-editor',
					'settings'   => [ 'editor' => $first_slide->content ],
					'elements'   => [],
				];
			}
			$btn_text = $first_slide->attr( 'button_text' );
			if ( $btn_text !== '' ) {
				$widgets[] = [
					'id'         => $this->uid(),
					'elType'     => 'widget',
					'widgetType' => 'button',
					'settings'   => [
						'text' => $btn_text,
						'link' => [ 'url' => $first_slide->attr( 'button_url' ), 'is_external' => false ],
					],
					'elements'   => [],
				];
			}
		}

		return [
			'id'       => $this->uid(),
			'elType'   => 'section',
			'isInner'  => false,
			'settings' => $section_settings,
			'elements' => [
				[
					'id'       => $this->uid(),
					'elType'   => 'column',
					'settings' => [ '_column_size' => 100, '_inline_size' => null ],
					'elements' => $widgets,
				],
			],
		];
	}

	/** Render Divi pricing_tables children as an HTML string for an html widget. */
	private function render_pricing_tables( DiviNode $node ): string {
		$html = '';
		foreach ( $node->children as $table ) {
			if ( $table->tag !== 'pricing_table' ) {
				continue;
			}
			$title    = $table->attr( 'header' ) ?: $table->attr( 'title' );
			$subtitle = $table->attr( 'subheader' ) ?: $table->attr( 'subtitle' );
			$currency = $table->attr( 'currency_frequency' ) ?: $table->attr( 'currency' );
			$price    = $table->attr( 'price_per_unit' ) ?: $table->attr( 'price' );
			$per      = $table->attr( 'per' );
			$btn_text = $table->attr( 'button_text' );
			$btn_url  = $table->attr( 'button_url' );

			$items = '';
			foreach ( $table->children as $item ) {
				if ( $item->tag === 'pricing_item' ) {
					$items .= '<li>' . $item->content . '</li>';
				}
			}

			$html .= '<div class="et-pricing-table">';
			if ( $title !== '' ) {
				$html .= '<h4>' . htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' ) . '</h4>';
			}
			if ( $subtitle !== '' ) {
				$html .= '<p class="et-price-subtitle">' . htmlspecialchars( $subtitle, ENT_QUOTES, 'UTF-8' ) . '</p>';
			}
			if ( $price !== '' ) {
				$html .= '<div class="et-price">' . htmlspecialchars( $currency . $price, ENT_QUOTES, 'UTF-8' );
				if ( $per !== '' ) {
					$html .= '<span>/' . htmlspecialchars( $per, ENT_QUOTES, 'UTF-8' ) . '</span>';
				}
				$html .= '</div>';
			}
			if ( $items !== '' ) {
				$html .= '<ul>' . $items . '</ul>';
			}
			if ( $btn_text !== '' ) {
				$html .= '<a href="' . htmlspecialchars( $btn_url, ENT_QUOTES, 'UTF-8' ) . '" class="et-btn">'
					. htmlspecialchars( $btn_text, ENT_QUOTES, 'UTF-8' ) . '</a>';
			}
			$html .= '</div>';
		}
		return $html ?: '<!-- Pricing tables (no data parsed) -->';
	}

	/** Map a Divi social network name to a Font Awesome 5 icon string. */
	private function social_fa_icon( string $network ): string {
		$map = [
			'facebook'  => 'fab fa-facebook-f',
			'twitter'   => 'fab fa-twitter',
			'instagram' => 'fab fa-instagram',
			'youtube'   => 'fab fa-youtube',
			'linkedin'  => 'fab fa-linkedin',
			'pinterest' => 'fab fa-pinterest',
			'vimeo'     => 'fab fa-vimeo-v',
			'github'    => 'fab fa-github',
			'tumblr'    => 'fab fa-tumblr',
		];
		return $map[ $network ] ?? 'fab fa-' . str_replace( '_', '-', $network );
	}

	// -----------------------------------------------------------------------
	// Attribute helpers
	// -----------------------------------------------------------------------

	/**
	 * Apply Divi disabled_on → Elementor hide_desktop / hide_tablet / hide_mobile.
	 *
	 * Divi stores visibility as "phone|tablet|desktop" where "on" means hidden.
	 * Elementor stores it as hide_<device> = "hidden-<device>" when hidden.
	 * Multiple nodes are OR-combined: if any node hides a device, the result is hidden.
	 */
	private function apply_responsive_visibility( array &$settings, DiviNode ...$nodes ): void {
		$map = [ 'desktop' => false, 'tablet' => false, 'mobile' => false ];

		foreach ( $nodes as $node ) {
			$raw = $node->attr( 'disabled_on' );
			if ( $raw === '' || $raw === 'off|off|off' ) {
				continue;
			}
			$parts = explode( '|', $raw );
			if ( ( $parts[0] ?? '' ) === 'on' ) { $map['mobile']  = true; }
			if ( ( $parts[1] ?? '' ) === 'on' ) { $map['tablet']  = true; }
			if ( ( $parts[2] ?? '' ) === 'on' ) { $map['desktop'] = true; }
		}

		foreach ( $map as $device => $hidden ) {
			if ( $hidden ) {
				$settings[ 'hide_' . $device ] = 'hidden-' . $device;
			}
		}
	}

	/**
	 * Apply background_color / background_image (+ position/size) from a DiviNode
	 * to an Elementor settings array in-place.
	 * Call with section first, then row to let row-level settings take precedence.
	 */
	private function apply_background( array &$settings, DiviNode $node ): void {
		$color = $node->attr( 'background_enable_color' ) === 'off' ? '' : $node->attr( 'background_color' );
		$image = $node->attr( 'background_enable_image' ) === 'off'
			? ''
			: $this->sanitize_dynamic_value( $node->attr( 'background_image' ) );

		if ( $image !== '' ) {
			$settings['background_background'] = 'classic';
			$settings['background_image']      = [ 'url' => $image, 'id' => 0 ];
			if ( $color !== '' ) {
				$settings['background_color'] = $color;
			}
			$pos = $node->attr( 'background_position' );
			if ( $pos !== '' ) {
				$settings['background_position'] = $this->map_background_position( $pos );
			}
			$size = $node->attr( 'background_size' );
			if ( $size !== '' ) {
				$settings['background_size'] = $size === 'initial' ? 'auto' : $size;
			}
		} elseif ( $color !== '' ) {
			$settings['background_background'] = 'classic';
			$settings['background_color']      = $color;
		}
	}

	/**
	 * Ensure every HTML comment opened with <!-- is closed with -->.
	 * An unclosed <!-- in an html widget causes WordPress's do_shortcode()
	 * to treat everything after it as comment content and skip all shortcodes.
	 */
	private function close_html_comments( string $html ): string {
		$opens  = substr_count( $html, '<!--' );
		$closes = substr_count( $html, '-->' );
		if ( $opens > $closes ) {
			$html .= str_repeat( ' -->', $opens - $closes );
		}
		return $html;
	}

	/**
	 * Map Divi module_id → Elementor _element_id (CSS ID) and
	 * module_class → _css_classes. Applies to any element type.
	 */
	private function apply_identity( array &$settings, DiviNode $node ): void {
		$id = $node->attr( 'module_id' );
		if ( $id !== '' ) {
			$settings['_element_id'] = $id;
		}
		$class = $node->attr( 'module_class' );
		if ( $class !== '' ) {
			$settings['_css_classes'] = $class;
		}
	}

	/**
	 * Map Divi custom_css_free_form → Elementor custom_css.
	 *
	 * custom_css_free_form already contains full rule blocks with "selector" as the
	 * element placeholder, so it transfers as-is.
	 *
	 * custom_css_main_element contains raw CSS properties (no selector wrapper).
	 * These must be wrapped in a selector block. For columns, Elementor's own
	 * .elementor-widget-wrap is already flex-direction:column; targeting it directly
	 * lets flex properties (e.g. flex-direction:row) take effect on the widget layer.
	 *
	 * @param string $raw_inner  CSS child combinator to append to "selector" when
	 *                           wrapping raw custom_css_main_element values.
	 *                           Empty string targets the element itself.
	 */
	private function apply_custom_css( array &$settings, DiviNode $node, string $raw_inner = '' ): void {
		$css = $node->attr( 'custom_css_free_form' );
		if ( $css !== '' ) {
			$settings['custom_css'] = $this->append_css( $settings['custom_css'] ?? '', $css );
			return;
		}
		$raw = $node->attr( 'custom_css_main_element' );
		if ( $raw !== '' ) {
			// When a column uses flex-direction:row, two Elementor defaults fight back:
			// (1) .elementor-widget-wrap has flex-direction:column, and
			// (2) each child .elementor-element has width:100%.
			// Stamp !important directly on the declaration so it wins in one rule block.
			$is_flex_row = $raw_inner !== '' && preg_match( '/flex-direction\s*:\s*row/i', $raw );
			if ( $is_flex_row ) {
				$raw = preg_replace( '/flex-direction\s*:\s*row/i', 'flex-direction: row !important', $raw );
			}
			$block = "selector{$raw_inner} {\n{$raw}\n}";
			if ( $is_flex_row ) {
				$block .= "\nselector .elementor-widget-wrap > .elementor-element {\n    width: auto !important;\n    flex: 0 0 auto !important;\n}";
			}
			$settings['custom_css'] = $this->append_css( $settings['custom_css'] ?? '', $block );
		}
	}

	/**
	 * Apply Divi box_shadow_* attributes to Elementor settings.
	 *
	 * @param string $prefix  'box_shadow'  for section/column, '_box_shadow' for widget advanced tab.
	 */
	private function apply_box_shadow( array &$settings, DiviNode $node, string $prefix = 'box_shadow' ): void {
		$style = $node->attr( 'box_shadow_style' );
		if ( $style === '' || $style === 'none' ) {
			return;
		}

		// Divi exports resolved px values alongside the preset name, so read them directly.
		$parse_num = static function ( string $val, float $default = 0.0 ): float {
			$val = trim( $val );
			if ( $val === '' ) {
				return $default;
			}
			return (float) preg_replace( '/[^0-9.\-]/', '', $val );
		};

		$settings[ "{$prefix}_box_shadow_type" ] = 'yes';
		$settings[ "{$prefix}_box_shadow" ]      = [
			'horizontal' => $parse_num( $node->attr( 'box_shadow_horizontal' ) ),
			'vertical'   => $parse_num( $node->attr( 'box_shadow_vertical' ) ),
			'blur'       => $parse_num( $node->attr( 'box_shadow_blur' ) ),
			'spread'     => $parse_num( $node->attr( 'box_shadow_spread' ) ),
			'inset'      => $node->attr( 'box_shadow_position' ) === 'inner' ? 'inset' : '',
			'color'      => $node->attr( 'box_shadow_color' ) ?: 'rgba(0,0,0,0.3)',
		];
	}

	/** Concatenate two CSS strings, separated by a newline when both are non-empty. */
	private function append_css( string $existing, string $new ): string {
		if ( $existing === '' ) {
			return $new;
		}
		if ( $new === '' ) {
			return $existing;
		}
		return $existing . "\n" . $new;
	}

	/**
	 * Parse a Divi spacing string ("top|right|bottom|left|important|linked") into
	 * an Elementor spacing array. Returns null when the value is empty or unusable.
	 */
	private function parse_spacing( string $raw ): ?array {
		if ( $raw === '' ) {
			return null;
		}
		// DiviParser converts || (empty slot marker) → \n for CSS attributes.
		// Reverse that here so "0px||50px||false|false" splits into 6 parts correctly.
		$raw   = str_replace( "\n", '||', $raw );
		$parts = explode( '|', $raw );
		if ( count( $parts ) < 4 ) {
			return null;
		}
		$unit   = 'px';
		$mapped = [];
		foreach ( array_slice( $parts, 0, 4 ) as $v ) {
			$v = trim( $v );
			if ( $v === '' ) {
				$mapped[] = '';
			} elseif ( preg_match( '/^(-?[\d.]+)(px|em|%|rem|vw|vh)?$/', $v, $m ) ) {
				$mapped[] = $m[1];
				if ( ! empty( $m[2] ) ) {
					$unit = $m[2];
				}
			} else {
				$mapped[] = $v;
			}
		}
		return [
			'unit'     => $unit,
			'top'      => $mapped[0],
			'right'    => $mapped[1],
			'bottom'   => $mapped[2],
			'left'     => $mapped[3],
			'isLinked' => false,
		];
	}

	/**
	 * Apply Divi custom_padding / custom_margin (and their _tablet / _phone variants)
	 * to an Elementor settings array.
	 */
	private function apply_spacing( array &$settings, DiviNode $node, bool $padding = true, bool $margin = true ): void {
		if ( ! $padding && ! $margin ) {
			return;
		}
		foreach ( [ 'padding' => 'custom_padding', 'margin' => 'custom_margin' ] as $el_key => $divi_key ) {
			if ( $el_key === 'padding' && ! $padding ) {
				continue;
			}
			if ( $el_key === 'margin' && ! $margin ) {
				continue;
			}
			$val = $node->attr( $divi_key );
			if ( $val !== '' ) {
				$parsed = $this->parse_spacing( $val );
				if ( $parsed !== null ) {
					$settings[ $el_key ] = $parsed;
				}
			}
			foreach ( [ '_tablet' => '_tablet', '_mobile' => '_phone' ] as $el_sfx => $divi_sfx ) {
				$val = $node->attr( $divi_key . $divi_sfx );
				if ( $val !== '' ) {
					$parsed = $this->parse_spacing( $val );
					if ( $parsed !== null ) {
						$settings[ $el_key . $el_sfx ] = $parsed;
					}
				}
			}
		}
	}

	/**
	 * Apply Divi min_height (and _tablet / _phone variants) to Elementor settings.
	 */
	private function apply_min_height( array &$settings, DiviNode $node ): void {
		$h = $node->attr( 'min_height' );
		if ( $h !== '' ) {
			$parsed = $this->parse_size_value( $h );
			if ( $parsed !== null ) {
				$settings['min_height'] = $parsed;
			}
		}
		$ht = $node->attr( 'min_height_tablet' );
		if ( $ht !== '' ) {
			$parsed = $this->parse_size_value( $ht );
			if ( $parsed !== null ) {
				$settings['min_height_tablet'] = $parsed;
			}
		}
		$hp = $node->attr( 'min_height_phone' );
		if ( $hp !== '' ) {
			$parsed = $this->parse_size_value( $hp );
			if ( $parsed !== null ) {
				$settings['min_height_mobile'] = $parsed;
			}
		}
	}

	/**
	 * Apply Divi border_radii ("on|tl|tr|br|bl") to Elementor border_radius.
	 */
	private function apply_border_radius( array &$settings, DiviNode $node ): void {
		$raw = $node->attr( 'border_radii' );
		if ( $raw === '' ) {
			return;
		}
		$parts = explode( '|', $raw );
		// Format: on|top-left|top-right|bottom-right|bottom-left
		if ( count( $parts ) < 5 || $parts[0] !== 'on' ) {
			return;
		}
		$unit   = 'px';
		$values = [];
		foreach ( array_slice( $parts, 1, 4 ) as $v ) {
			$v = trim( $v );
			if ( preg_match( '/^(-?[\d.]+)(px|em|rem|%)?$/', $v, $m ) ) {
				$values[] = $m[1];
				if ( ! empty( $m[2] ) ) {
					$unit = $m[2];
				}
			} else {
				$values[] = '0';
			}
		}
		$settings['border_radius'] = [
			'unit'     => $unit,
			'top'      => $values[0],
			'right'    => $values[1],
			'bottom'   => $values[2],
			'left'     => $values[3],
			'isLinked' => count( array_unique( $values ) ) === 1,
		];
	}

	/**
	 * Apply z_index, positioning, and module_alignment from Divi to Elementor advanced settings.
	 */
	private function apply_advanced_position( array &$settings, DiviNode $node ): void {
		$z = $node->attr( 'z_index' );
		if ( $z !== '' && is_numeric( $z ) ) {
			$settings['z_index'] = $z;
		}
		$pos = $node->attr( 'positioning' );
		if ( in_array( $pos, [ 'absolute', 'fixed', 'relative' ], true ) ) {
			$settings['_position'] = $pos;
		}
		$align = $node->attr( 'module_alignment' );
		if ( in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
			$settings['_element_align'] = $align;
		}
	}

	/**
	 * Apply a Divi gradient background to Elementor settings.
	 * Multi-stop gradients are simplified to the first and last colour stops.
	 */
	private function apply_gradient( array &$settings, DiviNode $node ): void {
		if ( $node->attr( 'use_background_color_gradient' ) !== 'on' ) {
			return;
		}
		$stops_raw = $node->attr( 'background_color_gradient_stops' );
		$direction = $node->attr( 'background_color_gradient_direction' );
		$colors    = [];
		foreach ( array_filter( array_map( 'trim', explode( '|', $stops_raw ) ) ) as $stop ) {
			if ( preg_match( '/^(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\))/', $stop, $m ) ) {
				$colors[] = $m[1];
			}
		}
		if ( empty( $colors ) ) {
			return;
		}
		$angle = 180;
		if ( preg_match( '/(\d+)deg/', $direction, $m ) ) {
			$angle = (int) $m[1];
		}
		$settings['background_background']     = 'gradient';
		$settings['background_color_a']        = $colors[0];
		$settings['background_color_b']        = end( $colors );
		$settings['background_gradient_type']  = 'linear';
		$settings['background_gradient_angle'] = $angle;
	}

	/**
	 * Map Divi top/bottom divider style + color to Elementor shape divider settings.
	 */
	private function apply_shape_dividers( array &$settings, DiviNode $node ): void {
		$map = [
			'triangle' => 'triangle',
			'arrow'    => 'arrow',
			'curve'    => 'tilt',
			'wave'     => 'waves',
			'clouds'   => 'clouds',
			'zigzag'   => 'zigzag',
		];
		foreach ( [ 'top', 'bottom' ] as $pos ) {
			$style = $node->attr( "{$pos}_divider_style" );
			if ( $style !== '' && $style !== 'none' && isset( $map[ $style ] ) ) {
				$settings[ "shape_divider_{$pos}" ] = $map[ $style ];
				$color = $node->attr( "{$pos}_divider_color" );
				if ( $color !== '' ) {
					$settings[ "shape_divider_{$pos}_color" ] = $color;
				}
			}
		}
	}

	/**
	 * Apply Divi font/size/line-height/letter-spacing attributes to Elementor typography controls.
	 *
	 * @param string $el_prefix    Elementor control group prefix (e.g. 'typography', 'title_typography').
	 * @param string $divi_prefix  Divi attribute prefix (e.g. 'text', 'header', 'button', 'header_2').
	 */
	private function apply_typography( array &$settings, DiviNode $node, string $el_prefix, string $divi_prefix ): void {
		$font_str = $node->attr( "{$divi_prefix}_font" );
		$size_str = $node->attr( "{$divi_prefix}_font_size" );
		$lh_str   = $node->attr( "{$divi_prefix}_line_height" );
		$ls_str   = $node->attr( "{$divi_prefix}_letter_spacing" );

		if ( $font_str !== '' ) {
			$font = $this->parse_divi_font( $font_str );
			if ( $font['family'] !== '' ) {
				$settings[ "{$el_prefix}_typography" ]  = 'custom';
				$settings[ "{$el_prefix}_font_family" ] = $font['family'];
			}
			if ( $font['weight'] !== '' ) {
				$settings[ "{$el_prefix}_typography" ]  = 'custom';
				$settings[ "{$el_prefix}_font_weight" ] = $font['weight'];
			}
			if ( $font['style'] !== 'normal' ) {
				$settings[ "{$el_prefix}_font_style" ] = $font['style'];
			}
			if ( $font['transform'] !== 'none' ) {
				$settings[ "{$el_prefix}_text_transform" ] = $font['transform'];
			}
		}

		if ( $size_str !== '' ) {
			$parsed = $this->parse_size_value( $size_str );
			if ( $parsed !== null ) {
				$settings[ "{$el_prefix}_typography" ] = 'custom';
				$settings[ "{$el_prefix}_font_size" ]  = $parsed;
			}
		}

		if ( $lh_str !== '' ) {
			$parsed = $this->parse_size_value( $lh_str );
			if ( $parsed !== null ) {
				$settings[ "{$el_prefix}_typography" ]  = 'custom';
				$settings[ "{$el_prefix}_line_height" ] = $parsed;
			}
		}

		if ( $ls_str !== '' ) {
			$parsed = $this->parse_size_value( $ls_str );
			if ( $parsed !== null ) {
				$settings[ "{$el_prefix}_typography" ]     = 'custom';
				$settings[ "{$el_prefix}_letter_spacing" ] = $parsed;
			}
		}
	}

	/**
	 * Parse a Divi font string ("Family|weight|italic|underline|strikethrough|uppercase|lowercase|smallcaps")
	 * into its component parts.
	 *
	 * @return array{family: string, weight: string, style: string, transform: string}
	 */
	private function parse_divi_font( string $font_str ): array {
		$parts     = explode( '|', $font_str );
		$family    = isset( $parts[0] ) && $parts[0] !== 'none' ? trim( $parts[0] ) : '';
		$raw_weight = isset( $parts[1] ) ? trim( $parts[1] ) : '';
		// Divi sometimes stores 'on' as a boolean shorthand for bold; only keep numeric weights.
		$weight    = is_numeric( $raw_weight ) ? $raw_weight : '';
		$style     = isset( $parts[2] ) && $parts[2] === 'italic' ? 'italic' : 'normal';
		$transform = 'none';
		if ( isset( $parts[5] ) && $parts[5] === 'uppercase' ) {
			$transform = 'uppercase';
		} elseif ( isset( $parts[6] ) && $parts[6] === 'lowercase' ) {
			$transform = 'lowercase';
		} elseif ( isset( $parts[7] ) && $parts[7] === 'capitalize' ) {
			$transform = 'capitalize';
		}
		return compact( 'family', 'weight', 'style', 'transform' );
	}

	/**
	 * Parse a CSS size string like "16px" or "1.5em" into Elementor's size/unit object.
	 * Returns null when the value is empty or unparseable.
	 *
	 * @return array{unit: string, size: float}|null
	 */
	private function parse_size_value( string $val ): ?array {
		$val = trim( $val );
		if ( $val === '' ) {
			return null;
		}
		if ( preg_match( '/^(-?[\d.]+)(px|em|rem|%|vw|vh)?$/', $val, $m ) ) {
			return [
				'unit' => $m[2] ?: 'px',
				'size' => (float) $m[1],
			];
		}
		return null;
	}

	/**
	 * Strip Divi dynamic content tokens (@ET-DC@base64json@) from attribute values.
	 * These tokens reference dynamic WordPress data (site logo, post title, etc.) that
	 * cannot be resolved without a live WP context; returning an empty string is safer
	 * than leaving a corrupted URL in the output.
	 */
	private function sanitize_dynamic_value( string $value ): string {
		if ( strpos( $value, '@ET-DC@' ) === false ) {
			return $value;
		}
		return (string) preg_replace( '/@ET-DC@[A-Za-z0-9+\/=]*@/', '', $value );
	}

	/**
	 * Convert Divi's background_position format ("horizontal||vertical", stored as
	 * "horizontal\nvertical" after DiviParser decodes ||) to a CSS background-position
	 * string ("horizontal vertical").
	 */
	private function map_background_position( string $divi_pos ): string {
		if ( $divi_pos === '' ) {
			return '';
		}
		// DiviParser decodes || → \n for all attribute values.
		$parts = explode( "\n", $divi_pos );
		if ( count( $parts ) >= 2 ) {
			return ( trim( $parts[0] ) ?: 'center' ) . ' ' . ( trim( $parts[1] ) ?: 'center' );
		}
		return trim( $divi_pos );
	}

	/** Map a Divi gutter_width (1-4) to the corresponding Elementor gap value. */
	private function map_gap( string $gutter ): string {
		return [ '1' => 'no', '2' => 'narrow', '3' => 'default', '4' => 'wide' ][ trim( $gutter ) ] ?? 'default';
	}

	/**
	 * Apply a Divi module-level background_color to Elementor widget advanced background.
	 * This is separate from apply_background() (which targets sections/columns) because
	 * Elementor widget-level backgrounds use the underscore-prefixed control group.
	 */
	private function apply_widget_background( array &$settings, DiviNode $node ): void {
		if ( $node->attr( 'background_enable_color' ) === 'off' ) {
			return;
		}
		$color = $node->attr( 'background_color' );
		if ( $color === '' ) {
			return;
		}
		$settings['_background_background'] = 'classic';
		$settings['_background_color']      = $color;
	}

	/**
	 * Convert Divi's font_icon attribute ("unicode\nprefix\nweight") to an Elementor
	 * icon object {"value": "fas fa-check-circle", "library": "fa-solid"}.
	 *
	 * Falls back to an empty icon object when the codepoint is not in FA_ICON_MAP.
	 *
	 * @return array{value: string, library: string}
	 */
	private function parse_divi_icon( string $raw ): array {
		if ( $raw === '' ) {
			return [ 'value' => '', 'library' => 'fa-solid' ];
		}
		// DiviParser converted || → \n; format is: unicode_char\nlibrary\nweight
		$parts   = explode( "\n", $raw );
		$unicode = isset( $parts[0] ) ? trim( $parts[0] ) : '';
		$weight  = isset( $parts[2] ) ? trim( $parts[2] ) : '900';

		// Determine Elementor library and FA prefix from weight/style.
		if ( (int) $weight >= 900 ) {
			$prefix  = 'fas';
			$library = 'fa-solid';
		} elseif ( (int) $weight === 0 || $weight === 'fab' || $weight === 'brands' ) {
			$prefix  = 'fab';
			$library = 'fa-brands';
		} else {
			$prefix  = 'far';
			$library = 'fa-regular';
		}

		// Map unicode character to a FontAwesome class name.
		$hex      = strtolower( sprintf( '%04x', mb_ord( $unicode, 'UTF-8' ) ) );
		$fa_name  = self::FA_ICON_MAP[ $hex ] ?? '';
		$value    = $fa_name !== '' ? "{$prefix} fa-{$fa_name}" : '';

		return [ 'value' => $value, 'library' => $library ];
	}

	/**
	 * Generate Elementor custom_css for paragraph-level text styles in a text-editor widget.
	 * The text-editor widget has no dedicated color/size/align controls; custom_css is the
	 * only reliable mechanism.
	 */
	private function apply_text_paragraph_css( array &$settings, DiviNode $node ): void {
		$wrapper_props = '';
		$para_props    = '';

		// Wrapper-level alignment (applies to the whole widget block).
		$align = $node->attr( 'text_orientation' ) ?: $node->attr( 'module_alignment' );
		if ( in_array( $align, [ 'left', 'center', 'right', 'justify' ], true ) ) {
			$wrapper_props .= "    text-align: {$align};\n";
		}

		// Paragraph color.
		$color = $node->attr( 'text_text_color' );
		if ( $color !== '' ) {
			$para_props .= "    color: {$color};\n";
		}

		// Paragraph font size.
		$size = $node->attr( 'text_font_size' );
		if ( $size !== '' ) {
			$para_props .= "    font-size: {$size};\n";
		}

		// Paragraph line height.
		$lh = $node->attr( 'text_line_height' );
		if ( $lh !== '' ) {
			$para_props .= "    line-height: {$lh};\n";
		}

		// Paragraph letter spacing.
		$ls = $node->attr( 'text_letter_spacing' );
		if ( $ls !== '' ) {
			$para_props .= "    letter-spacing: {$ls};\n";
		}

		$blocks = [];
		if ( $wrapper_props !== '' ) {
			$blocks[] = "selector {\n{$wrapper_props}}";
		}
		if ( $para_props !== '' ) {
			$blocks[] = "selector p, selector li {\n{$para_props}}";
		}

		if ( ! empty( $blocks ) ) {
			$settings['custom_css'] = $this->append_css(
				$settings['custom_css'] ?? '',
				implode( "\n", $blocks )
			);
		}
	}

	/**
	 * Generate Elementor custom_css for heading elements styled via Divi's
	 * header_font / header_font_size / header_text_color attributes on et_pb_text.
	 * Covers generic (all-heading) settings and per-level h1–h6 overrides.
	 */
	private function apply_text_heading_css( array &$settings, DiviNode $node ): void {
		$blocks = [];

		// Generic heading attributes (apply to all heading levels).
		$generic_props = $this->build_heading_css_props( $node, 'header' );
		if ( $generic_props !== '' ) {
			$blocks[] = "selector h1,selector h2,selector h3,selector h4,selector h5,selector h6{{$generic_props}}";
		}

		// Per-level overrides: header_1_*, header_2_*, …
		foreach ( [ 1, 2, 3, 4, 5, 6 ] as $level ) {
			$level_props = $this->build_heading_css_props( $node, "header_{$level}" );
			if ( $level_props !== '' ) {
				$blocks[] = "selector h{$level}{{$level_props}}";
			}
		}

		if ( empty( $blocks ) ) {
			return;
		}

		$settings['custom_css'] = $this->append_css(
			$settings['custom_css'] ?? '',
			implode( "\n", $blocks )
		);
	}

	/**
	 * Build a CSS property block string from Divi heading-related attributes with a given prefix.
	 * Returns an empty string when no attributes are set.
	 */
	private function build_heading_css_props( DiviNode $node, string $prefix ): string {
		$props = '';
		$color = $node->attr( "{$prefix}_text_color" );
		if ( $color !== '' ) {
			$props .= "    color: {$color};\n";
		}
		$size = $node->attr( "{$prefix}_font_size" );
		if ( $size !== '' ) {
			$props .= "    font-size: {$size};\n";
		}
		$font_str = $node->attr( "{$prefix}_font" );
		if ( $font_str !== '' ) {
			$font = $this->parse_divi_font( $font_str );
			if ( $font['family'] !== '' ) {
				$props .= "    font-family: '{$font['family']}';\n";
			}
			if ( $font['weight'] !== '' && is_numeric( $font['weight'] ) ) {
				$props .= "    font-weight: {$font['weight']};\n";
			}
			if ( $font['style'] !== 'normal' ) {
				$props .= "    font-style: {$font['style']};\n";
			}
			if ( $font['transform'] !== 'none' ) {
				$props .= "    text-transform: {$font['transform']};\n";
			}
		}
		$align = $node->attr( "{$prefix}_text_align" );
		if ( in_array( $align, [ 'left', 'center', 'right', 'justify' ], true ) ) {
			$props .= "    text-align: {$align};\n";
		}
		$lh = $node->attr( "{$prefix}_line_height" );
		if ( $lh !== '' ) {
			$props .= "    line-height: {$lh};\n";
		}
		return $props;
	}

	/** Generate a 7-character lowercase hex ID (matches Elementor's format). */
	private function uid(): string {
		return substr( md5( uniqid( '', true ) ), 0, 7 );
	}
}
