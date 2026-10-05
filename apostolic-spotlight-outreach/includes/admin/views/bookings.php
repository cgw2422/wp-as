<?php
/**
 * Bookings admin.
 *
 * @package ApostolicSpotlight\Outreach
 */

use ApostolicSpotlight\Outreach\Admin\Admin;
use ApostolicSpotlight\Outreach\Booking\Bookings;
use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Church\Outreach_State;
use ApostolicSpotlight\Outreach\Settings;
use ApostolicSpotlight\Outreach\Statuses;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'upcoming'; // phpcs:ignore WordPress.Security.NonceVerification
$tabs = array(
	'upcoming'  => __( 'Upcoming', 'apostolic-spotlight-outreach' ),
	'past'      => __( 'Past – mark outcome', 'apostolic-spotlight-outreach' ),
	'completed' => __( 'Completed', 'apostolic-spotlight-outreach' ),
	'canceled'  => __( 'Canceled', 'apostolic-spotlight-outreach' ),
	'unlinked'  => __( 'Unlinked', 'apostolic-spotlight-outreach' ),
);
if ( ! isset( $tabs[ $tab ] ) ) {
	$tab = 'upcoming';
}
$rows = Bookings::query( $tab, 200 );
$tz   = Settings::booking_timezone();

Admin::header(
	__( 'Bookings', 'apostolic-spotlight-outreach' ),
	array( array( 'label' => __( 'Open booking page', 'apostolic-spotlight-outreach' ), 'url' => Settings::booking_url() ) ),
	esc_html( sprintf( /* translators: %s: tz */ __( 'Times shown in %s', 'apostolic-spotlight-outreach' ), $tz->getName() ) )
);

echo '<nav class="aso-tabs">';
foreach ( $tabs as $k => $label ) {
	printf( '<a class="aso-tab %s" href="%s">%s <span class="aso-count">%s</span></a>', esc_attr( $k === $tab ? 'is-active' : '' ), esc_url( Util::admin_url( 'aso-bookings', array( 'tab' => $k ) ) ), esc_html( $label ), esc_html( number_format_i18n( Bookings::count( $k ) ) ) );
}
echo '</nav>';

if ( ! $rows ) {
	Admin::empty_state( __( 'No bookings here', 'apostolic-spotlight-outreach' ) );
	return;
}
$states = Outreach_State::get_many( array_filter( array_map( 'intval', wp_list_pluck( $rows, 'church_id' ) ) ) );
?>
<table class="widefat striped aso-table aso-bookings">
	<thead><tr>
		<th><?php esc_html_e( 'Date / time', 'apostolic-spotlight-outreach' ); ?></th>
		<th><?php esc_html_e( 'Church', 'apostolic-spotlight-outreach' ); ?></th>
		<th><?php esc_html_e( 'Pastor', 'apostolic-spotlight-outreach' ); ?></th>
		<th><?php esc_html_e( 'Email', 'apostolic-spotlight-outreach' ); ?></th>
		<th><?php esc_html_e( 'Google Meet', 'apostolic-spotlight-outreach' ); ?></th>
		<th><?php esc_html_e( 'Spotlight status', 'apostolic-spotlight-outreach' ); ?></th>
		<th><?php esc_html_e( 'Actions', 'apostolic-spotlight-outreach' ); ?></th>
	</tr></thead>
	<tbody>
	<?php foreach ( $rows as $b ) : ?>
		<?php
		$b      = Bookings::refresh_meet( $b );
		$church = $b->church_id ? Church_Repository::find( $b->church_id ) : null;
		?>
		<tr>
			<td class="aso-nowrap">
				<strong><?php echo esc_html( Util::format( $b->start_at, 'D, M j', $tz ) ); ?></strong><br>
				<?php echo esc_html( Util::format( $b->start_at, 'g:i a', $tz ) . ' – ' . Util::format( $b->end_at, 'g:i a T', $tz ) ); ?>
				<?php if ( 'canceled' === $tab || 'completed' === $tab ) : ?><br><?php echo Statuses::badge( 'booking', $b->status ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php endif; ?>
				<?php if ( $b->error ) : ?><br><small class="aso-error"><?php echo esc_html( $b->error ); ?></small><?php endif; ?>
			</td>
			<td>
				<?php if ( $church ) : ?>
					<a href="<?php echo esc_url( Util::church_admin_url( $church->id ) ); ?>"><strong><?php echo esc_html( $church->name() ); ?></strong></a><br><span class="aso-muted"><?php echo esc_html( $church->location() ); ?></span>
				<?php else : ?>
					<strong><?php echo esc_html( $b->church_name ); ?></strong><br><span class="aso-badge aso-badge--amber"><?php esc_html_e( 'Not linked', 'apostolic-spotlight-outreach' ); ?></span>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $b->pastor_name ); ?><?php echo $b->phone ? '<br><span class="aso-muted">' . esc_html( $b->phone ) . '</span>' : ''; ?></td>
			<td><a href="mailto:<?php echo esc_attr( $b->email ); ?>"><?php echo esc_html( $b->email ); ?></a></td>
			<td>
				<?php if ( $b->meet_url ) : ?>
					<a href="<?php echo esc_url( $b->meet_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( preg_replace( '#^https?://#', '', $b->meet_url ) ); ?></a>
				<?php else : ?>
					<span class="aso-muted">—</span>
				<?php endif; ?>
				<?php if ( $b->event_html_link ) : ?><br><a class="aso-muted" href="<?php echo esc_url( $b->event_html_link ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Calendar event', 'apostolic-spotlight-outreach' ); ?></a><?php endif; ?>
			</td>
			<td><?php echo $b->church_id && isset( $states[ (int) $b->church_id ] ) ? Statuses::badge( 'spotlight', $states[ (int) $b->church_id ]->spotlight_status ) : '<span class="aso-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
			<td class="aso-actions-cell">
				<?php if ( $church ) : ?>
					<a class="button button-small" href="<?php echo esc_url( Util::church_admin_url( $church->id ) ); ?>"><?php esc_html_e( 'Open Church', 'apostolic-spotlight-outreach' ); ?></a>
				<?php endif; ?>
				<?php if ( 'confirmed' === $b->status ) : ?>
					<?php if ( $b->meet_url && 'upcoming' === $tab ) : ?>
						<a class="button button-small button-primary" href="<?php echo esc_url( $b->meet_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Join Meet', 'apostolic-spotlight-outreach' ); ?></a>
					<?php endif; ?>
					<a class="button button-small" href="<?php echo esc_url( Admin::post_url( 'aso_booking_action', array( 'do' => 'completed', 'id' => $b->id ) ) ); ?>"><?php esc_html_e( 'Mark Spotlight Completed', 'apostolic-spotlight-outreach' ); ?></a>
					<?php if ( 'past' === $tab ) : ?>
						<a class="button button-small" href="<?php echo esc_url( Admin::post_url( 'aso_booking_action', array( 'do' => 'no_show', 'id' => $b->id ) ) ); ?>"><?php esc_html_e( 'No-show', 'apostolic-spotlight-outreach' ); ?></a>
					<?php else : ?>
						<details class="aso-inline-details">
							<summary class="button button-small"><?php esc_html_e( 'Reschedule', 'apostolic-spotlight-outreach' ); ?></summary>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="aso_booking_action"><input type="hidden" name="do" value="reschedule"><input type="hidden" name="id" value="<?php echo esc_attr( $b->id ); ?>">
								<?php wp_nonce_field( 'aso_booking_action' ); ?>
								<input type="datetime-local" name="new_start" required value="<?php echo esc_attr( Util::format( $b->start_at, 'Y-m-d\TH:i', $tz ) ); ?>">
								<button class="button button-small button-primary"><?php esc_html_e( 'Move', 'apostolic-spotlight-outreach' ); ?></button>
								<small class="aso-muted"><?php echo esc_html( $tz->getName() ); ?> · <?php esc_html_e( 'Google emails the pastor the update.', 'apostolic-spotlight-outreach' ); ?></small>
							</form>
						</details>
						<a class="button button-small aso-button-danger" href="<?php echo esc_url( Admin::post_url( 'aso_booking_action', array( 'do' => 'cancel', 'id' => $b->id ) ) ); ?>" data-aso-confirm="<?php esc_attr_e( 'Cancel this interview? Google Calendar will notify the pastor.', 'apostolic-spotlight-outreach' ); ?>"><?php esc_html_e( 'Cancel', 'apostolic-spotlight-outreach' ); ?></a>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( ! $church && in_array( $b->status, array( 'confirmed', 'completed' ), true ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aso-link-form">
						<input type="hidden" name="action" value="aso_booking_action"><input type="hidden" name="do" value="link"><input type="hidden" name="id" value="<?php echo esc_attr( $b->id ); ?>">
						<?php wp_nonce_field( 'aso_booking_action' ); ?>
						<?php
						$candidates = get_posts(
							array(
								'post_type'      => Church_Repository::post_type(),
								'post_status'    => Church_Repository::STATUSES,
								's'              => $b->church_name,
								'posts_per_page' => 15,
							)
						);
						?>
						<?php if ( $candidates ) : ?>
							<select name="church_id" required>
								<option value=""><?php esc_html_e( 'Link to church…', 'apostolic-spotlight-outreach' ); ?></option>
								<?php foreach ( $candidates as $p ) : ?>
									<option value="<?php echo esc_attr( $p->ID ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?> (#<?php echo esc_html( $p->ID ); ?>)</option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<input type="number" name="church_id" required class="small-text" placeholder="<?php esc_attr_e( 'Church ID', 'apostolic-spotlight-outreach' ); ?>">
						<?php endif; ?>
						<button class="button button-small"><?php esc_html_e( 'Link', 'apostolic-spotlight-outreach' ); ?></button>
					</form>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
