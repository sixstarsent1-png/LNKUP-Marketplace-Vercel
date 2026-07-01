<?php
/**
 * Featured opportunities section.
 *
 * @package LNKup_Theme
 */

$opportunities_url = lnkup_page_url( 'opportunities' );
$signup_url        = lnkup_theme_registration_url();
$launch_label      = lnkup_theme_launch_label();
$marketplace_open  = lnkup_theme_marketplace_open();

if ( function_exists( 'lnkup_get_featured_opportunities' ) ) {
	$featured = lnkup_get_featured_opportunities( 6, array( 'curate_homepage' => true ) );
} else {
	$featured = array();
}
?>
<section id="opportunities" class="lnkup-section lnkup-section--muted lnkup-section--bordered lnkup-reveal">
	<div class="lnkup-container">
		<div class="lnkup-section__header-row">
			<div class="lnkup-section__intro">
				<p class="lnkup-eyebrow"><?php echo $marketplace_open ? esc_html__( 'Featured opportunities', 'lnkup-theme' ) : esc_html__( 'Sneak peek', 'lnkup-theme' ); ?></p>
				<h2 class="lnkup-heading lnkup-heading--section">
					<?php
					echo $marketplace_open
						? esc_html__( 'Events booking vendors right now', 'lnkup-theme' )
						: esc_html( $launch_label ? sprintf( __( 'Full marketplace opens %s', 'lnkup-theme' ), $launch_label ) : __( 'Full marketplace opening soon', 'lnkup-theme' ) );
					?>
				</h2>
			</div>
			<?php if ( $marketplace_open ) : ?>
				<a href="<?php echo esc_url( $opportunities_url ); ?>" class="lnkup-btn lnkup-btn--outline"><?php esc_html_e( 'View all events', 'lnkup-theme' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( $signup_url ); ?>" class="lnkup-btn lnkup-btn--primary" data-testid="lnkup-featured-signup"><?php esc_html_e( 'Create free account', 'lnkup-theme' ); ?></a>
			<?php endif; ?>
		</div>
		<div class="lnkup-event-grid<?php echo $marketplace_open ? '' : ' lnkup-event-grid--preview'; ?>">
			<?php if ( ! empty( $featured ) ) : ?>
				<?php foreach ( $featured as $opp ) : ?>
					<div class="lnkup-featured-card-wrap<?php echo $marketplace_open ? '' : ' is-locked'; ?>" data-testid="lnkup-featured-card">
						<?php echo function_exists( 'lnkup_render_opportunity_card_v2' ) ? lnkup_render_opportunity_card_v2( $opp ) : lnkup_render_event_card( $opp ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( ! $marketplace_open ) : ?>
							<div class="lnkup-featured-card-wrap__lock" aria-hidden="true">
								<span><?php echo esc_html( $launch_label ? sprintf( __( 'Opens %s', 'lnkup-theme' ), $launch_label ) : __( 'Opens soon', 'lnkup-theme' ) ); ?></span>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<?php
				$fallback = array(
					array( 'name' => 'Dirty South Rodeo Charlotte', 'city' => 'Charlotte', 'state' => 'NC', 'fee' => 250, 'spots' => 6, 'total' => 40, 'image' => 'rodeo.svg' ),
					array( 'name' => 'Soul District Block Party', 'city' => 'Atlanta', 'state' => 'GA', 'fee' => 180, 'spots' => 12, 'total' => 50, 'image' => 'block-party.svg' ),
					array( 'name' => 'Sunset & Soul Miami', 'city' => 'Miami', 'state' => 'FL', 'fee' => 400, 'spots' => 3, 'total' => 35, 'image' => 'sunset-miami.svg' ),
				);
				foreach ( $fallback as $event ) :
					?>
					<div class="lnkup-featured-card-wrap<?php echo $marketplace_open ? '' : ' is-locked'; ?>">
						<article class="lnkup-event-card">
							<div class="lnkup-event-card__media">
								<img src="<?php echo esc_url( lnkup_theme_asset( 'images/events/' . $event['image'] ) ); ?>" alt="<?php echo esc_attr( $event['name'] ); ?>" />
								<span class="lnkup-badge">$<?php echo esc_html( (string) $event['fee'] ); ?> vendor fee</span>
							</div>
							<div class="lnkup-event-card__body">
								<h3><?php echo esc_html( $event['name'] ); ?></h3>
								<p><?php echo esc_html( $event['city'] . ', ' . $event['state'] ); ?></p>
								<a href="<?php echo esc_url( $marketplace_open ? $opportunities_url : $signup_url ); ?>" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php echo $marketplace_open ? esc_html__( 'Apply', 'lnkup-theme' ) : esc_html__( 'Sign up', 'lnkup-theme' ); ?></a>
							</div>
						</article>
						<?php if ( ! $marketplace_open ) : ?>
							<div class="lnkup-featured-card-wrap__lock" aria-hidden="true"><span><?php esc_html_e( 'Opens at launch', 'lnkup-theme' ); ?></span></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>
</section>
<?php if ( $marketplace_open && function_exists( 'lnkup_marketplace_phase_at_least' ) && lnkup_marketplace_phase_at_least( '1b' ) && class_exists( 'LNKup_Trending' ) ) : ?>
	<?php
	$closing = function_exists( 'lnkup_curate_trending_section' ) ? lnkup_curate_trending_section( LNKup_Trending::get_section( 'closing_soon', 8 ), 4 ) : LNKup_Trending::get_section( 'closing_soon', 4 );
	$applied = function_exists( 'lnkup_curate_trending_section' ) ? lnkup_curate_trending_section( LNKup_Trending::get_section( 'most_applied', 8 ), 4 ) : LNKup_Trending::get_section( 'most_applied', 4 );
	?>
	<?php if ( $closing || $applied ) : ?>
<section class="lnkup-section lnkup-section--bordered lnkup-reveal">
	<div class="lnkup-container">
		<?php if ( $closing ) : ?>
		<div class="lnkup-section__intro" style="margin-bottom:1.5rem;">
			<p class="lnkup-eyebrow"><?php esc_html_e( 'Closing soon', 'lnkup-theme' ); ?></p>
			<h2 class="lnkup-heading lnkup-heading--section"><?php esc_html_e( 'Apply before deadlines pass', 'lnkup-theme' ); ?></h2>
		</div>
		<div class="lnkup-event-grid" style="margin-bottom:2.5rem;">
			<?php foreach ( $closing as $opp ) : ?>
				<?php echo function_exists( 'lnkup_render_opportunity_card_v2' ) ? lnkup_render_opportunity_card_v2( $opp ) : lnkup_render_event_card( $opp ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
		<?php if ( $applied ) : ?>
		<div class="lnkup-section__intro" style="margin-bottom:1.5rem;">
			<p class="lnkup-eyebrow"><?php esc_html_e( 'Most applied', 'lnkup-theme' ); ?></p>
			<h2 class="lnkup-heading lnkup-heading--section"><?php esc_html_e( 'Popular with vendors', 'lnkup-theme' ); ?></h2>
		</div>
		<div class="lnkup-event-grid">
			<?php foreach ( $applied as $opp ) : ?>
				<?php echo function_exists( 'lnkup_render_opportunity_card_v2' ) ? lnkup_render_opportunity_card_v2( $opp ) : lnkup_render_event_card( $opp ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</section>
	<?php endif; ?>
<?php endif; ?>
