<?php
/**
 * Lightweight CRM table over the EXISTING directory churches.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach\Admin;

use ApostolicSpotlight\Outreach\Booking\Bookings;
use ApostolicSpotlight\Outreach\Church\Church;
use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Church\Outreach_State;
use ApostolicSpotlight\Outreach\Enrollments;
use ApostolicSpotlight\Outreach\Installer;
use ApostolicSpotlight\Outreach\Statuses;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class Churches_Table extends \WP_List_Table {

	/** @var array */
	public $filters = array();

	/** @var array<int,object> */
	private $states = array();
	/** @var array<int,object> */
	private $enrollments = array();
	/** @var array<int,object> */
	private $bookings = array();
	/** @var array<int,object> */
	private $queue = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'church',
				'plural'   => 'churches',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Filter keys read from the request (GET).
	 */
	public static function filter_keys() {
		return array( 's', 'organization', 'state', 'spotlight_status', 'website_status', 'grow_status', 'contacted', 'campaign_id', 'needs_review', 'paused' );
	}

	public static function read_filters( array $source ) {
		$f = array();
		foreach ( self::filter_keys() as $k ) {
			if ( isset( $source[ $k ] ) && '' !== $source[ $k ] ) {
				$f[ $k ] = sanitize_text_field( wp_unslash( $source[ $k ] ) );
			}
		}
		return $f;
	}

	public static function to_query_args( array $f ) {
		$args = array(
			'search'           => $f['s'] ?? '',
			'organization'     => $f['organization'] ?? '',
			'state'            => $f['state'] ?? '',
			'spotlight_status' => ! empty( $f['spotlight_status'] ) ? array( $f['spotlight_status'] ) : array(),
			'website_status'   => ! empty( $f['website_status'] ) ? array( $f['website_status'] ) : array(),
			'grow_status'      => ! empty( $f['grow_status'] ) ? array( $f['grow_status'] ) : array(),
			'contacted'        => $f['contacted'] ?? '',
			'campaign_id'      => (int) ( $f['campaign_id'] ?? 0 ),
			'needs_review'     => ! empty( $f['needs_review'] ),
			'paused'           => ! empty( $f['paused'] ),
		);
		return $args;
	}

	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'church'       => __( 'Church', 'apostolic-spotlight-outreach' ),
			'pastor'       => __( 'Pastor', 'apostolic-spotlight-outreach' ),
			'organization' => __( 'Organization', 'apostolic-spotlight-outreach' ),
			'location'     => __( 'City/State', 'apostolic-spotlight-outreach' ),
			'email'        => __( 'Email', 'apostolic-spotlight-outreach' ),
			'spotlight'    => __( 'Spotlight Status', 'apostolic-spotlight-outreach' ),
			'last_contact' => __( 'Last Contact', 'apostolic-spotlight-outreach' ),
			'next_action'  => __( 'Next Action', 'apostolic-spotlight-outreach' ),
			'booking'      => __( 'Booking', 'apostolic-spotlight-outreach' ),
			'website'      => __( 'Website Opp.', 'apostolic-spotlight-outreach' ),
			'grow'         => __( 'Grow Opp.', 'apostolic-spotlight-outreach' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'church'       => array( 'title', false ),
			'spotlight'    => array( 'status', false ),
			'last_contact' => array( 'last_contacted', true ),
		);
	}

	protected function get_primary_column_name() {
		return 'church';
	}

	public function prepare_items() {
		$this->filters = self::read_filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		$per_page      = $this->get_items_per_page( 'aso_churches_per_page', 50 );
		$args          = self::to_query_args( $this->filters );
		$args['paged']    = $this->get_pagenum();
		$args['per_page'] = $per_page;
		$args['orderby']  = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'title'; // phpcs:ignore WordPress.Security.NonceVerification
		$args['order']    = isset( $_GET['order'] ) && 'desc' === strtolower( sanitize_key( $_GET['order'] ) ) ? 'desc' : 'asc'; // phpcs:ignore WordPress.Security.NonceVerification

		$result = Church_Repository::query( $args );
		$ids    = $result['ids'];

		if ( $ids ) {
			_prime_post_caches( $ids, true, true );
		}
		$this->items = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post ) {
				$this->items[] = new Church( $post );
			}
		}
		$this->states      = Outreach_State::get_many( $ids );
		$this->enrollments = Enrollments::open_for_churches( $ids );
		$this->bookings    = Bookings::latest_for_churches( $ids );
		$this->queue       = self::next_queue_items( $ids );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'church' );
	}

	public static function next_queue_items( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return array();
		}
		$q    = Installer::table( 'queue' );
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results( "SELECT * FROM {$q} WHERE church_id IN ({$in}) AND status IN ('pending','scheduled','held') ORDER BY scheduled_at DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL -- ints only
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->church_id ] = $r;
		}
		return $out;
	}

	/**
	 * Human "next action" from current state – shared with the church screen.
	 */
	public static function next_action( $state, $enrollment = null, $booking = null, $queue = null ) {
		if ( $booking && 'confirmed' === $booking->status ) {
			$end = Util::from_db( $booking->end_at );
			if ( $end && $end < Util::now() ) {
				return array( __( 'Mark interview completed', 'apostolic-spotlight-outreach' ), 'amber' );
			}
			/* translators: %s: date */
			return array( sprintf( __( 'Interview %s', 'apostolic-spotlight-outreach' ), Util::short( $booking->start_at ) ), 'purple' );
		}
		if ( ! empty( $state->needs_review ) ) {
			return array( Outreach_State::review_reason_label( $state->review_reason ), 'amber' );
		}
		if ( ! empty( $state->paused ) ) {
			return array( __( 'Paused', 'apostolic-spotlight-outreach' ), 'gray' );
		}
		if ( $queue ) {
			$step = (int) $queue->step_number;
			$what = 1 === $step ? __( 'Invitation', 'apostolic-spotlight-outreach' ) : sprintf( /* translators: %d: number */ __( 'Follow-Up %d', 'apostolic-spotlight-outreach' ), $step - 1 );
			if ( 'held' === $queue->status ) {
				return array( $what . ' ' . __( 'held', 'apostolic-spotlight-outreach' ), 'amber' );
			}
			if ( $queue->scheduled_at ) {
				return array( $what . ' ' . Util::short( $queue->scheduled_at ), 'blue' );
			}
			return array( $what . ' ' . __( 'queued', 'apostolic-spotlight-outreach' ), 'blue' );
		}
		if ( $enrollment && 'active' === $enrollment->status && $enrollment->next_step_due_at ) {
			$step = (int) $enrollment->current_step + 1;
			$what = 1 === $step ? __( 'Invitation', 'apostolic-spotlight-outreach' ) : sprintf( /* translators: %d: number */ __( 'Follow-Up %d', 'apostolic-spotlight-outreach' ), $step - 1 );
			return array( $what . ' ' . Util::short( $enrollment->next_step_due_at ), 'slate' );
		}
		if ( $enrollment && 'finished' === $enrollment->status ) {
			return array( __( 'Awaiting reply', 'apostolic-spotlight-outreach' ), 'slate' );
		}
		if ( in_array( $state->spotlight_status, array( 'completed' ), true ) ) {
			return array( __( 'Publish Spotlight', 'apostolic-spotlight-outreach' ), 'green' );
		}
		if ( in_array( $state->spotlight_status, array( 'replied', 'interested' ), true ) ) {
			return array( __( 'Send booking link', 'apostolic-spotlight-outreach' ), 'teal' );
		}
		if ( $state->next_action ) {
			return array( $state->next_action, 'gray' );
		}
		if ( 'not_contacted' === $state->spotlight_status ) {
			return array( __( 'Start outreach', 'apostolic-spotlight-outreach' ), 'muted' );
		}
		return array( '—', 'muted' );
	}

	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="church_ids[]" value="%d" />', $item->id );
	}

	protected function column_church( $item ) {
		$url     = Util::church_admin_url( $item->id );
		$actions = array(
			'open' => sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Open', 'apostolic-spotlight-outreach' ) ),
		);
		if ( current_user_can( 'edit_post', $item->id ) ) {
			$actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( (string) $item->edit_link() ), esc_html__( 'Edit listing', 'apostolic-spotlight-outreach' ) );
		}
		$listing = $item->get( 'listing_url' );
		if ( $listing ) {
			$actions['view'] = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $listing ), esc_html__( 'View', 'apostolic-spotlight-outreach' ) );
		}
		$state = $this->states[ $item->id ];
		$flags = '';
		if ( ! empty( $state->needs_review ) ) {
			$flags .= ' <span class="aso-dot aso-dot--amber" title="' . esc_attr( Outreach_State::review_reason_label( $state->review_reason ) ) . '"></span>';
		}
		return sprintf( '<a class="row-title" href="%s">%s</a>%s', esc_url( $url ), esc_html( $item->name() ), $flags ) . $this->row_actions( $actions );
	}

	protected function column_pastor( $item ) {
		$name = $item->get( 'pastor_name' );
		return $name ? esc_html( $name ) : '<span class="aso-missing">' . esc_html__( 'Unknown', 'apostolic-spotlight-outreach' ) . '</span>';
	}

	protected function column_organization( $item ) {
		return esc_html( $item->get( 'organization' ) );
	}

	protected function column_location( $item ) {
		return esc_html( $item->location() );
	}

	protected function column_email( $item ) {
		$email = $item->outreach_email();
		if ( ! $email ) {
			return '<span class="aso-missing">' . esc_html__( 'No email', 'apostolic-spotlight-outreach' ) . '</span>';
		}
		return '<span class="aso-email">' . esc_html( $email ) . '</span>';
	}

	protected function column_spotlight( $item ) {
		return Statuses::badge( 'spotlight', $this->states[ $item->id ]->spotlight_status );
	}

	protected function column_last_contact( $item ) {
		$s = $this->states[ $item->id ];
		return $s->last_contacted_at ? esc_html( Util::short( $s->last_contacted_at ) ) : '<span class="aso-muted">—</span>';
	}

	protected function column_next_action( $item ) {
		list( $label, $tone ) = self::next_action(
			$this->states[ $item->id ],
			$this->enrollments[ $item->id ] ?? null,
			$this->bookings[ $item->id ] ?? null,
			$this->queue[ $item->id ] ?? null
		);
		return '<span class="aso-next aso-next--' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
	}

	protected function column_booking( $item ) {
		$b = $this->bookings[ $item->id ] ?? null;
		if ( ! $b ) {
			return '<span class="aso-muted">—</span>';
		}
		return Statuses::badge( 'booking', $b->status ) . '<br><small>' . esc_html( Util::short( $b->start_at ) ) . '</small>';
	}

	protected function column_website( $item ) {
		return Statuses::badge( 'website', $this->states[ $item->id ]->website_status );
	}

	protected function column_grow( $item ) {
		return Statuses::badge( 'grow', $this->states[ $item->id ]->grow_status );
	}

	public function no_items() {
		if ( ! Church_Repository::post_type() ) {
			esc_html_e( 'No church directory post type found. Configure it in Settings → Church Directory.', 'apostolic-spotlight-outreach' );
			return;
		}
		esc_html_e( 'No churches match these filters.', 'apostolic-spotlight-outreach' );
	}

	/**
	 * Custom bulk bar (posts to admin-post.php, so WP's "action" param can't be used).
	 */
	protected function bulk_actions( $which = '' ) {
		if ( 'top' !== $which ) {
			return;
		}
		$campaigns = array( '' => __( '— Choose campaign —', 'apostolic-spotlight-outreach' ) );
		foreach ( \ApostolicSpotlight\Outreach\Campaigns::enrollable() as $c ) {
			$campaigns[ $c->id ] = $c->name . ' (' . Statuses::label( 'campaign', $c->status ) . ')';
		}
		echo '<div class="aso-bulk">';
		echo Admin::select( // phpcs:ignore WordPress.Security.EscapeOutput
			'bulk_action',
			array(
				''               => __( 'Bulk actions', 'apostolic-spotlight-outreach' ),
				'add_to_campaign'=> __( 'Add to Campaign…', 'apostolic-spotlight-outreach' ),
				'mark_replied'   => __( 'Mark Replied', 'apostolic-spotlight-outreach' ),
				'mark_interested'=> __( 'Mark Interested', 'apostolic-spotlight-outreach' ),
				'not_interested' => __( 'Mark Not Interested', 'apostolic-spotlight-outreach' ),
				'pause'          => __( 'Pause Outreach', 'apostolic-spotlight-outreach' ),
				'resume'         => __( 'Resume Outreach', 'apostolic-spotlight-outreach' ),
			),
			'',
			'data-aso-bulk-action'
		);
		echo Admin::select( 'campaign_id', $campaigns, '', 'data-aso-bulk-campaign hidden' ); // phpcs:ignore WordPress.Security.EscapeOutput
		submit_button( __( 'Apply', 'apostolic-spotlight-outreach' ), 'action', 'aso_apply', false );
		echo '</div>';
	}
}
