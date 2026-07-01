<?php
/**
 * Marketplace cron jobs — notifications and trending refresh.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Marketplace_Cron {

	const HOOK_DAILY = 'lnkup_marketplace_daily';

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( self::HOOK_DAILY, array( __CLASS__, 'run_daily' ) );
		add_action( 'publish_lnkup_opportunity', array( __CLASS__, 'on_new_opportunity' ), 20, 2 );
		add_action( 'init', array( __CLASS__, 'sync_with_phase' ), 25 );
	}

	/**
	 * Schedule or clear cron based on marketplace phase (1C only).
	 */
	public static function sync_with_phase() {
		if ( lnkup_marketplace_phase_at_least( '1c' ) ) {
			self::schedule();
		} else {
			self::unschedule();
		}
	}

	/**
	 * Schedule cron on activation.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK_DAILY ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK_DAILY );
		}
	}

	/**
	 * Clear cron on deactivation.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK_DAILY );
	}

	/**
	 * Daily tasks.
	 */
	public static function run_daily() {
		self::check_saved_searches();
		self::check_deadline_reminders();
		self::check_almost_full();
		self::mark_post_event_reviewable();
		LNKup_Marketplace_Index::rebuild_all();
		if ( class_exists( 'LNKup_Vendor_Import' ) ) {
			LNKup_Vendor_Import::cleanup_expired_jobs();
		}
		if ( class_exists( 'LNKup_Intelligence_Cron' ) ) {
			LNKup_Intelligence_Cron::run_daily();
		}
	}

	/**
	 * Mark paid applications reviewable after event date passes.
	 */
	public static function mark_post_event_reviewable() {
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => 100,
				'meta_query'     => array(
					array(
						'key'     => '_lnkup_application_status',
						'value'   => array( 'paid', 'completed' ),
						'compare' => 'IN',
					),
					array(
						'key'     => '_lnkup_event_completed_at',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$today = gmdate( 'Y-m-d' );
		foreach ( $apps as $app ) {
			$event_id = (int) get_post_meta( $app->ID, '_lnkup_event_id', true );
			$event_date = $event_id ? get_post_meta( $event_id, '_lnkup_event_date', true ) : '';
			if ( $event_date && $event_date < $today ) {
				update_post_meta( $app->ID, '_lnkup_event_completed_at', current_time( 'mysql' ) );
				$organizer_id = (int) get_post_meta( $app->ID, '_lnkup_organizer_user_id', true );
				if ( $organizer_id && ! get_post_meta( $app->ID, '_lnkup_review_id', true ) ) {
					LNKup_Notifications::send_post_event_review_prompt( $organizer_id, $app->ID );
				}
			}
		}
	}

	/**
	 * Notify on new opportunity publish.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function on_new_opportunity( $post_id, $post ) {
		if ( ! lnkup_marketplace_phase_at_least( '1c' ) || ! LNKup_Saved_Searches::alerts_enabled() ) {
			return;
		}
		$row = LNKup_Marketplace_Index::get_row( $post_id );
		if ( ! $row ) {
			LNKup_Marketplace_Index::sync_opportunity( $post_id );
			$row = LNKup_Marketplace_Index::get_row( $post_id );
		}
		if ( ! $row ) {
			return;
		}

		LNKup_Notifications::send_favorite_organizer_new_event( (int) $row['organizer_user_id'], $post_id );
		self::notify_saved_search_matches( $row );
		self::notify_profile_matches( $row );
	}

	/**
	 * Check saved searches for all vendors (daily + weekly digest).
	 */
	private static function check_saved_searches() {
		if ( ! lnkup_marketplace_phase_at_least( '1c' ) || ! LNKup_Saved_Searches::alerts_enabled() || ! lnkup_is_marketplace_open() ) {
			return;
		}

		$max = LNKup_Saved_Searches::max_matches_per_email();
		foreach ( LNKup_Saved_Searches::get_alert_candidates() as $entry ) {
			$user_id = (int) $entry['user_id'];
			$search  = $entry['search'];
			$freq    = $search['frequency'] ?? LNKup_Saved_Searches::FREQ_DAILY;

			if ( LNKup_Saved_Searches::FREQ_INSTANT === $freq ) {
				continue;
			}
			if ( LNKup_Saved_Searches::FREQ_WEEKLY === $freq && ! LNKup_Saved_Searches::is_weekly_due( $search ) ) {
				LNKup_Saved_Searches::touch_alert_timestamps( $user_id, $search['id'], false );
				continue;
			}

			$created_after = $search['last_checked_at'] ?? $search['created_at'] ?? null;
			$result        = LNKup_Saved_Searches::run(
				$user_id,
				$search['id'],
				array(
					'created_after' => $created_after,
					'per_page'      => $max,
				)
			);

			if ( is_wp_error( $result ) ) {
				continue;
			}

			$items = $result['items'] ?? array();
			$count = (int) ( $result['total'] ?? count( $items ) );
			if ( $count > 0 ) {
				LNKup_Notifications::send_saved_search_match(
					$user_id,
					$search['name'],
					$count,
					$items,
					$search['filters'] ?? array(),
					$search['id']
				);
				LNKup_Saved_Searches::touch_alert_timestamps( $user_id, $search['id'], true );
				LNKup_Marketplace_Analytics::track(
					'saved_search_alert_sent',
					array( 'search_id' => $search['id'], 'count' => $count ),
					$user_id
				);
			} else {
				LNKup_Saved_Searches::touch_alert_timestamps( $user_id, $search['id'], false );
			}
		}
	}

	/**
	 * Deadline approaching notifications.
	 */
	private static function check_deadline_reminders() {
		global $wpdb;
		$table = LNKup_Marketplace_Index::table_name();

		$windows = array(
			'3d'  => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
			'48h' => gmdate( 'Y-m-d', strtotime( '+2 days' ) ),
			'24h' => gmdate( 'Y-m-d', strtotime( '+1 day' ) ),
		);

		foreach ( $windows as $window => $target_date ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT opportunity_id, title, deadline FROM {$table} WHERE deadline = %s",
				$target_date
			), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			foreach ( (array) $rows as $row ) {
				$opp_id = (int) $row['opportunity_id'];

				if ( '3d' === $window ) {
					$apps = get_posts(
						array(
							'post_type'      => 'lnkup_application',
							'posts_per_page' => -1,
							'meta_query'     => array(
								array( 'key' => '_lnkup_opportunity_id', 'value' => $opp_id ),
								array(
									'key'     => '_lnkup_application_status',
									'value'   => array( 'submitted', 'under_review', 'approved', 'payment_required' ),
									'compare' => 'IN',
								),
							),
						)
					);
					foreach ( $apps as $app ) {
						$vendor_id = (int) get_post_meta( $app->ID, '_lnkup_vendor_user_id', true );
						LNKup_Notifications::send_deadline_approaching( $vendor_id, $row['title'], $row['deadline'] );
					}
					continue;
				}

				$settings = lnkup_get_settings();
				if ( empty( $settings['deadline_reminder_favorites'] ) ) {
					continue;
				}

				$vendors = get_users( array( 'role' => 'lnkup_vendor', 'fields' => 'ID' ) );
				foreach ( $vendors as $uid ) {
					if ( ! LNKup_Vendor_Personalization::is_favorite( $uid, 'opportunities', $opp_id ) ) {
						continue;
					}
					if ( LNKup_Saved_Searches::vendor_has_application( $uid, $opp_id ) ) {
						continue;
					}
					$meta_key = 'lnkup_deadline_reminder_sent_' . $opp_id . '_' . $window;
					if ( get_user_meta( $uid, $meta_key, true ) ) {
						continue;
					}
					if ( ! lnkup_vendor_can_discover_opportunity( $uid, $opp_id ) ) {
						continue;
					}
					LNKup_Notifications::send_favorite_deadline_reminder( $uid, $row['title'], $row['deadline'], $window );
					update_user_meta( $uid, $meta_key, gmdate( 'Y-m-d' ) );
				}
			}
		}
	}

	/**
	 * Almost full notifications.
	 */
	private static function check_almost_full() {
		global $wpdb;
		$table = LNKup_Marketplace_Index::table_name();
		$rows  = $wpdb->get_results( "SELECT opportunity_id, title, spots_remaining, spots_total FROM {$table} WHERE spots_total > 0 AND spots_remaining > 0 AND (spots_remaining / spots_total) <= 0.1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $rows as $row ) {
			$fav_users = get_users( array( 'role' => 'lnkup_vendor', 'fields' => 'ID' ) );
			foreach ( $fav_users as $uid ) {
				if ( LNKup_Vendor_Personalization::is_favorite( $uid, 'opportunities', $row['opportunity_id'] ) ) {
					LNKup_Notifications::send_almost_full( $uid, $row['title'], $row['spots_remaining'] );
				}
			}
		}
	}

	/**
	 * Notify vendors with instant-frequency saved searches for new row.
	 *
	 * @param array $row Index row.
	 */
	private static function notify_saved_search_matches( $row ) {
		if ( ! lnkup_is_marketplace_open() ) {
			return;
		}

		$opp_id = (int) ( $row['opportunity_id'] ?? 0 );
		if ( ! $opp_id ) {
			return;
		}

		foreach ( LNKup_Saved_Searches::get_alert_candidates( LNKup_Saved_Searches::FREQ_INSTANT ) as $entry ) {
			$user_id = (int) $entry['user_id'];
			$search  = $entry['search'];

			if ( ! lnkup_vendor_can_discover_opportunity( $user_id, $opp_id ) ) {
				continue;
			}
			if ( LNKup_Saved_Searches::vendor_has_application( $user_id, $opp_id ) ) {
				continue;
			}
			if ( LNKup_Saved_Searches::instant_alert_sent_today( $user_id, $search['id'], $opp_id ) ) {
				continue;
			}
			if ( ! LNKup_Discovery::matches_filters( $search['filters'] ?? array(), $row ) ) {
				continue;
			}

			$item = lnkup_get_opportunity_card_data( $opp_id, $row );
			LNKup_Notifications::send_saved_search_match(
				$user_id,
				$search['name'],
				1,
				array( $item ),
				$search['filters'] ?? array(),
				$search['id']
			);
			LNKup_Saved_Searches::mark_instant_alert_sent( $user_id, $search['id'], $opp_id );
			LNKup_Marketplace_Analytics::track(
				'saved_search_alert_sent',
				array( 'search_id' => $search['id'], 'count' => 1, 'instant' => true ),
				$user_id,
				$opp_id
			);
		}
	}

	/**
	 * Notify vendors whose profile matches new opportunity.
	 */
	private static function notify_profile_matches( $row ) {
		$row_cats = json_decode( $row['categories'] ?? '[]', true ) ?: array();
		if ( empty( $row_cats ) ) {
			return;
		}

		$vendors = get_users( array( 'role' => 'lnkup_vendor' ) );
		foreach ( $vendors as $user ) {
			$profile_id = lnkup_get_vendor_profile_id( $user->ID );
			if ( ! $profile_id ) {
				continue;
			}
			$cats = wp_get_post_terms( $profile_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
			if ( is_wp_error( $cats ) ) {
				continue;
			}
			if ( array_intersect( $cats, $row_cats ) ) {
				LNKup_Notifications::send_profile_match( $user->ID, $row['title'] );
			}
		}
	}
}
