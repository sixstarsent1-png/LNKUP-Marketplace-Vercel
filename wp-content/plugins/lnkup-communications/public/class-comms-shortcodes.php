<?php
/**
 * Dashboard shortcodes: notification preferences and organizer event communications.
 * The theme only places these; all logic lives in this plugin.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Shortcodes {

	const PAGES = array(
		'notification-settings' => array( 'title' => 'Notification settings', 'shortcode' => '[lnkup_notification_preferences]', 'template' => 'page-templates/vendor-shell.php' ),
		'event-communications'  => array( 'title' => 'Event communications', 'shortcode' => '[lnkup_event_communications]', 'template' => '' ),
	);

	public static function init(): void {
		add_shortcode( 'lnkup_notification_preferences', array( __CLASS__, 'preferences' ) );
		add_shortcode( 'lnkup_event_communications', array( __CLASS__, 'event_communications' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_pages' ) );
		add_action(
			'init',
			static function (): void {
				if ( ! get_option( 'lnkup_comms_pages_created' ) ) {
					self::ensure_pages( true );
				}
			},
			30
		);
		add_filter( 'lnkup_event_workspace_actions', array( __CLASS__, 'workspace_action' ), 10, 2 );
		add_filter( 'lnkup_dashboard_nav_items', array( __CLASS__, 'nav_item' ), 10, 2 );
	}

	/**
	 * "Notifications" entry in the dashboard sidebar (More tier).
	 */
	public static function nav_item( $items, $role ): array {
		$items = (array) $items;
		if ( ! in_array( $role, array( 'vendor', 'organizer' ), true ) ) {
			return $items;
		}
		$items[] = array(
			'label' => __( 'Notifications', 'lnkup-communications' ),
			'href'  => LNKup_Comms_Suppression::preferences_url(),
			'slug'  => 'notification-settings',
			'icon'  => 'mail',
			'view'  => '',
			'tier'  => 'more',
		);
		return $items;
	}

	/**
	 * "Communications" button in the organizer event workspace header.
	 */
	public static function workspace_action( $html, $event_id ): string {
		$event_id = absint( $event_id );
		$user_id  = get_current_user_id();
		if ( ! LNKup_Comms_Settings::enabled( 'organizer_broadcasts' ) || ! $event_id ) {
			return (string) $html;
		}
		if ( ! LNKup_Capabilities::organizer_owns_event( $event_id, $user_id ) && ! LNKup_Capabilities::staff_can( $event_id, 'broadcast', $user_id ) ) {
			return (string) $html;
		}
		$page = function_exists( 'lnkup_page_url_helper' ) ? (string) lnkup_page_url_helper( 'event-communications' ) : home_url( '/event-communications/' );
		return (string) $html . '<a class="lnkup-btn lnkup-btn--outline" data-testid="lnkup-event-communications" href="' . esc_url( add_query_arg( 'event', $event_id, $page ) ) . '">' . esc_html__( 'Communications', 'lnkup-communications' ) . '</a>';
	}

	/**
	 * Create the two pages once (never overwrites existing pages).
	 */
	public static function ensure_pages( bool $force = false ): void {
		if ( get_option( 'lnkup_comms_pages_created' ) || ( ! $force && ! current_user_can( 'manage_options' ) ) ) {
			return;
		}
		$created = get_option( 'lnkup_pages_created', array() );
		$created = is_array( $created ) ? $created : array();
		foreach ( self::PAGES as $slug => $page ) {
			$existing = get_page_by_path( $slug );
			if ( $existing ) {
				$created[ $slug ] = (int) $existing->ID;
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $page['title'],
					'post_name'    => $slug,
					'post_content'  => $page['shortcode'],
					'page_template' => '' !== $page['template'] && '' !== locate_template( $page['template'] ) ? $page['template'] : '',
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				$created[ $slug ] = (int) $id;
			}
		}
		// Registers with lnkup_page_url_helper() so links resolve.
		update_option( 'lnkup_pages_created', $created );
		update_option( 'lnkup_comms_pages_created', 1, false );
	}

	public static function register_assets(): void {
		wp_register_style( 'lnkup-comms', LNKUP_COMMS_URL . 'public/css/comms.css', array(), LNKUP_COMMS_VERSION );
		wp_register_script( 'lnkup-comms-preferences', LNKUP_COMMS_URL . 'public/js/comms-preferences.js', array(), LNKUP_COMMS_VERSION, true );
		wp_register_script( 'lnkup-comms-event', LNKUP_COMMS_URL . 'public/js/comms-event.js', array(), LNKUP_COMMS_VERSION, true );
	}

	private static function boot_data(): array {
		return array(
			'restUrl' => esc_url_raw( rest_url( 'lnkup/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		);
	}

	private static function login_prompt(): string {
		$login = function_exists( 'lnkup_login_url_for' ) ? lnkup_login_url_for( get_permalink() ?: home_url( '/' ) ) : wp_login_url( get_permalink() ?: '' );
		return '<div class="lnkup-comms-card lnkup-comms-state"><p>' . esc_html__( 'Sign in to manage your notifications.', 'lnkup-communications' ) . '</p><a class="lnkup-comms-btn" href="' . esc_url( $login ) . '">' . esc_html__( 'Sign in', 'lnkup-communications' ) . '</a></div>';
	}

	public static function preferences(): string {
		if ( ! is_user_logged_in() ) {
			return self::login_prompt();
		}
		wp_enqueue_style( 'lnkup-comms' );
		wp_enqueue_script( 'lnkup-comms-preferences' );
		wp_localize_script(
			'lnkup-comms-preferences',
			'lnkupComms',
			array_merge(
				self::boot_data(),
				array(
					'i18n' => array(
						'loading'     => __( 'Loading your notification settings…', 'lnkup-communications' ),
						'error'       => __( 'We could not load your notification settings. Try refreshing or contact support.', 'lnkup-communications' ),
						'saveError'   => __( 'We could not save your changes. Please try again.', 'lnkup-communications' ),
						'saved'       => __( 'Settings saved', 'lnkup-communications' ),
						'save'        => __( 'Save settings', 'lnkup-communications' ),
						'saving'      => __( 'Saving…', 'lnkup-communications' ),
						'category'    => __( 'Notification', 'lnkup-communications' ),
						'email'       => __( 'Email', 'lnkup-communications' ),
						'push'        => __( 'Push', 'lnkup-communications' ),
						'locked'      => __( 'Always on', 'lnkup-communications' ),
						'immediate'   => __( 'Immediately', 'lnkup-communications' ),
						'daily'       => __( 'Daily digest', 'lnkup-communications' ),
						'weekly'      => __( 'Weekly digest', 'lnkup-communications' ),
						'off'         => __( 'Off', 'lnkup-communications' ),
						'on'          => __( 'On', 'lnkup-communications' ),
						'pushMaster'  => __( 'Allow push notifications on my devices', 'lnkup-communications' ),
						'quiet'       => __( 'Quiet hours (non-urgent push waits until they end)', 'lnkup-communications' ),
						'from'        => __( 'From', 'lnkup-communications' ),
						'to'          => __( 'To', 'lnkup-communications' ),
						'timezone'    => __( 'Time zone', 'lnkup-communications' ),
						'promo'       => __( 'Marketing', 'lnkup-communications' ),
						'note'        => __( 'Urgent event, payment and account messages are always delivered in your inbox. Your phone settings still control whether push alerts appear.', 'lnkup-communications' ),
						'empty'       => __( 'There are no notification settings for your account yet.', 'lnkup-communications' ),
					),
					'timezones' => timezone_identifiers_list(),
				)
			)
		);
		return '<section class="lnkup-comms" id="lnkup-comms-preferences" aria-live="polite"><div class="lnkup-comms-card lnkup-comms-state lnkup-comms-skeleton">' . esc_html__( 'Loading your notification settings…', 'lnkup-communications' ) . '</div></section>';
	}

	public static function event_communications( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return self::login_prompt();
		}
		$atts = shortcode_atts( array( 'event' => 0 ), (array) $atts );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selection; REST enforces permissions.
		$event_id = absint( $atts['event'] ?: ( $_GET['event'] ?? 0 ) );
		if ( ! $event_id || 'lnkup_event' !== get_post_type( $event_id ) ) {
			return '<div class="lnkup-comms-card lnkup-comms-state"><p>' . esc_html__( 'Open Communications from one of your events.', 'lnkup-communications' ) . '</p></div>';
		}
		if ( ! LNKup_Capabilities::organizer_owns_event( $event_id, get_current_user_id() ) && ! LNKup_Capabilities::staff_can( $event_id, 'broadcast', get_current_user_id() ) ) {
			return '<div class="lnkup-comms-card lnkup-comms-state"><p>' . esc_html__( 'You do not have access to message vendors for this event.', 'lnkup-communications' ) . '</p></div>';
		}
		if ( ! LNKup_Comms_Settings::enabled( 'organizer_broadcasts' ) ) {
			return '<div class="lnkup-comms-card lnkup-comms-state"><p>' . esc_html__( 'Event communications are coming soon.', 'lnkup-communications' ) . '</p></div>';
		}
		wp_enqueue_style( 'lnkup-comms' );
		wp_enqueue_script( 'lnkup-comms-event' );
		wp_localize_script(
			'lnkup-comms-event',
			'lnkupCommsEvent',
			array_merge(
				self::boot_data(),
				array(
					'eventId' => $event_id,
					'i18n'    => array(
						'loading'    => __( 'Loading event communications…', 'lnkup-communications' ),
						'error'      => __( 'We could not load this section. Try refreshing or contact support.', 'lnkup-communications' ),
						'recipients' => __( 'Recipients', 'lnkup-communications' ),
						'type'       => __( 'Message type', 'lnkup-communications' ),
						'title'      => __( 'Title', 'lnkup-communications' ),
						'message'    => __( 'Message', 'lnkup-communications' ),
						'channels'   => __( 'Also send by', 'lnkup-communications' ),
						'email'      => __( 'Email', 'lnkup-communications' ),
						'push'       => __( 'Push', 'lnkup-communications' ),
						'preview'    => __( 'Preview recipients', 'lnkup-communications' ),
						'send'       => __( 'Send message', 'lnkup-communications' ),
						'sending'    => __( 'Sending…', 'lnkup-communications' ),
						'sent'       => __( 'Message queued for delivery.', 'lnkup-communications' ),
						'confirm'    => __( 'Send this message to %d vendors?', 'lnkup-communications' ),
						'history'    => __( 'Sent messages', 'lnkup-communications' ),
						'noHistory'  => __( 'No messages sent for this event yet.', 'lnkup-communications' ),
						'inbox'      => __( 'inbox', 'lnkup-communications' ),
						'excluded'   => __( 'Opted out or unreachable', 'lnkup-communications' ),
						'notAllowed' => __( 'This message type cannot be sent to the selected group.', 'lnkup-communications' ),
						'noVendors'  => __( 'No vendors in this group', 'lnkup-communications' ),
					),
				)
			)
		);
		return '<section class="lnkup-comms" id="lnkup-comms-event" aria-live="polite"><div class="lnkup-comms-card lnkup-comms-state lnkup-comms-skeleton">' . esc_html__( 'Loading event communications…', 'lnkup-communications' ) . '</div></section>';
	}
}
