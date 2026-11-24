<?php
/**
 * wp-admin → LNKup → Marketing (Marketing Command Center).
 *
 * One page over the systems that already exist: conversion registry, tracked links, analytics
 * taxonomy, market registry, comms campaigns, partner/cohort pages, resources hub, ad platforms,
 * Search Console (credential-gated) and SEO diagnostics. Every number is counted from stored
 * records; anything not connected says so. Revenue attribution (GMV, fees, refunds) is shown only
 * to people with financial access. Links out to Organizer Growth, Partnerships, Meta and
 * Communications instead of duplicating them.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Marketing_Admin {

	const SLUG   = 'lnkup-marketing';
	const CAP    = 'lnkup_manage_marketing';
	const ACTION = 'lnkup_marketing_admin';
	const EXPORT = 'lnkup_marketing_export';
	const TABS   = array(
		'overview'     => 'Overview',
		'seo'          => 'SEO',
		'markets'      => 'Markets',
		'campaigns'    => 'Campaigns',
		'distribution' => 'Distribution',
		'content'      => 'Content',
		'integrations' => 'Integrations',
		'diagnostics'  => 'Diagnostics',
	);
	const STALE_DAYS = 180;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_' . self::EXPORT, array( __CLASS__, 'export' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'lnkup-marketplace', __( 'Marketing Command Center', 'lnkup-marketplace' ), __( 'Marketing', 'lnkup-marketplace' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function can(): bool {
		return current_user_can( self::CAP ) || ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() );
	}

	public static function can_view_revenue(): bool {
		return current_user_can( 'lnkup_manage_marketplace_financials' ) || ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() );
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/* ------------------------------------------------------------ Filters */

	/**
	 * Date range and dimension filters from the request (validated).
	 *
	 * @param array<string,mixed> $src Source (GET).
	 * @return array{from:string,to:string,market:string,channel:string,campaign:string}
	 */
	public static function filters( array $src ): array {
		$date = static fn( $v ): string => is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		$to   = $date( $src['to'] ?? '' ) ?: current_time( 'Y-m-d' );
		$from = $date( $src['from'] ?? '' ) ?: gmdate( 'Y-m-d', strtotime( $to . ' -29 days' ) );
		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}
		return array(
			'from'     => $from,
			'to'       => $to,
			'market'   => sanitize_title( (string) ( $src['market'] ?? '' ) ),
			'channel'  => sanitize_key( (string) ( $src['channel'] ?? '' ) ),
			'campaign' => substr( sanitize_text_field( (string) ( $src['campaign'] ?? '' ) ), 0, 100 ),
		);
	}

	/* ------------------------------------------------------------ Data */

	/**
	 * Conversion counts (non-test) in the window, filtered by last-touch attribution.
	 *
	 * @param array<string,string> $f Filters.
	 * @return array<string,int>
	 */
	public static function conversions( array $f ): array {
		global $wpdb;
		if ( ! class_exists( 'LNKup_Meta_Tables' ) ) {
			return array();
		}
		$where = array( 'is_test = 0', 'created_at BETWEEN %s AND %s' );
		$vals  = array( $f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59' );
		foreach ( array( 'market' => 'market', 'channel' => 'utm_source', 'campaign' => 'utm_campaign' ) as $filter => $key ) {
			if ( '' !== $f[ $filter ] ) {
				$where[] = "JSON_UNQUOTE(JSON_EXTRACT(attribution_json, '$.lt.{$key}')) = %s";
				$vals[]  = $f[ $filter ];
			}
		}
		$sql  = 'SELECT event_name, COUNT(*) n FROM ' . LNKup_Meta_Tables::conversions() . ' WHERE ' . implode( ' AND ', $where ) . ' GROUP BY event_name';
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (string) $r['event_name'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Tracked link performance in the window, optionally filtered by channel/campaign.
	 *
	 * @param array<string,string> $f Filters.
	 * @return array<int,array<string,mixed>>
	 */
	public static function link_rows( array $f, int $limit = 25 ): array {
		global $wpdb;
		if ( ! class_exists( 'LNKup_Marketing_Tables' ) ) {
			return array();
		}
		$links = LNKup_Marketing_Tables::links();
		$daily = LNKup_Marketing_Tables::link_daily();
		$where = array( 'd.day BETWEEN %s AND %s' );
		$vals  = array( $f['from'], $f['to'] );
		if ( '' !== $f['channel'] ) {
			$where[] = 'l.channel = %s';
			$vals[]  = $f['channel'];
		}
		if ( '' !== $f['campaign'] ) {
			$where[] = '( l.campaign = %s OR l.utm_campaign = %s )';
			$vals[]  = $f['campaign'];
			$vals[]  = $f['campaign'];
		}
		if ( '' !== $f['market'] ) {
			$where[] = "( l.target_type = 'market' AND l.target_key = %s )";
			$vals[]  = $f['market'];
		}
		$vals[] = max( 1, min( 500, $limit ) );
		$sql    = "SELECT l.id, l.code, l.label, l.channel, l.campaign, l.target_type, l.target_id, l.target_key, l.owner_user_id, l.owner_type, l.status,
				SUM(d.clicks) clicks, SUM(d.unique_clicks) unique_clicks, SUM(d.bot_clicks) bot_clicks, SUM(d.signups) signups, SUM(d.applications) applications, SUM(d.bookings) bookings
			FROM {$links} l INNER JOIN {$daily} d ON d.link_id = l.id WHERE " . implode( ' AND ', $where ) . ' GROUP BY l.id ORDER BY unique_clicks DESC, clicks DESC LIMIT %d';
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Overview KPIs with definitions.
	 *
	 * @param array<string,string> $f Filters.
	 * @return array<int,array{label:string,value:int,definition:string}>
	 */
	public static function kpis( array $f ): array {
		$conv   = self::conversions( $f );
		$links  = self::link_rows( $f, 500 );
		$counts = class_exists( 'LNKup_Analytics_Taxonomy' ) ? LNKup_Analytics_Taxonomy::counts( $f['from'], $f['to'] ) : array();
		return array(
			array( 'label' => 'Signups', 'value' => (int) ( $conv['CompleteRegistration'] ?? 0 ), 'definition' => 'Completed registrations (conversion registry, test accounts excluded).' ),
			array( 'label' => 'Applications', 'value' => (int) ( $conv['SubmitApplication'] ?? 0 ), 'definition' => 'Submitted vendor applications.' ),
			array( 'label' => 'Paid bookings', 'value' => (int) ( $conv['Purchase'] ?? 0 ), 'definition' => 'Authoritative payment successes (Stripe webhook confirmed).' ),
			array( 'label' => 'Leads', 'value' => (int) ( $conv['Lead'] ?? 0 ), 'definition' => 'Vendor interest in an external event or a new market alert (once per person and entity).' ),
			array( 'label' => 'Tracked link visitors', 'value' => (int) array_sum( wp_list_pluck( $links, 'unique_clicks' ) ), 'definition' => 'Unique daily visitors through /l/ links (bots excluded).' ),
			array( 'label' => 'Market alerts', 'value' => (int) ( $counts['market_alert_created']['count'] ?? 0 ), 'definition' => 'Market alerts created in the window. Not filtered by attribution.' ),
		);
	}

	/**
	 * Market rows for the Markets tab.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function markets(): array {
		if ( ! class_exists( 'LNKup_Market_Pages' ) || ! class_exists( 'LNKup_Market_Registry' ) ) {
			return array();
		}
		$out = array();
		foreach ( LNKup_Market_Pages::get_all_markets() as $slug => $raw ) {
			$market = LNKup_Market_Pages::get_market_by_slug( (string) $slug, false );
			if ( ! is_array( $market ) ) {
				continue;
			}
			$health = LNKup_Market_Registry::health( $market );
			$demand = array_slice( LNKup_Market_Registry::demand( (string) $slug ), 0, 3, true );
			$out[]  = array_merge(
				$health,
				array(
					'slug'    => (string) $slug,
					'name'    => (string) $market['display_name'],
					'state'   => (string) $market['state'],
					'enabled' => ! empty( $market['enabled'] ),
					'status'  => (string) ( $market['status'] ?? LNKup_Market_Registry::status( $market ) ),
					'demand'  => $demand,
					'url'     => (string) $market['url'],
				)
			);
		}
		usort( $out, static fn( $a, $b ) => $b['open_events'] <=> $a['open_events'] ?: strcmp( $a['name'], $b['name'] ) );
		return $out;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function campaigns( int $limit = 25 ): array {
		global $wpdb;
		if ( ! class_exists( 'LNKup_Comms_Campaigns' ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, campaign_type, status, audience_role, total_eligible, total_queued, scheduled_at, started_at, completed_at, submitted_by, approved_by, approved_at, created_at FROM ' . LNKup_Comms_Campaigns::table() . " WHERE campaign_type = 'admin' ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Guides by status plus the review queue and stale articles.
	 *
	 * @return array{counts:array<string,int>,review:WP_Post[],stale:WP_Post[]}
	 */
	public static function content(): array {
		$cats = class_exists( 'LNKup_Resources_Hub' ) ? array_keys( LNKup_Resources_Hub::CATEGORIES ) : array();
		$base = array( 'post_type' => 'post', 'category_name' => implode( ',', $cats ), 'no_found_rows' => false, 'posts_per_page' => 1, 'fields' => 'ids' );
		$counts = array();
		foreach ( array( 'draft', 'pending', 'publish' ) as $status ) {
			$counts[ $status ] = $cats ? (int) ( new WP_Query( array_merge( $base, array( 'post_status' => $status ) ) ) )->found_posts : 0;
		}
		$review = $cats ? get_posts( array( 'post_type' => 'post', 'category_name' => implode( ',', $cats ), 'post_status' => 'pending', 'posts_per_page' => 20 ) ) : array();
		$stale  = $cats ? get_posts( array( 'post_type' => 'post', 'category_name' => implode( ',', $cats ), 'post_status' => 'publish', 'posts_per_page' => 20, 'orderby' => 'modified', 'order' => 'ASC', 'date_query' => array( array( 'column' => 'post_modified_gmt', 'before' => self::STALE_DAYS . ' days ago' ) ) ) ) : array();
		return array( 'counts' => $counts, 'review' => $review, 'stale' => $stale );
	}

	/**
	 * Health checks across marketing systems.
	 *
	 * @return array<int,array{severity:string,label:string,detail:string}>
	 */
	public static function diagnostics(): array {
		global $wpdb;
		$out = array();
		$add = static function ( string $severity, string $label, string $detail ) use ( &$out ): void {
			$out[] = array( 'severity' => $severity, 'label' => $label, 'detail' => $detail );
		};
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( class_exists( 'LNKup_Comms_Queue' ) && method_exists( 'LNKup_Comms_Queue', 'failed_actions' ) ) {
			$failed = (int) LNKup_Comms_Queue::failed_actions( DAY_IN_SECONDS );
			$add( $failed > 0 ? 'warning' : 'ok', 'Communication jobs failed (24h)', (string) $failed );
		}
		if ( class_exists( 'LNKup_Comms_Campaigns' ) ) {
			$failed = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LNKup_Comms_Campaigns::table() . " WHERE status = 'failed'" );
			$add( $failed > 0 ? 'error' : 'ok', 'Campaigns stopped with an error', (string) $failed );
		}
		if ( class_exists( 'LNKup_Marketing_Tables' ) ) {
			$rows = (array) $wpdb->get_results( 'SELECT platform, status, COUNT(*) n FROM ' . LNKup_Marketing_Tables::ads_outbox() . ' GROUP BY platform, status', ARRAY_A );
			$failed = 0;
			$pending = 0;
			foreach ( $rows as $r ) {
				$failed  += 'failed' === $r['status'] ? (int) $r['n'] : 0;
				$pending += 'pending' === $r['status'] ? (int) $r['n'] : 0;
			}
			$add( $failed > 0 ? 'warning' : 'ok', 'GA4 / TikTok server events failed', sprintf( '%d failed, %d waiting', $failed, $pending ) );
		}
		if ( class_exists( 'LNKup_Meta_Tables' ) ) {
			$conv = LNKup_Meta_Tables::conversions();
			$dupe = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( SELECT entity_id FROM {$conv} WHERE event_name = 'Purchase' AND entity_type = 'payment' AND is_test = 0 GROUP BY entity_id HAVING COUNT(*) > 1 ) d" );
			$add( $dupe > 0 ? 'error' : 'ok', 'Payments with more than one Purchase conversion', (string) $dupe );
			$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$conv} WHERE event_name = 'CompleteRegistration' AND is_test = 0 AND created_at >= %s", $since ) );
			$blank = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$conv} WHERE event_name = 'CompleteRegistration' AND is_test = 0 AND created_at >= %s AND ( attribution_json IS NULL OR attribution_json IN ('', '[]', '{}', 'null') )", $since ) );
			$share = $total > 0 ? round( 100 * $blank / $total ) : 0;
			$add( $share > 60 ? 'warning' : 'ok', 'Signups with no campaign or referral touch (30 days)', $total > 0 ? sprintf( '%d of %d (%d%%)', $blank, $total, $share ) : 'No signups yet' );
		}
		// phpcs:enable
		if ( class_exists( 'LNKup_Ad_Platforms' ) ) {
			$s = LNKup_Ad_Platforms::status();
			if ( ! empty( $s['ga4']['flag'] ) && '' === (string) $s['ga4']['measurement_id'] ) {
				$add( 'warning', 'GA4 is on without a measurement ID', 'Add the ID in Integrations settings or turn the flag off.' );
			}
			if ( ! empty( $s['tiktok']['flag'] ) && '' === (string) $s['tiktok']['pixel_id'] ) {
				$add( 'warning', 'TikTok is on without a pixel ID', 'Add the pixel ID or turn the flag off.' );
			}
		}
		if ( class_exists( 'LNKup_Marketing_Flags' ) && LNKup_Marketing_Flags::enabled( 'deep_links_v2' ) ) {
			$prints = get_option( 'lnkup_android_sha256_fingerprints', array() );
			if ( ! is_array( $prints ) || ! array_filter( $prints ) ) {
				$add( 'info', 'Android app links have no signing fingerprint', 'Android opens links in the browser until a SHA-256 fingerprint is added.' );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------ Actions */

	public static function handle(): void {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$op     = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		$tab    = isset( $_POST['tab'] ) && isset( self::TABS[ sanitize_key( wp_unslash( (string) $_POST['tab'] ) ) ] ) ? sanitize_key( wp_unslash( (string) $_POST['tab'] ) ) : 'overview';
		$notice = '';
		switch ( $op ) {
			case 'save_app':
				$input = isset( $_POST['app'] ) && is_array( $_POST['app'] ) ? wp_unslash( $_POST['app'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
				LNKup_App_Deep_Links::save_campaign( (array) $input );
				$prints = preg_split( '/[\s,]+/', strtoupper( (string) ( $input['fingerprints'] ?? '' ) ) ) ?: array();
				$prints = array_values( array_unique( array_filter( $prints, static fn( $p ) => (bool) preg_match( '/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $p ) ) ) );
				update_option( 'lnkup_android_sha256_fingerprints', $prints, false );
				$notice = 'app_saved';
				break;
			case 'save_cohort':
				$input  = isset( $_POST['cohort'] ) && is_array( $_POST['cohort'] ) ? wp_unslash( $_POST['cohort'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in save().
				$result = LNKup_Cohort_Landings::save( (array) $input );
				$notice = is_wp_error( $result ) ? 'cohort_error' : 'cohort_saved';
				break;
			case 'delete_cohort':
				LNKup_Cohort_Landings::delete( isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( (string) $_POST['slug'] ) ) : '' );
				$notice = 'cohort_deleted';
				break;
			case 'seed_outlines':
				$notice = 'seeded_' . count( LNKup_Resources_Hub::seed_outlines( get_current_user_id() ) );
				break;
			case 'save_flags':
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'Only site administrators can change flags.', 'lnkup-marketplace' ), 403 );
				}
				$on = isset( $_POST['flags'] ) && is_array( $_POST['flags'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['flags'] ) ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				foreach ( array_keys( LNKup_Marketing_Flags::FLAGS ) as $flag ) {
					if ( LNKup_Marketing_Flags::enabled( $flag ) !== in_array( $flag, $on, true ) ) {
						LNKup_Marketing_Flags::set( $flag, in_array( $flag, $on, true ) );
					}
				}
				$notice = 'flags_saved';
				break;
			case 'seo_refresh':
				if ( class_exists( 'LNKup_SEO_Diagnostics' ) ) {
					LNKup_SEO_Diagnostics::run( true );
				}
				$notice = 'seo_refreshed';
				break;
		}
		wp_safe_redirect( self::url( array( 'tab' => $tab, 'notice' => $notice ) ) );
		exit;
	}

	/**
	 * CSV exports (links, conversions, taxonomy; revenue only with financial access).
	 */
	public static function export(): void {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		check_admin_referer( self::EXPORT );
		$f    = self::filters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in filters().
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( (string) $_GET['type'] ) ) : '';
		$rows = array();
		switch ( $type ) {
			case 'links':
				$rows[] = array( 'code', 'label', 'channel', 'campaign', 'target', 'owner_type', 'status', 'clicks', 'unique_clicks', 'bot_clicks', 'signups', 'applications', 'bookings' );
				foreach ( self::link_rows( $f, 500 ) as $r ) {
					$rows[] = array( $r['code'], $r['label'], $r['channel'], $r['campaign'], $r['target_type'] . ( $r['target_key'] ? ':' . $r['target_key'] : ( $r['target_id'] ? ':' . $r['target_id'] : '' ) ), $r['owner_type'], $r['status'], $r['clicks'], $r['unique_clicks'], $r['bot_clicks'], $r['signups'], $r['applications'], $r['bookings'] );
				}
				break;
			case 'kpis':
				$rows[] = array( 'metric', 'value', 'definition' );
				foreach ( self::kpis( $f ) as $k ) {
					$rows[] = array( $k['label'], $k['value'], $k['definition'] );
				}
				break;
			case 'taxonomy':
				$rows[] = array( 'event', 'meaning', 'status', 'count' );
				foreach ( LNKup_Analytics_Taxonomy::counts( $f['from'], $f['to'] ) as $name => $c ) {
					$rows[] = array( $name, $c['meaning'], $c['status'], $c['count'] );
				}
				break;
			case 'revenue':
				if ( ! self::can_view_revenue() ) {
					wp_die( esc_html__( 'Revenue attribution needs financial access.', 'lnkup-marketplace' ), 403 );
				}
				$dimension = isset( $_GET['dimension'] ) ? sanitize_key( wp_unslash( (string) $_GET['dimension'] ) ) : 'utm_source';
				$model     = isset( $_GET['model'] ) && 'first' === $_GET['model'] ? 'first' : 'last';
				$csv       = LNKup_Revenue_Attribution::to_csv( LNKup_Revenue_Attribution::report( $f['from'], $f['to'], $dimension, $model ) );
				self::send_csv( 'lnkup-revenue-attribution-' . $f['from'] . '-' . $f['to'] . '.csv', $csv );
				return;
			default:
				wp_die( esc_html__( 'Unknown export.', 'lnkup-marketplace' ), 400 );
		}
		$fh = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		foreach ( $rows as $row ) {
			fputcsv( $fh, array_map( array( __CLASS__, 'csv_cell' ), $row ) );
		}
		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		self::send_csv( 'lnkup-marketing-' . $type . '-' . $f['from'] . '-' . $f['to'] . '.csv', $csv );
	}

	/**
	 * Neutralize spreadsheet formulas in exported cells.
	 *
	 * @param mixed $value Cell.
	 */
	public static function csv_cell( $value ): string {
		$value = (string) $value;
		return '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $value ) ? "'" . $value : $value;
	}

	private static function send_csv( string $filename, string $csv ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body.
		exit;
	}

	/* ------------------------------------------------------------ Render */

	public static function render(): void {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = 'partners' === $tab ? 'distribution' : $tab;
		$tab = isset( self::TABS[ $tab ] ) ? $tab : 'overview';
		$f   = self::filters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in filters().
		echo '<div class="wrap lnkup-marketing-admin" data-testid="lnkup-marketing-admin">';
		echo '<h1>' . esc_html__( 'Marketing Command Center', 'lnkup-marketplace' ) . '</h1>';
		self::render_related();
		self::render_notice();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::TABS as $key => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( array_merge( array( 'tab' => $key ), array_intersect_key( $f, array_flip( array( 'from', 'to', 'market', 'channel', 'campaign' ) ) ) ) ) ), $tab === $key ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		if ( in_array( $tab, array( 'overview', 'distribution' ), true ) ) {
			self::render_filters( $tab, $f );
		}
		call_user_func( array( __CLASS__, 'tab_' . $tab ), $f );
		echo '</div>';
	}

	private static function render_related(): void {
		$links = array();
		if ( class_exists( 'LNKup_Organizer_Growth_Admin' ) && class_exists( 'LNKup_Org_Growth' ) && current_user_can( LNKup_Org_Growth::CAP ) ) {
			$links[] = array( 'Organizer Growth', admin_url( 'admin.php?page=' . LNKup_Organizer_Growth_Admin::SLUG ) );
		}
		if ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() ) {
			$links[] = array( 'Partnerships & Referrals', admin_url( 'admin.php?page=lnkup-partnerships' ) );
			$links[] = array( 'Communications', admin_url( 'admin.php?page=lnkup-communications' ) );
		}
		if ( current_user_can( 'lnkup_manage_settings' ) || ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() ) ) {
			$links[] = array( 'Meta Integration', admin_url( 'admin.php?page=lnkup-meta-health' ) );
			$links[] = array( 'City Markets', admin_url( 'admin.php?page=lnkup-city-markets' ) );
		}
		if ( ! $links ) {
			return;
		}
		echo '<p class="lnkup-mc-related">' . esc_html__( 'Related:', 'lnkup-marketplace' ) . ' ';
		echo implode( ' · ', array_map( static fn( $l ) => '<a href="' . esc_url( $l[1] ) . '">' . esc_html( $l[0] ) . '</a>', $links ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per item.
		echo '</p>';
	}

	private static function render_notice(): void {
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map    = array(
			'app_saved'      => array( 'success', 'App settings saved.' ),
			'cohort_saved'   => array( 'success', 'Cohort page saved.' ),
			'cohort_error'   => array( 'error', 'A cohort needs a title and an address.' ),
			'cohort_deleted' => array( 'success', 'Cohort page deleted.' ),
			'seo_refreshed'  => array( 'success', 'SEO diagnostics refreshed.' ),
			'flags_saved'    => array( 'success', 'Feature flags saved.' ),
		);
		if ( 0 === strpos( $notice, 'seeded_' ) ) {
			$map[ $notice ] = array( 'success', sprintf( '%d guide outline drafts created.', (int) substr( $notice, 7 ) ) );
		}
		if ( isset( $_GET['partner_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$map[ $notice = 'partner' ] = array( 'success', 'Partner page saved.' );
		} elseif ( isset( $_GET['partner_deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$map[ $notice = 'partner' ] = array( 'success', 'Partner page deleted.' );
		} elseif ( isset( $_GET['partner_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$map[ $notice = 'partner' ] = array( 'error', sanitize_text_field( wp_unslash( (string) $_GET['partner_error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( isset( $map[ $notice ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $notice ][0] ), esc_html( $map[ $notice ][1] ) );
		}
	}

	/**
	 * @param array<string,string> $f Filters.
	 */
	private static function render_filters( string $tab, array $f ): void {
		echo '<form method="get" class="lnkup-mc-filters" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:end">';
		printf( '<input type="hidden" name="page" value="%s"><input type="hidden" name="tab" value="%s">', esc_attr( self::SLUG ), esc_attr( $tab ) );
		printf( '<label>%s<br><input type="date" name="from" value="%s"></label>', esc_html__( 'From', 'lnkup-marketplace' ), esc_attr( $f['from'] ) );
		printf( '<label>%s<br><input type="date" name="to" value="%s"></label>', esc_html__( 'To', 'lnkup-marketplace' ), esc_attr( $f['to'] ) );
		echo '<label>' . esc_html__( 'Market', 'lnkup-marketplace' ) . '<br><select name="market"><option value="">' . esc_html__( 'All', 'lnkup-marketplace' ) . '</option>';
		foreach ( class_exists( 'LNKup_Market_Pages' ) ? LNKup_Market_Pages::get_all_markets() : array() as $slug => $m ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $slug ), selected( $f['market'], $slug, false ), esc_html( (string) ( $m['display_name'] ?? $m['city'] ?? $slug ) ) );
		}
		echo '</select></label>';
		printf( '<label>%s<br><input type="text" name="channel" value="%s" placeholder="instagram" size="12"></label>', esc_html__( 'Channel / source', 'lnkup-marketplace' ), esc_attr( $f['channel'] ) );
		printf( '<label>%s<br><input type="text" name="campaign" value="%s" size="16"></label>', esc_html__( 'Campaign', 'lnkup-marketplace' ), esc_attr( $f['campaign'] ) );
		echo '<button class="button">' . esc_html__( 'Apply', 'lnkup-marketplace' ) . '</button></form>';
	}

	/**
	 * @param array<string,string> $f Filters.
	 */
	private static function export_link( string $type, array $f, string $label, array $extra = array() ): string {
		$url = wp_nonce_url( add_query_arg( array_merge( array( 'action' => self::EXPORT, 'type' => $type ), $f, $extra ), admin_url( 'admin-post.php' ) ), self::EXPORT );
		return '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * @param string[]                $head Column labels.
	 * @param array<int,array<int,string>> $rows Escaped HTML cells.
	 */
	private static function table( array $head, array $rows, string $empty ): void {
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html( $empty ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $head as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . implode( '</td><td>', $row ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells escaped by callers.
		}
		echo '</tbody></table>';
	}

	private static function form_open( string $op, string $tab ): void {
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s"><input type="hidden" name="op" value="%s"><input type="hidden" name="tab" value="%s">', esc_attr( self::ACTION ), esc_attr( $op ), esc_attr( $tab ) );
		wp_nonce_field( self::ACTION );
	}

	/* ------------------------------------------------------------ Tabs */

	/**
	 * @param array<string,string> $f Filters.
	 */
	private static function tab_overview( array $f ): void {
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin:12px 0" data-testid="lnkup-mc-kpis">';
		foreach ( self::kpis( $f ) as $k ) {
			printf( '<div class="card" style="margin:0;max-width:none"><h3 style="margin:0 0 4px">%s</h3><p style="font-size:28px;margin:0">%s</p><p class="description">%s</p></div>', esc_html( $k['label'] ), esc_html( number_format_i18n( $k['value'] ) ), esc_html( $k['definition'] ) );
		}
		echo '</div><p>' . self::export_link( 'kpis', $f, 'Export KPIs (CSV)' ) . ' ' . self::export_link( 'taxonomy', $f, 'Export event taxonomy (CSV)' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<h2>' . esc_html__( 'Analytics event taxonomy', 'lnkup-marketplace' ) . '</h2>';
		$rows = array();
		foreach ( class_exists( 'LNKup_Analytics_Taxonomy' ) ? LNKup_Analytics_Taxonomy::counts( $f['from'], $f['to'] ) : array() as $name => $c ) {
			$rows[] = array( '<code>' . esc_html( $name ) . '</code>', esc_html( $c['meaning'] ), esc_html( str_replace( '_', ' ', $c['status'] ) ), esc_html( number_format_i18n( $c['count'] ) ) );
		}
		self::table( array( 'Event', 'Meaning', 'Source', 'Count' ), $rows, 'No analytics events recorded yet.' );

		echo '<h2>' . esc_html__( 'Revenue attribution', 'lnkup-marketplace' ) . '</h2>';
		if ( ! self::can_view_revenue() ) {
			echo '<p class="description">' . esc_html__( 'Revenue attribution includes payment amounts and needs financial access. Ask a finance admin for this report.', 'lnkup-marketplace' ) . '</p>';
			return;
		}
		$dimension = isset( $_GET['dimension'] ) ? sanitize_key( wp_unslash( (string) $_GET['dimension'] ) ) : 'utm_source'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$model     = isset( $_GET['model'] ) && 'first' === $_GET['model'] ? 'first' : 'last'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$report    = LNKup_Revenue_Attribution::report( $f['from'], $f['to'], $dimension, $model );
		echo '<form method="get" style="margin:8px 0">';
		printf( '<input type="hidden" name="page" value="%s"><input type="hidden" name="tab" value="overview">', esc_attr( self::SLUG ) );
		foreach ( array( 'from', 'to', 'market', 'channel', 'campaign' ) as $k ) {
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $k ), esc_attr( $f[ $k ] ) );
		}
		echo '<select name="dimension">';
		foreach ( LNKup_Revenue_Attribution::DIMENSIONS as $d ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $d ), selected( $report['dimension'], $d, false ), esc_html( $d ) );
		}
		echo '</select> <select name="model">';
		printf( '<option value="last"%s>Last touch</option><option value="first"%s>First touch</option>', selected( $report['model'], 'last', false ), selected( $report['model'], 'first', false ) );
		echo '</select> <button class="button">' . esc_html__( 'Update', 'lnkup-marketplace' ) . '</button> ' . self::export_link( 'revenue', $f, 'Export (CSV)', array( 'dimension' => $report['dimension'], 'model' => $report['model'] ) ) . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$rows  = array();
		$money = static fn( $v ): string => esc_html( '$' . number_format_i18n( (float) $v, 2 ) );
		foreach ( $report['rows'] as $r ) {
			$rows[] = array( esc_html( (string) $r['key'] ), esc_html( (string) $r['bookings'] ), $money( $r['gmv'] ), $money( $r['refunds'] ), $money( $r['platform_fee_net'] ), $money( $r['commissions'] ), $money( $r['net_revenue'] ), $report['spend_matched'] ? $money( $r['spend'] ) : '—' );
		}
		self::table( array( 'Source', 'Bookings', 'GMV', 'Refunds', 'Net platform fees', 'Partner commissions', 'Net revenue', 'Ad spend' ), $rows, 'No paid bookings in this window.' );
		if ( ! $report['spend_matched'] ) {
			echo '<p class="description">' . esc_html__( 'Ad spend is recorded by channel or campaign in Organizer Growth, so it is compared only in the utm_source and utm_campaign views.', 'lnkup-marketplace' ) . '</p>';
		}
	}

	/**
	 * @param array<string,string> $f Filters.
	 */
	private static function tab_seo( array $f ): void {
		$gsc = class_exists( 'LNKup_Search_Console' ) ? LNKup_Search_Console::status() : array( 'connected' => false, 'property' => '', 'reason' => '' );
		echo '<h2>' . esc_html__( 'Google Search Console', 'lnkup-marketplace' ) . '</h2>';
		if ( ! $gsc['connected'] ) {
			echo '<p data-testid="lnkup-mc-gsc-status"><strong>' . esc_html__( 'Not connected.', 'lnkup-marketplace' ) . '</strong> ' . esc_html( $gsc['reason'] ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Add LNKUP_GSC_SERVICE_ACCOUNT (path to the service-account JSON, outside the web root) and LNKUP_GSC_PROPERTY to wp-config.php, and give the service account read access to the property.', 'lnkup-marketplace' ) . '</p>';
		} else {
			$data = LNKup_Search_Console::performance( gmdate( 'Y-m-d', strtotime( '-30 days' ) ), gmdate( 'Y-m-d', strtotime( '-3 days' ) ), 'page' );
			if ( is_wp_error( $data ) ) {
				echo '<p><strong>' . esc_html__( 'Connected, but Google returned an error:', 'lnkup-marketplace' ) . '</strong> ' . esc_html( $data->get_error_message() ) . '</p>';
			} else {
				printf( '<p>%s · %s clicks · %s impressions (%s)</p>', esc_html( $gsc['property'] ), esc_html( number_format_i18n( (int) $data['totals']['clicks'] ) ), esc_html( number_format_i18n( (int) $data['totals']['impressions'] ) ), esc_html__( 'last 30 days, Google data', 'lnkup-marketplace' ) );
				$rows = array();
				foreach ( $data['rows'] as $r ) {
					$rows[] = array( '<a href="' . esc_url( $r['key'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) wp_parse_url( $r['key'], PHP_URL_PATH ) ) . '</a>', esc_html( (string) $r['clicks'] ), esc_html( (string) $r['impressions'] ), esc_html( $r['ctr'] . '%' ), esc_html( (string) $r['position'] ) );
				}
				self::table( array( 'Page', 'Clicks', 'Impressions', 'CTR', 'Avg. position' ), $rows, 'Google has no search data for this period yet.' );
			}
		}

		echo '<h2>' . esc_html__( 'Site diagnostics', 'lnkup-marketplace' ) . '</h2>';
		self::form_open( 'seo_refresh', 'seo' );
		echo '<p><button class="button">' . esc_html__( 'Re-run checks', 'lnkup-marketplace' ) . '</button></p></form>';
		$diag = class_exists( 'LNKup_SEO_Diagnostics' ) ? LNKup_SEO_Diagnostics::run( false ) : array( 'issues' => array(), 'policy_active' => false );
		if ( empty( $diag['policy_active'] ) ) {
			echo '<p class="description">' . esc_html__( 'The index policy flag (seo_index_policy) is off, so the clean URLs, schema and sitemap rules are not active.', 'lnkup-marketplace' ) . '</p>';
		}
		$rows = array();
		foreach ( (array) $diag['issues'] as $issue ) {
			$samples = array_map( static fn( $s ) => is_array( $s ) ? (string) ( $s['url'] ?? $s['title'] ?? wp_json_encode( $s ) ) : (string) $s, (array) ( $issue['samples'] ?? array() ) );
			$rows[]  = array( esc_html( ucfirst( (string) $issue['severity'] ) ), esc_html( (string) $issue['message'] ), esc_html( (string) $issue['count'] ), esc_html( implode( ', ', array_slice( $samples, 0, 3 ) ) ) );
		}
		self::table( array( 'Severity', 'Issue', 'Count', 'Examples' ), $rows, 'No SEO issues found.' );
	}

	/**
	 * @param array<string,string> $f Filters.
	 */
	private static function tab_markets( array $f ): void {
		$rows = array();
		foreach ( self::markets() as $m ) {
			$demand = array();
			foreach ( $m['demand'] as $cat => $n ) {
				$demand[] = esc_html( $cat . ' (' . $n . ')' );
			}
			$rows[] = array(
				'<a href="' . esc_url( $m['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $m['name'] . ( $m['state'] ? ', ' . $m['state'] : '' ) ) . '</a>',
				esc_html( $m['enabled'] ? LNKup_Market_Registry::status_label( $m['status'] ) : 'Off' ),
				esc_html( (string) $m['open_events'] ),
				esc_html( (string) $m['open_opportunities'] ),
				esc_html( (string) $m['active_organizers'] ),
				esc_html( (string) $m['vendors_based'] ),
				$demand ? implode( ', ', $demand ) : '—',
			);
		}
		echo '<p class="description">' . esc_html__( 'Live counts from published, open opportunities. Status: active needs 3+ open events. Demand is anonymous category taps on the market page (90 days).', 'lnkup-marketplace' ) . '</p>';
		self::table( array( 'Market', 'Status', 'Open events', 'Open spaces', 'Organizers', 'Vendors based there', 'Top demand' ), $rows, 'No city markets are configured.' );
	}

	/**
	 * @param array<string,string> $f Filters.
	 */
	private static function tab_campaigns( array $f ): void {
		if ( ! class_exists( 'LNKup_Comms_Campaigns' ) ) {
			echo '<p>' . esc_html__( 'The LNKup Communications plugin is not active.', 'lnkup-marketplace' ) . '</p>';
			return;
		}
		$scheduling = class_exists( 'LNKup_Comms_Campaign_Workflow' ) && LNKup_Comms_Campaign_Workflow::active();
		echo '<p class="description">' . esc_html( $scheduling ? 'Campaigns need a second person to approve before they send. Consent, unsubscribes and frequency caps are always applied.' : 'Scheduled, approved campaigns are off (campaign_scheduling flag). Campaigns below were sent from Communications.' ) . '</p>';
		$name  = static fn( $id ): string => $id ? esc_html( (string) ( get_userdata( (int) $id )->display_name ?? '#' . $id ) ) : '—';
		$rows  = array();
		$waiting = 0;
		foreach ( self::campaigns() as $c ) {
			$waiting += 'pending_approval' === $c['status'] ? 1 : 0;
			$rows[]   = array( esc_html( (string) $c['name'] ), esc_html( str_replace( '_', ' ', (string) $c['status'] ) ), esc_html( (string) $c['audience_role'] ), esc_html( (string) $c['total_queued'] ), esc_html( (string) ( $c['scheduled_at'] ?: '—' ) ), $name( $c['submitted_by'] ), $name( $c['approved_by'] ) );
		}
		if ( $waiting ) {
			printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( sprintf( _n( '%d campaign is waiting for approval.', '%d campaigns are waiting for approval.', $waiting, 'lnkup-marketplace' ), $waiting ) ) );
		}
		self::table( array( 'Campaign', 'Status', 'Audience', 'Queued', 'Scheduled', 'Submitted by', 'Approved by' ), $rows, 'No campaigns yet.' );
		if ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() ) {
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=lnkup-communications&tab=campaigns' ) ) . '">' . esc_html__( 'Open campaigns in Communications', 'lnkup-marketplace' ) . '</a></p>';
		}
	}

	/**
	 * @param array<string,string> $f Filters.
	 */
	private static function tab_distribution( array $f ): void {
		echo '<h2>' . esc_html__( 'Tracked links', 'lnkup-marketplace' ) . '</h2>';
		if ( ! class_exists( 'LNKup_Tracking_Links' ) || ! LNKup_Tracking_Links::active() ) {
			echo '<p class="description">' . esc_html__( 'Tracked links are off (tracking_links flag).', 'lnkup-marketplace' ) . '</p>';
		} else {
			$rows = array();
			foreach ( self::link_rows( $f ) as $r ) {
				$owner  = get_userdata( (int) $r['owner_user_id'] );
				$target = $r['target_type'] . ( $r['target_key'] ? ': ' . $r['target_key'] : ( (int) $r['target_id'] ? ': ' . html_entity_decode( get_the_title( (int) $r['target_id'] ), ENT_QUOTES, 'UTF-8' ) : '' ) );
				$rows[] = array( '<code>' . esc_html( (string) $r['code'] ) . '</code> ' . esc_html( (string) $r['label'] ), esc_html( $owner ? $owner->display_name . ' (' . $r['owner_type'] . ')' : '—' ), esc_html( $target ), esc_html( (string) $r['channel'] ), esc_html( (string) $r['unique_clicks'] ), esc_html( (string) $r['signups'] ), esc_html( (string) $r['applications'] ), esc_html( (string) $r['bookings'] ) );
			}
			self::table( array( 'Link', 'Owner', 'Target', 'Channel', 'Visitors', 'Signups', 'Applications', 'Bookings' ), $rows, 'No tracked link visits in this window.' );
			echo '<p>' . self::export_link( 'links', $f, 'Export links (CSV)' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<h2>' . esc_html__( 'Weekly digests', 'lnkup-marketplace' ) . '</h2>';
		$digests = array();
		foreach ( self::markets() as $m ) {
			if ( $m['enabled'] && $m['open_events'] > 0 && class_exists( 'LNKup_Market_Registry' ) ) {
				$digests[] = '<a href="' . esc_url( LNKup_Market_Registry::digest_url( $m['slug'] ) ) . '" target="_blank" rel="noopener">' . esc_html( $m['name'] ) . '</a>';
			}
		}
		echo '<p>' . ( $digests ? implode( ' · ', $digests ) : esc_html__( 'No market has open events this week.', 'lnkup-marketplace' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="description">' . esc_html__( 'Organizers can embed their open spaces on their own site from Marketing in their dashboard (list embeds read only public data).', 'lnkup-marketplace' ) . '</p>';

		self::render_partner_pages();
	}

	private static function render_partner_pages(): void {
		echo '<h2 id="partners">' . esc_html__( 'Partner pages', 'lnkup-marketplace' ) . '</h2>';
		if ( ! class_exists( 'LNKup_Partner_Landings' ) ) {
			return;
		}
		if ( ! LNKup_Partner_Landings::active() ) {
			echo '<p class="description">' . esc_html__( 'Partner pages need the tracking_links flag.', 'lnkup-marketplace' ) . '</p>';
		}
		$rows = array();
		foreach ( LNKup_Partner_Landings::all() as $page ) {
			$partner = class_exists( 'LNKup_Partner_Organizations' ) ? LNKup_Partner_Organizations::get( (int) $page['partner_id'] ) : null;
			$delete  = sprintf( '<form method="post" action="%s" style="display:inline"><input type="hidden" name="action" value="lnkup_partner_landing_delete"><input type="hidden" name="slug" value="%s">%s<button class="button-link-delete" onclick="return confirm(\'Delete this partner page?\')">%s</button></form>', esc_url( admin_url( 'admin-post.php' ) ), esc_attr( (string) $page['slug'] ), wp_nonce_field( 'lnkup_partner_landing_delete', '_wpnonce', true, false ), esc_html__( 'Delete', 'lnkup-marketplace' ) );
			$rows[]  = array( '<a href="' . esc_url( LNKup_Partner_Landings::url( (string) $page['slug'] ) ) . '" target="_blank" rel="noopener">/with/' . esc_html( (string) $page['slug'] ) . '/</a>', esc_html( $partner ? (string) $partner['name'] : '#' . (int) $page['partner_id'] ), esc_html( (string) $page['audience'] ), esc_html( ! empty( $page['enabled'] ) ? 'Live' : 'Off' ), $delete );
		}
		self::table( array( 'Page', 'Partner', 'Audience', 'Status', '' ), $rows, 'No partner pages yet.' );

		$partners = class_exists( 'LNKup_Partner_Organizations' ) ? array_merge( ...array_map( static fn( $stage ) => LNKup_Partner_Organizations::query( array( 'stage' => $stage, 'per_page' => 100 ) )['items'], LNKup_Partner_Landings::APPROVED ) ) : array();
		if ( ! $partners ) {
			echo '<p class="description">' . esc_html__( 'Partner pages can only be created for partners at the agreement stage or later (Partnerships & Referrals).', 'lnkup-marketplace' ) . '</p>';
			return;
		}
		printf( '<form method="post" action="%s" style="max-width:640px"><input type="hidden" name="action" value="lnkup_partner_landing_save">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'lnkup_partner_landing_save' );
		echo '<h3>' . esc_html__( 'Add or update a partner page', 'lnkup-marketplace' ) . '</h3><table class="form-table"><tr><th>' . esc_html__( 'Partner', 'lnkup-marketplace' ) . '</th><td><select name="partner_id" required>';
		foreach ( $partners as $p ) {
			printf( '<option value="%d">%s</option>', (int) $p['id'], esc_html( (string) $p['name'] ) );
		}
		echo '</select></td></tr>';
		printf( '<tr><th>%s</th><td>/with/<input name="slug" pattern="[a-z0-9-]{2,60}" placeholder="partner-name"></td></tr>', esc_html__( 'Address', 'lnkup-marketplace' ) );
		printf( '<tr><th>%s</th><td><select name="audience"><option value="vendor">Vendors</option><option value="organizer">Organizers</option></select></td></tr>', esc_html__( 'Audience', 'lnkup-marketplace' ) );
		printf( '<tr><th>%s</th><td><input name="headline" class="regular-text" maxlength="120"></td></tr>', esc_html__( 'Headline', 'lnkup-marketplace' ) );
		printf( '<tr><th>%s</th><td><textarea name="intro" rows="3" class="large-text" maxlength="600"></textarea></td></tr>', esc_html__( 'Intro', 'lnkup-marketplace' ) );
		echo '<tr><th>' . esc_html__( 'Market (optional)', 'lnkup-marketplace' ) . '</th><td><select name="market"><option value="">—</option>';
		foreach ( class_exists( 'LNKup_Market_Pages' ) ? LNKup_Market_Pages::get_all_markets() : array() as $slug => $m ) {
			printf( '<option value="%s">%s</option>', esc_attr( (string) $slug ), esc_html( (string) ( $m['display_name'] ?? $slug ) ) );
		}
		echo '</select></td></tr><tr><th>' . esc_html__( 'Live', 'lnkup-marketplace' ) . '</th><td><label><input type="checkbox" name="enabled" value="1" checked> ' . esc_html__( 'Publish the page', 'lnkup-marketplace' ) . '</label></td></tr></table>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Save partner page', 'lnkup-marketplace' ) . '</button></p></form>';
	}

	/**
	 * @param array<string,string> $f Filters.
	 */
	private static function tab_content( array $f ): void {
		echo '<h2>' . esc_html__( 'Resources hub', 'lnkup-marketplace' ) . '</h2>';
		if ( ! class_exists( 'LNKup_Resources_Hub' ) || ! LNKup_Resources_Hub::active() ) {
			echo '<p class="description">' . esc_html__( 'The resources hub is off (resources_hub flag). Drafts can still be prepared.', 'lnkup-marketplace' ) . '</p>';
		}
		$c = self::content();
		printf( '<p>%s</p>', esc_html( sprintf( 'Published guides: %d · Waiting for review: %d · Drafts: %d', $c['counts']['publish'], $c['counts']['pending'], $c['counts']['draft'] ) ) );
		self::form_open( 'seed_outlines', 'content' );
		echo '<p><button class="button">' . esc_html__( 'Create guide outline drafts', 'lnkup-marketplace' ) . '</button> <span class="description">' . esc_html__( 'Adds outline drafts once (never published automatically).', 'lnkup-marketplace' ) . '</span></p></form>';

		$row = static function ( WP_Post $post ): array {
			$checks = class_exists( 'LNKup_Resources_Hub' ) && LNKup_Resources_Hub::checklist_complete( $post->ID ) ? 'Complete' : 'Incomplete';
			return array( '<a href="' . esc_url( (string) get_edit_post_link( $post->ID ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>', esc_html( (string) get_the_author_meta( 'display_name', (int) $post->post_author ) ), esc_html( $checks ), esc_html( mysql2date( 'M j, Y', $post->post_modified ) ) );
		};
		echo '<h3>' . esc_html__( 'Review queue', 'lnkup-marketplace' ) . '</h3>';
		self::table( array( 'Guide', 'Author', 'Editorial checklist', 'Updated' ), array_map( $row, $c['review'] ), 'Nothing is waiting for review.' );
		echo '<h3>' . esc_html( sprintf( 'Not updated in %d days', self::STALE_DAYS ) ) . '</h3>';
		self::table( array( 'Guide', 'Author', 'Editorial checklist', 'Updated' ), array_map( $row, $c['stale'] ), 'No stale guides.' );

		echo '<h2>' . esc_html__( 'Organizer cohort pages', 'lnkup-marketplace' ) . '</h2>';
		$rows = array();
		foreach ( class_exists( 'LNKup_Cohort_Landings' ) ? LNKup_Cohort_Landings::all() : array() as $page ) {
			ob_start();
			self::form_open( 'delete_cohort', 'content' );
			printf( '<input type="hidden" name="slug" value="%s"><button class="button-link-delete" onclick="return confirm(\'Delete this cohort page?\')">%s</button></form>', esc_attr( (string) $page['slug'] ), esc_html__( 'Delete', 'lnkup-marketplace' ) );
			$rows[] = array( '<a href="' . esc_url( LNKup_Cohort_Landings::url( (string) $page['slug'] ) ) . '" target="_blank" rel="noopener">' . esc_html( (string) $page['title'] ) . '</a>', esc_html( count( (array) $page['speakers'] ) . ' speakers' ), esc_html( ! empty( $page['enabled'] ) ? 'Live' : 'Off' ), (string) ob_get_clean() );
		}
		self::table( array( 'Cohort', 'Speakers', 'Status', '' ), $rows, 'No cohort pages yet.' );
		if ( class_exists( 'LNKup_Cohort_Landings' ) && ! LNKup_Cohort_Landings::form_open() ) {
			echo '<p class="description">' . esc_html__( 'Interest forms stay closed until Organizer Growth is turned on (requests become organizer leads).', 'lnkup-marketplace' ) . '</p>';
		}
		self::form_open( 'save_cohort', 'content' );
		echo '<h3>' . esc_html__( 'Add or update a cohort page', 'lnkup-marketplace' ) . '</h3><table class="form-table">';
		printf( '<tr><th>%s</th><td>/cohort/<input name="cohort[slug]" required pattern="[a-z0-9-]{2,60}"></td></tr>', esc_html__( 'Address', 'lnkup-marketplace' ) );
		printf( '<tr><th>%s</th><td><input name="cohort[title]" required class="regular-text" maxlength="120"></td></tr>', esc_html__( 'Title', 'lnkup-marketplace' ) );
		printf( '<tr><th>%s</th><td><textarea name="cohort[intro]" rows="3" class="large-text"></textarea></td></tr>', esc_html__( 'Intro', 'lnkup-marketplace' ) );
		printf( '<tr><th>%s</th><td><textarea name="cohort[schedule]" rows="2" class="large-text"></textarea></td></tr>', esc_html__( 'Schedule', 'lnkup-marketplace' ) );
		for ( $i = 0; $i < 3; $i++ ) {
			printf( '<tr><th>%s %d</th><td><input name="cohort[speakers][%d][name]" placeholder="Name"> <input name="cohort[speakers][%d][role]" placeholder="Role" class="regular-text"></td></tr>', esc_html__( 'Speaker', 'lnkup-marketplace' ), $i + 1, $i, $i );
		}
		echo '<tr><th>' . esc_html__( 'Live', 'lnkup-marketplace' ) . '</th><td><label><input type="checkbox" name="cohort[enabled]" value="1"> ' . esc_html__( 'Publish the page', 'lnkup-marketplace' ) . '</label></td></tr></table>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Save cohort page', 'lnkup-marketplace' ) . '</button></p></form>';
	}

	/**
	 * @param array<string,string> $f Filters.
	 */
	private static function tab_integrations( array $f ): void {
		$yes  = static fn( bool $on, string $ok, string $no ): string => $on ? '<span style="color:#008a20">' . esc_html( $ok ) . '</span>' : esc_html( $no );
		$ads  = class_exists( 'LNKup_Ad_Platforms' ) ? LNKup_Ad_Platforms::status() : array();
		$gsc  = class_exists( 'LNKup_Search_Console' ) ? LNKup_Search_Console::status() : array( 'connected' => false );
		$meta = class_exists( 'LNKup_Meta_Config' ) ? ( '' !== LNKup_Meta_Config::dataset_id() && LNKup_Meta_Config::capi_enabled() ) : false;
		$comm = class_exists( 'LNKup_Comms_Settings' );
		$rows = array(
			array( 'Meta Pixel + Conversions API', $yes( $meta, 'Connected', 'Not configured' ), 'Managed in Meta Integration.' ),
			array( 'Google Analytics 4', $yes( ! empty( $ads['ga4']['client'] ), 'Sending', ! empty( $ads['ga4']['flag'] ) ? 'On, waiting for ID / production host' : 'Off' ), 'Flag ga4_enabled; measurement ID and API secret.' ),
			array( 'Google Ads conversions', $yes( '' !== (string) ( $ads['google_ads']['conversion_id'] ?? '' ), 'Configured', 'Not configured' ), 'Sent through GA4 tags when consent allows.' ),
			array( 'TikTok Pixel + Events API', $yes( ! empty( $ads['tiktok']['client'] ), 'Sending', ! empty( $ads['tiktok']['flag'] ) ? 'On, waiting for ID / production host' : 'Off' ), 'Flag tiktok_enabled.' ),
			array( 'Google Search Console', $yes( ! empty( $gsc['connected'] ), 'Connected', 'Not connected' ), 'Service account in wp-config.php.' ),
			array( 'Email', $yes( $comm && LNKup_Comms_Settings::enabled( 'email_channel' ), 'On' . ( $comm && LNKup_Comms_Settings::get( 'email_provider' ) ? ' (' . LNKup_Comms_Settings::get( 'email_provider' ) . ')' : ' (WordPress mail)' ), 'Off' ), 'LNKup Communications.' ),
			array( 'Push notifications', $yes( $comm && LNKup_Comms_Settings::enabled( 'push_channel' ), 'On (Expo)', 'Off' ), 'LNKup Communications.' ),
			array( 'SMS', esc_html( 'Not configured' ), 'No SMS provider is connected.' ),
		);
		self::table( array( 'Integration', 'Status', 'Notes' ), array_map( static fn( $r ) => array( esc_html( $r[0] ), $r[1], esc_html( $r[2] ) ), $rows ), '' );
		if ( ! empty( $ads ) && empty( $ads['host_allowed'] ) && empty( $ads['test_mode'] ) ) {
			echo '<p class="description">' . esc_html__( 'Ad platforms only send from the production host or in test mode, so test traffic never reaches live ad accounts.', 'lnkup-marketplace' ) . '</p>';
		}

		echo '<h2>' . esc_html__( 'Mobile app links', 'lnkup-marketplace' ) . '</h2>';
		$deep     = class_exists( 'LNKup_Marketing_Flags' ) && LNKup_Marketing_Flags::enabled( 'deep_links_v2' );
		$campaign = class_exists( 'LNKup_App_Deep_Links' ) ? LNKup_App_Deep_Links::campaign() : array( 'pt' => '', 'mt' => '8' );
		$prints   = get_option( 'lnkup_android_sha256_fingerprints', array() );
		echo '<p>' . wp_kses_post( $deep ? 'Event, market, tour and tracked links open the app (deep_links_v2 on). Turn this on only after the app version that handles them is live in the stores.' : 'Only profile links open the app (deep_links_v2 off).' ) . '</p>';
		self::form_open( 'save_app', 'integrations' );
		echo '<table class="form-table">';
		printf( '<tr><th>%s</th><td><input name="app[pt]" value="%s" inputmode="numeric" pattern="[0-9]*" maxlength="20"><p class="description">%s</p></td></tr>', esc_html__( 'App Store provider token (pt)', 'lnkup-marketplace' ), esc_attr( $campaign['pt'] ), esc_html__( 'From App Store Connect → App Analytics → Campaigns. Added with mt to App Store links that carry a campaign token (ct).', 'lnkup-marketplace' ) );
		printf( '<tr><th>%s</th><td><input name="app[mt]" value="%s" inputmode="numeric" pattern="[0-9]*" maxlength="3" size="4"></td></tr>', esc_html__( 'Media type (mt)', 'lnkup-marketplace' ), esc_attr( $campaign['mt'] ) );
		printf( '<tr><th>%s</th><td><textarea name="app[fingerprints]" rows="3" class="large-text code">%s</textarea><p class="description">%s</p></td></tr>', esc_html__( 'Android SHA-256 fingerprints', 'lnkup-marketplace' ), esc_textarea( implode( "\n", is_array( $prints ) ? $prints : array() ) ), esc_html__( 'One per line, from Play Console → App integrity → App signing (format AA:BB:…, 32 pairs). Served in /.well-known/assetlinks.json.', 'lnkup-marketplace' ) );
		echo '</table><p><button class="button button-primary">' . esc_html__( 'Save app settings', 'lnkup-marketplace' ) . '</button></p></form>';
	}

	/**
	 * @param array<string,string> $f Filters.
	 */
	private static function tab_diagnostics( array $f ): void {
		$labels = array( 'ok' => 'OK', 'info' => 'Info', 'warning' => 'Check', 'error' => 'Problem' );
		$rows   = array_map( static fn( $d ) => array( esc_html( $labels[ $d['severity'] ] ?? $d['severity'] ), esc_html( $d['label'] ), esc_html( $d['detail'] ) ), self::diagnostics() );
		self::table( array( 'Status', 'Check', 'Detail' ), $rows, 'No checks available.' );
		echo '<h2>' . esc_html__( 'Feature flags', 'lnkup-marketplace' ) . '</h2>';
		$admin = current_user_can( 'manage_options' );
		if ( $admin ) {
			self::form_open( 'save_flags', 'diagnostics' );
		}
		$rows = array();
		foreach ( class_exists( 'LNKup_Marketing_Flags' ) ? LNKup_Marketing_Flags::all() : array() as $flag => $on ) {
			$state  = $admin ? sprintf( '<label><input type="checkbox" name="flags[%s]" value="1"%s> %s</label>', esc_attr( $flag ), checked( $on, true, false ), esc_html__( 'On', 'lnkup-marketplace' ) ) : esc_html( $on ? 'On' : 'Off' );
			$rows[] = array( '<code>' . esc_html( $flag ) . '</code>', esc_html( LNKup_Marketing_Flags::FLAGS[ $flag ] ), $state );
		}
		self::table( array( 'Flag', 'Controls', 'State' ), $rows, '' );
		if ( $admin ) {
			echo '<p><button class="button button-primary">' . esc_html__( 'Save flags', 'lnkup-marketplace' ) . '</button></p></form>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'Only site administrators can change flags.', 'lnkup-marketplace' ) . '</p>';
	}
}
