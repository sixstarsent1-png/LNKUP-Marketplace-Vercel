<?php
/**
 * Organizer relationship insights for vendors.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Growth_Relationships {

	public static function for_vendor( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		$apps    = lnkup_get_applications_for_user( $user_id, 'vendor' );
		$by_org  = array();
		foreach ( $apps as $app ) {
			$oid = (int) get_post_meta( $app->ID, '_lnkup_organizer_user_id', true );
			if ( ! $oid ) {
				continue;
			}
			if ( ! isset( $by_org[ $oid ] ) ) {
				$user = get_userdata( $oid );
				$by_org[ $oid ] = array(
					'organizer_id'   => $oid,
					'name'           => $user ? $user->display_name : __( 'Organizer', 'lnkup-marketplace' ),
					'bookings'       => 0,
					'revenue'        => 0,
					'first_booking'  => null,
					'last_booking'   => null,
					'is_favorite'    => false,
					'invites'        => 0,
				);
			}
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
				$by_org[ $oid ]['bookings']++;
				$meta = LNKup_Vendor_Dashboard_Data::application_event_meta( $app );
				$by_org[ $oid ]['revenue'] += (float) $meta['fee'];
				$date = get_post_meta( $app->ID, '_lnkup_submitted_at', true ) ?: $app->post_date;
				if ( ! $by_org[ $oid ]['first_booking'] || strtotime( $date ) < strtotime( $by_org[ $oid ]['first_booking'] ) ) {
					$by_org[ $oid ]['first_booking'] = $date;
				}
				if ( ! $by_org[ $oid ]['last_booking'] || strtotime( $date ) > strtotime( $by_org[ $oid ]['last_booking'] ) ) {
					$by_org[ $oid ]['last_booking'] = $date;
				}
			}
		}
		global $wpdb;
		$rel_table = LNKup_Organizer_CRM_Tables::rel_table();
		$favorites = $wpdb->get_results(
			$wpdb->prepare( "SELECT organizer_user_id FROM {$rel_table} WHERE vendor_user_id = %d AND is_favorite = 1", $user_id ),
			ARRAY_A
		);
		foreach ( $favorites as $f ) {
			$oid = (int) $f['organizer_user_id'];
			if ( isset( $by_org[ $oid ] ) ) {
				$by_org[ $oid ]['is_favorite'] = true;
			}
		}
		$orgs = array_values( $by_org );
		usort( $orgs, function ( $a, $b ) { return $b['revenue'] <=> $a['revenue']; } );
		$repeat = array_filter( $orgs, function ( $o ) { return $o['bookings'] > 1; } );
		return array(
			'top_organizers'      => array_slice( $orgs, 0, 10 ),
			'repeat_bookings'     => count( $repeat ),
			'favorite_organizers' => array_values( array_filter( $orgs, function ( $o ) { return $o['is_favorite']; } ) ),
			'longest_relationship'=> self::longest( $orgs ),
			'revenue_by_organizer'=> array_slice( $orgs, 0, 8 ),
		);
	}

	public static function top_organizer( $user_id ) {
		$data = self::for_vendor( $user_id );
		return ! empty( $data['top_organizers'][0] ) ? $data['top_organizers'][0]['name'] : '';
	}

	private static function longest( $orgs ) {
		$longest = null;
		$max_days = 0;
		foreach ( $orgs as $o ) {
			if ( ! $o['first_booking'] ) {
				continue;
			}
			$days = (int) ( ( time() - strtotime( $o['first_booking'] ) ) / DAY_IN_SECONDS );
			if ( $days > $max_days ) {
				$max_days = $days;
				$longest = $o;
			}
		}
		return $longest;
	}
}
