<?php
declare(strict_types=1);

namespace Denise\Content;

use Denise\Support\Schema;

/**
 * Conversation + message persistence and the audit log.
 * All access is scoped to a user: a user can only ever touch their
 * own conversations.
 */
final class Conversations {

	/** Create a conversation for a user, titled from the first message. */
	public static function create( int $user_id, string $first_message, int $post_id = 0, string $kind = 'builder' ): int {
		global $wpdb;

		$title = wp_html_excerpt( $first_message, 60, '…' );
		$now   = current_time( 'mysql' );

		$wpdb->insert(
			Schema::conversations_table(),
			array(
				'user_id'    => $user_id,
				'post_id'    => $post_id,
				'kind'       => 'ask' === $kind ? 'ask' : 'builder',
				'title'      => $title,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
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
	 * @param int|null    $post_id Null: all conversations. Int: only ones
	 *                             scoped to that post (the editor sidebar).
	 * @param string|null $kind    Null: any kind. 'builder' or 'ask'.
	 */
	public static function recent( int $user_id, int $limit = 15, ?int $post_id = null, ?string $kind = null ): array {
		global $wpdb;

		$table = Schema::conversations_table();
		$where = 'user_id = %d';
		$args  = array( $user_id );

		if ( null !== $post_id ) {
			$where .= ' AND post_id = %d';
			$args[] = $post_id;
		}
		if ( null !== $kind ) {
			$where .= ' AND kind = %s';
			$args[] = 'ask' === $kind ? 'ask' : 'builder';
		}
		$args[] = $limit;

		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper, WHERE built from placeholders only.
				"SELECT id, title, updated_at FROM {$table} WHERE {$where} ORDER BY updated_at DESC LIMIT %d",
				...$args
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

	/** Mark a generated title on a conversation. */
	public static function set_title( int $conversation_id, string $title ): void {
		global $wpdb;

		$wpdb->update(
			Schema::conversations_table(),
			array(
				'title'  => wp_html_excerpt( $title, 200 ),
				'titled' => 1,
			),
			array( 'id' => $conversation_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	public static function is_titled( int $conversation_id ): bool {
		global $wpdb;

		$table = Schema::conversations_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT titled FROM {$table} WHERE id = %d", $conversation_id ) );
	}

	/**
	 * The user's most recent conversation of a kind, if it was active in
	 * the last $within_seconds. Used to resume a thread on page load.
	 */
	public static function latest( int $user_id, ?string $kind, int $within_seconds ): ?int {
		global $wpdb;

		$table = Schema::conversations_table();
		$since = gmdate( 'Y-m-d H:i:s', ( (int) current_time( 'timestamp' ) ) - $within_seconds );
		$where = 'user_id = %d AND post_id = 0 AND updated_at >= %s';
		$args  = array( $user_id, $since );
		if ( null !== $kind ) {
			$where .= ' AND kind = %s';
			$args[] = 'ask' === $kind ? 'ask' : 'builder';
		}
		$id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper, WHERE from placeholders only.
				"SELECT id FROM {$table} WHERE {$where} ORDER BY updated_at DESC LIMIT 1",
				...$args
			)
		);

		return $id ? (int) $id : null;
	}

	/** Delete one conversation the user owns, with its messages and audit rows. */
	public static function delete( int $conversation_id, int $user_id ): bool {
		global $wpdb;

		if ( ! self::get_owned( $conversation_id, $user_id ) ) {
			return false;
		}

		$wpdb->delete( Schema::messages_table(), array( 'conversation_id' => $conversation_id ), array( '%d' ) );
		$wpdb->delete( Schema::audit_table(), array( 'conversation_id' => $conversation_id ), array( '%d' ) );
		$wpdb->delete( Schema::conversations_table(), array( 'id' => $conversation_id ), array( '%d' ) );

		return true;
	}

	/**
	 * Delete conversations untouched for $days days, with their messages
	 * and audit rows. Returns how many conversations went.
	 */
	public static function delete_older_than( int $days ): int {
		global $wpdb;

		$conversations = Schema::conversations_table();
		$messages      = Schema::messages_table();
		$audit         = Schema::audit_table();
		$cutoff        = gmdate( 'Y-m-d H:i:s', ( (int) current_time( 'timestamp' ) ) - $days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$conversations} WHERE updated_at < %s", $cutoff ) ) );
		if ( ! $ids ) {
			return 0;
		}

		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$list = implode( ',', $chunk );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- ids are integers.
			$wpdb->query( "DELETE FROM {$messages} WHERE conversation_id IN ({$list})" );
			$wpdb->query( "DELETE FROM {$audit} WHERE conversation_id IN ({$list})" );
			$wpdb->query( "DELETE FROM {$conversations} WHERE id IN ({$list})" );
			// phpcs:enable
		}

		return count( $ids );
	}

	/**
	 * History in Claude API shape (for the orchestrators).
	 *
	 * @param int $max_messages 0 = everything. Otherwise only the newest
	 *                          rows are sent, trimmed back to start on a
	 *                          plain user message so no tool_use is ever
	 *                          separated from its tool_result. The full
	 *                          record stays in the table.
	 */
	public static function messages_for_api( int $conversation_id, int $max_messages = 0 ): array {
		$messages = self::all_messages_for_api( $conversation_id );

		if ( $max_messages <= 0 || count( $messages ) <= $max_messages ) {
			return $messages;
		}

		$messages = array_slice( $messages, -$max_messages );
		while ( $messages && ! ( 'user' === $messages[0]['role'] && is_string( $messages[0]['content'] ) ) ) {
			array_shift( $messages );
		}

		return $messages;
	}

	/** Every stored message in Claude API shape. */
	private static function all_messages_for_api( int $conversation_id ): array {
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

		foreach ( self::all_messages_for_api( $conversation_id ) as $msg ) {
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
