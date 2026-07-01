<?php
/**
 * City Connector admin UI.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Connector_Admin {

	/**
	 * Init admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 26 );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
	}

	/**
	 * Add submenu page.
	 */
	public static function add_menu() {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Connectors', 'lnkup-marketplace' ),
			__( 'Connectors', 'lnkup-marketplace' ),
			'lnkup_manage_all',
			'lnkup-connectors',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Handle admin POST actions.
	 */
	public static function handle_actions() {
		if ( ! is_admin() || ! LNKup_Capabilities::user_has_manage_all() ) {
			return;
		}
		if ( empty( $_POST['lnkup_connector_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		check_admin_referer( 'lnkup_connector_admin' );
		$action = sanitize_key( wp_unslash( $_POST['lnkup_connector_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id     = absint( $_POST['connector_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 'approve' === $action && $id ) {
			$result = LNKup_Connector::approve( $id );
			self::redirect_notice( is_wp_error( $result ) ? $result->get_error_message() : __( 'Connector approved.', 'lnkup-marketplace' ), is_wp_error( $result ) ? 'error' : 'success' );
		}
		if ( 'pause' === $action && $id ) {
			global $wpdb;
			$wpdb->update(
				LNKup_Connector_Tables::connectors_table(),
				array( 'status' => 'paused', 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => $id )
			);
			self::redirect_notice( __( 'Connector paused.', 'lnkup-marketplace' ) );
		}
		if ( 'reject' === $action && $id ) {
			global $wpdb;
			$wpdb->update(
				LNKup_Connector_Tables::connectors_table(),
				array( 'status' => 'rejected', 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => $id )
			);
			self::redirect_notice( __( 'Application rejected.', 'lnkup-marketplace' ) );
		}
		if ( 'reward_approve' === $action ) {
			$reward_id = absint( $_POST['reward_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$result    = LNKup_Connector_Rewards::approve_reward( $reward_id );
			self::redirect_notice(
				is_wp_error( $result ) ? $result->get_error_message() : __( 'Reward approved.', 'lnkup-marketplace' ),
				is_wp_error( $result ) ? 'error' : 'success',
				'rewards'
			);
		}
		if ( 'reward_paid' === $action ) {
			$reward_id = absint( $_POST['reward_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			LNKup_Connector_Rewards::mark_paid( $reward_id );
			self::redirect_notice( __( 'Reward marked paid.', 'lnkup-marketplace' ), 'success', 'rewards' );
		}
		if ( 'generate_code' === $action && $id ) {
			$custom = sanitize_text_field( wp_unslash( $_POST['custom_code'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			LNKup_Connector::generate_code( $id, $custom );
			self::redirect_notice( __( 'Referral code updated.', 'lnkup-marketplace' ), 'success', 'active' );
		}
	}

	/**
	 * Redirect with admin notice.
	 *
	 * @param string $message Message.
	 * @param string $type    notice type.
	 * @param string $tab     Tab slug.
	 */
	private static function redirect_notice( $message, $type = 'success', $tab = 'pending' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'lnkup-connectors',
					'tab'     => $tab,
					'message' => rawurlencode( $message ),
					'type'    => $type,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render admin page.
	 */
	public static function render_page() {
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'pending'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'pending', 'active', 'rewards' ), true ) ) {
			$tab = 'pending';
		}
		$metrics = LNKup_Connector::admin_metrics();
		global $wpdb;
		$table = LNKup_Connector_Tables::connectors_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$connectors = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT 100", 'pending' === $tab ? 'pending' : 'active' ),
			ARRAY_A
		);
		if ( 'active' === $tab ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$connectors = $wpdb->get_results( "SELECT * FROM {$table} WHERE status IN ('active','paused') ORDER BY updated_at DESC LIMIT 100", ARRAY_A );
		}
		$rewards_table = LNKup_Treasury_Tables::referral_rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rewards = $wpdb->get_results(
			"SELECT * FROM {$rewards_table} WHERE connector_id > 0 ORDER BY created_at DESC LIMIT 100",
			ARRAY_A
		);
		$settings = LNKup_Connector_Rewards::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'City Connectors', 'lnkup-marketplace' ); ?></h1>
			<?php if ( ! empty( $_GET['message'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['type'] ?? 'success' ) ) ); ?> is-dismissible"> <!-- phpcs:ignore WordPress.Security.NonceVerification.Recommended -->
					<p><?php echo esc_html( wp_unslash( $_GET['message'] ) ); ?></p> <!-- phpcs:ignore WordPress.Security.NonceVerification.Recommended -->
				</div>
			<?php endif; ?>
			<div class="lnkup-admin-metrics" style="display:flex;gap:1rem;flex-wrap:wrap;margin:1rem 0;">
				<div class="card"><strong><?php echo esc_html( (string) $metrics['active'] ); ?></strong> <?php esc_html_e( 'Active', 'lnkup-marketplace' ); ?></div>
				<div class="card"><strong><?php echo esc_html( (string) $metrics['pending'] ); ?></strong> <?php esc_html_e( 'Pending', 'lnkup-marketplace' ); ?></div>
				<div class="card"><strong><?php echo esc_html( (string) $metrics['referrals'] ); ?></strong> <?php esc_html_e( 'Referrals', 'lnkup-marketplace' ); ?></div>
				<div class="card"><strong>$<?php echo esc_html( number_format( (float) $metrics['revenue'], 2 ) ); ?></strong> <?php esc_html_e( 'Attributed revenue', 'lnkup-marketplace' ); ?></div>
				<div class="card"><strong><?php echo esc_html( (string) $metrics['rewards_pending'] ); ?></strong> <?php esc_html_e( 'Rewards pending', 'lnkup-marketplace' ); ?></div>
			</div>
			<p class="description">
				<?php
				printf(
					/* translators: %s reward mode */
					esc_html__( 'Reward mode: %s (configure in LNKup Settings)', 'lnkup-marketplace' ),
					esc_html( $settings['mode'] )
				);
				?>
			</p>
			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-connectors&tab=pending' ) ); ?>" class="nav-tab<?php echo 'pending' === $tab ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Pending', 'lnkup-marketplace' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-connectors&tab=active' ) ); ?>" class="nav-tab<?php echo 'active' === $tab ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Active', 'lnkup-marketplace' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-connectors&tab=rewards' ) ); ?>" class="nav-tab<?php echo 'rewards' === $tab ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Rewards', 'lnkup-marketplace' ); ?></a>
			</h2>
			<?php if ( 'rewards' === $tab ) : ?>
				<table class="widefat striped" style="margin-top:1rem;">
					<thead><tr><th>ID</th><th><?php esc_html_e( 'Connector', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Amount', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( (array) $rewards as $reward ) : ?>
						<?php $connector = LNKup_Connector::get( (int) ( $reward['connector_id'] ?? 0 ) ); ?>
						<tr>
							<td><?php echo esc_html( (string) $reward['id'] ); ?></td>
							<td><?php echo esc_html( $connector['display_name'] ?? '' ); ?></td>
							<td><?php echo esc_html( (string) ( $reward['event_type'] ?? '' ) ); ?></td>
							<td>$<?php echo esc_html( number_format( (float) ( $reward['amount'] ?? 0 ), 2 ) ); ?></td>
							<td><?php echo esc_html( (string) ( $reward['reward_status'] ?? '' ) ); ?></td>
							<td>
								<?php if ( 'pending' === ( $reward['reward_status'] ?? '' ) ) : ?>
									<form method="post" style="display:inline;"><?php wp_nonce_field( 'lnkup_connector_admin' ); ?>
										<input type="hidden" name="lnkup_connector_action" value="reward_approve" />
										<input type="hidden" name="reward_id" value="<?php echo esc_attr( (string) $reward['id'] ); ?>" />
										<button type="submit" class="button button-small"><?php esc_html_e( 'Approve', 'lnkup-marketplace' ); ?></button>
									</form>
								<?php elseif ( 'approved' === ( $reward['reward_status'] ?? '' ) ) : ?>
									<form method="post" style="display:inline;"><?php wp_nonce_field( 'lnkup_connector_admin' ); ?>
										<input type="hidden" name="lnkup_connector_action" value="reward_paid" />
										<input type="hidden" name="reward_id" value="<?php echo esc_attr( (string) $reward['id'] ); ?>" />
										<button type="submit" class="button button-small"><?php esc_html_e( 'Mark paid', 'lnkup-marketplace' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<table class="widefat striped" style="margin-top:1rem;">
					<thead><tr><th><?php esc_html_e( 'Name', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Email', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Market', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( (array) $connectors as $row ) : ?>
						<?php $code = LNKup_Connector::get_code_for_connector( (int) $row['id'] ); ?>
						<tr>
							<td><?php echo esc_html( $row['display_name'] ?? '' ); ?></td>
							<td><?php echo esc_html( $row['email'] ?? '' ); ?></td>
							<td><?php echo esc_html( $row['market_slug'] ?? '' ); ?></td>
							<td><?php echo esc_html( $row['status'] ?? '' ); ?><?php if ( $code ) : ?> · <code><?php echo esc_html( $code['code'] ?? '' ); ?></code><?php endif; ?></td>
							<td>
								<?php if ( 'pending' === ( $row['status'] ?? '' ) ) : ?>
									<form method="post" style="display:inline;"><?php wp_nonce_field( 'lnkup_connector_admin' ); ?>
										<input type="hidden" name="lnkup_connector_action" value="approve" />
										<input type="hidden" name="connector_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
										<button type="submit" class="button button-primary button-small"><?php esc_html_e( 'Approve', 'lnkup-marketplace' ); ?></button>
									</form>
									<form method="post" style="display:inline;"><?php wp_nonce_field( 'lnkup_connector_admin' ); ?>
										<input type="hidden" name="lnkup_connector_action" value="reject" />
										<input type="hidden" name="connector_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
										<button type="submit" class="button button-small"><?php esc_html_e( 'Reject', 'lnkup-marketplace' ); ?></button>
									</form>
								<?php else : ?>
									<form method="post" style="display:inline;"><?php wp_nonce_field( 'lnkup_connector_admin' ); ?>
										<input type="hidden" name="lnkup_connector_action" value="pause" />
										<input type="hidden" name="connector_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
										<button type="submit" class="button button-small"><?php esc_html_e( 'Pause', 'lnkup-marketplace' ); ?></button>
									</form>
									<form method="post" style="display:inline-flex;gap:4px;align-items:center;"><?php wp_nonce_field( 'lnkup_connector_admin' ); ?>
										<input type="hidden" name="lnkup_connector_action" value="generate_code" />
										<input type="hidden" name="connector_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
										<input type="text" name="custom_code" placeholder="<?php esc_attr_e( 'Custom code', 'lnkup-marketplace' ); ?>" class="regular-text" style="width:120px;" />
										<button type="submit" class="button button-small"><?php esc_html_e( 'Set code', 'lnkup-marketplace' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
