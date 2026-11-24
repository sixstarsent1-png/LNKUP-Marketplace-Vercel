<?php
/**
 * Organizer event workspace (Organizer Experience OS 2.0): persistent header, tabs with
 * legacy key aliases, Overview, Inventory, Applications, Vendors, Money and Marketing.
 *
 * Detail routes (roster, ops, scan, edit) stay in LNKup_Shortcodes.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Event workspace renderer.
 */
class LNKup_Organizer_Workspace_View {

	/**
	 * Old tab keys that keep working.
	 */
	const TAB_ALIASES = array(
		'slots'      => 'inventory',
		'requests'   => 'applications',
		'financials' => 'money',
	);

	/**
	 * Canonical tab key for a requested key.
	 *
	 * @param string $tab Requested key.
	 */
	public static function canonical_tab( string $tab ): string {
		return self::TAB_ALIASES[ $tab ] ?? $tab;
	}

	/**
	 * Render the workspace for one owned event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function render( int $event_id ): string {
		$event = get_post( $event_id );
		if ( ! $event || 'lnkup_event' !== $event->post_type || ! LNKup_Capabilities::organizer_owns_event( $event_id ) ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html__( 'You cannot open this event.', 'lnkup-marketplace' ) . '</p>';
		}
		$summary        = LNKup_Organizer_Presentation::event_summary( $event_id );
		$ops_tabs       = LNKup_Shortcodes::ops_tabs( $event_id );
		$can_financials = LNKup_Capabilities::can_manage_event_financials( $event_id );
		$tab            = isset( $_GET['tab'] ) ? self::canonical_tab( sanitize_key( wp_unslash( $_GET['tab'] ) ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$allowed        = array_merge( array( 'overview', 'inventory', 'applications', 'vendors', 'marketing' ), $can_financials ? array( 'money' ) : array(), array_keys( $ops_tabs ) );
		if ( ! in_array( $tab, $allowed, true ) ) {
			$tab = 'overview';
		}
		ob_start();
		?>
		<div class="lnkup-event-workspace-page oos-workspace" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>" data-tab="<?php echo esc_attr( $tab ); ?>">
			<?php echo self::header( $summary, $tab ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo self::tabs( $summary, $tab, $ops_tabs, $can_financials ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php
			if ( isset( $ops_tabs[ $tab ] ) ) {
				echo LNKup_Shortcodes::render_ops_panel( $event_id, $tab ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				switch ( $tab ) {
					case 'inventory':
						echo self::inventory( $summary ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						break;
					case 'applications':
					case 'vendors':
						echo self::applications( $summary, $tab ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						break;
					case 'money':
						echo LNKup_Shortcodes::render_workspace_financials( $event_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						break;
					case 'marketing':
						echo self::marketing( $summary ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						break;
					default:
						echo self::overview( $summary ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						break;
				}
			}
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Compact persistent header: identity, switcher, one primary action, overflow menu.
	 *
	 * @param array  $summary Event summary.
	 * @param string $tab     Current tab.
	 */
	private static function header( array $summary, string $tab ): string {
		$event_id = (int) $summary['id'];
		$dash     = lnkup_page_url_helper( 'organizer-dashboard' );
		$action   = LNKup_Organizer_Dashboard_Views::event_primary_action( $summary );
		$items    = array(
			array( 'label' => __( 'Edit event details', 'lnkup-marketplace' ), 'href' => add_query_arg( array( 'event' => $event_id, 'edit' => 1 ), $dash ) ),
		);
		if ( '' !== $summary['public_url'] ) {
			$items[] = array( 'label' => __( 'Preview public page', 'lnkup-marketplace' ), 'href' => $summary['public_url'] );
			$items[] = array( 'label' => __( 'Copy share link', 'lnkup-marketplace' ), 'attrs' => 'data-copy-url="' . esc_url( $summary['public_url'] ) . '"' );
		}
		$items[] = array( 'label' => __( 'Export vendor contacts', 'lnkup-marketplace' ), 'href' => LNKup_Shortcodes::organizer_vendor_export_url( $event_id ) );
		$items[] = array( 'label' => __( 'Duplicate event', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'events', array( 'duplicate' => 1 ) ) );

		/** This filter is documented in public/class-shortcodes.php */
		$extra = (string) apply_filters( 'lnkup_event_workspace_actions', '', $event_id );
		$extra = self::rebook_button( $summary ) . $extra;

		$switch = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => 60,
				'fields'         => 'ids',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'meta_key'       => '_lnkup_event_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_organizer_user_id', 'value' => (string) get_current_user_id() ),
				),
			)
		);
		ob_start();
		?>
		<header class="oos-ws-head" data-testid="lnkup-event-workspace-header">
			<div class="oos-ws-head__row">
				<?php echo LNKup_OS_UI::event_identity( $summary, array( 'size' => 'lg', 'heading' => 'h1' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="oos-ws-head__tools">
					<?php if ( count( $switch ) > 1 ) : ?>
						<label class="vos-sr" for="lnkup-event-switcher"><?php esc_html_e( 'Switch event', 'lnkup-marketplace' ); ?></label>
						<select id="lnkup-event-switcher" class="lnkup-event-switcher" data-oos-switcher>
							<?php foreach ( $switch as $sid ) : ?>
								<?php
								$date  = (string) get_post_meta( (int) $sid, '_lnkup_event_date', true );
								$label = LNKup_Organizer_Presentation::title( (int) $sid ) . ( $date ? ' — ' . date_i18n( 'M j, Y', (int) strtotime( $date ) ) : '' );
								?>
								<option value="<?php echo esc_url( add_query_arg( array( 'event' => (int) $sid, 'tab' => $tab ), $dash ) ); ?>"<?php selected( (int) $sid, $event_id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
					<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo LNKup_OS_UI::menu( __( 'More', 'lnkup-marketplace' ), $items, array( 'class' => 'lnkup-btn lnkup-btn--outline', 'testid' => 'lnkup-event-overflow', 'aria_label' => __( 'More event actions', 'lnkup-marketplace' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<a class="lnkup-btn lnkup-btn--primary" href="<?php echo esc_url( $action['href'] ); ?>" data-testid="lnkup-event-primary-action"><?php echo esc_html( $action['label'] ); ?></a>
				</div>
			</div>
		</header>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * "Rebook vendors" for a past event with confirmed vendors.
	 *
	 * @param array $summary Event summary.
	 */
	private static function rebook_button( array $summary ): string {
		if ( 'completed' !== $summary['chronology'] || (int) $summary['applications']['confirmed'] < 1 ) {
			return '';
		}
		$apps   = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_event_id', 'value' => (string) $summary['id'] ),
					array( 'key' => '_lnkup_application_status', 'value' => array( 'paid', 'completed' ), 'compare' => 'IN' ),
				),
			)
		);
		$rebook = array();
		foreach ( $apps as $app_id ) {
			$vendor_id            = (int) get_post_meta( (int) $app_id, '_lnkup_vendor_user_id', true );
			$rebook[ $vendor_id ] = array(
				'vendor_user_id' => $vendor_id,
				'business_name'  => LNKup_Organizer_Presentation::vendor_name( (int) $app_id ),
			);
		}
		return '<button type="button" class="lnkup-btn lnkup-btn--outline" data-rebook-vendors="' . esc_attr( (string) wp_json_encode( array_values( $rebook ) ) ) . '" data-testid="lnkup-rebook-vendors">' . esc_html( sprintf( /* translators: %d: vendors */ _n( 'Rebook %d vendor', 'Rebook %d vendors', count( $rebook ), 'lnkup-marketplace' ), count( $rebook ) ) ) . '</button>';
	}

	/**
	 * Workspace tabs. Operations, Check-in and Settings open their detail routes.
	 *
	 * @param array                $summary        Event summary.
	 * @param string               $tab            Current tab.
	 * @param array<string,string> $ops_tabs       Ops v2 tabs.
	 * @param bool                 $can_financials Financial access.
	 */
	private static function tabs( array $summary, string $tab, array $ops_tabs, bool $can_financials ): string {
		$event_id = (int) $summary['id'];
		$dash     = lnkup_page_url_helper( 'organizer-dashboard' );
		$base     = add_query_arg( 'event', $event_id, $dash );
		$tabs     = array(
			array( 'id' => 'overview', 'label' => __( 'Overview', 'lnkup-marketplace' ), 'href' => $base ),
			array( 'id' => 'inventory', 'label' => __( 'Inventory', 'lnkup-marketplace' ), 'href' => add_query_arg( 'tab', 'inventory', $base ) ),
			array( 'id' => 'applications', 'label' => __( 'Applications', 'lnkup-marketplace' ), 'href' => add_query_arg( 'tab', 'applications', $base ), 'badge' => (int) $summary['applications']['pending'] ),
			array( 'id' => 'vendors', 'label' => __( 'Confirmed vendors', 'lnkup-marketplace' ), 'href' => add_query_arg( 'tab', 'vendors', $base ) ),
		);
		if ( $can_financials ) {
			$tabs[] = array( 'id' => 'money', 'label' => __( 'Money', 'lnkup-marketplace' ), 'href' => add_query_arg( 'tab', 'money', $base ) );
		}
		$tabs[] = array( 'id' => 'operations', 'label' => __( 'Operations', 'lnkup-marketplace' ), 'href' => add_query_arg( array( 'event' => $event_id, 'ops' => 1 ), $dash ) );
		foreach ( $ops_tabs as $key => $label ) {
			$tabs[] = array( 'id' => $key, 'label' => $label, 'href' => add_query_arg( 'tab', $key, $base ) );
		}
		$tabs[] = array( 'id' => 'checkin', 'label' => __( 'Check-in', 'lnkup-marketplace' ), 'href' => add_query_arg( array( 'event' => $event_id, 'scan' => 1 ), $dash ) );
		$tabs[] = array( 'id' => 'marketing', 'label' => __( 'Marketing', 'lnkup-marketplace' ), 'href' => add_query_arg( 'tab', 'marketing', $base ) );
		$tabs[] = array( 'id' => 'settings', 'label' => __( 'Settings', 'lnkup-marketplace' ), 'href' => add_query_arg( array( 'event' => $event_id, 'edit' => 1 ), $dash ) );
		return lnkup_dash_tabs(
			$tabs,
			$tab,
			array(
				'variant' => 'underline',
				'class'   => 'lnkup-event-workspace__tabs',
				'testid'  => 'lnkup-event-workspace-tabs',
				'label'   => __( 'Event sections', 'lnkup-marketplace' ),
			)
		);
	}

	/**
	 * Overview: application funnel, capacity, money, readiness, deadlines.
	 *
	 * @param array $summary Event summary.
	 */
	private static function overview( array $summary ): string {
		$event_id  = (int) $summary['id'];
		$apps      = $summary['applications'];
		$app_url   = static function ( string $filter ) use ( $event_id ): string {
			return lnkup_organizer_view_url( 'applications', array_filter( array( 'filter' => $filter, 'event_filter' => $event_id ) ) );
		};
		$funnel    = array(
			array( __( 'Applied', 'lnkup-marketplace' ), (int) $apps['total'], $app_url( '' ) ),
			array( __( 'To review', 'lnkup-marketplace' ), (int) $apps['pending'], $app_url( 'pending' ) ),
			array( __( 'Info requested', 'lnkup-marketplace' ), (int) $apps['info_requested'], $app_url( 'needs_info' ) ),
			array( __( 'Approved, unpaid', 'lnkup-marketplace' ), (int) $apps['held'], $app_url( 'unpaid' ) ),
			array( __( 'Confirmed', 'lnkup-marketplace' ), (int) $apps['confirmed'], $app_url( 'paid' ) ),
			array( __( 'Waitlisted', 'lnkup-marketplace' ), (int) $apps['waitlisted'], $app_url( 'waitlisted' ) ),
			array( __( 'Declined', 'lnkup-marketplace' ), (int) $apps['declined'], $app_url( 'rejected' ) ),
		);
		$vendor_ready = class_exists( 'LNKup_Vendor_Readiness' ) ? LNKup_Vendor_Readiness::event_readiness_summary( $event_id ) : array();
		$org_ready    = class_exists( 'LNKup_Event_Readiness' ) ? LNKup_Event_Readiness::evaluate( $event_id ) : array();
		$money        = $summary['money'] ?? array( 'collected_gross' => 0, 'outstanding' => 0, 'paid_bookings' => 0 );
		$base         = add_query_arg( 'event', $event_id, lnkup_page_url_helper( 'organizer-dashboard' ) );
		ob_start();
		?>
		<div class="oos-ws-grid" data-testid="lnkup-event-command">
			<section class="vos-card" aria-labelledby="oos-ws-funnel" data-testid="lnkup-event-funnel">
				<?php echo LNKup_OS_UI::section_head( __( 'Applications', 'lnkup-marketplace' ), __( 'Open applications', 'lnkup-marketplace' ), add_query_arg( 'tab', 'applications', $base ), 'oos-ws-funnel' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<ul class="oos-funnel">
					<?php foreach ( $funnel as $stage ) : ?>
						<li><a href="<?php echo esc_url( $stage[2] ); ?>"><span class="oos-funnel__n"><?php echo esc_html( (string) $stage[1] ); ?></span><span class="oos-funnel__label"><?php echo esc_html( $stage[0] ); ?></span></a></li>
					<?php endforeach; ?>
				</ul>
			</section>
			<section class="vos-card" aria-labelledby="oos-ws-capacity" data-testid="lnkup-event-capacity">
				<?php echo LNKup_OS_UI::section_head( __( 'Capacity', 'lnkup-marketplace' ), __( 'Edit inventory', 'lnkup-marketplace' ), add_query_arg( 'tab', 'inventory', $base ), 'oos-ws-capacity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( (int) $summary['capacity']['booth_types'] < 1 ) : ?>
					<?php echo LNKup_OS_UI::empty_state( __( 'No booth types yet', 'lnkup-marketplace' ), __( 'Add at least one booth type so vendors can apply.', 'lnkup-marketplace' ), __( 'Add booth type', 'lnkup-marketplace' ), add_query_arg( 'tab', 'inventory', $base ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php else : ?>
					<?php echo LNKup_OS_UI::capacity_bar( $summary['capacity'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<p class="vos-footnote"><?php echo esc_html( sprintf( /* translators: 1: total spaces, 2: booth types */ _n( '%1$d spaces across %2$d booth type. Held spaces belong to approved vendors who have not paid yet.', '%1$d spaces across %2$d booth types. Held spaces belong to approved vendors who have not paid yet.', (int) $summary['capacity']['booth_types'], 'lnkup-marketplace' ), (int) $summary['capacity']['total'], (int) $summary['capacity']['booth_types'] ) ); ?></p>
				<?php endif; ?>
			</section>
			<section class="vos-card" aria-labelledby="oos-ws-money" data-testid="lnkup-event-money">
				<?php echo LNKup_OS_UI::section_head( __( 'Money for this event', 'lnkup-marketplace' ), LNKup_Capabilities::can_manage_event_financials( $event_id ) ? __( 'Event money', 'lnkup-marketplace' ) : '', add_query_arg( 'tab', 'money', $base ), 'oos-ws-money' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<dl class="vos-kv">
					<dt><?php esc_html_e( 'Gross booth fees collected', 'lnkup-marketplace' ); ?></dt>
					<dd><?php echo esc_html( LNKup_OS_UI::money( (float) $money['collected_gross'] ) ); ?></dd>
					<dt><?php esc_html_e( 'Outstanding (approved, unpaid)', 'lnkup-marketplace' ); ?></dt>
					<dd><?php echo esc_html( LNKup_OS_UI::money( (float) $money['outstanding'] ) ); ?></dd>
					<dt><?php esc_html_e( 'Paid bookings', 'lnkup-marketplace' ); ?></dt>
					<dd><?php echo esc_html( (string) (int) $money['paid_bookings'] ); ?></dd>
				</dl>
				<p class="vos-footnote"><?php esc_html_e( 'Gross amounts vendors paid for this event, before LNKup fees and refunds. Money shows your net totals.', 'lnkup-marketplace' ); ?></p>
			</section>
			<section class="vos-card" aria-labelledby="oos-ws-ready" data-testid="lnkup-event-readiness">
				<?php echo LNKup_OS_UI::section_head( __( 'Readiness', 'lnkup-marketplace' ), '', '', 'oos-ws-ready' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<dl class="vos-kv">
					<dt><?php esc_html_e( 'Vendors', 'lnkup-marketplace' ); ?></dt>
					<dd>
						<?php
						echo empty( $vendor_ready['vendor_count'] )
							? esc_html__( 'Appears once you approve vendors', 'lnkup-marketplace' )
							: esc_html( sprintf( /* translators: 1: ready, 2: booked, 3: at risk */ __( '%1$d of %2$d booked vendors ready · %3$d at risk', 'lnkup-marketplace' ), (int) $vendor_ready['ready_count'], (int) $vendor_ready['vendor_count'], (int) $vendor_ready['at_risk_count'] ) );
						?>
					</dd>
					<dt><?php esc_html_e( 'Your event setup', 'lnkup-marketplace' ); ?></dt>
					<dd><?php echo empty( $org_ready ) ? '—' : esc_html( sprintf( /* translators: %d: percent */ __( '%d%% of checklist done', 'lnkup-marketplace' ), (int) $org_ready['percent'] ) ); ?></dd>
				</dl>
				<?php if ( ! empty( $org_ready['next_actions'] ) ) : ?>
					<ul class="vos-timeline">
						<?php foreach ( $org_ready['next_actions'] as $next ) : ?>
							<li><a href="<?php echo esc_url( (string) $next['action_url'] ); ?>"><?php echo esc_html( (string) $next['label'] ); ?></a><span class="vos-timeline__meta"><?php echo esc_html( (string) $next['evidence'] ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>
			<section class="vos-card" aria-labelledby="oos-ws-deadlines" data-testid="lnkup-event-deadlines">
				<?php echo LNKup_OS_UI::section_head( __( 'Upcoming dates', 'lnkup-marketplace' ), '', '', 'oos-ws-deadlines' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo self::deadlines( $summary ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</section>
			<?php if ( class_exists( 'LNKup_Weather_Render' ) && class_exists( 'LNKup_Weather_Service' ) && 'completed' !== $summary['chronology'] ) : ?>
				<section class="vos-card" aria-label="<?php esc_attr_e( 'Weather', 'lnkup-marketplace' ); ?>" data-testid="lnkup-event-weather">
					<?php echo LNKup_Weather_Render::operations_panel( LNKup_Weather_Service::for_event( $event_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</section>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Application deadlines, load-in windows and the event date, soonest first.
	 *
	 * @param array $summary Event summary.
	 */
	private static function deadlines( array $summary ): string {
		$event_id = (int) $summary['id'];
		$today    = current_time( 'Y-m-d' );
		$items    = array();
		foreach ( LNKup_Organizer_Presentation::opportunity_ids( $event_id ) as $opp_id ) {
			$deadline = (string) get_post_meta( $opp_id, '_lnkup_application_deadline', true );
			if ( '' !== $deadline && substr( $deadline, 0, 10 ) >= $today ) {
				$items[] = array( substr( $deadline, 0, 10 ), sprintf( /* translators: %s: booth type */ __( 'Applications close · %s', 'lnkup-marketplace' ), lnkup_plain_title( $opp_id ) ) );
			}
		}
		if ( class_exists( 'LNKup_Loadin_Schedule' ) ) {
			foreach ( (array) LNKup_Loadin_Schedule::get_windows( $event_id ) as $window ) {
				$start = (string) ( $window['start_at'] ?? '' );
				if ( '' !== $start && substr( $start, 0, 10 ) >= $today ) {
					$items[] = array( substr( $start, 0, 10 ), sprintf( /* translators: %s: window label */ __( 'Load-in · %s', 'lnkup-marketplace' ), (string) ( $window['label'] ?? '' ) ) );
				}
			}
		}
		if ( '' !== $summary['date'] && $summary['date'] >= $today ) {
			$items[] = array( $summary['date'], __( 'Event day', 'lnkup-marketplace' ) );
		}
		if ( ! $items ) {
			return '<p class="lnkup-muted">' . esc_html__( 'No upcoming dates.', 'lnkup-marketplace' ) . '</p>';
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return strcmp( $a[0], $b[0] );
			}
		);
		$html = '<ol class="vos-timeline">';
		foreach ( array_slice( $items, 0, 6 ) as $item ) {
			$html .= '<li><span>' . esc_html( $item[1] ) . '</span><time datetime="' . esc_attr( $item[0] ) . '">' . esc_html( (string) date_i18n( 'M j', (int) strtotime( $item[0] ) ) ) . '</time></li>';
		}
		return $html . '</ol>';
	}

	/**
	 * Inventory: booth types table, edit drawer per row, add drawer.
	 *
	 * @param array $summary Event summary.
	 */
	private static function inventory( array $summary ): string {
		$event_id = (int) $summary['id'];
		$opps     = LNKup_Organizer_Presentation::opportunity_ids( $event_id );
		$preview  = (string) $summary['public_url'];
		$columns  = array(
			'type'      => array( 'label' => __( 'Booth type', 'lnkup-marketplace' ), 'lead' => true ),
			'category'  => array( 'label' => __( 'Category', 'lnkup-marketplace' ) ),
			'price'     => array( 'label' => __( 'Price', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
			'total'     => array( 'label' => __( 'Total', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
			'open'      => array( 'label' => __( 'Open', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
			'held'      => array( 'label' => __( 'Held', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
			'confirmed' => array( 'label' => __( 'Confirmed', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
			'waitlist'  => array( 'label' => __( 'Waitlist', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
			'status'    => array( 'label' => __( 'Status', 'lnkup-marketplace' ) ),
			'actions'   => array( 'label' => __( 'Actions', 'lnkup-marketplace' ), 'class' => 'vos-actions' ),
		);
		$rows     = array();
		$drawers  = '';
		foreach ( $opps as $opp_id ) {
			$cap     = LNKup_Organizer_Presentation::opportunity_capacity( $opp_id );
			$fee     = (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true );
			$state   = (string) get_post_meta( $opp_id, '_lnkup_opportunity_status', true );
			$closed  = 'closed' === $state;
			$terms   = wp_get_object_terms( $opp_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
			$cats    = is_wp_error( $terms ) ? '' : implode( ', ', array_map( static fn( $n ) => wp_specialchars_decode( (string) $n, ENT_QUOTES ), $terms ) );
			$title   = lnkup_plain_title( $opp_id );
			$floor   = (int) $cap['held'] + (int) $cap['confirmed'];
			$has_app = $floor + (int) $cap['waitlisted'] > 0 || (bool) get_posts(
				array(
					'post_type'      => 'lnkup_application',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_lnkup_opportunity_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => (string) $opp_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				)
			);
			$drawer_id = 'oos-inv-' . $opp_id;
			$rows[]    = array(
				'id'    => $opp_id,
				'attrs' => 'data-opportunity-id="' . esc_attr( (string) $opp_id ) . '"',
				'cells' => array(
					'type'      => '<span class="vos-table__primary">' . esc_html( $title ) . '</span><span class="vos-table__sub" data-testid="lnkup-slot-inventory">' . esc_html( sprintf( /* translators: 1: open, 2: held, 3: paid, 4: waitlisted */ __( '%1$s open · %2$d held · %3$d paid · %4$d waitlisted', 'lnkup-marketplace' ), $cap['unlimited'] ? __( 'Unlimited', 'lnkup-marketplace' ) : (string) $cap['open'], $cap['held'], $cap['confirmed'], $cap['waitlisted'] ) ) . '</span>',
					'category'  => esc_html( '' !== $cats ? $cats : '—' ),
					'price'     => esc_html( LNKup_OS_UI::money( $fee ) ),
					'total'     => esc_html( $cap['unlimited'] ? __( 'No limit', 'lnkup-marketplace' ) : (string) $cap['total'] ),
					'open'      => esc_html( $cap['unlimited'] ? '—' : (string) $cap['open'] ),
					'held'      => esc_html( (string) $cap['held'] ),
					'confirmed' => esc_html( (string) $cap['confirmed'] ),
					'waitlist'  => esc_html( (string) $cap['waitlisted'] ),
					'status'    => $closed ? LNKup_OS_UI::status( __( 'Closed', 'lnkup-marketplace' ), 'neutral' ) : ( ! $cap['unlimited'] && 0 === (int) $cap['open'] ? LNKup_OS_UI::status( __( 'Full', 'lnkup-marketplace' ), 'warning' ) : LNKup_OS_UI::status( __( 'Accepting', 'lnkup-marketplace' ), 'success' ) ),
					'actions'   => '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-vos-open="' . esc_attr( $drawer_id ) . '">' . esc_html__( 'Edit', 'lnkup-marketplace' ) . '</button>' . ( '' !== $preview ? ' <a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="' . esc_url( $preview ) . '" target="_blank" rel="noopener" data-testid="lnkup-slot-preview">' . esc_html__( 'Preview', 'lnkup-marketplace' ) . '</a>' : '' ),
				),
			);
			$drawers .= self::inventory_drawer( $drawer_id, $opp_id, $title, $fee, $cap, $floor, $closed, $has_app );
		}
		$actions = '<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" data-vos-open="oos-inv-add" data-testid="lnkup-inventory-add">' . esc_html__( 'Add booth type', 'lnkup-marketplace' ) . '</button>';
		ob_start();
		?>
		<section class="vos-section" data-testid="lnkup-event-inventory" data-slot-panel="<?php echo esc_attr( (string) $event_id ); ?>">
			<?php wp_nonce_field( 'lnkup_opportunity', 'lnkup_nonce', false ); ?>
			<div class="vos-section-head">
				<div>
					<h2 class="vos-section-head__title"><?php esc_html_e( 'Inventory', 'lnkup-marketplace' ); ?></h2>
					<p class="lnkup-muted" style="margin:4px 0 0"><?php esc_html_e( 'Booth types vendors can apply for. Held spaces belong to approved vendors who have not paid yet.', 'lnkup-marketplace' ); ?></p>
				</div>
				<?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<?php if ( $opps ) : ?>
				<?php echo LNKup_OS_UI::capacity_bar( $summary['capacity'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
			<?php
			echo LNKup_OS_UI::data_table( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$columns,
				$rows,
				array(
					'caption' => __( 'Booth types', 'lnkup-marketplace' ),
					'testid'  => 'lnkup-inventory-table',
					'empty'   => LNKup_OS_UI::empty_state( __( 'No booth types yet', 'lnkup-marketplace' ), __( 'Add a booth type with a price and number of spaces so vendors can apply.', 'lnkup-marketplace' ) ),
				)
			);
			?>
		</section>
		<?php
		echo $drawers; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo LNKup_OS_UI::drawer_shell( 'oos-inv-add', __( 'Add booth type', 'lnkup-marketplace' ), '<div class="oos-inv-add">' . LNKup_Shortcodes::render_add_vendor_slot_form( $event_id, true ) . '</div>', '', array( 'wide' => true, 'testid' => 'lnkup-inventory-add-drawer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return (string) ob_get_clean();
	}

	/**
	 * Edit drawer for one booth type. Saves through PATCH /organizer/opportunities/{id}/pricing,
	 * which refuses capacity below held + confirmed.
	 *
	 * @param string $id      Drawer element id.
	 * @param int    $opp_id  Opportunity ID.
	 * @param string $title   Booth type name.
	 * @param float  $fee     Price.
	 * @param array  $cap     Capacity.
	 * @param int    $floor   Held + confirmed.
	 * @param bool   $closed  Closed to new applications.
	 * @param bool   $has_app Has any application.
	 */
	private static function inventory_drawer( string $id, int $opp_id, string $title, float $fee, array $cap, int $floor, bool $closed, bool $has_app ): string {
		$min   = max( 1, $floor );
		$body  = '<form class="oos-inv-form" data-oos-inventory-form data-opportunity-id="' . esc_attr( (string) $opp_id ) . '" novalidate>';
		$body .= '<p class="vos-notice" data-oos-inv-error hidden role="alert"></p>';
		$body .= '<label class="vos-field"><span>' . esc_html__( 'Booth type name', 'lnkup-marketplace' ) . '</span><input type="text" name="title" value="' . esc_attr( $title ) . '" required maxlength="120" /></label>';
		$body .= '<div class="vos-field-row">';
		$body .= '<label class="vos-field"><span>' . esc_html__( 'Price', 'lnkup-marketplace' ) . '</span><input type="number" name="vendor_fee" min="0" step="0.01" value="' . esc_attr( (string) $fee ) . '" required /><span class="vos-field__hint">' . esc_html__( 'Applies to new applications. Existing invoices and payments keep their amount.', 'lnkup-marketplace' ) . '</span></label>';
		$body .= '<label class="vos-field"><span>' . esc_html__( 'Total spaces', 'lnkup-marketplace' ) . '</span><input type="number" name="spots_total" min="' . esc_attr( (string) $min ) . '" step="1" value="' . esc_attr( (string) max( $min, (int) $cap['total'] ) ) . '" required data-oos-floor="' . esc_attr( (string) $floor ) . '" /><span class="vos-field__hint">' . esc_html( sprintf( /* translators: 1: held, 2: confirmed, 3: minimum */ __( '%1$d held + %2$d confirmed, so the minimum is %3$d.', 'lnkup-marketplace' ), (int) $cap['held'], (int) $cap['confirmed'], $min ) ) . '</span></label>';
		$body .= '</div></form>';
		$body .= '<div data-slot-row data-opportunity-id="' . esc_attr( (string) $opp_id ) . '" class="oos-inv-more"><h3 class="vos-section-head__title" style="font-size:15px">' . esc_html__( 'Application questions', 'lnkup-marketplace' ) . '</h3><button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-edit-app-questions="' . esc_attr( (string) $opp_id ) . '" aria-expanded="false" data-testid="lnkup-slot-questions">' . esc_html__( 'Edit questions', 'lnkup-marketplace' ) . '</button></div>';
		$state = $closed
			? '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-slot-status="open" data-opportunity-id="' . esc_attr( (string) $opp_id ) . '">' . esc_html__( 'Reopen to new applications', 'lnkup-marketplace' ) . '</button>'
			: ( $has_app
				? '<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-slot-status="closed" data-opportunity-id="' . esc_attr( (string) $opp_id ) . '">' . esc_html__( 'Close to new applications', 'lnkup-marketplace' ) . '</button>'
				: '<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-delete-slot="' . esc_attr( (string) $opp_id ) . '">' . esc_html__( 'Remove booth type', 'lnkup-marketplace' ) . '</button>' );
		$foot  = '<span class="vos-drawer__foot-start">' . $state . '</span><button type="button" class="lnkup-btn lnkup-btn--ghost" data-vos-close>' . esc_html__( 'Cancel', 'lnkup-marketplace' ) . '</button><button type="button" class="lnkup-btn lnkup-btn--primary" data-oos-inventory-save="' . esc_attr( (string) $opp_id ) . '">' . esc_html__( 'Save changes', 'lnkup-marketplace' ) . '</button>';
		return LNKup_OS_UI::drawer_shell( $id, sprintf( /* translators: %s: booth type */ __( 'Edit %s', 'lnkup-marketplace' ), $title ), $body, $foot, array( 'testid' => 'lnkup-inventory-drawer' ) );
	}

	/**
	 * Applications or confirmed vendors for this event.
	 *
	 * @param array  $summary Event summary.
	 * @param string $tab     applications|vendors.
	 */
	private static function applications( array $summary, string $tab ): string {
		$event_id = (int) $summary['id'];
		$statuses = 'vendors' === $tab ? array( 'approved', 'payment_required', 'paid', 'completed' ) : array();
		$query    = array(
			'post_type'      => 'lnkup_application',
			'posts_per_page' => -1,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array( 'key' => '_lnkup_event_id', 'value' => (string) $event_id ),
			),
		);
		if ( $statuses ) {
			$query['meta_query'][] = array( 'key' => '_lnkup_application_status', 'value' => $statuses, 'compare' => 'IN' );
		}
		$apps = get_posts( $query );
		ob_start();
		if ( 'applications' === $tab && self::review_app_enabled() ) {
			?>
			<section class="lnkup-review" id="lnkup-review-app" data-testid="lnkup-review-app" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>" data-scope="event" aria-live="polite">
				<?php echo lnkup_loading_state( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</section>
			<p class="vos-footnote"><a class="vos-link" href="<?php echo esc_url( add_query_arg( array( 'event' => $event_id, 'roster' => 1 ), lnkup_page_url_helper( 'organizer-dashboard' ) ) ); ?>"><?php esc_html_e( 'Roster & readiness', 'lnkup-marketplace' ); ?></a> · <a class="vos-link" href="<?php echo esc_url( LNKup_Shortcodes::organizer_vendor_export_url( $event_id ) ); ?>"><?php esc_html_e( 'Export contacts (CSV)', 'lnkup-marketplace' ); ?></a></p>
			<?php
			return (string) ob_get_clean();
		}
		?>
		<div class="vos-toolbar">
			<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="<?php echo esc_url( lnkup_organizer_view_url( 'applications', array( 'event_filter' => $event_id ) ) ); ?>"><?php esc_html_e( 'Open in Applications', 'lnkup-marketplace' ); ?></a>
			<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="<?php echo esc_url( add_query_arg( array( 'event' => $event_id, 'roster' => 1 ), lnkup_page_url_helper( 'organizer-dashboard' ) ) ); ?>"><?php esc_html_e( 'Roster & readiness', 'lnkup-marketplace' ); ?></a>
			<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( LNKup_Shortcodes::organizer_vendor_export_url( $event_id ) ); ?>"><?php esc_html_e( 'Export contacts (CSV)', 'lnkup-marketplace' ); ?></a>
		</div>
		<?php
		echo LNKup_Organizer_Dashboard_Views::render_application_table( $apps, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return (string) ob_get_clean();
	}

	/**
	 * Whether the review workspace (organizer-review.js) powers application tables.
	 */
	public static function review_app_enabled(): bool {
		return class_exists( 'LNKup_Feature_Flags' ) && LNKup_Feature_Flags::application_review();
	}

	/**
	 * Marketing: share link, application link, QR and embed code.
	 *
	 * @param array $summary Event summary.
	 */
	private static function marketing( array $summary ): string {
		$event_id = (int) $summary['id'];
		$public   = (string) $summary['public_url'];
		$opp_id   = 0;
		foreach ( LNKup_Organizer_Presentation::opportunity_ids( $event_id ) as $candidate ) {
			if ( 'closed' !== get_post_meta( $candidate, '_lnkup_opportunity_status', true ) ) {
				$opp_id = $candidate;
				break;
			}
		}
		$apply = $opp_id
			? add_query_arg( 'apply', $opp_id, lnkup_page_url_helper( 'opportunities' ) )
			: add_query_arg( 'event', $event_id, lnkup_page_url_helper( 'opportunities' ) );
		$embed = $opp_id ? '<div data-lnkup-apply="' . $opp_id . '"></div><script src="' . esc_url( LNKUP_PLUGIN_URL . 'public/js/embed.js' ) . '" async></script>' : '';
		$qr    = class_exists( 'LNKup_QR' ) ? LNKup_QR::data_uri( $apply, 200 ) : '';
		ob_start();
		?>
		<div class="oos-ws-grid" data-testid="lnkup-event-marketing">
			<section class="vos-card" aria-labelledby="oos-mk-links">
				<?php echo LNKup_OS_UI::section_head( __( 'Share links', 'lnkup-marketplace' ), '', '', 'oos-mk-links' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( '' !== $public ) : ?>
					<label class="vos-field"><span><?php esc_html_e( 'Public event page', 'lnkup-marketplace' ); ?></span><input type="text" readonly value="<?php echo esc_attr( $public ); ?>" /></label>
					<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-copy-url="<?php echo esc_url( $public ); ?>"><?php esc_html_e( 'Copy page link', 'lnkup-marketplace' ); ?></button>
				<?php endif; ?>
				<label class="vos-field" style="margin-top:14px"><span><?php esc_html_e( 'Vendor application link', 'lnkup-marketplace' ); ?></span><input type="text" readonly value="<?php echo esc_attr( $apply ); ?>" /></label>
				<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-copy-url="<?php echo esc_url( $apply ); ?>" data-testid="lnkup-copy-apply-link"><?php esc_html_e( 'Copy application link', 'lnkup-marketplace' ); ?></button>
			</section>
			<section class="vos-card" aria-labelledby="oos-mk-qr">
				<?php echo LNKup_OS_UI::section_head( __( 'QR code for flyers', 'lnkup-marketplace' ), '', '', 'oos-mk-qr' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( '' !== $qr ) : ?>
					<img src="<?php echo esc_attr( $qr ); ?>" width="200" height="200" alt="<?php esc_attr_e( 'QR code that opens the vendor application page', 'lnkup-marketplace' ); ?>" />
					<p><a class="vos-link" href="<?php echo esc_attr( $qr ); ?>" download="<?php echo esc_attr( 'lnkup-event-' . $event_id . '-qr.svg' ); ?>"><?php esc_html_e( 'Download QR (SVG)', 'lnkup-marketplace' ); ?></a></p>
				<?php else : ?>
					<p class="lnkup-muted"><?php esc_html_e( 'QR codes are not available right now.', 'lnkup-marketplace' ); ?></p>
				<?php endif; ?>
			</section>
			<section class="vos-card" aria-labelledby="oos-mk-embed">
				<?php echo LNKup_OS_UI::section_head( __( 'Apply button for your website', 'lnkup-marketplace' ), '', '', 'oos-mk-embed' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( '' !== $embed ) : ?>
					<label class="vos-field"><span class="vos-sr"><?php esc_html_e( 'Embed code', 'lnkup-marketplace' ); ?></span><textarea readonly rows="3" data-testid="lnkup-event-embed"><?php echo esc_textarea( $embed ); ?></textarea></label>
					<p class="vos-footnote"><?php esc_html_e( 'Paste this where the button should appear. It opens this event’s application.', 'lnkup-marketplace' ); ?></p>
				<?php else : ?>
					<p class="lnkup-muted"><?php esc_html_e( 'Open a booth type to get an embed code.', 'lnkup-marketplace' ); ?></p>
				<?php endif; ?>
			</section>
			<section class="vos-card" aria-labelledby="oos-mk-more">
				<?php echo LNKup_OS_UI::section_head( __( 'Campaigns and results', 'lnkup-marketplace' ), __( 'Open Marketing', 'lnkup-marketplace' ), lnkup_organizer_view_url( 'marketing' ), 'oos-mk-more' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="lnkup-muted"><?php esc_html_e( 'Marketing tracks link views, applications and paid bookings separately, so you can see which channel brought vendors who actually booked.', 'lnkup-marketplace' ); ?></p>
			</section>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
