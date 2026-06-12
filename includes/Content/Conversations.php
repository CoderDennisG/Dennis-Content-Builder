<?php
declare(strict_types=1);

namespace DCB\Content;

use DCB\Support\Schema;

/**
 * Conversation + message persistence and the audit log.
 * All access is scoped to a user: a user can only ever touch their
 * own conversations.
 */
final class Conversations {

	/** Create a conversation for a user, titled from the first message. */
	public static function create( int $user_id, string $first_message, int $post_id = 0 ): int {
		global $wpdb;

		$title = wp_html_excerpt( $first_message, 60, '…' );
		$now   = current_time( 'mysql' );

		$wpdb->insert(
			Schema::conversations_table(),
			array(
				'user_id'    => $user_id,
				'post_id'    => $post_id,
				'title'      => $title,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/** Conversation row if it exists AND belongs to the user. */
	public static function get_owned( int $conversation_id, int $user_id ): ?object {
		global $wpdb;

		$table = Schema::conversations_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				"SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
				$conversation_id,
				$user_id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Recent conversations for the picker.
	 *
	 * @param int|null $post_id Null: all conversations. Int: only ones
	 *                          scoped to that post (the editor sidebar).
	 */
	public static function recent( int $user_id, int $limit = 15, ?int $post_id = null ): array {
		global $wpdb;

		$table = Schema::conversations_table();

		if ( null === $post_id ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
					"SELECT id, title, updated_at FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC LIMIT %d",
					$user_id,
					$limit
				)
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				"SELECT id, title, updated_at FROM {$table} WHERE user_id = %d AND post_id = %d ORDER BY updated_at DESC LIMIT %d",
				$user_id,
				$post_id,
				$limit
			)
		);
	}

	/** Append one Claude-format message (role + string|array content). */
	public static function append( int $conversation_id, string $role, $content ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::messages_table(),
			array(
				'conversation_id' => $conversation_id,
				'role'            => $role,
				'content'         => wp_json_encode( $content ),
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		$wpdb->update(
			Schema::conversations_table(),
			array( 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $conversation_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/** Full history in Claude API shape (for the orchestrator). */
	public static function messages_for_api( int $conversation_id ): array {
		global $wpdb;

		$table = Schema::messages_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				"SELECT role, content FROM {$table} WHERE conversation_id = %d ORDER BY id ASC",
				$conversation_id
			)
		);

		$messages = array();
		foreach ( $rows as $row ) {
			$messages[] = array(
				'role'    => $row->role,
				'content' => json_decode( $row->content, true ),
			);
		}

		return $messages;
	}

	/**
	 * Display-friendly history for the UI: user texts and assistant
	 * text blocks only (tool plumbing is skipped).
	 */
	public static function messages_for_display( int $conversation_id ): array {
		$display = array();

		foreach ( self::messages_for_api( $conversation_id ) as $msg ) {
			if ( 'user' === $msg['role'] && is_string( $msg['content'] ) ) {
				$display[] = array(
					'role' => 'user',
					'text' => $msg['content'],
				);
				continue;
			}

			if ( 'assistant' === $msg['role'] && is_array( $msg['content'] ) ) {
				$text = '';
				foreach ( $msg['content'] as $block ) {
					if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
						$text .= ( '' !== $text ? "\n\n" : '' ) . $block['text'];
					}
				}
				if ( '' !== $text ) {
					$display[] = array(
						'role' => 'assistant',
						'text' => $text,
					);
				}
			}
		}

		return $display;
	}

	// ------------------------------------------------------------------
	// Audit log
	// ------------------------------------------------------------------

	public static function audit( int $user_id, int $conversation_id, string $tool, int $post_id, string $detail ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::audit_table(),
			array(
				'user_id'         => $user_id,
				'conversation_id' => $conversation_id,
				'tool'            => $tool,
				'post_id'         => $post_id,
				'detail'          => wp_html_excerpt( $detail, 500 ),
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/** Draft cards (created/updated) for resuming a conversation. */
	public static function actions_for_display( int $conversation_id ): array {
		global $wpdb;

		$table = Schema::audit_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				"SELECT tool, post_id FROM {$table} WHERE conversation_id = %d AND tool IN ('create_draft','update_content') AND post_id > 0 ORDER BY id ASC",
				$conversation_id
			)
		);

		$actions = array();
		foreach ( $rows as $row ) {
			$post = get_post( (int) $row->post_id );
			if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$actions[] = array(
				'action'  => 'create_draft' === $row->tool ? 'created' : 'updated',
				'post_id' => $post->ID,
				'title'   => $post->post_title,
				'edit'    => get_edit_post_link( $post->ID, 'raw' ),
				'preview' => get_preview_post_link( $post->ID ),
			);
		}

		return $actions;
	}
}
