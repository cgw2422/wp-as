<?php
/**
 * Read-only outreach summary on the directory's own church edit screen
 * (and an "Outreach" row action in the directory list). Does not alter the directory UI otherwise.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach\Admin;

use ApostolicSpotlight\Outreach\Capabilities;
use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Church\Outreach_State;
use ApostolicSpotlight\Outreach\Statuses;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

final class Metabox {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
	}

	public static function register( $post_type ) {
		if ( $post_type !== Church_Repository::post_type() || ! current_user_can( Capabilities::OUTREACH ) ) {
			return;
		}
		add_meta_box( 'aso-outreach', __( 'Spotlight Outreach', 'apostolic-spotlight-outreach' ), array( __CLASS__, 'render' ), $post_type, 'side', 'default' );
	}

	public static function render( \WP_Post $post ) {
		$state = Outreach_State::get( $post->ID );
		echo '<p>' . Statuses::badge( 'spotlight', $state->spotlight_status ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="aso-muted">' . esc_html__( 'Website:', 'apostolic-spotlight-outreach' ) . ' ' . esc_html( Statuses::label( 'website', $state->website_status ) ) . '<br>';
		echo esc_html__( 'Apostolic Grow:', 'apostolic-spotlight-outreach' ) . ' ' . esc_html( Statuses::label( 'grow', $state->grow_status ) ) . '<br>';
		echo esc_html__( 'Last contact:', 'apostolic-spotlight-outreach' ) . ' ' . esc_html( $state->last_contacted_at ? Util::short( $state->last_contacted_at ) : '—' ) . '</p>';
		printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( Util::church_admin_url( $post->ID ) ), esc_html__( 'Open outreach record', 'apostolic-spotlight-outreach' ) );
	}

	public static function row_action( $actions, $post ) {
		if ( $post instanceof \WP_Post && $post->post_type === Church_Repository::post_type() && current_user_can( Capabilities::OUTREACH ) ) {
			$actions['aso_outreach'] = sprintf( '<a href="%s">%s</a>', esc_url( Util::church_admin_url( $post->ID ) ), esc_html__( 'Outreach', 'apostolic-spotlight-outreach' ) );
		}
		return $actions;
	}
}
