<?php
/**
 * Multi-slide platform tour modals for vendor and organizer dashboards.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Native (non-Shepherd) guided tour slides.
 */
class LNKup_Dashboard_Tour {

	const META_VENDOR_SEEN    = 'lnkup_vendor_tour_seen';
	const META_ORGANIZER_SEEN = 'lnkup_organizer_tour_seen';

	/**
	 * @param string $role vendor|organizer.
	 * @return string Meta key.
	 */
	public static function meta_key( string $role ): string {
		return 'organizer' === $role ? self::META_ORGANIZER_SEEN : self::META_VENDOR_SEEN;
	}

	/**
	 * Whether tour should auto-open (once).
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    vendor|organizer.
	 */
	public static function should_show( int $user_id, string $role ): bool {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}
		return ! (bool) get_user_meta( $user_id, self::meta_key( $role ), true );
	}

	/**
	 * Mark tour completed / skipped.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role.
	 */
	public static function mark_seen( int $user_id, string $role ): void {
		$user_id = absint( $user_id );
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::meta_key( $role ), 1 );
		}
	}

	/**
	 * Allow Help to replay tour without reopening welcome.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role.
	 */
	public static function reset( int $user_id, string $role ): void {
		$user_id = absint( $user_id );
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::meta_key( $role ) );
		}
	}

	/**
	 * @param string $role vendor|organizer.
	 * @return array<int, array<string, string>>
	 */
	public static function slides_for_role( string $role ): array {
		return 'organizer' === $role ? self::organizer_slides() : self::vendor_slides();
	}

	/**
	 * Render tour modal markup (always in DOM when on dashboard; JS controls visibility).
	 *
	 * @param string $role    vendor|organizer.
	 * @param bool   $auto_open Whether to open on first visit.
	 */
	public static function render_modal( string $role, bool $auto_open = false ): string {
		$role   = 'organizer' === $role ? 'organizer' : 'vendor';
		$slides = self::slides_for_role( $role );
		if ( ! $slides ) {
			return '';
		}
		$modal_id = 'organizer' === $role ? 'lnkup-organizer-tour-modal' : 'lnkup-vendor-tour-modal';
		$title_id = $modal_id . '-title';

		ob_start();
		?>
		<div
			class="lnkup-modal lnkup-modal--dashboard-tour"
			id="<?php echo esc_attr( $modal_id ); ?>"
			data-testid="<?php echo esc_attr( $modal_id ); ?>"
			data-tour-role="<?php echo esc_attr( $role ); ?>"
			data-tour-auto-open="<?php echo $auto_open ? '1' : '0'; ?>"
			hidden
		>
			<div class="lnkup-modal__backdrop" data-tour-skip="1"></div>
			<div class="lnkup-modal__dialog lnkup-tour-dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $title_id ); ?>">
				<button type="button" class="lnkup-modal__close" data-tour-skip="1" aria-label="<?php esc_attr_e( 'Close', 'lnkup-marketplace' ); ?>">&times;</button>
				<p class="lnkup-tour__eyebrow lnkup-muted"><?php echo esc_html( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_eyebrow' ) : __( 'Platform tour', 'lnkup-marketplace' ) ); ?></p>
				<div class="lnkup-tour__progress" data-tour-progress aria-hidden="true"></div>
				<?php foreach ( $slides as $i => $slide ) : ?>
					<div class="lnkup-tour__slide" data-tour-slide="<?php echo esc_attr( (string) $i ); ?>"<?php echo 0 === $i ? '' : ' hidden'; ?>>
						<h2 class="lnkup-type-title" <?php echo 0 === $i ? 'id="' . esc_attr( $title_id ) . '"' : ''; ?>><?php echo esc_html( $slide['title'] ); ?></h2>
						<p class="lnkup-muted lnkup-tour__body"><?php echo esc_html( $slide['body'] ); ?></p>
						<?php if ( ! empty( $slide['cta_url'] ) && ! empty( $slide['cta_label'] ) ) : ?>
							<p class="lnkup-tour__cta-wrap">
								<a href="<?php echo esc_url( $slide['cta_url'] ); ?>" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-tour-deeplink><?php echo esc_html( $slide['cta_label'] ); ?></a>
							</p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				<div class="lnkup-tour__actions">
					<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-tour-skip><?php echo esc_html( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_skip' ) : __( 'Skip tour', 'lnkup-marketplace' ) ); ?></button>
					<div class="lnkup-tour__nav">
						<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-tour-back hidden><?php echo esc_html( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_back' ) : __( 'Back', 'lnkup-marketplace' ) ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" data-tour-next><?php echo esc_html( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_next' ) : __( 'Next', 'lnkup-marketplace' ) ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" data-tour-done hidden><?php echo esc_html( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_done' ) : __( 'Done', 'lnkup-marketplace' ) ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private static function vendor_slides(): array {
		$profile = lnkup_page_url_helper( 'vendor-profile' );
		$find    = lnkup_page_url_helper( 'opportunities' );
		$dash    = lnkup_page_url_helper( 'vendor-dashboard' );
		$wallet  = add_query_arg( 'view', 'payments', $dash );
		$help    = add_query_arg( 'view', 'help', $dash );

		return array(
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_vendor_1_title' ) : __( 'Complete your profile', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_vendor_1_body' ) : __( 'Add business details and documents so organizers can approve you faster.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_vendor_1_cta' ) : __( 'Open profile', 'lnkup-marketplace' ),
				'cta_url'   => $profile,
			),
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_vendor_2_title' ) : __( 'Find events', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_vendor_2_body' ) : __( 'Browse opportunities near you, save favorites, and filter by city or category.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_vendor_2_cta' ) : __( 'Find events', 'lnkup-marketplace' ),
				'cta_url'   => $find,
			),
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_vendor_3_title' ) : __( 'Apply for free', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_vendor_3_body' ) : __( 'Submit applications from Find Events. You only pay a booth fee if the organizer accepts you.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_vendor_3_cta' ) : __( 'View requests', 'lnkup-marketplace' ),
				'cta_url'   => add_query_arg( 'view', 'applications', $dash ),
			),
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_vendor_4_title' ) : __( 'Pay and unlock your pass', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_vendor_4_body' ) : __( 'After acceptance, pay in Wallet to unlock load-in details and your vendor pass.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_vendor_4_cta' ) : __( 'Open wallet', 'lnkup-marketplace' ),
				'cta_url'   => $wallet,
			),
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_vendor_5_title' ) : __( 'Help anytime', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_vendor_5_body' ) : __( 'Open Help from the sidebar for the Knowledge Base, Getting Started checklist, and this tour again.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_vendor_5_cta' ) : __( 'Open Help', 'lnkup-marketplace' ),
				'cta_url'   => $help,
			),
		);
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private static function organizer_slides(): array {
		$dash = lnkup_page_url_helper( 'organizer-dashboard' );

		return array(
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_org_1_title' ) : __( 'Launch your event', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_org_1_body' ) : __( 'Create an event and publish vendor opportunities so vendors can apply.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_org_1_cta' ) : __( 'Launch event', 'lnkup-marketplace' ),
				'cta_url'   => $dash . '#create',
			),
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_org_2_title' ) : __( 'Set fees and spots', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_org_2_body' ) : __( 'Edit booth fees and capacity from Your Events so vendors know what they are applying for.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_org_2_cta' ) : __( 'Open events', 'lnkup-marketplace' ),
				'cta_url'   => $dash . '#events',
			),
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_org_3_title' ) : __( 'Review vendor requests', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_org_3_body' ) : __( 'Approve, waitlist, or reject applications from Vendor Requests.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_org_3_cta' ) : __( 'Review requests', 'lnkup-marketplace' ),
				'cta_url'   => $dash . '#applications',
			),
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_org_4_title' ) : __( 'Discover and invite', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_org_4_body' ) : __( 'Search the vendor network, save favorites, and send invites from Discover.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_org_4_cta' ) : __( 'Discover vendors', 'lnkup-marketplace' ),
				'cta_url'   => $dash . '#vendors',
			),
			array(
				'title'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_org_5_title' ) : __( 'Money and Help', 'lnkup-marketplace' ),
				'body'      => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_org_5_body' ) : __( 'Track booth fees in Financials. Use Help for the Knowledge Base and to replay this tour.', 'lnkup-marketplace' ),
				'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'tour_org_5_cta' ) : __( 'Open Help', 'lnkup-marketplace' ),
				'cta_url'   => $dash . '#help',
			),
		);
	}
}
