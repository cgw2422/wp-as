<?php
/**
 * Create / edit a campaign and its sequence.
 *
 * @package ApostolicSpotlight\Outreach
 */

use ApostolicSpotlight\Outreach\Admin\Admin;
use ApostolicSpotlight\Outreach\Campaigns;
use ApostolicSpotlight\Outreach\Templates;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
$campaign = $id ? Campaigns::find( $id ) : null;
$steps    = $campaign ? Campaigns::steps( $id ) : array_map(
	static function ( $s, $i ) {
		return (object) array_merge( $s, array( 'step_number' => $i + 1 ) );
	},
	Campaigns::default_steps(),
	array_keys( Campaigns::default_steps() )
);
$templates = Templates::all( Templates::sequence_categories() );
$start_at  = $campaign && $campaign->start_at ? Util::format( $campaign->start_at, 'Y-m-d\TH:i' ) : '';

Admin::header( $campaign ? __( 'Edit campaign', 'apostolic-spotlight-outreach' ) : __( 'New campaign', 'apostolic-spotlight-outreach' ) );

$tpl_select = static function ( $name, $selected ) use ( $templates ) {
	$html = '<select name="' . esc_attr( $name ) . '">';
	foreach ( $templates as $t ) {
		$html .= '<option value="' . esc_attr( $t->id ) . '"' . selected( (int) $selected, (int) $t->id, false ) . '>' . esc_html( $t->name ) . '</option>';
	}
	return $html . '</select>';
};
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aso-card aso-form-narrow">
	<input type="hidden" name="action" value="aso_campaign_save">
	<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
	<?php wp_nonce_field( 'aso_campaign_save' ); ?>

	<label class="aso-field"><span><?php esc_html_e( 'Campaign name', 'apostolic-spotlight-outreach' ); ?></span>
		<input type="text" name="name" required class="large-text" value="<?php echo esc_attr( $campaign ? $campaign->name : '' ); ?>" placeholder="<?php esc_attr_e( 'ALJC Ohio Spotlight Outreach', 'apostolic-spotlight-outreach' ); ?>">
	</label>
	<label class="aso-field"><span><?php esc_html_e( 'Description (internal)', 'apostolic-spotlight-outreach' ); ?></span>
		<textarea name="description" rows="2" class="large-text"><?php echo esc_textarea( $campaign ? $campaign->description : '' ); ?></textarea>
	</label>

	<fieldset class="aso-field">
		<span><?php esc_html_e( 'When should the first email go out?', 'apostolic-spotlight-outreach' ); ?></span>
		<label class="aso-radio"><input type="radio" name="start_mode" value="now" <?php checked( '' === $start_at ); ?>> <?php esc_html_e( 'As soon as the campaign is active (next open sending slot)', 'apostolic-spotlight-outreach' ); ?></label>
		<label class="aso-radio"><input type="radio" name="start_mode" value="scheduled" <?php checked( '' !== $start_at ); ?>> <?php esc_html_e( 'Not before:', 'apostolic-spotlight-outreach' ); ?>
			<input type="datetime-local" name="start_at" value="<?php echo esc_attr( $start_at ); ?>">
			<small class="aso-muted"><?php echo esc_html( wp_timezone_string() ); ?></small>
		</label>
	</fieldset>

	<h3><?php esc_html_e( 'Email sequence', 'apostolic-spotlight-outreach' ); ?></h3>
	<p class="aso-help"><?php esc_html_e( 'Each follow-up is sent only if there has been no reply, booking, pause, or “not interested” in the meantime. Website and Apostolic Grow templates cannot be used here – they are manual only.', 'apostolic-spotlight-outreach' ); ?></p>

	<div class="aso-steps" data-aso-steps>
		<?php foreach ( $steps as $i => $s ) : ?>
			<div class="aso-step-row" data-aso-step>
				<div class="aso-step-row__num"><?php echo esc_html( $i + 1 ); ?></div>
				<div class="aso-step-row__body">
					<label><?php esc_html_e( 'Template', 'apostolic-spotlight-outreach' ); ?> <?php echo $tpl_select( 'steps[' . $i . '][template_id]', $s->template_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
					<?php if ( 0 === $i ) : ?>
						<span class="aso-muted"><?php esc_html_e( 'Sent first (immediately or on the start date).', 'apostolic-spotlight-outreach' ); ?></span>
						<input type="hidden" name="steps[<?php echo esc_attr( $i ); ?>][delay_days]" value="0">
					<?php else : ?>
						<label><?php esc_html_e( 'Send', 'apostolic-spotlight-outreach' ); ?> <input type="number" min="1" max="60" name="steps[<?php echo esc_attr( $i ); ?>][delay_days]" value="<?php echo esc_attr( $s->delay_days ); ?>" class="small-text"> <?php esc_html_e( 'days after the previous email, if no response', 'apostolic-spotlight-outreach' ); ?></label>
						<label><input type="checkbox" name="steps[<?php echo esc_attr( $i ); ?>][reply_in_thread]" value="1" <?php checked( ! empty( $s->reply_in_thread ) ); ?>> <?php esc_html_e( 'Send as a reply in the same Gmail thread', 'apostolic-spotlight-outreach' ); ?></label>
						<label class="aso-link-danger"><input type="checkbox" name="steps[<?php echo esc_attr( $i ); ?>][remove]" value="1"> <?php esc_html_e( 'Remove step', 'apostolic-spotlight-outreach' ); ?></label>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<template data-aso-step-template>
		<div class="aso-step-row" data-aso-step>
			<div class="aso-step-row__num">__N__</div>
			<div class="aso-step-row__body">
				<label><?php esc_html_e( 'Template', 'apostolic-spotlight-outreach' ); ?> <?php echo $tpl_select( 'steps[__I__][template_id]', 0 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
				<label><?php esc_html_e( 'Send', 'apostolic-spotlight-outreach' ); ?> <input type="number" min="1" max="60" name="steps[__I__][delay_days]" value="7" class="small-text"> <?php esc_html_e( 'days after the previous email, if no response', 'apostolic-spotlight-outreach' ); ?></label>
				<label><input type="checkbox" name="steps[__I__][reply_in_thread]" value="1" checked> <?php esc_html_e( 'Send as a reply in the same Gmail thread', 'apostolic-spotlight-outreach' ); ?></label>
				<label class="aso-link-danger"><input type="checkbox" name="steps[__I__][remove]" value="1"> <?php esc_html_e( 'Remove step', 'apostolic-spotlight-outreach' ); ?></label>
			</div>
		</div>
	</template>
	<p><button type="button" class="button" data-aso-add-step><?php esc_html_e( '+ Add follow-up', 'apostolic-spotlight-outreach' ); ?></button></p>

	<p class="aso-actions">
		<button class="button button-primary button-large"><?php esc_html_e( 'Save campaign', 'apostolic-spotlight-outreach' ); ?></button>
		<a class="button button-large" href="<?php echo esc_url( Util::admin_url( 'aso-campaigns' ) ); ?>"><?php esc_html_e( 'Cancel', 'apostolic-spotlight-outreach' ); ?></a>
	</p>
	<?php if ( ! $campaign ) : ?>
		<p class="aso-help"><?php esc_html_e( 'New campaigns start as Draft. Add churches, preview the emails, then click Activate.', 'apostolic-spotlight-outreach' ); ?></p>
	<?php endif; ?>
</form>
