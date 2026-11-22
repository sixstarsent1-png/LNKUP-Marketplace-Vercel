<?php
/**
 * Server-owned application drafts (save & resume, cross-device).
 *
 * A draft is an `lnkup_application` post in the internal `lnkup_app_draft` status. Drafts never
 * carry the canonical link meta (`_lnkup_vendor_user_id`, `_lnkup_opportunity_id`,
 * `_lnkup_application_status`, ...), so no existing query, counter, inventory hold, organizer
 * list or notification can ever see them. They only use `_lnkup_draft_*` keys.
 *
 * Finalizing a draft goes through LNKup_Applications::submit_with_profile() (the one submit
 * path), with the draft ID as idempotency key, so a draft can produce at most one application.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Application_Drafts {

	const POST_STATUS   = 'lnkup_app_draft';
	const MAX_BYTES     = 20000;
	const MAX_PER_USER  = 50;
	const STATE_OPEN    = 'open';
	const STATES_CLOSED = array( 'submitted', 'expired', 'discarded' );
	const STEPS         = array( 'opportunity', 'business', 'questions', 'documents', 'review' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_status' ), 5 );
		add_action( LNKup_Marketplace_Cron::HOOK_DAILY, array( __CLASS__, 'expire_stale' ), 30 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_opportunity_deleted' ) );
	}

	/**
	 * Internal status: hidden from WP_Query `any`, admin lists and search.
	 */
	public static function register_status() {
		register_post_status(
			self::POST_STATUS,
			array(
				'label'                     => _x( 'Application draft', 'post status', 'lnkup-marketplace' ),
				'public'                    => false,
				'internal'                  => true,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);
	}

	/**
	 * Create or update the vendor's open draft for an opportunity.
	 *
	 * @param int      $user_id        Vendor.
	 * @param int      $opportunity_id Opportunity.
	 * @param array    $payload        step, fields, answers, profile_overrides, document_ids, client.
	 * @param int|null $revision       Revision the client last saw (null = blind write).
	 * @return array|WP_Error Draft DTO.
	 */
	public static function upsert( $user_id, $opportunity_id, array $payload, $revision = null ) {
		$user_id        = absint( $user_id );
		$opportunity_id = absint( $opportunity_id );
		if ( ! $user_id || ( ! LNKup_Roles::is_vendor( $user_id ) && ! LNKup_Capabilities::user_has_manage_all( $user_id ) ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'Vendor access required.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$opp = get_post( $opportunity_id );
		if ( ! $opp || 'lnkup_opportunity' !== $opp->post_type || 'publish' !== $opp->post_status ) {
			return new WP_Error( 'lnkup_not_found', __( 'This opportunity is no longer available.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( get_post_meta( $opportunity_id, '_lnkup_invite_only', true ) && ! lnkup_vendor_can_discover_opportunity( $user_id, $opportunity_id )
			&& ! LNKup_Applications::invite_token_valid_for( (string) ( $payload['invite_token'] ?? '' ), $opportunity_id, $user_id ) ) {
			return new WP_Error( 'lnkup_invite_only', __( 'This booth is invite-only.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$existing_app = LNKup_Applications::find_active_application( $opportunity_id, $user_id );
		if ( $existing_app ) {
			return new WP_Error( 'duplicate', lnkup_copy( 'apply_already_applied' ), array( 'status' => 409, 'existing_application_id' => $existing_app ) );
		}
		$previous = LNKup_Applications::reapply_state( $opportunity_id, $user_id );
		if ( $previous && 'blocked' === $previous['reapply'] ) {
			return new WP_Error(
				'previously_declined',
				__( 'This organizer declined your earlier application. You can apply again if they invite you or change this opportunity.', 'lnkup-marketplace' ),
				array( 'status' => 409, 'existing_application_id' => (int) $previous['id'] )
			);
		}

		return self::with_lock(
			'lnkup_draft_' . $user_id . '_' . $opportunity_id,
			static function () use ( $user_id, $opportunity_id, $payload, $revision ) {
				$draft_id = self::open_draft_id( $user_id, $opportunity_id );
				if ( $draft_id && null !== $revision && (int) $revision !== (int) get_post_meta( $draft_id, '_lnkup_draft_revision', true ) ) {
					return new WP_Error(
						'lnkup_draft_conflict',
						__( 'This application was updated on another device. We loaded the latest version.', 'lnkup-marketplace' ),
						array(
							'status' => 409,
							'draft'  => self::format( $draft_id ),
						)
					);
				}
				if ( ! $draft_id && self::open_count( $user_id ) >= self::MAX_PER_USER ) {
					return new WP_Error( 'lnkup_too_many_drafts', __( 'You have too many applications in progress. Submit or discard some first.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
				}
				$data = self::sanitize_payload( $opportunity_id, $user_id, $payload );
				if ( is_wp_error( $data ) ) {
					return $data;
				}
				if ( ! $draft_id ) {
					$draft_id = wp_insert_post(
						array(
							'post_type'   => 'lnkup_application',
							'post_status' => self::POST_STATUS,
							'post_author' => $user_id,
							'post_title'  => sprintf( 'Draft: %s', get_the_title( $opportunity_id ) ),
						),
						true
					);
					if ( is_wp_error( $draft_id ) ) {
						return new WP_Error( 'lnkup_draft_save_failed', __( 'We could not save your progress. Try again.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
					}
					update_post_meta( $draft_id, '_lnkup_draft_vendor_user_id', $user_id );
					update_post_meta( $draft_id, '_lnkup_draft_opportunity_id', $opportunity_id );
					update_post_meta( $draft_id, '_lnkup_draft_event_id', (int) get_post_meta( $opportunity_id, '_lnkup_event_id', true ) );
					update_post_meta( $draft_id, '_lnkup_draft_started_at', current_time( 'mysql' ) );
					update_post_meta( $draft_id, '_lnkup_draft_state', self::STATE_OPEN );
					update_post_meta( $draft_id, '_lnkup_draft_quoted_fee', (float) get_post_meta( $opportunity_id, '_lnkup_vendor_fee', true ) );
					update_post_meta( $draft_id, '_lnkup_draft_revision', 0 );
					do_action( 'lnkup_application_draft_started', (int) $draft_id, $opportunity_id, $user_id );
				}
				self::write_payload( (int) $draft_id, $data );
				return self::format( (int) $draft_id );
			}
		);
	}

	/**
	 * Normalize a draft payload; answers are validated leniently (required checks happen on submit).
	 *
	 * @param int   $opportunity_id Opportunity.
	 * @param int   $user_id        Vendor.
	 * @param array $payload        Raw payload.
	 * @return array|WP_Error
	 */
	private static function sanitize_payload( $opportunity_id, $user_id, array $payload ) {
		$step = sanitize_key( (string) ( $payload['step'] ?? 'opportunity' ) );
		if ( ! in_array( $step, self::STEPS, true ) ) {
			$step = 'opportunity';
		}
		$fields_in = is_array( $payload['fields'] ?? null ) ? $payload['fields'] : array();
		$fields    = array(
			'selected_category'   => sanitize_text_field( (string) ( $fields_in['selected_category'] ?? '' ) ),
			'application_message' => mb_substr( sanitize_textarea_field( (string) ( $fields_in['application_message'] ?? '' ) ), 0, 4000 ),
			'setup_notes'         => mb_substr( sanitize_textarea_field( (string) ( $fields_in['setup_notes'] ?? '' ) ), 0, 2000 ),
		);
		$answers_raw = is_array( $payload['answers'] ?? null ) ? $payload['answers'] : array();
		$checked     = LNKup_Application_Questions::validate_answers( $opportunity_id, $answers_raw, $user_id, false );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$answers = array();
		foreach ( $checked as $row ) {
			$answers[ $row['id'] ] = $row['value'];
		}
		$data = array(
			'step'              => $step,
			'fields'            => $fields,
			'answers'           => $answers,
			'profile_overrides' => LNKup_Applications::sanitize_profile_overrides( is_array( $payload['profile_overrides'] ?? null ) ? $payload['profile_overrides'] : array() ),
			'document_ids'      => LNKup_Application_Questions::owned_document_ids( (array) ( $payload['document_ids'] ?? array() ), $user_id ),
			'invite_token'      => sanitize_text_field( (string) ( $payload['invite_token'] ?? '' ) ),
			'client'            => in_array( $payload['client'] ?? '', array( 'web', 'mobile_web', 'app' ), true ) ? $payload['client'] : 'web',
		);
		if ( strlen( (string) wp_json_encode( $data ) ) > self::MAX_BYTES ) {
			return new WP_Error( 'lnkup_draft_too_large', __( 'Your answers are too long to save. Shorten them and try again.', 'lnkup-marketplace' ), array( 'status' => 413 ) );
		}
		return $data;
	}

	/**
	 * @param int   $draft_id Draft post ID.
	 * @param array $data     Sanitized payload.
	 */
	private static function write_payload( $draft_id, array $data ) {
		update_post_meta(
			$draft_id,
			'_lnkup_draft_answers',
			LNKup_Meta::json_for_meta(
				array(
					'fields'            => $data['fields'],
					'answers'           => $data['answers'],
					'profile_overrides' => $data['profile_overrides'],
					'invite_token'      => $data['invite_token'],
				)
			)
		);
		update_post_meta( $draft_id, '_lnkup_draft_step', $data['step'] );
		update_post_meta( $draft_id, '_lnkup_draft_document_ids', $data['document_ids'] );
		update_post_meta( $draft_id, '_lnkup_draft_client', $data['client'] );
		update_post_meta( $draft_id, '_lnkup_draft_updated_at', current_time( 'mysql' ) );
		update_post_meta( $draft_id, '_lnkup_draft_revision', (int) get_post_meta( $draft_id, '_lnkup_draft_revision', true ) + 1 );
	}

	/**
	 * Submit a draft as a real application. Safe to call repeatedly.
	 *
	 * @param int   $draft_id Draft post ID.
	 * @param int   $user_id  Vendor.
	 * @param array $args     accept_current_price (bool), accepted_terms (bool), idempotency_key.
	 * @return array|WP_Error { application_id, status, draft }.
	 */
	public static function finalize( $draft_id, $user_id, array $args = array() ) {
		$draft_id = absint( $draft_id );
		$user_id  = absint( $user_id );
		if ( ! self::owns( $draft_id, $user_id ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Application draft not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$opportunity_id = (int) get_post_meta( $draft_id, '_lnkup_draft_opportunity_id', true );

		return self::with_lock(
			'lnkup_draft_' . $user_id . '_' . $opportunity_id,
			static function () use ( $draft_id, $user_id, $opportunity_id, $args ) {
				$state = (string) get_post_meta( $draft_id, '_lnkup_draft_state', true );
				if ( 'submitted' === $state ) {
					$app_id = (int) get_post_meta( $draft_id, '_lnkup_draft_application_id', true );
					return array( 'application_id' => $app_id, 'status' => (string) get_post_meta( $app_id, '_lnkup_application_status', true ), 'replayed' => true );
				}
				if ( self::STATE_OPEN !== $state ) {
					return new WP_Error( 'lnkup_draft_closed', __( 'This application can no longer be submitted.', 'lnkup-marketplace' ), array( 'status' => 409, 'state' => $state ) );
				}

				// Revalidate price before commitment: a changed booth price is shown, never silently applied.
				$quoted  = (float) get_post_meta( $draft_id, '_lnkup_draft_quoted_fee', true );
				$current = (float) get_post_meta( $opportunity_id, '_lnkup_vendor_fee', true );
				if ( abs( $quoted - $current ) >= 0.005 && empty( $args['accept_current_price'] ) ) {
					update_post_meta( $draft_id, '_lnkup_draft_quoted_fee', $current );
					return new WP_Error(
						'lnkup_price_changed',
						sprintf(
							/* translators: 1: old price, 2: new price */
							__( 'The booth price changed from %1$s to %2$s. Review it and submit again.', 'lnkup-marketplace' ),
							lnkup_currency( $quoted ),
							lnkup_currency( $current )
						),
						array(
							'status'         => 409,
							'previous_price' => $quoted,
							'current_price'  => $current,
						)
					);
				}

				$payload = self::payload( $draft_id );
				$result  = LNKup_Applications::submit_with_profile(
					$user_id,
					$opportunity_id,
					array(
						'selected_category'   => $payload['fields']['selected_category'] ?? '',
						'application_message' => $payload['fields']['application_message'] ?? '',
						'setup_notes'         => $payload['fields']['setup_notes'] ?? '',
						'accepted_terms'      => ! empty( $args['accepted_terms'] ),
						'answers'             => $payload['answers'],
						'document_ids'        => (array) get_post_meta( $draft_id, '_lnkup_draft_document_ids', true ),
						'profile_overrides'   => $payload['profile_overrides'],
						'invite_token'        => $payload['invite_token'],
						'idempotency_key'     => 'draft-' . $draft_id,
						'draft_id'            => $draft_id,
						'group_id'            => (string) get_post_meta( $draft_id, '_lnkup_draft_group_id', true ),
						'started_at'          => (string) get_post_meta( $draft_id, '_lnkup_draft_started_at', true ),
						'client'              => (string) get_post_meta( $draft_id, '_lnkup_draft_client', true ),
						'source'              => 'draft',
					)
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				update_post_meta( $draft_id, '_lnkup_draft_state', 'submitted' );
				update_post_meta( $draft_id, '_lnkup_draft_application_id', (int) $result );
				update_post_meta( $draft_id, '_lnkup_draft_updated_at', current_time( 'mysql' ) );
				do_action( 'lnkup_application_draft_submitted', $draft_id, (int) $result );
				return array(
					'application_id' => (int) $result,
					'status'         => (string) get_post_meta( (int) $result, '_lnkup_application_status', true ),
					'replayed'       => false,
				);
			}
		);
	}

	/**
	 * Discard a draft (kept for funnel analytics, hidden from the vendor).
	 *
	 * @param int $draft_id Draft ID.
	 * @param int $user_id  Vendor.
	 * @return true|WP_Error
	 */
	public static function discard( $draft_id, $user_id ) {
		if ( ! self::owns( $draft_id, $user_id ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Application draft not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( self::STATE_OPEN === (string) get_post_meta( $draft_id, '_lnkup_draft_state', true ) ) {
			update_post_meta( $draft_id, '_lnkup_draft_state', 'discarded' );
			update_post_meta( $draft_id, '_lnkup_draft_updated_at', current_time( 'mysql' ) );
		}
		return true;
	}

	/**
	 * Open drafts for the vendor, newest first, as resume cards.
	 *
	 * @param int $user_id Vendor.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_for_vendor( $user_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => self::POST_STATUS,
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array( 'key' => '_lnkup_draft_vendor_user_id', 'value' => absint( $user_id ) ),
					array( 'key' => '_lnkup_draft_state', 'value' => self::STATE_OPEN ),
					array( 'key' => '_lnkup_draft_opportunity_id', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ),
				),
				'meta_key'       => '_lnkup_draft_updated_at', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
			)
		);
		return array_map( array( __CLASS__, 'format' ), array_map( 'intval', $ids ) );
	}

	/**
	 * Vendor's open draft for an opportunity (for the apply modal / wizard), or null.
	 *
	 * @param int $user_id        Vendor.
	 * @param int $opportunity_id Opportunity.
	 * @return array|null
	 */
	public static function get_for_opportunity( $user_id, $opportunity_id ) {
		$id = self::open_draft_id( $user_id, $opportunity_id );
		return $id ? self::format( $id ) : null;
	}

	/**
	 * Draft DTO with live eligibility, price check and progress.
	 *
	 * @param int $draft_id Draft ID.
	 * @return array<string, mixed>
	 */
	public static function format( $draft_id ) {
		$draft_id       = (int) $draft_id;
		$user_id        = (int) get_post_meta( $draft_id, '_lnkup_draft_vendor_user_id', true );
		$opportunity_id = (int) get_post_meta( $draft_id, '_lnkup_draft_opportunity_id', true );
		$event_id       = (int) get_post_meta( $draft_id, '_lnkup_draft_event_id', true );
		$payload        = self::payload( $draft_id );
		$step           = (string) get_post_meta( $draft_id, '_lnkup_draft_step', true ) ?: 'opportunity';
		$card           = $opportunity_id ? lnkup_get_opportunity_card_data( $opportunity_id ) : array();
		$eligibility    = $opportunity_id ? LNKup_Applications::evaluate_eligibility( $opportunity_id, $user_id, $card ?: null, (string) $payload['invite_token'] ) : array( 'can_apply' => false, 'block_reason' => 'invalid' );
		$quoted         = (float) get_post_meta( $draft_id, '_lnkup_draft_quoted_fee', true );
		$current        = $opportunity_id ? (float) get_post_meta( $opportunity_id, '_lnkup_vendor_fee', true ) : 0.0;
		$step_index     = array_search( $step, self::STEPS, true );

		return array(
			'id'                => $draft_id,
			'state'             => (string) get_post_meta( $draft_id, '_lnkup_draft_state', true ),
			'opportunity_id'    => $opportunity_id,
			'event_id'          => $event_id,
			'event_name'        => $event_id ? get_the_title( $event_id ) : (string) ( $card['event_name'] ?? '' ),
			'opportunity_title' => $opportunity_id ? get_the_title( $opportunity_id ) : '',
			'event_date'        => (string) ( $card['event_date'] ?? '' ),
			'location_label'    => (string) ( $card['location_label'] ?? '' ),
			'deadline'          => (string) ( $card['deadline'] ?? '' ),
			'step'              => $step,
			'progress_percent'  => false === $step_index ? 0 : (int) round( ( (int) $step_index / ( count( self::STEPS ) - 1 ) ) * 100 ),
			'fields'            => $payload['fields'],
			'answers'           => $payload['answers'],
			'profile_overrides' => $payload['profile_overrides'],
			'document_ids'      => array_map( 'intval', (array) get_post_meta( $draft_id, '_lnkup_draft_document_ids', true ) ),
			'revision'          => (int) get_post_meta( $draft_id, '_lnkup_draft_revision', true ),
			'started_at'        => (string) get_post_meta( $draft_id, '_lnkup_draft_started_at', true ),
			'updated_at'        => (string) get_post_meta( $draft_id, '_lnkup_draft_updated_at', true ),
			'can_submit'        => ! empty( $eligibility['can_apply'] ),
			'block_reason'      => (string) ( $eligibility['block_reason'] ?? '' ),
			'price'             => array(
				'quoted'  => $quoted,
				'current' => $current,
				'changed' => abs( $quoted - $current ) >= 0.005,
			),
			'resume_url'        => add_query_arg( array( 'apply' => $opportunity_id, 'resume' => 1 ), lnkup_page_url_helper( 'opportunities' ) ),
		);
	}

	/**
	 * Close open drafts that can never be submitted (deadline passed, slot closed, event cancelled).
	 * Keeps them for analytics; stops reminders and resume cards.
	 */
	public static function expire_stale() {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => self::POST_STATUS,
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array( 'key' => '_lnkup_draft_state', 'value' => self::STATE_OPEN ),
				),
			)
		);
		$expired = 0;
		foreach ( $ids as $draft_id ) {
			$opp_id = (int) get_post_meta( $draft_id, '_lnkup_draft_opportunity_id', true );
			if ( ! $opp_id ) {
				continue;
			}
			$opp    = get_post( $opp_id );
			$card   = $opp ? lnkup_get_opportunity_card_data( $opp_id ) : array();
			$closed = ! $opp || 'publish' !== $opp->post_status
				|| 'open' !== (string) get_post_meta( $opp_id, '_lnkup_opportunity_status', true )
				|| lnkup_deadline_passed( $card['deadline'] ?? '' );
			if ( $closed ) {
				update_post_meta( $draft_id, '_lnkup_draft_state', 'expired' );
				++$expired;
			}
		}
		return $expired;
	}

	/**
	 * @param int $post_id Post being deleted.
	 */
	public static function on_opportunity_deleted( $post_id ) {
		if ( 'lnkup_opportunity' !== get_post_type( $post_id ) ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => self::POST_STATUS,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => '_lnkup_draft_opportunity_id', 'value' => (int) $post_id ),
					array( 'key' => '_lnkup_draft_state', 'value' => self::STATE_OPEN ),
				),
			)
		);
		foreach ( $ids as $draft_id ) {
			update_post_meta( $draft_id, '_lnkup_draft_state', 'expired' );
		}
	}

	/**
	 * Decoded draft payload with defaults.
	 *
	 * @param int $draft_id Draft ID.
	 * @return array{fields:array,answers:array,profile_overrides:array,invite_token:string}
	 */
	public static function payload( $draft_id ) {
		$raw = json_decode( (string) get_post_meta( $draft_id, '_lnkup_draft_answers', true ), true );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'fields'            => is_array( $raw['fields'] ?? null ) ? $raw['fields'] : array(),
			'answers'           => is_array( $raw['answers'] ?? null ) ? $raw['answers'] : array(),
			'profile_overrides' => is_array( $raw['profile_overrides'] ?? null ) ? $raw['profile_overrides'] : array(),
			'invite_token'      => (string) ( $raw['invite_token'] ?? '' ),
		);
	}

	/**
	 * @param int $draft_id Draft ID.
	 * @param int $user_id  User.
	 */
	public static function owns( $draft_id, $user_id ) {
		return 'lnkup_application' === get_post_type( $draft_id )
			&& self::POST_STATUS === get_post_status( $draft_id )
			&& (int) get_post_meta( $draft_id, '_lnkup_draft_vendor_user_id', true ) === (int) $user_id
			&& (int) $user_id > 0;
	}

	/**
	 * @param int $user_id        Vendor.
	 * @param int $opportunity_id Opportunity.
	 */
	private static function open_draft_id( $user_id, $opportunity_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => self::POST_STATUS,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_query'     => array(
					array( 'key' => '_lnkup_draft_vendor_user_id', 'value' => absint( $user_id ) ),
					array( 'key' => '_lnkup_draft_opportunity_id', 'value' => absint( $opportunity_id ) ),
					array( 'key' => '_lnkup_draft_state', 'value' => self::STATE_OPEN ),
				),
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * @param int $user_id Vendor.
	 */
	private static function open_count( $user_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => self::POST_STATUS,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => '_lnkup_draft_vendor_user_id', 'value' => absint( $user_id ) ),
					array( 'key' => '_lnkup_draft_state', 'value' => self::STATE_OPEN ),
				),
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * MySQL named lock around draft read-modify-write.
	 *
	 * @param string   $name     Lock name.
	 * @param callable $callback Work.
	 * @return mixed|WP_Error
	 */
	private static function with_lock( $name, callable $callback ) {
		global $wpdb;
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'lnkup_busy', __( 'Still saving your last change. Try again in a moment.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		try {
			return $callback();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
}
