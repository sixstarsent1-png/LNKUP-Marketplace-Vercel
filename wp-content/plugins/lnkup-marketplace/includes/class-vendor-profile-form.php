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
		$preview_url = $slug ? add_query_arg( 'vendor', $slug, lnkup_page_url_helper( 'vendors' ) ) : '';
		$docs_url   = add_query_arg( 'view', 'documents', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$reviews_url = add_query_arg( 'view', 'reviews', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$compliance = LNKup_Documents::compliance_summary( $user_id );
		$rep        = ( $profile_id && class_exists( 'LNKup_Vendor_Reputation' ) ) ? LNKup_Vendor_Reputation::get( $profile_id ) : array();

		ob_start();
		?>
		<div class="lnkup-mp lnkup-vendor-dash lnkup-vendor-profile-editor" id="lnkup-vendor-profile-editor" data-testid="lnkup-vendor-profile-editor">
			<?php
			$doc_pct = $compliance['total'] ? round( ( $compliance['verified'] / $compliance['total'] ) * 100 ) : 0;
			echo lnkup_executive_hero(
				array(
					'title'     => lnkup_copy( 'vendor_profile_title' ),
					'subtitle'  => lnkup_copy( 'vendor_profile_subtitle' ),
					'kpi'       => (string) (int) ( $strength['score'] ?? 0 ) . '%',
					'kpi_label' => __( 'Profile strength', 'lnkup-marketplace' ),
					'ring_pct'  => (int) ( $strength['score'] ?? 0 ),
					'metrics'   => array(
						array( 'label' => __( 'Documents', 'lnkup-marketplace' ), 'value' => $doc_pct . '%' ),
						array( 'label' => __( 'Reviews', 'lnkup-marketplace' ), 'value' => (string) (int) ( $rep['review_count'] ?? 0 ) ),
					),
					'primary_cta' => $preview_url ? array(
						'label' => lnkup_copy( 'vendor_profile_preview_cta' ),
						'href'  => $preview_url,
					) : array(),
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>

			<div class="lnkup-profile-layout lnkup-profile-layout--resume">
				<aside class="lnkup-panel lnkup-profile-preview lnkup-profile-resume-banner lnkup-surface--hero" id="lnkup-profile-live-preview">
					<?php if ( ! empty( $meta['cover_url'] ) ) : ?>
						<div class="lnkup-profile-preview__cover" style="background-image:url(<?php echo esc_url( $meta['cover_url'] ); ?>)"></div>
					<?php endif; ?>
					<div class="lnkup-profile-preview__body">
						<?php if ( ! empty( $meta['logo_url'] ) ) : ?>
							<img src="<?php echo esc_url( $meta['logo_url'] ); ?>" alt="" class="lnkup-profile-preview__logo" id="lnkup-preview-logo" />
						<?php else : ?>
							<div class="lnkup-profile-preview__avatar" id="lnkup-preview-initials"><?php echo esc_html( $meta['initials'] ); ?></div>
						<?php endif; ?>
						<h2 class="lnkup-profile-preview__name" id="lnkup-preview-name"><?php echo esc_html( $meta['business_name'] ?: __( 'Your business', 'lnkup-marketplace' ) ); ?></h2>
						<p class="lnkup-profile-preview__location" id="lnkup-preview-location"><?php echo esc_html( $meta['location'] ); ?></p>
						<p class="lnkup-profile-preview__categories lnkup-muted" id="lnkup-preview-categories"><?php echo esc_html( $meta['categories_label'] ); ?></p>
						<p class="lnkup-profile-preview__strength" id="lnkup-preview-strength"><?php echo esc_html( sprintf( lnkup_copy( 'vendor_profile_progress' ), (int) ( $strength['score'] ?? 0 ), (string) ( $strength['tier'] ?? '' ) ) ); ?></p>
						<?php if ( $rep && ! empty( $rep['avg_rating'] ) ) : ?>
							<?php echo LNKup_Reputation_Render::star_rating( $rep['avg_rating'], $rep['review_count'] ?? 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
						<?php if ( $preview_url ) : ?>
							<a href="<?php echo esc_url( $preview_url ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php echo esc_html( lnkup_copy( 'vendor_profile_preview_cta' ) ); ?></a>
						<?php endif; ?>
						<div class="lnkup-profile-trust-strip lnkup-surface--quiet">
							<div><span class="lnkup-type-label"><?php esc_html_e( 'Trust', 'lnkup-marketplace' ); ?></span><p class="lnkup-type-display" style="font-size:1.25rem"><?php echo esc_html( $rep ? number_format( (float) ( $rep['avg_rating'] ?? 0 ), 1 ) : '—' ); ?></p></div>
							<div><span class="lnkup-type-label"><?php esc_html_e( 'Compliance', 'lnkup-marketplace' ); ?></span><p class="lnkup-type-display" style="font-size:1.25rem"><?php echo esc_html( (string) $doc_pct ); ?>%</p></div>
						</div>
					</div>
				</aside>

				<div class="lnkup-profile-sections">
					<?php
					self::section_form( 'business_basics', __( 'Business Basics', 'lnkup-marketplace' ), $strength, $meta, function () use ( $terms, $meta ) {
						?>
						<div class="lnkup-grid lnkup-grid--2">
							<div class="lnkup-form-row">
								<label for="business_name"><?php esc_html_e( 'Business Name', 'lnkup-marketplace' ); ?> *</label>
								<input type="text" id="business_name" name="business_name" required value="<?php echo esc_attr( $meta['business_name'] ); ?>" data-preview="name" />
							</div>
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
						<div class="lnkup-form-row">
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
						<div class="lnkup-grid lnkup-grid--2">
							<div class="lnkup-form-row">
								<label><?php esc_html_e( 'Logo', 'lnkup-marketplace' ); ?></label>
								<input type="hidden" name="logo_attachment_id" id="logo_attachment_id" value="<?php echo esc_attr( $meta['logo_id'] ); ?>" />
								<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-media-picker="logo"><?php esc_html_e( 'Choose logo', 'lnkup-marketplace' ); ?></button>
							</div>
							<div class="lnkup-form-row">
								<label><?php esc_html_e( 'Cover Photo', 'lnkup-marketplace' ); ?></label>
								<input type="hidden" name="cover_image_id" id="cover_image_id" value="<?php echo esc_attr( $meta['cover_id'] ); ?>" />
								<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-media-picker="cover"><?php esc_html_e( 'Choose cover', 'lnkup-marketplace' ); ?></button>
							</div>
						</div>
						<?php
					} );

					self::section_form( 'contact_socials', __( 'Contact & Socials', 'lnkup-marketplace' ), $strength, $meta, function () use ( $meta ) {
						?>
						<div class="lnkup-grid lnkup-grid--2">
							<div class="lnkup-form-row"><label for="contact_email"><?php esc_html_e( 'Contact Email', 'lnkup-marketplace' ); ?></label><input type="email" id="contact_email" name="contact_email" value="<?php echo esc_attr( $meta['contact_email'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="contact_phone"><?php esc_html_e( 'Contact Phone', 'lnkup-marketplace' ); ?></label><input type="text" id="contact_phone" name="contact_phone" value="<?php echo esc_attr( $meta['contact_phone'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="website"><?php esc_html_e( 'Website', 'lnkup-marketplace' ); ?></label><input type="url" id="website" name="website" value="<?php echo esc_attr( $meta['website'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="instagram"><?php esc_html_e( 'Instagram', 'lnkup-marketplace' ); ?></label><input type="text" id="instagram" name="instagram" value="<?php echo esc_attr( $meta['instagram'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="tiktok"><?php esc_html_e( 'TikTok', 'lnkup-marketplace' ); ?></label><input type="text" id="tiktok" name="tiktok" value="<?php echo esc_attr( $meta['tiktok'] ); ?>" /></div>
						</div>
						<?php
					} );

					self::section_form( 'markets_served', __( 'Markets Served', 'lnkup-marketplace' ), $strength, $meta, function () use ( $meta ) {
						?>
						<div class="lnkup-grid lnkup-grid--2">
							<div class="lnkup-form-row"><label for="city"><?php esc_html_e( 'Home City', 'lnkup-marketplace' ); ?></label><input type="text" id="city" name="city" value="<?php echo esc_attr( $meta['city'] ); ?>" data-preview="city" /></div>
							<div class="lnkup-form-row"><label for="state"><?php esc_html_e( 'Home State', 'lnkup-marketplace' ); ?></label><input type="text" id="state" name="state" maxlength="2" value="<?php echo esc_attr( $meta['state'] ); ?>" data-preview="state" /></div>
							<div class="lnkup-form-row"><label for="cities_served"><?php esc_html_e( 'Cities Served', 'lnkup-marketplace' ); ?></label><input type="text" id="cities_served" name="cities_served" value="<?php echo esc_attr( $meta['cities_served'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="states_served"><?php esc_html_e( 'States Served', 'lnkup-marketplace' ); ?></label><input type="text" id="states_served" name="states_served" value="<?php echo esc_attr( $meta['states_served'] ); ?>" /></div>
							<div class="lnkup-form-row"><label for="service_radius_miles"><?php esc_html_e( 'Service Radius (miles)', 'lnkup-marketplace' ); ?></label><input type="number" id="service_radius_miles" name="service_radius_miles" min="0" value="<?php echo esc_attr( $meta['service_radius_miles'] ); ?>" /></div>
							<div class="lnkup-form-row lnkup-form-row--checkbox"><label><input type="checkbox" name="willing_to_travel" value="1" <?php checked( $meta['willing_to_travel'] ); ?> /> <?php esc_html_e( 'Willing to travel', 'lnkup-marketplace' ); ?></label></div>
						</div>
						<?php
					} );

					self::section_form( 'setup_requirements', __( 'Setup Requirements', 'lnkup-marketplace' ), $strength, $meta, function () use ( $meta ) {
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

					self::section_form( 'photos_proof', __( 'Photos & Proof', 'lnkup-marketplace' ), $strength, $meta, function () use ( $meta ) {
						?>
						<p class="lnkup-muted"><?php esc_html_e( 'Add at least 3 photos of your booth, products, and past events.', 'lnkup-marketplace' ); ?></p>
						<input type="hidden" name="gallery_ids[]" value="" />
						<div id="lnkup-gallery-ids">
							<?php foreach ( $meta['gallery_ids'] as $gid ) : ?>
								<input type="hidden" name="gallery_ids[]" value="<?php echo esc_attr( (string) $gid ); ?>" />
							<?php endforeach; ?>
						</div>
						<div class="lnkup-profile-gallery-preview" id="lnkup-gallery-preview">
							<?php foreach ( $meta['gallery_urls'] as $url ) : ?>
								<img src="<?php echo esc_url( $url ); ?>" alt="" />
							<?php endforeach; ?>
						</div>
						<button type="button" class="lnkup-btn lnkup-btn--outline" data-media-picker="gallery"><?php esc_html_e( 'Manage gallery', 'lnkup-marketplace' ); ?></button>
						<?php
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
	 * @param array<string, mixed> $strength Strength payload.
	 * @param string               $key      Section key.
	 * @return array<string, mixed>
	 */
	private static function find_section( array $strength, string $key ): array {
		foreach ( (array) ( $strength['sections'] ?? array() ) as $section ) {
			if ( ( $section['key'] ?? '' ) === $key ) {
				return $section;
			}
		}
		return array();
	}
}
