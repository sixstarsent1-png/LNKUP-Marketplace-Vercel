<?php
/**
 * Vendor reliability intelligence for organizers.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Reliability_Intel {

	/**
	 * Reliability summary for vendor profile.
	 *
	 * @param int $profile_id Vendor profile ID.
	 * @param int $organizer_id Organizer user ID.
	 */
	public static function for_profile( $profile_id, $organizer_id = 0 ) {
		$profile_id = absint( $profile_id );
		$profile = LNKup_Vendor_CRM_Profile::get( $profile_id, $organizer_id ?: get_current_user_id() );
		if ( ! $profile ) {
			return null;
		}
		$metrics = $profile['reliability'] ?? array();
		$score = (int) ( $profile['vendor_score'] ?? 0 );
		$flags = array();
		if ( ! empty( $metrics['no_show_rate'] ) && $metrics['no_show_rate'] > 0.15 ) {
			$flags[] = array(
				'type'    => 'risk',
				'label'   => __( 'Likely no-show risk', 'lnkup-marketplace' ),
				'message' => __( 'This vendor has an elevated no-show rate.', 'lnkup-marketplace' ),
			);
		}
		if ( $score >= 80 ) {
			$flags[] = array(
				'type'    => 'positive',
				'label'   => __( 'Highly reliable', 'lnkup-marketplace' ),
				'message' => __( 'Strong track record across events.', 'lnkup-marketplace' ),
			);
		}
		if ( ! empty( $metrics['payment_completion_rate'] ) && $metrics['payment_completion_rate'] >= 0.9 ) {
			$flags[] = array(
				'type'    => 'positive',
				'label'   => __( 'Fast payer', 'lnkup-marketplace' ),
				'message' => __( 'Usually completes payment quickly after approval.', 'lnkup-marketplace' ),
			);
		}
		$missing_docs = self::has_missing_docs( $profile_id );
		if ( $missing_docs ) {
			$flags[] = array(
				'type'    => 'warning',
				'label'   => __( 'Missing documents', 'lnkup-marketplace' ),
				'message' => __( 'Required documents are incomplete or unverified.', 'lnkup-marketplace' ),
			);
		}
		return array(
			'profile_id'           => $profile_id,
			'business_name'        => $profile['business_name'] ?? '',
			'score'                => $score,
			'avg_rating'           => $profile['avg_rating'] ?? 0,
			'reliability'          => $metrics,
			'flags'                => $flags,
			'payment_likelihood'   => self::payment_likelihood( $metrics ),
			'no_show_likelihood'   => self::no_show_likelihood( $metrics ),
			'rebook_count'         => (int) ( $metrics['rebook_count'] ?? 0 ),
		);
	}

	/**
	 * Top reliable vendors for organizer.
	 *
	 * @param int $organizer_id Organizer ID.
	 * @param int $limit        Limit.
	 */
	public static function top_vendors( $organizer_id, $limit = 5 ) {
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => absint( $organizer_id ),
			)
		);
		$seen  = array();
		$items = array();
		foreach ( $events as $event_id ) {
			$apps = get_posts(
				array(
					'post_type'      => 'lnkup_application',
					'posts_per_page' => 20,
					'fields'         => 'ids',
					'meta_key'       => '_lnkup_event_id',
					'meta_value'     => $event_id,
				)
			);
			foreach ( $apps as $app_id ) {
				$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
				if ( ! $profile_id || isset( $seen[ $profile_id ] ) ) {
					continue;
				}
				$seen[ $profile_id ] = true;
				$intel = self::for_profile( $profile_id, $organizer_id );
				if ( $intel ) {
					$items[] = $intel;
				}
			}
		}
		usort(
			$items,
			function ( $a, $b ) {
				return ( $b['score'] ?? 0 ) <=> ( $a['score'] ?? 0 );
			}
		);
		return array_slice( $items, 0, $limit );
	}

	/**
	 * At-risk vendors for organizer.
	 *
	 * @param int $organizer_id Organizer ID.
	 * @param int $limit        Limit.
	 */
	public static function at_risk_vendors( $organizer_id, $limit = 5 ) {
		$all = self::top_vendors( $organizer_id, 50 );
		$at_risk = array_filter(
			$all,
			function ( $item ) {
				foreach ( $item['flags'] as $flag ) {
					if ( 'risk' === $flag['type'] || 'warning' === $flag['type'] ) {
						return true;
					}
				}
				return ( $item['score'] ?? 100 ) < 55;
			}
		);
		return array_slice( array_values( $at_risk ), 0, $limit );
	}

	private static function payment_likelihood( $metrics ) {
		$rate = (float) ( $metrics['payment_completion_rate'] ?? 0.7 );
		if ( $rate >= 0.85 ) {
			return 'high';
		}
		if ( $rate >= 0.6 ) {
			return 'medium';
		}
		return 'low';
	}

	private static function no_show_likelihood( $metrics ) {
		$rate = (float) ( $metrics['no_show_rate'] ?? 0 );
		if ( $rate >= 0.2 ) {
			return 'high';
		}
		if ( $rate >= 0.08 ) {
			return 'medium';
		}
		return 'low';
	}

	private static function has_missing_docs( $profile_id ) {
		$vendor_id = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		if ( ! $vendor_id ) {
			return false;
		}
		$docs = LNKup_Documents::get_for_vendor( $vendor_id );
		foreach ( $docs as $doc ) {
			if ( 'approved' !== get_post_meta( $doc->ID, '_lnkup_verification_status', true ) ) {
				return true;
			}
		}
		return false;
	}
}
