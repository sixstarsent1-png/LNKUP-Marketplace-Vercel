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
			)
		);

		$items = array();
		foreach ( $query['posts'] as $post ) {
			$profile = LNKup_Vendor_CRM_Profile::get( $post->ID, $organizer_id );
			if ( $profile ) {
				$items[] = lnkup_normalize_vendor_card( $profile );
			}
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
		$offset = ( $page - 1 ) * $per;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE {$where} ORDER BY last_activity_at DESC LIMIT {$offset}, {$per}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$items = array();
		foreach ( (array) $rows as $row ) {
			$profile = LNKup_Vendor_CRM_Profile::get( (int) $row['vendor_profile_id'], $organizer_id );
			if ( $profile && self::matches_filters( $profile, $args ) ) {
				$items[] = lnkup_normalize_vendor_card( $profile );
			}
		}
		$items = self::sort_items( $items, $args );
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
					<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( lnkup_page_url_helper( 'organizer-dashboard' ) . '#vendors' ); ?>"><?php esc_html_e( 'Discover vendors', 'lnkup-marketplace' ); ?></a>
				<?php endif; ?>
				<?php if ( 'imported' === $scope ) : ?>
					<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" data-testid="lnkup-import-vendors-btn"><?php esc_html_e( 'Import vendors', 'lnkup-marketplace' ); ?></button>
				<?php endif; ?>
			</div>
			<?php
			return ob_get_clean();
		}
		if ( 'table' === $view ) {
			$html = '<table class="lnkup-table lnkup-crm-table"><thead><tr><th>Vendor</th><th>Category</th><th>Score</th><th>Events</th><th></th></tr></thead><tbody>';
			foreach ( $items as $p ) {
				$url = add_query_arg( 'vendor', $p['profile_id'], lnkup_page_url_helper( 'organizer-dashboard' ) );
				$html .= '<tr><td>' . esc_html( $p['business_name'] ) . '</td><td>' . esc_html( implode( ', ', $p['category'] ) ) . '</td><td>' . esc_html( (string) $p['vendor_score'] ) . '</td><td>' . esc_html( (string) $p['completed_events'] ) . '</td><td><a class="lnkup-btn lnkup-btn--sm" href="' . esc_url( $url ) . '">View</a></td></tr>';
			}
			return $html . '</tbody></table>';
		}
		if ( 'compact' === $view ) {
			$html = '<ul class="lnkup-crm-compact">';
			foreach ( $items as $p ) {
				$url = add_query_arg( 'vendor', $p['profile_id'], lnkup_page_url_helper( 'organizer-dashboard' ) );
				$html .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $p['business_name'] ) . '</a> <span class="lnkup-muted">' . esc_html( (string) $p['vendor_score'] ) . '</span></li>';
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
						return ( $b['avg_rating'] ?? 0 ) <=> ( $a['avg_rating'] ?? 0 );
					case 'completed':
						return ( $b['reliability']['completed_events'] ?? $b['completed_events'] ?? 0 ) <=> ( $a['reliability']['completed_events'] ?? $a['completed_events'] ?? 0 );
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
}
