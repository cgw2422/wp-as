<?php
/**
 * Uninstall: removes plugin tables, options, capabilities and plugin-owned post meta.
 *
 * Directory church posts and their own fields are never touched. Data is kept unless
 * ASO_DELETE_DATA_ON_UNINSTALL is true in wp-config.php (outreach history is valuable).
 *
 * @package ApostolicSpotlight\Outreach
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'ASO_DELETE_DATA_ON_UNINSTALL' ) || ! ASO_DELETE_DATA_ON_UNINSTALL ) {
	// Still revoke stored Google tokens locally.
	delete_option( 'aso_google_tokens' );
	return;
}

global $wpdb;
foreach ( array( 'church_outreach', 'campaigns', 'campaign_steps', 'enrollments', 'queue', 'email_log', 'activity', 'bookings', 'templates' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aso_{$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL
}
foreach ( array( 'aso_settings', 'aso_db_version', 'aso_google_tokens', 'aso_google_account', 'aso_google_client_secret', 'aso_gmail_history_id', 'aso_reply_detector_status', 'aso_cron_last_run', 'aso_lock_queue' ) as $opt ) {
	delete_option( $opt );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_aso_' ) . '%' ) );

foreach ( wp_roles()->role_objects as $role ) {
	foreach ( array( 'aso_manage_outreach', 'aso_manage_templates', 'aso_manage_settings' ) as $cap ) {
		$role->remove_cap( $cap );
	}
}
wp_clear_scheduled_hook( 'aso_cron_queue' );
