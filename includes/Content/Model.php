<?php
declare(strict_types=1);

namespace Denise\Content;

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
 *  group      {type, label?, elements: Element[]} — a section; label names it
 *             (shown as the container's title in Elementor's structure panel)
 *  separator  {type}
 *  spacer     {type, height?:int px}
 *  widget     {type, widget, settings?} — Elementor-only block override: an
 *             explicit widget name + free-form (sanitized) settings. Renders
 *             only on the Elementor adapter; other builders ignore it.
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
		'widget',
		'raw',
	);

	public const INLINE_HTML = array(
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
	public static function sanitize_elements( $elements, bool $allow_raw = false, ?array $allowed_types = null ): array {
		if ( ! is_array( $elements ) ) {
			return array();
		}

		$clean = array();

		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) || empty( $el['type'] ) || ! in_array( $el['type'], self::TYPES, true ) ) {
				continue;
			}

			// Per-post-type block restriction. 'raw' (opaque passthrough) and
			// 'widget' (explicit Elementor block override, a deliberate user
			// request) are exempt: they aren't part of the generic catalog.
			if ( null !== $allowed_types && ! in_array( $el['type'], array( 'raw', 'widget' ), true ) && ! in_array( $el['type'], $allowed_types, true ) ) {
				continue;
			}

			$out = self::sanitize_element( $el, $allow_raw, $allowed_types );
			if ( null !== $out ) {
				$clean[] = $out;
			}
		}

		return $clean;
	}

	private static function sanitize_element( array $el, bool $allow_raw, ?array $allowed_types = null ): ?array {
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
							'elements' => self::sanitize_elements( $col['elements'] ?? array(), $allow_raw, $allowed_types ),
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
					'label'    => sanitize_text_field( (string) ( $el['label'] ?? '' ) ),
					'elements' => self::sanitize_elements( $el['elements'] ?? array(), $allow_raw, $allowed_types ),
				);

			case 'separator':
				return array( 'type' => 'separator' );

			case 'spacer':
				$height = isset( $el['height'] ) ? (int) $el['height'] : 40;
				return array(
					'type'   => 'spacer',
					'height' => max( 10, min( 300, $height ) ),
				);

			case 'widget':
				// Explicit Elementor-block override (escape hatch). Carries a
				// widget type name and a free-form settings object the AI built
				// from the widget's schema. Settings are untrusted — sanitized
				// recursively below. Builders that don't support it ignore it.
				$widget = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) ( $el['widget'] ?? '' ) ) );
				if ( '' === $widget ) {
					return null;
				}
				return array(
					'type'     => 'widget',
					'widget'   => $widget,
					'settings' => is_array( $el['settings'] ?? null ) ? self::sanitize_widget_settings( $el['settings'] ) : array(),
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

	/**
	 * Recursively sanitize free-form Elementor widget settings supplied by the
	 * AI for a `widget` element. AI output is untrusted (docs/RULES.md):
	 * strings pass through wp_kses_post and have script/vbscript URIs stripped
	 * (neutralizing <script>/on*-handler/JS-URL vectors even in raw-HTML-ish
	 * fields), while structure is depth- and breadth-capped. Non-scalar,
	 * non-array values are dropped.
	 *
	 * Also used by the Elementor in-place editing tools, which pass AI-authored
	 * settings straight to Elementor — same untrusted-input rules apply.
	 *
	 * @param mixed $value Raw settings node.
	 * @param int   $depth Current recursion depth.
	 * @return mixed Sanitized node.
	 */
	public static function sanitize_widget_settings( $value, int $depth = 0 ) {
		if ( $depth > 12 ) {
			return null;
		}

		if ( is_array( $value ) ) {
			$out   = array();
			$count = 0;
			foreach ( $value as $k => $v ) {
				if ( $count++ >= 300 ) {
					break;
				}
				// `$` stays: Elementor 4 atomic widgets key typed values as `$$type`.
				$key   = is_int( $k ) ? $k : preg_replace( '/[^A-Za-z0-9_\-$]/', '', (string) $k );
				$clean = self::sanitize_widget_settings( $v, $depth + 1 );
				if ( null !== $clean ) {
					$out[ $key ] = $clean;
				}
			}
			return $out;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$value = wp_kses_post( $value );
			return preg_replace( '/(?:javascript|vbscript)\s*:/i', '', $value );
		}

		return null;
	}

	/** Plain CSS for Elementor local styles: untrusted, so strip script vectors and cap size. */
	public static function sanitize_css( string $css ): string {
		$css = wp_strip_all_tags( $css );
		$css = preg_replace( '/(expression\s*\(|behavior\s*:|javascript\s*:|vbscript\s*:|@import\b|url\s*\(\s*[\'"]?\s*(?:javascript|data):)/i', '', $css );
		return substr( trim( (string) $css ), 0, 20000 );
	}

	private static function text( $value ): string {
		return trim( wp_kses( (string) $value, self::INLINE_HTML ) );
	}
}
