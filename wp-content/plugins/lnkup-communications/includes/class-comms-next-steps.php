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
		$invites = class_exists( 'LNKup_Vendor_Invites' ) ? LNKup_Vendor_Invites::get_for_vendor( $user_id, 'open', 20 ) : array();
		if ( $invites ) {
			$count   = count( $invites );
			/* translators: %d: count */
			$steps[] = self::item( 'invitations', sprintf( _n( 'Respond to %d organizer invitation', 'Respond to %d organizer invitations', $count, 'lnkup-communications' ), $count ), (string) $invites[0]['event_title'], 'vendor_invites', LNKup_Comms_Context::build( array() ), 'vendor', 'high', $count );
		}
		foreach ( self::open_drafts( $user_id ) as $draft ) {
			$steps[] = self::item( 'draft_' . $draft['draft_id'], __( 'Finish your application', 'lnkup-communications' ), $draft['title'], 'opportunity', LNKup_Comms_Context::build( array( 'opportunity_id' => $draft['opportunity_id'] ) ), 'vendor', $draft['closing'] ? 'high' : 'normal' );
		}
		$next = LNKup_Comms_Context::vendor_next_step( $user_id );
		if ( '' !== $next['key'] ) {
			$steps[] = self::item( 'profile_' . $next['key'], $next['label'], '', $next['link'], LNKup_Comms_Context::build( array() ), 'vendor', 'normal' );
		}
		if ( ! $upcoming && ! $invites ) {
			$recs = LNKup_Comms_Engagement::vendor_recommendations( $user_id, 5 );
			if ( $recs ) {
				$count   = count( $recs );
				/* translators: %d: count */
				$steps[] = self::item( 'opportunities', sprintf( _n( '%d open event matches your business', '%d open events match your business', $count, 'lnkup-communications' ), $count ), (string) ( $recs[0]['event_name'] ?? '' ), 'vendor_find', LNKup_Comms_Context::build( array() ), 'vendor', 'low', $count );
			}
		}
		$steps = self::rank( $steps );
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
			if ( 'publish' === get_post_status( $event_id ) && 0 === LNKup_Comms_Context::published_opportunity_count( $event_id ) ) {
				$steps[] = self::item( 'slots_' . $event_id, __( 'Add vendor opportunities so businesses can apply', 'lnkup-communications' ), $title, 'org_event', $ctx, 'organizer', 'high' );
			} elseif ( class_exists( 'LNKup_Event_Fulfillment' ) ) {
				foreach ( LNKup_Event_Fulfillment::for_event( $event_id )['actions'] as $action ) {
					if ( in_array( $action['key'], array( 'deadline', 'find_vendors', 'share_link' ), true ) ) {
						$steps[] = self::item( 'fill_' . $event_id, (string) $action['label'], $title, 'org_find_vendors', $ctx, 'organizer', 'deadline' === $action['key'] ? 'high' : 'normal' );
						break;
					}
				}
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
		$steps = self::rank( $steps );
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

	/**
	 * Stable priority order (high → normal → low); ties keep their discovery order.
	 */
	private static function rank( array $steps ): array {
		$weight = array( 'high' => 0, 'normal' => 1, 'low' => 2 );
		foreach ( $steps as $i => $step ) {
			$steps[ $i ]['_order'] = ( $weight[ $step['priority'] ] ?? 1 ) * 1000 + $i;
		}
		usort(
			$steps,
			static function ( array $a, array $b ): int {
				return $a['_order'] <=> $b['_order'];
			}
		);
		return array_map(
			static function ( array $step ): array {
				unset( $step['_order'] );
				return $step;
			},
			$steps
		);
	}

	/**
	 * Saved applications whose opportunity is still open and not applied to.
	 *
	 * @return array<int, array{draft_id:int,opportunity_id:int,title:string,closing:bool}>
	 */
	private static function open_drafts( int $user_id ): array {
		if ( ! class_exists( 'LNKup_Application_Drafts' ) ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => LNKup_Application_Drafts::POST_STATUS,
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_draft_vendor_user_id', 'value' => (string) $user_id ),
					array( 'key' => '_lnkup_draft_state', 'value' => LNKup_Application_Drafts::STATE_OPEN ),
				),
			)
		);
		$out = array();
		foreach ( array_map( 'intval', (array) $ids ) as $draft_id ) {
			$opp = (int) get_post_meta( $draft_id, '_lnkup_draft_opportunity_id', true );
			if ( ! LNKup_Comms_Context::opportunity_open( $opp ) || LNKup_Comms_Context::vendor_applied_to( $user_id, $opp ) ) {
				continue;
			}
			$deadline = trim( (string) get_post_meta( $opp, '_lnkup_application_deadline', true ) );
			$out[]    = array(
				'draft_id'       => $draft_id,
				'opportunity_id' => $opp,
				'title'          => (string) get_the_title( (int) get_post_meta( $opp, '_lnkup_event_id', true ) ?: $opp ),
				'closing'        => '' !== $deadline && strtotime( substr( $deadline, 0, 10 ) ) <= strtotime( wp_date( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ) ),
			);
		}
		return $out;
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
