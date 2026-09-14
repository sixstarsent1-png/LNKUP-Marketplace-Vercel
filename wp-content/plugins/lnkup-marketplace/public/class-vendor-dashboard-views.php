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
	 * Executive dashboard hero for vendor home.
	 *
	 * @param string $display_name Business or user name.
	 * @param array  $week         Week snapshot.
	 * @param float  $earned       Season earnings.
	 * @param array  $rep          Reputation payload.
	 * @param string $primary_url  Primary CTA URL.
	 * @param string $primary_label Primary CTA label.
	 * @param string $secondary_url Secondary CTA URL.
	 * @param string $secondary_label Secondary CTA label.
	 * @param string $subtitle     Location/subtitle line.
	 */
	public static function render_vendor_dash_hero( $display_name, $week, $earned, $rep, $primary_url, $primary_label, $secondary_url, $secondary_label, $subtitle ) {
		$greeting = LNKup_Vendor_Dashboard_Data::greeting_label();
		$rep_label = ! empty( $rep['is_new'] ) ? __( 'New vendor', 'lnkup-marketplace' ) : ( ! empty( $rep['tier_label'] ) ? $rep['tier_label'] : ( ! empty( $rep['score'] ) ? $rep['score'] . '/5' : '—' ) );
		ob_start();
		?>
		<header class="lnkup-dash-hero">
			<div class="lnkup-dash-hero__top">
				<div>
					<h1 class="lnkup-dash-hero__greeting"><?php echo esc_html( $greeting . ', ' . $display_name ); ?></h1>
					<p class="lnkup-dash-hero__subtitle"><?php echo esc_html( $subtitle ); ?></p>
				</div>
				<div class="lnkup-dash-hero__ctas">
					<a href="<?php echo esc_url( $primary_url ); ?>" class="lnkup-btn lnkup-btn--primary"><?php echo esc_html( $primary_label ); ?></a>
					<a href="<?php echo esc_url( $secondary_url ); ?>" class="lnkup-btn lnkup-btn--outline"><?php echo esc_html( $secondary_label ); ?></a>
				</div>
			</div>
			<div class="lnkup-dash-hero__metrics">
				<span class="lnkup-dash-hero__metric"><?php esc_html_e( 'Profile', 'lnkup-marketplace' ); ?> <strong><?php echo esc_html( (string) (int) $week['profile_strength'] ); ?>%</strong></span>
				<span class="lnkup-dash-hero__metric"><?php esc_html_e( 'Reputation', 'lnkup-marketplace' ); ?> <strong><?php echo esc_html( (string) $rep_label ); ?></strong></span>
				<span class="lnkup-dash-hero__metric"><?php esc_html_e( 'Earned', 'lnkup-marketplace' ); ?> <strong><?php echo esc_html( lnkup_currency( $earned ) ); ?></strong></span>
				<?php if ( $week['next_event_date'] ) : ?>
					<span class="lnkup-dash-hero__metric"><?php esc_html_e( 'Next event', 'lnkup-marketplace' ); ?> <strong><?php echo esc_html( date_i18n( 'M j', strtotime( $week['next_event_date'] ) ) ); ?></strong></span>
				<?php endif; ?>
			</div>
		</header>
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
	 * Today's priorities panel.
	 *
	 * @param array $priorities Priority items from data helper.
	 */
	public static function render_priority_panel( $priorities ) {
		$icons = array(
			'payment' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/></svg>',
			'docs'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>',
			'calendar'=> '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
			'app'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
		);
		ob_start();
		?>
		<div class="lnkup-panel lnkup-priority-panel lnkup-surface--primary" data-testid="lnkup-action-center">
			<h2 class="lnkup-priority-panel__title"><?php esc_html_e( 'Action Center', 'lnkup-marketplace' ); ?></h2>
			<p class="lnkup-type-meta"><?php esc_html_e( "What requires your attention today", 'lnkup-marketplace' ); ?></p>
			<?php if ( $priorities ) : ?>
				<ul class="lnkup-priority-panel__list">
					<?php foreach ( $priorities as $item ) : ?>
						<?php
						$icon_key = ! empty( $item['icon'] ) ? $item['icon'] : 'app';
						if ( ! empty( $item['payment_id'] ) ) {
							$icon_key = 'payment';
						} elseif ( false !== stripos( $item['title'], 'document' ) ) {
							$icon_key = 'docs';
						} elseif ( false !== stripos( $item['title'], 'load-in' ) || false !== stripos( $item['title'], 'upcoming' ) ) {
							$icon_key = 'calendar';
						}
						$icon_html = function_exists( 'lnkup_inline_icon' )
							? lnkup_inline_icon( $icons[ $icon_key ] ?? $icons['app'], 18 )
							: ( $icons[ $icon_key ] ?? $icons['app'] );
						?>
						<li class="lnkup-priority-item lnkup-micro-lift">
							<span class="lnkup-priority-item__dot lnkup-priority-item__dot--<?php echo esc_attr( $item['level'] ); ?>" aria-hidden="true"></span>
							<span class="lnkup-priority-item__icon" aria-hidden="true"><?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<div class="lnkup-priority-item__body">
								<p class="lnkup-priority-item__title"><?php echo esc_html( $item['title'] ); ?></p>
								<?php if ( ! empty( $item['meta'] ) ) : ?>
									<p class="lnkup-priority-item__meta"><?php echo esc_html( $item['meta'] ); ?></p>
								<?php endif; ?>
							</div>
							<div class="lnkup-priority-item__action">
								<?php if ( ! empty( $item['payment_id'] ) && ! empty( $item['primary'] ) ) : ?>
									<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm lnkup-pay-btn" data-payment-id="<?php echo esc_attr( (string) $item['payment_id'] ); ?>"><?php echo esc_html( $item['action_label'] ); ?></button>
								<?php else : ?>
									<a href="<?php echo esc_url( $item['action_href'] ); ?>" class="lnkup-btn lnkup-btn--sm <?php echo ! empty( $item['primary'] ) ? 'lnkup-btn--primary' : 'lnkup-btn--ghost'; ?>"><?php echo esc_html( $item['action_label'] ); ?></a>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<?php
				echo lnkup_empty_state(
					array(
						'title'   => __( 'You are all caught up', 'lnkup-marketplace' ),
						'desc'    => __( 'No urgent actions today. Browse events or strengthen your profile.', 'lnkup-marketplace' ),
						'compact' => true,
					)
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Application workflow card.
	 *
	 * @param WP_Post $app       Application post.
	 * @param string  $dashboard Dashboard base URL.
	 */
	public static function render_application_workflow_card( $app, $dashboard ) {
		$meta    = LNKup_Vendor_Dashboard_Data::application_event_meta( $app );
		$status  = get_post_meta( $app->ID, '_lnkup_application_status', true );
		$tab     = LNKup_Vendor_Dashboard_Data::application_tab( $status );
		$payment = LNKup_Payments::get_for_application( $app->ID );
		$steps   = LNKup_Vendor_Dashboard_Data::application_workflow_steps( $status );
		$filter_tab = in_array( $status, array( 'paid', 'completed' ), true ) ? 'booked' : $tab;
		ob_start();
		?>
		<article class="lnkup-workflow-card lnkup-micro-lift" data-app-tab="<?php echo esc_attr( $tab ); ?>" data-app-filter="<?php echo esc_attr( $filter_tab ); ?>">
			<div class="lnkup-workflow-card__head">
				<div>
					<h3 class="lnkup-workflow-card__title"><?php echo esc_html( $meta['event_title'] ); ?></h3>
					<p class="lnkup-workflow-card__meta">
						<?php
						echo esc_html(
							trim(
								( $meta['date'] ? date_i18n( 'M j, Y', strtotime( $meta['date'] ) ) . ' · ' : '' ) .
								trim( $meta['city'] . ( $meta['state'] ? ', ' . $meta['state'] : '' ) ) .
								' · ' . lnkup_currency( $meta['fee'] )
							)
						);
						?>
					</p>
				</div>
				<span class="lnkup-status lnkup-status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( LNKup_Vendor_Dashboard_Data::application_status_label( $status ) ); ?></span>
			</div>
			<div class="lnkup-workflow-timeline">
				<?php foreach ( $steps as $step ) : ?>
					<div class="lnkup-workflow-step<?php echo 'done' === $step['state'] ? ' is-done' : ( 'current' === $step['state'] ? ' is-current' : '' ); ?>">
						<span class="lnkup-workflow-step__label"><?php echo esc_html( $step['label'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="lnkup-workflow-card__actions">
				<?php if ( $payment && 'pending' === get_post_meta( $payment->ID, '_lnkup_payment_status', true ) ) : ?>
					<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm lnkup-pay-btn" data-payment-id="<?php echo esc_attr( (string) $payment->ID ); ?>"><?php esc_html_e( 'Pay now', 'lnkup-marketplace' ); ?></button>
				<?php elseif ( in_array( $status, array( 'paid', 'completed' ), true ) ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'operations', 'app' => $app->ID ), $dashboard ) ); ?>" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'Event operations', 'lnkup-marketplace' ); ?></a>
				<?php else : ?>
					<?php
					$opp_id  = (int) get_post_meta( $app->ID, '_lnkup_opportunity_id', true );
					$opp_url = $opp_id ? get_permalink( $opp_id ) : '';
					if ( ! $opp_url ) {
						$event_id = (int) get_post_meta( $app->ID, '_lnkup_event_id', true );
						$opp_url  = $event_id ? get_permalink( $event_id ) : '';
					}
					?>
					<?php if ( $opp_url ) : ?>
						<a href="<?php echo esc_url( $opp_url ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php esc_html_e( 'View opportunity', 'lnkup-marketplace' ); ?></a>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</article>
		<?php
		return ob_get_clean();
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
		$primary_url   = $week['outstanding_count'] > 0 ? $payments_url : $find_url;
		$primary_label = $week['outstanding_count'] > 0 ? __( 'Complete payment', 'lnkup-marketplace' ) : __( 'Find events', 'lnkup-marketplace' );
		$secondary_url = $week['profile_strength'] < 80 ? $profile_url : $apps_url;
		$secondary_label = $week['profile_strength'] < 80 ? __( 'Complete profile', 'lnkup-marketplace' ) : __( 'View applications', 'lnkup-marketplace' );
		$market_open   = lnkup_can_browse_marketplace();

		ob_start();
		?>
		<div class="lnkup-mp lnkup-vendor-dash">
			<?php if ( isset( $_GET['lnkup_payment'] ) && 'success' === $_GET['lnkup_payment'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="lnkup-alert lnkup-alert--success"><?php esc_html_e( 'Payment successful! Your packet is unlocked.', 'lnkup-marketplace' ); ?></div>
			<?php endif; ?>

			<?php self::render_vendor_notices( $user_id ); ?>

			<?php
			$rep_label = ! empty( $rep['is_new'] ) ? __( 'New vendor', 'lnkup-marketplace' ) : ( ! empty( $rep['tier_label'] ) ? $rep['tier_label'] : ( ! empty( $rep['avg_rating'] ) ? number_format( (float) $rep['avg_rating'], 1 ) . '/5' : '—' ) );
			$hero_metrics = array(
				array( 'label' => __( 'Reputation', 'lnkup-marketplace' ), 'value' => $rep_label ),
				array( 'label' => __( 'Earned', 'lnkup-marketplace' ), 'value' => lnkup_currency( $earned ) ),
				array( 'label' => __( 'Applications', 'lnkup-marketplace' ), 'value' => (string) $active_count ),
			);
			if ( $week['next_event_date'] ) {
				$hero_metrics[] = array( 'label' => __( 'Next event', 'lnkup-marketplace' ), 'value' => date_i18n( 'M j', strtotime( $week['next_event_date'] ) ) );
			}
			echo lnkup_executive_hero(
				array(
					'title'         => LNKup_Vendor_Dashboard_Data::greeting_label() . ', ' . $display_name,
					'subtitle'      => $subtitle,
					'kpi_label'     => __( 'Profile strength', 'lnkup-marketplace' ),
					'kpi'           => (string) (int) $week['profile_strength'] . '%',
					'ring_pct'      => (int) $week['profile_strength'],
					'metrics'       => $hero_metrics,
					'primary_cta'   => array( 'label' => $primary_label, 'href' => $primary_url ),
					'secondary_cta' => array( 'label' => $secondary_label, 'href' => $secondary_url ),
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::render_priority_panel( $priorities ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>

			<?php if ( (int) $week['outstanding_count'] > 0 ) : ?>
			<div class="lnkup-vendor-due-strip lnkup-surface--primary" data-testid="lnkup-vendor-due-strip">
				<div class="lnkup-vendor-due-strip__main">
					<p class="lnkup-type-label"><?php esc_html_e( 'Wallet', 'lnkup-marketplace' ); ?></p>
					<p class="lnkup-vendor-due-strip__value"><?php echo esc_html( lnkup_currency( $week['outstanding_amount'] ) ); ?></p>
					<p class="lnkup-vendor-due-strip__meta lnkup-muted">
						<?php
						printf(
							/* translators: %d: number of invoices due */
							esc_html( _n( '%d payment due', '%d payments due', (int) $week['outstanding_count'], 'lnkup-marketplace' ) ),
							(int) $week['outstanding_count']
						);
						?>
					</p>
				</div>
				<div class="lnkup-vendor-due-strip__actions">
					<a href="<?php echo esc_url( $payments_url ); ?>" class="lnkup-btn lnkup-btn--primary">
						<?php esc_html_e( 'Pay now', 'lnkup-marketplace' ); ?>
					</a>
				</div>
			</div>
			<?php endif; ?>

			<?php if ( $attention ) : ?>
			<section class="lnkup-vendor-section lnkup-section lnkup-section--tight">
				<div class="lnkup-section__header">
					<h2 class="lnkup-section__title"><?php esc_html_e( 'Applications needing attention', 'lnkup-marketplace' ); ?></h2>
					<a href="<?php echo esc_url( $apps_url ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php esc_html_e( 'View all', 'lnkup-marketplace' ); ?></a>
				</div>
				<div class="lnkup-workflow-grid">
					<?php foreach ( array_slice( $attention, 0, 2 ) as $app ) : ?>
						<?php echo self::render_application_workflow_card( $app, lnkup_page_url_helper( 'vendor-dashboard' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				</div>
			</section>
			<?php endif; ?>

			<?php if ( $week['next_event_title'] && $week['next_application_id'] ) : ?>
			<section class="lnkup-vendor-section lnkup-section lnkup-section--tight" id="upcoming">
				<div class="lnkup-panel lnkup-vendor-upcoming-hero lnkup-surface--primary lnkup-micro-lift">
					<div class="lnkup-vendor-upcoming-hero__main">
						<p class="lnkup-type-label"><?php esc_html_e( 'Upcoming event', 'lnkup-marketplace' ); ?></p>
						<h2 class="lnkup-type-h2"><?php echo esc_html( $week['next_event_title'] ); ?></h2>
						<?php if ( $week['next_event_date'] ) : ?>
							<p class="lnkup-vendor-upcoming-hero__date lnkup-type-meta"><?php echo esc_html( date_i18n( 'l, F j, Y', strtotime( $week['next_event_date'] ) ) ); ?></p>
						<?php endif; ?>
						<p class="lnkup-vendor-upcoming-hero__readiness"><?php printf( esc_html__( '%d%% event ready', 'lnkup-marketplace' ), (int) $week['readiness_percent'] ); ?></p>
					</div>
					<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'operations', 'app' => $week['next_application_id'] ), lnkup_page_url_helper( 'vendor-dashboard' ) ) ); ?>" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Event operations', 'lnkup-marketplace' ); ?></a>
				</div>
			</section>
			<?php endif; ?>

			<?php echo self::render_getting_started( $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<section class="lnkup-vendor-section lnkup-section lnkup-section--tight" id="lnkup-vendor-kpi-strip" data-testid="lnkup-vendor-kpis">
				<div class="lnkup-section__header">
					<h2 class="lnkup-section__title"><?php esc_html_e( 'Business health', 'lnkup-marketplace' ); ?></h2>
				</div>
				<div class="lnkup-grid lnkup-grid--4 lnkup-vendor-dash__stats">
				<?php
				$apps_week   = LNKup_Vendor_Dashboard_Data::applications_this_week( $applications );
				$apps_trend  = $apps_week > 0
					? sprintf( _n( '+%d this week', '+%d this week', $apps_week, 'lnkup-marketplace' ), $apps_week )
					: __( 'No new apps this week', 'lnkup-marketplace' );
				$apps_t_dir  = $apps_week > 0 ? 'up' : 'neutral';
				$book_trend  = $week['next_event_date']
					? sprintf( __( 'Next: %s', 'lnkup-marketplace' ), date_i18n( 'M j', strtotime( $week['next_event_date'] ) ) )
					: __( 'No upcoming events', 'lnkup-marketplace' );
				$book_t_dir  = $week['next_event_date'] ? 'up' : 'neutral';
				$doc_t_dir   = $compliance['attention_count'] > 0 ? 'down' : 'neutral';
				echo self::stat_card( __( 'Active applications', 'lnkup-marketplace' ), (string) $active_count, $apps_trend, 'apps', 0 === $active_count, $apps_t_dir, $apps_url, 'apps', (string) $active_count ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::stat_card( __( 'Confirmed bookings', 'lnkup-marketplace' ), (string) $bookings, $book_trend, 'star', 0 === $bookings, $book_t_dir, $apps_url, 'star', (string) $bookings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::stat_card( __( 'Earned this season', 'lnkup-marketplace' ), lnkup_currency( $earned ), __( 'Season to date', 'lnkup-marketplace' ), 'cash', $earned <= 0, 'neutral', $payments_url, 'cash', (string) $earned, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::stat_card( __( 'Documents', 'lnkup-marketplace' ), $doc_value, $doc_trend, 'docs', 0 === (int) $doc_value && 0 === $compliance['attention_count'], $doc_t_dir, $docs_url, 'docs', $doc_value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				</div>
			</section>

			<div class="lnkup-vendor-section lnkup-vendor-health-slot">
				<?php
				// Hero already shows profile strength ring — only surface the strength card when no profile yet.
				if ( ! $profile_id ) {
					echo self::render_profile_strength_card( $user_id, $profile_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo self::render_trust_status_card( $profile_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</div>

			<section class="lnkup-vendor-section lnkup-section lnkup-section--tight">
				<div class="lnkup-section__header">
					<h2 class="lnkup-section__title"><?php esc_html_e( 'Quick actions', 'lnkup-marketplace' ); ?></h2>
				</div>
				<div class="lnkup-vendor-action-grid lnkup-vendor-action-grid--compact">
				<?php
				echo self::action_card( $find_url, __( 'Find Events', 'lnkup-marketplace' ), __( 'Browse and apply to opportunities', 'lnkup-marketplace' ), 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::action_card( $docs_url, __( 'Upload Documents', 'lnkup-marketplace' ), $doc_value . ' ' . __( 'items', 'lnkup-marketplace' ), 'docs' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::action_card( $ops_url, __( 'Event operations', 'lnkup-marketplace' ), __( 'Passes & day-of info', 'lnkup-marketplace' ), 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				</div>
			</section>

			<?php if ( ! $active_count && ! $bookings && ! $attention ) : ?>
				<div class="lnkup-panel lnkup-vendor-empty-home lnkup-surface--quiet">
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'Your vendor command center', 'lnkup-marketplace' ); ?></h2>
					<p class="lnkup-muted"><?php esc_html_e( 'Start by finding events, completing your profile, and uploading compliance documents.', 'lnkup-marketplace' ); ?></p>
					<a href="<?php echo esc_url( $find_url ); ?>" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Browse Find Events', 'lnkup-marketplace' ); ?></a>
				</div>
			<?php endif; ?>

			<?php
			if ( class_exists( 'LNKup_Organizer_Recruit_Render' ) ) {
				$referrals_url = add_query_arg( 'view', 'referrals', lnkup_page_url_helper( 'vendor-dashboard' ) );
				echo '<div class="lnkup-vendor-section lnkup-section lnkup-section--tight">' . LNKup_Organizer_Recruit_Render::home_cta( $referrals_url ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>

			<section class="lnkup-vendor-section lnkup-section lnkup-section--tight" id="recommendations">
				<div class="lnkup-section__header">
					<h2 class="lnkup-section__title"><?php esc_html_e( 'Discover opportunities', 'lnkup-marketplace' ); ?></h2>
					<a href="<?php echo esc_url( $find_url ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php esc_html_e( 'Browse all', 'lnkup-marketplace' ); ?></a>
				</div>
				<div id="lnkup-vendor-home-rails" class="lnkup-vendor-home-rails" <?php echo $market_open ? '' : 'data-marketplace-locked="1"'; ?>>
					<?php if ( $recommended ) : ?>
						<section class="lnkup-rail" data-rail-section="recommended">
							<div class="lnkup-rail__header">
								<h3 class="lnkup-rail__title"><?php esc_html_e( 'Recommended for you', 'lnkup-marketplace' ); ?></h3>
							</div>
							<div class="lnkup-rail__track" data-loaded="1">
								<?php foreach ( $recommended as $opp ) { echo lnkup_render_opportunity_card_v2( $opp ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						</section>
					<?php endif; ?>
					<?php if ( $market_open ) : ?>
						<section class="lnkup-rail" data-rail-section="closing_soon">
							<div class="lnkup-rail__header"><h3 class="lnkup-rail__title"><?php esc_html_e( 'Closing soon', 'lnkup-marketplace' ); ?></h3></div>
							<div class="lnkup-rail__track" data-rail="closing_soon"></div>
						</section>
						<section class="lnkup-rail" data-rail-section="trending">
							<div class="lnkup-rail__header"><h3 class="lnkup-rail__title"><?php esc_html_e( 'Trending', 'lnkup-marketplace' ); ?></h3></div>
							<div class="lnkup-rail__track" data-rail="trending"></div>
						</section>
						<section class="lnkup-rail" data-rail-section="recent">
							<div class="lnkup-rail__header"><h3 class="lnkup-rail__title"><?php esc_html_e( 'Recently added', 'lnkup-marketplace' ); ?></h3></div>
							<div class="lnkup-rail__track" data-rail="recent"></div>
						</section>
					<?php else : ?>
						<div class="lnkup-empty-state lnkup-empty-state--compact">
							<p class="lnkup-empty-state__title"><?php esc_html_e( 'Recommendations unlock at launch', 'lnkup-marketplace' ); ?></p>
							<p class="lnkup-empty-state__desc"><?php esc_html_e( 'Complete your profile now to be ready when opportunities go live.', 'lnkup-marketplace' ); ?></p>
							<a href="<?php echo esc_url( $profile_url ); ?>" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'Complete profile', 'lnkup-marketplace' ); ?></a>
						</div>
					<?php endif; ?>
				</div>
			</section>

			<?php if ( ! $profile_id ) : ?>
				<div class="lnkup-alert lnkup-alert--warning"><?php esc_html_e( 'Complete your vendor profile to start applying.', 'lnkup-marketplace' ); ?> <a href="<?php echo esc_url( lnkup_page_url_helper( 'vendor-profile' ) ); ?>"><?php esc_html_e( 'Create profile', 'lnkup-marketplace' ); ?></a></div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Vendor notices banner.
	 *
	 * @param int $user_id User ID.
	 */
	private static function render_vendor_notices( $user_id ) {
		$notices = get_user_meta( $user_id, 'lnkup_vendor_notices', true );
		if ( ! is_array( $notices ) || ! $notices || ! class_exists( 'LNKup_Vendor_Invites' ) ) {
			return;
		}
		foreach ( array_slice( $notices, 0, 5 ) as $notice ) {
			$invite_id = absint( $notice['invite_id'] ?? 0 );
			if ( ! $invite_id ) {
				continue;
			}
			$token    = get_post_meta( $invite_id, '_lnkup_token', true );
			$event_id = (int) get_post_meta( $invite_id, '_lnkup_event_id', true );
			$link     = $token ? add_query_arg( 'invite', $token, lnkup_page_url_helper( 'opportunities' ) ) : lnkup_page_url_helper( 'opportunities' );
			?>
			<div class="lnkup-alert lnkup-alert--info">
				<strong><?php esc_html_e( 'Event invite', 'lnkup-marketplace' ); ?></strong>
				<?php if ( $event_id ) : ?> — <?php echo esc_html( get_the_title( $event_id ) ); ?><?php endif; ?>
				<a href="<?php echo esc_url( $link ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary"><?php esc_html_e( 'View Invite', 'lnkup-marketplace' ); ?></a>
			</div>
			<?php
		}
	}

	/**
	 * Applications view.
	 */
	public static function render_applications() {
		if ( ! lnkup_can_browse_marketplace() ) {
			ob_start();
			?>
			<div class="lnkup-mp lnkup-vendor-dash" id="applications" data-testid="lnkup-prelaunch-applications">
				<header class="lnkup-dash-header">
					<h1 class="lnkup-dash-header__title"><?php echo esc_html( lnkup_copy( 'vendor_requests' ) ); ?></h1>
					<p class="lnkup-dash-header__subtitle"><?php esc_html_e( 'Applications open when the marketplace launches.', 'lnkup-marketplace' ); ?></p>
				</header>
				<div class="lnkup-empty-state">
					<p class="lnkup-empty-state__title"><?php echo esc_html( sprintf( __( 'Opening %s', 'lnkup-marketplace' ), lnkup_get_launch_date_label() ) ); ?></p>
					<p class="lnkup-empty-state__desc"><?php esc_html_e( 'Build your vendor profile and upload documents now so you are ready to apply on day one.', 'lnkup-marketplace' ); ?></p>
					<a href="<?php echo esc_url( lnkup_page_url_helper( 'vendor-profile' ) ); ?>" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Complete your profile', 'lnkup-marketplace' ); ?></a>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}
		$ctx          = LNKup_Vendor_Dashboard_Data::context();
		$applications = $ctx['applications'];
		$dashboard    = lnkup_page_url_helper( 'vendor-dashboard' );
		$action_count = 0;
		$active_count = 0;
		$booked_count = 0;
		foreach ( $applications as $app ) {
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			$tab    = LNKup_Vendor_Dashboard_Data::application_tab( $status );
			if ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
				++$booked_count;
			} elseif ( 'action' === $tab ) {
				++$action_count;
			} elseif ( 'active' === $tab ) {
				++$active_count;
			}
		}
		$find_url = lnkup_page_url_helper( 'opportunities' );

		ob_start();
		?>
		<div class="lnkup-mp lnkup-vendor-dash" id="applications">
			<?php
			echo lnkup_executive_hero(
				array(
					'title'         => lnkup_copy( 'vendor_requests' ),
					'subtitle'      => __( 'Track every event you\'ve applied to in one place', 'lnkup-marketplace' ),
					'kpi'           => (string) count( $applications ),
					'kpi_label'     => __( 'Total applications', 'lnkup-marketplace' ),
					'metrics'       => array(
						array( 'label' => __( 'Action needed', 'lnkup-marketplace' ), 'value' => (string) $action_count ),
						array( 'label' => __( 'In progress', 'lnkup-marketplace' ), 'value' => (string) $active_count ),
						array( 'label' => __( 'Booked', 'lnkup-marketplace' ), 'value' => (string) $booked_count ),
					),
					'primary_cta'   => array(
						'label' => __( 'Find Events', 'lnkup-marketplace' ),
						'href'  => $find_url,
					),
					'secondary_cta' => array(
						'label' => __( 'Documents', 'lnkup-marketplace' ),
						'href'  => add_query_arg( 'view', 'documents', $dashboard ),
					),
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>

			<div class="lnkup-dash-tabs" role="tablist">
				<button type="button" class="lnkup-dash-tabs__btn is-active" data-app-tab="all"><?php esc_html_e( 'All', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-dash-tabs__btn" data-app-tab="active"><?php esc_html_e( 'Active', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-dash-tabs__btn" data-app-tab="action"><?php esc_html_e( 'Action needed', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-dash-tabs__btn" data-app-tab="past"><?php esc_html_e( 'Past', 'lnkup-marketplace' ); ?></button>
			</div>

			<div class="lnkup-panel lnkup-panel--flush">
				<?php if ( $applications ) : ?>
					<?php
					$groups = array(
						'action' => array( 'title' => __( 'Payment required', 'lnkup-marketplace' ), 'apps' => array() ),
						'active' => array( 'title' => __( 'In progress', 'lnkup-marketplace' ), 'apps' => array() ),
						'booked' => array( 'title' => __( 'Booked', 'lnkup-marketplace' ), 'apps' => array() ),
						'past'   => array( 'title' => __( 'Closed', 'lnkup-marketplace' ), 'apps' => array() ),
					);
					foreach ( $applications as $app ) {
						$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
						$tab    = LNKup_Vendor_Dashboard_Data::application_tab( $status );
						if ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
							$groups['booked']['apps'][] = $app;
						} elseif ( 'past' === $tab ) {
							$groups['past']['apps'][] = $app;
						} elseif ( 'action' === $tab ) {
							$groups['action']['apps'][] = $app;
						} else {
							$groups['active']['apps'][] = $app;
						}
					}
					?>
					<div class="lnkup-vendor-apps-workflow" id="lnkup-vendor-apps-workflow">
						<?php foreach ( $groups as $key => $group ) : ?>
							<?php if ( empty( $group['apps'] ) ) { continue; } ?>
							<section class="lnkup-workflow-section" data-app-group="<?php echo esc_attr( $key ); ?>">
								<h2 class="lnkup-workflow-section__title"><?php echo esc_html( $group['title'] ); ?></h2>
								<div class="lnkup-workflow-grid">
									<?php foreach ( $group['apps'] as $app ) : ?>
										<?php echo self::render_application_workflow_card( $app, $dashboard ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php endforeach; ?>
								</div>
							</section>
						<?php endforeach; ?>
					</div>
					<div class="lnkup-dash-table-wrap lnkup-dash-table-wrap--cards lnkup-visually-hidden" aria-hidden="true">
						<table class="lnkup-table lnkup-dash-table" id="lnkup-vendor-apps-table">
							<tbody>
							<?php foreach ( $applications as $app ) : ?>
								<?php $status = get_post_meta( $app->ID, '_lnkup_application_status', true ); ?>
								<tr data-app-tab="<?php echo esc_attr( LNKup_Vendor_Dashboard_Data::application_tab( $status ) ); ?>"><td></td></tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php else : ?>
					<div class="lnkup-empty-state" id="lnkup-vendor-apps-empty">
						<div class="lnkup-empty-state__icon" aria-hidden="true"></div>
						<p class="lnkup-empty-state__title"><?php esc_html_e( 'No applications yet', 'lnkup-marketplace' ); ?></p>
						<p class="lnkup-empty-state__desc"><?php esc_html_e( 'Browse events to find opportunities that match your business.', 'lnkup-marketplace' ); ?></p>
						<div class="lnkup-empty-state__actions">
							<a href="<?php echo esc_url( lnkup_page_url_helper( 'opportunities' ) ); ?>" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Find Events', 'lnkup-marketplace' ); ?></a>
							<a href="<?php echo esc_url( add_query_arg( 'view', 'documents', $dashboard ) ); ?>" class="lnkup-btn lnkup-btn--ghost"><?php esc_html_e( 'Upload documents', 'lnkup-marketplace' ); ?></a>
						</div>
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
			$ops_kpi = $pass_apps ? (string) count( $pass_apps ) : '0';
			echo lnkup_executive_hero(
				array(
					'title'       => __( 'Event Operations', 'lnkup-marketplace' ),
					'subtitle'    => $pass_only
						? __( 'Preview your vendor pass — complete payment to unlock full details', 'lnkup-marketplace' )
						: __( 'Event pass, readiness, venue maps, and day-of support', 'lnkup-marketplace' ),
					'kpi'         => $ops_kpi,
					'kpi_label'   => __( 'Confirmed events', 'lnkup-marketplace' ),
					'primary_cta' => $pass_apps ? array() : array(
						'label' => __( 'View applications', 'lnkup-marketplace' ),
						'href'  => $apps_url,
					),
					'secondary_cta' => $pass_apps ? array() : array(
						'label' => __( 'Find Events', 'lnkup-marketplace' ),
						'href'  => $find_url,
					),
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>

			<?php if ( ! $pass_apps ) : ?>
				<?php
				echo lnkup_empty_state(
					__( 'No confirmed events yet', 'lnkup-marketplace' ),
					__( 'Your event operations details will appear here once you\'re approved and paid for an event.', 'lnkup-marketplace' ),
					__( 'View applications', 'lnkup-marketplace' ),
					$apps_url
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<p class="lnkup-muted" style="margin-top:1rem;text-align:center;">
					<a href="<?php echo esc_url( $find_url ); ?>"><?php esc_html_e( 'Browse Find Events', 'lnkup-marketplace' ); ?></a>
				</p>
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
					</div>
					<?php if ( $countdown ) : ?>
						<div class="lnkup-ops-countdown"><?php echo esc_html( $countdown ); ?></div>
					<?php endif; ?>
				</div>
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

					<nav class="lnkup-dash-subnav lnkup-vendor-ops__tabs" role="tablist">
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
								$tab_badge = '<span class="lnkup-dash-subnav__badge">' . esc_html( (string) $checklist['percent'] ) . '%</span>';
							}
							?>
							<a href="<?php echo esc_url( $tab_url ); ?>" class="lnkup-dash-subnav__link lnkup-vendor-ops__tab<?php echo $tab === $key ? ' is-active' : ''; ?>" role="tab" data-ops-tab="<?php echo esc_attr( $key ); ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?><?php echo $tab_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
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
		$ctx  = LNKup_Vendor_Dashboard_Data::context();
		$week = LNKup_Vendor_Dashboard_Data::week_snapshot( $ctx );
		$referrals_url = add_query_arg( 'view', 'referrals', lnkup_page_url_helper( 'vendor-dashboard' ) );
		?>
		<div class="lnkup-mp lnkup-vendor-dash lnkup-vendor-growth" id="growth">
			<?php
			echo lnkup_executive_hero(
				array(
					'title'     => __( 'Growth', 'lnkup-marketplace' ),
					'subtitle'  => __( 'Revenue, markets, and business performance', 'lnkup-marketplace' ),
					'kpi'       => (string) (int) ( $week['profile_strength'] ?? 0 ) . '%',
					'kpi_label' => __( 'Profile strength', 'lnkup-marketplace' ),
					'ring_pct'  => (int) ( $week['profile_strength'] ?? 0 ),
					'metrics'   => array(
						array( 'label' => __( 'Apps this week', 'lnkup-marketplace' ), 'value' => (string) (int) ( $week['applications_this_week'] ?? 0 ) ),
					),
					'primary_cta' => array(
						'label' => __( 'Edit profile', 'lnkup-marketplace' ),
						'href'  => lnkup_page_url_helper( 'vendor-profile' ),
					),
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<?php
			if ( class_exists( 'LNKup_Organizer_Recruit_Render' ) ) {
				echo LNKup_Organizer_Recruit_Render::home_cta( $referrals_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
			<?php echo LNKup_Growth_Render::vendor_shell(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
			echo lnkup_executive_hero(
				array(
					'title'    => __( 'Referral Invites', 'lnkup-marketplace' ),
					'subtitle' => __( 'Invite organizers and earn on LNKup fees for 12 months', 'lnkup-marketplace' ),
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<?php echo LNKup_Organizer_Recruit_Render::hub_shell( 'vendor' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p class="lnkup-muted" style="margin-top:1rem;">
				<?php esc_html_e( 'Looking for peer vendor credits? Open Wallet → Referral Credits.', 'lnkup-marketplace' ); ?>
			</p>
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
			echo lnkup_executive_hero(
				array(
					'title'     => __( 'Reviews', 'lnkup-marketplace' ),
					'subtitle'  => __( 'What organizers say after working with you', 'lnkup-marketplace' ),
					'kpi'       => $count > 0 ? number_format( $avg, 1 ) : '—',
					'kpi_label' => __( 'Average rating', 'lnkup-marketplace' ),
					'metrics'   => array(
						array( 'label' => __( 'Reviews', 'lnkup-marketplace' ), 'value' => (string) $count ),
						array( 'label' => __( 'Reliability', 'lnkup-marketplace' ), 'value' => (string) ( $rep['score'] ?? '—' ) ),
					),
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
				<div class="lnkup-panel lnkup-review-score lnkup-trust-dashboard-hero lnkup-surface--primary">
					<div class="lnkup-review-score__main">
						<span class="lnkup-review-score__value"><?php echo esc_html( number_format( $avg, 1 ) ); ?></span>
						<?php echo LNKup_Reputation_Render::star_rating( $avg, $count ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<p class="lnkup-muted"><?php printf( esc_html( _n( '%d review', '%d reviews', $count, 'lnkup-marketplace' ) ), $count ); ?></p>
						<?php echo LNKup_Reputation_Render::badge_grid( $rep['badges'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<?php if ( $count > 0 ) : ?>
						<div class="lnkup-trust-star-bars" aria-label="<?php esc_attr_e( 'Rating distribution', 'lnkup-marketplace' ); ?>">
							<?php for ( $star = 5; $star >= 1; $star-- ) : ?>
								<?php $pct = $count > 0 ? (int) round( ( $stars[ $star ] / $count ) * 100 ) : 0; ?>
								<div class="lnkup-trust-star-bars__row">
									<span class="lnkup-trust-star-bars__label"><?php echo esc_html( (string) $star ); ?>★</span>
									<div class="lnkup-trust-star-bars__track"><span style="width:<?php echo esc_attr( (string) $pct ); ?>%"></span></div>
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
							<?php echo LNKup_Reputation_Render::review_card( $review ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
		$map_url   = ! empty( $meta['venue_address'] ) ? 'https://maps.google.com/?q=' . rawurlencode( $meta['venue_address'] ) : '';
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
				</div>
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
