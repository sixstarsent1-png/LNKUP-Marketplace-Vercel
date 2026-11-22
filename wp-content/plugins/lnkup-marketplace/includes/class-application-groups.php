<?php
/**
 * Multi-stop tour and series applications.
 *
 * One vendor submission fans out into independent per-stop applications through
 * LNKup_Applications::submit_with_profile(), each tagged with a shared
 * `_lnkup_submission_group_id`. Approval, payment, inventory, pass and check-in stay per stop.
 * Each stop uses the idempotency key "{group_id}-{opportunity_id}", so retrying a partially
 * failed submission only creates the stops that are still missing.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Application_Groups {

	const NS        = 'lnkup/v1';
	const MAX_STOPS = 60;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/tours/(?P<id>\d+)/apply-context',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_context' ),
				'permission_callback' => array( 'LNKup_Applications_REST', 'vendor_permission' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/tours/(?P<id>\d+)/apply-draft',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'rest_save_draft' ),
				'permission_callback' => array( 'LNKup_Applications_REST', 'vendor_permission' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/tours/(?P<id>\d+)/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_apply' ),
				'permission_callback' => array( 'LNKup_Applications_REST', 'vendor_permission' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);
	}

	/**
	 * Feature gate: tours must be on, plus the multi-apply flag.
	 */
	public static function enabled() {
		$settings = lnkup_get_settings();
		return ! empty( $settings['tour_multi_apply_enabled'] )
			&& class_exists( 'LNKup_Match_Settings' )
			&& LNKup_Match_Settings::enabled( 'matching_tours_enabled' );
	}

	/**
	 * @param int $tour_id Tour ID.
	 * @return true|WP_Error
	 */
	private static function guard( $tour_id ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'lnkup_feature_disabled', __( 'Multi-stop applications are not available yet.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( 'lnkup_tour' !== get_post_type( $tour_id ) || 'publish' !== get_post_status( $tour_id ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Tour not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return true;
	}

	/**
	 * GET stops, open vendor slots, per-slot price estimate and questions, profile snapshot, saved draft.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_context( WP_REST_Request $request ) {
		$tour_id = (int) $request['id'];
		$ok      = self::guard( $tour_id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$user_id = get_current_user_id();
		$detail  = LNKup_Tours::detail( $tour_id, $user_id );
		if ( is_wp_error( $detail ) ) {
			return $detail;
		}
		foreach ( $detail['stops'] as &$stop ) {
			foreach ( $stop['opportunities'] as &$opp ) {
				$opp['quote']     = LNKup_Applications_REST::quote_for_opportunity( (int) $opp['opportunity_id'] );
				$opp['questions'] = LNKup_Application_Questions::get_for_opportunity( (int) $opp['opportunity_id'] );
				$apply_ctx           = LNKup_Applications::build_apply_context( (int) $opp['opportunity_id'], $user_id );
				$eligibility         = is_wp_error( $apply_ctx ) ? array( 'can_apply' => false, 'block_reason' => 'invite_only' ) : $apply_ctx['eligibility'];
				$opp['can_apply']    = ! empty( $eligibility['can_apply'] );
				$opp['block_reason'] = (string) $eligibility['block_reason'];
				$opp['categories']   = is_wp_error( $apply_ctx ) ? array() : array_values( (array) $apply_ctx['categories'] );
				$opp['default_category'] = is_wp_error( $apply_ctx ) ? '' : (string) $apply_ctx['default_category'];
				$opp['required_documents'] = is_wp_error( $apply_ctx ) ? array() : (array) $apply_ctx['documents'];
				$opp['previous_application'] = is_wp_error( $apply_ctx ) ? null : $apply_ctx['previous_application'];
			}
			unset( $opp );
		}
		unset( $stop );

		$profile_id = (int) lnkup_get_vendor_profile_id( $user_id );
		$profile    = $profile_id ? LNKup_Vendor_CRM_Profile::get( $profile_id, 0 ) : null;
		$draft_id   = self::open_group_draft_id( $user_id, $tour_id );
		$doc_types  = class_exists( 'LNKup_Documents' ) ? LNKup_Documents::document_types() : array();
		$documents  = array();
		foreach ( class_exists( 'LNKup_Documents' ) ? LNKup_Documents::get_for_vendor( $user_id ) : array() as $doc ) {
			$type        = (string) get_post_meta( $doc->ID, '_lnkup_document_type', true );
			$documents[] = array(
				'id'    => (int) $doc->ID,
				'label' => (string) ( $doc_types[ $type ] ?? $doc->post_title ),
			);
		}

		return rest_ensure_response(
			array(
				'tour'      => $detail,
				'profile'   => $profile ? LNKup_Applications::profile_snapshot_from_dto( $profile ) : null,
				'documents' => $documents,
				'draft'     => $draft_id ? self::format_group_draft( $draft_id ) : null,
			)
		);
	}

	/**
	 * PUT autosave of the multi-stop form. Body: group_id, revision, selections, shared, per_stop.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_save_draft( WP_REST_Request $request ) {
		$tour_id = (int) $request['id'];
		$ok      = self::guard( $tour_id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$body    = $request->get_json_params();
		$body    = is_array( $body ) ? $body : array();
		$user_id = get_current_user_id();
		$group   = self::sanitize_group_id( (string) ( $body['group_id'] ?? '' ) );
		if ( '' === $group ) {
			return new WP_Error( 'lnkup_group_id_required', __( 'Missing request key. Refresh and try again.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$payload = self::sanitize_payload( $tour_id, $user_id, $body );
		if ( strlen( (string) wp_json_encode( $payload ) ) > LNKup_Application_Drafts::MAX_BYTES * 3 ) {
			return new WP_Error( 'lnkup_draft_too_large', __( 'Your answers are too long to save. Shorten them and try again.', 'lnkup-marketplace' ), array( 'status' => 413 ) );
		}
		$draft_id = self::open_group_draft_id( $user_id, $tour_id );
		$revision = array_key_exists( 'revision', $body ) && null !== $body['revision'] ? (int) $body['revision'] : null;
		if ( $draft_id && null !== $revision && $revision !== (int) get_post_meta( $draft_id, '_lnkup_draft_revision', true ) ) {
			return new WP_Error( 'lnkup_draft_conflict', __( 'This application was updated on another device. We loaded the latest version.', 'lnkup-marketplace' ), array( 'status' => 409, 'draft' => self::format_group_draft( $draft_id ) ) );
		}
		if ( ! $draft_id ) {
			$draft_id = wp_insert_post(
				array(
					'post_type'   => 'lnkup_application',
					'post_status' => LNKup_Application_Drafts::POST_STATUS,
					'post_author' => $user_id,
					'post_title'  => sprintf( 'Tour draft: %s', get_the_title( $tour_id ) ),
				),
				true
			);
			if ( is_wp_error( $draft_id ) ) {
				return new WP_Error( 'lnkup_draft_save_failed', __( 'We could not save your progress. Try again.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
			}
			update_post_meta( $draft_id, '_lnkup_draft_vendor_user_id', $user_id );
			update_post_meta( $draft_id, '_lnkup_draft_opportunity_id', 0 );
			update_post_meta( $draft_id, '_lnkup_draft_tour_id', $tour_id );
			update_post_meta( $draft_id, '_lnkup_draft_state', LNKup_Application_Drafts::STATE_OPEN );
			update_post_meta( $draft_id, '_lnkup_draft_started_at', current_time( 'mysql' ) );
			update_post_meta( $draft_id, '_lnkup_draft_revision', 0 );
		}
		update_post_meta( $draft_id, '_lnkup_draft_group_id', $group );
		update_post_meta( $draft_id, '_lnkup_draft_answers', LNKup_Meta::json_for_meta( $payload ) );
		update_post_meta( $draft_id, '_lnkup_draft_updated_at', current_time( 'mysql' ) );
		update_post_meta( $draft_id, '_lnkup_draft_revision', (int) get_post_meta( $draft_id, '_lnkup_draft_revision', true ) + 1 );
		return rest_ensure_response( array( 'draft' => self::format_group_draft( (int) $draft_id ) ) );
	}

	/**
	 * POST submit selected stops. Requires Idempotency-Key header and group_id.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_apply( WP_REST_Request $request ) {
		$tour_id = (int) $request['id'];
		$ok      = self::guard( $tour_id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$key = (string) $request->get_header( 'idempotency_key' );
		if ( '' === $key || ! preg_match( '/^[A-Za-z0-9_\-]{8,64}$/', $key ) ) {
			return new WP_Error( 'lnkup_idempotency_key_required', __( 'Missing request key. Refresh and try again.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$result = self::submit( $tour_id, get_current_user_id(), $body );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Submit a multi-stop application.
	 *
	 * @param int   $tour_id Tour ID.
	 * @param int   $user_id Vendor.
	 * @param array $body    group_id, selections[{event_id,opportunity_id}], shared{}, per_stop{opp_id:{answers,selected_category}}, accepted_terms.
	 * @return array|WP_Error { group_id, results[], summary }
	 */
	public static function submit( $tour_id, $user_id, array $body ) {
		$group = self::sanitize_group_id( (string) ( $body['group_id'] ?? '' ) );
		if ( '' === $group ) {
			return new WP_Error( 'lnkup_group_id_required', __( 'Missing request key. Refresh and try again.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( empty( $body['accepted_terms'] ) ) {
			return new WP_Error( 'terms_required', __( 'Please confirm you understand payment is only required if accepted.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$payload = self::sanitize_payload( $tour_id, $user_id, $body );
		if ( ! $payload['selections'] ) {
			return new WP_Error( 'lnkup_no_stops', __( 'Choose at least one stop.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$lock = 'lnkup_group_' . $user_id . '_' . substr( $group, 0, 40 );
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'rate_limit', __( 'Your application is already being submitted. Please wait a moment.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		try {
			$results = array();
			foreach ( $payload['selections'] as $sel ) {
				$results[] = self::submit_stop( $tour_id, $user_id, $group, $sel, $payload );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$summary = array_count_values( wp_list_pluck( $results, 'result' ) );
		$ok      = ( $summary['submitted'] ?? 0 ) + ( $summary['already_submitted'] ?? 0 );
		$draft   = self::open_group_draft_id( $user_id, $tour_id );
		if ( $draft && $ok === count( $results ) ) {
			update_post_meta( $draft, '_lnkup_draft_state', 'submitted' );
		}
		if ( ( $summary['submitted'] ?? 0 ) > 0 ) {
			do_action( 'lnkup_application_group_submitted', $group, $tour_id, $user_id, $results );
		}
		return array(
			'group_id' => $group,
			'tour_id'  => $tour_id,
			'results'  => $results,
			'summary'  => array(
				'submitted'      => (int) ( $summary['submitted'] ?? 0 ),
				'already'        => (int) ( $summary['already_submitted'] ?? 0 ),
				'failed'         => count( $results ) - $ok,
				'total'          => count( $results ),
				'all_succeeded'  => $ok === count( $results ),
			),
		);
	}

	/**
	 * Submit one stop; never throws, always returns a result row.
	 *
	 * @param int    $tour_id Tour.
	 * @param int    $user_id Vendor.
	 * @param string $group   Group ID.
	 * @param array  $sel     event_id, opportunity_id.
	 * @param array  $payload Sanitized payload.
	 * @return array<string, mixed>
	 */
	private static function submit_stop( $tour_id, $user_id, $group, array $sel, array $payload ) {
		$opp_id   = (int) $sel['opportunity_id'];
		$event_id = (int) $sel['event_id'];
		$row      = array(
			'event_id'       => $event_id,
			'opportunity_id' => $opp_id,
			'event_name'     => $event_id ? get_the_title( $event_id ) : '',
			'city'           => (string) get_post_meta( $event_id, '_lnkup_city', true ),
			'date'           => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
		);
		// Selection must be a real slot of a stop on this tour (no cross-tour or forged IDs).
		if ( 'lnkup_opportunity' !== get_post_type( $opp_id )
			|| (int) get_post_meta( $opp_id, '_lnkup_event_id', true ) !== $event_id
			|| (int) get_post_meta( $event_id, '_lnkup_tour_id', true ) !== (int) $tour_id ) {
			return array_merge( $row, array( 'result' => 'ineligible', 'code' => 'not_on_tour', 'message' => __( 'This date is not part of the tour.', 'lnkup-marketplace' ) ) );
		}
		$idem  = substr( $group . '-' . $opp_id, 0, 64 );
		$prior = LNKup_Applications::find_by_idempotency_key( $user_id, $opp_id, $idem );
		if ( $prior ) {
			return array_merge( $row, array( 'result' => 'already_submitted', 'application_id' => $prior, 'status' => (string) get_post_meta( $prior, '_lnkup_application_status', true ) ) );
		}
		$stop     = $payload['per_stop'][ $opp_id ] ?? array();
		$shared   = $payload['shared'];
		$category = '' !== (string) ( $stop['selected_category'] ?? '' ) ? (string) $stop['selected_category'] : (string) $shared['selected_category'];
		if ( '' === $category ) {
			// The vendor's own category that this slot accepts (same default the single-apply form preselects).
			$ctx      = LNKup_Applications::build_apply_context( $opp_id, $user_id );
			$category = is_wp_error( $ctx ) ? '' : (string) ( $ctx['default_category'] ?? '' );
			// Slots without any category list accept "General", like the single-apply form's hidden field.
			if ( '' === $category && ! is_wp_error( $ctx ) && empty( $ctx['categories'] ) ) {
				$category = 'General';
			}
		}
		$result = LNKup_Applications::submit_with_profile(
			$user_id,
			$opp_id,
			array(
				'selected_category'   => $category,
				'application_message' => $shared['application_message'],
				'setup_notes'         => $shared['setup_notes'],
				'accepted_terms'      => true,
				'answers'             => (array) ( $stop['answers'] ?? array() ),
				'document_ids'        => $shared['document_ids'],
				'profile_overrides'   => $shared['profile_overrides'],
				'idempotency_key'     => $idem,
				'group_id'            => $group,
				'client'              => $payload['client'],
				'source'              => 'tour_group',
			)
		);
		if ( ! is_wp_error( $result ) ) {
			return array_merge( $row, array( 'result' => 'submitted', 'application_id' => (int) $result, 'status' => 'submitted' ) );
		}
		$code = $result->get_error_code();
		$map  = array(
			'duplicate'                => 'already_applied',
			'previously_declined'      => 'ineligible',
			'deadline'                 => 'closed',
			'closed'                   => 'closed',
			'invalid'                  => 'closed',
			'full'                     => 'full',
			'invite_only'              => 'ineligible',
			'not_vendor'               => 'ineligible',
			'no_profile'               => 'ineligible',
			'invalid_category'         => 'needs_input',
			'category_required'        => 'needs_input',
			'lnkup_answers_required'   => 'needs_input',
			'lnkup_invalid_answer'     => 'needs_input',
			'lnkup_documents_required' => 'needs_input',
		);
		$data = $result->get_error_data();
		return array_merge(
			$row,
			array(
				'result'         => $map[ $code ] ?? 'error',
				'code'           => $code,
				'message'        => $result->get_error_message(),
				'fields'         => is_array( $data ) ? (array) ( $data['fields'] ?? array() ) : array(),
				'application_id' => is_array( $data ) ? (int) ( $data['existing_application_id'] ?? 0 ) : 0,
			)
		);
	}

	/**
	 * Normalize selections and shared / per-stop answers.
	 *
	 * @param int   $tour_id Tour.
	 * @param int   $user_id Vendor.
	 * @param array $body    Raw body.
	 * @return array{selections:array,shared:array,per_stop:array,client:string}
	 */
	private static function sanitize_payload( $tour_id, $user_id, array $body ) {
		$selections = array();
		$seen       = array();
		foreach ( array_slice( (array) ( $body['selections'] ?? array() ), 0, self::MAX_STOPS ) as $sel ) {
			if ( ! is_array( $sel ) ) {
				continue;
			}
			$opp_id = absint( $sel['opportunity_id'] ?? 0 );
			if ( ! $opp_id || isset( $seen[ $opp_id ] ) ) {
				continue;
			}
			$seen[ $opp_id ] = true;
			$selections[]    = array(
				'event_id'       => absint( $sel['event_id'] ?? 0 ),
				'opportunity_id' => $opp_id,
			);
		}
		$shared_in = is_array( $body['shared'] ?? null ) ? $body['shared'] : array();
		$per_in    = is_array( $body['per_stop'] ?? null ) ? $body['per_stop'] : array();
		$per_stop  = array();
		foreach ( $per_in as $opp_id => $stop ) {
			$opp_id = absint( $opp_id );
			if ( ! $opp_id || ! is_array( $stop ) || ! isset( $seen[ $opp_id ] ) ) {
				continue;
			}
			$answers = LNKup_Application_Questions::validate_answers( $opp_id, is_array( $stop['answers'] ?? null ) ? $stop['answers'] : array(), $user_id, false );
			$map     = array();
			if ( ! is_wp_error( $answers ) ) {
				foreach ( $answers as $row ) {
					$map[ $row['id'] ] = $row['value'];
				}
			}
			$per_stop[ $opp_id ] = array(
				'selected_category' => sanitize_text_field( (string) ( $stop['selected_category'] ?? '' ) ),
				'answers'           => $map,
			);
		}
		return array(
			'selections' => $selections,
			'shared'     => array(
				'selected_category'   => sanitize_text_field( (string) ( $shared_in['selected_category'] ?? '' ) ),
				'application_message' => mb_substr( sanitize_textarea_field( (string) ( $shared_in['application_message'] ?? '' ) ), 0, 4000 ),
				'setup_notes'         => mb_substr( sanitize_textarea_field( (string) ( $shared_in['setup_notes'] ?? '' ) ), 0, 2000 ),
				'profile_overrides'   => LNKup_Applications::sanitize_profile_overrides( is_array( $shared_in['profile_overrides'] ?? null ) ? $shared_in['profile_overrides'] : array() ),
				'document_ids'        => LNKup_Application_Questions::owned_document_ids( (array) ( $shared_in['document_ids'] ?? array() ), $user_id ),
			),
			'per_stop'   => $per_stop,
			'client'     => in_array( $body['client'] ?? '', array( 'web', 'mobile_web', 'app' ), true ) ? $body['client'] : 'web',
		);
	}

	/**
	 * @param string $raw Raw group id.
	 */
	private static function sanitize_group_id( $raw ) {
		$id = strtolower( preg_replace( '/[^A-Za-z0-9\-]/', '', $raw ) );
		return strlen( $id ) >= 8 ? substr( $id, 0, 48 ) : '';
	}

	/**
	 * @param int $user_id Vendor.
	 * @param int $tour_id Tour.
	 */
	private static function open_group_draft_id( $user_id, $tour_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => LNKup_Application_Drafts::POST_STATUS,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_query'     => array(
					array( 'key' => '_lnkup_draft_vendor_user_id', 'value' => absint( $user_id ) ),
					array( 'key' => '_lnkup_draft_tour_id', 'value' => absint( $tour_id ) ),
					array( 'key' => '_lnkup_draft_state', 'value' => LNKup_Application_Drafts::STATE_OPEN ),
				),
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * @param int $draft_id Draft ID.
	 * @return array<string, mixed>
	 */
	private static function format_group_draft( $draft_id ) {
		$payload = json_decode( (string) get_post_meta( $draft_id, '_lnkup_draft_answers', true ), true );
		return array(
			'id'         => (int) $draft_id,
			'group_id'   => (string) get_post_meta( $draft_id, '_lnkup_draft_group_id', true ),
			'revision'   => (int) get_post_meta( $draft_id, '_lnkup_draft_revision', true ),
			'updated_at' => (string) get_post_meta( $draft_id, '_lnkup_draft_updated_at', true ),
			'payload'    => is_array( $payload ) ? $payload : array(),
		);
	}
}
