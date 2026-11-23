<?php
/**
 * One queue of exceptions for the LNKup team: stuck applications, payment and webhook problems,
 * upcoming events that are not ready, trust and verification queues, failing message delivery,
 * matching health and partner reviews.
 *
 * Every item is read from the system that owns it and links to that system's detail screen;
 * decisions are made there. This class only adds "dismiss until it changes" and "snooze", which
 * are audited. Trust items carry counts and links only, never case narratives or documents.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Ops_Overview {

	const NS             = 'lnkup/v1';
	const DB_OPTION      = 'lnkup_ops_overview_db';
	const DB_VERSION     = '1';
	const REVIEW_SLA_DAYS = 5;
	const INFO_SLA_DAYS  = 7;
	const READINESS_DAYS = 14;
	const SEVERITY_ORDER = array( 'critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3 );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_ops_item_state';
	}

	public static function maybe_install(): void {
		if ( self::DB_VERSION === (string) get_option( self::DB_OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				item_key varchar(120) NOT NULL,
				state varchar(16) NOT NULL DEFAULT '',
				fingerprint varchar(64) NOT NULL DEFAULT '',
				until_at datetime NULL,
				note varchar(255) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (item_key)
			) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public static function register_routes(): void {
		$admin = static function () {
			return LNKup_Capabilities::user_has_manage_all();
		};
		register_rest_route( self::NS, '/admin/ops/queue', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_queue' ), 'permission_callback' => $admin ) );
		register_rest_route(
			self::NS,
			'/admin/ops/queue/state',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_state' ),
				'permission_callback' => $admin,
				'args'                => array(
					'key'   => array( 'type' => 'string', 'required' => true ),
					'state' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'dismissed', 'snoozed', 'clear' ) ),
					'hours' => array( 'type' => 'integer' ),
					'note'  => array( 'type' => 'string' ),
				),
			)
		);
	}

	public static function rest_queue( WP_REST_Request $request ) {
		return rest_ensure_response( self::queue( ! empty( $request->get_param( 'include_hidden' ) ) ) );
	}

	public static function rest_state( WP_REST_Request $request ) {
		$result = self::set_state(
			(string) $request->get_param( 'key' ),
			(string) $request->get_param( 'state' ),
			absint( $request->get_param( 'hours' ) ),
			(string) $request->get_param( 'note' ),
			get_current_user_id()
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( self::queue() );
	}

	/**
	 * The queue: open items sorted by severity then age.
	 *
	 * @param bool $include_hidden Also return dismissed and snoozed items.
	 * @return array{generated_at:string, items:array, hidden:int, counts:array}
	 */
	public static function queue( bool $include_hidden = false ): array {
		self::maybe_install();
		$items = array();
		foreach ( array( 'stuck_applications', 'payments', 'readiness', 'trust', 'communications', 'matching', 'partners' ) as $source ) {
			try {
				$items = array_merge( $items, call_user_func( array( __CLASS__, 'source_' . $source ) ) );
			} catch ( Throwable $e ) {
				$items[] = self::item( 'source_error:' . $source, $source, 'high', __( 'This check could not run', 'lnkup-marketplace' ), $e->getMessage(), 0, null, array(), array() );
			}
		}
		$states = self::states();
		$now    = time();
		$hidden = 0;
		$out    = array();
		foreach ( $items as $item ) {
			$state = $states[ $item['key'] ] ?? null;
			$item['state'] = '';
			if ( $state ) {
				$snoozed   = 'snoozed' === $state['state'] && $state['until_at'] && strtotime( $state['until_at'] . ' UTC' ) > $now;
				$dismissed = 'dismissed' === $state['state'] && $state['fingerprint'] === $item['fingerprint'];
				if ( $snoozed || $dismissed ) {
					++$hidden;
					if ( ! $include_hidden ) {
						continue;
					}
					$item['state'] = $state['state'];
				}
			}
			unset( $item['fingerprint'] );
			$out[] = $item;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return array( self::SEVERITY_ORDER[ $a['severity'] ] ?? 9, -$a['age_hours'] ) <=> array( self::SEVERITY_ORDER[ $b['severity'] ] ?? 9, -$b['age_hours'] );
			}
		);
		$counts = array_fill_keys( array_keys( self::SEVERITY_ORDER ), 0 );
		foreach ( $out as $item ) {
			if ( '' === $item['state'] ) {
				++$counts[ $item['severity'] ];
			}
		}
		return array(
			'generated_at' => gmdate( 'c' ),
			'items'        => $out,
			'hidden'       => $hidden,
			'counts'       => $counts,
		);
	}

	/**
	 * Dismiss (until the item changes), snooze (for N hours) or clear. Audited.
	 *
	 * @return true|WP_Error
	 */
	public static function set_state( string $key, string $state, int $hours, string $note, int $user_id ) {
		global $wpdb;
		self::maybe_install();
		$key = substr( sanitize_text_field( $key ), 0, 120 );
		if ( '' === $key || ! in_array( $state, array( 'dismissed', 'snoozed', 'clear' ), true ) ) {
			return new WP_Error( 'lnkup_ops_bad_state', __( 'Choose dismiss, snooze or clear.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( 'clear' === $state ) {
			$wpdb->delete( self::table(), array( 'item_key' => $key ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$fingerprint = '';
			if ( 'dismissed' === $state ) {
				$fingerprint = self::current_fingerprint( $key );
				if ( '' === $fingerprint ) {
					return new WP_Error( 'lnkup_ops_not_found', __( 'That item is no longer in the queue.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
				}
			}
			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				array(
					'item_key'    => $key,
					'state'       => $state,
					'fingerprint' => $fingerprint,
					'until_at'    => 'snoozed' === $state ? gmdate( 'Y-m-d H:i:s', time() + max( 1, min( 24 * 14, $hours ?: 24 ) ) * HOUR_IN_SECONDS ) : null,
					'note'        => mb_substr( sanitize_text_field( $note ), 0, 255 ),
					'user_id'     => $user_id,
					'updated_at'  => current_time( 'mysql', true ),
				)
			);
		}
		if ( class_exists( 'LNKup_Audit_Log' ) ) {
			LNKup_Audit_Log::log( 'ops_item_' . $state, 'ops_item', 0, array( 'new' => array( 'key' => $key, 'hours' => $hours ), 'reason' => sanitize_text_field( $note ), 'actor_user_id' => $user_id ) );
		}
		return true;
	}

	/**
	 * Fingerprint of an item as it is right now ('' when it is not in the queue).
	 */
	private static function current_fingerprint( string $key ): string {
		foreach ( array( 'stuck_applications', 'payments', 'readiness', 'trust', 'communications', 'matching', 'partners' ) as $source ) {
			foreach ( call_user_func( array( __CLASS__, 'source_' . $source ) ) as $item ) {
				if ( $item['key'] === $key ) {
					return $item['fingerprint'];
				}
			}
		}
		return '';
	}

	/**
	 * @return array<string, array{state:string, fingerprint:string, until_at:?string}>
	 */
	private static function states(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( 'SELECT item_key, state, fingerprint, until_at FROM ' . self::table(), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['item_key'] ] = $row;
		}
		return $out;
	}

	/**
	 * Build one queue item. The fingerprint changes when the underlying issue changes, so a
	 * dismissed item comes back if it gets worse or a new case appears.
	 *
	 * @param array|null $owner  {id, name}.
	 * @param array      $refs   event_id, application_id, payment_id.
	 * @param array      $action {label, url}.
	 */
	private static function item( string $key, string $source, string $severity, string $title, string $detail, float $age_hours, ?array $owner, array $refs, array $action, int $count = 1 ): array {
		return array(
			'key'         => $key,
			'source'      => $source,
			'severity'    => $severity,
			'title'       => $title,
			'detail'      => $detail,
			'age_hours'   => round( max( 0, $age_hours ), 1 ),
			'count'       => $count,
			'owner'       => $owner,
			'refs'        => array_merge( array( 'event_id' => 0, 'application_id' => 0, 'payment_id' => 0 ), $refs ),
			'action'      => $action,
			'fingerprint' => md5( $severity . '|' . $count . '|' . $title ),
		);
	}

	private static function hours_since( string $mysql_local ): float {
		$ts = strtotime( get_gmt_from_date( $mysql_local ) . ' UTC' );
		return $ts ? ( time() - $ts ) / HOUR_IN_SECONDS : 0.0;
	}

	private static function owner( int $user_id ): ?array {
		$user = $user_id ? get_userdata( $user_id ) : null;
		return $user ? array( 'id' => (int) $user_id, 'name' => (string) $user->display_name ) : null;
	}

	// ------------------------------------------------------------------ Sources.

	/**
	 * Applications waiting on someone, grouped per event and kind so one busy event is one item.
	 */
	private static function source_stuck_applications(): array {
		global $wpdb;
		$excluded = class_exists( 'LNKup_Analytics_Exclusions' ) ? LNKup_Analytics_Exclusions::user_ids() : array();
		$rows     = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT p.ID, p.post_date, s.meta_value AS status, e.meta_value AS event_id, o.meta_value AS organizer_id, v.meta_value AS vendor_id
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_application_status'
			INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_lnkup_event_id'
			LEFT JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_organizer_user_id'
			LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_lnkup_vendor_user_id'
			WHERE p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft')
			AND s.meta_value IN ('submitted','under_review','payment_required','info_requested')
			ORDER BY p.post_date ASC LIMIT 2000",
			ARRAY_A
		);
		$groups = array();
		$now    = time();
		foreach ( $rows as $row ) {
			if ( in_array( (int) $row['vendor_id'], $excluded, true ) || in_array( (int) $row['organizer_id'], $excluded, true ) ) {
				continue;
			}
			$app_id = (int) $row['ID'];
			$kind   = '';
			$age    = 0.0;
			switch ( $row['status'] ) {
				case 'submitted':
				case 'under_review':
					$age  = self::hours_since( (string) $row['post_date'] );
					$kind = $age >= self::REVIEW_SLA_DAYS * 24 ? 'review_overdue' : '';
					break;
				case 'payment_required':
					$due = (string) get_post_meta( $app_id, '_lnkup_payment_due_at', true );
					$ts  = $due ? strtotime( $due . ' UTC' ) : 0;
					if ( $ts && $ts < $now ) {
						$kind = 'unpaid_past_due';
						$age  = ( $now - $ts ) / HOUR_IN_SECONDS;
					}
					break;
				case 'info_requested':
					$asked = '';
					foreach ( array_reverse( (array) get_post_meta( $app_id, LNKup_Applications::HISTORY_META, true ) ) as $entry ) {
						if ( 'info_requested' === ( $entry['to'] ?? '' ) ) {
							$asked = (string) ( $entry['at'] ?? '' );
							break;
						}
					}
					$age  = $asked ? self::hours_since( $asked ) : self::hours_since( (string) $row['post_date'] );
					$kind = $age >= self::INFO_SLA_DAYS * 24 ? 'info_unanswered' : '';
					break;
			}
			if ( '' === $kind ) {
				continue;
			}
			$group_key = $kind . ':' . (int) $row['event_id'];
			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = array( 'kind' => $kind, 'event_id' => (int) $row['event_id'], 'organizer' => (int) $row['organizer_id'], 'count' => 0, 'oldest' => 0.0, 'oldest_app' => $app_id );
			}
			++$groups[ $group_key ]['count'];
			if ( $age > $groups[ $group_key ]['oldest'] ) {
				$groups[ $group_key ]['oldest']     = $age;
				$groups[ $group_key ]['oldest_app'] = $app_id;
			}
		}
		$labels = array(
			/* translators: 1: count, 2: event */
			'review_overdue'  => array( 'medium', __( '%1$d applications waiting more than five days for review at %2$s', 'lnkup-marketplace' ), __( 'The organizer has not decided. Nudge them or review on their behalf.', 'lnkup-marketplace' ) ),
			/* translators: 1: count, 2: event */
			'unpaid_past_due' => array( 'high', __( '%1$d approved vendors past their payment deadline at %2$s', 'lnkup-marketplace' ), __( 'The spot is held but unpaid. Remind the vendor or release the spot.', 'lnkup-marketplace' ) ),
			/* translators: 1: count, 2: event */
			'info_unanswered' => array( 'low', __( '%1$d information requests unanswered for a week at %2$s', 'lnkup-marketplace' ), __( 'The vendor has not replied to the organizer\'s question.', 'lnkup-marketplace' ) ),
		);
		$items = array();
		foreach ( $groups as $key => $g ) {
			list( $severity, $title, $detail ) = $labels[ $g['kind'] ];
			$items[] = self::item(
				'stuck:' . $key,
				'applications',
				$severity,
				sprintf( $title, $g['count'], get_the_title( $g['event_id'] ) ?: __( 'an event', 'lnkup-marketplace' ) ),
				$detail,
				$g['oldest'],
				self::owner( $g['organizer'] ),
				array( 'event_id' => $g['event_id'], 'application_id' => $g['oldest_app'] ),
				array( 'label' => __( 'Open the oldest application', 'lnkup-marketplace' ), 'url' => admin_url( 'post.php?post=' . (int) $g['oldest_app'] . '&action=edit' ) ),
				$g['count']
			);
		}
		return $items;
	}

	/**
	 * Hourly reconciliation errors or a reconciliation that stopped running, and webhooks that
	 * were never processed.
	 */
	private static function source_payments(): array {
		global $wpdb;
		$items  = array();
		$health = class_exists( 'LNKup_Financial_Cron' ) ? get_option( LNKup_Financial_Cron::HEALTH_OPTION, array() ) : array();
		$recon  = array( 'label' => __( 'Open reconciliation', 'lnkup-marketplace' ), 'url' => admin_url( 'admin.php?page=lnkup-financial-reconciliation' ) );
		if ( is_array( $health ) && ! empty( $health['errors'] ) ) {
			$items[] = self::item( 'payments:reconcile_errors', 'payments', 'critical', __( 'The hourly payment reconciliation reported errors', 'lnkup-marketplace' ), implode( ' · ', array_slice( array_map( 'sanitize_text_field', array_map( 'strval', (array) $health['errors'] ) ), 0, 3 ) ), self::hours_since( (string) ( $health['ran_at'] ?? current_time( 'mysql' ) ) ), null, array(), $recon, count( (array) $health['errors'] ) );
		}
		$ran = is_array( $health ) ? (string) ( $health['ran_at'] ?? '' ) : '';
		if ( '' === $ran || self::hours_since( $ran ) > 3 ) {
			$items[] = self::item( 'payments:reconcile_stale', 'payments', 'high', __( 'Payment reconciliation has not run in the last three hours', 'lnkup-marketplace' ), __( 'Payments may be confirmed in Stripe but not yet in LNKup. Check WP-Cron.', 'lnkup-marketplace' ), '' === $ran ? 0 : self::hours_since( $ran ), null, array(), $recon );
		}
		if ( class_exists( 'LNKup_Financial_Tables' ) ) {
			$table = LNKup_Financial_Tables::webhook_events_table();
			if ( LNKup_Financial_Tables::table_exists( $table ) ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, SUM(status = 'failed') AS failed, MIN(created_at) AS oldest FROM {$table} WHERE status NOT IN ('processed','ignored') AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
				$n   = (int) ( $row['n'] ?? 0 );
				if ( $n > 0 ) {
					$items[] = self::item( 'payments:webhooks_unprocessed', 'payments', (int) $row['failed'] > 0 ? 'high' : 'medium', sprintf( /* translators: %d: count */ _n( '%d Stripe event was not processed', '%d Stripe events were not processed', $n, 'lnkup-marketplace' ), $n ), __( 'Failed events retry up to five times; anything left here needs a look.', 'lnkup-marketplace' ), self::hours_since( (string) $row['oldest'] ), null, array(), $recon, $n );
				}
			}
		}
		return $items;
	}

	/**
	 * Events in the next two weeks whose readiness checklist is not complete.
	 */
	private static function source_readiness(): array {
		if ( ! class_exists( 'LNKup_Event_Readiness' ) || ! LNKup_Feature_Flags::organizer_os_anywhere( LNKup_Feature_Flags::ORGANIZER_OPS ) ) {
			return array();
		}
		$today  = current_time( 'Y-m-d' );
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_event_date', 'value' => array( $today, gmdate( 'Y-m-d', strtotime( $today . ' +' . self::READINESS_DAYS . ' days' ) ) ), 'compare' => 'BETWEEN', 'type' => 'DATE' ),
					array( 'key' => '_lnkup_event_status', 'value' => array( 'cancelled', 'postponed', 'draft' ), 'compare' => 'NOT IN' ),
				),
			)
		);
		$items = array();
		foreach ( $events as $event_id ) {
			$readiness = LNKup_Event_Readiness::evaluate( (int) $event_id );
			if ( (int) $readiness['percent'] >= 100 ) {
				continue;
			}
			$days    = max( 0, (int) $readiness['days_until'] );
			$next    = array_column( (array) $readiness['next_actions'], 'label' );
			$items[] = self::item(
				'readiness:' . (int) $event_id,
				'readiness',
				$days <= 3 ? 'high' : 'medium',
				/* translators: 1: event, 2: percent, 3: days */
				sprintf( __( '%1$s is %2$d%% ready with %3$d days to go', 'lnkup-marketplace' ), get_the_title( $event_id ), (int) $readiness['percent'], $days ),
				implode( ' · ', $next ),
				0,
				self::owner( (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ) ),
				array( 'event_id' => (int) $event_id ),
				array( 'label' => __( 'Open the event', 'lnkup-marketplace' ), 'url' => admin_url( 'post.php?post=' . (int) $event_id . '&action=edit' ) ),
				max( 1, 100 - (int) $readiness['percent'] )
			);
		}
		return $items;
	}

	/**
	 * Trust queues as counts and links only; case details stay in Trust & Safety.
	 */
	private static function source_trust(): array {
		global $wpdb;
		$items = array();
		$url   = static fn( string $tab ): array => array( 'label' => __( 'Open Trust & Safety', 'lnkup-marketplace' ), 'url' => admin_url( 'admin.php?page=lnkup-trust-safety&tab=' . $tab ) );
		if ( class_exists( 'LNKup_Organizer_Verification' ) ) {
			$n = (int) LNKup_Organizer_Verification::count_pending();
			if ( $n ) {
				$items[] = self::item( 'trust:verification', 'trust', 'medium', sprintf( /* translators: %d: count */ _n( '%d organizer is waiting for verification', '%d organizers are waiting for verification', $n, 'lnkup-marketplace' ), $n ), '', 0, null, array(), $url( 'organizers' ), $n );
			}
		}
		if ( class_exists( 'LNKup_Event_Claims' ) ) {
			$counts = (array) LNKup_Event_Claims::count_by_status();
			$n      = (int) ( $counts['disputed'] ?? 0 );
			if ( $n ) {
				$oldest  = class_exists( 'LNKup_Trust_Tables' ) ? (string) $wpdb->get_var( 'SELECT MIN(created_at) FROM ' . LNKup_Trust_Tables::claim_evidence_table() . " WHERE status = 'disputed'" ) : ''; // phpcs:ignore WordPress.DB
				$items[] = self::item( 'trust:claims_disputed', 'trust', 'high', sprintf( /* translators: %d: count */ _n( '%d event claim is disputed', '%d event claims are disputed', $n, 'lnkup-marketplace' ), $n ), '', $oldest ? self::hours_since( $oldest ) : 0, null, array(), $url( 'events' ), $n );
			}
		}
		if ( class_exists( 'LNKup_Trust_Cases' ) ) {
			$open     = (array) LNKup_Trust_Cases::open_counts();
			$total    = (int) ( $open['total'] ?? 0 );
			$critical = (int) ( $open['by_severity']['critical'] ?? 0 );
			if ( $total ) {
				$items[] = self::item( 'trust:cases', 'trust', $critical ? 'critical' : 'medium', sprintf( /* translators: 1: open cases, 2: critical */ __( '%1$d open trust cases (%2$d critical)', 'lnkup-marketplace' ), $total, $critical ), '', 0, null, array(), $url( 'cases' ), $total );
			}
		}
		return $items;
	}

	/**
	 * Communications health checks that are failing or warning.
	 */
	private static function source_communications(): array {
		if ( ! class_exists( 'LNKup_Comms_Health' ) ) {
			return array();
		}
		$items = array();
		foreach ( (array) LNKup_Comms_Health::checks() as $check ) {
			$status = (string) ( $check['status'] ?? 'ok' );
			if ( 'ok' === $status ) {
				continue;
			}
			$items[] = self::item(
				'comms:' . sanitize_key( (string) $check['key'] ),
				'communications',
				'error' === $status ? 'high' : 'low',
				(string) $check['label'],
				wp_strip_all_tags( (string) ( $check['detail'] ?? '' ) ),
				0,
				null,
				array(),
				array( 'label' => __( 'Open message health', 'lnkup-marketplace' ), 'url' => admin_url( 'admin.php?page=lnkup-communications&tab=health' ) )
			);
		}
		return $items;
	}

	private static function source_matching(): array {
		if ( ! class_exists( 'LNKup_Match_Cron' ) ) {
			return array();
		}
		$items = array();
		foreach ( (array) LNKup_Match_Cron::health_issues() as $issue ) {
			$items[] = self::item(
				'matching:' . sanitize_key( (string) $issue['code'] ),
				'matching',
				'medium',
				(string) ( $issue['detail'] ?? '' ) ?: __( 'Matching needs attention', 'lnkup-marketplace' ),
				__( 'Recommendations and alerts may be out of date.', 'lnkup-marketplace' ),
				0,
				null,
				array(),
				array( 'label' => __( 'Open matching health', 'lnkup-marketplace' ), 'url' => admin_url( 'admin.php?page=lnkup-marketplace-intelligence&tab=rollout' ) )
			);
		}
		return $items;
	}

	/**
	 * Partner attribution conflicts and clawbacks, and rewards on hold.
	 */
	private static function source_partners(): array {
		global $wpdb;
		$items = array();
		if ( class_exists( 'LNKup_Partner_Reviews' ) ) {
			$n = (int) LNKup_Partner_Reviews::open_count();
			if ( $n ) {
				$oldest  = (string) $wpdb->get_var( 'SELECT MIN(created_at) FROM ' . LNKup_Partner_Reviews::table() . " WHERE status = 'open'" ); // phpcs:ignore WordPress.DB
				$items[] = self::item( 'partners:reviews', 'partners', 'medium', sprintf( /* translators: %d: count */ _n( '%d partner commission needs a decision', '%d partner commissions need a decision', $n, 'lnkup-marketplace' ), $n ), __( 'Attribution conflicts and clawbacks on refunded bookings.', 'lnkup-marketplace' ), $oldest ? self::hours_since( $oldest ) : 0, null, array(), array( 'label' => __( 'Open partner reviews', 'lnkup-marketplace' ), 'url' => admin_url( 'admin.php?page=lnkup-partnerships&tab=reviews' ) ), $n );
			}
		}
		if ( class_exists( 'LNKup_Partnerships' ) ) {
			$held = (int) ( LNKup_Partnerships::ledger( array( 'status' => 'on_hold', 'per_page' => 1 ) )['total'] ?? 0 );
			if ( $held ) {
				$items[] = self::item( 'partners:holds', 'partners', 'low', sprintf( /* translators: %d: count */ _n( '%d partner reward is on hold', '%d partner rewards are on hold', $held, 'lnkup-marketplace' ), $held ), '', 0, null, array(), array( 'label' => __( 'Open the partner ledger', 'lnkup-marketplace' ), 'url' => admin_url( 'admin.php?page=lnkup-partnerships&tab=ledger' ) ), $held );
			}
		}
		return $items;
	}
}
