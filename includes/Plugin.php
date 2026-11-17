<?php
declare(strict_types=1);

namespace Denise;

use Denise\Admin\DenisePage;
use Denise\Admin\ChatPage;
use Denise\Adapters\ElementorAbilities;
use Denise\Admin\Settings;
use Denise\Ai\Scheduler;
use Denise\Api\Routes;
use Denise\Content\Retention;
use Denise\Frontend\Launcher;
use Denise\Admin\ElementorEditor;
use Denise\Support\Capabilities;
use Denise\Support\Migration;
use Denise\Support\Schema;

/**
 * Plugin bootstrap: wires hooks. No business logic here.
 */
final class Plugin {

	public const OPTION = 'denise_settings';

	public static function boot(): void {
		Migration::maybe_migrate();

		// DenisePage owns the top-level menu (priority 9); Settings (11)
		// hangs its submenu off it. ChatPage only ships the shared chat
		// assets (admin page, editor sidebar).
		( new DenisePage() )->register();
		( new ChatPage() )->register();
		( new Settings() )->register();
		( new Routes() )->register();
		( new Launcher() )->register();
		( new ElementorEditor() )->register();
		Scheduler::register();
		Retention::register();
		ElementorAbilities::register();

		// Upgrades (plugin replaced without re-activation): keep schema
		// and capabilities current.
		add_action( 'admin_init', array( self::class, 'maybe_upgrade' ) );
	}

	/** Activation hook target. */
	public static function activate(): void {
		Migration::maybe_migrate();
		Schema::install();
		Capabilities::install();
		Scheduler::register();
		Scheduler::sync_all();
		Retention::install();
	}

	/** Deactivation hook target: stop all scheduled events. */
	public static function deactivate(): void {
		Scheduler::deactivate();
		Retention::clear();
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'denise_version' ) !== DENISE_VERSION || ! Schema::is_current() ) {
			Schema::install();
			Capabilities::install();
			update_option( 'denise_version', DENISE_VERSION, false );
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
				'model'   => 'claude-sonnet-5-5',
			)
		);
	}

	/**
	 * Cache-busting version for an asset: plugin version plus the file's
	 * modification time, so a browser never keeps a stale script after an
	 * edit even while the plugin version stays the same.
	 */
	public static function asset_version( string $relative ): string {
		$mtime = @filemtime( DENISE_PLUGIN_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return DENISE_VERSION . ( $mtime ? '.' . $mtime : '' );
	}

	/** Models offered in settings. Keep in sync with docs/ARCHITECTURE.md. */
	public static function models(): array {
		return array(
			'claude-sonnet-5-5' => __( 'Claude Sonnet 5.5 — fast, good for questions and drafts (default)', 'denise' ),
			'claude-opus-5-5'   => __( 'Claude Opus 5.5 — heavier page composition, slower', 'denise' ),
			'claude-haiku-5-5'  => __( 'Claude Haiku 5.5 — cheapest, simplest tasks', 'denise' ),
			'claude-opus-4-8'   => __( 'Claude Opus 4.8 (previous default)', 'denise' ),
			'claude-sonnet-4-6' => __( 'Claude Sonnet 4.6 — faster, cheaper', 'denise' ),
			'claude-haiku-4-5'  => __( 'Claude Haiku 4.5 — fastest, simplest tasks', 'denise' ),
		);
	}
}
