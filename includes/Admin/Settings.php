<?php
declare(strict_types=1);

namespace DCB\Admin;

/**
 * Settings page. The UI is a small React app built on WordPress's
 * bundled @wordpress/components (no build step); it reads and writes
 * through the dcb/v1/settings REST endpoint.
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
			'dennis-content-builder',
			__( 'Content Builder Settings', 'dennis-content-builder' ),
			__( 'Settings', 'dennis-content-builder' ),
			'manage_options',
			'dcb-settings',
			array( $this, 'render' )
		);

		$this->hook = is_string( $hook ) ? $hook : '';
	}

	public function assets( string $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'dcb-settings', DCB_PLUGIN_URL . 'assets/settings.css', array( 'wp-components' ), DCB_VERSION );

		wp_enqueue_script(
			'dcb-settings',
			DCB_PLUGIN_URL . 'assets/settings.js',
			array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
			DCB_VERSION,
			true
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Content Builder Settings', 'dennis-content-builder' ); ?></h1>
			<div id="dcb-settings-root"></div>
		</div>
		<?php
	}
}
