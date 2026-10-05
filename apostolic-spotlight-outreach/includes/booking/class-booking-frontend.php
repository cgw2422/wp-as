<?php
/**
 * Public Calendly-style scheduling page.
 *
 * - Route:      /spotlight/schedule/ (configurable) via rewrite rule, or [apostolic_spotlight_booking] shortcode
 * - Personal:   /spotlight/schedule/?church={token}  (unguessable per-church token, never a post ID)
 * - Confirmed:  /spotlight/schedule/?booking={public_id}
 * - REST:       GET  /wp-json/aso/v1/booking/slots
 *               POST /wp-json/aso/v1/booking
 *               GET  /wp-json/aso/v1/booking/{public_id}/ics
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach\Booking;

use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Church\Outreach_State;
use ApostolicSpotlight\Outreach\Settings;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

final class Booking_Frontend {

	const QUERY_VAR = 'aso_booking';

	/** @var bool */
	private static $assets_needed = false;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
		add_shortcode( 'apostolic_spotlight_booking', array( __CLASS__, 'shortcode' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function add_rewrite_rules() {
		$slug = trim( (string) Settings::get( 'booking.slug', 'spotlight/schedule' ), '/' );
		if ( '' === $slug ) {
			return;
		}
		add_rewrite_rule( '^' . preg_quote( $slug, '/' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function maybe_render() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // Personal links must never be page-cached.
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		status_header( 200 );

		self::enqueue();
		$content = self::render_inner();

		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			include ASO_DIR . 'templates/booking-block-theme.php';
		} else {
			get_header();
			echo '<main id="primary" class="site-main aso-booking-main">' . $content . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped template.
			get_footer();
		}
		exit;
	}

	public static function shortcode() {
		self::enqueue();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		return self::render_inner();
	}

	private static function enqueue() {
		wp_enqueue_style( 'aso-booking', ASO_URL . 'assets/css/booking.css', array(), ASO_VERSION );
		wp_enqueue_script( 'aso-booking', ASO_URL . 'assets/js/booking.js', array(), ASO_VERSION, true );
		wp_localize_script(
			'aso-booking',
			'ASOBooking',
			array(
				'slotsUrl' => esc_url_raw( rest_url( 'aso/v1/booking/slots' ) ),
				'bookUrl'  => esc_url_raw( rest_url( 'aso/v1/booking' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'ownerTz'  => (string) Settings::get( 'booking.timezone' ),
				'duration' => (int) Settings::get( 'booking.duration', 15 ),
				'i18n'     => array(
					'loading'     => __( 'Checking availability…', 'apostolic-spotlight-outreach' ),
					'noSlots'     => __( 'There are no open times right now. Please check back soon or reply to the email you received.', 'apostolic-spotlight-outreach' ),
					'error'       => __( 'Something went wrong. Please try again.', 'apostolic-spotlight-outreach' ),
					'booking'     => __( 'Scheduling…', 'apostolic-spotlight-outreach' ),
					'confirm'     => __( 'Confirm interview', 'apostolic-spotlight-outreach' ),
					'pickTime'    => __( 'Pick a time', 'apostolic-spotlight-outreach' ),
					'timesIn'     => __( 'Times shown in', 'apostolic-spotlight-outreach' ),
					'selected'    => __( 'Selected:', 'apostolic-spotlight-outreach' ),
					'change'      => __( 'Change', 'apostolic-spotlight-outreach' ),
				),
			)
		);
	}

	private static function render_inner() {
		// Confirmation view.
		$public_id = isset( $_GET['booking'] ) ? sanitize_text_field( wp_unslash( $_GET['booking'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $public_id ) {
			$booking = Bookings::find_public( $public_id );
			if ( $booking ) {
				$booking = Bookings::refresh_meet( $booking );
				ob_start();
				include ASO_DIR . 'templates/booking-confirmation.php';
				return ob_get_clean();
			}
		}

		$token   = isset( $_GET['church'] ) ? sanitize_text_field( wp_unslash( $_GET['church'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$prefill = array( 'token' => '', 'church_name' => '', 'pastor_name' => '', 'email' => '', 'phone' => '' );
		if ( $token ) {
			$church_id = Outreach_State::find_by_token( $token );
			$church    = $church_id ? Church_Repository::find( $church_id ) : null;
			if ( $church ) {
				$prefill = array(
					'token'       => $token,
					'church_name' => $church->get( 'church_name' ),
					'pastor_name' => \ApostolicSpotlight\Outreach\Template_Renderer::clean_pastor_name( $church->get( 'pastor_name' ) ),
					'email'       => $church->outreach_email(),
					'phone'       => $church->get( 'phone' ),
				);
				$state = Outreach_State::get( $church->id );
				if ( 'scheduled' === $state->spotlight_status ) {
					$prefill['already_scheduled'] = true;
				}
			}
		}
		ob_start();
		include ASO_DIR . 'templates/booking-page.php';
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------
	 * REST.
	 * ------------------------------------------------------------------ */

	public static function routes() {
		register_rest_route(
			'aso/v1',
			'/booking/slots',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_slots' ),
				'permission_callback' => '__return_true', // Public by design: returns only open start times.
			)
		);
		register_rest_route(
			'aso/v1',
			'/booking',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_book' ),
				'permission_callback' => '__return_true', // Public form; protected by honeypot, rate limit and server-side slot validation.
				'args'                => array(
					'start'       => array( 'required' => true, 'type' => 'string' ),
					'pastor_name' => array( 'required' => true, 'type' => 'string' ),
					'church_name' => array( 'required' => true, 'type' => 'string' ),
					'email'       => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			'aso/v1',
			'/booking/(?P<id>[A-Za-z0-9_\-]{20,64})/ics',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_ics' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function rest_slots() {
		$slots = Availability::slots();
		if ( is_wp_error( $slots ) ) {
			return new \WP_REST_Response( array( 'slots' => array(), 'error' => __( 'Scheduling is temporarily unavailable. Please try again later.', 'apostolic-spotlight-outreach' ) ), 200 );
		}
		$out = array();
		foreach ( $slots as $s ) {
			$out[] = gmdate( 'c', $s['start'] );
		}
		$response = new \WP_REST_Response(
			array(
				'slots'    => $out,
				'duration' => (int) Settings::get( 'booking.duration', 15 ),
				'ownerTz'  => (string) Settings::get( 'booking.timezone' ),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function rest_book( \WP_REST_Request $req ) {
		// Honeypot.
		if ( '' !== trim( (string) $req->get_param( 'website' ) ) ) {
			return new \WP_Error( 'aso_rejected', __( 'Unable to schedule.', 'apostolic-spotlight-outreach' ), array( 'status' => 400 ) );
		}
		// Rate limit: 8 attempts / hour / IP.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'aso_rl_' . md5( $ip . wp_salt( 'nonce' ) );
		$n   = (int) get_transient( $key );
		if ( $n >= 8 ) {
			return new \WP_Error( 'aso_rate', __( 'Too many attempts. Please try again later.', 'apostolic-spotlight-outreach' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );

		$start = strtotime( (string) $req->get_param( 'start' ) );
		$data  = array(
			'start'       => $start ? $start : 0,
			'pastor_name' => sanitize_text_field( (string) $req->get_param( 'pastor_name' ) ),
			'church_name' => sanitize_text_field( (string) $req->get_param( 'church_name' ) ),
			'email'       => sanitize_email( (string) $req->get_param( 'email' ) ),
			'phone'       => sanitize_text_field( (string) $req->get_param( 'phone' ) ),
			'notes'       => sanitize_textarea_field( (string) $req->get_param( 'notes' ) ),
			'timezone'    => self::sanitize_tz( (string) $req->get_param( 'timezone' ) ),
			'church_id'   => 0,
		);
		if ( ! $data['start'] || '' === $data['pastor_name'] || '' === $data['church_name'] || ! is_email( $data['email'] ) ) {
			return new \WP_Error( 'aso_invalid', __( 'Please complete your name, church, email and a time.', 'apostolic-spotlight-outreach' ), array( 'status' => 400 ) );
		}
		$data['pastor_name'] = mb_substr( $data['pastor_name'], 0, 190 );
		$data['church_name'] = mb_substr( $data['church_name'], 0, 190 );
		$data['phone']       = mb_substr( $data['phone'], 0, 64 );
		$data['notes']       = mb_substr( $data['notes'], 0, 2000 );

		$token = (string) $req->get_param( 'token' );
		if ( $token ) {
			$data['church_id'] = Outreach_State::find_by_token( $token );
		}

		$booking = Bookings::create( $data, $data['church_id'] ? 'link' : 'public' );
		if ( is_wp_error( $booking ) ) {
			return new \WP_Error( $booking->get_error_code(), $booking->get_error_message(), array( 'status' => 409 ) );
		}
		return array(
			'ok'       => true,
			'redirect' => add_query_arg( 'booking', rawurlencode( $booking->public_id ), Settings::booking_url() ),
		);
	}

	public static function rest_ics( \WP_REST_Request $req ) {
		$booking = Bookings::find_public( $req['id'] );
		if ( ! $booking || 'confirmed' !== $booking->status ) {
			return new \WP_Error( 'aso_not_found', 'Not found', array( 'status' => 404 ) );
		}
		$title = Settings::get( 'booking.meeting_name' );
		$desc  = $booking->meet_url ? 'Join with Google Meet: ' . $booking->meet_url : '';
		$ics   = implode(
			"\r\n",
			array(
				'BEGIN:VCALENDAR',
				'VERSION:2.0',
				'PRODID:-//Apostolic Spotlight//Outreach//EN',
				'METHOD:PUBLISH',
				'BEGIN:VEVENT',
				'UID:' . $booking->public_id . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
				'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
				'DTSTART:' . gmdate( 'Ymd\THis\Z', strtotime( $booking->start_at . ' UTC' ) ),
				'DTEND:' . gmdate( 'Ymd\THis\Z', strtotime( $booking->end_at . ' UTC' ) ),
				'SUMMARY:' . self::ics_escape( $title ),
				'DESCRIPTION:' . self::ics_escape( $desc ),
				'LOCATION:' . self::ics_escape( $booking->meet_url ),
				'END:VEVENT',
				'END:VCALENDAR',
			)
		);
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="apostolic-spotlight-interview.ics"' );
		echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private static function ics_escape( $s ) {
		return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\;', '\,', '\n' ), (string) $s );
	}

	private static function sanitize_tz( $tz ) {
		return in_array( $tz, timezone_identifiers_list(), true ) ? $tz : (string) Settings::get( 'booking.timezone' );
	}

	/**
	 * Google Calendar "add event" template link for the confirmation page.
	 */
	public static function google_calendar_link( $booking ) {
		return add_query_arg(
			array(
				'action'  => 'TEMPLATE',
				'text'    => rawurlencode( (string) Settings::get( 'booking.meeting_name' ) ),
				'dates'   => gmdate( 'Ymd\THis\Z', strtotime( $booking->start_at . ' UTC' ) ) . '/' . gmdate( 'Ymd\THis\Z', strtotime( $booking->end_at . ' UTC' ) ),
				'details' => rawurlencode( $booking->meet_url ? 'Google Meet: ' . $booking->meet_url : '' ),
			),
			'https://calendar.google.com/calendar/render'
		);
	}
}
