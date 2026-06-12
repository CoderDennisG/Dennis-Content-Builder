<?php
declare(strict_types=1);

namespace DCB\Api;

use DCB\Ai\Orchestrator;
use DCB\Content\Conversations;
use DCB\Support\Capabilities;
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
	}

	/**
	 * SSE endpoint: emits progress while the orchestrator runs, then
	 * exits. Never returns a WP_REST_Response.
	 */
	public function chat( WP_REST_Request $request ): void {
		$user_id         = get_current_user_id();
		$text            = trim( (string) $request->get_param( 'message' ) );
		$conversation_id = absint( $request->get_param( 'conversation_id' ) ?? 0 );

		$this->start_stream();

		if ( '' === $text ) {
			$this->send( 'error', array( 'message' => __( 'Empty message.', 'dennis-content-builder' ) ) );
			exit;
		}

		if ( $conversation_id && ! Conversations::get_owned( $conversation_id, $user_id ) ) {
			$this->send( 'error', array( 'message' => __( 'Conversation not found.', 'dennis-content-builder' ) ) );
			exit;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		if ( ! $conversation_id ) {
			$conversation_id = Conversations::create( $user_id, $text );
		}

		Conversations::append( $conversation_id, 'user', $text );
		$this->send( 'start', array( 'conversation_id' => $conversation_id ) );

		try {
			$orchestrator = new Orchestrator(
				function ( string $type, array $data ): void {
					$this->send( $type, $data );
				}
			);

			$result = $orchestrator->run( $conversation_id, $user_id );

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
		return new WP_REST_Response(
			array( 'conversations' => Conversations::recent( get_current_user_id() ) ),
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
