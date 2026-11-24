<?php
/**
 * Vendor growth KPI calculations.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Growth_Kpis {

	/**
	 * Compute all dashboard KPIs for a vendor.
	 *
	 * @param int $user_id Vendor user ID.
	 */
	public static function for_vendor( $user_id = 0 ) {
		$user_id      = $user_id ?: get_current_user_id();
		$cached       = get_transient( 'lnkup_growth_kpis_' . $user_id );
		if ( false !== $cached ) {
			return $cached;
		}

		$ctx          = LNKup_Vendor_Dashboard_Data::context( $user_id );
		$applications = $ctx['applications'];
		$payments     = $ctx['payments'];
		$profile_id   = $ctx['profile_id'];

		$lifetime     = self::lifetime_revenue( $payments );
		$year_rev     = LNKup_Vendor_Dashboard_Data::earned_this_season( $payments );
		$month_rev    = self::revenue_this_month( $payments );
		$upcoming_rev = self::upcoming_revenue( $applications );
		$completed    = self::count_by_status( $applications, array( 'completed' ) );
		$upcoming     = self::count_upcoming_events( $applications );
		$avg_event    = $completed > 0 ? round( $lifetime / $completed, 2 ) : 0;

		$reputation   = $profile_id && class_exists( 'LNKup_Vendor_Reputation' )
			? LNKup_Vendor_Reputation::get( $profile_id )
			: array();
		$vendor_score = (int) ( $reputation['score'] ?? 0 );
		$ranking      = self::marketplace_ranking( $vendor_score );
		$repeat_pct   = self::repeat_organizer_pct( $applications );
		$app_stats    = self::application_stats( $applications );
		$rebooking    = self::rebooking_rate( $applications );
		$cities       = self::cities_served( $applications );
		$growth_rate  = LNKup_Growth_Snapshots::growth_rate( $user_id );
		$reviews      = $profile_id ? LNKup_Vendor_Reviews::get_for_vendor( $profile_id, 3 ) : array();
		$profile_str  = LNKup_Growth_Profile_Strength::score( $user_id );
		if ( is_wp_error( $profile_str ) || ! is_array( $profile_str ) ) {
			$profile_str = array( 'score' => 0, 'items' => array(), 'incomplete' => array() );
		}

		$data = array(
			'lifetime_revenue'        => $lifetime,
			'lifetime_spend'          => $lifetime,
			'spend_definition'        => 'vendor_booth_spend',
			'revenue_this_year'       => $year_rev,
			'revenue_this_month'      => $month_rev,
			'upcoming_revenue'        => $upcoming_rev,
			'completed_events'        => $completed,
			'upcoming_events'         => $upcoming,
			'average_event_revenue'   => $avg_event,
			'marketplace_ranking'     => $ranking,
			'vendor_score'            => $vendor_score,
			'repeat_organizer_pct'    => $repeat_pct,
			'application_success_rate'=> $app_stats['approval_rate'],
			'rebooking_rate'          => $rebooking,
			'cities_served'           => count( $cities ),
			'growth_rate'             => $growth_rate,
			'recent_reviews'          => array_slice( $reviews, 0, 3 ),
			'profile_strength'        => $profile_str['score'],
			'applications_submitted'  => $app_stats['submitted'],
			'applications_approved'   => $app_stats['approved'],
			'applications_rejected'   => $app_stats['rejected'],
			'payment_completion'      => self::payment_completion( $applications ),
			'organizer_invitations'   => self::organizer_invitations( $user_id ),
			'organizer_favorites'     => self::organizer_favorites( $user_id ),
			'repeat_organizers'       => self::repeat_organizers( $applications ),
			'repeat_cities'           => self::repeat_cities( $applications ),
			'completed_bookings'      => LNKup_Vendor_Dashboard_Data::count_confirmed_bookings( $applications ),
			'canceled_bookings'       => self::count_by_status( $applications, array( 'cancelled' ) ),
			'waitlists'               => self::count_by_status( $applications, array( 'waitlisted' ) ),
		);

		set_transient( 'lnkup_growth_kpis_' . $user_id, $data, 10 * MINUTE_IN_SECONDS );
		return $data;
	}

	public static function lifetime_revenue( $payments ) {
		$sum = 0.0;
		foreach ( $payments as $payment ) {
			if ( 'paid' === get_post_meta( $payment->ID, '_lnkup_payment_status', true ) ) {
				$sum += (float) get_post_meta( $payment->ID, '_lnkup_vendor_spot_fee', true );
			}
		}
		return round( $sum, 2 );
	}

	private static function revenue_this_month( $payments ) {
		$month = gmdate( 'Y-m' );
		$sum   = 0.0;
		foreach ( $payments as $payment ) {
			if ( 'paid' !== get_post_meta( $payment->ID, '_lnkup_payment_status', true ) ) {
				continue;
			}
			$paid_at = get_post_meta( $payment->ID, '_lnkup_paid_at', true );
			if ( $paid_at && 0 === strpos( $paid_at, $month ) ) {
				$sum += (float) get_post_meta( $payment->ID, '_lnkup_vendor_spot_fee', true );
			}
		}
		return round( $sum, 2 );
	}

	private static function upcoming_revenue( $applications ) {
		$sum = 0.0;
		foreach ( $applications as $app ) {
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( ! in_array( $status, array( 'approved', 'payment_required', 'paid' ), true ) ) {
				continue;
			}
			$meta = LNKup_Vendor_Dashboard_Data::application_event_meta( $app );
			if ( $meta['date'] && strtotime( $meta['date'] ) < strtotime( 'today' ) ) {
				continue;
			}
			$sum += (float) $meta['fee'];
		}
		return round( $sum, 2 );
	}

	private static function count_by_status( $applications, $statuses ) {
		return count(
			array_filter(
				$applications,
				function ( $a ) use ( $statuses ) {
					return in_array( get_post_meta( $a->ID, '_lnkup_application_status', true ), $statuses, true );
				}
			)
		);
	}

	private static function count_upcoming_events( $applications ) {
		$count = 0;
		foreach ( $applications as $app ) {
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( ! in_array( $status, array( 'paid', 'approved', 'payment_required' ), true ) ) {
				continue;
			}
			$meta = LNKup_Vendor_Dashboard_Data::application_event_meta( $app );
			if ( $meta['date'] && strtotime( $meta['date'] ) >= strtotime( 'today' ) ) {
				$count++;
			}
		}
		return $count;
	}

	private static function marketplace_ranking( $score ) {
		if ( $score >= 90 ) {
			return __( 'Top Rated', 'lnkup-marketplace' );
		}
		if ( $score >= 75 ) {
			return __( 'Highly Ranked', 'lnkup-marketplace' );
		}
		if ( $score >= 50 ) {
			return __( 'Rising', 'lnkup-marketplace' );
		}
		return __( 'Building', 'lnkup-marketplace' );
	}

	private static function repeat_organizer_pct( $applications ) {
		$orgs = array();
		foreach ( $applications as $app ) {
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( ! in_array( $status, array( 'paid', 'completed' ), true ) ) {
				continue;
			}
			$oid = (int) get_post_meta( $app->ID, '_lnkup_organizer_user_id', true );
			if ( $oid ) {
				$orgs[ $oid ] = ( $orgs[ $oid ] ?? 0 ) + 1;
			}
		}
		if ( empty( $orgs ) ) {
			return 0;
		}
		$repeat = count( array_filter( $orgs, function ( $c ) { return $c > 1; } ) );
		return round( ( $repeat / count( $orgs ) ) * 100, 1 );
	}

	private static function application_stats( $applications ) {
		$submitted = count( $applications );
		$approved  = 0;
		$rejected  = 0;
		foreach ( $applications as $app ) {
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( in_array( $status, array( 'approved', 'payment_required', 'paid', 'completed' ), true ) ) {
				$approved++;
			}
			if ( 'rejected' === $status ) {
				$rejected++;
			}
		}
		$rate = $submitted ? round( ( $approved / $submitted ) * 100, 1 ) : 0;
		return array(
			'submitted'     => $submitted,
			'approved'      => $approved,
			'rejected'      => $rejected,
			'approval_rate' => $rate,
		);
	}

	private static function rebooking_rate( $applications ) {
		$completed = self::count_by_status( $applications, array( 'completed' ) );
		$repeat    = self::repeat_organizers( $applications );
		return $completed > 0 ? round( ( $repeat / max( 1, $completed ) ) * 100, 1 ) : 0;
	}

	private static function cities_served( $applications ) {
		$cities = array();
		foreach ( $applications as $app ) {
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( ! in_array( $status, array( 'paid', 'completed' ), true ) ) {
				continue;
			}
			$meta = LNKup_Vendor_Dashboard_Data::application_event_meta( $app );
			if ( $meta['city'] ) {
				$cities[ $meta['city'] . ( $meta['state'] ? ', ' . $meta['state'] : '' ) ] = true;
			}
		}
		return array_keys( $cities );
	}

	private static function payment_completion( $applications ) {
		$needs = 0;
		$paid  = 0;
		foreach ( $applications as $app ) {
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( in_array( $status, array( 'approved', 'payment_required', 'paid', 'completed' ), true ) ) {
				$needs++;
				if ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
					$paid++;
				}
			}
		}
		return $needs ? round( ( $paid / $needs ) * 100, 1 ) : 0;
	}

	private static function organizer_invitations( $user_id ) {
		$invites = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_invite',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_vendor_user_id',
				'meta_value'     => $user_id,
			)
		);
		return count( $invites );
	}

	private static function organizer_favorites( $user_id ) {
		if ( ! $user_id ) {
			return 0;
		}
		global $wpdb;
		$table = LNKup_Organizer_CRM_Tables::rel_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE vendor_user_id = %d AND is_favorite = 1",
				$user_id
			)
		);
	}

	private static function repeat_organizers( $applications ) {
		$orgs = array();
		foreach ( $applications as $app ) {
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( ! in_array( $status, array( 'paid', 'completed' ), true ) ) {
				continue;
			}
			$oid = (int) get_post_meta( $app->ID, '_lnkup_organizer_user_id', true );
			if ( $oid ) {
				$orgs[ $oid ] = ( $orgs[ $oid ] ?? 0 ) + 1;
			}
		}
		return count( array_filter( $orgs, function ( $c ) { return $c > 1; } ) );
	}

	private static function repeat_cities( $applications ) {
		$cities = array();
		foreach ( $applications as $app ) {
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( ! in_array( $status, array( 'paid', 'completed' ), true ) ) {
				continue;
			}
			$meta = LNKup_Vendor_Dashboard_Data::application_event_meta( $app );
			if ( $meta['city'] ) {
				$key = $meta['city'];
				$cities[ $key ] = ( $cities[ $key ] ?? 0 ) + 1;
			}
		}
		return count( array_filter( $cities, function ( $c ) { return $c > 1; } ) );
	}

	public static function invalidate_cache( $user_id ) {
		delete_transient( 'lnkup_growth_kpis_' . $user_id );
		delete_transient( 'lnkup_growth_vendor_' . $user_id );
	}
}
