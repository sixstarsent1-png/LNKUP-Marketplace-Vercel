<?php
/**
 * Organizer cohort landing pages /cohort/{slug}/ (flag resources_hub). No LMS.
 *
 * Content (title, intro, schedule text, speakers) is entered by staff. The interest form creates
 * an organizer lead (LNKup_Org_Leads, source `cohort`) or logs the request on the existing lead;
 * it needs the organizer growth module, is nonce-checked, has a honeypot and is rate limited per
 * IP hash. The form is a real POST, so it works on cached or script-less pages.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Cohort_Landings {

	const OPTION     = 'lnkup_cohort_landings';
	const QUERY_VAR  = 'lnkup_cohort';
	const RATE_LIMIT = 5;

	/** @var array<string,mixed>|null|false */
	private static $current = false;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'rewrites' ), 24 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'guard' ), 4 );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 30 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 40 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'title' ), 40 );
		add_action( 'admin_post_nopriv_lnkup_cohort_interest', array( __CLASS__, 'handle_interest' ) );
		add_action( 'admin_post_lnkup_cohort_interest', array( __CLASS__, 'handle_interest' ) );
	}

	public static function active(): bool {
		return class_exists( 'LNKup_Marketing_Flags' ) && LNKup_Marketing_Flags::enabled( 'resources_hub' );
	}

	public static function rewrites(): void {
		if ( self::active() ) {
			add_rewrite_rule( '^cohort/([a-z0-9-]{2,60})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
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
	 * @return array<string,mixed>|WP_Error
	 */
	public static function save( array $input ) {
		$slug  = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		$title = mb_substr( sanitize_text_field( (string) ( $input['title'] ?? '' ) ), 0, 120 );
		if ( strlen( $slug ) < 2 || '' === $title ) {
			return new WP_Error( 'lnkup_cohort', __( 'A cohort needs a title and an address.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$speakers = array();
		foreach ( array_slice( (array) ( $input['speakers'] ?? array() ), 0, 12 ) as $speaker ) {
			$name = mb_substr( sanitize_text_field( (string) ( $speaker['name'] ?? '' ) ), 0, 80 );
			if ( '' !== $name ) {
				$speakers[] = array(
					'name' => $name,
					'role' => mb_substr( sanitize_text_field( (string) ( $speaker['role'] ?? '' ) ), 0, 120 ),
					'bio'  => mb_substr( sanitize_textarea_field( (string) ( $speaker['bio'] ?? '' ) ), 0, 400 ),
				);
			}
		}
		$pages          = self::all();
		$pages[ $slug ] = array(
			'slug'       => $slug,
			'title'      => $title,
			'intro'      => mb_substr( sanitize_textarea_field( (string) ( $input['intro'] ?? '' ) ), 0, 800 ),
			'schedule'   => mb_substr( sanitize_textarea_field( (string) ( $input['schedule'] ?? '' ) ), 0, 600 ),
			'speakers'   => $speakers,
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
		return home_url( '/cohort/' . sanitize_title( $slug ) . '/' );
	}

	public static function form_open(): bool {
		return class_exists( 'LNKup_Org_Growth' ) && LNKup_Org_Growth::is_enabled() && class_exists( 'LNKup_Org_Leads' );
	}

	/**
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
		if ( $page && ! empty( $page['enabled'] ) ) {
			self::$current = $page;
		}
		return self::$current;
	}

	public static function guard(): void {
		if ( '' !== (string) get_query_var( self::QUERY_VAR ) && ! self::current() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * @param string $template Template.
	 */
	public static function template( $template ) {
		if ( ! self::current() ) {
			return $template;
		}
		nocache_headers();
		$theme = locate_template( 'page-templates/cohort-landing.php' );
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
		$page = self::current();
		return $page ? $page['title'] . ' | LNKup' : $title;
	}

	/**
	 * Interest submission: lead upsert. Returns the lead ID, or WP_Error.
	 *
	 * @return int|WP_Error
	 */
	public static function submit( string $slug, array $input ) {
		$page = self::all()[ sanitize_title( $slug ) ] ?? null;
		if ( ! $page || empty( $page['enabled'] ) || ! self::active() ) {
			return new WP_Error( 'lnkup_cohort_closed', __( 'This cohort is not taking interest right now.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! self::form_open() ) {
			return new WP_Error( 'lnkup_cohort_closed', __( 'Interest forms are closed right now. Please check back soon.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		if ( '' !== trim( (string) ( $input['company_website'] ?? '' ) ) ) {
			return new WP_Error( 'lnkup_cohort_spam', __( 'Your request could not be sent.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$org   = mb_substr( sanitize_text_field( (string) ( $input['organization'] ?? '' ) ), 0, 191 );
		$name  = mb_substr( sanitize_text_field( (string) ( $input['name'] ?? '' ) ), 0, 120 );
		if ( ! is_email( $email ) || '' === $org || '' === $name || empty( $input['consent'] ) ) {
			return new WP_Error( 'lnkup_cohort_invalid', __( 'Add your name, organization, a valid email and confirm we may contact you.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'lnkup_cohort_rl_' . substr( hash_hmac( 'sha256', $ip . gmdate( 'Y-m-d' ), wp_salt( 'nonce' ) ), 0, 20 );
		$hit = (int) get_transient( $key );
		if ( $hit >= self::RATE_LIMIT ) {
			return new WP_Error( 'lnkup_rate_limited', __( 'Too many requests. Please try again tomorrow.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $hit + 1, DAY_IN_SECONDS );
		$frequency = sanitize_key( (string) ( $input['event_frequency'] ?? '' ) );
		$result    = LNKup_Org_Leads::create(
			array(
				'name'                => $org,
				'email'               => $email,
				'city'                => (string) ( $input['city'] ?? '' ),
				'state'               => (string) ( $input['state'] ?? '' ),
				'event_frequency'     => $frequency,
				'source'              => 'cohort',
				'source_detail'       => 'cohort:' . $page['slug'],
				'source_campaign'     => (string) $page['title'],
				'contact_preference'  => 'email',
				'qualification_notes' => sprintf( 'Contact: %s', $name ),
			),
			array( 'actor' => 0, 'tags' => array( 'cohort' ) )
		);
		$lead_id = is_wp_error( $result ) ? (int) ( ( (array) $result->get_error_data() )['duplicate_of'] ?? 0 ) : (int) $result;
		if ( ! $lead_id ) {
			return is_wp_error( $result ) ? $result : new WP_Error( 'lnkup_cohort_failed', __( 'Your request could not be saved. Please try again.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		/* translators: 1: person, 2: cohort */
		LNKup_Org_Leads::log_activity( $lead_id, 'cohort_interest', array( 'actor' => 0, 'summary' => sprintf( __( '%1$s asked to join %2$s', 'lnkup-marketplace' ), $name, $page['title'] ), 'payload' => array( 'cohort' => $page['slug'] ) ) );
		if ( class_exists( 'LNKup_Analytics_Taxonomy' ) ) {
			LNKup_Analytics_Taxonomy::emit( 'cohort_interest', array( 'cohort' => $page['slug'] ), 0, 0, true );
		}
		return $lead_id;
	}

	public static function handle_interest(): void {
		$slug = sanitize_title( wp_unslash( (string) ( $_POST['cohort'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$back = self::url( $slug );
		if ( ! isset( $_POST['lnkup_cohort_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['lnkup_cohort_nonce'] ) ), 'lnkup_cohort_' . $slug ) ) {
			wp_safe_redirect( add_query_arg( 'cohort_error', 'expired', $back ) . '#interest' );
			exit;
		}
		$result = self::submit( $slug, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		wp_safe_redirect( add_query_arg( is_wp_error( $result ) ? array( 'cohort_error' => $result->get_error_code() ) : array( 'joined' => 1 ), $back ) . '#interest' );
		exit;
	}
}
