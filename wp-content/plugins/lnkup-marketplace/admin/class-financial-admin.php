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
		add_action( 'admin_post_lnkup_recover_stranded', array( __CLASS__, 'handle_recover_stranded' ) );
		add_action( 'admin_post_lnkup_platform_payout', array( __CLASS__, 'handle_platform_payout' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
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

	/**
	 * Flash notices after admin_post redirects.
	 */
	public static function admin_notices() {
		if ( ! isset( $_GET['page'] ) || 'lnkup-financial' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( empty( $_GET['lnkup_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$code = sanitize_key( wp_unslash( $_GET['lnkup_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg  = isset( $_GET['lnkup_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['lnkup_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$class = ( 'error' === $code ) ? 'notice notice-error' : 'notice notice-success';
		if ( ! $msg ) {
			$msg = ( 'error' === $code )
				? __( 'Action failed.', 'lnkup-marketplace' )
				: __( 'Action completed.', 'lnkup-marketplace' );
		}
		printf( '<div class="%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $msg ) );
	}

	public static function render_page() {
		if ( ! LNKup_Capabilities::can_manage_marketplace_financials() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$tab     = sanitize_key( $_GET['tab'] ?? 'overview' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$summary = LNKup_Financial::admin_dashboard();
		$fraud   = LNKup_Fraud_Flags::list_open();
		$split   = class_exists( 'LNKup_Stripe_Connect' ) ? LNKup_Stripe_Connect::platform_balance_split() : array( 'synced' => false );
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
			<?php self::render_platform_stripe_panel( $split ); ?>
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

	/**
	 * Live main Stripe balance + organizer-owed vs fee remainder + actions.
	 *
	 * @param array $split From LNKup_Stripe_Connect::platform_balance_split().
	 */
	private static function render_platform_stripe_panel( $split ) {
		$split = is_array( $split ) ? $split : array();
		?>
		<h2><?php esc_html_e( 'Main Stripe balance (mixed)', 'lnkup-marketplace' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Stripe main-account Available mixes organizer/vendor net (stranded) and LNKup platform fees. Recover organizer share to Connect before paying out LNKup fees. Organizer Money → Cash Out never shows this mixed total.', 'lnkup-marketplace' ); ?>
		</p>
		<?php if ( empty( $split['synced'] ) ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( ! empty( $split['error'] ) ? (string) $split['error'] : __( 'Could not load platform Stripe balance.', 'lnkup-marketplace' ) ); ?></p></div>
			<?php
			return;
		endif;
		$source_label = class_exists( 'LNKup_Stripe_Connect' )
			? LNKup_Stripe_Connect::format_source_types_breakdown( $split['source_types'] ?? array() )
			: '';
		?>
		<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin:1rem 0 1.5rem;">
			<div class="card" style="padding:1rem;"><strong><?php echo esc_html( lnkup_currency( $split['platform_available'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'Stripe Available', 'lnkup-marketplace' ); ?></div>
			<div class="card" style="padding:1rem;"><strong><?php echo esc_html( lnkup_currency( $split['platform_pending'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'Stripe Pending', 'lnkup-marketplace' ); ?></div>
			<div class="card" style="padding:1rem;"><strong><?php echo esc_html( lnkup_currency( $split['organizer_owed'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'Organizer-owed (recoverable)', 'lnkup-marketplace' ); ?></div>
			<div class="card" style="padding:1rem;"><strong><?php echo esc_html( lnkup_currency( $split['fee_remainder'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'LNKup fee remainder', 'lnkup-marketplace' ); ?></div>
		</div>
		<?php if ( $source_label ) : ?>
			<p class="description"><?php echo esc_html( $source_label ); ?></p>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Recover stranded → Connect', 'lnkup-marketplace' ); ?></h3>
		<?php
		$recoverable_rows = array_filter(
			(array) ( $split['organizers'] ?? array() ),
			static function ( $row ) {
				return ! empty( $row['can_recover'] ) && (float) ( $row['recoverable'] ?? 0 ) > 0;
			}
		);
		if ( empty( $recoverable_rows ) ) :
			?>
			<p class="description"><?php esc_html_e( 'No destination organizers with ledger-owed funds stranded on the platform.', 'lnkup-marketplace' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Organizer', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Owed on platform', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Mode', 'lnkup-marketplace' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $recoverable_rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $row['name'] ?? '' ) . ' (#' . (int) ( $row['user_id'] ?? 0 ) . ')' ); ?></td>
						<td><?php echo esc_html( lnkup_currency( $row['recoverable'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['settlement_mode'] ?? '' ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<input type="hidden" name="action" value="lnkup_recover_stranded" />
								<input type="hidden" name="organizer_id" value="<?php echo esc_attr( (string) (int) ( $row['user_id'] ?? 0 ) ); ?>" />
								<?php wp_nonce_field( 'lnkup_recover_stranded' ); ?>
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Move to Connect', 'lnkup-marketplace' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php
		$treasury_rows = array_filter(
			(array) ( $split['organizers'] ?? array() ),
			static function ( $row ) {
				return empty( $row['can_recover'] ) && (float) ( $row['recoverable'] ?? 0 ) > 0;
			}
		);
		if ( ! empty( $treasury_rows ) ) :
			?>
			<p class="description" style="margin-top:0.75rem;">
				<?php esc_html_e( 'Platform-treasury organizers below also have ledger available counted in organizer-owed (fee remainder is reduced). Recover those via organizer Cash Out / Transfer, not Move to Connect.', 'lnkup-marketplace' ); ?>
			</p>
			<ul>
				<?php foreach ( $treasury_rows as $row ) : ?>
					<li><?php echo esc_html( (string) ( $row['name'] ?? '' ) . ' — ' . lnkup_currency( $row['recoverable'] ?? 0 ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Pay out LNKup fee remainder', 'lnkup-marketplace' ); ?></h3>
		<?php $fee_max = (float) ( $split['fee_remainder'] ?? 0 ); ?>
		<?php if ( $fee_max <= 0 ) : ?>
			<p class="description"><?php esc_html_e( 'No fee remainder available to pay out (all Available is organizer-owed, or balance is $0).', 'lnkup-marketplace' ); ?></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:420px;">
				<input type="hidden" name="action" value="lnkup_platform_payout" />
				<?php wp_nonce_field( 'lnkup_platform_payout' ); ?>
				<p>
					<label for="lnkup_platform_payout_amount"><?php esc_html_e( 'Amount (USD)', 'lnkup-marketplace' ); ?></label><br />
					<input type="number" step="0.01" min="0.01" max="<?php echo esc_attr( (string) $fee_max ); ?>" name="amount" id="lnkup_platform_payout_amount" value="<?php echo esc_attr( number_format( $fee_max, 2, '.', '' ) ); ?>" required />
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: max fee remainder */
						esc_html__( 'Standard payout to the bank on the main Stripe account. Max %s (fee remainder only).', 'lnkup-marketplace' ),
						esc_html( lnkup_currency( $fee_max ) )
					);
					?>
				</p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Pay out fees', 'lnkup-marketplace' ); ?></button></p>
			</form>
		<?php endif; ?>
		<hr style="margin:2rem 0;" />
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

	/**
	 * Transfer ledger-owed stranded funds from platform → organizer Connect.
	 */
	public static function handle_recover_stranded() {
		check_admin_referer( 'lnkup_recover_stranded' );
		if ( ! LNKup_Capabilities::can_manage_marketplace_financials() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$organizer_id = absint( $_POST['organizer_id'] ?? 0 );
		$redirect     = admin_url( 'admin.php?page=lnkup-financial&tab=overview' );

		if ( ! $organizer_id || ! class_exists( 'LNKup_Stripe_Connect' ) ) {
			wp_safe_redirect( add_query_arg( array( 'lnkup_notice' => 'error', 'lnkup_msg' => rawurlencode( __( 'Invalid organizer.', 'lnkup-marketplace' ) ) ), $redirect ) );
			exit;
		}

		$cents = LNKup_Stripe_Connect::organizer_recoverable_platform_cents( $organizer_id );
		if ( $cents < 1 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'lnkup_notice' => 'error',
						'lnkup_msg'    => rawurlencode( __( 'Nothing recoverable for this organizer right now.', 'lnkup-marketplace' ) ),
					),
					$redirect
				)
			);
			exit;
		}

		$result = LNKup_Stripe_Connect::transfer_platform_to_connect(
			$organizer_id,
			$cents / 100,
			array( 'admin_recover' => '1' )
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'lnkup_notice' => 'error',
						'lnkup_msg'    => rawurlencode( $result->get_error_message() ),
					),
					$redirect
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'lnkup_notice' => 'success',
					'lnkup_msg'    => rawurlencode(
						sprintf(
							/* translators: 1: amount 2: transfer id */
							__( 'Moved %1$s to Connect (transfer %2$s).', 'lnkup-marketplace' ),
							lnkup_currency( $cents / 100 ),
							(string) ( $result['transfer_id'] ?? '' )
						)
					),
				),
				$redirect
			)
		);
		exit;
	}

	/**
	 * Standard payout of LNKup fee remainder from main Stripe account.
	 */
	public static function handle_platform_payout() {
		check_admin_referer( 'lnkup_platform_payout' );
		if ( ! LNKup_Capabilities::can_manage_marketplace_financials() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$amount   = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0;
		$redirect = admin_url( 'admin.php?page=lnkup-financial&tab=overview' );

		if ( ! class_exists( 'LNKup_Stripe_Connect' ) ) {
			wp_safe_redirect( add_query_arg( array( 'lnkup_notice' => 'error', 'lnkup_msg' => rawurlencode( __( 'Connect unavailable.', 'lnkup-marketplace' ) ) ), $redirect ) );
			exit;
		}

		$result = LNKup_Stripe_Connect::create_platform_payout( $amount );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'lnkup_notice' => 'error',
						'lnkup_msg'    => rawurlencode( $result->get_error_message() ),
					),
					$redirect
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'lnkup_notice' => 'success',
					'lnkup_msg'    => rawurlencode(
						sprintf(
							/* translators: 1: amount 2: payout id */
							__( 'Platform payout %1$s created (%2$s).', 'lnkup-marketplace' ),
							lnkup_currency( (float) ( $result['amount'] ?? $amount ) ),
							(string) ( $result['payout_id'] ?? '' )
						)
					),
				),
				$redirect
			)
		);
		exit;
	}
}
