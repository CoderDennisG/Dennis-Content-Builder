<?php
declare(strict_types=1);

namespace Denise\Admin;

use Denise\Content\Conversations;
use Denise\Support\Capabilities;

/**
 * The "Denise" top-level menu and its chat page: one assistant that
 * answers questions about the site from the installed skills and builds
 * or edits content through its tools. Settings hangs off the same parent.
 *
 * Also owns what every Denise screen shares: the sidebar styling and the
 * suppression of other plugins' admin notices.
 */
final class DenisePage {

	public const PARENT = 'denise';

	/** Screen ids of every Denise admin page (parent + submenus). */
	private const SCREENS = array(
		'toplevel_page_denise',
		'denise_page_denise-settings',
	);

	/** Sidebar icon: a bold "D" monogram (20x20 SVG, white). Forced white by menu_style(). */
	private const ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iI2ZmZiIgZmlsbC1ydWxlPSJldmVub2RkIiBkPSJNNCAyaDZhOCA4IDAgMCAxIDAgMTZINHptMy41IDMuNXY5SDEwYTQuNSA0LjUgMCAwIDAgMC05eiIvPjwvc3ZnPg==';

	private string $hook = '';

	public function register(): void {
		// Priority 9: the parent must exist before Settings (11) adds its submenu.
		add_action( 'admin_menu', array( $this, 'add_menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( $this, 'menu_style' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'in_admin_header', array( $this, 'suppress_notices' ), 0 );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	public function add_menu(): void {
		$hook = add_menu_page(
			__( 'Denise', 'denise' ),
			__( 'Denise', 'denise' ),
			Capabilities::USE_CHAT,
			self::PARENT,
			array( $this, 'render' ),
			self::ICON,
			26
		);

		// Same slug as the parent: names the first submenu entry instead
		// of WP duplicating the parent label there.
		add_submenu_page(
			self::PARENT,
			__( 'Chat', 'denise' ),
			__( 'Chat', 'denise' ),
			Capabilities::USE_CHAT,
			self::PARENT,
			array( $this, 'render' )
		);

		$this->hook = is_string( $hook ) ? $hook : '';
	}

	/** True on any Denise admin screen. */
	public static function is_denise_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen instanceof \WP_Screen && in_array( $screen->id, self::SCREENS, true );
	}

	/**
	 * Drop every admin notice on Denise screens. Runs on `in_admin_header`,
	 * which fires just before WP calls the notice hooks, so nothing from
	 * core or other plugins prints above the chat. Notices injected later
	 * by JS are hidden by CSS (see body_class + menu_style).
	 */
	public function suppress_notices(): void {
		if ( ! self::is_denise_screen() ) {
			return;
		}
		foreach ( array( 'admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices' ) as $hook ) {
			remove_all_actions( $hook );
		}
	}

	public function body_class( string $classes ): string {
		return self::is_denise_screen() ? $classes . ' denise-screen' : $classes;
	}

	/** Sidebar styling (every admin screen) + notice hiding (Denise screens). */
	public function menu_style(): void {
		$css = '#adminmenu #toplevel_page_denise > a,'
			. '#adminmenu #toplevel_page_denise.wp-has-current-submenu > a,'
			. '#adminmenu #toplevel_page_denise.current > a{background:#cc6e4a;color:#fff;}'
			. '#adminmenu #toplevel_page_denise > a:hover,'
			. '#adminmenu #toplevel_page_denise > a:focus{background:#b85e3d;color:#fff;}'
			. '#adminmenu #toplevel_page_denise .wp-menu-image img{opacity:1;filter:brightness(0) invert(1);}'
			. 'body.denise-screen #wpbody-content > .notice,'
			. 'body.denise-screen #wpbody-content > .updated,'
			. 'body.denise-screen #wpbody-content > .error,'
			. 'body.denise-screen #wpbody-content > .update-nag,'
			. 'body.denise-screen .wrap > .notice:not(.denise-notice),'
			. 'body.denise-screen .wrap > .updated,'
			. 'body.denise-screen .wrap > .error,'
			. 'body.denise-screen .wrap > .update-nag{display:none !important;}';
		wp_add_inline_style( 'admin-menu', $css );
	}

	public function assets( string $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}
		ChatPage::enqueue_chat_core();
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::USE_CHAT ) ) {
			return;
		}

		$boot = array(
			'title'       => __( 'Denise', 'denise' ),
			'subtitle'    => __( 'Ask how the site works, or have me draft and edit pages', 'denise' ),
			'greeting'    => __( "Hi, I'm Denise. What do you need?", 'denise' ),
			'intro'       => __( 'Ask how something on this site works, or tell me what to build. I only ever create drafts; nothing goes live without you.', 'denise' ),
			'placeholder' => __( 'Ask a question, or describe what to build…', 'denise' ),
			'suggestions' => array(
				__( 'What is the shortcode for listing workshops led by Chip Conley?', 'denise' ),
				__( 'How do I show the dates and campus on a workshop page?', 'denise' ),
				__( 'Draft a blog post announcing a new workshop', 'denise' ),
			),
			'resume'      => (int) Conversations::latest( get_current_user_id(), null, DAY_IN_SECONDS ),
		);
		?>
		<div class="wrap denise-wrap">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Denise', 'denise' ); ?></h1>
			<div id="denise-chat-app" data-denise-boot="<?php echo esc_attr( (string) wp_json_encode( $boot ) ); ?>"></div>
		</div>
		<?php
	}
}
