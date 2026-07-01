<?php
/**
 * Admin Stripe Connect dashboard.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Connect_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 30 );
	}

	public static function add_menu() {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Stripe Connect', 'lnkup-marketplace' ),
			__( 'Stripe Connect', 'lnkup-marketplace' ),
			'lnkup_manage_marketplace_financials',
			'lnkup-stripe-connect',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Aggregate Connect organizer counts and treasury totals.
	 */
	private static function stats() {
		global $wpdb;
		$settings = lnkup_get_settings();
		$ca_table = LNKup_Financial_Tables::connected_accounts_table();
		$counts   = array(
			'connected'  => 0,
			'pending'    => 0,
			'restricted' => 0,
		);
		if ( LNKup_Financial_Tables::table_exists( $ca_table ) ) {
			$rows = $wpdb->get_results(
				"SELECT charges_enabled, payouts_enabled, verification_status, disabled_reason FROM {$ca_table} WHERE role_type = 'organizer'",
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				if ( ! empty( $row['charges_enabled'] ) && ! empty( $row['payouts_enabled'] ) ) {
					++$counts['connected'];
				} elseif ( ! empty( $row['disabled_reason'] ) || 'rejected' === ( $row['verification_status'] ?? '' ) ) {
					++$counts['restricted'];
				} else {
					++$counts['pending'];
				}
			}
		}

		$platform_fees = 0.0;
		$payouts_total = 0.0;
		if ( class_exists( 'LNKup_Treasury_Tables' ) ) {
			$pf = $wpdb->prefix . 'lnkup_platform_fees';
			if ( LNKup_Financial_Tables::table_exists( $pf ) ) {
				$platform_fees = (float) $wpdb->get_var( "SELECT COALESCE(SUM(platform_fee), 0) FROM {$pf}" );
			}
			$op = LNKup_Treasury_Tables::organizer_payouts_table();
			if ( LNKup_Financial_Tables::table_exists( $op ) ) {
				$payouts_total = (float) $wpdb->get_var( "SELECT COALESCE(SUM(net_amount), 0) FROM {$op} WHERE status = 'paid'" );
			}
		}

		$wh_table = LNKup_Financial_Tables::webhook_events_table();
		$webhook_health = 'unknown';
		if ( LNKup_Financial_Tables::table_exists( $wh_table ) ) {
			$recent_fail = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wh_table} WHERE status = 'failed' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
			);
			$webhook_health = $recent_fail > 0 ? 'degraded' : 'healthy';
		}

		return array(
			'counts'           => $counts,
			'platform_fees'    => $platform_fees,
			'payouts_total'    => $payouts_total,
			'platform_revenue' => $platform_fees,
			'webhook_health'   => $webhook_health,
			'connect_enabled'  => ! empty( $settings['stripe_connect_enabled'] ),
			'express_enabled'  => ! empty( $settings['connect_express_enabled'] ),
			'webhook_url'      => rest_url( 'lnkup/v1/stripe-webhook' ),
		);
	}

	public static function render_page() {
		if ( ! LNKup_Capabilities::can_manage_marketplace_financials() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$stats  = self::stats();
		$events = array(
			'account.updated',
			'checkout.session.completed',
			'payment_intent.succeeded',
			'payment_intent.payment_failed',
			'transfer.created',
			'transfer.failed',
			'payout.created',
			'payout.paid',
			'payout.failed',
			'charge.refunded',
			'charge.dispute.created',
		);
		?>
		<div class="wrap lnkup-fin-connect-admin">
			<h1><?php esc_html_e( 'Stripe Connect', 'lnkup-marketplace' ); ?></h1>
			<p>
				<?php
				echo esc_html(
					! empty( $stats['connect_enabled'] )
						? __( 'Connect is enabled for this marketplace.', 'lnkup-marketplace' )
						: __( 'Connect is disabled — enable it under LNKup → Settings.', 'lnkup-marketplace' )
				);
				?>
			</p>
			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem;margin:1.5rem 0;">
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) $stats['counts']['connected'] ); ?></strong><br><?php esc_html_e( 'Active organizers', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) $stats['counts']['pending'] ); ?></strong><br><?php esc_html_e( 'Pending onboarding', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) $stats['counts']['restricted'] ); ?></strong><br><?php esc_html_e( 'Restricted / rejected', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( lnkup_currency( $stats['platform_fees'] ) ); ?></strong><br><?php esc_html_e( 'Platform fees (ledger)', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( lnkup_currency( $stats['payouts_total'] ) ); ?></strong><br><?php esc_html_e( 'Organizer payouts paid', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( ucfirst( $stats['webhook_health'] ) ); ?></strong><br><?php esc_html_e( 'Webhook health (7d)', 'lnkup-marketplace' ); ?></div>
			</div>
			<h2><?php esc_html_e( 'Webhook endpoint', 'lnkup-marketplace' ); ?></h2>
			<p><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $stats['webhook_url'] ); ?>" onclick="this.select();" /></p>
			<h2><?php esc_html_e( 'Required Stripe events', 'lnkup-marketplace' ); ?></h2>
			<ul style="list-style:disc;margin-left:1.5rem;">
				<?php foreach ( $events as $event ) : ?>
					<li><code><?php echo esc_html( $event ); ?></code></li>
				<?php endforeach; ?>
			</ul>
			<p class="description">
				<?php esc_html_e( 'Destination charges route vendor payments to organizer Express accounts with application fees retained on the platform. Treasury fallback applies when Connect is not active for an organizer.', 'lnkup-marketplace' ); ?>
			</p>
		</div>
		<?php
	}
}
