<?php
declare(strict_types=1);

namespace Denise\Admin;

use Denise\Content\Profiles;
use Denise\Frontend\Launcher;
use Denise\Support\Capabilities;

/**
 * Denise inside the Elementor editor.
 *
 * Third surface, next to the wp-admin chat page and the front-end panel.
 * The same draggable panel mounts in the editor window, plus a bridge
 * script (assets/elementor-editor.js) that
 *  - sends the live document (ids, types, text settings, selection) with
 *    every message, because the saved copy is stale while editing, and
 *  - applies Denise's edits through the editor's own command bus, so they
 *    appear instantly, sit in the undo history and are saved only when the
 *    person clicks Update. Denise never writes to the database here.
 *
 * Gate: chat capability, post type activated in Settings, user can edit the
 * post. The page is Elementor-built by definition (it is open in Elementor).
 */
final class ElementorEditor {

	public function register(): void {
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'elementor/editor/footer', array( $this, 'render' ) );
	}

	private function target_post_id(): int {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! is_user_logged_in() ) {
			return 0;
		}
		if ( ! current_user_can( Capabilities::USE_CHAT ) ) {
			return 0;
		}
		$post_id = (int) \Elementor\Plugin::$instance->editor->get_post_id();
		if ( ! $post_id ) {
			return 0;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! Profiles::is_eligible( $post->post_type ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return 0;
		}
		return $post_id;
	}

	public function assets(): void {
		$post_id = $this->target_post_id();
		if ( ! $post_id ) {
			return;
		}

		// Bridge first: bubble.js looks for window.DeniseEditorBridge at boot.
		wp_enqueue_script(
			'denise-elementor-editor',
			DENISE_PLUGIN_URL . 'assets/elementor-editor.js',
			array( 'jquery' ),
			\Denise\Plugin::asset_version( 'assets/elementor-editor.js' ),
			true
		);

		Launcher::enqueue_panel(
			$post_id,
			array(
				'mode'   => 'elementor',
				'nodeId' => '',
				'i18n'   => array(
					'intro'    => __( 'Tell me what to change on this page. I will edit it right here in the editor, like you would by hand: nothing is saved until you click Update.', 'denise' ),
					'applied'  => __( 'Changed in the editor. Click Update to save, or undo with Ctrl+Z.', 'denise' ),
					'launcher' => __( 'Open Denise', 'denise' ),
				),
			),
			array( 'denise-elementor-editor' )
		);
	}

	public function render(): void {
		if ( ! $this->target_post_id() ) {
			return;
		}
		echo '<div id="denise-bubble-root"></div>';
	}
}
