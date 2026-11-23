<?php
/**
 * Site header partial.
 *
 * @package LNKup_Theme
 */

$opportunities_url = lnkup_theme_opportunities_url();
$vendor_dash_url   = lnkup_page_url( 'vendor-dashboard' );
$organizer_url     = lnkup_page_url( 'organizer-dashboard' );
$signup_url        = lnkup_theme_signup_url();
$login_url         = lnkup_theme_login_url();
$marketplace_open  = lnkup_theme_marketplace_open();
$is_front          = is_front_page();
$list_event_url    = is_user_logged_in()
	? ( function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'create' ) : lnkup_page_url( 'organizer-dashboard' ) . '?view=create' )
	: add_query_arg( 'role', 'organizer', $signup_url );
$account_url       = '';
if ( is_user_logged_in() ) {
	$account_roles = (array) wp_get_current_user()->roles;
	if ( in_array( 'lnkup_organizer', $account_roles, true ) && ! array_intersect( array( 'lnkup_vendor', 'administrator' ), $account_roles ) ) {
		$account_url = $organizer_url;
	} elseif ( array_intersect( array( 'lnkup_vendor', 'lnkup_admin', 'administrator' ), $account_roles ) || current_user_can( 'manage_options' ) ) {
		$account_url = $vendor_dash_url;
	} elseif ( in_array( 'lnkup_organizer', $account_roles, true ) ) {
		$account_url = $organizer_url;
	}
}
?>
<header class="lnkup-header<?php echo $marketplace_open ? '' : ' lnkup-header--prelaunch'; ?>">
	<?php
	get_template_part( 'template-parts/landing/launch-bar' );
	?>
	<div class="lnkup-header__inner">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="lnkup-header__logo" aria-label="<?php esc_attr_e( 'LNKup home', 'lnkup-theme' ); ?>">
			<?php lnkup_logo(); ?>
		</a>
		<nav class="lnkup-header__nav" aria-label="<?php esc_attr_e( 'Primary', 'lnkup-theme' ); ?>">
			<?php if ( $marketplace_open ) : ?>
				<a href="<?php echo esc_url( $opportunities_url ); ?>"><?php esc_html_e( 'Events', 'lnkup-theme' ); ?></a>
				<a href="<?php echo esc_url( lnkup_page_url( 'organizers' ) ); ?>"><?php esc_html_e( 'Organizers', 'lnkup-theme' ); ?></a>
				<a class="lnkup-header__text-link" href="<?php echo esc_url( $list_event_url ); ?>"><?php esc_html_e( 'List an event', 'lnkup-theme' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( lnkup_theme_nav_href( 'launch-countdown' ) ); ?>"><?php esc_html_e( 'Launch', 'lnkup-theme' ); ?></a>
			<?php endif; ?>
		</nav>
		<div class="lnkup-header__actions">
			<?php if ( $account_url ) : ?>
				<?php if ( ! $is_front ) { lnkup_theme_app_download_control(); } ?>
				<a href="<?php echo esc_url( $account_url ); ?>" class="lnkup-btn lnkup-btn--ghost"><?php esc_html_e( 'Dashboard', 'lnkup-theme' ); ?></a>
			<?php elseif ( ! is_user_logged_in() ) : ?>
				<a href="<?php echo esc_url( $login_url ); ?>" class="lnkup-btn lnkup-btn--ghost"><?php esc_html_e( 'Log in', 'lnkup-theme' ); ?></a>
				<?php if ( ! $is_front ) { lnkup_theme_app_download_control(); } ?>
				<a href="<?php echo esc_url( $signup_url ); ?>" class="lnkup-btn lnkup-btn--primary" data-testid="lnkup-header-signup"><?php esc_html_e( 'Sign up free', 'lnkup-theme' ); ?></a>
			<?php elseif ( ! $is_front ) : ?>
				<?php lnkup_theme_app_download_control(); ?>
			<?php endif; ?>
		</div>
		<button type="button" class="lnkup-header__toggle" id="lnkup-mobile-nav-toggle" aria-label="<?php esc_attr_e( 'Toggle menu', 'lnkup-theme' ); ?>" aria-expanded="false">
			<span></span><span></span><span></span>
		</button>
	</div>
	<div class="lnkup-header__mobile" id="lnkup-mobile-nav" hidden>
		<nav>
			<?php if ( $marketplace_open ) : ?>
				<a href="<?php echo esc_url( $opportunities_url ); ?>"><?php esc_html_e( 'Events', 'lnkup-theme' ); ?></a>
				<a href="<?php echo esc_url( lnkup_page_url( 'organizers' ) ); ?>"><?php esc_html_e( 'Organizers', 'lnkup-theme' ); ?></a>
				<a href="<?php echo esc_url( $list_event_url ); ?>"><?php esc_html_e( 'List an event', 'lnkup-theme' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( lnkup_theme_nav_href( 'launch-countdown' ) ); ?>"><?php esc_html_e( 'Launch', 'lnkup-theme' ); ?></a>
			<?php endif; ?>
			<?php if ( $account_url ) : ?>
				<?php if ( ! $is_front ) { lnkup_theme_app_download_control( 'menu' ); } ?>
				<a href="<?php echo esc_url( $account_url ); ?>" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Dashboard', 'lnkup-theme' ); ?></a>
			<?php elseif ( ! is_user_logged_in() ) : ?>
				<a href="<?php echo esc_url( $login_url ); ?>" class="lnkup-btn lnkup-btn--outline"><?php esc_html_e( 'Log in', 'lnkup-theme' ); ?></a>
				<?php if ( ! $is_front ) { lnkup_theme_app_download_control( 'menu' ); } ?>
				<a href="<?php echo esc_url( $signup_url ); ?>" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Sign up free', 'lnkup-theme' ); ?></a>
			<?php else : ?>
				<?php if ( ! $is_front ) { lnkup_theme_app_download_control( 'menu' ); } ?>
			<?php endif; ?>
		</nav>
	</div>
</header>
