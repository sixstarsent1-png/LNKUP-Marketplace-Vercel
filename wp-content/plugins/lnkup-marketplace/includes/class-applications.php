<?php
/**
 * Application workflow.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Applications {

	/**
	 * Submit application (legacy wrapper).
	 *
	 * @param int    $opportunity_id Opportunity ID.
	 * @param int    $user_id        Vendor user ID.
	 * @param string $message        Application message.
	 * @return int|WP_Error
	 */
	/**
	 * Re-apply policy hooks: material changes to an opportunity re-open it for declined vendors.
	 */
	public static function init() {
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_changed' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_changed' ), 10, 3 );
		add_action( 'set_object_terms', array( __CLASS__, 'on_terms_changed' ), 10, 6 );
		add_action( 'lnkup_application_withdrawn', array( __CLASS__, 'on_withdrawn' ), 5 );
	}

	/**
	 * Keep the index's legacy application counter in step when a vendor withdraws.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function on_withdrawn( $app_id ) {
		$opp_id = (int) get_post_meta( (int) $app_id, '_lnkup_opportunity_id', true );
		if ( $opp_id ) {
			LNKup_Marketplace_Index::decrement_applications( $opp_id );
		}
	}

	public static function submit( $opportunity_id, $user_id, $message = '' ) {
		return self::submit_with_profile(
			$user_id,
			$opportunity_id,
			array(
				'application_message' => $message,
				'accepted_terms'      => true,
			)
		);
	}

	/**
	 * Build apply-with-profile context for modal and REST.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 * @param int $user_id        Vendor user ID (0 when logged out).
	 * @return array|WP_Error
	 */
	public static function build_apply_context( $opportunity_id, $user_id = 0, $invite_token = '' ) {
		$opportunity_id = absint( $opportunity_id );
		$user_id        = $user_id ?: (int) get_current_user_id();

		$opp = get_post( $opportunity_id );
		if ( ! $opp || 'lnkup_opportunity' !== $opp->post_type ) {
			return new WP_Error( 'invalid', __( 'Invalid opportunity.', 'lnkup-marketplace' ) );
		}

		$card      = lnkup_get_opportunity_card_data( $opportunity_id );
		$opp_terms = self::opportunity_category_labels( $opportunity_id, $card );

		$required_docs = lnkup_get_opportunity_required_documents( $opportunity_id );
		$doc_labels    = LNKup_Documents::document_types();

		$eligibility = self::evaluate_eligibility( $opportunity_id, $user_id, $card, (string) $invite_token );

		// Invite-only slot details stay private to invited vendors, owners and admins.
		if ( 'invite_only' === $eligibility['block_reason'] || ( ! $user_id && get_post_meta( $opportunity_id, '_lnkup_invite_only', true ) && '' === (string) $invite_token ) ) {
			return new WP_Error( 'lnkup_invite_only', __( 'This booth is invite-only.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}

		$profile     = null;
		$strength    = array();
		$documents   = array();
		$warnings    = array();
		$categories  = array();
		$default_cat = '';

		if ( $user_id && $eligibility['profile_id'] ) {
			$profile_id = (int) $eligibility['profile_id'];
			$profile    = LNKup_Vendor_CRM_Profile::get( $profile_id, 0 );
			$strength   = class_exists( 'LNKup_Growth_Profile_Strength' )
				? LNKup_Growth_Profile_Strength::calculate( $profile_id )
				: array();

			$vendor_cats = self::flatten_category_list( $profile['category'] ?? array() );
			if ( $opp_terms ) {
				$categories = $opp_terms;
			} elseif ( $vendor_cats ) {
				$categories = $vendor_cats;
			}

			$intersect = array_values( array_intersect( $vendor_cats, $opp_terms ) );
			if ( $intersect ) {
				$default_cat = $intersect[0];
			} elseif ( $opp_terms ) {
				$default_cat = $opp_terms[0];
			} elseif ( $vendor_cats ) {
				$default_cat = $vendor_cats[0];
			}

			$documents = self::document_statuses_for_user( $user_id, $required_docs, $doc_labels );
			$warnings  = self::build_warnings( $profile, $strength, $documents, $vendor_cats, $opp_terms );
		}

		$button_state = self::button_state_from_eligibility( $eligibility );
		$draft        = null;
		if ( $user_id && class_exists( 'LNKup_Application_Drafts' ) ) {
			$open = LNKup_Application_Drafts::get_for_opportunity( $user_id, $opportunity_id );
			if ( $open ) {
				$draft = array_intersect_key( $open, array_flip( array( 'id', 'revision', 'step', 'updated_at', 'fields', 'answers', 'document_ids', 'price' ) ) );
			}
		}
		$tour_id   = (int) get_post_meta( (int) get_post_meta( $opportunity_id, '_lnkup_event_id', true ), '_lnkup_tour_id', true );
		$questions = LNKup_Application_Questions::get_for_opportunity( $opportunity_id );
		$own_docs  = array();
		if ( $user_id && in_array( 'file', wp_list_pluck( $questions, 'type' ), true ) && class_exists( 'LNKup_Documents' ) ) {
			foreach ( LNKup_Documents::get_for_vendor( $user_id ) as $doc ) {
				$type       = (string) get_post_meta( $doc->ID, '_lnkup_document_type', true );
				$own_docs[] = array( 'id' => (int) $doc->ID, 'label' => (string) ( $doc_labels[ $type ] ?? $doc->post_title ) );
			}
		}

		return array(
			'opportunity'   => array_merge(
				$card,
				array(
					'opportunity_id'     => $opportunity_id,
					'categories_needed'  => $opp_terms,
					'required_documents' => array_map(
						static function ( $slug ) use ( $doc_labels ) {
							return array(
								'type'  => $slug,
								'label' => $doc_labels[ $slug ] ?? ucwords( str_replace( '_', ' ', $slug ) ),
							);
						},
						$required_docs
					),
				)
			),
			'eligibility'   => $eligibility,
			'profile'       => $profile ? self::profile_snapshot_from_dto( $profile, $strength ) : null,
			'strength'      => $strength,
			'documents'     => $documents,
			'warnings'      => $warnings,
			'categories'    => $categories,
			'default_category' => $default_cat,
			'button_state'  => $button_state,
			'questions'     => $questions,
			'vendor_documents' => $own_docs,
			'documents_required_to_apply' => LNKup_Application_Questions::documents_gate( $opportunity_id ),
			'reusable_documents' => ( $user_id && $eligibility['profile_id'] && class_exists( 'LNKup_Document_Vault' ) && $required_docs )
				? LNKup_Document_Vault::find_reusable( (int) $eligibility['profile_id'], $opportunity_id )
				: null,
			'document_sharing' => class_exists( 'LNKup_Passport_Settings' ) && LNKup_Passport_Settings::enabled( 'document_vault_sharing' ),
			'draft'         => $draft,
			'previous_application' => $eligibility['previous_application'] ?? null,
			'tour_id'       => $tour_id,
			'features'      => array(
				'drafts'           => LNKup_Rollout::enabled( 'apply_wizard_enabled', $user_id ?: null ),
				'tour_multi_apply' => class_exists( 'LNKup_Application_Groups' ) && LNKup_Application_Groups::enabled( $user_id ?: null ),
			),
		);
	}

	/**
	 * Submit application with vendor profile snapshot.
	 *
	 * @param int   $vendor_user_id Vendor user ID.
	 * @param int   $opportunity_id Opportunity ID.
	 * @param array $args           Submission args.
	 * @return int|WP_Error
	 */
	public static function submit_with_profile( $vendor_user_id, $opportunity_id, $args = array() ) {
		global $wpdb;
		$lock = 'lnkup_apply_' . absint( $vendor_user_id ) . '_' . absint( $opportunity_id );
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'rate_limit', __( 'Your application is already being submitted. Please wait a moment.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		try {
			return self::submit_with_profile_locked( $vendor_user_id, $opportunity_id, $args );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Body of submit_with_profile; callers must hold the per-vendor/opportunity lock so the
	 * duplicate check and insert cannot interleave across concurrent requests.
	 *
	 * @param int   $vendor_user_id Vendor user ID.
	 * @param int   $opportunity_id Opportunity ID.
	 * @param array $args           Submission args.
	 * @return int|WP_Error
	 */
	private static function submit_with_profile_locked( $vendor_user_id, $opportunity_id, $args = array() ) {
		$vendor_user_id = absint( $vendor_user_id );
		$opportunity_id = absint( $opportunity_id );

		if ( ! $vendor_user_id || ! is_user_logged_in() ) {
			return new WP_Error( 'not_logged_in', __( 'Please log in to apply.', 'lnkup-marketplace' ) );
		}

		if ( ! LNKup_Roles::is_vendor( $vendor_user_id ) && ! LNKup_Capabilities::user_has_manage_all( $vendor_user_id ) ) {
			return new WP_Error( 'not_vendor', __( 'Vendor access required to apply.', 'lnkup-marketplace' ) );
		}

		// Idempotent retries (network retry, double tap, multi-stop resubmit) return the original application.
		$idempotency_key = isset( $args['idempotency_key'] ) ? substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $args['idempotency_key'] ), 0, 64 ) : '';
		if ( '' !== $idempotency_key ) {
			$prior = self::find_by_idempotency_key( $vendor_user_id, $opportunity_id, $idempotency_key );
			if ( $prior ) {
				return $prior;
			}
		}

		$invite_token = isset( $args['invite_token'] ) ? sanitize_text_field( (string) $args['invite_token'] ) : '';
		$card         = lnkup_get_opportunity_card_data( $opportunity_id );
		$eligibility  = self::evaluate_eligibility( $opportunity_id, $vendor_user_id, $card, $invite_token );

		if ( ! $eligibility['can_apply'] ) {
			$code = $eligibility['block_reason'] ?: 'blocked';
			$msg  = self::block_reason_message( $eligibility );
			$data = array();
			if ( ! empty( $eligibility['existing_application_id'] ) ) {
				$data['existing_application_id'] = (int) $eligibility['existing_application_id'];
				$data['status']                  = $eligibility['existing_application_status'];
			}
			return new WP_Error( $code, $msg, $data );
		}

		$message      = isset( $args['application_message'] ) ? sanitize_textarea_field( (string) $args['application_message'] ) : '';
		$setup_notes  = isset( $args['setup_notes'] ) ? sanitize_textarea_field( (string) $args['setup_notes'] ) : '';
		$category     = isset( $args['selected_category'] ) ? sanitize_text_field( (string) $args['selected_category'] ) : '';
		$accepted     = ! empty( $args['accepted_terms'] );

		if ( ! $accepted ) {
			return new WP_Error( 'terms_required', __( 'Please confirm you understand payment is only required if accepted.', 'lnkup-marketplace' ) );
		}

		if ( ! $category ) {
			return new WP_Error( 'category_required', __( 'Please select a category for your application.', 'lnkup-marketplace' ) );
		}

		$profile_id = (int) $eligibility['profile_id'];
		$context    = self::build_apply_context( $opportunity_id, $vendor_user_id, $invite_token );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$allowed_cats = (array) ( $context['categories'] ?? array() );
		if ( $allowed_cats && ! in_array( $category, $allowed_cats, true ) ) {
			return new WP_Error( 'invalid_category', __( 'Please select a valid category for this opportunity.', 'lnkup-marketplace' ) );
		}

		$answers = LNKup_Application_Questions::validate_answers( $opportunity_id, is_array( $args['answers'] ?? null ) ? $args['answers'] : array(), $vendor_user_id, true );
		if ( is_wp_error( $answers ) ) {
			return $answers;
		}
		if ( LNKup_Application_Questions::documents_gate( $opportunity_id ) ) {
			foreach ( (array) ( $context['documents'] ?? array() ) as $doc_row ) {
				if ( in_array( $doc_row['status'] ?? 'missing', array( 'missing', 'expired' ), true ) ) {
					return new WP_Error( 'lnkup_documents_required', __( 'This organizer needs your required documents before you apply.', 'lnkup-marketplace' ), array( 'status' => 422, 'document' => (string) ( $doc_row['type'] ?? '' ) ) );
				}
			}
		}
		$document_ids = LNKup_Application_Questions::owned_document_ids( (array) ( $args['document_ids'] ?? array() ), $vendor_user_id );

		$opp       = get_post( $opportunity_id );
		$event_id  = (int) get_post_meta( $opportunity_id, '_lnkup_event_id', true );
		$organizer_id = $event_id ? (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ) : 0;
		$event     = $event_id ? get_post( $event_id ) : null;
		$user      = get_userdata( $vendor_user_id );
		$display   = $user ? $user->display_name : '';

		$app_id = wp_insert_post(
			array(
				'post_title'   => sprintf(
					/* translators: 1: vendor name, 2: event name */
					__( 'Application: %1$s → %2$s', 'lnkup-marketplace' ),
					$display,
					$event ? $event->post_title : $opp->post_title
				),
				'post_type'    => 'lnkup_application',
				'post_status'  => 'publish',
				'post_content' => $message,
			)
		);

		if ( is_wp_error( $app_id ) ) {
			return $app_id;
		}

		$profile_dto  = $context['profile'] ?? array();
		// "This application only" edits from the wizard: applied to the frozen snapshot, never to the profile.
		foreach ( self::sanitize_profile_overrides( (array) ( $args['profile_overrides'] ?? array() ) ) as $field => $value ) {
			$profile_dto[ $field ] = $value;
		}
		$documents    = $context['documents'] ?? array();
		$docs_missing = false;
		foreach ( $documents as $doc_row ) {
			if ( in_array( $doc_row['status'], array( 'missing', 'expired' ), true ) ) {
				$docs_missing = true;
				break;
			}
		}

		update_post_meta( $app_id, '_lnkup_vendor_profile_id', $profile_id );
		update_post_meta( $app_id, '_lnkup_vendor_user_id', $vendor_user_id );
		update_post_meta( $app_id, '_lnkup_opportunity_id', $opportunity_id );
		update_post_meta( $app_id, '_lnkup_event_id', $event_id );
		update_post_meta( $app_id, '_lnkup_organizer_user_id', $organizer_id );
		update_post_meta( $app_id, '_lnkup_application_status', 'submitted' );
		update_post_meta( $app_id, '_lnkup_submitted_at', current_time( 'mysql' ) );
		update_post_meta( $app_id, '_lnkup_selected_category', $category );
		if ( $setup_notes ) {
			update_post_meta( $app_id, '_lnkup_setup_notes', $setup_notes );
		}
		update_post_meta( $app_id, '_lnkup_profile_snapshot', LNKup_Meta::json_for_meta( $profile_dto ) );
		update_post_meta( $app_id, '_lnkup_documents_snapshot', LNKup_Meta::json_for_meta( $documents ) );
		update_post_meta( $app_id, '_lnkup_documents_missing', $docs_missing );
		if ( $answers ) {
			update_post_meta( $app_id, '_lnkup_application_answers', LNKup_Meta::json_for_meta( $answers ) );
		}
		if ( $document_ids ) {
			update_post_meta( $app_id, '_lnkup_document_ids', $document_ids );
		}
		if ( '' !== $idempotency_key ) {
			update_post_meta( $app_id, '_lnkup_idempotency_key', $idempotency_key );
		}
		if ( ! empty( $args['group_id'] ) ) {
			update_post_meta( $app_id, '_lnkup_submission_group_id', sanitize_key( (string) $args['group_id'] ) );
		}
		if ( ! empty( $args['draft_id'] ) ) {
			update_post_meta( $app_id, '_lnkup_draft_id', absint( $args['draft_id'] ) );
		}
		if ( ! empty( $args['started_at'] ) ) {
			update_post_meta( $app_id, '_lnkup_started_at', sanitize_text_field( (string) $args['started_at'] ) );
		}
		if ( ! empty( $args['client'] ) ) {
			update_post_meta( $app_id, '_lnkup_submitted_client', sanitize_key( (string) $args['client'] ) );
		}
		if ( '' !== $invite_token && self::invite_token_valid_for( $invite_token, $opportunity_id, $vendor_user_id ) ) {
			$invite = LNKup_Vendor_Invites::get_by_token( $invite_token );
			if ( $invite ) {
				update_post_meta( $app_id, '_lnkup_invite_id', (int) $invite->ID );
			}
		}
		self::record_history( $app_id, '', 'submitted', array( 'source' => (string) ( $args['source'] ?? 'web' ) ) );

		// Document Vault: share only what the vendor explicitly consented to, for this application only.
		if ( ! empty( $args['share_consent'] ) && ! empty( $args['share_document_ids'] ) && class_exists( 'LNKup_Document_Vault' ) && LNKup_Passport_Settings::enabled( 'document_vault_sharing' ) ) {
			$required_types = lnkup_get_opportunity_required_documents( $opportunity_id );
			foreach ( array_slice( array_map( 'absint', (array) $args['share_document_ids'] ), 0, 10 ) as $share_doc_id ) {
				if ( in_array( (string) get_post_meta( $share_doc_id, '_lnkup_document_type', true ), $required_types, true ) ) {
					LNKup_Document_Vault::share( $profile_id, $share_doc_id, (int) $app_id, $vendor_user_id );
				}
			}
		}

		LNKup_Notifications::send_application_submitted( $app_id );
		LNKup_Marketplace_Index::increment_applications( $opportunity_id );
		do_action( 'lnkup_application_submitted', $app_id );

		return $app_id;
	}

	/**
	 * Evaluate whether a user can apply to an opportunity.
	 *
	 * @param int        $opportunity_id Opportunity ID.
	 * @param int        $user_id        User ID.
	 * @param array|null $card           Optional card data.
	 * @param string     $invite_token   Optional invite link token.
	 * @return array<string, mixed>
	 */
	public static function evaluate_eligibility( $opportunity_id, $user_id, $card = null, $invite_token = '' ) {
		$result = array(
			'can_apply'                    => false,
			'block_reason'                 => '',
			'profile_id'                   => 0,
			'existing_application_id'      => 0,
			'existing_application_status'  => '',
		);

		if ( ! $user_id ) {
			$result['block_reason'] = 'logged_out';
			return $result;
		}

		if ( ! LNKup_Roles::is_vendor( $user_id ) && ! LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			$result['block_reason'] = 'not_vendor';
			return $result;
		}

		$profile_id = lnkup_get_vendor_profile_id( $user_id );
		if ( ! $profile_id ) {
			$result['block_reason'] = 'no_profile';
			return $result;
		}
		$result['profile_id'] = $profile_id;

		$existing = self::find_active_application( $opportunity_id, $user_id );
		if ( $existing ) {
			$result['block_reason']                = 'duplicate';
			$result['existing_application_id']     = (int) $existing;
			$result['existing_application_status'] = (string) get_post_meta( $existing, '_lnkup_application_status', true );
			return $result;
		}

		$previous = self::reapply_state( $opportunity_id, $user_id );
		if ( $previous ) {
			$result['previous_application'] = $previous;
			if ( 'blocked' === $previous['reapply'] ) {
				$result['block_reason']                = 'previously_declined';
				$result['existing_application_id']     = (int) $previous['id'];
				$result['existing_application_status'] = (string) $previous['status'];
				return $result;
			}
		}

		$opp = get_post( $opportunity_id );
		if ( ! $opp || 'lnkup_opportunity' !== $opp->post_type ) {
			$result['block_reason'] = 'invalid';
			return $result;
		}

		$opp_status = get_post_meta( $opportunity_id, '_lnkup_opportunity_status', true );
		if ( 'open' !== $opp_status || 'publish' !== $opp->post_status ) {
			$result['block_reason'] = 'closed';
			return $result;
		}

		$event_id = (int) get_post_meta( $opportunity_id, '_lnkup_event_id', true );
		if ( $event_id && in_array( (string) get_post_meta( $event_id, '_lnkup_event_status', true ), array( 'cancelled', 'canceled', 'postponed' ), true ) ) {
			$result['block_reason'] = 'closed';
			return $result;
		}

		if ( $event_id && class_exists( 'LNKup_Event_Authenticity' ) && ! LNKup_Event_Authenticity::is_bookable( $event_id ) ) {
			$result['block_reason']  = 'closed';
			$result['closed_reason'] = 'authenticity_pending';
			return $result;
		}

		// Invite-only slots need an invite for this vendor, or a valid invite link token (B-15).
		if ( get_post_meta( $opportunity_id, '_lnkup_invite_only', true )
			&& ! LNKup_Capabilities::user_has_manage_all( $user_id )
			&& ! lnkup_vendor_can_discover_opportunity( $user_id, $opportunity_id )
			&& ! self::invite_token_valid_for( $invite_token, $opportunity_id, $user_id ) ) {
			$result['block_reason'] = 'invite_only';
			return $result;
		}

		if ( ! $card ) {
			$card = lnkup_get_opportunity_card_data( $opportunity_id );
		}

		if ( empty( $card['is_open'] ) ) {
			if ( lnkup_deadline_passed( $card['deadline'] ?? '' ) ) {
				$result['block_reason'] = 'deadline';
			} elseif ( empty( $card['spots_unlimited'] ) && (int) ( $card['spots_remaining'] ?? 0 ) <= 0 ) {
				$result['block_reason'] = 'full';
			} else {
				$result['block_reason'] = 'closed';
			}
			return $result;
		}

		$result['can_apply'] = true;
		return $result;
	}

	/**
	 * Snapshot fields a vendor may override for one application without touching their profile.
	 */
	const PROFILE_OVERRIDE_FIELDS = array( 'business_name', 'products', 'setup_needs', 'contact_email', 'contact_phone' );

	/**
	 * @param array $raw Raw overrides.
	 * @return array<string, string>
	 */
	public static function sanitize_profile_overrides( array $raw ) {
		$out = array();
		foreach ( self::PROFILE_OVERRIDE_FIELDS as $field ) {
			if ( ! isset( $raw[ $field ] ) || ! is_scalar( $raw[ $field ] ) ) {
				continue;
			}
			$value = 'contact_email' === $field ? sanitize_email( (string) $raw[ $field ] ) : sanitize_textarea_field( (string) $raw[ $field ] );
			if ( '' !== $value ) {
				$out[ $field ] = mb_substr( $value, 0, 2000 );
			}
		}
		return $out;
	}

	/**
	 * Application already created for this vendor + opportunity by the same idempotency key.
	 *
	 * @param int    $vendor_user_id Vendor.
	 * @param int    $opportunity_id Opportunity.
	 * @param string $key            Idempotency key.
	 * @return int Application ID or 0.
	 */
	public static function find_by_idempotency_key( $vendor_user_id, $opportunity_id, $key ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array( 'key' => '_lnkup_idempotency_key', 'value' => (string) $key ),
					array( 'key' => '_lnkup_vendor_user_id', 'value' => (int) $vendor_user_id ),
					array( 'key' => '_lnkup_opportunity_id', 'value' => (int) $opportunity_id ),
				),
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Statuses that count as an existing application for duplicate checks. Rejected and
	 * cancelled (withdrawn / expired) applications do not block re-applying (B-16).
	 */
	const ACTIVE_STATUSES = array( 'submitted', 'under_review', 'info_requested', 'approved', 'payment_required', 'paid', 'waitlisted', 'completed' );

	/**
	 * Newest active (non-draft, non-closed) application for a vendor + opportunity.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 * @param int $user_id        Vendor user ID.
	 * @return int Application ID or 0.
	 */
	public static function find_active_application( $opportunity_id, $user_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'post_status'    => 'any',
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => '_lnkup_opportunity_id', 'value' => (int) $opportunity_id ),
					array( 'key' => '_lnkup_vendor_user_id', 'value' => (int) $user_id ),
					array(
						'relation' => 'OR',
						array( 'key' => '_lnkup_application_status', 'value' => self::ACTIVE_STATUSES, 'compare' => 'IN' ),
						array( 'key' => '_lnkup_application_status', 'compare' => 'NOT EXISTS' ),
					),
				),
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	const REAPPLY_ALLOWED_META = '_lnkup_reapply_allowed_at';
	const MATERIAL_CHANGE_META = '_lnkup_material_changed_at';

	/** Opportunity / event fields whose change re-opens applications for declined vendors. */
	const MATERIAL_OPPORTUNITY_META = array( '_lnkup_vendor_fee', '_lnkup_application_deadline' );
	const MATERIAL_EVENT_META       = array( '_lnkup_event_date' );

	/**
	 * Newest closed (rejected or cancelled) application for a vendor + opportunity, or 0.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 * @param int $user_id        Vendor user ID.
	 */
	public static function find_latest_closed_application( $opportunity_id, $user_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array( 'key' => '_lnkup_opportunity_id', 'value' => (int) $opportunity_id ),
					array( 'key' => '_lnkup_vendor_user_id', 'value' => (int) $user_id ),
					array( 'key' => '_lnkup_application_status', 'value' => array( 'rejected', 'cancelled' ), 'compare' => 'IN' ),
				),
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Whether a vendor may apply again after an earlier application closed.
	 *
	 * Withdrawn / expired applications can always re-apply. A declined (rejected) application
	 * blocks re-applying until the organizer allows it, invites the vendor again, or the
	 * opportunity materially changes (fee, deadline, categories, event date).
	 *
	 * @param int $opportunity_id Opportunity ID.
	 * @param int $user_id        Vendor user ID.
	 * @return array{id:int,status:string,reapply:string,reason:string}|null Null when there is no closed application.
	 */
	public static function reapply_state( $opportunity_id, $user_id ) {
		$app_id = self::find_latest_closed_application( $opportunity_id, $user_id );
		if ( ! $app_id ) {
			return null;
		}
		$status = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		$state  = array( 'id' => $app_id, 'status' => $status, 'reapply' => 'allowed', 'reason' => '' );
		if ( 'rejected' !== $status ) {
			$state['reason'] = sanitize_key( (string) get_post_meta( $app_id, '_lnkup_cancellation_reason', true ) ) ?: 'cancelled';
			return $state;
		}
		$declined_at = (string) get_post_meta( $app_id, '_lnkup_decided_at', true ) ?: (string) get_post_field( 'post_modified', $app_id );
		if ( '' !== (string) get_post_meta( $app_id, self::REAPPLY_ALLOWED_META, true ) ) {
			$state['reason'] = 'organizer_allowed';
			return $state;
		}
		if ( self::invited_since( $opportunity_id, $user_id, $declined_at ) ) {
			$state['reason'] = 'invited_again';
			return $state;
		}
		if ( self::materially_changed_since( $opportunity_id, $declined_at ) ) {
			$state['reason'] = 'opportunity_changed';
			return $state;
		}
		$state['reapply'] = 'blocked';
		$state['reason']  = 'declined';
		return $state;
	}

	/**
	 * Organizer invited this vendor to this opportunity after a given local time.
	 */
	private static function invited_since( $opportunity_id, $user_id, $since ) {
		if ( '' === (string) $since ) {
			return false;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_invite',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'date_query'     => array( array( 'after' => (string) $since, 'inclusive' => false, 'column' => 'post_date' ) ),
				'meta_query'     => array(
					array( 'key' => '_lnkup_vendor_user_id', 'value' => (int) $user_id ),
					array( 'key' => '_lnkup_opportunity_id', 'value' => (int) $opportunity_id ),
				),
			)
		);
		return (bool) $ids;
	}

	/**
	 * Opportunity (or its event) had a material change after a given local time.
	 */
	private static function materially_changed_since( $opportunity_id, $since ) {
		if ( '' === (string) $since ) {
			return false;
		}
		$event_id = (int) get_post_meta( $opportunity_id, '_lnkup_event_id', true );
		foreach ( array( (int) $opportunity_id, $event_id ) as $post_id ) {
			$changed = $post_id ? (string) get_post_meta( $post_id, self::MATERIAL_CHANGE_META, true ) : '';
			if ( '' !== $changed && strtotime( $changed ) > strtotime( (string) $since ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Stamp a material change when a fee, deadline or event date changes.
	 *
	 * @param int    $meta_id   Meta ID.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	public static function on_meta_changed( $meta_id, $object_id, $meta_key ) {
		unset( $meta_id );
		$type = get_post_type( (int) $object_id );
		if ( ( 'lnkup_opportunity' === $type && in_array( $meta_key, self::MATERIAL_OPPORTUNITY_META, true ) )
			|| ( 'lnkup_event' === $type && in_array( $meta_key, self::MATERIAL_EVENT_META, true ) ) ) {
			update_post_meta( (int) $object_id, self::MATERIAL_CHANGE_META, current_time( 'mysql' ) );
		}
	}

	/**
	 * Stamp a material change when an opportunity's vendor categories change.
	 *
	 * @param int    $object_id  Post ID.
	 * @param array  $terms      Terms.
	 * @param array  $tt_ids     New term taxonomy IDs.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Append mode.
	 * @param array  $old_tt_ids Previous term taxonomy IDs.
	 */
	public static function on_terms_changed( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		unset( $terms, $append );
		if ( 'lnkup_vendor_category' !== $taxonomy || 'lnkup_opportunity' !== get_post_type( (int) $object_id ) || ! $old_tt_ids ) {
			return;
		}
		$new = array_map( 'intval', (array) $tt_ids );
		$old = array_map( 'intval', (array) $old_tt_ids );
		sort( $new );
		sort( $old );
		if ( $new !== $old ) {
			update_post_meta( (int) $object_id, self::MATERIAL_CHANGE_META, current_time( 'mysql' ) );
		}
	}

	/**
	 * Organizer lets a declined vendor apply again to the same opportunity.
	 *
	 * @param int $app_id  Rejected application.
	 * @param int $user_id Acting organizer.
	 * @return true|WP_Error
	 */
	public static function allow_reapply( $app_id, $user_id ) {
		if ( 'rejected' !== (string) get_post_meta( $app_id, '_lnkup_application_status', true ) ) {
			return new WP_Error( 'lnkup_not_declined', __( 'Only declined applications can be re-opened for the vendor.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		update_post_meta( $app_id, self::REAPPLY_ALLOWED_META, current_time( 'mysql' ) );
		update_post_meta( $app_id, '_lnkup_reapply_allowed_by', (int) $user_id );
		if ( class_exists( 'LNKup_Organizer_CRM' ) ) {
			LNKup_Organizer_CRM::log_activity(
				'reapply_allowed',
				(int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true ),
				(int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ),
				array( 'application_id' => (int) $app_id, 'event_id' => (int) get_post_meta( $app_id, '_lnkup_event_id', true ), 'actor_user_id' => (int) $user_id )
			);
		}
		do_action( 'lnkup_application_reapply_allowed', (int) $app_id, (int) $user_id );
		return true;
	}

	/**
	 * Whether an invite link token grants this vendor access to an invite-only opportunity.
	 *
	 * @param string $token          Invite token from the apply link.
	 * @param int    $opportunity_id Opportunity ID.
	 * @param int    $user_id        Vendor user ID.
	 */
	public static function invite_token_valid_for( $token, $opportunity_id, $user_id ) {
		$token = (string) $token;
		if ( '' === $token || ! class_exists( 'LNKup_Vendor_Invites' ) ) {
			return false;
		}
		$invite = LNKup_Vendor_Invites::get_by_token( $token );
		if ( ! $invite || ! hash_equals( (string) get_post_meta( $invite->ID, '_lnkup_token', true ), $token ) ) {
			return false;
		}
		if ( (int) get_post_meta( $invite->ID, '_lnkup_opportunity_id', true ) !== (int) $opportunity_id ) {
			return false;
		}
		$invited_user = (int) get_post_meta( $invite->ID, '_lnkup_vendor_user_id', true );
		if ( $invited_user && $invited_user !== (int) $user_id ) {
			return false;
		}
		if ( in_array( (string) get_post_meta( $invite->ID, '_lnkup_status', true ), array( LNKup_Vendor_Invites::STATUS_EXPIRED, LNKup_Vendor_Invites::STATUS_DECLINED ), true ) ) {
			return false;
		}
		$expires = (string) get_post_meta( $invite->ID, '_lnkup_expires_at', true );
		return ! ( $expires && strtotime( $expires ) < time() );
	}

	/**
	 * Document statuses for required types.
	 *
	 * @param int   $user_id       Vendor user ID.
	 * @param array $required_docs Document type slugs.
	 * @param array $doc_labels    Label map.
	 * @return array<int, array<string, mixed>>
	 */
	public static function document_statuses_for_user( $user_id, $required_docs, $doc_labels = array() ) {
		if ( ! $doc_labels ) {
			$doc_labels = LNKup_Documents::document_types();
		}

		$by_type = LNKup_Documents::current_by_type( (int) $user_id );

		$rows = array();
		foreach ( $required_docs as $type ) {
			$label  = $doc_labels[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) );
			$status = 'missing';
			$doc    = $by_type[ $type ] ?? null;

			if ( $doc ) {
				$ver_status = get_post_meta( $doc->ID, '_lnkup_verification_status', true );
				$expires    = get_post_meta( $doc->ID, '_lnkup_expiration_date', true );
				if ( $expires && strtotime( $expires ) < strtotime( 'today' ) ) {
					$status = 'expired';
				} elseif ( 'approved' === $ver_status ) {
					$status = 'approved';
				} elseif ( 'pending' === $ver_status || $ver_status ) {
					$status = 'pending';
				} else {
					$status = 'uploaded';
				}
			}

			$rows[] = array(
				'type'    => $type,
				'label'   => $label,
				'status'  => $status,
				'doc_id'  => $doc ? (int) $doc->ID : 0,
				'version' => $doc ? max( 1, (int) get_post_meta( $doc->ID, '_lnkup_version', true ) ) : 0,
			);
		}

		return $rows;
	}

	/**
	 * Build soft warnings for apply modal.
	 *
	 * @param array|null $profile     Profile DTO.
	 * @param array      $strength    Strength payload.
	 * @param array      $documents   Document rows.
	 * @param array      $vendor_cats Vendor categories.
	 * @param array      $opp_cats    Opportunity categories.
	 * @return array<int, array<string, string>>
	 */
	private static function build_warnings( $profile, $strength, $documents, $vendor_cats, $opp_cats ) {
		$warnings = array();
		$score    = (int) ( $strength['score'] ?? 0 );
		$tier     = (string) ( $strength['tier'] ?? '' );

		if ( $score < 50 || in_array( $tier, array( 'Started', 'Needs Work' ), true ) ) {
			$warnings[] = array(
				'code'       => 'profile_incomplete',
				'message'    => lnkup_copy( 'apply_profile_warning' ),
				'action_url' => lnkup_page_url_helper( 'vendor-profile' ),
				'action_label' => __( 'Update profile', 'lnkup-marketplace' ),
			);
		}

		$missing_docs = array_filter(
			$documents,
			static function ( $row ) {
				return in_array( $row['status'], array( 'missing', 'expired' ), true );
			}
		);
		if ( $missing_docs ) {
			$warnings[] = array(
				'code'    => 'documents_missing',
				'message' => lnkup_copy( 'apply_documents_warning' ),
			);
		}

		if ( $opp_cats && ! self::categories_intersect( $vendor_cats, $opp_cats ) ) {
			$warnings[] = array(
				'code'               => 'category_mismatch',
				'message'            => lnkup_copy( 'apply_category_mismatch' ),
				'action_url'         => lnkup_page_url_helper( 'vendor-profile' ) . '#categories',
				'action_label'       => __( 'Update profile categories', 'lnkup-marketplace' ),
				'event_categories'   => array_values( $opp_cats ),
				'profile_categories' => array_values( $vendor_cats ),
			);
		}

		if ( $profile && empty( $profile['business_name'] ) ) {
			$warnings[] = array(
				'code'    => 'missing_business_name',
				'message' => __( 'Your profile is missing information organizers usually review.', 'lnkup-marketplace' ),
			);
		}

		return $warnings;
	}

	/**
	 * Flatten CRM / form category values into display labels.
	 *
	 * @param mixed $value Categories as array, string, or term-shaped rows.
	 * @return array<int, string>
	 */
	private static function flatten_category_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[,|]/', $value ) ?: array();
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				$item = $item['name'] ?? $item['slug'] ?? '';
			}
			$item = trim( (string) $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Opportunity category labels from taxonomy plus card / fee-row fields.
	 *
	 * @param int   $opportunity_id Opportunity ID.
	 * @param array $card           Card payload.
	 * @return array<int, string>
	 */
	private static function opportunity_category_labels( $opportunity_id, $card = array() ): array {
		$names = wp_get_post_terms( $opportunity_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
		if ( is_wp_error( $names ) ) {
			$names = array();
		}
		$extra = array();
		foreach ( array( 'category', 'categories', 'vendor_categories' ) as $key ) {
			if ( ! empty( $card[ $key ] ) ) {
				$extra = array_merge( $extra, self::flatten_category_list( $card[ $key ] ) );
			}
		}
		$fee_rows = get_post_meta( $opportunity_id, '_lnkup_fee_rows', true );
		if ( is_array( $fee_rows ) ) {
			foreach ( $fee_rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$extra = array_merge(
					$extra,
					self::flatten_category_list( $row['categories'] ?? array() ),
					self::flatten_category_list( $row['vendor_categories'] ?? array() ),
					self::flatten_category_list( $row['vendor_category'] ?? '' ),
					self::flatten_category_list( $row['category'] ?? '' )
				);
			}
		}
		return self::flatten_category_list( array_merge( $names, $extra ) );
	}

	/**
	 * Compare vendor vs opportunity categories using names, slugs, and “Vendor” suffixes.
	 *
	 * @param array $left  Category labels.
	 * @param array $right Category labels.
	 */
	private static function categories_intersect( array $left, array $right ): bool {
		return (bool) array_intersect( self::normalize_category_keys( $left ), self::normalize_category_keys( $right ) );
	}

	/**
	 * @param array $cats Category labels.
	 * @return array<int, string>
	 */
	private static function normalize_category_keys( array $cats ): array {
		$keys = array();
		foreach ( self::flatten_category_list( $cats ) as $raw ) {
			$lower    = strtolower( $raw );
			$stripped = trim( (string) preg_replace( '/\s+vendor$/', '', $lower ) );
			$compact  = (string) preg_replace( '/[\s_-]+/', '', $lower );
			$keys[]   = $lower;
			$keys[]   = $stripped;
			$keys[]   = $compact;
			$keys[]   = (string) preg_replace( '/[\s_-]+/', '', $stripped );
			$term     = get_term_by( 'slug', sanitize_title( $raw ), 'lnkup_vendor_category' );
			if ( ! $term ) {
				$term = get_term_by( 'name', $raw, 'lnkup_vendor_category' );
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$name     = strtolower( $term->name );
				$keys[]   = $name;
				$keys[]   = trim( (string) preg_replace( '/\s+vendor$/', '', $name ) );
				$keys[]   = $term->slug;
			}
		}
		return array_values( array_unique( array_filter( $keys ) ) );
	}

	/**
	 * Map eligibility to button state for cards/modal.
	 *
	 * @param array $eligibility Eligibility array.
	 */
	private static function button_state_from_eligibility( $eligibility ) {
		if ( ! empty( $eligibility['can_apply'] ) ) {
			return 'apply';
		}
		$map = array(
			'logged_out' => 'login',
			'no_profile' => 'create_profile',
			'duplicate'  => 'already_applied',
			'previously_declined' => 'declined',
			'invite_only' => 'invite_only',
			'deadline'   => 'closed',
			'full'       => 'full',
			'closed'     => 'closed',
		);
		return $map[ $eligibility['block_reason'] ] ?? 'closed';
	}

	/**
	 * Human message for block reason.
	 *
	 * @param array $eligibility Eligibility array.
	 */
	private static function block_reason_message( $eligibility ) {
		switch ( $eligibility['block_reason'] ) {
			case 'logged_out':
				return lnkup_copy( 'apply_logged_out' );
			case 'not_vendor':
				return __( 'Vendor access required to apply.', 'lnkup-marketplace' );
			case 'no_profile':
				return lnkup_copy( 'apply_no_profile' );
			case 'duplicate':
				return lnkup_copy( 'apply_already_applied' );
			case 'previously_declined':
				return __( 'This organizer declined your earlier application. You can apply again if they invite you or change this opportunity.', 'lnkup-marketplace' );
			case 'deadline':
				return __( 'The application deadline for this opportunity has passed.', 'lnkup-marketplace' );
			case 'full':
				return __( 'No spots remaining for this opportunity.', 'lnkup-marketplace' );
			case 'invite_only':
				return __( 'This booth is invite-only. Use the invite link the organizer sent you.', 'lnkup-marketplace' );
			case 'closed':
				return __( 'This opportunity is not open for applications.', 'lnkup-marketplace' );
			default:
				return __( 'Unable to submit application.', 'lnkup-marketplace' );
		}
	}

	/**
	 * Lightweight profile snapshot from CRM DTO.
	 *
	 * @param array $profile  CRM profile.
	 * @param array $strength Strength payload.
	 * @return array<string, mixed>
	 */
	public static function profile_snapshot_from_dto( $profile, $strength = array() ) {
		$cities = $profile['cities_served'] ?? array();
		if ( ! is_array( $cities ) ) {
			$cities = array();
		}
		$setup_bits = array_filter(
			array(
				$profile['setup_type'] ?? '',
				$profile['power_needs'] ?? '',
				$profile['water_needs'] ?? '',
				$profile['tent_size'] ?? '',
				$profile['trailer_size'] ?? '',
			)
		);

		return array(
			'business_name'   => $profile['business_name'] ?? '',
			'category'        => $profile['category'] ?? array(),
			'products'        => $profile['products_services'] ?? '',
			'cities_served'   => $cities,
			'setup_needs'     => implode( ' · ', $setup_bits ),
			'contact_email'   => $profile['email'] ?? '',
			'contact_phone'   => $profile['phone'] ?? '',
			'logo_url'        => $profile['logo'] ?? '',
			'strength_tier'   => $strength['tier'] ?? ( $profile['profile_strength_tier'] ?? '' ),
			'strength_score'  => (int) ( $strength['score'] ?? ( $profile['profile_strength_score'] ?? 0 ) ),
			'verified'        => ! empty( $profile['verified'] ),
		);
	}

	/**
	 * Read stored profile snapshot for organizer review.
	 *
	 * @param int $app_id Application ID.
	 * @return array<string, mixed>
	 */
	public static function get_profile_snapshot( $app_id ) {
		$raw = get_post_meta( $app_id, '_lnkup_profile_snapshot', true );
		if ( $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		if ( ! $profile_id ) {
			return array();
		}

		$profile  = LNKup_Vendor_CRM_Profile::get( $profile_id, 0 );
		$strength = class_exists( 'LNKup_Growth_Profile_Strength' )
			? LNKup_Growth_Profile_Strength::calculate( $profile_id )
			: array();

		return $profile ? self::profile_snapshot_from_dto( $profile, $strength ) : array();
	}

	/**
	 * Read stored documents snapshot for organizer review.
	 *
	 * @param int $app_id Application ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_documents_snapshot( $app_id ) {
		$raw = get_post_meta( $app_id, '_lnkup_documents_snapshot', true );
		if ( $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}

	/**
	 * Transition application status.
	 *
	 * @param int    $app_id Application ID.
	 * @param string $status New status.
	 * @param array  $extra  Extra data.
	 */
	public static function transition( $app_id, $status, $extra = array() ) {
		$app_id = absint( $app_id );
		if ( ! $app_id || 'lnkup_application' !== get_post_type( $app_id ) || LNKup_Application_Drafts::POST_STATUS === get_post_status( $app_id ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Application not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$status = LNKup_Security::sanitize_application_status( $status );
		$prev   = (string) get_post_meta( $app_id, '_lnkup_application_status', true );

		$offer_return = self::$allow_offer_return && 'payment_required' === $prev && 'waitlisted' === $status;
		if ( ! $offer_return && ! self::can_transition( $prev, $status ) ) {
			return new WP_Error(
				'invalid_transition',
				__( 'This application can no longer be changed that way.', 'lnkup-marketplace' ),
				array(
					'status' => 409,
					'from'   => $prev,
					'to'     => $status,
				)
			);
		}

		if ( 'approved' === $status ) {
			$opp_id   = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
			$reserved = LNKup_Opportunity_Inventory::with_lock(
				$opp_id,
				static function () use ( $app_id, $opp_id, $prev, $extra ) {
					if ( ! LNKup_Opportunity_Inventory::has_room( $opp_id, $app_id ) ) {
						return new WP_Error(
							'opportunity_full',
							__( 'This vendor slot is full. Waitlist the vendor or add spots before approving.', 'lnkup-marketplace' ),
							array( 'status' => 409 )
						);
					}
					update_post_meta( $app_id, '_lnkup_application_status', 'approved' );
					update_post_meta( $app_id, '_lnkup_approved_at', current_time( 'mysql' ) );
					$payment_id = LNKup_Payments::create_from_application( $app_id );
					if ( ! $payment_id || is_wp_error( $payment_id ) ) {
						// Never leave a booking waiting on a payment that does not exist (B-6).
						update_post_meta( $app_id, '_lnkup_application_status', $prev );
						delete_post_meta( $app_id, '_lnkup_approved_at' );
						return new WP_Error(
							'lnkup_payment_create_failed',
							__( 'We could not set up payment for this booking. Please try approving again.', 'lnkup-marketplace' ),
							array( 'status' => 503 )
						);
					}
					self::record_history( $app_id, $prev, 'approved', $extra );
					update_post_meta( $app_id, '_lnkup_application_status', 'payment_required' );
					update_post_meta( $app_id, '_lnkup_payment_due_at', self::payment_due_from_extra( $extra ) );
					return (int) $payment_id;
				}
			);
			if ( is_wp_error( $reserved ) ) {
				return $reserved;
			}
			$payment_id = $reserved;
			update_post_meta( $app_id, '_lnkup_decided_at', current_time( 'mysql' ) );
			self::record_history( $app_id, 'approved', 'payment_required', array() );
		} elseif ( 'paid' === $status && ! in_array( $prev, LNKup_Opportunity_Inventory::HOLD_STATUSES, true ) ) {
			// Money for an application that is not holding a spot (e.g. a waitlist offer that
			// expired): only book it if the slot still has room, otherwise mark_paid() flags it.
			$opp_id = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
			$booked = LNKup_Opportunity_Inventory::with_lock(
				$opp_id,
				static function () use ( $app_id, $opp_id, $prev, $extra ) {
					if ( ! LNKup_Opportunity_Inventory::has_room( $opp_id, $app_id ) ) {
						return new WP_Error( 'opportunity_full', __( 'This vendor slot filled before the payment arrived.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
					}
					update_post_meta( $app_id, '_lnkup_application_status', 'paid' );
					self::record_history( $app_id, $prev, 'paid', $extra );
					return true;
				}
			);
			if ( is_wp_error( $booked ) ) {
				return $booked;
			}
		} else {
			update_post_meta( $app_id, '_lnkup_application_status', $status );
			if ( in_array( $status, array( 'rejected', 'waitlisted' ), true ) ) {
				update_post_meta( $app_id, '_lnkup_decided_at', current_time( 'mysql' ) );
			}
			self::record_history( $app_id, $prev, $status, $extra );
		}

		if ( 'approved' === $status ) {
			LNKup_Notifications::send_application_approved( $app_id );
			if ( $payment_id ) {
				LNKup_Financial_Service::maybe_finalize_zero_amount( (int) $payment_id, 'free_booth' );
			}
		} elseif ( 'rejected' === $status ) {
			if ( ! empty( $extra['reason'] ) ) {
				update_post_meta( $app_id, '_lnkup_rejection_reason', sanitize_textarea_field( $extra['reason'] ) );
			}
			self::void_pending_payment( $app_id, 'rejected' );
			LNKup_Notifications::send_application_rejected( $app_id );
		} elseif ( 'cancelled' === $status ) {
			self::void_pending_payment( $app_id, (string) ( $extra['reason'] ?? 'cancelled' ) );
			if ( 'paid' === $prev ) {
				LNKup_Packets::lock_for_application( $app_id );
			}
		} elseif ( 'waitlisted' === $status ) {
			LNKup_Notifications::send_application_waitlisted( $app_id );
		} elseif ( 'paid' === $status ) {
			if ( 'paid' === $prev ) {
				return true;
			}
			update_post_meta( $app_id, '_lnkup_paid_at', current_time( 'mysql' ) );
			LNKup_Packets::unlock_for_application( $app_id );
			LNKup_Notifications::send_payment_confirmed( $app_id );
			if ( class_exists( 'LNKup_Organizer_CRM' ) ) {
				LNKup_Organizer_CRM::log_activity(
					'packet_unlocked',
					(int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true ),
					(int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ),
					array( 'application_id' => $app_id, 'event_id' => (int) get_post_meta( $app_id, '_lnkup_event_id', true ) )
				);
			}
		}

		do_action( 'lnkup_application_status_changed', $app_id, $prev, get_post_meta( $app_id, '_lnkup_application_status', true ) );

		return true;
	}

	/**
	 * Payment deadline for a new approval: a caller-supplied UTC deadline between one hour and
	 * 30 days out (waitlist offers), otherwise the standard seven days.
	 *
	 * @param array $extra Transition extras.
	 */
	private static function payment_due_from_extra( $extra ) {
		$requested = is_array( $extra ) ? (string) ( $extra['payment_due_at'] ?? '' ) : '';
		$ts        = '' !== $requested ? strtotime( $requested . ' UTC' ) : false;
		if ( $ts && $ts >= time() + HOUR_IN_SECONDS && $ts <= time() + 30 * DAY_IN_SECONDS ) {
			return gmdate( 'Y-m-d H:i:s', $ts );
		}
		return gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) );
	}

	/** Set only by return_to_waitlist(); never reachable from request input. */
	private static $allow_offer_return = false;

	/**
	 * Put an unpaid waitlist offer back on the waitlist (offer expired or withdrawn).
	 *
	 * This is the only path from payment_required to waitlisted; the normal TRANSITIONS
	 * table does not allow it, so organizer actions cannot use it. The pending payment is
	 * voided first so the old checkout link cannot still be paid.
	 *
	 * @param int    $app_id Application ID.
	 * @param string $reason offer_expired|offer_revoked.
	 * @return true|WP_Error
	 */
	public static function return_to_waitlist( $app_id, $reason ) {
		$app_id = absint( $app_id );
		$reason = in_array( $reason, array( 'offer_expired', 'offer_revoked' ), true ) ? $reason : 'offer_expired';
		if ( 'payment_required' !== (string) get_post_meta( $app_id, '_lnkup_application_status', true ) ) {
			return new WP_Error( 'invalid_transition', __( 'This application is no longer waiting on payment.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		self::void_pending_payment( $app_id, $reason );
		self::$allow_offer_return = true;
		try {
			$result = self::transition( $app_id, 'waitlisted', array( 'reason' => $reason, 'source' => 'waitlist_offer' ) );
		} finally {
			self::$allow_offer_return = false;
		}
		if ( true === $result ) {
			delete_post_meta( $app_id, '_lnkup_payment_due_at' );
		}
		return $result;
	}

	/**
	 * Statuses a vendor may withdraw from. Paid bookings need a refund instead.
	 */
	const WITHDRAWABLE = array( 'submitted', 'under_review', 'info_requested', 'waitlisted', 'approved', 'payment_required' );

	/**
	 * Vendor withdraws their own unpaid application.
	 *
	 * @param int $app_id  Application ID.
	 * @param int $user_id Acting vendor.
	 * @return true|WP_Error
	 */
	public static function withdraw( $app_id, $user_id ) {
		$app_id  = absint( $app_id );
		$user_id = absint( $user_id );
		if ( 'lnkup_application' !== get_post_type( $app_id ) || ! LNKup_Capabilities::vendor_owns_application( $app_id, $user_id ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You cannot change this application.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$status = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		if ( ! in_array( $status, self::WITHDRAWABLE, true ) ) {
			return new WP_Error( 'lnkup_cannot_withdraw', __( 'This application can no longer be withdrawn. Contact the organizer about a refund.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$payment = class_exists( 'LNKup_Payments' ) ? LNKup_Payments::get_for_application( $app_id ) : null;
		if ( $payment && in_array( (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true ), array( 'processing', 'paid', 'partially_paid' ), true ) ) {
			return new WP_Error( 'lnkup_cannot_withdraw', __( 'A payment is already in progress for this application.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		update_post_meta( $app_id, '_lnkup_cancellation_reason', 'withdrawn' );
		$result = self::transition( $app_id, 'cancelled', array( 'reason' => 'withdrawn' ) );
		if ( is_wp_error( $result ) ) {
			delete_post_meta( $app_id, '_lnkup_cancellation_reason' );
			return $result;
		}
		update_post_meta( $app_id, '_lnkup_withdrawn_at', current_time( 'mysql' ) );
		do_action( 'lnkup_application_withdrawn', $app_id, $status );
		return true;
	}

	/**
	 * Stop an unpaid payment from being collected after its application leaves the booking flow.
	 * Paid payments are never touched here — refunds go through the Financial Service.
	 *
	 * @param int    $app_id Application ID.
	 * @param string $reason Audit reason.
	 */
	public static function void_pending_payment( $app_id, $reason ) {
		$payment = LNKup_Payments::get_for_application( $app_id );
		if ( ! $payment ) {
			return;
		}
		$pay_status = (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true );
		$provider   = LNKup_Payment_Provider::get_instance();
		if ( 'processing' === $pay_status ) {
			// Bank payments in flight cannot be voided here; if the money lands it is flagged for
			// admin review instead of rebooking the vendor (B-7).
			update_post_meta( $payment->ID, '_lnkup_closed_while_processing', sanitize_key( $reason ) );
			if ( $provider instanceof LNKup_Stripe_Provider ) {
				$provider->cancel_open_charges( $payment->ID );
			}
			LNKup_Financial_Audit::log( 'payment_closed_while_processing', 'payment', $payment->ID, array( 'reason' => sanitize_key( $reason ), 'application_id' => (int) $app_id ) );
			return;
		}
		if ( ! in_array( $pay_status, array( 'pending', 'failed' ), true ) || '' !== (string) get_post_meta( $payment->ID, '_lnkup_voided_at', true ) ) {
			return;
		}
		update_post_meta( $payment->ID, '_lnkup_payment_status', 'failed' );
		update_post_meta( $payment->ID, '_lnkup_voided_at', current_time( 'mysql' ) );
		update_post_meta( $payment->ID, '_lnkup_voided_reason', sanitize_key( $reason ) );
		// Close the payment link too, so an already-open checkout cannot still be paid.
		if ( $provider instanceof LNKup_Stripe_Provider ) {
			$provider->cancel_open_charges( $payment->ID );
		}
		if ( class_exists( 'LNKup_Credits' ) && method_exists( 'LNKup_Credits', 'restore_for_payment' ) ) {
			LNKup_Credits::restore_for_payment( $payment->ID, 'payment_voided' );
		}
		LNKup_Invoices::mark_void_for_application( $app_id, $reason );
		if ( class_exists( 'LNKup_Financial_Audit' ) ) {
			LNKup_Financial_Audit::log( 'payment_voided', 'payment', $payment->ID, array( 'reason' => sanitize_key( $reason ), 'application_id' => (int) $app_id ) );
		}
	}

	const HISTORY_META = '_lnkup_status_history';

	/**
	 * Append one status change to the application's timeline (capped at 100 entries).
	 *
	 * @param int    $app_id Application ID.
	 * @param string $from   Previous status.
	 * @param string $to     New status.
	 * @param array  $extra  Transition extras (reason, source).
	 */
	public static function record_history( $app_id, $from, $to, $extra = array() ) {
		if ( (string) $from === (string) $to ) {
			return;
		}
		$history   = get_post_meta( $app_id, self::HISTORY_META, true );
		$history   = is_array( $history ) ? $history : array();
		$history[] = array(
			'from'   => (string) $from,
			'to'     => (string) $to,
			'at'     => current_time( 'mysql' ),
			'by'     => (int) get_current_user_id(),
			'reason' => isset( $extra['reason'] ) ? sanitize_key( (string) $extra['reason'] ) : '',
			'source' => isset( $extra['source'] ) ? sanitize_key( (string) $extra['source'] ) : '',
		);
		update_post_meta( $app_id, self::HISTORY_META, array_slice( $history, -100 ) );
	}

	/**
	 * Status timeline, oldest first.
	 *
	 * @param int $app_id Application ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function status_history( $app_id ) {
		$history = get_post_meta( absint( $app_id ), self::HISTORY_META, true );
		return is_array( $history ) ? array_values( $history ) : array();
	}

	/**
	 * Source statuses each target status may be reached from.
	 *
	 * `paid` is reachable from open states because it reflects money already collected and
	 * confirmed by the Financial Service; it never moves backwards. It is NOT reachable from
	 * `rejected` or `cancelled`: money landing after a booking closed is flagged for admin review
	 * instead of rebooking the vendor without an inventory check (B-7).
	 */
	const TRANSITIONS = array(
		'under_review'     => array( '', 'submitted', 'waitlisted', 'rejected', 'info_requested' ),
		'info_requested'   => array( 'submitted', 'under_review' ),
		'approved'         => array( '', 'submitted', 'under_review', 'waitlisted', 'info_requested' ),
		'waitlisted'       => array( '', 'submitted', 'under_review', 'info_requested' ),
		'rejected'         => array( '', 'submitted', 'under_review', 'waitlisted', 'approved', 'payment_required', 'info_requested' ),
		'payment_required' => array( 'approved' ),
		'paid'             => array( '', 'submitted', 'under_review', 'waitlisted', 'approved', 'payment_required' ),
		'completed'        => array( 'paid' ),
		'cancelled'        => array( '', 'draft', 'submitted', 'under_review', 'waitlisted', 'approved', 'payment_required', 'paid', 'info_requested' ),
		'submitted'        => array( '', 'draft' ),
	);

	const INFO_HISTORY_META = '_lnkup_info_requests';

	/**
	 * Organizer asks the vendor for more information before deciding.
	 *
	 * @param int      $app_id       Application ID.
	 * @param string   $message      What the organizer needs.
	 * @param string[] $doc_types    Optional document types to upload.
	 * @param int      $organizer_id Acting organizer.
	 * @return array|WP_Error History entry.
	 */
	public static function request_info( $app_id, $message, $doc_types, $organizer_id ) {
		$app_id = absint( $app_id );
		if ( 'lnkup_application' !== get_post_type( $app_id ) ) {
			return new WP_Error( 'not_found', __( 'Application not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! LNKup_Capabilities::user_owns_post( $app_id, $organizer_id, '_lnkup_organizer_user_id' ) && ! LNKup_Capabilities::user_has_manage_all( $organizer_id )
			&& ! self::team_can_manage( $app_id, $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$message = trim( sanitize_textarea_field( (string) $message ) );
		if ( mb_strlen( $message ) < 3 ) {
			return new WP_Error( 'message_required', __( 'Tell the vendor what you need.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$docs   = array_values( array_filter( array_map( 'sanitize_text_field', (array) $doc_types ) ) );
		$result = self::transition( $app_id, 'info_requested' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$entry = self::append_info_history(
			$app_id,
			array(
				'type'      => 'request',
				'message'   => mb_substr( $message, 0, 2000 ),
				'documents' => array_slice( $docs, 0, 10 ),
				'by'        => (int) $organizer_id,
			)
		);
		LNKup_Notifications::send_info_requested( $app_id, $entry );
		LNKup_Organizer_CRM::log_activity(
			'info_requested',
			(int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true ),
			(int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ),
			array( 'event_id' => (int) get_post_meta( $app_id, '_lnkup_event_id', true ), 'application_id' => $app_id )
		);
		return $entry;
	}

	/**
	 * Vendor answers an information request; the application returns to review.
	 *
	 * @param int    $app_id    Application ID.
	 * @param string $message   Vendor reply.
	 * @param int    $vendor_id Acting vendor.
	 * @return array|WP_Error History entry.
	 */
	public static function respond_info( $app_id, $message, $vendor_id ) {
		$app_id = absint( $app_id );
		if ( 'lnkup_application' !== get_post_type( $app_id ) || (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ) !== (int) $vendor_id ) {
			return new WP_Error( 'not_found', __( 'Application not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( 'info_requested' !== (string) get_post_meta( $app_id, '_lnkup_application_status', true ) ) {
			return new WP_Error( 'no_open_request', __( 'The organizer is not waiting on more information for this request.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$message = trim( sanitize_textarea_field( (string) $message ) );
		if ( mb_strlen( $message ) < 2 ) {
			return new WP_Error( 'message_required', __( 'Add a reply for the organizer.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$result = self::transition( $app_id, 'under_review' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$entry = self::append_info_history(
			$app_id,
			array(
				'type'    => 'response',
				'message' => mb_substr( $message, 0, 4000 ),
				'by'      => (int) $vendor_id,
			)
		);
		LNKup_Notifications::send_info_response( $app_id, $entry );
		LNKup_Organizer_CRM::log_activity(
			'info_responded',
			(int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true ),
			(int) $vendor_id,
			array( 'event_id' => (int) get_post_meta( $app_id, '_lnkup_event_id', true ), 'application_id' => $app_id, 'actor_user_id' => (int) $vendor_id )
		);
		return $entry;
	}

	/**
	 * Request/response thread for an application, oldest first.
	 *
	 * @param int $app_id Application ID.
	 * @return array<int, array{type:string,message:string,documents?:string[],by:int,at:string}>
	 */
	public static function info_history( $app_id ) {
		$history = get_post_meta( absint( $app_id ), self::INFO_HISTORY_META, true );
		return is_array( $history ) ? array_values( $history ) : array();
	}

	/**
	 * Latest unanswered organizer request, if the application is waiting on the vendor.
	 *
	 * @param int $app_id Application ID.
	 * @return array|null
	 */
	public static function open_info_request( $app_id ) {
		if ( 'info_requested' !== (string) get_post_meta( absint( $app_id ), '_lnkup_application_status', true ) ) {
			return null;
		}
		foreach ( array_reverse( self::info_history( $app_id ) ) as $entry ) {
			if ( 'request' === ( $entry['type'] ?? '' ) ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * @param int   $app_id Application ID.
	 * @param array $entry  Entry without timestamp.
	 */
	private static function append_info_history( $app_id, $entry ) {
		$entry['at'] = current_time( 'mysql' );
		$history     = self::info_history( $app_id );
		$history[]   = $entry;
		update_post_meta( $app_id, self::INFO_HISTORY_META, array_slice( $history, -50 ) );
		return $entry;
	}

	/**
	 * Whether an application may move between two statuses.
	 *
	 * @param string $from Current status ('' for legacy records with no status).
	 * @param string $to   Target status.
	 */
	public static function can_transition( $from, $to ) {
		if ( 'paid' === $to && 'paid' === $from ) {
			return true;
		}
		$allowed = self::TRANSITIONS[ $to ] ?? array();
		return in_array( (string) $from, $allowed, true );
	}

	/**
	 * Organizer approve / reject / waitlist / review.
	 *
	 * @param int   $app_id  Application ID.
	 * @param string $action Action key.
	 * @param int   $user_id Acting organizer user ID.
	 * @param array $extra   Optional extras (e.g. rejection_reason from REST roster bulk).
	 */
	/**
	 * Organization team member with manage_applications on the application's event.
	 *
	 * @param int $app_id  Application ID.
	 * @param int $user_id User ID.
	 */
	private static function team_can_manage( $app_id, $user_id ) {
		if ( ! class_exists( 'LNKup_Org_Teams' ) ) {
			return false;
		}
		$event_id = (int) get_post_meta( absint( $app_id ), '_lnkup_event_id', true );
		return $event_id && LNKup_Org_Teams::can( absint( $user_id ), $event_id, 'manage_applications' );
	}

	public static function handle_organizer_action( $app_id, $action, $user_id, $extra = array() ) {
		if ( ! LNKup_Capabilities::user_owns_post( $app_id, $user_id, '_lnkup_organizer_user_id' ) && ! LNKup_Capabilities::user_has_manage_all( $user_id )
			&& ! self::team_can_manage( $app_id, $user_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ) );
		}

		$map = array(
			'approve'   => 'approved',
			'reject'    => 'rejected',
			'waitlist'  => 'waitlisted',
			'review'    => 'under_review',
		);

		$payload = is_array( $extra ) ? $extra : array();
		if ( 'allow_reapply' === $action ) {
			return self::allow_reapply( $app_id, $user_id );
		}
		if ( 'request_info' === $action ) {
			$entry = self::request_info( $app_id, (string) ( $payload['info_message'] ?? '' ), (array) ( $payload['info_documents'] ?? array() ), $user_id );
			return is_wp_error( $entry ) ? $entry : true;
		}

		if ( empty( $map[ $action ] ) ) {
			return new WP_Error( 'invalid', __( 'Invalid action.', 'lnkup-marketplace' ) );
		}

		if ( 'reject' === $action ) {
			if ( ! empty( $payload['rejection_reason'] ) ) {
				$payload['reason'] = sanitize_textarea_field( (string) $payload['rejection_reason'] );
			} elseif ( ! empty( $_POST['rejection_reason'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$payload['reason'] = sanitize_textarea_field( wp_unslash( $_POST['rejection_reason'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}

		return self::transition( $app_id, $map[ $action ], $payload );
	}
}
