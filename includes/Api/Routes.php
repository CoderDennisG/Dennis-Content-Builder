<?php
declare(strict_types=1);

namespace DCB\Api;

use DCB\Ai\Orchestrator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST namespace dcb/v1. Every route has a real permission callback —
 * never __return_true (docs/RULES.md).
 */
final class Routes {

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			'dcb/v1',
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'chat' ),
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
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

	public function chat( WP_REST_Request $request ) {
		$messages = $request->get_param( 'messages' );
		if ( ! is_array( $messages ) || ! $messages ) {
			return new WP_Error( 'dcb_bad_request', __( 'messages array is required.', 'dennis-content-builder' ), array( 'status' => 400 ) );
		}

		// Claude (Opus) can take a while on a full page build.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		try {
			$result = ( new Orchestrator() )->run( $messages );
			return new WP_REST_Response( $result, 200 );
		} catch ( \Anthropic\Core\Exceptions\APIStatusException $e ) {
			return new WP_Error(
				'dcb_api_error',
				sprintf(
					/* translators: %s: message */
					__( 'Claude API error: %s', 'dennis-content-builder' ),
					$e->getMessage()
				),
				array( 'status' => 502 )
			);
		} catch ( \Throwable $e ) {
			return new WP_Error( 'dcb_error', $e->getMessage(), array( 'status' => 500 ) );
		}
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
}
