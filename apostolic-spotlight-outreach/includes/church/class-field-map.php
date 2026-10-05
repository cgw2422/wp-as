<?php
/**
 * Maps the plugin's logical church fields onto the EXISTING directory record.
 *
 * Each logical field has a "source":
 *   title          – the post title
 *   permalink      – the public post URL (read only)
 *   meta:{key}     – a post meta / ACF field on the directory post (ACF stores values as post meta)
 *   tax:{taxonomy} – a taxonomy assigned to the directory post (read only from outreach screens)
 *   plugin         – not present in the directory; stored as post meta "_aso_{field}" on the same post
 *
 * Nothing is ever copied into a separate church table: the directory post is the source of truth.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach\Church;

use ApostolicSpotlight\Outreach\Settings;

defined( 'ABSPATH' ) || exit;

final class Field_Map {

	const PLUGIN_META_PREFIX = '_aso_';

	/**
	 * Logical fields.
	 *
	 * @return array<string,array{label:string,type:string,filter:bool,default:string,patterns:array}>
	 */
	public static function fields() {
		return array(
			'church_name'        => array(
				'label'    => __( 'Church name', 'apostolic-spotlight-outreach' ),
				'type'     => 'text',
				'filter'   => false,
				'default'  => 'title',
				'patterns' => array( 'church_name', 'name' ),
			),
			'pastor_name'        => array(
				'label'    => __( 'Pastor name', 'apostolic-spotlight-outreach' ),
				'type'     => 'text',
				'filter'   => false,
				'default'  => 'plugin',
				'patterns' => array( 'pastor_name', 'pastor', 'senior_pastor', 'lead_pastor', 'pastors', 'minister' ),
			),
			'pastor_email'       => array(
				'label'    => __( 'Pastor email', 'apostolic-spotlight-outreach' ),
				'type'     => 'email',
				'filter'   => false,
				'default'  => 'plugin',
				'patterns' => array( 'pastor_email', 'pastor_e_mail', 'pastoremail' ),
			),
			'church_email'       => array(
				'label'    => __( 'Church email', 'apostolic-spotlight-outreach' ),
				'type'     => 'email',
				'filter'   => false,
				'default'  => 'plugin',
				'patterns' => array( 'church_email', 'email', '_email', 'contact_email', 'e_mail' ),
			),
			'phone'              => array(
				'label'    => __( 'Phone', 'apostolic-spotlight-outreach' ),
				'type'     => 'text',
				'filter'   => false,
				'default'  => 'plugin',
				'patterns' => array( 'phone', 'church_phone', '_phone', 'phone_number', 'telephone' ),
			),
			'website'            => array(
				'label'    => __( 'Website', 'apostolic-spotlight-outreach' ),
				'type'     => 'url',
				'filter'   => false,
				'default'  => 'plugin',
				'patterns' => array( 'website', 'church_website', '_website', 'url', 'website_url', 'web' ),
			),
			'organization'       => array(
				'label'    => __( 'Denomination / organization', 'apostolic-spotlight-outreach' ),
				'type'     => 'text',
				'filter'   => true,
				'default'  => 'plugin',
				'patterns' => array( 'organization', 'denomination', 'affiliation', 'fellowship', 'organisation', 'org', 'church_organization' ),
			),
			'city'               => array(
				'label'    => __( 'City', 'apostolic-spotlight-outreach' ),
				'type'     => 'text',
				'filter'   => false,
				'default'  => 'plugin',
				'patterns' => array( 'city', 'church_city', '_city', 'address_city', 'town' ),
			),
			'state'              => array(
				'label'    => __( 'State', 'apostolic-spotlight-outreach' ),
				'type'     => 'text',
				'filter'   => true,
				'default'  => 'plugin',
				'patterns' => array( 'state', 'church_state', '_state', 'address_state', 'region', 'province' ),
			),
			'listing_url'        => array(
				'label'    => __( 'Directory listing URL', 'apostolic-spotlight-outreach' ),
				'type'     => 'url',
				'filter'   => false,
				'default'  => 'permalink',
				'patterns' => array(),
			),
			'contact_preference' => array(
				'label'    => __( 'Contact preference', 'apostolic-spotlight-outreach' ),
				'type'     => 'select',
				'filter'   => false,
				'default'  => 'plugin',
				'patterns' => array(),
			),
			'notes'              => array(
				'label'    => __( 'Outreach notes', 'apostolic-spotlight-outreach' ),
				'type'     => 'textarea',
				'filter'   => false,
				'default'  => 'plugin',
				'patterns' => array(),
			),
		);
	}

	public static function contact_preferences() {
		return array(
			''             => __( 'No preference', 'apostolic-spotlight-outreach' ),
			'pastor_email' => __( 'Pastor email', 'apostolic-spotlight-outreach' ),
			'church_email' => __( 'Church email', 'apostolic-spotlight-outreach' ),
			'phone'        => __( 'Phone call', 'apostolic-spotlight-outreach' ),
			'do_not_email' => __( 'Do not email', 'apostolic-spotlight-outreach' ),
		);
	}

	/**
	 * Configured source for a logical field.
	 *
	 * @return array{type:string,key:string}
	 */
	public static function source( $field ) {
		$fields = self::fields();
		$map    = (array) Settings::get( 'church.map', array() );
		if ( ! $map ) {
			// Not confirmed yet: use the auto-detected mapping so the directory is usable immediately.
			static $guessed = null;
			if ( null === $guessed ) {
				$guessed = array();
				$pt      = Church_Repository::post_type();
				$guessed = $pt ? self::guess_map( $pt ) : array();
			}
			$map = $guessed;
		}
		$raw    = isset( $map[ $field ] ) && '' !== $map[ $field ] ? $map[ $field ] : ( isset( $fields[ $field ] ) ? $fields[ $field ]['default'] : 'plugin' );
		return self::parse( $raw, $field );
	}

	public static function parse( $raw, $field ) {
		$raw = (string) $raw;
		if ( 0 === strpos( $raw, 'meta:' ) ) {
			return array( 'type' => 'meta', 'key' => substr( $raw, 5 ) );
		}
		if ( 0 === strpos( $raw, 'tax:' ) ) {
			return array( 'type' => 'tax', 'key' => substr( $raw, 4 ) );
		}
		if ( in_array( $raw, array( 'title', 'permalink' ), true ) ) {
			return array( 'type' => $raw, 'key' => '' );
		}
		return array( 'type' => 'plugin', 'key' => self::PLUGIN_META_PREFIX . $field );
	}

	/**
	 * Meta key used for queries when a field is meta- or plugin-backed; null otherwise.
	 */
	public static function meta_key( $field ) {
		$s = self::source( $field );
		return in_array( $s['type'], array( 'meta', 'plugin' ), true ) ? $s['key'] : null;
	}

	/* ------------------------------------------------------------------
	 * Detection helpers for Settings → Church Directory.
	 * ------------------------------------------------------------------ */

	/**
	 * Post types that look like they could hold the church directory, best first.
	 *
	 * @return array<string,array{label:string,count:int,score:int}>
	 */
	public static function candidate_post_types() {
		$out   = array();
		$types = get_post_types( array( 'show_ui' => true ), 'objects' );
		foreach ( $types as $name => $obj ) {
			if ( in_array( $name, array( 'attachment', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page' ), true ) ) {
				continue;
			}
			$hay   = strtolower( $name . ' ' . $obj->label . ' ' . ( isset( $obj->labels->singular_name ) ? $obj->labels->singular_name : '' ) );
			$score = 0;
			if ( false !== strpos( $hay, 'church' ) ) {
				$score += 10;
			}
			if ( preg_match( '/congregation|assembl|listing|director|place|location|gd_|at_biz_dir|wpbdp/', $hay ) ) {
				$score += 4;
			}
			if ( in_array( $name, array( 'post', 'page' ), true ) ) {
				$score -= 5;
			}
			$counts = wp_count_posts( $name );
			$count  = 0;
			foreach ( array( 'publish', 'draft', 'pending', 'private' ) as $st ) {
				$count += isset( $counts->$st ) ? (int) $counts->$st : 0;
			}
			$out[ $name ] = array(
				'label' => $obj->label,
				'count' => $count,
				'score' => $score,
			);
		}
		uasort(
			$out,
			static function ( $a, $b ) {
				return array( $b['score'], $b['count'] ) <=> array( $a['score'], $a['count'] );
			}
		);
		return $out;
	}

	public static function guess_post_type() {
		$c = self::candidate_post_types();
		foreach ( $c as $name => $info ) {
			if ( $info['score'] >= 10 ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * Meta keys in use for a post type (sampled), excluding WordPress internals and ACF reference keys.
	 *
	 * @return string[]
	 */
	public static function discover_meta_keys( $post_type ) {
		global $wpdb;
		if ( ! $post_type ) {
			return array();
		}
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s AND pm.meta_key NOT LIKE %s
				ORDER BY pm.meta_key LIMIT 400",
				$post_type,
				$wpdb->esc_like( self::PLUGIN_META_PREFIX ) . '%'
			)
		);
		$skip = array( '_edit_lock', '_edit_last', '_thumbnail_id', '_wp_old_slug', '_wp_page_template', '_encloseme', '_pingme', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_wp_old_date' );
		$out  = array();
		foreach ( (array) $keys as $k ) {
			if ( in_array( $k, $skip, true ) ) {
				continue;
			}
			// ACF stores "_fieldname" => "field_abc123" references alongside each value; hide those.
			if ( '_' === $k[0] && in_array( substr( $k, 1 ), $keys, true ) ) {
				continue;
			}
			$out[] = $k;
		}
		return $out;
	}

	/**
	 * ACF field labels for a post type, keyed by field name (empty when ACF is inactive).
	 *
	 * @return array<string,string>
	 */
	public static function acf_labels( $post_type ) {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}
		$labels = array();
		foreach ( (array) acf_get_field_groups( array( 'post_type' => $post_type ) ) as $group ) {
			foreach ( (array) acf_get_fields( $group ) as $f ) {
				if ( ! empty( $f['name'] ) ) {
					$labels[ $f['name'] ] = $f['label'];
				}
			}
		}
		return $labels;
	}

	/**
	 * Suggest a mapping for each field based on discovered meta keys / taxonomies.
	 *
	 * @return array<string,string>
	 */
	public static function guess_map( $post_type ) {
		$keys       = self::discover_meta_keys( $post_type );
		$acf        = array_keys( self::acf_labels( $post_type ) );
		$keys       = array_values( array_unique( array_merge( $acf, $keys ) ) );
		$taxonomies = get_object_taxonomies( $post_type );
		$lower_keys = array();
		foreach ( $keys as $k ) {
			$lower_keys[ strtolower( $k ) ] = $k;
		}
		$guess = array();
		foreach ( self::fields() as $field => $def ) {
			$guess[ $field ] = $def['default'];
			foreach ( $def['patterns'] as $pattern ) {
				if ( 'church_name' === $field ) {
					break;
				}
				if ( isset( $lower_keys[ $pattern ] ) ) {
					$guess[ $field ] = 'meta:' . $lower_keys[ $pattern ];
					break;
				}
				foreach ( (array) $taxonomies as $tax ) {
					if ( strtolower( $tax ) === $pattern || false !== strpos( strtolower( $tax ), $pattern ) ) {
						if ( in_array( $field, array( 'organization', 'state', 'city' ), true ) ) {
							$guess[ $field ] = 'tax:' . $tax;
							break 2;
						}
					}
				}
			}
		}
		return $guess;
	}
}
