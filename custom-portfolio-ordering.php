<?php
/**
 * Plugin Name: Custom Portfolio Ordering
 * Description: Drag-and-drop custom ordering for portfolio items by category/taxonomy term. Order is applied site-wide.
 * Version: 1.0.0
 * Author: Justin Netage
 * Text Domain: custom-portfolio-ordering
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CPO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CPO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CPO_VERSION', '1.0.0' );

require_once CPO_PLUGIN_DIR . 'includes/class-admin.php';
require_once CPO_PLUGIN_DIR . 'includes/class-frontend.php';

/**
 * Initialize the plugin.
 */
function cpo_init() {
	new CPO_Admin();
	new CPO_Frontend();
}
add_action( 'init', 'cpo_init' );
