<?php
// tests/FreeTrimTest.php
//
// Task 6 (guideline 5 trim): WooCommerce widget mapping, multi-file/multi-layout
// batch, and Theme Builder import move OUT of the free plugin. These tests prove
// the trim: the free source tree no longer contains the Pro-bound code, and the
// free runtime behavior degrades gracefully (report warning / thrown exception)
// with an upsell link instead of silently doing the Pro thing.

use PHPUnit\Framework\TestCase;
use DiviElementorConverter\Converter\DiviParser;
use DiviElementorConverter\Converter\ElementorBuilder;
use DiviElementorConverter\Admin\BatchImporter;

class FreeTrimTest extends TestCase {

    protected function setUp(): void {
        jhmg_test_reset_hooks();
    }

    private function free_dir(): string {
        return dirname( __DIR__ ) . '/plugin/jhmg-converter-divi-to-elementor/';
    }

    // -----------------------------------------------------------------------
    // Static greps — the Pro-bound code must not exist in the free tree.
    // -----------------------------------------------------------------------

    public function test_widget_map_has_no_wc_entries(): void {
        $source = file_get_contents( $this->free_dir() . 'includes/converter/class-elementor-builder.php' );

        $this->assertMatchesRegularExpression( '/private const WIDGET_MAP = \[(.*?)\n\t\];/s', $source );
        preg_match( '/private const WIDGET_MAP = \[(.*?)\n\t\];/s', $source, $m );
        $widget_map_block = $m[1];

        $this->assertStringNotContainsString( "'wc_", $widget_map_block );

        // widget_settings() must not have any wc_* case branches either — only
        // the str_starts_with( $tag, 'wc_' ) skip-with-warning branch (in
        // convert_module(), not widget_settings()) may reference the wc_ prefix.
        $this->assertStringNotContainsString( "case 'wc_", $source );
    }

    public function test_parse_theme_builder_layouts_removed_from_free_tree(): void {
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->free_dir() ) );
        foreach ( $iterator as $file ) {
            if ( $file->getExtension() !== 'php' ) {
                continue;
            }
            $this->assertStringNotContainsString(
                'parse_theme_builder_layouts',
                (string) file_get_contents( $file->getPathname() ),
                $file->getPathname() . ' still references parse_theme_builder_layouts (now Pro-only)'
            );
        }
    }

    public function test_converter_class_file_deleted(): void {
        $this->assertFileDoesNotExist( $this->free_dir() . 'includes/converter/class-converter.php' );
    }

    // -----------------------------------------------------------------------
    // WooCommerce modules degrade to a report warning + upsell, not a widget.
    // -----------------------------------------------------------------------

    public function test_wc_price_module_skipped_by_free_builder_with_upsell_warning(): void {
        $parser  = new DiviParser();
        $builder = new ElementorBuilder();

        $sc = '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_wc_price][/et_pb_wc_price][/et_pb_column][/et_pb_row][/et_pb_section]';
        $out = $builder->build( $parser->parse_shortcodes( $sc ) );

        $json = json_encode( $out, JSON_UNESCAPED_SLASHES );
        $this->assertStringNotContainsString( 'woocommerce-', $json );

        $warnings = $builder->get_warnings();
        $this->assertNotEmpty( $warnings );
        $this->assertStringContainsString( 'divi-to-elementor?utm', $warnings[0] );
        $this->assertStringContainsString( 'wc_price', $warnings[0] );
    }

    public function test_wc_module_warning_reaches_batch_importer_report(): void {
        // Mix a supported module in so the layout still produces output (a
        // layout containing ONLY a skipped wc_* module would legitimately fail
        // with "no sections generated" — that is not what this test covers).
        $sc = '[et_pb_section][et_pb_row][et_pb_column type="4_4"]'
            . '[et_pb_text]Hello[/et_pb_text]'
            . '[et_pb_wc_price][/et_pb_wc_price]'
            . '[/et_pb_column][/et_pb_row][/et_pb_section]';
        $json = json_encode( [ 'context' => 'et_builder', 'data' => [ '1' => $sc ] ] );

        $importer = new BatchImporter();
        $results  = $importer->import( $json, 'wc-mixed.json' );

        $this->assertCount( 1, $results );
        $result = $results[0];
        $this->assertTrue( $result['success'] );
        $this->assertArrayHasKey( 'report', $result );
        $this->assertNotEmpty( $result['report']['warnings'] );
        $this->assertStringContainsString( 'divi-to-elementor?utm', $result['report']['warnings'][0] );

        $data = get_post_meta( $result['post_id'], '_elementor_data', true );
        $this->assertIsString( $data );
        $this->assertStringNotContainsString( 'woocommerce-', $data );
    }

    // -----------------------------------------------------------------------
    // Theme Builder JSON is rejected outright (parse_json + parse_layouts).
    // -----------------------------------------------------------------------

    private function theme_builder_json(): string {
        $row = '[et_pb_row][et_pb_column type="4_4"][et_pb_text]x[/et_pb_text][/et_pb_column][/et_pb_row]';
        $sc  = "[et_pb_section]{$row}[/et_pb_section]";

        return json_encode(
            [
                'context'   => 'et_theme_builder',
                'templates' => [
                    [
                        'title'   => 'Default',
                        'layouts' => [
                            'header' => [ 'id' => 10, 'enabled' => true ],
                            'body'   => [ 'id' => 0, 'enabled' => true ],
                            'footer' => [ 'id' => 20, 'enabled' => true ],
                        ],
                    ],
                ],
                'layouts'   => [
                    '10' => [ 'context' => 'et_builder', 'data' => [ '10' => $sc ] ],
                    '20' => [ 'context' => 'et_builder', 'data' => [ '20' => $sc ] ],
                ],
            ]
        );
    }

    public function test_parse_json_et_theme_builder_throws_pro_required(): void {
        $parser = new DiviParser();

        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/Pro add-on/' );
        $parser->parse_json( $this->theme_builder_json() );
    }

    public function test_parse_layouts_et_theme_builder_throws_pro_required(): void {
        $parser = new DiviParser();

        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/Pro add-on/' );
        $parser->parse_layouts( $this->theme_builder_json() );
    }

    public function test_batch_importer_rejects_theme_builder_export_with_pro_upsell_message(): void {
        $importer = new BatchImporter();
        $results  = $importer->import( $this->theme_builder_json(), 'tb-export.json' );

        $this->assertCount( 1, $results );
        $this->assertFalse( $results[0]['success'] );
        $this->assertStringContainsString( 'Pro add-on', $results[0]['error'] );
        $this->assertStringContainsString( 'divi-to-elementor?utm', $results[0]['error'] );
    }
}
