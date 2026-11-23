<?php
/**
 * Partnership OS go-live readiness.
 *
 * Read-only: every query is a SELECT. report( false ) also skips the transient cache, so the
 * production preflight can call it without writing anything. Stages follow
 * docs/PARTNERSHIP_ROLLOUT.md: deployed → shadow evidence → enforce → programs → communications → mobile.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Partnership_Readiness {

	const CACHE_KEY = 'lnkup_partner_readiness';
	const CACHE_TTL = 10 * MINUTE_IN_SECONDS;

	/** Minimum days in shadow mode before enforce. */
	const SHADOW_DAYS = 14;
	/** Open conflict reviews older than this block enforce. */
	const STALE_REVIEW_DAYS = 7;
	/** Audit rows read for the shadow breakdown (counts above this are reported as truncated). */
	const BREAKDOWN_CAP = 5000;

	const PAGES = array( 'connector-dashboard', 'affiliate-program', 'partner-with-lnkup' );

	/**
	 * @return array{shadow_days:int, stale_review_days:int}
	 */
	public static function thresholds(): array {
		$t = (array) apply_filters(
			'lnkup_partnership_readiness_thresholds',
			array(
				'shadow_days'       => self::SHADOW_DAYS,
				'stale_review_days' => self::STALE_REVIEW_DAYS,
			)
		);
		return array(
			'shadow_days'       => max( 0, (int) ( $t['shadow_days'] ?? self::SHADOW_DAYS ) ),
			'stale_review_days' => max( 0, (int) ( $t['stale_review_days'] ?? self::STALE_REVIEW_DAYS ) ),
		);
	}

	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Full readiness report.
	 *
	 * @param bool $use_cache False for the preflight: no transient read or write.
	 * @return array{generated_at:string, mode:string, checks:array<int, array<string, string>>, stages:array<int, array<string, mixed>>, shadow:array<string, mixed>, integrity:array<string, int>}
	 */
	public static function report( bool $use_cache = true ): array {
		if ( $use_cache ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$report = self::build();
		if ( $use_cache ) {
			set_transient( self::CACHE_KEY, $report, self::CACHE_TTL );
		}
		return $report;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build(): array {
		$t      = self::thresholds();
		$checks = array();
		$add    = static function ( string $key, string $stage, string $level, string $label, string $detail, string $link = '' ) use ( &$checks ): void {
			$checks[] = compact( 'key', 'stage', 'level', 'label', 'detail', 'link' );
		};

		// ---------------------------------------------------------------- Deployed.
		$schema_ready = class_exists( 'LNKup_Partner_Tables' ) && LNKup_Partner_Tables::ready();
		$add(
			'schema',
			'deployed',
			$schema_ready ? 'ok' : 'fail',
			__( 'Partnership schema', 'lnkup-marketplace' ),
			$schema_ready
				/* translators: 1: schema version, 2: plugin version */
				? sprintf( __( 'Schema %1$s on plugin %2$s.', 'lnkup-marketplace' ), LNKup_Partner_Tables::SCHEMA_VERSION, defined( 'LNKUP_VERSION' ) ? LNKUP_VERSION : '?' )
				/* translators: %s: stored schema version */
				: sprintf( __( 'Stored schema is "%s". Load any page once so the migration runs, then re-check.', 'lnkup-marketplace' ), (string) get_option( 'lnkup_partner_schema', 'none' ) )
		);
		if ( ! $schema_ready ) {
			return self::finish( $checks, array(), array(), self::empty_integrity(), $t );
		}

		$missing_pages = array();
		foreach ( self::PAGES as $slug ) {
			$page = get_page_by_path( $slug );
			if ( ! $page || 'publish' !== $page->post_status ) {
				$missing_pages[] = $slug;
			}
		}
		$add(
			'pages',
			'deployed',
			$missing_pages ? 'warn' : 'ok',
			__( 'Partner pages', 'lnkup-marketplace' ),
			$missing_pages
				/* translators: %s: page slugs */
				? sprintf( __( 'Not published: %s. Re-activate the plugin or create the pages before opening applications.', 'lnkup-marketplace' ), implode( ', ', $missing_pages ) )
				: __( 'Connector dashboard, affiliate program and strategic partner pages are published.', 'lnkup-marketplace' )
		);

		// ---------------------------------------------------------------- Shadow evidence.
		$backfill_done = (string) get_option( LNKup_Attribution_Arbiter::BACKFILL_DONE, '' );
		$unresolved    = self::count( 'SELECT COUNT(*) FROM ' . LNKup_Treasury_Tables::referrals_table() . " WHERE attribution_status = '' AND referred_user_id > 0" );
		$backfill_complete = '' !== $backfill_done || 0 === $unresolved;
		if ( '' !== $backfill_done ) {
			/* translators: %s: date */
			$backfill_detail = sprintf( __( 'Finished %s. Historical conflicts are in the Reviews tab.', 'lnkup-marketplace' ), $backfill_done );
		} elseif ( 0 === $unresolved ) {
			$backfill_detail = __( 'Every referred account is resolved; the hourly job records completion on its next run.', 'lnkup-marketplace' );
		} else {
			/* translators: %d: referral count */
			$backfill_detail = sprintf( __( 'Still running hourly: %d referred accounts not yet resolved.', 'lnkup-marketplace' ), $unresolved );
		}
		$add( 'backfill', 'enforce', $backfill_complete ? 'ok' : 'warn', __( 'Attribution backfill', 'lnkup-marketplace' ), $backfill_detail, 'pipeline' );

		$mode       = LNKup_Attribution_Arbiter::mode();
		$mode_since = self::mode_since( $mode, $backfill_done );
		$mode_days  = '' !== $mode_since ? self::days_since( $mode_since ) : -1;
		$add(
			'arbiter_mode',
			'enforce',
			'enforce' === $mode ? 'ok' : ( 'off' === $mode ? 'warn' : 'info' ),
			__( 'Attribution arbiter mode', 'lnkup-marketplace' ),
			$mode_days >= 0
				/* translators: 1: mode, 2: days, 3: date */
				? sprintf( __( '%1$s for %2$d days (since %3$s).', 'lnkup-marketplace' ), $mode, $mode_days, $mode_since )
				/* translators: %s: mode */
				: sprintf( __( '%s; start date unknown (no settings change or backfill recorded yet).', 'lnkup-marketplace' ), $mode ),
			'settings'
		);

		$shadow = self::shadow_breakdown();
		$add(
			'shadow_blocks',
			'enforce',
			'info',
			__( 'Shadow-mode blocks', 'lnkup-marketplace' ),
			0 === $shadow['total_30d']
				? __( 'No blocks recorded yet. That is only evidence once the observation period has passed with real referral traffic.', 'lnkup-marketplace' )
				/* translators: 1: 7-day count, 2: 30-day count */
				: sprintf( __( '%1$d in the last 7 days, %2$d in the last 30. Each one is a reward enforce mode would have withheld.', 'lnkup-marketplace' ), $shadow['total_7d'], $shadow['total_30d'] ),
			'reviews'
		);
		if ( 'enforce' === $mode ) {
			$add(
				'enforce_blocks',
				'enforce',
				'info',
				__( 'Rewards withheld in enforce mode', 'lnkup-marketplace' ),
				/* translators: 1: 7-day count, 2: 30-day count */
				sprintf( __( '%1$d in the last 7 days, %2$d in the last 30.', 'lnkup-marketplace' ), $shadow['blocked_7d'], $shadow['blocked_30d'] ),
				'reviews'
			);
		}

		$reviews = self::reviews();
		$stale   = $reviews['oldest_conflict_days'] > $t['stale_review_days'];
		$add(
			'reviews',
			'enforce',
			$stale ? 'warn' : 'ok',
			__( 'Open reviews', 'lnkup-marketplace' ),
			/* translators: 1: conflicts, 2: clawbacks, 3: days */
			sprintf( __( '%1$d attribution conflicts, %2$d clawbacks open. Oldest conflict: %3$d days.', 'lnkup-marketplace' ), $reviews['conflict'], $reviews['clawback'], max( 0, $reviews['oldest_conflict_days'] ) ),
			'reviews'
		);

		$integrity = self::integrity();
		foreach ( self::integrity_labels() as $key => $meta ) {
			$n = $integrity[ $key ];
			$add(
				'integrity_' . $key,
				'enforce',
				0 === $n ? 'ok' : $meta['level'],
				$meta['label'],
				/* translators: %d: row count */
				0 === $n ? __( 'None.', 'lnkup-marketplace' ) : sprintf( _n( '%d ledger row.', '%d ledger rows.', $n, 'lnkup-marketplace' ), $n ) . ' ' . $meta['fix'],
				'ledger'
			);
		}

		// ---------------------------------------------------------------- Programs.
		foreach ( array( LNKup_Partner_Programs::CITY_CONNECTOR, LNKup_Partner_Programs::AFFILIATE, LNKup_Partner_Programs::STRATEGIC_PARTNER ) as $slug ) {
			$rule     = LNKup_Commission_Rules::active_rule( $slug );
			$accounts = self::count( 'SELECT COUNT(*) FROM ' . LNKup_Connector_Tables::connectors_table() . " WHERE status = 'active' AND program = %s", $slug );
			$city     = LNKup_Partner_Programs::CITY_CONNECTOR === $slug;
			$add(
				'program_' . $slug,
				'programs',
				$rule ? 'ok' : ( $city ? 'fail' : 'info' ),
				LNKup_Partner_Programs::label( $slug ),
				( $rule
					/* translators: 1: version, 2: terms */
					? sprintf( __( 'Rule v%1$d published: %2$s.', 'lnkup-marketplace' ), (int) $rule['version'], LNKup_Commission_Rules::basis_label( $rule ) )
					: ( $city ? __( 'No active rule. City Connector rewards cannot be calculated.', 'lnkup-marketplace' ) : __( 'No published rule; accounts are tracked but earn nothing until legal terms are approved and a rule is published.', 'lnkup-marketplace' ) ) )
				/* translators: %d: account count */
				. ' ' . sprintf( _n( '%d active account.', '%d active accounts.', $accounts, 'lnkup-marketplace' ), $accounts ),
				'rules'
			);
		}
		$pending = LNKup_Partner_Applications::count_pending();
		$add(
			'applications',
			'programs',
			$pending > 0 ? 'info' : 'ok',
			__( 'Partner applications', 'lnkup-marketplace' ),
			/* translators: %d: count */
			sprintf( _n( '%d application waiting for a decision.', '%d applications waiting for a decision.', $pending, 'lnkup-marketplace' ), $pending ),
			'applications'
		);
		$add(
			'payouts',
			'programs',
			'info',
			__( 'Partner payouts', 'lnkup-marketplace' ),
			__( 'Manual only. LNKup records payouts with a reference; no Stripe transfers are sent to partners.', 'lnkup-marketplace' ),
			'ledger'
		);

		// ---------------------------------------------------------------- Communications.
		$comms        = self::comms();
		$add(
			'comms_plugin',
			'communications',
			$comms['active'] ? 'ok' : 'fail',
			__( 'Communications plugin', 'lnkup-marketplace' ),
			$comms['active']
				/* translators: %s: version */
				? sprintf( __( 'lnkup-communications %s active.', 'lnkup-marketplace' ), $comms['version'] )
				: __( 'lnkup-communications is not active; partner notices cannot be sent.', 'lnkup-marketplace' )
		);
		if ( $comms['active'] ) {
			$add(
				'comms_channels',
				'communications',
				$comms['push_ready'] ? 'ok' : 'warn',
				__( 'Partner notice channels', 'lnkup-marketplace' ),
				$comms['push_ready']
					? __( 'Partner triggers go to email, inbox and push; commission push text carries no amount.', 'lnkup-marketplace' )
					: __( 'This build of lnkup-communications sends partner notices by email only. Deploy 1.2.0 or later for inbox and push.', 'lnkup-marketplace' )
			);
			$add(
				'flow_partners',
				'communications',
				'info',
				__( 'Partner notifications flag', 'lnkup-marketplace' ),
				$comms['flow_partners'] ? __( 'flow_partners is on.', 'lnkup-marketplace' ) : __( 'flow_partners is off (default). Turn it on last, after enforce.', 'lnkup-marketplace' )
			);
		}

		// ---------------------------------------------------------------- Mobile.
		$mobile_gate = class_exists( 'LNKup_Auth_REST' ) && method_exists( 'LNKup_Auth_REST', 'user_may_use_mobile' ) && self::mobile_gate_admits_partners();
		$add(
			'mobile_gate',
			'mobile',
			$mobile_gate ? 'ok' : 'warn',
			__( 'Mobile sign-in for partners', 'lnkup-marketplace' ),
			$mobile_gate
				? __( 'Active partner accounts may sign in to the app; suspended accounts are refused.', 'lnkup-marketplace' )
				: __( 'This plugin build does not admit partner accounts to the app. Ship the Partner home only after deploying it.', 'lnkup-marketplace' )
		);

		$context = array(
			'mode'          => $mode,
			'mode_days'     => $mode_days,
			'backfill_done' => $backfill_complete,
			'stale_review'  => $stale,
			'comms'         => $comms,
			'mobile_gate'   => $mobile_gate,
			'missing_pages' => $missing_pages,
		);
		return self::finish( $checks, $context, $shadow, $integrity, $t );
	}

	/**
	 * @param array<int, array<string, string>> $checks
	 * @param array<string, mixed>              $ctx
	 * @param array<string, mixed>              $shadow
	 * @param array<string, int>                $integrity
	 * @param array<string, int>                $t
	 * @return array<string, mixed>
	 */
	private static function finish( array $checks, array $ctx, array $shadow, array $integrity, array $t ): array {
		return array(
			'generated_at' => current_time( 'mysql' ),
			'mode'         => (string) ( $ctx['mode'] ?? '' ),
			'thresholds'   => $t,
			'checks'       => $checks,
			'stages'       => self::stages( $checks, $ctx, $integrity, $t ),
			'shadow'       => $shadow ?: self::empty_shadow(),
			'integrity'    => $integrity,
		);
	}

	/**
	 * Stage gates. status: done | ready | blocked.
	 *
	 * @param array<int, array<string, string>> $checks
	 * @param array<string, mixed>              $ctx
	 * @param array<string, int>                $integrity
	 * @param array<string, int>                $t
	 * @return array<int, array<string, mixed>>
	 */
	public static function stages( array $checks, array $ctx, array $integrity, array $t ): array {
		$fails = array();
		foreach ( $checks as $check ) {
			if ( 'fail' === $check['level'] ) {
				$fails[ $check['stage'] ][] = $check['label'];
			}
		}
		$deployed_ok = empty( $fails['deployed'] ) && array() !== $ctx;

		$mode    = (string) ( $ctx['mode'] ?? '' );
		$enforce = array();
		if ( ! $deployed_ok ) {
			$enforce[] = __( 'Finish the deploy stage first', 'lnkup-marketplace' );
		} elseif ( 'enforce' !== $mode ) {
			if ( empty( $ctx['backfill_done'] ) ) {
				$enforce[] = __( 'Attribution backfill has not finished', 'lnkup-marketplace' );
			}
			if ( 'shadow' !== $mode ) {
				$enforce[] = __( 'Run in shadow mode first (the arbiter is off)', 'lnkup-marketplace' );
			} elseif ( (int) ( $ctx['mode_days'] ?? -1 ) < 0 ) {
				$enforce[] = __( 'Shadow-mode start date is unknown; it starts counting at the backfill or the first shadow block', 'lnkup-marketplace' );
			} elseif ( (int) ( $ctx['mode_days'] ?? -1 ) < $t['shadow_days'] ) {
				/* translators: 1: days so far, 2: required days */
				$enforce[] = sprintf( __( 'Shadow mode has run %1$d of %2$d days', 'lnkup-marketplace' ), max( 0, (int) ( $ctx['mode_days'] ?? 0 ) ), $t['shadow_days'] );
			}
			if ( ! empty( $ctx['stale_review'] ) ) {
				/* translators: %d: days */
				$enforce[] = sprintf( __( 'Resolve attribution conflicts older than %d days', 'lnkup-marketplace' ), $t['stale_review_days'] );
			}
			foreach ( self::gating_integrity() as $key ) {
				if ( (int) ( $integrity[ $key ] ?? 0 ) > 0 ) {
					$enforce[] = self::integrity_labels()[ $key ]['label'];
				}
			}
		}
		$enforce_status = 'enforce' === $mode && $deployed_ok ? 'done' : ( $enforce ? 'blocked' : 'ready' );

		$programs = $fails['programs'] ?? array();
		if ( 'done' !== $enforce_status ) {
			$programs[] = __( 'Switch the arbiter to enforce first', 'lnkup-marketplace' );
		}
		if ( ! empty( $ctx['missing_pages'] ) ) {
			$programs[] = __( 'Publish the partner program pages', 'lnkup-marketplace' );
		}

		$comms        = (array) ( $ctx['comms'] ?? array() );
		$comms_block  = $fails['communications'] ?? array();
		$allow_shadow = (bool) apply_filters( 'lnkup_partnership_readiness_allow_comms_in_shadow', false );
		if ( 'done' !== $enforce_status && ! $allow_shadow ) {
			$comms_block[] = __( 'Switch the arbiter to enforce first (or explicitly accept shadow mode)', 'lnkup-marketplace' );
		}
		$all_fails = array_merge( ...array_values( $fails ?: array( array() ) ) );
		if ( $all_fails ) {
			$comms_block[] = __( 'Clear every failing check', 'lnkup-marketplace' );
		}
		$comms_status = ! empty( $comms['flow_partners'] ) && ! $comms_block ? 'done' : ( $comms_block ? 'blocked' : 'ready' );

		$mobile_block = array();
		if ( ! $deployed_ok ) {
			$mobile_block[] = __( 'Finish the deploy stage first', 'lnkup-marketplace' );
		}
		if ( empty( $ctx['mobile_gate'] ) ) {
			$mobile_block[] = __( 'Deploy a plugin build that admits partners to the app', 'lnkup-marketplace' );
		}

		return array(
			array( 'key' => 'deployed', 'label' => __( '1. Deployed', 'lnkup-marketplace' ), 'status' => $deployed_ok ? 'done' : 'blocked', 'blockers' => $fails['deployed'] ?? array() ),
			array( 'key' => 'enforce', 'label' => __( '2–3. Shadow evidence and enforce', 'lnkup-marketplace' ), 'status' => $enforce_status, 'blockers' => 'done' === $enforce_status ? array() : $enforce ),
			array( 'key' => 'programs', 'label' => __( '4. Affiliate and strategic programs', 'lnkup-marketplace' ), 'status' => $programs ? 'blocked' : 'ready', 'blockers' => $programs ),
			array( 'key' => 'communications', 'label' => __( '5. Partner notifications', 'lnkup-marketplace' ), 'status' => $comms_status, 'blockers' => 'done' === $comms_status ? array() : $comms_block ),
			array( 'key' => 'mobile', 'label' => __( '6. Mobile Partner home', 'lnkup-marketplace' ), 'status' => $mobile_block ? 'blocked' : 'ready', 'blockers' => $mobile_block ),
		);
	}

	// ------------------------------------------------------------------ Data.

	/**
	 * When the current arbiter mode took effect: the latest settings change into it, else the
	 * backfill date (shadow is the default from install).
	 */
	private static function mode_since( string $mode, string $backfill_done ): string {
		global $wpdb;
		$table = LNKup_Financial_Tables::audit_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT created_at, metadata FROM {$table} WHERE action = %s ORDER BY id DESC LIMIT 100", 'partnership_settings_updated' ), ARRAY_A );
		foreach ( $rows as $row ) {
			$meta = json_decode( (string) $row['metadata'], true );
			$from = (string) ( $meta['from']['partnerships_arbiter_mode'] ?? '' );
			$to   = (string) ( $meta['to']['partnerships_arbiter_mode'] ?? '' );
			if ( $to === $mode && $from !== $mode ) {
				return (string) $row['created_at'];
			}
		}
		if ( 'shadow' !== $mode ) {
			return '';
		}
		if ( '' !== $backfill_done ) {
			return $backfill_done;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(created_at) FROM {$table} WHERE action = %s", 'partner_arbiter_shadow_block' ) );
	}

	/**
	 * Totals for both actions; the reason/program breakdown covers the current mode's action only
	 * (shadow blocks, or real blocks once enforcing) so it always adds up to the headline count.
	 *
	 * @return array{total_7d:int, total_30d:int, blocked_7d:int, blocked_30d:int, action:string, by_reason:array<string,int>, by_program:array<string,int>, truncated:bool}
	 */
	public static function shadow_breakdown(): array {
		global $wpdb;
		$table = LNKup_Financial_Tables::audit_table();
		$d7    = self::ago( 7 );
		$d30   = self::ago( 30 );
		$out   = self::empty_shadow();
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE action = %s AND created_at >= %s";
		$out['total_7d']    = self::count( $sql, 'partner_arbiter_shadow_block', $d7 );
		$out['total_30d']   = self::count( $sql, 'partner_arbiter_shadow_block', $d30 );
		$out['blocked_7d']  = self::count( $sql, 'partner_arbiter_blocked', $d7 );
		$out['blocked_30d'] = self::count( $sql, 'partner_arbiter_blocked', $d30 );
		$out['action'] = 'enforce' === LNKup_Attribution_Arbiter::mode() ? 'partner_arbiter_blocked' : 'partner_arbiter_shadow_block';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$meta = (array) $wpdb->get_col( $wpdb->prepare( "SELECT metadata FROM {$table} WHERE action = %s AND created_at >= %s ORDER BY id DESC LIMIT %d", $out['action'], $d30, self::BREAKDOWN_CAP ) );
		foreach ( $meta as $json ) {
			$m       = json_decode( (string) $json, true );
			$reason  = sanitize_key( (string) ( $m['reason'] ?? 'unknown' ) ) ?: 'unknown';
			$program = sanitize_key( (string) ( $m['program'] ?? 'unknown' ) ) ?: 'unknown';
			$out['by_reason'][ $reason ]   = ( $out['by_reason'][ $reason ] ?? 0 ) + 1;
			$out['by_program'][ $program ] = ( $out['by_program'][ $program ] ?? 0 ) + 1;
		}
		arsort( $out['by_reason'] );
		arsort( $out['by_program'] );
		$out['truncated'] = count( $meta ) >= self::BREAKDOWN_CAP;
		return $out;
	}

	/**
	 * @return array{conflict:int, clawback:int, oldest_conflict_days:int}
	 */
	private static function reviews(): array {
		global $wpdb;
		$table = LNKup_Partner_Reviews::table();
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE type = %s AND status = 'open'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$oldest = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(created_at) FROM {$table} WHERE type = %s AND status = 'open'", 'attribution_conflict' ) );
		return array(
			'conflict'             => self::count( $sql, 'attribution_conflict' ),
			'clawback'             => self::count( $sql, 'clawback' ),
			'oldest_conflict_days' => '' !== $oldest ? self::days_since( $oldest ) : -1,
		);
	}

	/**
	 * Partner ledger (connector_id > 0) checks that should always be zero.
	 *
	 * @return array<string, int>
	 */
	public static function integrity(): array {
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		return array(
			'approved_early'   => self::count( "SELECT COUNT(*) FROM {$table} WHERE connector_id > 0 AND reward_status IN ('approved','paid') AND approved_at IS NOT NULL AND available_at IS NOT NULL AND approved_at < available_at" ),
			'negative_net'     => self::count( "SELECT COUNT(*) FROM {$table} WHERE connector_id > 0 AND amount - reversed_amount < -0.005" ),
			'duplicate_keys'   => self::count( "SELECT COUNT(*) FROM (SELECT idempotency_key FROM {$table} WHERE idempotency_key IS NOT NULL GROUP BY idempotency_key HAVING COUNT(*) > 1) d" ),
			'paid_without_ref' => self::count( "SELECT COUNT(*) FROM {$table} WHERE connector_id > 0 AND reward_status = 'paid' AND payout_reference = ''" ),
		);
	}

	/** Integrity checks that block enforce. paid_without_ref is a warning (legacy payouts predate references). */
	private static function gating_integrity(): array {
		return array( 'approved_early', 'negative_net', 'duplicate_keys' );
	}

	/**
	 * @return array<string, array{label:string, level:string, fix:string}>
	 */
	private static function integrity_labels(): array {
		return array(
			'approved_early'   => array(
				'label' => __( 'Commissions approved before their hold ended', 'lnkup-marketplace' ),
				'level' => 'fail',
				'fix'   => __( 'Hold them in the Commission Ledger and check who approved them.', 'lnkup-marketplace' ),
			),
			'negative_net'     => array(
				'label' => __( 'Commissions reversed by more than their amount', 'lnkup-marketplace' ),
				'level' => 'fail',
				'fix'   => __( 'Check the refund history for these payments before paying anything.', 'lnkup-marketplace' ),
			),
			'duplicate_keys'   => array(
				'label' => __( 'Duplicate commission idempotency keys', 'lnkup-marketplace' ),
				'level' => 'fail',
				'fix'   => __( 'The unique index is missing; reload once so the migration re-creates it.', 'lnkup-marketplace' ),
			),
			'paid_without_ref' => array(
				'label' => __( 'Paid commissions without a payout reference', 'lnkup-marketplace' ),
				'level' => 'warn',
				'fix'   => __( 'Usually payouts recorded before references existed. Note the reference in the ledger CSV.', 'lnkup-marketplace' ),
			),
		);
	}

	/**
	 * @return array{active:bool, version:string, flow_partners:bool, push_ready:bool}
	 */
	private static function comms(): array {
		$active = class_exists( 'LNKup_Comms_Settings' ) && class_exists( 'LNKup_Comms_Registry' );
		$out    = array(
			'active'        => $active,
			'version'       => defined( 'LNKUP_COMMS_VERSION' ) ? (string) LNKUP_COMMS_VERSION : '',
			'flow_partners' => false,
			'push_ready'    => false,
		);
		if ( ! $active ) {
			return $out;
		}
		$out['flow_partners'] = LNKup_Comms_Settings::enabled( 'flow_partners' );
		$def                  = LNKup_Comms_Registry::get( 'p_commission_recorded' );
		$out['push_ready']    = is_array( $def ) && in_array( 'push', (array) ( $def['channels'] ?? array() ), true );
		return $out;
	}

	/** Source check that the gate recognises partner roles (no user is created or loaded). */
	private static function mobile_gate_admits_partners(): bool {
		try {
			$method = new ReflectionMethod( 'LNKup_Auth_REST', 'user_may_use_mobile' );
			$file   = (string) $method->getFileName();
			$lines  = $file && is_readable( $file ) ? file( $file ) : false;
			if ( ! $lines ) {
				return false;
			}
			$body = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
			return false !== strpos( $body, 'lnkup_partner' ) && false !== strpos( $body, 'is_connector' );
		} catch ( ReflectionException $e ) {
			return false;
		}
	}

	// ------------------------------------------------------------------ Helpers.

	/**
	 * Prepared COUNT(*) query, 0 on error.
	 *
	 * @param mixed ...$args Placeholder values.
	 */
	private static function count( string $sql, ...$args ): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$value = $args ? $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) ) : $wpdb->get_var( $sql );
		// phpcs:enable
		return (int) $value;
	}

	private static function ago( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', (int) strtotime( current_time( 'mysql' ) ) - $days * DAY_IN_SECONDS );
	}

	private static function days_since( string $mysql ): int {
		$then = strtotime( $mysql );
		if ( ! $then ) {
			return -1;
		}
		return (int) floor( ( (int) strtotime( current_time( 'mysql' ) ) - $then ) / DAY_IN_SECONDS );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function empty_shadow(): array {
		return array( 'total_7d' => 0, 'total_30d' => 0, 'blocked_7d' => 0, 'blocked_30d' => 0, 'action' => 'partner_arbiter_shadow_block', 'by_reason' => array(), 'by_program' => array(), 'truncated' => false );
	}

	/**
	 * @return array<string, int>
	 */
	private static function empty_integrity(): array {
		return array( 'approved_early' => 0, 'negative_net' => 0, 'duplicate_keys' => 0, 'paid_without_ref' => 0 );
	}
}
