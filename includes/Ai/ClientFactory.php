<?php
declare(strict_types=1);

namespace Denise\Ai;

use Anthropic\Client;
use GuzzleHttp\Client as GuzzleClient;

/**
 * Builds the Anthropic client the plugin talks through.
 *
 * The SDK discovers a PSR-18 HTTP client on its own, which lands on
 * Guzzle with default options — and Guzzle's default is to download the
 * whole response body before handing it back. For a streamed request
 * that means every token arrives at once at the end of the turn and the
 * chat UI shows nothing until then. Passing our own Guzzle client with
 * `stream => true` lets the SDK read the SSE body as it arrives; the
 * StreamingTransport wrapper then keeps each read small so events surface
 * as they come instead of in 8 KB bursts (see that class).
 */
final class ClientFactory {

	public static function make( string $api_key ): Client {
		return new Client(
			apiKey: $api_key,
			requestOptions: array(
				'transporter' => new StreamingTransport(
					new GuzzleClient(
						array(
							'stream'          => true,
							'timeout'         => 300,
							'connect_timeout' => 15,
							'http_errors'     => false,
						)
					)
				),
			),
		);
	}
}
