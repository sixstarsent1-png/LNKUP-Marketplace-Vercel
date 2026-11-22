<?php
/**
 * Contact suppression for organizer outreach (unsubscribe, do-not-contact, bounce, complaint).
 *
 * Values are stored only as SHA-256 hashes. Leads are not WordPress users, so this list lives
 * here; when the Communications plugin is running, entries are mirrored into its suppression
 * table and its list is consulted too.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Contact_Suppression {

	const REASONS = array( 'unsubscribe', 'do_not_contact', 'bounce', 'complaint', 'manual' );

	public static function table(): string {
		return LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::SUPPRESSION );
	}

	public static function hash( string $value ): string {
		return hash( 'sha256', strtolower( trim( $value ) ) );
	}

	private static function comms_active(): bool {
		return did_action( 'lnkup_comms_loaded' ) > 0 && class_exists( 'LNKup_Comms_Suppression' );
	}

	public static function add_email( string $email, string $reason, string $source = '', int $lead_id = 0, int $actor = 0 ): bool {
		$email = LNKup_Org_Lead_Dedupe::normalize_email( $email );
		if ( '' === $email ) {
			return false;
		}
		self::insert( 'email', self::hash( $email ), $reason, $source, $lead_id, $actor );
		if ( self::comms_active() ) {
			LNKup_Comms_Suppression::add( $email, in_array( $reason, array( 'bounce' ), true ) ? 'all' : 'marketing', $reason );
		}
		return true;
	}

	public static function add_domain( string $domain, string $reason, string $source = '', int $lead_id = 0, int $actor = 0 ): bool {
		$domain = strtolower( trim( $domain ) );
		if ( '' === $domain || in_array( $domain, LNKup_Org_Lead_Dedupe::FREE_MAIL_DOMAINS, true ) ) {
			return false;
		}
		self::insert( 'domain', self::hash( $domain ), $reason, $source, $lead_id, $actor );
		return true;
	}

	private static function insert( string $scope, string $hash, string $reason, string $source, int $lead_id, int $actor ): void {
		global $wpdb;
		$reason = in_array( $reason, self::REASONS, true ) ? $reason : 'manual';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::table() . ' (scope, value_hash, reason, source, lead_id, created_by, created_at) VALUES (%s, %s, %s, %s, %d, %d, %s)
				ON DUPLICATE KEY UPDATE reason = VALUES(reason)',
				$scope,
				$hash,
				$reason,
				substr( sanitize_key( $source ), 0, 64 ),
				$lead_id,
				$actor,
				LNKup_Org_Growth::now()
			)
		);
	}

	/**
	 * Suppress a lead's email and (non-free-mail) domain.
	 */
	public static function suppress_lead( int $lead_id, string $reason, int $actor = 0 ): void {
		$lead = LNKup_Org_Leads::get_row( $lead_id );
		if ( ! $lead ) {
			return;
		}
		self::add_email( (string) $lead['email'], $reason, 'lead', $lead_id, $actor );
		if ( 'do_not_contact' === $reason ) {
			self::add_domain( (string) $lead['domain'], $reason, 'lead', $lead_id, $actor );
		}
		LNKup_Org_Leads::log_activity(
			$lead_id,
			'suppressed',
			array(
				'actor'   => $actor,
				'summary' => sprintf( /* translators: %s: reason */ __( 'Contact suppressed (%s)', 'lnkup-marketplace' ), str_replace( '_', ' ', $reason ) ),
				'payload' => array( 'reason' => $reason ),
			)
		);
	}

	/**
	 * @return string '' when sendable, otherwise the suppression reason.
	 */
	public static function check_email( string $email ): string {
		global $wpdb;
		$email = LNKup_Org_Lead_Dedupe::normalize_email( $email );
		if ( '' === $email ) {
			return 'invalid_email';
		}
		$domain = substr( $email, (int) strrpos( $email, '@' ) + 1 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$reason = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT reason FROM ' . self::table() . " WHERE (scope = 'email' AND value_hash = %s) OR (scope = 'domain' AND value_hash = %s) LIMIT 1",
				self::hash( $email ),
				self::hash( $domain )
			)
		);
		if ( is_string( $reason ) && '' !== $reason ) {
			return $reason;
		}
		if ( self::comms_active() ) {
			$comms = LNKup_Comms_Suppression::check_email( $email, 'organizer_growth', true );
			if ( '' !== $comms ) {
				return 'comms_' . $comms;
			}
		}
		return '';
	}

	/**
	 * @param array<string, mixed> $lead Lead row.
	 */
	public static function is_lead_suppressed( array $lead ): bool {
		if ( 'do_not_contact' === (string) ( $lead['outcome'] ?? '' ) ) {
			return true;
		}
		$email = (string) ( $lead['email'] ?? '' );
		if ( '' === $email ) {
			return false;
		}
		$reason = self::check_email( $email );
		return '' !== $reason && 'invalid_email' !== $reason;
	}

	/**
	 * Remove a suppression (admin only; never for unsubscribes or complaints).
	 *
	 * @return true|WP_Error
	 */
	public static function remove_email( string $email, int $actor ) {
		global $wpdb;
		$hash = self::hash( $email );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$reason = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT reason FROM ' . self::table() . " WHERE scope = 'email' AND value_hash = %s", $hash ) );
		if ( in_array( $reason, array( 'unsubscribe', 'complaint' ), true ) ) {
			return new WP_Error( 'lnkup_suppression_locked', __( 'This person unsubscribed or reported spam. Only they can opt back in.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$wpdb->delete( self::table(), array( 'scope' => 'email', 'value_hash' => $hash ) );
		LNKup_Financial_Audit::log( 'contact_suppression_removed', 'suppression', 0, array( 'actor' => $actor, 'reason' => $reason ) );
		return true;
	}

	/**
	 * @return array<string, int> reason => count.
	 */
	public static function counts(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( 'SELECT reason, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY reason', ARRAY_A );
		$out  = array_fill_keys( self::REASONS, 0 );
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['reason'] ] = (int) $row['n'];
		}
		return $out;
	}
}
