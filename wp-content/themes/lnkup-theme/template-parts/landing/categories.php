<?php
/**
 * Vendor categories section.
 *
 * @package LNKup_Theme
 */

$eyebrow    = (string) lnkup_landing_get( 'cat_eyebrow', '' );
$title      = (string) lnkup_landing_get( 'cat_title', '' );
$aside      = (string) lnkup_landing_get( 'cat_aside', '' );
$categories = lnkup_landing_lines( (string) lnkup_landing_get( 'cat_list', '' ) );
$banner     = lnkup_landing_image_url( 'cat_image_id', 'images/events/sunset-miami.svg' );
?>
<section id="categories" class="lnkup-section lnkup-categories-section lnkup-reveal">
	<?php if ( $banner ) : ?>
		<div class="lnkup-categories-section__banner" aria-hidden="true">
			<img src="<?php echo esc_url( $banner ); ?>" alt="" width="1600" height="480" loading="lazy" />
			<span class="lnkup-categories-section__scrim"></span>
		</div>
	<?php endif; ?>
	<div class="lnkup-container">
		<div class="lnkup-section__header-row">
			<div class="lnkup-section__intro">
				<?php lnkup_theme_section_kicker( '03 / CATEGORIES', $eyebrow ); ?>
				<h2 class="lnkup-heading lnkup-heading--section"><?php echo esc_html( $title ); ?></h2>
			</div>
			<?php if ( $aside ) : ?>
				<p class="lnkup-section__aside"><?php echo esc_html( $aside ); ?></p>
			<?php endif; ?>
		</div>
		<div class="lnkup-categories">
			<?php foreach ( $categories as $cat ) : ?>
				<div class="lnkup-category">
					<span class="lnkup-category__icon" aria-hidden="true"><?php echo lnkup_theme_category_icon( $cat ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span><?php echo esc_html( $cat ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
