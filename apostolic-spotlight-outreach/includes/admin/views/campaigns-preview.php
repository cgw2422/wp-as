<?php
/**
 * Preview a campaign's personalized emails for one church.
 *
 * @package ApostolicSpotlight\Outreach
 */

use ApostolicSpotlight\Outreach\Admin\Admin;
use ApostolicSpotlight\Outreach\Campaigns;
use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Google\Google_Oauth;
use ApostolicSpotlight\Outreach\Settings;
use ApostolicSpotlight\Outreach\Template_Renderer;
use ApostolicSpotlight\Outreach\Templates;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

$campaign = Campaigns::find( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
$church   = Church_Repository::find( isset( $_GET['church'] ) ? absint( $_GET['church'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
if ( ! $campaign || ! $church ) {
	Admin::empty_state( __( 'Nothing to preview', 'apostolic-spotlight-outreach' ) );
	return;
}
$steps = Campaigns::steps( $campaign->id );
?>
<p class="aso-breadcrumb"><a href="<?php echo esc_url( Util::admin_url( 'aso-campaigns', array( 'view' => 'view', 'id' => $campaign->id ) ) ); ?>">← <?php echo esc_html( $campaign->name ); ?></a></p>
<?php
/* translators: %s: church */
Admin::header( sprintf( __( 'Preview for %s', 'apostolic-spotlight-outreach' ), $church->name() ), array( array( 'label' => __( 'Open church', 'apostolic-spotlight-outreach' ), 'url' => Util::church_admin_url( $church->id ) ) ) );

$first_subject = '';
foreach ( $steps as $s ) :
	$t = Templates::find( $s->template_id );
	if ( ! $t ) {
		continue;
	}
	$r       = Template_Renderer::render_template( $t, $church );
	$subject = $r['subject'];
	if ( 1 === (int) $s->step_number ) {
		$first_subject = $subject;
	} elseif ( $s->reply_in_thread && $first_subject ) {
		$subject = 'Re: ' . $first_subject;
	}
	?>
	<div class="aso-card aso-email-preview">
		<div class="aso-card__head">
			<h3><?php echo esc_html( 1 === (int) $s->step_number ? __( 'Initial email', 'apostolic-spotlight-outreach' ) : sprintf( /* translators: %1$d: n, %2$d: days */ __( 'Follow-Up %1$d (after %2$d days, if no response)', 'apostolic-spotlight-outreach' ), $s->step_number - 1, $s->delay_days ) ); ?></h3>
			<span class="aso-muted"><?php echo esc_html( $t->name ); ?> · <a href="<?php echo esc_url( Util::admin_url( 'aso-templates', array( 'view' => 'edit', 'id' => $t->id ) ) ); ?>"><?php esc_html_e( 'Edit template', 'apostolic-spotlight-outreach' ); ?></a></span>
		</div>
		<?php if ( $r['missing'] ) : ?>
			<p class="aso-callout aso-callout--amber">
				<?php
				/* translators: %s: variable list */
				echo esc_html( sprintf( __( 'Missing: %s – this email will be held for review instead of sending.', 'apostolic-spotlight-outreach' ), implode( ', ', $r['missing'] ) ) );
				?>
			</p>
		<?php endif; ?>
		<?php if ( $r['unknown'] ) : ?>
			<p class="aso-callout aso-callout--red">
				<?php
				/* translators: %s: variable list */
				echo esc_html( sprintf( __( 'Unknown variables (typo?): %s', 'apostolic-spotlight-outreach' ), implode( ', ', $r['unknown'] ) ) );
				?>
			</p>
		<?php endif; ?>
		<dl class="aso-email-headers">
			<dt><?php esc_html_e( 'From', 'apostolic-spotlight-outreach' ); ?></dt><dd><?php echo esc_html( Settings::get( 'sender_name' ) . ' <' . ( Google_Oauth::email() ? Google_Oauth::email() : __( 'not connected', 'apostolic-spotlight-outreach' ) ) . '>' ); ?></dd>
			<dt><?php esc_html_e( 'To', 'apostolic-spotlight-outreach' ); ?></dt><dd><?php echo esc_html( $church->outreach_email() ? $church->outreach_email() : '—' ); ?></dd>
			<dt><?php esc_html_e( 'Subject', 'apostolic-spotlight-outreach' ); ?></dt><dd><strong><?php echo esc_html( $subject ); ?></strong></dd>
		</dl>
		<div class="aso-email-render"><?php echo wp_kses_post( Template_Renderer::to_html( $r['body'] ) ); ?></div>
	</div>
<?php endforeach; ?>
