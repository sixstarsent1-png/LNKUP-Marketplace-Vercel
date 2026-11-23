<?php
/**
 * wp-admin → LNKup → Organizer Growth (Organizer Acquisition OS).
 *
 * The page is a shell; organizer-growth.js renders each tab from lnkup/v1/growth/*.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_Growth_Admin {

	const SLUG = 'lnkup-organizer-growth';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 31 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Organizer Growth', 'lnkup-marketplace' ),
			__( 'Organizer Growth', 'lnkup-marketplace' ),
			LNKup_Org_Growth::CAP,
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		$ver = defined( 'LNKUP_VERSION' ) ? LNKUP_VERSION : '1';
		wp_enqueue_style( 'lnkup-organizer-growth', LNKUP_PLUGIN_URL . 'admin/css/organizer-growth.css', array(), $ver );
		wp_enqueue_script( 'lnkup-organizer-growth', LNKUP_PLUGIN_URL . 'admin/js/organizer-growth.js', array(), $ver, true );
		wp_localize_script(
			'lnkup-organizer-growth',
			'lnkupGrowth',
			array(
				'root'  => esc_url_raw( rest_url( 'lnkup/v1/growth' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public static function render_page(): void {
		if ( ! LNKup_Org_Growth::current_user_can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$tabs = array(
			'overview' => __( 'Overview', 'lnkup-marketplace' ),
			'leads'    => __( 'Organizer Leads', 'lnkup-marketplace' ),
			'pipeline' => __( 'Acquisition Pipeline', 'lnkup-marketplace' ),
			'tasks'    => __( 'Follow-ups', 'lnkup-marketplace' ),
			'outreach' => __( 'Outreach Campaigns', 'lnkup-marketplace' ),
			'analytics' => __( 'Analytics', 'lnkup-marketplace' ),
			'spend'    => __( 'Spend', 'lnkup-marketplace' ),
			'supply'   => __( 'Vendor Supply', 'lnkup-marketplace' ),
			'import'   => __( 'Import', 'lnkup-marketplace' ),
			'settings' => __( 'Settings', 'lnkup-marketplace' ),
		);
		?>
		<div class="wrap lnkup-og" id="lnkup-og-app" data-testid="organizer-growth">
			<h1><?php esc_html_e( 'Organizer Growth', 'lnkup-marketplace' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Find, contact and activate independent event organizers. Every number comes from recorded leads, activity and real marketplace bookings.', 'lnkup-marketplace' ); ?></p>
			<div class="lnkup-og-flag" data-og-flag hidden></div>
			<nav class="nav-tab-wrapper lnkup-og-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Organizer Growth sections', 'lnkup-marketplace' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="#<?php echo esc_attr( $key ); ?>" class="nav-tab" role="tab" data-og-tab="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php foreach ( array_keys( $tabs ) as $key ) : ?>
				<section class="lnkup-og-panel" role="tabpanel" data-og-panel="<?php echo esc_attr( $key ); ?>" data-testid="og-panel-<?php echo esc_attr( $key ); ?>" hidden>
					<div class="lnkup-og-loading" aria-live="polite"><?php esc_html_e( 'Loading…', 'lnkup-marketplace' ); ?></div>
				</section>
			<?php endforeach; ?>
			<aside class="lnkup-og-drawer" data-og-drawer hidden aria-label="<?php esc_attr_e( 'Lead details', 'lnkup-marketplace' ); ?>" data-testid="og-drawer"></aside>
			<div class="lnkup-og-toast" data-og-toast role="status" aria-live="polite" hidden></div>
		</div>
		<?php
	}
}
