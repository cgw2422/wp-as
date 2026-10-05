<?php
/**
 * admin-post.php handlers. Every handler verifies a nonce and a capability,
 * sanitizes input, and redirects back with a flash notice.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach\Admin;

use ApostolicSpotlight\Outreach\Activity;
use ApostolicSpotlight\Outreach\Booking\Availability;
use ApostolicSpotlight\Outreach\Booking\Bookings;
use ApostolicSpotlight\Outreach\Booking\Booking_Frontend;
use ApostolicSpotlight\Outreach\Campaigns;
use ApostolicSpotlight\Outreach\Capabilities;
use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Church\Field_Map;
use ApostolicSpotlight\Outreach\Church\Outreach_State;
use ApostolicSpotlight\Outreach\Enrollments;
use ApostolicSpotlight\Outreach\Google\Google_Oauth;
use ApostolicSpotlight\Outreach\Mailer;
use ApostolicSpotlight\Outreach\Queue;
use ApostolicSpotlight\Outreach\Settings;
use ApostolicSpotlight\Outreach\Statuses;
use ApostolicSpotlight\Outreach\Templates;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

final class Admin_Actions {

	public static function init() {
		$map = array(
			'aso_church_bulk'      => 'church_bulk',
			'aso_enroll_confirm'   => 'enroll_confirm',
			'aso_church_save'      => 'church_save',
			'aso_church_action'    => 'church_action',
			'aso_add_note'         => 'add_note',
			'aso_send_email'       => 'send_email',
			'aso_campaign_save'    => 'campaign_save',
			'aso_campaign_status'  => 'campaign_status',
			'aso_enrollment_remove'=> 'enrollment_remove',
			'aso_template_save'    => 'template_save',
			'aso_template_delete'  => 'template_delete',
			'aso_queue_action'     => 'queue_action',
			'aso_booking_action'   => 'booking_action',
			'aso_opportunity_save' => 'opportunity_save',
			'aso_settings_save'    => 'settings_save',
		);
		foreach ( $map as $action => $method ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, $method ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Helpers.
	 * ------------------------------------------------------------------ */

	private static function guard( $action, $cap = Capabilities::OUTREACH ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'apostolic-spotlight-outreach' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( $fallback, $message = '', $type = 'success' ) {
		if ( $message ) {
			Admin::flash( $message, $type );
		}
		$ref = wp_get_referer();
		wp_safe_redirect( $ref ? $ref : $fallback );
		exit;
	}

	private static function go( $url, $message = '', $type = 'success' ) {
		if ( $message ) {
			Admin::flash( $message, $type );
		}
		wp_safe_redirect( $url );
		exit;
	}

	private static function post( $key, $default = '' ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
	}

	private static function req( $key, $default = '' ) {
		return isset( $_REQUEST[ $key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification
	}

	private static function ids( $key ) {
		$raw = isset( $_POST[ $key ] ) ? (array) wp_unslash( $_POST[ $key ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
		return array_values( array_filter( array_map( 'absint', $raw ) ) );
	}

	/**
	 * Local "Y-m-d\TH:i" (datetime-local input) in a timezone → UTC DB string.
	 */
	private static function local_to_db( $value, \DateTimeZone $tz ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		try {
			$dt = new \DateTimeImmutable( $value, $tz );
		} catch ( \Exception $e ) {
			return null;
		}
		return Util::to_db( $dt );
	}

	/* ------------------------------------------------------------------
	 * Churches.
	 * ------------------------------------------------------------------ */

	public static function church_bulk() {
		self::guard( 'bulk-churches' ); // Nonce printed by WP_List_Table.
		$action = sanitize_key( self::post( 'bulk_action' ) );
		$ids    = self::ids( 'church_ids' );

		// "Select all N matching" – rebuild the id list from the posted filters.
		if ( self::post( 'all_matching' ) ) {
			$filters = Churches_Table::read_filters( isset( $_POST['filters'] ) ? (array) $_POST['filters'] : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in read_filters.
			$args    = Churches_Table::to_query_args( $filters );
			$args['ids_only'] = true;
			$ids     = Church_Repository::query( $args )['ids'];
		}
		$list = Util::admin_url( 'aso-churches' );
		if ( ! $ids ) {
			self::back( $list, __( 'Select at least one church.', 'apostolic-spotlight-outreach' ), 'warning' );
		}
		if ( ! $action ) {
			self::back( $list, __( 'Choose a bulk action.', 'apostolic-spotlight-outreach' ), 'warning' );
		}

		if ( 'add_to_campaign' === $action ) {
			$campaign_id = absint( self::post( 'campaign_id' ) );
			if ( ! $campaign_id ) {
				self::back( $list, __( 'Choose a campaign to add the churches to.', 'apostolic-spotlight-outreach' ), 'warning' );
			}
			// Hand the selection to the review screen via a short-lived transient (can be hundreds of IDs).
			$key = 'aso_sel_' . get_current_user_id() . '_' . wp_generate_password( 8, false );
			set_transient( $key, $ids, HOUR_IN_SECONDS );
			self::go( Util::admin_url( 'aso-churches', array( 'view' => 'enroll', 'campaign' => $campaign_id, 'sel' => substr( $key, 8 ) ) ) );
		}

		$n = 0;
		foreach ( $ids as $id ) {
			if ( ! Church_Repository::find( $id ) ) {
				continue;
			}
			switch ( $action ) {
				case 'mark_replied':
					Outreach_State::mark_replied( $id, 'manual' );
					break;
				case 'mark_interested':
					Outreach_State::set_spotlight_status( $id, 'interested' );
					break;
				case 'not_interested':
					Outreach_State::set_spotlight_status( $id, 'not_interested' );
					break;
				case 'pause':
					Outreach_State::pause( $id );
					break;
				case 'resume':
					Outreach_State::resume( $id );
					break;
				default:
					continue 2;
			}
			++$n;
		}
		/* translators: %d: number of churches */
		self::back( $list, sprintf( _n( '%d church updated.', '%d churches updated.', $n, 'apostolic-spotlight-outreach' ), $n ) );
	}

	public static function enroll_confirm() {
		self::guard( 'aso_enroll_confirm' );
		$campaign_id = absint( self::post( 'campaign_id' ) );
		$overrides   = array();
		foreach ( self::ids( 'override' ) as $id ) {
			$overrides[ $id ] = true;
		}
		// Rows with warnings post only "override[]" – ticking it both includes and overrides.
		$ids = array_values( array_unique( array_merge( self::ids( 'church_ids' ), array_keys( $overrides ) ) ) );
		$note   = sanitize_text_field( self::post( 'override_note' ) );
		$result = Enrollments::enroll( $campaign_id, $ids, $overrides, $note );
		$msg    = sprintf(
			/* translators: 1: enrolled 2: skipped 3: blocked */
			__( 'Enrolled %1$d church(es). Skipped %2$d with warnings (not overridden). %3$d could not be enrolled.', 'apostolic-spotlight-outreach' ),
			$result['enrolled'],
			$result['skipped'],
			$result['blocked']
		);
		$campaign = Campaigns::find( $campaign_id );
		if ( $campaign && 'active' !== $campaign->status ) {
			$msg .= ' ' . __( 'This campaign is not active yet – activate it to start sending.', 'apostolic-spotlight-outreach' );
		} else {
			Queue::materialize();
			Queue::plan();
		}
		self::go( Util::admin_url( 'aso-campaigns', array( 'view' => 'view', 'id' => $campaign_id ) ), $msg );
	}

	public static function church_save() {
		self::guard( 'aso_church_save' );
		$church = Church_Repository::find( absint( self::post( 'church_id' ) ) );
		if ( ! $church ) {
			self::back( Util::admin_url( 'aso-churches' ), __( 'Church not found.', 'apostolic-spotlight-outreach' ), 'error' );
		}
		$values = array();
		foreach ( Field_Map::fields() as $field => $def ) {
			if ( ! isset( $_POST['fields'][ $field ] ) || ! Church_Repository::is_writable( $field ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				continue;
			}
			$raw = wp_unslash( $_POST['fields'][ $field ] ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
			switch ( $def['type'] ) {
				case 'email':
					$v = sanitize_email( $raw );
					break;
				case 'url':
					$v = esc_url_raw( $raw );
					break;
				case 'textarea':
					$v = sanitize_textarea_field( $raw );
					break;
				case 'select':
					$v = array_key_exists( $raw, Field_Map::contact_preferences() ) ? $raw : '';
					break;
				default:
					$v = sanitize_text_field( $raw );
			}
			$values[ $field ] = $v;
		}
		Church_Repository::update_fields( $church, $values );

		$spot = sanitize_key( self::post( 'spotlight_status' ) );
		if ( $spot && Statuses::is_valid( 'spotlight', $spot ) ) {
			if ( 'replied' === $spot ) {
				Outreach_State::mark_replied( $church->id, 'manual' );
			} else {
				Outreach_State::set_spotlight_status( $church->id, $spot );
			}
		}
		foreach ( array( 'website_status', 'grow_status', 'website_review', 'grow_fit' ) as $f ) {
			if ( isset( $_POST[ $f ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				Outreach_State::set_opportunity( $church->id, $f, sanitize_key( self::post( $f ) ) );
			}
		}
		if ( isset( $_POST['next_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			Outreach_State::update( $church->id, array( 'next_action' => sanitize_text_field( self::post( 'next_action' ) ) ) );
		}
		self::back( Util::church_admin_url( $church->id ), __( 'Church saved.', 'apostolic-spotlight-outreach' ) );
	}

	public static function church_action() {
		self::guard( 'aso_church_action' );
		$id     = absint( self::req( 'church' ) );
		$do     = sanitize_key( self::req( 'do' ) );
		$church = Church_Repository::find( $id );
		if ( ! $church ) {
			self::back( Util::admin_url( 'aso-churches' ), __( 'Church not found.', 'apostolic-spotlight-outreach' ), 'error' );
		}
		$msg = __( 'Updated.', 'apostolic-spotlight-outreach' );
		switch ( $do ) {
			case 'mark_replied':
				Outreach_State::mark_replied( $id, 'manual' );
				$msg = __( 'Marked replied. Pending follow-ups were canceled.', 'apostolic-spotlight-outreach' );
				break;
			case 'interested':
			case 'not_interested':
			case 'completed':
			case 'published':
				Outreach_State::set_spotlight_status( $id, $do );
				break;
			case 'pause':
				Outreach_State::pause( $id );
				$msg = __( 'Outreach paused. No automated emails will be sent.', 'apostolic-spotlight-outreach' );
				break;
			case 'resume':
				Outreach_State::resume( $id );
				break;
			case 'clear_review':
				Outreach_State::clear_review( $id );
				$msg = __( 'Review flag cleared.', 'apostolic-spotlight-outreach' );
				break;
			case 'new_token':
				Outreach_State::regenerate_token( $id );
				Activity::log( $id, 'note', __( 'Booking link regenerated (old link no longer works).', 'apostolic-spotlight-outreach' ) );
				$msg = __( 'New booking link generated.', 'apostolic-spotlight-outreach' );
				break;
		}
		self::back( Util::church_admin_url( $id ), $msg );
	}

	public static function add_note() {
		self::guard( 'aso_add_note' );
		$id   = absint( self::post( 'church_id' ) );
		$note = sanitize_textarea_field( self::post( 'note' ) );
		if ( $id && $note && Church_Repository::find( $id ) ) {
			Activity::log( $id, 'note', $note );
		}
		self::back( Util::church_admin_url( $id ), __( 'Note added.', 'apostolic-spotlight-outreach' ) );
	}

	/**
	 * Manual one-off email (incl. Website / Grow follow-ups – never automatic).
	 */
	public static function send_email() {
		self::guard( 'aso_send_email' );
		$church = Church_Repository::find( absint( self::post( 'church_id' ) ) );
		if ( ! $church ) {
			self::back( Util::admin_url( 'aso-churches' ), __( 'Church not found.', 'apostolic-spotlight-outreach' ), 'error' );
		}
		$to      = sanitize_email( self::post( 'to' ) );
		$subject = sanitize_text_field( self::post( 'subject' ) );
		$body    = sanitize_textarea_field( self::post( 'body' ) );
		$tpl_id  = absint( self::post( 'template_id' ) );
		$tpl     = $tpl_id ? Templates::find( $tpl_id ) : null;
		$kind    = $tpl && in_array( $tpl->category, array( 'website', 'grow' ), true ) ? $tpl->category : 'manual';

		if ( ! $to || ! $subject || ! $body ) {
			self::back( Util::church_admin_url( $church->id ), __( 'Recipient, subject and message are required.', 'apostolic-spotlight-outreach' ), 'error' );
		}
		if ( preg_match( '/\{\{\s*[a-z0-9_]+/i', $subject . $body ) ) {
			self::back( Util::church_admin_url( $church->id ), __( 'The message still contains {{variables}}. Please fill them in before sending.', 'apostolic-spotlight-outreach' ), 'error' );
		}
		$result = Mailer::send( $church, $to, $subject, $body, array( 'kind' => $kind, 'template_id' => $tpl_id ? $tpl_id : null ) );
		if ( is_wp_error( $result ) ) {
			self::back( Util::church_admin_url( $church->id ), $result->get_error_message(), 'error' );
		}
		if ( 'website' === $kind ) {
			$state = Outreach_State::get( $church->id );
			if ( in_array( $state->website_status, array( 'unknown', 'potential' ), true ) ) {
				Outreach_State::set_opportunity( $church->id, 'website_status', 'potential' );
			}
		} elseif ( 'grow' === $kind ) {
			$state = Outreach_State::get( $church->id );
			if ( in_array( $state->grow_status, array( 'unknown', 'potential' ), true ) ) {
				Outreach_State::set_opportunity( $church->id, 'grow_status', 'potential' );
			}
		}
		self::go( Util::church_admin_url( $church->id ), __( 'Email sent from your Google Workspace account.', 'apostolic-spotlight-outreach' ) );
	}

	/* ------------------------------------------------------------------
	 * Campaigns.
	 * ------------------------------------------------------------------ */

	public static function campaign_save() {
		self::guard( 'aso_campaign_save' );
		$id   = absint( self::post( 'id' ) );
		$name = sanitize_text_field( self::post( 'name' ) );
		if ( '' === $name ) {
			self::back( Util::admin_url( 'aso-campaigns' ), __( 'Campaign name is required.', 'apostolic-spotlight-outreach' ), 'error' );
		}
		$steps = array();
		$raw   = isset( $_POST['steps'] ) && is_array( $_POST['steps'] ) ? wp_unslash( $_POST['steps'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value cast below.
		foreach ( $raw as $s ) {
			if ( ! is_array( $s ) || ! empty( $s['remove'] ) ) {
				continue;
			}
			$tpl = Templates::find( absint( $s['template_id'] ?? 0 ) );
			if ( ! $tpl || ! in_array( $tpl->category, Templates::sequence_categories(), true ) ) {
				continue; // Website/Grow templates can never be automated.
			}
			$steps[] = array(
				'template_id'     => (int) $tpl->id,
				'delay_days'      => absint( $s['delay_days'] ?? 1 ),
				'reply_in_thread' => ! empty( $s['reply_in_thread'] ),
			);
		}
		if ( ! $steps ) {
			self::back( Util::admin_url( 'aso-campaigns' ), __( 'A campaign needs at least one email step.', 'apostolic-spotlight-outreach' ), 'error' );
		}
		$start_mode = sanitize_key( self::post( 'start_mode' ) );
		$start_at   = 'scheduled' === $start_mode ? self::local_to_db( self::post( 'start_at' ), wp_timezone() ) : null;
		$data       = array(
			'name'        => $name,
			'description' => self::post( 'description' ),
			'start_at'    => $start_at,
			'steps'       => $steps,
		);
		if ( $id ) {
			Campaigns::update( $id, $data );
		} else {
			$id = Campaigns::create( $data );
		}
		self::go( Util::admin_url( 'aso-campaigns', array( 'view' => 'view', 'id' => $id ) ), __( 'Campaign saved.', 'apostolic-spotlight-outreach' ) );
	}

	public static function campaign_status() {
		self::guard( 'aso_campaign_status' );
		$id     = absint( self::req( 'id' ) );
		$status = sanitize_key( self::req( 'status' ) );
		Campaigns::set_status( $id, $status );
		if ( 'active' === $status ) {
			Queue::materialize();
			Queue::plan();
		}
		/* translators: %s: status */
		self::back( Util::admin_url( 'aso-campaigns' ), sprintf( __( 'Campaign is now %s.', 'apostolic-spotlight-outreach' ), Statuses::label( 'campaign', $status ) ) );
	}

	public static function enrollment_remove() {
		self::guard( 'aso_enrollment_remove' );
		$en = Enrollments::find( absint( self::req( 'id' ) ) );
		if ( $en ) {
			Enrollments::stop_for_church( (int) $en->church_id, 'removed', 'stopped', (int) $en->campaign_id );
		}
		self::back( Util::admin_url( 'aso-campaigns' ), __( 'Removed from campaign; pending emails canceled.', 'apostolic-spotlight-outreach' ) );
	}

	/* ------------------------------------------------------------------
	 * Templates.
	 * ------------------------------------------------------------------ */

	public static function template_save() {
		self::guard( 'aso_template_save', Capabilities::TEMPLATES );
		$id   = absint( self::post( 'id' ) );
		$data = array(
			'name'     => self::post( 'name' ),
			'category' => sanitize_key( self::post( 'category' ) ),
			'subject'  => self::post( 'subject' ),
			'body'     => self::post( 'body' ),
		);
		if ( '' === trim( $data['name'] ) || '' === trim( $data['subject'] ) || '' === trim( $data['body'] ) ) {
			self::back( Util::admin_url( 'aso-templates' ), __( 'Name, subject and body are required.', 'apostolic-spotlight-outreach' ), 'error' );
		}
		$id = Templates::save( $data, $id );
		self::go( Util::admin_url( 'aso-templates', array( 'view' => 'edit', 'id' => $id ) ), __( 'Template saved.', 'apostolic-spotlight-outreach' ) );
	}

	public static function template_delete() {
		self::guard( 'aso_template_delete', Capabilities::TEMPLATES );
		$res = Templates::delete( absint( self::req( 'id' ) ) );
		if ( is_wp_error( $res ) ) {
			self::go( Util::admin_url( 'aso-templates' ), $res->get_error_message(), 'error' );
		}
		self::go( Util::admin_url( 'aso-templates' ), __( 'Template deleted.', 'apostolic-spotlight-outreach' ) );
	}

	/* ------------------------------------------------------------------
	 * Queue.
	 * ------------------------------------------------------------------ */

	public static function queue_action() {
		self::guard( 'aso_queue_action' );
		$do  = sanitize_key( self::req( 'do' ) );
		$id  = absint( self::req( 'id' ) );
		$url = Util::admin_url( 'aso-queue' );
		switch ( $do ) {
			case 'retry':
				Queue::retry( $id );
				self::back( $url, __( 'Email returned to the queue.', 'apostolic-spotlight-outreach' ) );
				break;
			case 'cancel':
				Queue::cancel( $id );
				self::back( $url, __( 'Email canceled.', 'apostolic-spotlight-outreach' ) );
				break;
			case 'send_now':
				$outcome = Queue::send_now( $id );
				self::back( $url, 'sent' === $outcome ? __( 'Email sent.', 'apostolic-spotlight-outreach' ) : sprintf( /* translators: %s: outcome */ __( 'Not sent (%s). See the queue row for details.', 'apostolic-spotlight-outreach' ), $outcome ), 'sent' === $outcome ? 'success' : 'warning' );
				break;
			case 'rebalance':
				$n = Queue::rebalance();
				/* translators: %d: count */
				self::back( $url, sprintf( __( 'Queue re-planned: %d email(s) scheduled.', 'apostolic-spotlight-outreach' ), $n ) );
				break;
			case 'run':
				Queue::run();
				self::back( $url, __( 'Queue processed.', 'apostolic-spotlight-outreach' ) );
				break;
		}
		self::back( $url );
	}

	/* ------------------------------------------------------------------
	 * Bookings.
	 * ------------------------------------------------------------------ */

	public static function booking_action() {
		self::guard( 'aso_booking_action' );
		$do  = sanitize_key( self::req( 'do' ) );
		$id  = absint( self::req( 'id' ) );
		$url = Util::admin_url( 'aso-bookings' );
		switch ( $do ) {
			case 'cancel':
				$res = Bookings::cancel( $id, sanitize_text_field( self::req( 'reason' ) ) );
				is_wp_error( $res )
					? self::back( $url, $res->get_error_message(), 'error' )
					: self::back( $url, __( 'Interview canceled. Google Calendar notified the pastor.', 'apostolic-spotlight-outreach' ) );
				break;
			case 'completed':
			case 'no_show':
				Bookings::set_outcome( $id, $do );
				self::back( $url, 'completed' === $do ? __( 'Spotlight marked completed. Review the church for opportunities when you are ready.', 'apostolic-spotlight-outreach' ) : __( 'Marked as no-show.', 'apostolic-spotlight-outreach' ) );
				break;
			case 'reschedule':
				$ts = self::local_to_db( self::post( 'new_start' ), Settings::booking_timezone() );
				if ( ! $ts ) {
					self::back( $url, __( 'Choose a new date and time.', 'apostolic-spotlight-outreach' ), 'error' );
				}
				$res = Bookings::reschedule( $id, Util::from_db( $ts )->getTimestamp() );
				is_wp_error( $res )
					? self::back( $url, $res->get_error_message(), 'error' )
					: self::back( $url, __( 'Interview moved. Google Calendar sent the pastor an updated invitation.', 'apostolic-spotlight-outreach' ) );
				break;
			case 'link':
				$ok = Bookings::link_church( $id, absint( self::post( 'church_id' ) ) );
				self::back( $url, $ok ? __( 'Booking linked to church.', 'apostolic-spotlight-outreach' ) : __( 'Could not link – check the church ID.', 'apostolic-spotlight-outreach' ), $ok ? 'success' : 'error' );
				break;
		}
		self::back( $url );
	}

	/* ------------------------------------------------------------------
	 * Opportunities.
	 * ------------------------------------------------------------------ */

	public static function opportunity_save() {
		self::guard( 'aso_opportunity_save' );
		$id = absint( self::post( 'church_id' ) );
		if ( ! Church_Repository::find( $id ) ) {
			self::back( Util::admin_url( 'aso-opportunities' ), __( 'Church not found.', 'apostolic-spotlight-outreach' ), 'error' );
		}
		foreach ( array( 'website_review', 'grow_fit', 'website_status', 'grow_status' ) as $f ) {
			if ( isset( $_POST[ $f ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				Outreach_State::set_opportunity( $id, $f, sanitize_key( self::post( $f ) ) );
			}
		}
		$spot = sanitize_key( self::post( 'spotlight_status' ) );
		if ( in_array( $spot, array( 'completed', 'published' ), true ) ) {
			Outreach_State::set_spotlight_status( $id, $spot );
		}
		self::back( Util::admin_url( 'aso-opportunities' ), __( 'Opportunity review saved.', 'apostolic-spotlight-outreach' ) );
	}

	/* ------------------------------------------------------------------
	 * Settings.
	 * ------------------------------------------------------------------ */

	public static function settings_save() {
		self::guard( 'aso_settings_save', Capabilities::SETTINGS );
		$tab = sanitize_key( self::post( 'tab' ) );
		$url = Util::admin_url( 'aso-settings', array( 'tab' => $tab ) );

		switch ( $tab ) {
			case 'google':
				if ( ! Google_Oauth::credentials_from_constants() ) {
					$g              = (array) Settings::get( 'google' );
					$g['client_id'] = sanitize_text_field( self::post( 'client_id' ) );
					Settings::update_section( 'google', $g );
					$secret = trim( (string) self::post( 'client_secret' ) );
					if ( '' !== $secret && false === strpos( $secret, '•' ) ) {
						Google_Oauth::save_client_secret( sanitize_text_field( $secret ) );
					}
				}
				Settings::set( 'sender_name', sanitize_text_field( self::post( 'sender_name' ) ) );
				break;

			case 'church':
				$c              = (array) Settings::get( 'church' );
				$pt             = sanitize_key( self::post( 'post_type' ) );
				$c['post_type'] = post_type_exists( $pt ) ? $pt : '';
				$c['write_back'] = self::post( 'write_back' ) ? 1 : 0;
				$map            = array();
				$raw            = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				foreach ( array_keys( Field_Map::fields() ) as $field ) {
					$v = isset( $raw[ $field ] ) ? sanitize_text_field( $raw[ $field ] ) : '';
					if ( preg_match( '/^(title|permalink|plugin|meta:[^\s]{1,255}|tax:[a-z0-9_\-]{1,32})$/i', $v ) ) {
						$map[ $field ] = $v;
					}
				}
				if ( self::post( 'auto_detect' ) && $c['post_type'] ) {
					$map = Field_Map::guess_map( $c['post_type'] );
				}
				$c['map'] = $map;
				Settings::update_section( 'church', $c );
				break;

			case 'sending':
				$s                        = (array) Settings::get( 'sending' );
				$s['daily_limit']         = max( 1, min( 100, absint( self::post( 'daily_limit' ) ) ) );
				$days                     = array_map( 'absint', (array) self::post( 'days', array() ) );
				$s['days']                = array_values( array_intersect( $days, range( 1, 7 ) ) );
				$s['start']               = Util::sanitize_hm( self::post( 'start' ), '09:00' );
				$s['end']                 = Util::sanitize_hm( self::post( 'end' ), '16:00' );
				$tz                       = sanitize_text_field( self::post( 'timezone' ) );
				$s['timezone']            = in_array( $tz, timezone_identifiers_list(), true ) ? $tz : $s['timezone'];
				$s['min_spacing']         = max( 1, min( 240, absint( self::post( 'min_spacing' ) ) ) );
				$s['distribution']        = 'even' === self::post( 'distribution' ) ? 'even' : 'random';
				$s['recent_contact_days'] = min( 365, absint( self::post( 'recent_contact_days' ) ) );
				$s['no_response_days']    = max( 1, min( 60, absint( self::post( 'no_response_days' ) ) ) );
				$s['paused']              = self::post( 'paused' ) ? 1 : 0;
				Settings::update_section( 'sending', $s );
				$seq = array(
					'followup1_delay' => max( 1, min( 60, absint( self::post( 'followup1_delay' ) ) ) ),
					'followup2_delay' => max( 1, min( 60, absint( self::post( 'followup2_delay' ) ) ) ),
				);
				Settings::update_section( 'sequence', $seq );
				if ( self::post( 'rebalance' ) ) {
					Queue::rebalance();
				}
				break;

			case 'booking':
				$b         = (array) Settings::get( 'booking' );
				$old_slug  = $b['slug'];
				$slug      = trim( preg_replace( '#[^a-z0-9\-/]#', '', strtolower( (string) self::post( 'slug' ) ) ), '/' );
				$b['slug'] = $slug ? $slug : 'spotlight/schedule';
				foreach ( array( 'meeting_name', 'event_title' ) as $k ) {
					$b[ $k ] = sanitize_text_field( self::post( $k ) );
				}
				$b['intro'] = sanitize_textarea_field( self::post( 'intro' ) );
				foreach ( array( 'duration' => array( 5, 240 ), 'slot_interval' => array( 5, 240 ), 'buffer_before' => array( 0, 240 ), 'buffer_after' => array( 0, 240 ), 'window_days' => array( 1, 365 ), 'max_per_day' => array( 0, 50 ) ) as $k => $range ) {
					$b[ $k ] = max( $range[0], min( $range[1], absint( self::post( $k ) ) ) );
				}
				$b['min_notice_hours'] = max( 0, min( 720, (float) self::post( 'min_notice_hours' ) ) );
				$tz                    = sanitize_text_field( self::post( 'timezone' ) );
				$b['timezone']         = in_array( $tz, timezone_identifiers_list(), true ) ? $tz : $b['timezone'];
				$b['busy_calendars']   = sanitize_text_field( self::post( 'busy_calendars' ) );
				$b['event_calendar']   = sanitize_text_field( self::post( 'event_calendar' ) );
				$b['admin_link_in_event'] = self::post( 'admin_link_in_event' ) ? 1 : 0;
				$hours                 = array();
				$raw                   = isset( $_POST['hours'] ) && is_array( $_POST['hours'] ) ? wp_unslash( $_POST['hours'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				for ( $d = 1; $d <= 7; $d++ ) {
					$hours[ $d ] = array();
					foreach ( (array) ( $raw[ $d ] ?? array() ) as $range ) {
						$from = Util::sanitize_hm( $range['from'] ?? '', '' );
						$to   = Util::sanitize_hm( $range['to'] ?? '', '' );
						if ( $from && $to && Util::hm_to_minutes( $to ) > Util::hm_to_minutes( $from ) ) {
							$hours[ $d ][] = array( $from, $to );
						}
					}
				}
				$b['hours'] = $hours;
				Settings::update_section( 'booking', $b );
				Availability::flush_cache();
				if ( $old_slug !== $b['slug'] ) {
					Booking_Frontend::add_rewrite_rules();
					flush_rewrite_rules();
				}
				break;

			case 'replies':
				$r            = (array) Settings::get( 'replies' );
				$want         = (bool) self::post( 'enabled' );
				$r['enabled'] = $want && Google_Oauth::has_scope( Google_Oauth::SCOPE_METADATA ) ? 1 : 0;
				Settings::update_section( 'replies', $r );
				if ( $want && ! $r['enabled'] ) {
					// Need the extra permission – send the user through Google consent for gmail.metadata.
					wp_safe_redirect( wp_nonce_url( add_query_arg( array( 'action' => 'aso_google_connect', 'replies' => 1 ), admin_url( 'admin-post.php' ) ), 'aso_google_connect' ) );
					exit;
				}
				break;

			case 'permissions':
				$p            = (array) Settings::get( 'permissions' );
				$p['editors'] = self::post( 'editors' ) ? 1 : 0;
				Settings::update_section( 'permissions', $p );
				Capabilities::sync_editor_role();
				break;
		}
		self::go( $url, __( 'Settings saved.', 'apostolic-spotlight-outreach' ) );
	}
}
