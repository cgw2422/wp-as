<?php
/**
 * Campaign enrollments, duplicate-protection checks and automation stops.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

use ApostolicSpotlight\Outreach\Church\Church;
use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Church\Outreach_State;

defined( 'ABSPATH' ) || exit;

final class Enrollments {

	private static function table() {
		return Installer::table( 'enrollments' );
	}

	public static function find( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function for_church( $church_id ) {
		global $wpdb;
		$c = Installer::table( 'campaigns' );
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.*, c.name AS campaign_name, c.status AS campaign_status FROM ' . self::table() . " e LEFT JOIN {$c} c ON c.id = e.campaign_id WHERE e.church_id = %d ORDER BY e.enrolled_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL
				$church_id
			)
		);
	}

	/**
	 * Active (or paused) enrollments for many churches: church_id => row.
	 */
	public static function open_for_churches( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return array();
		}
		$c    = Installer::table( 'campaigns' );
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results( 'SELECT e.*, c.name AS campaign_name FROM ' . self::table() . " e LEFT JOIN {$c} c ON c.id = e.campaign_id WHERE e.church_id IN ({$in}) AND e.status IN ('active','paused','finished') ORDER BY e.enrolled_at ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL -- ints only
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->church_id ] = $r;
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Safety checks before enrolling.
	 * ------------------------------------------------------------------ */

	/**
	 * @return array{blocking:array<string,string>,warnings:array<string,string>}
	 *   blocking: cannot be overridden (no email, already in this campaign).
	 *   warnings: require an explicit manual override.
	 */
	public static function check( Church $church, $campaign_id, $state = null ) {
		global $wpdb;
		$state    = $state ? $state : Outreach_State::get( $church->id );
		$blocking = array();
		$warnings = array();

		$email = $church->outreach_email();
		if ( 'do_not_email' === $church->get( 'contact_preference' ) ) {
			$blocking['do_not_email'] = __( 'Contact preference is “Do not email”.', 'apostolic-spotlight-outreach' );
		} elseif ( ! $email ) {
			$blocking['no_email'] = __( 'No valid pastor or church email.', 'apostolic-spotlight-outreach' );
		}

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT e.campaign_id, e.status FROM ' . self::table() . ' e WHERE e.church_id = %d', $church->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $rows as $r ) {
			if ( (int) $r->campaign_id === (int) $campaign_id ) {
				$blocking['same_campaign'] = __( 'Already enrolled in this campaign.', 'apostolic-spotlight-outreach' );
			} elseif ( in_array( $r->status, array( 'active', 'paused' ), true ) ) {
				$camp                        = Campaigns::find( $r->campaign_id );
				$warnings['other_campaign'] = sprintf(
					/* translators: %s: campaign name */
					__( 'Already active in another campaign (%s).', 'apostolic-spotlight-outreach' ),
					$camp ? $camp->name : '#' . $r->campaign_id
				);
			}
		}

		$recent_days = (int) Settings::get( 'sending.recent_contact_days', 60 );
		if ( $state->last_contacted_at && $recent_days > 0 ) {
			$last = Util::from_db( $state->last_contacted_at );
			if ( $last && $last > Util::now()->modify( "-{$recent_days} days" ) ) {
				$warnings['recent'] = sprintf(
					/* translators: %s: date */
					__( 'Contacted recently (%s).', 'apostolic-spotlight-outreach' ),
					Util::short( $state->last_contacted_at )
				);
			}
		}
		if ( $state->replied_at ) {
			$warnings['replied'] = sprintf(
				/* translators: %s: date */
				__( 'Replied previously (%s).', 'apostolic-spotlight-outreach' ),
				Util::short( $state->replied_at )
			);
		}
		if ( 'not_interested' === $state->spotlight_status ) {
			$warnings['not_interested'] = __( 'Marked Not Interested.', 'apostolic-spotlight-outreach' );
		}
		if ( in_array( $state->spotlight_status, array( 'scheduled', 'completed', 'published' ), true ) ) {
			$warnings['spotlight_done'] = sprintf(
				/* translators: %s: status */
				__( 'Spotlight already %s.', 'apostolic-spotlight-outreach' ),
				strtolower( Statuses::label( 'spotlight', $state->spotlight_status ) )
			);
		}
		if ( in_array( $state->spotlight_status, array( 'replied', 'interested' ), true ) && empty( $warnings['replied'] ) ) {
			$warnings['in_conversation'] = __( 'Already in a conversation.', 'apostolic-spotlight-outreach' );
		}
		if ( ! empty( $state->paused ) ) {
			$warnings['paused'] = __( 'Outreach is paused for this church.', 'apostolic-spotlight-outreach' );
		}

		$info = array();
		if ( '' === trim( $church->get( 'pastor_name' ) ) ) {
			$info['no_pastor'] = __( 'Pastor name missing – emails using {{pastor_name}} will be held for review.', 'apostolic-spotlight-outreach' );
		}

		return array(
			'blocking' => $blocking,
			'warnings' => $warnings,
			'info'     => $info,
			'email'    => $email,
		);
	}

	/**
	 * Enroll churches.
	 *
	 * @param int[]           $church_ids
	 * @param array<int,bool> $overrides church_id => true to override warnings.
	 * @return array{enrolled:int,skipped:int,blocked:int}
	 */
	public static function enroll( $campaign_id, array $church_ids, array $overrides = array(), $note = '' ) {
		global $wpdb;
		$campaign = Campaigns::find( $campaign_id );
		$result   = array( 'enrolled' => 0, 'skipped' => 0, 'blocked' => 0 );
		if ( ! $campaign || 'archived' === $campaign->status ) {
			return $result;
		}
		$start = Util::now();
		if ( $campaign->start_at ) {
			$cs = Util::from_db( $campaign->start_at );
			if ( $cs && $cs > $start ) {
				$start = $cs;
			}
		}

		foreach ( array_unique( array_map( 'intval', $church_ids ) ) as $church_id ) {
			$church = Church_Repository::find( $church_id );
			if ( ! $church ) {
				++$result['blocked'];
				continue;
			}
			$check = self::check( $church, $campaign_id );
			if ( $check['blocking'] ) {
				++$result['blocked'];
				continue;
			}
			$overridden = ! empty( $overrides[ $church_id ] );
			if ( $check['warnings'] && ! $overridden ) {
				++$result['skipped'];
				continue;
			}
			$ok = $wpdb->insert(
				self::table(),
				array(
					'campaign_id'      => (int) $campaign_id,
					'church_id'        => $church_id,
					'status'           => 'active',
					'current_step'     => 0,
					'next_step_due_at' => Util::to_db( $start ),
					'recipient_email'  => $check['email'],
					'override_note'    => $check['warnings'] ? substr( 'Override: ' . implode( ' ', $check['warnings'] ) . ( $note ? ' — ' . $note : '' ), 0, 255 ) : '',
					'enrolled_by'      => get_current_user_id(),
					'enrolled_at'      => Util::now_db(),
				)
			);
			if ( ! $ok ) {
				++$result['blocked'];
				continue;
			}
			++$result['enrolled'];
			Outreach_State::ensure( $church_id );
			if ( $overridden && ! empty( $check['warnings']['paused'] ) ) {
				Outreach_State::update( $church_id, array( 'paused' => 0 ) );
			}
			Activity::log(
				$church_id,
				'enrolled',
				sprintf(
					/* translators: %s: campaign name */
					__( 'Added to campaign “%s”', 'apostolic-spotlight-outreach' ),
					$campaign->name
				) . ( $check['warnings'] ? ' ' . __( '(safety warnings overridden)', 'apostolic-spotlight-outreach' ) : '' ),
				array( 'warnings' => array_keys( $check['warnings'] ) ),
				$campaign_id
			);
			Outreach_State::advance_spotlight_status( $church_id, 'queued' );
		}
		return $result;
	}

	/* ------------------------------------------------------------------
	 * Stopping / pausing automation.
	 * ------------------------------------------------------------------ */

	/**
	 * Stop all open sequences for a church and cancel unsent queue items.
	 *
	 * @param string $reason     Machine reason (replied, status_scheduled, paused, ...).
	 * @param string $new_status stopped|paused|completed
	 * @param int    $only_campaign Limit to a campaign.
	 * @return int Number of enrollments affected.
	 */
	public static function stop_for_church( $church_id, $reason, $new_status = 'stopped', $only_campaign = 0 ) {
		global $wpdb;
		$t     = self::table();
		$where = $wpdb->prepare( "church_id = %d AND status IN ('active','paused','finished')", $church_id );
		if ( $only_campaign ) {
			$where .= $wpdb->prepare( ' AND campaign_id = %d', $only_campaign );
		}
		$ids = $wpdb->get_col( "SELECT id FROM {$t} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', array_map( 'intval', $ids ) );

		// "finished" sequences (all emails sent, awaiting reply) close as stopped, never paused.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET status = IF(status = 'finished' AND %s = 'paused', 'stopped', %s), stop_reason = %s, stopped_at = %s WHERE id IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL
				$new_status,
				$new_status,
				substr( $reason, 0, 64 ),
				Util::now_db()
			)
		);
		$q        = Installer::table( 'queue' );
		$canceled = $wpdb->query( "UPDATE {$q} SET status = 'canceled', updated_at = '" . esc_sql( Util::now_db() ) . "' WHERE enrollment_id IN ({$in}) AND status IN ('pending','scheduled','held')" ); // phpcs:ignore WordPress.DB.PreparedSQL

		if ( 'paused' !== $new_status ) {
			Activity::log(
				$church_id,
				'automation_stopped',
				sprintf(
					/* translators: 1: reason 2: number of canceled emails */
					__( 'Outreach automation stopped (%1$s). %2$d pending email(s) canceled.', 'apostolic-spotlight-outreach' ),
					self::reason_label( $reason ),
					(int) $canceled
				),
				array( 'reason' => $reason, 'enrollments' => $ids )
			);
		}
		return count( $ids );
	}

	/**
	 * Resume enrollments paused via the church "Pause outreach" control.
	 */
	public static function resume_for_church( $church_id ) {
		global $wpdb;
		$t   = self::table();
		$now = Util::now_db();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET status = 'active', stop_reason = '', stopped_at = NULL,
					next_step_due_at = IF(next_step_due_at IS NULL OR next_step_due_at < %s, %s, next_step_due_at)
				WHERE church_id = %d AND status = 'paused'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now,
				$now,
				$church_id
			)
		);
	}

	public static function reason_label( $reason ) {
		$labels = array(
			'replied'                => __( 'church replied', 'apostolic-spotlight-outreach' ),
			'status_replied'         => __( 'marked replied', 'apostolic-spotlight-outreach' ),
			'status_interested'      => __( 'marked interested', 'apostolic-spotlight-outreach' ),
			'status_scheduled'       => __( 'Spotlight scheduled', 'apostolic-spotlight-outreach' ),
			'status_completed'       => __( 'Spotlight completed', 'apostolic-spotlight-outreach' ),
			'status_published'       => __( 'Spotlight published', 'apostolic-spotlight-outreach' ),
			'status_not_interested'  => __( 'marked not interested', 'apostolic-spotlight-outreach' ),
			'paused'                 => __( 'paused manually', 'apostolic-spotlight-outreach' ),
			'bounce'                 => __( 'email bounced', 'apostolic-spotlight-outreach' ),
			'campaign_archived'      => __( 'campaign archived', 'apostolic-spotlight-outreach' ),
			'removed'                => __( 'removed from campaign', 'apostolic-spotlight-outreach' ),
			'send_failed'            => __( 'repeated send failures', 'apostolic-spotlight-outreach' ),
			'sequence_finished'      => __( 'sequence finished', 'apostolic-spotlight-outreach' ),
			'no_response'            => __( 'no response', 'apostolic-spotlight-outreach' ),
		);
		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : str_replace( '_', ' ', $reason );
	}
}
