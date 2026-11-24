<?php
/**
 * Builder validation: creation progress, publication checklist and fee previews.
 *
 * Three different measures are kept apart:
 * - creation progress: required checks passed / required checks (never 100% with a blocker),
 * - publication readiness: every required check passes,
 * - setup readiness (payouts, packet) is advisory and never blocks a free or review-first event,
 *   matching the existing publish rules.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Builder_Validator {

	/**
	 * @param array<string,mixed> $state   Normalized state.
	 * @param int                 $user_id Organizer.
	 * @return array{progress:int,publishable:bool,checks:array<int,array<string,mixed>>,steps:array<string,string>,location:array<string,mixed>,summary:array<string,mixed>}
	 */
	public static function report( array $state, int $user_id ): array {
		$ev     = $state['event'];
		$checks = array();
		$add    = static function ( string $key, string $step, string $level, bool $ok, string $label, string $message = '' ) use ( &$checks ): void {
			$checks[] = array( 'key' => $key, 'step' => $step, 'level' => $level, 'ok' => $ok, 'label' => $label, 'message' => $ok ? '' : $message );
		};
		$today = current_time( 'Y-m-d' );

		// Event information.
		$add( 'name', 'info', 'required', '' !== $ev['name'], __( 'Event name', 'lnkup-marketplace' ), __( 'Add the event name.', 'lnkup-marketplace' ) );
		$clean = '' === $ev['name'] || ! preg_match( '/\s[|@]\s?|\s\|\s/', $ev['name'] );
		$add( 'name_clean', 'info', 'recommended', $clean, __( 'Clean event name', 'lnkup-marketplace' ), __( 'Keep the name to the event itself. City, venue and date are shown separately.', 'lnkup-marketplace' ) );

		$date_ok = '' !== $ev['date'] && ! $ev['date_tba'];
		$add( 'date', 'info', 'required', $date_ok, __( 'Event date', 'lnkup-marketplace' ), $ev['date_tba'] ? __( 'Choose the date before publishing. You can keep "Date TBA" while it is a draft.', 'lnkup-marketplace' ) : __( 'Choose the event date.', 'lnkup-marketplace' ) );
		if ( $date_ok ) {
			$add( 'date_future', 'info', 'required', self::last_day( $ev ) >= $today, __( 'Date is upcoming', 'lnkup-marketplace' ), __( 'This date has already passed.', 'lnkup-marketplace' ) );
			// Without an end date, an end time earlier than the start is an overnight event.
			$order_ok = '' === $ev['end_date'] || $ev['end_date'] > $ev['date']
				|| ( $ev['end_date'] === $ev['date'] && ( '' === $ev['start_time'] || '' === $ev['end_time'] || $ev['end_time'] > $ev['start_time'] ) );
			$add( 'date_order', 'info', 'required', $order_ok, __( 'End is after start', 'lnkup-marketplace' ), __( 'The event ends before it starts.', 'lnkup-marketplace' ) );
		}

		$location = self::location( $ev );
		$add( 'location', 'info', 'required', 'ok' === $location['state'], __( 'Location', 'lnkup-marketplace' ), (string) $location['message'] );
		$conflict = self::geo_conflict( $state, $location );
		$add( 'geo_conflict', 'info', 'required', '' === $conflict || $ev['location_confirmed'], __( 'Location matches the event', 'lnkup-marketplace' ), $conflict );
		if ( $state['duplicate_of'] ) {
			$add( 'confirm_location', 'info', 'required', in_array( 'location', $state['confirmed'], true ), __( 'Location confirmed for this copy', 'lnkup-marketplace' ), __( 'Confirm the city and venue for this new event. Copies never keep the old location silently.', 'lnkup-marketplace' ) );
		}
		$add( 'venue', 'info', 'recommended', '' !== $ev['venue_name'] || $ev['venue_tba'], __( 'Venue', 'lnkup-marketplace' ), __( 'Add the venue or mark it as TBA.', 'lnkup-marketplace' ) );
		$add( 'image', 'info', 'recommended', $ev['featured_image_id'] > 0 || $ev['flyer_id'] > 0, __( 'Event image', 'lnkup-marketplace' ), __( 'Events with a photo or flyer get more applications. A branded image is used until you add one.', 'lnkup-marketplace' ) );
		$add( 'description', 'info', 'recommended', mb_strlen( $ev['description'] ) >= 80, __( 'Description', 'lnkup-marketplace' ), __( 'Tell vendors what the event is like and who attends.', 'lnkup-marketplace' ) );
		$add( 'attendance', 'info', 'optional', 'unknown' !== $ev['attendance']['mode'], __( 'Expected attendance', 'lnkup-marketplace' ), __( 'Optional. An estimate helps vendors decide.', 'lnkup-marketplace' ) );

		// Vendor opportunities.
		$options = $state['options'];
		$add( 'options', 'options', 'required', count( $options ) > 0, __( 'At least one vendor option', 'lnkup-marketplace' ), __( 'Add the booth or vendor types you are offering.', 'lnkup-marketplace' ) );
		foreach ( $options as $i => $opt ) {
			$label = '' !== $opt['name'] ? $opt['name'] : sprintf( /* translators: %d: option number */ __( 'Option %d', 'lnkup-marketplace' ), $i + 1 );
			$add( 'option_' . $opt['key'], 'options', 'required', '' !== $opt['category'] && $opt['spots'] > 0, $label, __( 'Choose a vendor category and how many spaces are available.', 'lnkup-marketplace' ) );
			if ( '' !== $opt['deadline'] && $date_ok ) {
				$add( 'option_deadline_' . $opt['key'], 'options', 'required', $opt['deadline'] <= $ev['date'] && $opt['deadline'] >= $today, sprintf( /* translators: %s: option */ __( '%s deadline', 'lnkup-marketplace' ), $label ), __( 'The application deadline must be between today and the event date.', 'lnkup-marketplace' ) );
			}
		}

		// Applications.
		foreach ( $state['applications']['questions'] as $q ) {
			$ok = '' !== $q['label'] && ( 'choice' !== $q['type'] || count( $q['options'] ) >= 2 );
			$add( 'question_' . $q['id'], 'applications', 'required', $ok, '' !== $q['label'] ? $q['label'] : __( 'Question', 'lnkup-marketplace' ), __( 'Questions need a label, and multiple-choice questions need at least two choices.', 'lnkup-marketplace' ) );
		}
		if ( 'custom' === $state['applications']['documents']['mode'] ) {
			$add( 'documents', 'applications', 'required', count( $state['applications']['documents']['items'] ) > 0, __( 'Required documents', 'lnkup-marketplace' ), __( 'Pick at least one document or choose "No documents".', 'lnkup-marketplace' ) );
		}

		// Payments.
		$deadline = $state['payments']['deadline'];
		if ( 'fixed' === $deadline['mode'] ) {
			$ok = '' !== $deadline['date'] && $deadline['date'] >= $today && ( ! $date_ok || $deadline['date'] <= $ev['date'] );
			$add( 'deadline', 'payments', 'required', $ok, __( 'Application deadline', 'lnkup-marketplace' ), __( 'Choose a deadline between today and the event date.', 'lnkup-marketplace' ) );
		}
		$dep = $state['payments']['deposit'];
		if ( $dep['enabled'] ) {
			$ok = $dep['value'] > 0 && ( 'fixed' === $dep['type'] || $dep['value'] < 100 );
			$add( 'deposit', 'payments', 'required', $ok, __( 'Deposit amount', 'lnkup-marketplace' ), __( 'A percentage deposit must be between 1 and 99 percent.', 'lnkup-marketplace' ) );
		}
		$paid = (bool) array_filter( $options, static fn( $o ) => $o['fee'] > 0 );
		if ( $paid ) {
			$ready = class_exists( 'LNKup_Stripe_Connect' ) && method_exists( 'LNKup_Stripe_Connect', 'is_connect_ready' ) && LNKup_Stripe_Connect::is_connect_ready( $user_id );
			$add( 'payouts', 'payments', 'recommended', $ready, __( 'Payouts set up', 'lnkup-marketplace' ), __( 'Vendors can apply now. Finish payout setup before you approve paid vendors so booth fees can be collected.', 'lnkup-marketplace' ) );
		}

		// Publish.
		if ( $state['sources'] || '' !== $state['import']['provider'] ) {
			$add( 'attest', 'publish', 'required', $state['publish']['attest'], __( 'You organize this event', 'lnkup-marketplace' ), __( 'Confirm that you organize or are authorized to manage vendors for this event.', 'lnkup-marketplace' ) );
		}
		if ( '' !== $state['import']['conflict'] ) {
			$add( 'ownership', 'publish', 'required', false, __( 'Event ownership', 'lnkup-marketplace' ), $state['import']['conflict'] );
		}
		if ( 'schedule' === $state['publish']['when'] ) {
			$add( 'schedule', 'publish', 'required', '' !== $state['publish']['at'] && $state['publish']['at'] > current_time( 'Y-m-d\TH:i' ), __( 'Publish time', 'lnkup-marketplace' ), __( 'Choose a publish time in the future.', 'lnkup-marketplace' ) );
		}

		$required = array_filter( $checks, static fn( $c ) => 'required' === $c['level'] );
		$passed   = array_filter( $required, static fn( $c ) => $c['ok'] );
		$steps    = array();
		foreach ( LNKup_Event_Builder_Schema::STEPS as $step ) {
			$mine          = array_filter( $required, static fn( $c ) => $c['step'] === $step );
			$steps[ $step ] = ! $mine ? 'done' : ( count( array_filter( $mine, static fn( $c ) => $c['ok'] ) ) === count( $mine ) ? 'done' : 'attention' );
		}
		return array(
			'progress'    => $required ? (int) floor( 100 * count( $passed ) / count( $required ) ) : 0,
			'publishable' => count( $passed ) === count( $required ),
			'checks'      => array_values( $checks ),
			'steps'       => $steps,
			'location'    => $location,
			'summary'     => self::inventory( $options ),
		);
	}

	/**
	 * @param array<string,mixed> $ev Event part.
	 */
	public static function last_day( array $ev ): string {
		return '' !== $ev['end_date'] && $ev['end_date'] > $ev['date'] ? $ev['end_date'] : $ev['date'];
	}

	/**
	 * Location check through the shared Location Service.
	 *
	 * @param array<string,mixed> $ev Event part.
	 * @return array<string,mixed>
	 */
	public static function location( array $ev ): array {
		if ( '' === $ev['city'] || '' === $ev['state'] ) {
			return array( 'state' => 'missing', 'message' => __( 'Choose the city and state where the event happens.', 'lnkup-marketplace' ), 'suggestions' => array() );
		}
		if ( ! class_exists( 'LNKup_Location_Service' ) ) {
			return array( 'state' => 'ok', 'message' => '', 'display' => $ev['city'] . ', ' . $ev['state'], 'suggestions' => array() );
		}
		$result = LNKup_Location_Service::validate_for_save( $ev['city'], $ev['state'], $ev['country'], array( 'mode' => 'strict', 'confirmed' => $ev['location_confirmed'] ) );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			$loc  = (array) ( $data['location'] ?? array() );
			return array(
				'state'       => 'confirm',
				'message'     => $result->get_error_message(),
				'suggestions' => array_slice( (array) ( $loc['suggestions'] ?? array() ), 0, 5 ),
			);
		}
		return array(
			'state'       => 'ok',
			'message'     => '',
			'display'     => (string) ( $result['display'] ?? '' ),
			'city'        => (string) ( $result['city'] ?? $ev['city'] ),
			'state_code'  => (string) ( $result['state_code'] ?? $result['state'] ?? $ev['state'] ),
			'market'      => class_exists( 'LNKup_Location_Service' ) ? LNKup_Location_Service::market_for( (string) ( $result['city'] ?? $ev['city'] ), (string) ( $result['state_code'] ?? $ev['state'] ) ) : '',
			'suggestions' => array(),
		);
	}

	/**
	 * Disagreement between the name, an imported source location and the chosen city.
	 *
	 * @param array<string,mixed> $state    State.
	 * @param array<string,mixed> $location Location check.
	 */
	public static function geo_conflict( array $state, array $location ): string {
		$ev   = $state['event'];
		$city = strtolower( (string) ( $location['city'] ?? $ev['city'] ) );
		if ( '' === $city ) {
			return '';
		}
		$market = strtolower( $ev['market_city'] );
		$src    = strtolower( $state['import']['source_city'] );
		if ( '' !== $src && $src !== $city && $src !== $market ) {
			/* translators: 1: imported city, 2: chosen city */
			return sprintf( __( 'The original listing says %1$s, but the event is set in %2$s. Confirm the location.', 'lnkup-marketplace' ), $state['import']['source_city'], $ev['city'] );
		}
		if ( class_exists( 'LNKup_Market_Pages' ) && '' !== $ev['name'] ) {
			foreach ( (array) LNKup_Market_Pages::get_all_markets() as $m ) {
				$mc = strtolower( (string) ( $m['city'] ?? '' ) );
				if ( '' !== $mc && $mc !== $city && $mc !== $market && preg_match( '/\b' . preg_quote( $mc, '/' ) . '\b/i', $ev['name'] ) ) {
					/* translators: 1: city in the name, 2: chosen city */
					return sprintf( __( 'The event name mentions %1$s, but the event is set in %2$s. Confirm the location, or set %1$s as the marketing market.', 'lnkup-marketplace' ), $m['city'], $ev['city'] );
				}
			}
		}
		return '';
	}

	/**
	 * Inventory summary from configured options (no demand estimates).
	 *
	 * @param array<int,array<string,mixed>> $options Options.
	 * @return array<string,mixed>
	 */
	public static function inventory( array $options ): array {
		$spaces = 0;
		$free   = 0;
		$max    = 0.0;
		$min    = null;
		foreach ( $options as $o ) {
			$spaces += $o['spots'];
			if ( $o['fee'] <= 0 ) {
				$free += $o['spots'];
			} else {
				$min = null === $min ? $o['fee'] : min( $min, $o['fee'] );
			}
			$max += $o['fee'] * $o['spots'];
		}
		return array(
			'types'         => count( $options ),
			'spaces'        => $spaces,
			'free_spaces'   => $free,
			'paid_spaces'   => $spaces - $free,
			'max_base_fees' => round( $max, 2 ),
			'starting_fee'  => null === $min ? 0.0 : (float) $min,
			'categories'    => array_values( array_unique( array_filter( wp_list_pluck( $options, 'category' ) ) ) ),
		);
	}

	/**
	 * Authoritative fee breakdown per option (LNKup_Fee_Engine).
	 *
	 * @param array<int,array<string,mixed>> $options Options.
	 * @return array<int,array<string,mixed>>
	 */
	public static function fee_preview( array $options, int $organizer_id ): array {
		$out = array();
		foreach ( $options as $o ) {
			$row = array( 'key' => $o['key'], 'name' => $o['name'], 'fee' => $o['fee'] );
			if ( class_exists( 'LNKup_Fee_Engine' ) && $o['fee'] > 0 ) {
				$calc = (array) LNKup_Fee_Engine::calculate( array( 'vendor_fee' => $o['fee'], 'organizer_id' => $organizer_id, 'category' => $o['category'] ) );
				$row  = array_merge(
					$row,
					array(
						'vendor_checkout_fee' => (float) ( $calc['vendor_checkout_fee'] ?? 0 ),
						'processing_fee'      => (float) ( $calc['processing_fee'] ?? 0 ),
						'tax_amount'          => (float) ( $calc['tax_amount'] ?? 0 ),
						'vendor_total'        => (float) ( $calc['booth_reservation_total'] ?? $o['fee'] ),
						'organizer_commission' => (float) ( $calc['organizer_commission'] ?? 0 ),
						'organizer_net'       => (float) ( $calc['organizer_net'] ?? 0 ),
					)
				);
			} else {
				$row = array_merge( $row, array( 'vendor_checkout_fee' => 0.0, 'processing_fee' => 0.0, 'tax_amount' => 0.0, 'vendor_total' => 0.0, 'organizer_commission' => 0.0, 'organizer_net' => 0.0, 'free' => true ) );
			}
			$out[] = $row;
		}
		return $out;
	}
}
