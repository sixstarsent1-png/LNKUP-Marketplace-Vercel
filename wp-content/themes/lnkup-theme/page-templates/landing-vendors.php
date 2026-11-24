<?php
/**
 * Template Name: Landing: Vendors
 *
 * /for-vendors/ and its campaign variants (/for-vendors/{slug}/). ?city= and ?category= only
 * narrow the page when they match live listings; ?opportunity= adds a return-to-listing CTA.
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

$ctx      = class_exists( 'LNKup_Campaign_Landings' ) ? LNKup_Campaign_Landings::context() : null;
$variant  = $ctx && $ctx['variant'] ? $ctx['variant'] : null;
$filters  = $ctx ? (array) $ctx['filters'] : array();
$opp      = $ctx && $ctx['opportunity'] ? $ctx['opportunity'] : null;
$city     = (string) ( $filters['label'] ?? '' );
$category = (string) ( $filters['category_label'] ?? '' );
$pricing  = lnkup_public_pricing();
$copy     = lnkup_public_pricing_copy();
$account  = lnkup_public_account();
$feed     = lnkup_theme_opportunities_url();
$feed_ctx = add_query_arg( array_filter( array( 'city' => $filters['city'] ?? '', 'state' => $filters['state'] ?? '', 'categories' => $category ) ), $feed );

if ( $category && $city ) {
	/* translators: 1: vendor category, 2: city */
	$auto_eyebrow = sprintf( __( 'For %1$s vendors in %2$s', 'lnkup-theme' ), $category, $city );
} elseif ( $category ) {
	/* translators: %s: vendor category */
	$auto_eyebrow = sprintf( __( 'For %s vendors', 'lnkup-theme' ), $category );
} elseif ( $city ) {
	/* translators: %s: city */
	$auto_eyebrow = sprintf( __( 'For vendors in %s', 'lnkup-theme' ), $city );
} else {
	$auto_eyebrow = __( 'For vendors', 'lnkup-theme' );
}
$eyebrow = $variant && $variant['eyebrow'] ? (string) $variant['eyebrow'] : $auto_eyebrow;
$title   = $variant && $variant['headline'] ? (string) $variant['headline'] : (string) lnkup_public_setting( 'vendor_title', __( 'Find your next event. Apply free. Pay only when approved.', 'lnkup-theme' ) );
$lead    = $variant && $variant['subhead'] ? (string) $variant['subhead'] : (string) lnkup_public_setting( 'vendor_lead', __( 'Festivals, markets and pop-ups post vendor spots on LNKup with booth prices up front. Build one profile and apply in minutes.', 'lnkup-theme' ) );
$image   = $variant && $variant['image_id'] ? (int) $variant['image_id'] : ( (int) lnkup_landing_get( 'vendor_hero_image_id', 0 ) ?: 'images/marketing/hero-connector.jpg' );
$cta     = $variant && $variant['cta_label'] ? (string) $variant['cta_label'] : __( 'Create a free vendor profile', 'lnkup-theme' );
$return  = $opp ? (string) $opp['url'] : '';

if ( $account['url'] ) {
	$ctas = array( array( $opp ? __( 'Back to the event', 'lnkup-theme' ) : __( 'Find events', 'lnkup-theme' ), $opp ? $return : $feed_ctx, 'lp-btn--lime', 'lp-vendor-cta-primary' ) );
} elseif ( $opp ) {
	$ctas = array(
		/* translators: %s: event title */
		array( sprintf( __( 'Sign up to apply to %s', 'lnkup-theme' ), wp_trim_words( $opp['title'], 6, '…' ) ), lnkup_public_signup_url( 'vendor', $return ), 'lp-btn--lime', 'lp-vendor-cta-primary' ),
		array( __( 'Back to the event', 'lnkup-theme' ), $return, 'lp-btn--on-dark', 'lp-vendor-cta-return' ),
	);
} else {
	$ctas = array(
		array( $cta, lnkup_public_signup_url( 'vendor' ), 'lp-btn--lime', 'lp-vendor-cta-primary' ),
		array( __( 'Browse events', 'lnkup-theme' ), $feed_ctx, 'lp-btn--on-dark', 'lp-vendor-cta-browse' ),
	);
}

$cards      = lnkup_public_event_cards( 'upcoming', 6, array_intersect_key( $filters, array_flip( array( 'city', 'state', 'categories' ) ) ) );
$narrowed   = (bool) array_intersect_key( $filters, array_flip( array( 'city', 'categories' ) ) );
$fell_back  = false;
if ( ! $cards && $narrowed ) {
	$cards     = lnkup_public_event_cards( 'upcoming', 6 );
	$fell_back = (bool) $cards;
}
$discovery  = lnkup_public_discovery();
$categories = array_slice( (array) ( $discovery['categories'] ?? array() ), 0, 8 );
$cities     = array_slice( (array) ( $discovery['cities'] ?? array() ), 0, 8 );
$steps      = lnkup_public_steps();

get_header();
?>
<div class="lnkup-site lp-site" data-testid="lp-vendor-landing" data-variant="<?php echo esc_attr( $variant ? (string) $variant['slug'] : 'default' ); ?>" data-city="<?php echo esc_attr( (string) ( $filters['city'] ?? '' ) ); ?>" data-category="<?php echo esc_attr( $category ); ?>">
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
				'ctas'    => $ctas,
				'note'    => __( 'Free to join and apply. No subscription.', 'lnkup-theme' ),
				'testid'  => 'lp-vendor-hero',
			)
		);
		?>

		<?php if ( lnkup_theme_marketplace_open() ) : ?>
			<section class="lp-section" aria-labelledby="lp-vendor-live-title" data-testid="lp-vendor-live">
				<div class="lp-container">
					<?php
					if ( $city && ! $fell_back ) {
						/* translators: %s: city */
						$live_title = sprintf( __( 'Events booking vendors in %s', 'lnkup-theme' ), $city );
					} elseif ( $category && ! $fell_back ) {
						/* translators: %s: category */
						$live_title = sprintf( __( 'Events looking for %s vendors', 'lnkup-theme' ), $category );
					} else {
						$live_title = __( 'Events booking vendors now', 'lnkup-theme' );
					}
					lnkup_public_section_head(
						array(
							'eyebrow'      => __( 'Live on LNKup', 'lnkup-theme' ),
							'title'        => $live_title,
							'id'           => 'lp-vendor-live-title',
							'lead'         => $fell_back ? __( 'Nothing matches that exact search right now, so here are other events taking applications.', 'lnkup-theme' ) : '',
							'action_label' => $cards ? __( 'See all events', 'lnkup-theme' ) : '',
							'action_url'   => $fell_back ? $feed : $feed_ctx,
						)
					);
					?>
					<?php if ( $cards ) : ?>
						<div class="lp-rail" style="--lp-rail-cols:3">
							<?php foreach ( $cards as $card ) : ?>
								<?php get_template_part( 'template-parts/public/event-card', null, array( 'card' => $card, 'testid' => 'lp-vendor-event-card' ) ); ?>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<div class="lp-empty">
							<h3><?php esc_html_e( 'New events open every week', 'lnkup-theme' ); ?></h3>
							<p><?php esc_html_e( 'Create your profile now so you can apply the moment an event in your area opens.', 'lnkup-theme' ); ?></p>
						</div>
					<?php endif; ?>
					<div style="margin-top:var(--lp-space-6)">
						<?php get_template_part( 'template-parts/public/search', null, array( 'id' => 'lp-vendor-search', 'mode' => 'events', 'city' => (string) ( $filters['city'] ?? '' ), 'state' => (string) ( $filters['state'] ?? '' ), 'category' => $category ) ); ?>
					</div>
				</div>
			</section>
		<?php endif; ?>

		<section class="lp-section lp-section--mist" aria-labelledby="lp-vendor-why-title">
			<div class="lp-container">
				<div class="lp-split" style="margin-bottom:var(--lp-space-7)">
					<div>
						<p class="lp-eyebrow"><?php esc_html_e( 'Why vendors use LNKup', 'lnkup-theme' ); ?></p>
						<h2 class="lp-h2" id="lp-vendor-why-title"><?php esc_html_e( 'Less hunting for events. More selling at them.', 'lnkup-theme' ); ?></h2>
						<ul class="lp-checks">
							<li><?php esc_html_e( 'Booth price, spots left and deadline shown before you apply', 'lnkup-theme' ); ?></li>
							<li><?php esc_html_e( 'One profile reused for every application', 'lnkup-theme' ); ?></li>
							<li><?php esc_html_e( 'Track every application and payment in one place', 'lnkup-theme' ); ?></li>
						</ul>
					</div>
					<?php lnkup_public_preview( 'vendor' ); ?>
				</div>
				<?php lnkup_public_feature_grid( lnkup_public_vendor_features() ); ?>
			</div>
		</section>

		<section class="lp-section lp-section--ink" aria-labelledby="lp-vendor-steps-title">
			<div class="lp-container">
				<?php
				lnkup_public_section_head(
					array(
						'eyebrow' => __( 'How it works', 'lnkup-theme' ),
						'title'   => __( 'From profile to paid booth', 'lnkup-theme' ),
						'id'      => 'lp-vendor-steps-title',
					)
				);
				?>
				<ol class="lp-steps">
					<?php foreach ( $steps['vendor'] as $step ) : ?>
						<li class="lp-step"><h3><?php echo esc_html( $step[0] ); ?></h3><p><?php echo esc_html( $step[1] ); ?></p></li>
					<?php endforeach; ?>
				</ol>
			</div>
		</section>

		<?php if ( lnkup_theme_marketplace_open() && ( count( $categories ) >= 2 || $cities ) ) : ?>
			<section class="lp-section" aria-labelledby="lp-vendor-browse-title">
				<div class="lp-container">
					<?php
					lnkup_public_section_head(
						array(
							'eyebrow' => __( 'Browse', 'lnkup-theme' ),
							'title'   => __( 'Where vendors are wanted', 'lnkup-theme' ),
							'id'      => 'lp-vendor-browse-title',
						)
					);
					?>
					<?php if ( count( $categories ) >= 2 ) : ?>
						<div class="lp-grid lp-grid--tiles" style="margin-bottom:var(--lp-space-5)">
							<?php foreach ( $categories as $cat ) : ?>
								<?php get_template_part( 'template-parts/public/category-tile', null, array( 'category' => $cat ) ); ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
					<?php if ( $cities ) : ?>
						<div class="lp-grid lp-grid--4">
							<?php foreach ( $cities as $city_row ) : ?>
								<?php get_template_part( 'template-parts/public/city-card', null, array( 'city' => $city_row ) ); ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( $pricing && $copy ) : ?>
			<?php $ex = $pricing['example']; ?>
			<section class="lp-section lp-section--mist" id="pricing" aria-labelledby="lp-vendor-pricing-title" data-testid="lp-vendor-pricing">
				<div class="lp-container">
					<?php
					lnkup_public_section_head(
						array(
							'eyebrow' => __( 'Pricing', 'lnkup-theme' ),
							'title'   => __( 'Free to apply. Clear fees when you book.', 'lnkup-theme' ),
							'id'      => 'lp-vendor-pricing-title',
							'lead'    => $copy['vendor'],
						)
					);
					?>
					<div class="lp-pricing" style="--lp-price-cols:2">
						<article class="lp-price lp-price--featured">
							<h3 class="lp-h3"><?php esc_html_e( 'Vendor', 'lnkup-theme' ); ?></h3>
							<p class="lp-price__amount">$0 <small><?php esc_html_e( 'to join and apply', 'lnkup-theme' ); ?></small></p>
							<ul class="lp-checks">
								<li><?php esc_html_e( 'Unlimited applications', 'lnkup-theme' ); ?></li>
								<li><?php esc_html_e( 'Vendor profile with photos and documents', 'lnkup-theme' ); ?></li>
								<li><?php esc_html_e( 'Pay only after an organizer approves you', 'lnkup-theme' ); ?></li>
							</ul>
							<div class="lp-actions"><a class="lp-btn lp-btn--primary lp-btn--lg" href="<?php echo esc_url( lnkup_public_signup_url( 'vendor', $return ) ); ?>"><?php echo esc_html( $cta ); ?></a></div>
						</article>
						<article class="lp-price">
							<h3 class="lp-h3"><?php echo esc_html( sprintf( /* translators: %s: booth price */ __( 'Example: a %s booth', 'lnkup-theme' ), lnkup_theme_money( $ex['booth'] ) ) ); ?></h3>
							<dl class="lp-breakdown" data-testid="lp-vendor-example">
								<div><dt><?php esc_html_e( 'Booth price set by the organizer', 'lnkup-theme' ); ?></dt><dd><?php echo esc_html( lnkup_theme_money( $ex['booth'] ) ); ?></dd></div>
								<?php if ( $ex['vendor_service_fee'] > 0 ) : ?>
									<div><dt><?php esc_html_e( 'LNKup service fee', 'lnkup-theme' ); ?></dt><dd><?php echo esc_html( lnkup_theme_money( $ex['vendor_service_fee'] ) ); ?></dd></div>
								<?php endif; ?>
								<?php if ( $ex['processing_fee'] > 0 ) : ?>
									<div><dt><?php esc_html_e( 'Card processing', 'lnkup-theme' ); ?></dt><dd><?php echo esc_html( lnkup_theme_money( $ex['processing_fee'] ) ); ?></dd></div>
								<?php endif; ?>
								<?php if ( $ex['tax'] > 0 ) : ?>
									<div><dt><?php esc_html_e( 'Tax', 'lnkup-theme' ); ?></dt><dd><?php echo esc_html( lnkup_theme_money( $ex['tax'] ) ); ?></dd></div>
								<?php endif; ?>
								<div class="lp-breakdown__total"><dt><?php esc_html_e( 'You pay after approval', 'lnkup-theme' ); ?></dt><dd><?php echo esc_html( lnkup_theme_money( $ex['vendor_total'] ) ); ?></dd></div>
							</dl>
							<p class="lp-small lp-muted" style="margin:0"><?php esc_html_e( 'Calculated with current LNKup pricing. Your checkout shows the exact total for each event before you pay.', 'lnkup-theme' ); ?></p>
						</article>
					</div>
				</div>
			</section>
		<?php endif; ?>

		<?php
		get_template_part( 'template-parts/public/social-proof', null, array( 'audience' => 'vendor' ) );
		get_template_part( 'template-parts/public/app', null, array( 'audience' => 'vendor' ) );
		?>

		<section class="lp-section" aria-labelledby="lp-vendor-faq-title">
			<div class="lp-container">
				<?php
				lnkup_public_section_head(
					array(
						'eyebrow' => __( 'Questions', 'lnkup-theme' ),
						'title'   => __( 'Vendor FAQ', 'lnkup-theme' ),
						'id'      => 'lp-vendor-faq-title',
						'align'   => 'center',
					)
				);
				lnkup_public_faq( lnkup_public_faqs( 'vendors' ), 'lp-vendor-faq' );
				?>
			</div>
		</section>

		<?php get_template_part( 'template-parts/public/final-cta', null, array( 'audience' => 'vendor', 'return_url' => $return ) ); ?>
	</main>
	<?php get_template_part( 'template-parts/landing/site-footer' ); ?>
</div>
<?php
get_footer();
