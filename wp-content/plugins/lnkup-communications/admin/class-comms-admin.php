<?php
/**
 * wp-admin: LNKup → Communications.
 *
 * Server-rendered screens over the same service classes the admin REST uses.
 * Every write goes through admin-post.php with a nonce and capability check.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Admin {

	const SLUG   = 'lnkup-communications';
	const ACTION = 'lnkup_comms_admin';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function can(): bool {
		return current_user_can( 'manage_options' ) || LNKup_Capabilities::user_has_manage_all( get_current_user_id() );
	}

	public static function menu(): void {
		$cap = current_user_can( 'manage_options' ) ? 'manage_options' : 'lnkup_manage_all';
		add_submenu_page( 'lnkup-marketplace', __( 'Communications', 'lnkup-communications' ), __( 'Communications', 'lnkup-communications' ), $cap, self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'lnkup-comms-admin', LNKUP_COMMS_URL . 'admin/css/comms-admin.css', array(), LNKUP_COMMS_VERSION );
	}

	private static function tabs(): array {
		return array(
			'overview'    => __( 'Overview', 'lnkup-communications' ),
			'automations' => __( 'Automated flows', 'lnkup-communications' ),
			'templates'   => __( 'Email & push templates', 'lnkup-communications' ),
			'campaigns'   => __( 'Campaigns', 'lnkup-communications' ),
			'logs'        => __( 'Delivery logs', 'lnkup-communications' ),
			'analytics'   => __( 'Analytics', 'lnkup-communications' ),
			'settings'    => __( 'Settings', 'lnkup-communications' ),
			'health'      => __( 'System health', 'lnkup-communications' ),
		);
	}

	private static function url( string $tab, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	private static function form_open( string $do, string $tab ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( self::ACTION . '_' . $do );
	}

	public static function render(): void {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'lnkup-communications' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'overview';
		$tab  = isset( self::tabs()[ $tab ] ) ? $tab : 'overview';
		echo '<div class="wrap lnkup-comms-admin"><h1>' . esc_html__( 'LNKup Communications', 'lnkup-communications' ) . '</h1>';
		self::notice();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::tabs() as $key => $label ) {
			echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $key ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav><div class="lnkup-comms-admin__body">';
		try {
			call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		} catch ( Throwable $e ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'This section could not be loaded. Check the PHP error log.', 'lnkup-communications' ) . '</p></div>';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup Communications admin tab ' . $tab . ': ' . $e->getMessage() );
		}
		echo '</div></div>';
	}

	private static function notice(): void {
		$notice = get_transient( 'lnkup_comms_notice_' . get_current_user_id() );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( 'lnkup_comms_notice_' . get_current_user_id() );
		echo '<div class="notice notice-' . esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
	}

	private static function flash( string $message, string $type = 'success' ): void {
		set_transient( 'lnkup_comms_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), 60 );
	}

	private static function stat( string $label, $value, string $hint = '' ): void {
		echo '<div class="lnkup-comms-stat"><span class="lnkup-comms-stat__value">' . esc_html( (string) $value ) . '</span><span class="lnkup-comms-stat__label">' . esc_html( $label ) . '</span>';
		if ( '' !== $hint ) {
			echo '<span class="lnkup-comms-stat__hint">' . esc_html( $hint ) . '</span>';
		}
		echo '</div>';
	}

	// ------------------------------------------------------------------ Tabs.

	private static function tab_overview(): void {
		$o     = LNKup_Comms_Analytics::overview( 7 );
		$push  = $o['channels']['push'] ?? array();
		$email = $o['channels']['email'] ?? array();
		echo '<p class="description">' . esc_html__( 'Last 7 days. "Accepted" means the provider accepted the message — it is not proof a phone displayed it or an inbox received it.', 'lnkup-communications' ) . '</p>';
		echo '<div class="lnkup-comms-stats">';
		self::stat( __( 'Queued now', 'lnkup-communications' ), number_format_i18n( $o['queued'] ), sprintf( /* translators: %d: users */ __( '%d users with pending messages', 'lnkup-communications' ), $o['users_pending'] ) );
		self::stat( __( 'Messages sent', 'lnkup-communications' ), number_format_i18n( ( $o['messages']['sent'] ?? 0 ) + ( $o['messages']['partial'] ?? 0 ) ) );
		self::stat( __( 'Push accepted by Expo', 'lnkup-communications' ), number_format_i18n( ( $push['provider_accepted'] ?? 0 ) + ( $push['delivered'] ?? 0 ) ), sprintf( /* translators: %d: count */ __( '%d confirmed by receipts', 'lnkup-communications' ), $push['delivered'] ?? 0 ) );
		self::stat( __( 'Push errors', 'lnkup-communications' ), number_format_i18n( $push['failed'] ?? 0 ) );
		self::stat( __( 'Emails accepted by transport', 'lnkup-communications' ), number_format_i18n( ( $email['provider_accepted'] ?? 0 ) + ( $email['delivered'] ?? 0 ) ), sprintf( /* translators: %d: count */ __( '%d provider-confirmed deliveries', 'lnkup-communications' ), $email['delivered'] ?? 0 ) );
		self::stat( __( 'Email failures', 'lnkup-communications' ), number_format_i18n( ( $email['failed'] ?? 0 ) + ( $email['bounced'] ?? 0 ) ) );
		self::stat( __( 'Suppressed', 'lnkup-communications' ), number_format_i18n( ( $o['messages']['suppressed'] ?? 0 ) + ( $o['messages']['cancelled'] ?? 0 ) ) );
		self::stat( __( 'Opened in app', 'lnkup-communications' ), number_format_i18n( $o['opened'] ), __( 'Inbox reads + push taps', 'lnkup-communications' ) );
		self::stat( __( 'Clicked', 'lnkup-communications' ), number_format_i18n( $o['clicked'] ) );
		self::stat( __( 'Active flows', 'lnkup-communications' ), $o['active_automations'] );
		self::stat( __( 'Failed jobs', 'lnkup-communications' ), $o['failed_jobs'] );
		self::stat( __( 'Unsubscribes', 'lnkup-communications' ), $o['unsubscribes'] );
		echo '</div>';

		echo '<div class="lnkup-comms-cols"><div class="lnkup-comms-panel"><h2>' . esc_html__( 'Top suppression reasons', 'lnkup-communications' ) . '</h2>';
		$reasons = array_merge( $o['message_reasons'], $o['delivery_reasons'] );
		if ( ! $reasons ) {
			echo '<p>' . esc_html__( 'No suppressed messages in this period.', 'lnkup-communications' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $reasons as $row ) {
				echo '<tr><td><code>' . esc_html( (string) $row['reason'] ) . '</code></td><td>' . esc_html( number_format_i18n( (int) $row['c'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div><div class="lnkup-comms-panel"><h2>' . esc_html__( 'Messages handled by this plugin', 'lnkup-communications' ) . '</h2>';
		if ( ! $o['claimed_core_keys'] ) {
			echo '<p>' . esc_html__( 'No flows are enabled. lnkup-marketplace is still sending all of its own notifications. Enable flows one at a time under Settings.', 'lnkup-communications' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'These lnkup-marketplace messages are now delivered by Communications:', 'lnkup-communications' ) . '</p><p>';
			foreach ( $o['claimed_core_keys'] as $key ) {
				echo '<code>' . esc_html( $key ) . '</code> ';
			}
			echo '</p>';
		}
		echo '</div></div>';
	}

	private static function tab_automations(): void {
		$paused = (array) LNKup_Comms_Settings::get( 'paused_automations' );
		echo '<p class="description">' . esc_html__( 'Flows are switched on in Settings. Individual triggers can be paused here; critical messages (event cancellations) cannot be paused.', 'lnkup-communications' ) . '</p>';
		foreach ( LNKup_Comms_Automations::catalog() as $flow ) {
			$on = LNKup_Comms_Settings::enabled( $flow['flag'] );
			echo '<div class="lnkup-comms-panel"><h2>' . esc_html( $flow['label'] ) . ' <span class="lnkup-comms-pill ' . ( $on ? 'is-on' : 'is-off' ) . '">' . esc_html( $on ? __( 'On', 'lnkup-communications' ) : __( 'Off', 'lnkup-communications' ) ) . '</span></h2>';
			echo '<p><strong>' . esc_html__( 'When:', 'lnkup-communications' ) . '</strong> ' . esc_html( $flow['schedule'] ) . ' · <strong>' . esc_html__( 'Who:', 'lnkup-communications' ) . '</strong> ' . esc_html( $flow['audience'] ) . '</p>';
			echo '<p class="description">' . esc_html( $flow['rule'] ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Trigger', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Priority', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Channels', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Recent (7d)', 'lnkup-communications' ) . '</th><th></th></tr></thead><tbody>';
			$recent = self::recent_counts();
			foreach ( $flow['triggers'] as $trigger ) {
				$def = LNKup_Comms_Registry::get( $trigger );
				if ( ! $def ) {
					continue;
				}
				$is_paused = in_array( $trigger, $paused, true );
				echo '<tr><td><code>' . esc_html( $trigger ) . '</code></td><td>' . esc_html( $def['priority'] ) . '</td><td>' . esc_html( implode( ', ', $def['channels'] ) ) . '</td><td>' . esc_html( (string) ( $recent[ $trigger ] ?? 0 ) ) . '</td><td>';
				if ( 'critical' !== $def['priority'] ) {
					self::form_open( $is_paused ? 'resume_trigger' : 'pause_trigger', 'automations' );
					echo '<input type="hidden" name="trigger" value="' . esc_attr( $trigger ) . '">';
					submit_button( $is_paused ? __( 'Resume', 'lnkup-communications' ) : __( 'Pause', 'lnkup-communications' ), 'small', '', false );
					echo ' <a href="' . esc_url( self::url( 'logs', array( 'trigger' => $trigger ) ) ) . '">' . esc_html__( 'Executions', 'lnkup-communications' ) . '</a></form>';
				} else {
					echo esc_html__( 'Protected', 'lnkup-communications' );
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		}

		echo '<div class="lnkup-comms-panel"><h2>' . esc_html__( 'Run a safe test', 'lnkup-communications' ) . '</h2><p class="description">' . esc_html__( 'Sends one real trigger to you (or a configured test account), bypassing its flag. Records must belong to the recipient.', 'lnkup-communications' ) . '</p>';
		self::form_open( 'test_send', 'automations' );
		echo '<p><label>' . esc_html__( 'Trigger', 'lnkup-communications' ) . ' <select name="trigger">';
		foreach ( array_keys( LNKup_Comms_Registry::triggers() ) as $key ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $key ) . '</option>';
		}
		echo '</select></label> <label>' . esc_html__( 'Recipient user ID', 'lnkup-communications' ) . ' <input type="number" name="user_id" value="' . esc_attr( (string) get_current_user_id() ) . '" class="small-text"></label> ';
		echo '<label>' . esc_html__( 'Application ID', 'lnkup-communications' ) . ' <input type="number" name="application_id" class="small-text"></label> <label>' . esc_html__( 'Event ID', 'lnkup-communications' ) . ' <input type="number" name="event_id" class="small-text"></label> <label>' . esc_html__( 'Variant', 'lnkup-communications' ) . ' <input type="text" name="variant" class="small-text"></label></p>';
		submit_button( __( 'Send test', 'lnkup-communications' ), 'secondary', '', false );
		echo '</form></div>';
	}

	private static function recent_counts(): array {
		static $counts = null;
		if ( null === $counts ) {
			global $wpdb;
			$counts = array();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT trigger_key, COUNT(*) c FROM ' . LNKup_Comms_Schema::table( 'messages' ) . ' WHERE created_at >= %s GROUP BY trigger_key', LNKup_Comms_Schema::now( -WEEK_IN_SECONDS ) ), ARRAY_A ) as $row ) {
				$counts[ (string) $row['trigger_key'] ] = (int) $row['c'];
			}
		}
		return $counts;
	}

	private static function tab_templates(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key      = isset( $_GET['template'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['template'] ) ) : '';
		$defaults = LNKup_Comms_Templates::defaults();
		echo '<div class="lnkup-comms-cols"><div class="lnkup-comms-panel lnkup-comms-panel--narrow"><h2>' . esc_html__( 'Templates', 'lnkup-communications' ) . '</h2><ul class="lnkup-comms-list">';
		foreach ( array_keys( $defaults ) as $tpl_key ) {
			$overridden = array() !== LNKup_Comms_Templates::override( $tpl_key );
			echo '<li><a' . ( $tpl_key === $key ? ' class="current"' : '' ) . ' href="' . esc_url( self::url( 'templates', array( 'template' => $tpl_key ) ) ) . '">' . esc_html( $tpl_key ) . '</a>' . ( $overridden ? ' <span class="lnkup-comms-pill is-on">' . esc_html__( 'edited', 'lnkup-communications' ) . '</span>' : '' ) . '</li>';
		}
		echo '</ul></div><div class="lnkup-comms-panel">';
		if ( ! isset( $defaults[ $key ] ) ) {
			echo '<p>' . esc_html__( 'Choose a template to edit and preview it.', 'lnkup-communications' ) . '</p></div></div>';
			return;
		}
		$template = LNKup_Comms_Templates::get( $key );
		echo '<h2>' . esc_html( $key ) . '</h2><p class="description">' . esc_html__( 'Available variables:', 'lnkup-communications' ) . ' ';
		foreach ( LNKup_Comms_Templates::placeholders( $defaults[ $key ] ) as $var ) {
			echo '<code>{{' . esc_html( $var ) . '}}</code> ';
		}
		echo '</p><p class="description">' . esc_html__( 'Plain text only. A paragraph whose optional variable is empty is dropped; a missing required variable stops the send instead of sending a placeholder.', 'lnkup-communications' ) . '</p>';
		self::form_open( 'save_template', 'templates' );
		echo '<input type="hidden" name="template" value="' . esc_attr( $key ) . '"><table class="form-table">';
		foreach ( array( 'subject', 'heading', 'cta', 'push_title', 'push_body', 'inbox_body' ) as $field ) {
			echo '<tr><th><label for="tpl-' . esc_attr( $field ) . '">' . esc_html( $field ) . '</label></th><td><input type="text" class="large-text" id="tpl-' . esc_attr( $field ) . '" name="fields[' . esc_attr( $field ) . ']" value="' . esc_attr( (string) ( $template[ $field ] ?? '' ) ) . '"></td></tr>';
		}
		echo '<tr><th><label for="tpl-body">body</label></th><td><textarea class="large-text" rows="6" id="tpl-body" name="fields[body]">' . esc_textarea( implode( "\n", (array) ( $template['body'] ?? array() ) ) ) . '</textarea><p class="description">' . esc_html__( 'One paragraph per line.', 'lnkup-communications' ) . '</p></td></tr></table>';
		submit_button( __( 'Save template', 'lnkup-communications' ), 'primary', '', false );
		echo '</form>';
		self::form_open( 'reset_template', 'templates' );
		echo '<input type="hidden" name="template" value="' . esc_attr( $key ) . '">';
		submit_button( __( 'Reset to default', 'lnkup-communications' ), 'link-delete', '', false );
		echo '</form>';

		$request = new WP_REST_Request( 'POST' );
		$request->set_url_params( array( 'key' => $key ) );
		$preview = LNKup_Comms_Admin_REST::preview_template( $request );
		if ( is_wp_error( $preview ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $preview->get_error_message() ) . '</p></div>';
		} else {
			$data = $preview->get_data();
			echo '<h3>' . esc_html__( 'Push / inbox preview (sample values)', 'lnkup-communications' ) . '</h3><div class="lnkup-comms-push"><strong>' . esc_html( $data['rendered']['push_title'] ) . '</strong><br>' . esc_html( $data['rendered']['push_body'] ) . '</div>';
			if ( '' !== (string) ( $data['rendered']['inbox_body'] ?? '' ) ) {
				echo '<p class="description">' . esc_html__( 'Inbox text:', 'lnkup-communications' ) . ' ' . esc_html( $data['rendered']['inbox_body'] ) . '</p>';
			}
			echo '<h3>' . esc_html__( 'Email preview (sample values)', 'lnkup-communications' ) . '</h3><iframe class="lnkup-comms-iframe" sandbox="" title="' . esc_attr__( 'Email preview', 'lnkup-communications' ) . '" srcdoc="' . esc_attr( $data['html'] ) . '"></iframe>';
		}
		echo '</div></div>';
	}

	private static function tab_campaigns(): void {
		if ( ! LNKup_Comms_Settings::enabled( 'campaigns_enabled' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Campaigns are disabled. You can draft, preview and test-send; enable "Admin push/email campaigns" in Settings to start sending.', 'lnkup-communications' ) . '</p></div>';
		}
		$preview = get_transient( 'lnkup_comms_campaign_preview_' . get_current_user_id() );
		echo '<div class="lnkup-comms-cols"><div class="lnkup-comms-panel"><h2>' . esc_html__( 'New campaign', 'lnkup-communications' ) . '</h2>';
		self::form_open( 'create_campaign', 'campaigns' );
		$cats = get_terms( array( 'taxonomy' => 'lnkup_vendor_category', 'hide_empty' => false ) );
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Name', 'lnkup-communications' ) . '</th><td><input type="text" class="regular-text" name="name" required></td></tr>';
		echo '<tr><th>' . esc_html__( 'Audience', 'lnkup-communications' ) . '</th><td><select name="filters[role]"><option value="vendor">' . esc_html__( 'Vendors', 'lnkup-communications' ) . '</option><option value="organizer">' . esc_html__( 'Organizers', 'lnkup-communications' ) . '</option></select></td></tr>';
		echo '<tr><th>' . esc_html__( 'City / state', 'lnkup-communications' ) . '</th><td><input type="text" name="filters[city]" placeholder="Atlanta"> <input type="text" name="filters[state]" placeholder="GA" class="small-text"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Vendor category', 'lnkup-communications' ) . '</th><td><select name="filters[category]"><option value="0">' . esc_html__( 'Any', 'lnkup-communications' ) . '</option>';
		foreach ( is_array( $cats ) ? $cats : array() as $term ) {
			echo '<option value="' . esc_attr( (string) $term->term_id ) . '">' . esc_html( $term->name ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Vendor filters', 'lnkup-communications' ) . '</th><td><select name="filters[profile_complete]"><option value="">' . esc_html__( 'Any profile', 'lnkup-communications' ) . '</option><option value="yes">' . esc_html__( 'Profile complete', 'lnkup-communications' ) . '</option><option value="no">' . esc_html__( 'Profile incomplete', 'lnkup-communications' ) . '</option></select> <select name="filters[applied]"><option value="">' . esc_html__( 'Any history', 'lnkup-communications' ) . '</option><option value="yes">' . esc_html__( 'Has applied', 'lnkup-communications' ) . '</option><option value="no">' . esc_html__( 'Never applied', 'lnkup-communications' ) . '</option></select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Organizer filters', 'lnkup-communications' ) . '</th><td><select name="filters[has_published]"><option value="">' . esc_html__( 'Any', 'lnkup-communications' ) . '</option><option value="yes">' . esc_html__( 'Has published events', 'lnkup-communications' ) . '</option><option value="no">' . esc_html__( 'Not published yet', 'lnkup-communications' ) . '</option></select> <label><input type="checkbox" name="filters[onboarding_incomplete]" value="1"> ' . esc_html__( 'Onboarding incomplete', 'lnkup-communications' ) . '</label> <label><input type="checkbox" name="filters[no_upcoming]" value="1"> ' . esc_html__( 'No upcoming events', 'lnkup-communications' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Registered within (days)', 'lnkup-communications' ) . '</th><td><input type="number" class="small-text" name="filters[registered_days]" min="0"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Category', 'lnkup-communications' ) . '</th><td><select name="category"><option value="product_news">' . esc_html__( 'LNKup news & promotions', 'lnkup-communications' ) . '</option><option value="opportunities">' . esc_html__( 'Opportunities (vendors)', 'lnkup-communications' ) . '</option><option value="recruitment">' . esc_html__( 'Recruitment & growth (organizers)', 'lnkup-communications' ) . '</option><option value="onboarding">' . esc_html__( 'Getting started', 'lnkup-communications' ) . '</option></select><p class="description">' . esc_html__( 'Recipients who opted out of this category by email or push are excluded from that channel.', 'lnkup-communications' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Channels', 'lnkup-communications' ) . '</th><td><label><input type="checkbox" checked disabled> ' . esc_html__( 'Inbox', 'lnkup-communications' ) . '</label> <label><input type="checkbox" name="channels[]" value="email" checked> ' . esc_html__( 'Email', 'lnkup-communications' ) . '</label> <label><input type="checkbox" name="channels[]" value="push"> ' . esc_html__( 'Push', 'lnkup-communications' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Subject', 'lnkup-communications' ) . '</th><td><input type="text" class="large-text" name="content[subject]" maxlength="120" required></td></tr>';
		echo '<tr><th>' . esc_html__( 'Message', 'lnkup-communications' ) . '</th><td><textarea class="large-text" rows="5" name="content[body]" maxlength="2000" required></textarea></td></tr>';
		echo '<tr><th>' . esc_html__( 'Push text', 'lnkup-communications' ) . '</th><td><input type="text" class="large-text" name="content[push]" maxlength="178"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Button', 'lnkup-communications' ) . '</th><td><input type="text" name="content[cta]" maxlength="40" placeholder="' . esc_attr__( 'Open LNKup', 'lnkup-communications' ) . '"> <select name="content[link]"><option value="home">' . esc_html__( 'Dashboard', 'lnkup-communications' ) . '</option><option value="vendor_find">' . esc_html__( 'Find events', 'lnkup-communications' ) . '</option><option value="vendor_profile">' . esc_html__( 'Vendor profile', 'lnkup-communications' ) . '</option><option value="org_launch">' . esc_html__( 'Create event', 'lnkup-communications' ) . '</option><option value="org_vendors">' . esc_html__( 'Vendor directory', 'lnkup-communications' ) . '</option></select></td></tr>';
		echo '</table>';
		submit_button( __( 'Save draft', 'lnkup-communications' ), 'primary', '', false );
		echo '</form></div><div class="lnkup-comms-panel"><h2>' . esc_html__( 'Campaigns', 'lnkup-communications' ) . '</h2>';
		$campaigns = LNKup_Comms_Campaigns::list( 'admin', 0, 30 );
		if ( ! $campaigns ) {
			echo '<p>' . esc_html__( 'No campaigns yet.', 'lnkup-communications' ) . '</p>';
		}
		foreach ( $campaigns as $c ) {
			$id = (int) $c['id'];
			echo '<div class="lnkup-comms-campaign"><h3>' . esc_html( (string) $c['name'] ) . ' <span class="lnkup-comms-pill">' . esc_html( (string) $c['status'] ) . '</span></h3>';
			echo '<p>' . esc_html( sprintf( /* translators: 1: role 2: channels 3: queued 4: excluded */ __( '%1$s · %2$s · %3$d queued · %4$d skipped', 'lnkup-communications' ), $c['audience_role'], $c['channels'], (int) $c['total_queued'], (int) $c['total_excluded'] ) ) . ' · <a href="' . esc_url( self::url( 'logs', array( 'campaign' => $id ) ) ) . '">' . esc_html__( 'Delivery log', 'lnkup-communications' ) . '</a></p>';
			if ( is_array( $preview ) && (int) $preview['id'] === $id ) {
				$p = $preview['data'];
				echo '<div class="lnkup-comms-preview"><p><strong>' . esc_html( sprintf( /* translators: %d: count */ __( '%d eligible recipients', 'lnkup-communications' ), (int) $p['recipients'] ) ) . '</strong> · ' . esc_html( sprintf( /* translators: 1: email 2: push */ __( '%1$d reachable by email · %2$d by push', 'lnkup-communications' ), (int) $p['email'], (int) $p['push'] ) ) . '</p><ul>';
				foreach ( (array) $p['excluded'] as $reason => $count ) {
					echo '<li>' . esc_html( $reason . ': ' . $count ) . '</li>';
				}
				echo '</ul>';
				if ( ! empty( $p['sample'] ) ) {
					echo '<p class="description">' . esc_html__( 'Sample:', 'lnkup-communications' ) . ' ' . esc_html( implode( ', ', array_filter( (array) $p['sample'] ) ) ) . '</p>';
				}
				if ( ! empty( $p['capped'] ) ) {
					echo '<p class="description">' . esc_html__( 'Preview evaluated the first 20,000 accounts.', 'lnkup-communications' ) . '</p>';
				}
				echo '</div>';
			}
			echo '<div class="lnkup-comms-actions">';
			foreach ( array( 'preview' => __( 'Preview audience', 'lnkup-communications' ), 'test' => __( 'Send test to me & test accounts', 'lnkup-communications' ) ) as $do => $label ) {
				self::form_open( 'campaign_' . $do, 'campaigns' );
				echo '<input type="hidden" name="campaign" value="' . esc_attr( (string) $id ) . '">';
				submit_button( $label, 'secondary small', '', false );
				echo '</form>';
			}
			if ( in_array( $c['status'], array( 'draft', 'paused' ), true ) ) {
				self::form_open( 'campaign_start', 'campaigns' );
				echo '<input type="hidden" name="campaign" value="' . esc_attr( (string) $id ) . '">';
				echo '<label>' . esc_html__( 'Confirm recipient count', 'lnkup-communications' ) . ' <input type="number" name="confirm_count" class="small-text"></label> ';
				submit_button( 'paused' === $c['status'] ? __( 'Resume', 'lnkup-communications' ) : __( 'Start sending', 'lnkup-communications' ), 'primary small', '', false );
				echo '</form>';
			}
			if ( 'running' === $c['status'] ) {
				self::form_open( 'campaign_pause', 'campaigns' );
				echo '<input type="hidden" name="campaign" value="' . esc_attr( (string) $id ) . '">';
				submit_button( __( 'Pause', 'lnkup-communications' ), 'secondary small', '', false );
				echo '</form>';
			}
			if ( in_array( $c['status'], array( 'draft', 'paused', 'running' ), true ) ) {
				self::form_open( 'campaign_cancel', 'campaigns' );
				echo '<input type="hidden" name="campaign" value="' . esc_attr( (string) $id ) . '">';
				submit_button( __( 'Cancel', 'lnkup-communications' ), 'link-delete small', '', false );
				echo '</form>';
			}
			echo '</div></div>';
		}
		echo '</div></div>';
	}

	private static function tab_logs(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$params         = array_map( 'sanitize_text_field', array_filter( wp_unslash( (array) $_GET ), 'is_scalar' ) );
		$params['page'] = max( 1, absint( $params['paged_logs'] ?? 1 ) );
		$data           = LNKup_Comms_Admin_REST::query_logs( $params );
		echo '<form method="get" class="lnkup-comms-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="logs">';
		foreach ( array( 'trigger' => __( 'Trigger', 'lnkup-communications' ), 'status' => __( 'Status', 'lnkup-communications' ), 'role' => __( 'Role', 'lnkup-communications' ), 'user' => __( 'User ID', 'lnkup-communications' ), 'event' => __( 'Event ID', 'lnkup-communications' ), 'application' => __( 'Application ID', 'lnkup-communications' ), 'campaign' => __( 'Campaign ID', 'lnkup-communications' ) ) as $key => $label ) {
			echo '<label>' . esc_html( $label ) . ' <input type="text" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) ( $params[ $key ] ?? '' ) ) . '" class="small-text"></label> ';
		}
		submit_button( __( 'Filter', 'lnkup-communications' ), 'secondary', '', false );
		echo '</form><p class="description">' . esc_html__( 'Message bodies are not stored. Read/open/click are shown only when observed (inbox read, push tap, tracked link).', 'lnkup-communications' ) . '</p>';
		echo '<table class="widefat striped lnkup-comms-log"><thead><tr><th>#</th><th>' . esc_html__( 'Recipient', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Trigger', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Context', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Title', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Status', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Channels', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Scheduled / sent (UTC)', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Engagement', 'lnkup-communications' ) . '</th></tr></thead><tbody>';
		if ( ! $data['items'] ) {
			echo '<tr><td colspan="9">' . esc_html__( 'No messages match these filters.', 'lnkup-communications' ) . '</td></tr>';
		}
		foreach ( $data['items'] as $row ) {
			$channels = array();
			foreach ( $row['deliveries'] as $d ) {
				$channels[] = $d['channel'] . ': ' . $d['status'] . ( '' !== $d['suppression_reason'] ? ' (' . $d['suppression_reason'] . ')' : '' ) . ( '' !== $d['last_error'] ? ' — ' . $d['last_error'] : '' ) . ( (int) $d['attempts'] > 1 ? ' ×' . (int) $d['attempts'] : '' );
			}
			$context = trim( ( $row['event_name'] ? $row['event_name'] : '' ) . ( (int) $row['application_id'] ? ' · app #' . (int) $row['application_id'] : '' ) . ( (int) $row['campaign_id'] ? ' · campaign #' . (int) $row['campaign_id'] : '' ), ' ·' );
			$engaged = array_filter( array( $row['read_at'] ? __( 'read', 'lnkup-communications' ) : '', $row['opened_at'] ? __( 'opened', 'lnkup-communications' ) : '', $row['clicked_at'] ? __( 'clicked', 'lnkup-communications' ) : '' ) );
			echo '<tr><td>' . esc_html( (string) $row['id'] ) . '</td><td>' . esc_html( $row['recipient'] . ' (' . $row['recipient_role'] . ')' ) . '</td><td><code>' . esc_html( (string) $row['template_key'] ) . '</code><br><small>' . esc_html( $row['priority'] . ' · ' . $row['category'] ) . '</small></td><td>' . esc_html( $context ) . '</td><td>' . esc_html( (string) $row['title'] ) . '</td><td>' . esc_html( (string) $row['status'] ) . ( '' !== $row['suppression_reason'] ? '<br><small>' . esc_html( (string) $row['suppression_reason'] ) . '</small>' : '' ) . '</td><td><small>' . esc_html( implode( ' | ', $channels ) ) . '</small></td><td><small>' . esc_html( (string) $row['scheduled_at'] ) . '<br>' . esc_html( (string) $row['sent_at'] ) . '</small></td><td>' . esc_html( implode( ', ', $engaged ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $data['total'] / $data['perPage'] );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( (string) paginate_links( array( 'base' => add_query_arg( 'paged_logs', '%#%' ), 'format' => '', 'current' => $data['page'], 'total' => $pages ) ) ) . '</div></div>';
		}
	}

	private static function tab_analytics(): void {
		$days   = 30;
		$report = LNKup_Comms_Analytics::trigger_report( $days );
		$funnel = LNKup_Comms_Analytics::marketplace_funnel( $days );
		echo '<p class="description">' . esc_html( sprintf( /* translators: %d: days */ __( 'Last 30 days. "Action completed" means the target action happened within %d days of the message. This is correlation, not proof that the message caused it.', 'lnkup-communications' ), LNKup_Comms_Settings::int( 'attribution_days' ) ) ) . '</p>';
		echo '<h2>' . esc_html__( 'Marketplace activation (new accounts, real records)', 'lnkup-communications' ) . '</h2><div class="lnkup-comms-stats">';
		self::stat( __( 'New vendors', 'lnkup-communications' ), $funnel['new_vendors'] );
		self::stat( __( 'Profile complete', 'lnkup-communications' ), $funnel['vendors_profile_complete'] );
		self::stat( __( 'Applied', 'lnkup-communications' ), $funnel['vendors_applied'] );
		self::stat( __( 'New organizers', 'lnkup-communications' ), $funnel['new_organizers'] );
		self::stat( __( 'Created an event', 'lnkup-communications' ), $funnel['organizers_created_event'] );
		self::stat( __( 'Published', 'lnkup-communications' ), $funnel['organizers_published'] );
		echo '</div><h2>' . esc_html__( 'By flow and trigger', 'lnkup-communications' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Flow', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Trigger', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Created', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Sent', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Suppressed', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Cancelled', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Failed', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Opened', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Clicked', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Action completed', 'lnkup-communications' ) . '</th></tr></thead><tbody>';
		if ( ! $report ) {
			echo '<tr><td colspan="10">' . esc_html__( 'No messages in this period.', 'lnkup-communications' ) . '</td></tr>';
		}
		foreach ( $report as $row ) {
			echo '<tr><td>' . esc_html( (string) $row['flow'] ) . '</td><td><code>' . esc_html( (string) $row['trigger_key'] ) . '</code></td>';
			foreach ( array( 'created', 'sent', 'suppressed', 'cancelled', 'failed', 'opened', 'clicked' ) as $col ) {
				echo '<td>' . esc_html( number_format_i18n( (int) $row[ $col ] ) ) . '</td>';
			}
			echo '<td>' . ( null === $row['actioned'] ? '—' : esc_html( number_format_i18n( (int) $row['actioned'] ) ) . '<br><small>' . esc_html( (string) $row['action_label'] ) . '</small>' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function tab_settings(): void {
		$s = LNKup_Comms_Settings::all();
		self::form_open( 'save_settings', 'settings' );
		echo '<h2>' . esc_html__( 'Feature flags', 'lnkup-communications' ) . '</h2><p class="description">' . esc_html__( 'Turn on one flow at a time and watch Delivery logs / System health. While a flow is off, lnkup-marketplace keeps sending its own messages for it.', 'lnkup-communications' ) . '</p><table class="form-table">';
		foreach ( LNKup_Comms_Settings::flags() as $key => $label ) {
			echo '<tr><th>' . esc_html( $key ) . '</th><td><label><input type="checkbox" name="s[' . esc_attr( $key ) . ']" value="1"' . checked( ! empty( $s[ $key ] ), true, false ) . '> ' . esc_html( $label ) . '</label></td></tr>';
		}
		echo '</table><h2>' . esc_html__( 'Delivery', 'lnkup-communications' ) . '</h2><table class="form-table">';
		$bools = array(
			'delivery_paused'  => __( 'Pause ALL deliveries (messages stay queued)', 'lnkup-communications' ),
			'email_channel'    => __( 'Email channel enabled', 'lnkup-communications' ),
			'push_channel'     => __( 'Push channel enabled', 'lnkup-communications' ),
			'android_channels' => __( 'Use Android channels (transactional / operations / opportunities) — enable only after the app build that creates them is live', 'lnkup-communications' ),
		);
		foreach ( $bools as $key => $label ) {
			echo '<tr><th>' . esc_html( $key ) . '</th><td><label><input type="checkbox" name="s[' . esc_attr( $key ) . ']" value="1"' . checked( ! empty( $s[ $key ] ), true, false ) . '> ' . esc_html( $label ) . '</label></td></tr>';
		}
		$ints = array(
			'cap_promo_push_daily'             => __( 'Max promotional pushes per user per day', 'lnkup-communications' ),
			'cap_promo_push_weekly'            => __( 'Max promotional pushes per user per week', 'lnkup-communications' ),
			'cap_promo_email_weekly'           => __( 'Max promotional emails per user per week', 'lnkup-communications' ),
			'cap_category_push_weekly'         => __( 'Max promotional pushes per category per user per week (0 = no per-category cap)', 'lnkup-communications' ),
			'cap_category_email_weekly'        => __( 'Max promotional emails per category per user per week (0 = no per-category cap)', 'lnkup-communications' ),
			'cooldown_hours'                   => __( 'Cross-channel cooldown: hold promotional email/push for (hours) after another important or promotional message (0 = off)', 'lnkup-communications' ),
			'holdout_percent'                  => __( 'Measurement holdout: % of users who never receive the selected engagement triggers (0–50)', 'lnkup-communications' ),
			'burst_window_minutes'             => __( 'Group organizer application alerts within (minutes)', 'lnkup-communications' ),
			'pending_review_hours'             => __( 'Pending-review reminder after (hours)', 'lnkup-communications' ),
			'digest_hour'                      => __( 'Digest hour (site time, 0–23)', 'lnkup-communications' ),
			'campaign_confirm_threshold'       => __( 'Require count confirmation for campaigns of at least', 'lnkup-communications' ),
			'campaign_batch_size'              => __( 'Campaign batch size', 'lnkup-communications' ),
			'organizer_broadcast_daily_limit'  => __( 'Organizer messages per event per day', 'lnkup-communications' ),
			'organizer_broadcast_min_interval' => __( 'Minutes between organizer messages', 'lnkup-communications' ),
			'retention_days'                   => __( 'Keep message history (days)', 'lnkup-communications' ),
			'attribution_days'                 => __( 'Attribution window (days)', 'lnkup-communications' ),
		);
		foreach ( $ints as $key => $label ) {
			echo '<tr><th><label for="s-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="number" min="0" class="small-text" id="s-' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $s[ $key ] ) . '"></td></tr>';
		}
		echo '<tr><th>' . esc_html__( 'Holdout triggers', 'lnkup-communications' ) . '</th><td><input type="hidden" name="s[holdout_triggers][]" value=""><div class="lnkup-comms-checklist">';
		foreach ( LNKup_Comms_Registry::triggers() as $trigger_key => $def ) {
			if ( ! in_array( (string) $def['category'], LNKup_Comms_Settings::HOLDOUT_CATEGORIES, true ) || ! in_array( (string) $def['priority'], array( 'normal', 'promotional' ), true ) ) {
				continue;
			}
			echo '<label><input type="checkbox" name="s[holdout_triggers][]" value="' . esc_attr( $trigger_key ) . '"' . checked( in_array( $trigger_key, (array) $s['holdout_triggers'], true ), true, false ) . '> <code>' . esc_html( $trigger_key ) . '</code></label> ';
		}
		echo '</div><p class="description">' . esc_html__( 'Held-out users are recorded as "holdout" so Analytics can compare their outcomes with users who received the message. Only engagement categories can be held out.', 'lnkup-communications' ) . '</p></td></tr>';
		echo '<tr><th><label for="s-test">' . esc_html__( 'Internal test account IDs', 'lnkup-communications' ) . '</label></th><td><input type="text" class="regular-text" id="s-test" name="s[test_user_ids]" value="' . esc_attr( (string) $s['test_user_ids'] ) . '"><p class="description">' . esc_html__( 'Comma-separated user IDs allowed to receive test sends.', 'lnkup-communications' ) . '</p></td></tr>';
		echo '<tr><th><label for="s-address">' . esc_html__( 'Sender postal address', 'lnkup-communications' ) . '</label></th><td><input type="text" class="large-text" id="s-address" name="s[company_address]" value="' . esc_attr( (string) $s['company_address'] ) . '"></td></tr>';
		echo '<tr><th><label for="s-provider">' . esc_html__( 'Email provider (webhooks)', 'lnkup-communications' ) . '</label></th><td><select id="s-provider" name="s[email_provider]">';
		foreach ( array( '' => __( 'None', 'lnkup-communications' ), 'postmark' => 'Postmark', 'sendgrid' => 'SendGrid', 'mailgun' => 'Mailgun', 'ses' => 'Amazon SES (SNS)', 'generic' => __( 'Generic JSON', 'lnkup-communications' ) ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $s['email_provider'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr><tr><th><label for="s-secret">' . esc_html__( 'Webhook secret', 'lnkup-communications' ) . '</label></th><td><input type="password" class="regular-text" id="s-secret" name="s[email_webhook_secret]" value="" autocomplete="new-password" placeholder="' . esc_attr( '' !== (string) $s['email_webhook_secret'] ? __( '(saved — leave blank to keep)', 'lnkup-communications' ) : '' ) . '">';
		if ( '' !== (string) $s['email_provider'] ) {
			echo '<p class="description">' . esc_html__( 'Webhook URL:', 'lnkup-communications' ) . ' <code>' . esc_html( rest_url( 'lnkup/v1/comms/email-webhook/' . $s['email_provider'] ) ) . '?key=…</code></p>';
		}
		echo '</td></tr></table>';
		submit_button( __( 'Save settings', 'lnkup-communications' ) );
		echo '</form>';
	}

	private static function tab_health(): void {
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Check', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Status', 'lnkup-communications' ) . '</th><th>' . esc_html__( 'Detail', 'lnkup-communications' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( LNKup_Comms_Health::checks() as $check ) {
			echo '<tr><td>' . esc_html( $check['label'] ) . '</td><td><span class="lnkup-comms-pill is-' . esc_attr( $check['status'] ) . '">' . esc_html( $check['status'] ) . '</span></td><td>' . esc_html( $check['detail'] ) . '</td><td>';
			if ( '' !== $check['action'] ) {
				self::form_open( 'health_action', 'health' );
				echo '<input type="hidden" name="health_action" value="' . esc_attr( $check['action'] ) . '">';
				$labels = array( 'sweep' => __( 'Wake worker', 'lnkup-communications' ), 'retry_failed' => __( 'Retry failed', 'lnkup-communications' ), 'cancel_stale' => __( 'Cancel obsolete', 'lnkup-communications' ) );
				submit_button( $labels[ $check['action'] ] ?? $check['action'], 'secondary small', '', false );
				echo '</form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Background jobs: Tools → Scheduled Actions (group "lnkup-comms").', 'lnkup-communications' ) . '</p>';
	}

	// ------------------------------------------------------------------ Writes.

	public static function handle(): void {
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( (string) $_POST['do'] ) ) : '';
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['tab'] ) ) : 'overview';
		if ( ! self::can() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'lnkup-communications' ), 403 );
		}
		check_admin_referer( self::ACTION . '_' . $do );
		$user_id  = get_current_user_id();
		$redirect = self::url( $tab );

		switch ( $do ) {
			case 'save_settings':
				$input = isset( $_POST['s'] ) ? wp_unslash( (array) $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in LNKup_Comms_Settings::update().
				foreach ( array_merge( array_keys( LNKup_Comms_Settings::flags() ), array( 'delivery_paused', 'email_channel', 'push_channel', 'android_channels' ) ) as $bool ) {
					$input[ $bool ] = ! empty( $input[ $bool ] );
				}
				if ( isset( $input['email_webhook_secret'] ) && '' === $input['email_webhook_secret'] ) {
					unset( $input['email_webhook_secret'] );
				}
				LNKup_Comms_Settings::update( $input );
				self::flash( __( 'Settings saved.', 'lnkup-communications' ) );
				break;
			case 'pause_trigger':
			case 'resume_trigger':
				$request = new WP_REST_Request( 'POST' );
				$request->set_url_params( array( 'key' => sanitize_key( wp_unslash( (string) ( $_POST['trigger'] ?? '' ) ) ), 'action' => 'pause_trigger' === $do ? 'pause' : 'resume' ) );
				$result = LNKup_Comms_Admin_REST::automation_action( $request );
				self::flash( is_wp_error( $result ) ? $result->get_error_message() : __( 'Updated.', 'lnkup-communications' ), is_wp_error( $result ) ? 'error' : 'success' );
				break;
			case 'test_send':
				$request = new WP_REST_Request( 'POST' );
				$request->set_body_params( array_map( 'sanitize_text_field', wp_unslash( array_intersect_key( (array) $_POST, array_flip( array( 'trigger', 'user_id', 'application_id', 'event_id', 'variant' ) ) ) ) ) );
				$result = LNKup_Comms_Admin_REST::test_send( $request );
				self::flash( is_wp_error( $result ) ? $result->get_error_message() : __( 'Test message queued. See Delivery logs.', 'lnkup-communications' ), is_wp_error( $result ) ? 'error' : 'success' );
				break;
			case 'save_template':
				$key    = sanitize_text_field( wp_unslash( (string) ( $_POST['template'] ?? '' ) ) );
				$fields = isset( $_POST['fields'] ) ? wp_unslash( (array) $_POST['fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in save_override().
				$result = LNKup_Comms_Templates::save_override( $key, $fields, $user_id );
				self::flash( is_wp_error( $result ) ? $result->get_error_message() : __( 'Template saved.', 'lnkup-communications' ), is_wp_error( $result ) ? 'error' : 'success' );
				$redirect = self::url( 'templates', array( 'template' => $key ) );
				break;
			case 'reset_template':
				$key = sanitize_text_field( wp_unslash( (string) ( $_POST['template'] ?? '' ) ) );
				LNKup_Comms_Templates::reset_override( $key );
				self::flash( __( 'Template reset to default.', 'lnkup-communications' ) );
				$redirect = self::url( 'templates', array( 'template' => $key ) );
				break;
			case 'create_campaign':
				$result = LNKup_Comms_Campaigns::create_admin(
					array(
						'name'     => sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ),
						'filters'  => isset( $_POST['filters'] ) ? wp_unslash( (array) $_POST['filters'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in clean_filters().
						'content'  => isset( $_POST['content'] ) ? wp_unslash( (array) $_POST['content'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in clean_content().
						'category' => sanitize_key( wp_unslash( (string) ( $_POST['category'] ?? '' ) ) ),
						'channels' => array_merge( array( 'inbox' ), array_map( 'sanitize_key', wp_unslash( (array) ( $_POST['channels'] ?? array() ) ) ) ),
					),
					$user_id
				);
				self::flash( is_wp_error( $result ) ? $result->get_error_message() : __( 'Draft saved. Preview the audience before sending.', 'lnkup-communications' ), is_wp_error( $result ) ? 'error' : 'success' );
				break;
			case 'campaign_preview':
				$id      = absint( $_POST['campaign'] ?? 0 );
				$preview = LNKup_Comms_Campaigns::preview( $id );
				if ( $preview ) {
					set_transient( 'lnkup_comms_campaign_preview_' . $user_id, array( 'id' => $id, 'data' => $preview ), 10 * MINUTE_IN_SECONDS );
				}
				break;
			case 'campaign_test':
				$result = LNKup_Comms_Campaigns::test_send( absint( $_POST['campaign'] ?? 0 ), $user_id );
				/* translators: %d: count */
				self::flash( is_wp_error( $result ) ? $result->get_error_message() : sprintf( __( 'Test queued for %d account(s).', 'lnkup-communications' ), (int) $result ), is_wp_error( $result ) ? 'error' : 'success' );
				break;
			case 'campaign_start':
				$result = LNKup_Comms_Campaigns::start( absint( $_POST['campaign'] ?? 0 ), absint( $_POST['confirm_count'] ?? 0 ) );
				self::flash( is_wp_error( $result ) ? $result->get_error_message() : __( 'Campaign started. Sending runs in the background.', 'lnkup-communications' ), is_wp_error( $result ) ? 'error' : 'success' );
				break;
			case 'campaign_pause':
				LNKup_Comms_Campaigns::pause( absint( $_POST['campaign'] ?? 0 ) );
				self::flash( __( 'Campaign paused.', 'lnkup-communications' ) );
				break;
			case 'campaign_cancel':
				LNKup_Comms_Campaigns::cancel( absint( $_POST['campaign'] ?? 0 ) );
				self::flash( __( 'Campaign cancelled. Unsent messages were cancelled.', 'lnkup-communications' ) );
				break;
			case 'health_action':
				$request = new WP_REST_Request( 'POST' );
				$request->set_body_params( array( 'action' => sanitize_key( wp_unslash( (string) ( $_POST['health_action'] ?? '' ) ) ) ) );
				$result = LNKup_Comms_Admin_REST::health_action( $request );
				self::flash( is_wp_error( $result ) ? $result->get_error_message() : __( 'Done.', 'lnkup-communications' ), is_wp_error( $result ) ? 'error' : 'success' );
				break;
		}
		wp_safe_redirect( $redirect );
		exit;
	}
}
