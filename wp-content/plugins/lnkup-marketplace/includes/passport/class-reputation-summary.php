<?php
/**
 * Transparent reputation indicators for public and organizer views.
 *
 * The internal 0–100 score is never returned here. Each indicator is explained separately:
 * business verification ≠ attendance ≠ satisfaction ≠ document compliance.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Reputation_Summary {

	/**
	 * @param int    $profile_id     Vendor profile ID.
	 * @param string $context        public|organizer|owner.
	 * @param int    $opportunity_id Optional opportunity for document readiness.
	 * @return array<string,mixed>
	 */
	public static function for_profile( int $profile_id, string $context = 'public', int $opportunity_id = 0 ): array {
		$stats    = LNKup_Participation_History::stats( $profile_id );
		$feedback = self::organizer_feedback( $profile_id );
		$is_new   = 0 === $stats['confirmed_events'] && 0 === $feedback['count'];

		$summary = array(
			'verification'       => self::verification( $profile_id ),
			'experience'         => self::experience( $stats ),
			'organizer_feedback' => $feedback,
			'document_readiness' => self::document_readiness( $profile_id, $context, $opportunity_id ),
			'achievements'       => LNKup_Passport_Settings::enabled( 'passport_history_v2' ) && class_exists( 'LNKup_Vendor_Badges' ) ? LNKup_Vendor_Badges::get_achievements( $profile_id ) : array(),
			'is_new_vendor'      => $is_new,
			'new_vendor_note'    => $is_new ? __( 'New to LNKup — no reviews or completed events yet. That is not a negative signal.', 'lnkup-marketplace' ) : '',
			'explanations'       => self::explanations(),
		);
		return (array) apply_filters( 'lnkup_reputation_summary', $summary, $profile_id, $context );
	}

	/**
	 * Business identity verification from the current record. Expired/revoked never read as current.
	 *
	 * @param int $profile_id Profile ID.
	 * @return array<string,mixed>
	 */
	public static function verification( int $profile_id ): array {
		$current = LNKup_Verification_Records::current( $profile_id, 'business_identity' );
		$is_verified = 'verified' === $current['status'];
		return array(
			'status'      => $current['status'],
			'is_verified' => $is_verified,
			'label'       => $is_verified ? __( 'Business Identity Verified', 'lnkup-marketplace' ) : $current['label'],
			'explanation' => $is_verified
				? __( 'LNKup completed its business identity review. This does not certify product quality, permits, insurance, or future performance.', 'lnkup-marketplace' )
				: ( 'expired' === $current['status'] ? __( 'A previous verification has expired and is awaiting renewal.', 'lnkup-marketplace' ) : '' ),
			'verified_at' => $is_verified ? $current['verified_at'] : null,
			'expires_at'  => $is_verified ? $current['expires_at'] : null,
		);
	}

	/**
	 * @param array<string,int> $stats Participation stats.
	 * @return array<string,mixed>
	 */
	public static function experience( array $stats ): array {
		$n = (int) $stats['confirmed_events'];
		return array(
			'confirmed_events'    => $n,
			'distinct_cities'     => (int) $stats['distinct_cities'],
			'distinct_organizers' => (int) $stats['distinct_organizers'],
			'label'               => $n
				/* translators: %d: number of events */
				? sprintf( _n( '%d confirmed event', '%d confirmed events', $n, 'lnkup-marketplace' ), $n )
				: __( 'No confirmed events yet', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Organizer feedback: each organizer's reviews are averaged first, then averaged across organizers,
	 * so one organizer cannot dominate. No average is shown below the configured minimums.
	 *
	 * @param int $profile_id Profile ID.
	 * @return array<string,mixed>
	 */
	public static function organizer_feedback( int $profile_id ): array {
		$by_org   = LNKup_Vendor_Reviews::ratings_by_organizer( $profile_id );
		$count    = 0;
		$org_avgs = array();
		foreach ( $by_org as $ratings ) {
			$ratings = array_filter( array_map( 'intval', (array) $ratings ) );
			if ( ! $ratings ) {
				continue;
			}
			$count     += count( $ratings );
			$org_avgs[] = array_sum( $ratings ) / count( $ratings );
		}
		$min_count = LNKup_Passport_Settings::int( 'passport_review_min_count' );
		$min_orgs  = LNKup_Passport_Settings::int( 'passport_review_min_organizers' );
		$display   = $count >= $min_count && count( $org_avgs ) >= $min_orgs;
		$average   = $org_avgs ? round( array_sum( $org_avgs ) / count( $org_avgs ), 1 ) : 0.0;

		$label = __( 'No reviews yet', 'lnkup-marketplace' );
		if ( $count > 0 && ! $display ) {
			$label = __( 'Not enough reviews yet', 'lnkup-marketplace' );
		} elseif ( $display ) {
			/* translators: 1: average rating, 2: review count */
			$label = sprintf( _n( '%1$s from %2$d review', '%1$s from %2$d reviews', $count, 'lnkup-marketplace' ), number_format_i18n( $average, 1 ), $count );
		}

		$categories = array();
		if ( $display ) {
			$summary    = LNKup_Vendor_Reviews::summary_for_vendor( $profile_id );
			$categories = array_intersect_key( (array) ( $summary['categories'] ?? array() ), LNKup_Vendor_Reviews::public_categories() );
		}

		return array(
			'count'           => $count,
			'organizers'      => count( $org_avgs ),
			'display_average' => $display,
			'average'         => $display ? $average : null,
			'categories'      => $categories,
			'label'           => $label,
			'minimum'         => array( 'reviews' => $min_count, 'organizers' => $min_orgs ),
			'method'          => __( 'Average of each organizer’s average rating, rounded to one decimal. Only published reviews from verified bookings count.', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Document readiness. Public: which document types are reviewed and current (no files, no dates).
	 * Organizer with an opportunity: per-requirement reuse status.
	 *
	 * @param int    $profile_id     Profile ID.
	 * @param string $context        Context.
	 * @param int    $opportunity_id Opportunity ID.
	 * @return array<string,mixed>
	 */
	public static function document_readiness( int $profile_id, string $context, int $opportunity_id = 0 ): array {
		$vendor_id = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		$types     = LNKup_Documents::document_types();
		$current   = array();
		foreach ( LNKup_Documents::current_by_type( $vendor_id ) as $type => $doc ) {
			$status = LNKup_Documents::effective_status( (int) $doc->ID );
			if ( in_array( $status, array( 'approved', 'expiring' ), true ) && 'w9' !== $type ) {
				$current[] = array( 'type' => $type, 'label' => $types[ $type ] ?? $type );
			}
		}
		$out = array(
			'current_types' => $current,
			'label'         => $current
				/* translators: %d: number of document types */
				? sprintf( _n( '%d reviewed document on file', '%d reviewed documents on file', count( $current ), 'lnkup-marketplace' ), count( $current ) )
				: __( 'No reviewed documents on file', 'lnkup-marketplace' ),
			'note'          => __( 'Reviewed documents may still need to meet each event’s specific requirements.', 'lnkup-marketplace' ),
		);
		if ( $opportunity_id && 'public' !== $context && class_exists( 'LNKup_Document_Vault' ) ) {
			$out['for_opportunity'] = LNKup_Document_Vault::find_reusable( $profile_id, $opportunity_id );
		}
		return $out;
	}

	/**
	 * Plain-language definitions shown next to indicators.
	 *
	 * @return array<string,string>
	 */
	public static function explanations(): array {
		return array(
			'verification'       => __( 'Business verification confirms identity details LNKup reviewed. It is not event attendance or a safety certification.', 'lnkup-marketplace' ),
			'experience'         => __( 'Counts events with a recorded check-in or a paid booking for an event that has ended with no no-show. Approvals and payments alone are not attendance.', 'lnkup-marketplace' ),
			'organizer_feedback' => __( 'Reviews come only from organizers who booked this vendor. One organizer cannot outweigh others.', 'lnkup-marketplace' ),
			'document_readiness' => __( 'Reviewed documents on file. Each event may have its own requirements.', 'lnkup-marketplace' ),
		);
	}
}
