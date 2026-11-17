<?php
declare(strict_types=1);

namespace Denise\Skills\WorkshopIntake;

/**
 * Plain agenda text → the enclosing [mea_workshops action="workshop_agenda"]
 * block: "#N" per day, "- TIME: label" per entry, no weekday/date lines
 * (the shortcode computes each day's date from the workshop's start_date).
 *
 * Pure function: no side effects, nothing written.
 */
final class AgendaConverter {

	private const MAX_DAYS    = 31;
	private const MAX_ENTRIES = 60;

	/**
	 * @return array{shortcode:string, days:int, entries:int, warnings:string[]}|array{error:string}
	 */
	public static function convert( string $text, string $start_date = '' ): array {
		$text = str_replace( array( "\r\n", "\r" ), "\n", trim( $text ) );
		if ( '' === $text ) {
			return array( 'error' => 'No agenda text given.' );
		}

		$days      = array();
		$current   = -1;
		$warnings  = array();
		$first_day = '';
		$entries   = 0;

		foreach ( explode( "\n", $text ) as $raw ) {
			$line = trim( preg_replace( '/[\x{00A0}\t]+/u', ' ', $raw ) );
			$line = trim( $line, " +•\xE2\x80\xA2" );
			if ( '' === $line || preg_match( '/^\[\/?mea_workshops/', $line ) ) {
				continue;
			}

			// Day heading: "DAY 2: Friday, January 22", "Day 2", "#2", "Day Two".
			if ( preg_match( '/^(?:day\s*#?\s*(\d+|one|two|three|four|five|six|seven|eight|nine|ten)|#\s*(\d+))\b\s*[:.\-–—]?\s*(.*)$/iu', $line, $m ) ) {
				if ( count( $days ) >= self::MAX_DAYS ) {
					return array( 'error' => 'More than ' . self::MAX_DAYS . ' days; that is not a workshop agenda.' );
				}
				$days[]  = array();
				$current = count( $days ) - 1;
				if ( 0 === $current && '' !== trim( $m[3] ) ) {
					$first_day = trim( $m[3] );
				}
				continue;
			}

			// Entry: "4:00 PM – Arrival", "4:00 PM: Arrival", "- 4:00 PM - Arrival", "16:00 Arrival".
			if ( preg_match( '/^[-–—•*]?\s*(\d{1,2}(?::\d{2})?\s*(?:[ap]\.?m\.?)?|noon|midnight)\s*[:\-–—]?\s*(.+)$/iu', $line, $m ) && self::looks_like_time( $m[1] ) ) {
				if ( $current < 0 ) {
					$days[]     = array();
					$current    = 0;
					$warnings[] = 'The first entries had no "Day 1" heading; they were taken as day 1.';
				}
				if ( $entries >= self::MAX_ENTRIES * self::MAX_DAYS ) {
					break;
				}
				$label = trim( preg_replace( '/\s+/', ' ', $m[2] ), ' -–—:' );
				if ( '' === $label ) {
					continue;
				}
				$days[ $current ][] = self::normalize_time( $m[1] ) . ': ' . $label;
				++$entries;
				continue;
			}

			$warnings[] = 'Skipped a line that is neither a day heading nor a timed entry: "' . mb_substr( $line, 0, 80 ) . '"';
		}

		if ( ! $days || 0 === $entries ) {
			return array( 'error' => 'No day headings or timed entries found. Expected lines like "Day 1" followed by "4:00 PM – Arrival & Check-In".' );
		}

		foreach ( $days as $i => $list ) {
			if ( ! $list ) {
				$warnings[] = sprintf( 'Day %d has no entries.', $i + 1 );
			}
		}

		if ( '' !== $first_day && '' !== $start_date ) {
			$check = self::compare_first_day( $first_day, $start_date );
			if ( '' !== $check ) {
				$warnings[] = $check;
			}
		} elseif ( '' !== $first_day ) {
			$warnings[] = 'The agenda labels day 1 as "' . $first_day . '". The shortcode prints dates from the workshop start_date, so make sure start_date is that day.';
		}

		$out = "[mea_workshops action=\"workshop_agenda\"]\n";
		foreach ( $days as $i => $list ) {
			$out .= '#' . ( $i + 1 ) . "\n";
			foreach ( $list as $entry ) {
				$out .= '- ' . $entry . "\n";
			}
		}
		$out .= '[/mea_workshops]';

		return array(
			'shortcode' => $out,
			'days'      => count( $days ),
			'entries'   => $entries,
			'warnings'  => array_values( array_unique( $warnings ) ),
		);
	}

	private static function looks_like_time( string $s ): bool {
		$s = strtolower( trim( $s ) );
		return in_array( $s, array( 'noon', 'midnight' ), true ) || (bool) preg_match( '/^\d{1,2}(:\d{2})?\s*([ap]\.?m\.?)?$/', $s );
	}

	/** "7:30am" → "7:30 AM", "16:00" stays, "noon" → "12:00 PM". */
	private static function normalize_time( string $s ): string {
		$s = strtolower( trim( $s ) );
		if ( 'noon' === $s ) {
			return '12:00 PM';
		}
		if ( 'midnight' === $s ) {
			return '12:00 AM';
		}
		if ( preg_match( '/^(\d{1,2})(?::(\d{2}))?\s*([ap])\.?m\.?$/', $s, $m ) ) {
			return (int) $m[1] . ':' . ( isset( $m[2] ) && '' !== $m[2] ? $m[2] : '00' ) . ' ' . strtoupper( $m[3] ) . 'M';
		}
		if ( preg_match( '/^(\d{1,2})(?::(\d{2}))?$/', $s, $m ) ) {
			return (int) $m[1] . ':' . ( isset( $m[2] ) && '' !== $m[2] ? $m[2] : '00' );
		}
		return strtoupper( $s );
	}

	/** Non-empty warning when the day-1 label names a date other than start_date. */
	private static function compare_first_day( string $label, string $start_date ): string {
		$start = strtotime( $start_date );
		if ( ! $start ) {
			return 'start_date "' . $start_date . '" is not a date I can read; day 1 could not be checked.';
		}
		$ts = strtotime( preg_replace( '/^[a-z]+,?\s*/i', '', $label ) . ' ' . gmdate( 'Y', $start ) );
		if ( ! $ts ) {
			return '';
		}
		if ( gmdate( 'Y-m-d', $ts ) !== gmdate( 'Y-m-d', $start ) ) {
			return sprintf( 'The agenda labels day 1 as "%s" but the workshop start_date is %s. The accordion prints dates from start_date, so one of them is wrong.', $label, gmdate( 'F j, Y', $start ) );
		}
		return '';
	}
}
