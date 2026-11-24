<?php
/**
 * wp-admin → LNKup → Location Health.
 *
 * Geographic integrity at a glance (invalid states, unresolved cities, missing coordinates,
 * duplicate markets) and the review queue for corrections the migration would not make on its
 * own. Every action is nonce-checked, capability-gated and recorded in LNKup_Audit_Log (through
 * LNKup_Location_Migration). There are no destructive bulk operations: "apply safe corrections"
 * only touches deterministic fixes and every apply run can be rolled back.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Location_Health_Admin {

	const SLUG      = 'lnkup-location-health';
	const CAP       = 'lnkup_manage_settings';
	const ACTION    = 'lnkup_location_health';
	const CACHE_KEY = 'lnkup_location_health_summary';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 32 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'lnkup-marketplace', __( 'Location Health', 'lnkup-marketplace' ), __( 'Location Health', 'lnkup-marketplace' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	private static function can(): bool {
		return current_user_can( self::CAP ) || ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() );
	}

	/**
	 * Live geographic health (cached 10 minutes; "Refresh" recomputes).
	 *
	 * @return array<string, mixed>
	 */
	public static function summary( bool $fresh = false ): array {
		$cached = $fresh ? false : get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$events = get_posts( array( 'post_type' => 'lnkup_event', 'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ), 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
		update_meta_cache( 'post', $events );
		$out = array(
			'total'            => 0,
			'validated'        => 0,
			'invalid_state'    => 0,
			'unresolved'       => 0,
			'missing_coords'   => 0,
			'missing_location' => 0,
		);
		foreach ( $events as $id ) {
			$city  = (string) get_post_meta( $id, '_lnkup_city', true );
			$state = (string) get_post_meta( $id, '_lnkup_state', true );
			if ( '' === trim( $city . $state ) ) {
				++$out['missing_location'];
				continue;
			}
			++$out['total'];
			$country = LNKup_Location_Service::country_code( (string) get_post_meta( $id, '_lnkup_country', true ) );
			if ( 'US' === $country && '' !== $state && null === LNKup_Location_Service::state_code( $state ) ) {
				++$out['invalid_state'];
			}
			$status = (string) get_post_meta( $id, '_lnkup_location_status', true );
			if ( in_array( $status, array( LNKup_Location_Service::VERIFIED, LNKup_Location_Service::NORMALIZED ), true ) ) {
				++$out['validated'];
			} elseif ( '' !== $status ) {
				++$out['unresolved'];
			}
			if ( '' === (string) get_post_meta( $id, '_lnkup_lat', true ) ) {
				++$out['missing_coords'];
			}
		}
		$out['duplicate_markets'] = count( self::duplicate_markets() );
		$out['migration']         = LNKup_Location_Migration::counts();
		$geo_failed               = 0;
		if ( class_exists( 'LNKup_Map_Tables' ) ) {
			$table = LNKup_Map_Tables::geocode_cache_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$geo_failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'" );
		}
		$out['provider_errors'] = $geo_failed;
		set_transient( self::CACHE_KEY, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Markets that resolve to the same canonical city (e.g. "atlanta" and "atlanta-ge").
	 *
	 * @return array<int, array{canonical: string, slugs: array<int, string>}>
	 */
	public static function duplicate_markets(): array {
		if ( ! class_exists( 'LNKup_Market_Pages' ) ) {
			return array();
		}
		$groups = array();
		foreach ( LNKup_Market_Pages::get_all_markets() as $slug => $market ) {
			if ( ! empty( $market['merged_into'] ) ) {
				continue;
			}
			$r   = LNKup_Location_Service::resolve( (string) ( $market['city'] ?? '' ), (string) ( $market['state'] ?? '' ) );
			$key = ( $r['suggestions'][0]['display'] ?? '' ) ?: $r['display'];
			if ( in_array( $r['status'], array( LNKup_Location_Service::VERIFIED, LNKup_Location_Service::NORMALIZED ), true ) ) {
				$key = $r['display'];
			}
			$groups[ strtolower( (string) $key ) ][] = (string) ( $market['slug'] ?? $slug );
		}
		$out = array();
		foreach ( $groups as $key => $slugs ) {
			if ( count( $slugs ) > 1 ) {
				$out[] = array( 'canonical' => $key, 'slugs' => $slugs );
			}
		}
		return $out;
	}

	public static function handle(): void {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'You do not have permission to manage locations.', 'lnkup-marketplace' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$op     = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		$actor  = get_current_user_id();
		$notice = '';
		switch ( $op ) {
			case 'dry_run':
				$r      = LNKup_Location_Migration::run( false, array(), $actor );
				$notice = sprintf( __( 'Dry run %1$s: %2$d proposals, %3$d need review. Nothing changed.', 'lnkup-marketplace' ), $r['run_id'], $r['total'], $r['review'] );
				break;
			case 'apply_safe':
				$r      = LNKup_Location_Migration::run( true, array(), $actor );
				$notice = sprintf( __( 'Run %1$s applied %2$d safe corrections; %3$d queued for review.', 'lnkup-marketplace' ), $r['run_id'], $r['applied'], $r['review'] );
				break;
			case 'confirm':
				$row_id = isset( $_POST['row_id'] ) ? absint( $_POST['row_id'] ) : 0;
				$city   = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['city'] ) ) : '';
				$state  = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['state'] ) ) : '';
				$result = LNKup_Location_Migration::confirm( $row_id, $city, $state, $actor );
				$notice = is_wp_error( $result ) ? $result->get_error_message() : __( 'Location confirmed and updated.', 'lnkup-marketplace' );
				break;
			case 'reject':
				LNKup_Location_Migration::reject( isset( $_POST['row_id'] ) ? absint( $_POST['row_id'] ) : 0, $actor );
				$notice = __( 'Suggestion rejected. The record keeps its current location.', 'lnkup-marketplace' );
				break;
			case 'revalidate':
				$row = LNKup_Location_Migration::get_row( isset( $_POST['row_id'] ) ? absint( $_POST['row_id'] ) : 0 );
				if ( $row ) {
					LNKup_Location_Migration::reject( (int) $row['id'], $actor );
					$r      = LNKup_Location_Migration::run( false, array( 'entity' => (string) $row['entity_type'], 'ids' => 'event' === $row['entity_type'] || 'vendor_profile' === $row['entity_type'] ? array( (int) $row['entity_id'] ) : array() ), $actor );
					$notice = sprintf( __( 'Revalidated: %d proposal(s) recorded in %s.', 'lnkup-marketplace' ), $r['total'], $r['run_id'] );
				}
				break;
			case 'merge_markets':
				$from   = isset( $_POST['from'] ) ? sanitize_title( wp_unslash( (string) $_POST['from'] ) ) : '';
				$to     = isset( $_POST['to'] ) ? sanitize_title( wp_unslash( (string) $_POST['to'] ) ) : '';
				$result = LNKup_Market_Pages::merge_market( $from, $to );
				if ( ! is_wp_error( $result ) && class_exists( 'LNKup_Audit_Log' ) ) {
					LNKup_Audit_Log::log( 'market_merged', 'market', 0, array( 'from' => $from, 'to' => $to, 'actor' => $actor ) );
				}
				$notice = is_wp_error( $result ) ? $result->get_error_message() : sprintf( __( 'Merged %1$s into %2$s. The old URL now redirects.', 'lnkup-marketplace' ), $from, $to );
				break;
			case 'rollback':
				$run    = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['run_id'] ) ) : '';
				$r      = LNKup_Location_Migration::rollback( $run, $actor );
				$notice = sprintf( __( 'Rolled back %1$d records (%2$d skipped because they changed since).', 'lnkup-marketplace' ), $r['restored'], $r['skipped'] );
				break;
		}
		delete_transient( self::CACHE_KEY );
		set_transient( 'lnkup_location_health_notice_' . $actor, $notice, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * @param string               $op     Operation.
	 * @param string               $label  Button label.
	 * @param array<string, mixed> $fields Hidden fields.
	 * @param string               $class  Button class.
	 */
	private static function button( string $op, string $label, array $fields = array(), string $class = 'button' ): string {
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		$html .= wp_nonce_field( self::ACTION, '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" /><input type="hidden" name="op" value="' . esc_attr( $op ) . '" />';
		foreach ( $fields as $name => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
		return $html . '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form> ';
	}

	public static function render(): void {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'lnkup-marketplace' ), 403 );
		}
		LNKup_Location_Migration::create_table();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$fresh   = ! empty( $_GET['refresh'] );
		$filters = array(
			'status'      => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : 'pending_review',
			'entity_type' => isset( $_GET['entity_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['entity_type'] ) ) : '',
			'confidence'  => isset( $_GET['confidence'] ) ? sanitize_key( wp_unslash( (string) $_GET['confidence'] ) ) : '',
			'limit'       => 200,
		);
		// phpcs:enable
		$s      = self::summary( $fresh );
		$notice = (string) get_transient( 'lnkup_location_health_notice_' . get_current_user_id() );
		delete_transient( 'lnkup_location_health_notice_' . get_current_user_id() );
		$m = (array) ( $s['migration'] ?? array() );
		?>
		<div class="wrap" data-testid="lnkup-location-health">
			<h1><?php esc_html_e( 'Location Health', 'lnkup-marketplace' ); ?></h1>
			<p class="description"><?php esc_html_e( 'US states are checked against the USPS list and cities against the US Census gazetteer. Only deterministic fixes apply automatically; everything else waits for you here. Financial and commission market ownership is never changed by these tools.', 'lnkup-marketplace' ); ?></p>
			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:900px;margin:16px 0">
				<tbody>
					<?php
					$cards = array(
						__( 'Events with a location', 'lnkup-marketplace' )   => $s['total'],
						__( 'Validated locations', 'lnkup-marketplace' )      => $s['validated'],
						__( 'Invalid US state codes', 'lnkup-marketplace' )   => $s['invalid_state'],
						__( 'Flagged / unresolved', 'lnkup-marketplace' )     => $s['unresolved'],
						__( 'Missing coordinates', 'lnkup-marketplace' )      => $s['missing_coords'],
						__( 'Events with no location', 'lnkup-marketplace' )  => $s['missing_location'],
						__( 'Duplicate markets', 'lnkup-marketplace' )        => $s['duplicate_markets'],
						__( 'Pending corrections', 'lnkup-marketplace' )      => (int) ( $m['pending_review'] ?? 0 ),
						__( 'Applied corrections', 'lnkup-marketplace' )      => (int) ( $m['applied'] ?? 0 ),
						__( 'Rolled back', 'lnkup-marketplace' )              => (int) ( $m['rolled_back'] ?? 0 ),
						__( 'Geocoder failures (cached)', 'lnkup-marketplace' ) => $s['provider_errors'],
					);
					foreach ( $cards as $label => $value ) :
						?>
						<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td data-metric="<?php echo esc_attr( sanitize_title( $label ) ); ?>"><strong><?php echo esc_html( number_format_i18n( (int) $value ) ); ?></strong></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'refresh' => 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Refresh counts', 'lnkup-marketplace' ); ?></a>
				<?php echo self::button( 'dry_run', __( 'Run dry-run audit', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo self::button( 'apply_safe', __( 'Apply safe corrections', 'lnkup-marketplace' ), array(), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</p>

			<h2><?php esc_html_e( 'Review queue and history', 'lnkup-marketplace' ); ?></h2>
			<form method="get" style="margin:8px 0">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<select name="status">
					<?php foreach ( array( 'pending_review', 'dry_run', 'applied', 'rejected', 'rolled_back', 'skipped' ) as $opt ) : ?>
						<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $filters['status'], $opt ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $opt ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="entity_type">
					<option value=""><?php esc_html_e( 'All records', 'lnkup-marketplace' ); ?></option>
					<?php foreach ( LNKup_Location_Migration::ENTITIES as $opt ) : ?>
						<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $filters['entity_type'], $opt ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $opt ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="confidence">
					<option value=""><?php esc_html_e( 'Any confidence', 'lnkup-marketplace' ); ?></option>
					<?php foreach ( array( 'verified', 'normalized', 'suggested', 'ambiguous', 'unresolved' ) as $opt ) : ?>
						<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $filters['confidence'], $opt ); ?>><?php echo esc_html( ucfirst( $opt ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button"><?php esc_html_e( 'Filter', 'lnkup-marketplace' ); ?></button>
			</form>
			<?php $rows = LNKup_Location_Migration::list_rows( $filters ); ?>
			<?php if ( ! $rows ) : ?>
				<p data-testid="lnkup-location-health-empty"><?php esc_html_e( 'Nothing here. Run a dry-run audit to check every record.', 'lnkup-marketplace' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" data-testid="lnkup-location-health-rows">
					<thead><tr>
						<th><?php esc_html_e( 'Record', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Original', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Proposed', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Confidence', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Related', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Run', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'lnkup-marketplace' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$original = json_decode( (string) $row['original'], true ) ?: array();
						$proposed = json_decode( (string) $row['proposed'], true ) ?: array();
						$choices  = isset( $proposed['city'] ) ? array( array( 'city' => $proposed['city'], 'state' => $proposed['state'] ?? '' ) ) : (array) ( $proposed['suggestions'] ?? array() );
						$edit     = 'event' === $row['entity_type'] ? get_edit_post_link( (int) $row['entity_id'] ) : '';
						?>
						<tr>
							<td><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $row['entity_type'] ) ) . ' #' . $row['entity_id'] ); ?><br /><?php if ( $edit ) : ?><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( (string) ( $original['title'] ?? '' ) ); ?></a><?php else : ?><?php echo esc_html( (string) ( $original['title'] ?? '' ) ); ?><?php endif; ?></td>
							<td><?php echo esc_html( trim( ( $original['city'] ?? '' ) . ', ' . ( $original['state'] ?? '' ), ', ' ) ); ?></td>
							<td><?php echo esc_html( implode( ' | ', array_map( static function ( $c ) { return trim( ( $c['city'] ?? '' ) . ', ' . ( $c['state'] ?? '' ), ', ' ); }, $choices ) ) ?: '—' ); ?></td>
							<td><?php echo esc_html( (string) $row['confidence'] ); ?></td>
							<td><?php echo esc_html( (string) $row['reason'] ); ?></td>
							<td><?php echo esc_html( (string) $row['related_count'] ); ?></td>
							<td><code><?php echo esc_html( (string) $row['run_id'] ); ?></code></td>
							<td>
								<?php if ( in_array( $row['status'], array( 'pending_review', 'dry_run' ), true ) ) : ?>
									<?php foreach ( array_slice( $choices, 0, 3 ) as $choice ) : ?>
										<?php echo self::button( 'confirm', sprintf( __( 'Use %s', 'lnkup-marketplace' ), trim( $choice['city'] . ', ' . $choice['state'], ', ' ) ), array( 'row_id' => $row['id'], 'city' => $choice['city'], 'state' => $choice['state'] ), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php endforeach; ?>
									<?php echo self::button( 'reject', __( 'Reject', 'lnkup-marketplace' ), array( 'row_id' => $row['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php echo self::button( 'revalidate', __( 'Revalidate', 'lnkup-marketplace' ), array( 'row_id' => $row['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php else : ?>
									<?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $row['status'] ) ) ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Duplicate markets', 'lnkup-marketplace' ); ?></h2>
			<?php $dupes = self::duplicate_markets(); ?>
			<?php if ( ! $dupes ) : ?>
				<p><?php esc_html_e( 'No duplicate markets. Every market maps to a distinct canonical city.', 'lnkup-marketplace' ); ?></p>
			<?php else : ?>
				<ul>
				<?php foreach ( $dupes as $group ) : ?>
					<li><strong><?php echo esc_html( $group['canonical'] ); ?></strong>:
						<?php foreach ( $group['slugs'] as $from ) : ?>
							<?php foreach ( $group['slugs'] as $to ) : ?>
								<?php if ( $from !== $to ) : ?>
									<?php echo self::button( 'merge_markets', sprintf( __( 'Merge %1$s into %2$s', 'lnkup-marketplace' ), $from, $to ), array( 'from' => $from, 'to' => $to ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php endif; ?>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Apply runs', 'lnkup-marketplace' ); ?></h2>
			<?php
			global $wpdb;
			$table = LNKup_Location_Migration::table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$runs = $wpdb->get_results( "SELECT run_id, MIN(created_at) AS at, SUM(status = 'applied') AS applied, SUM(status = 'rolled_back') AS rolled_back FROM {$table} WHERE run_id LIKE 'apply-%' GROUP BY run_id ORDER BY at DESC LIMIT 20", ARRAY_A );
			?>
			<?php if ( ! $runs ) : ?>
				<p><?php esc_html_e( 'No apply runs yet.', 'lnkup-marketplace' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr><th><?php esc_html_e( 'Run', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Started', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Applied', 'lnkup-marketplace' ); ?></th><th><?php esc_html_e( 'Rolled back', 'lnkup-marketplace' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $runs as $run ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $run['run_id'] ); ?></code></td>
							<td><?php echo esc_html( (string) $run['at'] ); ?></td>
							<td><?php echo esc_html( (string) $run['applied'] ); ?></td>
							<td><?php echo esc_html( (string) $run['rolled_back'] ); ?></td>
							<td><?php echo (int) $run['applied'] > 0 ? self::button( 'rollback', __( 'Roll back this run', 'lnkup-marketplace' ), array( 'run_id' => $run['run_id'] ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
