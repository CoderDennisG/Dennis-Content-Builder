<?php
declare(strict_types=1);

namespace DCB\Support;

/**
 * Custom tables + versioned migrations (docs/RULES.md: schema changes
 * only ever happen here, gated by dcb_db_version).
 */
final class Schema {

	private const DB_VERSION = 2;

	public static function conversations_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dcb_conversations';
	}

	public static function messages_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dcb_messages';
	}

	public static function audit_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dcb_audit_log';
	}

	/** Activation hook AND admin upgrade check both land here. */
	public static function install(): void {
		global $wpdb;

		if ( (int) get_option( 'dcb_db_version', 0 ) >= self::DB_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset       = $wpdb->get_charset_collate();
		$conversations = self::conversations_table();
		$messages      = self::messages_table();
		$audit         = self::audit_table();

		dbDelta(
			"CREATE TABLE {$conversations} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				title VARCHAR(200) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY post_id (post_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$messages} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				conversation_id BIGINT UNSIGNED NOT NULL,
				role VARCHAR(20) NOT NULL,
				content LONGTEXT NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY conversation_id (conversation_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$audit} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				conversation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				tool VARCHAR(64) NOT NULL,
				post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				detail TEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY post_id (post_id)
			) {$charset};"
		);

		update_option( 'dcb_db_version', self::DB_VERSION, false );
	}
}
