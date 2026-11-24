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
				<?php if ( $marketplace_open ) : ?>
					<p class="lnkup-section__lead"><?php esc_html_e( 'Discover upcoming festivals, markets, and events actively accepting vendors.', 'lnkup-theme' ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( $marketplace_open ) : ?>
				<a href="<?php echo esc_url( $opportunities_url ); ?>" class="lnkup-btn lnkup-btn--outline"><?php esc_html_e( 'View all events', 'lnkup-theme' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( $signup_url ); ?>" class="lnkup-btn lnkup-btn--primary" data-testid="lnkup-featured-signup"><?php esc_html_e( 'Create free account', 'lnkup-theme' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
		$city_counts = function_exists( 'lnkup_homepage_city_counts' ) ? lnkup_homepage_city_counts() : array();
		if ( $marketplace_open && $city_counts ) :
			?>
			<ul class="lnkup-hero-cities lnkup-hero-cities--section">
				<?php foreach ( $city_counts as $city_row ) : ?>
					<li>
						<a href="<?php echo esc_url( $city_row['url'] ); ?>">
							<?php echo esc_html( $city_row['label'] ); ?>
							<span><?php echo esc_html( sprintf( _n( '%d open', '%d open', (int) $city_row['count'], 'lnkup-theme' ), (int) $city_row['count'] ) ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<div class="lnkup-event-grid<?php echo $marketplace_open ? '' : ' lnkup-event-grid--preview'; ?>">
			<?php if ( ! empty( $featured ) ) : ?>
				<?php
				$featured_groups = function_exists( 'lnkup_group_opportunities_by_event' )
					? lnkup_group_opportunities_by_event( $featured )
					: array();
				foreach ( $featured_groups as $slots ) :
					?>
					<div class="lnkup-featured-card-wrap<?php echo $marketplace_open ? '' : ' is-locked'; ?>" data-testid="lnkup-featured-card">
						<?php echo function_exists( 'lnkup_render_homepage_event_card' ) ? lnkup_render_homepage_event_card( $slots ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( ! $marketplace_open ) : ?>
							<div class="lnkup-featured-card-wrap__lock" aria-hidden="true">
								<span><?php echo esc_html( $launch_label ? sprintf( __( 'Opens %s', 'lnkup-theme' ), $launch_label ) : __( 'Opens soon', 'lnkup-theme' ) ); ?></span>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php elseif ( ! $marketplace_open ) : ?>
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
								<img src="<?php echo esc_url( lnkup_theme_asset( 'images/events/' . $event['image'] ) ); ?>" alt="<?php echo esc_attr( $event['name'] ); ?>" width="640" height="400" loading="lazy" decoding="async" />
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
			<?php else : ?>
				<p class="lnkup-muted"><?php esc_html_e( 'No events are open right now.', 'lnkup-theme' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>
