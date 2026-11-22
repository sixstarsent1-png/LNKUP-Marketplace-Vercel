<?php
/**
 * Strategic partner organizations (lnkup_org_partners).
 *
 * One record per organization. Records dedupe on domain and normalized name, and link to the
 * existing Organizer Growth lead instead of duplicating it. Contacts are partner accounts with
 * `partner_id` set; performance is read from their referrals, rewards and submitted leads.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Partner_Organizations {

	const STAGES = array(
		'prospect'           => 'Prospect',
		'contacted'          => 'Contacted',
		'meeting'            => 'Meeting',
		'negotiation'        => 'Negotiation',
		'agreement'          => 'Agreement',
		'active'             => 'Active',
		'performance_review' => 'Performance review',
		'renewal'            => 'Renewal',
		'closed'             => 'Closed',
	);

	const CATEGORIES = array(
		'market_association'    => 'Market association',
		'chamber'               => 'Chamber of commerce',
		'production_company'    => 'Event production company',
		'venue_group'           => 'Venue operator',
		'community_org'         => 'Community business organization',
		'downtown_org'          => 'Downtown business organization',
		'university'            => 'University',
		'regional_network'      => 'Regional event network',
		'vendor_association'    => 'Vendor association',
		'agency'                => 'Agency representing organizers',
		'other'                 => 'Other industry partner',
	);

	public static function table(): string {
		return LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::PARTNERS );
	}

	/**
	 * @param array<string, mixed> $data name, website, contact_email.
	 * @return array{name_key: string, domain: string}
	 */
	private static function identity( array $data ): array {
		$domain = LNKup_Org_Lead_Dedupe::domain_from_url( (string) ( $data['website'] ?? '' ) );
		if ( '' === $domain ) {
			$domain = LNKup_Org_Lead_Dedupe::domain_from_email( LNKup_Org_Lead_Dedupe::normalize_email( (string) ( $data['contact_email'] ?? '' ) ) );
		}
		$free = array( 'gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com', 'aol.com' );
		return array(
			'name_key' => LNKup_Org_Lead_Dedupe::name_key( (string) ( $data['name'] ?? '' ) ),
			'domain'   => in_array( $domain, $free, true ) ? '' : $domain,
		);
	}

	/**
	 * Existing partner by domain or normalized name, else a new `prospect` record.
	 *
	 * @param array<string, mixed> $data Partner fields.
	 */
	public static function find_or_create( array $data, int $actor = 0 ): int {
		global $wpdb;
		$name = trim( sanitize_text_field( (string) ( $data['name'] ?? '' ) ) );
		if ( '' === $name ) {
			return 0;
		}
		$id     = self::identity( $data );
		$table  = self::table();
		$exists = 0;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( '' !== $id['domain'] ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE domain = %s ORDER BY id ASC LIMIT 1", $id['domain'] ) );
		}
		if ( ! $exists && strlen( $id['name_key'] ) >= 4 ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE name_key = %s ORDER BY id ASC LIMIT 1", $id['name_key'] ) );
		}
		// phpcs:enable
		if ( $exists ) {
			return $exists;
		}
		$now     = current_time( 'mysql' );
		$website = esc_url_raw( (string) ( $data['website'] ?? '' ) );
		$wpdb->insert(
			$table,
			array(
				'name'                 => substr( $name, 0, 191 ),
				'name_key'             => $id['name_key'],
				'domain'               => $id['domain'],
				'partner_type'         => self::category( (string) ( $data['category'] ?? '' ) ),
				'category'             => self::category( (string) ( $data['category'] ?? '' ) ),
				'website'              => $website,
				'contact_name'         => sanitize_text_field( (string) ( $data['contact_name'] ?? '' ) ),
				'contact_email'        => sanitize_email( (string) ( $data['contact_email'] ?? '' ) ),
				'cities_served'        => substr( sanitize_text_field( (string) ( $data['cities_served'] ?? '' ) ), 0, 255 ),
				'stage'                => 'prospect',
				'lead_id'              => self::matching_lead( $name, $website, (string) ( $data['contact_email'] ?? '' ) ),
				'agreement_status'     => 'none',
				'owner_user_id'        => 0,
				'created_at'           => $now,
				'updated_at'           => $now,
			)
		);
		$partner_id = (int) $wpdb->insert_id;
		if ( $partner_id ) {
			LNKup_Financial_Audit::log( 'partner_org_created', 'partner_org', $partner_id, array( 'actor_user_id' => $actor ) );
		}
		return $partner_id;
	}

	/**
	 * Organizer Growth lead for the same organization (hard identity match first, then name).
	 */
	private static function matching_lead( string $name, string $website, string $email ): int {
		if ( ! class_exists( 'LNKup_Org_Leads' ) || ! LNKup_Org_Growth_Tables::exists( LNKup_Org_Growth_Tables::LEADS ) ) {
			return 0;
		}
		$identity = LNKup_Org_Lead_Dedupe::identity( array( 'name' => $name, 'website' => $website, 'email' => $email ) );
		$matches  = LNKup_Org_Lead_Dedupe::find_matches( $identity );
		$hard     = LNKup_Org_Lead_Dedupe::first_hard( $matches );
		if ( $hard ) {
			return (int) $hard['lead_id'];
		}
		return $matches ? (int) $matches[0]['lead_id'] : 0;
	}

	private static function category( string $category ): string {
		$category = sanitize_key( $category );
		return isset( self::CATEGORIES[ $category ] ) ? $category : 'other';
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * @param array<string, mixed> $args stage, category, search, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$per   = max( 1, min( 100, (int) ( $args['per_page'] ?? 25 ) ) );
		$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
		$where = array( '1=1' );
		$vals  = array();
		$stage = sanitize_key( (string) ( $args['stage'] ?? '' ) );
		if ( isset( self::STAGES[ $stage ] ) ) {
			$where[] = 'stage = %s';
			$vals[]  = $stage;
		}
		$category = sanitize_key( (string) ( $args['category'] ?? '' ) );
		if ( isset( self::CATEGORIES[ $category ] ) ) {
			$where[] = 'category = %s';
			$vals[]  = $category;
		}
		$search = trim( sanitize_text_field( (string) ( $args['search'] ?? '' ) ) );
		if ( '' !== $search ) {
			$where[] = 'name LIKE %s';
			$vals[]  = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$sql = ' FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*)' . $sql, $vals ) ) : $wpdb->get_var( 'SELECT COUNT(*)' . $sql ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT *' . $sql . ' ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d', array_merge( $vals, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A );
		// phpcs:enable
		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::present( $row, false );
		}
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per );
	}

	/**
	 * @param array<string, mixed> $input Raw fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update( int $id, array $input, int $actor = 0 ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'lnkup_partner_org_missing', __( 'Partner organization not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$set = array();
		foreach ( array( 'name', 'contact_name', 'cities_served', 'agreement_ref' ) as $text ) {
			if ( array_key_exists( $text, $input ) ) {
				$set[ $text ] = substr( trim( sanitize_text_field( (string) $input[ $text ] ) ), 0, 'cities_served' === $text ? 255 : 191 );
			}
		}
		foreach ( array( 'referral_arrangement', 'notes' ) as $area ) {
			if ( array_key_exists( $area, $input ) ) {
				$set[ $area ] = sanitize_textarea_field( (string) $input[ $area ] );
			}
		}
		if ( array_key_exists( 'contact_email', $input ) ) {
			$set['contact_email'] = sanitize_email( (string) $input['contact_email'] );
		}
		if ( array_key_exists( 'website', $input ) ) {
			$set['website'] = esc_url_raw( (string) $input['website'] );
		}
		if ( array_key_exists( 'category', $input ) ) {
			$set['category']     = self::category( (string) $input['category'] );
			$set['partner_type'] = $set['category'];
		}
		if ( array_key_exists( 'owner_user_id', $input ) ) {
			$set['owner_user_id'] = absint( $input['owner_user_id'] );
		}
		if ( array_key_exists( 'next_follow_up_at', $input ) ) {
			$ts                       = strtotime( (string) $input['next_follow_up_at'] );
			$set['next_follow_up_at'] = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
		}
		if ( array_key_exists( 'stage', $input ) ) {
			$stage = sanitize_key( (string) $input['stage'] );
			if ( ! isset( self::STAGES[ $stage ] ) ) {
				return new WP_Error( 'lnkup_partner_org_stage', __( 'Unknown partnership stage.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			$set['stage']            = $stage;
			$set['agreement_status'] = in_array( $stage, array( 'agreement', 'active', 'performance_review', 'renewal' ), true ) ? 'signed' : ( 'closed' === $stage ? 'closed' : 'none' );
		}
		if ( array_key_exists( 'commission_rule_id', $input ) ) {
			$rule_id = absint( $input['commission_rule_id'] );
			if ( $rule_id ) {
				$rule = LNKup_Commission_Rules::get( $rule_id );
				if ( ! $rule || LNKup_Partner_Programs::STRATEGIC_PARTNER !== $rule['program'] ) {
					return new WP_Error( 'lnkup_partner_org_rule', __( 'Choose a strategic partner commission rule.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
				}
			}
			$set['commission_rule_id'] = $rule_id;
		}
		if ( ! $set ) {
			return self::present( $row );
		}
		if ( isset( $set['name'] ) || isset( $set['website'] ) || isset( $set['contact_email'] ) ) {
			$identity        = self::identity( array_merge( $row, $set ) );
			$set['name_key'] = $identity['name_key'];
			$set['domain']   = $identity['domain'];
		}
		$set['updated_at'] = current_time( 'mysql' );
		$wpdb->update( self::table(), $set, array( 'id' => $id ) );
		$changes = array();
		foreach ( $set as $key => $value ) {
			if ( 'updated_at' !== $key && (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
				$changes[ $key ] = array( (string) ( $row[ $key ] ?? '' ), (string) $value );
			}
		}
		if ( isset( $changes['stage'] ) ) {
			LNKup_Financial_Audit::log( 'partner_org_stage_changed', 'partner_org', $id, array( 'from' => $changes['stage'][0], 'to' => $changes['stage'][1], 'actor_user_id' => $actor ?: get_current_user_id() ) );
		}
		if ( isset( $changes['commission_rule_id'] ) ) {
			LNKup_Financial_Audit::log( 'partner_org_rule_changed', 'partner_org', $id, array( 'from' => $changes['commission_rule_id'][0], 'to' => $changes['commission_rule_id'][1], 'actor_user_id' => $actor ?: get_current_user_id() ) );
		}
		if ( $changes ) {
			LNKup_Financial_Audit::log( 'partner_org_updated', 'partner_org', $id, array( 'fields' => array_keys( $changes ), 'actor_user_id' => $actor ?: get_current_user_id() ) );
		}
		return self::present( (array) self::get( $id ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function contacts( int $partner_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, user_id, display_name, email, status, created_at FROM ' . LNKup_Connector_Tables::connectors_table() . ' WHERE partner_id = %d ORDER BY id ASC', $partner_id ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'connector_id' => (int) $row['id'],
				'user_id'      => (int) $row['user_id'],
				'name'         => (string) $row['display_name'],
				'email'        => (string) $row['email'],
				'status'       => (string) $row['status'],
				'status_label' => LNKup_Partner_Applications::STATUSES[ (string) $row['status'] ] ?? (string) $row['status'],
			);
		}
		return $out;
	}

	/**
	 * Real outcomes from the partner's contacts: referrals, milestones, net commissions and leads.
	 *
	 * @return array<string, mixed>
	 */
	public static function performance( int $partner_id ): array {
		global $wpdb;
		$out = array( 'referred_organizers' => 0, 'first_event' => 0, 'first_paid_booking' => 0, 'referred_vendors' => 0, 'leads' => 0, 'commission_net' => 0.0, 'commission_paid' => 0.0 );
		$ids = array_map( static fn( $c ) => (int) $c['connector_id'], self::contacts( $partner_id ) );
		if ( $ids ) {
			$in      = implode( ',', array_map( 'intval', $ids ) );
			$refs    = LNKup_Treasury_Tables::referrals_table();
			$codes   = LNKup_Treasury_Tables::referral_codes_table();
			$rewards = LNKup_Treasury_Tables::referral_rewards_table();
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row(
				"SELECT
					COUNT(DISTINCT CASE WHEN r.target_role LIKE '%organizer%' THEN r.referred_user_id END) AS organizers,
					COUNT(DISTINCT CASE WHEN r.target_role LIKE '%organizer%' AND (r.first_event_at IS NOT NULL OR r.first_opportunity_at IS NOT NULL) THEN r.referred_user_id END) AS first_event,
					COUNT(DISTINCT CASE WHEN r.first_payment_at IS NOT NULL THEN r.referred_user_id END) AS paid,
					COUNT(DISTINCT CASE WHEN r.target_role LIKE '%vendor%' THEN r.referred_user_id END) AS vendors
				FROM {$refs} r INNER JOIN {$codes} c ON c.id = r.referral_code_id
				WHERE c.connector_id IN ({$in})",
				ARRAY_A
			);
			$out['referred_organizers'] = (int) ( $row['organizers'] ?? 0 );
			$out['first_event']         = (int) ( $row['first_event'] ?? 0 );
			$out['first_paid_booking']  = (int) ( $row['paid'] ?? 0 );
			$out['referred_vendors']    = (int) ( $row['vendors'] ?? 0 );
			$out['commission_net']      = round( (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount - reversed_amount), 0) FROM {$rewards} WHERE connector_id IN ({$in}) AND reward_status <> 'reversed'" ), 2 );
			$out['commission_paid']     = round( (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount - reversed_amount), 0) FROM {$rewards} WHERE connector_id IN ({$in}) AND reward_status = 'paid'" ), 2 );
			// phpcs:enable
		}
		if ( class_exists( 'LNKup_Org_Leads' ) && LNKup_Org_Growth_Tables::exists( LNKup_Org_Growth_Tables::LEADS ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$out['leads'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Org_Leads::table() . ' WHERE partner_id = %d AND merged_into_id = 0', $partner_id ) );
		}
		return $out;
	}

	public static function count_active(): int {
		global $wpdb;
		if ( ! LNKup_Org_Growth_Tables::exists( LNKup_Org_Growth_Tables::PARTNERS ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE stage IN ('agreement','active','performance_review','renewal')" );
	}

	/**
	 * @param array<string, mixed> $row Partner row.
	 * @return array<string, mixed>
	 */
	public static function present( array $row, bool $detail = true ): array {
		$owner = (int) ( $row['owner_user_id'] ?? 0 ) ? get_userdata( (int) $row['owner_user_id'] ) : false;
		$rule  = (int) ( $row['commission_rule_id'] ?? 0 ) ? LNKup_Commission_Rules::get( (int) $row['commission_rule_id'] ) : null;
		$out   = array(
			'id'                   => (int) $row['id'],
			'name'                 => (string) $row['name'],
			'category'             => (string) ( $row['category'] ?? '' ),
			'category_label'       => self::CATEGORIES[ (string) ( $row['category'] ?? '' ) ] ?? '',
			'website'              => (string) ( $row['website'] ?? '' ),
			'contact_name'         => (string) ( $row['contact_name'] ?? '' ),
			'contact_email'        => (string) ( $row['contact_email'] ?? '' ),
			'cities_served'        => (string) ( $row['cities_served'] ?? '' ),
			'stage'                => (string) ( $row['stage'] ?? 'prospect' ),
			'stage_label'          => self::STAGES[ (string) ( $row['stage'] ?? 'prospect' ) ] ?? '',
			'owner_user_id'        => (int) ( $row['owner_user_id'] ?? 0 ),
			'owner'                => $owner ? $owner->display_name : '',
			'agreement_ref'        => (string) ( $row['agreement_ref'] ?? '' ),
			'commission_rule_id'   => (int) ( $row['commission_rule_id'] ?? 0 ),
			'commission_rule'      => $rule ? sprintf( 'v%d %s (%s)', (int) $rule['version'], LNKup_Commission_Rules::basis_label( $rule ), (string) $rule['status'] ) : '',
			'lead_id'              => (int) ( $row['lead_id'] ?? 0 ),
			'referral_arrangement' => (string) ( $row['referral_arrangement'] ?? '' ),
			'notes'                => (string) ( $row['notes'] ?? '' ),
			'next_follow_up_at'    => (string) ( $row['next_follow_up_at'] ?? '' ),
			'updated_at'           => (string) ( $row['updated_at'] ?? '' ),
		);
		if ( $detail ) {
			$out['contacts']    = self::contacts( (int) $row['id'] );
			$out['performance'] = self::performance( (int) $row['id'] );
		}
		return $out;
	}
}
