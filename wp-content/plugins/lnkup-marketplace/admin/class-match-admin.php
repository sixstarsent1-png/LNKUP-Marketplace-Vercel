<?php
/**
 * LNKup -> Marketplace Intelligence: matching rules, category groups,
 * data quality, "why matched?" diagnostics and job health.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Match_Admin {

	const SLUG = 'lnkup-marketplace-intelligence';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 28 );
		add_action( 'admin_post_lnkup_match_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_lnkup_match_save_groups', array( __CLASS__, 'handle_save_groups' ) );
		add_action( 'admin_post_lnkup_match_rebuild', array( __CLASS__, 'handle_rebuild' ) );
		add_action( 'admin_post_lnkup_match_revert', array( __CLASS__, 'handle_revert' ) );
		add_action( 'admin_post_lnkup_rollout_save', array( __CLASS__, 'handle_rollout_save' ) );
		add_action( 'admin_post_lnkup_match_market_export', array( __CLASS__, 'handle_market_export' ) );
		add_action( 'admin_post_lnkup_match_market_rebuild', array( __CLASS__, 'handle_market_rebuild' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Marketplace Intelligence', 'lnkup-marketplace' ),
			__( 'Marketplace Intelligence', 'lnkup-marketplace' ),
			'lnkup_manage_all',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	private static function require_admin() {
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
	}

	private static function tabs() {
		return array(
			'overview'    => __( 'Overview', 'lnkup-marketplace' ),
			'rules'       => __( 'Matching Rules', 'lnkup-marketplace' ),
			'categories'  => __( 'Category Groups', 'lnkup-marketplace' ),
			'quality'     => __( 'Data Quality', 'lnkup-marketplace' ),
			'diagnose'    => __( 'Why Matched?', 'lnkup-marketplace' ),
			'health'      => __( 'Automation Health', 'lnkup-marketplace' ),
			'rollout'     => __( 'Rollout & health', 'lnkup-marketplace' ),
			'supply'      => __( 'Supply & Demand', 'lnkup-marketplace' ),
		);
	}

	public static function render_page() {
		self::require_admin();
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( self::tabs()[ $tab ] ) ) {
			$tab = 'overview';
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Marketplace Intelligence', 'lnkup-marketplace' ) . '</h1>';
		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'lnkup-marketplace' ) . '</p></div>';
		}
		$error = get_transient( self::error_key() );
		if ( $error ) {
			delete_transient( self::error_key() );
			echo '<div class="notice notice-error"><p>' . esc_html( (string) $error ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::tabs() as $key => $label ) {
			$url = add_query_arg( array( 'page' => self::SLUG, 'tab' => $key ), admin_url( 'admin.php' ) );
			echo '<a class="nav-tab ' . ( $key === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav><div style="margin-top:16px">';
		call_user_func( array( __CLASS__, 'render_' . $tab ) );
		echo '</div></div>';
	}

	private static function render_overview() {
		$health = LNKup_Match_Cron::health();
		$flags  = array();
		foreach ( LNKup_Match_Settings::FLAGS as $flag ) {
			$flags[ $flag ] = LNKup_Match_Settings::active( $flag );
		}
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		$rows = array(
			__( 'Vendors indexed', 'lnkup-marketplace' )               => $health['vendors_indexed'],
			__( 'Vendors with map location', 'lnkup-marketplace' )     => $health['vendors_geo'],
			__( 'Recommended matches (cached)', 'lnkup-marketplace' )  => $health['matches'],
			__( 'Recompute queue', 'lnkup-marketplace' )               => $health['queue_size'],
		);
		foreach ( $rows as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( number_format_i18n( (int) $value ) ) . '</td></tr>';
		}
		foreach ( $flags as $flag => $on ) {
			echo '<tr><th><code>' . esc_html( $flag ) . '</code></th><td>' . ( $on ? esc_html__( 'On', 'lnkup-marketplace' ) : esc_html__( 'Off', 'lnkup-marketplace' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::render_quality_report();
		self::render_alert_report();
		echo '<p class="description">' . esc_html__( 'Matching is rules-based and explainable. Eligibility is checked first; relevance never overrides a failed eligibility rule. Popularity, organizer size and payment volume are not ranking factors.', 'lnkup-marketplace' ) . '</p>';
	}

	/**
	 * Recommendation funnel (last 30 days, observed): by band, section, rules version, and cohort vs. rest.
	 */
	private static function render_quality_report() {
		$report = LNKup_Match_Quality::report( 30 );
		echo '<h2>' . esc_html__( 'Recommendation quality (last 30 days, observed)', 'lnkup-marketplace' ) . '</h2>';
		if ( ! $report['totals']['impressions'] && ! $report['totals']['applications'] ) {
			echo '<p class="description">' . esc_html__( 'No recommendation impressions yet. They appear once vendors in the rollout audience open Find Events.', 'lnkup-marketplace' ) . '</p>';
		} else {
			$groups = array(
				__( 'All recommendations', 'lnkup-marketplace' ) => array( 'total' => $report['totals'] ),
				__( 'By match band', 'lnkup-marketplace' )        => $report['by_band'],
				__( 'By section', 'lnkup-marketplace' )           => $report['by_section'],
				__( 'By rules version', 'lnkup-marketplace' )     => $report['by_rules_version'],
			);
			foreach ( $groups as $title => $rows ) {
				self::render_funnel_table( $title, $rows );
			}
		}
		$cohort = $report['cohort'];
		echo '<h3>' . esc_html( sprintf( __( 'Vendors in the recommendations audience vs. the rest (%s)', 'lnkup-marketplace' ), 'all' === $cohort['audience'] ? __( 'everyone', 'lnkup-marketplace' ) : $cohort['percent'] . '%' ) ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th></th><th>' . esc_html__( 'Vendors', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Applications', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Per vendor', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Approval rate', 'lnkup-marketplace' ) . '</th></tr></thead><tbody>';
		foreach ( array( 'cohort' => __( 'In audience', 'lnkup-marketplace' ), 'rest' => __( 'Not yet', 'lnkup-marketplace' ) ) as $key => $label ) {
			$row = $cohort['groups'][ $key ];
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( number_format_i18n( $row['vendors'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['applications'] ) ) . '</td><td>' . esc_html( null === $row['applications_per_vendor'] ? '—' : (string) $row['applications_per_vendor'] ) . '</td><td>' . esc_html( null === $row['approval_rate'] ? '—' : $row['approval_rate'] . '%' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Applications that followed a recommendation click or impression within 14 days. This shows what happened, not that the recommendation caused it.', 'lnkup-marketplace' ) . ( $report['partial'] ? ' ' . esc_html__( 'Partial: only the most recent events were counted.', 'lnkup-marketplace' ) : '' ) . '</p>';
	}

	/**
	 * @param string $title Table title.
	 * @param array  $rows  Label => funnel row.
	 */
	private static function render_funnel_table( $title, array $rows ) {
		if ( ! $rows ) {
			return;
		}
		echo '<h3>' . esc_html( $title ) . '</h3><table class="widefat striped" style="max-width:960px"><thead><tr><th></th>';
		foreach ( array( __( 'Impressions', 'lnkup-marketplace' ), __( 'Clicks', 'lnkup-marketplace' ), __( 'CTR', 'lnkup-marketplace' ), __( 'Applications', 'lnkup-marketplace' ), __( 'Approved', 'lnkup-marketplace' ), __( 'Approval rate', 'lnkup-marketplace' ), __( 'Paid', 'lnkup-marketplace' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $label => $row ) {
			echo '<tr><th>' . esc_html( (string) $label ) . '</th>';
			foreach ( array( 'impressions', 'clicks' ) as $col ) {
				echo '<td>' . esc_html( number_format_i18n( $row[ $col ] ) ) . '</td>';
			}
			echo '<td>' . esc_html( null === $row['ctr'] ? '—' : $row['ctr'] . '%' ) . '</td>';
			foreach ( array( 'applications', 'approved' ) as $col ) {
				echo '<td>' . esc_html( number_format_i18n( $row[ $col ] ) ) . '</td>';
			}
			echo '<td>' . esc_html( null === $row['approval_rate'] ? '—' : $row['approval_rate'] . '%' ) . '</td><td>' . esc_html( number_format_i18n( $row['paid'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Matching alert performance (lnkup-communications), last 30 days. Observed, not causal.
	 */
	private static function render_alert_report() {
		if ( ! class_exists( 'LNKup_Comms_Analytics' ) ) {
			echo '<p class="description">' . esc_html__( 'Matching alerts are delivered by LNKup Communications, which is not active.', 'lnkup-marketplace' ) . '</p>';
			return;
		}
		$triggers = array( 'v_opportunity_match', 'v_organizer_new_opportunity', 'v_tour_match', 'v_weekly_matches', 'v_saved_search_match', 'v_saved_deadline', 'v_invited_to_apply', 'o_recruit_vendors', 'o_open_spots', 'o_new_matching_vendors' );
		$rows     = array_filter(
			LNKup_Comms_Analytics::trigger_report( 30 ),
			static function ( $row ) use ( $triggers ) {
				return in_array( (string) $row['trigger_key'], $triggers, true );
			}
		);
		global $wpdb;
		$table   = LNKup_Marketplace_Analytics::table_name();
		$applied = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT event_data FROM {$table} WHERE event_type = 'match_alert_application' AND created_at >= %s", wp_date( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ) as $data ) {
			$decoded = json_decode( (string) $data, true );
			$key     = (string) ( $decoded['trigger'] ?? '' );
			if ( '' !== $key ) {
				$applied[ $key ] = ( $applied[ $key ] ?? 0 ) + 1;
			}
		}
		echo '<h2>' . esc_html__( 'Matching alerts (last 30 days, observed)', 'lnkup-marketplace' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'No matching alerts yet. Enable the discovery flows in LNKup Communications once matching is on.', 'lnkup-marketplace' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:860px"><thead><tr>';
		foreach ( array( __( 'Alert', 'lnkup-marketplace' ), __( 'Created', 'lnkup-marketplace' ), __( 'Sent', 'lnkup-marketplace' ), __( 'Suppressed', 'lnkup-marketplace' ), __( 'Opened', 'lnkup-marketplace' ), __( 'Clicked', 'lnkup-marketplace' ), __( 'Applied after open/click', 'lnkup-marketplace' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$key = (string) $row['trigger_key'];
			echo '<tr><td><code>' . esc_html( $key ) . '</code></td>';
			foreach ( array( 'created', 'sent', 'suppressed', 'opened', 'clicked' ) as $col ) {
				echo '<td>' . esc_html( number_format_i18n( (int) $row[ $col ] ) ) . '</td>';
			}
			echo '<td>' . esc_html( number_format_i18n( (int) ( $applied[ $key ] ?? 0 ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Applications that followed an opened or clicked alert within 14 days. This shows what happened, not that the alert caused it.', 'lnkup-marketplace' ) . '</p>';
	}

	private static function render_rules() {
		$settings = lnkup_get_settings();
		$preview  = isset( $_GET['preview'] ) ? get_transient( self::preview_key() ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_array( $preview ) ) {
			$settings = array_merge( $settings, (array) $preview['candidate'] );
			self::render_preview( (array) $preview['result'] );
		}
		echo '<p>' . esc_html( sprintf( __( 'Current rules version: v%d', 'lnkup-marketplace' ), LNKup_Match_Settings::rules_version() ) ) . '</p>';
		$labels   = array(
			'category'     => __( 'Vendor category compatibility', 'lnkup-marketplace' ),
			'geo'          => __( 'Geographic compatibility', 'lnkup-marketplace' ),
			'requirements' => __( 'Event requirements (documents, facilities)', 'lnkup-marketplace' ),
			'preferences'  => __( 'Vendor preferences (budget, indoor/outdoor, event type)', 'lnkup-marketplace' ),
			'dates'        => __( 'Date availability', 'lnkup-marketplace' ),
			'signals'      => __( 'Relationship signals (worked with / follows)', 'lnkup-marketplace' ),
		);
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lnkup_match_save_settings' );
		echo '<input type="hidden" name="action" value="lnkup_match_save_settings" />';
		echo '<h2>' . esc_html__( 'Feature flags', 'lnkup-marketplace' ) . '</h2><table class="form-table">';
		$flag_labels = array(
			'matching_enabled'                     => __( 'Matching engine (master switch)', 'lnkup-marketplace' ),
			'matching_vendor_discovery_enabled'    => __( 'Vendor recommendations', 'lnkup-marketplace' ),
			'matching_organizer_discovery_enabled' => __( 'Organizer Find Vendors & recruitment', 'lnkup-marketplace' ),
			'matching_tours_enabled'               => __( 'Tours & series', 'lnkup-marketplace' ),
		);
		foreach ( $flag_labels as $key => $label ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( ! empty( $settings[ $key ] ), true, false ) . ' /> ' . esc_html__( 'Enabled', 'lnkup-marketplace' ) . '</label></td></tr>';
		}
		echo '</table><h2>' . esc_html__( 'Ranking weights', 'lnkup-marketplace' ) . '</h2><table class="form-table">';
		foreach ( LNKup_Match_Settings::WEIGHT_KEYS as $factor => $key ) {
			echo '<tr><th>' . esc_html( $labels[ $factor ] ) . '</th><td><input type="number" min="0" max="100" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) ( $settings[ $key ] ?? LNKup_Match_Settings::DEFAULT_WEIGHTS[ $factor ] ) ) . '" class="small-text" /></td></tr>';
		}
		echo '</table><p class="description">' . esc_html__( 'Weights are relative. Factors without data for a given vendor are dropped and the rest rescaled, so new vendors are not penalized for missing history.', 'lnkup-marketplace' ) . '</p>';
		echo '<h2>' . esc_html__( 'Thresholds & anti-spam limits', 'lnkup-marketplace' ) . '</h2><table class="form-table">';
		$numbers = array(
			'match_min_score'                => __( 'Minimum match score to recommend (0-100)', 'lnkup-marketplace' ),
			'match_invite_vendor_weekly_cap' => __( 'Max invites a vendor can receive per week (from new organizers)', 'lnkup-marketplace' ),
			'match_invite_org_daily_cap'     => __( 'Max invites an organizer can send per day', 'lnkup-marketplace' ),
			'match_invite_batch_max'         => __( 'Max vendors per batch invite', 'lnkup-marketplace' ),
		);
		$current = array(
			'match_min_score'                => LNKup_Match_Settings::min_score(),
			'match_invite_vendor_weekly_cap' => LNKup_Match_Settings::vendor_weekly_invite_cap(),
			'match_invite_org_daily_cap'     => LNKup_Match_Settings::organizer_daily_invite_cap(),
			'match_invite_batch_max'         => LNKup_Match_Settings::batch_max(),
		);
		foreach ( $numbers as $key => $label ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td><input type="number" min="0" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) ( $settings[ $key ] ?? $current[ $key ] ) ) . '" class="small-text" /></td></tr>';
		}
		echo '</table>';
		$note = is_array( $preview ) ? (string) $preview['note'] : '';
		echo '<p><label for="lnkup-change-note"><strong>' . esc_html__( 'Why are you changing the ranking?', 'lnkup-marketplace' ) . '</strong></label><br /><input type="text" id="lnkup-change-note" name="change_note" class="regular-text" maxlength="200" value="' . esc_attr( $note ) . '" /><br /><span class="description">' . esc_html__( 'Required when a weight or the minimum score changes. Saving creates a new rules version and recomputes matches.', 'lnkup-marketplace' ) . '</span></p>';
		echo '<p class="submit">';
		echo '<button type="submit" name="intent" value="preview" class="button">' . esc_html__( 'Preview changes', 'lnkup-marketplace' ) . '</button> ';
		echo '<button type="submit" name="intent" value="save" class="button button-primary">' . esc_html__( 'Save as new version', 'lnkup-marketplace' ) . '</button>';
		echo '</p></form>';

		self::render_versions();

		$log = get_option( 'lnkup_match_settings_log', array() );
		if ( is_array( $log ) && $log ) {
			echo '<h2>' . esc_html__( 'Change history', 'lnkup-marketplace' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Who', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Changed', 'lnkup-marketplace' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $log, 0, 10 ) as $entry ) {
				$user    = get_userdata( (int) $entry['user_id'] );
				$changed = array();
				foreach ( (array) $entry['after'] as $key => $value ) {
					if ( ( $entry['before'][ $key ] ?? null ) != $value ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
						$changed[] = $key . ': ' . wp_json_encode( $entry['before'][ $key ] ?? null ) . ' → ' . wp_json_encode( $value );
					}
				}
				echo '<tr><td>' . esc_html( $entry['at'] ) . '</td><td>' . esc_html( $user ? $user->display_name : '#' . (int) $entry['user_id'] ) . '</td><td>' . esc_html( implode( '; ', $changed ) . ( ! empty( $entry['note'] ) ? ' — ' . $entry['note'] : '' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Simulator output for the candidate rules (no data was changed).
	 *
	 * @param array $result LNKup_Match_Simulator::preview() result.
	 */
	private static function render_preview( array $result ) {
		echo '<div class="notice notice-info" style="padding:12px" data-testid="lnkup-match-preview"><h2 style="margin-top:0">' . esc_html__( 'Preview: what changes if you save', 'lnkup-marketplace' ) . '</h2>';
		if ( ! empty( $result['error'] ) ) {
			echo '<p>' . esc_html( (string) $result['error'] ) . '</p></div>';
			return;
		}
		echo '<p>' . esc_html( sprintf( __( 'Sample: %1$d vendors, %2$d vendor-opportunity pairs.', 'lnkup-marketplace' ), (int) $result['vendors'], (int) $result['pairs'] ) ) . ( ! empty( $result['partial'] ) ? ' ' . esc_html__( 'Partial sample (time limit reached).', 'lnkup-marketplace' ) : '' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th></th><th>' . esc_html__( 'Now', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'After', 'lnkup-marketplace' ) . '</th></tr></thead><tbody>';
		echo '<tr><th>' . esc_html__( 'Recommended pairs', 'lnkup-marketplace' ) . '</th><td>' . (int) $result['before']['recommended'] . '</td><td>' . (int) $result['after']['recommended'] . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Vendors with at least one recommendation', 'lnkup-marketplace' ) . '</th><td>' . (int) $result['before']['vendors_with_matches'] . '</td><td>' . (int) $result['after']['vendors_with_matches'] . '</td></tr>';
		foreach ( array( 'strong', 'good', 'possible' ) as $band ) {
			echo '<tr><th>' . esc_html( ucfirst( $band ) ) . '</th><td>' . (int) ( $result['before']['bands'][ $band ] ?? 0 ) . '</td><td>' . (int) ( $result['after']['bands'][ $band ] ?? 0 ) . '</td></tr>';
		}
		echo '<tr><th>' . esc_html__( 'Average score (recommended)', 'lnkup-marketplace' ) . '</th><td>' . esc_html( (string) $result['before']['avg_score'] ) . '</td><td>' . esc_html( (string) $result['after']['avg_score'] ) . '</td></tr>';
		echo '</tbody></table>';
		if ( ! empty( $result['factor_contribution'] ) ) {
			echo '<h3>' . esc_html__( 'Average factor contribution (points, recommended pairs after the change)', 'lnkup-marketplace' ) . '</h3><p>';
			$parts = array();
			foreach ( (array) $result['factor_contribution'] as $factor => $points ) {
				$parts[] = $factor . ': ' . $points;
			}
			echo esc_html( implode( ' · ', $parts ) ) . '</p>';
		}
		foreach ( array( 'gained' => __( 'Newly recommended (examples)', 'lnkup-marketplace' ), 'lost' => __( 'No longer recommended (examples)', 'lnkup-marketplace' ) ) as $key => $title ) {
			if ( empty( $result[ $key ] ) ) {
				continue;
			}
			echo '<h3>' . esc_html( $title ) . '</h3><ul>';
			foreach ( (array) $result[ $key ] as $row ) {
				echo '<li>' . esc_html( sprintf( '%s → %s: %d → %d%s', $row['vendor'], $row['opportunity'], (int) $row['score_before'], (int) $row['score_after'], $row['reason'] ? ' (' . $row['reason'] . ')' : '' ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<p class="description">' . esc_html__( 'Nothing was saved. Review the numbers, then use "Save as new version".', 'lnkup-marketplace' ) . '</p></div>';
	}

	/**
	 * Rules versions with notes and one-click revert.
	 */
	private static function render_versions() {
		$versions = LNKup_Match_Settings::versions();
		if ( ! $versions ) {
			return;
		}
		$current = LNKup_Match_Settings::rules_version();
		echo '<h2>' . esc_html__( 'Rules versions', 'lnkup-marketplace' ) . '</h2><table class="widefat striped" style="max-width:960px"><thead><tr><th>' . esc_html__( 'Version', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'When', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Who', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Note', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Weights / min score', 'lnkup-marketplace' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( array_slice( $versions, 0, 20 ) as $row ) {
			$user     = (int) $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
			$settings = (array) $row['settings'];
			$summary  = array();
			foreach ( LNKup_Match_Settings::WEIGHT_KEYS as $factor => $key ) {
				$summary[] = $factor . ' ' . (int) ( $settings[ $key ] ?? 0 );
			}
			$summary[] = 'min ' . (int) ( $settings['match_min_score'] ?? 0 );
			echo '<tr><td>v' . (int) $row['version'] . ( (int) $row['version'] === $current ? ' <strong>(' . esc_html__( 'current', 'lnkup-marketplace' ) . ')</strong>' : '' ) . '</td><td>' . esc_html( (string) $row['at'] ) . '</td><td>' . esc_html( $user ? $user->display_name : '—' ) . '</td><td>' . esc_html( (string) $row['note'] ) . '</td><td>' . esc_html( implode( ', ', $summary ) ) . '</td><td>';
			if ( (int) $row['version'] !== $current ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:4px">';
				wp_nonce_field( 'lnkup_match_revert' );
				echo '<input type="hidden" name="action" value="lnkup_match_revert" /><input type="hidden" name="version" value="' . (int) $row['version'] . '" />';
				echo '<input type="text" name="change_note" placeholder="' . esc_attr__( 'Why (optional)', 'lnkup-marketplace' ) . '" class="regular-text" style="width:160px" />';
				echo '<button type="submit" class="button">' . esc_html__( 'Revert to this version', 'lnkup-marketplace' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_categories() {
		$terms  = get_terms( array( 'taxonomy' => LNKup_Match_Categories::TAXONOMY, 'hide_empty' => false ) );
		$groups = LNKup_Match_Categories::groups();
		echo '<p>' . esc_html__( 'Categories in the same group count as related matches (scored below an exact category match).', 'lnkup-marketplace' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lnkup_match_save_groups' );
		echo '<input type="hidden" name="action" value="lnkup_match_save_groups" /><table class="widefat striped" style="max-width:720px"><thead><tr><th>' . esc_html__( 'Category', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Used by', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Group', 'lnkup-marketplace' ) . '</th></tr></thead><tbody>';
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$current = LNKup_Match_Categories::group_for_term( $term->term_id );
			echo '<tr><td>' . esc_html( $term->name ) . '</td><td>' . esc_html( number_format_i18n( (int) $term->count ) ) . '</td><td><select name="groups[' . (int) $term->term_id . ']"><option value="">' . esc_html__( '— None —', 'lnkup-marketplace' ) . '</option>';
			foreach ( $groups as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '" ' . selected( $current, $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save category groups', 'lnkup-marketplace' ) );
		echo '</form>';
	}

	private static function render_quality() {
		global $wpdb;
		$vtable  = LNKup_Match_Tables::vendor_index_table();
		$vcats   = LNKup_Match_Tables::vendor_categories_table();
		$index   = LNKup_Marketplace_Index::table_name();
		$ocats   = LNKup_Match_Tables::opportunity_categories_table();
		// phpcs:disable WordPress.DB
		$checks = array(
			__( 'Vendors without categories', 'lnkup-marketplace' )        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vtable} v WHERE v.status = 'active' AND v.profile_id NOT IN (SELECT profile_id FROM {$vcats})" ),
			__( 'Vendors without a home city/state', 'lnkup-marketplace' ) => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vtable} WHERE status = 'active' AND city = '' AND state = ''" ),
			__( 'Vendors with a city that could not be located', 'lnkup-marketplace' ) => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vtable} WHERE status = 'active' AND city <> '' AND lat IS NULL" ),
			__( 'Vendors who opted out of discovery', 'lnkup-marketplace' ) => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vtable} WHERE discoverable = 0" ),
			__( 'Vendors with matching preferences under 50% complete', 'lnkup-marketplace' ) => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vtable} WHERE status = 'active' AND profile_completeness < 50" ),
			__( 'Open opportunities without vendor categories', 'lnkup-marketplace' ) => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index} m WHERE m.opportunity_id NOT IN (SELECT opportunity_id FROM {$ocats})" ),
			__( 'Open opportunities without event coordinates', 'lnkup-marketplace' ) => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index} WHERE lat IS NULL OR lat = 0" ),
			__( 'Open opportunities without a deadline', 'lnkup-marketplace' ) => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index} WHERE deadline IS NULL" ),
			__( 'Open opportunities without an event date', 'lnkup-marketplace' ) => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index} WHERE event_date IS NULL" ),
		);
		$terms_without_group = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy = %s AND tt.term_id NOT IN (SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s)", LNKup_Match_Categories::TAXONOMY, LNKup_Match_Categories::META ) );
		$missing_geo_events  = $wpdb->get_results( "SELECT DISTINCT event_id, title, city, state FROM {$index} WHERE lat IS NULL OR lat = 0 ORDER BY event_date ASC LIMIT 20", ARRAY_A );
		// phpcs:enable WordPress.DB
		$checks[ __( 'Categories without a group', 'lnkup-marketplace' ) ] = $terms_without_group;

		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		foreach ( $checks as $label => $count ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( number_format_i18n( $count ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $missing_geo_events ) {
			echo '<h2>' . esc_html__( 'Events missing map coordinates (location normalization)', 'lnkup-marketplace' ) . '</h2><ul>';
			foreach ( $missing_geo_events as $event ) {
				echo '<li><a href="' . esc_url( get_edit_post_link( (int) $event['event_id'] ) ) . '">' . esc_html( $event['title'] ) . '</a> — ' . esc_html( trim( $event['city'] . ', ' . $event['state'], ', ' ) ) . '</li>';
			}
			echo '</ul>';
		}
	}

	private static function render_diagnose() {
		$profile_id = isset( $_GET['profile_id'] ) ? absint( $_GET['profile_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$opp_id     = isset( $_GET['opportunity_id'] ) ? absint( $_GET['opportunity_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input type="hidden" name="tab" value="diagnose" />';
		echo '<label>' . esc_html__( 'Vendor profile ID', 'lnkup-marketplace' ) . ' <input type="number" name="profile_id" value="' . esc_attr( $profile_id ?: '' ) . '" /></label> ';
		echo '<label>' . esc_html__( 'Opportunity ID', 'lnkup-marketplace' ) . ' <input type="number" name="opportunity_id" value="' . esc_attr( $opp_id ?: '' ) . '" /></label> ';
		submit_button( __( 'Explain', 'lnkup-marketplace' ), 'secondary', '', false );
		echo '</form>';
		if ( ! $profile_id || ! $opp_id ) {
			return;
		}
		$result = LNKup_Match_Service::diagnose( $profile_id, $opp_id );
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			return;
		}
		$live = $result['live'];
		echo '<h2>' . esc_html__( 'Result', 'lnkup-marketplace' ) . '</h2><table class="widefat striped" style="max-width:860px"><tbody>';
		echo '<tr><th>' . esc_html__( 'Eligible', 'lnkup-marketplace' ) . '</th><td>' . ( $live['eligible'] ? esc_html__( 'Yes', 'lnkup-marketplace' ) : esc_html__( 'No', 'lnkup-marketplace' ) . ' — ' . esc_html( implode( ', ', $live['blocks'] ) ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Recommended', 'lnkup-marketplace' ) . '</th><td>' . ( $live['recommended'] ? esc_html__( 'Yes', 'lnkup-marketplace' ) : esc_html__( 'No', 'lnkup-marketplace' ) . ' — ' . esc_html( implode( ', ', $live['suppressed'] ) ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Score / confidence', 'lnkup-marketplace' ) . '</th><td>' . esc_html( $live['score'] . ' / ' . $live['confidence'] . '%' ) . ' (' . esc_html__( 'minimum', 'lnkup-marketplace' ) . ' ' . (int) $result['min_score'] . ')</td></tr>';
		foreach ( $live['factors'] as $key => $factor ) {
			$score = null === $factor['score'] ? __( 'no data (dropped)', 'lnkup-marketplace' ) : (string) $factor['score'];
			echo '<tr><th>' . esc_html( ucfirst( $key ) ) . ' (' . (int) $factor['weight'] . ')</th><td>' . esc_html( $score . ' · ' . $factor['kind'] ) . '</td></tr>';
		}
		echo '<tr><th>' . esc_html__( 'Reasons shown', 'lnkup-marketplace' ) . '</th><td>' . esc_html( implode( ' · ', wp_list_pluck( $live['reasons'], 'text' ) ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Cached result', 'lnkup-marketplace' ) . '</th><td>' . esc_html( $result['cached'] ? wp_json_encode( $result['cached'] ) : __( 'none', 'lnkup-marketplace' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Vendor', 'lnkup-marketplace' ) . '</th><td><code>' . esc_html( wp_json_encode( $result['vendor_summary'] ) ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( 'Opportunity', 'lnkup-marketplace' ) . '</th><td><code>' . esc_html( wp_json_encode( $result['opportunity_summary'] ) ) . '</code></td></tr>';
		echo '</tbody></table>';
	}

	private static function render_health() {
		$health = LNKup_Match_Cron::health();
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		echo '<tr><th>' . esc_html__( 'Recompute queue size', 'lnkup-marketplace' ) . '</th><td>' . (int) $health['queue_size'] . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Next queue run', 'lnkup-marketplace' ) . '</th><td>' . esc_html( $health['next_queue_run'] ? wp_date( 'Y-m-d H:i:s', $health['next_queue_run'] ) : '—' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Next nightly rebuild', 'lnkup-marketplace' ) . '</th><td>' . esc_html( $health['next_daily_run'] ? wp_date( 'Y-m-d H:i:s', $health['next_daily_run'] ) : '—' ) . '</td></tr>';
		foreach ( $health['last'] as $job => $data ) {
			echo '<tr><th>' . esc_html( sprintf( __( 'Last %s run', 'lnkup-marketplace' ), $job ) ) . '</th><td><code>' . esc_html( wp_json_encode( $data ) ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px">';
		wp_nonce_field( 'lnkup_match_rebuild' );
		echo '<input type="hidden" name="action" value="lnkup_match_rebuild" />';
		submit_button( __( 'Rebuild vendor index and all matches', 'lnkup-marketplace' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * Staged rollout: matching health, one row per flag with audience, stage gates and history.
	 */
	private static function render_rollout() {
		$health = LNKup_Match_Cron::health();
		$issues = LNKup_Match_Cron::health_issues();
		echo '<h2>' . esc_html__( 'Matching health', 'lnkup-marketplace' ) . '</h2><table class="widefat striped" style="max-width:720px"><tbody>';
		$backfill = $health['backfill'];
		$rows     = array(
			__( 'Vendor index backfill', 'lnkup-marketplace' )     => $backfill['running'] ? sprintf( __( 'Running: page %1$d of %2$d (%3$s%%)', 'lnkup-marketplace' ), $backfill['page'], $backfill['total_pages'], (string) ( $backfill['percent'] ?? 0 ) ) : ( $backfill['complete'] ? __( 'Complete', 'lnkup-marketplace' ) : __( 'Not run yet', 'lnkup-marketplace' ) ),
			__( 'Recompute queue', 'lnkup-marketplace' )           => sprintf( __( '%1$d items, oldest %2$s', 'lnkup-marketplace' ), $health['queue_size'], null === $health['queue_oldest_minutes'] ? '—' : sprintf( __( '%d min', 'lnkup-marketplace' ), $health['queue_oldest_minutes'] ) ),
			__( 'Consecutive failed runs', 'lnkup-marketplace' )   => (string) $health['consecutive_error_runs'],
			__( 'Nightly rebuild scheduled', 'lnkup-marketplace' ) => $health['next_daily_run'] ? wp_date( 'Y-m-d H:i', $health['next_daily_run'] ) : __( 'No', 'lnkup-marketplace' ),
		);
		foreach ( $rows as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $issues ) {
			echo '<div class="notice notice-warning inline"><ul>';
			foreach ( $issues as $issue ) {
				echo '<li>' . esc_html( $issue['detail'] ) . '</li>';
			}
			echo '</ul></div>';
		}

		echo '<h2>' . esc_html__( 'Rollout', 'lnkup-marketplace' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The on/off switch still controls each feature (kill switch). The audience narrows who sees it while it ramps: internal (allowlist + admins), 5%, 25%, 50%, everyone. Buckets are stable per user, so the same vendors stay in as the percentage grows.', 'lnkup-marketplace' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Feature', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'On', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Audience', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Since', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Change', 'lnkup-marketplace' ) . '</th></tr></thead><tbody>';
		$stage_labels = array(
			'internal' => __( 'Internal (allowlist + admins)', 'lnkup-marketplace' ),
			'p5'       => '5%',
			'p25'      => '25%',
			'p50'      => '50%',
			'all'      => __( 'Everyone', 'lnkup-marketplace' ),
		);
		foreach ( LNKup_Rollout::FLAGS as $flag => $meta ) {
			$config = LNKup_Rollout::config( $flag );
			$next   = LNKup_Rollout::next_stage( $config['stage'] ) ?: $config['stage'];
			$audience = 'all' === $config['audience'] ? __( 'Everyone', 'lnkup-marketplace' ) : sprintf( __( '%1$s%% + %2$d allowlisted', 'lnkup-marketplace' ), $config['percent'], count( $config['user_ids'] ) );
			echo '<tr data-testid="lnkup-rollout-row" data-flag="' . esc_attr( $flag ) . '"><td><strong>' . esc_html( $meta['label'] ) . '</strong><br /><code>' . esc_html( $flag ) . '</code></td>';
			echo '<td>' . ( LNKup_Rollout::active( $flag ) ? esc_html__( 'On', 'lnkup-marketplace' ) : esc_html__( 'Off', 'lnkup-marketplace' ) ) . '</td>';
			echo '<td>' . esc_html( $audience ) . '</td><td>' . esc_html( $config['stage_since'] ?: '—' ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'lnkup_rollout_save' );
			echo '<input type="hidden" name="action" value="lnkup_rollout_save" /><input type="hidden" name="flag" value="' . esc_attr( $flag ) . '" />';
			echo '<select name="stage">';
			foreach ( $stage_labels as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '" ' . selected( $next, $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select> <input type="text" name="user_ids" value="' . esc_attr( implode( ',', $config['user_ids'] ) ) . '" placeholder="' . esc_attr__( 'Allowlist user IDs', 'lnkup-marketplace' ) . '" style="width:140px" />';
			echo ' <input type="text" name="note" placeholder="' . esc_attr__( 'Note', 'lnkup-marketplace' ) . '" style="width:160px" />';
			echo ' <label><input type="checkbox" name="override" value="1" /> ' . esc_html__( 'Override failing checks', 'lnkup-marketplace' ) . '</label> ';
			echo '<button type="submit" class="button">' . esc_html__( 'Apply', 'lnkup-marketplace' ) . '</button></form>';
			echo '<ul style="margin:6px 0 0">';
			foreach ( LNKup_Rollout::gates( $flag, $next ) as $gate ) {
				echo '<li>' . ( $gate['ok'] ? '&#10003; ' : '&#10007; ' ) . esc_html( $gate['label'] ) . ( $gate['ok'] || '' === $gate['detail'] ? '' : ' — ' . esc_html( $gate['detail'] ) ) . '</li>';
			}
			echo '</ul></td></tr>';
		}
		echo '</tbody></table>';

		$history = array_slice( LNKup_Rollout::history(), 0, 20 );
		if ( $history ) {
			echo '<h2>' . esc_html__( 'Rollout history', 'lnkup-marketplace' ) . '</h2><table class="widefat striped" style="max-width:960px"><thead><tr><th>' . esc_html__( 'When', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Who', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Feature', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Change', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Note', 'lnkup-marketplace' ) . '</th></tr></thead><tbody>';
			foreach ( $history as $row ) {
				$user   = get_userdata( (int) $row['user_id'] );
				$change = sprintf( '%s %d%% → %s %d%%', $row['before']['audience'], (int) $row['before']['percent'], $row['after']['audience'], (int) $row['after']['percent'] );
				echo '<tr><td>' . esc_html( $row['at'] ) . '</td><td>' . esc_html( $user ? $user->display_name : 'wp-cli' ) . '</td><td><code>' . esc_html( $row['flag'] ) . '</code></td><td>' . esc_html( $change ) . '</td><td>' . esc_html( (string) $row['note'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	public static function handle_rollout_save() {
		self::require_admin();
		check_admin_referer( 'lnkup_rollout_save' );
		$flag   = isset( $_POST['flag'] ) ? sanitize_key( wp_unslash( $_POST['flag'] ) ) : '';
		$stage  = isset( $_POST['stage'] ) ? sanitize_key( wp_unslash( $_POST['stage'] ) ) : '';
		$users  = isset( $_POST['user_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['user_ids'] ) ) : '';
		$note   = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$config = LNKup_Rollout::config( $flag );
		$result = true;
		$ids    = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $users ) ) ) ) );
		if ( $ids !== $config['user_ids'] ) {
			$result = LNKup_Rollout::set( $flag, array( 'user_ids' => $ids ), $note ?: 'Allowlist updated' );
		}
		if ( ! is_wp_error( $result ) && $stage && $stage !== $config['stage'] ) {
			$result = LNKup_Rollout::advance( $flag, $stage, $note, ! empty( $_POST['override'] ) );
		}
		$args = array( 'page' => self::SLUG, 'tab' => 'rollout' );
		if ( is_wp_error( $result ) ) {
			set_transient( self::error_key(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
		} else {
			$args['updated'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Supply & Demand: match liquidity per city (with the Organizer Growth supply signal) and city detail.
	 */
	private static function render_supply() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$city_key = isset( $_GET['city'] ) ? sanitize_text_field( wp_unslash( $_GET['city'] ) ) : '';
		$filters  = array(
			'state'   => isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '',
			'term_id' => isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0,
			'signal'  => isset( $_GET['signal'] ) ? sanitize_key( wp_unslash( $_GET['signal'] ) ) : '',
			'sort'    => isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'idle_vendors',
		);
		// phpcs:enable
		echo '<p class="description">' . esc_html__( 'Whether vendors actually match open booths, city by city: booths with too few recommended vendors, matching vendors with nothing to apply to, and searches that found nothing. Counts only; signals are threshold rules. Organizer Growth\'s supply signal is shown alongside when its snapshot exists.', 'lnkup-marketplace' ) . '</p>';
		if ( '' !== $city_key ) {
			self::render_supply_city( $city_key );
			return;
		}
		$data = LNKup_Match_Market::cities( array_merge( $filters, array( 'limit' => 200 ) ) );

		echo '<div style="display:flex;gap:8px;align-items:center;margin:8px 0">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lnkup_match_market_rebuild' );
		echo '<input type="hidden" name="action" value="lnkup_match_market_rebuild" />';
		submit_button( __( 'Rebuild now', 'lnkup-marketplace' ), 'secondary', '', false );
		echo '</form>';
		if ( $data['items'] ) {
			$export = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'lnkup_match_market_export' ), array_filter( $filters ) ), admin_url( 'admin-post.php' ) ), 'lnkup_match_market_export' );
			echo '<a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Export CSV', 'lnkup-marketplace' ) . '</a>';
		}
		echo '</div>';

		if ( '' === $data['snapshot_date'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No market snapshot yet. It builds nightly once matching is on, or use "Rebuild now".', 'lnkup-marketplace' ) . '</p></div>';
			return;
		}

		echo '<form method="get" style="margin:8px 0"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input type="hidden" name="tab" value="supply" />';
		echo '<label>' . esc_html__( 'State', 'lnkup-marketplace' ) . ' <input type="text" name="state" value="' . esc_attr( $filters['state'] ) . '" size="3" /></label> ';
		echo '<label>' . esc_html__( 'Category', 'lnkup-marketplace' ) . ' <select name="term_id"><option value="0">' . esc_html__( 'All categories', 'lnkup-marketplace' ) . '</option>';
		foreach ( $data['categories'] as $id => $name ) {
			echo '<option value="' . (int) $id . '" ' . selected( $filters['term_id'], (int) $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></label> <label>' . esc_html__( 'Signal', 'lnkup-marketplace' ) . ' <select name="signal"><option value="">' . esc_html__( 'Any', 'lnkup-marketplace' ) . '</option>';
		foreach ( LNKup_Match_Market::SIGNALS as $signal ) {
			echo '<option value="' . esc_attr( $signal ) . '" ' . selected( $filters['signal'], $signal, false ) . '>' . esc_html( LNKup_Match_Market::signal_label( $signal ) ) . '</option>';
		}
		echo '</select></label> <label>' . esc_html__( 'Sort by', 'lnkup-marketplace' ) . ' <select name="sort">';
		$sorts = array(
			'idle_vendors'             => __( 'Idle matching vendors', 'lnkup-marketplace' ),
			'opps_thin'                => __( 'Thin booths', 'lnkup-marketplace' ),
			'zero_result_searches_30d' => __( 'Zero-result searches', 'lnkup-marketplace' ),
			'matched_vendors'          => __( 'Matched vendors', 'lnkup-marketplace' ),
			'open_opportunities'       => __( 'Open booths', 'lnkup-marketplace' ),
			'delta_idle'               => __( 'Change in idle vendors (7 days)', 'lnkup-marketplace' ),
		);
		foreach ( $sorts as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $filters['sort'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label> ';
		submit_button( __( 'Filter', 'lnkup-marketplace' ), 'secondary', '', false );
		echo '</form>';

		echo '<p>' . esc_html( sprintf( __( 'Snapshot %1$s%2$s. %3$d cities.', 'lnkup-marketplace' ), $data['snapshot_date'], $data['compared_to'] ? ' ' . sprintf( __( '(changes vs. %s)', 'lnkup-marketplace' ), $data['compared_to'] ) : '', $data['total'] ) ) . '</p>';
		if ( ! $data['items'] ) {
			echo '<p class="description">' . esc_html__( 'No cities match these filters.', 'lnkup-marketplace' ) . '</p>';
			return;
		}
		$heads = array( __( 'City', 'lnkup-marketplace' ), __( 'Match signal', 'lnkup-marketplace' ), __( 'Organizer Growth signal', 'lnkup-marketplace' ), __( 'Open booths', 'lnkup-marketplace' ), __( 'Thin booths', 'lnkup-marketplace' ), __( 'No match', 'lnkup-marketplace' ), __( 'Matched vendors', 'lnkup-marketplace' ), __( 'Idle vendors', 'lnkup-marketplace' ), __( 'Apps from recs (30d)', 'lnkup-marketplace' ), __( 'Searches (30d)', 'lnkup-marketplace' ), __( 'Zero results (30d)', 'lnkup-marketplace' ) );
		echo '<table class="widefat striped" data-testid="lnkup-market-table"><thead><tr>';
		foreach ( $heads as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $data['items'] as $row ) {
			$url = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'supply', 'city' => $row['city_key'] ), admin_url( 'admin.php' ) );
			echo '<tr><td><a href="' . esc_url( $url ) . '">' . esc_html( $row['label'] ?: $row['city_key'] ) . '</a></td>';
			echo '<td>' . esc_html( $row['signal_label'] ) . '</td><td>' . esc_html( $row['org_signal'] ? self::org_signal_label( $row['org_signal'] ) : '—' ) . '</td>';
			echo '<td>' . (int) $row['open_opportunities'] . '</td>';
			echo '<td>' . (int) $row['opps_thin'] . self::delta( $row['delta_thin'] ) . '</td>';
			echo '<td>' . (int) $row['opps_unmatched'] . '</td>';
			echo '<td>' . (int) $row['matched_vendors'] . '</td>';
			echo '<td>' . (int) $row['idle_vendors'] . self::delta( $row['delta_idle'] ) . '</td>';
			echo '<td>' . (int) $row['applications_30d_from_recs'] . '</td><td>' . (int) $row['searches_30d'] . '</td><td>' . (int) $row['zero_result_searches_30d'] . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * City detail: categories, thin booths, trend, link to Organizer Growth leads.
	 *
	 * @param string $city_key City key.
	 */
	private static function render_supply_city( $city_key ) {
		$back = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'supply' ), admin_url( 'admin.php' ) );
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'All cities', 'lnkup-marketplace' ) . '</a></p>';
		$data = LNKup_Match_Market::city( $city_key );
		if ( is_wp_error( $data ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $data->get_error_message() ) . '</p></div>';
			return;
		}
		$s = $data['summary'];
		echo '<h2 data-testid="lnkup-market-city">' . esc_html( $s['label'] ) . '</h2>';
		echo '<p><strong>' . esc_html( $s['signal_label'] ) . '</strong>' . ( $data['org_signal'] ? ' · ' . esc_html__( 'Organizer Growth:', 'lnkup-marketplace' ) . ' ' . esc_html( self::org_signal_label( $data['org_signal'] ) ) : '' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		$rows = array(
			__( 'Open booths', 'lnkup-marketplace' )                                => $s['open_opportunities'],
			__( 'Booths with too few recommended vendors', 'lnkup-marketplace' )   => $s['opps_thin'],
			__( 'Booths with no recommended vendor', 'lnkup-marketplace' )         => $s['opps_unmatched'],
			__( 'Vendors with a recommended booth here', 'lnkup-marketplace' )     => $s['matched_vendors'],
			__( 'Vendors here with nothing recommended', 'lnkup-marketplace' )     => $s['idle_vendors'],
			__( 'Applications after a recommendation (30 days)', 'lnkup-marketplace' ) => $s['applications_30d_from_recs'],
			__( 'Searches naming this city (30 days)', 'lnkup-marketplace' )        => $s['searches_30d'],
			__( 'Searches that found nothing (30 days)', 'lnkup-marketplace' )      => $s['zero_result_searches_30d'],
		);
		foreach ( $rows as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . (int) $value . '</td></tr>';
		}
		echo '</tbody></table>';

		$leads_url = add_query_arg( array( 'page' => 'lnkup-organizer-growth', 'og_city' => $s['city'], 'og_state' => $s['state'] ), admin_url( 'admin.php' ) ) . '#leads';
		echo '<p>' . esc_html( sprintf( _n( '%d open organizer lead in this city.', '%d open organizer leads in this city.', (int) $data['open_leads'], 'lnkup-marketplace' ), (int) $data['open_leads'] ) ) . ' <a href="' . esc_url( $leads_url ) . '">' . esc_html__( 'Open in Organizer Growth', 'lnkup-marketplace' ) . '</a></p>';

		if ( $data['thin_booths'] ) {
			echo '<h3>' . esc_html__( 'Booths that need more matching vendors', 'lnkup-marketplace' ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Event', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Booth', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Date', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Organizer', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Spots left', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Recommended vendors', 'lnkup-marketplace' ) . '</th></tr></thead><tbody>';
			foreach ( $data['thin_booths'] as $booth ) {
				$event = $booth['edit_url'] ? '<a href="' . esc_url( $booth['edit_url'] ) . '">' . esc_html( $booth['event'] ) . '</a>' : esc_html( $booth['event'] );
				echo '<tr><td>' . $event . '</td><td>' . esc_html( $booth['title'] ) . '</td><td>' . esc_html( $booth['event_date'] ) . '</td><td>' . esc_html( $booth['organizer'] ) . '</td><td>' . (int) $booth['spots_remaining'] . '</td><td>' . (int) $booth['recommended'] . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $event is escaped above.
			}
			echo '</tbody></table>';
		}
		if ( $data['categories'] ) {
			echo '<h3>' . esc_html__( 'By category', 'lnkup-marketplace' ) . '</h3><table class="widefat striped" style="max-width:960px"><thead><tr><th>' . esc_html__( 'Category', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Signal', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Open booths', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Thin', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Matched vendors', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Idle vendors', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Zero-result searches', 'lnkup-marketplace' ) . '</th></tr></thead><tbody>';
			foreach ( $data['categories'] as $c ) {
				echo '<tr><td>' . esc_html( $c['category'] ) . '</td><td>' . esc_html( $c['signal_label'] ) . '</td><td>' . (int) $c['open_opportunities'] . '</td><td>' . (int) $c['opps_thin'] . '</td><td>' . (int) $c['matched_vendors'] . '</td><td>' . (int) $c['idle_vendors'] . '</td><td>' . (int) $c['zero_result_searches_30d'] . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( count( $data['trend'] ) > 1 ) {
			echo '<h3>' . esc_html__( 'Last 90 days', 'lnkup-marketplace' ) . '</h3><table class="widefat striped" style="max-width:720px"><thead><tr><th>' . esc_html__( 'Date', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Open booths', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Thin', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Matched', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Idle', 'lnkup-marketplace' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( array_reverse( $data['trend'] ), 0, 15 ) as $point ) {
				echo '<tr><td>' . esc_html( $point['date'] ) . '</td><td>' . (int) $point['open_opportunities'] . '</td><td>' . (int) $point['opps_thin'] . '</td><td>' . (int) $point['matched_vendors'] . '</td><td>' . (int) $point['idle_vendors'] . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	private static function delta( $value ) {
		if ( null === $value || 0 === (int) $value ) {
			return '';
		}
		return ' <span class="description">(' . ( $value > 0 ? '+' : '' ) . (int) $value . ')</span>';
	}

	private static function org_signal_label( $signal ) {
		$labels = array(
			'recruit_organizers' => __( 'Recruit organizers', 'lnkup-marketplace' ),
			'recruit_vendors'    => __( 'Recruit vendors', 'lnkup-marketplace' ),
			'balanced'           => __( 'Balanced', 'lnkup-marketplace' ),
			'insufficient_data'  => __( 'Not enough data', 'lnkup-marketplace' ),
		);
		return $labels[ $signal ] ?? (string) $signal;
	}

	public static function handle_market_export() {
		self::require_admin();
		check_admin_referer( 'lnkup_match_market_export' );
		$filters = array();
		foreach ( array( 'state', 'term_id', 'signal', 'sort' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				$filters[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=lnkup-market-supply-demand-' . current_time( 'Y-m-d' ) . '.csv' );
		echo LNKup_Match_Market::export_csv( $filters ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, formula-safe.
		exit;
	}

	public static function handle_market_rebuild() {
		self::require_admin();
		check_admin_referer( 'lnkup_match_market_rebuild' );
		$result = LNKup_Match_Market::rest_rebuild();
		$args   = array( 'page' => self::SLUG, 'tab' => 'supply' );
		if ( is_wp_error( $result ) ) {
			set_transient( self::error_key(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
		} else {
			$args['updated'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Matching settings from the Matching Rules form.
	 *
	 * Blank number boxes are left out so they keep their current value: read as 0 they would
	 * look like a ranking change (blocking the save behind the note) and drop invite caps to 1.
	 *
	 * @param array $post Unslashed form fields.
	 * @return array
	 */
	public static function input_from_post( array $post ) {
		$input = array();
		foreach ( LNKup_Match_Settings::keys() as $key ) {
			if ( in_array( $key, LNKup_Match_Settings::FLAGS, true ) ) {
				$input[ $key ] = isset( $post[ $key ] ) ? 1 : 0; // Unchecked boxes are not posted.
				continue;
			}
			$value = isset( $post[ $key ] ) ? trim( sanitize_text_field( (string) $post[ $key ] ) ) : '';
			if ( '' !== $value ) {
				$input[ $key ] = $value;
			}
		}
		return $input;
	}

	public static function handle_save_settings() {
		self::require_admin();
		check_admin_referer( 'lnkup_match_save_settings' );
		$input  = self::input_from_post( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field.
		$note   = isset( $_POST['change_note'] ) ? sanitize_text_field( wp_unslash( $_POST['change_note'] ) ) : '';
		$intent = isset( $_POST['intent'] ) ? sanitize_key( wp_unslash( $_POST['intent'] ) ) : 'save';
		$base   = array( 'page' => self::SLUG, 'tab' => 'rules' );

		if ( 'preview' === $intent ) {
			$candidate = array_intersect_key( $input, array_flip( LNKup_Match_Settings::rule_keys() ) );
			set_transient(
				self::preview_key(),
				array(
					'candidate' => $candidate,
					'note'      => $note,
					'result'    => LNKup_Match_Simulator::preview( $candidate ),
				),
				HOUR_IN_SECONDS
			);
			wp_safe_redirect( add_query_arg( array_merge( $base, array( 'preview' => 1 ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$result = LNKup_Match_Settings::save( $input, $note );
		if ( is_wp_error( $result ) ) {
			set_transient( self::error_key(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( $base, admin_url( 'admin.php' ) ) );
			exit;
		}
		delete_transient( self::preview_key() );
		wp_safe_redirect( add_query_arg( array_merge( $base, array( 'updated' => 1 ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_revert() {
		self::require_admin();
		check_admin_referer( 'lnkup_match_revert' );
		$version = isset( $_POST['version'] ) ? absint( $_POST['version'] ) : 0;
		$note    = isset( $_POST['change_note'] ) ? sanitize_text_field( wp_unslash( $_POST['change_note'] ) ) : '';
		$result  = LNKup_Match_Settings::revert( $version, $note );
		$args    = array( 'page' => self::SLUG, 'tab' => 'rules' );
		if ( is_wp_error( $result ) ) {
			set_transient( self::error_key(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
		} else {
			$args['updated'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function preview_key() {
		return 'lnkup_match_preview_' . get_current_user_id();
	}

	private static function error_key() {
		return 'lnkup_match_admin_error_' . get_current_user_id();
	}

	public static function handle_save_groups() {
		self::require_admin();
		check_admin_referer( 'lnkup_match_save_groups' );
		$groups = isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? wp_unslash( $_POST['groups'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( $groups as $term_id => $group ) {
			$term_id = absint( $term_id );
			$group   = sanitize_key( (string) $group );
			if ( $term_id && LNKup_Match_Categories::group_for_term( $term_id ) !== $group ) {
				LNKup_Match_Categories::set_group( $term_id, $group );
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'categories', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_rebuild() {
		self::require_admin();
		check_admin_referer( 'lnkup_match_rebuild' );
		LNKup_Match_Cron::queue_full_rebuild();
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'health', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
