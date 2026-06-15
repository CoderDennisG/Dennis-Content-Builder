<?php
declare(strict_types=1);

namespace DCB\Api;

use DCB\Ai\Orchestrator;
use DCB\Ai\Scheduler;
use DCB\Content\Conversations;
use DCB\Content\Fields;
use DCB\Content\Profiles;
use DCB\Plugin;
use DCB\Support\Capabilities;
use DCB\Support\SystemUser;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST namespace dcb/v1. Every route has a real permission callback —
 * never __return_true (docs/RULES.md).
 *
 * /chat answers as a Server-Sent Events stream: progress events while
 * Claude works, then a final "done" event. If a proxy buffers the
 * stream the client still gets every event on completion.
 */
final class Routes {

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$can_chat = static fn() => current_user_can( Capabilities::USE_CHAT );

		register_rest_route(
			'dcb/v1',
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'chat' ),
				'permission_callback' => $can_chat,
			)
		);

		register_rest_route(
			'dcb/v1',
			'/conversations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'conversations' ),
				'permission_callback' => $can_chat,
			)
		);

		register_rest_route(
			'dcb/v1',
			'/conversations/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'conversation' ),
				'permission_callback' => $can_chat,
			)
		);

		register_rest_route(
			'dcb/v1',
			'/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test' ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			)
		);

		register_rest_route(
			'dcb/v1',
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				),
			)
		);

		register_rest_route(
			'dcb/v1',
			'/run-schedule',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_schedule' ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			)
		);

		register_rest_route(
			'dcb/v1',
			'/fields',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'fields' ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			)
		);
	}

	public function fields( WP_REST_Request $request ): WP_REST_Response {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		return new WP_REST_Response(
			array( 'fields' => Fields::ui_list( $post_type ) ),
			200
		);
	}

	public function run_schedule( WP_REST_Request $request ) {
		$slug = sanitize_key( (string) $request->get_param( 'post_type' ) );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		$result = Scheduler::run_now( $slug );

		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'dcb_run_failed', $result['error'], array( 'status' => 400 ) );
		}

		return new WP_REST_Response( $result, 200 );
	}

	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->settings_payload(), 200 );
	}

	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$current = Plugin::settings();

		$model = sanitize_text_field( (string) $request->get_param( 'model' ) );
		if ( ! array_key_exists( $model, Plugin::models() ) ) {
			$model = $current['model'];
		}

		// Empty key keeps the saved one (write-only field).
		$api_key = trim( (string) $request->get_param( 'api_key' ) );
		if ( '' === $api_key ) {
			$api_key = $current['api_key'];
		} else {
			$api_key = sanitize_text_field( $api_key );
		}

		update_option(
			Plugin::OPTION,
			array(
				'api_key' => $api_key,
				'model'   => $model,
			)
		);

		$roles = array_map( 'sanitize_key', (array) $request->get_param( 'roles' ) );
		Capabilities::sync_roles( $roles );

		$profiles = $request->get_param( 'profiles' );
		if ( is_array( $profiles ) ) {
			Profiles::save( $profiles );
		}

		$allowed_blocks = $request->get_param( 'allowed_blocks' );
		if ( is_array( $allowed_blocks ) ) {
			Profiles::save_allowed_blocks( $allowed_blocks );
		}

		// Re-arm scheduled events to match the saved schedules.
		Scheduler::sync_all();

		return new WP_REST_Response( $this->settings_payload(), 200 );
	}

	/** Settings shape for the admin app. The API key is never returned. */
	private function settings_payload(): array {
		$settings = Plugin::settings();
		$has_key  = '' !== $settings['api_key'];

		$all_roles = array();
		foreach ( get_editable_roles() as $slug => $info ) {
			$all_roles[ $slug ] = translate_user_role( $info['name'] );
		}

		$post_types = array();
		$profiles   = array();
		foreach ( Profiles::candidate_post_types() as $pt ) {
			$post_types[] = array(
				'slug'        => $pt->name,
				'label'       => $pt->labels->name,
				'block_based' => post_type_supports( $pt->name, 'editor' ),
			);
			$profiles[ $pt->name ] = Profiles::get( $pt->name );
		}

		$system_user = get_user_by( 'id', SystemUser::id() );

		return array(
			'model'          => $settings['model'],
			'models'         => Plugin::models(),
			'has_key'        => $has_key,
			'key_hint'       => $has_key ? str_repeat( '•', 8 ) . substr( $settings['api_key'], -4 ) : '',
			'roles'          => Capabilities::roles_with_cap(),
			'all_roles'      => $all_roles,
			'admin_role'     => 'administrator',
			'post_types'     => $post_types,
			'profiles'       => $profiles,
			'block_catalog'  => Profiles::block_catalog(),
			'allowed_blocks' => Profiles::allowed_blocks() ?? array(),
			'weekdays'       => Profiles::WEEKDAYS,
			'timezone'       => wp_timezone_string(),
			'schedule_state' => Scheduler::state(),
			'system_user'    => $system_user ? $system_user->display_name : '',
		);
	}

	/**
	 * SSE endpoint: emits progress while the orchestrator runs, then
	 * exits. Never returns a WP_REST_Response.
	 */
	public function chat( WP_REST_Request $request ): void {
		$user_id         = get_current_user_id();
		$text            = trim( (string) $request->get_param( 'message' ) );
		$conversation_id = absint( $request->get_param( 'conversation_id' ) ?? 0 );
		$post_id         = absint( $request->get_param( 'post_id' ) ?? 0 );

		$this->start_stream();

		if ( '' === $text ) {
			$this->send( 'error', array( 'message' => __( 'Empty message.', 'dennis-content-builder' ) ) );
			exit;
		}

		if ( $conversation_id && ! Conversations::get_owned( $conversation_id, $user_id ) ) {
			$this->send( 'error', array( 'message' => __( 'Conversation not found.', 'dennis-content-builder' ) ) );
			exit;
		}

		// Post scope (editor sidebar): validate before trusting.
		$post_context = null;
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
				$this->send( 'error', array( 'message' => __( 'You cannot edit this content.', 'dennis-content-builder' ) ) );
				exit;
			}
			$post_context = array(
				'id'     => $post->ID,
				'type'   => $post->post_type,
				'title'  => $post->post_title,
				'status' => $post->post_status,
			);
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		if ( ! $conversation_id ) {
			$conversation_id = Conversations::create( $user_id, $text, $post_id );
		}

		Conversations::append( $conversation_id, 'user', $text );
		$this->send( 'start', array( 'conversation_id' => $conversation_id ) );

		try {
			$orchestrator = new Orchestrator(
				function ( string $type, array $data ): void {
					$this->send( $type, $data );
				}
			);

			$result = $orchestrator->run( $conversation_id, $user_id, $post_context );

			$this->send(
				'done',
				array(
					'conversation_id' => $conversation_id,
					'reply'           => $result['reply'],
				)
			);
		} catch ( \Throwable $e ) {
			$this->send( 'error', array( 'message' => $e->getMessage() ) );
		}

		exit;
	}

	public function conversations( WP_REST_Request $request ): WP_REST_Response {
		$post_id = $request->get_param( 'post_id' );

		return new WP_REST_Response(
			array(
				'conversations' => Conversations::recent(
					get_current_user_id(),
					15,
					null === $post_id ? null : absint( $post_id )
				),
			),
			200
		);
	}

	public function conversation( WP_REST_Request $request ) {
		$id = absint( $request['id'] );

		if ( ! Conversations::get_owned( $id, get_current_user_id() ) ) {
			return new WP_Error( 'dcb_not_found', __( 'Conversation not found.', 'dennis-content-builder' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'id'       => $id,
				'messages' => Conversations::messages_for_display( $id ),
				'actions'  => Conversations::actions_for_display( $id ),
			),
			200
		);
	}

	public function test( WP_REST_Request $request ) {
		try {
			$model = ( new Orchestrator() )->test_connection();
			return new WP_REST_Response(
				array(
					'ok'      => true,
					/* translators: %s: model id */
					'message' => sprintf( __( 'Connected — served by %s.', 'dennis-content-builder' ), $model ),
				),
				200
			);
		} catch ( \Throwable $e ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => $e->getMessage(),
				),
				200
			);
		}
	}

	// ------------------------------------------------------------------
	// SSE plumbing
	// ------------------------------------------------------------------

	private function start_stream(): void {
		// Defeat buffering wherever we can; harmless where we can't.
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );

		if ( function_exists( 'apache_setenv' ) ) {
			apache_setenv( 'no-gzip', '1' );
		}
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- required for SSE.
		@ini_set( 'zlib.output_compression', '0' );

		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
	}

	private function send( string $type, array $data ): void {
		echo 'data: ' . wp_json_encode( array_merge( array( 'type' => $type ), $data ) ) . "\n\n";

		if ( function_exists( 'flush' ) ) {
			flush();
		}
	}
}
