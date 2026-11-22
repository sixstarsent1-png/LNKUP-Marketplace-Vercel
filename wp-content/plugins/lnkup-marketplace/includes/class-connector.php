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
		if ( self::is_account_program( (string) ( $row['program'] ?? 'vendor_referral' ) ) ) {
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
		if ( class_exists( 'LNKup_Organizer_Recruit_Referrals' ) ) {
			$org = LNKup_Organizer_Recruit_Referrals::resolve_code_public( (string) $code );
			if ( $org ) {
				return $org;
			}
		}
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
	public static function build_referral_links( $connector, $cc = '' ) {
		$code_row = self::get_code_for_connector( (int) $connector['id'] );
		$code     = $code_row['code'] ?? '';
		$slug     = $connector['market_slug'] ?? '';
		$signup   = lnkup_registration_url();
		$args     = array( 'ref' => $code );
		$cc       = sanitize_key( (string) $cc );
		if ( $cc ) {
			$args['cc'] = $cc;
		}
		$links    = array(
			'vendor'    => add_query_arg( array_merge( $args, array( 'role' => 'vendor', 'market' => $slug ) ), $signup ),
			'organizer' => add_query_arg( array_merge( $args, array( 'role' => 'organizer', 'market' => $slug ) ), $signup ),
			'general'   => add_query_arg( $args, $signup ),
		);
		if ( $slug && class_exists( 'LNKup_Market_Pages' ) ) {
			$links['city_page'] = add_query_arg( $args, LNKup_Market_Pages::market_url( $slug ) );
		}
		$links['code'] = $code;
		$links['cc']   = $cc;
		$links['city'] = trim( (string) ( $connector['city'] ?? '' ) );
		$links['market'] = $slug;
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
				"SELECT * FROM {$table} WHERE connector_id = %d AND program IN (" . self::programs_sql() . ') ORDER BY id ASC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $connector_id )
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Program slugs that hold a partner account row in this table.
	 *
	 * @return string[]
	 */
	public static function account_programs() {
		return class_exists( 'LNKup_Partner_Programs' ) ? LNKup_Partner_Programs::ACCOUNT_PROGRAMS : array( self::PROGRAM );
	}

	/**
	 * @param string $program Program slug.
	 */
	public static function is_account_program( $program ) {
		return in_array( (string) $program, self::account_programs(), true );
	}

	/**
	 * SQL IN list of account programs (constants only).
	 */
	public static function programs_sql() {
		return "'" . implode( "','", self::account_programs() ) . "'";
	}

	/**
	 * Program a partner account belongs to (rows from before the program column default to City Connector).
	 *
	 * @param array|null $connector Connector row.
	 */
	public static function program_of( $connector ) {
		$program = (string) ( $connector['program'] ?? '' );
		return self::is_account_program( $program ) ? $program : self::PROGRAM;
	}

	/**
	 * WordPress role granted to an approved account of a program.
	 *
	 * @param string $program Program slug.
	 */
	public static function role_for_program( $program ) {
		return self::PROGRAM === $program ? 'lnkup_connector' : 'lnkup_partner';
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
		$program = self::program_of( $connector );
		$custom  = strtoupper( preg_replace( '/[^A-Z0-9\-]/', '', sanitize_text_field( $custom ) ) );
		if ( self::PROGRAM === $program ) {
			$prefix = $connector['market_slug'] ? strtoupper( substr( preg_replace( '/[^a-z]/', '', $connector['market_slug'] ), 0, 3 ) ) : 'LNK';
		} else {
			$prefix = 'affiliate' === $program ? 'AFF' : 'PTR';
		}
		if ( $custom ) {
			$code = $custom;
		} else {
			$code = $prefix . '-' . strtoupper( substr( md5( (string) $connector_id . wp_salt() ), 0, 6 ) );
		}
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_codes_table();
		$existing = self::get_code_for_connector( $connector_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s LIMIT 1", $code ) );
		if ( $exists && ! $existing ) {
			$code = $prefix . '-' . strtoupper( substr( md5( (string) microtime( true ) ), 0, 6 ) );
		}
		$now      = current_time( 'mysql' );
		$data     = array(
			'user_id'      => (int) ( $connector['user_id'] ?? 0 ),
			'code'         => $code,
			'role_type'    => 'connector',
			'program'      => $program,
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
		$user_id     = (int) ( $connector['user_id'] ?? 0 );
		$created_new = false;
		$role        = self::role_for_program( self::program_of( $connector ) );
		if ( ! $user_id ) {
			if ( email_exists( $connector['email'] ) ) {
				$user_id = (int) email_exists( $connector['email'] );
			} else {
				$username  = sanitize_user( strstr( $connector['email'], '@', true ) ?: 'connector', true );
				$suffix    = 1;
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
				$created_new = true;
				wp_new_user_notification( $user_id, null, 'user' );
			}
			if ( $connector['display_name'] ) {
				wp_update_user( array( 'ID' => $user_id, 'display_name' => $connector['display_name'] ) );
			}
		}
		self::ensure_connector_role( $user_id, $created_new, $role );
		$now = current_time( 'mysql' );
		global $wpdb;
		$update = array(
			'user_id'     => $user_id,
			'status'      => 'active',
			'approved_by' => absint( $admin_id ?: get_current_user_id() ),
			'approved_at' => $now,
			'updated_at'  => $now,
		);
		$agreement_rule = self::agreement_rule_id( $connector );
		if ( $agreement_rule && empty( $connector['commission_rule_id'] ) ) {
			$update['commission_rule_id'] = $agreement_rule;
		}
		$wpdb->update( LNKup_Connector_Tables::connectors_table(), $update, array( 'id' => absint( $connector_id ) ) );
		self::generate_code( $connector_id );
		if ( class_exists( 'LNKup_Connector_Rewards' ) ) {
			LNKup_Connector_Rewards::rule_for( (int) $connector_id );
		}
		if ( class_exists( 'LNKup_Connector_Ranks' ) ) {
			LNKup_Connector_Ranks::recalculate( $connector_id, 'approve' );
		}
		return self::get( $connector_id );
	}

	/**
	 * Grant City Connector without removing administrator or LNKup admin.
	 *
	 * @param int  $user_id     User ID.
	 * @param bool $created_new Whether this user was just created for the connector.
	 */
	public static function ensure_connector_role( $user_id, $created_new = false, $role = 'lnkup_connector' ) {
		$user = new WP_User( absint( $user_id ) );
		if ( ! $user->exists() ) {
			return;
		}
		if ( ! get_role( $role ) ) {
			LNKup_Roles::sync_role_capabilities();
		}
		$roles    = (array) $user->roles;
		$elevated = in_array( 'administrator', $roles, true ) || in_array( 'lnkup_admin', $roles, true );
		if ( $elevated || ! $created_new ) {
			$user->add_role( $role );
			return;
		}
		$user->set_role( $role );
	}

	/**
	 * Agreement rule of a strategic contact's partner organization, if one is set.
	 *
	 * @param array $connector Connector row.
	 */
	public static function agreement_rule_id( $connector ) {
		$partner_id = (int) ( $connector['partner_id'] ?? 0 );
		if ( ! $partner_id || ! class_exists( 'LNKup_Org_Growth_Tables' ) ) {
			return 0;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT commission_rule_id FROM ' . LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::PARTNERS ) . ' WHERE id = %d', $partner_id ) );
	}

	/**
	 * One-time: put administrator back on site-owner accounts stripped by connector approval.
	 */
	public static function restore_elevated_roles_stripped_by_approval() {
		if ( get_option( 'lnkup_connector_restored_admin_roles' ) ) {
			return;
		}
		$candidates = array();
		$admin_email = get_option( 'admin_email' );
		if ( is_string( $admin_email ) && $admin_email ) {
			$by_email = get_user_by( 'email', $admin_email );
			if ( $by_email instanceof WP_User ) {
				$candidates[ (int) $by_email->ID ] = $by_email;
			}
		}
		$user_one = get_userdata( 1 );
		if ( $user_one instanceof WP_User ) {
			$candidates[1] = $user_one;
		}
		foreach ( $candidates as $user ) {
			$roles = (array) $user->roles;
			if ( in_array( 'lnkup_connector', $roles, true ) && ! in_array( 'administrator', $roles, true ) && ! in_array( 'lnkup_admin', $roles, true ) ) {
				$user->add_role( 'administrator' );
			}
		}
		update_option( 'lnkup_connector_restored_admin_roles', '1' );
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
				(string) ( $code['program'] ?? self::PROGRAM ),
				(int) ( $code['id'] ?? 0 )
			),
			ARRAY_A
		);
		$rewards         = LNKup_Connector_Rewards::count_by_connector( $connector_id );
		$unique_payments = LNKup_Connector_Rewards::count_unique_payments( $connector_id );
		$paid_bookings   = $unique_payments > 0 ? $unique_payments : (int) ( $stats['paid_bookings'] ?? 0 );
		$onboarding      = self::get_onboarding( $connector_id );
		$activated       = self::count_activated_organizers( $connector_id );
		$program         = self::program_of( $connector );
		$views           = self::dashboard_views( $connector );
		$organization    = (string) ( $connector['organization'] ?? '' );
		$account_status  = (string) ( $connector['status'] ?? '' );
		$terms           = LNKup_Connector_Rewards::terms( $connector_id );
		$connector       = self::public_connector( $connector );
		return array(
			'program'        => $program,
			'program_label'  => class_exists( 'LNKup_Partner_Programs' ) ? LNKup_Partner_Programs::label( $program ) : '',
			'title'          => self::dashboard_title( array( 'program' => $program ) ),
			'views'          => array_keys( $views ),
			'terms_pending'  => (bool) $terms['terms_pending'],
			'organization'   => $organization,
			'account_status' => $account_status,
			'connector'    => $connector,
			'code'         => $code['code'] ?? '',
			'links'        => self::build_referral_links( $connector ),
			'terms'        => $terms,
			'default_view' => self::PROGRAM === $program ? $onboarding['default_view'] : 'home',
			'onboarding'   => $onboarding,
			'next_action'  => self::next_best_action( $connector_id ),
			'stats'        => array(
				'vendor_signups'     => (int) ( $stats['vendor_signups'] ?? 0 ),
				'organizer_signups'  => (int) ( $stats['organizer_signups'] ?? 0 ),
				'profiles_completed' => (int) ( $stats['profiles_completed'] ?? 0 ),
				'applications'       => (int) ( $stats['applications'] ?? 0 ),
				'opportunities'      => (int) ( $stats['opportunities'] ?? 0 ),
				'paid_bookings'      => $paid_bookings,
				'activated'          => $activated,
				'attributed_revenue' => (float) ( $stats['attributed_revenue'] ?? 0 ),
			),
			'rank'         => self::PROGRAM === $program && class_exists( 'LNKup_Connector_Ranks' ) ? LNKup_Connector_Ranks::dto( $connector_id ) : null,
			'rewards'      => $rewards,
			'earnings'     => array_merge(
				LNKup_Connector_Rewards::balances_for_connector( $connector_id ),
				array(
					'estimated' => LNKup_Connector_Rewards::estimate_from_gmv_total( (float) ( $stats['attributed_revenue'] ?? 0 ), $connector_id ),
				)
			),
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
		$params    = array( (string) ( $code['program'] ?? self::PROGRAM ), (int) $code['id'] );
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
	 * Activity rows safe for the connector dashboard (no emails).
	 *
	 * @param int $connector_id Connector ID.
	 * @param int $limit        Max rows.
	 */
	public static function public_activity( $connector_id, $limit = 30 ) {
		$rows = self::list_activity( $connector_id, $limit );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$uid  = (int) ( $row['referred_user_id'] ?? 0 );
			$city = $uid ? (string) get_user_meta( $uid, '_lnkup_city', true ) : '';
			$out[] = array(
				'id'         => (int) ( $row['id'] ?? 0 ),
				'event_type' => (string) ( $row['event_type'] ?? '' ),
				'created_at' => (string) ( $row['created_at'] ?? '' ),
				'city'       => $city,
			);
		}
		return $out;
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
	 * Record a content-tagged referral click (not unique).
	 *
	 * @param int    $connector_id Connector ID.
	 * @param string $cc           Template or post id.
	 */
	public static function record_content_click( $connector_id, $cc ) {
		$cc = sanitize_key( (string) $cc );
		if ( ! $connector_id || ! $cc ) {
			return 0;
		}
		global $wpdb;
		$wpdb->insert(
			LNKup_Connector_Tables::events_table(),
			array(
				'connector_id'     => absint( $connector_id ),
				'referral_id'      => 0,
				'referred_user_id' => 0,
				'event_type'       => 'content_click',
				'metadata'         => wp_json_encode( array( 'cc' => $cc ) ),
				'created_at'       => current_time( 'mysql' ),
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
				"SELECT * FROM {$table} WHERE referred_user_id = %d AND program IN (" . self::programs_sql() . ') ORDER BY created_at ASC, id ASC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id )
			),
			ARRAY_A
		);
	}

	/**
	 * Dashboard views (slug => label) a partner account can open. City Connectors keep every view;
	 * affiliates and strategic contacts have no ranks, content studio, launch plan or territories.
	 *
	 * @param array|null $connector Connector row (null = City Connector set).
	 * @return array<string, string>
	 */
	public static function dashboard_views( $connector = null ) {
		$all = array(
			'home'      => __( 'Overview', 'lnkup-marketplace' ),
			'start'     => __( 'Get Started', 'lnkup-marketplace' ),
			'referrals' => __( 'My Referrals', 'lnkup-marketplace' ),
			'leads'     => __( 'Organizer Leads', 'lnkup-marketplace' ),
			'cities'    => __( 'My Cities', 'lnkup-marketplace' ),
			'earnings'  => __( 'Earnings', 'lnkup-marketplace' ),
			'ranks'     => __( 'Ranks', 'lnkup-marketplace' ),
			'resources' => __( 'Resources', 'lnkup-marketplace' ),
			'studio'    => __( 'Content Studio', 'lnkup-marketplace' ),
			'profile'   => __( 'Profile', 'lnkup-marketplace' ),
		);
		if ( ! $connector || self::PROGRAM === self::program_of( $connector ) ) {
			return $all;
		}
		return array_intersect_key( $all, array_flip( array( 'home', 'referrals', 'leads', 'earnings', 'resources', 'profile' ) ) );
	}

	/**
	 * Dashboard title for an account's program.
	 *
	 * @param array|null $connector Connector row.
	 */
	public static function dashboard_title( $connector = null ) {
		switch ( self::program_of( $connector ) ) {
			case 'affiliate':
				return __( 'Affiliate Dashboard', 'lnkup-marketplace' );
			case 'strategic_partner':
				return __( 'Partner Dashboard', 'lnkup-marketplace' );
			default:
				return __( 'Connector Dashboard', 'lnkup-marketplace' );
		}
	}

	/**
	 * Map legacy view slugs and pick the default for new connectors.
	 *
	 * @param string     $requested Requested view.
	 * @param array|null $connector Connector row.
	 */
	public static function resolve_dashboard_view( $requested, $connector = null ) {
		$aliases = array(
			'guide'      => 'resources',
			'links'      => 'start',
			'rewards'    => 'earnings',
			'vendors'    => 'referrals',
			'organizers' => 'referrals',
			'activity'   => 'referrals',
			'create'     => 'studio',
		);
		$requested = sanitize_key( (string) $requested );
		if ( isset( $aliases[ $requested ] ) ) {
			$requested = $aliases[ $requested ];
		}
		$allowed = array_keys( self::dashboard_views( $connector ) );
		if ( $requested && in_array( $requested, $allowed, true ) ) {
			return $requested;
		}
		if ( $connector && self::PROGRAM !== self::program_of( $connector ) ) {
			return 'home';
		}
		if ( $connector ) {
			$onboarding = self::get_onboarding( (int) $connector['id'] );
			return $onboarding['default_view'];
		}
		return 'start';
	}

	/**
	 * Whether the connector profile has the minimum fields filled.
	 *
	 * @param array $connector Connector row.
	 */
	public static function is_profile_complete( $connector ) {
		$name  = trim( (string) ( $connector['display_name'] ?? '' ) );
		$city  = trim( (string) ( $connector['city'] ?? '' ) );
		$phone = trim( (string) ( $connector['phone'] ?? '' ) );
		$social = trim( (string) ( $connector['social_url'] ?? '' ) );
		return $name && $city && ( $phone || $social );
	}

	/**
	 * Allowed growth-style keys.
	 */
	public static function growth_style_keys() {
		return array( 'relationship', 'video', 'community', 'event' );
	}

	/**
	 * Parse stored growth styles.
	 *
	 * @param mixed $raw JSON string or array.
	 */
	public static function parse_growth_styles( $raw ) {
		if ( is_array( $raw ) ) {
			$arr = $raw;
		} else {
			$arr = json_decode( (string) $raw, true );
			if ( ! is_array( $arr ) && $raw ) {
				$arr = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
			}
		}
		if ( ! is_array( $arr ) ) {
			return array();
		}
		$out = array();
		foreach ( $arr as $key ) {
			$key = sanitize_key( (string) $key );
			if ( in_array( $key, self::growth_style_keys(), true ) ) {
				$out[] = $key;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Growth styles for a connector row.
	 *
	 * @param array $connector Connector row.
	 */
	public static function growth_styles_for( $connector ) {
		return self::parse_growth_styles( $connector['growth_styles'] ?? '' );
	}

	/**
	 * Copy reviewed_targets onto reviewed_earnings once.
	 *
	 * @param int   $user_id User ID.
	 * @param array $meta    Onboarding meta.
	 */
	public static function migrate_onboarding_meta( $user_id, $meta ) {
		$meta = is_array( $meta ) ? $meta : array();
		if ( ! empty( $meta['reviewed_targets'] ) && empty( $meta['reviewed_earnings'] ) ) {
			$meta['reviewed_earnings'] = $meta['reviewed_targets'];
			if ( $user_id ) {
				update_user_meta( $user_id, '_lnkup_connector_onboarding', $meta );
			}
		}
		return $meta;
	}

	/**
	 * Phase 1 complete (Rank 1 gate). Legacy educational steps still count.
	 *
	 * @param array $connector Connector row.
	 * @param array $meta      Onboarding meta.
	 */
	public static function phase1_complete( $connector, $meta ) {
		$styles = self::growth_styles_for( $connector );
		$city   = trim( (string) ( $connector['city'] ?? '' ) );
		$market = trim( (string) ( $connector['market_slug'] ?? '' ) );
		$legacy = self::is_profile_complete( $connector )
			&& ! empty( $meta['copied_link'] )
			&& ! empty( $meta['copied_vendor_link'] )
			&& ( ! empty( $meta['reviewed_targets'] ) || ! empty( $meta['reviewed_earnings'] ) );
		if ( $legacy ) {
			return true;
		}
		return self::is_profile_complete( $connector )
			&& $city && $market
			&& ! empty( $styles )
			&& ! empty( $meta['copied_link'] )
			&& ! empty( $meta['copied_vendor_link'] )
			&& ! empty( $meta['reviewed_earnings'] );
	}

	/**
	 * Onboarding checklist derived from real activity + self-report meta.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function get_onboarding( $connector_id ) {
		$connector = self::get( $connector_id );
		if ( ! $connector ) {
			return array(
				'progress'     => 0,
				'default_view' => 'start',
				'steps'        => array(),
				'phases'       => array(),
			);
		}
		$user_id = (int) ( $connector['user_id'] ?? 0 );
		$meta    = $user_id ? (array) get_user_meta( $user_id, '_lnkup_connector_onboarding', true ) : array();
		$meta    = self::migrate_onboarding_meta( $user_id, $meta );
		$styles  = self::growth_styles_for( $connector );
		$code    = self::get_code_for_connector( $connector_id );
		global $wpdb;
		$ref_table = LNKup_Treasury_Tables::referrals_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_signups,
					SUM(CASE WHEN target_role = 'lnkup_organizer' OR target_role = 'organizer' THEN 1 ELSE 0 END) AS organizer_signups,
					SUM(CASE WHEN first_opportunity_at IS NOT NULL AND (target_role = 'lnkup_organizer' OR target_role = 'organizer') THEN 1 ELSE 0 END) AS opportunities,
					SUM(CASE WHEN first_payment_at IS NOT NULL AND (target_role = 'lnkup_organizer' OR target_role = 'organizer') THEN 1 ELSE 0 END) AS paid
				FROM {$ref_table}
				WHERE program = %s AND referral_code_id = %d",
				(string) ( $code['program'] ?? self::PROGRAM ),
				(int) ( $code['id'] ?? 0 )
			),
			ARRAY_A
		);
		$events = LNKup_Connector_Tables::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$clicks = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$events} WHERE connector_id = %d AND event_type IN ('clicked','content_click')",
				absint( $connector_id )
			)
		);
		$balances = LNKup_Connector_Rewards::balances_for_connector( $connector_id );
		$terms    = LNKup_Connector_Rewards::terms();
		$payable  = (float) $balances['approved'] + (float) $balances['awaiting_approval'] + (float) $balances['pending_hold'];
		$activated = (int) ( $connector['activated_organizer_count'] ?? 0 );
		if ( $activated < 1 ) {
			$activated = self::count_activated_organizers( $connector_id );
		}
		$city   = trim( (string) ( $connector['city'] ?? '' ) );
		$market = trim( (string) ( $connector['market_slug'] ?? '' ) );
		$has_rank = ! empty( $connector['current_rank'] );
		$phase1_done = self::phase1_complete( $connector, $meta );

		$step = function ( $args ) {
			$defaults = array(
				'done'          => false,
				'auto'          => false,
				'cta_view'      => 'start',
				'cta_label'     => '',
				'open_share'    => false,
				'open_studio'   => false,
				'eta'           => '2 min',
				'why'           => '',
				'help'          => '',
				'completed_at'  => '',
				'status'        => 'available',
				'unlock'        => '',
			);
			$row = array_merge( $defaults, $args );
			if ( $row['done'] ) {
				$row['status'] = 'complete';
			}
			return $row;
		};

		$phase1 = array(
			$step(
				array(
					'id'         => 'profile',
					'done'       => self::is_profile_complete( $connector ),
					'auto'       => true,
					'cta_view'   => 'profile',
					'cta_label'  => __( 'Complete Profile', 'lnkup-marketplace' ),
					'title'      => __( 'Complete connector profile', 'lnkup-marketplace' ),
					'description'=> __( 'Add your name, city, and a phone or social link.', 'lnkup-marketplace' ),
					'why'        => __( 'Organizers need to know who is introducing them.', 'lnkup-marketplace' ),
					'eta'        => '3 min',
				)
			),
			$step(
				array(
					'id'         => 'markets',
					'done'       => (bool) ( $city && $market ),
					'auto'       => true,
					'cta_view'   => 'profile',
					'cta_label'  => __( 'Confirm City', 'lnkup-marketplace' ),
					'title'      => __( 'Confirm city and markets served', 'lnkup-marketplace' ),
					'description'=> __( 'Your home market is used on referral links and the leaderboard.', 'lnkup-marketplace' ),
					'why'        => __( 'City context makes your outreach specific.', 'lnkup-marketplace' ),
				)
			),
			$step(
				array(
					'id'         => 'growth_style',
					'done'       => ! empty( $styles ) || $phase1_done,
					'auto'       => false,
					'cta_view'   => 'start',
					'cta_label'  => __( 'Choose Growth Style', 'lnkup-marketplace' ),
					'title'      => __( 'Select how you grow LNKup', 'lnkup-marketplace' ),
					'description'=> __( 'Pick one or more styles. We will recommend tasks that match.', 'lnkup-marketplace' ),
					'why'        => __( 'Creators, community leads, and in-person connectors should not get the same homework.', 'lnkup-marketplace' ),
					'eta'        => '1 min',
				)
			),
			$step(
				array(
					'id'          => 'copied_link',
					'done'        => ! empty( $meta['copied_link'] ),
					'completed_at'=> (string) ( $meta['copied_link'] ?? '' ),
					'cta_view'    => 'start',
					'open_share'  => true,
					'cta_label'   => __( 'Copy Organizer Link', 'lnkup-marketplace' ),
					'title'       => __( 'Copy organizer referral link', 'lnkup-marketplace' ),
					'description' => __( 'This tracked link is how LNKup knows an organizer came from you.', 'lnkup-marketplace' ),
					'why'         => __( 'No link means no attribution.', 'lnkup-marketplace' ),
				)
			),
			$step(
				array(
					'id'          => 'copied_vendor_link',
					'done'        => ! empty( $meta['copied_vendor_link'] ),
					'completed_at'=> (string) ( $meta['copied_vendor_link'] ?? '' ),
					'cta_view'    => 'start',
					'open_share'  => true,
					'cta_label'   => __( 'Copy Vendor Link', 'lnkup-marketplace' ),
					'title'       => __( 'Copy vendor referral link', 'lnkup-marketplace' ),
					'description' => __( 'Vendor signups are tracked. They do not earn commission unless a vendor reward rule is on.', 'lnkup-marketplace' ),
					'why'         => __( 'Vendors still grow the marketplace even when they are not commissionable.', 'lnkup-marketplace' ),
				)
			),
			$step(
				array(
					'id'          => 'reviewed_earnings',
					'done'        => ! empty( $meta['reviewed_earnings'] ) || ! empty( $meta['reviewed_targets'] ),
					'completed_at'=> (string) ( $meta['reviewed_earnings'] ?? $meta['reviewed_targets'] ?? '' ),
					'cta_view'    => 'earnings',
					'cta_label'   => __( 'Learn How Earnings Work', 'lnkup-marketplace' ),
					'title'       => __( 'Review how earnings work', 'lnkup-marketplace' ),
					'description' => __( 'Commission is a share of qualifying booth GMV. Signups alone do not pay.', 'lnkup-marketplace' ),
					'why'         => __( 'Know what is estimated versus payable before you promote LNKup.', 'lnkup-marketplace' ),
					'eta'         => '4 min',
				)
			),
		);

		$phase2 = array(
			$step(
				array(
					'id'          => 'sent_intro',
					'done'        => ! empty( $meta['sent_intro'] ),
					'completed_at'=> (string) ( $meta['sent_intro'] ?? '' ),
					'open_share'  => true,
					'cta_label'   => __( 'Copy DM Script', 'lnkup-marketplace' ),
					'title'       => __( 'Send an organizer or vendor introduction', 'lnkup-marketplace' ),
					'description' => __( 'Copy a script, append your link, and send a real message.', 'lnkup-marketplace' ),
					'why'         => __( 'Direct introductions still convert relationship connectors.', 'lnkup-marketplace' ),
					'eta'         => '10 min',
					'status'      => $phase1_done ? 'available' : 'locked',
					'unlock'      => __( 'Finish Set Up first.', 'lnkup-marketplace' ),
				)
			),
			$step(
				array(
					'id'          => 'published_video',
					'done'        => ! empty( $meta['published_video'] ),
					'completed_at'=> (string) ( $meta['published_video'] ?? '' ),
					'cta_view'    => 'studio',
					'open_studio' => true,
					'cta_label'   => __( 'Create Your First Reel', 'lnkup-marketplace' ),
					'title'       => __( 'Publish a social video', 'lnkup-marketplace' ),
					'description' => __( 'Film a short video, then submit the published URL in Content Studio.', 'lnkup-marketplace' ),
					'why'         => __( 'Reels and TikToks reach people who will never open a DM.', 'lnkup-marketplace' ),
					'eta'         => '30 min',
					'status'      => $phase1_done ? 'available' : 'locked',
					'unlock'      => __( 'Finish Set Up first.', 'lnkup-marketplace' ),
				)
			),
			$step(
				array(
					'id'          => 'shared_story',
					'done'        => ! empty( $meta['shared_story'] ),
					'completed_at'=> (string) ( $meta['shared_story'] ?? '' ),
					'cta_view'    => 'studio',
					'open_studio' => true,
					'cta_label'   => __( 'Build a Story', 'lnkup-marketplace' ),
					'title'       => __( 'Share LNKup through a Story', 'lnkup-marketplace' ),
					'description' => __( 'Three frames: problem, what LNKup does, referral-link CTA.', 'lnkup-marketplace' ),
					'why'         => __( 'Stories are the fastest place to put a tappable link.', 'lnkup-marketplace' ),
					'eta'         => '8 min',
					'status'      => $phase1_done ? 'available' : 'locked',
					'unlock'      => __( 'Finish Set Up first.', 'lnkup-marketplace' ),
				)
			),
			$step(
				array(
					'id'          => 'posted_group',
					'done'        => ! empty( $meta['posted_group'] ),
					'completed_at'=> (string) ( $meta['posted_group'] ?? '' ),
					'cta_view'    => 'resources',
					'cta_label'   => __( 'Copy Group Post', 'lnkup-marketplace' ),
					'title'       => __( 'Post in a Facebook group or local community', 'lnkup-marketplace' ),
					'description' => __( 'Share a community-oriented explainer. Do not spam groups.', 'lnkup-marketplace' ),
					'why'         => __( 'Local groups already gather vendors and organizers.', 'lnkup-marketplace' ),
					'eta'         => '12 min',
					'status'      => $phase1_done ? 'available' : 'locked',
					'unlock'      => __( 'Finish Set Up first.', 'lnkup-marketplace' ),
				)
			),
			$step(
				array(
					'id'          => 'city_call',
					'done'        => ! empty( $meta['city_call'] ),
					'completed_at'=> (string) ( $meta['city_call'] ?? '' ),
					'cta_view'    => 'studio',
					'open_studio' => true,
					'cta_label'   => __( 'Share City Call', 'lnkup-marketplace' ),
					'title'       => __( 'Share a city-specific organizer or vendor call', 'lnkup-marketplace' ),
					'description' => __( 'Name your city and the types of events you are recruiting for.', 'lnkup-marketplace' ),
					'why'         => __( 'Local specificity outperforms generic “join our app” posts.', 'lnkup-marketplace' ),
					'eta'         => '15 min',
					'status'      => $phase1_done ? 'available' : 'locked',
					'unlock'      => __( 'Finish Set Up first.', 'lnkup-marketplace' ),
				)
			),
		);
		$promo_any = false;
		foreach ( $phase2 as $item ) {
			if ( ! empty( $item['done'] ) ) {
				$promo_any = true;
				break;
			}
		}

		$phase3 = array(
			$step(
				array(
					'id'         => 'first_visit',
					'done'       => $clicks > 0,
					'auto'       => true,
					'cta_view'   => 'referrals',
					'cta_label'  => __( 'View Your Clicks', 'lnkup-marketplace' ),
					'title'      => __( 'Generate first tracked visit', 'lnkup-marketplace' ),
					'description'=> __( 'Someone opened a referral link tagged to you.', 'lnkup-marketplace' ),
					'status'     => $clicks > 0 ? 'complete' : 'waiting',
					'unlock'     => __( 'Completed from real link clicks only.', 'lnkup-marketplace' ),
				)
			),
			$step(
				array(
					'id'         => 'organizer_signup',
					'done'       => (int) ( $row['total_signups'] ?? 0 ) > 0,
					'auto'       => true,
					'cta_view'   => 'referrals',
					'cta_label'  => __( 'View Your Registrations', 'lnkup-marketplace' ),
					'title'      => __( 'Generate first registration', 'lnkup-marketplace' ),
					'description'=> __( 'An organizer or vendor created an account through your link.', 'lnkup-marketplace' ),
					'status'     => (int) ( $row['total_signups'] ?? 0 ) > 0 ? 'complete' : 'waiting',
					'unlock'     => __( 'Completed from real signups only.', 'lnkup-marketplace' ),
				)
			),
			$step(
				array(
					'id'         => 'first_activation',
					'done'       => $activated > 0,
					'auto'       => true,
					'cta_view'   => 'referrals',
					'cta_label'  => __( 'View Referrals', 'lnkup-marketplace' ),
					'title'      => __( 'Activate first organizer', 'lnkup-marketplace' ),
					'description'=> __( 'Verified + published opportunity + three qualifying bookings.', 'lnkup-marketplace' ),
					'status'     => $activated > 0 ? 'complete' : 'waiting',
					'unlock'     => __( 'Completed from tracked organizer activity only.', 'lnkup-marketplace' ),
				)
			),
			$step(
				array(
					'id'         => 'first_booking',
					'done'       => (int) ( $row['paid'] ?? 0 ) > 0,
					'auto'       => true,
					'cta_view'   => 'earnings',
					'cta_label'  => __( 'View Earnings', 'lnkup-marketplace' ),
					'title'      => __( 'Generate first qualifying vendor booking', 'lnkup-marketplace' ),
					'description'=> __( 'Only completed, settled booth payments qualify.', 'lnkup-marketplace' ),
					'status'     => (int) ( $row['paid'] ?? 0 ) > 0 ? 'complete' : 'waiting',
					'unlock'     => __( 'Completed from qualifying payments only.', 'lnkup-marketplace' ),
				)
			),
			$step(
				array(
					'id'         => 'payout_eligible',
					'done'       => $payable >= (float) $terms['minimum_payout'],
					'auto'       => true,
					'cta_view'   => 'earnings',
					'cta_label'  => __( 'View Earnings', 'lnkup-marketplace' ),
					'title'      => __( 'Become eligible for first payout', 'lnkup-marketplace' ),
					'description'=> sprintf(
						/* translators: %s dollar amount */
						__( 'Approved and held earnings must reach the %s minimum. Admin still reviews each reward.', 'lnkup-marketplace' ),
						'$' . number_format_i18n( (float) $terms['minimum_payout'], 0 )
					),
					'status'     => $payable >= (float) $terms['minimum_payout'] ? 'complete' : 'waiting',
					'unlock'     => __( 'Completed from approved earnings only.', 'lnkup-marketplace' ),
				)
			),
			$step(
				array(
					'id'         => 'next_rank',
					'done'       => $has_rank,
					'auto'       => true,
					'cta_view'   => 'ranks',
					'cta_label'  => __( 'View ranks', 'lnkup-marketplace' ),
					'title'      => __( 'Advance toward the next connector rank', 'lnkup-marketplace' ),
					'description'=> __( 'Ranks never raise your commission. They track verified activity.', 'lnkup-marketplace' ),
					'status'     => $has_rank ? 'complete' : 'waiting',
					'unlock'     => __( 'Completed when Rank 1 is earned from Phase 1.', 'lnkup-marketplace' ),
				)
			),
		);

		$p1_done_n = 0;
		foreach ( $phase1 as $item ) {
			if ( ! empty( $item['done'] ) ) {
				++$p1_done_n;
			}
		}
		if ( $phase1_done ) {
			$p1_done_n = count( $phase1 );
		}
		$required_total = count( $phase1 );
		$required_done  = $p1_done_n;
		$progress       = (int) round( ( $required_done / max( 1, $required_total ) ) * 100 );

		$current_phase = 1;
		if ( $phase1_done && $promo_any ) {
			$current_phase = 3;
		} elseif ( $phase1_done ) {
			$current_phase = 2;
		}

		$current = self::current_launch_action( $phase1, $phase2, $phase3, $styles, $phase1_done );
		$steps   = array_merge( $phase1, $phase2, $phase3 );
		$steps[] = array(
			'id'          => 'reviewed_targets',
			'done'        => ! empty( $meta['reviewed_targets'] ) || ! empty( $meta['reviewed_earnings'] ),
			'auto'        => false,
			'cta_view'    => 'earnings',
			'title'       => __( 'Review how earnings work', 'lnkup-marketplace' ),
			'description' => __( 'Legacy step id kept for existing dashboards.', 'lnkup-marketplace' ),
		);
		$steps[] = array(
			'id'          => 'opportunity',
			'done'        => $activated > 0 || (int) ( $row['opportunities'] ?? 0 ) > 0,
			'auto'        => true,
			'cta_view'    => 'referrals',
			'title'       => __( 'Activate your first organizer', 'lnkup-marketplace' ),
			'description' => __( 'Legacy step id. Rank activation needs three qualifying bookings.', 'lnkup-marketplace' ),
		);

		$done_all = 0;
		foreach ( $steps as $s ) {
			if ( ! empty( $s['done'] ) ) {
				++$done_all;
			}
		}

		return array(
			'progress'       => $progress,
			'done'           => $required_done,
			'total'          => $required_total,
			'required_done'  => $required_done,
			'required_total' => $required_total,
			'default_view'   => $phase1_done ? 'home' : 'start',
			'steps'          => $steps,
			'phases'         => array(
				array(
					'id'          => 1,
					'key'         => 'setup',
					'name'        => __( 'Set Up', 'lnkup-marketplace' ),
					'label'       => __( 'Phase 1 of 3: Set Up', 'lnkup-marketplace' ),
					'required'    => true,
					'complete'    => $phase1_done,
					'steps'       => $phase1,
				),
				array(
					'id'          => 2,
					'key'         => 'promote',
					'name'        => __( 'Promote LNKup', 'lnkup-marketplace' ),
					'label'       => __( 'Phase 2 of 3: Promote LNKup', 'lnkup-marketplace' ),
					'required'    => false,
					'complete'    => $promo_any,
					'any_one'     => true,
					'steps'       => $phase2,
				),
				array(
					'id'          => 3,
					'key'         => 'activate',
					'name'        => __( 'Activate and Earn', 'lnkup-marketplace' ),
					'label'       => __( 'Phase 3 of 3: Activate and Earn', 'lnkup-marketplace' ),
					'required'    => false,
					'complete'    => false,
					'auto'        => true,
					'steps'       => $phase3,
				),
			),
			'current_phase'  => $current_phase,
			'phase1_complete'=> $phase1_done,
			'promo_complete' => $promo_any,
			'growth_styles'  => $styles,
			'current_action' => $current,
			'rank'           => array(
				'key'  => (string) ( $connector['current_rank'] ?? '' ),
				'name' => (string) ( $connector['current_rank'] ?? '' ),
			),
			'all_done'       => $done_all,
		);
	}

	/**
	 * One next launch action (PHP is the source of labels).
	 *
	 * @param array $phase1        Phase 1 steps.
	 * @param array $phase2        Phase 2 steps.
	 * @param array $phase3        Phase 3 steps.
	 * @param array $styles        Growth styles.
	 * @param bool  $phase1_done   Phase 1 complete.
	 */
	public static function current_launch_action( $phase1, $phase2, $phase3, $styles, $phase1_done ) {
		if ( ! $phase1_done ) {
			foreach ( $phase1 as $item ) {
				if ( empty( $item['done'] ) ) {
					return $item;
				}
			}
		}
		$order = array( 'sent_intro', 'published_video', 'shared_story', 'posted_group', 'city_call' );
		if ( in_array( 'video', $styles, true ) ) {
			$order = array( 'published_video', 'shared_story', 'sent_intro', 'posted_group', 'city_call' );
		} elseif ( in_array( 'relationship', $styles, true ) ) {
			$order = array( 'sent_intro', 'city_call', 'posted_group', 'published_video', 'shared_story' );
		} elseif ( in_array( 'community', $styles, true ) ) {
			$order = array( 'posted_group', 'sent_intro', 'shared_story', 'published_video', 'city_call' );
		} elseif ( in_array( 'event', $styles, true ) ) {
			$order = array( 'city_call', 'sent_intro', 'published_video', 'posted_group', 'shared_story' );
		}
		$by_id = array();
		foreach ( $phase2 as $item ) {
			$by_id[ $item['id'] ] = $item;
		}
		foreach ( $order as $id ) {
			if ( ! empty( $by_id[ $id ] ) && empty( $by_id[ $id ]['done'] ) ) {
				return $by_id[ $id ];
			}
		}
		foreach ( $phase3 as $item ) {
			if ( empty( $item['done'] ) ) {
				return $item;
			}
		}
		return array();
	}

	/**
	 * Persist a self-report onboarding step.
	 *
	 * @param int    $connector_id Connector ID.
	 * @param string $step         copied_link|reviewed_targets|sent_intro.
	 */
	public static function mark_onboarding_step( $connector_id, $step ) {
		$connector = self::get( $connector_id );
		if ( ! $connector ) {
			return new WP_Error( 'not_found', __( 'Connector not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$step = sanitize_key( $step );
		$allowed = array(
			'copied_link',
			'copied_vendor_link',
			'reviewed_targets',
			'reviewed_earnings',
			'sent_intro',
			'published_video',
			'shared_story',
			'posted_group',
			'city_call',
		);
		if ( ! in_array( $step, $allowed, true ) ) {
			return new WP_Error( 'invalid_step', __( 'That step cannot be marked manually.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$user_id = (int) ( $connector['user_id'] ?? 0 );
		$meta    = (array) get_user_meta( $user_id, '_lnkup_connector_onboarding', true );
		$meta[ $step ] = current_time( 'mysql' );
		if ( 'reviewed_earnings' === $step && empty( $meta['reviewed_targets'] ) ) {
			$meta['reviewed_targets'] = $meta[ $step ];
		}
		if ( 'reviewed_targets' === $step && empty( $meta['reviewed_earnings'] ) ) {
			$meta['reviewed_earnings'] = $meta[ $step ];
		}
		update_user_meta( $user_id, '_lnkup_connector_onboarding', $meta );
		if ( class_exists( 'LNKup_Connector_Ranks' ) ) {
			LNKup_Connector_Ranks::recalculate( $connector_id, 'onboarding' );
		}
		return self::get_onboarding( $connector_id );
	}

	/**
	 * Count completed paid booths for an organizer user.
	 *
	 * @param int $organizer_user_id Organizer user ID.
	 */
	public static function count_organizer_paid_bookings( $organizer_user_id ) {
		$organizer_user_id = absint( $organizer_user_id );
		if ( ! $organizer_user_id ) {
			return 0;
		}
		$q = new WP_Query(
			array(
				'post_type'      => 'lnkup_payment',
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_lnkup_organizer_user_id',
						'value' => $organizer_user_id,
					),
					array(
						'key'   => '_lnkup_payment_status',
						'value' => 'paid',
					),
				),
			)
		);
		return count( $q->posts );
	}

	/**
	 * Whether a referred organizer is verified.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function organizer_is_verified( $user_id ) {
		if ( class_exists( 'LNKup_Verification' ) && method_exists( 'LNKup_Verification', 'get_organizer_verification_status' ) ) {
			$status = LNKup_Verification::get_organizer_verification_status( $user_id );
			$level  = is_array( $status ) ? ( $status['level'] ?? '' ) : '';
			if ( $level && 'unverified' !== $level && 'none' !== $level ) {
				return true;
			}
		}
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $user_id,
			)
		);
		return ! empty( $events );
	}

	/**
	 * Organizer activation flags.
	 *
	 * @param array $referral Referral row.
	 */
	public static function organizer_activation( $referral ) {
		$user_id   = (int) ( $referral['referred_user_id'] ?? 0 );
		$verified  = $user_id ? self::organizer_is_verified( $user_id ) : false;
		$published = ! empty( $referral['first_opportunity_at'] );
		$paid      = self::count_organizer_paid_bookings( $user_id );
		$first_pay = ! empty( $referral['first_payment_at'] );
		return array(
			'registered'           => true,
			'verified'             => $verified,
			'published_opportunity'=> $published,
			'first_payment'        => $first_pay,
			'completed_payments'   => $paid,
			'activated'            => $verified && $published && $paid >= 3,
		);
	}

	/**
	 * Count activated organizers for a connector.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function count_activated_organizers( $connector_id ) {
		$funnel = self::get_funnel( $connector_id );
		$n      = 0;
		foreach ( $funnel['organizers'] as $card ) {
			if ( ! empty( $card['activation']['activated'] ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Organizer referral funnel cards (no private contact or financials).
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function get_funnel( $connector_id ) {
		global $wpdb;
		$code = self::get_code_for_connector( $connector_id );
		if ( ! $code ) {
			return array(
				'organizers' => array(),
				'vendors'    => array(),
				'counts'     => array(
					'clicks'        => 0,
					'registrations' => 0,
					'opportunities' => 0,
					'activated'     => 0,
					'bookings'      => LNKup_Connector_Rewards::count_unique_payments( $connector_id ),
				),
			);
		}
		$ref_table = LNKup_Treasury_Tables::referrals_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$ref_table} WHERE program = %s AND referral_code_id = %d ORDER BY created_at DESC LIMIT 100",
				(string) ( $code['program'] ?? self::PROGRAM ),
				(int) $code['id']
			),
			ARRAY_A
		);
		$events = LNKup_Connector_Tables::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$clicks = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$events} WHERE connector_id = %d AND event_type = 'clicked'",
				absint( $connector_id )
			)
		);
		$organizers = array();
		$vendors    = array();
		$opps       = 0;
		$activated  = 0;
		$rewards    = LNKup_Connector_Rewards::list_for_connector( $connector_id );
		$by_ref     = array();
		foreach ( $rewards as $reward ) {
			$rid = (int) ( $reward['referral_id'] ?? 0 );
			if ( ! isset( $by_ref[ $rid ] ) ) {
				$by_ref[ $rid ] = array( 'estimated' => 0.0, 'approved' => 0.0, 'pending' => false, 'paid' => false );
			}
			$st = $reward['display_status'];
			$amt = (float) $reward['amount'];
			if ( in_array( $st, array( 'approved', 'paid' ), true ) ) {
				$by_ref[ $rid ]['approved'] += $amt;
			}
			if ( 'paid' === $st ) {
				$by_ref[ $rid ]['paid'] = true;
			}
			if ( in_array( $st, array( 'pending_hold', 'awaiting_approval' ), true ) ) {
				$by_ref[ $rid ]['pending'] = true;
			}
		}
		foreach ( (array) $rows as $row ) {
			$role = (string) ( $row['target_role'] ?? '' );
			$is_org = false !== strpos( $role, 'organizer' );
			$user = get_userdata( (int) $row['referred_user_id'] );
			$city = '';
			if ( $user ) {
				$city = (string) get_user_meta( $user->ID, '_lnkup_city', true );
			}
			$activation = $is_org ? self::organizer_activation( $row ) : array();
			if ( $is_org && ! empty( $row['first_opportunity_at'] ) ) {
				++$opps;
			}
			if ( $is_org && ! empty( $activation['activated'] ) ) {
				++$activated;
			}
			$gmv = (float) ( $row['attributed_revenue'] ?? 0 );
			$earn = isset( $by_ref[ (int) $row['id'] ] ) ? $by_ref[ (int) $row['id'] ] : array( 'estimated' => 0.0, 'approved' => 0.0, 'pending' => false, 'paid' => false );
			$card = array(
				'id'                 => (int) $row['id'],
				'display_name'       => $user ? $user->display_name : __( 'Organizer', 'lnkup-marketplace' ),
				'city'               => $city,
				'referral_date'      => $row['created_at'] ?? '',
				'milestone'          => self::referral_pipeline_milestone( $row, $activation, $earn ),
				'qualifying_gmv'     => $gmv,
				'estimated_earnings' => LNKup_Connector_Rewards::estimate_from_gmv_total( $gmv, $connector_id ),
				'approved_earnings'  => (float) $earn['approved'],
				'remaining_term'     => LNKup_Connector_Rewards::remaining_term_label( $row ),
				'last_activity'      => $row['first_payment_at'] ?: ( $row['first_opportunity_at'] ?: ( $row['created_at'] ?? '' ) ),
				'activation'         => $activation,
				'kind'               => $is_org ? 'organizer' : 'vendor',
				'reward_eligible'    => ! empty( $earn['pending'] ) || ! empty( $earn['paid'] ) || (float) $earn['approved'] > 0,
			);
			if ( $is_org ) {
				$organizers[] = $card;
			} else {
				$card['display_name'] = $user ? $user->display_name : __( 'Vendor', 'lnkup-marketplace' );
				$vendors[] = $card;
			}
		}
		return array(
			'organizers' => $organizers,
			'vendors'    => $vendors,
			'counts'     => array(
				'clicks'        => $clicks,
				'registrations' => count( $organizers ),
				'opportunities' => $opps,
				'activated'     => $activated,
				'bookings'      => LNKup_Connector_Rewards::count_unique_payments( $connector_id ),
			),
			'vendor_referrals_rewarded' => ! empty( LNKup_Connector_Rewards::terms()['vendor_referrals_rewarded'] ),
		);
	}

	/**
	 * Pipeline milestone label for an organizer card.
	 *
	 * @param array $row        Referral row.
	 * @param array $activation Activation flags.
	 * @param array $earn       Reward flags.
	 */
	public static function referral_pipeline_milestone( $row, $activation, $earn ) {
		if ( ! empty( $earn['paid'] ) ) {
			return 'reward_paid';
		}
		if ( ! empty( $earn['pending'] ) ) {
			return 'reward_pending';
		}
		if ( ! empty( $activation['activated'] ) ) {
			return 'activated';
		}
		if ( ! empty( $activation['first_payment'] ) || ! empty( $row['first_payment_at'] ) ) {
			return 'first_payment';
		}
		if ( ! empty( $activation['published_opportunity'] ) ) {
			return 'opportunity_published';
		}
		if ( ! empty( $activation['verified'] ) ) {
			return 'verified';
		}
		if ( ! empty( $row['profile_completed_at'] ) ) {
			return 'profile_completed';
		}
		return 'registered';
	}

	/**
	 * Earnings payload for the Earnings view.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function get_earnings( $connector_id ) {
		global $wpdb;
		$ledger  = LNKup_Connector_Rewards::list_for_connector( $connector_id );
		$next    = '';
		$next_ts = 0;
		$ref_table = LNKup_Treasury_Tables::referrals_table();
		foreach ( $ledger as $i => $row ) {
			$ref_id = (int) ( $row['referral_id'] ?? 0 );
			$ledger[ $i ]['related_name'] = '';
			if ( $ref_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ref = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT referred_user_id FROM {$ref_table} WHERE id = %d",
						$ref_id
					),
					ARRAY_A
				);
				if ( $ref ) {
					$user = get_userdata( (int) $ref['referred_user_id'] );
					$ledger[ $i ]['related_name'] = $user ? $user->display_name : '';
				}
			}
			$pay_id = (int) ( $row['payment_id'] ?? 0 );
			$ledger[ $i ]['booking_amount'] = $pay_id ? LNKup_Connector_Rewards::booth_gmv( $pay_id ) : 0;
			$status = $row['display_status'] ?? '';
			$avail  = strtotime( (string) ( $row['available_at'] ?? '' ) );
			if ( in_array( $status, array( 'pending_hold', 'awaiting_approval', 'approved' ), true ) && $avail && ( ! $next_ts || $avail < $next_ts ) ) {
				$next_ts = $avail;
				$next    = date_i18n( get_option( 'date_format' ), $avail );
			}
		}
		$balances = LNKup_Connector_Rewards::balances_for_connector( $connector_id );
		$gmv      = 0.0;
		foreach ( $ledger as $row ) {
			$gmv += (float) ( $row['booking_amount'] ?? 0 );
		}
		$balances['estimated'] = LNKup_Connector_Rewards::estimate_from_gmv_total( $gmv, $connector_id );
		return array(
			'terms'       => LNKup_Connector_Rewards::terms( $connector_id ),
			'balances'    => $balances,
			'ledger'      => $ledger,
			'example'     => LNKup_Connector_Rewards::estimate_earnings( 250, 1, 1, $connector_id ),
			'next_payout' => $next ? $next : __( 'After LNKup approval', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Static resources copy (who to target, weekly plan, FAQs).
	 */
	public static function get_resources( $connector_id = 0 ) {
		$terms = LNKup_Connector_Rewards::terms();
		$styles = array();
		$city   = '';
		if ( $connector_id ) {
			$row = self::get( $connector_id );
			$styles = $row ? self::growth_styles_for( $row ) : array();
			$city   = $row ? trim( (string) ( $row['city'] ?? '' ) ) : '';
		}
		$groups = array(
			'videos' => array(
				'label' => __( 'Videos', 'lnkup-marketplace' ),
				'items' => array(
					__( 'Open Content Studio and film a 30-second talking-head video.', 'lnkup-marketplace' ),
					__( 'Submit the published URL so we can attach referral clicks.', 'lnkup-marketplace' ),
				),
			),
			'direct_outreach' => array(
				'label' => __( 'Direct outreach', 'lnkup-marketplace' ),
				'items' => array(
					__( 'DM, text, or email an organizer with your tracked link.', 'lnkup-marketplace' ),
					__( 'Follow up once after three days if they have not registered.', 'lnkup-marketplace' ),
				),
			),
			'social_posts' => array(
				'label' => __( 'Social posts', 'lnkup-marketplace' ),
				'items' => array(
					__( 'Post a Reel or TikTok that names your city.', 'lnkup-marketplace' ),
					__( 'Pin a comment with your referral link.', 'lnkup-marketplace' ),
				),
			),
			'stories' => array(
				'label' => __( 'Stories', 'lnkup-marketplace' ),
				'items' => array(
					__( 'Frame 1: local problem. Frame 2: what LNKup does. Frame 3: link sticker.', 'lnkup-marketplace' ),
				),
			),
			'facebook_groups' => array(
				'label' => __( 'Facebook groups', 'lnkup-marketplace' ),
				'items' => array(
					__( 'Share a community explainer, not a hard sell.', 'lnkup-marketplace' ),
				),
			),
			'in_person' => array(
				'label' => __( 'In-person outreach', 'lnkup-marketplace' ),
				'items' => array(
					__( 'Talk to vendors at markets and festivals. Share your vendor link.', 'lnkup-marketplace' ),
				),
			),
			'brand' => array(
				'label' => __( 'Brand assets', 'lnkup-marketplace' ),
				'items' => array(
					__( 'Use LNKup black, white, and lime. Do not invent metrics.', 'lnkup-marketplace' ),
				),
			),
			'education' => array(
				'label' => __( 'Program education', 'lnkup-marketplace' ),
				'items' => array(
					__( 'Earnings are performance-based. There is no guaranteed income.', 'lnkup-marketplace' ),
				),
			),
		);
		if ( in_array( 'video', $styles, true ) ) {
			$order = array( 'videos', 'social_posts', 'stories', 'direct_outreach', 'facebook_groups', 'in_person', 'brand', 'education' );
		} elseif ( in_array( 'community', $styles, true ) ) {
			$order = array( 'facebook_groups', 'social_posts', 'direct_outreach', 'videos', 'stories', 'in_person', 'brand', 'education' );
		} elseif ( in_array( 'event', $styles, true ) ) {
			$order = array( 'in_person', 'direct_outreach', 'videos', 'social_posts', 'stories', 'facebook_groups', 'brand', 'education' );
		} else {
			$order = array( 'direct_outreach', 'videos', 'social_posts', 'stories', 'facebook_groups', 'in_person', 'brand', 'education' );
		}
		$sorted = array();
		foreach ( $order as $key ) {
			if ( isset( $groups[ $key ] ) ) {
				$sorted[ $key ] = $groups[ $key ];
			}
		}
		$scripts = array(
			array(
				'id'       => 'ig',
				'group'    => 'direct_outreach',
				'label'    => __( 'Instagram DM — organizer', 'lnkup-marketplace' ),
				'audience' => 'organizer',
				'body'     => __( "Hey — I help organizers in {city} put vendor applications in one place instead of DMs and spreadsheets. LNKup lets you publish a booth opportunity, review vendors, and collect docs. Ticketing can stay where it is. Here's my link: {organizer}", 'lnkup-marketplace' ),
			),
			array(
				'id'       => 'email',
				'group'    => 'direct_outreach',
				'label'    => __( 'Email — organizer', 'lnkup-marketplace' ),
				'audience' => 'organizer',
				'body'     => __( "Subject: Vendor applications without the inbox chaos\n\nI am a LNKup City Connector in {city}. Organizers publish vendor opportunities, review applicants, and track payments in one dashboard. Use this link to create your organizer account: {organizer}", 'lnkup-marketplace' ),
			),
			array(
				'id'       => 'text',
				'group'    => 'direct_outreach',
				'label'    => __( 'Text — organizer', 'lnkup-marketplace' ),
				'audience' => 'organizer',
				'body'     => __( "Quick one — LNKup is the vendor marketplace for events in {city}. Publish an opportunity instead of collecting forms by hand: {organizer}", 'lnkup-marketplace' ),
			),
			array(
				'id'       => 'vendor-ig',
				'group'    => 'direct_outreach',
				'label'    => __( 'Instagram DM — vendor', 'lnkup-marketplace' ),
				'audience' => 'vendor',
				'body'     => __( "If you keep finding events after applications close, LNKup lists legitimate booth opportunities in one place. Apply, track status, and pay in one flow: {vendor}", 'lnkup-marketplace' ),
			),
			array(
				'id'       => 'vendor-text',
				'group'    => 'direct_outreach',
				'label'    => __( 'Text — vendor', 'lnkup-marketplace' ),
				'audience' => 'vendor',
				'body'     => __( "LNKup is where vendors in {city} find booth opportunities without chasing 10 different forms: {vendor}", 'lnkup-marketplace' ),
			),
			array(
				'id'       => 'vendor-email',
				'group'    => 'direct_outreach',
				'label'    => __( 'Email — vendor', 'lnkup-marketplace' ),
				'audience' => 'vendor',
				'body'     => __( "Subject: Booth opportunities in {city}\n\nLNKup lists vendor opportunities from organizers who actually review applications. Create your profile here: {vendor}", 'lnkup-marketplace' ),
			),
			array(
				'id'       => 'group-post',
				'group'    => 'facebook_groups',
				'label'    => __( 'Facebook group post', 'lnkup-marketplace' ),
				'audience' => 'both',
				'body'     => __( "Local organizers and vendors in {city} — LNKup is a marketplace for booth opportunities (not tickets). Organizers publish one listing. Vendors apply once. Here's the organizer link {organizer} and vendor link {vendor}.", 'lnkup-marketplace' ),
			),
		);
		return array(
			'terms'        => $terms,
			'growth_styles'=> $styles,
			'city'         => $city,
			'groups'       => $sorted,
			'scripts'      => $scripts,
			'targets'      => array(
				__( 'Festivals and block parties', 'lnkup-marketplace' ),
				__( 'Pop-up and farmers markets', 'lnkup-marketplace' ),
				__( 'Food-truck events', 'lnkup-marketplace' ),
				__( 'HBCU and university events', 'lnkup-marketplace' ),
				__( 'Conferences and expos', 'lnkup-marketplace' ),
				__( 'Chambers of commerce', 'lnkup-marketplace' ),
				__( 'Municipal and community events', 'lnkup-marketplace' ),
				__( 'Cultural organizations', 'lnkup-marketplace' ),
				__( 'Venues hosting recurring markets', 'lnkup-marketplace' ),
				__( 'Event promoters producing outdoor or daytime experiences', 'lnkup-marketplace' ),
			),
			'weekly_plan'  => array(
				__( 'Identify 25 qualified organizers', 'lnkup-marketplace' ),
				__( 'Send 10 personalized introductions', 'lnkup-marketplace' ),
				__( 'Complete five follow-ups', 'lnkup-marketplace' ),
				__( 'Secure three organizer conversations', 'lnkup-marketplace' ),
				__( 'Activate at least one organizer', 'lnkup-marketplace' ),
				__( 'Review referral and earnings statuses', 'lnkup-marketplace' ),
			),
			'faqs'         => array(
				array(
					'q' => __( 'Do I get a base payment?', 'lnkup-marketplace' ),
					'a' => __( 'No. Founding City Partner is performance-based. There is no upfront or guaranteed income.', 'lnkup-marketplace' ),
				),
				array(
					'q' => __( 'Does a signup earn commission?', 'lnkup-marketplace' ),
					'a' => __( 'No. Raw registrations do not earn. The organizer must publish an opportunity and vendors must complete qualifying booth payments.', 'lnkup-marketplace' ),
				),
				array(
					'q' => __( 'When does the 12-month term start?', 'lnkup-marketplace' ),
					'a' => __( 'It starts with that organizer’s first completed vendor booking, not the day they sign up.', 'lnkup-marketplace' ),
				),
				array(
					'q' => __( 'Why is a reward on hold?', 'lnkup-marketplace' ),
					'a' => __( 'Completed payments sit in a dispute and refund hold before LNKup can approve them.', 'lnkup-marketplace' ),
				),
			),
			'support_url'  => $terms['support_url'],
			'terms_url'    => $terms['terms_url'],
		);
	}

	/**
	 * Update connector profile fields the connector is allowed to edit.
	 *
	 * @param int   $connector_id Connector ID.
	 * @param array $input        Posted fields.
	 */
	public static function update_profile( $connector_id, $input ) {
		$connector = self::get( $connector_id );
		if ( ! $connector ) {
			return new WP_Error( 'not_found', __( 'Connector not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		global $wpdb;
		$data = array(
			'display_name'      => sanitize_text_field( (string) ( $input['display_name'] ?? $connector['display_name'] ) ),
			'phone'             => sanitize_text_field( (string) ( $input['phone'] ?? $connector['phone'] ) ),
			'social_url'        => esc_url_raw( (string) ( $input['social_url'] ?? $connector['social_url'] ) ),
			'payout_preference' => sanitize_key( (string) ( $input['payout_preference'] ?? $connector['payout_preference'] ) ),
			'updated_at'        => current_time( 'mysql' ),
		);
		if ( isset( $input['city'] ) ) {
			$data['city'] = sanitize_text_field( (string) $input['city'] );
		}
		if ( isset( $input['bio'] ) ) {
			$data['bio'] = sanitize_textarea_field( (string) $input['bio'] );
		}
		if ( isset( $input['leaderboard_opt_in'] ) ) {
			$data['leaderboard_opt_in'] = empty( $input['leaderboard_opt_in'] ) || '0' === (string) $input['leaderboard_opt_in'] ? 0 : 1;
		}
		if ( isset( $input['growth_styles'] ) ) {
			$data['growth_styles'] = wp_json_encode( self::parse_growth_styles( $input['growth_styles'] ) );
		}
		$wpdb->update(
			LNKup_Connector_Tables::connectors_table(),
			$data,
			array( 'id' => absint( $connector_id ) )
		);
		return self::public_connector( self::get( $connector_id ) );
	}

	/**
	 * Safe connector row for dashboard JSON (avatar, no admin notes).
	 *
	 * @param array|null $connector Connector row.
	 */
	public static function public_connector( $connector ) {
		if ( ! $connector ) {
			return null;
		}
		$user_id = (int) ( $connector['user_id'] ?? 0 );
		$name    = trim( (string) ( $connector['display_name'] ?? '' ) );
		$initials = '';
		if ( $name ) {
			$parts = preg_split( '/\s+/', $name );
			$initials = strtoupper( substr( $parts[0], 0, 1 ) . ( isset( $parts[1] ) ? substr( $parts[1], 0, 1 ) : '' ) );
		}
		unset( $connector['admin_notes'], $connector['email'] );
		$connector['initials']   = $initials ?: 'CC';
		$connector['avatar_url'] = $user_id ? get_avatar_url( $user_id, array( 'size' => 96 ) ) : '';
		$connector['growth_styles'] = self::growth_styles_for( $connector );
		$connector['leaderboard_opt_in'] = ! empty( $connector['leaderboard_opt_in'] );
		return $connector;
	}

	/**
	 * Next best action from real onboarding and earnings state.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function next_best_action( $connector_id ) {
		$onboarding = self::get_onboarding( $connector_id );
		$current    = $onboarding['current_action'] ?? array();
		if ( ! empty( $current['id'] ) && empty( $current['auto'] ) ) {
			return array(
				'id'          => $current['id'],
				'title'       => $current['title'],
				'description' => $current['why'] ?: $current['description'],
				'cta_view'    => $current['cta_view'] ?? 'start',
				'cta_label'   => $current['cta_label'] ?: __( 'Continue Setup', 'lnkup-marketplace' ),
				'open_share'  => ! empty( $current['open_share'] ),
				'open_studio' => ! empty( $current['open_studio'] ),
			);
		}
		if ( empty( $onboarding['phase1_complete'] ) && ! empty( $current['id'] ) ) {
			return array(
				'id'          => $current['id'],
				'title'       => $current['title'],
				'description' => $current['why'] ?: $current['description'],
				'cta_view'    => $current['cta_view'] ?? 'start',
				'cta_label'   => $current['cta_label'] ?: __( 'Continue Setup', 'lnkup-marketplace' ),
				'open_share'  => ! empty( $current['open_share'] ),
				'open_studio' => ! empty( $current['open_studio'] ),
			);
		}
		if ( class_exists( 'LNKup_Connector_Ranks' ) && LNKup_Connector_Ranks::enabled() ) {
			$rank = LNKup_Connector_Ranks::dto( $connector_id, false );
			if ( ! empty( $rank['next_action']['title'] ) ) {
				return array_merge(
					array(
						'cta_view'   => 'ranks',
						'cta_label'  => __( 'View ranks', 'lnkup-marketplace' ),
						'open_share' => false,
					),
					$rank['next_action']
				);
			}
		}
		$balances = LNKup_Connector_Rewards::balances_for_connector( $connector_id );
		$pending  = (float) $balances['pending_hold'] + (float) $balances['awaiting_approval'];
		if ( $pending > 0 ) {
			return array(
				'id'          => 'review_reward',
				'title'       => __( 'Review a pending reward', 'lnkup-marketplace' ),
				'description' => __( 'A qualifying booking is in hold or waiting for LNKup approval.', 'lnkup-marketplace' ),
				'cta_view'    => 'earnings',
				'cta_label'   => __( 'View Earnings', 'lnkup-marketplace' ),
				'open_share'  => false,
			);
		}
		$connector = self::get( $connector_id );
		if ( $connector && empty( $connector['payout_preference'] ) ) {
			return array(
				'id'          => 'payout',
				'title'       => __( 'Add payout details', 'lnkup-marketplace' ),
				'description' => __( 'Tell LNKup how you prefer to be paid. Payouts are still reviewed and marked paid by the team.', 'lnkup-marketplace' ),
				'cta_view'    => 'profile',
				'cta_label'   => __( 'Open profile', 'lnkup-marketplace' ),
				'open_share'  => false,
			);
		}
		return array(
			'id'          => 'share_more',
			'title'       => __( 'Share your organizer link again', 'lnkup-marketplace' ),
			'description' => __( 'Keep introducing organizers. There is no base payment — earnings follow qualifying bookings.', 'lnkup-marketplace' ),
			'cta_view'    => 'start',
			'cta_label'   => __( 'Share Your Link', 'lnkup-marketplace' ),
			'open_share'  => true,
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
