<?php
/**
 * Send queue.
 *
 * @package ApostolicSpotlight\Outreach
 */

use ApostolicSpotlight\Outreach\Admin\Admin;
use ApostolicSpotlight\Outreach\Campaigns;
use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Cron;
use ApostolicSpotlight\Outreach\Google\Google_Oauth;
use ApostolicSpotlight\Outreach\Installer;
use ApostolicSpotlight\Outreach\Queue;
use ApostolicSpotlight\Outreach\Settings;
use ApostolicSpotlight\Outreach\Statuses;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

global $wpdb;
$counts = Queue::counts();
$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'scheduled'; // phpcs:ignore WordPress.Security.NonceVerification
if ( ! Statuses::is_valid( 'queue', $status ) && 'upcoming' !== $status ) {
	$status = 'scheduled';
}
$q  = Installer::table( 'queue' );
$tz = Settings::sending_timezone();

$days_map = array( 1 => __( 'Mon', 'apostolic-spotlight-outreach' ), 2 => __( 'Tue', 'apostolic-spotlight-outreach' ), 3 => __( 'Wed', 'apostolic-spotlight-outreach' ), 4 => __( 'Thu', 'apostolic-spotlight-outreach' ), 5 => __( 'Fri', 'apostolic-spotlight-outreach' ), 6 => __( 'Sat', 'apostolic-spotlight-outreach' ), 7 => __( 'Sun', 'apostolic-spotlight-outreach' ) );
$days     = array_map(
	static function ( $d ) use ( $days_map ) {
		return $days_map[ (int) $d ] ?? '';
	},
	(array) Settings::get( 'sending.days' )
);
$last_run = (int) get_option( Cron::LAST_RUN, 0 );

Admin::header(
	__( 'Send Queue', 'apostolic-spotlight-outreach' ),
	array(
		array( 'label' => __( 'Process queue now', 'apostolic-spotlight-outreach' ), 'url' => Admin::post_url( 'aso_queue_action', array( 'do' => 'run' ) ) ),
		array( 'label' => __( 'Re-plan schedule', 'apostolic-spotlight-outreach' ), 'url' => Admin::post_url( 'aso_queue_action', array( 'do' => 'rebalance' ) ) ),
	),
	esc_html(
		sprintf(
			/* translators: 1: limit 2: days 3: start 4: end 5: tz 6: spacing */
			__( 'Up to %1$d emails/day · %2$s · %3$s–%4$s %5$s · at least %6$d min apart', 'apostolic-spotlight-outreach' ),
			Queue::daily_limit(),
			implode( ', ', $days ),
			Settings::get( 'sending.start' ),
			Settings::get( 'sending.end' ),
			$tz->getName(),
			(int) Settings::get( 'sending.min_spacing' )
		)
	) . ( $last_run ? ' · ' . esc_html( sprintf( /* translators: %s: time */ __( 'scheduler ran %s ago', 'apostolic-spotlight-outreach' ), human_time_diff( $last_run ) ) ) : '' )
);

if ( Settings::get( 'sending.paused' ) ) {
	echo '<p class="aso-callout aso-callout--amber">' . esc_html__( 'All campaign sending is paused (Settings → Sending). Emails stay in the queue.', 'apostolic-spotlight-outreach' ) . '</p>';
}
if ( ! Google_Oauth::is_connected() ) {
	echo '<p class="aso-callout aso-callout--red">' . esc_html__( 'Google is not connected – nothing will be sent until you connect your Workspace account.', 'apostolic-spotlight-outreach' ) . '</p>';
}

$tabs = array(
	'scheduled' => Statuses::label( 'queue', 'scheduled' ),
	'pending'   => Statuses::label( 'queue', 'pending' ),
	'upcoming'  => __( 'Upcoming follow-ups', 'apostolic-spotlight-outreach' ),
	'held'      => Statuses::label( 'queue', 'held' ),
	'failed'    => Statuses::label( 'queue', 'failed' ),
	'sent'      => Statuses::label( 'queue', 'sent' ),
	'canceled'  => Statuses::label( 'queue', 'canceled' ),
);
echo '<nav class="aso-tabs">';
foreach ( $tabs as $k => $label ) {
	$n = 'upcoming' === $k ? '' : ' <span class="aso-count">' . esc_html( number_format_i18n( $counts[ $k ] ?? 0 ) ) . '</span>';
	printf( '<a class="aso-tab %s" href="%s">%s%s</a>', esc_attr( $k === $status ? 'is-active' : '' ), esc_url( Util::admin_url( 'aso-queue', array( 'status' => $k ) ) ), esc_html( $label ), $n ); // phpcs:ignore WordPress.Security.EscapeOutput
}
echo '</nav>';

$campaign_names = array();
foreach ( Campaigns::all( true ) as $c ) {
	$campaign_names[ (int) $c->id ] = $c->name;
}
$step_label = static function ( $n ) {
	return 1 === (int) $n ? __( 'Invitation', 'apostolic-spotlight-outreach' ) : sprintf( /* translators: %d: n */ __( 'Follow-Up %d', 'apostolic-spotlight-outreach' ), $n - 1 );
};

if ( 'upcoming' === $status ) {
	$e    = Installer::table( 'enrollments' );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$e} WHERE status = 'active' AND next_step_due_at > %s ORDER BY next_step_due_at ASC LIMIT 200", Util::now_db() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $rows ) {
		Admin::empty_state( __( 'No upcoming follow-ups', 'apostolic-spotlight-outreach' ) );
		return;
	}
	echo '<p class="aso-help">' . esc_html__( 'These are not queued yet. Each becomes a queued email on its due date – unless the church replies, books, or is paused first.', 'apostolic-spotlight-outreach' ) . '</p>';
	echo '<table class="widefat striped aso-table"><thead><tr><th>' . esc_html__( 'Due', 'apostolic-spotlight-outreach' ) . '</th><th>' . esc_html__( 'Church', 'apostolic-spotlight-outreach' ) . '</th><th>' . esc_html__( 'Campaign', 'apostolic-spotlight-outreach' ) . '</th><th>' . esc_html__( 'Next email', 'apostolic-spotlight-outreach' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$church = Church_Repository::find( $r->church_id );
		printf(
			'<tr><td>%s</td><td><a href="%s">%s</a></td><td>%s</td><td>%s</td></tr>',
			esc_html( Util::format( $r->next_step_due_at, null, $tz ) ),
			esc_url( Util::church_admin_url( $r->church_id ) ),
			esc_html( $church ? $church->name() : '#' . $r->church_id ),
			esc_html( $campaign_names[ (int) $r->campaign_id ] ?? '' ),
			esc_html( $step_label( (int) $r->current_step + 1 ) )
		);
	}
	echo '</tbody></table>';
	return;
}

$order = in_array( $status, array( 'sent', 'canceled', 'failed' ), true ) ? 'DESC' : 'ASC';
$col   = 'sent' === $status ? 'sent_at' : ( 'scheduled' === $status ? 'scheduled_at' : 'updated_at' );
$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$q} WHERE status = %s ORDER BY {$col} {$order}, id ASC LIMIT 300", $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

if ( ! $rows ) {
	Admin::empty_state( __( 'Nothing here', 'apostolic-spotlight-outreach' ), 'scheduled' === $status ? __( 'When an active campaign has emails due, they are spread across your sending window here.', 'apostolic-spotlight-outreach' ) : '' );
	return;
}
_prime_post_caches( array_map( 'intval', wp_list_pluck( $rows, 'church_id' ) ), false, true );
?>
<table class="widefat striped aso-table">
	<thead><tr>
		<th><?php echo esc_html( 'sent' === $status ? __( 'Sent', 'apostolic-spotlight-outreach' ) : __( 'Scheduled', 'apostolic-spotlight-outreach' ) ); ?></th>
		<th><?php esc_html_e( 'Church', 'apostolic-spotlight-outreach' ); ?></th>
		<th><?php esc_html_e( 'Campaign', 'apostolic-spotlight-outreach' ); ?></th>
		<th><?php esc_html_e( 'Email', 'apostolic-spotlight-outreach' ); ?></th>
		<th><?php esc_html_e( 'Status', 'apostolic-spotlight-outreach' ); ?></th>
		<th></th>
	</tr></thead>
	<tbody>
	<?php foreach ( $rows as $r ) : ?>
		<?php $church = Church_Repository::find( $r->church_id ); ?>
		<tr>
			<td>
				<?php
				$when = 'sent' === $status ? $r->sent_at : $r->scheduled_at;
				echo $when ? esc_html( Util::format( $when, 'D M j, g:i a', $tz ) ) : '<span class="aso-muted">—</span>';
				?>
			</td>
			<td><a href="<?php echo esc_url( Util::church_admin_url( $r->church_id ) ); ?>"><?php echo esc_html( $church ? $church->name() : '#' . $r->church_id ); ?></a><br><span class="aso-muted"><?php echo esc_html( $church ? $church->outreach_email() : '' ); ?></span></td>
			<td><?php echo esc_html( $campaign_names[ (int) $r->campaign_id ] ?? '' ); ?></td>
			<td><?php echo esc_html( $step_label( $r->step_number ) ); ?></td>
			<td><?php echo Statuses::badge( 'queue', $r->status ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo $r->last_error ? '<br><small class="aso-error">' . esc_html( $r->last_error ) . '</small>' : ''; ?></td>
			<td class="aso-right aso-nowrap">
				<?php if ( in_array( $r->status, array( 'scheduled', 'pending', 'held' ), true ) ) : ?>
					<a class="button button-small" href="<?php echo esc_url( Admin::post_url( 'aso_queue_action', array( 'do' => 'send_now', 'id' => $r->id ) ) ); ?>" data-aso-confirm="<?php esc_attr_e( 'Send this email right now (outside the schedule)?', 'apostolic-spotlight-outreach' ); ?>"><?php esc_html_e( 'Send now', 'apostolic-spotlight-outreach' ); ?></a>
					<a class="button button-small" href="<?php echo esc_url( Admin::post_url( 'aso_queue_action', array( 'do' => 'cancel', 'id' => $r->id ) ) ); ?>"><?php esc_html_e( 'Cancel', 'apostolic-spotlight-outreach' ); ?></a>
				<?php endif; ?>
				<?php if ( in_array( $r->status, array( 'held', 'failed', 'canceled' ), true ) ) : ?>
					<a class="button button-small" href="<?php echo esc_url( Admin::post_url( 'aso_queue_action', array( 'do' => 'retry', 'id' => $r->id ) ) ); ?>"><?php esc_html_e( 'Retry', 'apostolic-spotlight-outreach' ); ?></a>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
