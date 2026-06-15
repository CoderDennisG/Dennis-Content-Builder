<?php
declare(strict_types=1);

namespace DCB;

use DCB\Admin\ChatPage;
use DCB\Admin\Settings;
use DCB\Ai\Scheduler;
use DCB\Api\Routes;
use DCB\Support\Capabilities;
use DCB\Support\Schema;

/**
 * Plugin bootstrap: wires hooks. No business logic here.
 */
final class Plugin {

	public const OPTION = 'dcb_settings';

	public static function boot(): void {
		// ChatPage owns the top-level menu and must register before the
		// Settings submenu, or WP files the submenu under a wrong hook
		// and its sidebar link 404s.
		( new ChatPage() )->register();
		( new Settings() )->register();
		( new Routes() )->register();
		Scheduler::register();

		// Upgrades (plugin replaced without re-activation): keep schema
		// and capabilities current.
		add_action( 'admin_init', array( self::class, 'maybe_upgrade' ) );
	}

	/** Activation hook target. */
	public static function activate(): void {
		Schema::install();
		Capabilities::install();
		Scheduler::register();
		Scheduler::sync_all();
	}

	/** Deactivation hook target: stop all scheduled events. */
	public static function deactivate(): void {
		Scheduler::deactivate();
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'dcb_version' ) !== DCB_VERSION ) {
			Schema::install();
			Capabilities::install();
			update_option( 'dcb_version', DCB_VERSION, false );
		}
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
