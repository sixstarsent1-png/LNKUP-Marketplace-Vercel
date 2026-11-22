<?php
/**
 * Reusable event setups ("templates") — an organizer's booth types, prices, requirements and
 * application questions, saved from one event and applied to the next.
 *
 * Stored per organizer in user meta (max 20). Creating an event with `setup_id` swaps the
 * submitted booth rows for the setup's rows inside create_opportunities_for_event(), so the
 * normal validation, fee engine and idempotency paths all still apply.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_Setups {

	const META = '_lnkup_organizer_event_setups';
	const MAX  = 20;

	const SLOT_FIELDS = array(
		'_lnkup_vendor_fee'          => 'vendor_fee',
		'_lnkup_spots_total'         => 'spots_total',
		'_lnkup_document_mode'       => 'document_mode',
		'_lnkup_required_documents'  => 'required_documents',
		'_lnkup_organizer_notes'     => 'organizer_notes',
		'_lnkup_deposit_required'    => 'deposit_required',
		'_lnkup_packet_lock'         => 'packet_lock',
	);

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( int $user_id ): array {
		$list = get_user_meta( $user_id, self::META, true );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $user_id, string $setup_id ): ?array {
		foreach ( self::all( $user_id ) as $setup ) {
			if ( (string) ( $setup['id'] ?? '' ) === $setup_id ) {
				return $setup;
			}
		}
		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $list Setups.
	 */
	private static function store( int $user_id, array $list ): void {
		// Slash so nested strings (question help text etc.) keep backslashes through update_user_meta().
		update_user_meta( $user_id, self::META, wp_slash( array_values( $list ) ) );
	}

	/**
	 * Save an organizer-owned event's booth configuration as a reusable setup.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save_from_event( int $user_id, int $event_id, string $name ) {
		$event = get_post( $event_id );
		if ( ! $event || 'lnkup_event' !== $event->post_type ) {
			return new WP_Error( 'lnkup_event_not_found', __( 'Event not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! LNKup_Capabilities::user_owns_post( $event_id, $user_id, '_lnkup_organizer_user_id' ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You can only save setups from your own events.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$list = self::all( $user_id );
		if ( count( $list ) >= self::MAX ) {
			/* translators: %d: max setups */
			return new WP_Error( 'lnkup_setup_limit', sprintf( __( 'You can keep up to %d saved setups. Delete one first.', 'lnkup-marketplace' ), self::MAX ), array( 'status' => 409 ) );
		}
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		if ( ! $opps ) {
			return new WP_Error( 'lnkup_setup_no_slots', __( 'This event has no booth types to save yet.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$slots = array();
		foreach ( $opps as $opp ) {
			$row = array( 'opportunity_title' => html_entity_decode( get_the_title( $opp ), ENT_QUOTES, 'UTF-8' ) );
			foreach ( self::SLOT_FIELDS as $meta => $key ) {
				$value = get_post_meta( $opp->ID, $meta, true );
				if ( '' !== $value && null !== $value ) {
					$row[ $key ] = $value;
				}
			}
			$terms             = wp_get_object_terms( $opp->ID, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
			$row['categories'] = is_wp_error( $terms ) ? array() : array_values( array_map( 'strval', $terms ) );
			if ( class_exists( 'LNKup_Application_Questions' ) ) {
				$row['setup_questions'] = LNKup_Application_Questions::get_for_opportunity( (int) $opp->ID );
				$row['setup_docs_gate'] = (bool) get_post_meta( $opp->ID, '_lnkup_documents_required_to_apply', true );
			}
			$slots[] = $row;
		}
		$name  = trim( sanitize_text_field( $name ) );
		$setup = array(
			'id'              => 'setup_' . wp_generate_password( 10, false, false ),
			'name'            => '' !== $name ? substr( $name, 0, 80 ) : html_entity_decode( get_the_title( $event ), ENT_QUOTES, 'UTF-8' ),
			'source_event_id' => $event_id,
			'slots'           => $slots,
			'created_at'      => current_time( 'mysql' ),
		);
		$list[] = $setup;
		self::store( $user_id, $list );
		return self::format( $setup );
	}

	public static function delete( int $user_id, string $setup_id ): bool {
		$list = self::all( $user_id );
		$next = array_values( array_filter( $list, static fn( $s ) => (string) ( $s['id'] ?? '' ) !== $setup_id ) );
		if ( count( $next ) === count( $list ) ) {
			return false;
		}
		self::store( $user_id, $next );
		return true;
	}

	/**
	 * @param array<string, mixed> $setup Setup.
	 * @return array<string, mixed>
	 */
	public static function format( array $setup ): array {
		return array(
			'id'              => (string) $setup['id'],
			'name'            => (string) $setup['name'],
			'source_event_id' => (int) $setup['source_event_id'],
			'slot_count'      => count( (array) $setup['slots'] ),
			'slots'           => array_map(
				static fn( $s ) => array(
					'title'          => (string) ( $s['opportunity_title'] ?? '' ),
					'categories'     => (array) ( $s['categories'] ?? array() ),
					'vendor_fee'     => isset( $s['vendor_fee'] ) ? (float) $s['vendor_fee'] : null,
					'spots_total'    => (int) ( $s['spots_total'] ?? 0 ),
					'question_count' => count( (array) ( $s['setup_questions'] ?? array() ) ),
				),
				(array) $setup['slots']
			),
			'created_at'      => (string) $setup['created_at'],
			'create_url'      => function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'create', array( 'setup' => (string) $setup['id'] ) ) : '',
		);
	}

	// ------------------------------------------------------------------ Create integration.

	/**
	 * Replace submitted booth rows with the setup's rows when `setup_id` is present.
	 *
	 * @param array<string, mixed> $input Create input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function apply_to_input( array $input, int $organizer_id ) {
		$setup_id = sanitize_text_field( (string) ( $input['setup_id'] ?? '' ) );
		if ( '' === $setup_id ) {
			return $input;
		}
		$setup = self::get( $organizer_id, $setup_id );
		if ( ! $setup ) {
			return new WP_Error( 'lnkup_setup_not_found', __( 'That saved setup no longer exists. Choose booth types manually.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$rows = array();
		foreach ( (array) $setup['slots'] as $slot ) {
			$rows[] = $slot;
		}
		unset( $input['fee_category'], $input['fee_custom'], $input['fee_amount'], $input['fee_spots'] );
		$input['opportunities'] = $rows;
		return $input;
	}

	/**
	 * Copy saved application questions onto the newly created slots (same order as rows).
	 *
	 * @param array<int, array<string, mixed>> $rows    Normalized rows.
	 * @param array<int, array<string, mixed>> $created Created slot summaries.
	 */
	public static function after_create( array $rows, array $created ): void {
		if ( ! class_exists( 'LNKup_Application_Questions' ) ) {
			return;
		}
		foreach ( $created as $i => $slot ) {
			$row    = $rows[ $i ] ?? array();
			$opp_id = (int) ( $slot['opportunity_id'] ?? 0 );
			if ( ! $opp_id || empty( $row['setup_questions'] ) ) {
				continue;
			}
			LNKup_Application_Questions::save_for_opportunity( $opp_id, (array) $row['setup_questions'], (int) get_post_meta( $opp_id, '_lnkup_organizer_user_id', true ), ! empty( $row['setup_docs_gate'] ) );
		}
	}

	// ------------------------------------------------------------------ REST.

	public static function register_routes(): void {
		register_rest_route(
			'lnkup/v1',
			'/organizer/setups',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_list' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_create' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
			)
		);
		register_rest_route(
			'lnkup/v1',
			'/organizer/setups/(?P<id>setup_[A-Za-z0-9]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'rest_delete' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
			)
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public static function permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Log in to manage saved setups.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}
		$user = wp_get_current_user();
		return in_array( 'lnkup_organizer', (array) $user->roles, true ) || LNKup_Capabilities::user_has_manage_all()
			? true
			: new WP_Error( 'rest_forbidden', __( 'Only organizers have saved event setups.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
	}

	public static function rest_list(): WP_REST_Response {
		$user_id = get_current_user_id();
		$events  = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 30,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $user_id,
			)
		);
		return new WP_REST_Response(
			array(
				'items'  => array_map( array( __CLASS__, 'format' ), self::all( $user_id ) ),
				'events' => array_map( static fn( $e ) => array( 'id' => (int) $e->ID, 'title' => html_entity_decode( get_the_title( $e ), ENT_QUOTES, 'UTF-8' ) ), $events ),
				'max'    => self::MAX,
			),
			200
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_create( WP_REST_Request $request ) {
		$result = self::save_from_event( get_current_user_id(), (int) $request->get_param( 'event_id' ), (string) $request->get_param( 'name' ) );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'setup' => $result ), 201 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_get( WP_REST_Request $request ) {
		$setup = self::get( get_current_user_id(), (string) $request['id'] );
		return $setup ? new WP_REST_Response( array( 'setup' => self::format( $setup ) ), 200 ) : new WP_Error( 'lnkup_setup_not_found', __( 'Saved setup not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_delete( WP_REST_Request $request ) {
		return self::delete( get_current_user_id(), (string) $request['id'] )
			? new WP_REST_Response( array( 'deleted' => true ), 200 )
			: new WP_Error( 'lnkup_setup_not_found', __( 'Saved setup not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
	}
}
