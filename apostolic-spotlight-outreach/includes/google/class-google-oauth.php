<?php
/**
 * Google OAuth 2.0 (web server flow) for a single connected Google Workspace account.
 *
 * Flow:
 *   Settings → "Connect Google" → admin-post.php?action=aso_google_connect (nonce + cap)
 *   → accounts.google.com consent (access_type=offline, state=random, include_granted_scopes)
 *   → admin-post.php?action=aso_google_callback (state + cap verified) → token exchange
 *   → tokens encrypted with Crypto and stored in a non-autoloaded option.
 *
 * Access tokens are refreshed automatically (Google_Client::access_token()).
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach\Google;

use ApostolicSpotlight\Outreach\Capabilities;
use ApostolicSpotlight\Outreach\Crypto;
use ApostolicSpotlight\Outreach\Settings;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

final class Google_Oauth {

	const TOKENS_OPTION  = 'aso_google_tokens';
	const ACCOUNT_OPTION = 'aso_google_account';
	const SECRET_OPTION  = 'aso_google_client_secret';

	const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

	const SCOPE_SEND      = 'https://www.googleapis.com/auth/gmail.send';
	const SCOPE_METADATA  = 'https://www.googleapis.com/auth/gmail.metadata';
	const SCOPE_EVENTS    = 'https://www.googleapis.com/auth/calendar.events';
	const SCOPE_FREEBUSY  = 'https://www.googleapis.com/auth/calendar.freebusy';

	public static function init() {
		add_action( 'admin_post_aso_google_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_aso_google_callback', array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_aso_google_disconnect', array( __CLASS__, 'handle_disconnect' ) );
	}

	/**
	 * Least-privileged scope set.
	 *
	 * Base (always):
	 *   openid email      – identify which account is connected
	 *   gmail.send        – send only; cannot read the mailbox
	 *   calendar.events   – create/update/cancel interview events with Meet
	 *   calendar.freebusy – read busy/free blocks only (no event details)
	 * Optional (only when reply detection is enabled):
	 *   gmail.metadata    – headers/labels only (From, Subject, thread IDs), never message bodies
	 */
	public static function scopes( $with_reply_detection = null ) {
		if ( null === $with_reply_detection ) {
			$with_reply_detection = (bool) Settings::get( 'replies.enabled' );
		}
		$scopes = array( 'openid', 'email', self::SCOPE_SEND, self::SCOPE_EVENTS, self::SCOPE_FREEBUSY );
		if ( $with_reply_detection ) {
			$scopes[] = self::SCOPE_METADATA;
		}
		return $scopes;
	}

	public static function client_id() {
		if ( defined( 'ASO_GOOGLE_CLIENT_ID' ) && ASO_GOOGLE_CLIENT_ID ) {
			return ASO_GOOGLE_CLIENT_ID;
		}
		return (string) Settings::get( 'google.client_id' );
	}

	public static function client_secret() {
		if ( defined( 'ASO_GOOGLE_CLIENT_SECRET' ) && ASO_GOOGLE_CLIENT_SECRET ) {
			return ASO_GOOGLE_CLIENT_SECRET;
		}
		$secret = Crypto::decrypt( get_option( self::SECRET_OPTION, '' ) );
		return $secret ? $secret : '';
	}

	public static function credentials_from_constants() {
		return defined( 'ASO_GOOGLE_CLIENT_ID' ) && ASO_GOOGLE_CLIENT_ID && defined( 'ASO_GOOGLE_CLIENT_SECRET' ) && ASO_GOOGLE_CLIENT_SECRET;
	}

	public static function save_client_secret( $secret ) {
		update_option( self::SECRET_OPTION, Crypto::encrypt( $secret ), false );
	}

	public static function has_credentials() {
		return '' !== self::client_id() && '' !== self::client_secret();
	}

	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=aso_google_callback' );
	}

	/* ------------------------------------------------------------------
	 * Token storage.
	 * ------------------------------------------------------------------ */

	/**
	 * @return array{access_token:string,refresh_token:string,expires_at:int,scope:string}|null
	 */
	public static function tokens() {
		$plain = Crypto::decrypt( get_option( self::TOKENS_OPTION, '' ) );
		if ( ! $plain ) {
			return null;
		}
		$data = json_decode( $plain, true );
		return is_array( $data ) ? $data : null;
	}

	public static function store_tokens( array $tokens ) {
		update_option( self::TOKENS_OPTION, Crypto::encrypt( wp_json_encode( $tokens ) ), false );
	}

	public static function account() {
		$a = get_option( self::ACCOUNT_OPTION, array() );
		return is_array( $a ) ? $a : array();
	}

	public static function is_connected() {
		$t = self::tokens();
		return $t && ! empty( $t['refresh_token'] );
	}

	public static function email() {
		$a = self::account();
		return isset( $a['email'] ) ? $a['email'] : '';
	}

	public static function granted_scopes() {
		$t = self::tokens();
		return $t && ! empty( $t['scope'] ) ? explode( ' ', $t['scope'] ) : array();
	}

	public static function has_scope( $scope ) {
		return in_array( $scope, self::granted_scopes(), true );
	}

	public static function mark_error( $message ) {
		$a               = self::account();
		$a['last_error'] = $message;
		$a['error_at']   = time();
		update_option( self::ACCOUNT_OPTION, $a, false );
	}

	public static function clear_error() {
		$a = self::account();
		if ( isset( $a['last_error'] ) ) {
			unset( $a['last_error'], $a['error_at'] );
			update_option( self::ACCOUNT_OPTION, $a, false );
		}
	}

	/* ------------------------------------------------------------------
	 * Handlers.
	 * ------------------------------------------------------------------ */

	public static function connect_url( $with_reply_detection = null ) {
		$args = array( 'action' => 'aso_google_connect' );
		if ( $with_reply_detection ) {
			$args['replies'] = 1;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'aso_google_connect' );
	}

	public static function handle_connect() {
		if ( ! current_user_can( Capabilities::SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to connect Google.', 'apostolic-spotlight-outreach' ), 403 );
		}
		check_admin_referer( 'aso_google_connect' );
		if ( ! self::has_credentials() ) {
			self::back( 'error', __( 'Enter your Google OAuth Client ID and Client Secret first.', 'apostolic-spotlight-outreach' ) );
		}
		$with_replies = ! empty( $_GET['replies'] ) || Settings::get( 'replies.enabled' );
		$state        = Util::token( 24 );
		set_transient( 'aso_oauth_state_' . get_current_user_id(), array( 'state' => $state, 'replies' => $with_replies ), 15 * MINUTE_IN_SECONDS );

		$url = add_query_arg(
			array(
				'client_id'              => rawurlencode( self::client_id() ),
				'redirect_uri'           => rawurlencode( self::redirect_uri() ),
				'response_type'          => 'code',
				'scope'                  => rawurlencode( implode( ' ', self::scopes( $with_replies ) ) ),
				'access_type'            => 'offline',
				'include_granted_scopes' => 'true',
				'prompt'                 => 'consent',
				'state'                  => rawurlencode( $state ),
			),
			self::AUTH_URL
		);
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external Google URL.
		exit;
	}

	public static function handle_callback() {
		if ( ! current_user_can( Capabilities::SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to connect Google.', 'apostolic-spotlight-outreach' ), 403 );
		}
		$saved = get_transient( 'aso_oauth_state_' . get_current_user_id() );
		delete_transient( 'aso_oauth_state_' . get_current_user_id() );
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! is_array( $saved ) || ! $state || ! hash_equals( $saved['state'], $state ) ) {
			self::back( 'error', __( 'Google connection failed: invalid or expired state. Please try again.', 'apostolic-spotlight-outreach' ) );
		}
		if ( ! empty( $_GET['error'] ) ) {
			/* translators: %s: error code from Google */
			self::back( 'error', sprintf( __( 'Google returned an error: %s', 'apostolic-spotlight-outreach' ), sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) );
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( ! $code ) {
			self::back( 'error', __( 'Google did not return an authorization code.', 'apostolic-spotlight-outreach' ) );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'code'          => $code,
					'client_id'     => self::client_id(),
					'client_secret' => self::client_secret(),
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		$data = self::decode( $response );
		if ( is_wp_error( $data ) ) {
			self::back( 'error', $data->get_error_message() );
		}

		$existing = self::tokens();
		$refresh  = ! empty( $data['refresh_token'] ) ? $data['refresh_token'] : ( $existing['refresh_token'] ?? '' );
		if ( ! $refresh ) {
			self::back( 'error', __( 'Google did not return a refresh token. Remove the app from your Google Account permissions and connect again.', 'apostolic-spotlight-outreach' ) );
		}
		self::store_tokens(
			array(
				'access_token'  => $data['access_token'],
				'refresh_token' => $refresh,
				'expires_at'    => time() + (int) $data['expires_in'],
				'scope'         => isset( $data['scope'] ) ? $data['scope'] : '',
			)
		);

		$email = self::email_from_id_token( isset( $data['id_token'] ) ? $data['id_token'] : '' );
		update_option(
			self::ACCOUNT_OPTION,
			array(
				'email'        => $email,
				'connected_at' => time(),
				'connected_by' => get_current_user_id(),
			),
			false
		);

		if ( ! empty( $saved['replies'] ) && self::has_scope( self::SCOPE_METADATA ) ) {
			$replies            = (array) Settings::get( 'replies' );
			$replies['enabled'] = 1;
			Settings::update_section( 'replies', $replies );
			\ApostolicSpotlight\Outreach\Reply_Detector::reset_cursor();
		}

		/* translators: %s: Google account email */
		self::back( 'success', sprintf( __( 'Connected to Google as %s.', 'apostolic-spotlight-outreach' ), $email ) );
	}

	public static function handle_disconnect() {
		if ( ! current_user_can( Capabilities::SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to disconnect Google.', 'apostolic-spotlight-outreach' ), 403 );
		}
		check_admin_referer( 'aso_google_disconnect' );
		$tokens = self::tokens();
		if ( $tokens && ! empty( $tokens['refresh_token'] ) ) {
			wp_remote_post( self::REVOKE_URL, array( 'timeout' => 10, 'body' => array( 'token' => $tokens['refresh_token'] ) ) );
		}
		delete_option( self::TOKENS_OPTION );
		delete_option( self::ACCOUNT_OPTION );
		$replies            = (array) Settings::get( 'replies' );
		$replies['enabled'] = 0;
		Settings::update_section( 'replies', $replies );
		self::back( 'success', __( 'Google account disconnected. Campaign sending is paused until you reconnect.', 'apostolic-spotlight-outreach' ) );
	}

	/**
	 * Exchange the refresh token for a new access token.
	 *
	 * @return array|\WP_Error
	 */
	public static function refresh() {
		$tokens = self::tokens();
		if ( ! $tokens || empty( $tokens['refresh_token'] ) ) {
			return new \WP_Error( 'aso_not_connected', __( 'Google account is not connected.', 'apostolic-spotlight-outreach' ) );
		}
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => self::client_id(),
					'client_secret' => self::client_secret(),
					'refresh_token' => $tokens['refresh_token'],
					'grant_type'    => 'refresh_token',
				),
			)
		);
		$data = self::decode( $response );
		if ( is_wp_error( $data ) ) {
			if ( 'invalid_grant' === $data->get_error_code() ) {
				// Revoked or expired consent: drop tokens so the UI shows "disconnected".
				delete_option( self::TOKENS_OPTION );
				self::mark_error( __( 'Google access was revoked or expired. Please reconnect.', 'apostolic-spotlight-outreach' ) );
			}
			return $data;
		}
		$tokens['access_token'] = $data['access_token'];
		$tokens['expires_at']   = time() + (int) $data['expires_in'];
		if ( ! empty( $data['scope'] ) ) {
			$tokens['scope'] = $data['scope'];
		}
		self::store_tokens( $tokens );
		self::clear_error();
		return $tokens;
	}

	/**
	 * @return array|\WP_Error
	 */
	private static function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$err  = is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : 'aso_token_error';
			$desc = is_array( $data ) && isset( $data['error_description'] ) ? (string) $data['error_description'] : __( 'Token request failed.', 'apostolic-spotlight-outreach' );
			return new \WP_Error( $err, $desc );
		}
		return $data;
	}

	/**
	 * The id_token comes straight from Google's token endpoint over TLS, so the
	 * payload can be read without signature verification (per OIDC spec 3.1.3.7).
	 */
	private static function email_from_id_token( $jwt ) {
		$parts = explode( '.', (string) $jwt );
		if ( count( $parts ) < 2 ) {
			return '';
		}
		$payload = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
		return is_array( $payload ) && ! empty( $payload['email'] ) ? sanitize_email( $payload['email'] ) : '';
	}

	private static function back( $type, $message ) {
		set_transient( 'aso_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 60 );
		wp_safe_redirect( Util::admin_url( 'aso-settings', array( 'tab' => 'google' ) ) );
		exit;
	}
}
