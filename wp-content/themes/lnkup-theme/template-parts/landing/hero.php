<?php
/**
 * Hero section partial.
 *
 * @package LNKup_Theme
 */

$signup_url        = lnkup_theme_registration_url();
$opportunities_url = lnkup_page_url( 'opportunities' );
$profile_url       = lnkup_page_url( 'vendor-profile' );
$hero_image        = lnkup_theme_asset( 'images/events/block-party.svg' );
$launch_label      = lnkup_theme_launch_label();
$marketplace_open  = lnkup_theme_marketplace_open();
$launch_goals      = lnkup_theme_launch_goals();
$primary_url       = is_user_logged_in() ? $profile_url : $signup_url;
$primary_label     = $marketplace_open
	? ( is_user_logged_in() ? __( 'Find Events', 'lnkup-theme' ) : __( 'Join the Prelaunch', 'lnkup-theme' ) )
	: ( is_user_logged_in() ? __( 'Complete your profile', 'lnkup-theme' ) : __( 'Join the Prelaunch', 'lnkup-theme' ) );
$secondary_url     = $marketplace_open ? $signup_url : '#how-it-works';
$secondary_label   = $marketplace_open ? __( 'List my business', 'lnkup-theme' ) : __( 'Explore How It Works', 'lnkup-theme' );
if ( $marketplace_open && is_user_logged_in() ) {
	$primary_url = $opportunities_url;
}
$vendor_goal_label = number_format_i18n( $launch_goals['vendors'] ) . '+';
$hero_floaters     = array( 'tent', 'music', 'pin', 'ticket' );
?>
<section class="lnkup-hero lnkup-reveal">
	<div class="lnkup-hero__canvas" aria-hidden="true">
		<div class="lnkup-hero__grid-pattern"></div>
		<div class="lnkup-hero__orb lnkup-hero__orb--primary"></div>
		<div class="lnkup-hero__orb lnkup-hero__orb--secondary"></div>
	</div>
	<div class="lnkup-hero__glow" aria-hidden="true"></div>
	<div class="lnkup-hero__floaters" aria-hidden="true" id="lnkup-hero-floaters">
		<?php foreach ( $hero_floaters as $i => $icon ) : ?>
			<span class="lnkup-hero__floater lnkup-hero__floater--<?php echo esc_attr( (string) ( $i + 1 ) ); ?>">
				<?php echo lnkup_theme_landing_icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</span>
		<?php endforeach; ?>
	</div>
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
					<?php esc_html_e( 'The opportunity network for live events', 'lnkup-theme' ); ?>
				</span>
			<?php endif; ?>
			<h1 class="lnkup-heading lnkup-heading--hero"><?php esc_html_e( 'The Opportunity Network for Live Events', 'lnkup-theme' ); ?></h1>
			<p class="lnkup-hero__lead">
				<?php esc_html_e( 'Find vendor opportunities, apply for free, get approved, pay securely, and unlock event details all in one place.', 'lnkup-theme' ); ?>
			</p>
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
		<div class="lnkup-hero__visual" data-testid="lnkup-hero-visual">
			<div class="lnkup-hero__image-frame">
				<div class="lnkup-hero__image-wrap">
					<img src="<?php echo esc_url( $hero_image ); ?>" alt="<?php esc_attr_e( 'Vibrant outdoor block party with vendor booths', 'lnkup-theme' ); ?>" width="720" height="560" loading="eager" />
					<div class="lnkup-hero__image-shine" aria-hidden="true"></div>
				</div>
			</div>
			<div class="lnkup-hero__card lnkup-hero__card--opportunity">
				<div class="lnkup-hero__card-row">
					<div class="lnkup-hero__card-thumb" aria-hidden="true">
						<img src="<?php echo esc_url( $hero_image ); ?>" alt="" width="40" height="40" loading="lazy" />
						<span class="lnkup-hero__card-thumb-icon"><?php echo lnkup_theme_category_icon( 'Food Vendor' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</div>
					<div class="lnkup-hero__card-body">
						<p class="lnkup-hero__card-label"><?php esc_html_e( 'New opportunity', 'lnkup-theme' ); ?></p>
						<p class="lnkup-hero__card-title"><?php esc_html_e( 'Soul District Block Party', 'lnkup-theme' ); ?></p>
					</div>
				</div>
				<div class="lnkup-hero__card-meta">
					<span>Atlanta, GA</span>
					<span class="lnkup-badge lnkup-badge--accent">12 spots left</span>
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
