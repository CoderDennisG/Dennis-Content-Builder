<?php
/**
 * Uninstall cleanup. Content created through the plugin is normal
 * WordPress content and is deliberately left untouched.
 *
 * @package Denise
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

delete_option( 'denise_settings' );
