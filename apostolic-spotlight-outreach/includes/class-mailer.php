<?php
/**
 * Sends email through the connected Google Workspace account (Gmail API).
 *
 * Campaign/outreach emails NEVER fall back to PHP mail()/wp_mail(): if Google is
 * not connected, sending fails and the queue waits.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

use ApostolicSpotlight\Outreach\Church\Church;
use ApostolicSpotlight\Outreach\Church\Outreach_State;
use ApostolicSpotlight\Outreach\Google\Gmail;
use ApostolicSpotlight\Outreach\Google\Google_Oauth;

defined( 'ABSPATH' ) || exit;

final class Mailer {

	/**
	 * @param Church $church
	 * @param string $to
	 * @param string $subject
	 * @param string $text Plain text body.
	 * @param array  $opts {
	 *   kind (campaign|manual|website|grow), campaign_id, enrollment_id, queue_id, template_id,
	 *   step_number, thread_id, in_reply_to
	 * }
	 * @return array{log_id:int,message_id:string,thread_id:string,rfc_message_id:string}|\WP_Error
	 */
	public static function send( Church $church, $to, $subject, $text, array $opts = array() ) {
		if ( ! Google_Oauth::is_connected() ) {
			return new \WP_Error( 'aso_not_connected', __( 'Google account is not connected; email not sent.', 'apostolic-spotlight-outreach' ) );
		}
		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'aso_bad_recipient', __( 'Invalid recipient email address.', 'apostolic-spotlight-outreach' ) );
		}
		$from   = Google_Oauth::email();
		$domain = substr( strrchr( $from ? $from : 'aso@localhost', '@' ), 1 );
		$rfc_id = '<aso.' . str_replace( '-', '', wp_generate_uuid4() ) . '@' . $domain . '>';

		$mime = Gmail::build_mime(
			array(
				'from_email'  => $from,
				'from_name'   => (string) Settings::get( 'sender_name' ),
				'to'          => $to,
				'to_name'     => '',
				'subject'     => $subject,
				'text'        => $text,
				'html'        => Template_Renderer::to_html( $text ),
				'message_id'  => $rfc_id,
				'in_reply_to' => $opts['in_reply_to'] ?? '',
			)
		);

		$result = Gmail::send( $mime, $opts['thread_id'] ?? '' );
		// A stale thread id (thread deleted) – retry as a new thread.
		if ( is_wp_error( $result ) && ! empty( $opts['thread_id'] ) && 404 === (int) ( $result->get_error_data()['status'] ?? 0 ) ) {
			$result = Gmail::send( $mime );
		}

		$base = array(
			'church_id'     => $church->id,
			'campaign_id'   => $opts['campaign_id'] ?? null,
			'enrollment_id' => $opts['enrollment_id'] ?? null,
			'queue_id'      => $opts['queue_id'] ?? null,
			'template_id'   => $opts['template_id'] ?? null,
			'step_number'   => $opts['step_number'] ?? null,
			'kind'          => $opts['kind'] ?? 'campaign',
			'from_email'    => $from,
			'to_email'      => strtolower( $to ),
			'subject'       => $subject,
			'body'          => $text,
			'rfc_message_id'=> $rfc_id,
		);

		if ( is_wp_error( $result ) ) {
			$log_id = Email_Log::insert( array_merge( $base, array( 'status' => 'failed', 'error' => $result->get_error_message() ) ) );
			Activity::log(
				$church->id,
				'email_failed',
				/* translators: 1: subject 2: error */
				sprintf( __( '“%1$s” failed to send: %2$s', 'apostolic-spotlight-outreach' ), $subject, $result->get_error_message() ),
				array( 'log_id' => $log_id ),
				$opts['campaign_id'] ?? null
			);
			return $result;
		}

		$log_id = Email_Log::insert(
			array_merge(
				$base,
				array(
					'gmail_message_id' => (string) ( $result['id'] ?? '' ),
					'gmail_thread_id'  => (string) ( $result['threadId'] ?? '' ),
				)
			)
		);
		Outreach_State::touch_contacted( $church->id, Util::now_db() );

		$label = self::kind_label( $opts['kind'] ?? 'campaign', $opts['step_number'] ?? 0 );
		Activity::log(
			$church->id,
			'email_sent',
			/* translators: 1: email kind 2: subject 3: recipient */
			sprintf( __( '%1$s sent: “%2$s” to %3$s', 'apostolic-spotlight-outreach' ), $label, $subject, $to ),
			array(
				'log_id'      => $log_id,
				'template_id' => $opts['template_id'] ?? null,
				'thread_id'   => $result['threadId'] ?? '',
			),
			$opts['campaign_id'] ?? null
		);

		return array(
			'log_id'         => $log_id,
			'message_id'     => (string) ( $result['id'] ?? '' ),
			'thread_id'      => (string) ( $result['threadId'] ?? '' ),
			'rfc_message_id' => $rfc_id,
		);
	}

	public static function kind_label( $kind, $step = 0 ) {
		if ( 'campaign' === $kind ) {
			if ( 1 === (int) $step ) {
				return __( 'Initial Spotlight email', 'apostolic-spotlight-outreach' );
			}
			/* translators: %d: follow-up number */
			return sprintf( __( 'Follow-Up %d', 'apostolic-spotlight-outreach' ), max( 1, (int) $step - 1 ) );
		}
		$labels = array(
			'manual'  => __( 'Manual email', 'apostolic-spotlight-outreach' ),
			'website' => __( 'Website follow-up', 'apostolic-spotlight-outreach' ),
			'grow'    => __( 'Apostolic Grow follow-up', 'apostolic-spotlight-outreach' ),
		);
		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : __( 'Email', 'apostolic-spotlight-outreach' );
	}
}
