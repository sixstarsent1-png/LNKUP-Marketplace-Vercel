<?php
/**
 * Organizer acquisition costs: manually entered marketing spend plus commissions actually
 * earned for referring organizers.
 *
 * Spend rows are never deleted — they are voided with a reason and audited. Commission cost is
 * read-only from the reward ledger: approved or paid rewards, net of reversals, whose referral
 * brought in an organizer linked to an Organizer Growth lead. Vendor-referral rewards are not
 * organizer acquisition cost and are excluded.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Growth_Spend {

	const COST_STATUSES = array( 'approved', 'paid' );

	public static function table(): string {
		return LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::SPEND );
	}

	/**
	 * @param array<string, mixed> $input spent_on, channel, campaign, city, state, amount, description.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function add( array $input, int $actor ) {
		global $wpdb;
		$ts = ! empty( $input['spent_on'] ) ? strtotime( (string) $input['spent_on'] ) : false;
		if ( ! $ts ) {
			return new WP_Error( 'lnkup_spend_date', __( 'Enter the date the money was spent.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$channel = sanitize_key( (string) ( $input['channel'] ?? '' ) );
		if ( '' === $channel ) {
			return new WP_Error( 'lnkup_spend_channel', __( 'Choose the acquisition channel this spend belongs to.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$amount = isset( $input['amount_cents'] ) ? (int) $input['amount_cents'] : (int) round( (float) preg_replace( '/[^0-9.\-]/', '', (string) ( $input['amount'] ?? '' ) ) * 100 );
		if ( $amount <= 0 ) {
			return new WP_Error( 'lnkup_spend_amount', __( 'Enter an amount greater than zero.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$wpdb->insert(
			self::table(),
			array(
				'spent_on'     => gmdate( 'Y-m-d', $ts ),
				'channel'      => substr( $channel, 0, 64 ),
				'campaign'     => substr( sanitize_text_field( (string) ( $input['campaign'] ?? '' ) ), 0, 100 ),
				'city'         => substr( sanitize_text_field( (string) ( $input['city'] ?? '' ) ), 0, 100 ),
				'state'        => strtoupper( substr( sanitize_text_field( (string) ( $input['state'] ?? '' ) ), 0, 32 ) ),
				'amount_cents' => $amount,
				'description'  => substr( sanitize_text_field( (string) ( $input['description'] ?? '' ) ), 0, 255 ),
				'entered_by'   => $actor,
				'created_at'   => LNKup_Org_Growth::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'lnkup_spend_failed', __( 'The spend entry could not be saved.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		LNKup_Financial_Audit::log( 'org_growth_spend_added', 'org_growth_spend', $id, array( 'actor' => $actor, 'amount_cents' => $amount, 'channel' => $channel ) );
		LNKup_Org_Growth_Metrics::flush_cache();
		return (array) self::get( $id );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function void( int $id, string $reason, int $actor ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'lnkup_spend_not_found', __( 'Spend entry not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( $row['voided'] ) {
			return $row;
		}
		$reason = trim( sanitize_text_field( $reason ) );
		if ( '' === $reason ) {
			return new WP_Error( 'lnkup_spend_void_reason', __( 'Say why this entry is being voided.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET voided_at = %s, void_reason = %s, voided_by = %d WHERE id = %d AND voided_at IS NULL', LNKup_Org_Growth::now(), substr( $reason, 0, 255 ), $actor, $id ) );
		LNKup_Financial_Audit::log( 'org_growth_spend_voided', 'org_growth_spend', $id, array( 'actor' => $actor, 'reason' => $reason, 'amount_cents' => $row['amount_cents'] ) );
		LNKup_Org_Growth_Metrics::flush_cache();
		return (array) self::get( $id );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? self::format( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	public static function format( array $row ): array {
		$user = get_userdata( (int) $row['entered_by'] );
		return array(
			'id'           => (int) $row['id'],
			'spent_on'     => (string) $row['spent_on'],
			'channel'      => (string) $row['channel'],
			'campaign'     => (string) $row['campaign'],
			'city'         => (string) $row['city'],
			'state'        => (string) $row['state'],
			'amount_cents' => (int) $row['amount_cents'],
			'description'  => (string) $row['description'],
			'entered_by'   => $user ? (string) $user->display_name : '',
			'voided'       => ! empty( $row['voided_at'] ),
			'voided_at'    => (string) ( $row['voided_at'] ?? '' ),
			'void_reason'  => (string) $row['void_reason'],
			'created_at'   => (string) $row['created_at'],
		);
	}

	/**
	 * @param array<string, mixed> $args from, to, channel, include_voided, limit.
	 * @return array<int, array<string, mixed>>
	 */
	public static function entries( array $args = array() ): array {
		global $wpdb;
		list( $where, $values ) = self::where( $args, ! empty( $args['include_voided'] ) );
		$limit = max( 1, min( 500, (int) ( $args['limit'] ?? 200 ) ) );
		$sql   = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY spent_on DESC, id DESC LIMIT ' . $limit;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $values ? $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		return array_map( array( __CLASS__, 'format' ), (array) $rows );
	}

	/**
	 * @return array{0:string[],1:array<int,mixed>}
	 */
	private static function where( array $args, bool $include_voided = false ): array {
		$where  = array( '1=1' );
		$values = array();
		if ( ! $include_voided ) {
			$where[] = 'voided_at IS NULL';
		}
		if ( ! empty( $args['from'] ) && strtotime( (string) $args['from'] ) ) {
			$where[]  = 'spent_on >= %s';
			$values[] = gmdate( 'Y-m-d', (int) strtotime( (string) $args['from'] ) );
		}
		if ( ! empty( $args['to'] ) && strtotime( (string) $args['to'] ) ) {
			$where[]  = 'spent_on <= %s';
			$values[] = gmdate( 'Y-m-d', (int) strtotime( (string) $args['to'] ) );
		}
		if ( ! empty( $args['channel'] ) ) {
			$where[]  = 'channel = %s';
			$values[] = sanitize_key( (string) $args['channel'] );
		}
		return array( $where, $values );
	}

	/**
	 * Non-voided spend in cents grouped by channel.
	 *
	 * @return array<string, int>
	 */
	public static function spend_by_channel( array $args = array() ): array {
		global $wpdb;
		list( $where, $values ) = self::where( $args );
		$sql = 'SELECT channel, SUM(amount_cents) AS cents FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' GROUP BY channel';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $values ? $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['channel'] ] = (int) $row['cents'];
		}
		return $out;
	}

	/**
	 * Earned organizer-referral commissions in cents, grouped by the referred lead's source.
	 *
	 * @param array<string, mixed> $args from, to (reward created_at), affiliation.
	 * @return array{by_channel:array<string,int>,by_connector:array<int,int>,total:int}
	 */
	public static function commission_costs( array $args = array() ): array {
		global $wpdb;
		$out = array(
			'by_channel'   => array(),
			'by_connector' => array(),
			'total'        => 0,
		);
		if ( ! class_exists( 'LNKup_Treasury_Tables' ) ) {
			return $out;
		}
		$rewards  = LNKup_Treasury_Tables::referral_rewards_table();
		$refs     = LNKup_Treasury_Tables::referrals_table();
		$leads    = LNKup_Org_Leads::table();
		$accounts = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACCOUNTS );
		$where    = array( "w.reward_status IN ('approved','paid')", 'l.merged_into_id = 0' );
		$values   = array();
		if ( ! empty( $args['from'] ) && strtotime( (string) $args['from'] ) ) {
			$where[]  = 'w.created_at >= %s';
			$values[] = gmdate( 'Y-m-d 00:00:00', (int) strtotime( (string) $args['from'] ) );
		}
		if ( ! empty( $args['to'] ) && strtotime( (string) $args['to'] ) ) {
			$where[]  = 'w.created_at <= %s';
			$values[] = gmdate( 'Y-m-d 23:59:59', (int) strtotime( (string) $args['to'] ) );
		}
		$aff = (string) ( $args['affiliation'] ?? '' );
		if ( 'house' === $aff ) {
			$where[] = 'l.is_house_affiliated = 1';
		} elseif ( 'independent' === $aff ) {
			$where[] = 'l.is_house_affiliated = 0';
		}
		$sql = "SELECT l.source AS source, l.assigned_connector_id AS connector, SUM(GREATEST(w.amount - w.reversed_amount, 0)) AS net
			FROM {$rewards} w
			INNER JOIN {$refs} r ON r.id = w.referral_id
			INNER JOIN {$accounts} a ON a.user_id = r.referred_user_id AND a.status = 'linked'
			INNER JOIN {$leads} l ON l.id = a.lead_id
			WHERE " . implode( ' AND ', $where ) . ' GROUP BY l.source, l.assigned_connector_id';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $values ? $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$cents   = (int) round( (float) $row['net'] * 100 );
			$channel = '' === (string) $row['source'] ? 'unknown' : (string) $row['source'];
			$out['by_channel'][ $channel ] = ( $out['by_channel'][ $channel ] ?? 0 ) + $cents;
			if ( (int) $row['connector'] ) {
				$out['by_connector'][ (int) $row['connector'] ] = ( $out['by_connector'][ (int) $row['connector'] ] ?? 0 ) + $cents;
			}
			$out['total'] += $cents;
		}
		return $out;
	}

	/**
	 * CSV import: spent_on, channel, amount, campaign, city, state, description (headers flexible).
	 *
	 * @return array{added:int,errors:array<int,string>}|WP_Error
	 */
	public static function import_file( string $path, int $actor ) {
		$parsed = LNKup_Org_Lead_Import::parse_file( $path );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$map = array();
		foreach ( $parsed['headers'] as $i => $header ) {
			$h = strtolower( trim( preg_replace( '/[_\-]+/', ' ', (string) $header ) ) );
			if ( in_array( $h, array( 'date', 'spent on', 'spend date' ), true ) ) {
				$map['spent_on'] = $i;
			} elseif ( in_array( $h, array( 'channel', 'source' ), true ) ) {
				$map['channel'] = $i;
			} elseif ( in_array( $h, array( 'amount', 'cost', 'spend', 'amount usd' ), true ) ) {
				$map['amount'] = $i;
			} elseif ( in_array( $h, array( 'campaign', 'city', 'state', 'description', 'notes' ), true ) ) {
				$map[ 'notes' === $h ? 'description' : $h ] = $i;
			}
		}
		foreach ( array( 'spent_on', 'channel', 'amount' ) as $required ) {
			if ( ! isset( $map[ $required ] ) ) {
				/* translators: %s: column */
				return new WP_Error( 'lnkup_spend_columns', sprintf( __( 'The CSV needs a "%s" column (date, channel, amount).', 'lnkup-marketplace' ), $required ), array( 'status' => 400 ) );
			}
		}
		$added  = 0;
		$errors = array();
		foreach ( array_slice( $parsed['rows'], 0, 1000 ) as $n => $cells ) {
			$input = array();
			foreach ( $map as $field => $i ) {
				$input[ $field ] = (string) ( $cells[ $i ] ?? '' );
			}
			$result = self::add( $input, $actor );
			if ( is_wp_error( $result ) ) {
				$errors[ $n + 2 ] = $result->get_error_message();
			} else {
				++$added;
			}
		}
		return array(
			'added'  => $added,
			'errors' => $errors,
		);
	}
}
