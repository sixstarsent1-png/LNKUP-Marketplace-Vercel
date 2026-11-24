<?php
/**
 * Optional prompts to get the LNKup iOS app.
 *
 * Prompts never block content: they can be dismissed (snoozed), turned off per user,
 * and are hidden for people who already sign in from the mobile app.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_App_Promo {

	const DEFAULT_STORE_URL = 'https://apps.apple.com/app/id6795865859';
	const META_DISMISSED_AT = 'lnkup_app_promo_dismissed_at';
	const META_OPT_OUT      = 'lnkup_app_promo_opt_out';
	const META_APP_USER     = 'lnkup_uses_mobile_app';
	const SNOOZE_DAYS       = 60;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'application_password_did_authenticate', array( __CLASS__, 'handle_application_password_auth' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
	}

	/**
	 * Register (not enqueue) front-end assets; enqueue() loads them when a prompt renders.
	 */
	public static function register_assets() {
		wp_register_style( 'lnkup-app-promo', LNKUP_PLUGIN_URL . 'public/css/app-promo.css', array(), lnkup_asset_version( 'public/css/app-promo.css' ) );
		wp_register_script( 'lnkup-app-promo', LNKUP_PLUGIN_URL . 'public/js/app-promo.js', array(), lnkup_asset_version( 'public/js/app-promo.js' ), true );
	}

	/**
	 * Enqueue prompt assets once per request.
	 */
	public static function enqueue() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		if ( ! wp_script_is( 'lnkup-app-promo', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'lnkup-app-promo' );
		wp_enqueue_script( 'lnkup-app-promo' );
		wp_localize_script(
			'lnkup-app-promo',
			'lnkupAppPromo',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => is_user_logged_in() ? wp_create_nonce( 'lnkup_action' ) : '',
				'loggedIn'   => is_user_logged_in(),
				'snoozeDays' => self::SNOOZE_DAYS,
			)
		);
	}

	/**
	 * Whether app prompts are switched on site-wide and the app is public.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = lnkup_get_settings();
		if ( isset( $settings['app_prompts_enabled'] ) && ! $settings['app_prompts_enabled'] ) {
			return false;
		}
		return function_exists( 'lnkup_is_app_released' ) ? lnkup_is_app_released() : true;
	}

	/**
	 * App Store URL, optionally tagged with a campaign token for App Store Connect analytics.
	 *
	 * @param string $context Short campaign label, e.g. homepage, organizer, vendor, nav.
	 * @return string
	 */
	public static function store_url( $context = '' ) {
		$settings = lnkup_get_settings();
		$url      = isset( $settings['app_store_url'] ) ? trim( (string) $settings['app_store_url'] ) : '';
		if ( '' === $url ) {
			$url = self::DEFAULT_STORE_URL;
		}
		$context = sanitize_key( (string) $context );
		if ( '' !== $context ) {
			$url = add_query_arg( 'ct', 'lnkup-' . $context, $url );
		}
		return $url;
	}

	/**
	 * Numeric App Store ID parsed from the store URL.
	 *
	 * @return string
	 */
	public static function app_id() {
		if ( preg_match( '/\/id(\d+)/', self::store_url(), $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * QR image that opens the App Store on a phone.
	 *
	 * @param string $context Campaign label.
	 * @return string
	 */
	public static function qr_url( $context = '' ) {
		return class_exists( 'LNKup_QR' ) ? LNKup_QR::data_uri( self::store_url( $context ), 160, 2 ) : '';
	}

	/**
	 * Whether the user has signed in from the mobile app.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_app_user( $user_id ) {
		return $user_id > 0 && (bool) get_user_meta( (int) $user_id, self::META_APP_USER, true );
	}

	/**
	 * Whether the user turned app suggestions off.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_opted_out( $user_id ) {
		return $user_id > 0 && (bool) get_user_meta( (int) $user_id, self::META_OPT_OUT, true );
	}

	/**
	 * Whether a prompt should render for this user.
	 *
	 * Logged-out visitors always pass here; their dismissal lives in localStorage.
	 *
	 * @param int|null $user_id User ID; defaults to the current user.
	 * @return bool
	 */
	public static function should_show( $user_id = null ) {
		if ( ! self::is_enabled() ) {
			return false;
		}
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( $user_id <= 0 ) {
			return true;
		}
		if ( self::is_app_user( $user_id ) || self::is_opted_out( $user_id ) ) {
			return false;
		}
		$dismissed_at = (int) get_user_meta( $user_id, self::META_DISMISSED_AT, true );
		return ! $dismissed_at || ( time() - $dismissed_at ) > self::SNOOZE_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Snooze prompts for SNOOZE_DAYS.
	 *
	 * @param int $user_id User ID.
	 */
	public static function dismiss( $user_id ) {
		update_user_meta( (int) $user_id, self::META_DISMISSED_AT, time() );
	}

	/**
	 * Turn app suggestions on or off for a user. Turning them on also ends any "Not now" snooze.
	 *
	 * @param int  $user_id User ID.
	 * @param bool $opt_out True to hide suggestions.
	 */
	public static function set_opt_out( $user_id, $opt_out ) {
		if ( $opt_out ) {
			update_user_meta( (int) $user_id, self::META_OPT_OUT, 1 );
			return;
		}
		delete_user_meta( (int) $user_id, self::META_OPT_OUT );
		delete_user_meta( (int) $user_id, self::META_DISMISSED_AT );
	}

	/**
	 * Remember that a user signs in from the mobile app.
	 *
	 * @param int $user_id User ID.
	 */
	public static function mark_app_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id > 0 && ! self::is_app_user( $user_id ) ) {
			update_user_meta( $user_id, self::META_APP_USER, 1 );
		}
	}

	/**
	 * Native app authenticates REST calls with an Application Password.
	 *
	 * @param WP_User $user Authenticated user.
	 */
	public static function handle_application_password_auth( $user ) {
		if ( $user instanceof WP_User ) {
			self::mark_app_user( $user->ID );
		}
	}

	/**
	 * Content for Safari's apple-itunes-app meta tag. The theme prints the tag.
	 *
	 * app-argument passes the current URL so an installed app can open the matching screen.
	 *
	 * @return string Empty when no banner should show.
	 */
	public static function smart_banner_content() {
		if ( ! self::should_show() ) {
			return '';
		}
		$app_id = self::app_id();
		if ( '' === $app_id ) {
			return '';
		}
		$request = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return 'app-id=' . $app_id . ', app-argument=' . home_url( $request );
	}

	/**
	 * Per-user "Hide app suggestions" setting.
	 *
	 * @return string
	 */
	public static function render_preference_toggle() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! self::is_enabled() ) {
			return '';
		}
		self::enqueue();
		ob_start();
		?>
		<div class="lnkup-panel lnkup-app-promo-pref" data-testid="lnkup-app-promo-pref">
			<h2 class="lnkup-panel__title"><?php esc_html_e( 'iPhone app', 'lnkup-marketplace' ); ?></h2>
			<label class="lnkup-app-promo-pref__label">
				<input type="checkbox" data-lnkup-app-promo-opt-out <?php checked( self::is_opted_out( $user_id ) ); ?> />
				<?php esc_html_e( 'Hide app suggestions', 'lnkup-marketplace' ); ?>
			</label>
			<p class="lnkup-muted"><?php esc_html_e( 'Stops "Get the iPhone app" cards, banners, and links for this account. You can turn them back on anytime.', 'lnkup-marketplace' ); ?></p>
			<p class="lnkup-app-promo-pref__status lnkup-muted" data-lnkup-app-promo-opt-out-status role="status" aria-live="polite"></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Dismissible dashboard card.
	 *
	 * @param string $role organizer|vendor.
	 * @return string
	 */
	public static function render_card( $role ) {
		if ( ! self::should_show() ) {
			return '';
		}
		self::enqueue();

		$role = 'organizer' === $role ? 'organizer' : 'vendor';
		if ( 'organizer' === $role ) {
			$title  = __( 'Run event day from your phone', 'lnkup-marketplace' );
			$points = array(
				__( 'Review vendor requests on the go', 'lnkup-marketplace' ),
				__( 'Check in vendors at load-in', 'lnkup-marketplace' ),
				__( 'Get alerts when vendors apply or pay', 'lnkup-marketplace' ),
			);
		} else {
			$title  = __( 'Take LNKup to your next event', 'lnkup-marketplace' );
			$points = array(
				__( 'Get alerts the moment you are approved', 'lnkup-marketplace' ),
				__( 'Keep your event pass and QR check-in handy', 'lnkup-marketplace' ),
				__( 'Find and apply to events from anywhere', 'lnkup-marketplace' ),
			);
		}

		ob_start();
		?>
		<section class="lnkup-app-promo lnkup-app-promo--card" data-lnkup-app-promo data-testid="lnkup-app-promo-card" aria-labelledby="lnkup-app-promo-title-<?php echo esc_attr( $role ); ?>">
			<div class="lnkup-app-promo__body">
				<p class="lnkup-app-promo__eyebrow"><?php esc_html_e( 'LNKup for iPhone', 'lnkup-marketplace' ); ?></p>
				<h2 class="lnkup-app-promo__title" id="lnkup-app-promo-title-<?php echo esc_attr( $role ); ?>"><?php echo esc_html( $title ); ?></h2>
				<ul class="lnkup-app-promo__points">
					<?php foreach ( $points as $point ) : ?>
						<li><?php echo esc_html( $point ); ?></li>
					<?php endforeach; ?>
				</ul>
				<div class="lnkup-app-promo__actions">
					<a class="lnkup-app-promo__store" href="<?php echo esc_url( self::store_url( $role ) ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Download on the App Store', 'lnkup-marketplace' ); ?>
					</a>
					<p class="lnkup-app-promo__android"><?php esc_html_e( 'The LNKup app is available for iPhone. Keep using the web on this device.', 'lnkup-marketplace' ); ?></p>
					<button type="button" class="lnkup-app-promo__dismiss" data-lnkup-app-promo-dismiss><?php esc_html_e( 'Not now', 'lnkup-marketplace' ); ?></button>
				</div>
			</div>
			<figure class="lnkup-app-promo__qr">
				<img src="<?php echo esc_attr( self::qr_url( $role ) ); ?>" width="120" height="120" alt="<?php esc_attr_e( 'QR code to download LNKup from the App Store', 'lnkup-marketplace' ); ?>" loading="lazy" />
				<figcaption><?php esc_html_e( 'Scan with your iPhone camera', 'lnkup-marketplace' ); ?></figcaption>
			</figure>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
