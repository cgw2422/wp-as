<?php
/**
 * Bookable Spotlight interview times.
 *
 * candidate slots  = weekly hours (booking timezone) stepped by slot interval, each `duration` long
 *                    within [now + minimum notice, now + booking window]
 * minus            = Google Calendar free/busy blocks (all configured calendars) and existing
 *                    confirmed bookings, each padded by buffer-before / buffer-after
 * minus            = days that already reached the optional max interviews/day
 *
 * Fails closed: if Google is not connected or free/busy cannot be read, no times are offered.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach\Booking;

use ApostolicSpotlight\Outreach\Google\Calendar;
use ApostolicSpotlight\Outreach\Google\Google_Oauth;
use ApostolicSpotlight\Outreach\Installer;
use ApostolicSpotlight\Outreach\Settings;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

final class Availability {

	const CACHE_TTL = 180;

	/**
	 * @param bool $fresh Bypass the free/busy cache (used when confirming a booking).
	 * @return array<int,array{start:int,end:int}>|\WP_Error
	 */
	public static function slots( $fresh = false ) {
		if ( ! Google_Oauth::is_connected() ) {
			return new \WP_Error( 'aso_not_connected', __( 'Scheduling is temporarily unavailable.', 'apostolic-spotlight-outreach' ) );
		}
		$candidates = self::candidates();
		if ( ! $candidates ) {
			return array();
		}
		$busy = self::busy( $fresh );
		if ( is_wp_error( $busy ) ) {
			return $busy;
		}
		$bb = (int) Settings::get( 'booking.buffer_before', 0 ) * 60;
		$ba = (int) Settings::get( 'booking.buffer_after', 0 ) * 60;

		$max_per_day = (int) Settings::get( 'booking.max_per_day', 0 );
		$per_day     = $max_per_day > 0 ? self::bookings_per_day() : array();
		$tz          = Settings::booking_timezone();

		$out = array();
		foreach ( $candidates as $slot ) {
			if ( $max_per_day > 0 ) {
				$day = wp_date( 'Y-m-d', $slot['start'], $tz );
				if ( isset( $per_day[ $day ] ) && $per_day[ $day ] >= $max_per_day ) {
					continue;
				}
			}
			$s = $slot['start'] - $bb;
			$e = $slot['end'] + $ba;
			$ok = true;
			foreach ( $busy as $b ) {
				if ( $s < $b[1] && $e > $b[0] ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				$out[] = $slot;
			}
		}
		return $out;
	}

	/**
	 * Is this exact start time currently bookable?
	 */
	public static function is_available( $start_ts, $fresh = true ) {
		$slots = self::slots( $fresh );
		if ( is_wp_error( $slots ) ) {
			return $slots;
		}
		foreach ( $slots as $s ) {
			if ( (int) $s['start'] === (int) $start_ts ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Weekly-hours candidates.
	 *
	 * @return array<int,array{start:int,end:int}>
	 */
	public static function candidates( $now = null ) {
		$tz       = Settings::booking_timezone();
		$duration = max( 5, (int) Settings::get( 'booking.duration', 15 ) ) * 60;
		$interval = max( 5, (int) Settings::get( 'booking.slot_interval', 15 ) ) * 60;
		$notice   = max( 0, (float) Settings::get( 'booking.min_notice_hours', 24 ) ) * HOUR_IN_SECONDS;
		$window   = max( 1, (int) Settings::get( 'booking.window_days', 30 ) ) * DAY_IN_SECONDS;
		$hours    = (array) Settings::get( 'booking.hours', array() );

		$now      = null === $now ? time() : (int) $now;
		$earliest = $now + (int) $notice;
		$latest   = $now + $window;

		$date = ( new \DateTimeImmutable( '@' . $earliest ) )->setTimezone( $tz )->setTime( 0, 0 );
		$last = ( new \DateTimeImmutable( '@' . $latest ) )->setTimezone( $tz )->setTime( 0, 0 );
		$out  = array();
		while ( $date <= $last ) {
			$dow = (int) $date->format( 'N' );
			foreach ( (array) ( $hours[ $dow ] ?? array() ) as $range ) {
				$sm = Util::hm_to_minutes( $range[0] ?? '' );
				$em = Util::hm_to_minutes( $range[1] ?? '' );
				if ( null === $sm || null === $em || $em <= $sm ) {
					continue;
				}
				$range_start = $date->setTime( intdiv( $sm, 60 ), $sm % 60 )->getTimestamp();
				$range_end   = 1440 === $em ? $date->modify( '+1 day' )->getTimestamp() : $date->setTime( intdiv( $em, 60 ), $em % 60 )->getTimestamp();
				for ( $t = $range_start; $t + $duration <= $range_end; $t += $interval ) {
					if ( $t >= $earliest && $t <= $latest ) {
						$out[ $t ] = array( 'start' => $t, 'end' => $t + $duration );
					}
				}
			}
			$date = $date->modify( '+1 day' );
		}
		ksort( $out );
		return array_values( $out );
	}

	/**
	 * Busy intervals from Google (cached briefly) plus our own confirmed bookings.
	 *
	 * @return array<int,array{0:int,1:int}>|\WP_Error
	 */
	private static function busy( $fresh ) {
		$tz     = Settings::booking_timezone();
		$from   = ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0 );
		$to     = $from->modify( '+' . ( (int) Settings::get( 'booking.window_days', 30 ) + 2 ) . ' days' );
		$key    = 'aso_busy_' . md5( $from->format( 'Y-m-d' ) . '|' . implode( ',', Calendar::busy_calendar_ids() ) . '|' . $to->format( 'Y-m-d' ) );
		$google = $fresh ? false : get_transient( $key );
		if ( false === $google ) {
			$google = Calendar::busy( $from, $to );
			if ( is_wp_error( $google ) ) {
				return $google;
			}
			set_transient( $key, $google, self::CACHE_TTL );
		}

		global $wpdb;
		$b    = Installer::table( 'bookings' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT start_at, end_at FROM {$b} WHERE status = 'confirmed' AND end_at >= %s", Util::now_db() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			$google[] = array( Util::from_db( $r->start_at )->getTimestamp(), Util::from_db( $r->end_at )->getTimestamp() );
		}
		return $google;
	}

	public static function flush_cache() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_aso\\_busy\\_%' OR option_name LIKE '\\_transient\\_timeout\\_aso\\_busy\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	private static function bookings_per_day() {
		global $wpdb;
		$b    = Installer::table( 'bookings' );
		$tz   = Settings::booking_timezone();
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT start_at FROM {$b} WHERE status IN ('confirmed','completed') AND start_at >= %s", Util::to_db( Util::now()->modify( '-1 day' ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $start ) {
			$day         = wp_date( 'Y-m-d', Util::from_db( $start )->getTimestamp(), $tz );
			$out[ $day ] = ( $out[ $day ] ?? 0 ) + 1;
		}
		return $out;
	}
}
