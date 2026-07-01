<?php
/**
 * Trust section.
 *
 * @package LNKup_Theme
 */

$marketplace_open = lnkup_theme_marketplace_open();
$launch_label     = lnkup_theme_launch_label();
$goals            = lnkup_theme_launch_goals();
$stats            = lnkup_theme_public_stats();
?>
<section class="lnkup-section lnkup-trust-section lnkup-reveal" data-testid="lnkup-trust-section">
	<div class="lnkup-container">
		<div class="lnkup-trust">
			<div class="lnkup-trust__intro">
				<p class="lnkup-eyebrow" data-testid="lnkup-trust-eyebrow">
					<?php echo $marketplace_open ? esc_html__( 'Trusted by vendors', 'lnkup-theme' ) : esc_html__( 'Launch goals', 'lnkup-theme' ); ?>
				</p>
				<h2 class="lnkup-heading lnkup-heading--section"><?php esc_html_e( 'Built for the live event economy', 'lnkup-theme' ); ?></h2>
				<p class="lnkup-trust__lead"><?php esc_html_e( 'LNKup is strictly the vendor opportunity layer — not ticketing. Organizers keep selling tickets on Posh, Eventbrite, and Universe while vendors find and book spots through LNKup.', 'lnkup-theme' ); ?></p>
			</div>
			<div class="lnkup-trust__stats">
				<?php if ( ! $marketplace_open ) : ?>
					<div class="lnkup-stat-card lnkup-stat-card--goal">
						<span class="lnkup-stat-card__badge"><?php esc_html_e( 'Goal', 'lnkup-theme' ); ?></span>
						<p class="lnkup-stat-card__value"><?php echo esc_html( lnkup_theme_format_goal_number( $goals['vendors'] ) ); ?></p>
						<p class="lnkup-stat-card__label"><?php esc_html_e( 'Vendor community goal', 'lnkup-theme' ); ?></p>
						<?php if ( $launch_label ) : ?>
							<p class="lnkup-stat-card__sub"><?php echo esc_html( sprintf( __( 'By %s', 'lnkup-theme' ), $launch_label ) ); ?></p>
						<?php endif; ?>
					</div>
					<div class="lnkup-stat-card lnkup-stat-card--goal">
						<span class="lnkup-stat-card__badge"><?php esc_html_e( 'Goal', 'lnkup-theme' ); ?></span>
						<p class="lnkup-stat-card__value"><?php echo esc_html( lnkup_theme_format_goal_number( $goals['organizers'] ) ); ?></p>
						<p class="lnkup-stat-card__label"><?php esc_html_e( 'Organizer community goal', 'lnkup-theme' ); ?></p>
						<?php if ( $launch_label ) : ?>
							<p class="lnkup-stat-card__sub"><?php echo esc_html( sprintf( __( 'By %s', 'lnkup-theme' ), $launch_label ) ); ?></p>
						<?php endif; ?>
					</div>
					<div class="lnkup-stat-card lnkup-stat-card--goal">
						<p class="lnkup-stat-card__value"><?php esc_html_e( 'Free', 'lnkup-theme' ); ?></p>
						<p class="lnkup-stat-card__label"><?php esc_html_e( 'To join & apply', 'lnkup-theme' ); ?></p>
						<p class="lnkup-stat-card__sub"><?php esc_html_e( 'No upfront fees', 'lnkup-theme' ); ?></p>
					</div>
					<div class="lnkup-stat-card lnkup-stat-card--goal">
						<p class="lnkup-stat-card__value">$0</p>
						<p class="lnkup-stat-card__label"><?php esc_html_e( 'Due until accepted', 'lnkup-theme' ); ?></p>
						<p class="lnkup-stat-card__sub"><?php esc_html_e( 'Pay only when approved', 'lnkup-theme' ); ?></p>
					</div>
				<?php else : ?>
					<div class="lnkup-stat-card">
						<p class="lnkup-stat-card__value"><?php echo esc_html( number_format_i18n( $stats['vendors'] ) . ( $stats['vendors'] > 0 ? '+' : '' ) ); ?></p>
						<p class="lnkup-stat-card__label"><?php esc_html_e( 'Active vendors', 'lnkup-theme' ); ?></p>
					</div>
					<div class="lnkup-stat-card">
						<p class="lnkup-stat-card__value"><?php echo esc_html( number_format_i18n( $stats['organizers'] ) . ( $stats['organizers'] > 0 ? '+' : '' ) ); ?></p>
						<p class="lnkup-stat-card__label"><?php esc_html_e( 'Organizers', 'lnkup-theme' ); ?></p>
					</div>
					<div class="lnkup-stat-card">
						<p class="lnkup-stat-card__value"><?php echo esc_html( number_format_i18n( $stats['cities'] ) . ( $stats['cities'] > 0 ? '+' : '' ) ); ?></p>
						<p class="lnkup-stat-card__label"><?php esc_html_e( 'Cities', 'lnkup-theme' ); ?></p>
					</div>
					<div class="lnkup-stat-card">
						<p class="lnkup-stat-card__value"><?php echo esc_html( lnkup_theme_format_public_gmv( $stats['gmv'] ) ); ?></p>
						<p class="lnkup-stat-card__label"><?php esc_html_e( 'Vendor bookings', 'lnkup-theme' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( ! $marketplace_open ) : ?>
				<p class="lnkup-trust__disclaimer" data-testid="lnkup-trust-disclaimer">
					<?php
					echo esc_html(
						$launch_label
							? sprintf(
								/* translators: %s: launch date */
								__( 'Community goals for our %s launch — not current platform totals.', 'lnkup-theme' ),
								$launch_label
							)
							: __( 'Community goals — not current platform totals.', 'lnkup-theme' )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	</div>
</section>
