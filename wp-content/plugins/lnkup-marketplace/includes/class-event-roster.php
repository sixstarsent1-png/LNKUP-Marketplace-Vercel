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
		$html .= '<div class="lnkup-roster"><div class="lnkup-roster__list">';
		foreach ( $rows as $row ) {
			$groups = implode( ' ', (array) ( $membership[ $row['application_id'] ] ?? array() ) );
			$url    = $row['profile_id'] ? add_query_arg( 'vendor', $row['profile_id'], lnkup_page_url_helper( 'organizer-dashboard' ) ) : '#';
			$html  .= '<article class="lnkup-roster-card" data-app-id="' . esc_attr( (string) $row['application_id'] ) . '" data-groups="' . esc_attr( $groups ) . '">';
			$html  .= '<label class="lnkup-roster-card__pick"><input type="checkbox" class="lnkup-roster-pick" value="' . esc_attr( (string) $row['application_id'] ) . '" /><span class="screen-reader-text">' . esc_html( sprintf( /* translators: %s: vendor name */ __( 'Select %s', 'lnkup-marketplace' ), (string) $row['business_name'] ) ) . '</span></label>';
			$html  .= '<div class="lnkup-roster-card__main"><strong><a href="' . esc_url( $url ) . '">' . esc_html( $row['business_name'] ) . '</a></strong>';
			if ( ! empty( $row['vendor_score'] ) && LNKup_Capabilities::user_has_manage_all() ) {
				$html .= ' <span class="lnkup-intel-score-inline" title="' . esc_attr__( 'Vendor score', 'lnkup-marketplace' ) . '">' . esc_html( (string) $row['vendor_score'] ) . '</span>';
			}
			$html .= '<span class="lnkup-roster-card__status lnkup-status lnkup-status--' . esc_attr( (string) ( $row['ops_status'] ?: $row['readiness'] ) ) . '">' . esc_html( (string) $row['ops_label'] ) . '</span>';
			if ( class_exists( 'LNKup_Vendor_Reliability_Intel' ) && ! empty( $row['profile_id'] ) ) {
				$intel = LNKup_Vendor_Reliability_Intel::for_profile( (int) $row['profile_id'] );
				if ( $intel && ! empty( $intel['flags'] ) ) {
					$flag  = $intel['flags'][0];
					$html .= ' <span class="lnkup-intel-flag lnkup-intel-flag--' . esc_attr( $flag['type'] ) . '">' . esc_html( $flag['label'] ) . '</span>';
				}
			}
			$html .= '<p class="lnkup-roster-card__note lnkup-muted">' . esc_html( (string) ( $row['message'] ?? '' ) ) . '</p>';
			$html .= '<p class="lnkup-roster-card__activity lnkup-muted"></p>';
			$html .= '</div>';
			$html .= '<div class="lnkup-roster-card__actions">';
			$html .= self::render_action_buttons( $row );
			$html .= '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-crm-review-open" data-app-id="' . esc_attr( (string) $row['application_id'] ) . '">' . esc_html__( 'Review', 'lnkup-marketplace' ) . '</button>';
			$html .= '</div></article>';
		}
		return $html . '</div></div>';
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
