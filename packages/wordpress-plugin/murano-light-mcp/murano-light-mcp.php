<?php
/**
 * Plugin Name: Murano Light MCP
 * Plugin URI: https://github.com/gabyic/murano-light-mcp
 * Description: Public read-only Model Context Protocol (MCP 2025-03-26) Streamable HTTP server for WooCommerce lighting stores. Feed-first variant-level indexing, ceiling clearance math, verified store policies, and privacy-preserving admin analytics.
 * Version: 1.3.0
 * Author: Murano Lamp
 * Author URI: https://muranolamp.com
 * License: MIT
 * Text Domain: murano-light-mcp
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 *
 * @package Murano_Light_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MURANO_MCP_VERSION', '1.3.0' );
define( 'MURANO_MCP_PATH', plugin_dir_path( __FILE__ ) );

require_once MURANO_MCP_PATH . 'includes/class-mcp-catalog.php';
require_once MURANO_MCP_PATH . 'includes/class-mcp-tools.php';
require_once MURANO_MCP_PATH . 'includes/class-mcp-analytics.php';
require_once MURANO_MCP_PATH . 'includes/class-mcp-admin.php';
require_once MURANO_MCP_PATH . 'includes/class-mcp-server.php';

register_activation_hook( __FILE__, array( 'Murano_Light_MCP_Analytics', 'install_table' ) );
add_action( 'plugins_loaded', array( 'Murano_Light_MCP_Analytics', 'maybe_install' ) );
add_action(
	'init',
	static function () {
		load_plugin_textdomain(
			'murano-light-mcp',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}
);

add_action( 'rest_api_init', array( 'Murano_Light_MCP_Server', 'register_routes' ) );
add_action( 'template_redirect', array( 'Murano_Light_MCP_Server', 'maybe_serve_well_known' ), 1 );

if ( is_admin() ) {
	Murano_Light_MCP_Admin::init();
}

// Invalidate cached variant catalog when products or guides update.
add_action( 'save_post_product', array( 'Murano_Light_MCP_Catalog', 'flush_cache' ) );
add_action( 'save_post_post', array( 'Murano_Light_MCP_Catalog', 'flush_cache' ) );
add_action( 'woocommerce_update_product', array( 'Murano_Light_MCP_Catalog', 'flush_cache' ) );
