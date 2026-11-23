<?php
/**
 * Organizer workspace access for organization team members.
 *
 * Team members do not need the lnkup_organizer role. They can open the organizer
 * dashboard, but only see the staff home, the review queue (when enabled), their team
 * memberships, and event pages their grants allow. Every action is still authorized
 * per event by LNKup_Capabilities::staff_can().
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Workspace {

	/** Dashboard views a team-only member may open. */
	const STAFF_VIEWS = array( 'home', 'applications', 'team', 'help' );

	public static function init() {
		add_filter( 'lnkup_require_roles_allowed', array( __CLASS__, 'admit_to_organizer_dashboard' ), 10, 3 );
		add_filter( 'lnkup_dashboard_nav_items', array( __CLASS__, 'staff_nav_items' ), 20, 2 );
	}

	/**
	 * Theme role gate: admit team members, and anyone opening a team invite link so they can accept it.
	 *
	 * @param bool     $allowed Current decision.
	 * @param string[] $roles   Roles the template requires.
	 * @param WP_User  $user    Current user.
	 */
	public static function admit_to_organizer_dashboard( $allowed, $roles, $user ) {
		if ( $allowed || ! in_array( 'lnkup_organizer', (array) $roles, true ) || ! $user instanceof WP_User || ! $user->ID ) {
			return $allowed;
		}
		if ( self::is_invite_request() ) {
			return true;
		}
		return LNKup_Capabilities::can_access_org_workspace( (int) $user->ID );
	}

	/**
	 * Team-only members get a short nav: their workspace, the review queue, and team access.
	 *
	 * @param array  $items Nav items.
	 * @param string $role  vendor|organizer.
	 */
	public static function staff_nav_items( $items, $role ) {
		if ( 'organizer' !== $role ) {
			return $items;
		}
		if ( ! LNKup_Feature_Flags::organizer_ops() ) {
			$items = array_values(
				array_filter(
					(array) $items,
					static function ( $item ) {
						return 'tasks' !== ( $item['view'] ?? '' );
					}
				)
			);
		}
		if ( ! LNKup_Capabilities::is_team_only_member() ) {
			return $items;
		}
		$keep = array( 'home', 'team', 'help', 'tasks' );
		if ( LNKup_Feature_Flags::application_review() ) {
			$keep[] = 'applications';
		}
		$out = array();
		foreach ( (array) $items as $item ) {
			if ( in_array( (string) ( $item['view'] ?? '' ), $keep, true ) ) {
				if ( 'home' === $item['view'] ) {
					$item['label'] = __( 'My work', 'lnkup-marketplace' );
				} elseif ( 'applications' === $item['view'] ) {
					$item['label'] = __( 'Review', 'lnkup-marketplace' );
					$item['badge'] = 0;
				}
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Whether the current request is opening a team invite link.
	 */
	public static function is_invite_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['lnkup_team_invite'] ) && '' !== sanitize_text_field( wp_unslash( $_GET['lnkup_team_invite'] ) )
			&& 'team' === ( function_exists( 'lnkup_organizer_dashboard_view' ) ? lnkup_organizer_dashboard_view() : '' );
	}

	/**
	 * Staff home: where this person has access and what they can do there.
	 *
	 * @param int $user_id Team member.
	 */
	public static function render_staff_home( $user_id ) {
		$user_id     = absint( $user_id );
		$memberships = array();
		foreach ( LNKup_Org_Teams::orgs_for_user( $user_id ) as $owner_id ) {
			$owner = get_userdata( $owner_id );
			foreach ( LNKup_Org_Teams::active_memberships( $owner_id, $user_id ) as $row ) {
				$memberships[] = array(
					'org'   => $owner ? $owner->display_name : '',
					'role'  => LNKup_Org_Teams::roles()[ $row['role'] ] ?? (string) $row['role'],
					'scope' => self::scope_label( (string) $row['scope_type'], (int) $row['scope_id'] ),
				);
			}
		}
		$review_events = LNKup_Feature_Flags::application_review() ? LNKup_Org_Teams::event_ids_for( $user_id, 'review_applications' ) : array();
		$roster_events = LNKup_Org_Teams::event_ids_for( $user_id, 'view_roster' );
		$checkin_ok    = array_values( array_filter( $roster_events, static function ( $id ) use ( $user_id ) {
			return LNKup_Capabilities::staff_can( (int) $id, 'check_in', $user_id );
		} ) );
		ob_start();
		echo lnkup_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'title'    => __( 'My work', 'lnkup-marketplace' ),
				'subtitle' => __( 'Events you help run, and what you can do for each.', 'lnkup-marketplace' ),
			)
		);
		?>
		<div class="lnkup-staff-home" data-testid="lnkup-staff-home">
			<section class="lnkup-panel" aria-labelledby="lnkup-staff-teams-title">
				<h2 id="lnkup-staff-teams-title" class="lnkup-panel__title"><?php esc_html_e( 'Your access', 'lnkup-marketplace' ); ?></h2>
				<?php if ( ! $memberships ) : ?>
					<p class="lnkup-muted"><?php esc_html_e( 'You are not on any organizer team right now. Ask an organizer to send you an invite.', 'lnkup-marketplace' ); ?></p>
				<?php else : ?>
					<ul class="lnkup-staff-home__list">
						<?php foreach ( $memberships as $m ) : ?>
							<li><strong><?php echo esc_html( $m['org'] ); ?></strong> · <?php echo esc_html( $m['role'] ); ?> · <span class="lnkup-muted"><?php echo esc_html( $m['scope'] ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>
			<?php if ( $review_events ) : ?>
				<section class="lnkup-panel">
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'Applications to review', 'lnkup-marketplace' ); ?></h2>
					<p><a class="lnkup-btn lnkup-btn--primary" href="<?php echo esc_url( add_query_arg( array( 'group' => 'pending', 'assigned' => 'me' ), lnkup_organizer_view_url( 'applications' ) ) ); ?>"><?php esc_html_e( 'Open review queue', 'lnkup-marketplace' ); ?></a></p>
				</section>
			<?php endif; ?>
			<?php if ( $roster_events ) : ?>
				<section class="lnkup-panel">
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'Events', 'lnkup-marketplace' ); ?></h2>
					<ul class="lnkup-staff-home__list">
						<?php foreach ( array_slice( $roster_events, 0, 50 ) as $event_id ) : ?>
							<li>
								<strong><?php echo esc_html( get_the_title( $event_id ) ); ?></strong>
								<span class="lnkup-muted"><?php echo esc_html( (string) get_post_meta( $event_id, '_lnkup_event_date', true ) ); ?></span>
								<a href="<?php echo esc_url( add_query_arg( array( 'event' => $event_id, 'roster' => 1 ), lnkup_page_url_helper( 'organizer-dashboard' ) ) ); ?>"><?php esc_html_e( 'Roster', 'lnkup-marketplace' ); ?></a>
								<?php if ( LNKup_Feature_Flags::organizer_ops() ) : ?>
									· <a href="<?php echo esc_url( add_query_arg( array( 'event' => $event_id, 'tab' => 'readiness' ), lnkup_page_url_helper( 'organizer-dashboard' ) ) ); ?>"><?php esc_html_e( 'Readiness', 'lnkup-marketplace' ); ?></a>
									· <a href="<?php echo esc_url( add_query_arg( array( 'event' => $event_id, 'tab' => 'tasks' ), lnkup_page_url_helper( 'organizer-dashboard' ) ) ); ?>"><?php esc_html_e( 'Tasks', 'lnkup-marketplace' ); ?></a>
								<?php endif; ?>
								<?php if ( in_array( (int) $event_id, $checkin_ok, true ) ) : ?>
									· <a href="<?php echo esc_url( add_query_arg( array( 'event' => $event_id, 'scan' => 1 ), lnkup_page_url_helper( 'organizer-dashboard' ) ) ); ?>"><?php esc_html_e( 'Check-in', 'lnkup-marketplace' ); ?></a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function scope_label( $scope_type, $scope_id ) {
		if ( 'all' === $scope_type ) {
			return __( 'All events', 'lnkup-marketplace' );
		}
		return ( 'tour' === $scope_type ? __( 'Tour: ', 'lnkup-marketplace' ) : __( 'Event: ', 'lnkup-marketplace' ) ) . get_the_title( $scope_id );
	}
}
