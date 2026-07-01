<?php
/**
 * City Connector orchestrator.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Connector {

	const PROGRAM = 'city_connector';

	/**
	 * Init hooks.
	 */
	public static function init() {
		LNKup_Connector_Attribution::init();
		LNKup_Connector_Tracking::init();
		LNKup_Connector_Rewards::init();
	}

	/**
	 * Get connector row by user ID.
	 *
	 * @param int $user_id User ID.
	 */
	public static function get_by_user_id( $user_id ) {
		global $wpdb;
		$table = LNKup_Connector_Tables::connectors_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d LIMIT 1", absint( $user_id ) ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Get connector by ID.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function get( $connector_id ) {
		global $wpdb;
		$table = LNKup_Connector_Tables::connectors_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $connector_id ) ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Get connector for referred user attribution.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function get_active( $connector_id ) {
		$row = self::get( $connector_id );
		if ( ! $row || 'active' !== ( $row['status'] ?? '' ) ) {
			return null;
		}
		return $row;
	}

	/**
	 * Mask email for connector display.
	 *
	 * @param string $email Email.
	 */
	public static function mask_email( $email ) {
		$email = sanitize_email( (string) $email );
		if ( ! $email || ! is_email( $email ) ) {
			return '';
		}
		$parts = explode( '@', $email );
		$local = $parts[0];
		$domain = $parts[1] ?? '';
		$masked = strlen( $local ) <= 2
			? substr( $local, 0, 1 ) . '***'
			: substr( $local, 0, 2 ) . '***';
		return $masked . '@' . $domain;
	}

	/**
	 * Resolve referral code row.
	 *
	 * @param string $code Code.
	 */
	public static function resolve_code( $code ) {
		$code = sanitize_text_field( (string) $code );
		if ( ! $code ) {
			return null;
		}
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_codes_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s AND is_active = 1 LIMIT 1", $code ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		if ( self::PROGRAM === ( $row['program'] ?? 'vendor_referral' ) ) {
			$connector = self::get_active( (int) ( $row['connector_id'] ?? 0 ) );
			if ( ! $connector ) {
				return null;
			}
		}
		return $row;
	}

	/**
	 * Public-safe code resolution.
	 *
	 * @param string $code Code.
	 */
	public static function resolve_code_public( $code ) {
		$row = self::resolve_code( $code );
		if ( ! $row ) {
			return null;
		}
		$market_name = '';
		if ( ! empty( $row['market_slug'] ) && class_exists( 'LNKup_Market_Pages' ) ) {
			$market = LNKup_Market_Pages::get_market_by_slug( (string) $row['market_slug'], false );
			$market_name = $market['display_name'] ?? '';
		}
		return array(
			'valid'       => true,
			'program'     => $row['program'] ?? 'vendor_referral',
			'market_slug' => $row['market_slug'] ?? '',
			'market_name' => $market_name,
		);
	}

	/**
	 * Build referral links for connector.
	 *
	 * @param array $connector Connector row.
	 */
	public static function build_referral_links( $connector ) {
		$code_row = self::get_code_for_connector( (int) $connector['id'] );
		$code     = $code_row['code'] ?? '';
		$slug     = $connector['market_slug'] ?? '';
		$signup   = lnkup_registration_url();
		$links    = array(
			'vendor'    => add_query_arg( array( 'role' => 'vendor', 'ref' => $code, 'market' => $slug ), $signup ),
			'organizer' => add_query_arg( array( 'role' => 'organizer', 'ref' => $code, 'market' => $slug ), $signup ),
			'general'   => add_query_arg( 'ref', $code, $signup ),
		);
		if ( $slug && class_exists( 'LNKup_Market_Pages' ) ) {
			$links['city_page'] = add_query_arg( 'ref', $code, LNKup_Market_Pages::market_url( $slug ) );
		}
		$links['code'] = $code;
		return $links;
	}

	/**
	 * Get referral code row for connector.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function get_code_for_connector( $connector_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_codes_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE connector_id = %d AND program = %s LIMIT 1",
				absint( $connector_id ),
				self::PROGRAM
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Generate unique connector referral code.
	 *
	 * @param int    $connector_id Connector ID.
	 * @param string $custom       Optional custom code.
	 */
	public static function generate_code( $connector_id, $custom = '' ) {
		$connector = self::get( $connector_id );
		if ( ! $connector ) {
			return null;
		}
		$custom = strtoupper( preg_replace( '/[^A-Z0-9\-]/', '', sanitize_text_field( $custom ) ) );
		if ( $custom ) {
			$code = $custom;
		} else {
			$prefix = $connector['market_slug'] ? strtoupper( substr( preg_replace( '/[^a-z]/', '', $connector['market_slug'] ), 0, 3 ) ) : 'LNK';
			$code   = $prefix . '-' . strtoupper( substr( md5( (string) $connector_id . wp_salt() ), 0, 6 ) );
		}
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_codes_table();
		$existing = self::get_code_for_connector( $connector_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s LIMIT 1", $code ) );
		if ( $exists && ! $existing ) {
			$prefix = $connector['market_slug'] ? strtoupper( substr( preg_replace( '/[^a-z]/', '', $connector['market_slug'] ), 0, 3 ) ) : 'LNK';
			$code   = $prefix . '-' . strtoupper( substr( md5( (string) microtime( true ) ), 0, 6 ) );
		}
		$now      = current_time( 'mysql' );
		$data     = array(
			'user_id'      => (int) ( $connector['user_id'] ?? 0 ),
			'code'         => $code,
			'role_type'    => 'connector',
			'program'      => self::PROGRAM,
			'market_slug'  => (string) ( $connector['market_slug'] ?? '' ),
			'connector_id' => absint( $connector_id ),
			'is_active'    => 1,
		);
		if ( $existing ) {
			$wpdb->update( $table, array_merge( $data, array( 'code' => $code ) ), array( 'id' => (int) $existing['id'] ) );
			$code_id = (int) $existing['id'];
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( $table, $data );
			$code_id = (int) $wpdb->insert_id;
		}
		$wpdb->update(
			LNKup_Connector_Tables::connectors_table(),
			array( 'referral_code_id' => $code_id, 'updated_at' => $now ),
			array( 'id' => absint( $connector_id ) )
		);
		return self::get_code_for_connector( $connector_id );
	}

	/**
	 * Submit connector interest application.
	 *
	 * @param array $data Form data.
	 */
	public static function submit_interest( $data ) {
		$email = sanitize_email( $data['email'] ?? '' );
		if ( ! $email || ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'lnkup-marketplace' ) );
		}
		global $wpdb;
		$table = LNKup_Connector_Tables::connectors_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pending = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE email = %s AND status = 'pending' LIMIT 1",
				$email
			)
		);
		if ( $pending ) {
			return array( 'success' => true, 'message' => __( 'We already have your application on file.', 'lnkup-marketplace' ) );
		}
		$market_slug = sanitize_title( (string) ( $data['market_slug'] ?? '' ) );
		$city        = sanitize_text_field( (string) ( $data['city'] ?? '' ) );
		$state       = strtoupper( sanitize_text_field( (string) ( $data['state'] ?? '' ) ) );
		if ( $market_slug && class_exists( 'LNKup_Market_Pages' ) ) {
			$market = LNKup_Market_Pages::get_market_by_slug( $market_slug, false );
			if ( $market ) {
				$city  = $city ?: (string) $market['city'];
				$state = $state ?: (string) $market['state'];
			}
		}
		$now = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'display_name'  => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
				'email'         => $email,
				'phone'         => sanitize_text_field( (string) ( $data['phone'] ?? '' ) ),
				'market_slug'   => $market_slug,
				'city'          => $city,
				'state'         => $state,
				'status'        => 'pending',
				'connected_to'  => sanitize_key( (string) ( $data['connected_to'] ?? 'both' ) ),
				'interest_note' => sanitize_textarea_field( (string) ( $data['why'] ?? '' ) ),
				'social_url'    => esc_url_raw( (string) ( $data['social_url'] ?? '' ) ),
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		return array( 'success' => true, 'id' => (int) $wpdb->insert_id );
	}

	/**
	 * Approve connector application.
	 *
	 * @param int $connector_id Connector ID.
	 * @param int $admin_id     Admin user ID.
	 */
	public static function approve( $connector_id, $admin_id = 0 ) {
		$connector = self::get( $connector_id );
		if ( ! $connector ) {
			return new WP_Error( 'not_found', __( 'Connector not found.', 'lnkup-marketplace' ) );
		}
		$user_id = (int) ( $connector['user_id'] ?? 0 );
		if ( ! $user_id ) {
			if ( email_exists( $connector['email'] ) ) {
				$user_id = (int) email_exists( $connector['email'] );
			} else {
				$username = sanitize_user( strstr( $connector['email'], '@', true ) ?: 'connector', true );
				$suffix   = 1;
				$candidate = $username;
				while ( username_exists( $candidate ) ) {
					$candidate = $username . $suffix;
					++$suffix;
				}
				$password = wp_generate_password( 16, true );
				$user_id  = wp_create_user( $candidate, $password, $connector['email'] );
				if ( is_wp_error( $user_id ) ) {
					return $user_id;
				}
				wp_new_user_notification( $user_id, null, 'user' );
			}
			$user = new WP_User( $user_id );
			$user->set_role( 'lnkup_connector' );
			if ( $connector['display_name'] ) {
				wp_update_user( array( 'ID' => $user_id, 'display_name' => $connector['display_name'] ) );
			}
		}
		$now = current_time( 'mysql' );
		global $wpdb;
		$wpdb->update(
			LNKup_Connector_Tables::connectors_table(),
			array(
				'user_id'     => $user_id,
				'status'      => 'active',
				'approved_by' => absint( $admin_id ?: get_current_user_id() ),
				'approved_at' => $now,
				'updated_at'  => $now,
			),
			array( 'id' => absint( $connector_id ) )
		);
		self::generate_code( $connector_id );
		return self::get( $connector_id );
	}

	/**
	 * Dashboard summary for connector.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function get_dashboard_summary( $connector_id ) {
		global $wpdb;
		$ref_table = LNKup_Treasury_Tables::referrals_table();
		$connector = self::get( $connector_id );
		if ( ! $connector ) {
			return array();
		}
		$code = self::get_code_for_connector( $connector_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_signups,
					SUM(CASE WHEN target_role = 'lnkup_organizer' OR target_role = 'organizer' THEN 1 ELSE 0 END) AS organizer_signups,
					SUM(CASE WHEN target_role = 'lnkup_vendor' OR target_role = 'vendor' OR target_role = '' THEN 1 ELSE 0 END) AS vendor_signups,
					SUM(CASE WHEN profile_completed_at IS NOT NULL THEN 1 ELSE 0 END) AS profiles_completed,
					SUM(CASE WHEN first_application_at IS NOT NULL THEN 1 ELSE 0 END) AS applications,
					SUM(CASE WHEN first_opportunity_at IS NOT NULL THEN 1 ELSE 0 END) AS opportunities,
					SUM(CASE WHEN first_payment_at IS NOT NULL THEN 1 ELSE 0 END) AS paid_bookings,
					SUM(attributed_revenue) AS attributed_revenue
				FROM {$ref_table}
				WHERE program = %s AND referral_code_id = %d",
				self::PROGRAM,
				(int) ( $code['id'] ?? 0 )
			),
			ARRAY_A
		);
		$rewards = LNKup_Connector_Rewards::count_by_connector( $connector_id );
		return array(
			'connector'  => $connector,
			'code'       => $code['code'] ?? '',
			'links'      => self::build_referral_links( $connector ),
			'stats'      => array(
				'vendor_signups'     => (int) ( $stats['vendor_signups'] ?? 0 ),
				'organizer_signups'  => (int) ( $stats['organizer_signups'] ?? 0 ),
				'profiles_completed' => (int) ( $stats['profiles_completed'] ?? 0 ),
				'applications'       => (int) ( $stats['applications'] ?? 0 ),
				'opportunities'      => (int) ( $stats['opportunities'] ?? 0 ),
				'paid_bookings'      => (int) ( $stats['paid_bookings'] ?? 0 ),
				'attributed_revenue' => (float) ( $stats['attributed_revenue'] ?? 0 ),
			),
			'rewards'    => $rewards,
		);
	}

	/**
	 * List referred users for connector.
	 *
	 * @param int    $connector_id Connector ID.
	 * @param string $role_filter  vendor|organizer|all.
	 */
	public static function list_referred_users( $connector_id, $role_filter = 'all' ) {
		global $wpdb;
		$code = self::get_code_for_connector( $connector_id );
		if ( ! $code ) {
			return array();
		}
		$ref_table = LNKup_Treasury_Tables::referrals_table();
		$sql       = "SELECT * FROM {$ref_table} WHERE program = %s AND referral_code_id = %d";
		$params    = array( self::PROGRAM, (int) $code['id'] );
		if ( 'vendor' === $role_filter ) {
			$sql .= " AND (target_role = 'vendor' OR target_role = 'lnkup_vendor' OR target_role = '')";
		} elseif ( 'organizer' === $role_filter ) {
			$sql .= " AND (target_role = 'organizer' OR target_role = 'lnkup_organizer')";
		}
		$sql .= ' ORDER BY created_at DESC LIMIT 100';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$user = get_userdata( (int) $row['referred_user_id'] );
			$out[] = array(
				'id'           => (int) $row['id'],
				'name'         => $user ? $user->display_name : __( 'User', 'lnkup-marketplace' ),
				'email_masked' => $user ? self::mask_email( $user->user_email ) : '',
				'signup_date'  => $row['created_at'] ?? '',
				'stage'        => self::referral_stage_label( $row ),
				'applications' => $row['first_application_at'] ? 1 : 0,
				'paid_bookings'=> $row['first_payment_at'] ? 1 : 0,
				'target_role'  => $row['target_role'] ?? '',
			);
		}
		return $out;
	}

	/**
	 * Human-readable referral stage.
	 *
	 * @param array $row Referral row.
	 */
	public static function referral_stage_label( $row ) {
		if ( ! empty( $row['first_payment_at'] ) ) {
			return __( 'Paid booking', 'lnkup-marketplace' );
		}
		if ( ! empty( $row['first_application_at'] ) ) {
			return __( 'Applied', 'lnkup-marketplace' );
		}
		if ( ! empty( $row['profile_completed_at'] ) ) {
			return __( 'Profile complete', 'lnkup-marketplace' );
		}
		if ( ! empty( $row['first_opportunity_at'] ) ) {
			return __( 'Opportunity created', 'lnkup-marketplace' );
		}
		if ( ! empty( $row['first_event_at'] ) ) {
			return __( 'Event created', 'lnkup-marketplace' );
		}
		return __( 'Signed up', 'lnkup-marketplace' );
	}

	/**
	 * List activity events for connector.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function list_activity( $connector_id, $limit = 50 ) {
		global $wpdb;
		$table = LNKup_Connector_Tables::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE connector_id = %d ORDER BY created_at DESC LIMIT %d",
				absint( $connector_id ),
				absint( $limit )
			),
			ARRAY_A
		);
	}

	/**
	 * Record connector event.
	 *
	 * @param int    $connector_id Connector ID.
	 * @param int    $referral_id  Referral ID.
	 * @param int    $referred_id  Referred user ID.
	 * @param string $event_type   Event type.
	 */
	public static function record_event( $connector_id, $referral_id, $referred_id, $event_type ) {
		global $wpdb;
		$table = LNKup_Connector_Tables::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$dup = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE connector_id = %d AND referral_id = %d AND event_type = %s LIMIT 1",
				absint( $connector_id ),
				absint( $referral_id ),
				sanitize_key( $event_type )
			)
		);
		if ( $dup ) {
			return (int) $dup;
		}
		$wpdb->insert(
			$table,
			array(
				'connector_id'    => absint( $connector_id ),
				'referral_id'     => absint( $referral_id ),
				'referred_user_id'=> absint( $referred_id ),
				'event_type'      => sanitize_key( $event_type ),
				'created_at'      => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Get referral row for referred user (connector program).
	 *
	 * @param int $user_id Referred user ID.
	 */
	public static function get_referral_for_user( $user_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referrals_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE referred_user_id = %d AND program = %s LIMIT 1",
				absint( $user_id ),
				self::PROGRAM
			),
			ARRAY_A
		);
	}

	/**
	 * Admin metrics overview.
	 */
	public static function admin_metrics() {
		global $wpdb;
		$ct = LNKup_Connector_Tables::connectors_table();
		$rt = LNKup_Treasury_Tables::referrals_table();
		$rw = LNKup_Treasury_Tables::referral_rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ct} WHERE status = 'active'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ct} WHERE status = 'pending'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$referrals = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rt} WHERE program = %s", self::PROGRAM ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$revenue = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(attributed_revenue) FROM {$rt} WHERE program = %s", self::PROGRAM ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rewards_pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rw} WHERE reward_status = 'pending' AND connector_id > 0" );
		return compact( 'active', 'pending', 'referrals', 'revenue', 'rewards_pending' );
	}
}
