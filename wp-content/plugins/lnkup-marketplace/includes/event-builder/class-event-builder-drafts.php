<?php
/**
 * Server-side creation drafts with optimistic concurrency.
 *
 * Drafts live in a private post type (`lnkup_event_draft`) instead of `lnkup_event`, so queries
 * across the marketplace that read events with post_status=any never see half-built events.
 * Each save increments a revision; a save carrying an older revision is rejected (409) so a
 * second tab or team member never silently overwrites newer work.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Builder_Drafts {

	const POST_TYPE    = 'lnkup_event_draft';
	const STATE_META   = '_lnkup_builder_state';
	const REV_META     = '_lnkup_builder_revision';
	const SOURCE_META  = '_lnkup_builder_source_event';
	const EVENT_META   = '_lnkup_builder_event_id';
	const MAX_DRAFTS   = 100;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
	}

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Event drafts', 'lnkup-marketplace' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title', 'author' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Owner of a draft, or a site manager.
	 */
	public static function can_edit( int $draft_id, int $user_id ): bool {
		$post = get_post( $draft_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return false;
		}
		if ( (int) $post->post_author === $user_id ) {
			return true;
		}
		return class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all( $user_id );
	}

	/**
	 * @param array<string,mixed> $state Initial state.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function create( int $user_id, array $state, int $source_event = 0 ) {
		if ( $user_id < 1 ) {
			return new WP_Error( 'lnkup_builder_auth', __( 'Sign in to create an event.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}
		$count = count( get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'draft', 'author' => $user_id, 'fields' => 'ids', 'posts_per_page' => self::MAX_DRAFTS + 1, 'no_found_rows' => true ) ) );
		if ( $count >= self::MAX_DRAFTS ) {
			return new WP_Error( 'lnkup_builder_limit', __( 'You have too many drafts. Finish or delete a few first.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		$state = LNKup_Event_Builder_Schema::normalize( $state );
		$id    = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'draft',
				'post_author' => $user_id,
				'post_title'  => '' !== $state['event']['name'] ? $state['event']['name'] : __( 'Untitled event', 'lnkup-marketplace' ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( (int) $id, '_lnkup_organizer_user_id', $user_id );
		update_post_meta( (int) $id, self::STATE_META, wp_slash( (string) wp_json_encode( $state ) ) );
		update_post_meta( (int) $id, self::REV_META, 1 );
		if ( $source_event ) {
			update_post_meta( (int) $id, self::SOURCE_META, $source_event );
		}
		return self::get( (int) $id );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get( int $draft_id ): ?array {
		$post = get_post( $draft_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}
		$raw   = json_decode( (string) get_post_meta( $draft_id, self::STATE_META, true ), true );
		$state = LNKup_Event_Builder_Schema::normalize( $raw );
		return array(
			'id'           => $draft_id,
			'revision'     => (int) get_post_meta( $draft_id, self::REV_META, true ),
			'state'        => $state,
			'source_event' => (int) get_post_meta( $draft_id, self::SOURCE_META, true ),
			'event_id'     => (int) get_post_meta( $draft_id, self::EVENT_META, true ),
			'title'        => $post->post_title,
			'updated_at'   => get_post_modified_time( 'c', true, $post ),
			'author'       => (int) $post->post_author,
		);
	}

	/**
	 * Merge a patch into the draft when the caller holds the current revision.
	 *
	 * @param array<string,mixed> $patch Partial state.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function save( int $draft_id, array $patch, int $revision ) {
		global $wpdb;
		$lock = 'lnkup_draft_' . $draft_id;
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'lnkup_builder_busy', __( 'Still saving. Try again in a moment.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		try {
			$draft = self::get( $draft_id );
			if ( ! $draft ) {
				return new WP_Error( 'lnkup_builder_missing', __( 'This draft no longer exists.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
			}
			if ( $revision !== $draft['revision'] ) {
				return new WP_Error(
					'lnkup_builder_conflict',
					__( 'This draft was changed in another window or by a teammate. Reload to see the latest version.', 'lnkup-marketplace' ),
					array( 'status' => 409, 'revision' => $draft['revision'] )
				);
			}
			$state = LNKup_Event_Builder_Schema::merge( $draft['state'], $patch );
			if ( LNKup_Event_Builder_Schema::too_large( $state ) ) {
				return new WP_Error( 'lnkup_builder_too_large', __( 'This draft is too large to save.', 'lnkup-marketplace' ), array( 'status' => 413 ) );
			}
			update_post_meta( $draft_id, self::STATE_META, wp_slash( (string) wp_json_encode( $state ) ) );
			update_post_meta( $draft_id, self::REV_META, $draft['revision'] + 1 );
			wp_update_post(
				array(
					'ID'         => $draft_id,
					'post_title' => '' !== $state['event']['name'] ? $state['event']['name'] : __( 'Untitled event', 'lnkup-marketplace' ),
				)
			);
			return self::get( $draft_id );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Drafts for the draft portfolio (newest first).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_for( int $user_id, int $limit = 50 ): array {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'draft',
				'author'         => $user_id,
				'fields'         => 'ids',
				'posts_per_page' => min( 100, $limit ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$draft = self::get( (int) $id );
			if ( ! $draft ) {
				continue;
			}
			$report = LNKup_Event_Builder_Validator::report( $draft['state'], $user_id );
			$out[]  = array(
				'id'           => (int) $id,
				'title'        => $draft['state']['event']['name'],
				'method'       => $draft['state']['method'],
				'step'         => $draft['state']['step'],
				'updated_at'   => $draft['updated_at'],
				'progress'     => $report['progress'],
				'missing'      => array_slice( wp_list_pluck( array_filter( $report['checks'], static fn( $c ) => 'required' === $c['level'] && ! $c['ok'] ), 'label' ), 0, 4 ),
				'source'       => $draft['state']['sources'][0]['provider'] ?? '',
				'editing'      => $draft['source_event'],
			);
		}
		return $out;
	}

	/**
	 * Delete a draft (drafts never hold applications or payments).
	 */
	public static function delete( int $draft_id ): bool {
		return (bool) wp_delete_post( $draft_id, true );
	}

	/**
	 * Mark a draft as published into an event and remove it from the portfolio.
	 */
	public static function mark_published( int $draft_id, int $event_id ): void {
		update_post_meta( $draft_id, self::EVENT_META, $event_id );
		wp_trash_post( $draft_id );
	}
}
