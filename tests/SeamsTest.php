<?php

namespace DiviElementorConverter\Tests;

use PHPUnit\Framework\TestCase;
use DiviElementorConverter\Converter\DiviParser;
use DiviElementorConverter\Converter\ElementorBuilder;
use DiviElementorConverter\Admin\BatchImporter;

class SeamsTest extends TestCase {

	protected function setUp(): void {
		jhmg_test_reset_hooks();
	}

	// -----------------------------------------------------------------------
	// jhmgcofo_convert_module
	// -----------------------------------------------------------------------

	public function test_convert_module_filter_short_circuits(): void {
		// wc_price is already in the free WIDGET_MAP, so it would pass with or without
		// the seam and wouldn't actually prove the filter is wired. Use a module tag
		// that has no built-in mapping (falls back to the 'html' widget) so a green
		// result here can only mean the filter short-circuited convert_module().
		add_filter(
			'jhmgcofo_convert_module',
			function ( $v, $node ) {
				return $node->tag === 'some_future_pro_widget'
					? [ 'id' => 'x1', 'elType' => 'widget', 'widgetType' => 'woocommerce-product-price', 'settings' => [] ]
					: $v;
			},
			10,
			2
		);

		$parser  = new DiviParser();
		$builder = new ElementorBuilder();

		// Minimal section>row>column>unknown-module tree, in the same shortcode style
		// used throughout ElementorBuilderTest.
		$sc = '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_some_future_pro_widget][/et_pb_some_future_pro_widget][/et_pb_column][/et_pb_row][/et_pb_section]';

		$out  = $builder->build( $parser->parse_shortcodes( $sc ) );
		$json = json_encode( $out );

		$this->assertStringContainsString( 'woocommerce-product-price', $json );
		$this->assertStringNotContainsString( 'some_future_pro_widget', $json );
	}

	// -----------------------------------------------------------------------
	// jhmgcofo_max_layouts
	// -----------------------------------------------------------------------

	/** Two-layout et_builder_layouts export, per DiviParserTest's fixture shapes. */
	private function two_layout_json(): string {
		$row = '[et_pb_row][et_pb_column type="4_4"][et_pb_text]x[/et_pb_text][/et_pb_column][/et_pb_row]';
		$sc  = "[et_pb_section]{$row}[/et_pb_section]";

		return json_encode(
			[
				'context' => 'et_builder_layouts',
				'data'    => [
					[ 'post_title' => 'Layout One', 'post_content' => $sc ],
					[ 'post_title' => 'Layout Two', 'post_content' => $sc ],
				],
			]
		);
	}

	public function test_max_layouts_filter_caps_import_and_reports(): void {
		// Two-layout input; default filter (1) => one post + a warning naming Pro.
		$importer = new BatchImporter();
		$results  = $importer->import( $this->two_layout_json(), 'two-layouts.json' );

		$this->assertCount( 1, $results );
		// json_encode() escapes forward slashes by default; decode that so the raw
		// URL substring assertion below matches the literal upsell URL.
		$this->assertStringContainsString(
			'divi5lab.com/plugins/divi-to-elementor',
			json_encode( $results, JSON_UNESCAPED_SLASHES )
		);
	}

	public function test_max_layouts_uncapped_when_filtered(): void {
		add_filter( 'jhmgcofo_max_layouts', fn() => PHP_INT_MAX );

		$importer = new BatchImporter();
		$results  = $importer->import( $this->two_layout_json(), 'two-layouts.json' );

		$this->assertCount( 2, $results );
	}

	// -----------------------------------------------------------------------
	// jhmgcofo_loaded
	// -----------------------------------------------------------------------

	public function test_loaded_action_fires(): void {
		$seen = null;
		add_action(
			'jhmgcofo_loaded',
			function ( $p ) use ( &$seen ) {
				$seen = $p;
			}
		);

		// is_admin() stubs false, so register_hooks() skips AdminPage — safe to call directly.
		\DiviElementorConverter\Plugin::instance()->register_hooks();

		$this->assertNotNull( $seen );
		$this->assertInstanceOf( \DiviElementorConverter\Plugin::class, $seen );
	}
}
