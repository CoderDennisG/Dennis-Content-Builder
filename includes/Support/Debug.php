<?php
declare(strict_types=1);

namespace Denise\Support;

/**
 * Opt-in developer logging of the exact JSON flowing through the assistant:
 * the tool inputs the AI generates, the tool results, and the Elementor tree
 * the adapter builds. Off unless `DENISE_DEBUG` is defined truthy (e.g. in
 * wp-config.php) — it writes nothing in normal operation.
 *
 * Output: wp-content/uploads/denise-debug.log (pretty-printed, appended).
 */
final class Debug {

	/** Whether debug logging is switched on. */
	public static function enabled(): bool {
		return defined( 'DENISE_DEBUG' ) && DENISE_DEBUG;
	}

	/**
	 * Append a labeled, pretty-printed JSON entry to the debug log.
	 *
	 * @param string $label   Short human label for the entry.
	 * @param mixed  $payload Any JSON-encodable value.
	 */
	public static function log( string $label, $payload ): void {
		if ( ! self::enabled() ) {
			return;
		}

		$file = self::file();
		if ( '' === $file ) {
			return;
		}

		$json  = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$entry = sprintf( "[%s] %s\n%s\n\n", current_time( 'mysql' ), $label, false === $json ? '(unencodable)' : $json );

		// Best-effort; never let logging interfere with the request.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $file, $entry, FILE_APPEND | LOCK_EX );
	}

	/** Absolute path to the log file, or '' if uploads aren't writable. */
	private static function file(): string {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		return rtrim( (string) $uploads['basedir'], '/\\' ) . '/denise-debug.log';
	}
}
