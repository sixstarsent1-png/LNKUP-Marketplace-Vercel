<?php
/**
 * Advertising tracking consent.
 *
 * LNKup operates in the US with an opt-out model: tracking is allowed unless the visitor
 * sends Global Privacy Control, has the opt-out cookie, or the signed-in user opted out.
 * A refusal blocks both the browser Pixel and the server Conversions API; the action is
 * still recorded internally (consent_state = denied). A consent management platform can
 * take over through the `lnkup_meta_tracking_allowed` filter.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Tracking_Consent {

	const OPTOUT_COOKIE = 'lnkup_tracking_optout';
	const USER_META     = '_lnkup_tracking_optout';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_handle_link' ), 6 );
		add_shortcode( 'lnkup_tracking_preferences', array( __CLASS__, 'render_preferences' ) );
	}

	/**
	 * Whether the current HTTP request carries a refusal (GPC header or opt-out cookie).
	 */
	public static function request_refuses(): bool {
		$gpc = isset( $_SERVER['HTTP_SEC_GPC'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '1' === $gpc ) {
			return true;
		}
		return isset( $_COOKIE[ self::OPTOUT_COOKIE ] ) && '1' === (string) $_COOKIE[ self::OPTOUT_COOKIE ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	public static function user_opted_out( int $user_id ): bool {
		return $user_id > 0 && '1' === (string) get_user_meta( $user_id, self::USER_META, true );
	}

	/**
	 * Consent state for an event.
	 *
	 * @param int  $user_id    Event owner (0 for anonymous).
	 * @param bool $in_request Whether the current request was made by that person.
	 * @return string granted|denied
	 */
	public static function state( int $user_id, bool $in_request ): string {
		$denied = self::user_opted_out( $user_id ) || ( $in_request && self::request_refuses() );
		$allowed = (bool) apply_filters( 'lnkup_meta_tracking_allowed', ! $denied, $user_id, $in_request );
		return $allowed ? 'granted' : 'denied';
	}

	/**
	 * Persist a preference for the signed-in user and this browser.
	 *
	 * @param bool $opt_out Opt out of advertising tracking.
	 */
	public static function set_preference( bool $opt_out ): void {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, self::USER_META, $opt_out ? '1' : '0' );
			if ( $opt_out && class_exists( 'LNKup_Conversion_Registry' ) ) {
				delete_user_meta( $user_id, LNKup_Conversion_Registry::PENDING_BROWSER_META );
			}
		}
		if ( ! headers_sent() ) {
			setcookie(
				self::OPTOUT_COOKIE,
				$opt_out ? '1' : '0',
				array(
					'expires'  => time() + YEAR_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::OPTOUT_COOKIE ] = $opt_out ? '1' : '0';
	}

	/**
	 * `?lnkup_tracking=optout|optin` links (for the privacy policy). Opting out needs no nonce
	 * so it keeps working from cached pages; opting back in requires one.
	 */
	public static function maybe_handle_link(): void {
		if ( empty( $_GET['lnkup_tracking'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$choice = sanitize_key( wp_unslash( (string) $_GET['lnkup_tracking'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'optin' === $choice ) {
			$nonce = isset( $_GET['_lnkupnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_lnkupnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! wp_verify_nonce( $nonce, 'lnkup_tracking_pref' ) ) {
				return;
			}
		} elseif ( 'optout' !== $choice ) {
			return;
		}
		self::set_preference( 'optout' === $choice );
		wp_safe_redirect( remove_query_arg( array( 'lnkup_tracking', '_lnkupnonce' ) ) );
		exit;
	}

	/**
	 * [lnkup_tracking_preferences] — place on the privacy policy or account settings page.
	 */
	public static function render_preferences(): string {
		$opted_out = self::user_opted_out( get_current_user_id() ) || self::request_refuses();
		$choice    = $opted_out ? 'optin' : 'optout';
		$url       = wp_nonce_url( add_query_arg( 'lnkup_tracking', $choice ), 'lnkup_tracking_pref', '_lnkupnonce' );
		ob_start();
		?>
		<div class="lnkup-card lnkup-tracking-preferences">
			<h3><?php esc_html_e( 'Advertising measurement', 'lnkup-marketplace' ); ?></h3>
			<p>
				<?php
				echo esc_html(
					$opted_out
						? __( 'You have opted out. LNKup does not share your activity with advertising partners such as Meta.', 'lnkup-marketplace' )
						: __( 'LNKup shares limited activity (for example, completed sign-ups) with Meta to measure our ads. We never share messages, applications, documents or payment details.', 'lnkup-marketplace' )
				);
				?>
			</p>
			<?php if ( self::request_refuses() && isset( $_SERVER['HTTP_SEC_GPC'] ) ) : // phpcs:ignore WordPress.Security.ValidatedSanitizedInput ?>
				<p class="lnkup-muted"><?php esc_html_e( 'Your browser sends a Global Privacy Control signal, which we honor.', 'lnkup-marketplace' ); ?></p>
			<?php endif; ?>
			<a class="lnkup-btn lnkup-btn--secondary" href="<?php echo esc_url( $url ); ?>">
				<?php echo esc_html( $opted_out ? __( 'Allow advertising measurement', 'lnkup-marketplace' ) : __( 'Opt out of advertising measurement', 'lnkup-marketplace' ) ); ?>
			</a>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
