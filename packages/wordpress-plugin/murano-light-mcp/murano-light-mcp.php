<?php
/**
 * Plugin Name: Murano Light MCP
 * Plugin URI: https://github.com/gabyic/murano-light-mcp
 * Description: Public read-only Model Context Protocol (MCP 2025-03-26) Streamable HTTP server for WooCommerce lighting stores. Feed-first variant-level indexing, ceiling clearance math, and verified store policies.
 * Version: 1.1.0
 * Author: Murano Lamp
 * Author URI: https://muranolamp.com
 * License: MIT
 * Text Domain: murano-light-mcp
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 *
 * @package Murano_Light_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MURANO_MCP_VERSION', '1.1.0' );
define( 'MURANO_MCP_PATH', plugin_dir_path( __FILE__ ) );

require_once MURANO_MCP_PATH . 'includes/class-mcp-catalog.php';
require_once MURANO_MCP_PATH . 'includes/class-mcp-tools.php';
require_once MURANO_MCP_PATH . 'includes/class-mcp-server.php';

add_action( 'rest_api_init', array( 'Murano_Light_MCP_Server', 'register_routes' ) );
add_action( 'template_redirect', array( 'Murano_Light_MCP_Server', 'maybe_serve_well_known' ), 1 );

// Invalidate cached variant catalog when products or guides update.
add_action( 'save_post_product', array( 'Murano_Light_MCP_Catalog', 'flush_cache' ) );
add_action( 'save_post_post', array( 'Murano_Light_MCP_Catalog', 'flush_cache' ) );
add_action( 'woocommerce_update_product', array( 'Murano_Light_MCP_Catalog', 'flush_cache' ) );
