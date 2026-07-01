<?php
/**
 * Market FAQ section.
 *
 * @package LNKup_Theme
 */

$ctx = lnkup_theme_market_context();
$market_name = $ctx ? $ctx['market']['display_name'] : 'LNKup';

$faqs = array(
	array(
		'q' => __( 'Is LNKup a ticketing platform?', 'lnkup-theme' ),
		'a' => __( 'No. LNKup is a vendor opportunity marketplace for live events — not attendee ticketing.', 'lnkup-theme' ),
	),
	array(
		'q' => __( 'Do vendors pay to join?', 'lnkup-theme' ),
		'a' => __( 'Joining and applying are free. Vendors pay only when accepted to an event.', 'lnkup-theme' ),
	),
	array(
		'q' => __( 'How do vendors apply?', 'lnkup-theme' ),
		'a' => __( 'Create a vendor profile once, then apply to opportunities with that profile and upload documents when requested.', 'lnkup-theme' ),
	),
	array(
		'q' => __( 'Can organizers invite private vendors?', 'lnkup-theme' ),
		'a' => __( 'Yes. Organizers can invite existing vendors and manage applications through their dashboard.', 'lnkup-theme' ),
	),
	array(
		'q' => __( 'What cities is LNKup launching in?', 'lnkup-theme' ),
		'a' => sprintf(
			/* translators: %s: city name */
			__( 'LNKup is building city by city. This page covers %s — more markets are coming online over time.', 'lnkup-theme' ),
			$market_name
		),
	),
	array(
		'q' => __( 'What kind of vendors can join?', 'lnkup-theme' ),
		'a' => __( 'Food vendors, food trucks, retail brands, beauty, jewelry, photo booths, art, wellness, services, and mobile businesses.', 'lnkup-theme' ),
	),
);
?>
<section class="lnkup-section lnkup-section--bordered lnkup-market-faq lnkup-reveal" id="faq">
	<div class="lnkup-container">
		<div class="lnkup-section__intro">
			<p class="lnkup-eyebrow"><?php esc_html_e( 'FAQ', 'lnkup-theme' ); ?></p>
			<h2 class="lnkup-heading lnkup-heading--section"><?php esc_html_e( 'Common questions', 'lnkup-theme' ); ?></h2>
		</div>
		<div class="lnkup-market-faq__list">
			<?php foreach ( $faqs as $faq ) : ?>
				<details class="lnkup-market-faq__item">
					<summary><?php echo esc_html( $faq['q'] ); ?></summary>
					<p><?php echo esc_html( $faq['a'] ); ?></p>
				</details>
			<?php endforeach; ?>
		</div>
	</div>
</section>
