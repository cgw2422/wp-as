<?php
/**
 * Activation, schema migrations (dbDelta) and default data.
 *
 * All DATETIME columns are stored in UTC.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

defined( 'ABSPATH' ) || exit;

final class Installer {

	const DB_OPTION = 'aso_db_version';

	public static function activate() {
		self::install_schema();
		Capabilities::install();
		Templates::seed_defaults();
		Cron::schedule();
		// Booking route needs a rewrite flush.
		\ApostolicSpotlight\Outreach\Booking\Booking_Frontend::add_rewrite_rules();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		Cron::unschedule();
		flush_rewrite_rules();
	}

	/**
	 * Run migrations when the stored schema version is behind the code.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_OPTION ) !== ASO_DB_VERSION ) {
			self::install_schema();
			Capabilities::install();
			Templates::seed_defaults();
		}
	}

	/**
	 * Table names keyed by short name.
	 *
	 * @return array<string,string>
	 */
	public static function tables() {
		global $wpdb;
		$p = $wpdb->prefix . 'aso_';
		return array(
			'outreach'    => $p . 'church_outreach',
			'campaigns'   => $p . 'campaigns',
			'steps'       => $p . 'campaign_steps',
			'enrollments' => $p . 'enrollments',
			'queue'       => $p . 'queue',
			'email_log'   => $p . 'email_log',
			'activity'    => $p . 'activity',
			'bookings'    => $p . 'bookings',
			'templates'   => $p . 'templates',
		);
	}

	public static function table( $name ) {
		$t = self::tables();
		return $t[ $name ];
	}

	public static function install_schema() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$c = $wpdb->get_charset_collate();
		$t = self::tables();

		// NOTE: dbDelta is picky – two spaces after PRIMARY KEY, one field per line.
		$sql = array();

		// Outreach state for an existing directory church (church_id = directory post ID).
		$sql[] = "CREATE TABLE {$t['outreach']} (
			church_id bigint(20) unsigned NOT NULL,
			spotlight_status varchar(32) NOT NULL DEFAULT 'not_contacted',
			website_status varchar(32) NOT NULL DEFAULT 'unknown',
			grow_status varchar(32) NOT NULL DEFAULT 'unknown',
			website_review varchar(32) NOT NULL DEFAULT '',
			grow_fit varchar(32) NOT NULL DEFAULT '',
			paused tinyint(1) NOT NULL DEFAULT 0,
			needs_review tinyint(1) NOT NULL DEFAULT 0,
			review_reason varchar(190) NOT NULL DEFAULT '',
			booking_token varchar(64) DEFAULT NULL,
			next_action varchar(190) NOT NULL DEFAULT '',
			next_action_at datetime DEFAULT NULL,
			first_contacted_at datetime DEFAULT NULL,
			last_contacted_at datetime DEFAULT NULL,
			replied_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (church_id),
			UNIQUE KEY booking_token (booking_token),
			KEY spotlight_status (spotlight_status),
			KEY website_status (website_status),
			KEY grow_status (grow_status),
			KEY last_contacted_at (last_contacted_at),
			KEY needs_review (needs_review)
		) $c;";

		$sql[] = "CREATE TABLE {$t['campaigns']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			description text NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			start_at datetime DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) $c;";

		// delay_days is relative to the previous step's send (step 1: relative to campaign start/enrollment).
		$sql[] = "CREATE TABLE {$t['steps']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) unsigned NOT NULL,
			step_number smallint(5) unsigned NOT NULL,
			template_id bigint(20) unsigned NOT NULL,
			delay_days smallint(5) unsigned NOT NULL DEFAULT 0,
			reply_in_thread tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY campaign_step (campaign_id,step_number)
		) $c;";

		$sql[] = "CREATE TABLE {$t['enrollments']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) unsigned NOT NULL,
			church_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			current_step smallint(5) unsigned NOT NULL DEFAULT 0,
			next_step_due_at datetime DEFAULT NULL,
			stop_reason varchar(64) NOT NULL DEFAULT '',
			recipient_email varchar(190) NOT NULL DEFAULT '',
			gmail_thread_id varchar(64) NOT NULL DEFAULT '',
			first_message_id varchar(255) NOT NULL DEFAULT '',
			first_subject varchar(255) NOT NULL DEFAULT '',
			override_note varchar(255) NOT NULL DEFAULT '',
			enrolled_by bigint(20) unsigned NOT NULL DEFAULT 0,
			enrolled_at datetime NOT NULL,
			last_sent_at datetime DEFAULT NULL,
			stopped_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY campaign_church (campaign_id,church_id),
			KEY church_id (church_id),
			KEY status_due (status,next_step_due_at),
			KEY gmail_thread_id (gmail_thread_id)
		) $c;";

		// status: pending (needs a slot) | scheduled | sending | sent | failed | canceled | held (needs manual review).
		$sql[] = "CREATE TABLE {$t['queue']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			enrollment_id bigint(20) unsigned NOT NULL,
			campaign_id bigint(20) unsigned NOT NULL,
			church_id bigint(20) unsigned NOT NULL,
			step_id bigint(20) unsigned NOT NULL,
			step_number smallint(5) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			scheduled_at datetime DEFAULT NULL,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			last_error text NULL,
			sent_at datetime DEFAULT NULL,
			email_log_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_scheduled (status,scheduled_at),
			KEY enrollment_id (enrollment_id),
			KEY church_id (church_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t['email_log']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			church_id bigint(20) unsigned DEFAULT NULL,
			campaign_id bigint(20) unsigned DEFAULT NULL,
			enrollment_id bigint(20) unsigned DEFAULT NULL,
			queue_id bigint(20) unsigned DEFAULT NULL,
			template_id bigint(20) unsigned DEFAULT NULL,
			step_number smallint(5) unsigned DEFAULT NULL,
			direction varchar(10) NOT NULL DEFAULT 'out',
			kind varchar(20) NOT NULL DEFAULT 'campaign',
			status varchar(20) NOT NULL DEFAULT 'sent',
			from_email varchar(190) NOT NULL DEFAULT '',
			to_email varchar(190) NOT NULL DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			body longtext NULL,
			gmail_message_id varchar(64) NOT NULL DEFAULT '',
			gmail_thread_id varchar(64) NOT NULL DEFAULT '',
			rfc_message_id varchar(255) NOT NULL DEFAULT '',
			error text NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY church_id (church_id),
			KEY campaign_id (campaign_id),
			KEY created_at (created_at),
			KEY gmail_message_id (gmail_message_id),
			KEY gmail_thread_id (gmail_thread_id),
			KEY to_email (to_email)
		) $c;";

		$sql[] = "CREATE TABLE {$t['activity']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			church_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(40) NOT NULL,
			message text NOT NULL,
			meta longtext NULL,
			campaign_id bigint(20) unsigned DEFAULT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY church_created (church_id,created_at),
			KEY type_created (type,created_at)
		) $c;";

		// status: confirmed | canceled | completed | no_show | failed.
		$sql[] = "CREATE TABLE {$t['bookings']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			public_id varchar(64) NOT NULL,
			church_id bigint(20) unsigned DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'confirmed',
			source varchar(20) NOT NULL DEFAULT 'public',
			pastor_name varchar(190) NOT NULL DEFAULT '',
			church_name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(64) NOT NULL DEFAULT '',
			notes text NULL,
			start_at datetime NOT NULL,
			end_at datetime NOT NULL,
			attendee_timezone varchar(64) NOT NULL DEFAULT '',
			google_event_id varchar(255) NOT NULL DEFAULT '',
			google_calendar_id varchar(255) NOT NULL DEFAULT '',
			meet_url varchar(255) NOT NULL DEFAULT '',
			event_html_link text NULL,
			error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			KEY church_id (church_id),
			KEY status_start (status,start_at)
		) $c;";

		// category: invitation | follow_up | website | grow | general.
		$sql[] = "CREATE TABLE {$t['templates']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(64) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL,
			category varchar(20) NOT NULL DEFAULT 'general',
			subject varchar(255) NOT NULL DEFAULT '',
			body longtext NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY category (category),
			KEY slug (slug)
		) $c;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::DB_OPTION, ASO_DB_VERSION );
	}
}
