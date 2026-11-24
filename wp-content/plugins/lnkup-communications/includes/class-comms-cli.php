<?php
/**
 * WP-CLI: `wp lnkup-comms` — rollout status, flag toggles, global pause, safe test sends.
 *
 * Logic lives in static methods (status_data, set_flag, test_send) so it can be
 * exercised without WP-CLI; the command class only formats output.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_CLI {

	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'lnkup-comms', 'LNKup_Comms_CLI_Command' );
		}
	}

	/**
	 * Everything an operator needs before and after toggling a flag.
	 */
	public static function status_data(): array {
		global $wpdb;
		$messages = LNKup_Comms_Schema::table( 'messages' );
		$flags    = array();
		foreach ( array_keys( LNKup_Comms_Settings::flags() ) as $flag ) {
			$flags[ $flag ] = LNKup_Comms_Settings::enabled( $flag );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$queued  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$messages} WHERE status = 'scheduled'" );
		$overdue = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} WHERE status = 'scheduled' AND scheduled_at < %s", LNKup_Comms_Schema::now( -15 * MINUTE_IN_SECONDS ) ) );
		$recent  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT trigger_key, status, COUNT(*) AS c FROM {$messages} WHERE created_at >= %s GROUP BY trigger_key, status ORDER BY trigger_key, status", LNKup_Comms_Schema::now( -DAY_IN_SECONDS ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'version'         => LNKUP_COMMS_VERSION,
			'delivery_paused' => LNKup_Comms_Settings::enabled( 'delivery_paused' ),
			'flags'           => $flags,
			'claimed'         => LNKup_Comms_Delegation::claimed_keys(),
			'queued'          => $queued,
			'overdue'         => $overdue,
			'health'          => array_map(
				static function ( array $check ): array {
					return array( 'check' => $check['label'], 'status' => $check['status'], 'detail' => $check['detail'] );
				},
				LNKup_Comms_Health::checks()
			),
			'last_24h'        => array_map(
				static function ( array $row ): array {
					return array( 'trigger' => (string) $row['trigger_key'], 'status' => (string) $row['status'], 'count' => (int) $row['c'] );
				},
				$recent
			),
		);
	}

	/**
	 * Every notification type the server can write, with its inbox tab per role and the
	 * triggers / deep-link keys that produce it. Web and mobile tests compare against this
	 * export so a new server type fails their drift tests until it is handled.
	 */
	public static function types_export(): array {
		$types = array();
		foreach ( LNKup_Comms_Registry::triggers() as $key => $def ) {
			$type = (string) $def['type'];
			if ( ! isset( $types[ $type ] ) ) {
				$types[ $type ] = array( 'type' => $type, 'triggers' => array(), 'links' => array(), 'roles' => array() );
			}
			$types[ $type ]['triggers'][] = (string) $key;
			$types[ $type ]['links'][]    = (string) $def['link'];
			$types[ $type ]['roles'][]    = (string) $def['role'];
		}
		foreach ( LNKup_Comms_Registry::core_types() as $type ) {
			if ( ! isset( $types[ $type ] ) ) {
				$types[ $type ] = array( 'type' => $type, 'triggers' => array(), 'links' => array(), 'roles' => array() );
			}
		}
		ksort( $types );
		$out = array();
		foreach ( $types as $type => $row ) {
			$row['links']    = array_values( array_unique( $row['links'] ) );
			$row['roles']    = array_values( array_unique( $row['roles'] ) );
			$row['tabs']     = array(
				'vendor'    => LNKup_Comms_Registry::tab_for_type( $type, 'vendor' ),
				'organizer' => LNKup_Comms_Registry::tab_for_type( $type, 'organizer' ),
			);
			$out[] = $row;
		}
		return array(
			'generated_by' => 'wp lnkup-comms export-types',
			'version'      => LNKUP_COMMS_VERSION,
			'tabs'         => array(
				'vendor'    => LNKup_Comms_Registry::tabs( 'vendor' ),
				'organizer' => LNKup_Comms_Registry::tabs( 'organizer' ),
				'partner'   => LNKup_Comms_Registry::tabs( 'partner' ),
			),
			'types'        => $out,
		);
	}

	/**
	 * Toggle exactly one known flag (or delivery_paused) and leave an audit line.
	 *
	 * @return true|WP_Error
	 */
	public static function set_flag( string $flag, bool $on, string $actor = 'wp-cli' ) {
		$allowed = array_merge( array_keys( LNKup_Comms_Settings::flags() ), array( 'delivery_paused' ) );
		if ( ! in_array( $flag, $allowed, true ) ) {
			return new WP_Error( 'lnkup_comms_flag', sprintf( 'Unknown flag "%s". Known: %s', $flag, implode( ', ', $allowed ) ) );
		}
		$before = LNKup_Comms_Settings::enabled( $flag );
		LNKup_Comms_Settings::update( array( $flag => $on ) );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( 'LNKup Communications: %s %s -> %s by %s', $flag, $before ? 'on' : 'off', $on ? 'on' : 'off', $actor ) );
		return true;
	}

	/**
	 * Cancel every scheduled (unsent) message whose trigger belongs to a flow flag.
	 *
	 * @return int|WP_Error Messages cancelled.
	 */
	public static function cancel_for_flag( string $flag, string $actor = 'wp-cli' ) {
		global $wpdb;
		if ( ! array_key_exists( $flag, LNKup_Comms_Settings::flags() ) ) {
			return new WP_Error( 'lnkup_comms_flag', sprintf( 'Unknown flag "%s".', $flag ) );
		}
		$triggers = array();
		foreach ( LNKup_Comms_Registry::triggers() as $key => $def ) {
			if ( (string) ( $def['flag'] ?? '' ) === $flag ) {
				$triggers[] = $key;
			}
		}
		if ( array() === $triggers ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $triggers ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Comms_Schema::table( 'messages' ) . " SET status = 'cancelled', suppression_reason = 'cancelled:flag_off', updated_at = %s WHERE status = 'scheduled' AND trigger_key IN ({$placeholders})", array_merge( array( LNKup_Comms_Schema::now() ), $triggers ) ) );
		$count = (int) $wpdb->rows_affected;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( 'LNKup Communications: cancelled %d queued %s messages by %s', $count, $flag, $actor ) );
		return $count;
	}

	/**
	 * Queue one real trigger to a configured internal test account (flag bypassed).
	 *
	 * @param array $ctx application_id, event_id, opportunity_id, payment_id, document_id.
	 * @return int|WP_Error Message ID.
	 */
	public static function test_send( string $trigger, int $user_id, array $ctx = array(), string $variant = '' ) {
		if ( ! in_array( $user_id, LNKup_Comms_Settings::test_user_ids(), true ) ) {
			return new WP_Error( 'lnkup_comms_test', 'Test sends only go to configured test accounts (Settings → Internal test account IDs).' );
		}
		if ( ! LNKup_Comms_Registry::get( $trigger ) ) {
			return new WP_Error( 'lnkup_comms_test', sprintf( 'Unknown trigger "%s".', $trigger ) );
		}
		$clean = array();
		foreach ( array( 'application_id', 'event_id', 'opportunity_id', 'payment_id', 'document_id' ) as $key ) {
			if ( ! empty( $ctx[ $key ] ) ) {
				$clean[ $key ] = absint( $ctx[ $key ] );
			}
		}
		$id = LNKup_Comms_Engine::dispatch( $trigger, $user_id, $clean, array( 'force' => true, 'window' => 'clitest_' . time(), 'variant' => sanitize_key( $variant ), 'source' => 'cli_test' ) );
		if ( ! $id ) {
			return new WP_Error( 'lnkup_comms_test', 'Not queued: the test account is not authorized for these records, or the message is not currently valid.' );
		}
		return $id;
	}

	public static function actor(): string {
		$user = getenv( 'USER' ) ?: ( getenv( 'USERNAME' ) ?: 'unknown' );
		return 'wp-cli (' . sanitize_user( (string) $user ) . ')';
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI_Command' ) ) {
	/**
	 * Manage the LNKup Communications rollout.
	 */
	class LNKup_Comms_CLI_Command extends WP_CLI_Command {

		/**
		 * Show flags, delegated core messages, queue, health and the last 24h of sends.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : table or json.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - json
		 * ---
		 *
		 * @param array $args       Positional.
		 * @param array $assoc_args Flags.
		 */
		public function status( $args, $assoc_args ): void {
			$data = LNKup_Comms_CLI::status_data();
			if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
				WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT ) );
				return;
			}
			WP_CLI::line( sprintf( 'LNKup Communications %s — delivery %s — %d queued (%d overdue)', $data['version'], $data['delivery_paused'] ? 'PAUSED' : 'running', $data['queued'], $data['overdue'] ) );
			WP_CLI::line( '' );
			$rows = array();
			foreach ( $data['flags'] as $flag => $on ) {
				$rows[] = array( 'flag' => $flag, 'state' => $on ? 'ON' : 'off' );
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'flag', 'state' ) );
			WP_CLI::line( 'Core messages delegated to this plugin: ' . ( $data['claimed'] ? implode( ', ', $data['claimed'] ) : 'none (lnkup-marketplace sends everything)' ) );
			WP_CLI::line( '' );
			WP_CLI\Utils\format_items( 'table', $data['health'], array( 'check', 'status', 'detail' ) );
			WP_CLI::line( '' );
			if ( $data['last_24h'] ) {
				WP_CLI\Utils\format_items( 'table', $data['last_24h'], array( 'trigger', 'status', 'count' ) );
			} else {
				WP_CLI::line( 'No messages in the last 24h.' );
			}
		}

		/**
		 * Turn one flow flag on or off.
		 *
		 * ## OPTIONS
		 *
		 * <name>
		 * : Flag, e.g. flow_applications.
		 *
		 * [--on]
		 * : Enable.
		 *
		 * [--off]
		 * : Disable (hands the flow back to lnkup-marketplace).
		 *
		 * [--cancel-queued]
		 * : With --off, also cancel this flow's scheduled messages (send-time suppression is the backstop).
		 *
		 * ## EXAMPLES
		 *
		 *     wp lnkup-comms flag flow_applications --on
		 *     wp lnkup-comms flag flow_applications --off --cancel-queued
		 *
		 * @param array $args       Positional.
		 * @param array $assoc_args Flags.
		 */
		public function flag( $args, $assoc_args ): void {
			$on  = isset( $assoc_args['on'] );
			$off = isset( $assoc_args['off'] );
			if ( $on === $off ) {
				WP_CLI::error( 'Pass exactly one of --on or --off.' );
			}
			if ( $on && isset( $assoc_args['cancel-queued'] ) ) {
				WP_CLI::error( '--cancel-queued only applies with --off.' );
			}
			$result = LNKup_Comms_CLI::set_flag( (string) ( $args[0] ?? '' ), $on, LNKup_Comms_CLI::actor() );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			$cancelled = '';
			if ( $off && isset( $assoc_args['cancel-queued'] ) ) {
				$count     = LNKup_Comms_CLI::cancel_for_flag( (string) $args[0], LNKup_Comms_CLI::actor() );
				$cancelled = is_wp_error( $count ) ? ' (' . $count->get_error_message() . ')' : sprintf( ' Cancelled %d queued messages.', $count );
			}
			WP_CLI::success( sprintf( '%s is now %s. Claimed core messages: %s.%s', $args[0], $on ? 'ON' : 'off', implode( ', ', LNKup_Comms_Delegation::claimed_keys() ) ?: 'none', $cancelled ) );
		}

		/**
		 * Cancel scheduled (unsent) messages for one flow flag.
		 *
		 * ## OPTIONS
		 *
		 * --flag=<name>
		 * : Flow flag, e.g. flow_discovery.
		 *
		 * ## EXAMPLES
		 *
		 *     wp lnkup-comms cancel --flag=flow_discovery
		 *
		 * @param array $args       Positional.
		 * @param array $assoc_args Flags.
		 */
		public function cancel( $args, $assoc_args ): void {
			unset( $args );
			$count = LNKup_Comms_CLI::cancel_for_flag( (string) ( $assoc_args['flag'] ?? '' ), LNKup_Comms_CLI::actor() );
			if ( is_wp_error( $count ) ) {
				WP_CLI::error( $count->get_error_message() );
			}
			WP_CLI::success( sprintf( 'Cancelled %d queued messages for %s.', $count, $assoc_args['flag'] ) );
		}

		/**
		 * Pause ALL deliveries (messages stay queued).
		 */
		public function pause(): void {
			LNKup_Comms_CLI::set_flag( 'delivery_paused', true, LNKup_Comms_CLI::actor() );
			WP_CLI::success( 'Deliveries paused. Messages stay queued; run `wp lnkup-comms resume` to continue.' );
		}

		/**
		 * Resume deliveries.
		 */
		public function resume(): void {
			LNKup_Comms_CLI::set_flag( 'delivery_paused', false, LNKup_Comms_CLI::actor() );
			WP_CLI::success( 'Deliveries resumed.' );
		}

		/**
		 * Queue one real message to a configured test account (bypasses the flow flag).
		 *
		 * ## OPTIONS
		 *
		 * <trigger>
		 * : Trigger key, e.g. v_application_submitted.
		 *
		 * --user=<id>
		 * : Test account user ID (must be in Settings → Internal test account IDs).
		 *
		 * [--application=<id>]
		 * : Application owned by the test account.
		 *
		 * [--event=<id>]
		 * : Event (organizer triggers).
		 *
		 * [--variant=<variant>]
		 * : Template variant, e.g. payment.
		 *
		 * [--deliver]
		 * : Deliver immediately in this process instead of waiting for the worker.
		 *
		 * @param array $args       Positional.
		 * @param array $assoc_args Flags.
		 */
		public function test( $args, $assoc_args ): void {
			$result = LNKup_Comms_CLI::test_send(
				(string) ( $args[0] ?? '' ),
				absint( $assoc_args['user'] ?? 0 ),
				array(
					'application_id' => $assoc_args['application'] ?? 0,
					'event_id'       => $assoc_args['event'] ?? 0,
				),
				(string) ( $assoc_args['variant'] ?? '' )
			);
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			if ( isset( $assoc_args['deliver'] ) ) {
				LNKup_Comms_Engine::deliver( $result );
				$message = LNKup_Comms_Engine::message( $result );
				WP_CLI::success( sprintf( 'Message #%d %s %s', $result, (string) ( $message['status'] ?? '?' ), (string) ( $message['suppression_reason'] ?? '' ) ) );
				return;
			}
			WP_CLI::success( sprintf( 'Message #%d queued. Check: wp lnkup-comms status', $result ) );
		}

		/**
		 * Export every notification type as JSON for the web and mobile drift tests.
		 *
		 * ## OPTIONS
		 *
		 * [--file=<path>]
		 * : Write to this file instead of stdout.
		 *
		 * @subcommand export-types
		 *
		 * @param array $args       Positional.
		 * @param array $assoc_args Flags.
		 */
		public function export_types( $args, $assoc_args ): void {
			unset( $args );
			$json = (string) wp_json_encode( LNKup_Comms_CLI::types_export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
			if ( empty( $assoc_args['file'] ) ) {
				WP_CLI::line( $json );
				return;
			}
			if ( false === file_put_contents( (string) $assoc_args['file'], $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				WP_CLI::error( 'Could not write ' . $assoc_args['file'] );
			}
			WP_CLI::success( 'Wrote ' . $assoc_args['file'] );
		}
	}
}
