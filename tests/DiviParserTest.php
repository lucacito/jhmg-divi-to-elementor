<?php

namespace DiviElementorConverter\Tests;

use DiviElementorConverter\Converter\DiviParser;
use PHPUnit\Framework\TestCase;

class DiviParserTest extends TestCase {

	private DiviParser $parser;

	protected function setUp(): void {
		$this->parser = new DiviParser();
	}

	// -----------------------------------------------------------------------
	// Basic structure
	// -----------------------------------------------------------------------

	public function test_parse_single_section_row_column_text(): void {
		$sc    = '[et_pb_section fb_built="1"][et_pb_row][et_pb_column type="4_4"][et_pb_text]<p>Hello</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';
		$nodes = $this->parser->parse_shortcodes( $sc );

		$this->assertCount( 1, $nodes );
		$section = $nodes[0];
		$this->assertSame( 'section', $section->tag );
		$this->assertSame( '1', $section->attr( 'fb_built' ) );

		$row = $section->children[0];
		$this->assertSame( 'row', $row->tag );

		$col = $row->children[0];
		$this->assertSame( 'column', $col->tag );
		$this->assertSame( '4_4', $col->attr( 'type' ) );

		$text = $col->children[0];
		$this->assertSame( 'text', $text->tag );
		$this->assertSame( '<p>Hello</p>', $text->content );
	}

	public function test_heading_title_attribute(): void {
		$sc    = '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_heading title="My Title" title_level="h1"][/et_pb_heading][/et_pb_column][/et_pb_row][/et_pb_section]';
		$nodes = $this->parser->parse_shortcodes( $sc );

		$heading = $nodes[0]->children[0]->children[0]->children[0];
		$this->assertSame( 'heading', $heading->tag );
		$this->assertSame( 'My Title', $heading->attr( 'title' ) );
		$this->assertSame( 'h1', $heading->attr( 'title_level' ) );
		$this->assertSame( '', $heading->content );
	}

	public function test_two_columns_in_one_row(): void {
		$sc    = '[et_pb_section][et_pb_row][et_pb_column type="1_2"][et_pb_text]A[/et_pb_text][/et_pb_column][et_pb_column type="1_2"][et_pb_text]B[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';
		$nodes = $this->parser->parse_shortcodes( $sc );

		$row = $nodes[0]->children[0];
		$this->assertCount( 2, $row->children );
		$this->assertSame( '1_2', $row->children[0]->attr( 'type' ) );
		$this->assertSame( '1_2', $row->children[1]->attr( 'type' ) );
	}

	public function test_multiple_rows_in_section(): void {
		$sc    = '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]R1[/et_pb_text][/et_pb_column][/et_pb_row][et_pb_row][et_pb_column type="4_4"][et_pb_text]R2[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';
		$nodes = $this->parser->parse_shortcodes( $sc );

		$this->assertCount( 2, $nodes[0]->children );
	}

	public function test_multiple_sections(): void {
		$row  = '[et_pb_row][et_pb_column type="4_4"][et_pb_text]x[/et_pb_text][/et_pb_column][/et_pb_row]';
		$sc   = "[et_pb_section]{$row}[/et_pb_section][et_pb_section]{$row}[/et_pb_section]";
		$nodes = $this->parser->parse_shortcodes( $sc );

		$this->assertCount( 2, $nodes );
		$this->assertSame( 'section', $nodes[1]->tag );
	}

	// -----------------------------------------------------------------------
	// Nested child modules
	// -----------------------------------------------------------------------

	public function test_accordion_with_items(): void {
		$sc = '[et_pb_section][et_pb_row][et_pb_column type="4_4"]'
			. '[et_pb_accordion]'
			. '[et_pb_accordion_item title="Q1"]<p>A1</p>[/et_pb_accordion_item]'
			. '[et_pb_accordion_item title="Q2"]<p>A2</p>[/et_pb_accordion_item]'
			. '[/et_pb_accordion]'
			. '[/et_pb_column][/et_pb_row][/et_pb_section]';

		$nodes     = $this->parser->parse_shortcodes( $sc );
		$accordion = $nodes[0]->children[0]->children[0]->children[0];

		$this->assertSame( 'accordion', $accordion->tag );
		$this->assertCount( 2, $accordion->children );
		$this->assertSame( 'accordion_item', $accordion->children[0]->tag );
		$this->assertSame( 'Q1', $accordion->children[0]->attr( 'title' ) );
		$this->assertSame( '<p>A1</p>', $accordion->children[0]->content );
	}

	public function test_social_media_follow_networks(): void {
		$sc = '[et_pb_section][et_pb_row][et_pb_column type="4_4"]'
			. '[et_pb_social_media_follow]'
			. '[et_pb_social_media_follow_network social_network="youtube" url="https://youtube.com"]yt[/et_pb_social_media_follow_network]'
			. '[et_pb_social_media_follow_network social_network="facebook" url="https://facebook.com"]fb[/et_pb_social_media_follow_network]'
			. '[/et_pb_social_media_follow]'
			. '[/et_pb_column][/et_pb_row][/et_pb_section]';

		$nodes  = $this->parser->parse_shortcodes( $sc );
		$follow = $nodes[0]->children[0]->children[0]->children[0];

		$this->assertSame( 'social_media_follow', $follow->tag );
		$this->assertCount( 2, $follow->children );
		$this->assertSame( 'youtube', $follow->children[0]->attr( 'social_network' ) );
		$this->assertSame( 'https://youtube.com', $follow->children[0]->attr( 'url' ) );
	}

	// -----------------------------------------------------------------------
	// Attributes
	// -----------------------------------------------------------------------

	public function test_hyphenated_attribute_names_are_parsed(): void {
		$sc    = '[et_pb_section][et_pb_row][et_pb_column type="4_4" overflow-x="visible" overflow-y="visible"][et_pb_text]Hi[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';
		$nodes = $this->parser->parse_shortcodes( $sc );

		$col = $nodes[0]->children[0]->children[0];
		$this->assertSame( 'column', $col->tag );
		$this->assertSame( '4_4', $col->attr( 'type' ) );
		$this->assertSame( 'visible', $col->attr( 'overflow-x' ) );
	}

	public function test_default_attr_returns_fallback(): void {
		$sc    = '[et_pb_section][et_pb_row][et_pb_column][/et_pb_column][/et_pb_row][/et_pb_section]';
		$nodes = $this->parser->parse_shortcodes( $sc );
		$col   = $nodes[0]->children[0]->children[0];

		$this->assertSame( 'missing', $col->attr( 'type', 'missing' ) );
	}

	public function test_attribute_with_special_chars(): void {
		$sc    = '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text custom_padding="10px|20px|10px|20px|false|false"]x[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';
		$nodes = $this->parser->parse_shortcodes( $sc );
		$text  = $nodes[0]->children[0]->children[0]->children[0];

		$this->assertSame( '10px|20px|10px|20px|false|false', $text->attr( 'custom_padding' ) );
	}

	// -----------------------------------------------------------------------
	// JSON parsing
	// -----------------------------------------------------------------------

	public function test_parse_json_et_builder(): void {
		$sc   = '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]Hello[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';
		$json = json_encode( [ 'context' => 'et_builder', 'data' => [ '1' => $sc ] ] );

		$nodes = $this->parser->parse_json( $json );
		$this->assertCount( 1, $nodes );
		$this->assertSame( 'section', $nodes[0]->tag );
	}

	public function test_parse_json_et_theme_builder(): void {
		$sc   = '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]TB[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';
		$json = json_encode( [
			'context'   => 'et_theme_builder',
			'layouts'   => [
				'158' => [ 'context' => 'et_builder', 'data' => [ '158' => $sc ] ],
			],
		] );

		$nodes = $this->parser->parse_json( $json );
		$this->assertCount( 1, $nodes );
		$this->assertSame( 'section', $nodes[0]->tag );
	}

	public function test_parse_json_invalid_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->parser->parse_json( 'not json at all' );
	}

	// -----------------------------------------------------------------------
	// Real reference file smoke-test
	// -----------------------------------------------------------------------

	public function test_parse_theme_builder_layouts_returns_role_tagged_results(): void {
		$row = '[et_pb_row][et_pb_column type="4_4"][et_pb_text]x[/et_pb_text][/et_pb_column][/et_pb_row]';
		$sc  = "[et_pb_section]{$row}[/et_pb_section]";

		$json = json_encode( [
			'context'   => 'et_theme_builder',
			'templates' => [
				[
					'title'   => 'Default',
					'layouts' => [
						'header' => [ 'id' => 10, 'enabled' => true ],
						'body'   => [ 'id' => 0,  'enabled' => true ],
						'footer' => [ 'id' => 20, 'enabled' => true ],
					],
				],
			],
			'layouts'   => [
				'10' => [ 'context' => 'et_builder', 'data' => [ '10' => $sc ] ],
				'20' => [ 'context' => 'et_builder', 'data' => [ '20' => $sc ] ],
			],
		] );

		$results = $this->parser->parse_theme_builder_layouts( $json );

		$this->assertCount( 2, $results );

		$roles = array_column( $results, 'role' );
		$this->assertContains( 'header', $roles );
		$this->assertContains( 'footer', $roles );

		foreach ( $results as $entry ) {
			$this->assertNotEmpty( $entry['nodes'] );
			$this->assertSame( 'section', $entry['nodes'][0]->tag );
		}
	}

	public function test_parse_theme_builder_layouts_wrong_context_throws(): void {
		$json = json_encode( [ 'context' => 'et_builder', 'data' => [] ] );
		$this->expectException( \InvalidArgumentException::class );
		$this->parser->parse_theme_builder_layouts( $json );
	}

	public function test_parse_theme_builder_layouts_from_header_footer_reference(): void {
		$json    = file_get_contents( __DIR__ . '/../references/headerandfooterplustemplates.json' );
		$results = $this->parser->parse_theme_builder_layouts( $json );

		$this->assertNotEmpty( $results );

		$roles = array_column( $results, 'role' );
		$this->assertContains( 'header', $roles, 'Must identify at least one header layout' );
		$this->assertContains( 'footer', $roles, 'Must identify at least one footer layout' );

		foreach ( $results as $entry ) {
			$this->assertNotEmpty( $entry['nodes'], "Layout {$entry['id']} ({$entry['role']}) produced no nodes" );
			$this->assertSame( 'section', $entry['nodes'][0]->tag );
		}
	}

	public function test_parse_404_reference_file(): void {
		$json  = file_get_contents( __DIR__ . '/../references/divi-theme-builder-pack-1-404-page-template.json' );
		$nodes = $this->parser->parse_json( $json );

		$this->assertNotEmpty( $nodes );
		foreach ( $nodes as $node ) {
			$this->assertSame( 'section', $node->tag );
		}
	}
}
