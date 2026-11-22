<?php
/**
 * Organization teams: an organizer account (the owner) grants scoped access
 * across their events and tours.
 *
 * Scope: all events, one tour (lnkup_tour via _lnkup_tour_id), or one event.
 * Capabilities reuse the LNKup_Capabilities::staff_can() vocabulary so
 * existing event-scoped checks pick teams up without new call sites.
 * Per-event LNKup_Event_Staff remains the finest level.
 *
 * Checks read the table on every request (no cache), so revocation is immediate.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Teams {

	const INVITE_TTL_DAYS     = 7;
	const INVITES_PER_DAY     = 25;
	const MAX_ACTIVE_MEMBERS  = 100;

	public static function roles() {
		return array(
			'admin'          => __( 'Organization Admin', 'lnkup-marketplace' ),
			'event_manager'  => __( 'Event Manager', 'lnkup-marketplace' ),
			'vendor_manager' => __( 'Vendor Manager', 'lnkup-marketplace' ),
			'reviewer'       => __( 'Application Reviewer', 'lnkup-marketplace' ),
			'finance'        => __( 'Finance', 'lnkup-marketplace' ),
			'ops_staff'      => __( 'Operations Staff', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Role → capabilities. Owner-only: owner, manage_connect.
	 *
	 * review_applications: open the review queue and workspace, see application answers.
	 * score_applications:  submit a rubric score and recommendation (never changes status).
	 * manage_applications: approve, decline, waitlist, request info, offer waitlist spots.
	 *
	 * @return array<string, string[]>
	 */
	public static function capability_matrix() {
		$ops    = array( 'view_roster', 'check_in', 'edit_ops', 'edit_packets', 'edit_map', 'broadcast' );
		$review = array( 'review_applications', 'score_applications', 'view_documents' );
		return array(
			'admin'          => array_merge( $ops, $review, array( 'view_intelligence', 'view_financials', 'manage_staff', 'manage_applications', 'manage_refunds', 'cancel_event', 'manage_team' ) ),
			'event_manager'  => array_merge( $ops, $review, array( 'view_intelligence', 'manage_applications', 'cancel_event' ) ),
			'vendor_manager' => array_merge( $review, array( 'view_roster', 'broadcast', 'manage_applications' ) ),
			'reviewer'       => $review,
			'finance'        => array( 'view_roster', 'view_financials', 'manage_refunds' ),
			'ops_staff'      => $ops,
		);
	}

	/**
	 * Events where $user_id holds $capability: events they own plus events reachable through
	 * active team memberships (all events, a tour's stops, or one event).
	 *
	 * @param int    $user_id    User.
	 * @param string $capability Capability key.
	 * @return int[]
	 */
	public static function event_ids_for( $user_id, $capability ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return array();
		}
		$owned = array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'lnkup_event',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_lnkup_organizer_user_id',
					'meta_value'     => (string) $user_id,
				)
			)
		);
		$ids    = $owned;
		$matrix = self::capability_matrix();
		foreach ( self::orgs_for_user( $user_id ) as $owner_id ) {
			foreach ( self::active_memberships( $owner_id, $user_id ) as $row ) {
				if ( ! in_array( $capability, $matrix[ $row['role'] ] ?? array(), true ) ) {
					continue;
				}
				$scope_id = (int) $row['scope_id'];
				if ( 'event' === $row['scope_type'] ) {
					if ( (int) get_post_meta( $scope_id, '_lnkup_organizer_user_id', true ) === (int) $owner_id ) {
						$ids[] = $scope_id;
					}
					continue;
				}
				$meta_query = array(
					array( 'key' => '_lnkup_organizer_user_id', 'value' => (string) $owner_id ),
				);
				if ( 'tour' === $row['scope_type'] ) {
					$meta_query[] = array( 'key' => '_lnkup_tour_id', 'value' => (string) $scope_id );
				}
				$ids = array_merge(
					$ids,
					array_map(
						'intval',
						get_posts(
							array(
								'post_type'      => 'lnkup_event',
								'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
								'posts_per_page' => -1,
								'fields'         => 'ids',
								'no_found_rows'  => true,
								'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							)
						)
					)
				);
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	public static function init() {
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ) );
	}

	public static function enabled() {
		return LNKup_Trust::flag( 'trust_org_teams' );
	}

	/**
	 * Whether a team membership grants $capability on $event_id to $user_id.
	 *
	 * @param int    $user_id    User.
	 * @param int    $event_id   Event.
	 * @param string $capability Capability key.
	 */
	public static function can( $user_id, $event_id, $capability ) {
		if ( ! self::enabled() || in_array( $capability, array( 'owner', 'manage_connect' ), true ) ) {
			return false;
		}
		$user_id  = absint( $user_id );
		$event_id = absint( $event_id );
		if ( ! $user_id || ! $event_id || 'lnkup_event' !== get_post_type( $event_id ) ) {
			return false;
		}
		$owner = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		if ( ! $owner || $owner === $user_id ) {
			return false;
		}
		$tour_id = (int) get_post_meta( $event_id, '_lnkup_tour_id', true );
		$matrix  = self::capability_matrix();
		foreach ( self::active_memberships( $owner, $user_id ) as $row ) {
			if ( ! in_array( $capability, $matrix[ $row['role'] ] ?? array(), true ) ) {
				continue;
			}
			if ( 'all' === $row['scope_type']
				|| ( 'event' === $row['scope_type'] && (int) $row['scope_id'] === $event_id )
				|| ( 'tour' === $row['scope_type'] && $tour_id && (int) $row['scope_id'] === $tour_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Organization-level capability (not tied to one event), e.g. manage_team.
	 *
	 * @param int    $user_id    User.
	 * @param int    $owner_id   Organization owner.
	 * @param string $capability Capability.
	 */
	public static function can_org( $user_id, $owner_id, $capability ) {
		$user_id  = absint( $user_id );
		$owner_id = absint( $owner_id );
		if ( $user_id && $user_id === $owner_id ) {
			return true;
		}
		if ( LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return true;
		}
		if ( ! self::enabled() ) {
			return false;
		}
		$matrix = self::capability_matrix();
		foreach ( self::active_memberships( $owner_id, $user_id ) as $row ) {
			if ( 'all' === $row['scope_type'] && in_array( $capability, $matrix[ $row['role'] ] ?? array(), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param int $owner_id Owner.
	 * @param int $user_id  Member.
	 * @return array<int, array<string, mixed>>
	 */
	public static function active_memberships( $owner_id, $user_id ) {
		global $wpdb;
		$table = LNKup_Trust_Tables::org_members_table();
		if ( ! LNKup_Trust_Tables::exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE org_owner_id = %d AND user_id = %d AND status = 'active' AND revoked_at IS NULL",
				absint( $owner_id ),
				absint( $user_id )
			),
			ARRAY_A
		);
	}

	/**
	 * Organizations (owner IDs) the user belongs to.
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	public static function orgs_for_user( $user_id ) {
		global $wpdb;
		$table = LNKup_Trust_Tables::org_members_table();
		if ( ! self::enabled() || ! LNKup_Trust_Tables::exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT org_owner_id FROM {$table} WHERE user_id = %d AND status = 'active' AND revoked_at IS NULL", absint( $user_id ) ) ) );
	}

	/**
	 * Team list for the owner's Team panel.
	 *
	 * @param int $owner_id Owner.
	 */
	public static function list_team( $owner_id ) {
		global $wpdb;
		$members_table = LNKup_Trust_Tables::org_members_table();
		$invites_table = LNKup_Trust_Tables::org_invites_table();
		$out           = array( 'members' => array(), 'invites' => array(), 'roles' => self::roles() );
		if ( ! LNKup_Trust_Tables::exists( $members_table ) ) {
			return $out;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$members_table} WHERE org_owner_id = %d AND status = 'active' ORDER BY created_at ASC", absint( $owner_id ) ), ARRAY_A );
		foreach ( $rows as $row ) {
			$user             = get_userdata( (int) $row['user_id'] );
			$out['members'][] = array(
				'id'          => (int) $row['id'],
				'user_id'     => (int) $row['user_id'],
				'name'        => $user ? $user->display_name : __( 'Deleted user', 'lnkup-marketplace' ),
				'email'       => $user ? $user->user_email : '',
				'role'        => (string) $row['role'],
				'role_label'  => self::roles()[ $row['role'] ] ?? (string) $row['role'],
				'scope_type'  => (string) $row['scope_type'],
				'scope_id'    => (int) $row['scope_id'],
				'scope_label' => self::scope_label( (string) $row['scope_type'], (int) $row['scope_id'] ),
				'created_at'  => (string) $row['created_at'],
			);
		}
		if ( LNKup_Trust_Tables::exists( $invites_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$invites = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$invites_table} WHERE org_owner_id = %d AND status = 'pending' AND expires_at > %s ORDER BY created_at DESC", absint( $owner_id ), current_time( 'mysql', true ) ), ARRAY_A );
			foreach ( $invites as $inv ) {
				$out['invites'][] = array(
					'id'          => (int) $inv['id'],
					'email'       => (string) $inv['email'],
					'role'        => (string) $inv['role'],
					'role_label'  => self::roles()[ $inv['role'] ] ?? (string) $inv['role'],
					'scope_label' => self::scope_label( (string) $inv['scope_type'], (int) $inv['scope_id'] ),
					'expires_at'  => (string) $inv['expires_at'],
				);
			}
		}
		return $out;
	}

	/**
	 * Invite someone by email. The raw token only exists in the email.
	 *
	 * @param int    $owner_id   Organization owner.
	 * @param string $email      Invitee email.
	 * @param string $role       Role.
	 * @param string $scope_type all|tour|event.
	 * @param int    $scope_id   Tour/event ID.
	 * @param int    $actor      Inviting user.
	 * @return array|WP_Error
	 */
	public static function invite( $owner_id, $email, $role, $scope_type, $scope_id, $actor ) {
		global $wpdb;
		$owner_id = absint( $owner_id );
		$email    = sanitize_email( $email );
		$role     = sanitize_key( $role );
		$check    = self::validate_assignment( $owner_id, $role, $scope_type, $scope_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'lnkup_team_bad_email', __( 'Enter a valid email address.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$owner = get_userdata( $owner_id );
		if ( $owner && strtolower( $owner->user_email ) === strtolower( $email ) ) {
			return new WP_Error( 'lnkup_team_self', __( 'You already own this organization.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( 'admin' === $role && absint( $actor ) !== $owner_id && ! LNKup_Capabilities::user_has_manage_all( $actor ) ) {
			return new WP_Error( 'lnkup_team_admin_owner_only', __( 'Only the organization owner can add another admin.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		if ( ! LNKup_Trust::rate_limit( 'org_invite_' . $owner_id, self::INVITES_PER_DAY, DAY_IN_SECONDS ) ) {
			return new WP_Error( 'rate_limited', __( 'Daily invite limit reached. Try again tomorrow.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}

		$token = wp_generate_password( 40, false, false );
		$now   = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			LNKup_Trust_Tables::org_invites_table(),
			array(
				'org_owner_id' => $owner_id,
				'email'        => strtolower( $email ),
				'role'         => $role,
				'scope_type'   => $scope_type,
				'scope_id'     => absint( $scope_id ),
				'token_hash'   => hash( 'sha256', $token ),
				'status'       => 'pending',
				'invited_by'   => absint( $actor ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + self::INVITE_TTL_DAYS * DAY_IN_SECONDS ),
				'created_at'   => $now,
			)
		);
		$invite_id = (int) $wpdb->insert_id;
		if ( ! $invite_id ) {
			return new WP_Error( 'lnkup_team_invite_failed', __( 'Could not create the invite. Try again.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}

		$accept_url = add_query_arg( array( 'view' => 'team', 'lnkup_team_invite' => $token ), lnkup_page_url_helper( 'organizer-dashboard' ) );
		if ( class_exists( 'LNKup_Notifications' ) ) {
			$org_name = $owner ? $owner->display_name : __( 'an organizer', 'lnkup-marketplace' );
			LNKup_Notifications::send(
				$email,
				/* translators: %s: organization name */
				sprintf( __( 'You were invited to %s on LNKup', 'lnkup-marketplace' ), $org_name ),
				wpautop(
					esc_html(
						sprintf(
							/* translators: 1: organization, 2: role, 3: days */
							__( '%1$s invited you to join their team on LNKup as %2$s. The invite expires in %3$d days. Sign in with this email address to accept.', 'lnkup-marketplace' ),
							$org_name,
							self::roles()[ $role ],
							self::INVITE_TTL_DAYS
						)
					)
				) . '<p><a href="' . esc_url( $accept_url ) . '">' . esc_html__( 'Accept invite', 'lnkup-marketplace' ) . '</a></p>'
			);
		}
		LNKup_Audit_Log::log( 'org_member_invited', 'org', $owner_id, array( 'actor_user_id' => absint( $actor ), 'new' => array( 'invite_id' => $invite_id, 'email_domain' => LNKup_Trust::domain_of( $email ), 'role' => $role, 'scope_type' => $scope_type, 'scope_id' => absint( $scope_id ) ) ) );
		return array( 'invite_id' => $invite_id, 'email' => strtolower( $email ), 'role' => $role );
	}

	/**
	 * Accept an invite. The signed-in account's email must match the invite.
	 *
	 * @param string $token   Raw token from the email link.
	 * @param int    $user_id Signed-in user.
	 * @return array|WP_Error
	 */
	public static function accept( $token, $user_id ) {
		global $wpdb;
		$table = LNKup_Trust_Tables::org_invites_table();
		if ( ! LNKup_Trust_Tables::exists( $table ) || '' === (string) $token ) {
			return new WP_Error( 'lnkup_team_invalid_invite', __( 'This invite link is not valid.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! LNKup_Trust::rate_limit( 'org_accept_' . absint( $user_id ), 10, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many attempts. Try again later.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$invite = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s LIMIT 1", hash( 'sha256', (string) $token ) ), ARRAY_A );
		if ( ! $invite || 'pending' !== $invite['status'] || strtotime( $invite['expires_at'] . ' UTC' ) < time() ) {
			return new WP_Error( 'lnkup_team_invalid_invite', __( 'This invite has expired or was already used. Ask the organizer to send a new one.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$user = get_userdata( absint( $user_id ) );
		if ( ! $user || strtolower( $user->user_email ) !== strtolower( (string) $invite['email'] ) ) {
			return new WP_Error( 'lnkup_team_wrong_account', __( 'Sign in with the email address this invite was sent to.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$member_id = self::add_member( (int) $invite['org_owner_id'], (int) $user->ID, (string) $invite['role'], (string) $invite['scope_type'], (int) $invite['scope_id'], (int) $invite['invited_by'] );
		if ( is_wp_error( $member_id ) ) {
			return $member_id;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, array( 'status' => 'accepted', 'accepted_user_id' => (int) $user->ID ), array( 'id' => (int) $invite['id'], 'status' => 'pending' ) );
		LNKup_Audit_Log::log( 'org_invite_accepted', 'org', (int) $invite['org_owner_id'], array( 'actor_user_id' => (int) $user->ID, 'new' => array( 'invite_id' => (int) $invite['id'], 'member_id' => $member_id ) ) );
		return array( 'member_id' => $member_id, 'org_owner_id' => (int) $invite['org_owner_id'], 'role' => (string) $invite['role'] );
	}

	/**
	 * @return int|WP_Error Member row ID.
	 */
	private static function add_member( $owner_id, $user_id, $role, $scope_type, $scope_id, $actor ) {
		global $wpdb;
		$check = self::validate_assignment( $owner_id, $role, $scope_type, $scope_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$table = LNKup_Trust_Tables::org_members_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE org_owner_id = %d AND status = 'active'", $owner_id ) );
		if ( $count >= self::MAX_ACTIVE_MEMBERS ) {
			return new WP_Error( 'lnkup_team_full', __( 'This team has reached its member limit.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'org_owner_id' => absint( $owner_id ),
				'user_id'      => absint( $user_id ),
				'role'         => $role,
				'scope_type'   => $scope_type,
				'scope_id'     => absint( $scope_id ),
				'status'       => 'active',
				'invited_by'   => absint( $actor ),
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		LNKup_Audit_Log::log( 'org_member_added', 'org', absint( $owner_id ), array( 'actor_user_id' => absint( $actor ), 'new' => array( 'member_id' => $id, 'user_id' => absint( $user_id ), 'role' => $role, 'scope_type' => $scope_type, 'scope_id' => absint( $scope_id ) ) ) );
		return $id;
	}

	/**
	 * Change a member's role or scope.
	 *
	 * @param int   $owner_id  Owner.
	 * @param int   $member_id Member row.
	 * @param array $changes   role, scope_type, scope_id.
	 * @param int   $actor     Actor.
	 * @return true|WP_Error
	 */
	public static function update_member( $owner_id, $member_id, array $changes, $actor ) {
		global $wpdb;
		$row = self::member_row( $owner_id, $member_id );
		if ( ! $row ) {
			return new WP_Error( 'lnkup_team_member_not_found', __( 'Team member not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$role       = isset( $changes['role'] ) ? sanitize_key( $changes['role'] ) : (string) $row['role'];
		$scope_type = isset( $changes['scope_type'] ) ? sanitize_key( $changes['scope_type'] ) : (string) $row['scope_type'];
		$scope_id   = isset( $changes['scope_id'] ) ? absint( $changes['scope_id'] ) : (int) $row['scope_id'];
		$check      = self::validate_assignment( $owner_id, $role, $scope_type, $scope_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ( 'admin' === $role || 'admin' === $row['role'] ) && absint( $actor ) !== absint( $owner_id ) && ! LNKup_Capabilities::user_has_manage_all( $actor ) ) {
			return new WP_Error( 'lnkup_team_admin_owner_only', __( 'Only the organization owner can change admin access.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			LNKup_Trust_Tables::org_members_table(),
			array( 'role' => $role, 'scope_type' => $scope_type, 'scope_id' => $scope_id, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $row['id'] )
		);
		LNKup_Audit_Log::log(
			'org_member_changed',
			'org',
			absint( $owner_id ),
			array(
				'actor_user_id' => absint( $actor ),
				'prev'          => array( 'member_id' => (int) $row['id'], 'role' => $row['role'], 'scope_type' => $row['scope_type'], 'scope_id' => (int) $row['scope_id'] ),
				'new'           => array( 'member_id' => (int) $row['id'], 'role' => $role, 'scope_type' => $scope_type, 'scope_id' => $scope_id ),
			)
		);
		return true;
	}

	/**
	 * Revoke a member. History rows stay; access ends on the next request.
	 *
	 * @param int    $owner_id  Owner.
	 * @param int    $member_id Member row.
	 * @param int    $actor     Actor.
	 * @param string $reason    Reason.
	 * @return true|WP_Error
	 */
	public static function revoke( $owner_id, $member_id, $actor, $reason = '' ) {
		global $wpdb;
		$row = self::member_row( $owner_id, $member_id );
		if ( ! $row ) {
			return new WP_Error( 'lnkup_team_member_not_found', __( 'Team member not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( 'admin' === $row['role'] && absint( $actor ) !== absint( $owner_id ) && ! LNKup_Capabilities::user_has_manage_all( $actor ) && absint( $actor ) !== (int) $row['user_id'] ) {
			return new WP_Error( 'lnkup_team_admin_owner_only', __( 'Only the organization owner can remove an admin.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			LNKup_Trust_Tables::org_members_table(),
			array( 'status' => 'revoked', 'revoked_at' => $now, 'updated_at' => $now ),
			array( 'id' => (int) $row['id'] )
		);
		LNKup_Audit_Log::log(
			'org_member_revoked',
			'org',
			absint( $owner_id ),
			array(
				'actor_user_id' => absint( $actor ),
				'prev'          => array( 'member_id' => (int) $row['id'], 'user_id' => (int) $row['user_id'], 'role' => $row['role'] ),
				'new'           => array( 'status' => 'revoked' ),
				'reason'        => $reason,
			)
		);
		self::maybe_end_sessions( (int) $row['user_id'] );
		do_action( 'lnkup_org_member_revoked', absint( $owner_id ), (int) $row['user_id'] );
		return true;
	}

	/**
	 * End the removed member's sessions only when the account has no organizer role of its own
	 * and no remaining team memberships, so their personal account is not disrupted.
	 *
	 * @param int $user_id User.
	 */
	private static function maybe_end_sessions( $user_id ) {
		if ( LNKup_Roles::is_organizer( $user_id ) || LNKup_Roles::is_vendor( $user_id ) || LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return;
		}
		if ( array() !== self::orgs_for_user( $user_id ) ) {
			return;
		}
		if ( class_exists( 'WP_Session_Tokens' ) ) {
			WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}
	}

	public static function on_user_deleted( $user_id ) {
		global $wpdb;
		$table = LNKup_Trust_Tables::org_members_table();
		if ( ! LNKup_Trust_Tables::exists( $table ) ) {
			return;
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'revoked', revoked_at = %s, updated_at = %s WHERE user_id = %d AND status = 'active'", $now, $now, absint( $user_id ) ) );
	}

	/**
	 * @return true|WP_Error
	 */
	private static function validate_assignment( $owner_id, $role, $scope_type, $scope_id ) {
		if ( ! isset( self::roles()[ $role ] ) ) {
			return new WP_Error( 'lnkup_team_bad_role', __( 'Choose a valid team role.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $scope_type, array( 'all', 'tour', 'event' ), true ) ) {
			return new WP_Error( 'lnkup_team_bad_scope', __( 'Choose which events this person can access.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( 'admin' === $role && 'all' !== $scope_type ) {
			return new WP_Error( 'lnkup_team_admin_scope', __( 'Organization admins always have access to all events.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( 'event' === $scope_type && ! LNKup_Capabilities::user_owns_post( absint( $scope_id ), absint( $owner_id ), '_lnkup_organizer_user_id' ) ) {
			return new WP_Error( 'lnkup_team_bad_scope', __( 'That event is not in your organization.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( 'tour' === $scope_type && ( 'lnkup_tour' !== get_post_type( absint( $scope_id ) ) || (int) get_post_meta( absint( $scope_id ), '_lnkup_organizer_user_id', true ) !== absint( $owner_id ) ) ) {
			return new WP_Error( 'lnkup_team_bad_scope', __( 'That tour is not in your organization.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return true;
	}

	private static function member_row( $owner_id, $member_id ) {
		global $wpdb;
		$table = LNKup_Trust_Tables::org_members_table();
		if ( ! LNKup_Trust_Tables::exists( $table ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND org_owner_id = %d AND status = 'active'", absint( $member_id ), absint( $owner_id ) ), ARRAY_A );
		return $row ?: null;
	}

	private static function scope_label( $scope_type, $scope_id ) {
		if ( 'all' === $scope_type ) {
			return __( 'All events', 'lnkup-marketplace' );
		}
		$title = $scope_id ? get_the_title( $scope_id ) : '';
		return 'tour' === $scope_type
			/* translators: %s: tour name */
			? sprintf( __( 'Tour: %s', 'lnkup-marketplace' ), $title )
			/* translators: %s: event name */
			: sprintf( __( 'Event: %s', 'lnkup-marketplace' ), $title );
	}
}
