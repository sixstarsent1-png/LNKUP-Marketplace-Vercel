<?php
/**
 * Appearance → Landing Page settings (editable homepage).
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Landing page settings registry and admin UI.
 */
class LNKup_Theme_Landing_Settings {

	const OPTION = 'lnkup_landing';

	/**
	 * Boot hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		add_action( 'init', array( __CLASS__, 'maybe_refresh_postlaunch_copy' ), 20 );
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
			'hero_primary_cta' => array(
				'Join the Prelaunch' => 'Create your account',
			),
			'cta_button'       => array(
				'Join the Prelaunch' => 'Create your account',
			),
			'hero_lead'        => array(
				'Find vendor opportunities, apply for free, get approved, pay securely, and unlock event details all in one place. Mobile app launches August 16, 2026 · 12:00 PM EST.' => 'Find vendor opportunities, apply for free, get approved, pay securely, and unlock event details all in one place.',
			),
			'cta_body'         => array(
				'Create your vendor profile, browse opportunities, and apply for free. Pay only when an organizer accepts you. Mobile app launches August 16, 2026 · 12:00 PM EST.' => 'Create your vendor profile, browse opportunities, and apply for free. Pay only when an organizer accepts you.',
			),
			'cat_aside'        => array(
				'13 categories · more launching soon' => '13 vendor categories',
			),
		);

		$changed = false;
		foreach ( $replacements as $key => $map ) {
			if ( ! isset( $saved[ $key ] ) || ! is_string( $saved[ $key ] ) ) {
				continue;
			}
			if ( isset( $map[ $saved[ $key ] ] ) ) {
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
	 * Default landing content (matches previous hardcoded copy).
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'hero_eyebrow'           => 'The opportunity network for live events',
			'hero_title'             => 'The Opportunity Network for Live Events',
			'hero_lead'              => 'Find vendor opportunities, apply for free, get approved, pay securely, and unlock event details all in one place.',
			'hero_primary_cta'       => 'Find Events',
			'hero_secondary_cta'     => 'List my business',
			'hero_image_id'          => 0,
			'hero_event_label'       => 'New opportunity',
			'hero_event_title'       => 'Soul District Block Party',
			'hero_event_meta'        => 'Atlanta, GA',
			'hero_event_badge'       => '12 spots left',
			'hero_goal_override'     => '',
			'hiw_eyebrow'            => 'How it works',
			'hiw_title'              => 'From profile to paid in four steps',
			'hiw_steps'              => array(
				array(
					'title'    => 'Create your vendor profile',
					'body'     => 'Build a profile that shows organizers who you are, what you sell, and where you set up.',
					'image_id' => 0,
				),
				array(
					'title'    => 'Browse event opportunities',
					'body'     => 'Filter by city, date, attendance, and vendor fee to find events that fit your business.',
					'image_id' => 0,
				),
				array(
					'title'    => 'Apply for free',
					'body'     => 'Submit applications to as many events as you want — applying never costs a thing.',
					'image_id' => 0,
				),
				array(
					'title'    => 'Pay only when accepted',
					'body'     => 'No upfront fees. You only pay your vendor spot once an organizer approves you.',
					'image_id' => 0,
				),
			),
			'aud_eyebrow'            => "Who it's for",
			'aud_title'              => 'Built for vendors and organizers',
			'aud_lead'               => '',
			'aud_vendor_title'       => 'Vendors',
			'aud_vendor_desc'        => 'Food trucks, artisans, retailers, and service vendors looking for booth opportunities at festivals, markets, and live events.',
			'aud_vendor_bullets'     => "Create your free profile today\nBrowse and apply to events\nPay only when accepted",
			'aud_vendor_cta'         => 'Sign up as vendor',
			'aud_vendor_image_id'    => 0,
			'aud_org_title'          => 'Organizers',
			'aud_org_desc'           => 'Event producers who need a vendor layer — not ticketing. Keep selling tickets on Posh, Eventbrite, and Universe.',
			'aud_org_bullets'        => "Sign up and launch vendor opportunities\nReview applications and collect payments\nRun day-of operations from one hub",
			'aud_org_cta'            => 'Sign up as organizer',
			'aud_org_image_id'       => 0,
			'cat_eyebrow'            => 'Vendor categories',
			'cat_title'              => 'Whatever you bring to the event',
			'cat_aside'              => '13 vendor categories',
			'cat_list'               => "Food Vendor\nFood Truck\nDesserts\nDrinks\nRetail\nClothing\nBeauty\nJewelry\nArt\nPhoto Booth\nServices\nWellness\nMerchandise",
			'cat_image_id'           => 0,
			'trust_eyebrow'          => 'Launch goals',
			'trust_eyebrow_open'     => 'Trusted by vendors',
			'trust_title'            => 'Built for the live event economy',
			'trust_lead'             => 'LNKup is strictly the vendor opportunity layer — not ticketing. Organizers keep selling tickets on Posh, Eventbrite, and Universe while vendors find and book spots through LNKup.',
			'trust_image_id'         => 0,
			'use_custom_stats'       => 0,
			'stats'                  => array(
				array( 'value' => '', 'label' => 'Vendor community goal', 'sub' => '' ),
				array( 'value' => '', 'label' => 'Organizer community goal', 'sub' => '' ),
				array( 'value' => 'Free', 'label' => 'To join & apply', 'sub' => 'No upfront fees' ),
				array( 'value' => '$0', 'label' => 'Due until accepted', 'sub' => 'Pay only when approved' ),
			),
			'cta_title'              => 'Ready to join the opportunity network?',
			'cta_body'               => 'Create your vendor profile, browse opportunities, and apply for free. Pay only when an organizer accepts you.',
			'cta_button'             => 'Find Events',
			'footer_title'           => 'Find Your Next Opportunity.',
			'footer_blurb'           => 'Join thousands of vendors growing their business at the best live events in the country.',
			'footer_cta'             => 'Get started free',
			'launch_goal_vendors'    => 4000,
			'launch_goal_organizers' => 500,
		);
	}

	/**
	 * Merged saved + defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$defaults = self::defaults();

		// Prefer plugin settings for goals until Landing Page has been saved with its own values.
		$plugin = get_option( 'lnkup_settings', array() );
		if ( is_array( $plugin ) ) {
			if ( ! array_key_exists( 'launch_goal_vendors', $saved ) && isset( $plugin['launch_goal_vendors'] ) ) {
				$defaults['launch_goal_vendors'] = max( 0, (int) $plugin['launch_goal_vendors'] );
			}
			if ( ! array_key_exists( 'launch_goal_organizers', $saved ) && isset( $plugin['launch_goal_organizers'] ) ) {
				$defaults['launch_goal_organizers'] = max( 0, (int) $plugin['launch_goal_organizers'] );
			}
		}

		$out = array_merge( $defaults, $saved );
		if ( empty( $saved['hiw_steps'] ) || ! is_array( $saved['hiw_steps'] ) ) {
			$out['hiw_steps'] = $defaults['hiw_steps'];
		} else {
			$steps = array();
			foreach ( $defaults['hiw_steps'] as $i => $def_step ) {
				$row = isset( $saved['hiw_steps'][ $i ] ) && is_array( $saved['hiw_steps'][ $i ] ) ? $saved['hiw_steps'][ $i ] : array();
				$steps[] = array_merge( $def_step, $row );
			}
			$out['hiw_steps'] = $steps;
		}
		if ( empty( $saved['stats'] ) || ! is_array( $saved['stats'] ) ) {
			$out['stats'] = $defaults['stats'];
		} else {
			$stats = array();
			for ( $i = 0; $i < 4; $i++ ) {
				$row     = isset( $saved['stats'][ $i ] ) && is_array( $saved['stats'][ $i ] ) ? $saved['stats'][ $i ] : array();
				$stats[] = array_merge( $defaults['stats'][ $i ], $row );
			}
			$out['stats'] = $stats;
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

	/**
	 * Register Appearance submenu.
	 */
	public static function register_menu() {
		add_theme_page(
			__( 'Landing Page', 'lnkup-theme' ),
			__( 'Landing Page', 'lnkup-theme' ),
			'edit_theme_options',
			'lnkup-landing',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Media + admin styles on settings page.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue_admin( $hook ) {
		if ( 'appearance_page_lnkup-landing' !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script(
			'lnkup-landing-admin',
			LNKUP_THEME_URI . '/assets/js/landing-admin.js',
			array( 'jquery' ),
			LNKUP_THEME_VERSION,
			true
		);
		wp_add_inline_style(
			'wp-admin',
			'.lnkup-landing-admin .form-table th{width:220px}.lnkup-landing-admin .lnkup-landing-media{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.lnkup-landing-admin .lnkup-landing-media img{max-width:120px;height:auto;border-radius:8px;border:1px solid #ddd}.lnkup-landing-admin .lnkup-landing-section{margin:2rem 0;padding:1.25rem;background:#fff;border:1px solid #dcdcde;border-radius:8px}.lnkup-landing-admin .lnkup-landing-section h2{margin-top:0}'
		);
	}

	/**
	 * Persist settings.
	 */
	public static function handle_save() {
		if ( ! isset( $_POST['lnkup_landing_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		check_admin_referer( 'lnkup_landing_save', 'lnkup_landing_nonce' );

		if ( ! empty( $_POST['lnkup_landing_reset'] ) ) {
			delete_option( self::OPTION );
			self::sync_launch_goals( self::defaults() );
			wp_safe_redirect( add_query_arg( array( 'page' => 'lnkup-landing', 'reset' => '1' ), admin_url( 'themes.php' ) ) );
			exit;
		}

		$raw = isset( $_POST['lnkup_landing'] ) && is_array( $_POST['lnkup_landing'] ) ? wp_unslash( $_POST['lnkup_landing'] ) : array();
		$clean = self::sanitize( $raw );
		update_option( self::OPTION, $clean, false );
		self::sync_launch_goals( $clean );

		wp_safe_redirect( add_query_arg( array( 'page' => 'lnkup-landing', 'updated' => '1' ), admin_url( 'themes.php' ) ) );
		exit;
	}

	/**
	 * Write launch goals into plugin settings.
	 *
	 * @param array<string,mixed> $landing Landing settings.
	 */
	public static function sync_launch_goals( array $landing ) {
		$vendors    = max( 0, (int) ( $landing['launch_goal_vendors'] ?? 4000 ) );
		$organizers = max( 0, (int) ( $landing['launch_goal_organizers'] ?? 500 ) );
		$settings   = get_option( 'lnkup_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['launch_goal_vendors']    = $vendors;
		$settings['launch_goal_organizers'] = $organizers;
		update_option( 'lnkup_settings', $settings, false );
	}

	/**
	 * @param array<string,mixed> $raw Raw POST.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $raw ) {
		$defaults = self::defaults();
		$out      = array();

		$text_keys = array(
			'hero_eyebrow', 'hero_title', 'hero_lead', 'hero_primary_cta', 'hero_secondary_cta',
			'hero_event_label', 'hero_event_title', 'hero_event_meta', 'hero_event_badge', 'hero_goal_override',
			'hiw_eyebrow', 'hiw_title',
			'aud_eyebrow', 'aud_title', 'aud_lead',
			'aud_vendor_title', 'aud_vendor_desc', 'aud_vendor_bullets', 'aud_vendor_cta',
			'aud_org_title', 'aud_org_desc', 'aud_org_bullets', 'aud_org_cta',
			'cat_eyebrow', 'cat_title', 'cat_aside', 'cat_list',
			'trust_eyebrow', 'trust_eyebrow_open', 'trust_title', 'trust_lead',
			'cta_title', 'cta_body', 'cta_button',
			'footer_title', 'footer_blurb', 'footer_cta',
		);
		foreach ( $text_keys as $key ) {
			$out[ $key ] = isset( $raw[ $key ] ) ? sanitize_textarea_field( (string) $raw[ $key ] ) : $defaults[ $key ];
		}

		$image_keys = array(
			'hero_image_id', 'aud_vendor_image_id', 'aud_org_image_id', 'cat_image_id', 'trust_image_id',
		);
		foreach ( $image_keys as $key ) {
			$out[ $key ] = isset( $raw[ $key ] ) ? absint( $raw[ $key ] ) : 0;
		}

		$out['use_custom_stats']       = ! empty( $raw['use_custom_stats'] ) ? 1 : 0;
		$out['launch_goal_vendors']    = isset( $raw['launch_goal_vendors'] ) ? max( 0, absint( $raw['launch_goal_vendors'] ) ) : 4000;
		$out['launch_goal_organizers'] = isset( $raw['launch_goal_organizers'] ) ? max( 0, absint( $raw['launch_goal_organizers'] ) ) : 500;

		$out['hiw_steps'] = array();
		$steps_raw        = isset( $raw['hiw_steps'] ) && is_array( $raw['hiw_steps'] ) ? $raw['hiw_steps'] : array();
		for ( $i = 0; $i < 4; $i++ ) {
			$row = isset( $steps_raw[ $i ] ) && is_array( $steps_raw[ $i ] ) ? $steps_raw[ $i ] : array();
			$out['hiw_steps'][] = array(
				'title'    => sanitize_text_field( (string) ( $row['title'] ?? $defaults['hiw_steps'][ $i ]['title'] ) ),
				'body'     => sanitize_textarea_field( (string) ( $row['body'] ?? $defaults['hiw_steps'][ $i ]['body'] ) ),
				'image_id' => absint( $row['image_id'] ?? 0 ),
			);
		}

		$out['stats'] = array();
		$stats_raw    = isset( $raw['stats'] ) && is_array( $raw['stats'] ) ? $raw['stats'] : array();
		for ( $i = 0; $i < 4; $i++ ) {
			$row = isset( $stats_raw[ $i ] ) && is_array( $stats_raw[ $i ] ) ? $stats_raw[ $i ] : array();
			$out['stats'][] = array(
				'value' => sanitize_text_field( (string) ( $row['value'] ?? $defaults['stats'][ $i ]['value'] ) ),
				'label' => sanitize_text_field( (string) ( $row['label'] ?? $defaults['stats'][ $i ]['label'] ) ),
				'sub'   => sanitize_text_field( (string) ( $row['sub'] ?? $defaults['stats'][ $i ]['sub'] ) ),
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
		$d = self::get_all();
		?>
		<div class="wrap lnkup-landing-admin">
			<h1><?php esc_html_e( 'Landing Page', 'lnkup-theme' ); ?></h1>
			<?php if ( ! empty( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Landing page saved.', 'lnkup-theme' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Landing page reset to defaults.', 'lnkup-theme' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Edit homepage copy, images, and stats. Empty images fall back to theme defaults. Featured event rails are removed from the homepage.', 'lnkup-theme' ); ?></p>
			<form method="post" action="">
				<?php wp_nonce_field( 'lnkup_landing_save', 'lnkup_landing_nonce' ); ?>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'Launch goals', 'lnkup-theme' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="launch_goal_vendors"><?php esc_html_e( 'Vendor goal', 'lnkup-theme' ); ?></label></th>
							<td><input name="lnkup_landing[launch_goal_vendors]" id="launch_goal_vendors" type="number" min="0" class="small-text" value="<?php echo esc_attr( (string) $d['launch_goal_vendors'] ); ?>"></td>
						</tr>
						<tr>
							<th><label for="launch_goal_organizers"><?php esc_html_e( 'Organizer goal', 'lnkup-theme' ); ?></label></th>
							<td><input name="lnkup_landing[launch_goal_organizers]" id="launch_goal_organizers" type="number" min="0" class="small-text" value="<?php echo esc_attr( (string) $d['launch_goal_organizers'] ); ?>"></td>
						</tr>
					</table>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'Hero', 'lnkup-theme' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::field_text( 'hero_eyebrow', __( 'Eyebrow', 'lnkup-theme' ), $d['hero_eyebrow'] );
						self::field_text( 'hero_title', __( 'Title', 'lnkup-theme' ), $d['hero_title'] );
						self::field_textarea( 'hero_lead', __( 'Lead', 'lnkup-theme' ), $d['hero_lead'] );
						self::field_text( 'hero_primary_cta', __( 'Primary CTA label', 'lnkup-theme' ), $d['hero_primary_cta'] );
						self::field_text( 'hero_secondary_cta', __( 'Secondary CTA label', 'lnkup-theme' ), $d['hero_secondary_cta'] );
						self::field_media( 'hero_image_id', __( 'Hero image', 'lnkup-theme' ), (int) $d['hero_image_id'] );
						self::field_text( 'hero_event_label', __( 'Event card label', 'lnkup-theme' ), $d['hero_event_label'] );
						self::field_text( 'hero_event_title', __( 'Event card title', 'lnkup-theme' ), $d['hero_event_title'] );
						self::field_text( 'hero_event_meta', __( 'Event card meta', 'lnkup-theme' ), $d['hero_event_meta'] );
						self::field_text( 'hero_event_badge', __( 'Event card badge', 'lnkup-theme' ), $d['hero_event_badge'] );
						self::field_text( 'hero_goal_override', __( 'Goal badge override (optional)', 'lnkup-theme' ), $d['hero_goal_override'] );
						?>
					</table>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'How it works', 'lnkup-theme' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::field_text( 'hiw_eyebrow', __( 'Eyebrow', 'lnkup-theme' ), $d['hiw_eyebrow'] );
						self::field_text( 'hiw_title', __( 'Title', 'lnkup-theme' ), $d['hiw_title'] );
						?>
					</table>
					<?php foreach ( $d['hiw_steps'] as $i => $step ) : ?>
						<h3><?php echo esc_html( sprintf( __( 'Step %d', 'lnkup-theme' ), $i + 1 ) ); ?></h3>
						<table class="form-table" role="presentation">
							<tr>
								<th><label><?php esc_html_e( 'Title', 'lnkup-theme' ); ?></label></th>
								<td><input class="regular-text" type="text" name="lnkup_landing[hiw_steps][<?php echo esc_attr( (string) $i ); ?>][title]" value="<?php echo esc_attr( $step['title'] ); ?>"></td>
							</tr>
							<tr>
								<th><label><?php esc_html_e( 'Body', 'lnkup-theme' ); ?></label></th>
								<td><textarea class="large-text" rows="3" name="lnkup_landing[hiw_steps][<?php echo esc_attr( (string) $i ); ?>][body]"><?php echo esc_textarea( $step['body'] ); ?></textarea></td>
							</tr>
							<tr>
								<th><label><?php esc_html_e( 'Image', 'lnkup-theme' ); ?></label></th>
								<td><?php self::media_control( 'lnkup_landing[hiw_steps][' . $i . '][image_id]', (int) $step['image_id'] ); ?></td>
							</tr>
						</table>
					<?php endforeach; ?>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'Built for vendors & organizers', 'lnkup-theme' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::field_text( 'aud_eyebrow', __( 'Eyebrow', 'lnkup-theme' ), $d['aud_eyebrow'] );
						self::field_text( 'aud_title', __( 'Title', 'lnkup-theme' ), $d['aud_title'] );
						self::field_textarea( 'aud_lead', __( 'Lead (optional)', 'lnkup-theme' ), $d['aud_lead'] );
						self::field_text( 'aud_vendor_title', __( 'Vendor title', 'lnkup-theme' ), $d['aud_vendor_title'] );
						self::field_textarea( 'aud_vendor_desc', __( 'Vendor description', 'lnkup-theme' ), $d['aud_vendor_desc'] );
						self::field_textarea( 'aud_vendor_bullets', __( 'Vendor bullets (one per line)', 'lnkup-theme' ), $d['aud_vendor_bullets'] );
						self::field_text( 'aud_vendor_cta', __( 'Vendor CTA', 'lnkup-theme' ), $d['aud_vendor_cta'] );
						self::field_media( 'aud_vendor_image_id', __( 'Vendor image', 'lnkup-theme' ), (int) $d['aud_vendor_image_id'] );
						self::field_text( 'aud_org_title', __( 'Organizer title', 'lnkup-theme' ), $d['aud_org_title'] );
						self::field_textarea( 'aud_org_desc', __( 'Organizer description', 'lnkup-theme' ), $d['aud_org_desc'] );
						self::field_textarea( 'aud_org_bullets', __( 'Organizer bullets (one per line)', 'lnkup-theme' ), $d['aud_org_bullets'] );
						self::field_text( 'aud_org_cta', __( 'Organizer CTA', 'lnkup-theme' ), $d['aud_org_cta'] );
						self::field_media( 'aud_org_image_id', __( 'Organizer image', 'lnkup-theme' ), (int) $d['aud_org_image_id'] );
						?>
					</table>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'Categories', 'lnkup-theme' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::field_text( 'cat_eyebrow', __( 'Eyebrow', 'lnkup-theme' ), $d['cat_eyebrow'] );
						self::field_text( 'cat_title', __( 'Title', 'lnkup-theme' ), $d['cat_title'] );
						self::field_text( 'cat_aside', __( 'Aside', 'lnkup-theme' ), $d['cat_aside'] );
						self::field_textarea( 'cat_list', __( 'Categories (one per line)', 'lnkup-theme' ), $d['cat_list'] );
						self::field_media( 'cat_image_id', __( 'Banner image', 'lnkup-theme' ), (int) $d['cat_image_id'] );
						?>
					</table>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'Trust / live event economy', 'lnkup-theme' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::field_text( 'trust_eyebrow', __( 'Eyebrow (prelaunch)', 'lnkup-theme' ), $d['trust_eyebrow'] );
						self::field_text( 'trust_eyebrow_open', __( 'Eyebrow (marketplace open)', 'lnkup-theme' ), $d['trust_eyebrow_open'] );
						self::field_text( 'trust_title', __( 'Title', 'lnkup-theme' ), $d['trust_title'] );
						self::field_textarea( 'trust_lead', __( 'Lead', 'lnkup-theme' ), $d['trust_lead'] );
						self::field_media( 'trust_image_id', __( 'Side image', 'lnkup-theme' ), (int) $d['trust_image_id'] );
						?>
						<tr>
							<th><?php esc_html_e( 'Custom stats', 'lnkup-theme' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="lnkup_landing[use_custom_stats]" value="1" <?php checked( ! empty( $d['use_custom_stats'] ) ); ?>>
									<?php esc_html_e( 'Use custom homepage stats (overrides auto launch goals / live totals)', 'lnkup-theme' ); ?>
								</label>
							</td>
						</tr>
					</table>
					<?php foreach ( $d['stats'] as $i => $stat ) : ?>
						<h3><?php echo esc_html( sprintf( __( 'Stat %d', 'lnkup-theme' ), $i + 1 ) ); ?></h3>
						<table class="form-table" role="presentation">
							<tr>
								<th><label><?php esc_html_e( 'Value', 'lnkup-theme' ); ?></label></th>
								<td><input class="regular-text" type="text" name="lnkup_landing[stats][<?php echo esc_attr( (string) $i ); ?>][value]" value="<?php echo esc_attr( $stat['value'] ); ?>" placeholder="<?php echo 0 === $i ? esc_attr__( 'Leave blank to use vendor goal', 'lnkup-theme' ) : ( 1 === $i ? esc_attr__( 'Leave blank to use organizer goal', 'lnkup-theme' ) : '' ); ?>"></td>
							</tr>
							<tr>
								<th><label><?php esc_html_e( 'Label', 'lnkup-theme' ); ?></label></th>
								<td><input class="regular-text" type="text" name="lnkup_landing[stats][<?php echo esc_attr( (string) $i ); ?>][label]" value="<?php echo esc_attr( $stat['label'] ); ?>"></td>
							</tr>
							<tr>
								<th><label><?php esc_html_e( 'Subtext', 'lnkup-theme' ); ?></label></th>
								<td><input class="regular-text" type="text" name="lnkup_landing[stats][<?php echo esc_attr( (string) $i ); ?>][sub]" value="<?php echo esc_attr( $stat['sub'] ); ?>"></td>
							</tr>
						</table>
					<?php endforeach; ?>
				</div>

				<div class="lnkup-landing-section">
					<h2><?php esc_html_e( 'Final CTA & footer', 'lnkup-theme' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::field_text( 'cta_title', __( 'Final CTA title', 'lnkup-theme' ), $d['cta_title'] );
						self::field_textarea( 'cta_body', __( 'Final CTA body', 'lnkup-theme' ), $d['cta_body'] );
						self::field_text( 'cta_button', __( 'Final CTA button', 'lnkup-theme' ), $d['cta_button'] );
						self::field_text( 'footer_title', __( 'Footer title', 'lnkup-theme' ), $d['footer_title'] );
						self::field_textarea( 'footer_blurb', __( 'Footer blurb', 'lnkup-theme' ), $d['footer_blurb'] );
						self::field_text( 'footer_cta', __( 'Footer CTA', 'lnkup-theme' ), $d['footer_cta'] );
						?>
					</table>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save landing page', 'lnkup-theme' ); ?></button>
					<button type="submit" name="lnkup_landing_reset" value="1" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Reset all landing fields to defaults?', 'lnkup-theme' ) ); ?>');"><?php esc_html_e( 'Reset to defaults', 'lnkup-theme' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * @param string $key   Field key.
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private static function field_text( $key, $label, $value ) {
		?>
		<tr>
			<th><label for="lnkup_landing_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input class="regular-text" type="text" id="lnkup_landing_<?php echo esc_attr( $key ); ?>" name="lnkup_landing[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>"></td>
		</tr>
		<?php
	}

	/**
	 * @param string $key   Field key.
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private static function field_textarea( $key, $label, $value ) {
		?>
		<tr>
			<th><label for="lnkup_landing_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><textarea class="large-text" rows="3" id="lnkup_landing_<?php echo esc_attr( $key ); ?>" name="lnkup_landing[<?php echo esc_attr( $key ); ?>]"><?php echo esc_textarea( $value ); ?></textarea></td>
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
			<?php if ( $url ) : ?>
				<img src="<?php echo esc_url( $url ); ?>" alt="" class="lnkup-landing-media__preview">
			<?php else : ?>
				<img src="" alt="" class="lnkup-landing-media__preview" hidden>
			<?php endif; ?>
			<button type="button" class="button lnkup-landing-media__select"><?php esc_html_e( 'Select image', 'lnkup-theme' ); ?></button>
			<button type="button" class="button lnkup-landing-media__clear" <?php echo $id ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'lnkup-theme' ); ?></button>
		</div>
		<?php
	}
}

LNKup_Theme_Landing_Settings::init();
