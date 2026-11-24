<?php
/**
 * Revenue attribution: which acquisition sources produce real marketplace money.
 *
 * Built on the Financial OS ledger, not on analytics: one row per paid booking from
 * lnkup_platform_fees (de-duplicated per payment exactly like LNKup_Platform_Fees), joined to
 * - the Purchase conversion row for that payment (attribution_json: first / last non-direct touch),
 * - lnkup_refunds (money returned to vendors),
 * - lnkup_referral_rewards (referral / City Connector commissions, net of reversals),
 * - lnkup_org_growth_spend (manually entered ad spend, by channel or campaign).
 *
 * GMV, LNKup net platform revenue, refunds, commissions and spend are reported separately; a
 * $500 booth payment is $500 of GMV and only the platform-fee share is LNKup revenue. Payments
 * with no attribution are reported as "Unattributed". Test conversions are excluded.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Revenue_Attribution {

	const DIMENSIONS   = array( 'utm_source', 'utm_medium', 'utm_campaign', 'lnk', 'market', 'ref', 'partner', 'device' );
	const UNATTRIBUTED = 'Unattributed';

	/**
	 * @param string $from      Y-m-d.
	 * @param string $to        Y-m-d.
	 * @param string $dimension One of DIMENSIONS.
	 * @param string $model     last|first.
	 * @return array{rows:array<int,array<string,mixed>>,totals:array<string,float|int>,model:string,dimension:string,spend_matched:bool}
	 */
	public static function report( string $from, string $to, string $dimension = 'utm_source', string $model = 'last' ): array {
		global $wpdb;
		$dimension = in_array( $dimension, self::DIMENSIONS, true ) ? $dimension : 'utm_source';
		$model     = 'first' === $model ? 'first' : 'last';
		$fees      = $wpdb->prefix . 'lnkup_platform_fees';
		$conv      = class_exists( 'LNKup_Meta_Tables' ) ? LNKup_Meta_Tables::conversions() : $wpdb->prefix . 'lnkup_conversion_events';
		$refunds   = class_exists( 'LNKup_Financial_Tables' ) ? LNKup_Financial_Tables::refunds_table() : $wpdb->prefix . 'lnkup_refunds';
		$rewards   = class_exists( 'LNKup_Treasury_Tables' ) ? LNKup_Treasury_Tables::referral_rewards_table() : $wpdb->prefix . 'lnkup_referral_rewards';
		$fees      = class_exists( 'LNKup_Treasury_Tables' ) ? LNKup_Treasury_Tables::platform_fees_table() : $fees;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$payments = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.payment_post_id AS payment_id, f.gross_amount, f.lnkup_platform_fee, f.refunded_platform_fee,
					( SELECT c.attribution_json FROM {$conv} c WHERE c.event_name = 'Purchase' AND c.entity_type = 'payment' AND c.entity_id = f.payment_post_id AND c.is_test = 0 ORDER BY c.id ASC LIMIT 1 ) AS attribution_json
				FROM {$fees} f INNER JOIN ( SELECT MIN(id) AS id FROM {$fees} GROUP BY payment_post_id ) firsts ON firsts.id = f.id
				WHERE f.created_at BETWEEN %s AND %s",
				$from . ' 00:00:00',
				$to . ' 23:59:59'
			),
			ARRAY_A
		);
		$ids      = array_map( 'intval', wp_list_pluck( $payments, 'payment_id' ) );
		$refunded = array();
		$commission = array();
		if ( $ids ) {
			$in = implode( ',', array_map( 'absint', $ids ) );
			if ( self::table_exists( $refunds ) ) {
				foreach ( (array) $wpdb->get_results( "SELECT payment_post_id, SUM(amount) amt FROM {$refunds} WHERE payment_post_id IN ({$in}) AND status IN ('approved','completed') GROUP BY payment_post_id", ARRAY_A ) as $r ) {
					$refunded[ (int) $r['payment_post_id'] ] = (float) $r['amt'];
				}
			}
			if ( self::table_exists( $rewards ) ) {
				foreach ( (array) $wpdb->get_results( "SELECT payment_id, SUM(GREATEST(amount - reversed_amount, 0)) amt FROM {$rewards} WHERE payment_id IN ({$in}) AND status NOT IN ('void','rejected','cancelled','declined') GROUP BY payment_id", ARRAY_A ) as $r ) {
					$commission[ (int) $r['payment_id'] ] = (float) $r['amt'];
				}
			}
		}
		// phpcs:enable

		$rows = array();
		foreach ( $payments as $p ) {
			$key = self::key_for( (string) $p['attribution_json'], $dimension, $model );
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array( 'key' => $key, 'bookings' => 0, 'gmv' => 0.0, 'refunds' => 0.0, 'platform_fee_gross' => 0.0, 'platform_fee_net' => 0.0, 'commissions' => 0.0 );
			}
			$pid = (int) $p['payment_id'];
			++$rows[ $key ]['bookings'];
			$rows[ $key ]['gmv']                += (float) $p['gross_amount'];
			$rows[ $key ]['refunds']            += (float) ( $refunded[ $pid ] ?? 0 );
			$rows[ $key ]['platform_fee_gross'] += (float) $p['lnkup_platform_fee'];
			$rows[ $key ]['platform_fee_net']   += max( 0.0, (float) $p['lnkup_platform_fee'] - (float) $p['refunded_platform_fee'] );
			$rows[ $key ]['commissions']        += (float) ( $commission[ $pid ] ?? 0 );
		}

		$spend         = self::spend( $from, $to, $dimension );
		$spend_matched = null !== $spend;
		foreach ( (array) $spend as $key => $amount ) {
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array( 'key' => $key, 'bookings' => 0, 'gmv' => 0.0, 'refunds' => 0.0, 'platform_fee_gross' => 0.0, 'platform_fee_net' => 0.0, 'commissions' => 0.0 );
			}
			$rows[ $key ]['spend'] = $amount;
		}

		$totals = array( 'bookings' => 0, 'gmv' => 0.0, 'refunds' => 0.0, 'platform_fee_gross' => 0.0, 'platform_fee_net' => 0.0, 'commissions' => 0.0, 'spend' => 0.0, 'net_revenue' => 0.0 );
		foreach ( $rows as &$row ) {
			$row['spend']             = round( (float) ( $row['spend'] ?? 0 ), 2 );
			$row['net_revenue']       = round( $row['platform_fee_net'] - $row['commissions'], 2 );
			$row['platform_fee_roas'] = $row['spend'] > 0 ? round( $row['platform_fee_net'] / $row['spend'], 2 ) : null;
			$row['gmv_roas']          = $row['spend'] > 0 ? round( $row['gmv'] / $row['spend'], 2 ) : null;
			foreach ( array( 'gmv', 'refunds', 'platform_fee_gross', 'platform_fee_net', 'commissions' ) as $money ) {
				$row[ $money ] = round( (float) $row[ $money ], 2 );
			}
			foreach ( array_keys( $totals ) as $t ) {
				$totals[ $t ] += $row[ $t ];
			}
		}
		unset( $row );
		$totals = array_map( static fn( $v ) => is_float( $v ) ? round( $v, 2 ) : $v, $totals );
		usort( $rows, static fn( $a, $b ) => ( self::UNATTRIBUTED === $a['key'] ) <=> ( self::UNATTRIBUTED === $b['key'] ) ?: $b['platform_fee_net'] <=> $a['platform_fee_net'] );

		return array( 'rows' => array_values( $rows ), 'totals' => $totals, 'model' => $model, 'dimension' => $dimension, 'spend_matched' => $spend_matched );
	}

	/**
	 * Dimension value from a conversion's attribution snapshot.
	 */
	public static function key_for( string $json, string $dimension, string $model ): string {
		$attr  = json_decode( $json, true );
		$touch = is_array( $attr ) ? ( 'first' === $model ? ( $attr['ft'] ?? $attr['lt'] ?? array() ) : ( $attr['lt'] ?? $attr['ft'] ?? array() ) ) : array();
		$value = is_array( $touch ) ? trim( (string) ( $touch[ $dimension ] ?? '' ) ) : '';
		if ( '' === $value && 'utm_source' === $dimension && is_array( $touch ) && ! empty( $touch['referrer'] ) ) {
			$value = 'referral: ' . (string) $touch['referrer'];
		}
		return '' !== $value ? substr( $value, 0, 120 ) : self::UNATTRIBUTED;
	}

	/**
	 * Spend by dimension value: channel ↔ utm_source, campaign ↔ utm_campaign. Null when the
	 * dimension has no spend mapping (spend is then shown only in totals elsewhere).
	 *
	 * @return array<string,float>|null
	 */
	private static function spend( string $from, string $to, string $dimension ): ?array {
		global $wpdb;
		$col = 'utm_source' === $dimension ? 'channel' : ( 'utm_campaign' === $dimension ? 'campaign' : '' );
		if ( '' === $col ) {
			return null;
		}
		$table = $wpdb->prefix . 'lnkup_org_growth_spend';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$col} k, SUM(amount_cents) cents FROM {$table} WHERE voided_at IS NULL AND spent_on BETWEEN %s AND %s GROUP BY {$col}", $from, $to ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$key         = '' !== trim( (string) $r['k'] ) ? (string) $r['k'] : self::UNATTRIBUTED;
			$out[ $key ] = ( $out[ $key ] ?? 0 ) + round( (int) $r['cents'] / 100, 2 );
		}
		return $out;
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * CSV for the Command Center export.
	 *
	 * @param array<string,mixed> $report Report.
	 */
	public static function to_csv( array $report ): string {
		$cols = array( 'key', 'bookings', 'gmv', 'refunds', 'platform_fee_gross', 'platform_fee_net', 'commissions', 'net_revenue', 'spend', 'platform_fee_roas', 'gmv_roas' );
		$fh   = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $fh, $cols );
		foreach ( (array) $report['rows'] as $row ) {
			$line = array();
			foreach ( $cols as $c ) {
				$value  = $row[ $c ] ?? '';
				$line[] = is_string( $value ) && preg_match( '/^[=+\-@]/', $value ) ? "'" . $value : $value;
			}
			fputcsv( $fh, $line );
		}
		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $csv;
	}
}
