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
	public static function build_apply_context( $opportunity_id, $user_id = 0 ) {
		$opportunity_id = absint( $opportunity_id );
		$user_id        = $user_id ?: (int) get_current_user_id();

		$opp = get_post( $opportunity_id );
		if ( ! $opp || 'lnkup_opportunity' !== $opp->post_type ) {
			return new WP_Error( 'invalid', __( 'Invalid opportunity.', 'lnkup-marketplace' ) );
		}

		$card       = lnkup_get_opportunity_card_data( $opportunity_id );
		$opp_terms  = wp_get_post_terms( $opportunity_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
		if ( is_wp_error( $opp_terms ) ) {
			$opp_terms = array();
		}

		$required_docs = lnkup_get_opportunity_required_documents( $opportunity_id );
		$doc_labels    = LNKup_Documents::document_types();

		$eligibility = self::evaluate_eligibility( $opportunity_id, $user_id, $card );

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

			$vendor_cats = is_array( $profile['category'] ?? null ) ? $profile['category'] : array();
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
		$vendor_user_id = absint( $vendor_user_id );
		$opportunity_id = absint( $opportunity_id );

		if ( ! $vendor_user_id || ! is_user_logged_in() ) {
			return new WP_Error( 'not_logged_in', __( 'Please log in to apply.', 'lnkup-marketplace' ) );
		}

		if ( ! LNKup_Roles::is_vendor( $vendor_user_id ) && ! LNKup_Capabilities::user_has_manage_all( $vendor_user_id ) ) {
			return new WP_Error( 'not_vendor', __( 'Vendor access required to apply.', 'lnkup-marketplace' ) );
		}

		$card        = lnkup_get_opportunity_card_data( $opportunity_id );
		$eligibility = self::evaluate_eligibility( $opportunity_id, $vendor_user_id, $card );

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
		$context    = self::build_apply_context( $opportunity_id, $vendor_user_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$allowed_cats = (array) ( $context['categories'] ?? array() );
		if ( $allowed_cats && ! in_array( $category, $allowed_cats, true ) ) {
			return new WP_Error( 'invalid_category', __( 'Please select a valid category for this opportunity.', 'lnkup-marketplace' ) );
		}

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
		update_post_meta( $app_id, '_lnkup_profile_snapshot', wp_json_encode( $profile_dto ) );
		update_post_meta( $app_id, '_lnkup_documents_snapshot', wp_json_encode( $documents ) );
		update_post_meta( $app_id, '_lnkup_documents_missing', $docs_missing );

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
	 * @return array<string, mixed>
	 */
	private static function evaluate_eligibility( $opportunity_id, $user_id, $card = null ) {
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

		$existing = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => 1,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => '_lnkup_opportunity_id', 'value' => $opportunity_id ),
					array( 'key' => '_lnkup_vendor_user_id', 'value' => $user_id ),
				),
				'post_status'    => 'any',
			)
		);
		if ( $existing ) {
			$result['block_reason']                = 'duplicate';
			$result['existing_application_id']     = (int) $existing[0]->ID;
			$result['existing_application_status'] = (string) get_post_meta( $existing[0]->ID, '_lnkup_application_status', true );
			return $result;
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

		if ( ! $card ) {
			$card = lnkup_get_opportunity_card_data( $opportunity_id );
		}

		if ( empty( $card['is_open'] ) ) {
			$deadline = $card['deadline'] ?? '';
			if ( $deadline && strtotime( $deadline ) < strtotime( 'today' ) ) {
				$result['block_reason'] = 'deadline';
			} elseif ( (int) ( $card['spots_remaining'] ?? 0 ) <= 0 ) {
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

		$docs    = LNKup_Documents::get_for_vendor( $user_id );
		$by_type = array();
		foreach ( $docs as $doc ) {
			$type = get_post_meta( $doc->ID, '_lnkup_document_type', true );
			if ( ! isset( $by_type[ $type ] ) ) {
				$by_type[ $type ] = $doc;
			}
		}

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
				'type'   => $type,
				'label'  => $label,
				'status' => $status,
				'doc_id' => $doc ? (int) $doc->ID : 0,
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
				'code'    => 'profile_incomplete',
				'message' => lnkup_copy( 'apply_profile_warning' ),
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

		if ( $opp_cats && $vendor_cats && ! array_intersect( $vendor_cats, $opp_cats ) ) {
			$warnings[] = array(
				'code'    => 'category_mismatch',
				'message' => __( 'Your profile category may not match what this event is seeking.', 'lnkup-marketplace' ),
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
			case 'deadline':
				return __( 'The application deadline for this opportunity has passed.', 'lnkup-marketplace' );
			case 'full':
				return __( 'No spots remaining for this opportunity.', 'lnkup-marketplace' );
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
		$status = LNKup_Security::sanitize_application_status( $status );
		$prev   = get_post_meta( $app_id, '_lnkup_application_status', true );

		update_post_meta( $app_id, '_lnkup_application_status', $status );

		if ( 'approved' === $status ) {
			update_post_meta( $app_id, '_lnkup_approved_at', current_time( 'mysql' ) );
			LNKup_Payments::create_from_application( $app_id );
			update_post_meta( $app_id, '_lnkup_application_status', 'payment_required' );
			update_post_meta( $app_id, '_lnkup_payment_due_at', gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ) );
			LNKup_Notifications::send_application_approved( $app_id );
		} elseif ( 'rejected' === $status ) {
			if ( ! empty( $extra['reason'] ) ) {
				update_post_meta( $app_id, '_lnkup_rejection_reason', sanitize_textarea_field( $extra['reason'] ) );
			}
			LNKup_Notifications::send_application_rejected( $app_id );
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
	 * Allowed transitions for organizer actions.
	 *
	 * @param string $action Action.
	 */
	public static function handle_organizer_action( $app_id, $action, $user_id ) {
		if ( ! LNKup_Capabilities::user_owns_post( $app_id, $user_id, '_lnkup_organizer_user_id' ) && ! LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ) );
		}

		$map = array(
			'approve'   => 'approved',
			'reject'    => 'rejected',
			'waitlist'  => 'waitlisted',
			'review'    => 'under_review',
		);

		if ( empty( $map[ $action ] ) ) {
			return new WP_Error( 'invalid', __( 'Invalid action.', 'lnkup-marketplace' ) );
		}

		$extra = array();
		if ( 'reject' === $action && ! empty( $_POST['rejection_reason'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$extra['reason'] = sanitize_textarea_field( wp_unslash( $_POST['rejection_reason'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		return self::transition( $app_id, $map[ $action ], $extra );
	}
}
