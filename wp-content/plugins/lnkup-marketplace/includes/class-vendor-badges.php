<?php
/**
 * Vendor verification and achievement badges.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Badges {

	/**
	 * Badge catalog.
	 */
	public static function catalog() {
		return array(
			'verified_vendor'      => array( 'label' => __( 'Verified Vendor', 'lnkup-marketplace' ), 'auto' => true ),
			'profile_complete'     => array( 'label' => __( 'Profile Complete', 'lnkup-marketplace' ), 'auto' => true ),
			'documents_ready'      => array( 'label' => __( 'Documents Ready', 'lnkup-marketplace' ), 'auto' => true ),
			'reliable_vendor'      => array( 'label' => __( 'Reliable Vendor', 'lnkup-marketplace' ), 'auto' => true ),
			'insurance_verified'     => array( 'label' => __( 'Insurance Verified', 'lnkup-marketplace' ), 'auto' => true, 'doc' => 'certificate_of_insurance' ),
			'license_verified'       => array( 'label' => __( 'License Verified', 'lnkup-marketplace' ), 'auto' => true, 'doc' => 'business_license' ),
			'health_permit_verified' => array( 'label' => __( 'Health Permit Verified', 'lnkup-marketplace' ), 'auto' => true, 'doc' => 'health_permit' ),
			'food_handler_verified'  => array( 'label' => __( 'Food Handler Verified', 'lnkup-marketplace' ), 'auto' => true, 'doc' => 'food_handler_permit' ),
			'temp_permit_verified'   => array( 'label' => __( 'Event Permit Verified', 'lnkup-marketplace' ), 'auto' => true, 'doc' => 'temporary_event_permit' ),
			'w9_verified'            => array( 'label' => __( 'W9 Verified', 'lnkup-marketplace' ), 'auto' => true, 'doc' => 'w9' ),
			'top_rated'              => array( 'label' => __( 'Top Rated Vendor', 'lnkup-marketplace' ), 'auto' => true ),
			'fast_responder'         => array( 'label' => __( 'Fast Responder', 'lnkup-marketplace' ), 'auto' => true ),
			'completed_5'            => array( 'label' => __( 'Completed 5+ Events', 'lnkup-marketplace' ), 'auto' => true ),
			'completed_10'           => array( 'label' => __( 'Completed 10+ Events', 'lnkup-marketplace' ), 'auto' => true ),
			'completed_25'           => array( 'label' => __( 'Completed 25+ Events', 'lnkup-marketplace' ), 'auto' => true ),
			'completed_50'           => array( 'label' => __( 'Completed 50+ Events', 'lnkup-marketplace' ), 'auto' => true ),
			'no_no_shows'            => array( 'label' => __( 'No No-Shows', 'lnkup-marketplace' ), 'auto' => true ),
			'preferred_vendor'       => array( 'label' => __( 'Preferred Vendor', 'lnkup-marketplace' ), 'auto' => true ),
			'lnkup_approved'         => array( 'label' => __( 'LNKup Approved', 'lnkup-marketplace' ), 'auto' => false ),
			'rising_vendor'          => array( 'label' => __( 'Rising Vendor', 'lnkup-marketplace' ), 'auto' => true ),
		);
	}

	/**
	 * Get active badges for profile.
	 */
	public static function get_active( $profile_id ) {
		global $wpdb;
		$table = LNKup_Reputation_Tables::badge_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE vendor_profile_id = %d AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > %s)",
				absint( $profile_id ),
				current_time( 'mysql' )
			),
			ARRAY_A
		);
		$catalog = self::catalog();
		$items   = array();
		foreach ( (array) $rows as $row ) {
			$slug = $row['badge_slug'];
			$items[] = array(
				'slug'      => $slug,
				'label'     => $catalog[ $slug ]['label'] ?? $slug,
				'earned_at' => $row['earned_at'],
				'source'    => $row['source'],
			);
		}
		return $items;
	}

	public static function get_active_slugs( $profile_id ) {
		return wp_list_pluck( self::get_active( $profile_id ), 'slug' );
	}

	/**
	 * Award badge.
	 */
	public static function award( $profile_id, $slug, $source = 'auto', $expires_at = null ) {
		global $wpdb;
		$slug = sanitize_key( $slug );
		if ( ! isset( self::catalog()[ $slug ] ) ) {
			return false;
		}
		$table = LNKup_Reputation_Tables::badge_table();
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE vendor_profile_id = %d AND badge_slug = %s AND revoked_at IS NULL",
				absint( $profile_id ),
				$slug
			)
		);
		if ( $exists ) {
			return (int) $exists;
		}
		$wpdb->insert(
			$table,
			array(
				'vendor_profile_id' => absint( $profile_id ),
				'badge_slug'        => $slug,
				'source'            => sanitize_key( $source ),
				'earned_at'         => current_time( 'mysql' ),
				'expires_at'        => $expires_at,
				'revoked_at'        => null,
				'revoked_by'        => 0,
				'meta'              => null,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		LNKup_Vendor_Reputation::log_activity(
			'badge_awarded',
			$profile_id,
			array( 'payload' => array( 'badge' => $slug, 'source' => $source ) )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Revoke badge.
	 */
	public static function revoke( $profile_id, $slug, $user_id = 0 ) {
		global $wpdb;
		$table = LNKup_Reputation_Tables::badge_table();
		$wpdb->update(
			$table,
			array(
				'revoked_at' => current_time( 'mysql' ),
				'revoked_by' => $user_id ?: get_current_user_id(),
			),
			array(
				'vendor_profile_id' => absint( $profile_id ),
				'badge_slug'        => sanitize_key( $slug ),
				'revoked_at'        => null,
			),
			array( '%s', '%d' ),
			array( '%d', '%s', '%s' )
		);
		LNKup_Vendor_Reputation::log_activity(
			'badge_revoked',
			$profile_id,
			array( 'payload' => array( 'badge' => $slug ) )
		);
	}

	/**
	 * Evaluate auto badges after score refresh.
	 */
	public static function evaluate_auto( $profile_id ) {
		$vendor_id = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		$metrics   = LNKup_Reliability_Metrics::get( $profile_id );
		$summary   = LNKup_Vendor_Reviews::summary_for_vendor( $profile_id );
		$verified  = 'approved' === get_post_meta( $profile_id, '_lnkup_verification_status', true );

		if ( $verified ) {
			self::award( $profile_id, 'verified_vendor' );
		} else {
			self::revoke( $profile_id, 'verified_vendor' );
		}

		self::sync_doc_badges( $profile_id, $vendor_id );

		if ( $metrics['completed_events'] >= 5 ) {
			self::award( $profile_id, 'completed_5' );
		}
		if ( $metrics['completed_events'] >= 10 ) {
			self::award( $profile_id, 'completed_10' );
		}
		if ( $metrics['completed_events'] >= 25 ) {
			self::award( $profile_id, 'completed_25' );
		}
		if ( $metrics['completed_events'] >= 50 ) {
			self::award( $profile_id, 'completed_50' );
		}

		if ( class_exists( 'LNKup_Growth_Profile_Strength' ) ) {
			$strength = LNKup_Growth_Profile_Strength::calculate( $profile_id );
			if ( (int) ( $strength['score'] ?? 0 ) >= 70 ) {
				self::award( $profile_id, 'profile_complete' );
			} else {
				self::revoke( $profile_id, 'profile_complete' );
			}
		}

		if ( class_exists( 'LNKup_Verification' ) ) {
			$doc_items = LNKup_Verification::get_required_verification_items_for_vendor( $profile_id, array() );
			$all_ready = true;
			foreach ( (array) ( $doc_items['required'] ?? array() ) as $doc ) {
				if ( ! in_array( $doc['status'] ?? '', array( 'approved', 'pending', 'expiring' ), true ) ) {
					$all_ready = false;
					break;
				}
			}
			if ( $all_ready ) {
				self::award( $profile_id, 'documents_ready' );
			} else {
				self::revoke( $profile_id, 'documents_ready' );
			}
		}

		if ( $metrics['completed_events'] >= 3 && (float) ( $metrics['avg_organizer_rating'] ?? 0 ) >= 4.0 && 0 === (int) ( $metrics['no_show_count'] ?? 0 ) ) {
			self::award( $profile_id, 'reliable_vendor' );
		} else {
			self::revoke( $profile_id, 'reliable_vendor' );
		}

		if ( $metrics['response_rate'] >= 90 ) {
			self::award( $profile_id, 'fast_responder' );
		}

		if ( $metrics['completed_events'] >= 5 && 0 === $metrics['no_show_count'] ) {
			self::award( $profile_id, 'no_no_shows' );
		} elseif ( $metrics['no_show_count'] > 0 ) {
			self::revoke( $profile_id, 'no_no_shows' );
		}

		if ( $summary['avg'] >= 4.5 && $summary['count'] >= 5 ) {
			self::award( $profile_id, 'top_rated' );
		} else {
			self::revoke( $profile_id, 'top_rated' );
		}

		if ( $metrics['completed_events'] < 5 && $summary['count'] >= 3 && $summary['avg'] >= 4.0 ) {
			self::award( $profile_id, 'rising_vendor' );
		}

		global $wpdb;
		$rel_table = LNKup_Organizer_CRM_Tables::rel_table();
		$pref_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$rel_table} WHERE vendor_profile_id = %d AND is_preferred = 1",
				$profile_id
			)
		);
		if ( $pref_count >= 2 ) {
			self::award( $profile_id, 'preferred_vendor' );
		}
	}

	/**
	 * Sync document-linked badges.
	 */
	private static function sync_doc_badges( $profile_id, $vendor_id ) {
		$catalog = self::catalog();
		$docs    = LNKup_Documents::get_for_vendor( $vendor_id );
		$by_type = array();
		foreach ( $docs as $doc ) {
			$type = get_post_meta( $doc->ID, '_lnkup_document_type', true );
			$by_type[ $type ] = $doc;
		}
		foreach ( $catalog as $slug => $def ) {
			if ( empty( $def['doc'] ) ) {
				continue;
			}
			$doc_type = $def['doc'];
			if ( isset( $by_type[ $doc_type ] ) && 'approved' === get_post_meta( $by_type[ $doc_type ]->ID, '_lnkup_verification_status', true ) ) {
				$exp = get_post_meta( $by_type[ $doc_type ]->ID, '_lnkup_expiration_date', true );
				self::award( $profile_id, $slug, 'auto', $exp ?: null );
			} else {
				self::revoke( $profile_id, $slug );
			}
		}
	}

	/**
	 * Available badges not yet earned.
	 */
	public static function get_available( $profile_id ) {
		$active = self::get_active_slugs( $profile_id );
		$out    = array();
		foreach ( self::catalog() as $slug => $def ) {
			if ( ! in_array( $slug, $active, true ) ) {
				$out[] = array( 'slug' => $slug, 'label' => $def['label'] );
			}
		}
		return $out;
	}
}
