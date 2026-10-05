<?php
/**
 * Google Calendar API wrapper: free/busy, events with Google Meet conferences.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach\Google;

use ApostolicSpotlight\Outreach\Settings;

defined( 'ABSPATH' ) || exit;

final class Calendar {

	const BASE = 'https://www.googleapis.com/calendar/v3';

	/**
	 * Calendar IDs whose busy blocks remove availability.
	 *
	 * @return string[]
	 */
	public static function busy_calendar_ids() {
		$raw = (string) Settings::get( 'booking.busy_calendars', 'primary' );
		$ids = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $raw ) ) );
		return $ids ? array_values( $ids ) : array( 'primary' );
	}

	public static function event_calendar_id() {
		$id = trim( (string) Settings::get( 'booking.event_calendar', 'primary' ) );
		return $id ? $id : 'primary';
	}

	/**
	 * Busy intervals across configured calendars.
	 *
	 * @return array<int,array{0:int,1:int}>|\WP_Error Unix timestamp pairs.
	 */
	public static function busy( \DateTimeInterface $from, \DateTimeInterface $to ) {
		$items = array();
		foreach ( self::busy_calendar_ids() as $id ) {
			$items[] = array( 'id' => $id );
		}
		$result = Google_Client::request(
			'POST',
			self::BASE . '/freeBusy',
			array(
				'timeMin' => gmdate( 'c', $from->getTimestamp() ),
				'timeMax' => gmdate( 'c', $to->getTimestamp() ),
				'items'   => $items,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$busy = array();
		foreach ( (array) ( $result['calendars'] ?? array() ) as $cal_id => $cal ) {
			if ( ! empty( $cal['errors'] ) ) {
				$reason = isset( $cal['errors'][0]['reason'] ) ? $cal['errors'][0]['reason'] : 'unknown';
				// Fail closed: if we cannot read a calendar we must not offer times.
				return new \WP_Error( 'aso_freebusy_calendar', sprintf( 'Calendar %s could not be read (%s).', $cal_id, $reason ) );
			}
			foreach ( (array) ( $cal['busy'] ?? array() ) as $b ) {
				$busy[] = array( strtotime( $b['start'] ), strtotime( $b['end'] ) );
			}
		}
		return $busy;
	}

	/**
	 * Create an event with a Google Meet conference and email the attendee.
	 *
	 * @return array|\WP_Error Event resource.
	 */
	public static function create_event( array $event ) {
		$event['conferenceData'] = array(
			'createRequest' => array(
				'requestId'             => wp_generate_uuid4(),
				'conferenceSolutionKey' => array( 'type' => 'hangoutsMeet' ),
			),
		);
		return Google_Client::request(
			'POST',
			self::BASE . '/calendars/' . rawurlencode( self::event_calendar_id() ) . '/events',
			$event,
			array(
				'conferenceDataVersion' => 1,
				'sendUpdates'           => 'all',
			)
		);
	}

	public static function get_event( $calendar_id, $event_id ) {
		return Google_Client::request( 'GET', self::BASE . '/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id ) );
	}

	public static function patch_event( $calendar_id, $event_id, array $patch ) {
		return Google_Client::request(
			'PATCH',
			self::BASE . '/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id ),
			$patch,
			array( 'sendUpdates' => 'all', 'conferenceDataVersion' => 1 )
		);
	}

	public static function delete_event( $calendar_id, $event_id ) {
		return Google_Client::request(
			'DELETE',
			self::BASE . '/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id ),
			null,
			array( 'sendUpdates' => 'all' )
		);
	}

	/**
	 * Pull the Meet join URL from an event resource.
	 */
	public static function meet_url( array $event ) {
		if ( ! empty( $event['hangoutLink'] ) ) {
			return (string) $event['hangoutLink'];
		}
		foreach ( (array) ( $event['conferenceData']['entryPoints'] ?? array() ) as $ep ) {
			if ( 'video' === ( $ep['entryPointType'] ?? '' ) && ! empty( $ep['uri'] ) ) {
				return (string) $ep['uri'];
			}
		}
		return '';
	}
}
