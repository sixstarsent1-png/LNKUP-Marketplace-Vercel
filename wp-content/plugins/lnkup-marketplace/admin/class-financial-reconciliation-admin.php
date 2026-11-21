<?php
/**
 * wp-admin Reconciliation health panel: diagnostics, webhook health, and approval-gated repair.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Reconciliation_Admin {

	const SLUG = 'lnkup-financial-reconciliation';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 30 );
		add_action( 'admin_post_lnkup_fin_repair_dry', array( __CLASS__, 'handle_dry_run' ) );
		add_action( 'admin_post_lnkup_fin_repair_apply', array( __CLASS__, 'handle_apply' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Financial Reconciliation', 'lnkup-marketplace' ),
			__( 'Reconciliation', 'lnkup-marketplace' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * @param array $args Query args.
	 */
	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * @param int|null $cents Cents or null.
	 */
	private static function money( $cents ) {
		return null === $cents ? '—' : '$' . number_format( (int) $cents / 100, 2 );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$organizer_id = absint( $_GET['organizer_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap"><h1>' . esc_html__( 'Financial reconciliation', 'lnkup-marketplace' ) . '</h1>';
		echo '<p>' . esc_html__( 'Read-only comparison of WordPress payments, the organizer ledger, webhooks and Stripe. Repairs require a dry run and explicit confirmation.', 'lnkup-marketplace' ) . '</p>';
		if ( $organizer_id ) {
			self::render_organizer( $organizer_id );
		} else {
			self::render_overview();
		}
		echo '</div>';
	}

	private static function render_overview() {
		$summary = LNKup_Financial_Reconciliation::platform_summary();
		$schema  = $summary['schema'];
		$hourly  = (array) ( $summary['hourly'] ?? array() );
		$hooks   = $summary['webhooks'];

		echo '<h2>' . esc_html__( 'Health', 'lnkup-marketplace' ) . '</h2><table class="widefat striped" style="max-width:900px"><tbody>';
		$rows = array(
			array( __( 'Ledger column fits connect_settlement', 'lnkup-marketplace' ), ! empty( $schema['connect_settlement_fits'] ) ? 'Yes' : 'NO — run the DB upgrade', empty( $schema['connect_settlement_fits'] ) ),
			array( __( 'Ledger idempotency keys', 'lnkup-marketplace' ), ! empty( $schema['idempotency_column'] ) ? 'Enabled' : 'Missing', empty( $schema['idempotency_column'] ) ),
			array( __( 'DB version', 'lnkup-marketplace' ), (string) ( $schema['db_version'] ?? '' ), false ),
			array( __( 'Auto-recovery since', 'lnkup-marketplace' ), (string) ( $schema['auto_recovery_since'] ?? '' ) ?: '—', false ),
			array( __( 'Hourly reconciliation last ran', 'lnkup-marketplace' ), $hourly ? ( $hourly['ran_at'] . ' · ' . count( (array) $hourly['synced'] ) . ' synced · ' . count( (array) $hourly['errors'] ) . ' errors' ) : 'Never', empty( $hourly ) ),
			array( __( 'Next hourly run', 'lnkup-marketplace' ), wp_next_scheduled( LNKup_Financial_Cron::HOURLY_HOOK ) ? wp_date( 'Y-m-d H:i:s', wp_next_scheduled( LNKup_Financial_Cron::HOURLY_HOOK ) ) : 'NOT SCHEDULED', ! wp_next_scheduled( LNKup_Financial_Cron::HOURLY_HOOK ) ),
			array( __( 'Last webhook received', 'lnkup-marketplace' ), (string) ( $hooks['last_received'] ?? '' ) ?: 'Never', empty( $hooks['last_received'] ) ),
			array( __( 'Webhooks not processed', 'lnkup-marketplace' ), (string) count( (array) ( $hooks['not_processed'] ?? array() ) ), ! empty( $hooks['not_processed'] ) ),
			array( __( 'Webhook endpoint', 'lnkup-marketplace' ), rest_url( 'lnkup/v1/stripe-webhook' ), false ),
		);
		foreach ( $rows as $row ) {
			printf(
				'<tr><th scope="row" style="width:320px">%s</th><td%s>%s</td></tr>',
				esc_html( $row[0] ),
				$row[2] ? ' style="color:#b32d2e;font-weight:600"' : '',
				esc_html( $row[1] )
			);
		}
		echo '</tbody></table>';

		if ( ! empty( $hooks['not_processed'] ) ) {
			echo '<h3>' . esc_html__( 'Webhooks waiting or failed', 'lnkup-marketplace' ) . '</h3><table class="widefat striped" style="max-width:900px"><thead><tr><th>Event</th><th>Type</th><th>Status</th><th>Attempts</th><th>Error</th><th>Received</th></tr></thead><tbody>';
			foreach ( array_slice( $hooks['not_processed'], 0, 25 ) as $hook ) {
				printf(
					'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
					esc_html( $hook['event_id'] ),
					esc_html( $hook['event_type'] ),
					esc_html( $hook['status'] ),
					(int) $hook['attempts'],
					esc_html( (string) $hook['error_message'] ),
					esc_html( $hook['created_at'] )
				);
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Organizers', 'lnkup-marketplace' ) . '</h2><table class="widefat striped"><thead><tr><th>Organizer</th><th>Payments</th><th>Paid</th><th>Collected</th><th>Ledger rows</th><th>Issues</th><th></th></tr></thead><tbody>';
		foreach ( $summary['organizers'] as $row ) {
			$issues = array();
			foreach ( $row['issues'] as $code => $count ) {
				$issues[] = $code . ' ×' . $count;
			}
			printf(
				'<tr><td>%s <span class="description">#%d</span></td><td>%d</td><td>%d</td><td>%s</td><td>%d</td><td>%s</td><td><a class="button" href="%s">%s</a></td></tr>',
				esc_html( $row['display_name'] ),
				(int) $row['organizer_id'],
				(int) $row['payments'],
				(int) $row['paid'],
				esc_html( self::money( $row['collected_cents'] ) ),
				(int) $row['ledger_rows'],
				$issues ? esc_html( implode( ', ', $issues ) ) : '<span style="color:#008a20">OK</span>',
				esc_url( self::url( array( 'organizer_id' => $row['organizer_id'] ) ) ),
				esc_html__( 'Open', 'lnkup-marketplace' )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * @param int $organizer_id Organizer.
	 */
	private static function render_organizer( $organizer_id ) {
		$stripe = ! empty( $_GET['stripe'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$report = LNKup_Financial_Reconciliation::organizer_report( $organizer_id, array( 'stripe' => $stripe ) );
		$totals = $report['totals'];

		printf(
			'<p><a href="%s">&larr; %s</a> · <strong>%s</strong> #%d · %s · <a class="button" href="%s">%s</a></p>',
			esc_url( self::url() ),
			esc_html__( 'All organizers', 'lnkup-marketplace' ),
			esc_html( $report['organizer']['display_name'] ),
			(int) $organizer_id,
			esc_html( 'Settlement: ' . $report['organizer']['settlement_mode_now'] ),
			esc_url( self::url( array( 'organizer_id' => $organizer_id, 'stripe' => 1 ) ) ),
			esc_html__( 'Verify with Stripe (read-only)', 'lnkup-marketplace' )
		);

		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		$facts = array(
			'WordPress paid payments'          => $totals['wp_paid_count'] . ' · ' . self::money( $totals['wp_paid_gross_cents'] ),
			'Stripe succeeded (organizer)'     => null === $totals['stripe_succeeded_count'] ? 'Not checked' : $totals['stripe_succeeded_count'] . ' · ' . self::money( $totals['stripe_succeeded_gross_cents'] ),
			'Ledger connect_settlement (net)'  => self::money( $totals['ledger_connect_settlement_net_cents'] ),
			'Wallet available / pending'       => $report['wallet_row'] ? self::money( $report['wallet_row']['available_balance_cents'] ) . ' / ' . self::money( $report['wallet_row']['pending_balance_cents'] ) : '—',
			'Connect balance available / pending' => isset( $totals['stripe_connect_balance']['available_cents'] ) ? self::money( $totals['stripe_connect_balance']['available_cents'] ) . ' / ' . self::money( $totals['stripe_connect_balance']['pending_cents'] ) : 'Not checked',
			'Migrations missing a ledger row'  => count( $report['migrations']['unrecorded'] ) . ' · ' . self::money( $report['migrations']['unrecorded_cents'] ),
		);
		foreach ( $facts as $label => $value ) {
			printf( '<tr><th scope="row" style="width:320px">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( (string) $value ) );
		}
		echo '</tbody></table>';

		if ( $report['issue_counts'] ) {
			echo '<p><strong>' . esc_html__( 'Issues:', 'lnkup-marketplace' ) . '</strong> ';
			foreach ( $report['issue_counts'] as $code => $count ) {
				echo '<code>' . esc_html( $code ) . '</code> ×' . (int) $count . ' ';
			}
			echo '</p>';
		}

		echo '<h2>' . esc_html__( 'Payments (newest paid first)', 'lnkup-marketplace' ) . '</h2><table class="widefat striped"><thead><tr><th>Payment</th><th>Status</th><th>Paid at</th><th>Total</th><th>Ledger</th><th>Stripe</th><th>Webhooks</th><th>Issues</th></tr></thead><tbody>';
		foreach ( $report['payments'] as $payment ) {
			$ledger = array();
			foreach ( $payment['ledger'] as $entry ) {
				$ledger[] = '#' . $entry['id'] . ' ' . $entry['entry_type'] . ' ' . self::money( $entry['amount_cents'] );
			}
			$pi = $payment['stripe'];
			printf(
				'<tr><td>#%d %s<br><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td></tr>',
				(int) $payment['id'],
				esc_html( $payment['title'] ),
				esc_html( $payment['intent_id'] ?: $payment['session_id'] ),
				esc_html( $payment['status'] ),
				esc_html( $payment['paid_at'] ),
				esc_html( self::money( $payment['total_cents'] ) ),
				esc_html( implode( ' · ', $ledger ) ?: '—' ),
				esc_html( is_array( $pi ) ? ( ( $pi['status'] ?? $pi['error'] ?? '' ) . ' ' . self::money( $pi['amount_received'] ?? null ) ) : '—' ),
				count( $payment['webhooks'] ),
				esc_html( implode( ', ', $payment['issues'] ) )
			);
		}
		echo '</tbody></table>';

		if ( $report['same_amount_same_day'] ) {
			echo '<h3>' . esc_html__( 'Same amount on the same day', 'lnkup-marketplace' ) . '</h3><ul>';
			foreach ( $report['same_amount_same_day'] as $cluster ) {
				printf(
					'<li>%s · %s × %d · payments %s · <strong>%s</strong></li>',
					esc_html( $cluster['day'] ),
					esc_html( self::money( $cluster['amount_cents'] ) ),
					count( $cluster['entry_ids'] ),
					esc_html( implode( ', ', $cluster['payment_ids'] ) ),
					esc_html( 'distinct_payments' === $cluster['verdict'] ? 'separate payments — not duplicates' : 'possible duplicate' )
				);
			}
			echo '</ul>';
		}

		self::render_repair( $organizer_id );
	}

	/**
	 * @param int $organizer_id Organizer.
	 */
	private static function render_repair( $organizer_id ) {
		echo '<h2>' . esc_html__( 'Repair', 'lnkup-marketplace' ) . '</h2>';
		$plan = get_transient( 'lnkup_fin_repair_view_' . get_current_user_id() . '_' . $organizer_id );
		if ( is_array( $plan ) ) {
			if ( 'apply' === ( $plan['mode'] ?? '' ) ) {
				echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( 'Repair run %s applied: %d items.', $plan['run_id'], count( $plan['results'] ) ) ) . '</p></div>';
				delete_transient( 'lnkup_fin_repair_view_' . get_current_user_id() . '_' . $organizer_id );
			} elseif ( empty( $plan['items'] ) ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Dry run found nothing to repair for this organizer.', 'lnkup-marketplace' ) . '</p></div>';
			} else {
				echo '<table class="widefat striped"><thead><tr><th>Item</th><th>Type</th><th>Amount</th><th>Ledger effect</th><th>Will apply</th><th>Why</th></tr></thead><tbody>';
				foreach ( $plan['items'] as $item ) {
					printf(
						'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
						esc_html( $item['id'] ),
						esc_html( $item['type'] ),
						esc_html( self::money( $item['amount_cents'] ) ),
						esc_html( self::money( $item['ledger_effect_cents'] ) ),
						$item['applicable'] ? 'Yes' : 'No',
						esc_html( $item['reason'] )
					);
				}
				echo '</tbody></table>';
				if ( $plan['applicable_count'] > 0 ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1em">';
					wp_nonce_field( 'lnkup_fin_repair_apply_' . $organizer_id );
					echo '<input type="hidden" name="action" value="lnkup_fin_repair_apply"><input type="hidden" name="organizer_id" value="' . (int) $organizer_id . '">';
					echo '<input type="hidden" name="confirmation_token" value="' . esc_attr( $plan['confirmation_token'] ) . '">';
					echo '<p>' . esc_html( sprintf( 'Applies %d items (net ledger effect %s). Rows are never deleted; duplicates get offsetting debits. Expires %s.', $plan['applicable_count'], self::money( $plan['net_effect_cents'] ), $plan['expires_at'] ) ) . '</p>';
					echo '<p><label>' . esc_html__( 'Type APPLY to confirm', 'lnkup-marketplace' ) . ' <input type="text" name="confirm_text" required pattern="APPLY" autocomplete="off"></label> ';
					submit_button( __( 'Apply repair', 'lnkup-marketplace' ), 'primary', 'submit', false );
					echo '</p></form>';
				}
			}
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lnkup_fin_repair_dry_' . $organizer_id );
		echo '<input type="hidden" name="action" value="lnkup_fin_repair_dry"><input type="hidden" name="organizer_id" value="' . (int) $organizer_id . '">';
		submit_button( __( 'Dry run repair (writes nothing)', 'lnkup-marketplace' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	public static function handle_dry_run() {
		$organizer_id = absint( $_POST['organizer_id'] ?? 0 );
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lnkup_fin_repair_dry_' . $organizer_id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$plan = LNKup_Financial_Repair::dry_run( $organizer_id, true );
		set_transient( 'lnkup_fin_repair_view_' . get_current_user_id() . '_' . $organizer_id, $plan, LNKup_Financial_Repair::TOKEN_TTL );
		wp_safe_redirect( self::url( array( 'organizer_id' => $organizer_id ) ) . '#repair' );
		exit;
	}

	public static function handle_apply() {
		$organizer_id = absint( $_POST['organizer_id'] ?? 0 );
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lnkup_fin_repair_apply_' . $organizer_id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		if ( 'APPLY' !== sanitize_text_field( wp_unslash( $_POST['confirm_text'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Type APPLY to confirm the repair.', 'lnkup-marketplace' ), 400 );
		}
		$result = LNKup_Financial_Repair::apply( $organizer_id, sanitize_text_field( wp_unslash( $_POST['confirmation_token'] ?? '' ) ) );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), 409 );
		}
		set_transient( 'lnkup_fin_repair_view_' . get_current_user_id() . '_' . $organizer_id, $result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( array( 'organizer_id' => $organizer_id ) ) . '#repair' );
		exit;
	}
}
