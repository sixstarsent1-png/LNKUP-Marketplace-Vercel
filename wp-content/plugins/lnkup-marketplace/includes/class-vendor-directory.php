<?php
/**
 * Organizer vendor directory.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Directory {

	/**
	 * Search vendors for organizer.
	 *
	 * @param int   $organizer_id Organizer user ID.
	 * @param array $args         Query args.
	 */
	public static function search( $organizer_id, $args = array() ) {
		$scope = sanitize_key( $args['scope'] ?? 'discover' );
		$view  = sanitize_key( $args['view'] ?? 'card' );
		$page  = max( 1, absint( $args['page'] ?? 1 ) );
		$per   = min( 48, max( 12, absint( $args['per_page'] ?? 24 ) ) );

		if ( 'my' === $scope ) {
			return self::search_my_vendors( $organizer_id, $args, $page, $per, $view );
		}
		if ( 'imported' === $scope ) {
			return self::search_imported_prospects( $organizer_id, $args, $page, $per, $view );
		}
		return self::search_discover( $organizer_id, $args, $page, $per, $view );
	}

	/**
	 * Discover marketplace-wide vendor profiles.
	 */
	private static function search_discover( $organizer_id, $args, $page, $per, $view ) {
		$query = lnkup_get_discoverable_vendors(
			array(
				'page'     => $page,
				'per_page' => $per,
				'q'        => $args['q'] ?? '',
				'city'     => $args['city'] ?? '',
				'state'    => $args['state'] ?? '',
				'category' => $args['category'] ?? '',
				'verified' => ! empty( $args['verified'] ) ? true : '',
				'organizer_id' => (int) $organizer_id,
				'sort'     => sanitize_key( (string) ( $args['sort'] ?? 'score' ) ),
			)
		);

		$items      = array();
		$vendor_ids = array();
		foreach ( $query['posts'] as $post ) {
			$vendor_ids[] = (int) get_post_meta( $post->ID, '_lnkup_owner_user_id', true );
		}
		LNKup_Organizer_CRM::prime_relationships( (int) $organizer_id, $vendor_ids );
		try {
			foreach ( $query['posts'] as $post ) {
				$profile = LNKup_Vendor_CRM_Profile::get( $post->ID, $organizer_id );
				if ( $profile ) {
					$items[] = lnkup_normalize_vendor_card( $profile );
				}
			}
		} finally {
			LNKup_Organizer_CRM::end_relationship_batch();
		}
		$items = self::sort_items( $items, $args );

		return array(
			'items'   => $items,
			'total'   => $query['total'],
			'page'    => $query['page'],
			'pages'   => $query['pages'],
			'html'    => self::render_items( $items, $view, 'discover' ),
			'scope'   => 'discover',
		);
	}

	/**
	 * My vendors from relationship table.
	 */
	private static function search_my_vendors( $organizer_id, $args, $page, $per, $view ) {
		global $wpdb;
		$table = LNKup_Organizer_CRM_Tables::rel_table();
		$where = $wpdb->prepare( 'organizer_user_id = %d', $organizer_id );
		if ( ! empty( $args['favorite'] ) ) {
			$where .= ' AND is_favorite = 1';
		}
		if ( ! empty( $args['preferred'] ) ) {
			$where .= ' AND is_preferred = 1';
		}
		$offset      = ( $page - 1 ) * $per;
		$has_filters = '' !== trim( (string) ( $args['q'] ?? '' ) ) || ! empty( $args['city'] ) || ! empty( $args['state'] ) || ! empty( $args['category'] ) || ! empty( $args['verified'] );
		$items       = array();
		if ( $has_filters ) {
			// Profile filters live on post meta, so filter the whole relationship set before paging.
			$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY last_activity_at DESC LIMIT 2000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $rows as $row ) {
				$profile = LNKup_Vendor_CRM_Profile::get( (int) $row['vendor_profile_id'], $organizer_id );
				if ( $profile && self::matches_filters( $profile, $args ) ) {
					$items[] = lnkup_normalize_vendor_card( $profile );
				}
			}
			$total = count( $items );
			$items = array_slice( self::sort_items( $items, $args ), $offset, $per );
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows  = $wpdb->get_results(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY last_activity_at DESC LIMIT {$offset}, {$per}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$profile = LNKup_Vendor_CRM_Profile::get( (int) $row['vendor_profile_id'], $organizer_id );
				if ( $profile ) {
					$items[] = lnkup_normalize_vendor_card( $profile );
				}
			}
			$items = self::sort_items( $items, $args );
		}
		return array(
			'items' => $items,
			'total' => $total,
			'page'  => $page,
			'pages' => (int) ceil( $total / $per ),
			'html'  => self::render_items( $items, $view, 'my' ),
			'scope' => 'my',
		);
	}

	/**
	 * Imported prospects (no LNKup account yet).
	 */
	private static function search_imported_prospects( $organizer_id, $args, $page, $per, $view ) {
		if ( ! class_exists( 'LNKup_Vendor_Import' ) ) {
			return array(
				'items' => array(),
				'total' => 0,
				'page'  => $page,
				'pages' => 0,
				'html'  => self::render_items( array(), $view, 'imported' ),
				'scope' => 'imported',
			);
		}

		$result = LNKup_Vendor_Import::list_prospects(
			$organizer_id,
			array(
				'page'     => $page,
				'per_page' => $per,
				'q'        => $args['q'] ?? '',
			)
		);

		$items = $result['items'];
		if ( ! empty( $args['category'] ) ) {
			$cat = sanitize_text_field( $args['category'] );
			$items = array_values(
				array_filter(
					$items,
					function ( $item ) use ( $cat ) {
						return stripos( (string) ( $item['category'] ?? '' ), $cat ) !== false;
					}
				)
			);
		}

		return array(
			'items' => $items,
			'total' => $result['total'],
			'page'  => $result['page'],
			'pages' => $result['pages'],
			'html'  => self::render_prospect_items( $items, $view ),
			'scope' => 'imported',
		);
	}

	/**
	 * Render prospect directory items.
	 *
	 * @param array  $items Prospect cards.
	 * @param string $view  View mode.
	 */
	private static function render_prospect_items( $items, $view ) {
		if ( ! $items ) {
			ob_start();
			?>
			<div class="lnkup-empty lnkup-empty--directory">
				<p><?php esc_html_e( 'No imported vendors yet.', 'lnkup-marketplace' ); ?></p>
				<p class="lnkup-muted"><?php esc_html_e( 'Use Import vendors to add prospects from a CSV spreadsheet.', 'lnkup-marketplace' ); ?></p>
			</div>
			<?php
			return ob_get_clean();
		}

		if ( 'table' === $view ) {
			$html = '<table class="lnkup-table lnkup-crm-table"><thead><tr><th>Business</th><th>Email</th><th>Category</th><th>City</th></tr></thead><tbody>';
			foreach ( $items as $p ) {
				$html .= '<tr><td>' . esc_html( $p['business_name'] ?: $p['contact_name'] ) . '</td><td>' . esc_html( $p['email'] ) . '</td><td>' . esc_html( $p['category'] ) . '</td><td>' . esc_html( $p['city'] ) . '</td></tr>';
			}
			return $html . '</tbody></table>';
		}

		if ( 'compact' === $view ) {
			$html = '<ul class="lnkup-crm-compact">';
			foreach ( $items as $p ) {
				$html .= '<li>' . esc_html( $p['business_name'] ?: $p['contact_name'] ) . ' <span class="lnkup-muted">' . esc_html( $p['email'] ) . '</span></li>';
			}
			return $html . '</ul>';
		}

		$html = '<div class="lnkup-crm-grid">';
		foreach ( $items as $p ) {
			$html .= LNKup_Vendor_Import::render_prospect_card_html( $p );
		}
		return $html . '</div>';
	}

	/**
	 * Apply WP_Query filters.
	 */
	private static function apply_filters( &$query_args, $args ) {
		if ( ! empty( $args['q'] ) ) {
			$query_args['s'] = sanitize_text_field( $args['q'] );
		}
		if ( ! empty( $args['city'] ) ) {
			$query_args['meta_query'][] = array(
				'key'   => '_lnkup_city',
				'value' => sanitize_text_field( $args['city'] ),
			);
		}
		if ( ! empty( $args['state'] ) ) {
			$query_args['meta_query'][] = array(
				'key'   => '_lnkup_state',
				'value' => sanitize_text_field( $args['state'] ),
			);
		}
		if ( ! empty( $args['category'] ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'lnkup_vendor_category',
					'field'    => 'name',
					'terms'    => sanitize_text_field( $args['category'] ),
				),
			);
		}
	}

	/**
	 * Post-filter for relationship results.
	 */
	private static function matches_filters( $profile, $args ) {
		if ( ! empty( $args['category'] ) && ! in_array( $args['category'], $profile['category'], true ) ) {
			return false;
		}
		if ( ! empty( $args['city'] ) && stripos( $profile['city'], $args['city'] ) === false ) {
			return false;
		}
		if ( ! empty( $args['tag'] ) && class_exists( 'LNKup_Vendor_Tags' ) ) {
			$slugs = wp_list_pluck( $profile['tags'], 'slug' );
			if ( ! in_array( sanitize_key( $args['tag'] ), $slugs, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Render directory items.
	 *
	 * @param array  $items Directory items.
	 * @param string $view  View mode.
	 * @param string $scope discover|my.
	 */
	private static function render_items( $items, $view, $scope = 'discover' ) {
		if ( ! $items ) {
			ob_start();
			?>
			<div class="lnkup-empty lnkup-empty--directory">
				<p><?php esc_html_e( 'No vendors found.', 'lnkup-marketplace' ); ?></p>
				<?php if ( 'discover' === $scope ) : ?>
					<p class="lnkup-muted"><?php esc_html_e( 'When vendors complete profiles they will appear here for marketplace-wide discovery.', 'lnkup-marketplace' ); ?></p>
					<a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="<?php echo esc_url( lnkup_page_url_helper( 'opportunities' ) ); ?>"><?php esc_html_e( 'Browse opportunities', 'lnkup-marketplace' ); ?></a>
				<?php else : ?>
					<p class="lnkup-muted"><?php esc_html_e( 'Vendors you work with will appear here after applications or invites.', 'lnkup-marketplace' ); ?></p>
					<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( lnkup_organizer_view_url( 'vendors' ) ); ?>"><?php esc_html_e( 'Discover vendors', 'lnkup-marketplace' ); ?></a>
				<?php endif; ?>
				<?php if ( 'imported' === $scope ) : ?>
					<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" data-testid="lnkup-import-vendors-btn"><?php esc_html_e( 'Import vendors', 'lnkup-marketplace' ); ?></button>
				<?php endif; ?>
			</div>
			<?php
			return ob_get_clean();
		}
		if ( 'table' === $view ) {
			$html = '<table class="lnkup-table lnkup-crm-table"><thead><tr><th>' . esc_html__( 'Vendor', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Category', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Organizer feedback', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Confirmed events', 'lnkup-marketplace' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $items as $p ) {
				$url = add_query_arg( 'vendor', $p['profile_id'], lnkup_page_url_helper( 'organizer-dashboard' ) );
				$html .= '<tr><td>' . esc_html( $p['business_name'] ) . '</td><td>' . esc_html( implode( ', ', $p['category'] ) ) . '</td><td>' . esc_html( (string) ( $p['indicators']['organizer_feedback']['label'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $p['indicators']['experience']['confirmed_events'] ?? $p['completed_events'] ) ) . '</td><td><a class="lnkup-btn lnkup-btn--sm" href="' . esc_url( $url ) . '">' . esc_html__( 'View', 'lnkup-marketplace' ) . '</a></td></tr>';
			}
			return $html . '</tbody></table>';
		}
		if ( 'compact' === $view ) {
			$html = '<ul class="lnkup-crm-compact">';
			foreach ( $items as $p ) {
				$url = add_query_arg( 'vendor', $p['profile_id'], lnkup_page_url_helper( 'organizer-dashboard' ) );
				$html .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $p['business_name'] ) . '</a> <span class="lnkup-muted">' . esc_html( (string) ( $p['indicators']['experience']['label'] ?? '' ) ) . '</span></li>';
			}
			return $html . '</ul>';
		}
		$html = '<div class="lnkup-crm-grid">';
		foreach ( $items as $p ) {
			$html .= LNKup_Vendor_CRM_Profile::render_card( $p );
		}
		return $html . '</div>';
	}

	/**
	 * Sort directory items.
	 */
	private static function sort_items( $items, $args ) {
		$sort = sanitize_key( $args['sort'] ?? 'score' );
		usort(
			$items,
			function ( $a, $b ) use ( $sort ) {
				switch ( $sort ) {
					case 'rating':
						// Only averages that meet the review minimum rank; others sort as neutral, never below.
						return (float) ( $b['indicators']['organizer_feedback']['average'] ?? 0 ) <=> (float) ( $a['indicators']['organizer_feedback']['average'] ?? 0 );
					case 'completed':
						return ( $b['indicators']['experience']['confirmed_events'] ?? $b['completed_events'] ?? 0 ) <=> ( $a['indicators']['experience']['confirmed_events'] ?? $a['completed_events'] ?? 0 );
					case 'reliable':
						return ( $b['vendor_score'] ?? 0 ) <=> ( $a['vendor_score'] ?? 0 );
					case 'risk':
						return ( $a['no_show_risk'] ?? 'low' ) <=> ( $b['no_show_risk'] ?? 'low' );
					case 'preferred':
						return ( ! empty( $b['is_preferred'] ) ) <=> ( ! empty( $a['is_preferred'] ) );
					case 'new':
						return ( $a['reputation']['is_new'] ?? false ) <=> ( $b['reputation']['is_new'] ?? false );
					default:
						return ( $b['vendor_score'] ?? 0 ) <=> ( $a['vendor_score'] ?? 0 );
				}
			}
		);
		return $items;
	}

	/**
	 * CSV header labels for a vendor contact list.
	 *
	 * @param bool $include_event Include event name and application status.
	 * @return string[]
	 */
	public static function contact_csv_headers( $include_event = false ) {
		$headers = array(
			__( 'Business name', 'lnkup-marketplace' ),
			__( 'Contact name', 'lnkup-marketplace' ),
			__( 'Categories', 'lnkup-marketplace' ),
			__( 'Email', 'lnkup-marketplace' ),
			__( 'Phone', 'lnkup-marketplace' ),
			__( 'City', 'lnkup-marketplace' ),
			__( 'State', 'lnkup-marketplace' ),
			__( 'Website', 'lnkup-marketplace' ),
		);
		if ( $include_event ) {
			$headers[] = __( 'Event', 'lnkup-marketplace' );
			$headers[] = __( 'Application status', 'lnkup-marketplace' );
		}
		return $headers;
	}

	/**
	 * One contact row from a vendor profile.
	 *
	 * Email falls back to the WordPress account email when the profile field is empty.
	 *
	 * @param int   $profile_id Profile post ID. 0 when unknown.
	 * @param array $context    Optional vendor_user_id, category_fallback, event_name, application_status.
	 * @return string[]
	 */
	public static function contact_row( $profile_id, $context = array() ) {
		$profile_id     = (int) $profile_id;
		$vendor_user_id = isset( $context['vendor_user_id'] ) ? (int) $context['vendor_user_id'] : 0;
		if ( ! $profile_id && $vendor_user_id && function_exists( 'lnkup_get_vendor_profile_id' ) ) {
			$profile_id = (int) lnkup_get_vendor_profile_id( $vendor_user_id );
		}
		if ( $profile_id && ! $vendor_user_id ) {
			$vendor_user_id = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		}

		$post = $profile_id ? get_post( $profile_id ) : null;
		$user = $vendor_user_id ? get_userdata( $vendor_user_id ) : false;

		$business = $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_business_name', true ) : '';
		if ( '' === $business && $post ) {
			$business = $post->post_title;
		}
		if ( '' === $business && $user ) {
			$business = $user->display_name;
		}

		$contact = $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_owner_name', true ) : '';
		if ( '' === $contact && $user ) {
			$contact = $user->display_name;
		}

		$categories = array();
		if ( $profile_id ) {
			$terms = wp_get_post_terms( $profile_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
				$categories = $terms;
			}
		}
		if ( ! $categories && ! empty( $context['category_fallback'] ) ) {
			$categories = array( (string) $context['category_fallback'] );
		}

		$email = $profile_id ? sanitize_email( (string) get_post_meta( $profile_id, '_lnkup_contact_email', true ) ) : '';
		if ( '' === $email && $user ) {
			$email = $user->user_email;
		}

		$row = array(
			$business,
			$contact,
			implode( '; ', $categories ),
			$email,
			$profile_id ? (string) get_post_meta( $profile_id, '_lnkup_contact_phone', true ) : '',
			$profile_id ? (string) get_post_meta( $profile_id, '_lnkup_city', true ) : '',
			$profile_id ? (string) get_post_meta( $profile_id, '_lnkup_state', true ) : '',
			$profile_id ? (string) get_post_meta( $profile_id, '_lnkup_website', true ) : '',
		);

		if ( array_key_exists( 'event_name', $context ) ) {
			$row[] = (string) $context['event_name'];
			$row[] = (string) ( $context['application_status'] ?? '' );
		}

		return $row;
	}

	/**
	 * Contact rows for every application on one event.
	 *
	 * @param int $event_id Event post ID.
	 * @return string[][]
	 */
	public static function contact_rows_for_event( $event_id ) {
		$event_id   = (int) $event_id;
		$event      = get_post( $event_id );
		$event_name = $event ? $event->post_title : '';
		$apps       = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$rows = array();
		foreach ( $apps as $app ) {
			$rows[] = self::contact_row(
				(int) get_post_meta( $app->ID, '_lnkup_vendor_profile_id', true ),
				array(
					'vendor_user_id'    => (int) get_post_meta( $app->ID, '_lnkup_vendor_user_id', true ),
					'category_fallback'  => (string) get_post_meta( $app->ID, '_lnkup_selected_category', true ),
					'event_name'         => $event_name,
					'application_status' => (string) get_post_meta( $app->ID, '_lnkup_application_status', true ),
				)
			);
		}
		return $rows;
	}

	/**
	 * Contact rows for every published vendor profile.
	 *
	 * @param string $category Optional vendor category name. Unknown names return no rows.
	 * @return string[][]
	 */
	public static function contact_rows_for_platform( $category = '' ) {
		if ( ! LNKup_Capabilities::user_has_manage_all() && ! current_user_can( 'manage_options' ) ) {
			return array();
		}
		$category = sanitize_text_field( (string) $category );
		$args     = array(
			'post_type'      => 'lnkup_vendor_profile',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);
		if ( '' !== $category ) {
			if ( ! term_exists( $category, 'lnkup_vendor_category' ) ) {
				return array();
			}
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'lnkup_vendor_category',
					'field'    => 'name',
					'terms'    => $category,
				),
			);
		}

		$rows = array();
		foreach ( get_posts( $args ) as $profile_id ) {
			$rows[] = self::contact_row( (int) $profile_id );
		}
		return $rows;
	}

	/**
	 * Stream a contact CSV and stop the request.
	 *
	 * @param string[][] $rows          Data rows.
	 * @param string     $filename      Download filename.
	 * @param bool       $include_event Include event columns.
	 */
	public static function stream_contact_csv( $rows, $filename, $include_event = false ) {
		$filename = sanitize_file_name( (string) $filename );
		if ( '' === $filename ) {
			$filename = 'vendor-contacts.csv';
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		echo "\xEF\xBB\xBF";
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $out ) {
			wp_die( esc_html__( 'Could not create the export.', 'lnkup-marketplace' ), 500 );
		}
		fputcsv( $out, self::contact_csv_headers( $include_event ) );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
