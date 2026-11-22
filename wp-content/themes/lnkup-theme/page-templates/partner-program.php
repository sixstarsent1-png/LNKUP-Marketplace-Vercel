<?php
/**
 * Partner program page (Affiliate Program / Partner With LNKup).
 *
 * Template Name: Partner Program
 *
 * Presentation only: terms and the application form are rendered by the plugin from the
 * published program rules.
 *
 * @package LNKup_Theme
 */

$lnkup_is_strategic = is_page( 'partner-with-lnkup' );
$lnkup_program      = $lnkup_is_strategic ? 'strategic_partner' : 'affiliate';

if ( $lnkup_is_strategic ) {
	$lnkup_copy = array(
		'kicker'  => __( 'Partner With LNKup', 'lnkup-theme' ),
		'title'   => __( 'Bring your organizer network to LNKup', 'lnkup-theme' ),
		'lead'    => __( 'For market associations, chambers, venue groups, production companies, universities and regional event networks that work with many organizers.', 'lnkup-theme' ),
		'who'     => array(
			__( 'Market associations and vendor associations', 'lnkup-theme' ),
			__( 'Chambers of commerce and downtown business organizations', 'lnkup-theme' ),
			__( 'Event production companies and venue operators', 'lnkup-theme' ),
			__( 'Universities, community organizations and regional event networks', 'lnkup-theme' ),
		),
		'steps'   => array(
			__( 'Tell us about your organization and the organizers you work with.', 'lnkup-theme' ),
			__( 'Meet with the LNKup partnerships team to agree on goals and terms.', 'lnkup-theme' ),
			__( 'Introduce organizers with your partner link and track their progress.', 'lnkup-theme' ),
			__( 'Review activation and booking outcomes together on a regular cadence.', 'lnkup-theme' ),
		),
	);
} else {
	$lnkup_copy = array(
		'kicker'  => __( 'Affiliate Program', 'lnkup-theme' ),
		'title'   => __( 'Refer independent event organizers to LNKup', 'lnkup-theme' ),
		'lead'    => __( 'For event promoters, producers, marketers, venue representatives and community connectors who know organizers looking for vendors.', 'lnkup-theme' ),
		'who'     => array(
			__( 'Event promoters and independent event producers', 'lnkup-theme' ),
			__( 'Marketing professionals and community organizers', 'lnkup-theme' ),
			__( 'Venue representatives and local business consultants', 'lnkup-theme' ),
			__( 'Regional event-industry connectors', 'lnkup-theme' ),
		),
		'steps'   => array(
			__( 'Apply below. LNKup reviews every application.', 'lnkup-theme' ),
			__( 'Once approved, you get a referral link, code and dashboard.', 'lnkup-theme' ),
			__( 'Introduce organizers; they register and publish events on LNKup.', 'lnkup-theme' ),
			__( 'Commission is recorded on verified paid bookings under the published terms.', 'lnkup-theme' ),
		),
	);
}

get_header();
?>
<div class="lnkup-site lnkup-site--connector">
	<?php get_template_part( 'template-parts/landing/site-header' ); ?>
	<main class="lnkup-site__main">
		<section class="lnkup-section lnkup-reveal">
			<div class="lnkup-container">
				<?php lnkup_theme_section_kicker( '01 / ' . strtoupper( $lnkup_copy['kicker'] ), $lnkup_copy['kicker'] ); ?>
				<h1 class="lnkup-heading lnkup-heading--section"><?php echo esc_html( $lnkup_copy['title'] ); ?></h1>
				<p class="lnkup-muted"><?php echo esc_html( $lnkup_copy['lead'] ); ?></p>
			</div>
		</section>
		<section class="lnkup-section lnkup-reveal">
			<div class="lnkup-container">
				<h2 class="lnkup-heading"><?php esc_html_e( 'Who it is for', 'lnkup-theme' ); ?></h2>
				<ul class="lnkup-connector-steps">
					<?php foreach ( $lnkup_copy['who'] as $lnkup_line ) : ?>
						<li><?php echo esc_html( $lnkup_line ); ?></li>
					<?php endforeach; ?>
				</ul>
				<h2 class="lnkup-heading"><?php esc_html_e( 'How it works', 'lnkup-theme' ); ?></h2>
				<ol class="lnkup-connector-steps">
					<?php foreach ( $lnkup_copy['steps'] as $lnkup_line ) : ?>
						<li><?php echo esc_html( $lnkup_line ); ?></li>
					<?php endforeach; ?>
				</ol>
				<h2 class="lnkup-heading"><?php esc_html_e( 'Program terms', 'lnkup-theme' ); ?></h2>
				<?php echo do_shortcode( '[lnkup_partner_program_terms program="' . esc_attr( $lnkup_program ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</section>
		<section id="apply" class="lnkup-section lnkup-band lnkup-band--dark lnkup-reveal">
			<div class="lnkup-container">
				<?php lnkup_theme_section_kicker( '02 / APPLY', __( 'Application', 'lnkup-theme' ) ); ?>
				<h2 class="lnkup-heading lnkup-heading--section"><?php echo esc_html( $lnkup_is_strategic ? __( 'Start a partnership conversation', 'lnkup-theme' ) : __( 'Apply to become an affiliate', 'lnkup-theme' ) ); ?></h2>
				<div class="lnkup-connector-panel">
					<?php echo do_shortcode( '[lnkup_partner_application program="' . esc_attr( $lnkup_program ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>
		</section>
		<section class="lnkup-section lnkup-reveal">
			<div class="lnkup-container">
				<h2 class="lnkup-heading"><?php esc_html_e( 'Frequently asked questions', 'lnkup-theme' ); ?></h2>
				<details><summary><?php esc_html_e( 'Is every applicant accepted?', 'lnkup-theme' ); ?></summary><p class="lnkup-muted"><?php esc_html_e( 'No. LNKup reviews every application and may ask for more information before deciding.', 'lnkup-theme' ); ?></p></details>
				<details><summary><?php esc_html_e( 'How are earnings calculated?', 'lnkup-theme' ); ?></summary><p class="lnkup-muted"><?php esc_html_e( 'Only under the published program terms shown above, on verified paid bookings. Refunded or disputed bookings are reversed. There is no base pay.', 'lnkup-theme' ); ?></p></details>
				<details><summary><?php esc_html_e( 'What if two people refer the same organizer?', 'lnkup-theme' ); ?></summary><p class="lnkup-muted"><?php esc_html_e( 'The earliest verified referral is credited. Close or conflicting claims are reviewed by LNKup, and one acquisition is never paid twice.', 'lnkup-theme' ); ?></p></details>
				<details><summary><?php esc_html_e( 'What can I say when I promote LNKup?', 'lnkup-theme' ); ?></summary><p class="lnkup-muted"><?php esc_html_e( 'Describe current LNKup features accurately and disclose your partner relationship. Do not promise organizers guaranteed vendor bookings, revenue or organizer counts.', 'lnkup-theme' ); ?></p></details>
				<p class="lnkup-muted"><small><?php esc_html_e( 'Approval and earnings are not guaranteed. Partners are independent and are responsible for their own taxes. LNKup may request tax information before paying commissions.', 'lnkup-theme' ); ?></small></p>
			</div>
		</section>
	</main>
	<?php get_template_part( 'template-parts/landing/site-footer' ); ?>
</div>
<?php
get_footer();
