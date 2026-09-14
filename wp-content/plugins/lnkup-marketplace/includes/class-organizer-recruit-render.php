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
	 * Shared hub shell (filled by JS).
	 *
	 * @param string $context vendor|organizer.
	 */
	public static function hub_shell( string $context = 'vendor' ): string {
		$context = in_array( $context, array( 'vendor', 'organizer' ), true ) ? $context : 'vendor';
		ob_start();
		?>
		<div
			class="lnkup-org-recruit"
			id="lnkup-org-recruit-<?php echo esc_attr( $context ); ?>"
			data-context="<?php echo esc_attr( $context ); ?>"
			data-testid="lnkup-org-recruit-hub"
		>
			<div class="lnkup-org-recruit__hero">
				<p class="lnkup-org-recruit__eyebrow"><?php esc_html_e( 'Referral Invites', 'lnkup-marketplace' ); ?></p>
				<h2 class="lnkup-org-recruit__title"><?php esc_html_e( 'Invite organizers or vendors — earn on LNKup fees for a year', 'lnkup-marketplace' ); ?></h2>
				<p class="lnkup-org-recruit__body lnkup-muted">
					<?php esc_html_e( 'Share a role-specific link. When they pay booth fees (vendors) or collect booth fees on their events (organizers), you earn a cut of LNKup’s vendor fee and organizer fee for up to 12 months.', 'lnkup-marketplace' ); ?>
				</p>
			</div>
			<div class="lnkup-org-recruit__mount" data-org-recruit-mount>
				<p class="lnkup-muted"><?php esc_html_e( 'Loading your invite link…', 'lnkup-marketplace' ); ?></p>
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
