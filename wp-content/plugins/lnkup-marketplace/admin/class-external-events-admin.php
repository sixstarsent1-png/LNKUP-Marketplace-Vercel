<?php
/**
 * Admin external events import and list.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Events_Admin {

	/**
	 * Init admin hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 26 );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
	}

	/**
	 * Add submenu page.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'External Events', 'lnkup-marketplace' ),
			__( 'External Events', 'lnkup-marketplace' ),
			'lnkup_manage_all',
			'lnkup-external-events',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Handle admin POST actions.
	 */
	public static function handle_actions(): void {
		if ( ! is_admin() || ! LNKup_Capabilities::user_has_manage_all() ) {
			return;
		}
		if ( empty( $_POST['lnkup_external_events_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		check_admin_referer( 'lnkup_external_events_admin' );

		$action = sanitize_key( wp_unslash( (string) $_POST['lnkup_external_events_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 'create' === $action ) {
			self::handle_create();
		} elseif ( 'import_csv' === $action ) {
			self::handle_import_csv();
		} elseif ( 'hide' === $action ) {
			self::handle_hide();
		} elseif ( 'unhide' === $action ) {
			self::handle_unhide();
		} elseif ( 'mark_claimed' === $action ) {
			self::handle_mark_claimed();
		} elseif ( 'approve' === $action ) {
			self::handle_approve();
		} elseif ( 'reject' === $action ) {
			self::handle_reject();
		} elseif ( 'import_url' === $action ) {
			self::handle_import_url();
		} elseif ( 'merge' === $action ) {
			self::handle_merge();
		} elseif ( 'refetch' === $action ) {
			self::handle_refetch();
		} elseif ( 'save_review' === $action ) {
			self::handle_save_review();
		} elseif ( 'save_email' === $action ) {
			self::handle_save_email( false );
		} elseif ( 'save_email_notify' === $action ) {
			self::handle_save_email( true );
		} elseif ( 'save_sync_settings' === $action ) {
			self::handle_save_sync_settings();
		} elseif ( 'run_sync' === $action ) {
			self::handle_run_sync();
		} elseif ( 'bulk_reject' === $action ) {
			self::handle_bulk_reject();
		} elseif ( 'bulk_approve_ready' === $action ) {
			self::handle_bulk_approve_ready();
		}
	}

	/**
	 * Create single external event.
	 */
	protected static function handle_create(): void {
		$data = array(
			'title'               => sanitize_text_field( wp_unslash( (string) ( $_POST['title'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'source_url'          => esc_url_raw( wp_unslash( (string) ( $_POST['source_url'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'date'                => sanitize_text_field( wp_unslash( (string) ( $_POST['date'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'city'                => sanitize_text_field( wp_unslash( (string) ( $_POST['city'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'state'               => sanitize_text_field( wp_unslash( (string) ( $_POST['state'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'venue_name'          => sanitize_text_field( wp_unslash( (string) ( $_POST['venue_name'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'organizer_name'      => sanitize_text_field( wp_unslash( (string) ( $_POST['organizer_name'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'organizer_url'       => esc_url_raw( wp_unslash( (string) ( $_POST['organizer_url'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'organizer_email'     => sanitize_email( wp_unslash( (string) ( $_POST['organizer_email'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'image_url'           => esc_url_raw( wp_unslash( (string) ( $_POST['image_url'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'event_type'          => sanitize_text_field( wp_unslash( (string) ( $_POST['event_type'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'description_excerpt' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['description_excerpt'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'source_event_id'     => sanitize_text_field( wp_unslash( (string) ( $_POST['source_event_id'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'application_url'     => esc_url_raw( wp_unslash( (string) ( $_POST['application_url'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'organizer_instagram' => LNKup_External_Events::sanitize_instagram( wp_unslash( (string) ( $_POST['organizer_instagram'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);

		$result = LNKup_External_Events::create( $data );
		if ( is_wp_error( $result ) ) {
			self::redirect_notice( 'error', $result->get_error_message() );
		}
		self::redirect_notice( 'success', __( 'External event added.', 'lnkup-marketplace' ) );
	}

	/**
	 * Import CSV rows.
	 */
	protected static function handle_import_csv(): void {
		$csv = isset( $_POST['csv_content'] ) ? wp_unslash( (string) $_POST['csv_content'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === trim( $csv ) && ! empty( $_FILES['csv_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$csv = (string) file_get_contents( sanitize_text_field( wp_unslash( (string) $_FILES['csv_file']['tmp_name'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		$result = LNKup_External_Events_Import::import_csv( $csv );
		if ( is_wp_error( $result ) ) {
			self::redirect_notice( 'error', $result->get_error_message() );
		}
		$message = sprintf(
			/* translators: 1: imported count, 2: skipped duplicates */
			__( 'Imported %1$d events (%2$d duplicates skipped).', 'lnkup-marketplace' ),
			(int) ( $result['created'] ?? 0 ),
			(int) ( $result['skipped'] ?? 0 )
		);
		self::redirect_notice( 'success', $message );
	}

	protected static function handle_hide(): void {
		$id = absint( $_POST['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = LNKup_External_Events::hide( $id );
		if ( is_wp_error( $result ) ) {
			self::redirect_notice( 'error', $result->get_error_message() );
		}
		self::redirect_notice( 'success', __( 'Event hidden from feed.', 'lnkup-marketplace' ) );
	}

	protected static function handle_unhide(): void {
		$id = absint( $_POST['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = LNKup_External_Events::unhide( $id );
		if ( is_wp_error( $result ) ) {
			self::redirect_notice( 'error', $result->get_error_message() );
		}
		self::redirect_notice( 'success', __( 'Event restored to feed.', 'lnkup-marketplace' ) );
	}

	protected static function handle_mark_claimed(): void {
		$id       = absint( $_POST['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$event_id = absint( $_POST['claimed_event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result   = LNKup_External_Events::mark_claimed_manual( $id, $event_id );
		if ( is_wp_error( $result ) ) {
			self::redirect_notice( 'error', $result->get_error_message() );
		}
		self::redirect_notice( 'success', __( 'Event marked as claimed.', 'lnkup-marketplace' ) );
	}

	protected static function handle_approve(): void {
		$id = absint( $_POST['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::apply_review_edits( $id );
		$result = LNKup_External_Events::publish( $id );
		if ( is_wp_error( $result ) ) {
			self::redirect_notice( 'error', $result->get_error_message() );
		}
		self::redirect_notice( 'success', __( 'Event approved and published.', 'lnkup-marketplace' ) );
	}

	/**
	 * Save review edits without publishing.
	 */
	protected static function handle_save_review(): void {
		$id = absint( $_POST['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::apply_review_edits( $id );
		self::redirect_notice( 'success', __( 'Review fields saved.', 'lnkup-marketplace' ) );
	}

	/**
	 * Save organizer email on a published event (optional notify).
	 *
	 * @param bool $force_notify Send organizer outreach after save.
	 */
	protected static function handle_save_email( bool $force_notify ): void {
		$id = absint( $_POST['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$row = LNKup_External_Events::get_row( $id );
		if ( ! $row ) {
			self::redirect_notice( 'error', __( 'Event not found.', 'lnkup-marketplace' ) );
		}

		$flush = self::apply_review_edits( $id, $force_notify );
		$title = (string) ( $row['title'] ?? '' );

		if ( ! empty( $flush['sent'] ) ) {
			self::redirect_notice(
				'success',
				sprintf(
					/* translators: 1: event title, 2: interest count */
					__( 'Organizer notified for "%1$s" (%2$d vendor interest(s)).', 'lnkup-marketplace' ),
					$title,
					(int) ( $flush['interest_count'] ?? 0 )
				)
			);
		}

		if ( $force_notify && empty( $flush['sent'] ) ) {
			self::redirect_notice(
				'error',
				sprintf(
					/* translators: %s: event title */
					__( 'Email saved for "%s", but organizer notification could not be sent (check email and pending interests).', 'lnkup-marketplace' ),
					$title
				)
			);
		}

		self::redirect_notice(
			'success',
			sprintf(
				/* translators: %s: event title */
				__( 'Organizer email saved for "%s".', 'lnkup-marketplace' ),
				$title
			)
		);
	}

	/**
	 * Re-fetch event metadata from source URL.
	 */
	protected static function handle_refetch(): void {
		$id = absint( $_POST['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::apply_review_edits( $id );
		$row = LNKup_External_Events::get_row( $id );
		if ( ! $row || empty( $row['source_url'] ) ) {
			self::redirect_notice( 'error', __( 'Event not found.', 'lnkup-marketplace' ) );
		}
		$provider = LNKup_External_Sources::detect_from_url( (string) $row['source_url'] );
		if ( ! $provider ) {
			self::redirect_notice( 'error', __( 'Unsupported source URL.', 'lnkup-marketplace' ) );
		}
		$parsed = $provider->parse_url( (string) $row['source_url'] );
		if ( is_wp_error( $parsed ) ) {
			self::redirect_notice( 'error', $parsed->get_error_message() );
		}
		$result = LNKup_External_Events::merge_refetch_fields( $id, $parsed, true );
		if ( is_wp_error( $result ) ) {
			self::redirect_notice( 'error', $result->get_error_message() );
		}
		self::redirect_notice( 'success', __( 'Event metadata refreshed from source URL.', 'lnkup-marketplace' ) );
	}

	/**
	 * Apply inline review field edits from POST.
	 *
	 * @param int  $id           External event ID.
	 * @param bool $force_notify Force organizer notification after email save.
	 * @return array{sent: bool, interest_count: int}
	 */
	protected static function apply_review_edits( int $id, bool $force_notify = false ): array {
		$flush = array(
			'sent'           => false,
			'interest_count' => 0,
		);
		if ( ! $id ) {
			return $flush;
		}

		$existing = LNKup_External_Events::get_row( $id );
		if ( ! $existing ) {
			return $flush;
		}

		$old_email = sanitize_email( (string) ( $existing['organizer_email'] ?? '' ) );
		$data      = array();
		if ( isset( $_POST['organizer_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$data['organizer_email'] = sanitize_email( wp_unslash( (string) $_POST['organizer_email'] ) );
		}
		if ( isset( $_POST['organizer_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$data['organizer_name'] = sanitize_text_field( wp_unslash( (string) $_POST['organizer_name'] ) );
		}
		if ( isset( $_POST['image_url'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$image = wp_unslash( (string) $_POST['image_url'] );
			if ( class_exists( 'LNKup_External_Source_Eventbrite' ) ) {
				$data['image_url'] = LNKup_External_Source_Eventbrite::sanitize_image_url( $image );
			} else {
				$data['image_url'] = esc_url_raw( $image );
			}
		}
		if ( isset( $_POST['event_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$data['event_type'] = sanitize_text_field( wp_unslash( (string) $_POST['event_type'] ) );
		}
		if ( isset( $_POST['application_url'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$data['application_url'] = esc_url_raw( wp_unslash( (string) $_POST['application_url'] ) );
		}
		if ( isset( $_POST['organizer_instagram'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$data['organizer_instagram'] = LNKup_External_Events::sanitize_instagram( wp_unslash( (string) $_POST['organizer_instagram'] ) );
		}
		if ( $data ) {
			LNKup_External_Events::update( $id, $data );
		}

		$row = LNKup_External_Events::get_row( $id );
		if ( ! $row || LNKup_External_Events::WORKFLOW_PUBLISHED !== ( $row['workflow_status'] ?? '' ) ) {
			return $flush;
		}

		$new_email = sanitize_email( (string) ( $row['organizer_email'] ?? '' ) );
		if ( ! is_email( $new_email ) ) {
			return $flush;
		}

		if ( $force_notify || ( ! is_email( $old_email ) && is_email( $new_email ) ) ) {
			$flush = LNKup_External_Events::flush_pending_organizer_notifications( $id );
		}

		return $flush;
	}

	/**
	 * Event type options for admin review dropdown.
	 *
	 * @return array<int, string>
	 */
	protected static function event_type_options(): array {
		$options = array( '' => __( '— Select type —', 'lnkup-marketplace' ) );
		if ( function_exists( 'lnkup_get_event_type_parents' ) ) {
			foreach ( lnkup_get_event_type_parents() as $term ) {
				$options[ $term->name ] = $term->name;
			}
		}
		return $options;
	}

	/**
	 * Render missing-field warnings for review queue.
	 *
	 * @param array<string, mixed> $row Event row.
	 */
	protected static function render_review_warnings( array $row ): void {
		$readiness    = LNKup_External_Events::review_readiness( $row );
		$warnings     = $readiness['warnings'];
		$is_published = LNKup_External_Events::WORKFLOW_PUBLISHED === ( $row['workflow_status'] ?? '' );
		$pending      = $is_published ? LNKup_External_Events::count_pending_interests( (int) ( $row['id'] ?? 0 ) ) : 0;
		$email        = (string) ( $row['organizer_email'] ?? '' );

		if ( $pending > 0 && ( '' === $email || ! is_email( $email ) ) ) {
			$warnings[] = sprintf(
				/* translators: %d: vendor count */
				__( '%d vendor(s) waiting for organizer notification', 'lnkup-marketplace' ),
				$pending
			);
		}

		if ( ! $warnings ) {
			return;
		}

		echo '<div class="lnkup-ext-review-warnings">';
		foreach ( $warnings as $warning ) {
			echo '<span class="lnkup-ext-review-warning">' . esc_html( $warning ) . '</span> ';
		}
		echo '</div>';
	}

	/**
	 * CSS class for vendor fit score tier.
	 *
	 * @param int $score Vendor fit score.
	 */
	protected static function vendor_fit_tier_class( int $score ): string {
		if ( $score >= 70 ) {
			return 'lnkup-ext-review-fit--high';
		}
		if ( $score >= 40 ) {
			return 'lnkup-ext-review-fit--medium';
		}
		return 'lnkup-ext-review-fit--low';
	}

	/**
	 * Parse bulk event IDs from POST (capped).
	 *
	 * @return array<int, int>
	 */
	protected static function parse_bulk_event_ids(): array {
		$raw = isset( $_POST['event_ids'] ) ? wp_unslash( $_POST['event_ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $raw )
				)
			)
		);

		return array_slice( $ids, 0, 50 );
	}

	/**
	 * Bulk reject pending review events.
	 */
	protected static function handle_bulk_reject(): void {
		$ids     = self::parse_bulk_event_ids();
		$rejected = 0;

		if ( ! $ids ) {
			self::redirect_notice( 'error', __( 'No events selected.', 'lnkup-marketplace' ), 'review' );
		}

		foreach ( $ids as $id ) {
			$row = LNKup_External_Events::get_row( $id );
			if ( ! $row || LNKup_External_Events::WORKFLOW_PENDING_REVIEW !== ( $row['workflow_status'] ?? '' ) ) {
				continue;
			}
			$result = LNKup_External_Events::reject( $id );
			if ( ! is_wp_error( $result ) ) {
				++$rejected;
			}
		}

		if ( $rejected < 1 ) {
			self::redirect_notice( 'error', __( 'No pending events were rejected.', 'lnkup-marketplace' ), 'review' );
		}

		self::redirect_notice(
			'success',
			sprintf(
				/* translators: %d: count */
				__( 'Rejected %d event(s).', 'lnkup-marketplace' ),
				$rejected
			),
			'review'
		);
	}

	/**
	 * Bulk approve ready pending review events.
	 */
	protected static function handle_bulk_approve_ready(): void {
		$ids      = self::parse_bulk_event_ids();
		$approved = 0;
		$skipped  = 0;

		if ( ! $ids ) {
			self::redirect_notice( 'error', __( 'No events selected.', 'lnkup-marketplace' ), 'review' );
		}

		foreach ( $ids as $id ) {
			$row = LNKup_External_Events::get_row( $id );
			if ( ! $row || LNKup_External_Events::WORKFLOW_PENDING_REVIEW !== ( $row['workflow_status'] ?? '' ) ) {
				++$skipped;
				continue;
			}

			$readiness = LNKup_External_Events::review_readiness( $row );
			if ( empty( $readiness['ready'] ) ) {
				++$skipped;
				continue;
			}

			$result = LNKup_External_Events::publish( $id );
			if ( is_wp_error( $result ) ) {
				++$skipped;
				continue;
			}
			++$approved;
		}

		if ( $approved < 1 && $skipped < 1 ) {
			self::redirect_notice( 'error', __( 'No events were approved.', 'lnkup-marketplace' ), 'review' );
		}

		if ( $skipped > 0 ) {
			self::redirect_notice(
				'success',
				sprintf(
					/* translators: 1: approved count, 2: skipped count */
					__( 'Approved %1$d ready event(s). Skipped %2$d not ready (missing email/image/type).', 'lnkup-marketplace' ),
					$approved,
					$skipped
				),
				'review'
			);
		}

		self::redirect_notice(
			'success',
			sprintf(
				/* translators: %d: count */
				__( 'Approved %d ready event(s).', 'lnkup-marketplace' ),
				$approved
			),
			'review'
		);
	}

	/**
	 * Resolve a display/storage image URL (https CDN, not Next.js proxy paths).
	 *
	 * @param array<string, mixed> $row Event row.
	 */
	protected static function review_image_url( array $row ): string {
		$raw = (string) ( $row['image_url'] ?? '' );
		if ( class_exists( 'LNKup_External_Source_Eventbrite' ) ) {
			$sanitized = LNKup_External_Source_Eventbrite::sanitize_image_url( $raw );
			if ( '' !== $sanitized ) {
				return $sanitized;
			}
		}
		return $raw;
	}

	/**
	 * Render inline review form for a pending row.
	 *
	 * @param array<string, mixed> $row                Event row.
	 * @param array<int, string>     $event_type_options Event type dropdown options.
	 */
	protected static function render_review_form( array $row, array $event_type_options ): void {
		$image_url = self::review_image_url( $row );
		?>
		<form method="post" class="lnkup-ext-review-form" data-testid="lnkup-ext-review-form-<?php echo esc_attr( (string) (int) $row['id'] ); ?>">
			<?php wp_nonce_field( 'lnkup_external_events_admin' ); ?>
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) (int) $row['id'] ); ?>" />
			<p>
				<label><?php esc_html_e( 'Organizer email', 'lnkup-marketplace' ); ?></label><br />
				<input class="regular-text" type="email" name="organizer_email" value="<?php echo esc_attr( (string) ( $row['organizer_email'] ?? '' ) ); ?>" placeholder="organizer@example.com" />
			</p>
			<p>
				<label><?php esc_html_e( 'Organizer name', 'lnkup-marketplace' ); ?></label><br />
				<input class="regular-text" type="text" name="organizer_name" value="<?php echo esc_attr( (string) ( $row['organizer_name'] ?? '' ) ); ?>" />
			</p>
			<p>
				<label><?php esc_html_e( 'Image URL', 'lnkup-marketplace' ); ?></label><br />
				<input class="large-text" type="url" name="image_url" value="<?php echo esc_attr( $image_url ); ?>" placeholder="https://cdn.evbuc.com/..." />
			</p>
			<p>
				<label><?php esc_html_e( 'Event type', 'lnkup-marketplace' ); ?></label><br />
				<select name="event_type">
					<?php foreach ( $event_type_options as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $row['event_type'] ?? '' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label><?php esc_html_e( 'Application URL', 'lnkup-marketplace' ); ?></label><br />
				<input class="large-text" type="url" name="application_url" value="<?php echo esc_attr( (string) ( $row['application_url'] ?? '' ) ); ?>" placeholder="https://example.com/vendors/apply" />
			</p>
			<p>
				<label><?php esc_html_e( 'Instagram', 'lnkup-marketplace' ); ?></label><br />
				<input class="regular-text" type="text" name="organizer_instagram" value="<?php echo esc_attr( (string) ( $row['organizer_instagram'] ?? '' ) ); ?>" placeholder="https://www.instagram.com/…" />
			</p>
			<?php if ( ! empty( $row['discovery_query'] ) ) : ?>
				<p class="description" data-testid="lnkup-ext-discovery-query">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: Google query */
							__( 'Found via: %s', 'lnkup-marketplace' ),
							(string) $row['discovery_query']
						)
					);
					?>
				</p>
			<?php endif; ?>
			<p class="submit" style="display:flex;gap:0.5rem;flex-wrap:wrap;">
				<button type="submit" class="button button-primary" name="lnkup_external_events_action" value="approve"><?php esc_html_e( 'Approve & publish', 'lnkup-marketplace' ); ?></button>
				<button type="submit" class="button" name="lnkup_external_events_action" value="save_review"><?php esc_html_e( 'Save', 'lnkup-marketplace' ); ?></button>
				<button type="submit" class="button" name="lnkup_external_events_action" value="reject"><?php esc_html_e( 'Reject', 'lnkup-marketplace' ); ?></button>
				<?php if ( ! empty( $row['source_url'] ) ) : ?>
					<button type="submit" class="button" name="lnkup_external_events_action" value="refetch"><?php esc_html_e( 'Re-fetch from URL', 'lnkup-marketplace' ); ?></button>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	protected static function handle_reject(): void {
		$id = absint( $_POST['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = LNKup_External_Events::reject( $id );
		if ( is_wp_error( $result ) ) {
			self::redirect_notice( 'error', $result->get_error_message() );
		}
		self::redirect_notice( 'success', __( 'Event rejected.', 'lnkup-marketplace' ) );
	}

	protected static function handle_import_url(): void {
		$url = esc_url_raw( wp_unslash( (string) ( $_POST['source_url'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$provider = LNKup_External_Sources::detect_from_url( $url );
		if ( ! $provider ) {
			self::redirect_notice( 'error', __( 'Unsupported URL. Use Eventbrite, Posh (/e/…), an RSS/Atom feed, or a public event website.', 'lnkup-marketplace' ) );
		}
		$data = $provider->parse_url( $url );
		if ( is_wp_error( $data ) ) {
			self::redirect_notice( 'error', $data->get_error_message() );
		}
		unset( $data['_preview_confidence'] );
		$id = LNKup_External_Events::create( $data );
		if ( is_wp_error( $id ) ) {
			self::redirect_notice( 'error', $id->get_error_message() );
		}
		self::redirect_notice( 'success', __( 'URL imported — pending review.', 'lnkup-marketplace' ) );
	}

	protected static function handle_save_sync_settings(): void {
		if ( ! class_exists( 'LNKup_External_Events_Sync' ) ) {
			self::redirect_notice( 'error', __( 'Auto sync is unavailable.', 'lnkup-marketplace' ), 'sync' );
		}
		LNKup_External_Events_Sync::save_settings( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::redirect_notice( 'success', __( 'Auto sync settings saved.', 'lnkup-marketplace' ), 'sync' );
	}

	protected static function handle_run_sync(): void {
		if ( ! class_exists( 'LNKup_External_Events_Sync' ) ) {
			self::redirect_notice( 'error', __( 'Auto sync is unavailable.', 'lnkup-marketplace' ), 'sync' );
		}
		$mode = sanitize_key( wp_unslash( (string) ( $_POST['sync_mode'] ?? 'full' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $mode, array( 'full', 'discovery', 'refresh', 'google' ), true ) ) {
			$mode = LNKup_External_Events_Sync::MODE_FULL;
		}
		$summary = LNKup_External_Events_Sync::run( $mode );
		$message = sprintf(
			/* translators: 1: discovered, 2: imported, 3: refreshed, 4: skipped, 5: vendor skipped, 6: errors */
			__( 'Sync finished: %1$d discovered, %2$d imported, %3$d refreshed, %4$d skipped, %5$d skipped (vendor filter), %6$d errors.', 'lnkup-marketplace' ),
			(int) ( $summary['discovered'] ?? 0 ),
			(int) ( $summary['imported'] ?? 0 ),
			(int) ( $summary['refreshed'] ?? 0 ),
			(int) ( $summary['skipped'] ?? 0 ),
			(int) ( $summary['skipped_vendor_filter'] ?? 0 ),
			(int) ( $summary['errors'] ?? 0 )
		);
		if ( ! empty( $summary['partial'] ) ) {
			$message .= ' ' . __( 'Partial run — time budget reached. Fewer cities, lower max candidates per city, or increase max fetch seconds.', 'lnkup-marketplace' );
		}
		$has_work = ( (int) ( $summary['imported'] ?? 0 ) + (int) ( $summary['refreshed'] ?? 0 ) ) > 0;
		$type     = ( ! empty( $summary['errors'] ) || ( ! empty( $summary['partial'] ) && ! $has_work ) ) ? 'error' : 'success';
		self::redirect_notice( $type, $message, 'sync' );
	}

	protected static function handle_merge(): void {
		$source = absint( $_POST['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$target = absint( $_POST['target_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = LNKup_External_Events_Dedup::merge( $source, $target );
		if ( is_wp_error( $result ) ) {
			self::redirect_notice( 'error', $result->get_error_message() );
		}
		self::redirect_notice( 'success', __( 'Duplicate merged.', 'lnkup-marketplace' ) );
	}

	/**
	 * @param string $type    Notice type.
	 * @param string $message Message.
	 * @param string $tab     Optional admin tab.
	 */
	protected static function redirect_notice( string $type, string $message, string $tab = '' ): void {
		$args = array(
			'page'             => 'lnkup-external-events',
			'lnkup_ext_notice' => $type,
			'lnkup_ext_msg'    => rawurlencode( $message ),
		);
		if ( '' !== $tab ) {
			$args['tab'] = sanitize_key( $tab );
		}
		wp_safe_redirect(
			add_query_arg( $args, admin_url( 'admin.php' ) )
		);
		exit;
	}

	/**
	 * Render admin page.
	 */
	public static function render_page(): void {
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ) );
		}

		$tab = sanitize_key( (string) ( $_GET['tab'] ?? 'events' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filters = array(
			'claim_status'     => sanitize_key( (string) ( $_GET['claim_status'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'workflow_status'  => sanitize_key( (string) ( $_GET['workflow_status'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'city'             => sanitize_text_field( (string) ( $_GET['city'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'platform'         => sanitize_key( (string) ( $_GET['platform'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'event_type'       => sanitize_text_field( (string) ( $_GET['event_type'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'needs_email'      => ! empty( $_GET['needs_email'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'order_by'         => sanitize_key( (string) ( $_GET['order_by'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'order'            => sanitize_key( (string) ( $_GET['order'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'min_vendor_fit'   => isset( $_GET['min_vendor_fit'] ) ? absint( $_GET['min_vendor_fit'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'ready_to_publish' => ! empty( $_GET['ready_to_publish'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		if ( 'review' === $tab && empty( $filters['workflow_status'] ) && empty( $filters['needs_email'] ) ) {
			$filters['workflow_status'] = LNKup_External_Events::WORKFLOW_PENDING_REVIEW;
		}
		if ( 'review' === $tab && empty( $filters['order_by'] ) ) {
			$filters['order_by'] = 'vendor_fit_score';
			$filters['order']    = 'DESC';
		}
		if ( ! empty( $filters['needs_email'] ) ) {
			$tab = 'events';
		}
		$list_args = array_filter(
			$filters,
			static function ( $value, $key ) {
				if ( in_array( $key, array( 'order_by', 'order' ), true ) ) {
					return '' !== (string) $value;
				}
				return ! empty( $value ) || ( 'min_vendor_fit' === $key && $value > 0 );
			},
			ARRAY_FILTER_USE_BOTH
		);
		$rows = LNKup_External_Events::list_admin( $list_args );
		$review_stats = 'review' === $tab ? LNKup_External_Events::review_queue_stats() : array();
		$dashboard = class_exists( 'LNKup_External_Events_Analytics' ) ? LNKup_External_Events_Analytics::dashboard() : array();

		$notice = sanitize_key( (string) ( $_GET['lnkup_ext_notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg    = isset( $_GET['lnkup_ext_msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['lnkup_ext_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'External Events', 'lnkup-marketplace' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Import unclaimed public event listings for vendor discovery. These are not official LNK opportunities until an organizer claims them.', 'lnkup-marketplace' ); ?></p>

			<?php if ( $notice && $msg ) : ?>
				<div class="notice notice-<?php echo 'error' === $notice ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper">
				<a class="nav-tab <?php echo 'review' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-external-events&tab=review' ) ); ?>"><?php esc_html_e( 'Review queue', 'lnkup-marketplace' ); ?></a>
				<a class="nav-tab <?php echo 'events' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-external-events&tab=events' ) ); ?>"><?php esc_html_e( 'All events', 'lnkup-marketplace' ); ?></a>
				<a class="nav-tab <?php echo 'analytics' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-external-events&tab=analytics' ) ); ?>"><?php esc_html_e( 'Analytics', 'lnkup-marketplace' ); ?></a>
				<a class="nav-tab <?php echo 'import' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-external-events&tab=import' ) ); ?>"><?php esc_html_e( 'Import', 'lnkup-marketplace' ); ?></a>
				<a class="nav-tab <?php echo 'sync' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-external-events&tab=sync' ) ); ?>"><?php esc_html_e( 'Auto sync', 'lnkup-marketplace' ); ?></a>
			</h2>

			<?php if ( 'sync' === $tab ) : ?>
				<?php
				$config     = class_exists( 'LNKup_External_Events_Sync' ) ? LNKup_External_Events_Sync::get_config() : array();
				$state      = class_exists( 'LNKup_External_Events_Sync' ) ? LNKup_External_Events_Sync::get_state() : array();
				$markets    = class_exists( 'LNKup_Market_Pages' ) ? LNKup_Market_Pages::get_all_markets() : array();
				$selected   = (array) ( $config['market_slugs'] ?? LNKup_External_Events_Sync::default_market_slugs() );
				?>
				<div data-testid="lnkup-external-autosync">
					<p class="description"><?php esc_html_e( 'Automatically discover Eventbrite and Google Search vendor-opportunity pages for launch cities and refresh stale listings. New imports land in the Review queue. Use a real server cron for reliable scheduling on production.', 'lnkup-marketplace' ); ?></p>
					<?php if ( ! empty( $state['finished_at'] ) ) : ?>
						<div class="lnkup-admin-kpi-grid" style="margin:1rem 0;">
							<div class="lnkup-admin-kpi"><strong><?php echo esc_html( (string) ( $state['finished_at'] ?? '—' ) ); ?></strong><span><?php esc_html_e( 'Last run', 'lnkup-marketplace' ); ?></span></div>
							<div class="lnkup-admin-kpi"><strong><?php echo esc_html( (string) (int) ( $state['discovered'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Discovered', 'lnkup-marketplace' ); ?></span></div>
							<div class="lnkup-admin-kpi"><strong><?php echo esc_html( (string) (int) ( $state['imported'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Imported', 'lnkup-marketplace' ); ?></span></div>
							<div class="lnkup-admin-kpi"><strong><?php echo esc_html( (string) (int) ( $state['refreshed'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Refreshed', 'lnkup-marketplace' ); ?></span></div>
							<div class="lnkup-admin-kpi"><strong><?php echo esc_html( (string) (int) ( $state['errors'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Errors', 'lnkup-marketplace' ); ?></span></div>
							<div class="lnkup-admin-kpi"><strong><?php echo esc_html( (string) (int) ( $state['skipped_vendor_filter'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Skipped (vendor filter)', 'lnkup-marketplace' ); ?></span></div>
							<div class="lnkup-admin-kpi"><strong><?php echo esc_html( (string) (int) ( $state['google_queries'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Google queries', 'lnkup-marketplace' ); ?></span></div>
						</div>
						<?php if ( ! empty( $state['last_error'] ) ) : ?>
							<p class="description"><strong><?php esc_html_e( 'Last error:', 'lnkup-marketplace' ); ?></strong> <?php echo esc_html( (string) $state['last_error'] ); ?></p>
						<?php endif; ?>
					<?php endif; ?>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-external-events&tab=review' ) ); ?>"><?php esc_html_e( 'Open review queue →', 'lnkup-marketplace' ); ?></a></p>
					<form method="post" class="lnkup-admin-form">
						<?php wp_nonce_field( 'lnkup_external_events_admin' ); ?>
						<input type="hidden" name="lnkup_external_events_action" value="save_sync_settings" />
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable auto sync', 'lnkup-marketplace' ); ?></th>
								<td><label><input type="checkbox" name="external_sync_enabled" value="1" <?php checked( ! empty( $config['enabled'] ) ); ?> /> <?php esc_html_e( 'Run scheduled Eventbrite discovery + refresh', 'lnkup-marketplace' ); ?></label></td>
							</tr>
							<tr>
								<th scope="row"><label for="external_sync_frequency"><?php esc_html_e( 'Frequency', 'lnkup-marketplace' ); ?></label></th>
								<td>
									<select name="external_sync_frequency" id="external_sync_frequency">
										<option value="daily" <?php selected( ( $config['frequency'] ?? 'daily' ), 'daily' ); ?>><?php esc_html_e( 'Daily', 'lnkup-marketplace' ); ?></option>
										<option value="twice_daily" <?php selected( ( $config['frequency'] ?? '' ), 'twice_daily' ); ?>><?php esc_html_e( 'Twice daily', 'lnkup-marketplace' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Launch cities', 'lnkup-marketplace' ); ?></th>
								<td>
									<?php foreach ( $markets as $slug => $market ) : ?>
										<?php if ( empty( $market['enabled'] ) && isset( $market['enabled'] ) ) { continue; } ?>
										<label style="display:block;margin-bottom:0.35rem;">
											<input type="checkbox" name="external_sync_market_slugs[]" value="<?php echo esc_attr( (string) $slug ); ?>" <?php checked( in_array( (string) $slug, $selected, true ) ); ?> />
											<?php echo esc_html( (string) ( $market['display_name'] ?? $market['city'] ?? $slug ) ); ?>
										</label>
									<?php endforeach; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Require vendor terms', 'lnkup-marketplace' ); ?></th>
								<td>
									<label><input type="checkbox" name="external_sync_vendor_filter_enabled" value="1" <?php checked( ! isset( $config['vendor_filter_enabled'] ) || ! empty( $config['vendor_filter_enabled'] ) ); ?> /> <?php esc_html_e( 'Only import events whose full description or title mentions vendor-related terms', 'lnkup-marketplace' ); ?></label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="external_sync_vendor_keywords"><?php esc_html_e( 'Vendor terms', 'lnkup-marketplace' ); ?></label></th>
								<td>
									<input class="large-text" type="text" name="external_sync_vendor_keywords" id="external_sync_vendor_keywords" value="<?php echo esc_attr( (string) ( lnkup_get_settings()['external_sync_vendor_keywords'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'vendor, food truck, booth, street food, artisan', 'lnkup-marketplace' ); ?>" />
									<p class="description"><?php esc_html_e( 'Comma-separated. Scans the full Eventbrite description, not just the stored excerpt. Leave empty to use built-in defaults.', 'lnkup-marketplace' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="external_sync_keywords"><?php esc_html_e( 'Additional keywords (optional AND filter)', 'lnkup-marketplace' ); ?></label></th>
								<td>
									<input class="regular-text" type="text" name="external_sync_keywords" id="external_sync_keywords" value="<?php echo esc_attr( (string) ( lnkup_get_settings()['external_sync_keywords'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'festival, food, market (optional)', 'lnkup-marketplace' ); ?>" />
									<p class="description"><?php esc_html_e( 'Comma-separated. When set, events must also match at least one of these terms.', 'lnkup-marketplace' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="external_sync_max_new_per_run"><?php esc_html_e( 'Max new imports per run', 'lnkup-marketplace' ); ?></label></th>
								<td><input type="number" min="1" max="200" class="small-text" name="external_sync_max_new_per_run" id="external_sync_max_new_per_run" value="<?php echo esc_attr( (string) (int) ( $config['max_new_per_run'] ?? 25 ) ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="external_sync_max_candidates_per_market"><?php esc_html_e( 'Max candidates per city', 'lnkup-marketplace' ); ?></label></th>
								<td>
									<input type="number" min="5" max="200" class="small-text" name="external_sync_max_candidates_per_market" id="external_sync_max_candidates_per_market" value="<?php echo esc_attr( (string) (int) ( $config['max_candidates_per_market'] ?? 35 ) ); ?>" />
									<p class="description"><?php esc_html_e( 'How many new Eventbrite pages to fetch per city per run (after vendor filter many are skipped). Lower this if sync hits the time budget.', 'lnkup-marketplace' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="external_sync_max_refresh_per_run"><?php esc_html_e( 'Max refreshes per run', 'lnkup-marketplace' ); ?></label></th>
								<td><input type="number" min="1" max="500" class="small-text" name="external_sync_max_refresh_per_run" id="external_sync_max_refresh_per_run" value="<?php echo esc_attr( (string) (int) ( $config['max_refresh_per_run'] ?? 50 ) ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="external_sync_refresh_after_days"><?php esc_html_e( 'Refresh after (days)', 'lnkup-marketplace' ); ?></label></th>
								<td><input type="number" min="1" max="90" class="small-text" name="external_sync_refresh_after_days" id="external_sync_refresh_after_days" value="<?php echo esc_attr( (string) (int) ( $config['refresh_after_days'] ?? 7 ) ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="external_sync_max_fetch_seconds"><?php esc_html_e( 'Max fetch seconds per run', 'lnkup-marketplace' ); ?></label></th>
								<td>
									<input type="number" min="60" max="600" class="small-text" name="external_sync_max_fetch_seconds" id="external_sync_max_fetch_seconds" value="<?php echo esc_attr( (string) (int) ( $config['max_fetch_seconds'] ?? 300 ) ); ?>" />
									<p class="description"><?php esc_html_e( 'Total seconds for discovery + refresh combined. Default 300. Increase if you sync many cities.', 'lnkup-marketplace' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Google Search ingest', 'lnkup-marketplace' ); ?></th>
								<td>
									<label><input type="checkbox" name="external_sync_google_enabled" value="1" <?php checked( ! empty( $config['google_enabled'] ) ); ?> /> <?php esc_html_e( 'Discover vendor-opportunity pages via Google Custom Search (review queue only)', 'lnkup-marketplace' ); ?></label>
									<p class="description"><?php esc_html_e( 'Requires a Programmable Search Engine that searches the entire web (not a single site). Do not scrape google.com HTML.', 'lnkup-marketplace' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="google_cse_api_key"><?php esc_html_e( 'Google CSE API key', 'lnkup-marketplace' ); ?></label></th>
								<td><input class="regular-text" type="password" name="google_cse_api_key" id="google_cse_api_key" value="<?php echo esc_attr( (string) ( lnkup_get_settings()['google_cse_api_key'] ?? '' ) ); ?>" autocomplete="off" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="google_cse_cx"><?php esc_html_e( 'Google CSE engine ID (cx)', 'lnkup-marketplace' ); ?></label></th>
								<td><input class="regular-text" type="text" name="google_cse_cx" id="google_cse_cx" value="<?php echo esc_attr( (string) ( lnkup_get_settings()['google_cse_cx'] ?? '' ) ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="google_search_daily_budget"><?php esc_html_e( 'Daily Google query budget', 'lnkup-marketplace' ); ?></label></th>
								<td>
									<input type="number" min="1" max="1000" class="small-text" name="google_search_daily_budget" id="google_search_daily_budget" value="<?php echo esc_attr( (string) (int) ( $config['google_daily_budget'] ?? 100 ) ); ?>" />
									<?php
									$quota = class_exists( 'LNKup_External_Events_Sync' ) ? LNKup_External_Events_Sync::google_quota_state() : array();
									if ( ! empty( $quota['date'] ) ) {
										echo '<p class="description">';
										echo esc_html(
											sprintf(
												/* translators: 1: used count, 2: date */
												__( 'Used %1$d queries on %2$s.', 'lnkup-marketplace' ),
												(int) ( $quota['count'] ?? 0 ),
												(string) $quota['date']
											)
										);
										echo '</p>';
									}
									?>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="google_search_queries_per_run"><?php esc_html_e( 'Google queries per run', 'lnkup-marketplace' ); ?></label></th>
								<td>
									<input type="number" min="1" max="25" class="small-text" name="google_search_queries_per_run" id="google_search_queries_per_run" value="<?php echo esc_attr( (string) (int) ( $config['google_queries_per_run'] ?? 10 ) ); ?>" />
									<p class="description"><?php esc_html_e( 'Chunk size. A 50 × city combinator is drained over multiple cron ticks.', 'lnkup-marketplace' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="google_search_templates"><?php esc_html_e( 'Google query templates', 'lnkup-marketplace' ); ?></label></th>
								<td>
									<textarea class="large-text" rows="8" name="google_search_templates" id="google_search_templates"><?php echo esc_textarea( (string) ( $config['google_templates'] ?? '' ) ); ?></textarea>
									<p class="description"><?php esc_html_e( 'One per line. Placeholders: {city}, {state}, {year}. Leave empty for built-in vendor-application templates.', 'lnkup-marketplace' ); ?></p>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Save auto sync settings', 'lnkup-marketplace' ) ); ?>
					</form>
					<form method="post" style="margin-top:1rem;">
						<?php wp_nonce_field( 'lnkup_external_events_admin' ); ?>
						<input type="hidden" name="lnkup_external_events_action" value="run_sync" />
						<label for="sync_mode"><?php esc_html_e( 'Run now', 'lnkup-marketplace' ); ?></label>
						<select name="sync_mode" id="sync_mode">
							<option value="full"><?php esc_html_e( 'Full (discovery + refresh)', 'lnkup-marketplace' ); ?></option>
							<option value="discovery"><?php esc_html_e( 'Discovery only', 'lnkup-marketplace' ); ?></option>
							<option value="refresh"><?php esc_html_e( 'Refresh only', 'lnkup-marketplace' ); ?></option>
							<option value="google"><?php esc_html_e( 'Google Search batch', 'lnkup-marketplace' ); ?></option>
						</select>
						<?php submit_button( __( 'Run sync now', 'lnkup-marketplace' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
			<?php elseif ( 'analytics' === $tab ) : ?>
				<?php $kpis = $dashboard['kpis'] ?? array(); ?>
				<div class="lnkup-admin-kpi-grid" data-testid="lnkup-external-analytics">
					<div class="lnkup-admin-kpi"><strong><?php echo esc_html( (string) (int) ( $kpis['external_events'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Total', 'lnkup-marketplace' ); ?></span></div>
					<div class="lnkup-admin-kpi"><strong><?php echo esc_html( (string) (int) ( $kpis['pending_review'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Pending review', 'lnkup-marketplace' ); ?></span></div>
					<div class="lnkup-admin-kpi"><strong><?php echo esc_html( (string) (float) ( $kpis['claim_rate'] ?? 0 ) ); ?>%</strong><span><?php esc_html_e( 'Claim rate', 'lnkup-marketplace' ); ?></span></div>
					<div class="lnkup-admin-kpi"><strong><?php echo esc_html( (string) (int) ( $kpis['vendor_interest'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Vendor interest', 'lnkup-marketplace' ); ?></span></div>
					<div class="lnkup-admin-kpi"><strong><?php echo esc_html( (string) (int) ( $kpis['missing_organizer_email'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Missing organizer email', 'lnkup-marketplace' ); ?></span></div>
					<div class="lnkup-admin-kpi"><strong><?php echo esc_html( (string) (int) ( $kpis['pending_organizer_notify'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Awaiting organizer notify', 'lnkup-marketplace' ); ?></span></div>
				</div>
				<?php if ( ! empty( $kpis['pending_organizer_notify'] ) ) : ?>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=lnkup-external-events&tab=events&needs_email=1' ) ); ?>"><?php esc_html_e( 'View events needing organizer email →', 'lnkup-marketplace' ); ?></a></p>
				<?php endif; ?>
			<?php elseif ( 'import' === $tab ) : ?>
			<h2><?php esc_html_e( 'Paste Eventbrite, Posh, or RSS URL', 'lnkup-marketplace' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Eventbrite and Posh event pages are scraped. RSS/Atom feeds import the next upcoming item (or ?item=0). Creates a pending-review external listing.', 'lnkup-marketplace' ); ?></p>
			<form method="post" data-testid="lnkup-external-url-form">
				<?php wp_nonce_field( 'lnkup_external_events_admin' ); ?>
				<input type="hidden" name="lnkup_external_events_action" value="import_url" />
				<p><input class="large-text" type="url" name="source_url" placeholder="https://www.eventbrite.com/e/… · https://posh.vip/e/… · https://example.com/feed" required /></p>
				<?php submit_button( __( 'Import URL (pending review)', 'lnkup-marketplace' ) ); ?>
			</form>
			<h2><?php esc_html_e( 'Add single event', 'lnkup-marketplace' ); ?></h2>
			<form method="post" class="lnkup-admin-form" data-testid="lnkup-external-event-form">
				<?php wp_nonce_field( 'lnkup_external_events_admin' ); ?>
				<input type="hidden" name="lnkup_external_events_action" value="create" />
				<table class="form-table">
					<tr><th><label for="ext_title"><?php esc_html_e( 'Title', 'lnkup-marketplace' ); ?></label></th><td><input class="regular-text" type="text" name="title" id="ext_title" required /></td></tr>
					<tr><th><label for="ext_source_url"><?php esc_html_e( 'Source URL', 'lnkup-marketplace' ); ?></label></th><td><input class="large-text" type="url" name="source_url" id="ext_source_url" required /></td></tr>
					<tr><th><label for="ext_date"><?php esc_html_e( 'Date', 'lnkup-marketplace' ); ?></label></th><td><input type="date" name="date" id="ext_date" /></td></tr>
					<tr><th><label for="ext_city"><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?></label></th><td><input type="text" name="city" id="ext_city" /></td></tr>
					<tr><th><label for="ext_state"><?php esc_html_e( 'State', 'lnkup-marketplace' ); ?></label></th><td><input type="text" name="state" id="ext_state" maxlength="2" /></td></tr>
					<tr><th><label for="ext_venue"><?php esc_html_e( 'Venue', 'lnkup-marketplace' ); ?></label></th><td><input class="regular-text" type="text" name="venue_name" id="ext_venue" /></td></tr>
					<tr><th><label for="ext_organizer"><?php esc_html_e( 'Organizer name', 'lnkup-marketplace' ); ?></label></th><td><input class="regular-text" type="text" name="organizer_name" id="ext_organizer" /></td></tr>
					<tr><th><label for="ext_organizer_email"><?php esc_html_e( 'Organizer email', 'lnkup-marketplace' ); ?></label></th><td><input class="regular-text" type="email" name="organizer_email" id="ext_organizer_email" /></td></tr>
					<tr><th><label for="ext_event_type"><?php esc_html_e( 'Event type', 'lnkup-marketplace' ); ?></label></th><td><input class="regular-text" type="text" name="event_type" id="ext_event_type" /></td></tr>
					<tr><th><label for="ext_excerpt"><?php esc_html_e( 'Description excerpt', 'lnkup-marketplace' ); ?></label></th><td><textarea name="description_excerpt" id="ext_excerpt" rows="3" class="large-text" maxlength="280"></textarea></td></tr>
				</table>
				<?php submit_button( __( 'Add event', 'lnkup-marketplace' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Bulk CSV import', 'lnkup-marketplace' ); ?></h2>
			<form method="post" enctype="multipart/form-data" data-testid="lnkup-external-csv-form">
				<?php wp_nonce_field( 'lnkup_external_events_admin' ); ?>
				<input type="hidden" name="lnkup_external_events_action" value="import_csv" />
				<p><input type="file" name="csv_file" accept=".csv,text/csv" /></p>
				<p class="description"><?php esc_html_e( 'Columns: title, source_url, date, city, state, venue, organizer_name, organizer_url, image_url, event_type, organizer_email, description_excerpt', 'lnkup-marketplace' ); ?></p>
				<?php submit_button( __( 'Import CSV', 'lnkup-marketplace' ), 'secondary' ); ?>
			</form>
			<?php elseif ( 'review' === $tab ) : ?>
			<?php
			$event_type_options = self::event_type_options();
			if ( 'start_datetime' === ( $filters['order_by'] ?? '' ) ) {
				$filters['order'] = 'ASC';
			} elseif ( empty( $filters['order'] ) ) {
				$filters['order'] = 'DESC';
			}
			?>
			<div class="lnkup-admin-kpi-grid lnkup-ext-review-kpi" data-testid="lnkup-ext-review-kpi">
				<div class="lnkup-admin-kpi"><strong><?php echo esc_html( (string) (int) ( $review_stats['pending_total'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Pending review', 'lnkup-marketplace' ); ?></span></div>
				<div class="lnkup-admin-kpi"><strong><?php echo esc_html( (string) (int) ( $review_stats['ready_count'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Ready to publish', 'lnkup-marketplace' ); ?></span></div>
				<div class="lnkup-admin-kpi"><strong><?php echo esc_html( (string) (int) ( $review_stats['missing_email_count'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Missing organizer email', 'lnkup-marketplace' ); ?></span></div>
				<div class="lnkup-admin-kpi"><strong><?php echo esc_html( (string) (float) ( $review_stats['avg_vendor_fit'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Avg vendor fit', 'lnkup-marketplace' ); ?></span></div>
			</div>

			<form method="get" class="lnkup-ext-review-filters" data-testid="lnkup-ext-review-filters">
				<input type="hidden" name="page" value="lnkup-external-events" />
				<input type="hidden" name="tab" value="review" />
				<input type="hidden" name="workflow_status" value="pending_review" />
				<select name="order_by" data-testid="lnkup-ext-review-sort">
					<option value="vendor_fit_score" <?php selected( $filters['order_by'] ?? '', 'vendor_fit_score' ); ?>><?php esc_html_e( 'Vendor fit (high to low)', 'lnkup-marketplace' ); ?></option>
					<option value="created_at" <?php selected( $filters['order_by'] ?? '', 'created_at' ); ?>><?php esc_html_e( 'Created (newest)', 'lnkup-marketplace' ); ?></option>
					<option value="start_datetime" <?php selected( $filters['order_by'] ?? '', 'start_datetime' ); ?>><?php esc_html_e( 'Event date (soonest)', 'lnkup-marketplace' ); ?></option>
				</select>
				<input type="text" name="city" placeholder="<?php esc_attr_e( 'City', 'lnkup-marketplace' ); ?>" value="<?php echo esc_attr( $filters['city'] ?? '' ); ?>" />
				<select name="platform">
					<option value=""><?php esc_html_e( 'All sources', 'lnkup-marketplace' ); ?></option>
					<option value="eventbrite" <?php selected( $filters['platform'] ?? '', 'eventbrite' ); ?>><?php esc_html_e( 'Eventbrite', 'lnkup-marketplace' ); ?></option>
					<option value="google" <?php selected( $filters['platform'] ?? '', 'google' ); ?>><?php esc_html_e( 'Google Search', 'lnkup-marketplace' ); ?></option>
					<option value="posh" <?php selected( $filters['platform'] ?? '', 'posh' ); ?>><?php esc_html_e( 'Posh', 'lnkup-marketplace' ); ?></option>
					<option value="rss" <?php selected( $filters['platform'] ?? '', 'rss' ); ?>><?php esc_html_e( 'RSS', 'lnkup-marketplace' ); ?></option>
				</select>
				<label>
					<?php esc_html_e( 'Min vendor fit', 'lnkup-marketplace' ); ?>
					<input type="number" min="0" max="100" class="small-text" name="min_vendor_fit" value="<?php echo esc_attr( (string) (int) ( $filters['min_vendor_fit'] ?? 0 ) ); ?>" data-testid="lnkup-ext-review-min-fit" />
				</label>
				<label style="margin-left:0.5rem;">
					<input type="checkbox" name="ready_to_publish" value="1" <?php checked( ! empty( $filters['ready_to_publish'] ) ); ?> data-testid="lnkup-ext-review-ready-only" />
					<?php esc_html_e( 'Ready to publish only', 'lnkup-marketplace' ); ?>
				</label>
				<?php submit_button( __( 'Filter', 'lnkup-marketplace' ), 'secondary', '', false ); ?>
			</form>

			<form method="post" id="lnkup-ext-review-bulk-form" class="lnkup-ext-review-bulk-bar" data-testid="lnkup-ext-review-bulk-bar">
				<?php wp_nonce_field( 'lnkup_external_events_admin' ); ?>
				<button type="submit" class="button" name="lnkup_external_events_action" value="bulk_reject"><?php esc_html_e( 'Reject selected', 'lnkup-marketplace' ); ?></button>
				<button type="submit" class="button button-primary" name="lnkup_external_events_action" value="bulk_approve_ready"><?php esc_html_e( 'Approve ready selected', 'lnkup-marketplace' ); ?></button>
			</form>

			<table class="widefat striped lnkup-ext-review-table" data-testid="lnkup-ext-review-table">
				<thead>
					<tr>
						<th class="check-column"><input type="checkbox" id="lnkup-ext-review-select-all" aria-label="<?php esc_attr_e( 'Select all', 'lnkup-marketplace' ); ?>" /></th>
						<th><?php esc_html_e( 'Preview', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Date', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Vendor fit', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Readiness', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'lnkup-marketplace' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No events pending review.', 'lnkup-marketplace' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$row_id       = (int) ( $row['id'] ?? 0 );
						$fit_score    = (int) ( $row['vendor_fit_score'] ?? 0 );
						$readiness    = LNKup_External_Events::review_readiness( $row );
						$image_url    = self::review_image_url( $row );
						$show_thumb   = '' !== $image_url && str_starts_with( strtolower( $image_url ), 'https://' );
						$relevance    = LNKup_External_Events::score_vendor_relevance(
							(string) ( $row['title'] ?? '' ),
							(string) ( $row['description_excerpt'] ?? '' ),
							(string) ( $row['event_type'] ?? '' )
						);
						$matched_terms = $relevance['matched'] ?? array();
						?>
						<tr id="event-<?php echo esc_attr( (string) $row_id ); ?>">
							<th scope="row" class="check-column">
								<input type="checkbox" name="event_ids[]" form="lnkup-ext-review-bulk-form" value="<?php echo esc_attr( (string) $row_id ); ?>" />
							</th>
							<td>
								<div class="lnkup-ext-review-preview">
									<?php if ( $show_thumb ) : ?>
										<img src="<?php echo esc_url( $image_url ); ?>" alt="" class="lnkup-ext-review-preview__thumb" width="48" height="48" loading="lazy" />
									<?php endif; ?>
									<p class="lnkup-ext-review-preview__excerpt"><?php echo esc_html( (string) ( $row['description_excerpt'] ?? '' ) ); ?></p>
								</div>
							</td>
							<td>
								<strong><?php echo esc_html( (string) ( $row['title'] ?? '' ) ); ?></strong>
								<?php if ( ! empty( $matched_terms ) ) : ?>
									<p class="description" title="<?php echo esc_attr( implode( ', ', $matched_terms ) ); ?>">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: comma-separated vendor terms */
												__( 'Matched: %s', 'lnkup-marketplace' ),
												implode( ', ', array_slice( $matched_terms, 0, 4 ) )
											)
										);
										?>
									</p>
								<?php endif; ?>
								<a href="<?php echo esc_url( (string) ( $row['source_url'] ?? '#' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Source', 'lnkup-marketplace' ); ?></a>
								<?php if ( ! empty( $row['source_platform'] ) ) : ?>
									<span class="description"><?php echo esc_html( (string) $row['source_platform'] ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) ( $row['start_datetime'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( trim( ( $row['city'] ?? '' ) . ', ' . ( $row['state'] ?? '' ), ', ' ) ); ?></td>
							<td>
								<span class="lnkup-ext-review-fit <?php echo esc_attr( self::vendor_fit_tier_class( $fit_score ) ); ?>" data-testid="lnkup-ext-vendor-fit-<?php echo esc_attr( (string) $row_id ); ?>">
									<?php echo esc_html( (string) $fit_score ); ?>
								</span>
							</td>
							<td>
								<?php if ( ! empty( $readiness['ready'] ) ) : ?>
									<span class="lnkup-ext-review-ready"><?php esc_html_e( 'Ready', 'lnkup-marketplace' ); ?></span>
								<?php else : ?>
									<span class="lnkup-ext-review-needs"><?php esc_html_e( 'Needs fields', 'lnkup-marketplace' ); ?></span>
									<?php self::render_review_warnings( $row ); ?>
								<?php endif; ?>
							</td>
							<td>
								<?php self::render_review_form( $row, $event_type_options ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			<script>
			(function () {
				var selectAll = document.getElementById('lnkup-ext-review-select-all');
				if (!selectAll) { return; }
				selectAll.addEventListener('change', function () {
					document.querySelectorAll('input[name="event_ids[]"]').forEach(function (cb) {
						cb.checked = selectAll.checked;
					});
				});
			})();
			</script>
			<?php elseif ( 'events' === $tab ) : ?>
			<?php $event_type_options = self::event_type_options(); ?>
			<form method="get">
				<input type="hidden" name="page" value="lnkup-external-events" />
				<select name="claim_status">
					<option value=""><?php esc_html_e( 'All statuses', 'lnkup-marketplace' ); ?></option>
					<option value="unclaimed" <?php selected( $filters['claim_status'], 'unclaimed' ); ?>><?php esc_html_e( 'Unclaimed', 'lnkup-marketplace' ); ?></option>
					<option value="hidden" <?php selected( $filters['claim_status'], 'hidden' ); ?>><?php esc_html_e( 'Hidden', 'lnkup-marketplace' ); ?></option>
					<option value="claimed" <?php selected( $filters['claim_status'], 'claimed' ); ?>><?php esc_html_e( 'Claimed', 'lnkup-marketplace' ); ?></option>
				</select>
				<select name="workflow_status">
					<option value=""><?php esc_html_e( 'All workflow', 'lnkup-marketplace' ); ?></option>
					<option value="pending_review" <?php selected( $filters['workflow_status'] ?? '', 'pending_review' ); ?>><?php esc_html_e( 'Pending review', 'lnkup-marketplace' ); ?></option>
					<option value="published" <?php selected( $filters['workflow_status'] ?? '', 'published' ); ?>><?php esc_html_e( 'Published', 'lnkup-marketplace' ); ?></option>
					<option value="hidden" <?php selected( $filters['workflow_status'] ?? '', 'hidden' ); ?>><?php esc_html_e( 'Hidden', 'lnkup-marketplace' ); ?></option>
					<option value="rejected" <?php selected( $filters['workflow_status'] ?? '', 'rejected' ); ?>><?php esc_html_e( 'Rejected', 'lnkup-marketplace' ); ?></option>
				</select>
				<input type="hidden" name="tab" value="events" />
				<input type="text" name="city" placeholder="<?php esc_attr_e( 'City', 'lnkup-marketplace' ); ?>" value="<?php echo esc_attr( $filters['city'] ?? '' ); ?>" />
				<label style="margin-left:0.5rem;">
					<input type="checkbox" name="needs_email" value="1" <?php checked( ! empty( $filters['needs_email'] ) ); ?> />
					<?php esc_html_e( 'Needs organizer email', 'lnkup-marketplace' ); ?>
				</label>
				<?php submit_button( __( 'Filter', 'lnkup-marketplace' ), 'secondary', '', false ); ?>
			</form>

			<table class="widefat striped" data-testid="lnkup-external-events-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Date', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Location', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Workflow', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Demand', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Status', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Interest', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Email on file', 'lnkup-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'lnkup-marketplace' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="9"><?php esc_html_e( 'No external events yet.', 'lnkup-marketplace' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$has_email = ! empty( $row['organizer_email'] ) && is_email( (string) $row['organizer_email'] );
						$is_pending = LNKup_External_Events::WORKFLOW_PENDING_REVIEW === ( $row['workflow_status'] ?? '' );
						$is_published = LNKup_External_Events::WORKFLOW_PUBLISHED === ( $row['workflow_status'] ?? '' );
						$is_unclaimed = 'unclaimed' === ( $row['claim_status'] ?? '' );
						$pending_notify = $is_published ? LNKup_External_Events::count_pending_interests( (int) ( $row['id'] ?? 0 ) ) : 0;
						$show_email_form = $is_published && $is_unclaimed && (
							$pending_notify > 0
							|| ( ! $has_email && (int) ( $row['vendor_interest_count'] ?? 0 ) > 0 )
						);
						$show_warnings = $is_pending || $show_email_form;
						?>
						<tr id="event-<?php echo esc_attr( (string) (int) $row['id'] ); ?>">
							<td>
								<strong><?php echo esc_html( (string) ( $row['title'] ?? '' ) ); ?></strong>
								<?php if ( $is_pending && (int) ( $row['vendor_fit_score'] ?? 0 ) > 0 ) : ?>
									<span class="lnkup-ext-review-warning" data-testid="lnkup-ext-vendor-fit-<?php echo esc_attr( (string) (int) $row['id'] ); ?>">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: vendor fit score */
												__( 'Vendor fit: %d', 'lnkup-marketplace' ),
												(int) ( $row['vendor_fit_score'] ?? 0 )
											)
										);
										?>
									</span>
								<?php endif; ?>
								<?php if ( $show_warnings ) : ?>
									<?php self::render_review_warnings( $row ); ?>
								<?php endif; ?>
								<br><a href="<?php echo esc_url( (string) ( $row['source_url'] ?? '#' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Source', 'lnkup-marketplace' ); ?></a>
							</td>
							<td><?php echo esc_html( (string) ( $row['start_datetime'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( trim( ( $row['city'] ?? '' ) . ', ' . ( $row['state'] ?? '' ), ', ' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['workflow_status'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) (int) ( $row['demand_score'] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['claim_status'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) (int) ( $row['vendor_interest_count'] ?? 0 ) ); ?></td>
							<td>
								<?php if ( $has_email ) : ?>
									<span class="lnkup-ext-email-yes" data-testid="lnkup-ext-email-on-file"><?php esc_html_e( 'Yes', 'lnkup-marketplace' ); ?></span>
								<?php else : ?>
									<span class="lnkup-ext-email-no" data-testid="lnkup-ext-email-missing"><?php esc_html_e( 'No', 'lnkup-marketplace' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $is_pending ) : ?>
									<?php self::render_review_form( $row, $event_type_options ); ?>
								<?php elseif ( $show_email_form ) : ?>
									<form method="post" class="lnkup-ext-email-form" data-testid="lnkup-ext-email-form-<?php echo esc_attr( (string) (int) $row['id'] ); ?>">
										<?php wp_nonce_field( 'lnkup_external_events_admin' ); ?>
										<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) (int) $row['id'] ); ?>" />
										<p>
											<label><?php esc_html_e( 'Organizer email', 'lnkup-marketplace' ); ?></label><br />
											<input class="regular-text" type="email" name="organizer_email" value="<?php echo esc_attr( (string) ( $row['organizer_email'] ?? '' ) ); ?>" placeholder="organizer@example.com" required />
										</p>
										<?php if ( $pending_notify > 0 ) : ?>
											<p class="description" data-testid="lnkup-ext-pending-notify-count">
												<?php
												echo esc_html(
													sprintf(
														/* translators: %d: vendor count */
														__( '%d vendor(s) waiting for organizer notification', 'lnkup-marketplace' ),
														$pending_notify
													)
												);
												?>
											</p>
										<?php endif; ?>
										<p class="submit" style="display:flex;gap:0.5rem;flex-wrap:wrap;">
											<button type="submit" class="button" name="lnkup_external_events_action" value="save_email"><?php esc_html_e( 'Save email', 'lnkup-marketplace' ); ?></button>
											<button type="submit" class="button button-primary" name="lnkup_external_events_action" value="save_email_notify"><?php esc_html_e( 'Save & notify organizer', 'lnkup-marketplace' ); ?></button>
										</p>
									</form>
								<?php else : ?>
								<?php if ( (int) ( $row['duplicate_confidence'] ?? 0 ) >= 70 ) : ?>
									<span class="description"><?php echo esc_html( sprintf( __( 'Possible duplicate (%d%%)', 'lnkup-marketplace' ), (int) $row['duplicate_confidence'] ) ); ?></span>
								<?php endif; ?>
								<?php if ( 'hidden' === ( $row['claim_status'] ?? '' ) ) : ?>
									<form method="post" style="display:inline;">
										<?php wp_nonce_field( 'lnkup_external_events_admin' ); ?>
										<input type="hidden" name="lnkup_external_events_action" value="unhide" />
										<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) (int) $row['id'] ); ?>" />
										<button type="submit" class="button button-small"><?php esc_html_e( 'Unhide', 'lnkup-marketplace' ); ?></button>
									</form>
								<?php elseif ( 'unclaimed' === ( $row['claim_status'] ?? '' ) ) : ?>
									<form method="post" style="display:inline;">
										<?php wp_nonce_field( 'lnkup_external_events_admin' ); ?>
										<input type="hidden" name="lnkup_external_events_action" value="hide" />
										<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) (int) $row['id'] ); ?>" />
										<button type="submit" class="button button-small"><?php esc_html_e( 'Hide', 'lnkup-marketplace' ); ?></button>
									</form>
									<form method="post" style="display:inline;">
										<?php wp_nonce_field( 'lnkup_external_events_admin' ); ?>
										<input type="hidden" name="lnkup_external_events_action" value="mark_claimed" />
										<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) (int) $row['id'] ); ?>" />
										<button type="submit" class="button button-small"><?php esc_html_e( 'Mark claimed', 'lnkup-marketplace' ); ?></button>
									</form>
								<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
