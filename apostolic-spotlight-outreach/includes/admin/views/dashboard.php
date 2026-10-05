<?php
/**
 * Dashboard.
 *
 * @package ApostolicSpotlight\Outreach
 */

use ApostolicSpotlight\Outreach\Admin\Admin;
use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Church\Outreach_State;
use ApostolicSpotlight\Outreach\Google\Google_Oauth;
use ApostolicSpotlight\Outreach\Metrics;
use ApostolicSpotlight\Outreach\Queue;
use ApostolicSpotlight\Outreach\Settings;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

$m     = Metrics::summary();
$tasks = Metrics::tasks();

$aso_church_name = static function ( $id ) {
	$c = Church_Repository::find( $id );
	return $c ? $c->name() : sprintf( '#%d', $id );
};

Admin::header(
	__( 'Spotlight Outreach', 'apostolic-spotlight-outreach' ),
	array(
		array( 'label' => __( 'Start Outreach', 'apostolic-spotlight-outreach' ), 'url' => Util::admin_url( 'aso-churches', array( 'spotlight_status' => 'not_contacted' ) ), 'primary' => true ),
		array( 'label' => __( 'New Campaign', 'apostolic-spotlight-outreach' ), 'url' => Util::admin_url( 'aso-campaigns', array( 'view' => 'edit' ) ) ),
		array( 'label' => __( 'Booking Page', 'apostolic-spotlight-outreach' ), 'url' => Settings::booking_url() ),
	),
	Google_Oauth::is_connected()
		/* translators: %s: email */
		? sprintf( esc_html__( 'Sending as %s', 'apostolic-spotlight-outreach' ), '<strong>' . esc_html( Google_Oauth::email() ) . '</strong>' ) . ( Settings::get( 'sending.paused' ) ? ' · <span class="aso-badge aso-badge--amber">' . esc_html__( 'Sending paused', 'apostolic-spotlight-outreach' ) . '</span>' : '' )
		: esc_html__( 'Google not connected', 'apostolic-spotlight-outreach' )
);

$cards = array(
	array( __( 'Contacted this week', 'apostolic-spotlight-outreach' ), $m['contacted_week'], 'blue', Util::admin_url( 'aso-churches', array( 'contacted' => 'yes', 'orderby' => 'last_contacted', 'order' => 'desc' ) ) ),
	array( __( 'Contacted this month', 'apostolic-spotlight-outreach' ), $m['contacted_month'], 'blue', null ),
	/* translators: %d: total emails */
	array( __( 'Emails sent this month', 'apostolic-spotlight-outreach' ), $m['emails_month'], 'slate', null, sprintf( __( '%d all time', 'apostolic-spotlight-outreach' ), $m['emails_total'] ) ),
	/* translators: %d: replies this month */
	array( __( 'Replies', 'apostolic-spotlight-outreach' ), $m['replied'], 'amber', Util::admin_url( 'aso-churches', array( 'spotlight_status' => 'replied' ) ), sprintf( __( '%d this month', 'apostolic-spotlight-outreach' ), $m['replied_month'] ) ),
	array( __( 'Spotlights scheduled', 'apostolic-spotlight-outreach' ), $m['upcoming'], 'purple', Util::admin_url( 'aso-bookings' ) ),
	array( __( 'Spotlights completed', 'apostolic-spotlight-outreach' ), $m['completed'], 'green', Util::admin_url( 'aso-churches', array( 'spotlight_status' => 'completed' ) ) ),
	array( __( 'Spotlights published', 'apostolic-spotlight-outreach' ), $m['published'], 'green-strong', Util::admin_url( 'aso-churches', array( 'spotlight_status' => 'published' ) ) ),
);
$opp_cards = array(
	array( __( 'Website leads', 'apostolic-spotlight-outreach' ), $m['website_leads'], 'amber', Util::admin_url( 'aso-opportunities', array( 'tab' => 'website' ) ) ),
	array( __( 'Website customers', 'apostolic-spotlight-outreach' ), $m['website_customers'], 'green-strong', Util::admin_url( 'aso-churches', array( 'website_status' => 'won' ) ) ),
	array( __( 'Apostolic Grow leads', 'apostolic-spotlight-outreach' ), $m['grow_leads'], 'amber', Util::admin_url( 'aso-opportunities', array( 'tab' => 'grow' ) ) ),
	array( __( 'Apostolic Grow customers', 'apostolic-spotlight-outreach' ), $m['grow_customers'], 'green-strong', Util::admin_url( 'aso-churches', array( 'grow_status' => 'customer' ) ) ),
);

$aso_render_cards = static function ( $list ) {
	echo '<div class="aso-stats">';
	foreach ( $list as $c ) {
		$tag = $c[3] ? 'a' : 'div';
		printf(
			'<%1$s class="aso-stat aso-stat--%2$s"%3$s><span class="aso-stat__value">%4$s</span><span class="aso-stat__label">%5$s</span>%6$s</%1$s>',
			$tag, // phpcs:ignore WordPress.Security.EscapeOutput
			esc_attr( $c[2] ),
			$c[3] ? ' href="' . esc_url( $c[3] ) . '"' : '', // phpcs:ignore WordPress.Security.EscapeOutput
			esc_html( number_format_i18n( $c[1] ) ),
			esc_html( $c[0] ),
			! empty( $c[4] ) ? '<span class="aso-stat__sub">' . esc_html( $c[4] ) . '</span>' : '' // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}
	echo '</div>';
};
?>

<h2 class="aso-section-title"><?php esc_html_e( 'Spotlight outreach', 'apostolic-spotlight-outreach' ); ?></h2>
<?php $aso_render_cards( $cards ); ?>

<h2 class="aso-section-title"><?php esc_html_e( 'Opportunities', 'apostolic-spotlight-outreach' ); ?></h2>
<?php $aso_render_cards( $opp_cards ); ?>

<h2 class="aso-section-title"><?php esc_html_e( 'Today’s tasks', 'apostolic-spotlight-outreach' ); ?></h2>
<div class="aso-grid aso-grid--3">

	<div class="aso-card">
		<div class="aso-card__head">
			<h3><span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e( 'Spotlight interviews today', 'apostolic-spotlight-outreach' ); ?></h3>
		</div>
		<?php if ( $tasks['interviews'] ) : ?>
			<ul class="aso-list">
				<?php foreach ( $tasks['interviews'] as $b ) : ?>
					<li>
						<strong><?php echo esc_html( Util::format( $b->start_at, get_option( 'time_format' ) ) ); ?></strong>
						— <?php echo esc_html( $b->church_name ); ?>
						<span class="aso-list__actions">
							<?php if ( $b->meet_url ) : ?><a class="button button-small button-primary" href="<?php echo esc_url( $b->meet_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Join Meet', 'apostolic-spotlight-outreach' ); ?></a><?php endif; ?>
							<?php if ( $b->church_id ) : ?><a class="button button-small" href="<?php echo esc_url( Util::church_admin_url( $b->church_id ) ); ?>"><?php esc_html_e( 'Open Church', 'apostolic-spotlight-outreach' ); ?></a><?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="aso-muted"><?php esc_html_e( 'No interviews today.', 'apostolic-spotlight-outreach' ); ?></p>
		<?php endif; ?>
		<?php if ( $tasks['past_unmarked'] ) : ?>
			<p class="aso-callout aso-callout--amber">
				<?php
				/* translators: %d: count */
				echo esc_html( sprintf( _n( '%d past interview needs to be marked completed.', '%d past interviews need to be marked completed.', count( $tasks['past_unmarked'] ), 'apostolic-spotlight-outreach' ), count( $tasks['past_unmarked'] ) ) );
				?>
				<a href="<?php echo esc_url( Util::admin_url( 'aso-bookings', array( 'tab' => 'past' ) ) ); ?>"><?php esc_html_e( 'Review', 'apostolic-spotlight-outreach' ); ?></a>
			</p>
		<?php endif; ?>
		<?php if ( $tasks['unlinked'] ) : ?>
			<p class="aso-callout aso-callout--amber">
				<?php
				/* translators: %d: count */
				echo esc_html( sprintf( _n( '%d booking is not linked to a church.', '%d bookings are not linked to a church.', count( $tasks['unlinked'] ), 'apostolic-spotlight-outreach' ), count( $tasks['unlinked'] ) ) );
				?>
				<a href="<?php echo esc_url( Util::admin_url( 'aso-bookings', array( 'tab' => 'unlinked' ) ) ); ?>"><?php esc_html_e( 'Link now', 'apostolic-spotlight-outreach' ); ?></a>
			</p>
		<?php endif; ?>
	</div>

	<div class="aso-card">
		<div class="aso-card__head">
			<h3><span class="dashicons dashicons-email-alt"></span> <?php esc_html_e( 'Emails today', 'apostolic-spotlight-outreach' ); ?></h3>
			<a href="<?php echo esc_url( Util::admin_url( 'aso-queue' ) ); ?>"><?php esc_html_e( 'Queue', 'apostolic-spotlight-outreach' ); ?> →</a>
		</div>
		<p class="aso-kpi-line">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: sent 2: scheduled 3: limit */
					__( '%1$d sent · %2$d scheduled · limit %3$d/day', 'apostolic-spotlight-outreach' ),
					$tasks['sent_today'],
					count( $tasks['scheduled_today'] ),
					Queue::daily_limit()
				)
			);
			?>
		</p>
		<p class="aso-kpi-line">
			<?php
			/* translators: %d: count */
			echo esc_html( sprintf( __( '%d follow-up(s) due by end of today', 'apostolic-spotlight-outreach' ), $tasks['followups_due'] ) );
			?>
		</p>
		<?php if ( $tasks['scheduled_today'] ) : ?>
			<ul class="aso-list aso-list--compact">
				<?php foreach ( array_slice( $tasks['scheduled_today'], 0, 8 ) as $q ) : ?>
					<li><span class="aso-muted"><?php echo esc_html( Util::format( $q->scheduled_at, get_option( 'time_format' ), \ApostolicSpotlight\Outreach\Settings::sending_timezone() ) ); ?></span> <?php echo esc_html( $aso_church_name( $q->church_id ) ); ?> <span class="aso-muted">(<?php echo esc_html( 1 === (int) $q->step_number ? __( 'Invitation', 'apostolic-spotlight-outreach' ) : sprintf( /* translators: %d: n */ __( 'Follow-Up %d', 'apostolic-spotlight-outreach' ), $q->step_number - 1 ) ); ?>)</span></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( $tasks['problems'] ) : ?>
			<p class="aso-callout aso-callout--red">
				<?php
				/* translators: %d: count */
				echo esc_html( sprintf( __( '%d email(s) held or failed – needs attention.', 'apostolic-spotlight-outreach' ), $tasks['problems'] ) );
				?>
				<a href="<?php echo esc_url( Util::admin_url( 'aso-queue', array( 'status' => 'held' ) ) ); ?>"><?php esc_html_e( 'Review', 'apostolic-spotlight-outreach' ); ?></a>
			</p>
		<?php endif; ?>
	</div>

	<div class="aso-card">
		<div class="aso-card__head">
			<h3><span class="dashicons dashicons-format-chat"></span> <?php esc_html_e( 'Recently replied', 'apostolic-spotlight-outreach' ); ?></h3>
		</div>
		<?php if ( $tasks['recent_replies'] ) : ?>
			<ul class="aso-list">
				<?php foreach ( $tasks['recent_replies'] as $r ) : ?>
					<li>
						<a href="<?php echo esc_url( Util::church_admin_url( $r->church_id ) ); ?>"><?php echo esc_html( $aso_church_name( $r->church_id ) ); ?></a>
						<span class="aso-muted"><?php echo esc_html( Util::short( $r->replied_at ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="aso-muted"><?php esc_html_e( 'No replies in the last 14 days.', 'apostolic-spotlight-outreach' ); ?></p>
		<?php endif; ?>
	</div>
</div>

<div class="aso-card">
	<div class="aso-card__head">
		<h3><span class="dashicons dashicons-flag"></span> <?php esc_html_e( 'Churches needing manual review', 'apostolic-spotlight-outreach' ); ?> <span class="aso-count"><?php echo esc_html( number_format_i18n( $m['needs_review'] ) ); ?></span></h3>
		<a href="<?php echo esc_url( Util::admin_url( 'aso-churches', array( 'needs_review' => 1 ) ) ); ?>"><?php esc_html_e( 'View all', 'apostolic-spotlight-outreach' ); ?> →</a>
	</div>
	<?php if ( $tasks['needs_review'] ) : ?>
		<table class="widefat striped aso-table">
			<tbody>
			<?php foreach ( $tasks['needs_review'] as $r ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( Util::church_admin_url( $r->church_id ) ); ?>"><strong><?php echo esc_html( $aso_church_name( $r->church_id ) ); ?></strong></a></td>
					<td><?php echo \ApostolicSpotlight\Outreach\Statuses::badge( 'spotlight', $r->spotlight_status ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td><?php echo esc_html( Outreach_State::review_reason_label( $r->review_reason ) ); ?></td>
					<td class="aso-right"><a class="button button-small" href="<?php echo esc_url( Util::church_admin_url( $r->church_id ) ); ?>"><?php esc_html_e( 'Review', 'apostolic-spotlight-outreach' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p class="aso-muted"><?php esc_html_e( 'Nothing needs review.', 'apostolic-spotlight-outreach' ); ?></p>
	<?php endif; ?>
</div>
