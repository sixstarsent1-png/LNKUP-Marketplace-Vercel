<?php
/**
 * wp-admin: LNKup → Vendor Passport & Trust (health, vendor lookup + timeline, migration, settings).
 *
 * Verification and review moderation stay on their existing pages; this hub links to them.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Passport_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 26 );
		add_action( 'admin_post_lnkup_passport_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_lnkup_passport_migration', array( __CLASS__, 'handle_migration' ) );
		add_action( 'admin_post_lnkup_passport_refresh', array( __CLASS__, 'handle_refresh' ) );
		add_action( 'admin_post_lnkup_passport_probe', array( __CLASS__, 'handle_probe' ) );
	}

	/**
	 * Human label for the private uploads probe result.
	 */
	private static function probe_label(): string {
		$last = LNKup_Private_Uploads_Probe::last();
		$when = $last['checked_at'] ? wp_date( 'M j, H:i', $last['checked_at'] ) : '';
		switch ( $last['status'] ) {
			case LNKup_Private_Uploads_Probe::STATUS_OK:
				/* translators: %s: time of the check */
				return sprintf( __( 'Protected (checked %s)', 'lnkup-marketplace' ), $when );
			case LNKup_Private_Uploads_Probe::STATUS_EXPOSED:
				/* translators: %s: time of the check */
				return sprintf( __( 'EXPOSED: files are served by URL (checked %s). Sharing and migration stay off until hosting blocks the folder.', 'lnkup-marketplace' ), $when );
			default:
				return $when
					/* translators: 1: time of the check, 2: error detail */
					? sprintf( __( 'Not verified (checked %1$s: %2$s)', 'lnkup-marketplace' ), $when, $last['detail'] )
					: __( 'Not verified yet', 'lnkup-marketplace' );
		}
	}

	public static function add_menu(): void {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Vendor Passport & Trust', 'lnkup-marketplace' ),
			__( 'Vendor Passport & Trust', 'lnkup-marketplace' ),
			'lnkup_manage_all',
			'lnkup-passport',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'health'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'health'   => __( 'System health', 'lnkup-marketplace' ),
			'vendor'   => __( 'Vendor lookup', 'lnkup-marketplace' ),
			'settings' => __( 'Settings', 'lnkup-marketplace' ),
		);
		?>
		<div class="wrap" data-testid="lnkup-admin-passport">
			<h1><?php esc_html_e( 'Vendor Passport & Trust', 'lnkup-marketplace' ); ?></h1>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-verification' ) ); ?>"><?php esc_html_e( 'Verification queue', 'lnkup-marketplace' ); ?></a> ·
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-reputation&tab=disputes' ) ); ?>"><?php esc_html_e( 'Review moderation', 'lnkup-marketplace' ); ?></a> ·
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-reputation&tab=flags' ) ); ?>"><?php esc_html_e( 'Risk flags', 'lnkup-marketplace' ); ?></a>
			</p>
			<?php
			$msg = isset( $_GET['lnkup_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['lnkup_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $msg ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			}
			?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-passport&tab=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			switch ( $tab ) {
				case 'vendor':
					self::render_vendor_tab();
					break;
				case 'settings':
					self::render_settings_tab();
					break;
				default:
					self::render_health_tab();
			}
			?>
		</div>
		<?php
	}

	private static function render_health_tab(): void {
		global $wpdb;
		$open_disputes = count(
			get_posts(
				array(
					'post_type'      => 'lnkup_dispute',
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( array( 'key' => '_lnkup_status', 'value' => array( 'open', 'appealed' ), 'compare' => 'IN' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			)
		);
		$pending_docs = count(
			get_posts(
				array(
					'post_type'      => 'lnkup_document',
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_lnkup_verification_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => 'pending', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
		$expiring_docs = count(
			get_posts(
				array(
					'post_type'      => 'lnkup_document',
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array( 'key' => '_lnkup_verification_status', 'value' => 'approved' ),
						array( 'key' => '_lnkup_expiration_date', 'value' => array( wp_date( 'Y-m-d' ), wp_date( 'Y-m-d', strtotime( '+30 days' ) ) ), 'compare' => 'BETWEEN', 'type' => 'DATE' ),
						array( 'key' => '_lnkup_superseded_by', 'compare' => 'NOT EXISTS' ),
					),
				)
			)
		);
		$stale = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_lnkup_reputation_updated_at'
				WHERE p.post_type = 'lnkup_vendor_profile' AND p.post_status = 'publish' AND ( pm.meta_value IS NULL OR pm.meta_value < %s )",
				gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
			)
		);
		$rows = array(
			__( 'Passport enabled', 'lnkup-marketplace' )                => LNKup_Passport_Settings::enabled() ? __( 'Yes', 'lnkup-marketplace' ) : __( 'No (flag off)', 'lnkup-marketplace' ),
			__( 'Schema version', 'lnkup-marketplace' )                  => (string) get_option( LNKup_Passport_Tables::SCHEMA_OPTION, '—' ),
			__( 'Data migration', 'lnkup-marketplace' )                  => get_option( LNKup_Passport::MIGRATION_OPTION ) ? __( 'Complete', 'lnkup-marketplace' ) : __( 'In progress (runs on admin load and daily)', 'lnkup-marketplace' ),
			__( 'Verification requests awaiting review', 'lnkup-marketplace' ) => (string) count( LNKup_Verification_Records::queue( 200 ) ),
			__( 'Verifications expiring in 30 days', 'lnkup-marketplace' ) => (string) count( LNKup_Verification_Records::expiring_within( 30 ) ),
			__( 'Documents pending review', 'lnkup-marketplace' )        => (string) $pending_docs,
			__( 'Approved documents expiring in 30 days', 'lnkup-marketplace' ) => (string) $expiring_docs,
			__( 'Open / appealed review disputes', 'lnkup-marketplace' ) => (string) $open_disputes,
			__( 'Lapsed document shares', 'lnkup-marketplace' )          => (string) (int) get_option( 'lnkup_passport_lapsed_shares', 0 ),
			__( 'Reputation caches older than 7 days', 'lnkup-marketplace' ) => (string) $stale,
			__( 'Legacy documents still in public uploads', 'lnkup-marketplace' ) => (string) LNKup_Document_Migration::remaining(),
			__( 'Private uploads folder', 'lnkup-marketplace' )         => self::probe_label(),
			__( 'Next daily Passport job', 'lnkup-marketplace' )         => wp_next_scheduled( LNKup_Passport::DAILY_HOOK ) ? wp_date( 'M j, H:i', (int) wp_next_scheduled( LNKup_Passport::DAILY_HOOK ) ) : __( 'Not scheduled', 'lnkup-marketplace' ),
		);
		echo '<table class="widefat striped" style="max-width:780px"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">';
		wp_nonce_field( 'lnkup_passport_probe', 'lnkup_nonce' );
		echo '<input type="hidden" name="action" value="lnkup_passport_probe" /><button class="button">' . esc_html__( 'Re-check private uploads folder', 'lnkup-marketplace' ) . '</button></form>';

		$migrating = (bool) get_option( LNKup_Document_Migration::ENABLED_OPT );
		echo '<h2>' . esc_html__( 'Private document migration', 'lnkup-marketplace' ) . '</h2>';
		echo '<p>' . esc_html__( 'Moves vendor documents uploaded before private storage existed out of public uploads, 25 per hour. Files are moved (not copied) and metadata updated. Review the dry run first.', 'lnkup-marketplace' ) . '</p>';
		$sample = LNKup_Document_Migration::find_public_files( 10 );
		if ( $sample ) {
			echo '<p><strong>' . esc_html__( 'Dry run (first 10):', 'lnkup-marketplace' ) . '</strong></p><ul>';
			foreach ( $sample as $row ) {
				echo '<li>#' . esc_html( (string) $row['doc_id'] ) . ' — <code>' . esc_html( $row['rel_path'] ) . '</code></li>';
			}
			echo '</ul>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lnkup_passport_migration', 'lnkup_nonce' );
		echo '<input type="hidden" name="action" value="lnkup_passport_migration" /><input type="hidden" name="mode" value="' . ( $migrating ? 'stop' : 'start' ) . '" />';
		echo '<button class="button ' . ( $migrating ? '' : 'button-primary' ) . '">' . esc_html( $migrating ? __( 'Pause migration', 'lnkup-marketplace' ) : __( 'Start migration', 'lnkup-marketplace' ) ) . '</button></form>';
		$log = LNKup_Document_Migration::recent_log( 10 );
		if ( $log ) {
			echo '<ul>';
			foreach ( $log as $entry ) {
				echo '<li>' . esc_html( $entry['at'] . ' · ' . $entry['event'] . ' · ' . wp_json_encode( $entry['context'] ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<p class="description">' . esc_html__( 'Nginx and IIS ignore .htaccess. Add a server rule that denies direct access to /wp-content/uploads/lnkup-private/ (see docs/PHASE_27_VENDOR_PASSPORT.md). Downloads always go through authenticated, logged PHP routes.', 'lnkup-marketplace' ) . '</p>';
	}

	private static function render_vendor_tab(): void {
		$profile_id = isset( $_GET['profile_id'] ) ? absint( $_GET['profile_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<form method="get" style="margin:1em 0">
			<input type="hidden" name="page" value="lnkup-passport" />
			<input type="hidden" name="tab" value="vendor" />
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Business name', 'lnkup-marketplace' ); ?>" />
			<button class="button"><?php esc_html_e( 'Find vendor', 'lnkup-marketplace' ); ?></button>
		</form>
		<?php
		if ( $search && ! $profile_id ) {
			$found = get_posts( array( 'post_type' => 'lnkup_vendor_profile', 's' => $search, 'posts_per_page' => 20, 'post_status' => 'any' ) );
			echo '<ul>';
			foreach ( $found as $p ) {
				echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=lnkup-passport&tab=vendor&profile_id=' . $p->ID ) ) . '">' . esc_html( $p->post_title ) . '</a> <span class="description">(' . esc_html( $p->post_status ) . ')</span></li>';
			}
			echo $found ? '' : '<li>' . esc_html__( 'No vendors found.', 'lnkup-marketplace' ) . '</li>';
			echo '</ul>';
			return;
		}
		if ( ! $profile_id || 'lnkup_vendor_profile' !== get_post_type( $profile_id ) ) {
			return;
		}
		$owner    = LNKup_Passport_Projection::owner_view( $profile_id );
		$history  = LNKup_Verification_Records::history( $profile_id, 50 );
		$stats    = LNKup_Participation_History::stats( $profile_id );
		$shares   = LNKup_Document_Vault::shares_for_profile( $profile_id );
		$activity = self::activity( $profile_id, 60 );
		?>
		<h2><?php echo esc_html( $owner['business_name'] ); ?> <span class="description">#<?php echo esc_html( (string) $profile_id ); ?></span></h2>
		<p>
			<?php echo esc_html( $owner['is_public'] ? __( 'Public Passport', 'lnkup-marketplace' ) : __( 'Private Passport', 'lnkup-marketplace' ) ); ?>
			<?php if ( $owner['public_url'] ) : ?> · <a href="<?php echo esc_url( $owner['public_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $owner['public_url'] ); ?></a><?php endif; ?>
			· <?php echo esc_html( sprintf( /* translators: %d: score */ __( 'Profile completeness %d%%', 'lnkup-marketplace' ), (int) $owner['strength']['score'] ) ); ?>
			· <?php echo esc_html( sprintf( /* translators: %d: internal score */ __( 'Internal score %d (staff only)', 'lnkup-marketplace' ), (int) get_post_meta( $profile_id, '_lnkup_reputation_score', true ) ) ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1em">
			<?php wp_nonce_field( 'lnkup_passport_refresh', 'lnkup_nonce' ); ?>
			<input type="hidden" name="action" value="lnkup_passport_refresh" />
			<input type="hidden" name="profile_id" value="<?php echo esc_attr( (string) $profile_id ); ?>" />
			<button class="button"><?php esc_html_e( 'Recalculate reputation & history', 'lnkup-marketplace' ); ?></button>
		</form>

		<h3><?php esc_html_e( 'Participation provenance', 'lnkup-marketplace' ); ?></h3>
		<p class="description"><?php echo esc_html( wp_json_encode( $stats ) ); ?></p>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Date', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Evidence', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Application', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Tour', 'lnkup-marketplace' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( LNKup_Participation_History::all_rows( $profile_id ) as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['event_title'] ); ?></td>
					<td><?php echo esc_html( $row['event_date'] ); ?></td>
					<td><?php echo esc_html( $row['state_label'] ); ?></td>
					<td><?php echo esc_html( $row['evidence'] ?: '—' ); ?></td>
					<td><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $row['application_id'] ) ); ?>">#<?php echo esc_html( (string) $row['application_id'] ); ?></a></td>
					<td><?php echo esc_html( $row['tour_title'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Verification history', 'lnkup-marketplace' ); ?></h3>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'When', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Type', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Source', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Reviewer', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Expires', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Reason', 'lnkup-marketplace' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $history as $row ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
					<td><?php echo esc_html( (string) $row['type_label'] ); ?></td>
					<td><?php echo esc_html( (string) $row['status_label'] ); ?></td>
					<td><?php echo esc_html( (string) $row['source'] ); ?></td>
					<td><?php echo esc_html( (string) $row['reviewer_name'] ); ?></td>
					<td><?php echo esc_html( (string) $row['expires_at'] ); ?></td>
					<td><?php echo esc_html( (string) $row['reason'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $history ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No verification records yet.', 'lnkup-marketplace' ); ?></td></tr>
			<?php endif; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Document shares', 'lnkup-marketplace' ); ?></h3>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Document', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Organizer', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Granted', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Access', 'lnkup-marketplace' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $shares as $share ) : ?>
				<tr>
					<td><?php echo esc_html( $share['document_label'] . ' v' . $share['version'] ); ?></td>
					<td><?php echo esc_html( $share['organizer_name'] ); ?></td>
					<td><?php echo esc_html( $share['event_title'] ); ?></td>
					<td><?php echo esc_html( $share['granted_at'] ); ?></td>
					<td><?php echo esc_html( $share['active'] ? __( 'Active', 'lnkup-marketplace' ) : ( $share['revoked_at'] ? __( 'Revoked', 'lnkup-marketplace' ) : __( 'Lapsed', 'lnkup-marketplace' ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $shares ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No documents shared.', 'lnkup-marketplace' ); ?></td></tr>
			<?php endif; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Activity timeline (private)', 'lnkup-marketplace' ); ?></h3>
		<ol>
			<?php foreach ( $activity as $row ) : ?>
				<li><code><?php echo esc_html( (string) $row['created_at'] ); ?></code> <?php echo esc_html( (string) $row['activity_type'] ); ?> <span class="description"><?php echo esc_html( wp_trim_words( (string) $row['payload'], 20 ) ); ?></span></li>
			<?php endforeach; ?>
			<?php if ( ! $activity ) : ?>
				<li><?php esc_html_e( 'No activity recorded.', 'lnkup-marketplace' ); ?></li>
			<?php endif; ?>
		</ol>
		<?php
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function activity( int $profile_id, int $limit ): array {
		global $wpdb;
		$table = LNKup_Reputation_Tables::activity_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT activity_type, payload, created_at FROM {$table} WHERE vendor_profile_id = %d ORDER BY id DESC LIMIT %d", $profile_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function render_settings_tab(): void {
		$s      = LNKup_Passport_Settings::snapshot();
		$labels = array(
			'passport_enabled'            => __( 'Vendor Passport (master switch)', 'lnkup-marketplace' ),
			'passport_verification_v2'    => __( 'Vendor verification requests & renewal', 'lnkup-marketplace' ),
			'document_vault_sharing'      => __( 'Document Vault sharing with organizers', 'lnkup-marketplace' ),
			'passport_history_v2'         => __( 'Participation history & achievements on Passport', 'lnkup-marketplace' ),
			'review_responses'            => __( 'Vendor review responses', 'lnkup-marketplace' ),
			'matching_reputation_signals' => __( 'Use Passport signals in matching (soft, never exclusionary)', 'lnkup-marketplace' ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'lnkup_passport_settings', 'lnkup_nonce' ); ?>
			<input type="hidden" name="action" value="lnkup_passport_settings" />
			<h2><?php esc_html_e( 'Feature flags', 'lnkup-marketplace' ); ?></h2>
			<?php foreach ( $labels as $key => $label ) : ?>
				<p><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?> /> <?php echo esc_html( $label ); ?></label></p>
			<?php endforeach; ?>
			<h2><?php esc_html_e( 'Reviews', 'lnkup-marketplace' ); ?></h2>
			<table class="form-table">
				<?php
				$ints = array(
					'passport_review_min_count'      => __( 'Minimum reviews before an average is shown', 'lnkup-marketplace' ),
					'passport_review_min_organizers' => __( 'Minimum distinct organizers before an average is shown', 'lnkup-marketplace' ),
					'passport_review_edit_days'      => __( 'Organizer edit window (days)', 'lnkup-marketplace' ),
					'passport_response_edit_days'    => __( 'Vendor response edit window (days)', 'lnkup-marketplace' ),
					'passport_report_monthly_cap'    => __( 'Review reports per vendor per 30 days', 'lnkup-marketplace' ),
					'passport_share_token_minutes'   => __( 'Shared document link lifetime (minutes)', 'lnkup-marketplace' ),
					'passport_public_rate_per_minute' => __( 'Public Passport requests per IP per minute', 'lnkup-marketplace' ),
				);
				foreach ( $ints as $key => $label ) :
					?>
					<tr><th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input type="number" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $s[ $key ] ); ?>" class="small-text" /></td></tr>
				<?php endforeach; ?>
				<tr><th><label for="passport_reminder_days"><?php esc_html_e( 'Document expiry reminders (days before)', 'lnkup-marketplace' ); ?></label></th><td><input type="text" id="passport_reminder_days" name="passport_reminder_days" value="<?php echo esc_attr( implode( ',', (array) $s['passport_reminder_days'] ) ); ?>" /></td></tr>
			</table>
			<h2><?php esc_html_e( 'Profile completeness weights', 'lnkup-marketplace' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Relative weights (normalized to 100). Completeness is separate from verification and never includes documents or reviews.', 'lnkup-marketplace' ); ?></p>
			<table class="form-table">
				<?php foreach ( LNKup_Passport_Settings::DEFAULT_STRENGTH_WEIGHTS as $key => $default ) : ?>
					<tr><th><?php echo esc_html( ucwords( str_replace( '_', ' ', $key ) ) ); ?></th><td><input type="number" min="0" max="100" name="<?php echo esc_attr( 'passport_strength_' . $key ); ?>" value="<?php echo esc_attr( (string) $s[ 'passport_strength_' . $key ] ); ?>" class="small-text" /></td></tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button( __( 'Save Passport settings', 'lnkup-marketplace' ) ); ?>
		</form>
		<?php
	}

	public static function handle_settings(): void {
		check_admin_referer( 'lnkup_passport_settings', 'lnkup_nonce' );
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$input = array();
		foreach ( LNKup_Passport_Settings::FLAGS as $flag ) {
			$input[ $flag ] = ! empty( $_POST[ $flag ] );
		}
		foreach ( array_keys( LNKup_Passport_Settings::INTS ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$input[ $key ] = (int) $_POST[ $key ];
			}
		}
		if ( isset( $_POST['passport_reminder_days'] ) ) {
			$input['passport_reminder_days'] = sanitize_text_field( wp_unslash( $_POST['passport_reminder_days'] ) );
		}
		foreach ( array_keys( LNKup_Passport_Settings::DEFAULT_STRENGTH_WEIGHTS ) as $key ) {
			if ( isset( $_POST[ 'passport_strength_' . $key ] ) ) {
				$input[ 'passport_strength_' . $key ] = (int) $_POST[ 'passport_strength_' . $key ];
			}
		}
		$before = LNKup_Passport_Settings::snapshot();
		$after  = LNKup_Passport_Settings::save( $input );
		$log    = get_option( 'lnkup_passport_settings_log', array() );
		$log    = is_array( $log ) ? $log : array();
		array_unshift( $log, array( 'user_id' => get_current_user_id(), 'at' => current_time( 'mysql' ), 'before' => $before, 'after' => $after ) );
		update_option( 'lnkup_passport_settings_log', array_slice( $log, 0, 50 ), false );
		$message = LNKup_Passport_Settings::$sharing_blocked
			? __( 'Settings saved, but Document Vault sharing stayed off: the private uploads folder did not pass the protection check. See System health.', 'lnkup-marketplace' )
			: __( 'Passport settings saved.', 'lnkup-marketplace' );
		wp_safe_redirect( add_query_arg( 'lnkup_msg', rawurlencode( $message ), admin_url( 'admin.php?page=lnkup-passport&tab=settings' ) ) );
		exit;
	}

	public static function handle_probe(): void {
		check_admin_referer( 'lnkup_passport_probe', 'lnkup_nonce' );
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		LNKup_Private_Uploads_Probe::run();
		wp_safe_redirect( add_query_arg( 'lnkup_msg', rawurlencode( self::probe_label() ), admin_url( 'admin.php?page=lnkup-passport&tab=health' ) ) );
		exit;
	}

	public static function handle_migration(): void {
		check_admin_referer( 'lnkup_passport_migration', 'lnkup_nonce' );
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$url = admin_url( 'admin.php?page=lnkup-passport&tab=health' );
		if ( 'stop' === sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) ) ) {
			LNKup_Document_Migration::disable();
		} elseif ( ! LNKup_Document_Migration::enable() ) {
			$url = add_query_arg( 'lnkup_msg', rawurlencode( __( 'Migration not started: the private uploads folder did not pass the protection check.', 'lnkup-marketplace' ) ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	public static function handle_refresh(): void {
		check_admin_referer( 'lnkup_passport_refresh', 'lnkup_nonce' );
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$profile_id = absint( $_POST['profile_id'] ?? 0 );
		LNKup_Participation_History::flush( $profile_id );
		LNKup_Vendor_Reputation::refresh( $profile_id );
		wp_safe_redirect( add_query_arg( 'lnkup_msg', rawurlencode( __( 'Recalculated.', 'lnkup-marketplace' ) ), admin_url( 'admin.php?page=lnkup-passport&tab=vendor&profile_id=' . $profile_id ) ) );
		exit;
	}
}
