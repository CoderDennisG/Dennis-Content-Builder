<?php
declare(strict_types=1);

namespace Denise\Admin;

use Denise\Plugin;

/**
 * Shared chat assets: the chat core (script, style, config) used by the
 * Denise page, the block editor sidebar and the front-end bubble.
 * Prototype: plain JS + CSS assets, no build step.
 */
final class ChatPage {

	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );
	}

	/** Same chat, inside the block editor as a sidebar. */
	public function editor_assets(): void {
		if ( ! current_user_can( \Denise\Support\Capabilities::USE_CHAT ) ) {
			return;
		}

		// Only on post types the admin marked AI-eligible.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! \Denise\Content\Profiles::is_eligible( (string) $screen->post_type ) ) {
			return;
		}

		self::enqueue_chat_core();

		wp_enqueue_script(
			'denise-editor',
			DENISE_PLUGIN_URL . 'assets/editor.js',
			array( 'denise-chat', 'wp-plugins', 'wp-editor', 'wp-element', 'wp-data', 'wp-blocks', 'wp-api-fetch', 'wp-i18n' ),
			\Denise\Plugin::asset_version( 'assets/editor.js' ),
			true
		);
	}

	/**
	 * Enqueue the shared chat core (denise-chat script/style + deniseChat config).
	 * Public + static so other surfaces (editor sidebar, front-end bubble)
	 * mount the exact same chat against the same REST endpoints.
	 */
	public static function enqueue_chat_core(): void {
		wp_enqueue_style( 'denise-chat', DENISE_PLUGIN_URL . 'assets/chat.css', array(), \Denise\Plugin::asset_version( 'assets/chat.css' ) );
		wp_enqueue_script( 'denise-chat', DENISE_PLUGIN_URL . 'assets/chat.js', array(), \Denise\Plugin::asset_version( 'assets/chat.js' ), true );

		wp_localize_script(
			'denise-chat',
			'deniseChat',
			array(
				'chatUrl'          => esc_url_raw( rest_url( 'denise/v1/chat' ) ),
				'conversationsUrl' => esc_url_raw( rest_url( 'denise/v1/conversations' ) ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'hasKey'           => '' !== Plugin::settings()['api_key'],
				'settings'         => esc_url_raw( admin_url( 'admin.php?page=denise-settings' ) ),
				'i18n'             => array(
					'name'          => __( 'Denise', 'denise' ),
					'greeting'      => __( "Hi, I'm Denise. What do you need?", 'denise' ),
					'placeholder'   => __( 'Ask a question, or describe what to build…', 'denise' ),
					'hint'          => __( 'Enter to send · Shift+Enter for a new line', 'denise' ),
					'loading'       => __( 'Loading…', 'denise' ),
					'noHistory'     => __( 'No conversations yet.', 'denise' ),
					'copy'          => __( 'Copy', 'denise' ),
					'copied'        => __( 'Copied', 'denise' ),
					'openSettings'  => __( 'Open Settings', 'denise' ),
					'delete'        => __( 'Delete conversation', 'denise' ),
					'deleteConfirm' => __( 'Delete this conversation? This cannot be undone.', 'denise' ),
					'send'          => __( 'Send', 'denise' ),
					'thinking'      => __( 'Thinking…', 'denise' ),
					'composing'     => __( 'Writing… %s characters', 'denise' ),
					'editDraft'     => __( 'Open in editor', 'denise' ),
					'preview'       => __( 'Preview', 'denise' ),
					'created'       => __( 'Draft created', 'denise' ),
					'updated'       => __( 'Content updated', 'denise' ),
					'pendingDraft'  => __( 'Draft saved, publish in Elementor', 'denise' ),
					'noKey'         => __( 'No API key configured yet. Add one in Settings first.', 'denise' ),
					'error'         => __( 'Something went wrong:', 'denise' ),
					'newChat'       => __( 'New conversation', 'denise' ),
					'history'       => __( 'Conversations', 'denise' ),
				),
			)
		);
	}
}
