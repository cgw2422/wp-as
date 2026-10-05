<?php
/**
 * Plugin settings stored in a single non-autoloaded option.
 *
 * Secrets (OAuth client secret, tokens) are NOT stored here in plain text –
 * see Crypto and Google_Oauth.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'aso_settings';

	/** @var array|null */
	private static $cache = null;

	public static function defaults() {
		$tz = wp_timezone_string();
		if ( ! $tz || preg_match( '/^[+-]\d/', $tz ) || 'UTC' === $tz ) {
			$tz = 'America/New_York';
		}
		return array(
			'sender_name'  => 'Cody Woods',
			'church'       => array(
				'post_type'  => '',
				'map'        => array(),
				'write_back' => 1,
			),
			'sending'      => array(
				'daily_limit'         => 25,
				'days'                => array( 1, 2, 3, 4, 5 ),
				'start'               => '09:00',
				'end'                 => '16:00',
				'timezone'            => $tz,
				'min_spacing'         => 8,
				'distribution'        => 'random',
				'recent_contact_days' => 60,
				'no_response_days'    => 7,
				'paused'              => 0,
			),
			'sequence'     => array(
				'followup1_delay' => 4,
				'followup2_delay' => 7,
			),
			'booking'      => array(
				'slug'              => 'spotlight/schedule',
				'meeting_name'      => 'Apostolic Spotlight Interview',
				'event_title'       => 'Apostolic Spotlight – {{church_name}}',
				'duration'          => 15,
				'slot_interval'     => 15,
				'buffer_before'     => 0,
				'buffer_after'      => 5,
				'min_notice_hours'  => 24,
				'window_days'       => 30,
				'max_per_day'       => 0,
				'timezone'          => $tz,
				'hours'             => array(
					1 => array( array( '18:00', '21:00' ) ),
					2 => array( array( '18:00', '21:00' ) ),
					3 => array(),
					4 => array( array( '18:00', '21:00' ) ),
					5 => array(),
					6 => array(),
					7 => array(),
				),
				'busy_calendars'    => 'primary',
				'event_calendar'    => 'primary',
				'intro'             => "Choose a time for a short 15-minute conversation about your church. We'll talk about your church's history, leadership, ministries, and what God is doing in your community.",
				'admin_link_in_event' => 0,
			),
			'replies'      => array(
				'enabled' => 0,
			),
			'permissions'  => array(
				'editors' => 0,
			),
			'google'       => array(
				'client_id' => '',
			),
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * Dot-notation getter: Settings::get( 'sending.daily_limit' ).
	 */
	public static function get( $path, $default = null ) {
		$value = self::all();
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return $default;
			}
			$value = $value[ $key ];
		}
		return $value;
	}

	/**
	 * Replace a top-level section.
	 */
	public static function update_section( $section, array $values ) {
		$all             = self::all();
		$all[ $section ] = $values;
		self::save( $all );
	}

	public static function set( $key, $value ) {
		$all         = self::all();
		$all[ $key ] = $value;
		self::save( $all );
	}

	public static function save( array $all ) {
		update_option( self::OPTION, $all, false );
		self::$cache = null;
	}

	/**
	 * Recursive merge where stored values override defaults, but list-like
	 * values (numeric keys) replace wholesale.
	 */
	private static function merge( array $defaults, array $stored ) {
		foreach ( $stored as $key => $value ) {
			if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && ! self::is_list( $defaults[ $key ] ) && ! self::is_list( $value ) ) {
				$defaults[ $key ] = self::merge( $defaults[ $key ], $value );
			} else {
				$defaults[ $key ] = $value;
			}
		}
		return $defaults;
	}

	private static function is_list( array $a ) {
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	public static function sending_timezone() {
		return Util::timezone( self::get( 'sending.timezone' ) );
	}

	public static function booking_timezone() {
		return Util::timezone( self::get( 'booking.timezone' ) );
	}

	public static function booking_url( $token = '' ) {
		$slug = trim( (string) self::get( 'booking.slug', 'spotlight/schedule' ), '/' );
		$url  = home_url( user_trailingslashit( $slug ) );
		if ( $token ) {
			$url = add_query_arg( 'church', rawurlencode( $token ), $url );
		}
		return $url;
	}
}
