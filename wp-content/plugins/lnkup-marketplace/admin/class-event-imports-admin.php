<?php
/**
 * wp-admin → LNKup → Event Imports.
 *
 * Provider capability table with on/off switches, recent import jobs across organizers, linked
 * listings with sync state, and the Event Creation OS feature-flag / credential status. Provider
 * credentials are never displayed — only whether they are configured.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Imports_Admin {

	const SLUG   = 'lnkup-event-imports';
	const CAP    = 'lnkup_manage_settings';
	const ACTION = 'lnkup_event_imports_providers';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 33 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save_providers' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'lnkup-marketplace', __( 'Event Imports', 'lnkup-marketplace' ), __( 'Event Imports', 'lnkup-marketplace' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	private static function can(): bool {
		return current_user_can( self::CAP ) || ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() );
	}

	public static function save_providers(): void {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'You do not have permission to change import providers.', 'lnkup-marketplace' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$enabled  = array_map( 'sanitize_key', (array) ( $_POST['enabled'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$disabled = array_values( array_diff( array_keys( LNKup_Import_Provider_Registry::all() ), $enabled ) );
		LNKup_Import_Provider_Registry::set_disabled( $disabled );
		if ( class_exists( 'LNKup_Audit_Log' ) ) {
			LNKup_Audit_Log::log( 'event_import_providers_updated', 'setting', 0, array( 'new' => array( 'disabled' => $disabled ), 'actor_user_id' => get_current_user_id() ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'You do not have permission to view event imports.', 'lnkup-marketplace' ), 403 );
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'providers'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array( 'providers' => __( 'Providers', 'lnkup-marketplace' ), 'jobs' => __( 'Import jobs', 'lnkup-marketplace' ), 'sources' => __( 'Linked listings', 'lnkup-marketplace' ), 'setup' => __( 'Setup', 'lnkup-marketplace' ) );
		echo '<div class="wrap" data-testid="lnkup-event-imports-admin"><h1>' . esc_html__( 'Event Imports', 'lnkup-marketplace' ) . '</h1>';
		echo '<p>' . esc_html__( 'Organizers import events they already list elsewhere. LNKup reads only public structured data or documented account APIs, never publishes imported events automatically, and never imports attendee prices or capacity.', 'lnkup-marketplace' ) . '</p>';
		if ( ! empty( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Providers updated.', 'lnkup-marketplace' ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf( '<a class="nav-tab %s" href="%s">%s</a>', $tab === $key ? 'nav-tab-active' : '', esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $key ), admin_url( 'admin.php' ) ) ), esc_html( $label ) );
		}
		echo '</nav>';
		switch ( $tab ) {
			case 'jobs':
				self::render_jobs();
				break;
			case 'sources':
				self::render_sources();
				break;
			case 'setup':
				self::render_setup();
				break;
			default:
				self::render_providers();
		}
		echo '</div>';
	}

	private static function render_providers(): void {
		$levels = array( 'A' => __( 'Account API', 'lnkup-marketplace' ), 'B' => __( 'Public structured data', 'lnkup-marketplace' ), 'C' => __( 'Link, best-effort details', 'lnkup-marketplace' ), 'D' => __( 'File / manual', 'lnkup-marketplace' ) );
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>' . esc_html__( 'On', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Provider', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Level', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'URL import', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Notes', 'lnkup-marketplace' ) . '</th></tr></thead><tbody>';
		foreach ( LNKup_Import_Provider_Registry::all() as $key => $p ) {
			printf(
				'<tr><td><input type="checkbox" name="enabled[]" value="%1$s" %2$s aria-label="%3$s"></td><td><strong>%4$s</strong></td><td>%5$s · %6$s</td><td>%7$s</td><td>%8$s</td></tr>',
				esc_attr( $key ),
				checked( $p['enabled'], true, false ),
				/* translators: %s: provider name */
				esc_attr( sprintf( __( 'Enable %s', 'lnkup-marketplace' ), $p['label'] ) ),
				esc_html( $p['label'] ),
				esc_html( $p['level'] ),
				esc_html( $levels[ $p['level'] ] ?? '' ),
				$p['url_import'] ? esc_html__( 'Yes', 'lnkup-marketplace' ) : esc_html__( 'Link only', 'lnkup-marketplace' ),
				esc_html( (string) $p['limitations'] )
			);
		}
		echo '</tbody></table>';
		submit_button( __( 'Save providers', 'lnkup-marketplace' ) );
		echo '</form>';
	}

	private static function render_jobs(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( 'SELECT * FROM ' . LNKup_Event_Import_Tables::jobs() . ' ORDER BY id DESC LIMIT 50', ARRAY_A );
		echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>#</th><th>' . esc_html__( 'Organizer', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Method', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Status', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Items', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Drafted / linked / failed / skipped', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Created', 'lnkup-marketplace' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No import jobs yet.', 'lnkup-marketplace' ) . '</td></tr>';
		}
		foreach ( (array) $rows as $r ) {
			$user = get_userdata( (int) $r['organizer_id'] );
			printf(
				'<tr><td>%1$d</td><td>%2$s</td><td>%3$s%4$s</td><td>%5$s</td><td>%6$d</td><td>%7$d / %8$d / %9$d / %10$d</td><td>%11$s</td></tr>',
				(int) $r['id'],
				esc_html( $user ? $user->display_name : '#' . (int) $r['organizer_id'] ),
				esc_html( (string) $r['method'] ),
				'' !== (string) $r['file_name'] ? ' · ' . esc_html( (string) $r['file_name'] ) : '',
				esc_html( (string) $r['status'] ),
				(int) $r['total_items'],
				(int) $r['created_count'],
				(int) $r['linked_count'],
				(int) $r['failed_count'],
				(int) $r['skipped_count'],
				esc_html( get_date_from_gmt( (string) $r['created_at'], 'M j, Y g:i a' ) )
			);
		}
		echo '</tbody></table>';
	}

	private static function render_sources(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( 'SELECT * FROM ' . LNKup_Event_Import_Tables::sources() . ' WHERE event_id > 0 ORDER BY updated_at DESC LIMIT 100', ARRAY_A );
		echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>' . esc_html__( 'Event', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Listing', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Mode', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Availability', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Pending changes', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Last checked', 'lnkup-marketplace' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No linked listings yet.', 'lnkup-marketplace' ) . '</td></tr>';
		}
		foreach ( (array) $rows as $row ) {
			$s = LNKup_Event_Sources::format( $row );
			printf(
				'<tr><td><a href="%1$s">%2$s</a></td><td><a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a>%5$s</td><td>%6$s</td><td>%7$s</td><td>%8$d</td><td>%9$s</td></tr>',
				esc_url( (string) get_edit_post_link( $s['event_id'] ) ),
				esc_html( lnkup_plain_title( $s['event_id'] ) ),
				esc_url( $s['url'] ),
				esc_html( $s['label'] ),
				$s['is_primary'] ? ' <span class="description">' . esc_html__( '(primary)', 'lnkup-marketplace' ) . '</span>' : '',
				esc_html( $s['mode'] ),
				esc_html( $s['availability'] ),
				count( (array) ( $s['pending_changes']['changes'] ?? array() ) ),
				esc_html( $s['last_sync_at'] ? mysql2date( 'M j, Y g:i a', get_date_from_gmt( (string) $row['last_sync_at'] ) ) : '—' )
			);
		}
		echo '</tbody></table>';
	}

	private static function render_setup(): void {
		echo '<h2>' . esc_html__( 'Feature flags', 'lnkup-marketplace' ) . '</h2><table class="widefat striped" style="max-width:720px"><tbody>';
		foreach ( LNKup_Event_OS_Flags::all() as $flag => $on ) {
			printf( '<tr><td><code>%s</code></td><td>%s</td></tr>', esc_html( $flag ), $on ? esc_html__( 'On', 'lnkup-marketplace' ) : esc_html__( 'Off', 'lnkup-marketplace' ) );
		}
		echo '</tbody></table>';
		echo '<h2>' . esc_html__( 'Eventbrite account import', 'lnkup-marketplace' ) . '</h2>';
		echo '<p>' . ( LNKup_Eventbrite_Connection::configured()
			? esc_html__( 'App credentials are configured in wp-config.php.', 'lnkup-marketplace' )
			: esc_html__( 'Not configured. Define LNKUP_EVENTBRITE_CLIENT_ID and LNKUP_EVENTBRITE_CLIENT_SECRET in wp-config.php, then turn on the eventbrite_oauth flag. Organizers see "Not connected" until then; URL import keeps working.', 'lnkup-marketplace' ) ) . '</p>';
		echo '<p>' . esc_html__( 'OAuth redirect URI to register with Eventbrite:', 'lnkup-marketplace' ) . ' <code>' . esc_html( LNKup_Eventbrite_Connection::redirect_uri() ) . '</code></p>';
		echo '<p>' . esc_html__( 'Webhook endpoint (registered automatically when listing sync is on):', 'lnkup-marketplace' ) . ' <code>' . esc_html( rest_url( 'lnkup/v1/webhooks/eventbrite' ) ) . '</code></p>';
	}
}
