<?php
/**
 * Organizer Overview aggregates: what needs attention now and how each upcoming event is tracking.
 *
 * Every number is counted from canonical records in events the viewer can see
 * (owner, organization team grant, or event staff). Zero counts are omitted, never invented.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_Overview {

	const UPCOMING_LIMIT    = 8;
	const ATTENTION_WINDOW  = 14;
	const DOCUMENTS_HORIZON = 60;

	/**
	 * @param int $user_id Viewer.
	 * @return array{attention:array,upcoming:array,tasks:array,event_count:int}
	 */
	public static function for_user( $user_id ) {
		$user_id = absint( $user_id );
		$events  = self::event_ids( $user_id );
		$dash    = lnkup_page_url_helper( 'organizer-dashboard' );
		$counts  = self::application_counts( $events );
		$tasks   = LNKup_Organizer_OS_Tables::ready( '2' ) ? LNKup_Event_Tasks::counts( $user_id, $events ) : array( 'mine' => 0, 'overdue' => 0, 'due_soon' => 0, 'all' => 0, 'completed' => 0 );
		$upcoming  = self::upcoming( $events );
		$attention = array();

		if ( $counts['pending'] ) {
			$attention[] = array(
				'key'   => 'pending_reviews',
				'count' => $counts['pending'],
				/* translators: %d: applications */
				'label' => sprintf( _n( '%d application is waiting for review', '%d applications are waiting for review', $counts['pending'], 'lnkup-marketplace' ), $counts['pending'] ),
				'url'   => add_query_arg( array( 'view' => 'applications', 'group' => 'pending', 'filter' => 'pending' ), $dash ),
			);
		}
		if ( $counts['unpaid'] ) {
			$attention[] = array(
				'key'   => 'unpaid',
				'count' => $counts['unpaid'],
				/* translators: %d: vendors */
				'label' => sprintf( _n( '%d approved vendor has not paid yet', '%d approved vendors have not paid yet', $counts['unpaid'], 'lnkup-marketplace' ), $counts['unpaid'] ),
				'url'   => add_query_arg( array( 'view' => 'financial', 'fin_tab' => 'vendor-revenue' ), $dash ),
			);
		}
		if ( LNKup_Feature_Flags::document_review() ) {
			$docs     = 0;
			$doc_link = '';
			foreach ( $upcoming as $event ) {
				if ( null === $event['days_until'] || $event['days_until'] > self::DOCUMENTS_HORIZON ) {
					continue;
				}
				$c     = LNKup_Organizer_Documents::counts_for_event( (int) $event['event_id'] );
				$count = $c['missing'] + $c['requested'] + $c['submitted'] + $c['rejected'] + $c['replacement_requested'] + $c['expired'];
				if ( $count && ! $doc_link ) {
					$doc_link = add_query_arg( array( 'event' => $event['event_id'], 'tab' => 'documents' ), $dash );
				}
				$docs += $count;
			}
			if ( $docs ) {
				$attention[] = array(
					'key'   => 'documents',
					'count' => $docs,
					/* translators: %d: documents */
					'label' => sprintf( _n( '%d required document is missing or waiting for your review', '%d required documents are missing or waiting for your review', $docs, 'lnkup-marketplace' ), $docs ),
					'url'   => $doc_link,
				);
			}
		}
		if ( $tasks['overdue'] ) {
			$attention[] = array(
				'key'   => 'overdue_tasks',
				'count' => $tasks['overdue'],
				/* translators: %d: tasks */
				'label' => sprintf( _n( '%d event task is overdue', '%d event tasks are overdue', $tasks['overdue'], 'lnkup-marketplace' ), $tasks['overdue'] ),
				'url'   => add_query_arg( array( 'view' => 'tasks', 'tasks_view' => 'overdue' ), $dash ),
			);
		}
		foreach ( $upcoming as $event ) {
			if ( null !== $event['days_until'] && $event['days_until'] <= self::ATTENTION_WINDOW && $event['readiness_percent'] < 100 ) {
				$attention[] = array(
					'key'   => 'readiness_' . $event['event_id'],
					'count' => 100 - $event['readiness_percent'],
					/* translators: 1: event, 2: days, 3: percent */
					'label' => sprintf( __( '%1$s is in %2$d days and %3$d%% ready', 'lnkup-marketplace' ), $event['title'], $event['days_until'], $event['readiness_percent'] ),
					'url'   => add_query_arg( array( 'event' => $event['event_id'], 'tab' => 'readiness' ), $dash ),
				);
			}
		}

		return array(
			'attention'   => $attention,
			'upcoming'    => $upcoming,
			'tasks'       => $tasks,
			'event_count' => is_array( $events ) ? count( $events ) : 0,
		);
	}

	/**
	 * Events in scope; platform admins see their own organizer events here.
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	private static function event_ids( $user_id ) {
		$ids = LNKup_Event_Tasks::scoped_event_ids( $user_id );
		if ( null === $ids ) {
			$ids = class_exists( 'LNKup_Org_Teams' ) ? LNKup_Org_Teams::event_ids_for( $user_id, 'view_roster' ) : array();
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * @param int[] $events Events.
	 * @return array{pending:int,unpaid:int}
	 */
	private static function application_counts( array $events ) {
		global $wpdb;
		$out = array( 'pending' => 0, 'unpaid' => 0 );
		if ( ! $events ) {
			return $out;
		}
		$placeholders = implode( ',', array_fill( 0, count( $events ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.meta_value AS status, COUNT(DISTINCT p.ID) AS n FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_lnkup_event_id' AND e.meta_value IN ({$placeholders})
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_application_status'
				WHERE p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft')
				GROUP BY s.meta_value",
				array_map( 'strval', $events )
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			if ( in_array( $row['status'], array( 'submitted', 'under_review' ), true ) ) {
				$out['pending'] += (int) $row['n'];
			} elseif ( in_array( $row['status'], array( 'approved', 'payment_required' ), true ) ) {
				$out['unpaid'] += (int) $row['n'];
			}
		}
		return $out;
	}

	/**
	 * Next events by date with readiness, confirmed vendors and capacity.
	 *
	 * @param int[] $events Events.
	 */
	private static function upcoming( array $events ) {
		if ( ! $events ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'post__in'       => $events,
				'posts_per_page' => self::UPCOMING_LIMIT,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_event_date',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_event_date', 'value' => current_time( 'Y-m-d' ), 'compare' => '>=', 'type' => 'DATE' ),
				),
			)
		);
		$out = array();
		foreach ( $ids as $event_id ) {
			$ready    = LNKup_Event_Readiness::evaluate( (int) $event_id );
			$capacity = 0;
			$paid     = 0;
			foreach ( get_posts( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_key' => '_lnkup_event_id', 'meta_value' => (string) $event_id ) ) as $opp_id ) {
				$summary   = LNKup_Opportunity_Inventory::summary( (int) $opp_id );
				$capacity += (int) $summary['capacity'];
				$paid     += (int) $summary['paid'];
			}
			$out[] = array(
				'event_id'          => (int) $event_id,
				'title'             => lnkup_plain_title( $event_id ),
				'date'              => $ready['event_date'],
				'days_until'        => $ready['days_until'],
				'readiness_percent' => (int) $ready['percent'],
				'paid'              => $paid,
				'capacity'          => $capacity,
				'next_action'       => $ready['next_actions'][0] ?? null,
				'url'               => add_query_arg( array( 'event' => $event_id, 'tab' => 'readiness' ), lnkup_page_url_helper( 'organizer-dashboard' ) ),
			);
		}
		return $out;
	}
}
