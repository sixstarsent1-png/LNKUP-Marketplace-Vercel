<?php
/**
 * Organizer activation follow-ups, driven by the marketplace activation model
 * (LNKup_Organizer_Activation).
 *
 * Daily: organization profile still incomplete (days 1 and 7), payouts not set up while money
 * is about to arrive (days 2 and 7), draft events left unpublished. Event-driven: first paid
 * booking celebration. Every nudge re-checks its stage at send time (validity rule
 * organizer_activation_stage / event_is_draft) and organizers get at most one activation nudge
 * per activation_cooldown_days across these triggers and o_onboarding.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Auto_Activation {

	const NUDGES    = array( 'o_setup_profile', 'o_setup_payouts', 'o_publish_draft', 'o_onboarding' );
	const INACTIVE  = array( 'cancelled', 'canceled', 'failed', 'skipped', 'suppressed', 'expired' );
	const SCAN_DAYS = 90;

	public static function init(): void {
		add_action( 'lnkup_comms_daily', array( __CLASS__, 'run' ), 45 );
		add_action( 'lnkup_payment_completed', array( __CLASS__, 'on_payment_completed' ), 60, 1 );
	}

	private static function available(): bool {
		return LNKup_Comms_Settings::enabled( 'flow_activation' ) && class_exists( 'LNKup_Organizer_Activation' );
	}

	public static function run(): void {
		if ( ! self::available() ) {
			return;
		}
		$organizers = get_users(
			array(
				'role__in'   => array( 'lnkup_organizer' ),
				'fields'     => 'ID',
				'number'     => 3000,
				'date_query' => array( array( 'after' => self::SCAN_DAYS . ' days ago', 'column' => 'user_registered' ) ),
			)
		);
		foreach ( array_map( 'intval', (array) $organizers ) as $org ) {
			self::organizer( $org );
		}
		self::drafts();
	}

	/**
	 * Whether an activation nudge already went to this organizer inside the cooldown.
	 */
	public static function in_cooldown( int $org ): bool {
		global $wpdb;
		$days = max( 0, LNKup_Comms_Settings::int( 'activation_cooldown_days' ) );
		if ( 0 === $days ) {
			return false;
		}
		$table    = LNKup_Comms_Schema::table( 'messages' );
		$triggers = "'" . implode( "','", self::NUDGES ) . "'";
		$inactive = "'" . implode( "','", self::INACTIVE ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE recipient_user_id = %d AND trigger_key IN ({$triggers}) AND status NOT IN ({$inactive}) AND created_at >= %s", $org, LNKup_Comms_Schema::now( -$days * DAY_IN_SECONDS ) ) ) > 0;
	}

	/**
	 * Earlier messages of one trigger to a user (non-cancelled), newest first.
	 *
	 * @return array<int, string> created_at values.
	 */
	private static function history( int $org, string $trigger ): array {
		global $wpdb;
		$table    = LNKup_Comms_Schema::table( 'messages' );
		$inactive = "'" . implode( "','", self::INACTIVE ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT created_at FROM {$table} WHERE recipient_user_id = %d AND trigger_key = %s AND status NOT IN ({$inactive}) ORDER BY created_at DESC LIMIT 5", $org, $trigger ) ) );
	}

	/**
	 * Two-step window: first nudge (`$first`), one follow-up (`$second`) at least 5 days later.
	 */
	private static function window( int $org, string $trigger, string $first, string $second ): string {
		$sent = self::history( $org, $trigger );
		if ( ! $sent ) {
			return $first;
		}
		if ( 1 === count( $sent ) && strtotime( $sent[0] ) <= time() - 5 * DAY_IN_SECONDS ) {
			return $second;
		}
		return '';
	}

	/**
	 * @return array<string, array<string, mixed>> stage key => stage.
	 */
	private static function stages( int $org ): array {
		$out = array();
		foreach ( LNKup_Organizer_Activation::for_user( $org, true )['stages'] as $stage ) {
			$out[ $stage['key'] ] = $stage;
		}
		return $out;
	}

	/**
	 * At most one daily nudge per organizer: profile first, then payouts.
	 */
	public static function organizer( int $org ): void {
		$user = get_userdata( $org );
		if ( ! $user || self::in_cooldown( $org ) ) {
			return;
		}
		$age    = (int) floor( ( time() - (int) strtotime( (string) $user->user_registered . ' UTC' ) ) / DAY_IN_SECONDS );
		$stages = self::stages( $org );

		if ( $age >= 1 && $age <= 30 && 'complete' !== ( $stages['organization']['status'] ?? 'complete' ) ) {
			$window = self::window( $org, 'o_setup_profile', 'd1', 'd7' );
			if ( '' !== $window && ( 'd1' === $window || $age >= 7 ) ) {
				self::send( 'o_setup_profile', $org, 'organization', $stages['organization'], $window );
				return;
			}
		}

		$payouts  = $stages['payouts'] ?? array();
		$money_in = 'complete' === ( $stages['publication']['status'] ?? '' ) || (int) ( $stages['first_paid_booking']['counts']['approved'] ?? 0 ) > 0;
		if ( $money_in && class_exists( 'LNKup_Stripe_Connect' ) && LNKup_Stripe_Connect::is_enabled() && 'complete' !== ( $payouts['status'] ?? 'complete' ) ) {
			$recent = self::history( $org, 'o_payout_setup' );
			if ( $recent && strtotime( $recent[0] ) > time() - 3 * DAY_IN_SECONDS ) {
				return;
			}
			$window = self::window( $org, 'o_setup_payouts', 'd2', 'd7' );
			if ( '' !== $window ) {
				self::send( 'o_setup_payouts', $org, 'payouts', $payouts, $window );
			}
		}
	}

	/**
	 * @param array<string, mixed> $stage Activation stage.
	 */
	private static function send( string $trigger, int $org, string $stage_key, array $stage, string $window ): int {
		return LNKup_Comms_Engine::dispatch(
			$trigger,
			$org,
			array(
				'organizer_user_id' => $org,
				'activation_stage'  => $stage_key,
				'missing_items'     => implode( ', ', (array) ( $stage['missing'] ?? array() ) ),
			),
			array(
				'window'     => $window,
				'cancel_key' => 'user:' . $org . '|activation',
				'source'     => 'activation',
			)
		);
	}

	/**
	 * Draft events (with at least one vendor slot) left unpublished. Once per event.
	 */
	public static function drafts(): void {
		$days   = max( 1, LNKup_Comms_Settings::int( 'draft_unpublished_days' ) );
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_event_status', 'value' => 'draft' ),
				),
				'date_query'     => array(
					array( 'before' => $days . ' days ago', 'column' => 'post_date_gmt' ),
					array( 'after' => '60 days ago', 'column' => 'post_date_gmt' ),
				),
			)
		);
		foreach ( array_map( 'intval', (array) $events ) as $event_id ) {
			$org = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
			if ( ! $org || LNKup_Comms_Context::event_is_past( $event_id ) || self::in_cooldown( $org ) ) {
				continue;
			}
			$slots = get_posts(
				array(
					'post_type'      => 'lnkup_opportunity',
					'post_status'    => array( 'publish', 'draft', 'pending' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => (string) $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( ! $slots ) {
				continue;
			}
			LNKup_Comms_Engine::dispatch(
				'o_publish_draft',
				$org,
				array(
					'event_id'          => $event_id,
					'organizer_user_id' => $org,
					'activation_stage'  => 'publication',
				),
				array( 'source' => 'activation' )
			);
		}
	}

	/**
	 * Celebrate the organizer's first verified paid booking (once; not blocked by cooldown).
	 *
	 * @param int|string $payment_id Payment post ID.
	 */
	public static function on_payment_completed( $payment_id ): void {
		$payment_id = (int) $payment_id;
		if ( ! self::available() || ! in_array( (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ), array( 'paid', 'partially_refunded' ), true ) ) {
			return;
		}
		$org = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		if ( ! $org || self::history( $org, 'o_first_booking' ) ) {
			return;
		}
		$paid = (int) ( self::stages( $org )['first_paid_booking']['counts']['paid'] ?? 0 );
		if ( 1 !== $paid ) {
			return;
		}
		LNKup_Comms_Engine::dispatch(
			'o_first_booking',
			$org,
			array(
				'payment_id'        => $payment_id,
				'event_id'          => (int) get_post_meta( $payment_id, '_lnkup_event_id', true ),
				'organizer_user_id' => $org,
			),
			array( 'window' => 'first', 'source' => 'activation' )
		);
	}
}
