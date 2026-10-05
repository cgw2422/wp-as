<?php
/**
 * Date/time and misc helpers. All persisted datetimes are UTC "Y-m-d H:i:s".
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

defined( 'ABSPATH' ) || exit;

final class Util {

	const DB_FORMAT = 'Y-m-d H:i:s';

	public static function utc() {
		return new \DateTimeZone( 'UTC' );
	}

	public static function timezone( $name ) {
		try {
			return new \DateTimeZone( $name ? $name : 'UTC' );
		} catch ( \Exception $e ) {
			return wp_timezone();
		}
	}

	/**
	 * Current time in UTC as DateTimeImmutable.
	 */
	public static function now() {
		return new \DateTimeImmutable( 'now', self::utc() );
	}

	public static function now_db() {
		return self::now()->format( self::DB_FORMAT );
	}

	public static function to_db( \DateTimeInterface $dt ) {
		return ( new \DateTimeImmutable( '@' . $dt->getTimestamp() ) )->setTimezone( self::utc() )->format( self::DB_FORMAT );
	}

	/**
	 * Parse a UTC DB datetime.
	 *
	 * @return \DateTimeImmutable|null
	 */
	public static function from_db( $value ) {
		if ( empty( $value ) || '0000-00-00 00:00:00' === $value ) {
			return null;
		}
		try {
			return new \DateTimeImmutable( $value, self::utc() );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Format a UTC DB datetime for display in a timezone (site tz by default).
	 */
	public static function format( $db_value, $format = null, $tz = null ) {
		$dt = self::from_db( $db_value );
		if ( ! $dt ) {
			return '';
		}
		$tz     = $tz ? $tz : wp_timezone();
		$format = $format ? $format : get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return wp_date( $format, $dt->getTimestamp(), $tz );
	}

	/**
	 * Short relative/human date for tables: "Today 3:15 pm", "Oct 6".
	 */
	public static function short( $db_value ) {
		$dt = self::from_db( $db_value );
		if ( ! $dt ) {
			return '';
		}
		$tz    = wp_timezone();
		$local = $dt->setTimezone( $tz );
		$today = new \DateTimeImmutable( 'now', $tz );
		if ( $local->format( 'Y-m-d' ) === $today->format( 'Y-m-d' ) ) {
			/* translators: %s: time */
			return sprintf( __( 'Today %s', 'apostolic-spotlight-outreach' ), wp_date( get_option( 'time_format' ), $dt->getTimestamp(), $tz ) );
		}
		$fmt = $local->format( 'Y' ) === $today->format( 'Y' ) ? 'M j' : 'M j, Y';
		return wp_date( $fmt, $dt->getTimestamp(), $tz );
	}

	/**
	 * Start of "today" / week / month in the site timezone, returned as UTC DB string.
	 */
	public static function period_start( $period ) {
		$tz  = wp_timezone();
		$now = new \DateTimeImmutable( 'now', $tz );
		switch ( $period ) {
			case 'week':
				$start_of_week = (int) get_option( 'start_of_week', 1 );
				$dow           = (int) $now->format( 'w' );
				$diff          = ( $dow - $start_of_week + 7 ) % 7;
				$start         = $now->setTime( 0, 0 )->modify( "-{$diff} days" );
				break;
			case 'month':
				$start = $now->modify( 'first day of this month' )->setTime( 0, 0 );
				break;
			default:
				$start = $now->setTime( 0, 0 );
		}
		return self::to_db( $start );
	}

	public static function end_of_today_db() {
		$tz = wp_timezone();
		return self::to_db( ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 23, 59, 59 ) );
	}

	/**
	 * Parse "HH:MM" into minutes from midnight.
	 */
	public static function hm_to_minutes( $hm ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( (string) $hm ), $m ) ) {
			return null;
		}
		$h = (int) $m[1];
		$i = (int) $m[2];
		if ( $h > 24 || $i > 59 || ( 24 === $h && $i > 0 ) ) {
			return null;
		}
		return $h * 60 + $i;
	}

	public static function sanitize_hm( $value, $fallback ) {
		$m = self::hm_to_minutes( $value );
		if ( null === $m ) {
			return $fallback;
		}
		return sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 );
	}

	/**
	 * Random URL-safe token.
	 */
	public static function token( $bytes = 18 ) {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
	}

	public static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	public static function uuid() {
		return wp_generate_uuid4();
	}

	public static function admin_url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	public static function church_admin_url( $church_id ) {
		return self::admin_url( 'aso-churches', array( 'view' => 'church', 'church' => (int) $church_id ) );
	}

	public static function is_valid_email( $email ) {
		return (bool) is_email( $email );
	}
}
