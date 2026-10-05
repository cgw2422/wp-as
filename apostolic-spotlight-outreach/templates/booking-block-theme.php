<?php
/**
 * Standalone document for block themes (which have no header.php/footer.php).
 * Renders the theme's header/footer template parts around the scheduler.
 *
 * @var string $content
 * @package ApostolicSpotlight\Outreach
 */

defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'aso-booking-page' ); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
	<?php echo do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<main class="aso-booking-main"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in templates. ?></main>
	<?php echo do_blocks( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
