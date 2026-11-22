<?php
/**
 * Public tour and series pages: /tours/{slug}/ and /series/{slug}/.
 *
 * Only published tours and their published, upcoming stops are shown (LNKup_Tours::detail() already
 * hides draft events and invite-only slots). Each stop links to its own application; vendors can
 * open the existing multi-stop application on Find Events, or register interest in the whole tour.
 * The theme template only lays out the page; all data and markup come from this class.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Portfolio_Public {

	const QUERY_VAR       = 'lnkup_tour_slug';
	const REWRITE_VERSION = '1';

	/**
	 * @var array|null Current page data.
	 */
	private static $context = null;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ), 11 );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ) );
	}

	public static function register_rewrite_rules() {
		add_rewrite_rule( '^tours/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		add_rewrite_rule( '^series/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		if ( get_option( 'lnkup_portfolio_rewrite_version' ) !== self::REWRITE_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'lnkup_portfolio_rewrite_version', self::REWRITE_VERSION, false );
		}
	}

	/**
	 * @param array $vars Query vars.
	 */
	public static function register_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Public URL: /tours/ for multi-city tours, /series/ for series and recurring markets.
	 *
	 * @param int $tour_id Tour.
	 */
	public static function tour_url( $tour_id ) {
		$slug = (string) get_post_field( 'post_name', $tour_id );
		if ( '' === $slug ) {
			return '';
		}
		$base = 'tour' === get_post_meta( $tour_id, '_lnkup_tour_type', true ) ? 'tours' : 'series';
		return home_url( '/' . $base . '/' . $slug . '/' );
	}

	/**
	 * "Part of {tour}" link for a discovery card, so one city's listing and its tour are shown as
	 * related rather than as duplicates. Null when the stop is not in a published tour.
	 *
	 * @param int $event_id Stop event.
	 * @return array{label:string,url:string}|null
	 */
	public static function card_tour_link( $event_id ) {
		$tour_id = $event_id ? (int) get_post_meta( (int) $event_id, '_lnkup_tour_id', true ) : 0;
		$summary = $tour_id ? LNKup_Tours::card_summary( $tour_id ) : null;
		if ( ! $summary ) {
			return null;
		}
		$owner = (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true );
		if ( LNKup_Feature_Flags::portfolio( $owner ) ) {
			$url = self::tour_url( $tour_id );
		} elseif ( class_exists( 'LNKup_Match_Settings' ) && LNKup_Match_Settings::enabled( 'matching_tours_enabled' ) ) {
			$url = add_query_arg( 'tour_id', $tour_id, lnkup_page_url_helper( 'opportunities' ) );
		} else {
			return null;
		}
		if ( '' === $url ) {
			return null;
		}
		return array(
			/* translators: %s: tour or series name */
			'label' => sprintf( __( 'Part of %s', 'lnkup-marketplace' ), $summary['title'] ),
			'url'   => $url,
		);
	}

	/**
	 * Published tour ID for a slug, when the Portfolio OS is on for its organizer.
	 *
	 * @param string $slug Slug.
	 */
	public static function tour_id_from_slug( $slug ) {
		$posts = get_posts(
			array(
				'post_type'      => 'lnkup_tour',
				'post_status'    => 'publish',
				'name'           => sanitize_title( (string) $slug ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		if ( ! $posts ) {
			return 0;
		}
		$tour_id = (int) $posts[0];
		$owner   = (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true );
		return LNKup_Feature_Flags::portfolio( $owner ) ? $tour_id : 0;
	}

	/**
	 * Public tour data (also served by REST for the Next.js and Expo apps).
	 *
	 * @param int $tour_id Tour.
	 * @param int $user_id Viewer (vendor match / application status), 0 for anonymous.
	 * @return array|WP_Error
	 */
	public static function data( $tour_id, $user_id = 0 ) {
		$detail = LNKup_Tours::detail( $tour_id, $user_id && ! LNKup_Tours::owns( $tour_id, $user_id ) ? $user_id : 0 );
		if ( is_wp_error( $detail ) ) {
			return $detail;
		}
		$today      = current_time( 'Y-m-d' );
		$stops      = array();
		$categories = array();
		foreach ( $detail['stops'] as $stop ) {
			$event_id = (int) $stop['event_id'];
			if ( 'publish' !== get_post_status( $event_id ) || ( $stop['date'] && $stop['date'] < $today ) || in_array( LNKup_Portfolio_Tours::stop_state( $event_id ), array( 'cancelled', 'postponed', 'skipped' ), true ) ) {
				continue;
			}
			$hidden = (bool) get_post_meta( $event_id, '_lnkup_venue_hidden', true );
			foreach ( $stop['opportunities'] as $opp ) {
				foreach ( wp_get_post_terms( (int) $opp['opportunity_id'], 'lnkup_vendor_category', array( 'fields' => 'names' ) ) as $name ) {
					if ( is_string( $name ) ) {
						$categories[ $name ] = true;
					}
				}
			}
			$stops[] = array_merge(
				$stop,
				array(
					'venue_name' => $hidden ? '' : (string) get_post_meta( $event_id, '_lnkup_venue_name', true ),
					'start_time' => (string) get_post_meta( $event_id, '_lnkup_start_time', true ),
					'end_time'   => (string) get_post_meta( $event_id, '_lnkup_end_time', true ),
					'timezone'   => LNKup_Portfolio_Tours::stop_timezone( $event_id ),
					'event_url'  => LNKup_Organizer_Onboarding::event_public_url( $event_id ),
				)
			);
		}
		$organizer_id = (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true );
		$settings     = lnkup_get_settings();
		$brand_id     = (int) get_post_meta( $tour_id, '_lnkup_brand_id', true );
		$categories   = array_keys( $categories );
		sort( $categories );
		return array(
			'id'              => (int) $tour_id,
			'title'           => $detail['title'],
			'type'            => $detail['type'],
			'description'     => $detail['description'],
			'season'          => (string) get_post_meta( $tour_id, '_lnkup_season', true ),
			'eligibility'     => (string) get_post_meta( $tour_id, '_lnkup_eligibility', true ),
			'website'         => (string) get_post_meta( $tour_id, '_lnkup_tour_website', true ),
			'cover_url'       => (string) get_the_post_thumbnail_url( $tour_id, 'large' ),
			'brand'           => $brand_id ? get_the_title( $brand_id ) : '',
			'organizer'       => array(
				'name' => $detail['organizer_name'],
				'url'  => LNKup_Organizer_Onboarding::organizer_public_url( $organizer_id ),
			),
			'categories'      => $categories,
			'stops'           => $stops,
			'accepting'       => (bool) array_filter( wp_list_pluck( $stops, 'accepting' ) ),
			'url'             => self::tour_url( $tour_id ),
			'multi_apply_url' => ! empty( $settings['tour_multi_apply_enabled'] ) ? add_query_arg( 'tour_id', (int) $tour_id, lnkup_page_url_helper( 'opportunities' ) ) : '',
		);
	}

	private static function is_tour_request() {
		return '' !== (string) get_query_var( self::QUERY_VAR );
	}

	/**
	 * @return array|null
	 */
	public static function context() {
		if ( null === self::$context && self::is_tour_request() ) {
			$tour_id       = self::tour_id_from_slug( (string) get_query_var( self::QUERY_VAR ) );
			$data          = $tour_id ? self::data( $tour_id, get_current_user_id() ) : null;
			self::$context = is_array( $data ) ? $data : array();
		}
		return self::$context ?: null;
	}

	/**
	 * @param string $template Template.
	 */
	public static function template_include( $template ) {
		if ( ! self::is_tour_request() ) {
			return $template;
		}
		if ( ! self::context() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return get_404_template();
		}
		$theme = locate_template( 'page-templates/tour-public.php' );
		return $theme ? $theme : LNKUP_PLUGIN_DIR . 'public/templates/tour-public.php';
	}

	/**
	 * @param string $title Title.
	 */
	public static function document_title( $title ) {
		$ctx = self::is_tour_request() ? self::context() : null;
		return $ctx ? $ctx['title'] . ' — ' . get_bloginfo( 'name' ) : $title;
	}

	public static function enqueue_assets() {
		if ( ! self::is_tour_request() || ! self::context() ) {
			return;
		}
		wp_enqueue_style( 'lnkup-portfolio', LNKUP_PLUGIN_URL . 'public/css/portfolio.css', array(), LNKUP_VERSION );
		wp_enqueue_script( 'lnkup-tour-public', LNKUP_PLUGIN_URL . 'public/js/tour-public.js', array(), LNKUP_VERSION, true );
		wp_localize_script(
			'lnkup-tour-public',
			'lnkupTourPublic',
			array(
				'root'     => esc_url_raw( rest_url( 'lnkup/v1' ) ),
				'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'isVendor' => is_user_logged_in() && LNKup_Roles::is_vendor( get_current_user_id() ),
			)
		);
	}

	/**
	 * Page body for the current tour request.
	 */
	public static function render_current() {
		$ctx = self::context();
		return $ctx ? self::render( $ctx ) : '';
	}

	/**
	 * @param array $tour Public data.
	 */
	public static function render( array $tour ) {
		$user_id   = get_current_user_id();
		$is_vendor = $user_id && LNKup_Roles::is_vendor( $user_id );
		$interest  = $is_vendor ? LNKup_Portfolio_Interest::status_for( $tour['id'], $user_id ) : null;
		$cities    = array_values( array_unique( array_filter( wp_list_pluck( $tour['stops'], 'city' ) ) ) );
		sort( $cities );
		$type_label = array(
			'tour'      => __( 'Multi-city tour', 'lnkup-marketplace' ),
			'series'    => __( 'Event series', 'lnkup-marketplace' ),
			'recurring' => __( 'Recurring market', 'lnkup-marketplace' ),
		);
		ob_start();
		?>
		<article class="lnkup-tour-public" data-testid="lnkup-tour-public" data-tour-id="<?php echo esc_attr( (string) $tour['id'] ); ?>">
			<header class="lnkup-tour-public__hero"<?php echo $tour['cover_url'] ? ' style="--lnkup-tour-cover:url(' . esc_url( $tour['cover_url'] ) . ')"' : ''; ?>>
				<div class="lnkup-tour-public__hero-inner">
					<p class="lnkup-tour-public__eyebrow"><?php echo esc_html( trim( ( $type_label[ $tour['type'] ] ?? $type_label['tour'] ) . ( $tour['season'] ? ' · ' . $tour['season'] : '' ) ) ); ?></p>
					<h1><?php echo esc_html( $tour['title'] ); ?></h1>
					<p class="lnkup-tour-public__meta">
						<?php
						printf(
							/* translators: 1: organizer, 2: number of dates */
							esc_html( _n( 'By %1$s · %2$d upcoming date', 'By %1$s · %2$d upcoming dates', count( $tour['stops'] ), 'lnkup-marketplace' ) ),
							$tour['organizer']['url'] ? '<a href="' . esc_url( $tour['organizer']['url'] ) . '">' . esc_html( $tour['organizer']['name'] ) . '</a>' : esc_html( $tour['organizer']['name'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							count( $tour['stops'] )
						);
						?>
					</p>
					<div class="lnkup-tour-public__actions">
						<?php if ( $tour['multi_apply_url'] && $tour['accepting'] ) : ?>
							<a class="lnkup-btn lnkup-btn--primary" href="<?php echo esc_url( $tour['multi_apply_url'] ); ?>" data-testid="lnkup-tour-public-multi-apply"><?php esc_html_e( 'Apply to several dates', 'lnkup-marketplace' ); ?></a>
						<?php endif; ?>
						<?php if ( $is_vendor ) : ?>
							<button type="button" class="lnkup-btn lnkup-btn--outline" data-tour-interest="<?php echo esc_attr( (string) $tour['id'] ); ?>" data-interested="<?php echo $interest && $interest['interested'] ? '1' : '0'; ?>" aria-pressed="<?php echo $interest && $interest['interested'] ? 'true' : 'false'; ?>" data-testid="lnkup-tour-interest">
								<?php echo $interest && $interest['interested'] ? esc_html__( 'Interested in this tour', 'lnkup-marketplace' ) : esc_html__( 'I\'m interested in the whole tour', 'lnkup-marketplace' ); ?>
							</button>
						<?php elseif ( ! $user_id ) : ?>
							<a class="lnkup-btn lnkup-btn--outline" href="<?php echo esc_url( lnkup_login_url_for( $tour['url'] ) ); ?>"><?php esc_html_e( 'Log in as a vendor to apply', 'lnkup-marketplace' ); ?></a>
						<?php endif; ?>
					</div>
					<p class="lnkup-tour-public__status" role="status" aria-live="polite" data-tour-interest-status hidden></p>
				</div>
			</header>

			<div class="lnkup-tour-public__body">
				<?php if ( $tour['description'] ) : ?>
					<section class="lnkup-tour-public__about">
						<h2><?php esc_html_e( 'About', 'lnkup-marketplace' ); ?></h2>
						<p><?php echo nl2br( esc_html( $tour['description'] ) ); ?></p>
						<?php if ( $tour['eligibility'] ) : ?>
							<h3><?php esc_html_e( 'Who can apply', 'lnkup-marketplace' ); ?></h3>
							<p><?php echo nl2br( esc_html( $tour['eligibility'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( $tour['categories'] ) : ?>
							<ul class="lnkup-tour-public__chips" aria-label="<?php esc_attr_e( 'Vendor categories', 'lnkup-marketplace' ); ?>">
								<?php foreach ( $tour['categories'] as $category ) : ?>
									<li><?php echo esc_html( $category ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</section>
				<?php endif; ?>

				<section class="lnkup-tour-public__stops" aria-labelledby="lnkup-tour-stops-title">
					<div class="lnkup-tour-public__stops-head">
						<h2 id="lnkup-tour-stops-title"><?php esc_html_e( 'Dates & cities', 'lnkup-marketplace' ); ?></h2>
						<?php if ( count( $cities ) > 1 ) : ?>
							<label class="lnkup-tour-public__filter">
								<span><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?></span>
								<select data-tour-city-filter>
									<option value=""><?php esc_html_e( 'All cities', 'lnkup-marketplace' ); ?></option>
									<?php foreach ( $cities as $city ) : ?>
										<option value="<?php echo esc_attr( $city ); ?>"><?php echo esc_html( $city ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						<?php endif; ?>
					</div>
					<?php if ( ! $tour['stops'] ) : ?>
						<p class="lnkup-empty-state"><?php esc_html_e( 'No upcoming dates are open yet. Check back soon.', 'lnkup-marketplace' ); ?></p>
					<?php else : ?>
						<ol class="lnkup-tour-public__list">
							<?php foreach ( $tour['stops'] as $stop ) : ?>
								<li class="lnkup-tour-stop-card" data-city="<?php echo esc_attr( $stop['city'] ); ?>" data-testid="lnkup-tour-public-stop">
									<div class="lnkup-tour-stop-card__when">
										<strong><?php echo esc_html( $stop['date'] ? wp_date( 'M j', strtotime( $stop['date'] . ' 12:00:00' ) ) : __( 'Date TBA', 'lnkup-marketplace' ) ); ?></strong>
										<span><?php echo esc_html( $stop['date'] ? wp_date( 'D, Y', strtotime( $stop['date'] . ' 12:00:00' ) ) : '' ); ?></span>
									</div>
									<div class="lnkup-tour-stop-card__where">
										<h3><?php echo esc_html( trim( $stop['city'] . ( $stop['state'] ? ', ' . $stop['state'] : '' ) ) ?: $stop['title'] ); ?></h3>
										<p class="lnkup-muted"><?php echo esc_html( $stop['venue_name'] ); ?></p>
										<?php if ( $stop['opportunities'] ) : ?>
											<ul class="lnkup-tour-stop-card__booths">
												<?php foreach ( $stop['opportunities'] as $opp ) : ?>
													<li>
														<span><?php echo esc_html( $opp['title'] ); ?></span>
														<span><?php echo esc_html( $opp['vendor_fee'] > 0 ? '$' . number_format_i18n( $opp['vendor_fee'], 2 ) : __( 'Free', 'lnkup-marketplace' ) ); ?></span>
														<?php if ( $opp['application_status'] ) : ?>
															<span class="lnkup-badge"><?php echo esc_html( ucwords( str_replace( '_', ' ', $opp['application_status'] ) ) ); ?></span>
														<?php elseif ( $opp['is_open'] ) : ?>
															<a class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" href="<?php echo esc_url( $opp['apply_url'] ); ?>"><?php esc_html_e( 'Apply', 'lnkup-marketplace' ); ?></a>
														<?php else : ?>
															<span class="lnkup-badge lnkup-badge--muted"><?php esc_html_e( 'Closed', 'lnkup-marketplace' ); ?></span>
														<?php endif; ?>
														<?php if ( $opp['is_open'] && $opp['deadline'] ) : ?>
															<small class="lnkup-muted"><?php echo esc_html( sprintf( __( 'Apply by %s', 'lnkup-marketplace' ), wp_date( 'M j', strtotime( $opp['deadline'] . ' 12:00:00' ) ) ) ); ?></small>
														<?php endif; ?>
													</li>
												<?php endforeach; ?>
											</ul>
										<?php else : ?>
											<p class="lnkup-muted"><?php esc_html_e( 'Vendor spots for this date are not open yet.', 'lnkup-marketplace' ); ?></p>
										<?php endif; ?>
									</div>
								</li>
							<?php endforeach; ?>
						</ol>
						<p class="lnkup-empty-state" data-tour-filter-empty hidden><?php esc_html_e( 'No dates in that city.', 'lnkup-marketplace' ); ?></p>
					<?php endif; ?>
				</section>
			</div>
		</article>
		<?php
		return (string) ob_get_clean();
	}
}
