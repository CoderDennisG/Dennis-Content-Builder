<?php
declare(strict_types=1);

namespace Denise\Ai;

use Anthropic\Client;
use Anthropic\Messages\InputJSONDelta;
use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\RawContentBlockStartEvent;
use Anthropic\Messages\RawMessageDeltaEvent;
use Anthropic\Messages\RawMessageStartEvent;
use Anthropic\Messages\TextDelta;
use Anthropic\Messages\ToolUseBlock;

/**
 * One streamed Anthropic request, shared by every loop in the plugin
 * (content builder, scheduler, Ask Denise). Forwards deltas to the
 * emitter and assembles the full assistant message as plain arrays.
 *
 * Emitted events: text {delta}, tool_start {name,label}, tool_progress
 * {name,chars}, usage {input, cache_read, cache_write, output, ttft_ms, total_ms}.
 */
final class Streamer {

	/** Models that accept output_config.effort (Opus 4.7+, Sonnet 5+, Haiku 5.5, Fable). */
	public static function supports_effort( string $model ): bool {
		return 1 === preg_match( '/^claude-(opus-(4-[78]|5)|sonnet-5|haiku-5|fable|mythos)/', $model );
	}

	/**
	 * @param string|array $system  System prompt: a string, or an array of
	 *                              text blocks (with optional cacheControl).
	 * @param callable     $emit    fn(string $type, array $data): void
	 * @param callable     $label   fn(string $tool): string — progress label.
	 * @param array        $extra   Extra named request params, e.g. ['outputConfig' => ['effort' => 'low']].
	 * @return array{content:array, tool_uses:array, stop_reason:?string, usage:array}
	 */
	public static function turn( Client $client, string $model, array $tools, array $messages, $system, int $max_tokens, callable $emit, callable $label, array $extra = array() ): array {
		$started = microtime( true );
		$stream  = $client->messages->createStream(
			...array_merge(
				array(
					'model'     => $model,
					'maxTokens' => $max_tokens,
					'system'    => $system,
					'tools'     => $tools,
					'messages'  => $messages,
				),
				$extra
			)
		);

		$blocks      = array(); // index => assembling block.
		$stop_reason = null;
		$last_ping   = 0.0;
		$usage       = array(
			'input'       => 0,
			'cache_read'  => 0,
			'cache_write' => 0,
			'output'      => 0,
			'ttft_ms'     => 0,
			'total_ms'    => 0,
		);

		foreach ( $stream as $event ) {
			if ( 0 === $usage['ttft_ms'] ) {
				$usage['ttft_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
			}

			if ( $event instanceof RawMessageStartEvent ) {
				$u = $event->message->usage ?? null;
				if ( $u ) {
					$usage['input']       = (int) ( $u->inputTokens ?? 0 );
					$usage['cache_read']  = (int) ( $u->cacheReadInputTokens ?? 0 );
					$usage['cache_write'] = (int) ( $u->cacheCreationInputTokens ?? 0 );
				}
			} elseif ( $event instanceof RawContentBlockStartEvent ) {
				$cb = $event->contentBlock;

				if ( $cb instanceof ToolUseBlock ) {
					$blocks[ $event->index ] = array(
						'kind' => 'tool_use',
						'id'   => $cb->id,
						'name' => $cb->name,
						'json' => '',
					);
					$emit(
						'tool_start',
						array(
							'name'  => $cb->name,
							'label' => $label( $cb->name ),
						)
					);
				} elseif ( 'text' === $cb->type ) {
					$blocks[ $event->index ] = array(
						'kind' => 'text',
						'text' => '',
					);
				} else {
					$blocks[ $event->index ] = array( 'kind' => 'other' );
				}
			} elseif ( $event instanceof RawContentBlockDeltaEvent ) {
				$delta = $event->delta;
				$idx   = $event->index;

				if ( $delta instanceof TextDelta && isset( $blocks[ $idx ] ) && 'text' === $blocks[ $idx ]['kind'] ) {
					$blocks[ $idx ]['text'] .= $delta->text;
					$emit( 'text', array( 'delta' => $delta->text ) );
				} elseif ( $delta instanceof InputJSONDelta && isset( $blocks[ $idx ] ) && 'tool_use' === $blocks[ $idx ]['kind'] ) {
					$blocks[ $idx ]['json'] .= $delta->partialJSON;

					// Throttle progress pings to ~3/second.
					$now = microtime( true );
					if ( $now - $last_ping > 0.33 ) {
						$last_ping = $now;
						$emit(
							'tool_progress',
							array(
								'name'  => $blocks[ $idx ]['name'],
								'chars' => strlen( $blocks[ $idx ]['json'] ),
							)
						);
					}
				}
			} elseif ( $event instanceof RawMessageDeltaEvent ) {
				$stop_reason     = $event->delta->stopReason ?? $stop_reason;
				$usage['output'] = (int) ( $event->usage->outputTokens ?? $usage['output'] );
			}
		}

		$usage['total_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		$emit( 'usage', $usage );

		$content   = array();
		$tool_uses = array();

		foreach ( $blocks as $block ) {
			if ( 'text' === $block['kind'] && '' !== $block['text'] ) {
				$content[] = array(
					'type' => 'text',
					'text' => $block['text'],
				);
			} elseif ( 'tool_use' === $block['kind'] ) {
				$input    = json_decode( '' !== $block['json'] ? $block['json'] : '{}', true );
				$tool_use = array(
					'type'  => 'tool_use',
					'id'    => $block['id'],
					'name'  => $block['name'],
					'input' => is_array( $input ) ? $input : array(),
				);

				$content[]   = $tool_use;
				$tool_uses[] = $tool_use;
			}
		}

		return array(
			'content'     => $content,
			'tool_uses'   => $tool_uses,
			'stop_reason' => is_object( $stop_reason ) ? ( $stop_reason->value ?? null ) : $stop_reason,
			'usage'       => $usage,
		);
	}
}
