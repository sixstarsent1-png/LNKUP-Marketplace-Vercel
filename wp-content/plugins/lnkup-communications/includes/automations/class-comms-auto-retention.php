<?php
/**
 * Organizer retention: live events with no applications, "plan your next event"
 * after an event ends, and reactivation for lapsed organizers. Recurring cadence
 * is suggested only when at least three past events show a regular interval.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Auto_Retention {

	public static function init(): void {
		add_action( 'lnkup_comms_daily', array( __CLASS__, 'run' ), 40 );
	}

	public static function run(): void {
		if ( ! LNKup_Comms_Settings::enabled( 'flow_retention' ) ) {
			return;
		}
		self::no_applications();
		$organizers = get_users( array( 'role__in' => array( 'lnkup_organizer' ), 'fields' => 'ID', 'number' => 3000 ) );
		foreach ( array_map( 'intval', (array) $organizers ) as $org ) {
			self::organizer( $org );
		}
	}

	/**
	 * Live events published 5+ days ago with zero applications, deadline still open.
	 */
	private static function no_applications(): void {
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => 'publish',
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_event_status', 'value' => 'live' ),
					array( 'key' => '_lnkup_event_date', 'value' => wp_date( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ), 'compare' => '>=', 'type' => 'DATE' ),
				),
				'date_query'     => array( array( 'before' => '5 days ago', 'column' => 'post_date_gmt' ) ),
			)
		);
		foreach ( array_map( 'intval', (array) $events ) as $event_id ) {
			$org = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
			if ( $org ) {
				LNKup_Comms_Engine::dispatch( 'o_no_applications', $org, array( 'event_id' => $event_id ), array( 'source' => 'retention' ) );
			}
		}
	}

	/**
	 * Past events (most recent first) as event_id => start timestamp.
	 */
	private static function past_events( int $org ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => 12,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_event_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_organizer_user_id', 'value' => (string) $org ),
					array( 'key' => '_lnkup_event_date', 'value' => wp_date( 'Y-m-d' ), 'compare' => '<', 'type' => 'DATE' ),
				),
			)
		);
		$out = array();
		foreach ( array_map( 'intval', (array) $ids ) as $event_id ) {
			if ( LNKup_Comms_Context::event_cancelled( $event_id ) ) {
				continue;
			}
			$start = LNKup_Comms_Context::event_start( $event_id );
			if ( $start ) {
				$out[ $event_id ] = $start->getTimestamp();
			}
		}
		return $out;
	}

	/**
	 * Cadence label from real history, or '' when not regular.
	 *
	 * @param int[] $timestamps Descending event starts.
	 */
	public static function cadence( array $timestamps ): string {
		$timestamps = array_values( $timestamps );
		if ( count( $timestamps ) < 3 ) {
			return '';
		}
		$gaps = array();
		for ( $i = 0; $i < count( $timestamps ) - 1 && $i < 5; $i++ ) {
			$gaps[] = (int) round( ( $timestamps[ $i ] - $timestamps[ $i + 1 ] ) / DAY_IN_SECONDS );
		}
		$targets = array(
			7  => __( 'weekly', 'lnkup-communications' ),
			14 => __( 'every other week', 'lnkup-communications' ),
			30 => __( 'monthly', 'lnkup-communications' ),
		);
		foreach ( $targets as $days => $label ) {
			$tolerance = 7 === $days ? 1 : 4;
			$regular   = true;
			foreach ( $gaps as $gap ) {
				if ( abs( $gap - $days ) > $tolerance ) {
					$regular = false;
					break;
				}
			}
			if ( $regular ) {
				return $label;
			}
		}
		return '';
	}

	private static function organizer( int $org ): void {
		if ( array() !== LNKup_Comms_Context::organizer_upcoming_events( $org ) ) {
			return;
		}
		$past = self::past_events( $org );
		if ( array() === $past ) {
			return;
		}
		$last_id    = (int) array_key_first( $past );
		$days_since = (int) floor( ( time() - (int) $past[ $last_id ] ) / DAY_IN_SECONDS );
		if ( $days_since >= 3 && $days_since <= 30 ) {
			$cadence = self::cadence( $past );
			LNKup_Comms_Engine::dispatch(
				'o_publish_next',
				$org,
				array( 'event_id' => $last_id, 'organizer_user_id' => $org, 'cadence_label' => $cadence ),
				array( 'variant' => '' !== $cadence ? 'recurring' : 'default', 'window' => 'after_' . $last_id, 'source' => 'retention' )
			);
		} elseif ( $days_since > 60 ) {
			// At most once per quarter.
			LNKup_Comms_Engine::dispatch( 'o_reactivation', $org, array( 'organizer_user_id' => $org ), array( 'window' => gmdate( 'Y' ) . 'q' . (int) ceil( (int) gmdate( 'n' ) / 3 ), 'source' => 'retention' ) );
		}
	}
}
