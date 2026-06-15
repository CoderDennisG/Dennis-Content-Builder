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

	/** Global allowed-block list (applies to every post type). */
	public const BLOCKS_OPTION = 'dcb_allowed_blocks';

	/** Post types enabled by default before the admin configures anything. */
	private const DEFAULT_ENABLED = array( 'page', 'post' );

	/** Weekday keys used by the scheduler (matches DateTime 'N' order: Mon..Sun). */
	public const WEEKDAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

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
	 * A type's profile, merged with defaults. Block restrictions are
	 * global (see allowed_blocks()), not part of the per-type profile.
	 *
	 * @return array{enabled:bool, instructions:string, schedule:array, fields:array}
	 */
	public static function get( string $type ): array {
		$all   = self::all();
		$saved = isset( $all[ $type ] ) && is_array( $all[ $type ] ) ? $all[ $type ] : array();
		$sched = isset( $saved['schedule'] ) && is_array( $saved['schedule'] ) ? $saved['schedule'] : array();

		return array(
			'enabled'      => array_key_exists( 'enabled', $saved )
				? (bool) $saved['enabled']
				: in_array( $type, self::DEFAULT_ENABLED, true ),
			'instructions' => isset( $saved['instructions'] ) ? (string) $saved['instructions'] : '',
			'fields'       => isset( $saved['fields'] ) && is_array( $saved['fields'] )
				? array_values( array_map( 'strval', $saved['fields'] ) )
				: array(),
			'schedule'     => array(
				'enabled'      => ! empty( $sched['enabled'] ),
				'days'         => isset( $sched['days'] ) && is_array( $sched['days'] )
					? array_values( array_intersect( $sched['days'], self::WEEKDAYS ) )
					: array(),
				'time'         => isset( $sched['time'] ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $sched['time'] )
					? $sched['time']
					: '09:00',
				'auto_publish' => ! empty( $sched['auto_publish'] ),
				'brief'        => isset( $sched['brief'] ) ? (string) $sched['brief'] : '',
			),
		);
	}

	public static function schedule_for( string $type ): array {
		return self::get( $type )['schedule'];
	}

	/** Top-level field names the AI may read/write for this type. */
	public static function allowed_fields( string $type ): array {
		return self::get( $type )['fields'];
	}

	/**
	 * Eligible types with an active schedule (enabled + at least one day).
	 *
	 * @return string[]
	 */
	public static function scheduled_types(): array {
		$out = array();
		foreach ( self::candidate_post_types() as $pt ) {
			$p = self::get( $pt->name );
			if ( $p['enabled'] && $p['schedule']['enabled'] && $p['schedule']['days'] ) {
				$out[] = $pt->name;
			}
		}
		return $out;
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
	 * Global allowed block (element) types, or null for "all". An empty
	 * saved list means no restriction. Applies to every post type.
	 *
	 * @return string[]|null
	 */
	public static function allowed_blocks(): ?array {
		$saved = get_option( self::BLOCKS_OPTION, array() );
		if ( ! is_array( $saved ) || ! $saved ) {
			return null;
		}
		$valid = array_values( array_intersect( $saved, array_keys( self::block_catalog() ) ) );
		return $valid ? $valid : null;
	}

	/** Persist the global allowed-block list. Empty = all blocks allowed. */
	public static function save_allowed_blocks( array $blocks ): void {
		$blocks = array_map( 'sanitize_key', $blocks );
		$blocks = array_values( array_intersect( $blocks, array_keys( self::block_catalog() ) ) );
		update_option( self::BLOCKS_OPTION, $blocks );
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

		$clean = array();

		foreach ( $incoming as $slug => $profile ) {
			$slug = sanitize_key( (string) $slug );
			if ( ! isset( $candidates[ $slug ] ) || ! is_array( $profile ) ) {
				continue;
			}

			$sched = is_array( $profile['schedule'] ?? null ) ? $profile['schedule'] : array();
			$days  = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $sched['days'] ?? array() ) ), self::WEEKDAYS ) );
			$time  = ( isset( $sched['time'] ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $sched['time'] ) ) ? (string) $sched['time'] : '09:00';

			$fields = array_values( array_map( 'sanitize_text_field', (array) ( $profile['fields'] ?? array() ) ) );

			$clean[ $slug ] = array(
				'enabled'      => ! empty( $profile['enabled'] ),
				'instructions' => sanitize_textarea_field( (string) ( $profile['instructions'] ?? '' ) ),
				'fields'       => $fields,
				'schedule'     => array(
					'enabled'      => ! empty( $sched['enabled'] ),
					'days'         => $days,
					'time'         => $time,
					'auto_publish' => ! empty( $sched['auto_publish'] ),
					'brief'        => sanitize_textarea_field( (string) ( $sched['brief'] ?? '' ) ),
				),
			);
		}

		update_option( self::OPTION, $clean );
	}
}
