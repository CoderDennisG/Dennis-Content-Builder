<?php
declare(strict_types=1);

namespace Denise\Frontend;

use Denise\Admin\ChatPage;
use Denise\Admin\DenisePage;
use Denise\Content\Profiles;
use Denise\Support\Capabilities;

/**
 * Denise in the admin bar.
 *
 * One "Denise" node in the toolbar for users who hold the chat capability:
 *  - on a front-end singular view of a post type activated in Settings →
 *    Post Types, which the user can edit, it toggles the chat panel scoped
 *    to that post (the same shared chat as the admin page);
 *  - anywhere else (wp-admin, other front-end pages) it links to the chat
 *    page in wp-admin.
 *
 * Nothing floats on the page any more and nothing renders for anonymous
 * visitors. The REST route enforces the same checks regardless.
 */
final class Launcher {

	public const NODE_ID = 'denise';

	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 90 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'bar_style' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'bar_style' ) );
		add_action( 'wp_footer', array( $this, 'render' ) );
	}

	/**
	 * The post the panel should scope to, or 0 when the panel must not show.
	 * Centralizes every gate so the bar node, assets() and render() agree:
	 * front end, logged in, chat capability, singular view of an activated
	 * post type, editable by this user.
	 */
	private function target_post_id(): int {
		if ( is_admin() || ! is_user_logged_in() || ! is_singular() ) {
			return 0;
		}
		if ( ! current_user_can( Capabilities::USE_CHAT ) ) {
			return 0;
		}

		$post_id = (int) get_queried_object_id();
		if ( ! $post_id ) {
			return 0;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! Profiles::is_eligible( $post->post_type ) ) {
			return 0;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return 0;
		}
		// Second gate, shared with the tool layer: only Elementor / block-editor builds.
		if ( ! Profiles::is_supported_build( $post ) ) {
			return 0;
		}

		return $post_id;
	}

	public function admin_bar( \WP_Admin_Bar $bar ): void {
		if ( ! is_user_logged_in() || ! current_user_can( Capabilities::USE_CHAT ) ) {
			return;
		}

		$post_id = $this->target_post_id();
		$bar->add_node(
			array(
				'id'    => self::NODE_ID,
				'title' => '<span class="ab-icon" aria-hidden="true"><span class="denise-ab-mark">D</span></span><span class="ab-label">' . esc_html__( 'Denise', 'denise' ) . '</span>',
				'href'  => $post_id ? '#denise-chat' : admin_url( 'admin.php?page=' . DenisePage::PARENT ),
				'meta'  => array(
					'class' => $post_id ? 'denise-bar denise-bar-toggle' : 'denise-bar',
					'title' => $post_id ? __( 'Chat with Denise about this page', 'denise' ) : __( 'Open Denise', 'denise' ),
				),
			)
		);
	}

	/**
	 * The D mark in the toolbar, coral like the sidebar entry.
	 *
	 * Core styles toolbar icons with a three-id selector
	 * (`#wpadminbar > #wp-toolbar > #wp-admin-bar-root-default .ab-icon`:
	 * dashicons font, 4px padding; on phones 40px dashicons !important), so
	 * the mark itself is an inner span with its own font and size, and the
	 * `.ab-icon` is only a centred box that core's rules may size.
	 */
	public function bar_style(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( Capabilities::USE_CHAT ) ) {
			return;
		}
		$icon = '#wpadminbar #wp-toolbar #wp-admin-bar-denise > .ab-item .ab-icon';
		$mark = '#wpadminbar #wp-toolbar #wp-admin-bar-denise .denise-ab-mark';
		$css  = $icon . '{display:flex;align-items:center;justify-content:center;width:18px;height:18px;margin:7px 6px 0 0;padding:0;}'
			. $mark . '{display:flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#cc6e4a;color:#fff;font:700 11px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}'
			. '#wpadminbar #wp-admin-bar-denise:hover .denise-ab-mark,#wpadminbar #wp-admin-bar-denise.is-open .denise-ab-mark{background:#b85e3d;}'
			. '#wpadminbar #wp-admin-bar-denise.is-open > .ab-item{background:#2b3831;color:#fff;}'
			. '@media screen and (max-width:782px){' . $icon . '{width:52px;height:46px;margin:0;}' . $mark . '{width:24px;height:24px;font-size:13px;}}';
		wp_add_inline_style( 'admin-bar', $css );
	}

	public function assets(): void {
		$post_id = $this->target_post_id();
		if ( ! $post_id || ! is_admin_bar_showing() ) {
			return;
		}
		self::enqueue_panel( $post_id );
	}

	/**
	 * Enqueue the chat core and the panel for one post. Shared with the
	 * Elementor editor surface (Admin\ElementorEditor), which passes its own
	 * mode, strings and extra script dependencies.
	 *
	 * @param array    $extra Overrides merged into the deniseBubble data (i18n merged key by key).
	 * @param string[] $deps  Extra script handles bubble.js must wait for.
	 */
	public static function enqueue_panel( int $post_id, array $extra = array(), array $deps = array() ): void {
		// Same chat core as the admin page / sidebar.
		ChatPage::enqueue_chat_core();

		wp_enqueue_style( 'denise-bubble', DENISE_PLUGIN_URL . 'assets/bubble.css', array( 'denise-chat' ), \Denise\Plugin::asset_version( 'assets/bubble.css' ) );
		wp_enqueue_script( 'denise-bubble', DENISE_PLUGIN_URL . 'assets/bubble.js', array_merge( array( 'denise-chat' ), $deps ), \Denise\Plugin::asset_version( 'assets/bubble.js' ), true );

		$data = array(
			'postId' => $post_id,
			'mode'   => 'front',
			'nodeId' => 'wp-admin-bar-' . self::NODE_ID,
			'i18n'   => array(
				'title'    => __( 'Denise', 'denise' ),
				'greeting' => __( 'Hi, I\'m Denise.', 'denise' ),
				'intro'    => __( 'Tell me what to change on this page and I will update it, or ask how it works.', 'denise' ),
				'close'    => __( 'Close', 'denise' ),
				'updated'  => __( 'This page was updated — reload to see the changes.', 'denise' ),
				'reload'   => __( 'Reload page', 'denise' ),
				'pending'  => __( 'Saved as a draft of this page. Open it in Elementor to review and publish.', 'denise' ),
				'open'     => __( 'Open in Elementor', 'denise' ),
				'applied'  => __( 'Changed in the editor.', 'denise' ),
				'dismiss'  => __( 'OK', 'denise' ),
				'failed'   => __( 'The editor could not apply that change:', 'denise' ),
				'launcher' => __( 'Open Denise', 'denise' ),
			),
		);
		if ( isset( $extra['i18n'] ) ) {
			$data['i18n'] = array_merge( $data['i18n'], (array) $extra['i18n'] );
			unset( $extra['i18n'] );
		}
		wp_localize_script( 'denise-bubble', 'deniseBubble', array_merge( $data, $extra ) );
	}

	public function render(): void {
		if ( ! $this->target_post_id() || ! is_admin_bar_showing() ) {
			return;
		}
		// The panel is built by bubble.js into this root; the toolbar node opens it.
		echo '<div id="denise-bubble-root"></div>';
	}
}
