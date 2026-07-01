<?php
/**
 * Admin UI for city market landing pages.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Market_Pages_Admin {

	/**
	 * Init admin hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_lnkup_save_market_pages', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_lnkup_save_market_pages_toggle', array( __CLASS__, 'handle_toggle' ) );
		add_action( 'admin_post_lnkup_flush_market_rewrites', array( __CLASS__, 'handle_flush_rewrites' ) );
	}

	/**
	 * Add submenu page.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'City Market Pages', 'lnkup-marketplace' ),
			__( 'City Markets', 'lnkup-marketplace' ),
			'lnkup_manage_settings',
			'lnkup-city-markets',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render admin page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'lnkup_manage_settings' ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}

		$settings = lnkup_get_settings();
		$markets  = LNKup_Market_Pages::get_all_markets();
		$edit     = isset( $_GET['market'] ) ? sanitize_title( wp_unslash( (string) $_GET['market'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing  = $edit && ! empty( $markets[ $edit ] ) ? LNKup_Market_Pages::get_market_by_slug( $edit, false ) : null;
		$categories = get_terms( array( 'taxonomy' => 'lnkup_vendor_category', 'hide_empty' => false ) );
		$event_types = get_terms( array( 'taxonomy' => 'lnkup_event_type', 'hide_empty' => false, 'parent' => 0 ) );
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}
		if ( is_wp_error( $event_types ) ) {
			$event_types = array();
		}

		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'City markets saved.', 'lnkup-marketplace' ) . '</p></div>';
		}
		if ( isset( $_GET['flushed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rewrite rules flushed.', 'lnkup-marketplace' ) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'City Market Pages', 'lnkup-marketplace' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Configure scalable /markets/{city-slug}/ landing pages. Stats on the front end use real index data only.', 'lnkup-marketplace' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1rem 0;">
				<?php wp_nonce_field( 'lnkup_save_market_pages_toggle' ); ?>
				<input type="hidden" name="action" value="lnkup_save_market_pages_toggle" />
				<label>
					<input type="checkbox" name="market_pages_enabled" value="1" <?php checked( ! isset( $settings['market_pages_enabled'] ) || ! empty( $settings['market_pages_enabled'] ) ); ?> />
					<?php esc_html_e( 'Enable city market landing pages', 'lnkup-marketplace' ); ?>
				</label>
				<?php submit_button( __( 'Save global toggle', 'lnkup-marketplace' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:0.5rem;">
				<?php wp_nonce_field( 'lnkup_flush_market_rewrites' ); ?>
				<input type="hidden" name="action" value="lnkup_flush_market_rewrites" />
				<?php submit_button( __( 'Flush rewrite rules', 'lnkup-marketplace' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( $editing ) : ?>
				<h2><?php echo esc_html( sprintf( __( 'Edit %s', 'lnkup-marketplace' ), $editing['display_name'] ) ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'lnkup_save_market_pages' ); ?>
					<input type="hidden" name="action" value="lnkup_save_market_pages" />
					<input type="hidden" name="market_slug" value="<?php echo esc_attr( $editing['slug'] ); ?>" />
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Launch status', 'lnkup-marketplace' ); ?></th>
							<td>
								<select name="launch_status">
									<?php foreach ( array( 'prelaunch', 'recruiting_vendors', 'recruiting_organizers', 'beta', 'active', 'paused' ) as $status ) : ?>
										<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $editing['launch_status'], $status ); ?>><?php echo esc_html( LNKup_Market_Pages::status_label( $status ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Vendor goal', 'lnkup-marketplace' ); ?></th>
							<td><input type="number" min="0" name="vendor_goal" value="<?php echo esc_attr( (string) $editing['vendor_goal'] ); ?>" class="small-text" /></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Organizer goal', 'lnkup-marketplace' ); ?></th>
							<td><input type="number" min="0" name="organizer_goal" value="<?php echo esc_attr( (string) $editing['organizer_goal'] ); ?>" class="small-text" /></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Enabled', 'lnkup-marketplace' ); ?></th>
							<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $editing['enabled'] ); ?> /> <?php esc_html_e( 'Market page is live', 'lnkup-marketplace' ); ?></label></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Indexable', 'lnkup-marketplace' ); ?></th>
							<td><label><input type="checkbox" name="indexable" value="1" <?php checked( $editing['indexable'] ); ?> /> <?php esc_html_e( 'Allow search engines to index', 'lnkup-marketplace' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="seo_title"><?php esc_html_e( 'SEO title', 'lnkup-marketplace' ); ?></label></th>
							<td><input type="text" class="large-text" id="seo_title" name="seo_title" value="<?php echo esc_attr( (string) ( $editing['seo_title'] ?? '' ) ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="seo_description"><?php esc_html_e( 'SEO description', 'lnkup-marketplace' ); ?></label></th>
							<td><textarea class="large-text" rows="3" id="seo_description" name="seo_description"><?php echo esc_textarea( (string) ( $editing['seo_description'] ?? '' ) ); ?></textarea></td>
						</tr>
						<tr>
							<th><label for="hero_headline"><?php esc_html_e( 'Hero headline override', 'lnkup-marketplace' ); ?></label></th>
							<td><input type="text" class="large-text" id="hero_headline" name="hero_headline" value="<?php echo esc_attr( (string) ( $editing['hero_headline'] ?? '' ) ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="hero_description"><?php esc_html_e( 'Hero description override', 'lnkup-marketplace' ); ?></label></th>
							<td><textarea class="large-text" rows="3" id="hero_description" name="hero_description"><?php echo esc_textarea( (string) ( $editing['hero_description'] ?? '' ) ); ?></textarea></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Featured categories', 'lnkup-marketplace' ); ?></th>
							<td>
								<select name="featured_category_slugs[]" multiple size="8" style="min-width:240px;">
									<?php foreach ( $categories as $term ) : ?>
										<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( in_array( $term->slug, (array) ( $editing['featured_category_slugs'] ?? array() ), true ) ); ?>><?php echo esc_html( $term->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Featured event types', 'lnkup-marketplace' ); ?></th>
							<td>
								<select name="featured_event_type_slugs[]" multiple size="8" style="min-width:240px;">
									<?php foreach ( $event_types as $term ) : ?>
										<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( in_array( $term->slug, (array) ( $editing['featured_event_type_slugs'] ?? array() ), true ) ); ?>><?php echo esc_html( $term->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save market', 'lnkup-marketplace' ) ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-city-markets' ) ); ?>"><?php esc_html_e( 'Back to list', 'lnkup-marketplace' ); ?></a>
				</form>
			<?php else : ?>
				<h2><?php esc_html_e( 'Markets', 'lnkup-marketplace' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Enabled', 'lnkup-marketplace' ); ?></th>
							<th><?php esc_html_e( 'URL', 'lnkup-marketplace' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $markets as $row ) : ?>
							<?php $norm = LNKup_Market_Pages::get_market_by_slug( $row['slug'], false ); ?>
							<?php if ( ! $norm ) { continue; } ?>
							<tr>
								<td><?php echo esc_html( $norm['display_name'] . ', ' . $norm['state'] ); ?></td>
								<td><?php echo esc_html( LNKup_Market_Pages::status_label( $norm['launch_status'] ) ); ?></td>
								<td><?php echo $norm['enabled'] ? esc_html__( 'Yes', 'lnkup-marketplace' ) : esc_html__( 'No', 'lnkup-marketplace' ); ?></td>
								<td><a href="<?php echo esc_url( $norm['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $norm['url'] ); ?></a></td>
								<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-city-markets&market=' . rawurlencode( $norm['slug'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'lnkup-marketplace' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save single market from admin form.
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( 'lnkup_manage_settings' ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		check_admin_referer( 'lnkup_save_market_pages' );

		$slug = sanitize_title( (string) ( $_POST['market_slug'] ?? '' ) );
		$markets = LNKup_Market_Pages::get_all_markets();
		if ( ! $slug || empty( $markets[ $slug ] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=lnkup-city-markets' ) );
			exit;
		}

		$status = sanitize_key( (string) ( $_POST['launch_status'] ?? 'prelaunch' ) );
		if ( ! in_array( $status, array( 'prelaunch', 'recruiting_vendors', 'recruiting_organizers', 'beta', 'active', 'paused' ), true ) ) {
			$status = 'prelaunch';
		}

		$markets[ $slug ]['launch_status']             = $status;
		$markets[ $slug ]['vendor_goal']               = max( 0, (int) ( $_POST['vendor_goal'] ?? 0 ) );
		$markets[ $slug ]['organizer_goal']            = max( 0, (int) ( $_POST['organizer_goal'] ?? 0 ) );
		$markets[ $slug ]['enabled']                   = ! empty( $_POST['enabled'] );
		$markets[ $slug ]['indexable']                 = ! empty( $_POST['indexable'] );
		$markets[ $slug ]['seo_title']                 = sanitize_text_field( (string) ( $_POST['seo_title'] ?? '' ) );
		$markets[ $slug ]['seo_description']           = sanitize_textarea_field( (string) ( $_POST['seo_description'] ?? '' ) );
		$markets[ $slug ]['hero_headline']             = sanitize_text_field( (string) ( $_POST['hero_headline'] ?? '' ) );
		$markets[ $slug ]['hero_description']          = sanitize_textarea_field( (string) ( $_POST['hero_description'] ?? '' ) );
		$markets[ $slug ]['featured_category_slugs']   = array_map( 'sanitize_title', (array) ( $_POST['featured_category_slugs'] ?? array() ) );
		$markets[ $slug ]['featured_event_type_slugs'] = array_map( 'sanitize_title', (array) ( $_POST['featured_event_type_slugs'] ?? array() ) );

		LNKup_Market_Pages::save_markets( $markets );
		flush_rewrite_rules( false );

		wp_safe_redirect( admin_url( 'admin.php?page=lnkup-city-markets&market=' . rawurlencode( $slug ) . '&updated=1' ) );
		exit;
	}

	/**
	 * Save global market pages toggle.
	 */
	public static function handle_toggle(): void {
		if ( ! current_user_can( 'lnkup_manage_settings' ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		check_admin_referer( 'lnkup_save_market_pages_toggle' );
		$settings = lnkup_get_settings();
		$settings['market_pages_enabled'] = ! empty( $_POST['market_pages_enabled'] );
		update_option( 'lnkup_settings', $settings );
		flush_rewrite_rules( false );
		wp_safe_redirect( admin_url( 'admin.php?page=lnkup-city-markets&updated=1' ) );
		exit;
	}

	/**
	 * Flush rewrite rules from admin.
	 */
	public static function handle_flush_rewrites(): void {
		if ( ! current_user_can( 'lnkup_manage_settings' ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		check_admin_referer( 'lnkup_flush_market_rewrites' );
		LNKup_Market_Pages::register_rewrite_rules();
		flush_rewrite_rules( false );
		wp_safe_redirect( admin_url( 'admin.php?page=lnkup-city-markets&flushed=1' ) );
		exit;
	}
}
