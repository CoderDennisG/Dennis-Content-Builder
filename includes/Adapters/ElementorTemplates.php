<?php
/**
 * Saved Elementor templates (the "My Templates" library) for Denise.
 *
 * Read-only lookup of the local template source: find a template by name
 * and validate one for insertion. The insert itself runs in the browser
 * through Elementor's own library flow (`library/insert-template`), which
 * fetches the content, handles the styles dialog and imports, exactly as
 * clicking Insert in the library does.
 *
 * @package Denise
 */

declare(strict_types=1);

namespace Denise\Adapters;

/**
 * Lookup and validation of saved local Elementor templates.
 */
final class ElementorTemplates {

	/**
	 * Template types Denise may insert into a page: page/section/container
	 * layouts and single widgets. Theme-builder parts (header, footer,
	 * single, archive, loop item, popup…) are not page content.
	 */
	private const INSERTABLE = array( 'page', 'section', 'container', 'e-div-block', 'e-flexbox', 'e-grid', 'widget' );

	/** Longest template title echoed back to the model. */
	private const MAX_TITLE = 120;

	public static function is_available(): bool {
		return class_exists( '\Elementor\Plugin' )
			&& isset( \Elementor\Plugin::$instance->templates_manager )
			&& class_exists( '\Elementor\TemplateLibrary\Source_Local' );
	}

	/**
	 * Saved templates whose title contains $search (all when empty), with the
	 * insertable ones first. Each item: id, title, type, insertable, modified.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function find( string $search = '', int $limit = 25 ): array {
		if ( ! self::is_available() ) {
			return array();
		}
		$source = \Elementor\Plugin::$instance->templates_manager->get_source( 'local' );
		if ( ! $source ) {
			return array();
		}
		$needle = self::fold( $search );
		$items  = array();
		foreach ( (array) $source->get_items() as $item ) {
			$id = absint( $item['template_id'] ?? 0 );
			if ( ! $id || ! self::can_read( $id ) ) {
				continue;
			}
			$title = (string) ( $item['title'] ?? '' );
			if ( '' !== $needle && ! self::matches( $title, $needle ) ) {
				continue;
			}
			$type    = (string) ( $item['type'] ?? '' );
			$items[] = array(
				'id'         => $id,
				'title'      => self::clip( $title ),
				'type'       => $type,
				'insertable' => in_array( $type, self::INSERTABLE, true ),
				'modified'   => (string) ( $item['human_modified_date'] ?? '' ),
			);
		}
		usort(
			$items,
			static function ( array $a, array $b ): int {
				$by_insertable = (int) $b['insertable'] <=> (int) $a['insertable'];
				return 0 !== $by_insertable ? $by_insertable : strcasecmp( $a['title'], $b['title'] );
			}
		);
		return array_slice( $items, 0, max( 1, $limit ) );
	}

	/**
	 * Validate a template for insertion and describe it for the browser.
	 *
	 * @return array{id:int,title:string,type:string}|array{error:string}
	 */
	public static function info( int $template_id ): array {
		if ( ! self::is_available() ) {
			return array( 'error' => 'Elementor is not active.' );
		}
		$post = get_post( $template_id );
		if ( ! $post || \Elementor\TemplateLibrary\Source_Local::CPT !== $post->post_type ) {
			return array( 'error' => "No saved Elementor template with id {$template_id}. Find it with find_elementor_template first." );
		}
		if ( ! self::can_read( $template_id ) ) {
			return array( 'error' => 'This template is not published and you cannot read it.' );
		}
		$type = (string) \Elementor\TemplateLibrary\Source_Local::get_template_type( $template_id );
		if ( ! in_array( $type, self::INSERTABLE, true ) ) {
			return array( 'error' => sprintf( 'Template "%s" is a %s template (a theme part), not page content; it cannot be inserted into a page.', self::clip( $post->post_title ), $type ) );
		}
		return array(
			'id'    => $template_id,
			'title' => self::clip( $post->post_title ),
			'type'  => $type,
		);
	}

	/** Page-settings keys that describe the template post itself, never copied onto a draft. */
	private const POST_SETTINGS = array( 'post_status', 'post_title', 'post_excerpt', 'post_featured_image', 'comment_status', 'menu_order' );

	/**
	 * Copy a template's elements (fresh ids) and page settings onto a DRAFT
	 * post through Elementor's own document save, the server-side half of
	 * what the library's "Insert" does. Drafts only: a published page's
	 * live layout is never replaced this way (RULES.md rule 6 exception).
	 *
	 * Context: Elementor validates dynamic-tag props against the tag's
	 * controls, and strips control labels on the front end (Performance::
	 * should_optimize_controls), which makes that validation fail there.
	 * Admin and REST requests (Denise's chat route) keep the labels; a CLI
	 * or cron caller must define REST_REQUEST before Elementor loads.
	 *
	 * @return array{post_id:int,template:array,sections:array}|array{error:string}
	 */
	public static function apply_to( int $post_id, int $template_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'Post not found or not editable by this user.' );
		}
		if ( 'draft' !== $post->post_status ) {
			return array( 'error' => 'A template can only be applied to a draft (this post is ' . $post->post_status . ').' );
		}
		$info = self::info( $template_id );
		if ( isset( $info['error'] ) ) {
			return $info;
		}
		if ( 'widget' === $info['type'] ) {
			return array( 'error' => sprintf( 'Template "%s" is a single widget, not a page layout.', $info['title'] ) );
		}
		$source  = \Elementor\Plugin::$instance->templates_manager->get_source( 'local' );
		$data    = $source ? $source->get_data(
			array(
				'template_id'        => $template_id,
				'with_page_settings' => true,
			)
		) : array();
		$content = isset( $data['content'] ) && is_array( $data['content'] ) ? array_values( $data['content'] ) : array();
		if ( ! $content ) {
			return array( 'error' => sprintf( 'Template "%s" is empty.', $info['title'] ) );
		}
		$settings = array_diff_key( (array) ( $data['page_settings'] ?? array() ), array_flip( self::POST_SETTINGS ) );

		$document = \Elementor\Plugin::$instance->documents->get( $post_id, false );
		if ( ! $document ) {
			return array( 'error' => 'Elementor cannot open this post as a document.' );
		}
		$document->set_is_built_with_elementor( true );
		$saved = $document->save(
			array(
				'elements' => $content,
				'settings' => $settings,
			)
		);
		if ( ! $saved ) {
			return array( 'error' => 'Elementor refused to save the draft for this user.' );
		}
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

		return array(
			'post_id'  => $post_id,
			'template' => $info,
			'sections' => self::sections( $content ),
		);
	}

	/**
	 * Root sections of element data as the editor names them.
	 *
	 * @return array<int, array{id:string, name:string, type:string}>
	 */
	public static function sections( array $elements ): array {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$name  = (string) ( $el['editor_settings']['title'] ?? $el['settings']['_title'] ?? '' );
			$out[] = array(
				'id'   => (string) ( $el['id'] ?? '' ),
				'name' => '' !== $name ? $name : (string) ( $el['widgetType'] ?? $el['elType'] ?? '' ),
				'type' => (string) ( $el['widgetType'] ?? $el['elType'] ?? '' ),
			);
		}
		return $out;
	}

	/** Published templates are readable by every editor; drafts only by those who can edit them. */
	private static function can_read( int $template_id ): bool {
		if ( 'publish' === get_post_status( $template_id ) && ! post_password_required( $template_id ) ) {
			return true;
		}
		return current_user_can( 'edit_post', $template_id );
	}

	/** Case- and punctuation-insensitive containment, or every word of the needle present. */
	private static function matches( string $title, string $needle ): bool {
		$hay = self::fold( $title );
		if ( false !== strpos( $hay, $needle ) ) {
			return true;
		}
		foreach ( explode( ' ', $needle ) as $word ) {
			if ( '' !== $word && false === strpos( $hay, $word ) ) {
				return false;
			}
		}
		return true;
	}

	private static function fold( string $text ): string {
		$text = strtolower( remove_accents( $text ) );
		$text = (string) preg_replace( '/[^a-z0-9]+/', ' ', $text );
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	private static function clip( string $title ): string {
		$title = trim( wp_strip_all_tags( $title ) );
		return mb_strlen( $title ) > self::MAX_TITLE ? mb_substr( $title, 0, self::MAX_TITLE ) . '…' : $title;
	}
}
