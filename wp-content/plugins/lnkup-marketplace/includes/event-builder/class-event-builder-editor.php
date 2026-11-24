<?php
/**
 * Edit 2.0: a published event is loaded into a builder draft, reviewed, and applied through the
 * existing update services. The live event keeps showing its published values until the
 * organizer applies the changes; existing applications, payments and invoices are never edited
 * (booth price changes affect new applications only, paid bookings keep their own amounts).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Builder_Editor {

	const BOOKED_STATUSES = array( 'approved', 'payment_required', 'paid', 'completed', 'confirmed' );
	const MATERIAL        = array( 'event.date', 'event.end_date', 'event.start_time', 'event.end_time', 'event.city', 'event.state', 'event.venue_name', 'event.venue_address' );

	public static function can_edit_event( int $event_id, int $user_id ): bool {
		if ( 'lnkup_event' !== get_post_type( $event_id ) ) {
			return false;
		}
		return LNKup_Capabilities::user_owns_post( $event_id, $user_id, '_lnkup_organizer_user_id' ) || LNKup_Capabilities::user_has_manage_all( $user_id );
	}

	/**
	 * Open (or resume) an edit draft for an event.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function start( int $event_id, int $user_id ) {
		if ( ! self::can_edit_event( $event_id, $user_id ) ) {
			return new WP_Error( 'lnkup_builder_forbidden', __( 'You cannot edit this event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$open = get_posts(
			array(
				'post_type'      => LNKup_Event_Builder_Drafts::POST_TYPE,
				'post_status'    => 'draft',
				'author'         => $user_id,
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => LNKup_Event_Builder_Drafts::SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $open ) {
			return LNKup_Event_Builder_Drafts::get( (int) $open[0] );
		}
		return LNKup_Event_Builder_Drafts::create( $user_id, self::state_from_event( $event_id ), $event_id );
	}

	/**
	 * Builder state from a live event (reusable for duplication and templates).
	 *
	 * @return array<string,mixed>
	 */
	public static function state_from_event( int $event_id, string $method = 'edit' ): array {
		$m      = static fn( string $k ) => get_post_meta( $event_id, $k, true );
		$indoor = (bool) $m( '_lnkup_indoor' );
		$out    = (bool) $m( '_lnkup_outdoor' );
		$terms  = wp_get_object_terms( $event_id, 'lnkup_event_type', array( 'fields' => 'slugs' ) );
		$opps   = self::opportunities( $event_id );
		$first  = $opps ? $opps[0] : 0;
		$sources = array();
		if ( class_exists( 'LNKup_Event_Sources' ) ) {
			foreach ( LNKup_Event_Sources::for_event( $event_id ) as $s ) {
				$sources[] = array( 'url' => $s['url'], 'provider' => $s['provider'], 'primary' => (bool) $s['is_primary'], 'mode' => $s['mode'] );
			}
		}
		if ( ! $sources && '' !== (string) $m( '_lnkup_ticketing_url' ) ) {
			$sources[] = array( 'url' => (string) $m( '_lnkup_ticketing_url' ), 'provider' => (string) $m( '_lnkup_ticketing_platform' ), 'primary' => true );
		}
		$options = array();
		foreach ( $opps as $opp_id ) {
			$cats      = wp_get_object_terms( $opp_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
			$options[] = array(
				'key'            => 'opp' . $opp_id,
				'opportunity_id' => $opp_id,
				'name'           => lnkup_plain_title( $opp_id ),
				'category'       => is_array( $cats ) && $cats ? (string) $cats[0] : '',
				'fee'            => (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true ),
				'spots'          => (int) get_post_meta( $opp_id, '_lnkup_spots_total', true ),
				'deadline'       => (string) get_post_meta( $opp_id, '_lnkup_application_deadline', true ),
				'description'    => (string) get_post_field( 'post_content', $opp_id ),
				'width_ft'       => (int) get_post_meta( $opp_id, '_lnkup_booth_width_ft', true ),
				'depth_ft'       => (int) get_post_meta( $opp_id, '_lnkup_booth_depth_ft', true ),
				'amenities'      => (array) json_decode( (string) get_post_meta( $opp_id, '_lnkup_included_amenities', true ), true ),
			);
		}
		$questions = array();
		if ( $first ) {
			$back = array( 'text' => 'short', 'textarea' => 'long', 'select' => 'choice', 'file' => 'file', 'checkbox' => 'ack' );
			foreach ( LNKup_Application_Questions::get_for_opportunity( $first ) as $q ) {
				$type        = $back[ $q['type'] ] ?? 'short';
				$questions[] = array( 'id' => $q['id'], 'label' => $q['label'], 'type' => $type, 'required' => $q['required'], 'options' => $q['options'], 'help' => $q['help'], 'scope' => 'all' );
			}
		}
		$doc_mode = $first ? (string) get_post_meta( $first, '_lnkup_document_mode', true ) : 'none';
		$doc_list = $first ? array_filter( array_map( 'trim', explode( ',', (string) get_post_meta( $first, '_lnkup_required_documents', true ) ) ) ) : array();
		$gate     = $first && (bool) get_post_meta( $first, LNKup_Application_Questions::DOCS_GATE, true );
		$att_mode = (string) $m( '_lnkup_attendance_mode' );
		$expected = (int) $m( '_lnkup_expected_attendance' );
		$deposit  = $first && (bool) get_post_meta( $first, '_lnkup_deposit_required', true );
		return LNKup_Event_Builder_Schema::normalize(
			array(
				'method'       => $method,
				'step'         => 'info',
				'event'        => array(
					'name'              => html_entity_decode( (string) get_post_field( 'post_title', $event_id ), ENT_QUOTES, 'UTF-8' ),
					'event_type'        => is_array( $terms ) && $terms ? $terms[0] : '',
					'brand_id'          => (int) $m( '_lnkup_brand_id' ),
					'tour_id'           => (int) $m( '_lnkup_tour_id' ),
					'summary'           => (string) get_post_field( 'post_excerpt', $event_id ),
					'description'       => (string) get_post_field( 'post_content', $event_id ),
					'attendance'        => array(
						'mode'  => $att_mode ?: ( $expected ? 'estimate' : 'unknown' ),
						'value' => $expected,
						'min'   => (int) $m( '_lnkup_attendance_min' ),
						'max'   => (int) $m( '_lnkup_attendance_max' ),
					),
					'format'            => (string) $m( '_lnkup_event_format' ) ?: ( $indoor && $out ? 'both' : ( $indoor ? 'indoor' : 'outdoor' ) ),
					'date'              => (string) $m( '_lnkup_event_date' ),
					'end_date'          => (string) $m( '_lnkup_event_end_date' ),
					'start_time'        => substr( (string) $m( '_lnkup_start_time' ), 0, 5 ),
					'end_time'          => substr( (string) $m( '_lnkup_end_time' ), 0, 5 ),
					'timezone'          => (string) $m( '_lnkup_event_timezone' ),
					'venue_name'        => (string) $m( '_lnkup_venue_name' ),
					'venue_address'     => (string) $m( '_lnkup_venue_address' ),
					'city'              => (string) $m( '_lnkup_city' ),
					'state'             => (string) $m( '_lnkup_state' ),
					'country'           => (string) $m( '_lnkup_country' ) ?: 'US',
					'venue_hidden'      => (bool) $m( '_lnkup_venue_hidden' ),
					'market_city'       => (string) $m( '_lnkup_market_city' ),
					'location_confirmed' => true,
					'featured_image_id' => (int) $m( '_lnkup_featured_image_id' ),
					'flyer_id'          => (int) $m( '_lnkup_flyer_id' ),
					'gallery_ids'       => function_exists( 'lnkup_parse_gallery_ids' ) ? lnkup_parse_gallery_ids( $m( '_lnkup_gallery_ids' ) ) : array(),
					'power_available'   => (bool) $m( '_lnkup_power_available' ),
				),
				'sources'      => $sources,
				'options'      => $options,
				'applications' => array(
					'mode'      => $first && get_post_meta( $first, '_lnkup_invite_only', true ) ? 'invite' : 'review',
					'questions' => $questions,
					'documents' => array(
						'mode'  => in_array( $doc_mode, array( 'none', 'platform_defaults', 'custom' ), true ) ? $doc_mode : 'none',
						'items' => array_map( static fn( $d ) => array( 'key' => $d, 'timing' => $gate ? 'before_approval' : 'before_event' ), array_diff( $doc_list, array( 'none' ) ) ),
					),
				),
				'payments'     => array(
					'deposit'           => array(
						'enabled' => $deposit,
						'type'    => $first ? ( (string) get_post_meta( $first, '_lnkup_deposit_type', true ) ?: 'percent' ) : 'percent',
						'value'   => $first ? (float) ( get_post_meta( $first, '_lnkup_deposit_percent', true ) ?: get_post_meta( $first, '_lnkup_deposit_amount', true ) ) : 0,
					),
					'cancellation_note' => (string) $m( '_lnkup_cancellation_policy_note' ),
				),
				'publish'      => array( 'attest' => true ),
			)
		);
	}

	/**
	 * @return int[]
	 */
	public static function opportunities( int $event_id ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'lnkup_opportunity',
					'post_status'    => 'publish',
					'posts_per_page' => 60,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery
					'no_found_rows'  => true,
				)
			)
		);
	}

	/**
	 * Booked vendors (approved or paying) on an event.
	 */
	public static function booked_count( int $event_id ): int {
		$opps = self::opportunities( $event_id );
		if ( ! $opps ) {
			return 0;
		}
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => array( 'publish', 'private', 'pending' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => '_lnkup_opportunity_id', 'value' => $opps, 'compare' => 'IN' ),
					array( 'key' => '_lnkup_application_status', 'value' => self::BOOKED_STATUSES, 'compare' => 'IN' ),
				),
			)
		);
		return count( $apps );
	}

	/**
	 * Differences between the edit draft and the live event.
	 *
	 * @return array{changes:array<int,array<string,mixed>>,material:bool,booked:int,pricing:bool}
	 */
	public static function review( int $draft_id ): array {
		$draft = LNKup_Event_Builder_Drafts::get( $draft_id );
		if ( ! $draft || ! $draft['source_event'] ) {
			return array( 'changes' => array(), 'material' => false, 'booked' => 0, 'pricing' => false );
		}
		$live    = self::state_from_event( $draft['source_event'] );
		$changes = array();
		foreach ( array( 'name', 'date', 'end_date', 'start_time', 'end_time', 'city', 'state', 'venue_name', 'venue_address', 'description', 'event_type', 'format' ) as $f ) {
			$before = (string) $live['event'][ $f ];
			$after  = (string) $draft['state']['event'][ $f ];
			if ( $before !== $after ) {
				$changes[] = array( 'field' => 'event.' . $f, 'before' => $before, 'after' => $after, 'material' => in_array( 'event.' . $f, self::MATERIAL, true ) );
			}
		}
		$pricing  = false;
		$live_opp = array();
		foreach ( $live['options'] as $o ) {
			$live_opp[ $o['opportunity_id'] ] = $o;
		}
		foreach ( $draft['state']['options'] as $o ) {
			if ( ! $o['opportunity_id'] ) {
				$changes[] = array( 'field' => 'option.new', 'before' => '', 'after' => $o['name'] ?: $o['category'], 'material' => false );
				continue;
			}
			$was = $live_opp[ $o['opportunity_id'] ] ?? null;
			unset( $live_opp[ $o['opportunity_id'] ] );
			if ( $was && ( (float) $was['fee'] !== (float) $o['fee'] || (int) $was['spots'] !== (int) $o['spots'] ) ) {
				$pricing   = $pricing || (float) $was['fee'] !== (float) $o['fee'];
				$changes[] = array( 'field' => 'option.' . $o['opportunity_id'], 'before' => sprintf( '$%s · %d', $was['fee'], $was['spots'] ), 'after' => sprintf( '$%s · %d', $o['fee'], $o['spots'] ), 'material' => (float) $was['fee'] !== (float) $o['fee'] );
			}
		}
		foreach ( $live_opp as $removed ) {
			$changes[] = array( 'field' => 'option.removed', 'before' => $removed['name'], 'after' => '', 'material' => true );
		}
		$booked = self::booked_count( $draft['source_event'] );
		return array(
			'changes'  => $changes,
			'material' => (bool) array_filter( $changes, static fn( $c ) => $c['material'] ),
			'booked'   => $booked,
			'pricing'  => $pricing,
		);
	}

	/**
	 * Apply an edit draft to its event.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function apply( int $draft_id, int $user_id, bool $confirmed = false ) {
		$draft = LNKup_Event_Builder_Drafts::get( $draft_id );
		if ( ! $draft || ! $draft['source_event'] ) {
			return new WP_Error( 'lnkup_builder_missing', __( 'This edit no longer exists.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$event_id = $draft['source_event'];
		if ( ! self::can_edit_event( $event_id, $user_id ) ) {
			return new WP_Error( 'lnkup_builder_forbidden', __( 'You cannot edit this event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$report = LNKup_Event_Builder_Validator::report( $draft['state'], $user_id );
		$blocks = array_values( array_filter( $report['checks'], static fn( $c ) => 'required' === $c['level'] && ! $c['ok'] && 'date_future' !== $c['key'] ) );
		if ( $blocks ) {
			return new WP_Error( 'lnkup_builder_not_ready', __( 'Fix the highlighted items before saving.', 'lnkup-marketplace' ), array( 'status' => 422, 'checks' => $blocks ) );
		}
		$review = self::review( $draft_id );
		if ( $review['material'] && $review['booked'] > 0 && ! $confirmed ) {
			return new WP_Error( 'lnkup_builder_confirm', __( 'Review the changes that affect booked vendors before saving.', 'lnkup-marketplace' ), array( 'status' => 409, 'review' => $review ) );
		}
		$state = LNKup_Event_Builder_Publisher::own_images( $draft['state'], $user_id );
		$input = LNKup_Event_Builder_Publisher::event_input( $state, $draft_id );
		unset( $input['client_token'] );
		$input['environment'] = $state['event']['format'];
		$result = LNKup_Organizer_Onboarding::update_event_from_request( $event_id, $input, $user_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$errors  = array();
		$current = self::opportunities( $event_id );
		$kept    = array();
		foreach ( $state['options'] as $o ) {
			if ( $o['opportunity_id'] && in_array( $o['opportunity_id'], $current, true ) ) {
				$kept[] = $o['opportunity_id'];
				$res    = LNKup_Organizer_Onboarding::update_opportunity_pricing( $o['opportunity_id'], $o['fee'], $o['spots'], $user_id, $o['name'] );
				if ( is_wp_error( $res ) ) {
					$errors[] = $res->get_error_message();
					continue;
				}
				if ( '' !== $o['deadline'] ) {
					update_post_meta( $o['opportunity_id'], '_lnkup_application_deadline', $o['deadline'] );
				}
				$questions = LNKup_Event_Builder_Publisher::questions_for( $state['applications']['questions'], $o );
				LNKup_Application_Questions::save_for_opportunity( $o['opportunity_id'], $questions, $user_id );
				continue;
			}
			if ( ! $o['opportunity_id'] ) {
				$created = LNKup_Organizer_Onboarding::create_opportunities_for_event(
					$event_id,
					array_merge( LNKup_Event_Builder_Publisher::opportunity_input( array_merge( $state, array( 'options' => array( $o ) ) ), $draft_id ), array( 'client_token' => 'builder-edit-' . $draft_id . '-' . $o['key'] ) ),
					$user_id
				);
				if ( is_wp_error( $created ) ) {
					$errors[] = $created->get_error_message();
				}
			}
		}
		foreach ( array_diff( $current, $kept ) as $removed ) {
			$res = LNKup_Organizer_Onboarding::delete_opportunity_for_event( (int) $removed, $user_id );
			if ( is_wp_error( $res ) ) {
				// Slots with applications are closed instead of removed.
				LNKup_Organizer_Onboarding::set_opportunity_listing_status( (int) $removed, 'closed', $user_id );
				$errors[] = $res->get_error_message();
			}
		}
		if ( class_exists( 'LNKup_Event_Sources' ) ) {
			LNKup_Event_Sources::attach_from_state( $event_id, $draft_id, $state, $user_id );
		}
		LNKup_Event_Builder_Drafts::mark_published( $draft_id, $event_id );
		$out             = LNKup_Event_Builder_Publisher::response( $event_id, false );
		$out['edited']   = true;
		$out['message']  = (string) ( $result['message'] ?? __( 'Event updated.', 'lnkup-marketplace' ) );
		$out['warnings'] = $errors;
		return $out;
	}
}
