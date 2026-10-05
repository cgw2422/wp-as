<?php
/**
 * Read/write access to EXISTING directory churches via the configured field map,
 * plus the filtered CRM query used by the Churches screen.
 *
 * To support a directory stored in custom tables, hook `aso_church_read_field`
 * / `aso_church_write_field` (and `aso_church_post_type`).
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach\Church;

use ApostolicSpotlight\Outreach\Installer;
use ApostolicSpotlight\Outreach\Settings;
use ApostolicSpotlight\Outreach\Activity;

defined( 'ABSPATH' ) || exit;

final class Church_Repository {

	const STATUSES = array( 'publish', 'pending', 'draft', 'private', 'future' );

	public static function post_type() {
		$pt = (string) Settings::get( 'church.post_type', '' );
		if ( '' === $pt || ! post_type_exists( $pt ) ) {
			$pt = Field_Map::guess_post_type();
		}
		return (string) apply_filters( 'aso_church_post_type', $pt );
	}

	public static function is_configured() {
		$pt = (string) Settings::get( 'church.post_type', '' );
		return '' !== $pt && post_type_exists( $pt );
	}

	/**
	 * @return Church|null
	 */
	public static function find( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || $post->post_type !== self::post_type() || 'trash' === $post->post_status ) {
			return null;
		}
		return new Church( $post );
	}

	public static function read_field( \WP_Post $post, $field ) {
		$source = Field_Map::source( $field );
		$value  = '';
		switch ( $source['type'] ) {
			case 'title':
				$value = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
				break;
			case 'permalink':
				$value = (string) get_permalink( $post );
				break;
			case 'tax':
				$terms = get_the_terms( $post, $source['key'] );
				if ( is_array( $terms ) ) {
					$value = implode( ', ', wp_list_pluck( $terms, 'name' ) );
				}
				break;
			case 'meta':
			case 'plugin':
				$raw   = get_post_meta( $post->ID, $source['key'], true );
				$value = self::stringify( $raw );
				break;
		}
		return (string) apply_filters( 'aso_church_read_field', trim( (string) $value ), $field, $post, $source );
	}

	/**
	 * ACF/directory values can be arrays (e.g. link fields, select fields).
	 */
	private static function stringify( $raw ) {
		if ( is_array( $raw ) ) {
			if ( isset( $raw['url'] ) ) {
				return (string) $raw['url'];
			}
			if ( isset( $raw['label'] ) ) {
				return (string) $raw['label'];
			}
			$flat = array_filter( array_map( 'strval', array_filter( $raw, 'is_scalar' ) ) );
			return implode( ', ', $flat );
		}
		return is_scalar( $raw ) ? (string) $raw : '';
	}

	public static function is_writable( $field ) {
		$source = Field_Map::source( $field );
		if ( 'plugin' === $source['type'] ) {
			return true;
		}
		if ( ! Settings::get( 'church.write_back' ) ) {
			return false;
		}
		return in_array( $source['type'], array( 'meta', 'title' ), true );
	}

	/**
	 * Write a logical field back to wherever it lives on the directory record.
	 *
	 * @return bool Whether the value changed.
	 */
	public static function write_field( Church $church, $field, $value ) {
		if ( ! self::is_writable( $field ) ) {
			return false;
		}
		$old = $church->get( $field );
		if ( (string) $old === (string) $value ) {
			return false;
		}
		$source = Field_Map::source( $field );

		$handled = apply_filters( 'aso_church_write_field', null, $church, $field, $value, $source );
		if ( null === $handled ) {
			switch ( $source['type'] ) {
				case 'title':
					wp_update_post( array( 'ID' => $church->id, 'post_title' => $value ) );
					break;
				case 'meta':
					// Use ACF when the key is a registered ACF field so its reference meta stays intact.
					if ( function_exists( 'update_field' ) && function_exists( 'get_field_object' ) && get_field_object( $source['key'], $church->id ) ) {
						update_field( $source['key'], $value, $church->id );
					} else {
						update_post_meta( $church->id, $source['key'], $value );
					}
					break;
				case 'plugin':
					if ( '' === $value ) {
						delete_post_meta( $church->id, $source['key'] );
					} else {
						update_post_meta( $church->id, $source['key'], $value );
					}
					break;
			}
		}
		return true;
	}

	/**
	 * Update several fields and log one timeline entry.
	 */
	public static function update_fields( Church $church, array $values ) {
		$changed = array();
		foreach ( $values as $field => $value ) {
			if ( self::write_field( $church, $field, $value ) ) {
				$changed[] = Field_Map::fields()[ $field ]['label'];
			}
		}
		if ( $changed && ! ( 1 === count( $values ) && isset( $values['notes'] ) ) ) {
			Activity::log(
				$church->id,
				'field_update',
				/* translators: %s: comma-separated list of fields */
				sprintf( __( 'Updated: %s', 'apostolic-spotlight-outreach' ), implode( ', ', $changed ) )
			);
		}
		return $changed;
	}

	/**
	 * Distinct values for a filterable field (for dropdowns).
	 *
	 * @return array<string,string> value => label
	 */
	public static function distinct_values( $field ) {
		global $wpdb;
		$source = Field_Map::source( $field );
		$pt     = self::post_type();
		if ( 'tax' === $source['type'] ) {
			$terms = get_terms( array( 'taxonomy' => $source['key'], 'hide_empty' => true ) );
			$out   = array();
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $t ) {
					$out[ $t->slug ] = $t->name;
				}
			}
			return $out;
		}
		$key = Field_Map::meta_key( $field );
		if ( ! $key ) {
			return array();
		}
		$cache_key = 'aso_distinct_' . md5( $pt . '|' . $key );
		$values    = wp_cache_get( $cache_key, 'aso' );
		if ( false === $values ) {
			$values = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value <> ''
					ORDER BY pm.meta_value LIMIT 500",
					$pt,
					$key
				)
			);
			wp_cache_set( $cache_key, $values, 'aso', 300 );
		}
		$out = array();
		foreach ( (array) $values as $v ) {
			if ( is_serialized( $v ) ) {
				continue;
			}
			$out[ $v ] = $v;
		}
		return $out;
	}

	/**
	 * Filtered church query for the CRM screen.
	 *
	 * @param array $args {
	 *   search, organization, state, spotlight_status, website_status, grow_status,
	 *   contacted (yes|no), campaign_id, needs_review, paused, opportunity (website|grow),
	 *   orderby (title|last_contacted|status), order, paged, per_page, ids_only (bool), include (int[])
	 * }
	 * @return array{ids:int[],total:int}
	 */
	public static function query( array $args ) {
		$pt = self::post_type();
		if ( ! $pt ) {
			return array( 'ids' => array(), 'total' => 0 );
		}
		$ids_only = ! empty( $args['ids_only'] );
		$q        = array(
			'post_type'              => $pt,
			'post_status'            => self::STATUSES,
			'fields'                 => 'ids',
			'posts_per_page'         => $ids_only ? -1 : max( 1, (int) ( $args['per_page'] ?? 25 ) ),
			'paged'                  => max( 1, (int) ( $args['paged'] ?? 1 ) ),
			'orderby'                => 'title',
			'order'                  => ( isset( $args['order'] ) && 'desc' === strtolower( $args['order'] ) ) ? 'DESC' : 'ASC',
			'no_found_rows'          => $ids_only,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
			'aso_query'              => $args,
		);
		if ( ! empty( $args['search'] ) ) {
			$q['s'] = (string) $args['search'];
		}
		if ( ! empty( $args['include'] ) ) {
			$q['post__in'] = array_map( 'intval', (array) $args['include'] );
		}

		$meta_query = array();
		$tax_query  = array();
		foreach ( array( 'organization', 'state' ) as $field ) {
			if ( empty( $args[ $field ] ) ) {
				continue;
			}
			$source = Field_Map::source( $field );
			if ( 'tax' === $source['type'] ) {
				$tax_query[] = array(
					'taxonomy' => $source['key'],
					'field'    => 'slug',
					'terms'    => (string) $args[ $field ],
				);
			} elseif ( $key = Field_Map::meta_key( $field ) ) { // phpcs:ignore
				$meta_query[] = array(
					'key'   => $key,
					'value' => (string) $args[ $field ],
				);
			}
		}
		if ( $meta_query ) {
			$q['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( $tax_query ) {
			$q['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		add_filter( 'posts_clauses', array( __CLASS__, 'clauses' ), 10, 2 );
		$query = new \WP_Query( $q );
		remove_filter( 'posts_clauses', array( __CLASS__, 'clauses' ), 10 );

		return array(
			'ids'   => array_map( 'intval', $query->posts ),
			'total' => $ids_only ? count( $query->posts ) : (int) $query->found_posts,
		);
	}

	/**
	 * Join outreach state and apply outreach filters/sorting.
	 *
	 * @internal
	 */
	public static function clauses( $clauses, \WP_Query $query ) {
		global $wpdb;
		$args = $query->get( 'aso_query' );
		if ( ! is_array( $args ) ) {
			return $clauses;
		}
		$o = Installer::table( 'outreach' );
		$e = Installer::table( 'enrollments' );

		$clauses['join'] .= " LEFT JOIN {$o} aso_o ON aso_o.church_id = {$wpdb->posts}.ID";
		$where            = '';

		$status_filters = array(
			'spotlight_status' => 'not_contacted',
			'website_status'   => 'unknown',
			'grow_status'      => 'unknown',
		);
		foreach ( $status_filters as $col => $default ) {
			if ( empty( $args[ $col ] ) ) {
				continue;
			}
			$values = array_filter( array_map( 'sanitize_key', (array) $args[ $col ] ) );
			if ( ! $values ) {
				continue;
			}
			$in = implode( ',', array_map( static function ( $v ) use ( $wpdb ) {
				return $wpdb->prepare( '%s', $v );
			}, $values ) );
			if ( in_array( $default, $values, true ) ) {
				$where .= " AND ( aso_o.church_id IS NULL OR aso_o.{$col} IN ({$in}) )";
			} else {
				$where .= " AND aso_o.{$col} IN ({$in})";
			}
		}
		foreach ( array( 'website_review', 'grow_fit' ) as $col ) {
			if ( isset( $args[ $col ] ) && '' !== $args[ $col ] && null !== $args[ $col ] ) {
				$where .= $wpdb->prepare( " AND aso_o.{$col} = %s", sanitize_key( $args[ $col ] ) );
			}
		}
		if ( ! empty( $args['contacted'] ) ) {
			$where .= 'yes' === $args['contacted']
				? ' AND aso_o.last_contacted_at IS NOT NULL'
				: ' AND ( aso_o.church_id IS NULL OR aso_o.last_contacted_at IS NULL )';
		}
		if ( ! empty( $args['unreviewed'] ) ) {
			$where .= " AND ( aso_o.website_review = '' OR aso_o.grow_fit = '' )";
		}
		if ( ! empty( $args['needs_review'] ) ) {
			$where .= ' AND aso_o.needs_review = 1';
		}
		if ( ! empty( $args['paused'] ) ) {
			$where .= ' AND aso_o.paused = 1';
		}
		if ( ! empty( $args['campaign_id'] ) ) {
			$where .= $wpdb->prepare( " AND EXISTS ( SELECT 1 FROM {$e} aso_e WHERE aso_e.church_id = {$wpdb->posts}.ID AND aso_e.campaign_id = %d )", (int) $args['campaign_id'] );
		}
		if ( ! empty( $args['opportunity'] ) ) {
			// Post-Spotlight review list: completed/published churches, or any church with an open opportunity.
			$col   = 'grow' === $args['opportunity'] ? 'grow_status' : 'website_status';
			$where .= " AND ( aso_o.spotlight_status IN ('completed','published') OR aso_o.{$col} NOT IN ('unknown','no_need') )";
		}
		$clauses['where'] .= $where;

		$order = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';
		switch ( $args['orderby'] ?? '' ) {
			case 'last_contacted':
				$clauses['orderby'] = "aso_o.last_contacted_at IS NULL, aso_o.last_contacted_at {$order}, {$wpdb->posts}.post_title ASC";
				break;
			case 'status':
				$clauses['orderby'] = "COALESCE(aso_o.spotlight_status,'not_contacted') {$order}, {$wpdb->posts}.post_title ASC";
				break;
			case 'updated':
				$clauses['orderby'] = "aso_o.updated_at IS NULL, aso_o.updated_at {$order}";
				break;
		}
		return $clauses;
	}
}
