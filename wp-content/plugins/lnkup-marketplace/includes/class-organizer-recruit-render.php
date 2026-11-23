<?php
/**
 * Organizer recruit referrals UI shell.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard markup for referral invites.
 */
class LNKup_Organizer_Recruit_Render {

	/**
	 * Headline used as the referral page subtitle.
	 */
	public static function headline(): string {
		return __( 'Invite organizers or vendors — earn on LNKup fees for a year', 'lnkup-marketplace' );
	}

	/**
	 * Shared hub shell (filled by JS).
	 *
	 * @param string $context vendor|organizer.
	 * @param array  $args    show_title: false when the page header already shows the title and headline.
	 */
	public static function hub_shell( string $context = 'vendor', array $args = array() ): string {
		$context    = in_array( $context, array( 'vendor', 'organizer' ), true ) ? $context : 'vendor';
		$show_title = ! isset( $args['show_title'] ) || $args['show_title'];
		ob_start();
		?>
		<div
			class="lnkup-org-recruit"
			id="lnkup-org-recruit-<?php echo esc_attr( $context ); ?>"
			data-context="<?php echo esc_attr( $context ); ?>"
			data-testid="lnkup-org-recruit-hub"
		>
			<?php if ( $show_title ) : ?>
				<div class="lnkup-org-recruit__hero">
					<p class="lnkup-org-recruit__eyebrow"><?php esc_html_e( 'Referral Invites', 'lnkup-marketplace' ); ?></p>
					<h2 class="lnkup-org-recruit__title"><?php echo esc_html( self::headline() ); ?></h2>
				</div>
			<?php endif; ?>
			<div class="lnkup-org-recruit__mount" data-org-recruit-mount>
				<?php echo function_exists( 'lnkup_loading_state' ) ? lnkup_loading_state( 'panel' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Compact home CTA card.
	 *
	 * @param string $href Link to referrals hub.
	 */
	public static function home_cta( string $href ): string {
		ob_start();
		?>
		<a class="lnkup-org-recruit-cta" href="<?php echo esc_url( $href ); ?>" data-testid="lnkup-org-recruit-home-cta">
			<span class="lnkup-org-recruit-cta__title"><?php esc_html_e( 'Invite organizers or vendors', 'lnkup-marketplace' ); ?></span>
			<span class="lnkup-org-recruit-cta__body"><?php esc_html_e( 'Earn 20% of LNKup fees for 12 months when they book or collect booth payments.', 'lnkup-marketplace' ); ?></span>
			<span class="lnkup-org-recruit-cta__action"><?php esc_html_e( 'Get invite link', 'lnkup-marketplace' ); ?> →</span>
		</a>
		<?php
		return (string) ob_get_clean();
	}
}
