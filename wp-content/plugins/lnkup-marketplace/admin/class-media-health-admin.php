<?php
/**
 * wp-admin → LNKup → Media Health.
 *
 * Shows how every event's image is resolved, flags broken uploads and events that only have
 * LNKup art, and lets admins recalculate, choose a display mode, set an override from the
 * Media Library, restore automatic selection, and export a CSV. Bulk actions never overwrite
 * organizer uploads: "use LNKup art" and "use tour artwork" skip events with their own image.
 * Every change is recorded in LNKup_Audit_Log.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Media_Health_Admin {

	const SLUG     = 'lnkup-media-health';
	const CAP      = 'lnkup_manage_settings';
	const ACTION   = 'lnkup_media_health';
	const PER_PAGE = 25;

	const SOURCE_LABELS = array(
		'override'          => 'Admin override',
		'opportunity_cover' => 'Slot cover',
		'event_cover'       => 'Event cover',
		'event_thumbnail'   => 'Featured image (wp-admin)',
		'flyer'             => 'Official flyer',
		'gallery'           => 'Gallery image',
		'external'          => 'Imported listing image',
		'tour_cover'        => 'Tour artwork',
		'tour_brand'        => 'Tour brand logo',
		'organizer_cover'   => 'Organizer brand cover',
		'organizer_logo'    => 'Organizer logo',
		'category_art'      => 'LNKup event-type art',
		'generated'         => 'Generated LNKup cover',
	);

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 31 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION . '_csv', array( __CLASS__, 'export_csv' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'lnkup-marketplace', __( 'Media Health', 'lnkup-marketplace' ), __( 'Media Health', 'lnkup-marketplace' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	private static function can(): bool {
		return current_user_can( self::CAP ) || ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() );
	}

	/**
	 * Current filters from the query string.
	 *
	 * @return array<string,mixed>
	 */
	private static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		return array(
			'source'     => isset( $_GET['source'] ) ? sanitize_key( wp_unslash( (string) $_GET['source'] ) ) : '',
			'health'     => isset( $_GET['health'] ) ? sanitize_key( wp_unslash( (string) $_GET['health'] ) ) : '',
			'organizer'  => isset( $_GET['organizer'] ) ? absint( $_GET['organizer'] ) : 0,
			'event_type' => isset( $_GET['event_type'] ) ? sanitize_title( wp_unslash( (string) $_GET['event_type'] ) ) : '',
			'tour'       => isset( $_GET['tour'] ) ? absint( $_GET['tour'] ) : 0,
			'city'       => isset( $_GET['city'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['city'] ) ) : '',
			'state'      => isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '',
			'status'     => isset( $_GET['post_status'] ) && 'any' === $_GET['post_status'] ? 'any' : 'publish',
			's'          => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '',
			'paged'      => max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ),
		);
		// phpcs:enable
	}

	/**
	 * Event IDs matching the post/meta/tax filters (source/health are applied after resolving).
	 *
	 * @param array<string,mixed> $f     Filters.
	 * @param int                 $limit Max IDs.
	 * @return int[]
	 */
	private static function query_ids( array $f, int $limit = 2000 ): array {
		$meta = array();
		if ( $f['organizer'] ) {
			$meta[] = array( 'key' => '_lnkup_organizer_user_id', 'value' => (string) $f['organizer'] );
		}
		if ( $f['tour'] ) {
			$meta[] = array( 'key' => '_lnkup_tour_id', 'value' => (string) $f['tour'] );
		}
		if ( '' !== $f['city'] ) {
			$meta[] = array( 'key' => '_lnkup_city', 'value' => $f['city'], 'compare' => 'LIKE' );
		}
		if ( '' !== $f['state'] ) {
			$meta[] = array( 'key' => '_lnkup_state', 'value' => $f['state'] );
		}
		$args = array(
			'post_type'      => 'lnkup_event',
			'post_status'    => 'any' === $f['status'] ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( '' !== $f['s'] ) {
			$args['s'] = $f['s'];
		}
		if ( $meta ) {
			$args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
		}
		if ( '' !== $f['event_type'] ) {
			$args['tax_query'] = array( array( 'taxonomy' => 'lnkup_event_type', 'field' => 'slug', 'terms' => $f['event_type'], 'include_children' => true ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_tax_query
		}
		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * Resolve and filter. Returns rows with media + health.
	 *
	 * @param array<string,mixed> $f Filters.
	 * @return array<int,array<string,mixed>>
	 */
	private static function rows( array $f ): array {
		$ids = self::query_ids( $f );
		LNKup_Event_Media::prime( $ids );
		$rows = array();
		foreach ( $ids as $id ) {
			$media  = LNKup_Event_Media::resolve( $id );
			$health = LNKup_Event_Media::health( $id, $media );
			if ( '' !== $f['source'] && $media['source'] !== $f['source'] ) {
				continue;
			}
			if ( '' !== $f['health'] && $health !== $f['health'] ) {
				continue;
			}
			$rows[] = array( 'id' => $id, 'media' => $media, 'health' => $health );
		}
		return $rows;
	}

	public static function handle(): void {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$op       = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		$ids      = isset( $_POST['event_ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['event_ids'] ) ) ) : array();
		$single   = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$back     = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( (string) $_POST['back'] ) ) : admin_url( 'admin.php?page=' . self::SLUG );
		$done     = 0;
		$skipped  = 0;
		$errors   = 0;
		if ( $single ) {
			$ids = array( $single );
		}
		foreach ( $ids as $id ) {
			if ( 'lnkup_event' !== get_post_type( $id ) ) {
				continue;
			}
			$media    = LNKup_Event_Media::resolve( $id );
			$uploaded = ! empty( $media['uploaded'] ) && 'override' !== $media['source'];
			$result   = true;
			switch ( $op ) {
				case 'recalculate':
					LNKup_Event_Media::forget( array( $id ) );
					LNKup_Event_Media::resolve( $id );
					LNKup_Event_Media::schedule_reindex_for_events( array( $id ) );
					break;
				case 'use_category_art':
				case 'use_generated':
					if ( $uploaded ) {
						++$skipped;
						continue 2;
					}
					$result = LNKup_Event_Media::set_display( $id, 'use_category_art' === $op ? 'category' : 'generated', null, 'media_health_bulk' );
					break;
				case 'use_tour_art':
					if ( $uploaded || ! (int) get_post_meta( $id, '_lnkup_tour_id', true ) ) {
						++$skipped;
						continue 2;
					}
					$result = LNKup_Event_Media::set_display( $id, 'tour', null, 'media_health_bulk' );
					break;
				case 'use_organizer_brand':
					if ( $uploaded ) {
						++$skipped;
						continue 2;
					}
					$result = LNKup_Event_Media::set_display( $id, 'organizer', null, 'media_health_bulk' );
					break;
				case 'set_override':
					$attachment = isset( $_POST['override_id'] ) ? absint( $_POST['override_id'] ) : 0;
					$result     = LNKup_Event_Media::set_display( $id, '', $attachment, 'media_health_override' );
					break;
				case 'restore_auto':
					$result = LNKup_Event_Media::set_display( $id, 'auto', 0, 'media_health_restore' );
					break;
				default:
					continue 2;
			}
			if ( is_wp_error( $result ) ) {
				++$errors;
			} else {
				++$done;
			}
		}
		wp_safe_redirect( add_query_arg( array( 'lnkup_done' => $done, 'lnkup_skipped' => $skipped, 'lnkup_errors' => $errors ), $back ) );
		exit;
	}

	public static function export_csv(): void {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		check_admin_referer( self::ACTION . '_csv' );
		$rows = self::rows( self::filters() );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=lnkup-media-health-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'event_id', 'title', 'status', 'organizer_id', 'city', 'state', 'event_type', 'source', 'mode', 'presentation', 'health', 'image_url' ) );
		foreach ( $rows as $row ) {
			$id    = (int) $row['id'];
			$terms = get_the_terms( $id, 'lnkup_event_type' );
			fputcsv(
				$out,
				array(
					$id,
					self::csv_safe( (string) get_the_title( $id ) ),
					(string) get_post_status( $id ),
					(int) get_post_meta( $id, '_lnkup_organizer_user_id', true ),
					self::csv_safe( (string) get_post_meta( $id, '_lnkup_city', true ) ),
					self::csv_safe( (string) get_post_meta( $id, '_lnkup_state', true ) ),
					self::csv_safe( is_array( $terms ) ? implode( '|', wp_list_pluck( $terms, 'name' ) ) : '' ),
					(string) $row['media']['source'],
					(string) $row['media']['mode'],
					(string) $row['media']['presentation'],
					(string) $row['health'],
					(string) $row['media']['url'],
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Neutralize spreadsheet formula injection.
	 *
	 * @param string $value Cell.
	 */
	private static function csv_safe( string $value ): string {
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function render(): void {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		wp_enqueue_media();
		$f      = self::filters();
		$rows   = self::rows( $f );
		$counts = array();
		$health = array( 'ok' => 0, 'broken' => 0, 'needs_artwork' => 0 );
		foreach ( $rows as $row ) {
			$counts[ $row['media']['source'] ] = ( $counts[ $row['media']['source'] ] ?? 0 ) + 1;
			++$health[ $row['health'] ];
		}
		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page  = min( $f['paged'], $pages );
		$slice = array_slice( $rows, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$back  = self::url( array_filter( array_merge( $f, array( 'paged' => $page ) ) ) );
		$types = get_terms( array( 'taxonomy' => 'lnkup_event_type', 'hide_empty' => false ) );
		$tours = get_posts( array( 'post_type' => 'lnkup_tour', 'post_status' => 'publish', 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$done    = isset( $_GET['lnkup_done'] ) ? absint( $_GET['lnkup_done'] ) : null;
		$skipped = isset( $_GET['lnkup_skipped'] ) ? absint( $_GET['lnkup_skipped'] ) : 0;
		$errors  = isset( $_GET['lnkup_errors'] ) ? absint( $_GET['lnkup_errors'] ) : 0;
		// phpcs:enable
		?>
		<div class="wrap lnkup-media-health">
			<h1><?php esc_html_e( 'Media Health', 'lnkup-marketplace' ); ?></h1>
			<p class="description"><?php esc_html_e( 'How each event image is chosen: admin override, organizer uploads (cover, wp-admin featured image, flyer, gallery), imported image, tour artwork, organizer branding, LNKup event-type art, then a generated cover. Bulk actions never replace an organizer upload.', 'lnkup-marketplace' ); ?></p>
			<?php if ( null !== $done ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Updated %1$d event(s). Skipped %2$d with organizer artwork. %3$d error(s).', 'lnkup-marketplace' ), $done, $skipped, $errors ) ); ?></p></div>
			<?php endif; ?>
			<style>
				.lnkup-media-health .mh-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:12px 0}
				.lnkup-media-health .mh-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:10px 14px}
				.lnkup-media-health .mh-card strong{display:block;font-size:22px}
				.lnkup-media-health .mh-thumb{width:96px;height:60px;border-radius:6px;object-fit:cover;background:#111;display:block}
				.lnkup-media-health .mh-thumb.contain{object-fit:contain}
				.lnkup-media-health .mh-broken{color:#b91c1c;font-weight:600}.lnkup-media-health .mh-needs{color:#a16207;font-weight:600}.lnkup-media-health .mh-ok{color:#15803d;font-weight:600}
				.lnkup-media-health .mh-filters{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:10px 0}
				.lnkup-media-health td form{display:inline-block;margin:0 4px 4px 0}
			</style>
			<div class="mh-grid">
				<div class="mh-card"><?php esc_html_e( 'Events', 'lnkup-marketplace' ); ?><strong><?php echo (int) $total; ?></strong></div>
				<div class="mh-card"><?php esc_html_e( 'Organizer / admin artwork', 'lnkup-marketplace' ); ?><strong><?php echo (int) array_sum( array_intersect_key( $counts, array_flip( LNKup_Event_Media::UPLOADED_SOURCES ) ) ); ?></strong></div>
				<div class="mh-card"><?php esc_html_e( 'Tour artwork', 'lnkup-marketplace' ); ?><strong><?php echo (int) ( ( $counts['tour_cover'] ?? 0 ) + ( $counts['tour_brand'] ?? 0 ) ); ?></strong></div>
				<div class="mh-card"><?php esc_html_e( 'Organizer branding', 'lnkup-marketplace' ); ?><strong><?php echo (int) ( ( $counts['organizer_cover'] ?? 0 ) + ( $counts['organizer_logo'] ?? 0 ) ); ?></strong></div>
				<div class="mh-card"><?php esc_html_e( 'LNKup art / generated', 'lnkup-marketplace' ); ?><strong><?php echo (int) ( ( $counts['category_art'] ?? 0 ) + ( $counts['generated'] ?? 0 ) ); ?></strong></div>
				<div class="mh-card"><?php esc_html_e( 'Broken uploads', 'lnkup-marketplace' ); ?><strong class="mh-broken"><?php echo (int) $health['broken']; ?></strong></div>
			</div>

			<form method="get" class="mh-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="search" name="s" value="<?php echo esc_attr( $f['s'] ); ?>" placeholder="<?php esc_attr_e( 'Search events', 'lnkup-marketplace' ); ?>" />
				<select name="source"><option value=""><?php esc_html_e( 'Any source', 'lnkup-marketplace' ); ?></option>
					<?php foreach ( self::SOURCE_LABELS as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>"<?php selected( $f['source'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
				</select>
				<select name="health"><option value=""><?php esc_html_e( 'Any health', 'lnkup-marketplace' ); ?></option>
					<option value="broken"<?php selected( $f['health'], 'broken' ); ?>><?php esc_html_e( 'Broken upload', 'lnkup-marketplace' ); ?></option>
					<option value="needs_artwork"<?php selected( $f['health'], 'needs_artwork' ); ?>><?php esc_html_e( 'Needs official artwork', 'lnkup-marketplace' ); ?></option>
					<option value="ok"<?php selected( $f['health'], 'ok' ); ?>><?php esc_html_e( 'OK', 'lnkup-marketplace' ); ?></option>
				</select>
				<select name="event_type"><option value=""><?php esc_html_e( 'Any event type', 'lnkup-marketplace' ); ?></option>
					<?php foreach ( is_array( $types ) ? $types : array() as $term ) : ?><option value="<?php echo esc_attr( $term->slug ); ?>"<?php selected( $f['event_type'], $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?>
				</select>
				<select name="tour"><option value="0"><?php esc_html_e( 'Any tour', 'lnkup-marketplace' ); ?></option>
					<?php foreach ( $tours as $tour ) : ?><option value="<?php echo (int) $tour->ID; ?>"<?php selected( $f['tour'], $tour->ID ); ?>><?php echo esc_html( get_the_title( $tour ) ); ?></option><?php endforeach; ?>
				</select>
				<input type="number" name="organizer" value="<?php echo $f['organizer'] ? (int) $f['organizer'] : ''; ?>" placeholder="<?php esc_attr_e( 'Organizer user ID', 'lnkup-marketplace' ); ?>" style="width:150px" />
				<input type="text" name="city" value="<?php echo esc_attr( $f['city'] ); ?>" placeholder="<?php esc_attr_e( 'City', 'lnkup-marketplace' ); ?>" style="width:120px" />
				<input type="text" name="state" value="<?php echo esc_attr( $f['state'] ); ?>" placeholder="<?php esc_attr_e( 'State', 'lnkup-marketplace' ); ?>" style="width:70px" />
				<label><input type="checkbox" name="post_status" value="any"<?php checked( 'any', $f['status'] ); ?> /> <?php esc_html_e( 'Include drafts', 'lnkup-marketplace' ); ?></label>
				<button class="button"><?php esc_html_e( 'Filter', 'lnkup-marketplace' ); ?></button>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'action' => self::ACTION . '_csv' ), array_filter( $f ) ), admin_url( 'admin-post.php' ) ), self::ACTION . '_csv' ) ); ?>"><?php esc_html_e( 'Export CSV', 'lnkup-marketplace' ); ?></a>
			</form>

			<?php if ( array() === $slice ) : ?>
				<p><?php esc_html_e( 'No events match these filters.', 'lnkup-marketplace' ); ?></p>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mh-bulk">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<input type="hidden" name="back" value="<?php echo esc_url( $back ); ?>" />
				<p>
					<select name="op">
						<option value="recalculate"><?php esc_html_e( 'Recalculate image', 'lnkup-marketplace' ); ?></option>
						<option value="use_category_art"><?php esc_html_e( 'Use LNKup event-type art (skips organizer uploads)', 'lnkup-marketplace' ); ?></option>
						<option value="use_tour_art"><?php esc_html_e( 'Use tour artwork (skips organizer uploads)', 'lnkup-marketplace' ); ?></option>
						<option value="use_organizer_brand"><?php esc_html_e( 'Use organizer branding (skips organizer uploads)', 'lnkup-marketplace' ); ?></option>
						<option value="use_generated"><?php esc_html_e( 'Use generated cover (skips organizer uploads)', 'lnkup-marketplace' ); ?></option>
						<option value="restore_auto"><?php esc_html_e( 'Restore automatic selection', 'lnkup-marketplace' ); ?></option>
					</select>
					<button class="button button-primary"><?php esc_html_e( 'Apply to selected', 'lnkup-marketplace' ); ?></button>
				</p>
			</form>
			<table class="widefat striped">
				<thead><tr>
					<td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.mh-check').forEach(function(c){c.checked=this.checked;}.bind(this))" aria-label="<?php esc_attr_e( 'Select all', 'lnkup-marketplace' ); ?>" /></td>
					<th><?php esc_html_e( 'Image', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Source', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Health', 'lnkup-marketplace' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'lnkup-marketplace' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $slice as $row ) : ?>
					<?php
					$id    = (int) $row['id'];
					$media = $row['media'];
					$org   = (int) get_post_meta( $id, '_lnkup_organizer_user_id', true );
					$place = trim( (string) get_post_meta( $id, '_lnkup_city', true ) . ', ' . (string) get_post_meta( $id, '_lnkup_state', true ), ', ' );
					?>
					<tr>
						<th class="check-column"><input type="checkbox" class="mh-check" name="event_ids[]" value="<?php echo (int) $id; ?>" form="mh-bulk" /></th>
						<td><img class="mh-thumb<?php echo 'contain' === $media['fit'] ? ' contain' : ''; ?>" style="background:<?php echo esc_attr( (string) $media['background'] ); ?>" src="<?php echo esc_url( (string) $media['thumb_url'] ); ?>" alt="" loading="lazy" /></td>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><strong><?php echo esc_html( get_the_title( $id ) ); ?></strong></a><br />
							<span class="description"><?php echo esc_html( trim( ( $org ? 'organizer #' . $org . ' · ' : '' ) . $place . ' · ' . get_post_status( $id ), ' ·' ) ); ?></span></td>
						<td><?php echo esc_html( self::SOURCE_LABELS[ $media['source'] ] ?? (string) $media['source'] ); ?><br /><span class="description"><?php echo esc_html( 'mode: ' . $media['mode'] . ' · ' . $media['presentation'] ); ?></span></td>
						<td><?php if ( 'broken' === $row['health'] ) : ?><span class="mh-broken"><?php esc_html_e( 'Broken upload', 'lnkup-marketplace' ); ?></span><?php elseif ( 'needs_artwork' === $row['health'] ) : ?><span class="mh-needs"><?php esc_html_e( 'Needs official artwork', 'lnkup-marketplace' ); ?></span><?php else : ?><span class="mh-ok"><?php esc_html_e( 'OK', 'lnkup-marketplace' ); ?></span><?php endif; ?></td>
						<td>
							<?php self::row_action( $id, 'recalculate', __( 'Recalculate', 'lnkup-marketplace' ), $back ); ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( self::ACTION ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
								<input type="hidden" name="op" value="set_override" />
								<input type="hidden" name="event_id" value="<?php echo (int) $id; ?>" />
								<input type="hidden" name="back" value="<?php echo esc_url( $back ); ?>" />
								<input type="hidden" name="override_id" class="mh-override-id" value="" />
								<button type="button" class="button button-small mh-choose"><?php esc_html_e( 'Set override image', 'lnkup-marketplace' ); ?></button>
							</form>
							<?php if ( 'auto' !== $media['mode'] || 'override' === $media['source'] ) : ?>
								<?php self::row_action( $id, 'restore_auto', __( 'Restore automatic', 'lnkup-marketplace' ), $back ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<?php if ( $page > 1 ) : ?><a class="button" href="<?php echo esc_url( self::url( array_filter( array_merge( $f, array( 'paged' => $page - 1 ) ) ) ) ); ?>">&larr;</a><?php endif; ?>
				<?php echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'lnkup-marketplace' ), $page, $pages ) ); ?>
				<?php if ( $page < $pages ) : ?><a class="button" href="<?php echo esc_url( self::url( array_filter( array_merge( $f, array( 'paged' => $page + 1 ) ) ) ) ); ?>">&rarr;</a><?php endif; ?>
			</p>
			<?php endif; ?>
			<h2><?php esc_html_e( 'LNKup event-type artwork library', 'lnkup-marketplace' ); ?></h2>
			<div class="mh-grid">
				<?php foreach ( LNKup_Event_Media_Library::catalog() as $group ) : ?>
					<div class="mh-card"><?php echo esc_html( $group['label'] ); ?>
						<?php foreach ( $group['urls'] as $art ) : ?><img class="mh-thumb" style="width:100%;height:auto;aspect-ratio:16/9;margin-top:6px" src="<?php echo esc_url( $art ); ?>" alt="" loading="lazy" /><?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<script>
			document.querySelectorAll('.mh-choose').forEach(function (btn) {
				btn.addEventListener('click', function () {
					if (!window.wp || !wp.media) { return; }
					var frame = wp.media({ title: <?php echo wp_json_encode( __( 'Choose event image', 'lnkup-marketplace' ) ); ?>, library: { type: 'image' }, multiple: false });
					frame.on('select', function () {
						var att = frame.state().get('selection').first().toJSON();
						var form = btn.closest('form');
						form.querySelector('.mh-override-id').value = att.id;
						form.submit();
					});
					frame.open();
				});
			});
			</script>
		</div>
		<?php
	}

	private static function row_action( int $id, string $op, string $label, string $back ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<input type="hidden" name="op" value="<?php echo esc_attr( $op ); ?>" />
			<input type="hidden" name="event_id" value="<?php echo (int) $id; ?>" />
			<input type="hidden" name="back" value="<?php echo esc_url( $back ); ?>" />
			<button class="button button-small"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}
}
