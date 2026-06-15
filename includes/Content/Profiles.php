<?php
declare(strict_types=1);

namespace DCB\Content;

use WP_Post_Type;

/**
 * Per-post-type profiles: eligibility, writing guidance, and an allowed
 * block subset. One engine, one model — the profile is what makes the
 * assistant behave differently per post type.
 *
 * Stored in option dcb_profiles keyed by post type slug:
 *   { enabled: bool, instructions: string, allowed_blocks: string[] }
 *
 * Custom fields (v0.5.0) will extend this shape; not handled here yet.
 */
final class Profiles {

	public const OPTION = 'dcb_profiles';

	/** Post types enabled by default before the admin configures anything. */
	private const DEFAULT_ENABLED = array( 'page', 'post' );

	/** Internal post types that are never AI-managed. */
	private const DENY = array(
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
	);

	/**
	 * Selectable block (element) types and their labels. 'raw' is omitted
	 * on purpose: it's opaque passthrough, never something the admin picks
	 * or the AI authors.
	 *
	 * @return array<string,string>
	 */
	public static function block_catalog(): array {
		return array(
			'heading'   => __( 'Heading', 'dennis-content-builder' ),
			'paragraph' => __( 'Paragraph', 'dennis-content-builder' ),
			'list'      => __( 'List', 'dennis-content-builder' ),
			'quote'     => __( 'Quote', 'dennis-content-builder' ),
			'image'     => __( 'Image', 'dennis-content-builder' ),
			'button'    => __( 'Button', 'dennis-content-builder' ),
			'columns'   => __( 'Columns', 'dennis-content-builder' ),
			'group'     => __( 'Group / section', 'dennis-content-builder' ),
			'separator' => __( 'Separator', 'dennis-content-builder' ),
			'spacer'    => __( 'Spacer', 'dennis-content-builder' ),
		);
	}

	/**
	 * Post types the admin may configure (public/UI types, minus internals).
	 *
	 * @return WP_Post_Type[]
	 */
	public static function candidate_post_types(): array {
		$types = get_post_types( array( 'show_ui' => true ), 'objects' );

		return array_values(
			array_filter(
				$types,
				static fn( WP_Post_Type $pt ) => ! in_array( $pt->name, self::DENY, true )
			)
		);
	}

	/** @return array<string,array> Stored profiles, unmerged. */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * A type's profile, merged with defaults.
	 *
	 * @return array{enabled:bool, instructions:string, allowed_blocks:array}
	 */
	public static function get( string $type ): array {
		$all   = self::all();
		$saved = isset( $all[ $type ] ) && is_array( $all[ $type ] ) ? $all[ $type ] : array();

		return array(
			'enabled'        => array_key_exists( 'enabled', $saved )
				? (bool) $saved['enabled']
				: in_array( $type, self::DEFAULT_ENABLED, true ),
			'instructions'   => isset( $saved['instructions'] ) ? (string) $saved['instructions'] : '',
			'allowed_blocks' => isset( $saved['allowed_blocks'] ) && is_array( $saved['allowed_blocks'] )
				? array_values( $saved['allowed_blocks'] )
				: array(),
		);
	}

	public static function is_eligible( string $type ): bool {
		return self::get( $type )['enabled'];
	}

	/** @return string[] Eligible post type slugs. */
	public static function eligible_types(): array {
		$eligible = array();
		foreach ( self::candidate_post_types() as $pt ) {
			if ( self::is_eligible( $pt->name ) ) {
				$eligible[] = $pt->name;
			}
		}
		return $eligible ? $eligible : array( 'post' );
	}

	public static function instructions( string $type ): string {
		return trim( self::get( $type )['instructions'] );
	}

	/**
	 * Allowed block (element) types for a post type, or null for "all".
	 * An empty saved list means no restriction.
	 *
	 * @return string[]|null
	 */
	public static function allowed_block_types( string $type ): ?array {
		$allowed = self::get( $type )['allowed_blocks'];
		if ( ! $allowed ) {
			return null;
		}
		// Only known catalog types; guard against stale slugs.
		$valid = array_values( array_intersect( $allowed, array_keys( self::block_catalog() ) ) );
		return $valid ? $valid : null;
	}

	/**
	 * Sanitize and persist an incoming profiles map (from the settings UI).
	 *
	 * @param array $incoming slug => {enabled, instructions, allowed_blocks}
	 */
	public static function save( array $incoming ): void {
		$candidates = array();
		foreach ( self::candidate_post_types() as $pt ) {
			$candidates[ $pt->name ] = true;
		}

		$catalog = array_keys( self::block_catalog() );
		$clean   = array();

		foreach ( $incoming as $slug => $profile ) {
			$slug = sanitize_key( (string) $slug );
			if ( ! isset( $candidates[ $slug ] ) || ! is_array( $profile ) ) {
				continue;
			}

			$blocks = array_map( 'sanitize_key', (array) ( $profile['allowed_blocks'] ?? array() ) );
			$blocks = array_values( array_intersect( $blocks, $catalog ) );

			$clean[ $slug ] = array(
				'enabled'        => ! empty( $profile['enabled'] ),
				'instructions'   => sanitize_textarea_field( (string) ( $profile['instructions'] ?? '' ) ),
				'allowed_blocks' => $blocks,
			);
		}

		update_option( self::OPTION, $clean );
	}
}
