<?php
/**
 * Acquisition landing pages (/for-organizers/, /for-vendors/) and their campaign variants
 * (/for-organizers/{slug}/, /for-vendors/{slug}/) for paid social and partner links.
 *
 * Variants are admin-authored copy stored in one option; they reuse the base page template, so
 * there is no second landing system. Query parameters can only narrow the page to a city or
 * category that exists in live listings; they never become copy or HTML on their own.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Campaign_Landings {

	const OPTION          = 'lnkup_campaign_landings';
	const QUERY_VAR       = 'lnkup_campaign';
	const PAGES_OPTION    = 'lnkup_campaign_landing_pages';
	const PAGES_VERSION   = '1';
	const ADMIN_SLUG      = 'lnkup-campaign-landings';
	const MAX_VARIANTS    = 40;

	/** audience => base page slug and theme template. */
	const PAGES = array(
		'organizer' => array( 'slug' => 'for-organizers', 'template' => 'page-templates/landing-organizers.php', 'title' => 'For Organizers' ),
		'vendor'    => array( 'slug' => 'for-vendors', 'template' => 'page-templates/landing-vendors.php', 'title' => 'For Vendors' ),
	);

	/** @var array<string,mixed>|null|false Resolved request context (false = not computed). */
	private static $context = false;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_rewrites' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_create_pages' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_unknown_variant' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'tag_meta_view' ), 20 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 20 );
		add_filter( 'rank_math/frontend/title', array( __CLASS__, 'document_title' ), 20 );
		add_filter( 'rank_math/frontend/description', array( __CLASS__, 'description' ), 20 );
		add_filter( 'rank_math/frontend/canonical', array( __CLASS__, 'canonical' ), 20 );
		add_filter( 'get_canonical_url', array( __CLASS__, 'canonical' ), 20 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_meta' ), 2 );
		add_action( 'wp_head', array( __CLASS__, 'print_home_schema' ), 3 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 30 );
		add_action( 'admin_post_lnkup_campaign_landing_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_lnkup_campaign_landing_delete', array( __CLASS__, 'handle_delete' ) );
	}

	public static function register_rewrites(): void {
		foreach ( self::PAGES as $page ) {
			add_rewrite_rule( '^' . $page['slug'] . '/([a-z0-9-]{1,60})/?$', 'index.php?pagename=' . $page['slug'] . '&' . self::QUERY_VAR . '=$matches[1]', 'top' );
		}
	}

	/**
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Create the two base pages once (never overwrites an existing page) and flush rewrites.
	 */
	public static function maybe_create_pages(): void {
		if ( self::PAGES_VERSION === get_option( self::PAGES_OPTION ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		foreach ( self::PAGES as $page ) {
			$existing = get_page_by_path( $page['slug'] );
			if ( $existing ) {
				if ( '' === (string) get_page_template_slug( $existing ) ) {
					update_post_meta( $existing->ID, '_wp_page_template', $page['template'] );
				}
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $page['title'],
					'post_name'    => $page['slug'],
					'post_content' => '',
				),
				true
			);
			if ( ! is_wp_error( $id ) ) {
				update_post_meta( (int) $id, '_wp_page_template', $page['template'] );
			}
		}
		self::register_rewrites();
		flush_rewrite_rules( false );
		update_option( self::PAGES_OPTION, self::PAGES_VERSION, false );
	}

	/**
	 * Audience of the current request (base page or variant), or empty.
	 */
	public static function current_audience(): string {
		foreach ( self::PAGES as $audience => $page ) {
			if ( is_page( $page['slug'] ) || is_page_template( $page['template'] ) ) {
				return $audience;
			}
		}
		return '';
	}

	/**
	 * All saved variants keyed by slug.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			return array();
		}
		$out = array();
		foreach ( $saved as $slug => $variant ) {
			if ( is_array( $variant ) ) {
				$out[ (string) $slug ] = self::normalize( $variant );
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $variant Raw variant.
	 * @return array<string,mixed>
	 */
	private static function normalize( array $variant ): array {
		return array_merge(
			array(
				'slug'      => '',
				'audience'  => 'vendor',
				'enabled'   => 0,
				'label'     => '',
				'eyebrow'   => '',
				'headline'  => '',
				'subhead'   => '',
				'cta_label' => '',
				'city'      => '',
				'state'     => '',
				'category'  => '',
				'image_id'  => 0,
				'indexable' => 0,
				'updated'   => '',
			),
			$variant
		);
	}

	/**
	 * Enabled variant for an audience and slug.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function variant( string $audience, string $slug ): ?array {
		$slug = sanitize_title( $slug );
		$all  = self::all();
		if ( '' === $slug || ! isset( $all[ $slug ] ) ) {
			return null;
		}
		$variant = $all[ $slug ];
		return ( ! empty( $variant['enabled'] ) && $audience === $variant['audience'] ) ? $variant : null;
	}

	/**
	 * Unknown or disabled variant slugs go back to the base landing (campaign parameters kept).
	 */
	public static function redirect_unknown_variant(): void {
		$slug     = (string) get_query_var( self::QUERY_VAR );
		$audience = self::current_audience();
		if ( '' === $slug || '' === $audience || null !== self::variant( $audience, $slug ) ) {
			return;
		}
		$keep = array();
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$keep[ $key ] = sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		wp_safe_redirect( add_query_arg( $keep, home_url( '/' . self::PAGES[ $audience ]['slug'] . '/' ) ), 302 );
		exit;
	}

	/**
	 * Request context for the landing template: audience, variant, and live-validated city/category.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function context(): ?array {
		if ( false !== self::$context ) {
			return self::$context;
		}
		$audience = self::current_audience();
		if ( '' === $audience ) {
			self::$context = null;
			return null;
		}
		$variant = self::variant( $audience, (string) get_query_var( self::QUERY_VAR ) );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$city     = isset( $_GET['city'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['city'] ) ) : '';
		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
		$category = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['category'] ) ) : '';
		$opp_id   = isset( $_GET['opportunity'] ) ? absint( wp_unslash( $_GET['opportunity'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $variant ) {
			$city     = '' !== $city ? $city : (string) $variant['city'];
			$state    = '' !== $state ? $state : (string) $variant['state'];
			$category = '' !== $category ? $category : (string) $variant['category'];
		}
		$filters = class_exists( 'LNKup_Public_Discovery_REST' )
			? LNKup_Public_Discovery_REST::validated_context( substr( $city, 0, 80 ), substr( $state, 0, 2 ), substr( $category, 0, 60 ) )
			: array();

		self::$context = array(
			'audience'    => $audience,
			'variant'     => $variant,
			'filters'     => $filters,
			'opportunity' => self::return_opportunity( $opp_id ),
		);
		return self::$context;
	}

	/**
	 * Open opportunity a visitor came from, so sign-up can send them straight back.
	 *
	 * @return array{id:int,title:string,url:string}|null
	 */
	private static function return_opportunity( int $opp_id ): ?array {
		if ( $opp_id < 1 || 'lnkup_opportunity' !== get_post_type( $opp_id ) || 'publish' !== get_post_status( $opp_id ) ) {
			return null;
		}
		if ( 'open' !== get_post_meta( $opp_id, '_lnkup_opportunity_status', true ) ) {
			return null;
		}
		$event_id = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
		if ( ! $event_id || 'publish' !== get_post_status( $event_id ) ) {
			return null;
		}
		$url = class_exists( 'LNKup_Organizer_Onboarding' ) ? (string) LNKup_Organizer_Onboarding::event_public_url( $event_id ) : '';
		if ( '' === $url ) {
			return null;
		}
		return array(
			'id'    => $opp_id,
			'title' => function_exists( 'lnkup_plain_title' ) ? lnkup_plain_title( get_post( $event_id ) ) : get_the_title( $event_id ),
			'url'   => $url,
		);
	}

	/**
	 * Landing views reuse the allowed ViewContent client event (same event_id in browser and
	 * server, consent rules unchanged); content_category tells organizer and vendor landings apart.
	 */
	public static function tag_meta_view(): void {
		if ( ! wp_script_is( 'lnkup-meta-pixel', 'enqueued' ) ) {
			return;
		}
		$ctx = self::context();
		if ( null === $ctx ) {
			return;
		}
		$data = array(
			'content_category' => $ctx['audience'] . '_landing',
			'content_name'     => $ctx['variant'] ? (string) $ctx['variant']['slug'] : 'default',
			'marketplace_role' => $ctx['audience'],
		);
		if ( ! empty( $ctx['filters']['city'] ) ) {
			$data['campaign_market'] = (string) $ctx['filters']['city'];
		}
		if ( ! empty( $ctx['filters']['category_label'] ) ) {
			$data['vendor_category'] = (string) $ctx['filters']['category_label'];
		}
		wp_add_inline_script( 'lnkup-meta-pixel', 'if(window.lnkupMeta){window.lnkupMeta.viewContent=' . wp_json_encode( $data ) . ';}', 'before' );
	}

	/**
	 * SEO copy for the base pages and variants.
	 *
	 * @return array{title:string,description:string}|null
	 */
	public static function seo_copy(): ?array {
		$ctx = self::context();
		if ( null === $ctx ) {
			return null;
		}
		if ( $ctx['variant'] && '' !== trim( (string) $ctx['variant']['headline'] ) ) {
			return array(
				'title'       => wp_strip_all_tags( (string) $ctx['variant']['headline'] ) . ' | LNKup',
				'description' => wp_strip_all_tags( (string) $ctx['variant']['subhead'] ),
			);
		}
		if ( 'organizer' === $ctx['audience'] ) {
			return array(
				'title'       => __( 'Fill Every Vendor Booth | LNKup for Event Organizers', 'lnkup-marketplace' ),
				'description' => __( 'Publish vendor opportunities, review applications, collect booth fees, and manage event-day check-in in one place. Keep selling tickets wherever you already do.', 'lnkup-marketplace' ),
			);
		}
		return array(
			'title'       => __( 'Find Vendor Events Near You | LNKup for Vendors', 'lnkup-marketplace' ),
			'description' => __( 'Discover festivals, markets, and pop-ups booking vendors. Create a free profile, apply in minutes, and pay your booth fee only after an organizer approves you.', 'lnkup-marketplace' ),
		);
	}

	/**
	 * @param string $title Title.
	 */
	public static function document_title( $title ) {
		$copy = self::seo_copy();
		return $copy ? $copy['title'] : $title;
	}

	/**
	 * @param string $description Description.
	 */
	public static function description( $description ) {
		$copy = self::seo_copy();
		return $copy && '' !== $copy['description'] ? $copy['description'] : $description;
	}

	/**
	 * Variants are canonical to their base landing.
	 *
	 * @param string $canonical Canonical URL.
	 */
	public static function canonical( $canonical ) {
		$ctx = self::context();
		if ( null === $ctx || ! $ctx['variant'] || ! empty( $ctx['variant']['indexable'] ) ) {
			return $canonical;
		}
		return home_url( '/' . self::PAGES[ $ctx['audience'] ]['slug'] . '/' );
	}

	/**
	 * @param array<string,mixed> $robots Robots.
	 * @return array<string,mixed>
	 */
	public static function robots( $robots ): array {
		$robots = is_array( $robots ) ? $robots : array();
		$ctx    = self::context();
		if ( null !== $ctx && $ctx['variant'] && empty( $ctx['variant']['indexable'] ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
		}
		return $robots;
	}

	/**
	 * Description and Open Graph tags when Rank Math is not handling them.
	 */
	public static function print_meta(): void {
		if ( ( function_exists( 'lnkup_seo_is_rankmath_active' ) && lnkup_seo_is_rankmath_active() ) || null === self::context() ) {
			return;
		}
		$copy = self::seo_copy();
		if ( ! $copy || '' === $copy['description'] ) {
			return;
		}
		echo '<meta name="description" content="' . esc_attr( $copy['description'] ) . '" />' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( $copy['description'] ) . '" />' . "\n";
	}

	/**
	 * Homepage WebSite (with a sitelinks search box pointing at Find Events) and Organization
	 * schema when Rank Math, which prints its own, is not active.
	 */
	public static function print_home_schema(): void {
		if ( ! is_front_page() || ( function_exists( 'lnkup_seo_is_rankmath_active' ) && lnkup_seo_is_rankmath_active() ) ) {
			return;
		}
		$feed  = class_exists( 'LNKup_Public_Discovery' ) ? LNKup_Public_Discovery::feed_url() : home_url( '/opportunities/' );
		$graph = array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				array(
					'@type'           => 'WebSite',
					'@id'             => home_url( '/#website' ),
					'name'            => 'LNKup',
					'url'             => home_url( '/' ),
					'potentialAction' => array(
						'@type'       => 'SearchAction',
						'target'      => array(
							'@type'       => 'EntryPoint',
							'urlTemplate' => add_query_arg( 'q', '{search_term_string}', $feed ),
						),
						'query-input' => 'required name=search_term_string',
					),
				),
				array(
					'@type' => 'Organization',
					'@id'   => home_url( '/#organization' ),
					'name'  => 'LNKup',
					'url'   => home_url( '/' ),
				),
			),
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>' . "\n";
	}

	/* ------------------------------------------------------------------ Admin */

	public static function admin_menu(): void {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Campaign Landings', 'lnkup-marketplace' ),
			__( 'Campaign Landings', 'lnkup-marketplace' ),
			'manage_options',
			self::ADMIN_SLUG,
			array( __CLASS__, 'render_admin' )
		);
	}

	/**
	 * @param array<string,mixed> $raw Raw input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function sanitize( array $raw ) {
		$slug = sanitize_title( (string) ( $raw['slug'] ?? '' ) );
		if ( '' === $slug || strlen( $slug ) > 60 ) {
			return new WP_Error( 'lnkup_landing_slug', __( 'Use a short slug with letters, numbers, and dashes.', 'lnkup-marketplace' ) );
		}
		$audience = in_array( $raw['audience'] ?? '', array( 'organizer', 'vendor' ), true ) ? (string) $raw['audience'] : 'vendor';
		$state    = function_exists( 'lnkup_canonical_state_code' )
			? lnkup_canonical_state_code( (string) ( $raw['state'] ?? '' ) )
			: strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $raw['state'] ?? '' ) ), 0, 2 ) );
		return array(
			'slug'      => $slug,
			'audience'  => $audience,
			'enabled'   => empty( $raw['enabled'] ) ? 0 : 1,
			'label'     => substr( sanitize_text_field( (string) ( $raw['label'] ?? '' ) ), 0, 80 ),
			'eyebrow'   => substr( sanitize_text_field( (string) ( $raw['eyebrow'] ?? '' ) ), 0, 80 ),
			'headline'  => substr( sanitize_text_field( (string) ( $raw['headline'] ?? '' ) ), 0, 120 ),
			'subhead'   => substr( sanitize_textarea_field( (string) ( $raw['subhead'] ?? '' ) ), 0, 280 ),
			'cta_label' => substr( sanitize_text_field( (string) ( $raw['cta_label'] ?? '' ) ), 0, 40 ),
			'city'      => substr( sanitize_text_field( (string) ( $raw['city'] ?? '' ) ), 0, 80 ),
			'state'     => $state,
			'category'  => substr( sanitize_text_field( (string) ( $raw['category'] ?? '' ) ), 0, 60 ),
			'image_id'  => absint( $raw['image_id'] ?? 0 ),
			'indexable' => empty( $raw['indexable'] ) ? 0 : 1,
			'updated'   => gmdate( 'c' ),
		);
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'lnkup-marketplace' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'lnkup_campaign_landing_save' );
		$raw     = isset( $_POST['landing'] ) && is_array( $_POST['landing'] ) ? wp_unslash( $_POST['landing'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$variant = self::sanitize( $raw );
		$back    = admin_url( 'admin.php?page=' . self::ADMIN_SLUG );
		if ( is_wp_error( $variant ) ) {
			wp_safe_redirect( add_query_arg( 'lnkup_error', rawurlencode( $variant->get_error_message() ), $back ) );
			exit;
		}
		$all      = get_option( self::OPTION, array() );
		$all      = is_array( $all ) ? $all : array();
		$original = sanitize_title( (string) ( $raw['original_slug'] ?? '' ) );
		if ( '' !== $original && $original !== $variant['slug'] ) {
			unset( $all[ $original ] );
		}
		if ( ! isset( $all[ $variant['slug'] ] ) && count( $all ) >= self::MAX_VARIANTS ) {
			wp_safe_redirect( add_query_arg( 'lnkup_error', rawurlencode( __( 'Variant limit reached. Delete an old variant first.', 'lnkup-marketplace' ) ), $back ) );
			exit;
		}
		$all[ $variant['slug'] ] = $variant;
		update_option( self::OPTION, $all, false );
		wp_safe_redirect( add_query_arg( 'lnkup_saved', '1', $back ) );
		exit;
	}

	public static function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'lnkup-marketplace' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'lnkup_campaign_landing_delete' );
		$slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( (string) $_POST['slug'] ) ) : '';
		$all  = get_option( self::OPTION, array() );
		if ( is_array( $all ) && isset( $all[ $slug ] ) ) {
			unset( $all[ $slug ] );
			update_option( self::OPTION, $all, false );
		}
		wp_safe_redirect( add_query_arg( 'lnkup_deleted', '1', admin_url( 'admin.php?page=' . self::ADMIN_SLUG ) ) );
		exit;
	}

	public static function variant_url( array $variant ): string {
		$audience = isset( self::PAGES[ $variant['audience'] ] ) ? $variant['audience'] : 'vendor';
		return home_url( '/' . self::PAGES[ $audience ]['slug'] . '/' . $variant['slug'] . '/' );
	}

	public static function render_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_media();
		$all     = self::all();
		$edit    = isset( $_GET['edit'] ) ? sanitize_title( wp_unslash( (string) $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $all[ $edit ] ) ? $all[ $edit ] : self::normalize( array( 'enabled' => 1 ) );
		$live    = class_exists( 'LNKup_Public_Discovery' ) ? LNKup_Public_Discovery::snapshot() : array( 'categories' => array(), 'city_index' => array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Campaign Landings', 'lnkup-marketplace' ); ?></h1>
			<p><?php esc_html_e( 'Variants of /for-organizers/ and /for-vendors/ for ads and partner links. They reuse the base page layout and live listings; only the copy and the default city or category change. Variants are not indexed unless you allow it.', 'lnkup-marketplace' ); ?></p>
			<?php if ( isset( $_GET['lnkup_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Variant saved.', 'lnkup-marketplace' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['lnkup_deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Variant deleted.', 'lnkup-marketplace' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['lnkup_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( (string) $_GET['lnkup_error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:1100px;margin:1rem 0 2rem">
				<thead><tr>
					<th><?php esc_html_e( 'Variant', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Audience', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'URL', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $all ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No variants yet. The base pages work without any.', 'lnkup-marketplace' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $all as $variant ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $variant['label'] ? $variant['label'] : $variant['slug'] ); ?></strong><br><span class="description"><?php echo esc_html( $variant['headline'] ); ?></span></td>
						<td><?php echo esc_html( 'organizer' === $variant['audience'] ? __( 'Organizers', 'lnkup-marketplace' ) : __( 'Vendors', 'lnkup-marketplace' ) ); ?></td>
						<td><code><?php echo esc_html( self::variant_url( $variant ) ); ?></code></td>
						<td><?php echo $variant['enabled'] ? esc_html__( 'Live', 'lnkup-marketplace' ) : esc_html__( 'Off', 'lnkup-marketplace' ); ?><?php echo $variant['indexable'] ? ' · ' . esc_html__( 'Indexable', 'lnkup-marketplace' ) : ''; ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::ADMIN_SLUG, 'edit' => $variant['slug'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'lnkup-marketplace' ); ?></a>
							<?php if ( $variant['enabled'] ) : ?>
								<a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( self::variant_url( $variant ) ); ?>"><?php esc_html_e( 'View', 'lnkup-marketplace' ); ?></a>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this variant?', 'lnkup-marketplace' ) ); ?>');">
								<input type="hidden" name="action" value="lnkup_campaign_landing_delete">
								<input type="hidden" name="slug" value="<?php echo esc_attr( $variant['slug'] ); ?>">
								<?php wp_nonce_field( 'lnkup_campaign_landing_delete' ); ?>
								<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'lnkup-marketplace' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo $edit && isset( $all[ $edit ] ) ? esc_html__( 'Edit variant', 'lnkup-marketplace' ) : esc_html__( 'Add variant', 'lnkup-marketplace' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lnkup_campaign_landing_save">
				<input type="hidden" name="landing[original_slug]" value="<?php echo esc_attr( (string) $current['slug'] ); ?>">
				<?php wp_nonce_field( 'lnkup_campaign_landing_save' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="lcl-audience"><?php esc_html_e( 'Audience', 'lnkup-marketplace' ); ?></label></th><td>
						<select id="lcl-audience" name="landing[audience]">
							<option value="vendor" <?php selected( $current['audience'], 'vendor' ); ?>><?php esc_html_e( 'Vendors (/for-vendors/…)', 'lnkup-marketplace' ); ?></option>
							<option value="organizer" <?php selected( $current['audience'], 'organizer' ); ?>><?php esc_html_e( 'Organizers (/for-organizers/…)', 'lnkup-marketplace' ); ?></option>
						</select></td></tr>
					<tr><th><label for="lcl-slug"><?php esc_html_e( 'Slug', 'lnkup-marketplace' ); ?></label></th><td><input id="lcl-slug" class="regular-text" name="landing[slug]" required maxlength="60" pattern="[a-z0-9-]+" value="<?php echo esc_attr( (string) $current['slug'] ); ?>"><p class="description"><?php esc_html_e( 'Lowercase letters, numbers, and dashes. Example: houston-food-trucks', 'lnkup-marketplace' ); ?></p></td></tr>
					<tr><th><label for="lcl-label"><?php esc_html_e( 'Internal name', 'lnkup-marketplace' ); ?></label></th><td><input id="lcl-label" class="regular-text" name="landing[label]" maxlength="80" value="<?php echo esc_attr( (string) $current['label'] ); ?>"></td></tr>
					<tr><th><label for="lcl-eyebrow"><?php esc_html_e( 'Eyebrow', 'lnkup-marketplace' ); ?></label></th><td><input id="lcl-eyebrow" class="regular-text" name="landing[eyebrow]" maxlength="80" value="<?php echo esc_attr( (string) $current['eyebrow'] ); ?>"></td></tr>
					<tr><th><label for="lcl-headline"><?php esc_html_e( 'Headline', 'lnkup-marketplace' ); ?></label></th><td><input id="lcl-headline" class="large-text" name="landing[headline]" maxlength="120" value="<?php echo esc_attr( (string) $current['headline'] ); ?>"></td></tr>
					<tr><th><label for="lcl-subhead"><?php esc_html_e( 'Subheadline', 'lnkup-marketplace' ); ?></label></th><td><textarea id="lcl-subhead" class="large-text" rows="3" name="landing[subhead]" maxlength="280"><?php echo esc_textarea( (string) $current['subhead'] ); ?></textarea></td></tr>
					<tr><th><label for="lcl-cta"><?php esc_html_e( 'Sign-up button label', 'lnkup-marketplace' ); ?></label></th><td><input id="lcl-cta" class="regular-text" name="landing[cta_label]" maxlength="40" value="<?php echo esc_attr( (string) $current['cta_label'] ); ?>"><p class="description"><?php esc_html_e( 'The main button opens sign-up for this audience, so describe joining (for example "Join free and apply").', 'lnkup-marketplace' ); ?></p></td></tr>
					<tr><th><label for="lcl-city"><?php esc_html_e( 'Default city', 'lnkup-marketplace' ); ?></label></th><td>
						<input id="lcl-city" class="regular-text" name="landing[city]" maxlength="80" list="lcl-cities" value="<?php echo esc_attr( (string) $current['city'] ); ?>">
						<input class="small-text" name="landing[state]" maxlength="2" placeholder="TX" value="<?php echo esc_attr( (string) $current['state'] ); ?>" aria-label="<?php esc_attr_e( 'State', 'lnkup-marketplace' ); ?>">
						<datalist id="lcl-cities"><?php foreach ( (array) ( $live['city_index'] ?? array() ) as $city ) : ?><option value="<?php echo esc_attr( (string) $city['city'] ); ?>"><?php endforeach; ?></datalist>
						<p class="description"><?php esc_html_e( 'Live listings narrow to this city when it has open events; otherwise the page shows all events.', 'lnkup-marketplace' ); ?></p></td></tr>
					<tr><th><label for="lcl-category"><?php esc_html_e( 'Default vendor category', 'lnkup-marketplace' ); ?></label></th><td>
						<input id="lcl-category" class="regular-text" name="landing[category]" maxlength="60" list="lcl-categories" value="<?php echo esc_attr( (string) $current['category'] ); ?>">
						<datalist id="lcl-categories"><?php foreach ( (array) ( $live['categories'] ?? array() ) as $cat ) : ?><option value="<?php echo esc_attr( (string) $cat['label'] ); ?>"><?php endforeach; ?></datalist></td></tr>
					<tr><th><label for="lcl-image"><?php esc_html_e( 'Hero image ID', 'lnkup-marketplace' ); ?></label></th><td><input id="lcl-image" class="small-text" type="number" min="0" name="landing[image_id]" value="<?php echo esc_attr( (string) $current['image_id'] ); ?>"><p class="description"><?php esc_html_e( 'Media Library attachment ID. Leave 0 to use the base page image. Use only photos you have rights to.', 'lnkup-marketplace' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th><td>
						<label><input type="checkbox" name="landing[enabled]" value="1" <?php checked( ! empty( $current['enabled'] ) ); ?>> <?php esc_html_e( 'Live', 'lnkup-marketplace' ); ?></label><br>
						<label><input type="checkbox" name="landing[indexable]" value="1" <?php checked( ! empty( $current['indexable'] ) ); ?>> <?php esc_html_e( 'Allow search engines to index this variant', 'lnkup-marketplace' ); ?></label></td></tr>
				</table>
				<?php submit_button( __( 'Save variant', 'lnkup-marketplace' ) ); ?>
			</form>
			<h2><?php esc_html_e( 'Tracking', 'lnkup-marketplace' ); ?></h2>
			<p><?php esc_html_e( 'Each landing view sends one ViewContent event (content_category organizer_landing or vendor_landing, content_name = variant slug) through the existing Meta setup, with the same consent rules and browser/server deduplication. Registrations are only reported after the account is created. UTM parameters are captured by LNKup attribution on any page.', 'lnkup-marketplace' ); ?></p>
		</div>
		<?php
	}
}
