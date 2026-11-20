<?php
/**
 * Reputation UI render helpers.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Reputation_Render {

	/**
	 * Score badge HTML.
	 */
	public static function score_badge( $score, $label = '', $is_new = false ) {
		if ( $is_new ) {
			return '<span class="lnkup-rep-score lnkup-rep-score--new">' . esc_html__( 'New Vendor — Building Reputation', 'lnkup-marketplace' ) . '</span>';
		}
		$class = 'lnkup-rep-score';
		if ( $score >= 90 ) {
			$class .= ' lnkup-rep-score--elite';
		} elseif ( $score >= 80 ) {
			$class .= ' lnkup-rep-score--trusted';
		} elseif ( $score < 50 ) {
			$class .= ' lnkup-rep-score--risk';
		}
		$html = '<span class="' . esc_attr( $class ) . '">';
		$html .= esc_html( sprintf( __( 'LNKup Score: %d / 100', 'lnkup-marketplace' ), (int) $score ) );
		if ( $label ) {
			$html .= ' <em>' . esc_html( $label ) . '</em>';
		}
		$html .= '</span>';
		return $html;
	}

	/**
	 * Star rating display.
	 */
	public static function star_rating( $rating, $count = 0 ) {
		$rating = (float) $rating;
		$full   = (int) floor( $rating );
		$label  = $count
			/* translators: 1: rating, 2: number of reviews */
			? sprintf( _n( '%1$s out of 5 stars from %2$d review', '%1$s out of 5 stars from %2$d reviews', (int) $count, 'lnkup-marketplace' ), number_format( $rating, 1 ), (int) $count )
			/* translators: %s: rating */
			: sprintf( __( '%s out of 5 stars', 'lnkup-marketplace' ), number_format( $rating, 1 ) );
		$html   = '<span class="lnkup-rep-stars" role="img" aria-label="' . esc_attr( $label ) . '">';
		for ( $i = 1; $i <= 5; $i++ ) {
			$html .= '<span class="lnkup-rep-stars__star' . ( $i <= $full ? ' is-filled' : '' ) . '" aria-hidden="true">★</span>';
		}
		$html .= ' <span class="lnkup-rep-stars__value" aria-hidden="true">' . esc_html( number_format( $rating, 1 ) ) . '</span>';
		if ( $count ) {
			$html .= ' <span class="lnkup-muted" aria-hidden="true">(' . esc_html( (string) $count ) . ')</span>';
		}
		$html .= '</span>';
		return $html;
	}

	/**
	 * Badge grid.
	 */
	public static function badge_grid( $badges ) {
		if ( ! $badges ) {
			return '<p class="lnkup-muted">' . esc_html__( 'No badges yet.', 'lnkup-marketplace' ) . '</p>';
		}
		$html = '<div class="lnkup-rep-badge-grid">';
		foreach ( $badges as $badge ) {
			$html .= '<span class="lnkup-badge lnkup-badge--secondary lnkup-rep-badge" title="' . esc_attr( $badge['label'] ) . '">' . esc_html( $badge['label'] ) . '</span>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Reliability meter rows.
	 */
	public static function reliability_meter( $metrics, $public = false ) {
		if ( ! $metrics ) {
			return '';
		}
		$rows = array(
			array( __( 'Completed events', 'lnkup-marketplace' ), (string) $metrics['completed_events'], null ),
			array( __( 'No-show rate', 'lnkup-marketplace' ), $metrics['no_show_rate'] . '%', 100 - $metrics['no_show_rate'] ),
		);
		if ( ! $public ) {
			$rows[] = array( __( 'Payment completion', 'lnkup-marketplace' ), $metrics['payment_completion_rate'] . '%', $metrics['payment_completion_rate'] );
			$rows[] = array( __( 'Document compliance', 'lnkup-marketplace' ), $metrics['document_compliance_rate'] . '%', $metrics['document_compliance_rate'] );
		}
		$rows[] = array( __( 'On-time check-ins', 'lnkup-marketplace' ), $metrics['on_time_check_in_rate'] . '%', $metrics['on_time_check_in_rate'] );
		$rows[] = array( __( 'Avg organizer rating', 'lnkup-marketplace' ), number_format( $metrics['avg_organizer_rating'], 1 ) . ' / 5', $metrics['avg_organizer_rating'] * 20 );
		$html = '<dl class="lnkup-rep-meter">';
		foreach ( $rows as $row ) {
			$html .= '<div class="lnkup-rep-meter__row"><dt>' . esc_html( $row[0] ) . '</dt><dd>' . esc_html( $row[1] );
			if ( null !== $row[2] ) {
				$html .= '<div class="lnkup-rep-meter__bar"><span style="width:' . esc_attr( min( 100, (float) $row[2] ) ) . '%"></span></div>';
			}
			$html .= '</dd></div>';
		}
		$html .= '</dl>';
		return $html;
	}

	/**
	 * Review card.
	 *
	 * @param array $review         Review row.
	 * @param bool  $show_organizer Name the reviewing organizer. Only for the reviewed vendor's own view.
	 */
	public static function review_card( $review, $show_organizer = false ) {
		ob_start();
		?>
		<article class="lnkup-rep-review-card lnkup-surface--primary lnkup-micro-lift">
			<header class="lnkup-rep-review-card__header">
				<?php echo self::star_rating( $review['overall_rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="lnkup-muted">
					<?php
					$review_ts   = ! empty( $review['created_at'] ) ? strtotime( (string) $review['created_at'] ) : false;
					$review_meta = array_filter(
						array(
							(string) ( $review['event_title'] ?? '' ),
							$show_organizer && ! empty( $review['organizer_name'] ) ? sprintf( /* translators: %s: organizer name */ __( 'by %s', 'lnkup-marketplace' ), (string) $review['organizer_name'] ) : '',
							$review_ts ? date_i18n( get_option( 'date_format' ), $review_ts ) : (string) ( $review['created_at'] ?? '' ),
						)
					);
					echo esc_html( implode( ' · ', $review_meta ) );
					?>
				</span>
			</header>
			<?php if ( ! empty( $review['public_text'] ) ) : ?>
				<p class="lnkup-rep-review-card__body"><?php echo esc_html( $review['public_text'] ); ?></p>
			<?php endif; ?>
		</article>
		<?php
		return ob_get_clean();
	}

	/**
	 * Completed events timeline.
	 */
	public static function completed_events_timeline( $events, $limit = 10 ) {
		$events = array_slice( $events, 0, $limit );
		if ( ! $events ) {
			return '<p class="lnkup-muted">' . esc_html__( 'No completed events yet.', 'lnkup-marketplace' ) . '</p>';
		}
		$html = '<ul class="lnkup-rep-timeline">';
		foreach ( $events as $ev ) {
			$html .= '<li class="lnkup-rep-timeline__item">';
			$html .= '<strong>' . esc_html( $ev['event_name'] ) . '</strong>';
			$html .= ' <span class="lnkup-muted">' . esc_html( $ev['date'] ) . ' · ' . esc_html( $ev['city'] . ( $ev['state'] ? ', ' . $ev['state'] : '' ) ) . '</span>';
			if ( $ev['verified'] ) {
				$html .= ' <span class="lnkup-badge lnkup-badge--accent">' . esc_html__( 'Verified', 'lnkup-marketplace' ) . '</span>';
			}
			if ( $ev['rating'] ) {
				$html .= ' ' . self::star_rating( $ev['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
		return $html;
	}

	/**
	 * Score breakdown panel.
	 */
	public static function score_breakdown_panel( $breakdown ) {
		if ( ! $breakdown || ! is_array( $breakdown ) ) {
			return '';
		}
		$html = '<ul class="lnkup-rep-breakdown">';
		foreach ( $breakdown as $key => $val ) {
			$html .= '<li><span>' . esc_html( ucwords( str_replace( '_', ' ', $key ) ) ) . '</span> <strong>' . esc_html( (string) $val ) . '</strong></li>';
		}
		$html .= '</ul>';
		return $html;
	}

	/**
	 * Risk indicator.
	 */
	public static function risk_indicator( $metrics ) {
		$risk = 'low';
		if ( $metrics['no_show_rate'] > 10 || $metrics['dispute_count'] > 0 ) {
			$risk = 'high';
		} elseif ( $metrics['no_show_rate'] > 3 || $metrics['cancellation_rate'] > 15 ) {
			$risk = 'medium';
		}
		return '<span class="lnkup-rep-risk lnkup-rep-risk--' . esc_attr( $risk ) . '">' . esc_html( ucfirst( $risk ) . ' risk' ) . '</span>';
	}

	/**
	 * Reputation summary card.
	 */
	public static function summary_card( $reputation ) {
		ob_start();
		?>
		<div class="lnkup-rep-summary">
			<?php echo self::score_badge( $reputation['score'], $reputation['label'], ! empty( $reputation['is_new'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( $reputation['review_count'] ) : ?>
				<?php echo self::star_rating( $reputation['avg_rating'], $reputation['review_count'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
			<?php echo self::badge_grid( $reputation['badges'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Public storefront hero.
	 *
	 * @param array<string, mixed> $data    Public profile DTO.
	 * @param string               $actions Optional action HTML, already escaped.
	 */
	public static function public_hero( $data, $actions = '' ) {
		$location   = trim( ( $data['city'] ?? '' ) . ( ! empty( $data['state'] ) ? ', ' . $data['state'] : '' ) );
		$categories = is_array( $data['categories'] ?? null ) ? implode( ' · ', $data['categories'] ) : '';
		$initials   = '';
		foreach ( preg_split( '/\s+/', trim( (string) ( $data['business_name'] ?? '' ) ) ) as $part ) {
			$initials .= strtoupper( substr( $part, 0, 1 ) );
			if ( strlen( $initials ) >= 2 ) {
				break;
			}
		}
		ob_start();
		?>
		<div class="lnkup-pub-hero<?php echo empty( $data['cover'] ) ? ' lnkup-pub-hero--plain' : ''; ?>">
			<?php if ( ! empty( $data['cover'] ) ) : ?>
				<div class="lnkup-pub-hero__cover" style="background-image:url(<?php echo esc_url( $data['cover'] ); ?>)"></div>
			<?php endif; ?>
			<div class="lnkup-pub-hero__body">
				<div class="lnkup-pub-hero__identity">
					<?php if ( ! empty( $data['logo'] ) ) : ?>
						<img src="<?php echo esc_url( $data['logo'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: business name */ __( '%s logo', 'lnkup-marketplace' ), (string) ( $data['business_name'] ?? '' ) ) ); ?>" class="lnkup-pub-hero__logo" />
					<?php else : ?>
						<span class="lnkup-pub-hero__logo lnkup-pub-hero__logo--initials" aria-hidden="true"><?php echo esc_html( $initials ?: 'LN' ); ?></span>
					<?php endif; ?>
					<div class="lnkup-pub-hero__titles">
						<h1 class="lnkup-type-display"><?php echo esc_html( $data['business_name'] ); ?></h1>
						<?php if ( $location ) : ?>
							<p class="lnkup-type-meta"><?php echo esc_html( $location ); ?></p>
						<?php endif; ?>
						<?php if ( $categories ) : ?>
							<p class="lnkup-pub-hero__category"><?php echo esc_html( $categories ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $data['reputation']['avg_rating'] ) && ! empty( $data['reputation']['review_count'] ) ) : ?>
							<div class="lnkup-pub-hero__rating"><?php echo self::star_rating( (float) $data['reputation']['avg_rating'], (int) $data['reputation']['review_count'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<?php endif; ?>
					</div>
				</div>
				<?php if ( $actions ) : ?>
					<div class="lnkup-pub-hero__actions"><?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Public photo gallery grid.
	 *
	 * @param array<int, string> $gallery Image URLs.
	 */
	public static function public_gallery( $gallery ) {
		if ( ! $gallery ) {
			return '';
		}
		$total = count( $gallery );
		ob_start();
		?>
		<section class="lnkup-pub-gallery" data-pub-gallery>
			<div class="lnkup-pub-gallery__grid">
				<?php foreach ( array_slice( $gallery, 0, 5 ) as $idx => $url ) : ?>
					<button type="button" class="lnkup-pub-gallery__item<?php echo 0 === $idx ? ' lnkup-pub-gallery__item--hero' : ''; ?>" data-gallery-index="<?php echo esc_attr( (string) $idx ); ?>" style="background-image:url(<?php echo esc_url( $url ); ?>)">
						<?php if ( 4 === $idx && $total > 5 ) : ?>
							<span class="lnkup-pub-gallery__more"><?php echo esc_html( sprintf( __( 'Show all %d photos', 'lnkup-marketplace' ), $total ) ); ?></span>
						<?php endif; ?>
					</button>
				<?php endforeach; ?>
			</div>
			<div class="lnkup-pub-gallery__lightbox" hidden data-gallery-lightbox>
				<button type="button" class="lnkup-pub-gallery__close" data-gallery-close aria-label="<?php esc_attr_e( 'Close', 'lnkup-marketplace' ); ?>">×</button>
				<img src="" alt="" data-gallery-lightbox-img />
			</div>
			<script type="application/json" data-gallery-json><?php echo wp_json_encode( array_values( $gallery ) ); ?></script>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Public trust strip.
	 *
	 * @param array<string, mixed> $data Public profile DTO.
	 */
	public static function public_trust_strip( $data ) {
		$verify = $data['verification'] ?? array();
		$rep    = $data['reputation'] ?? array();
		$badges = array_merge(
			(array) ( $verify['badges'] ?? array() ),
			wp_list_pluck( (array) ( $rep['badges'] ?? array() ), 'label' )
		);
		$badges = array_unique( array_filter( $badges ) );
		if ( ! $badges && empty( $verify['public_summary'] ) ) {
			return '';
		}
		ob_start();
		?>
		<section class="lnkup-pub-trust lnkup-surface--secondary">
			<h2 class="lnkup-type-label"><?php esc_html_e( 'Trust & verification', 'lnkup-marketplace' ); ?></h2>
			<?php if ( ! empty( $verify['public_summary'] ) ) : ?>
				<p class="lnkup-type-body"><?php echo esc_html( $verify['public_summary'] ); ?></p>
			<?php endif; ?>
			<?php if ( $badges ) : ?>
				<div class="lnkup-pub-trust__badges">
					<?php foreach ( array_slice( $badges, 0, 8 ) as $badge ) : ?>
						<span class="lnkup-badge lnkup-badge--verified"><?php echo esc_html( $badge ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Public metrics row.
	 *
	 * @param array<string, mixed> $data Public profile DTO.
	 */
	public static function public_metrics_row( $data ) {
		$metrics = $data['metrics'] ?? ( $data['reputation']['metrics'] ?? array() );
		$rows = array(
			array( __( 'Events completed', 'lnkup-marketplace' ), (string) ( $metrics['completed_events'] ?? count( $data['events'] ?? array() ) ) ),
			array( __( 'Repeat bookings', 'lnkup-marketplace' ), (string) ( $metrics['repeat_bookings'] ?? 0 ) ),
			array( __( 'On-time check-ins', 'lnkup-marketplace' ), (string) ( $metrics['on_time_check_in_rate'] ?? 0 ) . '%' ),
		);
		if ( ! empty( $data['years_in_business'] ) ) {
			$rows[] = array( __( 'Years in business', 'lnkup-marketplace' ), (string) $data['years_in_business'] );
		}
		ob_start();
		?>
		<section class="lnkup-pub-metrics">
			<?php foreach ( $rows as $row ) : ?>
				<div class="lnkup-pub-metrics__item lnkup-surface--primary lnkup-micro-lift">
					<p class="lnkup-type-label"><?php echo esc_html( $row[0] ); ?></p>
					<p class="lnkup-type-display"><?php echo esc_html( $row[1] ); ?></p>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Public reviews section with empty state.
	 *
	 * @param array<int, array<string, mixed>> $reviews Reviews.
	 */
	public static function public_reviews_section( $reviews ) {
		ob_start();
		?>
		<section class="lnkup-pub-reviews lnkup-section">
			<h2 class="lnkup-type-h2"><?php esc_html_e( 'Organizer reviews', 'lnkup-marketplace' ); ?></h2>
			<?php if ( $reviews ) : ?>
				<div class="lnkup-pub-reviews__list">
					<?php foreach ( $reviews as $review ) : ?>
						<?php echo self::review_card( $review ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<?php
				echo lnkup_empty_state(
					array(
						'title'   => __( 'No reviews yet', 'lnkup-marketplace' ),
						'desc'    => __( 'This vendor is building their reputation on LNKup. Completed events unlock organizer reviews.', 'lnkup-marketplace' ),
						'compact' => true,
					)
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}
}
