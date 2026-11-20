<?php
/**
 * Plugin activation and seeding.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Activator {

	/**
	 * Activate plugin.
	 */
	public static function activate() {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';
		require_once ABSPATH . 'wp-admin/includes/taxonomy.php';

		$was_installed = (bool) get_option( 'lnkup_db_version' );

		try {
			LNKup_Roles::register_roles();
			LNKup_Roles::sync_role_capabilities();
			LNKup_Post_Types::register();
			LNKup_Taxonomies::register();
			LNKup_Taxonomies::seed_terms();
			self::create_all_tables();
			self::create_pages();
			update_option( 'users_can_register', 1 );

			try {
				self::seed_sample_data();
				self::seed_marketplace_showcase_data();
			} catch ( Throwable $seed_error ) {
				error_log( 'LNKup sample data seed failed: ' . $seed_error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			if ( $was_installed ) {
				update_option( 'lnkup_needs_deferred_upgrade', LNKUP_VERSION );
			}

			LNKup_Marketplace_Cron::schedule();
			LNKup_Intelligence_Cron::schedule();
			LNKup_Financial_Cron::schedule();
			LNKup_Growth_Cron::schedule();
			if ( class_exists( 'LNKup_Market_Pages' ) ) {
				LNKup_Market_Pages::maybe_seed_markets();
			}
			if ( class_exists( 'LNKup_Weather_Cron' ) ) {
				LNKup_Weather_Cron::schedule();
			}
			flush_rewrite_rules();
			update_option( 'lnkup_db_version', LNKUP_VERSION );
			update_option( 'lnkup_seeded', LNKUP_VERSION );
			delete_transient( 'lnkup_activation_error' );
		} catch ( Throwable $e ) {
			$message = $e->getMessage();
			error_log( 'LNKup activation failed: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			set_transient( 'lnkup_activation_error', $message, DAY_IN_SECONDS );
		}
	}

	/**
	 * Create all plugin database tables (safe to call repeatedly).
	 */
	public static function create_all_tables() {
		LNKup_Marketplace_Index::create_tables();
		LNKup_Marketplace_Analytics::create_tables();
		LNKup_Organizer_CRM_Tables::create_tables();
		LNKup_Reputation_Tables::create_tables();
		LNKup_Operations_Tables::create_tables();
		LNKup_Intelligence_Tables::create_tables();
		LNKup_Map_Tables::create_tables();
		LNKup_Financial_Tables::create_tables();
		LNKup_Growth_Tables::create_tables();
		if ( class_exists( 'LNKup_Weather_Tables' ) ) {
			LNKup_Weather_Tables::create_tables();
		}
		if ( class_exists( 'LNKup_Outreach_Tables' ) ) {
			LNKup_Outreach_Tables::create_tables();
		}
		if ( class_exists( 'LNKup_External_Events_Tables' ) ) {
			LNKup_External_Events_Tables::create_tables();
		}
		if ( class_exists( 'LNKup_Search_Ingest_Tables' ) ) {
			LNKup_Search_Ingest_Tables::create_tables();
		}
		if ( class_exists( 'LNKup_Vendor_Import_Tables' ) ) {
			LNKup_Vendor_Import_Tables::create_tables();
		}
		if ( class_exists( 'LNKup_Connector_Tables' ) ) {
			LNKup_Connector_Tables::create_tables();
		}
		if ( class_exists( 'LNKup_Push_Tables' ) ) {
			LNKup_Push_Tables::create_tables();
		}
		if ( class_exists( 'LNKup_Inbox_Tables' ) ) {
			LNKup_Inbox_Tables::create_tables();
		}
	}

	/**
	 * Run deferred upgrade work in admin (index rebuild, backfills).
	 */
	public static function maybe_run_deferred_upgrade() {
		if ( ! is_admin() ) {
			return;
		}
		$pending = get_option( 'lnkup_needs_deferred_upgrade' );
		if ( ! $pending || $pending !== LNKUP_VERSION ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			return;
		}

		try {
			if ( class_exists( 'LNKup_Fee_Engine' ) ) {
				LNKup_Fee_Engine::migrate_fee_configuration();
			}
			self::migrate_document_modes();
			LNKup_Transactions::backfill_from_payments();
			LNKup_Transactions::backfill_extended_columns();
			LNKup_Marketplace_Index::rebuild_all();
			LNKup_Organizer_CRM::backfill_relationships();
			LNKup_Vendor_Reputation::backfill_all();
			LNKup_Growth::backfill_all();
			self::seed_marketplace_showcase_data();
			delete_option( 'lnkup_needs_deferred_upgrade' );
			delete_transient( 'lnkup_activation_error' );
		} catch ( Throwable $e ) {
			error_log( 'LNKup deferred upgrade failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			set_transient( 'lnkup_activation_error', $e->getMessage(), DAY_IN_SECONDS );
		}
	}

	/**
	 * Migrate legacy opportunities to explicit document mode meta.
	 */
	public static function migrate_document_modes() {
		if ( get_option( 'lnkup_document_mode_migrated_v1' ) ) {
			return;
		}
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => 'any',
			)
		);
		foreach ( $opps as $opp_id ) {
			$mode = (string) get_post_meta( $opp_id, '_lnkup_document_mode', true );
			if ( $mode ) {
				continue;
			}
			$raw = (string) get_post_meta( $opp_id, '_lnkup_required_documents', true );
			if ( 'none' === $raw ) {
				update_post_meta( $opp_id, '_lnkup_document_mode', 'none' );
			} elseif ( $raw ) {
				update_post_meta( $opp_id, '_lnkup_document_mode', 'custom' );
			} else {
				update_post_meta( $opp_id, '_lnkup_document_mode', 'platform_defaults' );
			}
		}
		update_option( 'lnkup_document_mode_migrated_v1', 1 );
	}

	/**
	 * Deactivate plugin.
	 */
	public static function deactivate() {
		LNKup_Marketplace_Cron::unschedule();
		LNKup_Financial_Cron::unschedule();
		if ( class_exists( 'LNKup_External_Events_Cron' ) ) {
			wp_clear_scheduled_hook( LNKup_External_Events_Cron::HOOK );
			LNKup_External_Events_Cron::unschedule_sync();
		}
		flush_rewrite_rules();
	}

	/**
	 * Create required pages.
	 */
	public static function create_pages() {
		$pages = array(
			'vendor-dashboard'    => array(
				'title'    => 'Vendor Dashboard',
				'template' => 'page-templates/vendor-dashboard.php',
				'content'  => '[lnkup_vendor_dashboard]',
			),
			'organizer-dashboard' => array(
				'title'    => 'Organizer Dashboard',
				'template' => 'page-templates/organizer-dashboard.php',
				'content'  => '[lnkup_organizer_dashboard]',
			),
			'opportunities'       => array(
				'title'    => 'Find Events',
				'template' => 'page-templates/vendor-shell.php',
				'content'  => '[lnkup_opportunities_feed]',
			),
			'find-events'         => array(
				'title'    => 'Find Events',
				'template' => 'page-templates/vendor-shell.php',
				'content'  => '[lnkup_opportunities_feed]',
			),
			'vendor-profile'      => array(
				'title'    => 'Vendor Profile',
				'template' => 'page-templates/vendor-shell.php',
				'content'  => '[lnkup_vendor_profile_form]',
			),
			'favorites'           => array(
				'title'    => 'Favorites',
				'template' => 'page-templates/vendor-shell.php',
				'content'  => '[lnkup_favorites]',
			),
			'vendors'             => array(
				'title'    => 'Vendor Directory',
				'template' => 'page-templates/full-width.php',
				'content'  => '[lnkup_vendor_public_profile]',
			),
			'organizers'          => array(
				'title'    => 'Organizers',
				'template' => 'page-templates/public-storefront.php',
				'content'  => '[lnkup_organizer_public_profile]',
			),
			'sign-up'             => array(
				'title'    => 'Sign Up',
				'template' => 'page-templates/sign-up.php',
				'content'  => '[lnkup_register_form]',
			),
			'log-in'              => array(
				'title'    => 'Log In',
				'template' => 'page-templates/log-in.php',
				'content'  => '[lnkup_login_form]',
			),
			'connector-dashboard' => array(
				'title'    => 'Connector Dashboard',
				'template' => 'page-templates/connector-dashboard.php',
				'content'  => '[lnkup_connector_dashboard]',
			),
			'connector-interest' => array(
				'title'    => 'Become a City Connector',
				'template' => 'page-templates/connector-interest.php',
				'content'  => '[lnkup_connector_interest_form]',
			),
			'claim-event'        => array(
				'title'    => 'Claim Event',
				'template' => 'page-templates/full-width.php',
				'content'  => '[lnkup_external_claim_landing]',
			),
		);

		$created = get_option( 'lnkup_pages_created', array() );

		foreach ( $pages as $slug => $data ) {
			if ( ! empty( $created[ $slug ] ) && get_post( $created[ $slug ] ) ) {
				continue;
			}
			$existing = get_page_by_path( $slug );
			if ( $existing ) {
				$created[ $slug ] = $existing->ID;
				continue;
			}
			$page_id = wp_insert_post(
				array(
					'post_title'   => $data['title'],
					'post_name'    => $slug,
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => $data['content'],
				)
			);
			if ( $page_id && ! is_wp_error( $page_id ) ) {
				update_post_meta( $page_id, '_wp_page_template', $data['template'] );
				$created[ $slug ] = $page_id;
			}
		}

		update_option( 'lnkup_pages_created', $created );

		$front_page_id = (int) get_option( 'page_on_front' );
		if ( ! $front_page_id ) {
			$front = get_page_by_path( 'home' );
			if ( $front ) {
				$front_page_id = $front->ID;
			} else {
				$front_page_id = wp_insert_post(
					array(
						'post_title'  => 'Home',
						'post_name'   => 'home',
						'post_status' => 'publish',
						'post_type'   => 'page',
					)
				);
			}
			if ( $front_page_id && ! is_wp_error( $front_page_id ) ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $front_page_id );
			}
		}
	}

	/**
	 * Sync vendor shell templates and shortcode content on existing pages.
	 */
	public static function sync_dashboard_templates() {
		$map = array(
			'vendor-dashboard' => array(
				'template' => 'page-templates/vendor-dashboard.php',
				'content'  => '[lnkup_vendor_dashboard]',
			),
			'organizer-dashboard' => array(
				'template' => 'page-templates/organizer-dashboard.php',
				'content'  => '[lnkup_organizer_dashboard]',
			),
			'opportunities'  => array(
				'template' => 'page-templates/vendor-shell.php',
				'content'  => '[lnkup_opportunities_feed]',
			),
			'find-events'    => array(
				'template' => 'page-templates/vendor-shell.php',
				'content'  => '[lnkup_opportunities_feed]',
			),
			'vendor-profile' => array(
				'template' => 'page-templates/vendor-shell.php',
				'content'  => '[lnkup_vendor_profile_form]',
			),
			'favorites'      => array(
				'template' => 'page-templates/vendor-shell.php',
				'content'  => '[lnkup_favorites]',
			),
			'sign-up'        => array(
				'template' => 'page-templates/sign-up.php',
				'content'  => '[lnkup_register_form]',
			),
			'log-in'         => array(
				'template' => 'page-templates/log-in.php',
				'content'  => '[lnkup_login_form]',
			),
			'connector-dashboard' => array(
				'template' => 'page-templates/connector-dashboard.php',
				'content'  => '[lnkup_connector_dashboard]',
			),
			'connector-interest' => array(
				'template' => 'page-templates/connector-interest.php',
				'content'  => '[lnkup_connector_interest_form]',
			),
			'organizers' => array(
				'template' => 'page-templates/public-storefront.php',
				'content'  => '[lnkup_organizer_public_profile]',
			),
		);
		$created = get_option( 'lnkup_pages_created', array() );
		foreach ( $map as $slug => $data ) {
			$page_id = ! empty( $created[ $slug ] ) ? (int) $created[ $slug ] : 0;
			if ( ! $page_id ) {
				$page = get_page_by_path( $slug );
				$page_id = $page ? (int) $page->ID : 0;
			}
			if ( ! $page_id ) {
				continue;
			}
			update_post_meta( $page_id, '_wp_page_template', $data['template'] );
			$page = get_post( $page_id );
			if ( $page && false === strpos( $page->post_content, $data['content'] ) ) {
				wp_update_post(
					array(
						'ID'           => $page_id,
						'post_content' => $data['content'],
					)
				);
			}
		}
	}

	/**
	 * Run template repair once per theme/plugin version bump.
	 */
	public static function maybe_repair_dashboard_pages() {
		$version = LNKUP_VERSION . '-' . ( defined( 'LNKUP_THEME_VERSION' ) ? LNKUP_THEME_VERSION : '0' );
		if ( get_option( 'lnkup_template_sync_version' ) === $version ) {
			return;
		}
		self::create_pages();
		self::sync_dashboard_templates();
		update_option( 'lnkup_template_sync_version', $version );
	}

	/**
	 * Warn admins when vendor pages still use the wrong page template.
	 */
	public static function maybe_dashboard_template_notice() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$expected = array(
			'vendor-dashboard'    => 'page-templates/vendor-dashboard.php',
			'organizer-dashboard' => 'page-templates/organizer-dashboard.php',
			'opportunities'       => 'page-templates/vendor-shell.php',
			'find-events'         => 'page-templates/vendor-shell.php',
			'vendor-profile'      => 'page-templates/vendor-shell.php',
			'favorites'           => 'page-templates/vendor-shell.php',
		);
		$wrong = array();
		foreach ( $expected as $slug => $template ) {
			$page = get_page_by_path( $slug );
			if ( $page && get_page_template_slug( $page->ID ) !== $template ) {
				$wrong[] = $slug;
			}
		}
		if ( ! $wrong ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated page slugs */
					__( 'LNKup dashboard pages (%s) are not using the correct page template. Re-save permalinks or re-activate the plugin to fix navigation.', 'lnkup-marketplace' ),
					implode( ', ', $wrong )
				)
			)
		);
	}

	/**
	 * Seed sample events and opportunities.
	 */
	public static function seed_sample_data() {
		if ( get_option( 'lnkup_sample_data_seeded' ) ) {
			return;
		}

		$organizer_id = self::get_or_create_demo_organizer();
		$samples      = array(
			array(
				'event' => 'Dirty South Rodeo Charlotte',
				'city'  => 'Charlotte', 'state' => 'NC', 'date' => '2026-08-16',
				'attendance' => 12000, 'type' => 'Rodeo', 'image' => 'rodeo',
				'fee' => 250, 'spots' => 40, 'remaining' => 6,
				'categories' => array( 'Food Truck', 'Drinks', 'Clothing', 'Retail' ),
			),
			array(
				'event' => 'Soul District Block Party',
				'city'  => 'Atlanta', 'state' => 'GA', 'date' => '2026-07-04',
				'attendance' => 8500, 'type' => 'Block Party', 'image' => 'block-party',
				'fee' => 180, 'spots' => 50, 'remaining' => 12,
				'categories' => array( 'Food Vendor', 'Desserts', 'Beauty', 'Art' ),
			),
			array(
				'event' => 'Sunset & Soul Miami',
				'city'  => 'Miami', 'state' => 'FL', 'date' => '2026-09-05',
				'attendance' => 15000, 'type' => 'Festival', 'image' => 'sunset-miami',
				'fee' => 400, 'spots' => 35, 'remaining' => 3,
				'categories' => array( 'Drinks', 'Food Truck', 'Jewelry', 'Clothing' ),
			),
			array(
				'event' => 'NC Greek Picnic',
				'city'  => 'Raleigh', 'state' => 'NC', 'date' => '2026-06-21',
				'attendance' => 20000, 'type' => 'Greek Event', 'image' => 'greek-picnic',
				'fee' => 300, 'spots' => 60, 'remaining' => 9,
				'categories' => array( 'Food Vendor', 'Clothing', 'Photo Booth', 'Services' ),
			),
			array(
				'event' => 'GHOE Vendor Market',
				'city'  => 'Greensboro', 'state' => 'NC', 'date' => '2026-11-01',
				'attendance' => 30000, 'type' => 'Market', 'image' => 'ghoe-market',
				'fee' => 350, 'spots' => 80, 'remaining' => 18,
				'categories' => array( 'Retail', 'Clothing', 'Art', 'Beauty', 'Desserts' ),
			),
		);

		$settings = lnkup_get_settings();
		$seeded   = 0;

		foreach ( $samples as $sample ) {
			$event_id = wp_insert_post(
				array(
					'post_title'   => $sample['event'],
					'post_status'  => 'publish',
					'post_type'    => 'lnkup_event',
					'post_content' => sprintf(
						/* translators: %s: event name */
						__( 'Vendor opportunities available for %s.', 'lnkup-marketplace' ),
						$sample['event']
					),
				)
			);
			if ( ! $event_id || is_wp_error( $event_id ) ) {
				continue;
			}

			update_post_meta( $event_id, '_lnkup_organizer_user_id', $organizer_id );
			update_post_meta( $event_id, '_lnkup_event_date', $sample['date'] );
			update_post_meta( $event_id, '_lnkup_city', $sample['city'] );
			update_post_meta( $event_id, '_lnkup_state', $sample['state'] );
			update_post_meta( $event_id, '_lnkup_expected_attendance', $sample['attendance'] );
			update_post_meta( $event_id, '_lnkup_ticketing_platform', 'Manual' );
			update_post_meta( $event_id, '_lnkup_status', 'published' );
			update_post_meta( $event_id, '_lnkup_venue_hidden', true );
			update_post_meta( $event_id, '_lnkup_outdoor', true );
			update_post_meta( $event_id, '_lnkup_power_available', true );
			update_post_meta( $event_id, '_lnkup_tags', 'festival, outdoor' );

			wp_set_object_terms( $event_id, $sample['type'], 'lnkup_event_type' );

			$opp_id = wp_insert_post(
				array(
					'post_title'  => $sample['event'] . ' - Vendor Opportunity',
					'post_status' => 'publish',
					'post_type'   => 'lnkup_opportunity',
				)
			);
			if ( ! $opp_id || is_wp_error( $opp_id ) ) {
				continue;
			}

			update_post_meta( $opp_id, '_lnkup_event_id', $event_id );
			update_post_meta( $opp_id, '_lnkup_spots_total', $sample['spots'] );
			update_post_meta( $opp_id, '_lnkup_spots_remaining', $sample['remaining'] );
			update_post_meta( $opp_id, '_lnkup_vendor_fee', $sample['fee'] );
			update_post_meta( $opp_id, '_lnkup_lnkup_fee', class_exists( 'LNKup_Fee_Engine' ) ? LNKup_Fee_Engine::resolve_vendor_checkout_fee_from_settings() : (float) ( $settings['vendor_checkout_fee'] ?? 5.99 ) );
			update_post_meta( $opp_id, '_lnkup_application_deadline', gmdate( 'Y-m-d', strtotime( $sample['date'] . ' -30 days' ) ) );
			update_post_meta( $opp_id, '_lnkup_opportunity_status', 'open' );

			wp_set_object_terms( $opp_id, $sample['categories'], 'lnkup_vendor_category' );
			LNKup_Marketplace_Index::sync_opportunity( $opp_id );
			++$seeded;
		}

		if ( $seeded > 0 ) {
			update_option( 'lnkup_sample_data_seeded', true );
		}
	}

	/**
	 * Seed diverse marketplace showcase events (one-time).
	 */
	public static function seed_marketplace_showcase_data() {
		if ( get_option( 'lnkup_marketplace_showcase_seeded' ) ) {
			return;
		}

		$organizer_id = self::get_or_create_demo_organizer();
		$settings     = lnkup_get_settings();
		$samples      = array(
			array(
				'event' => 'Innovate Summit Charlotte',
				'type'  => 'Conference',
				'city'  => 'Charlotte', 'state' => 'NC', 'date' => '2026-10-15',
				'attendance' => 3500, 'fee' => 450, 'spots' => 25, 'remaining' => 14,
				'venue' => 'Convention Center Hall B', 'indoor' => true, 'outdoor' => false,
				'categories' => array( 'Food Vendor', 'Beverage Vendor', 'Retail Vendor' ),
				'docs' => 'certificate_of_insurance,business_license',
				'market' => 'Carolinas',
			),
			array(
				'event' => 'Voices on Main Stage',
				'type'  => 'Speaking Event',
				'city'  => 'Washington', 'state' => 'DC', 'date' => '2026-09-20',
				'attendance' => 1200, 'fee' => 200, 'spots' => 15, 'remaining' => 8,
				'venue' => 'Capitol View Theater', 'indoor' => true, 'outdoor' => false,
				'categories' => array( 'Food Vendor', 'Merchandise Vendor' ),
				'docs' => 'certificate_of_insurance',
				'market' => 'Mid-Atlantic',
			),
			array(
				'event' => 'Midtown Pop-Up Market',
				'type'  => 'Pop-Up',
				'city'  => 'Atlanta', 'state' => 'GA', 'date' => '2026-08-02',
				'attendance' => 800, 'fee' => 75, 'spots' => 20, 'remaining' => 11,
				'venue' => 'Peachtree Plaza', 'indoor' => false, 'outdoor' => true,
				'categories' => array( 'Clothing Brand', 'Jewelry Vendor', 'Art Vendor' ),
				'docs' => '',
				'market' => 'Southeast',
			),
			array(
				'event' => 'Lone Star Rodeo Expo',
				'type'  => 'Rodeo',
				'city'  => 'Dallas', 'state' => 'TX', 'date' => '2026-07-18',
				'attendance' => 18000, 'fee' => 325, 'spots' => 55, 'remaining' => 22,
				'venue' => 'Fair Park Arena', 'indoor' => false, 'outdoor' => true,
				'categories' => array( 'Food Truck', 'Retail Vendor' ),
				'docs' => 'certificate_of_insurance,health_permit',
				'market' => 'Texas',
			),
			array(
				'event' => 'HBCU Homecoming Vendor Row',
				'type'  => 'HBCU Event',
				'city'  => 'Durham', 'state' => 'NC', 'date' => '2026-10-08',
				'attendance' => 22000, 'fee' => 275, 'spots' => 70, 'remaining' => 31,
				'venue' => 'Campus Quad', 'indoor' => false, 'outdoor' => true,
				'categories' => array( 'Clothing Brand', 'Beauty Vendor', 'Food Vendor' ),
				'docs' => 'certificate_of_insurance,w9',
				'market' => 'Carolinas',
			),
			array(
				'event' => 'Downtown Night Market',
				'type'  => 'Market',
				'city'  => 'Nashville', 'state' => 'TN', 'date' => '2026-06-28',
				'attendance' => 4500, 'fee' => 150, 'spots' => 40, 'remaining' => 19,
				'venue' => 'Riverfront Pavilion', 'indoor' => false, 'outdoor' => true,
				'categories' => array( 'Food Truck', 'Dessert Vendor', 'Art Vendor' ),
				'docs' => 'health_permit',
				'market' => 'Southeast',
			),
			array(
				'event' => 'Pulse Brand Activation Tour',
				'type'  => 'Brand Activation',
				'city'  => 'Miami', 'state' => 'FL', 'date' => '2026-11-12',
				'attendance' => 6000, 'fee' => 500, 'spots' => 12, 'remaining' => 4,
				'venue' => 'Wynwood Warehouse', 'indoor' => true, 'outdoor' => true,
				'categories' => array( 'Mobile Business', 'Photo Booth Vendor', 'Beverage Vendor' ),
				'docs' => 'certificate_of_insurance,business_license,w9',
				'market' => 'Florida',
			),
			array(
				'event' => 'Taste of the South Food Festival',
				'type'  => 'Food Festival',
				'city'  => 'Birmingham', 'state' => 'AL', 'date' => '2026-09-01',
				'attendance' => 14000, 'fee' => 380, 'spots' => 45, 'remaining' => 17,
				'venue' => 'Railroad Park', 'indoor' => false, 'outdoor' => true,
				'categories' => array( 'Food Truck', 'Food Vendor', 'Dessert Vendor' ),
				'docs' => 'certificate_of_insurance,health_permit',
				'market' => 'Southeast',
			),
			array(
				'event' => 'Maker Skills Workshop Weekend',
				'type'  => 'Workshop',
				'city'  => 'Raleigh', 'state' => 'NC', 'date' => '2026-08-22',
				'attendance' => 600, 'fee' => 95, 'spots' => 18, 'remaining' => 10,
				'venue' => 'Innovation Hub', 'indoor' => true, 'outdoor' => false,
				'categories' => array( 'Art Vendor', 'Service Vendor', 'Merchandise Vendor' ),
				'docs' => 'business_license',
				'market' => 'Carolinas',
			),
			array(
				'event' => 'Rain or Shine Tented Expo',
				'type'  => 'Trade Show',
				'city'  => 'Orlando', 'state' => 'FL', 'date' => '2026-12-05',
				'attendance' => 9000, 'fee' => 425, 'spots' => 60, 'remaining' => 28,
				'venue' => 'Expo Center Tent Lot', 'indoor' => false, 'outdoor' => true,
				'categories' => array( 'Retail Vendor', 'Wellness Vendor', 'Beauty Vendor' ),
				'docs' => 'certificate_of_insurance',
				'market' => 'Florida',
				'tent' => true,
				'tags' => 'tented, rain or shine',
			),
		);

		$seeded = 0;
		foreach ( $samples as $sample ) {
			global $wpdb;
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'lnkup_event' AND post_title = %s LIMIT 1", $sample['event'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $exists ) {
				continue;
			}

			$event_id = wp_insert_post(
				array(
					'post_title'   => $sample['event'],
					'post_status'  => 'publish',
					'post_type'    => 'lnkup_event',
					'post_content' => sprintf(
						/* translators: %s: event name */
						__( 'Vendor opportunities for %s.', 'lnkup-marketplace' ),
						$sample['event']
					),
				)
			);
			if ( ! $event_id || is_wp_error( $event_id ) ) {
				continue;
			}

			update_post_meta( $event_id, '_lnkup_organizer_user_id', $organizer_id );
			update_post_meta( $event_id, '_lnkup_event_date', $sample['date'] );
			update_post_meta( $event_id, '_lnkup_city', $sample['city'] );
			update_post_meta( $event_id, '_lnkup_state', $sample['state'] );
			update_post_meta( $event_id, '_lnkup_venue_name', $sample['venue'] );
			update_post_meta( $event_id, '_lnkup_expected_attendance', $sample['attendance'] );
			update_post_meta( $event_id, '_lnkup_ticketing_platform', 'Manual' );
			update_post_meta( $event_id, '_lnkup_status', 'published' );
			update_post_meta( $event_id, '_lnkup_indoor', ! empty( $sample['indoor'] ) );
			update_post_meta( $event_id, '_lnkup_outdoor', ! empty( $sample['outdoor'] ) );
			update_post_meta( $event_id, '_lnkup_power_available', true );
			update_post_meta( $event_id, '_lnkup_tent_allowed', ! empty( $sample['tent'] ) );
			update_post_meta( $event_id, '_lnkup_tags', $sample['tags'] ?? 'marketplace, showcase' );

			wp_set_object_terms( $event_id, $sample['type'], 'lnkup_event_type' );
			if ( ! empty( $sample['market'] ) ) {
				wp_set_object_terms( $event_id, $sample['market'], 'lnkup_market' );
			}

			$opp_title = $sample['event'] . ' — Vendor Opportunity';
			$opp_id    = wp_insert_post(
				array(
					'post_title'  => $opp_title,
					'post_status' => 'publish',
					'post_type'   => 'lnkup_opportunity',
				)
			);
			if ( ! $opp_id || is_wp_error( $opp_id ) ) {
				continue;
			}

			update_post_meta( $opp_id, '_lnkup_event_id', $event_id );
			update_post_meta( $opp_id, '_lnkup_spots_total', $sample['spots'] );
			update_post_meta( $opp_id, '_lnkup_spots_remaining', $sample['remaining'] );
			update_post_meta( $opp_id, '_lnkup_vendor_fee', $sample['fee'] );
			update_post_meta( $opp_id, '_lnkup_lnkup_fee', class_exists( 'LNKup_Fee_Engine' ) ? LNKup_Fee_Engine::resolve_vendor_checkout_fee_from_settings() : (float) ( $settings['vendor_checkout_fee'] ?? 5.99 ) );
			update_post_meta( $opp_id, '_lnkup_application_deadline', gmdate( 'Y-m-d', strtotime( $sample['date'] . ' -21 days' ) ) );
			update_post_meta( $opp_id, '_lnkup_opportunity_status', 'open' );
			if ( ! empty( $sample['docs'] ) ) {
				update_post_meta( $opp_id, '_lnkup_required_documents', $sample['docs'] );
			}

			wp_set_object_terms( $opp_id, $sample['categories'], 'lnkup_vendor_category' );
			if ( ! empty( $sample['market'] ) ) {
				wp_set_object_terms( $opp_id, $sample['market'], 'lnkup_market' );
			}

			LNKup_Marketplace_Index::sync_opportunity( $opp_id );
			++$seeded;
		}

		if ( $seeded > 0 ) {
			update_option( 'lnkup_marketplace_showcase_seeded', true );
		}
	}

	/**
	 * Get or create demo organizer user.
	 */
	private static function get_or_create_demo_organizer() {
		$user = get_user_by( 'login', 'lnkup_demo_organizer' );
		if ( $user ) {
			return $user->ID;
		}

		$id = wp_insert_user(
			array(
				'user_login'   => 'lnkup_demo_organizer',
				'user_pass'    => wp_generate_password(),
				'user_email'   => 'organizer@example.com',
				'display_name' => 'Demo Organizer',
				'role'         => 'lnkup_organizer',
			)
		);

		if ( is_wp_error( $id ) ) {
			$fallback = get_current_user_id();
			return $fallback ? $fallback : 1;
		}

		return $id;
	}
}
