<?php
/**
 * Gmail API wrapper (send + optional metadata-only reply detection).
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach\Google;

use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

final class Gmail {

	const BASE = 'https://gmail.googleapis.com/gmail/v1/users/me';

	/**
	 * Build an RFC 5322 multipart/alternative message.
	 *
	 * @param array $m { from_name, from_email, to, to_name, subject, text, html, message_id, in_reply_to, references }
	 */
	public static function build_mime( array $m ) {
		$boundary = 'aso_' . bin2hex( random_bytes( 12 ) );
		$headers  = array();
		$headers[] = 'From: ' . self::address( $m['from_email'], $m['from_name'] ?? '' );
		$headers[] = 'To: ' . self::address( $m['to'], $m['to_name'] ?? '' );
		$headers[] = 'Subject: ' . self::encode_header( $m['subject'] );
		$headers[] = 'Date: ' . gmdate( 'D, d M Y H:i:s' ) . ' +0000';
		$headers[] = 'MIME-Version: 1.0';
		if ( ! empty( $m['message_id'] ) ) {
			$headers[] = 'Message-ID: ' . $m['message_id'];
		}
		if ( ! empty( $m['in_reply_to'] ) ) {
			$headers[] = 'In-Reply-To: ' . $m['in_reply_to'];
			$headers[] = 'References: ' . ( ! empty( $m['references'] ) ? $m['references'] : $m['in_reply_to'] );
		}
		$headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

		$body  = "--{$boundary}\r\n";
		$body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
		$body .= chunk_split( base64_encode( $m['text'] ) );
		$body .= "--{$boundary}\r\n";
		$body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
		$body .= chunk_split( base64_encode( $m['html'] ) );
		$body .= "--{$boundary}--";

		return implode( "\r\n", $headers ) . "\r\n\r\n" . $body;
	}

	private static function address( $email, $name ) {
		$email = sanitize_email( $email );
		if ( '' === trim( (string) $name ) ) {
			return $email;
		}
		return self::encode_header( $name ) . ' <' . $email . '>';
	}

	private static function encode_header( $value ) {
		$value = str_replace( array( "\r", "\n" ), ' ', (string) $value );
		if ( preg_match( '/[^\x20-\x7E]/', $value ) ) {
			return '=?UTF-8?B?' . base64_encode( $value ) . '?=';
		}
		return $value;
	}

	/**
	 * Send a raw MIME message. Lands in the account's Sent folder.
	 *
	 * @return array{id:string,threadId:string}|\WP_Error
	 */
	public static function send( $mime, $thread_id = '' ) {
		$body = array( 'raw' => Util::base64url_encode( $mime ) );
		if ( $thread_id ) {
			$body['threadId'] = $thread_id;
		}
		return Google_Client::request( 'POST', self::BASE . '/messages/send', $body );
	}

	public static function profile() {
		return Google_Client::request( 'GET', self::BASE . '/profile' );
	}

	/**
	 * history.list – supported by the gmail.metadata scope.
	 */
	public static function history( $start_history_id, $page_token = '' ) {
		$query = array(
			'startHistoryId' => $start_history_id,
			'historyTypes'   => 'messageAdded',
			'labelId'        => 'INBOX',
			'maxResults'     => 100,
		);
		if ( $page_token ) {
			$query['pageToken'] = $page_token;
		}
		return Google_Client::request( 'GET', self::BASE . '/history', null, $query );
	}

	private static function metadata_query( array $headers ) {
		$q = 'format=metadata';
		foreach ( $headers as $h ) {
			$q .= '&metadataHeaders=' . rawurlencode( $h );
		}
		return $q;
	}

	public static function reply_headers() {
		return array( 'From', 'Subject', 'Auto-Submitted', 'X-Autoreply', 'X-Autorespond', 'Precedence', 'Content-Type' );
	}

	public static function message_metadata( $id ) {
		return Google_Client::request( 'GET', self::BASE . '/messages/' . rawurlencode( $id ) . '?' . self::metadata_query( self::reply_headers() ) );
	}

	public static function thread_metadata( $thread_id ) {
		return Google_Client::request( 'GET', self::BASE . '/threads/' . rawurlencode( $thread_id ) . '?' . self::metadata_query( self::reply_headers() ) );
	}

	/**
	 * Header lookup on a Gmail message resource.
	 */
	public static function header( array $message, $name ) {
		$headers = isset( $message['payload']['headers'] ) ? $message['payload']['headers'] : array();
		foreach ( $headers as $h ) {
			if ( 0 === strcasecmp( $h['name'], $name ) ) {
				return (string) $h['value'];
			}
		}
		return '';
	}

	/**
	 * Extract the bare email from a From header.
	 */
	public static function parse_email( $from ) {
		if ( preg_match( '/<([^>]+)>/', $from, $m ) ) {
			return strtolower( trim( $m[1] ) );
		}
		return strtolower( trim( $from ) );
	}
}
