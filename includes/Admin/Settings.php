<?php
declare(strict_types=1);

namespace Denise\Admin;

/**
 * Settings page. The UI is a small React app built on WordPress's
 * bundled @wordpress/components (no build step); it reads and writes
 * through the denise/v1/settings REST endpoint.
 */
final class Settings {

	private string $hook = '';

	public function register(): void {
		// Priority 11: guarantees the parent menu (priority 10) exists first.
		add_action( 'admin_menu', array( $this, 'add_menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function add_menu(): void {
		$hook = add_submenu_page(
			DenisePage::PARENT,
			__( 'Denise Settings', 'denise' ),
			__( 'Settings', 'denise' ),
			'manage_options',
			'denise-settings',
			array( $this, 'render' )
		);

		$this->hook = is_string( $hook ) ? $hook : '';
	}

	public function assets( string $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'denise-settings', DENISE_PLUGIN_URL . 'assets/settings.css', array( 'wp-components' ), \Denise\Plugin::asset_version( 'assets/settings.css' ) );

		wp_enqueue_script(
			'denise-settings',
			DENISE_PLUGIN_URL . 'assets/settings.js',
			array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
			\Denise\Plugin::asset_version( 'assets/settings.js' ),
			true
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap denise-settings-wrap">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Denise Settings', 'denise' ); ?></h1>
			<div class="denise-settings-head">
				<span class="denise-avatar" aria-hidden="true">D</span>
				<div>
					<div class="denise-settings-head-title"><?php esc_html_e( 'Denise', 'denise' ); ?></div>
					<div class="denise-settings-head-sub"><?php esc_html_e( 'Settings · connection, access, content types', 'denise' ); ?></div>
				</div>
			</div>
			<div id="denise-settings-root"></div>
		</div>
		<?php
	}
}
