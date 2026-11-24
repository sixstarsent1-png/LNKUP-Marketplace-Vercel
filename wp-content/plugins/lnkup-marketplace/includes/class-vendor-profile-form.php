<?php
/**
 * Sectioned vendor profile editor.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Profile_Form {

	/**
	 * Render full profile resume builder.
	 */
	public static function render(): string {
		LNKup_Capabilities::require_login();
		if ( ! LNKup_Roles::is_vendor() && ! LNKup_Capabilities::user_has_manage_all() ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html__( 'Vendor access required.', 'lnkup-marketplace' ) . '</p>';
		}

		$user_id    = get_current_user_id();
		$profile_id = lnkup_get_vendor_profile_id( $user_id );
		$profile    = $profile_id ? get_post( $profile_id ) : null;
		$strength   = class_exists( 'LNKup_Growth_Profile_Strength' )
			? LNKup_Growth_Profile_Strength::calculate( (int) $profile_id )
			: array();
		$terms      = get_terms( array( 'taxonomy' => 'lnkup_vendor_category', 'hide_empty' => false ) );
		$meta       = self::meta_values( $profile_id, $profile );
		$slug       = $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_public_slug', true ) : '';
		$preview_url = $slug && class_exists( 'LNKup_Passport_Public' ) ? LNKup_Passport_Public::url_for_slug( $slug ) : '';
		$facebook    = $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_facebook', true ) : '';
		$capabilities = $profile_id && class_exists( 'LNKup_Passport_Projection' ) ? wp_list_pluck( LNKup_Passport_Projection::capabilities( (int) $profile_id ), 'key' ) : array();
		$booth_types  = $profile_id && class_exists( 'LNKup_Passport_Projection' ) ? LNKup_Passport_Projection::json_list( get_post_meta( $profile_id, '_lnkup_preferred_booth_types', true ) ) : array();
		$docs_url   = add_query_arg( 'view', 'documents', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$reviews_url = add_query_arg( 'view', 'reviews', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$compliance = LNKup_Documents::compliance_summary( $user_id );
		$rep        = ( $profile_id && class_exists( 'LNKup_Vendor_Reputation' ) ) ? LNKup_Vendor_Reputation::get( $profile_id ) : array();

		ob_start();
		?>
		<div class="lnkup-mp lnkup-vendor-dash lnkup-vendor-profile-editor" id="lnkup-vendor-profile-editor" data-testid="lnkup-vendor-profile-editor">
			<?php
			$profile_actions = '';
			if ( $preview_url ) {
				$profile_actions = '<button type="button" class="lnkup-btn lnkup-btn--outline" data-copy-url="' . esc_url( $preview_url ) . '">' . esc_html__( 'Copy link', 'lnkup-marketplace' ) . '</button>'
					. '<a class="lnkup-btn lnkup-btn--outline" href="' . esc_url( $preview_url ) . '">' . esc_html( lnkup_copy( 'vendor_profile_preview_cta' ) ) . '</a>';
			}
			echo lnkup_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'title'    => __( 'Profile', 'lnkup-marketplace' ),
					'subtitle' => __( 'What organizers see when they review your application.', 'lnkup-marketplace' ),
					'actions'  => $profile_actions,
				)
			);
			?>

			<?php echo self::strength_summary( $strength ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div class="lnkup-profile-layout lnkup-profile-layout--stack">
				<div class="lnkup-profile-sections">
					<?php
					self::section_form( 'business_basics', __( 'Business Basics', 'lnkup-marketplace' ), $strength, $meta, function () use ( $terms, $meta ) {
						?>
						<?php
						$image_alt = $meta['business_name'] ? $meta['business_name'] : __( 'Business', 'lnkup-marketplace' );
						echo lnkup_image_upload_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							array(
								'label'        => __( 'Logo', 'lnkup-marketplace' ),
								'button_label' => __( 'Upload logo', 'lnkup-marketplace' ),
								'input_name'   => 'logo_attachment_id',
								'input_id'     => 'logo_attachment_id',
								'ids'          => array( (int) $meta['logo_id'] ),
								/* translators: %s: business name */
								'alt'          => sprintf( __( '%s logo', 'lnkup-marketplace' ), $image_alt ),
								'testid'       => 'lnkup-vendor-logo-upload',
							)
						);
						?>
						<div class="lnkup-form-row">
							<label for="business_name"><?php esc_html_e( 'Business name', 'lnkup-marketplace' ); ?> *</label>
							<input type="text" id="business_name" name="business_name" required value="<?php echo esc_attr( $meta['business_name'] ); ?>" data-preview="name" />
						</div>
						<div class="lnkup-form-row">
							<label for="city"><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?></label>
							<input type="text" id="city" name="city" value="<?php echo esc_attr( $meta['city'] ); ?>" data-preview="city" data-lnkup-location-city />
						</div>
						<div class="lnkup-grid lnkup-grid--2">
							<div class="lnkup-form-row">
								<label for="owner_name"><?php esc_html_e( 'Owner / Contact Name', 'lnkup-marketplace' ); ?></label>
								<input type="text" id="owner_name" name="owner_name" value="<?php echo esc_attr( $meta['owner_name'] ); ?>" />
							</div>
							<div class="lnkup-form-row">
								<label for="years_in_business"><?php esc_html_e( 'Years in Business', 'lnkup-marketplace' ); ?></label>
								<input type="number" id="years_in_business" name="years_in_business" min="0" value="<?php echo esc_attr( $meta['years_in_business'] ); ?>" />
							</div>
						</div>
						<div class="lnkup-form-row">
							<label for="description"><?php esc_html_e( 'About your business', 'lnkup-marketplace' ); ?></label>
							<textarea id="description" name="description" rows="4"><?php echo esc_textarea( $meta['description'] ); ?></textarea>
						</div>
						<div class="lnkup-form-row">
							<label for="products_services"><?php esc_html_e( 'Products / Services Sold', 'lnkup-marketplace' ); ?></label>
							<textarea id="products_services" name="products_services" rows="3"><?php echo esc_textarea( $meta['products_services'] ); ?></textarea>
						</div>
						<div class="lnkup-form-row" id="categories">
							<label><?php esc_html_e( 'Vendor Categories', 'lnkup-marketplace' ); ?></label>
							<div class="lnkup-chip-select">
								<?php foreach ( $terms as $term ) : ?>
									<label>
										<input type="checkbox" name="categories[]" value="<?php echo esc_attr( $term->name ); ?>" <?php checked( in_array( $term->name, $meta['categories'], true ) ); ?> />
										<span><?php echo esc_html( $term->name ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
						<?php
						echo lnkup_image_upload_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							array(
								'label'        => __( 'Cover photo', 'lnkup-marketplace' ),
								'button_label' => __( 'Upload cover', 'lnkup-marketplace' ),
								'input_name'   => 'cover_image_id',
								'input_id'     => 'cover_image_id',
								'ids'          => array( (int) $meta['cover_id'] ),
								/* translators: %s: business name */
								'alt'          => sprintf( __( '%s cover photo', 'lnkup-marketplace' ), $image_alt ),
								'testid'       => 'lnkup-vendor-cover-upload',
							)
						);
						?>
						<?php
					} );

					self::section_form( 'contact_socials', __( 'Contact & Socials', 'lnkup-marketplace' ), $strength, $meta, function () use ( $meta, $facebook ) {
						?>
						<div class="lnkup-grid lnkup-grid--2">
							<div class="lnkup-form-row"><label for="contact_email"><?php esc_html_e( 'Contact Email', 'lnkup-marketplace' ); ?></label><input type="email" id="contact_email" name="contact_email" value="<?php echo esc_attr( $meta['contact_email'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="contact_phone"><?php esc_html_e( 'Contact Phone', 'lnkup-marketplace' ); ?></label><input type="text" id="contact_phone" name="contact_phone" value="<?php echo esc_attr( $meta['contact_phone'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="website"><?php esc_html_e( 'Website', 'lnkup-marketplace' ); ?></label><input type="url" id="website" name="website" value="<?php echo esc_attr( $meta['website'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="instagram"><?php esc_html_e( 'Instagram', 'lnkup-marketplace' ); ?></label><input type="text" id="instagram" name="instagram" value="<?php echo esc_attr( $meta['instagram'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="tiktok"><?php esc_html_e( 'TikTok', 'lnkup-marketplace' ); ?></label><input type="text" id="tiktok" name="tiktok" value="<?php echo esc_attr( $meta['tiktok'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="facebook"><?php esc_html_e( 'Facebook', 'lnkup-marketplace' ); ?></label><input type="text" id="facebook" name="facebook" value="<?php echo esc_attr( $facebook ); ?>" /></div>
						</div>
						<p class="lnkup-muted"><?php esc_html_e( 'Social links are optional. Email and phone are only shared with organizers you apply to or accept invites from.', 'lnkup-marketplace' ); ?></p>
						<?php
					} );

					self::section_form( 'markets_served', __( 'Markets Served', 'lnkup-marketplace' ), $strength, $meta, function () use ( $meta ) {
						?>
						<div class="lnkup-grid lnkup-grid--2">
							<div class="lnkup-form-row"><label for="state"><?php esc_html_e( 'Home State', 'lnkup-marketplace' ); ?></label><input type="text" id="state" name="state" maxlength="2" value="<?php echo esc_attr( $meta['state'] ); ?>" data-preview="state" data-lnkup-location-state /></div>
							<div class="lnkup-form-row"><label for="cities_served"><?php esc_html_e( 'Cities Served', 'lnkup-marketplace' ); ?></label><input type="text" id="cities_served" name="cities_served" value="<?php echo esc_attr( $meta['cities_served'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="states_served"><?php esc_html_e( 'States Served', 'lnkup-marketplace' ); ?></label><input type="text" id="states_served" name="states_served" value="<?php echo esc_attr( $meta['states_served'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="service_radius_miles"><?php esc_html_e( 'Service Radius (miles)', 'lnkup-marketplace' ); ?></label><input type="number" id="service_radius_miles" name="service_radius_miles" min="0" value="<?php echo esc_attr( $meta['service_radius_miles'] ); ?>" /></div>
							<div class="lnkup-form-row lnkup-form-row--checkbox"><label><input type="checkbox" name="willing_to_travel" value="1" <?php checked( $meta['willing_to_travel'] ); ?> /> <?php esc_html_e( 'Willing to travel', 'lnkup-marketplace' ); ?></label></div>
						</div>
						<?php
					} );

					self::section_form( 'setup_requirements', __( 'Setup Requirements', 'lnkup-marketplace' ), $strength, $meta, function () use ( $meta, $capabilities, $booth_types ) {
						if ( class_exists( 'LNKup_Passport_Projection' ) ) :
							?>
							<input type="hidden" name="capabilities[]" value="" />
							<?php foreach ( LNKup_Passport_Projection::capability_groups() as $group_key => $group ) : ?>
								<fieldset class="lnkup-form-row" data-testid="lnkup-capabilities-<?php echo esc_attr( $group_key ); ?>">
									<legend><?php echo esc_html( $group['label'] ); ?></legend>
									<div class="lnkup-chip-select">
										<?php foreach ( (array) $group['options'] as $cap_key => $cap_label ) : ?>
											<label>
												<input type="checkbox" name="capabilities[]" value="<?php echo esc_attr( $cap_key ); ?>" <?php checked( in_array( $cap_key, $capabilities, true ) ); ?> />
												<span><?php echo esc_html( $cap_label ); ?></span>
											</label>
										<?php endforeach; ?>
									</div>
								</fieldset>
							<?php endforeach; ?>
							<div class="lnkup-form-row">
								<label for="preferred_booth_types"><?php esc_html_e( 'Preferred booth types', 'lnkup-marketplace' ); ?></label>
								<input type="text" id="preferred_booth_types" name="preferred_booth_types" value="<?php echo esc_attr( implode( ', ', $booth_types ) ); ?>" placeholder="<?php esc_attr_e( '10x10, Food truck spot, Corner booth', 'lnkup-marketplace' ); ?>" />
							</div>
							<?php
						endif;
						?>
						<div class="lnkup-grid lnkup-grid--2">
							<div class="lnkup-form-row"><label for="setup_type"><?php esc_html_e( 'Setup Type', 'lnkup-marketplace' ); ?></label><input type="text" id="setup_type" name="setup_type" value="<?php echo esc_attr( $meta['setup_type'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="tent_size"><?php esc_html_e( 'Tent Size', 'lnkup-marketplace' ); ?></label><input type="text" id="tent_size" name="tent_size" value="<?php echo esc_attr( $meta['tent_size'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="trailer_size"><?php esc_html_e( 'Trailer Size', 'lnkup-marketplace' ); ?></label><input type="text" id="trailer_size" name="trailer_size" value="<?php echo esc_attr( $meta['trailer_size'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="food_truck_size"><?php esc_html_e( 'Food Truck Size', 'lnkup-marketplace' ); ?></label><input type="text" id="food_truck_size" name="food_truck_size" value="<?php echo esc_attr( $meta['food_truck_size'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="space_needed"><?php esc_html_e( 'Space Needs', 'lnkup-marketplace' ); ?></label><input type="text" id="space_needed" name="space_needed" value="<?php echo esc_attr( $meta['space_needed'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="power_needed"><?php esc_html_e( 'Power Needs', 'lnkup-marketplace' ); ?></label><input type="text" id="power_needed" name="power_needed" value="<?php echo esc_attr( $meta['power_needed'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="water_needed"><?php esc_html_e( 'Water Needs', 'lnkup-marketplace' ); ?></label><input type="text" id="water_needed" name="water_needed" value="<?php echo esc_attr( $meta['water_needed'] ); ?>" /></div>
							<div class="lnkup-form-row">
								<label for="indoor_outdoor_preference"><?php esc_html_e( 'Indoor / Outdoor Preference', 'lnkup-marketplace' ); ?></label>
								<select id="indoor_outdoor_preference" name="indoor_outdoor_preference">
									<option value=""><?php esc_html_e( 'Select…', 'lnkup-marketplace' ); ?></option>
									<option value="indoor" <?php selected( $meta['indoor_outdoor_preference'], 'indoor' ); ?>><?php esc_html_e( 'Indoor', 'lnkup-marketplace' ); ?></option>
									<option value="outdoor" <?php selected( $meta['indoor_outdoor_preference'], 'outdoor' ); ?>><?php esc_html_e( 'Outdoor', 'lnkup-marketplace' ); ?></option>
									<option value="both" <?php selected( $meta['indoor_outdoor_preference'], 'both' ); ?>><?php esc_html_e( 'Both', 'lnkup-marketplace' ); ?></option>
								</select>
							</div>
							<div class="lnkup-form-row lnkup-form-row--checkbox"><label><input type="checkbox" name="has_generator" value="1" <?php checked( $meta['has_generator'] ); ?> /> <?php esc_html_e( 'I bring my own generator', 'lnkup-marketplace' ); ?></label></div>
						</div>
						<?php
					} );

					self::section_form( 'photos_proof', __( 'Photos', 'lnkup-marketplace' ), $strength, $meta, function () use ( $meta ) {
						$photo_alt = $meta['business_name'] ? $meta['business_name'] : __( 'Vendor', 'lnkup-marketplace' );
						echo lnkup_image_upload_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							array(
								'mode'         => 'gallery',
								'label'        => __( 'Gallery', 'lnkup-marketplace' ),
								'button_label' => __( 'Add photos', 'lnkup-marketplace' ),
								'item_name'    => 'gallery_ids[]',
								'ids'          => (array) $meta['gallery_ids'],
								/* translators: %s: business name */
								'alt'          => sprintf( __( '%s photo', 'lnkup-marketplace' ), $photo_alt ),
								'testid'       => 'lnkup-vendor-gallery-upload',
							)
						);
					} );

					self::section_readonly( 'documents', __( 'Documents', 'lnkup-marketplace' ), $strength, $compliance, $docs_url );
					self::section_readonly_trust( 'trust_reputation', __( 'Trust & Reputation', 'lnkup-marketplace' ), $strength, $rep, $reviews_url );
					self::section_form( 'booking_preferences', __( 'Booking Preferences', 'lnkup-marketplace' ), $strength, $meta, function () use ( $meta ) {
						?>
						<div class="lnkup-grid lnkup-grid--2">
							<div class="lnkup-form-row"><label for="preferred_fee_min"><?php esc_html_e( 'Preferred Fee Min ($)', 'lnkup-marketplace' ); ?></label><input type="number" step="0.01" id="preferred_fee_min" name="preferred_fee_min" value="<?php echo esc_attr( $meta['preferred_fee_min'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="preferred_fee_max"><?php esc_html_e( 'Preferred Fee Max ($)', 'lnkup-marketplace' ); ?></label><input type="number" step="0.01" id="preferred_fee_max" name="preferred_fee_max" value="<?php echo esc_attr( $meta['preferred_fee_max'] ); ?>" /></div>
						</div>
						<div class="lnkup-form-row">
							<label for="availability_notes"><?php esc_html_e( 'Availability Notes', 'lnkup-marketplace' ); ?></label>
							<textarea id="availability_notes" name="availability_notes" rows="3"><?php echo esc_textarea( $meta['availability_notes'] ); ?></textarea>
						</div>
						<?php
					} );
					if ( $profile_id && class_exists( 'LNKup_Match_REST' ) ) {
						$matching = LNKup_Match_REST::preferences_payload( (int) $profile_id );
						self::section_form( 'matching_preferences', __( 'Matching Preferences', 'lnkup-marketplace' ), $strength, $meta, function () use ( $matching ) {
							self::matching_fields( $matching );
						} );
					}
					if ( class_exists( 'LNKup_App_Promo' ) ) {
						echo LNKup_App_Promo::render_preference_toggle(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					?>
				</div>
			</div>

			<form class="lnkup-form lnkup-document-form lnkup-doc-upload-form" id="lnkup-document-upload-form" enctype="multipart/form-data" hidden>
				<?php wp_nonce_field( 'lnkup_document', 'lnkup_nonce' ); ?>
				<input type="hidden" name="action" value="lnkup_upload_document" />
				<input type="hidden" id="document_type" name="document_type" value="" />
				<input type="date" id="expiration_date" name="expiration_date" />
				<input type="file" id="lnkup_document" name="lnkup_document" accept=".pdf,.jpg,.jpeg,.png" />
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Matching preference fields (progressive: every field is optional).
	 *
	 * @param array<string, mixed> $matching LNKup_Match_REST::preferences_payload().
	 */
	private static function matching_fields( array $matching ): void {
		$prefs        = $matching['preferences'];
		$completeness = $matching['completeness'];
		$names        = array();
		foreach ( $matching['options']['categories'] as $option ) {
			$names[ $option['id'] ] = $option['name'];
		}
		$months = array();
		for ( $m = 1; $m <= 12; $m++ ) {
			$months[ $m ] = date_i18n( 'M', mktime( 0, 0, 0, $m, 1 ) );
		}
		?>
		<div class="lnkup-match-completeness" data-testid="lnkup-match-completeness">
			<p><strong><?php echo esc_html( sprintf( __( 'Matching profile %d%% complete', 'lnkup-marketplace' ), (int) $completeness['score'] ) ); ?></strong></p>
			<div class="lnkup-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) (int) $completeness['score'] ); ?>"><span style="width:<?php echo esc_attr( (string) (int) $completeness['score'] ); ?>%"></span></div>
			<?php if ( $completeness['missing'] ) : ?>
				<ul class="lnkup-muted lnkup-match-completeness__missing">
					<?php foreach ( $completeness['missing'] as $item ) : ?>
						<li><?php echo esc_html( $item['label'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="lnkup-muted"><?php esc_html_e( 'Everything here is optional. The more you share, the better your event recommendations. Your exact location is never shown to organizers.', 'lnkup-marketplace' ); ?></p>
		</div>
		<div class="lnkup-grid lnkup-grid--2">
			<div class="lnkup-form-row">
				<label for="match_primary_category"><?php esc_html_e( 'Primary category', 'lnkup-marketplace' ); ?></label>
				<select id="match_primary_category" name="match_prefs[primary_category]">
					<option value="0"><?php esc_html_e( 'Select…', 'lnkup-marketplace' ); ?></option>
					<?php foreach ( $prefs['categories'] as $term_id ) : ?>
						<option value="<?php echo esc_attr( (string) $term_id ); ?>" <?php selected( (int) $prefs['primary_category'], (int) $term_id ); ?>><?php echo esc_html( $names[ $term_id ] ?? '' ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( ! $prefs['categories'] ) : ?>
					<p class="lnkup-muted"><?php esc_html_e( 'Choose your vendor categories in Business Basics first.', 'lnkup-marketplace' ); ?></p>
				<?php endif; ?>
			</div>
			<div class="lnkup-form-row">
				<label for="match_zip"><?php esc_html_e( 'ZIP code (optional)', 'lnkup-marketplace' ); ?></label>
				<input type="text" id="match_zip" name="match_prefs[zip]" maxlength="10" inputmode="numeric" value="<?php echo esc_attr( $prefs['zip'] ); ?>" />
			</div>
			<div class="lnkup-form-row lnkup-form-row--checkbox">
				<input type="hidden" name="match_prefs[travel_national]" value="0" />
				<label><input type="checkbox" name="match_prefs[travel_national]" value="1" <?php checked( $prefs['travel_national'] ); ?> /> <?php esc_html_e( 'I travel nationally for the right event', 'lnkup-marketplace' ); ?></label>
			</div>
		</div>
		<div class="lnkup-form-row">
			<label><?php esc_html_e( 'Event types you like', 'lnkup-marketplace' ); ?></label>
			<input type="hidden" name="match_prefs[event_types]" value="" />
			<div class="lnkup-chip-select">
				<?php foreach ( $matching['options']['event_types'] as $type ) : ?>
					<label>
						<input type="checkbox" name="match_prefs[event_types][]" value="<?php echo esc_attr( (string) $type['id'] ); ?>" <?php checked( in_array( $type['name'], $prefs['event_types'], true ) ); ?> />
						<span><?php echo esc_html( $type['name'] ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="lnkup-form-row">
			<label><?php esc_html_e( 'Months you are available', 'lnkup-marketplace' ); ?></label>
			<input type="hidden" name="match_prefs[available_months]" value="" />
			<div class="lnkup-chip-select">
				<?php foreach ( $months as $num => $label ) : ?>
					<label>
						<input type="checkbox" name="match_prefs[available_months][]" value="<?php echo esc_attr( (string) $num ); ?>" <?php checked( in_array( $num, array_map( 'intval', $prefs['available_months'] ), true ) ); ?> />
						<span><?php echo esc_html( $label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<fieldset class="lnkup-form-row">
			<legend><?php esc_html_e( 'Discovery & invites', 'lnkup-marketplace' ); ?></legend>
			<input type="hidden" name="match_prefs[discoverable]" value="0" />
			<label class="lnkup-form-row--checkbox"><input type="checkbox" name="match_prefs[discoverable]" value="1" data-testid="lnkup-match-discoverable" <?php checked( $prefs['discoverable'] ); ?> /> <?php esc_html_e( 'Let organizers find my business in vendor search', 'lnkup-marketplace' ); ?></label>
			<input type="hidden" name="match_prefs[accept_invites]" value="0" />
			<label class="lnkup-form-row--checkbox"><input type="checkbox" name="match_prefs[accept_invites]" value="1" <?php checked( $prefs['accept_invites'] ); ?> /> <?php esc_html_e( 'Organizers I have not worked with can invite me to apply', 'lnkup-marketplace' ); ?></label>
			<p class="lnkup-muted"><?php esc_html_e( 'Organizers only see your public profile (name, logo, categories, city, description, photos). Your email, phone, documents, applications and payments stay private until you apply to their event or accept their invite.', 'lnkup-marketplace' ); ?></p>
		</fieldset>
		<?php
	}

	/**
	 * @param int         $profile_id Profile ID.
	 * @param WP_Post|null $profile   Profile post.
	 * @return array<string, mixed>
	 */
	private static function meta_values( int $profile_id, $profile ): array {
		$business = $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_business_name', true ) : '';
		$city     = $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_city', true ) : '';
		$state    = $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_state', true ) : '';
		$logo_id  = $profile_id ? (int) get_post_meta( $profile_id, '_lnkup_logo_attachment_id', true ) : 0;
		$cover_id = $profile_id ? (int) get_post_meta( $profile_id, '_lnkup_cover_image_id', true ) : 0;
		$cities   = $profile_id ? json_decode( get_post_meta( $profile_id, '_lnkup_cities_served', true ) ?: '[]', true ) : array();
		$states   = $profile_id ? json_decode( get_post_meta( $profile_id, '_lnkup_states_served', true ) ?: '[]', true ) : array();
		$gallery  = $profile_id ? LNKup_Growth_Profile_Strength::gallery_ids( $profile_id ) : array();
		$categories = $profile_id ? wp_get_post_terms( $profile_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) ) : array();
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}
		$initials = '';
		foreach ( preg_split( '/\s+/', trim( $business ) ) as $part ) {
			$initials .= strtoupper( substr( $part, 0, 1 ) );
			if ( strlen( $initials ) >= 2 ) {
				break;
			}
		}
		$gallery_urls = array();
		foreach ( $gallery as $gid ) {
			$url = wp_get_attachment_image_url( $gid, 'medium' );
			if ( $url ) {
				$gallery_urls[] = $url;
			}
		}
		return array(
			'business_name'            => $business,
			'owner_name'               => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_owner_name', true ) : '',
			'description'              => $profile ? $profile->post_content : '',
			'products_services'        => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_products_services', true ) : '',
			'years_in_business'        => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_years_in_business', true ) : '',
			'contact_email'            => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_contact_email', true ) : '',
			'contact_phone'            => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_contact_phone', true ) : '',
			'website'                  => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_website', true ) : '',
			'instagram'                => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_instagram', true ) : '',
			'tiktok'                   => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_tiktok', true ) : '',
			'city'                     => $city,
			'state'                    => $state,
			'cities_served'            => is_array( $cities ) ? implode( ', ', $cities ) : '',
			'states_served'            => is_array( $states ) ? implode( ', ', $states ) : '',
			'service_radius_miles'     => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_service_radius_miles', true ) : '',
			'willing_to_travel'        => (bool) get_post_meta( $profile_id, '_lnkup_willing_to_travel', true ),
			'setup_type'               => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_setup_type', true ) : '',
			'tent_size'                => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_tent_size', true ) : '',
			'trailer_size'             => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_trailer_size', true ) : '',
			'food_truck_size'          => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_food_truck_size', true ) : '',
			'space_needed'             => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_space_needed', true ) : '',
			'power_needed'             => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_power_needed', true ) : '',
			'water_needed'             => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_water_needed', true ) : '',
			'has_generator'            => (bool) get_post_meta( $profile_id, '_lnkup_has_generator', true ),
			'indoor_outdoor_preference'=> $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_indoor_outdoor_preference', true ) : '',
			'preferred_fee_min'        => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_preferred_fee_min', true ) : '',
			'preferred_fee_max'        => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_preferred_fee_max', true ) : '',
			'availability_notes'       => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_availability_notes', true ) : '',
			'logo_id'                  => (string) $logo_id,
			'cover_id'                 => (string) $cover_id,
			'logo_url'                 => $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '',
			'cover_url'                => $cover_id ? (string) wp_get_attachment_image_url( $cover_id, 'large' ) : '',
			'gallery_ids'              => $gallery,
			'gallery_urls'             => $gallery_urls,
			'categories'               => $categories,
			'categories_label'         => implode( ', ', $categories ),
			'location'                 => trim( $city . ( $state ? ', ' . $state : '' ) ),
			'initials'                 => $initials ?: 'V',
		);
	}

	/**
	 * @param string               $key      Section key.
	 * @param string               $title    Section title.
	 * @param array<string, mixed> $strength Strength payload.
	 * @param array<string, mixed> $meta     Meta values.
	 * @param callable             $fields   Field renderer.
	 */
	private static function section_form( string $key, string $title, array $strength, array $meta, callable $fields ): void {
		$section = self::find_section( $strength, $key );
		$status  = $section['status'] ?? 'missing';
		?>
		<article class="lnkup-profile-section lnkup-profile-section--<?php echo esc_attr( str_replace( '_', '-', $status ) ); ?>" id="section-<?php echo esc_attr( str_replace( '_', '-', $key ) ); ?>" data-profile-section="<?php echo esc_attr( $key ); ?>">
			<header class="lnkup-profile-section__header">
				<span class="lnkup-profile-section__complete-dot<?php echo ! empty( $section['complete'] ) ? ' is-complete' : ''; ?>" aria-hidden="true"></span>
				<h3 class="lnkup-profile-section__title"><?php echo esc_html( $title ); ?></h3>
				<span class="lnkup-profile-section__pill" data-section-status><?php echo esc_html( $section['status_label'] ?? lnkup_copy( 'profile_section_missing' ) ); ?></span>
			</header>
			<form class="lnkup-form lnkup-profile-form lnkup-profile-section-form" data-section="<?php echo esc_attr( $key ); ?>">
				<?php wp_nonce_field( 'lnkup_profile', 'lnkup_nonce' ); ?>
				<input type="hidden" name="action" value="lnkup_save_profile" />
				<input type="hidden" name="business_name" value="<?php echo esc_attr( $meta['business_name'] ); ?>" class="lnkup-profile-business-name-field" />
				<?php $fields(); ?>
				<div class="lnkup-profile-section__footer">
					<button type="submit" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" data-section-save><?php echo esc_html( lnkup_copy( 'profile_save_section' ) ); ?></button>
					<span class="lnkup-profile-section__saved lnkup-muted" data-section-saved hidden><?php echo esc_html( lnkup_copy( 'profile_section_saved' ) ); ?></span>
					<span class="lnkup-profile-section__error lnkup-alert lnkup-alert--error" data-section-error hidden></span>
				</div>
			</form>
		</article>
		<?php
	}

	/**
	 * @param string               $key        Section key.
	 * @param string               $title      Title.
	 * @param array<string, mixed> $strength   Strength.
	 * @param array<string, mixed> $compliance Compliance summary.
	 * @param string               $docs_url   Documents URL.
	 */
	private static function section_readonly( string $key, string $title, array $strength, array $compliance, string $docs_url ): void {
		$section = self::find_section( $strength, $key );
		$status  = $section['status'] ?? 'missing';
		$labels  = array(
			'verified' => __( 'Verified', 'lnkup-marketplace' ),
			'expiring' => __( 'Expiring soon', 'lnkup-marketplace' ),
			'pending'  => __( 'Pending review', 'lnkup-marketplace' ),
			'missing'  => __( 'Missing', 'lnkup-marketplace' ),
		);
		?>
		<article class="lnkup-profile-section lnkup-profile-section--<?php echo esc_attr( str_replace( '_', '-', $status ) ); ?>" id="section-<?php echo esc_attr( str_replace( '_', '-', $key ) ); ?>" data-profile-section="<?php echo esc_attr( $key ); ?>">
			<header class="lnkup-profile-section__header">
				<span class="lnkup-profile-section__complete-dot<?php echo ! empty( $section['complete'] ) ? ' is-complete' : ''; ?>" aria-hidden="true"></span>
				<h3 class="lnkup-profile-section__title"><?php echo esc_html( $title ); ?></h3>
				<span class="lnkup-profile-section__pill" data-section-status><?php echo esc_html( $section['status_label'] ?? '' ); ?></span>
			</header>
			<ul class="lnkup-profile-doc-list">
				<?php foreach ( (array) ( $compliance['items'] ?? array() ) as $type_key => $item ) : ?>
					<li class="lnkup-profile-doc-list__item">
						<span><?php echo esc_html( $item['label'] ?? $type_key ); ?></span>
						<span class="lnkup-profile-doc-list__status lnkup-profile-doc-list__status--<?php echo esc_attr( $item['status'] ?? 'missing' ); ?>"><?php echo esc_html( $labels[ $item['status'] ?? 'missing' ] ?? $item['status'] ); ?></span>
						<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-doc-upload="<?php echo esc_attr( (string) $type_key ); ?>"><?php esc_html_e( 'Upload', 'lnkup-marketplace' ); ?></button>
					</li>
				<?php endforeach; ?>
			</ul>
			<a href="<?php echo esc_url( $docs_url ); ?>" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm"><?php esc_html_e( 'Open documents manager', 'lnkup-marketplace' ); ?></a>
		</article>
		<?php
	}

	/**
	 * @param string               $key         Section key.
	 * @param string               $title       Title.
	 * @param array<string, mixed> $strength    Strength.
	 * @param array<string, mixed> $reputation  Reputation.
	 * @param string               $reviews_url Reviews URL.
	 */
	private static function section_readonly_trust( string $key, string $title, array $strength, array $reputation, string $reviews_url ): void {
		$section = self::find_section( $strength, $key );
		$status  = $section['status'] ?? 'optional';
		?>
		<article class="lnkup-profile-section lnkup-profile-section--<?php echo esc_attr( str_replace( '_', '-', $status ) ); ?>" id="section-<?php echo esc_attr( str_replace( '_', '-', $key ) ); ?>" data-profile-section="<?php echo esc_attr( $key ); ?>">
			<header class="lnkup-profile-section__header">
				<span class="lnkup-profile-section__complete-dot<?php echo ! empty( $section['complete'] ) ? ' is-complete' : ''; ?>" aria-hidden="true"></span>
				<h3 class="lnkup-profile-section__title"><?php echo esc_html( $title ); ?></h3>
				<span class="lnkup-profile-section__pill" data-section-status><?php echo esc_html( $section['status_label'] ?? lnkup_copy( 'profile_section_optional' ) ); ?></span>
			</header>
			<p class="lnkup-muted"><?php printf( esc_html__( 'Completed events: %d · Reviews: %d · Rating: %s', 'lnkup-marketplace' ), (int) ( $reputation['completed_events'] ?? 0 ), (int) ( $reputation['review_count'] ?? 0 ), esc_html( (string) ( $reputation['avg_rating'] ?? '—' ) ) ); ?></p>
			<a href="<?php echo esc_url( $reviews_url ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php esc_html_e( 'View reviews', 'lnkup-marketplace' ); ?></a>
		</article>
		<?php
	}

	/**
	 * Compact profile strength: score, tier, and jump links to incomplete sections.
	 *
	 * @param array<string, mixed> $strength Strength payload.
	 */
	private static function strength_summary( array $strength ): string {
		if ( ! isset( $strength['score'] ) ) {
			return '';
		}
		$score      = (int) $strength['score'];
		$incomplete = array_values(
			array_filter(
				(array) ( $strength['sections'] ?? array() ),
				static function ( $section ) {
					return empty( $section['complete'] ) && ! empty( $section['key'] );
				}
			)
		);
		ob_start();
		?>
		<section class="lnkup-profile-strength-summary" data-testid="lnkup-profile-strength-summary" aria-label="<?php esc_attr_e( 'Profile strength', 'lnkup-marketplace' ); ?>">
			<p class="lnkup-profile-strength-summary__score">
				<strong><?php echo esc_html( sprintf( /* translators: %d: score */ __( '%d%% complete', 'lnkup-marketplace' ), $score ) ); ?></strong>
				<?php if ( ! empty( $strength['tier'] ) ) : ?>
					<span class="lnkup-muted"><?php echo esc_html( (string) $strength['tier'] ); ?></span>
				<?php endif; ?>
			</p>
			<div class="lnkup-vendor-strength-card__bar" role="progressbar" aria-label="<?php esc_attr_e( 'Profile strength', 'lnkup-marketplace' ); ?>" aria-valuenow="<?php echo esc_attr( (string) $score ); ?>" aria-valuemin="0" aria-valuemax="100">
				<span class="lnkup-vendor-strength-card__fill" style="width:<?php echo esc_attr( (string) min( 100, max( 0, $score ) ) ); ?>%"></span>
			</div>
			<?php if ( $incomplete ) : ?>
				<p class="lnkup-profile-strength-summary__todo">
					<span class="lnkup-muted"><?php esc_html_e( 'Still to finish:', 'lnkup-marketplace' ); ?></span>
					<?php foreach ( $incomplete as $section ) : ?>
						<a href="#section-<?php echo esc_attr( str_replace( '_', '-', (string) $section['key'] ) ); ?>"><?php echo esc_html( (string) ( $section['label'] ?? $section['title'] ?? $section['key'] ) ); ?></a>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $strength Strength payload.
	 * @param string               $key      Section key.
	 * @return array<string, mixed>
	 */
	private static function find_section( array $strength, string $key ): array {
		$all = array_merge( (array) ( $strength['sections'] ?? array() ), (array) ( $strength['related'] ?? array() ) );
		foreach ( $all as $section ) {
			if ( ( $section['key'] ?? '' ) === $key ) {
				return $section;
			}
		}
		return array();
	}
}
