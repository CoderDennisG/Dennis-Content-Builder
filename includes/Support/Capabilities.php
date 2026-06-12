<?php
declare(strict_types=1);

namespace DCB\Support;

/**
 * The dcb_use_chat capability gates the chat UI and the /chat REST
 * route. Content tools still re-check edit_post/edit_posts per call —
 * this capability only controls who sees the assistant at all.
 */
final class Capabilities {

	public const USE_CHAT = 'dcb_use_chat';

	private const DEFAULT_ROLES = array( 'administrator', 'editor' );

	/** Grant defaults on activation / upgrade. Idempotent. */
	public static function install(): void {
		foreach ( self::DEFAULT_ROLES as $role_key ) {
			$role = get_role( $role_key );
			if ( $role && ! $role->has_cap( self::USE_CHAT ) ) {
				$role->add_cap( self::USE_CHAT );
			}
		}
	}

	/**
	 * Sync the capability to an admin-chosen set of roles.
	 * Administrators always keep it.
	 *
	 * @param string[] $enabled_roles Role keys that should have the cap.
	 */
	public static function sync_roles( array $enabled_roles ): void {
		$enabled_roles[] = 'administrator';

		foreach ( get_editable_roles() as $key => $info ) {
			$role = get_role( $key );
			if ( ! $role ) {
				continue;
			}

			$should = in_array( $key, $enabled_roles, true );
			$has    = $role->has_cap( self::USE_CHAT );

			if ( $should && ! $has ) {
				$role->add_cap( self::USE_CHAT );
			} elseif ( ! $should && $has ) {
				$role->remove_cap( self::USE_CHAT );
			}
		}
	}

	/** Role keys that currently hold the capability. */
	public static function roles_with_cap(): array {
		$roles = array();
		foreach ( get_editable_roles() as $key => $info ) {
			$role = get_role( $key );
			if ( $role && $role->has_cap( self::USE_CHAT ) ) {
				$roles[] = $key;
			}
		}
		return $roles;
	}
}
