<?php
declare(strict_types=1);

namespace Denise\Content;

use Denise\Support\Debug;

/**
 * Daily clean-up: conversations (with their messages and audit rows) that
 * have not been touched for `denise_retention_days` days are deleted.
 * Default 90. Return 0 from the filter to keep everything.
 */
final class Retention {

	public const HOOK = 'denise_retention';

	private const DEFAULT_DAYS = 90;

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );

		// Self-heal: the plugin may have been replaced without re-activation.
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::install();
		}
	}

	public static function install(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function clear(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function days(): int {
		/**
		 * How long conversations are kept, in days. 0 disables deletion.
		 *
		 * @param int $days
		 */
		return max( 0, (int) apply_filters( 'denise_retention_days', self::DEFAULT_DAYS ) );
	}

	/** @return int conversations deleted */
	public static function run(): int {
		$days = self::days();
		if ( 0 === $days ) {
			return 0;
		}

		$deleted = Conversations::delete_older_than( $days );
		Debug::log(
			'retention',
			array(
				'days'    => $days,
				'deleted' => $deleted,
			)
		);

		return $deleted;
	}
}
