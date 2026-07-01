<?php
/**
 * Financial UI render fragments.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Render {

	public static function vendor_shell() {
		ob_start();
		?>
		<div class="lnkup-fin-shell" id="lnkup-vendor-financial" data-role="vendor">
			<div class="lnkup-fin-balance-hero" id="lnkup-fin-balance-hero">
				<div class="lnkup-fin-balance-hero__skeleton"></div>
			</div>
			<nav class="lnkup-fin-section-nav" aria-label="<?php esc_attr_e( 'Payment sections', 'lnkup-marketplace' ); ?>">
				<button type="button" class="lnkup-fin-section is-active" data-section="pay"><?php esc_html_e( 'Pay', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-fin-section" data-section="wallet"><?php esc_html_e( 'Wallet', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-fin-section" data-section="extras"><?php esc_html_e( 'Extras', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-fin-section" data-section="reports"><?php esc_html_e( 'Reports', 'lnkup-marketplace' ); ?></button>
			</nav>
			<nav class="lnkup-fin-tabs-wrap" aria-label="<?php esc_attr_e( 'Payments tabs', 'lnkup-marketplace' ); ?>">
				<div class="lnkup-fin-tabs" role="tablist">
					<button type="button" class="lnkup-fin-tab is-active" role="tab" aria-selected="true" data-tab="overview" data-section="pay"><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Overview', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="invoices" data-section="pay"><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Invoices', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="payment-methods" data-section="pay"><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Payment Methods', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="receipts" data-section="pay"><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Receipts', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="wallet" data-section="wallet"><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Wallet & Credits', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="referrals" data-section="wallet"><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Referral Credits', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="refunds" data-section="wallet"><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Refunds', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="premium" data-section="extras"><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Premium Listings', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="tax" data-section="extras"><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Tax Center', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="reports" data-section="reports"><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Reports', 'lnkup-marketplace' ); ?></span></button>
				</div>
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
				<div class="lnkup-fin-panel" data-panel="reports" id="lnkup-fin-vendor-reports"></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function organizer_shell() {
		ob_start();
		?>
		<div class="lnkup-fin-shell" id="lnkup-organizer-financial" data-role="organizer">
			<div class="lnkup-fin-connect-top" id="lnkup-fin-connect-banner" aria-live="polite"></div>
			<div class="lnkup-fin-balance-hero" id="lnkup-fin-org-balance-hero">
				<div class="lnkup-fin-balance-hero__skeleton"></div>
			</div>
			<nav class="lnkup-fin-section-nav" aria-label="<?php esc_attr_e( 'Financial categories', 'lnkup-marketplace' ); ?>">
				<button type="button" class="lnkup-fin-section is-active" data-section="money"><?php esc_html_e( 'Money', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-fin-section" data-section="revenue"><?php esc_html_e( 'Revenue', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-fin-section" data-section="planning"><?php esc_html_e( 'Planning', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-fin-section" data-section="account"><?php esc_html_e( 'Account', 'lnkup-marketplace' ); ?></button>
			</nav>
			<nav class="lnkup-fin-tabs-wrap" aria-label="<?php esc_attr_e( 'Financial sections', 'lnkup-marketplace' ); ?>">
				<div class="lnkup-fin-tabs" role="tablist">
					<button type="button" class="lnkup-fin-tab is-active" role="tab" aria-selected="true" data-tab="overview" data-section="money"><span class="lnkup-fin-tab__icon" aria-hidden="true">◉</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Overview', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="wallet" data-section="money"><span class="lnkup-fin-tab__icon" aria-hidden="true">◎</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Wallet', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="cashout" data-section="money"><span class="lnkup-fin-tab__icon" aria-hidden="true">↗</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Cash Out', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="payouts" data-section="money"><span class="lnkup-fin-tab__icon" aria-hidden="true">⇄</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Payouts', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="vendor-revenue" data-section="revenue"><span class="lnkup-fin-tab__icon" aria-hidden="true">$</span><span class="lnkup-fin-tab__label"><?php echo esc_html( lnkup_copy( 'vendor_revenue' ) ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="sponsor" data-section="revenue"><span class="lnkup-fin-tab__icon" aria-hidden="true">★</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Sponsor Revenue', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="reports" data-section="revenue"><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Reports', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="reserves" data-section="planning"><span class="lnkup-fin-tab__icon" aria-hidden="true">⛨</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Reserves', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="budget" data-section="planning"><span class="lnkup-fin-tab__icon" aria-hidden="true">▦</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Budget', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="forecast" data-section="planning"><span class="lnkup-fin-tab__icon" aria-hidden="true">⌁</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Forecast', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="refunds" data-section="planning"><span class="lnkup-fin-tab__icon" aria-hidden="true">↩</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Refunds', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="tax" data-section="account"><span class="lnkup-fin-tab__icon" aria-hidden="true">⎙</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Tax', 'lnkup-marketplace' ); ?></span></button>
					<button type="button" class="lnkup-fin-tab" role="tab" aria-selected="false" data-tab="settings" data-section="account"><span class="lnkup-fin-tab__icon" aria-hidden="true">⚙</span><span class="lnkup-fin-tab__label"><?php esc_html_e( 'Settings', 'lnkup-marketplace' ); ?></span></button>
				</div>
			</nav>
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
				<div class="lnkup-fin-panel lnkup-fin-tab-panel" role="tabpanel" data-panel="tax" id="lnkup-fin-org-tax"></div>
				<div class="lnkup-fin-panel lnkup-fin-tab-panel" role="tabpanel" data-panel="reports" id="lnkup-fin-org-reports"></div>
				<div class="lnkup-fin-panel lnkup-fin-tab-panel" role="tabpanel" data-panel="settings" id="lnkup-fin-org-settings"></div>
			</div>
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
