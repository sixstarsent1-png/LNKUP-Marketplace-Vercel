<?php
/**
 * Vendor readiness score for event roster.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Readiness {

	const STATUS_READY           = 'ready';
	const STATUS_NEEDS_PAYMENT   = 'needs_payment';
	const STATUS_NEEDS_DOCUMENTS = 'needs_documents';
	const STATUS_NEEDS_REVIEW    = 'needs_review';
	const STATUS_AT_RISK         = 'at_risk';
	const STATUS_COMPLETED       = 'completed';

	const TIER_READY     = 'ready';
	const TIER_ON_TRACK  = 'on_track';
	const TIER_AT_RISK   = 'at_risk';

	/**
	 * Calculate readiness for an application.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function calculate( $app_id ) {
		$status = get_post_meta( $app_id, '_lnkup_application_status', true );
		$vendor_id = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$organizer_id = (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true );
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );

		if ( get_post_meta( $app_id, '_lnkup_no_show_at', true ) ) {
			return self::STATUS_AT_RISK;
		}
		if ( in_array( $status, array( 'completed' ), true ) || get_post_meta( $app_id, '_lnkup_checked_in_at', true ) ) {
			return self::STATUS_COMPLETED;
		}
		if ( in_array( $status, array( 'submitted', 'under_review' ), true ) ) {
			return self::STATUS_NEEDS_REVIEW;
		}
		if ( in_array( $status, array( 'rejected', 'cancelled' ), true ) ) {
			return self::STATUS_AT_RISK;
		}

		$flags = class_exists( 'LNKup_Vendor_Flags' ) ? LNKup_Vendor_Flags::count_for_vendor( $organizer_id, $vendor_id ) : 0;
		if ( $flags > 0 ) {
			return self::STATUS_AT_RISK;
		}

		if ( in_array( $status, array( 'payment_required', 'approved' ), true ) ) {
			return self::STATUS_NEEDS_PAYMENT;
		}

		if ( 'paid' === $status ) {
			if ( ! self::documents_complete( $vendor_id, $app_id ) ) {
				return self::STATUS_NEEDS_DOCUMENTS;
			}
			$packet = get_posts(
				array(
					'post_type'      => 'lnkup_packet',
					'posts_per_page' => 1,
					'meta_key'       => '_lnkup_application_id',
					'meta_value'     => $app_id,
				)
			);
			if ( $packet && get_post_meta( $packet[0]->ID, '_lnkup_packet_locked', true ) ) {
				return self::STATUS_NEEDS_DOCUMENTS;
			}
			return self::STATUS_READY;
		}

		if ( 'waitlisted' === $status ) {
			return self::STATUS_NEEDS_REVIEW;
		}

		return self::STATUS_NEEDS_REVIEW;
	}

	/**
	 * Check if vendor documents are approved.
	 *
	 * @param int $vendor_id Vendor user ID.
	 * @param int $app_id    Application ID.
	 */
	private static function documents_complete( $vendor_id, $app_id ) {
		$opp_id = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		$required = $opp_id ? lnkup_get_opportunity_required_documents( $opp_id ) : array();
		if ( empty( $required ) ) {
			return true;
		}
		$docs = LNKup_Documents::get_for_vendor( $vendor_id );
		$approved_types = array();
		foreach ( $docs as $doc ) {
			if ( 'approved' === get_post_meta( $doc->ID, '_lnkup_verification_status', true ) ) {
				$approved_types[] = get_post_meta( $doc->ID, '_lnkup_document_type', true );
			}
		}
		foreach ( $required as $type ) {
			if ( ! in_array( $type, $approved_types, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Refresh cached readiness on application.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function refresh( $app_id ) {
		$readiness = self::calculate( $app_id );
		update_post_meta( $app_id, '_lnkup_readiness_status', $readiness );
		return $readiness;
	}

	/**
	 * Human label for status.
	 *
	 * @param string $status Status slug.
	 */
	public static function label( $status ) {
		$labels = array(
			self::STATUS_READY           => __( 'Ready', 'lnkup-marketplace' ),
			self::STATUS_NEEDS_PAYMENT   => __( 'Needs Payment', 'lnkup-marketplace' ),
			self::STATUS_NEEDS_DOCUMENTS => __( 'Needs Documents', 'lnkup-marketplace' ),
			self::STATUS_NEEDS_REVIEW    => __( 'Needs Review', 'lnkup-marketplace' ),
			self::STATUS_AT_RISK         => __( 'At Risk', 'lnkup-marketplace' ),
			self::STATUS_COMPLETED       => __( 'Completed', 'lnkup-marketplace' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Human label for readiness tier.
	 *
	 * @param string $tier Tier slug.
	 */
	public static function tier_label( $tier ) {
		$labels = array(
			self::TIER_READY    => __( 'Ready', 'lnkup-marketplace' ),
			self::TIER_ON_TRACK => __( 'On Track', 'lnkup-marketplace' ),
			self::TIER_AT_RISK  => __( 'At Risk', 'lnkup-marketplace' ),
		);
		return $labels[ $tier ] ?? $tier;
	}

	/**
	 * Resolve tier from percent and status slug.
	 *
	 * @param int    $percent Weighted percent.
	 * @param string $status  Status slug from calculate().
	 */
	private static function resolve_tier( $percent, $status ) {
		if ( in_array( $status, array( self::STATUS_AT_RISK, self::STATUS_NEEDS_PAYMENT, self::STATUS_NEEDS_REVIEW ), true ) ) {
			return self::TIER_AT_RISK;
		}
		if ( 100 === $percent || self::STATUS_READY === $status || self::STATUS_COMPLETED === $status ) {
			return self::TIER_READY;
		}
		if ( $percent < 50 ) {
			return self::TIER_AT_RISK;
		}
		if ( $percent >= 65 ) {
			return self::TIER_ON_TRACK;
		}
		return self::TIER_AT_RISK;
	}

	/**
	 * Build a checklist item row.
	 *
	 * @param string $key         Item key.
	 * @param string $label       Display label.
	 * @param bool   $done        Whether complete.
	 * @param int    $weight      Scoring weight.
	 * @param string $group       Group key.
	 * @param string $cta_tab     Vendor ops tab for CTA.
	 * @param string $cta_section Optional packet section.
	 */
	private static function make_item( $key, $label, $done, $weight, $group, $cta_tab = '', $cta_section = '' ) {
		$item_status = $done ? 'done' : 'pending';
		if ( ! $done && in_array( $key, array( 'application_approved', 'payment_complete' ), true ) ) {
			$item_status = 'blocked';
		}
		return array(
			'key'          => $key,
			'label'        => $label,
			'done'         => $done,
			'weight'       => $weight,
			'group'        => $group,
			'status'       => $item_status,
			'cta_tab'      => $cta_tab,
			'cta_section'  => $cta_section,
		);
	}

	/**
	 * Pick next action from incomplete items (priority order).
	 *
	 * @param array $items Flat checklist items.
	 */
	private static function resolve_next_action( $items, $app_id = 0 ) {
		$priority = array(
			'payment_complete',
			'application_approved',
		);
		$opp_id = $app_id ? (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true ) : 0;
		$required = $opp_id ? lnkup_get_opportunity_required_documents( $opp_id ) : array();
		foreach ( $required as $doc_type ) {
			$priority[] = 'doc_' . $doc_type;
		}
		$priority = array_merge(
			$priority,
			array(
				'documents_complete',
				'packet_viewed',
				'rules_accepted',
				'booth_assigned',
				'parking_assigned',
				'loadin_assigned',
				'qr_ready',
				'emergency_contacts',
			)
		);
		$by_key = array();
		foreach ( $items as $item ) {
			$by_key[ $item['key'] ] = $item;
		}
		foreach ( $priority as $key ) {
			if ( empty( $by_key[ $key ] ) || ! empty( $by_key[ $key ]['done'] ) ) {
				continue;
			}
			$item = $by_key[ $key ];
			$tab  = $item['cta_tab'] ?: 'readiness';
			$url  = '';
			if ( $tab ) {
				$dashboard = lnkup_page_url_helper( 'vendor-dashboard' );
				$args      = array( 'view' => 'operations', 'tab' => $tab );
				if ( ! empty( $item['cta_section'] ) ) {
					$args['section'] = $item['cta_section'];
				}
				$url = add_query_arg( $args, $dashboard );
			}
			return array(
				'label'   => sprintf(
					/* translators: %s: checklist item label */
					__( 'Complete: %s', 'lnkup-marketplace' ),
					$item['label']
				),
				'tab'     => $tab,
				'section' => $item['cta_section'] ?? '',
				'url'     => $url,
			);
		}
		return null;
	}

	/**
	 * Group flat items into display groups.
	 *
	 * @param array $items Flat items.
	 */
	private static function build_groups( $items ) {
		$group_labels = array(
			'application' => __( 'Application', 'lnkup-marketplace' ),
			'documents'   => __( 'Documents', 'lnkup-marketplace' ),
			'logistics'   => __( 'Logistics', 'lnkup-marketplace' ),
			'day_of'      => __( 'Day-of', 'lnkup-marketplace' ),
		);
		$groups = array();
		foreach ( $group_labels as $key => $label ) {
			$groups[ $key ] = array(
				'key'   => $key,
				'label' => $label,
				'items' => array(),
			);
		}
		foreach ( $items as $item ) {
			$g = $item['group'] ?? 'application';
			if ( ! isset( $groups[ $g ] ) ) {
				$groups[ $g ] = array(
					'key'   => $g,
					'label' => $g,
					'items' => array(),
				);
			}
			$groups[ $g ]['items'][] = $item;
		}
		return array_values( array_filter( $groups, function ( $g ) {
			return ! empty( $g['items'] );
		} ) );
	}

	/**
	 * Detailed readiness checklist with weighted percent score.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function checklist( $app_id ) {
		$app_id    = absint( $app_id );
		$vendor_id = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$status    = get_post_meta( $app_id, '_lnkup_application_status', true );
		$docs      = LNKup_Documents::get_for_vendor( $vendor_id );
		$approved_types = array();
		foreach ( $docs as $doc ) {
			if ( 'approved' === get_post_meta( $doc->ID, '_lnkup_verification_status', true ) ) {
				$approved_types[] = get_post_meta( $doc->ID, '_lnkup_document_type', true );
			}
		}
		$packet = get_posts(
			array(
				'post_type'      => 'lnkup_packet',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_application_id',
				'meta_value'     => $app_id,
			)
		);
		$packet_id     = $packet ? $packet[0]->ID : 0;
		$packet_viewed = $packet_id && class_exists( 'LNKup_Packets' ) ? LNKup_Packets::has_engagement( $packet_id, 'welcome', 'viewed' ) : false;
		$rules_ok      = $packet_id && class_exists( 'LNKup_Packets' ) ? LNKup_Packets::has_engagement( $packet_id, 'rules', 'acknowledged' ) : false;
		$booth         = class_exists( 'LNKup_Booths' ) ? LNKup_Booths::get_for_application( $app_id ) : null;
		$parking       = class_exists( 'LNKup_Parking' ) ? LNKup_Parking::get_for_application( $app_id ) : null;
		$loadin        = class_exists( 'LNKup_Loadin_Schedule' ) ? LNKup_Loadin_Schedule::get_for_application( $app_id ) : null;
		$qr            = $packet_id ? (bool) get_post_meta( $packet_id, '_lnkup_qr_pass', true ) : false;
		$event_id      = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$emergency     = $event_id && class_exists( 'LNKup_Event_Operations' ) ? LNKup_Event_Operations::emergency_contacts( $event_id ) : array();

		$doc_types   = class_exists( 'LNKup_Documents' ) ? LNKup_Documents::document_types() : array();
		$opp_id      = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		$required    = $opp_id ? lnkup_get_opportunity_required_documents( $opp_id ) : array();
		$items       = array();

		$items[] = self::make_item(
			'application_approved',
			__( 'Application approved', 'lnkup-marketplace' ),
			in_array( $status, array( 'approved', 'payment_required', 'paid', 'completed' ), true ),
			15,
			'application',
			'overview'
		);
		$items[] = self::make_item(
			'payment_complete',
			__( 'Payment complete', 'lnkup-marketplace' ),
			in_array( $status, array( 'paid', 'completed' ), true ),
			15,
			'application',
			'overview'
		);

		foreach ( $required as $doc_type ) {
			$label   = $doc_types[ $doc_type ] ?? $doc_type;
			$items[] = self::make_item(
				'doc_' . $doc_type,
				/* translators: %s: document type label */
				sprintf( __( '%s approved', 'lnkup-marketplace' ), $label ),
				in_array( $doc_type, $approved_types, true ),
				10,
				'documents',
				'overview'
			);
		}

		if ( ! empty( $required ) ) {
			$items[] = self::make_item(
				'documents_complete',
				__( 'Documents complete', 'lnkup-marketplace' ),
				self::documents_complete( $vendor_id, $app_id ),
				5,
				'documents',
				'overview'
			);
		}

		$items[] = self::make_item(
			'packet_viewed',
			__( 'Packet viewed', 'lnkup-marketplace' ),
			$packet_viewed,
			5,
			'day_of',
			'packet',
			'welcome'
		);
		$items[] = self::make_item(
			'rules_accepted',
			__( 'Rules accepted', 'lnkup-marketplace' ),
			$rules_ok,
			5,
			'day_of',
			'packet',
			'rules'
		);
		$items[] = self::make_item(
			'booth_assigned',
			__( 'Booth assigned', 'lnkup-marketplace' ),
			(bool) $booth,
			8,
			'logistics',
			'map'
		);
		$items[] = self::make_item(
			'parking_assigned',
			__( 'Parking assigned', 'lnkup-marketplace' ),
			(bool) $parking,
			8,
			'logistics',
			'packet',
			'parking'
		);
		$items[] = self::make_item(
			'loadin_assigned',
			__( 'Load-in time assigned', 'lnkup-marketplace' ),
			(bool) $loadin,
			8,
			'logistics',
			'packet',
			'loadin'
		);
		$items[] = self::make_item(
			'qr_ready',
			__( 'QR pass ready', 'lnkup-marketplace' ),
			$qr,
			3,
			'day_of',
			'packet',
			'qr'
		);
		$items[] = self::make_item(
			'emergency_contacts',
			__( 'Emergency contacts', 'lnkup-marketplace' ),
			! empty( $emergency ),
			3,
			'day_of',
			'packet',
			'emergency'
		);

		$earned  = 0;
		$total   = 0;
		foreach ( $items as $item ) {
			$total += (int) $item['weight'];
			if ( $item['done'] ) {
				$earned += (int) $item['weight'];
			}
		}
		$percent = (int) round( ( $earned / max( 1, $total ) ) * 100 );
		$status_slug = self::calculate( $app_id );
		$tier    = self::resolve_tier( $percent, $status_slug );
		$label   = self::tier_label( $tier );
		if ( self::TIER_READY === $tier && 100 === $percent ) {
			$label = __( '100% Ready', 'lnkup-marketplace' );
		} elseif ( self::TIER_ON_TRACK === $tier ) {
			$label = $percent . '%';
		}

		$groups       = self::build_groups( $items );
		$next_action  = self::resolve_next_action( $items, $app_id );
		$pending_top  = array_values(
			array_filter(
				$items,
				function ( $i ) {
					return empty( $i['done'] );
				}
			)
		);
		$pending_top  = array_slice( $pending_top, 0, 3 );

		update_post_meta( $app_id, '_lnkup_readiness_percent', $percent );

		return array(
			'percent'      => $percent,
			'label'        => $label,
			'tier'         => $tier,
			'tier_label'   => self::tier_label( $tier ),
			'items'        => $items,
			'groups'       => $groups,
			'next_action'  => $next_action,
			'pending_top'  => $pending_top,
			'status'       => $status_slug,
		);
	}

	/**
	 * Event-level readiness summary for organizer surfaces.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function event_readiness_summary( $event_id ) {
		$event_id = absint( $event_id );
		$apps     = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
				'fields'         => 'ids',
			)
		);
		$vendors       = array();
		$percent_sum   = 0;
		$percent_count = 0;
		$ready_count   = 0;
		$on_track      = 0;
		$at_risk       = 0;

		foreach ( $apps as $app_id ) {
			$app_status = get_post_meta( $app_id, '_lnkup_application_status', true );
			if ( ! in_array( $app_status, array( 'approved', 'payment_required', 'paid', 'completed' ), true ) ) {
				continue;
			}
			$checklist = self::checklist( $app_id );
			if ( in_array( $app_status, array( 'paid', 'completed' ), true ) ) {
				$percent_sum += $checklist['percent'];
				$percent_count++;
			}
			$tier = $checklist['tier'] ?? self::TIER_AT_RISK;
			if ( self::TIER_READY === $tier ) {
				$ready_count++;
			} elseif ( self::TIER_ON_TRACK === $tier ) {
				$on_track++;
			} else {
				$at_risk++;
			}
			$blocker = '';
			if ( ! empty( $checklist['next_action']['label'] ) ) {
				$blocker = $checklist['next_action']['label'];
			}
			$vendor_name = class_exists( 'LNKup_Event_Operations' )
				? LNKup_Event_Operations::vendor_name_for_app( $app_id )
				: sprintf( __( 'App #%d', 'lnkup-marketplace' ), $app_id );
			$vendors[] = array(
				'application_id'  => $app_id,
				'business_name'   => $vendor_name,
				'percent'         => $checklist['percent'],
				'tier'            => $tier,
				'tier_label'      => self::tier_label( $tier ),
				'status'          => $checklist['status'],
				'status_label'    => self::label( $checklist['status'] ),
				'blocker'         => $blocker,
				'next_action'     => $checklist['next_action'],
			);
		}

		usort(
			$vendors,
			function ( $a, $b ) {
				return $a['percent'] <=> $b['percent'];
			}
		);

		return array(
			'percent'       => $percent_count ? (int) round( $percent_sum / $percent_count ) : 0,
			'ready_count'   => $ready_count,
			'on_track_count'=> $on_track,
			'at_risk_count' => $at_risk,
			'vendor_count'  => count( $vendors ),
			'vendors'       => $vendors,
		);
	}
}
