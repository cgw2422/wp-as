<?php
/**
 * Minimal authenticated JSON client for Google REST APIs using the WordPress HTTP API.
 * (No vendor SDK – keeps the plugin small and avoids dependency conflicts.)
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach\Google;

defined( 'ABSPATH' ) || exit;

final class Google_Client {

	/**
	 * Valid access token, refreshing when it expires within 60s.
	 *
	 * @return string|\WP_Error
	 */
	public static function access_token() {
		$tokens = Google_Oauth::tokens();
		if ( ! $tokens || empty( $tokens['refresh_token'] ) ) {
			return new \WP_Error( 'aso_not_connected', __( 'Google account is not connected.', 'apostolic-spotlight-outreach' ) );
		}
		if ( empty( $tokens['access_token'] ) || (int) $tokens['expires_at'] < time() + 60 ) {
			$tokens = Google_Oauth::refresh();
			if ( is_wp_error( $tokens ) ) {
				return $tokens;
			}
		}
		return $tokens['access_token'];
	}

	/**
	 * @param string     $method GET|POST|PATCH|DELETE|PUT
	 * @param string     $url    Absolute URL.
	 * @param array|null $body   JSON body.
	 * @param array      $query  Query args.
	 * @return array|\WP_Error Decoded JSON (empty array for 204).
	 */
	public static function request( $method, $url, $body = null, array $query = array(), $retry = true ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = '' === $raw ? array() : json_decode( $raw, true );

		if ( 401 === $code && $retry ) {
			// Token may have been revoked/rotated early – refresh once and retry.
			$refreshed = Google_Oauth::refresh();
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			return self::request( $method, $url, $body, array(), false );
		}
		if ( $code >= 400 ) {
			$message = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : sprintf( 'HTTP %d', $code );
			$reason  = is_array( $data ) && isset( $data['error']['errors'][0]['reason'] ) ? $data['error']['errors'][0]['reason'] : '';
			return new \WP_Error( 'aso_google_http_' . $code, $message, array( 'status' => $code, 'reason' => $reason, 'body' => $data ) );
		}
		return is_array( $data ) ? $data : array();
	}
}
