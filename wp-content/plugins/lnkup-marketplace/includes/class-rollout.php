<?php
/**
 * Staged audience rollout for feature flags (marketplace + communications).
 *
 * A flag's own on/off setting still decides whether the feature is active at all (kill switch).
 * The rollout entry only narrows who sees it while it ramps:
 *
 * - audience "all":    everyone (default when no entry exists, so behavior is unchanged).
 * - audience "cohort": admins, the allowlist, and a stable percentage of users.
 *
 * Percentages use a bucket per group, so one vendor sees recommendations, tours and match alerts
 * together rather than a random mix.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Rollout {

	const OPTION     = 'lnkup_rollout';
	const LOG_OPTION = 'lnkup_rollout_log';

	/** Flags that support a staged audience, mapped to their bucket group and owning plugin. */
	const FLAGS = array(
		'matching_vendor_discovery_enabled'    => array( 'group' => 'matching_vendor', 'source' => 'marketplace', 'label' => 'Vendor recommendations' ),
		'matching_tours_enabled'               => array( 'group' => 'matching_vendor', 'source' => 'marketplace', 'label' => 'Tours & series' ),
		'matching_organizer_discovery_enabled' => array( 'group' => 'matching_organizer', 'source' => 'marketplace', 'label' => 'Find Vendors (organizers)' ),
		'apply_wizard_enabled'                 => array( 'group' => 'apply', 'source' => 'marketplace', 'label' => 'Apply drafts & questions' ),
		'tour_multi_apply_enabled'             => array( 'group' => 'apply', 'source' => 'marketplace', 'label' => 'Multi-stop apply' ),
		'flow_discovery'                       => array( 'group' => 'matching_vendor', 'source' => 'comms', 'label' => 'Match alerts' ),
		'flow_discovery_tours'                 => array( 'group' => 'matching_vendor', 'source' => 'comms', 'label' => 'Tour match alerts' ),
		'flow_discovery_weekly'                => array( 'group' => 'matching_vendor', 'source' => 'comms', 'label' => 'Weekly top matches' ),
		'flow_recruitment'                     => array( 'group' => 'matching_organizer', 'source' => 'comms', 'label' => 'Recruitment nudges' ),
		'flow_recruitment_updates'             => array( 'group' => 'matching_organizer', 'source' => 'comms', 'label' => 'Open spots & new matching vendors' ),
	);

	/** Recommended ramp. "internal" = allowlist and admins only. */
	const STAGES = array(
		'internal' => 0,
		'p5'       => 5,
		'p25'      => 25,
		'p50'      => 50,
		'all'      => 100,
	);

	/**
	 * Register the WP-CLI command.
	 */
	public static function init() {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'LNKup_Rollout_CLI' ) ) {
			WP_CLI::add_command( 'lnkup rollout', 'LNKup_Rollout_CLI' );
		}
	}

	public static function is_rollout_flag( $flag ) {
		return isset( self::FLAGS[ (string) $flag ] );
	}

	/**
	 * Audience config for a flag (defaults to everyone).
	 *
	 * @param string $flag Flag key.
	 * @return array{audience:string,percent:int,user_ids:int[],stage:string,updated_at:string,updated_by:int,note:string,stage_since:string}
	 */
	public static function config( $flag ) {
		$all   = get_option( self::OPTION, array() );
		$entry = is_array( $all ) && isset( $all[ $flag ] ) && is_array( $all[ $flag ] ) ? $all[ $flag ] : array();
		$audience = 'cohort' === ( $entry['audience'] ?? '' ) ? 'cohort' : 'all';
		$percent  = max( 0, min( 100, (int) ( $entry['percent'] ?? 0 ) ) );
		return array(
			'audience'    => $audience,
			'percent'     => $percent,
			'user_ids'    => array_values( array_filter( array_map( 'absint', (array) ( $entry['user_ids'] ?? array() ) ) ) ),
			'stage'       => 'all' === $audience ? 'all' : self::stage_for_percent( $percent ),
			'updated_at'  => (string) ( $entry['updated_at'] ?? '' ),
			'updated_by'  => (int) ( $entry['updated_by'] ?? 0 ),
			'note'        => (string) ( $entry['note'] ?? '' ),
			'stage_since' => (string) ( $entry['stage_since'] ?? '' ),
		);
	}

	/**
	 * Update a flag's audience. Every change is logged with who, when and why.
	 *
	 * @param string $flag    Flag key.
	 * @param array  $changes audience, percent, user_ids (array or comma string), stage.
	 * @param string $note    Reason (shown in history).
	 * @return array|WP_Error New config.
	 */
	public static function set( $flag, array $changes, $note = '' ) {
		if ( ! self::is_rollout_flag( $flag ) ) {
			return new WP_Error( 'lnkup_rollout_unknown_flag', __( 'This flag does not support staged rollout.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$before = self::config( $flag );
		$after  = $before;
		if ( isset( $changes['stage'] ) && isset( self::STAGES[ $changes['stage'] ] ) ) {
			$after['audience'] = 'all' === $changes['stage'] ? 'all' : 'cohort';
			$after['percent']  = self::STAGES[ $changes['stage'] ];
		}
		if ( isset( $changes['audience'] ) ) {
			$after['audience'] = 'cohort' === $changes['audience'] ? 'cohort' : 'all';
		}
		if ( isset( $changes['percent'] ) ) {
			$after['percent'] = max( 0, min( 100, (int) $changes['percent'] ) );
		}
		if ( isset( $changes['user_ids'] ) ) {
			$ids               = is_array( $changes['user_ids'] ) ? $changes['user_ids'] : explode( ',', (string) $changes['user_ids'] );
			$after['user_ids'] = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		}
		$after['stage'] = 'all' === $after['audience'] ? 'all' : self::stage_for_percent( $after['percent'] );
		$now            = current_time( 'mysql' );
		if ( $after['stage'] !== $before['stage'] || '' === $before['stage_since'] ) {
			$after['stage_since'] = $now;
		}
		$after['updated_at'] = $now;
		$after['updated_by'] = get_current_user_id();
		$after['note']       = sanitize_text_field( (string) $note );

		$all          = get_option( self::OPTION, array() );
		$all          = is_array( $all ) ? $all : array();
		$all[ $flag ] = $after;
		update_option( self::OPTION, $all, false );

		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'flag'    => $flag,
				'user_id' => get_current_user_id(),
				'at'      => $now,
				'note'    => $after['note'],
				'before'  => array_intersect_key( $before, array_flip( array( 'audience', 'percent', 'user_ids' ) ) ),
				'after'   => array_intersect_key( $after, array_flip( array( 'audience', 'percent', 'user_ids' ) ) ),
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, 200 ), false );
		do_action( 'lnkup_rollout_changed', $flag, $after, $before );
		return $after;
	}

	/**
	 * Stable bucket 0-99 for a user within a group.
	 *
	 * @param string $group   Bucket group.
	 * @param int    $user_id User ID.
	 */
	public static function bucket( $group, $user_id ) {
		return (int) ( sprintf( '%u', crc32( $group . ':' . (int) $user_id ) ) % 100 );
	}

	/**
	 * Whether a user is in a flag's audience (does not check the flag's own on/off switch).
	 *
	 * @param string   $flag    Flag key.
	 * @param int|null $user_id Null = current user; 0 = system context (cron, CLI, background jobs).
	 */
	public static function in_audience( $flag, $user_id = null ) {
		if ( ! self::is_rollout_flag( $flag ) ) {
			return true;
		}
		$config = self::config( $flag );
		if ( 'all' === $config['audience'] ) {
			return true;
		}
		$system = 0 === $user_id;
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
			// No signed-in user: background work stays active, anonymous web visitors wait for "all".
			$system = ! $user_id && (bool) apply_filters( 'lnkup_rollout_system_context', wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) );
		}
		if ( $system ) {
			return true;
		}
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return false;
		}
		$included = in_array( $user_id, $config['user_ids'], true )
			|| ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all( $user_id ) )
			|| self::bucket( self::FLAGS[ $flag ]['group'], $user_id ) < $config['percent'];
		return (bool) apply_filters( 'lnkup_rollout_in_audience', $included, $flag, $user_id, $config );
	}

	/**
	 * A boolean flag stored in lnkup_settings, narrowed to its rollout audience.
	 *
	 * @param string   $flag    Settings key.
	 * @param int|null $user_id See in_audience().
	 */
	public static function enabled( $flag, $user_id = null ) {
		$settings = lnkup_get_settings();
		return ! empty( $settings[ $flag ] ) && self::in_audience( $flag, $user_id );
	}

	/**
	 * Whether a flag is switched on at all (any audience).
	 *
	 * @param string $flag Flag key.
	 */
	public static function active( $flag ) {
		$meta = self::FLAGS[ $flag ] ?? null;
		if ( ! $meta ) {
			return false;
		}
		if ( 'comms' === $meta['source'] ) {
			return class_exists( 'LNKup_Comms_Settings' ) && LNKup_Comms_Settings::enabled( $flag );
		}
		if ( 0 === strpos( $flag, 'matching_' ) ) {
			return class_exists( 'LNKup_Match_Settings' ) && LNKup_Match_Settings::active( $flag );
		}
		return ! empty( lnkup_get_settings()[ $flag ] );
	}

	/**
	 * Closest named stage for a cohort percentage.
	 *
	 * @param int $percent Percent.
	 */
	public static function stage_for_percent( $percent ) {
		$stage = 'internal';
		foreach ( self::STAGES as $key => $value ) {
			if ( 'all' !== $key && (int) $percent >= $value ) {
				$stage = $key;
			}
		}
		return $stage;
	}

	/**
	 * Next stage after the current one, or '' at the end.
	 *
	 * @param string $stage Stage key.
	 */
	public static function next_stage( $stage ) {
		$keys = array_keys( self::STAGES );
		$i    = array_search( $stage, $keys, true );
		return false === $i || ! isset( $keys[ $i + 1 ] ) ? '' : $keys[ $i + 1 ];
	}

	const MIN_DAYS_AT_STAGE       = 3;
	const MAX_UNGEOCODED_SHARE    = 0.20;
	const MAX_UNCATEGORIZED_SHARE = 0.10;
	const MAX_UNSUBSCRIBE_RATE    = 0.02;

	/**
	 * Checks before widening a flag's audience. Every gate can be overridden with a note.
	 *
	 * @param string $flag   Flag key.
	 * @param string $target Target stage key.
	 * @return array<int,array{key:string,label:string,ok:bool,detail:string}>
	 */
	public static function gates( $flag, $target ) {
		$meta    = self::FLAGS[ $flag ] ?? null;
		$config  = self::config( $flag );
		$gates   = array();
		$widens  = self::stage_rank( $target ) > self::stage_rank( $config['stage'] ) && 'all' !== $config['stage'];
		if ( ! $meta ) {
			return $gates;
		}
		$gates[] = self::gate( 'active', __( 'Feature is switched on', 'lnkup-marketplace' ), self::active( $flag ), __( 'Turn the feature on first; the audience only narrows who sees it.', 'lnkup-marketplace' ) );

		$matching = 0 === strpos( $meta['group'], 'matching' ) || 'tour_multi_apply_enabled' === $flag;
		if ( $matching && class_exists( 'LNKup_Match_Cron' ) ) {
			$health = LNKup_Match_Cron::health();
			$gates[] = self::gate( 'backfill', __( 'Vendor index backfill complete', 'lnkup-marketplace' ), ! $health['backfill']['running'], sprintf( __( 'Backfill at %s%%.', 'lnkup-marketplace' ), (string) ( $health['backfill']['percent'] ?? 0 ) ) );
			$fresh   = null === $health['queue_oldest_minutes'] || $health['queue_oldest_minutes'] <= LNKup_Match_Cron::STALE_QUEUE_MINUTES;
			$gates[] = self::gate( 'queue', __( 'Recompute queue is fresh and cron is running', 'lnkup-marketplace' ), $fresh && (bool) $health['next_daily_run'] && ! LNKup_Match_Cron::health_issues(), implode( ' ', wp_list_pluck( LNKup_Match_Cron::health_issues(), 'detail' ) ) );
			$quality = self::data_quality();
			$gates[] = self::gate(
				'data_quality',
				__( 'Data quality thresholds', 'lnkup-marketplace' ),
				$quality['ungeocoded_share'] <= self::MAX_UNGEOCODED_SHARE && $quality['uncategorized_share'] <= self::MAX_UNCATEGORIZED_SHARE,
				sprintf( __( '%1$s%% of vendors without a map location (max 20%%), %2$s%% of opportunities without categories (max 10%%).', 'lnkup-marketplace' ), round( 100 * $quality['ungeocoded_share'] ), round( 100 * $quality['uncategorized_share'] ) )
			);
		}
		if ( 'comms' === $meta['source'] ) {
			$days    = self::transactional_send_days();
			$gates[] = self::gate( 'transactional', __( 'Transactional messages have been sending for 7+ days', 'lnkup-marketplace' ), $days >= 7, sprintf( __( 'Sent on %d of the last 14 days.', 'lnkup-marketplace' ), $days ) );
			$rate    = self::unsubscribe_rate();
			$gates[] = self::gate( 'unsubscribes', __( 'No unsubscribe or complaint spike in the last 3 days', 'lnkup-marketplace' ), $rate['ok'], sprintf( __( '%1$d unsubscribes/complaints vs. %2$d promotional messages sent.', 'lnkup-marketplace' ), $rate['suppressions'], $rate['sent'] ) );
		}
		if ( $widens ) {
			$since   = $config['stage_since'] ? strtotime( $config['stage_since'] ) : 0;
			$days_at = $since ? (int) floor( ( current_time( 'timestamp' ) - $since ) / DAY_IN_SECONDS ) : 0; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			$gates[] = self::gate( 'time_at_stage', sprintf( __( 'At the current stage for %d+ days', 'lnkup-marketplace' ), self::MIN_DAYS_AT_STAGE ), $days_at >= self::MIN_DAYS_AT_STAGE, sprintf( __( '%d days so far.', 'lnkup-marketplace' ), $days_at ) );
		}
		return $gates;
	}

	/**
	 * Move a flag to a stage. Failing gates block unless an override note is given.
	 *
	 * @param string $flag          Flag key.
	 * @param string $stage         Target stage.
	 * @param string $note          Reason.
	 * @param bool   $override      Proceed despite failing gates (note required).
	 * @return array|WP_Error New config.
	 */
	public static function advance( $flag, $stage, $note = '', $override = false ) {
		if ( ! isset( self::STAGES[ $stage ] ) ) {
			return new WP_Error( 'lnkup_rollout_stage', __( 'Unknown rollout stage.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$current = self::config( $flag )['stage'];
		$widens  = 'all' !== $current && self::stage_rank( $stage ) > self::stage_rank( $current );
		// Narrowing (or rolling back) is always allowed: it is the safe direction.
		$failing = ! $widens ? array() : array_values(
			array_filter(
				self::gates( $flag, $stage ),
				static function ( $gate ) {
					return ! $gate['ok'];
				}
			)
		);
		if ( $failing && ( ! $override || '' === trim( (string) $note ) ) ) {
			return new WP_Error(
				'lnkup_rollout_gates',
				sprintf( __( 'Checks not met: %s. Add a note and choose override to continue anyway.', 'lnkup-marketplace' ), implode( ', ', wp_list_pluck( $failing, 'label' ) ) ),
				array( 'status' => 409, 'failing' => wp_list_pluck( $failing, 'key' ) )
			);
		}
		$label = $failing ? sprintf( '[override: %s] ', implode( ',', wp_list_pluck( $failing, 'key' ) ) ) : '';
		return self::set( $flag, array( 'stage' => $stage ), $label . $note );
	}

	private static function stage_rank( $stage ) {
		$i = array_search( $stage, array_keys( self::STAGES ), true );
		return false === $i ? 0 : (int) $i;
	}

	private static function gate( $key, $label, $ok, $detail ) {
		return array( 'key' => $key, 'label' => $label, 'ok' => (bool) $ok, 'detail' => (string) $detail );
	}

	/**
	 * Shares of active vendors without coordinates and of indexed opportunities without categories.
	 *
	 * @return array{ungeocoded_share:float,uncategorized_share:float}
	 */
	public static function data_quality() {
		global $wpdb;
		$out = array( 'ungeocoded_share' => 0.0, 'uncategorized_share' => 0.0 );
		if ( ! class_exists( 'LNKup_Match_Tables' ) || ! LNKup_Match_Tables::exists( LNKup_Match_Tables::vendor_index_table() ) ) {
			return $out;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$vtable  = LNKup_Match_Tables::vendor_index_table();
		$active  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vtable} WHERE status = 'active'" );
		$no_geo  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vtable} WHERE status = 'active' AND lat IS NULL" );
		$index   = LNKup_Marketplace_Index::table_name();
		$ocats   = LNKup_Match_Tables::opportunity_categories_table();
		$opps    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index}" );
		$no_cats = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index} m WHERE m.opportunity_id NOT IN (SELECT opportunity_id FROM {$ocats})" );
		// phpcs:enable
		$out['ungeocoded_share']    = $active ? $no_geo / $active : 0.0;
		$out['uncategorized_share'] = $opps ? $no_cats / $opps : 0.0;
		return $out;
	}

	/**
	 * Distinct days in the last 14 with a sent transactional message (Communications).
	 */
	private static function transactional_send_days() {
		if ( ! class_exists( 'LNKup_Comms_Schema' ) ) {
			return 0;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT DATE(created_at)) FROM ' . LNKup_Comms_Schema::table( 'messages' ) . " WHERE transactional = 1 AND status = 'sent' AND created_at >= %s", gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Unsubscribes / complaints in the last 3 days against promotional sends in the same window.
	 *
	 * @return array{ok:bool,suppressions:int,sent:int}
	 */
	private static function unsubscribe_rate() {
		if ( ! class_exists( 'LNKup_Comms_Schema' ) ) {
			return array( 'ok' => true, 'suppressions' => 0, 'sent' => 0 );
		}
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$supp = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Comms_Schema::table( 'suppressions' ) . ' WHERE created_at >= %s', $since ) );
		$sent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Comms_Schema::table( 'messages' ) . " WHERE transactional = 0 AND status = 'sent' AND created_at >= %s", $since ) );
		// phpcs:enable
		return array(
			'ok'           => $supp <= 5 || ( $sent > 0 && $supp / $sent <= self::MAX_UNSUBSCRIBE_RATE ),
			'suppressions' => $supp,
			'sent'         => $sent,
		);
	}

	/**
	 * Rollout history, newest first.
	 *
	 * @param string $flag Optional flag filter.
	 * @return array<int,array<string,mixed>>
	 */
	public static function history( $flag = '' ) {
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		return '' === $flag ? $log : array_values(
			array_filter(
				$log,
				static function ( $row ) use ( $flag ) {
					return ( $row['flag'] ?? '' ) === $flag;
				}
			)
		);
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Staged rollout for LNKup feature flags.
	 */
	class LNKup_Rollout_CLI {

		/**
		 * Show every rollout flag: on/off, audience, percent, allowlist size.
		 *
		 * ## EXAMPLES
		 *
		 *     wp lnkup rollout status
		 */
		public function status() {
			$rows = array();
			foreach ( LNKup_Rollout::FLAGS as $flag => $meta ) {
				$config = LNKup_Rollout::config( $flag );
				$rows[] = array(
					'flag'      => $flag,
					'active'    => LNKup_Rollout::active( $flag ) ? 'on' : 'off',
					'audience'  => $config['audience'],
					'stage'     => $config['stage'],
					'percent'   => 'all' === $config['audience'] ? 100 : $config['percent'],
					'allowlist' => count( $config['user_ids'] ),
					'since'     => $config['stage_since'],
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'flag', 'active', 'audience', 'stage', 'percent', 'allowlist', 'since' ) );
		}

		/**
		 * Change a flag's audience. Does not switch the feature on or off.
		 *
		 * ## OPTIONS
		 *
		 * <flag>
		 * : Flag key (see `wp lnkup rollout status`).
		 *
		 * [--stage=<stage>]
		 * : internal, p5, p25, p50 or all.
		 *
		 * [--audience=<audience>]
		 * : all or cohort.
		 *
		 * [--percent=<percent>]
		 * : Cohort percentage 0-100.
		 *
		 * [--users=<ids>]
		 * : Comma-separated allowlist user IDs (replaces the list).
		 *
		 * [--note=<note>]
		 * : Why (kept in history).
		 *
		 * [--override]
		 * : Move to --stage even if rollout checks fail (requires --note).
		 *
		 * ## EXAMPLES
		 *
		 *     wp lnkup rollout set flow_discovery --stage=p5 --note="Week 2"
		 *     wp lnkup rollout set apply_wizard_enabled --audience=cohort --percent=0 --users=12,34
		 *
		 * @param array $args       Positional.
		 * @param array $assoc_args Named.
		 */
		public function set( $args, $assoc_args ) {
			$flag    = (string) $args[0];
			$note    = (string) ( $assoc_args['note'] ?? '' );
			$changes = array_intersect_key( $assoc_args, array_flip( array( 'audience', 'percent' ) ) );
			if ( isset( $assoc_args['users'] ) ) {
				$changes['user_ids'] = (string) $assoc_args['users'];
			}
			$result = $changes ? LNKup_Rollout::set( $flag, $changes, $note ?: 'wp-cli' ) : LNKup_Rollout::config( $flag );
			if ( ! is_wp_error( $result ) && isset( $assoc_args['stage'] ) ) {
				// Stage moves go through the same checks as the admin screen.
				$result = LNKup_Rollout::advance( $flag, (string) $assoc_args['stage'], $note, isset( $assoc_args['override'] ) );
			}
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			WP_CLI::success( sprintf( '%s: %s (%d%%, %d allowlisted)', $args[0], $result['stage'], 'all' === $result['audience'] ? 100 : $result['percent'], count( $result['user_ids'] ) ) );
		}
	}
}
