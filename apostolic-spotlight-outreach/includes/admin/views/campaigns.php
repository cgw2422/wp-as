<?php
/**
 * Campaign list.
 *
 * @package ApostolicSpotlight\Outreach
 */

use ApostolicSpotlight\Outreach\Admin\Admin;
use ApostolicSpotlight\Outreach\Campaigns;
use ApostolicSpotlight\Outreach\Statuses;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

$show_archived = ! empty( $_GET['archived'] ); // phpcs:ignore WordPress.Security.NonceVerification
$campaigns     = Campaigns::all( $show_archived );

Admin::header(
	__( 'Campaigns', 'apostolic-spotlight-outreach' ),
	array(
		array( 'label' => __( 'New Campaign', 'apostolic-spotlight-outreach' ), 'url' => Util::admin_url( 'aso-campaigns', array( 'view' => 'edit' ) ), 'primary' => true ),
		$show_archived
			? array( 'label' => __( 'Hide archived', 'apostolic-spotlight-outreach' ), 'url' => Util::admin_url( 'aso-campaigns' ) )
			: array( 'label' => __( 'Show archived', 'apostolic-spotlight-outreach' ), 'url' => Util::admin_url( 'aso-campaigns', array( 'archived' => 1 ) ) ),
	),
	esc_html__( 'A campaign is a group of churches receiving the same personal invitation sequence. Sends are spread out by the queue.', 'apostolic-spotlight-outreach' )
);

if ( ! $campaigns ) {
	Admin::empty_state(
		__( 'No campaigns yet', 'apostolic-spotlight-outreach' ),
		__( 'Create a campaign such as “ALJC Ohio Spotlight Outreach”, then add churches from the Churches screen.', 'apostolic-spotlight-outreach' ),
		array( 'label' => __( 'Create your first campaign', 'apostolic-spotlight-outreach' ), 'url' => Util::admin_url( 'aso-campaigns', array( 'view' => 'edit' ) ) )
	);
	return;
}
?>
<div class="aso-grid aso-grid--cards">
	<?php foreach ( $campaigns as $c ) : ?>
		<?php $s = Campaigns::stats( $c->id ); ?>
		<div class="aso-card aso-campaign">
			<div class="aso-card__head">
				<h3><a href="<?php echo esc_url( Util::admin_url( 'aso-campaigns', array( 'view' => 'view', 'id' => $c->id ) ) ); ?>"><?php echo esc_html( $c->name ); ?></a></h3>
				<?php echo Statuses::badge( 'campaign', $c->status ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
			<?php if ( $c->description ) : ?><p class="aso-muted"><?php echo esc_html( wp_trim_words( $c->description, 20 ) ); ?></p><?php endif; ?>
			<div class="aso-mini-stats">
				<div><strong><?php echo esc_html( $s['enrolled'] ); ?></strong><span><?php esc_html_e( 'churches', 'apostolic-spotlight-outreach' ); ?></span></div>
				<div><strong><?php echo esc_html( $s['sent'] ); ?></strong><span><?php esc_html_e( 'sent', 'apostolic-spotlight-outreach' ); ?></span></div>
				<div><strong><?php echo esc_html( $s['queued'] ); ?></strong><span><?php esc_html_e( 'queued', 'apostolic-spotlight-outreach' ); ?></span></div>
				<div><strong><?php echo esc_html( $s['replied'] ); ?></strong><span><?php esc_html_e( 'replied', 'apostolic-spotlight-outreach' ); ?></span></div>
				<div><strong><?php echo esc_html( $s['booked'] ); ?></strong><span><?php esc_html_e( 'booked', 'apostolic-spotlight-outreach' ); ?></span></div>
			</div>
			<div class="aso-card__foot">
				<a class="button" href="<?php echo esc_url( Util::admin_url( 'aso-campaigns', array( 'view' => 'view', 'id' => $c->id ) ) ); ?>"><?php esc_html_e( 'Open', 'apostolic-spotlight-outreach' ); ?></a>
				<a class="button" href="<?php echo esc_url( Util::admin_url( 'aso-churches', array( 'campaign_id' => $c->id ) ) ); ?>"><?php esc_html_e( 'Churches', 'apostolic-spotlight-outreach' ); ?></a>
				<?php if ( in_array( $c->status, array( 'draft', 'paused' ), true ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( Admin::post_url( 'aso_campaign_status', array( 'id' => $c->id, 'status' => 'active' ) ) ); ?>"><?php esc_html_e( 'Activate', 'apostolic-spotlight-outreach' ); ?></a>
				<?php elseif ( 'active' === $c->status ) : ?>
					<a class="button" href="<?php echo esc_url( Admin::post_url( 'aso_campaign_status', array( 'id' => $c->id, 'status' => 'paused' ) ) ); ?>"><?php esc_html_e( 'Pause', 'apostolic-spotlight-outreach' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	<?php endforeach; ?>
</div>
