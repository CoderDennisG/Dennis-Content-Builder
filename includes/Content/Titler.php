<?php
declare(strict_types=1);

namespace Denise\Content;

use Denise\Ai\ClientFactory;
use Denise\Plugin;
use Denise\Support\Debug;

/**
 * Names a conversation from its first exchange with one tiny Haiku call,
 * so the history list reads "Featured faculty shortcode" instead of the
 * first sixty characters of the question. Runs after the answer has been
 * sent, so the person never waits for it. Fails silently: the excerpt
 * title set on creation stays if anything goes wrong.
 */
final class Titler {

	private const MODEL      = 'claude-haiku-5-5';
	private const MAX_CHARS  = 60;
	private const MAX_TOKENS = 40;

	public static function maybe_title( int $conversation_id, string $question, string $reply ): ?string {
		if ( Conversations::is_titled( $conversation_id ) ) {
			return null;
		}

		$settings = Plugin::settings();
		if ( '' === $settings['api_key'] || '' === trim( $question ) ) {
			return null;
		}

		try {
			$client   = ClientFactory::make( $settings['api_key'] );
			$response = $client->messages->create(
				model: self::MODEL,
				maxTokens: self::MAX_TOKENS,
				system: 'You name chat conversations. Reply with only a title of three to six words in plain language, no quotes, no trailing period, in the language of the question. Name the topic, not the fact that it is a question. If the exchange is only a greeting or small talk, reply exactly: Quick chat',
				messages: array(
					array(
						'role'    => 'user',
						'content' => "Question:\n" . mb_substr( $question, 0, 600 ) . "\n\nAnswer:\n" . mb_substr( $reply, 0, 900 ),
					),
				),
			);

			$title = '';
			foreach ( $response->content as $block ) {
				if ( 'text' === $block->type ) {
					$title = trim( $block->text );
					break;
				}
			}

			$title = trim( preg_replace( '/\s+/', ' ', (string) $title ), " \t\n\r\"'.“”" );
			if ( '' === $title ) {
				return null;
			}
			$title = mb_substr( $title, 0, self::MAX_CHARS );

			Conversations::set_title( $conversation_id, $title );
			return $title;
		} catch ( \Throwable $e ) {
			Debug::log( 'titler:error', $e->getMessage() );
			return null;
		}
	}
}
