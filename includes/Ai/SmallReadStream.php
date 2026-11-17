<?php
/**
 * Body stream decorator for the streaming Anthropic client: reads are
 * capped at a small size so streamed tokens reach the browser as they
 * arrive instead of waiting for a full buffer. Used by StreamingTransport.
 *
 * @package Denise
 */

declare(strict_types=1);

namespace Denise\Ai;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Body decorator: never read more than READ_SIZE bytes at once.
 */
final class SmallReadStream implements StreamInterface {
	use StreamDecoratorTrait;

	private const READ_SIZE = 256;

	/** @var StreamInterface */
	private $stream;

	public function __construct( StreamInterface $stream ) {
		$this->stream = $stream;
	}

	public function read( $length ): string {
		return $this->stream->read( min( (int) $length, self::READ_SIZE ) );
	}
}
