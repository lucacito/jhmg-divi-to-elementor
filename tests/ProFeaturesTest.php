<?php
// tests/ProFeaturesTest.php
use PHPUnit\Framework\TestCase;
use DiviElementorConverter\Converter\DiviParser;
use DiviElementorConverter\Converter\ElementorBuilder;
use DiviElementorConverter\Admin\BatchImporter;

class ProFeaturesTest extends TestCase {

    protected function setUp(): void {
        jhmg_test_reset_hooks();
    }

    // -----------------------------------------------------------------------
    // WooModules — jhmgcofo_convert_module seam
    // -----------------------------------------------------------------------

    /**
     * wc_price is already in the free WIDGET_MAP (it isn't trimmed until Task 6),
     * so this is a round-trip regression check that Pro's WooModules mapping
     * agrees with the free builder end-to-end — not a strict "seam is wired"
     * proof (SeamsTest::test_convert_module_filter_short_circuits already
     * covers that with a tag the free map does not know).
     */
    public function test_wc_price_converts_to_woocommerce_product_price_through_free_builder(): void {
        \DiviElementorConverter\Pro\Plugin::instance()->register_hooks();

        // The WooModules callback must actually be registered on the seam.
        $this->assertNotEmpty( $GLOBALS['jhmg_test_hooks']['jhmgcofo_convert_module'] ?? [] );

        $parser  = new DiviParser();
        $builder = new ElementorBuilder();

        $sc = '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_wc_price][/et_pb_wc_price][/et_pb_column][/et_pb_row][/et_pb_section]';
        $out = $builder->build( $parser->parse_shortcodes( $sc ) );

        $widget = $out[0]['elements'][0]['elements'][0];
        $this->assertSame( 'widget', $widget['elType'] );
        $this->assertSame( 'woocommerce-product-price', $widget['widgetType'] );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', $widget['id'] );
    }

    public function test_wc_cart_notice_maps_to_html_widget_with_cart_shortcode(): void {
        \DiviElementorConverter\Pro\Plugin::instance()->register_hooks();

        $parser  = new DiviParser();
        $builder = new ElementorBuilder();

        $sc = '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_wc_cart_notice][/et_pb_wc_cart_notice][/et_pb_column][/et_pb_row][/et_pb_section]';
        $out = $builder->build( $parser->parse_shortcodes( $sc ) );

        $widget = $out[0]['elements'][0]['elements'][0];
        $this->assertSame( 'html', $widget['widgetType'] );
        $this->assertSame( '[woocommerce_cart]', $widget['settings']['html'] );
    }

    // -----------------------------------------------------------------------
    // jhmgcofo_max_layouts — uncapped batch
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

    public function test_two_layout_import_creates_two_posts_with_pro_hooks_registered(): void {
        \DiviElementorConverter\Pro\Plugin::instance()->register_hooks();

        $importer = new BatchImporter();
        $results  = $importer->import( $this->two_layout_json(), 'two-layouts.json' );

        $this->assertCount( 2, $results );
        foreach ( $results as $result ) {
            $this->assertTrue( $result['success'] );
            $this->assertGreaterThan( 0, $result['post_id'] );
        }
    }

    // -----------------------------------------------------------------------
    // ThemeBuilderImporter
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

    public function test_theme_builder_importer_creates_elementor_library_posts_for_header_and_footer(): void {
        $importer = new \DiviElementorConverter\Pro\Converter\ThemeBuilderImporter();
        $results  = $importer->import( $this->theme_builder_json() );

        $this->assertCount( 2, $results );

        $roles = array_column( $results, 'role' );
        $this->assertContains( 'header', $roles );
        $this->assertContains( 'footer', $roles );

        foreach ( $results as $result ) {
            $this->assertGreaterThan( 0, $result['post_id'] );

            $post = get_post( $result['post_id'] );
            $this->assertSame( 'elementor_library', $post->post_type );

            $data = get_post_meta( $result['post_id'], '_elementor_data', true );
            $this->assertNotSame( '', $data );
            $this->assertIsString( $data );

            $this->assertSame( 'builder', get_post_meta( $result['post_id'], '_elementor_edit_mode', true ) );
            $this->assertSame( 'wp-page', get_post_meta( $result['post_id'], '_elementor_template_type', true ) );
        }
    }

    public function test_theme_builder_importer_wrong_context_throws(): void {
        $importer = new \DiviElementorConverter\Pro\Converter\ThemeBuilderImporter();
        $this->expectException( \InvalidArgumentException::class );
        $importer->import( json_encode( [ 'context' => 'et_builder', 'data' => [] ] ) );
    }

    // -----------------------------------------------------------------------
    // ThemeBuilderImporter::parse_theme_builder_layouts()
    //
    // Moved from tests/DiviParserTest.php (free DiviParser::parse_theme_builder_layouts()
    // is now Pro-only — free's DiviParser throws on et_theme_builder JSON instead,
    // see tests/FreeTrimTest.php). Task 6's brief called for verifying whether
    // Pro's ThemeBuilderImporter depended on free's parser method before deleting
    // it there: it did (import() called $this->parser->parse_theme_builder_layouts()
    // where $parser is free's DiviParser), so the method was ported onto
    // ThemeBuilderImporter itself rather than deleted — these three tests are the
    // same assertions as before, just called on the new home of the method.
    // -----------------------------------------------------------------------

    public function test_parse_theme_builder_layouts_returns_role_tagged_results(): void {
        $importer = new \DiviElementorConverter\Pro\Converter\ThemeBuilderImporter();
        $results  = $importer->parse_theme_builder_layouts( $this->theme_builder_json() );

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
        $importer = new \DiviElementorConverter\Pro\Converter\ThemeBuilderImporter();
        $json     = json_encode( [ 'context' => 'et_builder', 'data' => [] ] );

        $this->expectException( \InvalidArgumentException::class );
        $importer->parse_theme_builder_layouts( $json );
    }

    public function test_parse_theme_builder_layouts_from_header_footer_reference(): void {
        $importer = new \DiviElementorConverter\Pro\Converter\ThemeBuilderImporter();
        $json     = file_get_contents( __DIR__ . '/../references/headerandfooterplustemplates.json' );
        $results  = $importer->parse_theme_builder_layouts( $json );

        $this->assertNotEmpty( $results );

        $roles = array_column( $results, 'role' );
        $this->assertContains( 'header', $roles, 'Must identify at least one header layout' );
        $this->assertContains( 'footer', $roles, 'Must identify at least one footer layout' );

        foreach ( $results as $entry ) {
            $this->assertNotEmpty( $entry['nodes'], "Layout {$entry['id']} ({$entry['role']}) produced no nodes" );
            $this->assertSame( 'section', $entry['nodes'][0]->tag );
        }
    }

    // -----------------------------------------------------------------------
    // ProPage — jhmgcofop_* dispatch collision guard
    //
    // Mirrors the sibling jhmg-elementor-to-divi5 project's Phase 2 lesson
    // (ProKitTest::test_kit_page_dispatch_identifiers_use_edcp_prefix_and_do_not_collide_with_free):
    // free's AdminPage::handle_post() hooks admin_init unscoped and dispatches
    // purely on $_POST['action'] / $_GET['jhmgcofo_action'], and free's
    // plugins_loaded callback runs before Pro's (priority 10 vs 20). If Pro
    // reused free's dispatch strings, every form submitted on Pro's own page
    // would be intercepted by free first.
    // -----------------------------------------------------------------------

    public function test_pro_page_dispatch_identifiers_use_jhmgcofop_prefix_and_do_not_collide_with_free(): void {
        $pro_page = \DiviElementorConverter\Pro\Admin\ProPage::class;
        $free     = \DiviElementorConverter\Admin\AdminPage::class;

        $this->assertStringStartsWith( 'jhmgcofop_', $pro_page::IMPORT_NONCE_ACTION );
        $this->assertStringStartsWith( 'jhmgcofop_', $pro_page::IMPORT_NONCE_NAME );
        $this->assertNotSame( $free::IMPORT_NONCE_ACTION, $pro_page::IMPORT_NONCE_ACTION );
        $this->assertNotSame( $free::IMPORT_NONCE_NAME, $pro_page::IMPORT_NONCE_NAME );
        $this->assertNotSame( $free::MENU_SLUG, $pro_page::MENU_SLUG );

        $this->assertSame( 'jhmgcofop_import', $pro_page::IMPORT_NONCE_ACTION );
        $this->assertSame( 'jhmgcofop_import_nonce', $pro_page::IMPORT_NONCE_NAME );
        $this->assertSame( 'jhmgcofop-converter', $pro_page::MENU_SLUG );

        // Belt and braces (per task-5-brief step 3): grep Pro page source for
        // free's dispatch identifiers — must be zero hits.
        $source = (string) file_get_contents( ( new \ReflectionClass( $pro_page ) )->getFileName() );

        $this->assertStringNotContainsString( 'jhmgcofo_import', $source );
        $this->assertStringNotContainsString( 'jhmgcofo_action', $source );
        $this->assertStringNotContainsString( 'jhmgcofo_publish_', $source );

        $this->assertStringContainsString( 'jhmgcofop_import', $source );
        $this->assertStringContainsString( 'jhmgcofop_action', $source );
        $this->assertStringContainsString( 'jhmgcofop_publish_', $source );
    }

    public function test_pro_plugin_registers_pro_page_only_when_admin(): void {
        // is_admin() stubs false in tests/bootstrap.php, so register_hooks()
        // must not attempt to instantiate ProPage (whose init() touches
        // unstubbed WP admin functions) — safe to call directly, matching
        // SeamsTest::test_loaded_action_fires.
        \DiviElementorConverter\Pro\Plugin::instance()->register_hooks();
        $this->assertFalse( is_admin() );
    }
}
