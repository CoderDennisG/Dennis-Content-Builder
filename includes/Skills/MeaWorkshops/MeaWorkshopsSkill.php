<?php
declare(strict_types=1);

namespace Denise\Skills\MeaWorkshops;

use Denise\Skills\Skill;
use Denise\Support\Capabilities;
use WP_Query;

/**
 * Skill: the MEA Workshops plugin (workshop post type, shortcodes, ACF
 * fields, PDF/JSON feeds). Notes come from the plugin's own docs/card.md
 * and docs/shortcodes.md, read live from the installed plugin so they
 * never go stale and ship with the plugin to production. Tools resolve real names (a faculty
 * member, a workshop) to the IDs and SKUs the shortcodes need.
 *
 * Read-only by design.
 */
final class MeaWorkshopsSkill implements Skill {

	private const PLUGIN_CARD = 'mea-workshops/docs/card.md';
	private const PLUGIN_DOCS = 'mea-workshops/docs/shortcodes.md';

	private const FACULTY_META_KEYS = array( 'faculty', 'featured_faculty', 'host' );

	private ?string $knowledge = null;

	public function id(): string {
		return 'mea-workshops';
	}

	public function name(): string {
		return 'MEA Workshops';
	}

	public function description(): string {
		return 'The workshop post type and everything around it: the [mea_workshops action="…"] shortcodes, the [mea_workshop_list_markup] archive and its URL filters, ACF fields (dates, campus, pillar, pricing, early bird), faculty/hosts, testimonials, FAQs, agendas, live sessions, the schedule PDF and JSON feeds.';
	}

	public function triggers(): array {
		return array(
			'workshop',
			'shortcode',
			'mea_workshops',
			'faculty',
			'facilitator',
			'host',
			'sku',
			'campus',
			'pillar',
			'early bird',
			'early-bird',
			'enroll',
			'schedule',
			'pdf',
			'testimonial',
			'agenda',
			'live session',
			'faq',
			'podcast',
			'baja',
			'santa fe',
			'scholarship',
			'pricing',
			'spots',
		);
	}

	public function knowledge(): string {
		if ( null !== $this->knowledge ) {
			return $this->knowledge;
		}

		$card = $this->read( WP_PLUGIN_DIR . '/' . self::PLUGIN_CARD );
		$docs = $this->read( WP_PLUGIN_DIR . '/' . self::PLUGIN_DOCS );

		$text  = "# Skill: MEA Workshops\n\n";
		$text .= '' !== $card ? "## About the plugin (docs/card.md, read live)\n\n" . $card . "\n\n" : '_The plugin card ' . self::PLUGIN_CARD . " was not found on this install._\n\n";
		$text .= "## Shortcode reference (the plugin's docs/shortcodes.md, read live)\n\n";
		$text .= '' !== $docs ? $docs : '_The reference file ' . self::PLUGIN_DOCS . ' was not found on this install. Say so if a question needs it._';
		$text .= "\n\n" . $this->indexes();

		$this->knowledge = $text;
		return $this->knowledge;
	}

	public function tools(): array {
		return array(
			array(
				'name'        => 'find_faculty',
				'description' => 'Look up faculty members (post type "faculty") by name. Use it to turn a person\'s name into the exact faculty title, ID and slug before answering a shortcode or filter question that needs them.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'query' => array(
							'type'        => 'string',
							'description' => 'Part of the name, e.g. "Conley".',
						),
					),
					'required'             => array( 'query' ),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'find_workshop',
				'description' => 'Look up workshops by title or SKU. Returns ID, title, SKU, status, dates, campus and the faculty attached to each match.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'query' => array(
							'type'        => 'string',
							'description' => 'Part of the workshop title, or a SKU.',
						),
					),
					'required'             => array( 'query' ),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'workshops_for_faculty',
				'description' => 'List the workshops a faculty member is attached to (through the faculty, featured_faculty or host fields). Pass the faculty ID from find_faculty.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'faculty_id' => array(
							'type'        => 'integer',
							'description' => 'Faculty post ID.',
						),
					),
					'required'             => array( 'faculty_id' ),
					'additionalProperties' => false,
				),
			),
		);
	}

	public function label( string $tool ): string {
		switch ( $tool ) {
			case 'find_faculty':
				return __( 'Looking up faculty…', 'denise' );
			case 'find_workshop':
				return __( 'Looking up workshops…', 'denise' );
			case 'workshops_for_faculty':
				return __( 'Checking which workshops they lead…', 'denise' );
			default:
				return __( 'Looking that up…', 'denise' );
		}
	}

	public function execute( string $tool, array $input ): array {
		if ( ! current_user_can( Capabilities::USE_CHAT ) ) {
			return array( 'error' => 'Not allowed.' );
		}

		switch ( $tool ) {
			case 'find_faculty':
				return $this->find_faculty( (string) ( $input['query'] ?? '' ) );
			case 'find_workshop':
				return $this->find_workshop( (string) ( $input['query'] ?? '' ) );
			case 'workshops_for_faculty':
				return $this->workshops_for_faculty( (int) ( $input['faculty_id'] ?? 0 ) );
			default:
				return array( 'error' => 'Unknown tool.' );
		}
	}

	// ------------------------------------------------------------------

	private function find_faculty( string $query ): array {
		$query = trim( $query );
		if ( '' === $query || ! post_type_exists( 'faculty' ) ) {
			return array(
				'faculty' => array(),
				'note'    => '' === $query ? 'Empty query.' : 'The faculty post type is not registered on this site.',
			);
		}

		$q = new WP_Query(
			array(
				'post_type'      => 'faculty',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				's'              => $query,
				'posts_per_page' => 10,
				'no_found_rows'  => true,
			)
		);

		$out = array();
		foreach ( $q->posts as $post ) {
			$out[] = array(
				'id'     => $post->ID,
				'title'  => $post->post_title,
				'slug'   => $post->post_name,
				'status' => $post->post_status,
				'url'    => get_permalink( $post ),
			);
		}

		return array( 'faculty' => $out );
	}

	private function find_workshop( string $query ): array {
		$query = trim( $query );
		if ( '' === $query || ! post_type_exists( 'workshop' ) ) {
			return array(
				'workshops' => array(),
				'note'      => '' === $query ? 'Empty query.' : 'The workshop post type is not registered on this site.',
			);
		}

		$args = array(
			'post_type'      => 'workshop',
			'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
			'posts_per_page' => 10,
			'no_found_rows'  => true,
		);

		// A SKU looks like an identifier, not a phrase: try the meta first.
		if ( preg_match( '/^[A-Za-z0-9_-]{2,20}$/', $query ) ) {
			$by_sku = new WP_Query(
				$args + array(
					'meta_key'   => 'sku',
					'meta_value' => $query,
				)
			);
			if ( $by_sku->posts ) {
				return array( 'workshops' => array_map( array( $this, 'workshop_row' ), $by_sku->posts ) );
			}
		}

		$q = new WP_Query( $args + array( 's' => $query ) );

		return array( 'workshops' => array_map( array( $this, 'workshop_row' ), $q->posts ) );
	}

	private function workshops_for_faculty( int $faculty_id ): array {
		if ( $faculty_id <= 0 ) {
			return array(
				'workshops' => array(),
				'note'      => 'Missing faculty_id.',
			);
		}

		$faculty = get_post( $faculty_id );
		if ( ! $faculty || 'faculty' !== $faculty->post_type ) {
			return array(
				'workshops' => array(),
				'note'      => 'No faculty post with that ID.',
			);
		}

		// ACF relationship fields store a serialized array of ID strings;
		// a single post-object field stores the bare ID.
		$meta_query = array( 'relation' => 'OR' );
		foreach ( self::FACULTY_META_KEYS as $key ) {
			$meta_query[] = array(
				'key'     => $key,
				'value'   => '"' . $faculty_id . '"',
				'compare' => 'LIKE',
			);
			$meta_query[] = array(
				'key'     => $key,
				'value'   => (string) $faculty_id,
				'compare' => '=',
			);
		}

		$q = new WP_Query(
			array(
				'post_type'      => 'workshop',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
				'posts_per_page' => 50,
				'no_found_rows'  => true,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'orderby'        => 'meta_value',
				'meta_key'       => 'start_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'order'          => 'DESC',
			)
		);

		return array(
			'faculty'   => array(
				'id'    => $faculty->ID,
				'title' => $faculty->post_title,
			),
			'workshops' => array_map( array( $this, 'workshop_row' ), $q->posts ),
		);
	}

	private function workshop_row( \WP_Post $post ): array {
		$faculty = array();
		foreach ( self::FACULTY_META_KEYS as $key ) {
			$ids = get_post_meta( $post->ID, $key, true );
			$ids = is_array( $ids ) ? $ids : ( '' === $ids || null === $ids ? array() : array( $ids ) );
			foreach ( $ids as $id ) {
				$id = is_object( $id ) ? (int) ( $id->ID ?? 0 ) : (int) $id;
				if ( $id > 0 && ! isset( $faculty[ $id ] ) ) {
					$faculty[ $id ] = array(
						'id'    => $id,
						'title' => get_the_title( $id ),
						'via'   => $key,
					);
				} elseif ( $id > 0 ) {
					$faculty[ $id ]['via'] .= ', ' . $key;
				}
			}
		}

		$campus = get_post_meta( $post->ID, 'campus', true );

		return array(
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'status'     => $post->post_status,
			'sku'        => (string) get_post_meta( $post->ID, 'sku', true ),
			'start_date' => (string) get_post_meta( $post->ID, 'start_date', true ),
			'end_date'   => (string) get_post_meta( $post->ID, 'end_date', true ),
			'campus'     => is_array( $campus ) ? implode( ', ', array_map( 'strval', $campus ) ) : (string) $campus,
			'url'        => get_permalink( $post ),
			'faculty'    => array_values( $faculty ),
		);
	}

	/**
	 * Compact faculty + workshop indexes so most name questions resolve
	 * without a tool round trip. Cached 10 minutes: the notes must stay
	 * byte-identical between turns for the prompt cache to hit.
	 */
	private function indexes(): string {
		$cached = get_transient( 'denise_skill_mea_workshops_index' );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$text = "## Faculty index (id · name · slug)\nEvery faculty post on this site. Use the exact name for the `host` URL filter.\n";
		if ( post_type_exists( 'faculty' ) ) {
			$faculty = get_posts(
				array(
					'post_type'      => 'faculty',
					'post_status'    => 'publish',
					'posts_per_page' => 400,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);
			foreach ( $faculty as $post ) {
				$text .= sprintf( "- %d · %s · %s\n", $post->ID, $post->post_title, $post->post_name );
			}
		}

		$text .= "\n## Workshop index (id · sku · title · start→end · campus · status)\nPublished and scheduled workshops only; use find_workshop for drafts.\n";
		if ( post_type_exists( 'workshop' ) ) {
			$workshops = get_posts(
				array(
					'post_type'      => 'workshop',
					'post_status'    => array( 'publish', 'future' ),
					'posts_per_page' => 300,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);
			foreach ( $workshops as $post ) {
				$campus = get_post_meta( $post->ID, 'campus', true );
				$text  .= sprintf(
					"- %d · %s · %s · %s→%s · %s · %s\n",
					$post->ID,
					(string) get_post_meta( $post->ID, 'sku', true ),
					$post->post_title,
					(string) get_post_meta( $post->ID, 'start_date', true ),
					(string) get_post_meta( $post->ID, 'end_date', true ),
					is_array( $campus ) ? implode( '/', array_map( 'strval', $campus ) ) : (string) $campus,
					$post->post_status
				);
			}
		}

		set_transient( 'denise_skill_mea_workshops_index', $text, 10 * MINUTE_IN_SECONDS );
		return $text;
	}

	private function read( string $path ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$text = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_string( $text ) ? trim( $text ) : '';
	}
}
