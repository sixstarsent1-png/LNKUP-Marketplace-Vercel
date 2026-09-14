<?php
/**
 * Site header partial.
 *
 * @package LNKup_Theme
 */

$opportunities_url = lnkup_page_url( 'opportunities' );
$vendor_dash_url   = lnkup_page_url( 'vendor-dashboard' );
$organizer_url     = lnkup_page_url( 'organizer-dashboard' );
$signup_url        = lnkup_theme_registration_url();
$login_url         = wp_login_url( $signup_url );
$marketplace_open  = lnkup_theme_marketplace_open();
?>
<header class="lnkup-header<?php echo $marketplace_open ? '' : ' lnkup-header--prelaunch'; ?>">
	<?php
	get_template_part( 'template-parts/landing/launch-bar' );
	get_template_part( 'template-parts/landing/app-release-bar' );
	?>
	<div class="lnkup-header__inner">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="lnkup-header__logo" aria-label="<?php esc_attr_e( 'LNKup home', 'lnkup-theme' ); ?>">
			<?php lnkup_logo(); ?>
		</a>
		<nav class="lnkup-header__nav" aria-label="<?php esc_attr_e( 'Primary', 'lnkup-theme' ); ?>">
			<a href="<?php echo esc_url( lnkup_theme_nav_href( 'how-it-works' ) ); ?>"><?php esc_html_e( 'How it works', 'lnkup-theme' ); ?></a>
			<a href="<?php echo esc_url( lnkup_theme_nav_href( 'categories' ) ); ?>"><?php esc_html_e( 'Categories', 'lnkup-theme' ); ?></a>
			<?php if ( $marketplace_open ) : ?>
				<a href="<?php echo esc_url( $opportunities_url ); ?>"><?php esc_html_e( 'Opportunities', 'lnkup-theme' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( lnkup_theme_nav_href( 'launch-countdown' ) ); ?>"><?php esc_html_e( 'Launch', 'lnkup-theme' ); ?></a>
			<?php endif; ?>
			<a href="<?php echo esc_url( lnkup_theme_nav_href( 'for-audiences' ) ); ?>"><?php esc_html_e( 'For organizers', 'lnkup-theme' ); ?></a>
		</nav>
		<div class="lnkup-header__actions">
			<?php if ( is_user_logged_in() ) : ?>
				<a href="<?php echo esc_url( $vendor_dash_url ); ?>" class="lnkup-btn lnkup-btn--ghost"><?php esc_html_e( 'Dashboard', 'lnkup-theme' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( $login_url ); ?>" class="lnkup-btn lnkup-btn--ghost"><?php esc_html_e( 'Log in', 'lnkup-theme' ); ?></a>
				<a href="<?php echo esc_url( $signup_url ); ?>" class="lnkup-btn lnkup-btn--primary" data-testid="lnkup-header-signup"><?php esc_html_e( 'Sign up free', 'lnkup-theme' ); ?></a>
			<?php endif; ?>
		</div>
		<button type="button" class="lnkup-header__toggle" id="lnkup-mobile-nav-toggle" aria-label="<?php esc_attr_e( 'Toggle menu', 'lnkup-theme' ); ?>" aria-expanded="false">
			<span></span><span></span><span></span>
		</button>
	</div>
	<div class="lnkup-header__mobile" id="lnkup-mobile-nav" hidden>
		<nav>
			<a href="<?php echo esc_url( lnkup_theme_nav_href( 'how-it-works' ) ); ?>"><?php esc_html_e( 'How it works', 'lnkup-theme' ); ?></a>
			<a href="<?php echo esc_url( lnkup_theme_nav_href( 'categories' ) ); ?>"><?php esc_html_e( 'Categories', 'lnkup-theme' ); ?></a>
			<?php if ( $marketplace_open ) : ?>
				<a href="<?php echo esc_url( $opportunities_url ); ?>"><?php esc_html_e( 'Opportunities', 'lnkup-theme' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( lnkup_theme_nav_href( 'launch-countdown' ) ); ?>"><?php esc_html_e( 'Launch', 'lnkup-theme' ); ?></a>
			<?php endif; ?>
			<a href="<?php echo esc_url( lnkup_theme_nav_href( 'for-audiences' ) ); ?>"><?php esc_html_e( 'For organizers', 'lnkup-theme' ); ?></a>
			<a href="<?php echo esc_url( $login_url ); ?>" class="lnkup-btn lnkup-btn--outline"><?php esc_html_e( 'Log in', 'lnkup-theme' ); ?></a>
			<?php if ( ! is_user_logged_in() ) : ?>
				<a href="<?php echo esc_url( $signup_url ); ?>" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Sign up free', 'lnkup-theme' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( $vendor_dash_url ); ?>" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Dashboard', 'lnkup-theme' ); ?></a>
			<?php endif; ?>
		</nav>
	</div>
</header>
