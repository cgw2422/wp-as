<?php
/**
 * Email template list.
 *
 * @package ApostolicSpotlight\Outreach
 */

use ApostolicSpotlight\Outreach\Admin\Admin;
use ApostolicSpotlight\Outreach\Templates;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

Admin::header(
	__( 'Email Templates', 'apostolic-spotlight-outreach' ),
	array( array( 'label' => __( 'New Template', 'apostolic-spotlight-outreach' ), 'url' => Util::admin_url( 'aso-templates', array( 'view' => 'edit' ) ), 'primary' => true ) ),
	esc_html__( 'Plain, personal emails. Website and Apostolic Grow templates are kept separate and are only ever sent manually.', 'apostolic-spotlight-outreach' )
);

foreach ( Templates::categories() as $cat => $label ) :
	$list = Templates::all( array( $cat ) );
	if ( ! $list ) {
		continue;
	}
	?>
	<h2 class="aso-section-title"><?php echo esc_html( $label ); ?></h2>
	<div class="aso-grid aso-grid--cards">
		<?php foreach ( $list as $t ) : ?>
			<div class="aso-card aso-template-card">
				<div class="aso-card__head">
					<h3><a href="<?php echo esc_url( Util::admin_url( 'aso-templates', array( 'view' => 'edit', 'id' => $t->id ) ) ); ?>"><?php echo esc_html( $t->name ); ?></a></h3>
					<?php if ( in_array( $t->category, array( 'website', 'grow' ), true ) ) : ?>
						<span class="aso-badge aso-badge--gray"><?php esc_html_e( 'Manual only', 'apostolic-spotlight-outreach' ); ?></span>
					<?php endif; ?>
				</div>
				<p class="aso-template-card__subject"><?php echo esc_html( $t->subject ); ?></p>
				<p class="aso-muted"><?php echo esc_html( wp_trim_words( $t->body, 28 ) ); ?></p>
				<div class="aso-card__foot">
					<a class="button" href="<?php echo esc_url( Util::admin_url( 'aso-templates', array( 'view' => 'edit', 'id' => $t->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'apostolic-spotlight-outreach' ); ?></a>
					<a class="aso-link-danger" href="<?php echo esc_url( Admin::post_url( 'aso_template_delete', array( 'id' => $t->id ) ) ); ?>" data-aso-confirm="<?php esc_attr_e( 'Delete this template?', 'apostolic-spotlight-outreach' ); ?>"><?php esc_html_e( 'Delete', 'apostolic-spotlight-outreach' ); ?></a>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
<?php endforeach; ?>
