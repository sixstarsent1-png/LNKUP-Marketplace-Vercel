<?php
/**
 * Static Knowledge Base articles for vendor and organizer dashboards.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Role-specific help content (no CPT).
 */
class LNKup_Knowledge_Base {

	/**
	 * Articles for a dashboard role.
	 *
	 * @param string $role vendor|organizer.
	 * @return array<int, array<string, string>>
	 */
	public static function articles_for_role( string $role ): array {
		$role = sanitize_key( $role );
		if ( 'organizer' === $role ) {
			return self::organizer_articles();
		}
		return self::vendor_articles();
	}

	/**
	 * Render Knowledge Base panel markup.
	 *
	 * @param string $role vendor|organizer.
	 * @return string
	 */
	public static function render( string $role ): string {
		$role     = sanitize_key( $role );
		$articles = self::articles_for_role( $role );
		$faq_url  = self::faq_url();
		$support  = self::support_email();
		$checklist_url = self::checklist_reset_target( $role );

		ob_start();
		?>
		<section class="lnkup-kb lnkup-panel" data-section="help" data-testid="lnkup-knowledge-base" data-kb-role="<?php echo esc_attr( $role ); ?>">
			<header class="lnkup-kb__header">
				<h2 class="lnkup-panel__title"><?php echo esc_html( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_title' ) : __( 'Knowledge Base', 'lnkup-marketplace' ) ); ?></h2>
				<p class="lnkup-kb__intro lnkup-muted"><?php echo esc_html( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_intro' ) : __( 'Quick answers for common marketplace tasks.', 'lnkup-marketplace' ) ); ?></p>
			</header>
			<div class="lnkup-kb__list">
				<?php foreach ( $articles as $article ) : ?>
					<details class="lnkup-kb__item">
						<summary class="lnkup-kb__summary"><?php echo esc_html( $article['title'] ); ?></summary>
						<div class="lnkup-kb__body">
							<p><?php echo esc_html( $article['body'] ); ?></p>
							<?php if ( ! empty( $article['cta_url'] ) && ! empty( $article['cta_label'] ) ) : ?>
								<a href="<?php echo esc_url( $article['cta_url'] ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline"><?php echo esc_html( $article['cta_label'] ); ?></a>
							<?php endif; ?>
						</div>
					</details>
				<?php endforeach; ?>
			</div>
			<footer class="lnkup-kb__footer">
				<?php if ( $faq_url ) : ?>
					<p class="lnkup-kb__link-row">
						<a href="<?php echo esc_url( $faq_url ); ?>" class="lnkup-kb__link"><?php echo esc_html( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_faq_link' ) : __( 'View market FAQ', 'lnkup-marketplace' ) ); ?></a>
					</p>
				<?php endif; ?>
				<?php if ( $support ) : ?>
					<p class="lnkup-kb__link-row lnkup-muted">
						<?php echo esc_html( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_support_label' ) : __( 'Need more help?', 'lnkup-marketplace' ) ); ?>
						<a href="<?php echo esc_url( 'mailto:' . $support ); ?>"><?php echo esc_html( $support ); ?></a>
					</p>
				<?php endif; ?>
				<p class="lnkup-kb__reset-row">
					<button
						type="button"
						class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"
						data-kb-show-checklist
						data-kb-role="<?php echo esc_attr( $role ); ?>"
						data-kb-checklist-url="<?php echo esc_url( $checklist_url ); ?>"
					>
						<?php echo esc_html( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_show_checklist_again' ) : __( 'Show getting started checklist again', 'lnkup-marketplace' ) ); ?>
					</button>
				</p>
				<p class="lnkup-kb__reset-row">
					<button
						type="button"
						class="lnkup-btn lnkup-btn--outline lnkup-btn--sm"
						data-kb-replay-tour
						data-kb-role="<?php echo esc_attr( $role ); ?>"
					>
						<?php echo esc_html( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_replay' ) : __( 'Replay platform tour', 'lnkup-marketplace' ) ); ?>
					</button>
				</p>
			</footer>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private static function vendor_articles(): array {
		$profile_url  = lnkup_page_url_helper( 'vendor-profile' );
		$find_url     = lnkup_page_url_helper( 'opportunities' );
		$dash         = lnkup_page_url_helper( 'vendor-dashboard' );
		$payments_url = add_query_arg( 'view', 'payments', $dash );
		$ops_url      = add_query_arg( 'view', 'operations', $dash );

		return array(
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_vendor_profile_title' ) : __( 'Complete your profile and documents', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_vendor_profile_body' ) : __( 'Add business details and upload required documents so organizers can approve you faster.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_vendor_profile_cta' ) : __( 'Open profile', 'lnkup-marketplace' ),
				'cta_url'   => $profile_url,
			),
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_vendor_find_title' ) : __( 'Find events and apply', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_vendor_find_body' ) : __( 'Browse opportunities, save favorites, and submit applications from Find Events.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_vendor_find_cta' ) : __( 'Find events', 'lnkup-marketplace' ),
				'cta_url'   => $find_url,
			),
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_vendor_pay_title' ) : __( 'Pay booth fees and unlock your pass', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_vendor_pay_body' ) : __( 'After you are accepted, pay your booth fee in Wallet to unlock packet details and your vendor pass.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_vendor_pay_cta' ) : __( 'Open wallet', 'lnkup-marketplace' ),
				'cta_url'   => $payments_url,
			),
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_vendor_ops_title' ) : __( 'Event day: operations and check-in', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_vendor_ops_body' ) : __( 'Use Event Operations for load-in, parking, check-in, and day-of support once you are booked.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_vendor_ops_cta' ) : __( 'Open operations', 'lnkup-marketplace' ),
				'cta_url'   => $ops_url,
			),
		);
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private static function organizer_articles(): array {
		$dash = lnkup_page_url_helper( 'organizer-dashboard' );

		return array(
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_org_launch_title' ) : __( 'Launch your first event', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_org_launch_body' ) : __( 'Create an event, set categories and booth fees, then publish so vendors can apply.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_org_launch_cta' ) : __( 'Launch event', 'lnkup-marketplace' ),
				'cta_url'   => lnkup_organizer_view_url( 'create' ),
			),
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_org_review_title' ) : __( 'Review and approve vendors', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_org_review_body' ) : __( 'Open Vendor Requests to approve, waitlist, or reject applications as they come in.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_org_review_cta' ) : __( 'Review requests', 'lnkup-marketplace' ),
				'cta_url'   => lnkup_organizer_view_url( 'applications' ),
			),
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_org_fees_title' ) : __( 'Fees, spots, and payments', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_org_fees_body' ) : __( 'Edit opportunity pricing and spots from your Events list. Vendors pay only after they are accepted.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_org_fees_cta' ) : __( 'Open events', 'lnkup-marketplace' ),
				'cta_url'   => lnkup_organizer_view_url( 'events' ),
			),
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_org_discover_title' ) : __( 'Discover / invite vendors', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_org_discover_body' ) : __( 'Search the vendor directory, save favorites, and send invites from Discover and Invites.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_org_discover_cta' ) : __( 'Discover vendors', 'lnkup-marketplace' ),
				'cta_url'   => lnkup_organizer_view_url( 'vendors' ),
			),
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_org_payouts_title' ) : __( 'Payouts and financial center', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_org_payouts_body' ) : __( 'Connect payouts and track booth-fee revenue in the Financial center.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_org_payouts_cta' ) : __( 'Open financials', 'lnkup-marketplace' ),
				'cta_url'   => lnkup_organizer_view_url( 'financial' ),
			),
		);
	}

	/**
	 * @return string
	 */
	private static function faq_url(): string {
		return trailingslashit( home_url( '/' ) ) . '#faq';
	}

	/**
	 * @return string
	 */
	private static function support_email(): string {
		$settings = get_option( 'lnkup_settings', array() );
		$email    = is_array( $settings ) && ! empty( $settings['support_email'] )
			? (string) $settings['support_email']
			: (string) get_option( 'admin_email' );
		return sanitize_email( $email );
	}

	/**
	 * Where “show checklist again” should land after reset.
	 *
	 * @param string $role Role.
	 * @return string
	 */
	private static function checklist_reset_target( string $role ): string {
		if ( 'organizer' === $role ) {
			return lnkup_page_url_helper( 'organizer-dashboard' ) . '#lnkup-launch-checklist';
		}
		return lnkup_page_url_helper( 'vendor-dashboard' ) . '#lnkup-vendor-getting-started';
	}
}
