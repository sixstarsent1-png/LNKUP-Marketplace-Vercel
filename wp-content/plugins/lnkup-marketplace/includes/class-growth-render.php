<?php
/**
 * Vendor growth dashboard UI fragments.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Growth_Render {

	public static function vendor_shell() {
		ob_start();
		?>
		<div class="lnkup-growth-shell" id="lnkup-vendor-growth" data-role="vendor">
			<div class="lnkup-growth-kpi-strip lnkup-growth-shell__kpi-strip" id="lnkup-growth-kpis">
				<div class="lnkup-growth-skeleton lnkup-growth-skeleton--kpi"></div>
				<div class="lnkup-growth-skeleton lnkup-growth-skeleton--kpi"></div>
				<div class="lnkup-growth-skeleton lnkup-growth-skeleton--kpi"></div>
				<div class="lnkup-growth-skeleton lnkup-growth-skeleton--kpi"></div>
			</div>
			<div class="lnkup-growth-tabs-wrap lnkup-filter-bar--sticky">
				<div class="lnkup-growth-tabs lnkup-dash-tabs lnkup-dash-tabs--underline" role="tablist" aria-label="<?php esc_attr_e( 'Growth sections', 'lnkup-marketplace' ); ?>">
					<button type="button" class="lnkup-dash-tabs__btn lnkup-growth-tab is-active" data-tab="overview"><?php esc_html_e( 'Overview', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-dash-tabs__btn lnkup-growth-tab" data-tab="revenue"><?php esc_html_e( 'Revenue', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-dash-tabs__btn lnkup-growth-tab" data-tab="markets"><?php esc_html_e( 'Markets', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-dash-tabs__btn lnkup-growth-tab" data-tab="profile-exports"><?php esc_html_e( 'Profile & exports', 'lnkup-marketplace' ); ?></button>
				</div>
			</div>
			<div class="lnkup-growth-panels">
				<div class="lnkup-growth-panel is-active" data-panel="overview" id="lnkup-growth-overview"></div>
				<div class="lnkup-growth-panel" data-panel="revenue" id="lnkup-growth-revenue"></div>
				<div class="lnkup-growth-panel" data-panel="markets" id="lnkup-growth-markets"></div>
				<div class="lnkup-growth-panel" data-panel="profile-exports" id="lnkup-growth-profile-exports"></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function progress_ring( $pct, $label ) {
		$pct = min( 100, max( 0, (int) $pct ) );
		ob_start();
		?>
		<div class="lnkup-growth-ring" style="--pct: <?php echo esc_attr( (string) $pct ); ?>">
			<span class="lnkup-growth-ring__value"><?php echo esc_html( (string) $pct ); ?>%</span>
			<span class="lnkup-growth-ring__label"><?php echo esc_html( $label ); ?></span>
		</div>
		<?php
		return ob_get_clean();
	}
}
