<?php
/**
 * Organizer-defined application questions per opportunity, and answer validation.
 *
 * Definitions live on the opportunity (`_lnkup_application_questions`, JSON list). Answers are
 * stored on the submitted application (`_lnkup_application_answers`) as a frozen snapshot that
 * includes the question label, so later edits to the questions never rewrite reviewed answers.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Application_Questions {

	const META          = '_lnkup_application_questions';
	const DOCS_GATE     = '_lnkup_documents_required_to_apply';
	const TYPES         = array( 'text', 'textarea', 'select', 'checkbox', 'file' );
	const MAX_QUESTIONS = 20;
	const MAX_OPTIONS   = 20;
	const MAX_ANSWER    = 4000;

	/**
	 * Normalized questions for an opportunity.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 * @return array<int, array{id:string,label:string,type:string,required:bool,options:string[],help:string}>
	 */
	public static function get_for_opportunity( $opportunity_id ) {
		$raw  = get_post_meta( absint( $opportunity_id ), self::META, true );
		$list = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( ! is_array( $list ) ) {
			return array();
		}
		$clean = self::sanitize_definitions( $list );
		return is_wp_error( $clean ) ? array() : $clean;
	}

	/**
	 * Validate and normalize organizer question definitions.
	 *
	 * @param array $raw Raw list.
	 * @return array|WP_Error
	 */
	public static function sanitize_definitions( array $raw ) {
		if ( count( $raw ) > self::MAX_QUESTIONS ) {
			return new WP_Error( 'lnkup_too_many_questions', sprintf( __( 'Add up to %d questions.', 'lnkup-marketplace' ), self::MAX_QUESTIONS ), array( 'status' => 400 ) );
		}
		$out  = array();
		$seen = array();
		foreach ( array_values( $raw ) as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label = trim( sanitize_text_field( (string) ( $item['label'] ?? '' ) ) );
			if ( '' === $label ) {
				continue;
			}
			$type = sanitize_key( (string) ( $item['type'] ?? 'text' ) );
			if ( ! in_array( $type, self::TYPES, true ) ) {
				return new WP_Error( 'lnkup_invalid_question_type', __( 'Choose a supported question type.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			$id = sanitize_key( (string) ( $item['id'] ?? '' ) );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				$id = 'q_' . substr( md5( $label . '|' . $index ), 0, 10 );
			}
			$seen[ $id ] = true;
			$options     = array();
			if ( 'select' === $type ) {
				$options = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $item['options'] ?? array() ) ) ) ) ), 0, self::MAX_OPTIONS );
				if ( ! $options ) {
					return new WP_Error( 'lnkup_question_options_required', sprintf( __( '"%s" needs at least one choice.', 'lnkup-marketplace' ), $label ), array( 'status' => 400 ) );
				}
			}
			$out[] = array(
				'id'       => $id,
				'label'    => mb_substr( $label, 0, 200 ),
				'type'     => $type,
				'required' => ! empty( $item['required'] ),
				'options'  => $options,
				'help'     => mb_substr( sanitize_text_field( (string) ( $item['help'] ?? '' ) ), 0, 300 ),
			);
		}
		return $out;
	}

	/**
	 * Save questions for an opportunity the organizer owns.
	 *
	 * @param int   $opportunity_id Opportunity ID.
	 * @param array $raw            Raw definitions.
	 * @param int   $user_id        Acting user.
	 * @param bool  $docs_gate      Whether required documents block submission.
	 * @return array|WP_Error Saved definitions.
	 */
	public static function save_for_opportunity( $opportunity_id, array $raw, $user_id, $docs_gate = null ) {
		$opportunity_id = absint( $opportunity_id );
		if ( 'lnkup_opportunity' !== get_post_type( $opportunity_id ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Opportunity not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! self::can_manage( $opportunity_id, $user_id ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You can only edit questions on your own events.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$clean = self::sanitize_definitions( $raw );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		update_post_meta( $opportunity_id, self::META, LNKup_Meta::json_for_meta( $clean ) );
		if ( null !== $docs_gate ) {
			update_post_meta( $opportunity_id, self::DOCS_GATE, $docs_gate ? 1 : 0 );
		}
		return $clean;
	}

	/**
	 * Organizer owns the opportunity's event, or the user manages all.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 * @param int $user_id        User ID.
	 */
	public static function can_manage( $opportunity_id, $user_id ) {
		if ( LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return true;
		}
		$event_id = (int) get_post_meta( $opportunity_id, '_lnkup_event_id', true );
		return $event_id && LNKup_Capabilities::organizer_owns_event( $event_id, $user_id );
	}

	/**
	 * Validate vendor answers against the opportunity's current questions.
	 *
	 * @param int   $opportunity_id Opportunity ID.
	 * @param array $answers        Map of question id => answer.
	 * @param int   $vendor_user_id Vendor.
	 * @param bool  $strict         Enforce required answers (final submit) vs. partial (draft save).
	 * @return array|WP_Error Frozen answer snapshot list.
	 */
	public static function validate_answers( $opportunity_id, array $answers, $vendor_user_id, $strict = true ) {
		$questions = self::get_for_opportunity( $opportunity_id );
		$out       = array();
		$missing   = array();
		foreach ( $questions as $q ) {
			$raw   = $answers[ $q['id'] ] ?? null;
			$value = self::clean_answer( $q, $raw, $vendor_user_id );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			$empty = null === $value || '' === $value || array() === $value || false === $value;
			if ( $empty && $q['required'] ) {
				$missing[] = $q['id'];
			}
			if ( ! $empty ) {
				$out[] = array(
					'id'    => $q['id'],
					'label' => $q['label'],
					'type'  => $q['type'],
					'value' => $value,
				);
			}
		}
		if ( $strict && $missing ) {
			return new WP_Error(
				'lnkup_answers_required',
				__( 'Answer the required questions before submitting.', 'lnkup-marketplace' ),
				array(
					'status' => 422,
					'fields' => $missing,
				)
			);
		}
		return $out;
	}

	/**
	 * @param array $q              Question.
	 * @param mixed $raw            Raw answer.
	 * @param int   $vendor_user_id Vendor.
	 * @return mixed|WP_Error
	 */
	private static function clean_answer( array $q, $raw, $vendor_user_id ) {
		switch ( $q['type'] ) {
			case 'checkbox':
				return null === $raw ? false : rest_sanitize_boolean( $raw );
			case 'select':
				$value = sanitize_text_field( (string) $raw );
				if ( '' !== $value && ! in_array( $value, $q['options'], true ) ) {
					return new WP_Error( 'lnkup_invalid_answer', sprintf( __( 'Choose one of the listed options for "%s".', 'lnkup-marketplace' ), $q['label'] ), array( 'status' => 422, 'fields' => array( $q['id'] ) ) );
				}
				return $value;
			case 'file':
				$doc_id = absint( $raw );
				if ( ! $doc_id ) {
					return null;
				}
				if ( ! self::vendor_owns_document( $doc_id, $vendor_user_id ) ) {
					return new WP_Error( 'lnkup_forbidden_document', __( 'Attach one of your own documents.', 'lnkup-marketplace' ), array( 'status' => 403, 'fields' => array( $q['id'] ) ) );
				}
				return $doc_id;
			case 'textarea':
				return mb_substr( trim( sanitize_textarea_field( (string) $raw ) ), 0, self::MAX_ANSWER );
			default:
				return mb_substr( trim( sanitize_text_field( (string) $raw ) ), 0, 500 );
		}
	}

	/**
	 * Whether a lnkup_document belongs to the vendor.
	 *
	 * @param int $doc_id         Document post ID.
	 * @param int $vendor_user_id Vendor.
	 */
	public static function vendor_owns_document( $doc_id, $vendor_user_id ) {
		return 'lnkup_document' === get_post_type( $doc_id )
			&& (int) get_post_meta( $doc_id, '_lnkup_vendor_user_id', true ) === (int) $vendor_user_id;
	}

	/**
	 * Filter a list of document IDs down to the vendor's own documents.
	 *
	 * @param array $ids            Raw IDs.
	 * @param int   $vendor_user_id Vendor.
	 * @return int[]
	 */
	public static function owned_document_ids( array $ids, $vendor_user_id ) {
		$out = array();
		foreach ( array_slice( array_unique( array_map( 'absint', $ids ) ), 0, 20 ) as $id ) {
			if ( $id && self::vendor_owns_document( $id, $vendor_user_id ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Whether missing documents block submission for this opportunity.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 */
	public static function documents_gate( $opportunity_id ) {
		return (bool) get_post_meta( absint( $opportunity_id ), self::DOCS_GATE, true );
	}

	/**
	 * Frozen answers on a submitted application.
	 *
	 * @param int $app_id Application ID.
	 * @return array
	 */
	public static function answers_for_application( $app_id ) {
		$raw = json_decode( (string) get_post_meta( absint( $app_id ), '_lnkup_application_answers', true ), true );
		return is_array( $raw ) ? $raw : array();
	}
}
