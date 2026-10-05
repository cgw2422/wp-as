<?php
/**
 * Spotlight interview bookings (wp_aso_bookings) + Google Calendar/Meet lifecycle.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach\Booking;

use ApostolicSpotlight\Outreach\Activity;
use ApostolicSpotlight\Outreach\Church\Church;
use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Church\Field_Map;
use ApostolicSpotlight\Outreach\Church\Outreach_State;
use ApostolicSpotlight\Outreach\Google\Calendar;
use ApostolicSpotlight\Outreach\Installer;
use ApostolicSpotlight\Outreach\Settings;
use ApostolicSpotlight\Outreach\Template_Renderer;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

final class Bookings {

	private static function table() {
		return Installer::table( 'bookings' );
	}

	public static function find( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function find_public( $public_id ) {
		global $wpdb;
		$public_id = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $public_id );
		if ( strlen( $public_id ) < 20 ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE public_id = %s', $public_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function for_church( $church_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE church_id = %d ORDER BY start_at DESC', $church_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Latest relevant booking per church: church_id => row.
	 */
	public static function latest_for_churches( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return array();
		}
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . " WHERE church_id IN ({$in}) AND status <> 'failed' ORDER BY start_at ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL -- ints only
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->church_id ] = $r;
		}
		return $out;
	}

	/**
	 * @param string $view upcoming|past|completed|canceled|today|unlinked
	 */
	public static function query( $view, $limit = 100 ) {
		global $wpdb;
		$t   = self::table();
		$now = Util::now_db();
		switch ( $view ) {
			case 'past':
				$sql = $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'confirmed' AND end_at < %s ORDER BY start_at DESC LIMIT %d", $now, $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				break;
			case 'completed':
				$sql = $wpdb->prepare( "SELECT * FROM {$t} WHERE status IN ('completed','no_show') ORDER BY start_at DESC LIMIT %d", $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				break;
			case 'canceled':
				$sql = $wpdb->prepare( "SELECT * FROM {$t} WHERE status IN ('canceled','failed') ORDER BY start_at DESC LIMIT %d", $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				break;
			case 'today':
				$tz    = wp_timezone();
				$start = Util::to_db( ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0 ) );
				$sql   = $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'confirmed' AND start_at >= %s AND start_at <= %s ORDER BY start_at ASC LIMIT %d", $start, Util::end_of_today_db(), $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				break;
			case 'unlinked':
				$sql = $wpdb->prepare( "SELECT * FROM {$t} WHERE church_id IS NULL AND status IN ('confirmed','completed') ORDER BY start_at DESC LIMIT %d", $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				break;
			default:
				$sql = $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'confirmed' AND end_at >= %s ORDER BY start_at ASC LIMIT %d", $now, $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function count( $view ) {
		return count( self::query( $view, 1000 ) );
	}

	/**
	 * Find a directory church by an email address (pastor or church email).
	 */
	public static function match_church_by_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) ) {
			return 0;
		}
		$meta_query = array( 'relation' => 'OR' );
		foreach ( array( 'pastor_email', 'church_email' ) as $f ) {
			$key = Field_Map::meta_key( $f );
			if ( $key ) {
				$meta_query[] = array( 'key' => $key, 'value' => $email, 'compare' => '=' );
			}
		}
		if ( count( $meta_query ) < 2 ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => Church_Repository::post_type(),
				'post_status'    => Church_Repository::STATUSES,
				'fields'         => 'ids',
				'posts_per_page' => 2,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return 1 === count( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Create a booking and its Google Calendar event (with Meet) – called from the public form.
	 *
	 * @param array $data { start (ts), pastor_name, church_name, email, phone, notes, timezone, church_id }
	 * @return object|\WP_Error Booking row.
	 */
	public static function create( array $data, $source = 'public' ) {
		global $wpdb;

		$lock = $wpdb->get_var( "SELECT GET_LOCK('aso_booking', 10)" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( '1' !== (string) $lock ) {
			return new \WP_Error( 'aso_busy', __( 'The scheduler is busy. Please try again in a moment.', 'apostolic-spotlight-outreach' ) );
		}
		try {
			$start     = (int) $data['start'];
			$available = Availability::is_available( $start, true );
			if ( is_wp_error( $available ) ) {
				return new \WP_Error( 'aso_unavailable', __( 'We could not check the calendar right now. Please try again shortly.', 'apostolic-spotlight-outreach' ) );
			}
			if ( ! $available ) {
				return new \WP_Error( 'aso_slot_taken', __( 'Sorry, that time was just taken. Please choose another time.', 'apostolic-spotlight-outreach' ) );
			}

			$church_id = ! empty( $data['church_id'] ) ? (int) $data['church_id'] : self::match_church_by_email( $data['email'] );
			$church    = $church_id ? Church_Repository::find( $church_id ) : null;
			$church_id = $church ? $church->id : 0;

			$end = $start + max( 5, (int) Settings::get( 'booking.duration', 15 ) ) * 60;
			$now = Util::now_db();
			$wpdb->insert(
				self::table(),
				array(
					'public_id'          => Util::token( 24 ),
					'church_id'          => $church_id ? $church_id : null,
					'status'             => 'confirmed',
					'source'             => $source,
					'pastor_name'        => $data['pastor_name'],
					'church_name'        => $data['church_name'],
					'email'              => $data['email'],
					'phone'              => $data['phone'],
					'notes'              => $data['notes'],
					'start_at'           => gmdate( Util::DB_FORMAT, $start ),
					'end_at'             => gmdate( Util::DB_FORMAT, $end ),
					'attendee_timezone'  => $data['timezone'],
					'google_calendar_id' => Calendar::event_calendar_id(),
					'created_at'         => $now,
					'updated_at'         => $now,
				)
			);
			$booking = self::find( $wpdb->insert_id );

			$event = Calendar::create_event( self::event_body( $booking, $church ) );
			if ( is_wp_error( $event ) ) {
				$wpdb->update( self::table(), array( 'status' => 'failed', 'error' => $event->get_error_message(), 'updated_at' => Util::now_db() ), array( 'id' => $booking->id ) );
				if ( $church_id ) {
					Activity::log( $church_id, 'booking_canceled', sprintf( /* translators: %s: error */ __( 'Booking attempt failed (calendar error): %s', 'apostolic-spotlight-outreach' ), $event->get_error_message() ) );
				}
				return new \WP_Error( 'aso_calendar', __( 'We could not create the calendar invitation. Please try again or reply to the email you received.', 'apostolic-spotlight-outreach' ) );
			}
			$meet = Calendar::meet_url( $event );
			$wpdb->update(
				self::table(),
				array(
					'google_event_id' => (string) $event['id'],
					'meet_url'        => $meet,
					'event_html_link' => (string) ( $event['htmlLink'] ?? '' ),
					'updated_at'      => Util::now_db(),
				),
				array( 'id' => $booking->id )
			);
			Availability::flush_cache();
			$booking = self::find( $booking->id );
		} finally {
			$wpdb->query( "SELECT RELEASE_LOCK('aso_booking')" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		if ( $church ) {
			self::apply_to_church( $booking, $church );
		} else {
			// No church match – surfaces on the dashboard for manual linking.
			do_action( 'aso_booking_unlinked', $booking );
		}
		do_action( 'aso_booking_created', $booking );
		return $booking;
	}

	/**
	 * Update church pipeline & fill blank contact fields from the booking.
	 */
	public static function apply_to_church( $booking, Church $church ) {
		$when = Util::format( $booking->start_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', Settings::booking_timezone() );
		Activity::log(
			$church->id,
			'booking',
			/* translators: 1: date/time 2: pastor */
			sprintf( __( 'Spotlight interview booked for %1$s by %2$s', 'apostolic-spotlight-outreach' ), $when, $booking->pastor_name ),
			array( 'booking_id' => $booking->id, 'meet_url' => $booking->meet_url )
		);
		Outreach_State::set_spotlight_status( $church->id, 'scheduled', 'booking' );

		// Only fill BLANK fields; differences are noted for manual review, never overwritten.
		$fill  = array();
		$diffs = array();
		$pairs = array(
			'pastor_name'  => $booking->pastor_name,
			'pastor_email' => $booking->email,
			'phone'        => $booking->phone,
		);
		foreach ( $pairs as $field => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$current = $church->get( $field );
			if ( '' === $current && Church_Repository::is_writable( $field ) ) {
				$fill[ $field ] = $value;
			} elseif ( '' !== $current && 0 !== strcasecmp( trim( $current ), trim( $value ) ) && ! ( 'pastor_email' === $field && 0 === strcasecmp( $church->get( 'church_email' ), $value ) ) ) {
				$diffs[] = Field_Map::fields()[ $field ]['label'] . ': ' . $value;
			}
		}
		if ( $fill ) {
			Church_Repository::update_fields( $church, $fill );
		}
		if ( $diffs ) {
			Activity::log( $church->id, 'note', __( 'Booking form details differ from the directory (not overwritten): ', 'apostolic-spotlight-outreach' ) . implode( '; ', $diffs ) );
		}
	}

	/**
	 * Link an unmatched booking to a church after the fact.
	 */
	public static function link_church( $booking_id, $church_id ) {
		global $wpdb;
		$booking = self::find( $booking_id );
		$church  = Church_Repository::find( $church_id );
		if ( ! $booking || ! $church ) {
			return false;
		}
		$wpdb->update( self::table(), array( 'church_id' => $church->id, 'updated_at' => Util::now_db() ), array( 'id' => $booking->id ) );
		self::apply_to_church( self::find( $booking->id ), $church );
		if ( 'completed' === $booking->status ) {
			Outreach_State::set_spotlight_status( $church->id, 'completed', 'manual' );
		}
		return true;
	}

	private static function event_body( $booking, $church ) {
		$tz        = Settings::get( 'booking.timezone' );
		$church_nm = $church ? $church->name() : $booking->church_name;
		$title     = Template_Renderer::render( (string) Settings::get( 'booking.event_title' ), array( 'church_name' => $church_nm, 'pastor_name' => $booking->pastor_name ) );

		$lines   = array();
		$lines[] = sprintf( '%s with %s%s.', Settings::get( 'booking.meeting_name' ), $booking->pastor_name, $church_nm ? ' of ' . $church_nm : '' );
		$lines[] = '';
		$lines[] = 'Church: ' . $church_nm;
		if ( $church && $church->location() ) {
			$lines[] = 'Location: ' . $church->location();
		}
		if ( $church && $church->get( 'organization' ) ) {
			$lines[] = 'Organization: ' . $church->get( 'organization' );
		}
		$lines[] = 'Pastor: ' . $booking->pastor_name;
		$lines[] = 'Email: ' . $booking->email;
		if ( $booking->phone ) {
			$lines[] = 'Phone: ' . $booking->phone;
		}
		if ( $church && $church->get( 'website' ) ) {
			$lines[] = 'Website: ' . $church->get( 'website' );
		}
		if ( $church && $church->get( 'listing_url' ) ) {
			$lines[] = 'Apostolic Spotlight listing: ' . $church->get( 'listing_url' );
		}
		if ( $booking->notes ) {
			$lines[] = '';
			$lines[] = 'Notes from the pastor: ' . $booking->notes;
		}
		if ( $church && Settings::get( 'booking.admin_link_in_event' ) ) {
			$lines[] = '';
			$lines[] = 'Outreach record: ' . Util::church_admin_url( $church->id );
		}

		return array(
			'summary'            => $title['text'],
			'description'        => implode( "\n", $lines ),
			'start'              => array( 'dateTime' => gmdate( 'c', strtotime( $booking->start_at . ' UTC' ) ), 'timeZone' => $tz ),
			'end'                => array( 'dateTime' => gmdate( 'c', strtotime( $booking->end_at . ' UTC' ) ), 'timeZone' => $tz ),
			'attendees'          => array(
				array(
					'email'       => $booking->email,
					'displayName' => $booking->pastor_name,
				),
			),
			'guestsCanModify'    => false,
			'reminders'          => array( 'useDefault' => true ),
			'extendedProperties' => array(
				'private' => array(
					'aso_booking_id' => (string) $booking->id,
					'aso_church_id'  => (string) ( $church ? $church->id : '' ),
				),
			),
		);
	}

	/**
	 * Meet links are normally returned immediately; if conference creation was still pending, re-fetch.
	 */
	public static function refresh_meet( $booking ) {
		global $wpdb;
		if ( $booking->meet_url || ! $booking->google_event_id ) {
			return $booking;
		}
		$event = Calendar::get_event( $booking->google_calendar_id ? $booking->google_calendar_id : 'primary', $booking->google_event_id );
		if ( ! is_wp_error( $event ) ) {
			$meet = Calendar::meet_url( $event );
			if ( $meet ) {
				$wpdb->update( self::table(), array( 'meet_url' => $meet, 'updated_at' => Util::now_db() ), array( 'id' => $booking->id ) );
				$booking->meet_url = $meet;
			}
		}
		return $booking;
	}

	/* ------------------------------------------------------------------
	 * Admin lifecycle actions.
	 * ------------------------------------------------------------------ */

	public static function cancel( $id, $reason = '' ) {
		global $wpdb;
		$booking = self::find( $id );
		if ( ! $booking || 'confirmed' !== $booking->status ) {
			return new \WP_Error( 'aso_booking', __( 'Booking not found or not active.', 'apostolic-spotlight-outreach' ) );
		}
		if ( $booking->google_event_id ) {
			$res = Calendar::delete_event( $booking->google_calendar_id ? $booking->google_calendar_id : 'primary', $booking->google_event_id );
			if ( is_wp_error( $res ) && ! in_array( (int) ( $res->get_error_data()['status'] ?? 0 ), array( 404, 410 ), true ) ) {
				return $res;
			}
		}
		$wpdb->update( self::table(), array( 'status' => 'canceled', 'updated_at' => Util::now_db() ), array( 'id' => $booking->id ) );
		Availability::flush_cache();
		if ( $booking->church_id ) {
			Activity::log( $booking->church_id, 'booking_canceled', __( 'Spotlight interview canceled', 'apostolic-spotlight-outreach' ) . ( $reason ? ': ' . $reason : '' ), array( 'booking_id' => $booking->id ) );
			$state = Outreach_State::get( $booking->church_id );
			if ( 'scheduled' === $state->spotlight_status ) {
				Outreach_State::set_spotlight_status( $booking->church_id, 'interested', 'manual', __( 'interview canceled', 'apostolic-spotlight-outreach' ) );
			}
		}
		return true;
	}

	/**
	 * Move the event; Google emails the pastor the updated invitation.
	 */
	public static function reschedule( $id, $new_start_ts ) {
		global $wpdb;
		$booking = self::find( $id );
		if ( ! $booking || 'confirmed' !== $booking->status ) {
			return new \WP_Error( 'aso_booking', __( 'Booking not found or not active.', 'apostolic-spotlight-outreach' ) );
		}
		$duration = strtotime( $booking->end_at . ' UTC' ) - strtotime( $booking->start_at . ' UTC' );
		$tz       = Settings::get( 'booking.timezone' );
		$end_ts   = $new_start_ts + $duration;
		if ( $booking->google_event_id ) {
			$res = Calendar::patch_event(
				$booking->google_calendar_id ? $booking->google_calendar_id : 'primary',
				$booking->google_event_id,
				array(
					'start' => array( 'dateTime' => gmdate( 'c', $new_start_ts ), 'timeZone' => $tz ),
					'end'   => array( 'dateTime' => gmdate( 'c', $end_ts ), 'timeZone' => $tz ),
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		$wpdb->update(
			self::table(),
			array(
				'start_at'   => gmdate( Util::DB_FORMAT, $new_start_ts ),
				'end_at'     => gmdate( Util::DB_FORMAT, $end_ts ),
				'updated_at' => Util::now_db(),
			),
			array( 'id' => $booking->id )
		);
		Availability::flush_cache();
		if ( $booking->church_id ) {
			Activity::log(
				$booking->church_id,
				'booking_moved',
				sprintf(
					/* translators: 1: old time 2: new time */
					__( 'Interview moved from %1$s to %2$s', 'apostolic-spotlight-outreach' ),
					Util::format( $booking->start_at ),
					Util::format( gmdate( Util::DB_FORMAT, $new_start_ts ) )
				),
				array( 'booking_id' => $booking->id )
			);
		}
		return true;
	}

	public static function set_outcome( $id, $status ) {
		global $wpdb;
		$booking = self::find( $id );
		if ( ! $booking || ! in_array( $status, array( 'completed', 'no_show' ), true ) ) {
			return false;
		}
		$wpdb->update( self::table(), array( 'status' => $status, 'updated_at' => Util::now_db() ), array( 'id' => $booking->id ) );
		if ( $booking->church_id ) {
			if ( 'completed' === $status ) {
				Outreach_State::set_spotlight_status( $booking->church_id, 'completed', 'manual', __( 'interview held', 'apostolic-spotlight-outreach' ) );
			} else {
				Activity::log( $booking->church_id, 'booking_canceled', __( 'Marked as no-show', 'apostolic-spotlight-outreach' ), array( 'booking_id' => $booking->id ) );
				Outreach_State::set_spotlight_status( $booking->church_id, 'interested', 'manual', __( 'no-show – reschedule personally', 'apostolic-spotlight-outreach' ) );
				Outreach_State::flag_review( $booking->church_id, 'no_show' );
			}
		}
		return true;
	}
}
