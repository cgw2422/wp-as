<?php
/**
 * Enrollment review: duplicate-protection warnings before adding churches to a campaign.
 *
 * @package ApostolicSpotlight\Outreach
 */

use ApostolicSpotlight\Outreach\Admin\Admin;
use ApostolicSpotlight\Outreach\Campaigns;
use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Church\Outreach_State;
use ApostolicSpotlight\Outreach\Enrollments;
use ApostolicSpotlight\Outreach\Statuses;
use ApostolicSpotlight\Outreach\Templates;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

$campaign = Campaigns::find( isset( $_GET['campaign'] ) ? absint( $_GET['campaign'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
$sel      = isset( $_GET['sel'] ) ? sanitize_key( wp_unslash( $_GET['sel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$ids      = array();
if ( $sel && 0 === strpos( $sel, get_current_user_id() . '_' ) ) {
	$ids = (array) get_transient( 'aso_sel_' . $sel );
}
if ( ! $campaign || ! $ids ) {
	Admin::empty_state( __( 'Nothing to enroll', 'apostolic-spotlight-outreach' ), __( 'The selection expired or the campaign was not found. Please select churches again.', 'apostolic-spotlight-outreach' ), array( 'label' => __( 'Back to churches', 'apostolic-spotlight-outreach' ), 'url' => Util::admin_url( 'aso-churches' ) ) );
	return;
}

$states = Outreach_State::get_many( $ids );
$rows   = array();
$tally  = array( 'ok' => 0, 'warn' => 0, 'blocked' => 0 );
foreach ( $ids as $id ) {
	$church = Church_Repository::find( $id );
	if ( ! $church ) {
		continue;
	}
	$check  = Enrollments::check( $church, $campaign->id, $states[ $id ] ?? null );
	$kind   = $check['blocking'] ? 'blocked' : ( $check['warnings'] ? 'warn' : 'ok' );
	++$tally[ $kind ];
	$rows[] = array( $church, $check, $kind );
}
usort(
	$rows,
	static function ( $a, $b ) {
		$order = array( 'warn' => 0, 'blocked' => 1, 'ok' => 2 );
		return $order[ $a[2] ] <=> $order[ $b[2] ];
	}
);
$steps = Campaigns::steps( $campaign->id );

/* translators: %s: campaign name */
Admin::header( sprintf( __( 'Add to “%s”', 'apostolic-spotlight-outreach' ), $campaign->name ), array(), esc_html__( 'Review safety checks before enrolling. Churches with warnings are skipped unless you explicitly override them.', 'apostolic-spotlight-outreach' ) );
?>
<div class="aso-stats aso-stats--small">
	<div class="aso-stat aso-stat--green"><span class="aso-stat__value"><?php echo esc_html( $tally['ok'] ); ?></span><span class="aso-stat__label"><?php esc_html_e( 'Ready', 'apostolic-spotlight-outreach' ); ?></span></div>
	<div class="aso-stat aso-stat--amber"><span class="aso-stat__value"><?php echo esc_html( $tally['warn'] ); ?></span><span class="aso-stat__label"><?php esc_html_e( 'Need override', 'apostolic-spotlight-outreach' ); ?></span></div>
	<div class="aso-stat aso-stat--red"><span class="aso-stat__value"><?php echo esc_html( $tally['blocked'] ); ?></span><span class="aso-stat__label"><?php esc_html_e( 'Cannot enroll', 'apostolic-spotlight-outreach' ); ?></span></div>
	<div class="aso-stat aso-stat--slate">
		<span class="aso-stat__label"><?php esc_html_e( 'Sequence', 'apostolic-spotlight-outreach' ); ?></span>
		<span class="aso-stat__sub">
			<?php
			$parts = array();
			foreach ( $steps as $s ) {
				$t       = Templates::find( $s->template_id );
				$parts[] = ( 1 === (int) $s->step_number ? __( 'Day 0', 'apostolic-spotlight-outreach' ) : '+' . $s->delay_days . 'd' ) . ': ' . ( $t ? $t->name : '?' );
			}
			echo esc_html( implode( ' → ', $parts ) );
			?>
		</span>
	</div>
</div>

<?php if ( 'active' !== $campaign->status ) : ?>
	<p class="aso-callout aso-callout--amber"><?php esc_html_e( 'This campaign is not active. Churches will be enrolled, but nothing sends until you activate the campaign.', 'apostolic-spotlight-outreach' ); ?></p>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="aso_enroll_confirm">
	<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign->id ); ?>">
	<?php wp_nonce_field( 'aso_enroll_confirm' ); ?>

	<table class="widefat striped aso-table">
		<thead>
			<tr>
				<th class="check-column"><input type="checkbox" data-aso-toggle-all="church_ids[]" checked></th>
				<th><?php esc_html_e( 'Church', 'apostolic-spotlight-outreach' ); ?></th>
				<th><?php esc_html_e( 'Will email', 'apostolic-spotlight-outreach' ); ?></th>
				<th><?php esc_html_e( 'Status', 'apostolic-spotlight-outreach' ); ?></th>
				<th><?php esc_html_e( 'Safety checks', 'apostolic-spotlight-outreach' ); ?></th>
				<th><?php esc_html_e( 'Preview', 'apostolic-spotlight-outreach' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $rows as $row ) : ?>
			<?php list( $church, $check, $kind ) = $row; ?>
			<tr class="aso-row--<?php echo esc_attr( $kind ); ?>">
				<th class="check-column">
					<?php if ( 'ok' === $kind ) : ?>
						<input type="checkbox" name="church_ids[]" value="<?php echo esc_attr( $church->id ); ?>" checked>
					<?php endif; ?>
				</th>
				<td>
					<a href="<?php echo esc_url( Util::church_admin_url( $church->id ) ); ?>" target="_blank"><strong><?php echo esc_html( $church->name() ); ?></strong></a><br>
					<span class="aso-muted"><?php echo esc_html( implode( ' · ', array_filter( array( $church->get( 'pastor_name' ), $church->location(), $church->get( 'organization' ) ) ) ) ); ?></span>
				</td>
				<td><?php echo $check['email'] ? esc_html( $check['email'] ) : '<span class="aso-missing">—</span>'; ?></td>
				<td><?php echo Statuses::badge( 'spotlight', ( $states[ $church->id ] ?? Outreach_State::defaults( $church->id ) )->spotlight_status ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
				<td>
					<?php foreach ( $check['blocking'] as $msg ) : ?>
						<div class="aso-check-item aso-check-item--red"><?php echo esc_html( $msg ); ?></div>
					<?php endforeach; ?>
					<?php foreach ( $check['warnings'] as $msg ) : ?>
						<div class="aso-check-item aso-check-item--amber"><?php echo esc_html( $msg ); ?></div>
					<?php endforeach; ?>
					<?php foreach ( $check['info'] as $msg ) : ?>
						<div class="aso-check-item aso-check-item--muted"><?php echo esc_html( $msg ); ?></div>
					<?php endforeach; ?>
					<?php if ( 'warn' === $kind ) : ?>
						<label class="aso-override"><input type="checkbox" name="override[]" value="<?php echo esc_attr( $church->id ); ?>"> <?php esc_html_e( 'Override and enroll anyway', 'apostolic-spotlight-outreach' ); ?></label>
					<?php elseif ( 'ok' === $kind && ! $check['info'] ) : ?>
						<span class="aso-check-item aso-check-item--green"><?php esc_html_e( 'Clear', 'apostolic-spotlight-outreach' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( 'blocked' !== $kind ) : ?>
						<a href="<?php echo esc_url( Util::admin_url( 'aso-campaigns', array( 'view' => 'preview', 'id' => $campaign->id, 'church' => $church->id ) ) ); ?>" target="_blank"><?php esc_html_e( 'Preview emails', 'apostolic-spotlight-outreach' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<p>
		<label><?php esc_html_e( 'Override note (optional, saved on overridden enrollments):', 'apostolic-spotlight-outreach' ); ?>
			<input type="text" name="override_note" class="regular-text" maxlength="150">
		</label>
	</p>
	<p class="aso-actions">
		<button class="button button-primary button-hero"><?php esc_html_e( 'Enroll selected churches', 'apostolic-spotlight-outreach' ); ?></button>
		<a class="button button-hero" href="<?php echo esc_url( Util::admin_url( 'aso-churches' ) ); ?>"><?php esc_html_e( 'Cancel', 'apostolic-spotlight-outreach' ); ?></a>
	</p>
</form>
