<?php
/**
 * Admin map tools and marketplace city map.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Map_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 28 );
		add_action( 'admin_post_lnkup_geocode_backfill', array( __CLASS__, 'handle_backfill' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Maps', 'lnkup-marketplace' ),
			__( 'Maps', 'lnkup-marketplace' ),
			'lnkup_manage_all',
			'lnkup-maps',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_backfill() {
		if ( ! current_user_can( 'lnkup_manage_all' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		check_admin_referer( 'lnkup_geocode_backfill' );
		$count = LNKup_Geocoder::backfill_events( 50 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'lnkup-maps', 'geocoded' => $count ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page() {
		if ( ! LNKup_Capabilities::can_view_market_map() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$missing = LNKup_Geocoder::events_missing_coords( 20 );
		$cities  = LNKup_Map_Insights::city_aggregates();
		$insights = LNKup_Map_Insights::marketplace_cities();
		$geocoded = isset( $_GET['geocoded'] ) ? absint( $_GET['geocoded'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap lnkup-map-admin">
			<h1><?php esc_html_e( 'Marketplace Maps', 'lnkup-marketplace' ); ?></h1>
			<?php if ( $geocoded ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( sprintf( __( 'Geocoded %d events.', 'lnkup-marketplace' ), $geocoded ) ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Geocode Health', 'lnkup-marketplace' ); ?></h2>
			<p><?php echo esc_html( sprintf( __( '%d events missing coordinates (showing up to 20).', 'lnkup-marketplace' ), count( $missing ) ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'lnkup_geocode_backfill' ); ?>
				<input type="hidden" name="action" value="lnkup_geocode_backfill" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Backfill Geocoding', 'lnkup-marketplace' ); ?></button>
			</form>
			<?php if ( $missing ) : ?>
				<ul>
					<?php foreach ( $missing as $event ) : ?>
						<li><?php echo esc_html( $event->post_title ); ?> — <?php echo esc_html( get_post_meta( $event->ID, '_lnkup_geocode_status', true ) ?: 'missing' ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2><?php esc_html_e( 'City Activity Map Data', 'lnkup-marketplace' ); ?></h2>
			<div id="lnkup-admin-city-map" class="lnkup-map-admin__city" data-rest="<?php echo esc_url( rest_url( 'lnkup/v1/maps/marketplace/cities' ) ); ?>"></div>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Opportunities', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Applications', 'lnkup-marketplace' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $cities, 0, 15 ) as $city ) : ?>
					<tr>
						<td><?php echo esc_html( $city['label'] ); ?></td>
						<td><?php echo esc_html( (string) $city['opportunities'] ); ?></td>
						<td><?php echo esc_html( (string) $city['applications'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Location Insights', 'lnkup-marketplace' ); ?></h2>
			<ul>
				<?php foreach ( $insights as $item ) : ?>
					<li><strong><?php echo esc_html( $item['city'] ); ?>:</strong> <?php echo esc_html( $item['insight'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}
