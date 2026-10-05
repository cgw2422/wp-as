<?php
/**
 * Variable substitution for templates.
 *
 * Syntax: {{variable}} or {{variable|fallback text}}.
 * A variable used WITHOUT a fallback that resolves to an empty value is reported in
 * `missing` – the queue holds such emails for manual review instead of sending
 * "Pastor ," to a real person.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

use ApostolicSpotlight\Outreach\Church\Church;
use ApostolicSpotlight\Outreach\Church\Outreach_State;

defined( 'ABSPATH' ) || exit;

final class Template_Renderer {

	public static function variables() {
		return array(
			'church_name'       => __( 'Church name', 'apostolic-spotlight-outreach' ),
			'pastor_name'       => __( 'Pastor full name (titles like "Rev." removed)', 'apostolic-spotlight-outreach' ),
			'pastor_first_name' => __( 'Pastor first name', 'apostolic-spotlight-outreach' ),
			'pastor_last_name'  => __( 'Pastor last name', 'apostolic-spotlight-outreach' ),
			'city'              => __( 'City', 'apostolic-spotlight-outreach' ),
			'state'             => __( 'State', 'apostolic-spotlight-outreach' ),
			'organization'      => __( 'Denomination / organization', 'apostolic-spotlight-outreach' ),
			'church_website'    => __( 'Church website', 'apostolic-spotlight-outreach' ),
			'spotlight_url'     => __( 'Directory listing / Spotlight URL', 'apostolic-spotlight-outreach' ),
			'booking_url'       => __( 'Personal scheduling link for this church', 'apostolic-spotlight-outreach' ),
			'sender_name'       => __( 'Your name (Settings)', 'apostolic-spotlight-outreach' ),
			'sender_first_name' => __( 'Your first name', 'apostolic-spotlight-outreach' ),
		);
	}

	/**
	 * Build the variable context for a church.
	 */
	public static function context( Church $church, array $extra = array() ) {
		$pastor = self::clean_pastor_name( $church->get( 'pastor_name' ) );
		$parts  = preg_split( '/\s+/', $pastor );
		$sender = (string) Settings::get( 'sender_name' );
		$sparts = preg_split( '/\s+/', trim( $sender ) );

		$ctx = array(
			'church_name'       => $church->get( 'church_name' ),
			'pastor_name'       => $pastor,
			'pastor_first_name' => $pastor ? $parts[0] : '',
			'pastor_last_name'  => count( $parts ) > 1 ? end( $parts ) : '',
			'city'              => $church->get( 'city' ),
			'state'             => $church->get( 'state' ),
			'organization'      => $church->get( 'organization' ),
			'church_website'    => $church->get( 'website' ),
			'spotlight_url'     => $church->get( 'listing_url' ),
			'booking_url'       => Settings::booking_url( Outreach_State::booking_token( $church->id ) ),
			'sender_name'       => $sender,
			'sender_first_name' => $sparts ? $sparts[0] : '',
		);
		return apply_filters( 'aso_template_context', array_merge( $ctx, $extra ), $church );
	}

	/**
	 * Remove honorifics so "Pastor {{pastor_name}}" never reads "Pastor Rev. John Smith".
	 */
	public static function clean_pastor_name( $name ) {
		$name = trim( (string) $name );
		$name = preg_replace( '/^(pastor|pstr\.?|rev\.?|reverend|bishop|bp\.?|elder|dr\.?|apostle|minister)\s+/i', '', $name );
		$name = preg_replace( '/^(rev\.?|dr\.?)\s+/i', '', $name ); // "Pastor Rev. X" → "X".
		// Multiple pastors ("John & Jane Smith") stay as-is.
		return trim( $name );
	}

	/**
	 * @return array{text:string,missing:string[],unknown:string[]}
	 */
	public static function render( $text, array $ctx ) {
		$missing = array();
		$unknown = array();
		$out     = preg_replace_callback(
			'/\{\{\s*([a-z0-9_]+)\s*(?:\|([^}]*))?\}\}/i',
			static function ( $m ) use ( $ctx, &$missing, &$unknown ) {
				$key      = strtolower( $m[1] );
				$fallback = isset( $m[2] ) ? $m[2] : null;
				if ( ! array_key_exists( $key, $ctx ) ) {
					$unknown[] = $key;
					return null !== $fallback ? $fallback : '';
				}
				$value = trim( (string) $ctx[ $key ] );
				if ( '' === $value ) {
					if ( null === $fallback ) {
						$missing[] = $key;
					}
					return null !== $fallback ? $fallback : '';
				}
				return $value;
			},
			(string) $text
		);
		return array(
			'text'    => $out,
			'missing' => array_values( array_unique( $missing ) ),
			'unknown' => array_values( array_unique( $unknown ) ),
		);
	}

	/**
	 * Render subject + body for a template row and church.
	 *
	 * @return array{subject:string,body:string,missing:string[],unknown:string[]}
	 */
	public static function render_template( $template, Church $church, array $extra = array() ) {
		$ctx     = self::context( $church, $extra );
		$subject = self::render( $template->subject, $ctx );
		$body    = self::render( $template->body, $ctx );
		return array(
			'subject' => $subject['text'],
			'body'    => $body['text'],
			'missing' => array_values( array_unique( array_merge( $subject['missing'], $body['missing'] ) ) ),
			'unknown' => array_values( array_unique( array_merge( $subject['unknown'], $body['unknown'] ) ) ),
		);
	}

	/**
	 * Plain text → simple personal-looking HTML (no images, no tracking).
	 */
	public static function to_html( $text ) {
		$html = esc_html( $text );
		$html = make_clickable( $html );
		$html = wpautop( $html );
		return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222">' . $html . '</div>';
	}
}
