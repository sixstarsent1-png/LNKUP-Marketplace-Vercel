<?php
/**
 * Stripe Connect architecture — onboarding, splits, destination charges.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Stripe_Connect {

	/**
	 * Local disabled_reason for an account the organizer or Stripe deauthorized.
	 */
	const DEAUTHORIZED_REASON = 'deauthorized';

	public static function is_enabled() {
		$settings = lnkup_get_settings();
		return ! empty( $settings['stripe_connect_enabled'] );
	}

	/**
	 * Settlement rail for organizer payments.
	 *
	 * @param int $organizer_id Organizer user ID.
	 * @return string destination|platform_treasury
	 */
	public static function settlement_mode_for_organizer( $organizer_id ) {
		if ( self::is_connect_ready( $organizer_id ) ) {
			return 'destination';
		}
		return 'platform_treasury';
	}

	/**
	 * Whether organizer Connect account is ready for destination charges.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function is_connect_ready( $user_id ) {
		if ( ! self::is_enabled() ) {
			return false;
		}
		$account = self::get_account( $user_id, 'organizer' );
		return $account
			&& ! empty( $account['stripe_account_id'] )
			&& ! empty( $account['charges_enabled'] )
			&& ! empty( $account['payouts_enabled'] )
			&& empty( $account['disabled_reason'] );
	}

	/**
	 * Whether organizer has past-due Connect requirements.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function has_past_due_requirements( $user_id ) {
		$account = self::get_account( $user_id, 'organizer' );
		if ( ! $account ) {
			return false;
		}
		$past_due = json_decode( $account['requirements_past_due'] ?? '[]', true );
		return is_array( $past_due ) && count( $past_due ) > 0;
	}

	/**
	 * Get connected account for user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    organizer|vendor.
	 */
	public static function get_account( $user_id, $role = 'organizer' ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::connected_accounts_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return null;
		}
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . LNKup_Financial_Tables::connected_accounts_table() . ' WHERE user_id = %d AND role_type = %s LIMIT 1',
				absint( $user_id ),
				sanitize_key( $role )
			),
			ARRAY_A
		);
	}

	/**
	 * Map Stripe account object to internal status.
	 *
	 * @param array $account Local DB row or Stripe account array.
	 */
	public static function map_account_status( $account ) {
		if ( empty( $account['stripe_account_id'] ) && empty( $account['id'] ) ) {
			return 'not_started';
		}
		$reason       = (string) ( $account['disabled_reason'] ?? '' );
		$verification = $account['verification_status'] ?? '';
		if ( self::DEAUTHORIZED_REASON === $reason ) {
			return 'disabled';
		}
		if ( 'rejected' === $verification || 0 === strpos( $reason, 'rejected.' ) ) {
			return 'rejected';
		}
		$past_due = json_decode( $account['requirements_past_due'] ?? '[]', true );
		if ( ( is_array( $past_due ) && count( $past_due ) > 0 ) || 'requirements.past_due' === $reason ) {
			return 'verification_required';
		}
		if ( in_array( $reason, array( 'requirements.pending_verification', 'under_review' ), true ) ) {
			return 'pending_verification';
		}
		if ( '' !== $reason ) {
			return 'disabled';
		}
		if ( ! empty( $account['charges_enabled'] ) && ! empty( $account['payouts_enabled'] ) ) {
			return 'active';
		}
		if ( empty( $account['charges_enabled'] ) && ! empty( $account['details_submitted'] ) ) {
			return 'restricted';
		}
		return 'pending';
	}

	/**
	 * Connect onboarding availability without calling Stripe.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function onboarding_status( $user_id ) {
		$status    = self::get_status( $user_id );
		$available = self::is_enabled() && ( new LNKup_Stripe_Client() )->is_available();
		$settings  = lnkup_get_settings();
		$status['available']                  = $available;
		$status['settlement_mode']            = self::settlement_mode_for_organizer( $user_id );
		$status['instant_payouts_enabled']    = ! empty( $settings['connect_instant_payouts_enabled'] );
		$status['payout_schedule']            = sanitize_key( $settings['connect_payout_schedule'] ?? 'manual' );
		if ( ! self::is_enabled() ) {
			$status['code']    = 'connect_not_configured';
			$status['message'] = __( 'Stripe Connect is not enabled on this marketplace.', 'lnkup-marketplace' );
		} elseif ( ! ( new LNKup_Stripe_Client() )->is_available() ) {
			$status['code']    = 'stripe_not_configured';
			$status['message'] = __( 'Stripe API keys are not configured.', 'lnkup-marketplace' );
		}
		return $status;
	}

	/**
	 * Connect status for dashboard.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function get_status( $user_id ) {
		$account = self::get_account( $user_id, 'organizer' );
		if ( ! $account || empty( $account['stripe_account_id'] ) ) {
			return array(
				'status'            => 'not_started',
				'charges_enabled'   => false,
				'payouts_enabled'   => false,
				'requirements_due'  => array(),
				'requirements_past_due' => array(),
				'settlement_mode'   => 'platform_treasury',
			);
		}
		$requirements_due      = json_decode( $account['requirements_due'] ?? '[]', true ) ?: array();
		$requirements_past_due = json_decode( $account['requirements_past_due'] ?? '[]', true ) ?: array();
		$metadata              = json_decode( $account['metadata'] ?? '{}', true ) ?: array();
		$status                = self::map_account_status( $account );
		$masked_id             = $account['stripe_account_id'];
		if ( strlen( $masked_id ) > 8 ) {
			$masked_id = substr( $masked_id, 0, 7 ) . '…' . substr( $masked_id, -4 );
		}
		return array(
			'status'                => $status,
			'account_id'            => $masked_id,
			'charges_enabled'       => (bool) $account['charges_enabled'],
			'payouts_enabled'       => (bool) $account['payouts_enabled'],
			'details_submitted'     => (bool) ( $account['details_submitted'] ?? false ),
			'requirements_due'      => $requirements_due,
			'requirements_past_due' => $requirements_past_due,
			'pending_verification'  => is_array( $metadata['pending_verification'] ?? null ) ? $metadata['pending_verification'] : array(),
			'disabled_reason'       => $account['disabled_reason'] ?? '',
			'last_synced_at'        => $account['last_synced_at'] ?? null,
			'settlement_mode'       => self::settlement_mode_for_organizer( $user_id ),
		);
	}

	/**
	 * Default Connect return URL.
	 */
	public static function connect_return_url() {
		$settings = lnkup_get_settings();
		if ( ! empty( $settings['connect_return_url'] ) ) {
			return $settings['connect_return_url'];
		}
		return home_url( '/organizer-dashboard/?view=financial&connect=return' );
	}

	/**
	 * Default Connect refresh URL.
	 */
	public static function connect_refresh_url() {
		$settings = lnkup_get_settings();
		if ( ! empty( $settings['connect_refresh_url'] ) ) {
			return $settings['connect_refresh_url'];
		}
		return home_url( '/organizer-dashboard/?view=financial&connect=refresh' );
	}

	/**
	 * HTTPS bridge URLs that bounce Stripe back into the mobile app.
	 *
	 * @param string $kind return|refresh.
	 * @return string
	 */
	public static function connect_app_bridge_url( $kind ) {
		$kind = 'refresh' === $kind ? 'refresh' : 'return';
		return home_url( '/?lnkup_app_connect=' . $kind );
	}

	/**
	 * Whether a Connect return/refresh URL is allowlisted (open-redirect safe).
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public static function is_allowed_connect_redirect_url( $url ) {
		$raw = trim( (string) $url );
		if ( '' === $raw ) {
			return false;
		}

		// Mobile deep links (Stripe may prefer HTTPS; bridge is preferred).
		if ( preg_match( '#^lnkup://money(?:\?connect=(?:return|refresh))?$#i', $raw ) ) {
			return true;
		}

		$url = esc_url_raw( $raw );
		if ( '' === $url ) {
			return false;
		}

		$normalized = untrailingslashit( $url );
		$allowed    = array(
			untrailingslashit( self::connect_return_url() ),
			untrailingslashit( self::connect_refresh_url() ),
			untrailingslashit( self::connect_app_bridge_url( 'return' ) ),
			untrailingslashit( self::connect_app_bridge_url( 'refresh' ) ),
		);

		return in_array( $normalized, $allowed, true );
	}

	/**
	 * Redirect Stripe Connect completion into the Expo app scheme.
	 */
	public static function maybe_redirect_app_connect() {
		if ( empty( $_GET['lnkup_app_connect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$kind = sanitize_key( wp_unslash( (string) $_GET['lnkup_app_connect'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $kind, array( 'return', 'refresh' ), true ) ) {
			return;
		}
		$target = 'lnkup://money?connect=' . rawurlencode( $kind );
		// Custom schemes are not allowed by wp_safe_redirect; send Location directly.
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Location: ' . $target, true, 302 );
		}
		exit;
	}

	/**
	 * Register Connect hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_app_connect' ), 1 );
	}

	/**
	 * Upsert connected account record.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role type.
	 * @param array  $data    Account data.
	 */
	public static function upsert_account( $user_id, $role, $data ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::connected_accounts_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0;
		}
		$existing = self::get_account( $user_id, $role );
		$row      = array(
			'user_id'    => absint( $user_id ),
			'role_type'  => sanitize_key( $role ),
			'updated_at' => current_time( 'mysql' ),
		);
		// On update only touch the columns the caller passed, so partial writes keep synced state.
		if ( ! $existing || array_key_exists( 'stripe_account_id', $data ) ) {
			$row['stripe_account_id'] = sanitize_text_field( $data['stripe_account_id'] ?? '' );
		}
		if ( ! $existing || array_key_exists( 'verification_status', $data ) ) {
			$row['verification_status'] = sanitize_key( $data['verification_status'] ?? 'pending' );
		}
		foreach ( array( 'payouts_enabled', 'charges_enabled', 'details_submitted' ) as $flag ) {
			if ( ! $existing || array_key_exists( $flag, $data ) ) {
				$row[ $flag ] = ! empty( $data[ $flag ] ) ? 1 : 0;
			}
		}
		foreach ( array( 'requirements_due', 'requirements_past_due' ) as $list ) {
			if ( ! $existing || array_key_exists( $list, $data ) ) {
				$row[ $list ] = isset( $data[ $list ] ) ? wp_json_encode( $data[ $list ] ) : null;
			}
		}
		if ( ! $existing || array_key_exists( 'disabled_reason', $data ) ) {
			$row['disabled_reason'] = sanitize_text_field( $data['disabled_reason'] ?? '' );
		}
		if ( ! $existing || array_key_exists( 'metadata', $data ) ) {
			$row['metadata'] = ! empty( $data['metadata'] ) ? wp_json_encode( $data['metadata'] ) : null;
		}
		$row['last_synced_at'] = $data['last_synced_at'] ?? current_time( 'mysql' );

		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $existing['id'] ) );
			$old_acct = (string) ( $existing['stripe_account_id'] ?? '' );
			$new_acct = (string) ( $row['stripe_account_id'] ?? $old_acct );
			if ( '' !== $old_acct && '' !== $new_acct && $old_acct !== $new_acct ) {
				do_action( 'lnkup_connect_account_changed', absint( $user_id ), sanitize_key( $role ), $old_acct, $new_acct );
			}
			return (int) $existing['id'];
		}
		$row['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $table, $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Poll Stripe and update local Connect row.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function sync_account_from_stripe( $user_id ) {
		$account = self::get_account( $user_id, 'organizer' );
		if ( ! $account || empty( $account['stripe_account_id'] ) ) {
			return new WP_Error( 'no_account', __( 'No Connect account to sync.', 'lnkup-marketplace' ) );
		}
		$client  = new LNKup_Stripe_Client();
		$remote  = $client->retrieve_account( $account['stripe_account_id'] );
		if ( is_wp_error( $remote ) ) {
			if ( self::is_missing_account_error( $remote ) ) {
				self::reset_account( $user_id );
				LNKup_Financial_Audit::log( 'connect_account_missing', 'user', $user_id, array( 'account_id' => $account['stripe_account_id'] ) );
				return self::get_status( $user_id );
			}
			self::log_stripe_error( 'connect_sync', $user_id, $remote );
			return new WP_Error(
				'stripe_api_error',
				__( 'We couldn\'t refresh your payout status. Try again in a minute.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}
		self::apply_stripe_account_payload( $user_id, $remote );
		$remote_interval = (string) ( $remote['settings']['payouts']['schedule']['interval'] ?? '' );
		if ( 'manual' !== $remote_interval ) {
			self::ensure_payout_schedule( $user_id );
		}
		LNKup_Financial_Audit::log( 'connect_account_synced', 'user', $user_id, array( 'account_id' => $account['stripe_account_id'] ) );
		return self::get_status( $user_id );
	}

	/**
	 * Whether a Stripe error means the connected account no longer exists or is no longer accessible.
	 *
	 * @param WP_Error $error Stripe client error.
	 * @return bool
	 */
	public static function is_missing_account_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		$data = $error->get_error_data();
		$code = is_array( $data ) ? (string) ( $data['stripe_code'] ?? '' ) : '';
		if ( in_array( $code, array( 'resource_missing', 'account_invalid' ), true ) ) {
			return true;
		}
		$msg = strtolower( (string) $error->get_error_message() );
		return false !== strpos( $msg, 'no such account' )
			|| false !== strpos( $msg, 'does not have access to account' );
	}

	/**
	 * Clear the local Connect row so onboarding can create a fresh account.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function reset_account( $user_id ) {
		// A new idempotency generation so the next create cannot replay the old account.
		update_user_meta( $user_id, '_lnkup_connect_account_generation', (int) get_user_meta( $user_id, '_lnkup_connect_account_generation', true ) + 1 );
		self::upsert_account(
			$user_id,
			'organizer',
			array(
				'stripe_account_id'     => '',
				'verification_status'   => 'pending',
				'charges_enabled'       => false,
				'payouts_enabled'       => false,
				'details_submitted'     => false,
				'requirements_due'      => array(),
				'requirements_past_due' => array(),
				'disabled_reason'       => '',
				'metadata'              => array(),
			)
		);
	}

	/**
	 * Log a raw Stripe error for operators; callers return friendly copy to users.
	 *
	 * @param string   $context Short context slug.
	 * @param int      $user_id Organizer user ID.
	 * @param WP_Error $error   Stripe client error.
	 */
	private static function log_stripe_error( $context, $user_id, $error ) {
		$message = is_wp_error( $error ) ? $error->get_error_message() : '';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[LNKup] stripe_connect_%s_failed user=%d message=%s', $context, absint( $user_id ), $message ) );
		LNKup_Financial_Audit::log( 'connect_' . $context . '_failed', 'user', absint( $user_id ), array( 'message' => $message ) );
	}

	/**
	 * Live Stripe Connect balance for destination-mode organizers.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function retrieve_connect_balance( $user_id ) {
		$account = self::get_account( $user_id, 'organizer' );
		if ( ! $account || empty( $account['stripe_account_id'] ) ) {
			return array(
				'available' => 0,
				'pending'   => 0,
				'currency'  => 'usd',
				'synced'    => false,
			);
		}
		if ( empty( $account['charges_enabled'] ) || empty( $account['payouts_enabled'] ) ) {
			return array(
				'available' => 0,
				'pending'   => 0,
				'currency'  => 'usd',
				'synced'    => false,
			);
		}
		$client  = new LNKup_Stripe_Client();
		$balance = $client->retrieve_connect_balance( $account['stripe_account_id'] );
		if ( is_wp_error( $balance ) ) {
			return array(
				'available' => 0,
				'pending'   => 0,
				'currency'  => 'usd',
				'synced'    => false,
				'error'     => $balance->get_error_message(),
			);
		}
		return $balance;
	}

	/**
	 * When the connected account's pending funds become available, from Stripe's own
	 * balance transactions (read-only, cached 5 minutes). Returns ok=false on any error so
	 * callers simply show no date.
	 *
	 * @param int $user_id Organizer user ID.
	 * @return array{ok:bool,next_available_on:?string,schedule:array<int,array{date:string,cents:int}>,items:array<int,array{source:string,cents:int,created:int,available_on:string}>}
	 */
	public static function pending_release_schedule( $user_id ) {
		$empty   = array( 'ok' => false, 'next_available_on' => null, 'schedule' => array(), 'items' => array() );
		$user_id = absint( $user_id );
		$account = $user_id ? self::get_account( $user_id, 'organizer' ) : null;
		if ( ! $account || empty( $account['stripe_account_id'] ) ) {
			return $empty;
		}
		$key    = 'lnkup_connect_pending_' . $user_id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$client = new LNKup_Stripe_Client();
		$list   = $client->list_connect_balance_transactions( $account['stripe_account_id'], array( 'limit' => 50 ) );
		if ( is_wp_error( $list ) || ! is_array( $list ) ) {
			set_transient( $key, $empty, MINUTE_IN_SECONDS );
			return $empty;
		}
		$by_date = array();
		$items   = array();
		foreach ( (array) ( $list['data'] ?? array() ) as $bt ) {
			if ( 'pending' !== ( $bt['status'] ?? '' ) || (int) ( $bt['net'] ?? 0 ) <= 0 || empty( $bt['available_on'] ) ) {
				continue;
			}
			$date             = gmdate( 'Y-m-d', (int) $bt['available_on'] );
			$by_date[ $date ] = ( $by_date[ $date ] ?? 0 ) + (int) $bt['net'];
			$items[]          = array(
				'source'       => (string) ( is_array( $bt['source'] ?? null ) ? ( $bt['source']['id'] ?? '' ) : ( $bt['source'] ?? '' ) ),
				'cents'        => (int) $bt['net'],
				'created'      => (int) ( $bt['created'] ?? 0 ),
				'available_on' => $date,
			);
		}
		ksort( $by_date );
		$schedule = array();
		foreach ( $by_date as $date => $cents ) {
			$schedule[] = array( 'date' => $date, 'cents' => $cents );
		}
		$result = array(
			'ok'                => true,
			'next_available_on' => $schedule ? $schedule[0]['date'] : null,
			'schedule'          => array_slice( $schedule, 0, 10 ),
			'items'             => $items,
		);
		set_transient( $key, $result, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Mark Connect account disabled locally (deauthorize).
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function deauthorize_account( $user_id ) {
		$account = self::get_account( $user_id, 'organizer' );
		if ( ! $account ) {
			return new WP_Error( 'no_account', __( 'No Connect account found.', 'lnkup-marketplace' ) );
		}
		self::upsert_account(
			$user_id,
			'organizer',
			array(
				'verification_status' => 'disabled',
				'charges_enabled'     => false,
				'payouts_enabled'     => false,
				'disabled_reason'     => self::DEAUTHORIZED_REASON,
			)
		);
		LNKup_Financial_Audit::log( 'connect_deauthorized', 'user', $user_id, array() );
		return array( 'success' => true, 'status' => 'disabled' );
	}

	/**
	 * Handle account.application.deauthorized (platform lost access to the connected account).
	 *
	 * @param array  $application Stripe application object.
	 * @param string $account_id  Connected account ID from the event envelope.
	 */
	public static function handle_account_deauthorized( $application, $account_id ) {
		unset( $application );
		$user_id = self::user_id_for_stripe_account( (string) $account_id );
		if ( $user_id ) {
			self::deauthorize_account( $user_id );
		}
	}

	/**
	 * Look up the organizer that owns a connected account.
	 *
	 * @param string $stripe_account_id Connected account ID.
	 * @return int
	 */
	private static function user_id_for_stripe_account( $stripe_account_id ) {
		if ( '' === $stripe_account_id ) {
			return 0;
		}
		global $wpdb;
		$table = LNKup_Financial_Tables::connected_accounts_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT user_id FROM {$table} WHERE stripe_account_id = %s AND role_type = 'organizer' LIMIT 1", $stripe_account_id ),
			ARRAY_A
		);
		return $row ? (int) $row['user_id'] : 0;
	}

	/**
	 * Apply Stripe Account API payload to local row.
	 *
	 * @param int   $user_id Organizer user ID.
	 * @param array $account Stripe account object.
	 */
	public static function apply_stripe_account_payload( $user_id, $account ) {
		$requirements    = is_array( $account['requirements'] ?? null ) ? $account['requirements'] : array();
		$account_id      = (string) ( $account['id'] ?? '' );
		$disabled_reason = (string) ( $requirements['disabled_reason'] ?? '' );
		$existing        = self::get_account( $user_id, 'organizer' );
		$is_deauthorized = $existing
			&& self::DEAUTHORIZED_REASON === ( $existing['disabled_reason'] ?? '' )
			&& $account_id === (string) ( $existing['stripe_account_id'] ?? '' );

		if ( $is_deauthorized ) {
			$disabled_reason     = self::DEAUTHORIZED_REASON;
			$verification_status = 'disabled';
		} elseif ( 0 === strpos( $disabled_reason, 'rejected.' ) ) {
			$verification_status = 'rejected';
		} elseif ( ! empty( $account['details_submitted'] ) ) {
			$verification_status = 'verified';
		} else {
			$verification_status = 'pending';
		}

		self::upsert_account(
			$user_id,
			'organizer',
			array(
				'stripe_account_id'     => $account_id,
				'verification_status'   => $verification_status,
				'payouts_enabled'       => ! $is_deauthorized && ! empty( $account['payouts_enabled'] ),
				'charges_enabled'       => ! $is_deauthorized && ! empty( $account['charges_enabled'] ),
				'details_submitted'     => ! empty( $account['details_submitted'] ),
				'requirements_due'      => $requirements['currently_due'] ?? array(),
				'requirements_past_due' => $requirements['past_due'] ?? array(),
				'disabled_reason'       => $disabled_reason,
				'last_synced_at'        => current_time( 'mysql' ),
				'metadata'              => array(
					'capabilities'             => $account['capabilities'] ?? array(),
					'pending_verification'     => $requirements['pending_verification'] ?? array(),
					'payout_schedule_interval' => (string) ( $account['settings']['payouts']['schedule']['interval'] ?? '' ),
				),
			)
		);

		$was_ready = $existing && ! empty( $existing['payouts_enabled'] ) && ! empty( $existing['charges_enabled'] ) && '' === (string) ( $existing['disabled_reason'] ?? '' );
		$is_ready  = ! $is_deauthorized && ! empty( $account['payouts_enabled'] ) && ! empty( $account['charges_enabled'] ) && '' === $disabled_reason;
		$old_state = $existing ? (string) ( $existing['verification_status'] ?? '' ) . ( $was_ready ? ':ready' : '' ) : '';
		$new_state = $verification_status . ( $is_ready ? ':ready' : '' );
		if ( $old_state !== $new_state ) {
			do_action( 'lnkup_connect_account_status_changed', absint( $user_id ), $old_state, $new_state );
		}
	}

	/**
	 * Build Stripe settings.payouts.schedule from LNKup settings.
	 *
	 * @return array
	 */
	public static function payout_schedule_params_from_settings() {
		$settings = lnkup_get_settings();
		$sched    = sanitize_key( $settings['connect_payout_schedule'] ?? 'manual' );
		if ( 'weekly' === $sched ) {
			return array(
				'interval'      => 'weekly',
				'weekly_anchor' => 'monday',
			);
		}
		if ( 'daily' === $sched ) {
			return array( 'interval' => 'daily' );
		}
		return array( 'interval' => 'manual' );
	}

	/**
	 * Best-effort sync of Connect payout schedule (soft-fail).
	 *
	 * @param int  $user_id       Organizer user ID.
	 * @param bool $force_manual  When true (cashout), always set interval to manual so funds stay payable on-demand.
	 * @return bool True when update succeeded or no update needed.
	 */
	public static function ensure_payout_schedule( $user_id, $force_manual = false ) {
		$settings = lnkup_get_settings();
		$sched    = sanitize_key( $settings['connect_payout_schedule'] ?? 'manual' );
		// Soft sync from settings: only push when marketplace wants manual — unless cashout forces it.
		if ( ! $force_manual && 'manual' !== $sched ) {
			return true;
		}
		$account = self::get_account( $user_id, 'organizer' );
		if ( ! $account || empty( $account['stripe_account_id'] ) ) {
			return false;
		}
		$metadata = json_decode( $account['metadata'] ?? '{}', true ) ?: array();
		if ( 'manual' === ( $metadata['payout_schedule_interval'] ?? '' ) ) {
			return true;
		}
		$client = new LNKup_Stripe_Client();
		$result = $client->update_connect_account(
			$account['stripe_account_id'],
			array(
				'settings' => array(
					'payouts' => array(
						'schedule' => array( 'interval' => 'manual' ),
					),
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return false;
		}
		$metadata['payout_schedule_interval'] = 'manual';
		self::upsert_account( $user_id, 'organizer', array( 'metadata' => $metadata ) );
		return true;
	}

	/**
	 * Create Express Dashboard login link for an organizer with an active Connect account.
	 *
	 * @param int $user_id Organizer user ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_login_link( $user_id ) {
		if ( ! self::is_enabled() ) {
			return new WP_Error(
				'connect_not_configured',
				__( 'Stripe Connect is not enabled.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}
		$client = new LNKup_Stripe_Client();
		if ( ! $client->is_available() ) {
			return new WP_Error(
				'stripe_not_configured',
				__( 'Stripe is not configured.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}
		$account = self::get_account( $user_id, 'organizer' );
		if ( ! $account || empty( $account['stripe_account_id'] ) ) {
			return new WP_Error(
				'connect_not_started',
				__( 'Finish payout setup before managing your Stripe account.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}
		$link = $client->create_login_link( $account['stripe_account_id'] );
		if ( is_wp_error( $link ) ) {
			self::log_stripe_error( 'login_link', $user_id, $link );
			return new WP_Error(
				'stripe_api_error',
				__( 'We couldn\'t open your payout dashboard. Finish payout setup first, or try again in a minute.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}
		$url = is_array( $link ) ? (string) ( $link['url'] ?? '' ) : '';
		if ( '' === $url ) {
			return new WP_Error(
				'stripe_api_error',
				__( 'Could not open Stripe Express.', 'lnkup-marketplace' ),
				array( 'status' => 500 )
			);
		}
		return array(
			'success' => true,
			'url'     => $url,
			'message' => __( 'Stripe Express is ready.', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Create Express account and onboarding link.
	 *
	 * @param int   $user_id Organizer user ID.
	 * @param array $args    Optional. return_url, refresh_url (allowlisted).
	 */
	public static function create_onboarding_link( $user_id, $args = array() ) {
		if ( ! self::is_enabled() ) {
			return array(
				'success' => false,
				'code'    => 'connect_not_configured',
				'message' => __( 'Stripe Connect is not enabled.', 'lnkup-marketplace' ),
				'status'  => 'not_started',
			);
		}
		$client = new LNKup_Stripe_Client();
		if ( ! $client->is_available() ) {
			return array(
				'success' => false,
				'code'    => 'stripe_not_configured',
				'message' => __( 'Stripe is not configured.', 'lnkup-marketplace' ),
				'status'  => 'not_started',
			);
		}
		$settings = lnkup_get_settings();
		$account  = self::get_account( $user_id, 'organizer' );

		// Organizer is reconnecting: re-check the existing account instead of trusting the local flag.
		if ( $account && ! empty( $account['stripe_account_id'] ) && self::DEAUTHORIZED_REASON === ( $account['disabled_reason'] ?? '' ) ) {
			$remote = $client->retrieve_account( $account['stripe_account_id'] );
			if ( is_wp_error( $remote ) && self::is_missing_account_error( $remote ) ) {
				self::reset_account( $user_id );
			} elseif ( ! is_wp_error( $remote ) ) {
				self::upsert_account( $user_id, 'organizer', array( 'disabled_reason' => '' ) );
				self::apply_stripe_account_payload( $user_id, $remote );
			}
			$account = self::get_account( $user_id, 'organizer' );
		}

		if ( ! $account || empty( $account['stripe_account_id'] ) ) {
			$params = array(
				'type'     => ! empty( $settings['connect_express_enabled'] ) ? 'express' : 'standard',
				'country'  => $settings['connect_platform_country'] ?? 'US',
				'metadata' => array( 'organizer_user_id' => (string) $user_id ),
				'settings' => array(
					'payouts' => array(
						'schedule' => self::payout_schedule_params_from_settings(),
					),
				),
			);
			$generation = (int) get_user_meta( $user_id, '_lnkup_connect_account_generation', true );
			$created    = $client->create_connect_account( $params, 'lnkup_connect_acct_' . absint( $user_id ) . '_' . $generation );
			if ( is_wp_error( $created ) ) {
				// Next attempt must not replay a cached failure for the same idempotency key.
				update_user_meta( $user_id, '_lnkup_connect_account_generation', $generation + 1 );
				self::log_stripe_error( 'account_create', $user_id, $created );
				return array(
					'success' => false,
					'code'    => 'stripe_api_error',
					'message' => __( 'We couldn\'t start payout setup. Try again in a minute.', 'lnkup-marketplace' ),
					'status'  => 'not_started',
				);
			}
			self::upsert_account( $user_id, 'organizer', array(
				'stripe_account_id'   => $created['id'] ?? '',
				'verification_status' => 'pending',
			) );
			$account = self::get_account( $user_id, 'organizer' );
		}
		if ( ! $account || empty( $account['stripe_account_id'] ) ) {
			return array(
				'success' => false,
				'code'    => 'stripe_api_error',
				'message' => __( 'Could not create Connect account.', 'lnkup-marketplace' ),
				'status'  => 'not_started',
			);
		}

		$return_url  = self::connect_return_url();
		$refresh_url = self::connect_refresh_url();
		if ( ! empty( $args['return_url'] ) && self::is_allowed_connect_redirect_url( $args['return_url'] ) ) {
			$candidate = trim( (string) $args['return_url'] );
			$return_url = preg_match( '#^lnkup://#i', $candidate ) ? $candidate : esc_url_raw( $candidate );
		}
		if ( ! empty( $args['refresh_url'] ) && self::is_allowed_connect_redirect_url( $args['refresh_url'] ) ) {
			$candidate   = trim( (string) $args['refresh_url'] );
			$refresh_url = preg_match( '#^lnkup://#i', $candidate ) ? $candidate : esc_url_raw( $candidate );
		}

		$link = $client->create_account_link( array(
			'account'     => $account['stripe_account_id'],
			'refresh_url' => $refresh_url,
			'return_url'  => $return_url,
			'type'        => 'account_onboarding',
		) );
		if ( is_wp_error( $link ) ) {
			self::log_stripe_error( 'account_link', $user_id, $link );
			if ( self::is_missing_account_error( $link ) ) {
				self::reset_account( $user_id );
			}
			return array(
				'success' => false,
				'code'    => 'stripe_api_error',
				'message' => __( 'We couldn\'t open payout setup. Try again in a minute.', 'lnkup-marketplace' ),
				'status'  => 'not_started',
			);
		}
		return array(
			'success'    => true,
			'url'        => $link['url'] ?? '',
			'account_id' => $account['stripe_account_id'],
		);
	}

	/**
	 * Calculate payout split for organizer.
	 *
	 * @param float $gross_amount Gross collected.
	 * @param int   $organizer_id Organizer user ID.
	 */
	public static function calculate_payout_split( $booth_amount, $organizer_id ) {
		$booth_amount         = max( 0, (float) $booth_amount );
		$vendor_checkout_fee  = LNKup_Fee_Engine::resolve_vendor_checkout_fee( $organizer_id );
		$organizer_commission = LNKup_Fee_Engine::resolve_organizer_commission( $booth_amount, $organizer_id );
		$platform_fee         = round( $vendor_checkout_fee + $organizer_commission, 2 );
		$net                  = max( 0, round( $booth_amount - $organizer_commission, 2 ) );
		return array(
			'gross_amount'         => round( $booth_amount, 2 ),
			'vendor_checkout_fee'  => round( $vendor_checkout_fee, 2 ),
			'organizer_commission' => round( $organizer_commission, 2 ),
			'platform_fee'         => $platform_fee,
			'net_amount'           => $net,
			'connect_ready'        => self::is_connect_ready( $organizer_id ),
			'settlement_mode'      => self::settlement_mode_for_organizer( $organizer_id ),
		);
	}

	/**
	 * Destination charge params for PaymentIntent / Checkout.
	 *
	 * @param int $organizer_id Organizer user ID.
	 * @param int $platform_fee_cents Platform fee in cents.
	 */
	public static function destination_charge_params( $organizer_id, $platform_fee_cents ) {
		if ( ! self::is_connect_ready( $organizer_id ) ) {
			return array();
		}
		$account = self::get_account( $organizer_id, 'organizer' );
		return array(
			'application_fee_amount' => max( 0, (int) round( $platform_fee_cents ) ),
			'transfer_data'          => array( 'destination' => $account['stripe_account_id'] ),
		);
	}

	/**
	 * Persist settlement metadata on payment post.
	 *
	 * @param int      $payment_id   Payment post ID.
	 * @param int      $organizer_id Organizer ID.
	 * @param array    $breakdown    Fee breakdown.
	 * @param string   $context      create|settle — settle preserves charge truth (do not flip treasury → destination).
	 * @param int|null $amount_cents Optional charge amount in cents (stamps destination amount on create).
	 */
	public static function persist_payment_settlement_meta( $payment_id, $organizer_id, $breakdown, $context = 'create', $amount_cents = null ) {
		$payment_id   = absint( $payment_id );
		$organizer_id = absint( $organizer_id );
		$context      = ( 'settle' === $context ) ? 'settle' : 'create';

		$existing_mode = (string) get_post_meta( $payment_id, '_lnkup_settlement_mode', true );
		$existing_dest = (string) get_post_meta( $payment_id, '_lnkup_stripe_destination_account', true );

		if ( 'settle' === $context ) {
			// Money already charged: mode must match where Stripe funds sit, not Connect readiness now.
			if ( '' !== $existing_dest ) {
				$mode = 'destination';
			} else {
				// No destination stamped at create → platform charge (or unknown). Keep treasury.
				$mode = 'platform_treasury';
			}
		} else {
			$mode = self::settlement_mode_for_organizer( $organizer_id );
		}

		$app_fee_cents = isset( $breakdown['application_fee_cents'] )
			? (int) $breakdown['application_fee_cents']
			: (int) round( (float) ( $breakdown['platform_fee'] ?? 0 ) * 100 );
		update_post_meta( $payment_id, '_lnkup_settlement_mode', $mode );
		// At settle the charge already happened: keep the fee Stripe was sent (or reported) and
		// the charge-time breakdown instead of re-deriving them from today's fee settings.
		$frozen = 'settle' === $context && '' !== (string) get_post_meta( $payment_id, '_lnkup_fee_breakdown', true );
		if ( ! $frozen ) {
			update_post_meta( $payment_id, '_lnkup_application_fee_cents', $app_fee_cents );
			update_post_meta( $payment_id, '_lnkup_fee_breakdown', wp_json_encode( $breakdown ) );
		} else {
			update_post_meta( $payment_id, '_lnkup_fee_breakdown_settled', wp_json_encode( $breakdown ) );
			$app_fee_cents = (int) get_post_meta( $payment_id, '_lnkup_application_fee_cents', true );
		}
		if ( 'destination' === $mode ) {
			if ( '' === $existing_dest ) {
				$account = self::get_account( $organizer_id, 'organizer' );
				update_post_meta( $payment_id, '_lnkup_stripe_destination_account', $account['stripe_account_id'] ?? '' );
			}
			if ( null !== $amount_cents && (int) $amount_cents > 0 ) {
				$amount_cents = (int) $amount_cents;
				update_post_meta( $payment_id, '_lnkup_stripe_charge_amount_cents', $amount_cents );
				update_post_meta(
					$payment_id,
					'_lnkup_stripe_destination_amount',
					round( max( 0, $amount_cents - $app_fee_cents ) / 100, 2 )
				);
			}
		} elseif ( 'platform_treasury' === $mode && 'create' === $context ) {
			// A new platform charge: drop any destination stamp left by an earlier attempt, or
			// settlement would book this charge as if the money were on Connect.
			delete_post_meta( $payment_id, '_lnkup_stripe_destination_account' );
			delete_post_meta( $payment_id, '_lnkup_stripe_destination_amount' );
		}
	}

	/**
	 * Schedule transfer to connected account (platform_treasury cashout only).
	 *
	 * @param int   $organizer_id Organizer ID.
	 * @param float $amount       Amount.
	 * @param array $metadata     Metadata.
	 */
	public static function schedule_transfer( $organizer_id, $amount, $metadata = array() ) {
		if ( 'destination' === self::settlement_mode_for_organizer( $organizer_id ) ) {
			return new WP_Error(
				'destination_mode',
				__( 'Transfers are not used when destination charges are active — use Connect payout instead.', 'lnkup-marketplace' )
			);
		}
		if ( ! self::is_enabled() ) {
			LNKup_Financial_Audit::log( 'connect_transfer_stub', 'payout', $organizer_id, array(
				'amount'   => $amount,
				'metadata' => $metadata,
			) );
			return array( 'status' => 'manual', 'message' => __( 'Connect not enabled — transfer logged.', 'lnkup-marketplace' ) );
		}
		$account = self::get_account( $organizer_id, 'organizer' );
		if ( ! $account || empty( $account['stripe_account_id'] ) ) {
			return new WP_Error( 'no_account', __( 'Organizer Connect account not found.', 'lnkup-marketplace' ) );
		}
		if ( ! self::is_connect_ready( $organizer_id ) ) {
			return array( 'status' => 'manual', 'message' => __( 'Connect account not ready — queued for manual payout.', 'lnkup-marketplace' ) );
		}
		$settings = lnkup_get_settings();
		$currency = $settings['connect_platform_currency'] ?? 'usd';
		$client       = new LNKup_Stripe_Client();
		$local_payout = absint( $metadata['payout_id'] ?? 0 );
		$transfer     = $client->create_transfer(
			array(
				'amount'      => (int) round( $amount * 100 ),
				'currency'    => $currency,
				'destination' => $account['stripe_account_id'],
				'metadata'    => array_merge( array( 'organizer_user_id' => (string) $organizer_id ), $metadata ),
			),
			$local_payout ? 'lnkup_transfer_' . $local_payout : ''
		);
		if ( is_wp_error( $transfer ) ) {
			return $transfer;
		}
		LNKup_Financial_Audit::log( 'connect_transfer_created', 'payout', $organizer_id, array(
			'amount'            => $amount,
			'transfer_id'       => $transfer['id'] ?? '',
			'stripe_account_id' => $account['stripe_account_id'],
		) );
		return array(
			'status'      => 'transferred',
			'transfer_id' => $transfer['id'] ?? '',
			'account_id'  => $account['stripe_account_id'],
		);
	}

	/**
	 * Ledger claim in cents for destination organizers (settlement − processing).
	 *
	 * @param int $organizer_id Organizer user ID.
	 * @return int
	 */
	public static function organizer_ledger_claim_cents( $organizer_id ) {
		if ( ! class_exists( 'LNKup_Organizer_Wallet' ) ) {
			return 0;
		}
		// Platform-held funds only; destination-charge mirrors are already on Connect.
		return max( 0, (int) round( LNKup_Organizer_Wallet::platform_claim( absint( $organizer_id ) ) * 100 ) );
	}

	/**
	 * Platform funds this organizer may recover (ledger claim minus Connect available, capped by platform).
	 *
	 * Never returns uncapped platform balance — application fees stay on LNKup.
	 *
	 * @param int        $organizer_id            Organizer user ID.
	 * @param int|null   $connect_available_cents Optional live Connect available cents.
	 * @param array|null $platform_balance        Optional normalized platform balance payload.
	 * @return int Cents recoverable via Transfer.
	 */
	public static function organizer_recoverable_platform_cents( $organizer_id, $connect_available_cents = null, $platform_balance = null ) {
		$organizer_id = absint( $organizer_id );
		if ( ! $organizer_id || ! self::is_enabled() || ! self::is_connect_ready( $organizer_id ) ) {
			return 0;
		}
		if ( 'destination' !== self::settlement_mode_for_organizer( $organizer_id ) ) {
			return 0;
		}

		$ledger_claim_cents = self::organizer_ledger_claim_cents( $organizer_id );
		if ( $ledger_claim_cents < 1 ) {
			return 0;
		}

		$client = new LNKup_Stripe_Client();

		if ( null === $connect_available_cents ) {
			$account = self::get_account( $organizer_id, 'organizer' );
			if ( ! $account || empty( $account['stripe_account_id'] ) ) {
				return 0;
			}
			$live = $client->retrieve_connect_balance( $account['stripe_account_id'] );
			if ( is_wp_error( $live ) ) {
				return 0;
			}
			$connect_available_cents = isset( $live['available_cents'] )
				? (int) $live['available_cents']
				: (int) round( ( (float) ( $live['available'] ?? 0 ) ) * 100 );
		}
		$connect_available_cents = max( 0, (int) $connect_available_cents );

		// The claim already counts only platform-held money, net of earlier recovery transfers; the
		// Connect balance is destination money and must not be subtracted from it.
		$stranded_claim = $ledger_claim_cents;
		if ( $stranded_claim < 1 ) {
			return 0;
		}

		if ( null === $platform_balance ) {
			$platform_balance = $client->retrieve_platform_balance();
		}
		if ( is_wp_error( $platform_balance ) || ! is_array( $platform_balance ) ) {
			return 0;
		}
		$platform_cents = isset( $platform_balance['available_cents'] )
			? (int) $platform_balance['available_cents']
			: (int) round( ( (float) ( $platform_balance['available'] ?? 0 ) ) * 100 );

		return min( $stranded_claim, max( 0, $platform_cents ) );
	}

	/**
	 * Transfer platform balance to the organizer Connect account (stranded-funds recovery).
	 *
	 * Unlike schedule_transfer(), this is allowed in destination mode so cashout can
	 * move ledger-owed funds that never destination-charged onto Connect, then payout.
	 *
	 * @param int   $organizer_id Organizer user ID.
	 * @param float $amount       Dollars to transfer.
	 * @param array $metadata     Stripe metadata.
	 * @return array|WP_Error
	 */
	public static function transfer_platform_to_connect( $organizer_id, $amount, $metadata = array() ) {
		$organizer_id = absint( $organizer_id );
		$amount_cents = (int) round( (float) $amount * 100 );
		if ( $amount_cents < 1 ) {
			return new WP_Error( 'invalid_amount', __( 'Invalid transfer amount.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! self::is_enabled() ) {
			return new WP_Error( 'connect_disabled', __( 'Stripe Connect is not enabled.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$account = self::get_account( $organizer_id, 'organizer' );
		if ( ! $account || empty( $account['stripe_account_id'] ) ) {
			return new WP_Error( 'no_account', __( 'Organizer Connect account not found.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! self::is_connect_ready( $organizer_id ) ) {
			return new WP_Error( 'not_ready', __( 'Connect account is not ready for transfers.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$settings = lnkup_get_settings();
		$currency = strtolower( (string) ( $settings['connect_platform_currency'] ?? 'usd' ) );
		$client   = new LNKup_Stripe_Client();

		$platform = $client->retrieve_platform_balance();
		if ( is_wp_error( $platform ) ) {
			$data = $platform->get_error_data();
			if ( ! is_array( $data ) || empty( $data['status'] ) ) {
				$platform->add_data( array( 'status' => 400 ) );
			}
			return $platform;
		}
		$platform_cents = isset( $platform['available_cents'] )
			? (int) $platform['available_cents']
			: (int) round( ( (float) ( $platform['available'] ?? 0 ) ) * 100 );
		$platform_sources = is_array( $platform['source_types'] ?? null ) ? $platform['source_types'] : array();

		if ( $amount_cents > $platform_cents ) {
			return new WP_Error(
				'insufficient_platform_balance',
				sprintf(
					/* translators: 1: requested 2: platform available */
					__( 'Need %1$s on the LNKup platform balance to move funds, but only %2$s is available.', 'lnkup-marketplace' ),
					lnkup_currency( $amount_cents / 100 ),
					lnkup_currency( $platform_cents / 100 )
				),
				array( 'status' => 400 )
			);
		}

		$candidates = self::payout_source_type_candidates( $platform_sources, $amount_cents, 'standard' );
		if ( empty( $candidates ) ) {
			$msg = sprintf(
				/* translators: %s: amount */
				__( 'Cannot move %s from the LNKup platform balance — no single balance bucket covers that amount.', 'lnkup-marketplace' ),
				lnkup_currency( $amount_cents / 100 )
			);
			$split = self::format_source_types_breakdown( $platform_sources );
			if ( $split ) {
				$msg .= ' ' . $split;
			}
			return new WP_Error( 'insufficient_platform_balance', $msg, array( 'status' => 400 ) );
		}

		$base_params = array(
			'amount'      => $amount_cents,
			'currency'    => $currency,
			'destination' => $account['stripe_account_id'],
			'metadata'    => array_merge(
				array(
					'organizer_user_id' => (string) $organizer_id,
					'lnkup_recovery'    => '1',
				),
				$metadata
			),
		);

		$last_error   = null;
		$transfer     = null;
		$chosen       = '';
		$local_payout = absint( $metadata['payout_id'] ?? 0 );
		// Deterministic key: a double click, a second tab or a retry returns the same transfer.
		$claim_cents = (int) round( LNKup_Organizer_Wallet::platform_claim( $organizer_id ) * 100 );
		$key_base    = $local_payout
			? 'lnkup_recovery_' . $local_payout
			: 'lnkup_recovery_org_' . $organizer_id . '_' . $claim_cents . '_' . $amount_cents;
		foreach ( $candidates as $source_type ) {
			$params                = $base_params;
			$params['source_type'] = $source_type;
			$transfer              = $client->create_transfer( $params, $key_base . '_' . $source_type );
			if ( ! is_wp_error( $transfer ) ) {
				$chosen = $source_type;
				break;
			}
			$last_error = $transfer;
			if ( ! self::is_retryable_source_balance_error( $transfer ) ) {
				break;
			}
		}

		if ( is_wp_error( $transfer ) || null === $transfer ) {
			$error = is_wp_error( $last_error ) ? $last_error : new WP_Error(
				'transfer_failed',
				__( 'Could not move funds to your payout account.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
			if ( ! is_array( $error->get_error_data() ) || empty( $error->get_error_data()['status'] ) ) {
				$error->add_data( array( 'status' => 400 ) );
			}
			return $error;
		}

		// Ledger the transfer so the platform claim shrinks by exactly what was moved (once).
		$wallet = LNKup_Organizer_Wallet::get_or_create( $organizer_id );
		LNKup_Organizer_Wallet::insert_entry(
			array(
				'wallet_id'       => (int) $wallet['id'],
				'user_id'         => $organizer_id,
				'entry_type'      => 'debit',
				'amount'          => round( $amount_cents / 100, 2 ),
				'source_type'     => 'recovery_transfer',
				'payout_id'       => $local_payout,
				'note'            => __( 'Moved LNKup-held funds to your payout account', 'lnkup-marketplace' ),
				'metadata'        => wp_json_encode( array( 'transfer_id' => (string) ( $transfer['id'] ?? '' ), 'source_type' => $chosen ) ),
				'idempotency_key' => 'recovery:' . (string) ( $transfer['id'] ?? $key_base ),
			)
		);
		LNKup_Financial_Audit::log(
			'connect_recovery_transfer',
			'payout',
			$organizer_id,
			array(
				'amount'            => $amount_cents / 100,
				'transfer_id'       => $transfer['id'] ?? '',
				'stripe_account_id' => $account['stripe_account_id'],
				'source_type'       => $chosen,
			)
		);

		return array(
			'status'      => 'transferred',
			'transfer_id' => $transfer['id'] ?? '',
			'account_id'  => $account['stripe_account_id'],
			'source_type' => $chosen,
		);
	}

	/**
	 * Split main Stripe Available into organizer-owed vs LNKup fee remainder.
	 *
	 * @return array{
	 *   synced:bool,
	 *   error?:string,
	 *   platform_available:float,
	 *   platform_pending:float,
	 *   platform_available_cents:int,
	 *   source_types:array,
	 *   organizer_owed_cents:int,
	 *   organizer_owed:float,
	 *   fee_remainder_cents:int,
	 *   fee_remainder:float,
	 *   organizers:array<int,array>
	 * }
	 */
	public static function platform_balance_split() {
		$empty = array(
			'synced'                   => false,
			'platform_available'       => 0.0,
			'platform_pending'         => 0.0,
			'platform_available_cents' => 0,
			'source_types'             => array(),
			'organizer_owed_cents'     => 0,
			'organizer_owed'           => 0.0,
			'fee_remainder_cents'      => 0,
			'fee_remainder'            => 0.0,
			'organizers'               => array(),
		);

		if ( ! self::is_enabled() ) {
			$empty['error'] = __( 'Stripe Connect is not enabled.', 'lnkup-marketplace' );
			return $empty;
		}

		$client   = new LNKup_Stripe_Client();
		$platform = $client->retrieve_platform_balance();
		if ( is_wp_error( $platform ) ) {
			$empty['error'] = $platform->get_error_message();
			return $empty;
		}

		$platform_cents = isset( $platform['available_cents'] )
			? (int) $platform['available_cents']
			: (int) round( ( (float) ( $platform['available'] ?? 0 ) ) * 100 );
		$pending_cents  = isset( $platform['pending_cents'] )
			? (int) $platform['pending_cents']
			: (int) round( ( (float) ( $platform['pending'] ?? 0 ) ) * 100 );
		$source_types   = is_array( $platform['source_types'] ?? null ) ? $platform['source_types'] : array();

		$organizers     = array();
		$total_stranded = 0;

		foreach ( self::organizer_owed_breakdown() as $row ) {
			$user_id = (int) $row['user_id'];
			$user    = get_userdata( $user_id );
			$owed    = (int) $row['owed_cents'];
			$can     = $row['claim_cents'] > 0 && self::is_connect_ready( $user_id );
			$organizers[] = array(
				'user_id'            => $user_id,
				'name'               => $user ? $user->display_name : ( '#' . $user_id ),
				'settlement_mode'    => self::settlement_mode_for_organizer( $user_id ),
				'recoverable_cents'  => $can ? (int) $row['claim_cents'] : 0,
				'recoverable'        => $can ? round( $row['claim_cents'] / 100, 2 ) : 0.0,
				'owed_cents'         => $owed,
				'ledger_claim_cents' => (int) $row['claim_cents'],
				'can_recover'        => $can,
			);
			$total_stranded += $owed;
		}

		// Cap aggregate organizer-owed at platform available (fees cannot go negative).
		$owed_cents = min( $total_stranded, max( 0, $platform_cents ) );
		$fee_cents  = max( 0, $platform_cents - $owed_cents );

		return array(
			'synced'                   => true,
			'platform_available'       => round( $platform_cents / 100, 2 ),
			'platform_pending'         => round( $pending_cents / 100, 2 ),
			'platform_available_cents' => $platform_cents,
			'source_types'             => $source_types,
			'organizer_owed_cents'     => $owed_cents,
			'organizer_owed'           => round( $owed_cents / 100, 2 ),
			'fee_remainder_cents'      => $fee_cents,
			'fee_remainder'            => round( $fee_cents / 100, 2 ),
			'organizers'               => $organizers,
		);
	}

	/**
	 * Money on the LNKup platform balance that belongs to organizers, per organizer, from the ledger.
	 *
	 * Owed = treasury available + treasury pending (escrow) + platform claim (migrated / recovered,
	 * not yet transferred) + treasury cashouts requested but not yet paid. Every organizer wallet is
	 * counted regardless of Connect state, so the fee remainder can never include organizer money.
	 *
	 * @return array<int, array{user_id:int, owed_cents:int, claim_cents:int}>
	 */
	public static function organizer_owed_breakdown() {
		global $wpdb;
		$wallets = LNKup_Treasury_Tables::organizer_wallets_table();
		$payouts = LNKup_Treasury_Tables::organizer_payouts_table();
		if ( ! LNKup_Financial_Tables::table_exists( $wallets ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( "SELECT user_id, available_balance, pending_balance FROM {$wallets}", ARRAY_A );
		$in_flight = array();
		if ( LNKup_Financial_Tables::table_exists( $payouts ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( (array) $wpdb->get_results( "SELECT user_id, SUM(amount) AS total FROM {$payouts} WHERE status IN ('requested','approved','processing') AND provider <> 'stripe_connect_payout' GROUP BY user_id", ARRAY_A ) as $p ) {
				$in_flight[ (int) $p['user_id'] ] = (int) round( (float) $p['total'] * 100 );
			}
		}
		$out = array();
		foreach ( $rows as $row ) {
			$user_id = (int) $row['user_id'];
			$claim   = (int) round( LNKup_Organizer_Wallet::platform_claim( $user_id ) * 100 );
			$owed    = (int) round( ( (float) $row['available_balance'] + (float) $row['pending_balance'] ) * 100 ) + $claim + ( $in_flight[ $user_id ] ?? 0 );
			if ( $owed > 0 ) {
				$out[] = array( 'user_id' => $user_id, 'owed_cents' => $owed, 'claim_cents' => $claim );
			}
		}
		return $out;
	}

	/**
	 * Pay out LNKup fee remainder from the platform Stripe account (Standard).
	 *
	 * Amount is capped at fee_remainder so organizer-owed funds stay on platform for recovery.
	 *
	 * @param float $amount Dollars.
	 * @return array|WP_Error
	 */
	public static function create_platform_payout( $amount ) {
		$amount_cents = (int) round( (float) $amount * 100 );
		if ( $amount_cents < 1 ) {
			return new WP_Error( 'invalid_amount', __( 'Invalid payout amount.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! self::is_enabled() ) {
			return new WP_Error( 'connect_disabled', __( 'Stripe Connect is not enabled.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$split = self::platform_balance_split();
		if ( empty( $split['synced'] ) ) {
			return new WP_Error(
				'balance_unavailable',
				! empty( $split['error'] ) ? (string) $split['error'] : __( 'Could not load platform Stripe balance.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}

		$fee_cents = (int) ( $split['fee_remainder_cents'] ?? 0 );
		if ( $amount_cents > $fee_cents ) {
			return new WP_Error(
				'exceeds_fee_remainder',
				sprintf(
					/* translators: 1: requested 2: fee remainder 3: organizer owed */
					__( 'Requested %1$s but only %2$s is LNKup fee remainder (%3$s is organizer-owed on the platform and must be recovered to Connect first).', 'lnkup-marketplace' ),
					lnkup_currency( $amount_cents / 100 ),
					lnkup_currency( $fee_cents / 100 ),
					lnkup_currency( ( (int) ( $split['organizer_owed_cents'] ?? 0 ) ) / 100 )
				),
				array( 'status' => 400 )
			);
		}

		$client         = new LNKup_Stripe_Client();
		$platform       = $client->retrieve_platform_balance();
		if ( is_wp_error( $platform ) ) {
			return $platform;
		}
		$platform_cents   = isset( $platform['available_cents'] )
			? (int) $platform['available_cents']
			: (int) round( ( (float) ( $platform['available'] ?? 0 ) ) * 100 );
		$platform_sources = is_array( $platform['source_types'] ?? null ) ? $platform['source_types'] : array();

		if ( $amount_cents > $platform_cents ) {
			return new WP_Error(
				'insufficient_platform_balance',
				sprintf(
					/* translators: 1: requested 2: available */
					__( 'Requested %1$s but platform Available is only %2$s.', 'lnkup-marketplace' ),
					lnkup_currency( $amount_cents / 100 ),
					lnkup_currency( $platform_cents / 100 )
				),
				array( 'status' => 400 )
			);
		}

		$candidates = self::payout_source_type_candidates( $platform_sources, $amount_cents, 'standard' );
		if ( empty( $candidates ) ) {
			$msg = sprintf(
				/* translators: %s: amount */
				__( 'Cannot pay out %s — no single platform balance bucket covers that amount.', 'lnkup-marketplace' ),
				lnkup_currency( $amount_cents / 100 )
			);
			$split_label = self::format_source_types_breakdown( $platform_sources );
			if ( $split_label ) {
				$msg .= ' ' . $split_label;
			}
			return new WP_Error( 'insufficient_platform_balance', $msg, array( 'status' => 400 ) );
		}

		$settings = lnkup_get_settings();
		$currency = strtolower( (string) ( $settings['connect_platform_currency'] ?? 'usd' ) );
		if ( ! empty( $platform['currency'] ) ) {
			$currency = strtolower( (string) $platform['currency'] );
		}

		$base_params = array(
			'amount'   => $amount_cents,
			'currency' => $currency,
			'metadata' => array(
				'lnkup_platform_payout' => '1',
				'fee_remainder_cents'   => (string) $fee_cents,
			),
		);

		$last_error = null;
		$payout     = null;
		$chosen     = '';
		foreach ( $candidates as $source_type ) {
			$params                = $base_params;
			$params['source_type'] = $source_type;
			$payout                = $client->create_platform_payout( $params );
			if ( ! is_wp_error( $payout ) ) {
				$chosen = $source_type;
				break;
			}
			$last_error = $payout;
			if ( ! self::is_retryable_source_balance_error( $payout ) ) {
				break;
			}
		}

		if ( is_wp_error( $payout ) || null === $payout ) {
			$error = is_wp_error( $last_error ) ? $last_error : new WP_Error(
				'payout_failed',
				__( 'Platform payout could not be completed.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
			return $error;
		}

		LNKup_Financial_Audit::log(
			'platform_payout_created',
			'payout',
			0,
			array(
				'amount'      => $amount_cents / 100,
				'payout_id'   => $payout['id'] ?? '',
				'source_type' => $chosen,
			)
		);

		return array(
			'status'      => 'paid',
			'payout_id'   => $payout['id'] ?? '',
			'source_type' => $chosen,
			'amount'      => $amount_cents / 100,
		);
	}

	/**
	 * Friendly label for a Stripe balance source_type key.
	 *
	 * @param string $key source_type (card, bank_account, …).
	 * @return string
	 */
	public static function source_type_label( $key ) {
		$key = sanitize_key( (string) $key );
		$map = array(
			'card'         => __( 'Card', 'lnkup-marketplace' ),
			'bank_account' => __( 'Bank', 'lnkup-marketplace' ),
			'fpx'          => 'FPX',
		);
		if ( isset( $map[ $key ] ) ) {
			return $map[ $key ];
		}
		return $key ? ucwords( str_replace( '_', ' ', $key ) ) : '';
	}

	/**
	 * Human-readable Card/Bank split for Connect available balance.
	 *
	 * @param array $source_types Map of source_type => cents.
	 * @return string
	 */
	public static function format_source_types_breakdown( $source_types ) {
		if ( ! is_array( $source_types ) || empty( $source_types ) ) {
			return '';
		}
		$parts = array();
		foreach ( $source_types as $key => $cents ) {
			$label   = self::source_type_label( $key );
			$parts[] = sprintf( '%s: %s', $label, lnkup_currency( ( (int) $cents ) / 100 ) );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Pick a Connect payout source_type that covers the requested amount.
	 *
	 * Stripe defaults omitted source_type to "card", which fails when funds sit in bank_account.
	 *
	 * @param array  $source_types Map of source_type => cents.
	 * @param int    $amount_cents Requested payout cents.
	 * @param string $payout_type  standard|instant.
	 * @return string Empty when none covers (or source_types empty).
	 */
	public static function select_payout_source_type( $source_types, $amount_cents, $payout_type = 'standard' ) {
		$candidates = self::payout_source_type_candidates( $source_types, $amount_cents, $payout_type );
		return ! empty( $candidates[0] ) ? (string) $candidates[0] : '';
	}

	/**
	 * Ordered source_type candidates for a Connect payout.
	 *
	 * When Stripe omits source_types but total available covers the amount, falls back to
	 * bank_account/card (or card/bank for Instant) so we never silently default to card-only.
	 *
	 * @param array  $source_types Map of source_type => cents.
	 * @param int    $amount_cents Requested payout cents.
	 * @param string $payout_type  standard|instant.
	 * @return string[]
	 */
	public static function payout_source_type_candidates( $source_types, $amount_cents, $payout_type = 'standard' ) {
		$amount_cents = (int) $amount_cents;
		$preferred    = ( 'instant' === $payout_type )
			? array( 'card', 'bank_account' )
			: array( 'bank_account', 'card' );

		$cents_map = array();
		foreach ( (array) $source_types as $key => $val ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$cents_map[ $key ] = (int) $val;
		}

		$ordered = array();
		if ( empty( $cents_map ) ) {
			// Breakdown missing — probe preferred buckets (Stripe rejects empty ones).
			return $preferred;
		}

		foreach ( $preferred as $key ) {
			if ( isset( $cents_map[ $key ] ) && $cents_map[ $key ] >= $amount_cents ) {
				$ordered[] = $key;
			}
		}

		$extras = array();
		foreach ( $cents_map as $key => $cents ) {
			if ( $cents >= $amount_cents && ! in_array( $key, $ordered, true ) ) {
				$extras[ $key ] = $cents;
			}
		}
		if ( ! empty( $extras ) ) {
			arsort( $extras, SORT_NUMERIC );
			foreach ( array_keys( $extras ) as $key ) {
				$ordered[] = $key;
			}
		}

		return $ordered;
	}

	/**
	 * Whether a Stripe payout error is worth retrying with another source_type.
	 *
	 * @param WP_Error $error Stripe/client error.
	 * @return bool
	 */
	public static function is_retryable_source_balance_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		$data = $error->get_error_data();
		$code = is_array( $data ) && ! empty( $data['stripe_code'] ) ? (string) $data['stripe_code'] : '';
		if ( 'balance_insufficient' === $code ) {
			return true;
		}
		$msg = strtolower( (string) $error->get_error_message() );
		if ( false !== strpos( $msg, 'insufficient' ) ) {
			return true;
		}
		if ( false !== strpos( $msg, 'too low' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Create payout on connected account (destination mode cashout).
	 *
	 * @param int    $organizer_id Organizer ID.
	 * @param float  $amount       Net amount in dollars.
	 * @param string $type         standard|instant.
	 * @param array  $metadata     Metadata.
	 */
	public static function create_connect_payout( $organizer_id, $amount, $type = 'standard', $metadata = array() ) {
		$account = self::get_account( $organizer_id, 'organizer' );
		if ( ! $account || empty( $account['stripe_account_id'] ) ) {
			return new WP_Error( 'no_account', __( 'Organizer Connect account not found.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! self::is_connect_ready( $organizer_id ) ) {
			return new WP_Error( 'not_ready', __( 'Connect account is not ready for payouts.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( self::has_past_due_requirements( $organizer_id ) ) {
			return new WP_Error( 'requirements_past_due', __( 'Complete Stripe verification before cashing out.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$settings = lnkup_get_settings();
		if ( 'instant' === $type && empty( $settings['connect_instant_payouts_enabled'] ) ) {
			return new WP_Error( 'instant_disabled', __( 'Instant payouts are not enabled.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		// On-demand cashout: keep funds on the connected account (not auto-paid out).
		self::ensure_payout_schedule( $organizer_id, true );

		$currency     = strtolower( (string) ( $settings['connect_platform_currency'] ?? 'usd' ) );
		$amount_cents = (int) round( (float) $amount * 100 );
		if ( $amount_cents < 1 ) {
			return new WP_Error( 'invalid_amount', __( 'Invalid cashout amount.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$client  = new LNKup_Stripe_Client();
		$acct_id = $account['stripe_account_id'];

		// Re-fetch after schedule update so available figures are current.
		$live = $client->retrieve_connect_balance( $acct_id );
		if ( is_wp_error( $live ) ) {
			$data = $live->get_error_data();
			if ( ! is_array( $data ) || empty( $data['status'] ) ) {
				$live->add_data( array( 'status' => 400 ) );
			}
			return $live;
		}

		$available_cents = isset( $live['available_cents'] )
			? (int) $live['available_cents']
			: (int) round( ( (float) ( $live['available'] ?? 0 ) ) * 100 );
		$instant_cents   = isset( $live['instant_available_cents'] )
			? (int) $live['instant_available_cents']
			: (int) round( ( (float) ( $live['instant_available'] ?? 0 ) ) * 100 );
		$source_types    = is_array( $live['source_types'] ?? null ) ? $live['source_types'] : array();
		$instant_sources = is_array( $live['instant_source_types'] ?? null ) ? $live['instant_source_types'] : array();

		// Stranded funds: ledger-owed money on platform → Transfer to Connect, then payout.
		// Not needed for an Instant payout that Stripe's instant-eligible balance already covers.
		$instant_covered = ( 'instant' === $type && $amount_cents <= $instant_cents );
		if ( $amount_cents > $available_cents && ! $instant_covered ) {
			$shortfall_cents = $amount_cents - $available_cents;
			$platform        = $client->retrieve_platform_balance();
			$recoverable     = self::organizer_recoverable_platform_cents(
				$organizer_id,
				$available_cents,
				is_wp_error( $platform ) ? null : $platform
			);

			if ( $recoverable >= $shortfall_cents ) {
				$recovery = self::transfer_platform_to_connect(
					$organizer_id,
					$shortfall_cents / 100,
					array_merge( $metadata, array( 'cashout_recovery' => '1' ) )
				);
				if ( is_wp_error( $recovery ) ) {
					return $recovery;
				}

				$live = $client->retrieve_connect_balance( $acct_id );
				if ( is_wp_error( $live ) ) {
					$data = $live->get_error_data();
					if ( ! is_array( $data ) || empty( $data['status'] ) ) {
						$live->add_data( array( 'status' => 400 ) );
					}
					return $live;
				}
				$available_cents = isset( $live['available_cents'] )
					? (int) $live['available_cents']
					: (int) round( ( (float) ( $live['available'] ?? 0 ) ) * 100 );
				$instant_cents   = isset( $live['instant_available_cents'] )
					? (int) $live['instant_available_cents']
					: (int) round( ( (float) ( $live['instant_available'] ?? 0 ) ) * 100 );
				$source_types    = is_array( $live['source_types'] ?? null ) ? $live['source_types'] : array();
				$instant_sources = is_array( $live['instant_source_types'] ?? null ) ? $live['instant_source_types'] : array();
			} elseif ( ! is_wp_error( $platform ) ) {
				$platform_cents = isset( $platform['available_cents'] )
					? (int) $platform['available_cents']
					: (int) round( ( (float) ( $platform['available'] ?? 0 ) ) * 100 );
				if ( $platform_cents >= $shortfall_cents ) {
					$acct_suffix = strlen( (string) $acct_id ) > 4 ? substr( (string) $acct_id, -4 ) : (string) $acct_id;
					$ledger_claim = self::organizer_ledger_claim_cents( $organizer_id );
					return new WP_Error(
						'funds_on_platform',
						sprintf(
							/* translators: 1: connect available 2: recoverable 3: ledger claim 4: account suffix */
							__( 'Your Connect payout account (…%4$s) has %1$s available. LNKup can automatically move up to %2$s of ledger-owed platform funds (ledger claim %3$s). Request a smaller amount, or contact support if this looks wrong.', 'lnkup-marketplace' ),
							lnkup_currency( $available_cents / 100 ),
							lnkup_currency( $recoverable / 100 ),
							lnkup_currency( $ledger_claim / 100 ),
							$acct_suffix
						),
						array(
							'status'                   => 400,
							'platform_available_cents' => $platform_cents,
							'connect_available_cents'  => $available_cents,
							'recoverable_cents'        => $recoverable,
							'ledger_claim_cents'       => $ledger_claim,
						)
					);
				}
			}
		}

		$is_instant        = ( 'instant' === $type );
		$payable_cents     = $is_instant ? $instant_cents : $available_cents;
		$candidate_sources = $is_instant ? $instant_sources : $source_types;

		if ( $amount_cents > $payable_cents ) {
			if ( $is_instant ) {
				$msg = sprintf(
					/* translators: 1: requested amount 2: instant available 3: standard available */
					__( 'Requested %1$s Instant, but only %2$s is Instant-eligible. Standard available is %3$s — use Standard cashout instead.', 'lnkup-marketplace' ),
					lnkup_currency( $amount_cents / 100 ),
					lnkup_currency( $instant_cents / 100 ),
					lnkup_currency( $available_cents / 100 )
				);
			} else {
				$msg = sprintf(
					/* translators: 1: requested amount 2: available amount */
					__( 'Requested %1$s but only %2$s is available to pay out.', 'lnkup-marketplace' ),
					lnkup_currency( $amount_cents / 100 ),
					lnkup_currency( $available_cents / 100 )
				);
			}
			$split = self::format_source_types_breakdown( $candidate_sources );
			if ( $split ) {
				$msg .= ' ' . $split;
			}
			return new WP_Error( 'insufficient_balance', $msg, array( 'status' => 400 ) );
		}

		$candidates = self::payout_source_type_candidates( $candidate_sources, $amount_cents, $type );
		if ( empty( $candidates ) ) {
			if ( $is_instant ) {
				$msg = sprintf(
					/* translators: 1: requested amount 2: standard available */
					__( 'Requested %1$s Instant, but no Instant balance bucket covers that amount. Standard available is %2$s — use Standard cashout instead.', 'lnkup-marketplace' ),
					lnkup_currency( $amount_cents / 100 ),
					lnkup_currency( $available_cents / 100 )
				);
			} else {
				$msg = sprintf(
					/* translators: %s: requested amount */
					__( 'Requested %s, but no single Stripe balance bucket (card vs bank) has enough funds. Stripe payouts use one source type at a time.', 'lnkup-marketplace' ),
					lnkup_currency( $amount_cents / 100 )
				);
			}
			$split = self::format_source_types_breakdown( $candidate_sources );
			if ( $split ) {
				$msg .= ' ' . $split;
			}
			return new WP_Error( 'insufficient_balance', $msg, array( 'status' => 400 ) );
		}

		$externals = $client->list_connect_external_accounts( $acct_id );
		if ( is_wp_error( $externals ) ) {
			$data = $externals->get_error_data();
			if ( ! is_array( $data ) || empty( $data['status'] ) ) {
				$externals->add_data( array( 'status' => 400 ) );
			}
			return $externals;
		}
		if ( empty( $externals ) ) {
			return new WP_Error(
				'no_external_account',
				__( 'Add a bank account in payout setup before cashing out.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}

		$instant_destination = '';
		if ( $is_instant ) {
			foreach ( $externals as $ext ) {
				$methods = isset( $ext['available_payout_methods'] ) && is_array( $ext['available_payout_methods'] )
					? $ext['available_payout_methods']
					: array();
				if ( in_array( 'instant', $methods, true ) && ! empty( $ext['id'] ) ) {
					$instant_destination = (string) $ext['id'];
					break;
				}
			}
			if ( ! $instant_destination ) {
				return new WP_Error(
					'instant_unavailable',
					__( 'Instant payouts need an eligible debit card or bank on your payout account. Use Standard, or finish Instant setup in Stripe.', 'lnkup-marketplace' ),
					array( 'status' => 400 )
				);
			}
		}

		$payout_currency = ! empty( $live['currency'] ) ? strtolower( (string) $live['currency'] ) : $currency;
		$base_params     = array(
			'amount'   => $amount_cents,
			'currency' => $payout_currency,
			'metadata' => array_merge( array( 'organizer_user_id' => (string) $organizer_id ), $metadata ),
		);
		if ( $is_instant ) {
			$base_params['method']      = 'instant';
			$base_params['destination'] = $instant_destination;
		}

		$attempted    = array();
		$last_error   = null;
		$chosen       = '';
		$payout       = null;
		$local_payout = absint( $metadata['payout_id'] ?? 0 );

		foreach ( $candidates as $source_type ) {
			$params                  = $base_params;
			$params['source_type']   = $source_type;
			$attempted[]             = $source_type;
			$payout                  = $client->create_connect_payout(
				$acct_id,
				$params,
				$local_payout ? 'lnkup_payout_' . $local_payout . '_' . $source_type : ''
			);
			if ( ! is_wp_error( $payout ) ) {
				$chosen = $source_type;
				break;
			}
			$last_error = $payout;
			if ( ! self::is_retryable_source_balance_error( $payout ) ) {
				break;
			}
		}

		if ( is_wp_error( $payout ) || null === $payout ) {
			$error = is_wp_error( $last_error ) ? $last_error : new WP_Error(
				'payout_failed',
				__( 'Cashout could not be completed.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
			$msg = self::format_payout_failure_message(
				$error->get_error_message(),
				$amount_cents,
				$available_cents,
				$instant_cents,
				$source_types,
				$instant_sources,
				$attempted,
				$acct_id
			);
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[LNKup] create_connect_payout_failed user=%d amount=%s available=%d instant=%d source_types=%s attempted=%s acct=%s message=%s',
					$organizer_id,
					(string) $amount,
					$available_cents,
					$instant_cents,
					wp_json_encode( $source_types ),
					implode( ',', $attempted ),
					$acct_id,
					$error->get_error_message()
				)
			);
			LNKup_Financial_Audit::log(
				'connect_payout_failed',
				'payout',
				$organizer_id,
				array(
					'amount'   => $amount,
					'metadata' => array(
						'source_types'            => $source_types,
						'instant_source_types'    => $instant_sources,
						'available_cents'         => $available_cents,
						'instant_available_cents' => $instant_cents,
						'attempted'               => $attempted,
						'message'                 => $error->get_error_message(),
					),
				)
			);
			$data = $error->get_error_data();
			if ( ! is_array( $data ) ) {
				$data = array();
			}
			$data['status']                  = ! empty( $data['status'] ) ? $data['status'] : 400;
			$data['attempted']               = $attempted;
			$data['source_types']            = $source_types;
			$data['instant_source_types']    = $instant_sources;
			$data['available_cents']         = $available_cents;
			$data['instant_available_cents'] = $instant_cents;
			return new WP_Error( $error->get_error_code() ? $error->get_error_code() : 'payout_failed', $msg, $data );
		}

		LNKup_Financial_Audit::log( 'connect_payout_created', 'payout', $organizer_id, array(
			'amount'      => $amount,
			'payout_id'   => $payout['id'] ?? '',
			'payout_type' => $type,
			'metadata'    => array(
				'source_type'  => $chosen,
				'source_types' => $source_types,
				'attempted'    => $attempted,
			),
		) );
		return array(
			'status'      => 'processing',
			'payout_id'   => $payout['id'] ?? '',
			'source_type' => $chosen,
		);
	}

	/**
	 * Append diagnostic balance details to a Stripe payout failure message.
	 *
	 * @param string $base_message     Stripe/client message.
	 * @param int    $amount_cents     Requested cents.
	 * @param int    $available_cents  Standard available cents.
	 * @param int    $instant_cents    Instant available cents.
	 * @param array  $source_types     Available source_types map.
	 * @param array  $instant_sources  Instant source_types map.
	 * @param array  $attempted        Tried source_type keys.
	 * @param string $acct_id          Connect account id.
	 * @return string
	 */
	public static function format_payout_failure_message( $base_message, $amount_cents, $available_cents, $instant_cents, $source_types, $instant_sources, $attempted, $acct_id ) {
		$msg = trim( (string) $base_message );
		$msg .= ' ' . sprintf(
			/* translators: 1: requested 2: available 3: instant available */
			__( 'Requested %1$s. Available %2$s · Instant %3$s.', 'lnkup-marketplace' ),
			lnkup_currency( ( (int) $amount_cents ) / 100 ),
			lnkup_currency( ( (int) $available_cents ) / 100 ),
			lnkup_currency( ( (int) $instant_cents ) / 100 )
		);
		$split = self::format_source_types_breakdown( $source_types );
		if ( $split ) {
			$msg .= ' ' . sprintf(
				/* translators: %s: Card/Bank breakdown */
				__( 'Available by source: %s.', 'lnkup-marketplace' ),
				$split
			);
		}
		$instant_split = self::format_source_types_breakdown( $instant_sources );
		if ( $instant_split ) {
			$msg .= ' ' . sprintf(
				/* translators: %s: Card/Bank breakdown */
				__( 'Instant by source: %s.', 'lnkup-marketplace' ),
				$instant_split
			);
		}
		if ( ! empty( $attempted ) && is_array( $attempted ) ) {
			$msg .= ' ' . sprintf(
				/* translators: %s: comma-separated Stripe source_type keys tried */
				__( 'Tried balance sources: %s.', 'lnkup-marketplace' ),
				implode( ', ', $attempted )
			);
		}
		$acct_id = (string) $acct_id;
		if ( strlen( $acct_id ) > 4 ) {
			$msg .= ' ' . sprintf(
				/* translators: %s: last 4 of Connect account id */
				__( 'Connect …%s.', 'lnkup-marketplace' ),
				substr( $acct_id, -4 )
			);
		}
		return $msg;
	}

	/**
	 * Handle account.updated webhook payload.
	 *
	 * @param array $account Stripe account object.
	 */
	public static function handle_account_updated( $account ) {
		$account_id = (string) ( $account['id'] ?? '' );
		if ( '' === $account_id ) {
			return;
		}
		$user_id = self::user_id_for_stripe_account( $account_id );
		if ( ! $user_id && ! empty( $account['metadata']['organizer_user_id'] ) ) {
			// Metadata may only claim an organizer that has no other connected account yet.
			$candidate = absint( $account['metadata']['organizer_user_id'] );
			$existing  = $candidate ? self::get_account( $candidate, 'organizer' ) : null;
			if ( $candidate && ( ! $existing || empty( $existing['stripe_account_id'] ) ) ) {
				$user_id = $candidate;
			}
		}
		if ( ! $user_id ) {
			return;
		}
		self::apply_stripe_account_payload( $user_id, $account );
	}

	/**
	 * Handle transfer.created webhook — mark organizer payout paid (treasury path).
	 *
	 * @param array $transfer Stripe transfer object.
	 */
	public static function handle_transfer_created( $transfer ) {
		$payout_id = self::payout_id_for_treasury_transfer( $transfer );
		if ( $payout_id && class_exists( 'LNKup_Organizer_Payouts' ) ) {
			LNKup_Organizer_Payouts::mark_paid( $payout_id, $transfer['id'] ?? '' );
		}
	}

	/**
	 * Handle transfer.reversed webhook — a fully reversed treasury cashout transfer failed.
	 *
	 * @param array $transfer Stripe transfer object.
	 */
	public static function handle_transfer_reversed( $transfer ) {
		$payout_id = self::payout_id_for_treasury_transfer( $transfer );
		if ( ! $payout_id || ! class_exists( 'LNKup_Organizer_Payouts' ) ) {
			return;
		}
		$amount          = (int) ( $transfer['amount'] ?? 0 );
		$amount_reversed = (int) ( $transfer['amount_reversed'] ?? 0 );
		if ( empty( $transfer['reversed'] ) && ( $amount < 1 || $amount_reversed < $amount ) ) {
			return;
		}
		LNKup_Organizer_Payouts::handle_async_failure( $payout_id, __( 'Transfer to your payout account was reversed', 'lnkup-marketplace' ) );
	}

	/**
	 * Local payout row for a treasury cashout transfer (ignores stranded-funds recovery transfers).
	 *
	 * @param array $transfer Stripe transfer object.
	 * @return int
	 */
	private static function payout_id_for_treasury_transfer( $transfer ) {
		$metadata = is_array( $transfer['metadata'] ?? null ) ? $transfer['metadata'] : array();
		// Recovery transfers carry the cashout's payout_id but only fund the Connect payout that follows.
		if ( ! empty( $metadata['lnkup_recovery'] ) || ! empty( $metadata['cashout_recovery'] ) ) {
			return 0;
		}
		return absint( $metadata['payout_id'] ?? 0 );
	}

	/**
	 * Handle payout webhook events on connected account.
	 *
	 * @param array  $payout     Stripe payout object.
	 * @param string $type       Event type.
	 * @param string $account_id Connected account that sent the event (event.account).
	 */
	public static function handle_payout_event( $payout, $type, $account_id = '' ) {
		$local_id = absint( $payout['metadata']['payout_id'] ?? 0 );
		if ( ! $local_id || ! class_exists( 'LNKup_Organizer_Payouts' ) ) {
			return;
		}
		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_payouts_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT user_id, provider, provider_ref FROM {$table} WHERE id = %d", $local_id ), ARRAY_A );
		if ( ! $row ) {
			return;
		}
		// metadata.payout_id is set by whoever creates the payout: only trust it when the event came
		// from the owner's own connected account and the row is an LNKup Connect payout.
		$owner = self::get_account( (int) $row['user_id'], 'organizer' );
		if ( 'stripe_connect_payout' !== (string) $row['provider'] || '' === (string) $account_id || (string) ( $owner['stripe_account_id'] ?? '' ) !== (string) $account_id ) {
			LNKup_Financial_Audit::log( 'payout_event_rejected', 'payout', $local_id, array( 'type' => $type, 'account' => (string) $account_id ) );
			return;
		}
		$provider_ref = (string) $row['provider_ref'];
		$stripe_id    = (string) ( $payout['id'] ?? '' );
		if ( '' !== $provider_ref && '' !== $stripe_id && $provider_ref !== $stripe_id ) {
			return;
		}
		$status = (string) ( $payout['status'] ?? '' );
		if ( 'payout.paid' === $type || ( 'payout.updated' === $type && 'paid' === $status ) ) {
			LNKup_Organizer_Payouts::mark_paid( $local_id, $stripe_id );
		} elseif ( in_array( $type, array( 'payout.failed', 'payout.canceled' ), true ) || ( 'payout.updated' === $type && in_array( $status, array( 'failed', 'canceled' ), true ) ) ) {
			$reason = (string) ( $payout['failure_message'] ?? '' );
			if ( '' === $reason && 'canceled' === $status ) {
				$reason = __( 'Payout was canceled', 'lnkup-marketplace' );
			}
			LNKup_Organizer_Payouts::handle_async_failure( $local_id, $reason );
		}
	}

	/**
	 * Audit a refund on a destination charge.
	 *
	 * The transfer reversal itself happens at refund time (reverse_transfer=true in
	 * LNKup_Stripe_Provider::refund()), so this must not reverse again.
	 *
	 * @param int   $payment_id Payment post ID.
	 * @param float $amount     Cumulative refunded amount.
	 */
	public static function handle_destination_refund( $payment_id, $amount ) {
		if ( 'destination' !== get_post_meta( $payment_id, '_lnkup_settlement_mode', true ) ) {
			return;
		}
		LNKup_Financial_Audit::log( 'connect_refund_reversal', 'payment', $payment_id, array( 'amount_refunded' => $amount ) );
	}
}
