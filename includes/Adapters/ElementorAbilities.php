<?php
declare(strict_types=1);

namespace Denise\Adapters;

/**
 * Bridge to Elementor's own abilities (Elementor 4.3+, modules/mcp):
 * get-page-structure, manage-elements, build-composition, create-page,
 * get-widget-schema, list-widget-schemas, create-preview-link, …
 *
 * Elementor only registers them when its "MCP" switch is on, which also
 * exposes an MCP endpoint. Denise needs the abilities in-process only, so
 * when the switch is off it registers them itself on the Abilities API
 * init hook. Nothing becomes reachable from outside: the abilities run
 * only through Denise's tools, under the calling user's capabilities and
 * Elementor's own permission callbacks.
 */
final class ElementorAbilities {

	public const PREFIX = 'elementor/';

	public static function register(): void {
		add_action( 'wp_abilities_api_init', array( self::class, 'ensure_registered' ), 20 );
	}

	/** Elementor with its MCP module present (4.3+), regardless of the switch. */
	public static function is_available(): bool {
		return class_exists( '\\Elementor\\Plugin' )
			&& class_exists( '\\Elementor\\Modules\\Mcp\\Module' )
			&& function_exists( 'wp_get_ability' )
			&& \Elementor\Modules\Mcp\Module::is_active();
	}

	/** Runs inside wp_abilities_api_init; idempotent. */
	public static function ensure_registered(): void {
		if ( ! self::is_available() || wp_has_ability( self::PREFIX . 'get-page-structure' ) ) {
			return;
		}
		$module = \Elementor\Plugin::$instance->modules_manager->get_modules( 'mcp' );
		if ( $module && method_exists( $module, 'register_abilities' ) ) {
			$module->register_abilities();
		}
	}

	/**
	 * Execute one ability. Returns ['data' => …] or ['error' => message].
	 */
	public static function run( string $name, array $args ): array {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return array( 'error' => 'The WordPress Abilities API is not available.' );
		}
		$ability = wp_get_ability( self::PREFIX . $name );
		if ( ! $ability ) {
			return array( 'error' => "Elementor ability not found: {$name}. Elementor 4.3 or newer is required." );
		}
		try {
			$result = $ability->execute( $args );
		} catch ( \Throwable $e ) {
			return array( 'error' => $e->getMessage() );
		}
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			return array(
				'error'   => $result->get_error_message(),
				'details' => is_array( $data ) ? $data : null,
			);
		}
		return array( 'data' => $result );
	}
}
