<?php
/**
 * Partner landing pages /with/{slug}/ (flag tracking_links).
 *
 * A page can only exist for a partner organization record (wp_lnkup_org_partners) at an approved
 * stage (agreement, active, performance review, renewal); a closed or unapproved partner's page
 * returns 404. Pages show live opportunities only (a market or category filter), co-brand with the
 * partner's name, record a first-party campaign touch (utm_source=partner, partner=ID), and pass
 * the partner's existing referral code to sign-up so the partner program credits as usual.
 * Campaign pages are noindex,follow.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Partner_Landings {

	const OPTION    = 'lnkup_partner_landings';
	const QUERY_VAR = 'lnkup_partner_landing';
	const APPROVED  = array( 'agreement', 'active', 'performance_review', 'renewal' );
	const MAX_PAGES = 200;

	/** @var array<string,mixed>|null|false */
	private static $current = false;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'rewrites' ), 23 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'prepare' ), 4 );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 30 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 40 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'title' ), 40 );
		add_action( 'admin_post_lnkup_partner_landing_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_lnkup_partner_landing_delete', array( __CLASS__, 'handle_delete' ) );
	}

	public static function active(): bool {
		return class_exists( 'LNKup_Tracking_Links' ) && LNKup_Tracking_Links::active();
	}

	public static function rewrites(): void {
		if ( self::active() ) {
			add_rewrite_rule( '^with/([a-z0-9-]{2,60})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		}
	}

	/**
	 * @param string[] $vars Vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Partner record when approved, else null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function approved_partner( int $partner_id ): ?array {
		if ( $partner_id < 1 || ! class_exists( 'LNKup_Partner_Organizations' ) ) {
			return null;
		}
		$partner = LNKup_Partner_Organizations::get( $partner_id );
		return ( $partner && in_array( (string) $partner['stage'], self::APPROVED, true ) ) ? $partner : null;
	}

	/**
	 * @return array<string,mixed>|WP_Error Saved page.
	 */
	public static function save( array $input ) {
		$partner_id = absint( $input['partner_id'] ?? 0 );
		$partner    = self::approved_partner( $partner_id );
		if ( ! $partner ) {
			return new WP_Error( 'lnkup_partner_landing', __( 'Partner pages need an approved partner record (agreement or active stage).', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		$slug = '' !== $slug ? $slug : sanitize_title( (string) $partner['name'] );
		if ( strlen( $slug ) < 2 ) {
			return new WP_Error( 'lnkup_partner_landing', __( 'Choose a page address.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$pages = self::all();
		if ( isset( $pages[ $slug ] ) && (int) $pages[ $slug ]['partner_id'] !== $partner_id ) {
			return new WP_Error( 'lnkup_partner_landing', __( 'That address is used by another partner.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		if ( ! isset( $pages[ $slug ] ) && count( $pages ) >= self::MAX_PAGES ) {
			return new WP_Error( 'lnkup_partner_landing', __( 'Too many partner pages.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$market   = sanitize_title( (string) ( $input['market'] ?? '' ) );
		$category = sanitize_text_field( (string) ( $input['category'] ?? '' ) );
		if ( '' !== $market && ( ! class_exists( 'LNKup_Market_Pages' ) || ! LNKup_Market_Pages::get_market_by_slug( $market, false ) ) ) {
			$market = '';
		}
		if ( '' !== $category && ( ! function_exists( 'lnkup_canonical_vendor_categories' ) || ! in_array( $category, lnkup_canonical_vendor_categories(), true ) ) ) {
			$category = '';
		}
		$pages[ $slug ] = array(
			'slug'       => $slug,
			'partner_id' => $partner_id,
			'audience'   => 'organizer' === ( $input['audience'] ?? '' ) ? 'organizer' : 'vendor',
			'headline'   => mb_substr( sanitize_text_field( (string) ( $input['headline'] ?? '' ) ), 0, 120 ),
			'intro'      => mb_substr( sanitize_textarea_field( (string) ( $input['intro'] ?? '' ) ), 0, 600 ),
			'market'     => $market,
			'category'   => $category,
			'enabled'    => ! empty( $input['enabled'] ),
			'updated_at' => current_time( 'mysql', true ),
		);
		update_option( self::OPTION, $pages, false );
		return $pages[ $slug ];
	}

	public static function delete( string $slug ): void {
		$pages = self::all();
		unset( $pages[ sanitize_title( $slug ) ] );
		update_option( self::OPTION, $pages, false );
	}

	public static function url( string $slug ): string {
		return home_url( '/with/' . sanitize_title( $slug ) . '/' );
	}

	/**
	 * Context for the current request (null = not a partner page or not allowed).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function current(): ?array {
		if ( false !== self::$current ) {
			return self::$current;
		}
		self::$current = null;
		$slug          = sanitize_title( (string) get_query_var( self::QUERY_VAR ) );
		if ( '' === $slug || ! self::active() ) {
			return null;
		}
		$page = self::all()[ $slug ] ?? null;
		if ( ! $page || empty( $page['enabled'] ) ) {
			return null;
		}
		$partner = self::approved_partner( (int) $page['partner_id'] );
		if ( ! $partner ) {
			return null;
		}
		self::$current = self::build( $page, $partner );
		return self::$current;
	}

	/**
	 * @param array<string,mixed> $page    Page config.
	 * @param array<string,mixed> $partner Partner record.
	 * @return array<string,mixed>
	 */
	public static function build( array $page, array $partner ): array {
		$cards  = array();
		$market = '' !== $page['market'] && class_exists( 'LNKup_Market_Pages' ) ? LNKup_Market_Pages::get_market_by_slug( (string) $page['market'] ) : null;
		if ( $market && class_exists( 'LNKup_Market_Registry' ) ) {
			$cards = LNKup_Market_Registry::cards( $market, array_filter( array( 'limit' => 9, 'terms' => '' !== $page['category'] ? array( $page['category'] ) : array() ) ) );
		} elseif ( class_exists( 'LNKup_Public_Discovery' ) ) {
			$cards = LNKup_Public_Discovery::event_cards( 'upcoming', 9, '' !== $page['category'] ? array( 'categories' => (string) $page['category'] ) : array() );
		}
		$ref    = self::partner_ref_code( (int) $partner['id'] );
		$base   = function_exists( 'lnkup_registration_url' ) ? lnkup_registration_url() : wp_registration_url();
		$signup = add_query_arg( array_filter( array( 'role' => $page['audience'], 'ref' => $ref, 'market' => $page['market'] ) ), $base );
		return array(
			'page'       => $page,
			'partner'    => array( 'id' => (int) $partner['id'], 'name' => (string) $partner['name'], 'website' => (string) ( $partner['website'] ?? '' ) ),
			'market'     => $market,
			'cards'      => $cards,
			'signup_url' => $signup,
			'feed_url'   => add_query_arg( array_filter( array( 'city' => $market['city'] ?? '', 'state' => $market['state'] ?? '', 'categories' => $page['category'] ) ), function_exists( 'lnkup_page_url_helper' ) ? lnkup_page_url_helper( 'opportunities' ) : home_url( '/opportunities/' ) ),
		);
	}

	/**
	 * The partner's existing referral code (via its connector/partner account), or ''.
	 */
	public static function partner_ref_code( int $partner_id ): string {
		global $wpdb;
		if ( ! class_exists( 'LNKup_Connector_Tables' ) || ! class_exists( 'LNKup_Connector' ) ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$connector = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Connector_Tables::connectors_table() . " WHERE partner_id = %d AND status = 'active' ORDER BY id ASC LIMIT 1", $partner_id ) );
		$code      = $connector ? LNKup_Connector::get_code_for_connector( $connector ) : null;
		return $code && ! empty( $code['code'] ) ? (string) $code['code'] : '';
	}

	public static function prepare(): void {
		if ( '' === (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		$ctx = self::current();
		if ( ! $ctx ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( class_exists( 'LNKup_Acquisition_Attribution' ) && ! ( class_exists( 'LNKup_Tracking_Links' ) && LNKup_Tracking_Links::is_bot( $ua ) ) ) {
			LNKup_Acquisition_Attribution::record_touch(
				array_filter(
					array(
						'utm_source'   => 'partner',
						'utm_medium'   => 'partner_page',
						'utm_campaign' => (string) $ctx['page']['slug'],
						'partner'      => (string) $ctx['partner']['id'],
						'market'       => (string) $ctx['page']['market'],
						'landing'      => '/with/' . $ctx['page']['slug'] . '/',
					)
				)
			);
		}
	}

	/**
	 * @param string $template Template.
	 */
	public static function template( $template ) {
		if ( ! self::current() ) {
			return $template;
		}
		$theme = locate_template( 'page-templates/partner-landing.php' );
		return $theme ? $theme : $template;
	}

	/**
	 * @param array<string,bool> $robots Robots.
	 * @return array<string,bool>
	 */
	public static function robots( $robots ): array {
		$robots = is_array( $robots ) ? $robots : array();
		if ( self::current() ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	/**
	 * @param string $title Title.
	 */
	public static function title( $title ) {
		$ctx = self::current();
		/* translators: %s: partner */
		return $ctx ? sprintf( __( '%s × LNKup', 'lnkup-marketplace' ), $ctx['partner']['name'] ) : $title;
	}

	public static function can_manage(): bool {
		return current_user_can( 'lnkup_manage_marketing' ) || current_user_can( 'manage_options' );
	}

	public static function handle_save(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		check_admin_referer( 'lnkup_partner_landing_save' );
		$result = self::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$args   = is_wp_error( $result ) ? array( 'partner_error' => rawurlencode( $result->get_error_message() ) ) : array( 'partner_saved' => 1 );
		flush_rewrite_rules( false );
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'lnkup-marketing', 'tab' => 'partners' ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_delete(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		check_admin_referer( 'lnkup_partner_landing_delete' );
		self::delete( sanitize_title( wp_unslash( (string) ( $_POST['slug'] ?? '' ) ) ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'lnkup-marketing', 'tab' => 'partners', 'partner_deleted' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
