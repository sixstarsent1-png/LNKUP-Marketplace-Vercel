<?php
/**
 * Default page template.
 *
 * @package LNKup_Theme
 */

get_header();
?>
<div class="lnkup-site">
	<?php get_template_part( 'template-parts/landing/site-header' ); ?>
	<main class="lnkup-site__main lnkup-container lnkup-section">
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<article <?php post_class( 'lnkup-prose' ); ?>>
				<h1 class="lnkup-heading lnkup-heading--page"><?php the_title(); ?></h1>
				<div class="lnkup-content"><?php the_content(); ?></div>
			</article>
			<?php
		endwhile;
		?>
	</main>
	<?php get_template_part( 'template-parts/landing/site-footer' ); ?>
</div>
<?php
get_footer();
