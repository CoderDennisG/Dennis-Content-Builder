<?php
declare(strict_types=1);

namespace DCB\Ai;

use DCB\Adapters\GutenbergAdapter;
use DCB\Content\Model;
use WP_Query;

/**
 * The tool allowlist given to Claude, and their server-side execution.
 *
 * Security invariants (docs/RULES.md):
 *  - Tools are defined in code only — no dynamic registration.
 *  - Every tool re-checks current_user_can() for its specific target.
 *  - Content tools can only create drafts and write revisions. No
 *    publish, no delete, no users, no options.
 */
final class Tools {

	/** Tracks human-visible results (created/updated drafts) for the UI. */
	private array $actions = array();

	/** @var callable Audit logger: fn(string $tool, int $post_id, string $detail). */
	private $audit;

	public function __construct( ?callable $audit = null ) {
		$this->audit = $audit ?? static function ( string $tool, int $post_id, string $detail ): void {};
	}

	public function actions(): array {
		return $this->actions;
	}

	/** Human-readable progress labels for the chat UI. */
	public static function label( string $tool ): string {
		switch ( $tool ) {
			case 'list_content':
				return __( 'Looking through your content…', 'dennis-content-builder' );
			case 'read_content':
				return __( 'Reading the page…', 'dennis-content-builder' );
			case 'create_draft':
				return __( 'Writing your draft…', 'dennis-content-builder' );
			case 'update_content':
				return __( 'Applying the changes…', 'dennis-content-builder' );
			case 'search_media':
				return __( 'Searching the media library…', 'dennis-content-builder' );
			default:
				return __( 'Working…', 'dennis-content-builder' );
		}
	}

	/**
	 * Tool definitions in the SDK's camelCase array shape.
	 * Element shapes are described in the system prompt; deep validation
	 * happens server-side in Model::sanitize_elements().
	 */
	public static function definitions(): array {
		$elements_schema = array(
			'type'        => 'array',
			'description' => 'Array of content elements. Allowed types and shapes are documented in the system prompt.',
			'items'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'type' => array(
						'type' => 'string',
						'enum' => array( 'heading', 'paragraph', 'list', 'quote', 'image', 'button', 'columns', 'group', 'separator', 'spacer', 'raw' ),
					),
				),
				'required'             => array( 'type' ),
				'additionalProperties' => true,
			),
		);

		return array(
			array(
				'name'        => 'list_content',
				'description' => 'List pages or posts on the site (id, title, status, type). Use to find existing content before reading or editing it.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type' => array(
							'type'        => 'string',
							'enum'        => array( 'page', 'post', 'any' ),
							'description' => 'Filter by type. Default any.',
						),
						'search'    => array(
							'type'        => 'string',
							'description' => 'Optional title/content search.',
						),
					),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'read_content',
				'description' => 'Read a page or post as a neutral element tree. Always read before editing existing content. Elements of type "raw" are third-party/unknown blocks: you may move or delete them, but never modify their "block" string.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array( 'type' => 'integer' ),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'create_draft',
				'description' => 'Create a new page or post as a DRAFT from an element tree. Returns the post id and edit link. You cannot publish — a human reviews and publishes.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type' => array(
							'type' => 'string',
							'enum' => \DCB\Content\Profiles::eligible_types(),
						),
						'title'     => array( 'type' => 'string' ),
						'elements'  => $elements_schema,
					),
					'required'             => array( 'post_type', 'title', 'elements' ),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'update_content',
				'description' => 'Replace the full element tree (and optionally the title) of an existing page or post. A revision of the previous version is saved automatically, so changes are undoable. Status is never changed. Send the COMPLETE tree — including unchanged elements and any "raw" elements verbatim from read_content.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'  => array( 'type' => 'integer' ),
						'title'    => array( 'type' => 'string' ),
						'elements' => $elements_schema,
					),
					'required'             => array( 'post_id', 'elements' ),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'search_media',
				'description' => 'Search the media library for existing images. Use these (with their id) for image elements instead of inventing URLs.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'search' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
			),
		);
	}

	/**
	 * Execute a tool call. Returns a JSON-encodable result array;
	 * failures return ['error' => ...] so Claude can adapt.
	 */
	public function execute( string $name, array $input ): array {
		$result = $this->dispatch( $name, $input );

		$post_id = absint( $result['post_id'] ?? $input['post_id'] ?? 0 );
		$detail  = isset( $result['error'] )
			? 'ERROR: ' . $result['error']
			: wp_json_encode( array_intersect_key( $input, array_flip( array( 'post_type', 'title', 'search' ) ) ) );

		( $this->audit )( $name, $post_id, (string) $detail );

		return $result;
	}

	private function dispatch( string $name, array $input ): array {
		try {
			switch ( $name ) {
				case 'list_content':
					return $this->list_content( $input );
				case 'read_content':
					return $this->read_content( $input );
				case 'create_draft':
					return $this->create_draft( $input );
				case 'update_content':
					return $this->update_content( $input );
				case 'search_media':
					return $this->search_media( $input );
				default:
					return array( 'error' => "Unknown tool: {$name}" );
			}
		} catch ( \Throwable $e ) {
			return array( 'error' => $e->getMessage() );
		}
	}

	private function list_content( array $input ): array {
		$eligible = \DCB\Content\Profiles::eligible_types();
		$type     = $input['post_type'] ?? 'any';
		if ( ! in_array( $type, $eligible, true ) ) {
			$type = $eligible;
		}

		$query = new WP_Query(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				's'              => sanitize_text_field( (string) ( $input['search'] ?? '' ) ),
				'posts_per_page' => 20,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$items[] = array(
				'id'     => $post->ID,
				'title'  => $post->post_title,
				'type'   => $post->post_type,
				'status' => $post->post_status,
			);
		}

		return array( 'items' => $items );
	}

	private function read_content( array $input ): array {
		$post = get_post( absint( $input['post_id'] ?? 0 ) );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return array( 'error' => 'Post not found or not editable by this user.' );
		}
		if ( ! \DCB\Content\Profiles::is_eligible( $post->post_type ) ) {
			return array( 'error' => 'This content type is not managed by the assistant.' );
		}

		$adapter = new GutenbergAdapter();

		return array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'status'   => $post->post_status,
			'type'     => $post->post_type,
			'elements' => $adapter->parse( $post ),
		);
	}

	private function create_draft( array $input ): array {
		$type    = sanitize_key( (string) ( $input['post_type'] ?? 'page' ) );
		$pt_obj  = get_post_type_object( $type );

		if ( ! $pt_obj || ! \DCB\Content\Profiles::is_eligible( $type ) ) {
			return array( 'error' => 'That content type is not available to the assistant.' );
		}
		if ( ! current_user_can( $pt_obj->cap->create_posts ) ) {
			return array( 'error' => 'Current user may not create this content type.' );
		}

		$elements = Model::sanitize_elements(
			$input['elements'] ?? array(),
			false,
			\DCB\Content\Profiles::allowed_blocks()
		);
		if ( ! $elements ) {
			return array( 'error' => 'No valid elements supplied.' );
		}

		$adapter = new GutenbergAdapter();
		$post_id = wp_insert_post(
			array(
				'post_type'    => $type,
				'post_status'  => 'draft', // Invariant: drafts only.
				'post_title'   => sanitize_text_field( (string) ( $input['title'] ?? '' ) ),
				'post_content' => $adapter->serialize( $elements ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return array( 'error' => $post_id->get_error_message() );
		}

		$this->actions[] = array(
			'action'  => 'created',
			'post_id' => $post_id,
			'title'   => get_the_title( $post_id ),
			'edit'    => get_edit_post_link( $post_id, 'raw' ),
			'preview' => get_preview_post_link( $post_id ),
		);

		return array(
			'post_id'   => $post_id,
			'status'    => 'draft',
			'edit_link' => get_edit_post_link( $post_id, 'raw' ),
		);
	}

	private function update_content( array $input ): array {
		$post = get_post( absint( $input['post_id'] ?? 0 ) );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return array( 'error' => 'Post not found or not editable by this user.' );
		}
		if ( ! \DCB\Content\Profiles::is_eligible( $post->post_type ) ) {
			return array( 'error' => 'This content type is not managed by the assistant.' );
		}

		// Raw passthrough is allowed here: trees for updates legitimately
		// carry blocks that came from read_content. Block restriction still
		// applies to authored (non-raw) elements.
		$elements = Model::sanitize_elements(
			$input['elements'] ?? array(),
			true,
			\DCB\Content\Profiles::allowed_blocks()
		);
		if ( ! $elements ) {
			return array( 'error' => 'No valid elements supplied.' );
		}

		$adapter = new GutenbergAdapter();
		$args    = array(
			'ID'           => $post->ID,
			'post_content' => $adapter->serialize( $elements ),
			// Invariant: status untouched.
		);
		if ( isset( $input['title'] ) && '' !== trim( (string) $input['title'] ) ) {
			$args['post_title'] = sanitize_text_field( (string) $input['title'] );
		}

		$result = wp_update_post( $args, true ); // Saves a revision automatically.
		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_message() );
		}

		$this->actions[] = array(
			'action'  => 'updated',
			'post_id' => $post->ID,
			'title'   => get_the_title( $post->ID ),
			'edit'    => get_edit_post_link( $post->ID, 'raw' ),
			'preview' => get_preview_post_link( $post->ID ),
		);

		return array(
			'post_id'   => $post->ID,
			'updated'   => true,
			'edit_link' => get_edit_post_link( $post->ID, 'raw' ),
		);
	}

	private function search_media( array $input ): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				's'              => sanitize_text_field( (string) ( $input['search'] ?? '' ) ),
				'posts_per_page' => 10,
			)
		);

		$items = array();
		foreach ( $query->posts as $att ) {
			$src = wp_get_attachment_image_src( $att->ID, 'large' );
			if ( ! $src ) {
				continue;
			}
			$items[] = array(
				'id'    => $att->ID,
				'url'   => $src[0],
				'alt'   => (string) get_post_meta( $att->ID, '_wp_attachment_image_alt', true ),
				'title' => $att->post_title,
			);
		}

		return array( 'images' => $items );
	}
}
