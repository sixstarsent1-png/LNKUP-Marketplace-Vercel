<?php
/**
 * Admin Organizer CRM moderation.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_CRM_Admin {

	/**
	 * Init admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
	}

	/**
	 * Add submenu.
	 */
	public static function add_menu() {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Organizer CRM', 'lnkup-marketplace' ),
			__( 'Organizer CRM', 'lnkup-marketplace' ),
			'lnkup_manage_all',
			'lnkup-organizer-crm',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render moderation page.
	 */
	public static function render_page() {
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$flags = LNKup_Vendor_Flags::get_admin_flags( 50 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Organizer CRM — Moderation', 'lnkup-marketplace' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Admin-visible risk flags and marketplace-wide vendor issues. Private organizer notes and lists remain scoped to each organizer.', 'lnkup-marketplace' ); ?></p>
			<h2><?php esc_html_e( 'Escalated Risk Flags', 'lnkup-marketplace' ); ?></h2>
			<?php if ( $flags ) : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Type', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Vendor', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Date', 'lnkup-marketplace' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $flags as $flag ) : ?>
						<tr>
							<td><?php echo esc_html( $flag['label'] ); ?></td>
							<td><?php echo esc_html( $flag['vendor_name'] ); ?></td>
							<td><?php echo esc_html( $flag['date'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No admin-visible flags yet.', 'lnkup-marketplace' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
