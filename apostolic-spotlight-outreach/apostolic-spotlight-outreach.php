<?php
/**
 * Plugin Name:       Apostolic Spotlight Outreach
 * Description:       Pastor outreach, Spotlight interview scheduling and opportunity tracking for the Apostolic Spotlight church directory. Sends through Google Workspace (Gmail) and books interviews against Google Calendar with Google Meet.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Apostolic Spotlight
 * License:           GPL-2.0-or-later
 * Text Domain:       apostolic-spotlight-outreach
 *
 * @package ApostolicSpotlight\Outreach
 */

defined( 'ABSPATH' ) || exit;

define( 'ASO_VERSION', '1.0.0' );
define( 'ASO_DB_VERSION', '1.0.0' );
define( 'ASO_FILE', __FILE__ );
define( 'ASO_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASO_URL', plugin_dir_url( __FILE__ ) );

require_once ASO_DIR . 'includes/class-autoloader.php';
\ApostolicSpotlight\Outreach\Autoloader::register();

register_activation_hook( __FILE__, array( '\ApostolicSpotlight\Outreach\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\ApostolicSpotlight\Outreach\Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\ApostolicSpotlight\Outreach\Plugin::instance()->boot();
	}
);
