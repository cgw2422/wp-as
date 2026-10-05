<?php
/**
 * Churches (CRM list).
 *
 * @package ApostolicSpotlight\Outreach
 */

use ApostolicSpotlight\Outreach\Admin\Admin;
use ApostolicSpotlight\Outreach\Admin\Churches_Table;
use ApostolicSpotlight\Outreach\Campaigns;
use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Church\Field_Map;
use ApostolicSpotlight\Outreach\Statuses;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

$table = new Churches_Table();
$table->prepare_items();
$f     = $table->filters;
$total = $table->get_pagination_arg( 'total_items' );

$pt_obj = get_post_type_object( Church_Repository::post_type() );
Admin::header(
	__( 'Churches', 'apostolic-spotlight-outreach' ),
	array(
		array( 'label' => __( 'Not Contacted', 'apostolic-spotlight-outreach' ), 'url' => Util::admin_url( 'aso-churches', array( 'spotlight_status' => 'not_contacted' ) ) ),
		array( 'label' => __( 'Needs Review', 'apostolic-spotlight-outreach' ), 'url' => Util::admin_url( 'aso-churches', array( 'needs_review' => 1 ) ) ),
	),
	$pt_obj
		/* translators: %s: post type label */
		? sprintf( esc_html__( 'Reading your existing directory: %s', 'apostolic-spotlight-outreach' ), '<strong>' . esc_html( $pt_obj->label ) . '</strong>' )
		: ''
);

$opt = static function ( $group, $all ) {
	return array( '' => $all ) + Statuses::options( $group );
};
$orgs   = Church_Repository::distinct_values( 'organization' );
$states = Church_Repository::distinct_values( 'state' );
$camps  = array( '' => __( 'Any campaign', 'apostolic-spotlight-outreach' ) );
foreach ( Campaigns::all( true ) as $c ) {
	$camps[ $c->id ] = $c->name;
}
?>

<form method="get" class="aso-filters">
	<input type="hidden" name="page" value="aso-churches">
	<div class="aso-filters__row">
		<input type="search" name="s" value="<?php echo esc_attr( $f['s'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Search churches…', 'apostolic-spotlight-outreach' ); ?>">
		<?php
		if ( $orgs ) {
			echo Admin::select( 'organization', array( '' => __( 'Any organization', 'apostolic-spotlight-outreach' ) ) + $orgs, $f['organization'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( Field_Map::meta_key( 'organization' ) ) {
			echo '<input type="text" name="organization" placeholder="' . esc_attr__( 'Organization', 'apostolic-spotlight-outreach' ) . '" value="' . esc_attr( $f['organization'] ?? '' ) . '">';
		}
		if ( $states ) {
			echo Admin::select( 'state', array( '' => __( 'Any state', 'apostolic-spotlight-outreach' ) ) + $states, $f['state'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( Field_Map::meta_key( 'state' ) ) {
			echo '<input type="text" name="state" placeholder="' . esc_attr__( 'State', 'apostolic-spotlight-outreach' ) . '" value="' . esc_attr( $f['state'] ?? '' ) . '">';
		}
		echo Admin::select( 'spotlight_status', $opt( 'spotlight', __( 'Any Spotlight status', 'apostolic-spotlight-outreach' ) ), $f['spotlight_status'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo Admin::select( 'contacted', array( '' => __( 'Contacted or not', 'apostolic-spotlight-outreach' ), 'no' => __( 'Never contacted', 'apostolic-spotlight-outreach' ), 'yes' => __( 'Contacted', 'apostolic-spotlight-outreach' ) ), $f['contacted'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
	</div>
	<div class="aso-filters__row">
		<?php
		echo Admin::select( 'website_status', $opt( 'website', __( 'Any website opportunity', 'apostolic-spotlight-outreach' ) ), $f['website_status'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo Admin::select( 'grow_status', $opt( 'grow', __( 'Any Grow opportunity', 'apostolic-spotlight-outreach' ) ), $f['grow_status'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo Admin::select( 'campaign_id', $camps, $f['campaign_id'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<label class="aso-check"><input type="checkbox" name="needs_review" value="1" <?php checked( ! empty( $f['needs_review'] ) ); ?>> <?php esc_html_e( 'Needs review', 'apostolic-spotlight-outreach' ); ?></label>
		<label class="aso-check"><input type="checkbox" name="paused" value="1" <?php checked( ! empty( $f['paused'] ) ); ?>> <?php esc_html_e( 'Paused', 'apostolic-spotlight-outreach' ); ?></label>
		<button class="button button-primary"><?php esc_html_e( 'Filter', 'apostolic-spotlight-outreach' ); ?></button>
		<?php if ( $f ) : ?>
			<a class="button-link" href="<?php echo esc_url( Util::admin_url( 'aso-churches' ) ); ?>"><?php esc_html_e( 'Clear filters', 'apostolic-spotlight-outreach' ); ?></a>
		<?php endif; ?>
	</div>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aso-table-form" data-aso-churches-form>
	<input type="hidden" name="action" value="aso_church_bulk">
	<?php /* WP_List_Table::display() prints the "bulk-churches" nonce. */ ?>
	<?php foreach ( $f as $k => $v ) : ?>
		<input type="hidden" name="filters[<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( $v ); ?>">
	<?php endforeach; ?>
	<input type="hidden" name="all_matching" value="" data-aso-all-matching>

	<div class="aso-select-all" data-aso-select-all-banner hidden>
		<span data-aso-page-selected></span>
		<?php if ( $total > count( $table->items ) ) : ?>
			<button type="button" class="button-link" data-aso-select-all-matching data-total="<?php echo esc_attr( $total ); ?>">
				<?php
				/* translators: %s: number of churches */
				echo esc_html( sprintf( __( 'Select all %s churches matching these filters', 'apostolic-spotlight-outreach' ), number_format_i18n( $total ) ) );
				?>
			</button>
		<?php endif; ?>
	</div>

	<?php $table->display(); ?>
</form>
