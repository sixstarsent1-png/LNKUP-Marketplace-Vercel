<?php
/**
 * "Your next steps" (vendor) and "Requires attention" (organizer), derived from
 * canonical marketplace records at request time. No stored KPIs.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Next_Steps {

	/**
	 * @return array{steps:array,upcoming:array}
	 */
	public static function vendor( int $user_id ): array {
		$steps    = array();
		$upcoming = array();
		$apps     = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_vendor_user_id', 'value' => (string) $user_id ),
					array( 'key' => '_lnkup_application_status', 'value' => array( 'info_requested', 'approved', 'payment_required', 'paid', 'completed' ), 'compare' => 'IN' ),
				),
			)
		);
		foreach ( array_map( 'intval', $apps ) as $app_id ) {
			$ctx    = LNKup_Comms_Context::build( array( 'application_id' => $app_id ) );
			$event  = (int) $ctx['event_id'];
			if ( LNKup_Comms_Context::event_is_past( $event ) || LNKup_Comms_Context::event_cancelled( $event ) ) {
				continue;
			}
			$title  = (string) get_the_title( $event ?: (int) $ctx['opportunity_id'] );
			$status = LNKup_Comms_Context::app_status( $app_id );
			if ( 'info_requested' === $status ) {
				$steps[] = self::item( 'info_requested', __( 'Reply to the organizer', 'lnkup-communications' ), $title, 'booking', $ctx, 'vendor', 'high' );
				continue;
			}
			if ( LNKup_Comms_Context::payment_due( $app_id, (int) $ctx['payment_id'] ) ) {
				$steps[] = self::item( 'payment_due', __( 'Finish payment to confirm your spot', 'lnkup-communications' ), $title, 'wallet', $ctx, 'vendor', 'high' );
				continue;
			}
			if ( in_array( $status, LNKup_Comms_Context::CONFIRMED_BOOKING, true ) ) {
				if ( 'missing' === LNKup_Comms_Context::document_code( $app_id ) ) {
					$steps[] = self::item( 'documents', __( 'Upload required documents', 'lnkup-communications' ), $title, 'documents', $ctx, 'vendor', 'high' );
				}
				$start = LNKup_Comms_Context::event_start( $event );
				$upcoming[] = array(
					'applicationId' => $app_id,
					'eventId'       => $event,
					'title'         => $title,
					'date'          => $start ? $start->format( DATE_ATOM ) : '',
					'daysAway'      => $start ? max( 0, (int) floor( ( $start->getTimestamp() - time() ) / DAY_IN_SECONDS ) ) : null,
					'passReady'     => class_exists( 'LNKup_Packets' ) && LNKup_Packets::is_pass_unlocked_for_application( $app_id ),
					'deepLink'      => '/pass/' . $app_id,
				);
			}
		}
		$next = LNKup_Comms_Context::vendor_next_step( $user_id );
		if ( '' !== $next['key'] ) {
			$steps[] = self::item( 'profile_' . $next['key'], $next['label'], '', $next['link'], LNKup_Comms_Context::build( array() ), 'vendor', 'normal' );
		}
		usort(
			$upcoming,
			static function ( array $a, array $b ): int {
				return strcmp( (string) $a['date'], (string) $b['date'] );
			}
		);
		return array(
			'steps'    => array_slice( $steps, 0, 8 ),
			'upcoming' => array_slice( $upcoming, 0, 5 ),
		);
	}

	/**
	 * @return array{steps:array,upcoming:array}
	 */
	public static function organizer( int $user_id ): array {
		$steps    = array();
		$upcoming = array();
		foreach ( LNKup_Comms_Context::organizer_upcoming_events( $user_id ) as $event_id ) {
			$ctx      = LNKup_Comms_Context::build( array( 'event_id' => $event_id ) );
			$title    = (string) get_the_title( $event_id );
			$pending  = LNKup_Comms_Context::count_apps( array( '_lnkup_event_id' => $event_id ), LNKup_Comms_Context::PENDING_STATUSES );
			$unpaid   = LNKup_Comms_Context::count_apps( array( '_lnkup_event_id' => $event_id ), array( 'approved', 'payment_required' ) );
			$confirmed_ids = LNKup_Comms_Context::event_app_ids( $event_id, LNKup_Comms_Context::CONFIRMED_BOOKING, 300 );
			$missing_docs  = 0;
			foreach ( $confirmed_ids as $app_id ) {
				if ( 'missing' === LNKup_Comms_Context::document_code( $app_id ) ) {
					++$missing_docs;
				}
			}
			if ( $pending > 0 ) {
				/* translators: %d: count */
				$steps[] = self::item( 'pending_' . $event_id, sprintf( _n( '%d application waiting for review', '%d applications waiting for review', $pending, 'lnkup-communications' ), $pending ), $title, 'org_review', $ctx, 'organizer', 'high', $pending );
			}
			if ( $missing_docs > 0 ) {
				/* translators: %d: count */
				$steps[] = self::item( 'docs_' . $event_id, sprintf( _n( '%d confirmed vendor missing documents', '%d confirmed vendors missing documents', $missing_docs, 'lnkup-communications' ), $missing_docs ), $title, 'org_event', $ctx, 'organizer', 'normal', $missing_docs );
			}
			$start      = LNKup_Comms_Context::event_start( $event_id );
			$upcoming[] = array(
				'eventId'   => $event_id,
				'title'     => $title,
				'date'      => $start ? $start->format( DATE_ATOM ) : '',
				'daysAway'  => $start ? max( 0, (int) floor( ( $start->getTimestamp() - time() ) / DAY_IN_SECONDS ) ) : null,
				'pending'   => $pending,
				'unpaid'    => $unpaid,
				'confirmed' => count( $confirmed_ids ),
				'missingDocuments' => $missing_docs,
				'deepLink'  => '/(organizer)/events/' . $event_id,
			);
		}
		$setup = LNKup_Comms_Context::organizer_next_step( $user_id );
		if ( '' !== $setup['key'] ) {
			$steps[] = self::item( 'setup_' . $setup['key'], $setup['label'], $setup['event_id'] ? (string) get_the_title( $setup['event_id'] ) : '', $setup['link'], LNKup_Comms_Context::build( array( 'event_id' => $setup['event_id'] ) ), 'organizer', 'normal' );
		}
		$failed = self::recent_failed_payments( $user_id );
		if ( $failed > 0 ) {
			/* translators: %d: count */
			$steps[] = self::item( 'failed_payments', sprintf( _n( '%d vendor payment did not complete this week', '%d vendor payments did not complete this week', $failed, 'lnkup-communications' ), $failed ), '', 'org_money', LNKup_Comms_Context::build( array() ), 'organizer', 'normal', $failed );
		}
		usort(
			$upcoming,
			static function ( array $a, array $b ): int {
				return strcmp( (string) $a['date'], (string) $b['date'] );
			}
		);
		return array(
			'steps'    => array_slice( $steps, 0, 10 ),
			'upcoming' => array_slice( $upcoming, 0, 6 ),
		);
	}

	private static function recent_failed_payments( int $organizer_id ): int {
		$query = new WP_Query(
			array(
				'post_type'      => 'lnkup_payment',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_organizer_user_id', 'value' => (string) $organizer_id ),
					array( 'key' => '_lnkup_payment_status', 'value' => 'failed' ),
					array( 'key' => '_lnkup_voided_at', 'compare' => 'NOT EXISTS' ),
				),
				'date_query'     => array( array( 'after' => '7 days ago', 'column' => 'post_modified_gmt' ) ),
			)
		);
		return (int) $query->found_posts;
	}

	private static function item( string $key, string $title, string $context, string $link, array $ctx, string $role, string $priority, int $count = 0 ): array {
		return array(
			'key'      => $key,
			'title'    => $title,
			'context'  => $context,
			'priority' => $priority,
			'count'    => $count,
			'deepLink' => LNKup_Comms_Context::deep_link( $link, $ctx, $role ),
			'webUrl'   => LNKup_Comms_Context::web_url( $link, $ctx, $role ),
		);
	}
}
