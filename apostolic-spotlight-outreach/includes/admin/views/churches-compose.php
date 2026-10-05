<?php
/**
 * Manual email composer (one church, one email, sent only when you click Send).
 *
 * @package ApostolicSpotlight\Outreach
 */

use ApostolicSpotlight\Outreach\Admin\Admin;
use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Google\Google_Oauth;
use ApostolicSpotlight\Outreach\Template_Renderer;
use ApostolicSpotlight\Outreach\Templates;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

$church = Church_Repository::find( isset( $_GET['church'] ) ? absint( $_GET['church'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
if ( ! $church ) {
	Admin::empty_state( __( 'Church not found', 'apostolic-spotlight-outreach' ) );
	return;
}
$tpl_id   = isset( $_GET['template'] ) ? absint( $_GET['template'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
$template = $tpl_id ? Templates::find( $tpl_id ) : null;
$rendered = $template ? Template_Renderer::render_template( $template, $church ) : array( 'subject' => '', 'body' => '', 'missing' => array(), 'unknown' => array() );

$recipients = array();
foreach ( array( 'pastor_email', 'church_email' ) as $f ) {
	$e = $church->get( $f );
	if ( is_email( $e ) ) {
		$recipients[ $e ] = $e . ' (' . ( 'pastor_email' === $f ? __( 'pastor', 'apostolic-spotlight-outreach' ) : __( 'church', 'apostolic-spotlight-outreach' ) ) . ')';
	}
}
?>
<p class="aso-breadcrumb"><a href="<?php echo esc_url( Util::church_admin_url( $church->id ) ); ?>">← <?php echo esc_html( $church->name() ); ?></a></p>
<?php
/* translators: %s: church name */
Admin::header( sprintf( __( 'Email %s', 'apostolic-spotlight-outreach' ), $church->name() ), array(), esc_html__( 'Sent once, from your Google Workspace account, and logged on the timeline.', 'apostolic-spotlight-outreach' ) );
?>

<div class="aso-columns">
	<div class="aso-columns__main">
		<form method="get" class="aso-inline-form">
			<input type="hidden" name="page" value="aso-churches">
			<input type="hidden" name="view" value="compose">
			<input type="hidden" name="church" value="<?php echo esc_attr( $church->id ); ?>">
			<label><?php esc_html_e( 'Start from template:', 'apostolic-spotlight-outreach' ); ?>
				<select name="template" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( '— Blank —', 'apostolic-spotlight-outreach' ); ?></option>
					<?php foreach ( Templates::categories() as $cat => $label ) : ?>
						<optgroup label="<?php echo esc_attr( $label ); ?>">
							<?php foreach ( Templates::all( array( $cat ) ) as $t ) : ?>
								<option value="<?php echo esc_attr( $t->id ); ?>" <?php selected( $tpl_id, (int) $t->id ); ?>><?php echo esc_html( $t->name ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
			</label>
		</form>

		<?php if ( $rendered['missing'] ) : ?>
			<p class="aso-callout aso-callout--amber">
				<?php
				/* translators: %s: variable list */
				echo esc_html( sprintf( __( 'Missing information for: %s. Edit the text below before sending.', 'apostolic-spotlight-outreach' ), implode( ', ', $rendered['missing'] ) ) );
				?>
			</p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aso-card">
			<input type="hidden" name="action" value="aso_send_email">
			<input type="hidden" name="church_id" value="<?php echo esc_attr( $church->id ); ?>">
			<input type="hidden" name="template_id" value="<?php echo esc_attr( $tpl_id ); ?>">
			<?php wp_nonce_field( 'aso_send_email' ); ?>
			<label class="aso-field"><span><?php esc_html_e( 'From', 'apostolic-spotlight-outreach' ); ?></span>
				<input type="text" disabled value="<?php echo esc_attr( Google_Oauth::is_connected() ? Google_Oauth::email() : __( 'Google not connected', 'apostolic-spotlight-outreach' ) ); ?>">
			</label>
			<label class="aso-field"><span><?php esc_html_e( 'To', 'apostolic-spotlight-outreach' ); ?></span>
				<input type="email" name="to" required list="aso-recipients" value="<?php echo esc_attr( $church->outreach_email() ); ?>">
				<datalist id="aso-recipients">
					<?php foreach ( $recipients as $email => $label ) : ?>
						<option value="<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</datalist>
			</label>
			<label class="aso-field"><span><?php esc_html_e( 'Subject', 'apostolic-spotlight-outreach' ); ?></span>
				<input type="text" name="subject" required value="<?php echo esc_attr( $rendered['subject'] ); ?>">
			</label>
			<label class="aso-field"><span><?php esc_html_e( 'Message', 'apostolic-spotlight-outreach' ); ?></span>
				<textarea name="body" rows="18" required><?php echo esc_textarea( $rendered['body'] ); ?></textarea>
			</label>
			<p>
				<button class="button button-primary button-large" <?php disabled( ! Google_Oauth::is_connected() ); ?> data-aso-confirm="<?php esc_attr_e( 'Send this email now?', 'apostolic-spotlight-outreach' ); ?>"><?php esc_html_e( 'Send email', 'apostolic-spotlight-outreach' ); ?></button>
			</p>
		</form>
	</div>
	<aside class="aso-columns__side">
		<div class="aso-card">
			<div class="aso-card__head"><h3><?php esc_html_e( 'A note on follow-ups', 'apostolic-spotlight-outreach' ); ?></h3></div>
			<p class="aso-help"><?php esc_html_e( 'Website and Apostolic Grow emails are always manual. Send one only when you have reviewed the church and genuinely believe it would help – the Spotlight itself must never feel like a sales call.', 'apostolic-spotlight-outreach' ); ?></p>
		</div>
	</aside>
</div>
