<?php
/**
 * Waitlist order and time-boxed offers.
 *
 * An offer is an approval with a payment deadline: the application moves waitlisted →
 * approved → payment_required through LNKup_Applications::transition(), which checks
 * capacity under the opportunity inventory lock, so offers hold a spot exactly like any
 * other approval and can never oversell. Nothing is charged automatically. An unpaid
 * offer that passes its deadline is voided and the vendor returns to the waitlist.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Waitlist_Offers {

	const POSITION_META     = '_lnkup_waitlist_position';
	const EXPIRY_CRON       = 'lnkup_waitlist_offers_expire';
	const DEFAULT_HOURS     = 48;
	const MIN_HOURS         = 2;
	const MAX_HOURS         = 168;
	const EXPIRY_BATCH      = 100;
	const POSITION_CLEAR_ON = array( 'paid', 'completed', 'rejected', 'cancelled' );

	public static function init() {
		add_action( 'lnkup_application_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 3 );
		add_action( self::EXPIRY_CRON, array( __CLASS__, 'expire_due_offers' ) );
		add_action( 'init', array( __CLASS__, 'schedule_cron' ), 20 );
	}

	/**
	 * Scheduled whether or not the flag is on, so offers made before it was turned off still expire.
	 */
	public static function schedule_cron() {
		if ( LNKup_Organizer_OS_Tables::ready() && ! wp_next_scheduled( self::EXPIRY_CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::EXPIRY_CRON );
		}
	}

	/**
	 * Keep waitlist order and offer rows in step with canonical status changes.
	 *
	 * @param int    $app_id Application.
	 * @param string $prev   Previous status.
	 * @param string $next   New status.
	 */
	public static function on_status_changed( $app_id, $prev, $next ) {
		$app_id = absint( $app_id );
		if ( 'waitlisted' === $next && '' === (string) get_post_meta( $app_id, self::POSITION_META, true ) ) {
			update_post_meta( $app_id, self::POSITION_META, self::next_position( (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true ) ) );
		}
		if ( in_array( $next, self::POSITION_CLEAR_ON, true ) ) {
			delete_post_meta( $app_id, self::POSITION_META );
		}
		$offer = self::active_offer( $app_id );
		if ( ! $offer ) {
			return;
		}
		if ( in_array( $next, array( 'paid', 'completed' ), true ) ) {
			self::resolve( (int) $offer['id'], 'accepted' );
		} elseif ( in_array( $next, array( 'rejected', 'cancelled' ), true ) ) {
			self::resolve( (int) $offer['id'], 'declined' );
		}
	}

	/**
	 * @param int $opp_id Opportunity.
	 */
	private static function next_position( $opp_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$max = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(CAST(pos.meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} pos
				INNER JOIN {$wpdb->postmeta} o ON o.post_id = pos.post_id AND o.meta_key = '_lnkup_opportunity_id' AND o.meta_value = %s
				WHERE pos.meta_key = %s",
				(string) absint( $opp_id ),
				self::POSITION_META
			)
		);
		return $max + 1;
	}

	/**
	 * Waitlisted applications for an opportunity, in queue order.
	 *
	 * @param int $opp_id Opportunity.
	 * @return array<int, array<string, mixed>>
	 */
	public static function waitlist( $opp_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => 'any',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_opportunity_id', 'value' => (string) absint( $opp_id ) ),
					array( 'key' => '_lnkup_application_status', 'value' => 'waitlisted' ),
				),
			)
		);
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
		}
		$rows = array();
		foreach ( $ids as $app_id ) {
			$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
			$rows[]     = array(
				'id'          => (int) $app_id,
				'position'    => (int) get_post_meta( $app_id, self::POSITION_META, true ),
				'vendor_name' => $profile_id ? ( (string) get_post_meta( $profile_id, '_lnkup_business_name', true ) ?: get_the_title( $profile_id ) ) : '#' . $app_id,
				'category'    => (string) get_post_meta( $app_id, '_lnkup_selected_category', true ),
				'added_at'    => (string) get_post_meta( $app_id, '_lnkup_decided_at', true ),
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				$pa = $a['position'] ?: PHP_INT_MAX;
				$pb = $b['position'] ?: PHP_INT_MAX;
				return $pa === $pb ? $a['id'] <=> $b['id'] : $pa <=> $pb;
			}
		);
		return $rows;
	}

	/**
	 * Organizer sets the waitlist order for one opportunity.
	 *
	 * @param int   $opp_id  Opportunity.
	 * @param int[] $ordered Application IDs, first = next in line.
	 * @param int   $user_id Actor.
	 * @return array|WP_Error Updated waitlist.
	 */
	public static function reorder( $opp_id, array $ordered, $user_id ) {
		$opp_id   = absint( $opp_id );
		$event_id = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
		if ( 'lnkup_opportunity' !== get_post_type( $opp_id ) || ! $event_id || ! LNKup_Capabilities::staff_can( $event_id, 'manage_applications', $user_id ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You cannot change this waitlist.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$current = wp_list_pluck( self::waitlist( $opp_id ), 'id' );
		$ordered = array_values( array_unique( array_map( 'absint', $ordered ) ) );
		if ( array_diff( $ordered, $current ) ) {
			return new WP_Error( 'lnkup_waitlist_changed', __( 'The waitlist changed since you loaded it. Refresh and try again.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$final = array_merge( $ordered, array_values( array_diff( $current, $ordered ) ) );
		foreach ( $final as $i => $app_id ) {
			update_post_meta( $app_id, self::POSITION_META, $i + 1 );
		}
		LNKup_Application_Review::audit( 'waitlist_reordered', $event_id, $user_id, array( 'opportunity_id' => $opp_id, 'order' => $final ) );
		return self::waitlist( $opp_id );
	}

	/**
	 * Open offer for an application, if any.
	 *
	 * @param int $app_id Application.
	 * @return array|null
	 */
	public static function active_offer( $app_id ) {
		global $wpdb;
		if ( ! LNKup_Organizer_OS_Tables::ready() ) {
			return null;
		}
		$table = LNKup_Organizer_OS_Tables::waitlist_offers_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE application_id = %d AND status = 'offered' ORDER BY id DESC LIMIT 1", absint( $app_id ) ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'         => (int) $row['id'],
			'expires_at' => (string) $row['expires_at'],
			'offered_by' => LNKup_Application_Review::user_label( (int) $row['offered_by'] )['name'],
			'created_at' => (string) $row['created_at'],
		);
	}

	/**
	 * Offer an open spot to a waitlisted vendor.
	 *
	 * @param int $app_id  Waitlisted application.
	 * @param int $user_id Organizer (manage_applications on the event).
	 * @param int $hours   Hours the vendor has to pay.
	 * @return array|WP_Error Offer.
	 */
	public static function make_offer( $app_id, $user_id, $hours = self::DEFAULT_HOURS ) {
		global $wpdb;
		$app_id = absint( $app_id );
		if ( ! LNKup_Feature_Flags::waitlist_offers() ) {
			return new WP_Error( 'lnkup_feature_off', __( 'Waitlist offers are not turned on.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		if ( ! LNKup_Capabilities::can_on_application( $app_id, 'manage_applications', $user_id ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You cannot make offers for this event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		if ( 'waitlisted' !== (string) get_post_meta( $app_id, '_lnkup_application_status', true ) ) {
			return new WP_Error( 'lnkup_not_waitlisted', __( 'Only waitlisted vendors can be offered a spot.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$hours  = max( self::MIN_HOURS, min( self::MAX_HOURS, absint( $hours ) ?: self::DEFAULT_HOURS ) );
		$opp_id = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );

		$expires = gmdate( 'Y-m-d H:i:s', time() + $hours * HOUR_IN_SECONDS );
		// Capacity is checked inside transition() under the inventory lock; a full slot returns 409.
		$result = LNKup_Applications::transition( $app_id, 'approved', array( 'source' => 'waitlist_offer', 'payment_due_at' => $expires ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			LNKup_Organizer_OS_Tables::waitlist_offers_table(),
			array(
				'application_id' => $app_id,
				'opportunity_id' => $opp_id,
				'event_id'       => (int) get_post_meta( $app_id, '_lnkup_event_id', true ),
				'status'         => 'offered',
				'offered_by'     => absint( $user_id ),
				'expires_at'     => $expires,
				'created_at'     => current_time( 'mysql', true ),
			)
		);
		$offer = self::active_offer( $app_id );
		self::notify_vendor_offer( $app_id, $expires );
		LNKup_Application_Review::audit( 'waitlist_offer_created', (int) get_post_meta( $app_id, '_lnkup_event_id', true ), $user_id, array( 'application_id' => $app_id, 'expires_at' => $expires ) );
		do_action( 'lnkup_waitlist_offer_created', $app_id, $expires, absint( $user_id ) );
		return $offer ? $offer : array( 'expires_at' => $expires );
	}

	/**
	 * Organizer withdraws an unpaid offer; the vendor returns to the waitlist.
	 *
	 * @param int $app_id  Application.
	 * @param int $user_id Actor.
	 * @return true|WP_Error
	 */
	public static function revoke_offer( $app_id, $user_id ) {
		if ( ! LNKup_Capabilities::can_on_application( $app_id, 'manage_applications', $user_id ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You cannot change offers for this event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$offer = self::active_offer( $app_id );
		if ( ! $offer ) {
			return new WP_Error( 'lnkup_no_offer', __( 'There is no open offer for this vendor.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$result = self::close_unpaid( absint( $app_id ), (int) $offer['id'], 'revoked' );
		if ( true === $result ) {
			LNKup_Application_Review::audit( 'waitlist_offer_revoked', (int) get_post_meta( $app_id, '_lnkup_event_id', true ), $user_id, array( 'application_id' => absint( $app_id ) ) );
		}
		return $result;
	}

	/**
	 * Hourly: return unpaid offers past their deadline to the waitlist.
	 *
	 * @return int Offers expired.
	 */
	public static function expire_due_offers() {
		global $wpdb;
		if ( ! LNKup_Organizer_OS_Tables::ready() ) {
			return 0;
		}
		$table = LNKup_Organizer_OS_Tables::waitlist_offers_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT id, application_id FROM {$table} WHERE status = 'offered' AND expires_at < %s ORDER BY expires_at ASC LIMIT %d", gmdate( 'Y-m-d H:i:s' ), self::EXPIRY_BATCH ), ARRAY_A );
		$expired = 0;
		foreach ( (array) $rows as $row ) {
			$app_id = (int) $row['application_id'];
			if ( true === self::close_unpaid( $app_id, (int) $row['id'], 'expired' ) ) {
				++$expired;
				LNKup_Application_Review::audit( 'waitlist_offer_expired', (int) get_post_meta( $app_id, '_lnkup_event_id', true ), 0, array( 'application_id' => $app_id ) );
				self::notify_organizer_expired( $app_id );
				do_action( 'lnkup_waitlist_offer_expired', $app_id );
			}
		}
		return $expired;
	}

	/**
	 * Void an unpaid offer and put the vendor back on the waitlist, under the inventory lock so
	 * a payment landing at the same moment is either booked first or refused by mark_paid().
	 *
	 * @param int    $app_id   Application.
	 * @param int    $offer_id Offer row.
	 * @param string $outcome  expired|revoked.
	 * @return true|WP_Error
	 */
	private static function close_unpaid( $app_id, $offer_id, $outcome ) {
		$opp_id = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		return LNKup_Opportunity_Inventory::with_lock(
			$opp_id,
			static function () use ( $app_id, $offer_id, $outcome ) {
				$status = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
				if ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
					self::resolve( $offer_id, 'accepted' );
					return new WP_Error( 'lnkup_offer_paid', __( 'The vendor already paid for this spot.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
				}
				if ( 'payment_required' !== $status ) {
					self::resolve( $offer_id, 'declined' );
					return new WP_Error( 'lnkup_offer_closed', __( 'This offer is no longer open.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
				}
				$payment = LNKup_Payments::get_for_application( $app_id );
				$pay     = $payment ? (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true ) : '';
				if ( in_array( $pay, array( 'processing', 'partially_paid' ), true ) ) {
					// Money is in flight: keep the spot until the payment settles or fails.
					return new WP_Error( 'lnkup_offer_payment_in_progress', __( 'A payment for this offer is in progress.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
				}
				$result = LNKup_Applications::return_to_waitlist( $app_id, 'expired' === $outcome ? 'offer_expired' : 'offer_revoked' );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				self::resolve( $offer_id, $outcome );
				return true;
			}
		);
	}

	/**
	 * @param int    $offer_id Offer row.
	 * @param string $status   accepted|expired|revoked|declined.
	 */
	private static function resolve( $offer_id, $status ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			LNKup_Organizer_OS_Tables::waitlist_offers_table(),
			array( 'status' => sanitize_key( $status ), 'resolved_at' => current_time( 'mysql', true ) ),
			array( 'id' => absint( $offer_id ), 'status' => 'offered' )
		);
	}

	/**
	 * @param int    $app_id  Application.
	 * @param string $expires UTC deadline.
	 */
	private static function notify_vendor_offer( $app_id, $expires ) {
		$vendor = get_userdata( (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ) );
		if ( ! $vendor || ! class_exists( 'LNKup_Notifications' ) ) {
			return;
		}
		$event    = get_the_title( (int) get_post_meta( $app_id, '_lnkup_event_id', true ) );
		$deadline = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $expires . ' UTC' ) );
		LNKup_Notifications::send(
			$vendor->user_email,
			/* translators: %s: event name */
			sprintf( __( 'A spot opened up at %s', 'lnkup-marketplace' ), $event ),
			wpautop(
				esc_html(
					sprintf(
						/* translators: 1: event, 2: deadline */
						__( 'Good news: a vendor spot opened at %1$s and the organizer is offering it to you. Complete payment by %2$s to confirm it. If you do not pay by then, the spot goes back to the waitlist and you keep your place in line.', 'lnkup-marketplace' ),
						$event,
						$deadline
					)
				)
			) . '<p><a href="' . esc_url( lnkup_page_url_helper( 'vendor-dashboard' ) ) . '">' . esc_html__( 'Review and pay', 'lnkup-marketplace' ) . '</a></p>'
		);
	}

	/**
	 * @param int $app_id Application.
	 */
	private static function notify_organizer_expired( $app_id ) {
		$organizer = get_userdata( (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true ) );
		if ( ! $organizer || ! class_exists( 'LNKup_Notifications' ) ) {
			return;
		}
		$event = get_the_title( (int) get_post_meta( $app_id, '_lnkup_event_id', true ) );
		LNKup_Notifications::send(
			$organizer->user_email,
			/* translators: %s: event name */
			sprintf( __( 'Waitlist offer expired for %s', 'lnkup-marketplace' ), $event ),
			wpautop( esc_html__( 'A vendor did not pay before their waitlist offer deadline, so they are back on the waitlist and the spot is open again. You can offer it to the next vendor in line.', 'lnkup-marketplace' ) )
				. '<p><a href="' . esc_url( add_query_arg( 'group', 'waitlisted', lnkup_organizer_view_url( 'applications' ) ) ) . '">' . esc_html__( 'Open the waitlist', 'lnkup-marketplace' ) . '</a></p>'
		);
	}
}
