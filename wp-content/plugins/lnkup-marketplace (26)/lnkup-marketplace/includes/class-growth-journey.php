<?php
/**
 * Vendor customer journey milestones and business calendar.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Growth_Journey {

	public static function timeline( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		self::detect_and_record( $user_id );
		global $wpdb;
		$table = LNKup_Growth_Tables::milestones_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE vendor_user_id = %d ORDER BY achieved_at DESC LIMIT 50", $user_id ),
			ARRAY_A
		);
		return array_map(
			function ( $row ) {
				return array(
					'key'         => $row['milestone_key'],
					'title'       => $row['title'],
					'description' => $row['description'],
					'achieved_at' => $row['achieved_at'],
				);
			},
			(array) $rows
		);
	}

	public static function detect_and_record( $user_id ) {
		$user = get_userdata( $user_id );
		if ( $user ) {
			self::record( $user_id, 'joined', __( 'Joined Marketplace', 'lnkup-marketplace' ), $user->user_registered );
		}
		$apps = lnkup_get_applications_for_user( $user_id, 'vendor' );
		$first_app = null;
		$first_approval = null;
		$first_payment = null;
		$completed = 0;
		$lifetime = 0;
		foreach ( $apps as $app ) {
			$submitted = get_post_meta( $app->ID, '_lnkup_submitted_at', true ) ?: $app->post_date;
			if ( ! $first_app || strtotime( $submitted ) < strtotime( $first_app ) ) {
				$first_app = $submitted;
			}
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( in_array( $status, array( 'approved', 'payment_required', 'paid', 'completed' ), true ) ) {
				$decided = get_post_meta( $app->ID, '_lnkup_decided_at', true ) ?: $submitted;
				if ( ! $first_approval || strtotime( $decided ) < strtotime( $first_approval ) ) {
					$first_approval = $decided;
				}
			}
			if ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
				$paid_at = get_post_meta( $app->ID, '_lnkup_paid_at', true ) ?: $submitted;
				if ( ! $first_payment || strtotime( $paid_at ) < strtotime( $first_payment ) ) {
					$first_payment = $paid_at;
				}
			}
			if ( 'completed' === $status ) {
				$completed++;
			}
		}
		if ( $first_app ) {
			self::record( $user_id, 'first_application', __( 'First Application', 'lnkup-marketplace' ), $first_app );
		}
		if ( $first_approval ) {
			self::record( $user_id, 'first_approval', __( 'First Approval', 'lnkup-marketplace' ), $first_approval );
		}
		if ( $first_payment ) {
			self::record( $user_id, 'first_payment', __( 'First Payment', 'lnkup-marketplace' ), $first_payment );
		}
		$payments = lnkup_get_payments_for_user( $user_id, 'vendor' );
		$lifetime = LNKup_Growth_Kpis::lifetime_revenue( $payments );
		if ( $lifetime >= 10000 ) {
			self::record( $user_id, 'first_10k', __( 'First $10k Revenue', 'lnkup-marketplace' ), current_time( 'mysql' ) );
		}
		if ( $completed >= 1 ) {
			self::record( $user_id, 'first_event', __( 'First Completed Event', 'lnkup-marketplace' ), current_time( 'mysql' ) );
		}
		if ( $completed >= 100 ) {
			self::record( $user_id, 'events_100', __( '100 Events Completed', 'lnkup-marketplace' ), current_time( 'mysql' ) );
		}
		$profile_id = lnkup_get_vendor_profile_id( $user_id );
		if ( $profile_id ) {
			$badges = LNKup_Vendor_Badges::get_active_slugs( $profile_id );
			if ( in_array( 'top_rated', $badges, true ) ) {
				self::record( $user_id, 'top_rated', __( 'Top Rated', 'lnkup-marketplace' ), current_time( 'mysql' ) );
			}
		}
	}

	public static function record( $user_id, $key, $title, $achieved_at, $description = '' ) {
		global $wpdb;
		$table = LNKup_Growth_Tables::milestones_table();
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE vendor_user_id = %d AND milestone_key = %s", $user_id, $key )
		);
		if ( $exists ) {
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'vendor_user_id' => $user_id,
				'milestone_key'  => sanitize_key( $key ),
				'title'          => sanitize_text_field( $title ),
				'description'    => sanitize_textarea_field( $description ),
				'achieved_at'    => $achieved_at,
			)
		);
	}
}

class LNKup_Growth_Calendar {

	public static function items( $user_id = 0, $from = '', $to = '' ) {
		$user_id = $user_id ?: get_current_user_id();
		$from_ts = $from ? strtotime( $from ) : strtotime( 'first day of this month' );
		$to_ts   = $to ? strtotime( $to ) : strtotime( '+3 months' );
		$items   = array();

		foreach ( lnkup_get_applications_for_user( $user_id, 'vendor' ) as $app ) {
			$meta   = LNKup_Vendor_Dashboard_Data::application_event_meta( $app );
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( $meta['date'] ) {
				$ts = strtotime( $meta['date'] );
				if ( $ts >= $from_ts && $ts <= $to_ts && in_array( $status, array( 'paid', 'approved', 'payment_required' ), true ) ) {
					$items[] = array(
						'type'  => 'event',
						'title' => $meta['event_title'],
						'date'  => $meta['date'],
						'meta'  => array( 'application_id' => $app->ID, 'city' => $meta['city'] ),
					);
				}
			}
			$deadline = get_post_meta( $app->ID, '_lnkup_application_deadline', true );
			if ( $deadline ) {
				$ts = strtotime( $deadline );
				if ( $ts >= $from_ts && $ts <= $to_ts ) {
					$items[] = array(
						'type'  => 'deadline',
						'title' => sprintf( __( 'Application deadline: %s', 'lnkup-marketplace' ), $meta['event_title'] ),
						'date'  => $deadline,
					);
				}
			}
		}

		if ( class_exists( 'LNKup_Installments' ) ) {
			foreach ( LNKup_Installments::upcoming_for_vendor( $user_id ) as $inst ) {
				$due = $inst['due_date'] ?? '';
				if ( $due && strtotime( $due ) >= $from_ts && strtotime( $due ) <= $to_ts ) {
					$items[] = array(
						'type'  => 'payment',
						'title' => __( 'Installment due', 'lnkup-marketplace' ),
						'date'  => $due,
						'meta'  => array( 'amount' => $inst['amount'] ?? 0 ),
					);
				}
			}
		}

		foreach ( LNKup_Growth_Goals::list_for_vendor( $user_id ) as $goal ) {
			if ( ! empty( $goal['deadline'] ) ) {
				$ts = strtotime( $goal['deadline'] );
				if ( $ts >= $from_ts && $ts <= $to_ts && 'active' === $goal['status'] ) {
					$items[] = array(
						'type'  => 'goal',
						'title' => $goal['title'],
						'date'  => $goal['deadline'],
					);
				}
			}
		}

		$compliance = LNKup_Documents::compliance_summary( $user_id );
		foreach ( $compliance['items'] as $doc ) {
			$expires = $doc['expires'] ?? $doc['expires_at'] ?? '';
			if ( $expires ) {
				$ts = strtotime( $expires );
				if ( $ts >= $from_ts && $ts <= $to_ts ) {
					$items[] = array(
						'type'  => 'document',
						'title' => sprintf( __( 'Document expires: %s', 'lnkup-marketplace' ), $doc['label'] ),
						'date'  => $expires,
					);
				}
			}
		}

		usort( $items, function ( $a, $b ) { return strtotime( $a['date'] ) <=> strtotime( $b['date'] ); } );
		return $items;
	}
}

class LNKup_Growth_Achievements {

	public static function for_vendor( $user_id = 0 ) {
		$user_id    = $user_id ?: get_current_user_id();
		$profile_id = lnkup_get_vendor_profile_id( $user_id );
		$earned     = $profile_id ? LNKup_Vendor_Badges::get_active( $profile_id ) : array();
		$catalog    = LNKup_Vendor_Badges::catalog();
		$kpis       = LNKup_Growth_Kpis::for_vendor( $user_id );
		$progress   = array();
		$targets    = array(
			'completed_5'  => array( 'current' => $kpis['completed_events'] ?? 0, 'target' => 5 ),
			'completed_10' => array( 'current' => $kpis['completed_events'] ?? 0, 'target' => 10 ),
			'completed_25' => array( 'current' => $kpis['completed_events'] ?? 0, 'target' => 25 ),
		);
		$earned_slugs = wp_list_pluck( $earned, 'slug' );
		$next = null;
		foreach ( $targets as $slug => $t ) {
			if ( in_array( $slug, $earned_slugs, true ) ) {
				continue;
			}
			$pct = $t['target'] ? min( 100, round( ( $t['current'] / $t['target'] ) * 100 ) ) : 0;
			$progress[] = array(
				'slug'     => $slug,
				'label'    => $catalog[ $slug ]['label'] ?? $slug,
				'current'  => $t['current'],
				'target'   => $t['target'],
				'progress' => $pct,
			);
			if ( ! $next ) {
				$next = $progress[ count( $progress ) - 1 ];
			}
		}
		return array(
			'earned'   => $earned,
			'progress' => $progress,
			'next'     => $next,
		);
	}
}

class LNKup_Growth_Exports {

	public static function csv( $user_id, $type ) {
		$user_id = $user_id ?: get_current_user_id();
		$type    = sanitize_key( $type );
		$rows    = array();
		$headers = array();

		switch ( $type ) {
			case 'revenue':
				$headers = array( 'Month', 'Amount' );
				foreach ( LNKup_Growth_Analytics::revenue_by_month( $user_id ) as $r ) {
					$rows[] = array( $r['month'], $r['amount'] );
				}
				break;
			case 'events':
				$headers = array( 'Event', 'City', 'Date', 'Fee', 'Status' );
				foreach ( lnkup_get_applications_for_user( $user_id, 'vendor' ) as $app ) {
					$meta = LNKup_Vendor_Dashboard_Data::application_event_meta( $app );
					$rows[] = array(
						$meta['event_title'],
						$meta['city'],
						$meta['date'],
						$meta['fee'],
						get_post_meta( $app->ID, '_lnkup_application_status', true ),
					);
				}
				break;
			case 'performance':
				$headers = array( 'Metric', 'Value' );
				$kpis = LNKup_Growth_Kpis::for_vendor( $user_id );
				foreach ( $kpis as $k => $v ) {
					if ( is_scalar( $v ) ) {
						$rows[] = array( $k, $v );
					}
				}
				break;
			case 'tax_summary':
				$headers = array( 'Note' );
				$rows[]  = array( __( 'Tax summary PDF export coming soon. Use revenue export for now.', 'lnkup-marketplace' ) );
				break;
			default:
				return new WP_Error( 'invalid_type', __( 'Unknown export type.', 'lnkup-marketplace' ) );
		}

		$csv = implode( ',', $headers ) . "\n";
		foreach ( $rows as $row ) {
			$csv .= implode( ',', array_map( array( __CLASS__, 'escape_csv' ), $row ) ) . "\n";
		}
		return $csv;
	}

	private static function escape_csv( $val ) {
		$val = (string) $val;
		if ( false !== strpos( $val, ',' ) || false !== strpos( $val, '"' ) ) {
			return '"' . str_replace( '"', '""', $val ) . '"';
		}
		return $val;
	}
}
