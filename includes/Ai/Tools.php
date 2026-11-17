<?php
declare(strict_types=1);

namespace Denise\Ai;

use Denise\Adapters\BuilderAdapter;
use Denise\Adapters\ElementorAbilities;
use Denise\Adapters\ElementorAdapter;
use Denise\Adapters\GutenbergAdapter;
use Denise\Content\Model;
use Denise\Content\Profiles;
use Denise\Support\Debug;
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

	/**
	 * Saved-template insertion is PARKED (2026-11-14): the library-flow relay
	 * produced nothing visible in the editor and could not be debugged from
	 * CLI. Off = the tool is not offered to the model and refuses if called;
	 * the implementation and bridge op stay for when it is picked up again.
	 */
	private const TEMPLATE_INSERT_ENABLED = false;

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
				return __( 'Looking through your content…', 'denise' );
			case 'read_content':
				return __( 'Reading the page…', 'denise' );
			case 'create_draft':
				return __( 'Writing your draft…', 'denise' );
			case 'update_content':
				return __( 'Applying the changes…', 'denise' );
			case 'search_media':
				return __( 'Searching the media library…', 'denise' );
			case 'read_fields':
				return __( 'Reading custom fields…', 'denise' );
			case 'update_fields':
				return __( 'Updating custom fields…', 'denise' );
			case 'list_elementor_widgets':
				return __( 'Browsing Elementor widgets…', 'denise' );
			case 'get_elementor_widget_schema':
				return __( 'Reading the widget options…', 'denise' );
			case 'read_elementor_page':
			case 'read_elementor_element':
				return __( 'Reading the Elementor page…', 'denise' );
			case 'update_elementor_element':
				return __( 'Updating the Elementor page…', 'denise' );
			case 'add_elementor_widget':
			case 'add_elementor_elements':
				return __( 'Adding to the Elementor page…', 'denise' );
			case 'move_elementor_element':
			case 'duplicate_elementor_element':
				return __( 'Rearranging the Elementor page…', 'denise' );
			case 'remove_elementor_element':
				return __( 'Removing from the Elementor page…', 'denise' );
			case 'find_elementor_template':
				return __( 'Looking through saved templates…', 'denise' );
			case 'create_elementor_draft_from_template':
				return __( 'Creating the draft from the template…', 'denise' );
			case 'apply_elementor_template':
				return __( 'Applying the template…', 'denise' );
			case 'insert_elementor_template':
				return __( 'Inserting the template…', 'denise' );
			default:
				return __( 'Working…', 'denise' );
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
						'enum' => array( 'heading', 'paragraph', 'list', 'quote', 'image', 'button', 'columns', 'group', 'separator', 'spacer', 'widget', 'raw' ),
					),
				),
				'required'             => array( 'type' ),
				'additionalProperties' => true,
			),
		);

		$tools = array(
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
							'enum' => \Denise\Content\Profiles::eligible_types(),
						),
						'title'     => array( 'type' => 'string' ),
						'elements'  => $elements_schema,
						'builder'   => array(
							'type'        => 'string',
							'enum'        => array( 'gutenberg', 'elementor' ),
							'description' => 'Optional. Only set this when the user EXPLICITLY asks for a specific page builder (e.g. "use Elementor", "build it in the block editor"). Omit it otherwise — each content type has a configured default builder.',
						),
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
			array(
				'name'        => 'read_fields',
				'description' => 'Read a post\'s custom fields (ACF and registered meta) the admin has allowed for this type. Returns each field\'s schema (name, type, label, choices, nested sub-fields for repeaters/groups/flexible content) and its current value. Call this before update_fields.',
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
				'name'        => 'update_fields',
				'description' => 'Set custom field values on a post. "fields" maps field name to value. Text, number, choice (use the exact choice value), true/false, date (YYYY-MM-DD), repeater (array of row objects keyed by sub-field name), group (one row object) and post references (post_object/relationship: post ids from a lookup, an array when the field takes several) can be written. Images, files, galleries, flexible content and taxonomy fields are read-only. Writing a repeater replaces ALL its rows: when the post already has rows the call is refused until you show the person the new rows and call again with confirm_replace true. Prior values are backed up automatically.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'         => array( 'type' => 'integer' ),
						'fields'          => array(
							'type'                 => 'object',
							'description'          => 'Map of field name to new value.',
							'additionalProperties' => true,
						),
						'confirm_replace' => array(
							'type'        => 'boolean',
							'description' => 'true only after the person agreed to replace the existing repeater rows.',
						),
					),
					'required'             => array( 'post_id', 'fields' ),
					'additionalProperties' => false,
				),
			),
		);

		// Elementor tools, backed by Elementor's own abilities (4.3+). Plain
		// settings everywhere: Elementor validates against each widget's schema.
		if ( ElementorAdapter::is_available() ) {
			$tools[] = array(
				'name'        => 'list_elementor_widgets',
				'description' => 'List the Elementor element types available on this site (e-heading, e-paragraph, e-button, e-image, e-flexbox, …) with a one-line description each.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				),
			);
			$tools[] = array(
				'name'        => 'get_elementor_widget_schema',
				'description' => 'The plain settings schema of one Elementor element type (e.g. e-heading: title, tag). Read it before setting anything other than text.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'widget_type' => array(
							'type'        => 'string',
							'description' => 'Element type, e.g. "e-heading".',
						),
					),
					'required'             => array( 'widget_type' ),
					'additionalProperties' => false,
				),
			);
			$tools[] = array(
				'name'        => 'read_elementor_page',
				'description' => 'Skeleton of an Elementor page: element ids, types and nesting. Always do this first to find the element you need; then read_elementor_element for its text, settings and CSS.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array( 'type' => 'integer' ),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
			);
			$tools[] = array(
				'name'        => 'read_elementor_element',
				'description' => 'One element (and its children) with content: plain settings (e.g. an e-heading\'s "title"), its CSS, tag and classes. The settings come back in exactly the shape update_elementor_element accepts.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'    => array( 'type' => 'integer' ),
						'element_id' => array( 'type' => 'string' ),
					),
					'required'             => array( 'post_id', 'element_id' ),
					'additionalProperties' => false,
				),
			);
			$tools[] = array(
				'name'        => 'update_elementor_element',
				'description' => 'Change an element in place by id. "settings" is a partial plain map merged onto the element (e-heading: {"title": "New text"}; e-paragraph: {"paragraph": "…"}; e-button: {"text": "…", "link": {"destination": "https://…"}}; set a key to null to remove it). "style" is an optional plain CSS string merged into the element\'s local style (supports &:hover and @media(--mobile) blocks). "classes" replaces the attached global classes. A text change passes text keys only: for list widgets send icon_list as [{"text": "…"}, …] and the rows keep their icons, ids and links; never send icons, images or styles unless asked.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'          => array( 'type' => 'integer' ),
						'element_id'       => array( 'type' => 'string' ),
						'settings'         => array( 'type' => 'object' ),
						'style'            => array( 'type' => 'string' ),
						'style_apply_mode' => array(
							'type' => 'string',
							'enum' => array( 'patch', 'replace' ),
						),
						'classes'          => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
					'required'             => array( 'post_id', 'element_id' ),
					'additionalProperties' => false,
				),
			);
			$tools[] = array(
				'name'        => 'add_elementor_widget',
				'description' => 'Add one element inside a container. parent_id is a container id from read_elementor_page (omit to add at the page root; root items must be containers such as e-flexbox). settings is the plain map for that type; style is optional CSS.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'     => array( 'type' => 'integer' ),
						'parent_id'   => array( 'type' => 'string' ),
						'widget_type' => array(
							'type'        => 'string',
							'description' => 'e.g. e-heading, e-paragraph, e-button, e-image, e-divider, e-flexbox',
						),
						'settings'    => array( 'type' => 'object' ),
						'style'       => array( 'type' => 'string' ),
					),
					'required'             => array( 'post_id', 'widget_type' ),
					'additionalProperties' => false,
				),
			);
			$tools[] = array(
				'name'        => 'add_elementor_elements',
				'description' => 'Build a whole section or several elements at once. xml_structure nests element tags, each with a unique configuration-id attribute, e.g. <e-flexbox configuration-id="hero"><e-heading configuration-id="h1"></e-heading><e-paragraph configuration-id="p1"></e-paragraph></e-flexbox>. element_config maps configuration-id to that element\'s plain settings; style maps configuration-id to a CSS string (containers default to display:flex; set flex-direction, gap, padding there). parent_id = container to insert into (omit for page root). mode "replace_children" clears the parent first.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'        => array( 'type' => 'integer' ),
						'parent_id'      => array( 'type' => 'string' ),
						'xml_structure'  => array( 'type' => 'string' ),
						'element_config' => array( 'type' => 'object' ),
						'style'          => array( 'type' => 'object' ),
						'mode'           => array(
							'type' => 'string',
							'enum' => array( 'append', 'replace_children' ),
						),
					),
					'required'             => array( 'post_id', 'xml_structure' ),
					'additionalProperties' => false,
				),
			);
			$tools[] = array(
				'name'        => 'move_elementor_element',
				'description' => 'Move an element into another container. new_parent_id is a container id, or "document" for the page root. index is the 0-based position among the container\'s children (omit to append).',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'       => array( 'type' => 'integer' ),
						'element_id'    => array( 'type' => 'string' ),
						'new_parent_id' => array( 'type' => 'string' ),
						'index'         => array( 'type' => 'integer' ),
					),
					'required'             => array( 'post_id', 'element_id', 'new_parent_id' ),
					'additionalProperties' => false,
				),
			);
			$tools[] = array(
				'name'        => 'duplicate_elementor_element',
				'description' => 'Duplicate an element (and its children) right after itself.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'    => array( 'type' => 'integer' ),
						'element_id' => array( 'type' => 'string' ),
					),
					'required'             => array( 'post_id', 'element_id' ),
					'additionalProperties' => false,
				),
			);
			$tools[] = array(
				'name'        => 'remove_elementor_element',
				'description' => 'Remove an element (and its children) from the page. Confirm with the person first when it is a whole section.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'    => array( 'type' => 'integer' ),
						'element_id' => array( 'type' => 'string' ),
					),
					'required'             => array( 'post_id', 'element_id' ),
					'additionalProperties' => false,
				),
			);
			$tools[] = array(
				'name'        => 'find_elementor_template',
				'description' => 'Find saved Elementor templates ("My Templates" in the editor\'s library) by name, to answer questions about them: id, title, type and whether it is page content (page/section/container/widget) or a theme part (header, footer, loop item…). Lookup only: you cannot insert templates; the person does that in the editor with Add Template.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'search' => array(
							'type'        => 'string',
							'description' => 'Words from the template title, e.g. "workshop baja". Empty lists them all.',
						),
					),
					'additionalProperties' => false,
				),
			);
			$tools[] = array(
				'name'        => 'create_elementor_draft_from_template',
				'description' => 'Create a new DRAFT of a post type from a saved Elementor page template (id from find_elementor_template): the template\'s sections are copied onto the new post exactly as Add Template → Insert would, server-side, no editor needed. Returns the draft id, its Elementor edit link and the root sections with their ids and editor names, ready for read_elementor_page / update_elementor_element text replacement and update_fields.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type'   => array(
							'type'        => 'string',
							'description' => 'e.g. "workshop" or "page".',
						),
						'title'       => array( 'type' => 'string' ),
						'template_id' => array( 'type' => 'integer' ),
					),
					'required'             => array( 'post_type', 'title', 'template_id' ),
					'additionalProperties' => false,
				),
			);
			$tools[] = array(
				'name'        => 'apply_elementor_template',
				'description' => 'Replace the layout of an existing DRAFT with a saved Elementor page template (drafts only; refused on published pages). If the draft already has content the call is refused until the person agrees and you pass confirm true.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'     => array( 'type' => 'integer' ),
						'template_id' => array( 'type' => 'integer' ),
						'confirm'     => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'post_id', 'template_id' ),
					'additionalProperties' => false,
				),
			);
			if ( self::TEMPLATE_INSERT_ENABLED ) {
				$tools[] = array(
					'name'        => 'insert_elementor_template',
					'description' => 'Insert a saved Elementor template into the page that is open in the Elementor editor, through Elementor\'s own library, exactly like Add Template → Insert. template_id comes from find_elementor_template. Templates go at the page root; index is the 0-based position among the root sections (omit to append at the end). Elementor may show its "how to apply styles" dialog to the person. Only works while the page is open in the editor.',
					'inputSchema' => array(
						'type'                 => 'object',
						'properties'           => array(
							'post_id'     => array( 'type' => 'integer' ),
							'template_id' => array( 'type' => 'integer' ),
							'index'       => array( 'type' => 'integer' ),
						),
						'required'             => array( 'post_id', 'template_id' ),
						'additionalProperties' => false,
					),
				);
			}
		}

		return $tools;
	}

	/**
	 * Execute a tool call. Returns a JSON-encodable result array;
	 * failures return ['error' => ...] so Claude can adapt.
	 */
	public function execute( string $name, array $input ): array {
		Debug::log( "tool input → {$name}", $input );

		$result = $this->dispatch( $name, $input );

		Debug::log( "tool result ← {$name}", $result );

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
				case 'read_fields':
					return $this->read_fields( $input );
				case 'update_fields':
					return $this->update_fields( $input );
				case 'list_elementor_widgets':
					return $this->list_elementor_widgets( $input );
				case 'get_elementor_widget_schema':
					return $this->get_elementor_widget_schema( $input );
				case 'read_elementor_page':
					return $this->read_elementor_page( $input );
				case 'read_elementor_element':
					return $this->read_elementor_element( $input );
				case 'update_elementor_element':
					return $this->update_elementor_element( $input );
				case 'add_elementor_widget':
					return $this->add_elementor_widget( $input );
				case 'add_elementor_elements':
					return $this->add_elementor_elements( $input );
				case 'move_elementor_element':
					return $this->move_elementor_element( $input );
				case 'duplicate_elementor_element':
					return $this->duplicate_elementor_element( $input );
				case 'remove_elementor_element':
					return $this->remove_elementor_element( $input );
				case 'find_elementor_template':
					return $this->find_elementor_template( $input );
				case 'create_elementor_draft_from_template':
					return $this->create_elementor_draft_from_template( $input );
				case 'apply_elementor_template':
					return $this->apply_elementor_template( $input );
				case 'insert_elementor_template':
					if ( ! self::TEMPLATE_INSERT_ENABLED ) {
						return array( 'error' => 'Template insertion is switched off. Tell the person to insert it themselves: Add Template (folder icon) → My Templates → Insert.' );
					}
					return $this->insert_elementor_template( $input );
				default:
					return array( 'error' => "Unknown tool: {$name}" );
			}
		} catch ( \Throwable $e ) {
			return array( 'error' => $e->getMessage() );
		}
	}

	private function list_content( array $input ): array {
		$eligible = \Denise\Content\Profiles::eligible_types();
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
			$editor  = \Denise\Content\Profiles::editor_of( $post );
			$items[] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'type'     => $post->post_type,
				'status'   => $post->post_status,
				'editor'   => $editor,
				'editable' => \Denise\Content\Profiles::is_supported_build( $post ),
			);
		}

		return array( 'items' => $items );
	}

	private function read_content( array $input ): array {
		$post = get_post( absint( $input['post_id'] ?? 0 ) );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return array( 'error' => 'Post not found or not editable by this user.' );
		}
		if ( ! \Denise\Content\Profiles::is_eligible( $post->post_type ) ) {
			return array( 'error' => 'This content type is not managed by the assistant.' );
		}
		$blocked = $this->guard_build( $post );
		if ( $blocked ) {
			return $blocked;
		}
		if ( $this->is_elementor_post( $post->ID ) ) {
			return array( 'error' => 'This is an Elementor page — read it with read_elementor_page (not read_content), and edit it in place with the Elementor editing tools.' );
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
		$type   = sanitize_key( (string) ( $input['post_type'] ?? 'page' ) );
		$pt_obj = get_post_type_object( $type );

		if ( ! $pt_obj || ! \Denise\Content\Profiles::is_eligible( $type ) ) {
			return array( 'error' => 'That content type is not available to the assistant.' );
		}
		if ( ! current_user_can( $pt_obj->cap->create_posts ) ) {
			return array( 'error' => 'Current user may not create this content type.' );
		}

		$elements = Model::sanitize_elements(
			$input['elements'] ?? array(),
			false,
			\Denise\Content\Profiles::allowed_blocks()
		);
		if ( ! $elements ) {
			return array( 'error' => 'No valid elements supplied.' );
		}

		$adapter = $this->resolve_adapter( $type, isset( $input['builder'] ) ? (string) $input['builder'] : null );

		// Insert an empty draft first, then let the adapter write content into
		// its own storage (post_content for Gutenberg, _elementor_data for
		// Elementor). Invariant: drafts only.
		$post_id = wp_insert_post(
			array(
				'post_type'    => $type,
				'post_status'  => 'draft',
				'post_title'   => sanitize_text_field( (string) ( $input['title'] ?? '' ) ),
				'post_content' => '',
				'meta_input'   => $adapter->insert_meta( $type ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return array( 'error' => $post_id->get_error_message() );
		}

		try {
			$adapter->persist( $post_id, $elements );
		} catch ( \Throwable $e ) {
			return array( 'error' => 'Draft created but content could not be written: ' . $e->getMessage() );
		}

		$this->actions[] = array(
			'action'  => 'created',
			'post_id' => $post_id,
			'title'   => get_the_title( $post_id ),
			'builder' => $adapter->label(),
			'edit'    => $this->edit_link_for( $post_id, $adapter ),
			'preview' => get_preview_post_link( $post_id ),
		);

		return array(
			'post_id'   => $post_id,
			'status'    => 'draft',
			'builder'   => $adapter->label(),
			'edit_link' => $this->edit_link_for( $post_id, $adapter ),
		);
	}

	// ------------------------------------------------------------------
	// Elementor (Elementor 4.3+ native abilities, in-process)
	// ------------------------------------------------------------------

	private function list_elementor_widgets( array $input ): array {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return array( 'error' => 'Not allowed.' );
		}
		$res = ElementorAbilities::run( 'list-widget-schemas', array( 'summary' => true ) );
		return isset( $res['error'] ) ? $res : array( 'widgets' => $res['data'] );
	}

	private function get_elementor_widget_schema( array $input ): array {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return array( 'error' => 'Not allowed.' );
		}
		$widget = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) ( $input['widget_type'] ?? '' ) ) );
		if ( '' === $widget ) {
			return array( 'error' => 'widget_type is required.' );
		}
		$res = ElementorAbilities::run( 'get-widget-schema', array( 'widget_type' => $widget ) );
		return isset( $res['error'] ) ? $res : array(
			'widget_type' => $widget,
			'schema'      => $res['data'],
		);
	}

	/**
	 * Shared guard for Elementor edit tools: the post must exist, be editable
	 * by the current user, be an assistant-managed type, and actually be an
	 * Elementor page. Returns an error array to short-circuit, or null to pass.
	 */
	private function guard_elementor_edit( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return array( 'error' => 'Post not found or not editable by this user.' );
		}
		if ( ! Profiles::is_eligible( $post->post_type ) ) {
			return array( 'error' => 'This content type is not managed by the assistant.' );
		}
		if ( ! $this->is_elementor_post( $post->ID ) ) {
			return array( 'error' => 'This page is not an Elementor page. Use read_content / update_content for block-editor content.' );
		}
		return null;
	}

	/** Record a UI action card for an Elementor edit (deduped per post per turn). */
	private function record_elementor_edit( int $post_id ): void {
		foreach ( $this->actions as $existing ) {
			if ( ( $existing['post_id'] ?? 0 ) === $post_id ) {
				return;
			}
		}
		$this->actions[] = array(
			'action'          => 'updated',
			'post_id'         => $post_id,
			'title'           => get_the_title( $post_id ),
			'builder'         => 'Elementor',
			'pending_publish' => $this->elementor_edit_is_pending( $post_id ),
			'edit'            => admin_url( 'post.php?post=' . $post_id . '&action=elementor' ),
			'preview'         => get_preview_post_link( $post_id ),
		);
	}

	/**
	 * Elementor keeps a published (or private) page live and writes Denise's
	 * edits into an autosave revision; they go live when someone publishes
	 * from the editor. Draft pages are written directly.
	 */
	private function elementor_edit_is_pending( int $post_id ): bool {
		return in_array( get_post_status( $post_id ), array( 'publish', 'private' ), true );
	}

	/**
	 * Where to read from: the pending autosave when Denise (or the editor)
	 * has unpublished changes newer than the live page, else the page itself.
	 * Keeps reads consistent with manage-elements, which edits that draft.
	 *
	 * @return array{id:int, pending:bool}
	 */
	private function elementor_read_target( int $post_id ): array {
		$post     = get_post( $post_id );
		$autosave = $post ? wp_get_post_autosave( $post_id, get_current_user_id() ) : false;
		if ( $post && $autosave && strtotime( $autosave->post_modified_gmt ) > strtotime( $post->post_modified_gmt ) ) {
			return array(
				'id'      => (int) $autosave->ID,
				'pending' => true,
			);
		}
		return array(
			'id'      => $post_id,
			'pending' => false,
		);
	}

	/** Note appended to edit results so the model explains the state correctly. */
	private function elementor_edit_note( int $post_id ): string {
		return $this->elementor_edit_is_pending( $post_id )
			? 'Saved as a pending draft of this published page: the live page is unchanged until someone opens it in the Elementor editor and clicks Publish/Update. Tell the person this.'
			: 'Saved to the draft page.';
	}

	private function read_elementor_page( array $input ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
		$guard   = $this->guard_elementor_edit( $post_id );
		if ( $guard ) {
			return $guard;
		}
		if ( $this->in_editor( $post_id ) ) {
			return array(
				'post_id'   => $post_id,
				'reading'   => 'live editor (the unsaved state the person sees right now)',
				'selected'  => $this->editor->selected(),
				'structure' => $this->editor->elements(),
			);
		}
		$target = $this->elementor_read_target( $post_id );
		$res    = ElementorAbilities::run( 'get-page-structure', array( 'post_id' => $target['id'] ) );
		if ( isset( $res['error'] ) ) {
			return $res;
		}
		return array(
			'post_id'   => $post_id,
			'reading'   => $target['pending'] ? 'pending draft (unpublished changes exist; this is what edits build on)' : 'live page',
			'structure' => $res['data'],
		);
	}

	private function read_elementor_element( array $input ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
		$guard   = $this->guard_elementor_edit( $post_id );
		if ( $guard ) {
			return $guard;
		}
		$element_id = sanitize_text_field( (string) ( $input['element_id'] ?? '' ) );
		if ( '' === $element_id ) {
			return array( 'error' => 'element_id is required.' );
		}
		if ( $this->in_editor( $post_id ) ) {
			$node = $this->editor_node( $element_id );
			if ( isset( $node['error'] ) ) {
				return $node;
			}
			return array(
				'post_id' => $post_id,
				'reading' => 'live editor',
				'element' => $node,
			);
		}
		$target = $this->elementor_read_target( $post_id );
		$res    = ElementorAbilities::run(
			'get-page-structure',
			array(
				'post_id'         => $target['id'],
				'element_id'      => $element_id,
				'include_content' => true,
			)
		);
		if ( isset( $res['error'] ) ) {
			return $res;
		}
		// Elementor's ability leaves legacy (V3) widgets without settings; fill their text in.
		$element = \Denise\Adapters\ElementorLegacy::enrich( $target['id'], (array) $res['data'] );
		return array(
			'post_id' => $post_id,
			'reading' => $target['pending'] ? 'pending draft' : 'live page',
			'element' => $element,
		);
	}

	/** One or more manage-elements operations, saved together. */
	private function manage_elements( int $post_id, array $operations ): array {
		$res = ElementorAbilities::run(
			'manage-elements',
			array(
				'post_id'    => $post_id,
				'operations' => $operations,
			)
		);
		if ( isset( $res['error'] ) ) {
			return $res;
		}
		$data    = (array) $res['data'];
		$results = (array) ( $data['results'] ?? array() );
		$failed  = array();
		foreach ( $results as $r ) {
			$r = (array) $r;
			if ( ! empty( $r['error'] ) || ( isset( $r['status'] ) && 'error' === $r['status'] ) ) {
				$failed[] = $r;
			}
		}
		if ( $failed && count( $failed ) === count( $operations ) ) {
			return array(
				'error'   => 'Elementor rejected the change: ' . wp_json_encode( $failed ),
				'results' => $results,
			);
		}
		$this->record_elementor_edit( $post_id );
		return array(
			'ok'      => true,
			'post_id' => $post_id,
			'results' => $results,
			'note'    => $this->elementor_edit_note( $post_id ),
		);
	}

	private function update_elementor_element( array $input ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
		$guard   = $this->guard_elementor_edit( $post_id );
		if ( $guard ) {
			return $guard;
		}
		$element_id = sanitize_text_field( (string) ( $input['element_id'] ?? '' ) );
		if ( '' === $element_id ) {
			return array( 'error' => 'element_id is required.' );
		}
		$op = array(
			'action'     => 'update',
			'element_id' => $element_id,
		);
		if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
			$op['settings'] = Model::sanitize_widget_settings( $input['settings'] );
		}
		if ( isset( $input['style'] ) && is_string( $input['style'] ) ) {
			$op['style'] = Model::sanitize_css( $input['style'] );
			if ( isset( $input['style_apply_mode'] ) && in_array( $input['style_apply_mode'], array( 'patch', 'replace' ), true ) ) {
				$op['style_apply_mode'] = $input['style_apply_mode'];
			}
		}
		if ( isset( $input['classes'] ) && is_array( $input['classes'] ) ) {
			$op['classes'] = array_values( array_map( 'sanitize_text_field', array_map( 'strval', $input['classes'] ) ) );
		}
		if ( ! isset( $op['settings'] ) && ! isset( $op['style'] ) && ! isset( $op['classes'] ) ) {
			return array( 'error' => 'Nothing to change: pass settings, style or classes.' );
		}
		if ( $this->in_editor( $post_id ) ) {
			if ( ! isset( $op['settings'] ) ) {
				return array( 'error' => 'Only settings (text, links, values) can be changed while the page is open in the editor; styles and classes are not relayed yet. Tell the person to ask again from the page itself for styling.' );
			}
			$node = $this->editor_node( $element_id );
			if ( isset( $node['error'] ) ) {
				return $node;
			}
			$resolved = $this->editor_settings( EditorContext::type_of( $node ), (array) ( $input['settings'] ?? array() ) );
			if ( isset( $resolved['error'] ) ) {
				return $resolved;
			}
			$warnings = $resolved['warnings'];
			if ( isset( $op['style'] ) || isset( $op['classes'] ) ) {
				$warnings[] = 'Style/classes were ignored: not available while the editor is open.';
			}
			return $this->editor_action(
				$post_id,
				'update',
				array(
					'element_id' => $element_id,
					'settings'   => $resolved['settings'],
				),
				$warnings
			);
		}
		$type = $this->stored_element_type( $post_id, $element_id );
		if ( '' !== $type && \Denise\Adapters\ElementorLegacy::is_legacy( $type ) ) {
			if ( ! isset( $op['settings'] ) ) {
				return array( 'error' => "Element {$element_id} is a legacy (V3) {$type} widget: only its text settings can be changed, not style or classes." );
			}
			$legacy = \Denise\Adapters\ElementorLegacy::update( $post_id, $element_id, $op['settings'] );
			if ( isset( $legacy['error'] ) ) {
				return $legacy;
			}
			$this->record_elementor_edit( $post_id );
			$result = array(
				'ok'      => true,
				'post_id' => $post_id,
				'changed' => $legacy['changed'],
				'note'    => 'Saved to the draft page (legacy widget, text only).',
			);
			if ( $legacy['ignored'] ) {
				$result['ignored'] = $legacy['ignored'];
			}
			if ( isset( $op['style'] ) || isset( $op['classes'] ) ) {
				$result['warnings'] = array( 'Style/classes were ignored: not available on legacy widgets.' );
			}
			return $result;
		}
		return $this->manage_elements( $post_id, array( $op ) );
	}

	private function add_elementor_widget( array $input ): array {
		$widget_type = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) ( $input['widget_type'] ?? '' ) ) );
		if ( '' === $widget_type ) {
			return array( 'error' => 'widget_type is required.' );
		}
		$editor_post = absint( $input['post_id'] ?? 0 );
		if ( $this->in_editor( $editor_post ) ) {
			$guard = $this->guard_elementor_edit( $editor_post );
			return $guard ? $guard : $this->editor_add_widget( $editor_post, $widget_type, $input );
		}
		$composition = array(
			'post_id'        => $input['post_id'] ?? 0,
			'xml_structure'  => "<{$widget_type} configuration-id=\"new-1\"></{$widget_type}>",
			'element_config' => array( 'new-1' => (array) ( $input['settings'] ?? array() ) ),
		);
		if ( isset( $input['parent_id'] ) && '' !== $input['parent_id'] ) {
			$composition['parent_id'] = $input['parent_id'];
		}
		if ( isset( $input['style'] ) && is_string( $input['style'] ) && '' !== trim( $input['style'] ) ) {
			$composition['style'] = array( 'new-1' => $input['style'] );
		}
		return $this->add_elementor_elements( $composition );
	}

	private function add_elementor_elements( array $input ): array {
		if ( $this->in_editor( absint( $input['post_id'] ?? 0 ) ) ) {
			return array( 'error' => 'Building whole sections is not available while the page is open in the editor. Add elements one at a time with add_elementor_widget, or tell the person to ask again from the page itself.' );
		}
		$post_id = absint( $input['post_id'] ?? 0 );
		$guard   = $this->guard_elementor_edit( $post_id );
		if ( $guard ) {
			return $guard;
		}
		$xml = trim( (string) ( $input['xml_structure'] ?? '' ) );
		if ( '' === $xml ) {
			return array( 'error' => 'xml_structure is required.' );
		}
		$args   = array(
			'post_id'       => $post_id,
			'xml_structure' => $xml,
		);
		$config = array();
		foreach ( (array) ( $input['element_config'] ?? array() ) as $key => $settings ) {
			$config[ sanitize_text_field( (string) $key ) ] = Model::sanitize_widget_settings( (array) $settings );
		}
		$args['element_config'] = $config ? $config : new \stdClass();
		$style                  = array();
		foreach ( (array) ( $input['style'] ?? array() ) as $key => $css ) {
			if ( is_string( $css ) ) {
				$style[ sanitize_text_field( (string) $key ) ] = Model::sanitize_css( $css );
			}
		}
		if ( $style ) {
			$args['style'] = $style;
		}
		if ( isset( $input['parent_id'] ) && '' !== (string) $input['parent_id'] ) {
			$args['parent_id'] = sanitize_text_field( (string) $input['parent_id'] );
		}
		if ( isset( $input['mode'] ) && in_array( $input['mode'], array( 'append', 'replace_children' ), true ) ) {
			$args['mode'] = $input['mode'];
		}
		$res = ElementorAbilities::run( 'build-composition', $args );
		if ( isset( $res['error'] ) ) {
			return $res;
		}
		$this->record_elementor_edit( $post_id );
		$data = (array) $res['data'];
		return array(
			'added'            => true,
			'post_id'          => $post_id,
			'root_element_ids' => $data['root_element_ids'] ?? array(),
			'warnings'         => $data['warnings'] ?? array(),
			'note'             => $this->elementor_edit_note( $post_id ),
		);
	}

	private function move_elementor_element( array $input ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
		$guard   = $this->guard_elementor_edit( $post_id );
		if ( $guard ) {
			return $guard;
		}
		$element_id = sanitize_text_field( (string) ( $input['element_id'] ?? '' ) );
		$parent_id  = sanitize_text_field( (string) ( $input['new_parent_id'] ?? '' ) );
		if ( '' === $element_id || '' === $parent_id ) {
			return array( 'error' => 'element_id and new_parent_id are required.' );
		}
		$op = array(
			'action'        => 'move',
			'element_id'    => $element_id,
			'new_parent_id' => $parent_id,
		);
		if ( isset( $input['index'] ) ) {
			$op['index'] = absint( $input['index'] );
		}
		if ( $this->in_editor( $post_id ) ) {
			foreach ( array( $element_id, $parent_id ) as $id ) {
				$node = $this->editor_node( $id );
				if ( isset( $node['error'] ) ) {
					return $node;
				}
			}
			$data = array(
				'element_id'    => $element_id,
				'new_parent_id' => $parent_id,
			);
			if ( isset( $op['index'] ) ) {
				$data['index'] = $op['index'];
			}
			return $this->editor_action( $post_id, 'move', $data );
		}
		return $this->manage_elements( $post_id, array( $op ) );
	}

	private function duplicate_elementor_element( array $input ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
		$guard   = $this->guard_elementor_edit( $post_id );
		if ( $guard ) {
			return $guard;
		}
		$element_id = sanitize_text_field( (string) ( $input['element_id'] ?? '' ) );
		if ( '' === $element_id ) {
			return array( 'error' => 'element_id is required.' );
		}
		if ( $this->in_editor( $post_id ) ) {
			$node = $this->editor_node( $element_id );
			if ( isset( $node['error'] ) ) {
				return $node;
			}
			return $this->editor_action( $post_id, 'duplicate', array( 'element_id' => $element_id ) );
		}
		return $this->manage_elements(
			$post_id,
			array(
				array(
					'action'     => 'duplicate',
					'element_id' => $element_id,
				),
			)
		);
	}

	private function remove_elementor_element( array $input ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
		$guard   = $this->guard_elementor_edit( $post_id );
		if ( $guard ) {
			return $guard;
		}
		$element_id = sanitize_text_field( (string) ( $input['element_id'] ?? '' ) );
		if ( '' === $element_id ) {
			return array( 'error' => 'element_id is required.' );
		}
		if ( $this->in_editor( $post_id ) ) {
			$node = $this->editor_node( $element_id );
			if ( isset( $node['error'] ) ) {
				return $node;
			}
			return $this->editor_action( $post_id, 'delete', array( 'element_id' => $element_id ) );
		}
		return $this->manage_elements(
			$post_id,
			array(
				array(
					'action'     => 'delete',
					'element_id' => $element_id,
				),
			)
		);
	}

	private function is_elementor_post( int $post_id ): bool {
		return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
	}

	/** widgetType/elType of an element in the post's stored Elementor data, or '' when not found. */
	private function stored_element_type( int $post_id, string $element_id ): string {
		$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
		if ( ! is_array( $data ) ) {
			return '';
		}
		$stack = $data;
		while ( $stack ) {
			$el = array_pop( $stack );
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( (string) ( $el['id'] ?? '' ) === $element_id ) {
				return (string) ( $el['widgetType'] ?? $el['elType'] ?? '' );
			}
			foreach ( (array) ( $el['elements'] ?? array() ) as $child ) {
				$stack[] = $child;
			}
		}
		return '';
	}

	// ---- Saved templates ---------------------------------------------------

	private function find_elementor_template( array $input ): array {
		$search = sanitize_text_field( (string) ( $input['search'] ?? '' ) );
		$items  = \Denise\Adapters\ElementorTemplates::find( $search );
		if ( ! $items ) {
			return array(
				'templates' => array(),
				'note'      => '' === $search
					? 'There are no saved Elementor templates on this site.'
					: "No saved template matches \"{$search}\". Try fewer words, or call again with an empty search to list them all.",
			);
		}
		return array(
			'templates' => $items,
			'note'      => 'Lookup only. To place one, the person uses Add Template (the folder icon in an empty area of the page) → My Templates → Insert.',
		);
	}

	/**
	 * New draft built from a saved page template: insert an empty draft of
	 * the type, then copy the template onto it (Elementor document save).
	 * Invariant: drafts only. A failed copy removes the empty draft again.
	 */
	private function create_elementor_draft_from_template( array $input ): array {
		$type   = sanitize_key( (string) ( $input['post_type'] ?? 'page' ) );
		$pt_obj = get_post_type_object( $type );
		if ( ! $pt_obj || ! Profiles::is_eligible( $type ) ) {
			return array( 'error' => 'That content type is not available to the assistant.' );
		}
		if ( ! current_user_can( $pt_obj->cap->create_posts ) ) {
			return array( 'error' => 'Current user may not create this content type.' );
		}
		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		if ( '' === $title ) {
			return array( 'error' => 'A title is required.' );
		}
		$template_id = absint( $input['template_id'] ?? 0 );
		$info        = \Denise\Adapters\ElementorTemplates::info( $template_id );
		if ( isset( $info['error'] ) ) {
			return $info;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => $type,
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_content' => '',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return array( 'error' => $post_id->get_error_message() );
		}

		$applied = \Denise\Adapters\ElementorTemplates::apply_to( $post_id, $template_id );
		if ( isset( $applied['error'] ) ) {
			wp_delete_post( $post_id, true );
			return array( 'error' => 'The template could not be applied, so no draft was kept: ' . $applied['error'] );
		}

		$edit = admin_url( 'post.php?post=' . $post_id . '&action=elementor' );

		$this->actions[] = array(
			'action'  => 'created',
			'post_id' => $post_id,
			'title'   => get_the_title( $post_id ),
			'builder' => 'Elementor',
			'edit'    => $edit,
			'preview' => get_preview_post_link( $post_id ),
		);

		return array(
			'post_id'   => $post_id,
			'status'    => 'draft',
			'builder'   => 'Elementor',
			'template'  => $info['title'],
			'edit_link' => $edit,
			'sections'  => $applied['sections'],
			'note'      => 'The draft now holds the template\'s own text. Replace it section by section with update_elementor_element; nothing is published.',
		);
	}

	private function apply_elementor_template( array $input ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'Post not found or not editable by this user.' );
		}
		if ( ! Profiles::is_eligible( $post->post_type ) ) {
			return array( 'error' => 'This content type is not managed by the assistant.' );
		}
		if ( 'draft' !== $post->post_status ) {
			return array( 'error' => 'A template can only be applied to a draft (this post is ' . $post->post_status . '); a live page keeps its layout.' );
		}
		if ( $this->in_editor( $post_id ) ) {
			return array( 'error' => 'The page is open in the Elementor editor; ask the person to close it (or insert the template there with Add Template) first.' );
		}
		$existing = (string) get_post_meta( $post_id, '_elementor_data', true );
		if ( '' !== $existing && '[]' !== $existing && empty( $input['confirm'] ) ) {
			return array( 'error' => 'This draft already has an Elementor layout; applying a template replaces it entirely. Ask the person, then call again with confirm: true.' );
		}
		$applied = \Denise\Adapters\ElementorTemplates::apply_to( $post_id, absint( $input['template_id'] ?? 0 ) );
		if ( isset( $applied['error'] ) ) {
			return $applied;
		}
		$this->record_elementor_edit( $post_id );
		return array(
			'post_id'  => $post_id,
			'template' => $applied['template']['title'],
			'sections' => $applied['sections'],
			'note'     => 'The draft now holds the template\'s own text; replace it section by section.',
		);
	}

	/**
	 * Insert a saved template into the page open in the editor. The browser
	 * runs Elementor's own library flow for it (fetch, styles dialog, import),
	 * so the result is exactly what clicking Insert in the library gives.
	 */
	private function insert_elementor_template( array $input ): array {
		$post_id     = absint( $input['post_id'] ?? 0 );
		$template_id = absint( $input['template_id'] ?? 0 );
		if ( ! $template_id ) {
			return array( 'error' => 'template_id is required; find it with find_elementor_template.' );
		}
		if ( ! $this->in_editor( $post_id ) ) {
			return array( 'error' => 'Templates can only be inserted while the page is open in the Elementor editor (the editor places and renders them). Tell the person to open the page in Elementor and ask again from there.' );
		}
		$guard = $this->guard_elementor_edit( $post_id );
		if ( $guard ) {
			return $guard;
		}
		if ( '' !== trim( (string) ( $input['parent_id'] ?? '' ) ) ) {
			return array( 'error' => 'Elementor inserts templates at the page root only (optionally at an index). Insert it there, then move the new sections with move_elementor_element if needed.' );
		}
		$template = \Denise\Adapters\ElementorTemplates::info( $template_id );
		if ( isset( $template['error'] ) ) {
			return $template;
		}
		$data = array(
			'template_id' => $template['id'],
			'title'       => $template['title'],
			'type'        => $template['type'],
		);
		if ( isset( $input['index'] ) && is_numeric( $input['index'] ) ) {
			$data['index'] = max( 0, (int) $input['index'] );
		}
		$result             = $this->editor_action( $post_id, 'import', $data );
		$result['inserted'] = $template['title'];
		$result['note']     = 'Handed to Elementor\'s template library in the open editor, exactly like Add Template → Insert: the library fetches the template, may ask the person how to apply styles (they choose and click Insert), then places it at the end of the page. Not saved until they click Update; undo with Ctrl+Z. If the person cancels the dialog, nothing is inserted and the next message reports it.';
		return $result;
	}

	// ---- Editor mode -------------------------------------------------------
	//
	// When the page is open in the Elementor editor, the browser sends the live
	// document with each message (EditorContext) and the write tools do not
	// touch the database: they record an "editor" action that the panel relays
	// to the editor's command bus, so the change happens exactly like a manual
	// edit (instant, undoable, saved on Update). Reads answer from the snapshot.

	/** Live editor state for the post being edited, or null when no editor is open. */
	private ?EditorContext $editor = null;

	public function set_editor( ?EditorContext $editor ): void {
		$this->editor = $editor;
	}

	private function in_editor( int $post_id ): bool {
		return null !== $this->editor && $this->editor->post_id() === $post_id;
	}

	/** The snapshot node for an element id, or an error array for the model. */
	private function editor_node( string $element_id ) {
		$node = $this->editor ? $this->editor->find( $element_id ) : null;
		if ( ! $node ) {
			return array( 'error' => "No element with id \"{$element_id}\" is on the page open in the editor. Use the ids from the live editor state." );
		}
		return $node;
	}

	/** Record a relayed edit and tell the model what happened. */
	private function editor_action( int $post_id, string $op, array $data, array $warnings = array() ): array {
		$this->actions[] = array_merge(
			array(
				'action'  => 'editor',
				'op'      => $op,
				'post_id' => $post_id,
			),
			$data
		);
		$result          = array(
			'ok'      => true,
			'post_id' => $post_id,
			'applied' => 'editor',
			'note'    => 'Applied directly in the open Elementor editor, like a manual edit: visible at once, undoable with Ctrl+Z, saved only when the person clicks Update. Say this once, not after every change.',
		);
		if ( $warnings ) {
			$result['warnings'] = $warnings;
		}
		return $result;
	}

	/**
	 * Plain settings → what the editor stores for this element type (typed
	 * props for V4 widgets, plain for V3). Error array when nothing usable.
	 */
	private function editor_settings( string $element_type, array $plain ) {
		$resolved = \Denise\Adapters\ElementorProps::resolve( $element_type, Model::sanitize_widget_settings( $plain ) );
		if ( ! $resolved['settings'] ) {
			return array( 'error' => 'None of those settings could be applied to ' . $element_type . ': ' . implode( ' ', array_merge( $resolved['errors'], $resolved['warnings'] ) ) );
		}
		return $resolved;
	}

	private function editor_add_widget( int $post_id, string $widget_type, array $input ): array {
		$parent_id = sanitize_text_field( (string) ( $input['parent_id'] ?? '' ) );
		if ( '' !== $parent_id ) {
			$parent = $this->editor_node( $parent_id );
			if ( isset( $parent['error'] ) ) {
				return $parent;
			}
		}
		if ( isset( $input['style'] ) && '' !== trim( (string) $input['style'] ) ) {
			return array( 'error' => 'Styles cannot be set while the page is open in the editor; add the element without style, or ask again from the page itself.' );
		}
		$resolved = $this->editor_settings( $widget_type, (array) ( $input['settings'] ?? array() ) );
		if ( isset( $resolved['error'] ) && ! empty( $input['settings'] ) ) {
			return $resolved;
		}
		$settings = isset( $resolved['settings'] ) ? $resolved['settings'] : array();
		return $this->editor_action(
			$post_id,
			'create',
			array(
				'parent_id' => $parent_id,
				'model'     => \Denise\Adapters\ElementorProps::model_for( $widget_type, $settings ),
			),
			isset( $resolved['warnings'] ) ? $resolved['warnings'] : array()
		);
	}

	/**
	 * The builder gate (mirrors the front-end launcher): Denise reads and
	 * edits only Elementor or block-editor builds. Anything else (Thrive
	 * Architect, classic editor) is refused with a message the model should
	 * pass on to the user rather than work around. Null = pass.
	 */
	private function guard_build( \WP_Post $post ): ?array {
		if ( Profiles::is_supported_build( $post ) ) {
			return null;
		}
		return array(
			'error' => sprintf(
				'This %s was built with %s, which Denise cannot edit. Only pages built with Elementor or the block editor can be changed here; tell the user so, and do not try another tool on it.',
				$post->post_type,
				Profiles::editor_label( Profiles::editor_of( $post ) )
			),
		);
	}

	/**
	 * Resolve the builder adapter for a new draft. An explicit per-request
	 * choice (the model passing `builder`, set only when the user asked for a
	 * specific builder) wins over the post type's configured default. Elementor
	 * is honored only when actually available; otherwise we fall back to
	 * Gutenberg rather than failing.
	 */
	private function resolve_adapter( string $type, ?string $requested ): BuilderAdapter {
		$builder = ( null !== $requested && in_array( $requested, Profiles::BUILDERS, true ) )
			? $requested
			: Profiles::builder_for( $type );

		if ( 'elementor' === $builder && ElementorAdapter::is_available() ) {
			return new ElementorAdapter();
		}
		return new GutenbergAdapter();
	}

	/**
	 * Edit link for the created post. Elementor pages open in the Elementor
	 * editor; everything else uses the normal block-editor link.
	 */
	private function edit_link_for( int $post_id, BuilderAdapter $adapter ): string {
		if ( $adapter instanceof ElementorAdapter ) {
			return admin_url( 'post.php?post=' . $post_id . '&action=elementor' );
		}
		return (string) get_edit_post_link( $post_id, 'raw' );
	}

	private function update_content( array $input ): array {
		$post = get_post( absint( $input['post_id'] ?? 0 ) );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return array( 'error' => 'Post not found or not editable by this user.' );
		}
		if ( ! \Denise\Content\Profiles::is_eligible( $post->post_type ) ) {
			return array( 'error' => 'This content type is not managed by the assistant.' );
		}
		$blocked = $this->guard_build( $post );
		if ( $blocked ) {
			return $blocked;
		}
		if ( $this->is_elementor_post( $post->ID ) ) {
			return array( 'error' => 'This is an Elementor page — do not use update_content on it (that would flatten its design). Edit it in place with the Elementor editing tools after reading it with read_elementor_page.' );
		}

		// Raw passthrough is allowed here: trees for updates legitimately
		// carry blocks that came from read_content. Block restriction still
		// applies to authored (non-raw) elements.
		$elements = Model::sanitize_elements(
			$input['elements'] ?? array(),
			true,
			\Denise\Content\Profiles::allowed_blocks()
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

	private function read_fields( array $input ): array {
		$post = get_post( absint( $input['post_id'] ?? 0 ) );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return array( 'error' => 'Post not found or not editable by this user.' );
		}

		$allowed = \Denise\Content\Profiles::allowed_fields( $post->post_type );
		if ( ! $allowed ) {
			return array(
				'fields' => array(),
				'note'   => 'No custom fields are enabled for this content type.',
			);
		}

		return \Denise\Content\Fields::read( $post->ID, $post->post_type, $allowed );
	}

	private function update_fields( array $input ): array {
		$post = get_post( absint( $input['post_id'] ?? 0 ) );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return array( 'error' => 'Post not found or not editable by this user.' );
		}

		$allowed = \Denise\Content\Profiles::allowed_fields( $post->post_type );
		$tree    = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
		if ( ! $allowed || ! $tree ) {
			return array( 'error' => 'No writable fields supplied or none enabled for this type.' );
		}

		// A repeater write replaces every row: existing rows need the person's ok first (RULES.md rule 11).
		if ( empty( $input['confirm_replace'] ) ) {
			$replacing = \Denise\Content\Fields::replacements( $post->ID, $post->post_type, array_intersect_key( $tree, array_flip( $allowed ) ) );
			if ( $replacing ) {
				$parts = array();
				foreach ( $replacing as $name => $count ) {
					$parts[] = sprintf( '%s (%d existing row%s)', $name, $count, 1 === $count ? '' : 's' );
				}
				return array(
					'error' => 'Not written: ' . implode( ', ', $parts ) . ' already ' . ( 1 === count( $parts ) ? 'has' : 'have' ) . ' rows and a write replaces them all. Show the person the rows you intend to write, and only after they agree call update_fields again with confirm_replace: true.',
				);
			}
		}

		return \Denise\Content\Fields::write( $post->ID, $post->post_type, $allowed, $tree );
	}
}
