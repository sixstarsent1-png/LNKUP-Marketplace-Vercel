<?php
/**
 * Intelligence UI render helpers.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Intelligence_Render {

	/**
	 * Reusable insight card.
	 *
	 * @param array $card Card data.
	 */
	public static function insight_card( $card ) {
		$type = esc_attr( $card['type'] ?? 'info' );
		$html = '<article class="lnkup-intel-card lnkup-intel-card--' . $type . '">';
		$html .= '<header class="lnkup-intel-card__head"><strong>' . esc_html( $card['title'] ?? '' ) . '</strong>';
		if ( ! empty( $card['priority'] ) ) {
			$html .= '<span class="lnkup-intel-priority lnkup-intel-priority--' . esc_attr( $card['priority'] ) . '">' . esc_html( ucfirst( $card['priority'] ) ) . '</span>';
		}
		$html .= '</header>';
		if ( ! empty( $card['explanation'] ) ) {
			$html .= '<p class="lnkup-intel-card__body">' . esc_html( $card['explanation'] ) . '</p>';
		}
		if ( isset( $card['metric'] ) ) {
			$html .= '<p class="lnkup-intel-card__metric">' . esc_html( (string) $card['metric'] ) . '</p>';
		}
		if ( ! empty( $card['cta']['url'] ) ) {
			$html .= '<a href="' . esc_url( $card['cta']['url'] ) . '" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary">' . esc_html( $card['cta']['label'] ?? __( 'View', 'lnkup-marketplace' ) ) . '</a>';
		}
		if ( ! empty( $card['timestamp'] ) ) {
			$html .= '<footer class="lnkup-intel-card__time lnkup-muted">' . esc_html( $card['timestamp'] ) . '</footer>';
		}
		$html .= '</article>';
		return $html;
	}

	/**
	 * Score badge HTML.
	 *
	 * @param int    $score Score value.
	 * @param string $label Label text.
	 */
	public static function score_badge( $score, $label = '' ) {
		$score = (int) $score;
		$class = $score >= 80 ? 'excellent' : ( $score >= 65 ? 'healthy' : ( $score >= 50 ? 'attention' : 'risk' ) );
		$html  = '<div class="lnkup-intel-score lnkup-intel-score--' . esc_attr( $class ) . '">';
		$html .= '<span class="lnkup-intel-score__value">' . esc_html( (string) $score ) . '</span>';
		$html .= '<span class="lnkup-intel-score__label">' . esc_html( $label ?: __( 'Score', 'lnkup-marketplace' ) ) . '</span>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * Horizontal bar funnel.
	 *
	 * @param array  $stages Stage label => count.
	 * @param string $title  Funnel title.
	 */
	public static function funnel_bars( $stages, $title = '' ) {
		if ( ! $stages ) {
			return '';
		}
		$max = max( 1, max( array_map( 'intval', $stages ) ) );
		$html = '<div class="lnkup-intel-funnel">';
		if ( $title ) {
			$html .= '<h3 class="lnkup-intel-funnel__title">' . esc_html( $title ) . '</h3>';
		}
		foreach ( $stages as $label => $count ) {
			$count = (int) $count;
			$pct   = round( ( $count / $max ) * 100 );
			$html .= '<div class="lnkup-intel-funnel__row"><span class="lnkup-intel-funnel__label">' . esc_html( $label ) . '</span>';
			$html .= '<div class="lnkup-intel-funnel__bar"><span style="width:' . esc_attr( (string) $pct ) . '%"></span></div>';
			$html .= '<span class="lnkup-intel-funnel__value">' . esc_html( (string) $count ) . '</span></div>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * KPI row for intelligence dashboard.
	 *
	 * @param array $kpis Label => value pairs.
	 */
	public static function kpi_row( $kpis ) {
		if ( ! $kpis ) {
			return '';
		}
		$html = '<div class="lnkup-intel-kpis lnkup-grid lnkup-grid--4">';
		foreach ( $kpis as $label => $value ) {
			$html .= '<div class="lnkup-stat-card lnkup-intel-kpi"><div class="lnkup-stat-card__content"><p class="lnkup-stat-card__label">' . esc_html( $label ) . '</p><p class="lnkup-stat-card__value">' . esc_html( (string) $value ) . '</p></div></div>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Risk alerts list.
	 *
	 * @param array $alerts Alert rows.
	 */
	public static function risk_list( $alerts ) {
		if ( ! $alerts ) {
			return '<p class="lnkup-muted">' . esc_html__( 'No active risk alerts.', 'lnkup-marketplace' ) . '</p>';
		}
		$html = '<ul class="lnkup-intel-risks">';
		foreach ( $alerts as $alert ) {
			$level = esc_attr( $alert['level'] ?? 'medium' );
			$html .= '<li class="lnkup-intel-risk lnkup-intel-risk--' . $level . '">';
			$html .= '<strong>' . esc_html( ucfirst( $level ) ) . '</strong> — ' . esc_html( $alert['message'] ?? '' );
			if ( ! empty( $alert['action_text'] ) ) {
				$html .= ' <em>' . esc_html( $alert['action_text'] ) . '</em>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
		return $html;
	}

	/**
	 * Recommended actions list.
	 *
	 * @param array $actions Action rows.
	 */
	public static function actions_list( $actions ) {
		if ( ! $actions ) {
			return '<p class="lnkup-muted">' . esc_html__( 'No recommended actions right now.', 'lnkup-marketplace' ) . '</p>';
		}
		$html = '<div class="lnkup-intel-actions">';
		foreach ( $actions as $action ) {
			$html .= self::insight_card(
				array(
					'type'        => 'recommendation',
					'title'       => $action['title'] ?? '',
					'explanation' => $action['reason'] ?? '',
					'priority'    => $action['priority'] ?? 'medium',
					'cta'         => $action['cta'] ?? array(),
				)
			);
		}
		$html .= '</div>';
		return $html;
	}
}
