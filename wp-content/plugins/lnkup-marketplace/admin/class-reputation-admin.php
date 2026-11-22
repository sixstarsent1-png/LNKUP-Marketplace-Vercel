<?php
/**
 * Admin reputation moderation: internal score, reviews, disputes (decisions + appeals), flag confirmation.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Reputation_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 25 );
		add_action( 'admin_post_lnkup_rep_score_override', array( __CLASS__, 'handle_score_override' ) );
		add_action( 'admin_post_lnkup_rep_badge_action', array( __CLASS__, 'handle_badge_action' ) );
		add_action( 'admin_post_lnkup_rep_review_moderate', array( __CLASS__, 'handle_review_moderate' ) );
		add_action( 'admin_post_lnkup_rep_dispute_resolve', array( __CLASS__, 'handle_dispute_resolve' ) );
		add_action( 'admin_post_lnkup_rep_flag_confirm', array( __CLASS__, 'handle_flag_confirm' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Reputation', 'lnkup-marketplace' ),
			__( 'Reputation', 'lnkup-marketplace' ),
			'lnkup_manage_all',
			'lnkup-reputation',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'overview' => __( 'Internal score', 'lnkup-marketplace' ),
			'reviews'  => __( 'Reviews', 'lnkup-marketplace' ),
			'disputes' => __( 'Moderation queue', 'lnkup-marketplace' ),
			'flags'    => __( 'Risk flags', 'lnkup-marketplace' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Vendor Reputation', 'lnkup-marketplace' ); ?></h1>
			<?php self::render_notice(); ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-reputation&tab=' . $key ) ); ?>" class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			switch ( $tab ) {
				case 'reviews':
					self::render_reviews_tab();
					break;
				case 'disputes':
					self::render_disputes_tab();
					break;
				case 'flags':
					self::render_flags_tab();
					break;
				default:
					self::render_overview_tab();
			}
			?>
		</div>
		<?php
	}

	private static function render_notice() {
		$msg = isset( $_GET['lnkup_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['lnkup_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $msg ) {
			return;
		}
		$is_error = 0 === strpos( $msg, 'error:' );
		echo '<div class="notice ' . ( $is_error ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $is_error ? substr( $msg, 6 ) : $msg ) . '</p></div>';
	}

	private static function render_overview_tab() {
		echo '<p class="description">' . esc_html__( 'The 0–100 score is internal only. Vendors and organizers see separate indicators (verification, experience, organizer feedback, document readiness).', 'lnkup-marketplace' ) . '</p>';
		$profiles = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_profile',
				'posts_per_page' => 50,
				'post_status'    => 'publish',
				'meta_key'       => '_lnkup_reputation_score', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
			)
		);
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Vendor', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Score', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Label', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Rating', 'lnkup-marketplace' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $profiles as $p ) {
			$score = get_post_meta( $p->ID, '_lnkup_reputation_score', true );
			$label = get_post_meta( $p->ID, '_lnkup_reputation_label', true );
			$avg   = get_post_meta( $p->ID, '_lnkup_avg_rating', true );
			echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=lnkup-passport&tab=vendor&profile_id=' . $p->ID ) ) . '">' . esc_html( $p->post_title ) . '</a></td><td>' . esc_html( (string) $score ) . '</td><td>' . esc_html( $label ) . '</td><td>' . esc_html( (string) $avg ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
			wp_nonce_field( 'lnkup_rep_score', 'lnkup_nonce' );
			echo '<input type="hidden" name="action" value="lnkup_rep_score_override" /><input type="hidden" name="profile_id" value="' . esc_attr( (string) $p->ID ) . '" />';
			echo '<input type="number" name="score" min="0" max="100" value="' . esc_attr( (string) $score ) . '" style="width:60px;" />';
			echo '<button type="submit" class="button button-small">' . esc_html__( 'Override', 'lnkup-marketplace' ) . '</button></form>';
			echo ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
			wp_nonce_field( 'lnkup_rep_badge', 'lnkup_nonce' );
			echo '<input type="hidden" name="action" value="lnkup_rep_badge_action" /><input type="hidden" name="profile_id" value="' . esc_attr( (string) $p->ID ) . '" /><input type="hidden" name="badge" value="lnkup_approved" /><input type="hidden" name="badge_action" value="award" />';
			echo '<button type="submit" class="button button-small">' . esc_html__( 'LNKup Approved', 'lnkup-marketplace' ) . '</button></form>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_reviews_tab() {
		$reviews = get_posts( array( 'post_type' => 'lnkup_review', 'posts_per_page' => 50, 'orderby' => 'date', 'order' => 'DESC' ) );
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Rating', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Status', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Participation', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Text', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Vendor response', 'lnkup-marketplace' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $reviews as $r ) {
			$status = LNKup_Vendor_Reviews::status_of( $r->ID );
			if ( get_post_meta( $r->ID, '_lnkup_under_dispute', true ) ) {
				$status .= ' · ' . __( 'under review', 'lnkup-marketplace' );
			}
			echo '<tr><td>' . esc_html( (string) $r->ID ) . '</td><td>' . esc_html( (string) get_post_meta( $r->ID, '_lnkup_overall_rating', true ) ) . '</td><td>' . esc_html( $status ) . '</td><td>' . esc_html( get_post_meta( $r->ID, '_lnkup_verified_participation', true ) ? __( 'Check-in verified', 'lnkup-marketplace' ) : __( 'Paid booking', 'lnkup-marketplace' ) ) . '</td><td>' . esc_html( wp_trim_words( $r->post_content, 12 ) ) . '</td><td>' . esc_html( wp_trim_words( (string) get_post_meta( $r->ID, '_lnkup_vendor_response', true ), 10 ) ) . '</td><td>';
			foreach ( LNKup_Vendor_Reviews::STATUSES as $st ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;"><input type="hidden" name="action" value="lnkup_rep_review_moderate" />';
				wp_nonce_field( 'lnkup_rep_review', 'lnkup_nonce' );
				echo '<input type="hidden" name="review_id" value="' . esc_attr( (string) $r->ID ) . '" /><input type="hidden" name="status" value="' . esc_attr( $st ) . '" /><button class="button button-small">' . esc_html( $st ) . '</button></form> ';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_disputes_tab() {
		echo '<p class="description">' . esc_html__( 'Reported reviews stay visible while under review. Hide only when a documented standard is met; a negative review alone is not a reason to remove it. Each party may appeal once.', 'lnkup-marketplace' ) . '</p>';
		$disputes = get_posts( array( 'post_type' => 'lnkup_dispute', 'posts_per_page' => 50, 'orderby' => 'date', 'order' => 'DESC' ) );
		$reasons  = LNKup_Vendor_Reviews::report_reasons();
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Review', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Status', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Reported as', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Reason / appeal', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Decision', 'lnkup-marketplace' ) . '</th></tr></thead><tbody>';
		foreach ( $disputes as $d ) {
			$status    = (string) get_post_meta( $d->ID, '_lnkup_status', true );
			$review_id = (int) get_post_meta( $d->ID, '_lnkup_review_id', true );
			$code      = (string) get_post_meta( $d->ID, '_lnkup_reason_code', true );
			$appeal    = (string) get_post_meta( $d->ID, '_lnkup_appeal_reason', true );
			$review    = get_post( $review_id );
			echo '<tr><td>' . esc_html( (string) $d->ID ) . '</td><td>#' . esc_html( (string) $review_id ) . '<br /><em>' . esc_html( $review ? wp_trim_words( $review->post_content, 14 ) : '' ) . '</em></td><td>' . esc_html( $status ) . '</td><td>' . esc_html( $reasons[ $code ] ?? $code ) . '</td><td>' . esc_html( wp_trim_words( $d->post_content, 20 ) );
			if ( '' !== $appeal ) {
				echo '<br /><strong>' . esc_html__( 'Appeal:', 'lnkup-marketplace' ) . '</strong> ' . esc_html( wp_trim_words( $appeal, 20 ) );
			}
			echo '</td><td>';
			if ( in_array( $status, array( LNKup_Vendor_Reviews::DISPUTE_OPEN, LNKup_Vendor_Reviews::DISPUTE_APPEALED, 'open' ), true ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lnkup_rep_dispute_resolve" />';
				wp_nonce_field( 'lnkup_rep_dispute', 'lnkup_nonce' );
				echo '<input type="hidden" name="dispute_id" value="' . esc_attr( (string) $d->ID ) . '" />';
				echo '<select name="decision"><option value="uphold">' . esc_html__( 'Uphold (keep review)', 'lnkup-marketplace' ) . '</option><option value="request_edit">' . esc_html__( 'Request organizer edit', 'lnkup-marketplace' ) . '</option><option value="hide">' . esc_html__( 'Hide review', 'lnkup-marketplace' ) . '</option></select> ';
				echo '<select name="reason_code"><option value="">' . esc_html__( 'Reason (required to hide)', 'lnkup-marketplace' ) . '</option>';
				foreach ( LNKup_Vendor_Reviews::moderation_reasons() as $key => $label ) {
					echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
				}
				echo '</select> <input type="text" name="note" placeholder="' . esc_attr__( 'Internal note', 'lnkup-marketplace' ) . '" /> <button class="button button-small button-primary">' . esc_html__( 'Decide', 'lnkup-marketplace' ) . '</button></form>';
			} else {
				echo esc_html( (string) get_post_meta( $d->ID, '_lnkup_decision', true ) ) . ' · ' . esc_html( (string) get_post_meta( $d->ID, '_lnkup_decided_at', true ) );
				if ( get_post_meta( $d->ID, '_lnkup_final', true ) ) {
					echo ' · ' . esc_html__( 'final', 'lnkup-marketplace' );
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_flags_tab() {
		echo '<p class="description">' . esc_html__( 'Organizer flags are private notes. They affect the internal score and LNKup Verified level only after staff confirm them.', 'lnkup-marketplace' ) . '</p>';
		$flags = get_posts( array( 'post_type' => 'lnkup_vendor_flag', 'posts_per_page' => 50, 'orderby' => 'date', 'order' => 'DESC' ) );
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Type', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Vendor', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Note', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Confirmed', 'lnkup-marketplace' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $flags as $f ) {
			$vendor    = get_userdata( (int) get_post_meta( $f->ID, '_lnkup_vendor_user_id', true ) );
			$confirmed = (bool) get_post_meta( $f->ID, '_lnkup_admin_confirmed', true );
			echo '<tr><td>' . esc_html( (string) $f->ID ) . '</td><td>' . esc_html( (string) get_post_meta( $f->ID, '_lnkup_flag_type', true ) ) . '</td><td>' . esc_html( $vendor ? $vendor->display_name : '' ) . '</td><td>' . esc_html( (string) get_post_meta( $f->ID, '_lnkup_flag_note', true ) ) . '</td><td>' . esc_html( $confirmed ? __( 'Yes', 'lnkup-marketplace' ) : __( 'No', 'lnkup-marketplace' ) ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;"><input type="hidden" name="action" value="lnkup_rep_flag_confirm" />';
			wp_nonce_field( 'lnkup_rep_flag', 'lnkup_nonce' );
			echo '<input type="hidden" name="flag_id" value="' . esc_attr( (string) $f->ID ) . '" /><input type="hidden" name="confirm" value="' . ( $confirmed ? '0' : '1' ) . '" /><button class="button button-small">' . esc_html( $confirmed ? __( 'Unconfirm', 'lnkup-marketplace' ) : __( 'Confirm', 'lnkup-marketplace' ) ) . '</button></form>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function handle_score_override() {
		check_admin_referer( 'lnkup_rep_score', 'lnkup_nonce' );
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$profile_id = absint( $_POST['profile_id'] ?? 0 );
		$score      = min( 100, absint( $_POST['score'] ?? 0 ) );
		update_post_meta( $profile_id, '_lnkup_reputation_score_override', $score );
		LNKup_Vendor_Reputation::log_activity( 'admin_adjustment', $profile_id, array( 'payload' => array( 'score' => $score ) ) );
		LNKup_Vendor_Reputation::refresh( $profile_id );
		wp_safe_redirect( admin_url( 'admin.php?page=lnkup-reputation' ) );
		exit;
	}

	public static function handle_badge_action() {
		check_admin_referer( 'lnkup_rep_badge', 'lnkup_nonce' );
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$profile_id = absint( $_POST['profile_id'] ?? 0 );
		$badge      = sanitize_key( $_POST['badge'] ?? '' );
		$action     = sanitize_key( $_POST['badge_action'] ?? '' );
		if ( 'award' === $action ) {
			LNKup_Vendor_Badges::award( $profile_id, $badge, 'manual' );
		} else {
			LNKup_Vendor_Badges::revoke( $profile_id, $badge );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=lnkup-reputation' ) );
		exit;
	}

	public static function handle_review_moderate() {
		check_admin_referer( 'lnkup_rep_review', 'lnkup_nonce' );
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$ok = LNKup_Vendor_Reviews::moderate( absint( $_POST['review_id'] ?? 0 ), sanitize_key( $_POST['status'] ?? '' ) );
		wp_safe_redirect( add_query_arg( 'lnkup_msg', rawurlencode( $ok ? __( 'Review status updated.', 'lnkup-marketplace' ) : 'error:' . __( 'Invalid review or status.', 'lnkup-marketplace' ) ), admin_url( 'admin.php?page=lnkup-reputation&tab=reviews' ) ) );
		exit;
	}

	public static function handle_dispute_resolve() {
		check_admin_referer( 'lnkup_rep_dispute', 'lnkup_nonce' );
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$result = LNKup_Vendor_Reviews::decide(
			absint( $_POST['dispute_id'] ?? 0 ),
			sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) ),
			sanitize_key( wp_unslash( $_POST['reason_code'] ?? '' ) ),
			sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) )
		);
		$msg = is_wp_error( $result ) ? 'error:' . $result->get_error_message() : __( 'Decision recorded.', 'lnkup-marketplace' );
		wp_safe_redirect( add_query_arg( 'lnkup_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=lnkup-reputation&tab=disputes' ) ) );
		exit;
	}

	public static function handle_flag_confirm() {
		check_admin_referer( 'lnkup_rep_flag', 'lnkup_nonce' );
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$flag_id = absint( $_POST['flag_id'] ?? 0 );
		if ( 'lnkup_vendor_flag' === get_post_type( $flag_id ) ) {
			$confirm = ! empty( $_POST['confirm'] );
			if ( $confirm ) {
				update_post_meta( $flag_id, '_lnkup_admin_confirmed', '1' );
				update_post_meta( $flag_id, '_lnkup_admin_confirmed_by', get_current_user_id() );
			} else {
				delete_post_meta( $flag_id, '_lnkup_admin_confirmed' );
			}
			$vendor_id  = (int) get_post_meta( $flag_id, '_lnkup_vendor_user_id', true );
			$profile_id = (int) lnkup_get_vendor_profile_id( $vendor_id );
			if ( $profile_id ) {
				LNKup_Vendor_Reputation::log_activity( $confirm ? 'flag_confirmed' : 'flag_unconfirmed', $profile_id, array( 'payload' => array( 'flag_id' => $flag_id ) ) );
				LNKup_Vendor_Reputation::refresh( $profile_id );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=lnkup-reputation&tab=flags' ) );
		exit;
	}
}
