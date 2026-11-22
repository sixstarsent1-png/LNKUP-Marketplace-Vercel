<?php
/**
 * Deterministic, explainable vendor <-> opportunity matching.
 *
 * Pure functions over plain arrays (no WordPress calls) so the rules can be
 * unit-tested in isolation. LNKup_Match_Service builds the inputs from
 * canonical CPT data and the derived index tables.
 *
 * Vendor input keys: profile_id, user_id, active, discoverable, categories
 * (list of {term_id,name,group}), primary_category, city, state, lat, lng,
 * radius_miles, travel_national, cities_served, states_served, event_types,
 * fee_max, env_pref, available_months, blackout_dates, booked_dates,
 * power_needed, water_needed, food_truck, approved_documents,
 * past_organizers, favorite_organizers, favorite_opportunities,
 * applied_opportunities, invited_opportunities, related_organizers.
 *
 * Opportunity input keys: opportunity_id, event_id, organizer_user_id,
 * tour_id, status, event_published, event_date, deadline, spots_remaining,
 * invite_only, categories (list of {term_id,name,group}), city, state, lat,
 * lng, vendor_fee, indoor, outdoor, power_available, water_available,
 * food_truck_allowed, event_types, required_documents, trending_score.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Match_Engine {

	const DEFAULT_LOCAL_RADIUS = 50;
	const MIN_CONFIDENCE       = 40;

	const BLOCK_OPPORTUNITY_CLOSED = 'opportunity_closed';
	const BLOCK_EVENT_UNPUBLISHED  = 'event_unpublished';
	const BLOCK_EVENT_PAST         = 'event_past';
	const BLOCK_DEADLINE_PASSED    = 'deadline_passed';
	const BLOCK_NO_INVENTORY       = 'no_inventory';
	const BLOCK_INVITE_REQUIRED    = 'invite_required';
	const BLOCK_VENDOR_INACTIVE    = 'vendor_inactive';
	const BLOCK_CATEGORY_MISMATCH  = 'category_mismatch';
	const BLOCK_ALREADY_APPLIED    = 'already_applied';
	const BLOCK_NOT_DISCOVERABLE   = 'not_discoverable';

	/**
	 * Full evaluation: hard eligibility first, then weighted relevance.
	 *
	 * @param array $vendor  Vendor input.
	 * @param array $opp     Opportunity input.
	 * @param array $weights Factor weights keyed by factor.
	 * @param array $context today (Y-m-d), side (vendor|organizer), min_score.
	 * @return array
	 */
	public static function evaluate( array $vendor, array $opp, array $weights, array $context = array() ) {
		$eligibility = self::eligibility( $vendor, $opp, $context );
		$result      = array(
			'profile_id'     => (int) ( $vendor['profile_id'] ?? 0 ),
			'opportunity_id' => (int) ( $opp['opportunity_id'] ?? 0 ),
			'eligible'       => $eligibility['eligible'],
			'blocks'         => $eligibility['blocks'],
			'score'          => 0,
			'confidence'     => 0,
			'factors'        => array(),
			'reasons'        => array(),
			'recommended'    => false,
			'suppressed'     => array(),
		);
		if ( ! $eligibility['eligible'] ) {
			$result['suppressed'] = $eligibility['blocks'];
			return $result;
		}

		$scored               = self::score( $vendor, $opp, $weights );
		$result['score']      = $scored['score'];
		$result['confidence'] = $scored['confidence'];
		$result['factors']    = $scored['factors'];
		$result['reasons']    = $scored['reasons'];

		$min_score = isset( $context['min_score'] ) ? (int) $context['min_score'] : 40;
		if ( $result['score'] < $min_score ) {
			$result['suppressed'][] = 'below_min_score';
		}
		if ( $result['confidence'] < self::MIN_CONFIDENCE ) {
			$result['suppressed'][] = 'low_confidence';
		}
		if ( 0.0 === $result['factors']['geo']['score'] ) {
			$result['suppressed'][] = 'outside_market';
		}
		$result['recommended'] = empty( $result['suppressed'] );
		return $result;
	}

	/**
	 * Hard eligibility rules. Any block excludes the pair regardless of score.
	 *
	 * @return array{eligible:bool,blocks:string[]}
	 */
	public static function eligibility( array $vendor, array $opp, array $context = array() ) {
		$today  = $context['today'] ?? gmdate( 'Y-m-d' );
		$side   = $context['side'] ?? 'vendor';
		$blocks = array();

		if ( 'open' !== ( $opp['status'] ?? 'open' ) ) {
			$blocks[] = self::BLOCK_OPPORTUNITY_CLOSED;
		}
		if ( empty( $opp['event_published'] ) ) {
			$blocks[] = self::BLOCK_EVENT_UNPUBLISHED;
		}
		if ( ! empty( $opp['event_date'] ) && $opp['event_date'] < $today ) {
			$blocks[] = self::BLOCK_EVENT_PAST;
		}
		if ( ! empty( $opp['deadline'] ) && $opp['deadline'] < $today ) {
			$blocks[] = self::BLOCK_DEADLINE_PASSED;
		}
		if ( (int) ( $opp['spots_remaining'] ?? 0 ) <= 0 ) {
			$blocks[] = self::BLOCK_NO_INVENTORY;
		}
		$opp_id = (int) ( $opp['opportunity_id'] ?? 0 );
		if ( 'vendor' === $side && ! empty( $opp['invite_only'] ) && ! in_array( $opp_id, array_map( 'intval', (array) ( $vendor['invited_opportunities'] ?? array() ) ), true ) ) {
			$blocks[] = self::BLOCK_INVITE_REQUIRED;
		}
		if ( empty( $vendor['active'] ) ) {
			$blocks[] = self::BLOCK_VENDOR_INACTIVE;
		}
		$category = self::category_match( $vendor, $opp );
		if ( 'none' === $category['kind'] ) {
			$blocks[] = self::BLOCK_CATEGORY_MISMATCH;
		}
		if ( in_array( $opp_id, array_map( 'intval', (array) ( $vendor['applied_opportunities'] ?? array() ) ), true ) ) {
			$blocks[] = self::BLOCK_ALREADY_APPLIED;
		}
		if ( 'organizer' === $side && empty( $vendor['discoverable'] ) ) {
			$organizer = (int) ( $opp['organizer_user_id'] ?? 0 );
			if ( ! in_array( $organizer, array_map( 'intval', (array) ( $vendor['related_organizers'] ?? array() ) ), true ) ) {
				$blocks[] = self::BLOCK_NOT_DISCOVERABLE;
			}
		}

		return array(
			'eligible' => empty( $blocks ),
			'blocks'   => $blocks,
		);
	}

	/**
	 * Weighted relevance. Factors without data are dropped and the remaining
	 * weights rescaled, so missing history never pushes new users down.
	 *
	 * @return array{score:int,confidence:int,factors:array,reasons:array}
	 */
	public static function score( array $vendor, array $opp, array $weights ) {
		$factors = array(
			'category'     => self::factor_category( $vendor, $opp ),
			'geo'          => self::factor_geo( $vendor, $opp ),
			'requirements' => self::factor_requirements( $vendor, $opp ),
			'preferences'  => self::factor_preferences( $vendor, $opp ),
			'dates'        => self::factor_dates( $vendor, $opp ),
			'signals'      => self::factor_signals( $vendor, $opp ),
		);

		$total_weight   = 0;
		$present_weight = 0;
		$weighted       = 0.0;
		$reasons        = array();
		foreach ( $factors as $key => $factor ) {
			$weight                    = max( 0, (int) ( $weights[ $key ] ?? 0 ) );
			$factors[ $key ]['weight'] = $weight;
			$total_weight             += $weight;
			if ( null === $factor['score'] ) {
				continue;
			}
			$present_weight += $weight;
			$weighted       += $weight * $factor['score'];
			foreach ( $factor['reasons'] as $reason ) {
				$reasons[] = $reason;
			}
		}

		$score      = $present_weight > 0 ? (int) round( ( $weighted / $present_weight ) * 100 ) : 0;
		$confidence = $total_weight > 0 ? (int) round( ( $present_weight / $total_weight ) * 100 ) : 0;

		return array(
			'score'      => max( 0, min( 100, $score ) ),
			'confidence' => max( 0, min( 100, $confidence ) ),
			'factors'    => $factors,
			'reasons'    => array_slice( $reasons, 0, 4 ),
		);
	}

	/**
	 * Category relationship between vendor and opportunity.
	 *
	 * @return array{kind:string,label:string}  kind: primary|exact|group|open|unknown|none
	 */
	public static function category_match( array $vendor, array $opp ) {
		$opp_cats    = (array) ( $opp['categories'] ?? array() );
		$vendor_cats = (array) ( $vendor['categories'] ?? array() );
		if ( empty( $opp_cats ) ) {
			return array( 'kind' => 'open', 'label' => '' );
		}
		if ( empty( $vendor_cats ) ) {
			return array( 'kind' => 'unknown', 'label' => '' );
		}
		$opp_terms  = array();
		$opp_groups = array();
		foreach ( $opp_cats as $cat ) {
			$opp_terms[ (int) ( $cat['term_id'] ?? 0 ) ] = (string) ( $cat['name'] ?? '' );
			if ( ! empty( $cat['group'] ) ) {
				$opp_groups[ (string) $cat['group'] ] = (string) ( $cat['name'] ?? '' );
			}
		}
		$primary = (int) ( $vendor['primary_category'] ?? 0 );
		$best    = array( 'kind' => 'none', 'label' => '' );
		$rank    = array( 'none' => 0, 'group' => 1, 'exact' => 2, 'primary' => 3 );
		foreach ( $vendor_cats as $cat ) {
			$term_id = (int) ( $cat['term_id'] ?? 0 );
			$kind    = 'none';
			if ( $term_id && isset( $opp_terms[ $term_id ] ) ) {
				$kind = $primary && $term_id === $primary ? 'primary' : 'exact';
			} elseif ( ! empty( $cat['group'] ) && isset( $opp_groups[ (string) $cat['group'] ] ) ) {
				$kind = 'group';
			}
			if ( $rank[ $kind ] > $rank[ $best['kind'] ] ) {
				$best = array(
					'kind'  => $kind,
					'label' => 'group' === $kind ? $opp_groups[ (string) $cat['group'] ] : (string) ( $cat['name'] ?? '' ),
				);
			}
		}
		return $best;
	}

	/**
	 * Category factor.
	 */
	private static function factor_category( array $vendor, array $opp ) {
		$match = self::category_match( $vendor, $opp );
		switch ( $match['kind'] ) {
			case 'primary':
				return self::factor( 1.0, $match['kind'], array( self::reason( 'category', sprintf( self::t( 'Matches your %s category' ), $match['label'] ), $match['label'] ) ) );
			case 'exact':
				return self::factor( 0.85, $match['kind'], array( self::reason( 'category', sprintf( self::t( 'Accepting %s vendors' ), $match['label'] ), $match['label'] ) ) );
			case 'group':
				return self::factor( 0.6, $match['kind'], array( self::reason( 'category_group', sprintf( self::t( 'Accepting related %s vendors' ), $match['label'] ), $match['label'] ) ) );
			case 'open':
				return empty( $vendor['categories'] )
					? self::factor( null, 'open', array() )
					: self::factor( 0.7, 'open', array( self::reason( 'category_open', self::t( 'Open to all vendor categories' ) ) ) );
			default:
				return self::factor( null, $match['kind'], array() );
		}
	}

	/**
	 * Geographic factor.
	 */
	private static function factor_geo( array $vendor, array $opp ) {
		$opp_city  = self::norm( $opp['city'] ?? '' );
		$opp_state = strtoupper( trim( (string) ( $opp['state'] ?? '' ) ) );
		$opp_lat   = self::coord( $opp['lat'] ?? null, 90 );
		$opp_lng   = self::coord( $opp['lng'] ?? null, 180 );
		$has_opp   = '' !== $opp_city || '' !== $opp_state || ( null !== $opp_lat && null !== $opp_lng );

		$v_city   = self::norm( $vendor['city'] ?? '' );
		$v_state  = strtoupper( trim( (string) ( $vendor['state'] ?? '' ) ) );
		$v_lat    = self::coord( $vendor['lat'] ?? null, 90 );
		$v_lng    = self::coord( $vendor['lng'] ?? null, 180 );
		$cities   = array_map( array( __CLASS__, 'norm' ), (array) ( $vendor['cities_served'] ?? array() ) );
		$states   = array_map( 'strtoupper', array_map( 'trim', (array) ( $vendor['states_served'] ?? array() ) ) );
		$national = ! empty( $vendor['travel_national'] );
		$has_vend = '' !== $v_city || '' !== $v_state || null !== $v_lat || $cities || $states || $national;

		if ( ! $has_opp || ! $has_vend ) {
			return self::factor( null, 'unknown', array() );
		}

		$label = trim( (string) ( $opp['city'] ?? '' ) );
		if ( '' !== $opp_city ) {
			foreach ( $cities as $served ) {
				$served_city = trim( explode( ',', $served )[0] );
				if ( $served_city === $opp_city ) {
					return self::factor( 1.0, 'preferred_city', array( self::reason( 'preferred_city', sprintf( self::t( 'In %s, one of your selected markets' ), $label ), $label ) ) );
				}
			}
			if ( $v_city === $opp_city && ( '' === $v_state || '' === $opp_state || $v_state === $opp_state ) ) {
				return self::factor( 1.0, 'home_city', array( self::reason( 'home_city', sprintf( self::t( 'In your home city, %s' ), $label ), $label ) ) );
			}
		}

		$best = self::factor( 0.0, 'outside', array() );
		if ( null !== $v_lat && null !== $v_lng && null !== $opp_lat && null !== $opp_lng ) {
			$radius   = (int) ( $vendor['radius_miles'] ?? 0 );
			$radius   = $radius > 0 ? $radius : self::DEFAULT_LOCAL_RADIUS;
			$distance = self::distance_miles( $v_lat, $v_lng, $opp_lat, $opp_lng );
			if ( $distance <= $radius ) {
				$value = 0.9 - 0.4 * ( $distance / $radius );
				$best  = self::factor(
					$value,
					'within_radius',
					array( self::reason( 'within_radius', sprintf( self::t( 'About %d miles from your business' ), (int) round( $distance ) ), (string) (int) round( $distance ) ) )
				);
				$best['distance_miles'] = round( $distance, 1 );
			}
		}
		if ( $best['score'] < 0.6 && '' !== $opp_state && in_array( $opp_state, $states, true ) ) {
			$best = self::factor( 0.6, 'preferred_state', array( self::reason( 'preferred_state', sprintf( self::t( 'In %s, a state you serve' ), $opp_state ), $opp_state ) ) );
		}
		if ( $best['score'] < 0.4 && $national ) {
			$best = self::factor( 0.4, 'national', array( self::reason( 'national', self::t( 'You are open to traveling nationally' ) ) ) );
		}
		return $best;
	}

	/**
	 * Document readiness and facility compatibility.
	 */
	private static function factor_requirements( array $vendor, array $opp ) {
		$parts    = array();
		$reasons  = array();
		$required = array_values( array_filter( (array) ( $opp['required_documents'] ?? array() ) ) );
		$approved = (array) ( $vendor['approved_documents'] ?? array() );
		if ( $required ) {
			$have    = count( array_intersect( $required, $approved ) );
			$parts[] = $have / count( $required );
			if ( $have === count( $required ) ) {
				$reasons[] = self::reason( 'documents_ready', self::t( 'Your required documents are already approved' ) );
			}
		} else {
			$parts[] = 1.0;
		}
		if ( ! empty( $vendor['power_needed'] ) ) {
			$parts[] = ! empty( $opp['power_available'] ) ? 1.0 : 0.0;
		}
		if ( ! empty( $vendor['water_needed'] ) ) {
			$parts[] = ! empty( $opp['water_available'] ) ? 1.0 : 0.0;
		}
		if ( ! empty( $vendor['food_truck'] ) ) {
			$ok      = ! empty( $opp['food_truck_allowed'] );
			$parts[] = $ok ? 1.0 : 0.0;
			if ( $ok ) {
				$reasons[] = self::reason( 'food_truck', self::t( 'Food trucks are allowed' ) );
			}
		}
		return self::factor( array_sum( $parts ) / count( $parts ), 'requirements', $reasons );
	}

	/**
	 * Booth price, indoor/outdoor and event-type preferences.
	 */
	private static function factor_preferences( array $vendor, array $opp ) {
		$parts   = array();
		$reasons = array();
		$fee_max = isset( $vendor['fee_max'] ) && '' !== $vendor['fee_max'] && null !== $vendor['fee_max'] ? (float) $vendor['fee_max'] : null;
		if ( null !== $fee_max && $fee_max > 0 && isset( $opp['vendor_fee'] ) ) {
			$fee = (float) $opp['vendor_fee'];
			if ( $fee <= $fee_max ) {
				$parts[]   = 1.0;
				$reasons[] = self::reason( 'within_budget', self::t( 'Booth fee is within your budget' ) );
			} else {
				$parts[] = max( 0.0, 1 - ( ( $fee - $fee_max ) / $fee_max ) );
			}
		}
		$env = (string) ( $vendor['env_pref'] ?? '' );
		if ( in_array( $env, array( 'indoor', 'outdoor' ), true ) && ( ! empty( $opp['indoor'] ) || ! empty( $opp['outdoor'] ) ) ) {
			$parts[] = ! empty( $opp[ $env ] ) ? 1.0 : 0.0;
		}
		$wanted = array_map( array( __CLASS__, 'norm' ), (array) ( $vendor['event_types'] ?? array() ) );
		if ( $wanted ) {
			$types = array_map( array( __CLASS__, 'norm' ), (array) ( $opp['event_types'] ?? array() ) );
			$hit   = array_values( array_intersect( $wanted, $types ) );
			if ( $hit ) {
				$parts[] = 1.0;
				foreach ( (array) ( $opp['event_types'] ?? array() ) as $type ) {
					if ( self::norm( $type ) === $hit[0] ) {
						$reasons[] = self::reason( 'event_type', sprintf( self::t( 'A %s, one of your preferred event types' ), $type ), (string) $type );
						break;
					}
				}
			} elseif ( $types ) {
				$parts[] = 0.3;
			}
		}
		if ( ! $parts ) {
			return self::factor( null, 'preferences', array() );
		}
		return self::factor( array_sum( $parts ) / count( $parts ), 'preferences', $reasons );
	}

	/**
	 * Availability: preferred months, blackout dates, existing paid bookings.
	 */
	private static function factor_dates( array $vendor, array $opp ) {
		$date = (string) ( $opp['event_date'] ?? '' );
		if ( '' === $date ) {
			return self::factor( null, 'dates', array() );
		}
		$parts   = array();
		$reasons = array();
		$months  = array_map( 'intval', (array) ( $vendor['available_months'] ?? array() ) );
		if ( $months ) {
			$in      = in_array( (int) substr( $date, 5, 2 ), $months, true );
			$parts[] = $in ? 1.0 : 0.0;
			if ( $in ) {
				$reasons[] = self::reason( 'available_month', self::t( 'During a month you are available' ) );
			}
		}
		$blackouts = (array) ( $vendor['blackout_dates'] ?? array() );
		if ( $blackouts ) {
			$parts[] = in_array( $date, $blackouts, true ) ? 0.0 : 1.0;
		}
		$booked = (array) ( $vendor['booked_dates'] ?? array() );
		if ( $booked ) {
			$parts[] = in_array( $date, $booked, true ) ? 0.0 : 1.0;
		}
		if ( ! $parts ) {
			return self::factor( null, 'dates', array() );
		}
		return self::factor( array_sum( $parts ) / count( $parts ), 'dates', $reasons );
	}

	/**
	 * Relationship signals. Absent signals are dropped, never penalized.
	 */
	private static function factor_signals( array $vendor, array $opp ) {
		$organizer = (int) ( $opp['organizer_user_id'] ?? 0 );
		$opp_id    = (int) ( $opp['opportunity_id'] ?? 0 );
		if ( $organizer && in_array( $organizer, array_map( 'intval', (array) ( $vendor['past_organizers'] ?? array() ) ), true ) ) {
			return self::factor( 1.0, 'previous_organizer', array( self::reason( 'previous_organizer', self::t( "From an organizer you've worked with" ) ) ) );
		}
		if ( $organizer && in_array( $organizer, array_map( 'intval', (array) ( $vendor['favorite_organizers'] ?? array() ) ), true ) ) {
			return self::factor( 0.8, 'followed_organizer', array( self::reason( 'followed_organizer', self::t( 'From an organizer you follow' ) ) ) );
		}
		if ( $opp_id && in_array( $opp_id, array_map( 'intval', (array) ( $vendor['favorite_opportunities'] ?? array() ) ), true ) ) {
			return self::factor( 0.6, 'saved', array( self::reason( 'saved', self::t( 'You saved this opportunity' ) ) ) );
		}
		// Passport feedback is a small positive tie-breaker only: present when the public review minimum is met,
		// otherwise dropped (no reviews is never a penalty). Hard eligibility is evaluated separately.
		$feedback = $vendor['organizer_feedback'] ?? null;
		if ( null !== $feedback && (float) $feedback >= 4.0 ) {
			return self::factor( 0.5, 'organizer_feedback', array( self::reason( 'organizer_feedback', self::t( 'Consistently positive organizer reviews' ) ) ) );
		}
		return self::factor( null, 'none', array() );
	}

	/**
	 * Deterministic ranking: score, then soonest date, then trending (tiebreak only).
	 *
	 * @param array $a Row with score, event_date, trending_score.
	 * @param array $b Row.
	 */
	public static function compare( array $a, array $b ) {
		$by_score = (int) ( $b['score'] ?? 0 ) <=> (int) ( $a['score'] ?? 0 );
		if ( 0 !== $by_score ) {
			return $by_score;
		}
		$a_date  = (string) ( $a['event_date'] ?? '' );
		$b_date  = (string) ( $b['event_date'] ?? '' );
		$by_date = ( '' === $a_date ? '9999-12-31' : $a_date ) <=> ( '' === $b_date ? '9999-12-31' : $b_date );
		if ( 0 !== $by_date ) {
			return $by_date;
		}
		return (float) ( $b['trending_score'] ?? 0 ) <=> (float) ( $a['trending_score'] ?? 0 );
	}

	/**
	 * Vendor-facing band. Raw scores stay internal.
	 *
	 * @param int $score Score 0-100.
	 */
	public static function band( $score ) {
		$score = (int) $score;
		if ( $score >= 75 ) {
			return 'strong';
		}
		if ( $score >= 55 ) {
			return 'good';
		}
		return 'possible';
	}

	/**
	 * Haversine distance in miles.
	 */
	public static function distance_miles( $lat1, $lng1, $lat2, $lng2 ) {
		$earth = 3959;
		$d_lat = deg2rad( (float) $lat2 - (float) $lat1 );
		$d_lng = deg2rad( (float) $lng2 - (float) $lng1 );
		$a     = sin( $d_lat / 2 ) ** 2 + cos( deg2rad( (float) $lat1 ) ) * cos( deg2rad( (float) $lat2 ) ) * sin( $d_lng / 2 ) ** 2;
		return $earth * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	/**
	 * Normalize a city/type label for comparison.
	 *
	 * @param mixed $value Label.
	 */
	public static function norm( $value ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $value ) ) );
	}

	/**
	 * Validated coordinate or null (rejects 0,0 placeholders and out-of-range values).
	 */
	private static function coord( $value, $limit ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		$value = (float) $value;
		if ( abs( $value ) > $limit || 0.0 === $value ) {
			return null;
		}
		return $value;
	}

	private static function factor( $score, $kind, array $reasons ) {
		return array(
			'score'   => null === $score ? null : round( max( 0.0, min( 1.0, (float) $score ) ), 4 ),
			'kind'    => $kind,
			'reasons' => $reasons,
		);
	}

	private static function reason( $code, $text, $value = '' ) {
		return array(
			'code'  => $code,
			'text'  => $text,
			'value' => (string) $value,
		);
	}

	private static function t( $text ) {
		return function_exists( '__' ) ? __( $text, 'lnkup-marketplace' ) : $text; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}
}
