<?php
declare(strict_types=1);

namespace DCB\Admin;

use DCB\Plugin;

/**
 * "Content Builder" admin page hosting the chat UI.
 * Prototype: plain JS + CSS assets, no build step.
 */
final class ChatPage {

	private const SLUG = 'dennis-content-builder';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'Content Builder', 'dennis-content-builder' ),
			__( 'Content Builder', 'dennis-content-builder' ),
			\DCB\Support\Capabilities::USE_CHAT,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-format-chat',
			26
		);
	}

	public function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'dcb-chat', DCB_PLUGIN_URL . 'assets/chat.css', array(), DCB_VERSION );
		wp_enqueue_script( 'dcb-chat', DCB_PLUGIN_URL . 'assets/chat.js', array(), DCB_VERSION, true );

		wp_localize_script(
			'dcb-chat',
			'dcbChat',
			array(
				'chatUrl'          => esc_url_raw( rest_url( 'dcb/v1/chat' ) ),
				'conversationsUrl' => esc_url_raw( rest_url( 'dcb/v1/conversations' ) ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'hasKey'           => '' !== Plugin::settings()['api_key'],
				'settings'         => esc_url_raw( admin_url( 'admin.php?page=dcb-settings' ) ),
				'i18n'             => array(
					'placeholder' => __( 'Describe the page you want, or ask me to edit an existing one…', 'dennis-content-builder' ),
					'send'        => __( 'Send', 'dennis-content-builder' ),
					'thinking'    => __( 'Thinking…', 'dennis-content-builder' ),
					'composing'   => __( 'Writing… %s characters', 'dennis-content-builder' ),
					'editDraft'   => __( 'Open in editor', 'dennis-content-builder' ),
					'preview'     => __( 'Preview', 'dennis-content-builder' ),
					'created'     => __( 'Draft created', 'dennis-content-builder' ),
					'updated'     => __( 'Content updated', 'dennis-content-builder' ),
					'noKey'       => __( 'No API key configured yet. Add one in Settings first.', 'dennis-content-builder' ),
					'error'       => __( 'Something went wrong:', 'dennis-content-builder' ),
					'newChat'     => __( 'New conversation', 'dennis-content-builder' ),
					'history'     => __( 'Previous conversations…', 'dennis-content-builder' ),
				),
			)
		);
	}

	public function render(): void {
		if ( ! current_user_can( \DCB\Support\Capabilities::USE_CHAT ) ) {
			return;
		}
		?>
		<div class="wrap dcb-wrap">
			<h1><?php esc_html_e( 'Content Builder', 'dennis-content-builder' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Chat with AI to create pages and posts. Everything is saved as a draft for you to review — nothing goes live without you.', 'dennis-content-builder' ); ?>
			</p>
			<div id="dcb-toolbar">
				<button type="button" class="button" id="dcb-new-chat"></button>
				<select id="dcb-history"></select>
			</div>
			<div id="dcb-chat-app">
				<div id="dcb-messages" aria-live="polite"></div>
				<form id="dcb-form">
					<textarea id="dcb-input" rows="3" required></textarea>
					<button type="submit" class="button button-primary" id="dcb-send"></button>
				</form>
			</div>
		</div>
		<?php
	}
}
