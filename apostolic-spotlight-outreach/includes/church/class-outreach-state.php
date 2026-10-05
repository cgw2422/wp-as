<?php
/**
 * Outreach state attached to an existing directory church (one row per church in
 * wp_aso_church_outreach, keyed by the directory post ID).
 *
 * All status transitions go through here so the timeline and automation stops stay consistent.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach\Church;

use ApostolicSpotlight\Outreach\Activity;
use ApostolicSpotlight\Outreach\Enrollments;
use ApostolicSpotlight\Outreach\Installer;
use ApostolicSpotlight\Outreach\Statuses;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

final class Outreach_State {

	private static function table() {
		return Installer::table( 'outreach' );
	}

	public static function defaults( $church_id ) {
		return (object) array(
			'church_id'          => (int) $church_id,
			'spotlight_status'   => 'not_contacted',
			'website_status'     => 'unknown',
			'grow_status'        => 'unknown',
			'website_review'     => '',
			'grow_fit'           => '',
			'paused'             => 0,
			'needs_review'       => 0,
			'review_reason'      => '',
			'booking_token'      => null,
			'next_action'        => '',
			'next_action_at'     => null,
			'first_contacted_at' => null,
			'last_contacted_at'  => null,
			'replied_at'         => null,
			'created_at'         => null,
			'updated_at'         => null,
		);
	}

	public static function get( $church_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE church_id = %d', $church_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $row : self::defaults( $church_id );
	}

	/**
	 * @param int[] $ids
	 * @return array<int,object>
	 */
	public static function get_many( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = self::defaults( $id );
		}
		if ( ! $ids ) {
			return $out;
		}
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . " WHERE church_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL -- ints only
		foreach ( $rows as $r ) {
			$out[ (int) $r->church_id ] = $r;
		}
		return $out;
	}

	public static function ensure( $church_id ) {
		global $wpdb;
		$now = Util::now_db();
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table() . ' (church_id, created_at, updated_at) VALUES (%d, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$church_id,
				$now,
				$now
			)
		);
	}

	public static function update( $church_id, array $data ) {
		global $wpdb;
		self::ensure( $church_id );
		$data['updated_at'] = Util::now_db();
		$wpdb->update( self::table(), $data, array( 'church_id' => (int) $church_id ) );
	}

	/* ------------------------------------------------------------------
	 * Spotlight pipeline.
	 * ------------------------------------------------------------------ */

	/**
	 * Set the Spotlight status (manual or system) with logging and side effects.
	 *
	 * @param string $source manual|system|reply|booking
	 */
	public static function set_spotlight_status( $church_id, $status, $source = 'manual', $note = '' ) {
		if ( ! Statuses::is_valid( 'spotlight', $status ) ) {
			return false;
		}
		$state = self::get( $church_id );
		$old   = $state->spotlight_status;
		if ( $old === $status ) {
			return false;
		}
		$data = array( 'spotlight_status' => $status );
		if ( 'replied' === $status && empty( $state->replied_at ) ) {
			$data['replied_at'] = Util::now_db();
		}
		if ( in_array( $status, array( 'completed', 'published' ), true ) && '' === $state->website_review && '' === $state->grow_fit ) {
			// Queue a manual opportunity review – never an automatic pitch.
			$data['needs_review']  = 1;
			$data['review_reason'] = 'opportunity_review';
		}
		self::update( $church_id, $data );

		$msg = sprintf(
			/* translators: 1: old status 2: new status */
			__( 'Spotlight status: %1$s → %2$s', 'apostolic-spotlight-outreach' ),
			Statuses::label( 'spotlight', $old ),
			Statuses::label( 'spotlight', $status )
		);
		if ( $note ) {
			$msg .= ' — ' . $note;
		}
		Activity::log( $church_id, 'status', $msg, array( 'field' => 'spotlight_status', 'from' => $old, 'to' => $status, 'source' => $source ) );

		if ( in_array( $status, Statuses::stop_statuses(), true ) ) {
			Enrollments::stop_for_church( $church_id, 'status_' . $status );
		}
		do_action( 'aso_spotlight_status_changed', $church_id, $status, $old, $source );
		return true;
	}

	/**
	 * Move forward only (used by the sender: never downgrade "Replied" to "Follow-Up 1 Sent").
	 */
	public static function advance_spotlight_status( $church_id, $status ) {
		$state = self::get( $church_id );
		if ( in_array( $state->spotlight_status, array( 'not_interested', 'no_response' ), true ) && ! in_array( $status, array( 'scheduled' ), true ) ) {
			return false;
		}
		if ( Statuses::rank( $status ) <= Statuses::rank( $state->spotlight_status ) ) {
			return false;
		}
		return self::set_spotlight_status( $church_id, $status, 'system' );
	}

	public static function mark_replied( $church_id, $source = 'manual', $detail = '' ) {
		$state = self::get( $church_id );
		self::update(
			$church_id,
			array(
				'replied_at'    => Util::now_db(),
				'needs_review'  => 1,
				'review_reason' => 'replied',
			)
		);
		if ( Statuses::rank( $state->spotlight_status ) < Statuses::rank( 'replied' ) || in_array( $state->spotlight_status, array( 'no_response' ), true ) ) {
			self::set_spotlight_status( $church_id, 'replied', $source, $detail );
		} else {
			Enrollments::stop_for_church( $church_id, 'replied' );
		}
	}

	/* ------------------------------------------------------------------
	 * Opportunities (separate from the Spotlight pipeline).
	 * ------------------------------------------------------------------ */

	public static function set_opportunity( $church_id, $field, $value ) {
		$groups = array(
			'website_status' => 'website',
			'grow_status'    => 'grow',
			'website_review' => 'website_review',
			'grow_fit'       => 'grow_fit',
		);
		if ( ! isset( $groups[ $field ] ) || ! Statuses::is_valid( $groups[ $field ], $value ) ) {
			return false;
		}
		$state = self::get( $church_id );
		if ( (string) $state->$field === (string) $value ) {
			return false;
		}
		$data = array( $field => $value );

		// Once both assessments are done, the opportunity-review flag clears.
		$wr = 'website_review' === $field ? $value : $state->website_review;
		$gf = 'grow_fit' === $field ? $value : $state->grow_fit;
		if ( 'opportunity_review' === $state->review_reason && '' !== $wr && '' !== $gf ) {
			$data['needs_review']  = 0;
			$data['review_reason'] = '';
		}
		self::update( $church_id, $data );

		$names = array(
			'website_status' => __( 'Website opportunity', 'apostolic-spotlight-outreach' ),
			'grow_status'    => __( 'Apostolic Grow opportunity', 'apostolic-spotlight-outreach' ),
			'website_review' => __( 'Website review', 'apostolic-spotlight-outreach' ),
			'grow_fit'       => __( 'Apostolic Grow fit', 'apostolic-spotlight-outreach' ),
		);
		Activity::log(
			$church_id,
			'opportunity',
			sprintf( '%s: %s → %s', $names[ $field ], Statuses::label( $groups[ $field ], $state->$field ), Statuses::label( $groups[ $field ], $value ) ),
			array( 'field' => $field, 'from' => $state->$field, 'to' => $value )
		);
		return true;
	}

	/* ------------------------------------------------------------------
	 * Automation controls.
	 * ------------------------------------------------------------------ */

	public static function pause( $church_id ) {
		self::update( $church_id, array( 'paused' => 1 ) );
		Activity::log( $church_id, 'automation_paused', __( 'Outreach manually paused', 'apostolic-spotlight-outreach' ) );
		Enrollments::stop_for_church( $church_id, 'paused', 'paused' );
	}

	public static function resume( $church_id ) {
		self::update( $church_id, array( 'paused' => 0 ) );
		Activity::log( $church_id, 'automation_resumed', __( 'Outreach resumed', 'apostolic-spotlight-outreach' ) );
		Enrollments::resume_for_church( $church_id );
	}

	public static function flag_review( $church_id, $reason ) {
		self::update( $church_id, array( 'needs_review' => 1, 'review_reason' => substr( (string) $reason, 0, 190 ) ) );
	}

	public static function clear_review( $church_id ) {
		self::update( $church_id, array( 'needs_review' => 0, 'review_reason' => '' ) );
	}

	public static function touch_contacted( $church_id, $when_db ) {
		$state = self::get( $church_id );
		$data  = array( 'last_contacted_at' => $when_db );
		if ( empty( $state->first_contacted_at ) ) {
			$data['first_contacted_at'] = $when_db;
		}
		self::update( $church_id, $data );
	}

	/* ------------------------------------------------------------------
	 * Booking tokens (non-sequential, unguessable per-church links).
	 * ------------------------------------------------------------------ */

	public static function booking_token( $church_id ) {
		$state = self::get( $church_id );
		if ( ! empty( $state->booking_token ) ) {
			return $state->booking_token;
		}
		$token = Util::token( 18 );
		self::update( $church_id, array( 'booking_token' => $token ) );
		return $token;
	}

	public static function find_by_token( $token ) {
		global $wpdb;
		$token = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $token );
		if ( strlen( $token ) < 16 ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT church_id FROM ' . self::table() . ' WHERE booking_token = %s', $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function regenerate_token( $church_id ) {
		self::update( $church_id, array( 'booking_token' => Util::token( 18 ) ) );
	}

	public static function review_reason_label( $reason ) {
		$labels = array(
			'replied'            => __( 'Replied – respond personally', 'apostolic-spotlight-outreach' ),
			'opportunity_review' => __( 'Review website / Grow opportunity', 'apostolic-spotlight-outreach' ),
			'bounce'             => __( 'Email bounced – check address', 'apostolic-spotlight-outreach' ),
			'auto_reply'         => __( 'Auto-reply received', 'apostolic-spotlight-outreach' ),
			'send_failed'        => __( 'Email failed to send', 'apostolic-spotlight-outreach' ),
			'held'               => __( 'Email held – missing information', 'apostolic-spotlight-outreach' ),
			'booking_unmatched'  => __( 'Booking needs church match', 'apostolic-spotlight-outreach' ),
		);
		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : ( $reason ? $reason : __( 'Needs review', 'apostolic-spotlight-outreach' ) );
	}
}
