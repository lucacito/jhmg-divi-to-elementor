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

    // -----------------------------------------------------------------------
    // TB rejection → admin error notice routing (review fix).
    //
    // handle_import() itself is not headlessly testable (check_admin_referer /
    // wp_safe_redirect / exit), so these tests pin the two seams the routing is
    // built from: (a) the exact-match condition — a TB import's failed result
    // error must be IDENTICAL to DiviParser::THEME_BUILDER_PRO_MESSAGE, which
    // is what AdminPage::handle_import() compares against to divert the result
    // to the notice path; (b) the notice mechanism — set_notice()/render_notice()
    // produce a consumed-once notice-error banner carrying the upsell URL.
    // Task 8's e2e must eyeball the full upload→notice flow in wp-admin.
    // -----------------------------------------------------------------------

    public function test_tb_rejection_error_exactly_matches_parser_constant_used_for_notice_routing(): void {
        // The constant must be public (AdminPage reads it) and self-contained
        // (message + upsell URL).
        $message = DiviParser::THEME_BUILDER_PRO_MESSAGE;
        $this->assertStringContainsString( 'Theme Builder exports require the Pro add-on', $message );
        $this->assertStringContainsString( 'divi-to-elementor?utm_source=plugin&utm_medium=upsell', $message );

        // BatchImporter propagates the parser's message verbatim into the failed
        // result — the exact-match routing in AdminPage::handle_import() relies
        // on this identity.
        $importer = new BatchImporter();
        $results  = $importer->import( $this->theme_builder_json(), 'tb-export.json' );
        $this->assertSame( $message, $results[0]['error'] );

        // And handle_import() actually contains the routing (source-level pin,
        // since the method itself cannot run headlessly).
        $source = file_get_contents( $this->free_dir() . 'includes/admin/class-admin-page.php' );
        $this->assertStringContainsString( 'THEME_BUILDER_PRO_MESSAGE', $source );
    }

    public function test_admin_notice_roundtrip_renders_error_banner_with_upsell_and_is_consumed(): void {
        $page = new \DiviElementorConverter\Admin\AdminPage();

        $set = new ReflectionMethod( $page, 'set_notice' );
        $set->invoke( $page, 'error', DiviParser::THEME_BUILDER_PRO_MESSAGE );

        $render = new ReflectionMethod( $page, 'render_notice' );

        ob_start();
        $render->invoke( $page );
        $html = ob_get_clean();

        $this->assertStringContainsString( 'notice-error', $html );
        $this->assertStringContainsString( 'Theme Builder exports require the Pro add-on', $html );
        $this->assertStringContainsString( 'divi-to-elementor?utm_source=plugin', $html );

        // One-shot: the transient is consumed on render.
        ob_start();
        $render->invoke( $page );
        $second = ob_get_clean();
        $this->assertSame( '', $second );
    }
}
