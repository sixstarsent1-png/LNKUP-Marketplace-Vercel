<?php
/**
 * Vendor CRM profile assembly.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_CRM_Profile {

	/**
	 * Get profile by vendor profile post ID.
	 *
	 * @param int $profile_id Profile post ID.
	 * @param int $organizer_id Organizer user ID.
	 */
	public static function get( $profile_id, $organizer_id = 0 ) {
		$organizer_id = $organizer_id ?: get_current_user_id();
		$post = get_post( $profile_id );
		if ( ! $post || 'lnkup_vendor_profile' !== $post->post_type ) {
			return null;
		}
		$vendor_id = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		$can_manage = LNKup_Capabilities::organizer_can_manage_vendor( $organizer_id, $vendor_id );
		$rel = LNKup_Organizer_CRM::get_relationship( $organizer_id, $vendor_id );
		$cities = json_decode( get_post_meta( $profile_id, '_lnkup_cities_served', true ) ?: '[]', true ) ?: array();
		$states = json_decode( get_post_meta( $profile_id, '_lnkup_states_served', true ) ?: '[]', true ) ?: array();
		$gallery = json_decode( get_post_meta( $profile_id, '_lnkup_gallery_ids', true ) ?: '[]', true ) ?: array();
		$logo_id = (int) get_post_meta( $profile_id, '_lnkup_logo_attachment_id', true );
		$categories = wp_get_post_terms( $profile_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}

		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array( 'key' => '_lnkup_vendor_user_id', 'value' => $vendor_id ),
					array( 'key' => '_lnkup_organizer_user_id', 'value' => $organizer_id ),
				),
			)
		);

		$past_events = array();
		$upcoming = array();
		$payments_total = 0;
		$completed = 0;
		foreach ( $apps as $app ) {
			$event_id = (int) get_post_meta( $app->ID, '_lnkup_event_id', true );
			$event = $event_id ? get_post( $event_id ) : null;
			$app_status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			$row = array(
				'application_id' => $app->ID,
				'event_id'       => $event_id,
				'event_title'    => $event ? $event->post_title : '',
				'status'         => $app_status,
				'date'           => $event ? get_post_meta( $event_id, '_lnkup_event_date', true ) : '',
			);
			if ( in_array( $app_status, array( 'paid', 'completed' ), true ) ) {
				$completed++;
				$past_events[] = $row;
			} elseif ( in_array( $app_status, array( 'submitted', 'under_review', 'approved', 'payment_required', 'waitlisted' ), true ) ) {
				$upcoming[] = $row;
			}
			if ( 'paid' === $app_status ) {
				$pay = get_posts(
					array(
						'post_type'      => 'lnkup_payment',
						'posts_per_page' => 1,
						'meta_key'       => '_lnkup_application_id',
						'meta_value'     => $app->ID,
					)
				);
				if ( $pay ) {
					$payments_total += (float) get_post_meta( $pay[0]->ID, '_lnkup_total_amount', true );
				}
			}
		}

		$docs = LNKup_Documents::get_for_vendor( $vendor_id );
		$doc_summary = array();
		foreach ( $docs as $doc ) {
			$doc_summary[] = array(
				'type'   => get_post_meta( $doc->ID, '_lnkup_document_type', true ),
				'status' => get_post_meta( $doc->ID, '_lnkup_verification_status', true ),
			);
		}

		$flags = class_exists( 'LNKup_Vendor_Flags' ) ? LNKup_Vendor_Flags::get_for_vendor( $organizer_id, $vendor_id ) : array();
		$tags  = class_exists( 'LNKup_Vendor_Tags' ) ? LNKup_Vendor_Tags::get_for_vendor( $organizer_id, $vendor_id ) : array();
		$notes = $can_manage && class_exists( 'LNKup_Vendor_Notes' ) ? LNKup_Vendor_Notes::get_for_vendor( $organizer_id, $vendor_id, 5 ) : array();
		$reputation = class_exists( 'LNKup_Vendor_Reputation' ) ? LNKup_Vendor_Reputation::get( $profile_id ) : array();
		$metrics = class_exists( 'LNKup_Reliability_Metrics' ) ? LNKup_Reliability_Metrics::get( $profile_id ) : array();
		$review_summary = class_exists( 'LNKup_Vendor_Reviews' ) ? LNKup_Vendor_Reviews::summary_for_vendor( $profile_id ) : array( 'avg' => 0, 'count' => 0 );
		$strength       = class_exists( 'LNKup_Growth_Profile_Strength' ) ? LNKup_Growth_Profile_Strength::calculate( $profile_id ) : array();

		$gallery_urls = array();
		foreach ( $gallery as $aid ) {
			$url = wp_get_attachment_image_url( (int) $aid, 'medium' );
			if ( $url ) {
				$gallery_urls[] = $url;
			}
		}

		$profile = array(
			'profile_id'        => $profile_id,
			'vendor_user_id'    => $vendor_id,
			'business_name'     => get_post_meta( $profile_id, '_lnkup_business_name', true ) ?: $post->post_title,
			'owner_name'        => get_post_meta( $profile_id, '_lnkup_owner_name', true ),
			'email'             => get_post_meta( $profile_id, '_lnkup_contact_email', true ),
			'phone'             => get_post_meta( $profile_id, '_lnkup_contact_phone', true ),
			'instagram'         => get_post_meta( $profile_id, '_lnkup_instagram', true ),
			'website'           => get_post_meta( $profile_id, '_lnkup_website', true ),
			'category'          => $categories,
			'city'              => get_post_meta( $profile_id, '_lnkup_city', true ),
			'state'             => get_post_meta( $profile_id, '_lnkup_state', true ),
			'cities_served'     => $cities,
			'states_served'     => $states,
			'logo'              => $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '',
			'gallery'           => $gallery_urls,
			'products_services' => get_post_meta( $profile_id, '_lnkup_products_services', true ),
			'setup_type'        => get_post_meta( $profile_id, '_lnkup_setup_type', true ),
			'power_needs'       => get_post_meta( $profile_id, '_lnkup_power_needed', true ),
			'water_needs'       => get_post_meta( $profile_id, '_lnkup_water_needed', true ),
			'tent_size'         => get_post_meta( $profile_id, '_lnkup_tent_size', true ),
			'trailer_size'      => get_post_meta( $profile_id, '_lnkup_trailer_size', true ),
			'description'       => $post->post_content,
			'verified'          => 'approved' === get_post_meta( $profile_id, '_lnkup_verification_status', true ),
			'documents'         => $doc_summary,
			'applications'      => count( $apps ),
			'completed_events'  => $completed,
			'payments_total'    => $payments_total,
			'past_events'       => $past_events,
			'upcoming_events'   => $upcoming,
			'is_favorite'       => ! empty( $rel['is_favorite'] ),
			'is_preferred'      => ! empty( $rel['is_preferred'] ),
			'can_manage'        => $can_manage,
			'flags'             => $flags,
			'tags'              => $tags,
			'recent_notes'      => $notes,
			'vendor_score'      => $reputation['score'] ?? self::compute_score( $completed, $flags, $doc_summary, $rel ),
			'reputation_score'  => $reputation['score'] ?? 0,
			'reputation_label'  => $reputation['label'] ?? '',
			'avg_rating'        => $review_summary['avg'] ?? 0,
			'review_count'      => $review_summary['count'] ?? 0,
			'reputation'        => $reputation,
			'reliability'       => $metrics,
			'badges'            => $reputation['badges'] ?? array(),
			'no_show_count'     => self::no_show_count( $organizer_id, $vendor_id ),
			'no_show_risk'      => ( $metrics['no_show_rate'] ?? 0 ) > 10 ? 'high' : ( ( $metrics['no_show_rate'] ?? 0 ) > 5 ? 'medium' : 'low' ),
			'profile_strength_score' => (int) ( $strength['score'] ?? 0 ),
			'profile_strength_tier'  => (string) ( $strength['tier'] ?? '' ),
			'cover_image'       => (int) get_post_meta( $profile_id, '_lnkup_cover_image_id', true ) ? wp_get_attachment_image_url( (int) get_post_meta( $profile_id, '_lnkup_cover_image_id', true ), 'large' ) : '',
			'space_needed'      => get_post_meta( $profile_id, '_lnkup_space_needed', true ),
		);

		return apply_filters( 'lnkup_vendor_profile_data', $profile, 'crm' );
	}

	/**
	 * Get public-safe profile DTO.
	 *
	 * @param int $profile_id Profile ID.
	 */
	public static function get_public( $profile_id ) {
		$profile_id = absint( $profile_id );
		$post = get_post( $profile_id );
		if ( ! $post || 'lnkup_vendor_profile' !== $post->post_type ) {
			return null;
		}
		$reputation = LNKup_Vendor_Reputation::get( $profile_id );
		$logo_id    = (int) get_post_meta( $profile_id, '_lnkup_logo_attachment_id', true );
		$cover_id   = (int) get_post_meta( $profile_id, '_lnkup_cover_image_id', true );
		$gallery    = array();
		if ( class_exists( 'LNKup_Growth_Profile_Strength' ) ) {
			foreach ( LNKup_Growth_Profile_Strength::gallery_ids( $profile_id ) as $gid ) {
				$url = wp_get_attachment_image_url( (int) $gid, 'large' );
				if ( $url ) {
					$gallery[] = $url;
				}
			}
		}
		$cities = json_decode( get_post_meta( $profile_id, '_lnkup_cities_served', true ) ?: '[]', true ) ?: array();
		$states = json_decode( get_post_meta( $profile_id, '_lnkup_states_served', true ) ?: '[]', true ) ?: array();
		$verification = class_exists( 'LNKup_Verification' )
			? LNKup_Verification::get_vendor_verification_status( $profile_id, array( 'public' => true ) )
			: array();
		$metrics = $reputation['metrics'] ?? ( class_exists( 'LNKup_Reliability_Metrics' ) ? LNKup_Reliability_Metrics::get( $profile_id ) : array() );
		$data = array(
			'profile_id'        => $profile_id,
			'business_name'     => get_post_meta( $profile_id, '_lnkup_business_name', true ) ?: $post->post_title,
			'description'       => $post->post_content,
			'city'              => get_post_meta( $profile_id, '_lnkup_city', true ),
			'state'             => get_post_meta( $profile_id, '_lnkup_state', true ),
			'categories'        => wp_get_post_terms( $profile_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) ),
			'products_services' => get_post_meta( $profile_id, '_lnkup_products_services', true ),
			'years_in_business' => get_post_meta( $profile_id, '_lnkup_years_in_business', true ),
			'website'           => get_post_meta( $profile_id, '_lnkup_website', true ),
			'instagram'         => get_post_meta( $profile_id, '_lnkup_instagram', true ),
			'tiktok'            => get_post_meta( $profile_id, '_lnkup_tiktok', true ),
			'logo'              => $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '',
			'cover'             => $cover_id ? wp_get_attachment_image_url( $cover_id, 'large' ) : '',
			'gallery'           => $gallery,
			'cities_served'     => $cities,
			'states_served'     => $states,
			'setup_type'        => get_post_meta( $profile_id, '_lnkup_setup_type', true ),
			'power_needs'       => get_post_meta( $profile_id, '_lnkup_power_needed', true ),
			'water_needs'       => get_post_meta( $profile_id, '_lnkup_water_needed', true ),
			'tent_size'         => get_post_meta( $profile_id, '_lnkup_tent_size', true ),
			'trailer_size'      => get_post_meta( $profile_id, '_lnkup_trailer_size', true ),
			'verification'      => $verification,
			'reputation'        => $reputation,
			'metrics'           => $metrics,
			'reviews'           => LNKup_Vendor_Reviews::get_for_vendor( $profile_id, 10 ),
			'events'            => LNKup_Completed_Events::get_for_vendor( $profile_id, 'public' ),
			'public_slug'       => get_post_meta( $profile_id, '_lnkup_public_slug', true ),
		);
		if ( is_wp_error( $data['categories'] ) ) {
			$data['categories'] = array();
		}
		return apply_filters( 'lnkup_vendor_profile_data', $data, 'public' );
	}

	/**
	 * Compute vendor score 0-100 (legacy fallback).
	 */
	private static function compute_score( $completed, $flags, $docs, $rel ) {
		$score = 50;
		$score += min( 30, $completed * 5 );
		if ( ! empty( $rel['is_preferred'] ) ) {
			$score += 10;
		}
		if ( ! empty( $rel['is_favorite'] ) ) {
			$score += 5;
		}
		$score -= min( 40, count( $flags ) * 10 );
		$approved = array_filter( $docs, function ( $d ) { return 'approved' === $d['status']; } );
		if ( $docs && count( $approved ) === count( $docs ) ) {
			$score += 10;
		}
		return max( 0, min( 100, $score ) );
	}

	/**
	 * Count no-shows for vendor with organizer.
	 */
	private static function no_show_count( $organizer_id, $vendor_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_lnkup_vendor_user_id' AND pm2.meta_value = %d
				INNER JOIN {$wpdb->postmeta} pm3 ON pm3.post_id = p.ID AND pm3.meta_key = '_lnkup_organizer_user_id' AND pm3.meta_value = %d
				WHERE p.post_type = 'lnkup_application' AND pm.meta_key = '_lnkup_no_show_at' AND pm.meta_value != ''",
				$vendor_id,
				$organizer_id
			)
		);
	}

	/**
	 * City and state, or the first cities and states served.
	 *
	 * @param array $profile Profile data.
	 */
	public static function location_label( $profile ) {
		$profile = is_array( $profile ) ? $profile : array();
		$city    = trim( (string) ( $profile['city'] ?? '' ) );
		$state   = trim( (string) ( $profile['state'] ?? '' ) );
		if ( '' === $city && '' === $state ) {
			$cities = array_values( array_filter( array_map( 'strval', (array) ( $profile['cities_served'] ?? array() ) ) ) );
			$states = array_values( array_filter( array_map( 'strval', (array) ( $profile['states_served'] ?? array() ) ) ) );
			$city   = trim( (string) ( $cities[0] ?? '' ) );
			$state  = trim( (string) ( $states[0] ?? '' ) );
		}
		if ( '' === $city ) {
			return $state;
		}
		return $state ? $city . ', ' . $state : $city;
	}

	/**
	 * Format profile as CRM card HTML.
	 *
	 * @param array $profile Profile data.
	 */
	public static function render_card( $profile ) {
		if ( ! $profile ) {
			return '';
		}
		$initials = '';
		foreach ( preg_split( '/\s+/', trim( (string) $profile['business_name'] ) ) as $part ) {
			$initials .= strtoupper( substr( $part, 0, 1 ) );
			if ( strlen( $initials ) >= 2 ) {
				break;
			}
		}
		$categories = array();
		foreach ( array_slice( (array) ( $profile['category'] ?? array() ), 0, 3 ) as $category ) {
			$label = is_array( $category ) ? (string) ( $category['name'] ?? '' ) : (string) $category;
			$label = trim( $label );
			if ( '' !== $label ) {
				$categories[] = $label;
			}
		}
		$location   = self::location_label( $profile );
		$sell       = trim( (string) ( $profile['products_services'] ?? '' ) );
		$profile_id = absint( $profile['profile_id'] ?? 0 );
		$profile_url = '';
		if ( $profile_id && function_exists( 'lnkup_page_url_helper' ) ) {
			$profile_url = add_query_arg( 'vendor', $profile_id, lnkup_page_url_helper( 'organizer-dashboard' ) );
		}
		ob_start();
		?>
		<article class="lnkup-vendor-card lnkup-crm-card" data-profile-id="<?php echo esc_attr( (string) $profile_id ); ?>">
			<div class="lnkup-vendor-card__header lnkup-crm-card__header">
				<?php if ( ! empty( $profile['logo'] ) ) : ?>
					<img src="<?php echo esc_url( $profile['logo'] ); ?>" alt="" class="lnkup-vendor-card__avatar lnkup-crm-card__avatar" />
				<?php else : ?>
					<span class="lnkup-vendor-card__avatar lnkup-crm-card__avatar lnkup-crm-card__avatar--placeholder"><?php echo esc_html( $initials ?: 'V' ); ?></span>
				<?php endif; ?>
				<div>
					<h3 class="lnkup-vendor-card__name">
						<?php if ( $profile_url ) : ?>
							<a href="<?php echo esc_url( $profile_url ); ?>"><?php echo esc_html( $profile['business_name'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $profile['business_name'] ); ?>
						<?php endif; ?>
					</h3>
					<p class="lnkup-muted"><?php echo esc_html( $location ? $location : __( 'Location not listed.', 'lnkup-marketplace' ) ); ?></p>
				</div>
			</div>
			<?php
			$completed_events = (int) ( $profile['reliability']['completed_events'] ?? $profile['completed_events'] ?? 0 );
			?>
			<?php if ( $categories ) : ?>
				<ul class="lnkup-vendor-card__chips" aria-label="<?php esc_attr_e( 'Categories', 'lnkup-marketplace' ); ?>">
					<?php foreach ( $categories as $category ) : ?>
						<li class="lnkup-badge lnkup-badge--outline"><?php echo esc_html( $category ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php elseif ( $sell ) : ?>
				<p class="lnkup-muted"><?php echo esc_html( wp_trim_words( $sell, 12, '…' ) ); ?></p>
			<?php else : ?>
				<p class="lnkup-muted"><?php esc_html_e( 'Category not listed.', 'lnkup-marketplace' ); ?></p>
			<?php endif; ?>
			<?php if ( $completed_events > 0 ) : ?>
				<p class="lnkup-muted"><?php echo esc_html( sprintf( _n( '%d event', '%d events', $completed_events, 'lnkup-marketplace' ), $completed_events ) ); ?></p>
			<?php endif; ?>
			<div class="lnkup-vendor-card__actions lnkup-crm-card__actions">
				<?php if ( $profile_url ) : ?>
					<a class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'View', 'lnkup-marketplace' ); ?></a>
				<?php endif; ?>
				<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline lnkup-crm-invite" data-vendor-id="<?php echo esc_attr( (string) $profile['vendor_user_id'] ); ?>" data-vendor-name="<?php echo esc_attr( $profile['business_name'] ); ?>" data-profile-id="<?php echo esc_attr( (string) $profile_id ); ?>"><?php esc_html_e( 'Invite', 'lnkup-marketplace' ); ?></button>
			</div>
		</article>
		<?php
		return ob_get_clean();
	}
}
