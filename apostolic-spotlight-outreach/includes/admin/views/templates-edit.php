<?php
/**
 * Template editor with variable reference and live preview against a real church.
 *
 * @package ApostolicSpotlight\Outreach
 */

use ApostolicSpotlight\Outreach\Admin\Admin;
use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Template_Renderer;
use ApostolicSpotlight\Outreach\Templates;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
$template = $id ? Templates::find( $id ) : null;
$preview  = isset( $_GET['preview_church'] ) ? Church_Repository::find( absint( $_GET['preview_church'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
if ( ! $preview ) {
	$sample  = Church_Repository::query( array( 'per_page' => 1 ) );
	$preview = $sample['ids'] ? Church_Repository::find( $sample['ids'][0] ) : null;
}
?>
<p class="aso-breadcrumb"><a href="<?php echo esc_url( Util::admin_url( 'aso-templates' ) ); ?>">← <?php esc_html_e( 'Email Templates', 'apostolic-spotlight-outreach' ); ?></a></p>
<?php Admin::header( $template ? $template->name : __( 'New template', 'apostolic-spotlight-outreach' ) ); ?>

<div class="aso-columns">
	<div class="aso-columns__main">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aso-card">
			<input type="hidden" name="action" value="aso_template_save">
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
			<?php wp_nonce_field( 'aso_template_save' ); ?>
			<div class="aso-form-grid">
				<label class="aso-field"><span><?php esc_html_e( 'Template name', 'apostolic-spotlight-outreach' ); ?></span>
					<input type="text" name="name" required value="<?php echo esc_attr( $template ? $template->name : '' ); ?>">
				</label>
				<label class="aso-field"><span><?php esc_html_e( 'Category', 'apostolic-spotlight-outreach' ); ?></span>
					<?php echo Admin::select( 'category', Templates::categories(), $template ? $template->category : 'general' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</label>
			</div>
			<label class="aso-field"><span><?php esc_html_e( 'Subject', 'apostolic-spotlight-outreach' ); ?></span>
				<input type="text" name="subject" required class="large-text" data-aso-var-target value="<?php echo esc_attr( $template ? $template->subject : '' ); ?>">
			</label>
			<label class="aso-field"><span><?php esc_html_e( 'Body (plain text – links become clickable automatically)', 'apostolic-spotlight-outreach' ); ?></span>
				<textarea name="body" rows="22" required class="large-text aso-mono" data-aso-var-target><?php echo esc_textarea( $template ? $template->body : '' ); ?></textarea>
			</label>
			<p><button class="button button-primary button-large"><?php esc_html_e( 'Save template', 'apostolic-spotlight-outreach' ); ?></button></p>
		</form>

		<?php if ( $template && $preview ) : ?>
			<?php $r = Template_Renderer::render_template( $template, $preview ); ?>
			<div class="aso-card aso-email-preview">
				<div class="aso-card__head">
					<h3>
						<?php
						/* translators: %s: church name */
						echo esc_html( sprintf( __( 'Preview with %s', 'apostolic-spotlight-outreach' ), $preview->name() ) );
						?>
					</h3>
					<form method="get" class="aso-inline-form">
						<input type="hidden" name="page" value="aso-templates"><input type="hidden" name="view" value="edit"><input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
						<input type="number" name="preview_church" placeholder="<?php esc_attr_e( 'Church ID', 'apostolic-spotlight-outreach' ); ?>" class="small-text">
						<button class="button button-small"><?php esc_html_e( 'Use church', 'apostolic-spotlight-outreach' ); ?></button>
					</form>
				</div>
				<?php if ( $r['missing'] ) : ?>
					<p class="aso-callout aso-callout--amber"><?php echo esc_html( sprintf( /* translators: %s: vars */ __( 'This church is missing: %s', 'apostolic-spotlight-outreach' ), implode( ', ', $r['missing'] ) ) ); ?></p>
				<?php endif; ?>
				<?php if ( $r['unknown'] ) : ?>
					<p class="aso-callout aso-callout--red"><?php echo esc_html( sprintf( /* translators: %s: vars */ __( 'Unknown variables: %s', 'apostolic-spotlight-outreach' ), implode( ', ', $r['unknown'] ) ) ); ?></p>
				<?php endif; ?>
				<dl class="aso-email-headers"><dt><?php esc_html_e( 'Subject', 'apostolic-spotlight-outreach' ); ?></dt><dd><strong><?php echo esc_html( $r['subject'] ); ?></strong></dd></dl>
				<div class="aso-email-render"><?php echo wp_kses_post( Template_Renderer::to_html( $r['body'] ) ); ?></div>
			</div>
		<?php endif; ?>
	</div>

	<aside class="aso-columns__side">
		<div class="aso-card">
			<div class="aso-card__head"><h3><?php esc_html_e( 'Variables', 'apostolic-spotlight-outreach' ); ?></h3></div>
			<p class="aso-help"><?php esc_html_e( 'Click to insert at the cursor. Add a fallback with a pipe: {{pastor_first_name|friend}}. Without a fallback, a missing value holds the email for review.', 'apostolic-spotlight-outreach' ); ?></p>
			<ul class="aso-vars">
				<?php foreach ( Template_Renderer::variables() as $var => $desc ) : ?>
					<li><button type="button" class="aso-var" data-aso-insert="{{<?php echo esc_attr( $var ); ?>}}">{{<?php echo esc_html( $var ); ?>}}</button><span><?php echo esc_html( $desc ); ?></span></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</aside>
</div>
