<?php
/**
 * Trust and verification orchestrator for vendors and organizers.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Central verification service — derives levels from real platform data.
 */
class LNKup_Verification {

	const PROFILE_COMPLETE_THRESHOLD = 70;

	/**
	 * Init hooks.
	 */
	public static function init(): void {
		add_action( 'updated_post_meta', array( __CLASS__, 'on_profile_verification_meta' ), 10, 4 );
		add_action( 'lnkup_application_status_changed', array( __CLASS__, 'on_application_status_changed' ), 10, 2 );
	}

	/**
	 * Refresh vendor verification side effects (badges, reputation).
	 *
	 * @param int $profile_id Vendor profile post ID.
	 */
	public static function refresh_vendor( int $profile_id ): void {
		$profile_id = absint( $profile_id );
		if ( ! $profile_id ) {
			return;
		}
		if ( class_exists( 'LNKup_Vendor_Reputation' ) ) {
			LNKup_Vendor_Reputation::refresh( $profile_id );
		} elseif ( class_exists( 'LNKup_Vendor_Badges' ) ) {
			LNKup_Vendor_Badges::evaluate_auto( $profile_id );
		}
	}

	/**
	 * @param int                  $meta_id    Meta ID.
	 * @param int                  $post_id    Post ID.
	 * @param string               $meta_key   Meta key.
	 * @param string|array|mixed   $meta_value Meta value.
	 */
	public static function on_profile_verification_meta( $meta_id, $post_id, $meta_key, $meta_value ): void {
		unset( $meta_id, $meta_value );
		if ( '_lnkup_verification_status' !== $meta_key ) {
			return;
		}
		$post = get_post( (int) $post_id );
		if ( ! $post || 'lnkup_vendor_profile' !== $post->post_type ) {
			return;
		}
		self::refresh_vendor( (int) $post_id );
	}

	/**
	 * @param int    $application_id Application ID.
	 * @param string $status         New status.
	 */
	public static function on_application_status_changed( $application_id, $status ): void {
		unset( $status );
		$profile_id = (int) get_post_meta( (int) $application_id, '_lnkup_vendor_profile_id', true );
		if ( $profile_id ) {
			self::refresh_vendor( $profile_id );
		}
	}

	/**
	 * Vendor verification status payload.
	 *
	 * @param int                  $vendor_profile_id Profile post ID.
	 * @param array<string, mixed> $context           opportunity_id, application_id, public.
	 * @return array<string, mixed>
	 */
	public static function get_vendor_verification_status( int $vendor_profile_id, array $context = array() ): array {
		$vendor_profile_id = absint( $vendor_profile_id );
		$is_public         = ! empty( $context['public'] );
		$profile           = get_post( $vendor_profile_id );
		$vendor_id         = $profile ? (int) get_post_meta( $vendor_profile_id, '_lnkup_owner_user_id', true ) : 0;

		$strength     = class_exists( 'LNKup_Growth_Profile_Strength' )
			? LNKup_Growth_Profile_Strength::calculate( $vendor_profile_id )
			: array( 'score' => 0, 'missing_items' => array(), 'recommended_actions' => array() );
		$metrics      = class_exists( 'LNKup_Reliability_Metrics' ) ? LNKup_Reliability_Metrics::get( $vendor_profile_id ) : array();
		$review_sum   = class_exists( 'LNKup_Vendor_Reviews' ) ? LNKup_Vendor_Reviews::summary_for_vendor( $vendor_profile_id ) : array( 'avg' => 0, 'count' => 0 );
		$manual       = $profile ? (string) get_post_meta( $vendor_profile_id, '_lnkup_verification_status', true ) : '';
		$doc_items    = self::get_required_verification_items_for_vendor( $vendor_profile_id, $context );
		$required     = $doc_items['required'] ?? array();
		$suggested    = $doc_items['suggested'] ?? array();
		$not_applicable = $doc_items['not_applicable'] ?? array();

		$profile_complete = self::is_profile_complete( $vendor_profile_id, $strength );
		$documents_ready  = self::is_documents_ready( $required );
		$admin_approved   = 'approved' === $manual;
		$has_flags        = self::has_high_severity_flags( $vendor_profile_id );
		$lnkup_verified   = $profile_complete && $documents_ready && $admin_approved && ! $has_flags;
		$top_rated        = self::is_top_rated_vendor( $vendor_profile_id, $lnkup_verified, $profile_complete, $documents_ready, $metrics, $review_sum );

		$level = 'basic_vendor';
		if ( $top_rated ) {
			$level = 'top_rated_vendor';
		} elseif ( $lnkup_verified ) {
			$level = 'lnkup_verified';
		} elseif ( $documents_ready && $profile_complete ) {
			$level = 'documents_ready';
		} elseif ( $profile_complete ) {
			$level = 'profile_complete';
		} elseif ( $profile && $vendor_id ) {
			$level = 'basic_vendor';
		}

		$labels = self::vendor_level_labels();
		$completed_items   = self::vendor_completed_items( $vendor_profile_id, $strength, $required, $admin_approved, $metrics );
		$missing_items     = self::vendor_missing_items( $vendor_profile_id, $strength, $required, $admin_approved, $profile_complete, $documents_ready );
		$recommended_items = self::vendor_recommended_items( $strength, $suggested, $required );
		$next_action       = ! empty( $missing_items[0] ) ? $missing_items[0] : ( ! empty( $recommended_items[0] ) ? $recommended_items[0] : '' );
		$percent           = self::compute_vendor_percent( $strength, $required, $admin_approved, $metrics, $level );

		$badges = self::build_vendor_badge_list( $vendor_profile_id, $level, $context, $is_public, 'profile' );

		$response = array(
			'level'                   => $level,
			'label'                   => $labels[ $level ] ?? $level,
			'percent_complete'        => $percent,
			'badges'                  => wp_list_pluck( $badges, 'label' ),
			'badge_details'           => $badges,
			'completed_items'         => $completed_items,
			'missing_items'           => $missing_items,
			'recommended_items'       => $recommended_items,
			'required_documents'      => $required,
			'suggested_documents'     => $suggested,
			'not_applicable_documents'=> $not_applicable,
			'next_action'             => $next_action,
			'public_summary'          => self::vendor_public_summary( $level, $percent, $required ),
			'profile_id'              => $vendor_profile_id,
		);

		if ( ! $is_public && LNKup_Capabilities::user_has_manage_all() ) {
			$response['internal_notes'] = $profile
				? (string) get_post_meta( $vendor_profile_id, '_lnkup_verification_admin_notes', true )
				: '';
			$response['manual_status']  = $manual ?: 'pending';
			$response['badge_reasons']  = self::vendor_badge_reasons( $vendor_profile_id, $badges );
		}

		return $response;
	}

	/**
	 * Organizer verification status payload.
	 *
	 * @param int                  $organizer_id Organizer user ID.
	 * @param array<string, mixed> $context      public flag.
	 * @return array<string, mixed>
	 */
	public static function get_organizer_verification_status( int $organizer_id, array $context = array() ): array {
		$organizer_id = absint( $organizer_id );
		$is_public    = ! empty( $context['public'] );
		$user         = get_userdata( $organizer_id );

		$status       = self::compute_organizer_level_data( $organizer_id );
		$level        = $status['level'];
		$payout_connected = $status['payout_connected'];
		$manual_verified  = $status['manual_verified'];
		$verified         = $status['verified'];
		$trusted          = $status['trusted'];

		$labels    = self::organizer_level_labels();
		$checklist = class_exists( 'LNKup_Organizer_Launch_Checklist' )
			? LNKup_Organizer_Launch_Checklist::get_checklist( $organizer_id )
			: array( 'items' => array(), 'percent' => 0 );
		$completed_items = array();
		$missing_items   = array();
		foreach ( (array) ( $checklist['items'] ?? array() ) as $item ) {
			if ( LNKup_Organizer_Launch_Checklist::STATUS_COMPLETE === ( $item['status'] ?? '' ) ) {
				$completed_items[] = (string) ( $item['title'] ?? '' );
			} else {
				$missing_items[] = (string) ( $item['title'] ?? '' );
			}
		}
		if ( $payout_connected ) {
			$completed_items[] = __( 'Stripe Express connected', 'lnkup-marketplace' );
		} else {
			$missing_items[] = __( 'Finish payout setup', 'lnkup-marketplace' );
		}
		if ( $manual_verified ) {
			$completed_items[] = __( 'Verified by LNKup', 'lnkup-marketplace' );
		}

		$completed_items = array_values( array_unique( array_filter( $completed_items ) ) );
		$missing_items   = array_values( array_unique( array_filter( $missing_items ) ) );
		$next_action     = ! empty( $missing_items[0] ) ? $missing_items[0] : '';
		$percent         = self::compute_organizer_percent( $level, (int) ( $checklist['percent'] ?? 0 ) );
		$badges          = self::get_organizer_badges( $organizer_id, $is_public ? 'card' : 'profile' );

		$response = array(
			'level'            => $level,
			'label'            => $labels[ $level ] ?? $level,
			'percent_complete' => $percent,
			'badges'           => wp_list_pluck( $badges, 'label' ),
			'badge_details'    => $badges,
			'completed_items'  => $completed_items,
			'missing_items'    => $missing_items,
			'next_action'      => $next_action,
			'public_summary'   => self::organizer_public_summary( $level, $payout_connected ),
			'organizer_id'     => $organizer_id,
			'payout_connected' => $payout_connected,
		);
		if ( class_exists( 'LNKup_Organizer_Verification' ) && ( $is_public || get_current_user_id() === $organizer_id || LNKup_Capabilities::user_has_manage_all() ) ) {
			$response['indicators'] = LNKup_Organizer_Verification::indicators( $organizer_id, $is_public );
		}

		if ( ! $is_public && LNKup_Capabilities::user_has_manage_all() ) {
			$response['internal_notes'] = (string) get_user_meta( $organizer_id, 'lnkup_organizer_verification_notes', true );
			$response['manual_verified'] = $manual_verified;
		}

		return $response;
	}

	/**
	 * Vendor badges for display (context-filtered).
	 *
	 * @param int                  $vendor_profile_id Profile ID.
	 * @param string               $context           profile|public|application|card.
	 * @param array<string, mixed> $args              opportunity_id, application_id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_vendor_badges( int $vendor_profile_id, string $context = 'profile', array $args = array() ): array {
		$vendor_profile_id = absint( $vendor_profile_id );
		$ctx               = array_merge( $args, array( 'public' => in_array( $context, array( 'public', 'card' ), true ) ) );
		$level             = self::compute_vendor_level( $vendor_profile_id, $ctx );
		return self::build_vendor_badge_list( $vendor_profile_id, $level, $ctx, in_array( $context, array( 'public', 'card' ), true ), $context );
	}

	/**
	 * Organizer badges (derived).
	 *
	 * @param int    $organizer_id Organizer user ID.
	 * @param string $context      profile|card|public.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_organizer_badges( int $organizer_id, string $context = 'profile' ): array {
		unset( $context );
		$organizer_id = absint( $organizer_id );
		$level_data   = self::compute_organizer_level_data( $organizer_id );
		$level        = $level_data['level'];
		$badges       = array();
		$map          = array(
			'basic_organizer'          => __( 'Basic Organizer', 'lnkup-marketplace' ),
			'payout_connected'         => __( 'Payout Connected', 'lnkup-marketplace' ),
			'event_details_verified'   => __( 'Event Details Verified', 'lnkup-marketplace' ),
			'verified_organizer'       => __( 'Verified Organizer', 'lnkup-marketplace' ),
			'trusted_organizer'        => __( 'Trusted Organizer', 'lnkup-marketplace' ),
		);
		$extras = array(
			'payments_ready'  => __( 'Payments Ready', 'lnkup-marketplace' ),
			'packet_ready'      => __( 'Packet Ready', 'lnkup-marketplace' ),
			'repeat_organizer'  => __( 'Repeat Organizer', 'lnkup-marketplace' ),
		);

		$order = array_keys( $map );
		$idx   = array_search( $level, $order, true );
		if ( false !== $idx ) {
			for ( $i = 0; $i <= $idx; $i++ ) {
				$slug = $order[ $i ];
				if ( 'basic_organizer' === $slug ) {
					continue;
				}
				$badges[] = array( 'slug' => $slug, 'label' => $map[ $slug ], 'source' => 'derived' );
			}
		}

		if ( self::is_payout_connected( $organizer_id ) ) {
			$badges[] = array( 'slug' => 'payments_ready', 'label' => $extras['payments_ready'], 'source' => 'derived' );
		}
		if ( self::organizer_packet_ready( $organizer_id ) ) {
			$badges[] = array( 'slug' => 'packet_ready', 'label' => $extras['packet_ready'], 'source' => 'derived' );
		}
		if ( self::organizer_repeat_bookings( $organizer_id ) ) {
			$badges[] = array( 'slug' => 'repeat_organizer', 'label' => $extras['repeat_organizer'], 'source' => 'derived' );
		}

		return array_slice( self::dedupe_badges( $badges ), 0, 3 );
	}

	/**
	 * Required / suggested / not-applicable document items for vendor.
	 *
	 * @param int                  $vendor_profile_id Profile ID.
	 * @param array<string, mixed> $context           opportunity_id, application_id.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function get_required_verification_items_for_vendor( int $vendor_profile_id, array $context = array() ): array {
		$vendor_profile_id = absint( $vendor_profile_id );
		$vendor_id         = (int) get_post_meta( $vendor_profile_id, '_lnkup_owner_user_id', true );
		$opp_id            = self::resolve_context_opportunity_id( $context );

		$required_slugs = array();
		if ( $opp_id ) {
			$required_slugs = lnkup_get_opportunity_required_documents( $opp_id );
		} else {
			$required_slugs = self::aggregate_active_application_required_docs( $vendor_id );
		}

		$suggested_slugs = self::get_category_suggested_documents( $vendor_profile_id );
		$suggested_slugs = array_values( array_diff( $suggested_slugs, $required_slugs ) );

		$all_types = class_exists( 'LNKup_Documents' ) ? array_keys( LNKup_Documents::document_types() ) : array();
		$not_applicable = array_values( array_diff( $all_types, array_merge( $required_slugs, $suggested_slugs ) ) );

		$doc_map = class_exists( 'LNKup_Documents' ) ? LNKup_Documents::document_types() : array();
		$vendor_docs = $vendor_id && class_exists( 'LNKup_Documents' ) ? LNKup_Documents::get_for_vendor( $vendor_id ) : array();
		$by_type = array();
		foreach ( $vendor_docs as $doc ) {
			$type = (string) get_post_meta( $doc->ID, '_lnkup_document_type', true );
			$by_type[ $type ] = $doc;
		}

		$format_doc = function ( string $slug, bool $is_required ) use ( $doc_map, $by_type ): array {
			$doc    = $by_type[ $slug ] ?? null;
			$status = 'missing';
			if ( $doc ) {
				$ver = (string) get_post_meta( $doc->ID, '_lnkup_verification_status', true );
				if ( 'approved' === $ver ) {
					$exp = get_post_meta( $doc->ID, '_lnkup_expiration_date', true );
					if ( $exp && strtotime( (string) $exp ) < strtotime( '+30 days' ) ) {
						$status = 'expiring';
					} else {
						$status = 'approved';
					}
				} elseif ( 'rejected' === $ver ) {
					$status = 'rejected';
				} else {
					$status = 'pending';
				}
			}
			return array(
				'slug'       => $slug,
				'label'      => $doc_map[ $slug ] ?? $slug,
				'status'     => $status,
				'required'   => $is_required,
				'suggested'  => ! $is_required,
			);
		};

		$required = array_map(
			function ( $slug ) use ( $format_doc ) {
				return $format_doc( (string) $slug, true );
			},
			$required_slugs
		);
		$suggested = array_map(
			function ( $slug ) use ( $format_doc ) {
				return $format_doc( (string) $slug, false );
			},
			$suggested_slugs
		);
		$not_applicable_items = array_map(
			function ( $slug ) use ( $doc_map ) {
				return array(
					'slug'  => $slug,
					'label' => $doc_map[ $slug ] ?? $slug,
				);
			},
			$not_applicable
		);

		return array(
			'required'       => $required,
			'suggested'      => $suggested,
			'not_applicable' => $not_applicable_items,
		);
	}

	/**
	 * Document context summary for application review.
	 *
	 * @param int $application_id Application ID.
	 * @return array<string, mixed>
	 */
	public static function get_application_document_context( int $application_id ): array {
		$application_id = absint( $application_id );
		$profile_id     = (int) get_post_meta( $application_id, '_lnkup_vendor_profile_id', true );
		$opp_id         = (int) get_post_meta( $application_id, '_lnkup_opportunity_id', true );
		if ( ! $profile_id ) {
			$vendor_id  = (int) get_post_meta( $application_id, '_lnkup_vendor_user_id', true );
			$profile_id = (int) lnkup_get_vendor_profile_id( $vendor_id );
		}

		$items = self::get_required_verification_items_for_vendor(
			$profile_id,
			array( 'application_id' => $application_id, 'opportunity_id' => $opp_id )
		);
		$required = $items['required'] ?? array();
		if ( empty( $required ) ) {
			return array(
				'summary' => __( 'No required documents for this opportunity', 'lnkup-marketplace' ),
				'code'    => 'none_required',
			);
		}
		$missing = array_filter(
			$required,
			function ( $item ) {
				return 'approved' !== ( $item['status'] ?? '' );
			}
		);
		if ( empty( $missing ) ) {
			return array(
				'summary' => __( 'Required documents ready', 'lnkup-marketplace' ),
				'code'    => 'ready',
			);
		}
		$pending = array_filter(
			$missing,
			function ( $item ) {
				return in_array( $item['status'] ?? '', array( 'pending', 'expiring' ), true );
			}
		);
		if ( ! empty( $pending ) ) {
			return array(
				'summary' => __( 'Required documents pending review', 'lnkup-marketplace' ),
				'code'    => 'pending',
			);
		}
		return array(
			'summary' => __( 'Required documents missing', 'lnkup-marketplace' ),
			'code'    => 'missing',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function vendor_level_labels(): array {
		return array(
			'basic_vendor'      => __( 'Basic Vendor', 'lnkup-marketplace' ),
			'profile_complete'  => __( 'Profile Complete', 'lnkup-marketplace' ),
			'documents_ready'   => __( 'Documents Ready', 'lnkup-marketplace' ),
			'lnkup_verified'    => __( 'LNKup Verified', 'lnkup-marketplace' ),
			'top_rated_vendor'  => __( 'Top Rated Vendor', 'lnkup-marketplace' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function organizer_level_labels(): array {
		return array(
			'basic_organizer'        => __( 'Basic Organizer', 'lnkup-marketplace' ),
			'payout_connected'       => __( 'Payout Connected', 'lnkup-marketplace' ),
			'event_details_verified' => __( 'Event Details Verified', 'lnkup-marketplace' ),
			'verified_organizer'     => __( 'Verified Organizer', 'lnkup-marketplace' ),
			'trusted_organizer'      => __( 'Trusted Organizer', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Category-based document suggestions (not requirements).
	 *
	 * @param int $vendor_profile_id Profile ID.
	 * @return array<int, string>
	 */
	public static function get_category_suggested_documents( int $vendor_profile_id ): array {
		$terms = wp_get_post_terms( $vendor_profile_id, 'lnkup_vendor_category', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}
		$map = array(
			'food-truck'     => array( 'health_permit', 'food_handler_permit' ),
			'food-beverage'  => array( 'health_permit', 'food_handler_permit' ),
			'catering'       => array( 'health_permit', 'certificate_of_insurance' ),
			'photo-booth'    => array( 'certificate_of_insurance' ),
			'beauty'         => array( 'business_license' ),
			'jewelry'        => array( 'sales_tax_certificate' ),
			'art'            => array( 'sales_tax_certificate' ),
		);
		$suggested = array();
		foreach ( (array) $terms as $slug ) {
			$key = sanitize_title( (string) $slug );
			if ( isset( $map[ $key ] ) ) {
				$suggested = array_merge( $suggested, $map[ $key ] );
			}
		}
		$suggested = apply_filters( 'lnkup_verification_suggested_documents', $suggested, $vendor_profile_id, $terms );
		return array_values( array_unique( array_map( 'sanitize_key', $suggested ) ) );
	}

	/**
	 * @param array<string, mixed> $context Context.
	 */
	private static function resolve_context_opportunity_id( array $context ): int {
		if ( ! empty( $context['opportunity_id'] ) ) {
			return absint( $context['opportunity_id'] );
		}
		if ( ! empty( $context['application_id'] ) ) {
			return (int) get_post_meta( absint( $context['application_id'] ), '_lnkup_opportunity_id', true );
		}
		return 0;
	}

	/**
	 * Union of required docs across active vendor applications.
	 *
	 * @param int $vendor_id Vendor user ID.
	 * @return array<int, string>
	 */
	private static function aggregate_active_application_required_docs( int $vendor_id ): array {
		if ( ! $vendor_id ) {
			return array();
		}
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_vendor_user_id',
				'meta_value'     => $vendor_id,
				'meta_query'     => array(
					array(
						'key'     => '_lnkup_application_status',
						'value'   => array( 'submitted', 'under_review', 'approved', 'payment_required', 'paid', 'waitlisted' ),
						'compare' => 'IN',
					),
				),
			)
		);
		$slugs = array();
		foreach ( $apps as $app_id ) {
			$opp_id = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
			if ( $opp_id ) {
				$slugs = array_merge( $slugs, lnkup_get_opportunity_required_documents( $opp_id ) );
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * @param array<string, mixed> $strength Profile strength payload.
	 */
	private static function is_profile_complete( int $profile_id, array $strength ): bool {
		if ( empty( $profile_id ) ) {
			return false;
		}
		$score = (int) ( $strength['score'] ?? 0 );
		if ( $score >= self::PROFILE_COMPLETE_THRESHOLD ) {
			return true;
		}
		$core_keys = array( 'business_basics', 'contact', 'category', 'markets', 'setup', 'photos' );
		$sections  = (array) ( $strength['sections'] ?? array() );
		$by_key    = array();
		foreach ( $sections as $section ) {
			$by_key[ $section['key'] ?? '' ] = ! empty( $section['complete'] );
		}
		foreach ( $core_keys as $key ) {
			if ( empty( $by_key[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<int, array<string, mixed>> $required Required doc items.
	 */
	private static function is_documents_ready( array $required ): bool {
		if ( empty( $required ) ) {
			return true;
		}
		foreach ( $required as $item ) {
			$status = $item['status'] ?? '';
			if ( ! in_array( $status, array( 'approved', 'pending', 'expiring' ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $metrics Metrics.
	 * @param array<string, mixed> $review_sum Review summary.
	 */
	private static function is_top_rated_vendor(
		int $profile_id,
		bool $lnkup_verified,
		bool $profile_complete,
		bool $documents_ready,
		array $metrics,
		array $review_sum
	): bool {
		unset( $profile_id );
		$strong_rep = (float) ( $review_sum['avg'] ?? 0 ) >= 4.5 && (int) ( $review_sum['count'] ?? 0 ) >= 5;
		$events     = (int) ( $metrics['completed_events'] ?? 0 ) >= 3;
		$low_risk   = (int) ( $metrics['no_show_count'] ?? 0 ) === 0;
		$base_ok    = $lnkup_verified || ( $profile_complete && $documents_ready && $strong_rep );
		return $base_ok && $strong_rep && $events && $low_risk;
	}

	private static function has_high_severity_flags( int $profile_id ): bool {
		$vendor_id = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		if ( ! $vendor_id ) {
			return false;
		}
		$severe = array( 'chargeback', 'rule_violation', 'no_show' );
		$flags  = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_flag',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_vendor_user_id',
				'meta_value'     => $vendor_id,
			)
		);
		foreach ( $flags as $flag_id ) {
			$type = (string) get_post_meta( $flag_id, '_lnkup_flag_type', true );
			if ( in_array( $type, $severe, true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function is_payout_connected( int $organizer_id ): bool {
		if ( class_exists( 'LNKup_Stripe_Connect' ) ) {
			if ( ! LNKup_Stripe_Connect::is_enabled() ) {
				return true;
			}
			return LNKup_Stripe_Connect::is_connect_ready( $organizer_id );
		}
		return false;
	}

	private static function organizer_event_details_verified( int $organizer_id ): bool {
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $organizer_id,
			)
		);
		if ( empty( $events ) ) {
			return false;
		}
		$event = $events[0];
		$date  = get_post_meta( $event->ID, '_lnkup_event_date', true );
		$city  = get_post_meta( $event->ID, '_lnkup_city', true );
		$contact = get_post_meta( $event->ID, '_lnkup_organizer_contact_email', true );
		if ( ! $contact ) {
			$user = get_userdata( $organizer_id );
			$contact = $user ? $user->user_email : '';
		}
		return (bool) ( $date && ( $city || get_post_meta( $event->ID, '_lnkup_venue_name', true ) ) && $contact );
	}

	private static function organizer_has_live_opportunity( int $organizer_id ): bool {
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $organizer_id,
			)
		);
		if ( empty( $events ) ) {
			return false;
		}
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post_status'    => 'publish',
				'meta_query'     => array(
					array(
						'key'     => '_lnkup_event_id',
						'value'   => $events,
						'compare' => 'IN',
					),
				),
			)
		);
		return ! empty( $opps );
	}

	private static function is_trusted_organizer( int $organizer_id ): bool {
		$paid = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => '_lnkup_organizer_user_id', 'value' => $organizer_id ),
					array( 'key' => '_lnkup_application_status', 'value' => array( 'paid', 'completed' ), 'compare' => 'IN' ),
				),
			)
		);
		if ( empty( $paid ) ) {
			return false;
		}
		global $wpdb;
		if ( class_exists( 'LNKup_Financial_Tables' ) ) {
			$table = LNKup_Financial_Tables::fraud_flags_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$flags = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = 'open' AND severity = 'high'",
					$organizer_id
				)
			);
			if ( $flags > 0 ) {
				return false;
			}
		}
		return true;
	}

	private static function organizer_packet_ready( int $organizer_id ): bool {
		if ( ! class_exists( 'LNKup_Organizer_Launch_Checklist' ) ) {
			return false;
		}
		$checklist = LNKup_Organizer_Launch_Checklist::get_checklist( $organizer_id );
		foreach ( (array) ( $checklist['items'] ?? array() ) as $item ) {
			if ( 'vendor_packet' === ( $item['key'] ?? '' ) ) {
				return LNKup_Organizer_Launch_Checklist::STATUS_COMPLETE === ( $item['status'] ?? '' );
			}
		}
		return false;
	}

	private static function organizer_repeat_bookings( int $organizer_id ): bool {
		$count = count(
			get_posts(
				array(
					'post_type'      => 'lnkup_event',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_lnkup_organizer_user_id',
					'meta_value'     => $organizer_id,
				)
			)
		);
		return $count >= 2;
	}

	/**
	 * @param array<string, mixed> $strength Strength.
	 * @param array<int, array<string, mixed>> $required Required docs.
	 */
	private static function vendor_completed_items(
		int $profile_id,
		array $strength,
		array $required,
		bool $admin_approved,
		array $metrics
	): array {
		$items = array();
		if ( self::is_profile_complete( $profile_id, $strength ) ) {
			$items[] = __( 'Business profile complete', 'lnkup-marketplace' );
			$items[] = __( 'Contact info added', 'lnkup-marketplace' );
		}
		if ( (int) ( $metrics['completed_events'] ?? 0 ) > 0 ) {
			$items[] = __( 'Event history on LNKup', 'lnkup-marketplace' );
		}
		if ( $admin_approved ) {
			$items[] = __( 'Verified by LNKup team', 'lnkup-marketplace' );
		}
		foreach ( $required as $doc ) {
			if ( 'approved' === ( $doc['status'] ?? '' ) ) {
				$items[] = sprintf(
					/* translators: %s: document label */
					__( '%s approved', 'lnkup-marketplace' ),
					$doc['label'] ?? $doc['slug']
				);
			}
		}
		return array_values( array_unique( $items ) );
	}

	/**
	 * @param array<string, mixed> $strength Strength.
	 * @param array<int, array<string, mixed>> $required Required docs.
	 */
	private static function vendor_missing_items(
		int $profile_id,
		array $strength,
		array $required,
		bool $admin_approved,
		bool $profile_complete,
		bool $documents_ready
	): array {
		$items = (array) ( $strength['missing_items'] ?? array() );
		if ( ! $profile_complete ) {
			$items[] = __( 'Complete your business profile', 'lnkup-marketplace' );
		}
		if ( ! $documents_ready ) {
			foreach ( $required as $doc ) {
				if ( ! in_array( $doc['status'] ?? '', array( 'approved', 'pending', 'expiring' ), true ) ) {
					$items[] = sprintf(
						/* translators: %s: document label */
						__( 'Upload %s', 'lnkup-marketplace' ),
						$doc['label'] ?? $doc['slug']
					);
				}
			}
		}
		if ( $profile_complete && $documents_ready && ! $admin_approved ) {
			$items[] = __( 'Awaiting LNKup verification review', 'lnkup-marketplace' );
		}
		return array_values( array_unique( array_filter( $items ) ) );
	}

	/**
	 * @param array<string, mixed> $strength Strength.
	 * @param array<int, array<string, mixed>> $suggested Suggested docs.
	 * @param array<int, array<string, mixed>> $required Required docs.
	 * @return array<int, string>
	 */
	private static function vendor_recommended_items( array $strength, array $suggested, array $required ): array {
		$items = array();
		foreach ( (array) ( $strength['recommended_actions'] ?? array() ) as $action ) {
			if ( ! empty( $action['label'] ) ) {
				$items[] = (string) $action['label'];
			}
		}
		foreach ( $suggested as $doc ) {
			if ( 'missing' === ( $doc['status'] ?? '' ) && empty( $required ) ) {
				$items[] = sprintf(
					/* translators: %s: document label */
					__( 'Upload %s if your target events require it', 'lnkup-marketplace' ),
					$doc['label'] ?? $doc['slug']
				);
			} elseif ( 'missing' === ( $doc['status'] ?? '' ) ) {
				$items[] = sprintf(
					/* translators: %s: document label */
					__( '%s may help for larger venues, but it is not required right now', 'lnkup-marketplace' ),
					$doc['label'] ?? $doc['slug']
				);
			}
		}
		return array_values( array_unique( array_filter( $items ) ) );
	}

	/**
	 * @param array<string, mixed> $strength Strength.
	 * @param array<int, array<string, mixed>> $required Required docs.
	 * @param array<string, mixed> $metrics Metrics.
	 */
	private static function compute_vendor_percent( array $strength, array $required, bool $admin_approved, array $metrics, string $level ): int {
		$profile_score = min( 100, (int) ( $strength['score'] ?? 0 ) );
		$doc_score     = 100;
		if ( ! empty( $required ) ) {
			$approved = 0;
			foreach ( $required as $doc ) {
				if ( in_array( $doc['status'] ?? '', array( 'approved', 'pending', 'expiring' ), true ) ) {
					++$approved;
				}
			}
			$doc_score = (int) round( ( $approved / count( $required ) ) * 100 );
		}
		$rep_score = min( 100, (int) round( (float) ( $metrics['avg_organizer_rating'] ?? 0 ) * 20 ) );
		$admin_pts = $admin_approved ? 100 : ( 'lnkup_verified' === $level ? 50 : 0 );
		$percent   = (int) round( ( $profile_score * 0.4 ) + ( $doc_score * 0.3 ) + ( $rep_score * 0.2 ) + ( $admin_pts * 0.1 ) );
		return min( 100, max( 0, $percent ) );
	}

	private static function compute_organizer_percent( string $level, int $checklist_percent ): int {
		$map = array(
			'basic_organizer'        => 15,
			'payout_connected'       => 45,
			'event_details_verified' => 65,
			'verified_organizer'     => 85,
			'trusted_organizer'      => 100,
		);
		$base = $map[ $level ] ?? 10;
		return min( 100, max( $base, (int) round( ( $base + $checklist_percent ) / 2 ) ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $required Required docs.
	 */
	private static function vendor_public_summary( string $level, int $percent, array $required ): string {
		unset( $percent );
		$labels = self::vendor_level_labels();
		$label  = $labels[ $level ] ?? $level;
		if ( empty( $required ) && in_array( $level, array( 'profile_complete', 'documents_ready', 'lnkup_verified', 'top_rated_vendor' ), true ) ) {
			return sprintf(
				/* translators: %s: verification level label */
				__( 'This vendor has completed their LNKup profile. %s', 'lnkup-marketplace' ),
				$label
			);
		}
		if ( 'lnkup_verified' === $level || 'top_rated_vendor' === $level ) {
			return __( 'Verified by LNKup — profile, documents, and event history check out.', 'lnkup-marketplace' );
		}
		return sprintf(
			/* translators: %s: verification level label */
			__( 'This vendor is building trust on LNKup. Current status: %s.', 'lnkup-marketplace' ),
			$label
		);
	}

	private static function organizer_public_summary( string $level, bool $payout_connected ): string {
		if ( $payout_connected ) {
			return __( 'This organizer can securely collect vendor payments through LNKup.', 'lnkup-marketplace' );
		}
		if ( 'trusted_organizer' === $level ) {
			return __( 'Trusted organizer with a strong track record on LNKup.', 'lnkup-marketplace' );
		}
		if ( 'verified_organizer' === $level ) {
			return __( 'Verified organizer with complete event details and payout setup.', 'lnkup-marketplace' );
		}
		return __( 'This organizer is setting up their LNKup presence.', 'lnkup-marketplace' );
	}

	/**
	 * @param array<int, array<string, mixed>> $badges Badges.
	 * @return array<int, array<string, string>>
	 */
	private static function vendor_badge_reasons( int $profile_id, array $badges ): array {
		$reasons = array();
		foreach ( $badges as $badge ) {
			$reasons[] = array(
				'badge'  => $badge['label'] ?? '',
				'reason' => ( 'derived' === ( $badge['source'] ?? '' ) )
					? __( 'Earned from profile and verification criteria', 'lnkup-marketplace' )
					: __( 'Awarded from platform activity or admin action', 'lnkup-marketplace' ),
			);
		}
		return $reasons;
	}

	/**
	 * Compute vendor verification level slug.
	 *
	 * @param int                  $vendor_profile_id Profile ID.
	 * @param array<string, mixed> $context           Context.
	 */
	private static function compute_vendor_level( int $vendor_profile_id, array $context = array() ): string {
		$profile = get_post( $vendor_profile_id );
		$vendor_id = $profile ? (int) get_post_meta( $vendor_profile_id, '_lnkup_owner_user_id', true ) : 0;
		$strength = class_exists( 'LNKup_Growth_Profile_Strength' )
			? LNKup_Growth_Profile_Strength::calculate( $vendor_profile_id )
			: array();
		$metrics = class_exists( 'LNKup_Reliability_Metrics' ) ? LNKup_Reliability_Metrics::get( $vendor_profile_id ) : array();
		$review_sum = class_exists( 'LNKup_Vendor_Reviews' ) ? LNKup_Vendor_Reviews::summary_for_vendor( $vendor_profile_id ) : array( 'avg' => 0, 'count' => 0 );
		$manual = $profile ? (string) get_post_meta( $vendor_profile_id, '_lnkup_verification_status', true ) : '';
		$required = self::get_required_verification_items_for_vendor( $vendor_profile_id, $context )['required'] ?? array();

		$profile_complete = self::is_profile_complete( $vendor_profile_id, $strength );
		$documents_ready  = self::is_documents_ready( $required );
		$admin_approved   = 'approved' === $manual;
		$lnkup_verified   = $profile_complete && $documents_ready && $admin_approved && ! self::has_high_severity_flags( $vendor_profile_id );
		$top_rated        = self::is_top_rated_vendor( $vendor_profile_id, $lnkup_verified, $profile_complete, $documents_ready, $metrics, $review_sum );

		if ( $top_rated ) {
			return 'top_rated_vendor';
		}
		if ( $lnkup_verified ) {
			return 'lnkup_verified';
		}
		if ( $documents_ready && $profile_complete ) {
			return 'documents_ready';
		}
		if ( $profile_complete ) {
			return 'profile_complete';
		}
		if ( $profile && $vendor_id ) {
			return 'basic_vendor';
		}
		return 'basic_vendor';
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function compute_organizer_level_data( int $organizer_id ): array {
		$user = get_userdata( $organizer_id );
		$payout_connected = self::is_payout_connected( $organizer_id );
		$event_details    = self::organizer_event_details_verified( $organizer_id );
		$has_live_opp     = self::organizer_has_live_opportunity( $organizer_id );
		$manual_verified  = class_exists( 'LNKup_Organizer_Verification' )
			? LNKup_Organizer_Verification::is_verified( $organizer_id )
			: (bool) get_user_meta( $organizer_id, 'lnkup_organizer_verified', true );
		$verified         = $payout_connected && $event_details && $has_live_opp && $manual_verified;
		$trusted          = $verified && self::is_trusted_organizer( $organizer_id );

		$level = 'basic_organizer';
		if ( $trusted ) {
			$level = 'trusted_organizer';
		} elseif ( $verified ) {
			$level = 'verified_organizer';
		} elseif ( $event_details ) {
			$level = 'event_details_verified';
		} elseif ( $payout_connected ) {
			$level = 'payout_connected';
		} elseif ( $user ) {
			$level = 'basic_organizer';
		}

		return array(
			'level'            => $level,
			'payout_connected' => $payout_connected,
			'manual_verified'  => $manual_verified,
			'verified'         => $verified,
			'trusted'          => $trusted,
		);
	}

	/**
	 * @param int                  $vendor_profile_id Profile ID.
	 * @param string               $level             Level slug.
	 * @param array<string, mixed> $context           Context.
	 * @param bool                 $is_public         Public view.
	 * @param string               $display_context   Display context key.
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_vendor_badge_list(
		int $vendor_profile_id,
		string $level,
		array $context,
		bool $is_public,
		string $display_context = 'profile'
	): array {
		$doc_ctx       = self::get_required_verification_items_for_vendor( $vendor_profile_id, $context );
		$relevant_docs = array_merge(
			wp_list_pluck( $doc_ctx['required'] ?? array(), 'slug' ),
			wp_list_pluck( $doc_ctx['suggested'] ?? array(), 'slug' )
		);
		$badges = array();
		$level_badges = array(
			'profile_complete' => __( 'Profile Complete', 'lnkup-marketplace' ),
			'documents_ready'  => __( 'Documents Ready', 'lnkup-marketplace' ),
			'lnkup_verified'   => __( 'Verified by LNKup', 'lnkup-marketplace' ),
			'top_rated_vendor' => __( 'Top Rated Vendor', 'lnkup-marketplace' ),
		);
		$level_order = array( 'basic_vendor', 'profile_complete', 'documents_ready', 'lnkup_verified', 'top_rated_vendor' );
		$current_idx = array_search( $level, $level_order, true );
		foreach ( $level_order as $idx => $slug ) {
			if ( false !== $current_idx && $idx <= $current_idx && isset( $level_badges[ $slug ] ) ) {
				$badges[] = array(
					'slug'   => $slug,
					'label'  => $level_badges[ $slug ],
					'source' => 'derived',
				);
			}
		}

		$catalog = class_exists( 'LNKup_Vendor_Badges' ) ? LNKup_Vendor_Badges::catalog() : array();
		$active  = class_exists( 'LNKup_Vendor_Badges' ) ? LNKup_Vendor_Badges::get_active( $vendor_profile_id ) : array();
		foreach ( $active as $badge ) {
			$slug = $badge['slug'] ?? '';
			$def  = $catalog[ $slug ] ?? array();
			if ( ! empty( $def['doc'] ) && ! in_array( $def['doc'], $relevant_docs, true ) && $is_public ) {
				continue;
			}
			$label = $badge['label'] ?? $slug;
			if ( 'verified_vendor' === $slug && in_array( $display_context, array( 'public', 'card' ), true ) ) {
				$label = __( 'Verified by LNKup', 'lnkup-marketplace' );
			}
			$badges[] = array(
				'slug'   => $slug,
				'label'  => $label,
				'source' => $badge['source'] ?? 'auto',
			);
		}

		return self::dedupe_badges( $badges );
	}

	/**
	 * @param array<int, array<string, mixed>> $badges Badges.
	 * @return array<int, array<string, mixed>>
	 */
	private static function dedupe_badges( array $badges ): array {
		$seen = array();
		$out  = array();
		foreach ( $badges as $badge ) {
			$key = strtolower( (string) ( $badge['label'] ?? '' ) );
			if ( $key && isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $badge;
		}
		return $out;
	}
}
