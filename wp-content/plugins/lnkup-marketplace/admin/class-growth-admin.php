<?php
/**
 * Admin Growth Center.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Growth_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 30 );
	}

	public static function add_menu() {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Growth Center', 'lnkup-marketplace' ),
			__( 'Growth', 'lnkup-marketplace' ),
			'lnkup_manage_marketplace_growth',
			'lnkup-growth',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! LNKup_Capabilities::can_manage_marketplace_growth() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$summary = LNKup_Growth::admin_summary();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Marketplace Growth Center', 'lnkup-marketplace' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Marketplace-level aggregates only. Individual vendor financial data is never shown here.', 'lnkup-marketplace' ); ?></p>
			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;margin:1.5rem 0;">
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) ( $summary['active_vendors'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'Active Vendors', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) ( $summary['avg_profile_score'] ?? 0 ) ); ?>%</strong><br><?php esc_html_e( 'Avg Profile Strength', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) ( $summary['goals_in_progress'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'Goals In Progress', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) ( $summary['recommendations'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'Active Recommendations', 'lnkup-marketplace' ); ?></div>
			</div>
		</div>
		<?php
	}
}
