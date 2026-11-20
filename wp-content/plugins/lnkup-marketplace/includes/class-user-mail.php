<?php
/**
 * Admin email to existing LNKup accounts.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_User_Mail {

	/**
	 * Register the admin screen.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 26 );
		add_action( 'admin_post_lnkup_queue_account_email', array( __CLASS__, 'handle_queue' ) );
	}

	/**
	 * Submenu under LNKup.
	 */
	public static function add_menu() {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Email accounts', 'lnkup-marketplace' ),
			__( 'Email accounts', 'lnkup-marketplace' ),
			'lnkup_manage_all',
			'lnkup-account-email',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Admin compose form and recent sends.
	 */
	public static function render_page() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}

		$campaigns = class_exists( 'LNKup_Outreach' )
			? LNKup_Outreach::list_campaigns(
				array(
					'source' => 'accounts',
					'limit'  => 30,
				)
			)
			: array();
		$settings = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();

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
			<h1><?php esc_html_e( 'Email accounts', 'lnkup-marketplace' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Send a custom email to vendors, organizers, or everyone who already has a LNKup account. Messages go out in batches and follow the outreach hourly and daily limits.', 'lnkup-marketplace' ); ?></p>
			<?php if ( empty( $settings['outreach_emails_enabled'] ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Outreach email sending is turned off, so queued messages will wait until it is enabled.', 'lnkup-marketplace' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'lnkup_queue_account_email' ); ?>
				<input type="hidden" name="action" value="lnkup_queue_account_email" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Audience', 'lnkup-marketplace' ); ?></th>
						<td>
							<label><input type="radio" name="audience" value="lnkup_vendor" checked /> <?php esc_html_e( 'All vendors', 'lnkup-marketplace' ); ?></label><br />
							<label><input type="radio" name="audience" value="lnkup_organizer" /> <?php esc_html_e( 'All organizers', 'lnkup-marketplace' ); ?></label><br />
							<label><input type="radio" name="audience" value="everyone" /> <?php esc_html_e( 'Everyone', 'lnkup-marketplace' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lnkup-account-subject"><?php esc_html_e( 'Subject', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="text" class="regular-text" id="lnkup-account-subject" name="mail_subject" maxlength="140" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="lnkup-account-message"><?php esc_html_e( 'Message', 'lnkup-marketplace' ); ?></label></th>
						<td><textarea class="large-text" rows="8" id="lnkup-account-message" name="mail_message" maxlength="4000" required></textarea></td>
					</tr>
				</table>
				<?php submit_button( __( 'Queue email', 'lnkup-marketplace' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Recent emails', 'lnkup-marketplace' ); ?></h2>
			<?php if ( ! $campaigns ) : ?>
				<p><?php esc_html_e( 'No account emails yet.', 'lnkup-marketplace' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Subject', 'lnkup-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Audience', 'lnkup-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Sent', 'lnkup-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Skipped', 'lnkup-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Failed', 'lnkup-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $campaigns as $campaign ) : ?>
							<tr>
								<td><?php echo esc_html( $campaign['name'] ?? '' ); ?></td>
								<td><?php echo esc_html( self::audience_label( $campaign['target_role'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) (int) ( $campaign['sent'] ?? 0 ) ); ?> / <?php echo esc_html( (string) (int) ( $campaign['total'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) (int) ( $campaign['skipped'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) (int) ( $campaign['failed'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $campaign['status'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Queue one message per matching account.
	 */
	public static function handle_queue() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		check_admin_referer( 'lnkup_queue_account_email' );

		$audience = isset( $_POST['audience'] ) ? sanitize_key( wp_unslash( $_POST['audience'] ) ) : '';
		if ( ! in_array( $audience, array( 'lnkup_vendor', 'lnkup_organizer', 'everyone' ), true ) ) {
			self::redirect_notice( 'error', __( 'Choose an audience.', 'lnkup-marketplace' ) );
		}

		$subject = isset( $_POST['mail_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['mail_subject'] ) ) : '';
		$message = isset( $_POST['mail_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mail_message'] ) ) : '';
		$subject = trim( str_replace( array( "\r", "\n" ), '', $subject ) );
		$message = trim( $message );
		if ( strlen( $subject ) > 140 ) {
			$subject = substr( $subject, 0, 140 );
		}
		if ( strlen( $message ) > 4000 ) {
			$message = substr( $message, 0, 4000 );
		}
		if ( '' === $subject || '' === $message ) {
			self::redirect_notice( 'error', __( 'Add a subject and a message.', 'lnkup-marketplace' ) );
		}

		$people = self::audience_people( $audience );
		if ( ! $people ) {
			self::redirect_notice( 'error', __( 'No accounts match that audience.', 'lnkup-marketplace' ) );
		}

		$campaign_id = LNKup_Outreach::create_campaign(
			array(
				'name'        => $subject,
				'target_role' => $audience,
				'message'     => $message,
				'source'      => 'accounts',
				'created_by'  => get_current_user_id(),
			)
		);
		if ( is_wp_error( $campaign_id ) ) {
			self::redirect_notice( 'error', $campaign_id->get_error_message() );
		}

		$added = LNKup_Outreach::add_account_recipients( $campaign_id, $people );
		if ( $added < 1 ) {
			self::redirect_notice( 'error', __( 'No accounts could be queued.', 'lnkup-marketplace' ) );
		}

		$queued = LNKup_Outreach::queue_campaign( $campaign_id );
		if ( is_wp_error( $queued ) ) {
			self::redirect_notice( 'error', $queued->get_error_message() );
		}

		self::redirect_notice(
			'success',
			sprintf(
				/* translators: %d: number of accounts */
				_n( 'Queued email for %d account.', 'Queued email for %d accounts.', $added, 'lnkup-marketplace' ),
				$added
			)
		);
	}

	/**
	 * Accounts in the selected audience.
	 *
	 * @param string $audience lnkup_vendor|lnkup_organizer|everyone.
	 * @return array<int, array{email:string,name:string,user_id:int}>
	 */
	private static function audience_people( $audience ) {
		$roles = array();
		if ( 'lnkup_vendor' === $audience || 'everyone' === $audience ) {
			$roles[] = 'lnkup_vendor';
		}
		if ( 'lnkup_organizer' === $audience || 'everyone' === $audience ) {
			$roles[] = 'lnkup_organizer';
		}
		if ( ! $roles ) {
			return array();
		}

		$users = get_users(
			array(
				'role__in' => $roles,
				'fields'   => array( 'ID', 'user_email', 'display_name' ),
				'number'   => -1,
			)
		);

		$people = array();
		$seen   = array();
		foreach ( $users as $user ) {
			$email = sanitize_email( $user->user_email ?? '' );
			if ( ! $email ) {
				continue;
			}
			$key = strtolower( $email );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$people[]     = array(
				'email'   => $email,
				'name'    => (string) ( $user->display_name ?? '' ),
				'user_id' => (int) $user->ID,
			);
		}
		return $people;
	}

	/**
	 * Audience label for the recent list.
	 *
	 * @param string $role Stored target role.
	 */
	private static function audience_label( $role ) {
		if ( 'lnkup_organizer' === $role ) {
			return __( 'Organizers', 'lnkup-marketplace' );
		}
		if ( 'everyone' === $role ) {
			return __( 'Everyone', 'lnkup-marketplace' );
		}
		return __( 'Vendors', 'lnkup-marketplace' );
	}

	/**
	 * Whether the current user may send platform email.
	 */
	private static function can_manage() {
		return current_user_can( 'lnkup_manage_all' ) || ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() );
	}

	/**
	 * Return to the admin screen with a notice.
	 *
	 * @param string $type    success|error.
	 * @param string $message Notice text.
	 */
	private static function redirect_notice( $type, $message ) {
		$url = add_query_arg(
			array(
				'page'          => 'lnkup-account-email',
				'lnkup_notice'  => 'error' === $type ? 'error' : 'success',
				'lnkup_message' => $message,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
