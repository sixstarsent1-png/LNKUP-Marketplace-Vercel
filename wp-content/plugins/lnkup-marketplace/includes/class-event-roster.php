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
		if ( ! LNKup_Capabilities::user_owns_post( $event_id, $organizer_id, '_lnkup_organizer_user_id' ) && ! LNKup_Capabilities::user_has_manage_all( $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ) );
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
		foreach ( $apps as $app ) {
			$row = self::format_roster_row( $app, $organizer_id );
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( get_post_meta( $app->ID, '_lnkup_checked_in_at', true ) ) {
				$groups['checked_in'][] = $row;
			} elseif ( get_post_meta( $app->ID, '_lnkup_no_show_at', true ) ) {
				$groups['no_shows'][] = $row;
			} elseif ( 'rejected' === $status ) {
				$groups['rejected'][] = $row;
			} elseif ( 'waitlisted' === $status ) {
				$groups['waitlisted'][] = $row;
			} elseif ( in_array( $status, array( 'payment_required', 'approved' ), true ) ) {
				$groups['missing_payment'][] = $row;
			} elseif ( 'paid' === $status ) {
				$readiness = LNKup_Vendor_Readiness::refresh( $app->ID );
				if ( LNKup_Vendor_Readiness::STATUS_NEEDS_DOCUMENTS === $readiness ) {
					$groups['missing_documents'][] = $row;
				} else {
					$groups['paid'][] = $row;
				}
				$groups['approved'][] = $row;
			} elseif ( in_array( $status, array( 'submitted', 'under_review' ), true ) ) {
				$groups['applied'][] = $row;
			}
		}
		return array(
			'event_id' => $event_id,
			'groups'   => $groups,
			'total'    => count( $apps ),
			'html'     => self::render_html( $groups ),
		);
	}

	/**
	 * Format roster row.
	 */
	private static function format_roster_row( $app, $organizer_id ) {
		$vendor_id = (int) get_post_meta( $app->ID, '_lnkup_vendor_user_id', true );
		$profile_id = (int) get_post_meta( $app->ID, '_lnkup_vendor_profile_id', true );
		$profile = $profile_id ? LNKup_Vendor_CRM_Profile::get( $profile_id, $organizer_id ) : null;
		$readiness = LNKup_Vendor_Readiness::refresh( $app->ID );
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
			'no_show'        => (bool) get_post_meta( $app->ID, '_lnkup_no_show_at', true ),
			'vendor_score'   => $profile ? $profile['vendor_score'] : 0,
			'flags'          => $profile ? $profile['flags'] : array(),
		);
	}

	/**
	 * Render roster HTML.
	 */
	private static function render_html( $groups ) {
		$html = '<div class="lnkup-roster">';
		foreach ( $groups as $key => $rows ) {
			if ( ! $rows ) {
				continue;
			}
			$html .= '<section class="lnkup-roster__group"><h3>' . esc_html( ucwords( str_replace( '_', ' ', $key ) ) ) . ' (' . count( $rows ) . ')</h3>';
			$html .= '<div class="lnkup-roster__list">';
			foreach ( $rows as $row ) {
				$url = $row['profile_id'] ? add_query_arg( 'vendor', $row['profile_id'], lnkup_page_url_helper( 'organizer-dashboard' ) ) : '#';
				$html .= '<article class="lnkup-roster-card" data-app-id="' . esc_attr( (string) $row['application_id'] ) . '">';
				$html .= '<label class="lnkup-roster-card__pick"><input type="checkbox" class="lnkup-roster-pick" value="' . esc_attr( (string) $row['application_id'] ) . '" /></label>';
				$html .= '<div class="lnkup-roster-card__main"><strong><a href="' . esc_url( $url ) . '">' . esc_html( $row['business_name'] ) . '</a></strong>';
				if ( ! empty( $row['vendor_score'] ) ) {
					$html .= ' <span class="lnkup-intel-score-inline" title="' . esc_attr__( 'Vendor score', 'lnkup-marketplace' ) . '">' . esc_html( (string) $row['vendor_score'] ) . '</span>';
				}
				$html .= '<span class="lnkup-status lnkup-status--' . esc_attr( $row['readiness'] ) . '">' . esc_html( $row['readiness_label'] ) . '</span>';
				if ( class_exists( 'LNKup_Vendor_Reliability_Intel' ) && ! empty( $row['profile_id'] ) ) {
					$intel = LNKup_Vendor_Reliability_Intel::for_profile( (int) $row['profile_id'] );
					if ( $intel && ! empty( $intel['flags'] ) ) {
						$flag = $intel['flags'][0];
						$html .= ' <span class="lnkup-intel-flag lnkup-intel-flag--' . esc_attr( $flag['type'] ) . '">' . esc_html( $flag['label'] ) . '</span>';
					}
				}
				$html .= '</div>';
				$html .= '<div class="lnkup-roster-card__actions">';
				$html .= '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-crm-checkin" data-app-id="' . esc_attr( (string) $row['application_id'] ) . '">Check in</button>';
				$html .= '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost lnkup-crm-noshow" data-app-id="' . esc_attr( (string) $row['application_id'] ) . '">No-show</button>';
				$html .= '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-crm-late" data-app-id="' . esc_attr( (string) $row['application_id'] ) . '">Late</button>';
				$html .= '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-crm-review-open" data-app-id="' . esc_attr( (string) $row['application_id'] ) . '">Review</button>';
				if ( ! empty( $row['readiness_label'] ) ) {
					$html .= '<span class="lnkup-badge lnkup-badge--' . esc_attr( $row['readiness'] ) . '">' . esc_html( $row['readiness_label'] ) . '</span>';
				}
				$html .= '</div></article>';
			}
			$html .= '</div></section>';
		}
		return $html . '</div>';
	}

	/**
	 * Bulk action on applications.
	 */
	public static function bulk_action( $event_id, $organizer_id, $app_ids, $action, $extra = array() ) {
		if ( ! LNKup_Capabilities::user_owns_post( $event_id, $organizer_id, '_lnkup_organizer_user_id' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$results = array();
		foreach ( (array) $app_ids as $app_id ) {
			$app_id = absint( $app_id );
			if ( (int) get_post_meta( $app_id, '_lnkup_event_id', true ) !== (int) $event_id ) {
				continue;
			}
			switch ( $action ) {
				case 'approve':
				case 'reject':
				case 'waitlist':
					$results[] = LNKup_Applications::handle_organizer_action( $app_id, $action, $organizer_id );
					break;
				case 'check_in':
					if ( class_exists( 'LNKup_Checkins' ) ) {
						LNKup_Checkins::record( $app_id, 'checked_in', 'manual' );
					} else {
						update_post_meta( $app_id, '_lnkup_checked_in_at', current_time( 'mysql' ) );
					}
					delete_post_meta( $app_id, '_lnkup_no_show_at' );
					LNKup_Organizer_CRM::log_activity( 'checked_in', $organizer_id, (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ), array( 'application_id' => $app_id, 'event_id' => $event_id ) );
					self::refresh_vendor_reputation( $app_id );
					$results[] = true;
					break;
				case 'no_show':
					if ( class_exists( 'LNKup_Checkins' ) ) {
						LNKup_Checkins::record( $app_id, 'no_show', 'manual' );
					} else {
						update_post_meta( $app_id, '_lnkup_no_show_at', current_time( 'mysql' ) );
					}
					LNKup_Organizer_CRM::log_activity( 'no_show', $organizer_id, (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ), array( 'application_id' => $app_id, 'event_id' => $event_id ) );
					self::refresh_vendor_reputation( $app_id );
					$results[] = true;
					break;
				case 'late_arrival':
					if ( class_exists( 'LNKup_Checkins' ) ) {
						LNKup_Checkins::record( $app_id, 'late_arrival', 'manual' );
					} else {
						update_post_meta( $app_id, '_lnkup_late_arrival_at', current_time( 'mysql' ) );
					}
					self::refresh_vendor_reputation( $app_id );
					$results[] = true;
					break;
				case 'mark_complete':
					if ( class_exists( 'LNKup_Checkins' ) ) {
						LNKup_Checkins::record( $app_id, 'checked_out', 'manual' );
					} else {
						update_post_meta( $app_id, '_lnkup_event_completed_at', current_time( 'mysql' ) );
						update_post_meta( $app_id, '_lnkup_application_status', 'completed' );
					}
					self::refresh_vendor_reputation( $app_id );
					$results[] = true;
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
		return $results;
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
