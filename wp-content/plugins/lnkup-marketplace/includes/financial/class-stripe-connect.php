<?php
/**
 * Stripe Connect architecture — onboarding, splits, destination charges.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Stripe_Connect {

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
		if ( ! empty( $account['disabled_reason'] ) ) {
			return 'disabled';
		}
		$verification = $account['verification_status'] ?? '';
		if ( 'rejected' === $verification ) {
			return 'rejected';
		}
		$past_due = json_decode( $account['requirements_past_due'] ?? '[]', true );
		if ( is_array( $past_due ) && count( $past_due ) > 0 ) {
			return 'verification_required';
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
		$status['available']       = $available;
		$status['settlement_mode'] = self::settlement_mode_for_organizer( $user_id );
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
		return home_url( '/organizer-dashboard/?connect=return#financial' );
	}

	/**
	 * Default Connect refresh URL.
	 */
	public static function connect_refresh_url() {
		$settings = lnkup_get_settings();
		if ( ! empty( $settings['connect_refresh_url'] ) ) {
			return $settings['connect_refresh_url'];
		}
		return home_url( '/organizer-dashboard/?connect=refresh#financial' );
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
			'user_id'               => absint( $user_id ),
			'role_type'             => sanitize_key( $role ),
			'stripe_account_id'     => sanitize_text_field( $data['stripe_account_id'] ?? '' ),
			'verification_status'   => sanitize_key( $data['verification_status'] ?? 'pending' ),
			'payouts_enabled'       => ! empty( $data['payouts_enabled'] ) ? 1 : 0,
			'charges_enabled'       => ! empty( $data['charges_enabled'] ) ? 1 : 0,
			'details_submitted'     => ! empty( $data['details_submitted'] ) ? 1 : 0,
			'requirements_due'      => isset( $data['requirements_due'] ) ? wp_json_encode( $data['requirements_due'] ) : null,
			'requirements_past_due' => isset( $data['requirements_past_due'] ) ? wp_json_encode( $data['requirements_past_due'] ) : null,
			'disabled_reason'       => sanitize_text_field( $data['disabled_reason'] ?? '' ),
			'last_synced_at'        => $data['last_synced_at'] ?? current_time( 'mysql' ),
			'metadata'              => ! empty( $data['metadata'] ) ? wp_json_encode( $data['metadata'] ) : null,
			'updated_at'            => current_time( 'mysql' ),
		);
		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $existing['id'] ) );
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
			return $remote;
		}
		self::apply_stripe_account_payload( $user_id, $remote );
		LNKup_Financial_Audit::log( 'connect_account_synced', 'user', $user_id, array( 'account_id' => $account['stripe_account_id'] ) );
		return self::get_status( $user_id );
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
				'stripe_account_id'   => $account['stripe_account_id'],
				'verification_status' => 'disabled',
				'charges_enabled'     => false,
				'payouts_enabled'     => false,
				'disabled_reason'     => 'deauthorized',
			)
		);
		LNKup_Financial_Audit::log( 'connect_deauthorized', 'user', $user_id, array() );
		return array( 'success' => true, 'status' => 'disabled' );
	}

	/**
	 * Apply Stripe Account API payload to local row.
	 *
	 * @param int   $user_id Organizer user ID.
	 * @param array $account Stripe account object.
	 */
	public static function apply_stripe_account_payload( $user_id, $account ) {
		$requirements = $account['requirements'] ?? array();
		self::upsert_account(
			$user_id,
			'organizer',
			array(
				'stripe_account_id'     => $account['id'] ?? '',
				'verification_status'   => ! empty( $account['details_submitted'] ) ? 'verified' : 'pending',
				'payouts_enabled'       => ! empty( $account['payouts_enabled'] ),
				'charges_enabled'       => ! empty( $account['charges_enabled'] ),
				'details_submitted'     => ! empty( $account['details_submitted'] ),
				'requirements_due'      => $requirements['currently_due'] ?? array(),
				'requirements_past_due' => $requirements['past_due'] ?? array(),
				'disabled_reason'       => $account['disabled_reason'] ?? '',
				'last_synced_at'        => current_time( 'mysql' ),
				'metadata'              => array( 'capabilities' => $account['capabilities'] ?? array() ),
			)
		);
	}

	/**
	 * Create Express account and onboarding link.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function create_onboarding_link( $user_id ) {
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
		if ( ! $account || empty( $account['stripe_account_id'] ) ) {
			$params = array(
				'type'     => ! empty( $settings['connect_express_enabled'] ) ? 'express' : 'standard',
				'country'  => $settings['connect_platform_country'] ?? 'US',
				'metadata' => array( 'organizer_user_id' => (string) $user_id ),
			);
			$created = $client->create_connect_account( $params );
			if ( is_wp_error( $created ) ) {
				return array(
					'success' => false,
					'code'    => 'stripe_api_error',
					'message' => $created->get_error_message(),
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
		$link = $client->create_account_link( array(
			'account'     => $account['stripe_account_id'],
			'refresh_url' => self::connect_refresh_url(),
			'return_url'  => self::connect_return_url(),
			'type'        => 'account_onboarding',
		) );
		if ( is_wp_error( $link ) ) {
			return array(
				'success' => false,
				'code'    => 'stripe_api_error',
				'message' => $link->get_error_message(),
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
	 * @param int   $payment_id Payment post ID.
	 * @param int   $organizer_id Organizer ID.
	 * @param array $breakdown Fee breakdown.
	 */
	public static function persist_payment_settlement_meta( $payment_id, $organizer_id, $breakdown ) {
		$mode = self::settlement_mode_for_organizer( $organizer_id );
		update_post_meta( $payment_id, '_lnkup_settlement_mode', $mode );
		update_post_meta( $payment_id, '_lnkup_application_fee_cents', (int) round( ( $breakdown['platform_fee'] ?? 0 ) * 100 ) );
		update_post_meta( $payment_id, '_lnkup_fee_breakdown', wp_json_encode( $breakdown ) );
		if ( 'destination' === $mode ) {
			$account = self::get_account( $organizer_id, 'organizer' );
			update_post_meta( $payment_id, '_lnkup_stripe_destination_account', $account['stripe_account_id'] ?? '' );
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
		$client   = new LNKup_Stripe_Client();
		$transfer = $client->create_transfer( array(
			'amount'      => (int) round( $amount * 100 ),
			'currency'    => $currency,
			'destination' => $account['stripe_account_id'],
			'metadata'    => array_merge( array( 'organizer_user_id' => (string) $organizer_id ), $metadata ),
		) );
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
			return new WP_Error( 'no_account', __( 'Organizer Connect account not found.', 'lnkup-marketplace' ) );
		}
		if ( ! self::is_connect_ready( $organizer_id ) ) {
			return new WP_Error( 'not_ready', __( 'Connect account is not ready for payouts.', 'lnkup-marketplace' ) );
		}
		if ( self::has_past_due_requirements( $organizer_id ) ) {
			return new WP_Error( 'requirements_past_due', __( 'Complete Stripe verification before cashing out.', 'lnkup-marketplace' ) );
		}
		$settings = lnkup_get_settings();
		if ( 'instant' === $type && empty( $settings['connect_instant_payouts_enabled'] ) ) {
			return new WP_Error( 'instant_disabled', __( 'Instant payouts are not enabled.', 'lnkup-marketplace' ) );
		}
		$currency = $settings['connect_platform_currency'] ?? 'usd';
		$client   = new LNKup_Stripe_Client();
		$params   = array(
			'amount'   => (int) round( $amount * 100 ),
			'currency' => $currency,
			'metadata' => array_merge( array( 'organizer_user_id' => (string) $organizer_id ), $metadata ),
		);
		if ( 'instant' === $type ) {
			$params['method'] = 'instant';
		}
		$payout = $client->create_connect_payout( $account['stripe_account_id'], $params );
		if ( is_wp_error( $payout ) ) {
			return $payout;
		}
		LNKup_Financial_Audit::log( 'connect_payout_created', 'payout', $organizer_id, array(
			'amount'     => $amount,
			'payout_id'  => $payout['id'] ?? '',
			'payout_type'=> $type,
		) );
		return array(
			'status'    => 'processing',
			'payout_id' => $payout['id'] ?? '',
		);
	}

	/**
	 * Handle account.updated webhook payload.
	 *
	 * @param array $account Stripe account object.
	 */
	public static function handle_account_updated( $account ) {
		$user_id = 0;
		if ( ! empty( $account['metadata']['organizer_user_id'] ) ) {
			$user_id = (int) $account['metadata']['organizer_user_id'];
		}
		if ( ! $user_id ) {
			global $wpdb;
			$table = LNKup_Financial_Tables::connected_accounts_table();
			$row   = $wpdb->get_row(
				$wpdb->prepare( "SELECT user_id FROM {$table} WHERE stripe_account_id = %s LIMIT 1", $account['id'] ?? '' ),
				ARRAY_A
			);
			$user_id = $row ? (int) $row['user_id'] : 0;
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
		$payout_id = (int) ( $transfer['metadata']['payout_id'] ?? 0 );
		if ( $payout_id && class_exists( 'LNKup_Organizer_Payouts' ) ) {
			LNKup_Organizer_Payouts::mark_paid( $payout_id, $transfer['id'] ?? '' );
		}
	}

	/**
	 * Handle transfer.failed webhook.
	 *
	 * @param array $transfer Stripe transfer object.
	 */
	public static function handle_transfer_failed( $transfer ) {
		$payout_id = (int) ( $transfer['metadata']['payout_id'] ?? 0 );
		if ( ! $payout_id ) {
			return;
		}
		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_payouts_table();
		$wpdb->update(
			$table,
			array(
				'status'         => 'failed',
				'failure_reason' => sanitize_text_field( $transfer['failure_message'] ?? __( 'Transfer failed', 'lnkup-marketplace' ) ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $payout_id )
		);
	}

	/**
	 * Handle payout webhook events on connected account.
	 *
	 * @param array  $payout Stripe payout object.
	 * @param string $type   Event type.
	 */
	public static function handle_payout_event( $payout, $type ) {
		$local_id = (int) ( $payout['metadata']['payout_id'] ?? 0 );
		if ( ! $local_id ) {
			return;
		}
		if ( 'payout.paid' === $type ) {
			LNKup_Organizer_Payouts::mark_paid( $local_id, $payout['id'] ?? '' );
		} elseif ( 'payout.failed' === $type ) {
			global $wpdb;
			$table = LNKup_Treasury_Tables::organizer_payouts_table();
			$wpdb->update(
				$table,
				array(
					'status'         => 'failed',
					'failure_reason' => sanitize_text_field( $payout['failure_message'] ?? __( 'Payout failed', 'lnkup-marketplace' ) ),
					'updated_at'     => current_time( 'mysql' ),
				),
				array( 'id' => $local_id )
			);
		}
	}

	/**
	 * Connect-aware refund reversal for destination charges.
	 *
	 * @param int   $payment_id Payment post ID.
	 * @param float $amount     Refund amount.
	 */
	public static function handle_destination_refund( $payment_id, $amount ) {
		if ( 'destination' !== get_post_meta( $payment_id, '_lnkup_settlement_mode', true ) ) {
			return;
		}
		$transfer_id = get_post_meta( $payment_id, '_lnkup_stripe_transfer_id', true );
		$client      = new LNKup_Stripe_Client();
		if ( $transfer_id ) {
			$client->reverse_transfer( $transfer_id, array( 'amount' => (int) round( $amount * 100 ) ) );
		}
		LNKup_Financial_Audit::log( 'connect_refund_reversal', 'payment', $payment_id, array( 'amount' => $amount ) );
	}
}
