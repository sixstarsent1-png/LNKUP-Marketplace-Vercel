<?php
/**
 * wp-admin "Operations queue": the exceptions LNKup staff should look at today, each linking to
 * the screen where the decision is made.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Ops_Admin {

	const SLUG = 'lnkup-ops';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 12 );
		add_action( 'admin_post_lnkup_ops_state', array( __CLASS__, 'handle_state' ) );
	}

	public static function add_menu(): void {
		add_submenu_page( 'lnkup-marketplace', __( 'Operations queue', 'lnkup-marketplace' ), __( 'Operations queue', 'lnkup-marketplace' ), 'lnkup_manage_all', self::SLUG, array( __CLASS__, 'render_page' ) );
	}

	public static function handle_state(): void {
		check_admin_referer( 'lnkup_ops_state', 'lnkup_nonce' );
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$result = LNKup_Ops_Overview::set_state(
			sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) ),
			sanitize_key( wp_unslash( $_POST['state'] ?? '' ) ),
			absint( $_POST['hours'] ?? 24 ),
			sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ),
			get_current_user_id()
		);
		$msg = is_wp_error( $result ) ? $result->get_error_message() : __( 'Queue updated.', 'lnkup-marketplace' );
		wp_safe_redirect( add_query_arg( 'lnkup_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	public static function render_page(): void {
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$show_hidden = ! empty( $_GET['hidden'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$queue       = LNKup_Ops_Overview::queue( $show_hidden );
		$msg         = isset( $_GET['lnkup_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['lnkup_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$labels      = array( 'critical' => __( 'Critical', 'lnkup-marketplace' ), 'high' => __( 'High', 'lnkup-marketplace' ), 'medium' => __( 'Medium', 'lnkup-marketplace' ), 'low' => __( 'Low', 'lnkup-marketplace' ) );
		?>
		<div class="wrap" data-testid="lnkup-admin-ops">
			<h1><?php esc_html_e( 'Operations queue', 'lnkup-marketplace' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Exceptions across applications, payments, events, trust, messages, matching and partners. Each item links to the screen where you decide; dismissing hides it until it changes.', 'lnkup-marketplace' ); ?></p>
			<?php if ( $msg ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>
			<p>
				<?php foreach ( $queue['counts'] as $severity => $n ) : ?>
					<strong><?php echo esc_html( $labels[ $severity ] ); ?>:</strong> <?php echo esc_html( (string) $n ); ?> &nbsp;
				<?php endforeach; ?>
				<?php if ( $queue['hidden'] ) : ?>
					· <a href="<?php echo esc_url( add_query_arg( 'hidden', $show_hidden ? '' : '1' ) ); ?>"><?php echo esc_html( $show_hidden ? __( 'Hide dismissed and snoozed', 'lnkup-marketplace' ) : sprintf( /* translators: %d: count */ __( 'Show %d dismissed or snoozed', 'lnkup-marketplace' ), $queue['hidden'] ) ); ?></a>
				<?php endif; ?>
			</p>
			<?php if ( ! $queue['items'] ) : ?>
				<p data-testid="lnkup-ops-empty"><?php esc_html_e( 'Nothing needs attention right now.', 'lnkup-marketplace' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" data-testid="lnkup-ops-table">
					<thead><tr><th><?php esc_html_e( 'Severity', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'What happened', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Age', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Owner', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Next step', 'lnkup-marketplace' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $queue['items'] as $item ) : ?>
						<tr data-ops-key="<?php echo esc_attr( $item['key'] ); ?>">
							<td><?php echo esc_html( $labels[ $item['severity'] ] ?? $item['severity'] ); ?><?php echo $item['state'] ? ' · ' . esc_html( $item['state'] ) : ''; ?></td>
							<td><strong><?php echo esc_html( $item['title'] ); ?></strong><?php if ( '' !== $item['detail'] ) : ?><br><span class="description"><?php echo esc_html( $item['detail'] ); ?></span><?php endif; ?></td>
							<td><?php echo esc_html( $item['age_hours'] >= 48 ? sprintf( /* translators: %d: days */ __( '%d days', 'lnkup-marketplace' ), (int) floor( $item['age_hours'] / 24 ) ) : ( $item['age_hours'] > 0 ? sprintf( /* translators: %d: hours */ __( '%d hours', 'lnkup-marketplace' ), (int) ceil( $item['age_hours'] ) ) : '—' ) ); ?></td>
							<td><?php echo esc_html( $item['owner']['name'] ?? '—' ); ?></td>
							<td><?php if ( ! empty( $item['action']['url'] ) ) : ?><a class="button" href="<?php echo esc_url( $item['action']['url'] ); ?>"><?php echo esc_html( $item['action']['label'] ); ?></a><?php endif; ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:4px">
									<?php wp_nonce_field( 'lnkup_ops_state', 'lnkup_nonce' ); ?>
									<input type="hidden" name="action" value="lnkup_ops_state" />
									<input type="hidden" name="key" value="<?php echo esc_attr( $item['key'] ); ?>" />
									<?php if ( $item['state'] ) : ?>
										<button class="button-link" name="state" value="clear"><?php esc_html_e( 'Restore', 'lnkup-marketplace' ); ?></button>
									<?php else : ?>
										<button class="button-link" name="state" value="snoozed"><?php esc_html_e( 'Snooze a day', 'lnkup-marketplace' ); ?></button>
										<button class="button-link" name="state" value="dismissed"><?php esc_html_e( 'Dismiss until it changes', 'lnkup-marketplace' ); ?></button>
									<?php endif; ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
