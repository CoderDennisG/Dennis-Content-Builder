<?php
declare(strict_types=1);

namespace DCB\Adapters;

use WP_Post;

/**
 * Gutenberg adapter: neutral model <-> core block markup.
 *
 * Serialization templates must byte-match what each core block's save
 * function produces, otherwise the editor flags the block as invalid.
 * That exactness is why the AI never writes markup directly.
 */
final class GutenbergAdapter implements BuilderAdapter {

	public function supports( WP_Post $post ): bool {
		// v0: Gutenberg is the only adapter; it owns everything.
		return true;
	}

	public function label(): string {
		return 'Gutenberg';
	}

	// ------------------------------------------------------------------
	// Serialize: neutral model -> block markup
	// ------------------------------------------------------------------

	public function serialize( array $elements ): string {
		$out = array();

		foreach ( $elements as $el ) {
			$markup = $this->serialize_element( $el );
			if ( '' !== $markup ) {
				$out[] = $markup;
			}
		}

		return implode( "\n\n", $out );
	}

	private function serialize_element( array $el ): string {
		switch ( $el['type'] ) {
			case 'heading':
				$level = (int) ( $el['level'] ?? 2 );
				$attrs = 2 === $level ? '' : ' ' . wp_json_encode( array( 'level' => $level ) );
				return "<!-- wp:heading{$attrs} -->\n<h{$level} class=\"wp-block-heading\">{$el['text']}</h{$level}>\n<!-- /wp:heading -->";

			case 'paragraph':
				return "<!-- wp:paragraph -->\n<p>{$el['text']}</p>\n<!-- /wp:paragraph -->";

			case 'list':
				$ordered = ! empty( $el['ordered'] );
				$tag     = $ordered ? 'ol' : 'ul';
				$attrs   = $ordered ? ' ' . wp_json_encode( array( 'ordered' => true ) ) : '';
				$items   = array();
				foreach ( $el['items'] as $item ) {
					$items[] = "<!-- wp:list-item -->\n<li>{$item}</li>\n<!-- /wp:list-item -->";
				}
				$inner = implode( "\n\n", $items );
				return "<!-- wp:list{$attrs} -->\n<{$tag} class=\"wp-block-list\">{$inner}</{$tag}>\n<!-- /wp:list -->";

			case 'quote':
				$cite = '' !== ( $el['citation'] ?? '' ) ? "<cite>{$el['citation']}</cite>" : '';
				return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>{$el['text']}</p>\n<!-- /wp:paragraph -->{$cite}</blockquote>\n<!-- /wp:quote -->";

			case 'image':
				$id      = (int) ( $el['id'] ?? 0 );
				$alt     = esc_attr( $el['alt'] ?? '' );
				$url     = esc_url( $el['url'] );
				$caption = $el['caption'] ?? '';

				$attrs = array( 'sizeSlug' => 'large' );
				$class = 'wp-block-image size-large';
				$img   = "<img src=\"{$url}\" alt=\"{$alt}\"/>";

				if ( $id > 0 ) {
					$attrs = array(
						'id'              => $id,
						'sizeSlug'        => 'large',
						'linkDestination' => 'none',
					);
					$img   = "<img src=\"{$url}\" alt=\"{$alt}\" class=\"wp-image-{$id}\"/>";
				}

				$figcaption = '' !== $caption ? "<figcaption class=\"wp-element-caption\">{$caption}</figcaption>" : '';
				$json       = wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES );

				return "<!-- wp:image {$json} -->\n<figure class=\"{$class}\">{$img}{$figcaption}</figure>\n<!-- /wp:image -->";

			case 'button':
				$url = esc_url( $el['url'] );
				return "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"{$url}\">{$el['text']}</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->";

			case 'columns':
				$cols = array();
				foreach ( $el['columns'] as $col ) {
					$inner  = $this->serialize( $col['elements'] );
					$cols[] = "<!-- wp:column -->\n<div class=\"wp-block-column\">" . ( '' !== $inner ? "\n{$inner}\n" : '' ) . "</div>\n<!-- /wp:column -->";
				}
				$inner = implode( "\n\n", $cols );
				return "<!-- wp:columns -->\n<div class=\"wp-block-columns\">{$inner}</div>\n<!-- /wp:columns -->";

			case 'group':
				$inner = $this->serialize( $el['elements'] );
				return "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\">" . ( '' !== $inner ? "\n{$inner}\n" : '' ) . "</div>\n<!-- /wp:group -->";

			case 'separator':
				return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";

			case 'spacer':
				$height = (int) ( $el['height'] ?? 40 );
				return "<!-- wp:spacer {\"height\":\"{$height}px\"} -->\n<div style=\"height:{$height}px\" aria-hidden=\"true\" class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->";

			case 'raw':
				return (string) $el['block'];
		}

		return '';
	}

	// ------------------------------------------------------------------
	// Parse: block markup -> neutral model
	// ------------------------------------------------------------------

	public function parse( WP_Post $post ): array {
		$blocks = parse_blocks( $post->post_content );
		return $this->parse_blocks( $blocks );
	}

	private function parse_blocks( array $blocks ): array {
		$elements = array();

		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				// Freeform whitespace between blocks.
				if ( '' === trim( $block['innerHTML'] ) ) {
					continue;
				}
				$elements[] = array(
					'type'  => 'raw',
					'block' => $block['innerHTML'],
				);
				continue;
			}

			$el = $this->parse_block( $block );
			if ( null !== $el ) {
				$elements[] = $el;
			}
		}

		return $elements;
	}

	private function parse_block( array $block ): ?array {
		$name  = $block['blockName'];
		$attrs = $block['attrs'] ?? array();
		$html  = $block['innerHTML'];

		switch ( $name ) {
			case 'core/heading':
				$level = (int) ( $attrs['level'] ?? 2 );
				return array(
					'type'  => 'heading',
					'level' => $level,
					'text'  => $this->inner_text( $html, "h{$level}" ),
				);

			case 'core/paragraph':
				$text = $this->inner_text( $html, 'p' );
				return '' === $text ? null : array(
					'type' => 'paragraph',
					'text' => $text,
				);

			case 'core/list':
				$items = array();
				foreach ( $block['innerBlocks'] as $inner ) {
					if ( 'core/list-item' === $inner['blockName'] ) {
						$items[] = $this->inner_text( $inner['innerHTML'], 'li' );
					}
				}
				return array(
					'type'    => 'list',
					'ordered' => ! empty( $attrs['ordered'] ),
					'items'   => $items,
				);

			case 'core/quote':
				$text = '';
				foreach ( $block['innerBlocks'] as $inner ) {
					if ( 'core/paragraph' === $inner['blockName'] ) {
						$text = $this->inner_text( $inner['innerHTML'], 'p' );
						break;
					}
				}
				return array(
					'type'     => 'quote',
					'text'     => $text,
					'citation' => $this->inner_text( $html, 'cite' ),
				);

			case 'core/image':
				preg_match( '#<img[^>]*src="([^"]*)"#', $html, $src );
				preg_match( '#<img[^>]*alt="([^"]*)"#', $html, $alt );
				return array(
					'type'    => 'image',
					'url'     => $src[1] ?? '',
					'id'      => (int) ( $attrs['id'] ?? 0 ),
					'alt'     => $alt[1] ?? '',
					'caption' => $this->inner_text( $html, 'figcaption' ),
				);

			case 'core/buttons':
				// Flatten: surface the first button; extra buttons pass through raw.
				foreach ( $block['innerBlocks'] as $inner ) {
					if ( 'core/button' === $inner['blockName'] ) {
						preg_match( '#href="([^"]*)"#', $inner['innerHTML'], $href );
						return array(
							'type' => 'button',
							'text' => $this->inner_text( $inner['innerHTML'], 'a' ),
							'url'  => $href[1] ?? '#',
						);
					}
				}
				return null;

			case 'core/columns':
				$cols = array();
				foreach ( $block['innerBlocks'] as $inner ) {
					if ( 'core/column' === $inner['blockName'] ) {
						$cols[] = array( 'elements' => $this->parse_blocks( $inner['innerBlocks'] ) );
					}
				}
				return array(
					'type'    => 'columns',
					'columns' => $cols,
				);

			case 'core/group':
				return array(
					'type'     => 'group',
					'elements' => $this->parse_blocks( $block['innerBlocks'] ),
				);

			case 'core/separator':
				return array( 'type' => 'separator' );

			case 'core/spacer':
				return array(
					'type'   => 'spacer',
					'height' => (int) preg_replace( '/\D/', '', (string) ( $attrs['height'] ?? '40' ) ),
				);
		}

		// Unknown block (third-party or unsupported core): opaque passthrough.
		return array(
			'type'  => 'raw',
			'label' => $name,
			'block' => serialize_block( $block ),
		);
	}

	/** Extract inner HTML of the first <tag> in a fragment (inline markup kept). */
	private function inner_text( string $html, string $tag ): string {
		if ( preg_match( "#<{$tag}[^>]*>(.*?)</{$tag}>#s", $html, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}
}
