<?php
/**
 * Organizer activation model — the single source for "where is this organizer in setup".
 *
 * Six stages, all derived from existing records (profile meta, Stripe Connect status, events,
 * opportunities, applications, payments). Nothing is stored here except a short cache. The
 * launch checklist, the Get started view, Communications nudges, the Expo home card and the
 * Organizer Growth drawer all read this model so they always agree.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_Activation {

	const STAGES        = array( 'organization', 'payouts', 'first_event', 'publication', 'first_applications', 'first_paid_booking' );
	const CACHE_TTL     = 120;
	const PAID_STATUSES = array( 'paid', 'partially_refunded' );
	const APPROVED_APP  = array( 'approved', 'payment_required', 'paid', 'completed' );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		foreach ( array( 'save_post_lnkup_event', 'save_post_lnkup_opportunity', 'save_post_lnkup_application' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_for_post' ), 20, 1 );
		}
		add_action( 'lnkup_event_status_changed', array( __CLASS__, 'flush_for_post' ), 20, 1 );
		add_action( 'lnkup_opportunity_status_changed', array( __CLASS__, 'flush_for_post' ), 20, 1 );
		add_action( 'lnkup_application_submitted', array( __CLASS__, 'flush_for_post' ), 20, 1 );
		add_action( 'lnkup_application_status_changed', array( __CLASS__, 'flush_for_post' ), 20, 1 );
		add_action( 'lnkup_payment_completed', array( __CLASS__, 'flush_for_post' ), 20, 1 );
		add_action( 'lnkup_payment_refund_synced', array( __CLASS__, 'flush_for_post' ), 20, 1 );
		add_action( 'updated_user_meta', array( __CLASS__, 'flush_for_meta' ), 20, 3 );
		add_action( 'added_user_meta', array( __CLASS__, 'flush_for_meta' ), 20, 3 );
	}

	// ------------------------------------------------------------------ Cache.

	private static function cache_key( int $user_id ): string {
		return 'lnkup_org_activation_' . $user_id;
	}

	public static function flush( int $user_id ): void {
		if ( $user_id > 0 ) {
			delete_transient( self::cache_key( $user_id ) );
		}
	}

	/**
	 * @param int|string $post_id Post ID from lifecycle hooks.
	 */
	public static function flush_for_post( $post_id ): void {
		$organizer = (int) get_post_meta( (int) $post_id, '_lnkup_organizer_user_id', true );
		if ( ! $organizer ) {
			$event_id  = (int) get_post_meta( (int) $post_id, '_lnkup_event_id', true );
			$organizer = $event_id ? (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ) : 0;
		}
		self::flush( $organizer );
	}

	/**
	 * @param int    $meta_id  Meta row.
	 * @param int    $user_id  User.
	 * @param string $meta_key Key.
	 */
	public static function flush_for_meta( $meta_id, $user_id, $meta_key ): void {
		unset( $meta_id );
		if ( 0 === strpos( (string) $meta_key, '_lnkup_organizer_' ) || 0 === strpos( (string) $meta_key, '_lnkup_stripe' ) || 0 === strpos( (string) $meta_key, '_lnkup_connect' ) ) {
			self::flush( (int) $user_id );
		}
	}

	// ------------------------------------------------------------------ Facts.

	/**
	 * Raw organizer facts shared by the stages and the launch checklist.
	 *
	 * @return array<string, mixed>
	 */
	public static function facts( int $organizer_id ): array {
		$organizer_id = absint( $organizer_id );
		$events       = $organizer_id ? get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $organizer_id,
			)
		) : array();
		$event_ids = array_map( static fn( $e ) => (int) $e->ID, $events );
		$opps      = $event_ids ? get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => '_lnkup_event_id',
						'value'   => $event_ids,
						'compare' => 'IN',
					),
				),
			)
		) : array();
		$apps     = function_exists( 'lnkup_get_applications_for_user' ) && $organizer_id ? (array) lnkup_get_applications_for_user( $organizer_id, 'organizer' ) : array();
		$payments = function_exists( 'lnkup_get_payments_for_user' ) && $organizer_id ? (array) lnkup_get_payments_for_user( $organizer_id, 'organizer' ) : array();

		// Prefer an event that is live now; an event that went live before (and has since ended)
		// still counts as published.
		$live_event = null;
		$was_live   = null;
		foreach ( $events as $event ) {
			if ( 'live' === (string) get_post_meta( $event->ID, '_lnkup_event_status', true ) ) {
				$live_event = $event;
				break;
			}
			if ( ! $was_live && '' !== (string) get_post_meta( $event->ID, '_lnkup_first_published_at', true ) ) {
				$was_live = $event;
			}
		}
		$live_event = $live_event ?: $was_live;

		// The most complete slot, preferring one on the live event, then the newest event.
		$best_opp   = null;
		$best_score = -1;
		foreach ( $opps as $opp ) {
			$score = self::opportunity_completeness( (int) $opp->ID )['score'];
			if ( $live_event && (int) get_post_meta( $opp->ID, '_lnkup_event_id', true ) === (int) $live_event->ID ) {
				$score += 10;
			}
			if ( $score >= $best_score ) {
				$best_score = $score;
				$best_opp   = $opp;
			}
		}
		$primary_event = $live_event;
		if ( ! $primary_event && $best_opp ) {
			$primary_event = get_post( (int) get_post_meta( $best_opp->ID, '_lnkup_event_id', true ) ) ?: null;
		}
		if ( ! $primary_event && $events ) {
			$primary_event = end( $events );
		}

		$app_statuses = array();
		foreach ( $apps as $app ) {
			$app_statuses[] = (string) get_post_meta( $app->ID, '_lnkup_application_status', true );
		}
		$paid = 0;
		foreach ( $payments as $payment ) {
			if ( in_array( (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true ), self::PAID_STATUSES, true ) ) {
				++$paid;
			}
		}

		return array(
			'organizer_id'  => $organizer_id,
			'events'        => $events,
			'opps'          => $opps,
			'apps'          => $apps,
			'payments'      => $payments,
			'live_event'    => $live_event,
			'primary_event' => $primary_event,
			'primary_opp'   => $best_opp,
			'app_statuses'  => $app_statuses,
			'paid_count'    => $paid,
			'profile'       => self::profile( $organizer_id ),
			'payout'        => self::payout( $organizer_id ),
		);
	}

	/**
	 * Setup completeness of one vendor slot.
	 *
	 * @return array{categories:bool,price:bool,deadline:bool,score:int}
	 */
	public static function opportunity_completeness( int $opp_id ): array {
		$terms      = wp_get_post_terms( $opp_id, 'lnkup_vendor_category', array( 'fields' => 'ids' ) );
		$categories = ! is_wp_error( $terms ) && ! empty( $terms );
		// A price counts once the organizer set it — $0 (free booth) is a valid price.
		$price    = '' !== (string) get_post_meta( $opp_id, '_lnkup_vendor_fee', true );
		$deadline = '' !== (string) get_post_meta( $opp_id, '_lnkup_application_deadline', true );
		return array(
			'categories' => $categories,
			'price'      => $price,
			'deadline'   => $deadline,
			'score'      => (int) $categories + (int) $price + (int) $deadline,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function profile( int $user_id ): array {
		$get  = static fn( string $k ): string => trim( (string) get_user_meta( $user_id, '_lnkup_organizer_' . $k, true ) );
		$user = get_userdata( $user_id );
		$name = $get( 'public_name' );
		return array(
			'name'    => '' !== $name ? $name : ( $user ? (string) $user->display_name : '' ),
			'has_name' => '' !== $name,
			'city'    => $get( 'city' ),
			'state'   => $get( 'state' ),
			'logo'    => (int) $get( 'logo_id' ) > 0,
			'bio'     => '' !== $get( 'bio' ) || '' !== $get( 'tagline' ),
			'website' => $get( 'website' ),
		);
	}

	/**
	 * Payout readiness in the same friendly terms the Financial tab uses.
	 *
	 * @return array{state:string,label:string,ready:bool,started:bool,required:bool}
	 */
	public static function payout( int $user_id ): array {
		if ( ! class_exists( 'LNKup_Stripe_Connect' ) || ! LNKup_Stripe_Connect::is_enabled() ) {
			return array(
				'state'    => 'manual',
				'label'    => __( 'Paid by LNKup', 'lnkup-marketplace' ),
				'ready'    => true,
				'started'  => true,
				'required' => false,
			);
		}
		if ( LNKup_Stripe_Connect::is_connect_ready( $user_id ) ) {
			return array(
				'state'    => 'active',
				'label'    => __( 'Payouts active', 'lnkup-marketplace' ),
				'ready'    => true,
				'started'  => true,
				'required' => true,
			);
		}
		$connect = (array) LNKup_Stripe_Connect::get_status( $user_id );
		$status  = (string) ( $connect['status'] ?? 'not_started' );
		$map     = array(
			'pending'               => array( 'setup_incomplete', __( 'Finish payout setup', 'lnkup-marketplace' ) ),
			'restricted'            => array( 'setup_incomplete', __( 'Finish payout setup', 'lnkup-marketplace' ) ),
			'pending_verification'  => array( 'verifying', __( 'Verifying payout details', 'lnkup-marketplace' ) ),
			'verification_required' => array( 'action_required', __( 'Payout details needed', 'lnkup-marketplace' ) ),
			'disabled'              => array( 'paused', __( 'Payouts paused', 'lnkup-marketplace' ) ),
			'rejected'              => array( 'paused', __( 'Payouts paused', 'lnkup-marketplace' ) ),
		);
		list( $state, $label ) = $map[ $status ] ?? array( 'not_connected', __( 'Set up payouts', 'lnkup-marketplace' ) );
		return array(
			'state'    => $state,
			'label'    => $label,
			'ready'    => false,
			'started'  => ! empty( $connect['account_id'] ) || ! empty( $connect['details_submitted'] ),
			'required' => true,
		);
	}

	// ------------------------------------------------------------------ Stages.

	/**
	 * Full activation payload (cached briefly).
	 *
	 * @return array<string, mixed>
	 */
	public static function for_user( int $organizer_id, bool $fresh = false ): array {
		$organizer_id = absint( $organizer_id );
		if ( ! $fresh ) {
			$cached = get_transient( self::cache_key( $organizer_id ) );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$data = self::build( self::facts( $organizer_id ) );
		set_transient( self::cache_key( $organizer_id ), $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * @param array<string, mixed> $f Facts.
	 * @return array<string, mixed>
	 */
	public static function build( array $f ): array {
		$view    = static fn( string $v, array $args = array() ): string => function_exists( 'lnkup_organizer_view_url' ) ? add_query_arg( $args, lnkup_organizer_view_url( $v ) ) : add_query_arg( array_merge( array( 'view' => $v ), $args ), home_url( '/organizer-dashboard/' ) );
		$p       = $f['profile'];
		$stages  = array();
		$item    = static fn( string $key, string $label, bool $done, bool $required = true ): array => array( 'key' => $key, 'label' => $label, 'done' => $done, 'required' => $required );

		// 1. Organization.
		$items    = array(
			$item( 'name', __( 'Organization name', 'lnkup-marketplace' ), $p['has_name'] ),
			$item( 'location', __( 'City and state', 'lnkup-marketplace' ), '' !== $p['city'] && '' !== $p['state'] ),
			$item( 'logo', __( 'Logo', 'lnkup-marketplace' ), $p['logo'], false ),
			$item( 'bio', __( 'Short description', 'lnkup-marketplace' ), $p['bio'], false ),
		);
		$stages[] = self::stage( 'organization', __( 'Set up your organization', 'lnkup-marketplace' ), __( 'Vendors see this on every event you publish.', 'lnkup-marketplace' ), $items, __( 'Complete profile', 'lnkup-marketplace' ), $view( 'start', array( 'step' => 'organization' ) ) );

		// 2. Payouts.
		$payout   = $f['payout'];
		$items    = array( $item( 'payout_account', $payout['label'], $payout['ready'] ) );
		$stage    = self::stage( 'payouts', __( 'Set up payouts', 'lnkup-marketplace' ), $payout['required'] ? __( 'Connect a payout account so vendor booth fees reach you.', 'lnkup-marketplace' ) : __( 'LNKup collects vendor payments and pays you out — nothing to set up.', 'lnkup-marketplace' ), $items, __( 'Set up payouts', 'lnkup-marketplace' ), $view( 'financial' ) );
		if ( ! $payout['ready'] && $payout['started'] ) {
			$stage['status'] = 'in_progress';
		}
		$stage['payout_state'] = $payout['state'];
		$stages[]              = $stage;

		// 3. First event.
		$opp      = $f['primary_opp'];
		$c        = $opp ? self::opportunity_completeness( (int) $opp->ID ) : array( 'categories' => false, 'price' => false, 'deadline' => false );
		$items    = array(
			$item( 'event', __( 'Event details', 'lnkup-marketplace' ), ! empty( $f['events'] ) ),
			$item( 'opportunity', __( 'A vendor booth or slot', 'lnkup-marketplace' ), null !== $opp ),
			$item( 'categories', __( 'Vendor categories', 'lnkup-marketplace' ), $c['categories'] ),
			$item( 'price', __( 'Booth price (free is fine)', 'lnkup-marketplace' ), $c['price'] ),
			$item( 'deadline', __( 'Application deadline', 'lnkup-marketplace' ), $c['deadline'] ),
		);
		$event_id = $f['primary_event'] ? (int) $f['primary_event']->ID : 0;
		$stages[] = self::stage( 'first_event', __( 'Create your first event', 'lnkup-marketplace' ), __( 'Add the event, its booth types and prices.', 'lnkup-marketplace' ), $items, empty( $f['events'] ) ? __( 'Create event', 'lnkup-marketplace' ) : __( 'Finish event setup', 'lnkup-marketplace' ), empty( $f['events'] ) ? $view( 'create' ) : $view( 'events', array( 'event' => $event_id ) ) );

		// 4. Publication.
		$live       = $f['live_event'];
		$share      = $live && class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::event_share_links( (int) $live->ID ) : array();
		$items      = array( $item( 'live', __( 'Event open for vendor applications', 'lnkup-marketplace' ), null !== $live ) );
		$stage      = self::stage( 'publication', __( 'Publish and share', 'lnkup-marketplace' ), __( 'Open applications and share your apply link with vendors.', 'lnkup-marketplace' ), $items, __( 'Open applications', 'lnkup-marketplace' ), $view( 'events', array( 'event' => $event_id ) ) );
		$stage['share'] = $live ? array(
			'event_id'        => (int) $live->ID,
			'event_title'     => get_the_title( $live ),
			'event_url'       => class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::event_public_url( (int) $live->ID ) : '',
			'application_url' => (string) ( $share[0]['url'] ?? '' ),
			'opportunity_id'  => (int) ( $share[0]['id'] ?? 0 ),
			'links'           => $share,
			'organizer_url'   => class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::organizer_public_url( (int) $f['organizer_id'] ) : '',
		) : null;
		$stages[] = $stage;

		// 5. First applications.
		$received = count( $f['apps'] );
		$reviewed = count( array_filter( $f['app_statuses'], static fn( $s ) => ! in_array( $s, array( '', 'submitted', 'draft', 'pending' ), true ) ) );
		$items    = array(
			$item( 'received', __( 'First vendor application', 'lnkup-marketplace' ), $received > 0 ),
			$item( 'reviewed', __( 'Review an application', 'lnkup-marketplace' ), $reviewed > 0 ),
		);
		$stage    = self::stage( 'first_applications', __( 'Review your first applications', 'lnkup-marketplace' ), $received ? __( 'Approve, waitlist or decline vendors.', 'lnkup-marketplace' ) : __( 'Share your apply link or invite vendors to get applications.', 'lnkup-marketplace' ), $items, $received ? __( 'Review applications', 'lnkup-marketplace' ) : __( 'Invite vendors', 'lnkup-marketplace' ), $received ? $view( 'applications' ) : $view( 'vendors' ) );
		$stage['counts'] = array( 'received' => $received, 'reviewed' => $reviewed );
		$stages[]        = $stage;

		// 6. First paid booking.
		$approved = count( array_filter( $f['app_statuses'], static fn( $s ) => in_array( $s, self::APPROVED_APP, true ) ) );
		$items    = array(
			$item( 'approved', __( 'Approve a vendor', 'lnkup-marketplace' ), $approved > 0 ),
			$item( 'paid', __( 'Vendor payment received', 'lnkup-marketplace' ), $f['paid_count'] > 0 ),
		);
		$stage    = self::stage( 'first_paid_booking', __( 'Complete your first paid booking', 'lnkup-marketplace' ), __( 'Approved vendors pay their booth fee through LNKup.', 'lnkup-marketplace' ), $items, __( 'View payments', 'lnkup-marketplace' ), $view( 'financial' ) );
		$stage['counts'] = array( 'approved' => $approved, 'paid' => (int) $f['paid_count'] );
		$stages[]        = $stage;

		$done     = count( array_filter( $stages, static fn( $s ) => 'complete' === $s['status'] ) );
		// First time each stage is seen complete (UTC), so follow-up nudges can be measured.
		if ( (int) $f['organizer_id'] > 0 ) {
			foreach ( $stages as $s ) {
				if ( 'complete' === $s['status'] ) {
					add_user_meta( (int) $f['organizer_id'], '_lnkup_activation_done_' . $s['key'], gmdate( 'Y-m-d H:i:s' ), true );
				}
			}
		}
		$by_key   = array();
		foreach ( $stages as $s ) {
			$by_key[ $s['key'] ] = $s;
		}
		$next     = null;
		foreach ( $stages as $s ) {
			if ( 'complete' !== $s['status'] ) {
				$next = array(
					'stage'     => $s['key'],
					'title'     => $s['title'],
					'label'     => $s['cta_label'],
					'url'       => $s['cta_url'],
					'missing'   => $s['missing'],
				);
				break;
			}
		}
		$to_event = array( 'organization', 'first_event', 'publication' );
		$to_paid  = self::STAGES;
		$pct      = static fn( array $keys ): int => (int) round( 100 * count( array_filter( $keys, static fn( $k ) => 'complete' === $by_key[ $k ]['status'] ) ) / count( $keys ) );

		return array(
			'organizer_id'                   => (int) $f['organizer_id'],
			'stages'                         => $stages,
			'completed'                      => $done,
			'total'                          => count( $stages ),
			'percent'                        => (int) round( 100 * $done / count( $stages ) ),
			'current_stage'                  => $next ? $next['stage'] : 'activated',
			'next_action'                    => $next,
			'progress_to_first_event'        => $pct( $to_event ),
			'progress_to_first_paid_booking' => $pct( $to_paid ),
			'share'                          => $by_key['publication']['share'],
			'is_activated'                   => null === $next,
			'generated_at'                   => current_time( 'mysql' ),
		);
	}

	/**
	 * @param array<int, array{key:string,label:string,done:bool,required:bool}> $items Items.
	 * @return array<string, mixed>
	 */
	private static function stage( string $key, string $title, string $description, array $items, string $cta_label, string $cta_url ): array {
		$required = array_filter( $items, static fn( $i ) => $i['required'] );
		$done_req = array_filter( $required, static fn( $i ) => $i['done'] );
		$any_done = array_filter( $items, static fn( $i ) => $i['done'] );
		if ( count( $done_req ) === count( $required ) ) {
			$status = 'complete';
		} elseif ( $any_done ) {
			$status = 'in_progress';
		} else {
			$status = 'not_started';
		}
		$missing = array_values( array_map( static fn( $i ) => $i['label'], array_filter( $required, static fn( $i ) => ! $i['done'] ) ) );
		return array(
			'key'         => $key,
			'title'       => $title,
			'description' => $description,
			'status'      => $status,
			'items'       => array_values( $items ),
			'missing'     => $missing,
			'cta_label'   => $cta_label,
			'cta_url'     => $cta_url,
		);
	}

	/**
	 * Next recommended action (used by Communications nudges).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function next_action( int $organizer_id ): ?array {
		$data = self::for_user( $organizer_id );
		return $data['next_action'];
	}

	/**
	 * Next setup step in the shape LNKup_Comms_Context::organizer_next_step() returns, so
	 * onboarding nudges follow the same model as the dashboard. Comms only nudges for event,
	 * publish and payout setup (in that order); profile and booking stages have their own flows.
	 *
	 * @return array{key:string,label:string,link:string,event_id:int}
	 */
	public static function comms_next_step( int $organizer_id ): array {
		$data   = self::for_user( $organizer_id );
		$stages = array();
		foreach ( $data['stages'] as $s ) {
			$stages[ $s['key'] ] = $s;
		}
		$event = $stages['first_event'];
		$facts_event = 0;
		if ( preg_match( '/[?&]event=(\d+)/', (string) $event['cta_url'], $m ) ) {
			$facts_event = (int) $m[1];
		}
		if ( 'complete' !== $event['status'] ) {
			$done = array();
			foreach ( $event['items'] as $i ) {
				$done[ $i['key'] ] = $i['done'];
			}
			if ( empty( $done['event'] ) ) {
				return array( 'key' => 'first_event', 'label' => __( 'Create your first event', 'lnkup-marketplace' ), 'link' => 'org_launch', 'event_id' => 0 );
			}
			if ( empty( $done['opportunity'] ) || empty( $done['price'] ) ) {
				return array( 'key' => 'opportunity', 'label' => __( 'Add a vendor opportunity so vendors can apply', 'lnkup-marketplace' ), 'link' => 'org_event', 'event_id' => $facts_event );
			}
			if ( empty( $done['categories'] ) ) {
				return array( 'key' => 'categories', 'label' => __( 'Select the vendor categories you are looking for', 'lnkup-marketplace' ), 'link' => 'org_event', 'event_id' => $facts_event );
			}
			return array( 'key' => 'deadline', 'label' => __( 'Set an application deadline', 'lnkup-marketplace' ), 'link' => 'org_event', 'event_id' => $facts_event );
		}
		if ( 'complete' !== $stages['publication']['status'] ) {
			return array( 'key' => 'publish', 'label' => __( 'Open a vendor slot to publish your event', 'lnkup-marketplace' ), 'link' => 'org_event', 'event_id' => $facts_event );
		}
		if ( 'complete' !== $stages['payouts']['status'] ) {
			return array( 'key' => 'payouts', 'label' => __( 'Finish payout setup so vendor payments reach you', 'lnkup-marketplace' ), 'link' => 'org_money', 'event_id' => 0 );
		}
		return array( 'key' => '', 'label' => '', 'link' => 'org_launch', 'event_id' => 0 );
	}

	// ------------------------------------------------------------------ REST.

	public static function register_routes(): void {
		register_rest_route(
			'lnkup/v1',
			'/organizer/activation',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_get' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'args'                => array(
					'organizer_id' => array(
						'type'     => 'integer',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * Organizers read their own activation; growth admins may read any organizer's.
	 *
	 * @return bool|WP_Error
	 */
	public static function rest_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Log in to see your setup progress.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}
		$target = (int) $request->get_param( 'organizer_id' );
		$self   = get_current_user_id();
		if ( $target && $target !== $self ) {
			$admin = ( class_exists( 'LNKup_Org_Growth' ) && LNKup_Org_Growth::current_user_can_manage() ) || LNKup_Capabilities::user_has_manage_all();
			return $admin ? true : new WP_Error( 'rest_forbidden', __( 'You can only see your own setup progress.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$user = wp_get_current_user();
		return in_array( 'lnkup_organizer', (array) $user->roles, true ) || LNKup_Capabilities::user_has_manage_all()
			? true
			: new WP_Error( 'rest_forbidden', __( 'Only organizers have event setup progress.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
	}

	public static function rest_get( WP_REST_Request $request ): WP_REST_Response {
		$target = (int) $request->get_param( 'organizer_id' );
		$data   = self::for_user( $target ? $target : get_current_user_id(), (bool) $request->get_param( 'fresh' ) );
		return new WP_REST_Response( array( 'success' => true, 'data' => $data ), 200 );
	}
}
