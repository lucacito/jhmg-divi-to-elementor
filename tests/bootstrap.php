<?php

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Satisfy the guard at the top of every plugin file.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/fake-abspath/' );
}

// Custom autoloader that mirrors the plugin's class-*.php / strtolower-subdir convention.
spl_autoload_register( function ( string $class ): void {
	$prefix = 'DiviElementorConverter\\';

	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}

	$relative   = ltrim( substr( $class, strlen( $prefix ) ), '\\' );
	$parts      = explode( '\\', $relative );
	$class_name = array_pop( $parts );
	$base       = dirname( __DIR__ ) . '/plugin/jhmg-converter-divi-to-elementor/includes/';
	$dir        = $base . ( $parts ? implode( '/', array_map( 'strtolower', $parts ) ) . '/' : '' );
	$file       = 'class-' . strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $class_name ) ) . '.php';
	$path       = $dir . $file;

	if ( ! file_exists( $path ) && empty( $parts ) ) {
		$path = $base . 'helpers/' . $file;
	}

	if ( file_exists( $path ) ) {
		require_once $path;
	}
} );

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return dirname( $file ) . '/';
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'file://' . dirname( $file ) . '/';
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $callback ) {}
}

if ( ! function_exists( 'add_shortcode' ) ) {
	$GLOBALS['__test_shortcodes'] = [];

	function add_shortcode( $tag, $callback ) {
		$GLOBALS['__test_shortcodes'][ $tag ] = $callback;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( '__return_true' ) ) {
	function __return_true() {
		return true;
	}
}

// Filter/action registry used by tests. Reset between tests via jhmg_test_reset_hooks().
$GLOBALS['jhmg_test_hooks'] = [];

if ( ! function_exists( 'jhmg_test_reset_hooks' ) ) {
	function jhmg_test_reset_hooks() {
		$GLOBALS['jhmg_test_hooks'] = [];
		if ( isset( $GLOBALS['__test_options'] ) ) {
			$GLOBALS['__test_options'] = [];
		}
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['jhmg_test_hooks'][ $tag ][] = [ 'cb' => $callback, 'args' => $accepted_args ];
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $tag, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		foreach ( $GLOBALS['jhmg_test_hooks'][ $tag ] ?? [] as $entry ) {
			$value = call_user_func_array( $entry['cb'], array_slice( array_merge( [ $value ], $args ), 0, max( 1, $entry['args'] ) ) );
		}
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) {
		foreach ( $GLOBALS['jhmg_test_hooks'][ $tag ] ?? [] as $entry ) {
			call_user_func_array( $entry['cb'], array_slice( $args, 0, max( 1, $entry['args'] ) ) );
		}
	}
}

// Simple in-memory post meta stubs for tests.
if ( ! function_exists( 'update_post_meta' ) ) {
	$GLOBALS['__test_postmeta'] = [];

	function update_post_meta( $post_id, $meta_key, $meta_value ) {
		$id = (int) $post_id;
		if ( ! isset( $GLOBALS['__test_postmeta'][ $id ] ) ) {
			$GLOBALS['__test_postmeta'][ $id ] = [];
		}
		// Store as a single-value array (overwrites previous single value).
		$GLOBALS['__test_postmeta'][ $id ][ $meta_key ] = [ $meta_value ];
		return true;
	}

	// add_post_meta appends a new value (multiple values per key, like WordPress).
	function add_post_meta( $post_id, $meta_key, $meta_value, $unique = false ) {
		$id = (int) $post_id;
		if ( ! isset( $GLOBALS['__test_postmeta'][ $id ] ) ) {
			$GLOBALS['__test_postmeta'][ $id ] = [];
		}
		if ( ! isset( $GLOBALS['__test_postmeta'][ $id ][ $meta_key ] ) ) {
			$GLOBALS['__test_postmeta'][ $id ][ $meta_key ] = [];
		}
		if ( $unique && ! empty( $GLOBALS['__test_postmeta'][ $id ][ $meta_key ] ) ) {
			return false;
		}
		$GLOBALS['__test_postmeta'][ $id ][ $meta_key ][] = $meta_value;
		return true;
	}

	function get_post_meta( $post_id, $meta_key = '', $single = false ) {
		$id = (int) $post_id;
		if ( $meta_key === '' ) {
			return $GLOBALS['__test_postmeta'][ $id ] ?? [];
		}
		$exists = isset( $GLOBALS['__test_postmeta'][ $id ] ) && array_key_exists( $meta_key, $GLOBALS['__test_postmeta'][ $id ] );
		if ( ! $exists ) {
			return $single ? '' : [];
		}
		$values = $GLOBALS['__test_postmeta'][ $id ][ $meta_key ];
		if ( $single ) {
			return $values[0] ?? '';
		}
		return $values;
	}

	function delete_post_meta( $post_id, $meta_key = '', $meta_value = '' ) {
		$id = (int) $post_id;
		if ( isset( $GLOBALS['__test_postmeta'][ $id ][ $meta_key ] ) ) {
			unset( $GLOBALS['__test_postmeta'][ $id ][ $meta_key ] );
			return true;
		}
		return false;
	}
}

// Minimal WP_Query stub: returns empty results, forcing post creation in Theme Builder logic.
if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public array $posts = [];
		public function __construct( array $args = [] ) {}
		public function have_posts(): bool { return ! empty( $this->posts ); }
	}
}

// Minimal in-memory post storage for integration tests.
if ( ! function_exists( 'wp_insert_post' ) ) {
	$GLOBALS['__test_posts'] = [];
	$GLOBALS['__test_next_post_id'] = 1000;

	function wp_insert_post( $postarr ) {
		$id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : $GLOBALS['__test_next_post_id']++;
		$post = (object) array_merge( [ 'ID' => $id, 'post_type' => $postarr['post_type'] ?? 'post', 'post_content' => $postarr['post_content'] ?? '' ], $postarr );
		$GLOBALS['__test_posts'][ $id ] = $post;
		return $id;
	}

	function wp_update_post( $postarr ) {
		$id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $id <= 0 || ! isset( $GLOBALS['__test_posts'][ $id ] ) ) {
			return 0;
		}

		$post = $GLOBALS['__test_posts'][ $id ];
		foreach ( $postarr as $key => $value ) {
			if ( $key === 'ID' ) {
				continue;
			}
			$post->{$key} = $value;
		}

		$GLOBALS['__test_posts'][ $id ] = $post;

		return $id;
	}

	function get_post( $post_id ) {
		$id = (int) $post_id;
		return $GLOBALS['__test_posts'][ $id ] ?? null;
	}

	function get_post_type( $post = null ) {
		if ( is_object( $post ) && isset( $post->post_type ) ) {
			return $post->post_type;
		}
		$id = (int) $post;
		if ( $id > 0 ) {
			$p = get_post( $id );
			return $p ? $p->post_type : null;
		}
		return null;
	}

	function wp_set_post_terms( $post_id, $terms, $taxonomy ) {
		// No-op for tests.
		return true;
	}

	function wp_set_object_terms( $post_id, $terms, $taxonomy, $append = false ) {
		// No-op for tests.
		return [];
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}

if ( ! function_exists( 'is_customize_preview' ) ) {
	function is_customize_preview() {
		return false;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap = null ) {
		return true;
	}
}

if ( ! function_exists( 'is_singular' ) ) {
	function is_singular() {
		return false;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) {
		return addslashes( is_string( $value ) ? $value : '' );
	}
}

if ( ! function_exists( 'has_block' ) ) {
	function has_block( $block, $post_id = 0 ) {
		return false;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $message;
		public function __construct( string $code = '', string $message = '' ) {
			$this->message = $message;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	$GLOBALS['__test_transients'] = [];

	function set_transient( string $key, $value, int $expiration = 0 ): bool {
		$GLOBALS['__test_transients'][ $key ] = $value;
		return true;
	}

	function get_transient( string $key ) {
		return $GLOBALS['__test_transients'][ $key ] ?? false;
	}

	function delete_transient( string $key ): bool {
		unset( $GLOBALS['__test_transients'][ $key ] );
		return true;
	}
}

// Simple in-memory options store.
if ( ! function_exists( 'get_option' ) ) {
	$GLOBALS['__test_options'] = [];

	function get_option( string $key, $default = false ) {
		return $GLOBALS['__test_options'][ $key ] ?? $default;
	}

	function update_option( string $key, $value, $autoload = null ): bool {
		$GLOBALS['__test_options'][ $key ] = $value;
		return true;
	}

	function delete_option( string $key ): bool {
		unset( $GLOBALS['__test_options'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		return [
			'basedir' => sys_get_temp_dir(),
			'baseurl' => 'file://' . sys_get_temp_dir(),
		];
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( string $file ): void {
		if ( file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme( $template = null ) {
		return new class {
			public function get( $key ) {
				return 'Divi';
			}
		};
	}
}

if ( ! function_exists( 'get_template' ) ) {
	function get_template() {
		return 'Divi';
	}
}

// current_time() is unstubbed elsewhere in this bootstrap (existing callers all
// have a non-empty-title short-circuit that skips it) but Pro's
// ThemeBuilderImporter::import() calls it unconditionally per header/footer
// layout, porting the free plugin's dead Converter::convert_theme_builder().
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql', $gmt = 0 ) {
		if ( $type === 'timestamp' ) {
			return time();
		}
		if ( $type === 'mysql' ) {
			return date( 'Y-m-d H:i:s' );
		}
		return date( $type );
	}
}

// Licensing HTTP stubs (used by Pro\Licensing\LicenseClient).
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '', $scheme = null ) {
		return 'https://test-site.example' . $path;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '', $scheme = 'admin' ) {
		return 'https://test-site.example/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

// HTTP queue stubs used by tests to mock wp_remote_post()/wp_remote_get() responses
// (e.g. Pro licensing HTTP calls). Queue a response via jhmg_test_http_queue().
$GLOBALS['jhmg_test_http'] = [ 'queue' => [], 'log' => [] ];

if ( ! function_exists( 'jhmg_test_http_queue' ) ) {
	/** Queue a fake response: ['code'=>200,'body'=>['status'=>'active',...]] or new WP_Error(...) */
	function jhmg_test_http_queue( $response ) {
		$GLOBALS['jhmg_test_http']['queue'][] = $response;
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = [] ) {
		$GLOBALS['jhmg_test_http']['log'][] = [ 'method' => 'POST', 'url' => $url, 'args' => $args ];
		$r = array_shift( $GLOBALS['jhmg_test_http']['queue'] );
		return $r instanceof WP_Error ? $r : [ 'response' => [ 'code' => $r['code'] ], 'body' => json_encode( $r['body'] ) ];
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = [] ) {
		$GLOBALS['jhmg_test_http']['log'][] = [ 'method' => 'GET', 'url' => $url, 'args' => $args ];
		$r = array_shift( $GLOBALS['jhmg_test_http']['queue'] );
		return $r instanceof WP_Error ? $r : [ 'response' => [ 'code' => $r['code'] ], 'body' => json_encode( $r['body'] ) ];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) {
		return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) {
		return is_array( $r ) ? ( $r['body'] ?? '' ) : '';
	}
}

// NOTE: unlike the sibling repo's bootstrap, this file intentionally does NOT
// require the free plugin's main file. The existing 45 unit tests construct
// classes directly via the composer/mirror autoloaders above, and no test
// currently needs a bootstrapped Plugin::instance() singleton or the
// register_activation_hook()/add_action('plugins_loaded', ...) side effects
// that requiring the main file would trigger. If a future seam/integration
// test needs the fully-booted plugin, require the main file there (or add a
// guarded require here then) rather than paying that cost for every test run.

// Require the Pro plugin's main file to define JHMGCOFOP_* constants for ProPluginTest.
if ( file_exists( dirname( __DIR__ ) . '/plugin/jhmg-converter-divi-to-elementor-pro/jhmg-converter-divi-to-elementor-pro.php' ) ) {
	require_once dirname( __DIR__ ) . '/plugin/jhmg-converter-divi-to-elementor-pro/jhmg-converter-divi-to-elementor-pro.php';
}
