<?php
/**
 * Template Name: Landing: Organizers
 *
 * /for-organizers/ and its campaign variants (/for-organizers/{slug}/).
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

$ctx      = class_exists( 'LNKup_Campaign_Landings' ) ? LNKup_Campaign_Landings::context() : null;
$variant  = $ctx && $ctx['variant'] ? $ctx['variant'] : null;
$filters  = $ctx ? (array) $ctx['filters'] : array();
$pricing  = lnkup_public_pricing();
$copy     = lnkup_public_pricing_copy();
$walk_url = (string) lnkup_landing_get( 'org_walkthrough_url', '' );
$walk_url = ( '' !== $walk_url && 0 === strpos( $walk_url, 'https://' ) ) ? $walk_url : '';
$city     = ! empty( $filters['label'] ) ? (string) $filters['label'] : '';
$image    = $variant && $variant['image_id'] ? (int) $variant['image_id'] : ( (int) lnkup_landing_get( 'org_hero_image_id', 0 ) ?: 'images/marketing/hero-market.jpg' );
$title    = $variant && $variant['headline'] ? (string) $variant['headline'] : (string) lnkup_public_setting( 'org_title', __( 'Fill every vendor booth without the spreadsheets.', 'lnkup-theme' ) );
$lead     = $variant && $variant['subhead'] ? (string) $variant['subhead'] : (string) lnkup_public_setting( 'org_lead', __( 'Publish vendor opportunities, review applications, collect booth fees and run load-in from one dashboard. Keep selling tickets wherever you already do.', 'lnkup-theme' ) );
$eyebrow  = $variant && $variant['eyebrow'] ? (string) $variant['eyebrow'] : ( $city ? sprintf( /* translators: %s: city */ __( 'For event organizers in %s', 'lnkup-theme' ), $city ) : __( 'For event organizers', 'lnkup-theme' ) );
$cta      = $variant && $variant['cta_label'] ? (string) $variant['cta_label'] : __( 'List your event free', 'lnkup-theme' );
$ctas     = array( array( $cta, lnkup_public_signup_url( 'organizer' ), 'lp-btn--lime', 'lp-org-cta-primary' ) );
if ( $walk_url ) {
	$ctas[] = array( __( 'Book a walkthrough', 'lnkup-theme' ), $walk_url, 'lp-btn--on-dark', 'lp-org-walkthrough' );
} else {
	$ctas[] = array( __( 'See pricing', 'lnkup-theme' ), '#pricing', 'lp-btn--on-dark', 'lp-org-pricing-link' );
}
$vendors    = class_exists( 'LNKup_Public_Discovery' ) ? LNKup_Public_Discovery::featured_vendors( 8 ) : array();
$categories = array_slice( (array) ( lnkup_public_discovery()['categories'] ?? array() ), 0, 8 );
$steps      = lnkup_public_steps();
$account    = lnkup_public_account();

get_header();
?>
<div class="lnkup-site lp-site" data-testid="lp-organizer-landing" data-variant="<?php echo esc_attr( $variant ? (string) $variant['slug'] : 'default' ); ?>">
	<?php get_template_part( 'template-parts/landing/site-header' ); ?>
	<main id="lp-main" class="lp-main">
		<?php
		get_template_part(
			'template-parts/public/landing-hero',
			null,
			array(
				'eyebrow' => $eyebrow,
				'title'   => $title,
				'lead'    => $lead,
				'image'   => $image,
				'ctas'    => $account['url'] && 'organizer' === $account['role'] ? array( array( __( 'Go to your dashboard', 'lnkup-theme' ), $account['url'], 'lp-btn--lime' ) ) : $ctas,
				'note'    => __( 'Free to list. LNKup does not sell tickets or touch your ticketing.', 'lnkup-theme' ),
				'testid'  => 'lp-organizer-hero',
			)
		);
		?>

		<section class="lp-section" aria-labelledby="lp-org-why-title">
			<div class="lp-container lp-split">
				<div>
					<p class="lp-eyebrow"><?php esc_html_e( 'Why organizers switch', 'lnkup-theme' ); ?></p>
					<h2 class="lp-h2" id="lp-org-why-title"><?php esc_html_e( 'Vendor management that runs itself', 'lnkup-theme' ); ?></h2>
					<ul class="lp-checks">
						<li><?php esc_html_e( 'One application link instead of forms, DMs and email threads', 'lnkup-theme' ); ?></li>
						<li><?php esc_html_e( 'Vendors pay booth fees online when you approve them', 'lnkup-theme' ); ?></li>
						<li><?php esc_html_e( 'Documents and booth details collected before event day', 'lnkup-theme' ); ?></li>
						<li><?php esc_html_e( 'Vendors already searching LNKup can find your event', 'lnkup-theme' ); ?></li>
					</ul>
				</div>
				<?php lnkup_public_preview( 'organizer' ); ?>
			</div>
		</section>

		<section class="lp-section lp-section--mist" aria-labelledby="lp-org-tools-title">
			<div class="lp-container">
				<?php
				lnkup_public_section_head(
					array(
						'eyebrow' => __( 'Everything in one place', 'lnkup-theme' ),
						'title'   => __( 'Tools for the vendor side of your event', 'lnkup-theme' ),
						'id'      => 'lp-org-tools-title',
					)
				);
				lnkup_public_feature_grid( lnkup_public_organizer_features() );
				?>
			</div>
		</section>

		<section class="lp-section lp-section--ink" aria-labelledby="lp-org-steps-title">
			<div class="lp-container">
				<?php
				lnkup_public_section_head(
					array(
						'eyebrow' => __( 'How it works', 'lnkup-theme' ),
						'title'   => __( 'From listing to load-in', 'lnkup-theme' ),
						'id'      => 'lp-org-steps-title',
					)
				);
				?>
				<ol class="lp-steps">
					<?php foreach ( $steps['organizer'] as $step ) : ?>
						<li class="lp-step"><h3><?php echo esc_html( $step[0] ); ?></h3><p><?php echo esc_html( $step[1] ); ?></p></li>
					<?php endforeach; ?>
				</ol>
			</div>
		</section>

		<?php if ( count( $vendors ) >= 3 || count( $categories ) >= 3 ) : ?>
			<section class="lp-section" aria-labelledby="lp-org-supply-title" data-testid="lp-org-supply">
				<div class="lp-container">
					<?php
					lnkup_public_section_head(
						array(
							'eyebrow'      => __( 'Vendor supply', 'lnkup-theme' ),
							'title'        => __( 'Vendors on LNKup', 'lnkup-theme' ),
							'id'           => 'lp-org-supply-title',
							'lead'         => __( 'Browse public vendor profiles by category and city, then invite the ones that fit your event.', 'lnkup-theme' ),
							'action_label' => __( 'Open vendor directory', 'lnkup-theme' ),
							'action_url'   => lnkup_page_url( 'vendors' ),
						)
					);
					?>
					<?php if ( count( $vendors ) >= 3 ) : ?>
						<div class="lp-rail" style="--lp-rail-cols:4">
							<?php foreach ( $vendors as $vendor ) : ?>
								<?php get_template_part( 'template-parts/public/vendor-card', null, array( 'vendor' => $vendor ) ); ?>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<div class="lp-grid lp-grid--tiles">
							<?php foreach ( $categories as $cat ) : ?>
								<?php
								$term = ! empty( $cat['terms'][0] ) ? (string) $cat['terms'][0] : (string) $cat['label'];
								get_template_part( 'template-parts/public/category-tile', null, array( 'category' => array_merge( $cat, array( 'count' => 0 ) ), 'url' => add_query_arg( 'category', rawurlencode( $term ), lnkup_page_url( 'vendors' ) ) ) );
								?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( $pricing && $copy ) : ?>
			<section class="lp-section lp-section--mist" id="pricing" aria-labelledby="lp-org-pricing-title" data-testid="lp-org-pricing">
				<div class="lp-container">
					<?php
					lnkup_public_section_head(
						array(
							'eyebrow' => __( 'Pricing', 'lnkup-theme' ),
							'title'   => __( 'Free to list. Pay only when vendors pay you.', 'lnkup-theme' ),
							'id'      => 'lp-org-pricing-title',
							'lead'    => $copy['organizer'],
						)
					);
					$ex = $pricing['example'];
					?>
					<div class="lp-pricing" style="--lp-price-cols:2;margin-bottom:var(--lp-space-6)">
						<article class="lp-price lp-price--featured">
							<h3 class="lp-h3"><?php esc_html_e( 'Organizer', 'lnkup-theme' ); ?></h3>
							<p class="lp-price__amount">$0 <small><?php esc_html_e( 'to list events and review vendors', 'lnkup-theme' ); ?></small></p>
							<p class="lp-muted" style="margin:0">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: commission percent */
										__( 'Then %s of each booth fee vendors pay through LNKup.', 'lnkup-theme' ),
										lnkup_public_percent( $pricing['organizer_commission_percent'] )
									)
								);
								?>
							</p>
							<ul class="lp-checks">
								<li><?php esc_html_e( 'Events and vendor opportunities with no listing fee', 'lnkup-theme' ); ?></li>
								<li><?php esc_html_e( 'Applications, required documents and vendor notes', 'lnkup-theme' ); ?></li>
								<li><?php esc_html_e( 'Online booth payments and bank payouts', 'lnkup-theme' ); ?></li>
								<li><?php esc_html_e( 'Event-day check-in on web and iPhone', 'lnkup-theme' ); ?></li>
							</ul>
							<div class="lp-actions"><a class="lp-btn lp-btn--primary lp-btn--lg" href="<?php echo esc_url( lnkup_public_signup_url( 'organizer' ) ); ?>"><?php echo esc_html( $cta ); ?></a></div>
						</article>
						<article class="lp-price">
							<h3 class="lp-h3"><?php echo esc_html( sprintf( /* translators: %s: example booth price */ __( 'Example: one %s booth', 'lnkup-theme' ), lnkup_theme_money( $ex['booth'] ) ) ); ?></h3>
							<dl class="lp-breakdown">
								<div><dt><?php esc_html_e( 'Booth price you set', 'lnkup-theme' ); ?></dt><dd><?php echo esc_html( lnkup_theme_money( $ex['booth'] ) ); ?></dd></div>
								<div><dt><?php esc_html_e( 'LNKup fee', 'lnkup-theme' ); ?></dt><dd>−<?php echo esc_html( lnkup_theme_money( $ex['organizer_fee'] ) ); ?></dd></div>
								<div class="lp-breakdown__total"><dt><?php esc_html_e( 'Your payout', 'lnkup-theme' ); ?></dt><dd data-testid="lp-org-example-payout"><?php echo esc_html( lnkup_theme_money( $ex['organizer_payout'] ) ); ?></dd></div>
							</dl>
							<p class="lp-small lp-muted" style="margin:0"><?php esc_html_e( 'Calculated with current LNKup pricing. Custom pricing agreed for your organization may differ.', 'lnkup-theme' ); ?></p>
						</article>
					</div>

					<div class="lp-calc" data-lp-calc data-commission="<?php echo esc_attr( (string) $pricing['organizer_commission_percent'] ); ?>" data-testid="lp-roi-calculator">
						<div class="lp-calc__inputs">
							<h3 class="lp-h3"><?php esc_html_e( 'Estimate your vendor revenue', 'lnkup-theme' ); ?></h3>
							<div class="lp-calc__field">
								<label for="lp-calc-booths"><?php esc_html_e( 'Paid vendor booths', 'lnkup-theme' ); ?> <output for="lp-calc-booths">40</output></label>
								<input type="range" id="lp-calc-booths" name="booths" min="1" max="300" step="1" value="40">
							</div>
							<div class="lp-calc__field">
								<label for="lp-calc-booth"><?php esc_html_e( 'Average booth price', 'lnkup-theme' ); ?> <output for="lp-calc-booth">$150</output></label>
								<input type="range" id="lp-calc-booth" name="booth" min="25" max="2000" step="25" value="150">
							</div>
							<p class="lp-small lp-muted" style="margin:0"><?php esc_html_e( 'An estimate for planning only, based on the numbers you choose.', 'lnkup-theme' ); ?></p>
						</div>
						<div class="lp-calc__result" aria-live="polite">
							<span class="lp-small" style="color:var(--lp-muted-dark)"><?php esc_html_e( 'Estimated payout to you', 'lnkup-theme' ); ?></span>
							<span class="lp-calc__total" data-out="net">—</span>
							<dl class="lp-calc__rows">
								<div><dt><?php esc_html_e( 'Booth fees collected', 'lnkup-theme' ); ?></dt><dd data-out="gross">—</dd></div>
								<div><dt><?php echo esc_html( sprintf( /* translators: %s: percent */ __( 'LNKup fee (%s)', 'lnkup-theme' ), lnkup_public_percent( $pricing['organizer_commission_percent'] ) ) ); ?></dt><dd data-out="fee">—</dd></div>
							</dl>
						</div>
					</div>
				</div>
			</section>
		<?php endif; ?>

		<?php
		get_template_part( 'template-parts/public/social-proof', null, array( 'audience' => 'organizer' ) );
		get_template_part( 'template-parts/public/app', null, array( 'audience' => 'organizer' ) );
		?>

		<section class="lp-section" aria-labelledby="lp-org-faq-title">
			<div class="lp-container">
				<?php
				lnkup_public_section_head(
					array(
						'eyebrow' => __( 'Questions', 'lnkup-theme' ),
						'title'   => __( 'Organizer FAQ', 'lnkup-theme' ),
						'id'      => 'lp-org-faq-title',
						'align'   => 'center',
					)
				);
				lnkup_public_faq( lnkup_public_faqs( 'organizers' ), 'lp-org-faq' );
				?>
			</div>
		</section>

		<?php get_template_part( 'template-parts/public/final-cta', null, array( 'audience' => 'organizer' ) ); ?>
	</main>
	<?php get_template_part( 'template-parts/landing/site-footer' ); ?>
</div>
<?php
get_footer();
