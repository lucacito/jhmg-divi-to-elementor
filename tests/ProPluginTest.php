<?php
// tests/ProPluginTest.php
use PHPUnit\Framework\TestCase;

class ProPluginTest extends TestCase {
    protected function setUp(): void {
        jhmg_test_reset_hooks();
    }

    public function test_pro_plugin_class_exists(): void {
        $this->assertTrue( class_exists( \DiviElementorConverter\Pro\Plugin::class ) );
    }

    public function test_pro_plugin_constants_defined(): void {
        $this->assertSame( 'divi-to-elementor-pro', JHMGCOFOP_PRODUCT_SLUG );
        $this->assertSame( '1.0.0', JHMGCOFOP_PLUGIN_VERSION );
    }

    public function test_register_hooks_sets_pro_active_filter(): void {
        // Before register_hooks, the filter should be false.
        $this->assertFalse( apply_filters( 'jhmgcofo_pro_active', false ) );

        // Manually call register_hooks (normally done by init() on plugins_loaded).
        \DiviElementorConverter\Pro\Plugin::instance()->register_hooks();

        // After register_hooks, the filter should be true.
        $this->assertTrue( apply_filters( 'jhmgcofo_pro_active', false ) );
    }

    public function test_pro_plugin_api_base_default(): void {
        $this->assertSame( 'https://divi5lab.com', JHMGCOFOP_API_BASE );
    }
}
