<?php
/**
 * Organizer dashboard → Create (Event Creation OS 3.0).
 *
 * Renders the shell for the builder app (event-builder.js) on the Organizer OS 2.0 design system.
 * The app talks only to lnkup/v1/organizer/builder/*, /locations/* and the import routes.
 * With the event_builder_v3 flag off the legacy create wizard keeps rendering.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Builder_View {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ), 30 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_legacy_edit' ), 5 );
	}

	/**
	 * Existing "Edit event" links (?event=ID&edit=1) open Edit 2.0 when the builder is on.
	 * `classic=1` keeps the previous form available.
	 */
	public static function redirect_legacy_edit(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['edit'] ) || empty( $_GET['event'] ) || ! empty( $_GET['classic'] ) || ! is_page( 'organizer-dashboard' ) || ! self::active() ) {
			return;
		}
		$event_id = absint( $_GET['event'] );
		// phpcs:enable
		if ( ! class_exists( 'LNKup_Event_Builder_Editor' ) || ! LNKup_Event_Builder_Editor::can_edit_event( $event_id, get_current_user_id() ) ) {
			return;
		}
		wp_safe_redirect( lnkup_organizer_view_url( 'create', array( 'edit' => $event_id ) ) );
		exit;
	}

	public static function active(): bool {
		return class_exists( 'LNKup_Event_Builder_REST' ) && LNKup_Event_Builder_REST::can_use();
	}

	public static function on_builder_page(): bool {
		if ( ! function_exists( 'lnkup_organizer_dashboard_view' ) || ! is_page() ) {
			return false;
		}
		$page = get_queried_object();
		if ( ! $page instanceof WP_Post || 'organizer-dashboard' !== $page->post_name ) {
			return false;
		}
		return in_array( lnkup_organizer_dashboard_view(), array( 'create', 'imports' ), true );
	}

	public static function maybe_enqueue(): void {
		if ( self::active() && self::on_builder_page() ) {
			self::enqueue();
		}
	}

	public static function enqueue(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		$ver  = static fn( string $rel ) => function_exists( 'lnkup_asset_version' ) ? lnkup_asset_version( $rel ) : LNKUP_VERSION;
		wp_enqueue_style( 'lnkup-event-builder', LNKUP_PLUGIN_URL . 'public/css/event-builder.css', array(), $ver( 'public/css/event-builder.css' ) );
		$deps = array();
		if ( wp_script_is( 'lnkup-qr', 'registered' ) ) {
			$deps[] = 'lnkup-qr';
		}
		wp_enqueue_script( 'lnkup-event-builder', LNKUP_PLUGIN_URL . 'public/js/event-builder.js', $deps, $ver( 'public/js/event-builder.js' ), true );
		$user = get_current_user_id();
		$get  = static fn( string $k ): string => isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = function_exists( 'lnkup_organizer_view_url' ) ? 'lnkup_organizer_view_url' : null;
		$url  = static fn( string $v, array $a = array() ) => $view ? lnkup_organizer_view_url( $v, $a ) : home_url( '/organizer-dashboard/?view=' . $v );
		wp_localize_script(
			'lnkup-event-builder',
			'lnkupEventBuilder',
			array(
				'rest'      => esc_url_raw( rest_url( 'lnkup/v1/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'view'      => function_exists( 'lnkup_organizer_dashboard_view' ) ? lnkup_organizer_dashboard_view() : 'create',
				'draft'     => absint( $get( 'draft' ) ),
				'step'      => sanitize_key( $get( 'step' ) ),
				'edit'      => absint( $get( 'edit' ) ),
				'duplicate' => absint( $get( 'duplicate' ) ),
				'template'  => $get( 'template' ),
				'method'    => sanitize_key( $get( 'method' ) ),
				'importUrl' => esc_url_raw( $get( 'url' ) ),
				'flags'     => class_exists( 'LNKup_Event_OS_Flags' ) ? LNKup_Event_OS_Flags::all() : array(),
				'urls'      => array(
					'create'     => $url( 'create' ),
					'events'     => $url( 'events' ),
					'tours'      => $url( 'tours' ),
					'imports'    => $url( 'imports' ),
					'financial'  => $url( 'financial' ),
					'dashboard'  => $url( 'home' ),
				),
				'organizer' => class_exists( 'LNKup_OS_UI' ) && method_exists( 'LNKup_OS_UI', 'organizer_name' ) ? LNKup_OS_UI::organizer_name( $user ) : wp_get_current_user()->display_name,
			)
		);
	}

	/**
	 * App shell. Content renders client-side; a short server message covers no-JS and errors.
	 */
	public static function render( string $mode = 'create' ): string {
		self::enqueue();
		$title = 'imports' === $mode ? __( 'Event imports', 'lnkup-marketplace' ) : __( 'Create', 'lnkup-marketplace' );
		ob_start();
		?>
		<div class="eb-app" data-testid="lnkup-event-builder" data-mode="<?php echo esc_attr( $mode ); ?>" aria-live="polite">
			<div class="eb-boot" data-eb-boot>
				<p class="eb-boot__title"><?php echo esc_html( $title ); ?></p>
				<div class="eb-skeleton" aria-hidden="true"><span></span><span></span><span></span></div>
				<noscript><p><?php esc_html_e( 'Turn on JavaScript to create events.', 'lnkup-marketplace' ); ?></p></noscript>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
