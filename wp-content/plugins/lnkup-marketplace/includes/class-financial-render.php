<?php
/**
 * Financial UI render fragments.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Render {

	public static function vendor_shell( $after_hero = '' ) {
		$user_id = get_current_user_id();
		$summary = class_exists( 'LNKup_Vendor_Presentation' ) ? LNKup_Vendor_Presentation::spend_summary( $user_id ) : array( 'outstanding' => 0.0, 'total_spent' => 0.0 );
		$credits = class_exists( 'LNKup_Credits' ) ? (float) LNKup_Credits::available_total( $user_id ) : 0.0;
		$primary = array(
			'overview'        => __( 'Overview', 'lnkup-marketplace' ),
			'invoices'        => __( 'Invoices', 'lnkup-marketplace' ),
			'receipts'        => __( 'Receipts', 'lnkup-marketplace' ),
			'payment-methods' => __( 'Payment methods', 'lnkup-marketplace' ),
			'reports'         => __( 'Reports', 'lnkup-marketplace' ),
		);
		$more    = array(
			'wallet'    => __( 'Credits', 'lnkup-marketplace' ),
			'refunds'   => __( 'Refunds', 'lnkup-marketplace' ),
			'referrals' => __( 'Referral earnings', 'lnkup-marketplace' ),
			'disputes'  => __( 'Disputes', 'lnkup-marketplace' ),
			'tax'       => __( 'Tax summaries', 'lnkup-marketplace' ),
			'premium'   => __( 'Premium listings', 'lnkup-marketplace' ),
		);
		ob_start();
		?>
		<div class="lnkup-fin-shell" id="lnkup-vendor-financial" data-role="vendor">
			<section class="lnkup-wallet-balance vos-wallet-summary" aria-labelledby="balance-label">
				<h2 class="screen-reader-text" id="balance-label"><?php esc_html_e( 'Wallet summary', 'lnkup-marketplace' ); ?></h2>
				<div class="lnkup-fin-balance-hero" id="lnkup-fin-balance-hero">
					<div class="lnkup-fin-balance-hero__grid lnkup-fin-balance-hero__grid--vendor">
						<div class="lnkup-fin-balance-hero__primary"><p class="lnkup-fin-balance-hero__label"><?php esc_html_e( 'Amount due', 'lnkup-marketplace' ); ?></p><p class="lnkup-fin-balance-hero__value"><?php echo esc_html( lnkup_currency( (float) $summary['outstanding'] ) ); ?></p></div>
						<div><p class="lnkup-fin-balance-hero__label"><?php esc_html_e( 'Total spent', 'lnkup-marketplace' ); ?></p><p class="lnkup-fin-balance-hero__value"><?php echo esc_html( lnkup_currency( (float) $summary['total_spent'] ) ); ?></p><p class="lnkup-muted"><?php esc_html_e( 'Booth fees you paid', 'lnkup-marketplace' ); ?></p></div>
						<div><p class="lnkup-fin-balance-hero__label"><?php esc_html_e( 'Credits', 'lnkup-marketplace' ); ?></p><p class="lnkup-fin-balance-hero__value"><?php echo esc_html( lnkup_currency( $credits ) ); ?></p></div>
						<div><p class="lnkup-fin-balance-hero__label"><?php esc_html_e( 'Next installment', 'lnkup-marketplace' ); ?></p><p class="lnkup-fin-balance-hero__value">—</p></div>
					</div>
				</div>
			</section>
			<?php echo $after_hero; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<nav class="lnkup-fin-section-nav" hidden>
				<button type="button" class="lnkup-fin-section is-active" data-section="pay"><?php esc_html_e( 'Pay', 'lnkup-marketplace' ); ?></button>
			</nav>
			<nav class="lnkup-fin-tabs-wrap vos-wallet-tabs" aria-label="<?php esc_attr_e( 'Wallet sections', 'lnkup-marketplace' ); ?>">
				<div class="lnkup-fin-tabs lnkup-dash-tabs lnkup-dash-tabs--underline" role="tablist">
					<?php foreach ( $primary as $key => $label ) : ?>
						<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab<?php echo 'overview' === $key ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo 'overview' === $key ? 'true' : 'false'; ?>" data-tab="<?php echo esc_attr( $key ); ?>" data-section="pay"><span class="lnkup-fin-tab__label"><?php echo esc_html( $label ); ?></span></button>
					<?php endforeach; ?>
				</div>
				<details class="vos-tab-more">
					<summary><?php esc_html_e( 'More', 'lnkup-marketplace' ); ?></summary>
					<div class="vos-tab-more__menu" role="tablist" aria-label="<?php esc_attr_e( 'More wallet sections', 'lnkup-marketplace' ); ?>">
						<?php foreach ( $more as $key => $label ) : ?>
							<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab" role="tab" aria-selected="false" data-tab="<?php echo esc_attr( $key ); ?>" data-section="pay"><span class="lnkup-fin-tab__label"><?php echo esc_html( $label ); ?></span></button>
						<?php endforeach; ?>
					</div>
				</details>
			</nav>
			<div class="lnkup-fin-panels">
				<div class="lnkup-fin-panel is-active" data-panel="overview" id="lnkup-fin-vendor-overview"></div>
				<div class="lnkup-fin-panel" data-panel="invoices" id="lnkup-fin-vendor-invoices"></div>
				<div class="lnkup-fin-panel" data-panel="payment-methods" id="lnkup-fin-vendor-payment-methods"></div>
				<div class="lnkup-fin-panel" data-panel="wallet" id="lnkup-fin-vendor-wallet"></div>
				<div class="lnkup-fin-panel" data-panel="referrals" id="lnkup-fin-vendor-referrals"></div>
				<div class="lnkup-fin-panel" data-panel="premium" id="lnkup-fin-vendor-premium"></div>
				<div class="lnkup-fin-panel" data-panel="tax" id="lnkup-fin-vendor-tax"></div>
				<div class="lnkup-fin-panel" data-panel="receipts" id="lnkup-fin-vendor-receipts"></div>
				<div class="lnkup-fin-panel" data-panel="refunds" id="lnkup-fin-vendor-refunds"></div>
				<div class="lnkup-fin-panel" data-panel="disputes" id="lnkup-fin-vendor-disputes"></div>
				<div class="lnkup-fin-panel" data-panel="reports" id="lnkup-fin-vendor-reports"></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function organizer_recent_activity() {
		$payments = function_exists( 'lnkup_get_payments_for_user' ) ? lnkup_get_payments_for_user( get_current_user_id(), 'organizer' ) : array();
		$rows     = function_exists( 'lnkup_payment_display_rows' ) ? lnkup_sort_payment_rows_by_activity( lnkup_payment_display_rows( $payments ) ) : array();
		$rows     = array_slice( $rows, 0, 5 );
		ob_start();
		?>
		<section class="lnkup-wallet-payments" aria-labelledby="lnkup-money-activity-title">
			<h2 id="lnkup-money-activity-title"><?php esc_html_e( 'Recent activity', 'lnkup-marketplace' ); ?></h2>
			<?php if ( ! $rows ) : ?>
				<p class="lnkup-empty-state__title"><?php esc_html_e( 'No payments yet', 'lnkup-marketplace' ); ?></p>
			<?php else : ?>
				<ul class="lnkup-workflow-grid">
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$payment  = $row['payment'];
						$event_id = (int) get_post_meta( $payment->ID, '_lnkup_event_id', true );
						$vendor   = (string) get_post_meta( $payment->ID, '_lnkup_vendor_name', true );
						if ( ! $vendor ) {
							$vendor_id = (int) get_post_meta( $payment->ID, '_lnkup_vendor_user_id', true );
							$vendor    = $vendor_id ? get_the_author_meta( 'display_name', $vendor_id ) : $payment->post_title;
						}
						$activity = lnkup_payment_activity_date( $payment );
						$when     = $activity ? date_i18n( 'M j, Y', strtotime( $activity ) ) : '';
						$meta = implode( ' · ', array_filter( array( $event_id ? lnkup_plain_title( $event_id ) : '', $when ) ) );
						?>
						<li class="lnkup-request-row">
							<div class="lnkup-request-row__copy">
								<p class="lnkup-request-row__title"><?php echo esc_html( $vendor ); ?></p>
								<?php if ( $meta ) : ?><p class="lnkup-request-row__meta"><?php echo esc_html( $meta ); ?></p><?php endif; ?>
							</div>
							<span class="lnkup-request-row__fee"><?php echo esc_html( function_exists( 'lnkup_currency' ) ? lnkup_currency( $row['amount'] ) : (string) $row['amount'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Money 2.0 shell: summary flow strip, primary tabs plus More. Panels and their loaders are
	 * unchanged (financial.js); tab keys are the same, so ?fin_tab= links keep working.
	 */
	public static function organizer_shell_os() {
		$primary = array(
			'overview'       => __( 'Overview', 'lnkup-marketplace' ),
			'vendor-revenue' => __( 'Transactions', 'lnkup-marketplace' ),
			'payouts'        => __( 'Payouts', 'lnkup-marketplace' ),
			'cashout'        => __( 'Cash out', 'lnkup-marketplace' ),
			'forecast'       => __( 'Planning', 'lnkup-marketplace' ),
			'reports'        => __( 'Reports', 'lnkup-marketplace' ),
			'settings'       => __( 'Account', 'lnkup-marketplace' ),
		);
		$more    = array(
			'wallet'   => __( 'Ledger', 'lnkup-marketplace' ),
			'budget'   => __( 'Budget', 'lnkup-marketplace' ),
			'reserves' => __( 'Reserves', 'lnkup-marketplace' ),
			'refunds'  => __( 'Refunds', 'lnkup-marketplace' ),
			'disputes' => __( 'Disputes', 'lnkup-marketplace' ),
			'sponsor'  => __( 'Sponsor revenue', 'lnkup-marketplace' ),
			'tax'      => __( 'Tax', 'lnkup-marketplace' ),
		);
		$panels  = array_merge( array_keys( $primary ), array_keys( $more ) );
		ob_start();
		?>
		<div class="lnkup-fin-shell oos-money" id="lnkup-organizer-financial" data-role="organizer">
			<?php echo self::organizer_flow_strip( get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div class="lnkup-fin-connect-top" id="lnkup-fin-connect-banner" aria-live="polite"></div>
			<nav class="lnkup-fin-tabs-wrap vos-wallet-tabs" aria-label="<?php esc_attr_e( 'Money sections', 'lnkup-marketplace' ); ?>" data-testid="lnkup-money-tabs">
				<div class="lnkup-fin-tabs lnkup-dash-tabs lnkup-dash-tabs--underline" role="tablist">
					<?php foreach ( $primary as $key => $label ) : ?>
						<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab<?php echo 'overview' === $key ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo 'overview' === $key ? 'true' : 'false'; ?>" data-tab="<?php echo esc_attr( $key ); ?>"><span class="lnkup-fin-tab__label"><?php echo esc_html( $label ); ?></span></button>
					<?php endforeach; ?>
				</div>
				<details class="vos-tab-more">
					<summary><?php esc_html_e( 'More', 'lnkup-marketplace' ); ?></summary>
					<div class="vos-tab-more__menu" role="tablist" aria-label="<?php esc_attr_e( 'More money sections', 'lnkup-marketplace' ); ?>">
						<?php foreach ( $more as $key => $label ) : ?>
							<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab" role="tab" aria-selected="false" data-tab="<?php echo esc_attr( $key ); ?>"><span class="lnkup-fin-tab__label"><?php echo esc_html( $label ); ?></span></button>
						<?php endforeach; ?>
					</div>
				</details>
			</nav>
			<div class="lnkup-fin-balance-hero" id="lnkup-fin-org-balance-hero">
				<div class="lnkup-fin-balance-hero__skeleton"></div>
			</div>
			<div class="lnkup-fin-panels">
				<?php foreach ( $panels as $key ) : ?>
					<div class="lnkup-fin-panel lnkup-fin-tab-panel<?php echo 'overview' === $key ? ' is-active' : ''; ?>" role="tabpanel" data-panel="<?php echo esc_attr( $key ); ?>" id="lnkup-fin-org-<?php echo esc_attr( $key ); ?>"></div>
				<?php endforeach; ?>
			</div>
			<?php echo self::organizer_recent_activity(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Money flow strip from the organizer money contract: collected → pending → available →
	 * in transit → paid out, each labeled with what it is and where it comes from.
	 *
	 * @param int $user_id Organizer.
	 */
	public static function organizer_flow_strip( $user_id ) {
		$contract = class_exists( 'LNKup_Organizer_Presentation' ) ? LNKup_Organizer_Presentation::money_contract( (int) $user_id ) : array();
		if ( ! $contract ) {
			return '';
		}
		$m      = (array) ( $contract['metrics'] ?? array() );
		$steps  = array(
			'collected'  => array( __( 'Collected', 'lnkup-marketplace' ), __( 'After LNKup fees, all time', 'lnkup-marketplace' ) ),
			'pending'    => array( __( 'Pending', 'lnkup-marketplace' ), __( 'Not released by Stripe yet', 'lnkup-marketplace' ) ),
			'available'  => array( __( 'Available', 'lnkup-marketplace' ), __( 'Ready to cash out', 'lnkup-marketplace' ) ),
			'in_transit' => array( __( 'In transit', 'lnkup-marketplace' ), __( 'On the way to your bank', 'lnkup-marketplace' ) ),
			'paid_out'   => array( __( 'Paid out', 'lnkup-marketplace' ), __( 'Reached your bank, all time', 'lnkup-marketplace' ) ),
		);
		$synced = (string) ( $contract['last_synced_at'] ?? '' );
		$html   = '<section class="oos-money-summary" data-testid="lnkup-money-flow" aria-label="' . esc_attr__( 'Money summary', 'lnkup-marketplace' ) . '"><div class="vos-flow">';
		foreach ( $steps as $key => $step ) {
			$metric = (array) ( $m[ $key ] ?? array() );
			$ok     = 'ok' === ( $metric['status'] ?? '' ) && null !== ( $metric['cents'] ?? null );
			$value  = $ok ? ( function_exists( 'lnkup_currency' ) ? lnkup_currency( (int) $metric['cents'] / 100 ) : '$' . number_format( (int) $metric['cents'] / 100, 2 ) ) : __( 'Not synced', 'lnkup-marketplace' );
			$html  .= '<div class="vos-flow__step" data-metric="' . esc_attr( $key ) . '"><span class="vos-flow__label">' . esc_html( $step[0] ) . '</span><span class="vos-flow__value">' . esc_html( $value ) . '</span><span class="vos-flow__hint">' . esc_html( $step[1] ) . '</span></div>';
		}
		$html .= '</div><p class="vos-footnote">';
		$html .= '' !== $synced
			? esc_html( sprintf( /* translators: %s: date and time */ __( 'Balances last synced with Stripe %s.', 'lnkup-marketplace' ), date_i18n( 'M j, g:i a', (int) strtotime( $synced ) ) ) )
			: esc_html__( 'Balances have not been synced with Stripe yet.', 'lnkup-marketplace' );
		$html .= ' ' . esc_html__( 'Event pages show gross booth fees before LNKup fees.', 'lnkup-marketplace' ) . '</p></section>';
		return $html;
	}

	public static function organizer_shell() {
		if ( class_exists( 'LNKup_OS_UI' ) ) {
			return self::organizer_shell_os();
		}
		ob_start();
		?>
		<div class="lnkup-fin-shell" id="lnkup-organizer-financial" data-role="organizer">
			<div class="lnkup-fin-connect-top" id="lnkup-fin-connect-banner" aria-live="polite"></div>
			<nav class="lnkup-fin-section-nav lnkup-dash-tabs lnkup-dash-tabs--pills" aria-label="<?php esc_attr_e( 'Financial categories', 'lnkup-marketplace' ); ?>">
				<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-section is-active" data-section="money"><?php esc_html_e( 'Money', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-section" data-section="revenue"><?php esc_html_e( 'Revenue', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-section" data-section="planning"><?php esc_html_e( 'Planning', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-section" data-section="account"><?php esc_html_e( 'Account', 'lnkup-marketplace' ); ?></button>
			</nav>
			<nav class="lnkup-fin-tabs-wrap" aria-label="<?php esc_attr_e( 'Financial sections', 'lnkup-marketplace' ); ?>">
				<div class="lnkup-fin-tabs lnkup-dash-tabs lnkup-dash-tabs--underline" role="tablist">
					<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab is-active" role="tab" aria-selected="true" data-tab="overview" data-section="money"><span class="lnkup-fin-tab__icon" aria-hidden="true">◉</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Overview', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab" role="tab" aria-selected="false" data-tab="wallet" data-section="money"><span class="lnkup-fin-tab__icon" aria-hidden="true">◎</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Wallet', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab" role="tab" aria-selected="false" data-tab="cashout" data-section="money"><span class="lnkup-fin-tab__icon" aria-hidden="true">↗</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Cash Out', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab" role="tab" aria-selected="false" data-tab="payouts" data-section="money"><span class="lnkup-fin-tab__icon" aria-hidden="true">⇄</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Payouts', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab" role="tab" aria-selected="false" data-tab="vendor-revenue" data-section="revenue"><span class="lnkup-fin-tab__icon" aria-hidden="true">$</span><span class="lnkup-fin-tab__label"><?php echo esc_html( lnkup_copy( 'vendor_revenue' ) ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab" role="tab" aria-selected="false" data-tab="sponsor" data-section="revenue"><span class="lnkup-fin-tab__icon" aria-hidden="true">★</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Sponsor Revenue', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab" role="tab" aria-selected="false" data-tab="reports" data-section="revenue"><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Reports', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab" role="tab" aria-selected="false" data-tab="reserves" data-section="planning"><span class="lnkup-fin-tab__icon" aria-hidden="true">⛨</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Reserves', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab" role="tab" aria-selected="false" data-tab="budget" data-section="planning"><span class="lnkup-fin-tab__icon" aria-hidden="true">▦</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Budget', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab" role="tab" aria-selected="false" data-tab="forecast" data-section="planning"><span class="lnkup-fin-tab__icon" aria-hidden="true">⌁</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Forecast', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab" role="tab" aria-selected="false" data-tab="refunds" data-section="planning"><span class="lnkup-fin-tab__icon" aria-hidden="true">↩</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Refunds', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab" role="tab" aria-selected="false" data-tab="disputes" data-section="planning"><span class="lnkup-fin-tab__icon" aria-hidden="true">⚠</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Disputes', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab" role="tab" aria-selected="false" data-tab="tax" data-section="account"><span class="lnkup-fin-tab__icon" aria-hidden="true">⎙</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Tax', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn lnkup-fin-tab" role="tab" aria-selected="false" data-tab="settings" data-section="account"><span class="lnkup-fin-tab__icon" aria-hidden="true">⚙</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Settings', 'lnkup-marketplace' ); ?></span></button>
				</div>
			</nav>
			<div class="lnkup-fin-balance-hero" id="lnkup-fin-org-balance-hero">
				<div class="lnkup-fin-balance-hero__skeleton"></div>
			</div>
			<div class="lnkup-fin-panels">
				<div class="lnkup-fin-panel lnkup-fin-tab-panel is-active" role="tabpanel" data-panel="overview" id="lnkup-fin-org-overview"></div>
				<div class="lnkup-fin-panel lnkup-fin-tab-panel" role="tabpanel" data-panel="wallet" id="lnkup-fin-org-wallet"></div>
				<div class="lnkup-fin-panel lnkup-fin-tab-panel" role="tabpanel" data-panel="cashout" id="lnkup-fin-org-cashout"></div>
				<div class="lnkup-fin-panel lnkup-fin-tab-panel" role="tabpanel" data-panel="vendor-revenue" id="lnkup-fin-org-vendor-revenue"></div>
				<div class="lnkup-fin-panel lnkup-fin-tab-panel" role="tabpanel" data-panel="sponsor" id="lnkup-fin-org-sponsor"></div>
				<div class="lnkup-fin-panel lnkup-fin-tab-panel" role="tabpanel" data-panel="payouts" id="lnkup-fin-org-payouts"></div>
				<div class="lnkup-fin-panel lnkup-fin-tab-panel" role="tabpanel" data-panel="reserves" id="lnkup-fin-org-reserves"></div>
				<div class="lnkup-fin-panel lnkup-fin-tab-panel" role="tabpanel" data-panel="budget" id="lnkup-fin-org-budget"></div>
				<div class="lnkup-fin-panel lnkup-fin-tab-panel" role="tabpanel" data-panel="forecast" id="lnkup-fin-org-forecast"></div>
				<div class="lnkup-fin-panel lnkup-fin-tab-panel" role="tabpanel" data-panel="refunds" id="lnkup-fin-org-refunds"></div>
				<div class="lnkup-fin-panel lnkup-fin-tab-panel" role="tabpanel" data-panel="disputes" id="lnkup-fin-org-disputes"></div>
				<div class="lnkup-fin-panel lnkup-fin-tab-panel" role="tabpanel" data-panel="tax" id="lnkup-fin-org-tax"></div>
				<div class="lnkup-fin-panel lnkup-fin-tab-panel" role="tabpanel" data-panel="reports" id="lnkup-fin-org-reports"></div>
				<div class="lnkup-fin-panel lnkup-fin-tab-panel" role="tabpanel" data-panel="settings" id="lnkup-fin-org-settings"></div>
			</div>
			<?php echo self::organizer_recent_activity(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function event_financial_tab( $event_id ) {
		ob_start();
		?>
		<div class="lnkup-fin-event" id="lnkup-event-financial" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"></div>
		<?php
		return ob_get_clean();
	}

	public static function status_chip( $status ) {
		$status = sanitize_key( $status );
		return '<span class="lnkup-fin-status lnkup-fin-status--' . esc_attr( $status ) . '">' . esc_html( ucwords( str_replace( '_', ' ', $status ) ) ) . '</span>';
	}
}
