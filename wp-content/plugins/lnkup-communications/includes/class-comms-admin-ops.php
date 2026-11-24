<?php
/**
 * Admin command-center reads and writes: per-user communication timeline, the
 * suppression list, audit logging (marketplace Trust audit log) and the
 * treated-vs-holdout comparison.
 *
 * Callers are responsible for capability checks; every method here is admin-only.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Admin_Ops {

	/**
	 * Append to the tamper-evident marketplace audit log (or the PHP log when Trust is unavailable).
	 *
	 * @param string     $action    Action slug.
	 * @param string|int $entity    Entity ID or key.
	 * @param array      $args      prev, new, reason.
	 */
	public static function audit( string $action, $entity, array $args = array() ): void {
		$entity_id = is_numeric( $entity ) ? (int) $entity : 0;
		if ( ! $entity_id ) {
			$args['new'] = array_merge( array( 'key' => (string) $entity ), (array) ( $args['new'] ?? array() ) );
		}
		if ( class_exists( 'LNKup_Audit_Log' ) && LNKup_Audit_Log::log( $action, 'communications', $entity_id, $args ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( 'LNKup Communications audit: %s %s by user %d', $action, (string) $entity, get_current_user_id() ) );
	}

	/**
	 * Settings diff for the audit log (secrets are never written).
	 */
	public static function audit_settings( array $before, array $after ): void {
		$changed = array();
		foreach ( $after as $key => $value ) {
			if ( 'email_webhook_secret' === $key ) {
				continue;
			}
			if ( ( $before[ $key ] ?? null ) !== $value ) {
				$changed[ $key ] = array( 'from' => $before[ $key ] ?? null, 'to' => $value );
			}
		}
		if ( $changed ) {
			self::audit( 'comms_settings_changed', 'settings', array( 'new' => $changed ) );
		}
	}

	// ------------------------------------------------------------------ Timeline.

	/**
	 * Everything Communications knows about one user. Viewing it is audit-logged by the caller.
	 *
	 * @return array|null
	 */
	public static function user_timeline( int $user_id, int $limit = 50 ): ?array {
		global $wpdb;
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}
		$messages   = LNKup_Comms_Schema::table( 'messages' );
		$deliveries = LNKup_Comms_Schema::table( 'deliveries' );
		$clicks     = LNKup_Comms_Schema::table( 'clicks' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, trigger_key, template_key, recipient_role, category, priority, status, suppression_reason, title, event_id, application_id, opportunity_id, campaign_id, scheduled_at, sent_at, read_at, opened_at, clicked_at, created_at FROM {$messages} WHERE recipient_user_id = %d ORDER BY id DESC LIMIT %d", $user_id, $limit ), ARRAY_A );
		$ids  = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$by   = array();
		if ( $ids ) {
			foreach ( (array) $wpdb->get_results( 'SELECT message_id, channel, status, suppression_reason, last_error, attempts, created_at FROM ' . $deliveries . ' WHERE message_id IN (' . implode( ',', $ids ) . ') ORDER BY id ASC', ARRAY_A ) as $d ) {
				$by[ (int) $d['message_id'] ][] = $d;
			}
		}
		$click_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$clicks} WHERE user_id = %d", $user_id ) );
		$scheduled   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, trigger_key, scheduled_at, cancel_key FROM {$messages} WHERE recipient_user_id = %d AND status = 'scheduled' ORDER BY scheduled_at ASC LIMIT 50", $user_id ), ARRAY_A );
		$reasons     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT suppression_reason AS reason, COUNT(*) AS c FROM {$messages} WHERE recipient_user_id = %d AND status IN ('suppressed','cancelled') AND suppression_reason <> '' GROUP BY suppression_reason ORDER BY c DESC LIMIT 15", $user_id ), ARRAY_A );
		$devices     = class_exists( 'LNKup_Push_Tables' ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Push_Tables::table_name( LNKup_Push_Tables::DEVICES ) . ' WHERE user_id = %d AND enabled = 1', $user_id ) ) : 0;
		$supp_rows   = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, scope, reason, created_at FROM ' . LNKup_Comms_Schema::table( 'suppressions' ) . ' WHERE email_hash = %s', LNKup_Comms_Suppression::hash_email( (string) $user->user_email ) ), ARRAY_A );
		// phpcs:enable
		foreach ( $rows as &$row ) {
			$row['deliveries'] = $by[ (int) $row['id'] ] ?? array();
		}
		unset( $row );
		return array(
			'user'         => array(
				'id'         => $user_id,
				'name'       => (string) $user->display_name,
				'roles'      => LNKup_Comms_Preferences::user_roles( $user_id ),
				'registered' => (string) $user->user_registered,
			),
			'preferences'  => LNKup_Comms_Preferences::get( $user_id ),
			'devices'      => $devices,
			'suppressions' => $supp_rows,
			'scheduled'    => $scheduled,
			'reasons'      => $reasons,
			'clicks'       => $click_count,
			'messages'     => $rows,
		);
	}

	// ------------------------------------------------------------------ Suppression list.

	/**
	 * @param string $email Optional email filter (hashed before lookup; the list never stores addresses).
	 */
	public static function suppressions( string $email = '', int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$table = LNKup_Comms_Schema::table( 'suppressions' );
		$where = '1=1';
		$args  = array();
		if ( '' !== $email ) {
			$where  = 'email_hash = %s';
			$args[] = LNKup_Comms_Suppression::hash_email( $email );
		}
		$offset = max( 0, ( $page - 1 ) * $per_page );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, email_hash, scope, reason, created_at FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A );
		$by_reason = (array) $wpdb->get_results( "SELECT reason, COUNT(*) AS c FROM {$table} GROUP BY reason ORDER BY c DESC", ARRAY_A );
		// phpcs:enable
		return array( 'items' => $rows, 'total' => $total, 'by_reason' => $by_reason, 'page' => $page, 'per_page' => $per_page );
	}

	/**
	 * Remove one suppression row with a mandatory note (audit-logged). Removing a bounce does not
	 * re-subscribe marketing; it only lets delivery be attempted again.
	 *
	 * @return true|WP_Error
	 */
	public static function remove_suppression( int $id, string $note ) {
		global $wpdb;
		$note = sanitize_text_field( $note );
		if ( '' === trim( $note ) ) {
			return new WP_Error( 'lnkup_comms_note_required', __( 'Add a note explaining why this address is being removed.', 'lnkup-communications' ), array( 'status' => 400 ) );
		}
		$table = LNKup_Comms_Schema::table( 'suppressions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'lnkup_comms_not_found', __( 'That suppression no longer exists.', 'lnkup-communications' ), array( 'status' => 404 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $table, array( 'id' => $id ) );
		self::audit( 'comms_suppression_removed', $id, array( 'prev' => array( 'scope' => $row['scope'], 'reason' => $row['reason'] ), 'reason' => $note ) );
		return true;
	}

	// ------------------------------------------------------------------ Holdouts.

	/**
	 * Treated vs holdout outcome rate per trigger over the period. Outcome = the recipient took
	 * the trigger's target action within the attribution window after the message was created.
	 * Observed difference only; small groups are flagged rather than interpreted.
	 */
	public static function holdout_report( int $days = 30 ): array {
		global $wpdb;
		$messages = LNKup_Comms_Schema::table( 'messages' );
		$since    = LNKup_Comms_Schema::now( -$days * DAY_IN_SECONDS );
		$window   = max( 1, LNKup_Comms_Settings::int( 'attribution_days' ) ) * DAY_IN_SECONDS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$triggers = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT trigger_key FROM {$messages} WHERE suppression_reason = 'holdout' AND created_at >= %s", $since ) );
		$out      = array();
		foreach ( $triggers as $trigger ) {
			$outcome = self::outcome_sql( (string) $trigger );
			if ( '' === $outcome ) {
				continue;
			}
			$groups = array();
			foreach ( array( 'treated' => "m.status IN ('sent','partial')", 'holdout' => "m.suppression_reason = 'holdout'" ) as $group => $condition ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$total     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT m.recipient_user_id) FROM {$messages} m WHERE m.trigger_key = %s AND m.created_at >= %s AND {$condition}", $trigger, $since ) );
				$converted = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT m.recipient_user_id) FROM {$messages} m WHERE m.trigger_key = %s AND m.created_at >= %s AND {$condition} AND EXISTS ({$outcome})", $trigger, $since, $window ) );
				// phpcs:enable
				$groups[ $group ] = array( 'users' => $total, 'converted' => $converted, 'rate' => $total ? round( $converted / $total, 4 ) : null );
			}
			$out[] = array(
				'trigger'       => (string) $trigger,
				'outcome'       => self::outcome_label( (string) $trigger ),
				'treated'       => $groups['treated'],
				'holdout'       => $groups['holdout'],
				'difference'    => null !== $groups['treated']['rate'] && null !== $groups['holdout']['rate'] ? round( $groups['treated']['rate'] - $groups['holdout']['rate'], 4 ) : null,
				'small_sample'  => $groups['treated']['users'] < 100 || $groups['holdout']['users'] < 100,
			);
		}
		return $out;
	}

	/**
	 * Correlated subquery (uses m.* and one %d window placeholder) that is true when the recipient
	 * took the trigger's target action after the message.
	 */
	private static function outcome_sql( string $trigger ): string {
		global $wpdb;
		$pm    = $wpdb->postmeta;
		$posts = $wpdb->posts;
		$vendor_applied = "SELECT 1 FROM {$posts} a INNER JOIN {$pm} v ON v.post_id = a.ID AND v.meta_key = '_lnkup_vendor_user_id' AND v.meta_value = m.recipient_user_id
			WHERE a.post_type = 'lnkup_application' AND a.post_status NOT IN ('trash','lnkup_app_draft') AND a.post_date_gmt >= m.created_at AND a.post_date_gmt <= DATE_ADD(m.created_at, INTERVAL %d SECOND)";
		$org_invited    = "SELECT 1 FROM {$posts} i WHERE i.post_type = 'lnkup_vendor_invite' AND i.post_author = m.recipient_user_id AND i.post_date_gmt >= m.created_at AND i.post_date_gmt <= DATE_ADD(m.created_at, INTERVAL %d SECOND)";
		$org_created    = "SELECT 1 FROM {$posts} e INNER JOIN {$pm} o ON o.post_id = e.ID AND o.meta_key = '_lnkup_organizer_user_id' AND o.meta_value = m.recipient_user_id
			WHERE e.post_type = 'lnkup_event' AND e.post_date_gmt >= m.created_at AND e.post_date_gmt <= DATE_ADD(m.created_at, INTERVAL %d SECOND)";
		$map            = array(
			'v_opportunity_match'         => $vendor_applied,
			'v_organizer_new_opportunity' => $vendor_applied,
			'v_tour_match'                => $vendor_applied,
			'v_weekly_matches'            => $vendor_applied,
			'v_inactive'                  => $vendor_applied,
			'v_application_draft'         => $vendor_applied,
			'v_onboarding'                => $vendor_applied,
			'o_new_vendors_nearby'        => $org_invited,
			'o_new_matching_vendors'      => $org_invited,
			'o_recruit_vendors'           => $org_invited,
			'o_no_applications'           => $org_invited,
			'o_onboarding'                => $org_created,
			'o_publish_next'              => $org_created,
			'o_reactivation'              => $org_created,
		);
		return $map[ $trigger ] ?? '';
	}

	public static function outcome_label( string $trigger ): string {
		if ( 0 === strpos( $trigger, 'v_' ) ) {
			return __( 'Submitted an application', 'lnkup-communications' );
		}
		if ( in_array( $trigger, array( 'o_onboarding', 'o_publish_next', 'o_reactivation' ), true ) ) {
			return __( 'Created an event', 'lnkup-communications' );
		}
		return __( 'Invited a vendor', 'lnkup-communications' );
	}
}
