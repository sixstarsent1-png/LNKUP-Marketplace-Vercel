<?php
/**
 * Hero section partial.
 *
 * @package LNKup_Theme
 */

$signup_url        = lnkup_theme_registration_url();
$opportunities_url = lnkup_page_url( 'opportunities' );
$profile_url       = lnkup_page_url( 'vendor-profile' );
$organizer_url     = lnkup_page_url( 'organizer-dashboard' );
$organizer_signup  = function_exists( 'lnkup_theme_market_signup_url' )
	? lnkup_theme_market_signup_url( 'organizer' )
	: add_query_arg( 'role', 'organizer', $signup_url );
$hero_image        = lnkup_landing_image_url( 'hero_image_id', 'images/marketing/hero-home.jpg' );
$launch_label      = lnkup_theme_launch_label();
$marketplace_open  = lnkup_theme_marketplace_open();
$launch_goals      = lnkup_theme_launch_goals();
$eyebrow           = (string) lnkup_landing_get( 'hero_eyebrow', '' );
$title             = (string) lnkup_landing_get( 'hero_title', '' );
$lead              = (string) lnkup_landing_get( 'hero_lead', '' );
$primary_label     = (string) lnkup_landing_get( 'hero_primary_cta', __( 'Create your account', 'lnkup-theme' ) );
$secondary_label   = (string) lnkup_landing_get( 'hero_secondary_cta', __( 'Explore How It Works', 'lnkup-theme' ) );
$event_label       = (string) lnkup_landing_get( 'hero_event_label', __( 'New opportunity', 'lnkup-theme' ) );
$event_title       = (string) lnkup_landing_get( 'hero_event_title', '' );
$event_meta        = (string) lnkup_landing_get( 'hero_event_meta', '' );
$event_badge       = (string) lnkup_landing_get( 'hero_event_badge', '' );
$goal_override     = trim( (string) lnkup_landing_get( 'hero_goal_override', '' ) );

$primary_url   = is_user_logged_in() ? $profile_url : $signup_url;
$secondary_url = '#how-it-works';
if ( $marketplace_open ) {
	$primary_url   = $opportunities_url;
	$secondary_url = is_user_logged_in()
		? $organizer_url . '#create'
		: $organizer_signup;
}

$vendor_goal_label = '' !== $goal_override ? $goal_override : number_format_i18n( $launch_goals['vendors'] ) . '+';
?>
<section class="lnkup-hero lnkup-hero--cinematic lnkup-reveal">
	<?php
	get_template_part(
		'template-parts/landing/hero-canvas',
		null,
		array(
			'photo' => $hero_image,
			'stamp' => 'LNK / MARKETPLACE',
		)
	);
	?>
	<div class="lnkup-container lnkup-hero__grid">
		<div class="lnkup-hero__content">
			<?php if ( ! $marketplace_open && $launch_label ) : ?>
				<span class="lnkup-pill lnkup-pill--launch" data-testid="lnkup-hero-launch-pill">
					<span class="lnkup-pill__dot"></span>
					<?php echo esc_html( sprintf( __( 'Launching %s', 'lnkup-theme' ), $launch_label ) ); ?>
				</span>
			<?php else : ?>
				<span class="lnkup-pill">
					<span class="lnkup-pill__dot"></span>
					<?php echo esc_html( $eyebrow ); ?>
				</span>
			<?php endif; ?>
			<h1 class="lnkup-heading lnkup-heading--hero"><?php echo esc_html( $title ); ?></h1>
			<p class="lnkup-hero__lead"><?php echo esc_html( $lead ); ?></p>
			<?php if ( ! $marketplace_open && $launch_label ) : ?>
				<p class="lnkup-hero__sublead"><?php echo esc_html( sprintf( __( 'Create your free account today. Browse and apply when we launch %s.', 'lnkup-theme' ), $launch_label ) ); ?></p>
			<?php endif; ?>
			<div class="lnkup-hero__cta">
				<a href="<?php echo esc_url( $primary_url ); ?>" class="lnkup-btn lnkup-btn--primary lnkup-btn--lg" data-testid="lnkup-hero-cta-primary">
					<?php echo esc_html( $primary_label ); ?>
					<svg class="lnkup-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
				</a>
				<a href="<?php echo esc_url( $secondary_url ); ?>" class="lnkup-btn lnkup-btn--outline lnkup-btn--lg"><?php echo esc_html( $secondary_label ); ?></a>
			</div>
			<ul class="lnkup-hero__trust-list">
				<li>
					<?php echo lnkup_theme_landing_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php esc_html_e( 'Free to join & apply', 'lnkup-theme' ); ?>
				</li>
				<li>
					<?php echo lnkup_theme_landing_icon( 'star' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php esc_html_e( 'Pay only when accepted', 'lnkup-theme' ); ?>
				</li>
				<li>
					<?php echo lnkup_theme_landing_icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php
					echo $marketplace_open
						? esc_html__( 'Events across our markets', 'lnkup-theme' )
						: esc_html__( 'Building our launch cities', 'lnkup-theme' );
					?>
				</li>
			</ul>
		</div>
		<div class="lnkup-hero__stamps" data-testid="lnkup-hero-visual">
			<div class="lnkup-hero__card lnkup-hero__card--opportunity">
				<div class="lnkup-hero__card-row">
					<div class="lnkup-hero__card-body">
						<p class="lnkup-hero__card-label"><?php echo esc_html( $event_label ); ?></p>
						<p class="lnkup-hero__card-title"><?php echo esc_html( $event_title ); ?></p>
					</div>
				</div>
				<div class="lnkup-hero__card-meta">
					<span><?php echo esc_html( $event_meta ); ?></span>
					<?php if ( $event_badge ) : ?>
						<span class="lnkup-badge lnkup-badge--accent"><?php echo esc_html( $event_badge ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( ! $marketplace_open ) : ?>
				<a href="<?php echo esc_url( $signup_url ); ?>" class="lnkup-hero__card lnkup-hero__card--goal" data-testid="lnkup-hero-goal-card">
					<p class="lnkup-hero__card-label"><?php esc_html_e( 'Community goal', 'lnkup-theme' ); ?></p>
					<p class="lnkup-hero__stat"><?php echo esc_html( $vendor_goal_label ); ?></p>
					<p class="lnkup-hero__card-title"><?php esc_html_e( 'vendors on LNKup', 'lnkup-theme' ); ?></p>
					<span class="lnkup-hero__card-link"><?php esc_html_e( 'Be an early vendor', 'lnkup-theme' ); ?> &rarr;</span>
				</a>
			<?php else : ?>
				<?php $live_stats = lnkup_theme_public_stats(); ?>
				<div class="lnkup-hero__card lnkup-hero__card--stat" data-testid="lnkup-hero-stat-card">
					<p class="lnkup-hero__stat"><?php echo esc_html( number_format_i18n( $live_stats['vendors'] ) . ( $live_stats['vendors'] > 0 ? '+' : '' ) ); ?></p>
					<p class="lnkup-hero__card-label"><?php esc_html_e( 'active vendors', 'lnkup-theme' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
	</div>
</section>
