<?php
declare(strict_types=1);

namespace Denise\Ai;

use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 transport for the Anthropic SDK that actually streams.
 *
 * Two things stand between the API's SSE stream and the browser:
 *  1. Guzzle downloads the whole body before returning the response
 *     unless `stream => true` is set (done here).
 *  2. The SDK then reads the body in large fixed-size blocks, and PHP's
 *     HTTP stream blocks each read until the block is full or the body
 *     ends — so events still surface in ~8 KB bursts, i.e. at the end
 *     of a short turn. SmallReadStream caps every read at a few hundred
 *     bytes so each read returns as soon as the next few events arrive.
 */
final class StreamingTransport implements ClientInterface {

	private GuzzleClient $guzzle;

	public function __construct( GuzzleClient $guzzle ) {
		$this->guzzle = $guzzle;
	}

	public function sendRequest( RequestInterface $request ): ResponseInterface {
		$response = $this->guzzle->sendRequest( $request );

		$type = $response->getHeaderLine( 'Content-Type' );
		if ( false === stripos( $type, 'text/event-stream' ) ) {
			return $response;
		}

		return $response->withBody( new SmallReadStream( $response->getBody() ) );
	}
}
