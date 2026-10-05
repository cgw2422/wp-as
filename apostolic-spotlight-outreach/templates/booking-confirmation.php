<?php
/**
 * Booking confirmation markup.
 *
 * @var object $booking
 * @package ApostolicSpotlight\Outreach
 */

use ApostolicSpotlight\Outreach\Booking\Booking_Frontend;
use ApostolicSpotlight\Outreach\Settings;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

$aso_tz      = \ApostolicSpotlight\Outreach\Util::timezone( $booking->attendee_timezone ? $booking->attendee_timezone : Settings::get( 'booking.timezone' ) );
$aso_owner   = Settings::booking_timezone();
$aso_start   = Util::from_db( $booking->start_at );
$aso_end     = Util::from_db( $booking->end_at );
$aso_ok      = 'confirmed' === $booking->status;
?>
<div class="aso-booking aso-booking--confirmed">
	<div class="aso-booking__card aso-booking__card--narrow">
		<section class="aso-booking__main">
			<?php if ( $aso_ok ) : ?>
				<div class="aso-confirm__icon" aria-hidden="true"></div>
				<h1 class="aso-booking__title"><?php esc_html_e( 'You’re scheduled!', 'apostolic-spotlight-outreach' ); ?></h1>
				<p class="aso-booking__text">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: email */
							__( 'A Google Calendar invitation with the Meet link has been sent to %s.', 'apostolic-spotlight-outreach' ),
							$booking->email
						)
					);
					?>
				</p>
			<?php else : ?>
				<h1 class="aso-booking__title"><?php esc_html_e( 'This interview is no longer scheduled', 'apostolic-spotlight-outreach' ); ?></h1>
				<p class="aso-booking__text"><a href="<?php echo esc_url( Settings::booking_url() ); ?>"><?php esc_html_e( 'Choose a new time', 'apostolic-spotlight-outreach' ); ?></a></p>
			<?php endif; ?>

			<dl class="aso-confirm">
				<dt><?php esc_html_e( 'What', 'apostolic-spotlight-outreach' ); ?></dt>
				<dd><?php echo esc_html( Settings::get( 'booking.meeting_name' ) ); ?> — <?php echo esc_html( $booking->church_name ); ?></dd>

				<dt><?php esc_html_e( 'Date', 'apostolic-spotlight-outreach' ); ?></dt>
				<dd><?php echo esc_html( wp_date( 'l, F j, Y', $aso_start->getTimestamp(), $aso_tz ) ); ?></dd>

				<dt><?php esc_html_e( 'Time', 'apostolic-spotlight-outreach' ); ?></dt>
				<dd>
					<?php echo esc_html( wp_date( 'g:i a', $aso_start->getTimestamp(), $aso_tz ) . ' – ' . wp_date( 'g:i a T', $aso_end->getTimestamp(), $aso_tz ) ); ?>
					<?php if ( $aso_tz->getName() !== $aso_owner->getName() ) : ?>
						<br><small><?php echo esc_html( sprintf( /* translators: %s: time */ __( '(%s for Apostolic Spotlight)', 'apostolic-spotlight-outreach' ), wp_date( 'g:i a T', $aso_start->getTimestamp(), $aso_owner ) ) ); ?></small>
					<?php endif; ?>
				</dd>

				<dt><?php esc_html_e( 'Time zone', 'apostolic-spotlight-outreach' ); ?></dt>
				<dd><?php echo esc_html( str_replace( '_', ' ', $aso_tz->getName() ) ); ?></dd>

				<?php if ( $aso_ok ) : ?>
					<dt><?php esc_html_e( 'Google Meet', 'apostolic-spotlight-outreach' ); ?></dt>
					<dd>
						<?php if ( $booking->meet_url ) : ?>
							<a href="<?php echo esc_url( $booking->meet_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( preg_replace( '#^https?://#', '', $booking->meet_url ) ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'The Meet link is in your calendar invitation.', 'apostolic-spotlight-outreach' ); ?>
						<?php endif; ?>
					</dd>
				<?php endif; ?>
			</dl>

			<?php if ( $aso_ok ) : ?>
				<div class="aso-confirm__actions">
					<a class="aso-button aso-button--ghost" href="<?php echo esc_url( Booking_Frontend::google_calendar_link( $booking ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Add to Google Calendar', 'apostolic-spotlight-outreach' ); ?></a>
					<a class="aso-button aso-button--ghost" href="<?php echo esc_url( rest_url( 'aso/v1/booking/' . $booking->public_id . '/ics' ) ); ?>"><?php esc_html_e( 'Download .ics (Outlook / Apple)', 'apostolic-spotlight-outreach' ); ?></a>
				</div>
				<p class="aso-booking__small"><?php esc_html_e( 'Need to change the time? Just reply to the calendar invitation and we’ll find another time.', 'apostolic-spotlight-outreach' ); ?></p>
			<?php endif; ?>
		</section>
	</div>
</div>
