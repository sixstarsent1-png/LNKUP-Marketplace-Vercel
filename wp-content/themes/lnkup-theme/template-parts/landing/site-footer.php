<?php
/**
 * Site footer partial.
 *
 * @package LNKup_Theme
 */

$signup_url       = lnkup_theme_registration_url();
$marketplace_open = lnkup_theme_marketplace_open();
$launch_label     = lnkup_theme_launch_label();
?>
<footer class="lnkup-footer">
	<div class="lnkup-container">
		<div class="lnkup-footer__grid">
			<div>
				<h2 class="lnkup-heading lnkup-heading--footer"><?php esc_html_e( 'Find Your Next Opportunity.', 'lnkup-theme' ); ?></h2>
				<p><?php esc_html_e( 'Join thousands of vendors growing their business at the best live events in the country.', 'lnkup-theme' ); ?></p>
				<a href="<?php echo esc_url( is_user_logged_in() ? lnkup_page_url( 'vendor-profile' ) : $signup_url ); ?>" class="lnkup-btn lnkup-btn--accent lnkup-btn--lg"><?php echo is_user_logged_in() ? esc_html__( 'Complete profile', 'lnkup-theme' ) : esc_html__( 'Get started free', 'lnkup-theme' ); ?></a>
			</div>
			<div class="lnkup-footer__cols">
				<div>
					<h3><?php esc_html_e( 'For vendors', 'lnkup-theme' ); ?></h3>
					<ul>
						<li><a href="<?php echo esc_url( $marketplace_open ? lnkup_page_url( 'opportunities' ) : $signup_url ); ?>"><?php echo $marketplace_open ? esc_html__( 'Find Events', 'lnkup-theme' ) : esc_html__( 'Sign up', 'lnkup-theme' ); ?></a></li>
						<li><a href="<?php echo esc_url( $signup_url ); ?>"><?php esc_html_e( 'Create Account', 'lnkup-theme' ); ?></a></li>
						<li><a href="<?php echo esc_url( lnkup_page_url( 'vendor-dashboard' ) ); ?>"><?php esc_html_e( 'Applications', 'lnkup-theme' ); ?></a></li>
					</ul>
				</div>
				<div>
					<h3><?php esc_html_e( 'For organizers', 'lnkup-theme' ); ?></h3>
					<ul>
						<li><a href="<?php echo esc_url( lnkup_page_url( 'organizer-dashboard' ) ); ?>"><?php esc_html_e( 'Organizer Home', 'lnkup-theme' ); ?></a></li>
						<li><a href="<?php echo esc_url( lnkup_page_url( 'organizer-dashboard' ) ); ?>#create"><?php esc_html_e( 'Create Event', 'lnkup-theme' ); ?></a></li>
						<li><a href="<?php echo esc_url( lnkup_page_url( 'organizer-dashboard' ) ); ?>#events"><?php esc_html_e( 'Build Opportunity', 'lnkup-theme' ); ?></a></li>
					</ul>
				</div>
				<div>
					<h3><?php esc_html_e( 'Company', 'lnkup-theme' ); ?></h3>
					<ul>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-marketplace' ) ); ?>"><?php esc_html_e( 'Admin', 'lnkup-theme' ); ?></a></li>
						<li><a href="#"><?php esc_html_e( 'About', 'lnkup-theme' ); ?></a></li>
						<li><a href="#"><?php esc_html_e( 'Contact', 'lnkup-theme' ); ?></a></li>
					</ul>
				</div>
			</div>
		</div>
		<div class="lnkup-footer__bottom">
			<span class="lnkup-footer__brand">LNK<span>up</span></span>
			<p>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> LNKup. <?php esc_html_e( 'The opportunity network for live events.', 'lnkup-theme' ); ?><?php echo $launch_label && ! $marketplace_open ? ' · ' . esc_html( sprintf( __( 'Launching %s', 'lnkup-theme' ), $launch_label ) ) : ''; ?></p>
		</div>
	</div>
</footer>
