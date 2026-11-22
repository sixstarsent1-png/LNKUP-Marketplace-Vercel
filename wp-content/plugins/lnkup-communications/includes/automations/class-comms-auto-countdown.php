<?php
/**
 * Event countdowns (hourly) and document expiry reminders (daily).
 *
 * Windows are computed in the EVENT's timezone. Each window fires at most once
 * per recipient (dedupe). A missed window (worker down > 12h) is skipped rather
 * than sent late.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Auto_Countdown {

	const BAND = 12 * HOUR_IN_SECONDS;

	public static function init(): void {
		add_action( 'lnkup_comms_hourly', array( __CLASS__, 'run' ), 20 );
		add_action( 'lnkup_comms_daily', array( __CLASS__, 'documents_expiring' ), 20 );
	}

	/**
	 * Window key + label for an event start, or null when no window is open now.
	 *
	 * @param array<string,int> $windows key => seconds before start.
	 * @return array{key:string,label:string}|null
	 */
	public static function window_for( DateTimeImmutable $start, array $windows, ?int $now = null ): ?array {
		$now   = $now ?? time();
		$until = $start->getTimestamp() - $now;
		if ( $until <= 0 ) {
			return null;
		}
		$local = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $start->getTimezone() );
		if ( $local->format( 'Y-m-d' ) === $start->format( 'Y-m-d' ) && (int) $local->format( 'G' ) >= 7 ) {
			return array( 'key' => 'morning', 'label' => __( 'today', 'lnkup-communications' ) );
		}
		$labels = array(
			'd30' => __( 'in 30 days', 'lnkup-communications' ),
			'd14' => __( 'in 2 weeks', 'lnkup-communications' ),
			'd7'  => __( 'in 1 week', 'lnkup-communications' ),
			'h72' => __( 'in 3 days', 'lnkup-communications' ),
			'h24' => __( 'tomorrow', 'lnkup-communications' ),
		);
		foreach ( $windows as $key => $seconds ) {
			if ( $until <= $seconds && $until > $seconds - self::BAND ) {
				return array( 'key' => $key, 'label' => $labels[ $key ] ?? '' );
			}
		}
		return null;
	}

	public static function run(): void {
		$vendor_on = LNKup_Comms_Settings::enabled( 'flow_countdown' );
		if ( ! $vendor_on ) {
			return;
		}
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_lnkup_event_date',
						'value'   => array( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ), wp_date( 'Y-m-d', time() + 31 * DAY_IN_SECONDS ) ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
				),
			)
		);
		$vendor_windows = array( 'd14' => 14 * DAY_IN_SECONDS, 'd7' => 7 * DAY_IN_SECONDS, 'h72' => 3 * DAY_IN_SECONDS, 'h24' => DAY_IN_SECONDS );
		$org_windows    = array( 'd30' => 30 * DAY_IN_SECONDS ) + $vendor_windows;

		foreach ( array_map( 'intval', (array) $events ) as $event_id ) {
			if ( LNKup_Comms_Context::event_cancelled( $event_id ) ) {
				continue;
			}
			$start = LNKup_Comms_Context::event_start( $event_id );
			if ( ! $start ) {
				continue;
			}
			$vendor_window = self::window_for( $start, $vendor_windows );
			if ( $vendor_window ) {
				foreach ( LNKup_Comms_Context::event_app_ids( $event_id, LNKup_Comms_Context::CONFIRMED_BOOKING ) as $app_id ) {
					self::vendor_reminder( $app_id, $vendor_window );
				}
			}
			$org_window = self::window_for( $start, $org_windows );
			if ( $org_window ) {
				$organizer = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
				if ( $organizer && 'live' === LNKup_Comms_Context::event_status( $event_id ) ) {
					LNKup_Comms_Engine::dispatch( 'o_countdown', $organizer, array( 'event_id' => $event_id, 'window_label' => $org_window['label'] ), array( 'window' => $org_window['key'], 'cancel_key' => 'event:' . $event_id . '|countdown', 'source' => 'countdown' ) );
				}
			}
		}
	}

	/**
	 * Vendor reminder with the exact outstanding items. Fully prepared vendors get fewer reminders.
	 */
	private static function vendor_reminder( int $app_id, array $window ): void {
		$vendor = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$tasks  = array();
		if ( 'missing' === LNKup_Comms_Context::document_code( $app_id ) ) {
			$tasks[] = __( 'required documents', 'lnkup-communications' );
		}
		if ( class_exists( 'LNKup_Packets' ) && ! LNKup_Packets::is_pass_unlocked_for_application( $app_id ) ) {
			$tasks[] = __( 'your event pass is not ready yet — contact the organizer', 'lnkup-communications' );
		}
		$ready = array() === $tasks;
		if ( $ready && ! in_array( $window['key'], array( 'd7', 'h24', 'morning' ), true ) ) {
			return;
		}
		LNKup_Comms_Engine::dispatch(
			'v_countdown',
			$vendor,
			array( 'application_id' => $app_id, 'window_label' => $window['label'], 'tasks_line' => implode( ', ', $tasks ) ),
			array( 'variant' => $ready ? 'ready' : 'action', 'window' => $window['key'], 'cancel_key' => 'app:' . $app_id . '|countdown', 'source' => 'countdown' )
		);
	}

	/**
	 * Approved documents expiring in 30 or 7 days.
	 */
	public static function documents_expiring(): void {
		if ( ! LNKup_Comms_Settings::enabled( 'flow_documents' ) ) {
			return;
		}
		$docs = get_posts(
			array(
				'post_type'      => 'lnkup_document',
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_verification_status', 'value' => 'approved' ),
					array(
						'key'     => '_lnkup_expiration_date',
						'value'   => array( wp_date( 'Y-m-d' ), wp_date( 'Y-m-d', time() + 31 * DAY_IN_SECONDS ) ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
				),
			)
		);
		foreach ( array_map( 'intval', (array) $docs ) as $doc_id ) {
			$expires = (string) get_post_meta( $doc_id, '_lnkup_expiration_date', true );
			$days    = (int) floor( ( (int) strtotime( $expires ) - time() ) / DAY_IN_SECONDS );
			$window  = $days <= 7 ? 'd7' : ( $days <= 30 ? 'd30' : '' );
			$vendor  = (int) get_post_meta( $doc_id, '_lnkup_vendor_user_id', true );
			if ( '' === $window || ! $vendor ) {
				continue;
			}
			LNKup_Comms_Engine::dispatch(
				'v_document_expiring',
				$vendor,
				array(
					'document_id'    => $doc_id,
					'document_label' => LNKup_Comms_Listen_Documents::label( $doc_id ),
					'expires_on'     => wp_date( 'M j, Y', (int) strtotime( $expires ) ),
				),
				array( 'window' => $window . '_' . sanitize_key( $expires ), 'source' => 'document_expiry' )
			);
		}
	}
}
