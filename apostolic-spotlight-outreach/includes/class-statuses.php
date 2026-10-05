<?php
/**
 * Status vocabularies. Spotlight pipeline and the two opportunity pipelines are
 * intentionally separate fields.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

defined( 'ABSPATH' ) || exit;

final class Statuses {

	/**
	 * Spotlight pipeline. Keys are stored; order defines "rank" for forward-only updates.
	 */
	public static function spotlight() {
		return array(
			'not_contacted'   => array( __( 'Not Contacted', 'apostolic-spotlight-outreach' ), 'gray' ),
			'queued'          => array( __( 'Queued', 'apostolic-spotlight-outreach' ), 'slate' ),
			'initial_sent'    => array( __( 'Initial Email Sent', 'apostolic-spotlight-outreach' ), 'blue' ),
			'followup1_sent'  => array( __( 'Follow-Up 1 Sent', 'apostolic-spotlight-outreach' ), 'blue' ),
			'followup2_sent'  => array( __( 'Follow-Up 2 Sent', 'apostolic-spotlight-outreach' ), 'blue' ),
			'replied'         => array( __( 'Replied', 'apostolic-spotlight-outreach' ), 'amber' ),
			'interested'      => array( __( 'Interested', 'apostolic-spotlight-outreach' ), 'teal' ),
			'scheduled'       => array( __( 'Spotlight Scheduled', 'apostolic-spotlight-outreach' ), 'purple' ),
			'completed'       => array( __( 'Spotlight Completed', 'apostolic-spotlight-outreach' ), 'green' ),
			'published'       => array( __( 'Spotlight Published', 'apostolic-spotlight-outreach' ), 'green-strong' ),
			'not_interested'  => array( __( 'Not Interested', 'apostolic-spotlight-outreach' ), 'red' ),
			'no_response'     => array( __( 'No Response', 'apostolic-spotlight-outreach' ), 'muted' ),
		);
	}

	public static function website() {
		return array(
			'unknown'  => array( __( 'Unknown', 'apostolic-spotlight-outreach' ), 'gray' ),
			'no_need'  => array( __( 'No Need', 'apostolic-spotlight-outreach' ), 'muted' ),
			'potential'=> array( __( 'Potential', 'apostolic-spotlight-outreach' ), 'amber' ),
			'interested' => array( __( 'Interested', 'apostolic-spotlight-outreach' ), 'teal' ),
			'proposal' => array( __( 'Proposal/Discussion', 'apostolic-spotlight-outreach' ), 'purple' ),
			'won'      => array( __( 'Won', 'apostolic-spotlight-outreach' ), 'green-strong' ),
			'lost'     => array( __( 'Lost', 'apostolic-spotlight-outreach' ), 'red' ),
		);
	}

	public static function grow() {
		return array(
			'unknown'        => array( __( 'Unknown', 'apostolic-spotlight-outreach' ), 'gray' ),
			'no_need'        => array( __( 'No Need', 'apostolic-spotlight-outreach' ), 'muted' ),
			'potential'      => array( __( 'Potential', 'apostolic-spotlight-outreach' ), 'amber' ),
			'interested'     => array( __( 'Interested', 'apostolic-spotlight-outreach' ), 'teal' ),
			'demo_scheduled' => array( __( 'Demo Scheduled', 'apostolic-spotlight-outreach' ), 'purple' ),
			'customer'       => array( __( 'Customer', 'apostolic-spotlight-outreach' ), 'green-strong' ),
			'lost'           => array( __( 'Lost', 'apostolic-spotlight-outreach' ), 'red' ),
		);
	}

	/** Post-Spotlight manual website assessment. '' = not yet reviewed. */
	public static function website_review() {
		return array(
			''                   => array( __( 'Not Reviewed', 'apostolic-spotlight-outreach' ), 'gray' ),
			'looks_good'         => array( __( 'Looks Good', 'apostolic-spotlight-outreach' ), 'green' ),
			'needs_improvement'  => array( __( 'Needs Improvement', 'apostolic-spotlight-outreach' ), 'amber' ),
			'strong_opportunity' => array( __( 'Strong Opportunity', 'apostolic-spotlight-outreach' ), 'purple' ),
		);
	}

	/** Post-Spotlight manual Apostolic Grow assessment. '' = not yet reviewed. */
	public static function grow_fit() {
		return array(
			''             => array( __( 'Not Reviewed', 'apostolic-spotlight-outreach' ), 'gray' ),
			'unknown'      => array( __( 'Unknown', 'apostolic-spotlight-outreach' ), 'muted' ),
			'possible_fit' => array( __( 'Possible Fit', 'apostolic-spotlight-outreach' ), 'amber' ),
			'strong_fit'   => array( __( 'Strong Fit', 'apostolic-spotlight-outreach' ), 'purple' ),
		);
	}

	public static function booking() {
		return array(
			'confirmed' => array( __( 'Scheduled', 'apostolic-spotlight-outreach' ), 'purple' ),
			'completed' => array( __( 'Completed', 'apostolic-spotlight-outreach' ), 'green' ),
			'canceled'  => array( __( 'Canceled', 'apostolic-spotlight-outreach' ), 'muted' ),
			'no_show'   => array( __( 'No-Show', 'apostolic-spotlight-outreach' ), 'red' ),
			'failed'    => array( __( 'Failed', 'apostolic-spotlight-outreach' ), 'red' ),
		);
	}

	public static function enrollment() {
		return array(
			'active'    => array( __( 'Active', 'apostolic-spotlight-outreach' ), 'blue' ),
			'finished'  => array( __( 'Sequence Sent', 'apostolic-spotlight-outreach' ), 'slate' ),
			'completed' => array( __( 'Closed', 'apostolic-spotlight-outreach' ), 'muted' ),
			'stopped'   => array( __( 'Stopped', 'apostolic-spotlight-outreach' ), 'amber' ),
			'paused'    => array( __( 'Paused', 'apostolic-spotlight-outreach' ), 'gray' ),
		);
	}

	public static function queue() {
		return array(
			'pending'   => array( __( 'Awaiting Slot', 'apostolic-spotlight-outreach' ), 'gray' ),
			'scheduled' => array( __( 'Scheduled', 'apostolic-spotlight-outreach' ), 'blue' ),
			'sending'   => array( __( 'Sending', 'apostolic-spotlight-outreach' ), 'amber' ),
			'sent'      => array( __( 'Sent', 'apostolic-spotlight-outreach' ), 'green' ),
			'failed'    => array( __( 'Failed', 'apostolic-spotlight-outreach' ), 'red' ),
			'canceled'  => array( __( 'Canceled', 'apostolic-spotlight-outreach' ), 'muted' ),
			'held'      => array( __( 'Held for Review', 'apostolic-spotlight-outreach' ), 'amber' ),
		);
	}

	public static function campaign() {
		return array(
			'draft'     => array( __( 'Draft', 'apostolic-spotlight-outreach' ), 'gray' ),
			'active'    => array( __( 'Active', 'apostolic-spotlight-outreach' ), 'green' ),
			'paused'    => array( __( 'Paused', 'apostolic-spotlight-outreach' ), 'amber' ),
			'archived'  => array( __( 'Archived', 'apostolic-spotlight-outreach' ), 'muted' ),
		);
	}

	/**
	 * Spotlight statuses that mean "a human conversation has started" – automated
	 * sequence emails must stop.
	 */
	public static function stop_statuses() {
		return array( 'replied', 'interested', 'scheduled', 'completed', 'published', 'not_interested' );
	}

	public static function options( $group ) {
		$out = array();
		foreach ( self::group( $group ) as $key => $def ) {
			$out[ $key ] = $def[0];
		}
		return $out;
	}

	public static function group( $group ) {
		switch ( $group ) {
			case 'spotlight':
				return self::spotlight();
			case 'website':
				return self::website();
			case 'grow':
				return self::grow();
			case 'website_review':
				return self::website_review();
			case 'grow_fit':
				return self::grow_fit();
			case 'booking':
				return self::booking();
			case 'enrollment':
				return self::enrollment();
			case 'queue':
				return self::queue();
			case 'campaign':
				return self::campaign();
		}
		return array();
	}

	public static function label( $group, $key ) {
		$g = self::group( $group );
		return isset( $g[ $key ] ) ? $g[ $key ][0] : (string) $key;
	}

	public static function is_valid( $group, $key ) {
		return array_key_exists( (string) $key, self::group( $group ) );
	}

	public static function rank( $key ) {
		$keys = array_keys( self::spotlight() );
		$pos  = array_search( $key, $keys, true );
		return false === $pos ? -1 : $pos;
	}

	/**
	 * Escaped status badge HTML.
	 */
	public static function badge( $group, $key ) {
		$g     = self::group( $group );
		$label = isset( $g[ $key ] ) ? $g[ $key ][0] : (string) $key;
		$color = isset( $g[ $key ] ) ? $g[ $key ][1] : 'gray';
		return sprintf( '<span class="aso-badge aso-badge--%s">%s</span>', esc_attr( $color ), esc_html( $label ) );
	}
}
