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
			<div class="lnkup-launch-checklist lnkup-vendor-getting-started vos-card vos-setup" id="lnkup-vendor-getting-started" data-testid="lnkup-vendor-home-tutorial" data-all-complete="0">
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
						<?php
						$shown = 0;
						foreach ( $items as $item ) :
							$key         = isset( $item['key'] ) ? (string) $item['key'] : '';
							$is_complete = ! empty( $item['done'] );
							if ( $is_complete || $shown >= 2 ) {
								continue;
							}
							++$shown;
							$is_current   = $key && $key === $next_key;
							$cta_url      = isset( $item['cta_url'] ) ? (string) $item['cta_url'] : '#';
							$cta_label    = isset( $item['cta_label'] ) ? (string) $item['cta_label'] : '';
							?>
							<article class="lnkup-launch-checklist-card lnkup-launch-checklist-card--not-started <?php echo $is_current ? 'is-current' : 'is-pending'; ?>" data-vendor-tutorial-step="<?php echo esc_attr( $key ); ?>">
								<span class="lnkup-launch-checklist-card__pill"><?php echo esc_html( lnkup_copy( 'launch_checklist_status_not_started' ) ); ?></span>
								<h3 class="lnkup-launch-checklist-card__title"><?php echo esc_html( $item['title'] ?? '' ); ?></h3>
								<p class="lnkup-launch-checklist-card__desc lnkup-muted"><?php echo esc_html( $item['description'] ?? '' ); ?></p>
								<?php if ( $cta_label ) : ?>
									<a href="<?php echo esc_url( $cta_url ); ?>" class="lnkup-btn lnkup-btn--sm <?php echo $is_current ? 'lnkup-btn--primary' : 'lnkup-btn--outline'; ?> lnkup-launch-checklist-card__cta"><?php echo esc_html( $cta_label ); ?></a>
								<?php endif; ?>
							</article>
						<?php endforeach; ?>
					</div>
					<?php if ( $completed > 0 ) : ?>
						<details class="lnkup-launch-checklist__done">
							<summary><?php echo esc_html( sprintf( /* translators: %d: completed step count */ _n( '%d completed step', '%d completed steps', $completed, 'lnkup-marketplace' ), $completed ) ); ?></summary>
							<ul>
								<?php foreach ( $items as $done_item ) : ?>
									<?php if ( empty( $done_item['done'] ) ) { continue; } ?>
									<li><?php echo esc_html( (string) ( $done_item['title'] ?? '' ) ); ?></li>
								<?php endforeach; ?>
							</ul>
						</details>
					<?php endif; ?>
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
		$place     = (string) ( $meta['place'] ?? '' );
		if ( '' === $place ) {
			$place = function_exists( 'lnkup_display_place' ) ? lnkup_display_place( (string) $meta['city'], (string) $meta['state'] ) : trim( $meta['city'] . ( $meta['state'] ? ', ' . $meta['state'] : '' ) );
		}
		$where     = implode( ', ', array_filter( array( (string) $meta['venue'], $place ) ) );
		$life      = class_exists( 'LNKup_Vendor_Presentation' ) ? LNKup_Vendor_Presentation::lifecycle( $app->ID ) : array();
		$primary   = (string) ( $life['primary'] ?? LNKup_Vendor_Dashboard_Data::application_status_label( $status ) );
		$is_past   = ! empty( $life['is_past'] );
		$can_withdraw = array_key_exists( 'can_withdraw', $life ) ? ! empty( $life['can_withdraw'] ) : ( in_array( $status, LNKup_Applications::WITHDRAWABLE, true ) && ! ( $payment && in_array( (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true ), array( 'processing', 'paid', 'partially_paid' ), true ) ) );
		$is_free   = (float) $meta['fee'] <= 0 && ( ! $payment || (float) get_post_meta( $payment->ID, '_lnkup_total_amount', true ) <= 0 );
		$note      = '';
		if ( 'waitlisted' === $status ) {
			$note = __( 'You are on the waitlist. We will let you know if a spot opens.', 'lnkup-marketplace' );
		} elseif ( 'rejected' === $status ) {
			$note = __( 'Not selected this time. Keep applying to similar events.', 'lnkup-marketplace' );
		} elseif ( 'cancelled' === $status ) {
			$note = __( 'This request was cancelled.', 'lnkup-marketplace' );
		} elseif ( $pay_due && $is_past ) {
			$note = __( 'This event already happened. The balance is still open on your invoice.', 'lnkup-marketplace' );
		}
		$detail_url = add_query_arg( array( 'view' => 'applications', 'app' => (int) $app->ID ), lnkup_page_url_helper( 'vendor-dashboard' ) );
		$organizer  = LNKup_Vendor_UI::organizer_name( $event_id );
		$tone       = LNKup_Vendor_UI::tone_for_status( $status, (bool) $pay_due );
		$amount     = $payment ? (float) get_post_meta( $payment->ID, '_lnkup_total_amount', true ) : (float) $meta['fee'];
		ob_start();
		?>
		<article class="lnkup-workflow-card lnkup-request-row vos-booking" data-app-tab="<?php echo esc_attr( $filter ); ?>" data-app-filter="<?php echo esc_attr( $filter ); ?>" data-testid="lnkup-vendor-request-card">
			<a class="vos-booking__media" href="<?php echo esc_url( $detail_url ); ?>" tabindex="-1" aria-hidden="true"><?php echo LNKup_Vendor_UI::event_image( $event_id, (string) $meta['event_title'], $opp_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
			<div class="vos-booking__body">
				<div class="vos-booking__top">
					<?php echo LNKup_Vendor_UI::status( $primary, $tone ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="vos-booking__amount"><?php echo esc_html( $is_free ? __( 'Free', 'lnkup-marketplace' ) : lnkup_currency( $amount ) ); ?></span>
				</div>
				<a class="lnkup-request-row__title vos-booking__title" href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $meta['event_title'] ); ?></a>
				<p class="lnkup-request-row__meta vos-booking__meta" data-testid="lnkup-vendor-request-when"><?php echo esc_html( implode( ' · ', array_filter( array( $when, $where ) ) ) ); ?></p>
				<p class="vos-booking__sub"><?php echo esc_html( implode( ' · ', array_filter( array( ( $slot && $slot !== (string) $meta['event_title'] ) ? $slot : '', $organizer ) ) ) ); ?></p>
				<?php if ( $note ) : ?>
					<p class="lnkup-request-row__note vos-booking__note"><?php echo esc_html( $note ); ?></p>
				<?php endif; ?>
				<?php echo self::render_info_request_block( (int) $app->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="lnkup-request-row__actions vos-booking__actions">
					<?php if ( $pay_due ) : ?>
						<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm lnkup-pay-btn" data-payment-id="<?php echo esc_attr( (string) $payment->ID ); ?>"><?php echo esc_html( $is_past ? __( 'Pay invoice', 'lnkup-marketplace' ) : __( 'Pay now', 'lnkup-marketplace' ) ); ?></button>
					<?php elseif ( 'booked' === $bucket ) : ?>
						<a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="<?php echo esc_url( $pass_url ); ?>"><?php esc_html_e( 'Open pass', 'lnkup-marketplace' ); ?></a>
					<?php elseif ( $docs_due ) : ?>
						<a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="<?php echo esc_url( $docs_url ); ?>"><?php esc_html_e( 'Upload documents', 'lnkup-marketplace' ); ?></a>
					<?php endif; ?>
					<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'Details', 'lnkup-marketplace' ); ?></a>
					<?php if ( $can_withdraw ) : ?>
						<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm vos-booking__withdraw" data-withdraw-application data-endpoint="<?php echo esc_url( rest_url( 'lnkup/v1/applications/' . (int) $app->ID . '/withdraw' ) ); ?>" data-testid="lnkup-vendor-withdraw"><?php esc_html_e( 'Withdraw', 'lnkup-marketplace' ); ?></button>
					<?php endif; ?>
				</div>
				<p class="lnkup-request-row__meta" role="status" aria-live="polite" data-withdraw-status hidden></p>
			</div>
		</article>
		<?php
		return ob_get_clean();
	}

	/**
	 * Booking detail workspace: overview, application, payments, documents, event prep, activity.
	 *
	 * @param WP_Post $app     Application owned by the current vendor.
	 * @param int     $user_id Vendor user ID.
	 */
	private static function render_booking_detail( $app, $user_id ) {
		$meta      = LNKup_Vendor_Dashboard_Data::application_event_meta( $app );
		$status    = (string) get_post_meta( $app->ID, '_lnkup_application_status', true );
		$life      = class_exists( 'LNKup_Vendor_Presentation' ) ? LNKup_Vendor_Presentation::lifecycle( $app->ID, $user_id ) : array();
		$payment   = LNKup_Vendor_Dashboard_Data::application_payment_for_vendor( $app, $user_id );
		$pay_due   = LNKup_Vendor_Dashboard_Data::application_is_payable( $app, $user_id );
		$event_id  = (int) ( $meta['event_id'] ?? 0 );
		$opp_id    = (int) get_post_meta( $app->ID, '_lnkup_opportunity_id', true );
		$dashboard = lnkup_page_url_helper( 'vendor-dashboard' );
		$apps_url  = add_query_arg( 'view', 'applications', $dashboard );
		$ops_base  = add_query_arg( array( 'view' => 'operations', 'app' => (int) $app->ID ), $dashboard );
		$event_url = ( $event_id && class_exists( 'LNKup_Organizer_Onboarding' ) ) ? LNKup_Organizer_Onboarding::event_public_url( $event_id ) : '';
		$primary   = (string) ( $life['primary'] ?? LNKup_Vendor_Dashboard_Data::application_status_label( $status ) );
		$organizer = LNKup_Vendor_UI::organizer_name( $event_id );
		$slot      = $opp_id ? lnkup_plain_title( $opp_id ) : '';
		$is_past   = ! empty( $life['is_past'] );
		$steps     = in_array( $status, array( 'rejected', 'cancelled' ), true ) ? array() : LNKup_Vendor_Dashboard_Data::application_workflow_steps(
			$status,
			array(
				'is_free'    => (float) $meta['fee'] <= 0,
				'event_date' => (string) $meta['date'],
			)
		);

		$pay_status = $payment ? (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true ) : '';
		$total      = $payment ? (float) get_post_meta( $payment->ID, '_lnkup_total_amount', true ) : (float) $meta['fee'];
		$items      = array();
		$invoice    = null;
		if ( $payment && class_exists( 'LNKup_Invoices' ) ) {
			$invoice = LNKup_Invoices::get_by_payment( (int) $payment->ID );
			if ( is_array( $invoice ) && ! empty( $invoice['id'] ) ) {
				foreach ( (array) LNKup_Invoices::get_items( (int) $invoice['id'] ) as $item ) {
					$items[] = array(
						'description' => (string) ( $item['description'] ?? '' ),
						'amount'      => (float) ( $item['line_total'] ?? 0 ),
					);
				}
			}
		}
		$receipt     = ( $payment && class_exists( 'LNKup_Receipts' ) ) ? LNKup_Receipts::format( LNKup_Receipts::get_by_payment( (int) $payment->ID ) ) : null;
		$doc_context = class_exists( 'LNKup_Verification' ) ? (array) LNKup_Verification::get_application_document_context( (int) $app->ID ) : array();
		$readiness   = ( 'booked' === LNKup_Vendor_Dashboard_Data::application_request_bucket( $app ) && class_exists( 'LNKup_Vendor_Readiness' ) ) ? LNKup_Vendor_Readiness::checklist( (int) $app->ID ) : array();
		$days        = LNKup_Vendor_UI::days_until( (string) $meta['date'] );

		$sections = array(
			'overview' => __( 'Overview', 'lnkup-marketplace' ),
			'payments' => __( 'Payments', 'lnkup-marketplace' ),
			'documents' => __( 'Documents', 'lnkup-marketplace' ),
			'prep'     => __( 'Event prep', 'lnkup-marketplace' ),
			'activity' => __( 'Activity', 'lnkup-marketplace' ),
		);
		$current = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $sections[ $current ] ) ) {
			$current = 'overview';
		}

		ob_start();
		?>
		<div class="lnkup-mp lnkup-vendor-dash vos-detail" id="applications" data-testid="lnkup-booking-detail">
			<nav class="vos-crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'lnkup-marketplace' ); ?>"><a href="<?php echo esc_url( $apps_url ); ?>">&larr; <?php esc_html_e( 'All bookings', 'lnkup-marketplace' ); ?></a></nav>
			<header class="vos-detail__hero">
				<div class="vos-detail__media"><?php echo LNKup_Vendor_UI::event_image( $event_id, (string) $meta['event_title'], $opp_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="vos-detail__copy">
					<div class="vos-detail__badges">
						<?php echo LNKup_Vendor_UI::status( $primary, LNKup_Vendor_UI::tone_for_status( $status, (bool) $pay_due ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( null !== $days && ! $is_past ) : ?>
							<span class="vos-countdown"><?php echo esc_html( 0 === $days ? __( 'Today', 'lnkup-marketplace' ) : sprintf( /* translators: %d: days */ _n( '%d day to go', '%d days to go', $days, 'lnkup-marketplace' ), $days ) ); ?></span>
						<?php endif; ?>
					</div>
					<h1 class="vos-page-header__title"><?php echo esc_html( (string) $meta['event_title'] ); ?></h1>
					<p class="vos-page-header__subtitle"><?php echo esc_html( implode( ' · ', array_filter( array( LNKup_Vendor_UI::date( (string) $meta['date'] ), implode( ', ', array_filter( array( (string) $meta['venue'], (string) ( $meta['place'] ?? '' ) ) ) ), $organizer ) ) ) ); ?></p>
					<div class="vos-detail__actions">
						<?php if ( $pay_due && $payment ) : ?>
							<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-pay-btn" data-payment-id="<?php echo esc_attr( (string) $payment->ID ); ?>"><?php echo esc_html( $is_past ? __( 'Pay invoice', 'lnkup-marketplace' ) : __( 'Pay booth fee', 'lnkup-marketplace' ) ); ?></button>
						<?php elseif ( $readiness ) : ?>
							<a class="lnkup-btn lnkup-btn--primary" href="<?php echo esc_url( add_query_arg( 'tab', 'readiness', $ops_base ) ); ?>"><?php esc_html_e( 'Prepare for event', 'lnkup-marketplace' ); ?></a>
						<?php endif; ?>
						<?php if ( $event_url ) : ?>
							<a class="lnkup-btn lnkup-btn--outline" href="<?php echo esc_url( $event_url ); ?>"><?php esc_html_e( 'Event page', 'lnkup-marketplace' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			</header>

			<div class="vos-tabs" data-vos-tabs>
				<div class="vos-tabs__list" role="tablist" aria-label="<?php esc_attr_e( 'Booking sections', 'lnkup-marketplace' ); ?>">
					<?php foreach ( $sections as $key => $label ) : ?>
						<button type="button" role="tab" class="vos-tabs__tab" id="vos-tab-<?php echo esc_attr( $key ); ?>" aria-controls="vos-panel-<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo $current === $key ? 'true' : 'false'; ?>" tabindex="<?php echo $current === $key ? '0' : '-1'; ?>" data-section="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</div>

				<section class="vos-tabs__panel" role="tabpanel" id="vos-panel-overview" aria-labelledby="vos-tab-overview"<?php echo 'overview' !== $current ? ' hidden' : ''; ?>>
					<div class="vos-detail__grid">
						<dl class="vos-dl vos-card">
							<div><dt><?php esc_html_e( 'Application', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( (string) ( $life['application'] ?? LNKup_Vendor_Dashboard_Data::application_status_label( $status ) ) ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Booking', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( (string) ( $life['booking'] ?? '—' ) ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Payment', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( (string) ( $life['payment'] ?? '—' ) ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( (string) ( $life['event'] ?? '—' ) ); ?></dd></div>
							<?php if ( $slot ) : ?><div><dt><?php esc_html_e( 'Booth', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( $slot ); ?></dd></div><?php endif; ?>
							<div><dt><?php esc_html_e( 'Booth fee', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( (float) $meta['fee'] > 0 ? lnkup_currency( (float) $meta['fee'] ) : __( 'Free', 'lnkup-marketplace' ) ); ?></dd></div>
						</dl>
						<div class="vos-card">
							<h2 class="vos-section-head__title"><?php esc_html_e( 'Progress', 'lnkup-marketplace' ); ?></h2>
							<?php if ( $steps ) : ?>
								<ol class="vos-timeline">
									<?php foreach ( $steps as $step ) : ?>
										<li class="vos-timeline__item is-<?php echo esc_attr( $step['state'] ); ?>"<?php echo 'current' === $step['state'] ? ' aria-current="step"' : ''; ?>><?php echo esc_html( $step['label'] ); ?></li>
									<?php endforeach; ?>
								</ol>
							<?php else : ?>
								<p class="vos-empty__desc"><?php echo esc_html( 'rejected' === $status ? __( 'This application was not selected.', 'lnkup-marketplace' ) : __( 'This application was cancelled.', 'lnkup-marketplace' ) ); ?></p>
							<?php endif; ?>
						</div>
					</div>
					<?php echo self::render_info_request_block( (int) $app->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo class_exists( 'LNKup_Distribution' ) ? LNKup_Distribution::acceptance_card_for_request( (int) $app->ID ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in acceptance_card(). ?>
				</section>

				<section class="vos-tabs__panel" role="tabpanel" id="vos-panel-payments" aria-labelledby="vos-tab-payments"<?php echo 'payments' !== $current ? ' hidden' : ''; ?>>
					<div class="vos-card">
						<?php if ( ! $payment ) : ?>
							<?php echo LNKup_Vendor_UI::empty_state( __( 'No invoice yet', 'lnkup-marketplace' ), __( 'An invoice is created when the organizer approves your application. Applying is free.', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php else : ?>
							<div class="vos-section-head">
								<h2 class="vos-section-head__title"><?php esc_html_e( 'Invoice', 'lnkup-marketplace' ); ?><?php if ( is_array( $invoice ) && ! empty( $invoice['invoice_number'] ) ) : ?> <span class="vos-muted">#<?php echo esc_html( (string) $invoice['invoice_number'] ); ?></span><?php endif; ?></h2>
								<?php echo LNKup_Vendor_UI::status( (string) ( $life['payment'] ?? '' ), 'paid' === $pay_status ? 'success' : ( $pay_due ? 'warning' : 'info' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
							<table class="vos-lines">
								<tbody>
									<?php if ( $items ) : ?>
										<?php foreach ( $items as $item ) : ?>
											<tr><th scope="row"><?php echo esc_html( $item['description'] ); ?></th><td><?php echo esc_html( lnkup_currency( $item['amount'] ) ); ?></td></tr>
										<?php endforeach; ?>
									<?php else : ?>
										<tr><th scope="row"><?php esc_html_e( 'Recorded total', 'lnkup-marketplace' ); ?><span class="vos-lines__hint"><?php esc_html_e( 'Line items were not stored for this payment.', 'lnkup-marketplace' ); ?></span></th><td><?php echo esc_html( lnkup_currency( $total ) ); ?></td></tr>
									<?php endif; ?>
								</tbody>
								<tfoot><tr><th scope="row"><?php esc_html_e( 'Total', 'lnkup-marketplace' ); ?></th><td><?php echo esc_html( lnkup_currency( $total ) ); ?></td></tr></tfoot>
							</table>
							<div class="vos-detail__actions">
								<?php if ( $pay_due ) : ?>
									<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-pay-btn" data-payment-id="<?php echo esc_attr( (string) $payment->ID ); ?>"><?php esc_html_e( 'Pay now', 'lnkup-marketplace' ); ?></button>
								<?php endif; ?>
								<?php if ( $receipt && ! empty( $receipt['view_url'] ) ) : ?>
									<a class="lnkup-btn lnkup-btn--outline" href="<?php echo esc_url( (string) $receipt['view_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View receipt', 'lnkup-marketplace' ); ?></a>
								<?php endif; ?>
								<a class="vos-link" href="<?php echo esc_url( add_query_arg( array( 'view' => 'payments', 'fin_tab' => 'invoices' ), $dashboard ) ); ?>"><?php esc_html_e( 'All invoices', 'lnkup-marketplace' ); ?></a>
							</div>
						<?php endif; ?>
					</div>
				</section>

				<section class="vos-tabs__panel" role="tabpanel" id="vos-panel-documents" aria-labelledby="vos-tab-documents"<?php echo 'documents' !== $current ? ' hidden' : ''; ?>>
					<div class="vos-card">
						<?php
						$doc_code  = (string) ( $doc_context['code'] ?? '' );
						$doc_label = (string) ( $doc_context['label'] ?? $doc_context['message'] ?? '' );
						if ( '' === $doc_label ) {
							$doc_label = in_array( $doc_code, array( 'missing', 'pending' ), true )
								? __( 'Documents are still needed for this event.', 'lnkup-marketplace' )
								: __( 'No documents are outstanding for this event.', 'lnkup-marketplace' );
						}
						echo LNKup_Vendor_UI::status( $doc_label, in_array( $doc_code, array( 'missing', 'pending' ), true ) ? 'warning' : 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
						<p class="vos-empty__desc"><?php esc_html_e( 'Approved documents in your vault are reused across applications unless this event asks for something different.', 'lnkup-marketplace' ); ?></p>
						<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="<?php echo esc_url( add_query_arg( 'view', 'documents', $dashboard ) ); ?>"><?php esc_html_e( 'Open document vault', 'lnkup-marketplace' ); ?></a>
					</div>
				</section>

				<section class="vos-tabs__panel" role="tabpanel" id="vos-panel-prep" aria-labelledby="vos-tab-prep"<?php echo 'prep' !== $current ? ' hidden' : ''; ?>>
					<div class="vos-card">
						<?php if ( $readiness ) : ?>
							<p class="vos-detail__prep-figure"><?php echo esc_html( sprintf( /* translators: %d: percent */ __( '%d%% of event prep complete', 'lnkup-marketplace' ), (int) ( $readiness['percent'] ?? 0 ) ) ); ?></p>
							<?php echo LNKup_Vendor_UI::progress( (int) ( $readiness['percent'] ?? 0 ), __( 'Event preparation', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<div class="vos-detail__actions">
								<a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="<?php echo esc_url( add_query_arg( 'tab', 'readiness', $ops_base ) ); ?>"><?php esc_html_e( 'Readiness checklist', 'lnkup-marketplace' ); ?></a>
								<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="<?php echo esc_url( add_query_arg( 'tab', 'pass', $ops_base ) ); ?>"><?php esc_html_e( 'Event pass', 'lnkup-marketplace' ); ?></a>
								<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="<?php echo esc_url( add_query_arg( 'tab', 'map', $ops_base ) ); ?>"><?php esc_html_e( 'Booth & venue', 'lnkup-marketplace' ); ?></a>
							</div>
						<?php elseif ( $is_past ) : ?>
							<?php echo LNKup_Vendor_UI::empty_state( __( 'This event has ended', 'lnkup-marketplace' ), __( 'Event prep is closed. Your receipts and history stay available here.', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php else : ?>
							<?php echo LNKup_Vendor_UI::empty_state( __( 'Event prep unlocks after your booking is confirmed', 'lnkup-marketplace' ), __( 'Once you are approved and paid, your pass, booth assignment, and load-in details appear here.', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</div>
				</section>

				<section class="vos-tabs__panel" role="tabpanel" id="vos-panel-activity" aria-labelledby="vos-tab-activity"<?php echo 'activity' !== $current ? ' hidden' : ''; ?>>
					<div class="vos-card">
						<ol class="vos-timeline vos-timeline--dated">
							<li class="vos-timeline__item is-done"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Application created %s', 'lnkup-marketplace' ), get_the_date( 'M j, Y', $app ) ) ); ?></li>
							<?php if ( $payment ) : ?>
								<li class="vos-timeline__item is-done"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Invoice issued %s', 'lnkup-marketplace' ), get_the_date( 'M j, Y', $payment ) ) ); ?></li>
							<?php endif; ?>
							<?php if ( $receipt ) : ?>
								<li class="vos-timeline__item is-done"><?php echo esc_html( sprintf( /* translators: 1: receipt number, 2: date */ __( 'Receipt %1$s issued %2$s', 'lnkup-marketplace' ), (string) $receipt['receipt_number'], mysql2date( 'M j, Y', (string) $receipt['created_at'] ) ) ); ?></li>
							<?php endif; ?>
							<li class="vos-timeline__item is-current"><?php echo esc_html( sprintf( /* translators: %s: status */ __( 'Current status: %s', 'lnkup-marketplace' ), $primary ) ); ?></li>
						</ol>
					</div>
				</section>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
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
		<div class="lnkup-panel lnkup-trust-card vos-card vos-level" id="lnkup-vendor-trust-card" data-testid="lnkup-vendor-trust-card" data-profile-id="<?php echo esc_attr( (string) $profile_id ); ?>">
			<div class="lnkup-trust-card__loading">
				<div class="lnkup-skeleton lnkup-skeleton--title"></div>
				<div class="lnkup-skeleton lnkup-skeleton--line"></div>
				<div class="lnkup-skeleton lnkup-skeleton--line lnkup-skeleton--short"></div>
			</div>
			<div class="lnkup-trust-card__content" hidden>
				<div class="vos-section-head">
					<h2 class="vos-section-head__title"><?php esc_html_e( 'Vendor level', 'lnkup-marketplace' ); ?></h2>
					<p class="lnkup-trust-card__status vos-level__name" data-trust-status></p>
				</div>
				<p class="lnkup-type-meta lnkup-trust-card__desc"><?php esc_html_e( 'Your level rises as you complete verification and events. Remaining steps are listed below.', 'lnkup-marketplace' ); ?></p>
				<div class="lnkup-trust-card__level-wrap">
					<div class="lnkup-trust-card__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Progress to next vendor level', 'lnkup-marketplace' ); ?>">
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
		$subtitle      = function_exists( 'lnkup_display_place' ) && ( $ctx['city'] || $ctx['state'] )
			? lnkup_display_place( (string) $ctx['city'], (string) $ctx['state'] )
			: ( $ctx['city'] ? trim( $ctx['city'] . ( $ctx['state'] ? ', ' . $ctx['state'] : '' ) ) : __( 'Add your city so recommendations can match where you work.', 'lnkup-marketplace' ) );
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
		$bucket_counts = LNKup_Vendor_Dashboard_Data::request_bucket_counts( $applications, $user_id );
		if ( (int) $bucket_counts['booked'] > 0 ) {
			$greeting = __( 'Get ready for your next event.', 'lnkup-marketplace' );
		} elseif ( $applications ) {
			$greeting = __( 'Your vendor business, all in one place.', 'lnkup-marketplace' );
		} else {
			$greeting = __( 'Find your next opportunity.', 'lnkup-marketplace' );
		}

		$completed_count = (int) ( $bucket_counts['completed'] ?? 0 );
		if ( ! $applications ) {
			$stage = 'new';
		} elseif ( (int) $bucket_counts['booked'] > 0 ) {
			$stage = 'booked';
		} elseif ( $completed_count >= 2 ) {
			$stage = 'experienced';
		} else {
			$stage = 'applying';
		}
		$favorites   = class_exists( 'LNKup_Vendor_Personalization' ) ? LNKup_Vendor_Personalization::get_favorites( $user_id ) : array();
		$saved_count = count( (array) ( $favorites['events'] ?? array() ) ) + count( (array) ( $favorites['opportunities'] ?? array() ) );

		ob_start();
		self::render_vendor_notices( $user_id );
		$invites_html = (string) ob_get_clean();

		$getting_started = self::render_getting_started( $ctx );
		$spotlight       = self::render_spotlight( $week, $ops_url );
		$action_center   = $applications ? self::render_next_action( $priorities ) : '';

		$recommend_html = '';
		if ( $market_open && $recommended && function_exists( 'lnkup_render_opportunity_card_v2' ) ) {
			ob_start();
			?>
			<section class="vos-section" data-testid="lnkup-vendor-recommended" aria-labelledby="vos-recommended-title">
				<?php echo LNKup_Vendor_UI::section_head( __( 'Recommended for your business', 'lnkup-marketplace' ), __( 'Browse all', 'lnkup-marketplace' ), $find_url, 'vos-recommended-title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="lnkup-event-grid vos-card-grid">
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
			<?php
			$recommend_html = (string) ob_get_clean();
		}

		$discover_html = '';
		if ( ! $spotlight && $market_open ) {
			ob_start();
			?>
			<section class="vos-discover" data-testid="lnkup-vendor-discover">
				<div>
					<p class="vos-eyebrow"><?php esc_html_e( 'Marketplace', 'lnkup-marketplace' ); ?></p>
					<h2 class="vos-discover__title"><?php esc_html_e( 'Find festivals, markets, and pop-ups looking for vendors', 'lnkup-marketplace' ); ?></h2>
					<p class="vos-discover__desc"><?php esc_html_e( 'Apply for free. You only pay a booth fee when an organizer accepts you.', 'lnkup-marketplace' ); ?></p>
				</div>
				<a class="lnkup-btn lnkup-btn--primary" href="<?php echo esc_url( $find_url ); ?>"><?php esc_html_e( 'Explore opportunities', 'lnkup-marketplace' ); ?></a>
			</section>
			<?php
			$discover_html = (string) ob_get_clean();
		}

		$metrics_html = $applications ? LNKup_Vendor_UI::metrics(
			array(
				array(
					'key'   => 'needs-action',
					'label' => __( 'Payments due', 'lnkup-marketplace' ),
					'value' => (string) $bucket_counts['pay-now'],
					'hint'  => __( 'Upcoming events', 'lnkup-marketplace' ),
					'href'  => add_query_arg( 'app_filter', 'pay-now', $apps_url ),
					'tone'  => (int) $bucket_counts['pay-now'] > 0 ? 'warning' : '',
				),
				array(
					'key'   => 'active',
					'label' => __( 'In review', 'lnkup-marketplace' ),
					'value' => (string) $bucket_counts['in-review'],
					'hint'  => __( 'Waiting on organizers', 'lnkup-marketplace' ),
					'href'  => add_query_arg( 'app_filter', 'in-review', $apps_url ),
				),
				array(
					'key'   => 'confirmed',
					'label' => __( 'Upcoming bookings', 'lnkup-marketplace' ),
					'value' => (string) $bucket_counts['booked'],
					'hint'  => __( 'Confirmed, not past', 'lnkup-marketplace' ),
					'href'  => add_query_arg( 'app_filter', 'booked', $apps_url ),
				),
				array(
					'key'   => 'saved',
					'label' => __( 'Saved', 'lnkup-marketplace' ),
					'value' => (string) $saved_count,
					'hint'  => __( 'Events and booths', 'lnkup-marketplace' ),
					'href'  => lnkup_page_url_helper( 'favorites' ),
				),
			),
			'lnkup-vendor-home-stats'
		) : '';

		ob_start();
		?>
		<div class="lnkup-mp lnkup-vendor-dash vos-home" data-vos-stage="<?php echo esc_attr( $stage ); ?>">
			<?php echo self::render_payment_return_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo self::render_home_welcome( $ctx, $display_name, $subtitle, $market_open ? $find_url : '', $greeting ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<div class="vos-layout">
				<div class="vos-main">
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
					if ( 'booked' === $stage ) {
						echo $spotlight . $action_center . $recommend_html;
					} elseif ( 'new' === $stage ) {
						echo $discover_html . $recommend_html;
					} else {
						echo $action_center . $spotlight . $discover_html . $recommend_html;
					}
					if ( $applications ) {
						echo self::render_recent_activity( $user_id, $applications, $apps_url );
					}
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>
				<aside class="vos-rail" aria-label="<?php esc_attr_e( 'Your business at a glance', 'lnkup-marketplace' ); ?>">
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $metrics_html;
					echo $invites_html;
					echo $getting_started;
					if ( '' === trim( (string) $getting_started ) && (int) $week['profile_strength'] < 80 ) {
						echo self::render_profile_strength_card( $user_id, (int) $profile_id );
					}
					if ( $profile_id ) {
						echo self::render_trust_status_card( (int) $profile_id );
					}
					if ( class_exists( 'LNKup_App_Promo' ) ) {
						echo LNKup_App_Promo::render_card( 'vendor' );
					}
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</aside>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Upcoming confirmed event spotlight with image, countdown, readiness, and pass links.
	 *
	 * @param array  $week    Output of week_snapshot().
	 * @param string $ops_url Vendor operations URL.
	 */
	private static function render_spotlight( $week, $ops_url ) {
		$app_id = (int) ( $week['next_application_id'] ?? 0 );
		if ( ! $app_id ) {
			return '';
		}
		$app  = get_post( $app_id );
		$meta = $app instanceof WP_Post ? LNKup_Vendor_Dashboard_Data::application_event_meta( $app ) : array();
		if ( ! $meta ) {
			return '';
		}
		$event_id  = (int) ( $meta['event_id'] ?? 0 );
		$title     = (string) $meta['event_title'];
		$days      = LNKup_Vendor_UI::days_until( (string) $meta['date'] );
		$readiness = (int) $week['readiness_percent'];
		$organizer = LNKup_Vendor_UI::organizer_name( $event_id );
		$place     = (string) ( $meta['place'] ?? '' );
		$base      = add_query_arg( 'app', $app_id, $ops_url );
		ob_start();
		?>
		<section class="vos-spotlight" data-testid="lnkup-vendor-next-event" aria-labelledby="vos-spotlight-title">
			<div class="vos-spotlight__media"><?php echo LNKup_Vendor_UI::event_image( $event_id, $title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="vos-spotlight__body">
				<div class="vos-spotlight__top">
					<p class="vos-eyebrow"><?php esc_html_e( 'Your next event', 'lnkup-marketplace' ); ?></p>
					<?php if ( null !== $days ) : ?>
						<span class="vos-countdown"><?php echo esc_html( 0 === $days ? __( 'Today', 'lnkup-marketplace' ) : sprintf( /* translators: %d: days */ _n( '%d day to go', '%d days to go', $days, 'lnkup-marketplace' ), $days ) ); ?></span>
					<?php endif; ?>
				</div>
				<h2 class="vos-spotlight__title" id="vos-spotlight-title"><?php echo esc_html( $title ); ?></h2>
				<p class="vos-spotlight__meta"><?php echo esc_html( implode( ' · ', array_filter( array( LNKup_Vendor_UI::date( (string) $meta['date'] ), $place, $organizer ) ) ) ); ?></p>
				<div class="vos-spotlight__readiness">
					<span><?php echo esc_html( sprintf( /* translators: %d: percent */ __( 'Event prep %d%% complete', 'lnkup-marketplace' ), $readiness ) ); ?></span>
					<?php echo LNKup_Vendor_UI::progress( $readiness, __( 'Event preparation', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<?php if ( (float) $week['outstanding_amount'] > 0 ) : ?>
					<p class="vos-spotlight__due"><?php echo LNKup_Vendor_UI::status( sprintf( /* translators: %s: amount */ __( '%s due across your bookings', 'lnkup-marketplace' ), lnkup_currency( (float) $week['outstanding_amount'] ) ), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
				<?php endif; ?>
				<div class="vos-spotlight__actions">
					<a class="lnkup-btn lnkup-btn--primary" href="<?php echo esc_url( add_query_arg( 'tab', 'readiness', $base ) ); ?>"><?php esc_html_e( 'Prepare for event', 'lnkup-marketplace' ); ?></a>
					<a class="lnkup-btn lnkup-btn--outline" href="<?php echo esc_url( add_query_arg( 'tab', 'pass', $base ) ); ?>"><?php esc_html_e( 'Event pass', 'lnkup-marketplace' ); ?></a>
					<a class="vos-link" href="<?php echo esc_url( add_query_arg( 'tab', 'map', $base ) ); ?>"><?php esc_html_e( 'Booth & venue', 'lnkup-marketplace' ); ?></a>
				</div>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Compact personalized welcome: logo, business name, location, one primary action.
	 *
	 * @param array  $ctx          Dashboard context.
	 * @param string $display_name Business or display name.
	 * @param string $subtitle     Location or profile prompt.
	 * @param string $find_url     Find events URL ('' before launch).
	 * @param string $greeting     Lifecycle greeting.
	 */
	private static function render_home_welcome( $ctx, $display_name, $subtitle, $find_url, $greeting = '' ) {
		$profile_id = (int) ( $ctx['profile_id'] ?? 0 );
		$logo_id    = $profile_id ? (int) get_post_meta( $profile_id, '_lnkup_logo_attachment_id', true ) : 0;
		$logo_url   = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '';
		$initial    = strtoupper( substr( trim( (string) $display_name ), 0, 1 ) );
		$profile_url = lnkup_page_url_helper( 'vendor-profile' );
		ob_start();
		?>
		<header class="vos-hero" data-testid="lnkup-vendor-home-welcome">
			<div class="vos-hero__identity">
				<?php if ( $logo_url ) : ?>
					<img class="vos-hero__logo" src="<?php echo esc_url( $logo_url ); ?>" alt="" width="56" height="56" decoding="async" />
				<?php else : ?>
					<span class="vos-hero__logo vos-hero__logo--initial" aria-hidden="true"><?php echo esc_html( $initial ?: 'L' ); ?></span>
				<?php endif; ?>
				<div class="vos-hero__copy">
					<p class="vos-hero__business" title="<?php echo esc_attr( $display_name ); ?>"><?php echo esc_html( $display_name ); ?><?php if ( $subtitle ) : ?><span class="vos-hero__place"> · <?php echo esc_html( $subtitle ); ?></span><?php endif; ?></p>
					<h1 class="vos-hero__title"><?php echo esc_html( $greeting ? $greeting : __( 'Find your next opportunity.', 'lnkup-marketplace' ) ); ?></h1>
				</div>
			</div>
			<div class="vos-hero__actions">
				<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Edit profile', 'lnkup-marketplace' ); ?></a>
				<?php if ( $find_url ) : ?>
					<a class="lnkup-btn lnkup-btn--primary" href="<?php echo esc_url( $find_url ); ?>"><?php esc_html_e( 'Find events', 'lnkup-marketplace' ); ?></a>
				<?php endif; ?>
			</div>
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
		$now     = array();
		$history = array();
		foreach ( (array) $priorities as $item ) {
			if ( 'history' === ( $item['group'] ?? '' ) ) {
				$history[] = $item;
			} else {
				$now[] = $item;
			}
		}
		ob_start();
		?>
		<section class="vos-actions" data-testid="lnkup-action-center" aria-labelledby="vos-actions-title">
			<div class="vos-section-head">
				<h2 class="vos-section-head__title" id="vos-actions-title"><?php esc_html_e( 'Needs your attention', 'lnkup-marketplace' ); ?></h2>
				<?php if ( $now ) : ?>
					<span class="vos-count"><?php echo esc_html( (string) count( $now ) ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( $now ) : ?>
				<ol class="vos-action-list">
					<?php foreach ( $now as $index => $item ) : ?>
						<?php echo self::render_action_item( $item, 0 === $index ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				</ol>
			<?php else : ?>
				<div class="vos-clear">
					<span class="vos-clear__icon" aria-hidden="true">✓</span>
					<div>
						<p class="vos-clear__title"><?php esc_html_e( 'You are all caught up', 'lnkup-marketplace' ); ?></p>
						<p class="vos-clear__desc"><?php esc_html_e( 'Nothing needs your attention for upcoming events. Payments, documents, and event prep appear here.', 'lnkup-marketplace' ); ?></p>
					</div>
				</div>
			<?php endif; ?>
			<?php if ( $history ) : ?>
				<div class="vos-history">
					<p class="vos-history__label"><?php esc_html_e( 'Past events', 'lnkup-marketplace' ); ?></p>
					<ol class="vos-action-list vos-action-list--quiet">
						<?php foreach ( $history as $item ) : ?>
							<?php echo self::render_action_item( $item, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					</ol>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * One Action Center row.
	 *
	 * @param array $item  Priority item.
	 * @param bool  $first Highlight the first item with a primary button.
	 */
	private static function render_action_item( $item, $first ) {
		$level = (string) ( $item['level'] ?? 'info' );
		$tone  = 'urgent' === $level ? 'warning' : ( 'warn' === $level ? 'warning' : 'info' );
		$btn   = $first ? 'lnkup-btn--primary' : 'lnkup-btn--outline';
		ob_start();
		?>
		<li class="vos-action vos-action--<?php echo esc_attr( $tone ); ?>">
			<span class="vos-action__icon" aria-hidden="true"><?php echo 'warning' === $tone ? '!' : '•'; ?></span>
			<div class="vos-action__copy">
				<p class="vos-action__title"><?php echo esc_html( (string) $item['title'] ); ?></p>
				<?php if ( ! empty( $item['meta'] ) ) : ?>
					<p class="vos-action__meta"><?php echo esc_html( (string) $item['meta'] ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $item['payment_id'] ) && ! empty( $item['primary'] ) ) : ?>
				<button type="button" class="lnkup-btn <?php echo esc_attr( $btn ); ?> lnkup-btn--sm lnkup-pay-btn" data-payment-id="<?php echo esc_attr( (string) $item['payment_id'] ); ?>"><?php echo esc_html( (string) $item['action_label'] ); ?></button>
			<?php elseif ( ! empty( $item['action_href'] ) ) : ?>
				<a class="lnkup-btn <?php echo esc_attr( $btn ); ?> lnkup-btn--sm" href="<?php echo esc_url( (string) $item['action_href'] ); ?>"><?php echo esc_html( (string) $item['action_label'] ); ?></a>
			<?php endif; ?>
		</li>
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
			$raw_items = is_array( $result ) ? (array) ( $result['items'] ?? array() ) : array();
			$grouped   = array();
			foreach ( $raw_items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$key = strtolower( trim( (string) ( $item['title'] ?? '' ) ) );
				if ( '' === $key ) {
					$key = 'item-' . count( $grouped );
				}
				if ( ! isset( $grouped[ $key ] ) ) {
					$item['count']   = 1;
					$grouped[ $key ] = $item;
				} else {
					++$grouped[ $key ]['count'];
				}
			}
			$items = array_values( $grouped );
		}
		ob_start();
		?>
		<section class="vos-section vos-activity" data-testid="lnkup-vendor-recent-activity">
			<?php echo LNKup_Vendor_UI::section_head( $items ? __( 'Recent activity', 'lnkup-marketplace' ) : __( 'Recent bookings', 'lnkup-marketplace' ), __( 'View all', 'lnkup-marketplace' ), $apps_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( $items ) : ?>
				<ol class="lnkup-activity-feed vos-feed">
					<?php foreach ( $items as $item ) : ?>
						<?php
						$ts   = ! empty( $item['createdAt'] ) ? strtotime( (string) $item['createdAt'] ) : 0;
						$href = (string) ( $item['href'] ?? '' );
						$href = ( $href && wp_validate_redirect( $href, '' ) ) ? $href : '';
						?>
						<li class="lnkup-activity-feed__item<?php echo empty( $item['readAt'] ) ? ' is-unread' : ''; ?>">
							<span class="vos-feed__icon vos-feed__icon--<?php echo esc_attr( 'urgent' === ( $item['severity'] ?? '' ) ? 'warning' : 'info' ); ?>" aria-hidden="true"><?php echo (int) ( $item['count'] ?? 1 ) > 1 ? esc_html( (string) (int) $item['count'] ) : '•'; ?></span>
							<div class="lnkup-activity-feed__body">
								<?php
								$activity_title = (string) $item['title'];
								if ( (int) ( $item['count'] ?? 1 ) > 1 ) {
									$activity_title = sprintf(
										/* translators: 1: count, 2: activity title */
										__( '%1$d updates: %2$s', 'lnkup-marketplace' ),
										(int) $item['count'],
										(string) $item['title']
									);
								}
								?>
								<?php if ( $href ) : ?>
									<a class="lnkup-activity-feed__title" href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( $activity_title ); ?></a>
								<?php else : ?>
									<p class="lnkup-activity-feed__title"><?php echo esc_html( $activity_title ); ?></p>
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
		if ( ! $invites ) {
			return;
		}
		$all_url = add_query_arg( array( 'view' => 'passport', 'tab' => 'invitations' ), lnkup_page_url_helper( 'vendor-dashboard' ) );
		?>
		<section class="vos-card vos-invites" data-testid="lnkup-vendor-invitations">
			<?php echo LNKup_Vendor_UI::section_head( sprintf( /* translators: %d: invitation count */ __( 'Invitations (%d)', 'lnkup-marketplace' ), count( $invites ) ), __( 'View all', 'lnkup-marketplace' ), $all_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<ul class="vos-invites__list">
				<?php foreach ( array_slice( $invites, 0, 3 ) as $invite ) : ?>
					<?php
					$token    = (string) get_post_meta( $invite->ID, '_lnkup_token', true );
					$event_id = (int) get_post_meta( $invite->ID, '_lnkup_event_id', true );
					$opp_id   = (int) get_post_meta( $invite->ID, '_lnkup_opportunity_id', true );
					if ( ! $event_id && $opp_id ) {
						$event_id = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
					}
					$detail    = self::invite_event_detail( $invite->ID );
					$parts     = array_map( 'trim', explode( '·', $detail ) );
					$name      = $parts[0] ?? '';
					$sub       = implode( ' · ', array_slice( $parts, 1 ) );
					$organizer = LNKup_Vendor_UI::organizer_name( $event_id );
					$link      = $token ? add_query_arg( 'invite', $token, lnkup_page_url_helper( 'opportunities' ) ) : lnkup_page_url_helper( 'opportunities' );
					?>
					<li class="vos-invite">
						<span class="vos-invite__media"><?php echo LNKup_Vendor_UI::event_image( $event_id, '' !== $name ? $name : __( 'Event invitation', 'lnkup-marketplace' ), $opp_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<div class="vos-invite__copy">
							<a class="vos-invite__title" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( '' !== $name ? $name : __( 'Event invitation', 'lnkup-marketplace' ) ); ?></a>
							<p class="vos-invite__meta"><?php echo esc_html( implode( ' · ', array_filter( array( $sub, $organizer ? sprintf( /* translators: %s: organizer */ __( 'from %s', 'lnkup-marketplace' ), $organizer ) : '' ) ) ) ); ?></p>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="vos-footnote"><?php esc_html_e( 'An invitation lets you apply. It does not reserve a booth.', 'lnkup-marketplace' ); ?></p>
		</section>
		<?php
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
		$event_name = class_exists( 'LNKup_Vendor_Presentation' )
			? LNKup_Vendor_Presentation::customer_event_title( $event_id )
			: ( ( $event instanceof WP_Post ) ? trim( (string) $event->post_title ) : '' );
		if ( __( 'Event details unavailable', 'lnkup-marketplace' ) === $event_name ) {
			$event_name = '';
		}
		$slot_name  = ( '' === $event_name && $slot instanceof WP_Post ) ? trim( (string) $slot->post_title ) : '';
		$date       = $event_id ? (string) get_post_meta( $event_id, '_lnkup_event_date', true ) : '';
		$city       = $event_id ? (string) get_post_meta( $event_id, '_lnkup_city', true ) : '';
		$state      = $event_id ? (string) get_post_meta( $event_id, '_lnkup_state', true ) : '';
		$where      = function_exists( 'lnkup_display_place' ) ? lnkup_display_place( $city, $state ) : trim( $city . ( $state ? ', ' . $state : '' ) );
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
		$detail_id    = isset( $_GET['app'] ) ? absint( $_GET['app'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $detail_id ) {
			foreach ( $applications as $owned ) {
				if ( (int) $owned->ID === $detail_id ) {
					return self::render_booking_detail( $owned, (int) $ctx['user_id'] );
				}
			}
		}
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
					'subtitle' => __( 'Applications, booth fees, upcoming events, and your history in one place.', 'lnkup-marketplace' ),
					'actions'  => '<a class="lnkup-btn lnkup-btn--outline" href="' . esc_url( $find_url ) . '">' . esc_html__( 'Explore', 'lnkup-marketplace' ) . '</a>',
				)
			);
			$request_tabs = array();
			foreach (
				array(
					'all'       => __( 'All', 'lnkup-marketplace' ),
					'pay-now'   => __( 'Needs action', 'lnkup-marketplace' ),
					'in-review' => __( 'Applications', 'lnkup-marketplace' ),
					'booked'    => __( 'Upcoming', 'lnkup-marketplace' ),
					'completed' => __( 'Past', 'lnkup-marketplace' ),
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
	 * Prefer the next future confirmed booking.
	 *
	 * @param array $apps Application posts.
	 */
	private static function default_operations_application( $apps ) {
		$best_id   = 0;
		$best_date = '';
		$today     = current_time( 'Y-m-d' );
		foreach ( $apps as $app ) {
			if ( ! $app instanceof WP_Post ) {
				continue;
			}
			$event_id = (int) get_post_meta( $app->ID, '_lnkup_event_id', true );
			$date     = $event_id ? (string) get_post_meta( $event_id, '_lnkup_event_date', true ) : '';
			if ( '' !== $date && $date >= $today && ( '' === $best_date || $date < $best_date ) ) {
				$best_id   = (int) $app->ID;
				$best_date = $date;
			}
		}
		if ( $best_id ) {
			return $best_id;
		}
		$first = reset( $apps );
		return $first instanceof WP_Post ? (int) $first->ID : 0;
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
			$selected = self::default_operations_application( $pass_apps );
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
				<?php
				$sel_where     = implode( ', ', array_filter( array( (string) $sel_meta['venue'], (string) ( $sel_meta['place'] ?? '' ) ) ) );
				$sel_event_id  = (int) ( $sel_meta['event_id'] ?? 0 );
				$sel_life      = class_exists( 'LNKup_Vendor_Presentation' ) ? LNKup_Vendor_Presentation::lifecycle( $selected, (int) $ctx['user_id'] ) : array();
				$sel_ready     = ( ! $pass_only && class_exists( 'LNKup_Vendor_Readiness' ) ) ? (int) ( LNKup_Vendor_Readiness::checklist( $selected )['percent'] ?? 0 ) : 0;
				$sel_organizer = LNKup_Vendor_UI::organizer_name( $sel_event_id );
				?>
				<header class="vos-ops-header" data-testid="lnkup-vendor-ops-header">
					<div class="vos-ops-header__media"><?php echo LNKup_Vendor_UI::event_image( $sel_event_id, (string) $sel_meta['event_title'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<div class="vos-ops-header__body">
						<div class="vos-detail__badges">
							<?php echo LNKup_Vendor_UI::status( (string) ( $sel_life['primary'] ?? '' ), $pass_only ? 'warning' : 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php if ( $countdown ) : ?>
								<span class="vos-countdown"><?php echo esc_html( $countdown ); ?></span>
							<?php endif; ?>
						</div>
						<h2 class="vos-ops-header__title"><?php echo esc_html( $sel_meta['event_title'] ); ?></h2>
						<p class="vos-ops-header__meta"><?php echo esc_html( implode( ' · ', array_filter( array( ! empty( $sel_meta['date'] ) ? date_i18n( 'l, F j, Y', strtotime( $sel_meta['date'] ) ) : __( 'Date not published yet', 'lnkup-marketplace' ), $sel_where, $sel_organizer ) ) ) ); ?></p>
						<?php if ( ! $pass_only ) : ?>
							<div class="vos-ops-header__ready">
								<span><?php echo esc_html( sprintf( /* translators: %d: percent */ __( 'Event prep %d%%', 'lnkup-marketplace' ), $sel_ready ) ); ?></span>
								<?php echo LNKup_Vendor_UI::progress( $sel_ready, __( 'Event preparation', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						<?php endif; ?>
					</div>
					<?php if ( count( $pass_apps ) > 1 ) : ?>
						<label class="vos-ops-switcher">
							<span><?php esc_html_e( 'Switch event', 'lnkup-marketplace' ); ?></span>
							<select id="lnkup-vendor-ops-event-select">
								<?php foreach ( $pass_apps as $app ) : ?>
									<?php $option_meta = LNKup_Vendor_Dashboard_Data::application_event_meta( $app ); ?>
									<option value="<?php echo esc_attr( (string) $app->ID ); ?>"<?php selected( $selected, $app->ID ); ?>><?php echo esc_html( (string) $option_meta['event_title'] . ( ! empty( $option_meta['date'] ) ? ' · ' . date_i18n( 'M j', strtotime( $option_meta['date'] ) ) : '' ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>
				</header>
				<?php
				$pass_only_payment = $pass_only ? LNKup_Vendor_Dashboard_Data::application_payment_for_vendor( $selected ) : null;
				?>
				<?php if ( $pass_only_payment && LNKup_Vendor_Dashboard_Data::application_is_payable( $selected ) ) : ?>
					<div class="vos-action vos-action--warning vos-ops-pay" data-testid="lnkup-vendor-ops-pay">
						<span class="vos-action__icon" aria-hidden="true">!</span>
						<div class="vos-action__copy"><p class="vos-action__title"><?php esc_html_e( 'Pay your booth fee to unlock event prep', 'lnkup-marketplace' ); ?></p><p class="vos-action__meta"><?php esc_html_e( 'Readiness, your booth map, load-in details, and your check-in pass open after payment.', 'lnkup-marketplace' ); ?></p></div>
						<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm lnkup-pay-btn" data-payment-id="<?php echo esc_attr( (string) $pass_only_payment->ID ); ?>"><?php esc_html_e( 'Pay now', 'lnkup-marketplace' ); ?></button>
					</div>
				<?php endif; ?>
				<div class="lnkup-vendor-ops" id="lnkup-vendor-ops" data-app-id="<?php echo esc_attr( (string) $selected ); ?>"<?php echo $pass_only ? ' data-pass-only="1"' : ''; ?>>

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
							<div class="lnkup-ops-readiness-shell vos-card">
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
