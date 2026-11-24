<?php
/**
 * Single post / guide (Design System 5.0). Article + Breadcrumb schema come from
 * LNKup_Resources_Hub; related opportunities are live listings matched by the post's tags.
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

get_header();
the_post();
$hub     = class_exists( 'LNKup_Resources_Hub' );
$related = $hub ? LNKup_Resources_Hub::related_cards( get_the_ID() ) : array();
$cats    = get_the_category();
?>
<div class="lnkup-site lp-site" data-testid="lnkup-guide">
	<?php get_template_part( 'template-parts/landing/site-header' ); ?>
	<main id="lp-main" class="lp-main">
		<article class="lp-section">
			<div class="lp-container lnkup-guide">
				<nav class="lnkup-guide__crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'lnkup-theme' ); ?>">
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'lnkup-theme' ); ?></a>
					<?php if ( $hub ) : ?> / <a href="<?php echo esc_url( LNKup_Resources_Hub::resources_url() ); ?>"><?php esc_html_e( 'Resources', 'lnkup-theme' ); ?></a><?php endif; ?>
				</nav>
				<?php if ( $cats ) : ?><p class="lp-eyebrow"><?php echo esc_html( $cats[0]->name ); ?></p><?php endif; ?>
				<h1 class="lp-h1"><?php the_title(); ?></h1>
				<p class="lnkup-guide__meta">
					<?php
					/* translators: 1: published date, 2: updated date */
					echo esc_html( get_the_modified_date( 'Y-m-d' ) !== get_the_date( 'Y-m-d' ) ? sprintf( __( 'Published %1$s · Updated %2$s', 'lnkup-theme' ), get_the_date(), get_the_modified_date() ) : get_the_date() );
					?>
				</p>
				<?php if ( has_post_thumbnail() ) : ?>
					<figure class="lnkup-guide__media"><?php echo lnkup_public_image( (int) get_post_thumbnail_id(), array( 'alt' => get_the_title(), 'eager' => true, 'sizes' => '(max-width: 800px) 100vw, 760px' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></figure>
				<?php endif; ?>
				<div class="lnkup-guide__body"><?php the_content(); ?></div>
			</div>
		</article>
		<?php if ( $related ) : ?>
			<section class="lp-section lp-section--mist" aria-labelledby="lnkup-guide-related-title" data-testid="lnkup-guide-related">
				<div class="lp-container">
					<?php lnkup_public_section_head( array( 'eyebrow' => __( 'Live on LNKup', 'lnkup-theme' ), 'title' => __( 'Related vendor opportunities', 'lnkup-theme' ), 'id' => 'lnkup-guide-related-title' ) ); ?>
					<div class="lp-rail" style="--lp-rail-cols:3">
						<?php foreach ( $related as $card ) : ?>
							<?php get_template_part( 'template-parts/public/event-card', null, array( 'card' => $card, 'testid' => 'lnkup-guide-related-card' ) ); ?>
						<?php endforeach; ?>
					</div>
				</div>
			</section>
		<?php endif; ?>
		<?php get_template_part( 'template-parts/public/final-cta', null, array( 'audience' => 'vendor' ) ); ?>
	</main>
	<?php get_template_part( 'template-parts/landing/site-footer' ); ?>
</div>
<?php
get_footer();
