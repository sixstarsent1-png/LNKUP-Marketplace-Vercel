<?php
/**
 * Lead normalization, duplicate detection and manual merge.
 *
 * Hard matches (same verified email, business domain, Instagram handle or linked organizer
 * account) block a second record. Similar names only raise a "possible duplicate" flag for
 * review — unrelated businesses with similar names are never merged automatically.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Lead_Dedupe {

	/** Mailbox providers whose domain says nothing about the organization. */
	const FREE_MAIL_DOMAINS = array(
		'gmail.com', 'googlemail.com', 'yahoo.com', 'ymail.com', 'hotmail.com', 'outlook.com', 'live.com', 'msn.com',
		'aol.com', 'icloud.com', 'me.com', 'mac.com', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.com', 'zoho.com',
		'comcast.net', 'att.net', 'bellsouth.net', 'verizon.net', 'sbcglobal.net', 'yandex.com',
	);

	/** Hosts that are profile platforms, not an organization's own site. */
	const PLATFORM_HOSTS = array(
		'instagram.com', 'facebook.com', 'fb.com', 'tiktok.com', 'twitter.com', 'x.com', 'linktr.ee', 'eventbrite.com',
		'posh.vip', 'universe.com', 'allevents.in', 'google.com', 'sites.google.com', 'wixsite.com', 'linkin.bio',
	);

	const NAME_STOPWORDS = array( 'the', 'llc', 'inc', 'co', 'company', 'ltd', 'corp', 'corporation', 'group' );

	public static function normalize_email( string $email ): string {
		$email = strtolower( trim( $email ) );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Registrable host of a URL, without www., or '' for platform hosts.
	 */
	public static function domain_from_url( string $url ): string {
		$url = trim( strtolower( $url ) );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#', $url ) ) {
			$url = 'http://' . $url;
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$host = preg_replace( '/^www\./', '', $host );
		if ( '' === $host || false === strpos( $host, '.' ) ) {
			return '';
		}
		foreach ( self::PLATFORM_HOSTS as $platform ) {
			if ( $host === $platform || substr( $host, -strlen( '.' . $platform ) ) === '.' . $platform ) {
				return '';
			}
		}
		return $host;
	}

	/**
	 * Business domain from email when it is not a free mailbox.
	 */
	public static function domain_from_email( string $email ): string {
		$email = self::normalize_email( $email );
		if ( '' === $email ) {
			return '';
		}
		$domain = substr( $email, (int) strrpos( $email, '@' ) + 1 );
		return in_array( $domain, self::FREE_MAIL_DOMAINS, true ) ? '' : $domain;
	}

	public static function normalize_instagram( string $value ): string {
		$value = trim( strtolower( $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '#instagram\.com/([a-z0-9._]+)#', $value, $m ) ) {
			$value = $m[1];
		}
		$value = ltrim( $value, '@' );
		$value = preg_replace( '/[^a-z0-9._]/', '', $value );
		return in_array( $value, array( 'p', 'reel', 'explore', 'stories' ), true ) ? '' : (string) $value;
	}

	public static function name_key( string $name ): string {
		$name  = strtolower( remove_accents( $name ) );
		$name  = str_replace( '&', ' and ', $name );
		$words = preg_split( '/[^a-z0-9]+/', $name ) ?: array();
		$words = array_values( array_filter( $words, static fn( $w ) => '' !== $w && ! in_array( $w, self::NAME_STOPWORDS, true ) ) );
		return substr( implode( ' ', $words ), 0, 191 );
	}

	/**
	 * Derived identity columns for a lead payload.
	 *
	 * @param array<string, mixed> $data Sanitized lead columns.
	 * @return array<string, string>
	 */
	public static function identity( array $data ): array {
		$email  = self::normalize_email( (string) ( $data['email'] ?? '' ) );
		$domain = self::domain_from_url( (string) ( $data['website'] ?? '' ) );
		if ( '' === $domain ) {
			$domain = self::domain_from_email( $email );
		}
		return array(
			'email'     => $email,
			'domain'    => $domain,
			'instagram' => self::normalize_instagram( (string) ( $data['instagram'] ?? '' ) ),
			'name_key'  => self::name_key( (string) ( $data['name'] ?? '' ) ),
		);
	}

	/**
	 * Existing leads that match the identity.
	 *
	 * @param array<string, string> $identity  From identity().
	 * @param string                $state     State for name similarity.
	 * @param int                   $exclude   Lead to ignore.
	 * @return array<int, array{lead_id:int,reason:string,hard:bool}>
	 */
	public static function find_matches( array $identity, string $state = '', int $exclude = 0 ): array {
		global $wpdb;
		$table   = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::LEADS );
		$matches = array();
		$checks  = array(
			'email'     => 'email',
			'domain'    => 'domain',
			'instagram' => 'instagram',
		);
		foreach ( $checks as $key => $column ) {
			$value = (string) ( $identity[ $key ] ?? '' );
			if ( '' === $value ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE {$column} = %s AND merged_into_id = 0 AND id <> %d LIMIT 5", $value, $exclude ) );
			foreach ( $ids as $id ) {
				$matches[ (int) $id ] = $matches[ (int) $id ] ?? array(
					'lead_id' => (int) $id,
					'reason'  => $key,
					'hard'    => true,
				);
			}
		}

		$email = (string) ( $identity['email'] ?? '' );
		if ( '' !== $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$lead_id = LNKup_Org_Leads::lead_for_user( (int) $user->ID );
				if ( $lead_id && $lead_id !== $exclude && ! isset( $matches[ $lead_id ] ) ) {
					$matches[ $lead_id ] = array(
						'lead_id' => $lead_id,
						'reason'  => 'linked_account',
						'hard'    => true,
					);
				}
			}
		}

		$name_key = (string) ( $identity['name_key'] ?? '' );
		if ( strlen( $name_key ) >= 4 ) {
			$sql = "SELECT id FROM {$table} WHERE name_key = %s AND merged_into_id = 0 AND id <> %d";
			$args = array( $name_key, $exclude );
			if ( '' !== $state ) {
				$sql   .= ' AND (state = %s OR state = \'\')';
				$args[] = $state;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$ids = $wpdb->get_col( $wpdb->prepare( $sql . ' LIMIT 5', $args ) );
			foreach ( $ids as $id ) {
				if ( ! isset( $matches[ (int) $id ] ) ) {
					$matches[ (int) $id ] = array(
						'lead_id' => (int) $id,
						'reason'  => 'similar_name',
						'hard'    => false,
					);
				}
			}
		}
		return array_values( $matches );
	}

	/**
	 * @param array<int, array{lead_id:int,reason:string,hard:bool}> $matches Matches.
	 * @return array{lead_id:int,reason:string,hard:bool}|null
	 */
	public static function first_hard( array $matches ): ?array {
		foreach ( $matches as $match ) {
			if ( $match['hard'] ) {
				return $match;
			}
		}
		return null;
	}

	/**
	 * Review queue: leads flagged as possible duplicates.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function review_queue( int $limit = 50 ): array {
		global $wpdb;
		$table = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::LEADS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE possible_duplicate_of > 0 AND merged_into_id = 0 ORDER BY updated_at DESC LIMIT %d", max( 1, min( 200, $limit ) ) ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$other = LNKup_Org_Leads::get_row( (int) $row['possible_duplicate_of'] );
			$out[] = array(
				'lead'      => LNKup_Org_Leads::format( $row ),
				'candidate' => $other ? LNKup_Org_Leads::format( $other ) : null,
			);
		}
		return $out;
	}

	/**
	 * Clear a possible-duplicate flag after review ("not the same business").
	 */
	public static function dismiss( int $lead_id, int $actor ): bool {
		global $wpdb;
		$table = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::LEADS );
		$wpdb->update( $table, array( 'possible_duplicate_of' => 0, 'updated_at' => LNKup_Org_Growth::now() ), array( 'id' => $lead_id ) );
		LNKup_Org_Leads::log_activity( $lead_id, 'duplicate_dismissed', array( 'actor' => $actor, 'summary' => __( 'Marked as a different business', 'lnkup-marketplace' ) ) );
		return true;
	}

	/**
	 * Merge $source into $target. History, attribution, accounts and milestones move; the
	 * source row stays (merged_into_id, outcome=duplicate) so its attribution is auditable.
	 *
	 * @return true|WP_Error
	 */
	public static function merge( int $source_id, int $target_id, int $actor ) {
		global $wpdb;
		if ( $source_id === $target_id ) {
			return new WP_Error( 'lnkup_merge_same', __( 'Choose two different leads to merge.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$source = LNKup_Org_Leads::get_row( $source_id );
		$target = LNKup_Org_Leads::get_row( $target_id );
		if ( ! $source || ! $target ) {
			return new WP_Error( 'lnkup_lead_not_found', __( 'Lead not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( (int) $source['merged_into_id'] || (int) $target['merged_into_id'] ) {
			return new WP_Error( 'lnkup_merge_merged', __( 'One of these leads was already merged.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}

		$lock = 'lnkup_org_lead_merge';
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'lnkup_busy', __( 'Another merge is running. Try again in a moment.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		try {
			$t = static fn( string $s ): string => LNKup_Org_Growth_Tables::table( $s );
			foreach ( array( LNKup_Org_Growth_Tables::ACTIVITY, LNKup_Org_Growth_Tables::TASKS, LNKup_Org_Growth_Tables::LEAD_EVENTS, LNKup_Org_Growth_Tables::ACCOUNTS ) as $suffix ) {
				$wpdb->update( $t( $suffix ), array( 'lead_id' => $target_id ), array( 'lead_id' => $source_id ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . $t( LNKup_Org_Growth_Tables::TAGS ) . ' (lead_id, tag) SELECT %d, tag FROM ' . $t( LNKup_Org_Growth_Tables::TAGS ) . ' WHERE lead_id = %d', $target_id, $source_id ) );
			$wpdb->delete( $t( LNKup_Org_Growth_Tables::TAGS ), array( 'lead_id' => $source_id ) );
			// Keep the earliest achievement of each milestone.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$moving = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $t( LNKup_Org_Growth_Tables::MILESTONES ) . ' WHERE lead_id = %d', $source_id ), ARRAY_A );
			foreach ( (array) $moving as $m ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, achieved_at FROM ' . $t( LNKup_Org_Growth_Tables::MILESTONES ) . ' WHERE lead_id = %d AND milestone = %s', $target_id, $m['milestone'] ), ARRAY_A );
				if ( ! $existing ) {
					$wpdb->update( $t( LNKup_Org_Growth_Tables::MILESTONES ), array( 'lead_id' => $target_id ), array( 'id' => (int) $m['id'] ) );
					continue;
				}
				if ( (string) $m['achieved_at'] < (string) $existing['achieved_at'] ) {
					$wpdb->update( $t( LNKup_Org_Growth_Tables::MILESTONES ), array( 'achieved_at' => $m['achieved_at'], 'source_object_id' => $m['source_object_id'], 'user_id' => $m['user_id'] ), array( 'id' => (int) $existing['id'] ) );
				}
				$wpdb->delete( $t( LNKup_Org_Growth_Tables::MILESTONES ), array( 'id' => (int) $m['id'] ) );
			}

			$fill = array();
			foreach ( array( 'email', 'domain', 'phone', 'instagram', 'facebook', 'website', 'city', 'state', 'service_area', 'description', 'category', 'event_frequency', 'cities_served', 'event_categories', 'application_process', 'application_url', 'referral_code' ) as $col ) {
				if ( '' === (string) $target[ $col ] && '' !== (string) $source[ $col ] ) {
					$fill[ $col ] = $source[ $col ];
				}
			}
			foreach ( array( 'estimated_booths', 'referrer_user_id', 'assigned_user_id', 'assigned_connector_id', 'partner_id' ) as $col ) {
				if ( ! (int) $target[ $col ] && (int) $source[ $col ] ) {
					$fill[ $col ] = (int) $source[ $col ];
				}
			}
			if ( empty( $target['next_event_date'] ) && ! empty( $source['next_event_date'] ) ) {
				$fill['next_event_date'] = $source['next_event_date'];
			}
			if ( LNKup_Org_Lead_Pipeline::rank( (string) $source['stage'] ) > LNKup_Org_Lead_Pipeline::rank( (string) $target['stage'] ) ) {
				$fill['stage'] = $source['stage'];
			}
			$fill['possible_duplicate_of'] = 0;
			$fill['updated_at']            = LNKup_Org_Growth::now();
			$wpdb->update( $t( LNKup_Org_Growth_Tables::LEADS ), $fill, array( 'id' => $target_id ) );

			$wpdb->update(
				$t( LNKup_Org_Growth_Tables::LEADS ),
				array(
					'merged_into_id'        => $target_id,
					'outcome'               => 'duplicate',
					'possible_duplicate_of' => 0,
					'updated_at'            => LNKup_Org_Growth::now(),
				),
				array( 'id' => $source_id )
			);
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$summary = array(
			'actor'   => $actor,
			'payload' => array(
				'source_id'     => $source_id,
				'target_id'     => $target_id,
				'source_name'   => (string) $source['name'],
				'source_source' => (string) $source['source'],
				'source_import' => (int) $source['import_id'],
			),
		);
		LNKup_Org_Leads::log_activity( $target_id, 'merged', $summary + array( 'summary' => sprintf( /* translators: %s: lead name */ __( 'Merged duplicate "%s" into this lead', 'lnkup-marketplace' ), (string) $source['name'] ) ) );
		LNKup_Org_Leads::log_activity( $source_id, 'merged', $summary + array( 'summary' => __( 'Merged into another lead', 'lnkup-marketplace' ) ) );
		LNKup_Org_Lead_Scoring::score_lead( $target_id );
		LNKup_Org_Growth_Metrics::flush_cache();
		return true;
	}
}
