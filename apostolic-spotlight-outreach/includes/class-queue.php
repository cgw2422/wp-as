<?php
/**
 * Low-volume send queue.
 *
 * Three stages, all run from WP-Cron every few minutes (Cron::tick):
 *
 * 1. materialize() – each ACTIVE enrollment whose next step is due gets one queue row (status "pending").
 * 2. plan()        – pending rows receive a send time (status "scheduled") inside the configured
 *                    sending window, honoring the daily limit, allowed weekdays, and minimum spacing.
 *                    Sends are spread across the window (evenly, or evenly with random jitter), and
 *                    overflow rolls to the next allowed day. Follow-ups are planned before new invitations.
 * 3. process()     – sends due rows one at a time via Gmail after re-checking that the church has not
 *                    replied, booked, been paused or marked not interested in the meantime.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Church\Outreach_State;
use ApostolicSpotlight\Outreach\Google\Google_Oauth;

defined( 'ABSPATH' ) || exit;

final class Queue {

	const MAX_ATTEMPTS = 3;
	const PLAN_DAYS    = 60;

	private static function table() {
		return Installer::table( 'queue' );
	}

	public static function run() {
		self::recover_stuck();
		self::sweep_no_response();
		self::materialize();
		self::plan();
		self::process();
	}

	/* ------------------------------------------------------------------
	 * 1. Materialize due steps.
	 * ------------------------------------------------------------------ */

	public static function materialize() {
		global $wpdb;
		$e   = Installer::table( 'enrollments' );
		$c   = Installer::table( 'campaigns' );
		$q   = self::table();
		$now = Util::now_db();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.* FROM {$e} e
				INNER JOIN {$c} c ON c.id = e.campaign_id AND c.status = 'active'
				WHERE e.status = 'active' AND e.next_step_due_at IS NOT NULL AND e.next_step_due_at <= %s
				AND NOT EXISTS ( SELECT 1 FROM {$q} q WHERE q.enrollment_id = e.id AND q.status IN ('pending','scheduled','sending','held') )
				ORDER BY e.next_step_due_at ASC LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now
			)
		);
		foreach ( $rows as $en ) {
			$step = Campaigns::step( $en->campaign_id, (int) $en->current_step + 1 );
			if ( ! $step ) {
				self::finish_enrollment( $en );
				continue;
			}
			$wpdb->insert(
				$q,
				array(
					'enrollment_id' => $en->id,
					'campaign_id'   => $en->campaign_id,
					'church_id'     => $en->church_id,
					'step_id'       => $step->id,
					'step_number'   => $step->step_number,
					'status'        => 'pending',
					'created_at'    => $now,
					'updated_at'    => $now,
				)
			);
		}
	}

	private static function finish_enrollment( $en ) {
		global $wpdb;
		$days = max( 1, (int) Settings::get( 'sending.no_response_days', 7 ) );
		$wpdb->update(
			Installer::table( 'enrollments' ),
			array(
				'status'           => 'finished',
				'next_step_due_at' => Util::to_db( Util::now()->modify( "+{$days} days" ) ),
			),
			array( 'id' => $en->id )
		);
	}

	/**
	 * Sequences that finished with no reply after N days → "No Response".
	 */
	public static function sweep_no_response() {
		global $wpdb;
		$e    = Installer::table( 'enrollments' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$e} WHERE status = 'finished' AND next_step_due_at <= %s LIMIT 200", Util::now_db() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $en ) {
			$wpdb->update( $e, array( 'status' => 'completed', 'stop_reason' => 'no_response', 'stopped_at' => Util::now_db() ), array( 'id' => $en->id ) );
			$state = Outreach_State::get( $en->church_id );
			if ( in_array( $state->spotlight_status, array( 'queued', 'initial_sent', 'followup1_sent', 'followup2_sent' ), true ) ) {
				Outreach_State::set_spotlight_status( $en->church_id, 'no_response', 'system', __( 'sequence finished without a reply', 'apostolic-spotlight-outreach' ) );
			}
		}
	}

	/* ------------------------------------------------------------------
	 * 2. Plan send slots.
	 * ------------------------------------------------------------------ */

	public static function plan() {
		global $wpdb;
		$q = self::table();
		$c = Installer::table( 'campaigns' );

		$pending = $wpdb->get_results(
			"SELECT q.id FROM {$q} q INNER JOIN {$c} c ON c.id = q.campaign_id AND c.status = 'active'
			WHERE q.status = 'pending' ORDER BY (q.step_number > 1) DESC, q.created_at ASC, q.id ASC LIMIT 2000" // phpcs:ignore WordPress.DB.PreparedSQL
		);
		if ( ! $pending ) {
			return 0;
		}
		$ids = wp_list_pluck( $pending, 'id' );

		$tz       = Settings::sending_timezone();
		$limit    = self::daily_limit();
		$days     = array_map( 'intval', (array) Settings::get( 'sending.days', array( 1, 2, 3, 4, 5 ) ) );
		$start_m  = Util::hm_to_minutes( Settings::get( 'sending.start', '09:00' ) );
		$end_m    = Util::hm_to_minutes( Settings::get( 'sending.end', '16:00' ) );
		$spacing  = max( 1, (int) Settings::get( 'sending.min_spacing', 8 ) ) * 60;
		$jitter   = 'random' === Settings::get( 'sending.distribution', 'random' );
		$now      = Util::now();
		$assigned = 0;

		if ( ! $days || null === $start_m || null === $end_m || $end_m <= $start_m || $limit < 1 ) {
			return 0;
		}

		$today = $now->setTimezone( $tz )->setTime( 0, 0 );
		for ( $d = 0; $d < self::PLAN_DAYS && $ids; $d++ ) {
			$date = $today->modify( "+{$d} days" );
			if ( ! in_array( (int) $date->format( 'N' ), $days, true ) ) {
				continue;
			}
			$ws = $date->setTime( intdiv( $start_m, 60 ), $start_m % 60 )->getTimestamp();
			$we = $date->setTime( intdiv( $end_m, 60 ), $end_m % 60 )->getTimestamp();
			$es = 0 === $d ? max( $ws, $now->getTimestamp() + 120 ) : $ws;
			if ( $es >= $we - 60 ) {
				continue;
			}
			$day_start = Util::to_db( $date );
			$day_end   = Util::to_db( $date->modify( '+1 day' ) );
			$taken     = self::taken_times( $day_start, $day_end );
			$capacity  = $limit - count( $taken );
			if ( $capacity <= 0 ) {
				continue;
			}
			$need  = min( $capacity, count( $ids ) );
			$slots = self::slots( $es, $we, $need, $taken, $spacing, $jitter );
			foreach ( $slots as $ts ) {
				$id = array_shift( $ids );
				$wpdb->update(
					$q,
					array(
						'status'       => 'scheduled',
						'scheduled_at' => gmdate( Util::DB_FORMAT, $ts ),
						'updated_at'   => Util::now_db(),
					),
					array( 'id' => $id, 'status' => 'pending' )
				);
				++$assigned;
			}
		}
		return $assigned;
	}

	public static function daily_limit() {
		return max( 0, min( 100, (int) Settings::get( 'sending.daily_limit', 25 ) ) );
	}

	/**
	 * Unix timestamps of campaign sends already used/reserved within a local day.
	 */
	private static function taken_times( $day_start_db, $day_end_db ) {
		global $wpdb;
		$q    = self::table();
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT COALESCE(sent_at, scheduled_at) FROM {$q}
				WHERE ( status IN ('scheduled','sending') AND scheduled_at >= %s AND scheduled_at < %s )
				   OR ( status IN ('sent','failed') AND sent_at >= %s AND sent_at < %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$day_start_db,
				$day_end_db,
				$day_start_db,
				$day_end_db
			)
		);
		return array_map(
			static function ( $v ) {
				return Util::from_db( $v )->getTimestamp();
			},
			array_filter( (array) $rows )
		);
	}

	/**
	 * Choose up to $need timestamps in [$start,$end) spread across the window and at least
	 * $spacing seconds from each other and from $taken.
	 *
	 * @return int[]
	 */
	public static function slots( $start, $end, $need, array $taken, $spacing, $jitter ) {
		$len = $end - $start;
		if ( $need < 1 || $len <= 0 ) {
			return array();
		}
		$out = array();
		for ( $i = 0; $i < $need; $i++ ) {
			$frac = $jitter ? ( $i + 0.15 + ( wp_rand( 0, 700 ) / 1000 ) ) : ( $i + 0.5 );
			$t    = (int) ( $start + $len * $frac / $need );
			// Nudge forward until clear of other sends.
			$guard = 0;
			while ( $t < $end && self::conflicts( $t, array_merge( $taken, $out ), $spacing ) && $guard++ < 500 ) {
				$t += 60;
			}
			if ( $t < $end && ! self::conflicts( $t, array_merge( $taken, $out ), $spacing ) ) {
				$out[] = $t;
			}
		}
		sort( $out );
		return $out;
	}

	private static function conflicts( $t, array $times, $spacing ) {
		foreach ( $times as $u ) {
			if ( abs( $t - $u ) < $spacing ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Release scheduled-but-unsent slots and re-plan (after changing sending settings).
	 */
	public static function rebalance() {
		global $wpdb;
		$q = self::table();
		$wpdb->query( "UPDATE {$q} SET status = 'pending', scheduled_at = NULL WHERE status = 'scheduled'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		return self::plan();
	}

	/* ------------------------------------------------------------------
	 * 3. Process due sends.
	 * ------------------------------------------------------------------ */

	public static function within_window( \DateTimeImmutable $now = null ) {
		$now   = $now ? $now : Util::now();
		$local = $now->setTimezone( Settings::sending_timezone() );
		$days  = array_map( 'intval', (array) Settings::get( 'sending.days', array() ) );
		if ( ! in_array( (int) $local->format( 'N' ), $days, true ) ) {
			return false;
		}
		$m  = (int) $local->format( 'G' ) * 60 + (int) $local->format( 'i' );
		$sm = Util::hm_to_minutes( Settings::get( 'sending.start' ) );
		$em = Util::hm_to_minutes( Settings::get( 'sending.end' ) );
		return null !== $sm && null !== $em && $m >= $sm && $m < $em;
	}

	public static function sent_today() {
		global $wpdb;
		$q     = self::table();
		$tz    = Settings::sending_timezone();
		$start = ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0 );
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$q} WHERE status IN ('sent','sending','failed') AND sent_at >= %s", Util::to_db( $start ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public static function process( $max = 5 ) {
		global $wpdb;
		if ( Settings::get( 'sending.paused' ) || ! Google_Oauth::is_connected() ) {
			return 0;
		}
		$q   = self::table();
		$now = Util::now();

		// Scheduled for an earlier local day (e.g. cron was down) → re-plan rather than send late.
		$today_start = Util::to_db( $now->setTimezone( Settings::sending_timezone() )->setTime( 0, 0 ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$q} SET status = 'pending', scheduled_at = NULL WHERE status = 'scheduled' AND scheduled_at < %s", $today_start ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! self::within_window( $now ) ) {
			return 0;
		}

		$spacing = max( 1, (int) Settings::get( 'sending.min_spacing', 8 ) ) * 60;
		$sent    = 0;
		for ( $i = 0; $i < $max; $i++ ) {
			if ( self::sent_today() >= self::daily_limit() ) {
				break;
			}
			$last = $wpdb->get_var( "SELECT MAX(sent_at) FROM {$q} WHERE status IN ('sent','sending')" ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $last && ( Util::now()->getTimestamp() - Util::from_db( $last )->getTimestamp() ) < $spacing ) {
				break;
			}
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$q} WHERE status = 'scheduled' AND scheduled_at <= %s ORDER BY scheduled_at ASC, id ASC LIMIT 1", Util::now_db() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $id ) {
				break;
			}
			// Atomic claim so overlapping cron runs never double-send.
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$q} SET status = 'sending', sent_at = %s, updated_at = %s WHERE id = %d AND status = 'scheduled'", Util::now_db(), Util::now_db(), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( 1 !== (int) $claimed ) {
				continue;
			}
			$outcome = self::send_item( $id );
			if ( 'sent' === $outcome ) {
				++$sent;
			} elseif ( 'auth' === $outcome ) {
				break;
			}
		}
		return $sent;
	}

	/**
	 * Send a claimed queue row.
	 *
	 * @return string sent|canceled|held|failed|retry|auth|deferred
	 */
	public static function send_item( $id ) {
		global $wpdb;
		$q    = self::table();
		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$q} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $item ) {
			return 'canceled';
		}
		$en       = Enrollments::find( $item->enrollment_id );
		$campaign = Campaigns::find( $item->campaign_id );
		$church   = Church_Repository::find( $item->church_id );
		$state    = Outreach_State::get( $item->church_id );

		if ( ! $church || ! $en || 'active' !== $en->status ) {
			self::set_status( $id, 'canceled', __( 'Enrollment no longer active.', 'apostolic-spotlight-outreach' ) );
			return 'canceled';
		}
		if ( ! $campaign || 'active' !== $campaign->status ) {
			self::set_status( $id, 'pending', '', true );
			return 'deferred';
		}
		if ( ! empty( $state->paused ) || in_array( $state->spotlight_status, Statuses::stop_statuses(), true ) ) {
			self::set_status( $id, 'canceled', __( 'Church replied, booked, or was paused before sending.', 'apostolic-spotlight-outreach' ) );
			Enrollments::stop_for_church( $church->id, ! empty( $state->paused ) ? 'paused' : 'status_' . $state->spotlight_status, ! empty( $state->paused ) ? 'paused' : 'stopped' );
			return 'canceled';
		}

		$step     = Campaigns::step( $item->campaign_id, $item->step_number );
		$template = $step ? Templates::find( $step->template_id ) : null;
		if ( ! $template ) {
			return self::hold( $item, __( 'Template missing for this step.', 'apostolic-spotlight-outreach' ) );
		}
		$to = $church->outreach_email();
		if ( ! $to ) {
			return self::hold( $item, __( 'No valid email address (or contact preference is “Do not email”).', 'apostolic-spotlight-outreach' ) );
		}
		$rendered = Template_Renderer::render_template( $template, $church );
		if ( $rendered['missing'] ) {
			return self::hold(
				$item,
				/* translators: %s: variable names */
				sprintf( __( 'Missing information for: %s', 'apostolic-spotlight-outreach' ), implode( ', ', $rendered['missing'] ) )
			);
		}

		$subject   = $rendered['subject'];
		$thread_id = '';
		$reply_to  = '';
		if ( (int) $item->step_number > 1 && ! empty( $step->reply_in_thread ) && $en->gmail_thread_id ) {
			$thread_id = $en->gmail_thread_id;
			$reply_to  = $en->first_message_id;
			if ( $en->first_subject ) {
				$subject = preg_match( '/^re:/i', $en->first_subject ) ? $en->first_subject : 'Re: ' . $en->first_subject;
			}
		}

		$result = Mailer::send(
			$church,
			$to,
			$subject,
			$rendered['body'],
			array(
				'kind'          => 'campaign',
				'campaign_id'   => $item->campaign_id,
				'enrollment_id' => $item->enrollment_id,
				'queue_id'      => $item->id,
				'template_id'   => $template->id,
				'step_number'   => $item->step_number,
				'thread_id'     => $thread_id,
				'in_reply_to'   => $reply_to,
			)
		);

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			if ( in_array( $code, array( 'aso_not_connected', 'invalid_grant', 'aso_google_http_401', 'aso_google_http_403' ), true ) ) {
				self::set_status( $id, 'scheduled', $result->get_error_message() );
				return 'auth';
			}
			$attempts = (int) $item->attempts + 1;
			if ( $attempts >= self::MAX_ATTEMPTS ) {
				$wpdb->update( $q, array( 'status' => 'failed', 'attempts' => $attempts, 'last_error' => $result->get_error_message(), 'updated_at' => Util::now_db() ), array( 'id' => $id ) );
				Outreach_State::flag_review( $church->id, 'send_failed' );
				return 'failed';
			}
			$wpdb->update( $q, array( 'status' => 'pending', 'scheduled_at' => null, 'sent_at' => null, 'attempts' => $attempts, 'last_error' => $result->get_error_message(), 'updated_at' => Util::now_db() ), array( 'id' => $id ) );
			return 'retry';
		}

		$now = Util::now_db();
		$wpdb->update( $q, array( 'status' => 'sent', 'sent_at' => $now, 'email_log_id' => $result['log_id'], 'last_error' => null, 'updated_at' => $now ), array( 'id' => $id ) );

		$update = array(
			'current_step' => (int) $item->step_number,
			'last_sent_at' => $now,
		);
		if ( 1 === (int) $item->step_number || ! $en->gmail_thread_id ) {
			$update['gmail_thread_id']  = $result['thread_id'];
			$update['first_message_id'] = $result['rfc_message_id'];
			$update['first_subject']    = $subject;
		}
		$next = Campaigns::step( $item->campaign_id, (int) $item->step_number + 1 );
		if ( $next ) {
			$update['next_step_due_at'] = Util::to_db( Util::now()->modify( '+' . (int) $next->delay_days . ' days' ) );
		} else {
			$days                       = max( 1, (int) Settings::get( 'sending.no_response_days', 7 ) );
			$update['status']           = 'finished';
			$update['next_step_due_at'] = Util::to_db( Util::now()->modify( "+{$days} days" ) );
		}
		$wpdb->update( Installer::table( 'enrollments' ), $update, array( 'id' => $en->id ) );

		$status_for_step = array( 1 => 'initial_sent', 2 => 'followup1_sent' );
		Outreach_State::advance_spotlight_status( $church->id, isset( $status_for_step[ (int) $item->step_number ] ) ? $status_for_step[ (int) $item->step_number ] : 'followup2_sent' );
		return 'sent';
	}

	private static function hold( $item, $reason ) {
		self::set_status( $item->id, 'held', $reason );
		Outreach_State::flag_review( $item->church_id, 'held' );
		Activity::log( $item->church_id, 'email_held', $reason, array( 'queue_id' => $item->id ), $item->campaign_id );
		return 'held';
	}

	private static function set_status( $id, $status, $error = '', $clear_schedule = false ) {
		global $wpdb;
		$data = array( 'status' => $status, 'updated_at' => Util::now_db() );
		if ( '' !== $error ) {
			$data['last_error'] = $error;
		}
		if ( $clear_schedule ) {
			$data['scheduled_at'] = null;
		}
		if ( in_array( $status, array( 'pending', 'scheduled', 'held', 'canceled' ), true ) ) {
			$data['sent_at'] = null;
		}
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * A row stuck in "sending" (PHP died mid-request) – we cannot know whether Gmail
	 * accepted it, so flag for manual review instead of risking a duplicate email.
	 */
	public static function recover_stuck() {
		global $wpdb;
		$q    = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$q} WHERE status = 'sending' AND updated_at < %s", Util::to_db( Util::now()->modify( '-15 minutes' ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			self::hold( $r, __( 'Send was interrupted. Check your Gmail Sent folder, then retry or cancel.', 'apostolic-spotlight-outreach' ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Admin operations.
	 * ------------------------------------------------------------------ */

	public static function retry( $id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => 'pending', 'scheduled_at' => null, 'sent_at' => null, 'attempts' => 0, 'updated_at' => Util::now_db() ), array( 'id' => (int) $id ) );
		self::plan();
	}

	public static function cancel( $id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => 'canceled', 'updated_at' => Util::now_db() ), array( 'id' => (int) $id ) );
	}

	/**
	 * Send now (manual) – bypasses the window but still honors safety checks.
	 */
	public static function send_now( $id ) {
		global $wpdb;
		$q       = self::table();
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$q} SET status = 'sending', sent_at = %s, updated_at = %s WHERE id = %d AND status IN ('pending','scheduled','held')", Util::now_db(), Util::now_db(), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== (int) $claimed ) {
			return 'canceled';
		}
		return self::send_item( $id );
	}

	public static function counts() {
		global $wpdb;
		$q    = self::table();
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$q} GROUP BY status", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( Statuses::queue() as $k => $v ) {
			$out[ $k ] = isset( $rows[ $k ] ) ? (int) $rows[ $k ]->n : 0;
		}
		return $out;
	}
}
