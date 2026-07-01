<?php
/**
 * Revenue intelligence aggregates.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Revenue_Intelligence {

	public static function marketplace_summary() {
		$cached = get_transient( 'lnkup_intel_revenue_market' );
		if ( false !== $cached ) {
			return $cached;
		}
		if ( class_exists( 'LNKup_Financial_Reports' ) ) {
			$data = LNKup_Financial_Reports::marketplace_summary();
			$cached = array(
				'gmv'             => $data['gmv'] ?? 0,
				'lnkup_fees'      => $data['platform_revenue'] ?? 0,
				'organizer_fees'  => $data['organizer_revenue'] ?? 0,
				'paid_bookings'   => $data['paid_bookings'] ?? 0,
				'avg_fee'         => $data['avg_vendor_fee'] ?? 0,
				'refunds'         => $data['refund_volume'] ?? 0,
				'disputes'        => $data['dispute_count'] ?? 0,
			);
			set_transient( 'lnkup_intel_revenue_market', $cached, HOUR_IN_SECONDS );
			return $cached;
		}
		$payments = get_posts(
			array(
				'post_type'      => 'lnkup_payment',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_payment_status',
				'meta_value'     => 'paid',
			)
		);
		$gmv = 0;
		$lnkup_fees = 0;
		$organizer_fees = 0;
		$count = 0;
		foreach ( $payments as $pid ) {
			$gmv += (float) get_post_meta( $pid, '_lnkup_total_amount', true );
			$lnkup_fees += (float) get_post_meta( $pid, '_lnkup_lnkup_fee', true );
			$organizer_fees += (float) get_post_meta( $pid, '_lnkup_vendor_spot_fee', true );
			$count++;
		}
		$data = array(
			'gmv'             => round( $gmv, 2 ),
			'lnkup_fees'      => round( $lnkup_fees, 2 ),
			'organizer_fees'  => round( $organizer_fees, 2 ),
			'paid_bookings'   => $count,
			'avg_fee'         => $count ? round( $organizer_fees / $count, 2 ) : 0,
			'refunds'         => 0,
			'disputes'        => 0,
		);
		set_transient( 'lnkup_intel_revenue_market', $data, HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Revenue for single event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function for_event( $event_id ) {
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => absint( $event_id ),
			)
		);
		$vendor_revenue = 0;
		$lnkup_revenue = 0;
		$paid = 0;
		foreach ( $apps as $app_id ) {
			if ( ! in_array( get_post_meta( $app_id, '_lnkup_application_status', true ), array( 'paid', 'completed' ), true ) ) {
				continue;
			}
			$payments = get_posts(
				array(
					'post_type'      => 'lnkup_payment',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_lnkup_application_id',
					'meta_value'     => $app_id,
				)
			);
			if ( ! $payments ) {
				continue;
			}
			$paid++;
			$vendor_revenue += (float) get_post_meta( $payments[0], '_lnkup_vendor_spot_fee', true );
			$lnkup_revenue += (float) get_post_meta( $payments[0], '_lnkup_lnkup_fee', true );
		}
		return array(
			'vendor_fee_revenue' => round( $vendor_revenue, 2 ),
			'lnkup_fee_revenue'  => round( $lnkup_revenue, 2 ),
			'total_revenue'      => round( $vendor_revenue + $lnkup_revenue, 2 ),
			'paid_vendors'       => $paid,
			'avg_vendor_fee'     => $paid ? round( $vendor_revenue / $paid, 2 ) : 0,
		);
	}

	/**
	 * Scoped revenue with filters.
	 *
	 * @param array $args Filters.
	 */
	public static function query( $args = array() ) {
		$args = wp_parse_args( $args, array( 'organizer_id' => 0, 'event_id' => 0 ) );
		if ( $args['event_id'] ) {
			return self::for_event( $args['event_id'] );
		}
		if ( $args['organizer_id'] ) {
			return self::for_organizer( $args['organizer_id'] );
		}
		return self::marketplace_summary();
	}

	public static function for_organizer( $organizer_id ) {
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => absint( $organizer_id ),
			)
		);
		$total = array(
			'vendor_fee_revenue' => 0,
			'lnkup_fee_revenue'  => 0,
			'total_revenue'      => 0,
			'paid_vendors'       => 0,
		);
		foreach ( $events as $event_id ) {
			$row = self::for_event( $event_id );
			$total['vendor_fee_revenue'] += $row['vendor_fee_revenue'];
			$total['lnkup_fee_revenue'] += $row['lnkup_fee_revenue'];
			$total['total_revenue'] += $row['total_revenue'];
			$total['paid_vendors'] += $row['paid_vendors'];
		}
		$total['vendor_fee_revenue'] = round( $total['vendor_fee_revenue'], 2 );
		$total['lnkup_fee_revenue'] = round( $total['lnkup_fee_revenue'], 2 );
		$total['total_revenue'] = round( $total['total_revenue'], 2 );
		return $total;
	}
}
