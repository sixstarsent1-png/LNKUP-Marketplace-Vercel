<?php
/**
 * Admin Financial Center.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 29 );
		add_action( 'admin_post_lnkup_payout_complete', array( __CLASS__, 'handle_payout_complete' ) );
		add_action( 'admin_post_lnkup_org_payout_complete', array( __CLASS__, 'handle_org_payout_complete' ) );
		add_action( 'admin_post_lnkup_fraud_resolve', array( __CLASS__, 'handle_fraud_resolve' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Financial Center', 'lnkup-marketplace' ),
			__( 'Financial', 'lnkup-marketplace' ),
			'lnkup_manage_marketplace_financials',
			'lnkup-financial',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! LNKup_Capabilities::can_manage_marketplace_financials() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$tab     = sanitize_key( $_GET['tab'] ?? 'overview' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$summary = LNKup_Financial::admin_dashboard();
		$fraud   = LNKup_Fraud_Flags::list_open();
		global $wpdb;
		$pending_payouts = $wpdb->get_results(
			"SELECT * FROM " . LNKup_Financial_Tables::payouts_table() . " WHERE status IN ('pending','scheduled') ORDER BY scheduled_date ASC LIMIT 20",
			ARRAY_A
		);
		$org_payouts = array();
		if ( class_exists( 'LNKup_Treasury_Tables' ) && LNKup_Financial_Tables::table_exists( LNKup_Treasury_Tables::organizer_payouts_table() ) ) {
			$org_payouts = $wpdb->get_results(
				"SELECT * FROM " . LNKup_Treasury_Tables::organizer_payouts_table() . " WHERE status IN ('requested','approved','processing') ORDER BY created_at ASC LIMIT 20",
				ARRAY_A
			);
		}
		$pending_refunds = $wpdb->get_results(
			"SELECT * FROM " . LNKup_Financial_Tables::refunds_table() . " WHERE status = 'pending' ORDER BY created_at DESC LIMIT 20",
			ARRAY_A
		);
		$approvals = class_exists( 'LNKup_Approval_Workflows' ) ? LNKup_Approval_Workflows::list_pending( 20 ) : array();
		$tabs = array(
			'overview'     => __( 'Platform Overview', 'lnkup-marketplace' ),
			'payouts'      => __( 'Payouts', 'lnkup-marketplace' ),
			'refunds'      => __( 'Refunds', 'lnkup-marketplace' ),
			'approvals'    => __( 'Approvals', 'lnkup-marketplace' ),
			'subscriptions'=> __( 'Subscriptions', 'lnkup-marketplace' ),
			'fraud'        => __( 'Fraud', 'lnkup-marketplace' ),
		);
		?>
		<div class="wrap lnkup-fin-admin">
			<h1><?php esc_html_e( 'Marketplace Financial Center', 'lnkup-marketplace' ); ?></h1>
			<nav class="nav-tab-wrapper" style="margin:1rem 0;">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-financial&tab=' . $slug ) ); ?>" class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php if ( 'overview' === $tab ) : ?>
			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;margin:1.5rem 0;">
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( lnkup_currency( $summary['gmv'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'GMV', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( lnkup_currency( $summary['platform_revenue'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'Platform Revenue', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( lnkup_currency( $summary['treasury_platform_fees'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'Treasury Fees', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( lnkup_currency( $summary['subscription_mrr'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'Sub MRR', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) ( $summary['pending_refunds'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'Pending Refunds', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) ( $summary['outstanding_payouts'] ?? $summary['pending_payouts'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'Outstanding Payouts', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) ( $summary['open_disputes'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'Open Disputes', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) ( $summary['refund_percent'] ?? 0 ) ); ?>%</strong><br><?php esc_html_e( 'Refund Rate', 'lnkup-marketplace' ); ?></div>
			</div>
			<?php if ( ! empty( $summary['top_organizers'] ) ) : ?>
				<h2><?php esc_html_e( 'Top Organizers', 'lnkup-marketplace' ); ?></h2>
				<ul><?php foreach ( $summary['top_organizers'] as $org ) : ?><li><?php echo esc_html( $org['name'] . ' — ' . lnkup_currency( $org['revenue'] ) ); ?></li><?php endforeach; ?></ul>
			<?php endif; ?>
			<?php endif; ?>
			<?php if ( in_array( $tab, array( 'overview', 'payouts' ), true ) ) : ?>
			<h2><?php esc_html_e( 'Legacy Event Payouts', 'lnkup-marketplace' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Net', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Scheduled', 'lnkup-marketplace' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $pending_payouts as $p ) : ?>
					<tr>
						<td><?php echo esc_html( get_the_title( (int) $p['event_id'] ) ); ?></td>
						<td><?php echo esc_html( lnkup_currency( $p['net_amount'] ) ); ?></td>
						<td><?php echo esc_html( $p['scheduled_date'] ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="lnkup_payout_complete" />
								<input type="hidden" name="payout_id" value="<?php echo esc_attr( (string) $p['id'] ); ?>" />
								<?php wp_nonce_field( 'lnkup_payout_complete' ); ?>
								<button type="submit" class="button"><?php esc_html_e( 'Mark Paid', 'lnkup-marketplace' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<h2><?php esc_html_e( 'Organizer Treasury Cashouts', 'lnkup-marketplace' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'User', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Net', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $org_payouts as $p ) : ?>
					<tr>
						<td><?php echo esc_html( get_userdata( (int) $p['user_id'] )->display_name ?? '#' . $p['user_id'] ); ?></td>
						<td><?php echo esc_html( lnkup_currency( $p['net_amount'] ) ); ?></td>
						<td><?php echo esc_html( $p['status'] ); ?></td>
						<td>
							<?php if ( class_exists( 'LNKup_Organizer_Payouts' ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="lnkup_org_payout_complete" />
								<input type="hidden" name="payout_id" value="<?php echo esc_attr( (string) $p['id'] ); ?>" />
								<?php wp_nonce_field( 'lnkup_org_payout_complete' ); ?>
								<button type="submit" class="button"><?php esc_html_e( 'Mark Paid', 'lnkup-marketplace' ); ?></button>
							</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
			<?php if ( in_array( $tab, array( 'overview', 'refunds' ), true ) ) : ?>
			<h2><?php esc_html_e( 'Pending Refund Requests', 'lnkup-marketplace' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Amount', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Type', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Reason', 'lnkup-marketplace' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $pending_refunds as $r ) : ?>
					<tr>
						<td><?php echo esc_html( lnkup_currency( $r['amount'] ) ); ?></td>
						<td><?php echo esc_html( $r['refund_type'] ); ?></td>
						<td><?php echo esc_html( $r['reason'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
			<?php if ( in_array( $tab, array( 'overview', 'approvals' ), true ) && $approvals ) : ?>
			<h2><?php esc_html_e( 'Approval Queue', 'lnkup-marketplace' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Type', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Amount', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $approvals as $a ) : ?>
					<tr>
						<td><?php echo esc_html( $a['action_type'] ); ?></td>
						<td><?php echo esc_html( lnkup_currency( $a['amount'] ) ); ?></td>
						<td><?php echo esc_html( $a['status'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
			<?php if ( 'subscriptions' === $tab && class_exists( 'LNKup_Subscriptions' ) ) : ?>
			<h2><?php esc_html_e( 'Subscription Plans', 'lnkup-marketplace' ); ?></h2>
			<ul>
				<?php foreach ( LNKup_Subscriptions::list_plans() as $plan ) : ?>
					<li><?php echo esc_html( $plan['name'] . ' — ' . lnkup_currency( $plan['price_monthly'] ?? 0 ) . '/mo' ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
			<?php if ( in_array( $tab, array( 'overview', 'fraud' ), true ) ) : ?>
			<h2><?php esc_html_e( 'Fraud Review Queue', 'lnkup-marketplace' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'User', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Type', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Details', 'lnkup-marketplace' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $fraud as $f ) : ?>
					<tr>
						<td><?php echo esc_html( get_userdata( (int) $f['user_id'] )->display_name ?? '#' . $f['user_id'] ); ?></td>
						<td><?php echo esc_html( $f['flag_type'] ); ?></td>
						<td><?php echo esc_html( $f['details'] ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="lnkup_fraud_resolve" />
								<input type="hidden" name="flag_id" value="<?php echo esc_attr( (string) $f['id'] ); ?>" />
								<?php wp_nonce_field( 'lnkup_fraud_resolve' ); ?>
								<button type="submit" class="button"><?php esc_html_e( 'Resolve', 'lnkup-marketplace' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_payout_complete() {
		check_admin_referer( 'lnkup_payout_complete' );
		if ( ! LNKup_Capabilities::can_manage_marketplace_financials() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$payout_id = absint( $_POST['payout_id'] ?? 0 );
		LNKup_Payouts::mark_completed( $payout_id, 'manual_' . wp_generate_password( 8, false ) );
		wp_safe_redirect( admin_url( 'admin.php?page=lnkup-financial&updated=1' ) );
		exit;
	}

	public static function handle_org_payout_complete() {
		check_admin_referer( 'lnkup_org_payout_complete' );
		if ( ! LNKup_Capabilities::can_manage_marketplace_financials() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$payout_id = absint( $_POST['payout_id'] ?? 0 );
		if ( class_exists( 'LNKup_Organizer_Payouts' ) ) {
			LNKup_Organizer_Payouts::mark_paid( $payout_id, 'manual_' . wp_generate_password( 8, false ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=lnkup-financial&tab=payouts&updated=1' ) );
		exit;
	}

	public static function handle_fraud_resolve() {
		check_admin_referer( 'lnkup_fraud_resolve' );
		if ( ! LNKup_Capabilities::can_manage_marketplace_financials() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		LNKup_Fraud_Flags::resolve( absint( $_POST['flag_id'] ?? 0 ) );
		wp_safe_redirect( admin_url( 'admin.php?page=lnkup-financial&updated=1' ) );
		exit;
	}
}
