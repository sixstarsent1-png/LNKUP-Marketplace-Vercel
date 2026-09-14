<?php
/**
 * How it works section.
 *
 * @package LNKup_Theme
 */

$eyebrow = (string) lnkup_landing_get( 'hiw_eyebrow', __( 'How it works', 'lnkup-theme' ) );
$title   = (string) lnkup_landing_get( 'hiw_title', '' );
$steps   = lnkup_landing_get( 'hiw_steps', array() );
if ( ! is_array( $steps ) ) {
	$steps = array();
}

$fallback_images = array(
	'images/events/block-party.svg',
	'images/events/ghoe-market.svg',
	'images/events/greek-picnic.svg',
	'images/events/rodeo.svg',
);

$step_icons = array(
	'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>',
	'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
	'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>',
	'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
);
?>
<section id="how-it-works" class="lnkup-section lnkup-section--editorial lnkup-reveal">
	<div class="lnkup-container">
		<div class="lnkup-section__intro">
			<?php lnkup_theme_section_kicker( '01 / VENDORS', $eyebrow ); ?>
			<h2 class="lnkup-heading lnkup-heading--section"><?php echo esc_html( $title ); ?></h2>
		</div>
		<ol class="lnkup-steps">
			<?php foreach ( $steps as $i => $step ) : ?>
				<?php
				$step       = is_array( $step ) ? $step : array();
				$step_title = (string) ( $step['title'] ?? '' );
				$step_body  = (string) ( $step['body'] ?? '' );
				$img_fallback = $fallback_images[ $i % count( $fallback_images ) ];
				$step_img   = lnkup_landing_attachment_url( (int) ( $step['image_id'] ?? 0 ), $img_fallback );
				$icon       = $step_icons[ $i % count( $step_icons ) ];
				?>
				<li class="lnkup-card lnkup-step">
					<?php if ( $step_img ) : ?>
						<div class="lnkup-step__media">
							<img src="<?php echo esc_url( $step_img ); ?>" alt="" width="480" height="300" loading="lazy" />
						</div>
					<?php endif; ?>
					<div class="lnkup-step__top">
						<span class="lnkup-step__icon" aria-hidden="true"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="lnkup-step__number">0<?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
					</div>
					<h3 class="lnkup-step__title"><?php echo esc_html( $step_title ); ?></h3>
					<p class="lnkup-step__desc"><?php echo esc_html( $step_body ); ?></p>
				</li>
			<?php endforeach; ?>
		</ol>
	</div>
</section>
