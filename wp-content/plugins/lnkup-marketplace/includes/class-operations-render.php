<?php
/**
 * Operations UI render helpers.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Operations_Render {

	/**
	 * Panel shell header.
	 *
	 * @param string $title       Title text.
	 * @param string $subtitle    Subtitle text.
	 * @param string $actions_html Optional actions HTML.
	 */
	public static function panel_header( $title, $subtitle = '', $actions_html = '' ) {
		$html  = '<header class="lnkup-ops-panel-shell__head">';
		$html .= '<div class="lnkup-ops-panel-shell__heading">';
		$html .= '<h3 class="lnkup-ops-panel-shell__title">' . esc_html( $title ) . '</h3>';
		if ( $subtitle ) {
			$html .= '<p class="lnkup-muted lnkup-ops-panel-shell__subtitle">' . esc_html( $subtitle ) . '</p>';
		}
		$html .= '</div>';
		if ( $actions_html ) {
			$html .= '<div class="lnkup-ops-panel-shell__actions">' . $actions_html . '</div>';
		}
		$html .= '</header>';
		return $html;
	}

	/**
	 * Panel stats strip.
	 *
	 * @param array $stats Stats rows as label/value arrays.
	 */
	public static function panel_stats_strip( $stats ) {
		if ( empty( $stats ) ) {
			return '';
		}
		$html = '<div class="lnkup-ops-panel-shell__stats">';
		foreach ( (array) $stats as $stat ) {
			$label = $stat['label'] ?? '';
			$value = $stat['value'] ?? '';
			$html .= '<div class="lnkup-ops-panel-shell__stat"><span class="lnkup-muted">' . esc_html( $label ) . '</span><strong>' . esc_html( (string) $value ) . '</strong></div>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * KPI cards row HTML.
	 *
	 * @param array $kpis KPI data.
	 */
	public static function kpi_row( $kpis ) {
		if ( ! $kpis ) {
			return '';
		}
		if ( ! empty( $kpis['lifecycle']['cards'] ) ) {
			$html = '<p class="vos-eyebrow" data-testid="lnkup-ops-phase">' . esc_html( (string) $kpis['lifecycle']['phase_label'] ) . '</p><div class="lnkup-ops-kpis vos-metrics" data-testid="lnkup-ops-lifecycle-kpis">';
			foreach ( $kpis['lifecycle']['cards'] as $card ) {
				$html .= '<div class="vos-metric"><span class="vos-metric__label">' . esc_html( $card['label'] ) . '</span><span class="vos-metric__value oos-fraction">' . esc_html( $card['value'] ) . '</span><span class="vos-metric__hint oos-denominator">' . esc_html( $card['denominator'] ) . '</span></div>';
			}
			return $html . '</div>';
		}
		$readiness_value = ( $kpis['readiness_percent'] ?? 0 ) . '%';
		$readiness_sub   = $kpis['readiness_ready_label'] ?? '';
		$cards = array(
			array( __( 'Approved', 'lnkup-marketplace' ), $kpis['approved_vendors'] ?? 0, '' ),
			array( __( 'Paid', 'lnkup-marketplace' ), $kpis['paid_vendors'] ?? 0, '' ),
			array( __( 'Readiness', 'lnkup-marketplace' ), $readiness_value, $readiness_sub ),
			array( __( 'Checked in', 'lnkup-marketplace' ), $kpis['checked_in'] ?? 0, '' ),
			array( __( 'Late', 'lnkup-marketplace' ), $kpis['late_vendors'] ?? 0, '' ),
			array( __( 'Missing', 'lnkup-marketplace' ), $kpis['missing_vendors'] ?? 0, '' ),
			array( __( 'Support', 'lnkup-marketplace' ), $kpis['support_requests'] ?? 0, '' ),
			array( __( 'Check-Ins', 'lnkup-marketplace' ), $kpis['avg_checkin_time'] ?? '—', '' ),
			array( __( 'Setup %', 'lnkup-marketplace' ), ( $kpis['setup_completion_pct'] ?? 0 ) . '%', '' ),
		);
		$html = '<div class="lnkup-ops-kpis lnkup-grid lnkup-grid--4">';
		foreach ( $cards as $card ) {
			$html .= '<div class="lnkup-stat-card lnkup-ops-kpi">';
			$html .= '<p class="lnkup-stat-card__value">' . esc_html( (string) $card[1] ) . '</p>';
			$html .= '<p class="lnkup-stat-card__label">' . esc_html( $card[0] ) . '</p>';
			if ( ! empty( $card[2] ) ) {
				$html .= '<p class="lnkup-stat-card__sub lnkup-muted">' . esc_html( (string) $card[2] ) . '</p>';
			}
			$html .= '</div>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Lifecycle progress bar.
	 *
	 * @param array $lifecycle Lifecycle DTO.
	 * @param int   $event_id  Event (adds the open/overdue task count when tasks are on).
	 */
	public static function lifecycle_bar( $lifecycle, $event_id = 0 ) {
		if ( ! $lifecycle ) {
			return '';
		}
		$html = '<div class="lnkup-ops-lifecycle">';
		$html .= '<div class="lnkup-ops-lifecycle__head"><strong>' . esc_html( $lifecycle['stage_label'] ?? '' ) . '</strong><span>' . esc_html( (string) ( $lifecycle['progress'] ?? 0 ) ) . '%</span></div>';
		$html .= '<div class="lnkup-ops-lifecycle__bar"><span style="width:' . esc_attr( (string) ( $lifecycle['progress'] ?? 0 ) ) . '%"></span></div>';
		if ( ! empty( $lifecycle['tasks'] ) ) {
			$html .= '<ul class="lnkup-ops-lifecycle__tasks">';
			foreach ( $lifecycle['tasks'] as $task ) {
				$html .= '<li>' . esc_html( $task ) . '</li>';
			}
			$html .= '</ul>';
		}
		if ( $event_id && LNKup_Feature_Flags::organizer_ops() && LNKup_Organizer_OS_Tables::ready( '2' ) ) {
			$counts = LNKup_Event_Tasks::event_counts( $event_id );
			$url    = add_query_arg( array( 'event' => $event_id, 'tab' => 'tasks' ), lnkup_page_url_helper( 'organizer-dashboard' ) );
			$label  = $counts['open']
				/* translators: 1: open tasks, 2: overdue tasks */
				? sprintf( __( '%1$d open tasks, %2$d overdue', 'lnkup-marketplace' ), $counts['open'], $counts['overdue'] )
				: __( 'No open tasks', 'lnkup-marketplace' );
			$html .= '<p class="lnkup-ops-lifecycle__task-count' . ( $counts['overdue'] ? ' is-overdue' : '' ) . '"><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Compliance alerts list.
	 *
	 * @param array $alerts Alert rows.
	 */
	public static function compliance_alerts( $alerts, $groups = null ) {
		if ( ! $alerts ) {
			return '<p class="lnkup-muted">' . esc_html__( 'No compliance alerts.', 'lnkup-marketplace' ) . '</p>';
		}
		if ( is_array( $groups ) && $groups ) {
			$html = '<ul class="lnkup-ops-alerts" data-testid="lnkup-ops-compliance-groups">';
			foreach ( $groups as $group ) {
				$names = array_slice( array_filter( $group['vendors'] ), 0, 3 );
				$more  = max( 0, (int) $group['count'] - count( $names ) );
				$html .= '<li class="lnkup-ops-alert lnkup-ops-alert--' . esc_attr( $group['severity'] ) . '"><strong>' . esc_html( $group['requirement'] ) . '</strong> · ' . esc_html( $group['owner_label'] ) . ' · ' . esc_html( sprintf( /* translators: %d: vendors */ _n( '%d vendor', '%d vendors', (int) $group['count'], 'lnkup-marketplace' ), (int) $group['count'] ) );
				$html .= '<br><span class="lnkup-muted">' . esc_html( implode( ', ', $names ) . ( $more ? ' ' . sprintf( /* translators: %d: more */ __( '+%d more', 'lnkup-marketplace' ), $more ) : '' ) ) . '</span></li>';
			}
			return $html . '</ul>';
		}
		$html = '<ul class="lnkup-ops-alerts">';
		foreach ( $alerts as $alert ) {
			$sev = esc_attr( $alert['severity'] ?? 'medium' );
			$html .= '<li class="lnkup-ops-alert lnkup-ops-alert--' . $sev . '">';
			$html .= esc_html( $alert['message'] ?? '' );
			if ( ! empty( $alert['next_action'] ) ) {
				$html .= ' <span class="lnkup-muted">— ' . esc_html( $alert['next_action'] ) . '</span>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
		return $html;
	}

	/**
	 * SVG progress ring markup.
	 *
	 * @param int    $percent Percent 0-100.
	 * @param string $tier    Tier slug.
	 */
	public static function readiness_ring( $percent, $tier = '' ) {
		$percent = max( 0, min( 100, (int) $percent ) );
		$radius  = 42;
		$circ    = 2 * M_PI * $radius;
		$offset  = $circ - ( $percent / 100 ) * $circ;
		$tier    = esc_attr( $tier ?: 'on_track' );
		$html    = '<div class="lnkup-ops-readiness__ring-wrap lnkup-ops-readiness__ring-wrap--' . $tier . '" data-percent="' . esc_attr( (string) $percent ) . '">';
		$html   .= '<svg class="lnkup-ops-readiness__ring-svg" viewBox="0 0 100 100" aria-hidden="true">';
		$html   .= '<circle class="lnkup-ops-readiness__ring-track" cx="50" cy="50" r="' . esc_attr( (string) $radius ) . '" />';
		$html   .= '<circle class="lnkup-ops-readiness__ring-progress" cx="50" cy="50" r="' . esc_attr( (string) $radius ) . '" stroke-dasharray="' . esc_attr( (string) $circ ) . '" stroke-dashoffset="' . esc_attr( (string) $offset ) . '" />';
		$html   .= '</svg>';
		$html   .= '<span class="lnkup-ops-readiness__ring-value">' . esc_html( (string) $percent ) . '%</span>';
		$html   .= '</div>';
		return $html;
	}

	/**
	 * Readiness checklist HTML (vendor).
	 *
	 * @param array  $checklist Checklist DTO.
	 * @param int    $app_id    Application ID for CTA URLs.
	 */
	public static function readiness_checklist( $checklist, $app_id = 0 ) {
		if ( ! $checklist ) {
			return '';
		}
		$app_id = $app_id ?: (int) ( $_GET['app'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tier   = $checklist['tier'] ?? 'on_track';
		$dashboard = lnkup_page_url_helper( 'vendor-dashboard' );

		$html = '<div class="lnkup-ops-readiness lnkup-ops-readiness--v2">';
		$html .= '<div class="lnkup-ops-readiness__hero">';
		$html .= self::readiness_ring( $checklist['percent'] ?? 0, $tier );
		$html .= '<div class="lnkup-ops-readiness__meta">';
		$html .= '<span class="lnkup-ops-readiness__pill lnkup-ops-readiness__pill--' . esc_attr( $tier ) . '">' . esc_html( $checklist['tier_label'] ?? $checklist['label'] ?? '' ) . '</span>';
		$html .= '<p class="lnkup-muted">' . esc_html__( 'Complete each item before event day.', 'lnkup-marketplace' ) . '</p>';
		$html .= '</div></div>';

		if ( ! empty( $checklist['next_action'] ) ) {
			$na   = $checklist['next_action'];
			$url  = $na['url'] ?? '';
			if ( $app_id && empty( $url ) ) {
				$args = array( 'view' => 'operations', 'app' => $app_id, 'tab' => $na['tab'] ?? 'readiness' );
				if ( ! empty( $na['section'] ) ) {
					$args['section'] = $na['section'];
				}
				$url = add_query_arg( $args, $dashboard );
			}
			$html .= '<div class="lnkup-ops-readiness__next">';
			$html .= '<p class="lnkup-ops-readiness__next-label">' . esc_html__( 'Next step', 'lnkup-marketplace' ) . '</p>';
			$html .= '<p><strong>' . esc_html( $na['label'] ?? '' ) . '</strong></p>';
			if ( $url ) {
				$html .= '<a href="' . esc_url( $url ) . '" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm">' . esc_html__( 'Go', 'lnkup-marketplace' ) . '</a>';
			}
			$html .= '</div>';
		}

		$flat = array();
		foreach ( (array) ( $checklist['groups'] ?? array() ) as $group ) {
			foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
				$flat[] = $item;
			}
		}
		if ( ! $flat ) {
			$flat = (array) ( $checklist['items'] ?? array() );
		}
		$has_owner = (bool) array_filter(
			$flat,
			static function ( $item ) {
				return isset( $item['owner'] );
			}
		);
		if ( $has_owner ) {
			$buckets = array(
				'vendor'    => array(),
				'organizer' => array(),
				'done'      => array(),
			);
			foreach ( $flat as $item ) {
				if ( ! empty( $item['done'] ) ) {
					$buckets['done'][] = $item;
				} elseif ( 'organizer' === $item['owner'] || 'system' === $item['owner'] ) {
					$buckets['organizer'][] = $item;
				} else {
					$buckets['vendor'][] = $item;
				}
			}
			$vendor_total = count(
				array_filter(
					$flat,
					static function ( $item ) {
						return 'vendor' === ( $item['owner'] ?? 'vendor' );
					}
				)
			);
			$vendor_done  = $vendor_total - count( $buckets['vendor'] );
			$html .= '<p class="vos-readiness__split">' . esc_html(
				sprintf(
					/* translators: 1: done count, 2: total, 3: organizer pending count */
					__( 'You have finished %1$d of %2$d of your steps. %3$d items are waiting on the organizer.', 'lnkup-marketplace' ),
					$vendor_done,
					$vendor_total,
					count( $buckets['organizer'] )
				)
			) . '</p>';
			$titles = array(
				'vendor'    => __( 'Your next steps', 'lnkup-marketplace' ),
				'organizer' => __( 'Waiting on organizer', 'lnkup-marketplace' ),
			);
			foreach ( $titles as $bucket => $title ) {
				if ( ! $buckets[ $bucket ] ) {
					continue;
				}
				$html .= '<section class="lnkup-ops-readiness__group vos-readiness__group vos-readiness__group--' . esc_attr( $bucket ) . '">';
				$html .= '<h4 class="lnkup-ops-readiness__group-title">' . esc_html( $title ) . ' <span class="vos-muted">(' . count( $buckets[ $bucket ] ) . ')</span></h4>';
				$html .= '<ul class="lnkup-ops-readiness__list">';
				foreach ( $buckets[ $bucket ] as $item ) {
					$is_blocked = 'blocked' === ( $item['status'] ?? '' );
					$html      .= '<li class="is-' . ( 'organizer' === $bucket ? 'waiting' : ( $is_blocked ? 'blocked' : 'pending' ) ) . '">';
					$html      .= '<span class="lnkup-ops-readiness__icon" aria-hidden="true">' . ( 'organizer' === $bucket ? '…' : ( $is_blocked ? '!' : '○' ) ) . '</span>';
					$html      .= '<span class="lnkup-ops-readiness__item-label">' . esc_html( (string) $item['label'] ) . '</span>';
					if ( 'vendor' === $bucket && ! empty( $item['cta_tab'] ) && $app_id ) {
						$cta_args = array( 'view' => 'operations', 'app' => $app_id, 'tab' => $item['cta_tab'] );
						if ( ! empty( $item['cta_section'] ) ) {
							$cta_args['section'] = $item['cta_section'];
						}
						$html .= '<a href="' . esc_url( add_query_arg( $cta_args, $dashboard ) ) . '" class="lnkup-ops-readiness__cta">' . esc_html( ! empty( $item['action_label'] ) ? (string) $item['action_label'] : __( 'Open', 'lnkup-marketplace' ) ) . '</a>';
					}
					$html .= '</li>';
				}
				$html .= '</ul></section>';
			}
			if ( $buckets['done'] ) {
				$html .= '<details class="vos-readiness__done"><summary>' . esc_html( sprintf( /* translators: %d: count */ _n( '%d item complete', '%d items complete', count( $buckets['done'] ), 'lnkup-marketplace' ), count( $buckets['done'] ) ) ) . '</summary><ul class="lnkup-ops-readiness__list">';
				foreach ( $buckets['done'] as $item ) {
					$html .= '<li class="is-done"><span class="lnkup-ops-readiness__icon" aria-hidden="true">✓</span><span class="lnkup-ops-readiness__item-label">' . esc_html( (string) ( $item['done_label'] ?? $item['label'] ) ) . '</span></li>';
				}
				$html .= '</ul></details>';
			}
			return $html . '</div>';
		}

		$groups = ! empty( $checklist['groups'] ) ? $checklist['groups'] : array();
		if ( $groups ) {
			foreach ( $groups as $group ) {
				$html .= '<section class="lnkup-ops-readiness__group">';
				$html .= '<h4 class="lnkup-ops-readiness__group-title">' . esc_html( $group['label'] ?? '' ) . '</h4>';
				$html .= '<ul class="lnkup-ops-readiness__list">';
				foreach ( $group['items'] as $item ) {
					$status_class = 'is-' . esc_attr( $item['status'] ?? ( $item['done'] ? 'done' : 'pending' ) );
					$html .= '<li class="' . $status_class . '">';
					$icon = ! empty( $item['done'] ) ? '✓' : ( 'blocked' === ( $item['status'] ?? '' ) ? '!' : '○' );
					$html .= '<span class="lnkup-ops-readiness__icon" aria-hidden="true">' . esc_html( $icon ) . '</span>';
					$html .= '<span class="lnkup-ops-readiness__item-label">' . esc_html( $item['label'] ) . '</span>';
					if ( empty( $item['done'] ) && ! empty( $item['cta_tab'] ) && $app_id ) {
						$cta_args = array( 'view' => 'operations', 'app' => $app_id, 'tab' => $item['cta_tab'] );
						if ( ! empty( $item['cta_section'] ) ) {
							$cta_args['section'] = $item['cta_section'];
						}
						$cta_url = add_query_arg( $cta_args, $dashboard );
						$html .= '<a href="' . esc_url( $cta_url ) . '" class="lnkup-ops-readiness__cta">' . esc_html__( 'Fix', 'lnkup-marketplace' ) . '</a>';
					}
					$html .= '</li>';
				}
				$html .= '</ul></section>';
			}
		} else {
			$html .= '<ul class="lnkup-ops-readiness__list">';
			foreach ( $checklist['items'] as $item ) {
				$html .= '<li class="' . ( $item['done'] ? 'is-done' : 'is-pending' ) . '">' . esc_html( $item['label'] ) . '</li>';
			}
			$html .= '</ul>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Organizer event readiness dashboard.
	 *
	 * @param array $summary Summary from event_readiness_summary().
	 * @param int   $event_id Event ID.
	 */
	public static function readiness_dashboard( $summary, $event_id = 0 ) {
		if ( ! $summary ) {
			return '<p class="lnkup-muted">' . esc_html__( 'No vendor readiness data yet.', 'lnkup-marketplace' ) . '</p>';
		}
		$event_id = absint( $event_id );
		$dash_url = lnkup_page_url_helper( 'organizer-dashboard' );
		$html     = '<div class="lnkup-ops-readiness-dash">';
		$html    .= '<div class="lnkup-ops-readiness-dash__summary">';
		$html    .= self::readiness_ring( $summary['percent'] ?? 0, ( $summary['percent'] ?? 0 ) >= 65 ? 'on_track' : 'at_risk' );
		$html    .= '<div class="lnkup-ops-readiness-dash__counts">';
		$html    .= '<p><strong>' . esc_html( (string) ( $summary['percent'] ?? 0 ) ) . '%</strong> ' . esc_html__( 'avg readiness', 'lnkup-marketplace' ) . '</p>';
		$html    .= '<ul class="lnkup-ops-readiness-dash__tiers">';
		$html    .= '<li><span class="lnkup-ops-readiness__pill lnkup-ops-readiness__pill--ready">' . esc_html__( 'Ready', 'lnkup-marketplace' ) . '</span> ' . esc_html( (string) ( $summary['ready_count'] ?? 0 ) ) . '</li>';
		$html    .= '<li><span class="lnkup-ops-readiness__pill lnkup-ops-readiness__pill--on_track">' . esc_html__( 'On track', 'lnkup-marketplace' ) . '</span> ' . esc_html( (string) ( $summary['on_track_count'] ?? 0 ) ) . '</li>';
		$html    .= '<li><span class="lnkup-ops-readiness__pill lnkup-ops-readiness__pill--at_risk">' . esc_html__( 'At risk', 'lnkup-marketplace' ) . '</span> ' . esc_html( (string) ( $summary['at_risk_count'] ?? 0 ) ) . '</li>';
		$html    .= '</ul></div></div>';

		if ( ! empty( $summary['vendors'] ) ) {
			$html .= '<div class="lnkup-ops-table-wrap"><table class="lnkup-ops-table lnkup-ops-readiness-table"><thead><tr>';
			$html .= '<th>' . esc_html__( 'Vendor', 'lnkup-marketplace' ) . '</th>';
			$html .= '<th>' . esc_html__( 'Readiness', 'lnkup-marketplace' ) . '</th>';
			$html .= '<th>' . esc_html__( 'Status', 'lnkup-marketplace' ) . '</th>';
			$html .= '<th>' . esc_html__( 'Top blocker', 'lnkup-marketplace' ) . '</th>';
			$html .= '</tr></thead><tbody>';
			foreach ( $summary['vendors'] as $v ) {
				$tier = esc_attr( $v['tier'] ?? 'on_track' );
				$apps_url = add_query_arg(
					array(
						'app'    => $v['application_id'],
						'filter' => 'missing_docs',
					),
					lnkup_organizer_view_url( 'applications' )
				);
				$html .= '<tr>';
				$html .= '<td><a href="' . esc_url( $apps_url ) . '">' . esc_html( $v['business_name'] ?? '' ) . '</a></td>';
				$html .= '<td><span class="lnkup-ops-readiness__pill lnkup-ops-readiness__pill--' . $tier . '">' . esc_html( (string) ( $v['percent'] ?? 0 ) ) . '%</span></td>';
				$html .= '<td>' . esc_html( $v['status_label'] ?? '' ) . '</td>';
				$html .= '<td class="lnkup-muted">' . esc_html( $v['blocker'] ?? '—' ) . '</td>';
				$html .= '</tr>';
			}
			$html .= '</tbody></table></div>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Operations feed list.
	 *
	 * @param array $items Feed items.
	 */
	public static function feed_list( $items ) {
		if ( ! $items ) {
			return '<p class="lnkup-muted">' . esc_html__( 'No activity yet.', 'lnkup-marketplace' ) . '</p>';
		}
		$html = '<ul class="lnkup-ops-feed" id="lnkup-ops-feed-list">';
		foreach ( $items as $item ) {
			$ts    = ! empty( $item['created_at'] ) ? (int) strtotime( (string) $item['created_at'] ) : 0;
			$html .= '<li><strong>' . esc_html( $item['label'] ?? '' ) . '</strong> <span class="lnkup-muted">' . ( $ts && class_exists( 'LNKup_OS_UI' ) ? LNKup_OS_UI::relative_time( $ts ) : esc_html( $item['created_at'] ?? '' ) ) . '</span></li>';
		}
		$html .= '</ul>';
		return $html;
	}

	/**
	 * Kanban board shell.
	 *
	 * @param array $columns Column slug => vendors.
	 */
	public static function kanban_board( $columns ) {
		$html = '<div class="lnkup-ops-kanban" id="lnkup-ops-kanban">';
		foreach ( $columns as $slug => $vendors ) {
			$label = LNKup_Event_Operations::ops_statuses()[ $slug ] ?? $slug;
			$html .= '<div class="lnkup-ops-kanban__col" data-ops-col="' . esc_attr( $slug ) . '">';
			$html .= '<h4>' . esc_html( $label ) . ' <span>(' . count( $vendors ) . ')</span></h4>';
			$html .= '<div class="lnkup-ops-kanban__cards">';
			foreach ( $vendors as $v ) {
				$tier = esc_attr( $v['readiness_tier'] ?? 'on_track' );
				$html .= '<article class="lnkup-ops-kanban__card" draggable="true" data-app-id="' . esc_attr( (string) $v['application_id'] ) . '">';
				$html .= '<strong>' . esc_html( $v['business_name'] ) . '</strong>';
				$html .= '<span class="lnkup-ops-readiness__pill lnkup-ops-readiness__pill--' . $tier . ' lnkup-ops-kanban__readiness">' . esc_html( (string) $v['readiness_pct'] ) . '%</span>';
				if ( ! empty( $v['booth']['booth_number'] ) ) {
					$html .= '<span class="lnkup-badge">' . esc_html( $v['booth']['booth_number'] ) . '</span>';
				}
				$html .= '</article>';
			}
			$html .= '</div></div>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Organizer overview readiness summary card.
	 *
	 * @param array $summary Readiness summary DTO.
	 * @param int   $event_id Event ID.
	 */
	public static function readiness_overview_card( $summary, $event_id ) {
		if ( ! $summary ) {
			return '';
		}
		$ops_url = add_query_arg(
			array(
				'event' => absint( $event_id ),
				'ops'   => 1,
			),
			lnkup_page_url_helper( 'organizer-dashboard' )
		);
		$html  = '<div class="lnkup-ops-panel lnkup-ops-readiness-overview">';
		$html .= '<h3>' . esc_html__( 'Event readiness', 'lnkup-marketplace' ) . '</h3>';
		$html .= '<p class="lnkup-ops-readiness-overview__pct"><strong>' . esc_html( (string) ( $summary['percent'] ?? 0 ) ) . '%</strong> ' . esc_html__( 'average across paid vendors', 'lnkup-marketplace' ) . '</p>';
		if ( ( $summary['at_risk_count'] ?? 0 ) > 0 ) {
			$html .= '<p class="lnkup-ops-alert lnkup-ops-alert--high">' . esc_html( sprintf(
				/* translators: %d: vendor count */
				_n( '%d vendor at risk', '%d vendors at risk', (int) $summary['at_risk_count'], 'lnkup-marketplace' ),
				(int) $summary['at_risk_count']
			) ) . '</p>';
		}
		$html .= '<div class="lnkup-ops-readiness-overview__actions">';
		$html .= '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline lnkup-ops-goto-tab" data-ops-tab="readiness">' . esc_html__( 'View readiness', 'lnkup-marketplace' ) . '</button>';
		$html .= '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost lnkup-ops-goto-tab" data-ops-tab="kanban">' . esc_html__( 'Open roster', 'lnkup-marketplace' ) . '</button>';
		$html .= '</div></div>';
		return $html;
	}

	/**
	 * Venue map overlay with booth pins.
	 *
	 * @param int   $event_id Event ID.
	 * @param array $booths   Booth rows.
	 */
	public static function venue_map( $event_id, $booths = null, $role = 'organizer' ) {
		if ( class_exists( 'LNKup_Map_Render' ) ) {
			return LNKup_Map_Render::venue_map( $event_id, $booths, $role );
		}
		return '';
	}
}
