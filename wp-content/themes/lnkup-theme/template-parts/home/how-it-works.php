<?php
/**
 * How it works, with a vendor / organizer toggle. Steps describe features that exist today.
 *
 * @package LNKup_Theme
 */

$steps = lnkup_public_steps();
?>
<section class="lp-section lp-section--ink" id="how-it-works" aria-labelledby="lp-hiw-title" data-testid="lp-how-it-works">
	<div class="lp-container">
		<?php
		lnkup_public_section_head(
			array(
				'eyebrow' => __( 'How it works', 'lnkup-theme' ),
				'title'   => __( 'One marketplace, two simple paths', 'lnkup-theme' ),
				'id'      => 'lp-hiw-title',
			)
		);
		?>
		<div class="lp-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Choose your role', 'lnkup-theme' ); ?>" data-lp-tabs style="margin-bottom:var(--lp-space-6)">
			<button type="button" class="lp-tabs__tab" role="tab" id="lp-hiw-tab-vendor" aria-controls="lp-hiw-vendor" aria-selected="true"><?php esc_html_e( 'For vendors', 'lnkup-theme' ); ?></button>
			<button type="button" class="lp-tabs__tab" role="tab" id="lp-hiw-tab-organizer" aria-controls="lp-hiw-organizer" aria-selected="false" tabindex="-1"><?php esc_html_e( 'For organizers', 'lnkup-theme' ); ?></button>
		</div>
		<?php foreach ( array( 'vendor', 'organizer' ) as $role ) : ?>
			<div class="lp-tabpanel" id="lp-hiw-<?php echo esc_attr( $role ); ?>" role="tabpanel" aria-labelledby="lp-hiw-tab-<?php echo esc_attr( $role ); ?>"<?php echo 'vendor' === $role ? '' : ' hidden'; ?>>
				<ol class="lp-steps">
					<?php foreach ( $steps[ $role ] as $step ) : ?>
						<li class="lp-step">
							<h3><?php echo esc_html( $step[0] ); ?></h3>
							<p><?php echo esc_html( $step[1] ); ?></p>
						</li>
					<?php endforeach; ?>
				</ol>
				<div class="lp-actions" style="margin-top:var(--lp-space-6)">
					<?php if ( 'vendor' === $role ) : ?>
						<a class="lp-btn lp-btn--lime lp-btn--lg" href="<?php echo esc_url( lnkup_public_signup_url( 'vendor' ) ); ?>"><?php esc_html_e( 'Create a free vendor profile', 'lnkup-theme' ); ?></a>
						<a class="lp-btn lp-btn--on-dark lp-btn--lg" href="<?php echo esc_url( home_url( '/for-vendors/' ) ); ?>"><?php esc_html_e( 'More for vendors', 'lnkup-theme' ); ?></a>
					<?php else : ?>
						<a class="lp-btn lp-btn--lime lp-btn--lg" href="<?php echo esc_url( lnkup_public_signup_url( 'organizer' ) ); ?>"><?php esc_html_e( 'List your event free', 'lnkup-theme' ); ?></a>
						<a class="lp-btn lp-btn--on-dark lp-btn--lg" href="<?php echo esc_url( home_url( '/for-organizers/' ) ); ?>"><?php esc_html_e( 'More for organizers', 'lnkup-theme' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</section>
