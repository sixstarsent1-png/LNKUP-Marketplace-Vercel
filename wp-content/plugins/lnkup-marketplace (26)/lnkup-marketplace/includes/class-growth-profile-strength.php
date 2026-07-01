<?php
/**
 * Vendor profile strength checklist.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Growth_Profile_Strength {

	const TIER_STARTED       = 'Started';
	const TIER_NEEDS_WORK    = 'Needs Work';
	const TIER_GOOD          = 'Good';
	const TIER_STRONG        = 'Strong';
	const TIER_BOOKING_READY = 'Booking Ready';

	/**
	 * Full profile strength payload for dashboard, REST, and profile editor.
	 *
	 * @param int $profile_id Vendor profile post ID.
	 * @return array<string, mixed>
	 */
	public static function calculate( int $profile_id ): array {
		$user_id = $profile_id ? (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true ) : 0;
		$sections = self::build_sections( $profile_id, $user_id );

		$score              = 0;
		$completed_sections = 0;
		$total_sections     = count( $sections );
		$missing_items      = array();
		$recommended        = array();

		foreach ( $sections as $section ) {
			$score += (int) ( $section['points_earned'] ?? 0 );
			if ( ! empty( $section['complete'] ) ) {
				++$completed_sections;
			}
			foreach ( (array) ( $section['missing'] ?? array() ) as $missing ) {
				$missing_items[] = $missing;
			}
			foreach ( (array) ( $section['actions'] ?? array() ) as $action ) {
				if ( empty( $action['complete'] ) ) {
					$recommended[] = $action;
				}
			}
		}

		$score = min( 100, max( 0, $score ) );
		usort(
			$recommended,
			function ( $a, $b ) {
				return (int) ( $b['priority'] ?? 0 ) <=> (int) ( $a['priority'] ?? 0 );
			}
		);
		$recommended = array_slice( $recommended, 0, 10 );

		return array(
			'score'               => $score,
			'tier'                => self::tier_for_score( $score ),
			'completed_sections'  => $completed_sections,
			'total_sections'      => $total_sections,
			'missing_items'       => array_values( array_unique( $missing_items ) ),
			'recommended_actions' => array_values( array_map(
				function ( $action ) {
					unset( $action['complete'], $action['priority'] );
					return $action;
				},
				$recommended
			) ),
			'sections'            => $sections,
			'profile_id'          => $profile_id,
		);
	}

	/**
	 * Legacy checklist items (growth tab compatibility).
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function checklist( $user_id = 0 ) {
		$user_id    = $user_id ?: get_current_user_id();
		$profile_id = lnkup_get_vendor_profile_id( $user_id );
		$calc       = self::calculate( (int) $profile_id );
		$items      = array();

		foreach ( $calc['sections'] as $section ) {
			$items[] = array(
				'key'      => $section['key'],
				'label'    => $section['title'],
				'weight'   => $section['max_points'],
				'complete' => ! empty( $section['complete'] ),
				'tip'      => ! empty( $section['complete'] ) ? '' : ( $section['status_label'] ?? '' ),
			);
		}
		return $items;
	}

	/**
	 * Legacy score wrapper.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, mixed>
	 */
	public static function score( $user_id = 0 ) {
		$user_id    = $user_id ?: get_current_user_id();
		$profile_id = lnkup_get_vendor_profile_id( $user_id );
		$calc       = self::calculate( (int) $profile_id );
		$items      = self::checklist( $user_id );

		return array(
			'score'               => $calc['score'],
			'tier'                => $calc['tier'],
			'items'               => $items,
			'incomplete'          => array_values( array_filter( $items, function ( $i ) {
				return empty( $i['complete'] );
			} ) ),
			'completed_sections'  => $calc['completed_sections'],
			'total_sections'      => $calc['total_sections'],
			'missing_items'       => $calc['missing_items'],
			'recommended_actions' => $calc['recommended_actions'],
			'sections'            => $calc['sections'],
		);
	}

	/**
	 * @param int $score Score 0-100.
	 */
	public static function tier_for_score( int $score ): string {
		if ( $score >= 90 ) {
			return self::TIER_BOOKING_READY;
		}
		if ( $score >= 75 ) {
			return self::TIER_STRONG;
		}
		if ( $score >= 50 ) {
			return self::TIER_GOOD;
		}
		if ( $score >= 25 ) {
			return self::TIER_NEEDS_WORK;
		}
		return self::TIER_STARTED;
	}

	/**
	 * @param int $profile_id Profile ID.
	 * @param int $user_id    Owner user ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_sections( int $profile_id, int $user_id ): array {
		return array(
			self::section_business_basics( $profile_id ),
			self::section_contact_socials( $profile_id, $user_id ),
			self::section_markets_served( $profile_id ),
			self::section_setup_requirements( $profile_id ),
			self::section_photos_proof( $profile_id ),
			self::section_documents( $user_id ),
			self::section_trust_reputation( $profile_id, $user_id ),
			self::section_booking_preferences( $profile_id ),
		);
	}

	/**
	 * @param int    $profile_id Profile ID.
	 * @param string $key        Section key.
	 * @param string $title      Section title.
	 * @param int    $max        Max points.
	 * @param int    $earned     Earned points.
	 * @param array  $missing    Missing labels.
	 * @param array  $actions    Recommended actions.
	 * @return array<string, mixed>
	 */
	private static function section_result( int $profile_id, string $key, string $title, int $max, int $earned, array $missing, array $actions ): array {
		$complete = $earned >= $max;
		$status   = $complete ? 'complete' : ( $earned > 0 ? 'in_progress' : 'missing' );
		return array(
			'key'           => $key,
			'title'         => $title,
			'max_points'    => $max,
			'points_earned' => min( $max, $earned ),
			'complete'      => $complete,
			'status'        => $status,
			'status_label'  => self::section_status_label( $status ),
			'missing'       => $missing,
			'actions'       => $actions,
		);
	}

	/**
	 * @param string $status Section status key.
	 */
	private static function section_status_label( string $status ): string {
		switch ( $status ) {
			case 'complete':
				return function_exists( 'lnkup_copy' ) ? lnkup_copy( 'profile_section_complete' ) : __( 'Complete', 'lnkup-marketplace' );
			case 'in_progress':
				return function_exists( 'lnkup_copy' ) ? lnkup_copy( 'profile_section_missing' ) : __( 'Missing info', 'lnkup-marketplace' );
			default:
				return function_exists( 'lnkup_copy' ) ? lnkup_copy( 'profile_section_missing' ) : __( 'Missing info', 'lnkup-marketplace' );
		}
	}

	/**
	 * @param int $profile_id Profile ID.
	 * @return array<string, mixed>
	 */
	private static function section_business_basics( int $profile_id ): array {
		$max     = 20;
		$earned  = 0;
		$missing = array();
		$actions = array();

		if ( ! $profile_id ) {
			return self::section_result( $profile_id, 'business_basics', __( 'Business Basics', 'lnkup-marketplace' ), $max, 0, array( __( 'Create your vendor profile', 'lnkup-marketplace' ) ), array(
				self::action( 'create_profile', __( 'Start profile', 'lnkup-marketplace' ), __( 'Add your business name and description to get started.', 'lnkup-marketplace' ), '#section-business-basics', 10 ),
			) );
		}

		$post = get_post( $profile_id );
		if ( (string) get_post_meta( $profile_id, '_lnkup_business_name', true ) !== '' ) {
			$earned += 3;
		} else {
			$missing[] = __( 'Add business name', 'lnkup-marketplace' );
			$actions[] = self::action( 'business_name', __( 'Add business name', 'lnkup-marketplace' ), __( 'Organizers need your business name to recognize you.', 'lnkup-marketplace' ), '#section-business-basics', 9 );
		}

		if ( $post && strlen( wp_strip_all_tags( $post->post_content ) ) >= 50 ) {
			$earned += 3;
		} else {
			$missing[] = __( 'Add business description', 'lnkup-marketplace' );
			$actions[] = self::action( 'description', __( 'Write your story', 'lnkup-marketplace' ), __( 'Tell organizers what makes your booth special.', 'lnkup-marketplace' ), '#section-business-basics', 8 );
		}

		$terms = wp_get_post_terms( $profile_id, 'lnkup_vendor_category' );
		if ( ! is_wp_error( $terms ) && count( $terms ) > 0 ) {
			$earned += 3;
		} else {
			$missing[] = __( 'Select vendor categories', 'lnkup-marketplace' );
			$actions[] = self::action( 'categories', __( 'Add categories', 'lnkup-marketplace' ), __( 'Categories help you match with the right events.', 'lnkup-marketplace' ), '#section-business-basics', 7 );
		}

		if ( (string) get_post_meta( $profile_id, '_lnkup_products_services', true ) !== '' ) {
			$earned += 3;
		} else {
			$missing[] = __( 'List products or services', 'lnkup-marketplace' );
			$actions[] = self::action( 'products', __( 'Add products/services', 'lnkup-marketplace' ), __( 'Show organizers what you sell at events.', 'lnkup-marketplace' ), '#section-business-basics', 6 );
		}

		if ( (int) get_post_meta( $profile_id, '_lnkup_years_in_business', true ) > 0 ) {
			$earned += 2;
		} else {
			$missing[] = __( 'Add years in business', 'lnkup-marketplace' );
		}

		if ( self::has_logo( $profile_id ) ) {
			$earned += 3;
		} else {
			$missing[] = __( 'Upload logo', 'lnkup-marketplace' );
			$actions[] = self::action( 'logo', __( 'Upload logo', 'lnkup-marketplace' ), __( 'A logo helps organizers recognize your brand.', 'lnkup-marketplace' ), '#section-business-basics', 5 );
		}

		if ( (int) get_post_meta( $profile_id, '_lnkup_cover_image_id', true ) > 0 ) {
			$earned += 3;
		} else {
			$missing[] = __( 'Add cover photo', 'lnkup-marketplace' );
			$actions[] = self::action( 'cover', __( 'Add cover photo', 'lnkup-marketplace' ), __( 'Cover photos make your profile stand out.', 'lnkup-marketplace' ), '#section-business-basics', 4 );
		}

		return self::section_result( $profile_id, 'business_basics', __( 'Business Basics', 'lnkup-marketplace' ), $max, $earned, $missing, $actions );
	}

	/**
	 * @param int $profile_id Profile ID.
	 * @param int $user_id    User ID.
	 * @return array<string, mixed>
	 */
	private static function section_contact_socials( int $profile_id, int $user_id ): array {
		$max     = 10;
		$earned  = 0;
		$missing = array();
		$actions = array();

		if ( ! $profile_id ) {
			return self::section_result( $profile_id, 'contact_socials', __( 'Contact & Socials', 'lnkup-marketplace' ), $max, 0, array(), array() );
		}

		$checks = array(
			array( 'key' => 'contact_email', 'meta' => '_lnkup_contact_email', 'pts' => 3, 'label' => __( 'Add contact email', 'lnkup-marketplace' ) ),
			array( 'key' => 'contact_phone', 'meta' => '_lnkup_contact_phone', 'pts' => 3, 'label' => __( 'Add contact phone', 'lnkup-marketplace' ) ),
			array( 'key' => 'website', 'meta' => '_lnkup_website', 'pts' => 2, 'label' => __( 'Add website', 'lnkup-marketplace' ) ),
			array( 'key' => 'instagram', 'meta' => '_lnkup_instagram', 'pts' => 1, 'label' => __( 'Add Instagram', 'lnkup-marketplace' ) ),
			array( 'key' => 'tiktok', 'meta' => '_lnkup_tiktok', 'pts' => 1, 'label' => __( 'Add TikTok', 'lnkup-marketplace' ) ),
		);

		foreach ( $checks as $check ) {
			$val = (string) get_post_meta( $profile_id, $check['meta'], true );
			if ( 'contact_email' === $check['key'] && ! $val ) {
				$user = get_userdata( $user_id );
				$val  = $user ? (string) $user->user_email : '';
			}
			if ( $val !== '' ) {
				$earned += (int) $check['pts'];
			} else {
				$missing[] = $check['label'];
			}
		}

		if ( $earned < $max ) {
			$actions[] = self::action( 'contact', __( 'Add contact details', 'lnkup-marketplace' ), __( 'Organizers reach out faster when contact info is complete.', 'lnkup-marketplace' ), '#section-contact-socials', 5 );
		}

		return self::section_result( $profile_id, 'contact_socials', __( 'Contact & Socials', 'lnkup-marketplace' ), $max, $earned, $missing, $actions );
	}

	/**
	 * @param int $profile_id Profile ID.
	 * @return array<string, mixed>
	 */
	private static function section_markets_served( int $profile_id ): array {
		$max     = 10;
		$earned  = 0;
		$missing = array();
		$actions = array();

		if ( ! $profile_id ) {
			return self::section_result( $profile_id, 'markets_served', __( 'Markets Served', 'lnkup-marketplace' ), $max, 0, array(), array() );
		}

		if ( (string) get_post_meta( $profile_id, '_lnkup_city', true ) !== '' || (string) get_post_meta( $profile_id, '_lnkup_state', true ) !== '' ) {
			$earned += 4;
		} else {
			$missing[] = __( 'Add home city/state', 'lnkup-marketplace' );
		}

		$cities = self::decode_json_list( get_post_meta( $profile_id, '_lnkup_cities_served', true ) );
		if ( ! empty( $cities ) ) {
			$earned += 3;
		} else {
			$missing[] = __( 'Add cities served', 'lnkup-marketplace' );
		}

		$states = self::decode_json_list( get_post_meta( $profile_id, '_lnkup_states_served', true ) );
		if ( ! empty( $states ) ) {
			$earned += 3;
		} else {
			$missing[] = __( 'Add states served', 'lnkup-marketplace' );
		}

		if ( $earned < $max ) {
			$actions[] = self::action( 'markets', __( 'Add markets served', 'lnkup-marketplace' ), __( 'Tell organizers where you are willing to vend.', 'lnkup-marketplace' ), '#section-markets-served', 5 );
		}

		return self::section_result( $profile_id, 'markets_served', __( 'Markets Served', 'lnkup-marketplace' ), $max, $earned, $missing, $actions );
	}

	/**
	 * @param int $profile_id Profile ID.
	 * @return array<string, mixed>
	 */
	private static function section_setup_requirements( int $profile_id ): array {
		$max     = 15;
		$earned  = 0;
		$missing = array();
		$actions = array();

		if ( ! $profile_id ) {
			return self::section_result( $profile_id, 'setup_requirements', __( 'Setup Requirements', 'lnkup-marketplace' ), $max, 0, array(), array() );
		}

		if ( (string) get_post_meta( $profile_id, '_lnkup_setup_type', true ) !== '' ) {
			$earned += 5;
		} else {
			$missing[] = __( 'Add setup type', 'lnkup-marketplace' );
			$actions[] = self::action( 'setup', __( 'Add setup requirements', 'lnkup-marketplace' ), __( 'Organizers need to know your space and power needs.', 'lnkup-marketplace' ), '#section-setup-requirements', 7 );
		}

		$detail_fields = array(
			'_lnkup_tent_size'         => 2,
			'_lnkup_trailer_size'      => 2,
			'_lnkup_food_truck_size'   => 2,
			'_lnkup_space_needed'      => 2,
			'_lnkup_power_needed'      => 2,
			'_lnkup_water_needed'      => 2,
			'_lnkup_has_generator'     => 1,
			'_lnkup_indoor_outdoor_preference' => 2,
		);
		$detail_earned = 0;
		foreach ( $detail_fields as $meta_key => $pts ) {
			$val = get_post_meta( $profile_id, $meta_key, true );
			if ( '_lnkup_has_generator' === $meta_key ) {
				if ( '' !== $val && null !== $val ) {
					$detail_earned += $pts;
				}
			} elseif ( (string) $val !== '' ) {
				$detail_earned += $pts;
			}
		}
		$earned += min( 10, $detail_earned );

		if ( $detail_earned < 4 ) {
			$missing[] = __( 'Add setup details (tent, power, space)', 'lnkup-marketplace' );
		}

		return self::section_result( $profile_id, 'setup_requirements', __( 'Setup Requirements', 'lnkup-marketplace' ), $max, $earned, $missing, $actions );
	}

	/**
	 * @param int $profile_id Profile ID.
	 * @return array<string, mixed>
	 */
	private static function section_photos_proof( int $profile_id ): array {
		$max     = 15;
		$earned  = 0;
		$missing = array();
		$actions = array();
		$gallery = self::gallery_ids( $profile_id );
		$count   = count( $gallery );

		if ( $count >= 3 ) {
			$earned = $max;
		} elseif ( $count >= 1 ) {
			$earned = 8;
			$missing[] = sprintf( __( 'Add %d more photos', 'lnkup-marketplace' ), 3 - $count );
			$actions[] = self::action( 'photos', __( 'Add photos', 'lnkup-marketplace' ), __( 'Profiles with photos are easier for organizers to approve.', 'lnkup-marketplace' ), '#section-photos-proof', 8 );
		} else {
			$missing[] = __( 'Add at least 3 photos', 'lnkup-marketplace' );
			$actions[] = self::action( 'photos', __( 'Upload photos', 'lnkup-marketplace' ), __( 'Show your booth, products, and past events.', 'lnkup-marketplace' ), '#section-photos-proof', 9 );
		}

		return self::section_result( $profile_id, 'photos_proof', __( 'Photos & Proof', 'lnkup-marketplace' ), $max, $earned, $missing, $actions );
	}

	/**
	 * @param int $user_id User ID.
	 * @return array<string, mixed>
	 */
	private static function section_documents( int $user_id ): array {
		$max     = 15;
		$earned  = 0;
		$missing = array();
		$actions = array();

		if ( self::doc_verified( $user_id, 'certificate_of_insurance' ) ) {
			$earned += 8;
		} else {
			$missing[] = __( 'Upload Certificate of Insurance', 'lnkup-marketplace' );
			$actions[] = self::action( 'coi', __( 'Upload insurance', 'lnkup-marketplace' ), __( 'Verified insurance speeds up approvals.', 'lnkup-marketplace' ), '#section-documents', 10 );
		}

		$other_types = array( 'business_license', 'health_permit', 'w9', 'sales_tax_certificate' );
		$other_count = 0;
		foreach ( $other_types as $type ) {
			if ( self::doc_verified( $user_id, $type ) ) {
				++$other_count;
			}
		}
		if ( $other_count >= 1 ) {
			$earned += 7;
		} else {
			$missing[] = __( 'Upload at least one compliance document', 'lnkup-marketplace' );
			$actions[] = self::action( 'documents', __( 'Upload documents', 'lnkup-marketplace' ), __( 'Keep licenses and permits current.', 'lnkup-marketplace' ), '#section-documents', 9 );
		}

		return self::section_result( 0, 'documents', __( 'Documents', 'lnkup-marketplace' ), $max, $earned, $missing, $actions );
	}

	/**
	 * @param int $profile_id Profile ID.
	 * @param int $user_id    User ID.
	 * @return array<string, mixed>
	 */
	private static function section_trust_reputation( int $profile_id, int $user_id ): array {
		$max     = 10;
		$earned  = 0;
		$missing = array();
		$actions = array();

		if ( ! $profile_id ) {
			return self::section_result( $profile_id, 'trust_reputation', __( 'Trust & Reputation', 'lnkup-marketplace' ), $max, 0, array(), array() );
		}

		if ( class_exists( 'LNKup_Vendor_Badges' ) ) {
			$badges = LNKup_Vendor_Badges::get_active_slugs( $profile_id );
			if ( in_array( 'verified_vendor', $badges, true ) ) {
				$earned += 4;
			}
		}

		$rep = class_exists( 'LNKup_Vendor_Reputation' ) ? LNKup_Vendor_Reputation::get( $profile_id ) : array();
		if ( (int) ( $rep['review_count'] ?? 0 ) > 0 ) {
			$earned += 3;
		}
		if ( (int) ( $rep['completed_events'] ?? 0 ) > 0 ) {
			$earned += 2;
		}

		// New vendors: baseline credit when profile exists and reputation meta is present.
		if ( $earned < 4 && ! empty( $rep['updated_at'] ) ) {
			$earned = max( $earned, 4 );
		} elseif ( $profile_id && $earned < 2 ) {
			$earned = 2;
		}

		if ( (int) ( $rep['review_count'] ?? 0 ) === 0 ) {
			$missing[] = __( 'Earn reviews after your first events', 'lnkup-marketplace' );
		}

		return self::section_result( $profile_id, 'trust_reputation', __( 'Trust & Reputation', 'lnkup-marketplace' ), $max, min( $max, $earned ), $missing, $actions );
	}

	/**
	 * @param int $profile_id Profile ID.
	 * @return array<string, mixed>
	 */
	private static function section_booking_preferences( int $profile_id ): array {
		$max     = 5;
		$earned  = 0;
		$missing = array();
		$actions = array();

		if ( ! $profile_id ) {
			return self::section_result( $profile_id, 'booking_preferences', __( 'Booking Preferences', 'lnkup-marketplace' ), $max, 0, array(), array() );
		}

		$notes = (string) get_post_meta( $profile_id, '_lnkup_availability_notes', true );
		$min   = (float) get_post_meta( $profile_id, '_lnkup_preferred_fee_min', true );
		$max_fee = (float) get_post_meta( $profile_id, '_lnkup_preferred_fee_max', true );

		if ( $notes !== '' ) {
			$earned += 3;
		}
		if ( $min > 0 || $max_fee > 0 ) {
			$earned += 2;
		}

		if ( $earned < $max ) {
			$missing[] = __( 'Add availability or fee preferences', 'lnkup-marketplace' );
			$actions[] = self::action( 'booking', __( 'Set booking preferences', 'lnkup-marketplace' ), __( 'Help organizers understand your ideal opportunities.', 'lnkup-marketplace' ), '#section-booking-preferences', 4 );
		}

		return self::section_result( $profile_id, 'booking_preferences', __( 'Booking Preferences', 'lnkup-marketplace' ), $max, $earned, $missing, $actions );
	}

	/**
	 * @param string $key         Action key.
	 * @param string $label       CTA label.
	 * @param string $description Helper text.
	 * @param string $anchor      Section anchor.
	 * @param int    $priority    Sort priority.
	 * @return array<string, mixed>
	 */
	private static function action( string $key, string $label, string $description, string $anchor, int $priority ): array {
		return array(
			'key'         => $key,
			'label'       => $label,
			'description' => $description,
			'cta'         => $label,
			'cta_url'     => $anchor,
			'priority'    => $priority,
			'complete'    => false,
		);
	}

	/**
	 * @param int $profile_id Profile ID.
	 */
	public static function has_logo( int $profile_id ): bool {
		if ( ! $profile_id ) {
			return false;
		}
		if ( (int) get_post_meta( $profile_id, '_lnkup_logo_attachment_id', true ) > 0 ) {
			return true;
		}
				return (bool) get_post_thumbnail_id( $profile_id );
	}

	/**
	 * @param int $profile_id Profile ID.
	 * @return array<int, int>
	 */
	public static function gallery_ids( int $profile_id ): array {
		if ( ! $profile_id ) {
			return array();
		}
		return self::decode_json_list( get_post_meta( $profile_id, '_lnkup_gallery_ids', true ), true );
	}

	/**
	 * @param mixed $raw     Meta value.
	 * @param bool  $as_int  Cast to integers.
	 * @return array<int, mixed>
	 */
	private static function decode_json_list( $raw, bool $as_int = false ): array {
		if ( is_array( $raw ) ) {
			$list = $raw;
		} elseif ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			$list    = is_array( $decoded ) ? $decoded : array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		} else {
			$list = array();
		}
		if ( $as_int ) {
			return array_values( array_filter( array_map( 'absint', $list ) ) );
		}
		return array_values( array_filter( array_map( 'strval', $list ) ) );
	}

	/**
	 * @param int    $user_id User ID.
	 * @param string $type    Document type slug.
	 */
	public static function doc_verified( $user_id, $type ) {
		foreach ( LNKup_Documents::get_for_vendor( $user_id ) as $doc ) {
			if ( $type !== get_post_meta( $doc->ID, '_lnkup_document_type', true ) ) {
				continue;
			}
			$status = get_post_meta( $doc->ID, '_lnkup_verification_status', true );
			if ( in_array( $status, array( 'approved', 'verified' ), true ) ) {
				return true;
			}
		}
		return false;
	}
}
