<?php
/**
 * Admin Intelligence Center.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Intelligence_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 27 );
	}

	public static function add_menu() {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Intelligence Center', 'lnkup-marketplace' ),
			__( 'Intelligence', 'lnkup-marketplace' ),
			'lnkup_manage_all',
			'lnkup-intelligence',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! LNKup_Capabilities::can_view_market_intelligence() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		$health   = LNKup_Revenue_Intelligence::marketplace_summary();
		$events   = get_posts( array( 'post_type' => 'lnkup_event', 'posts_per_page' => 50, 'fields' => 'ids', 'post_status' => 'publish' ) );
		$board    = array();
		foreach ( $events as $event_id ) {
			$sc = LNKup_Event_Score::compute( $event_id );
			$board[] = array(
				'title' => get_the_title( $event_id ),
				'score' => $sc['score'],
				'label' => $sc['label'],
				'city'  => get_post_meta( $event_id, '_lnkup_city', true ),
			);
		}
		usort( $board, function ( $a, $b ) { return $b['score'] <=> $a['score']; } );
		$board = array_slice( $board, 0, 10 );
		$cities   = LNKup_Market_Trends::cities( array( 'limit' => 6 ) );
		$cats     = LNKup_Market_Trends::categories( array( 'limit' => 6 ) );
		$analytics = LNKup_Marketplace_Analytics::get_dashboard_metrics();
		?>
		<div class="wrap lnkup-intel-admin">
			<h1><?php esc_html_e( 'Marketplace Intelligence Center', 'lnkup-marketplace' ); ?></h1>
			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin:1.5rem 0;">
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( lnkup_currency( $health['gmv'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'GMV', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( lnkup_currency( $health['lnkup_fees'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'LNKup Fees', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) ( $health['paid_bookings'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'Paid Bookings', 'lnkup-marketplace' ); ?></div>
				<div class="card" style="padding:1rem;"><strong><?php echo esc_html( (string) ( $analytics['searches_30d'] ?? 0 ) ); ?></strong><br><?php esc_html_e( 'Searches (30d)', 'lnkup-marketplace' ); ?></div>
			</div>
			<h2><?php esc_html_e( 'Event Performance Leaderboard', 'lnkup-marketplace' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Score', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $board as $row ) : ?>
					<tr><td><?php echo esc_html( $row['title'] ); ?></td><td><?php echo esc_html( (string) $row['score'] ); ?> — <?php echo esc_html( $row['label'] ); ?></td><td><?php echo esc_html( $row['city'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<h2><?php esc_html_e( 'Top Cities', 'lnkup-marketplace' ); ?></h2>
			<ul><?php foreach ( $cities as $c ) : ?><li><?php echo esc_html( $c['label'] . ' — ' . $c['applications'] . ' applications' ); ?></li><?php endforeach; ?></ul>
			<h2><?php esc_html_e( 'Category Trends', 'lnkup-marketplace' ); ?></h2>
			<ul><?php foreach ( $cats as $cat ) : ?><li><?php echo esc_html( $cat['category'] . ' (' . $cat['fill_rate'] . '% fill)' ); ?><?php echo $cat['insight'] ? ' — ' . esc_html( $cat['insight'] ) : ''; ?></li><?php endforeach; ?></ul>
		</div>
		<?php
	}
}
