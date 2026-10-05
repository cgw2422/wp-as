<?php
/**
 * Main plugin bootstrap. Wires modules to WordPress hooks.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

use ApostolicSpotlight\Outreach\Admin\Admin;
use ApostolicSpotlight\Outreach\Admin\Admin_Actions;
use ApostolicSpotlight\Outreach\Admin\Metabox;
use ApostolicSpotlight\Outreach\Booking\Booking_Frontend;
use ApostolicSpotlight\Outreach\Google\Google_Oauth;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot() {
		Installer::maybe_upgrade();

		Cron::init();
		Booking_Frontend::init();
		Google_Oauth::init();

		if ( is_admin() ) {
			Admin::init();
			Admin_Actions::init();
			Metabox::init();
		}

		/**
		 * Fires once the outreach plugin has registered its modules.
		 * Future modules (SMS, Grow provisioning, ...) can hook here.
		 */
		do_action( 'aso_loaded' );
	}
}
