<?php
/**
 * Custom capabilities.
 *
 * - aso_manage_outreach : dashboard, churches, campaigns, queue, bookings, opportunities, sending.
 * - aso_manage_templates: create/edit email templates.
 * - aso_manage_settings : Google connection, OAuth credentials, sending limits, church field mapping.
 *
 * Administrators get everything. Editors can optionally be granted outreach + templates
 * (never settings / OAuth) from Settings → Permissions.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	const OUTREACH  = 'aso_manage_outreach';
	const TEMPLATES = 'aso_manage_templates';
	const SETTINGS  = 'aso_manage_settings';

	public static function all() {
		return array( self::OUTREACH, self::TEMPLATES, self::SETTINGS );
	}

	public static function install() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
		self::sync_editor_role();
	}

	/**
	 * Grant/revoke editor access according to the permissions setting.
	 */
	public static function sync_editor_role() {
		$editor = get_role( 'editor' );
		if ( ! $editor ) {
			return;
		}
		$allow = (bool) Settings::get( 'permissions.editors' );
		foreach ( array( self::OUTREACH, self::TEMPLATES ) as $cap ) {
			if ( $allow ) {
				$editor->add_cap( $cap );
			} else {
				$editor->remove_cap( $cap );
			}
		}
		// Editors never get settings access.
		$editor->remove_cap( self::SETTINGS );
	}

	public static function uninstall() {
		global $wp_roles;
		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new \WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		foreach ( $wp_roles->role_objects as $role ) {
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
