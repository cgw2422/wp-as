<?php
/**
 * Read model for a directory church (wraps the existing WP_Post).
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach\Church;

defined( 'ABSPATH' ) || exit;

final class Church {

	/** @var \WP_Post */
	public $post;

	/** @var int */
	public $id;

	/** @var array<string,string> */
	private $cache = array();

	public function __construct( \WP_Post $post ) {
		$this->post = $post;
		$this->id   = (int) $post->ID;
	}

	public function get( $field ) {
		if ( ! array_key_exists( $field, $this->cache ) ) {
			$this->cache[ $field ] = Church_Repository::read_field( $this->post, $field );
		}
		return $this->cache[ $field ];
	}

	public function name() {
		$name = $this->get( 'church_name' );
		return '' !== $name ? $name : __( '(Untitled church)', 'apostolic-spotlight-outreach' );
	}

	/**
	 * Best address for outreach, honoring contact preference.
	 */
	public function outreach_email() {
		$pref   = $this->get( 'contact_preference' );
		$pastor = $this->get( 'pastor_email' );
		$church = $this->get( 'church_email' );
		if ( 'do_not_email' === $pref ) {
			return '';
		}
		if ( 'church_email' === $pref && is_email( $church ) ) {
			return $church;
		}
		if ( is_email( $pastor ) ) {
			return $pastor;
		}
		return is_email( $church ) ? $church : '';
	}

	public function location() {
		return trim( implode( ', ', array_filter( array( $this->get( 'city' ), $this->get( 'state' ) ) ) ) );
	}

	public function edit_link() {
		return get_edit_post_link( $this->id, 'raw' );
	}

	public function to_array() {
		$out = array( 'id' => $this->id );
		foreach ( array_keys( Field_Map::fields() ) as $f ) {
			$out[ $f ] = $this->get( $f );
		}
		return $out;
	}
}
