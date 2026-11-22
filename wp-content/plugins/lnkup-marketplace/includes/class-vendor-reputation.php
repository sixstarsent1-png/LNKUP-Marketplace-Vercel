<?php
/**
 * Vendor reputation bootstrap, refresh, and activity logging.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Reputation {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'lnkup_application_status_changed', array( __CLASS__, 'on_application_status' ), 20, 3 );
		add_action( 'lnkup_vendor_review_submitted', array( __CLASS__, 'on_review_submitted' ), 10, 2 );
		add_action( 'lnkup_reputation_score_changed', array( __CLASS__, 'maybe_snapshot' ), 10, 2 );
		add_action( self::REFRESH_HOOK, array( __CLASS__, 'refresh' ) );
	}

	const REFRESH_HOOK = 'lnkup_reputation_refresh_profile';

	/**
	 * Whether an ID is a vendor profile post.
	 *
	 * @param int $profile_id Post ID.
	 */
	public static function is_vendor_profile( $profile_id ) {
		$profile_id = absint( $profile_id );
		return $profile_id && 'lnkup_vendor_profile' === get_post_type( $profile_id );
	}

	/**
	 * Queue a background refresh (deduplicated by WP-Cron).
	 *
	 * @param int $profile_id Vendor profile post ID.
	 */
	public static function queue_refresh( $profile_id ) {
		$profile_id = absint( $profile_id );
		if ( ! self::is_vendor_profile( $profile_id ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::REFRESH_HOOK, array( $profile_id ) ) ) {
			wp_schedule_single_event( time() + 5, self::REFRESH_HOOK, array( $profile_id ) );
		}
	}

	/**
	 * Refresh cached reputation for profile.
	 *
	 * @param int $profile_id Vendor profile post ID.
	 */
	public static function refresh( $profile_id ) {
		$profile_id = absint( $profile_id );
		if ( ! self::is_vendor_profile( $profile_id ) ) {
			return null;
		}

		$old_score = (int) get_post_meta( $profile_id, '_lnkup_reputation_score', true );
		$result    = LNKup_Reputation_Score::calculate( $profile_id );

		update_post_meta( $profile_id, '_lnkup_reputation_score', $result['score'] );
		update_post_meta( $profile_id, '_lnkup_reputation_label', $result['label'] );
		update_post_meta( $profile_id, '_lnkup_avg_rating', $result['avg_rating'] );
		update_post_meta( $profile_id, '_lnkup_review_count', $result['review_count'] );
		update_post_meta( $profile_id, '_lnkup_reputation_breakdown', wp_json_encode( $result['breakdown'] ) );
		update_post_meta( $profile_id, '_lnkup_reputation_updated_at', current_time( 'mysql' ) );

		if ( ! get_post_meta( $profile_id, '_lnkup_public_slug', true ) ) {
			self::ensure_public_slug( $profile_id );
		}

		if ( class_exists( 'LNKup_Vendor_Badges' ) ) {
			LNKup_Vendor_Badges::evaluate_auto( $profile_id );
		}

		if ( $old_score !== $result['score'] ) {
			self::log_activity(
				'score_changed',
				$profile_id,
				array(
					'old' => $old_score,
					'new' => $result['score'],
					'label' => $result['label'],
				)
			);
			do_action( 'lnkup_reputation_score_changed', $profile_id, $result );
		}

		return $result;
	}

	/**
	 * Get cached reputation block.
	 *
	 * @param int $profile_id Profile ID.
	 */
	public static function get( $profile_id ) {
		$profile_id = absint( $profile_id );
		if ( ! self::is_vendor_profile( $profile_id ) ) {
			return null;
		}
		$updated = get_post_meta( $profile_id, '_lnkup_reputation_updated_at', true );
		if ( ! $updated ) {
			// Reads never write: compute in the background and serve a neutral state now.
			self::queue_refresh( $profile_id );
			return self::neutral( $profile_id );
		}
		$breakdown = json_decode( get_post_meta( $profile_id, '_lnkup_reputation_breakdown', true ) ?: '{}', true );
		return array(
			'score'        => (int) get_post_meta( $profile_id, '_lnkup_reputation_score', true ),
			'label'        => get_post_meta( $profile_id, '_lnkup_reputation_label', true ),
			'avg_rating'   => (float) get_post_meta( $profile_id, '_lnkup_avg_rating', true ),
			'review_count' => (int) get_post_meta( $profile_id, '_lnkup_review_count', true ),
			'breakdown'    => is_array( $breakdown ) ? $breakdown : array(),
			'is_new'       => LNKup_Reputation_Score::LABEL_NEW === get_post_meta( $profile_id, '_lnkup_reputation_label', true ),
			'metrics'      => LNKup_Reliability_Metrics::get( $profile_id ),
			'badges'       => class_exists( 'LNKup_Vendor_Badges' ) ? LNKup_Vendor_Badges::get_active( $profile_id ) : array(),
			'public_slug'  => get_post_meta( $profile_id, '_lnkup_public_slug', true ),
			'updated_at'   => $updated,
		);
	}

	/**
	 * Neutral reputation for profiles not yet computed.
	 *
	 * @param int $profile_id Profile ID.
	 */
	public static function neutral( $profile_id ) {
		return array(
			'score'        => 0,
			'label'        => LNKup_Reputation_Score::LABEL_NEW,
			'avg_rating'   => 0.0,
			'review_count' => 0,
			'breakdown'    => array(),
			'is_new'       => true,
			'metrics'      => LNKup_Reliability_Metrics::get( $profile_id ),
			'badges'       => class_exists( 'LNKup_Vendor_Badges' ) ? LNKup_Vendor_Badges::get_active( $profile_id ) : array(),
			'public_slug'  => (string) get_post_meta( $profile_id, '_lnkup_public_slug', true ),
			'updated_at'   => '',
			'pending'      => true,
		);
	}

	/**
	 * Log reputation activity.
	 */
	public static function log_activity( $type, $profile_id, $args = array() ) {
		global $wpdb;
		$vendor_id = (int) get_post_meta( absint( $profile_id ), '_lnkup_owner_user_id', true );
		$wpdb->insert(
			LNKup_Reputation_Tables::activity_table(),
			array(
				'vendor_profile_id' => absint( $profile_id ),
				'vendor_user_id'    => $vendor_id,
				'organizer_user_id' => absint( $args['organizer_user_id'] ?? 0 ),
				'event_id'          => absint( $args['event_id'] ?? 0 ),
				'application_id'    => absint( $args['application_id'] ?? 0 ),
				'activity_type'     => sanitize_key( $type ),
				'payload'           => wp_json_encode( $args['payload'] ?? $args ),
				'actor_user_id'     => get_current_user_id(),
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Ensure public slug exists.
	 *
	 * @param int $profile_id Profile ID.
	 */
	public static function ensure_public_slug( $profile_id ) {
		$slug = get_post_meta( $profile_id, '_lnkup_public_slug', true );
		if ( $slug ) {
			return $slug;
		}
		$name = get_post_meta( $profile_id, '_lnkup_business_name', true ) ?: get_the_title( $profile_id );
		$slug = sanitize_title( $name );
		if ( ! $slug ) {
			$slug = 'vendor-' . $profile_id;
		}
		$base = $slug;
		$i    = 1;
		while ( self::slug_exists( $slug, $profile_id ) ) {
			$slug = $base . '-' . $i;
			$i++;
		}
		update_post_meta( $profile_id, '_lnkup_public_slug', $slug );
		return $slug;
	}

	/**
	 * Check slug uniqueness.
	 */
	private static function slug_exists( $slug, $exclude_id ) {
		$posts = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_profile',
				'posts_per_page' => 1,
				'post__not_in'   => array( $exclude_id ),
				'meta_key'       => '_lnkup_public_slug',
				'meta_value'     => $slug,
				'fields'         => 'ids',
			)
		);
		return ! empty( $posts );
	}

	/**
	 * Resolve a public Passport by slug only. Numeric IDs, drafts and private profiles never resolve.
	 *
	 * @param string $slug Public slug.
	 */
	public static function resolve_public_profile( $slug ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug ) {
			return 0;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_profile',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_public_slug', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$profile_id = $posts ? (int) $posts[0] : 0;
		if ( ! $profile_id || ! LNKup_Passport_Privacy::is_public( $profile_id ) ) {
			return 0;
		}
		return $profile_id;
	}

	/**
	 * Public slug lookup that also lets the owner (or staff) preview a private Passport.
	 *
	 * @param string $slug    Public slug.
	 * @param int    $user_id Viewer user ID.
	 * @return array{profile_id:int,is_preview:bool}
	 */
	public static function resolve_viewable_profile( $slug, $user_id = 0 ) {
		$profile_id = self::resolve_public_profile( $slug );
		if ( $profile_id ) {
			return array( 'profile_id' => $profile_id, 'is_preview' => false );
		}
		$user_id = absint( $user_id );
		if ( ! $user_id || is_numeric( $slug ) ) {
			return array( 'profile_id' => 0, 'is_preview' => false );
		}
		$candidate = self::resolve_profile( $slug );
		if ( $candidate && ( (int) get_post_meta( $candidate, '_lnkup_owner_user_id', true ) === $user_id || LNKup_Capabilities::user_has_manage_all( $user_id ) ) ) {
			return array( 'profile_id' => $candidate, 'is_preview' => true );
		}
		return array( 'profile_id' => 0, 'is_preview' => false );
	}

	/**
	 * Resolve profile by slug or ID (authenticated/internal contexts only).
	 *
	 * @param string $slug_or_id Slug or numeric ID.
	 */
	public static function resolve_profile( $slug_or_id ) {
		if ( is_numeric( $slug_or_id ) ) {
			$post = get_post( absint( $slug_or_id ) );
			return ( $post && 'lnkup_vendor_profile' === $post->post_type ) ? $post->ID : 0;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_profile',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_public_slug',
				'meta_value'     => sanitize_title( $slug_or_id ),
				'fields'         => 'ids',
			)
		);
		return $posts ? (int) $posts[0] : 0;
	}

	/**
	 * Backfill reputation for all profiles.
	 */
	public static function backfill_all() {
		$profiles = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_profile',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => 'publish',
			)
		);
		foreach ( $profiles as $pid ) {
			self::refresh( $pid );
		}
	}

	/**
	 * Improvement tips for vendor dashboard.
	 *
	 * @param int $profile_id Profile ID.
	 */
	public static function get_improvement_tips( $profile_id ) {
		$tips    = array();
		$vendor_id = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		$metrics = LNKup_Reliability_Metrics::get( $profile_id );
		$badges  = class_exists( 'LNKup_Vendor_Badges' ) ? LNKup_Vendor_Badges::get_active_slugs( $profile_id ) : array();

		if ( ! in_array( 'insurance_verified', $badges, true ) ) {
			$tips[] = __( 'Upload your COI to unlock Insurance Verified.', 'lnkup-marketplace' );
		}
		if ( $metrics['completed_events'] < 5 ) {
			$remaining = 5 - $metrics['completed_events'];
			$tips[] = sprintf(
				/* translators: %d: events remaining */
				__( 'Complete %d more events to earn Completed 5+ Events.', 'lnkup-marketplace' ),
				$remaining
			);
		}
		if ( $metrics['payment_completion_rate'] < 100 ) {
			$tips[] = __( 'Pay accepted bookings on time to improve reliability.', 'lnkup-marketplace' );
		}
		if ( $metrics['document_compliance_rate'] < 100 ) {
			$tips[] = __( 'Upload and verify required documents to boost your score.', 'lnkup-marketplace' );
		}
		if ( 0 === $metrics['review_count'] && $metrics['completed_events'] > 0 ) {
			$tips[] = __( 'Great events lead to reviews — keep delivering quality experiences.', 'lnkup-marketplace' );
		}
		return $tips;
	}

	public static function on_application_status( $app_id, $prev, $status ) {
		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		if ( $profile_id ) {
			self::refresh( $profile_id );
		}
	}

	public static function on_review_submitted( $review_id, $profile_id ) {
		self::log_activity(
			'review_submitted',
			$profile_id,
			array(
				'payload' => array( 'review_id' => $review_id ),
			)
		);
		self::refresh( $profile_id );
	}

	/**
	 * Weekly snapshot debounce.
	 */
	public static function maybe_snapshot( $profile_id, $result ) {
		global $wpdb;
		$table = LNKup_Reputation_Tables::snapshot_table();
		$last  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT created_at FROM {$table} WHERE vendor_profile_id = %d ORDER BY created_at DESC LIMIT 1",
				$profile_id
			)
		);
		if ( $last && strtotime( $last ) > strtotime( '-6 days' ) ) {
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'vendor_profile_id' => $profile_id,
				'score'             => $result['score'],
				'label'             => $result['label'],
				'breakdown'         => wp_json_encode( $result['breakdown'] ),
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}
}
