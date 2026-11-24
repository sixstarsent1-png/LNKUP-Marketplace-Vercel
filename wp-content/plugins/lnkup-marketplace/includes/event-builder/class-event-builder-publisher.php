<?php
/**
 * Publishes a builder draft through the existing event + opportunity services.
 *
 * LNKup_Organizer_Onboarding keeps doing the real work (location validation, idempotency lock,
 * geocoding, marketplace index, operations bootstrap). The draft ID is the client token, so a
 * retried publish returns the same event instead of a second one.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Builder_Publisher {

	const SCHEDULE_HOOK = 'lnkup_builder_publish_scheduled';
	const SCHEDULE_META = '_lnkup_builder_scheduled_at';

	public static function init(): void {
		add_action( self::SCHEDULE_HOOK, array( __CLASS__, 'run_scheduled' ), 10, 1 );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function publish( int $draft_id, int $user_id, bool $from_schedule = false, bool $confirm = false ) {
		$published = (int) get_post_meta( $draft_id, LNKup_Event_Builder_Drafts::EVENT_META, true );
		if ( $published && 'lnkup_event' === get_post_type( $published ) ) {
			return self::response( $published, true );
		}
		$draft = LNKup_Event_Builder_Drafts::get( $draft_id );
		if ( ! $draft ) {
			return new WP_Error( 'lnkup_builder_missing', __( 'This draft no longer exists.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( $draft['source_event'] ) {
			return LNKup_Event_Builder_Editor::apply( $draft_id, $user_id, $confirm );
		}
		if ( class_exists( 'LNKup_Event_Builder_Portfolio' ) && LNKup_Event_Builder_Portfolio::is_portfolio( $draft['state'] ) ) {
			return LNKup_Event_Builder_Portfolio::publish( $draft_id, $user_id );
		}
		$state  = self::own_images( $draft['state'], $user_id );
		$report = LNKup_Event_Builder_Validator::report( $state, $user_id );
		if ( ! $report['publishable'] ) {
			return new WP_Error( 'lnkup_builder_not_ready', __( 'Finish the required items before publishing.', 'lnkup-marketplace' ), array( 'status' => 422, 'checks' => array_values( array_filter( $report['checks'], static fn( $c ) => 'required' === $c['level'] && ! $c['ok'] ) ) ) );
		}
		if ( ! $from_schedule && 'schedule' === $state['publish']['when'] ) {
			return self::schedule( $draft_id, $state['publish']['at'] );
		}

		$previous = get_current_user_id();
		wp_set_current_user( $user_id );
		try {
			$event = LNKup_Organizer_Onboarding::create_event_from_request( self::event_input( $state, $draft_id ) );
			if ( is_wp_error( $event ) ) {
				return $event;
			}
			$event_id = (int) $event['event_id'];
			self::apply_event_extras( $event_id, $state, $draft_id );

			$created = LNKup_Organizer_Onboarding::create_opportunities_for_event( $event_id, self::opportunity_input( $state, $draft_id ), $user_id );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$ids = array_map( 'intval', (array) ( $created['opportunity_ids'] ?? array() ) );
			self::apply_opportunity_extras( $ids, $state, $user_id );

			if ( class_exists( 'LNKup_Event_Sources' ) ) {
				LNKup_Event_Sources::attach_from_state( $event_id, $draft_id, $state, $user_id );
			}
			LNKup_Event_Builder_Drafts::mark_published( $draft_id, $event_id );
			self::track( 'event_published', $state, $event_id, $user_id );
			do_action( 'lnkup_builder_event_published', $event_id, $state, $user_id );
			return self::response( $event_id, false );
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * Only images the organizer uploaded (or already used on their events) can be attached.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public static function own_images( array $state, int $user_id ): array {
		$ok = static function ( int $id ) use ( $user_id ): bool {
			if ( ! $id || ! wp_attachment_is_image( $id ) ) {
				return false;
			}
			return (int) get_post_field( 'post_author', $id ) === $user_id
				|| (int) get_post_meta( $id, '_lnkup_uploaded_by', true ) === $user_id
				|| (int) get_post_meta( (int) wp_get_post_parent_id( $id ), '_lnkup_organizer_user_id', true ) === $user_id
				|| LNKup_Capabilities::user_has_manage_all( $user_id );
		};
		$ev                      = &$state['event'];
		$ev['featured_image_id'] = $ok( (int) $ev['featured_image_id'] ) ? (int) $ev['featured_image_id'] : 0;
		$ev['flyer_id']          = $ok( (int) $ev['flyer_id'] ) ? (int) $ev['flyer_id'] : 0;
		$ev['gallery_ids']       = array_values( array_filter( $ev['gallery_ids'], static fn( $i ) => $ok( (int) $i ) ) );
		unset( $ev );
		return $state;
	}

	/**
	 * Keep the draft private until the chosen time, then publish it from cron.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private static function schedule( int $draft_id, string $at ) {
		$ts = strtotime( get_gmt_from_date( str_replace( 'T', ' ', $at ) . ':00' ) . ' UTC' );
		if ( ! $ts || $ts <= time() ) {
			return new WP_Error( 'lnkup_builder_schedule', __( 'Choose a publish time in the future.', 'lnkup-marketplace' ), array( 'status' => 422 ) );
		}
		wp_clear_scheduled_hook( self::SCHEDULE_HOOK, array( $draft_id ) );
		wp_schedule_single_event( $ts, self::SCHEDULE_HOOK, array( $draft_id ) );
		update_post_meta( $draft_id, self::SCHEDULE_META, $ts );
		return array( 'scheduled' => true, 'draft_id' => $draft_id, 'publish_at' => gmdate( 'c', $ts ) );
	}

	/**
	 * @param int $draft_id Draft.
	 */
	public static function run_scheduled( $draft_id ): void {
		$draft_id = (int) $draft_id;
		$post     = get_post( $draft_id );
		if ( ! $post || LNKup_Event_Builder_Drafts::POST_TYPE !== $post->post_type ) {
			return;
		}
		$result = self::publish( $draft_id, (int) $post->post_author, true );
		if ( is_wp_error( $result ) ) {
			update_post_meta( $draft_id, '_lnkup_builder_schedule_error', $result->get_error_message() );
		}
		delete_post_meta( $draft_id, self::SCHEDULE_META );
	}

	/**
	 * Builder state → create_event_from_request input.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public static function event_input( array $state, int $draft_id ): array {
		$ev      = $state['event'];
		$primary = '';
		foreach ( $state['sources'] as $s ) {
			if ( $s['primary'] ) {
				$primary = $s['url'];
			}
		}
		$attendance = 'estimate' === $ev['attendance']['mode'] ? $ev['attendance']['value'] : ( 'range' === $ev['attendance']['mode'] ? $ev['attendance']['min'] : '' );
		$gallery    = $ev['gallery_ids'];
		foreach ( array( $ev['flyer_id'], $ev['featured_image_id'] ) as $img ) {
			if ( $img && ! in_array( $img, $gallery, true ) ) {
				array_unshift( $gallery, $img );
			}
		}
		$input = array(
			'event_name'          => $ev['name'],
			'description'         => $ev['description'],
			'event_date'          => $ev['date'],
			'start_time'          => $ev['start_time'],
			'end_time'            => $ev['end_time'],
			'city'                => $ev['city'],
			'state'               => $ev['state'],
			'country'             => $ev['country'],
			'venue_name'          => $ev['venue_tba'] ? '' : $ev['venue_name'],
			'venue_address'       => $ev['venue_tba'] ? '' : $ev['venue_address'],
			'venue_hidden'        => $ev['venue_hidden'] ? 1 : 0,
			'expected_attendance' => '' === $attendance ? '' : (string) $attendance,
			'power_available'     => $ev['power_available'] ? 1 : 0,
			'event_type'          => $ev['event_type'],
			'location_mode'       => 'strict',
			'location_confirmed'  => $ev['location_confirmed'] ? 1 : 0,
			'client_token'        => 'builder-' . $draft_id,
			'featured_image_id'   => $ev['featured_image_id'] ?: $ev['flyer_id'],
			'gallery_ids'         => $gallery ? implode( ',', $gallery ) : '',
		);
		if ( '' !== $primary ) {
			$input['ticketing_url']      = $primary;
			$input['ticketing_platform'] = $state['sources'][0]['provider'] ?: 'other';
		}
		return $input;
	}

	/**
	 * Builder options → create_opportunities_for_event input.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public static function opportunity_input( array $state, int $draft_id ): array {
		$docs      = $state['applications']['documents'];
		$deadline  = self::deadline( $state );
		$rows      = array();
		foreach ( $state['options'] as $opt ) {
			$row = array(
				'categories'           => array( $opt['category'] ),
				'vendor_category'      => $opt['category'],
				'vendor_fee'           => $opt['fee'],
				'spots_total'          => $opt['spots'],
				'opportunity_title'    => '' !== $opt['name'] ? $opt['name'] : $opt['category'],
				'description'          => $opt['description'],
				'application_deadline' => '' !== $opt['deadline'] ? $opt['deadline'] : $deadline,
			);
			$rows[] = $row;
		}
		$format = $state['event']['format'];
		return array(
			'opportunities'      => $rows,
			'client_token'       => 'builder-' . $draft_id,
			'document_mode'      => $docs['mode'],
			'required_documents' => wp_list_pluck( $docs['items'], 'key' ),
			'deposit_required'   => $state['payments']['deposit']['enabled'] ? 1 : 0,
			'environment'        => 'both' === $format ? 'both' : ( 'tba' === $format ? '' : $format ),
		);
	}

	/**
	 * Fixed deadline, N days before the event, or the platform default ('' lets onboarding decide).
	 *
	 * @param array<string,mixed> $state State.
	 */
	public static function deadline( array $state ): string {
		$d = $state['payments']['deadline'];
		if ( 'fixed' === $d['mode'] ) {
			return $d['date'];
		}
		if ( 'relative' === $d['mode'] && '' !== $state['event']['date'] ) {
			$ts = strtotime( $state['event']['date'] . ' -' . (int) $d['days_before'] . ' days' );
			return $ts ? gmdate( 'Y-m-d', max( $ts, strtotime( current_time( 'Y-m-d' ) ) ) ) : '';
		}
		return '';
	}

	/**
	 * @param array<string,mixed> $state State.
	 */
	private static function apply_event_extras( int $event_id, array $state, int $draft_id ): void {
		$ev = $state['event'];
		update_post_meta( $event_id, '_lnkup_builder_draft_id', $draft_id );
		update_post_meta( $event_id, '_lnkup_event_format', $ev['format'] );
		update_post_meta( $event_id, '_lnkup_event_timezone', $ev['timezone'] );
		update_post_meta( $event_id, '_lnkup_attendance_mode', $ev['attendance']['mode'] );
		if ( 'range' === $ev['attendance']['mode'] ) {
			update_post_meta( $event_id, '_lnkup_attendance_min', $ev['attendance']['min'] );
			update_post_meta( $event_id, '_lnkup_attendance_max', $ev['attendance']['max'] );
		}
		if ( '' !== $ev['end_date'] && $ev['end_date'] > $ev['date'] ) {
			update_post_meta( $event_id, '_lnkup_event_end_date', $ev['end_date'] );
		}
		if ( '' !== $ev['market_city'] && 0 !== strcasecmp( $ev['market_city'], $ev['city'] ) ) {
			update_post_meta( $event_id, '_lnkup_market_city', $ev['market_city'] );
		}
		if ( '' !== $ev['summary'] ) {
			wp_update_post( array( 'ID' => $event_id, 'post_excerpt' => $ev['summary'] ) );
		}
		if ( $ev['flyer_id'] ) {
			update_post_meta( $event_id, '_lnkup_flyer_id', $ev['flyer_id'] );
		}
		if ( $ev['brand_id'] && 'lnkup_brand' === get_post_type( $ev['brand_id'] ) && (int) get_post_field( 'post_author', $ev['brand_id'] ) === (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ) ) {
			update_post_meta( $event_id, '_lnkup_brand_id', $ev['brand_id'] );
		}
		if ( 'indoor' === $ev['format'] || 'outdoor' === $ev['format'] || 'both' === $ev['format'] ) {
			update_post_meta( $event_id, '_lnkup_indoor', in_array( $ev['format'], array( 'indoor', 'both' ), true ) );
			update_post_meta( $event_id, '_lnkup_outdoor', in_array( $ev['format'], array( 'outdoor', 'both' ), true ) );
		}
		if ( '' !== $state['payments']['cancellation_note'] ) {
			update_post_meta( $event_id, '_lnkup_cancellation_policy_note', $state['payments']['cancellation_note'] );
		}
		update_post_meta( $event_id, '_lnkup_ownership_state', $state['import']['provider'] || $state['sources'] ? 'attested' : 'organizer_created' );
		if ( $state['template']['id'] ) {
			update_post_meta( $event_id, '_lnkup_template_id', $state['template']['id'] );
			update_post_meta( $event_id, '_lnkup_template_version', $state['template']['version'] );
		}
	}

	/**
	 * Questions, documents gate, invite-only, closed applications, deposit, dimensions and amenities.
	 *
	 * @param int[]               $opp_ids Created opportunities (same order as options).
	 * @param array<string,mixed> $state   State.
	 */
	private static function apply_opportunity_extras( array $opp_ids, array $state, int $user_id ): void {
		$apps     = $state['applications'];
		$gate     = (bool) array_filter( $apps['documents']['items'], static fn( $d ) => 'before_approval' === $d['timing'] && $d['required'] );
		$deposit  = $state['payments']['deposit'];
		foreach ( array_values( $state['options'] ) as $i => $opt ) {
			$opp_id = (int) ( $opp_ids[ $i ] ?? 0 );
			if ( ! $opp_id ) {
				continue;
			}
			$questions = self::questions_for( $apps['questions'], $opt );
			if ( $questions || $gate ) {
				LNKup_Application_Questions::save_for_opportunity( $opp_id, $questions, $user_id, $gate );
			}
			update_post_meta( $opp_id, '_lnkup_invite_only', 'invite' === $apps['mode'] );
			if ( $opt['width_ft'] || $opt['depth_ft'] ) {
				update_post_meta( $opp_id, '_lnkup_booth_width_ft', $opt['width_ft'] );
				update_post_meta( $opp_id, '_lnkup_booth_depth_ft', $opt['depth_ft'] );
			}
			if ( $opt['amenities'] ) {
				update_post_meta( $opp_id, '_lnkup_included_amenities', wp_json_encode( $opt['amenities'] ) );
			}
			if ( $deposit['enabled'] ) {
				update_post_meta( $opp_id, '_lnkup_deposit_type', $deposit['type'] );
				update_post_meta( $opp_id, 'percent' === $deposit['type'] ? '_lnkup_deposit_percent' : '_lnkup_deposit_amount', $deposit['value'] );
			}
			if ( 'closed' === $apps['mode'] || 'closed' === $state['publish']['applications'] ) {
				LNKup_Organizer_Onboarding::set_opportunity_listing_status( $opp_id, 'closed', $user_id );
			}
		}
	}

	/**
	 * Builder question types → LNKup_Application_Questions types, filtered by scope.
	 *
	 * @param array<int,array<string,mixed>> $questions Questions.
	 * @param array<string,mixed>            $opt       Option.
	 * @return array<int,array<string,mixed>>
	 */
	public static function questions_for( array $questions, array $opt ): array {
		$out = array();
		foreach ( $questions as $q ) {
			if ( 'all' !== $q['scope'] && $q['scope'] !== $opt['key'] && 0 !== strcasecmp( $q['scope'], $opt['category'] ) ) {
				continue;
			}
			$type    = array( 'short' => 'text', 'long' => 'textarea', 'choice' => 'select', 'yesno' => 'select', 'number' => 'text', 'file' => 'file', 'ack' => 'checkbox' )[ $q['type'] ] ?? 'text';
			$options = 'yesno' === $q['type'] ? array( __( 'Yes', 'lnkup-marketplace' ), __( 'No', 'lnkup-marketplace' ) ) : $q['options'];
			$help    = 'number' === $q['type'] && '' === $q['help'] ? __( 'Enter a number.', 'lnkup-marketplace' ) : $q['help'];
			$out[]   = array( 'id' => $q['id'], 'label' => $q['label'], 'type' => $type, 'required' => $q['required'], 'options' => $options, 'help' => $help );
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function response( int $event_id, bool $idempotent ): array {
		$opps = get_posts( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'publish', 'posts_per_page' => 50, 'fields' => 'ids', 'meta_key' => '_lnkup_event_id', 'meta_value' => $event_id, 'no_found_rows' => true ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$apply = function_exists( 'lnkup_page_url_helper' ) && $opps ? add_query_arg( 'apply', (int) $opps[0], lnkup_page_url_helper( 'opportunities' ) ) : '';
		return array(
			'published'       => true,
			'idempotent'      => $idempotent,
			'event_id'        => $event_id,
			'title'           => lnkup_plain_title( $event_id ),
			'public_url'      => LNKup_Organizer_Onboarding::event_public_url( $event_id ),
			'apply_url'       => $apply,
			'dashboard_url'   => function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'events', array( 'event' => $event_id ) ) : '',
			'find_vendors'    => function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'vendors', array( 'event' => $event_id ) ) : '',
			'invite_url'      => function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'invites', array( 'event' => $event_id ) ) : '',
			'operations_url'  => function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'operations', array( 'event' => $event_id ) ) : '',
			'opportunity_ids' => array_map( 'intval', $opps ),
		);
	}

	/**
	 * @param array<string,mixed> $state State.
	 */
	public static function track( string $type, array $state, int $event_id, int $user_id ): void {
		if ( class_exists( 'LNKup_Analytics_Taxonomy' ) ) {
			LNKup_Analytics_Taxonomy::emit( $type, array( 'method' => $state['method'], 'quick' => $state['quick'] ? 1 : 0, 'options' => count( $state['options'] ), 'provider' => $state['import']['provider'] ), $user_id, 0 );
		} elseif ( class_exists( 'LNKup_Marketplace_Analytics' ) ) {
			LNKup_Marketplace_Analytics::track( $type, array( 'method' => $state['method'], 'event_id' => $event_id ), $user_id );
		}
	}
}
