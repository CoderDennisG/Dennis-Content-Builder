<?php
declare(strict_types=1);

namespace DCB\Support;

/**
 * A dedicated, plugin-owned user that scheduled runs execute as. Gives
 * automated drafts a clear author and a real capability boundary, and
 * keeps audit trails attributable to "the automation", not a person.
 *
 * It is an internal service account: created with a random password and
 * not intended for interactive login.
 */
final class SystemUser {

	public const OPTION = 'dcb_system_user_id';
	private const LOGIN = 'dcb_content_builder';

	public static function id(): int {
		return (int) get_option( self::OPTION, 0 );
	}

	/**
	 * Return the system user id, creating the account if needed.
	 * Returns 0 only if creation failed.
	 */
	public static function ensure(): int {
		$id = self::id();
		if ( $id && get_user_by( 'id', $id ) ) {
			return $id;
		}

		$existing = get_user_by( 'login', self::LOGIN );
		if ( $existing ) {
			update_option( self::OPTION, $existing->ID, false );
			return $existing->ID;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = $host ? $host : 'example.com';

		$new_id = wp_insert_user(
			array(
				'user_login'   => self::LOGIN,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'user_email'   => 'content-builder@' . $host,
				'display_name' => 'Content Builder',
				'nickname'     => 'Content Builder',
				'role'         => 'editor',
			)
		);

		if ( is_wp_error( $new_id ) ) {
			return 0;
		}

		update_option( self::OPTION, $new_id, false );
		return (int) $new_id;
	}
}
