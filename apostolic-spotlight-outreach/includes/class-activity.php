<?php
/**
 * Per-church activity timeline.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

defined( 'ABSPATH' ) || exit;

final class Activity {

	/**
	 * Timeline entry types => [label, icon (dashicon), tone].
	 */
	public static function types() {
		return array(
			'email_sent'        => array( __( 'Email sent', 'apostolic-spotlight-outreach' ), 'email-alt', 'blue' ),
			'email_failed'      => array( __( 'Email failed', 'apostolic-spotlight-outreach' ), 'warning', 'red' ),
			'email_held'        => array( __( 'Email held for review', 'apostolic-spotlight-outreach' ), 'flag', 'amber' ),
			'reply'             => array( __( 'Reply received', 'apostolic-spotlight-outreach' ), 'format-chat', 'amber' ),
			'auto_reply'        => array( __( 'Auto-reply received', 'apostolic-spotlight-outreach' ), 'clock', 'muted' ),
			'bounce'            => array( __( 'Delivery failure', 'apostolic-spotlight-outreach' ), 'dismiss', 'red' ),
			'status'            => array( __( 'Status changed', 'apostolic-spotlight-outreach' ), 'tag', 'slate' ),
			'opportunity'       => array( __( 'Opportunity updated', 'apostolic-spotlight-outreach' ), 'chart-line', 'purple' ),
			'enrolled'          => array( __( 'Added to campaign', 'apostolic-spotlight-outreach' ), 'megaphone', 'slate' ),
			'automation_stopped'=> array( __( 'Outreach automation stopped', 'apostolic-spotlight-outreach' ), 'controls-pause', 'amber' ),
			'automation_paused' => array( __( 'Outreach paused', 'apostolic-spotlight-outreach' ), 'controls-pause', 'gray' ),
			'automation_resumed'=> array( __( 'Outreach resumed', 'apostolic-spotlight-outreach' ), 'controls-play', 'blue' ),
			'booking'           => array( __( 'Spotlight scheduled', 'apostolic-spotlight-outreach' ), 'calendar-alt', 'purple' ),
			'booking_canceled'  => array( __( 'Booking canceled', 'apostolic-spotlight-outreach' ), 'calendar', 'muted' ),
			'booking_moved'     => array( __( 'Booking rescheduled', 'apostolic-spotlight-outreach' ), 'calendar', 'purple' ),
			'note'              => array( __( 'Note', 'apostolic-spotlight-outreach' ), 'edit', 'gray' ),
			'field_update'      => array( __( 'Details updated', 'apostolic-spotlight-outreach' ), 'admin-generic', 'gray' ),
		);
	}

	/**
	 * @param int    $church_id
	 * @param string $type
	 * @param string $message Plain text (escaped on output).
	 * @param array  $meta
	 * @param int    $campaign_id
	 */
	public static function log( $church_id, $type, $message, array $meta = array(), $campaign_id = null ) {
		global $wpdb;
		$wpdb->insert(
			Installer::table( 'activity' ),
			array(
				'church_id'   => (int) $church_id,
				'type'        => sanitize_key( $type ),
				'message'     => wp_strip_all_tags( (string) $message ),
				'meta'        => $meta ? wp_json_encode( $meta ) : null,
				'campaign_id' => $campaign_id ? (int) $campaign_id : null,
				'user_id'     => get_current_user_id(),
				'created_at'  => Util::now_db(),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		do_action( 'aso_activity_logged', $church_id, $type, $message, $meta );
		return (int) $wpdb->insert_id;
	}

	public static function for_church( $church_id, $limit = 200 ) {
		global $wpdb;
		$table = Installer::table( 'activity' );
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE church_id = %d ORDER BY created_at DESC, id DESC LIMIT %d", $church_id, $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public static function recent( $types = array(), $limit = 10 ) {
		global $wpdb;
		$table = Installer::table( 'activity' );
		if ( $types ) {
			$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE type IN ($in) ORDER BY created_at DESC LIMIT %d", array_merge( $types, array( $limit ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL
			);
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
