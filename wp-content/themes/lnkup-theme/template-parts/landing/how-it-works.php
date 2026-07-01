<?php
/**
 * How it works section.
 *
 * @package LNKup_Theme
 */

$steps = array(
	array(
		'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>',
		'title' => __( 'Create your vendor profile', 'lnkup-theme' ),
		'desc'  => __( 'Build a profile that shows organizers who you are, what you sell, and where you set up.', 'lnkup-theme' ),
	),
	array(
		'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
		'title' => __( 'Browse event opportunities', 'lnkup-theme' ),
		'desc'  => __( 'Filter by city, date, attendance, and vendor fee to find events that fit your business.', 'lnkup-theme' ),
	),
	array(
		'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>',
		'title' => __( 'Apply for free', 'lnkup-theme' ),
		'desc'  => __( 'Submit applications to as many events as you want — applying never costs a thing.', 'lnkup-theme' ),
	),
	array(
		'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
		'title' => __( 'Pay only when accepted', 'lnkup-theme' ),
		'desc'  => __( 'No upfront fees. You only pay your vendor spot once an organizer approves you.', 'lnkup-theme' ),
	),
);
?>
<section id="how-it-works" class="lnkup-section lnkup-section--muted lnkup-section--bordered lnkup-reveal">
	<div class="lnkup-container">
		<div class="lnkup-section__intro">
			<p class="lnkup-eyebrow"><?php esc_html_e( 'How it works', 'lnkup-theme' ); ?></p>
			<h2 class="lnkup-heading lnkup-heading--section"><?php esc_html_e( 'From profile to paid in four steps', 'lnkup-theme' ); ?></h2>
		</div>
		<ol class="lnkup-steps">
			<?php foreach ( $steps as $i => $step ) : ?>
				<li class="lnkup-card lnkup-step">
					<div class="lnkup-step__top">
						<span class="lnkup-step__icon" aria-hidden="true"><?php echo $step['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="lnkup-step__number">0<?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
					</div>
					<h3 class="lnkup-step__title"><?php echo esc_html( $step['title'] ); ?></h3>
					<p class="lnkup-step__desc"><?php echo esc_html( $step['desc'] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ol>
	</div>
</section>
