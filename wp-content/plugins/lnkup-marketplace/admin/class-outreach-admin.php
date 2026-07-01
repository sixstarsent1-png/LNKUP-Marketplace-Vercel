<?php
/**
 * Admin outreach campaigns.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Outreach_Admin {

	/**
	 * Init admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 25 );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
	}

	/**
	 * Add submenu page.
	 */
	public static function add_menu() {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Outreach', 'lnkup-marketplace' ),
			__( 'Outreach', 'lnkup-marketplace' ),
			'lnkup_manage_all',
			'lnkup-outreach',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Handle form POST actions.
	 */
	public static function handle_actions() {
		if ( ! is_admin() || ! current_user_can( 'lnkup_manage_all' ) ) {
			return;
		}
		if ( empty( $_POST['lnkup_outreach_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		check_admin_referer( 'lnkup_outreach_admin' );

		$action = sanitize_key( wp_unslash( $_POST['lnkup_outreach_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 'create' === $action ) {
			self::handle_create();
		} elseif ( 'queue' === $action ) {
			self::handle_queue();
		} elseif ( 'cancel' === $action ) {
			self::handle_cancel();
		} elseif ( 'retry' === $action ) {
			self::handle_retry();
		}
	}

	/**
	 * Create or save draft campaign.
	 */
	protected static function handle_create() {
		$queue = ! empty( $_POST['lnkup_outreach_queue'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$lines = self::collect_recipient_lines();

		$campaign_id = LNKup_Outreach::create_campaign(
			array(
				'name'        => sanitize_text_field( wp_unslash( $_POST['campaign_name'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'target_role' => sanitize_text_field( wp_unslash( $_POST['target_role'] ?? 'lnkup_vendor' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'message'     => sanitize_textarea_field( wp_unslash( $_POST['campaign_message'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'source'      => 'admin',
				'created_by'  => get_current_user_id(),
			)
		);

		if ( is_wp_error( $campaign_id ) ) {
			self::redirect_with_notice( 'error', $campaign_id->get_error_message() );
		}

		if ( $lines ) {
			$import = LNKup_Outreach::import_recipients( $campaign_id, $lines );
			if ( is_wp_error( $import ) ) {
				self::redirect_with_notice( 'error', $import->get_error_message(), $campaign_id );
			}
		}

		if ( $queue ) {
			$queued = LNKup_Outreach::queue_campaign( $campaign_id );
			if ( is_wp_error( $queued ) ) {
				self::redirect_with_notice( 'error', $queued->get_error_message(), $campaign_id );
			}
			self::redirect_with_notice( 'success', __( 'Campaign queued for sending.', 'lnkup-marketplace' ), $campaign_id );
		}

		self::redirect_with_notice( 'success', __( 'Campaign saved as draft.', 'lnkup-marketplace' ), $campaign_id );
	}

	/**
	 * Queue existing draft campaign.
	 */
	protected static function handle_queue() {
		$campaign_id = absint( $_POST['campaign_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result      = LNKup_Outreach::queue_campaign( $campaign_id );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( 'error', $result->get_error_message(), $campaign_id );
		}
		self::redirect_with_notice( 'success', __( 'Campaign queued.', 'lnkup-marketplace' ), $campaign_id );
	}

	/**
	 * Cancel campaign.
	 */
	protected static function handle_cancel() {
		$campaign_id = absint( $_POST['campaign_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result      = LNKup_Outreach::cancel_campaign( $campaign_id );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( 'error', $result->get_error_message(), $campaign_id );
		}
		self::redirect_with_notice( 'success', __( 'Campaign cancelled.', 'lnkup-marketplace' ), $campaign_id );
	}

	/**
	 * Retry failed recipients.
	 */
	protected static function handle_retry() {
		$campaign_id = absint( $_POST['campaign_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result      = LNKup_Outreach::retry_failed( $campaign_id );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( 'error', $result->get_error_message(), $campaign_id );
		}
		self::redirect_with_notice( 'success', __( 'Failed recipients re-queued.', 'lnkup-marketplace' ), $campaign_id );
	}

	/**
	 * Collect recipient lines from textarea or CSV upload.
	 *
	 * @return array
	 */
	protected static function collect_recipient_lines() {
		$lines = array();
		if ( ! empty( $_POST['recipients'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$lines = preg_split( '/\r\n|\r|\n/', wp_unslash( $_POST['recipients'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( ! empty( $_FILES['recipients_csv']['tmp_name'] ) && is_uploaded_file( $_FILES['recipients_csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$csv = file( $_FILES['recipients_csv']['tmp_name'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_file
			if ( is_array( $csv ) ) {
				$lines = array_merge( $lines, $csv );
			}
		}
		return array_filter( array_map( 'trim', $lines ) );
	}

	/**
	 * Redirect with admin notice.
	 *
	 * @param string   $type        Notice type.
	 * @param string   $message     Message.
	 * @param int|null $campaign_id Campaign ID.
	 */
	protected static function redirect_with_notice( $type, $message, $campaign_id = null ) {
		$args = array(
			'page'           => 'lnkup-outreach',
			'lnkup_notice'   => $type,
			'lnkup_message'  => rawurlencode( $message ),
		);
		if ( $campaign_id ) {
			$args['campaign_id'] = (int) $campaign_id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render admin page.
	 */
	public static function render_page() {
		if ( ! LNKup_Capabilities::user_has_manage_all() && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}

		$view_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$campaign = $view_id ? LNKup_Outreach::get_campaign( $view_id ) : null;
		$campaigns = LNKup_Outreach::list_campaigns( array( 'source' => 'admin', 'limit' => 50 ) );
		$settings  = lnkup_get_settings();
		$max       = (int) ( $settings['outreach_admin_max_per_campaign'] ?? 500 );
		$rate      = class_exists( 'LNKup_Outreach' ) ? LNKup_Outreach::get_rate_limit_status() : array();

		if ( ! empty( $_GET['lnkup_notice'] ) && ! empty( $_GET['lnkup_message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notice_type = sanitize_key( wp_unslash( $_GET['lnkup_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notice_msg  = sanitize_text_field( wp_unslash( $_GET['lnkup_message'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( 'error' === $notice_type ? 'error' : 'success' ),
				esc_html( $notice_msg )
			);
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Platform Outreach', 'lnkup-marketplace' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Mass invite vendors and organizers by email for launch campaigns. Emails are queued and sent in batches via wp_mail.', 'lnkup-marketplace' ); ?></p>

			<?php if ( ! empty( $rate['enabled'] ) ) : ?>
				<div class="notice notice-info inline" style="max-width:960px;margin:1rem 0;padding:1rem;">
					<p><strong><?php esc_html_e( 'Send throttling active', 'lnkup-marketplace' ); ?></strong></p>
					<ul style="margin:0.5rem 0 0;list-style:disc;padding-left:1.25rem;">
						<li><?php printf( esc_html__( 'Sent this hour: %1$d / %2$d', 'lnkup-marketplace' ), (int) ( $rate['sent_hour'] ?? 0 ), (int) ( $rate['hourly_limit'] ?? 0 ) ); ?></li>
						<li><?php printf( esc_html__( 'Sent today: %1$d / %2$d', 'lnkup-marketplace' ), (int) ( $rate['sent_day'] ?? 0 ), (int) ( $rate['daily_limit'] ?? 0 ) ); ?></li>
						<?php if ( ! empty( $rate['next_run'] ) ) : ?>
							<li><?php printf( esc_html__( 'Next batch scheduled: %s', 'lnkup-marketplace' ), esc_html( wp_date( 'M j, Y g:i A', (int) $rate['next_run'] ) ) ); ?></li>
						<?php endif; ?>
						<?php if ( ! empty( $rate['limited'] ) ) : ?>
							<li>
								<?php
								if ( 'day' === $rate['limited'] ) {
									printf(
										esc_html__( 'Daily cap reached — auto-resumes %s', 'lnkup-marketplace' ),
										esc_html( wp_date( 'M j, Y g:i A', strtotime( (string) ( $rate['next_resume_at'] ?? '' ) ) ) )
									);
								} else {
									printf(
										esc_html__( 'Hourly cap reached — auto-resumes %s', 'lnkup-marketplace' ),
										esc_html( wp_date( 'M j, Y g:i A', strtotime( (string) ( $rate['next_resume_at'] ?? '' ) ) ) )
									);
								}
								?>
							</li>
						<?php endif; ?>
					</ul>
					<p class="description" style="margin-top:0.75rem;"><?php esc_html_e( 'Tune limits under LNKup → Settings → Outreach throttling.', 'lnkup-marketplace' ); ?></p>
				</div>
			<?php endif; ?>

			<div style="display:grid;grid-template-columns:1fr 1fr;gap:2rem;margin-top:1.5rem;align-items:start;">
				<div>
					<h2><?php esc_html_e( 'Create campaign', 'lnkup-marketplace' ); ?></h2>
					<form method="post" enctype="multipart/form-data" action="">
						<?php wp_nonce_field( 'lnkup_outreach_admin' ); ?>
						<input type="hidden" name="lnkup_outreach_action" value="create" />
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="campaign_name"><?php esc_html_e( 'Campaign name', 'lnkup-marketplace' ); ?></label></th>
								<td><input type="text" class="regular-text" id="campaign_name" name="campaign_name" required /></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Target role', 'lnkup-marketplace' ); ?></th>
								<td>
									<label><input type="radio" name="target_role" value="lnkup_vendor" checked /> <?php esc_html_e( 'Vendor', 'lnkup-marketplace' ); ?></label><br />
									<label><input type="radio" name="target_role" value="lnkup_organizer" /> <?php esc_html_e( 'Organizer', 'lnkup-marketplace' ); ?></label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="campaign_message"><?php esc_html_e( 'Custom message', 'lnkup-marketplace' ); ?></label></th>
								<td><textarea class="large-text" rows="3" id="campaign_message" name="campaign_message" placeholder="<?php esc_attr_e( 'Optional personal note included in the email', 'lnkup-marketplace' ); ?>"></textarea></td>
							</tr>
							<tr>
								<th scope="row"><label for="recipients"><?php esc_html_e( 'Recipients', 'lnkup-marketplace' ); ?></label></th>
								<td>
									<textarea class="large-text code" rows="8" id="recipients" name="recipients" placeholder="email@example.com&#10;name@company.com, Jane Doe"></textarea>
									<p class="description"><?php printf( esc_html__( 'One email per line. Optional name after comma. Max %d per campaign.', 'lnkup-marketplace' ), $max ); ?></p>
									<p><label><?php esc_html_e( 'Or upload CSV', 'lnkup-marketplace' ); ?> <input type="file" name="recipients_csv" accept=".csv,text/csv" /></label></p>
								</td>
							</tr>
						</table>
						<p class="submit">
							<button type="submit" class="button"><?php esc_html_e( 'Save draft', 'lnkup-marketplace' ); ?></button>
							<button type="submit" class="button button-primary" name="lnkup_outreach_queue" value="1"><?php esc_html_e( 'Queue & send', 'lnkup-marketplace' ); ?></button>
						</p>
					</form>
				</div>

				<div>
					<h2><?php esc_html_e( 'Campaigns', 'lnkup-marketplace' ); ?></h2>
					<?php if ( ! $campaigns ) : ?>
						<p><?php esc_html_e( 'No campaigns yet.', 'lnkup-marketplace' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Name', 'lnkup-marketplace' ); ?></th>
									<th><?php esc_html_e( 'Role', 'lnkup-marketplace' ); ?></th>
									<th><?php esc_html_e( 'Progress', 'lnkup-marketplace' ); ?></th>
									<th><?php esc_html_e( 'Registered', 'lnkup-marketplace' ); ?></th>
									<th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $campaigns as $c ) : ?>
									<tr>
										<td>
											<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'lnkup-outreach', 'campaign_id' => (int) $c['id'] ), admin_url( 'admin.php' ) ) ); ?>">
												<?php echo esc_html( $c['name'] ); ?>
											</a>
										</td>
										<td><?php echo esc_html( str_replace( 'lnkup_', '', $c['target_role'] ) ); ?></td>
										<td><?php echo esc_html( (int) $c['sent'] . '/' . (int) $c['total'] ); ?></td>
										<td><?php echo esc_html( (string) (int) $c['registered'] ); ?></td>
										<td><?php echo esc_html( $c['status'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $campaign ) : ?>
				<hr />
				<h2><?php echo esc_html( $campaign['name'] ); ?></h2>
				<p>
					<strong><?php esc_html_e( 'Status:', 'lnkup-marketplace' ); ?></strong> <?php echo esc_html( $campaign['status'] ); ?> |
					<strong><?php esc_html_e( 'Sent:', 'lnkup-marketplace' ); ?></strong> <?php echo esc_html( (string) (int) $campaign['sent'] ); ?> /
					<?php echo esc_html( (string) (int) $campaign['total'] ); ?> |
					<strong><?php esc_html_e( 'Registered:', 'lnkup-marketplace' ); ?></strong> <?php echo esc_html( (string) (int) $campaign['registered'] ); ?> |
					<strong><?php esc_html_e( 'Skipped:', 'lnkup-marketplace' ); ?></strong> <?php echo esc_html( (string) (int) $campaign['skipped'] ); ?> |
					<strong><?php esc_html_e( 'Failed:', 'lnkup-marketplace' ); ?></strong> <?php echo esc_html( (string) (int) $campaign['failed'] ); ?>
				</p>
				<?php
				global $wpdb;
				$rec_table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$pending_count = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$rec_table} WHERE campaign_id = %d AND status = %s",
						(int) $campaign['id'],
						LNKup_Outreach::REC_PENDING
					)
				);
				if ( $pending_count > 0 && in_array( $campaign['status'], array( LNKup_Outreach::STATUS_QUEUED, LNKup_Outreach::STATUS_PROCESSING ), true ) && ! empty( $rate['limited'] ) ) :
					?>
					<div class="notice notice-warning inline"><p>
						<?php
						if ( 'day' === $rate['limited'] ) {
							printf(
								esc_html__( '%1$d invites pending — daily send cap reached. Auto-resumes %2$s.', 'lnkup-marketplace' ),
								$pending_count,
								esc_html( wp_date( 'M j, Y g:i A', strtotime( (string) ( $rate['next_resume_at'] ?? '' ) ) ) )
							);
						} else {
							printf(
								esc_html__( '%1$d invites pending — hourly send cap reached. Auto-resumes %2$s.', 'lnkup-marketplace' ),
								$pending_count,
								esc_html( wp_date( 'M j, Y g:i A', strtotime( (string) ( $rate['next_resume_at'] ?? '' ) ) ) )
							);
						}
						?>
					</p></div>
				<?php endif; ?>
				<p>
					<?php if ( in_array( $campaign['status'], array( LNKup_Outreach::STATUS_DRAFT, LNKup_Outreach::STATUS_QUEUED ), true ) ) : ?>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'lnkup_outreach_admin' ); ?>
							<input type="hidden" name="lnkup_outreach_action" value="queue" />
							<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $campaign['id'] ); ?>" />
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Queue & send', 'lnkup-marketplace' ); ?></button>
						</form>
					<?php endif; ?>
					<?php if ( in_array( $campaign['status'], array( LNKup_Outreach::STATUS_QUEUED, LNKup_Outreach::STATUS_PROCESSING ), true ) ) : ?>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'lnkup_outreach_admin' ); ?>
							<input type="hidden" name="lnkup_outreach_action" value="cancel" />
							<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $campaign['id'] ); ?>" />
							<button type="submit" class="button"><?php esc_html_e( 'Cancel', 'lnkup-marketplace' ); ?></button>
						</form>
					<?php endif; ?>
					<?php if ( (int) $campaign['failed'] > 0 ) : ?>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'lnkup_outreach_admin' ); ?>
							<input type="hidden" name="lnkup_outreach_action" value="retry" />
							<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $campaign['id'] ); ?>" />
							<button type="submit" class="button"><?php esc_html_e( 'Retry failed', 'lnkup-marketplace' ); ?></button>
						</form>
					<?php endif; ?>
				</p>
				<?php
				$recipients = LNKup_Outreach::list_recipients( (int) $campaign['id'], 200 );
				if ( $recipients ) :
					?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Email', 'lnkup-marketplace' ); ?></th>
								<th><?php esc_html_e( 'Name', 'lnkup-marketplace' ); ?></th>
								<th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th>
								<th><?php esc_html_e( 'Sent', 'lnkup-marketplace' ); ?></th>
								<th><?php esc_html_e( 'Registered', 'lnkup-marketplace' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recipients as $r ) : ?>
								<tr>
									<td><?php echo esc_html( $r['email'] ); ?></td>
									<td><?php echo esc_html( $r['name'] ); ?></td>
									<td><?php echo esc_html( $r['status'] ); ?></td>
									<td><?php echo esc_html( $r['sent_at'] ?: '—' ); ?></td>
									<td><?php echo esc_html( $r['registered_at'] ?: '—' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
