<?php
/**
 * Guide / post list (Design System 5.0). Used by category.php and home.php.
 *
 * $args: eyebrow, title, lead.
 *
 * @package LNKup_Theme
 */

$args = wp_parse_args( $args, array( 'eyebrow' => '', 'title' => '', 'lead' => '' ) );
?>
<div class="lnkup-site lp-site" data-testid="lnkup-resources-list">
	<?php get_template_part( 'template-parts/landing/site-header' ); ?>
	<main id="lp-main" class="lp-main">
		<section class="lp-section">
			<div class="lp-container">
				<?php lnkup_public_section_head( array( 'eyebrow' => (string) $args['eyebrow'], 'title' => (string) $args['title'], 'lead' => (string) $args['lead'], 'id' => 'lnkup-resources-title' ) ); ?>
				<?php if ( have_posts() ) : ?>
					<div class="lnkup-guide-list">
						<?php while ( have_posts() ) : ?>
							<?php the_post(); ?>
							<a class="lnkup-guide-card" href="<?php the_permalink(); ?>" data-testid="lnkup-guide-card">
								<?php $cats = get_the_category(); ?>
								<?php if ( $cats ) : ?><span class="lp-chip lp-chip--line"><?php echo esc_html( $cats[0]->name ); ?></span><?php endif; ?>
								<h3><?php the_title(); ?></h3>
								<p><?php echo esc_html( wp_trim_words( get_the_excerpt(), 24, '…' ) ); ?></p>
								<span class="lnkup-guide__meta"><?php echo esc_html( get_the_date() ); ?></span>
							</a>
						<?php endwhile; ?>
					</div>
					<nav class="lnkup-guide-pagination" aria-label="<?php esc_attr_e( 'More guides', 'lnkup-theme' ); ?>">
						<?php echo paginate_links( array( 'type' => 'plain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</nav>
				<?php else : ?>
					<div class="lp-empty">
						<h3><?php esc_html_e( 'Guides are on the way', 'lnkup-theme' ); ?></h3>
						<p><?php esc_html_e( 'New articles for vendors and organizers are being written now.', 'lnkup-theme' ); ?></p>
						<a class="lp-btn lp-btn--primary" href="<?php echo esc_url( lnkup_theme_opportunities_url() ); ?>"><?php esc_html_e( 'Browse events', 'lnkup-theme' ); ?></a>
					</div>
				<?php endif; ?>
			</div>
		</section>
	</main>
	<?php get_template_part( 'template-parts/landing/site-footer' ); ?>
</div>
