<?php
/**
 * Dashboard metrics & task lists (all aggregate SQL – stays fast with thousands of churches).
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

use ApostolicSpotlight\Outreach\Booking\Bookings;

defined( 'ABSPATH' ) || exit;

final class Metrics {

	public static function summary() {
		global $wpdb;
		$o = Installer::table( 'outreach' );

		$counts = $wpdb->get_row(
			"SELECT
				SUM(replied_at IS NOT NULL) AS replied,
				SUM(spotlight_status = 'scheduled') AS scheduled,
				SUM(spotlight_status IN ('completed','published')) AS completed,
				SUM(spotlight_status = 'published') AS published,
				SUM(website_status IN ('potential','interested','proposal')) AS website_leads,
				SUM(website_status = 'won') AS website_customers,
				SUM(grow_status IN ('potential','interested','demo_scheduled')) AS grow_leads,
				SUM(grow_status = 'customer') AS grow_customers,
				SUM(needs_review = 1) AS needs_review
			FROM {$o}", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$counts = array_map( 'intval', (array) $counts );

		$week  = Util::period_start( 'week' );
		$month = Util::period_start( 'month' );

		$counts['contacted_week']  = Email_Log::count_churches_contacted_since( $week );
		$counts['contacted_month'] = Email_Log::count_churches_contacted_since( $month );
		$counts['emails_month']    = Email_Log::count_sent_since( $month );
		$counts['emails_total']    = Email_Log::count_sent_since( '1970-01-01 00:00:00' );
		$counts['replied_month']   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$o} WHERE replied_at >= %s", $month ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts['upcoming']        = Bookings::count( 'upcoming' );
		return $counts;
	}

	/**
	 * Today's tasks.
	 */
	public static function tasks() {
		global $wpdb;
		$q  = Installer::table( 'queue' );
		$e  = Installer::table( 'enrollments' );
		$o  = Installer::table( 'outreach' );
		$tz = Settings::sending_timezone();

		$day_start = Util::to_db( ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0 ) );
		$day_end   = Util::to_db( ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 23, 59, 59 ) );

		return array(
			'scheduled_today' => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$q} WHERE status IN ('scheduled','sending') AND scheduled_at BETWEEN %s AND %s ORDER BY scheduled_at ASC LIMIT 50", $day_start, $day_end ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'sent_today'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$q} WHERE status = 'sent' AND sent_at BETWEEN %s AND %s", $day_start, $day_end ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'followups_due'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$e} WHERE status = 'active' AND current_step >= 1 AND next_step_due_at <= %s", $day_end ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'interviews'      => Bookings::query( 'today', 20 ),
			'past_unmarked'   => Bookings::query( 'past', 20 ),
			'unlinked'        => Bookings::query( 'unlinked', 20 ),
			'needs_review'    => $wpdb->get_results( "SELECT * FROM {$o} WHERE needs_review = 1 ORDER BY updated_at DESC LIMIT 15" ), // phpcs:ignore WordPress.DB.PreparedSQL
			'recent_replies'  => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$o} WHERE replied_at >= %s ORDER BY replied_at DESC LIMIT 10", Util::to_db( Util::now()->modify( '-14 days' ) ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'problems'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$q} WHERE status IN ('held','failed')" ), // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}
}
