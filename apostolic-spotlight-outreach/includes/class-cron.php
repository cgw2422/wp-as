<?php
/**
 * WP-Cron wiring.
 *
 * For reliable timing on a low-traffic site, disable traffic-triggered cron and hit
 * wp-cron.php every 5 minutes from a real system cron (see README).
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

defined( 'ABSPATH' ) || exit;

final class Cron {

	const QUEUE_HOOK   = 'aso_cron_queue';
	const LAST_RUN     = 'aso_cron_last_run';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::QUEUE_HOOK, array( __CLASS__, 'run_queue' ) );
		// Self-heal if events were cleared.
		if ( ! wp_next_scheduled( self::QUEUE_HOOK ) ) {
			add_action( 'init', array( __CLASS__, 'schedule' ) );
		}
	}

	public static function schedules( $schedules ) {
		$schedules['aso_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Apostolic Spotlight Outreach)', 'apostolic-spotlight-outreach' ),
		);
		return $schedules;
	}

	public static function schedule() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		if ( ! wp_next_scheduled( self::QUEUE_HOOK ) ) {
			wp_schedule_event( time() + 60, 'aso_five_minutes', self::QUEUE_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::QUEUE_HOOK );
	}

	public static function run_queue() {
		if ( ! self::lock( 'queue' ) ) {
			return;
		}
		try {
			// Reply detection first so a reply that just arrived cancels the next follow-up.
			Reply_Detector::run();
			Queue::run();
			update_option( self::LAST_RUN, time(), false );
		} finally {
			self::unlock( 'queue' );
		}
	}

	private static function lock( $name ) {
		$key = 'aso_lock_' . $name;
		// add_option is atomic (fails if exists); expire stale locks after 10 minutes.
		if ( add_option( $key, time(), '', 'no' ) ) {
			return true;
		}
		$since = (int) get_option( $key );
		if ( $since && $since < time() - 600 ) {
			update_option( $key, time(), false );
			return true;
		}
		return false;
	}

	private static function unlock( $name ) {
		delete_option( 'aso_lock_' . $name );
	}
}
