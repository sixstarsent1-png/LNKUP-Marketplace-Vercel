<?php
/**
 * City market page (/markets/{city}/) and its sub-pages (/markets/{city}/{category}/ and
 * /markets/{city}/this-week/) on Design System 5.0.
 *
 * Everything shown is live: open events from the marketplace index, categories with counts,
 * organizers with open events, nearby markets. No goals, targets or estimated counts.
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

$ctx = function_exists( 'lnkup_theme_market_context' ) ? lnkup_theme_market_context() : null;
if ( ! $ctx ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$market    = $ctx['market'];
$sub       = isset( $ctx['sub'] ) && is_array( $ctx['sub'] ) ? $ctx['sub'] : null;
$name      = (string) $market['display_name'];
$slug      = (string) $market['slug'];
$open      = ! empty( $ctx['marketplace_open'] );
$registry  = class_exists( 'LNKup_Market_Registry' );
$status    = (string) ( $market['status'] ?? 'prelaunch' );
$account   = lnkup_public_account();
$page_url  = class_exists( 'LNKup_Market_Pages' ) ? LNKup_Market_Pages::market_url( $slug ) : home_url( '/markets/' . $slug . '/' );
$feed_url  = (string) $market['opportunities_url'];

$cards = array();
if ( $sub ) {
	$cards = (array) $sub['cards'];
} elseif ( $open && $registry ) {
	$cards = LNKup_Market_Registry::cards( $market, array( 'limit' => 9 ) );
}
$categories = ( $open && $registry ) ? LNKup_Market_Registry::categories( $market ) : array();
$health     = $registry ? LNKup_Market_Registry::health( $market ) : array();
$organizers = ( $open && $registry && ! $sub ) ? LNKup_Market_Registry::featured_organizers( $market, 6 ) : array();
$nearby     = $registry ? LNKup_Market_Registry::nearby( $market, 6 ) : array();
$recent     = array();
if ( $open && $registry && ! $sub ) {
	$shown = array_flip( wp_list_pluck( $cards, 'url' ) );
	foreach ( LNKup_Market_Registry::cards( $market, array( 'limit' => 6, 'sort' => 'newest', 'created_after' => gmdate( 'Y-m-d', strtotime( '-14 days' ) ) ) ) as $card ) {
		if ( ! isset( $shown[ (string) $card['url'] ] ) && count( $recent ) < 3 ) {
			$recent[] = $card;
		}
	}
}
$digest = ( $registry && class_exists( 'LNKup_Market_Discovery' ) && LNKup_Market_Discovery::active() && ! $sub ) ? LNKup_Market_Discovery::build( $market, LNKup_Market_Discovery::DIGEST_SLUG ) : null;

$title = $sub ? (string) $sub['title'] : (string) $ctx['headline'];
$lead  = $sub && class_exists( 'LNKup_Market_Discovery' ) ? LNKup_Market_Discovery::description_text() : (string) $ctx['description'];

$vendor_cta = $account['url']
	? array( __( 'Find events', 'lnkup-theme' ), add_query_arg( 'market', $slug, $feed_url ), 'lp-btn--lime', 'lnkup-market-vendor-cta' )
	: array( __( 'Join as a vendor', 'lnkup-theme' ), (string) $ctx['vendor_signup_url'], 'lp-btn--lime', 'lnkup-market-vendor-cta' );
$ctas = array( $vendor_cta );
if ( ! $account['url'] ) {
	$ctas[] = array( __( 'List your event', 'lnkup-theme' ), (string) $ctx['organizer_signup_url'], 'lp-btn--on-dark', 'lnkup-market-organizer-cta' );
}

$alert_signup = add_query_arg(
	array(
		'alert'  => 1,
		'market' => $slug,
	),
	lnkup_public_signup_url( 'vendor', $page_url )
);
$alert_categories = function_exists( 'lnkup_canonical_vendor_categories' ) ? (array) lnkup_canonical_vendor_categories() : array();
$can_alert        = is_user_logged_in() && in_array( $account['role'], array( 'vendor', 'admin' ), true );

$faq_vendor = function_exists( 'lnkup_public_faqs' ) ? lnkup_public_faqs( 'vendors' ) : array();
$faq_org    = function_exists( 'lnkup_public_faqs' ) ? lnkup_public_faqs( 'organizers' ) : array();
$faqs       = array_filter(
	array(
		array(
			/* translators: %s: city */
			'q' => sprintf( __( 'How do I find vendor opportunities in %s?', 'lnkup-theme' ), $name ),
			/* translators: %s: city */
			'a' => sprintf( __( 'Browse the events on this page or search %s on LNKup. Each listing shows the event date, the vendor categories the organizer wants and how to apply.', 'lnkup-theme' ), $name ),
		),
		$faq_vendor[0] ?? null,
		array(
			/* translators: %s: city */
			'q' => sprintf( __( 'How do I hear about new events in %s?', 'lnkup-theme' ), $name ),
			/* translators: %s: city */
			'a' => sprintf( __( 'Create a free vendor account and turn on alerts for %s. We email you when a matching event opens, and you can pause or remove alerts any time.', 'lnkup-theme' ), $name ),
		),
		isset( $faq_org[1] ) ? array(
			/* translators: %s: city */
			'q' => sprintf( __( 'I organize events in %s. Can I list them?', 'lnkup-theme' ), $name ),
			'a' => (string) $faq_org[1]['a'],
		) : null,
	)
);

get_header();
?>
<div class="lnkup-site lp-site lnkup-site--market" data-testid="lnkup-market-page" data-market-slug="<?php echo esc_attr( $slug ); ?>" data-market-status="<?php echo esc_attr( $status ); ?>"<?php echo $sub ? ' data-market-sub="' . esc_attr( (string) $sub['slug'] ) . '"' : ''; ?>>
	<?php get_template_part( 'template-parts/landing/site-header' ); ?>
	<main id="lp-main" class="lp-main">
		<?php
		get_template_part(
			'template-parts/public/landing-hero',
			null,
			array(
				'eyebrow' => $sub
					/* translators: %s: city */
					? sprintf( __( 'LNKup %s', 'lnkup-theme' ), $name )
					: ( $registry ? LNKup_Market_Registry::status_label( $status ) : '' ),
				'title'   => $title,
				'lead'    => $lead,
				'image'   => 'images/marketing/hero-market.jpg',
				'ctas'    => $ctas,
				'note'    => __( 'Free to join and apply. No subscription.', 'lnkup-theme' ),
				'testid'  => 'lnkup-market-hero',
			)
		);
		?>

		<?php if ( $sub ) : ?>
			<nav class="lp-container" aria-label="<?php esc_attr_e( 'Breadcrumb', 'lnkup-theme' ); ?>" style="padding-top:var(--lp-space-5)">
				<a class="lp-link" href="<?php echo esc_url( $page_url ); ?>" data-testid="lnkup-market-back">&larr; <?php echo esc_html( sprintf( /* translators: %s: city */ __( 'All of %s', 'lnkup-theme' ), $name ) ); ?></a>
			</nav>
		<?php endif; ?>

		<section class="lp-section" id="opportunities" aria-labelledby="lnkup-market-live-title" data-testid="lnkup-market-opportunities">
			<div class="lp-container">
				<?php if ( ! $open ) : ?>
					<p class="lp-notice" data-testid="lnkup-market-prelaunch-copy" style="margin-bottom:var(--lp-space-5)">
						<?php
						echo esc_html(
							$ctx['launch_label']
								/* translators: 1: city, 2: launch date */
								? sprintf( __( '%1$s is in prelaunch. We are onboarding vendors and organizers before the public marketplace launches in %2$s.', 'lnkup-theme' ), $name, $ctx['launch_label'] )
								/* translators: %s: city */
								: sprintf( __( '%s is in prelaunch. We are onboarding vendors and organizers before the public marketplace launches.', 'lnkup-theme' ), $name )
						);
						?>
					</p>
				<?php endif; ?>
				<?php
				lnkup_public_section_head(
					array(
						'eyebrow'      => $open ? __( 'Live on LNKup', 'lnkup-theme' ) : '',
						/* translators: %s: city */
						'title'        => $sub ? (string) $sub['title'] : sprintf( __( 'Events booking vendors in %s', 'lnkup-theme' ), $name ),
						'id'           => 'lnkup-market-live-title',
						'action_label' => $cards ? __( 'See all in this city', 'lnkup-theme' ) : '',
						'action_url'   => $feed_url,
					)
				);
				?>
				<?php if ( $open && ! $sub && ! empty( $health['open_events'] ) ) : ?>
					<ul class="lp-stats lp-stats--light" data-testid="lnkup-market-health" style="--lp-stat-cols:3;margin:0 0 var(--lp-space-5);padding:0;list-style:none">
						<li class="lp-stats__item"><span class="lp-stats__value"><?php echo esc_html( number_format_i18n( (int) $health['open_events'] ) ); ?></span><span class="lp-stats__label"><?php echo esc_html( _n( 'event booking vendors', 'events booking vendors', (int) $health['open_events'], 'lnkup-theme' ) ); ?></span></li>
						<?php if ( ! empty( $health['active_organizers'] ) ) : ?>
							<li class="lp-stats__item"><span class="lp-stats__value"><?php echo esc_html( number_format_i18n( (int) $health['active_organizers'] ) ); ?></span><span class="lp-stats__label"><?php echo esc_html( _n( 'organizer with open events', 'organizers with open events', (int) $health['active_organizers'], 'lnkup-theme' ) ); ?></span></li>
						<?php endif; ?>
						<?php if ( count( $categories ) > 0 ) : ?>
							<li class="lp-stats__item"><span class="lp-stats__value"><?php echo esc_html( number_format_i18n( count( $categories ) ) ); ?></span><span class="lp-stats__label"><?php echo esc_html( _n( 'vendor category wanted', 'vendor categories wanted', count( $categories ), 'lnkup-theme' ) ); ?></span></li>
						<?php endif; ?>
					</ul>
				<?php endif; ?>
				<?php if ( $cards ) : ?>
					<div class="lp-rail" style="--lp-rail-cols:3">
						<?php foreach ( $cards as $card ) : ?>
							<?php get_template_part( 'template-parts/public/event-card', null, array( 'card' => $card, 'testid' => 'lnkup-market-opp-card' ) ); ?>
						<?php endforeach; ?>
					</div>
				<?php elseif ( $open ) : ?>
					<div class="lp-empty" data-testid="lnkup-market-empty-opps">
						<?php if ( $sub ) : ?>
							<h3><?php echo esc_html( sprintf( /* translators: 1: list, 2: city */ __( 'Nothing open for %1$s in %2$s right now', 'lnkup-theme' ), (string) $sub['label'], $name ) ); ?></h3>
						<?php else : ?>
							<h3><?php echo esc_html( sprintf( /* translators: %s: city */ __( 'No events in %s are taking vendor applications right now', 'lnkup-theme' ), $name ) ); ?></h3>
						<?php endif; ?>
						<p><?php esc_html_e( 'Turn on alerts and we will email you when events here open.', 'lnkup-theme' ); ?></p>
						<a class="lp-btn lp-btn--primary" href="#lnkup-market-alerts"><?php esc_html_e( 'Get alerts', 'lnkup-theme' ); ?></a>
					</div>
				<?php endif; ?>
				<?php if ( $digest && ! empty( $digest['eligible'] ) ) : ?>
					<p style="margin-top:var(--lp-space-5)"><a class="lp-link" href="<?php echo esc_url( (string) $digest['url'] ); ?>" data-testid="lnkup-market-digest-link"><?php echo esc_html( sprintf( /* translators: %s: city */ __( 'Happening in %s in the next two weeks', 'lnkup-theme' ), $name ) ); ?> <?php echo lnkup_public_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></p>
				<?php endif; ?>
			</div>
		</section>

		<?php if ( count( $categories ) >= 2 && ( ! $sub || 'digest' === $sub['type'] ) ) : ?>
			<section class="lp-section lp-section--mist" aria-labelledby="lnkup-market-cats-title" data-testid="lnkup-market-categories">
				<div class="lp-container">
					<?php
					lnkup_public_section_head(
						array(
							'eyebrow' => __( 'Browse by category', 'lnkup-theme' ),
							/* translators: %s: city */
							'title'   => sprintf( __( 'Vendors wanted in %s', 'lnkup-theme' ), $name ),
							'id'      => 'lnkup-market-cats-title',
						)
					);
					?>
					<div class="lp-grid lp-grid--tiles">
						<?php foreach ( array_slice( $categories, 0, 12 ) as $cat ) : ?>
							<?php get_template_part( 'template-parts/public/category-tile', null, array( 'category' => $cat ) ); ?>
						<?php endforeach; ?>
					</div>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( $recent ) : ?>
			<section class="lp-section" aria-labelledby="lnkup-market-recent-title" data-testid="lnkup-market-recent">
				<div class="lp-container">
					<?php
					lnkup_public_section_head(
						array(
							'eyebrow' => __( 'Just added', 'lnkup-theme' ),
							/* translators: %s: city */
							'title'   => sprintf( __( 'New in %s in the last two weeks', 'lnkup-theme' ), $name ),
							'id'      => 'lnkup-market-recent-title',
						)
					);
					?>
					<div class="lp-rail" style="--lp-rail-cols:3">
						<?php foreach ( $recent as $card ) : ?>
							<?php get_template_part( 'template-parts/public/event-card', null, array( 'card' => $card, 'testid' => 'lnkup-market-recent-card' ) ); ?>
						<?php endforeach; ?>
					</div>
				</div>
			</section>
		<?php endif; ?>

		<section class="lp-section lp-section--ink" id="lnkup-market-alerts" aria-labelledby="lnkup-market-alerts-title" data-testid="lnkup-market-alerts">
			<div class="lp-container lp-split">
				<div>
					<p class="lp-eyebrow"><?php esc_html_e( 'Alerts', 'lnkup-theme' ); ?></p>
					<h2 class="lp-h2" id="lnkup-market-alerts-title"><?php echo esc_html( sprintf( /* translators: %s: city */ __( 'Get alerts for %s', 'lnkup-theme' ), $name ) ); ?></h2>
					<p class="lp-lead"><?php esc_html_e( 'We email you when a new event matching your category opens here: one message per event. Pause or remove the alert any time in Saved searches.', 'lnkup-theme' ); ?></p>
					<?php if ( $can_alert ) : ?>
						<form class="lp-actions" data-lnkup-market-alert>
							<label class="lp-visually-hidden" for="lnkup-market-alert-category"><?php esc_html_e( 'Category', 'lnkup-theme' ); ?></label>
							<select id="lnkup-market-alert-category" name="category" class="lp-btn lp-btn--light">
								<option value=""><?php esc_html_e( 'All categories', 'lnkup-theme' ); ?></option>
								<?php foreach ( $alert_categories as $label ) : ?>
									<option value="<?php echo esc_attr( sanitize_title( (string) $label ) ); ?>"><?php echo esc_html( (string) $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="lp-btn lp-btn--lime" data-testid="lnkup-market-alert-submit"><?php esc_html_e( 'Turn on alerts', 'lnkup-theme' ); ?></button>
							<p class="lp-small" role="status" aria-live="polite" data-lnkup-alert-status hidden></p>
						</form>
					<?php elseif ( ! is_user_logged_in() ) : ?>
						<div class="lp-actions">
							<a class="lp-btn lp-btn--lime" href="<?php echo esc_url( $alert_signup ); ?>" data-testid="lnkup-market-alert-signup" data-lnkup-track="market_alert_signup_click"><?php esc_html_e( 'Create a free account for alerts', 'lnkup-theme' ); ?></a>
						</div>
					<?php endif; ?>
				</div>
				<?php if ( $alert_categories ) : ?>
					<div data-testid="lnkup-market-demand">
						<h3 class="lp-h3"><?php echo esc_html( sprintf( /* translators: %s: city */ __( 'What do you sell? Tell us what %s is missing.', 'lnkup-theme' ), $name ) ); ?></h3>
						<p class="lp-small lp-muted"><?php esc_html_e( 'Anonymous. We count taps per category to decide which events to bring here.', 'lnkup-theme' ); ?></p>
						<div class="lp-search__chips">
							<?php foreach ( $alert_categories as $label ) : ?>
								<button type="button" class="lp-chip lp-chip--line" aria-pressed="false" data-lnkup-demand="<?php echo esc_attr( sanitize_title( (string) $label ) ); ?>"><?php echo esc_html( (string) $label ); ?></button>
							<?php endforeach; ?>
						</div>
						<p class="lp-small" role="status" aria-live="polite" data-lnkup-demand-status hidden></p>
					</div>
				<?php endif; ?>
			</div>
		</section>

		<?php if ( $organizers ) : ?>
			<section class="lp-section" aria-labelledby="lnkup-market-orgs-title" data-testid="lnkup-market-organizers">
				<div class="lp-container">
					<?php
					lnkup_public_section_head(
						array(
							'eyebrow' => __( 'Organizers', 'lnkup-theme' ),
							/* translators: %s: city */
							'title'   => sprintf( __( 'Organizers booking vendors in %s', 'lnkup-theme' ), $name ),
							'id'      => 'lnkup-market-orgs-title',
						)
					);
					?>
					<div class="lp-grid lp-grid--3">
						<?php foreach ( $organizers as $org ) : ?>
							<a class="lp-city" href="<?php echo esc_url( $org['url'] ); ?>">
								<span>
									<span class="lp-city__name"><?php echo esc_html( $org['name'] ); ?><?php if ( $org['verified'] ) : ?> <span class="lp-chip lp-chip--lime"><?php esc_html_e( 'Verified', 'lnkup-theme' ); ?></span><?php endif; ?></span>
									<span class="lp-city__count"><?php echo esc_html( sprintf( /* translators: %d: events */ _n( '%d open event here', '%d open events here', (int) $org['events'], 'lnkup-theme' ), (int) $org['events'] ) ); ?></span>
								</span>
								<span class="lp-city__arrow"><?php echo lnkup_public_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( $nearby ) : ?>
			<section class="lp-section lp-section--mist" aria-labelledby="lnkup-market-nearby-title" data-testid="lnkup-market-nearby">
				<div class="lp-container">
					<?php
					lnkup_public_section_head(
						array(
							'eyebrow' => __( 'Nearby', 'lnkup-theme' ),
							'title'   => __( 'Markets near you', 'lnkup-theme' ),
							'id'      => 'lnkup-market-nearby-title',
						)
					);
					?>
					<div class="lp-grid lp-grid--3">
						<?php foreach ( $nearby as $near ) : ?>
							<a class="lp-city" href="<?php echo esc_url( $near['url'] ); ?>">
								<span>
									<span class="lp-city__name"><?php echo esc_html( $near['name'] ); ?></span>
									<span class="lp-city__count">
										<?php
										echo esc_html(
											$near['open_events'] > 0
												/* translators: %d: open events */
												? sprintf( _n( '%d event booking vendors', '%d events booking vendors', (int) $near['open_events'], 'lnkup-theme' ), (int) $near['open_events'] )
												: __( 'Get alerts when events open', 'lnkup-theme' )
										);
										?>
									</span>
								</span>
								<span class="lp-city__arrow"><?php echo lnkup_public_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( ! $sub && '' !== trim( wp_strip_all_tags( (string) ( $market['content'] ?? '' ) ) ) ) : ?>
			<section class="lp-section" aria-labelledby="lnkup-market-guide-title" data-testid="lnkup-market-guide">
				<div class="lp-container" style="max-width:820px">
					<p class="lp-eyebrow"><?php esc_html_e( 'Local guide', 'lnkup-theme' ); ?></p>
					<h2 class="lp-h2" id="lnkup-market-guide-title"><?php echo esc_html( sprintf( /* translators: %s: city */ __( 'Vending in %s', 'lnkup-theme' ), $name ) ); ?></h2>
					<div class="lp-lead"><?php echo wp_kses_post( wpautop( (string) $market['content'] ) ); ?></div>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( ! $sub ) : ?>
			<section class="lp-section lp-section--mist" aria-label="<?php esc_attr_e( 'Get involved', 'lnkup-theme' ); ?>">
				<div class="lp-container lp-grid lp-grid--2">
					<article class="lp-feature" data-testid="lnkup-market-organizer-block">
						<span class="lp-feature__icon"><?php echo lnkup_public_icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<h3><?php echo esc_html( sprintf( /* translators: %s: city */ __( 'Organizing an event in %s?', 'lnkup-theme' ), $name ) ); ?></h3>
						<p><?php esc_html_e( 'List vendor spots for free, review applications in one queue and collect booth fees online.', 'lnkup-theme' ); ?></p>
						<div class="lp-actions"><a class="lp-btn lp-btn--primary" href="<?php echo esc_url( $account['url'] && 'organizer' === $account['role'] ? (string) $ctx['organizer_create_url'] : (string) $ctx['organizer_signup_url'] ); ?>" data-lnkup-track="market_organizer_signup_click"><?php esc_html_e( 'List your event', 'lnkup-theme' ); ?></a></div>
					</article>
					<article class="lp-feature" data-testid="lnkup-market-connector">
						<span class="lp-feature__icon"><?php echo lnkup_public_icon( 'users' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<h3><?php echo esc_html( sprintf( /* translators: %s: city */ __( 'Help build LNKup in %s', 'lnkup-theme' ), $name ) ); ?></h3>
						<p><?php esc_html_e( 'We work with local connectors who know vendors, organizers and markets.', 'lnkup-theme' ); ?></p>
						<div class="lp-actions"><a class="lp-btn lp-btn--outline" href="<?php echo esc_url( (string) $ctx['connector_url'] ); ?>" data-lnkup-track="market_connector_click"><?php esc_html_e( 'Become a City Connector', 'lnkup-theme' ); ?></a></div>
					</article>
				</div>
			</section>

			<?php get_template_part( 'template-parts/public/app', null, array( 'audience' => 'vendor' ) ); ?>

			<section class="lp-section" aria-labelledby="lnkup-market-faq-title">
				<div class="lp-container">
					<?php
					lnkup_public_section_head(
						array(
							'eyebrow' => __( 'Questions', 'lnkup-theme' ),
							/* translators: %s: city */
							'title'   => sprintf( __( 'Vending in %s: FAQ', 'lnkup-theme' ), $name ),
							'id'      => 'lnkup-market-faq-title',
							'align'   => 'center',
						)
					);
					lnkup_public_faq( array_values( $faqs ), 'lnkup-market-faq' );
					?>
				</div>
			</section>
		<?php endif; ?>
	</main>
	<?php get_template_part( 'template-parts/landing/site-footer' ); ?>
</div>
<?php
get_footer();
