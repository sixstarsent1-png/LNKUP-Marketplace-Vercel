<?php
/**
 * Fires lifecycle hooks when canonical status meta changes.
 *
 * Many services write status meta directly with update_post_meta(). This class
 * watches those keys in one place so other plugins (lnkup-communications) get a
 * reliable `*_status_changed` signal without every write site being edited.
 * It never changes data.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Lifecycle_Hooks {

	/**
	 * post_type => meta_key => hook name. Hook args: ( $post_id, $old, $new, $meta_key ).
	 */
	const WATCHED = array(
		'lnkup_payment'       => array( '_lnkup_payment_status' => 'lnkup_payment_status_changed' ),
		'lnkup_event'         => array(
			'_lnkup_event_status' => 'lnkup_event_status_changed',
			'_lnkup_status'       => 'lnkup_event_status_changed',
		),
		'lnkup_opportunity'   => array( '_lnkup_opportunity_status' => 'lnkup_opportunity_status_changed' ),
		'lnkup_document'      => array( '_lnkup_verification_status' => 'lnkup_document_status_changed' ),
		'lnkup_vendor_invite' => array( '_lnkup_status' => 'lnkup_vendor_invite_status_changed' ),
	);

	/**
	 * Old values captured before an update, keyed "post_id|meta_key".
	 *
	 * @var array<string, string>
	 */
	private static $pending = array();

	public static function init() {
		add_filter( 'update_post_metadata', array( __CLASS__, 'capture_old' ), 999, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_updated' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_added' ), 10, 4 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_post_status' ), 10, 3 );
		add_action( 'lnkup_event_status_changed', array( __CLASS__, 'maybe_first_live' ), 5, 3 );
		add_action( 'lnkup_opportunity_status_changed', array( __CLASS__, 'maybe_opportunity_published' ), 5, 3 );
	}

	/**
	 * Fire `lnkup_opportunity_published( $opp_id, $organizer_id )` once, the first time a vendor
	 * slot opens for applications.
	 *
	 * @param int    $opp_id Opportunity ID.
	 * @param string $old    Previous status.
	 * @param string $new    New status.
	 */
	public static function maybe_opportunity_published( $opp_id, $old, $new ) {
		unset( $old );
		if ( 'open' !== $new || 'lnkup_opportunity' !== get_post_type( (int) $opp_id ) ) {
			return;
		}
		if ( ! add_post_meta( (int) $opp_id, '_lnkup_first_opened_at', current_time( 'mysql' ), true ) ) {
			return;
		}
		$organizer_id = (int) get_post_meta( (int) $opp_id, '_lnkup_organizer_user_id', true );
		if ( ! $organizer_id ) {
			$organizer_id = (int) get_post_field( 'post_author', (int) $opp_id );
		}
		try {
			do_action( 'lnkup_opportunity_published', (int) $opp_id, $organizer_id );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup lnkup_opportunity_published listener failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Fire `lnkup_event_first_live( $event_id, $organizer_id )` exactly once, the first time an
	 * event's listing goes live (has an open vendor slot). Events are inserted as published
	 * posts before their slots exist, so post publication is not a usable milestone.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $old      Previous status.
	 * @param string $new      New status.
	 */
	public static function maybe_first_live( $event_id, $old, $new ) {
		unset( $old );
		if ( 'live' !== $new || 'lnkup_event' !== get_post_type( (int) $event_id ) ) {
			return;
		}
		if ( ! add_post_meta( (int) $event_id, '_lnkup_first_published_at', current_time( 'mysql' ), true ) ) {
			return;
		}
		$organizer_id = (int) get_post_meta( (int) $event_id, '_lnkup_organizer_user_id', true );
		if ( ! $organizer_id ) {
			$organizer_id = (int) get_post_field( 'post_author', (int) $event_id );
		}
		try {
			do_action( 'lnkup_event_first_live', (int) $event_id, $organizer_id );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup lnkup_event_first_live listener failed: ' . $e->getMessage() );
		}
	}

	/**
	 * @param string $meta_key Meta key.
	 * @param int    $post_id  Post ID.
	 * @return string Hook name, or ''.
	 */
	private static function hook_for( $meta_key, $post_id ) {
		// Runs on every post meta write site-wide: reject unrelated keys before any lookup.
		if ( ! in_array( $meta_key, array( '_lnkup_payment_status', '_lnkup_event_status', '_lnkup_status', '_lnkup_opportunity_status', '_lnkup_verification_status' ), true ) ) {
			return '';
		}
		$type = get_post_type( $post_id );
		if ( ! $type || ! isset( self::WATCHED[ $type ][ $meta_key ] ) ) {
			return '';
		}
		return self::WATCHED[ $type ][ $meta_key ];
	}

	/**
	 * @param mixed  $check      Short-circuit value (returned unchanged).
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 * @return mixed
	 */
	public static function capture_old( $check, $object_id, $meta_key, $meta_value ) {
		unset( $meta_value );
		if ( null === $check && '' !== self::hook_for( $meta_key, (int) $object_id ) ) {
			self::$pending[ (int) $object_id . '|' . $meta_key ] = (string) get_post_meta( (int) $object_id, $meta_key, true );
		}
		return $check;
	}

	/**
	 * @param int    $meta_id    Meta row.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 */
	public static function on_updated( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id );
		$key = (int) $object_id . '|' . $meta_key;
		if ( ! array_key_exists( $key, self::$pending ) ) {
			return;
		}
		$old = self::$pending[ $key ];
		unset( self::$pending[ $key ] );
		self::fire( (int) $object_id, $meta_key, $old, is_scalar( $meta_value ) ? (string) $meta_value : '' );
	}

	/**
	 * @param int    $meta_id    Meta row.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 */
	public static function on_added( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id );
		$key = (int) $object_id . '|' . $meta_key;
		$old = self::$pending[ $key ] ?? '';
		unset( self::$pending[ $key ] );
		self::fire( (int) $object_id, $meta_key, $old, is_scalar( $meta_value ) ? (string) $meta_value : '' );
	}

	/**
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param string $old      Previous value.
	 * @param string $new      New value.
	 */
	private static function fire( $post_id, $meta_key, $old, $new ) {
		$hook = self::hook_for( $meta_key, $post_id );
		if ( '' === $hook || $old === $new ) {
			return;
		}
		try {
			do_action( $hook, $post_id, $old, $new, $meta_key );
		} catch ( Throwable $e ) {
			// A listener failure must never break the business write.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup lifecycle hook ' . $hook . ' failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Opportunity removed (trashed).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_trash( $post_id ) {
		$type = get_post_type( $post_id );
		if ( 'lnkup_opportunity' === $type ) {
			do_action( 'lnkup_opportunity_removed', (int) $post_id );
		} elseif ( 'lnkup_event' === $type ) {
			do_action( 'lnkup_event_removed', (int) $post_id );
		}
	}

	/**
	 * Event or opportunity published for the first time.
	 *
	 * @param string  $new  New post status.
	 * @param string  $old  Old post status.
	 * @param WP_Post $post Post.
	 */
	public static function on_post_status( $new, $old, $post ) {
		if ( ! $post instanceof WP_Post || 'publish' !== $new || 'publish' === $old ) {
			return;
		}
		if ( 'lnkup_event' === $post->post_type ) {
			do_action( 'lnkup_event_published', (int) $post->ID );
		}
	}
}
