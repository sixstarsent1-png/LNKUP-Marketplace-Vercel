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
			$flags[ $flag ] = LNKup_Match_Settings::enabled( $flag );
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
		self::render_alert_report();
		echo '<p class="description">' . esc_html__( 'Matching is rules-based and explainable. Eligibility is checked first; relevance never overrides a failed eligibility rule. Popularity, organizer size and payment volume are not ranking factors.', 'lnkup-marketplace' ) . '</p>';
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
		foreach ( $numbers as $key => $label ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td><input type="number" min="0" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) ( $settings[ $key ] ?? '' ) ) . '" class="small-text" /></td></tr>';
		}
		echo '</table>';
		submit_button( __( 'Save matching settings', 'lnkup-marketplace' ) );
		echo '</form>';

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
				echo '<tr><td>' . esc_html( $entry['at'] ) . '</td><td>' . esc_html( $user ? $user->display_name : '#' . (int) $entry['user_id'] ) . '</td><td>' . esc_html( implode( '; ', $changed ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
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

	private static function render_supply() {
		echo '<p>' . esc_html__( 'Geographic supply & demand reporting (vendor supply, organizer demand, liquidity, unmet demand, growth opportunities) is planned for Phase 7 and will build on the vendor index and match tables shown on the other tabs.', 'lnkup-marketplace' ) . '</p>';
	}

	public static function handle_save_settings() {
		self::require_admin();
		check_admin_referer( 'lnkup_match_save_settings' );
		$input = array();
		foreach ( LNKup_Match_Settings::keys() as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$input[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
		LNKup_Match_Settings::save( $input );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'rules', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
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
