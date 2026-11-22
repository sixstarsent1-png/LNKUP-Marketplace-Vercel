<?php
/**
 * Admin verification queue and manual trust controls.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Verification_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 24 );
		add_action( 'admin_post_lnkup_verify_document', array( __CLASS__, 'handle_document_action' ) );
		add_action( 'admin_post_lnkup_verify_vendor_profile', array( __CLASS__, 'handle_vendor_action' ) );
		add_action( 'admin_post_lnkup_verify_organizer', array( __CLASS__, 'handle_organizer_action' ) );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Verification', 'lnkup-marketplace' ),
			__( 'Verification', 'lnkup-marketplace' ),
			'lnkup_manage_all',
			'lnkup-verification',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'documents'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap" data-testid="lnkup-admin-verification">
			<h1><?php esc_html_e( 'Trust & Verification', 'lnkup-marketplace' ); ?></h1>
			<?php
			$lnkup_error = isset( $_GET['lnkup_error'] ) ? sanitize_text_field( wp_unslash( $_GET['lnkup_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $lnkup_error ) :
				?>
				<div class="notice notice-error"><p><?php echo esc_html( $lnkup_error ); ?></p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-verification&tab=documents' ) ); ?>" class="nav-tab <?php echo 'documents' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Document Queue', 'lnkup-marketplace' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-verification&tab=vendors' ) ); ?>" class="nav-tab <?php echo 'vendors' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Vendor Profiles', 'lnkup-marketplace' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-verification&tab=organizers' ) ); ?>" class="nav-tab <?php echo 'organizers' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Organizers', 'lnkup-marketplace' ); ?></a>
			</nav>
			<?php
			switch ( $tab ) {
				case 'vendors':
					self::render_vendors_tab();
					break;
				case 'organizers':
					self::render_organizers_tab();
					break;
				default:
					self::render_documents_tab();
			}
			?>
		</div>
		<?php
	}

	private static function render_documents_tab(): void {
		$docs = get_posts(
			array(
				'post_type'      => 'lnkup_document',
				'posts_per_page' => 50,
				'meta_key'       => '_lnkup_verification_status',
				'meta_value'     => 'pending',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$types = class_exists( 'LNKup_Documents' ) ? LNKup_Documents::document_types() : array();
		?>
		<p><?php esc_html_e( 'Review uploaded vendor documents. Approvals refresh reputation and badges.', 'lnkup-marketplace' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Vendor', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Type', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Uploaded', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'lnkup-marketplace' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $docs ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No pending documents.', 'lnkup-marketplace' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $docs as $doc ) : ?>
					<?php
					$vendor_id  = (int) get_post_meta( $doc->ID, '_lnkup_vendor_user_id', true );
					$vendor     = get_userdata( $vendor_id );
					$type       = (string) get_post_meta( $doc->ID, '_lnkup_document_type', true );
					$status     = (string) get_post_meta( $doc->ID, '_lnkup_verification_status', true );
					?>
					<tr>
						<td><?php echo esc_html( $vendor ? $vendor->display_name : '#' . $vendor_id ); ?></td>
						<td><?php echo esc_html( $types[ $type ] ?? $type ); ?></td>
						<td><?php echo esc_html( $status ); ?></td>
						<td><?php echo esc_html( get_the_date( '', $doc ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<?php wp_nonce_field( 'lnkup_verify_document' ); ?>
								<input type="hidden" name="action" value="lnkup_verify_document" />
								<input type="hidden" name="document_id" value="<?php echo esc_attr( (string) $doc->ID ); ?>" />
								<input type="hidden" name="status" value="approved" />
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Approve', 'lnkup-marketplace' ); ?></button>
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<?php wp_nonce_field( 'lnkup_verify_document' ); ?>
								<input type="hidden" name="action" value="lnkup_verify_document" />
								<input type="hidden" name="document_id" value="<?php echo esc_attr( (string) $doc->ID ); ?>" />
								<input type="hidden" name="status" value="rejected" />
								<input type="text" name="reason" required placeholder="<?php esc_attr_e( 'Reason shown to vendor', 'lnkup-marketplace' ); ?>" />
								<button type="submit" class="button"><?php esc_html_e( 'Reject', 'lnkup-marketplace' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_vendors_tab(): void {
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$args   = array(
			'post_type'      => 'lnkup_vendor_profile',
			'posts_per_page' => 30,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( $search ) {
			$args['s'] = $search;
		}
		$profiles = get_posts( $args );
		$queue    = class_exists( 'LNKup_Verification_Records' ) ? LNKup_Verification_Records::queue( 25 ) : array();
		$types    = class_exists( 'LNKup_Verification_Records' ) ? LNKup_Verification_Records::type_labels() : array();
		?>
		<?php if ( $queue ) : ?>
			<h2><?php esc_html_e( 'Awaiting review', 'lnkup-marketplace' ); ?></h2>
			<table class="widefat striped" data-testid="lnkup-verification-records-queue">
				<thead><tr><th><?php esc_html_e( 'Vendor', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Type', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Submitted', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Note', 'lnkup-marketplace' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $queue as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-passport&tab=vendor&profile_id=' . (int) $row['vendor_profile_id'] ) ); ?>"><?php echo esc_html( get_the_title( (int) $row['vendor_profile_id'] ) ); ?></a></td>
						<td><?php echo esc_html( $types[ $row['type'] ] ?? $row['type'] ); ?></td>
						<td><?php echo esc_html( $row['status'] ); ?></td>
						<td><?php echo esc_html( $row['created_at'] ); ?></td>
						<td><?php echo esc_html( wp_trim_words( (string) $row['reason'], 16 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<form method="get">
			<input type="hidden" name="page" value="lnkup-verification" />
			<input type="hidden" name="tab" value="vendors" />
			<p class="search-box">
				<label class="screen-reader-text" for="vendor-search"><?php esc_html_e( 'Search vendors', 'lnkup-marketplace' ); ?></label>
				<input type="search" id="vendor-search" name="s" value="<?php echo esc_attr( $search ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'lnkup-marketplace' ); ?></button>
			</p>
		</form>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Vendor', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Level', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Manual status', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Missing', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'lnkup-marketplace' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $profiles as $profile ) : ?>
				<?php
				$status = class_exists( 'LNKup_Verification' )
					? LNKup_Verification::get_vendor_verification_status( $profile->ID )
					: array();
				$manual = (string) get_post_meta( $profile->ID, '_lnkup_verification_status', true );
				?>
				<tr>
					<td><strong><?php echo esc_html( $profile->post_title ); ?></strong></td>
					<td><?php echo esc_html( (string) ( $status['label'] ?? '' ) ); ?> · <?php echo esc_html( (string) ( $status['percent_complete'] ?? 0 ) ); ?>%</td>
					<td><?php echo esc_html( $manual ?: 'pending' ); ?></td>
					<td><?php echo esc_html( implode( '; ', array_slice( (array) ( $status['missing_items'] ?? array() ), 0, 2 ) ) ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'lnkup_verify_vendor_profile' ); ?>
							<input type="hidden" name="action" value="lnkup_verify_vendor_profile" />
							<input type="hidden" name="profile_id" value="<?php echo esc_attr( (string) $profile->ID ); ?>" />
							<select name="status">
								<option value="approved"><?php esc_html_e( 'Verify business identity', 'lnkup-marketplace' ); ?></option>
								<option value="info_required"><?php esc_html_e( 'Request more information', 'lnkup-marketplace' ); ?></option>
								<option value="pending"><?php esc_html_e( 'Pending', 'lnkup-marketplace' ); ?></option>
								<option value="rejected"><?php esc_html_e( 'Reject', 'lnkup-marketplace' ); ?></option>
								<option value="revoked"><?php esc_html_e( 'Revoke', 'lnkup-marketplace' ); ?></option>
							</select>
							<label><?php esc_html_e( 'Recheck by', 'lnkup-marketplace' ); ?> <input type="date" name="expires_at" /></label>
							<input type="text" name="notes" placeholder="<?php esc_attr_e( 'Reason (required to reject, request info, or revoke)', 'lnkup-marketplace' ); ?>" class="regular-text" />
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-passport&tab=vendor&profile_id=' . $profile->ID ) ); ?>"><?php esc_html_e( 'History', 'lnkup-marketplace' ); ?></a>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Update', 'lnkup-marketplace' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_organizers_tab(): void {
		$users = get_users(
			array(
				'role__in' => array( 'lnkup_organizer', 'administrator' ),
				'number'   => 30,
				'orderby'  => 'registered',
				'order'    => 'DESC',
			)
		);
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Organizer', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Level', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Verified', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'lnkup-marketplace' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $users as $user ) : ?>
				<?php
				$status = class_exists( 'LNKup_Verification' )
					? LNKup_Verification::get_organizer_verification_status( (int) $user->ID )
					: array();
				$verified = (bool) get_user_meta( $user->ID, 'lnkup_organizer_verified', true );
				?>
				<tr>
					<td><?php echo esc_html( $user->display_name ); ?></td>
					<td><?php echo esc_html( (string) ( $status['label'] ?? '' ) ); ?></td>
					<td><?php echo $verified ? esc_html__( 'Yes', 'lnkup-marketplace' ) : esc_html__( 'No', 'lnkup-marketplace' ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'lnkup_verify_organizer' ); ?>
							<input type="hidden" name="action" value="lnkup_verify_organizer" />
							<input type="hidden" name="organizer_id" value="<?php echo esc_attr( (string) $user->ID ); ?>" />
							<label><input type="checkbox" name="verified" value="1" <?php checked( $verified ); ?> /> <?php esc_html_e( 'Verified Organizer', 'lnkup-marketplace' ); ?></label>
							<input type="text" name="notes" placeholder="<?php esc_attr_e( 'Internal notes', 'lnkup-marketplace' ); ?>" class="regular-text" />
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'lnkup-marketplace' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function handle_document_action(): void {
		if ( ! current_user_can( 'lnkup_manage_all' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		check_admin_referer( 'lnkup_verify_document' );
		$doc_id = absint( $_POST['document_id'] ?? 0 );
		$status = sanitize_key( (string) ( $_POST['status'] ?? '' ) );
		$reason = sanitize_textarea_field( wp_unslash( (string) ( $_POST['reason'] ?? '' ) ) );
		if ( $doc_id && in_array( $status, array( 'approved', 'rejected', 'pending' ), true ) ) {
			LNKup_Documents::set_review_status( $doc_id, $status, $reason );
			if ( class_exists( 'LNKup_Vendor_Reputation' ) ) {
				LNKup_Vendor_Reputation::log_activity( 'document_reviewed', (int) get_post_meta( $doc_id, '_lnkup_vendor_profile_id', true ), array( 'payload' => array( 'document_id' => $doc_id, 'status' => $status ) ) );
			}
			$profile_id = (int) get_post_meta( $doc_id, '_lnkup_vendor_profile_id', true );
			if ( $profile_id && class_exists( 'LNKup_Verification' ) ) {
				LNKup_Verification::refresh_vendor( $profile_id );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=lnkup-verification&tab=documents&updated=1' ) );
		exit;
	}

	public static function handle_vendor_action(): void {
		if ( ! current_user_can( 'lnkup_manage_all' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		check_admin_referer( 'lnkup_verify_vendor_profile' );
		$profile_id = absint( $_POST['profile_id'] ?? 0 );
		$status     = sanitize_key( (string) ( $_POST['status'] ?? '' ) );
		$notes      = sanitize_textarea_field( wp_unslash( (string) ( $_POST['notes'] ?? '' ) ) );
		$expires    = sanitize_text_field( wp_unslash( (string) ( $_POST['expires_at'] ?? '' ) ) );
		$decisions  = array( 'approved' => 'verified', 'rejected' => 'rejected', 'pending' => 'pending', 'info_required' => 'info_required', 'revoked' => 'revoked' );
		$error      = '';
		if ( $profile_id && isset( $decisions[ $status ] ) ) {
			$recorded = LNKup_Verification_Records::decide( $profile_id, 'business_identity', $decisions[ $status ], array( 'reason' => $notes, 'expires_at' => $expires ) );
			if ( is_wp_error( $recorded ) ) {
				$error = $recorded->get_error_message();
			} elseif ( $notes ) {
				update_post_meta( $profile_id, '_lnkup_verification_admin_notes', $notes );
			}
			if ( class_exists( 'LNKup_Verification' ) ) {
				LNKup_Verification::refresh_vendor( $profile_id );
			}
		}
		$url = admin_url( 'admin.php?page=lnkup-verification&tab=vendors&updated=1' );
		if ( $error ) {
			$url = add_query_arg( 'lnkup_error', rawurlencode( $error ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	public static function handle_organizer_action(): void {
		if ( ! current_user_can( 'lnkup_manage_all' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		check_admin_referer( 'lnkup_verify_organizer' );
		$organizer_id = absint( $_POST['organizer_id'] ?? 0 );
		$verified     = ! empty( $_POST['verified'] );
		$notes        = sanitize_textarea_field( (string) ( $_POST['notes'] ?? '' ) );
		if ( $organizer_id ) {
			update_user_meta( $organizer_id, 'lnkup_organizer_verified', $verified ? '1' : '' );
			if ( $notes ) {
				update_user_meta( $organizer_id, 'lnkup_organizer_verification_notes', $notes );
			}
			if ( class_exists( 'LNKup_Marketplace_Index' ) ) {
				$opps = get_posts(
					array(
						'post_type'      => 'lnkup_opportunity',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'meta_key'       => '_lnkup_organizer_user_id',
						'meta_value'     => $organizer_id,
					)
				);
				foreach ( $opps as $opp_id ) {
					LNKup_Marketplace_Index::sync_opportunity( (int) $opp_id );
				}
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=lnkup-verification&tab=organizers&updated=1' ) );
		exit;
	}
}
