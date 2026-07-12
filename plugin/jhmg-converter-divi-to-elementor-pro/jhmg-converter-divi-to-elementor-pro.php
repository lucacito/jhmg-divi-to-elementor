<?php
/**
 * Plugin Name:       JHMG Converter For Divi to Elementor — Pro
 * Description:       Pro add-on: batch conversion, WooCommerce widget mapping, and Divi Theme Builder import.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      8.0
 * Requires Plugins:  jhmg-converter-divi-to-elementor
 * Author:            Lucas Lopvet
 * License:           GPLv2 or later
 * Text Domain:       jhmg-converter-divi-to-elementor-pro
 */

defined( 'ABSPATH' ) || exit;

define( 'JHMGCOFOP_PLUGIN_FILE', __FILE__ );
define( 'JHMGCOFOP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JHMGCOFOP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JHMGCOFOP_PLUGIN_VERSION', '1.0.0' );
define( 'JHMGCOFOP_PRODUCT_SLUG', 'divi-to-elementor-pro' );
// Overridable for local/dev license servers: define JHMGCOFOP_API_BASE in wp-config.php.
defined( 'JHMGCOFOP_API_BASE' ) || define( 'JHMGCOFOP_API_BASE', 'https://divi5lab.com' );

require_once JHMGCOFOP_PLUGIN_DIR . 'includes/class-autoloader.php';

\DiviElementorConverter\Pro\Plugin::instance()->init();
