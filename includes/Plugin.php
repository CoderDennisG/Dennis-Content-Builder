<?php
declare(strict_types=1);

namespace DCB;

use DCB\Admin\ChatPage;
use DCB\Admin\Settings;
use DCB\Api\Routes;

/**
 * Plugin bootstrap: wires hooks. No business logic here.
 */
final class Plugin {

	public const OPTION = 'dcb_settings';

	public static function boot(): void {
		( new Settings() )->register();
		( new ChatPage() )->register();
		( new Routes() )->register();
	}

	/**
	 * Plugin settings with defaults.
	 *
	 * @return array{api_key:string, model:string}
	 */
	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'api_key' => '',
				'model'   => 'claude-opus-4-8',
			)
		);
	}

	/** Models offered in settings. Keep in sync with docs/ARCHITECTURE.md. */
	public static function models(): array {
		return array(
			'claude-opus-4-8'   => __( 'Claude Opus 4.8 — best quality (default)', 'dennis-content-builder' ),
			'claude-sonnet-4-6' => __( 'Claude Sonnet 4.6 — faster, cheaper', 'dennis-content-builder' ),
			'claude-haiku-4-5'  => __( 'Claude Haiku 4.5 — fastest, simplest tasks', 'dennis-content-builder' ),
		);
	}
}
