<?php
/**
 * Audience resolution for organizer event communications and admin campaigns.
 *
 * Counts are exact: computed from real records, in batches. Event groups only
 * ever contain vendors with an application on that event.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Audiences {

	/**
	 * Organizer event groups (only groups supported by real application data).
	 */
	public static function event_groups(): array {
		return array(
			'all_applicants'  => array( 'label' => __( 'All applicants', 'lnkup-communications' ), 'statuses' => array( 'submitted', 'under_review', 'info_requested', 'approved', 'payment_required', 'waitlisted', 'paid', 'completed' ) ),
			'pending'         => array( 'label' => __( 'Pending applications', 'lnkup-communications' ), 'statuses' => array( 'submitted', 'under_review', 'info_requested' ) ),
			'approved_unpaid' => array( 'label' => __( 'Approved, payment pending', 'lnkup-communications' ), 'statuses' => array( 'approved', 'payment_required' ) ),
			'waitlisted'      => array( 'label' => __( 'Waitlisted vendors', 'lnkup-communications' ), 'statuses' => array( 'waitlisted' ) ),
			'confirmed'       => array( 'label' => __( 'Confirmed (paid) vendors', 'lnkup-communications' ), 'statuses' => array( 'paid', 'completed' ) ),
			'docs_incomplete' => array( 'label' => __( 'Confirmed vendors missing documents', 'lnkup-communications' ), 'statuses' => array( 'paid', 'completed' ) ),
			'not_checked_in'  => array( 'label' => __( 'Confirmed vendors not checked in', 'lnkup-communications' ), 'statuses' => array( 'paid', 'completed' ) ),
			'checked_in'      => array( 'label' => __( 'Checked-in vendors', 'lnkup-communications' ), 'statuses' => array( 'paid', 'completed' ) ),
		);
	}

	/**
	 * Recipients for an event group: [ vendor_user_id => application_id ].
	 *
	 * @return array<int,int>
	 */
	public static function event_group_members( int $event_id, string $group ): array {
		$groups = self::event_groups();
		if ( ! isset( $groups[ $group ] ) ) {
			return array();
		}
		$out = array();
		foreach ( LNKup_Comms_Context::event_app_ids( $event_id, $groups[ $group ]['statuses'] ) as $app_id ) {
			if ( 'approved_unpaid' === $group && ! LNKup_Comms_Context::payment_due( $app_id, self::payment_id( $app_id ) ) ) {
				continue;
			}
			if ( 'docs_incomplete' === $group && 'missing' !== LNKup_Comms_Context::document_code( $app_id ) ) {
				continue;
			}
			$checked = (bool) get_post_meta( $app_id, '_lnkup_checked_in_at', true );
			if ( 'not_checked_in' === $group && $checked ) {
				continue;
			}
			if ( 'checked_in' === $group && ! $checked ) {
				continue;
			}
			$vendor = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
			if ( $vendor > 0 && ! isset( $out[ $vendor ] ) ) {
				$out[ $vendor ] = $app_id;
			}
		}
		return $out;
	}

	private static function payment_id( int $app_id ): int {
		$payment = class_exists( 'LNKup_Payments' ) ? LNKup_Payments::get_for_application( $app_id ) : null;
		return $payment ? (int) $payment->ID : 0;
	}

	/**
	 * Group sizes for an event (for the organizer picker).
	 */
	public static function event_group_counts( int $event_id ): array {
		$out = array();
		foreach ( self::event_groups() as $key => $group ) {
			$out[] = array(
				'key'   => $key,
				'label' => $group['label'],
				'count' => count( self::event_group_members( $event_id, $key ) ),
			);
		}
		return $out;
	}

	/**
	 * Preview: who receives what on which channel. Inbox always reaches every member.
	 *
	 * @param int[]  $user_ids Recipients.
	 * @param string $category Preference category.
	 * @param bool   $critical Critical messages ignore category opt-outs.
	 */
	public static function preview( array $user_ids, string $category, bool $critical, array $channels ): array {
		$email_ok = 0;
		$push_ok  = 0;
		$excluded = array( 'no_email' => 0, 'email_opted_out' => 0, 'email_suppressed' => 0, 'no_push_device' => 0, 'push_opted_out' => 0 );
		$cat      = LNKup_Comms_Registry::category( $category );
		$promo    = $cat && ! empty( $cat['promotional'] );
		foreach ( $user_ids as $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( ! $user ) {
				continue;
			}
			if ( in_array( 'email', $channels, true ) ) {
				if ( ! is_email( $user->user_email ) ) {
					++$excluded['no_email'];
				} elseif ( ! $critical && 'off' === LNKup_Comms_Preferences::email_mode( (int) $user_id, $category ) ) {
					++$excluded['email_opted_out'];
				} elseif ( '' !== LNKup_Comms_Suppression::check_email( (string) $user->user_email, $category, $promo ) ) {
					++$excluded['email_suppressed'];
				} else {
					++$email_ok;
				}
			}
			if ( in_array( 'push', $channels, true ) ) {
				if ( ! LNKup_Comms_Preferences::push_on( (int) $user_id, $critical ? 'account' : $category ) ) {
					++$excluded['push_opted_out'];
				} elseif ( array() === LNKup_Comms_Channel_Push::devices( (int) $user_id ) ) {
					++$excluded['no_push_device'];
				} else {
					++$push_ok;
				}
			}
		}
		return array(
			'recipients' => count( $user_ids ),
			'inbox'      => in_array( 'inbox', $channels, true ) ? count( $user_ids ) : 0,
			'email'      => $email_ok,
			'push'       => $push_ok,
			'excluded'   => $excluded,
		);
	}

	// ------------------------------------------------------------------ Admin segments.

	/**
	 * Sanitize campaign filters.
	 */
	public static function clean_filters( array $raw ): array {
		return array(
			'role'                => in_array( (string) ( $raw['role'] ?? '' ), array( 'vendor', 'organizer' ), true ) ? (string) $raw['role'] : 'vendor',
			'city'                => sanitize_text_field( (string) ( $raw['city'] ?? '' ) ),
			'state'               => sanitize_text_field( (string) ( $raw['state'] ?? '' ) ),
			'category'            => absint( $raw['category'] ?? 0 ),
			'profile_complete'    => in_array( (string) ( $raw['profile_complete'] ?? '' ), array( 'yes', 'no' ), true ) ? (string) $raw['profile_complete'] : '',
			'applied'             => in_array( (string) ( $raw['applied'] ?? '' ), array( 'yes', 'no' ), true ) ? (string) $raw['applied'] : '',
			'has_published'       => in_array( (string) ( $raw['has_published'] ?? '' ), array( 'yes', 'no' ), true ) ? (string) $raw['has_published'] : '',
			'onboarding_incomplete' => ! empty( $raw['onboarding_incomplete'] ),
			'no_upcoming'         => ! empty( $raw['no_upcoming'] ),
			'registered_days'     => absint( $raw['registered_days'] ?? 0 ),
		);
	}

	/**
	 * One batch of users (ID cursor) for the role in the filters.
	 *
	 * @return int[]
	 */
	public static function user_batch( array $filters, int $after_id, int $limit ): array {
		global $wpdb;
		$roles = 'organizer' === $filters['role'] ? array( 'lnkup_organizer' ) : array( 'lnkup_vendor' );
		$args  = array(
			'role__in' => $roles,
			'fields'   => 'ID',
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'number'   => $limit,
		);
		if ( $filters['registered_days'] > 0 ) {
			$args['date_query'] = array( array( 'after' => gmdate( 'Y-m-d H:i:s', time() - $filters['registered_days'] * DAY_IN_SECONDS ), 'column' => 'user_registered' ) );
		}
		$cursor = static function ( WP_User_Query $query ) use ( $after_id, $wpdb ): void {
			$query->query_where .= $wpdb->prepare( " AND {$wpdb->users}.ID > %d", $after_id );
		};
		add_action( 'pre_user_query', $cursor );
		$ids = get_users( $args );
		remove_action( 'pre_user_query', $cursor );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Whether a user matches the segment filters (real records only).
	 */
	public static function matches( int $user_id, array $filters ): bool {
		if ( 'vendor' === $filters['role'] ) {
			$profile_id = function_exists( 'lnkup_get_vendor_profile_id' ) ? (int) lnkup_get_vendor_profile_id( $user_id ) : 0;
			if ( '' !== $filters['city'] ) {
				$city   = strtolower( (string) get_post_meta( $profile_id, '_lnkup_city', true ) );
				$served = strtolower( (string) get_post_meta( $profile_id, '_lnkup_cities_served', true ) );
				$want   = strtolower( $filters['city'] );
				if ( ! $profile_id || ( $city !== $want && false === strpos( $served, $want ) ) ) {
					return false;
				}
			}
			if ( '' !== $filters['state'] ) {
				$state  = strtolower( (string) get_post_meta( $profile_id, '_lnkup_state', true ) );
				$served = strtolower( (string) get_post_meta( $profile_id, '_lnkup_states_served', true ) );
				$want   = strtolower( $filters['state'] );
				if ( ! $profile_id || ( $state !== $want && false === strpos( $served, $want ) ) ) {
					return false;
				}
			}
			if ( $filters['category'] && ( ! $profile_id || ! has_term( $filters['category'], 'lnkup_vendor_category', $profile_id ) ) ) {
				return false;
			}
			if ( '' !== $filters['profile_complete'] || '' !== $filters['applied'] ) {
				$step = LNKup_Comms_Context::vendor_next_step( $user_id )['key'];
				$complete = in_array( $step, array( '', 'first_application' ), true );
				if ( 'yes' === $filters['profile_complete'] && ! $complete ) {
					return false;
				}
				if ( 'no' === $filters['profile_complete'] && $complete ) {
					return false;
				}
				$applied = LNKup_Comms_Context::count_apps( array( '_lnkup_vendor_user_id' => $user_id ), array( 'submitted', 'under_review', 'info_requested', 'approved', 'payment_required', 'paid', 'waitlisted', 'rejected', 'completed', 'cancelled' ) ) > 0;
				if ( ( 'yes' === $filters['applied'] && ! $applied ) || ( 'no' === $filters['applied'] && $applied ) ) {
					return false;
				}
			}
			return true;
		}

		if ( '' !== $filters['city'] ) {
			$cities = strtolower( (string) get_user_meta( $user_id, '_lnkup_organizer_service_cities', true ) );
			if ( false === strpos( $cities, strtolower( $filters['city'] ) ) ) {
				return false;
			}
		}
		if ( '' !== $filters['has_published'] || $filters['onboarding_incomplete'] ) {
			$step = LNKup_Comms_Context::organizer_next_step( $user_id )['key'];
			$published = in_array( $step, array( '', 'payouts' ), true );
			if ( ( 'yes' === $filters['has_published'] && ! $published ) || ( 'no' === $filters['has_published'] && $published ) ) {
				return false;
			}
			if ( $filters['onboarding_incomplete'] && '' === $step ) {
				return false;
			}
		}
		if ( $filters['no_upcoming'] && array() !== LNKup_Comms_Context::organizer_upcoming_events( $user_id ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Exact segment size and preview. Processes in batches; capped at 20,000 users
	 * evaluated per preview to keep the admin responsive (reported when hit).
	 */
	public static function segment_preview( array $filters, string $category, array $channels ): array {
		$after   = 0;
		$members = array();
		$scanned = 0;
		do {
			$batch = self::user_batch( $filters, $after, 500 );
			foreach ( $batch as $user_id ) {
				$after = $user_id;
				++$scanned;
				if ( self::matches( $user_id, $filters ) ) {
					$members[] = $user_id;
				}
			}
		} while ( count( $batch ) === 500 && $scanned < 20000 );

		$preview             = self::preview( $members, $category, false, $channels );
		$preview['scanned']  = $scanned;
		$preview['capped']   = $scanned >= 20000;
		$preview['sample']   = array_map(
			static function ( int $id ): string {
				$user = get_userdata( $id );
				return $user ? (string) $user->display_name : '';
			},
			array_slice( $members, 0, 5 )
		);
		return $preview;
	}
}
