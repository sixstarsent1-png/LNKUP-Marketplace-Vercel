<?php
/**
 * Preview a matching rules change before saving it.
 *
 * Scores a deterministic sample of active vendors against their candidate opportunities with the
 * current and the candidate weights / minimum score, using the same pure engine as production.
 * Nothing is written. Time-boxed; a partial sample is reported as such.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Match_Simulator {

	const DEFAULT_SAMPLE   = 300;
	const OPPS_PER_VENDOR  = 200;
	const TIME_LIMIT       = 10.0;
	const EXAMPLES         = 10;

	/**
	 * @param array $candidate Rule keys (weights, match_min_score) to try; missing keys keep current values.
	 * @param int   $sample    Max vendors to score.
	 * @return array<string,mixed>
	 */
	public static function preview( array $candidate, $sample = self::DEFAULT_SAMPLE ) {
		if ( ! class_exists( 'LNKup_Match_Tables' ) || ! LNKup_Match_Tables::exists( LNKup_Match_Tables::vendor_index_table() ) ) {
			return array( 'error' => __( 'The matching index is not built yet. Run a rebuild first.', 'lnkup-marketplace' ) );
		}
		$started = microtime( true );
		$current = LNKup_Match_Settings::current_rules();
		$after   = self::candidate_rules( $current, $candidate );
		$w_now   = self::weights_from( $current );
		$w_new   = self::weights_from( $after );
		$today   = current_time( 'Y-m-d' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Match_Tables::vendor_index_table() . " WHERE status = 'active' ORDER BY profile_id ASC LIMIT %d", max( 1, min( 2000, (int) $sample ) ) ), ARRAY_A );

		$stats   = array( 'before' => self::empty_side(), 'after' => self::empty_side() );
		$vendors = array( 'before' => array(), 'after' => array() );
		$factors = array();
		$gained  = array();
		$lost    = array();
		$pairs   = 0;
		$scored  = 0;
		$partial = false;
		$opps    = array();

		foreach ( array_chunk( $rows, 50 ) as $chunk ) {
			foreach ( LNKup_Match_Service::vendor_inputs( $chunk ) as $vendor ) {
				if ( microtime( true ) - $started > self::TIME_LIMIT ) {
					$partial = true;
					break 2;
				}
				++$scored;
				$opp_rows = LNKup_Match_Service::candidate_opportunity_rows( $vendor, self::OPPS_PER_VENDOR );
				$missing  = array_filter(
					$opp_rows,
					static function ( $row ) use ( $opps ) {
						return ! isset( $opps[ (int) $row['opportunity_id'] ] );
					}
				);
				if ( $missing ) {
					$opps += LNKup_Match_Service::opportunity_inputs( array_values( $missing ) );
				}
				foreach ( $opp_rows as $row ) {
					$opp = $opps[ (int) $row['opportunity_id'] ] ?? null;
					if ( ! $opp ) {
						continue;
					}
					++$pairs;
					$now = LNKup_Match_Engine::evaluate( $vendor, $opp, $w_now, array( 'today' => $today, 'side' => 'index', 'min_score' => $current['match_min_score'] ) );
					$new = LNKup_Match_Engine::evaluate( $vendor, $opp, $w_new, array( 'today' => $today, 'side' => 'index', 'min_score' => $after['match_min_score'] ) );
					self::tally( $stats['before'], $vendors['before'], $now, (int) $vendor['profile_id'] );
					self::tally( $stats['after'], $vendors['after'], $new, (int) $vendor['profile_id'] );
					if ( $new['recommended'] ) {
						self::contribution( $factors, $new, $w_new );
					}
					if ( $new['recommended'] && ! $now['recommended'] && count( $gained ) < self::EXAMPLES ) {
						$gained[] = self::example( $vendor, $opp, $now, $new, implode( ', ', $now['suppressed'] ) );
					} elseif ( $now['recommended'] && ! $new['recommended'] && count( $lost ) < self::EXAMPLES ) {
						$lost[] = self::example( $vendor, $opp, $now, $new, implode( ', ', $new['suppressed'] ) );
					}
				}
			}
		}

		foreach ( array( 'before', 'after' ) as $side ) {
			$stats[ $side ]['vendors_with_matches'] = count( $vendors[ $side ] );
			$stats[ $side ]['avg_score']            = $stats[ $side ]['recommended'] ? round( $stats[ $side ]['score_sum'] / $stats[ $side ]['recommended'], 1 ) : 0;
			unset( $stats[ $side ]['score_sum'] );
		}
		$contribution = array();
		foreach ( $factors as $factor => $agg ) {
			$contribution[ $factor ] = $agg['n'] ? round( $agg['points'] / $agg['n'], 1 ) : 0;
		}
		arsort( $contribution );

		return array(
			'current'             => $current,
			'candidate'           => $after,
			'vendors'             => $scored,
			'pairs'               => $pairs,
			'before'              => $stats['before'],
			'after'               => $stats['after'],
			'factor_contribution' => $contribution,
			'gained'              => $gained,
			'lost'                => $lost,
			'partial'             => $partial,
			'seconds'             => round( microtime( true ) - $started, 2 ),
		);
	}

	/**
	 * Candidate values clamped like save() would.
	 */
	private static function candidate_rules( array $current, array $candidate ) {
		$out = $current;
		foreach ( LNKup_Match_Settings::rule_keys() as $key ) {
			if ( isset( $candidate[ $key ] ) && '' !== (string) $candidate[ $key ] ) {
				$out[ $key ] = max( 0, min( 100, (int) $candidate[ $key ] ) );
			}
		}
		return $out;
	}

	/**
	 * Factor-keyed weights from rule values (all-zero falls back to defaults, as in production).
	 */
	private static function weights_from( array $rules ) {
		$weights = array();
		foreach ( LNKup_Match_Settings::WEIGHT_KEYS as $factor => $key ) {
			$weights[ $factor ] = (int) ( $rules[ $key ] ?? LNKup_Match_Settings::DEFAULT_WEIGHTS[ $factor ] );
		}
		return array_sum( $weights ) > 0 ? $weights : LNKup_Match_Settings::DEFAULT_WEIGHTS;
	}

	private static function empty_side() {
		return array( 'recommended' => 0, 'bands' => array( 'strong' => 0, 'good' => 0, 'possible' => 0 ), 'score_sum' => 0 );
	}

	private static function tally( array &$side, array &$vendors, array $result, $profile_id ) {
		if ( ! $result['recommended'] ) {
			return;
		}
		++$side['recommended'];
		++$side['bands'][ LNKup_Match_Engine::band( (int) $result['score'] ) ];
		$side['score_sum']      += (int) $result['score'];
		$vendors[ $profile_id ] = true;
	}

	/**
	 * Points each factor added to the final score (weight share of factors with data).
	 */
	private static function contribution( array &$factors, array $result, array $weights ) {
		$total = 0;
		foreach ( (array) $result['factors'] as $factor => $row ) {
			if ( null !== ( $row['score'] ?? null ) ) {
				$total += (int) ( $weights[ $factor ] ?? 0 );
			}
		}
		if ( $total <= 0 ) {
			return;
		}
		foreach ( (array) $result['factors'] as $factor => $row ) {
			if ( null === ( $row['score'] ?? null ) ) {
				continue;
			}
			$factors[ $factor ]['points'] = ( $factors[ $factor ]['points'] ?? 0 ) + 100 * (float) $row['score'] * (int) ( $weights[ $factor ] ?? 0 ) / $total;
			$factors[ $factor ]['n']      = ( $factors[ $factor ]['n'] ?? 0 ) + 1;
		}
	}

	private static function example( array $vendor, array $opp, array $now, array $new, $reason ) {
		$name = (string) get_post_meta( (int) $vendor['profile_id'], '_lnkup_business_name', true );
		return array(
			'profile_id'     => (int) $vendor['profile_id'],
			'opportunity_id' => (int) $opp['opportunity_id'],
			'vendor'         => '' !== $name ? $name : sprintf( 'Vendor #%d', (int) $vendor['profile_id'] ),
			'opportunity'    => lnkup_plain_title( (int) $opp['opportunity_id'] ) ?: sprintf( 'Opportunity #%d', (int) $opp['opportunity_id'] ),
			'score_before'   => (int) $now['score'],
			'score_after'    => (int) $new['score'],
			'reason'         => (string) $reason,
		);
	}
}
