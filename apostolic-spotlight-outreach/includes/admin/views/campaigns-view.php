<?php
/**
 * Campaign detail.
 *
 * @package ApostolicSpotlight\Outreach
 */

use ApostolicSpotlight\Outreach\Admin\Admin;
use ApostolicSpotlight\Outreach\Campaigns;
use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Church\Outreach_State;
use ApostolicSpotlight\Outreach\Enrollments;
use ApostolicSpotlight\Outreach\Installer;
use ApostolicSpotlight\Outreach\Statuses;
use ApostolicSpotlight\Outreach\Templates;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

global $wpdb;
$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
$campaign = Campaigns::find( $id );
if ( ! $campaign ) {
	Admin::empty_state( __( 'Campaign not found', 'apostolic-spotlight-outreach' ) );
	return;
}
$stats = Campaigns::stats( $id );
$steps = Campaigns::steps( $id );

$filter   = isset( $_GET['en_status'] ) ? sanitize_key( $_GET['en_status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$paged    = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
$per_page = 100;
$e        = Installer::table( 'enrollments' );
$where    = $wpdb->prepare( 'campaign_id = %d', $id );
if ( $filter && Statuses::is_valid( 'enrollment', $filter ) ) {
	$where .= $wpdb->prepare( ' AND status = %s', $filter );
}
$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$e} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL
$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$e} WHERE {$where} ORDER BY enrolled_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, ( $paged - 1 ) * $per_page ) ); // phpcs:ignore WordPress.DB.PreparedSQL
$ids   = wp_list_pluck( $rows, 'church_id' );
if ( $ids ) {
	_prime_post_caches( array_map( 'intval', $ids ), false, true );
}
$states = Outreach_State::get_many( $ids );

$actions = array( array( 'label' => __( 'Edit', 'apostolic-spotlight-outreach' ), 'url' => Util::admin_url( 'aso-campaigns', array( 'view' => 'edit', 'id' => $id ) ) ) );
if ( in_array( $campaign->status, array( 'draft', 'paused' ), true ) ) {
	$actions[] = array( 'label' => __( 'Activate campaign', 'apostolic-spotlight-outreach' ), 'url' => Admin::post_url( 'aso_campaign_status', array( 'id' => $id, 'status' => 'active' ) ), 'primary' => true );
} elseif ( 'active' === $campaign->status ) {
	$actions[] = array( 'label' => __( 'Pause campaign', 'apostolic-spotlight-outreach' ), 'url' => Admin::post_url( 'aso_campaign_status', array( 'id' => $id, 'status' => 'paused' ) ) );
}
if ( 'archived' !== $campaign->status ) {
	$actions[] = array( 'label' => __( 'Add churches', 'apostolic-spotlight-outreach' ), 'url' => Util::admin_url( 'aso-churches', array( 'spotlight_status' => 'not_contacted' ) ) );
}
?>
<p class="aso-breadcrumb"><a href="<?php echo esc_url( Util::admin_url( 'aso-campaigns' ) ); ?>">← <?php esc_html_e( 'Campaigns', 'apostolic-spotlight-outreach' ); ?></a></p>
<?php Admin::header( $campaign->name, $actions, Statuses::badge( 'campaign', $campaign->status ) . ( $campaign->start_at ? ' · ' . esc_html( sprintf( /* translators: %s: date */ __( 'Starts %s', 'apostolic-spotlight-outreach' ), Util::format( $campaign->start_at ) ) ) : '' ) ); ?>

<div class="aso-stats aso-stats--small">
	<div class="aso-stat aso-stat--slate"><span class="aso-stat__value"><?php echo esc_html( $stats['enrolled'] ); ?></span><span class="aso-stat__label"><?php esc_html_e( 'Churches', 'apostolic-spotlight-outreach' ); ?></span></div>
	<div class="aso-stat aso-stat--blue"><span class="aso-stat__value"><?php echo esc_html( $stats['active'] ); ?></span><span class="aso-stat__label"><?php esc_html_e( 'Active sequences', 'apostolic-spotlight-outreach' ); ?></span></div>
	<div class="aso-stat aso-stat--blue"><span class="aso-stat__value"><?php echo esc_html( $stats['sent'] ); ?></span><span class="aso-stat__label"><?php esc_html_e( 'Emails sent', 'apostolic-spotlight-outreach' ); ?></span></div>
	<div class="aso-stat aso-stat--slate"><span class="aso-stat__value"><?php echo esc_html( $stats['queued'] ); ?></span><span class="aso-stat__label"><?php esc_html_e( 'In queue', 'apostolic-spotlight-outreach' ); ?></span></div>
	<div class="aso-stat aso-stat--amber"><span class="aso-stat__value"><?php echo esc_html( $stats['replied'] ); ?></span><span class="aso-stat__label"><?php esc_html_e( 'Replied', 'apostolic-spotlight-outreach' ); ?></span></div>
	<div class="aso-stat aso-stat--purple"><span class="aso-stat__value"><?php echo esc_html( $stats['booked'] ); ?></span><span class="aso-stat__label"><?php esc_html_e( 'Booked', 'apostolic-spotlight-outreach' ); ?></span></div>
</div>

<div class="aso-card">
	<div class="aso-card__head"><h3><?php esc_html_e( 'Sequence', 'apostolic-spotlight-outreach' ); ?></h3>
		<?php if ( $ids ) : ?>
			<a class="button" href="<?php echo esc_url( Util::admin_url( 'aso-campaigns', array( 'view' => 'preview', 'id' => $id, 'church' => (int) $ids[0] ) ) ); ?>"><?php esc_html_e( 'Preview personalized emails', 'apostolic-spotlight-outreach' ); ?></a>
		<?php endif; ?>
	</div>
	<ol class="aso-sequence">
		<?php foreach ( $steps as $s ) : ?>
			<?php $t = Templates::find( $s->template_id ); ?>
			<li>
				<strong><?php echo esc_html( 1 === (int) $s->step_number ? __( 'Initial email', 'apostolic-spotlight-outreach' ) : sprintf( /* translators: %d: n */ __( 'Follow-Up %d', 'apostolic-spotlight-outreach' ), $s->step_number - 1 ) ); ?></strong>
				— <?php echo esc_html( $t ? $t->name : __( '(missing template)', 'apostolic-spotlight-outreach' ) ); ?>
				<span class="aso-muted">
					<?php
					echo esc_html(
						1 === (int) $s->step_number
							? __( 'on start', 'apostolic-spotlight-outreach' )
							/* translators: %d: days */
							: sprintf( _n( '%d day after previous, if no response', '%d days after previous, if no response', $s->delay_days, 'apostolic-spotlight-outreach' ), $s->delay_days ) . ( $s->reply_in_thread ? ' · ' . __( 'same thread', 'apostolic-spotlight-outreach' ) : '' )
					);
					?>
				</span>
			</li>
		<?php endforeach; ?>
	</ol>
</div>

<div class="aso-card">
	<div class="aso-card__head">
		<h3><?php esc_html_e( 'Enrolled churches', 'apostolic-spotlight-outreach' ); ?></h3>
		<form method="get" class="aso-inline-form">
			<input type="hidden" name="page" value="aso-campaigns"><input type="hidden" name="view" value="view"><input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
			<?php echo Admin::select( 'en_status', array( '' => __( 'All', 'apostolic-spotlight-outreach' ) ) + Statuses::options( 'enrollment' ), $filter, 'onchange="this.form.submit()"' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</form>
	</div>
	<?php if ( $rows ) : ?>
		<table class="widefat striped aso-table">
			<thead><tr>
				<th><?php esc_html_e( 'Church', 'apostolic-spotlight-outreach' ); ?></th>
				<th><?php esc_html_e( 'Recipient', 'apostolic-spotlight-outreach' ); ?></th>
				<th><?php esc_html_e( 'Sequence', 'apostolic-spotlight-outreach' ); ?></th>
				<th><?php esc_html_e( 'Progress', 'apostolic-spotlight-outreach' ); ?></th>
				<th><?php esc_html_e( 'Spotlight', 'apostolic-spotlight-outreach' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<?php $church = Church_Repository::find( $r->church_id ); ?>
				<tr>
					<td><a href="<?php echo esc_url( Util::church_admin_url( $r->church_id ) ); ?>"><strong><?php echo esc_html( $church ? $church->name() : '#' . $r->church_id ); ?></strong></a><br><span class="aso-muted"><?php echo esc_html( $church ? $church->location() : '' ); ?></span></td>
					<td><?php echo esc_html( $church ? $church->outreach_email() : $r->recipient_email ); ?></td>
					<td><?php echo Statuses::badge( 'enrollment', $r->status ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo $r->stop_reason ? '<br><small class="aso-muted">' . esc_html( Enrollments::reason_label( $r->stop_reason ) ) . '</small>' : ''; ?></td>
					<td>
						<?php
						/* translators: 1: sent 2: total steps */
						echo esc_html( sprintf( __( '%1$d of %2$d sent', 'apostolic-spotlight-outreach' ), $r->current_step, count( $steps ) ) );
						if ( 'active' === $r->status && $r->next_step_due_at ) {
							/* translators: %s: date */
							echo '<br><small class="aso-muted">' . esc_html( sprintf( __( 'next due %s', 'apostolic-spotlight-outreach' ), Util::short( $r->next_step_due_at ) ) ) . '</small>';
						}
						?>
					</td>
					<td><?php echo Statuses::badge( 'spotlight', $states[ (int) $r->church_id ]->spotlight_status ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td class="aso-right">
						<a href="<?php echo esc_url( Util::admin_url( 'aso-campaigns', array( 'view' => 'preview', 'id' => $id, 'church' => $r->church_id ) ) ); ?>"><?php esc_html_e( 'Preview', 'apostolic-spotlight-outreach' ); ?></a>
						<?php if ( in_array( $r->status, array( 'active', 'paused', 'finished' ), true ) ) : ?>
							| <a class="aso-link-danger" href="<?php echo esc_url( Admin::post_url( 'aso_enrollment_remove', array( 'id' => $r->id ) ) ); ?>" data-aso-confirm="<?php esc_attr_e( 'Remove from this campaign and cancel pending emails?', 'apostolic-spotlight-outreach' ); ?>"><?php esc_html_e( 'Remove', 'apostolic-spotlight-outreach' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				)
			);
			echo '</div></div>';
		}
		?>
	<?php else : ?>
		<p class="aso-muted"><?php esc_html_e( 'No churches yet. Go to Churches, filter, select, and choose “Add to Campaign”.', 'apostolic-spotlight-outreach' ); ?></p>
	<?php endif; ?>
</div>

<?php if ( 'archived' !== $campaign->status ) : ?>
	<p><a class="aso-link-danger" href="<?php echo esc_url( Admin::post_url( 'aso_campaign_status', array( 'id' => $id, 'status' => 'archived' ) ) ); ?>" data-aso-confirm="<?php esc_attr_e( 'Archive this campaign? Active sequences will stop and pending emails will be canceled.', 'apostolic-spotlight-outreach' ); ?>"><?php esc_html_e( 'Archive campaign', 'apostolic-spotlight-outreach' ); ?></a></p>
<?php endif; ?>
