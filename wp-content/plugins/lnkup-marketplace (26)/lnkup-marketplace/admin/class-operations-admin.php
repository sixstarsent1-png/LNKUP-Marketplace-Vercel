<?php
/**
 * Admin operations dashboard.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Operations_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 26 );
	}

	public static function add_menu() {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Operations', 'lnkup-marketplace' ),
			__( 'Operations', 'lnkup-marketplace' ),
			'lnkup_manage_all',
			'lnkup-operations',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! LNKup_Capabilities::admin_can_view_ops() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$health = LNKup_Ops_Analytics::platform_health();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Operations Health', 'lnkup-marketplace' ); ?></h1>
			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin:1.5rem 0;">
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) $health['events_today'] ); ?></strong><br><?php esc_html_e( 'Events Today', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) $health['open_support'] ); ?></strong><br><?php esc_html_e( 'Open Support', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) $health['open_incidents'] ); ?></strong><br><?php esc_html_e( 'Open Incidents', 'lnkup-marketplace' ); ?></div>
			</div>
		</div>
		<?php
	}
}
