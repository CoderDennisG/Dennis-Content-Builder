<?php
declare(strict_types=1);

namespace DCB\Ai;

use DCB\Content\Conversations;
use DCB\Content\Profiles;
use DCB\Support\SystemUser;
use DateTimeImmutable;

/**
 * Day-and-time scheduled auto-creation.
 *
 * Each scheduled post type gets a precise self-rearming single event:
 * we compute the next matching weekday+time in the site timezone,
 * schedule that exact moment, and re-arm the following occurrence when
 * it fires. Relies on a real cron hitting wp-cron.php (e.g. WP Engine)
 * for on-time delivery.
 */
final class Scheduler {

	public const HOOK          = 'dcb_run_schedule';
	private const RESYNC_HOOK  = 'dcb_schedule_resync';
	private const STATE_OPTION = 'dcb_schedule_state';

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ), 10, 1 );
		add_action( self::RESYNC_HOOK, array( self::class, 'sync_all' ) );

		// Daily self-heal in case an event was dropped.
		if ( ! wp_next_scheduled( self::RESYNC_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::RESYNC_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::RESYNC_HOOK );
	}

	/** Re-arm every scheduled type; clear events for the rest. */
	public static function sync_all(): void {
		foreach ( Profiles::candidate_post_types() as $pt ) {
			self::sync_type( $pt->name );
		}
		if ( Profiles::scheduled_types() ) {
			SystemUser::ensure();
		}
	}

	/** Schedule (or clear) the next occurrence for one post type. */
	public static function sync_type( string $slug ): void {
		wp_clear_scheduled_hook( self::HOOK, array( $slug ) );

		if ( ! in_array( $slug, Profiles::scheduled_types(), true ) ) {
			self::set_state( $slug, array( 'next_run' => 0 ) );
			return;
		}

		$next = self::next_run_timestamp( Profiles::schedule_for( $slug ) );
		if ( $next ) {
			wp_schedule_single_event( $next, self::HOOK, array( $slug ) );
			self::set_state( $slug, array( 'next_run' => $next ) );
		}
	}

	/**
	 * Next UTC timestamp matching any selected weekday at the set time,
	 * in the site timezone. Null if no days are selected.
	 */
	public static function next_run_timestamp( array $schedule ): ?int {
		if ( empty( $schedule['days'] ) ) {
			return null;
		}

		$parts  = explode( ':', (string) $schedule['time'] );
		$hour   = isset( $parts[0] ) ? (int) $parts[0] : 9;
		$minute = isset( $parts[1] ) ? (int) $parts[1] : 0;
		$tz     = wp_timezone();
		$now    = new DateTimeImmutable( 'now', $tz );

		for ( $i = 0; $i < 8; $i++ ) {
			$candidate = $now->modify( "+{$i} day" )->setTime( $hour, $minute, 0 );
			$weekday   = Profiles::WEEKDAYS[ (int) $candidate->format( 'N' ) - 1 ];

			if ( in_array( $weekday, $schedule['days'], true ) && $candidate->getTimestamp() > $now->getTimestamp() ) {
				return $candidate->getTimestamp();
			}
		}

		return null;
	}

	/** Cron callback: run the due type, then re-arm the next occurrence. */
	public static function run( string $slug ): void {
		if ( ! in_array( $slug, Profiles::scheduled_types(), true ) ) {
			return;
		}

		self::sync_type( $slug ); // Arm the next one up front.
		self::do_run( $slug );
	}

	/**
	 * Run immediately, ignoring the schedule (the "Run now" button).
	 *
	 * @return array{post_id?:int, edit?:string, published?:bool, error?:string}
	 */
	public static function run_now( string $slug ): array {
		if ( ! Profiles::is_eligible( $slug ) ) {
			return array( 'error' => __( 'This content type is not managed by the assistant.', 'dennis-content-builder' ) );
		}
		if ( '' === trim( Profiles::schedule_for( $slug )['brief'] ) ) {
			return array( 'error' => __( 'Add a brief and Save before running.', 'dennis-content-builder' ) );
		}

		return self::do_run( $slug );
	}

	/**
	 * Execute one generation as the system user. Publishes afterward only
	 * when the type opted into auto-publish.
	 */
	private static function do_run( string $slug ): array {
		$schedule = Profiles::schedule_for( $slug );
		$user_id  = SystemUser::ensure();
		if ( ! $user_id ) {
			return array( 'error' => 'System user unavailable.' );
		}

		$previous = get_current_user_id();
		wp_set_current_user( $user_id );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		$out = array();

		try {
			$result = ( new Orchestrator() )->run_scheduled( $slug, $schedule['brief'], $user_id );

			foreach ( $result['actions'] as $action ) {
				if ( 'created' !== $action['action'] || empty( $action['post_id'] ) ) {
					continue;
				}
				$post_id = (int) $action['post_id'];

				if ( $schedule['auto_publish'] ) {
					wp_update_post(
						array(
							'ID'          => $post_id,
							'post_status' => 'publish',
						)
					);
					Conversations::audit( $user_id, 0, 'auto_publish', $post_id, $slug );
				}

				$out = array(
					'post_id'   => $post_id,
					'edit'      => get_edit_post_link( $post_id, 'raw' ),
					'published' => (bool) $schedule['auto_publish'],
				);
			}

			if ( ! $out ) {
				$out = array( 'error' => 'The run finished without creating content.' );
			}
		} catch ( \Throwable $e ) {
			Conversations::audit( $user_id, 0, 'schedule_error', 0, $e->getMessage() );
			$out = array( 'error' => $e->getMessage() );
		} finally {
			wp_set_current_user( $previous );
			self::set_state( $slug, array( 'last_run' => time() ) );
		}

		return $out;
	}

	// ------------------------------------------------------------------
	// Run-state (last/next) for the UI
	// ------------------------------------------------------------------

	public static function state(): array {
		$s = get_option( self::STATE_OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	private static function set_state( string $slug, array $patch ): void {
		$state          = self::state();
		$current        = isset( $state[ $slug ] ) && is_array( $state[ $slug ] ) ? $state[ $slug ] : array();
		$state[ $slug ] = array_merge( $current, $patch );
		update_option( self::STATE_OPTION, $state, false );
	}
}
