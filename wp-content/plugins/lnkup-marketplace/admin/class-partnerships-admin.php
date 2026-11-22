<?php
/**
 * LNKup > Partnerships & Referrals admin hub.
 *
 * Reads only from the canonical referral, reward and review tables. Every write goes through
 * LNKup_Connector_Rewards / LNKup_Commission_Rules / LNKup_Attribution_Arbiter and is audited.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Partnerships_Admin {

	const SLUG  = 'lnkup-partnerships';
	const NONCE = 'lnkup_partnerships_admin';
	const TABS  = array( 'overview', 'applications', 'partners', 'pipeline', 'rules', 'ledger', 'reviews', 'connectors', 'settings' );

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 25 );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Partnerships & Referrals', 'lnkup-marketplace' ),
			__( 'Partnerships & Referrals', 'lnkup-marketplace' ),
			'lnkup_manage_all',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_script( 'lnkup-partnerships-admin', LNKUP_PLUGIN_URL . 'admin/js/partnerships.js', array(), LNKUP_VERSION, true );
	}

	private static function url( string $tab, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	private static function redirect( string $tab, string $message, string $type = 'success' ): void {
		wp_safe_redirect( self::url( $tab, array( 'message' => rawurlencode( $message ), 'type' => $type ) ) );
		exit;
	}

	/**
	 * @param true|WP_Error|array<string, mixed> $result Result.
	 */
	private static function finish( string $tab, $result, string $ok ): void {
		if ( is_wp_error( $result ) ) {
			self::redirect( $tab, $result->get_error_message(), 'error' );
		}
		self::redirect( $tab, $ok );
	}

	// ------------------------------------------------------------------ Actions.

	public static function handle_actions(): void {
		if ( empty( $_POST['lnkup_partners_action'] ) || ! is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		check_admin_referer( self::NONCE );
		$action = sanitize_key( wp_unslash( $_POST['lnkup_partners_action'] ) );
		$post   = wp_unslash( $_POST );
		$actor  = get_current_user_id();

		switch ( $action ) {
			case 'ledger':
				$op = sanitize_key( (string) ( $post['ledger_op'] ?? '' ) );
				self::finish(
					'ledger',
					LNKup_Partnerships::ledger_action(
						absint( $post['reward_id'] ?? 0 ),
						str_replace( '_', '-', $op ),
						array(
							'reason'           => sanitize_key( (string) ( $post['reason'] ?? 'admin_review' ) ),
							'payout_reference' => sanitize_text_field( (string) ( $post['payout_reference'] ?? '' ) ),
						)
					),
					__( 'Ledger updated.', 'lnkup-marketplace' )
				);
				break;
			case 'rule_create':
				self::finish( 'rules', LNKup_Commission_Rules::create_version( sanitize_key( (string) ( $post['program'] ?? '' ) ), (array) $post, $actor ), __( 'Draft rule created. Publish it to apply to new referrals.', 'lnkup-marketplace' ) );
				break;
			case 'rule_publish':
				self::finish( 'rules', LNKup_Commission_Rules::publish( absint( $post['rule_id'] ?? 0 ), $actor ), __( 'Rule published. Existing referrals keep the rule they were locked to.', 'lnkup-marketplace' ) );
				break;
			case 'review_resolve':
				$review_id = absint( $post['review_id'] ?? 0 );
				$review    = LNKup_Partner_Reviews::get( $review_id );
				$notes     = sanitize_textarea_field( (string) ( $post['notes'] ?? '' ) );
				if ( $review && 'attribution_conflict' === $review['type'] ) {
					self::finish( 'reviews', LNKup_Attribution_Arbiter::resolve_conflict( $review_id, absint( $post['winner_referral_id'] ?? 0 ), $actor, $notes ), __( 'Attribution resolved.', 'lnkup-marketplace' ) );
				}
				$resolution = sanitize_key( (string) ( $post['resolution'] ?? '' ) );
				if ( ! $review || ! in_array( $resolution, array( 'recovered', 'written_off', 'not_owed' ), true ) ) {
					self::redirect( 'reviews', __( 'Choose how this review was resolved.', 'lnkup-marketplace' ), 'error' );
				}
				$closed = LNKup_Partner_Reviews::close( $review_id, $resolution, 0, $notes, $actor );
				self::redirect( 'reviews', $closed ? __( 'Review closed.', 'lnkup-marketplace' ) : __( 'This review was already resolved.', 'lnkup-marketplace' ), $closed ? 'success' : 'error' );
				break;
			case 'territory_add':
				self::finish( 'connectors', LNKup_Partnerships::add_territory( absint( $post['connector_id'] ?? 0 ), (string) ( $post['scope_type'] ?? '' ), (string) ( $post['value'] ?? '' ), $actor ), __( 'Territory assigned.', 'lnkup-marketplace' ) );
				break;
			case 'territory_remove':
				self::finish( 'connectors', LNKup_Partnerships::remove_territory( absint( $post['territory_id'] ?? 0 ) ), __( 'Territory removed.', 'lnkup-marketplace' ) );
				break;
			case 'settings':
				LNKup_Partnerships::update_settings( (array) $post );
				self::redirect( 'settings', __( 'Settings saved.', 'lnkup-marketplace' ) );
				break;
			case 'application':
				self::finish(
					'applications',
					LNKup_Partner_Applications::transition( absint( $post['application_id'] ?? 0 ), sanitize_key( (string) ( $post['application_op'] ?? '' ) ), $actor, (string) ( $post['note'] ?? '' ) ),
					__( 'Application updated.', 'lnkup-marketplace' )
				);
				break;
			case 'org_update':
				$fields = array_intersect_key( (array) $post, array_flip( array( 'name', 'category', 'contact_name', 'contact_email', 'website', 'cities_served', 'stage', 'owner_user_id', 'agreement_ref', 'commission_rule_id', 'referral_arrangement', 'notes', 'next_follow_up_at' ) ) );
				self::finish( 'partners', LNKup_Partner_Organizations::update( absint( $post['partner_id'] ?? 0 ), $fields, $actor ), __( 'Partner organization saved.', 'lnkup-marketplace' ) );
				break;
			case 'org_create':
				$org_id = LNKup_Partner_Organizations::find_or_create( (array) $post, $actor );
				self::finish(
					'partners',
					$org_id ? true : new WP_Error( 'lnkup_partner_org_name', __( 'Organization name is required.', 'lnkup-marketplace' ) ),
					__( 'Partner organization saved (existing records are reused, not duplicated).', 'lnkup-marketplace' )
				);
				break;
		}
	}

	public static function maybe_export(): void {
		if ( empty( $_GET['lnkup_partners_export'] ) || ! is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		check_admin_referer( 'lnkup_partners_export' );
		$csv = LNKup_Partnerships::ledger_csv(
			array(
				'program' => sanitize_key( wp_unslash( $_GET['program'] ?? '' ) ),
				'status'  => sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ),
			)
		);
		LNKup_Financial_Audit::log( 'partner_ledger_exported', 'partnership_ledger', 0, array( 'bytes' => strlen( $csv ) ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=lnkup-partner-ledger-' . gmdate( 'Ymd' ) . '.csv' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	// ------------------------------------------------------------------ Render.

	public static function render_page(): void {
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'overview' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, self::TABS, true ) ) {
			$tab = 'overview';
		}
		$labels = array(
			'overview'     => __( 'Overview', 'lnkup-marketplace' ),
			'applications' => __( 'Applications', 'lnkup-marketplace' ),
			'partners'     => __( 'Strategic Partners', 'lnkup-marketplace' ),
			'pipeline'   => __( 'Referral Pipeline', 'lnkup-marketplace' ),
			'rules'      => __( 'Commission Rules', 'lnkup-marketplace' ),
			'ledger'     => __( 'Commission Ledger', 'lnkup-marketplace' ),
			'reviews'    => __( 'Reviews', 'lnkup-marketplace' ),
			'connectors' => __( 'City Connectors', 'lnkup-marketplace' ),
			'settings'   => __( 'Settings', 'lnkup-marketplace' ),
		);
		?>
		<div class="wrap lnkup-partnerships-admin" data-testid="lnkup-partnerships-admin">
			<h1><?php esc_html_e( 'Partnerships & Referrals', 'lnkup-marketplace' ); ?></h1>
			<?php self::render_notice(); ?>
			<?php if ( ! LNKup_Partner_Tables::ready() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Partnership tables are being installed. Reload this page in a moment.', 'lnkup-marketplace' ); ?></p></div>
			<?php else : ?>
				<nav class="nav-tab-wrapper">
					<?php foreach ( $labels as $slug => $label ) : ?>
						<a href="<?php echo esc_url( self::url( $slug ) ); ?>" class="nav-tab<?php echo $tab === $slug ? ' nav-tab-active' : ''; ?>" data-testid="lnkup-partners-tab-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>
				<div class="lnkup-partners-panel" style="margin-top:16px;">
					<?php
					switch ( $tab ) {
						case 'applications':
							self::render_applications();
							break;
						case 'partners':
							self::render_partners();
							break;
						case 'pipeline':
							self::render_pipeline();
							break;
						case 'rules':
							self::render_rules();
							break;
						case 'ledger':
							self::render_ledger();
							break;
						case 'reviews':
							self::render_reviews();
							break;
						case 'connectors':
							self::render_connectors();
							break;
						case 'settings':
							self::render_settings();
							break;
						default:
							self::render_overview();
					}
					?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_notice(): void {
		if ( empty( $_GET['message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$type = 'error' === sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ) ? 'error' : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['message'] ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	private static function money( $value ): string {
		return '$' . number_format( (float) $value, 2 );
	}

	private static function card( string $label, $value, string $help = '' ): void {
		?>
		<div class="card" style="min-width:180px;flex:1;padding:12px 16px;margin:0;">
			<p style="margin:0;color:#646970;"><?php echo esc_html( $label ); ?></p>
			<p style="margin:4px 0 0;font-size:22px;font-weight:600;"><?php echo esc_html( (string) $value ); ?></p>
			<?php if ( $help ) : ?><p class="description" style="margin:4px 0 0;"><?php echo esc_html( $help ); ?></p><?php endif; ?>
		</div>
		<?php
	}

	private static function nonce_field(): void {
		wp_nonce_field( self::NONCE );
	}

	private static function render_overview(): void {
		$o = LNKup_Partnerships::overview();
		?>
		<div data-testid="lnkup-partners-overview">
			<p class="description">
				<?php
				printf(
					/* translators: 1: generated time, 2: arbiter mode */
					esc_html__( 'Actual ledger and referral data as of %1$s (refreshes every 10 minutes). Attribution mode: %2$s.', 'lnkup-marketplace' ),
					esc_html( (string) $o['generated_at'] ),
					'<strong>' . esc_html( (string) $o['arbiter_mode'] ) . '</strong>'
				);
				?>
			</p>
			<h2><?php esc_html_e( 'Organizer acquisition', 'lnkup-marketplace' ); ?></h2>
			<div style="display:flex;gap:12px;flex-wrap:wrap;">
				<?php
				self::card( __( 'Active City Connectors', 'lnkup-marketplace' ), (int) $o['connectors']['active'], sprintf( /* translators: %d pending */ __( '%d applications pending', 'lnkup-marketplace' ), (int) $o['connectors']['pending'] ) );
				self::card( __( 'Active affiliates', 'lnkup-marketplace' ), (int) ( $o['partners']['affiliates_active'] ?? 0 ) );
				self::card( __( 'Strategic partners (agreement or active)', 'lnkup-marketplace' ), (int) ( $o['partners']['strategic_active'] ?? 0 ), sprintf( /* translators: %d contacts */ __( '%d active partner contacts', 'lnkup-marketplace' ), (int) ( $o['partners']['strategic_contacts'] ?? 0 ) ) );
				self::card( __( 'Partner applications to review', 'lnkup-marketplace' ), (int) ( $o['partners']['applications_pending'] ?? 0 ) );
				self::card( __( 'Partner-submitted organizer leads', 'lnkup-marketplace' ), (int) $o['leads']['partner_submitted'] );
				self::card( __( 'Referred organizers registered', 'lnkup-marketplace' ), (int) $o['organizers']['referred'], sprintf( /* translators: 1 independent, 2 house */ __( '%1$d independent · %2$d Six Stars / house', 'lnkup-marketplace' ), (int) $o['organizers']['independent'], (int) $o['organizers']['house'] ) );
				self::card( __( 'With a published event', 'lnkup-marketplace' ), (int) $o['organizers']['first_event'] );
				self::card( __( 'With a verified paid booking', 'lnkup-marketplace' ), (int) $o['organizers']['first_paid_booking'] );
				self::card( __( 'Referred vendors', 'lnkup-marketplace' ), (int) $o['vendors_referred'] );
				?>
			</div>
			<h2><?php esc_html_e( 'Commissions (net of reversals)', 'lnkup-marketplace' ); ?></h2>
			<div style="display:flex;gap:12px;flex-wrap:wrap;">
				<?php
				self::card( __( 'Pending', 'lnkup-marketplace' ), self::money( $o['totals']['pending'] ), __( 'Not payable yet', 'lnkup-marketplace' ) );
				self::card( __( 'On hold', 'lnkup-marketplace' ), self::money( $o['totals']['on_hold'] ) );
				self::card( __( 'Approved', 'lnkup-marketplace' ), self::money( $o['totals']['approved'] ), __( 'Payable, not yet paid', 'lnkup-marketplace' ) );
				self::card( __( 'Paid / credited', 'lnkup-marketplace' ), self::money( $o['totals']['paid'] ) );
				self::card( __( 'Reversed', 'lnkup-marketplace' ), self::money( $o['totals']['reversed'] ) );
				self::card( __( 'Qualifying payments', 'lnkup-marketplace' ), (int) $o['qualifying_payments'] );
				self::card( __( 'Commissionable LNKup fees', 'lnkup-marketplace' ), self::money( $o['commissionable_platform_fees'] ) );
				self::card( __( 'Qualifying booth GMV (legacy GMV rule)', 'lnkup-marketplace' ), self::money( $o['qualifying_booth_gmv'] ) );
				self::card( __( 'Open reviews', 'lnkup-marketplace' ), (int) $o['open_reviews'] );
				?>
			</div>
			<h2><?php esc_html_e( 'By program', 'lnkup-marketplace' ); ?></h2>
			<?php if ( empty( $o['ledger'] ) ) : ?>
				<p><?php esc_html_e( 'No commission activity has been recorded yet.', 'lnkup-marketplace' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Program', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Rows', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Pending', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'On hold', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Approved', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Paid / credited', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Reversed', 'lnkup-marketplace' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $o['ledger'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $row['label'] ); ?></td>
							<td><?php echo esc_html( (string) $row['count'] ); ?></td>
							<td><?php echo esc_html( self::money( $row['pending'] ) ); ?></td>
							<td><?php echo esc_html( self::money( $row['on_hold'] ) ); ?></td>
							<td><?php echo esc_html( self::money( $row['approved'] ) ); ?></td>
							<td><?php echo esc_html( self::money( $row['paid'] ) ); ?></td>
							<td><?php echo esc_html( self::money( $row['reversed'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function pager( int $total, int $page, int $per, string $tab, array $args ): void {
		$pages = (int) ceil( $total / max( 1, $per ) );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<p class="tablenav-pages">';
		for ( $i = 1; $i <= min( $pages, 50 ); $i++ ) {
			$link = self::url( $tab, array_merge( $args, array( 'paged' => $i ) ) );
			echo $i === $page ? '<strong>' . esc_html( (string) $i ) . '</strong> ' : '<a href="' . esc_url( $link ) . '">' . esc_html( (string) $i ) . '</a> ';
		}
		echo '</p>';
	}

	private static function program_select( string $name, string $current, bool $any = true ): void {
		echo '<select name="' . esc_attr( $name ) . '">';
		if ( $any ) {
			echo '<option value="">' . esc_html__( 'All programs', 'lnkup-marketplace' ) . '</option>';
		}
		foreach ( LNKup_Partner_Programs::definitions() as $slug => $def ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $slug ), selected( $current, $slug, false ), esc_html( (string) $def['name'] ) );
		}
		echo '</select>';
	}

	private static function render_pipeline(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$args = array(
			'program'            => sanitize_key( wp_unslash( $_GET['program'] ?? '' ) ),
			'attribution_status' => sanitize_key( wp_unslash( $_GET['attribution_status'] ?? '' ) ),
			'role'               => sanitize_key( wp_unslash( $_GET['role'] ?? '' ) ),
			'market'             => sanitize_key( wp_unslash( $_GET['market'] ?? '' ) ),
		);
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:enable
		$data = LNKup_Partnerships::referrals( $args + array( 'page' => $page, 'per_page' => 25 ) );
		?>
		<form method="get" data-testid="lnkup-partners-pipeline-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="pipeline" />
			<?php self::program_select( 'program', $args['program'] ); ?>
			<select name="attribution_status">
				<option value=""><?php esc_html_e( 'Any attribution', 'lnkup-marketplace' ); ?></option>
				<?php foreach ( array( 'winning', 'conflict', 'superseded' ) as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $args['attribution_status'], $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="role">
				<option value=""><?php esc_html_e( 'Organizers and vendors', 'lnkup-marketplace' ); ?></option>
				<option value="organizer" <?php selected( $args['role'], 'organizer' ); ?>><?php esc_html_e( 'Organizers', 'lnkup-marketplace' ); ?></option>
				<option value="vendor" <?php selected( $args['role'], 'vendor' ); ?>><?php esc_html_e( 'Vendors', 'lnkup-marketplace' ); ?></option>
			</select>
			<input type="text" name="market" value="<?php echo esc_attr( $args['market'] ); ?>" placeholder="<?php esc_attr_e( 'Market slug', 'lnkup-marketplace' ); ?>" />
			<button class="button"><?php esc_html_e( 'Filter', 'lnkup-marketplace' ); ?></button>
		</form>
		<?php if ( ! $data['items'] ) : ?>
			<p><?php esc_html_e( 'No referrals match these filters.', 'lnkup-marketplace' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped" data-testid="lnkup-partners-pipeline">
			<thead><tr><th>ID</th><th><?php esc_html_e( 'Program', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Referrer', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Referred', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Role', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Attributed', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'First event', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'First paid booking', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Attribution', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Earned (net)', 'lnkup-marketplace' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $data['items'] as $r ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $r['id'] ); ?></td>
					<td><?php echo esc_html( $r['program_label'] ); ?></td>
					<td><?php echo esc_html( $r['referrer'] ); ?></td>
					<td><?php echo esc_html( $r['referred'] ); ?></td>
					<td><?php echo esc_html( $r['target_role'] ); ?></td>
					<td><?php echo esc_html( $r['created_at'] ); ?></td>
					<td><?php echo esc_html( $r['first_event_at'] ?: '—' ); ?></td>
					<td><?php echo esc_html( $r['first_payment_at'] ?: '—' ); ?></td>
					<td><?php echo esc_html( $r['attribution_status'] ?: __( 'Not yet resolved', 'lnkup-marketplace' ) ); ?></td>
					<td><?php echo esc_html( self::money( $r['earned'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::pager( $data['total'], $page, 25, 'pipeline', array_filter( $args ) );
	}

	private static function render_rules(): void {
		$rules = LNKup_Commission_Rules::list_rules();
		?>
		<p class="description"><?php esc_html_e( 'Published rules are never edited. Create a new version to change terms; referrals and connectors stay locked to the version they were attributed or approved under. Legacy (settings) versions follow the existing City Connector and referral settings.', 'lnkup-marketplace' ); ?></p>
		<table class="widefat striped" data-testid="lnkup-partners-rules">
			<thead><tr><th><?php esc_html_e( 'Program', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Version', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Terms', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Term / hold', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Stacking', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Effective', 'lnkup-marketplace' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $rules as $rule ) : ?>
				<tr>
					<td><?php echo esc_html( $rule['program_label'] ); ?></td>
					<td>v<?php echo esc_html( (string) $rule['version'] ); ?><?php echo 'settings' === $rule['source'] ? ' <span class="description">(' . esc_html__( 'legacy settings', 'lnkup-marketplace' ) . ')</span>' : ''; ?><br /><span class="description"><?php echo esc_html( $rule['name'] ); ?></span></td>
					<td><?php echo esc_html( $rule['basis_label'] ); ?><?php echo $rule['cap'] > 0 ? '<br /><span class="description">' . esc_html( sprintf( /* translators: %s cap */ __( 'Capped at %s per booking', 'lnkup-marketplace' ), self::money( $rule['cap'] ) ) ) . '</span>' : ''; ?></td>
					<td><?php echo esc_html( sprintf( /* translators: 1 months, 2 days */ __( '%1$d mo / %2$d-day hold', 'lnkup-marketplace' ), $rule['term_months'], $rule['hold_days'] ) ); ?></td>
					<td><?php echo esc_html( $rule['stacking_policy'] ); ?></td>
					<td><?php echo esc_html( $rule['status'] ); ?></td>
					<td><?php echo esc_html( $rule['effective_from'] ? $rule['effective_from'] . ( $rule['effective_to'] ? ' → ' . $rule['effective_to'] : '' ) : '—' ); ?></td>
					<td>
						<?php if ( 'draft' === $rule['status'] ) : ?>
							<form method="post" data-lnkup-confirm="<?php esc_attr_e( 'Publish this rule? New referrals from now on will earn under it.', 'lnkup-marketplace' ); ?>">
								<?php self::nonce_field(); ?>
								<input type="hidden" name="lnkup_partners_action" value="rule_publish" />
								<input type="hidden" name="rule_id" value="<?php echo esc_attr( (string) $rule['id'] ); ?>" />
								<button class="button button-primary button-small"><?php esc_html_e( 'Publish', 'lnkup-marketplace' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<h2><?php esc_html_e( 'Create a draft version', 'lnkup-marketplace' ); ?></h2>
		<form method="post" data-testid="lnkup-partners-rule-form">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="lnkup_partners_action" value="rule_create" />
			<table class="form-table" role="presentation">
				<tr><th><label><?php esc_html_e( 'Program', 'lnkup-marketplace' ); ?></label></th><td><?php self::program_select( 'program', LNKup_Partner_Programs::CITY_CONNECTOR, false ); ?></td></tr>
				<tr><th><label for="lnkup-rule-name"><?php esc_html_e( 'Name', 'lnkup-marketplace' ); ?></label></th><td><input id="lnkup-rule-name" name="name" class="regular-text" required /></td></tr>
				<tr><th><label for="lnkup-rule-basis"><?php esc_html_e( 'Basis', 'lnkup-marketplace' ); ?></label></th><td>
					<select id="lnkup-rule-basis" name="basis">
						<option value="platform_fee"><?php esc_html_e( 'Share of LNKup fees (recommended)', 'lnkup-marketplace' ); ?></option>
						<option value="booth_gmv"><?php esc_html_e( 'Share of booth price (GMV)', 'lnkup-marketplace' ); ?></option>
						<option value="fixed"><?php esc_html_e( 'Fixed amount per milestone', 'lnkup-marketplace' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Booth price is not LNKup revenue. A GMV share can exceed what LNKup earns on a booking.', 'lnkup-marketplace' ); ?></p>
				</td></tr>
				<tr><th><label for="lnkup-rule-rate"><?php esc_html_e( 'Rate (%)', 'lnkup-marketplace' ); ?></label></th><td><input id="lnkup-rule-rate" name="rate" type="number" min="0" max="100" step="0.01" class="small-text" /></td></tr>
				<tr><th><label for="lnkup-rule-fixed"><?php esc_html_e( 'Fixed amount ($)', 'lnkup-marketplace' ); ?></label></th><td><input id="lnkup-rule-fixed" name="fixed_amount" type="number" min="0" step="0.01" class="small-text" /></td></tr>
				<tr><th><label for="lnkup-rule-cap"><?php esc_html_e( 'Cap per booking ($, 0 = none)', 'lnkup-marketplace' ); ?></label></th><td><input id="lnkup-rule-cap" name="cap" type="number" min="0" step="0.01" class="small-text" value="0" /></td></tr>
				<tr><th><label for="lnkup-rule-term"><?php esc_html_e( 'Term (months)', 'lnkup-marketplace' ); ?></label></th><td><input id="lnkup-rule-term" name="term_months" type="number" min="0" max="60" class="small-text" value="12" /></td></tr>
				<tr><th><label for="lnkup-rule-hold"><?php esc_html_e( 'Hold (days)', 'lnkup-marketplace' ); ?></label></th><td><input id="lnkup-rule-hold" name="hold_days" type="number" min="0" max="180" class="small-text" value="30" /></td></tr>
				<tr><th><label for="lnkup-rule-stack"><?php esc_html_e( 'Stacking', 'lnkup-marketplace' ); ?></label></th><td>
					<select id="lnkup-rule-stack" name="stacking_policy">
						<option value="exclusive"><?php esc_html_e( 'Exclusive — one commission per payment', 'lnkup-marketplace' ); ?></option>
						<option value="payment_stackable"><?php esc_html_e( 'May stack with another program on the same payment', 'lnkup-marketplace' ); ?></option>
					</select>
				</td></tr>
				<tr><th><?php esc_html_e( 'Approval', 'lnkup-marketplace' ); ?></th><td><label><input type="checkbox" name="requires_approval" value="1" checked /> <?php esc_html_e( 'Requires admin approval before payout', 'lnkup-marketplace' ); ?></label></td></tr>
				<tr><th><label for="lnkup-rule-agreement"><?php esc_html_e( 'Agreement reference', 'lnkup-marketplace' ); ?></label></th><td><input id="lnkup-rule-agreement" name="agreement_ref" class="regular-text" /></td></tr>
			</table>
			<?php submit_button( __( 'Create draft', 'lnkup-marketplace' ) ); ?>
		</form>
		<?php
	}

	private static function render_ledger(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$args = array(
			'program' => sanitize_key( wp_unslash( $_GET['program'] ?? '' ) ),
			'status'  => sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ),
		);
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:enable
		$data   = LNKup_Partnerships::ledger( $args + array( 'page' => $page, 'per_page' => 25 ) );
		$export = wp_nonce_url( self::url( 'ledger', array_filter( $args ) + array( 'lnkup_partners_export' => 1 ) ), 'lnkup_partners_export' );
		?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="ledger" />
			<?php self::program_select( 'program', $args['program'] ); ?>
			<select name="status">
				<option value=""><?php esc_html_e( 'Any status', 'lnkup-marketplace' ); ?></option>
				<?php foreach ( array( 'pending', 'on_hold', 'approved', 'paid', 'reversed' ) as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $args['status'], $s ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $s ) ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button"><?php esc_html_e( 'Filter', 'lnkup-marketplace' ); ?></button>
			<a class="button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'lnkup-marketplace' ); ?></a>
		</form>
		<p class="description"><?php esc_html_e( 'City Connector rewards are paid manually after approval. Referral-invite and vendor-referral rewards are wallet credits issued automatically and are shown for reconciliation only.', 'lnkup-marketplace' ); ?></p>
		<?php if ( ! $data['items'] ) : ?>
			<p><?php esc_html_e( 'No ledger rows match these filters.', 'lnkup-marketplace' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped" data-testid="lnkup-partners-ledger">
			<thead><tr><th>ID</th><th><?php esc_html_e( 'Program', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Earner', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Payment', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Basis', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Net', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Actions', 'lnkup-marketplace' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $data['items'] as $r ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $r['id'] ); ?></td>
					<td><?php echo esc_html( $r['program_label'] ); ?><?php echo $r['rule_version'] ? ' <span class="description">v' . esc_html( (string) $r['rule_version'] ) . '</span>' : ''; ?></td>
					<td><?php echo esc_html( $r['earner'] ); ?></td>
					<td><?php echo $r['payment_id'] ? '#' . esc_html( (string) $r['payment_id'] ) : '—'; ?></td>
					<td><?php echo $r['basis'] ? esc_html( $r['basis'] . ' ' . self::money( $r['basis_amount'] ) ) : '—'; ?></td>
					<td><?php echo esc_html( self::money( $r['net_amount'] ) ); ?><?php echo $r['reversed_amount'] > 0 ? '<br /><span class="description">' . esc_html( sprintf( /* translators: %s amount */ __( '%s reversed', 'lnkup-marketplace' ), self::money( $r['reversed_amount'] ) ) ) . '</span>' : ''; ?></td>
					<td><?php echo esc_html( $r['display_status'] ); ?><?php echo $r['hold_reason'] ? '<br /><span class="description">' . esc_html( $r['hold_reason'] ) . '</span>' : ''; ?><?php echo $r['payout_reference'] ? '<br /><span class="description">' . esc_html( $r['payout_reference'] ) . '</span>' : ''; ?></td>
					<td><?php self::ledger_actions( $r ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::pager( $data['total'], $page, 25, 'ledger', array_filter( $args ) );
	}

	/**
	 * @param array<string, mixed> $r Presented reward.
	 */
	private static function ledger_actions( array $r ): void {
		if ( 'manual_payout' !== $r['settlement'] ) {
			echo '<span class="description">' . esc_html__( 'Wallet credit', 'lnkup-marketplace' ) . '</span>';
			return;
		}
		$ops = array();
		if ( 'pending' === $r['reward_status'] ) {
			if ( 'awaiting_approval' === $r['display_status'] ) {
				$ops['approve'] = __( 'Approve', 'lnkup-marketplace' );
			}
			$ops['hold'] = __( 'Hold', 'lnkup-marketplace' );
		} elseif ( 'approved' === $r['reward_status'] ) {
			$ops['mark_paid'] = __( 'Mark paid', 'lnkup-marketplace' );
			$ops['hold']      = __( 'Hold', 'lnkup-marketplace' );
		} elseif ( 'on_hold' === $r['reward_status'] ) {
			$ops['release'] = __( 'Release', 'lnkup-marketplace' );
		}
		foreach ( $ops as $op => $label ) {
			?>
			<form method="post" style="display:inline-block;margin:0 4px 4px 0;"<?php echo 'mark_paid' === $op ? ' data-lnkup-confirm="' . esc_attr__( 'Only mark paid after the payout has actually been sent.', 'lnkup-marketplace' ) . '"' : ''; ?>>
				<?php self::nonce_field(); ?>
				<input type="hidden" name="lnkup_partners_action" value="ledger" />
				<input type="hidden" name="ledger_op" value="<?php echo esc_attr( $op ); ?>" />
				<input type="hidden" name="reward_id" value="<?php echo esc_attr( (string) $r['id'] ); ?>" />
				<?php if ( 'mark_paid' === $op ) : ?>
					<input type="text" name="payout_reference" class="small-text" placeholder="<?php esc_attr_e( 'Payout ref', 'lnkup-marketplace' ); ?>" aria-label="<?php esc_attr_e( 'Payout reference', 'lnkup-marketplace' ); ?>" required />
				<?php endif; ?>
				<button class="button button-small"><?php echo esc_html( $label ); ?></button>
			</form>
			<?php
		}
	}

	private static function render_reviews(): void {
		$data = LNKup_Partner_Reviews::query( array( 'status' => 'open', 'per_page' => 50 ) );
		?>
		<p class="description"><?php esc_html_e( 'Conflicts hold commissions until you pick the winning referral; payments deferred during the conflict are credited to the winner when you resolve. Clawbacks list paid commissions whose booking was later refunded — recover or write them off outside LNKup, then record the outcome here.', 'lnkup-marketplace' ); ?></p>
		<?php if ( ! $data['items'] ) : ?>
			<p data-testid="lnkup-partners-reviews-empty"><?php esc_html_e( 'No open reviews.', 'lnkup-marketplace' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped" data-testid="lnkup-partners-reviews">
			<thead><tr><th>ID</th><th><?php esc_html_e( 'Type', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Subject', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Opened', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Resolve', 'lnkup-marketplace' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $data['items'] as $review ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $review['id'] ); ?></td>
					<td><?php echo esc_html( 'attribution_conflict' === $review['type'] ? __( 'Attribution conflict', 'lnkup-marketplace' ) : ucwords( str_replace( '_', ' ', $review['type'] ) ) ); ?></td>
					<td><?php self::review_subject( $review ); ?></td>
					<td><?php echo esc_html( (string) $review['created_at'] ); ?></td>
					<td>
						<form method="post">
							<?php self::nonce_field(); ?>
							<input type="hidden" name="lnkup_partners_action" value="review_resolve" />
							<input type="hidden" name="review_id" value="<?php echo esc_attr( (string) $review['id'] ); ?>" />
							<?php if ( 'attribution_conflict' === $review['type'] ) : ?>
								<select name="winner_referral_id" required>
									<option value=""><?php esc_html_e( 'Choose winner…', 'lnkup-marketplace' ); ?></option>
									<?php foreach ( $review['referral_ids'] as $rid ) : ?>
										<?php $ref = LNKup_Attribution_Arbiter::referral( (int) $rid ); ?>
										<?php $who = $ref ? get_userdata( (int) $ref['referrer_user_id'] ) : false; ?>
										<option value="<?php echo esc_attr( (string) $rid ); ?>"><?php echo esc_html( sprintf( '#%d %s — %s', $rid, $ref ? LNKup_Partner_Programs::label( (string) $ref['program'] ) : '', $who ? $who->display_name : '' ) ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<select name="resolution" required>
									<option value=""><?php esc_html_e( 'Outcome…', 'lnkup-marketplace' ); ?></option>
									<option value="recovered"><?php esc_html_e( 'Recovered', 'lnkup-marketplace' ); ?></option>
									<option value="written_off"><?php esc_html_e( 'Written off', 'lnkup-marketplace' ); ?></option>
									<option value="not_owed"><?php esc_html_e( 'Not owed', 'lnkup-marketplace' ); ?></option>
								</select>
							<?php endif; ?>
							<input type="text" name="notes" placeholder="<?php esc_attr_e( 'Notes', 'lnkup-marketplace' ); ?>" />
							<button class="button button-small"><?php esc_html_e( 'Resolve', 'lnkup-marketplace' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param array<string, mixed> $review Review.
	 */
	private static function review_subject( array $review ): void {
		if ( 'attribution_conflict' === $review['type'] ) {
			$user     = get_userdata( (int) $review['referred_user_id'] );
			$deferred = count( (array) ( $review['payload']['deferred_payments'] ?? array() ) );
			echo esc_html( sprintf( /* translators: 1 name, 2 claims, 3 deferred */ __( '%1$s — %2$d competing claims, %3$d deferred payments', 'lnkup-marketplace' ), $user ? $user->display_name : '#' . (int) $review['referred_user_id'], count( $review['referral_ids'] ), $deferred ) );
			return;
		}
		echo esc_html( sprintf( /* translators: 1 reward, 2 payment, 3 amount */ __( 'Reward #%1$d on payment #%2$d — %3$s owed back', 'lnkup-marketplace' ), (int) $review['reward_id'], (int) $review['payment_id'], self::money( $review['amount'] ) ) );
	}

	private static function render_connectors(): void {
		$connectors  = class_exists( 'LNKup_Org_Growth' ) ? LNKup_Org_Growth::connectors() : array();
		$territories = LNKup_Partnerships::territories();
		$by_conn     = array();
		foreach ( $territories as $t ) {
			$by_conn[ $t['connector_id'] ][] = $t;
		}
		?>
		<p>
			<?php esc_html_e( 'Approve applicants, manage referral codes, ranks and manual payouts on the existing Connectors page.', 'lnkup-marketplace' ); ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-connectors' ) ); ?>"><?php esc_html_e( 'Open Connectors', 'lnkup-marketplace' ); ?></a>
		</p>
		<h2><?php esc_html_e( 'Territories', 'lnkup-marketplace' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Territories organize responsibility. They do not block valid referrals from outside the territory.', 'lnkup-marketplace' ); ?></p>
		<?php if ( ! $connectors ) : ?>
			<p><?php esc_html_e( 'No active City Connectors yet.', 'lnkup-marketplace' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped" data-testid="lnkup-partners-territories">
			<thead><tr><th><?php esc_html_e( 'Connector', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Home city', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Assigned territories', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Assign', 'lnkup-marketplace' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $connectors as $c ) : ?>
				<tr>
					<td><?php echo esc_html( $c['name'] ); ?></td>
					<td><?php echo esc_html( $c['city'] ); ?></td>
					<td>
						<?php foreach ( (array) ( $by_conn[ $c['id'] ] ?? array() ) as $t ) : ?>
							<form method="post" style="display:inline-block;margin:0 6px 4px 0;">
								<?php self::nonce_field(); ?>
								<input type="hidden" name="lnkup_partners_action" value="territory_remove" />
								<input type="hidden" name="territory_id" value="<?php echo esc_attr( (string) $t['id'] ); ?>" />
								<span class="description"><?php echo esc_html( $t['scope_type'] . ': ' . $t['value'] ); ?></span>
								<button class="button-link" aria-label="<?php esc_attr_e( 'Remove territory', 'lnkup-marketplace' ); ?>">×</button>
							</form>
						<?php endforeach; ?>
					</td>
					<td>
						<form method="post">
							<?php self::nonce_field(); ?>
							<input type="hidden" name="lnkup_partners_action" value="territory_add" />
							<input type="hidden" name="connector_id" value="<?php echo esc_attr( (string) $c['id'] ); ?>" />
							<select name="scope_type">
								<?php foreach ( LNKup_Partnerships::TERRITORY_SCOPES as $scope ) : ?>
									<option value="<?php echo esc_attr( $scope ); ?>"><?php echo esc_html( ucfirst( $scope ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<input type="text" name="value" placeholder="<?php esc_attr_e( 'e.g. Charlotte or NC', 'lnkup-marketplace' ); ?>" required />
							<button class="button button-small"><?php esc_html_e( 'Add', 'lnkup-marketplace' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_settings(): void {
		$s = LNKup_Partnerships::settings();
		?>
		<form method="post" data-testid="lnkup-partners-settings">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="lnkup_partners_action" value="settings" />
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="lnkup-arbiter-mode"><?php esc_html_e( 'Attribution enforcement', 'lnkup-marketplace' ); ?></label></th>
					<td>
						<select id="lnkup-arbiter-mode" name="partnerships_arbiter_mode">
							<option value="shadow" <?php selected( $s['partnerships_arbiter_mode'], 'shadow' ); ?>><?php esc_html_e( 'Shadow — log what would be blocked, keep paying as before', 'lnkup-marketplace' ); ?></option>
							<option value="enforce" <?php selected( $s['partnerships_arbiter_mode'], 'enforce' ); ?>><?php esc_html_e( 'Enforce — one winning referral per account and per payment', 'lnkup-marketplace' ); ?></option>
							<option value="off" <?php selected( $s['partnerships_arbiter_mode'], 'off' ); ?>><?php esc_html_e( 'Off', 'lnkup-marketplace' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Review partner_arbiter_shadow_block entries in the financial audit log before switching to Enforce.', 'lnkup-marketplace' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="lnkup-conflict-window"><?php esc_html_e( 'Conflict window (hours)', 'lnkup-marketplace' ); ?></label></th>
					<td><input id="lnkup-conflict-window" type="number" min="0" max="720" name="partnerships_conflict_window_hours" value="<?php echo esc_attr( (string) $s['partnerships_conflict_window_hours'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Two different referrers claiming one account inside this window go to manual review. 0 = earliest always wins.', 'lnkup-marketplace' ); ?></p></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Connector lead submission', 'lnkup-marketplace' ); ?></th>
					<td><label><input type="checkbox" name="connector_leads_enabled" value="1" <?php checked( ! empty( $s['connector_leads_enabled'] ) ); ?> /> <?php esc_html_e( 'Let active City Connectors submit organizer leads into Organizer Growth', 'lnkup-marketplace' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}
}
