<?php
/**
 * Distribution loops built on existing systems.
 *
 * External demand
 * - Vendor interest in an external (not-yet-on-LNKup) event upserts an organizer lead in
 *   LNKup_Org_Leads (deduplicated by source event, then by the lead module's email/website
 *   identity rules), source `external_interest`, with each request logged as lead activity.
 *   The Lead conversion itself is recorded by LNKup_Analytics_Taxonomy.
 * - When the organizer claims the event, vendors who asked are notified through
 *   Communications (`v_external_event_claimed`, their preferences apply, once per vendor) and the
 *   funnel interest → notified → applied → booked is computed from real records.
 * - Claim invitations already use the real vendor_interest_count (LNKup_External_Events_Followup).
 *
 * Acceptance share card (flag tracking_links): an opt-in card on a vendor's approved/paid booking
 * with a ready-to-share message and tracked link to the public event page. Public data only;
 * nothing is posted for the vendor.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Distribution {

	const DAILY_HOOK      = 'lnkup_distribution_daily';
	const LEAD_SOURCE     = 'external_interest';
	const BOOKED_STATUSES = array( 'approved', 'payment_required', 'paid', 'completed' );

	public static function init(): void {
		add_action( 'lnkup_analytics_tracked', array( __CLASS__, 'on_tracked' ), 20, 4 );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::DAILY_HOOK, array( __CLASS__, 'sweep_claims' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 06:15' ), 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * @param string              $type    Analytics type.
	 * @param array<string,mixed> $data    Data.
	 * @param int                 $user_id User.
	 */
	public static function on_tracked( $type, $data, $user_id, $opp_id = 0 ): void {
		if ( 'external_event_interest_submitted' === $type && ! empty( $data['external_event_id'] ) ) {
			self::upsert_lead( (int) $data['external_event_id'] );
		} elseif ( 'external_event_claim_completed' === $type && ! empty( $data['external_event_id'] ) && ! empty( $data['event_id'] ) ) {
			self::notify_claim( (int) $data['external_event_id'], (int) $data['event_id'] );
		}
	}

	/* ------------------------------------------------------------ Organizer leads */

	public static function leads_active(): bool {
		return class_exists( 'LNKup_Org_Growth' ) && method_exists( 'LNKup_Org_Growth', 'is_enabled' ) && LNKup_Org_Growth::is_enabled() && class_exists( 'LNKup_Org_Leads' );
	}

	public static function lead_for_external( int $external_id ): int {
		global $wpdb;
		if ( ! self::leads_active() ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Org_Leads::table() . ' WHERE source_detail = %s ORDER BY id ASC LIMIT 1', 'external_event:' . $external_id ) );
	}

	/**
	 * Create (or find) the organizer lead for an external event and log this vendor request.
	 */
	public static function upsert_lead( int $external_id ): int {
		if ( ! self::leads_active() || ! class_exists( 'LNKup_External_Events' ) ) {
			return 0;
		}
		$row = LNKup_External_Events::get_row( $external_id );
		if ( ! $row ) {
			return 0;
		}
		$count   = (int) ( $row['vendor_interest_count'] ?? 0 );
		$lead_id = self::lead_for_external( $external_id );
		if ( ! $lead_id ) {
			$name   = trim( (string) ( $row['organizer_name'] ?? '' ) );
			$result = LNKup_Org_Leads::create(
				array(
					'name'            => '' !== $name ? $name : (string) $row['title'],
					'email'           => (string) ( $row['organizer_email'] ?? '' ),
					'website'         => (string) ( $row['organizer_url'] ?? '' ),
					'instagram'       => (string) ( $row['organizer_instagram'] ?? '' ),
					'city'            => (string) ( $row['city'] ?? '' ),
					'state'           => (string) ( $row['state'] ?? '' ),
					'application_url' => (string) ( $row['application_url'] ?? '' ),
					'next_event_date' => (string) ( $row['start_datetime'] ?? '' ),
					'source'          => self::LEAD_SOURCE,
					'source_detail'   => 'external_event:' . $external_id,
					'source_campaign' => mb_substr( (string) $row['title'], 0, 180 ),
				),
				array( 'actor' => 0, 'tags' => array( 'external-demand' ) )
			);
			if ( is_wp_error( $result ) ) {
				$data    = (array) $result->get_error_data();
				$lead_id = (int) ( $data['duplicate_of'] ?? 0 );
			} else {
				$lead_id = (int) $result;
			}
		}
		if ( $lead_id ) {
			LNKup_Org_Leads::log_activity(
				$lead_id,
				'external_interest',
				array(
					'actor'   => 0,
					/* translators: 1: vendor requests, 2: event */
					'summary' => sprintf( _n( '%1$d vendor asked for a spot at %2$s on LNKup', '%1$d vendors asked for spots at %2$s on LNKup', max( 1, $count ), 'lnkup-marketplace' ), max( 1, $count ), (string) $row['title'] ),
					'payload' => array( 'external_event_id' => $external_id, 'demand' => $count ),
				)
			);
			if ( class_exists( 'LNKup_Org_Lead_Scoring' ) ) {
				LNKup_Org_Lead_Scoring::score_lead( $lead_id );
			}
		}
		return $lead_id;
	}

	/* ------------------------------------------------------------ Claims */

	/**
	 * Vendors who asked about an external event.
	 *
	 * @return int[]
	 */
	public static function interested_vendors( int $external_id ): array {
		global $wpdb;
		if ( ! class_exists( 'LNKup_External_Events_Tables' ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT vendor_user_id FROM ' . LNKup_External_Events_Tables::interest_table() . ' WHERE external_event_id = %d', $external_id ) ) );
	}

	private static function first_open_opportunity( int $event_id ): int {
		foreach ( get_posts( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'publish', 'posts_per_page' => 50, 'fields' => 'ids', 'no_found_rows' => true, 'meta_key' => '_lnkup_event_id', 'meta_value' => $event_id ) ) as $opp ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$status = (string) get_post_meta( (int) $opp, '_lnkup_opportunity_status', true );
			$due    = (string) get_post_meta( (int) $opp, '_lnkup_application_deadline', true );
			if ( ( '' === $status || 'open' === $status ) && (int) get_post_meta( (int) $opp, '_lnkup_spots_remaining', true ) > 0 && ( '' === $due || $due >= current_time( 'Y-m-d' ) ) ) {
				return (int) $opp;
			}
		}
		return 0;
	}

	private static function applied_to_event( int $vendor, int $event_id ): bool {
		return (bool) get_posts( array( 'post_type' => 'lnkup_application', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => array( array( 'key' => '_lnkup_vendor_user_id', 'value' => $vendor ), array( 'key' => '_lnkup_event_id', 'value' => $event_id ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	/**
	 * Tell interested vendors the event is now on LNKup (once per vendor; Communications applies
	 * preferences, frequency caps and suppression).
	 *
	 * @return int Messages queued.
	 */
	public static function notify_claim( int $external_id, int $event_id ): int {
		if ( 'publish' !== get_post_status( $event_id ) || ! class_exists( 'LNKup_Comms_Engine' ) ) {
			return 0;
		}
		$opp = self::first_open_opportunity( $event_id );
		if ( ! $opp ) {
			return 0;
		}
		$queued = 0;
		foreach ( self::interested_vendors( $external_id ) as $vendor ) {
			if ( $vendor < 1 || self::applied_to_event( $vendor, $event_id ) ) {
				continue;
			}
			$sent = LNKup_Comms_Engine::dispatch(
				'v_external_event_claimed',
				$vendor,
				array( 'event_id' => $event_id, 'opportunity_id' => $opp, 'vendor_user_id' => $vendor ),
				array( 'window' => 'claim' . $external_id, 'source' => 'external_claim' )
			);
			if ( $sent > 0 ) {
				++$queued;
				if ( class_exists( 'LNKup_Analytics_Taxonomy' ) ) {
					LNKup_Analytics_Taxonomy::emit( 'external_claim_vendor_notified', array( 'external_event_id' => $external_id, 'event_id' => $event_id ), $vendor );
				}
			}
		}
		return $queued;
	}

	/**
	 * Catch claims approved outside the tracked path (admin review). Dedupe makes this idempotent.
	 */
	public static function sweep_claims(): int {
		global $wpdb;
		if ( ! class_exists( 'LNKup_External_Events_Tables' ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, claimed_event_id FROM ' . LNKup_External_Events_Tables::events_table() . " WHERE claim_status = 'claimed' AND claimed_event_id > 0 AND vendor_interest_count > 0 AND updated_at >= %s LIMIT 200", gmdate( 'Y-m-d H:i:s', strtotime( '-14 days' ) ) ), ARRAY_A );
		$total = 0;
		foreach ( $rows as $row ) {
			$total += self::notify_claim( (int) $row['id'], (int) $row['claimed_event_id'] );
		}
		return $total;
	}

	/**
	 * interest → notified → applied → booked for one external event (real records only).
	 *
	 * @return array<string,int>
	 */
	public static function claim_funnel( int $external_id ): array {
		global $wpdb;
		$row      = class_exists( 'LNKup_External_Events' ) ? LNKup_External_Events::get_row( $external_id ) : null;
		$event_id = $row ? (int) ( $row['claimed_event_id'] ?? 0 ) : 0;
		$vendors  = self::interested_vendors( $external_id );
		$notified = 0;
		if ( $event_id && $vendors && class_exists( 'LNKup_Comms_Schema' ) ) {
			$in = implode( ',', array_map( 'absint', $vendors ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$notified = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT recipient_user_id) FROM ' . LNKup_Comms_Schema::table( 'messages' ) . " WHERE trigger_key = 'v_external_event_claimed' AND event_id = %d AND recipient_user_id IN ({$in})", $event_id ) );
		}
		$applied = 0;
		$booked  = 0;
		foreach ( $event_id ? $vendors : array() as $vendor ) {
			$apps = get_posts( array( 'post_type' => 'lnkup_application', 'post_status' => 'any', 'posts_per_page' => 5, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => array( array( 'key' => '_lnkup_vendor_user_id', 'value' => $vendor ), array( 'key' => '_lnkup_event_id', 'value' => $event_id ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			if ( $apps ) {
				++$applied;
				foreach ( $apps as $app ) {
					if ( in_array( (string) get_post_meta( (int) $app, '_lnkup_application_status', true ), array( 'paid', 'completed' ), true ) ) {
						++$booked;
						break;
					}
				}
			}
		}
		return array( 'interested' => count( $vendors ), 'claimed_event_id' => $event_id, 'notified' => $notified, 'applied' => $applied, 'booked' => $booked );
	}

	/* ------------------------------------------------------------ Acceptance share card */

	/**
	 * Opt-in share card for an approved/paid booking on an upcoming public event. Rendered only on
	 * that booking's detail page (?app=ID), never in booking lists.
	 */
	public static function acceptance_card_for_request( int $app_id ): string {
		$requested = isset( $_GET['app'] ) ? absint( $_GET['app'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $requested === $app_id ? self::acceptance_card( $app_id, get_current_user_id() ) : '';
	}

	public static function acceptance_card( int $app_id, int $user_id ): string {
		if ( ! class_exists( 'LNKup_Tracking_Links' ) || ! LNKup_Tracking_Links::active() || $user_id < 1 ) {
			return '';
		}
		if ( (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ) !== $user_id || ! in_array( (string) get_post_meta( $app_id, '_lnkup_application_status', true ), self::BOOKED_STATUSES, true ) ) {
			return '';
		}
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$date     = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		if ( 'publish' !== get_post_status( $event_id ) || ( '' !== $date && $date < current_time( 'Y-m-d' ) ) ) {
			return '';
		}
		$url   = LNKup_Tracking_Links::resolve_url( 'event', $event_id, '', false );
		$title = html_entity_decode( get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' );
		$city  = (string) get_post_meta( $event_id, '_lnkup_city', true );
		/* translators: 1: event, 2: date, 3: city */
		$text = trim( sprintf( __( "I'm vending at %1\$s on %2\$s in %3\$s. Come say hi!", 'lnkup-marketplace' ), $title, '' !== $date ? date_i18n( 'M j', strtotime( $date ) ) : '', $city ) );
		ob_start();
		?>
		<section class="vos-card lnkup-accept-share" data-testid="lnkup-acceptance-share" data-app="<?php echo esc_attr( (string) $app_id ); ?>">
			<h2 class="vos-section-head__title"><?php esc_html_e( 'Tell your customers where to find you', 'lnkup-marketplace' ); ?></h2>
			<p class="vos-empty__desc"><?php esc_html_e( 'Optional. Nothing is posted for you; copy the message or share it yourself. It only includes public event details.', 'lnkup-marketplace' ); ?></p>
			<textarea rows="3" readonly data-accept-text><?php echo esc_textarea( $text . "\n" . $url ); ?></textarea>
			<div class="vos-detail__actions">
				<button type="button" class="lnkup-btn lnkup-btn--outline" data-accept-share data-app="<?php echo esc_attr( (string) $app_id ); ?>" data-event-url="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Copy with tracked link', 'lnkup-marketplace' ); ?></button>
			</div>
			<p class="vos-empty__desc" role="status" aria-live="polite" data-accept-status hidden></p>
		</section>
		<script>
		document.querySelectorAll('[data-testid="lnkup-acceptance-share"]').forEach(function (card) {
			if (card.dataset.bound) return;
			card.dataset.bound = '1';
			var btn = card.querySelector('[data-accept-share]');
			var status = card.querySelector('[data-accept-status]');
			var text = card.querySelector('[data-accept-text]');
			btn.addEventListener('click', function () {
				btn.disabled = true;
				fetch(<?php echo wp_json_encode( esc_url_raw( rest_url( 'lnkup/v1/vendor/acceptance-link' ) ) ); ?>, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?> }, body: JSON.stringify({ application_id: parseInt(btn.getAttribute('data-app'), 10) }) })
					.then(function (r) { if (!r.ok || (r.headers.get('content-type') || '').indexOf('application/json') === -1) throw new Error(); return r.json(); })
					.then(function (json) {
						text.value = text.value.split(btn.getAttribute('data-event-url')).join(json.url);
						var share = text.value;
						if (navigator.share) { navigator.share({ text: share }).catch(function () {}); } else if (navigator.clipboard) { navigator.clipboard.writeText(share); }
						status.hidden = false;
						status.textContent = <?php echo wp_json_encode( __( 'Ready to share. Your tracked link counts clicks to the event page.', 'lnkup-marketplace' ) ); ?>;
					})
					.catch(function () { status.hidden = false; status.textContent = <?php echo wp_json_encode( __( 'Could not create a tracked link. The plain event link still works.', 'lnkup-marketplace' ) ); ?>; })
					.finally(function () { btn.disabled = false; });
			});
		});
		</script>
		<?php
		return (string) ob_get_clean();
	}

	public static function routes(): void {
		register_rest_route(
			'lnkup/v1',
			'/vendor/acceptance-link',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_acceptance_link' ),
				'permission_callback' => static fn(): bool => is_user_logged_in(),
			)
		);
	}

	/**
	 * Vendor-owned tracked link to the event of their own approved/paid booking (reused if present).
	 */
	public static function rest_acceptance_link( WP_REST_Request $request ) {
		$user   = get_current_user_id();
		$app_id = absint( ( $request->get_json_params() ?: array() )['application_id'] ?? $request->get_param( 'application_id' ) );
		if ( '' === self::acceptance_card( $app_id, $user ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'This booking cannot be shared.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		foreach ( LNKup_Tracking_Links::list_for_owner( $user, array( 'target_type' => 'event', 'target_id' => $event_id, 'status' => 'active' ) ) as $existing ) {
			return rest_ensure_response( array( 'url' => LNKup_Tracking_Links::url( (string) $existing['code'] ) ) );
		}
		$link = LNKup_Tracking_Links::create( array( 'owner_user_id' => $user, 'owner_type' => 'vendor', 'target_type' => 'event', 'target_id' => $event_id, 'channel' => 'other', 'label' => __( 'Booking share', 'lnkup-marketplace' ), 'campaign' => 'acceptance_share' ) );
		if ( is_wp_error( $link ) ) {
			return $link;
		}
		return rest_ensure_response( array( 'url' => LNKup_Tracking_Links::url( (string) $link['code'] ) ) );
	}

	/**
	 * A vendor may link an event they hold an approved/paid booking for.
	 */
	public static function vendor_booked_event( int $vendor, int $event_id ): bool {
		return (bool) get_posts( array( 'post_type' => 'lnkup_application', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => array( array( 'key' => '_lnkup_vendor_user_id', 'value' => $vendor ), array( 'key' => '_lnkup_event_id', 'value' => $event_id ), array( 'key' => '_lnkup_application_status', 'value' => self::BOOKED_STATUSES, 'compare' => 'IN' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
}
