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
		// Conditional write: only the request that sets the milestone first announces it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$column} = %s WHERE id = %d AND {$column} IS NULL", current_time( 'mysql' ), absint( $referral_id ) ) );
		if ( 1 === (int) $updated && in_array( $column, array( 'first_event_at', 'first_payment_at' ), true ) ) {
			do_action( 'lnkup_partner_referral_milestone', absint( $referral_id ), $column );
		}
	}

	/**
	 * Resolve connector program attribution for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array|null { user_id, referral, connector_id } or null.
	 */
	private static function attribution_for_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return null;
		}
		$referral = LNKup_Connector::get_referral_for_user( $user_id );
		if ( ! $referral ) {
			return null;
		}
		$connector_id = (int) get_user_meta( $user_id, '_lnkup_referred_by_connector_id', true );
		if ( ! $connector_id && ! empty( $referral['referral_code_id'] ) ) {
			global $wpdb;
			$codes = LNKup_Treasury_Tables::referral_codes_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$connector_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT connector_id FROM {$codes} WHERE id = %d", (int) $referral['referral_code_id'] )
			);
		}
		if ( ! $connector_id ) {
			return null;
		}
		return array(
			'user_id'      => $user_id,
			'referral'     => $referral,
			'connector_id' => $connector_id,
		);
	}

	/**
	 * Track for referred user.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $event_type Event type.
	 */
	private static function track_user( $user_id, $event_type ) {
		$attr = self::attribution_for_user( $user_id );
		if ( ! $attr ) {
			return;
		}
		LNKup_Connector::record_event( (int) $attr['connector_id'], (int) $attr['referral']['id'], (int) $attr['user_id'], $event_type );
		return $attr['referral'];
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
	 * Payment completed — attribute booth GMV once (organizer-first).
	 *
	 * Organizer-referred connector wins. If the organizer was not referred, fall
	 * back to the vendor-referred connector. Same connector on both sides pays once.
	 *
	 * @param int $payment_id Payment ID.
	 */
	public static function on_payment_completed( $payment_id ) {
		$payment_id   = absint( $payment_id );
		$vendor_id    = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		$organizer_id = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$booth_gmv    = LNKup_Connector_Rewards::booth_gmv( $payment_id );

		$vendor_attr = self::attribution_for_user( $vendor_id );
		$org_attr    = self::attribution_for_user( $organizer_id );

		foreach ( array_filter( array( $vendor_id, $organizer_id ) ) as $uid ) {
			$referral = self::track_user( (int) $uid, 'paid' );
			if ( $referral ) {
				self::touch_milestone( (int) $referral['id'], 'first_payment_at' );
			}
		}

		$winner = $org_attr ? $org_attr : $vendor_attr;
		if ( ! $winner ) {
			return;
		}

		if ( $booth_gmv > 0 ) {
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . LNKup_Treasury_Tables::referrals_table() . ' SET attributed_revenue = attributed_revenue + %f WHERE id = %d',
					$booth_gmv,
					(int) $winner['referral']['id']
				)
			);
		}

		$is_organizer = (bool) $org_attr && (int) $winner['user_id'] === (int) $org_attr['user_id'];
		LNKup_Connector_Rewards::maybe_queue_paid_booking_reward(
			(int) $winner['connector_id'],
			(int) $winner['referral']['id'],
			$payment_id,
			$is_organizer
		);
		if ( class_exists( 'LNKup_Connector_Ranks' ) ) {
			LNKup_Connector_Ranks::record_payment(
				$payment_id,
				(int) $winner['connector_id'],
				(int) $winner['referral']['id'],
				$is_organizer ? 'organizer' : 'vendor'
			);
			LNKup_Connector_Ranks::recalculate( (int) $winner['connector_id'], 'payment' );
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
			$cid = (int) get_user_meta( $user_id, '_lnkup_referred_by_connector_id', true );
			if ( $cid && class_exists( 'LNKup_Connector_Ranks' ) ) {
				LNKup_Connector_Ranks::recalculate( $cid, 'event' );
			}
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
			$cid = (int) get_user_meta( $user_id, '_lnkup_referred_by_connector_id', true );
			LNKup_Connector_Rewards::maybe_queue_organizer_opportunity_reward( $cid, (int) $referral['id'] );
			if ( $cid && class_exists( 'LNKup_Connector_Ranks' ) ) {
				LNKup_Connector_Ranks::recalculate( $cid, 'opportunity' );
			}
		}
	}
}
