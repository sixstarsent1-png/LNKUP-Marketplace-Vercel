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
		add_action( 'admin_post_lnkup_apply_market_suggestion', array( __CLASS__, 'handle_apply_suggestion' ) );
		add_action( 'admin_post_lnkup_create_market', array( __CLASS__, 'handle_create' ) );
	}

	private static function can_manage(): bool {
		return current_user_can( 'lnkup_manage_settings' ) || LNKup_Capabilities::user_has_manage_all();
	}

	/**
	 * Whether the matching market snapshot is available for suggestions.
	 */
	private static function has_signals(): bool {
		return class_exists( 'LNKup_Match_Market' ) && LNKup_Match_Tables::exists( LNKup_Match_Market::table() );
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
		if ( isset( $_GET['applied'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Suggested status applied and logged.', 'lnkup-marketplace' ) . '</p></div>';
		}
		if ( isset( $_GET['market_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['market_error'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$signals = self::has_signals();
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
						<?php if ( class_exists( 'LNKup_Market_Registry' ) ) : ?>
							<?php self::render_registry_fields( LNKup_Market_Registry::with_defaults( $editing ), $markets, $categories ); ?>
						<?php endif; ?>
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
							<?php if ( $signals ) : ?>
								<th><?php esc_html_e( 'Suggested status', 'lnkup-marketplace' ); ?></th>
							<?php endif; ?>
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
								<?php if ( $signals ) : ?>
									<td data-testid="market-suggestion">
										<?php $suggestion = LNKup_Match_Market::suggest_market_status( $norm['city'], $norm['state'] ); ?>
										<?php if ( ! $suggestion ) : ?>
											<span class="description"><?php esc_html_e( 'Not enough data', 'lnkup-marketplace' ); ?></span>
										<?php elseif ( $suggestion['status'] === $norm['launch_status'] ) : ?>
											<span class="description" title="<?php echo esc_attr( $suggestion['reason'] ); ?>"><?php esc_html_e( 'Matches current status', 'lnkup-marketplace' ); ?></span>
										<?php else : ?>
											<strong><?php echo esc_html( LNKup_Market_Pages::status_label( $suggestion['status'] ) ); ?></strong>
											<br><span class="description"><?php echo esc_html( $suggestion['reason'] ); ?></span>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:4px;">
												<?php wp_nonce_field( 'lnkup_apply_market_suggestion_' . $norm['slug'] ); ?>
												<input type="hidden" name="action" value="lnkup_apply_market_suggestion" />
												<input type="hidden" name="market_slug" value="<?php echo esc_attr( $norm['slug'] ); ?>" />
												<?php submit_button( __( 'Apply', 'lnkup-marketplace' ), 'small', 'submit', false ); ?>
											</form>
										<?php endif; ?>
									</td>
								<?php endif; ?>
								<td><?php echo $norm['enabled'] ? esc_html__( 'Yes', 'lnkup-marketplace' ) : esc_html__( 'No', 'lnkup-marketplace' ); ?></td>
								<td><a href="<?php echo esc_url( $norm['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $norm['url'] ); ?></a></td>
								<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-city-markets&market=' . rawurlencode( $norm['slug'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'lnkup-marketplace' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $signals ) : ?>
					<p class="description"><?php esc_html_e( 'Suggestions come from the daily matching and vendor supply snapshots. A status only changes when you click Apply, and every change is logged.', 'lnkup-marketplace' ); ?></p>
					<?php self::render_status_log(); ?>
					<?php self::render_new_market_suggestions(); ?>
				<?php endif; ?>
				<?php self::render_create_form(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Marketing registry fields: location, nearby markets, status override, category pages, content.
	 *
	 * @param array<string,mixed>                $market     Market with registry defaults.
	 * @param array<string,array<string,mixed>> $markets    All markets.
	 * @param array<int,WP_Term>                 $categories Vendor category terms.
	 */
	private static function render_registry_fields( array $market, array $markets, array $categories ): void {
		$computed = LNKup_Market_Registry::status( array_merge( $market, array( 'status_override' => '' ) ) );
		$health   = LNKup_Market_Registry::health( $market );
		$labels   = function_exists( 'lnkup_canonical_category_options' ) ? array_keys( lnkup_canonical_category_options( $categories ) ) : wp_list_pluck( $categories, 'name' );
		$counts   = array();
		foreach ( LNKup_Market_Registry::categories( $market ) as $row ) {
			$counts[ $row['slug'] ] = $row['count'];
		}
		?>
		<tr>
			<th><?php esc_html_e( 'Market health (live)', 'lnkup-marketplace' ); ?></th>
			<td data-testid="market-health">
				<?php
				printf(
					/* translators: 1: open events, 2: open listings, 3: organizers, 4: vendors */
					esc_html__( '%1$d open events · %2$d open listings · %3$d organizers with open events · %4$d vendors based here', 'lnkup-marketplace' ),
					(int) $health['open_events'],
					(int) $health['open_opportunities'],
					(int) $health['active_organizers'],
					(int) $health['vendors_based']
				);
				?>
				<p class="description">
					<?php
					/* translators: %s: computed status */
					printf( esc_html__( 'Computed public status: %s', 'lnkup-marketplace' ), esc_html( LNKup_Market_Registry::status_label( $computed ) ) );
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th><label for="status_override"><?php esc_html_e( 'Public status override', 'lnkup-marketplace' ); ?></label></th>
			<td>
				<select id="status_override" name="status_override">
					<option value=""><?php esc_html_e( 'Automatic (recommended)', 'lnkup-marketplace' ); ?></option>
					<?php foreach ( LNKup_Market_Registry::STATUSES as $status ) : ?>
						<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $market['status_override'], $status ); ?>><?php echo esc_html( LNKup_Market_Registry::status_label( $status ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Coordinates', 'lnkup-marketplace' ); ?></th>
			<td>
				<label><?php esc_html_e( 'Lat', 'lnkup-marketplace' ); ?> <input type="text" name="lat" size="10" inputmode="decimal" value="<?php echo esc_attr( null === $market['lat'] ? '' : (string) $market['lat'] ); ?>" /></label>
				<label><?php esc_html_e( 'Lng', 'lnkup-marketplace' ); ?> <input type="text" name="lng" size="10" inputmode="decimal" value="<?php echo esc_attr( null === $market['lng'] ? '' : (string) $market['lng'] ); ?>" /></label>
				<p class="description"><?php esc_html_e( 'Used to list nearby markets within 300 miles. Leave blank to fall back to same-state markets.', 'lnkup-marketplace' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="metro"><?php esc_html_e( 'Metro area', 'lnkup-marketplace' ); ?></label></th>
			<td><input type="text" id="metro" name="metro" class="regular-text" value="<?php echo esc_attr( (string) $market['metro'] ); ?>" /></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Nearby markets', 'lnkup-marketplace' ); ?></th>
			<td>
				<select name="nearby[]" multiple size="6" style="min-width:240px;">
					<?php foreach ( $markets as $slug => $row ) : ?>
						<?php if ( $slug === $market['slug'] ) { continue; } ?>
						<option value="<?php echo esc_attr( (string) $slug ); ?>" <?php selected( in_array( (string) $slug, $market['nearby'], true ) ); ?>><?php echo esc_html( (string) ( $row['display_name'] ?? $slug ) . ', ' . (string) ( $row['state'] ?? '' ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'City + category pages', 'lnkup-marketplace' ); ?></th>
			<td data-testid="market-category-pages">
				<?php foreach ( $labels as $label ) : ?>
					<?php $slug = sanitize_title( (string) $label ); ?>
					<label style="display:inline-block;min-width:220px;">
						<input type="checkbox" name="category_pages[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $market['category_pages'], true ) ); ?> />
						<?php echo esc_html( (string) $label ); ?>
						<span class="description">(<?php echo esc_html( (string) ( $counts[ $slug ] ?? 0 ) ); ?>)</span>
					</label>
				<?php endforeach; ?>
				<p class="description">
					<?php
					/* translators: %d: minimum open events */
					printf( esc_html__( 'A page is indexable only while this market is indexable and the category has at least %d open events here. Counts in brackets are live open events.', 'lnkup-marketplace' ), (int) LNKup_Market_Registry::CATEGORY_MIN );
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th><label for="market_content"><?php esc_html_e( 'Local guide content', 'lnkup-marketplace' ); ?></label></th>
			<td>
				<textarea class="large-text" rows="8" id="market_content" name="market_content"><?php echo esc_textarea( (string) $market['content'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Original, city-specific guidance for vendors (permits, seasons, venues). Basic formatting allowed. 300+ characters lets a market with no open events stay indexable.', 'lnkup-marketplace' ); ?></p>
			</td>
		</tr>
		<?php
	}

	private static function render_status_log(): void {
		$log = LNKup_Match_Market::market_status_log( 10 );
		if ( ! $log ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Recent status changes', 'lnkup-marketplace' ) . '</h3><ul data-testid="market-status-log">';
		foreach ( $log as $entry ) {
			$user = get_userdata( (int) $entry['user_id'] );
			echo '<li>' . esc_html(
				sprintf(
					/* translators: 1: date, 2: market slug, 3: old status, 4: new status, 5: user, 6: reason. */
					__( '%1$s — %2$s: %3$s → %4$s by %5$s (%6$s)', 'lnkup-marketplace' ),
					$entry['at'],
					$entry['slug'],
					LNKup_Market_Pages::status_label( (string) $entry['from'] ),
					LNKup_Market_Pages::status_label( (string) $entry['to'] ),
					$user ? $user->display_name : __( 'unknown user', 'lnkup-marketplace' ),
					$entry['reason']
				)
			) . '</li>';
		}
		echo '</ul>';
	}

	private static function render_new_market_suggestions(): void {
		$rows = LNKup_Match_Market::suggested_new_markets( 10 );
		echo '<h2>' . esc_html__( 'Suggested new markets', 'lnkup-marketplace' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'No cities without a market page show vendor demand yet.', 'lnkup-marketplace' ) . '</p>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'Cities without a market page, ranked by vendors with a recommended booth there plus searches for the city that found nothing (30 days).', 'lnkup-marketplace' ) . '</p>';
		echo '<table class="widefat striped" data-testid="market-new-suggestions"><thead><tr><th>' . esc_html__( 'City', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Matching vendors', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Searches found nothing', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Signal', 'lnkup-marketplace' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$url = add_query_arg(
				array(
					'page'      => 'lnkup-city-markets',
					'new_city'  => $row['city'],
					'new_state' => $row['state'],
				),
				admin_url( 'admin.php' )
			) . '#lnkup-create-market';
			printf(
				'<tr><td>%s</td><td>%d</td><td>%d</td><td>%s</td><td><a href="%s">%s</a></td></tr>',
				esc_html( $row['label'] ),
				(int) $row['matched_vendors'],
				(int) $row['zero_result_searches_30d'],
				esc_html( $row['signal_label'] ),
				esc_url( $url ),
				esc_html__( 'Create market page', 'lnkup-marketplace' )
			);
		}
		echo '</tbody></table>';
	}

	private static function render_create_form(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$city  = isset( $_GET['new_city'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['new_city'] ) ) : '';
		$state = isset( $_GET['new_state'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_GET['new_state'] ) ) ) : '';
		// phpcs:enable
		?>
		<h2 id="lnkup-create-market"><?php esc_html_e( 'Add a market', 'lnkup-marketplace' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-testid="market-create-form">
			<?php wp_nonce_field( 'lnkup_create_market' ); ?>
			<input type="hidden" name="action" value="lnkup_create_market" />
			<label><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?> <input type="text" name="city" required value="<?php echo esc_attr( $city ); ?>" /></label>
			<label><?php esc_html_e( 'State', 'lnkup-marketplace' ); ?> <input type="text" name="state" required maxlength="2" size="3" value="<?php echo esc_attr( $state ); ?>" /></label>
			<?php submit_button( __( 'Create market page', 'lnkup-marketplace' ), 'secondary', 'submit', false ); ?>
			<p class="description"><?php esc_html_e( 'New markets start in Prelaunch and disabled, so you can review the page before it goes live.', 'lnkup-marketplace' ); ?></p>
		</form>
		<?php
	}

	/**
	 * Apply the suggested launch status (recomputed server-side) for one market.
	 */
	public static function handle_apply_suggestion(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$slug = sanitize_title( wp_unslash( (string) ( $_POST['market_slug'] ?? '' ) ) );
		check_admin_referer( 'lnkup_apply_market_suggestion_' . $slug );
		$result = self::has_signals() ? LNKup_Match_Market::apply_market_suggestion( $slug ) : new WP_Error( 'lnkup_market_no_signals', __( 'Market signals are not available yet.', 'lnkup-marketplace' ) );
		$args   = is_wp_error( $result ) ? array( 'market_error' => rawurlencode( $result->get_error_message() ) ) : array( 'applied' => 1 );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=lnkup-city-markets' ) ) );
		exit;
	}

	/**
	 * Create a market page (disabled, prelaunch) for a city.
	 */
	public static function handle_create(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		check_admin_referer( 'lnkup_create_market' );
		$result = self::create_market( wp_unslash( (string) ( $_POST['city'] ?? '' ) ), wp_unslash( (string) ( $_POST['state'] ?? '' ) ) );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'market_error', rawurlencode( $result->get_error_message() ), admin_url( 'admin.php?page=lnkup-city-markets' ) ) );
			exit;
		}
		flush_rewrite_rules( false );
		wp_safe_redirect( admin_url( 'admin.php?page=lnkup-city-markets&market=' . rawurlencode( $result ) . '&updated=1' ) );
		exit;
	}

	/**
	 * @return string|WP_Error New market slug.
	 */
	public static function create_market( string $city, string $state ) {
		$city  = sanitize_text_field( $city );
		$state = strtoupper( sanitize_text_field( $state ) );
		if ( '' === $city || ! preg_match( '/^[A-Z]{2}$/', $state ) ) {
			return new WP_Error( 'lnkup_market_invalid', __( 'Enter a city and a two-letter state.', 'lnkup-marketplace' ) );
		}
		$markets = LNKup_Market_Pages::get_all_markets();
		foreach ( $markets as $m ) {
			if ( 0 === strcasecmp( (string) ( $m['city'] ?? '' ), $city ) && strtoupper( (string) ( $m['state'] ?? '' ) ) === $state ) {
				return new WP_Error( 'lnkup_market_exists', __( 'That city already has a market page.', 'lnkup-marketplace' ) );
			}
		}
		$slug = sanitize_title( $city );
		if ( '' === $slug || isset( $markets[ $slug ] ) ) {
			$slug = sanitize_title( $city . '-' . $state );
		}
		if ( '' === $slug || isset( $markets[ $slug ] ) ) {
			return new WP_Error( 'lnkup_market_exists', __( 'A market with that address already exists.', 'lnkup-marketplace' ) );
		}
		$settings         = lnkup_get_settings();
		$markets[ $slug ] = array(
			'slug'                      => $slug,
			'city'                      => $city,
			'state'                     => $state,
			'display_name'              => $city,
			'region'                    => '',
			'launch_status'             => 'prelaunch',
			'vendor_goal'               => (int) ( $settings['launch_goal_vendors'] ?? 4000 ),
			'organizer_goal'            => (int) ( $settings['launch_goal_organizers'] ?? 500 ),
			'hero_headline'             => '',
			'hero_description'          => '',
			'featured_category_slugs'   => array(),
			'featured_event_type_slugs' => array(),
			'seo_title'                 => '',
			'seo_description'           => '',
			'og_image'                  => '',
			'hero_image'                => 'block-party.svg',
			'enabled'                   => false,
			'indexable'                 => true,
			'connector_cta_url'         => '',
		);
		LNKup_Market_Pages::save_markets( $markets );
		return $slug;
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
		if ( class_exists( 'LNKup_Market_Registry' ) && isset( $_POST['market_content'] ) ) {
			$lat = trim( (string) wp_unslash( $_POST['lat'] ?? '' ) );
			$lng = trim( (string) wp_unslash( $_POST['lng'] ?? '' ) );
			$override = sanitize_key( (string) ( $_POST['status_override'] ?? '' ) );
			$markets[ $slug ]['lat']             = is_numeric( $lat ) && abs( (float) $lat ) <= 90 ? (float) $lat : null;
			$markets[ $slug ]['lng']             = is_numeric( $lng ) && abs( (float) $lng ) <= 180 ? (float) $lng : null;
			$markets[ $slug ]['metro']           = sanitize_text_field( wp_unslash( (string) ( $_POST['metro'] ?? '' ) ) );
			$markets[ $slug ]['nearby']          = array_values( array_intersect( array_map( 'sanitize_title', (array) ( $_POST['nearby'] ?? array() ) ), array_diff( array_keys( $markets ), array( $slug ) ) ) );
			$markets[ $slug ]['category_pages']  = array_values( array_unique( array_map( 'sanitize_title', (array) ( $_POST['category_pages'] ?? array() ) ) ) );
			$markets[ $slug ]['content']         = wp_kses_post( wp_unslash( (string) $_POST['market_content'] ) );
			$markets[ $slug ]['status_override'] = in_array( $override, LNKup_Market_Registry::STATUSES, true ) ? $override : '';
		}

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
