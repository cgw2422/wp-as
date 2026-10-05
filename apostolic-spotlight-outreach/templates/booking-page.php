<?php
/**
 * Public scheduling page markup.
 *
 * @var array $prefill
 * @package ApostolicSpotlight\Outreach
 */

use ApostolicSpotlight\Outreach\Settings;

defined( 'ABSPATH' ) || exit;

$aso_duration = (int) Settings::get( 'booking.duration', 15 );
?>
<div class="aso-booking" id="aso-booking">
	<div class="aso-booking__card">
		<aside class="aso-booking__intro">
			<p class="aso-booking__eyebrow"><?php esc_html_e( 'Apostolic Spotlight', 'apostolic-spotlight-outreach' ); ?></p>
			<h1 class="aso-booking__title"><?php echo esc_html( Settings::get( 'booking.meeting_name' ) ); ?></h1>
			<ul class="aso-booking__meta">
				<li class="aso-meta--time"><?php echo esc_html( sprintf( /* translators: %d: minutes */ _n( '%d minute', '%d minutes', $aso_duration, 'apostolic-spotlight-outreach' ), $aso_duration ) ); ?></li>
				<li class="aso-meta--video"><?php esc_html_e( 'Google Meet video call', 'apostolic-spotlight-outreach' ); ?></li>
				<?php if ( ! empty( $prefill['church_name'] ) ) : ?>
					<li class="aso-meta--church"><?php echo esc_html( $prefill['church_name'] ); ?></li>
				<?php endif; ?>
			</ul>
			<p class="aso-booking__text"><?php echo esc_html( Settings::get( 'booking.intro' ) ); ?></p>
			<?php if ( ! empty( $prefill['already_scheduled'] ) ) : ?>
				<p class="aso-booking__notice"><?php esc_html_e( 'It looks like an interview is already on the calendar for your church. If you need a different time, simply reply to your calendar invitation – or pick a new time below and we will sort it out.', 'apostolic-spotlight-outreach' ); ?></p>
			<?php endif; ?>
		</aside>

		<section class="aso-booking__main">
			<div class="aso-step" data-step="pick">
				<h2 class="aso-step__title"><?php esc_html_e( 'Select a date & time', 'apostolic-spotlight-outreach' ); ?></h2>
				<p class="aso-booking__tz"><span data-tz-label></span></p>
				<div class="aso-booking__status" data-status role="status" aria-live="polite"></div>
				<div class="aso-booking__picker">
					<div class="aso-days" data-days role="listbox" aria-label="<?php esc_attr_e( 'Available dates', 'apostolic-spotlight-outreach' ); ?>"></div>
					<div class="aso-times" data-times aria-live="polite"></div>
				</div>
			</div>

			<form class="aso-step aso-form" data-step="details" hidden novalidate>
				<button type="button" class="aso-link" data-back>&larr; <?php esc_html_e( 'Back', 'apostolic-spotlight-outreach' ); ?></button>
				<h2 class="aso-step__title"><?php esc_html_e( 'Your details', 'apostolic-spotlight-outreach' ); ?></h2>
				<p class="aso-form__selected" data-selected></p>

				<input type="hidden" name="token" value="<?php echo esc_attr( $prefill['token'] ); ?>">
				<input type="hidden" name="start" value="">
				<input type="hidden" name="timezone" value="">
				<div class="aso-hp" aria-hidden="true">
					<label><?php esc_html_e( 'Leave this empty', 'apostolic-spotlight-outreach' ); ?> <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
				</div>

				<label class="aso-field">
					<span><?php esc_html_e( 'Pastor name', 'apostolic-spotlight-outreach' ); ?> *</span>
					<input type="text" name="pastor_name" required maxlength="190" autocomplete="name" value="<?php echo esc_attr( $prefill['pastor_name'] ); ?>">
				</label>
				<label class="aso-field">
					<span><?php esc_html_e( 'Church name', 'apostolic-spotlight-outreach' ); ?> *</span>
					<input type="text" name="church_name" required maxlength="190" autocomplete="organization" value="<?php echo esc_attr( $prefill['church_name'] ); ?>">
				</label>
				<label class="aso-field">
					<span><?php esc_html_e( 'Email', 'apostolic-spotlight-outreach' ); ?> *</span>
					<input type="email" name="email" required maxlength="190" autocomplete="email" value="<?php echo esc_attr( $prefill['email'] ); ?>">
					<small><?php esc_html_e( 'Your Google Calendar invitation and Meet link will be sent here.', 'apostolic-spotlight-outreach' ); ?></small>
				</label>
				<label class="aso-field">
					<span><?php esc_html_e( 'Phone (optional)', 'apostolic-spotlight-outreach' ); ?></span>
					<input type="tel" name="phone" maxlength="64" autocomplete="tel" value="<?php echo esc_attr( $prefill['phone'] ); ?>">
				</label>
				<label class="aso-field">
					<span><?php esc_html_e( 'Anything you’d like me to know? (optional)', 'apostolic-spotlight-outreach' ); ?></span>
					<textarea name="notes" rows="3" maxlength="2000"></textarea>
				</label>
				<div class="aso-booking__status" data-form-status role="alert" aria-live="assertive"></div>
				<button type="submit" class="aso-button" data-submit><?php esc_html_e( 'Confirm interview', 'apostolic-spotlight-outreach' ); ?></button>
			</form>
		</section>
	</div>
</div>
