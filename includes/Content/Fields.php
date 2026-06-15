<?php
declare(strict_types=1);

namespace DCB\Content;

/**
 * Custom-field discovery, read, and write across ACF and native
 * registered meta.
 *
 * Schema is recursive (repeaters / groups / flexible content nest), so
 * the engine can READ the full tree today. WRITING is enabled only for
 * the reliable scalar/choice types in this cut; complex types are
 * returned as read-only until their write path is proven (see
 * docs/ROADMAP.md v0.6.0 phasing).
 */
final class Fields {

	/** ACF field types the AI may write in this cut. */
	private const WRITABLE_ACF = array(
		'text',
		'textarea',
		'wysiwyg',
		'number',
		'range',
		'email',
		'url',
		'password',
		'select',
		'checkbox',
		'radio',
		'button_group',
		'true_false',
		'date_picker',
		'date_time_picker',
		'time_picker',
		'color_picker',
	);

	private static function acf_active(): bool {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' );
	}

	// ------------------------------------------------------------------
	// Discovery
	// ------------------------------------------------------------------

	/**
	 * Top-level field schema nodes for a post type (ACF + native).
	 * Pass $post_id when known so ACF location rules resolve precisely.
	 *
	 * @return array<int,array> Recursive nodes.
	 */
	public static function discover( string $post_type, int $post_id = 0 ): array {
		$nodes = array();

		if ( self::acf_active() ) {
			$args = array( 'post_type' => $post_type );
			if ( $post_id ) {
				$args['post_id'] = $post_id;
			}
			foreach ( acf_get_field_groups( $args ) as $group ) {
				foreach ( (array) acf_get_fields( $group['key'] ) as $field ) {
					$nodes[] = self::normalize_acf( $field );
				}
			}
		}

		foreach ( self::native_meta( $post_type ) as $node ) {
			$nodes[] = $node;
		}

		return $nodes;
	}

	private static function normalize_acf( array $field ): array {
		$type = (string) ( $field['type'] ?? 'text' );

		$node = array(
			'source'       => 'acf',
			'key'          => (string) ( $field['key'] ?? '' ),
			'name'         => (string) ( $field['name'] ?? '' ),
			'label'        => (string) ( $field['label'] ?? '' ),
			'type'         => $type,
			'instructions' => (string) ( $field['instructions'] ?? '' ),
			'required'     => ! empty( $field['required'] ),
			'writable'     => in_array( $type, self::WRITABLE_ACF, true ),
		);

		if ( isset( $field['choices'] ) && is_array( $field['choices'] ) ) {
			$node['choices']  = $field['choices'];
			$node['multiple'] = ! empty( $field['multiple'] ) || 'checkbox' === $type;
		}

		if ( in_array( $type, array( 'repeater', 'group' ), true ) && ! empty( $field['sub_fields'] ) ) {
			$node['sub_fields'] = array_map( array( self::class, 'normalize_acf' ), $field['sub_fields'] );
		}

		if ( 'flexible_content' === $type && ! empty( $field['layouts'] ) ) {
			$node['layouts'] = array();
			foreach ( $field['layouts'] as $layout ) {
				$node['layouts'][] = array(
					'name'       => (string) ( $layout['name'] ?? '' ),
					'label'      => (string) ( $layout['label'] ?? '' ),
					'sub_fields' => ! empty( $layout['sub_fields'] )
						? array_map( array( self::class, 'normalize_acf' ), $layout['sub_fields'] )
						: array(),
				);
			}
		}

		return $node;
	}

	/**
	 * Native registered meta for a post type. Scalars are writable;
	 * arrays/objects are read-only in this cut.
	 *
	 * @return array<int,array>
	 */
	private static function native_meta( string $post_type ): array {
		if ( ! function_exists( 'get_registered_meta_keys' ) ) {
			return array();
		}

		$nodes = array();
		foreach ( get_registered_meta_keys( 'post', $post_type ) as $key => $args ) {
			// Skip our own internal keys and anything private.
			if ( '_' === substr( (string) $key, 0, 1 ) ) {
				continue;
			}
			$type     = (string) ( $args['type'] ?? 'string' );
			$scalar   = in_array( $type, array( 'string', 'integer', 'number', 'boolean' ), true );
			$nodes[]  = array(
				'source'   => 'meta',
				'key'      => (string) $key,
				'name'     => (string) $key,
				'label'    => '' !== (string) ( $args['description'] ?? '' ) ? (string) $args['description'] : (string) $key,
				'type'     => $type,
				'single'   => ! empty( $args['single'] ),
				'writable' => $scalar && ! empty( $args['single'] ),
			);
		}

		return $nodes;
	}

	/** Flattened rows for the settings checklist (top-level tickable). */
	public static function ui_list( string $post_type ): array {
		$rows = array();
		foreach ( self::discover( $post_type ) as $node ) {
			self::flatten( $node, 0, $rows );
		}
		return $rows;
	}

	private static function flatten( array $node, int $depth, array &$rows ): void {
		$rows[] = array(
			'name'     => $node['name'],
			'label'    => $node['label'] ? $node['label'] : $node['name'],
			'type'     => $node['type'],
			'writable' => ! empty( $node['writable'] ),
			'depth'    => $depth,
			'source'   => $node['source'],
		);

		foreach ( $node['sub_fields'] ?? array() as $sub ) {
			self::flatten( $sub, $depth + 1, $rows );
		}
		foreach ( $node['layouts'] ?? array() as $layout ) {
			foreach ( $layout['sub_fields'] as $sub ) {
				self::flatten( $sub, $depth + 1, $rows );
			}
		}
	}

	// ------------------------------------------------------------------
	// Read
	// ------------------------------------------------------------------

	/**
	 * Allowed fields' schema + current values for a post.
	 *
	 * @param string[] $allowed Top-level field names the AI may see.
	 * @return array{schema:array, values:array}
	 */
	public static function read( int $post_id, string $post_type, array $allowed ): array {
		$schema = array();
		$values = array();

		foreach ( self::discover( $post_type, $post_id ) as $node ) {
			if ( ! in_array( $node['name'], $allowed, true ) ) {
				continue;
			}
			$schema[]               = $node;
			$values[ $node['name'] ] = self::read_one( $post_id, $node );
		}

		return array(
			'schema' => $schema,
			'values' => $values,
		);
	}

	private static function read_one( int $post_id, array $node ) {
		if ( 'acf' === $node['source'] && function_exists( 'get_field' ) ) {
			return get_field( $node['name'], $post_id, false ); // Raw, round-trippable.
		}
		return get_post_meta( $post_id, $node['name'], ! empty( $node['single'] ) );
	}

	// ------------------------------------------------------------------
	// Write
	// ------------------------------------------------------------------

	/**
	 * Validate and write a value tree. Only writable (scalar/choice)
	 * fields are accepted in this cut; complex fields return an error so
	 * the model learns they're read-only. Prior values are snapshotted
	 * to post meta first (revisions don't cover meta).
	 *
	 * @return array{written:string[], errors:array<string,string>}
	 */
	public static function write( int $post_id, string $post_type, array $allowed, array $tree ): array {
		$nodes = array();
		foreach ( self::discover( $post_type, $post_id ) as $node ) {
			$nodes[ $node['name'] ] = $node;
		}

		$written  = array();
		$errors   = array();
		$snapshot = array();

		foreach ( $tree as $name => $value ) {
			$name = (string) $name;

			if ( ! in_array( $name, $allowed, true ) || ! isset( $nodes[ $name ] ) ) {
				$errors[ $name ] = 'Unknown or not-allowed field.';
				continue;
			}

			$node = $nodes[ $name ];
			if ( empty( $node['writable'] ) ) {
				$errors[ $name ] = sprintf( 'Field type "%s" is read-only in this version.', $node['type'] );
				continue;
			}

			$clean = self::validate( $node, $value );
			if ( null === $clean && null !== $value ) {
				$errors[ $name ] = 'Invalid value for this field.';
				continue;
			}

			$snapshot[ $name ] = self::read_one( $post_id, $node );

			if ( 'acf' === $node['source'] && function_exists( 'update_field' ) ) {
				update_field( $node['key'] ? $node['key'] : $node['name'], $clean, $post_id );
			} else {
				update_post_meta( $post_id, $node['name'], $clean );
			}

			$written[] = $name;
		}

		if ( $snapshot ) {
			update_post_meta( $post_id, '_dcb_field_backup', $snapshot );
		}

		return array(
			'written' => $written,
			'errors'  => $errors,
		);
	}

	/**
	 * Type-aware validation/sanitization. Returns the clean value, or
	 * null if invalid.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function validate( array $node, $value ) {
		$type    = $node['type'];
		$choices = isset( $node['choices'] ) && is_array( $node['choices'] ) ? array_map( 'strval', array_keys( $node['choices'] ) ) : array();

		switch ( $type ) {
			case 'text':
			case 'string':
				return sanitize_text_field( (string) $value );

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'wysiwyg':
				return wp_kses_post( (string) $value );

			case 'email':
				$email = sanitize_email( (string) $value );
				return is_email( $email ) ? $email : null;

			case 'url':
				return esc_url_raw( (string) $value );

			case 'password':
				return (string) $value;

			case 'number':
			case 'range':
			case 'integer':
				return is_numeric( $value ) ? $value + 0 : null;

			case 'boolean':
			case 'true_false':
				return empty( $value ) ? 0 : 1;

			case 'select':
			case 'radio':
			case 'button_group':
				if ( ! empty( $node['multiple'] ) ) {
					return self::filter_choices( (array) $value, $choices );
				}
				return in_array( (string) $value, $choices, true ) ? (string) $value : null;

			case 'checkbox':
				return self::filter_choices( (array) $value, $choices );

			case 'color_picker':
				return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string) $value ) ? (string) $value : null;

			case 'date_picker':
				return preg_match( '/^\d{4}-?\d{2}-?\d{2}$/', (string) $value ) ? str_replace( '-', '', (string) $value ) : null;

			case 'date_time_picker':
			case 'time_picker':
				return sanitize_text_field( (string) $value );
		}

		return null;
	}

	private static function filter_choices( array $values, array $choices ): array {
		$out = array();
		foreach ( $values as $v ) {
			if ( in_array( (string) $v, $choices, true ) ) {
				$out[] = (string) $v;
			}
		}
		return $out;
	}
}
