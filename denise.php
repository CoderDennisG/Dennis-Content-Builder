<?php

/**
 * Denise
 *
 * @package           Denise
 *
 * @wordpress-plugin
 * Plugin Name:       Denise
 * Plugin URI:        https://myfreelance101.com
 * Description:       AI assistant for the WordPress admin with pluggable skills. First skill family: build and edit content by chatting (Gutenberg and Elementor, drafts only).
 * Version:           0.7.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Dennis Gutierrez
 * Author URI:        https://myfreelance101.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       denise
 * Domain Path:       /languages
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'DENISE_VERSION', '0.7.0' );
define( 'DENISE_PLUGIN_FILE', __FILE__ );
define( 'DENISE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DENISE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$denise_autoload = DENISE_PLUGIN_DIR . 'vendor/autoload.php';

if ( ! file_exists( $denise_autoload ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Denise: vendor/autoload.php is missing. Run "composer install" inside the plugin directory.', 'denise' );
			echo '</p></div>';
		}
	);
	return;
}

require $denise_autoload;

register_activation_hook( __FILE__, array( \Denise\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Denise\Plugin::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( \Denise\Plugin::class, 'boot' ) );
