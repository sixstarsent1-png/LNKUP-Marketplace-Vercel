<?php
/**
 * City Connector funnel tracking.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Connector_Tracking {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'save_post_lnkup_vendor_profile', array( __CLASS__, 'on_vendor_profile_save' ), 20, 2 );
		add_action( 'lnkup_application_submitted', array( __CLASS__, 'on_application_submitted' ), 20, 1 );
		add_action( 'lnkup_application_approved', array( __CLASS__, 'on_application_approved' ), 20, 1 );
		add_action( 'lnkup_payment_completed', array( __CLASS__, 'on_payment_completed' ), 15, 1 );
		add_action( 'save_post_lnkup_event', array( __CLASS__, 'on_event_save' ), 20, 2 );
		add_action( 'save_post_lnkup_opportunity', array( __CLASS__, 'on_opportunity_save' ), 20, 2 );
	}

	/**
	 * Update referral milestone column once.
	 *
	 * @param int    $referral_id Referral ID.
	 * @param string $column      Column name.
	 */
	private static function touch_milestone( $referral_id, $column ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referrals_table();
		$allowed = array( 'profile_completed_at', 'first_application_at', 'first_payment_at', 'first_event_at', 'first_opportunity_at' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $referral_id ) ), ARRAY_A );
		if ( ! $row || ! empty( $row[ $column ] ) ) {
			return;
		}
		$wpdb->update(
			$table,
			array( $column => current_time( 'mysql' ) ),
			array( 'id' => absint( $referral_id ) )
		);
	}

	/**
	 * Track for referred user.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $event_type Event type.
	 */
	private static function track_user( $user_id, $event_type ) {
		$referral = LNKup_Connector::get_referral_for_user( $user_id );
		if ( ! $referral ) {
			return;
		}
		$connector_id = (int) get_user_meta( $user_id, '_lnkup_referred_by_connector_id', true );
		if ( ! $connector_id ) {
			$code_row = LNKup_Connector::get_code_for_connector( 0 );
			unset( $code_row );
			global $wpdb;
			$codes = LNKup_Treasury_Tables::referral_codes_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$code_row = $wpdb->get_row(
				$wpdb->prepare( "SELECT connector_id FROM {$codes} WHERE id = %d", (int) $referral['referral_code_id'] ),
				ARRAY_A
			);
			$connector_id = (int) ( $code_row['connector_id'] ?? 0 );
		}
		LNKup_Connector::record_event( $connector_id, (int) $referral['id'], $user_id, $event_type );
		return $referral;
	}

	/**
	 * Vendor profile saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function on_vendor_profile_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		$user_id = (int) get_post_meta( $post_id, '_lnkup_vendor_user_id', true );
		if ( ! $user_id ) {
			return;
		}
		$referral = self::track_user( $user_id, 'profile_created' );
		if ( ! $referral ) {
			return;
		}
		if ( class_exists( 'LNKup_Verification' ) ) {
			$status = LNKup_Verification::get_vendor_verification_status( $post_id );
			$level  = $status['level'] ?? 'basic_vendor';
			if ( in_array( $level, array( 'profile_complete', 'documents_ready', 'lnkup_verified', 'top_rated_vendor' ), true ) ) {
				self::touch_milestone( (int) $referral['id'], 'profile_completed_at' );
				self::track_user( $user_id, 'profile_completed' );
			}
		}
	}

	/**
	 * Application submitted.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function on_application_submitted( $app_id ) {
		$user_id = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		if ( ! $user_id ) {
			return;
		}
		$referral = self::track_user( $user_id, 'applied' );
		if ( $referral ) {
			self::touch_milestone( (int) $referral['id'], 'first_application_at' );
		}
	}

	/**
	 * Application approved.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function on_application_approved( $app_id ) {
		$user_id = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		if ( $user_id ) {
			self::track_user( $user_id, 'approved' );
		}
	}

	/**
	 * Payment completed — attribute revenue.
	 *
	 * @param int $payment_id Payment ID.
	 */
	public static function on_payment_completed( $payment_id ) {
		$vendor_id = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		$organizer_id = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$lnkup_fee = (float) get_post_meta( $payment_id, '_lnkup_lnkup_fee', true );

		foreach ( array_filter( array( $vendor_id, $organizer_id ) ) as $uid ) {
			$referral = self::track_user( (int) $uid, 'paid' );
			if ( ! $referral ) {
				continue;
			}
			self::touch_milestone( (int) $referral['id'], 'first_payment_at' );
			if ( $lnkup_fee > 0 ) {
				global $wpdb;
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . LNKup_Treasury_Tables::referrals_table() . ' SET attributed_revenue = attributed_revenue + %f WHERE id = %d',
						$lnkup_fee,
						(int) $referral['id']
					)
				);
			}
			LNKup_Connector_Rewards::maybe_queue_paid_booking_reward(
				(int) get_user_meta( $uid, '_lnkup_referred_by_connector_id', true ),
				(int) $referral['id'],
				$payment_id
			);
		}
	}

	/**
	 * Event created by organizer.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function on_event_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		$user_id = (int) get_post_meta( $post_id, '_lnkup_organizer_user_id', true );
		if ( ! $user_id ) {
			$user_id = (int) $post->post_author;
		}
		$referral = self::track_user( $user_id, 'event_created' );
		if ( $referral ) {
			self::touch_milestone( (int) $referral['id'], 'first_event_at' );
		}
	}

	/**
	 * Opportunity created.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function on_opportunity_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		$event_id = (int) get_post_meta( $post_id, '_lnkup_event_id', true );
		$user_id  = $event_id ? (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ) : (int) $post->post_author;
		$referral = self::track_user( $user_id, 'opportunity_created' );
		if ( $referral ) {
			self::touch_milestone( (int) $referral['id'], 'first_opportunity_at' );
			LNKup_Connector_Rewards::maybe_queue_organizer_opportunity_reward(
				(int) get_user_meta( $user_id, '_lnkup_referred_by_connector_id', true ),
				(int) $referral['id']
			);
		}
	}
}
