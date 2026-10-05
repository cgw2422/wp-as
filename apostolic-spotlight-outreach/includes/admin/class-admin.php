<?php
/**
 * Admin menu, routing, assets and notices.
 *
 * Every screen lives under the top-level "Apostolic Spotlight Outreach" menu. Detail
 * screens are sub-views of a visible page (e.g. aso-churches&view=church&church=123).
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach\Admin;

use ApostolicSpotlight\Outreach\Capabilities;
use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Cron;
use ApostolicSpotlight\Outreach\Google\Google_Oauth;
use ApostolicSpotlight\Outreach\Settings;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'set-screen-option', array( __CLASS__, 'set_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_aso_churches_per_page', array( __CLASS__, 'set_screen_option' ), 10, 3 );
	}

	public static function pages() {
		return array(
			'aso-dashboard'     => array( __( 'Dashboard', 'apostolic-spotlight-outreach' ), Capabilities::OUTREACH, 'dashboard' ),
			'aso-churches'      => array( __( 'Churches', 'apostolic-spotlight-outreach' ), Capabilities::OUTREACH, 'churches' ),
			'aso-campaigns'     => array( __( 'Campaigns', 'apostolic-spotlight-outreach' ), Capabilities::OUTREACH, 'campaigns' ),
			'aso-templates'     => array( __( 'Email Templates', 'apostolic-spotlight-outreach' ), Capabilities::TEMPLATES, 'templates' ),
			'aso-queue'         => array( __( 'Queue', 'apostolic-spotlight-outreach' ), Capabilities::OUTREACH, 'queue' ),
			'aso-bookings'      => array( __( 'Bookings', 'apostolic-spotlight-outreach' ), Capabilities::OUTREACH, 'bookings' ),
			'aso-opportunities' => array( __( 'Opportunities', 'apostolic-spotlight-outreach' ), Capabilities::OUTREACH, 'opportunities' ),
			'aso-settings'      => array( __( 'Settings', 'apostolic-spotlight-outreach' ), Capabilities::SETTINGS, 'settings' ),
		);
	}

	public static function menu() {
		$hook = add_menu_page(
			__( 'Apostolic Spotlight Outreach', 'apostolic-spotlight-outreach' ),
			__( 'Apostolic Spotlight Outreach', 'apostolic-spotlight-outreach' ),
			Capabilities::OUTREACH,
			'aso-dashboard',
			array( __CLASS__, 'render' ),
			'dashicons-megaphone',
			26
		);
		foreach ( self::pages() as $slug => $def ) {
			$h = add_submenu_page( 'aso-dashboard', $def[0] . ' ‹ ' . __( 'Spotlight Outreach', 'apostolic-spotlight-outreach' ), $def[0], $def[1], $slug, array( __CLASS__, 'render' ) );
			if ( 'aso-churches' === $slug ) {
				add_action( 'load-' . $h, array( __CLASS__, 'churches_screen_options' ) );
			}
		}
		unset( $hook );
	}

	public static function churches_screen_options() {
		if ( empty( $_GET['view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			add_screen_option( 'per_page', array( 'label' => __( 'Churches per page', 'apostolic-spotlight-outreach' ), 'default' => 50, 'option' => 'aso_churches_per_page' ) );
		}
	}

	public static function set_screen_option( $status, $option, $value ) {
		if ( 'aso_churches_per_page' === $option ) {
			return max( 10, min( 500, (int) $value ) );
		}
		return $status;
	}

	public static function current_page() {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function render() {
		$page  = self::current_page();
		$pages = self::pages();
		if ( ! isset( $pages[ $page ] ) || ! current_user_can( $pages[ $page ][1] ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'apostolic-spotlight-outreach' ), 403 );
		}
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$file = $pages[ $page ][2];
		$sub  = array(
			'churches'  => array( 'church', 'enroll', 'compose' ),
			'campaigns' => array( 'edit', 'view', 'preview' ),
			'templates' => array( 'edit' ),
		);
		if ( $view && isset( $sub[ $file ] ) && in_array( $view, $sub[ $file ], true ) ) {
			$file = $file . '-' . $view;
		}
		echo '<div class="wrap aso-wrap">';
		include ASO_DIR . 'includes/admin/views/' . $file . '.php';
		echo '</div>';
	}

	public static function assets( $hook ) {
		$page = self::current_page();
		if ( 0 !== strpos( $page, 'aso-' ) ) {
			// Also load on the church edit screen for the metabox.
			if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
				return;
			}
		}
		wp_enqueue_style( 'aso-admin', ASO_URL . 'assets/css/admin.css', array(), ASO_VERSION );
		wp_enqueue_script( 'aso-admin', ASO_URL . 'assets/js/admin.js', array(), ASO_VERSION, true );
		wp_localize_script(
			'aso-admin',
			'ASOAdmin',
			array(
				'confirm' => __( 'Are you sure?', 'apostolic-spotlight-outreach' ),
				'copied'  => __( 'Copied!', 'apostolic-spotlight-outreach' ),
			)
		);
	}

	/**
	 * Flash notice for the current user.
	 */
	public static function flash( $message, $type = 'success' ) {
		set_transient( 'aso_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 60 );
	}

	public static function notices() {
		$page = self::current_page();
		if ( 0 !== strpos( $page, 'aso-' ) ) {
			return;
		}
		$n = get_transient( 'aso_notice_' . get_current_user_id() );
		if ( $n ) {
			delete_transient( 'aso_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible aso-notice"><p>%s</p></div>', esc_attr( 'error' === $n['type'] ? 'error' : ( 'warning' === $n['type'] ? 'warning' : 'success' ) ), wp_kses_post( $n['message'] ) );
		}

		$settings_url = Util::admin_url( 'aso-settings' );
		if ( ! Church_Repository::is_configured() && 'aso-settings' !== $page ) {
			printf(
				'<div class="notice notice-warning aso-notice"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Confirm which post type holds your church directory and how its fields map to outreach fields.', 'apostolic-spotlight-outreach' ),
				esc_url( add_query_arg( 'tab', 'church', $settings_url ) ),
				esc_html__( 'Open Church Directory settings', 'apostolic-spotlight-outreach' )
			);
		}
		$account = Google_Oauth::account();
		if ( ! empty( $account['last_error'] ) ) {
			printf(
				'<div class="notice notice-error aso-notice"><p>%s <a href="%s">%s</a></p></div>',
				esc_html( $account['last_error'] ),
				esc_url( add_query_arg( 'tab', 'google', $settings_url ) ),
				esc_html__( 'Reconnect Google', 'apostolic-spotlight-outreach' )
			);
		} elseif ( ! Google_Oauth::is_connected() && 'aso-settings' !== $page ) {
			printf(
				'<div class="notice notice-info aso-notice"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Google Workspace is not connected yet. Emails will wait in the queue and the scheduling page will not offer times until you connect.', 'apostolic-spotlight-outreach' ),
				esc_url( add_query_arg( 'tab', 'google', $settings_url ) ),
				esc_html__( 'Connect Google', 'apostolic-spotlight-outreach' )
			);
		}
		$last = (int) get_option( Cron::LAST_RUN, 0 );
		if ( Google_Oauth::is_connected() && $last && $last < time() - HOUR_IN_SECONDS && ! Settings::get( 'sending.paused' ) ) {
			printf(
				'<div class="notice notice-warning aso-notice"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: human time diff */
						__( 'The outreach scheduler last ran %s ago. If your site gets little traffic, set up a real cron job for wp-cron.php (see Settings → Sending).', 'apostolic-spotlight-outreach' ),
						human_time_diff( $last )
					)
				)
			);
		}
	}

	/* ------------------------------------------------------------------
	 * View helpers.
	 * ------------------------------------------------------------------ */

	public static function header( $title, $actions = array(), $subtitle = '' ) {
		echo '<div class="aso-header"><div><h1 class="aso-title">' . esc_html( $title ) . '</h1>';
		if ( $subtitle ) {
			echo '<p class="aso-subtitle">' . wp_kses_post( $subtitle ) . '</p>';
		}
		echo '</div><div class="aso-header__actions">';
		foreach ( $actions as $a ) {
			printf(
				'<a class="button %s" href="%s">%s</a>',
				esc_attr( ! empty( $a['primary'] ) ? 'button-primary' : '' ),
				esc_url( $a['url'] ),
				esc_html( $a['label'] )
			);
		}
		echo '</div></div>';
		echo '<hr class="wp-header-end">';
	}

	public static function post_url( $action, array $args = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ), $action );
	}

	public static function select( $name, array $options, $selected, $attrs = '' ) {
		$html = '<select name="' . esc_attr( $name ) . '" ' . $attrs . '>';
		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( (string) $selected, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	public static function empty_state( $title, $text = '', $button = null ) {
		echo '<div class="aso-empty"><h3>' . esc_html( $title ) . '</h3>';
		if ( $text ) {
			echo '<p>' . esc_html( $text ) . '</p>';
		}
		if ( $button ) {
			printf( '<a class="button button-primary" href="%s">%s</a>', esc_url( $button['url'] ), esc_html( $button['label'] ) );
		}
		echo '</div>';
	}
}
