<?php
// tests/FilterStubTest.php
use PHPUnit\Framework\TestCase;

class FilterStubTest extends TestCase {
    protected function setUp(): void { jhmg_test_reset_hooks(); }

    public function test_apply_filters_passthrough_when_no_filter(): void {
        $this->assertNull( apply_filters( 'jhmgcofo_kit_globals', null ) );
        $this->assertFalse( apply_filters( 'jhmgcofo_pro_active', false ) );
    }

    public function test_registered_filter_transforms_value(): void {
        add_filter( 'jhmgcofo_pro_active', fn( $v ) => true );
        $this->assertTrue( apply_filters( 'jhmgcofo_pro_active', false ) );
    }

    public function test_filter_receives_extra_args(): void {
        add_filter( 'jhmgcofo_x', fn( $v, $extra ) => $v . $extra, 10, 2 );
        $this->assertSame( 'ab', apply_filters( 'jhmgcofo_x', 'a', 'b' ) );
    }

    public function test_do_action_invokes_callbacks(): void {
        $called = null;
        add_action( 'jhmgcofo_loaded', function ( $arg ) use ( &$called ) { $called = $arg; } );
        do_action( 'jhmgcofo_loaded', 'plugin-instance' );
        $this->assertSame( 'plugin-instance', $called );
    }
}
