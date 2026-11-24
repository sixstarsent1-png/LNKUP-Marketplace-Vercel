<?php
/**
 * External source sync: re-read an event's official listing and propose changes for review.
 *
 * Nothing is applied automatically. Each proposed change says whether the organizer edited that
 * field since the import (then the listing never overwrites it unless the organizer explicitly
 * accepts). Accepted changes go through Edit 2.0 (LNKup_Event_Builder_Editor::apply), so booked
 * vendors get the same material-change review and notifications as a manual edit.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Source_Sync {

	const CRON       = 'lnkup_event_source_sync';
	const BATCH      = 20;
	const FIELDS     = array( 'name', 'date', 'end_date', 'start_time', 'end_time', 'venue_name', 'venue_address', 'city', 'state', 'postal_code', 'description' );
	const IMPORT_MAP = array( 'address' => 'venue_address', 'zip' => 'postal_code' );

	public static function init(): void {
		add_action( self::CRON, array( __CLASS__, 'run_due' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
	}

	public static function enabled(): bool {
		return class_exists( 'LNKup_Event_OS_Flags' ) && LNKup_Event_OS_Flags::enabled( 'event_source_sync' );
	}

	public static function schedule(): void {
		if ( self::enabled() && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		} elseif ( ! self::enabled() && wp_next_scheduled( self::CRON ) ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/**
	 * Daily: imported/account sources of upcoming events not checked in the last day.
	 */
	public static function run_due(): void {
		global $wpdb;
		if ( ! self::enabled() ) {
			return;
		}
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Event_Import_Tables::sources() . " WHERE event_id > 0 AND mode IN ('import','account') AND availability <> 'removed' AND ( last_attempt_at IS NULL OR last_attempt_at < %s ) ORDER BY last_attempt_at ASC LIMIT %d", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), self::BATCH ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $ids as $id ) {
			$src = LNKup_Event_Sources::get( (int) $id );
			if ( $src && (string) get_post_meta( $src['event_id'], '_lnkup_event_date', true ) >= current_time( 'Y-m-d' ) ) {
				self::check( (int) $id );
			}
		}
	}

	/**
	 * Re-read one source and store the proposed changes.
	 *
	 * @return array<string,mixed>|WP_Error Formatted source.
	 */
	public static function check( int $source_id ) {
		$src = LNKup_Event_Sources::get( $source_id );
		if ( ! $src || ! $src['event_id'] ) {
			return new WP_Error( 'lnkup_source_missing', __( 'This listing is no longer linked.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$owner = (int) get_post_meta( $src['event_id'], '_lnkup_organizer_user_id', true );
		$now   = current_time( 'mysql', true );
		LNKup_Event_Sources::update( $source_id, array( 'last_attempt_at' => $now ) );

		$incoming = self::incoming( $src, $owner );
		if ( is_wp_error( $incoming ) ) {
			$code = $incoming->get_error_code();
			if ( in_array( $code, array( 'lnkup_fetch_status' ), true ) && 404 === (int) ( $incoming->get_error_data()['status'] ?? 0 ) ) {
				LNKup_Event_Sources::update( $source_id, array( 'availability' => 'removed' ) );
			}
			return $incoming;
		}
		$changes = self::diff( $src, $incoming['fields'] );
		LNKup_Event_Sources::update(
			$source_id,
			array(
				'availability'    => $incoming['availability'],
				'last_sync_at'    => $now,
				'source_version'  => substr( md5( (string) wp_json_encode( $incoming['fields'] ) ), 0, 16 ),
				'pending_changes' => $changes ? wp_json_encode( array( 'checked_at' => gmdate( 'c' ), 'changes' => $changes, 'warnings' => $incoming['warnings'] ) ) : '',
			)
		);
		if ( $changes ) {
			do_action( 'lnkup_event_source_changes', $src['event_id'], $source_id, $changes );
		}
		return LNKup_Event_Sources::get( $source_id );
	}

	/**
	 * Current listing values (public page or connected account), in builder field names.
	 *
	 * @param array<string,mixed> $src Source.
	 * @return array{fields:array<string,string>,availability:string,warnings:string[]}|WP_Error
	 */
	private static function incoming( array $src, int $owner ) {
		if ( 'account' === $src['mode'] && 'eventbrite' === $src['provider'] && class_exists( 'LNKup_Eventbrite_Connection' ) ) {
			$preview = LNKup_Eventbrite_Connection::event_preview( $owner, (string) $src['external_event_id'] );
			if ( ! $preview ) {
				return new WP_Error( 'lnkup_source_unavailable', __( 'This event is no longer available in your Eventbrite account.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
			}
		} else {
			$prev = get_current_user_id();
			wp_set_current_user( $owner );
			$preview = LNKup_Event_Import_Service::preview( $src['url'], $owner, false );
			wp_set_current_user( $prev );
			if ( is_wp_error( $preview ) ) {
				return $preview;
			}
			if ( 'unreachable' === $preview['status'] ) {
				return new WP_Error( 'lnkup_fetch_status', $preview['message'], array( 'status' => (int) $preview['http_status'] ) );
			}
		}
		$fields = array();
		foreach ( (array) $preview['fields'] as $k => $v ) {
			$key = self::IMPORT_MAP[ $k ] ?? $k;
			if ( in_array( $key, self::FIELDS, true ) && is_scalar( $v ) ) {
				$fields[ $key ] = (string) $v;
			}
		}
		$cancelled = (bool) array_filter( (array) $preview['warnings'], static fn( $w ) => false !== stripos( (string) $w, 'cancelled' ) );
		return array( 'fields' => $fields, 'availability' => $cancelled ? 'cancelled' : 'available', 'warnings' => array_values( (array) $preview['warnings'] ) );
	}

	/**
	 * @param array<string,mixed>  $src      Source.
	 * @param array<string,string> $incoming Listing values.
	 * @return array<int,array{field:string,current:string,incoming:string,imported:string,edited:bool,material:bool}>
	 */
	public static function diff( array $src, array $incoming ): array {
		$event    = LNKup_Event_Builder_Editor::state_from_event( (int) $src['event_id'] )['event'];
		$snapshot = (array) ( $src['provenance']['values'] ?? array() );
		$out      = array();
		foreach ( $incoming as $field => $value ) {
			$current = (string) ( $event[ $field ] ?? '' );
			if ( '' === $value || self::same( $field, $current, $value ) ) {
				continue;
			}
			$imported = isset( $snapshot[ $field ] ) ? (string) $snapshot[ $field ] : null;
			if ( null !== $imported && self::same( $field, $value, $imported ) ) {
				continue; // The listing has not changed since it was imported or last reviewed.
			}
			$out[]    = array(
				'field'    => $field,
				'current'  => $current,
				'incoming' => $value,
				'imported' => (string) $imported,
				'edited'   => null === $imported ? '' !== $current : ! self::same( $field, $current, $imported ),
				'material' => in_array( 'event.' . $field, LNKup_Event_Builder_Editor::MATERIAL, true ),
			);
		}
		return $out;
	}

	private static function same( string $field, string $a, string $b ): bool {
		if ( 'description' === $field ) {
			return preg_replace( '/\s+/', ' ', trim( $a ) ) === preg_replace( '/\s+/', ' ', trim( $b ) );
		}
		return 0 === strcasecmp( trim( $a ), trim( $b ) );
	}

	/**
	 * Apply the organizer-selected changes through Edit 2.0.
	 *
	 * @param string[] $fields Fields to take from the listing.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function apply( int $source_id, array $fields, int $user_id, bool $confirm ) {
		$src = LNKup_Event_Sources::get( $source_id );
		if ( ! $src || empty( $src['pending_changes']['changes'] ) ) {
			return new WP_Error( 'lnkup_source_nothing', __( 'There are no listing changes to apply.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$event_id = (int) $src['event_id'];
		$pick     = array();
		foreach ( $src['pending_changes']['changes'] as $c ) {
			if ( in_array( $c['field'], $fields, true ) ) {
				$pick[ $c['field'] ] = $c['incoming'];
			}
		}
		if ( ! $pick ) {
			return new WP_Error( 'lnkup_source_nothing', __( 'Choose at least one change to apply.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$draft = LNKup_Event_Builder_Drafts::create( $user_id, LNKup_Event_Builder_Editor::state_from_event( $event_id ), $event_id );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		$patch = array( 'event' => $pick );
		if ( isset( $pick['city'] ) || isset( $pick['state'] ) ) {
			$patch['event']['location_confirmed'] = true;
		}
		$saved = LNKup_Event_Builder_Drafts::save( (int) $draft['id'], $patch, (int) $draft['revision'] );
		if ( is_wp_error( $saved ) ) {
			wp_delete_post( (int) $draft['id'], true );
			return $saved;
		}
		$result = LNKup_Event_Builder_Editor::apply( (int) $draft['id'], $user_id, $confirm );
		if ( is_wp_error( $result ) ) {
			wp_delete_post( (int) $draft['id'], true );
			return $result;
		}
		$remaining = array_values( array_filter( $src['pending_changes']['changes'], static fn( $c ) => ! isset( $pick[ $c['field'] ] ) ) );
		$values    = (array) ( $src['provenance']['values'] ?? array() );
		foreach ( $pick as $f => $v ) {
			$values[ $f ] = $v;
		}
		LNKup_Event_Sources::update(
			$source_id,
			array(
				'pending_changes' => $remaining ? wp_json_encode( array_merge( $src['pending_changes'], array( 'changes' => $remaining ) ) ) : '',
				'provenance'      => wp_json_encode( array( 'origin' => (array) ( $src['provenance']['origin'] ?? array() ), 'values' => $values ) ),
			)
		);
		return array( 'applied' => array_keys( $pick ), 'remaining' => count( $remaining ), 'result' => $result );
	}

	/**
	 * Keep the event as is; remember the listing values so the same change is not proposed again.
	 */
	public static function dismiss( int $source_id ): void {
		$src = LNKup_Event_Sources::get( $source_id );
		if ( ! $src ) {
			return;
		}
		$values = (array) ( $src['provenance']['values'] ?? array() );
		foreach ( (array) ( $src['pending_changes']['changes'] ?? array() ) as $c ) {
			$values[ $c['field'] ] = $c['incoming'];
		}
		LNKup_Event_Sources::update(
			$source_id,
			array(
				'pending_changes' => '',
				'provenance'      => wp_json_encode( array( 'origin' => (array) ( $src['provenance']['origin'] ?? array() ), 'values' => $values ) ),
			)
		);
	}
}
