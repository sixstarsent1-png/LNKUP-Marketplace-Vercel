<?php
/**
 * Organizer onboarding flow (v2): a resumable, server-backed guided setup.
 *
 * Step status is derived from LNKup_Organizer_Activation wherever a real record exists (profile
 * meta, events, payout status), so the dashboard, apps and nudges always agree. Only the
 * organizer's own choices are stored in `_lnkup_onboarding_state`: what they organize
 * (formats), the step they were on, skipped steps and whether they dismissed the guide. That is
 * what lets an organizer stop on one device and continue on another.
 *
 * Event creation stays in LNKup_Organizer_Onboarding; tours and series stay in the Portfolio OS.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_Onboarding_Flow {

	const META      = '_lnkup_onboarding_state';
	const VERSION   = 1;
	const STEPS     = array( 'account', 'organization', 'format', 'first_event', 'payouts', 'share' );
	const SKIPPABLE = array( 'format', 'payouts' );
	const FORMATS   = array( 'single', 'recurring', 'series', 'tour', 'company' );
	const NS        = 'lnkup/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function enabled(): bool {
		return class_exists( 'LNKup_Feature_Flags' ) && LNKup_Feature_Flags::onboarding_v2();
	}

	/**
	 * Organizer-facing labels for each format.
	 *
	 * @return array<string, array{label:string,description:string}>
	 */
	public static function format_labels(): array {
		return array(
			'single'    => array(
				'label'       => __( 'Single event', 'lnkup-marketplace' ),
				'description' => __( 'One festival, market, expo or other event.', 'lnkup-marketplace' ),
			),
			'recurring' => array(
				'label'       => __( 'Recurring event', 'lnkup-marketplace' ),
				'description' => __( 'Happens weekly, monthly, seasonally or on another schedule.', 'lnkup-marketplace' ),
			),
			'series'    => array(
				'label'       => __( 'Event series', 'lnkup-marketplace' ),
				'description' => __( 'Several related events under one brand, with different dates or venues.', 'lnkup-marketplace' ),
			),
			'tour'      => array(
				'label'       => __( 'Multi-city tour', 'lnkup-marketplace' ),
				'description' => __( 'One branded event with stops in several cities.', 'lnkup-marketplace' ),
			),
			'company'   => array(
				'label'       => __( 'Event production company', 'lnkup-marketplace' ),
				'description' => __( 'You run several brands, events, tours and series.', 'lnkup-marketplace' ),
			),
		);
	}

	// ------------------------------------------------------------------ Signup.

	/**
	 * Event category choices for signup: top-level event types.
	 *
	 * @return array<string, string> slug => name.
	 */
	public static function category_options(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'lnkup_event_type',
				'parent'     => 0,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$out = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$out[ $term->slug ] = $term->name;
		}
		return $out;
	}

	/**
	 * Clean organizer signup fields.
	 *
	 * @param array $input organization_name, city, state, category.
	 * @return array{organization_name:string,city:string,state:string,category:string}
	 */
	public static function sanitize_signup( array $input ): array {
		$category = sanitize_title( (string) ( $input['category'] ?? '' ) );
		return array(
			'organization_name' => mb_substr( trim( sanitize_text_field( (string) ( $input['organization_name'] ?? '' ) ) ), 0, 120 ),
			'city'              => mb_substr( trim( sanitize_text_field( (string) ( $input['city'] ?? '' ) ) ), 0, 120 ),
			'state'             => mb_substr( trim( sanitize_text_field( (string) ( $input['state'] ?? '' ) ) ), 0, 32 ),
			'category'          => isset( self::category_options()[ $category ] ) ? $category : '',
		);
	}

	/**
	 * Validate organizer signup fields before the account is created.
	 *
	 * @param array $fields   sanitize_signup() output.
	 * @param bool  $required Web form requires name and location; the app API keeps them optional.
	 * @return true|WP_Error
	 */
	public static function validate_signup( array $fields, bool $required ) {
		if ( ! $required ) {
			return true;
		}
		if ( '' === $fields['organization_name'] ) {
			return new WP_Error( 'organization_required', __( 'Add your organization or business name.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( '' === $fields['city'] || '' === $fields['state'] ) {
			return new WP_Error( 'location_required', __( 'Add the city and state where you mainly hold events.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Store signup fields as the organizer's public profile basics, so the Organization step
	 * starts partly done. Existing values are never overwritten.
	 *
	 * @param int   $user_id Organizer.
	 * @param array $fields  sanitize_signup() output.
	 */
	public static function save_signup( int $user_id, array $fields ): void {
		$map = array(
			'organization_name' => '_lnkup_organizer_public_name',
			'city'              => '_lnkup_organizer_city',
			'state'             => '_lnkup_organizer_state',
			'category'          => '_lnkup_organizer_category',
		);
		foreach ( $map as $field => $meta ) {
			if ( '' !== $fields[ $field ] && '' === (string) get_user_meta( $user_id, $meta, true ) ) {
				update_user_meta( $user_id, $meta, $fields[ $field ] );
			}
		}
		$state = self::get_state( $user_id );
		if ( '' === $state['started_at'] ) {
			$state['started_at']   = current_time( 'mysql' );
			$state['current_step'] = 'organization';
			self::save_state( $user_id, $state );
		}
	}

	// ------------------------------------------------------------------ State.

	/**
	 * Stored choices (defaults filled in).
	 *
	 * @return array{version:int,formats:string[],current_step:string,skipped:string[],dismissed:bool,started_at:string,completed_at:string}
	 */
	public static function get_state( int $user_id ): array {
		$raw = json_decode( (string) get_user_meta( $user_id, self::META, true ), true );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'version'      => self::VERSION,
			// Keep the organizer's order: the first format picked drives first-event routing.
			'formats'      => array_values( array_unique( array_filter( array_map( 'strval', (array) ( $raw['formats'] ?? array() ) ), static fn( $f ) => in_array( $f, self::FORMATS, true ) ) ) ),
			'current_step' => in_array( (string) ( $raw['current_step'] ?? '' ), self::STEPS, true ) ? (string) $raw['current_step'] : '',
			'skipped'      => array_values( array_intersect( self::SKIPPABLE, array_map( 'strval', (array) ( $raw['skipped'] ?? array() ) ) ) ),
			'dismissed'    => ! empty( $raw['dismissed'] ),
			'started_at'   => (string) ( $raw['started_at'] ?? '' ),
			'completed_at' => (string) ( $raw['completed_at'] ?? '' ),
		);
	}

	/**
	 * @param int   $user_id Organizer.
	 * @param array $state   Clean state.
	 */
	private static function save_state( int $user_id, array $state ): void {
		update_user_meta( $user_id, self::META, wp_slash( (string) wp_json_encode( $state ) ) );
	}

	/**
	 * Apply organizer choices. Unknown keys are ignored; invalid values are rejected.
	 *
	 * @param int   $user_id Organizer.
	 * @param array $changes formats[], current_step, skip, unskip, dismiss.
	 * @return array|WP_Error Full flow payload.
	 */
	public static function update( int $user_id, array $changes ) {
		$state  = self::get_state( $user_id );
		$before = $state;
		if ( array_key_exists( 'formats', $changes ) ) {
			$formats = array_values( array_unique( array_map( 'sanitize_key', (array) $changes['formats'] ) ) );
			$invalid = array_diff( $formats, self::FORMATS );
			if ( $invalid ) {
				return new WP_Error( 'lnkup_invalid_format', __( 'Choose from the listed event formats.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			$state['formats'] = $formats;
			$state['skipped'] = array_values( array_diff( $state['skipped'], $formats ? array( 'format' ) : array() ) );
		}
		if ( array_key_exists( 'current_step', $changes ) ) {
			$step = sanitize_key( (string) $changes['current_step'] );
			if ( ! in_array( $step, self::STEPS, true ) ) {
				return new WP_Error( 'lnkup_invalid_step', __( 'Unknown setup step.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			$state['current_step'] = $step;
		}
		foreach ( array( 'skip' => true, 'unskip' => false ) as $key => $add ) {
			if ( ! array_key_exists( $key, $changes ) ) {
				continue;
			}
			$step = sanitize_key( (string) $changes[ $key ] );
			if ( ! in_array( $step, self::SKIPPABLE, true ) ) {
				return new WP_Error( 'lnkup_step_not_skippable', __( 'This step cannot be skipped.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			$state['skipped'] = $add ? array_values( array_unique( array_merge( $state['skipped'], array( $step ) ) ) ) : array_values( array_diff( $state['skipped'], array( $step ) ) );
		}
		if ( array_key_exists( 'dismiss', $changes ) ) {
			$state['dismissed'] = rest_sanitize_boolean( $changes['dismiss'] );
		}
		if ( '' === $state['started_at'] ) {
			$state['started_at'] = current_time( 'mysql' );
		}
		self::save_state( $user_id, $state );
		do_action( 'lnkup_organizer_onboarding_updated', $user_id, $state, $before );
		return self::for_user( $user_id );
	}

	// ------------------------------------------------------------------ Steps.

	/**
	 * Full onboarding payload for an organizer.
	 *
	 * @return array<string, mixed>
	 */
	public static function for_user( int $user_id ): array {
		$state      = self::get_state( $user_id );
		$activation = LNKup_Organizer_Activation::for_user( $user_id, true );
		$stages     = array();
		foreach ( $activation['stages'] as $stage ) {
			$stages[ $stage['key'] ] = $stage;
		}
		$routing = self::format_routing( $user_id );
		$view    = static fn( string $v, array $args = array() ): string => add_query_arg( $args, lnkup_organizer_view_url( $v ) );

		$steps   = array();
		$steps[] = self::step( 'account', __( 'Create your account', 'lnkup-marketplace' ), 'complete', array(), '' );
		$steps[] = self::step( 'organization', $stages['organization']['title'], $stages['organization']['status'], $stages['organization']['missing'], $view( 'start', array( 'step' => 'organization' ) ) );
		$steps[] = self::step(
			'format',
			__( 'Tell us what you organize', 'lnkup-marketplace' ),
			$state['formats'] ? 'complete' : 'not_started',
			$state['formats'] ? array() : array( __( 'Event format', 'lnkup-marketplace' ) ),
			$view( 'start', array( 'step' => 'format' ) )
		);
		$first = $stages['first_event'];
		$steps[] = self::step( 'first_event', self::first_event_title( $state['formats'] ), $first['status'], $first['missing'], empty( $first['items'][0]['done'] ) ? self::first_event_url( $state['formats'], $routing ) : $first['cta_url'] );
		$payout = $stages['payouts'];
		$steps[] = array_merge(
			self::step( 'payouts', $payout['title'], $payout['status'], $payout['missing'], $view( 'start', array( 'step' => 'payouts' ) ) ),
			array( 'payout_state' => (string) ( $payout['payout_state'] ?? '' ) )
		);
		$pub     = $stages['publication'];
		$steps[] = self::step( 'share', __( 'Publish and share', 'lnkup-marketplace' ), $pub['status'], $pub['missing'], $view( 'start', array( 'step' => 'share' ) ) );

		foreach ( $steps as &$s ) {
			$s['skipped']   = 'complete' !== $s['status'] && in_array( $s['key'], $state['skipped'], true );
			$s['skippable'] = in_array( $s['key'], self::SKIPPABLE, true );
		}
		unset( $s );

		$next = null;
		foreach ( $steps as $s ) {
			if ( 'complete' !== $s['status'] && ! $s['skipped'] ) {
				$next = $s;
				break;
			}
		}
		$done      = count( array_filter( $steps, static fn( $s ) => 'complete' === $s['status'] ) );
		$completed = null === $next;
		if ( $completed && '' === $state['completed_at'] && $user_id > 0 ) {
			$state['completed_at'] = current_time( 'mysql' );
			if ( '' === $state['started_at'] ) {
				$state['started_at'] = $state['completed_at'];
			}
			self::save_state( $user_id, $state );
			do_action( 'lnkup_organizer_onboarding_completed', $user_id, $state );
		}

		$current = null;
		foreach ( $steps as $s ) {
			if ( $s['key'] === $state['current_step'] && 'complete' !== $s['status'] ) {
				$current = $s;
			}
		}
		$resume = $current ? $current : $next;
		return array(
			'enabled'          => self::enabled(),
			'state'            => $state,
			'steps'            => $steps,
			'completed'        => $done,
			'total'            => count( $steps ),
			'percent'          => (int) round( 100 * $done / count( $steps ) ),
			'is_complete'      => $completed,
			'next_step'        => $next ? array( 'key' => $next['key'], 'title' => $next['title'], 'url' => $next['cta_url'], 'missing' => $next['missing'] ) : null,
			'resume_url'       => $resume ? (string) $resume['cta_url'] : lnkup_organizer_view_url( 'home' ),
			'drafts'           => self::resume_targets( $user_id ),
			'formats_routing'  => $routing,
			'payout'           => LNKup_Organizer_Activation::payout( $user_id ),
			'activation'       => array(
				'percent'       => (int) $activation['percent'],
				'current_stage' => (string) $activation['current_stage'],
				'setup_complete' => (bool) $activation['is_activated'],
				'share'         => $activation['share'],
			),
		);
	}

	/**
	 * @param string   $key     Step key.
	 * @param string   $title   Title.
	 * @param string   $status  complete|in_progress|not_started.
	 * @param string[] $missing Missing labels.
	 * @param string   $url     Where to continue.
	 * @return array<string, mixed>
	 */
	private static function step( string $key, string $title, string $status, array $missing, string $url ): array {
		return array(
			'key'     => $key,
			'title'   => $title,
			'status'  => $status,
			'missing' => array_values( $missing ),
			'cta_url' => $url,
		);
	}

	/**
	 * Where each format starts. Tours, series and recurring markets open the Tours & Series
	 * wizard only when the Portfolio OS is on for this organizer; otherwise Create Event.
	 *
	 * @return array<string, array{url:string,target:string,note:string}>
	 */
	public static function format_routing( int $user_id ): array {
		$portfolio = class_exists( 'LNKup_Feature_Flags' ) && LNKup_Feature_Flags::portfolio( $user_id );
		$create    = array(
			'url'    => lnkup_organizer_view_url( 'create' ),
			'target' => 'create',
			'note'   => '',
		);
		$fallback  = array_merge( $create, array( 'note' => __( 'Start with your first date. Tours & Series tools are coming to your account soon.', 'lnkup-marketplace' ) ) );
		$out       = array( 'single' => $create );
		foreach ( array( 'recurring', 'series', 'tour' ) as $type ) {
			$out[ $type ] = $portfolio ? array(
				'url'    => add_query_arg( 'new', $type, lnkup_organizer_view_url( 'tours' ) ),
				'target' => 'tours',
				'note'   => '',
			) : $fallback;
		}
		$out['company'] = $portfolio ? array(
			'url'    => lnkup_organizer_view_url( 'tours' ),
			'target' => 'tours',
			'note'   => __( 'Add your brands, then set up each tour, series or event.', 'lnkup-marketplace' ),
		) : $fallback;
		return $out;
	}

	/**
	 * Primary format: the first one the organizer picked.
	 *
	 * @param string[] $formats Formats.
	 */
	private static function primary_format( array $formats ): string {
		return $formats ? (string) $formats[0] : 'single';
	}

	/**
	 * @param string[] $formats Formats.
	 */
	private static function first_event_title( array $formats ): string {
		$titles = array(
			'tour'      => __( 'Set up your tour', 'lnkup-marketplace' ),
			'recurring' => __( 'Set up your recurring event', 'lnkup-marketplace' ),
			'series'    => __( 'Set up your event series', 'lnkup-marketplace' ),
			'company'   => __( 'Set up your first event or tour', 'lnkup-marketplace' ),
		);
		return $titles[ self::primary_format( $formats ) ] ?? __( 'Create your first event', 'lnkup-marketplace' );
	}

	/**
	 * @param string[] $formats Formats.
	 * @param array    $routing format_routing().
	 */
	private static function first_event_url( array $formats, array $routing ): string {
		return (string) ( $routing[ self::primary_format( $formats ) ]['url'] ?? lnkup_organizer_view_url( 'create' ) );
	}

	/**
	 * Unfinished work the organizer can pick up on any device: draft events (not tour stops)
	 * and draft tours / series.
	 *
	 * @return array<int, array{type:string,id:int,title:string,updated_at:string,url:string}>
	 */
	public static function resume_targets( int $user_id ): array {
		$out    = array();
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 20,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_organizer_user_id', 'value' => (string) $user_id ),
					array( 'key' => '_lnkup_event_status', 'value' => 'draft' ),
				),
			)
		);
		foreach ( $events as $event ) {
			if ( (int) get_post_meta( $event->ID, '_lnkup_tour_id', true ) ) {
				continue;
			}
			$out[] = array(
				'type'       => 'event',
				'id'         => (int) $event->ID,
				'title'      => $event->post_title,
				'updated_at' => (string) $event->post_modified,
				'url'        => add_query_arg( array( 'event' => (int) $event->ID, 'edit' => 1 ), lnkup_page_url_helper( 'organizer-dashboard' ) ),
			);
			if ( count( $out ) >= 5 ) {
				break;
			}
		}
		if ( class_exists( 'LNKup_Feature_Flags' ) && LNKup_Feature_Flags::portfolio( $user_id ) ) {
			$tours = get_posts(
				array(
					'post_type'      => 'lnkup_tour',
					'post_status'    => 'draft',
					'posts_per_page' => 5,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'no_found_rows'  => true,
					'meta_key'       => '_lnkup_organizer_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => (string) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			foreach ( $tours as $tour ) {
				if ( 'archived' === get_post_meta( $tour->ID, '_lnkup_tour_status', true ) ) {
					continue;
				}
				$out[] = array(
					'type'       => (string) ( get_post_meta( $tour->ID, '_lnkup_tour_type', true ) ?: 'tour' ),
					'id'         => (int) $tour->ID,
					'title'      => $tour->post_title,
					'updated_at' => (string) $tour->post_modified,
					'url'        => add_query_arg( 'tour', (int) $tour->ID, lnkup_organizer_view_url( 'tours' ) ),
				);
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $b['updated_at'], $a['updated_at'] );
			}
		);
		return array_slice( $out, 0, 8 );
	}

	/**
	 * Whether login should land this organizer on Get started.
	 */
	public static function should_resume( int $user_id ): bool {
		if ( ! self::enabled() || ! $user_id ) {
			return false;
		}
		$state = self::get_state( $user_id );
		if ( $state['dismissed'] || '' !== $state['completed_at'] ) {
			return false;
		}
		return ! self::for_user( $user_id )['is_complete'];
	}

	// ------------------------------------------------------------------ REST.

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/organizer/onboarding',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => array( __CLASS__, 'rest_read_permission' ),
					'args'                => array( 'organizer_id' => array( 'type' => 'integer', 'required' => false ) ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'rest_patch' ),
					'permission_callback' => array( __CLASS__, 'rest_write_permission' ),
				),
			)
		);
	}

	/**
	 * Organizers read their own flow; growth admins may read any organizer's.
	 *
	 * @return true|WP_Error
	 */
	public static function rest_read_permission( WP_REST_Request $request ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'lnkup_feature_disabled', __( 'Guided setup is not available yet.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return LNKup_Organizer_Activation::rest_permission( $request );
	}

	/**
	 * Only the organizer themself can change their choices.
	 *
	 * @return true|WP_Error
	 */
	public static function rest_write_permission( WP_REST_Request $request ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'lnkup_feature_disabled', __( 'Guided setup is not available yet.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Log in to continue.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}
		$target = (int) $request->get_param( 'organizer_id' );
		if ( $target && $target !== get_current_user_id() ) {
			return new WP_Error( 'rest_forbidden', __( 'You can only change your own setup.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return LNKup_Roles::is_organizer( get_current_user_id() )
			? true
			: new WP_Error( 'rest_forbidden', __( 'Only organizers have guided setup.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
	}

	public static function rest_get( WP_REST_Request $request ): WP_REST_Response {
		$target = (int) $request->get_param( 'organizer_id' );
		return new WP_REST_Response( array( 'success' => true, 'data' => self::for_user( $target ? $target : get_current_user_id() ) ), 200 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_patch( WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$body    = is_array( $body ) ? $body : $request->get_body_params();
		$changes = array_intersect_key( (array) $body, array_flip( array( 'formats', 'current_step', 'skip', 'unskip', 'dismiss' ) ) );
		if ( ! $changes ) {
			return new WP_Error( 'lnkup_nothing_to_change', __( 'Nothing to update.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$result = self::update( get_current_user_id(), $changes );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'success' => true, 'data' => $result ), 200 );
	}
}
