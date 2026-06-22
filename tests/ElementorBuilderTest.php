<?php

namespace DiviElementorConverter\Tests;

use DiviElementorConverter\Converter\DiviParser;
use DiviElementorConverter\Converter\ElementorBuilder;
use PHPUnit\Framework\TestCase;

class ElementorBuilderTest extends TestCase {

	private DiviParser       $parser;
	private ElementorBuilder $builder;

	protected function setUp(): void {
		$this->parser  = new DiviParser();
		$this->builder = new ElementorBuilder();
	}

	/** Parse shortcode → build Elementor JSON in one step. */
	private function build( string $sc ): array {
		return $this->builder->build( $this->parser->parse_shortcodes( $sc ) );
	}

	// -----------------------------------------------------------------------
	// Structure
	// -----------------------------------------------------------------------

	public function test_single_section_produces_one_elementor_section(): void {
		$result = $this->build(
			'[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]Hi[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]'
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 'section', $result[0]['elType'] );
	}

	public function test_two_rows_in_one_divi_section_produce_inner_sections(): void {
		$row    = '[et_pb_row][et_pb_column type="4_4"][et_pb_text]x[/et_pb_text][/et_pb_column][/et_pb_row]';
		$result = $this->build( "[et_pb_section]{$row}{$row}[/et_pb_section]" );

		// Two rows in one Divi section → one outer Elementor section with two inner sections.
		// This keeps the section background from repeating visually.
		$this->assertCount( 1, $result );
		$this->assertSame( 'section', $result[0]['elType'] );
		$wrapper_col    = $result[0]['elements'][0];
		$inner_sections = $wrapper_col['elements'];
		$this->assertCount( 2, $inner_sections );
		$this->assertTrue( $inner_sections[0]['isInner'] );
		$this->assertTrue( $inner_sections[1]['isInner'] );
	}

	public function test_two_divi_sections_produce_two_elementor_sections(): void {
		$inner  = '[et_pb_row][et_pb_column type="4_4"][et_pb_text]x[/et_pb_text][/et_pb_column][/et_pb_row]';
		$result = $this->build( "[et_pb_section]{$inner}[/et_pb_section][et_pb_section]{$inner}[/et_pb_section]" );

		$this->assertCount( 2, $result );
	}

	public function test_element_ids_are_7_char_hex(): void {
		$result = $this->build(
			'[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]A[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]'
		);
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{7}$/', $result[0]['id'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{7}$/', $result[0]['elements'][0]['id'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{7}$/', $result[0]['elements'][0]['elements'][0]['id'] );
	}

	public function test_all_element_ids_are_unique(): void {
		$row    = '[et_pb_row][et_pb_column type="4_4"][et_pb_text]x[/et_pb_text][et_pb_text]y[/et_pb_text][/et_pb_column][/et_pb_row]';
		$result = $this->build( "[et_pb_section]{$row}[/et_pb_section]" );

		$ids = [
			$result[0]['id'],
			$result[0]['elements'][0]['id'],
			$result[0]['elements'][0]['elements'][0]['id'],
			$result[0]['elements'][0]['elements'][1]['id'],
		];
		$this->assertCount( 4, array_unique( $ids ) );
	}

	// -----------------------------------------------------------------------
	// Columns
	// -----------------------------------------------------------------------

	public function test_full_width_column(): void {
		$result = $this->build(
			'[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]x[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]'
		);
		$col = $result[0]['elements'][0];
		$this->assertSame( 'column', $col['elType'] );
		$this->assertSame( 100, $col['settings']['_column_size'] );
	}

	public function test_half_columns(): void {
		$result = $this->build(
			'[et_pb_section][et_pb_row][et_pb_column type="1_2"][et_pb_text]A[/et_pb_text][/et_pb_column][et_pb_column type="1_2"][et_pb_text]B[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]'
		);
		$cols = $result[0]['elements'];
		$this->assertCount( 2, $cols );
		$this->assertSame( 50, $cols[0]['settings']['_column_size'] );
		$this->assertSame( 50, $cols[1]['settings']['_column_size'] );
	}

	public function test_third_columns(): void {
		$col = '[et_pb_column type="1_3"][et_pb_text]x[/et_pb_text][/et_pb_column]';
		$result = $this->build(
			"[et_pb_section][et_pb_row]{$col}{$col}{$col}[/et_pb_row][/et_pb_section]"
		);
		foreach ( $result[0]['elements'] as $c ) {
			$this->assertSame( 33, $c['settings']['_column_size'] );
		}
	}

	// -----------------------------------------------------------------------
	// Widgets
	// -----------------------------------------------------------------------

	public function test_text_widget(): void {
		$result = $this->build(
			'[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]<p>Hello world</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]'
		);
		$w = $result[0]['elements'][0]['elements'][0];
		$this->assertSame( 'widget', $w['elType'] );
		$this->assertSame( 'text-editor', $w['widgetType'] );
		$this->assertSame( '<p>Hello world</p>', $w['settings']['editor'] );
	}

	public function test_heading_widget(): void {
		$result = $this->build(
			'[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_heading title="My Title" title_level="h3"][/et_pb_heading][/et_pb_column][/et_pb_row][/et_pb_section]'
		);
		$w = $result[0]['elements'][0]['elements'][0];
		$this->assertSame( 'heading', $w['widgetType'] );
		$this->assertSame( 'My Title', $w['settings']['title'] );
		$this->assertSame( 'h3', $w['settings']['header_size'] );
	}

	public function test_button_widget(): void {
		$result = $this->build(
			'[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_button button_text="Click" button_url="https://example.com"][/et_pb_button][/et_pb_column][/et_pb_row][/et_pb_section]'
		);
		$w = $result[0]['elements'][0]['elements'][0];
		$this->assertSame( 'button', $w['widgetType'] );
		$this->assertSame( 'Click', $w['settings']['text'] );
		$this->assertSame( 'https://example.com', $w['settings']['link']['url'] );
	}

	public function test_image_widget(): void {
		$result = $this->build(
			'[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_image src="https://example.com/img.jpg" alt_text="Alt"][/et_pb_image][/et_pb_column][/et_pb_row][/et_pb_section]'
		);
		$w = $result[0]['elements'][0]['elements'][0];
		$this->assertSame( 'image', $w['widgetType'] );
		$this->assertSame( 'https://example.com/img.jpg', $w['settings']['image']['url'] );
		$this->assertSame( 'Alt', $w['settings']['image']['alt'] );
	}

	public function test_accordion_widget(): void {
		$result = $this->build(
			'[et_pb_section][et_pb_row][et_pb_column type="4_4"]'
			. '[et_pb_accordion]'
			. '[et_pb_accordion_item title="Q1"]<p>A1</p>[/et_pb_accordion_item]'
			. '[et_pb_accordion_item title="Q2"]<p>A2</p>[/et_pb_accordion_item]'
			. '[/et_pb_accordion]'
			. '[/et_pb_column][/et_pb_row][/et_pb_section]'
		);
		$w = $result[0]['elements'][0]['elements'][0];
		$this->assertSame( 'accordion', $w['widgetType'] );
		$this->assertCount( 2, $w['settings']['tabs'] );
		$this->assertSame( 'Q1', $w['settings']['tabs'][0]['tab_title'] );
		$this->assertSame( '<p>A1</p>', $w['settings']['tabs'][0]['tab_content'] );
	}

	public function test_toggle_widget(): void {
		$result = $this->build(
			'[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_toggle title="FAQ"]<p>Answer</p>[/et_pb_toggle][/et_pb_column][/et_pb_row][/et_pb_section]'
		);
		$w = $result[0]['elements'][0]['elements'][0];
		$this->assertSame( 'accordion', $w['widgetType'] );
		$this->assertSame( 'FAQ', $w['settings']['tabs'][0]['tab_title'] );
		$this->assertSame( '<p>Answer</p>', $w['settings']['tabs'][0]['tab_content'] );
	}

	public function test_social_media_follow_widget(): void {
		$result = $this->build(
			'[et_pb_section][et_pb_row][et_pb_column type="4_4"]'
			. '[et_pb_social_media_follow]'
			. '[et_pb_social_media_follow_network social_network="youtube" url="https://youtube.com"]yt[/et_pb_social_media_follow_network]'
			. '[/et_pb_social_media_follow]'
			. '[/et_pb_column][/et_pb_row][/et_pb_section]'
		);
		$w = $result[0]['elements'][0]['elements'][0];
		$this->assertSame( 'social-icons', $w['widgetType'] );
		$this->assertCount( 1, $w['settings']['social_icon_list'] );
		$this->assertSame( 'fab fa-youtube', $w['settings']['social_icon_list'][0]['social_icon'] );
		$this->assertSame( 'https://youtube.com', $w['settings']['social_icon_list'][0]['link']['url'] );
	}

	public function test_menu_widget_maps_to_ekit_nav_menu(): void {
		$result = $this->build(
			'[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_menu menu_id="3"][/et_pb_menu][/et_pb_column][/et_pb_row][/et_pb_section]'
		);
		$w = $result[0]['elements'][0]['elements'][0];
		$this->assertSame( 'ekit-nav-menu', $w['widgetType'] );
		$this->assertSame( '3', $w['settings']['elementskit_nav_menu'] );
	}

	public function test_fullwidth_menu_also_maps_to_ekit_nav_menu(): void {
		$result = $this->build(
			'[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_fullwidth_menu menu_id="5"][/et_pb_fullwidth_menu][/et_pb_column][/et_pb_row][/et_pb_section]'
		);
		$w = $result[0]['elements'][0]['elements'][0];
		$this->assertSame( 'ekit-nav-menu', $w['widgetType'] );
		$this->assertSame( '5', $w['settings']['elementskit_nav_menu'] );
	}

	public function test_unknown_module_falls_back_to_html_widget(): void {
		$result = $this->build(
			'[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_some_future_module][/et_pb_some_future_module][/et_pb_column][/et_pb_row][/et_pb_section]'
		);
		$w = $result[0]['elements'][0]['elements'][0];
		$this->assertSame( 'html', $w['widgetType'] );
		$this->assertStringContainsString( 'some_future_module', $w['settings']['html'] );
	}

	public function test_empty_text_has_paragraph_fallback(): void {
		$result = $this->build(
			'[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text][/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]'
		);
		$w = $result[0]['elements'][0]['elements'][0];
		$this->assertSame( '<p></p>', $w['settings']['editor'] );
	}

	// -----------------------------------------------------------------------
	// Responsive visibility (disabled_on)
	// -----------------------------------------------------------------------

	public function test_row_disabled_on_desktop_tablet_hides_elementor_section(): void {
		// disabled_on="on|on|off" → Divi format is phone|tablet|desktop,
		// so on|on|off = hidden on phone + tablet, visible on desktop (desktop-only row).
		$sc = '[et_pb_section][et_pb_row disabled_on="on|on|off"][et_pb_column type="4_4"][et_pb_text]x[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';
		$result = $this->build( $sc );

		$this->assertSame( 'hidden-mobile',  $result[0]['settings']['hide_mobile'] );
		$this->assertSame( 'hidden-tablet',  $result[0]['settings']['hide_tablet'] );
		$this->assertArrayNotHasKey( 'hide_desktop', $result[0]['settings'] );
	}

	public function test_row_disabled_on_mobile_hides_elementor_section_on_mobile(): void {
		// disabled_on="off|off|on" → Divi format is phone|tablet|desktop,
		// so off|off|on = visible on phone + tablet, hidden on desktop (mobile-only row).
		$sc = '[et_pb_section][et_pb_row disabled_on="off|off|on"][et_pb_column type="4_4"][et_pb_text]x[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';
		$result = $this->build( $sc );

		$this->assertArrayNotHasKey( 'hide_mobile',  $result[0]['settings'] );
		$this->assertArrayNotHasKey( 'hide_tablet',  $result[0]['settings'] );
		$this->assertSame( 'hidden-desktop', $result[0]['settings']['hide_desktop'] );
	}

	public function test_all_devices_off_produces_no_hide_settings(): void {
		$sc = '[et_pb_section][et_pb_row disabled_on="off|off|off"][et_pb_column type="4_4"][et_pb_text]x[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';
		$result = $this->build( $sc );

		$this->assertArrayNotHasKey( 'hide_desktop', $result[0]['settings'] );
		$this->assertArrayNotHasKey( 'hide_tablet',  $result[0]['settings'] );
		$this->assertArrayNotHasKey( 'hide_mobile',  $result[0]['settings'] );
	}

	public function test_widget_disabled_on_maps_to_hide_settings(): void {
		// disabled_on="on|off|off" → phone:on, tablet:off, desktop:off → hidden on mobile only.
		$sc = '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text disabled_on="on|off|off"]x[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';
		$result = $this->build( $sc );
		$w = $result[0]['elements'][0]['elements'][0];

		$this->assertSame( 'hidden-mobile',   $w['settings']['hide_mobile'] );
		$this->assertArrayNotHasKey( 'hide_tablet',  $w['settings'] );
		$this->assertArrayNotHasKey( 'hide_desktop', $w['settings'] );
	}

	// -----------------------------------------------------------------------
	// Slider → standalone section
	// -----------------------------------------------------------------------

	public function test_slider_produces_standalone_section_from_first_slide(): void {
		$sc = '[et_pb_section]'
			. '[et_pb_row][et_pb_column type="4_4"]'
			. '[et_pb_slider]'
			. '[et_pb_slide heading="Hello Slider" button_text="Click Here" button_url="https://example.com" background_image="https://example.com/bg.jpg"]<p>Slide body</p>[/et_pb_slide]'
			. '[et_pb_slide heading="Second Slide"][/et_pb_slide]'
			. '[/et_pb_slider]'
			. '[/et_pb_column][/et_pb_row]'
			. '[/et_pb_section]';

		$result = $this->build( $sc );

		// Only the slider section should be emitted (original row had nothing else).
		$this->assertCount( 1, $result );

		$section = $result[0];
		$this->assertSame( 'section', $section['elType'] );

		// Background image from the first slide.
		$this->assertSame( 'classic', $section['settings']['background_background'] );
		$this->assertSame( 'https://example.com/bg.jpg', $section['settings']['background_image']['url'] );

		// One full-width column.
		$this->assertCount( 1, $section['elements'] );
		$col = $section['elements'][0];
		$this->assertSame( 'column', $col['elType'] );
		$this->assertSame( 100, $col['settings']['_column_size'] );

		$widgets = $col['elements'];
		$this->assertCount( 3, $widgets );
		$this->assertSame( 'heading',     $widgets[0]['widgetType'] );
		$this->assertSame( 'Hello Slider', $widgets[0]['settings']['title'] );
		$this->assertSame( 'text-editor', $widgets[1]['widgetType'] );
		$this->assertSame( '<p>Slide body</p>', $widgets[1]['settings']['editor'] );
		$this->assertSame( 'button',      $widgets[2]['widgetType'] );
		$this->assertSame( 'Click Here',  $widgets[2]['settings']['text'] );
		$this->assertSame( 'https://example.com', $widgets[2]['settings']['link']['url'] );
	}

	public function test_slider_beside_other_widgets_emits_both_sections(): void {
		$sc = '[et_pb_section]'
			. '[et_pb_row][et_pb_column type="4_4"]'
			. '[et_pb_slider][et_pb_slide heading="Hi"][/et_pb_slide][/et_pb_slider]'
			. '[et_pb_text]<p>Below</p>[/et_pb_text]'
			. '[/et_pb_column][/et_pb_row]'
			. '[/et_pb_section]';

		$result = $this->build( $sc );

		// Slider section + normal row section both emitted.
		$this->assertCount( 2, $result );
		$this->assertSame( 'section', $result[0]['elType'] );
		$this->assertSame( 'heading',     $result[0]['elements'][0]['elements'][0]['widgetType'] );
		$this->assertSame( 'text-editor', $result[1]['elements'][0]['elements'][0]['widgetType'] );
	}

	// -----------------------------------------------------------------------
	// Real reference file smoke-test
	// -----------------------------------------------------------------------

	public function test_build_from_hp_divi4_reference(): void {
		$json   = file_get_contents( __DIR__ . '/../references/Divi Builder Layouts hp divi 4.json' );
		$nodes  = ( new DiviParser() )->parse_json( $json );
		$result = $this->builder->build( $nodes );

		// hp25 layout has 4 Divi sections. Multi-row sections collapse to one outer
		// Elementor section, so the total is 4 or more.
		$this->assertGreaterThanOrEqual( 4, count( $result ) );

		foreach ( $result as $section ) {
			$this->assertSame( 'section', $section['elType'] );
			$this->assertNotEmpty( $section['elements'], 'Every Elementor section must have at least one column' );
			foreach ( $section['elements'] as $col ) {
				$this->assertSame( 'column', $col['elType'] );
			}
		}

		// Recursively collect all widget types (works for both flat and inner-section layouts).
		$widget_types = [];
		$collect      = static function ( array $elements ) use ( &$collect, &$widget_types ): void {
			foreach ( $elements as $el ) {
				if ( isset( $el['widgetType'] ) ) {
					$widget_types[] = $el['widgetType'];
				}
				$collect( $el['elements'] ?? [] );
			}
		};
		$collect( $result );

		$this->assertContains( 'text-editor', $widget_types, 'hp25 must produce at least one text widget' );
		$this->assertContains( 'button', $widget_types, 'hp25 must produce at least one button widget' );
	}

	public function test_build_from_layout16_case_studies(): void {
		$json   = file_get_contents( __DIR__ . '/../references/Divi Builder Layouts(16).json' );
		$nodes  = ( new DiviParser() )->parse_json( $json );
		$result = $this->builder->build( $nodes );

		// 2 Divi sections: hero (1 row, flat) + case studies (9 rows, outer+inner).
		$this->assertCount( 2, $result );
		foreach ( $result as $s ) {
			$this->assertSame( 'section', $s['elType'] );
			$this->assertNotEmpty( $s['elements'] );
		}

		// Hero section (index 0): gradient background from the section.
		$hero = $result[0];
		$this->assertSame( 'gradient', $hero['settings']['background_background'] );
		$this->assertSame( '#22bd83', $hero['settings']['background_color_a'] );
		$this->assertSame( '#2498d3', $hero['settings']['background_color_b'] );
		$this->assertSame( 90, $hero['settings']['background_gradient_angle'] );
		// Arrow shape divider on bottom.
		$this->assertSame( 'arrow', $hero['settings']['shape_divider_bottom'] );
		$this->assertSame( '#F8F8F8', $hero['settings']['shape_divider_bottom_color'] );
		// Hero section: row padding 0 top / 50px bottom.
		$this->assertSame( '0',  $hero['settings']['padding']['top'] );
		$this->assertSame( '50', $hero['settings']['padding']['bottom'] );

		// Case-studies outer section (index 1): 9 inner sections; first 8 have gap="no"
		// (gutter_width=1). The 9th row uses a different gutter setting.
		$case_outer  = $result[1];
		$inner_secs  = $case_outer['elements'][0]['elements'];
		$this->assertCount( 9, $inner_secs );
		foreach ( array_slice( $inner_secs, 0, 8 ) as $i => $inner ) {
			$this->assertSame( 'no', $inner['settings']['gap'], "Inner section $i should have no column gap" );
		}

		// Recursive widget finder: walks arbitrary nesting depth.
		$find_widget = static function ( array $elements, callable $match ) use ( &$find_widget ): ?array {
			foreach ( $elements as $el ) {
				if ( isset( $el['widgetType'] ) && $match( $el ) ) {
					return $el;
				}
				$found = $find_widget( $el['elements'] ?? [], $match );
				if ( $found !== null ) {
					return $found;
				}
			}
			return null;
		};

		// Buttons have custom background and alignment mapped.
		$btn = $find_widget(
			$result,
			static fn( $w ) => $w['widgetType'] === 'button' && isset( $w['settings']['background_color'] )
		);
		$this->assertNotNull( $btn, 'At least one button must have custom background color' );
		$this->assertSame( 'left', $btn['settings']['align'] );
		$this->assertSame( '25', $btn['settings']['border_radius']['top'] );

		// Dividers have colour and weight mapped.
		$div = $find_widget(
			$result,
			static fn( $w ) => $w['widgetType'] === 'divider' && isset( $w['settings']['color'] )
		);
		$this->assertNotNull( $div, 'At least one divider must have a mapped color' );
		$this->assertSame( 2.0, $div['settings']['weight']['size'] );
		$this->assertSame( 120.0, $div['settings']['width']['size'] );

		// Column-level gradient background (MedRev / brainleaf / 1QBit columns).
		$find_col = static function ( array $elements, callable $match ) use ( &$find_col ): ?array {
			foreach ( $elements as $el ) {
				if ( ( $el['elType'] ?? '' ) === 'column' && $match( $el ) ) {
					return $el;
				}
				$found = $find_col( $el['elements'] ?? [], $match );
				if ( $found !== null ) {
					return $found;
				}
			}
			return null;
		};
		$grad_col = $find_col(
			$result,
			static fn( $c ) => ( $c['settings']['background_background'] ?? '' ) === 'gradient'
		);
		$this->assertNotNull( $grad_col, 'At least one column must have a gradient background' );
	}

	public function test_build_from_rad_websites_reference(): void {
		$json   = file_get_contents( __DIR__ . '/../references/Unstyled Divi Modules - provided by RAD Websites v1.3.json' );
		$nodes  = ( new DiviParser() )->parse_json( $json );
		$result = $this->builder->build( $nodes );

		$this->assertNotEmpty( $result );
		foreach ( $result as $section ) {
			$this->assertSame( 'section', $section['elType'] );
			$this->assertNotEmpty( $section['elements'], 'Every section should have at least one column' );
		}
	}
}
