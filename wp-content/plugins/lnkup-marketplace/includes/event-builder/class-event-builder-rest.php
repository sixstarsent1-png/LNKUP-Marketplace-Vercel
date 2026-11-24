<?php
/**
 * REST: lnkup/v1/organizer/builder/* — drafts, autosave, validation, previews, publish, edit.
 *
 * Every route requires a signed-in organizer (or site manager) and the event_builder_v3 flag;
 * draft routes also require draft ownership.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Builder_REST {

	const NS   = 'lnkup/v1';
	const BASE = '/organizer/builder';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function active(): bool {
		return LNKup_Event_OS_Flags::enabled( 'event_builder_v3' );
	}

	public static function can_use(): bool {
		$user = get_current_user_id();
		if ( ! $user || ! self::active() ) {
			return false;
		}
		return ( class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_organizer( $user ) ) || LNKup_Capabilities::user_has_manage_all( $user );
	}

	/**
	 * @return bool|WP_Error
	 */
	public static function can_use_draft( WP_REST_Request $request ) {
		if ( ! self::can_use() ) {
			return false;
		}
		if ( ! LNKup_Event_Builder_Drafts::can_edit( (int) $request['id'], get_current_user_id() ) ) {
			return new WP_Error( 'lnkup_builder_forbidden', __( 'You cannot open this draft.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return true;
	}

	public static function routes(): void {
		$can   = array( __CLASS__, 'can_use' );
		$draft = array( __CLASS__, 'can_use_draft' );
		$id    = array( 'id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) ) );
		register_rest_route( self::NS, self::BASE . '/config', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'config' ), 'permission_callback' => $can ) );
		register_rest_route(
			self::NS,
			self::BASE . '/drafts',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_drafts' ), 'permission_callback' => $can ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_draft' ), 'permission_callback' => $can ),
			)
		);
		register_rest_route(
			self::NS,
			self::BASE . '/drafts/(?P<id>\d+)',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_draft' ), 'permission_callback' => $draft, 'args' => $id ),
				array( 'methods' => 'PATCH,POST,PUT', 'callback' => array( __CLASS__, 'save_draft' ), 'permission_callback' => $draft, 'args' => $id ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_draft' ), 'permission_callback' => $draft, 'args' => $id ),
			)
		);
		register_rest_route( self::NS, self::BASE . '/drafts/(?P<id>\d+)/preview', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'preview' ), 'permission_callback' => $draft, 'args' => $id ) );
		register_rest_route( self::NS, self::BASE . '/drafts/(?P<id>\d+)/review', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'review' ), 'permission_callback' => $draft, 'args' => $id ) );
		register_rest_route( self::NS, self::BASE . '/drafts/(?P<id>\d+)/apply-template', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'apply_template' ), 'permission_callback' => $draft, 'args' => $id ) );
		register_rest_route( self::NS, self::BASE . '/drafts/(?P<id>\d+)/publish', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'publish' ), 'permission_callback' => $draft, 'args' => $id ) );
		register_rest_route( self::NS, self::BASE . '/events/(?P<id>\d+)/edit', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'edit_event' ), 'permission_callback' => $can, 'args' => $id ) );
		register_rest_route( self::NS, self::BASE . '/fee-preview', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'fee_preview' ), 'permission_callback' => $can ) );
		register_rest_route( self::NS, self::BASE . '/recommendations', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'recommendations' ), 'permission_callback' => $can ) );
		register_rest_route( self::NS, self::BASE . '/media', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'upload' ), 'permission_callback' => $can ) );
		register_rest_route( self::NS, self::BASE . '/library', array( 'methods' => 'GET', 'callback' => static fn() => rest_ensure_response( LNKup_Event_Builder_Library::list_for( get_current_user_id() ) ), 'permission_callback' => $can ) );
		register_rest_route( self::NS, self::BASE . '/library/templates', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_template' ), 'permission_callback' => $can ) );
		register_rest_route( self::NS, self::BASE . '/library/setups/(?P<setup>[a-zA-Z0-9_]+)/convert', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'convert_setup' ), 'permission_callback' => $can ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_template( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Event_Builder_Library::save_as_template( get_current_user_id(), sanitize_text_field( (string) ( $body['name'] ?? '' ) ), absint( $body['draft_id'] ?? 0 ), absint( $body['event_id'] ?? 0 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		LNKup_Event_Builder_Publisher::track( 'template_saved', LNKup_Event_Builder_Schema::blank( 'template' ), 0, get_current_user_id() );
		return rest_ensure_response( $result );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function convert_setup( WP_REST_Request $request ) {
		$result = LNKup_Event_Builder_Library::convert_setup( get_current_user_id(), (string) $request['setup'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * @param array<string,mixed>|null $draft Draft.
	 */
	public static function draft_payload( ?array $draft ): array {
		if ( ! $draft ) {
			return array();
		}
		$user = get_current_user_id();
		return array(
			'draft'  => $draft,
			'report' => LNKup_Event_Builder_Validator::report( $draft['state'], $user, (int) $draft['source_event'] ),
			'fees'   => LNKup_Event_Builder_Validator::fee_preview( $draft['state']['options'], $user ),
			'scheduled_at' => ( $ts = (int) get_post_meta( (int) $draft['id'], LNKup_Event_Builder_Publisher::SCHEDULE_META, true ) ) ? gmdate( 'c', $ts ) : '',
		);
	}

	private static function body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		return is_array( $body ) ? $body : (array) $request->get_body_params();
	}

	public static function config(): WP_REST_Response {
		$user  = get_current_user_id();
		$types = get_terms( array( 'taxonomy' => 'lnkup_event_type', 'hide_empty' => false ) );
		$type_list = array();
		foreach ( is_array( $types ) ? $types : array() as $t ) {
			$type_list[] = array( 'id' => (int) $t->term_id, 'slug' => $t->slug, 'name' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ), 'parent' => (int) $t->parent );
		}
		$brands = array();
		foreach ( get_posts( array( 'post_type' => 'lnkup_brand', 'author' => $user, 'post_status' => 'publish', 'posts_per_page' => 50, 'no_found_rows' => true ) ) as $b ) {
			$brands[] = array( 'id' => $b->ID, 'name' => $b->post_title );
		}
		$docs = array();
		$labels = array( 'certificate_of_insurance' => __( 'Certificate of insurance', 'lnkup-marketplace' ), 'health_permit' => __( 'Health / food permit', 'lnkup-marketplace' ), 'business_license' => __( 'Business license', 'lnkup-marketplace' ), 'w9' => __( 'W-9', 'lnkup-marketplace' ) );
		foreach ( LNKup_Event_Builder_Schema::DOCUMENTS as $d ) {
			$docs[] = array( 'key' => $d, 'label' => $labels[ $d ] ?? $d );
		}
		$amen = array( 'electricity' => __( 'Electricity', 'lnkup-marketplace' ), 'table' => __( 'Table', 'lnkup-marketplace' ), 'chairs' => __( 'Chairs', 'lnkup-marketplace' ), 'tent' => __( 'Tent', 'lnkup-marketplace' ), 'water' => __( 'Water access', 'lnkup-marketplace' ), 'parking' => __( 'Vendor parking', 'lnkup-marketplace' ), 'wifi' => __( 'Wi-Fi', 'lnkup-marketplace' ), 'extra_credentials' => __( 'Extra credentials', 'lnkup-marketplace' ) );
		$settings = function_exists( 'lnkup_get_settings' ) ? (array) lnkup_get_settings() : array();
		return rest_ensure_response(
			array(
				'flags'          => LNKup_Event_OS_Flags::all(),
				'portfolio'      => class_exists( 'LNKup_Feature_Flags' ) && LNKup_Feature_Flags::portfolio( $user ),
				'categories'     => function_exists( 'lnkup_canonical_vendor_categories' ) ? lnkup_canonical_vendor_categories() : array(),
				'event_types'    => $type_list,
				'brands'         => $brands,
				'documents'      => $docs,
				'amenities'      => array_map( static fn( $k, $v ) => array( 'key' => $k, 'label' => $v ), array_keys( $amen ), $amen ),
				'timezone'       => LNKup_Event_Builder_Schema::timezone( '' ),
				'payouts_ready'  => class_exists( 'LNKup_Stripe_Connect' ) && LNKup_Stripe_Connect::is_connect_ready( $user ),
				'payouts_url'    => function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'financial' ) : '',
				'commission_pct' => (float) ( $settings['organizer_commission_percent'] ?? 6 ),
			)
		);
	}

	public static function list_drafts(): WP_REST_Response {
		return rest_ensure_response( array( 'drafts' => LNKup_Event_Builder_Drafts::list_for( get_current_user_id() ) ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_draft( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$user   = get_current_user_id();
		$method = LNKup_Event_Builder_Schema::pick( $body['method'] ?? '', LNKup_Event_Builder_Schema::METHODS, 'single' );
		$state  = is_array( $body['state'] ?? null ) ? $body['state'] : array();
		$state['method'] = $method;
		$state['quick']  = ! empty( $body['quick'] ) || ! empty( $state['quick'] );

		if ( ! empty( $body['duplicate_event'] ) ) {
			$state = LNKup_Event_Builder_Library::duplicate_state( (int) $body['duplicate_event'], $user, (array) ( $body['components'] ?? array() ) );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
		} elseif ( ! empty( $body['template_id'] ) ) {
			$ref   = (string) $body['template_id'];
			$state = LNKup_Event_Builder_Library::template_state( ctype_digit( $ref ) ? (int) $ref : sanitize_text_field( $ref ), $user, $state );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
		}
		$draft = LNKup_Event_Builder_Drafts::create( $user, $state );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		LNKup_Event_Builder_Publisher::track( 'creation_method_selected', $draft['state'], 0, $user );
		$response = rest_ensure_response( self::draft_payload( $draft ) );
		$response->set_status( 201 );
		return $response;
	}

	public static function get_draft( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( self::draft_payload( LNKup_Event_Builder_Drafts::get( (int) $request['id'] ) ) );
	}

	/**
	 * Autosave: body { revision, patch }.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_draft( WP_REST_Request $request ) {
		$body     = self::body( $request );
		$revision = isset( $body['revision'] ) ? (int) $body['revision'] : (int) $request->get_header( 'if_match' );
		$patch    = is_array( $body['patch'] ?? null ) ? $body['patch'] : array();
		$saved    = LNKup_Event_Builder_Drafts::save( (int) $request['id'], $patch, $revision );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		if ( isset( $patch['step'] ) ) {
			LNKup_Event_Builder_Publisher::track( 'creation_step_completed', $saved['state'], 0, get_current_user_id() );
		}
		return rest_ensure_response( self::draft_payload( $saved ) );
	}

	public static function delete_draft( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request['id'];
		wp_clear_scheduled_hook( LNKup_Event_Builder_Publisher::SCHEDULE_HOOK, array( $id ) );
		return rest_ensure_response( array( 'deleted' => LNKup_Event_Builder_Drafts::delete( $id ) ) );
	}

	/**
	 * Vendor-facing preview built from the same fields the live page uses.
	 */
	public static function preview( WP_REST_Request $request ): WP_REST_Response {
		$draft = LNKup_Event_Builder_Drafts::get( (int) $request['id'] );
		$mode  = 'guest' === $request->get_param( 'mode' ) ? 'guest' : 'vendor';
		return rest_ensure_response( self::preview_payload( (array) $draft['state'], $mode, get_current_user_id() ) );
	}

	/**
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public static function preview_payload( array $state, string $mode, int $user_id ): array {
		$ev     = $state['event'];
		$image  = '';
		$img_id = $ev['flyer_id'] ?: $ev['featured_image_id'];
		if ( $img_id ) {
			$image = (string) wp_get_attachment_image_url( $img_id, 'large' );
		}
		$org = class_exists( 'LNKup_OS_UI' ) && method_exists( 'LNKup_OS_UI', 'organizer_name' ) ? (string) LNKup_OS_UI::organizer_name( $user_id ) : (string) get_the_author_meta( 'display_name', $user_id );
		$inv = LNKup_Event_Builder_Validator::inventory( $state['options'] );
		$opts = array();
		foreach ( $state['options'] as $o ) {
			$opts[] = array(
				'name'       => '' !== $o['name'] ? $o['name'] : $o['category'],
				'category'   => $o['category'],
				'price'      => 'guest' === $mode ? null : $o['fee'],
				'spots'      => 'guest' === $mode ? null : $o['spots'],
				'dimensions' => $o['width_ft'] && $o['depth_ft'] ? $o['width_ft'] . ' × ' . $o['depth_ft'] . ' ft' : '',
				'amenities'  => $o['amenities'],
				'free'       => $o['fee'] <= 0,
			);
		}
		$date_label = '';
		if ( '' !== $ev['date'] ) {
			$date_label = wp_date( 'D, M j, Y', strtotime( $ev['date'] . ' 12:00:00' ) );
			if ( '' !== $ev['end_date'] && $ev['end_date'] > $ev['date'] ) {
				$date_label .= ' – ' . wp_date( 'M j', strtotime( $ev['end_date'] . ' 12:00:00' ) );
			}
		}
		$sources = array();
		foreach ( $state['sources'] as $s ) {
			$sources[] = array( 'url' => $s['url'], 'label' => class_exists( 'LNKup_Import_Provider_Registry' ) ? LNKup_Import_Provider_Registry::label_for_url( $s['url'] ) : wp_parse_url( $s['url'], PHP_URL_HOST ), 'primary' => $s['primary'] );
		}
		return array(
			'mode'        => $mode,
			'title'       => '' !== $ev['name'] ? $ev['name'] : __( 'Untitled event', 'lnkup-marketplace' ),
			'image'       => $image,
			'image_focus' => $ev['image_focus'],
			'date'        => $ev['date_tba'] ? __( 'Date to be announced', 'lnkup-marketplace' ) : $date_label,
			'time'        => trim( $ev['start_time'] . ( '' !== $ev['end_time'] ? ' – ' . $ev['end_time'] : '' ) ),
			'place'       => trim( ( $ev['venue_tba'] ? __( 'Venue TBA', 'lnkup-marketplace' ) : ( $ev['venue_hidden'] && 'guest' === $mode ? '' : $ev['venue_name'] ) ) . ( '' !== $ev['city'] ? ' · ' . $ev['city'] . ( '' !== $ev['state'] ? ', ' . $ev['state'] : '' ) : '' ), ' ·' ),
			'organizer'   => $org,
			'description' => $ev['description'],
			'attendance'  => 'estimate' === $ev['attendance']['mode'] ? sprintf( /* translators: %s: number */ __( 'About %s expected (organizer estimate)', 'lnkup-marketplace' ), number_format_i18n( $ev['attendance']['value'] ) ) : ( 'range' === $ev['attendance']['mode'] ? sprintf( /* translators: 1: min, 2: max */ __( '%1$s–%2$s expected (organizer estimate)', 'lnkup-marketplace' ), number_format_i18n( $ev['attendance']['min'] ), number_format_i18n( $ev['attendance']['max'] ) ) : '' ),
			'options'     => $opts,
			'starting'    => 'guest' === $mode ? null : $inv['starting_fee'],
			'all_free'    => $inv['spaces'] > 0 && $inv['spaces'] === $inv['free_spaces'],
			'pricing_note' => 'guest' === $mode ? __( 'Sign in to see booth prices and availability.', 'lnkup-marketplace' ) : '',
			'applications' => 'closed' === $state['applications']['mode'] || 'closed' === $state['publish']['applications'] ? 'closed' : ( 'invite' === $state['applications']['mode'] ? 'invite' : 'open' ),
			'deadline'    => LNKup_Event_Builder_Publisher::deadline( $state ),
			'sources'     => $sources,
			'disclosure'  => $sources ? __( 'Vendor applications and booth payments are handled on LNKup. Attendee tickets are sold on the official event listing.', 'lnkup-marketplace' ) : '',
		);
	}

	/**
	 * Apply a template's vendor setup to an existing draft (event details are kept).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function apply_template( WP_REST_Request $request ) {
		$body  = self::body( $request );
		$id    = (int) $request['id'];
		$draft = LNKup_Event_Builder_Drafts::get( $id );
		$ref   = (string) ( $body['template_id'] ?? '' );
		$tpl   = LNKup_Event_Builder_Library::template_state( ctype_digit( $ref ) ? (int) $ref : sanitize_text_field( $ref ), get_current_user_id(), array() );
		if ( is_wp_error( $tpl ) ) {
			return $tpl;
		}
		$patch = array(
			'options'      => $tpl['options'],
			'applications' => array( 'questions' => $tpl['applications']['questions'], 'documents' => $tpl['applications']['documents'] ),
			'template'     => $tpl['template'],
			'step'         => 'options',
		);
		if ( 'relative' === $tpl['payments']['deadline']['mode'] ) {
			$patch['payments'] = array( 'deadline' => $tpl['payments']['deadline'] );
		}
		$saved = LNKup_Event_Builder_Drafts::save( $id, $patch, isset( $body['revision'] ) ? (int) $body['revision'] : (int) $draft['revision'] );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		LNKup_Event_Builder_Publisher::track( 'template_applied', $saved['state'], 0, get_current_user_id() );
		return rest_ensure_response( self::draft_payload( $saved ) );
	}

	/**
	 * @return WP_REST_Response
	 */
	public static function review( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( LNKup_Event_Builder_Editor::review( (int) $request['id'] ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function publish( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Event_Builder_Publisher::publish( (int) $request['id'], get_current_user_id(), false, ! empty( $body['confirm'] ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function edit_event( WP_REST_Request $request ) {
		$draft = LNKup_Event_Builder_Editor::start( (int) $request['id'], get_current_user_id() );
		return is_wp_error( $draft ) ? $draft : rest_ensure_response( self::draft_payload( $draft ) );
	}

	public static function fee_preview( WP_REST_Request $request ): WP_REST_Response {
		$body    = self::body( $request );
		$options = LNKup_Event_Builder_Schema::options( (array) ( $body['options'] ?? array() ) );
		return rest_ensure_response( array( 'fees' => LNKup_Event_Builder_Validator::fee_preview( $options, get_current_user_id() ) ) );
	}

	public static function recommendations(): WP_REST_Response {
		return rest_ensure_response( LNKup_Event_Builder_Library::recommendations( get_current_user_id() ) );
	}

	/**
	 * Image upload into the media library (event cover / flyer / gallery).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new WP_Error( 'lnkup_builder_upload', __( 'Choose an image to upload.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$file = $files['file'];
		if ( (int) ( $file['size'] ?? 0 ) > 8 * MB_IN_BYTES ) {
			return new WP_Error( 'lnkup_builder_upload', __( 'Images can be up to 8 MB.', 'lnkup-marketplace' ), array( 'status' => 413 ) );
		}
		$check = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) $file['name'], array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif' ) );
		if ( empty( $check['type'] ) || 0 !== strpos( (string) $check['type'], 'image/' ) || ! @getimagesize( (string) $file['tmp_name'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'lnkup_builder_upload', __( 'Upload a JPG, PNG, WebP or GIF image.', 'lnkup-marketplace' ), array( 'status' => 415 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_handle_sideload( array( 'name' => sanitize_file_name( (string) $file['name'] ), 'tmp_name' => (string) $file['tmp_name'] ), 0 );
		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'lnkup_builder_upload', __( 'The image could not be saved. Try another file.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		update_post_meta( (int) $id, '_lnkup_uploaded_by', get_current_user_id() );
		$src = wp_get_attachment_image_src( (int) $id, 'large' );
		return rest_ensure_response( array( 'id' => (int) $id, 'url' => $src ? $src[0] : '', 'width' => $src ? (int) $src[1] : 0, 'height' => $src ? (int) $src[2] : 0 ) );
	}
}
