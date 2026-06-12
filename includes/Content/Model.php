<?php
declare(strict_types=1);

namespace DCB\Content;

/**
 * The neutral content model: validation + sanitization of AI-produced
 * element trees before they reach any adapter.
 *
 * Element shapes (v0 prototype vocabulary):
 *  heading    {type, text, level?:1-4}
 *  paragraph  {type, text}
 *  list       {type, ordered?:bool, items: string[]}
 *  quote      {type, text, citation?}
 *  image      {type, url, alt?, caption?, id?:int}
 *  button     {type, text, url}
 *  columns    {type, columns: [{elements: Element[]}]}
 *  group      {type, elements: Element[]}
 *  separator  {type}
 *  spacer     {type, height?:int px}
 *  raw        {type, block} — opaque passthrough, never AI-authored
 */
final class Model {

	private const TYPES = array(
		'heading',
		'paragraph',
		'list',
		'quote',
		'image',
		'button',
		'columns',
		'group',
		'separator',
		'spacer',
		'raw',
	);

	private const INLINE_HTML = array(
		'strong' => array(),
		'em'     => array(),
		'b'      => array(),
		'i'      => array(),
		'br'     => array(),
		'code'   => array(),
		'a'      => array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
		),
	);

	/**
	 * Sanitize an AI-supplied element tree. Unknown types and malformed
	 * entries are dropped; all text passes through a strict inline-HTML
	 * allowlist. Never trust the model.
	 *
	 * @param mixed $elements Raw decoded elements array.
	 * @param bool  $allow_raw Whether opaque raw blocks are allowed (only
	 *                         when the tree came from parsing existing
	 *                         content, not from the AI).
	 */
	public static function sanitize_elements( $elements, bool $allow_raw = false ): array {
		if ( ! is_array( $elements ) ) {
			return array();
		}

		$clean = array();

		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) || empty( $el['type'] ) || ! in_array( $el['type'], self::TYPES, true ) ) {
				continue;
			}

			$out = self::sanitize_element( $el, $allow_raw );
			if ( null !== $out ) {
				$clean[] = $out;
			}
		}

		return $clean;
	}

	private static function sanitize_element( array $el, bool $allow_raw ): ?array {
		switch ( $el['type'] ) {
			case 'heading':
				$level = isset( $el['level'] ) ? (int) $el['level'] : 2;
				return array(
					'type'  => 'heading',
					'level' => max( 1, min( 4, $level ) ),
					'text'  => self::text( $el['text'] ?? '' ),
				);

			case 'paragraph':
				return array(
					'type' => 'paragraph',
					'text' => self::text( $el['text'] ?? '' ),
				);

			case 'list':
				$items = array();
				foreach ( (array) ( $el['items'] ?? array() ) as $item ) {
					if ( is_string( $item ) && '' !== trim( $item ) ) {
						$items[] = self::text( $item );
					}
				}
				if ( ! $items ) {
					return null;
				}
				return array(
					'type'    => 'list',
					'ordered' => ! empty( $el['ordered'] ),
					'items'   => $items,
				);

			case 'quote':
				return array(
					'type'     => 'quote',
					'text'     => self::text( $el['text'] ?? '' ),
					'citation' => self::text( $el['citation'] ?? '' ),
				);

			case 'image':
				$url = esc_url_raw( (string) ( $el['url'] ?? '' ) );
				if ( '' === $url ) {
					return null;
				}
				return array(
					'type'    => 'image',
					'url'     => $url,
					'id'      => isset( $el['id'] ) ? absint( $el['id'] ) : 0,
					'alt'     => sanitize_text_field( (string) ( $el['alt'] ?? '' ) ),
					'caption' => self::text( $el['caption'] ?? '' ),
				);

			case 'button':
				return array(
					'type' => 'button',
					'text' => self::text( $el['text'] ?? '' ),
					'url'  => esc_url_raw( (string) ( $el['url'] ?? '#' ) ),
				);

			case 'columns':
				$cols = array();
				foreach ( (array) ( $el['columns'] ?? array() ) as $col ) {
					if ( is_array( $col ) ) {
						$cols[] = array(
							'elements' => self::sanitize_elements( $col['elements'] ?? array(), $allow_raw ),
						);
					}
				}
				if ( count( $cols ) < 2 ) {
					return null;
				}
				return array(
					'type'    => 'columns',
					'columns' => $cols,
				);

			case 'group':
				return array(
					'type'     => 'group',
					'elements' => self::sanitize_elements( $el['elements'] ?? array(), $allow_raw ),
				);

			case 'separator':
				return array( 'type' => 'separator' );

			case 'spacer':
				$height = isset( $el['height'] ) ? (int) $el['height'] : 40;
				return array(
					'type'   => 'spacer',
					'height' => max( 10, min( 300, $height ) ),
				);

			case 'raw':
				if ( ! $allow_raw || empty( $el['block'] ) || ! is_string( $el['block'] ) ) {
					return null;
				}
				return array(
					'type'  => 'raw',
					'block' => $el['block'],
				);
		}

		return null;
	}

	private static function text( $value ): string {
		return trim( wp_kses( (string) $value, self::INLINE_HTML ) );
	}
}
