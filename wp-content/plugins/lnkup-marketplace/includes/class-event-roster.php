<?php
/**
 * Event vendor roster.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Roster {

	/**
	 * Get roster for event.
	 *
	 * @param int $event_id     Event ID.
	 * @param int $organizer_id Organizer user ID.
	 */
	public static function get( $event_id, $organizer_id = 0 ) {
		$organizer_id = $organizer_id ?: get_current_user_id();
		if ( ! LNKup_Checkins::actor_can_operate( $event_id, $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		$groups = array(
			'applied'           => array(),
			'approved'          => array(),
			'paid'              => array(),
			'missing_payment'   => array(),
			'missing_documents' => array(),
			'waitlisted'        => array(),
			'rejected'          => array(),
			'checked_in'        => array(),
			'no_shows'          => array(),
		);
		LNKup_Vendor_Readiness::begin_batch();
		try {
			$result = self::build( $event_id, $apps, $groups, $organizer_id );
		} finally {
			LNKup_Vendor_Readiness::end_batch();
		}
		return $result;
	}

	/**
	 * Group rows and render; readiness is computed once per application (format_roster_row).
	 *
	 * @param int       $event_id     Event ID.
	 * @param WP_Post[] $apps         Applications.
	 * @param array     $groups       Empty group buckets.
	 * @param int       $organizer_id Actor.
	 */
	private static function build( $event_id, $apps, $groups, $organizer_id ) {
		$rows       = array();
		$membership = array();
		foreach ( $apps as $app ) {
			$row = self::format_roster_row( $app, $organizer_id );
			$rows[ $app->ID ] = $row;
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			$keys   = array();
			if ( get_post_meta( $app->ID, '_lnkup_checked_in_at', true ) ) {
				$keys[] = 'checked_in';
			} elseif ( get_post_meta( $app->ID, '_lnkup_no_show_at', true ) ) {
				$keys[] = 'no_shows';
			} elseif ( 'rejected' === $status ) {
				$keys[] = 'rejected';
			} elseif ( 'waitlisted' === $status ) {
				$keys[] = 'waitlisted';
			} elseif ( in_array( $status, array( 'payment_required', 'approved', 'paid', 'completed' ), true ) ) {
				$paid = class_exists( 'LNKup_Vendor_Readiness' )
					&& LNKup_Vendor_Readiness::application_payment_satisfied( (int) $app->ID, $status );
				if ( ! $paid ) {
					$keys[] = 'missing_payment';
				} else {
					$readiness   = (string) $row['readiness'];
					$outstanding = false;
					if ( LNKup_Feature_Flags::document_review() ) {
						// Per-event organizer decisions are authoritative for this event's roster.
						foreach ( LNKup_Organizer_Documents::requirements_for_application( (int) $app->ID ) as $requirement ) {
							if ( ! in_array( $requirement['status'], array( 'accepted', 'waived' ), true ) ) {
								$outstanding = true;
							}
						}
					} else {
						$doc_code    = class_exists( 'LNKup_Verification' ) ? (string) ( LNKup_Verification::get_application_document_context( (int) $app->ID )['code'] ?? '' ) : '';
						$outstanding = in_array( $doc_code, array( 'pending', 'missing' ), true ) || LNKup_Vendor_Readiness::STATUS_NEEDS_DOCUMENTS === $readiness;
					}
					if ( $outstanding ) {
						$keys[] = 'missing_documents';
					} else {
						$keys[] = 'paid';
					}
					$keys[] = 'approved';
				}
			} elseif ( in_array( $status, array( 'submitted', 'under_review' ), true ) ) {
				$keys[] = 'applied';
			}
			$membership[ $app->ID ] = $keys;
			foreach ( $keys as $key ) {
				$groups[ $key ][] = $row;
			}
		}
		$unique = array_values( $rows );
		return array(
			'event_id' => $event_id,
			'groups'   => $groups,
			'rows'     => $unique,
			'total'    => count( $unique ),
			'html'     => self::render_html( $unique, $membership ),
		);
	}

	/**
	 * Format roster row.
	 */
	private static function format_roster_row( $app, $organizer_id ) {
		$vendor_id = (int) get_post_meta( $app->ID, '_lnkup_vendor_user_id', true );
		$profile_id = (int) get_post_meta( $app->ID, '_lnkup_vendor_profile_id', true );
		$profile = $profile_id ? LNKup_Vendor_CRM_Profile::get( $profile_id, $organizer_id ) : null;
		$readiness  = LNKup_Vendor_Readiness::refresh( $app->ID );
		$evaluation = class_exists( 'LNKup_Checkins' )
			? LNKup_Checkins::evaluate( $app->ID, '', $organizer_id, (int) get_post_meta( $app->ID, '_lnkup_event_id', true ) )
			: array();
		$ops = (string) ( $evaluation['ops_status'] ?? '' );
		$reason = (string) ( $evaluation['reason'] ?? '' );
		return array(
			'application_id' => $app->ID,
			'vendor_user_id' => $vendor_id,
			'profile_id'     => $profile_id,
			'business_name'  => $profile ? $profile['business_name'] : ( get_userdata( $vendor_id )->display_name ?? '' ),
			'status'         => get_post_meta( $app->ID, '_lnkup_application_status', true ),
			'readiness'      => $readiness,
			'readiness_label'=> LNKup_Vendor_Readiness::label( $readiness ),
			'booth'          => get_post_meta( $app->ID, '_lnkup_booth_placeholder', true ),
			'checked_in'     => (bool) get_post_meta( $app->ID, '_lnkup_checked_in_at', true ),
			'checked_in_at'  => (string) ( $evaluation['checked_in_at'] ?? '' ),
			'no_show'        => (bool) get_post_meta( $app->ID, '_lnkup_no_show_at', true ),
			'vendor_score'   => $profile ? $profile['vendor_score'] : 0,
			'flags'          => $profile ? $profile['flags'] : array(),
			'ops_status'     => $ops,
			'ops_label'      => class_exists( 'LNKup_Checkins' ) ? LNKup_Checkins::roster_label( $ops, $reason ) : '',
			'reason'         => $reason,
			'message'        => (string) ( $evaluation['message'] ?? '' ),
			'actions'        => array_values( (array) ( $evaluation['actions'] ?? array() ) ),
		);
	}

	/**
	 * Render one card per application. Group chips stay as filters.
	 *
	 * @param array<int, array<string, mixed>> $rows       Unique rows.
	 * @param array<int, string[]>             $membership Group keys per application.
	 */
	private static function render_html( $rows, $membership = array() ) {
		$counts = array();
		foreach ( $membership as $keys ) {
			foreach ( (array) $keys as $key ) {
				$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
			}
		}
		$html  = '<p class="lnkup-roster-count" id="lnkup-roster-count" data-total="' . esc_attr( (string) count( $rows ) ) . '">';
		$html .= esc_html( sprintf( _n( '%d vendor', '%d vendors', count( $rows ), 'lnkup-marketplace' ), count( $rows ) ) );
		$html .= '</p>';
		if ( $counts ) {
			$labels = array(
				'applied'           => __( 'New requests', 'lnkup-marketplace' ),
				'approved'          => __( 'Accepted', 'lnkup-marketplace' ),
				'paid'              => __( 'Paid and ready', 'lnkup-marketplace' ),
				'missing_payment'   => __( 'Not paid yet', 'lnkup-marketplace' ),
				'missing_documents' => __( 'Documents needed', 'lnkup-marketplace' ),
				'waitlisted'        => __( 'Waitlisted', 'lnkup-marketplace' ),
				'rejected'          => __( 'Declined', 'lnkup-marketplace' ),
				'checked_in'        => __( 'Checked in', 'lnkup-marketplace' ),
				'no_shows'          => __( 'No-shows', 'lnkup-marketplace' ),
			);
			$html .= '<div class="lnkup-roster-filters" id="lnkup-roster-filters" role="group" aria-label="' . esc_attr__( 'Filter roster', 'lnkup-marketplace' ) . '">';
			$html .= '<button type="button" class="lnkup-badge is-active" data-roster-filter="" aria-pressed="true">' . esc_html__( 'All', 'lnkup-marketplace' ) . '</button>';
			foreach ( $counts as $key => $count ) {
				$label = $labels[ $key ] ?? ucwords( str_replace( '_', ' ', $key ) );
				$html .= '<button type="button" class="lnkup-badge" data-roster-filter="' . esc_attr( $key ) . '" aria-pressed="false">' . esc_html( $label . ' (' . $count . ')' ) . '</button>';
			}
			$html .= '</div>';
		}
		$cols   = array(
			'payment_complete'   => __( 'Payment', 'lnkup-marketplace' ),
			'documents_complete' => __( 'Documents', 'lnkup-marketplace' ),
			'packet_viewed'      => __( 'Packet', 'lnkup-marketplace' ),
			'booth_assigned'     => __( 'Booth', 'lnkup-marketplace' ),
			'parking_assigned'   => __( 'Parking', 'lnkup-marketplace' ),
			'loadin_assigned'    => __( 'Load-in', 'lnkup-marketplace' ),
			'qr_ready'           => __( 'Pass', 'lnkup-marketplace' ),
		);
		$owners = array(
			'vendor'    => __( 'Vendor', 'lnkup-marketplace' ),
			'organizer' => __( 'You', 'lnkup-marketplace' ),
			'system'    => __( 'LNKup', 'lnkup-marketplace' ),
		);
		$html  .= '<div class="lnkup-roster vos-table-wrap" data-testid="lnkup-roster-matrix"><table class="vos-table lnkup-roster-table">';
		$html  .= '<caption class="vos-sr">' . esc_html__( 'Vendor roster and readiness by requirement', 'lnkup-marketplace' ) . '</caption><thead><tr>';
		$html  .= '<th scope="col" class="vos-check"><span class="vos-sr">' . esc_html__( 'Select', 'lnkup-marketplace' ) . '</span></th>';
		$html  .= '<th scope="col">' . esc_html__( 'Vendor', 'lnkup-marketplace' ) . '</th>';
		foreach ( $cols as $label ) {
			$html .= '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		$html .= '<th scope="col">' . esc_html__( 'Check-in', 'lnkup-marketplace' ) . '</th><th scope="col">' . esc_html__( 'Next step · who', 'lnkup-marketplace' ) . '</th><th scope="col" class="vos-actions">' . esc_html__( 'Actions', 'lnkup-marketplace' ) . '</th></tr></thead><tbody class="lnkup-roster__list">';
		foreach ( $rows as $row ) {
			$app_id = (int) $row['application_id'];
			$groups = implode( ' ', (array) ( $membership[ $app_id ] ?? array() ) );
			$url    = $row['profile_id'] ? add_query_arg( 'vendor', $row['profile_id'], lnkup_page_url_helper( 'organizer-dashboard' ) ) : '#';
			$html  .= '<tr class="lnkup-roster-card" data-app-id="' . esc_attr( (string) $app_id ) . '" data-groups="' . esc_attr( $groups ) . '">';
			$html  .= '<td class="vos-check"><label class="lnkup-roster-card__pick"><input type="checkbox" class="lnkup-roster-pick" value="' . esc_attr( (string) $app_id ) . '" /><span class="screen-reader-text">' . esc_html( sprintf( /* translators: %s: vendor name */ __( 'Select %s', 'lnkup-marketplace' ), (string) $row['business_name'] ) ) . '</span></label></td>';
			$html  .= '<td class="lnkup-roster-card__main"><a class="vos-table__primary" href="' . esc_url( $url ) . '">' . esc_html( $row['business_name'] ) . '</a>';
			if ( ! empty( $row['vendor_score'] ) && LNKup_Capabilities::user_has_manage_all() ) {
				$html .= ' <span class="lnkup-intel-score-inline" title="' . esc_attr__( 'Vendor score', 'lnkup-marketplace' ) . '">' . esc_html( (string) $row['vendor_score'] ) . '</span>';
			}
			$html .= '<span class="lnkup-roster-card__status" data-tone="' . esc_attr( self::status_tone( $row ) ) . '">' . esc_html( (string) $row['ops_label'] ) . '</span>';
			if ( class_exists( 'LNKup_Vendor_Reliability_Intel' ) && ! empty( $row['profile_id'] ) ) {
				$intel = LNKup_Vendor_Reliability_Intel::for_profile( (int) $row['profile_id'] );
				if ( $intel && ! empty( $intel['flags'] ) ) {
					$flag  = $intel['flags'][0];
					$html .= ' <span class="lnkup-intel-flag lnkup-intel-flag--' . esc_attr( $flag['type'] ) . '">' . esc_html( $flag['label'] ) . '</span>';
				}
			}
			$html .= '<span class="lnkup-roster-card__note vos-table__sub">' . esc_html( (string) ( $row['message'] ?? '' ) ) . '</span>';
			$html .= '<span class="lnkup-roster-card__activity vos-table__sub"></span></td>';
			$html .= self::readiness_cells( $row, $cols, $owners );
			$html .= '<td class="vos-actions"><span class="lnkup-roster-card__actions">' . self::render_action_buttons( $row );
			$html .= '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost lnkup-crm-review-open" data-app-id="' . esc_attr( (string) $app_id ) . '">' . esc_html__( 'Review', 'lnkup-marketplace' ) . '</button>';
			$html .= '</span></td></tr>';
		}
		return $html . '</tbody></table></div>';
	}

	/**
	 * Chip tone for a roster row: blocked bookings warn, holds wait, checked-in succeeds.
	 *
	 * @param array<string, mixed> $row Roster row.
	 */
	private static function status_tone( array $row ): string {
		if ( ! empty( $row['no_show'] ) ) {
			return 'danger';
		}
		if ( in_array( (string) $row['ops_status'], array( 'checked_in', 'late', 'setting_up', 'live_ready', 'checked_out' ), true ) ) {
			return 'success';
		}
		$reason = (string) ( $row['reason'] ?? '' );
		if ( 'invalid_booking' === $reason || 'documents' === $reason ) {
			return 'warning';
		}
		if ( 'payment_pending' === $reason ) {
			return 'hold';
		}
		return 'info';
	}

	/**
	 * Readiness cells for booked vendors (approved or paid); one spanning note otherwise.
	 *
	 * @param array<string, mixed>  $row    Roster row.
	 * @param array<string, string> $cols   Checklist key => label.
	 * @param array<string, string> $owners Owner key => label.
	 */
	private static function readiness_cells( array $row, array $cols, array $owners ): string {
		$app_id  = (int) $row['application_id'];
		$checked = ! empty( $row['checked_in'] );
		if ( ! in_array( (string) $row['status'], array( 'approved', 'payment_required', 'paid', 'completed' ), true ) || ! class_exists( 'LNKup_OS_UI' ) ) {
			return '<td colspan="' . esc_attr( (string) ( count( $cols ) + 2 ) ) . '" class="lnkup-muted">' . esc_html__( 'Readiness starts once you approve this vendor.', 'lnkup-marketplace' ) . '</td>';
		}
		$items = array();
		foreach ( (array) ( LNKup_Vendor_Readiness::checklist( $app_id )['items'] ?? array() ) as $item ) {
			$items[ $item['key'] ] = $item;
		}
		$html = '';
		$next = null;
		foreach ( $cols as $key => $label ) {
			if ( ! isset( $items[ $key ] ) ) {
				$html .= '<td><span class="lnkup-muted">' . esc_html__( 'Not required', 'lnkup-marketplace' ) . '</span></td>';
				continue;
			}
			$item = $items[ $key ];
			if ( $item['done'] ) {
				$html .= '<td>' . LNKup_OS_UI::status( __( 'Done', 'lnkup-marketplace' ), 'success' ) . '</td>';
				continue;
			}
			$blocked = 'blocked' === ( $item['status'] ?? '' );
			$html   .= '<td>' . LNKup_OS_UI::status( $blocked ? __( 'Blocked', 'lnkup-marketplace' ) : __( 'To do', 'lnkup-marketplace' ), $blocked ? 'danger' : 'warning' ) . '</td>';
			$next    = $next ?? $item;
		}
		$html .= '<td>' . ( $checked ? LNKup_OS_UI::status( __( 'Checked in', 'lnkup-marketplace' ), 'success' ) : '<span class="lnkup-muted">—</span>' ) . '</td>';
		$html .= '<td>' . ( $next ? esc_html( (string) ( $next['label'] ?? '' ) ) . '<span class="vos-table__sub">' . esc_html( $owners[ $next['owner'] ?? 'vendor' ] ?? '' ) . '</span>' : esc_html__( 'Ready', 'lnkup-marketplace' ) ) . '</td>';
		return $html;
	}

	/**
	 * Buttons for actions this actor may perform.
	 *
	 * @param array<string, mixed> $row Roster row.
	 */
	private static function render_action_buttons( array $row ) {
		$labels = class_exists( 'LNKup_Checkins' ) ? LNKup_Checkins::actions() : array();
		$bulk   = array(
			'checked_in'     => 'check_in',
			'no_show'        => 'no_show',
			'late_arrival'   => 'late_arrival',
			'setup_started'  => 'setup_started',
			'setup_complete' => 'setup_complete',
			'checked_out'    => 'mark_complete',
		);
		$html = '';
		foreach ( (array) ( $row['actions'] ?? array() ) as $action ) {
			if ( ! isset( $bulk[ $action ] ) ) {
				continue;
			}
			$html .= '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-roster-action" data-roster-action="' . esc_attr( $bulk[ $action ] ) . '" data-app-id="' . esc_attr( (string) $row['application_id'] ) . '">' . esc_html( $labels[ $action ] ?? $action ) . '</button>';
		}
		return $html;
	}

	/**
	 * Bulk action on applications.
	 */
	public static function bulk_action( $event_id, $organizer_id, $app_ids, $action, $extra = array() ) {
		if ( ! LNKup_Checkins::actor_can_operate( $event_id, $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$results = array();
		foreach ( (array) $app_ids as $app_id ) {
			$app_id = absint( $app_id );
			if ( (int) get_post_meta( $app_id, '_lnkup_event_id', true ) !== (int) $event_id ) {
				$results[] = array(
					'application_id' => $app_id,
					'success'        => false,
					'reason'         => 'wrong_event',
					'message'        => __( 'That application belongs to a different event.', 'lnkup-marketplace' ),
				);
				continue;
			}
			switch ( $action ) {
				case 'approve':
				case 'reject':
				case 'waitlist':
				case 'allow_reapply':
					$results[] = LNKup_Applications::handle_organizer_action( $app_id, $action, $organizer_id, $extra );
					break;
				case 'check_in':
				case 'no_show':
				case 'late_arrival':
				case 'setup_started':
				case 'setup_complete':
				case 'mark_complete':
					$map = array(
						'check_in'       => 'checked_in',
						'no_show'        => 'no_show',
						'late_arrival'   => 'late_arrival',
						'setup_started'  => 'setup_started',
						'setup_complete' => 'setup_complete',
						'mark_complete'  => 'checked_out',
					);
					$recorded = LNKup_Checkins::record( $app_id, $map[ $action ], 'bulk', $event_id );
					$results[] = $recorded;
					break;
				case 'add_tag':
					if ( ! empty( $extra['tag'] ) ) {
						LNKup_Vendor_Tags::add( $organizer_id, (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ), $extra['tag'] );
					}
					$results[] = true;
					break;
				case 'add_list':
					if ( ! empty( $extra['list_id'] ) ) {
						LNKup_Vendor_Lists::add_vendor( (int) $extra['list_id'], $organizer_id, (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true ) );
					}
					$results[] = true;
					break;
			}
		}
		$succeeded = array();
		$failed    = array();
		foreach ( $results as $item ) {
			if ( is_array( $item ) ) {
				if ( ! empty( $item['success'] ) ) {
					$succeeded[] = (int) ( $item['application_id'] ?? 0 );
				} else {
					$failed[] = $item;
				}
				continue;
			}
			if ( is_wp_error( $item ) ) {
				$failed[] = array(
					'success' => false,
					'reason'  => $item->get_error_code(),
					'message' => $item->get_error_message(),
				);
				continue;
			}
			if ( $item ) {
				$succeeded[] = 0;
			}
		}
		return array(
			'success'   => array() === $failed,
			'succeeded' => $succeeded,
			'failed'    => $failed,
			'results'   => $results,
		);
	}

	/**
	 * Refresh vendor reputation after roster action.
	 *
	 * @param int $app_id Application ID.
	 */
	private static function refresh_vendor_reputation( $app_id ) {
		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		if ( $profile_id && class_exists( 'LNKup_Vendor_Reputation' ) ) {
			LNKup_Vendor_Reputation::refresh( $profile_id );
		}
	}
}
