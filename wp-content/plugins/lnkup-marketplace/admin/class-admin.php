<?php
/**
 * Admin settings and overview.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Admin {

	/**
	 * Init admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_rebuild_index' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_activation_error_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_deferred_upgrade_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_stripe_test_mode_notice' ) );
	}

	/**
	 * Warn when test_mode is on with live Stripe keys (unsigned webhook bypass is already blocked when secret is set).
	 */
	public static function render_stripe_test_mode_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		if ( empty( $settings['test_mode'] ) ) {
			return;
		}
		$secret_key = (string) ( $settings['stripe_secret_key'] ?? '' );
		$is_live    = 0 === strpos( $secret_key, 'sk_live_' ) || 0 === strpos( $secret_key, 'rk_live_' );
		if ( ! $is_live ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'LNKup: Test Mode is on with live Stripe keys.', 'lnkup-marketplace' ); ?></strong>
				<?php esc_html_e( 'Turn off Test Mode for production. Webhook signatures are still required whenever a signing secret is saved.', 'lnkup-marketplace' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Display activation error notice if present.
	 */
	public static function render_activation_error_notice() {
		$error = get_transient( 'lnkup_activation_error' );
		if ( ! $error ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'LNKup Marketplace activation issue:', 'lnkup-marketplace' ); ?></strong>
				<?php echo esc_html( $error ); ?>
			</p>
			<p><?php esc_html_e( 'Core features may still work. Try deactivating and re-activating the plugin. Check wp-content/debug.log for details.', 'lnkup-marketplace' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Prompt admin to open wp-admin once so deferred upgrade can finish.
	 */
	public static function render_deferred_upgrade_notice() {
		if ( ! get_option( 'lnkup_needs_deferred_upgrade' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			return;
		}
		?>
		<div class="notice notice-info">
			<p>
				<?php esc_html_e( 'LNKup Marketplace is finishing a background upgrade (search index and data sync). This page will complete it automatically.', 'lnkup-marketplace' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Add admin menu.
	 */
	public static function add_menu() {
		add_menu_page(
			__( 'LNKup', 'lnkup-marketplace' ),
			__( 'LNKup', 'lnkup-marketplace' ),
			'lnkup_manage_all',
			'lnkup-marketplace',
			array( __CLASS__, 'render_overview' ),
			'dashicons-networking',
			30
		);

		add_submenu_page(
			'lnkup-marketplace',
			__( 'Settings', 'lnkup-marketplace' ),
			__( 'Settings', 'lnkup-marketplace' ),
			'lnkup_manage_settings',
			'lnkup-settings',
			array( __CLASS__, 'render_settings' )
		);

		add_submenu_page(
			'lnkup-marketplace',
			__( 'Marketplace Analytics', 'lnkup-marketplace' ),
			__( 'Analytics', 'lnkup-marketplace' ),
			'lnkup_manage_settings',
			'lnkup-analytics',
			array( __CLASS__, 'render_analytics' )
		);
	}

	/**
	 * Handle index rebuild.
	 */
	public static function maybe_rebuild_index() {
		if ( ! isset( $_GET['lnkup_rebuild_index'] ) || ! current_user_can( 'lnkup_manage_settings' ) ) {
			return;
		}
		check_admin_referer( 'lnkup_rebuild_index' );
		$count = LNKup_Marketplace_Index::rebuild_all();
		wp_safe_redirect( add_query_arg( array( 'lnkup_rebuilt' => $count ), admin_url( 'admin.php?page=lnkup-settings' ) ) );
		exit;
	}

	/**
	 * Render marketplace analytics.
	 */
	public static function render_analytics() {
		if ( ! LNKup_Capabilities::user_has_manage_all() && ! current_user_can( 'lnkup_manage_settings' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$m = LNKup_Marketplace_Analytics::get_dashboard_metrics();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Marketplace Analytics', 'lnkup-marketplace' ); ?></h1>
			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;margin:1.5rem 0;">
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) $m['searches_30d'] ); ?></strong><br><?php esc_html_e( 'Searches (30d)', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) $m['card_clicks_30d'] ); ?></strong><br><?php esc_html_e( 'Card Clicks', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) $m['favorites_30d'] ); ?></strong><br><?php esc_html_e( 'Favorites', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) $m['recommendation_ctr'] ); ?>%</strong><br><?php esc_html_e( 'Recommendation CTR', 'lnkup-marketplace' ); ?></div>
			</div>
			<h2><?php esc_html_e( 'Top Cities', 'lnkup-marketplace' ); ?></h2>
			<ul><?php foreach ( $m['top_cities'] as $city => $cnt ) : ?><li><?php echo esc_html( $city . ' (' . $cnt . ')' ); ?></li><?php endforeach; ?></ul>
			<h2><?php esc_html_e( 'Popular Categories', 'lnkup-marketplace' ); ?></h2>
			<p><?php echo esc_html( implode( ', ', $m['popular_categories'] ) ); ?></p>
			<h2><?php esc_html_e( 'Popular Searches', 'lnkup-marketplace' ); ?></h2>
			<ul>
				<?php foreach ( (array) $m['popular_searches'] as $term ) : ?>
					<li><?php echo esc_html( $term ); ?></li>
				<?php endforeach; ?>
			</ul>
			<h2><?php esc_html_e( 'Zero-Result Searches', 'lnkup-marketplace' ); ?></h2>
			<ul>
				<?php foreach ( (array) $m['zero_result_searches'] as $term => $cnt ) : ?>
					<li><?php echo esc_html( $term . ' (' . $cnt . ')' ); ?></li>
				<?php endforeach; ?>
			</ul>
			<h2><?php esc_html_e( 'Filter Usage (30d)', 'lnkup-marketplace' ); ?></h2>
			<ul>
				<?php foreach ( (array) $m['filter_usage'] as $filter => $cnt ) : ?>
					<li><?php echo esc_html( $filter . ': ' . $cnt ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Register settings.
	 */
	public static function register_settings() {
		register_setting(
			'lnkup_settings_group',
			'lnkup_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Input.
	 */
	public static function sanitize_settings( $input ) {
		$output = lnkup_get_settings();
		$fee_keys_changed = false;
		$output['stripe_public_key']   = isset( $input['stripe_public_key'] ) ? sanitize_text_field( $input['stripe_public_key'] ) : '';
		$output['stripe_secret_key']   = isset( $input['stripe_secret_key'] ) ? sanitize_text_field( $input['stripe_secret_key'] ) : '';
		if ( isset( $input['stripe_webhook_secret'] ) ) {
			$webhook_secret = sanitize_text_field( $input['stripe_webhook_secret'] );
			if ( '' !== $webhook_secret ) {
				$output['stripe_webhook_secret'] = $webhook_secret;
			}
		}
		if ( isset( $input['stripe_connect_webhook_secret'] ) ) {
			$connect_webhook_secret = sanitize_text_field( $input['stripe_connect_webhook_secret'] );
			if ( '' !== $connect_webhook_secret ) {
				$output['stripe_connect_webhook_secret'] = $connect_webhook_secret;
			}
		}
		$output['test_mode']           = ! empty( $input['test_mode'] );
		$output['apply_wizard_enabled']     = ! empty( $input['apply_wizard_enabled'] );
		$output['tour_multi_apply_enabled'] = ! empty( $input['tour_multi_apply_enabled'] );
		$output['portfolio_os_enabled']     = ! empty( $input['portfolio_os_enabled'] );
		if ( isset( $input['portfolio_os_user_ids'] ) ) {
			$output['portfolio_os_user_ids'] = implode( ',', array_filter( array_map( 'absint', explode( ',', (string) $input['portfolio_os_user_ids'] ) ) ) );
		}
		$output['application_review_v2_enabled'] = ! empty( $input['application_review_v2_enabled'] );
		$output['waitlist_offers_enabled']       = ! empty( $input['waitlist_offers_enabled'] );
		$output['organizer_onboarding_v2_enabled'] = ! empty( $input['organizer_onboarding_v2_enabled'] );
		$output['default_packet_lock'] = ! empty( $input['default_packet_lock'] );
		$output['support_email']       = isset( $input['support_email'] ) ? sanitize_email( $input['support_email'] ) : $output['support_email'];
		$output['notify_vendor']       = ! empty( $input['notify_vendor'] );
		$output['notify_organizer']    = ! empty( $input['notify_organizer'] );
		$output['notify_admin']        = ! empty( $input['notify_admin'] );
		$output['activation_emails_enabled'] = ! empty( $input['activation_emails_enabled'] );
		if ( isset( $input['activation_daily_batch'] ) ) {
			$output['activation_daily_batch'] = max( 10, min( 500, (int) $input['activation_daily_batch'] ) );
		}
		if ( isset( $input['expo_push_access_token'] ) ) {
			$output['expo_push_access_token'] = sanitize_text_field( $input['expo_push_access_token'] );
		}
		if ( ! empty( $input['default_documents'] ) && is_array( $input['default_documents'] ) ) {
			$output['default_documents'] = array_map( 'sanitize_text_field', $input['default_documents'] );
		}
		if ( isset( $input['marketplace_phase'] ) ) {
			$phase = strtolower( sanitize_text_field( $input['marketplace_phase'] ) );
			$output['marketplace_phase'] = in_array( $phase, array( '1a', '1b', '1c' ), true ) ? $phase : '1a';
		}
		$output['stripe_connect_enabled']        = ! empty( $input['stripe_connect_enabled'] );
		$output['connect_express_enabled']       = ! empty( $input['connect_express_enabled'] );
		$output['connect_instant_payouts_enabled'] = ! empty( $input['connect_instant_payouts_enabled'] );
		$output['connect_manual_fallback']       = ! empty( $input['connect_manual_fallback'] );
		$output['ach_enabled']                   = ! empty( $input['ach_enabled'] );
		$output['stripe_billing_enabled']        = ! empty( $input['stripe_billing_enabled'] );
		$output['apple_pay_enabled']             = ! empty( $input['apple_pay_enabled'] );
		$output['google_pay_enabled']            = ! empty( $input['google_pay_enabled'] );
		if ( isset( $input['connect_platform_country'] ) ) {
			$output['connect_platform_country'] = strtoupper( sanitize_text_field( $input['connect_platform_country'] ) );
		}
		if ( isset( $input['connect_platform_currency'] ) ) {
			$output['connect_platform_currency'] = strtolower( sanitize_text_field( $input['connect_platform_currency'] ) );
		}
		if ( isset( $input['application_fee_type'] ) ) {
			$fee_type = sanitize_key( $input['application_fee_type'] );
			$output['application_fee_type'] = in_array( $fee_type, array( 'flat', 'percent', 'hybrid' ), true ) ? $fee_type : 'flat';
		}
		if ( isset( $input['application_fee_percent'] ) ) {
			$output['application_fee_percent'] = max( 0, (float) $input['application_fee_percent'] );
		}
		if ( isset( $input['application_fee_flat'] ) ) {
			$output['application_fee_flat'] = LNKup_Security::sanitize_money( $input['application_fee_flat'] );
		}
		if ( isset( $input['vendor_checkout_fee'] ) ) {
			$output['vendor_checkout_fee'] = LNKup_Security::sanitize_money( $input['vendor_checkout_fee'] );
			$output['application_fee_flat'] = $output['vendor_checkout_fee'];
			$output['default_fee']          = $output['vendor_checkout_fee'];
			$fee_keys_changed = true;
		}
		if ( isset( $input['organizer_commission_percent'] ) ) {
			$output['organizer_commission_percent'] = max( 0, min( 100, (float) $input['organizer_commission_percent'] ) );
			$fee_keys_changed = true;
		}
		$output['organizer_recruit_enabled'] = ! empty( $input['organizer_recruit_enabled'] );
		if ( isset( $input['organizer_recruit_percent'] ) ) {
			$output['organizer_recruit_percent'] = max( 0, min( 100, (float) $input['organizer_recruit_percent'] ) );
		}
		if ( isset( $input['organizer_recruit_months'] ) ) {
			$output['organizer_recruit_months'] = max( 1, min( 36, (int) $input['organizer_recruit_months'] ) );
		}
		if ( isset( $input['reserve_percent'] ) ) {
			$output['reserve_percent'] = max( 0, min( 100, (float) $input['reserve_percent'] ) );
		}
		if ( isset( $input['connect_payout_schedule'] ) ) {
			$sched = sanitize_key( $input['connect_payout_schedule'] );
			$output['connect_payout_schedule'] = in_array( $sched, array( 'daily', 'weekly', 'manual' ), true ) ? $sched : 'daily';
		}
		if ( isset( $input['connect_return_url'] ) ) {
			$output['connect_return_url'] = esc_url_raw( $input['connect_return_url'] );
		}
		if ( isset( $input['connect_refresh_url'] ) ) {
			$output['connect_refresh_url'] = esc_url_raw( $input['connect_refresh_url'] );
		}
		if ( isset( $input['fee_payer_mode'] ) ) {
			$payer = sanitize_key( $input['fee_payer_mode'] );
			$output['fee_payer_mode'] = in_array( $payer, array( 'vendor', 'organizer', 'split' ), true ) ? $payer : 'vendor';
		}
		if ( class_exists( 'LNKup_Fee_Engine' ) ) {
			$output = LNKup_Fee_Engine::sync_fee_setting_aliases( $output );
		}
		if ( $fee_keys_changed && class_exists( 'LNKup_Payments' ) ) {
			LNKup_Payments::refresh_all_pending_fees();
		}
		$output['weather_intelligence_enabled'] = ! empty( $input['weather_intelligence_enabled'] );
		if ( isset( $input['openweather_api_key'] ) ) {
			$key = sanitize_text_field( $input['openweather_api_key'] );
			if ( '' !== $key ) {
				$output['openweather_api_key'] = $key;
			}
		}
		if ( isset( $input['openweather_api_mode'] ) ) {
			$mode = sanitize_key( $input['openweather_api_mode'] );
			$output['openweather_api_mode'] = in_array( $mode, array( 'current_forecast' ), true ) ? $mode : 'current_forecast';
		}
		if ( isset( $input['weather_units'] ) ) {
			$units = sanitize_key( $input['weather_units'] );
			$output['weather_units'] = in_array( $units, array( 'imperial', 'metric', 'standard' ), true ) ? $units : 'imperial';
		}
		if ( isset( $input['weather_refresh_minutes'] ) ) {
			$output['weather_refresh_minutes'] = max( 5, min( 240, (int) $input['weather_refresh_minutes'] ) );
		}
		$output['weather_historical_enabled'] = ! empty( $input['weather_historical_enabled'] );
		$output['weather_vendor_packet_note'] = ! empty( $input['weather_vendor_packet_note'] );
		$output['outreach_rate_limit_enabled'] = ! empty( $input['outreach_rate_limit_enabled'] );
		if ( isset( $input['outreach_hourly_limit'] ) ) {
			$output['outreach_hourly_limit'] = max( 1, (int) $input['outreach_hourly_limit'] );
		}
		if ( isset( $input['outreach_daily_limit'] ) ) {
			$output['outreach_daily_limit'] = max( 1, (int) $input['outreach_daily_limit'] );
		}
		if ( isset( $input['outreach_batch_size'] ) ) {
			$output['outreach_batch_size'] = max( 1, (int) $input['outreach_batch_size'] );
		}
		if ( isset( $input['outreach_batch_interval_seconds'] ) ) {
			$output['outreach_batch_interval_seconds'] = max( 60, (int) $input['outreach_batch_interval_seconds'] );
		}
		if ( isset( $input['outreach_resume_time'] ) ) {
			$time = sanitize_text_field( $input['outreach_resume_time'] );
			if ( preg_match( '/^\d{1,2}:\d{2}$/', $time ) ) {
				$output['outreach_resume_time'] = $time;
			}
		}
		$output['saved_search_alerts_enabled'] = ! empty( $input['saved_search_alerts_enabled'] );
		if ( isset( $input['saved_search_limit_per_vendor'] ) ) {
			$output['saved_search_limit_per_vendor'] = max( 1, min( 25, (int) $input['saved_search_limit_per_vendor'] ) );
		}
		if ( isset( $input['saved_search_default_frequency'] ) ) {
			$freq = sanitize_key( $input['saved_search_default_frequency'] );
			$output['saved_search_default_frequency'] = in_array( $freq, array( 'daily', 'weekly', 'instant' ), true ) ? $freq : 'daily';
		}
		if ( isset( $input['saved_search_max_matches_per_email'] ) ) {
			$output['saved_search_max_matches_per_email'] = max( 1, min( 10, (int) $input['saved_search_max_matches_per_email'] ) );
		}
		$output['deadline_reminder_favorites'] = ! empty( $input['deadline_reminder_favorites'] );
		if ( isset( $input['crm_import_max_rows_organizer'] ) ) {
			$output['crm_import_max_rows_organizer'] = max( 50, min( 2000, (int) $input['crm_import_max_rows_organizer'] ) );
		}
		if ( isset( $input['crm_import_max_file_size_mb'] ) ) {
			$output['crm_import_max_file_size_mb'] = max( 1, min( 20, (float) $input['crm_import_max_file_size_mb'] ) );
		}
		$output['crm_import_allow_invite'] = ! empty( $input['crm_import_allow_invite'] );
		$output['market_pages_enabled'] = ! empty( $input['market_pages_enabled'] );
		$output['connector_rewards_enabled'] = ! empty( $input['connector_rewards_enabled'] );
		if ( isset( $input['connector_reward_mode'] ) ) {
			$mode = sanitize_key( $input['connector_reward_mode'] );
			$output['connector_reward_mode'] = in_array( $mode, array( 'manual', 'platform_credit', 'disabled' ), true ) ? $mode : 'manual';
		}
		if ( isset( $input['connector_vendor_signup_credit'] ) ) {
			$output['connector_vendor_signup_credit'] = max( 0, (float) $input['connector_vendor_signup_credit'] );
		}
		if ( isset( $input['connector_vendor_paid_booking_credit'] ) ) {
			$output['connector_vendor_paid_booking_credit'] = max( 0, (float) $input['connector_vendor_paid_booking_credit'] );
		}
		if ( isset( $input['connector_organizer_first_booking_credit'] ) ) {
			$output['connector_organizer_first_booking_credit'] = max( 0, (float) $input['connector_organizer_first_booking_credit'] );
		}
		if ( isset( $input['connector_organizer_10_booking_bonus'] ) ) {
			$output['connector_organizer_10_booking_bonus'] = max( 0, (float) $input['connector_organizer_10_booking_bonus'] );
		}
		if ( isset( $input['connector_booking_share_percent'] ) ) {
			$output['connector_booking_share_percent'] = max( 0, min( 100, (float) $input['connector_booking_share_percent'] ) );
		}
		if ( isset( $input['connector_booking_share_cap'] ) ) {
			$output['connector_booking_share_cap'] = max( 0, (float) $input['connector_booking_share_cap'] );
		}
		if ( isset( $input['connector_reward_term_months'] ) ) {
			$output['connector_reward_term_months'] = max( 1, min( 36, (int) $input['connector_reward_term_months'] ) );
		}
		if ( isset( $input['connector_payout_hold_days'] ) ) {
			$output['connector_payout_hold_days'] = max( 0, min( 180, (int) $input['connector_payout_hold_days'] ) );
		}
		if ( isset( $input['connector_minimum_payout'] ) ) {
			$output['connector_minimum_payout'] = max( 0, (float) $input['connector_minimum_payout'] );
		}
		$output['connector_vendor_referrals_rewarded'] = ! empty( $input['connector_vendor_referrals_rewarded'] );
		if ( isset( $input['connector_terms_url'] ) ) {
			$output['connector_terms_url'] = esc_url_raw( (string) $input['connector_terms_url'] );
		}
		if ( isset( $input['connector_support_url'] ) ) {
			$output['connector_support_url'] = esc_url_raw( (string) $input['connector_support_url'] );
		}
		$output['connector_ranks_enabled'] = ! empty( $input['connector_ranks_enabled'] );
		$output['connector_leaderboard_enabled'] = ! empty( $input['connector_leaderboard_enabled'] );
		$output['connector_rank_celebration_enabled'] = ! empty( $input['connector_rank_celebration_enabled'] );
		$output['connector_rank_bonus_requires_approval'] = ! empty( $input['connector_rank_bonus_requires_approval'] );
		if ( isset( $input['connector_founding_cutoff'] ) ) {
			$output['connector_founding_cutoff'] = sanitize_text_field( (string) $input['connector_founding_cutoff'] );
		}
		$rank_ints = array(
			'connector_rank_market_builder_activated',
			'connector_rank_market_builder_bookings',
			'connector_rank_city_captain_activated',
			'connector_rank_city_captain_bookings',
			'connector_rank_city_captain_recurring',
			'connector_rank_city_director_activated',
			'connector_rank_city_director_bookings',
			'connector_rank_city_director_recurring',
			'connector_rank_regional_partner_activated',
			'connector_rank_regional_partner_bookings',
			'connector_rank_regional_partner_recurring',
			'connector_rank_regional_partner_cities',
		);
		foreach ( $rank_ints as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$output[ $key ] = max( 0, (int) $input[ $key ] );
			}
		}
		$rank_floats = array(
			'connector_rank_market_builder_gmv',
			'connector_rank_market_builder_bonus',
			'connector_rank_city_captain_gmv',
			'connector_rank_city_captain_bonus',
			'connector_rank_city_director_gmv',
			'connector_rank_city_director_bonus',
			'connector_rank_regional_partner_gmv',
			'connector_rank_regional_partner_bonus',
		);
		foreach ( $rank_floats as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$output[ $key ] = max( 0, (float) $input[ $key ] );
			}
		}
		$output['platform_prelaunch_mode'] = ! empty( $input['platform_prelaunch_mode'] );
		if ( isset( $input['platform_launch_date'] ) ) {
			$launch_date = sanitize_text_field( $input['platform_launch_date'] );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $launch_date ) ) {
				$output['platform_launch_date'] = $launch_date;
			}
		}
		$output['platform_prelaunch_admin_preview'] = ! empty( $input['platform_prelaunch_admin_preview'] );
		if ( isset( $input['app_release_datetime'] ) ) {
			$app_dt = sanitize_text_field( (string) $input['app_release_datetime'] );
			$app_dt = str_replace( 'T', ' ', $app_dt );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $app_dt ) ) {
				$app_dt .= ':00';
			}
			if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $app_dt ) ) {
				$output['app_release_datetime'] = $app_dt;
			}
		}
		if ( isset( $input['app_release_tz'] ) ) {
			$tz = sanitize_text_field( (string) $input['app_release_tz'] );
			try {
				new DateTimeZone( $tz );
				$output['app_release_tz'] = $tz;
			} catch ( Exception $e ) {
				$output['app_release_tz'] = 'America/New_York';
			}
		}
		if ( isset( $input['app_store_url'] ) ) {
			$store_url  = esc_url_raw( trim( (string) $input['app_store_url'] ), array( 'https' ) );
			$store_host = $store_url ? wp_parse_url( $store_url, PHP_URL_HOST ) : '';
			if ( in_array( $store_host, array( 'apps.apple.com', 'itunes.apple.com' ), true ) ) {
				$output['app_store_url'] = $store_url;
			}
			// Checkbox lives beside the URL field, so only read it when that form section posts.
			$output['app_prompts_enabled'] = ! empty( $input['app_prompts_enabled'] );
		}
		LNKup_Marketplace_Cron::sync_with_phase();
		return $output;
	}

	/**
	 * Render admin overview.
	 */
	public static function render_overview() {
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}

		$vendors     = count_users()['avail_roles']['lnkup_vendor'] ?? 0;
		$organizers  = count_users()['avail_roles']['lnkup_organizer'] ?? 0;
		$events      = wp_count_posts( 'lnkup_event' )->publish ?? 0;
		$applications = wp_count_posts( 'lnkup_application' )->publish ?? 0;
		$payments    = get_posts( array( 'post_type' => 'lnkup_payment', 'posts_per_page' => -1, 'meta_key' => '_lnkup_payment_status', 'meta_value' => 'paid' ) );
		$gmv         = array_reduce(
			$payments,
			function ( $sum, $p ) {
				return $sum + (float) get_post_meta( $p->ID, '_lnkup_total_amount', true );
			},
			0
		);
		$lnkup_rev   = array_reduce(
			$payments,
			function ( $sum, $p ) {
				return $sum + (float) get_post_meta( $p->ID, '_lnkup_lnkup_fee', true );
			},
			0
		);
		$pending_docs = get_posts(
			array(
				'post_type'      => 'lnkup_document',
				'posts_per_page' => -1,
				'meta_key'       => '_lnkup_verification_status',
				'meta_value'     => 'pending',
				'fields'         => 'ids',
			)
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LNKup Marketplace', 'lnkup-marketplace' ); ?></h1>
			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-top:1.5rem;">
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) $vendors ); ?></strong><br><?php esc_html_e( 'Vendors', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) $organizers ); ?></strong><br><?php esc_html_e( 'Organizers', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) $events ); ?></strong><br><?php esc_html_e( 'Active Events', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) $applications ); ?></strong><br><?php esc_html_e( 'Applications', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( lnkup_currency( $gmv ) ); ?></strong><br><?php esc_html_e( 'GMV', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( lnkup_currency( $lnkup_rev ) ); ?></strong><br><?php esc_html_e( 'LNKup Revenue', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) count( $pending_docs ) ); ?></strong><br><?php esc_html_e( 'Documents Pending', 'lnkup-marketplace' ); ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render settings page.
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'lnkup_manage_settings' ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}

		$settings = lnkup_get_settings();
		$doc_types = LNKup_Documents::document_types();
		$webhook_url = rest_url( 'lnkup/v1/stripe-webhook' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LNKup Settings', 'lnkup-marketplace' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'lnkup_settings_group' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="stripe_public_key"><?php esc_html_e( 'Stripe Public Key', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="text" class="regular-text" id="stripe_public_key" name="lnkup_settings[stripe_public_key]" value="<?php echo esc_attr( $settings['stripe_public_key'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="stripe_secret_key"><?php esc_html_e( 'Stripe Secret Key', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="password" class="regular-text" id="stripe_secret_key" name="lnkup_settings[stripe_secret_key]" value="<?php echo esc_attr( $settings['stripe_secret_key'] ); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Stripe Webhook', 'lnkup-marketplace' ); ?></th>
						<td>
							<p>
								<label for="stripe_webhook_url"><strong><?php esc_html_e( 'Endpoint URL', 'lnkup-marketplace' ); ?></strong></label><br />
								<input type="text" class="large-text code" id="stripe_webhook_url" readonly value="<?php echo esc_attr( $webhook_url ); ?>" onclick="this.select();" />
							</p>
							<p class="description">
								<?php esc_html_e( 'In Stripe Dashboard → Developers → Webhooks, add this URL as a destination. Select these events:', 'lnkup-marketplace' ); ?>
								<code>account.updated</code>,
								<code>account.application.deauthorized</code>,
								<code>checkout.session.completed</code>,
								<code>payment_intent.succeeded</code>,
								<code>payment_intent.processing</code>,
								<code>payment_intent.payment_failed</code>,
								<code>transfer.created</code>,
								<code>transfer.reversed</code>,
								<code>payout.created</code>,
								<code>payout.paid</code>,
								<code>payout.failed</code>,
								<code>charge.refunded</code>,
								<code>charge.dispute.created</code>
							</p>
							<p>
								<label for="stripe_webhook_secret"><strong><?php esc_html_e( 'Signing secret', 'lnkup-marketplace' ); ?></strong></label><br />
								<input
									type="password"
									class="regular-text"
									id="stripe_webhook_secret"
									name="lnkup_settings[stripe_webhook_secret]"
									value=""
									placeholder="<?php echo esc_attr( ! empty( $settings['stripe_webhook_secret'] ) ? __( 'Configured — enter new value to replace', 'lnkup-marketplace' ) : 'whsec_...' ); ?>"
									autocomplete="new-password"
								/>
							</p>
							<?php if ( ! empty( $settings['stripe_webhook_secret'] ) ) : ?>
								<p class="description"><?php esc_html_e( 'Webhook signing secret is saved. Leave the field empty to keep the current value.', 'lnkup-marketplace' ); ?></p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Paste the whsec_... value from your Stripe webhook destination.', 'lnkup-marketplace' ); ?></p>
							<?php endif; ?>
							<p>
								<label for="stripe_connect_webhook_secret"><strong><?php esc_html_e( 'Connect signing secret (connected accounts)', 'lnkup-marketplace' ); ?></strong></label><br />
								<input
									type="password"
									class="regular-text"
									id="stripe_connect_webhook_secret"
									name="lnkup_settings[stripe_connect_webhook_secret]"
									value=""
									placeholder="<?php echo esc_attr( ! empty( $settings['stripe_connect_webhook_secret'] ) ? __( 'Configured — enter new value to replace', 'lnkup-marketplace' ) : 'whsec_...' ); ?>"
									autocomplete="new-password"
								/>
							</p>
							<p class="description"><?php esc_html_e( 'Optional. Add a second destination with the same URL that listens to events on connected accounts (account.updated, account.application.deauthorized, payout.paid, payout.failed) and paste its whsec_... value here. Leave empty to keep the current value.', 'lnkup-marketplace' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Enable Test Mode', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[test_mode]" value="1" <?php checked( $settings['test_mode'] ); ?> /> <?php esc_html_e( 'Use payment simulation when Stripe is not configured (admins only, and only with WP_DEBUG on). Webhooks always require a valid signature.', 'lnkup-marketplace' ); ?></label></td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Vendor applications', 'lnkup-marketplace' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Application wizard', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[apply_wizard_enabled]" value="1" <?php checked( ! empty( $settings['apply_wizard_enabled'] ) ); ?> /> <?php esc_html_e( 'Step-by-step apply form with autosave, resume on any device, organizer questions and price estimate', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Multi-stop tour applications', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[tour_multi_apply_enabled]" value="1" <?php checked( ! empty( $settings['tour_multi_apply_enabled'] ) ); ?> /> <?php esc_html_e( 'Let vendors apply to several tour stops or series dates at once (one application per stop). Requires Tours & series.', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Organizer Portfolio OS', 'lnkup-marketplace' ); ?></th>
						<td>
							<label><input type="checkbox" name="lnkup_settings[portfolio_os_enabled]" value="1" <?php checked( ! empty( $settings['portfolio_os_enabled'] ) ); ?> /> <?php esc_html_e( 'Tours & Series workspace for every organizer: tour and recurring-series setup, bulk stops, templates, stop-level pricing overrides, tour dashboard and public tour pages. Each stop keeps its own applications, booths and payments.', 'lnkup-marketplace' ); ?></label>
							<p><label for="lnkup-portfolio-os-users"><?php esc_html_e( 'Early access organizer IDs (comma-separated, used while the switch above is off):', 'lnkup-marketplace' ); ?></label><br />
							<input type="text" class="regular-text" id="lnkup-portfolio-os-users" name="lnkup_settings[portfolio_os_user_ids]" value="<?php echo esc_attr( (string) ( $settings['portfolio_os_user_ids'] ?? '' ) ); ?>" /></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Organizer guided setup (v2)', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[organizer_onboarding_v2_enabled]" value="1" <?php checked( ! empty( $settings['organizer_onboarding_v2_enabled'] ) ); ?> /> <?php esc_html_e( 'Organizer sign-up asks for organization name, city and category; new organizers land on Get started, choose what they organize (single, recurring, series, tour, production company) and can resume setup on any device.', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Application review workspace', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[application_review_v2_enabled]" value="1" <?php checked( ! empty( $settings['application_review_v2_enabled'] ) ); ?> /> <?php esc_html_e( 'Organizer review queue across events, reviewer assignment, optional scoring rubrics and previewed bulk decisions. Scores never change an application on their own.', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Waitlist offers', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[waitlist_offers_enabled]" value="1" <?php checked( ! empty( $settings['waitlist_offers_enabled'] ) ); ?> /> <?php esc_html_e( 'Let organizers offer an open spot to a waitlisted vendor with a payment deadline. Unpaid offers expire and return the vendor to the waitlist. Requires the review workspace.', 'lnkup-marketplace' ); ?></label></td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Payment methods (Financial OS)', 'lnkup-marketplace' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Flags gate WordPress Financial OS behavior. Enable matching methods in the Stripe Dashboard. Apps stay read-only + checkout handoff — they are not the payment system of record.', 'lnkup-marketplace' ); ?></p>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'ACH / bank debit', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[ach_enabled]" value="1" <?php checked( ! empty( $settings['ach_enabled'] ) ); ?> /> <?php esc_html_e( 'Allow US bank account (ACH) on PaymentIntents; processing webhooks do not unlock packets until succeeded', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Stripe Billing', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[stripe_billing_enabled]" value="1" <?php checked( ! empty( $settings['stripe_billing_enabled'] ) ); ?> /> <?php esc_html_e( 'Create real Stripe subscriptions when plans have a stripe_price_id (otherwise simulated)', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Apple Pay', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[apple_pay_enabled]" value="1" <?php checked( ! empty( $settings['apple_pay_enabled'] ) ); ?> /> <?php esc_html_e( 'Operator flag — enable Apple Pay in Stripe Dashboard / Payment Element domains', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Google Pay', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[google_pay_enabled]" value="1" <?php checked( ! empty( $settings['google_pay_enabled'] ) ); ?> /> <?php esc_html_e( 'Operator flag — enable Google Pay in Stripe Dashboard / Payment Element domains', 'lnkup-marketplace' ); ?></label></td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Stripe Connect', 'lnkup-marketplace' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Express marketplace with destination charges and application fees. See Financial → Stripe Connect for status.', 'lnkup-marketplace' ); ?></p>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Enable Connect', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[stripe_connect_enabled]" value="1" <?php checked( ! empty( $settings['stripe_connect_enabled'] ) ); ?> /> <?php esc_html_e( 'Route organizer payments via Stripe Connect', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Express accounts', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[connect_express_enabled]" value="1" <?php checked( ! empty( $settings['connect_express_enabled'] ) ); ?> /> <?php esc_html_e( 'Use Express onboarding', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Treasury fallback', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[connect_manual_fallback]" value="1" <?php checked( ! empty( $settings['connect_manual_fallback'] ) ); ?> /> <?php esc_html_e( 'Hold funds in platform treasury when organizer is not onboarded', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><label for="connect_platform_country"><?php esc_html_e( 'Platform country', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="text" id="connect_platform_country" name="lnkup_settings[connect_platform_country]" value="<?php echo esc_attr( $settings['connect_platform_country'] ?? 'US' ); ?>" maxlength="2" class="small-text" /></td>
					</tr>
					<tr>
						<th><label for="connect_platform_currency"><?php esc_html_e( 'Currency', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="text" id="connect_platform_currency" name="lnkup_settings[connect_platform_currency]" value="<?php echo esc_attr( $settings['connect_platform_currency'] ?? 'usd' ); ?>" maxlength="3" class="small-text" /></td>
					</tr>
					<tr>
						<th colspan="2"><h3 style="margin:1rem 0 0;"><?php esc_html_e( 'Marketplace fees at checkout', 'lnkup-marketplace' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Vendor pays a bundled booth total (booth + vendor fee). Organizer commission is deducted from booth revenue. Combined total is retained via Stripe application_fee on Connect charges.', 'lnkup-marketplace' ); ?></p></th>
					</tr>
					<tr>
						<th><label for="vendor_checkout_fee"><?php esc_html_e( 'Vendor checkout fee ($)', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<input type="number" step="0.01" id="vendor_checkout_fee" name="lnkup_settings[vendor_checkout_fee]" value="<?php echo esc_attr( (string) ( $settings['vendor_checkout_fee'] ?? $settings['application_fee_flat'] ?? $settings['default_fee'] ?? 5.99 ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Flat fee added to booth price at checkout (bundled in one line for vendors). Default: $5.99', 'lnkup-marketplace' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="organizer_commission_percent"><?php esc_html_e( 'Organizer commission (%)', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<input type="number" step="0.01" id="organizer_commission_percent" name="lnkup_settings[organizer_commission_percent]" value="<?php echo esc_attr( (string) ( $settings['organizer_commission_percent'] ?? 6 ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Percent of booth price retained from organizer revenue. Default: 6%', 'lnkup-marketplace' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Organizer recruit referrals', 'lnkup-marketplace' ); ?></th>
						<td>
							<label><input type="checkbox" name="lnkup_settings[organizer_recruit_enabled]" value="1" <?php checked( ! isset( $settings['organizer_recruit_enabled'] ) || ! empty( $settings['organizer_recruit_enabled'] ) ); ?> /> <?php esc_html_e( 'Enable invite-organizer program (vendors & organizers)', 'lnkup-marketplace' ); ?></label>
							<p class="description"><?php esc_html_e( 'Referrers earn a share of LNKup vendor + organizer fees on the referred organizer’s paid booth transactions for the window below.', 'lnkup-marketplace' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="organizer_recruit_percent"><?php esc_html_e( 'Recruit commission (% of platform fees)', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<input type="number" step="0.01" id="organizer_recruit_percent" name="lnkup_settings[organizer_recruit_percent]" value="<?php echo esc_attr( (string) ( $settings['organizer_recruit_percent'] ?? 20 ) ); ?>" />
						</td>
					</tr>
					<tr>
						<th><label for="organizer_recruit_months"><?php esc_html_e( 'Recruit earning window (months)', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<input type="number" min="1" max="36" id="organizer_recruit_months" name="lnkup_settings[organizer_recruit_months]" value="<?php echo esc_attr( (string) ( $settings['organizer_recruit_months'] ?? 12 ) ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th><label for="reserve_percent"><?php esc_html_e( 'Reserve percent', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="number" step="0.01" id="reserve_percent" name="lnkup_settings[reserve_percent]" value="<?php echo esc_attr( (string) ( $settings['reserve_percent'] ?? 5 ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="connect_payout_schedule"><?php esc_html_e( 'Default payout schedule', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<select id="connect_payout_schedule" name="lnkup_settings[connect_payout_schedule]">
								<option value="manual" <?php selected( $settings['connect_payout_schedule'] ?? 'manual', 'manual' ); ?>><?php esc_html_e( 'Manual (recommended for in-app cashout)', 'lnkup-marketplace' ); ?></option>
								<option value="daily" <?php selected( $settings['connect_payout_schedule'] ?? 'manual', 'daily' ); ?>><?php esc_html_e( 'Daily', 'lnkup-marketplace' ); ?></option>
								<option value="weekly" <?php selected( $settings['connect_payout_schedule'] ?? 'manual', 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'lnkup-marketplace' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Instant payouts', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[connect_instant_payouts_enabled]" value="1" <?php checked( ! empty( $settings['connect_instant_payouts_enabled'] ) ); ?> /> <?php esc_html_e( 'Allow instant Connect payouts (Stripe fees apply)', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><label for="fee_payer_mode"><?php esc_html_e( 'Fee payer', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<select id="fee_payer_mode" name="lnkup_settings[fee_payer_mode]">
								<option value="vendor" <?php selected( $settings['fee_payer_mode'] ?? 'vendor', 'vendor' ); ?>><?php esc_html_e( 'Vendor pays platform fee', 'lnkup-marketplace' ); ?></option>
								<option value="organizer" <?php selected( $settings['fee_payer_mode'] ?? 'vendor', 'organizer' ); ?>><?php esc_html_e( 'Organizer pays platform fee', 'lnkup-marketplace' ); ?></option>
								<option value="split" <?php selected( $settings['fee_payer_mode'] ?? 'vendor', 'split' ); ?>><?php esc_html_e( 'Split', 'lnkup-marketplace' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="connect_return_url"><?php esc_html_e( 'Connect return URL', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<input type="url" class="large-text" id="connect_return_url" name="lnkup_settings[connect_return_url]" value="<?php echo esc_attr( $settings['connect_return_url'] ?? '' ); ?>" placeholder="<?php echo esc_attr( home_url( '/organizer-dashboard/?view=financial&connect=return' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave blank to use organizer dashboard default.', 'lnkup-marketplace' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="connect_refresh_url"><?php esc_html_e( 'Connect refresh URL', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<input type="url" class="large-text" id="connect_refresh_url" name="lnkup_settings[connect_refresh_url]" value="<?php echo esc_attr( $settings['connect_refresh_url'] ?? '' ); ?>" placeholder="<?php echo esc_attr( home_url( '/organizer-dashboard/?view=financial&connect=refresh' ) ); ?>" />
						</td>
					</tr>
				</table>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Default Packet Locking', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[default_packet_lock]" value="1" <?php checked( $settings['default_packet_lock'] ); ?> /> <?php esc_html_e( 'Lock packets until payment', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><label for="support_email"><?php esc_html_e( 'Platform Support Email', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="email" id="support_email" name="lnkup_settings[support_email]" value="<?php echo esc_attr( $settings['support_email'] ); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Default Required Documents', 'lnkup-marketplace' ); ?></th>
						<td>
							<?php foreach ( $doc_types as $key => $label ) : ?>
								<label style="display:block;"><input type="checkbox" name="lnkup_settings[default_documents][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $settings['default_documents'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Email Notifications', 'lnkup-marketplace' ); ?></th>
						<td>
							<label><input type="checkbox" name="lnkup_settings[notify_vendor]" value="1" <?php checked( $settings['notify_vendor'] ); ?> /> <?php esc_html_e( 'Vendor notifications', 'lnkup-marketplace' ); ?></label><br>
							<label><input type="checkbox" name="lnkup_settings[notify_organizer]" value="1" <?php checked( $settings['notify_organizer'] ); ?> /> <?php esc_html_e( 'Organizer notifications', 'lnkup-marketplace' ); ?></label><br>
							<label><input type="checkbox" name="lnkup_settings[notify_admin]" value="1" <?php checked( $settings['notify_admin'] ); ?> /> <?php esc_html_e( 'Admin notifications', 'lnkup-marketplace' ); ?></label><br>
							<label><input type="checkbox" name="lnkup_settings[activation_emails_enabled]" value="1" <?php checked( ! isset( $settings['activation_emails_enabled'] ) || ! empty( $settings['activation_emails_enabled'] ) ); ?> /> <?php esc_html_e( 'Activation drip (welcome, finish profile, find events / create event)', 'lnkup-marketplace' ); ?></label>
							<p class="description" style="margin-top:8px;">
								<label for="activation_daily_batch"><?php esc_html_e( 'Daily activation batch size', 'lnkup-marketplace' ); ?></label>
								<input type="number" min="10" max="500" id="activation_daily_batch" name="lnkup_settings[activation_daily_batch]" value="<?php echo esc_attr( (string) ( $settings['activation_daily_batch'] ?? 100 ) ); ?>" class="small-text" />
							</p>
							<?php if ( current_user_can( 'manage_options' ) || ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() ) ) : ?>
								<?php $lnkup_failures = LNKup_Notifications::recent_failures( 20 ); ?>
								<h4 style="margin:16px 0 8px;"><?php esc_html_e( 'Recent notification failures', 'lnkup-marketplace' ); ?></h4>
								<?php if ( empty( $lnkup_failures ) ) : ?>
									<p class="description"><?php esc_html_e( 'No failed or suppressed deliveries.', 'lnkup-marketplace' ); ?></p>
								<?php else : ?>
									<table class="widefat striped" style="max-width:720px;">
										<thead>
											<tr>
												<th><?php esc_html_e( 'Outcome', 'lnkup-marketplace' ); ?></th>
												<th><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></th>
												<th><?php esc_html_e( 'Attempts', 'lnkup-marketplace' ); ?></th>
												<th><?php esc_html_e( 'Reason', 'lnkup-marketplace' ); ?></th>
												<th><?php esc_html_e( 'Updated', 'lnkup-marketplace' ); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $lnkup_failures as $failure ) : ?>
												<tr>
													<td><?php echo esc_html( (string) ( $failure['status'] ?? '' ) ); ?></td>
													<td><?php echo esc_html( trim( (string) ( $failure['event_key'] ?? '' ) . ' ' . (string) ( $failure['channel'] ?? '' ) . ' ' . (string) ( $failure['window_key'] ?? '' ) ) ); ?></td>
													<td><?php echo esc_html( (string) ( $failure['attempts'] ?? '' ) ); ?></td>
													<td><?php echo esc_html( (string) ( $failure['last_error'] ?? '' ) ); ?></td>
													<td><?php echo esc_html( (string) ( $failure['updated_at'] ?? '' ) ); ?></td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="expo_push_access_token"><?php esc_html_e( 'Expo Push Access Token', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<input type="password" id="expo_push_access_token" name="lnkup_settings[expo_push_access_token]" value="<?php echo esc_attr( $settings['expo_push_access_token'] ?? '' ); ?>" class="regular-text" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Optional. Bearer token for Expo Push API. Leave blank to use the public endpoint (fine for staging).', 'lnkup-marketplace' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="marketplace_phase"><?php esc_html_e( 'Marketplace Phase', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<select id="marketplace_phase" name="lnkup_settings[marketplace_phase]">
								<option value="1a" <?php selected( $settings['marketplace_phase'] ?? '1a', '1a' ); ?>><?php esc_html_e( '1A — Core browse (search, filters, favorites)', 'lnkup-marketplace' ); ?></option>
								<option value="1b" <?php selected( $settings['marketplace_phase'] ?? '1a', '1b' ); ?>><?php esc_html_e( '1B — Intelligence (recommendations, trending, collections)', 'lnkup-marketplace' ); ?></option>
								<option value="1c" <?php selected( $settings['marketplace_phase'] ?? '1a', '1c' ); ?>><?php esc_html_e( '1C — Advanced (map, compare, cron notifications)', 'lnkup-marketplace' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Ship 1A first on production. Enable 1B after browse is stable, then 1C.', 'lnkup-marketplace' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="saved_search_limit_per_vendor"><?php esc_html_e( 'Saved searches per vendor', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<input type="number" min="1" max="25" id="saved_search_limit_per_vendor" name="lnkup_settings[saved_search_limit_per_vendor]" value="<?php echo esc_attr( (string) ( $settings['saved_search_limit_per_vendor'] ?? 10 ) ); ?>" class="small-text" />
						</td>
					</tr>
					<?php if ( lnkup_marketplace_phase_at_least( '1c' ) ) : ?>
					<tr>
						<th><?php esc_html_e( 'Saved search alerts', 'lnkup-marketplace' ); ?></th>
						<td>
							<label><input type="checkbox" name="lnkup_settings[saved_search_alerts_enabled]" value="1" <?php checked( ! isset( $settings['saved_search_alerts_enabled'] ) || ! empty( $settings['saved_search_alerts_enabled'] ) ); ?> /> <?php esc_html_e( 'Send opportunity alert emails for saved searches', 'lnkup-marketplace' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><label for="saved_search_default_frequency"><?php esc_html_e( 'Default alert frequency', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<select id="saved_search_default_frequency" name="lnkup_settings[saved_search_default_frequency]">
								<option value="daily" <?php selected( $settings['saved_search_default_frequency'] ?? 'daily', 'daily' ); ?>><?php esc_html_e( 'Daily', 'lnkup-marketplace' ); ?></option>
								<option value="weekly" <?php selected( $settings['saved_search_default_frequency'] ?? 'daily', 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'lnkup-marketplace' ); ?></option>
								<option value="instant" <?php selected( $settings['saved_search_default_frequency'] ?? 'daily', 'instant' ); ?>><?php esc_html_e( 'Instant (on publish)', 'lnkup-marketplace' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="saved_search_max_matches_per_email"><?php esc_html_e( 'Max matches per alert email', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="number" min="1" max="10" id="saved_search_max_matches_per_email" name="lnkup_settings[saved_search_max_matches_per_email]" value="<?php echo esc_attr( (string) ( $settings['saved_search_max_matches_per_email'] ?? 5 ) ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Favorite deadline reminders', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[deadline_reminder_favorites]" value="1" <?php checked( ! isset( $settings['deadline_reminder_favorites'] ) || ! empty( $settings['deadline_reminder_favorites'] ) ); ?> /> <?php esc_html_e( 'Remind vendors when favorited opportunities close in 48h / 24h', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<?php endif; ?>
				</table>
				<h2><?php esc_html_e( 'Weather Intelligence', 'lnkup-marketplace' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Enable Weather Intelligence', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[weather_intelligence_enabled]" value="1" <?php checked( ! empty( $settings['weather_intelligence_enabled'] ) ); ?> /> <?php esc_html_e( 'Show weather readiness in Event Intelligence and Operations', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><label for="openweather_api_key"><?php esc_html_e( 'OpenWeather API Key', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="openweather_api_key" name="lnkup_settings[openweather_api_key]" value="" placeholder="<?php echo esc_attr( ! empty( $settings['openweather_api_key'] ) ? __( 'Configured — enter new value to replace', 'lnkup-marketplace' ) : 'appid...' ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Server-side only. Get a key at openweathermap.org/api_keys. Uses Current Weather + 5-day Forecast APIs.', 'lnkup-marketplace' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="weather_units"><?php esc_html_e( 'Units', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<select id="weather_units" name="lnkup_settings[weather_units]">
								<option value="imperial" <?php selected( $settings['weather_units'] ?? 'imperial', 'imperial' ); ?>><?php esc_html_e( 'Imperial (°F, mph)', 'lnkup-marketplace' ); ?></option>
								<option value="metric" <?php selected( $settings['weather_units'] ?? 'imperial', 'metric' ); ?>><?php esc_html_e( 'Metric (°C, m/s)', 'lnkup-marketplace' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="weather_refresh_minutes"><?php esc_html_e( 'Cache refresh (minutes)', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="number" min="5" max="240" id="weather_refresh_minutes" name="lnkup_settings[weather_refresh_minutes]" value="<?php echo esc_attr( (string) ( $settings['weather_refresh_minutes'] ?? 30 ) ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Historical risk API', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[weather_historical_enabled]" value="1" <?php checked( ! empty( $settings['weather_historical_enabled'] ) ); ?> /> <?php esc_html_e( 'Attempt OpenWeather historical data for events 17+ days out (requires plan support)', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Vendor packet weather note', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[weather_vendor_packet_note]" value="1" <?php checked( ! empty( $settings['weather_vendor_packet_note'] ) ); ?> /> <?php esc_html_e( 'Show compact weather readiness in vendor packets', 'lnkup-marketplace' ); ?></label></td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'CRM vendor import', 'lnkup-marketplace' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="crm_import_max_rows_organizer"><?php esc_html_e( 'Max CSV rows per import', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="number" min="50" max="2000" id="crm_import_max_rows_organizer" name="lnkup_settings[crm_import_max_rows_organizer]" value="<?php echo esc_attr( (string) ( $settings['crm_import_max_rows_organizer'] ?? 500 ) ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label for="crm_import_max_file_size_mb"><?php esc_html_e( 'Max import file size (MB)', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="number" min="1" max="20" step="0.5" id="crm_import_max_file_size_mb" name="lnkup_settings[crm_import_max_file_size_mb]" value="<?php echo esc_attr( (string) ( $settings['crm_import_max_file_size_mb'] ?? 5 ) ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Invites during import', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[crm_import_allow_invite]" value="1" <?php checked( ! isset( $settings['crm_import_allow_invite'] ) || ! empty( $settings['crm_import_allow_invite'] ) ); ?> /> <?php esc_html_e( 'Allow organizers to queue outreach invites from CSV import (still requires explicit confirmation)', 'lnkup-marketplace' ); ?></label></td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'City Connector rewards', 'lnkup-marketplace' ); ?></h2>
				<p class="description"><?php esc_html_e( 'When enabled, connectors earn a share of qualifying booth GMV from organizers they directly refer. The term starts at that organizer’s first completed booking. Organizer-side attribution wins if both sides were referred. Admin must approve after the hold; rewards never auto-payout via Stripe. There is no base or guaranteed payment.', 'lnkup-marketplace' ); ?></p>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Enable connector rewards', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[connector_rewards_enabled]" value="1" <?php checked( ! empty( $settings['connector_rewards_enabled'] ) ); ?> /> <?php esc_html_e( 'Queue a pending reward on each qualifying completed booth payment', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><label for="connector_reward_mode"><?php esc_html_e( 'Reward mode', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<select id="connector_reward_mode" name="lnkup_settings[connector_reward_mode]">
								<option value="manual" <?php selected( $settings['connector_reward_mode'] ?? 'manual', 'manual' ); ?>><?php esc_html_e( 'Manual (admin approve / mark paid)', 'lnkup-marketplace' ); ?></option>
								<option value="platform_credit" <?php selected( $settings['connector_reward_mode'] ?? '', 'platform_credit' ); ?>><?php esc_html_e( 'Platform credit on approve', 'lnkup-marketplace' ); ?></option>
								<option value="disabled" <?php selected( $settings['connector_reward_mode'] ?? '', 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'lnkup-marketplace' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="connector_booking_share_percent"><?php esc_html_e( 'Booth GMV share (%)', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="number" step="0.1" min="0" max="100" id="connector_booking_share_percent" name="lnkup_settings[connector_booking_share_percent]" value="<?php echo esc_attr( (string) ( $settings['connector_booking_share_percent'] ?? 10 ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Percent of booth price (_lnkup_vendor_spot_fee), not LNKup platform take or organizer commission.', 'lnkup-marketplace' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="connector_booking_share_cap"><?php esc_html_e( 'Per-transaction cap ($)', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="number" step="0.01" min="0" id="connector_booking_share_cap" name="lnkup_settings[connector_booking_share_cap]" value="<?php echo esc_attr( (string) ( $settings['connector_booking_share_cap'] ?? 0 ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( '0 disables the cap.', 'lnkup-marketplace' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="connector_reward_term_months"><?php esc_html_e( 'Reward term (months)', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="number" min="1" max="36" id="connector_reward_term_months" name="lnkup_settings[connector_reward_term_months]" value="<?php echo esc_attr( (string) ( $settings['connector_reward_term_months'] ?? 12 ) ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label for="connector_payout_hold_days"><?php esc_html_e( 'Hold period (days)', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="number" min="0" max="180" id="connector_payout_hold_days" name="lnkup_settings[connector_payout_hold_days]" value="<?php echo esc_attr( (string) ( $settings['connector_payout_hold_days'] ?? 30 ) ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label for="connector_minimum_payout"><?php esc_html_e( 'Minimum payout ($)', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="number" step="0.01" min="0" id="connector_minimum_payout" name="lnkup_settings[connector_minimum_payout]" value="<?php echo esc_attr( (string) ( $settings['connector_minimum_payout'] ?? 50 ) ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Vendor referral earnings', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[connector_vendor_referrals_rewarded]" value="1" <?php checked( ! empty( $settings['connector_vendor_referrals_rewarded'] ) ); ?> /> <?php esc_html_e( 'Also queue booking rewards when only the vendor (not the organizer) was referred', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><label for="connector_terms_url"><?php esc_html_e( 'Program terms URL', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="url" id="connector_terms_url" name="lnkup_settings[connector_terms_url]" value="<?php echo esc_attr( (string) ( $settings['connector_terms_url'] ?? '' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="connector_support_url"><?php esc_html_e( 'Support / contact URL', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="url" id="connector_support_url" name="lnkup_settings[connector_support_url]" value="<?php echo esc_attr( (string) ( $settings['connector_support_url'] ?? '' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="connector_vendor_signup_credit"><?php esc_html_e( 'Vendor signup credit ($)', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="number" step="0.01" min="0" id="connector_vendor_signup_credit" name="lnkup_settings[connector_vendor_signup_credit]" value="<?php echo esc_attr( (string) ( $settings['connector_vendor_signup_credit'] ?? 0 ) ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label for="connector_organizer_10_booking_bonus"><?php esc_html_e( 'Organizer 10-booking bonus ($)', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="number" step="0.01" min="0" id="connector_organizer_10_booking_bonus" name="lnkup_settings[connector_organizer_10_booking_bonus]" value="<?php echo esc_attr( (string) ( $settings['connector_organizer_10_booking_bonus'] ?? 0 ) ); ?>" class="small-text" /></td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'City Connector ranks', 'lnkup-marketplace' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Rank never changes commission. Bonuses stay pending until an admin approves them. Hold days reuse the connector payout hold above.', 'lnkup-marketplace' ); ?></p>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Enable ranks', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[connector_ranks_enabled]" value="1" <?php checked( ! empty( $settings['connector_ranks_enabled'] ) ); ?> /> <?php esc_html_e( 'Calculate and display City Connector ranks', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Leaderboard', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[connector_leaderboard_enabled]" value="1" <?php checked( ! empty( $settings['connector_leaderboard_enabled'] ) ); ?> /> <?php esc_html_e( 'Show opt-in city leaderboard (no dollars)', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Celebration', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[connector_rank_celebration_enabled]" value="1" <?php checked( ! empty( $settings['connector_rank_celebration_enabled'] ) ); ?> /> <?php esc_html_e( 'Show a one-time celebration when a rank is earned', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Bonus approval', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[connector_rank_bonus_requires_approval]" value="1" <?php checked( ! empty( $settings['connector_rank_bonus_requires_approval'] ) ); ?> /> <?php esc_html_e( 'Rank bonuses require admin approval (recommended)', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><label for="connector_founding_cutoff"><?php esc_html_e( 'Founding City Partner cutoff', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="date" id="connector_founding_cutoff" name="lnkup_settings[connector_founding_cutoff]" value="<?php echo esc_attr( (string) ( $settings['connector_founding_cutoff'] ?? '' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Empty = every approved connector is founding. Date is inclusive.', 'lnkup-marketplace' ); ?></p></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Market Builder', 'lnkup-marketplace' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Activated', 'lnkup-marketplace' ); ?> <input type="number" min="0" name="lnkup_settings[connector_rank_market_builder_activated]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_market_builder_activated'] ?? 3 ) ); ?>" class="small-text" /></label>
							<label><?php esc_html_e( 'Bookings', 'lnkup-marketplace' ); ?> <input type="number" min="0" name="lnkup_settings[connector_rank_market_builder_bookings]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_market_builder_bookings'] ?? 15 ) ); ?>" class="small-text" /></label>
							<label><?php esc_html_e( 'GMV', 'lnkup-marketplace' ); ?> <input type="number" min="0" step="0.01" name="lnkup_settings[connector_rank_market_builder_gmv]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_market_builder_gmv'] ?? 3000 ) ); ?>" class="small-text" /></label>
							<label><?php esc_html_e( 'Bonus', 'lnkup-marketplace' ); ?> <input type="number" min="0" step="0.01" name="lnkup_settings[connector_rank_market_builder_bonus]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_market_builder_bonus'] ?? 100 ) ); ?>" class="small-text" /></label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'City Captain', 'lnkup-marketplace' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Activated', 'lnkup-marketplace' ); ?> <input type="number" min="0" name="lnkup_settings[connector_rank_city_captain_activated]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_city_captain_activated'] ?? 10 ) ); ?>" class="small-text" /></label>
							<label><?php esc_html_e( 'Bookings', 'lnkup-marketplace' ); ?> <input type="number" min="0" name="lnkup_settings[connector_rank_city_captain_bookings]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_city_captain_bookings'] ?? 60 ) ); ?>" class="small-text" /></label>
							<label><?php esc_html_e( 'GMV', 'lnkup-marketplace' ); ?> <input type="number" min="0" step="0.01" name="lnkup_settings[connector_rank_city_captain_gmv]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_city_captain_gmv'] ?? 15000 ) ); ?>" class="small-text" /></label>
							<label><?php esc_html_e( 'Recurring', 'lnkup-marketplace' ); ?> <input type="number" min="0" name="lnkup_settings[connector_rank_city_captain_recurring]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_city_captain_recurring'] ?? 3 ) ); ?>" class="small-text" /></label>
							<label><?php esc_html_e( 'Bonus', 'lnkup-marketplace' ); ?> <input type="number" min="0" step="0.01" name="lnkup_settings[connector_rank_city_captain_bonus]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_city_captain_bonus'] ?? 250 ) ); ?>" class="small-text" /></label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'City Director', 'lnkup-marketplace' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Activated', 'lnkup-marketplace' ); ?> <input type="number" min="0" name="lnkup_settings[connector_rank_city_director_activated]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_city_director_activated'] ?? 25 ) ); ?>" class="small-text" /></label>
							<label><?php esc_html_e( 'Bookings', 'lnkup-marketplace' ); ?> <input type="number" min="0" name="lnkup_settings[connector_rank_city_director_bookings]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_city_director_bookings'] ?? 200 ) ); ?>" class="small-text" /></label>
							<label><?php esc_html_e( 'GMV', 'lnkup-marketplace' ); ?> <input type="number" min="0" step="0.01" name="lnkup_settings[connector_rank_city_director_gmv]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_city_director_gmv'] ?? 50000 ) ); ?>" class="small-text" /></label>
							<label><?php esc_html_e( 'Recurring', 'lnkup-marketplace' ); ?> <input type="number" min="0" name="lnkup_settings[connector_rank_city_director_recurring]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_city_director_recurring'] ?? 8 ) ); ?>" class="small-text" /></label>
							<label><?php esc_html_e( 'Bonus', 'lnkup-marketplace' ); ?> <input type="number" min="0" step="0.01" name="lnkup_settings[connector_rank_city_director_bonus]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_city_director_bonus'] ?? 500 ) ); ?>" class="small-text" /></label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Regional Partner', 'lnkup-marketplace' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Activated', 'lnkup-marketplace' ); ?> <input type="number" min="0" name="lnkup_settings[connector_rank_regional_partner_activated]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_regional_partner_activated'] ?? 50 ) ); ?>" class="small-text" /></label>
							<label><?php esc_html_e( 'Bookings', 'lnkup-marketplace' ); ?> <input type="number" min="0" name="lnkup_settings[connector_rank_regional_partner_bookings]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_regional_partner_bookings'] ?? 500 ) ); ?>" class="small-text" /></label>
							<label><?php esc_html_e( 'GMV', 'lnkup-marketplace' ); ?> <input type="number" min="0" step="0.01" name="lnkup_settings[connector_rank_regional_partner_gmv]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_regional_partner_gmv'] ?? 125000 ) ); ?>" class="small-text" /></label>
							<label><?php esc_html_e( 'Recurring', 'lnkup-marketplace' ); ?> <input type="number" min="0" name="lnkup_settings[connector_rank_regional_partner_recurring]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_regional_partner_recurring'] ?? 15 ) ); ?>" class="small-text" /></label>
							<label><?php esc_html_e( 'Cities', 'lnkup-marketplace' ); ?> <input type="number" min="1" name="lnkup_settings[connector_rank_regional_partner_cities]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_regional_partner_cities'] ?? 2 ) ); ?>" class="small-text" /></label>
							<label><?php esc_html_e( 'Bonus', 'lnkup-marketplace' ); ?> <input type="number" min="0" step="0.01" name="lnkup_settings[connector_rank_regional_partner_bonus]" value="<?php echo esc_attr( (string) ( $settings['connector_rank_regional_partner_bonus'] ?? 1000 ) ); ?>" class="small-text" /></label>
						</td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Outreach throttling', 'lnkup-marketplace' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Protect SiteGround SMTP limits during mass invite campaigns. Only outreach campaign sends are throttled — transactional notifications are unaffected.', 'lnkup-marketplace' ); ?></p>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Enable rate limiting', 'lnkup-marketplace' ); ?></th>
						<td><label><input type="checkbox" name="lnkup_settings[outreach_rate_limit_enabled]" value="1" <?php checked( ! empty( $settings['outreach_rate_limit_enabled'] ) ); ?> /> <?php esc_html_e( 'Throttle outreach sends (recommended on SiteGround)', 'lnkup-marketplace' ); ?></label></td>
					</tr>
					<tr>
						<th><label for="outreach_hourly_limit"><?php esc_html_e( 'Hourly send cap', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="number" min="1" id="outreach_hourly_limit" name="lnkup_settings[outreach_hourly_limit]" value="<?php echo esc_attr( (string) ( $settings['outreach_hourly_limit'] ?? 600 ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'GoGeek allows ~800/hour; 600 leaves room for transactional mail.', 'lnkup-marketplace' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="outreach_daily_limit"><?php esc_html_e( 'Daily send cap', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="number" min="1" id="outreach_daily_limit" name="lnkup_settings[outreach_daily_limit]" value="<?php echo esc_attr( (string) ( $settings['outreach_daily_limit'] ?? 1500 ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Unsent invites roll over and auto-resume the next day.', 'lnkup-marketplace' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="outreach_batch_size"><?php esc_html_e( 'Batch size', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="number" min="1" id="outreach_batch_size" name="lnkup_settings[outreach_batch_size]" value="<?php echo esc_attr( (string) ( $settings['outreach_batch_size'] ?? 20 ) ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label for="outreach_batch_interval_seconds"><?php esc_html_e( 'Seconds between batches', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="number" min="60" id="outreach_batch_interval_seconds" name="lnkup_settings[outreach_batch_interval_seconds]" value="<?php echo esc_attr( (string) ( $settings['outreach_batch_interval_seconds'] ?? 180 ) ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label for="outreach_resume_time"><?php esc_html_e( 'Daily resume time', 'lnkup-marketplace' ); ?></label></th>
						<td><input type="time" id="outreach_resume_time" name="lnkup_settings[outreach_resume_time]" value="<?php echo esc_attr( (string) ( $settings['outreach_resume_time'] ?? '08:00' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Site timezone. When daily cap is hit, sending resumes at this time the next day.', 'lnkup-marketplace' ); ?></p></td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Pre-launch', 'lnkup-marketplace' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Before the launch date, vendors see a waitlist experience. LNKup admins can optionally preview the full marketplace while pre-launch is active. Marketplace open is independent of the mobile app release date below.', 'lnkup-marketplace' ); ?></p>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Pre-launch mode', 'lnkup-marketplace' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="lnkup_settings[platform_prelaunch_mode]" value="1" <?php checked( ! empty( $settings['platform_prelaunch_mode'] ) ); ?> />
								<?php esc_html_e( 'Gate Find Events, favorites, and applications until launch date', 'lnkup-marketplace' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="platform_launch_date"><?php esc_html_e( 'Launch date', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<input type="date" id="platform_launch_date" name="lnkup_settings[platform_launch_date]" value="<?php echo esc_attr( (string) ( $settings['platform_launch_date'] ?? '' ) ); ?>" />
							<p class="description">
								<?php
								if ( lnkup_is_marketplace_open() ) {
									esc_html_e( 'Marketplace is currently open to the public.', 'lnkup-marketplace' );
								} else {
									printf(
										/* translators: %s: formatted launch date */
										esc_html__( 'Public launch scheduled for %s.', 'lnkup-marketplace' ),
										esc_html( lnkup_get_launch_date_label() )
									);
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Admin preview', 'lnkup-marketplace' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="lnkup_settings[platform_prelaunch_admin_preview]" value="1" <?php checked( ! empty( $settings['platform_prelaunch_admin_preview'] ) ); ?> />
								<?php esc_html_e( 'Allow LNKup admins to browse and test apply flows before public launch', 'lnkup-marketplace' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Applies to users with LNKup admin access only. Public visitors and vendors still see the pre-launch gate.', 'lnkup-marketplace' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="app_release_datetime"><?php esc_html_e( 'App release datetime', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<?php
							$app_dt_raw = (string) ( $settings['app_release_datetime'] ?? '2026-08-16 12:00:00' );
							$app_dt_raw = str_replace( ' ', 'T', substr( $app_dt_raw, 0, 16 ) );
							?>
							<input type="datetime-local" id="app_release_datetime" name="lnkup_settings[app_release_datetime]" value="<?php echo esc_attr( $app_dt_raw ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: formatted app release label */
									esc_html__( 'Homepage app bar shows until this moment. Current label: %s', 'lnkup-marketplace' ),
									esc_html( lnkup_get_app_release_label() )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="app_release_tz"><?php esc_html_e( 'App release timezone', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<input type="text" id="app_release_tz" name="lnkup_settings[app_release_tz]" value="<?php echo esc_attr( (string) ( $settings['app_release_tz'] ?? 'America/New_York' ) ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'PHP timezone ID (default America/New_York). Does not gate the web marketplace.', 'lnkup-marketplace' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="app_store_url"><?php esc_html_e( 'App Store URL', 'lnkup-marketplace' ); ?></label></th>
						<td>
							<input type="url" id="app_store_url" name="lnkup_settings[app_store_url]" value="<?php echo esc_attr( (string) ( $settings['app_store_url'] ?? 'https://apps.apple.com/app/id6795865859' ) ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'apps.apple.com link used by the homepage, dashboards, footer, and the Safari app banner.', 'lnkup-marketplace' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'App suggestions', 'lnkup-marketplace' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="lnkup_settings[app_prompts_enabled]" value="1" <?php checked( ! isset( $settings['app_prompts_enabled'] ) || ! empty( $settings['app_prompts_enabled'] ) ); ?> />
								<?php esc_html_e( 'Show optional "Get the iPhone app" prompts after the app release date', 'lnkup-marketplace' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Prompts can always be dismissed and are hidden for people who already use the app.', 'lnkup-marketplace' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<hr />
			<h2><?php esc_html_e( 'Marketplace Index', 'lnkup-marketplace' ); ?></h2>
			<?php
			global $wpdb;
			$index_table = LNKup_Marketplace_Index::table_name();
			$index_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$opp_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'lnkup_opportunity' AND post_status = 'publish'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$missing     = max( 0, $opp_count - $index_count );
			?>
			<p>
				<?php
				printf(
					esc_html__( 'Current phase: %1$s. Indexed opportunities: %2$d of %3$d published.', 'lnkup-marketplace' ),
					esc_html( strtoupper( lnkup_get_marketplace_phase() ) ),
					$index_count,
					$opp_count
				);
				?>
			</p>
			<?php if ( $missing > 0 ) : ?>
				<div class="notice notice-warning inline"><p><?php printf( esc_html__( '%d published opportunities are not in the search index. Rebuild to include user-created listings.', 'lnkup-marketplace' ), (int) $missing ); ?></p></div>
			<?php endif; ?>
			<?php if ( 0 === $index_count ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Search index is empty. Rebuild before deploying to production.', 'lnkup-marketplace' ); ?></p></div>
			<?php endif; ?>
			<div class="notice notice-info inline" style="max-width:720px;">
				<p><strong><?php esc_html_e( 'Production deploy checklist', 'lnkup-marketplace' ); ?></strong></p>
				<ol>
					<li><?php esc_html_e( 'Upload plugin v2.0.0 to thelnkup.com', 'lnkup-marketplace' ); ?></li>
					<li><?php esc_html_e( 'Confirm marketplace phase is 1A in settings', 'lnkup-marketplace' ); ?></li>
					<li><?php esc_html_e( 'Click Rebuild Search Index below', 'lnkup-marketplace' ); ?></li>
					<li><?php esc_html_e( 'Verify /opportunities/ search, filters, favorites, and saved searches', 'lnkup-marketplace' ); ?></li>
				</ol>
			</div>
			<?php if ( isset( $_GET['lnkup_rebuilt'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p><?php printf( esc_html__( 'Rebuilt index for %s opportunities.', 'lnkup-marketplace' ), esc_html( sanitize_text_field( wp_unslash( $_GET['lnkup_rebuilt'] ) ) ) ); ?></p></div>
			<?php endif; ?>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=lnkup-settings&lnkup_rebuild_index=1' ), 'lnkup_rebuild_index' ) ); ?>"><?php esc_html_e( 'Rebuild Search Index', 'lnkup-marketplace' ); ?></a></p>
		</div>
		<?php
	}
}
