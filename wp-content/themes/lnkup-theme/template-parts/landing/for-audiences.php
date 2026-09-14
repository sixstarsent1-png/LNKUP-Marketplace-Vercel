<?php
/**
 * Vendor vs organizer audience cards.
 *
 * @package LNKup_Theme
 */

$signup_url       = lnkup_theme_registration_url();
$organizer_url    = lnkup_page_url( 'organizer-dashboard' );
$profile_url      = lnkup_page_url( 'vendor-profile' );
$vendor_signup    = function_exists( 'lnkup_theme_market_signup_url' )
	? lnkup_theme_market_signup_url( 'vendor' )
	: add_query_arg( 'role', 'vendor', $signup_url );
$organizer_signup = function_exists( 'lnkup_theme_market_signup_url' )
	? lnkup_theme_market_signup_url( 'organizer' )
	: add_query_arg( 'role', 'organizer', $signup_url );
$eyebrow          = (string) lnkup_landing_get( 'aud_eyebrow', '' );
$title            = (string) lnkup_landing_get( 'aud_title', '' );
$lead             = trim( (string) lnkup_landing_get( 'aud_lead', '' ) );
$vendor_title     = (string) lnkup_landing_get( 'aud_vendor_title', '' );
$vendor_desc      = (string) lnkup_landing_get( 'aud_vendor_desc', '' );
$vendor_bullets   = lnkup_landing_lines( (string) lnkup_landing_get( 'aud_vendor_bullets', '' ) );
$vendor_cta       = (string) lnkup_landing_get( 'aud_vendor_cta', __( 'Sign up as vendor', 'lnkup-theme' ) );
$vendor_image     = lnkup_landing_image_url( 'aud_vendor_image_id', 'images/events/block-party.svg' );
$org_title        = (string) lnkup_landing_get( 'aud_org_title', '' );
$org_desc         = (string) lnkup_landing_get( 'aud_org_desc', '' );
$org_bullets      = lnkup_landing_lines( (string) lnkup_landing_get( 'aud_org_bullets', '' ) );
$org_cta          = (string) lnkup_landing_get( 'aud_org_cta', __( 'Sign up as organizer', 'lnkup-theme' ) );
$org_image        = lnkup_landing_image_url( 'aud_org_image_id', 'images/events/greek-picnic.svg' );
?>
<section class="lnkup-section lnkup-section--editorial lnkup-reveal" id="for-audiences">
	<div class="lnkup-container">
		<div class="lnkup-section__intro">
			<?php lnkup_theme_section_kicker( '02 / NETWORK', $eyebrow ); ?>
			<h2 class="lnkup-heading lnkup-heading--section"><?php echo esc_html( $title ); ?></h2>
			<?php if ( $lead ) : ?>
				<p class="lnkup-section__lead"><?php echo esc_html( $lead ); ?></p>
			<?php endif; ?>
		</div>
		<div class="lnkup-audience-grid">
			<article class="lnkup-card lnkup-audience-card lnkup-audience-card--visual">
				<div class="lnkup-audience-card__media">
					<img src="<?php echo esc_url( $vendor_image ); ?>" alt="" width="640" height="400" loading="lazy" />
				</div>
				<div class="lnkup-audience-card__body">
					<h3 class="lnkup-audience-card__title"><?php echo esc_html( $vendor_title ); ?></h3>
					<p class="lnkup-audience-card__desc"><?php echo esc_html( $vendor_desc ); ?></p>
					<?php if ( $vendor_bullets ) : ?>
						<ul class="lnkup-audience-card__list">
							<?php foreach ( $vendor_bullets as $bullet ) : ?>
								<li><?php echo esc_html( $bullet ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<a href="<?php echo esc_url( is_user_logged_in() ? $profile_url : $vendor_signup ); ?>" class="lnkup-btn lnkup-btn--primary"><?php echo esc_html( $vendor_cta ); ?></a>
				</div>
			</article>
			<article class="lnkup-card lnkup-audience-card lnkup-audience-card--visual">
				<div class="lnkup-audience-card__media">
					<img src="<?php echo esc_url( $org_image ); ?>" alt="" width="640" height="400" loading="lazy" />
				</div>
				<div class="lnkup-audience-card__body">
					<h3 class="lnkup-audience-card__title"><?php echo esc_html( $org_title ); ?></h3>
					<p class="lnkup-audience-card__desc"><?php echo esc_html( $org_desc ); ?></p>
					<?php if ( $org_bullets ) : ?>
						<ul class="lnkup-audience-card__list">
							<?php foreach ( $org_bullets as $bullet ) : ?>
								<li><?php echo esc_html( $bullet ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<a href="<?php echo esc_url( is_user_logged_in() ? $organizer_url : $organizer_signup ); ?>" class="lnkup-btn lnkup-btn--outline"><?php echo esc_html( $org_cta ); ?></a>
				</div>
			</article>
		</div>
	</div>
</section>
