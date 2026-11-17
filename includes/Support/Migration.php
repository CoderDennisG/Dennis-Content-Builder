<?php
declare(strict_types=1);

namespace Denise\Support;

/**
 * One-time carry-over from the plugin's former identity (Dennis/Denise
 * Content Builder, prefix `dcb`). Renames the custom tables, copies the
 * options, moves the chat capability on every role, renames the field
 * backup meta key and clears the old cron hooks. Idempotent; guarded by
 * the `denise_migrated_from_dcb` option.
 */
final class Migration {

	private const FLAG = 'denise_migrated_from_dcb';

	private const TABLES = array( 'conversations', 'messages', 'audit_log' );

	private const OPTIONS = array(
		'dcb_settings',
		'dcb_version',
		'dcb_db_version',
		'dcb_profiles',
		'dcb_allowed_blocks',
		'dcb_schedule_state',
		'dcb_system_user_id',
		'dcb_post_type_builder',
	);

	public static function maybe_migrate(): void {
		if ( get_option( self::FLAG ) ) {
			return;
		}

		global $wpdb;

		foreach ( self::TABLES as $suffix ) {
			$old = $wpdb->prefix . 'dcb_' . $suffix;
			$new = $wpdb->prefix . 'denise_' . $suffix;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) === $old;
			$new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) ) === $new;
			if ( $old_exists && ! $new_exists ) {
				$wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" );
			}
			// phpcs:enable
		}

		foreach ( self::OPTIONS as $old_name ) {
			$new_name = 'denise_' . substr( $old_name, 4 );
			if ( false !== get_option( $new_name ) ) {
				delete_option( $old_name );
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $old_name ) );
			if ( ! $row ) {
				continue;
			}
			add_option( $new_name, maybe_unserialize( $row->option_value ), '', in_array( $row->autoload, array( 'yes', 'on', 'auto-on', 'auto' ), true ) );
			delete_option( $old_name );
		}

		foreach ( array_keys( wp_roles()->roles ) as $role_key ) {
			$role = get_role( $role_key );
			if ( $role && $role->has_cap( 'dcb_use_chat' ) ) {
				$role->add_cap( Capabilities::USE_CHAT );
				$role->remove_cap( 'dcb_use_chat' );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->postmeta, array( 'meta_key' => '_denise_field_backup' ), array( 'meta_key' => '_dcb_field_backup' ) );

		wp_unschedule_hook( 'dcb_run_schedule' );
		wp_unschedule_hook( 'dcb_schedule_resync' );

		update_option( self::FLAG, 1, true );
	}
}
