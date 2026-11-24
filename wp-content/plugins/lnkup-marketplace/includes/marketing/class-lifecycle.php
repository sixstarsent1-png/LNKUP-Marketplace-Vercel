<?php
/**
 * Lifecycle messages and consent records for the Marketing OS (delivery stays in Communications).
 *
 * - v_market_alert: when an opportunity is published, vendors with a market alert (a saved search
 *   created from a market page) that matches it get one message per opportunity, however many of
 *   their alerts match (the comms dedupe key is trigger + recipient + opportunity). The legacy
 *   instant saved-search alert for the same search and opportunity is marked as sent first, so
 *   it never duplicates. Vendors who already applied are skipped.
 * - v_followed_tour_new_stop: when a published, upcoming event joins a tour (or a tour stop is
 *   published), followers of the tour get one message per stop.
 * - Marketing consent: `_lnkup_marketing_consent` records state, source and time whenever consent
 *   for promotional categories changes (registration default under the documented US opt-out
 *   model, preferences, unsubscribe links, explicit market alert opt-in). History keeps 20 entries.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Lifecycle {

	const ALERTS_META  = '_lnkup_market_alerts';
	const CONSENT_META = '_lnkup_marketing_consent';

	public static function init(): void {
		add_action( 'publish_lnkup_opportunity', array( __CLASS__, 'on_opportunity_published' ), 15, 2 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_tour_meta' ), 20, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_tour_meta' ), 20, 4 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_event_status' ), 20, 3 );
		add_action( 'lnkup_user_registered', array( __CLASS__, 'on_registered' ), 30, 2 );
		add_action( 'lnkup_comms_preferences_updated', array( __CLASS__, 'on_preferences' ), 10, 2 );
		add_action( 'lnkup_comms_unsubscribed', array( __CLASS__, 'on_unsubscribed' ), 10, 2 );
	}

	/* ------------------------------------------------------------ Market alerts */

	/**
	 * Remember that a saved search is a market alert.
	 */
	public static function register_market_alert( int $user_id, string $search_id, string $market, string $category ): void {
		$alerts               = self::alerts( $user_id );
		$alerts[ $search_id ] = array( 'market' => sanitize_title( $market ), 'category' => sanitize_text_field( $category ), 'created_at' => time() );
		update_user_meta( $user_id, self::ALERTS_META, $alerts );
		self::record_consent( $user_id, 'granted', 'market_alert' );
	}

	/**
	 * @return array<string,array<string,mixed>> search_id => alert.
	 */
	public static function alerts( int $user_id ): array {
		$alerts = get_user_meta( $user_id, self::ALERTS_META, true );
		return is_array( $alerts ) ? $alerts : array();
	}

	/**
	 * @param int          $post_id Opportunity.
	 * @param WP_Post|null $post    Post.
	 */
	public static function on_opportunity_published( $post_id, $post = null ): int {
		global $wpdb;
		$opp_id = (int) $post_id;
		if ( ! class_exists( 'LNKup_Comms_Engine' ) || ! class_exists( 'LNKup_Saved_Searches' ) || ! class_exists( 'LNKup_Marketplace_Index' ) ) {
			return 0;
		}
		$row = LNKup_Marketplace_Index::get_row( $opp_id );
		if ( ! $row ) {
			LNKup_Marketplace_Index::sync_opportunity( $opp_id );
			$row = LNKup_Marketplace_Index::get_row( $opp_id );
		}
		if ( ! $row || (int) ( $row['spots_remaining'] ?? 0 ) < 1 ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$users  = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", self::ALERTS_META ) ) );
		if ( ! $users ) {
			return 0;
		}
		// Active, alert-enabled searches (normalized rows; nothing is executed).
		$active = array();
		foreach ( LNKup_Saved_Searches::get_alert_candidates( null, $users ) as $entry ) {
			$active[ (int) $entry['user_id'] ][ (string) $entry['search']['id'] ] = $entry['search'];
		}
		$queued = 0;
		foreach ( $users as $user_id ) {
			$matched = array();
			foreach ( self::alerts( $user_id ) as $search_id => $alert ) {
				$search = $active[ $user_id ][ (string) $search_id ] ?? null;
				if ( is_array( $search ) && LNKup_Discovery::matches_filters( (array) ( $search['filters'] ?? array() ), $row ) ) {
					$matched[ (string) $search_id ] = (array) $alert;
				}
			}
			if ( ! $matched || LNKup_Saved_Searches::vendor_has_application( $user_id, $opp_id ) || ( function_exists( 'lnkup_vendor_can_discover_opportunity' ) && ! lnkup_vendor_can_discover_opportunity( $user_id, $opp_id ) ) ) {
				continue;
			}
			foreach ( array_keys( $matched ) as $search_id ) {
				LNKup_Saved_Searches::mark_instant_alert_sent( $user_id, $search_id, $opp_id );
			}
			$first  = reset( $matched );
			$market = class_exists( 'LNKup_Market_Pages' ) ? LNKup_Market_Pages::get_market_by_slug( (string) $first['market'], false ) : null;
			$sent   = LNKup_Comms_Engine::dispatch(
				'v_market_alert',
				$user_id,
				array( 'opportunity_id' => $opp_id, 'event_id' => (int) $row['event_id'], 'vendor_user_id' => $user_id, 'market_name' => $market ? (string) $market['display_name'] : (string) $row['city'] ),
				// The comms dedupe object prefers event_id; the window keeps one message per opportunity.
				array( 'window' => 'o' . $opp_id, 'source' => 'market_alert' )
			);
			$queued += $sent > 0 ? 1 : 0;
		}
		return $queued;
	}

	/* ------------------------------------------------------------ Followed tours */

	/**
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post.
	 * @param string $meta_key   Key.
	 * @param mixed  $meta_value Value.
	 */
	public static function on_tour_meta( $meta_id, $object_id, $meta_key, $meta_value ): void {
		if ( '_lnkup_tour_id' === $meta_key && 'lnkup_event' === get_post_type( (int) $object_id ) ) {
			self::notify_tour_stop( (int) $object_id, (int) $meta_value );
		}
	}

	/**
	 * @param string  $new  New status.
	 * @param string  $old  Old status.
	 * @param WP_Post $post Post.
	 */
	public static function on_event_status( $new, $old, $post ): void {
		if ( 'publish' === $new && 'publish' !== $old && $post instanceof WP_Post && 'lnkup_event' === $post->post_type ) {
			$tour = (int) get_post_meta( $post->ID, '_lnkup_tour_id', true );
			if ( $tour ) {
				self::notify_tour_stop( (int) $post->ID, $tour );
			}
		}
	}

	public static function notify_tour_stop( int $event_id, int $tour_id ): int {
		if ( $tour_id < 1 || ! class_exists( 'LNKup_Comms_Engine' ) || ! class_exists( 'LNKup_Event_Distribution' ) ) {
			return 0;
		}
		$date = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		if ( 'publish' !== get_post_status( $event_id ) || 'publish' !== get_post_status( $tour_id ) || ( '' !== $date && $date < current_time( 'Y-m-d' ) ) ) {
			return 0;
		}
		$queued = 0;
		foreach ( LNKup_Event_Distribution::tour_followers( $tour_id ) as $user_id ) {
			$sent = LNKup_Comms_Engine::dispatch(
				'v_followed_tour_new_stop',
				$user_id,
				array( 'event_id' => $event_id, 'tour_id' => $tour_id, 'vendor_user_id' => $user_id ),
				array( 'window' => 'tour' . $tour_id, 'source' => 'tour_follow' )
			);
			$queued += $sent > 0 ? 1 : 0;
		}
		return $queued;
	}

	/* ------------------------------------------------------------ Consent */

	/**
	 * Record a consent change for promotional communication.
	 *
	 * @param string $state granted|withdrawn.
	 */
	public static function record_consent( int $user_id, string $state, string $source ): void {
		if ( $user_id < 1 || ! in_array( $state, array( 'granted', 'withdrawn' ), true ) ) {
			return;
		}
		$record    = get_user_meta( $user_id, self::CONSENT_META, true );
		$record    = is_array( $record ) ? $record : array( 'history' => array() );
		$entry     = array( 'state' => $state, 'source' => sanitize_key( $source ), 'at' => time() );
		$history   = array_slice( array_merge( (array) ( $record['history'] ?? array() ), array( $entry ) ), -20 );
		update_user_meta( $user_id, self::CONSENT_META, array_merge( $entry, array( 'history' => $history ) ) );
	}

	/**
	 * @param int    $user_id User.
	 * @param string $role    Role.
	 */
	public static function on_registered( $user_id, $role = '' ): void {
		// US opt-out model (documented in the privacy notice): promotional email is on by default and
		// every message carries an unsubscribe link. Recorded so the source is auditable.
		self::record_consent( (int) $user_id, 'granted', 'registration_default' );
	}

	/**
	 * @param int   $user_id User.
	 * @param array $input   Applied input.
	 */
	public static function on_preferences( $user_id, $input ): void {
		if ( ! class_exists( 'LNKup_Comms_Registry' ) ) {
			return;
		}
		$promo = array();
		foreach ( LNKup_Comms_Registry::categories() as $key => $cat ) {
			if ( ! empty( $cat['promotional'] ) ) {
				$promo[] = $key;
			}
		}
		$on  = false;
		$off = false;
		foreach ( (array) ( $input['categories'] ?? array() ) as $row ) {
			if ( is_array( $row ) && in_array( (string) ( $row['key'] ?? '' ), $promo, true ) && isset( $row['email'] ) ) {
				'off' === (string) $row['email'] ? $off = true : $on = true;
			}
		}
		if ( $off || $on ) {
			self::record_consent( (int) $user_id, $off && ! $on ? 'withdrawn' : 'granted', 'preferences' );
		}
	}

	/**
	 * @param int    $user_id User.
	 * @param string $scope   marketing|category.
	 */
	public static function on_unsubscribed( $user_id, $scope ): void {
		self::record_consent( (int) $user_id, 'withdrawn', 'unsubscribe_link' );
	}
}
