<?php
/**
 * Campaigns and their email sequences.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

defined( 'ABSPATH' ) || exit;

final class Campaigns {

	public static function find( $id ) {
		global $wpdb;
		$t = Installer::table( 'campaigns' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function all( $include_archived = false ) {
		global $wpdb;
		$t     = Installer::table( 'campaigns' );
		$where = $include_archived ? '' : "WHERE status <> 'archived'";
		return $wpdb->get_results( "SELECT * FROM {$t} {$where} ORDER BY FIELD(status,'active','draft','paused','archived'), created_at DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Campaigns new churches can be added to.
	 */
	public static function enrollable() {
		return array_filter(
			self::all(),
			static function ( $c ) {
				return in_array( $c->status, array( 'draft', 'active', 'paused' ), true );
			}
		);
	}

	public static function create( array $data ) {
		global $wpdb;
		$now = Util::now_db();
		$wpdb->insert(
			Installer::table( 'campaigns' ),
			array(
				'name'        => sanitize_text_field( $data['name'] ),
				'description' => sanitize_textarea_field( $data['description'] ?? '' ),
				'status'      => 'draft',
				'start_at'    => ! empty( $data['start_at'] ) ? $data['start_at'] : null,
				'created_by'  => get_current_user_id(),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		self::save_steps( $id, ! empty( $data['steps'] ) ? $data['steps'] : self::default_steps() );
		return $id;
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		$row = array( 'updated_at' => Util::now_db() );
		if ( isset( $data['name'] ) ) {
			$row['name'] = sanitize_text_field( $data['name'] );
		}
		if ( array_key_exists( 'description', $data ) ) {
			$row['description'] = sanitize_textarea_field( $data['description'] );
		}
		if ( array_key_exists( 'start_at', $data ) ) {
			$row['start_at'] = $data['start_at'] ? $data['start_at'] : null;
		}
		$wpdb->update( Installer::table( 'campaigns' ), $row, array( 'id' => (int) $id ) );
		if ( isset( $data['steps'] ) ) {
			self::save_steps( $id, $data['steps'] );
		}
	}

	public static function set_status( $id, $status ) {
		global $wpdb;
		if ( ! Statuses::is_valid( 'campaign', $status ) ) {
			return;
		}
		$wpdb->update( Installer::table( 'campaigns' ), array( 'status' => $status, 'updated_at' => Util::now_db() ), array( 'id' => (int) $id ) );
		if ( in_array( $status, array( 'paused', 'archived', 'draft' ), true ) ) {
			// Release any reserved send slots; the planner skips inactive campaigns.
			$q = Installer::table( 'queue' );
			$wpdb->query( $wpdb->prepare( "UPDATE {$q} SET status = 'pending', scheduled_at = NULL WHERE campaign_id = %d AND status = 'scheduled'", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( 'archived' === $status ) {
			$e = Installer::table( 'enrollments' );
			$active = $wpdb->get_col( $wpdb->prepare( "SELECT church_id FROM {$e} WHERE campaign_id = %d AND status IN ('active','paused')", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $active as $church_id ) {
				Enrollments::stop_for_church( (int) $church_id, 'campaign_archived', 'stopped', (int) $id );
			}
		}
	}

	public static function default_steps() {
		$steps = array();
		$map   = array(
			array( 'spotlight_invitation', 0 ),
			array( 'spotlight_followup_1', (int) Settings::get( 'sequence.followup1_delay', 4 ) ),
			array( 'spotlight_followup_2', (int) Settings::get( 'sequence.followup2_delay', 7 ) ),
		);
		foreach ( $map as $i => $def ) {
			$tpl = Templates::find_by_slug( $def[0] );
			if ( $tpl ) {
				$steps[] = array(
					'template_id'     => (int) $tpl->id,
					'delay_days'      => $def[1],
					'reply_in_thread' => $i > 0 ? 1 : 0,
				);
			}
		}
		return $steps;
	}

	public static function steps( $campaign_id ) {
		global $wpdb;
		$t = Installer::table( 'steps' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE campaign_id = %d ORDER BY step_number ASC", $campaign_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function step( $campaign_id, $step_number ) {
		global $wpdb;
		$t = Installer::table( 'steps' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE campaign_id = %d AND step_number = %d", $campaign_id, $step_number ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Replace the sequence. Step numbers are re-assigned 1..n in order.
	 *
	 * @param array $steps [ [template_id, delay_days, reply_in_thread], ... ]
	 */
	public static function save_steps( $campaign_id, array $steps ) {
		global $wpdb;
		$t = Installer::table( 'steps' );
		$wpdb->delete( $t, array( 'campaign_id' => (int) $campaign_id ) );
		$n = 0;
		foreach ( $steps as $s ) {
			$template_id = (int) ( $s['template_id'] ?? 0 );
			if ( ! $template_id || ! Templates::find( $template_id ) ) {
				continue;
			}
			++$n;
			$wpdb->insert(
				$t,
				array(
					'campaign_id'     => (int) $campaign_id,
					'step_number'     => $n,
					'template_id'     => $template_id,
					'delay_days'      => 1 === $n ? 0 : max( 1, min( 60, (int) ( $s['delay_days'] ?? 1 ) ) ),
					'reply_in_thread' => 1 === $n ? 0 : ( empty( $s['reply_in_thread'] ) ? 0 : 1 ),
				)
			);
		}
	}

	/**
	 * Counts for campaign cards.
	 */
	public static function stats( $campaign_id ) {
		global $wpdb;
		$e   = Installer::table( 'enrollments' );
		$l   = Installer::table( 'email_log' );
		$q   = Installer::table( 'queue' );
		$o   = Installer::table( 'outreach' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS enrolled,
					SUM(e.status = 'active') AS active,
					SUM(e.status = 'stopped') AS stopped,
					SUM(o.replied_at IS NOT NULL AND o.replied_at >= e.enrolled_at) AS replied,
					SUM(o.spotlight_status IN ('scheduled','completed','published')) AS booked
				FROM {$e} e LEFT JOIN {$o} o ON o.church_id = e.church_id
				WHERE e.campaign_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$campaign_id
			),
			ARRAY_A
		);
		$row['sent']    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$l} WHERE campaign_id = %d AND status = 'sent' AND direction = 'out'", $campaign_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row['queued']  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$q} WHERE campaign_id = %d AND status IN ('pending','scheduled','held')", $campaign_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', $row );
	}
}
