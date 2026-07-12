<?php
// tests/D2ELicenseClientTest.php
//
// Ported from the sibling repo's tests/LicenseClientTest.php with D2E's own
// product/basename/admin-slug/product-URL/option-prefix values. The
// LicenseClient class itself is a byte-for-byte sync of the canonical
// (transformed only for namespace + text domain by
// scripts/sync-license-client.sh in the layoutlab/site worktree) — these
// tests exist so D2E's own suite proves the synced copy behaves correctly
// under D2E's wiring, independent of the sibling repo's suite.
use PHPUnit\Framework\TestCase;
use DiviElementorConverter\Pro\Licensing\LicenseClient;

class D2ELicenseClientTest extends TestCase {
    private LicenseClient $client;

    protected function setUp(): void {
        jhmg_test_reset_hooks();
        delete_option( 'jhmgcofop_license_key' );
        delete_option( 'jhmgcofop_license_state' );
        delete_option( 'jhmgcofop_update_blocked' );
        $this->client = new LicenseClient(
            'divi-to-elementor-pro',
            '1.0.0',
            'https://divi5lab.com',
            'jhmg-converter-divi-to-elementor-pro/jhmg-converter-divi-to-elementor-pro.php',
            'jhmgcofop-converter',
            'https://divi5lab.com/plugins/divi-to-elementor',
            'jhmgcofop'
        );
    }

    public function test_activate_success_stores_key_and_state(): void {
        jhmg_test_http_queue( [ 'code' => 200, 'body' => [ 'status' => 'active', 'product' => 'divi-to-elementor-pro', 'expires' => '2027-07-11T00:00:00.000Z' ] ] );
        $res = $this->client->activate( 'JHMG-AAAA-BBBB-CCCC-DDDD' );
        $this->assertTrue( $res['ok'] );
        $this->assertSame( 'JHMG-AAAA-BBBB-CCCC-DDDD', $this->client->get_key() );
        $this->assertSame( 'active', $this->client->get_state()['status'] );
        // Wire contract: snake_case params to the right endpoint.
        $call = $GLOBALS['jhmg_test_http']['log'][0];
        $this->assertSame( 'https://divi5lab.com/api/license/activate', $call['url'] );
        $body = json_decode( $call['args']['body'], true );
        $this->assertSame( [ 'key', 'site_url', 'product', 'plugin_version', 'wp_version' ], array_keys( $body ) );
        // Storage NAMES are jhmgcofop_-prefixed (option-prefix isolation).
        $this->assertSame( 'JHMG-AAAA-BBBB-CCCC-DDDD', get_option( 'jhmgcofop_license_key' ) );
    }

    public function test_activate_invalid_key_reports_error(): void {
        jhmg_test_http_queue( [ 'code' => 404, 'body' => [ 'error' => 'invalid_key' ] ] );
        $res = $this->client->activate( 'JHMG-ZZZZ-ZZZZ-ZZZZ-ZZZZ' );
        $this->assertFalse( $res['ok'] );
        $this->assertSame( 'invalid_key', $res['error'] );
        $this->assertNull( $this->client->get_key() );
    }

    public function test_refresh_skips_within_24h_cache(): void {
        update_option( 'jhmgcofop_license_key', 'JHMG-AAAA-BBBB-CCCC-DDDD' );
        update_option( 'jhmgcofop_license_state', [ 'status' => 'active', 'expires' => null, 'checked_at' => time() - 100 ] );
        $this->client->refresh();
        $this->assertCount( 0, $GLOBALS['jhmg_test_http']['log'] );
    }

    public function test_refresh_network_failure_within_grace_keeps_state(): void {
        update_option( 'jhmgcofop_license_key', 'JHMG-AAAA-BBBB-CCCC-DDDD' );
        update_option( 'jhmgcofop_license_state', [ 'status' => 'active', 'expires' => null, 'checked_at' => time() - 2 * DAY_IN_SECONDS ] );
        jhmg_test_http_queue( new WP_Error( 'http_failure', 'timeout' ) );
        $this->client->refresh();
        $this->assertSame( 'active', $this->client->get_state()['status'] );
    }

    public function test_inject_update_adds_package_when_licensed(): void {
        update_option( 'jhmgcofop_license_key', 'JHMG-AAAA-BBBB-CCCC-DDDD' );
        jhmg_test_http_queue( [ 'code' => 200, 'body' => [ 'update' => true, 'version' => '1.1.0', 'changelog' => 'x', 'package' => 'https://divi5lab.com/api/plugin/download?product=divi-to-elementor-pro&key=JHMG-AAAA-BBBB-CCCC-DDDD' ] ] );
        $t = $this->client->inject_update( (object) [ 'response' => [] ] );
        $entry = $t->response['jhmg-converter-divi-to-elementor-pro/jhmg-converter-divi-to-elementor-pro.php'];
        $this->assertSame( '1.1.0', $entry->new_version );
        $this->assertStringContainsString( '/api/plugin/download', $entry->package );
        $this->assertSame( 'https://divi5lab.com/plugins/divi-to-elementor', $entry->url );
    }

    public function test_inject_update_without_package_sets_renewal_flag(): void {
        update_option( 'jhmgcofop_license_key', 'JHMG-AAAA-BBBB-CCCC-DDDD' );
        jhmg_test_http_queue( [ 'code' => 200, 'body' => [ 'update' => true, 'version' => '1.1.0', 'changelog' => 'x' ] ] );
        $t = $this->client->inject_update( (object) [ 'response' => [] ] );
        $this->assertArrayNotHasKey( 'jhmg-converter-divi-to-elementor-pro/jhmg-converter-divi-to-elementor-pro.php', $t->response );
        $this->assertNotEmpty( get_option( 'jhmgcofop_update_blocked' ) );
    }

    // ------------------------------------------------------------------
    // A.1 — update-check caching
    // ------------------------------------------------------------------

    public function test_inject_update_caches_update_check_and_skips_second_http_call(): void {
        update_option( 'jhmgcofop_license_key', 'JHMG-AAAA-BBBB-CCCC-DDDD' );
        jhmg_test_http_queue( [ 'code' => 200, 'body' => [ 'update' => true, 'version' => '1.1.0', 'changelog' => 'x', 'package' => 'https://divi5lab.com/api/plugin/download?product=x' ] ] );

        $t1 = $this->client->inject_update( (object) [ 'response' => [] ] );
        $this->assertCount( 1, $GLOBALS['jhmg_test_http']['log'] );
        $entry = $t1->response['jhmg-converter-divi-to-elementor-pro/jhmg-converter-divi-to-elementor-pro.php'];
        $this->assertSame( '1.1.0', $entry->new_version );

        // Second call: same product|version|key — must hit the transient cache, no new HTTP.
        $t2 = $this->client->inject_update( (object) [ 'response' => [] ] );
        $this->assertCount( 1, $GLOBALS['jhmg_test_http']['log'] );
        $entry2 = $t2->response['jhmg-converter-divi-to-elementor-pro/jhmg-converter-divi-to-elementor-pro.php'];
        $this->assertSame( '1.1.0', $entry2->new_version );
    }

    public function test_force_refresh_busts_update_check_cache(): void {
        update_option( 'jhmgcofop_license_key', 'JHMG-AAAA-BBBB-CCCC-DDDD' );
        update_option( 'jhmgcofop_license_state', [ 'status' => 'active', 'expires' => null, 'checked_at' => time() ] );
        jhmg_test_http_queue( [ 'code' => 200, 'body' => [ 'update' => true, 'version' => '1.1.0', 'changelog' => 'x', 'package' => 'https://divi5lab.com/api/plugin/download?product=x' ] ] );
        $this->client->inject_update( (object) [ 'response' => [] ] );
        $this->assertCount( 1, $GLOBALS['jhmg_test_http']['log'] );

        // Force refresh (e.g. "Check again") must bust the update-check transient too.
        jhmg_test_http_queue( [ 'code' => 200, 'body' => [ 'status' => 'active', 'product' => 'divi-to-elementor-pro', 'expires' => null ] ] );
        $this->client->refresh( true );

        jhmg_test_http_queue( [ 'code' => 200, 'body' => [ 'update' => true, 'version' => '1.2.0', 'changelog' => 'y', 'package' => 'https://divi5lab.com/api/plugin/download?product=x' ] ] );
        $t = $this->client->inject_update( (object) [ 'response' => [] ] );
        $entry = $t->response['jhmg-converter-divi-to-elementor-pro/jhmg-converter-divi-to-elementor-pro.php'];
        $this->assertSame( '1.2.0', $entry->new_version );
    }

    // ------------------------------------------------------------------
    // A.2 — resilient refresh() on transient server errors
    // ------------------------------------------------------------------

    public function test_refresh_rate_limited_keeps_last_state(): void {
        update_option( 'jhmgcofop_license_key', 'JHMG-AAAA-BBBB-CCCC-DDDD' );
        $checked_at = time() - 2 * DAY_IN_SECONDS;
        update_option( 'jhmgcofop_license_state', [ 'status' => 'active', 'expires' => null, 'checked_at' => $checked_at ] );
        jhmg_test_http_queue( [ 'code' => 429, 'body' => [ 'error' => 'rate_limited' ] ] );

        $this->client->refresh();

        $state = $this->client->get_state();
        $this->assertSame( 'active', $state['status'] );
        $this->assertSame( $checked_at, $state['checked_at'] );
    }

    public function test_refresh_server_error_keeps_last_state(): void {
        update_option( 'jhmgcofop_license_key', 'JHMG-AAAA-BBBB-CCCC-DDDD' );
        $checked_at = time() - 2 * DAY_IN_SECONDS;
        update_option( 'jhmgcofop_license_state', [ 'status' => 'active', 'expires' => null, 'checked_at' => $checked_at ] );
        jhmg_test_http_queue( [ 'code' => 503, 'body' => [ 'error' => 'server_error' ] ] );

        $this->client->refresh();

        $state = $this->client->get_state();
        $this->assertSame( 'active', $state['status'] );
        $this->assertSame( $checked_at, $state['checked_at'] );
    }

    public function test_refresh_definitive_invalid_key_updates_state(): void {
        update_option( 'jhmgcofop_license_key', 'JHMG-AAAA-BBBB-CCCC-DDDD' );
        update_option( 'jhmgcofop_license_state', [ 'status' => 'active', 'expires' => null, 'checked_at' => time() - 2 * DAY_IN_SECONDS ] );
        jhmg_test_http_queue( [ 'code' => 404, 'body' => [ 'error' => 'invalid_key' ] ] );

        $this->client->refresh();

        $state = $this->client->get_state();
        $this->assertSame( 'invalid', $state['status'] );
    }

    // ------------------------------------------------------------------
    // A.3 — WP auto-update compatibility
    // ------------------------------------------------------------------

    public function test_inject_update_response_entry_has_plugin_and_id(): void {
        update_option( 'jhmgcofop_license_key', 'JHMG-AAAA-BBBB-CCCC-DDDD' );
        jhmg_test_http_queue( [ 'code' => 200, 'body' => [ 'update' => true, 'version' => '1.1.0', 'changelog' => 'x', 'package' => 'https://divi5lab.com/api/plugin/download?product=x' ] ] );
        $t = $this->client->inject_update( (object) [ 'response' => [] ] );
        $entry = $t->response['jhmg-converter-divi-to-elementor-pro/jhmg-converter-divi-to-elementor-pro.php'];
        $this->assertSame( 'jhmg-converter-divi-to-elementor-pro/jhmg-converter-divi-to-elementor-pro.php', $entry->plugin );
        $this->assertSame( 'jhmg-converter-divi-to-elementor-pro/jhmg-converter-divi-to-elementor-pro.php', $entry->id );
    }

    public function test_inject_update_sets_no_update_entry_when_no_update_available(): void {
        update_option( 'jhmgcofop_license_key', 'JHMG-AAAA-BBBB-CCCC-DDDD' );
        jhmg_test_http_queue( [ 'code' => 200, 'body' => [ 'update' => false ] ] );
        $t = $this->client->inject_update( (object) [ 'response' => [] ] );
        $this->assertObjectHasProperty( 'no_update', $t );
        $entry = $t->no_update['jhmg-converter-divi-to-elementor-pro/jhmg-converter-divi-to-elementor-pro.php'];
        $this->assertSame( 'jhmg-converter-divi-to-elementor-pro/jhmg-converter-divi-to-elementor-pro.php', $entry->plugin );
        $this->assertSame( 'jhmg-converter-divi-to-elementor-pro', $entry->slug );
        $this->assertSame( '1.0.0', $entry->new_version );
    }

    // ------------------------------------------------------------------
    // option_prefix — cross-plugin storage isolation
    // ------------------------------------------------------------------

    public function test_option_prefix_scopes_license_storage_per_product(): void {
        // A differently-prefixed client (e.g. the E2D5 sibling Pro plugin,
        // 'edcp', co-installed on the same site) must not see or clobber
        // this client's ('jhmgcofop') license storage.
        $other = new LicenseClient(
            'elementor-to-divi5-pro',
            '1.0.0',
            'https://divi5lab.com',
            'jhmg-converter-for-elementor-to-divi-pro/jhmg-converter-for-elementor-to-divi-pro.php',
            'edcp-kit',
            'https://divi5lab.com/plugins/elementor-to-divi-5',
            'edcp'
        );

        jhmg_test_http_queue( [ 'code' => 200, 'body' => [ 'status' => 'active', 'product' => 'elementor-to-divi5-pro', 'expires' => null ] ] );
        $other->activate( 'JHMG-OTHER-KEY' );

        $this->assertNull( $this->client->get_key() );
        $this->assertSame( 'JHMG-OTHER-KEY', $other->get_key() );
    }
}
