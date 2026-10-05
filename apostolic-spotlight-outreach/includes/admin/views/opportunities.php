<?php
/**
 * Post-Spotlight opportunity review. Nothing here sends anything automatically.
 *
 * @package ApostolicSpotlight\Outreach
 */

use ApostolicSpotlight\Outreach\Admin\Admin;
use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Church\Outreach_State;
use ApostolicSpotlight\Outreach\Statuses;
use ApostolicSpotlight\Outreach\Templates;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'review'; // phpcs:ignore WordPress.Security.NonceVerification
$tabs = array(
	'review'  => __( 'Needs review', 'apostolic-spotlight-outreach' ),
	'website' => __( 'Website pipeline', 'apostolic-spotlight-outreach' ),
	'grow'    => __( 'Apostolic Grow pipeline', 'apostolic-spotlight-outreach' ),
	'all'     => __( 'All completed Spotlights', 'apostolic-spotlight-outreach' ),
);
if ( ! isset( $tabs[ $tab ] ) ) {
	$tab = 'review';
}
$args = array( 'per_page' => 100, 'paged' => max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ), 'orderby' => 'updated', 'order' => 'desc' ); // phpcs:ignore WordPress.Security.NonceVerification
switch ( $tab ) {
	case 'review':
		$args['spotlight_status'] = array( 'completed', 'published' );
		$args['unreviewed']       = true;
		break;
	case 'website':
		$args['website_status'] = array( 'potential', 'interested', 'proposal', 'won', 'lost' );
		break;
	case 'grow':
		$args['grow_status'] = array( 'potential', 'interested', 'demo_scheduled', 'customer', 'lost' );
		break;
	default:
		$args['spotlight_status'] = array( 'completed', 'published' );
}
$result = Church_Repository::query( $args );
$ids    = $result['ids'];
if ( $ids ) {
	_prime_post_caches( $ids, false, true );
}
$states = Outreach_State::get_many( $ids );

$website_tpl = Templates::all( array( 'website' ) );
$grow_tpl    = Templates::all( array( 'grow' ) );

Admin::header(
	__( 'Opportunities', 'apostolic-spotlight-outreach' ),
	array(),
	esc_html__( 'After a Spotlight, review each church thoughtfully. Website and Apostolic Grow are separate – a church may fit one, both, or neither. Follow-ups are always your decision.', 'apostolic-spotlight-outreach' )
);

echo '<nav class="aso-tabs">';
foreach ( $tabs as $k => $label ) {
	printf( '<a class="aso-tab %s" href="%s">%s</a>', esc_attr( $k === $tab ? 'is-active' : '' ), esc_url( Util::admin_url( 'aso-opportunities', array( 'tab' => $k ) ) ), esc_html( $label ) );
}
echo '</nav>';

if ( ! $ids ) {
	Admin::empty_state( 'review' === $tab ? __( 'All caught up', 'apostolic-spotlight-outreach' ) : __( 'Nothing here yet', 'apostolic-spotlight-outreach' ), 'review' === $tab ? __( 'Completed Spotlights appear here until both the website and Apostolic Grow reviews are recorded.', 'apostolic-spotlight-outreach' ) : '' );
	return;
}
?>
<div class="aso-opps">
	<?php foreach ( $ids as $id ) : ?>
		<?php
		$church = Church_Repository::find( $id );
		if ( ! $church ) {
			continue;
		}
		$s = $states[ $id ];
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aso-card aso-opp">
			<input type="hidden" name="action" value="aso_opportunity_save">
			<input type="hidden" name="church_id" value="<?php echo esc_attr( $id ); ?>">
			<?php wp_nonce_field( 'aso_opportunity_save' ); ?>
			<div class="aso-opp__church">
				<a href="<?php echo esc_url( Util::church_admin_url( $id ) ); ?>"><strong><?php echo esc_html( $church->name() ); ?></strong></a>
				<?php echo Statuses::badge( 'spotlight', $s->spotlight_status ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<div class="aso-muted"><?php echo esc_html( implode( ' · ', array_filter( array( $church->get( 'pastor_name' ), $church->location(), $church->get( 'organization' ) ) ) ) ); ?></div>
				<?php if ( $church->get( 'website' ) ) : ?>
					<a href="<?php echo esc_url( $church->get( 'website' ) ); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-external"></span> <?php echo esc_html( preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( $church->get( 'website' ) ) ) ); ?></a>
				<?php else : ?>
					<span class="aso-missing"><?php esc_html_e( 'No website on file', 'apostolic-spotlight-outreach' ); ?></span>
				<?php endif; ?>
				<?php if ( 'completed' === $s->spotlight_status ) : ?>
					<label class="aso-check"><input type="checkbox" name="spotlight_status" value="published"> <?php esc_html_e( 'Spotlight is published', 'apostolic-spotlight-outreach' ); ?></label>
				<?php endif; ?>
			</div>
			<div class="aso-opp__col">
				<h4><?php esc_html_e( 'Website', 'apostolic-spotlight-outreach' ); ?></h4>
				<label><?php esc_html_e( 'Review', 'apostolic-spotlight-outreach' ); ?> <?php echo Admin::select( 'website_review', Statuses::options( 'website_review' ), $s->website_review ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
				<label><?php esc_html_e( 'Opportunity', 'apostolic-spotlight-outreach' ); ?> <?php echo Admin::select( 'website_status', Statuses::options( 'website' ), $s->website_status ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
				<?php foreach ( $website_tpl as $t ) : ?>
					<a class="button button-small" href="<?php echo esc_url( Util::admin_url( 'aso-churches', array( 'view' => 'compose', 'church' => $id, 'template' => $t->id ) ) ); ?>"><?php esc_html_e( 'Draft website follow-up', 'apostolic-spotlight-outreach' ); ?></a>
					<?php break; ?>
				<?php endforeach; ?>
			</div>
			<div class="aso-opp__col">
				<h4><?php esc_html_e( 'Apostolic Grow', 'apostolic-spotlight-outreach' ); ?></h4>
				<label><?php esc_html_e( 'Fit', 'apostolic-spotlight-outreach' ); ?> <?php echo Admin::select( 'grow_fit', Statuses::options( 'grow_fit' ), $s->grow_fit ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
				<label><?php esc_html_e( 'Opportunity', 'apostolic-spotlight-outreach' ); ?> <?php echo Admin::select( 'grow_status', Statuses::options( 'grow' ), $s->grow_status ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
				<?php foreach ( $grow_tpl as $t ) : ?>
					<a class="button button-small" href="<?php echo esc_url( Util::admin_url( 'aso-churches', array( 'view' => 'compose', 'church' => $id, 'template' => $t->id ) ) ); ?>"><?php esc_html_e( 'Draft Grow follow-up', 'apostolic-spotlight-outreach' ); ?></a>
					<?php break; ?>
				<?php endforeach; ?>
			</div>
			<div class="aso-opp__save"><button class="button button-primary"><?php esc_html_e( 'Save review', 'apostolic-spotlight-outreach' ); ?></button></div>
		</form>
	<?php endforeach; ?>
</div>
