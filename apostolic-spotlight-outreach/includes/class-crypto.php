<?php
/**
 * Symmetric encryption for secrets at rest (OAuth refresh/access tokens, client secret).
 *
 * Uses libsodium secretbox (bundled with PHP 7.2+ and polyfilled by WordPress core).
 * Key: ASO_ENCRYPTION_KEY constant if defined in wp-config.php (recommended),
 * otherwise derived from the site's AUTH salts. Rotating the salts invalidates the
 * stored tokens (you simply reconnect Google).
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	const PREFIX = 'aso1:';

	private static function key() {
		$material = defined( 'ASO_ENCRYPTION_KEY' ) && ASO_ENCRYPTION_KEY
			? ASO_ENCRYPTION_KEY
			: wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return hash( 'sha256', 'aso-token-vault|' . $material, true );
	}

	public static function encrypt( $plain ) {
		if ( '' === $plain || null === $plain ) {
			return '';
		}
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( (string) $plain, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $cipher );
	}

	/**
	 * @return string|null Null when the value cannot be decrypted (e.g. salts rotated).
	 */
	public static function decrypt( $stored ) {
		if ( ! is_string( $stored ) || 0 !== strpos( $stored, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		return false === $plain ? null : $plain;
	}
}
