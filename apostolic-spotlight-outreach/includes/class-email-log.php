<?php
/**
 * Email log (outgoing sends + detected inbound replies).
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

defined( 'ABSPATH' ) || exit;

final class Email_Log {

	private static function table() {
		return Installer::table( 'email_log' );
	}

	public static function insert( array $row ) {
		global $wpdb;
		$row = array_merge(
			array(
				'direction'  => 'out',
				'kind'       => 'campaign',
				'status'     => 'sent',
				'user_id'    => get_current_user_id(),
				'created_at' => Util::now_db(),
			),
			$row
		);
		$wpdb->insert( self::table(), $row );
		return (int) $wpdb->insert_id;
	}

	public static function find( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function for_church( $church_id, $limit = 100 ) {
		global $wpdb;
		$t = Installer::table( 'templates' );
		$c = Installer::table( 'campaigns' );
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.*, t.name AS template_name, c.name AS campaign_name FROM ' . self::table() . " l
				LEFT JOIN {$t} t ON t.id = l.template_id
				LEFT JOIN {$c} c ON c.id = l.campaign_id
				WHERE l.church_id = %d ORDER BY l.created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$church_id,
				$limit
			)
		);
	}

	public static function seen_message( $gmail_message_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE gmail_message_id = %s LIMIT 1', $gmail_message_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function count_sent_since( $since_db, $kind = null ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE direction = 'out' AND status = 'sent' AND created_at >= %s", $since_db ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $kind ) {
			$sql .= $wpdb->prepare( ' AND kind = %s', $kind );
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function count_churches_contacted_since( $since_db ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT church_id) FROM ' . self::table() . " WHERE direction = 'out' AND status = 'sent' AND created_at >= %s", $since_db ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
