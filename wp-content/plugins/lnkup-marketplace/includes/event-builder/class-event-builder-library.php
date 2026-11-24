<?php
/**
 * Configuration library: versioned templates (LNKup_Portfolio_Templates), legacy saved setups
 * (LNKup_Organizer_Setups, shown read-only with "convert"), starter structures, duplication and
 * creation-method recommendations.
 *
 * Templates carry structure only: booth types, prices the organizer saved, questions, documents
 * and deadline offset. They never include applications, bookings, payments, passes, assigned
 * vendors, credentials or private documents.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Builder_Library {

	/**
	 * Starter structures: categories and questions only, no prices or capacity.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function starters(): array {
		$q = static fn( string $id, string $label, string $type = 'short', bool $req = false, array $opts = array() ) => array( 'id' => $id, 'label' => $label, 'type' => $type, 'required' => $req, 'options' => $opts, 'scope' => 'all' );
		return array(
			'outdoor_festival'   => array( 'name' => __( 'Outdoor festival', 'lnkup-marketplace' ), 'format' => 'outdoor', 'categories' => array( 'Food Trucks', 'Food', 'Drinks', 'Retail', 'Art' ), 'questions' => array( $q( 'power', __( 'Do you need electricity?', 'lnkup-marketplace' ), 'yesno', true ) ), 'docs' => array( 'certificate_of_insurance' ) ),
			'food_truck_fest'    => array( 'name' => __( 'Food truck festival', 'lnkup-marketplace' ), 'format' => 'outdoor', 'categories' => array( 'Food Trucks', 'Desserts', 'Drinks' ), 'questions' => array( $q( 'truck_length', __( 'Truck or trailer length (ft)', 'lnkup-marketplace' ), 'number', true ), $q( 'menu', __( 'Menu highlights', 'lnkup-marketplace' ), 'long' ) ), 'docs' => array( 'health_permit', 'certificate_of_insurance' ) ),
			'indoor_market'      => array( 'name' => __( 'Indoor vendor market', 'lnkup-marketplace' ), 'format' => 'indoor', 'categories' => array( 'Retail', 'Clothing', 'Jewelry', 'Beauty', 'Art' ), 'questions' => array( $q( 'products', __( 'What will you sell?', 'lnkup-marketplace' ), 'long', true ) ), 'docs' => array() ),
			'popup_shop'         => array( 'name' => __( 'Pop-up shop', 'lnkup-marketplace' ), 'format' => 'indoor', 'categories' => array( 'Retail', 'Clothing', 'Beauty' ), 'questions' => array(), 'docs' => array() ),
			'community_festival' => array( 'name' => __( 'Community festival', 'lnkup-marketplace' ), 'format' => 'outdoor', 'categories' => array( 'Food', 'Retail', 'Services', 'Art' ), 'questions' => array(), 'docs' => array() ),
			'convention'         => array( 'name' => __( 'Convention', 'lnkup-marketplace' ), 'format' => 'indoor', 'categories' => array( 'Merchandise', 'Art', 'Services' ), 'questions' => array( $q( 'setup', __( 'Booth setup needs', 'lnkup-marketplace' ), 'long' ) ), 'docs' => array() ),
			'farmers_market'     => array( 'name' => __( 'Farmers market', 'lnkup-marketplace' ), 'format' => 'outdoor', 'categories' => array( 'Food', 'Drinks', 'Wellness' ), 'questions' => array( $q( 'grown', __( 'Do you grow or make what you sell?', 'lnkup-marketplace' ), 'yesno', true ) ), 'docs' => array( 'health_permit' ) ),
		);
	}

	/**
	 * Everything the organizer can start from.
	 *
	 * @return array<string,mixed>
	 */
	public static function list_for( int $user_id ): array {
		$templates = array();
		if ( class_exists( 'LNKup_Portfolio_Templates' ) ) {
			foreach ( LNKup_Portfolio_Templates::list_for_organizer( $user_id ) as $tpl ) {
				$t           = is_array( $tpl ) ? $tpl : LNKup_Portfolio_Templates::format( (int) $tpl );
				$templates[] = array(
					'id'      => (int) $t['id'],
					'name'    => (string) $t['name'],
					'version' => (int) $t['version'],
					'summary' => $t['summary'],
					'booths'  => array_map( static fn( $b ) => array( 'label' => $b['label'], 'category' => $b['category'], 'price' => $b['price'], 'capacity' => $b['capacity'] ), (array) ( $t['config']['booth_types'] ?? array() ) ),
					'updated' => (string) $t['updated_at'],
				);
			}
		}
		$setups = array();
		if ( class_exists( 'LNKup_Organizer_Setups' ) ) {
			foreach ( LNKup_Organizer_Setups::all( $user_id ) as $s ) {
				$f        = LNKup_Organizer_Setups::format( $s );
				$setups[] = array( 'id' => $f['id'], 'name' => $f['name'], 'slot_count' => $f['slot_count'], 'created_at' => $f['created_at'] );
			}
		}
		$starters = array();
		foreach ( self::starters() as $key => $s ) {
			$starters[] = array( 'key' => $key, 'name' => $s['name'], 'categories' => $s['categories'], 'questions' => count( $s['questions'] ) );
		}
		return array( 'templates' => $templates, 'setups' => $setups, 'starters' => $starters );
	}

	/**
	 * Builder state seeded from a template version (template ID or "starter:key" or "setup:id").
	 *
	 * @param int|string          $template Template reference.
	 * @param array<string,mixed> $base     Base state.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function template_state( $template, int $user_id, array $base = array() ) {
		$state           = LNKup_Event_Builder_Schema::normalize( $base );
		$state['method'] = 'template';
		if ( is_string( $template ) && 0 === strpos( $template, 'starter:' ) ) {
			$s = self::starters()[ substr( $template, 8 ) ] ?? null;
			if ( ! $s ) {
				return new WP_Error( 'lnkup_builder_template', __( 'That starter is not available.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
			}
			$state['event']['format']        = $s['format'];
			$state['options']                = array_map( static fn( $c ) => array( 'name' => $c, 'category' => $c, 'fee' => 0, 'spots' => 0 ), $s['categories'] );
			$state['applications']['questions'] = $s['questions'];
			if ( $s['docs'] ) {
				$state['applications']['documents'] = array( 'mode' => 'custom', 'items' => array_map( static fn( $d ) => array( 'key' => $d, 'timing' => 'before_event' ), $s['docs'] ) );
			}
			return LNKup_Event_Builder_Schema::normalize( $state );
		}
		if ( is_string( $template ) && 0 === strpos( $template, 'setup:' ) ) {
			$setup = class_exists( 'LNKup_Organizer_Setups' ) ? LNKup_Organizer_Setups::get( $user_id, substr( $template, 6 ) ) : null;
			if ( ! $setup ) {
				return new WP_Error( 'lnkup_builder_template', __( 'That saved setup is not available.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
			}
			$state['options'] = array();
			foreach ( (array) $setup['slots'] as $slot ) {
				$cat                = (string) ( ( (array) ( $slot['categories'] ?? array() ) )[0] ?? '' );
				$state['options'][] = array( 'name' => (string) ( $slot['opportunity_title'] ?? $cat ), 'category' => '' !== $cat ? $cat : (string) ( $slot['opportunity_title'] ?? '' ), 'fee' => (float) ( $slot['vendor_fee'] ?? 0 ), 'spots' => (int) ( $slot['spots_total'] ?? 0 ) );
			}
			return LNKup_Event_Builder_Schema::normalize( $state );
		}
		$tid = (int) $template;
		if ( ! class_exists( 'LNKup_Portfolio_Templates' ) || ! LNKup_Portfolio_Templates::owns( $tid, $user_id ) ) {
			return new WP_Error( 'lnkup_builder_template', __( 'That template is not available.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$version = LNKup_Portfolio_Templates::current_version( $tid );
		if ( ! $version ) {
			return new WP_Error( 'lnkup_builder_template', __( 'This template has no saved setup yet.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$config = (array) $version['config'];
		$state['options'] = array_map(
			static fn( $b ) => array( 'key' => $b['key'], 'name' => $b['label'], 'category' => '' !== $b['category'] ? $b['category'] : $b['label'], 'fee' => $b['price'], 'spots' => $b['capacity'] ),
			(array) ( $config['booth_types'] ?? array() )
		);
		$back = array( 'text' => 'short', 'textarea' => 'long', 'select' => 'choice', 'file' => 'file', 'checkbox' => 'ack' );
		$state['applications']['questions'] = array_map(
			static fn( $q ) => array( 'id' => $q['id'], 'label' => $q['label'], 'type' => $back[ $q['type'] ] ?? 'short', 'required' => $q['required'], 'options' => $q['options'], 'help' => $q['help'], 'scope' => 'all' ),
			(array) ( $config['questions'] ?? array() )
		);
		$state['applications']['documents'] = array(
			'mode'  => (string) ( $config['document_mode'] ?? 'none' ),
			'items' => array_map( static fn( $d ) => array( 'key' => $d, 'timing' => 'before_event' ), (array) ( $config['required_documents'] ?? array() ) ),
		);
		$state['payments']['deadline'] = array( 'mode' => 'relative', 'days_before' => (int) ( $config['deadline_days_before'] ?? 7 ) );
		if ( '' === $state['event']['description'] && '' !== (string) ( $config['vendor_description'] ?? '' ) ) {
			$state['event']['description'] = (string) $config['vendor_description'];
		}
		$state['template'] = array( 'id' => $tid, 'version' => (int) $version['version'] );
		foreach ( array( 'options', 'applications.questions', 'applications.documents' ) as $f ) {
			$state['provenance'][ $f ] = 'template';
		}
		return LNKup_Event_Builder_Schema::normalize( $state );
	}

	/**
	 * Template config from builder state (structure only).
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public static function config_from_state( array $state ): array {
		$q = array();
		foreach ( $state['applications']['questions'] as $item ) {
			$mapped = LNKup_Event_Builder_Publisher::questions_for( array( array_merge( $item, array( 'scope' => 'all' ) ) ), array( 'key' => '', 'category' => '' ) );
			$q      = array_merge( $q, $mapped );
		}
		return array(
			'booth_types'          => array_map( static fn( $o ) => array( 'key' => $o['key'], 'label' => '' !== $o['name'] ? $o['name'] : $o['category'], 'category' => $o['category'], 'price' => $o['fee'], 'capacity' => $o['spots'] ), $state['options'] ),
			'document_mode'        => $state['applications']['documents']['mode'],
			'required_documents'   => wp_list_pluck( $state['applications']['documents']['items'], 'key' ),
			'questions'            => $q,
			'deadline_days_before' => 'relative' === $state['payments']['deadline']['mode'] ? (int) $state['payments']['deadline']['days_before'] : 7,
			'vendor_description'   => '',
		);
	}

	/**
	 * Save a draft's or an event's configuration as a new template.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function save_as_template( int $user_id, string $name, int $draft_id = 0, int $event_id = 0 ) {
		if ( $draft_id ) {
			if ( ! LNKup_Event_Builder_Drafts::can_edit( $draft_id, $user_id ) ) {
				return new WP_Error( 'lnkup_builder_forbidden', __( 'You cannot use this draft.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
			}
			$state = LNKup_Event_Builder_Drafts::get( $draft_id )['state'];
		} elseif ( $event_id && LNKup_Event_Builder_Editor::can_edit_event( $event_id, $user_id ) ) {
			$state = LNKup_Event_Builder_Editor::state_from_event( $event_id );
		} else {
			return new WP_Error( 'lnkup_builder_forbidden', __( 'Choose one of your events or drafts.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		if ( ! $state['options'] ) {
			return new WP_Error( 'lnkup_builder_template', __( 'Add at least one vendor option before saving a template.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return LNKup_Portfolio_Templates::create( $user_id, $name, self::config_from_state( $state ) );
	}

	/**
	 * Convert a legacy saved setup into a versioned template (the setup is kept).
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function convert_setup( int $user_id, string $setup_id ) {
		$setup = class_exists( 'LNKup_Organizer_Setups' ) ? LNKup_Organizer_Setups::get( $user_id, $setup_id ) : null;
		if ( ! $setup ) {
			return new WP_Error( 'lnkup_builder_template', __( 'That saved setup is not available.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$booths    = array();
		$questions = array();
		$docs      = array();
		$mode      = 'none';
		foreach ( (array) $setup['slots'] as $i => $slot ) {
			$cat      = (string) ( ( (array) ( $slot['categories'] ?? array() ) )[0] ?? $slot['opportunity_title'] ?? '' );
			$booths[] = array( 'key' => 'b' . $i, 'label' => (string) ( $slot['opportunity_title'] ?? $cat ), 'category' => $cat, 'price' => (float) ( $slot['vendor_fee'] ?? 0 ), 'capacity' => (int) ( $slot['spots_total'] ?? 0 ) );
			if ( ! $questions && ! empty( $slot['setup_questions'] ) ) {
				$questions = (array) $slot['setup_questions'];
			}
			if ( ! empty( $slot['document_mode'] ) ) {
				$mode = (string) $slot['document_mode'];
				$docs = array_filter( array_map( 'trim', explode( ',', (string) ( $slot['required_documents'] ?? '' ) ) ), static fn( $d ) => '' !== $d && 'none' !== $d );
			}
		}
		return LNKup_Portfolio_Templates::create( $user_id, (string) $setup['name'], array( 'booth_types' => $booths, 'questions' => $questions, 'document_mode' => $mode, 'required_documents' => array_values( $docs ), 'deadline_days_before' => 7 ) );
	}

	/**
	 * Duplication 2.0: a draft (never a live event) with the chosen reusable parts.
	 * Date, external links and option IDs are cleared; the location must be re-confirmed.
	 *
	 * @param string[] $components description, images, options, questions, documents, payments, category.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function duplicate_state( int $event_id, int $user_id, array $components = array() ) {
		if ( ! LNKup_Event_Builder_Editor::can_edit_event( $event_id, $user_id ) ) {
			return new WP_Error( 'lnkup_builder_forbidden', __( 'You can only duplicate your own events.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$all   = array( 'description', 'images', 'options', 'questions', 'documents', 'payments', 'category' );
		$keep  = $components ? array_intersect( $all, array_map( 'sanitize_key', $components ) ) : $all;
		$state = LNKup_Event_Builder_Editor::state_from_event( $event_id, 'duplicate' );
		$state['event']['date']               = '';
		$state['event']['end_date']           = '';
		$state['event']['location_confirmed'] = false;
		$state['sources']                     = array();
		$state['publish']                     = array( 'when' => 'now', 'applications' => 'open', 'attest' => false );
		$state['duplicate_of']                = $event_id;
		$state['confirmed']                   = array();
		foreach ( $state['options'] as &$o ) {
			$o['opportunity_id'] = 0;
			$o['deadline']       = '';
			$o['key']            = 'd' . substr( md5( $o['key'] . wp_rand() ), 0, 8 );
		}
		unset( $o );
		if ( ! in_array( 'description', $keep, true ) ) {
			$state['event']['description'] = '';
			$state['event']['summary']     = '';
		}
		if ( ! in_array( 'images', $keep, true ) ) {
			$state['event']['featured_image_id'] = 0;
			$state['event']['flyer_id']          = 0;
			$state['event']['gallery_ids']       = array();
		}
		if ( ! in_array( 'options', $keep, true ) ) {
			$state['options'] = array();
		}
		if ( ! in_array( 'questions', $keep, true ) ) {
			$state['applications']['questions'] = array();
		}
		if ( ! in_array( 'documents', $keep, true ) ) {
			$state['applications']['documents'] = array( 'mode' => 'none', 'items' => array() );
		}
		if ( ! in_array( 'payments', $keep, true ) ) {
			$state['payments'] = array();
		}
		if ( ! in_array( 'category', $keep, true ) ) {
			$state['event']['event_type'] = '';
		}
		foreach ( array_keys( $state['event'] ) as $f ) {
			$state['provenance'][ 'event.' . $f ] = 'duplicate';
		}
		return LNKup_Event_Builder_Schema::normalize( $state );
	}

	/**
	 * Suggested starting methods from real account context. Never auto-selects.
	 *
	 * @return array<string,mixed>
	 */
	public static function recommendations( int $user_id ): array {
		$events = get_posts( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'posts_per_page' => 20, 'fields' => 'ids', 'meta_key' => '_lnkup_organizer_user_id', 'meta_value' => $user_id, 'no_found_rows' => true ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$tours  = class_exists( 'LNKup_Feature_Flags' ) && LNKup_Feature_Flags::portfolio( $user_id ) ? get_posts( array( 'post_type' => 'lnkup_tour', 'post_status' => 'publish', 'author' => $user_id, 'posts_per_page' => 5, 'fields' => 'ids', 'no_found_rows' => true ) ) : array();
		$with_link = 0;
		foreach ( $events as $id ) {
			$with_link += '' !== (string) get_post_meta( $id, '_lnkup_ticketing_url', true ) ? 1 : 0;
		}
		$drafts    = LNKup_Event_Builder_Drafts::list_for( $user_id, 3 );
		$templates = class_exists( 'LNKup_Portfolio_Templates' ) ? count( LNKup_Portfolio_Templates::list_for_organizer( $user_id ) ) : 0;
		$reco      = array();
		if ( $drafts ) {
			$reco[] = array( 'method' => 'continue', 'reason' => __( 'Pick up where you left off.', 'lnkup-marketplace' ) );
		}
		if ( $tours ) {
			$reco[] = array( 'method' => 'tour', 'reason' => __( 'Add the next stop to your tour.', 'lnkup-marketplace' ) );
		}
		if ( ! $events ) {
			$reco[] = array( 'method' => 'import', 'reason' => __( 'Already listed on POSH, Eventbrite or your website? Import it and keep selling tickets there.', 'lnkup-marketplace' ) );
		} elseif ( $templates ) {
			$reco[] = array( 'method' => 'template', 'reason' => __( 'Start from one of your saved templates.', 'lnkup-marketplace' ) );
		} elseif ( count( $events ) >= 1 ) {
			$reco[] = array( 'method' => 'duplicate', 'reason' => __( 'Reuse the vendor setup from a past event.', 'lnkup-marketplace' ) );
		}
		if ( $with_link >= 2 && LNKup_Event_OS_Flags::enabled( 'event_import_v3' ) ) {
			$reco[] = array( 'method' => 'import', 'reason' => __( 'Your events have ticketing links — import the next one in seconds.', 'lnkup-marketplace' ) );
		}
		$recent = array();
		foreach ( array_slice( $events, 0, 6 ) as $id ) {
			$recent[] = array( 'id' => (int) $id, 'title' => lnkup_plain_title( (int) $id ), 'date' => (string) get_post_meta( (int) $id, '_lnkup_event_date', true ), 'city' => (string) get_post_meta( (int) $id, '_lnkup_city', true ) );
		}
		return array( 'recommended' => array_slice( $reco, 0, 2 ), 'drafts' => $drafts, 'recent_events' => $recent, 'has_events' => (bool) $events, 'library' => self::list_for( $user_id ) );
	}
}
