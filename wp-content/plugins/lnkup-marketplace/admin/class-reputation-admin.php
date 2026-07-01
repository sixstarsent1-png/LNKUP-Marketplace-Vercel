<?php
/**
 * Admin reputation moderation.
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

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><? esc_html_e( 'Vendor Reputation', 'lnkup-marketplace' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-reputation&tab=overview' ) ); ?>" class="nav-tab <?php echo 'overview' === $tab ? 'nav-tab-active' : ''; ?>"><? esc_html_e( 'Overview', 'lnkup-marketplace' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-reputation&tab=reviews' ) ); ?>" class="nav-tab <?php echo 'reviews' === $tab ? 'nav-tab-active' : ''; ?>"><? esc_html_e( 'Reviews', 'lnkup-marketplace' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-reputation&tab=disputes' ) ); ?>" class="nav-tab <?php echo 'disputes' === $tab ? 'nav-tab-active' : ''; ?>"><? esc_html_e( 'Disputes', 'lnkup-marketplace' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-reputation&tab=flags' ) ); ?>" class="nav-tab <?php echo 'flags' === $tab ? 'nav-tab-active' : ''; ?>"><? esc_html_e( 'Risk Flags', 'lnkup-marketplace' ); ?></a>
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

	private static function render_overview_tab() {
		$profiles = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_profile',
				'posts_per_page' => 50,
				'post_status'    => 'publish',
				'meta_key'       => '_lnkup_reputation_score',
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
			)
		);
		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Vendor', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Score', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Label', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Rating', 'lnkup-marketplace' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $profiles as $p ) {
			$score = get_post_meta( $p->ID, '_lnkup_reputation_score', true );
			$label = get_post_meta( $p->ID, '_lnkup_reputation_label', true );
			$avg   = get_post_meta( $p->ID, '_lnkup_avg_rating', true );
			echo '<tr><td>' . esc_html( $p->post_title ) . '</td><td>' . esc_html( (string) $score ) . '</td><td>' . esc_html( $label ) . '</td><td>' . esc_html( (string) $avg ) . '</td><td>';
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
		echo '<table class="widefat"><thead><tr><th>ID</th><th>' . esc_html__( 'Rating', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Status', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Text', 'lnkup-marketplace' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $reviews as $r ) {
			echo '<tr><td>' . esc_html( (string) $r->ID ) . '</td><td>' . esc_html( (string) get_post_meta( $r->ID, '_lnkup_overall_rating', true ) ) . '</td><td>' . esc_html( get_post_meta( $r->ID, '_lnkup_status', true ) ) . '</td><td>' . esc_html( wp_trim_words( $r->post_content, 12 ) ) . '</td><td>';
			foreach ( array( 'hidden', 'removed', 'published' ) as $st ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;"><input type="hidden" name="action" value="lnkup_rep_review_moderate" />';
				wp_nonce_field( 'lnkup_rep_review', 'lnkup_nonce' );
				echo '<input type="hidden" name="review_id" value="' . esc_attr( (string) $r->ID ) . '" /><input type="hidden" name="status" value="' . esc_attr( $st ) . '" /><button class="button button-small">' . esc_html( $st ) . '</button></form> ';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_disputes_tab() {
		$disputes = get_posts( array( 'post_type' => 'lnkup_dispute', 'posts_per_page' => 50 ) );
		echo '<table class="widefat"><thead><tr><th>ID</th><th>' . esc_html__( 'Review', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Status', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Reason', 'lnkup-marketplace' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $disputes as $d ) {
			echo '<tr><td>' . esc_html( (string) $d->ID ) . '</td><td>' . esc_html( (string) get_post_meta( $d->ID, '_lnkup_review_id', true ) ) . '</td><td>' . esc_html( get_post_meta( $d->ID, '_lnkup_status', true ) ) . '</td><td>' . esc_html( wp_trim_words( $d->post_content, 10 ) ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lnkup_rep_dispute_resolve" />';
			wp_nonce_field( 'lnkup_rep_dispute', 'lnkup_nonce' );
			echo '<input type="hidden" name="dispute_id" value="' . esc_attr( (string) $d->ID ) . '" /><select name="resolution"><option value="resolved">resolved</option><option value="dismissed">dismissed</option></select>';
			echo '<input type="text" name="note" placeholder="Resolution note" /><button class="button button-small">' . esc_html__( 'Resolve', 'lnkup-marketplace' ) . '</button></form>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_flags_tab() {
		$flags = LNKup_Vendor_Flags::get_admin_flags( 50 );
		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Type', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Note', 'lnkup-marketplace' ) . '</th></tr></thead><tbody>';
		foreach ( $flags as $f ) {
			echo '<tr><td>' . esc_html( $f['label'] ) . '</td><td>' . esc_html( $f['note'] ?? '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function handle_score_override() {
		check_admin_referer( 'lnkup_rep_score', 'lnkup_nonce' );
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$profile_id = absint( $_POST['profile_id'] ?? 0 );
		$score      = absint( $_POST['score'] ?? 0 );
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
		LNKup_Vendor_Reviews::moderate( absint( $_POST['review_id'] ?? 0 ), sanitize_key( $_POST['status'] ?? 'hidden' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=lnkup-reputation&tab=reviews' ) );
		exit;
	}

	public static function handle_dispute_resolve() {
		check_admin_referer( 'lnkup_rep_dispute', 'lnkup_nonce' );
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$dispute_id = absint( $_POST['dispute_id'] ?? 0 );
		$resolution = sanitize_key( $_POST['resolution'] ?? 'resolved' );
		update_post_meta( $dispute_id, '_lnkup_status', $resolution );
		update_post_meta( $dispute_id, '_lnkup_admin_resolution', sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ) );
		$review_id = (int) get_post_meta( $dispute_id, '_lnkup_review_id', true );
		if ( 'resolved' === $resolution ) {
			LNKup_Vendor_Reviews::moderate( $review_id, 'removed' );
		} else {
			update_post_meta( $review_id, '_lnkup_status', 'published' );
		}
		$profile_id = (int) get_post_meta( $review_id, '_lnkup_vendor_profile_id', true );
		LNKup_Vendor_Reputation::log_activity( 'dispute_resolved', $profile_id, array( 'payload' => array( 'dispute_id' => $dispute_id ) ) );
		LNKup_Vendor_Reputation::refresh( $profile_id );
		wp_safe_redirect( admin_url( 'admin.php?page=lnkup-reputation&tab=disputes' ) );
		exit;
	}
}
