<?php
/**
 * City market landing page template.
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

$ctx = function_exists( 'lnkup_theme_market_context' ) ? lnkup_theme_market_context() : null;
if ( ! $ctx ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

get_header();
?>
<div class="lnkup-site lnkup-site--market" data-testid="lnkup-market-page" data-market-slug="<?php echo esc_attr( $ctx['market']['slug'] ?? '' ); ?>">
	<?php get_template_part( 'template-parts/landing/site-header' ); ?>
	<main class="lnkup-site__main">
		<?php
		get_template_part( 'template-parts/market/hero' );
		get_template_part( 'template-parts/market/launch-status' );
		get_template_part( 'template-parts/market/categories' );
		get_template_part( 'template-parts/market/event-types' );
		get_template_part( 'template-parts/market/opportunities' );
		get_template_part( 'template-parts/market/organizer-cta' );
		get_template_part( 'template-parts/market/vendor-cta' );
		get_template_part( 'template-parts/market/connector-cta' );
		get_template_part( 'template-parts/market/how-it-works' );
		get_template_part( 'template-parts/market/faq' );
		?>
	</main>
	<?php get_template_part( 'template-parts/landing/site-footer' ); ?>
</div>
<?php
get_footer();
