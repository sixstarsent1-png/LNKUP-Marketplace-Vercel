<?php
/**
 * Organizer section: how listing vendor spots, reviewing vendors and running the event works.
 *
 * @package LNKup_Theme
 */

$signup_url       = lnkup_theme_registration_url();
$organizer_url    = lnkup_page_url( 'organizer-dashboard' );
$organizer_signup = function_exists( 'lnkup_theme_market_signup_url' )
	? lnkup_theme_market_signup_url( 'organizer' )
	: add_query_arg( 'role', 'organizer', $signup_url );
$create_url       = is_user_logged_in()
	? ( function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'create' ) : $organizer_url . '?view=create' )
	: $organizer_signup;
$image            = lnkup_landing_image_url( 'aud_org_image_id', 'images/marketing/hero-market.jpg' );

$steps = array(
	array(
		'title' => __( 'List your vendor spots', 'lnkup-theme' ),
		'body'  => __( 'Booth types, sizes, fees and how many you have. Publish once and vendors can apply.', 'lnkup-theme' ),
	),
	array(
		'title' => __( 'Review vendors side by side', 'lnkup-theme' ),
		'body'  => __( 'Profiles, documents and answers in one queue. Approve, waitlist or ask for more.', 'lnkup-theme' ),
	),
	array(
		'title' => __( 'Get paid and run the day', 'lnkup-theme' ),
		'body'  => __( 'Vendors pay after you approve. Check them in at load-in from your phone.', 'lnkup-theme' ),
	),
);
?>
<section class="lnkup-section lnkup-section--organizers lnkup-reveal" id="for-organizers" data-testid="lnkup-home-organizers">
	<div class="lnkup-container lnkup-organizers">
		<div class="lnkup-organizers__copy">
			<p class="lnkup-eyebrow"><?php esc_html_e( 'For organizers', 'lnkup-theme' ); ?></p>
			<h2 class="lnkup-heading lnkup-heading--section"><?php esc_html_e( 'Fill your vendor lineup without the spreadsheet', 'lnkup-theme' ); ?></h2>
			<ol class="lnkup-organizers__steps">
				<?php foreach ( $steps as $i => $step ) : ?>
					<li>
						<span class="lnkup-organizers__num" aria-hidden="true"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
						<div>
							<h3><?php echo esc_html( $step['title'] ); ?></h3>
							<p><?php echo esc_html( $step['body'] ); ?></p>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>
			<div class="lnkup-organizers__actions">
				<a href="<?php echo esc_url( $create_url ); ?>" class="lnkup-btn lnkup-btn--primary lnkup-btn--lg" data-testid="lnkup-home-organizer-cta"><?php esc_html_e( 'List your event', 'lnkup-theme' ); ?></a>
				<a href="<?php echo esc_url( lnkup_page_url( 'organizers' ) ); ?>" class="lnkup-btn lnkup-btn--outline lnkup-btn--lg lnkup-btn--on-dark"><?php esc_html_e( 'Browse organizers', 'lnkup-theme' ); ?></a>
			</div>
		</div>
		<?php if ( $image ) : ?>
			<figure class="lnkup-organizers__media">
				<img src="<?php echo esc_url( $image ); ?>" alt="<?php esc_attr_e( 'Vendors setting up booths at an evening market', 'lnkup-theme' ); ?>" width="960" height="720" loading="lazy" decoding="async" />
			</figure>
		<?php endif; ?>
	</div>
</section>
