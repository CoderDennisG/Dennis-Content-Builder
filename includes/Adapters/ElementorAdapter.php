<?php
declare(strict_types=1);

namespace Denise\Adapters;

use WP_Post;

/**
 * Elementor adapter: neutral model -> Elementor 4 atomic element tree.
 *
 * Elementor stores its content in the `_elementor_data` post meta, not in
 * post_content, so this adapter's real work happens in persist(). It never
 * writes that JSON itself: it describes the page as the small XML the
 * `elementor/build-composition` ability accepts (one tag per element, a
 * plain settings map per element, a CSS string per element) and lets
 * Elementor validate, build and save through its own document API.
 *
 * Safety (docs/RULES.md): this adapter only ever creates content on a post
 * the Denise tool layer already created as a draft. The AI still only
 * emits the neutral model; the mapping to Elementor widgets is fixed here.
 *
 * Scope today: create-only. Existing Elementor pages are edited in place
 * through the Elementor tools in Ai\Tools, not round-tripped through the
 * neutral model (see parse()).
 */
final class ElementorAdapter implements BuilderAdapter {

	/** Page-level layout, as plain CSS per build-composition. */
	private const SECTION_CSS = 'display:flex;flex-direction:column;gap:24px;padding:60px 20px;max-width:1200px;margin-inline:auto;';
	private const GROUP_CSS   = 'display:flex;flex-direction:column;gap:16px;padding:0;';
	private const ROW_CSS     = 'display:flex;flex-direction:row;gap:24px;padding:0; @media(--mobile){flex-direction:column;}';
	private const COLUMN_CSS  = 'display:flex;flex-direction:column;gap:16px;padding:0;flex:1 1 0;min-width:0;';

	private int $counter = 0;

	/** @var array<string, array> configuration-id => plain settings */
	private array $config = array();

	/** @var array<string, string> configuration-id => css */
	private array $style = array();

	public static function is_available(): bool {
		return ElementorAbilities::is_available();
	}

	public function label(): string {
		return 'Elementor';
	}

	public function supports( WP_Post $post ): bool {
		return 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true );
	}

	public function insert_meta( string $post_type ): array {
		return array(
			'_elementor_edit_mode'     => 'builder',
			'_elementor_template_type' => 'wp-' . $post_type,
		);
	}

	/**
	 * Not implemented: existing Elementor pages are edited in place by
	 * element id (Ai\Tools), never flattened through the neutral model.
	 */
	public function parse( WP_Post $post ): array {
		return array();
	}

	/** Elementor content does not live in post_content. */
	public function serialize( array $elements ): string {
		return '';
	}

	public function persist( int $post_id, array $elements ): void {
		if ( ! self::is_available() ) {
			throw new \RuntimeException( 'Elementor 4.3 or newer (with its MCP module) is not active.' );
		}

		$this->counter = 0;
		$this->config  = array();
		$this->style   = array();

		$xml = $this->to_xml( $elements );

		\Denise\Support\Debug::log(
			"elementor composition ← post {$post_id}",
			array(
				'xml'    => $xml,
				'config' => $this->config,
				'style'  => $this->style,
			)
		);

		$result = ElementorAbilities::run(
			'build-composition',
			array(
				'post_id'        => $post_id,
				'xml_structure'  => $xml,
				'element_config' => $this->config,
				'style'          => $this->style,
				'mode'           => 'replace_children',
			)
		);

		if ( isset( $result['error'] ) ) {
			throw new \RuntimeException( esc_html( (string) $result['error'] ) );
		}

		// Make sure Elementor renders from its data, not the empty post_content.
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
	}

	// ------------------------------------------------------------------
	// Neutral model -> composition XML
	// ------------------------------------------------------------------

	/**
	 * Top level: every group becomes a section; loose elements between
	 * groups are gathered into their own section. Returns the XML; settings
	 * and CSS accumulate in $this->config / $this->style.
	 */
	private function to_xml( array $elements ): string {
		$sections = array();
		$loose    = array();

		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( 'group' === ( $el['type'] ?? '' ) ) {
				if ( $loose ) {
					$sections[] = $this->container( 'e-flexbox', self::SECTION_CSS, $this->children( $loose ), 'Section' );
					$loose      = array();
				}
				$sections[] = $this->container( 'e-flexbox', self::SECTION_CSS, $this->children( (array) ( $el['elements'] ?? array() ) ), (string) ( $el['label'] ?? 'Section' ) );
				continue;
			}
			$loose[] = $el;
		}
		if ( $loose ) {
			$sections[] = $this->container( 'e-flexbox', self::SECTION_CSS, $this->children( $loose ), 'Section' );
		}

		return implode( '', $sections );
	}

	private function children( array $elements ): string {
		$out = '';
		foreach ( $elements as $el ) {
			if ( is_array( $el ) ) {
				$out .= $this->element( $el );
			}
		}
		return $out;
	}

	private function element( array $el ): string {
		switch ( (string) ( $el['type'] ?? '' ) ) {
			case 'heading':
				$level = max( 1, min( 6, (int) ( $el['level'] ?? 2 ) ) );
				return $this->widget(
					'e-heading',
					array(
						'title' => $this->inline( (string) ( $el['text'] ?? '' ) ),
						'tag'   => 'h' . $level,
					)
				);

			case 'paragraph':
				return $this->widget( 'e-paragraph', array( 'paragraph' => $this->inline( (string) ( $el['text'] ?? '' ) ) ) );

			case 'list':
				$tag   = ! empty( $el['ordered'] ) ? 'ol' : 'ul';
				$items = '';
				foreach ( (array) ( $el['items'] ?? array() ) as $item ) {
					$items .= '<li>' . $this->inline( (string) $item ) . '</li>';
				}
				return '' === $items ? '' : $this->widget( 'e-paragraph', array( 'paragraph' => "<{$tag}>{$items}</{$tag}>" ) );

			case 'quote':
				$text = $this->inline( (string) ( $el['text'] ?? '' ) );
				$cite = trim( (string) ( $el['citation'] ?? '' ) );
				$html = '<blockquote>' . $text . ( '' !== $cite ? '<br><em>— ' . esc_html( $cite ) . '</em>' : '' ) . '</blockquote>';
				return $this->widget( 'e-paragraph', array( 'paragraph' => $html ) );

			case 'image':
				$src = array();
				if ( ! empty( $el['id'] ) ) {
					$src['id'] = (int) $el['id'];
				} elseif ( ! empty( $el['url'] ) ) {
					$src['url'] = esc_url_raw( (string) $el['url'] );
					$src['alt'] = sanitize_text_field( (string) ( $el['alt'] ?? '' ) );
				}
				if ( ! $src ) {
					return '';
				}
				return $this->widget(
					'e-image',
					array(
						'image' => array(
							'src'  => $src,
							'size' => 'large',
						),
					)
				);

			case 'button':
				$settings = array( 'text' => $this->inline( (string) ( $el['text'] ?? 'Learn more' ) ) );
				$url      = trim( (string) ( $el['url'] ?? '' ) );
				if ( '' !== $url && '#' !== $url ) {
					$settings['link'] = array(
						'destination'   => esc_url_raw( $url ),
						'isTargetBlank' => false,
					);
				}
				return $this->widget( 'e-button', $settings );

			case 'separator':
				return $this->widget( 'e-divider', array() );

			case 'spacer':
				$height = max( 4, min( 400, (int) ( $el['height'] ?? 40 ) ) );
				return $this->container( 'e-div-block', "height:{$height}px;padding:0;", '', 'Spacer' );

			case 'group':
				return $this->container( 'e-flexbox', self::GROUP_CSS, $this->children( (array) ( $el['elements'] ?? array() ) ), (string) ( $el['label'] ?? 'Group' ) );

			case 'columns':
				$cols = '';
				foreach ( (array) ( $el['columns'] ?? array() ) as $col ) {
					$cols .= $this->container( 'e-flexbox', self::COLUMN_CSS, $this->children( (array) ( $col['elements'] ?? array() ) ), 'Column' );
				}
				return '' === $cols ? '' : $this->container( 'e-flexbox', self::ROW_CSS, $cols, 'Columns' );

			case 'widget':
				// Explicit override: exactly the widget the user dictated, with
				// the plain settings the AI built from its schema.
				$type = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) ( $el['widget'] ?? '' ) ) );
				if ( '' === $type ) {
					return '';
				}
				return $this->widget( $type, (array) ( $el['settings'] ?? array() ) );

			case 'raw':
			default:
				return '';
		}
	}

	private function widget( string $type, array $settings ): string {
		$id                  = $this->next_id( ucfirst( str_replace( array( 'e-', '-' ), array( '', ' ' ), $type ) ) );
		$this->config[ $id ] = $settings;
		return "<{$type} configuration-id=\"{$id}\"></{$type}>";
	}

	private function container( string $type, string $css, string $children, string $label = 'Container' ): string {
		$id                  = $this->next_id( $label );
		$this->config[ $id ] = array();
		$this->style[ $id ]  = $css;
		return "<{$type} configuration-id=\"{$id}\">{$children}</{$type}>";
	}

	/** Unique configuration-id; Elementor uses it as the element's Navigator title. */
	private function next_id( string $label ): string {
		++$this->counter;
		$slug = trim( preg_replace( '/[^A-Za-z0-9]+/', '-', $label ), '-' );
		return ( '' !== $slug ? $slug : 'Element' ) . '-' . $this->counter;
	}

	/** Inline text as Elementor's atomic text props accept it. */
	private function inline( string $text ): string {
		return trim( wp_kses( $text, \Denise\Content\Model::INLINE_HTML ) );
	}
}
