<?php
/**
 * Rule-based growth recommendations and market insights.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Growth_Recommendations {

	public static function generate( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		self::clear_stale( $user_id );
		$rules   = array();
		$profile = LNKup_Growth_Profile_Strength::score( $user_id );
		if ( is_wp_error( $profile ) || ! is_array( $profile ) ) {
			$profile = array( 'score' => 0, 'items' => array(), 'incomplete' => array() );
		}
		if ( ( $profile['score'] ?? 0 ) < 80 ) {
			$rules[] = array(
				'action_key' => 'profile_incomplete',
				'title'      => __( 'Complete your profile', 'lnkup-marketplace' ),
				'reason'     => sprintf( __( 'Your profile is %d%% complete. Stronger profiles get approved more often.', 'lnkup-marketplace' ), $profile['score'] ),
				'priority'   => 90,
			);
		}
		$kpis = LNKup_Growth_Kpis::for_vendor( $user_id );
		if ( ( $kpis['application_success_rate'] ?? 0 ) < 50 && ( $kpis['applications_submitted'] ?? 0 ) >= 3 ) {
			$rules[] = array(
				'action_key' => 'improve_approval',
				'title'      => __( 'Improve approval odds', 'lnkup-marketplace' ),
				'reason'     => __( 'Your approval rate is below marketplace average. Complete documents and respond faster.', 'lnkup-marketplace' ),
				'priority'   => 85,
			);
		}
		$expansion = self::market_expansion( $user_id );
		if ( ! empty( $expansion['suggested_cities'][0] ) ) {
			$city = $expansion['suggested_cities'][0]['city'];
			$rules[] = array(
				'action_key' => 'expand_city',
				'title'      => sprintf( __( 'Expand to %s', 'lnkup-marketplace' ), $city ),
				'reason'     => $expansion['suggested_cities'][0]['reason'] ?? __( 'Vendors like you perform well in this market.', 'lnkup-marketplace' ),
				'priority'   => 75,
			);
		}
		if ( ! empty( $expansion['suggested_categories'][0] ) ) {
			$cat = $expansion['suggested_categories'][0]['category'];
			$rules[] = array(
				'action_key' => 'add_category',
				'title'      => sprintf( __( 'Add %s category', 'lnkup-marketplace' ), $cat ),
				'reason'     => __( 'This category is trending and underrepresented in your profile.', 'lnkup-marketplace' ),
				'priority'   => 70,
			);
		}
		$compliance = LNKup_Documents::compliance_summary( $user_id );
		if ( ( $compliance['attention_count'] ?? 0 ) > 0 ) {
			$rules[] = array(
				'action_key' => 'renew_documents',
				'title'      => __( 'Renew expiring documents', 'lnkup-marketplace' ),
				'reason'     => sprintf( __( '%d documents need attention before your next application.', 'lnkup-marketplace' ), $compliance['attention_count'] ),
				'priority'   => 95,
			);
		}
		$matches = LNKup_Recommendations::get_for_user( $user_id, 1 );
		if ( ! empty( $matches[0] ) ) {
			$match = LNKup_Growth_Match::for_opportunity( $user_id, $matches[0]['opportunity_id'] );
			if ( is_array( $match ) && ( $match['match_score'] ?? 0 ) >= 85 ) {
				$rules[] = array(
					'action_key' => 'strong_match',
					'title'      => __( 'Apply to a strong match', 'lnkup-marketplace' ),
					'reason'     => sprintf( __( '%d%% match available — %s', 'lnkup-marketplace' ), $match['match_score'], $match['match_label'] ),
					'priority'   => 80,
				);
			}
		}
		foreach ( $rules as $rule ) {
			self::persist( $user_id, $rule );
		}
		do_action( 'lnkup_growth_recommendation_generated', $user_id, $rules );
		return self::active( $user_id );
	}

	public static function active( $user_id = 0 ) {
		global $wpdb;
		$user_id = $user_id ?: get_current_user_id();
		$table   = LNKup_Growth_Tables::recommendations_table();
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE vendor_user_id = %d AND dismissed_at IS NULL AND (expires_at IS NULL OR expires_at > %s) ORDER BY priority DESC, id DESC LIMIT 12",
				$user_id,
				current_time( 'mysql' )
			),
			ARRAY_A
		);
		return array_map(
			function ( $row ) {
				return array(
					'id'         => (int) $row['id'],
					'action_key' => $row['action_key'],
					'title'      => $row['title'],
					'reason'     => $row['reason'],
					'priority'   => (int) $row['priority'],
					'source'     => $row['source'],
				);
			},
			(array) $rows
		);
	}

	public static function dismiss( $rec_id, $user_id ) {
		global $wpdb;
		$table = LNKup_Growth_Tables::recommendations_table();
		$owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT vendor_user_id FROM {$table} WHERE id = %d", $rec_id ) );
		if ( $owner !== (int) $user_id && ! LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return false;
		}
		return (bool) $wpdb->update(
			$table,
			array( 'dismissed_at' => current_time( 'mysql' ) ),
			array( 'id' => $rec_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public static function personalized_insights( $user_id ) {
		$kpis   = LNKup_Growth_Kpis::for_vendor( $user_id );
		$insights = array();
		$by_cat = LNKup_Growth_Analytics::revenue_by_dimension( $user_id, 'category' );
		if ( count( $by_cat ) >= 2 && $by_cat[0]['amount'] > $by_cat[1]['amount'] ) {
			$insights[] = sprintf(
				__( '%1$s is outperforming %2$s for your business.', 'lnkup-marketplace' ),
				$by_cat[0]['label'],
				$by_cat[1]['label']
			);
		}
		$by_city = LNKup_Growth_Analytics::revenue_by_dimension( $user_id, 'city' );
		if ( ! empty( $by_city[0] ) ) {
			$insights[] = sprintf( __( '%s is your highest-performing city.', 'lnkup-marketplace' ), $by_city[0]['label'] );
		}
		if ( ( $kpis['growth_rate'] ?? 0 ) > 0 ) {
			$insights[] = sprintf( __( 'Revenue grew %s%% this period.', 'lnkup-marketplace' ), $kpis['growth_rate'] );
		}
		if ( ( $kpis['organizer_favorites'] ?? 0 ) > 0 ) {
			$insights[] = sprintf( __( '%d organizers have favorited you.', 'lnkup-marketplace' ), $kpis['organizer_favorites'] );
		}
		$profile_id = lnkup_get_vendor_profile_id( $user_id );
		if ( $profile_id ) {
			$badges = LNKup_Vendor_Badges::get_active_slugs( $profile_id );
			if ( in_array( 'top_rated', $badges, true ) ) {
				$insights[] = __( 'You have earned Top Rated status.', 'lnkup-marketplace' );
			}
		}
		return array_slice( $insights, 0, 6 );
	}

	public static function market_expansion( $user_id ) {
		$user_id      = $user_id ?: get_current_user_id();
		$profile_id   = lnkup_get_vendor_profile_id( $user_id );
		$home_city    = $profile_id ? get_post_meta( $profile_id, '_lnkup_city', true ) : '';
		$served       = array();
		$applications = lnkup_get_applications_for_user( $user_id, 'vendor' );
		foreach ( $applications as $app ) {
			$meta = LNKup_Vendor_Dashboard_Data::application_event_meta( $app );
			if ( $meta['city'] ) {
				$served[ $meta['city'] ] = true;
			}
		}
		$trending_cities = class_exists( 'LNKup_Market_Trends' ) ? LNKup_Market_Trends::cities( array( 'limit' => 10 ) ) : array();
		$suggested_cities = array();
		foreach ( $trending_cities as $tc ) {
			$city = $tc['city'] ?? $tc['label'] ?? '';
			if ( ! $city || $city === $home_city || isset( $served[ $city ] ) ) {
				continue;
			}
			$suggested_cities[] = array(
				'city'   => $city,
				'state'  => $tc['state'] ?? '',
				'reason' => sprintf( __( 'Fast-growing market near your success in %s', 'lnkup-marketplace' ), $home_city ?: __( 'your region', 'lnkup-marketplace' ) ),
			);
			if ( count( $suggested_cities ) >= 5 ) {
				break;
			}
		}
		$profile_cats = array();
		if ( $profile_id ) {
			$profile_cats = wp_get_post_terms( $profile_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
			if ( is_wp_error( $profile_cats ) ) {
				$profile_cats = array();
			}
		}
		$trending_cats = class_exists( 'LNKup_Market_Trends' ) ? LNKup_Market_Trends::categories( array( 'limit' => 10 ) ) : array();
		$suggested_cats = array();
		foreach ( $trending_cats as $tc ) {
			$cat = $tc['category'] ?? $tc['label'] ?? '';
			if ( ! $cat || in_array( $cat, $profile_cats, true ) ) {
				continue;
			}
			$suggested_cats[] = array(
				'category' => $cat,
				'reason'     => __( 'High demand category in marketplace', 'lnkup-marketplace' ),
			);
			if ( count( $suggested_cats ) >= 5 ) {
				break;
			}
		}
		return array(
			'suggested_cities'     => $suggested_cities,
			'suggested_states'     => array_values( array_unique( wp_list_pluck( $suggested_cities, 'state' ) ) ),
			'suggested_categories' => $suggested_cats,
			'growth_opportunities' => array_slice( array_merge( $suggested_cities, $suggested_cats ), 0, 8 ),
		);
	}

	public static function market_insights() {
		$summary = class_exists( 'LNKup_Financial_Reports' ) ? LNKup_Financial_Reports::marketplace_summary() : array();
		return array(
			'avg_vendor_fee'       => $summary['avg_vendor_fee'] ?? 0,
			'trending_cities'    => class_exists( 'LNKup_Market_Trends' ) ? LNKup_Market_Trends::cities( array( 'limit' => 5 ) ) : array(),
			'trending_categories'=> class_exists( 'LNKup_Market_Trends' ) ? LNKup_Market_Trends::categories( array( 'limit' => 5 ) ) : array(),
			'fastest_growing'    => class_exists( 'LNKup_Trending' ) ? LNKup_Trending::get_section( 'trending', 5 ) : array(),
			'best_seasons'       => array( __( 'Summer', 'lnkup-marketplace' ), __( 'Fall', 'lnkup-marketplace' ) ),
		);
	}

	private static function persist( $user_id, $rule ) {
		global $wpdb;
		$table = LNKup_Growth_Tables::recommendations_table();
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE vendor_user_id = %d AND action_key = %s AND dismissed_at IS NULL LIMIT 1",
				$user_id,
				$rule['action_key']
			)
		);
		if ( $exists ) {
			$wpdb->update(
				$table,
				array(
					'title'    => $rule['title'],
					'reason'   => $rule['reason'],
					'priority' => (int) $rule['priority'],
				),
				array( 'id' => (int) $exists )
			);
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'vendor_user_id' => $user_id,
				'action_key'     => $rule['action_key'],
				'title'          => $rule['title'],
				'reason'         => $rule['reason'],
				'priority'       => (int) $rule['priority'],
				'source'         => 'rules',
				'created_at'     => current_time( 'mysql' ),
			)
		);
	}

	private static function clear_stale( $user_id ) {
		global $wpdb;
		$table = LNKup_Growth_Tables::recommendations_table();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE vendor_user_id = %d AND dismissed_at IS NULL AND created_at < %s",
				$user_id,
				gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
			)
		);
	}
}
