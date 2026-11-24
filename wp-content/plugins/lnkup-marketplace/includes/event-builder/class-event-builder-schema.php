<?php
/**
 * Builder state contract: every field the creation flow stores, sanitized and bounded.
 *
 * The state is creation scratch space only. Publishing maps it onto the existing event and
 * opportunity services; nothing reads it as a source of truth for a live event.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Builder_Schema {

	const METHODS        = array( 'single', 'recurring', 'tour', 'series', 'import', 'duplicate', 'template', 'bulk', 'edit' );
	const STEPS          = array( 'info', 'options', 'applications', 'payments', 'publish' );
	const FORMATS        = array( 'outdoor', 'indoor', 'both', 'tba' );
	const APP_MODES      = array( 'review', 'invite', 'closed' );
	const QUESTION_TYPES = array( 'short', 'long', 'choice', 'yesno', 'number', 'file', 'ack' );
	const DOC_TIMING     = array( 'before_approval', 'before_event' );
	const DOCUMENTS      = array( 'certificate_of_insurance', 'health_permit', 'business_license', 'w9' );
	const AMENITIES      = array( 'electricity', 'table', 'chairs', 'tent', 'water', 'parking', 'wifi', 'extra_credentials' );
	const MAX_OPTIONS    = 30;
	const MAX_QUESTIONS  = 20;
	const MAX_SOURCES    = 6;
	const MAX_BYTES      = 200000;

	/**
	 * Empty state for a method.
	 *
	 * @return array<string,mixed>
	 */
	public static function blank( string $method = 'single' ): array {
		return self::normalize( array( 'method' => $method ) );
	}

	/**
	 * @param mixed $raw Raw state (decoded JSON).
	 * @return array<string,mixed>
	 */
	public static function normalize( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$ev  = is_array( $raw['event'] ?? null ) ? $raw['event'] : array();
		$app = is_array( $raw['applications'] ?? null ) ? $raw['applications'] : array();
		$pay = is_array( $raw['payments'] ?? null ) ? $raw['payments'] : array();
		$pub = is_array( $raw['publish'] ?? null ) ? $raw['publish'] : array();
		$att = is_array( $ev['attendance'] ?? null ) ? $ev['attendance'] : array();
		$dep = is_array( $pay['deposit'] ?? null ) ? $pay['deposit'] : array();
		$ddl = is_array( $pay['deadline'] ?? null ) ? $pay['deadline'] : array();
		$doc = is_array( $app['documents'] ?? null ) ? $app['documents'] : array();
		$imp = is_array( $raw['import'] ?? null ) ? $raw['import'] : array();
		$tpl = is_array( $raw['template'] ?? null ) ? $raw['template'] : array();

		$state = array(
			'method'       => self::pick( $raw['method'] ?? '', self::METHODS, 'single' ),
			'step'         => self::pick( $raw['step'] ?? '', self::STEPS, 'info' ),
			'quick'        => ! empty( $raw['quick'] ),
			'event'        => array(
				'name'               => self::text( $ev['name'] ?? '', 140 ),
				'event_type'         => sanitize_title( (string) ( $ev['event_type'] ?? '' ) ),
				'brand_id'           => absint( $ev['brand_id'] ?? 0 ),
				'tour_id'            => absint( $ev['tour_id'] ?? 0 ),
				'summary'            => self::text( $ev['summary'] ?? '', 200 ),
				'description'        => mb_substr( sanitize_textarea_field( (string) ( $ev['description'] ?? '' ) ), 0, 6000 ),
				'attendance'         => array(
					'mode'  => self::pick( $att['mode'] ?? '', array( 'unknown', 'estimate', 'range' ), 'unknown' ),
					'value' => min( 5000000, absint( $att['value'] ?? 0 ) ),
					'min'   => min( 5000000, absint( $att['min'] ?? 0 ) ),
					'max'   => min( 5000000, absint( $att['max'] ?? 0 ) ),
				),
				'format'             => self::pick( $ev['format'] ?? '', self::FORMATS, 'outdoor' ),
				'date'               => self::date( $ev['date'] ?? '' ),
				'end_date'           => self::date( $ev['end_date'] ?? '' ),
				'start_time'         => self::time( $ev['start_time'] ?? '' ),
				'end_time'           => self::time( $ev['end_time'] ?? '' ),
				'timezone'           => self::timezone( $ev['timezone'] ?? '' ),
				'date_tba'           => ! empty( $ev['date_tba'] ),
				'venue_name'         => self::text( $ev['venue_name'] ?? '', 160 ),
				'venue_address'      => self::text( $ev['venue_address'] ?? '', 240 ),
				'city'               => self::text( $ev['city'] ?? '', 80 ),
				'state'              => strtoupper( self::text( $ev['state'] ?? '', 40 ) ),
				'country'            => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $ev['country'] ?? 'US' ) ), 0, 2 ) ) ?: 'US',
				'postal_code'        => self::text( $ev['postal_code'] ?? '', 12 ),
				'venue_tba'          => ! empty( $ev['venue_tba'] ),
				'venue_hidden'       => ! empty( $ev['venue_hidden'] ),
				'market_city'        => self::text( $ev['market_city'] ?? '', 80 ),
				'location_confirmed' => ! empty( $ev['location_confirmed'] ),
				'featured_image_id'  => absint( $ev['featured_image_id'] ?? 0 ),
				'flyer_id'           => absint( $ev['flyer_id'] ?? 0 ),
				'gallery_ids'        => array_slice( array_values( array_filter( array_map( 'absint', (array) ( $ev['gallery_ids'] ?? array() ) ) ) ), 0, 12 ),
				'image_focus'        => self::pick( $ev['image_focus'] ?? '', array( 'center', 'top', 'bottom' ), 'center' ),
				'power_available'    => ! empty( $ev['power_available'] ),
			),
			'sources'      => self::sources( (array) ( $raw['sources'] ?? array() ) ),
			'options'      => self::options( (array) ( $raw['options'] ?? array() ) ),
			'applications' => array(
				'mode'      => self::pick( $app['mode'] ?? '', self::APP_MODES, 'review' ),
				'questions' => self::questions( (array) ( $app['questions'] ?? array() ) ),
				'documents' => array(
					'mode'  => self::pick( $doc['mode'] ?? '', array( 'none', 'platform_defaults', 'custom' ), 'none' ),
					'items' => self::documents( (array) ( $doc['items'] ?? array() ) ),
				),
			),
			'payments'     => array(
				'deposit'           => array(
					'enabled' => ! empty( $dep['enabled'] ),
					'type'    => self::pick( $dep['type'] ?? '', array( 'percent', 'fixed' ), 'percent' ),
					'value'   => max( 0, min( 100000, (float) ( $dep['value'] ?? 0 ) ) ),
				),
				'deadline'          => array(
					'mode'        => self::pick( $ddl['mode'] ?? '', array( 'default', 'fixed', 'relative' ), 'default' ),
					'date'        => self::date( $ddl['date'] ?? '' ),
					'days_before' => min( 365, absint( $ddl['days_before'] ?? 7 ) ),
				),
				'cancellation_note' => mb_substr( sanitize_textarea_field( (string) ( $pay['cancellation_note'] ?? '' ) ), 0, 1200 ),
			),
			'publish'      => array(
				'when'         => self::pick( $pub['when'] ?? '', array( 'now', 'schedule' ), 'now' ),
				'at'           => self::datetime( $pub['at'] ?? '' ),
				'applications' => self::pick( $pub['applications'] ?? '', array( 'open', 'closed' ), 'open' ),
				'attest'       => ! empty( $pub['attest'] ),
			),
			'provenance'   => self::provenance( (array) ( $raw['provenance'] ?? array() ) ),
			'import'       => array(
				'provider'          => sanitize_key( (string) ( $imp['provider'] ?? '' ) ),
				'external_event_id' => self::text( $imp['external_event_id'] ?? '', 100 ),
				'original_url'      => esc_url_raw( (string) ( $imp['original_url'] ?? '' ) ),
				'source_city'       => self::text( $imp['source_city'] ?? '', 80 ),
				'source_state'      => self::text( $imp['source_state'] ?? '', 40 ),
				'source_id'         => absint( $imp['source_id'] ?? 0 ),
				'conflict'          => self::text( $imp['conflict'] ?? '', 200 ),
				'associate_event'   => absint( $imp['associate_event'] ?? 0 ),
			),
			'template'     => array(
				'id'      => absint( $tpl['id'] ?? 0 ),
				'version' => absint( $tpl['version'] ?? 0 ),
			),
			'duplicate_of' => absint( $raw['duplicate_of'] ?? 0 ),
			'confirmed'    => array_values( array_intersect( array( 'location', 'capacity', 'deadline', 'external_link' ), array_map( 'sanitize_key', (array) ( $raw['confirmed'] ?? array() ) ) ) ),
			'portfolio'    => self::portfolio( (array) ( $raw['portfolio'] ?? array() ) ),
		);
		return $state;
	}

	/**
	 * Merge a partial patch (one step's fields) into a state.
	 *
	 * @param array<string,mixed> $state Current.
	 * @param array<string,mixed> $patch Partial.
	 * @return array<string,mixed>
	 */
	public static function merge( array $state, array $patch ): array {
		return self::normalize( self::deep_merge( $state, $patch ) );
	}

	/**
	 * Associative parts merge key by key; lists (options, sources, questions…) are replaced.
	 *
	 * @param array<mixed> $base  Base.
	 * @param array<mixed> $patch Patch.
	 * @return array<mixed>
	 */
	private static function deep_merge( array $base, array $patch ): array {
		foreach ( $patch as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && self::is_assoc( $value ) && self::is_assoc( $base[ $key ] ) ) {
				$base[ $key ] = self::deep_merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/**
	 * @param array<string,mixed> $state State.
	 */
	public static function too_large( array $state ): bool {
		return strlen( (string) wp_json_encode( $state ) ) > self::MAX_BYTES;
	}

	/* ------------------------------------------------------------ Parts */

	/**
	 * @param array<int,mixed> $raw Rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function options( array $raw ): array {
		$out  = array();
		$keys = array();
		foreach ( array_slice( array_values( $raw ), 0, self::MAX_OPTIONS ) as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $row['key'] ?? '' ) );
			if ( '' === $key || isset( $keys[ $key ] ) ) {
				$key = 'o' . substr( md5( wp_json_encode( $row ) . $i ), 0, 8 );
			}
			$keys[ $key ] = true;
			$out[]        = array(
				'key'         => $key,
				'name'        => self::text( $row['name'] ?? '', 100 ),
				'category'    => self::text( $row['category'] ?? '', 60 ),
				'fee'         => round( max( 0, min( 100000, (float) ( $row['fee'] ?? 0 ) ) ), 2 ),
				'spots'       => min( 5000, absint( $row['spots'] ?? 0 ) ),
				'width_ft'    => min( 500, absint( $row['width_ft'] ?? 0 ) ),
				'depth_ft'    => min( 500, absint( $row['depth_ft'] ?? 0 ) ),
				'amenities'   => array_values( array_intersect( self::AMENITIES, array_map( 'sanitize_key', (array) ( $row['amenities'] ?? array() ) ) ) ),
				'description' => mb_substr( sanitize_textarea_field( (string) ( $row['description'] ?? '' ) ), 0, 1000 ),
				'deadline'    => self::date( $row['deadline'] ?? '' ),
				'opportunity_id' => absint( $row['opportunity_id'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * @param array<int,mixed> $raw Questions.
	 * @return array<int,array<string,mixed>>
	 */
	public static function questions( array $raw ): array {
		$out = array();
		foreach ( array_slice( array_values( $raw ), 0, self::MAX_QUESTIONS ) as $i => $q ) {
			if ( ! is_array( $q ) ) {
				continue;
			}
			$label = self::text( $q['label'] ?? '', 200 );
			$type  = self::pick( $q['type'] ?? '', self::QUESTION_TYPES, 'short' );
			$out[] = array(
				'id'       => sanitize_key( (string) ( $q['id'] ?? '' ) ) ?: 'q' . substr( md5( $label . $i ), 0, 8 ),
				'label'    => $label,
				'type'     => $type,
				'required' => ! empty( $q['required'] ),
				'help'     => self::text( $q['help'] ?? '', 300 ),
				'options'  => 'choice' === $type ? array_slice( array_values( array_filter( array_map( static fn( $o ) => self::text( $o, 80 ), (array) ( $q['options'] ?? array() ) ) ) ), 0, 20 ) : array(),
				'scope'    => self::text( $q['scope'] ?? 'all', 60 ) ?: 'all',
			);
		}
		return $out;
	}

	/**
	 * @param array<int,mixed> $raw Document rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function documents( array $raw ): array {
		$out  = array();
		$seen = array();
		foreach ( $raw as $d ) {
			$key = sanitize_key( (string) ( is_array( $d ) ? ( $d['key'] ?? '' ) : $d ) );
			if ( ! in_array( $key, self::DOCUMENTS, true ) || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'key'      => $key,
				'timing'   => self::pick( is_array( $d ) ? ( $d['timing'] ?? '' ) : '', self::DOC_TIMING, 'before_event' ),
				'required' => ! is_array( $d ) || ! isset( $d['required'] ) || ! empty( $d['required'] ),
			);
		}
		return $out;
	}

	/**
	 * @param array<int,mixed> $raw Sources.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sources( array $raw ): array {
		$out     = array();
		$primary = false;
		$seen    = array();
		foreach ( array_slice( array_values( $raw ), 0, self::MAX_SOURCES ) as $s ) {
			$url = is_array( $s ) ? (string) ( $s['url'] ?? '' ) : (string) $s;
			$url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
			if ( '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$is_primary   = ! $primary && ( ! is_array( $s ) || ! empty( $s['primary'] ) );
			$primary      = $primary || $is_primary;
			$out[]        = array(
				'url'      => $url,
				'provider' => sanitize_key( (string) ( is_array( $s ) ? ( $s['provider'] ?? '' ) : '' ) ),
				'label'    => self::text( is_array( $s ) ? ( $s['label'] ?? '' ) : '', 60 ),
				'primary'  => $is_primary,
				'mode'     => self::pick( is_array( $s ) ? ( $s['mode'] ?? '' ) : '', array( 'link', 'import', 'account' ), 'link' ),
			);
		}
		if ( $out && ! $primary ) {
			$out[0]['primary'] = true;
		}
		return $out;
	}

	/**
	 * Recurring / series / tour settings (Phases 9–10).
	 *
	 * @param array<string,mixed> $raw Raw.
	 * @return array<string,mixed>
	 */
	public static function portfolio( array $raw ): array {
		$rec   = is_array( $raw['recurrence'] ?? null ) ? $raw['recurrence'] : array();
		$stops = array();
		foreach ( array_slice( array_values( (array) ( $raw['stops'] ?? array() ) ), 0, 60 ) as $i => $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$stops[] = array(
				'key'           => sanitize_key( (string) ( $s['key'] ?? '' ) ) ?: 's' . substr( md5( wp_json_encode( $s ) . $i ), 0, 8 ),
				'event_id'      => absint( $s['event_id'] ?? 0 ),
				'date'          => self::date( $s['date'] ?? '' ),
				'start_time'    => self::time( $s['start_time'] ?? '' ),
				'end_time'      => self::time( $s['end_time'] ?? '' ),
				'timezone'      => '' !== (string) ( $s['timezone'] ?? '' ) ? self::timezone( $s['timezone'] ) : '',
				'city'          => self::text( $s['city'] ?? '', 80 ),
				'state'         => strtoupper( self::text( $s['state'] ?? '', 40 ) ),
				'market_city'   => self::text( $s['market_city'] ?? '', 80 ),
				'venue_name'    => self::text( $s['venue_name'] ?? '', 160 ),
				'venue_address' => self::text( $s['venue_address'] ?? '', 240 ),
				'url'           => esc_url_raw( (string) ( $s['url'] ?? '' ), array( 'http', 'https' ) ),
				'fee_override'  => isset( $s['fee_override'] ) && '' !== (string) $s['fee_override'] ? round( max( 0, (float) $s['fee_override'] ), 2 ) : null,
				'spots_override' => isset( $s['spots_override'] ) && '' !== (string) $s['spots_override'] ? absint( $s['spots_override'] ) : null,
				'skip'          => ! empty( $s['skip'] ),
				'confirmed'     => ! empty( $s['confirmed'] ),
			);
		}
		return array(
			'name'       => self::text( $raw['name'] ?? '', 140 ),
			'tour_id'    => absint( $raw['tour_id'] ?? 0 ),
			'recurrence' => array(
				'frequency'   => self::pick( $rec['frequency'] ?? '', array( 'weekly', 'biweekly', 'monthly_weekday', 'monthly_date', 'custom' ), 'weekly' ),
				'weekdays'    => array_values( array_intersect( array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ), array_map( 'sanitize_key', (array) ( $rec['weekdays'] ?? array() ) ) ) ),
				'start'       => self::date( $rec['start'] ?? '' ),
				'until'       => self::date( $rec['until'] ?? '' ),
				'count'       => min( 52, absint( $rec['count'] ?? 0 ) ),
				'exclude'     => array_values( array_filter( array_map( array( __CLASS__, 'date' ), (array) ( $rec['exclude'] ?? array() ) ) ) ),
				'dates'       => array_slice( array_values( array_filter( array_map( array( __CLASS__, 'date' ), (array) ( $rec['dates'] ?? array() ) ) ) ), 0, 52 ),
			),
			'stops'      => $stops,
		);
	}

	/**
	 * @param array<string,mixed> $raw Field => origin.
	 * @return array<string,string>
	 */
	private static function provenance( array $raw ): array {
		$out = array();
		foreach ( array_slice( $raw, 0, 60, true ) as $field => $origin ) {
			$field = preg_replace( '/[^a-z0-9_.]/', '', strtolower( (string) $field ) );
			if ( '' !== $field ) {
				$out[ $field ] = self::pick( $origin, array( 'imported', 'edited', 'template', 'duplicate', 'tour' ), 'edited' );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------ Scalars */

	/**
	 * @param mixed    $value   Value.
	 * @param string[] $allowed Allowed.
	 */
	public static function pick( $value, array $allowed, string $default ): string {
		$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * @param mixed $value Value.
	 */
	public static function text( $value, int $max ): string {
		$value = is_scalar( $value ) ? html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
		$value = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( $value ) ) );
		return mb_substr( $value, 0, $max );
	}

	/**
	 * @param mixed $value Value.
	 */
	public static function date( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * @param mixed $value Value.
	 */
	public static function time( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : '';
	}

	/**
	 * @param mixed $value Value.
	 */
	public static function datetime( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return preg_match( '/^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : '';
	}

	/**
	 * @param mixed $value Value.
	 */
	public static function timezone( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' !== $value && in_array( $value, timezone_identifiers_list(), true ) ) {
			return $value;
		}
		$site = wp_timezone_string();
		return in_array( $site, timezone_identifiers_list(), true ) ? $site : 'America/New_York';
	}

	/**
	 * @param array<mixed> $arr Array.
	 */
	private static function is_assoc( array $arr ): bool {
		return array() !== $arr && array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}
}
