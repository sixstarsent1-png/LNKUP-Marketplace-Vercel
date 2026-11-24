<?php
/**
 * Site header 5.0: announcement, desktop dropdowns, Get started role menu, mobile drawer.
 *
 * @package LNKup_Theme
 */

$nav        = lnkup_public_nav();
$account    = lnkup_public_account();
$login_url  = lnkup_theme_login_url();
$is_front   = is_front_page();
$open       = lnkup_theme_marketplace_open();
$signup_v   = lnkup_public_signup_url( 'vendor' );
$signup_o   = lnkup_public_signup_url( 'organizer' );
$current    = trailingslashit( home_url( isset( $GLOBALS['wp']->request ) ? (string) $GLOBALS['wp']->request : '' ) );
?>
<?php get_template_part( 'template-parts/public/announcement' ); ?>
<?php get_template_part( 'template-parts/landing/launch-bar' ); ?>
<?php
if ( ! $is_front && ! lnkup_theme_is_acquisition_landing() ) {
	get_template_part( 'template-parts/landing/app-release-bar' );
}
?>
<header class="lp-header<?php echo $open ? '' : ' lp-header--prelaunch'; ?>" data-lp-header>
	<div class="lp-container lp-header__bar">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="lp-header__logo" aria-label="<?php esc_attr_e( 'LNKup home', 'lnkup-theme' ); ?>">
			<?php lnkup_logo(); ?>
		</a>

		<nav class="lp-nav" aria-label="<?php esc_attr_e( 'Primary', 'lnkup-theme' ); ?>">
			<?php foreach ( $nav as $item ) : ?>
				<div class="lp-nav__item">
					<?php if ( ! empty( $item['url'] ) ) : ?>
						<a class="lp-nav__link" href="<?php echo esc_url( $item['url'] ); ?>"<?php echo trailingslashit( strtok( $item['url'], '?#' ) ) === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $item['label'] ); ?></a>
					<?php else : ?>
						<?php
						$menu_id = 'lp-menu-' . $item['id'];
						$groups  = array_values( (array) $item['groups'] );
						$wide    = count( $groups ) > 1;
						?>
						<button type="button" class="lp-nav__trigger" aria-expanded="false" aria-controls="<?php echo esc_attr( $menu_id ); ?>" data-lp-menu-trigger>
							<?php echo esc_html( $item['label'] ); ?> <?php echo lnkup_public_icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</button>
						<div class="lp-menu<?php echo $wide ? ' lp-menu--wide' : ''; ?>" id="<?php echo esc_attr( $menu_id ); ?>" hidden data-lp-menu>
							<?php foreach ( $groups as $group ) : ?>
								<?php if ( $wide ) : ?><div><?php endif; ?>
								<?php if ( ! empty( $group['title'] ) ) : ?>
									<p class="lp-menu__group-title"><?php echo esc_html( $group['title'] ); ?></p>
								<?php endif; ?>
								<?php foreach ( $group['links'] as $link ) : ?>
									<a class="lp-menu__link" href="<?php echo esc_url( $link['url'] ); ?>">
										<span class="lp-menu__label"><?php echo esc_html( $link['label'] ); ?></span>
										<?php if ( ! empty( $link['desc'] ) ) : ?>
											<span class="lp-menu__desc"><?php echo esc_html( $link['desc'] ); ?></span>
										<?php elseif ( ! empty( $link['count'] ) ) : ?>
											<span class="lp-menu__count"><?php echo esc_html( $link['count'] ); ?></span>
										<?php endif; ?>
									</a>
								<?php endforeach; ?>
								<?php if ( $wide ) : ?></div><?php endif; ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</nav>

		<div class="lp-header__actions">
			<?php if ( ! $is_front ) : ?>
				<span class="lp-header__desktop-only"><?php lnkup_theme_app_download_control(); ?></span>
			<?php endif; ?>
			<?php if ( $account['url'] ) : ?>
				<a href="<?php echo esc_url( $account['url'] ); ?>" class="lp-btn lp-btn--primary" data-testid="lp-header-dashboard"><?php esc_html_e( 'Dashboard', 'lnkup-theme' ); ?></a>
			<?php elseif ( ! is_user_logged_in() ) : ?>
				<a href="<?php echo esc_url( $login_url ); ?>" class="lp-btn lp-btn--ghost"><?php esc_html_e( 'Log in', 'lnkup-theme' ); ?></a>
				<div class="lp-header__role">
					<button type="button" class="lp-btn lp-btn--primary" aria-expanded="false" aria-controls="lp-menu-role" data-lp-menu-trigger data-testid="lnkup-header-signup">
						<?php esc_html_e( 'Get started', 'lnkup-theme' ); ?> <?php echo lnkup_public_icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</button>
					<div class="lp-menu lp-menu--right" id="lp-menu-role" hidden data-lp-menu>
						<a class="lp-menu__link" href="<?php echo esc_url( $signup_v ); ?>" data-testid="lp-role-vendor">
							<span class="lp-menu__label"><?php esc_html_e( "I'm a vendor", 'lnkup-theme' ); ?></span>
							<span class="lp-menu__desc"><?php esc_html_e( 'Find events and apply to sell', 'lnkup-theme' ); ?></span>
						</a>
						<a class="lp-menu__link" href="<?php echo esc_url( $signup_o ); ?>" data-testid="lp-role-organizer">
							<span class="lp-menu__label"><?php esc_html_e( "I'm an organizer", 'lnkup-theme' ); ?></span>
							<span class="lp-menu__desc"><?php esc_html_e( 'List an event and book vendors', 'lnkup-theme' ); ?></span>
						</a>
					</div>
				</div>
			<?php endif; ?>
			<button type="button" class="lp-header__toggle" id="lnkup-mobile-nav-toggle" aria-expanded="false" aria-controls="lnkup-mobile-nav" aria-label="<?php esc_attr_e( 'Open menu', 'lnkup-theme' ); ?>" data-lp-drawer-open>
				<?php echo lnkup_public_icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</div>
	</div>
</header>

<div class="lp-drawer" id="lnkup-mobile-nav" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Menu', 'lnkup-theme' ); ?>" hidden data-lp-drawer>
	<button type="button" class="lp-drawer__scrim" tabindex="-1" aria-label="<?php esc_attr_e( 'Close menu', 'lnkup-theme' ); ?>" data-lp-drawer-close></button>
	<div class="lp-drawer__panel">
		<div class="lp-drawer__head">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="lp-header__logo" aria-label="<?php esc_attr_e( 'LNKup home', 'lnkup-theme' ); ?>"><?php lnkup_logo(); ?></a>
			<button type="button" class="lp-header__toggle" aria-label="<?php esc_attr_e( 'Close menu', 'lnkup-theme' ); ?>" data-lp-drawer-close>
				<?php echo lnkup_public_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</div>
		<nav class="lp-drawer__body" aria-label="<?php esc_attr_e( 'Mobile', 'lnkup-theme' ); ?>">
			<?php foreach ( $nav as $index => $item ) : ?>
				<?php if ( ! empty( $item['url'] ) ) : ?>
					<a class="lp-drawer__plain" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
				<?php else : ?>
					<details class="lp-drawer__group"<?php echo 0 === $index ? ' open' : ''; ?>>
						<summary><?php echo esc_html( $item['label'] ); ?> <?php echo lnkup_public_icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></summary>
						<div class="lp-drawer__links">
							<?php foreach ( (array) $item['groups'] as $group ) : ?>
								<?php foreach ( $group['links'] as $link ) : ?>
									<a href="<?php echo esc_url( $link['url'] ); ?>"><span><?php echo esc_html( $link['label'] ); ?></span><?php echo ! empty( $link['count'] ) ? '<span class="lp-muted lp-small">' . esc_html( $link['count'] ) . '</span>' : ''; ?></a>
								<?php endforeach; ?>
							<?php endforeach; ?>
						</div>
					</details>
				<?php endif; ?>
			<?php endforeach; ?>
		</nav>
		<div class="lp-drawer__foot">
			<?php if ( $account['url'] ) : ?>
				<a href="<?php echo esc_url( $account['url'] ); ?>" class="lp-btn lp-btn--primary lp-btn--lg lp-btn--block"><?php esc_html_e( 'Go to dashboard', 'lnkup-theme' ); ?></a>
			<?php elseif ( ! is_user_logged_in() ) : ?>
				<a href="<?php echo esc_url( $signup_v ); ?>" class="lp-btn lp-btn--primary lp-btn--lg lp-btn--block"><?php esc_html_e( "I'm a vendor — sign up free", 'lnkup-theme' ); ?></a>
				<a href="<?php echo esc_url( $signup_o ); ?>" class="lp-btn lp-btn--outline lp-btn--lg lp-btn--block"><?php esc_html_e( "I'm an organizer — list an event", 'lnkup-theme' ); ?></a>
				<a href="<?php echo esc_url( $login_url ); ?>" class="lp-btn lp-btn--ghost lp-btn--block"><?php esc_html_e( 'Log in', 'lnkup-theme' ); ?></a>
			<?php endif; ?>
			<?php if ( ! $is_front ) : ?>
				<?php lnkup_theme_app_download_control( 'menu' ); ?>
			<?php endif; ?>
		</div>
	</div>
</div>
