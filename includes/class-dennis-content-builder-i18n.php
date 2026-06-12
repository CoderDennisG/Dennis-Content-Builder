<?php

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://myfreelance101.com
 * @since      1.0.0
 *
 * @package    Dennis_Content_Builder
 * @subpackage Dennis_Content_Builder/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Dennis_Content_Builder
 * @subpackage Dennis_Content_Builder/includes
 * @author     Dennis Gutierrez <myfreelance101@proton.me>
 */
class Dennis_Content_Builder_i18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'dennis-content-builder',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}



}
