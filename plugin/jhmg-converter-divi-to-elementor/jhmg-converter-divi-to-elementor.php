<?php
/**
 * Plugin Name: JHMG Converter For Divi to Elementor
 * Plugin URI:  https://jhmediagroup.com/plugin
 * Description: Converts Divi layouts into Elementor page data.
 * Version:     1.0.0
 * Author:      Lucas Lopvet
 * Author URI:  https://jhmediagroup.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jhmg-converter-divi-to-elementor
 * Domain Path: /languages
 * Requires at least: 5.9
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DEC_PLUGIN_FILE', __FILE__ );
define( 'DEC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DEC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

defined( 'DEC_PLUGIN_VERSION' ) || define( 'DEC_PLUGIN_VERSION', '1.0.0' );

require_once DEC_PLUGIN_DIR . 'includes/helpers/class-autoloader.php';

register_deactivation_hook( __FILE__, static function () {
    delete_option( 'dec_premium_active' );
} );

\DiviElementorConverter\Plugin::instance()->init();
