<?php
/**
 * Dennis Content Builder
 *
 * @package           DCB
 *
 * @wordpress-plugin
 * Plugin Name:       Dennis Content Builder
 * Plugin URI:        https://myfreelance101.com
 * Description:       Build and edit WordPress content by chatting with AI. Gutenberg-native, drafts only.
 * Version:           0.4.2
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Dennis Gutierrez
 * Author URI:        https://myfreelance101.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       dennis-content-builder
 * Domain Path:       /languages
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'DCB_VERSION', '0.4.2' );
define( 'DCB_PLUGIN_FILE', __FILE__ );
define( 'DCB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DCB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$dcb_autoload = DCB_PLUGIN_DIR . 'vendor/autoload.php';

if ( ! file_exists( $dcb_autoload ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Dennis Content Builder: vendor/autoload.php is missing. Run "composer install" inside the plugin directory.', 'dennis-content-builder' );
			echo '</p></div>';
		}
	);
	return;
}

require $dcb_autoload;

register_activation_hook( __FILE__, array( \DCB\Plugin::class, 'activate' ) );
add_action( 'plugins_loaded', array( \DCB\Plugin::class, 'boot' ) );
