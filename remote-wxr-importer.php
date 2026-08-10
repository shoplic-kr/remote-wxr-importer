<?php
/**
 * Plugin Name:       Remote WXR Importer
 * Description:       Imports WXR files through REST requests authenticated with WordPress Application Passwords.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Shoplic Inc.
 * Author URI:        https://shoplic.kr
 * Text Domain:       remote-wxr-importer
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Remote_WXR_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RWI_VERSION', '1.0.0' );
define( 'RWI_PLUGIN_FILE', __FILE__ );
define( 'RWI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once RWI_PLUGIN_DIR . 'includes/class-rwi-rest-controller.php';

/**
 * Loads plugin translations.
 *
 * @return void
 */
function rwi_load_textdomain() {
	load_plugin_textdomain(
		'remote-wxr-importer',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'rwi_load_textdomain' );

/**
 * Registers the REST API route.
 *
 * @return void
 */
function rwi_register_rest_routes() {
	$controller = new RWI_REST_Controller();
	$controller->register_routes();
}
add_action( 'rest_api_init', 'rwi_register_rest_routes' );
