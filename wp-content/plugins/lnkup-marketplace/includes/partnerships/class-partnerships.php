<?php
/**
 * Partnership OS read models and connector-facing actions.
 *
 * Every number here is read from the canonical referral, reward and lead tables. Nothing is
 * estimated or projected, and pending commissions are never counted as paid.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Partnerships {

	const OVERVIEW_CACHE    = 'lnkup_partner_overview_v1';
	const LEAD_DAILY_LIMIT  = 20;
	const TERRITORY_SCOPES  = array( 'city', 'metro', 'state', 'region' );

	public static function init(): void {
		add_action( 'init', array( 'LNKup_Partner_Tables', 'maybe_upgrade' ), 6 );
		foreach ( array( 'lnkup_partner_commission_recorded', 'lnkup_partner_commission_approved', 'lnkup_partner_commission_paid', 'lnkup_partner_review_opened', 'lnkup_referral_rewards_reversed', 'lnkup_partner_application_submitted', 'lnkup_partner_application_transitioned' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_overview' ) );
		}
		LNKup_Attribution_Arbiter::init();
	}

	public static function flush_overview(): void {
		delete_transient( self::OVERVIEW_CACHE );
	}

	const SETTINGS_OPTION = 'lnkup_partnerships_settings';

	/**
	 * Partnership settings live in their own option: saving through lnkup_settings would run the
	 * main settings sanitizer, which resets keys (Stripe credentials) that are not in the form.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$defaults = array(
			'partnerships_arbiter_mode'          => 'shadow',
			'partnerships_conflict_window_hours' => 72,
			'connector_leads_enabled'            => true,
		);
		return wp_parse_args( (array) get_option( self::SETTINGS_OPTION, array() ), $defaults );
	}

	/**
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function update_settings( array $input ): array {
		$mode = sanitize_key( (string) ( $input['partnerships_arbiter_mode'] ?? 'shadow' ) );
		$next = array(
			'partnerships_arbiter_mode'          => in_array( $mode, array( 'off', 'shadow', 'enforce' ), true ) ? $mode : 'shadow',
			'partnerships_conflict_window_hours' => max( 0, min( 720, (int) ( $input['partnerships_conflict_window_hours'] ?? 72 ) ) ),
			'connector_leads_enabled'            => ! empty( $input['connector_leads_enabled'] ),
		);
		$prev = self::settings();
		update_option( self::SETTINGS_OPTION, $next, false );
		LNKup_Financial_Audit::log( 'partnership_settings_updated', 'partnership_settings', 0, array( 'from' => $prev, 'to' => $next ) );
		if ( class_exists( 'LNKup_Partnership_Readiness' ) ) {
			LNKup_Partnership_Readiness::flush();
		}
		return $next;
	}

	public static function setting( string $key, $fallback = null ) {
		$s = self::settings();
		return array_key_exists( $key, $s ) ? $s[ $key ] : $fallback;
	}

	// ------------------------------------------------------------------ Overview.

	/**
	 * @return array<string, mixed>
	 */
	public static function overview(): array {
		$cached = get_transient( self::OVERVIEW_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( ! LNKup_Partner_Tables::ready() ) {
			return array( 'ready' => false );
		}
		global $wpdb;
		$refs    = LNKup_Treasury_Tables::referrals_table();
		$rewards = LNKup_Treasury_Tables::referral_rewards_table();
		$out     = array(
			'ready'              => true,
			'generated_at'       => current_time( 'mysql' ),
			'connectors'         => array( 'active' => 0, 'pending' => 0 ),
			'programs'           => array(),
			'organizers'         => array( 'referred' => 0, 'first_event' => 0, 'first_paid_booking' => 0, 'house' => 0, 'independent' => 0 ),
			'vendors_referred'   => 0,
			'leads'              => array( 'partner_submitted' => 0, 'connector_assigned' => 0 ),
			'ledger'             => array(),
			'totals'             => array( 'pending' => 0.0, 'on_hold' => 0.0, 'approved' => 0.0, 'paid' => 0.0, 'reversed' => 0.0 ),
			'qualifying_payments' => 0,
			'commissionable_platform_fees' => 0.0,
			'qualifying_booth_gmv' => 0.0,
			'open_reviews'       => class_exists( 'LNKup_Partner_Reviews' ) ? LNKup_Partner_Reviews::open_count() : 0,
			'arbiter_mode'       => LNKup_Attribution_Arbiter::mode(),
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( class_exists( 'LNKup_Connector_Tables' ) ) {
			$ct = LNKup_Connector_Tables::connectors_table();
			$out['connectors']['active']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ct} WHERE status = 'active' AND program = 'city_connector'" );
			$out['connectors']['pending'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ct} WHERE status = 'pending' AND program = 'city_connector'" );
			$out['partners']              = array(
				'affiliates_active'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ct} WHERE status = 'active' AND program = 'affiliate'" ),
				'strategic_contacts'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ct} WHERE status = 'active' AND program = 'strategic_partner'" ),
				'strategic_active'     => class_exists( 'LNKup_Partner_Organizations' ) ? LNKup_Partner_Organizations::count_active() : 0,
				'applications_pending' => class_exists( 'LNKup_Partner_Applications' ) ? LNKup_Partner_Applications::count_pending() : 0,
			);
		}
		if ( LNKup_Financial_Tables::table_exists( $refs ) ) {
			foreach ( (array) $wpdb->get_results( "SELECT program, COUNT(*) AS n FROM {$refs} GROUP BY program", ARRAY_A ) as $row ) {
				$out['programs'][ (string) $row['program'] ] = array(
					'label'     => LNKup_Partner_Programs::label( (string) $row['program'] ),
					'referrals' => (int) $row['n'],
				);
			}
			$org_where = "(target_role LIKE '%organizer%')";
			$out['organizers']['referred']           = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT referred_user_id) FROM {$refs} WHERE {$org_where}" );
			$out['organizers']['first_event']        = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT referred_user_id) FROM {$refs} WHERE {$org_where} AND (first_event_at IS NOT NULL OR first_opportunity_at IS NOT NULL)" );
			$out['organizers']['first_paid_booking'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT r.referred_user_id) FROM {$refs} r WHERE r.target_role LIKE '%organizer%' AND (r.first_payment_at IS NOT NULL OR EXISTS (SELECT 1 FROM {$rewards} w WHERE w.referral_id = r.id AND w.payment_id > 0))" );
			$out['vendors_referred']                 = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT referred_user_id) FROM {$refs} WHERE target_role LIKE '%vendor%' OR (target_role = '' AND program = 'vendor_referral')" );

			$house = class_exists( 'LNKup_Org_Growth' ) ? LNKup_Org_Growth::house_organizer_ids() : array();
			if ( $house ) {
				$ids = implode( ',', array_map( 'intval', $house ) );
				$out['organizers']['house'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT referred_user_id) FROM {$refs} WHERE {$org_where} AND referred_user_id IN ({$ids})" );
			}
			$out['organizers']['independent'] = max( 0, $out['organizers']['referred'] - $out['organizers']['house'] );
		}
		if ( LNKup_Financial_Tables::table_exists( $rewards ) ) {
			$rows = $wpdb->get_results(
				"SELECT IF(program = '', IF(connector_id > 0, 'city_connector', 'vendor_referral'), program) AS prog, reward_status,
					COUNT(*) AS n, SUM(amount - reversed_amount) AS net, SUM(reversed_amount) AS reversed, SUM(IF(reward_status = 'reversed' AND reversed_amount = 0, amount, 0)) AS legacy_reversed
				FROM {$rewards} GROUP BY prog, reward_status",
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$prog   = (string) $row['prog'];
				$status = (string) $row['reward_status'];
				$bucket = in_array( $status, array( 'approved', 'paid', 'on_hold', 'reversed' ), true ) ? $status : 'pending';
				if ( ! isset( $out['ledger'][ $prog ] ) ) {
					$out['ledger'][ $prog ] = array( 'label' => LNKup_Partner_Programs::label( $prog ), 'pending' => 0.0, 'on_hold' => 0.0, 'approved' => 0.0, 'paid' => 0.0, 'reversed' => 0.0, 'count' => 0 );
				}
				$reversed = (float) $row['reversed'] + (float) $row['legacy_reversed'];
				if ( 'reversed' !== $bucket ) {
					$out['ledger'][ $prog ][ $bucket ] += (float) $row['net'];
					$out['totals'][ $bucket ]          += (float) $row['net'];
				}
				$out['ledger'][ $prog ]['reversed'] += $reversed;
				$out['totals']['reversed']          += $reversed;
				$out['ledger'][ $prog ]['count']    += (int) $row['n'];
			}
			$out['qualifying_payments']          = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT payment_id) FROM {$rewards} WHERE payment_id > 0 AND reward_status <> 'reversed'" );
			$out['commissionable_platform_fees'] = (float) $wpdb->get_var( "SELECT COALESCE(SUM(basis_amount), 0) FROM {$rewards} WHERE basis = 'platform_fee' AND reward_status <> 'reversed'" );
			$out['qualifying_booth_gmv']         = (float) $wpdb->get_var( "SELECT COALESCE(SUM(basis_amount), 0) FROM {$rewards} WHERE basis = 'booth_gmv' AND reward_status <> 'reversed'" );
		}
		if ( class_exists( 'LNKup_Org_Leads' ) && LNKup_Org_Growth_Tables::exists( LNKup_Org_Growth_Tables::LEADS ) ) {
			$lt = LNKup_Org_Leads::table();
			$out['leads']['partner_submitted']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lt} WHERE source IN ('city_connector','connector','partner','organizer_referral','referral') AND merged_into_id = 0" );
			$out['leads']['connector_assigned'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lt} WHERE assigned_connector_id > 0 AND merged_into_id = 0" );
		}
		// phpcs:enable
		foreach ( $out['totals'] as $k => $v ) {
			$out['totals'][ $k ] = round( $v, 2 );
		}
		$out['commissionable_platform_fees'] = round( $out['commissionable_platform_fees'], 2 );
		$out['qualifying_booth_gmv']         = round( $out['qualifying_booth_gmv'], 2 );
		set_transient( self::OVERVIEW_CACHE, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	// ------------------------------------------------------------------ Referral pipeline.

	/**
	 * @param array<string, mixed> $args program, attribution_status, market, role, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public static function referrals( array $args = array() ): array {
		global $wpdb;
		$refs    = LNKup_Treasury_Tables::referrals_table();
		$rewards = LNKup_Treasury_Tables::referral_rewards_table();
		$per     = max( 1, min( 100, (int) ( $args['per_page'] ?? 25 ) ) );
		$page    = max( 1, (int) ( $args['page'] ?? 1 ) );
		$where   = array( '1=1' );
		$vals    = array();
		if ( ! empty( $args['program'] ) ) {
			$where[] = 'r.program = %s';
			$vals[]  = sanitize_key( (string) $args['program'] );
		}
		if ( ! empty( $args['attribution_status'] ) ) {
			$where[] = 'r.attribution_status = %s';
			$vals[]  = sanitize_key( (string) $args['attribution_status'] );
		}
		if ( ! empty( $args['market'] ) ) {
			$where[] = 'r.market_slug = %s';
			$vals[]  = sanitize_key( (string) $args['market'] );
		}
		$role = sanitize_key( (string) ( $args['role'] ?? '' ) );
		if ( in_array( $role, array( 'organizer', 'vendor' ), true ) ) {
			$where[] = 'r.target_role LIKE %s';
			$vals[]  = '%' . $wpdb->esc_like( $role ) . '%';
		}
		$sql = " FROM {$refs} r WHERE " . implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*)' . $sql, $vals ) ) : $wpdb->get_var( 'SELECT COUNT(*)' . $sql ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, (SELECT COALESCE(SUM(w.amount - w.reversed_amount), 0) FROM {$rewards} w WHERE w.referral_id = r.id AND w.reward_status <> 'reversed') AS earned" . $sql . ' ORDER BY r.created_at DESC, r.id DESC LIMIT %d OFFSET %d',
				array_merge( $vals, array( $per, ( $page - 1 ) * $per ) )
			),
			ARRAY_A
		);
		// phpcs:enable
		$items = array();
		foreach ( (array) $rows as $row ) {
			$referrer = get_userdata( (int) $row['referrer_user_id'] );
			$referred = get_userdata( (int) $row['referred_user_id'] );
			$items[]  = array(
				'id'                 => (int) $row['id'],
				'program'            => (string) $row['program'],
				'program_label'      => LNKup_Partner_Programs::label( (string) $row['program'] ),
				'referrer'           => $referrer ? $referrer->display_name : '',
				'referrer_user_id'   => (int) $row['referrer_user_id'],
				'referred'           => $referred ? $referred->display_name : '',
				'referred_user_id'   => (int) $row['referred_user_id'],
				'target_role'        => self::normalize_role( (string) $row['target_role'] ),
				'market'             => (string) $row['market_slug'],
				'created_at'         => (string) $row['created_at'],
				'first_event_at'     => (string) ( $row['first_event_at'] ?? '' ),
				'first_payment_at'   => (string) ( $row['first_payment_at'] ?? '' ),
				'attribution_status' => (string) ( $row['attribution_status'] ?? '' ),
				'reward_status'      => (string) $row['reward_status'],
				'earned'             => round( (float) $row['earned'], 2 ),
			);
		}
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per );
	}

	public static function normalize_role( string $role ): string {
		if ( false !== strpos( $role, 'organizer' ) ) {
			return 'organizer';
		}
		if ( false !== strpos( $role, 'vendor' ) ) {
			return 'vendor';
		}
		return '';
	}

	// ------------------------------------------------------------------ Ledger.

	/**
	 * @param array<string, mixed> $args program, status, connector_id, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public static function ledger( array $args = array() ): array {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		$per   = max( 1, min( 200, (int) ( $args['per_page'] ?? 25 ) ) );
		$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
		$where = array( '1=1' );
		$vals  = array();
		$prog  = sanitize_key( (string) ( $args['program'] ?? '' ) );
		if ( LNKup_Partner_Programs::CITY_CONNECTOR === $prog ) {
			$where[] = "connector_id > 0 AND program IN ('', 'city_connector')";
		} elseif ( LNKup_Partner_Programs::is_account_program( $prog ) ) {
			$where[] = 'connector_id > 0 AND program = %s';
			$vals[]  = $prog;
		} elseif ( $prog ) {
			$where[] = 'program = %s AND connector_id = 0';
			$vals[]  = $prog;
		}
		$status = sanitize_key( (string) ( $args['status'] ?? '' ) );
		if ( $status ) {
			$where[] = 'reward_status = %s';
			$vals[]  = $status;
		}
		if ( ! empty( $args['connector_id'] ) ) {
			$where[] = 'connector_id = %d';
			$vals[]  = absint( $args['connector_id'] );
		}
		$sql = " FROM {$table} WHERE " . implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*)' . $sql, $vals ) ) : $wpdb->get_var( 'SELECT COUNT(*)' . $sql ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT *' . $sql . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', array_merge( $vals, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A );
		// phpcs:enable
		$items = array_map( array( __CLASS__, 'present_reward' ), (array) $rows );
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per );
	}

	/**
	 * @param array<string, mixed> $row Reward row.
	 * @return array<string, mixed>
	 */
	public static function present_reward( array $row ): array {
		$earner  = get_userdata( (int) $row['user_id'] );
		$program = (string) $row['program'];
		if ( '' === $program ) {
			$program = (int) $row['connector_id'] > 0 ? LNKup_Partner_Programs::CITY_CONNECTOR : LNKup_Partner_Programs::VENDOR_REFERRAL;
		}
		$manual = (int) $row['connector_id'] > 0;
		return array(
			'id'               => (int) $row['id'],
			'program'          => $program,
			'program_label'    => LNKup_Partner_Programs::label( $program ),
			'earner'           => $earner ? $earner->display_name : '',
			'earner_user_id'   => (int) $row['user_id'],
			'connector_id'     => (int) $row['connector_id'],
			'referral_id'      => (int) $row['referral_id'],
			'payment_id'       => (int) $row['payment_id'],
			'event_type'       => (string) $row['event_type'],
			'amount'           => round( (float) $row['amount'], 2 ),
			'reversed_amount'  => round( (float) ( $row['reversed_amount'] ?? 0 ), 2 ),
			'net_amount'       => round( max( 0, (float) $row['amount'] - (float) ( $row['reversed_amount'] ?? 0 ) ), 2 ),
			'currency'         => (string) ( $row['currency'] ?? 'USD' ),
			'basis'            => (string) ( $row['basis'] ?? '' ),
			'basis_amount'     => round( (float) ( $row['basis_amount'] ?? 0 ), 2 ),
			'rule_version'     => (int) ( $row['rule_version'] ?? 0 ),
			'reward_status'    => (string) $row['reward_status'],
			'display_status'   => $manual ? LNKup_Connector_Rewards::display_status( $row ) : (string) $row['reward_status'],
			'hold_reason'      => (string) ( $row['hold_reason'] ?? '' ),
			'payout_reference' => (string) ( $row['payout_reference'] ?? '' ),
			'available_at'     => (string) ( $row['available_at'] ?? '' ),
			'approved_at'      => (string) ( $row['approved_at'] ?? '' ),
			'paid_at'          => (string) ( $row['paid_at'] ?? '' ),
			'created_at'       => (string) $row['created_at'],
			'settlement'       => $manual ? 'manual_payout' : 'wallet_credit',
		);
	}

	/**
	 * Which partners drove paid bookings across a tour's stops. Every count is by payment ID, so
	 * a booking with two commission rows (connector plus vendor credit) is one booking, and the
	 * same booking never counts at two stops. Admin only: organizers never see partner money.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function tour_attribution( int $tour_id ) {
		global $wpdb;
		if ( 'lnkup_tour' !== get_post_type( $tour_id ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Tour not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$stops = class_exists( 'LNKup_Tours' ) ? LNKup_Tours::stop_event_ids( $tour_id ) : array();
		$out   = array(
			'tour_id'  => $tour_id,
			'title'    => get_the_title( $tour_id ),
			'stops'    => array(),
			'partners' => array(),
			'totals'   => array( 'paid_bookings' => 0, 'attributed_bookings' => 0, 'attributed_share' => 0.0, 'attributed_booth_gmv' => 0.0, 'commission_net' => 0.0 ),
			'basis'    => __( 'Paid bookings are payments that were ever paid at a tour stop; a later refund reverses the commission, not the booking. Each payment counts once.', 'lnkup-marketplace' ),
		);
		if ( ! $stops ) {
			return $out;
		}
		$paid_in  = "'" . implode( "','", LNKup_Opportunity_Funnel::PAID_STATUSES ) . "'";
		$payments = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT p.ID AS payment_id, e.meta_value AS event_id FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_lnkup_event_id'
			INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_payment_status'
			WHERE p.post_type = 'lnkup_payment' AND s.meta_value IN ({$paid_in}) AND e.meta_value IN (" . implode( ',', array_map( 'absint', $stops ) ) . ')',
			ARRAY_A
		);
		$event_of = array();
		foreach ( $payments as $row ) {
			$event_of[ (int) $row['payment_id'] ] = (int) $row['event_id'];
		}
		$stop_rows = array();
		foreach ( $stops as $event_id ) {
			$stop_rows[ $event_id ] = array( 'event_id' => $event_id, 'title' => get_the_title( $event_id ), 'paid_bookings' => 0, 'attributed_bookings' => 0, 'attributed_booth_gmv' => 0.0 );
		}
		foreach ( $event_of as $event_id ) {
			++$stop_rows[ $event_id ]['paid_bookings'];
		}
		$out['totals']['paid_bookings'] = count( $event_of );

		$rewards = $event_of ? (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'SELECT * FROM ' . LNKup_Treasury_Tables::referral_rewards_table() . ' WHERE payment_id IN (' . implode( ',', array_keys( $event_of ) ) . ')',
			ARRAY_A
		) : array();
		$partners   = array();
		$attributed = array();
		foreach ( $rewards as $raw ) {
			$row     = self::present_reward( $raw );
			$pay     = (int) $row['payment_id'];
			$key     = $row['program'] . ':' . ( $row['connector_id'] ?: 'u' . $row['earner_user_id'] );
			if ( ! isset( $partners[ $key ] ) ) {
				$partners[ $key ] = array(
					'program'       => $row['program'],
					'program_label' => $row['program_label'],
					'earner'        => $row['earner'],
					'connector_id'  => $row['connector_id'],
					'payments'      => array(),
					'commission'    => array( 'pending' => 0.0, 'approved' => 0.0, 'paid' => 0.0, 'reversed' => 0.0, 'net' => 0.0 ),
				);
			}
			$partners[ $key ]['payments'][ $pay ] = true;
			$attributed[ $pay ]                   = true;
			$status                               = (string) $row['display_status'];
			$bucket = in_array( $status, array( 'approved', 'paid' ), true ) ? $status : ( 'reversed' === $status || 'expired' === $status ? 'reversed' : 'pending' );
			if ( 'reversed' !== $bucket ) {
				$partners[ $key ]['commission'][ $bucket ] += $row['net_amount'];
				$partners[ $key ]['commission']['net']     += $row['net_amount'];
			}
			$partners[ $key ]['commission']['reversed'] += 'reversed' === $bucket && $row['reversed_amount'] <= 0 ? $row['amount'] : $row['reversed_amount'];
		}
		foreach ( array_keys( $attributed ) as $pay ) {
			$gmv = LNKup_Connector_Rewards::booth_gmv( $pay );
			++$stop_rows[ $event_of[ $pay ] ]['attributed_bookings'];
			$stop_rows[ $event_of[ $pay ] ]['attributed_booth_gmv'] += $gmv;
			$out['totals']['attributed_booth_gmv']                  += $gmv;
		}
		foreach ( $partners as $partner ) {
			$gmv = 0.0;
			foreach ( array_keys( $partner['payments'] ) as $pay ) {
				$gmv += LNKup_Connector_Rewards::booth_gmv( $pay );
			}
			$partner['bookings']   = count( $partner['payments'] );
			$partner['booth_gmv']  = round( $gmv, 2 );
			$partner['commission'] = array_map( static fn( $v ) => round( (float) $v, 2 ), $partner['commission'] );
			unset( $partner['payments'] );
			$out['totals']['commission_net'] += $partner['commission']['net'];
			$out['partners'][]                = $partner;
		}
		usort( $out['partners'], static fn( $a, $b ) => $b['bookings'] <=> $a['bookings'] );
		$out['stops']                          = array_values( array_map( static function ( $s ) { $s['attributed_booth_gmv'] = round( $s['attributed_booth_gmv'], 2 ); return $s; }, $stop_rows ) );
		$out['totals']['attributed_bookings']  = count( $attributed );
		$out['totals']['attributed_share']     = $out['totals']['paid_bookings'] ? round( count( $attributed ) / $out['totals']['paid_bookings'], 4 ) : 0.0;
		$out['totals']['attributed_booth_gmv'] = round( $out['totals']['attributed_booth_gmv'], 2 );
		$out['totals']['commission_net']       = round( $out['totals']['commission_net'], 2 );
		return $out;
	}

	/**
	 * Apply an admin ledger action to a manually settled (connector) reward.
	 *
	 * @return true|WP_Error
	 */
	public static function ledger_action( int $reward_id, string $action, array $args = array() ) {
		switch ( $action ) {
			case 'approve':
				return LNKup_Connector_Rewards::approve_reward( $reward_id );
			case 'hold':
				return LNKup_Connector_Rewards::hold( $reward_id, (string) ( $args['reason'] ?? 'admin_review' ) );
			case 'release':
				return LNKup_Connector_Rewards::release( $reward_id );
			case 'mark-paid':
				return LNKup_Connector_Rewards::mark_paid( $reward_id, (string) ( $args['payout_reference'] ?? '' ) );
		}
		return new WP_Error( 'lnkup_ledger_action', __( 'Unknown ledger action.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
	}

	public static function ledger_csv( array $args ): string {
		$args['per_page'] = 200;
		$args['page']     = 1;
		$cols   = array( 'id', 'program', 'earner', 'payment_id', 'event_type', 'basis', 'basis_amount', 'rule_version', 'amount', 'reversed_amount', 'net_amount', 'reward_status', 'payout_reference', 'created_at', 'approved_at', 'paid_at' );
		$handle = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $handle, $cols );
		do {
			$batch = self::ledger( $args );
			foreach ( $batch['items'] as $item ) {
				fputcsv( $handle, array_map( array( __CLASS__, 'csv_cell' ), array_intersect_key( array_merge( array_flip( $cols ), $item ), array_flip( $cols ) ) ) );
			}
			++$args['page'];
		} while ( ( $args['page'] - 1 ) * 200 < $batch['total'] && $args['page'] <= 50 );
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $csv;
	}

	/**
	 * Neutralize spreadsheet formula injection.
	 *
	 * @param mixed $value Cell.
	 */
	private static function csv_cell( $value ): string {
		$value = (string) $value;
		return preg_match( '/^[=+\-@]/', $value ) ? "'" . $value : $value;
	}

	// ------------------------------------------------------------------ Territories.

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function territories( int $connector_id = 0 ): array {
		global $wpdb;
		if ( ! LNKup_Partner_Tables::exists( LNKup_Partner_Tables::TERRITORIES ) ) {
			return array();
		}
		$table = LNKup_Partner_Tables::table( LNKup_Partner_Tables::TERRITORIES );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $connector_id
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE connector_id = %d ORDER BY scope_type, value", $connector_id ), ARRAY_A )
			: $wpdb->get_results( "SELECT * FROM {$table} ORDER BY connector_id, scope_type, value LIMIT 1000", ARRAY_A );
		// phpcs:enable
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'           => (int) $row['id'],
				'connector_id' => (int) $row['connector_id'],
				'scope_type'   => (string) $row['scope_type'],
				'value'        => (string) $row['value'],
				'created_at'   => (string) $row['created_at'],
			);
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function add_territory( int $connector_id, string $scope_type, string $value, int $actor = 0 ) {
		global $wpdb;
		$scope_type = sanitize_key( $scope_type );
		$value      = trim( sanitize_text_field( $value ) );
		if ( ! LNKup_Connector::get( $connector_id ) ) {
			return new WP_Error( 'lnkup_territory_connector', __( 'Connector not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $scope_type, self::TERRITORY_SCOPES, true ) || '' === $value ) {
			return new WP_Error( 'lnkup_territory_invalid', __( 'Choose a city, metro, state or region and enter a name.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( 'state' === $scope_type ) {
			$value = strtoupper( substr( $value, 0, 2 ) );
		}
		$value = substr( $value, 0, 128 );
		$table = LNKup_Partner_Tables::table( LNKup_Partner_Tables::TERRITORIES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE connector_id = %d AND scope_type = %s AND value = %s", $connector_id, $scope_type, $value ) );
		if ( ! $existing ) {
			$wpdb->insert(
				$table,
				array(
					'connector_id' => $connector_id,
					'scope_type'   => $scope_type,
					'value'        => $value,
					'assigned_by'  => $actor ?: get_current_user_id(),
					'created_at'   => current_time( 'mysql' ),
				)
			);
			$existing = (int) $wpdb->insert_id;
			LNKup_Financial_Audit::log( 'connector_territory_assigned', 'connector', $connector_id, array( 'scope_type' => $scope_type, 'value' => $value ) );
		}
		return array( 'id' => $existing, 'connector_id' => $connector_id, 'scope_type' => $scope_type, 'value' => $value );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function remove_territory( int $territory_id ) {
		global $wpdb;
		$table = LNKup_Partner_Tables::table( LNKup_Partner_Tables::TERRITORIES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $territory_id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'lnkup_territory_missing', __( 'Territory not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$wpdb->delete( $table, array( 'id' => $territory_id ) );
		LNKup_Financial_Audit::log( 'connector_territory_removed', 'connector', (int) $row['connector_id'], array( 'scope_type' => $row['scope_type'], 'value' => $row['value'] ) );
		return true;
	}

	// ------------------------------------------------------------------ Connector lead submission.

	public static function leads_enabled(): bool {
		return (bool) self::setting( 'connector_leads_enabled', true ) && class_exists( 'LNKup_Org_Leads' ) && LNKup_Org_Growth_Tables::exists( LNKup_Org_Growth_Tables::LEADS );
	}

	/**
	 * Submit an organizer lead on behalf of a connector.
	 *
	 * Duplicate, existing-account and suppressed submissions return a status only; nothing about
	 * the existing record is revealed to the connector.
	 *
	 * @param array<string, mixed> $connector Connector row.
	 * @param array<string, mixed> $input     Raw fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function submit_connector_lead( array $connector, array $input ) {
		if ( ! self::leads_enabled() ) {
			return new WP_Error( 'lnkup_leads_disabled', __( 'Lead submission is not available right now.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$connector_id = (int) $connector['id'];
		$user_id      = (int) $connector['user_id'];
		$program      = LNKup_Connector::program_of( $connector );
		if ( self::leads_submitted_today( $connector_id ) >= self::LEAD_DAILY_LIMIT ) {
			return new WP_Error( 'lnkup_leads_rate_limited', __( 'You have reached today’s lead submission limit. Try again tomorrow.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		$name  = trim( sanitize_text_field( (string) ( $input['name'] ?? '' ) ) );
		$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$site  = trim( (string) ( $input['website'] ?? '' ) );
		$insta = trim( sanitize_text_field( (string) ( $input['instagram'] ?? '' ) ) );
		if ( '' === $name ) {
			return new WP_Error( 'lnkup_lead_name_required', __( 'Organizer or business name is required.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( '' === $email && '' === $site && '' === $insta ) {
			return new WP_Error( 'lnkup_lead_contact_required', __( 'Add a public website, Instagram or business email so LNKup can verify the organizer.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		if ( '' !== $email ) {
			$existing_user = get_user_by( 'email', $email );
			if ( $existing_user ) {
				return array( 'status' => 'already_in_pipeline', 'message' => self::pipeline_message() );
			}
			if ( '' !== LNKup_Contact_Suppression::check_email( $email ) ) {
				return array( 'status' => 'not_accepted', 'message' => __( 'LNKup can’t accept this lead. Thanks for thinking of us.', 'lnkup-marketplace' ) );
			}
		}

		$code   = LNKup_Connector::get_code_for_connector( $connector_id );
		$reason = sanitize_textarea_field( (string) ( $input['reason'] ?? '' ) );
		$result = LNKup_Org_Leads::create(
			array(
				'name'                  => $name,
				'email'                 => $email,
				'website'               => $site,
				'instagram'             => $insta,
				'city'                  => sanitize_text_field( (string) ( $input['city'] ?? $connector['city'] ?? '' ) ),
				'state'                 => sanitize_text_field( (string) ( $input['state'] ?? $connector['state'] ?? '' ) ),
				'category'              => sanitize_text_field( (string) ( $input['event_type'] ?? '' ) ),
				'application_url'       => (string) ( $input['application_url'] ?? '' ),
				'source'                => LNKup_Connector::PROGRAM === $program ? 'city_connector' : 'partner',
				'source_campaign'       => $program,
				'partner_id'            => (int) ( $connector['partner_id'] ?? 0 ),
				'source_detail'         => substr( sanitize_text_field( (string) ( $input['source'] ?? '' ) ), 0, 255 ),
				'referral_code'         => (string) ( $code['code'] ?? '' ),
				'referrer_user_id'      => $user_id,
				'assigned_connector_id' => $connector_id,
				'qualification_notes'   => $reason,
			),
			array( 'actor' => $user_id )
		);
		if ( is_wp_error( $result ) ) {
			if ( 'lnkup_lead_duplicate' === $result->get_error_code() ) {
				return array( 'status' => 'already_in_pipeline', 'message' => self::pipeline_message() );
			}
			return new WP_Error( 'lnkup_lead_submit_failed', $result->get_error_message(), array( 'status' => 400 ) );
		}
		LNKup_Org_Leads::log_activity( (int) $result, 'connector_submitted', array( 'actor' => $user_id, 'summary' => sprintf( /* translators: %s program */ __( 'Submitted by %s', 'lnkup-marketplace' ), LNKup_Partner_Programs::label( $program ) ) ) );
		return array(
			'status'  => 'submitted',
			'message' => __( 'Thanks! LNKup will review this organizer. A lead is not an acquired organizer until they register and activate.', 'lnkup-marketplace' ),
			'lead'    => self::present_connector_lead( (array) LNKup_Org_Leads::get_row( (int) $result ) ),
		);
	}

	private static function pipeline_message(): string {
		return __( 'This organizer is already known to LNKup. If you introduced them, contact your LNKup partner manager for attribution review.', 'lnkup-marketplace' );
	}

	public static function leads_submitted_today( int $connector_id ): int {
		global $wpdb;
		$since = gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Org_Leads::table() . " WHERE assigned_connector_id = %d AND source IN ('city_connector','partner') AND created_at >= %s", $connector_id, $since ) );
	}

	/**
	 * Leads assigned to or submitted by this connector only.
	 *
	 * @param array<string, mixed> $connector Connector row.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, daily_limit: int, submitted_today: int}
	 */
	public static function connector_leads( array $connector, int $page = 1, int $per_page = 20 ): array {
		global $wpdb;
		$per  = max( 1, min( 50, $per_page ) );
		$page = max( 1, $page );
		$cid  = (int) $connector['id'];
		$uid  = (int) $connector['user_id'];
		$base = array(
			'items'           => array(),
			'total'           => 0,
			'page'            => $page,
			'per_page'        => $per,
			'daily_limit'     => self::LEAD_DAILY_LIMIT,
			'submitted_today' => 0,
			'enabled'         => self::leads_enabled(),
		);
		if ( ! $base['enabled'] ) {
			return $base;
		}
		$table = LNKup_Org_Leads::table();
		$where = "(assigned_connector_id = %d OR (created_by = %d AND created_by > 0)) AND merged_into_id = 0";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$base['total'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $cid, $uid ) );
		$rows          = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $cid, $uid, $per, ( $page - 1 ) * $per ), ARRAY_A );
		// phpcs:enable
		$base['items']           = array_map( array( __CLASS__, 'present_connector_lead' ), (array) $rows );
		$base['submitted_today'] = self::leads_submitted_today( $cid );
		return $base;
	}

	/**
	 * Limited lead shape for connectors: no contact data, scores or internal notes.
	 *
	 * @param array<string, mixed> $row Lead row.
	 * @return array<string, mixed>
	 */
	public static function present_connector_lead( array $row ): array {
		$stage   = (string) ( $row['stage'] ?? '' );
		$outcome = (string) ( $row['outcome'] ?? '' );
		return array(
			'id'            => (int) ( $row['id'] ?? 0 ),
			'name'          => (string) ( $row['name'] ?? '' ),
			'city'          => (string) ( $row['city'] ?? '' ),
			'state'         => (string) ( $row['state'] ?? '' ),
			'category'      => (string) ( $row['category'] ?? '' ),
			'stage'         => $stage,
			'stage_label'   => LNKup_Org_Lead_Pipeline::STAGES[ $stage ] ?? ucwords( str_replace( '_', ' ', $stage ) ),
			'outcome'       => $outcome,
			'outcome_label' => $outcome ? ( LNKup_Org_Lead_Pipeline::OUTCOMES[ $outcome ] ?? '' ) : '',
			'submitted_at'  => (string) ( $row['created_at'] ?? '' ),
			'updated_at'    => (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
