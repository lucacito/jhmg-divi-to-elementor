<?php

namespace DiviElementorConverter\Tests;

use DiviElementorConverter\Admin\AdminPage;
use DiviElementorConverter\Admin\BatchImporter;
use DiviElementorConverter\Converter\DiviParser;
use DiviElementorConverter\Converter\ElementorBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Guards the wp.org review requirement that an imported Divi export can never
 * put executable content into a site.
 *
 * Everything in a Divi JSON file is attacker-controlled: it is an uploaded file,
 * and the plugin writes its contents into _elementor_data, which Elementor then
 * renders on the frontend. These tests pin the two sinks the review named —
 * html-widget values and custom_css values — plus the smaller attribute sinks
 * that feed the same output.
 */
final class ImportHardeningTest extends TestCase {

	/** Build a layout from a Divi shortcode string and return the Elementor tree. */
	private function build( string $shortcode, ?ElementorBuilder &$builder = null ): array {
		$parser  = new DiviParser();
		$builder = new ElementorBuilder();
		return $builder->build( $parser->parse_shortcodes( $shortcode ) );
	}

	/** Wrap a module in the section/row/column scaffolding the builder expects. */
	private function wrap( string $module ): string {
		return '[et_pb_section][et_pb_row][et_pb_column type="4_4"]' . $module . '[/et_pb_column][/et_pb_row][/et_pb_section]';
	}

	/** Recursively collect one settings key from every element in the tree. */
	private function collect( array $elements, string $key ): array {
		$found = [];
		foreach ( $elements as $el ) {
			if ( isset( $el['settings'][ $key ] ) ) {
				$found[] = $el['settings'][ $key ];
			}
			if ( ! empty( $el['elements'] ) ) {
				$found = array_merge( $found, $this->collect( $el['elements'], $key ) );
			}
		}
		return $found;
	}

	private function call_private( object $obj, string $method, array $args ): mixed {
		return ( new ReflectionMethod( $obj, $method ) )->invokeArgs( $obj, $args );
	}

	// -----------------------------------------------------------------------
	// HTML widget values
	// -----------------------------------------------------------------------

	public function test_code_module_script_tag_is_stripped(): void {
		$result = $this->build(
			$this->wrap( '[et_pb_code]<p>ok</p><script>alert(1)</script>[/et_pb_code]' ),
			$builder
		);

		$html = $this->collect( $result, 'html' );
		$this->assertNotEmpty( $html, 'code module should still produce an html widget' );
		$this->assertStringNotContainsString( '<script', $html[0] );
		$this->assertStringNotContainsString( 'alert(1)', $html[0] );
		$this->assertStringContainsString( '<p>ok</p>', $html[0], 'safe markup must survive' );
	}

	public function test_code_module_event_handler_is_stripped(): void {
		$result = $this->build( $this->wrap( '[et_pb_code]<div onclick="steal()">x</div>[/et_pb_code]' ) );

		$html = $this->collect( $result, 'html' );
		$this->assertStringNotContainsString( 'onclick', $html[0] );
		$this->assertStringNotContainsString( 'steal()', $html[0] );
	}

	public function test_code_module_javascript_url_is_stripped(): void {
		$result = $this->build( $this->wrap( '[et_pb_code]<a href="javascript:alert(1)">go</a>[/et_pb_code]' ) );

		$html = $this->collect( $result, 'html' );
		$this->assertStringNotContainsString( 'javascript:', $html[0] );
	}

	public function test_stripped_code_module_records_a_report_warning(): void {
		$this->build( $this->wrap( '[et_pb_code]<script>alert(1)</script>[/et_pb_code]' ), $builder );

		$warnings = $builder->get_warnings();
		$this->assertNotEmpty( $warnings, 'the user must be told the module was altered' );
		$this->assertStringContainsString( 'removed', strtolower( implode( ' ', $warnings ) ) );
	}

	public function test_safe_code_module_produces_no_warning(): void {
		$this->build( $this->wrap( '[et_pb_code]<p>Just <strong>text</strong>.</p>[/et_pb_code]' ), $builder );

		$this->assertSame( [], $builder->get_warnings(), 'clean markup must not raise a false alarm' );
	}

	public function test_unknown_module_fallback_is_sanitized(): void {
		$result = $this->build( $this->wrap( '[et_pb_not_a_real_module]<script>alert(1)</script>[/et_pb_not_a_real_module]' ) );

		$json = (string) json_encode( $result );
		$this->assertStringNotContainsString( '<script', $json );
	}

	public function test_team_member_description_is_sanitized(): void {
		$result = $this->build( $this->wrap( '[et_pb_team_member name="A"]<script>alert(1)</script>bio[/et_pb_team_member]' ) );

		$json = (string) json_encode( $result );
		$this->assertStringNotContainsString( '<script', $json );
	}

	public function test_pricing_item_content_is_sanitized(): void {
		$module = '[et_pb_pricing_tables][et_pb_pricing_table header="Basic"][et_pb_pricing_item]<script>alert(1)</script>Feature[/et_pb_pricing_item][/et_pb_pricing_table][/et_pb_pricing_tables]';
		$result = $this->build( $this->wrap( $module ) );

		$json = (string) json_encode( $result );
		$this->assertStringNotContainsString( '<script', $json );
	}

	public function test_signup_module_cannot_break_out_of_its_html_comment(): void {
		$result = $this->build( $this->wrap( '[et_pb_signup provider="x--><script>alert(1)</script><!--" title="t"][/et_pb_signup]' ) );

		$json = (string) json_encode( $result );
		$this->assertStringNotContainsString( '<script', $json );
		$this->assertStringNotContainsString( '-->', substr( $json, 0, (int) strpos( $json, 'Email opt-in' ) ?: 0 ) );
	}

	// -----------------------------------------------------------------------
	// custom_css values
	// -----------------------------------------------------------------------

	public function test_custom_css_cannot_close_the_style_element(): void {
		$result = $this->build(
			$this->wrap( '[et_pb_text custom_css_free_form="selector{color:red}</style><script>alert(1)</script>"]hi[/et_pb_text]' )
		);

		$css = implode( "\n", $this->collect( $result, 'custom_css' ) );
		$this->assertStringNotContainsString( '</style>', $css );
		$this->assertStringNotContainsString( '<script', $css );
	}

	public function test_custom_css_strips_script_bodies_not_just_script_tags(): void {
		// Removing <script> and </script> but leaving what sat between them would
		// drop the script's source into the stylesheet as loose text.
		$result = $this->build(
			$this->wrap( '[et_pb_text custom_css_free_form="selector{color:red}<script>alert(1);fetch(\'//evil.test\')</script>"]hi[/et_pb_text]' )
		);

		$css = implode( "\n", $this->collect( $result, 'custom_css' ) );
		$this->assertStringNotContainsString( 'alert', $css );
		$this->assertStringNotContainsString( 'evil.test', $css );
		$this->assertStringContainsString( 'color:red', $css, 'the legitimate rule must survive' );
	}

	public function test_main_element_css_never_produces_a_nested_block(): void {
		// custom_css_main_element is a declaration list, so braces are never
		// legitimate there. Balancing rather than stripping them would turn this
		// payload into a nested block inside the converter's selector wrapper.
		$result = $this->build(
			$this->wrap( '[et_pb_text custom_css_main_element="color:red} body{display:none"]hi[/et_pb_text]' )
		);

		foreach ( $this->collect( $result, 'custom_css' ) as $css ) {
			$this->assertDoesNotMatchRegularExpression( '/\{[^{}]*\{/', $css, 'no nested rule block may appear' );
			$this->assertSame( 1, substr_count( $css, '{' ) );
			$this->assertSame( 1, substr_count( $css, '}' ) );
		}
	}

	public function test_custom_css_import_rule_is_stripped(): void {
		$result = $this->build(
			$this->wrap( '[et_pb_text custom_css_free_form="@import url(https://evil.test/x.css); selector{color:red}"]hi[/et_pb_text]' )
		);

		$css = implode( "\n", $this->collect( $result, 'custom_css' ) );
		$this->assertStringNotContainsString( '@import', $css );
		$this->assertStringNotContainsString( 'evil.test', $css );
	}

	public function test_custom_css_expression_and_binding_vectors_are_stripped(): void {
		$result = $this->build(
			$this->wrap( '[et_pb_text custom_css_free_form="selector{width:expression(alert(1));behavior:url(x.htc);-moz-binding:url(y.xml)}"]hi[/et_pb_text]' )
		);

		$css = strtolower( implode( "\n", $this->collect( $result, 'custom_css' ) ) );
		$this->assertStringNotContainsString( 'expression(', $css );
		$this->assertStringNotContainsString( 'behavior:', $css );
		$this->assertStringNotContainsString( '-moz-binding', $css );
	}

	public function test_custom_css_braces_are_balanced(): void {
		$result = $this->build(
			$this->wrap( '[et_pb_text custom_css_free_form="} body{display:none} selector{color:red"]hi[/et_pb_text]' )
		);

		foreach ( $this->collect( $result, 'custom_css' ) as $css ) {
			$this->assertSame(
				substr_count( $css, '{' ),
				substr_count( $css, '}' ),
				'every custom_css block must be brace-balanced so it cannot escape its selector scope'
			);
		}
	}

	public function test_main_element_css_cannot_escape_its_selector_wrapper(): void {
		$result = $this->build(
			$this->wrap( '[et_pb_text custom_css_main_element="color:red} body{display:none"]hi[/et_pb_text]' )
		);

		foreach ( $this->collect( $result, 'custom_css' ) as $css ) {
			$this->assertSame( substr_count( $css, '{' ), substr_count( $css, '}' ) );
		}
	}

	public function test_generated_css_values_cannot_inject_extra_declarations(): void {
		// The payload text may survive as inert characters inside the one value it
		// was written into — what must not survive is its ability to terminate that
		// declaration (;) or open a rule block of its own ({ }). So assert on
		// structure: one block, one declaration.
		$result = $this->build(
			$this->wrap( '[et_pb_text text_text_color="red;} body{display:none"]<p>hi</p>[/et_pb_text]' )
		);

		$blocks = $this->collect( $result, 'custom_css' );
		$this->assertNotEmpty( $blocks );

		foreach ( $blocks as $css ) {
			$this->assertSame( 1, substr_count( $css, '{' ), 'the injected value must not open a second rule block' );
			$this->assertSame( 1, substr_count( $css, '}' ) );
			$this->assertSame( 1, substr_count( $css, ';' ), 'the injected value must not terminate its own declaration' );
			$this->assertStringNotContainsString( 'body{', str_replace( ' ', '', $css ) );
		}
	}

	// -----------------------------------------------------------------------
	// Attribute sinks
	// -----------------------------------------------------------------------

	public function test_module_id_and_class_are_reduced_to_css_identifiers(): void {
		$result = $this->build(
			$this->wrap( '[et_pb_text module_id="a\" onmouseover=\"x" module_class="b\"><script>"]hi[/et_pb_text]' )
		);

		$json = (string) json_encode( $result );
		$this->assertStringNotContainsString( 'onmouseover', $json );
		$this->assertStringNotContainsString( '<script', $json );
	}

	// -----------------------------------------------------------------------
	// Titles taken from the uploaded file
	// -----------------------------------------------------------------------

	public function test_layout_title_from_json_is_reduced_to_plain_text(): void {
		$json = (string) json_encode( [
			'context' => 'et_builder_layouts',
			'data'    => [
				[
					'post_title'   => '<script>alert(1)</script>Home',
					'post_content' => '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]hi[/et_pb_text][/et_pb_column][/et_pb_row][et_pb_section]',
				],
			],
		] );

		$results = ( new BatchImporter() )->import( $json, 'export.json' );

		$this->assertNotEmpty( $results );
		$this->assertStringNotContainsString( '<script', (string) $results[0]['title'] );
	}

	// -----------------------------------------------------------------------
	// Upload sanitization / validation
	// -----------------------------------------------------------------------

	public function test_sanitize_upload_keeps_only_the_four_expected_members(): void {
		$file = $this->call_private( new AdminPage(), 'sanitize_upload', [
			[
				'name'      => 'my layout.json',
				'tmp_name'  => '/tmp/php123',
				'error'     => '0',
				'size'      => '42',
				'type'      => 'application/json',
				'injected'  => 'should not survive',
			],
		] );

		$this->assertSame( [ 'name', 'tmp_name', 'error', 'size' ], array_keys( $file ) );
		$this->assertSame( 0, $file['error'] );
		$this->assertSame( 42, $file['size'] );
	}

	public function test_sanitize_upload_strips_dangerous_characters_from_the_filename(): void {
		$file = $this->call_private( new AdminPage(), 'sanitize_upload', [
			[ 'name' => '../../<script>evil</script>.json', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 10 ],
		] );

		$this->assertStringNotContainsString( '<', $file['name'] );
		$this->assertStringNotContainsString( '/', $file['name'] );
	}

	public function test_sanitize_upload_tolerates_a_missing_entry(): void {
		$file = $this->call_private( new AdminPage(), 'sanitize_upload', [ [] ] );

		$this->assertSame( '', $file['name'] );
		$this->assertSame( UPLOAD_ERR_NO_FILE, $file['error'] );
	}

	public function test_validate_upload_rejects_a_php_upload_error_first(): void {
		$error = $this->call_private( new AdminPage(), 'validate_upload', [
			[ 'name' => 'layout.json', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0 ],
		] );

		$this->assertNotSame( '', $error );
		$this->assertStringContainsString( 'exceeds', strtolower( $error ) );
	}

	public function test_validate_upload_rejects_a_non_json_extension(): void {
		$error = $this->call_private( new AdminPage(), 'validate_upload', [
			[ 'name' => 'payload.php', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 10 ],
		] );

		$this->assertStringContainsString( '.json', $error );
	}

	public function test_validate_upload_rejects_a_double_extension(): void {
		$error = $this->call_private( new AdminPage(), 'validate_upload', [
			[ 'name' => 'layout.json.php', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 10 ],
		] );

		$this->assertNotSame( '', $error );
	}

	public function test_validate_upload_rejects_an_empty_file(): void {
		$error = $this->call_private( new AdminPage(), 'validate_upload', [
			[ 'name' => 'layout.json', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 0 ],
		] );

		$this->assertStringContainsString( 'empty', strtolower( $error ) );
	}

	public function test_validate_upload_rejects_a_tmp_name_that_was_not_an_http_upload(): void {
		// is_uploaded_file() is false for any path PHP did not receive as an
		// upload — this is what blocks a crafted tmp_name of /etc/passwd.
		$error = $this->call_private( new AdminPage(), 'validate_upload', [
			[ 'name' => 'layout.json', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK, 'size' => 10 ],
		] );

		$this->assertStringContainsString( 'verified', strtolower( $error ) );
	}
}
