<?php
/**
 * PSR-4-ish autoloader using WordPress file naming (class-foo-bar.php).
 *
 * ApostolicSpotlight\Outreach\Google\Google_Client => includes/google/class-google-client.php
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	const PREFIX = 'ApostolicSpotlight\\Outreach\\';

	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	public static function load( $class ) {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}
		$relative = substr( $class, strlen( self::PREFIX ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$dir      = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$file     = ASO_DIR . 'includes/' . $dir . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
