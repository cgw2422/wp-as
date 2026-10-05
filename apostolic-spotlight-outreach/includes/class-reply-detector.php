<?php
/**
 * Optional automatic reply detection (requires the gmail.metadata scope).
 *
 * gmail.metadata exposes message headers and labels only – never bodies or attachments.
 * Detection strategy (every cron tick, ~5 minutes, before the queue sends):
 *
 *   1. Incremental sync with users.history.list (messageAdded, INBOX) from the last stored
 *      historyId. Each new inbox message's headers are fetched (format=metadata) and matched:
 *        - its threadId equals a thread we started from an enrollment, OR
 *        - its From address equals an address we have emailed (email log)
 *   2. If the stored historyId has expired (Gmail keeps ~1 week), fall back to checking each
 *      open enrollment's Gmail thread for a message not sent by us, then reset the cursor.
 *
 * Classification: bounces (mailer-daemon/postmaster) stop the sequence and flag the address;
 * auto-replies (Auto-Submitted / X-Autoreply / "Out of office") are logged and flagged for review
 * but do NOT stop the sequence; anything else is a genuine reply → Mark Replied (stops automation).
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

use ApostolicSpotlight\Outreach\Church\Outreach_State;
use ApostolicSpotlight\Outreach\Google\Gmail;
use ApostolicSpotlight\Outreach\Google\Google_Oauth;

defined( 'ABSPATH' ) || exit;

final class Reply_Detector {

	const CURSOR_OPTION = 'aso_gmail_history_id';
	const STATUS_OPTION = 'aso_reply_detector_status';

	public static function is_available() {
		return Google_Oauth::is_connected() && Google_Oauth::has_scope( Google_Oauth::SCOPE_METADATA );
	}

	public static function is_enabled() {
		return (bool) Settings::get( 'replies.enabled' ) && self::is_available();
	}

	public static function reset_cursor() {
		delete_option( self::CURSOR_OPTION );
	}

	public static function run() {
		if ( ! self::is_enabled() ) {
			return;
		}
		$cursor = get_option( self::CURSOR_OPTION, '' );
		$found  = 0;
		if ( ! $cursor ) {
			$found += self::check_threads();
			self::store_cursor_from_profile();
		} else {
			$found += self::sync_history( $cursor );
		}
		update_option( self::STATUS_OPTION, array( 'last_run' => time(), 'found' => $found ), false );
	}

	private static function store_cursor_from_profile() {
		$profile = Gmail::profile();
		if ( ! is_wp_error( $profile ) && ! empty( $profile['historyId'] ) ) {
			update_option( self::CURSOR_OPTION, (string) $profile['historyId'], false );
		}
	}

	private static function sync_history( $cursor ) {
		$found      = 0;
		$page_token = '';
		$latest     = $cursor;
		$pages      = 0;
		do {
			$res = Gmail::history( $cursor, $page_token );
			if ( is_wp_error( $res ) ) {
				if ( 404 === (int) ( $res->get_error_data()['status'] ?? 0 ) ) {
					// History expired – fall back to per-thread checks and restart the cursor.
					$found += self::check_threads();
					self::store_cursor_from_profile();
				} else {
					update_option( self::STATUS_OPTION, array( 'last_run' => time(), 'error' => $res->get_error_message() ), false );
				}
				return $found;
			}
			if ( ! empty( $res['historyId'] ) ) {
				$latest = (string) $res['historyId'];
			}
			foreach ( (array) ( $res['history'] ?? array() ) as $h ) {
				foreach ( (array) ( $h['messagesAdded'] ?? array() ) as $added ) {
					$msg = $added['message'] ?? array();
					if ( empty( $msg['id'] ) || Email_Log::seen_message( $msg['id'] ) ) {
						continue;
					}
					if ( in_array( 'SENT', (array) ( $msg['labelIds'] ?? array() ), true ) ) {
						continue;
					}
					$full = Gmail::message_metadata( $msg['id'] );
					if ( ! is_wp_error( $full ) && self::handle_message( $full ) ) {
						++$found;
					}
				}
			}
			$page_token = $res['nextPageToken'] ?? '';
		} while ( $page_token && ++$pages < 10 );

		update_option( self::CURSOR_OPTION, $latest, false );
		return $found;
	}

	/**
	 * Fallback: inspect each open enrollment thread.
	 */
	public static function check_threads() {
		global $wpdb;
		$e     = Installer::table( 'enrollments' );
		$rows  = $wpdb->get_results( "SELECT DISTINCT gmail_thread_id FROM {$e} WHERE status IN ('active','finished') AND gmail_thread_id <> '' LIMIT 300" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$found = 0;
		foreach ( $rows as $r ) {
			$thread = Gmail::thread_metadata( $r->gmail_thread_id );
			if ( is_wp_error( $thread ) ) {
				continue;
			}
			foreach ( (array) ( $thread['messages'] ?? array() ) as $msg ) {
				if ( in_array( 'SENT', (array) ( $msg['labelIds'] ?? array() ), true ) || Email_Log::seen_message( $msg['id'] ) ) {
					continue;
				}
				if ( self::handle_message( $msg ) ) {
					++$found;
				}
			}
		}
		return $found;
	}

	/**
	 * Match a Gmail message (metadata format) to a church and act on it.
	 *
	 * @return bool True when it matched a church.
	 */
	public static function handle_message( array $msg ) {
		global $wpdb;
		$from      = Gmail::parse_email( Gmail::header( $msg, 'From' ) );
		$own       = strtolower( Google_Oauth::email() );
		$thread_id = (string) ( $msg['threadId'] ?? '' );
		if ( ! $from || $from === $own ) {
			return false;
		}

		$church_id     = 0;
		$campaign_id   = null;
		$enrollment_id = null;
		if ( $thread_id ) {
			$e   = Installer::table( 'enrollments' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, church_id, campaign_id FROM {$e} WHERE gmail_thread_id = %s ORDER BY id DESC LIMIT 1", $thread_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $row ) {
				$church_id     = (int) $row->church_id;
				$campaign_id   = (int) $row->campaign_id;
				$enrollment_id = (int) $row->id;
			}
			if ( ! $church_id ) {
				$l         = Installer::table( 'email_log' );
				$church_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT church_id FROM {$l} WHERE gmail_thread_id = %s AND direction = 'out' ORDER BY id DESC LIMIT 1", $thread_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		$is_bounce = (bool) preg_match( '/^(mailer-daemon|postmaster)@/i', $from );
		if ( ! $church_id && ! $is_bounce ) {
			$l         = Installer::table( 'email_log' );
			$church_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT church_id FROM {$l} WHERE to_email = %s AND direction = 'out' AND status = 'sent' ORDER BY id DESC LIMIT 1", $from ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( ! $church_id ) {
			return false;
		}

		$subject = Gmail::header( $msg, 'Subject' );
		Email_Log::insert(
			array(
				'church_id'        => $church_id,
				'campaign_id'      => $campaign_id,
				'enrollment_id'    => $enrollment_id,
				'direction'        => 'in',
				'kind'             => $is_bounce ? 'bounce' : 'reply',
				'status'           => 'received',
				'from_email'       => $from,
				'to_email'         => $own,
				'subject'          => $subject,
				'gmail_message_id' => (string) $msg['id'],
				'gmail_thread_id'  => $thread_id,
				'user_id'          => 0,
			)
		);

		if ( $is_bounce ) {
			Activity::log( $church_id, 'bounce', sprintf( /* translators: %s: subject */ __( 'Delivery failure notice: “%s”', 'apostolic-spotlight-outreach' ), $subject ), array( 'gmail_message_id' => $msg['id'] ), $campaign_id );
			Enrollments::stop_for_church( $church_id, 'bounce' );
			Outreach_State::flag_review( $church_id, 'bounce' );
			return true;
		}
		if ( self::is_auto_reply( $msg ) ) {
			Activity::log( $church_id, 'auto_reply', sprintf( /* translators: 1: sender 2: subject */ __( 'Auto-reply from %1$s: “%2$s” (sequence continues)', 'apostolic-spotlight-outreach' ), $from, $subject ), array( 'gmail_message_id' => $msg['id'] ), $campaign_id );
			Outreach_State::flag_review( $church_id, 'auto_reply' );
			return true;
		}

		Activity::log( $church_id, 'reply', sprintf( /* translators: 1: sender 2: subject */ __( 'Reply detected from %1$s: “%2$s”', 'apostolic-spotlight-outreach' ), $from, $subject ), array( 'gmail_message_id' => $msg['id'], 'thread_id' => $thread_id ), $campaign_id );
		Outreach_State::mark_replied( $church_id, 'reply', __( 'detected in Gmail', 'apostolic-spotlight-outreach' ) );
		return true;
	}

	public static function is_auto_reply( array $msg ) {
		$auto = strtolower( Gmail::header( $msg, 'Auto-Submitted' ) );
		if ( $auto && 'no' !== $auto ) {
			return true;
		}
		if ( Gmail::header( $msg, 'X-Autoreply' ) || Gmail::header( $msg, 'X-Autorespond' ) ) {
			return true;
		}
		if ( in_array( strtolower( Gmail::header( $msg, 'Precedence' ) ), array( 'auto_reply', 'bulk', 'junk' ), true ) ) {
			return true;
		}
		return (bool) preg_match( '/^(automatic reply|auto(-|\s)?reply|out of (the )?office|away from (the )?office|autoreply)/i', Gmail::header( $msg, 'Subject' ) );
	}
}
