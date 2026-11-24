<?php
/**
 * Maps marketplace lifecycle hooks to canonical conversions.
 *
 * Only authoritative server hooks are used (they fire after the business action succeeded),
 * never button clicks. Milestones ("First…") check real counts so accounts that existed
 * before this module shipped do not report a first event on their fifth action.
 *
 * Purchase convention: value = amount the vendor actually paid for the booking
 * (_lnkup_amount_paid_cents), currency = platform currency. LNKup platform revenue
 * (_lnkup_lnkup_fee) is stored internally in value_internal and never sent to Meta.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Conversion_Listeners {

	const PAYMENT_CONTEXT_META = '_lnkup_meta_checkout_ctx';

	public static function init(): void {
		add_action( 'lnkup_user_registered', array( __CLASS__, 'on_registered' ), 20, 3 );
		add_action( 'lnkup_vendor_profile_saved', array( __CLASS__, 'on_vendor_profile_saved' ), 20, 2 );
		add_action( 'lnkup_organizer_profile_saved', array( __CLASS__, 'on_organizer_profile_saved' ), 20, 1 );
		add_action( 'lnkup_event_status_changed', array( __CLASS__, 'on_event_status_changed' ), 30, 3 );
		add_action( 'lnkup_event_first_live', array( __CLASS__, 'on_event_first_live' ), 20, 2 );
		add_action( 'lnkup_opportunity_published', array( __CLASS__, 'on_opportunity_published' ), 20, 2 );
		add_action( 'lnkup_vendor_invite_created', array( __CLASS__, 'on_invite_created' ), 20, 5 );
		add_action( 'lnkup_application_submitted', array( __CLASS__, 'on_application_submitted' ), 30, 1 );
		add_action( 'lnkup_application_approved', array( __CLASS__, 'on_application_approved' ), 30, 1 );
		add_action( 'lnkup_application_status_changed', array( __CLASS__, 'on_application_status_changed' ), 30, 3 );
		add_action( 'lnkup_application_event_completed', array( __CLASS__, 'on_application_event_completed' ), 30, 2 );
		add_action( 'lnkup_checkout_started', array( __CLASS__, 'on_checkout_started' ), 20, 2 );
		add_action( 'lnkup_payment_completed', array( __CLASS__, 'on_payment_completed' ), 30, 1 );
		add_action( 'lnkup_refund_completed', array( __CLASS__, 'on_refund_completed' ), 30, 1 );
		add_action( 'lnkup_favorite_toggled', array( __CLASS__, 'on_favorite_toggled' ), 20, 4 );
	}

	/**
	 * Count posts of a type owned by a user through a meta key, with optional extra meta filters.
	 *
	 * @param string              $post_type Post type.
	 * @param string              $owner_key Meta key holding the user ID.
	 * @param int                 $user_id   User.
	 * @param array<int,array>    $extra     Extra meta_query clauses.
	 * @param int                 $cap       Stop counting after this many.
	 */
	private static function count_owned( string $post_type, string $owner_key, int $user_id, array $extra = array(), int $cap = 3 ): int {
		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => $cap,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array_merge( array( 'relation' => 'AND', array( 'key' => $owner_key, 'value' => $user_id ) ), $extra ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
			)
		);
		return count( $query->posts );
	}

	private static function currency(): string {
		$settings = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		return strtoupper( (string) ( $settings['connect_platform_currency'] ?? 'usd' ) );
	}

	private static function role_slug( string $role ): string {
		return 'lnkup_organizer' === $role ? 'organizer' : 'vendor';
	}

	private static function organizer_of( int $post_id ): int {
		$org = (int) get_post_meta( $post_id, '_lnkup_organizer_user_id', true );
		return $org ? $org : (int) get_post_field( 'post_author', $post_id );
	}

	private static function is_organizer( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, 'lnkup_manage_own_events' );
	}

	/**
	 * Registration: CompleteRegistration + role-specific event.
	 *
	 * @param int                 $user_id User.
	 * @param string              $role    Role.
	 * @param array<string,mixed> $context Context.
	 */
	public static function on_registered( $user_id, $role, $context = array() ): void {
		$user_id = (int) $user_id;
		$slug    = self::role_slug( (string) $role );
		$market  = is_array( $context ) ? (string) ( $context['market'] ?? '' ) : '';
		$custom  = array(
			'content_name'     => 'organizer' === $slug ? 'Organizer account' : 'Vendor account',
			'status'           => 'completed',
			'marketplace_role' => $slug,
			'campaign_market'  => $market,
		);
		$args = array( 'user_id' => $user_id, 'role' => $slug, 'custom_data' => $custom, 'capture_request' => true );
		LNKup_Conversion_Registry::record( 'CompleteRegistration', $args );
		LNKup_Conversion_Registry::record( 'organizer' === $slug ? 'OrganizerRegistered' : 'VendorRegistered', $args );
	}

	/**
	 * @param int $user_id    User.
	 * @param int $profile_id Vendor profile.
	 */
	public static function on_vendor_profile_saved( $user_id, $profile_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! class_exists( 'LNKup_Growth_Profile_Strength' ) ) {
			return;
		}
		$calc = LNKup_Growth_Profile_Strength::calculate( (int) $profile_id );
		$min  = (int) LNKup_Meta_Config::settings()['vendor_activation_profile_min'];
		if ( (int) ( $calc['score'] ?? 0 ) < $min ) {
			return;
		}
		LNKup_Conversion_Registry::record( 'VendorProfileCompleted', array( 'user_id' => $user_id, 'role' => 'vendor', 'custom_data' => array( 'marketplace_role' => 'vendor' ) ) );
		self::maybe_vendor_activated( $user_id );
	}

	/**
	 * Organizer essentials: public name and city.
	 *
	 * @param int $user_id User.
	 */
	public static function on_organizer_profile_saved( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return;
		}
		$name = trim( (string) get_user_meta( $user_id, '_lnkup_organizer_public_name', true ) );
		$city = trim( (string) get_user_meta( $user_id, '_lnkup_organizer_city', true ) );
		if ( '' === $name || '' === $city ) {
			return;
		}
		LNKup_Conversion_Registry::record( 'OrganizerProfileCompleted', array( 'user_id' => $user_id, 'role' => 'organizer', 'custom_data' => array( 'marketplace_role' => 'organizer' ) ) );
	}

	/**
	 * Event creation is the first status meta write on a new event.
	 *
	 * @param int    $event_id Event.
	 * @param string $old      Old status.
	 * @param string $new      New status.
	 */
	public static function on_event_status_changed( $event_id, $old, $new ): void {
		unset( $new );
		if ( '' !== (string) $old ) {
			return;
		}
		$event_id = (int) $event_id;
		$org      = self::organizer_of( $event_id );
		if ( ! self::is_organizer( $org ) || '' !== (string) get_post_meta( $event_id, '_lnkup_external_event_id', true ) ) {
			return;
		}
		$args = array(
			'user_id'     => $org,
			'role'        => 'organizer',
			'entity_type' => 'event',
			'entity_id'   => $event_id,
			'custom_data' => array( 'content_ids' => array( $event_id ), 'content_type' => 'event', 'marketplace_role' => 'organizer' ),
		);
		if ( null === LNKup_Conversion_Registry::record( 'EventCreated', $args ) ) {
			return;
		}
		if ( 1 === self::count_owned( 'lnkup_event', '_lnkup_organizer_user_id', $org, array(), 2 ) ) {
			LNKup_Conversion_Registry::record( 'FirstEventCreated', array_merge( $args, array( 'entity_type' => '', 'entity_id' => 0 ) ) );
		}
	}

	/**
	 * Listing went live (it has at least one open vendor slot): EventPublished and organizer activation.
	 *
	 * @param int $event_id Event.
	 * @param int $org      Organizer.
	 */
	public static function on_event_first_live( $event_id, $org ): void {
		$event_id = (int) $event_id;
		$org      = (int) $org;
		if ( ! self::is_organizer( $org ) ) {
			return;
		}
		$custom = array( 'content_ids' => array( $event_id ), 'content_type' => 'event', 'marketplace_role' => 'organizer', 'event_category' => self::event_category( $event_id ) );
		$base   = array( 'user_id' => $org, 'role' => 'organizer', 'custom_data' => $custom );
		if ( null === LNKup_Conversion_Registry::record( 'EventPublished', array_merge( $base, array( 'entity_type' => 'event', 'entity_id' => $event_id ) ) ) ) {
			return;
		}
		$live = self::count_owned( 'lnkup_event', '_lnkup_organizer_user_id', $org, array( array( 'key' => '_lnkup_first_published_at', 'compare' => 'EXISTS' ) ), 2 );
		if ( 1 === $live ) {
			LNKup_Conversion_Registry::record( 'FirstEventPublished', $base );
		} elseif ( $live > 1 ) {
			LNKup_Conversion_Registry::record( 'RepeatOrganizerEvent', array_merge( $base, array( 'entity_type' => 'event', 'entity_id' => $event_id ) ) );
		}
		// A live listing always has an open vendor opportunity, which is the organizer activation rule.
		LNKup_Conversion_Registry::record( 'OrganizerActivated', $base );
	}

	/**
	 * @param int $opp_id Opportunity.
	 * @param int $org    Organizer.
	 */
	public static function on_opportunity_published( $opp_id, $org ): void {
		$opp_id = (int) $opp_id;
		$org    = (int) $org;
		$posted = (int) get_post_time( 'U', true, $opp_id );
		// Slots that existed before this module shipped and are merely reopened are not new publications.
		if ( ! self::is_organizer( $org ) || ( $posted && $posted < time() - 30 * DAY_IN_SECONDS ) ) {
			return;
		}
		$event_id = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
		LNKup_Conversion_Registry::record(
			'VendorOpportunityPublished',
			array(
				'user_id'     => $org,
				'role'        => 'organizer',
				'entity_type' => 'opportunity',
				'entity_id'   => $opp_id,
				'custom_data' => array( 'content_ids' => array( $opp_id ), 'content_type' => 'product', 'opportunity_id' => (string) $opp_id, 'event_id' => (string) $event_id, 'marketplace_role' => 'organizer' ),
			)
		);
	}

	/**
	 * @param int $invite_id Invite.
	 * @param int $org       Organizer.
	 * @param int $vendor    Vendor (unused; the invited business is never identified to Meta).
	 * @param int $event_id  Event.
	 * @param int $opp_id    Opportunity.
	 */
	public static function on_invite_created( $invite_id, $org, $vendor = 0, $event_id = 0, $opp_id = 0 ): void {
		unset( $vendor );
		$org = (int) $org;
		if ( ! self::is_organizer( $org ) ) {
			return;
		}
		$custom = array( 'marketplace_role' => 'organizer', 'event_id' => (string) (int) $event_id, 'opportunity_id' => (string) (int) $opp_id );
		if ( null === LNKup_Conversion_Registry::record( 'VendorInvited', array( 'user_id' => $org, 'role' => 'organizer', 'entity_type' => 'invite', 'entity_id' => (int) $invite_id, 'custom_data' => $custom ) ) ) {
			return;
		}
		if ( 1 === self::count_owned( 'lnkup_vendor_invite', '_lnkup_organizer_user_id', $org, array(), 2 ) ) {
			LNKup_Conversion_Registry::record( 'FirstVendorInvited', array( 'user_id' => $org, 'role' => 'organizer', 'custom_data' => $custom ) );
		}
	}

	/**
	 * Non-sensitive application context (never answers, documents or messages).
	 *
	 * @param int $app_id Application.
	 * @return array<string,mixed>
	 */
	private static function application_custom( int $app_id ): array {
		$opp   = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		$event = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		return array(
			'content_ids'      => $opp ? array( $opp ) : array(),
			'content_type'     => 'product',
			'opportunity_id'   => $opp ? (string) $opp : '',
			'event_id'         => $event ? (string) $event : '',
			'event_category'   => $event ? self::event_category( $event ) : '',
			'marketplace_role' => 'vendor',
		);
	}

	private static function event_category( int $event_id ): string {
		$terms = get_the_terms( $event_id, 'lnkup_event_type' );
		return is_array( $terms ) && isset( $terms[0] ) ? (string) $terms[0]->name : '';
	}

	/**
	 * @param int $app_id Application.
	 */
	public static function on_application_submitted( $app_id ): void {
		$app_id = (int) $app_id;
		$vendor = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		if ( $vendor < 1 ) {
			return;
		}
		$custom = self::application_custom( $app_id );
		if ( null === LNKup_Conversion_Registry::record( 'SubmitApplication', array( 'user_id' => $vendor, 'entity_type' => 'application', 'entity_id' => $app_id, 'custom_data' => $custom ) ) ) {
			return;
		}
		if ( 1 === self::count_owned( 'lnkup_application', '_lnkup_vendor_user_id', $vendor, array( array( 'key' => '_lnkup_application_status', 'value' => 'draft', 'compare' => '!=' ) ), 2 ) ) {
			LNKup_Conversion_Registry::record( 'FirstVendorApplication', array( 'user_id' => $vendor, 'custom_data' => $custom ) );
		}
		self::maybe_vendor_activated( $vendor );

		$org = (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true );
		if ( self::is_organizer( $org ) && 1 === self::count_owned( 'lnkup_application', '_lnkup_organizer_user_id', $org, array( array( 'key' => '_lnkup_application_status', 'value' => 'draft', 'compare' => '!=' ) ), 2 ) ) {
			LNKup_Conversion_Registry::record( 'OrganizerFirstApplicationReceived', array( 'user_id' => $org, 'role' => 'organizer', 'capture_request' => false, 'custom_data' => array( 'marketplace_role' => 'organizer', 'event_id' => $custom['event_id'] ) ) );
		}
	}

	/**
	 * Vendor activation: essential profile plus at least N submitted applications.
	 *
	 * @param int $vendor Vendor user.
	 */
	private static function maybe_vendor_activated( int $vendor ): void {
		$settings = LNKup_Meta_Config::settings();
		$min_apps = max( 1, (int) $settings['vendor_activation_min_applications'] );
		$profile  = function_exists( 'lnkup_get_vendor_profile_id' ) ? (int) lnkup_get_vendor_profile_id( $vendor ) : 0;
		if ( ! $profile || ! class_exists( 'LNKup_Growth_Profile_Strength' ) ) {
			return;
		}
		$calc = LNKup_Growth_Profile_Strength::calculate( $profile );
		if ( (int) ( $calc['score'] ?? 0 ) < (int) $settings['vendor_activation_profile_min'] ) {
			return;
		}
		$apps = self::count_owned( 'lnkup_application', '_lnkup_vendor_user_id', $vendor, array( array( 'key' => '_lnkup_application_status', 'value' => 'draft', 'compare' => '!=' ) ), $min_apps );
		if ( $apps >= $min_apps ) {
			LNKup_Conversion_Registry::record( 'VendorActivated', array( 'user_id' => $vendor, 'role' => 'vendor', 'custom_data' => array( 'marketplace_role' => 'vendor' ) ) );
		}
	}

	/**
	 * @param int $app_id Application.
	 */
	public static function on_application_approved( $app_id ): void {
		$app_id = (int) $app_id;
		$vendor = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		if ( $vendor < 1 ) {
			return;
		}
		$custom = self::application_custom( $app_id );
		$args   = array( 'user_id' => $vendor, 'capture_request' => false, 'custom_data' => $custom );
		if ( null === LNKup_Conversion_Registry::record( 'ApplicationApproved', array_merge( $args, array( 'entity_type' => 'application', 'entity_id' => $app_id ) ) ) ) {
			return;
		}
		$approved = self::count_owned(
			'lnkup_application',
			'_lnkup_vendor_user_id',
			$vendor,
			array( array( 'key' => '_lnkup_application_status', 'value' => array( 'approved', 'payment_required', 'paid', 'confirmed', 'completed' ), 'compare' => 'IN' ) ),
			2
		);
		if ( 1 === $approved ) {
			LNKup_Conversion_Registry::record( 'FirstApplicationApproved', $args );
		}
	}

	/**
	 * Approval paths that create no payment (free booths) still pass through `approved`.
	 *
	 * @param int    $app_id Application.
	 * @param string $prev   Previous status.
	 * @param string $new    New status.
	 */
	public static function on_application_status_changed( $app_id, $prev, $new ): void {
		unset( $prev );
		if ( in_array( (string) $new, array( 'approved', 'payment_required' ), true ) ) {
			self::on_application_approved( $app_id );
		}
	}

	/**
	 * @param int $app_id   Application.
	 * @param int $event_id Event.
	 */
	public static function on_application_event_completed( $app_id, $event_id ): void {
		unset( $event_id );
		$app_id = (int) $app_id;
		$vendor = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		if ( $vendor > 0 ) {
			LNKup_Conversion_Registry::record( 'VendorEventCompleted', array( 'user_id' => $vendor, 'entity_type' => 'application', 'entity_id' => $app_id, 'capture_request' => false, 'custom_data' => self::application_custom( $app_id ) ) );
		}
	}

	/**
	 * InitiateCheckout. The vendor's browser context is kept on the payment so the Purchase,
	 * confirmed later by a Stripe webhook with no browser, can still be matched.
	 *
	 * @param int    $payment_id Payment.
	 * @param string $mode       Checkout mode.
	 */
	public static function on_checkout_started( $payment_id, $mode = '' ): void {
		unset( $mode );
		$payment_id = (int) $payment_id;
		$vendor     = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		if ( $vendor < 1 || get_current_user_id() !== $vendor ) {
			return;
		}
		$context = LNKup_Meta_Cookies::request_context();
		if ( 'granted' === LNKup_Tracking_Consent::state( $vendor, true ) && array() !== $context ) {
			update_post_meta(
				$payment_id,
				self::PAYMENT_CONTEXT_META,
				array(
					'context' => $context,
					'url'     => LNKup_Conversion_Registry::clean_source_url( wp_get_referer() ? (string) wp_get_referer() : '' ),
					'at'      => time(),
				)
			);
		}
		$opp = (int) get_post_meta( $payment_id, '_lnkup_opportunity_id', true );
		LNKup_Conversion_Registry::record(
			'InitiateCheckout',
			array(
				'user_id'     => $vendor,
				'entity_type' => 'payment',
				'entity_id'   => $payment_id,
				'custom_data' => array(
					'content_ids' => $opp ? array( $opp ) : array(),
					'content_type' => 'product',
					'content_name' => $opp ? 'Vendor booth' : '',
					'value'       => class_exists( 'LNKup_Financial_Service' ) ? LNKup_Financial_Service::stored_amount_due( $payment_id ) : 0,
					'currency'    => self::currency(),
					'num_items'   => 1,
				),
			)
		);
	}

	/**
	 * Stored checkout context for a payment (only used if captured within the last 7 days).
	 *
	 * @param int $payment_id Payment.
	 * @return array{context:array,url:string}
	 */
	private static function checkout_context( int $payment_id ): array {
		$stored = get_post_meta( $payment_id, self::PAYMENT_CONTEXT_META, true );
		if ( ! is_array( $stored ) || (int) ( $stored['at'] ?? 0 ) < time() - 7 * DAY_IN_SECONDS ) {
			return array( 'context' => array(), 'url' => '' );
		}
		return array( 'context' => (array) ( $stored['context'] ?? array() ), 'url' => (string) ( $stored['url'] ?? '' ) );
	}

	/**
	 * Fires once per payment after `finalize_payment` confirmed it as paid (webhook or return page).
	 *
	 * @param int $payment_id Payment.
	 */
	public static function on_payment_completed( $payment_id ): void {
		$payment_id = (int) $payment_id;
		if ( 'paid' !== (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) ) {
			return;
		}
		$vendor = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		if ( $vendor < 1 ) {
			return;
		}
		$opp      = (int) get_post_meta( $payment_id, '_lnkup_opportunity_id', true );
		$amount   = round( (int) get_post_meta( $payment_id, '_lnkup_amount_paid_cents', true ) / 100, 2 );
		$platform = round( (float) get_post_meta( $payment_id, '_lnkup_lnkup_fee', true ), 2 );
		$ctx      = self::checkout_context( $payment_id );
		$in_req   = get_current_user_id() === $vendor;
		$base     = array(
			'user_id'         => $vendor,
			'capture_request' => $in_req,
			'value_internal'  => $platform,
		);
		if ( ! $in_req && array() !== $ctx['context'] ) {
			$base['context']    = $ctx['context'];
			$base['source_url'] = $ctx['url'];
		}
		$custom = array(
			'content_ids'  => $opp ? array( $opp ) : array(),
			'content_type' => 'product',
			'contents'     => $opp ? array( array( 'id' => $opp, 'quantity' => 1 ) ) : array(),
			'num_items'    => 1,
			'order_id'     => (string) $payment_id,
		);

		if ( $amount > 0 ) {
			LNKup_Conversion_Registry::record(
				'Purchase',
				array_merge( $base, array( 'entity_type' => 'payment', 'entity_id' => $payment_id, 'custom_data' => array_merge( $custom, array( 'value' => $amount, 'currency' => self::currency() ) ) ) )
			);
		}

		$paid_count = self::count_owned( 'lnkup_payment', '_lnkup_vendor_user_id', $vendor, array( array( 'key' => '_lnkup_payment_status', 'value' => 'paid' ) ), 2 );
		if ( 1 === $paid_count ) {
			LNKup_Conversion_Registry::record( 'FirstVendorBooking', array_merge( $base, array( 'custom_data' => $custom ) ) );
		} elseif ( $paid_count > 1 ) {
			LNKup_Conversion_Registry::record( 'RepeatVendorBooking', array_merge( $base, array( 'entity_type' => 'payment', 'entity_id' => $payment_id, 'custom_data' => $custom ) ) );
		}

		$org = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		if ( self::is_organizer( $org ) ) {
			$org_paid = self::count_owned( 'lnkup_payment', '_lnkup_organizer_user_id', $org, array( array( 'key' => '_lnkup_payment_status', 'value' => 'paid' ) ), 2 );
			if ( 1 === $org_paid ) {
				$org_args = array( 'user_id' => $org, 'role' => 'organizer', 'capture_request' => false, 'custom_data' => array( 'marketplace_role' => 'organizer' ) );
				LNKup_Conversion_Registry::record( 'OrganizerFirstVendorConfirmed', $org_args );
				if ( $amount > 0 ) {
					LNKup_Conversion_Registry::record( 'OrganizerFirstPaidBooking', $org_args );
				}
			}
		}
	}

	/**
	 * Refunds are internal only (no negative Purchase is sent to Meta).
	 *
	 * @param int $refund_id Refund.
	 */
	public static function on_refund_completed( $refund_id ): void {
		global $wpdb;
		$refund_id = (int) $refund_id;
		if ( ! class_exists( 'LNKup_Financial_Tables' ) || $refund_id < 1 ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$refund     = $wpdb->get_row( $wpdb->prepare( 'SELECT payment_post_id, vendor_user_id, amount FROM ' . LNKup_Financial_Tables::refunds_table() . ' WHERE id = %d', $refund_id ), ARRAY_A );
		$payment_id = is_array( $refund ) ? (int) $refund['payment_post_id'] : 0;
		$vendor     = is_array( $refund ) ? (int) $refund['vendor_user_id'] : 0;
		LNKup_Conversion_Registry::record(
			'PaymentRefunded',
			array(
				'user_id'         => $vendor,
				'entity_type'     => 'refund',
				'entity_id'       => $refund_id,
				'capture_request' => false,
				'value_internal'  => is_array( $refund ) ? -1 * abs( (float) $refund['amount'] ) : null,
				'custom_data'     => array( 'order_id' => (string) $payment_id ),
			)
		);
	}

	/**
	 * @param int    $user_id User.
	 * @param string $type    events|organizers|opportunities.
	 * @param int    $id      Entity.
	 * @param bool   $added   Added (true) or removed.
	 */
	public static function on_favorite_toggled( $user_id, $type, $id, $added ): void {
		if ( ! $added || ! in_array( (string) $type, array( 'events', 'opportunities' ), true ) ) {
			return;
		}
		$entity = 'events' === $type ? 'event' : 'opportunity';
		LNKup_Conversion_Registry::record(
			'AddToWishlist',
			array(
				'user_id'     => (int) $user_id,
				'entity_type' => $entity,
				'entity_id'   => (int) $id,
				'custom_data' => array(
					'content_ids'      => array( (int) $id ),
					'content_type'     => 'product',
					'content_category' => $entity,
					'content_name'     => wp_strip_all_tags( (string) get_the_title( (int) $id ) ),
				),
			)
		);
	}
}
