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
		$webhook_counts = array(
			'processed' => 0,
			'pending'   => 0,
			'failed'    => 0,
			'total_7d'  => 0,
		);
		if ( LNKup_Financial_Tables::table_exists( $wh_table ) ) {
			$recent_fail = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wh_table} WHERE status = 'failed' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
			);
			$webhook_health = $recent_fail > 0 ? 'degraded' : 'healthy';
			$webhook_counts['processed'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wh_table} WHERE status = 'processed' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
			);
			$webhook_counts['pending'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wh_table} WHERE status = 'pending' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
			);
			$webhook_counts['failed'] = $recent_fail;
			$webhook_counts['total_7d'] = $webhook_counts['processed'] + $webhook_counts['pending'] + $webhook_counts['failed'];
		}

		return array(
			'counts'           => $counts,
			'platform_fees'    => $platform_fees,
			'payouts_total'    => $payouts_total,
			'platform_revenue' => $platform_fees,
			'webhook_health'   => $webhook_health,
			'webhook_counts'   => $webhook_counts,
			'connect_enabled'  => ! empty( $settings['stripe_connect_enabled'] ),
			'express_enabled'  => ! empty( $settings['connect_express_enabled'] ),
			'ach_enabled'      => ! empty( $settings['ach_enabled'] ),
			'billing_enabled'  => ! empty( $settings['stripe_billing_enabled'] ),
			'apple_pay'        => ! empty( $settings['apple_pay_enabled'] ),
			'google_pay'       => ! empty( $settings['google_pay_enabled'] ),
			'webhook_url'      => rest_url( 'lnkup/v1/stripe-webhook' ),
		);
	}

	/**
	 * Recent webhook log rows for idempotency evidence.
	 *
	 * @param int $limit Row limit.
	 * @return array
	 */
	private static function recent_webhook_events( $limit = 25 ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::webhook_events_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		$limit = max( 1, min( 100, absint( $limit ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			"SELECT id, event_id, event_type, status, attempts, created_at, processed_at FROM {$table} ORDER BY id DESC LIMIT {$limit}",
			ARRAY_A
		);
	}

	public static function render_page() {
		if ( ! LNKup_Capabilities::can_manage_marketplace_financials() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$stats  = self::stats();
		$recent = self::recent_webhook_events( 25 );
		$events = array(
			'account.updated',
			'checkout.session.completed',
			'payment_intent.succeeded',
			'payment_intent.processing',
			'payment_intent.payment_failed',
			'customer.subscription.created',
			'customer.subscription.updated',
			'customer.subscription.deleted',
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

			<h2><?php esc_html_e( 'Payment method flags', 'lnkup-marketplace' ); ?></h2>
			<ul style="list-style:disc;margin-left:1.5rem;">
				<li><?php echo esc_html( sprintf( /* translators: on/off */ __( 'ACH: %s', 'lnkup-marketplace' ), ! empty( $stats['ach_enabled'] ) ? __( 'on', 'lnkup-marketplace' ) : __( 'off', 'lnkup-marketplace' ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( /* translators: on/off */ __( 'Stripe Billing: %s', 'lnkup-marketplace' ), ! empty( $stats['billing_enabled'] ) ? __( 'on', 'lnkup-marketplace' ) : __( 'off', 'lnkup-marketplace' ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( /* translators: on/off */ __( 'Apple Pay flag: %s', 'lnkup-marketplace' ), ! empty( $stats['apple_pay'] ) ? __( 'on', 'lnkup-marketplace' ) : __( 'off', 'lnkup-marketplace' ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( /* translators: on/off */ __( 'Google Pay flag: %s', 'lnkup-marketplace' ), ! empty( $stats['google_pay'] ) ? __( 'on', 'lnkup-marketplace' ) : __( 'off', 'lnkup-marketplace' ) ) ); ?></li>
			</ul>

			<h2><?php esc_html_e( 'Webhook endpoint', 'lnkup-marketplace' ); ?></h2>
			<p><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $stats['webhook_url'] ); ?>" onclick="this.select();" /></p>

			<h2><?php esc_html_e( 'Webhook idempotency evidence (7d)', 'lnkup-marketplace' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Events are keyed by Stripe event_id. Duplicate deliveries return HTTP 200 with duplicate:true and skip re-processing when status is already processed.', 'lnkup-marketplace' ); ?>
			</p>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: processed 2: pending 3: failed 4: total */
						__( 'Processed %1$d · Pending %2$d · Failed %3$d · Total %4$d', 'lnkup-marketplace' ),
						(int) $stats['webhook_counts']['processed'],
						(int) $stats['webhook_counts']['pending'],
						(int) $stats['webhook_counts']['failed'],
						(int) $stats['webhook_counts']['total_7d']
					)
				);
				?>
			</p>
			<?php if ( empty( $recent ) ) : ?>
				<p><?php esc_html_e( 'No webhook events logged yet.', 'lnkup-marketplace' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1100px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'lnkup-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Event ID', 'lnkup-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Type', 'lnkup-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Attempts', 'lnkup-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Created', 'lnkup-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Processed', 'lnkup-marketplace' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) ( $row['id'] ?? '' ) ); ?></td>
								<td><code><?php echo esc_html( (string) ( $row['event_id'] ?? '' ) ); ?></code></td>
								<td><code><?php echo esc_html( (string) ( $row['event_type'] ?? '' ) ); ?></code></td>
								<td><?php echo esc_html( (string) ( $row['status'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['attempts'] ?? '0' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['created_at'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['processed_at'] ?? '—' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Required Stripe events', 'lnkup-marketplace' ); ?></h2>
			<ul style="list-style:disc;margin-left:1.5rem;">
				<?php foreach ( $events as $event ) : ?>
					<li><code><?php echo esc_html( $event ); ?></code></li>
				<?php endforeach; ?>
			</ul>
			<p class="description">
				<?php esc_html_e( 'Destination charges route vendor payments to organizer Express accounts with application fees retained on the platform. Treasury fallback applies when Connect is not active for an organizer. ACH payment_intent.processing marks payments processing without unlocking packets until payment_intent.succeeded.', 'lnkup-marketplace' ); ?>
			</p>
		</div>
		<?php
	}
}
