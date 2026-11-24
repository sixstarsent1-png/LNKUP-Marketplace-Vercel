<?php
/**
 * Weather intelligence render helpers.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Weather_Render {

	/**
	 * Compact Weather Readiness card for organizer dashboard.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function readiness_card_compact( $user_id ) {
		$summary = LNKup_Weather_Service::portfolio_summary( $user_id );
		ob_start();
		if ( empty( $summary['has_event'] ) ) {
			?>
			<div class="lnkup-weather-card lnkup-weather-card--empty" data-testid="lnkup-weather-readiness-card">
				<h3 class="lnkup-weather-card__title"><?php esc_html_e( 'Weather Readiness', 'lnkup-marketplace' ); ?></h3>
				<p class="lnkup-muted"><?php echo esc_html( $summary['message'] ?? __( 'Weather intelligence will appear for upcoming events.', 'lnkup-marketplace' ) ); ?></p>
			</div>
			<?php
			return ob_get_clean();
		}
		echo self::card_shell( $summary, 'compact' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return ob_get_clean();
	}

	/**
	 * Operations panel for command center overview.
	 *
	 * @param array $dto Weather DTO.
	 */
	public static function operations_panel( $dto ) {
		if ( ! is_array( $dto ) ) {
			return '';
		}
		ob_start();
		echo self::card_shell( $dto, 'operations' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! empty( $dto['recommendations'] ) ) {
			echo '<ul class="lnkup-weather-recs">';
			foreach ( $dto['recommendations'] as $rec ) {
				echo '<li>' . esc_html( $rec ) . '</li>';
			}
			echo '</ul>';
		}
		if ( ! empty( $dto['contingency_plan'] ) ) {
			echo '<div class="lnkup-weather-contingency"><strong>' . esc_html__( 'Contingency plan', 'lnkup-marketplace' ) . '</strong><p>' . esc_html( $dto['contingency_plan'] ) . '</p></div>';
		}
		return ob_get_clean();
	}

	/**
	 * Intelligence panel (full).
	 *
	 * @param array $dto Weather DTO.
	 */
	public static function intelligence_panel( $dto ) {
		if ( ! is_array( $dto ) ) {
			return '';
		}
		ob_start();
		echo self::card_shell( $dto, 'intelligence' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::risk_grid( $dto );
		if ( ! empty( $dto['hourly'] ) && is_array( $dto['hourly'] ) ) {
			echo '<div class="lnkup-weather-hourly"><h4>' . esc_html__( 'Hourly outlook', 'lnkup-marketplace' ) . '</h4><ul>';
			foreach ( array_slice( $dto['hourly'], 0, 8 ) as $slot ) {
				$time = ! empty( $slot['timestamp'] ) ? gmdate( 'g A', (int) $slot['timestamp'] ) : '';
				echo '<li><span>' . esc_html( $time ) . '</span> ';
				if ( isset( $slot['temp'] ) ) {
					echo '<span>' . esc_html( round( (float) $slot['temp'] ) . '°' ) . '</span> ';
				}
				echo '<span class="lnkup-muted">' . esc_html( $slot['description'] ?? '' ) . '</span></li>';
			}
			echo '</ul></div>';
		}
		if ( ! empty( $dto['recommendations'] ) ) {
			echo '<div class="lnkup-weather-recs-block"><h4>' . esc_html__( 'Planning recommendations', 'lnkup-marketplace' ) . '</h4><ul class="lnkup-weather-recs">';
			foreach ( $dto['recommendations'] as $rec ) {
				echo '<li>' . esc_html( $rec ) . '</li>';
			}
			echo '</ul></div>';
		}
		return ob_get_clean();
	}

	/**
	 * Vendor packet compact note.
	 *
	 * @param array|null $note Vendor note from service.
	 */
	public static function vendor_packet_note( $note ) {
		if ( ! is_array( $note ) || '' === ( $note['summary'] ?? '' ) && empty( $note['recommendations'] ) ) {
			return '';
		}
		ob_start();
		?>
		<div class="lnkup-weather-vendor-note">
			<?php if ( ! empty( $note['forecast_status'] ) ) : ?>
				<p class="lnkup-weather-vendor-note__status"><?php echo esc_html( $note['forecast_status'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $note['summary'] ) ) : ?>
				<p><?php echo esc_html( $note['summary'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $note['recommendations'] ) ) : ?>
				<ul class="lnkup-weather-recs">
					<?php foreach ( $note['recommendations'] as $rec ) : ?>
						<li><?php echo esc_html( $rec ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $note['contingency'] ) ) : ?>
				<p class="lnkup-muted"><?php echo esc_html( $note['contingency'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Loading skeleton.
	 */
	public static function skeleton( $event_id = 0 ) {
		$attr = $event_id ? ' data-event-id="' . esc_attr( (string) $event_id ) . '"' : '';
		return '<div class="lnkup-weather-card lnkup-weather-card--loading" data-testid="lnkup-ops-weather"' . $attr . '><div class="lnkup-skeleton lnkup-skeleton--card"></div><p class="lnkup-muted">' . esc_html__( 'Loading weather intelligence…', 'lnkup-marketplace' ) . '</p></div>';
	}

	/**
	 * Card shell shared markup.
	 *
	 * @param array  $dto  DTO.
	 * @param string $variant compact|operations|intelligence.
	 */
	private static function card_shell( array $dto, $variant ) {
		$event_id = (int) ( $dto['event_id'] ?? 0 );
		$status   = $dto['status'] ?? 'unavailable';
		$risks    = $dto['risks'] ?? array();
		$top_rec  = ! empty( $dto['recommendations'][0] ) ? $dto['recommendations'][0] : '';
		$test_id  = 'compact' === $variant ? 'lnkup-weather-readiness-card' : 'lnkup-ops-weather';

		if ( in_array( $status, array( 'unconfigured', 'no_location', 'no_date', 'error' ), true ) && empty( $dto['stale'] ) ) {
			ob_start();
			?>
			<div class="lnkup-weather-card lnkup-weather-card--<?php echo esc_attr( $status ); ?>" data-testid="<?php echo esc_attr( $test_id ); ?>" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>">
				<h3 class="lnkup-weather-card__title"><?php esc_html_e( 'Weather Readiness', 'lnkup-marketplace' ); ?></h3>
				<p class="lnkup-alert lnkup-alert--info"><?php echo esc_html( $dto['message'] ?? __( 'Weather intelligence unavailable.', 'lnkup-marketplace' ) ); ?></p>
				<?php if ( $event_id && 'unconfigured' !== $status ) : ?>
					<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline lnkup-weather-refresh" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Retry', 'lnkup-marketplace' ); ?></button>
				<?php endif; ?>
			</div>
			<?php
			return ob_get_clean();
		}

		$icon = $dto['current']['icon'] ?? ( $dto['event_day']['icon'] ?? '' );
		ob_start();
		?>
		<div class="lnkup-weather-card lnkup-weather-card--<?php echo esc_attr( $variant ); ?><?php echo ! empty( $dto['stale'] ) ? ' is-stale' : ''; ?>" data-testid="<?php echo esc_attr( $test_id ); ?>" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>">
			<div class="lnkup-weather-card__head">
				<h3 class="lnkup-weather-card__title"><?php esc_html_e( 'Weather Readiness', 'lnkup-marketplace' ); ?></h3>
				<?php if ( $icon ) : ?>
					<img class="lnkup-weather-card__icon" src="<?php echo esc_url( 'https://openweathermap.org/img/wn/' . rawurlencode( $icon ) . '@2x.png' ); ?>" alt="" width="48" height="48" loading="lazy" />
				<?php endif; ?>
			</div>
			<p class="lnkup-weather-card__status"><?php echo esc_html( $dto['forecast_status'] ?? '' ); ?></p>
			<?php if ( ! empty( $dto['summary'] ) ) : ?>
				<p class="lnkup-weather-card__summary"><?php echo esc_html( $dto['summary'] ); ?></p>
			<?php endif; ?>
			<div class="lnkup-weather-card__risks">
				<?php self::risk_chip( __( 'Overall', 'lnkup-marketplace' ), $risks['overall'] ?? 'unavailable' ); ?>
				<?php self::risk_chip( __( 'Rain', 'lnkup-marketplace' ), $risks['rain'] ?? 'unavailable' ); ?>
				<?php self::risk_chip( __( 'Wind', 'lnkup-marketplace' ), $risks['wind'] ?? 'unavailable' ); ?>
				<?php self::risk_chip( __( 'Heat', 'lnkup-marketplace' ), $risks['heat'] ?? 'unavailable' ); ?>
			</div>
			<?php if ( $top_rec && 'compact' === $variant ) : ?>
				<p class="lnkup-weather-card__rec lnkup-muted"><strong><?php esc_html_e( 'Top recommendation', 'lnkup-marketplace' ); ?>:</strong> <?php echo esc_html( $top_rec ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $dto['last_updated'] ) ) : ?>
				<p class="lnkup-weather-card__updated lnkup-muted">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: datetime */
							__( 'Last updated %s', 'lnkup-marketplace' ),
							$dto['last_updated']
						)
					);
					?>
				</p>
			<?php endif; ?>
			<div class="lnkup-weather-card__actions">
				<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline lnkup-weather-refresh" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Refresh forecast', 'lnkup-marketplace' ); ?></button>
				<?php if ( $event_id && 'compact' === $variant ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'event' => $event_id, 'ops' => 1 ), lnkup_page_url_helper( 'organizer-dashboard' ) ) ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost"><?php esc_html_e( 'View details', 'lnkup-marketplace' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Risk grid for intelligence panel.
	 *
	 * @param array $dto DTO.
	 */
	private static function risk_grid( array $dto ) {
		$risks = $dto['risks'] ?? array();
		ob_start();
		?>
		<div class="lnkup-weather-risk-grid">
			<?php
			foreach (
				array(
					'overall' => __( 'Overall', 'lnkup-marketplace' ),
					'rain'    => __( 'Rain', 'lnkup-marketplace' ),
					'wind'    => __( 'Wind', 'lnkup-marketplace' ),
					'heat'    => __( 'Heat', 'lnkup-marketplace' ),
					'storm'   => __( 'Storm', 'lnkup-marketplace' ),
					'setup'   => __( 'Setup', 'lnkup-marketplace' ),
				) as $key => $label
			) {
				self::risk_chip( $label, $risks[ $key ] ?? 'unavailable' );
			}
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Single risk chip.
	 *
	 * @param string $label Label.
	 * @param string $level Risk level.
	 */
	private static function risk_chip( $label, $level ) {
		$level = in_array( $level, array( 'low', 'medium', 'high', 'unavailable' ), true ) ? $level : 'unavailable';
		echo '<span class="lnkup-weather-risk lnkup-weather-risk--' . esc_attr( $level ) . '">';
		echo '<span class="lnkup-weather-risk__label">' . esc_html( $label ) . '</span> ';
		echo '<span class="lnkup-weather-risk__value">' . esc_html( ucfirst( $level ) ) . '</span>';
		echo '</span>';
	}
}
