<?php
/**
 * Appearance → Landing Page: content for the public website (homepage, /for-organizers/,
 * /for-vendors/, announcement bar, proof blocks, FAQs) with a draft → preview → publish flow.
 *
 * Marketplace numbers are never typed in here; they always come from live data. Testimonials and
 * case studies only render when an editor confirms they are real and used with permission.
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Landing page settings registry and admin UI.
 */
class LNKup_Theme_Landing_Settings {

	const OPTION       = 'lnkup_landing';
	const DRAFT_OPTION = 'lnkup_landing_draft';
	const PREVIEW_ARG  = 'lnkup_landing_preview';
	const MAX_LOGOS    = 8;
	const MAX_QUOTES   = 6;
	const MAX_CASES    = 3;

	/** Unverified traction claims that earlier defaults shipped with; replaced on upgrade. */
	const RETIRED_COPY = array(
		'footer_blurb' => array( 'Join thousands of vendors growing their business at the best live events in the country.' ),
	);

	/** @var array<string,mixed>|null */
	private static $cache = null;

	/**
	 * Boot hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		add_action( 'init', array( __CLASS__, 'maybe_refresh_postlaunch_copy' ), 20 );
		add_action( 'init', array( __CLASS__, 'retire_unverified_copy' ), 21 );
		add_action( 'template_redirect', array( __CLASS__, 'preview_headers' ) );
		add_action( 'wp_footer', array( __CLASS__, 'preview_notice' ) );
	}

	/**
	 * One-time remap of exact prelaunch landing strings after marketplace launch.
	 */
	public static function maybe_refresh_postlaunch_copy() {
		if ( get_option( 'lnkup_landing_postlaunch_copy_v1' ) ) {
			return;
		}
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$replacements = array(
			'hero_primary_cta' => array( 'Join the Prelaunch' => 'Create your account' ),
			'cta_button'       => array( 'Join the Prelaunch' => 'Create your account' ),
		);
		$changed = false;
		foreach ( $replacements as $key => $map ) {
			if ( isset( $saved[ $key ] ) && is_string( $saved[ $key ] ) && isset( $map[ $saved[ $key ] ] ) ) {
				$saved[ $key ] = $map[ $saved[ $key ] ];
				$changed       = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION, $saved, false );
		}
		update_option( 'lnkup_landing_postlaunch_copy_v1', '1', false );
	}

	/**
	 * Clear saved copy that matches a retired, unverifiable claim (falls back to the new default).
	 */
	public static function retire_unverified_copy() {
		if ( get_option( 'lnkup_landing_retired_copy_v2' ) ) {
			return;
		}
		$saved = get_option( self::OPTION, array() );
		if ( is_array( $saved ) ) {
			$changed = false;
			foreach ( self::RETIRED_COPY as $key => $strings ) {
				if ( isset( $saved[ $key ] ) && in_array( trim( (string) $saved[ $key ] ), $strings, true ) ) {
					unset( $saved[ $key ] );
					$changed = true;
				}
			}
			if ( isset( $saved['use_custom_stats'] ) || isset( $saved['stats'] ) ) {
				unset( $saved['use_custom_stats'], $saved['stats'] );
				$changed = true;
			}
			if ( $changed ) {
				update_option( self::OPTION, $saved, false );
			}
		}
		update_option( 'lnkup_landing_retired_copy_v2', '1', false );
	}

	/**
	 * Defaults. Empty strings mean "use the template's built-in copy".
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'home_eyebrow'           => '',
			'home_title'             => '',
			'home_lead'              => '',
			'hero_image_id'          => 0,
			'org_title'              => '',
			'org_lead'               => '',
			'org_hero_image_id'      => 0,
			'org_walkthrough_url'    => '',
			'vendor_title'           => '',
			'vendor_lead'            => '',
			'vendor_hero_image_id'   => 0,
			'shot_organizer'         => 0,
			'shot_vendor'            => 0,
			'announce_enabled'       => 0,
			'announce_text'          => '',
			'announce_link_label'    => '',
			'announce_url'           => '',
			'announce_expires'       => '',
			'logos'                  => array(),
			'testimonials'           => array(),
			'case_studies'           => array(),
			'faq_home'               => '',
			'faq_organizers'         => '',
			'faq_vendors'            => '',
			'featured_vendor_ids'    => array(),
			'hiw_eyebrow'            => 'How it works',
			'hiw_title'              => 'From profile to paid in four steps',
			'hiw_steps'              => array(
				array( 'title' => 'Create your vendor profile', 'body' => 'Build a profile that shows organizers who you are, what you sell, and where you set up.', 'image_id' => 0 ),
				array( 'title' => 'Browse event opportunities', 'body' => 'Filter by city, date, attendance, and vendor fee to find events that fit your business.', 'image_id' => 0 ),
				array( 'title' => 'Apply for free', 'body' => 'Submit applications to as many events as you want — applying never costs a thing.', 'image_id' => 0 ),
				array( 'title' => 'Pay only when accepted', 'body' => 'No upfront fees. You only pay your vendor spot once an organizer approves you.', 'image_id' => 0 ),
			),
			'cta_title'              => '',
			'footer_blurb'           => '',
			'launch_goal_vendors'    => 4000,
			'launch_goal_organizers' => 500,
		);
	}

	/**
	 * Whether the current request is an editor previewing the draft.
	 */
	public static function is_preview() {
		if ( is_admin() || ! isset( $_GET[ self::PREVIEW_ARG ] ) || ! is_user_logged_in() || ! current_user_can( 'edit_theme_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		return (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET[ self::PREVIEW_ARG ] ) ), 'lnkup_landing_preview' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Draft previews must never be cached or indexed.
	 */
	public static function preview_headers() {
		if ( self::is_preview() ) {
			nocache_headers();
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
		}
	}

	public static function preview_notice() {
		if ( ! self::is_preview() ) {
			return;
		}
		echo '<div role="status" style="position:fixed;left:16px;bottom:16px;z-index:9999;padding:10px 14px;border-radius:10px;background:#0b0b0c;color:#c8f542;font:600 13px/1.3 Inter,system-ui,sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.3)">' . esc_html__( 'Previewing landing page draft — not published', 'lnkup-theme' ) . '</div>';
	}

	/**
	 * Published values (or the draft for an editor's preview request), merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_all() {
		$preview = self::is_preview();
		if ( null !== self::$cache && ! $preview ) {
			return self::$cache;
		}
		$saved = get_option( $preview ? self::DRAFT_OPTION : self::OPTION, array() );
		if ( $preview && ( ! is_array( $saved ) || ! $saved ) ) {
			$saved = get_option( self::OPTION, array() );
		}
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$defaults = self::defaults();
		$plugin   = get_option( 'lnkup_settings', array() );
		if ( is_array( $plugin ) ) {
			foreach ( array( 'launch_goal_vendors', 'launch_goal_organizers' ) as $goal ) {
				if ( ! array_key_exists( $goal, $saved ) && isset( $plugin[ $goal ] ) ) {
					$defaults[ $goal ] = max( 0, (int) $plugin[ $goal ] );
				}
			}
		}
		$out   = array_merge( $defaults, $saved );
		$steps = array();
		foreach ( $defaults['hiw_steps'] as $i => $def_step ) {
			$row     = isset( $saved['hiw_steps'][ $i ] ) && is_array( $saved['hiw_steps'][ $i ] ) ? $saved['hiw_steps'][ $i ] : array();
			$steps[] = array_merge( $def_step, $row );
		}
		$out['hiw_steps'] = $steps;
		if ( ! $preview ) {
			self::$cache = $out;
		}
		return $out;
	}

	/**
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = '' ) {
		$all = self::get_all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public static function register_menu() {
		add_theme_page( __( 'Landing Page', 'lnkup-theme' ), __( 'Landing Page', 'lnkup-theme' ), 'edit_theme_options', 'lnkup-landing', array( __CLASS__, 'render_page' ) );
	}

	/**
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue_admin( $hook ) {
		if ( 'appearance_page_lnkup-landing' !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'lnkup-landing-admin', LNKUP_THEME_URI . '/assets/js/landing-admin.js', array( 'jquery' ), LNKUP_THEME_VERSION, true );
		wp_add_inline_style(
			'wp-admin',
			'.lnkup-landing-admin .form-table th{width:220px}.lnkup-landing-admin .lnkup-landing-media{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.lnkup-landing-admin .lnkup-landing-media img{max-width:120px;height:auto;border-radius:8px;border:1px solid #ddd}.lnkup-landing-admin .lnkup-landing-section{margin:1.5rem 0;padding:1.25rem;background:#fff;border:1px solid #dcdcde;border-radius:8px}.lnkup-landing-admin .lnkup-landing-section h2{margin-top:0}.lnkup-landing-admin .lnkup-landing-row{padding:12px 0;border-top:1px solid #f0f0f1}.lnkup-landing-admin .lnkup-landing-bar{position:sticky;top:32px;z-index:5;display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px}'
		);
	}

	private static function redirect_back( $flag ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'lnkup-landing', $flag => '1' ), admin_url( 'themes.php' ) ) );
		exit;
	}

	/**
	 * Save draft, publish, publish existing draft, discard draft, or reset.
	 */
	public static function handle_save() {
		if ( ! isset( $_POST['lnkup_landing_nonce'] ) || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		check_admin_referer( 'lnkup_landing_save', 'lnkup_landing_nonce' );
		$action = isset( $_POST['lnkup_landing_action'] ) ? sanitize_key( wp_unslash( $_POST['lnkup_landing_action'] ) ) : 'publish';

		if ( 'reset' === $action ) {
			delete_option( self::OPTION );
			delete_option( self::DRAFT_OPTION );
			self::sync_launch_goals( self::defaults() );
			self::redirect_back( 'reset' );
		}
		if ( 'discard' === $action ) {
			delete_option( self::DRAFT_OPTION );
			self::redirect_back( 'discarded' );
		}
		$raw   = isset( $_POST['lnkup_landing'] ) && is_array( $_POST['lnkup_landing'] ) ? wp_unslash( $_POST['lnkup_landing'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$clean = self::sanitize( $raw );
		if ( 'draft' === $action ) {
			update_option( self::DRAFT_OPTION, $clean, false );
			self::redirect_back( 'drafted' );
		}
		update_option( self::OPTION, $clean, false );
		delete_option( self::DRAFT_OPTION );
		self::$cache = null;
		self::sync_launch_goals( $clean );
		do_action( 'lnkup_landing_published', $clean );
		self::redirect_back( 'updated' );
	}

	/**
	 * Write launch goals into plugin settings.
	 *
	 * @param array<string,mixed> $landing Landing settings.
	 */
	public static function sync_launch_goals( array $landing ) {
		$settings = get_option( 'lnkup_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['launch_goal_vendors']    = max( 0, (int) ( $landing['launch_goal_vendors'] ?? 4000 ) );
		$settings['launch_goal_organizers'] = max( 0, (int) ( $landing['launch_goal_organizers'] ?? 500 ) );
		update_option( 'lnkup_settings', $settings, false );
	}

	/**
	 * @param array<string,mixed> $raw Raw POST.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $raw ) {
		$defaults = self::defaults();
		$out      = array();

		$text_keys = array( 'home_eyebrow', 'home_title', 'org_title', 'vendor_title', 'announce_text', 'announce_link_label', 'hiw_eyebrow', 'hiw_title', 'cta_title' );
		foreach ( $text_keys as $key ) {
			$out[ $key ] = isset( $raw[ $key ] ) ? substr( sanitize_text_field( (string) $raw[ $key ] ), 0, 200 ) : $defaults[ $key ];
		}
		foreach ( array( 'home_lead', 'org_lead', 'vendor_lead', 'footer_blurb', 'faq_home', 'faq_organizers', 'faq_vendors' ) as $key ) {
			$out[ $key ] = isset( $raw[ $key ] ) ? substr( sanitize_textarea_field( (string) $raw[ $key ] ), 0, 6000 ) : $defaults[ $key ];
		}
		foreach ( array( 'hero_image_id', 'org_hero_image_id', 'vendor_hero_image_id', 'shot_organizer', 'shot_vendor' ) as $key ) {
			$out[ $key ] = isset( $raw[ $key ] ) ? absint( $raw[ $key ] ) : 0;
		}
		$out['announce_enabled'] = empty( $raw['announce_enabled'] ) ? 0 : 1;
		$out['announce_url']     = isset( $raw['announce_url'] ) ? esc_url_raw( trim( (string) $raw['announce_url'] ) ) : '';
		$expires                 = isset( $raw['announce_expires'] ) ? (string) $raw['announce_expires'] : '';
		$out['announce_expires'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires ) ? $expires : '';
		$walk                    = isset( $raw['org_walkthrough_url'] ) ? esc_url_raw( trim( (string) $raw['org_walkthrough_url'] ), array( 'https' ) ) : '';
		$out['org_walkthrough_url'] = 0 === strpos( $walk, 'https://' ) ? $walk : '';

		$out['launch_goal_vendors']    = isset( $raw['launch_goal_vendors'] ) ? absint( $raw['launch_goal_vendors'] ) : 4000;
		$out['launch_goal_organizers'] = isset( $raw['launch_goal_organizers'] ) ? absint( $raw['launch_goal_organizers'] ) : 500;

		$ids                        = preg_split( '/[\s,]+/', (string) ( $raw['featured_vendor_ids'] ?? '' ) );
		$out['featured_vendor_ids'] = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		$out['featured_vendor_ids'] = array_slice( $out['featured_vendor_ids'], 0, 12 );

		$out['hiw_steps'] = array();
		$steps_raw        = isset( $raw['hiw_steps'] ) && is_array( $raw['hiw_steps'] ) ? $raw['hiw_steps'] : array();
		for ( $i = 0; $i < 4; $i++ ) {
			$row                = isset( $steps_raw[ $i ] ) && is_array( $steps_raw[ $i ] ) ? $steps_raw[ $i ] : array();
			$out['hiw_steps'][] = array(
				'title'    => sanitize_text_field( (string) ( $row['title'] ?? $defaults['hiw_steps'][ $i ]['title'] ) ),
				'body'     => sanitize_textarea_field( (string) ( $row['body'] ?? $defaults['hiw_steps'][ $i ]['body'] ) ),
				'image_id' => absint( $row['image_id'] ?? 0 ),
			);
		}

		$audiences = array( 'all', 'organizer', 'vendor' );
		$out['logos'] = array();
		foreach ( array_slice( (array) ( $raw['logos'] ?? array() ), 0, self::MAX_LOGOS ) as $row ) {
			$row = is_array( $row ) ? $row : array();
			if ( empty( $row['image_id'] ) || '' === trim( (string) ( $row['name'] ?? '' ) ) ) {
				continue;
			}
			$out['logos'][] = array(
				'name'     => substr( sanitize_text_field( (string) $row['name'] ), 0, 80 ),
				'image_id' => absint( $row['image_id'] ),
				'url'      => esc_url_raw( (string) ( $row['url'] ?? '' ), array( 'https', 'http' ) ),
			);
		}
		$out['testimonials'] = array();
		foreach ( array_slice( (array) ( $raw['testimonials'] ?? array() ), 0, self::MAX_QUOTES ) as $row ) {
			$row = is_array( $row ) ? $row : array();
			if ( '' === trim( (string) ( $row['quote'] ?? '' ) ) ) {
				continue;
			}
			$out['testimonials'][] = array(
				'quote'    => substr( sanitize_textarea_field( (string) $row['quote'] ), 0, 600 ),
				'name'     => substr( sanitize_text_field( (string) ( $row['name'] ?? '' ) ), 0, 80 ),
				'role'     => substr( sanitize_text_field( (string) ( $row['role'] ?? '' ) ), 0, 120 ),
				'image_id' => absint( $row['image_id'] ?? 0 ),
				'audience' => in_array( $row['audience'] ?? '', $audiences, true ) ? (string) $row['audience'] : 'all',
				'verified' => empty( $row['verified'] ) ? 0 : 1,
			);
		}
		$out['case_studies'] = array();
		foreach ( array_slice( (array) ( $raw['case_studies'] ?? array() ), 0, self::MAX_CASES ) as $row ) {
			$row = is_array( $row ) ? $row : array();
			if ( '' === trim( (string) ( $row['title'] ?? '' ) ) ) {
				continue;
			}
			$out['case_studies'][] = array(
				'title'    => substr( sanitize_text_field( (string) $row['title'] ), 0, 120 ),
				'summary'  => substr( sanitize_textarea_field( (string) ( $row['summary'] ?? '' ) ), 0, 400 ),
				'url'      => esc_url_raw( (string) ( $row['url'] ?? '' ), array( 'https', 'http' ) ),
				'image_id' => absint( $row['image_id'] ?? 0 ),
				'audience' => in_array( $row['audience'] ?? '', $audiences, true ) ? (string) $row['audience'] : 'all',
				'verified' => empty( $row['verified'] ) ? 0 : 1,
			);
		}
		return $out;
	}

	/**
	 * Admin page markup.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		$draft     = get_option( self::DRAFT_OPTION, array() );
		$has_draft = is_array( $draft ) && $draft;
		$d         = array_merge( self::get_all(), $has_draft ? $draft : array() );
		$nonce     = wp_create_nonce( 'lnkup_landing_preview' );
		$previews  = array(
			__( 'Homepage', 'lnkup-theme' )       => home_url( '/' ),
			__( 'For organizers', 'lnkup-theme' ) => home_url( '/for-organizers/' ),
			__( 'For vendors', 'lnkup-theme' )    => home_url( '/for-vendors/' ),
		);
		$notices   = array(
			'updated'   => __( 'Published. The public site now shows these changes.', 'lnkup-theme' ),
			'drafted'   => __( 'Draft saved. Preview it below, then publish when ready.', 'lnkup-theme' ),
			'discarded' => __( 'Draft discarded.', 'lnkup-theme' ),
			'reset'     => __( 'Landing content reset to defaults.', 'lnkup-theme' ),
		);
		?>
		<div class="wrap lnkup-landing-admin">
			<h1><?php esc_html_e( 'Landing Page', 'lnkup-theme' ); ?></h1>
			<?php foreach ( $notices as $flag => $message ) : ?>
				<?php if ( ! empty( $_GET[ $flag ] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
				<?php endif; ?>
			<?php endforeach; ?>
			<p><?php esc_html_e( 'Copy, images and proof blocks for the homepage and the organizer and vendor landing pages. Leave a field empty to use the built-in copy. Event, city and category counts are always live and cannot be edited.', 'lnkup-theme' ); ?></p>
			<?php if ( $has_draft ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'You have an unpublished draft. The form below shows the draft.', 'lnkup-theme' ); ?>
					<?php foreach ( $previews as $label => $url ) : ?>
						<a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( add_query_arg( self::PREVIEW_ARG, $nonce, $url ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: page */ __( 'Preview %s', 'lnkup-theme' ), $label ) ); ?></a>
					<?php endforeach; ?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'lnkup_landing_save', 'lnkup_landing_nonce' ); ?>
				<div class="lnkup-landing-bar">
					<button type="submit" name="lnkup_landing_action" value="draft" class="button"><?php esc_html_e( 'Save draft', 'lnkup-theme' ); ?></button>
					<button type="submit" name="lnkup_landing_action" value="publish" class="button button-primary"><?php esc_html_e( 'Publish', 'lnkup-theme' ); ?></button>
					<?php if ( $has_draft ) : ?>
						<button type="submit" name="lnkup_landing_action" value="discard" class="button button-link-delete" formnovalidate><?php esc_html_e( 'Discard draft', 'lnkup-theme' ); ?></button>
					<?php endif; ?>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'Announcement bar', 'lnkup-theme' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr><th><?php esc_html_e( 'Show', 'lnkup-theme' ); ?></th><td><label><input type="checkbox" name="lnkup_landing[announce_enabled]" value="1" <?php checked( ! empty( $d['announce_enabled'] ) ); ?>> <?php esc_html_e( 'Show on every public page', 'lnkup-theme' ); ?></label></td></tr>
						<?php
						self::field_text( 'announce_text', __( 'Message', 'lnkup-theme' ), $d['announce_text'] );
						self::field_text( 'announce_link_label', __( 'Link label', 'lnkup-theme' ), $d['announce_link_label'] );
						self::field_text( 'announce_url', __( 'Link URL', 'lnkup-theme' ), $d['announce_url'], 'url' );
						self::field_text( 'announce_expires', __( 'Hide after (date)', 'lnkup-theme' ), $d['announce_expires'], 'date' );
						?>
					</table>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'Homepage hero', 'lnkup-theme' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::field_text( 'home_eyebrow', __( 'Eyebrow', 'lnkup-theme' ), $d['home_eyebrow'] );
						self::field_text( 'home_title', __( 'Headline', 'lnkup-theme' ), $d['home_title'] );
						self::field_textarea( 'home_lead', __( 'Subheadline', 'lnkup-theme' ), $d['home_lead'] );
						self::field_media( 'hero_image_id', __( 'Hero photo', 'lnkup-theme' ), (int) $d['hero_image_id'] );
						?>
					</table>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'For organizers page', 'lnkup-theme' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::field_text( 'org_title', __( 'Headline', 'lnkup-theme' ), $d['org_title'] );
						self::field_textarea( 'org_lead', __( 'Subheadline', 'lnkup-theme' ), $d['org_lead'] );
						self::field_media( 'org_hero_image_id', __( 'Hero photo', 'lnkup-theme' ), (int) $d['org_hero_image_id'] );
						self::field_media( 'shot_organizer', __( 'Organizer dashboard screenshot', 'lnkup-theme' ), (int) $d['shot_organizer'] );
						self::field_text( 'org_walkthrough_url', __( 'Walkthrough booking link (https)', 'lnkup-theme' ), $d['org_walkthrough_url'], 'url' );
						?>
					</table>
					<p class="description"><?php esc_html_e( 'The "Book a walkthrough" button only appears when a booking link is set. Without a screenshot, the page shows a labeled illustration.', 'lnkup-theme' ); ?></p>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'For vendors page', 'lnkup-theme' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::field_text( 'vendor_title', __( 'Headline', 'lnkup-theme' ), $d['vendor_title'] );
						self::field_textarea( 'vendor_lead', __( 'Subheadline', 'lnkup-theme' ), $d['vendor_lead'] );
						self::field_media( 'vendor_hero_image_id', __( 'Hero photo', 'lnkup-theme' ), (int) $d['vendor_hero_image_id'] );
						self::field_media( 'shot_vendor', __( 'Vendor dashboard screenshot', 'lnkup-theme' ), (int) $d['shot_vendor'] );
						?>
					</table>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'Featured vendors', 'lnkup-theme' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr><th><label for="lnkup_landing_featured_vendor_ids"><?php esc_html_e( 'Pinned vendor profile IDs', 'lnkup-theme' ); ?></label></th>
						<td><input class="regular-text" id="lnkup_landing_featured_vendor_ids" name="lnkup_landing[featured_vendor_ids]" value="<?php echo esc_attr( implode( ', ', array_map( 'intval', (array) $d['featured_vendor_ids'] ) ) ); ?>">
						<p class="description"><?php esc_html_e( 'Comma-separated. Only vendors whose Passport is public are ever shown; others are skipped automatically.', 'lnkup-theme' ); ?></p></td></tr>
					</table>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'Partner and event logos', 'lnkup-theme' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Only add logos of organizations that use LNKup and agreed to be shown.', 'lnkup-theme' ); ?></p>
					<?php for ( $i = 0; $i < self::MAX_LOGOS; $i++ ) : ?>
						<?php $row = (array) ( $d['logos'][ $i ] ?? array() ); ?>
						<div class="lnkup-landing-row">
							<input class="regular-text" placeholder="<?php esc_attr_e( 'Name', 'lnkup-theme' ); ?>" name="lnkup_landing[logos][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( (string) ( $row['name'] ?? '' ) ); ?>">
							<input class="regular-text" type="url" placeholder="https://" name="lnkup_landing[logos][<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( (string) ( $row['url'] ?? '' ) ); ?>">
							<?php self::media_control( 'lnkup_landing[logos][' . $i . '][image_id]', (int) ( $row['image_id'] ?? 0 ) ); ?>
						</div>
					<?php endfor; ?>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'Testimonials', 'lnkup-theme' ); ?></h2>
					<p class="description"><?php esc_html_e( 'A testimonial is shown only after you tick "Real quote, used with permission". Never paraphrase or invent quotes.', 'lnkup-theme' ); ?></p>
					<?php for ( $i = 0; $i < self::MAX_QUOTES; $i++ ) : ?>
						<?php $row = (array) ( $d['testimonials'][ $i ] ?? array() ); ?>
						<div class="lnkup-landing-row">
							<textarea class="large-text" rows="2" placeholder="<?php esc_attr_e( 'Quote', 'lnkup-theme' ); ?>" name="lnkup_landing[testimonials][<?php echo (int) $i; ?>][quote]"><?php echo esc_textarea( (string) ( $row['quote'] ?? '' ) ); ?></textarea>
							<input class="regular-text" placeholder="<?php esc_attr_e( 'Name', 'lnkup-theme' ); ?>" name="lnkup_landing[testimonials][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( (string) ( $row['name'] ?? '' ) ); ?>">
							<input class="regular-text" placeholder="<?php esc_attr_e( 'Role and business', 'lnkup-theme' ); ?>" name="lnkup_landing[testimonials][<?php echo (int) $i; ?>][role]" value="<?php echo esc_attr( (string) ( $row['role'] ?? '' ) ); ?>">
							<?php self::audience_select( 'lnkup_landing[testimonials][' . $i . '][audience]', (string) ( $row['audience'] ?? 'all' ) ); ?>
							<label><input type="checkbox" name="lnkup_landing[testimonials][<?php echo (int) $i; ?>][verified]" value="1" <?php checked( ! empty( $row['verified'] ) ); ?>> <?php esc_html_e( 'Real quote, used with permission', 'lnkup-theme' ); ?></label>
							<?php self::media_control( 'lnkup_landing[testimonials][' . $i . '][image_id]', (int) ( $row['image_id'] ?? 0 ) ); ?>
						</div>
					<?php endfor; ?>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'Case studies', 'lnkup-theme' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Shown only when marked as a real, approved story. Do not include unverified revenue or attendance numbers.', 'lnkup-theme' ); ?></p>
					<?php for ( $i = 0; $i < self::MAX_CASES; $i++ ) : ?>
						<?php $row = (array) ( $d['case_studies'][ $i ] ?? array() ); ?>
						<div class="lnkup-landing-row">
							<input class="regular-text" placeholder="<?php esc_attr_e( 'Title', 'lnkup-theme' ); ?>" name="lnkup_landing[case_studies][<?php echo (int) $i; ?>][title]" value="<?php echo esc_attr( (string) ( $row['title'] ?? '' ) ); ?>">
							<textarea class="large-text" rows="2" placeholder="<?php esc_attr_e( 'Summary', 'lnkup-theme' ); ?>" name="lnkup_landing[case_studies][<?php echo (int) $i; ?>][summary]"><?php echo esc_textarea( (string) ( $row['summary'] ?? '' ) ); ?></textarea>
							<input class="regular-text" type="url" placeholder="https://" name="lnkup_landing[case_studies][<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( (string) ( $row['url'] ?? '' ) ); ?>">
							<?php self::audience_select( 'lnkup_landing[case_studies][' . $i . '][audience]', (string) ( $row['audience'] ?? 'all' ) ); ?>
							<label><input type="checkbox" name="lnkup_landing[case_studies][<?php echo (int) $i; ?>][verified]" value="1" <?php checked( ! empty( $row['verified'] ) ); ?>> <?php esc_html_e( 'Real, approved story', 'lnkup-theme' ); ?></label>
							<?php self::media_control( 'lnkup_landing[case_studies][' . $i . '][image_id]', (int) ( $row['image_id'] ?? 0 ) ); ?>
						</div>
					<?php endfor; ?>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'FAQs', 'lnkup-theme' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Question on the first line, answer on the next line, and a blank line between questions. Leave empty to use the built-in FAQs, which quote current fees automatically.', 'lnkup-theme' ); ?></p>
					<table class="form-table" role="presentation">
						<?php
						self::field_textarea( 'faq_home', __( 'Homepage', 'lnkup-theme' ), $d['faq_home'], 8 );
						self::field_textarea( 'faq_organizers', __( 'For organizers', 'lnkup-theme' ), $d['faq_organizers'], 8 );
						self::field_textarea( 'faq_vendors', __( 'For vendors', 'lnkup-theme' ), $d['faq_vendors'], 8 );
						?>
					</table>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'Closing call to action and footer', 'lnkup-theme' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::field_text( 'cta_title', __( 'Homepage closing headline', 'lnkup-theme' ), $d['cta_title'] );
						self::field_textarea( 'footer_blurb', __( 'Footer tagline', 'lnkup-theme' ), $d['footer_blurb'] );
						?>
					</table>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'City pages: how it works', 'lnkup-theme' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::field_text( 'hiw_eyebrow', __( 'Eyebrow', 'lnkup-theme' ), $d['hiw_eyebrow'] );
						self::field_text( 'hiw_title', __( 'Title', 'lnkup-theme' ), $d['hiw_title'] );
						?>
					</table>
					<?php foreach ( $d['hiw_steps'] as $i => $step ) : ?>
						<div class="lnkup-landing-row">
							<strong><?php echo esc_html( sprintf( /* translators: %d: step */ __( 'Step %d', 'lnkup-theme' ), $i + 1 ) ); ?></strong><br>
							<input class="regular-text" name="lnkup_landing[hiw_steps][<?php echo (int) $i; ?>][title]" value="<?php echo esc_attr( (string) $step['title'] ); ?>">
							<textarea class="large-text" rows="2" name="lnkup_landing[hiw_steps][<?php echo (int) $i; ?>][body]"><?php echo esc_textarea( (string) $step['body'] ); ?></textarea>
							<?php self::media_control( 'lnkup_landing[hiw_steps][' . $i . '][image_id]', (int) $step['image_id'] ); ?>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'Pre-launch goals', 'lnkup-theme' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Used only before the marketplace opens, and always labeled as goals — never as current numbers.', 'lnkup-theme' ); ?></p>
					<table class="form-table" role="presentation">
						<?php
						self::field_text( 'launch_goal_vendors', __( 'Vendor goal', 'lnkup-theme' ), (string) $d['launch_goal_vendors'], 'number' );
						self::field_text( 'launch_goal_organizers', __( 'Organizer goal', 'lnkup-theme' ), (string) $d['launch_goal_organizers'], 'number' );
						?>
					</table>
				</div>

				<p class="submit">
					<button type="submit" name="lnkup_landing_action" value="draft" class="button"><?php esc_html_e( 'Save draft', 'lnkup-theme' ); ?></button>
					<button type="submit" name="lnkup_landing_action" value="publish" class="button button-primary"><?php esc_html_e( 'Publish', 'lnkup-theme' ); ?></button>
					<button type="submit" name="lnkup_landing_action" value="reset" class="button button-link-delete" formnovalidate onclick="return confirm('<?php echo esc_js( __( 'Reset all landing content to defaults? This also discards any draft.', 'lnkup-theme' ) ); ?>');"><?php esc_html_e( 'Reset to defaults', 'lnkup-theme' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * @param string $name  Input name.
	 * @param string $value Current audience.
	 */
	private static function audience_select( $name, $value ) {
		$options = array(
			'all'       => __( 'All pages', 'lnkup-theme' ),
			'organizer' => __( 'Organizer pages', 'lnkup-theme' ),
			'vendor'    => __( 'Vendor pages', 'lnkup-theme' ),
		);
		echo '<select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $value, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
	}

	/**
	 * @param string $key   Field key.
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $type  Input type.
	 */
	private static function field_text( $key, $label, $value, $type = 'text' ) {
		?>
		<tr>
			<th><label for="lnkup_landing_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input class="regular-text" type="<?php echo esc_attr( $type ); ?>" id="lnkup_landing_<?php echo esc_attr( $key ); ?>" name="lnkup_landing[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $value ); ?>"<?php echo 'number' === $type ? ' min="0"' : ''; ?>></td>
		</tr>
		<?php
	}

	/**
	 * @param string $key   Field key.
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param int    $rows  Rows.
	 */
	private static function field_textarea( $key, $label, $value, $rows = 3 ) {
		?>
		<tr>
			<th><label for="lnkup_landing_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><textarea class="large-text" rows="<?php echo (int) $rows; ?>" id="lnkup_landing_<?php echo esc_attr( $key ); ?>" name="lnkup_landing[<?php echo esc_attr( $key ); ?>]"><?php echo esc_textarea( (string) $value ); ?></textarea></td>
		</tr>
		<?php
	}

	/**
	 * @param string $key   Field key.
	 * @param string $label Label.
	 * @param int    $id    Attachment ID.
	 */
	private static function field_media( $key, $label, $id ) {
		?>
		<tr>
			<th><label><?php echo esc_html( $label ); ?></label></th>
			<td><?php self::media_control( 'lnkup_landing[' . $key . ']', $id ); ?></td>
		</tr>
		<?php
	}

	/**
	 * @param string $name Input name.
	 * @param int    $id   Attachment ID.
	 */
	private static function media_control( $name, $id ) {
		$id  = absint( $id );
		$url = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
		?>
		<div class="lnkup-landing-media" data-lnkup-media>
			<input type="hidden" class="lnkup-landing-media__input" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $id ); ?>">
			<img src="<?php echo esc_url( (string) $url ); ?>" alt="" class="lnkup-landing-media__preview"<?php echo $url ? '' : ' hidden'; ?>>
			<button type="button" class="button lnkup-landing-media__select"><?php esc_html_e( 'Select image', 'lnkup-theme' ); ?></button>
			<button type="button" class="button lnkup-landing-media__clear" <?php echo $id ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'lnkup-theme' ); ?></button>
		</div>
		<?php
	}
}

LNKup_Theme_Landing_Settings::init();
