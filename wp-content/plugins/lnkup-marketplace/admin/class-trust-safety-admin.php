<?php
/**
 * LNKup → Trust & Safety admin console.
 *
 * Server-rendered tabs; every write goes through admin-post with a nonce and
 * the same services the REST routes use. Links to the existing Verification
 * and Financial reconciliation screens instead of duplicating them.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Trust_Safety_Admin {

	const SLUG = 'lnkup-trust-safety';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 23 );
		add_action( 'admin_post_lnkup_trust_case_action', array( __CLASS__, 'handle_case_action' ) );
		add_action( 'admin_post_lnkup_trust_claim_action', array( __CLASS__, 'handle_claim_action' ) );
		add_action( 'admin_post_lnkup_trust_organizer_action', array( __CLASS__, 'handle_organizer_action' ) );
		add_action( 'admin_post_lnkup_trust_event_authenticity', array( __CLASS__, 'handle_event_authenticity' ) );
		add_action( 'admin_post_lnkup_trust_audit_verify', array( __CLASS__, 'handle_audit_verify' ) );
		add_action( 'admin_post_lnkup_trust_settings', array( __CLASS__, 'handle_settings' ) );
	}

	public static function add_menu() {
		$open  = LNKup_Trust_Cases::open_counts();
		$badge = $open['total'] > 0 ? ' <span class="awaiting-mod">' . (int) $open['total'] . '</span>' : '';
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Trust & Safety', 'lnkup-marketplace' ),
			__( 'Trust & Safety', 'lnkup-marketplace' ) . $badge,
			LNKup_Trust::CAP,
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	private static function tabs() {
		return array(
			'overview'   => __( 'Overview', 'lnkup-marketplace' ),
			'cases'      => __( 'Cases', 'lnkup-marketplace' ),
			'organizers' => __( 'Organizer Verification', 'lnkup-marketplace' ),
			'events'     => __( 'Event Authenticity', 'lnkup-marketplace' ),
			'audit'      => __( 'Audit Log', 'lnkup-marketplace' ),
			'settings'   => __( 'Policies & Settings', 'lnkup-marketplace' ),
		);
	}

	public static function render_page() {
		if ( ! LNKup_Trust::user_can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$notice = isset( $_GET['lnkup_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['lnkup_notice'] ) ) : '';
		$error  = isset( $_GET['lnkup_error'] ) ? sanitize_text_field( wp_unslash( $_GET['lnkup_error'] ) ) : '';
		// phpcs:enable
		if ( ! isset( self::tabs()[ $tab ] ) ) {
			$tab = 'overview';
		}
		?>
		<div class="wrap" data-testid="lnkup-admin-trust-safety">
			<h1><?php esc_html_e( 'Trust & Safety', 'lnkup-marketplace' ); ?></h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<?php if ( $error ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( self::tabs() as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			switch ( $tab ) {
				case 'cases':
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$case_id = isset( $_GET['case'] ) ? absint( $_GET['case'] ) : 0;
					$case_id ? self::render_case_detail( $case_id ) : self::render_cases();
					break;
				case 'organizers':
					self::render_organizers();
					break;
				case 'events':
					self::render_events();
					break;
				case 'audit':
					self::render_audit();
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

	/* ---------- tabs ---------- */

	private static function render_overview() {
		$data  = LNKup_Trust_REST::overview_data();
		$cards = array(
			array( __( 'Open cases', 'lnkup-marketplace' ), (int) $data['cases']['total'], self::url( 'cases' ) ),
			array( __( 'Critical / elevated', 'lnkup-marketplace' ), (int) $data['cases']['by_severity']['critical'] + (int) $data['cases']['by_severity']['elevated'], self::url( 'cases', array( 'severity' => 'critical' ) ) ),
			array( __( 'Event claims to review', 'lnkup-marketplace' ), (int) $data['claims_pending'], self::url( 'events' ) ),
			array( __( 'Disputed event ownership', 'lnkup-marketplace' ), (int) $data['claims_disputed'], self::url( 'events' ) ),
			array( __( 'Organizer verification requests', 'lnkup-marketplace' ), (int) $data['organizer_reviews_pending'], self::url( 'organizers' ) ),
			array( __( 'Open Stripe chargebacks', 'lnkup-marketplace' ), $data['open_chargebacks'], self::url( 'cases', array( 'case_type' => 'chargeback' ) ) ),
			array( __( 'Open payment risk flags', 'lnkup-marketplace' ), $data['open_fraud_flags'], self::url( 'cases', array( 'case_type' => 'fraud_flag' ) ) ),
			array( __( 'Rejected payment confirmations', 'lnkup-marketplace' ), (int) $data['payment_mismatch_cases'], self::url( 'cases', array( 'case_type' => 'payment_mismatch' ) ) ),
		);
		?>
		<p><?php esc_html_e( 'Counts come from live records. Nothing here takes action on its own; every decision is made by a reviewer and recorded in the audit log.', 'lnkup-marketplace' ); ?></p>
		<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin:16px 0;">
			<?php foreach ( $cards as $card ) : ?>
				<a href="<?php echo esc_url( $card[2] ); ?>" class="card" style="margin:0;padding:16px;text-decoration:none;">
					<div style="font-size:28px;font-weight:600;color:#111;"><?php echo null === $card[1] ? '—' : esc_html( number_format_i18n( (int) $card[1] ) ); ?></div>
					<div style="color:#50575e;"><?php echo esc_html( $card[0] ); ?></div>
				</a>
			<?php endforeach; ?>
		</div>
		<h2><?php esc_html_e( 'Related tools', 'lnkup-marketplace' ); ?></h2>
		<ul>
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-verification' ) ); ?>"><?php esc_html_e( 'Vendor & document verification queue', 'lnkup-marketplace' ); ?></a></li>
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-financial-reconciliation' ) ); ?>"><?php esc_html_e( 'Financial reconciliation (payment exceptions)', 'lnkup-marketplace' ); ?></a></li>
		</ul>
		<h2><?php esc_html_e( 'Audit log integrity', 'lnkup-marketplace' ); ?></h2>
		<?php self::render_chain_status( $data['audit_chain'] ); ?>
		<h2><?php esc_html_e( 'Active safeguards', 'lnkup-marketplace' ); ?></h2>
		<?php self::render_flag_summary( $data['flags'] ); ?>
		<?php
	}

	private static function render_cases() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filters = array(
			'status'    => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'active',
			'severity'  => isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : '',
			'case_type' => isset( $_GET['case_type'] ) ? sanitize_key( wp_unslash( $_GET['case_type'] ) ) : '',
		);
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$result = LNKup_Trust_Cases::query( array_merge( $filters, array( 'limit' => 25, 'offset' => ( $page - 1 ) * 25 ) ) );
		?>
		<form method="get" style="margin:12px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="cases" />
			<select name="status">
				<option value="active" <?php selected( $filters['status'], 'active' ); ?>><?php esc_html_e( 'Active', 'lnkup-marketplace' ); ?></option>
				<?php foreach ( LNKup_Trust_Cases::STATUSES as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filters['status'], $s ); ?>><?php echo esc_html( self::humanize( $s ) ); ?></option>
				<?php endforeach; ?>
				<option value="all" <?php selected( $filters['status'], 'all' ); ?>><?php esc_html_e( 'All', 'lnkup-marketplace' ); ?></option>
			</select>
			<select name="severity">
				<option value=""><?php esc_html_e( 'Any severity', 'lnkup-marketplace' ); ?></option>
				<?php foreach ( LNKup_Trust_Cases::SEVERITIES as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filters['severity'], $s ); ?>><?php echo esc_html( self::severity_label( $s ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="case_type">
				<option value=""><?php esc_html_e( 'Any type', 'lnkup-marketplace' ); ?></option>
				<?php foreach ( LNKup_Trust_Cases::CASE_TYPES as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filters['case_type'], $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'lnkup-marketplace' ), 'secondary', '', false ); ?>
		</form>
		<?php if ( empty( $result['items'] ) ) : ?>
			<p><?php esc_html_e( 'No cases match these filters.', 'lnkup-marketplace' ); ?></p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Case', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Type', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Severity', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Subject', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Summary', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Assigned', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Updated (UTC)', 'lnkup-marketplace' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $result['items'] as $case ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( self::url( 'cases', array( 'case' => $case['id'] ) ) ); ?>"><strong><?php echo esc_html( $case['case_ref'] ); ?></strong></a></td>
						<td><?php echo esc_html( $case['case_label'] ); ?></td>
						<td><?php echo esc_html( self::severity_label( $case['severity'] ) ); ?></td>
						<td><?php echo esc_html( self::humanize( $case['status'] ) ); ?></td>
						<td><?php echo wp_kses_post( self::subject_link( $case['subject_type'], $case['subject_id'] ) ); ?></td>
						<td><?php echo esc_html( $case['summary'] ); ?></td>
						<td><?php echo esc_html( self::user_name( $case['assigned_to'] ) ); ?></td>
						<td><?php echo esc_html( $case['updated_at'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $result['total'] / 25 );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
	}

	private static function render_case_detail( $case_id ) {
		$case = LNKup_Trust_Cases::get( $case_id );
		if ( ! $case ) {
			echo '<p>' . esc_html__( 'Case not found.', 'lnkup-marketplace' ) . '</p>';
			return;
		}
		$case      = LNKup_Trust_Cases::format( $case );
		$timeline  = LNKup_Trust_Cases::timeline( $case_id );
		$next      = LNKup_Trust_Cases::TRANSITIONS[ $case['status'] ] ?? array();
		$reviewers = get_users( array( 'capability' => LNKup_Trust::CAP, 'fields' => array( 'ID', 'display_name' ), 'number' => 50 ) );
		?>
		<p><a href="<?php echo esc_url( self::url( 'cases' ) ); ?>">&larr; <?php esc_html_e( 'Back to cases', 'lnkup-marketplace' ); ?></a></p>
		<h2><?php echo esc_html( $case['case_ref'] . ' · ' . $case['case_label'] ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th><td><?php echo esc_html( self::humanize( $case['status'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Severity', 'lnkup-marketplace' ); ?></th><td><?php echo esc_html( self::severity_label( $case['severity'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Subject', 'lnkup-marketplace' ); ?></th><td><?php echo wp_kses_post( self::subject_link( $case['subject_type'], $case['subject_id'] ) ); ?></td></tr>
			<?php if ( $case['event_id'] ) : ?>
				<tr><th><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></th><td><?php echo wp_kses_post( self::subject_link( 'event', $case['event_id'] ) ); ?> — <?php echo esc_html( LNKup_Event_Authenticity::public_label( $case['event_id'] )['label'] ); ?></td></tr>
			<?php endif; ?>
			<?php if ( $case['payment_id'] ) : ?>
				<tr><th><?php esc_html_e( 'Payment', 'lnkup-marketplace' ); ?></th><td><?php echo wp_kses_post( self::subject_link( 'payment', $case['payment_id'] ) ); ?></td></tr>
			<?php endif; ?>
			<tr><th><?php esc_html_e( 'Summary', 'lnkup-marketplace' ); ?></th><td><?php echo esc_html( $case['summary'] ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Required next step', 'lnkup-marketplace' ); ?></th><td><?php echo esc_html( $case['next_step'] ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Evidence', 'lnkup-marketplace' ); ?></th><td><pre style="white-space:pre-wrap;max-height:320px;overflow:auto;background:#f6f7f7;padding:8px;"><?php echo esc_html( (string) wp_json_encode( $case['evidence'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre></td></tr>
		</table>

		<h3><?php esc_html_e( 'Actions', 'lnkup-marketplace' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:640px;">
			<?php wp_nonce_field( 'lnkup_trust_case_' . $case_id ); ?>
			<input type="hidden" name="action" value="lnkup_trust_case_action" />
			<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case_id ); ?>" />
			<p><label for="lnkup-case-note"><?php esc_html_e( 'Note (required to close a case)', 'lnkup-marketplace' ); ?></label><br />
				<textarea id="lnkup-case-note" name="note" rows="3" class="large-text"></textarea></p>
			<p>
				<button class="button" name="op" value="note"><?php esc_html_e( 'Add note', 'lnkup-marketplace' ); ?></button>
				<?php foreach ( $next as $to ) : ?>
					<button class="button <?php echo in_array( $to, LNKup_Trust_Cases::CLOSED_STATUSES, true ) ? 'button-primary' : ''; ?>" name="to" value="<?php echo esc_attr( $to ); ?>"><?php echo esc_html( self::transition_label( $to ) ); ?></button>
				<?php endforeach; ?>
			</p>
			<p>
				<select name="assignee">
					<option value="0"><?php esc_html_e( 'Unassigned', 'lnkup-marketplace' ); ?></option>
					<?php foreach ( $reviewers as $reviewer ) : ?>
						<option value="<?php echo esc_attr( (string) $reviewer->ID ); ?>" <?php selected( $case['assigned_to'], (int) $reviewer->ID ); ?>><?php echo esc_html( $reviewer->display_name ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button" name="op" value="assign"><?php esc_html_e( 'Assign', 'lnkup-marketplace' ); ?></button>
			</p>
		</form>

		<h3><?php esc_html_e( 'Timeline (UTC)', 'lnkup-marketplace' ); ?></h3>
		<table class="widefat striped">
			<tbody>
				<?php foreach ( $timeline as $row ) : ?>
					<tr>
						<td style="width:170px;"><?php echo esc_html( $row['created_at'] ); ?></td>
						<td style="width:160px;"><?php echo esc_html( self::humanize( $row['event_type'] ) ); ?><?php echo $row['to_status'] ? esc_html( ' → ' . self::humanize( $row['to_status'] ) ) : ''; ?></td>
						<td style="width:160px;"><?php echo esc_html( (int) $row['actor_user_id'] ? self::user_name( (int) $row['actor_user_id'] ) : __( 'System', 'lnkup-marketplace' ) ); ?></td>
						<td><?php echo esc_html( (string) $row['note'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_organizers() {
		$queue = LNKup_Organizer_Verification::review_queue();
		?>
		<p><?php esc_html_e( 'Payout connection (Stripe) and LNKup organization review are separate. Approve only after checking the organization\'s website, domain and public presence. Do not request identity documents that Stripe already collects.', 'lnkup-marketplace' ); ?></p>
		<?php if ( ! $queue ) : ?>
			<p><?php esc_html_e( 'No organizers are waiting for review.', 'lnkup-marketplace' ); ?></p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Organizer', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Request', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Signals', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Decision', 'lnkup-marketplace' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $queue as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_user_link( $row['user_id'] ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a><br /><small><?php echo esc_html( $row['email'] ); ?></small></td>
						<td><?php echo esc_html( $row['status_label'] ); ?></td>
						<td>
							<?php if ( ! empty( $row['request'] ) ) : ?>
								<strong><?php echo esc_html( $row['request']['organization_name'] ); ?></strong><br />
								<?php if ( ! empty( $row['request']['website'] ) ) : ?>
									<a href="<?php echo esc_url( $row['request']['website'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['request']['website'] ); ?></a><br />
								<?php endif; ?>
								<small><?php echo esc_html( $row['request']['role_at_org'] ); ?></small>
								<?php if ( ! empty( $row['request']['notes'] ) ) : ?>
									<p><?php echo esc_html( $row['request']['notes'] ); ?></p>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( $row['payout_connected'] ? __( 'Payouts active', 'lnkup-marketplace' ) : __( 'Payouts not set up', 'lnkup-marketplace' ) ); ?><br />
							<?php echo esc_html( $row['domain_verified'] ? sprintf( /* translators: %s: domain */ __( 'Controls %s', 'lnkup-marketplace' ), $row['verified_domain'] ) : __( 'No verified domain', 'lnkup-marketplace' ) ); ?>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'lnkup_trust_org_' . $row['user_id'] ); ?>
								<input type="hidden" name="action" value="lnkup_trust_organizer_action" />
								<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $row['user_id'] ); ?>" />
								<textarea name="note" rows="2" class="widefat" placeholder="<?php esc_attr_e( 'Reason (sent to the organizer for “Request info” and “Decline”)', 'lnkup-marketplace' ); ?>"></textarea>
								<button class="button button-primary" name="decision" value="approve"><?php esc_html_e( 'Approve', 'lnkup-marketplace' ); ?></button>
								<button class="button" name="decision" value="request_info"><?php esc_html_e( 'Request info', 'lnkup-marketplace' ); ?></button>
								<button class="button" name="decision" value="decline"><?php esc_html_e( 'Decline', 'lnkup-marketplace' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_events() {
		$claims = LNKup_Event_Claims::list_for_review();
		?>
		<p><?php esc_html_e( 'Claims of externally discovered events. A pending claim without an event means nothing was transferred yet. A disputed claim means another organizer already manages the listing; transfer ownership only after confirming with both parties.', 'lnkup-marketplace' ); ?></p>
		<?php if ( ! $claims ) : ?>
			<p><?php esc_html_e( 'No event claims are waiting for review.', 'lnkup-marketplace' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Listing', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Claimed by', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Link match', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'State', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Decision', 'lnkup-marketplace' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $claims as $raw ) : ?>
						<?php $claim = LNKup_Trust_REST::format_claim( $raw ); ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $claim['external_title'] ); ?></strong><br />
								<?php if ( $claim['source_url'] ) : ?>
									<a href="<?php echo esc_url( $claim['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Source listing', 'lnkup-marketplace' ); ?></a>
								<?php endif; ?>
								<?php if ( $claim['event_id'] ) : ?>
									· <?php echo wp_kses_post( self::subject_link( 'event', $claim['event_id'] ) ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $claim['organizer_name'] ); ?><br /><small><?php echo esc_html( $claim['organizer_email'] ); ?></small></td>
							<td><?php echo esc_html( self::match_label( $claim['match_type'] ) ); ?></td>
							<td><?php echo esc_html( self::humanize( $claim['status'] ) ); ?><?php echo $claim['case_id'] ? ' · <a href="' . esc_url( self::url( 'cases', array( 'case' => $claim['case_id'] ) ) ) . '">' . esc_html__( 'case', 'lnkup-marketplace' ) . '</a>' : ''; ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'lnkup_trust_claim_' . $claim['id'] ); ?>
									<input type="hidden" name="action" value="lnkup_trust_claim_action" />
									<input type="hidden" name="claim_id" value="<?php echo esc_attr( (string) $claim['id'] ); ?>" />
									<textarea name="note" rows="2" class="widefat" placeholder="<?php esc_attr_e( 'How ownership was confirmed (internal)', 'lnkup-marketplace' ); ?>"></textarea>
									<button class="button button-primary" name="op" value="approve"><?php esc_html_e( 'Approve claim', 'lnkup-marketplace' ); ?></button>
									<button class="button" name="op" value="reject"><?php esc_html_e( 'Reject claim', 'lnkup-marketplace' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Set listing status', 'lnkup-marketplace' ); ?></h2>
		<p><?php esc_html_e( 'Use for disputed or restricted listings. Existing paid bookings are not changed; restricted listings stop taking new applications and payments.', 'lnkup-marketplace' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'lnkup_trust_event_authenticity' ); ?>
			<input type="hidden" name="action" value="lnkup_trust_event_authenticity" />
			<input type="number" name="event_id" min="1" placeholder="<?php esc_attr_e( 'Event ID', 'lnkup-marketplace' ); ?>" required />
			<select name="status">
				<?php foreach ( LNKup_Event_Authenticity::STATUSES as $status ) : ?>
					<option value="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( self::humanize( $status ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" name="note" class="regular-text" placeholder="<?php esc_attr_e( 'Reason', 'lnkup-marketplace' ); ?>" required />
			<?php submit_button( __( 'Update listing', 'lnkup-marketplace' ), 'secondary', '', false ); ?>
		</form>
		<?php
	}

	private static function render_audit() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filters = array(
			'entity_type' => isset( $_GET['entity_type'] ) ? sanitize_key( wp_unslash( $_GET['entity_type'] ) ) : '',
			'entity_id'   => isset( $_GET['entity_id'] ) ? absint( $_GET['entity_id'] ) : 0,
			'action'      => isset( $_GET['audit_action'] ) ? sanitize_key( wp_unslash( $_GET['audit_action'] ) ) : '',
		);
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$rows = LNKup_Audit_Log::list_entries( array_merge( $filters, array( 'limit' => 50, 'offset' => ( $page - 1 ) * 50 ) ) );
		self::render_chain_status( get_option( 'lnkup_audit_chain_last_check', null ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0 16px;">
			<?php wp_nonce_field( 'lnkup_trust_audit_verify' ); ?>
			<input type="hidden" name="action" value="lnkup_trust_audit_verify" />
			<?php submit_button( __( 'Verify chain now', 'lnkup-marketplace' ), 'secondary', '', false ); ?>
		</form>
		<form method="get" style="margin:12px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="audit" />
			<input type="text" name="entity_type" value="<?php echo esc_attr( $filters['entity_type'] ); ?>" placeholder="<?php esc_attr_e( 'Entity type', 'lnkup-marketplace' ); ?>" />
			<input type="number" name="entity_id" value="<?php echo $filters['entity_id'] ? esc_attr( (string) $filters['entity_id'] ) : ''; ?>" placeholder="<?php esc_attr_e( 'Entity ID', 'lnkup-marketplace' ); ?>" />
			<input type="text" name="audit_action" value="<?php echo esc_attr( $filters['action'] ); ?>" placeholder="<?php esc_attr_e( 'Action', 'lnkup-marketplace' ); ?>" />
			<?php submit_button( __( 'Filter', 'lnkup-marketplace' ), 'secondary', '', false ); ?>
		</form>
		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'No audit entries match.', 'lnkup-marketplace' ); ?></p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>#</th>
					<th><?php esc_html_e( 'When (UTC)', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Action', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Actor', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Entity', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Change', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Reason', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Request', 'lnkup-marketplace' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $row['id'] ); ?></td>
						<td><?php echo esc_html( $row['created_at'] ); ?></td>
						<td><code><?php echo esc_html( $row['action'] ); ?></code><?php echo 'success' !== $row['result'] ? ' <em>(' . esc_html( $row['result'] ) . ')</em>' : ''; ?></td>
						<td><?php echo esc_html( (int) $row['actor_user_id'] ? self::user_name( (int) $row['actor_user_id'] ) : __( 'System', 'lnkup-marketplace' ) ); ?></td>
						<td><?php echo esc_html( $row['entity_type'] . ' #' . $row['entity_id'] ); ?></td>
						<td><small><?php echo esc_html( self::short_json( $row['prev_state'] ) ); ?><?php echo $row['prev_state'] ? ' → ' : ''; ?><?php echo esc_html( self::short_json( $row['new_state'] ) ); ?></small></td>
						<td><?php echo esc_html( $row['reason'] ); ?></td>
						<td><code><?php echo esc_html( substr( (string) $row['request_id'], 0, 8 ) ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page - 1 ) ); ?>">&larr; <?php esc_html_e( 'Newer', 'lnkup-marketplace' ); ?></a>
			<?php endif; ?>
			<?php if ( count( $rows ) === 50 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page + 1 ) ); ?>"><?php esc_html_e( 'Older', 'lnkup-marketplace' ); ?> &rarr;</a>
			<?php endif; ?>
		</p>
		<?php
	}

	private static function render_settings() {
		$settings = lnkup_get_settings();
		$mode     = LNKup_Trust::claim_review_mode();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:820px;">
			<?php wp_nonce_field( 'lnkup_trust_settings' ); ?>
			<input type="hidden" name="action" value="lnkup_trust_settings" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Event claim review', 'lnkup-marketplace' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="trust_claim_review_mode" value="off" <?php checked( $mode, 'off' ); ?> /> <?php esc_html_e( 'Off — legacy behaviour, no claim cases', 'lnkup-marketplace' ); ?></label><br />
							<label><input type="radio" name="trust_claim_review_mode" value="log" <?php checked( $mode, 'log' ); ?> /> <?php esc_html_e( 'Log — claims go through, mismatches and conflicts open cases (default)', 'lnkup-marketplace' ); ?></label><br />
							<label><input type="radio" name="trust_claim_review_mode" value="enforce" <?php checked( $mode, 'enforce' ); ?> /> <?php esc_html_e( 'Enforce — claim links used by a different email wait for review before anything is created', 'lnkup-marketplace' ); ?></label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'A second organizer claiming an already-managed event always opens a dispute case and never transfers the listing.', 'lnkup-marketplace' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Publish gate', 'lnkup-marketplace' ); ?></th>
					<td>
						<label><input type="checkbox" name="trust_publish_gate" value="1" <?php checked( ! empty( $settings['trust_publish_gate'] ) ); ?> /> <?php esc_html_e( 'Only organizer-managed listings appear in Find Events and accept applications and payments', 'lnkup-marketplace' ); ?></label>
						<p class="description"><?php esc_html_e( 'Off by default. Listings created before this release and organizer-created events are already organizer-managed. Restricted listings are always blocked.', 'lnkup-marketplace' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-verify claims', 'lnkup-marketplace' ); ?></th>
					<td>
						<label><input type="checkbox" name="trust_auto_promote_claims" value="1" <?php checked( ! empty( $settings['trust_auto_promote_claims'] ) ); ?> /> <?php esc_html_e( 'Mark a claim organizer-managed automatically when the claim email or domain matches and payouts are active', 'lnkup-marketplace' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Organization teams', 'lnkup-marketplace' ); ?></th>
					<td>
						<label><input type="checkbox" name="trust_org_teams" value="1" <?php checked( ! empty( $settings['trust_org_teams'] ) ); ?> /> <?php esc_html_e( 'Let organizers invite team members with scoped access to all events, one tour, or one event', 'lnkup-marketplace' ); ?></label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save safeguards', 'lnkup-marketplace' ) ); ?>
		</form>
		<p><?php esc_html_e( 'Changes to these settings are recorded in the audit log.', 'lnkup-marketplace' ); ?></p>
		<?php
	}

	/* ---------- handlers ---------- */

	public static function handle_case_action() {
		$case_id = isset( $_POST['case_id'] ) ? absint( $_POST['case_id'] ) : 0;
		self::guard( 'lnkup_trust_case_' . $case_id );
		$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$op     = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$to     = isset( $_POST['to'] ) ? sanitize_key( wp_unslash( $_POST['to'] ) ) : '';
		if ( 'assign' === $op ) {
			$result = LNKup_Trust_Cases::assign( $case_id, isset( $_POST['assignee'] ) ? absint( $_POST['assignee'] ) : 0 );
		} elseif ( 'note' === $op ) {
			$result = LNKup_Trust_Cases::add_note( $case_id, $note );
		} else {
			$result = LNKup_Trust_Cases::transition( $case_id, $to, $note );
		}
		self::redirect_result( $result, self::url( 'cases', array( 'case' => $case_id ) ), __( 'Case updated.', 'lnkup-marketplace' ) );
	}

	public static function handle_claim_action() {
		$claim_id = isset( $_POST['claim_id'] ) ? absint( $_POST['claim_id'] ) : 0;
		self::guard( 'lnkup_trust_claim_' . $claim_id );
		$note = isset( $_POST['note'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) ) : '';
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		if ( mb_strlen( $note ) < 5 ) {
			$result = new WP_Error( 'lnkup_note_required', __( 'Add a short note explaining how ownership was confirmed.', 'lnkup-marketplace' ) );
		} else {
			$result = 'approve' === $op ? LNKup_Event_Claims::approve( $claim_id, $note ) : LNKup_Event_Claims::reject( $claim_id, $note );
		}
		self::redirect_result( $result, self::url( 'events' ), __( 'Claim decision recorded.', 'lnkup-marketplace' ) );
	}

	public static function handle_organizer_action() {
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		self::guard( 'lnkup_trust_org_' . $user_id );
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$note     = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$result   = LNKup_Organizer_Verification::decide( $user_id, $decision, $note, get_current_user_id() );
		self::redirect_result( $result, self::url( 'organizers' ), __( 'Organizer decision recorded.', 'lnkup-marketplace' ) );
	}

	public static function handle_event_authenticity() {
		self::guard( 'lnkup_trust_event_authenticity' );
		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$note     = isset( $_POST['note'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['note'] ) ) ) : '';
		$result   = mb_strlen( $note ) < 5
			? new WP_Error( 'lnkup_note_required', __( 'Add a short reason.', 'lnkup-marketplace' ) )
			: LNKup_Event_Authenticity::set_status( $event_id, $status, $note );
		self::redirect_result( $result, self::url( 'events' ), __( 'Listing status updated.', 'lnkup-marketplace' ) );
	}

	public static function handle_audit_verify() {
		self::guard( 'lnkup_trust_audit_verify' );
		$result = LNKup_Audit_Log::verify_chain();
		update_option( 'lnkup_audit_chain_last_check', array_merge( $result, array( 'at' => current_time( 'mysql', true ) ) ), false );
		LNKup_Audit_Log::log( 'audit_chain_verified', 'audit_log', 0, array( 'new' => $result, 'result' => $result['ok'] ? 'success' : 'failure' ) );
		$message = $result['ok']
			/* translators: %d: rows */
			? sprintf( __( 'Audit chain intact (%d rows checked).', 'lnkup-marketplace' ), (int) $result['checked'] )
			/* translators: %d: row id */
			: sprintf( __( 'Audit chain broken at row %d.', 'lnkup-marketplace' ), (int) $result['broken_id'] );
		self::redirect_result( $result['ok'] ? true : new WP_Error( 'chain', $message ), self::url( 'audit' ), $message );
	}

	public static function handle_settings() {
		self::guard( 'lnkup_trust_settings' );
		$settings = get_option( 'lnkup_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$before   = array_intersect_key( lnkup_get_settings(), array_flip( array( 'trust_claim_review_mode', 'trust_publish_gate', 'trust_auto_promote_claims', 'trust_org_teams' ) ) );
		$mode     = isset( $_POST['trust_claim_review_mode'] ) ? sanitize_key( wp_unslash( $_POST['trust_claim_review_mode'] ) ) : 'log';
		$settings['trust_claim_review_mode']   = in_array( $mode, LNKup_Trust::CLAIM_MODES, true ) ? $mode : 'log';
		$settings['trust_publish_gate']        = ! empty( $_POST['trust_publish_gate'] );
		$settings['trust_auto_promote_claims'] = ! empty( $_POST['trust_auto_promote_claims'] );
		$settings['trust_org_teams']           = ! empty( $_POST['trust_org_teams'] );
		update_option( 'lnkup_settings', $settings, false );
		$after = array_intersect_key( $settings, $before );
		LNKup_Audit_Log::log( 'trust_settings_changed', 'settings', 0, array( 'prev' => $before, 'new' => $after ) );
		if ( (bool) $before['trust_publish_gate'] !== (bool) $after['trust_publish_gate'] && class_exists( 'LNKup_Marketplace_Index' ) ) {
			wp_schedule_single_event( time() + 5, 'lnkup_trust_rebuild_index' );
		}
		self::redirect_result( true, self::url( 'settings' ), __( 'Safeguards saved.', 'lnkup-marketplace' ) );
	}

	/* ---------- helpers ---------- */

	private static function guard( $nonce_action ) {
		if ( ! LNKup_Trust::user_can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	private static function redirect_result( $result, $url, $success_message ) {
		if ( is_wp_error( $result ) ) {
			$url = add_query_arg( 'lnkup_error', rawurlencode( $result->get_error_message() ), $url );
		} else {
			$url = add_query_arg( 'lnkup_notice', rawurlencode( $success_message ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	private static function url( $tab, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	private static function render_chain_status( $chain ) {
		if ( ! is_array( $chain ) ) {
			echo '<p>' . esc_html__( 'The audit chain has not been checked yet. It is verified daily.', 'lnkup-marketplace' ) . '</p>';
			return;
		}
		if ( ! empty( $chain['ok'] ) ) {
			/* translators: 1: rows, 2: time */
			echo '<p>' . esc_html( sprintf( __( 'Intact: %1$d rows checked at %2$s UTC.', 'lnkup-marketplace' ), (int) $chain['checked'], (string) ( $chain['at'] ?? '' ) ) ) . '</p>';
			return;
		}
		/* translators: 1: row id, 2: reason */
		echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( __( 'Broken at row %1$d (%2$s). A critical case was opened.', 'lnkup-marketplace' ), (int) $chain['broken_id'], (string) $chain['reason'] ) ) . '</p></div>';
	}

	private static function render_flag_summary( $flags ) {
		echo '<ul>';
		/* translators: %s: mode */
		echo '<li>' . esc_html( sprintf( __( 'Event claim review: %s', 'lnkup-marketplace' ), ucfirst( (string) $flags['trust_claim_review_mode'] ) ) ) . '</li>';
		echo '<li>' . esc_html( $flags['trust_publish_gate'] ? __( 'Publish gate: on', 'lnkup-marketplace' ) : __( 'Publish gate: off', 'lnkup-marketplace' ) ) . '</li>';
		echo '<li>' . esc_html( $flags['trust_auto_promote_claims'] ? __( 'Auto-verify matching claims: on', 'lnkup-marketplace' ) : __( 'Auto-verify matching claims: off', 'lnkup-marketplace' ) ) . '</li>';
		echo '<li>' . esc_html( $flags['trust_org_teams'] ? __( 'Organization teams: on', 'lnkup-marketplace' ) : __( 'Organization teams: off', 'lnkup-marketplace' ) ) . '</li>';
		echo '</ul>';
	}

	private static function subject_link( $type, $id ) {
		$id = absint( $id );
		if ( ! $id ) {
			return '—';
		}
		if ( 'user' === $type ) {
			return '<a href="' . esc_url( get_edit_user_link( $id ) ) . '">' . esc_html( self::user_name( $id ) ) . '</a>';
		}
		if ( in_array( $type, array( 'event', 'payment', 'application' ), true ) && get_post( $id ) ) {
			return '<a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ?: ucfirst( $type ) . ' #' . $id ) . '</a>';
		}
		return esc_html( self::humanize( (string) $type ) . ' #' . $id );
	}

	private static function user_name( $user_id ) {
		if ( ! $user_id ) {
			return '—';
		}
		$user = get_userdata( (int) $user_id );
		return $user ? $user->display_name : '#' . (int) $user_id;
	}

	private static function severity_label( $severity ) {
		$labels = array(
			'low'      => __( 'Low', 'lnkup-marketplace' ),
			'review'   => __( 'Review suggested', 'lnkup-marketplace' ),
			'elevated' => __( 'Elevated', 'lnkup-marketplace' ),
			'critical' => __( 'Critical', 'lnkup-marketplace' ),
		);
		return $labels[ $severity ] ?? $severity;
	}

	private static function transition_label( $to ) {
		$labels = array(
			'in_review'                => __( 'Start review', 'lnkup-marketplace' ),
			'info_requested'           => __( 'Request information', 'lnkup-marketplace' ),
			'awaiting_response'        => __( 'Awaiting response', 'lnkup-marketplace' ),
			'resolved'                 => __( 'Resolve', 'lnkup-marketplace' ),
			'dismissed_false_positive' => __( 'Dismiss as false positive', 'lnkup-marketplace' ),
		);
		return $labels[ $to ] ?? self::humanize( $to );
	}

	private static function match_label( $match ) {
		$labels = array(
			'email'  => __( 'Same email as invite', 'lnkup-marketplace' ),
			'domain' => __( 'Same organization domain', 'lnkup-marketplace' ),
			'none'   => __( 'Different email', 'lnkup-marketplace' ),
		);
		return $labels[ $match ] ?? $match;
	}

	private static function humanize( $slug ) {
		return ucfirst( str_replace( '_', ' ', (string) $slug ) );
	}

	private static function short_json( $json ) {
		$json = (string) $json;
		return strlen( $json ) > 140 ? substr( $json, 0, 140 ) . '…' : $json;
	}
}

add_action(
	'lnkup_trust_rebuild_index',
	static function () {
		if ( class_exists( 'LNKup_Marketplace_Index' ) ) {
			LNKup_Marketplace_Index::rebuild_all();
		}
	}
);
