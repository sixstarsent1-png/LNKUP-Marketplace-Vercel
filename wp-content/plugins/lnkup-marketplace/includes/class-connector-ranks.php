<?php
/**
 * City Connector performance ranks.
 *
 * Cached metrics + idempotent booking attribution. Commission is unchanged.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Connector_Ranks {

	const KEYS = array( 'city_connector', 'market_builder', 'city_captain', 'city_director', 'regional_partner' );

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'lnkup_marketplace_daily', array( __CLASS__, 'recalculate_all' ), 40 );
		// Refund and chargeback reversals arrive through LNKup_Referral_Reversals for every refund path.
	}

	/**
	 * Rank definitions from settings (defaults match the program spec).
	 */
	public static function definitions() {
		$s = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		$names = array(
			'city_connector'   => __( 'City Connector', 'lnkup-marketplace' ),
			'market_builder'   => __( 'Market Builder', 'lnkup-marketplace' ),
			'city_captain'     => __( 'City Captain', 'lnkup-marketplace' ),
			'city_director'    => __( 'City Director', 'lnkup-marketplace' ),
			'regional_partner' => __( 'Regional Partner', 'lnkup-marketplace' ),
		);
		$benefits = array(
			'city_connector'   => array(
				__( 'Connector dashboard access', 'lnkup-marketplace' ),
				__( 'Organizer and vendor referral tools', 'lnkup-marketplace' ),
				__( 'Founding commission access when eligible', 'lnkup-marketplace' ),
				__( 'Outreach scripts and resources', 'lnkup-marketplace' ),
			),
			'market_builder'   => array(
				__( 'Market Builder badge', 'lnkup-marketplace' ),
				__( 'Verified connector designation', 'lnkup-marketplace' ),
				__( 'Priority connector support', 'lnkup-marketplace' ),
			),
			'city_captain'     => array(
				__( 'City Captain badge', 'lnkup-marketplace' ),
				__( 'Priority access to city leads', 'lnkup-marketplace' ),
				__( 'Featured connector profile', 'lnkup-marketplace' ),
				__( 'Premium promotional resource kit', 'lnkup-marketplace' ),
			),
			'city_director'    => array(
				__( 'City Director badge', 'lnkup-marketplace' ),
				__( 'Priority city campaign opportunities', 'lnkup-marketplace' ),
				__( 'Early access to qualified organizer leads', 'lnkup-marketplace' ),
				__( 'Eligibility to recruit or mentor connectors', 'lnkup-marketplace' ),
			),
			'regional_partner' => array(
				__( 'Regional Partner badge', 'lnkup-marketplace' ),
				__( 'Regional growth opportunities', 'lnkup-marketplace' ),
				__( 'Dedicated partner support', 'lnkup-marketplace' ),
				__( 'Eligibility for a custom long-term revenue agreement', 'lnkup-marketplace' ),
			),
		);
		return array(
			'city_connector'   => array(
				'key'        => 'city_connector',
				'name'       => $names['city_connector'],
				'activated'  => 0,
				'bookings'   => 0,
				'gmv'        => 0,
				'recurring'  => 0,
				'cities'     => 0,
				'bonus'      => 0,
				'benefits'   => $benefits['city_connector'],
				'onboarding' => true,
			),
			'market_builder'   => array(
				'key'        => 'market_builder',
				'name'       => $names['market_builder'],
				'activated'  => max( 0, (int) ( $s['connector_rank_market_builder_activated'] ?? 3 ) ),
				'bookings'   => max( 0, (int) ( $s['connector_rank_market_builder_bookings'] ?? 15 ) ),
				'gmv'        => max( 0, (float) ( $s['connector_rank_market_builder_gmv'] ?? 3000 ) ),
				'recurring'  => 0,
				'cities'     => 0,
				'bonus'      => max( 0, (float) ( $s['connector_rank_market_builder_bonus'] ?? 100 ) ),
				'benefits'   => $benefits['market_builder'],
				'onboarding' => true,
			),
			'city_captain'     => array(
				'key'        => 'city_captain',
				'name'       => $names['city_captain'],
				'activated'  => max( 0, (int) ( $s['connector_rank_city_captain_activated'] ?? 10 ) ),
				'bookings'   => max( 0, (int) ( $s['connector_rank_city_captain_bookings'] ?? 60 ) ),
				'gmv'        => max( 0, (float) ( $s['connector_rank_city_captain_gmv'] ?? 15000 ) ),
				'recurring'  => max( 0, (int) ( $s['connector_rank_city_captain_recurring'] ?? 3 ) ),
				'cities'     => 0,
				'bonus'      => max( 0, (float) ( $s['connector_rank_city_captain_bonus'] ?? 250 ) ),
				'benefits'   => $benefits['city_captain'],
				'onboarding' => true,
			),
			'city_director'    => array(
				'key'        => 'city_director',
				'name'       => $names['city_director'],
				'activated'  => max( 0, (int) ( $s['connector_rank_city_director_activated'] ?? 25 ) ),
				'bookings'   => max( 0, (int) ( $s['connector_rank_city_director_bookings'] ?? 200 ) ),
				'gmv'        => max( 0, (float) ( $s['connector_rank_city_director_gmv'] ?? 50000 ) ),
				'recurring'  => max( 0, (int) ( $s['connector_rank_city_director_recurring'] ?? 8 ) ),
				'cities'     => 0,
				'bonus'      => max( 0, (float) ( $s['connector_rank_city_director_bonus'] ?? 500 ) ),
				'benefits'   => $benefits['city_director'],
				'onboarding' => true,
			),
			'regional_partner' => array(
				'key'        => 'regional_partner',
				'name'       => $names['regional_partner'],
				'activated'  => max( 0, (int) ( $s['connector_rank_regional_partner_activated'] ?? 50 ) ),
				'bookings'   => max( 0, (int) ( $s['connector_rank_regional_partner_bookings'] ?? 500 ) ),
				'gmv'        => max( 0, (float) ( $s['connector_rank_regional_partner_gmv'] ?? 125000 ) ),
				'recurring'  => max( 0, (int) ( $s['connector_rank_regional_partner_recurring'] ?? 15 ) ),
				'cities'     => max( 1, (int) ( $s['connector_rank_regional_partner_cities'] ?? 2 ) ),
				'bonus'      => max( 0, (float) ( $s['connector_rank_regional_partner_bonus'] ?? 1000 ) ),
				'benefits'   => $benefits['regional_partner'],
				'onboarding' => true,
			),
		);
	}

	/**
	 * Whether the rank program is enabled.
	 */
	public static function enabled() {
		$s = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		return ! isset( $s['connector_ranks_enabled'] ) || ! empty( $s['connector_ranks_enabled'] );
	}

	/**
	 * Rank index, or -1 if unknown.
	 *
	 * @param string $key Rank key.
	 */
	public static function rank_index( $key ) {
		$i = array_search( $key, self::KEYS, true );
		return false === $i ? -1 : (int) $i;
	}

	/**
	 * Higher of two rank keys (monotonic).
	 *
	 * @param string $a Rank key.
	 * @param string $b Rank key.
	 */
	public static function higher_rank( $a, $b ) {
		return self::rank_index( $a ) >= self::rank_index( $b ) ? $a : $b;
	}

	/**
	 * Whether metrics satisfy a rank (cannot skip: caller walks in order).
	 *
	 * @param array $def               Rank definition.
	 * @param array $metrics           Metric bag.
	 * @param bool  $approved          Connector approved/active.
	 * @param bool  $onboarding_done   All onboarding steps done.
	 */
	public static function meets_rank( $def, $metrics, $approved, $onboarding_done ) {
		if ( ! $approved ) {
			return false;
		}
		if ( ! empty( $def['onboarding'] ) && ! $onboarding_done ) {
			return false;
		}
		if ( (int) $metrics['activated_organizers'] < (int) $def['activated'] ) {
			return false;
		}
		if ( (int) $metrics['qualifying_bookings'] < (int) $def['bookings'] ) {
			return false;
		}
		if ( (float) $metrics['qualifying_gmv'] + 0.0001 < (float) $def['gmv'] ) {
			return false;
		}
		if ( (int) $metrics['recurring_organizers'] < (int) $def['recurring'] ) {
			return false;
		}
		if ( (int) $def['cities'] > 1 && (int) $metrics['approved_cities'] < (int) $def['cities'] ) {
			return false;
		}
		return true;
	}

	/**
	 * Evaluate achieved rank without decreasing current/highest.
	 *
	 * @param array  $metrics          Metrics.
	 * @param bool   $approved         Approved.
	 * @param bool   $onboarding_done  Onboarding complete.
	 * @param string $current          Stored current rank.
	 * @param string $highest          Stored highest rank.
	 * @param array  $defs             Definitions.
	 */
	public static function evaluate( $metrics, $approved, $onboarding_done, $current = '', $highest = '', $defs = null ) {
		$defs = $defs ?: self::definitions();
		$achieved = '';
		foreach ( self::KEYS as $key ) {
			if ( empty( $defs[ $key ] ) || ! self::meets_rank( $defs[ $key ], $metrics, $approved, $onboarding_done ) ) {
				break;
			}
			$achieved = $key;
		}
		$new_current = $current;
		if ( $achieved && self::rank_index( $achieved ) > self::rank_index( $new_current ) ) {
			$new_current = $achieved;
		}
		$new_highest = $highest;
		if ( $new_current && self::rank_index( $new_current ) > self::rank_index( $new_highest ) ) {
			$new_highest = $new_current;
		}
		return array(
			'achieved' => $achieved,
			'current'  => $new_current,
			'highest'  => $new_highest,
			'advanced' => $new_current && $new_current !== $current,
		);
	}

	/**
	 * Requirement rows toward a target rank.
	 *
	 * @param array $metrics Metrics.
	 * @param array $def     Target rank definition.
	 */
	public static function requirement_rows( $metrics, $def ) {
		$rows = array();
		$map  = array(
			'activated_organizers' => array( 'activated', __( 'Activated organizers', 'lnkup-marketplace' ) ),
			'qualifying_bookings'  => array( 'bookings', __( 'Completed bookings', 'lnkup-marketplace' ) ),
			'qualifying_gmv'       => array( 'gmv', __( 'Qualifying GMV', 'lnkup-marketplace' ) ),
			'recurring_organizers' => array( 'recurring', __( 'Recurring organizers', 'lnkup-marketplace' ) ),
			'approved_cities'      => array( 'cities', __( 'Approved cities', 'lnkup-marketplace' ) ),
		);
		foreach ( $map as $metric_key => $pair ) {
			$required = $def[ $pair[0] ];
			if ( 'recurring_organizers' === $metric_key && (int) $required <= 0 ) {
				continue;
			}
			if ( 'approved_cities' === $metric_key && (int) $required <= 1 ) {
				continue;
			}
			$current   = $metrics[ $metric_key ];
			$remaining = max( 0, ( 'qualifying_gmv' === $metric_key ) ? ( (float) $required - (float) $current ) : ( (int) $required - (int) $current ) );
			if ( 'qualifying_gmv' === $metric_key ) {
				$remaining = round( (float) $remaining, 2 );
			}
			$complete = $remaining <= 0;
			$pct      = ( $required > 0 ) ? min( 100, (int) round( ( (float) $current / (float) $required ) * 100 ) ) : 100;
			$rows[]   = array(
				'key'       => $metric_key,
				'label'     => $pair[1],
				'current'   => $current,
				'required'  => $required,
				'remaining' => $remaining,
				'complete'  => $complete,
				'percent'   => $pct,
			);
		}
		return $rows;
	}

	/**
	 * Average requirement percent (capped 100).
	 *
	 * @param array $rows Requirement rows.
	 */
	public static function progress_percent( $rows ) {
		if ( ! $rows ) {
			return 100;
		}
		$sum = 0;
		foreach ( $rows as $row ) {
			$sum += (int) $row['percent'];
		}
		return (int) min( 100, round( $sum / count( $rows ) ) );
	}

	/**
	 * Smallest meaningful remaining gap (highest % among incomplete).
	 *
	 * @param array $rows Requirement rows.
	 */
	public static function closest_gap( $rows ) {
		$best = null;
		foreach ( $rows as $row ) {
			if ( ! empty( $row['complete'] ) ) {
				continue;
			}
			if ( ! $best || (int) $row['percent'] > (int) $best['percent'] ) {
				$best = $row;
			}
		}
		return $best;
	}

	/**
	 * Idempotency key for a rank bonus.
	 *
	 * @param int    $connector_id Connector ID.
	 * @param string $rank_key     Rank key.
	 */
	public static function bonus_key( $connector_id, $rank_key ) {
		return 'connector_rank_bonus:' . absint( $connector_id ) . ':' . sanitize_key( $rank_key );
	}

	/**
	 * Zero metrics.
	 */
	public static function empty_metrics() {
		return array(
			'activated_organizers' => 0,
			'recurring_organizers' => 0,
			'qualifying_bookings'  => 0,
			'qualifying_gmv'       => 0.0,
			'approved_cities'      => 0,
		);
	}

	/**
	 * Safe zero-state DTO.
	 */
	public static function empty_dto() {
		$defs = self::definitions();
		$next = $defs['city_connector'];
		$rows = self::requirement_rows( self::empty_metrics(), $next );
		return array(
			'current_rank' => array(
				'key'              => '',
				'name'             => '',
				'earned_at'        => null,
				'founding_partner' => false,
			),
			'metrics'      => self::empty_metrics(),
			'next_rank'    => array(
				'key'              => 'city_connector',
				'name'             => $next['name'],
				'progress_percent' => 0,
				'requirements'     => $rows,
			),
			'history'      => array(),
			'next_action'  => array(),
			'celebration'  => null,
			'benefits'     => array(),
		);
	}

	/**
	 * Whether a payment may count toward rank.
	 *
	 * @param int $payment_id Payment ID.
	 */
	public static function is_qualifying_payment( $payment_id ) {
		$payment_id = absint( $payment_id );
		if ( ! $payment_id ) {
			return false;
		}
		$status = sanitize_key( (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) );
		if ( in_array( $status, array( 'refunded', 'failed', 'cancelled', 'disputed', 'chargeback' ), true ) ) {
			return false;
		}
		if ( self::is_test_payment( $payment_id ) ) {
			return false;
		}
		if ( (float) LNKup_Connector_Rewards::booth_gmv( $payment_id ) <= 0 ) {
			return false;
		}
		if ( class_exists( 'LNKup_Financial_Tables' ) && method_exists( 'LNKup_Financial_Tables', 'disputes_table' ) ) {
			global $wpdb;
			$table = LNKup_Financial_Tables::disputes_table();
			if ( LNKup_Financial_Tables::table_exists( $table ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$open = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$table} WHERE payment_post_id = %d AND status IN ('open','needs_response','lost','chargeback') LIMIT 1",
						$payment_id
					)
				);
				if ( $open ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Test / simulated payments never count in production ranks.
	 *
	 * @param int $payment_id Payment ID.
	 */
	public static function is_test_payment( $payment_id ) {
		if ( get_post_meta( $payment_id, '_lnkup_test_mode', true ) ) {
			return true;
		}
		foreach ( array( '_lnkup_stripe_payment_intent', '_lnkup_stripe_intent_id', '_lnkup_stripe_charge_id', '_lnkup_provider_ref' ) as $key ) {
			$val = (string) get_post_meta( $payment_id, $key, true );
			if ( 0 === strpos( $val, 'sim_' ) ) {
				return true;
			}
		}
		$s = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		$live_site = empty( $s['test_mode'] );
		$livemode  = get_post_meta( $payment_id, '_lnkup_stripe_livemode', true );
		if ( $live_site && ( '0' === (string) $livemode || 'false' === (string) $livemode ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Record organizer-first booking attribution (UNIQUE payment_id).
	 *
	 * @param int    $payment_id   Payment ID.
	 * @param int    $connector_id Connector ID.
	 * @param int    $referral_id  Referral ID.
	 * @param string $reason       organizer|vendor.
	 */
	public static function record_payment( $payment_id, $connector_id, $referral_id, $reason ) {
		$payment_id   = absint( $payment_id );
		$connector_id = absint( $connector_id );
		$referral_id  = absint( $referral_id );
		$reason       = 'organizer' === $reason ? 'organizer' : 'vendor';
		if ( ! $payment_id || ! $connector_id ) {
			return 0;
		}
		$connector = LNKup_Connector::get( $connector_id );
		if ( $connector && LNKup_Connector::PROGRAM !== LNKup_Connector::program_of( $connector ) ) {
			return 0;
		}
		if ( ! $connector ) {
			return 0;
		}
		$org_id    = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$vendor_id = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		if ( (int) ( $connector['user_id'] ?? 0 ) && in_array( (int) $connector['user_id'], array( $org_id, $vendor_id ), true ) ) {
			return 0;
		}
		if ( get_user_meta( $org_id, '_lnkup_duplicate_account', true ) || get_user_meta( $vendor_id, '_lnkup_duplicate_account', true ) ) {
			return 0;
		}
		if ( ! self::is_qualifying_payment( $payment_id ) ) {
			self::reverse_payment( $payment_id, 'not_qualifying' );
			return 0;
		}
		$gmv = LNKup_Connector_Rewards::booth_gmv( $payment_id );
		$opp = (int) get_post_meta( $payment_id, '_lnkup_opportunity_id', true );
		$market = '';
		if ( $org_id ) {
			$ref = LNKup_Connector::get_referral_for_user( $org_id );
			$market = $ref['market_slug'] ?? '';
		}
		if ( ! $market ) {
			$market = (string) ( $connector['market_slug'] ?? '' );
		}
		$hold = (int) LNKup_Connector_Rewards::terms()['hold_days'];
		$now  = current_time( 'mysql' );
		$available = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( $hold * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		global $wpdb;
		$table = LNKup_Connector_Tables::booking_attr_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE payment_id = %d", $payment_id ), ARRAY_A );
		$row      = array(
			'payment_id'     => $payment_id,
			'connector_id'   => $connector_id,
			'referral_id'    => $referral_id,
			'reason'         => $reason,
			'gmv'            => $gmv,
			'opportunity_id' => $opp,
			'market_slug'    => sanitize_title( $market ),
			'status'         => 'counted',
			'available_at'   => $existing['available_at'] ?? $available,
			'updated_at'     => $now,
		);
		if ( $existing ) {
			if ( 'reversed' === ( $existing['status'] ?? '' ) || 'disputed' === ( $existing['status'] ?? '' ) ) {
				return (int) $existing['id'];
			}
			$wpdb->update( $table, $row, array( 'id' => (int) $existing['id'] ) );
			return (int) $existing['id'];
		}
		$row['counted_at'] = $now;
		$wpdb->insert( $table, $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Reverse a booking attribution row.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $why        Reason.
	 */
	public static function reverse_payment( $payment_id, $why = 'refund' ) {
		global $wpdb;
		$table = LNKup_Connector_Tables::booking_attr_table();
		$status = 'disputed' === $why ? 'disputed' : 'reversed';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE payment_id = %d", absint( $payment_id ) ), ARRAY_A );
		if ( ! $row ) {
			return;
		}
		$wpdb->update(
			$table,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $row['id'] )
		);
		if ( ! empty( $row['connector_id'] ) ) {
			self::recalculate( (int) $row['connector_id'], 'reverse_' . $why );
		}
	}

	/**
	 * Refund hook — exclude the booking from rank.
	 *
	 * @param int $refund_id Refund ID.
	 */
	public static function on_refund_completed( $refund_id ) {
		global $wpdb;
		if ( ! class_exists( 'LNKup_Financial_Tables' ) ) {
			return;
		}
		$refunds = LNKup_Financial_Tables::refunds_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$refund = $wpdb->get_row( $wpdb->prepare( "SELECT payment_post_id FROM {$refunds} WHERE id = %d", absint( $refund_id ) ), ARRAY_A );
		$payment_id = (int) ( $refund['payment_post_id'] ?? 0 );
		if ( $payment_id ) {
			self::reverse_payment( $payment_id, 'refund' );
		}
	}

	/**
	 * Compute live metrics from the booking ledger (hold elapsed, counted only).
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function metrics( $connector_id ) {
		$metrics = self::empty_metrics();
		$connector_id = absint( $connector_id );
		if ( ! $connector_id ) {
			return $metrics;
		}
		global $wpdb;
		$table = LNKup_Connector_Tables::booking_attr_table();
		$now   = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE connector_id = %d AND status = 'counted' AND available_at <= %s",
				$connector_id,
				$now
			),
			ARRAY_A
		);
		$by_org = array();
		foreach ( (array) $rows as $row ) {
			$metrics['qualifying_bookings']++;
			$metrics['qualifying_gmv'] += (float) $row['gmv'];
			$org_id = 0;
			$ref_id = (int) ( $row['referral_id'] ?? 0 );
			if ( $ref_id ) {
				$ref = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT referred_user_id, target_role, first_opportunity_at, market_slug FROM ' . LNKup_Treasury_Tables::referrals_table() . ' WHERE id = %d',
						$ref_id
					),
					ARRAY_A
				);
				$role = (string) ( $ref['target_role'] ?? '' );
				if ( $ref && false !== strpos( $role, 'organizer' ) ) {
					$org_id = (int) $ref['referred_user_id'];
					if ( ! isset( $by_org[ $org_id ] ) ) {
						$by_org[ $org_id ] = array(
							'bookings'     => 0,
							'opps'         => array(),
							'published'    => ! empty( $ref['first_opportunity_at'] ),
							'market'       => (string) ( $ref['market_slug'] ?? $row['market_slug'] ),
						);
					}
				}
			}
			if ( $org_id ) {
				$by_org[ $org_id ]['bookings']++;
				$opp = (int) ( $row['opportunity_id'] ?? 0 );
				if ( $opp ) {
					$by_org[ $org_id ]['opps'][ $opp ] = true;
				}
			}
		}
		$cities = array();
		foreach ( $by_org as $org_id => $info ) {
			$verified = LNKup_Connector::organizer_is_verified( $org_id );
			$activated = $verified && ! empty( $info['published'] ) && (int) $info['bookings'] >= 3;
			if ( $activated ) {
				$metrics['activated_organizers']++;
				if ( count( $info['opps'] ) >= 2 ) {
					$metrics['recurring_organizers']++;
				}
				$slug = sanitize_title( (string) $info['market'] );
				if ( $slug ) {
					$cities[ $slug ] = true;
				}
			}
		}
		$metrics['approved_cities'] = count( $cities );
		$metrics['qualifying_gmv']  = round( (float) $metrics['qualifying_gmv'], 2 );
		return $metrics;
	}

	/**
	 * Recalculate and persist rank for one connector.
	 *
	 * @param int    $connector_id Connector ID.
	 * @param string $source       Trigger source.
	 */
	public static function recalculate( $connector_id, $source = 'manual' ) {
		$connector = LNKup_Connector::get( $connector_id );
		if ( ! $connector || LNKup_Connector::PROGRAM !== LNKup_Connector::program_of( $connector ) ) {
			return self::empty_dto();
		}
		if ( ! self::enabled() ) {
			return self::dto( $connector_id, false );
		}
		$approved = in_array( (string) ( $connector['status'] ?? '' ), array( 'active', 'paused' ), true );
		$onboard  = self::onboarding_complete( $connector_id );
		$metrics  = self::metrics( $connector_id );
		$eval     = self::evaluate(
			$metrics,
			$approved,
			$onboard,
			(string) ( $connector['current_rank'] ?? '' ),
			(string) ( $connector['highest_rank'] ?? '' )
		);
		$now = current_time( 'mysql' );
		$founding = ! empty( $connector['founding_partner'] ) ? 1 : ( self::should_mark_founding( $connector ) ? 1 : 0 );
		$data = array(
			'activated_organizer_count' => (int) $metrics['activated_organizers'],
			'recurring_organizer_count' => (int) $metrics['recurring_organizers'],
			'qualifying_booking_count'  => (int) $metrics['qualifying_bookings'],
			'qualifying_gmv'            => (float) $metrics['qualifying_gmv'],
			'approved_city_count'       => (int) $metrics['approved_cities'],
			'current_rank'              => $eval['current'],
			'highest_rank'              => $eval['highest'],
			'rank_calculated_at'        => $now,
			'founding_partner'          => $founding,
			'updated_at'                => $now,
		);
		if ( $eval['advanced'] ) {
			$data['rank_earned_at'] = $now;
			$data['rank_celebration_pending'] = $eval['current'];
			self::record_rank_event( $connector_id, 'rank_earned_' . $eval['current'], array(
				'from'   => $connector['current_rank'] ?? '',
				'to'     => $eval['current'],
				'source' => $source,
			) );
			self::maybe_queue_bonus( $connector_id, $eval['current'] );
		}
		global $wpdb;
		$wpdb->update(
			LNKup_Connector_Tables::connectors_table(),
			$data,
			array( 'id' => absint( $connector_id ) )
		);
		return self::dto( $connector_id, false );
	}

	/**
	 * Daily reconciliation.
	 */
	public static function recalculate_all() {
		if ( ! self::enabled() ) {
			return;
		}
		global $wpdb;
		$table = LNKup_Connector_Tables::connectors_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( "SELECT id FROM {$table} WHERE status IN ('active','paused') LIMIT 500" );
		foreach ( (array) $ids as $id ) {
			self::recalculate( (int) $id, 'daily' );
		}
	}

	/**
	 * Whether founding badge should be set.
	 *
	 * @param array $connector Connector row.
	 */
	public static function should_mark_founding( $connector ) {
		if ( ! empty( $connector['founding_partner'] ) ) {
			return true;
		}
		$s = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		$cutoff = (string) ( $s['connector_founding_cutoff'] ?? '' );
		$approved_at = (string) ( $connector['approved_at'] ?? '' );
		if ( ! $approved_at ) {
			return false;
		}
		if ( ! $cutoff ) {
			return true;
		}
		return strtotime( $approved_at ) <= strtotime( $cutoff . ' 23:59:59' );
	}

	/**
	 * Onboarding complete when every checklist step is done.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function onboarding_complete( $connector_id ) {
		$on = LNKup_Connector::get_onboarding( $connector_id );
		return ! empty( $on['phase1_complete'] );
	}

	/**
	 * Queue a one-time rank bonus (never auto-paid).
	 *
	 * @param int    $connector_id Connector ID.
	 * @param string $rank_key     Rank key.
	 */
	public static function maybe_queue_bonus( $connector_id, $rank_key ) {
		$defs = self::definitions();
		if ( empty( $defs[ $rank_key ] ) || (float) $defs[ $rank_key ]['bonus'] <= 0 ) {
			return 0;
		}
		if ( 'city_connector' === $rank_key ) {
			return 0;
		}
		if ( LNKup_Connector::PROGRAM !== LNKup_Connector::program_of( LNKup_Connector::get( $connector_id ) ) ) {
			return 0;
		}
		$key = self::bonus_key( $connector_id, $rank_key );
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$dup = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE idempotency_key = %s LIMIT 1", $key ) );
		if ( $dup ) {
			return (int) $dup;
		}
		$connector = LNKup_Connector::get( $connector_id );
		$row       = array(
			'referral_id'     => 0,
			'user_id'         => (int) ( $connector['user_id'] ?? 0 ),
			'connector_id'    => absint( $connector_id ),
			'event_type'      => 'connector_rank_bonus_' . sanitize_key( $rank_key ),
			'amount'          => (float) $defs[ $rank_key ]['bonus'],
			'status'          => 'pending',
			'reward_status'   => 'pending',
			'created_at'      => current_time( 'mysql' ),
			'available_at'    => current_time( 'mysql' ),
			'program'         => LNKup_Connector::PROGRAM,
			'idempotency_key' => $key,
		);
		$wpdb->insert( $table, $row );
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			self::record_rank_event( $connector_id, 'rank_bonus_created', array( 'rank' => $rank_key, 'reward_id' => $id, 'key' => $key ) );
			if ( class_exists( 'LNKup_Financial_Audit' ) ) {
				LNKup_Financial_Audit::log( 'connector_rank_bonus_queued', 'connector_reward', $id, array( 'rank' => $rank_key, 'key' => $key ) );
			}
		}
		return $id;
	}

	/**
	 * Reverse a rank bonus (admin).
	 *
	 * @param int $connector_id Connector ID.
	 * @param int $reward_id    Reward ID.
	 */
	public static function reverse_bonus( $connector_id, $reward_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND connector_id = %d", absint( $reward_id ), absint( $connector_id ) ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Bonus not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( 'paid' === ( $row['reward_status'] ?? '' ) ) {
			return new WP_Error( 'paid', __( 'Paid bonuses are not reversed automatically.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$wpdb->update( $table, array( 'reward_status' => 'reversed' ), array( 'id' => (int) $row['id'] ) );
		self::record_rank_event( $connector_id, 'rank_bonus_reversed', array( 'reward_id' => (int) $row['id'] ) );
		return true;
	}

	/**
	 * Admin override (may decrease). Writes audit event.
	 *
	 * @param int    $connector_id Connector ID.
	 * @param string $rank_key     Rank key.
	 * @param int    $admin_id     Admin ID.
	 */
	public static function override_rank( $connector_id, $rank_key, $admin_id = 0 ) {
		$rank_key = sanitize_key( $rank_key );
		if ( $rank_key && ! in_array( $rank_key, self::KEYS, true ) ) {
			return new WP_Error( 'invalid_rank', __( 'Unknown rank.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$connector = LNKup_Connector::get( $connector_id );
		if ( ! $connector ) {
			return new WP_Error( 'not_found', __( 'Connector not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$now = current_time( 'mysql' );
		$highest = $connector['highest_rank'] ?? '';
		if ( $rank_key && self::rank_index( $rank_key ) > self::rank_index( $highest ) ) {
			$highest = $rank_key;
		}
		global $wpdb;
		$wpdb->update(
			LNKup_Connector_Tables::connectors_table(),
			array(
				'current_rank'       => $rank_key,
				'highest_rank'       => $highest,
				'rank_earned_at'     => $rank_key ? $now : null,
				'rank_calculated_at' => $now,
				'updated_at'         => $now,
			),
			array( 'id' => absint( $connector_id ) )
		);
		self::record_rank_event( $connector_id, 'rank_corrected', array(
			'from'     => $connector['current_rank'] ?? '',
			'to'       => $rank_key,
			'admin_id' => $admin_id ?: get_current_user_id(),
		) );
		return self::dto( $connector_id, false );
	}

	/**
	 * Write a rank audit event (unique event_type avoids record_event dedupe).
	 *
	 * @param int    $connector_id Connector ID.
	 * @param string $event_type   Event type.
	 * @param array  $meta         Metadata.
	 */
	public static function record_rank_event( $connector_id, $event_type, $meta = array() ) {
		global $wpdb;
		$wpdb->insert(
			LNKup_Connector_Tables::events_table(),
			array(
				'connector_id'     => absint( $connector_id ),
				'referral_id'      => 0,
				'referred_user_id' => 0,
				'event_type'       => sanitize_key( $event_type ),
				'metadata'         => wp_json_encode( $meta ),
				'created_at'       => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Rank history for dashboard (no emails).
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function history( $connector_id ) {
		global $wpdb;
		$table = LNKup_Connector_Tables::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_type, metadata, created_at FROM {$table} WHERE connector_id = %d AND event_type LIKE %s ORDER BY created_at DESC LIMIT 40",
				absint( $connector_id ),
				$wpdb->esc_like( 'rank_' ) . '%'
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$meta = json_decode( (string) ( $row['metadata'] ?? '' ), true );
			$out[] = array(
				'event_type' => $row['event_type'],
				'created_at' => $row['created_at'],
				'meta'       => is_array( $meta ) ? $meta : array(),
			);
		}
		return $out;
	}

	/**
	 * Dashboard DTO. Recalc only if cache is missing.
	 *
	 * @param int  $connector_id Connector ID.
	 * @param bool $refresh      Recalc if cache empty.
	 */
	public static function dto( $connector_id, $refresh = true ) {
		$connector = LNKup_Connector::get( $connector_id );
		if ( ! $connector ) {
			return self::empty_dto();
		}
		if ( $refresh && empty( $connector['rank_calculated_at'] ) ) {
			return self::recalculate( $connector_id, 'dto' );
		}
		$defs    = self::definitions();
		$metrics = array(
			'activated_organizers' => (int) ( $connector['activated_organizer_count'] ?? 0 ),
			'recurring_organizers' => (int) ( $connector['recurring_organizer_count'] ?? 0 ),
			'qualifying_bookings'  => (int) ( $connector['qualifying_booking_count'] ?? 0 ),
			'qualifying_gmv'       => (float) ( $connector['qualifying_gmv'] ?? 0 ),
			'approved_cities'      => (int) ( $connector['approved_city_count'] ?? 0 ),
		);
		$current = (string) ( $connector['current_rank'] ?? '' );
		$next_key = '';
		$idx = self::rank_index( $current );
		if ( $idx < 0 ) {
			$next_key = 'city_connector';
		} elseif ( $idx < count( self::KEYS ) - 1 ) {
			$next_key = self::KEYS[ $idx + 1 ];
		}
		$next = $next_key && isset( $defs[ $next_key ] ) ? $defs[ $next_key ] : null;
		$rows = $next ? self::requirement_rows( $metrics, $next ) : array();
		$unlocked = array();
		foreach ( self::KEYS as $key ) {
			if ( $current && self::rank_index( $key ) <= self::rank_index( $current ) ) {
				$unlocked = array_merge( $unlocked, $defs[ $key ]['benefits'] );
			}
		}
		$pending = (string) ( $connector['rank_celebration_pending'] ?? '' );
		$ack     = (string) ( $connector['rank_celebration_ack'] ?? '' );
		$s       = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		$cele_on = ! isset( $s['connector_rank_celebration_enabled'] ) || ! empty( $s['connector_rank_celebration_enabled'] );
		$celebration = null;
		if ( $cele_on && $pending && $pending !== $ack && isset( $defs[ $pending ] ) ) {
			$celebration = array(
				'key'      => $pending,
				'name'     => $defs[ $pending ]['name'],
				'benefits' => $defs[ $pending ]['benefits'],
				'bonus'    => (float) $defs[ $pending ]['bonus'],
			);
		}
		return array(
			'current_rank' => array(
				'key'              => $current,
				'name'             => $current && isset( $defs[ $current ] ) ? $defs[ $current ]['name'] : '',
				'earned_at'        => $connector['rank_earned_at'] ?? null,
				'founding_partner' => ! empty( $connector['founding_partner'] ),
			),
			'metrics'      => $metrics,
			'next_rank'    => $next ? array(
				'key'              => $next['key'],
				'name'             => $next['name'],
				'progress_percent' => self::progress_percent( $rows ),
				'requirements'     => $rows,
			) : null,
			'history'      => self::history( $connector_id ),
			'next_action'  => self::next_rank_action( $rows, $next ),
			'celebration'  => $celebration,
			'benefits'     => $unlocked,
			'leaderboard_opt_in' => ! empty( $connector['leaderboard_opt_in'] ),
		);
	}

	/**
	 * Next recommended rank action from requirement gaps.
	 *
	 * @param array      $rows Requirement rows.
	 * @param array|null $next Next rank definition.
	 */
	public static function next_rank_action( $rows, $next ) {
		$gap = self::closest_gap( $rows );
		if ( ! $gap || ! $next ) {
			return array();
		}
		$name = $next['name'];
		$msgs = array(
			'activated_organizers' => sprintf(
				/* translators: 1: remaining count 2: rank name */
				_n( 'Activate %1$d more organizer to reach %2$s.', 'Activate %1$d more organizers to reach %2$s.', (int) $gap['remaining'], 'lnkup-marketplace' ),
				(int) $gap['remaining'],
				$name
			),
			'qualifying_bookings'  => sprintf(
				/* translators: 1: remaining count 2: rank name */
				__( 'Generate %1$d more completed bookings to reach %2$s.', 'lnkup-marketplace' ),
				(int) $gap['remaining'],
				$name
			),
			'qualifying_gmv'       => sprintf(
				/* translators: 1: remaining dollars 2: rank name */
				__( 'Generate $%1$s more qualifying GMV to reach %2$s.', 'lnkup-marketplace' ),
				number_format_i18n( (float) $gap['remaining'], 0 ),
				$name
			),
			'recurring_organizers' => sprintf(
				/* translators: 1: remaining count 2: rank name */
				__( 'Help %1$d organizers publish another opportunity.', 'lnkup-marketplace' ),
				(int) $gap['remaining'],
				$name
			),
			'approved_cities'      => __( 'Expand into one additional approved city to qualify for Regional Partner.', 'lnkup-marketplace' ),
		);
		return array(
			'id'          => 'rank_' . $gap['key'],
			'title'       => $msgs[ $gap['key'] ] ?? $gap['label'],
			'description' => $msgs[ $gap['key'] ] ?? '',
			'cta_view'    => 'ranks',
			'cta_label'   => __( 'View ranks', 'lnkup-marketplace' ),
			'open_share'  => in_array( $gap['key'], array( 'activated_organizers', 'qualifying_bookings', 'qualifying_gmv' ), true ),
		);
	}

	/**
	 * Roadmap for all five ranks.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function roadmap( $connector_id ) {
		$dto  = self::dto( $connector_id );
		$defs = self::definitions();
		$cur  = $dto['current_rank']['key'] ?? '';
		$earned = $dto['current_rank']['earned_at'] ?? null;
		$out  = array();
		foreach ( self::KEYS as $key ) {
			$def    = $defs[ $key ];
			$status = 'locked';
			if ( $cur && self::rank_index( $key ) < self::rank_index( $cur ) ) {
				$status = 'achieved';
			} elseif ( $key === $cur ) {
				$status = 'current';
			}
			$out[] = array(
				'key'          => $key,
				'name'         => $def['name'],
				'requirements' => self::requirement_rows( $dto['metrics'], $def ),
				'benefits'     => $def['benefits'],
				'bonus'        => (float) $def['bonus'],
				'status'       => $status,
				'earned_at'    => ( 'current' === $status || 'achieved' === $status ) ? $earned : null,
			);
		}
		return array(
			'ranks'            => $out,
			'founding_partner' => ! empty( $dto['current_rank']['founding_partner'] ),
		);
	}

	/**
	 * Acknowledge celebration (once).
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function acknowledge_celebration( $connector_id ) {
		$connector = LNKup_Connector::get( $connector_id );
		if ( ! $connector ) {
			return new WP_Error( 'not_found', __( 'Connector not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$pending = (string) ( $connector['rank_celebration_pending'] ?? '' );
		global $wpdb;
		$wpdb->update(
			LNKup_Connector_Tables::connectors_table(),
			array(
				'rank_celebration_ack'     => $pending,
				'rank_celebration_pending' => '',
				'updated_at'               => current_time( 'mysql' ),
			),
			array( 'id' => absint( $connector_id ) )
		);
		return self::dto( $connector_id, false );
	}

	/**
	 * Public city leaderboard (no earnings).
	 *
	 * @param string $market Market slug.
	 * @param string $period weekly|monthly|all.
	 */
	public static function leaderboard( $market = '', $period = 'all' ) {
		$s = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		if ( isset( $s['connector_leaderboard_enabled'] ) && empty( $s['connector_leaderboard_enabled'] ) ) {
			return array(
				'enabled'      => false,
				'period'       => $period,
				'method'       => __( 'Ranked by performance rank, then qualifying bookings, then activated organizers. Earnings are never shown.', 'lnkup-marketplace' ),
				'connectors'   => array(),
				'empty_reason' => 'disabled',
			);
		}
		$period = in_array( $period, array( 'weekly', 'monthly', 'all' ), true ) ? $period : 'all';
		$since  = '';
		if ( 'weekly' === $period ) {
			$since = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 7 * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		} elseif ( 'monthly' === $period ) {
			$since = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 30 * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		}
		global $wpdb;
		$ct = LNKup_Connector_Tables::connectors_table();
		$sql = "SELECT id, display_name, current_rank, qualifying_booking_count, activated_organizer_count, market_slug FROM {$ct}
			WHERE status = 'active' AND leaderboard_opt_in = 1 AND current_rank <> ''";
		$params = array();
		if ( $market ) {
			$sql     .= ' AND market_slug = %s';
			$params[] = sanitize_title( $market );
		}
		$sql .= ' ORDER BY FIELD(current_rank, \'regional_partner\',\'city_director\',\'city_captain\',\'market_builder\',\'city_connector\') ASC, qualifying_booking_count DESC, activated_organizer_count DESC LIMIT 25';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		$defs = self::definitions();
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$bookings = (int) $row['qualifying_booking_count'];
			if ( $since ) {
				$attr = LNKup_Connector_Tables::booking_attr_table();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$bookings = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$attr} WHERE connector_id = %d AND status = 'counted' AND counted_at >= %s",
						(int) $row['id'],
						$since
					)
				);
			}
			$out[] = array(
				'display_name'         => $row['display_name'],
				'rank_key'             => $row['current_rank'],
				'rank_name'            => $defs[ $row['current_rank'] ]['name'] ?? $row['current_rank'],
				'qualifying_bookings'  => $bookings,
				'activated_organizers' => (int) $row['activated_organizer_count'],
			);
		}
		$empty = count( $out ) < 3 ? 'too_few' : '';
		return array(
			'enabled'      => true,
			'period'       => $period,
			'method'       => __( 'Ranked by verified performance rank, then qualifying bookings, then activated organizers. Private earnings are never shown.', 'lnkup-marketplace' ),
			'connectors'   => $empty ? array() : $out,
			'empty_reason' => $empty,
		);
	}

	/**
	 * Latest unpaid rank bonus for a connector (admin list).
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function pending_bonus( $connector_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE connector_id = %d AND event_type LIKE %s AND reward_status IN ('pending','approved') ORDER BY id DESC LIMIT 1",
				absint( $connector_id ),
				$wpdb->esc_like( 'connector_rank_bonus_' ) . '%'
			),
			ARRAY_A
		);
	}

	/**
	 * Admin attribution dispute list.
	 */
	public static function disputed_attributions() {
		global $wpdb;
		$table = LNKup_Connector_Tables::booking_attr_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'disputed' ORDER BY updated_at DESC LIMIT 100", ARRAY_A );
	}

	/**
	 * Resolve a disputed booking attribution.
	 *
	 * @param int    $payment_id   Payment ID.
	 * @param int    $connector_id Connector ID.
	 * @param string $status       counted|reversed.
	 */
	public static function resolve_attribution( $payment_id, $connector_id, $status ) {
		$status = in_array( $status, array( 'counted', 'reversed' ), true ) ? $status : 'reversed';
		global $wpdb;
		$table = LNKup_Connector_Tables::booking_attr_table();
		$wpdb->update(
			$table,
			array(
				'connector_id' => absint( $connector_id ),
				'status'       => $status,
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'payment_id' => absint( $payment_id ) )
		);
		self::recalculate( $connector_id, 'attribution_resolve' );
		self::record_rank_event( $connector_id, 'rank_corrected', array( 'payment_id' => absint( $payment_id ), 'status' => $status ) );
		return true;
	}
}
