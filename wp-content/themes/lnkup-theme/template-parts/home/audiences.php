<?php
/**
 * Two audience panels linking to the acquisition landings.
 *
 * @package LNKup_Theme
 */

$vendor_points = array(
	__( 'Apply free to as many events as you like', 'lnkup-theme' ),
	__( 'See booth prices and open spots before you apply', 'lnkup-theme' ),
	__( 'Pay only after an organizer approves you', 'lnkup-theme' ),
	__( 'One profile with your photos, categories and documents', 'lnkup-theme' ),
);
$organizer_points = array(
	__( 'Publish booth types, prices, spots and deadlines', 'lnkup-theme' ),
	__( 'Review and approve applications in one queue', 'lnkup-theme' ),
	__( 'Vendors pay online; payouts go to your bank', 'lnkup-theme' ),
	__( 'Keep selling tickets wherever you already do', 'lnkup-theme' ),
);
?>
<section class="lp-section" aria-labelledby="lp-aud-title" data-testid="lp-audiences">
	<div class="lp-container">
		<?php
		lnkup_public_section_head(
			array(
				'eyebrow' => __( 'Built for both sides', 'lnkup-theme' ),
				'title'   => __( 'Vendors grow. Organizers fill every booth.', 'lnkup-theme' ),
				'id'      => 'lp-aud-title',
			)
		);
		?>
		<div class="lp-audience">
			<article class="lp-audience__card">
				<span class="lp-chip lp-chip--lime" style="align-self:flex-start"><?php esc_html_e( 'Vendors', 'lnkup-theme' ); ?></span>
				<h3 class="lp-h3"><?php esc_html_e( 'Find events worth your weekend', 'lnkup-theme' ); ?></h3>
				<p class="lp-muted"><?php esc_html_e( 'Food trucks, makers, boutiques, artists, beauty and service vendors.', 'lnkup-theme' ); ?></p>
				<ul class="lp-checks"><?php foreach ( $vendor_points as $point ) : ?><li><?php echo esc_html( $point ); ?></li><?php endforeach; ?></ul>
				<div class="lp-actions">
					<a class="lp-btn lp-btn--primary" href="<?php echo esc_url( lnkup_public_signup_url( 'vendor' ) ); ?>"><?php esc_html_e( 'Create a free profile', 'lnkup-theme' ); ?></a>
					<a class="lp-btn lp-btn--ghost" href="<?php echo esc_url( home_url( '/for-vendors/' ) ); ?>"><?php esc_html_e( 'How it works for vendors', 'lnkup-theme' ); ?></a>
				</div>
			</article>
			<article class="lp-audience__card lp-audience__card--ink">
				<span class="lp-chip lp-chip--lime" style="align-self:flex-start"><?php esc_html_e( 'Organizers', 'lnkup-theme' ); ?></span>
				<h3 class="lp-h3"><?php esc_html_e( 'The vendor side of your event, handled', 'lnkup-theme' ); ?></h3>
				<p class="lp-muted"><?php esc_html_e( 'Festivals, night markets, pop-ups, block parties and community events.', 'lnkup-theme' ); ?></p>
				<ul class="lp-checks"><?php foreach ( $organizer_points as $point ) : ?><li><?php echo esc_html( $point ); ?></li><?php endforeach; ?></ul>
				<div class="lp-actions">
					<a class="lp-btn lp-btn--lime" href="<?php echo esc_url( lnkup_public_signup_url( 'organizer' ) ); ?>"><?php esc_html_e( 'List your event free', 'lnkup-theme' ); ?></a>
					<a class="lp-btn lp-btn--on-dark" href="<?php echo esc_url( home_url( '/for-organizers/' ) ); ?>"><?php esc_html_e( 'How it works for organizers', 'lnkup-theme' ); ?></a>
				</div>
			</article>
		</div>
	</div>
</section>
