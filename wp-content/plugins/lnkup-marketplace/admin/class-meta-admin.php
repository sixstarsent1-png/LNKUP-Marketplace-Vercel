<?php
/**
 * wp-admin → LNKup → Meta Integration.
 *
 * Health overview, per-event delivery and matching coverage, Dataset Quality trends,
 * event debugger, alerts and non-secret settings. Shows parameter *presence* only, never
 * personal values, and never shows access tokens (masked status only).
 *
 * Every number is labeled as Official Meta, LNKup internal, or Unavailable.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Meta_Admin {

	const SLUG   = 'lnkup-meta-health';
	const CAP    = 'lnkup_manage_settings';
	const ACTION = 'lnkup_meta_action';
	const CHECK_OPTION = 'lnkup_meta_credential_check';

	const PRIORITY_EVENTS = array( 'CompleteRegistration', 'OrganizerRegistered', 'VendorRegistered', 'OrganizerActivated', 'VendorActivated', 'EventPublished', 'SubmitApplication', 'InitiateCheckout', 'Purchase', 'FirstVendorBooking' );

	const REASONS = array(
		'internal_only'       => 'Internal only (not sent to Meta)',
		'excluded_user'       => 'Staff / excluded test account',
		'consent_denied'      => 'Visitor opted out (GPC or opt-out)',
		'app_events_disabled' => 'Native app event (app events off)',
		'capi_disabled'       => 'Conversions API turned off',
		'token_missing'       => 'No access token configured',
		'dataset_missing'     => 'No dataset ID',
		'test_code_missing'   => 'Test mode without a test event code',
		'non_production'      => 'Not the production site',
		'missing_user_agent'  => 'No browser user agent (Meta requires it)',
		'event_too_old'       => 'Older than 7 days',
	);

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'lnkup-marketplace', __( 'Meta Integration', 'lnkup-marketplace' ), __( 'Meta Integration', 'lnkup-marketplace' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	private static function can(): bool {
		return current_user_can( self::CAP ) || LNKup_Capabilities::user_has_manage_all();
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * admin-post handler for every action on the page.
	 */
	public static function handle(): void {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$op     = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		$tab    = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['tab'] ) ) : 'overview';
		$notice = '';

		switch ( $op ) {
			case 'save_settings':
				$input = isset( $_POST['meta'] ) && is_array( $_POST['meta'] ) ? wp_unslash( $_POST['meta'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per key in LNKup_Meta_Config::save().
				LNKup_Meta_Config::save( $input );
				$notice = 'saved';
				break;
			case 'check_credentials':
				self::check_credentials();
				$notice = 'checked';
				break;
			case 'test_event':
				$notice = self::send_test_event();
				break;
			case 'retry_failed':
				$notice = 'retried_' . LNKup_Meta_Dispatcher::retry_failed();
				break;
			case 'dispatch_now':
				LNKup_Meta_Dispatcher::run();
				$notice = 'dispatched';
				break;
			case 'sync_dq':
				$status = LNKup_Meta_Dataset_Quality::sync( true );
				$notice = ! empty( $status['notice'] ) ? 'dq_rate_limited' : ( ! empty( $status['ok'] ) ? 'dq_ok' : 'dq_failed' );
				break;
			case 'evaluate_alerts':
				LNKup_Meta_Alerts::evaluate();
				$notice = 'alerts_evaluated';
				break;
			case 'alert_status':
				LNKup_Meta_Alerts::set_status( isset( $_POST['alert_id'] ) ? absint( $_POST['alert_id'] ) : 0, isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : '' );
				$notice = 'alert_updated';
				break;
		}
		wp_safe_redirect( self::url( array( 'tab' => $tab, 'notice' => $notice ) ) );
		exit;
	}

	/**
	 * Probe what each configured token can actually reach. Results are sanitized summaries.
	 */
	public static function check_credentials(): array {
		$capi    = LNKup_Meta_Config::capi_token();
		$dq      = LNKup_Meta_Config::dataset_quality_token();
		$ads     = LNKup_Meta_Config::marketing_api_token();
		$dataset = LNKup_Meta_Config::dataset_id();
		$result  = array( 'checked_at' => time() );

		$probe = static function ( array $res, callable $ok_summary ): array {
			if ( ! empty( $res['ok'] ) ) {
				return array( 'ok' => true, 'summary' => (string) $ok_summary( (array) $res['body'] ) );
			}
			return array( 'ok' => false, 'summary' => trim( (string) $res['error_code'] . ' ' . LNKup_Meta_Redactor::redact( (string) $res['error_message'] ) ) );
		};

		$result['dataset'] = '' === $capi ? array( 'ok' => false, 'summary' => 'No CAPI token' ) : $probe(
			LNKup_Meta_Capi_Client::get( rawurlencode( $dataset ), array( 'fields' => 'id,name' ), $capi ),
			static function ( array $b ): string {
				return sprintf( 'Dataset %s "%s" is reachable with the CAPI token.', (string) ( $b['id'] ?? '' ), sanitize_text_field( (string) ( $b['name'] ?? '' ) ) );
			}
		);

		$result['dataset_quality'] = '' === $dq ? array( 'ok' => false, 'summary' => 'No token' ) : $probe(
			LNKup_Meta_Capi_Client::get( 'dataset_quality', array( 'dataset_id' => $dataset, 'fields' => 'web{event_name}' ), $dq ),
			static function ( array $b ): string {
				return sprintf( 'Dataset Quality API readable (%d events reported).', is_array( $b['web'] ?? null ) ? count( $b['web'] ) : 0 );
			}
		);

		$result['permissions'] = '' === $capi ? array( 'ok' => false, 'summary' => 'No CAPI token' ) : $probe(
			LNKup_Meta_Capi_Client::get( 'me/permissions', array(), $capi ),
			static function ( array $b ): string {
				$granted = array();
				foreach ( (array) ( $b['data'] ?? array() ) as $perm ) {
					if ( is_array( $perm ) && 'granted' === ( $perm['status'] ?? '' ) ) {
						$granted[] = sanitize_key( (string) $perm['permission'] );
					}
				}
				return array() === $granted ? 'Token reports no app permissions (normal for Events Manager tokens).' : 'Granted: ' . implode( ', ', $granted );
			}
		);

		$ad_token = '' !== $ads ? $ads : $capi;
		$result['ad_account'] = '' === $ad_token ? array( 'ok' => false, 'summary' => 'No token' ) : $probe(
			LNKup_Meta_Capi_Client::get( LNKup_Meta_Config::ad_account_id(), array( 'fields' => 'id,name,currency,timezone_name,account_status' ), $ad_token ),
			static function ( array $b ): string {
				return sprintf( 'Ad account %s readable (currency %s, timezone %s).', (string) ( $b['id'] ?? '' ), sanitize_text_field( (string) ( $b['currency'] ?? '?' ) ), sanitize_text_field( (string) ( $b['timezone_name'] ?? '?' ) ) );
			}
		);
		$result['ad_account']['token'] = '' !== $ads ? 'META_MARKETING_API_ACCESS_TOKEN' : 'META_CAPI_ACCESS_TOKEN';

		// Events Manager tokens are scoped to sending events and reading quality data; they usually
		// cannot read the dataset node or the ad account. Those probes are informational only.
		$result['dataset']['optional']    = true;
		$result['dataset']['note']        = 'Events Manager tokens often cannot read the dataset record. This does not affect sending; confirm sending with "Send test event".';
		$result['ad_account']['optional'] = true;
		$result['ad_account']['note']     = 'Only needed for Marketing API spend reporting (later phase). Requires a system-user token with ads_read assigned to the ad account.';

		update_option( self::CHECK_OPTION, $result, false );
		return $result;
	}

	/**
	 * One PageView with the admin's own browser context, sent with the configured test code.
	 * It appears only in Events Manager → Test events and never affects optimization or reporting.
	 */
	private static function send_test_event(): string {
		$code = LNKup_Meta_Config::test_event_code();
		if ( '' === $code ) {
			return 'test_code_missing';
		}
		$event = array(
			'event_name'       => 'PageView',
			'event_time'       => time(),
			'event_id'         => LNKup_Conversion_Registry::uuid(),
			'action_source'    => 'website',
			'event_source_url' => home_url( '/' ),
			'user_data'        => LNKup_Meta_User_Data::build( get_current_user_id(), LNKup_Meta_Cookies::request_context() ),
		);
		$started = microtime( true );
		$result  = LNKup_Meta_Capi_Client::send_events( array( $event ), $code );
		LNKup_Meta_Dispatcher::log_delivery( 'capi_test', 1, $result, true, (int) round( ( microtime( true ) - $started ) * 1000 ) );
		update_option(
			'lnkup_meta_last_test',
			array(
				'at'       => time(),
				'ok'       => ! empty( $result['ok'] ),
				'received' => (int) $result['events_received'],
				'trace'    => (string) $result['fbtrace_id'],
				'error'    => trim( (string) $result['error_code'] . ' ' . (string) $result['error_message'] ),
			),
			false
		);
		return ! empty( $result['ok'] ) ? 'test_ok' : 'test_failed';
	}

	/* ------------------------------------------------------------------ rendering */

	public static function render(): void {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$tabs = array(
			'overview' => __( 'Overview', 'lnkup-marketplace' ),
			'events'   => __( 'Conversion events', 'lnkup-marketplace' ),
			'quality'  => __( 'Dataset Quality & trends', 'lnkup-marketplace' ),
			'debugger' => __( 'Event debugger', 'lnkup-marketplace' ),
			'alerts'   => __( 'Alerts', 'lnkup-marketplace' ),
			'settings' => __( 'Configuration', 'lnkup-marketplace' ),
		);
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'overview';
		?>
		<div class="wrap lnkup-meta-admin">
			<h1><?php esc_html_e( 'Meta Integration', 'lnkup-marketplace' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Badges: Official Meta = returned by Meta APIs. LNKup internal = counted from LNKup records. Unavailable = not provided; never estimated.', 'lnkup-marketplace' ); ?></p>
			<?php self::render_notice(); ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( array( 'tab' => $key ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<style>
				.lnkup-meta-admin .lnkup-badge{display:inline-block;padding:1px 7px;border-radius:10px;font-size:11px;font-weight:600;margin-left:4px}
				.lnkup-meta-admin .b-meta{background:#e7f0ff;color:#1d4ed8}.lnkup-meta-admin .b-int{background:#ecfccb;color:#3f6212}.lnkup-meta-admin .b-na{background:#f1f1f1;color:#666}
				.lnkup-meta-admin .ok{color:#15803d;font-weight:600}.lnkup-meta-admin .bad{color:#b91c1c;font-weight:600}.lnkup-meta-admin .warn{color:#a16207;font-weight:600}
				.lnkup-meta-admin .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-top:12px}
				.lnkup-meta-admin .card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 16px;max-width:none;margin:0}
				.lnkup-meta-admin td.num,.lnkup-meta-admin th.num{text-align:right}
				.lnkup-meta-admin .chip{display:inline-block;font-size:11px;padding:0 5px;margin:1px;border-radius:4px;background:#f0f0f1}.lnkup-meta-admin .chip.on{background:#dcfce7}
			</style>
			<?php
			switch ( $tab ) {
				case 'events':
					self::render_events();
					break;
				case 'quality':
					self::render_quality();
					break;
				case 'debugger':
					self::render_debugger();
					break;
				case 'alerts':
					self::render_alerts();
					break;
				case 'settings':
					self::render_settings();
					break;
				default:
					self::render_overview();
			}
			?>
		</div>
		<?php
	}

	private static function render_notice(): void {
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $notice ) {
			return;
		}
		$messages = array(
			'saved'             => array( 'success', __( 'Settings saved.', 'lnkup-marketplace' ) ),
			'checked'           => array( 'info', __( 'Credential check finished. Results are below.', 'lnkup-marketplace' ) ),
			'test_ok'           => array( 'success', __( 'Test event accepted by Meta. Look for it in Events Manager → Test events.', 'lnkup-marketplace' ) ),
			'test_failed'       => array( 'error', __( 'Meta rejected the test event. See the result on the Overview tab.', 'lnkup-marketplace' ) ),
			'test_code_missing' => array( 'warning', __( 'Set a test event code (Configuration tab or META_TEST_EVENT_CODE) before sending a test event.', 'lnkup-marketplace' ) ),
			'dispatched'        => array( 'info', __( 'Dispatcher ran.', 'lnkup-marketplace' ) ),
			'dq_ok'             => array( 'success', __( 'Dataset Quality metrics synced.', 'lnkup-marketplace' ) ),
			'dq_failed'         => array( 'error', __( 'Dataset Quality sync failed. See the error on the Dataset Quality tab.', 'lnkup-marketplace' ) ),
			'dq_rate_limited'   => array( 'warning', __( 'Dataset Quality was synced less than 10 minutes ago.', 'lnkup-marketplace' ) ),
			'alerts_evaluated'  => array( 'info', __( 'Alert checks ran.', 'lnkup-marketplace' ) ),
			'alert_updated'     => array( 'success', __( 'Alert updated.', 'lnkup-marketplace' ) ),
		);
		if ( 0 === strpos( $notice, 'retried_' ) ) {
			$messages[ $notice ] = array( 'info', sprintf( /* translators: %d: count */ __( '%d failed events were queued for retry.', 'lnkup-marketplace' ), (int) substr( $notice, 8 ) ) );
		}
		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
		}
	}

	private static function action_button( string $op, string $label, string $tab, string $class = 'button', array $extra = array() ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 6px 6px 0">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<input type="hidden" name="op" value="<?php echo esc_attr( $op ); ?>" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
			<?php foreach ( $extra as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" />
			<?php endforeach; ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private static function yes_no( bool $ok, string $yes = 'Yes', string $no = 'No' ): string {
		return '<span class="' . ( $ok ? 'ok' : 'bad' ) . '">' . esc_html( $ok ? $yes : $no ) . '</span>';
	}

	private static function badge( string $type ): string {
		$map = array( 'meta' => array( 'b-meta', 'Official Meta' ), 'int' => array( 'b-int', 'LNKup internal' ), 'na' => array( 'b-na', 'Unavailable' ) );
		return '<span class="lnkup-badge ' . esc_attr( $map[ $type ][0] ) . '">' . esc_html( $map[ $type ][1] ) . '</span>';
	}

	private static function when( $gmt_or_ts ): string {
		if ( empty( $gmt_or_ts ) ) {
			return '—';
		}
		$ts = is_numeric( $gmt_or_ts ) ? (int) $gmt_or_ts : strtotime( (string) $gmt_or_ts . ' UTC' );
		return $ts ? esc_html( wp_date( 'M j, Y g:i a', $ts ) ) : '—';
	}

	private static function status_counts(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) n FROM ' . LNKup_Meta_Tables::conversions() . ' WHERE created_at >= %s GROUP BY status', gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	private static function render_overview(): void {
		$block    = LNKup_Meta_Config::capi_block_reason();
		$dq       = LNKup_Meta_Dataset_Quality::status();
		$last     = get_option( 'lnkup_meta_last_delivery', array() );
		$test     = get_option( 'lnkup_meta_last_test', array() );
		$check    = get_option( self::CHECK_OPTION, array() );
		$counts   = self::status_counts();
		$plugins  = LNKup_Meta_Pixel::detected_plugins();
		$conflict = get_option( LNKup_Meta_REST::CONFLICT_OPTION, array() );
		$open     = self::open_alert_count();
		?>
		<div class="grid">
			<div class="card">
				<h3><?php esc_html_e( 'Connection', 'lnkup-marketplace' ); ?></h3>
				<p><?php esc_html_e( 'Dataset ID', 'lnkup-marketplace' ); ?>: <code><?php echo esc_html( LNKup_Meta_Config::dataset_id() ); ?></code></p>
				<p><?php esc_html_e( 'Pixel ID', 'lnkup-marketplace' ); ?>: <code><?php echo esc_html( LNKup_Meta_Config::pixel_id() ); ?></code>
					<?php if ( LNKup_Meta_Config::pixel_id() !== LNKup_Meta_Config::dataset_id() ) : ?><span class="warn"><?php esc_html_e( 'differs from dataset — confirm in Events Manager', 'lnkup-marketplace' ); ?></span><?php endif; ?></p>
				<p><?php esc_html_e( 'Ad account', 'lnkup-marketplace' ); ?>: <code><?php echo esc_html( LNKup_Meta_Config::ad_account_id() ); ?></code></p>
				<p><?php esc_html_e( 'Graph API', 'lnkup-marketplace' ); ?>: <code><?php echo esc_html( LNKup_Meta_Config::graph_version() ); ?></code>
					<?php if ( LNKup_Meta_Config::graph_version_outdated() ) : ?><span class="bad"><?php echo esc_html( sprintf( 'older than %s', LNKup_Meta_Config::MIN_GRAPH_VERSION ) ); ?></span><?php endif; ?></p>
				<p><?php esc_html_e( 'Environment', 'lnkup-marketplace' ); ?>: <?php echo LNKup_Meta_Config::is_production() ? '<span class="ok">Production</span>' : '<span class="warn">Non-production (events are not sent unless test mode is on)</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php if ( LNKup_Meta_Config::test_mode() ) : ?><span class="warn"> · <?php esc_html_e( 'Test mode', 'lnkup-marketplace' ); ?></span><?php endif; ?></p>
			</div>
			<div class="card">
				<h3><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></h3>
				<p><?php esc_html_e( 'Browser Pixel', 'lnkup-marketplace' ); ?>: <?php echo self::yes_no( LNKup_Meta_Config::pixel_active(), 'Active', LNKup_Meta_Config::pixel_enabled() ? 'Enabled, not on production' : 'Off' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
				<p><?php esc_html_e( 'Conversions API', 'lnkup-marketplace' ); ?>: <?php echo self::yes_no( '' === $block, 'Sending', self::REASONS[ $block ] ?? $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
				<p><?php esc_html_e( 'Dataset Quality API', 'lnkup-marketplace' ); ?>: <?php echo LNKup_Meta_Config::dataset_quality_enabled() ? self::yes_no( ! empty( $dq['ok'] ), 'OK', empty( $dq['checked_at'] ) ? 'Not synced yet' : 'Error' ) : '<span class="warn">Off</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
				<p><?php esc_html_e( 'Marketing API reporting', 'lnkup-marketplace' ); ?>: <span class="warn"><?php esc_html_e( 'Not part of this release (access check only)', 'lnkup-marketplace' ); ?></span></p>
				<p><?php esc_html_e( 'Native app events', 'lnkup-marketplace' ); ?>: <span class="warn"><?php echo esc_html( LNKup_Meta_Config::app_events_enabled() ? 'Flag on (no SDK in app yet)' : 'Off — app actions are recorded internally only' ); ?></span></p>
				<p><?php esc_html_e( 'Open alerts', 'lnkup-marketplace' ); ?>: <a href="<?php echo esc_url( self::url( array( 'tab' => 'alerts' ) ) ); ?>"><?php echo (int) $open; ?></a></p>
			</div>
			<div class="card">
				<h3><?php esc_html_e( 'Credentials (masked)', 'lnkup-marketplace' ); ?></h3>
				<?php
				$masked = array(
					'META_CAPI_ACCESS_TOKEN'            => LNKup_Meta_Config::capi_token(),
					'META_DATASET_QUALITY_ACCESS_TOKEN' => 'none' === LNKup_Meta_Config::secret_source( 'META_DATASET_QUALITY_ACCESS_TOKEN' ) ? '' : LNKup_Meta_Config::dataset_quality_token(),
					'META_MARKETING_API_ACCESS_TOKEN'   => LNKup_Meta_Config::marketing_api_token(),
				);
				foreach ( $masked as $key => $token ) :
					?>
					<p><code><?php echo esc_html( $key ); ?></code>: <?php echo esc_html( LNKup_Meta_Config::mask( $token ) ); ?>
						<span class="description">(<?php echo esc_html( LNKup_Meta_Config::secret_source( $key ) ); ?>)</span></p>
				<?php endforeach; ?>
				<?php if ( 'none' === LNKup_Meta_Config::secret_source( 'META_DATASET_QUALITY_ACCESS_TOKEN' ) ) : ?>
					<p class="description"><?php esc_html_e( 'Dataset Quality reuses the CAPI token.', 'lnkup-marketplace' ); ?></p>
				<?php endif; ?>
				<?php if ( is_array( $check ) && ! empty( $check['checked_at'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Last check', 'lnkup-marketplace' ); ?></strong> <?php echo self::when( (int) $check['checked_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
					<?php foreach ( array( 'dataset_quality' => 'Dataset Quality read', 'permissions' => 'Token permissions', 'dataset' => 'Dataset record read (optional)', 'ad_account' => 'Ad account ads_read (optional)' ) as $key => $label ) : ?>
						<?php if ( isset( $check[ $key ] ) ) : ?>
							<?php $item = $check[ $key ]; ?>
							<p><?php echo esc_html( $label ); ?>:
								<?php if ( ! empty( $item['ok'] ) ) : ?>
									<span class="ok"><?php esc_html_e( 'OK', 'lnkup-marketplace' ); ?></span>
								<?php elseif ( ! empty( $item['optional'] ) ) : ?>
									<span class="warn"><?php esc_html_e( 'Not available (optional)', 'lnkup-marketplace' ); ?></span>
								<?php else : ?>
									<span class="bad"><?php esc_html_e( 'Failed', 'lnkup-marketplace' ); ?></span>
								<?php endif; ?>
								<br /><span class="description"><?php echo esc_html( (string) $item['summary'] ); ?></span>
								<?php if ( empty( $item['ok'] ) && ! empty( $item['note'] ) ) : ?><br /><span class="description"><?php echo esc_html( (string) $item['note'] ); ?></span><?php endif; ?>
							</p>
						<?php endif; ?>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Event sending can only be confirmed by a test event or a successful delivery.', 'lnkup-marketplace' ); ?></p>
				<?php endif; ?>
				<?php self::action_button( 'check_credentials', __( 'Check credentials', 'lnkup-marketplace' ), 'overview' ); ?>
			</div>
			<div class="card">
				<h3><?php esc_html_e( 'Delivery (last 7 days)', 'lnkup-marketplace' ); ?> <?php echo self::badge( 'int' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>
				<?php foreach ( array( 'sent' => 'Sent', 'test' => 'Sent (test)', 'pending' => 'Pending', 'sending' => 'In flight', 'failed' => 'Failed', 'expired' => 'Expired', 'skipped' => 'Not sent (by policy)' ) as $key => $label ) : ?>
					<p><?php echo esc_html( $label ); ?>: <strong><?php echo (int) ( $counts[ $key ] ?? 0 ); ?></strong></p>
				<?php endforeach; ?>
				<p><?php esc_html_e( 'Last delivery', 'lnkup-marketplace' ); ?>: <?php echo is_array( $last ) && ! empty( $last['at'] ) ? self::when( (int) $last['at'] ) . ' ' . self::yes_no( ! empty( $last['ok'] ), 'OK', 'Error ' . (string) ( $last['code'] ?? '' ) ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
				<?php self::action_button( 'dispatch_now', __( 'Send pending now', 'lnkup-marketplace' ), 'overview' ); ?>
				<?php self::action_button( 'retry_failed', __( 'Retry failed events', 'lnkup-marketplace' ), 'overview' ); ?>
			</div>
			<div class="card">
				<h3><?php esc_html_e( 'Test event', 'lnkup-marketplace' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Sends one PageView with your own browser context and the configured test code. It appears only in Events Manager → Test events.', 'lnkup-marketplace' ); ?></p>
				<p><?php esc_html_e( 'Test event code', 'lnkup-marketplace' ); ?>: <?php echo '' !== LNKup_Meta_Config::test_event_code() ? '<span class="ok">Configured</span>' : '<span class="warn">Not set</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
				<?php if ( is_array( $test ) && ! empty( $test['at'] ) ) : ?>
					<p><?php echo self::when( (int) $test['at'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>: <?php echo self::yes_no( ! empty( $test['ok'] ), 'Accepted (' . (int) $test['received'] . ' received)', 'Rejected' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( ! empty( $test['trace'] ) ) : ?><br /><span class="description">fbtrace_id <?php echo esc_html( (string) $test['trace'] ); ?></span><?php endif; ?>
						<?php if ( empty( $test['ok'] ) && ! empty( $test['error'] ) ) : ?><br /><span class="description"><?php echo esc_html( LNKup_Meta_Redactor::redact( (string) $test['error'] ) ); ?></span><?php endif; ?></p>
				<?php endif; ?>
				<?php self::action_button( 'test_event', __( 'Send test event', 'lnkup-marketplace' ), 'overview' ); ?>
			</div>
			<div class="card">
				<h3><?php esc_html_e( 'Third-party Pixel', 'lnkup-marketplace' ); ?></h3>
				<?php if ( array() === $plugins && ( ! is_array( $conflict ) || empty( $conflict['at'] ) ) ) : ?>
					<p class="ok"><?php esc_html_e( 'No other Meta Pixel detected.', 'lnkup-marketplace' ); ?></p>
				<?php else : ?>
					<?php if ( array() !== $plugins ) : ?><p class="bad"><?php echo esc_html( 'Active: ' . implode( ', ', $plugins ) ); ?></p><?php endif; ?>
					<?php if ( is_array( $conflict ) && ! empty( $conflict['at'] ) ) : ?><p class="warn"><?php echo esc_html( sprintf( 'A browser reported a second Pixel (%s) on %s', (string) $conflict['reason'], (string) $conflict['url'] ) ); ?> — <?php echo self::when( (int) $conflict['at'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p><?php endif; ?>
					<p class="description"><?php esc_html_e( 'While another Pixel is present, LNKup does not initialize a second copy; it sends its conversions through the existing one (trackSingle). Deactivate the other plugin when you enable the LNKup Pixel.', 'lnkup-marketplace' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private static function open_alert_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LNKup_Meta_Tables::alerts() . " WHERE status = 'open'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function range_days(): int {
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $days, array( 7, 14, 30, 90 ), true ) ? $days : 30;
	}

	private static function range_links( string $tab, int $days ): void {
		echo '<p>';
		foreach ( array( 7, 14, 30, 90 ) as $d ) {
			printf( '<a class="button%s" href="%s">%s</a> ', $d === $days ? ' button-primary' : '', esc_url( self::url( array( 'tab' => $tab, 'days' => $d ) ) ), esc_html( sprintf( 'Last %d days', $d ) ) );
		}
		echo '</p>';
	}

	private static function render_events(): void {
		global $wpdb;
		$days  = self::range_days();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$presence_sql = '';
		foreach ( array( 'em', 'external_id', 'ip', 'ua', 'fbp', 'fbc' ) as $key ) {
			$presence_sql .= $wpdb->prepare( ', SUM(FIND_IN_SET(%s, match_presence) > 0) AS p_' . $key, $key );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT event_name, COUNT(*) total, SUM(status = 'sent') sent, SUM(status = 'failed') failed, SUM(status IN ('pending','sending')) pending, SUM(status IN ('skipped','expired')) skipped, SUM(browser_status IN ('flushed','fired')) browser, SUM(attribution_json <> '') attributed, MAX(created_at) last_at, MAX(sent_at) last_sent{$presence_sql} FROM " . LNKup_Meta_Tables::conversions() . ' WHERE created_at >= %s AND is_test = 0 GROUP BY event_name', $since ), ARRAY_A );
		$by    = array();
		foreach ( (array) $rows as $row ) {
			$by[ (string) $row['event_name'] ] = $row;
		}
		$emq      = LNKup_Meta_Dataset_Quality::latest( 'emq_score' );
		$coverage = LNKup_Meta_Dataset_Quality::latest( 'event_coverage' );
		$dedupe   = LNKup_Meta_Dataset_Quality::latest( 'dedupe_event_id_overall' );
		$defs     = LNKup_Conversion_Registry::definitions();
		$order    = array_unique( array_merge( self::PRIORITY_EVENTS, array_keys( $defs ) ) );
		self::range_links( 'events', $days );
		?>
		<p class="description"><?php esc_html_e( 'Coverage columns show the share of events that carried each matching parameter (presence only). Anonymous events such as ViewContent and Search naturally carry fewer parameters. "Browser" counts events handed to the Pixel with the same event ID; actual browser receipt is only visible in Meta.', 'lnkup-marketplace' ); ?></p>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></th>
				<th class="num"><?php esc_html_e( 'Recorded', 'lnkup-marketplace' ); ?> <?php echo self::badge( 'int' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
				<th class="num"><?php esc_html_e( 'Sent', 'lnkup-marketplace' ); ?></th>
				<th class="num"><?php esc_html_e( 'Failed', 'lnkup-marketplace' ); ?></th>
				<th class="num"><?php esc_html_e( 'Pending', 'lnkup-marketplace' ); ?></th>
				<th class="num"><?php esc_html_e( 'Not sent', 'lnkup-marketplace' ); ?></th>
				<th class="num"><?php esc_html_e( 'Browser', 'lnkup-marketplace' ); ?></th>
				<th><?php esc_html_e( 'Coverage: email / ext ID / IP / UA / fbp / fbc', 'lnkup-marketplace' ); ?></th>
				<th class="num"><?php esc_html_e( 'Attributed', 'lnkup-marketplace' ); ?></th>
				<th class="num"><?php esc_html_e( 'EMQ', 'lnkup-marketplace' ); ?> <?php echo self::badge( 'meta' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
				<th class="num"><?php esc_html_e( 'Coverage / dedup', 'lnkup-marketplace' ); ?> <?php echo self::badge( 'meta' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
				<th><?php esc_html_e( 'Last recorded / sent', 'lnkup-marketplace' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $order as $name ) : ?>
				<?php
				$row   = $by[ $name ] ?? null;
				$def   = $defs[ $name ] ?? array();
				$total = $row ? (int) $row['total'] : 0;
				if ( ! $row && ! in_array( $name, self::PRIORITY_EVENTS, true ) ) {
					continue;
				}
				$pct = static function ( string $key ) use ( $row, $total ): string {
					return $total ? (string) (int) round( 100 * (int) $row[ 'p_' . $key ] / $total ) . '%' : '—';
				};
				?>
				<tr>
					<td><strong><?php echo esc_html( $name ); ?></strong><?php if ( ! empty( $def['optimize'] ) ) : ?> <span class="lnkup-badge b-int" title="<?php esc_attr_e( 'Suitable for ad optimization once volume is sufficient', 'lnkup-marketplace' ); ?>">optimizable</span><?php endif; ?>
						<?php if ( empty( $def['server'] ) ) : ?><br /><span class="description"><?php esc_html_e( 'Internal / browser only', 'lnkup-marketplace' ); ?></span><?php endif; ?></td>
					<td class="num"><?php echo (int) $total; ?></td>
					<td class="num"><?php echo (int) ( $row['sent'] ?? 0 ); ?></td>
					<td class="num"><?php echo (int) ( $row['failed'] ?? 0 ); ?></td>
					<td class="num"><?php echo (int) ( $row['pending'] ?? 0 ); ?></td>
					<td class="num"><?php echo (int) ( $row['skipped'] ?? 0 ); ?></td>
					<td class="num"><?php echo (int) ( $row['browser'] ?? 0 ); ?></td>
					<td><?php echo esc_html( implode( ' / ', array_map( $pct, array( 'em', 'external_id', 'ip', 'ua', 'fbp', 'fbc' ) ) ) ); ?></td>
					<td class="num"><?php echo esc_html( $total ? (string) (int) round( 100 * (int) $row['attributed'] / $total ) . '%' : '—' ); ?></td>
					<td class="num"><?php echo isset( $emq[ $name ]['value'] ) ? esc_html( number_format( (float) $emq[ $name ]['value'], 1 ) ) : self::badge( 'na' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td class="num"><?php echo isset( $coverage[ $name ]['value'] ) ? esc_html( number_format( (float) $coverage[ $name ]['value'], 1 ) . '%' ) : self::badge( 'na' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						/ <?php echo isset( $dedupe[ $name ]['value'] ) ? esc_html( number_format( (float) $dedupe[ $name ]['value'], 1 ) . '%' ) : self::badge( 'na' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td><?php echo self::when( $row['last_at'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><br /><?php echo self::when( $row['last_sent'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_quality(): void {
		global $wpdb;
		$days   = self::range_days();
		$status = LNKup_Meta_Dataset_Quality::status();
		self::range_links( 'quality', $days );
		?>
		<div class="card">
			<h3><?php esc_html_e( 'Dataset Quality API', 'lnkup-marketplace' ); ?> <?php echo self::badge( 'meta' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>
			<?php if ( ! LNKup_Meta_Config::dataset_quality_enabled() ) : ?>
				<p class="warn"><?php esc_html_e( 'Dataset Quality sync is off (Configuration tab or META_DATASET_QUALITY_ENABLED).', 'lnkup-marketplace' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Last successful sync', 'lnkup-marketplace' ); ?>: <?php echo self::when( (int) ( $status['last_success_at'] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					· <?php esc_html_e( 'Last attempt', 'lnkup-marketplace' ); ?>: <?php echo self::when( (int) ( $status['checked_at'] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php if ( ! empty( $status['field_set'] ) && 'core' === $status['field_set'] ) : ?> · <span class="warn"><?php esc_html_e( 'Core fields only (Meta rejected the extended field set)', 'lnkup-marketplace' ); ?></span><?php endif; ?></p>
				<?php if ( isset( $status['ok'] ) && ! $status['ok'] ) : ?>
					<p class="bad"><?php echo esc_html( trim( (string) $status['error_code'] . ' ' . (string) $status['error_message'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $status['metrics_available'] ) ) : ?>
					<p class="description"><?php echo esc_html( 'Metrics Meta returned: ' . implode( ', ', array_slice( (array) $status['metrics_available'], 0, 40 ) ) ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'History starts the day monitoring began; earlier periods are not reconstructed. Meta metrics are 7-day rolling values and may lag by a day.', 'lnkup-marketplace' ); ?></p>
			<?php self::action_button( 'sync_dq', __( 'Sync Dataset Quality now', 'lnkup-marketplace' ), 'quality' ); ?>
		</div>
		<?php
		foreach ( array( 'emq_score' => 'Event Match Quality (0–10)', 'event_coverage' => 'Event coverage % (server vs browser)', 'dedupe_event_id_overall' => 'Browser events deduplicated by event_id %', 'delay_2h_pct' => 'Events delayed over 2h %' ) as $metric => $label ) {
			$series = LNKup_Meta_Dataset_Quality::series( $metric, $days );
			echo '<h3>' . esc_html( $label ) . ' ' . self::badge( 'meta' ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( array() === $series ) {
				echo '<p>' . self::badge( 'na' ) . ' ' . esc_html__( 'No snapshots for this metric in the selected range.', 'lnkup-marketplace' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				continue;
			}
			$dates = array();
			foreach ( $series as $points ) {
				$dates = array_merge( $dates, array_keys( $points ) );
			}
			$dates = array_slice( array_values( array_unique( $dates ) ), -14 );
			sort( $dates );
			echo '<table class="widefat striped"><thead><tr><th>Event</th>';
			foreach ( $dates as $d ) {
				echo '<th class="num">' . esc_html( substr( $d, 5 ) ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $series as $event => $points ) {
				echo '<tr><td>' . esc_html( $event ) . '</td>';
				foreach ( $dates as $d ) {
					echo '<td class="num">' . ( isset( $points[ $d ] ) ? esc_html( number_format( (float) $points[ $d ], 1 ) ) : '—' ) . '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		$diag = LNKup_Meta_Dataset_Quality::latest( 'emq_diagnostics' );
		echo '<h3>' . esc_html__( 'EMQ diagnostics', 'lnkup-marketplace' ) . ' ' . self::badge( 'meta' ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( array() === $diag ) {
			echo '<p>' . self::badge( 'na' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<ul>';
			foreach ( $diag as $event => $row ) {
				foreach ( (array) json_decode( $row['detail'], true ) as $item ) {
					if ( is_array( $item ) ) {
						printf( '<li><strong>%s</strong>: %s — %s <span class="description">(%s%%, %d events)</span></li>', esc_html( $event ), esc_html( (string) $item['name'] ), esc_html( (string) $item['solution'] ), esc_html( null === $item['pct'] ? '?' : (string) $item['pct'] ), (int) $item['affected'] );
					}
				}
			}
			echo '</ul>';
		}

		// Internal delivery reliability by day.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$daily = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) d, COUNT(*) total, SUM(status IN ('sent','test')) sent, SUM(status = 'failed') failed, SUM(status = 'expired') expired, SUM(attribution_json = '' AND user_id > 0) unattributed FROM " . LNKup_Meta_Tables::conversions() . ' WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY d DESC LIMIT 90', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ), ARRAY_A );
		echo '<h3>' . esc_html__( 'Daily conversion volume and delivery', 'lnkup-marketplace' ) . ' ' . self::badge( 'int' ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( empty( $daily ) ) {
			echo '<p>' . esc_html__( 'No conversion events recorded in this range yet.', 'lnkup-marketplace' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Date (UTC)</th><th class="num">Recorded</th><th class="num">Sent</th><th class="num">Failed</th><th class="num">Expired</th><th class="num">Signed-in without attribution</th></tr></thead><tbody>';
		foreach ( $daily as $row ) {
			printf( '<tr><td>%s</td><td class="num">%d</td><td class="num">%d</td><td class="num">%d</td><td class="num">%d</td><td class="num">%d</td></tr>', esc_html( (string) $row['d'] ), (int) $row['total'], (int) $row['sent'], (int) $row['failed'], (int) $row['expired'], (int) $row['unattributed'] );
		}
		echo '</tbody></table>';
	}

	private static function render_debugger(): void {
		global $wpdb;
		$table  = LNKup_Meta_Tables::conversions();
		$name   = isset( $_GET['event'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['event'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$where  = '1=1';
		$args   = array();
		if ( '' !== $name ) {
			$where .= ' AND event_name = %s';
			$args[] = $name;
		}
		if ( in_array( $status, array( 'pending', 'sending', 'sent', 'test', 'failed', 'expired', 'skipped' ), true ) ) {
			$where .= ' AND status = %s';
			$args[] = $status;
		}
		$args[] = 50;
		$args[] = ( $paged - 1 ) * 50;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, event_id, event_name, user_id, role, entity_type, entity_id, source_platform, event_time, match_presence, consent_state, status, browser_status, attempts, attribution_json <> '' AS has_attr, last_error_code, last_error, is_test, created_at FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args ), ARRAY_A );
		?>
		<form method="get" style="margin:12px 0">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="debugger" />
			<select name="event">
				<option value=""><?php esc_html_e( 'All events', 'lnkup-marketplace' ); ?></option>
				<?php foreach ( array_keys( LNKup_Conversion_Registry::definitions() ) as $event ) : ?>
					<option value="<?php echo esc_attr( $event ); ?>"<?php selected( $name, $event ); ?>><?php echo esc_html( $event ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="status">
				<option value=""><?php esc_html_e( 'Any status', 'lnkup-marketplace' ); ?></option>
				<?php foreach ( array( 'pending', 'sending', 'sent', 'test', 'failed', 'expired', 'skipped' ) as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>"<?php selected( $status, $s ); ?>><?php echo esc_html( $s ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button"><?php esc_html_e( 'Filter', 'lnkup-marketplace' ); ?></button>
		</form>
		<p class="description"><?php esc_html_e( 'Shows whether matching parameters were present — never their values. User IDs link to the WordPress profile for authorized admins.', 'lnkup-marketplace' ); ?></p>
		<?php if ( empty( $rows ) ) : ?>
			<p><?php esc_html_e( 'No conversion events match this filter yet.', 'lnkup-marketplace' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped">
			<thead><tr><th>When</th><th>Event</th><th>Event ID</th><th>Marketplace action</th><th>Source</th><th>Status</th><th>Browser</th><th>Matching parameters</th><th>Consent</th><th>Attribution</th><th>Detail</th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php $present = array_filter( explode( ',', (string) $row['match_presence'] ) ); ?>
				<tr>
					<td><?php echo self::when( (string) $row['created_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td><?php echo esc_html( (string) $row['event_name'] ); ?><?php echo $row['is_test'] ? ' <span class="lnkup-badge b-na">test</span>' : ''; ?></td>
					<td><code style="font-size:11px"><?php echo esc_html( (string) $row['event_id'] ); ?></code></td>
					<td><?php echo esc_html( (int) $row['entity_id'] ? (string) $row['entity_type'] . ' #' . (int) $row['entity_id'] : '—' ); ?><br /><span class="description"><?php echo $row['user_id'] ? 'user #' . (int) $row['user_id'] : 'anonymous'; ?> <?php echo esc_html( (string) $row['role'] ); ?></span></td>
					<td><?php echo esc_html( (string) $row['source_platform'] ); ?></td>
					<td><strong><?php echo esc_html( (string) $row['status'] ); ?></strong><?php echo (int) $row['attempts'] ? '<br /><span class="description">' . (int) $row['attempts'] . ' attempt(s)</span>' : ''; ?></td>
					<td><?php echo esc_html( (string) $row['browser_status'] ); ?></td>
					<td><?php foreach ( array( 'em' => 'Email', 'external_id' => 'Ext ID', 'ip' => 'IP', 'ua' => 'UA', 'fbp' => 'Browser ID', 'fbc' => 'Click ID' ) as $key => $label ) : ?><span class="chip<?php echo in_array( $key, $present, true ) ? ' on' : ''; ?>"><?php echo esc_html( $label . ': ' . ( in_array( $key, $present, true ) ? 'Yes' : 'No' ) ); ?></span><?php endforeach; ?></td>
					<td><?php echo esc_html( 'granted' === $row['consent_state'] ? 'Eligible' : 'Refused' ); ?></td>
					<td><?php echo $row['has_attr'] ? 'Yes' : 'No'; ?></td>
					<td><?php echo esc_html( self::REASONS[ (string) $row['last_error_code'] ] ?? (string) $row['last_error_code'] ); ?><?php if ( '' !== (string) $row['last_error'] ) : ?><br /><span class="description"><?php echo esc_html( LNKup_Meta_Redactor::redact( (string) $row['last_error'] ) ); ?></span><?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<?php if ( $paged > 1 ) : ?><a class="button" href="<?php echo esc_url( self::url( array( 'tab' => 'debugger', 'event' => $name, 'status' => $status, 'paged' => $paged - 1 ) ) ); ?>">&larr;</a><?php endif; ?>
			<?php if ( count( $rows ) === 50 ) : ?><a class="button" href="<?php echo esc_url( self::url( array( 'tab' => 'debugger', 'event' => $name, 'status' => $status, 'paged' => $paged + 1 ) ) ); ?>">&rarr;</a><?php endif; ?>
		</p>
		<?php
	}

	private static function render_alerts(): void {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . LNKup_Meta_Tables::alerts() . " ORDER BY FIELD(status,'open','dismissed','resolved'), FIELD(severity,'critical','high','medium','low'), last_seen DESC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::action_button( 'evaluate_alerts', __( 'Run checks now', 'lnkup-marketplace' ), 'alerts' );
		?>
		<p class="description"><?php esc_html_e( 'Checks run hourly with minimum-volume floors. Causes are suspected, not confirmed. Critical and high alerts email the site admin at most once per day.', 'lnkup-marketplace' ); ?></p>
		<?php if ( empty( $rows ) ) : ?>
			<p class="ok"><?php esc_html_e( 'No alerts.', 'lnkup-marketplace' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped">
			<thead><tr><th>Status</th><th>Severity</th><th>Issue</th><th>Evidence</th><th>Suspected cause</th><th>Recommended action</th><th>First / latest</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $row['status'] ); ?></td>
					<td><span class="<?php echo in_array( $row['severity'], array( 'critical', 'high' ), true ) ? 'bad' : 'warn'; ?>"><?php echo esc_html( (string) $row['severity'] ); ?></span></td>
					<td><strong><?php echo esc_html( (string) $row['title'] ); ?></strong><br /><span class="description"><?php echo esc_html( (string) $row['affected_events'] ); ?></span></td>
					<td><?php echo esc_html( (string) $row['evidence'] ); ?></td>
					<td><?php echo esc_html( (string) $row['suspected_cause'] ); ?></td>
					<td><?php echo esc_html( (string) $row['recommended_action'] ); ?></td>
					<td><?php echo self::when( (string) $row['first_seen'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><br /><?php echo self::when( (string) $row['last_seen'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td><?php if ( 'open' === $row['status'] ) : ?>
						<?php self::action_button( 'alert_status', __( 'Dismiss', 'lnkup-marketplace' ), 'alerts', 'button button-small', array( 'alert_id' => (int) $row['id'], 'status' => 'dismissed' ) ); ?>
						<?php self::action_button( 'alert_status', __( 'Resolve', 'lnkup-marketplace' ), 'alerts', 'button button-small', array( 'alert_id' => (int) $row['id'], 'status' => 'resolved' ) ); ?>
					<?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_settings(): void {
		$s = LNKup_Meta_Config::settings();
		$flags = array(
			'META_PIXEL_ENABLED'           => __( 'Load the LNKup Meta Pixel (deactivate any other Pixel plugin first)', 'lnkup-marketplace' ),
			'META_CAPI_ENABLED'            => __( 'Send server events (Conversions API)', 'lnkup-marketplace' ),
			'META_DATASET_QUALITY_ENABLED' => __( 'Sync Dataset Quality metrics daily', 'lnkup-marketplace' ),
			'META_MARKETING_API_ENABLED'   => __( 'Marketing API reporting (reserved; read-only reporting ships in a later phase)', 'lnkup-marketplace' ),
			'META_APP_EVENTS_ENABLED'      => __( 'Native app events (requires the Meta SDK in a new app build)', 'lnkup-marketplace' ),
			'META_TEST_MODE'               => __( 'Test mode: send every server event with the test event code (never counted for optimization)', 'lnkup-marketplace' ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<input type="hidden" name="op" value="save_settings" />
			<input type="hidden" name="tab" value="settings" />
			<p class="description"><?php esc_html_e( 'Access tokens are never entered here. Define them in wp-config.php (LNKUP_META_CAPI_ACCESS_TOKEN etc.) or the server environment. Values set there override this page and show as locked.', 'lnkup-marketplace' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( $flags as $env => $label ) : ?>
					<?php $key = LNKup_Meta_Config::FLAG_KEYS[ $env ]; $locked = LNKup_Meta_Config::is_locked( $env ); ?>
					<tr><th><code><?php echo esc_html( $env ); ?></code></th><td><label><input type="checkbox" name="meta[<?php echo esc_attr( $key ); ?>]" value="1"<?php checked( ! empty( $s[ $key ] ) ); ?><?php disabled( $locked ); ?> /> <?php echo esc_html( $label ); ?></label><?php echo $locked ? ' <em>(' . esc_html__( 'locked by wp-config / environment', 'lnkup-marketplace' ) . ')</em>' : ''; ?></td></tr>
				<?php endforeach; ?>
				<?php foreach ( LNKup_Meta_Config::VALUE_KEYS as $env => $key ) : ?>
					<?php $locked = LNKup_Meta_Config::is_locked( $env ); ?>
					<tr><th><code><?php echo esc_html( $env ); ?></code></th><td><input type="text" class="regular-text" name="meta[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $s[ $key ] ); ?>"<?php disabled( $locked ); ?> /><?php echo $locked ? ' <em>(' . esc_html__( 'locked', 'lnkup-marketplace' ) . ')</em>' : ''; ?></td></tr>
				<?php endforeach; ?>
				<tr><th><?php esc_html_e( 'Limited Data Use', 'lnkup-marketplace' ); ?></th><td>
					<select name="meta[ldu_mode]">
						<option value="off"<?php selected( $s['ldu_mode'], 'off' ); ?>><?php esc_html_e( 'Off', 'lnkup-marketplace' ); ?></option>
						<option value="ldu_geo"<?php selected( $s['ldu_mode'], 'ldu_geo' ); ?>><?php esc_html_e( 'LDU — Meta geolocates (applies in states with privacy laws)', 'lnkup-marketplace' ); ?></option>
						<option value="ldu_all_us"<?php selected( $s['ldu_mode'], 'ldu_all_us' ); ?>><?php esc_html_e( 'LDU — apply to all US traffic', 'lnkup-marketplace' ); ?></option>
					</select></td></tr>
				<tr><th><?php esc_html_e( 'Exclude staff accounts', 'lnkup-marketplace' ); ?></th><td><label><input type="checkbox" name="meta[exclude_staff]" value="1"<?php checked( ! empty( $s['exclude_staff'] ) ); ?> /> <?php esc_html_e( 'Never send events for LNKup admins', 'lnkup-marketplace' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'Excluded user IDs (test accounts)', 'lnkup-marketplace' ); ?></th><td><input type="text" class="regular-text" name="meta[excluded_user_ids]" value="<?php echo esc_attr( (string) $s['excluded_user_ids'] ); ?>" placeholder="12,34" /></td></tr>
				<tr><th><?php esc_html_e( 'Vendor activation', 'lnkup-marketplace' ); ?></th><td>
					<?php esc_html_e( 'Profile strength at least', 'lnkup-marketplace' ); ?> <input type="number" min="0" max="100" name="meta[vendor_activation_profile_min]" value="<?php echo (int) $s['vendor_activation_profile_min']; ?>" style="width:70px" />
					<?php esc_html_e( 'and at least', 'lnkup-marketplace' ); ?> <input type="number" min="1" max="20" name="meta[vendor_activation_min_applications]" value="<?php echo (int) $s['vendor_activation_min_applications']; ?>" style="width:60px" /> <?php esc_html_e( 'submitted application(s).', 'lnkup-marketplace' ); ?>
					<p class="description"><?php esc_html_e( 'Organizer activation = first event listing goes live with an open vendor opportunity.', 'lnkup-marketplace' ); ?></p></td></tr>
				<tr><th><?php esc_html_e( 'Alert thresholds', 'lnkup-marketplace' ); ?></th><td>
					<?php
					$fields = array(
						'alert_min_volume'             => 'Minimum volume before alerting',
						'alert_failure_rate_pct'       => 'Delivery failure rate % (24h)',
						'alert_registration_gap_hours' => 'Registration tracking gap (hours)',
						'alert_failed_queue_max'       => 'Failed events (7 days)',
						'alert_backlog_minutes'        => 'Pending older than (minutes)',
						'alert_dq_stale_hours'         => 'Dataset Quality stale after (hours)',
						'alert_coverage_drop_pts'      => 'Matching coverage drop (points, week over week)',
						'alert_emq_drop'               => 'EMQ drop (points)',
					);
					foreach ( $fields as $key => $label ) :
						?>
						<p><label><?php echo esc_html( $label ); ?> <input type="number" step="<?php echo 'alert_emq_drop' === $key ? '0.1' : '1'; ?>" min="0" name="meta[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $s[ $key ] ); ?>" style="width:80px" /></label></p>
					<?php endforeach; ?>
				</td></tr>
			</table>
			<?php submit_button( __( 'Save configuration', 'lnkup-marketplace' ) ); ?>
		</form>
		<?php
	}
}
