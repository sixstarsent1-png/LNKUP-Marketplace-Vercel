<?php
/**
 * Vendor dashboard view renderers.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Dashboard_Views {

	/**
	 * Render stat card.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $trend Trend line.
	 * @param string $icon  Icon key.
	 * @param bool   $is_zero Whether value is zero for muted styling.
	 * @param string $trend_dir Trend direction: up|down|neutral.
	 * @param string $href    Optional link URL (wraps card as anchor).
	 */
	public static function stat_card( $label, $value, $trend = '', $icon = 'apps', $is_zero = false, $trend_dir = 'neutral', $href = '', $accent = '', $animate = '', $count_currency = false ) {
		return lnkup_kpi_card(
			array(
				'label'          => $label,
				'value'          => $value,
				'trend'          => $trend,
				'icon'           => $icon,
				'is_zero'        => $is_zero,
				'trend_dir'      => $trend_dir,
				'href'           => $href,
				'accent'         => $accent,
				'animate'        => $animate,
				'count_currency' => $count_currency,
			)
		);
	}

	/**
	 * Render organizer overview action card with icon tile.
	 *
	 * @param string $href     Link URL.
	 * @param string $title    Card title.
	 * @param string $subtitle Subtitle text.
	 * @param string $icon     Icon key (inbox|users|payment|search|docs|chart).
	 */
	public static function organizer_action_card( $href, $title, $subtitle, $icon = 'inbox' ) {
		$icons = array(
			'inbox'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-6l-2 3H10l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>',
			'users'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
			'payment'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/></svg>',
			'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
			'search'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
			'docs'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 18v-6M9 15h6"/></svg>',
			'chart'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>',
		);
		$icon_html = function_exists( 'lnkup_inline_icon' )
			? lnkup_inline_icon( $icons[ $icon ] ?? $icons['inbox'], 18 )
			: ( $icons[ $icon ] ?? $icons['inbox'] );
		ob_start();
		?>
		<a href="<?php echo esc_url( $href ); ?>" class="lnkup-organizer-action-card">
			<span class="lnkup-organizer-action-card__icon" style="display:inline-flex;width:1.75rem;height:1.75rem;flex-shrink:0;overflow:hidden;align-items:center;justify-content:center;" aria-hidden="true"><?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<strong><?php echo esc_html( $title ); ?></strong>
			<span><?php echo esc_html( $subtitle ); ?></span>
		</a>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render vendor home action card with icon tile.
	 *
	 * @param string $href     Link URL.
	 * @param string $title    Card title.
	 * @param string $subtitle Subtitle text.
	 * @param string $icon     Icon key.
	 */
	public static function action_card( $href, $title, $subtitle, $icon = 'apps' ) {
		$icons = array(
			'search'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
			'store'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9 12 2l9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>',
			'docs'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 18v-6M9 15h6"/></svg>',
			'payment'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/></svg>',
			'chart'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>',
			'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
			'help'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>',
		);
		$icon_html = function_exists( 'lnkup_inline_icon' )
			? lnkup_inline_icon( $icons[ $icon ] ?? $icons['search'], 18 )
			: ( $icons[ $icon ] ?? $icons['search'] );
		ob_start();
		?>
		<a href="<?php echo esc_url( $href ); ?>" class="lnkup-action-tile lnkup-vendor-action-card lnkup-micro-lift">
			<span class="lnkup-action-tile__icon" aria-hidden="true"><?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<strong class="lnkup-action-tile__title"><?php echo esc_html( $title ); ?></strong>
			<span class="lnkup-action-tile__desc"><?php echo esc_html( $subtitle ); ?></span>
		</a>
		<?php
		return ob_get_clean();
	}

	/**
	 * Vendor getting-started checklist + optional welcome modal.
	 *
	 * @param array<string, mixed> $ctx Vendor dashboard context.
	 * @return string
	 */
	public static function render_getting_started( $ctx ) {
		$user_id = (int) ( $ctx['user_id'] ?? get_current_user_id() );
		$checklist = class_exists( 'LNKup_Vendor_Getting_Started' )
			? LNKup_Vendor_Getting_Started::get_checklist( $user_id, is_array( $ctx ) ? $ctx : array() )
			: array();
		$show      = ! empty( $checklist['show'] );
		$welcome   = ! empty( $checklist['show_welcome'] );
		$tour_auto = class_exists( 'LNKup_Dashboard_Tour' ) && LNKup_Dashboard_Tour::should_show( $user_id, 'vendor' );

		if ( ! $show && ! $welcome && ! class_exists( 'LNKup_Dashboard_Tour' ) ) {
			return '';
		}

		$completed = (int) ( $checklist['completed'] ?? 0 );
		$total     = (int) ( $checklist['total'] ?? 0 );
		$percent   = (int) ( $checklist['percent'] ?? 0 );
		$items     = isset( $checklist['items'] ) && is_array( $checklist['items'] ) ? $checklist['items'] : array();
		$progress  = function_exists( 'lnkup_copy' ) ? sprintf( lnkup_copy( 'vendor_tutorial_progress' ), $completed, $total ) : '';
		$next_key  = '';
		foreach ( $items as $scan_item ) {
			if ( empty( $scan_item['done'] ) ) {
				$next_key = isset( $scan_item['key'] ) ? (string) $scan_item['key'] : '';
				break;
			}
		}

		ob_start();
		if ( $show ) :
			?>
			<div class="lnkup-launch-checklist lnkup-vendor-getting-started" id="lnkup-vendor-getting-started" data-testid="lnkup-vendor-home-tutorial" data-all-complete="0">
				<div class="lnkup-launch-checklist__body">
					<header class="lnkup-launch-checklist__header">
						<div class="lnkup-launch-checklist__head">
							<h2 class="lnkup-launch-checklist__title"><?php echo esc_html( lnkup_copy( 'vendor_tutorial_title' ) ); ?></h2>
							<p class="lnkup-launch-checklist__progress lnkup-muted"><?php echo esc_html( $progress ); ?></p>
						</div>
						<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-vendor-tutorial-dismiss>
							<?php echo esc_html( lnkup_copy( 'vendor_tutorial_dismiss' ) ); ?>
						</button>
						<div class="lnkup-launch-checklist__progress-bar" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php echo esc_attr( $progress ); ?>">
							<span class="lnkup-launch-checklist__progress-fill" style="width:<?php echo esc_attr( (string) $percent ); ?>%"></span>
						</div>
					</header>
					<div class="lnkup-launch-checklist__grid">
						<?php foreach ( $items as $item ) : ?>
							<?php
							$key          = isset( $item['key'] ) ? (string) $item['key'] : '';
							$is_complete  = ! empty( $item['done'] );
							$is_current   = ! $is_complete && $key && $key === $next_key;
							$cta_url      = isset( $item['cta_url'] ) ? (string) $item['cta_url'] : '#';
							$cta_label    = isset( $item['cta_label'] ) ? (string) $item['cta_label'] : '';
							$status       = $is_complete ? 'complete' : 'not_started';
							$card_classes = array(
								'lnkup-launch-checklist-card',
								'lnkup-launch-checklist-card--' . str_replace( '_', '-', $status ),
								$is_complete ? 'is-done' : ( $is_current ? 'is-current' : 'is-pending' ),
							);
							?>
							<article class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>" data-vendor-tutorial-step="<?php echo esc_attr( $key ); ?>">
								<span class="lnkup-launch-checklist-card__pill"><?php echo esc_html( $is_complete ? lnkup_copy( 'launch_checklist_status_complete' ) : lnkup_copy( 'launch_checklist_status_not_started' ) ); ?></span>
								<h3 class="lnkup-launch-checklist-card__title"><?php echo esc_html( $item['title'] ?? '' ); ?></h3>
								<p class="lnkup-launch-checklist-card__desc lnkup-muted"><?php echo esc_html( $item['description'] ?? '' ); ?></p>
								<?php if ( $cta_label ) : ?>
									<a href="<?php echo esc_url( $cta_url ); ?>" class="lnkup-btn lnkup-btn--sm <?php echo $is_complete ? 'lnkup-btn--ghost' : ( $is_current ? 'lnkup-btn--primary' : 'lnkup-btn--outline' ); ?> lnkup-launch-checklist-card__cta"><?php echo esc_html( $cta_label ); ?></a>
								<?php endif; ?>
							</article>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
			<?php
		endif;

		if ( $welcome ) :
			?>
			<div class="lnkup-modal lnkup-modal--vendor-welcome" id="lnkup-vendor-welcome-modal" data-testid="lnkup-vendor-welcome-modal" hidden>
				<div class="lnkup-modal__backdrop" data-vendor-welcome-close="1"></div>
				<div class="lnkup-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="lnkup-vendor-welcome-title">
					<button type="button" class="lnkup-modal__close" data-vendor-welcome-close="1" aria-label="<?php esc_attr_e( 'Close', 'lnkup-marketplace' ); ?>">&times;</button>
					<h2 id="lnkup-vendor-welcome-title" class="lnkup-type-title"><?php echo esc_html( lnkup_copy( 'vendor_tutorial_welcome_title' ) ); ?></h2>
					<p class="lnkup-muted"><?php echo esc_html( lnkup_copy( 'vendor_tutorial_welcome_body' ) ); ?></p>
					<div class="lnkup-modal__actions" style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-top:1.25rem;">
						<button type="button" class="lnkup-btn lnkup-btn--primary" data-vendor-welcome-start><?php echo esc_html( lnkup_copy( 'vendor_tutorial_welcome_start' ) ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--ghost" data-vendor-welcome-skip><?php echo esc_html( lnkup_copy( 'vendor_tutorial_welcome_skip' ) ); ?></button>
					</div>
				</div>
			</div>
			<?php
		endif;

		if ( class_exists( 'LNKup_Dashboard_Tour' ) ) {
			echo LNKup_Dashboard_Tour::render_modal( 'vendor', $tour_auto ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		return ob_get_clean();
	}

	/**
	 * Organizer's open question and the vendor reply form.
	 *
	 * @param int $app_id Application ID.
	 * @return string
	 */
	private static function render_info_request_block( $app_id ) {
		$request = LNKup_Applications::open_info_request( $app_id );
		if ( ! $request ) {
			return '';
		}
		$docs     = array_filter( (array) ( $request['documents'] ?? array() ) );
		$field_id = 'lnkup-info-reply-' . $app_id;
		ob_start();
		?>
		<div class="lnkup-info-request" data-testid="lnkup-vendor-info-request">
			<p class="lnkup-info-request__eyebrow"><?php esc_html_e( 'The organizer needs more information', 'lnkup-marketplace' ); ?></p>
			<p class="lnkup-info-request__message"><?php echo esc_html( (string) $request['message'] ); ?></p>
			<?php if ( $docs ) : ?>
				<p class="lnkup-request-row__meta">
					<?php
					/* translators: %s: document types */
					echo esc_html( sprintf( __( 'Documents requested: %s', 'lnkup-marketplace' ), implode( ', ', $docs ) ) );
					?>
					<a href="<?php echo esc_url( add_query_arg( 'view', 'documents', lnkup_page_url_helper( 'vendor-dashboard' ) ) ); ?>"><?php esc_html_e( 'Upload', 'lnkup-marketplace' ); ?></a>
				</p>
			<?php endif; ?>
			<form class="lnkup-info-request__form" data-info-respond data-endpoint="<?php echo esc_url( rest_url( 'lnkup/v1/applications/' . $app_id . '/respond-info' ) ); ?>">
				<label for="<?php echo esc_attr( $field_id ); ?>"><?php esc_html_e( 'Your reply', 'lnkup-marketplace' ); ?></label>
				<textarea id="<?php echo esc_attr( $field_id ); ?>" name="message" rows="3" required minlength="2" maxlength="4000" data-testid="lnkup-vendor-info-reply"></textarea>
				<p class="lnkup-info-request__status" role="status" aria-live="polite" hidden></p>
				<button type="submit" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" data-testid="lnkup-vendor-info-send"><?php esc_html_e( 'Send reply', 'lnkup-marketplace' ); ?></button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Application workflow card.
	 *
	 * @param WP_Post $app       Application post.
	 * @param string  $dashboard Dashboard base URL.
	 */
	public static function render_application_workflow_card( $app, $dashboard ) {
		unset( $dashboard );
		$meta     = LNKup_Vendor_Dashboard_Data::application_event_meta( $app );
		$status   = (string) get_post_meta( $app->ID, '_lnkup_application_status', true );
		$bucket   = LNKup_Vendor_Dashboard_Data::application_request_bucket( $app );
		$payment  = LNKup_Vendor_Dashboard_Data::application_payment_for_vendor( $app );
		$pay_due  = LNKup_Vendor_Dashboard_Data::application_is_payable( $app );
		$opp_id   = (int) get_post_meta( $app->ID, '_lnkup_opportunity_id', true );
		$slot     = $opp_id ? get_the_title( $opp_id ) : '';
		$event_id = (int) ( $meta['event_id'] ?? 0 );
		$event_url = ( $event_id && class_exists( 'LNKup_Organizer_Onboarding' ) ) ? LNKup_Organizer_Onboarding::event_public_url( $event_id ) : '';
		$initials = '';
		foreach ( preg_split( '/\s+/', trim( (string) $meta['event_title'] ) ) as $part ) {
			$initials .= strtoupper( substr( $part, 0, 1 ) );
			if ( strlen( $initials ) >= 2 ) {
				break;
			}
		}
		$filter = 'applied' === $bucket ? 'other' : $bucket;
		$pass_url = add_query_arg(
			array(
				'view' => 'operations',
				'app'  => (int) $app->ID,
				'tab'  => 'pass',
			),
			lnkup_page_url_helper( 'vendor-dashboard' )
		);
		$docs_url  = add_query_arg( 'view', 'documents', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$doc_code  = class_exists( 'LNKup_Verification' ) ? (string) ( LNKup_Verification::get_application_document_context( (int) $app->ID )['code'] ?? '' ) : '';
		$docs_due  = in_array( $doc_code, array( 'missing', 'pending' ), true ) && ! in_array( $status, array( 'rejected', 'cancelled', 'completed' ), true );
		$when      = self::application_when_label( $meta );
		$where     = implode( ', ', array_filter( array( (string) $meta['venue'], trim( $meta['city'] . ( $meta['state'] ? ', ' . $meta['state'] : '' ) ) ) ) );
		$is_closed = in_array( $status, array( 'rejected', 'cancelled' ), true );
		$is_free   = (float) $meta['fee'] <= 0 && ( ! $payment || (float) get_post_meta( $payment->ID, '_lnkup_total_amount', true ) <= 0 );
		$steps     = $is_closed ? array() : LNKup_Vendor_Dashboard_Data::application_workflow_steps(
			$status,
			array(
				'is_free'    => $is_free,
				'event_date' => (string) $meta['date'],
			)
		);
		$note      = '';
		if ( 'waitlisted' === $status ) {
			$note = __( 'You are on the waitlist. We will let you know if a spot opens.', 'lnkup-marketplace' );
		} elseif ( 'rejected' === $status ) {
			$note = __( 'Not selected this time. Keep applying to similar events.', 'lnkup-marketplace' );
		} elseif ( 'cancelled' === $status ) {
			$note = __( 'This request was cancelled.', 'lnkup-marketplace' );
		}
		ob_start();
		?>
		<article class="lnkup-workflow-card lnkup-request-row" data-app-tab="<?php echo esc_attr( $filter ); ?>" data-app-filter="<?php echo esc_attr( $filter ); ?>" data-testid="lnkup-vendor-request-card">
			<div class="lnkup-request-row__main">
				<span class="lnkup-request-row__initials" aria-hidden="true"><?php echo esc_html( $initials ?: 'E' ); ?></span>
				<div class="lnkup-request-row__copy">
					<?php if ( 'booked' === $bucket ) : ?>
						<a class="lnkup-request-row__title" href="<?php echo esc_url( $pass_url ); ?>"><?php echo esc_html( $meta['event_title'] ); ?></a>
					<?php elseif ( $event_url ) : ?>
						<a class="lnkup-request-row__title" href="<?php echo esc_url( $event_url ); ?>"><?php echo esc_html( $meta['event_title'] ); ?></a>
					<?php else : ?>
						<p class="lnkup-request-row__title"><?php echo esc_html( $meta['event_title'] ); ?></p>
					<?php endif; ?>
					<?php if ( $when || $where ) : ?>
						<p class="lnkup-request-row__meta" data-testid="lnkup-vendor-request-when"><?php echo esc_html( implode( ' · ', array_filter( array( $when, $where ) ) ) ); ?></p>
					<?php endif; ?>
					<?php if ( $slot ) : ?>
						<p class="lnkup-request-row__meta"><?php echo esc_html( $slot ); ?></p>
					<?php endif; ?>
					<?php if ( $steps ) : ?>
						<ol class="lnkup-request-steps" aria-label="<?php esc_attr_e( 'Request progress', 'lnkup-marketplace' ); ?>">
							<?php foreach ( $steps as $step ) : ?>
								<li class="lnkup-request-steps__item is-<?php echo esc_attr( $step['state'] ); ?>"<?php echo 'current' === $step['state'] ? ' aria-current="step"' : ''; ?>>
									<?php echo esc_html( $step['label'] ); ?>
									<?php if ( 'done' === $step['state'] ) : ?>
										<span class="screen-reader-text"><?php esc_html_e( '(done)', 'lnkup-marketplace' ); ?></span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php elseif ( $note ) : ?>
						<p class="lnkup-request-row__meta lnkup-request-row__note"><?php echo esc_html( $note ); ?></p>
					<?php endif; ?>
					<?php if ( $steps && $note ) : ?>
						<p class="lnkup-request-row__meta lnkup-request-row__note"><?php echo esc_html( $note ); ?></p>
					<?php endif; ?>
					<?php echo self::render_info_request_block( (int) $app->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>
			<span class="lnkup-request-row__fee"><?php echo esc_html( lnkup_currency( $meta['fee'] ) ); ?></span>
			<span class="lnkup-status lnkup-status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( LNKup_Vendor_Dashboard_Data::application_status_label( $status ) ); ?></span>
			<div class="lnkup-request-row__actions">
				<?php if ( $pay_due ) : ?>
					<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm lnkup-pay-btn" data-payment-id="<?php echo esc_attr( (string) $payment->ID ); ?>"><?php esc_html_e( 'Pay now', 'lnkup-marketplace' ); ?></button>
				<?php endif; ?>
				<?php if ( 'booked' === $bucket ) : ?>
					<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="<?php echo esc_url( $pass_url ); ?>"><?php esc_html_e( 'Open pass', 'lnkup-marketplace' ); ?></a>
				<?php endif; ?>
				<?php if ( $docs_due ) : ?>
					<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="<?php echo esc_url( $docs_url ); ?>"><?php esc_html_e( 'Documents', 'lnkup-marketplace' ); ?></a>
				<?php endif; ?>
				<?php if ( $event_url ) : ?>
					<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( $event_url ); ?>"><?php esc_html_e( 'View event', 'lnkup-marketplace' ); ?></a>
				<?php endif; ?>
				<?php if ( in_array( $status, LNKup_Applications::WITHDRAWABLE, true ) && ! ( $payment && in_array( (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true ), array( 'processing', 'paid', 'partially_paid' ), true ) ) ) : ?>
					<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-withdraw-application data-endpoint="<?php echo esc_url( rest_url( 'lnkup/v1/applications/' . (int) $app->ID . '/withdraw' ) ); ?>" data-testid="lnkup-vendor-withdraw"><?php esc_html_e( 'Withdraw', 'lnkup-marketplace' ); ?></button>
				<?php endif; ?>
			</div>
			<p class="lnkup-request-row__meta" role="status" aria-live="polite" data-withdraw-status hidden></p>
		</article>
		<?php
		return ob_get_clean();
	}

	/**
	 * "Continue your application" cards for open server-side drafts.
	 *
	 * @param int $user_id Vendor.
	 * @return string
	 */
	public static function render_draft_resume_cards( $user_id ) {
		if ( ! $user_id || ! class_exists( 'LNKup_Application_Drafts' ) ) {
			return '';
		}
		$drafts = LNKup_Application_Drafts::list_for_vendor( $user_id );
		if ( ! $drafts ) {
			return '';
		}
		ob_start();
		?>
		<section class="lnkup-panel lnkup-draft-resume" aria-labelledby="lnkup-draft-resume-title" data-testid="lnkup-draft-resume">
			<h2 class="lnkup-draft-resume__title" id="lnkup-draft-resume-title"><?php esc_html_e( 'Continue your application', 'lnkup-marketplace' ); ?></h2>
			<ul class="lnkup-draft-resume__list">
				<?php foreach ( $drafts as $draft ) : ?>
					<?php
					$date_ts     = $draft['event_date'] ? strtotime( $draft['event_date'] ) : false;
					$deadline_ts = $draft['deadline'] ? strtotime( $draft['deadline'] ) : false;
					$updated_ts  = $draft['updated_at'] ? strtotime( $draft['updated_at'] ) : false;
					?>
					<li class="lnkup-draft-resume__item" data-testid="lnkup-draft-resume-item">
						<div class="lnkup-draft-resume__copy">
							<p class="lnkup-request-row__title"><?php echo esc_html( $draft['event_name'] ?: $draft['opportunity_title'] ); ?></p>
							<p class="lnkup-request-row__meta">
								<?php
								echo esc_html(
									implode(
										' · ',
										array_filter(
											array(
												$date_ts ? date_i18n( 'D, M j, Y', $date_ts ) : '',
												$draft['location_label'],
												$draft['opportunity_title'],
											)
										)
									)
								);
								?>
							</p>
							<div class="lnkup-draft-resume__progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $draft['progress_percent'] ); ?>" aria-label="<?php esc_attr_e( 'Application progress', 'lnkup-marketplace' ); ?>">
								<span style="width:<?php echo esc_attr( (string) max( 5, (int) $draft['progress_percent'] ) ); ?>%"></span>
							</div>
							<p class="lnkup-request-row__meta">
								<?php if ( $updated_ts ) : ?>
									<?php /* translators: %s: relative time */ echo esc_html( sprintf( __( 'Saved %s ago', 'lnkup-marketplace' ), human_time_diff( $updated_ts, current_time( 'timestamp' ) ) ) ); ?>
								<?php endif; ?>
								<?php if ( $deadline_ts ) : ?>
									· <?php /* translators: %s: date */ echo esc_html( sprintf( __( 'Apply by %s', 'lnkup-marketplace' ), date_i18n( 'M j', $deadline_ts ) ) ); ?>
								<?php endif; ?>
							</p>
							<?php if ( ! $draft['can_submit'] ) : ?>
								<p class="lnkup-request-row__meta lnkup-request-row__note"><?php esc_html_e( 'This booth is no longer accepting applications.', 'lnkup-marketplace' ); ?></p>
							<?php elseif ( $draft['price']['changed'] ) : ?>
								<p class="lnkup-request-row__meta lnkup-request-row__note"><?php /* translators: %s: price */ echo esc_html( sprintf( __( 'The booth price changed to %s.', 'lnkup-marketplace' ), lnkup_currency( $draft['price']['current'] ) ) ); ?></p>
							<?php endif; ?>
						</div>
						<?php if ( $draft['can_submit'] ) : ?>
							<a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="<?php echo esc_url( $draft['resume_url'] ); ?>" data-testid="lnkup-draft-continue"><?php esc_html_e( 'Continue', 'lnkup-marketplace' ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Event date and start time for a request card, e.g. "Sat, Jun 7 · 10:00 am".
	 *
	 * @param array $meta Output of application_event_meta().
	 * @return string
	 */
	private static function application_when_label( $meta ) {
		$date = (string) ( $meta['date'] ?? '' );
		$ts   = $date ? strtotime( $date ) : false;
		if ( ! $ts ) {
			return '';
		}
		$label = date_i18n( 'D, M j, Y', $ts );
		$start = (string) ( $meta['start_time'] ?? '' );
		$start_ts = $start ? strtotime( $date . ' ' . $start ) : false;
		if ( $start_ts ) {
			$label .= ' · ' . date_i18n( get_option( 'time_format' ), $start_ts );
		}
		return $label;
	}

	/**
	 * Profile strength card for vendor home.
	 *
	 * @param int $user_id    Vendor user ID.
	 * @param int $profile_id Vendor profile post ID.
	 */
	public static function render_profile_strength_card( $user_id, $profile_id ) {
		unset( $user_id );
		$profile_url = lnkup_page_url_helper( 'vendor-profile' );
		$find_url    = lnkup_page_url_helper( 'opportunities' );
		$strength    = class_exists( 'LNKup_Growth_Profile_Strength' )
			? LNKup_Growth_Profile_Strength::calculate( (int) $profile_id )
			: array( 'score' => 0, 'tier' => '', 'recommended_actions' => array() );
		$score       = (int) ( $strength['score'] ?? 0 );
		$tier        = (string) ( $strength['tier'] ?? '' );
		$slug        = $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_public_slug', true ) : '';
		$preview_url = $slug ? add_query_arg( 'vendor', $slug, lnkup_page_url_helper( 'vendors' ) ) : '';
		$actions     = array_slice( (array) ( $strength['recommended_actions'] ?? array() ), 0, 3 );

		ob_start();
		?>
		<div class="lnkup-panel lnkup-vendor-strength-card" id="lnkup-vendor-strength-card" data-testid="lnkup-vendor-strength-card">
			<?php if ( ! $profile_id ) : ?>
				<h2 class="lnkup-panel__title"><?php echo esc_html( lnkup_copy( 'vendor_profile_empty_title' ) ); ?></h2>
				<p class="lnkup-muted"><?php echo esc_html( lnkup_copy( 'vendor_profile_empty_copy' ) ); ?></p>
				<a href="<?php echo esc_url( $profile_url ); ?>" class="lnkup-btn lnkup-btn--primary"><?php echo esc_html( lnkup_copy( 'vendor_profile_start_cta' ) ); ?></a>
			<?php elseif ( $score >= 90 ) : ?>
				<h2 class="lnkup-panel__title"><?php echo esc_html( lnkup_copy( 'vendor_profile_booking_ready_title' ) ); ?></h2>
				<p class="lnkup-muted"><?php echo esc_html( lnkup_copy( 'vendor_profile_booking_ready_copy' ) ); ?></p>
				<p class="lnkup-vendor-strength-card__progress"><?php echo esc_html( sprintf( lnkup_copy( 'vendor_profile_progress' ), $score, $tier ) ); ?></p>
				<div class="lnkup-vendor-strength-card__bar" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $score ); ?>" aria-valuemin="0" aria-valuemax="100">
					<span class="lnkup-vendor-strength-card__fill" style="width:<?php echo esc_attr( (string) $score ); ?>%"></span>
				</div>
				<div class="lnkup-vendor-strength-card__actions">
					<a href="<?php echo esc_url( $find_url ); ?>" class="lnkup-btn lnkup-btn--primary"><?php echo esc_html( lnkup_copy( 'vendor_profile_find_events' ) ); ?></a>
					<?php if ( $preview_url ) : ?>
						<a href="<?php echo esc_url( $preview_url ); ?>" class="lnkup-btn lnkup-btn--outline"><?php echo esc_html( lnkup_copy( 'vendor_profile_preview_cta' ) ); ?></a>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<h2 class="lnkup-panel__title"><?php echo esc_html( lnkup_copy( 'vendor_profile_title' ) ); ?></h2>
				<p class="lnkup-muted"><?php echo esc_html( lnkup_copy( 'vendor_profile_subtitle' ) ); ?></p>
				<p class="lnkup-vendor-strength-card__progress"><?php echo esc_html( sprintf( lnkup_copy( 'vendor_profile_progress' ), $score, $tier ) ); ?></p>
				<div class="lnkup-vendor-strength-card__bar" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $score ); ?>" aria-valuemin="0" aria-valuemax="100">
					<span class="lnkup-vendor-strength-card__fill" style="width:<?php echo esc_attr( (string) $score ); ?>%"></span>
				</div>
				<?php if ( $actions ) : ?>
					<ul class="lnkup-vendor-strength-card__missing">
						<?php foreach ( $actions as $action ) : ?>
							<li><?php echo esc_html( $action['label'] ?? '' ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<div class="lnkup-vendor-strength-card__actions">
					<a href="<?php echo esc_url( $profile_url ); ?>" class="lnkup-btn lnkup-btn--primary"><?php echo esc_html( lnkup_copy( 'vendor_profile_complete_cta' ) ); ?></a>
					<?php if ( $preview_url ) : ?>
						<a href="<?php echo esc_url( $preview_url ); ?>" class="lnkup-btn lnkup-btn--ghost"><?php echo esc_html( lnkup_copy( 'vendor_profile_preview_cta' ) ); ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Trust / verification status card shell (hydrated by verification.js).
	 *
	 * @param int $profile_id Vendor profile post ID.
	 */
	public static function render_trust_status_card( $profile_id ) {
		$profile_id = absint( $profile_id );
		$docs_url   = add_query_arg( 'view', 'documents', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$profile_url = lnkup_page_url_helper( 'vendor-profile' );

		ob_start();
		?>
		<div class="lnkup-panel lnkup-trust-card lnkup-surface--secondary" id="lnkup-vendor-trust-card" data-testid="lnkup-vendor-trust-card" data-profile-id="<?php echo esc_attr( (string) $profile_id ); ?>">
			<div class="lnkup-trust-card__loading">
				<div class="lnkup-skeleton lnkup-skeleton--title"></div>
				<div class="lnkup-skeleton lnkup-skeleton--line"></div>
				<div class="lnkup-skeleton lnkup-skeleton--line lnkup-skeleton--short"></div>
			</div>
			<div class="lnkup-trust-card__content" hidden>
				<h2 class="lnkup-type-h2"><?php esc_html_e( 'Vendor level', 'lnkup-marketplace' ); ?></h2>
				<p class="lnkup-trust-card__status lnkup-type-display" data-trust-status></p>
				<p class="lnkup-type-meta lnkup-trust-card__desc"><?php esc_html_e( 'Unlock badges and benefits as you complete events and verification.', 'lnkup-marketplace' ); ?></p>
				<div class="lnkup-trust-card__level-wrap">
					<div class="lnkup-trust-card__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100">
						<span class="lnkup-trust-card__fill" data-trust-percent-bar style="width:0%"></span>
					</div>
					<p class="lnkup-type-caption" data-trust-next-milestone></p>
				</div>
				<div class="lnkup-trust-card__badges" data-trust-badges></div>
				<ul class="lnkup-trust-card__missing" data-trust-missing></ul>
				<p class="lnkup-type-meta lnkup-trust-card__docs-note" data-trust-docs-note hidden></p>
				<div class="lnkup-trust-card__actions">
					<a href="<?php echo esc_url( $profile_url ); ?>" class="lnkup-btn lnkup-btn--primary" data-trust-cta><?php esc_html_e( 'Finish verification', 'lnkup-marketplace' ); ?></a>
					<a href="<?php echo esc_url( $docs_url ); ?>" class="lnkup-btn lnkup-btn--ghost"><?php esc_html_e( 'Documents', 'lnkup-marketplace' ); ?></a>
				</div>
			</div>
			<div class="lnkup-trust-card__error" hidden>
				<p class="lnkup-muted"><?php esc_html_e( 'Verification unavailable', 'lnkup-marketplace' ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Vendor dashboard home view.
	 */
	public static function render_home() {
		LNKup_Capabilities::require_login();
		$ctx           = LNKup_Vendor_Dashboard_Data::context();
		$user_id       = $ctx['user_id'];
		$applications  = $ctx['applications'];
		$payments      = $ctx['payments'];
		$profile_id    = $ctx['profile_id'];
		$compliance    = $ctx['compliance'];
		$display_name  = $ctx['business_name'] ? $ctx['business_name'] : wp_get_current_user()->display_name;
		$subtitle      = $ctx['city'] ? trim( $ctx['city'] . ( $ctx['state'] ? ', ' . $ctx['state'] : '' ) ) : __( 'Complete your profile to unlock personalized recommendations.', 'lnkup-marketplace' );
		$active_count  = LNKup_Vendor_Dashboard_Data::count_active_applications( $applications );
		$bookings      = LNKup_Vendor_Dashboard_Data::count_confirmed_bookings( $applications );
		$earned        = LNKup_Vendor_Dashboard_Data::earned_this_season( $payments );
		$attention     = LNKup_Vendor_Dashboard_Data::attention_applications( $applications );
		$recommended   = lnkup_marketplace_phase_at_least( '1b' ) ? LNKup_Recommendations::get_for_user( $user_id, 3 ) : array();
		$apps_url      = add_query_arg( 'view', 'applications', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$docs_url      = add_query_arg( 'view', 'documents', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$doc_value     = $compliance['attention_count'] > 0
			? (string) $compliance['attention_count']
			: (string) $compliance['verified'];
		$doc_trend     = $compliance['attention_count'] > 0
			? __( 'need attention', 'lnkup-marketplace' )
			: sprintf( __( 'of %d verified', 'lnkup-marketplace' ), $compliance['total'] );
		$reviews_url   = add_query_arg( 'view', 'reviews', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$week          = LNKup_Vendor_Dashboard_Data::week_snapshot( $ctx );
		$ops_url       = add_query_arg( 'view', 'operations', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$payments_url  = add_query_arg( 'view', 'payments', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$growth_url    = add_query_arg( 'view', 'growth', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$help_url      = add_query_arg( 'view', 'help', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$profile_url   = lnkup_page_url_helper( 'vendor-profile' );
		$find_url      = lnkup_page_url_helper( 'opportunities' );
		$priorities    = LNKup_Vendor_Dashboard_Data::today_priorities( $ctx );
		$rep           = ( $profile_id && class_exists( 'LNKup_Vendor_Reputation' ) ) ? LNKup_Vendor_Reputation::get( $profile_id ) : array();
		$market_open   = lnkup_can_browse_marketplace();

		ob_start();
		?>
		<div class="lnkup-mp lnkup-vendor-dash">
			<?php echo self::render_payment_return_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php self::render_vendor_notices( $user_id ); ?>

			<?php echo self::render_home_welcome( $ctx, $display_name, $subtitle, $market_open ? $find_url : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php
			$getting_started = self::render_getting_started( $ctx );
			echo $getting_started; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<?php if ( ! $applications ) : ?>
				<?php
				echo lnkup_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					array(
						'title'     => __( 'No applications yet', 'lnkup-marketplace' ),
						'desc'      => __( 'Find an event that fits your business and apply for free. You only pay when an organizer accepts you.', 'lnkup-marketplace' ),
						'cta_label' => __( 'Find an event', 'lnkup-marketplace' ),
						'cta_href'  => $find_url,
					)
				);
				?>
			<?php else : ?>
				<?php
				echo self::render_next_action( $priorities ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$bucket_counts = LNKup_Vendor_Dashboard_Data::request_bucket_counts( $applications, $user_id );
				$favorites     = class_exists( 'LNKup_Vendor_Personalization' ) ? LNKup_Vendor_Personalization::get_favorites( $user_id ) : array();
				$saved_count   = count( (array) ( $favorites['events'] ?? array() ) ) + count( (array) ( $favorites['opportunities'] ?? array() ) );
				echo lnkup_stat_strip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					array(
						array(
							'key'   => 'active',
							'label' => __( 'In review', 'lnkup-marketplace' ),
							'value' => (string) $bucket_counts['in-review'],
							'href'  => add_query_arg( 'app_filter', 'in-review', $apps_url ),
						),
						array(
							'key'   => 'needs-action',
							'label' => __( 'Payments due', 'lnkup-marketplace' ),
							'value' => (string) $bucket_counts['pay-now'],
							'href'  => add_query_arg( 'app_filter', 'pay-now', $apps_url ),
						),
						array(
							'key'   => 'confirmed',
							'label' => __( 'Upcoming bookings', 'lnkup-marketplace' ),
							'value' => (string) $bucket_counts['booked'],
							'href'  => add_query_arg( 'app_filter', 'booked', $apps_url ),
						),
						array(
							'key'   => 'saved',
							'label' => __( 'Saved', 'lnkup-marketplace' ),
							'value' => (string) $saved_count,
							'href'  => lnkup_page_url_helper( 'favorites' ),
						),
					),
					array( 'testid' => 'lnkup-vendor-home-stats' )
				);
				echo self::render_next_event_strip( $week, $ops_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			<?php endif; ?>
			<?php if ( $market_open && $recommended && function_exists( 'lnkup_render_opportunity_card_v2' ) ) : ?>
				<section class="lnkup-vendor-section" data-testid="lnkup-vendor-recommended">
					<div class="lnkup-home-block__head">
						<h2 class="lnkup-panel__title"><?php esc_html_e( 'Recommended for you', 'lnkup-marketplace' ); ?></h2>
						<a href="<?php echo esc_url( $find_url ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php esc_html_e( 'Browse all', 'lnkup-marketplace' ); ?></a>
					</div>
					<div class="lnkup-event-grid">
						<?php foreach ( $recommended as $opp ) : ?>
							<?php
							if ( ! is_array( $opp ) ) {
								continue;
							}
							$opp['card_variant'] = 'compact';
							echo lnkup_render_opportunity_card_v2( $opp ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>
			<?php
			if ( $applications ) {
				echo self::render_recent_activity( $user_id, $applications, $apps_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( '' === trim( (string) $getting_started ) && (int) $week['profile_strength'] < 80 ) {
				echo self::render_profile_strength_card( $user_id, (int) $profile_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( $profile_id ) {
				echo self::render_trust_status_card( (int) $profile_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( class_exists( 'LNKup_App_Promo' ) ) {
				echo LNKup_App_Promo::render_card( 'vendor' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Compact personalized welcome: logo, business name, location, one primary action.
	 *
	 * @param array  $ctx          Dashboard context.
	 * @param string $display_name Business or display name.
	 * @param string $subtitle     Location or profile prompt.
	 * @param string $find_url     Find events URL ('' before launch).
	 */
	private static function render_home_welcome( $ctx, $display_name, $subtitle, $find_url ) {
		$profile_id = (int) ( $ctx['profile_id'] ?? 0 );
		$logo_id    = $profile_id ? (int) get_post_meta( $profile_id, '_lnkup_logo_attachment_id', true ) : 0;
		$logo_url   = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '';
		$initial    = strtoupper( substr( trim( (string) $display_name ), 0, 1 ) );
		ob_start();
		?>
		<header class="lnkup-page-header lnkup-home-welcome" data-testid="lnkup-vendor-home-welcome">
			<div class="lnkup-home-welcome__identity">
				<?php if ( $logo_url ) : ?>
					<img class="lnkup-home-welcome__logo" src="<?php echo esc_url( $logo_url ); ?>" alt="" width="48" height="48" decoding="async" />
				<?php else : ?>
					<span class="lnkup-home-welcome__logo lnkup-home-welcome__logo--initial" aria-hidden="true"><?php echo esc_html( $initial ?: 'L' ); ?></span>
				<?php endif; ?>
				<div class="lnkup-home-welcome__copy">
					<h1 class="lnkup-page-header__title lnkup-home-welcome__title">
						<?php
						/* translators: %s: vendor business name */
						echo esc_html( sprintf( __( 'Welcome back, %s', 'lnkup-marketplace' ), $display_name ) );
						?>
					</h1>
					<p class="lnkup-home-welcome__meta"><?php echo esc_html( $subtitle ); ?></p>
				</div>
			</div>
			<?php if ( $find_url ) : ?>
				<a class="lnkup-btn lnkup-btn--primary lnkup-home-welcome__cta" href="<?php echo esc_url( $find_url ); ?>"><?php esc_html_e( 'Find events', 'lnkup-marketplace' ); ?></a>
			<?php endif; ?>
		</header>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * One prominent next step from today's priorities, then any others in a compact list.
	 *
	 * @param array $priorities Output of LNKup_Vendor_Dashboard_Data::today_priorities().
	 */
	private static function render_next_action( $priorities ) {
		$priorities = array_values( (array) $priorities );
		$next       = $priorities[0] ?? null;
		$rest       = array_slice( $priorities, 1 );
		ob_start();
		?>
		<section class="lnkup-next-action" data-testid="lnkup-action-center" aria-labelledby="lnkup-next-action-title">
			<?php if ( $next ) : ?>
				<div class="lnkup-next-action__card lnkup-next-action__card--<?php echo esc_attr( (string) ( $next['level'] ?? 'info' ) ); ?>">
					<div class="lnkup-next-action__copy">
						<p class="lnkup-next-action__eyebrow"><?php esc_html_e( 'Next step', 'lnkup-marketplace' ); ?></p>
						<h2 class="lnkup-next-action__title" id="lnkup-next-action-title"><?php echo esc_html( (string) $next['title'] ); ?></h2>
						<?php if ( ! empty( $next['meta'] ) ) : ?>
							<p class="lnkup-next-action__meta"><?php echo esc_html( (string) $next['meta'] ); ?></p>
						<?php endif; ?>
					</div>
					<?php if ( ! empty( $next['payment_id'] ) && ! empty( $next['primary'] ) ) : ?>
						<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-pay-btn" data-payment-id="<?php echo esc_attr( (string) $next['payment_id'] ); ?>"><?php echo esc_html( (string) $next['action_label'] ); ?></button>
					<?php elseif ( ! empty( $next['action_href'] ) ) : ?>
						<a class="lnkup-btn lnkup-btn--primary" href="<?php echo esc_url( (string) $next['action_href'] ); ?>"><?php echo esc_html( (string) $next['action_label'] ); ?></a>
					<?php endif; ?>
				</div>
				<?php if ( $rest ) : ?>
					<ul class="lnkup-next-action__more" aria-label="<?php esc_attr_e( 'Also needs attention', 'lnkup-marketplace' ); ?>">
						<?php foreach ( $rest as $item ) : ?>
							<li class="lnkup-next-action__item">
								<span class="lnkup-priority-item__dot lnkup-priority-item__dot--<?php echo esc_attr( (string) ( $item['level'] ?? 'info' ) ); ?>" aria-hidden="true"></span>
								<span class="lnkup-next-action__item-title"><?php echo esc_html( (string) $item['title'] ); ?></span>
								<?php if ( ! empty( $item['payment_id'] ) && ! empty( $item['primary'] ) ) : ?>
									<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm lnkup-pay-btn" data-payment-id="<?php echo esc_attr( (string) $item['payment_id'] ); ?>"><?php echo esc_html( (string) $item['action_label'] ); ?></button>
								<?php elseif ( ! empty( $item['action_href'] ) ) : ?>
									<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( (string) $item['action_href'] ); ?>"><?php echo esc_html( (string) $item['action_label'] ); ?></a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php else : ?>
				<div class="lnkup-next-action__card lnkup-next-action__card--clear">
					<div class="lnkup-next-action__copy">
						<p class="lnkup-next-action__eyebrow"><?php esc_html_e( 'Next step', 'lnkup-marketplace' ); ?></p>
						<h2 class="lnkup-next-action__title" id="lnkup-next-action-title"><?php esc_html_e( 'You are all caught up', 'lnkup-marketplace' ); ?></h2>
						<p class="lnkup-next-action__meta"><?php esc_html_e( 'Nothing needs your attention right now. We will flag payments, documents, and event prep here.', 'lnkup-marketplace' ); ?></p>
					</div>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Chronological activity from the vendor inbox; falls back to the latest request cards.
	 *
	 * @param int       $user_id      Vendor user ID.
	 * @param WP_Post[] $applications Vendor applications.
	 * @param string    $apps_url     Requests URL.
	 */
	private static function render_recent_activity( $user_id, $applications, $apps_url ) {
		$items = array();
		if ( class_exists( 'LNKup_Inbox_Service' ) ) {
			$result = LNKup_Inbox_Service::list_items(
				$user_id,
				array(
					'role'     => 'vendor',
					'per_page' => 5,
				)
			);
			$items  = is_array( $result ) ? (array) ( $result['items'] ?? array() ) : array();
		}
		ob_start();
		?>
		<section class="lnkup-vendor-section" data-testid="lnkup-vendor-recent-activity">
			<div class="lnkup-home-block__head">
				<h2 class="lnkup-panel__title"><?php echo esc_html( $items ? __( 'Recent activity', 'lnkup-marketplace' ) : __( 'Recent bookings', 'lnkup-marketplace' ) ); ?></h2>
				<a href="<?php echo esc_url( $apps_url ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php esc_html_e( 'View all', 'lnkup-marketplace' ); ?></a>
			</div>
			<?php if ( $items ) : ?>
				<ol class="lnkup-activity-feed">
					<?php foreach ( $items as $item ) : ?>
						<?php
						$ts   = ! empty( $item['createdAt'] ) ? strtotime( (string) $item['createdAt'] ) : 0;
						$href = (string) ( $item['href'] ?? '' );
						$href = ( $href && wp_validate_redirect( $href, '' ) ) ? $href : '';
						?>
						<li class="lnkup-activity-feed__item<?php echo empty( $item['readAt'] ) ? ' is-unread' : ''; ?>">
							<span class="lnkup-priority-item__dot lnkup-priority-item__dot--<?php echo esc_attr( 'urgent' === ( $item['severity'] ?? '' ) ? 'urgent' : 'info' ); ?>" aria-hidden="true"></span>
							<div class="lnkup-activity-feed__body">
								<?php if ( $href ) : ?>
									<a class="lnkup-activity-feed__title" href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( (string) $item['title'] ); ?></a>
								<?php else : ?>
									<p class="lnkup-activity-feed__title"><?php echo esc_html( (string) $item['title'] ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $item['message'] ) ) : ?>
									<p class="lnkup-activity-feed__meta"><?php echo esc_html( wp_trim_words( (string) $item['message'], 18 ) ); ?></p>
								<?php endif; ?>
							</div>
							<?php if ( $ts ) : ?>
								<time class="lnkup-activity-feed__time" datetime="<?php echo esc_attr( gmdate( 'c', $ts ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: relative time */ __( '%s ago', 'lnkup-marketplace' ), human_time_diff( $ts ) ) ); ?></time>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php else : ?>
				<div class="lnkup-workflow-grid">
					<?php foreach ( array_slice( $applications, 0, 3 ) as $app ) : ?>
						<?php echo self::render_application_workflow_card( $app, lnkup_page_url_helper( 'vendor-dashboard' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Next booked event: date, readiness, and amount still due.
	 *
	 * @param array  $week    Output of week_snapshot().
	 * @param string $ops_url Vendor operations URL.
	 * @return string
	 */
	private static function render_next_event_strip( $week, $ops_url ) {
		if ( empty( $week['next_application_id'] ) || empty( $week['next_event_title'] ) ) {
			return '';
		}
		$ts       = ! empty( $week['next_event_date'] ) ? strtotime( (string) $week['next_event_date'] ) : false;
		$readiness = (int) $week['readiness_percent'];
		$url      = add_query_arg( 'app', (int) $week['next_application_id'], $ops_url );
		ob_start();
		?>
		<a class="lnkup-home-next lnkup-home-next--row lnkup-next-event-strip" href="<?php echo esc_url( $url ); ?>" data-testid="lnkup-vendor-next-event">
			<span class="lnkup-next-event-strip__label lnkup-type-label"><?php esc_html_e( 'Next event', 'lnkup-marketplace' ); ?></span>
			<strong class="lnkup-next-event-strip__title"><?php echo esc_html( (string) $week['next_event_title'] ); ?></strong>
			<?php if ( $ts ) : ?>
				<span class="lnkup-muted"><?php echo esc_html( date_i18n( 'D, M j', $ts ) ); ?></span>
			<?php endif; ?>
			<span class="lnkup-muted"><?php echo esc_html( sprintf( /* translators: %d: readiness percent */ __( '%d%% ready', 'lnkup-marketplace' ), $readiness ) ); ?></span>
			<?php if ( (float) $week['outstanding_amount'] > 0 ) : ?>
				<span class="lnkup-next-event-strip__due"><?php echo esc_html( sprintf( /* translators: %s: amount */ __( '%s due', 'lnkup-marketplace' ), lnkup_currency( (float) $week['outstanding_amount'] ) ) ); ?></span>
			<?php endif; ?>
			<span class="lnkup-home-next__go" aria-hidden="true">&rarr;</span>
		</a>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Payment return banner. The query flag is not enough; the stored status decides.
	 *
	 * @return string
	 */
	private static function render_payment_return_notice() {
		if ( ! isset( $_GET['lnkup_payment'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}
		$flag = sanitize_key( wp_unslash( $_GET['lnkup_payment'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'success' !== $flag ) {
			return '';
		}
		$user_id    = get_current_user_id();
		$payment_id = isset( $_GET['payment_id'] ) ? absint( $_GET['payment_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$payment    = null;
		if ( $payment_id ) {
			$post = get_post( $payment_id );
			if ( $post instanceof WP_Post && 'lnkup_payment' === $post->post_type ) {
				$owner = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
				if ( ! $owner || $owner === $user_id || current_user_can( 'manage_options' ) ) {
					$payment = $post;
				}
			}
		} else {
			$payments = lnkup_get_payments_for_user( $user_id, 'vendor' );
			$payment  = $payments ? $payments[0] : null;
		}
		if ( ! $payment instanceof WP_Post ) {
			return '';
		}
		$status = (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true );
		if ( 'paid' === $status ) {
			return '<div class="lnkup-alert lnkup-alert--success">' . esc_html__( 'Payment successful. Your pass is ready.', 'lnkup-marketplace' ) . '</div>';
		}
		if ( 'processing' === $status ) {
			return '<div class="lnkup-alert lnkup-alert--info">' . esc_html__( 'Payment is processing. Your pass unlocks when it is marked paid.', 'lnkup-marketplace' ) . '</div>';
		}
		return '';
	}

	/**
	 * Vendor notices banner.
	 *
	 * @param int $user_id User ID.
	 */
	private static function render_vendor_notices( $user_id ) {
		if ( ! class_exists( 'LNKup_Vendor_Invites' ) ) {
			return;
		}
		$invites = array();
		$notices = get_user_meta( $user_id, 'lnkup_vendor_notices', true );
		if ( is_array( $notices ) ) {
			foreach ( array_slice( $notices, 0, 5 ) as $notice ) {
				$invite_id = absint( $notice['invite_id'] ?? 0 );
				$invite    = $invite_id ? get_post( $invite_id ) : null;
				if ( $invite instanceof WP_Post && 'lnkup_vendor_invite' === $invite->post_type ) {
					$invites[ $invite->ID ] = $invite;
				}
			}
		}
		if ( ! $invites ) {
			$found = get_posts(
				array(
					'post_type'      => 'lnkup_vendor_invite',
					'post_status'    => 'any',
					'posts_per_page' => 5,
					'meta_query'     => array(
						array(
							'key'   => '_lnkup_vendor_user_id',
							'value' => $user_id,
						),
						array(
							'key'     => '_lnkup_status',
							'value'   => array( LNKup_Vendor_Invites::STATUS_SENT, LNKup_Vendor_Invites::STATUS_OPENED ),
							'compare' => 'IN',
						),
					),
				)
			);
			foreach ( $found as $invite ) {
				$invites[ $invite->ID ] = $invite;
			}
		}
		foreach ( $invites as $invite ) {
			$token  = (string) get_post_meta( $invite->ID, '_lnkup_token', true );
			$detail = self::invite_event_detail( $invite->ID );
			$link   = $token ? add_query_arg( 'invite', $token, lnkup_page_url_helper( 'opportunities' ) ) : lnkup_page_url_helper( 'opportunities' );
			?>
			<div class="lnkup-alert lnkup-alert--info">
				<strong><?php esc_html_e( 'Event invite', 'lnkup-marketplace' ); ?></strong>
				<?php if ( $detail ) : ?>
					<span><?php echo esc_html( $detail ); ?></span>
				<?php endif; ?>
				<a href="<?php echo esc_url( $link ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary"><?php esc_html_e( 'View Invite', 'lnkup-marketplace' ); ?></a>
			</div>
			<?php
		}
	}

	/**
	 * Event name, date, and city for an invite. Uses the post title directly.
	 *
	 * @param int $invite_id Invite post ID.
	 */
	private static function invite_event_detail( $invite_id ) {
		$event_id = (int) get_post_meta( $invite_id, '_lnkup_event_id', true );
		$opp_id   = (int) get_post_meta( $invite_id, '_lnkup_opportunity_id', true );
		if ( ! $event_id && $opp_id ) {
			$event_id = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
		}
		$event      = $event_id ? get_post( $event_id ) : null;
		$slot       = $opp_id ? get_post( $opp_id ) : null;
		$event_name = ( $event instanceof WP_Post ) ? trim( (string) $event->post_title ) : '';
		$slot_name  = ( '' === $event_name && $slot instanceof WP_Post ) ? trim( (string) $slot->post_title ) : '';
		$date       = $event_id ? (string) get_post_meta( $event_id, '_lnkup_event_date', true ) : '';
		$city       = $event_id ? (string) get_post_meta( $event_id, '_lnkup_city', true ) : '';
		$state      = $event_id ? (string) get_post_meta( $event_id, '_lnkup_state', true ) : '';
		$where      = trim( $city . ( $state ? ', ' . $state : '' ) );
		$when       = $date ? date_i18n( 'M j, Y', strtotime( $date ) ) : '';
		return implode( ' · ', array_filter( array( '' !== $event_name ? $event_name : $slot_name, $when, $where ) ) );
	}

	/**
	 * Applications view.
	 */
	public static function render_applications() {
		if ( ! lnkup_can_browse_marketplace() ) {
			ob_start();
			?>
			<div class="lnkup-mp lnkup-vendor-dash" id="applications" data-testid="lnkup-prelaunch-applications">
				<?php
				echo lnkup_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					array(
						'title'    => lnkup_copy( 'vendor_requests' ),
						'subtitle' => __( 'Applications open when the marketplace launches.', 'lnkup-marketplace' ),
					)
				);
				echo lnkup_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					array(
						'title'     => sprintf( __( 'Opening %s', 'lnkup-marketplace' ), lnkup_get_launch_date_label() ),
						'desc'      => __( 'Build your vendor profile and upload documents now so you are ready to apply on day one.', 'lnkup-marketplace' ),
						'cta_label' => __( 'Complete your profile', 'lnkup-marketplace' ),
						'cta_href'  => lnkup_page_url_helper( 'vendor-profile' ),
					)
				);
				?>
			</div>
			<?php
			return ob_get_clean();
		}
		$ctx          = LNKup_Vendor_Dashboard_Data::context();
		$applications = $ctx['applications'];
		$dashboard    = lnkup_page_url_helper( 'vendor-dashboard' );
		$counts   = LNKup_Vendor_Dashboard_Data::request_bucket_counts( $applications, $ctx['user_id'] );
		$active   = isset( $_GET['app_filter'] ) ? sanitize_key( wp_unslash( $_GET['app_filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $active, array( 'all', 'in-review', 'pay-now', 'booked', 'completed' ), true ) ) {
			$active = 'all';
		}
		$find_url = lnkup_page_url_helper( 'opportunities' );

		ob_start();
		?>
		<div class="lnkup-mp lnkup-vendor-dash" id="applications">
			<?php
			echo lnkup_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'title'    => __( 'Bookings', 'lnkup-marketplace' ),
					'subtitle' => __( 'Drafts, applications waiting on an organizer, fees due, and confirmed events.', 'lnkup-marketplace' ),
					'actions'  => '<a class="lnkup-btn lnkup-btn--outline" href="' . esc_url( $find_url ) . '">' . esc_html__( 'Explore', 'lnkup-marketplace' ) . '</a>',
				)
			);
			$request_tabs = array();
			foreach (
				array(
					'all'       => __( 'All', 'lnkup-marketplace' ),
					'pay-now'   => __( 'Needs action', 'lnkup-marketplace' ),
					'in-review' => __( 'In review', 'lnkup-marketplace' ),
					'booked'    => __( 'Confirmed', 'lnkup-marketplace' ),
					'completed' => __( 'Past events', 'lnkup-marketplace' ),
				) as $tab_key => $tab_label
			) {
				$request_tabs[] = array(
					'id'    => $tab_key,
					'label' => $tab_label,
					'badge' => (int) $counts[ $tab_key ],
					'attrs' => array( 'data-app-tab' => $tab_key ),
				);
			}
			echo lnkup_dash_tabs( $request_tabs, $active, array( 'variant' => 'pills', 'label' => __( 'Filter requests', 'lnkup-marketplace' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::render_draft_resume_cards( (int) $ctx['user_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>

			<div class="lnkup-panel lnkup-panel--flush">
				<?php if ( $applications ) : ?>
					<div class="lnkup-vendor-apps-workflow lnkup-workflow-grid" id="lnkup-vendor-apps-workflow">
						<?php foreach ( $applications as $app ) : ?>
							<?php
							$card   = self::render_application_workflow_card( $app, $dashboard );
							$bucket = LNKup_Vendor_Dashboard_Data::application_request_bucket( $app, $ctx['user_id'] );
							if ( 'all' !== $active && $bucket !== $active ) {
								$card = preg_replace( '/<article /', '<article hidden ', $card, 1 );
							}
							echo $card; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						<?php endforeach; ?>
					</div>
					<div id="lnkup-vendor-apps-filter-empty" data-testid="lnkup-vendor-apps-filter-empty"<?php echo ( 'all' === $active || (int) $counts[ $active ] > 0 ) ? ' hidden' : ''; ?>>
						<?php
						echo lnkup_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							array(
								'title'     => __( 'Nothing in this tab', 'lnkup-marketplace' ),
								'desc'      => __( 'Requests move here as organizers review them. Check the All tab for everything.', 'lnkup-marketplace' ),
								'cta_label' => __( 'Find events', 'lnkup-marketplace' ),
								'cta_href'  => $find_url,
								'compact'   => true,
							)
						);
						?>
					</div>
				<?php else : ?>
					<div id="lnkup-vendor-apps-empty">
						<?php
						echo lnkup_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							array(
								'title'     => __( 'Nothing here yet', 'lnkup-marketplace' ),
								'desc'      => __( 'Requests appear here after you apply to an event.', 'lnkup-marketplace' ),
								'cta_label' => __( 'Find an event', 'lnkup-marketplace' ),
								'cta_href'  => $find_url,
							)
						);
						?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Event operations hub with per-booking sub-tabs.
	 */
	public static function render_operations() {
		LNKup_Capabilities::require_login();
		$ctx           = LNKup_Vendor_Dashboard_Data::context();
		$dashboard     = lnkup_page_url_helper( 'vendor-dashboard' );
		$paid_apps     = LNKup_Vendor_Dashboard_Data::confirmed_applications( $ctx['applications'] );
		$pass_apps     = LNKup_Vendor_Dashboard_Data::pass_viewable_applications( $ctx['applications'] );
		$selected      = isset( $_GET['app'] ) ? absint( $_GET['app'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab           = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'packet' === $tab ) {
			$tab = 'pass';
		}
		$valid_tabs    = array( 'overview', 'pass', 'readiness', 'map', 'support' );
		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			$tab = 'overview';
		}
		if ( ! $selected && $pass_apps ) {
			$selected = $pass_apps[0]->ID;
		}
		$pass_ids = array_map( 'intval', wp_list_pluck( $pass_apps, 'ID' ) );
		if ( $selected && ! in_array( $selected, $pass_ids, true ) ) {
			$selected = $pass_ids[0] ?? 0;
		}
		$pass_only = $selected && LNKup_Vendor_Dashboard_Data::is_pass_only_application( $selected );
		if ( $pass_only ) {
			$tab = 'pass';
		}
		$apps_url = add_query_arg( 'view', 'applications', $dashboard );
		$find_url = lnkup_page_url_helper( 'opportunities' );

		ob_start();
		?>
		<div class="lnkup-mp lnkup-vendor-dash lnkup-vendor-ops-page" id="operations">
			<?php
			echo lnkup_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'title'    => __( 'Event operations', 'lnkup-marketplace' ),
					'subtitle' => __( 'Your pass, readiness, map, and event-day support for booked events.', 'lnkup-marketplace' ),
				)
			);
			?>

			<?php if ( ! $pass_apps ) : ?>
				<?php
				echo lnkup_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					array(
						'title'               => __( 'No confirmed events yet', 'lnkup-marketplace' ),
						'desc'                => __( 'Your event operations details will appear here once you\'re approved and paid for an event.', 'lnkup-marketplace' ),
						'cta_label'           => __( 'View applications', 'lnkup-marketplace' ),
						'cta_href'            => $apps_url,
						'secondary_cta_label' => __( 'Browse Find Events', 'lnkup-marketplace' ),
						'secondary_cta_href'  => $find_url,
					)
				);
				?>
			<?php else : ?>
				<?php
				$sel_meta   = LNKup_Vendor_Dashboard_Data::application_event_meta( get_post( $selected ) );
				$countdown  = '';
				if ( ! empty( $sel_meta['date'] ) ) {
					$diff = strtotime( $sel_meta['date'] ) - current_time( 'timestamp' );
					if ( $diff > 0 ) {
						$days = (int) ceil( $diff / DAY_IN_SECONDS );
						$countdown = sprintf( _n( '%d day to go', '%d days to go', $days, 'lnkup-marketplace' ), $days );
					}
				}
				?>
				<div class="lnkup-ops-mission-header lnkup-surface--hero">
					<div>
						<p class="lnkup-muted"><?php esc_html_e( 'Mission control', 'lnkup-marketplace' ); ?></p>
						<h2 class="lnkup-panel__title"><?php echo esc_html( $sel_meta['event_title'] ); ?></h2>
						<?php if ( ! empty( $sel_meta['date'] ) ) : ?>
							<p class="lnkup-muted"><?php echo esc_html( date_i18n( 'l, F j, Y', strtotime( $sel_meta['date'] ) ) ); ?></p>
						<?php endif; ?>
						<?php
						$sel_where = implode( ' · ', array_filter( array( (string) $sel_meta['venue'], trim( $sel_meta['city'] . ( $sel_meta['state'] ? ', ' . $sel_meta['state'] : '' ) ) ) ) );
						?>
						<?php if ( $sel_where ) : ?>
							<p class="lnkup-muted"><?php echo esc_html( $sel_where ); ?></p>
						<?php endif; ?>
					</div>
					<?php if ( $countdown ) : ?>
						<div class="lnkup-ops-countdown"><?php echo esc_html( $countdown ); ?></div>
					<?php endif; ?>
				</div>
				<?php
				$pass_only_payment = $pass_only ? LNKup_Vendor_Dashboard_Data::application_payment_for_vendor( $selected ) : null;
				?>
				<?php if ( $pass_only_payment && LNKup_Vendor_Dashboard_Data::application_is_payable( $selected ) ) : ?>
					<div class="lnkup-home-next lnkup-home-next--row" data-testid="lnkup-vendor-ops-pay">
						<span class="lnkup-status-dot lnkup-status-dot--warning" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Pay your booth fee to unlock readiness, your map, and event-day support.', 'lnkup-marketplace' ); ?></span>
						<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm lnkup-pay-btn" data-payment-id="<?php echo esc_attr( (string) $pass_only_payment->ID ); ?>"><?php esc_html_e( 'Pay now', 'lnkup-marketplace' ); ?></button>
					</div>
				<?php endif; ?>
				<div class="lnkup-vendor-ops" id="lnkup-vendor-ops" data-app-id="<?php echo esc_attr( (string) $selected ); ?>"<?php echo $pass_only ? ' data-pass-only="1"' : ''; ?>>
					<?php if ( count( $pass_apps ) > 1 ) : ?>
						<label class="lnkup-vendor-ops__selector">
							<span class="lnkup-muted"><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></span>
							<select id="lnkup-vendor-ops-event-select" class="lnkup-input">
								<?php foreach ( $pass_apps as $app ) : ?>
									<?php $event_id = (int) get_post_meta( $app->ID, '_lnkup_event_id', true ); ?>
									<option value="<?php echo esc_attr( (string) $app->ID ); ?>"<?php selected( $selected, $app->ID ); ?>><?php echo esc_html( get_the_title( $event_id ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>

					<?php
					$checklist = ( ! $pass_only && class_exists( 'LNKup_Vendor_Readiness' ) ) ? LNKup_Vendor_Readiness::checklist( $selected ) : array( 'percent' => 0 );
					$tabs      = $pass_only
						? array( 'pass' => __( 'Event Pass', 'lnkup-marketplace' ) )
						: array(
							'overview'  => __( 'Overview', 'lnkup-marketplace' ),
							'pass'      => __( 'Event Pass', 'lnkup-marketplace' ),
							'readiness' => __( 'Readiness', 'lnkup-marketplace' ),
							'map'       => __( 'Venue map', 'lnkup-marketplace' ),
							'support'   => __( 'Support', 'lnkup-marketplace' ),
						);
					?>

					<nav class="lnkup-dash-tabs lnkup-dash-tabs--underline lnkup-vendor-ops__tabs" aria-label="<?php esc_attr_e( 'Event operations sections', 'lnkup-marketplace' ); ?>">
						<?php
						foreach ( $tabs as $key => $label ) :
							$tab_url = add_query_arg(
								array(
									'view' => 'operations',
									'app'  => $selected,
									'tab'  => $key,
								),
								$dashboard
							);
							$tab_badge = '';
							if ( 'readiness' === $key && ! empty( $checklist['percent'] ) ) {
								$tab_badge = ' <span class="lnkup-dash-tabs__badge">' . esc_html( (string) $checklist['percent'] ) . '%</span>';
							}
							?>
							<a href="<?php echo esc_url( $tab_url ); ?>" class="lnkup-dash-tabs__btn lnkup-vendor-ops__tab<?php echo $tab === $key ? ' is-active' : ''; ?>" data-ops-tab="<?php echo esc_attr( $key ); ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?><?php echo $tab_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
						<?php endforeach; ?>
					</nav>

					<div class="lnkup-vendor-ops__panels">
						<?php
						$event_id = (int) get_post_meta( $selected, '_lnkup_event_id', true );
						$meta     = LNKup_Vendor_Dashboard_Data::application_event_meta( get_post( $selected ) );
						?>
						<?php if ( ! $pass_only ) : ?>
						<div class="lnkup-vendor-ops__panel<?php echo 'overview' === $tab ? ' is-active' : ''; ?>" data-ops-panel="overview"<?php echo 'overview' !== $tab ? ' hidden' : ''; ?>>
							<div id="lnkup-vendor-ops-home" data-app-id="<?php echo esc_attr( (string) $selected ); ?>">
								<?php
								echo self::render_ops_overview( $selected, $ctx['user_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							</div>
						</div>
						<?php endif; ?>
						<div class="lnkup-vendor-ops__panel<?php echo 'pass' === $tab ? ' is-active' : ''; ?>" data-ops-panel="pass"<?php echo 'pass' !== $tab ? ' hidden' : ''; ?>>
							<?php echo LNKup_Vendor_Event_Pass_View::render( $selected ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<?php if ( ! $pass_only ) : ?>
						<div class="lnkup-vendor-ops__panel<?php echo 'readiness' === $tab ? ' is-active' : ''; ?>" data-ops-panel="readiness"<?php echo 'readiness' !== $tab ? ' hidden' : ''; ?>>
							<div class="lnkup-ops-readiness-shell lnkup-surface--primary">
								<?php if ( ! empty( $checklist['percent'] ) ) : ?>
									<div class="lnkup-ops-readiness-shell__hero">
										<div class="lnkup-compliance-ring" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) (int) $checklist['percent'] ); ?>" aria-valuemin="0" aria-valuemax="100" style="--progress: <?php echo esc_attr( (string) min( 100, max( 0, (int) $checklist['percent'] ) ) ); ?>%">
											<span class="lnkup-compliance-ring__value"><?php echo esc_html( (string) (int) $checklist['percent'] ); ?>%</span>
										</div>
										<div>
											<h3 class="lnkup-type-h2"><?php esc_html_e( 'Event readiness', 'lnkup-marketplace' ); ?></h3>
											<p class="lnkup-muted"><?php esc_html_e( 'Complete these items before load-in day.', 'lnkup-marketplace' ); ?></p>
										</div>
									</div>
								<?php endif; ?>
								<?php if ( class_exists( 'LNKup_Operations_Render' ) && class_exists( 'LNKup_Vendor_Readiness' ) ) : ?>
									<?php echo LNKup_Operations_Render::readiness_checklist( $checklist, $selected ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php endif; ?>
							</div>
						</div>
						<div class="lnkup-vendor-ops__panel<?php echo 'map' === $tab ? ' is-active' : ''; ?>" data-ops-panel="map"<?php echo 'map' !== $tab ? ' hidden' : ''; ?>>
							<?php
							if ( class_exists( 'LNKup_Operations_Render' ) ) {
								$booths = class_exists( 'LNKup_Booths' ) ? LNKup_Booths::get_for_application( $selected ) : null;
								echo LNKup_Operations_Render::venue_map( $event_id, $booths ? array( $booths ) : null, 'vendor' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
							?>
						</div>
						<div class="lnkup-vendor-ops__panel<?php echo 'support' === $tab ? ' is-active' : ''; ?>" data-ops-panel="support"<?php echo 'support' !== $tab ? ' hidden' : ''; ?>>
							<div class="lnkup-panel lnkup-ops-support-panel lnkup-surface--primary" id="lnkup-vendor-support-panel" data-app-id="<?php echo esc_attr( (string) $selected ); ?>">
								<h3 class="lnkup-panel__title"><?php esc_html_e( 'Request support', 'lnkup-marketplace' ); ?></h3>
								<p class="lnkup-muted"><?php esc_html_e( 'Need help on event day? Send a message to the organizer operations team.', 'lnkup-marketplace' ); ?></p>
								<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-ops-support-open" data-app-id="<?php echo esc_attr( (string) $selected ); ?>"><?php esc_html_e( 'New request', 'lnkup-marketplace' ); ?></button>
								<div class="lnkup-ops-support-history" id="lnkup-vendor-support-history" data-lnkup-panel="support-history">
									<div class="lnkup-skeleton lnkup-skeleton--card"></div>
								</div>
							</div>
						</div>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Documents view wrapper.
	 */
	public static function render_documents() {
		return LNKup_Shortcodes::render_documents_manager();
	}

	/**
	 * Payments view wrapper.
	 */
	public static function render_payments() {
		return LNKup_Shortcodes::render_payments_dashboard( 'vendor' );
	}

	/**
	 * Vendor growth dashboard.
	 */
	public static function render_growth() {
		LNKup_Capabilities::require_login();
		if ( ! LNKup_Capabilities::can_view_growth() ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html__( 'Growth dashboard access required.', 'lnkup-marketplace' ) . '</p>';
		}
		ob_start();
		$referrals_url = add_query_arg( 'view', 'referrals', lnkup_page_url_helper( 'vendor-dashboard' ) );
		?>
		<div class="lnkup-mp lnkup-vendor-dash lnkup-vendor-growth" id="growth">
			<?php
			echo lnkup_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'title'    => __( 'Growth', 'lnkup-marketplace' ),
					'subtitle' => __( 'How your applications, bookings, and profile are trending.', 'lnkup-marketplace' ),
				)
			);
			if ( class_exists( 'LNKup_Organizer_Recruit_Render' ) ) {
				echo LNKup_Organizer_Recruit_Render::home_cta( $referrals_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo LNKup_Growth_Render::vendor_shell(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Vendor Referral Invites (recruit organizers).
	 */
	public static function render_referrals() {
		LNKup_Capabilities::require_login();
		self::enqueue_org_recruit_assets();
		ob_start();
		?>
		<div class="lnkup-mp lnkup-vendor-dash" id="referrals">
			<?php
			echo lnkup_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'title'    => __( 'Referral Invites', 'lnkup-marketplace' ),
					'subtitle' => LNKup_Organizer_Recruit_Render::headline(),
					'actions'  => '<a class="lnkup-btn lnkup-btn--outline" href="' . esc_url( add_query_arg( array( 'view' => 'payments', 'fin_tab' => 'referrals' ), lnkup_page_url_helper( 'vendor-dashboard' ) ) ) . '">' . esc_html__( 'View referral credits', 'lnkup-marketplace' ) . '</a>',
				)
			);
			echo LNKup_Organizer_Recruit_Render::hub_shell( 'vendor', array( 'show_title' => false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Enqueue referral invite assets.
	 */
	public static function enqueue_org_recruit_assets() {
		if ( class_exists( 'LNKup_Shortcodes' ) ) {
			LNKup_Shortcodes::enqueue_organizer_recruit_assets();
		}
	}

	/**
	 * Knowledge Base / Help view.
	 *
	 * @return string
	 */
	public static function render_help() {
		ob_start();
		$user_id = get_current_user_id();
		?>
		<div class="lnkup-mp lnkup-vendor-dash" id="help">
			<?php
			echo lnkup_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'title'    => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_eyebrow' ) : __( 'Help', 'lnkup-marketplace' ),
					'subtitle' => __( 'Guides for applying, paying, documents, and event day.', 'lnkup-marketplace' ),
				)
			);
			if ( class_exists( 'LNKup_Knowledge_Base' ) ) {
				echo LNKup_Knowledge_Base::render( 'vendor' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( class_exists( 'LNKup_Dashboard_Tour' ) ) {
				echo LNKup_Dashboard_Tour::render_modal( 'vendor', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Vendor Passport view: server shell + tabs; data loads from /passport/me.
	 */
	public static function render_passport() {
		$enabled = class_exists( 'LNKup_Passport_Settings' ) && LNKup_Passport_Settings::enabled( 'passport_enabled' );
		$tabs    = array(
			'overview'     => __( 'Overview', 'lnkup-marketplace' ),
			'portfolio'    => __( 'Portfolio', 'lnkup-marketplace' ),
			'verification' => __( 'Verification', 'lnkup-marketplace' ),
			'history'      => __( 'Event History', 'lnkup-marketplace' ),
			'reviews'      => __( 'Reviews', 'lnkup-marketplace' ),
			'documents'    => __( 'Documents', 'lnkup-marketplace' ),
			'preferences'  => __( 'Preferences', 'lnkup-marketplace' ),
			'invitations'  => __( 'Invitations', 'lnkup-marketplace' ),
			'settings'     => __( 'Settings', 'lnkup-marketplace' ),
		);
		ob_start();
		?>
		<div class="lnkup-mp lnkup-vendor-dash lnkup-passport" id="lnkup-passport" data-testid="lnkup-passport">
			<?php
			echo lnkup_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'title'    => __( 'Your Vendor Passport', 'lnkup-marketplace' ),
					'subtitle' => __( 'One professional identity that travels with you to every event on LNKup.', 'lnkup-marketplace' ),
				)
			);
			?>
			<?php if ( ! $enabled ) : ?>
				<?php
				echo lnkup_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					array(
						'title'     => __( 'Vendor Passport is coming soon', 'lnkup-marketplace' ),
						'desc'      => __( 'Your profile, documents and reviews are safe. Keep your profile up to date in the meantime.', 'lnkup-marketplace' ),
						'cta_label' => __( 'Edit profile', 'lnkup-marketplace' ),
						'cta_href'  => lnkup_page_url_helper( 'vendor-profile' ),
					)
				);
				?>
			<?php else : ?>
				<nav class="lnkup-passport__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Passport sections', 'lnkup-marketplace' ); ?>">
					<?php foreach ( $tabs as $key => $label ) : ?>
						<button type="button" role="tab" class="lnkup-passport__tab" id="lnkup-passport-tab-<?php echo esc_attr( $key ); ?>" aria-controls="lnkup-passport-panel" aria-selected="<?php echo 'overview' === $key ? 'true' : 'false'; ?>" data-passport-tab="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</nav>
				<div class="lnkup-passport__panel" id="lnkup-passport-panel" role="tabpanel" aria-live="polite" data-passport-panel>
					<div class="lnkup-passport__skeleton" data-passport-loading>
						<span class="lnkup-skeleton lnkup-skeleton--title"></span>
						<span class="lnkup-skeleton lnkup-skeleton--line"></span>
						<span class="lnkup-skeleton lnkup-skeleton--line"></span>
					</div>
				</div>
				<noscript><p class="lnkup-alert"><?php esc_html_e( 'Turn on JavaScript to manage your Passport, or use the profile editor.', 'lnkup-marketplace' ); ?></p></noscript>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Reviews view.
	 */
	public static function render_reviews() {
		$ctx        = LNKup_Vendor_Dashboard_Data::context();
		$profile_id = $ctx['profile_id'];
		$rep        = ( $profile_id && class_exists( 'LNKup_Vendor_Reputation' ) ) ? LNKup_Vendor_Reputation::get( $profile_id ) : array();
		$avg        = (float) ( $rep['avg_rating'] ?? 0 );
		$count      = (int) ( $rep['review_count'] ?? 0 );

		ob_start();
		?>
		<div class="lnkup-mp lnkup-vendor-dash" id="reviews">
			<?php
			echo lnkup_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'title'    => __( 'Reviews', 'lnkup-marketplace' ),
					'subtitle' => __( 'What organizers say after working with you.', 'lnkup-marketplace' ),
				)
			);
			?>

			<?php if ( $profile_id && class_exists( 'LNKup_Vendor_Reputation' ) ) : ?>
				<?php
				$rep     = LNKup_Vendor_Reputation::get( $profile_id );
				$reviews = LNKup_Vendor_Reviews::get_for_vendor( $profile_id, 20 );
				$avg     = (float) ( $rep['avg_rating'] ?? 0 );
				$count   = (int) ( $rep['review_count'] ?? count( $reviews ) );
				$stars   = array( 5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0 );
				foreach ( $reviews as $review ) {
					$r = (int) round( (float) ( $review['overall_rating'] ?? 0 ) );
					if ( $r >= 1 && $r <= 5 ) {
						$stars[ $r ]++;
					}
				}
				?>
				<?php
				$feedback = class_exists( 'LNKup_Reputation_Summary' ) ? LNKup_Reputation_Summary::organizer_feedback( (int) $profile_id ) : array( 'display_average' => true, 'label' => '' );
				?>
				<div class="lnkup-panel lnkup-review-score lnkup-trust-dashboard-hero lnkup-surface--primary">
					<div class="lnkup-review-score__main">
						<?php if ( ! empty( $feedback['display_average'] ) ) : ?>
							<span class="lnkup-review-score__value"><?php echo esc_html( number_format( (float) ( $feedback['average'] ?? $avg ), 1 ) ); ?></span>
							<?php echo LNKup_Reputation_Render::star_rating( (float) ( $feedback['average'] ?? $avg ), $count ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
						<p class="lnkup-muted"><?php echo esc_html( (string) ( $feedback['label'] ?? '' ) ); ?></p>
						<?php if ( class_exists( 'LNKup_Passport_Settings' ) && LNKup_Passport_Settings::enabled( 'passport_enabled' ) ) : ?>
							<a class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" href="<?php echo esc_url( add_query_arg( array( 'view' => 'passport', 'tab' => 'reviews' ), lnkup_page_url_helper( 'vendor-dashboard' ) ) ); ?>"><?php esc_html_e( 'Respond or report in your Passport', 'lnkup-marketplace' ); ?></a>
						<?php endif; ?>
						<?php echo LNKup_Reputation_Render::badge_grid( $rep['badges'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<?php if ( $count > 0 ) : ?>
						<div class="lnkup-trust-star-bars" role="group" aria-label="<?php esc_attr_e( 'Rating distribution', 'lnkup-marketplace' ); ?>">
							<?php for ( $star = 5; $star >= 1; $star-- ) : ?>
								<?php $pct = $count > 0 ? (int) round( ( $stars[ $star ] / $count ) * 100 ) : 0; ?>
								<div class="lnkup-trust-star-bars__row">
									<span class="lnkup-trust-star-bars__label" aria-hidden="true"><?php echo esc_html( (string) $star ); ?>★</span>
									<div class="lnkup-trust-star-bars__track" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $pct ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: star count, 2: number of reviews */ _n( '%1$d stars: %2$d review', '%1$d stars: %2$d reviews', (int) $stars[ $star ], 'lnkup-marketplace' ), $star, (int) $stars[ $star ] ) ); ?>"><span style="width:<?php echo esc_attr( (string) $pct ); ?>%"></span></div>
									<span class="lnkup-trust-star-bars__count"><?php echo esc_html( (string) $stars[ $star ] ); ?></span>
								</div>
							<?php endfor; ?>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $rep['metrics'] ) ) : ?>
						<?php echo LNKup_Reputation_Render::reliability_meter( $rep['metrics'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
				</div>

				<div class="lnkup-review-list">
					<?php if ( $reviews ) : ?>
						<?php foreach ( $reviews as $review ) : ?>
							<?php echo LNKup_Reputation_Render::review_card( $review, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					<?php else : ?>
						<?php
						echo lnkup_empty_state(
							array(
								'title'                 => __( 'No reviews yet', 'lnkup-marketplace' ),
								'desc'                  => __( 'Complete events to collect organizer feedback and build trust.', 'lnkup-marketplace' ),
								'cta_label'             => __( 'Find Events', 'lnkup-marketplace' ),
								'cta_href'              => lnkup_page_url_helper( 'opportunities' ),
								'secondary_cta_label'   => __( 'View applications', 'lnkup-marketplace' ),
								'secondary_cta_href'    => add_query_arg( 'view', 'applications', lnkup_page_url_helper( 'vendor-dashboard' ) ),
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<?php
				echo lnkup_empty_state(
					array(
						'title'                 => __( 'Complete your profile', 'lnkup-marketplace' ),
						'desc'                  => __( 'Finish your vendor profile to receive reviews after events.', 'lnkup-marketplace' ),
						'cta_label'             => __( 'Edit profile', 'lnkup-marketplace' ),
						'cta_href'              => lnkup_page_url_helper( 'vendor-profile' ),
						'secondary_cta_label'   => __( 'Find Events', 'lnkup-marketplace' ),
						'secondary_cta_href'    => lnkup_page_url_helper( 'opportunities' ),
					)
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render vendor operations overview with booth, parking, load-in, and QR subsection.
	 *
	 * @param int $app_id  Application ID.
	 * @param int $user_id Vendor user ID.
	 */
	private static function render_ops_overview( $app_id, $user_id ) {
		$home = class_exists( 'LNKup_Event_Operations' )
			? LNKup_Event_Operations::vendor_home_for_application( $app_id, $user_id )
			: array( 'has_event' => false );
		$checklist      = $home['readiness'] ?? ( class_exists( 'LNKup_Vendor_Readiness' ) ? LNKup_Vendor_Readiness::checklist( $app_id ) : array() );
		$meta           = LNKup_Vendor_Dashboard_Data::application_event_meta( get_post( $app_id ) );
		$payment        = class_exists( 'LNKup_Payments' ) ? LNKup_Payments::get_for_application( $app_id ) : null;
		$payment_status = '';
		if ( $payment instanceof WP_Post ) {
			$payment_status = (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true );
		}
		$organizer = (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true );
		$org_user  = $organizer ? get_userdata( $organizer ) : null;
		$venue     = $meta['venue'] ?? '';
		$map_query = ! empty( $meta['venue_address'] )
			? (string) $meta['venue_address']
			: implode( ', ', array_filter( array( (string) $venue, (string) ( $meta['city'] ?? '' ), (string) ( $meta['state'] ?? '' ) ) ) );
		$map_url   = '' !== $map_query ? 'https://maps.google.com/?q=' . rawurlencode( $map_query ) : '';
		$dashboard = lnkup_page_url_helper( 'vendor-dashboard' );
		$ops_base  = add_query_arg( array( 'view' => 'operations', 'app' => $app_id ), $dashboard );
		$tier      = $checklist['tier'] ?? 'on_track';
		ob_start();
		?>
		<div class="lnkup-ops-vendor-home">
			<?php if ( ! empty( $checklist['next_action'] ) || ! empty( $checklist['pending_top'] ) ) : ?>
				<div class="lnkup-ops-next-steps">
					<h4 class="lnkup-ops-next-steps__title"><?php esc_html_e( 'Next steps', 'lnkup-marketplace' ); ?></h4>
					<ul class="lnkup-ops-next-steps__list">
						<?php if ( ! empty( $checklist['next_action']['label'] ) ) : ?>
							<?php
							$na_url = add_query_arg( array( 'tab' => $checklist['next_action']['tab'] ?? 'readiness' ), $ops_base );
							if ( ! empty( $checklist['next_action']['section'] ) ) {
								$na_url = add_query_arg( 'section', $checklist['next_action']['section'], $na_url );
							}
							?>
							<li><a href="<?php echo esc_url( $na_url ); ?>"><?php echo esc_html( $checklist['next_action']['label'] ); ?></a></li>
						<?php endif; ?>
						<?php
						$extra = array_slice( $checklist['pending_top'] ?? array(), 1, 2 );
						foreach ( $extra as $item ) :
							if ( empty( $item['label'] ) ) {
								continue;
							}
							$item_url = add_query_arg( array( 'tab' => $item['cta_tab'] ?? 'readiness' ), $ops_base );
							if ( ! empty( $item['cta_section'] ) ) {
								$item_url = add_query_arg( 'section', $item['cta_section'], $item_url );
							}
							?>
							<li><a href="<?php echo esc_url( $item_url ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			<article class="lnkup-ops-vendor-card lnkup-ops-vendor-card--hero lnkup-surface--primary">
				<h3><?php echo esc_html( $home['event_title'] ?? $meta['event_title'] ); ?></h3>
				<?php if ( ! empty( $home['event_date'] ) || ! empty( $meta['date'] ) ) : ?>
					<p class="lnkup-muted"><?php echo esc_html( date_i18n( 'l, F j, Y', strtotime( $home['event_date'] ?? $meta['date'] ) ) ); ?><?php echo ! empty( $meta['start_time'] ) ? ' · ' . esc_html( $meta['start_time'] ) : ''; ?></p>
				<?php endif; ?>
				<?php if ( $venue ) : ?>
					<p class="lnkup-muted"><?php echo esc_html( $venue ); ?></p>
				<?php endif; ?>
				<div class="lnkup-ops-vendor-card__chips">
					<span class="lnkup-badge lnkup-badge--readiness lnkup-badge--<?php echo esc_attr( $tier ); ?>"><?php printf( esc_html__( '%d%% ready', 'lnkup-marketplace' ), (int) ( $home['readiness_percent'] ?? 0 ) ); ?></span>
					<?php if ( $payment_status ) : ?>
						<span class="lnkup-badge lnkup-badge--secondary"><?php echo esc_html( ucfirst( $payment_status ) ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $home['packet_status'] ) ) : ?>
						<span class="lnkup-badge lnkup-badge--accent"><?php echo esc_html( $home['packet_status'] ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $home['unread_broadcasts'] ) ) : ?>
						<a class="lnkup-badge lnkup-badge--secondary" href="<?php echo esc_url( add_query_arg( 'tab', 'pass', $ops_base ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: unread organizer updates */ _n( '%d new update', '%d new updates', (int) $home['unread_broadcasts'], 'lnkup-marketplace' ), (int) $home['unread_broadcasts'] ) ); ?></a>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $home['weather']['summary'] ) ) : ?>
					<p class="lnkup-ops-vendor-card__weather" data-testid="lnkup-vendor-ops-weather">
						<span class="lnkup-type-label"><?php esc_html_e( 'Weather', 'lnkup-marketplace' ); ?></span>
						<?php echo esc_html( (string) $home['weather']['summary'] ); ?>
					</p>
				<?php endif; ?>
				<?php if ( ! empty( $home['countdown_label'] ) ) : ?>
					<p class="lnkup-ops-vendor-card__countdown"><?php echo esc_html( $home['countdown_label'] ); ?></p>
				<?php endif; ?>
				<?php if ( $org_user ) : ?>
					<p class="lnkup-muted">
						<?php esc_html_e( 'Organizer contact:', 'lnkup-marketplace' ); ?>
						<?php
						$show_name = $org_user->display_name && strtolower( $org_user->display_name ) !== strtolower( $org_user->user_email );
						if ( $show_name ) {
							echo esc_html( $org_user->display_name );
							if ( $org_user->user_email ) {
								echo ' · ';
							}
						}
						if ( $org_user->user_email ) {
							echo esc_html( $org_user->user_email );
						}
						?>
					</p>
				<?php endif; ?>
				<?php if ( $map_url ) : ?>
					<p><a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( $map_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open map', 'lnkup-marketplace' ); ?></a></p>
				<?php endif; ?>
				<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm lnkup-ops-support-open" data-app-id="<?php echo esc_attr( (string) $app_id ); ?>"><?php esc_html_e( 'Request help', 'lnkup-marketplace' ); ?></button>
			</article>
			<div class="lnkup-ops-overview-grid">
				<div class="lnkup-ops-panel lnkup-ops-panel--status lnkup-surface--secondary<?php echo ! empty( $home['booth'] ) ? ' is-complete' : ''; ?>">
					<h4 class="lnkup-ops-panel__title"><span class="lnkup-ops-panel__icon" aria-hidden="true"><?php echo ! empty( $home['booth'] ) ? '✓' : '○'; ?></span><?php esc_html_e( 'Booth assignment', 'lnkup-marketplace' ); ?></h4>
					<?php if ( ! empty( $home['booth'] ) ) : ?>
						<p><strong><?php echo esc_html( $home['booth']['booth_number'] ?? '' ); ?></strong></p>
						<p class="lnkup-muted"><?php echo esc_html( $home['booth']['booth_type_label'] ?? '' ); ?><?php echo ! empty( $home['booth']['booth_size'] ) ? ' · ' . esc_html( $home['booth']['booth_size'] ) : ''; ?></p>
						<?php if ( ! empty( $home['booth']['has_power'] ) || ! empty( $home['booth']['has_water'] ) ) : ?>
							<p class="lnkup-muted">
								<?php
								$amenities = array();
								if ( ! empty( $home['booth']['has_power'] ) ) {
									$amenities[] = __( 'Power', 'lnkup-marketplace' );
								}
								if ( ! empty( $home['booth']['has_water'] ) ) {
									$amenities[] = __( 'Water', 'lnkup-marketplace' );
								}
								echo esc_html( implode( ' · ', $amenities ) );
								?>
							</p>
						<?php endif; ?>
						<p><a href="<?php echo esc_url( add_query_arg( 'tab', 'map', $ops_base ) ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php esc_html_e( 'View on map', 'lnkup-marketplace' ); ?></a></p>
					<?php else : ?>
						<p class="lnkup-muted"><?php esc_html_e( 'Booth not assigned yet. Check your packet for updates.', 'lnkup-marketplace' ); ?></p>
						<p><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'pass' ), $ops_base ) ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php esc_html_e( 'Open event pass', 'lnkup-marketplace' ); ?></a></p>
					<?php endif; ?>
				</div>
				<div class="lnkup-ops-panel lnkup-ops-panel--status lnkup-surface--secondary<?php echo ! empty( $home['parking'] ) ? ' is-complete' : ''; ?>">
					<h4 class="lnkup-ops-panel__title"><span class="lnkup-ops-panel__icon" aria-hidden="true"><?php echo ! empty( $home['parking'] ) ? '✓' : '○'; ?></span><?php esc_html_e( 'Parking', 'lnkup-marketplace' ); ?></h4>
					<?php if ( ! empty( $home['parking'] ) ) : ?>
						<p><strong><?php echo esc_html( $home['parking']['label'] ?? '' ); ?></strong></p>
						<?php if ( ! empty( $home['parking']['gate'] ) ) : ?>
							<p class="lnkup-muted"><?php echo esc_html( sprintf( __( 'Gate: %s', 'lnkup-marketplace' ), $home['parking']['gate'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $home['parking']['instructions'] ) ) : ?>
							<p class="lnkup-muted"><?php echo esc_html( $home['parking']['instructions'] ); ?></p>
						<?php endif; ?>
					<?php else : ?>
						<p class="lnkup-muted"><?php esc_html_e( 'Parking details will appear when assigned.', 'lnkup-marketplace' ); ?></p>
						<p><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'pass' ), $ops_base ) ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php esc_html_e( 'Open event pass', 'lnkup-marketplace' ); ?></a></p>
					<?php endif; ?>
				</div>
				<div class="lnkup-ops-panel lnkup-ops-panel--status lnkup-surface--secondary<?php echo ! empty( $home['loadin'] ) ? ' is-complete' : ''; ?>">
					<h4 class="lnkup-ops-panel__title"><span class="lnkup-ops-panel__icon" aria-hidden="true"><?php echo ! empty( $home['loadin'] ) ? '✓' : '○'; ?></span><?php esc_html_e( 'Load-in', 'lnkup-marketplace' ); ?></h4>
					<?php if ( ! empty( $home['loadin'] ) ) : ?>
						<p><strong><?php echo esc_html( $home['loadin']['label'] ?? '' ); ?></strong></p>
						<?php if ( ! empty( $home['loadin']['gate'] ) ) : ?>
							<p class="lnkup-muted"><?php echo esc_html( sprintf( __( 'Gate: %s', 'lnkup-marketplace' ), $home['loadin']['gate'] ) ); ?></p>
						<?php endif; ?>
					<?php else : ?>
						<p class="lnkup-muted"><?php esc_html_e( 'Load-in window not scheduled yet.', 'lnkup-marketplace' ); ?></p>
						<p><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'pass' ), $ops_base ) ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php esc_html_e( 'Open event pass', 'lnkup-marketplace' ); ?></a></p>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $home['emergency'] ) && is_array( $home['emergency'] ) ) : ?>
					<div class="lnkup-ops-panel lnkup-surface--secondary">
						<h4 class="lnkup-ops-panel__title"><?php esc_html_e( 'Emergency contacts', 'lnkup-marketplace' ); ?></h4>
						<ul class="lnkup-ops-emergency-list">
							<?php foreach ( array_slice( $home['emergency'], 0, 4 ) as $contact ) : ?>
								<li>
									<strong><?php echo esc_html( $contact['name'] ?? $contact['label'] ?? '' ); ?></strong>
									<?php if ( ! empty( $contact['phone'] ) ) : ?>
										<span class="lnkup-muted"><?php echo esc_html( $contact['phone'] ); ?></span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $home['qr_pass'] ) ) : ?>
					<div class="lnkup-ops-panel lnkup-ops-panel--qr lnkup-surface--secondary">
						<h4 class="lnkup-ops-panel__title"><?php esc_html_e( 'Check-in QR pass', 'lnkup-marketplace' ); ?></h4>
						<p class="lnkup-muted"><?php esc_html_e( 'Show this at vendor check-in.', 'lnkup-marketplace' ); ?></p>
						<div class="lnkup-ops-qr lnkup-ops-qr--compact" data-qr="<?php echo esc_attr( $home['qr_pass'] ); ?>"></div>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
