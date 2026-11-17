<?php
/**
 * Legacy (V3) Elementor widgets on a draft: text editor, icon list,
 * shortcode, heading… Elementor's manage-elements ability refuses them
 * ("elementor_v3_not_supported") and reads their settings as empty, so
 * Denise reads their content keys from the stored element data and, on a
 * DRAFT only, writes text changes back through Elementor's Document::save,
 * the same save the editor performs. Content keys only: never styles.
 *
 * @package Denise
 */

declare(strict_types=1);

namespace Denise\Adapters;

/**
 * Read/update of content settings on legacy widgets.
 */
final class ElementorLegacy {

	/** Content-bearing settings of legacy widgets Denise may read and change. */
	private const CONTENT_KEYS = array(
		'title',
		'editor',
		'text',
		'html',
		'shortcode',
		'button_text',
		'title_text',
		'description_text',
		'heading',
		'sub_heading',
		'caption',
		'alt',
		'custom_text',
		'label',
		'placeholder',
		'tab_title',
		'tab_content',
		'content',
		'header_size',
		'size',
		'link',
		'url',
	);

	/** Repeater settings whose rows carry text (row keys Denise may change). */
	private const ROW_KEYS = array(
		'icon_list' => array( 'text', 'link' ),
		'tabs'      => array( 'tab_title', 'tab_content' ),
		'slides'    => array( 'heading', 'description', 'button_text', 'link' ),
	);

	private const MAX_TEXT = 4000;

	/** True for a widget/element type that has no atomic (V4) definition. */
	public static function is_legacy( string $type ): bool {
		if ( '' === $type ) {
			return false;
		}
		if ( ! class_exists( '\Elementor\Modules\GlobalClasses\Utils\Atomic_Elements_Utils' ) ) {
			return true;
		}
		$instance = \Elementor\Modules\GlobalClasses\Utils\Atomic_Elements_Utils::get_element_instance( $type );
		return ! $instance || ! \Elementor\Modules\GlobalClasses\Utils\Atomic_Elements_Utils::is_atomic_element( $instance );
	}

	/**
	 * Fill in the content settings of legacy elements in a get-page-structure
	 * tree (which leaves them empty) from the stored element data.
	 */
	public static function enrich( int $post_id, array $tree ): array {
		$stored = self::elements( $post_id );
		if ( ! $stored ) {
			return $tree;
		}
		$index = array();
		self::index( $stored, $index );
		// The ability may hand back nested objects; work on plain arrays.
		$plain = json_decode( (string) wp_json_encode( $tree ), true );
		return is_array( $plain ) ? self::fill( $plain, $index ) : $tree;
	}

	/**
	 * Change content settings of one legacy element on a draft.
	 *
	 * @param array<string,mixed> $settings Plain settings (already sanitized as untrusted input).
	 * @return array{ok:true,changed:string[],ignored:string[]}|array{error:string}
	 */
	public static function update( int $post_id, string $element_id, array $settings ): array {
		$post = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'Post not found or not editable by this user.' );
		}
		if ( 'draft' !== $post->post_status ) {
			return array( 'error' => 'This is a legacy (V3) Elementor widget and the page is ' . $post->post_status . ': Elementor only lets Denise change legacy widgets on drafts. Ask the person to change it in the Elementor editor.' );
		}
		$document = class_exists( '\Elementor\Plugin' ) ? \Elementor\Plugin::$instance->documents->get( $post_id, false ) : null;
		if ( ! $document ) {
			return array( 'error' => 'Elementor cannot open this post as a document.' );
		}
		$elements = $document->get_elements_data();
		$found    = false;
		$changed  = array();
		$ignored  = array();
		$elements = self::walk_update( $elements, $element_id, $settings, $found, $changed, $ignored );
		if ( ! $found ) {
			return array( 'error' => "No element with id \"{$element_id}\" on this page." );
		}
		if ( ! $changed ) {
			return array( 'error' => 'None of those settings are content keys of this legacy widget (' . implode( ', ', $ignored ) . '). Legacy widgets accept text keys such as title, editor, text, shortcode, icon_list rows.' );
		}
		if ( ! $document->save( array( 'elements' => $elements ) ) ) {
			return array( 'error' => 'Elementor refused to save the draft for this user.' );
		}
		return array(
			'ok'      => true,
			'changed' => $changed,
			'ignored' => $ignored,
		);
	}

	// ---- internals ------------------------------------------------------

	private static function elements( int $post_id ): array {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return array();
		}
		$document = \Elementor\Plugin::$instance->documents->get( $post_id, false );
		return $document ? (array) $document->get_elements_data() : array();
	}

	private static function index( array $elements, array &$index ): void {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) || empty( $el['id'] ) ) {
				continue;
			}
			$index[ (string) $el['id'] ] = $el;
			if ( ! empty( $el['elements'] ) ) {
				self::index( $el['elements'], $index );
			}
		}
	}

	private static function fill( array $tree, array $index ): array {
		foreach ( $tree as $k => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$id = (string) ( $node['id'] ?? '' );
			if ( '' === $id ) {
				// A wrapper or a list of nodes, not an element: look inside.
				$tree[ $k ] = self::fill( $node, $index );
				continue;
			}
			$type = (string) ( $node['widgetType'] ?? $node['elType'] ?? '' );
			if ( isset( $index[ $id ] ) && empty( $node['settings'] ) && self::is_legacy( $type ) ) {
				$content = self::content_of( (array) ( $index[ $id ]['settings'] ?? array() ) );
				if ( $content ) {
					$node['settings'] = $content;
					$node['legacy']   = true;
				}
			}
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$node['elements'] = self::fill( $node['elements'], $index );
			}
			$tree[ $k ] = $node;
		}
		return $tree;
	}

	/** The readable content of legacy settings: plain strings, link urls, repeater rows' text keys. */
	private static function content_of( array $settings ): array {
		$out = array();
		foreach ( self::CONTENT_KEYS as $key ) {
			if ( ! isset( $settings[ $key ] ) ) {
				continue;
			}
			$v = $settings[ $key ];
			if ( is_array( $v ) && isset( $v['url'] ) ) {
				$v = (string) $v['url'];
			}
			if ( is_scalar( $v ) && '' !== (string) $v ) {
				$out[ $key ] = self::clip( (string) $v );
			}
		}
		foreach ( self::ROW_KEYS as $key => $row_keys ) {
			if ( empty( $settings[ $key ] ) || ! is_array( $settings[ $key ] ) ) {
				continue;
			}
			$rows = array();
			foreach ( $settings[ $key ] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$clean = array();
				foreach ( $row_keys as $rk ) {
					if ( ! isset( $row[ $rk ] ) ) {
						continue;
					}
					$v = $row[ $rk ];
					if ( is_array( $v ) && isset( $v['url'] ) ) {
						$v = (string) $v['url'];
					}
					if ( is_scalar( $v ) && '' !== (string) $v ) {
						$clean[ $rk ] = self::clip( (string) $v );
					}
				}
				$rows[] = $clean;
			}
			$out[ $key ] = $rows;
		}
		return $out;
	}

	/**
	 * Apply $settings to the element with $element_id. Text keys are set;
	 * repeater rows replace the row list but inherit icon, id and link from
	 * the existing row at the same position (or the first row), so the
	 * widget keeps its look.
	 */
	private static function walk_update( array $elements, string $element_id, array $settings, bool &$found, array &$changed, array &$ignored ): array {
		foreach ( $elements as $i => $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( (string) ( $el['id'] ?? '' ) === $element_id ) {
				$found    = true;
				$existing = (array) ( $el['settings'] ?? array() );
				foreach ( $settings as $key => $value ) {
					$key = (string) $key;
					if ( isset( self::ROW_KEYS[ $key ] ) ) {
						if ( ! is_array( $value ) ) {
							$ignored[] = $key;
							continue;
						}
						$existing[ $key ] = self::merge_rows( (array) ( $existing[ $key ] ?? array() ), $value, self::ROW_KEYS[ $key ] );
						$changed[]        = $key;
						continue;
					}
					if ( ! in_array( $key, self::CONTENT_KEYS, true ) ) {
						$ignored[] = $key;
						continue;
					}
					if ( in_array( $key, array( 'link', 'url' ), true ) && 'link' === $key ) {
						$url              = is_array( $value ) ? (string) ( $value['url'] ?? $value['destination'] ?? '' ) : (string) $value;
						$existing['link'] = array_merge( is_array( $existing['link'] ?? null ) ? $existing['link'] : array(), array( 'url' => esc_url_raw( $url ) ) );
						$changed[]        = $key;
						continue;
					}
					if ( ! is_scalar( $value ) ) {
						$ignored[] = $key;
						continue;
					}
					$existing[ $key ] = self::clip( (string) $value );
					$changed[]        = $key;
				}
				$el['settings'] = $existing;
				$elements[ $i ] = $el;
				return $elements;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = self::walk_update( $el['elements'], $element_id, $settings, $found, $changed, $ignored );
				$elements[ $i ] = $el;
				if ( $found ) {
					return $elements;
				}
			}
		}
		return $elements;
	}

	private static function merge_rows( array $current, array $rows, array $row_keys ): array {
		$out      = array();
		$template = isset( $current[0] ) && is_array( $current[0] ) ? $current[0] : array();
		$n        = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$base = isset( $current[ $n ] ) && is_array( $current[ $n ] ) ? $current[ $n ] : $template;
			$new  = $base;
			if ( ! isset( $current[ $n ] ) || ! is_array( $current[ $n ] ) ) {
				$new['_id'] = substr( md5( uniqid( (string) $n, true ) ), 0, 7 );
			}
			foreach ( $row_keys as $rk ) {
				if ( ! array_key_exists( $rk, $row ) ) {
					continue;
				}
				$v = $row[ $rk ];
				if ( 'link' === $rk ) {
					$url         = is_array( $v ) ? (string) ( $v['url'] ?? '' ) : (string) $v;
					$new['link'] = array_merge( is_array( $new['link'] ?? null ) ? $new['link'] : array(), array( 'url' => esc_url_raw( $url ) ) );
					continue;
				}
				if ( is_scalar( $v ) ) {
					$new[ $rk ] = self::clip( (string) $v );
				}
			}
			$out[] = $new;
			++$n;
		}
		return $out;
	}

	private static function clip( string $text ): string {
		return mb_strlen( $text ) > self::MAX_TEXT ? mb_substr( $text, 0, self::MAX_TEXT ) : $text;
	}
}
