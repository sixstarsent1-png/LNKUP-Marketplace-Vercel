<?php
/**
 * Resources hub on core WordPress posts (flag resources_hub). No separate CMS.
 *
 * - Categories `vendor-guides` and `organizer-guides`; `/resources/` lists both, `/guides/{slug}/`
 *   is the canonical URL of a guide post.
 * - Article + BreadcrumbList JSON-LD for guides; "related opportunities" from the post's tags when
 *   a tag names a city market or a vendor category (live listings only).
 * - Editorial review checklist (SEO reviewed, fact-checked, legal review when the topic covers
 *   permits, insurance or tax). Anyone below administrator (marketing staff given an author role,
 *   editors, authors) cannot publish a guide until the checklist is complete; the post is held as
 *   Pending review instead. The lnkup_marketing role itself has no post capabilities because
 *   LNKup's private CPTs map to the generic post capabilities.
 * - seed_outlines() creates six outline drafts once. Nothing is ever published automatically.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Resources_Hub {

	const CATEGORIES = array(
		'vendor-guides'    => 'Vendor guides',
		'organizer-guides' => 'Organizer guides',
	);
	const QUERY_VAR  = 'lnkup_resources';
	const CHECKLIST  = array( '_lnkup_review_seo', '_lnkup_review_facts', '_lnkup_review_legal' );
	const LEGAL_FLAG = '_lnkup_review_legal_needed';
	const SEEDED     = 'lnkup_resources_outlines_seeded';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'setup' ), 24 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'post_link', array( __CLASS__, 'post_link' ), 20, 2 );
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_post', array( __CLASS__, 'save_checklist' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'enforce_checklist' ), 20, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'held_notice' ) );
		add_action( 'wp_head', array( __CLASS__, 'schema' ), 30 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 35 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'title' ), 40 );
	}

	/**
	 * @param string $title Title.
	 */
	public static function title( $title ) {
		return self::active() && self::is_resources_index() ? __( 'Guides for vendors and organizers | LNKup', 'lnkup-marketplace' ) : $title;
	}

	public static function assets(): void {
		$cohort = class_exists( 'LNKup_Cohort_Landings' ) && LNKup_Cohort_Landings::current();
		if ( self::active() && ( is_singular( 'post' ) || is_category() || is_home() || $cohort ) ) {
			wp_enqueue_style( 'lnkup-resources', LNKUP_PLUGIN_URL . 'public/css/resources.css', array(), LNKUP_VERSION );
		}
	}

	public static function active(): bool {
		return class_exists( 'LNKup_Marketing_Flags' ) && LNKup_Marketing_Flags::enabled( 'resources_hub' );
	}

	public static function setup(): void {
		if ( ! self::active() ) {
			return;
		}
		foreach ( self::CATEGORIES as $slug => $name ) {
			if ( ! term_exists( $slug, 'category' ) ) {
				wp_insert_term( $name, 'category', array( 'slug' => $slug ) );
			}
		}
		add_rewrite_rule( '^resources/?$', 'index.php?category_name=' . implode( ',', array_keys( self::CATEGORIES ) ) . '&' . self::QUERY_VAR . '=1', 'top' );
		add_rewrite_rule( '^resources/page/([0-9]+)/?$', 'index.php?category_name=' . implode( ',', array_keys( self::CATEGORIES ) ) . '&' . self::QUERY_VAR . '=1&paged=$matches[1]', 'top' );
		add_rewrite_rule( '^guides/([^/]+)/?$', 'index.php?name=$matches[1]&post_type=post', 'top' );
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

	public static function is_guide( $post ): bool {
		$post = get_post( $post );
		return $post && 'post' === $post->post_type && has_category( array_keys( self::CATEGORIES ), $post );
	}

	/**
	 * @param string  $link Link.
	 * @param WP_Post $post Post.
	 */
	public static function post_link( $link, $post ) {
		if ( self::active() && $post instanceof WP_Post && 'publish' === $post->post_status && self::is_guide( $post ) ) {
			return home_url( '/guides/' . $post->post_name . '/' );
		}
		return $link;
	}

	public static function resources_url(): string {
		return home_url( '/resources/' );
	}

	public static function is_resources_index(): bool {
		return (bool) get_query_var( self::QUERY_VAR );
	}

	/* ------------------------------------------------------------ Related opportunities */

	/**
	 * Live event cards for a guide whose tags name a market city or a vendor category.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function related_cards( int $post_id, int $limit = 3 ): array {
		if ( ! class_exists( 'LNKup_Public_Discovery' ) ) {
			return array();
		}
		$context = array();
		$cats    = function_exists( 'lnkup_canonical_vendor_categories' ) ? lnkup_canonical_vendor_categories() : array();
		foreach ( (array) wp_get_post_tags( $post_id, array( 'fields' => 'names' ) ) as $tag ) {
			foreach ( $cats as $label ) {
				if ( 0 === strcasecmp( (string) $tag, (string) $label ) && empty( $context['categories'] ) ) {
					$context['categories'] = (string) $label;
				}
			}
			if ( empty( $context['city'] ) && class_exists( 'LNKup_Market_Pages' ) ) {
				foreach ( LNKup_Market_Pages::get_all_markets() as $market ) {
					if ( is_array( $market ) && ! empty( $market['enabled'] ) && 0 === strcasecmp( (string) $tag, (string) ( $market['city'] ?? '' ) ) ) {
						$context['city']  = (string) $market['city'];
						$context['state'] = (string) $market['state'];
					}
				}
			}
		}
		if ( ! $context ) {
			return array();
		}
		return LNKup_Public_Discovery::event_cards( 'upcoming', $limit, $context );
	}

	/* ------------------------------------------------------------ Schema */

	public static function schema(): void {
		if ( ! self::active() || ! is_singular( 'post' ) || ! self::is_guide( get_queried_object_id() ) ) {
			return;
		}
		$post  = get_post( get_queried_object_id() );
		$image = get_the_post_thumbnail_url( $post, 'large' );
		$graph = array(
			array_filter(
				array(
					'@type'            => 'Article',
					'headline'         => wp_strip_all_tags( get_the_title( $post ) ),
					'description'      => wp_strip_all_tags( get_the_excerpt( $post ) ),
					'datePublished'    => get_post_time( 'c', true, $post ),
					'dateModified'     => get_post_modified_time( 'c', true, $post ),
					'image'            => $image ? $image : null,
					'mainEntityOfPage' => get_permalink( $post ),
					'author'           => array( '@type' => 'Organization', 'name' => 'LNKup', 'url' => home_url( '/' ) ),
					'publisher'        => array( '@type' => 'Organization', 'name' => 'LNKup', 'url' => home_url( '/' ) ),
				)
			),
		);
		if ( class_exists( 'LNKup_SEO_Schema' ) ) {
			$graph[] = LNKup_SEO_Schema::breadcrumbs(
				array(
					array( __( 'Home', 'lnkup-marketplace' ), home_url( '/' ) ),
					array( __( 'Resources', 'lnkup-marketplace' ), self::resources_url() ),
					array( wp_strip_all_tags( get_the_title( $post ) ), get_permalink( $post ) ),
				)
			);
		}
		printf( '<script type="application/ld+json">%s</script>' . "\n", wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) );
	}

	/* ------------------------------------------------------------ Editorial checklist */

	public static function meta_box(): void {
		if ( self::active() ) {
			add_meta_box( 'lnkup-review-checklist', __( 'Review checklist', 'lnkup-marketplace' ), array( __CLASS__, 'render_meta_box' ), 'post', 'side', 'high' );
		}
	}

	/**
	 * @param WP_Post $post Post.
	 */
	public static function render_meta_box( $post ): void {
		wp_nonce_field( 'lnkup_review_checklist', 'lnkup_review_nonce' );
		$items = array(
			'_lnkup_review_seo'   => __( 'SEO reviewed (title, description, links)', 'lnkup-marketplace' ),
			'_lnkup_review_facts' => __( 'Facts checked against current sources', 'lnkup-marketplace' ),
			self::LEGAL_FLAG      => __( 'Covers permits, insurance or tax', 'lnkup-marketplace' ),
			'_lnkup_review_legal' => __( 'Legal or compliance review done (required for permit, insurance or tax topics)', 'lnkup-marketplace' ),
		);
		echo '<p class="description">' . esc_html__( 'Marketing team guides need this checklist before they can be published.', 'lnkup-marketplace' ) . '</p>';
		foreach ( $items as $key => $label ) {
			printf( '<p><label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label></p>', esc_attr( $key ), checked( (bool) get_post_meta( $post->ID, $key, true ), true, false ), esc_html( $label ) );
		}
	}

	/**
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post.
	 */
	public static function save_checklist( $post_id, $post ): void {
		if ( ! isset( $_POST['lnkup_review_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['lnkup_review_nonce'] ) ), 'lnkup_review_checklist' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( array_merge( self::CHECKLIST, array( self::LEGAL_FLAG ) ) as $key ) {
			update_post_meta( $post_id, $key, ! empty( $_POST[ $key ] ) ? 1 : 0 );
		}
	}

	/**
	 * Whether a checklist (submitted now or stored) is complete.
	 *
	 * @param array<string,mixed> $submitted Submitted fields (or empty to use stored meta).
	 */
	public static function checklist_complete( int $post_id, array $submitted = array() ): bool {
		$value = static fn( string $key ): bool => array_key_exists( $key, $submitted ) ? ! empty( $submitted[ $key ] ) : (bool) get_post_meta( $post_id, $key, true );
		if ( ! $value( '_lnkup_review_seo' ) || ! $value( '_lnkup_review_facts' ) ) {
			return false;
		}
		return ! $value( self::LEGAL_FLAG ) || $value( '_lnkup_review_legal' );
	}

	/**
	 * Hold a guide as Pending review when a marketing-role user publishes without the checklist.
	 *
	 * @param array<string,mixed> $data    Post data.
	 * @param array<string,mixed> $postarr Raw post array.
	 * @return array<string,mixed>
	 */
	public static function enforce_checklist( $data, $postarr ) {
		if ( ! self::active() || 'post' !== ( $data['post_type'] ?? '' ) || ! in_array( $data['post_status'] ?? '', array( 'publish', 'future' ), true ) ) {
			return $data;
		}
		// Applies to signed-in marketing staff and non-administrator authors or editors.
		if ( ! is_user_logged_in() || current_user_can( 'manage_options' ) ) {
			return $data;
		}
		$post_id = (int) ( $postarr['ID'] ?? 0 );
		$cats    = array_map( 'intval', (array) ( $postarr['post_category'] ?? wp_get_post_categories( $post_id ) ) );
		$guide   = false;
		foreach ( array_keys( self::CATEGORIES ) as $slug ) {
			$term  = get_term_by( 'slug', $slug, 'category' );
			$guide = $guide || ( $term && in_array( (int) $term->term_id, $cats, true ) );
		}
		$submitted = isset( $_POST['lnkup_review_nonce'] ) ? array_intersect_key( wp_unslash( $_POST ), array_flip( array_merge( self::CHECKLIST, array( self::LEGAL_FLAG ) ) ) ) + array_fill_keys( array_merge( self::CHECKLIST, array( self::LEGAL_FLAG ) ), 0 ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		if ( $guide && ! self::checklist_complete( $post_id, $submitted ) ) {
			$data['post_status'] = 'pending';
			set_transient( 'lnkup_review_held_' . get_current_user_id(), 1, MINUTE_IN_SECONDS );
		}
		return $data;
	}

	public static function held_notice(): void {
		if ( get_transient( 'lnkup_review_held_' . get_current_user_id() ) ) {
			delete_transient( 'lnkup_review_held_' . get_current_user_id() );
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This guide was saved as Pending review. Complete the review checklist before publishing.', 'lnkup-marketplace' ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------ Outline drafts */

	/**
	 * Six outline drafts (once). Drafts only: never published, no statistics or claims.
	 *
	 * @return int[] Created post IDs.
	 */
	public static function seed_outlines( int $author = 0 ): array {
		if ( get_option( self::SEEDED ) ) {
			return array();
		}
		self::setup();
		$note     = __( 'Outline only. Write from first-hand experience and current, cited sources. Do not add statistics, testimonials or legal advice without review.', 'lnkup-marketplace' );
		$outlines = array(
			array( 'vendor-guides', __( 'How to apply for vendor booths: a step-by-step checklist', 'lnkup-marketplace' ), array( __( 'Build a complete vendor profile', 'lnkup-marketplace' ), __( 'Read the listing: dates, booth sizes, fees, documents', 'lnkup-marketplace' ), __( 'Apply, then what happens next', 'lnkup-marketplace' ), __( 'Paying only after approval', 'lnkup-marketplace' ) ), false ),
			array( 'vendor-guides', __( 'Questions to ask before you book a festival booth', 'lnkup-marketplace' ), array( __( 'Expected setup and load-in', 'lnkup-marketplace' ), __( 'Power, water and tent rules', 'lnkup-marketplace' ), __( 'Refund and weather policies', 'lnkup-marketplace' ) ), false ),
			array( 'vendor-guides', __( 'Food vendor permits: what to check with your city and the organizer', 'lnkup-marketplace' ), array( __( 'Which office issues temporary food permits', 'lnkup-marketplace' ), __( 'Documents organizers commonly request', 'lnkup-marketplace' ), __( 'Timelines to plan for', 'lnkup-marketplace' ) ), true ),
			array( 'organizer-guides', __( 'How to recruit vendors for your first market', 'lnkup-marketplace' ), array( __( 'Decide your vendor mix', 'lnkup-marketplace' ), __( 'Publish clear booth options', 'lnkup-marketplace' ), __( 'Share tracked links where vendors are', 'lnkup-marketplace' ) ), false ),
			array( 'organizer-guides', __( 'Setting booth fees and refund policies vendors understand', 'lnkup-marketplace' ), array( __( 'What booth fees usually cover', 'lnkup-marketplace' ), __( 'Writing a refund policy', 'lnkup-marketplace' ), __( 'Communicating changes', 'lnkup-marketplace' ) ), false ),
			array( 'organizer-guides', __( 'Vendor insurance requirements: what to ask for and why', 'lnkup-marketplace' ), array( __( 'Common certificate requests', 'lnkup-marketplace' ), __( 'Collecting documents before load-in', 'lnkup-marketplace' ), __( 'When to involve your venue or insurer', 'lnkup-marketplace' ) ), true ),
		);
		$ids = array();
		foreach ( $outlines as $o ) {
			$term = get_term_by( 'slug', $o[0], 'category' );
			$body = '<!-- wp:paragraph --><p><em>' . esc_html( $note ) . '</em></p><!-- /wp:paragraph -->';
			foreach ( $o[2] as $heading ) {
				$body .= '<!-- wp:heading --><h2>' . esc_html( $heading ) . '</h2><!-- /wp:heading --><!-- wp:paragraph --><p>' . esc_html__( 'To write.', 'lnkup-marketplace' ) . '</p><!-- /wp:paragraph -->';
			}
			$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => $o[1], 'post_content' => $body, 'post_author' => $author, 'post_category' => $term ? array( (int) $term->term_id ) : array() ) );
			if ( $id && ! is_wp_error( $id ) ) {
				update_post_meta( (int) $id, self::LEGAL_FLAG, $o[3] ? 1 : 0 );
				$ids[] = (int) $id;
			}
		}
		update_option( self::SEEDED, $ids, false );
		return $ids;
	}
}
