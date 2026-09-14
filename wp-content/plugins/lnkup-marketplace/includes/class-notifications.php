<?php
/**
 * Email notifications.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Notifications {

	/**
	 * Send branded email.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    HTML body.
	 */
	public static function send( $to, $subject, $body ) {
		$settings = lnkup_get_settings();
		$html     = self::wrap_template( $body );
		$headers  = array( 'Content-Type: text/html; charset=UTF-8' );
		return wp_mail( $to, $subject, $html, $headers );
	}

	/**
	 * Email wrapper template.
	 *
	 * @param string $content Content.
	 */
	public static function wrap_template( $content ) {
		ob_start();
		include LNKUP_PLUGIN_DIR . 'templates/emails/wrapper.php';
		return ob_get_clean();
	}

	/**
	 * Resolve application context for email + push.
	 *
	 * @param int $app_id Application ID.
	 * @return array{
	 *   app_id:int,
	 *   event_id:int,
	 *   opportunity_id:int,
	 *   event_title:string,
	 *   opportunity_title:string,
	 *   display_title:string,
	 *   vendor_user_id:int,
	 *   organizer_user_id:int,
	 *   vendor:WP_User|false,
	 *   organizer:WP_User|false,
	 *   vendor_business:string,
	 *   vendor_apps_url:string,
	 *   vendor_payments_url:string,
	 *   vendor_pass_url:string,
	 *   vendor_ops_url:string,
	 *   organizer_vendors_url:string,
	 *   organizer_money_url:string,
	 *   organizer_roster_url:string
	 * }
	 */
	private static function application_context( $app_id ) {
		$app_id         = absint( $app_id );
		$event_id       = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$opportunity_id = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		$vendor_user_id = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$organizer_id   = (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true );
		$profile_id     = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );

		$event_title       = $event_id ? get_the_title( $event_id ) : '';
		$opportunity_title = $opportunity_id ? get_the_title( $opportunity_id ) : '';
		$display_title     = $event_title ? $event_title : ( $opportunity_title ? $opportunity_title : __( 'your opportunity', 'lnkup-marketplace' ) );

		$vendor_business = '';
		if ( $profile_id ) {
			$vendor_business = get_the_title( $profile_id );
		}
		if ( ! $vendor_business ) {
			$vendor = get_userdata( $vendor_user_id );
			$vendor_business = $vendor ? $vendor->display_name : __( 'A vendor', 'lnkup-marketplace' );
		}

		$vendor_dashboard    = lnkup_page_url_helper( 'vendor-dashboard' );
		$organizer_dashboard = lnkup_page_url_helper( 'organizer-dashboard' );

		return array(
			'app_id'                => $app_id,
			'event_id'              => $event_id,
			'opportunity_id'        => $opportunity_id,
			'event_title'           => (string) $event_title,
			'opportunity_title'     => (string) $opportunity_title,
			'display_title'         => (string) $display_title,
			'vendor_user_id'        => $vendor_user_id,
			'organizer_user_id'     => $organizer_id,
			'vendor'                => get_userdata( $vendor_user_id ),
			'organizer'             => get_userdata( $organizer_id ),
			'vendor_business'       => (string) $vendor_business,
			'vendor_apps_url'       => add_query_arg( 'view', 'applications', $vendor_dashboard ),
			'vendor_payments_url'   => add_query_arg( 'view', 'payments', $vendor_dashboard ),
			'vendor_pass_url'       => add_query_arg(
				array(
					'view' => 'operations',
					'app'  => $app_id,
					'tab'  => 'pass',
				),
				$vendor_dashboard
			),
			'vendor_ops_url'        => add_query_arg( 'view', 'operations', $vendor_dashboard ),
			'organizer_vendors_url' => add_query_arg( 'view', 'applications', $organizer_dashboard ),
			'organizer_money_url'   => $organizer_dashboard . '#financial',
			'organizer_roster_url'  => add_query_arg(
				array(
					'event'  => $event_id,
					'roster' => 1,
				),
				$organizer_dashboard
			),
		);
	}

	/**
	 * Escape-safe stacked paragraphs.
	 *
	 * @param string ...$paragraphs Plain-text paragraphs.
	 * @return string
	 */
	private static function email_paragraphs( ...$paragraphs ) {
		$html = '';
		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( (string) $paragraph );
			if ( '' === $paragraph ) {
				continue;
			}
			$html .= '<p style="margin:0 0 16px;">' . esc_html( $paragraph ) . '</p>';
		}
		return $html;
	}

	/**
	 * Lime CTA button block.
	 *
	 * @param string $url   Destination URL.
	 * @param string $label Button label.
	 * @return string
	 */
	private static function email_cta_block( $url, $label ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return '';
		}
		return '<p style="margin:24px 0 8px;"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 24px;background:#c8f542;color:#1a1b1f;text-decoration:none;border-radius:8px;font-weight:700;">'
			. esc_html( $label ) . '</a></p>';
	}

	/**
	 * Event / opportunity meta lines for emails.
	 *
	 * @param array  $ctx   application_context().
	 * @param string $extra Optional extra escaped HTML (e.g. reason).
	 * @return string
	 */
	private static function email_event_meta( $ctx, $extra = '' ) {
		$html = '<p style="margin:0 0 8px;font-weight:700;font-size:16px;color:#1a1b1f;">' . esc_html( $ctx['display_title'] ) . '</p>';
		if ( ! empty( $ctx['opportunity_title'] ) && $ctx['opportunity_title'] !== $ctx['event_title'] ) {
			$html .= '<p style="margin:0 0 16px;font-size:14px;color:#555;">' . esc_html( $ctx['opportunity_title'] ) . '</p>';
		} elseif ( '' === $extra ) {
			$html .= '<div style="margin:0 0 8px;"></div>';
		}
		if ( $extra ) {
			$html .= $extra;
		}
		return $html;
	}

	/**
	 * Safe Expo push helper — never breaks email flow.
	 *
	 * @param int    $user_id User ID.
	 * @param string $title   Title.
	 * @param string $body    Body.
	 * @param array  $data    Deep-link data.
	 */
	private static function push_user( $user_id, $title, $body, $data = array() ) {
		if ( class_exists( 'LNKup_Inbox_Service' ) ) {
			try {
				LNKup_Inbox_Service::record_from_push( $user_id, $title, $body, is_array( $data ) ? $data : array() );
			} catch ( Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup inbox record failed: ' . $e->getMessage() );
			}
		}
		if ( ! class_exists( 'LNKup_Push_Service' ) ) {
			return;
		}
		LNKup_Push_Service::notify_user( $user_id, $title, $body, $data );
	}

	/**
	 * Send application submitted.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function send_application_submitted( $app_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) ) {
			return;
		}
		$ctx  = self::application_context( $app_id );
		$user = $ctx['vendor'];
		if ( ! $user ) {
			return;
		}

		$body  = self::email_paragraphs(
			__( 'Your application has been submitted. The organizer will review it soon.', 'lnkup-marketplace' )
		);
		$body .= self::email_event_meta( $ctx );
		$body .= self::email_cta_block( $ctx['vendor_apps_url'], __( 'View your requests', 'lnkup-marketplace' ) );
		self::send( $user->user_email, __( 'Application submitted — LNKup', 'lnkup-marketplace' ), $body );

		$organizer = $ctx['organizer'];
		if ( $organizer && ! empty( $settings['notify_organizer'] ) ) {
			$org_body  = self::email_paragraphs(
				sprintf(
					/* translators: %s: vendor business name */
					__( '%s applied to one of your opportunities.', 'lnkup-marketplace' ),
					$ctx['vendor_business']
				)
			);
			$org_body .= self::email_event_meta( $ctx );
			$org_body .= self::email_cta_block( $ctx['organizer_vendors_url'], __( 'Review applications', 'lnkup-marketplace' ) );
			self::send( $organizer->user_email, __( 'New vendor application — LNKup', 'lnkup-marketplace' ), $org_body );

			$push_body = sprintf(
				/* translators: 1: vendor name, 2: event title */
				__( '%1$s applied to %2$s.', 'lnkup-marketplace' ),
				$ctx['vendor_business'],
				$ctx['display_title']
			);
			self::push_user(
				$ctx['organizer_user_id'],
				__( 'New vendor application', 'lnkup-marketplace' ),
				$push_body,
				array(
					'type'          => 'organizer_new_vendor_request',
					'applicationId' => (string) $ctx['app_id'],
					'href'          => '/(organizer)/vendors',
				)
			);
		}
	}

	/**
	 * Send application approved.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function send_application_approved( $app_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) ) {
			return;
		}
		$ctx  = self::application_context( $app_id );
		$user = $ctx['vendor'];
		if ( ! $user ) {
			return;
		}

		$body  = self::email_paragraphs(
			__( 'Congratulations! You have been approved. Complete payment to secure your spot.', 'lnkup-marketplace' )
		);
		$body .= self::email_event_meta( $ctx );
		$body .= self::email_cta_block( $ctx['vendor_payments_url'], __( 'Complete payment', 'lnkup-marketplace' ) );
		self::send( $user->user_email, __( 'Application approved — payment required', 'lnkup-marketplace' ), $body );

		$push_body = sprintf(
			/* translators: %s: event title */
			__( 'Complete payment to secure your spot for %s.', 'lnkup-marketplace' ),
			$ctx['display_title']
		);
		self::push_user(
			$ctx['vendor_user_id'],
			__( 'Application approved', 'lnkup-marketplace' ),
			$push_body,
			array(
				'type'          => 'payment_required',
				'applicationId' => (string) $ctx['app_id'],
				'href'          => '/(vendor)/wallet',
			)
		);
	}

	/**
	 * Send payment required.
	 *
	 * @param int $app_id     Application ID.
	 * @param int $payment_id Payment ID.
	 */
	public static function send_payment_required( $app_id, $payment_id ) {
		self::send_application_approved( $app_id );
	}

	/**
	 * Send payment confirmed.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function send_payment_confirmed( $app_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) ) {
			return;
		}
		$ctx  = self::application_context( $app_id );
		$user = $ctx['vendor'];
		if ( ! $user ) {
			return;
		}

		$body  = self::email_paragraphs(
			__( 'Your payment has been received. Your vendor packet and event pass are unlocked.', 'lnkup-marketplace' )
		);
		$body .= self::email_event_meta( $ctx );
		$body .= self::email_cta_block( $ctx['vendor_pass_url'], __( 'Open your pass', 'lnkup-marketplace' ) );
		self::send( $user->user_email, __( 'Payment confirmed — LNKup', 'lnkup-marketplace' ), $body );

		$push_body = sprintf(
			/* translators: %s: event title */
			__( 'Your spot for %s is confirmed. Packet unlocked.', 'lnkup-marketplace' ),
			$ctx['display_title']
		);
		self::push_user(
			$ctx['vendor_user_id'],
			__( 'Payment confirmed', 'lnkup-marketplace' ),
			$push_body,
			array(
				'type'          => 'payment_paid',
				'applicationId' => (string) $ctx['app_id'],
				'href'          => '/pass/' . $ctx['app_id'],
			)
		);
	}

	/**
	 * Send packet unlocked.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function send_packet_unlocked( $app_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) ) {
			return;
		}
		$ctx  = self::application_context( $app_id );
		$user = $ctx['vendor'];
		if ( ! $user ) {
			return;
		}

		$body  = self::email_paragraphs(
			__( 'Your load-in details and QR pass are now available.', 'lnkup-marketplace' )
		);
		$body .= self::email_event_meta( $ctx );
		$body .= self::email_cta_block( $ctx['vendor_pass_url'], __( 'View your pass', 'lnkup-marketplace' ) );
		self::send( $user->user_email, __( 'Vendor packet unlocked — LNKup', 'lnkup-marketplace' ), $body );

		$push_body = sprintf(
			/* translators: %s: event title */
			__( 'Load-in details and QR pass are ready for %s.', 'lnkup-marketplace' ),
			$ctx['display_title']
		);
		self::push_user(
			$ctx['vendor_user_id'],
			__( 'Packet unlocked', 'lnkup-marketplace' ),
			$push_body,
			array(
				'type'          => 'packet_unlocked',
				'applicationId' => (string) $ctx['app_id'],
				'href'          => '/pass/' . $ctx['app_id'],
			)
		);
	}

	/**
	 * Notify vendor when pass details are updated.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function send_pass_details_updated( $app_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) ) {
			return;
		}
		$ctx  = self::application_context( $app_id );
		$user = $ctx['vendor'];
		if ( ! $user ) {
			return;
		}

		$body  = self::email_paragraphs(
			__( 'Your organizer updated event-day details on your vendor pass.', 'lnkup-marketplace' )
		);
		$body .= self::email_event_meta( $ctx );
		$body .= self::email_cta_block( $ctx['vendor_pass_url'], __( 'View your pass', 'lnkup-marketplace' ) );
		self::send( $user->user_email, __( 'Event pass updated — LNKup', 'lnkup-marketplace' ), $body );

		$push_body = sprintf(
			/* translators: %s: event title */
			__( 'Pass details updated for %s.', 'lnkup-marketplace' ),
			$ctx['display_title']
		);
		self::push_user(
			$ctx['vendor_user_id'],
			__( 'Event pass updated', 'lnkup-marketplace' ),
			$push_body,
			array(
				'type'          => 'system',
				'applicationId' => (string) $ctx['app_id'],
				'href'          => '/pass/' . $ctx['app_id'],
			)
		);
	}

	/**
	 * Send application rejected.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function send_application_rejected( $app_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) ) {
			return;
		}
		$ctx  = self::application_context( $app_id );
		$user = $ctx['vendor'];
		if ( ! $user ) {
			return;
		}

		$reason      = (string) get_post_meta( $app_id, '_lnkup_rejection_reason', true );
		$reason_html = '';
		if ( $reason ) {
			$reason_html = '<p style="margin:0 0 16px;"><strong>' . esc_html__( 'Reason:', 'lnkup-marketplace' ) . '</strong> ' . esc_html( $reason ) . '</p>';
		}

		$body  = self::email_paragraphs(
			__( 'Your application was not accepted for this event.', 'lnkup-marketplace' )
		);
		$body .= self::email_event_meta( $ctx, $reason_html );
		$body .= self::email_cta_block( $ctx['vendor_apps_url'], __( 'View your requests', 'lnkup-marketplace' ) );
		self::send( $user->user_email, __( 'Application update — LNKup', 'lnkup-marketplace' ), $body );

		$push_body = sprintf(
			/* translators: %s: event title */
			__( 'Your application for %s was not accepted.', 'lnkup-marketplace' ),
			$ctx['display_title']
		);
		self::push_user(
			$ctx['vendor_user_id'],
			__( 'Application update', 'lnkup-marketplace' ),
			$push_body,
			array(
				'type'          => 'application_rejected',
				'applicationId' => (string) $ctx['app_id'],
				'href'          => '/(vendor)/requests',
			)
		);
	}

	/**
	 * Send waitlisted notification.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function send_application_waitlisted( $app_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) ) {
			return;
		}
		$ctx  = self::application_context( $app_id );
		$user = $ctx['vendor'];
		if ( ! $user ) {
			return;
		}

		$body  = self::email_paragraphs(
			__( 'You have been waitlisted for this opportunity. The organizer may contact you if a spot opens.', 'lnkup-marketplace' )
		);
		$body .= self::email_event_meta( $ctx );
		$body .= self::email_cta_block( $ctx['vendor_apps_url'], __( 'View your requests', 'lnkup-marketplace' ) );
		self::send( $user->user_email, __( 'Application waitlisted — LNKup', 'lnkup-marketplace' ), $body );

		$push_body = sprintf(
			/* translators: %s: event title */
			__( 'You are waitlisted for %s.', 'lnkup-marketplace' ),
			$ctx['display_title']
		);
		self::push_user(
			$ctx['vendor_user_id'],
			__( 'Application waitlisted', 'lnkup-marketplace' ),
			$push_body,
			array(
				'type'          => 'application_waitlisted',
				'applicationId' => (string) $ctx['app_id'],
				'href'          => '/(vendor)/requests',
			)
		);
	}

	/**
	 * Notify organizer of vendor payment.
	 *
	 * @param int $payment_id Payment ID.
	 */
	public static function send_organizer_vendor_paid( $payment_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_organizer'] ) ) {
			return;
		}
		$payment_id   = absint( $payment_id );
		$organizer_id = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$user         = get_userdata( $organizer_id );
		if ( ! $user ) {
			return;
		}

		$app_id = (int) get_post_meta( $payment_id, '_lnkup_application_id', true );
		$ctx    = $app_id ? self::application_context( $app_id ) : null;

		$event_title = $ctx ? $ctx['display_title'] : __( 'your event', 'lnkup-marketplace' );
		$vendor_name = $ctx ? $ctx['vendor_business'] : __( 'A vendor', 'lnkup-marketplace' );
		$money_url   = $ctx ? $ctx['organizer_money_url'] : ( lnkup_page_url_helper( 'organizer-dashboard' ) . '#financial' );

		$body  = self::email_paragraphs(
			sprintf(
				/* translators: %s: vendor business name */
				__( '%s completed payment for your event.', 'lnkup-marketplace' ),
				$vendor_name
			)
		);
		if ( $ctx ) {
			$body .= self::email_event_meta( $ctx );
		} else {
			$body .= '<p style="margin:0 0 16px;font-weight:700;">' . esc_html( $event_title ) . '</p>';
		}
		$body .= self::email_cta_block( $money_url, __( 'View money', 'lnkup-marketplace' ) );
		self::send( $user->user_email, __( 'Vendor payment received — LNKup', 'lnkup-marketplace' ), $body );

		$push_body = sprintf(
			/* translators: 1: vendor name, 2: event title */
			__( '%1$s paid for %2$s.', 'lnkup-marketplace' ),
			$vendor_name,
			$event_title
		);
		self::push_user(
			$organizer_id,
			__( 'Vendor payment received', 'lnkup-marketplace' ),
			$push_body,
			array(
				'type'          => 'organizer_payment_received',
				'paymentId'     => (string) $payment_id,
				'applicationId' => $app_id ? (string) $app_id : '',
				'href'          => '/(organizer)/money',
			)
		);
	}

	/**
	 * Notify admin of pending document.
	 *
	 * @param int $doc_id Document ID.
	 */
	public static function send_document_pending_admin( $doc_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_admin'] ) ) {
			return;
		}
		$email = $settings['support_email'] ?: get_option( 'admin_email' );
		self::send(
			$email,
			__( 'Document pending verification — LNKup', 'lnkup-marketplace' ),
			'<p>' . esc_html__( 'A new vendor document requires verification.', 'lnkup-marketplace' ) . '</p>'
		);
	}

	/**
	 * Notify admin when a vendor applied but organizer email is missing.
	 *
	 * @param array<string, mixed> $event       External event row.
	 * @param int                  $interest_id Interest row ID.
	 * @param int                  $vendor_id   Vendor user ID.
	 * @return bool
	 */
	public static function send_external_event_missing_email_admin( $event, $interest_id, $vendor_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_admin'] ) ) {
			return false;
		}

		$email = $settings['support_email'] ?: get_option( 'admin_email' );
		if ( ! is_email( $email ) ) {
			return false;
		}

		$vendor = get_userdata( $vendor_id );
		$profiles = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_profile',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_vendor_user_id',
				'meta_value'     => $vendor_id,
				'fields'         => 'ids',
			)
		);
		$business = ! empty( $profiles[0] ) ? get_the_title( (int) $profiles[0] ) : ( $vendor ? $vendor->display_name : '' );

		$title = (string) ( $event['title'] ?? __( 'External event', 'lnkup-marketplace' ) );
		$subject = sprintf(
			/* translators: %s: event title */
			__( 'Vendor applied — organizer email needed: %s', 'lnkup-marketplace' ),
			$title
		);

		$admin_url = admin_url(
			'admin.php?page=lnkup-external-events&tab=events&needs_email=1#event-' . (int) ( $event['id'] ?? 0 )
		);

		$body  = '<p>' . esc_html__( 'A vendor requested a booth opportunity, but this external event has no organizer email on file.', 'lnkup-marketplace' ) . '</p>';
		$body .= '<p><strong>' . esc_html( $title ) . '</strong></p>';
		if ( ! empty( $event['city'] ) || ! empty( $event['start_datetime'] ) ) {
			$body .= '<p>' . esc_html(
				trim(
					(string) ( $event['city'] ?? '' ) . ', ' . (string) ( $event['state'] ?? '' ) . ' · ' . (string) ( $event['start_datetime'] ?? '' ),
					', · '
				)
			) . '</p>';
		}
		if ( $business ) {
			$body .= '<p>' . sprintf(
				/* translators: %s: vendor business name */
				esc_html__( 'Vendor: %s', 'lnkup-marketplace' ),
				esc_html( $business )
			) . '</p>';
		}
		$body .= '<p>' . sprintf(
			/* translators: %d: interest ID */
			esc_html__( 'Interest ID: %d', 'lnkup-marketplace' ),
			(int) $interest_id
		) . '</p>';
		$body .= '<p><a href="' . esc_url( $admin_url ) . '" style="display:inline-block;padding:12px 24px;background:#c8ff00;color:#000;text-decoration:none;border-radius:8px;font-weight:600;">'
			. esc_html__( 'Add organizer email', 'lnkup-marketplace' ) . '</a></p>';

		return (bool) self::send( $email, $subject, $body );
	}

	/**
	 * Saved search match notification digest.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $name      Search name.
	 * @param int    $count     Match count.
	 * @param array  $items     Opportunity card items.
	 * @param array  $filters   Saved filters for restore link.
	 * @param string $search_id Search UUID.
	 */
	public static function send_saved_search_match( $user_id, $name, $count, $items = array(), $filters = array(), $search_id = '' ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) || ! LNKup_Saved_Searches::alerts_enabled() ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$city = ! empty( $filters['city'] ) ? (string) $filters['city'] : '';
		if ( $city ) {
			$subject = sprintf(
				/* translators: 1: count, 2: city */
				__( '%1$d new vendor opportunities in %2$s — LNKup', 'lnkup-marketplace' ),
				(int) $count,
				$city
			);
		} else {
			$subject = __( 'New LNKup opportunities match your search', 'lnkup-marketplace' );
		}

		$restore  = base64_encode( wp_json_encode( $filters ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$view_all = add_query_arg( 'lnkup_restore', rawurlencode( $restore ), lnkup_page_url_helper( 'opportunities' ) );
		$manage   = lnkup_page_url_helper( 'favorites' ) . '#lnkup-saved-searches-panel';

		$body  = self::email_paragraphs(
			sprintf(
				/* translators: 1: count, 2: search name */
				__( '%1$d new opportunities match your saved search "%2$s".', 'lnkup-marketplace' ),
				(int) $count,
				$name
			)
		);

		$body .= '<div style="margin:16px 0;">';
		foreach ( array_slice( (array) $items, 0, 5 ) as $item ) {
			$opp_id   = (int) ( $item['opportunity_id'] ?? 0 );
			$title    = esc_html( $item['event_name'] ?? $item['name'] ?? '' );
			$location = esc_html( $item['location_label'] ?? '' );
			$date     = esc_html( $item['event_date_label'] ?? ( $item['event_date'] ?? '' ) );
			$fee      = esc_html( $item['fee_label'] ?? '' );
			$deadline = esc_html( $item['deadline_label'] ?? '' );
			$link     = esc_url( add_query_arg( 'apply', $opp_id, lnkup_page_url_helper( 'opportunities' ) ) );
			$body    .= '<div style="border:1px solid #e5e5e5;border-radius:8px;padding:12px;margin-bottom:12px;">';
			$body    .= '<p style="margin:0 0 4px;font-weight:600;">' . $title . '</p>';
			if ( $location ) {
				$body .= '<p style="margin:0;font-size:14px;color:#555;">' . $location . '</p>';
			}
			if ( $date ) {
				$body .= '<p style="margin:4px 0 0;font-size:14px;color:#555;">' . esc_html__( 'Event:', 'lnkup-marketplace' ) . ' ' . $date . '</p>';
			}
			if ( $fee ) {
				$body .= '<p style="margin:4px 0 0;font-size:14px;color:#555;">' . esc_html__( 'Fee:', 'lnkup-marketplace' ) . ' ' . $fee . '</p>';
			}
			if ( $deadline ) {
				$body .= '<p style="margin:4px 0 0;font-size:14px;color:#555;">' . esc_html__( 'Deadline:', 'lnkup-marketplace' ) . ' ' . $deadline . '</p>';
			}
			$body .= '<p style="margin:8px 0 0;"><a href="' . $link . '" style="color:#000;font-weight:600;">' . esc_html__( 'View opportunity', 'lnkup-marketplace' ) . '</a></p>';
			$body .= '</div>';
		}
		$body .= '</div>';

		$body .= self::email_cta_block( $view_all, __( 'View all matches', 'lnkup-marketplace' ) );
		$body .= '<p style="font-size:13px;color:#666;margin-top:20px;"><a href="' . esc_url( $manage ) . '">' . esc_html__( 'Manage saved search alerts', 'lnkup-marketplace' ) . '</a></p>';

		self::send( $user->user_email, $subject, $body );

		$push_body = sprintf(
			/* translators: 1: count, 2: search name */
			__( '%1$d new matches for "%2$s".', 'lnkup-marketplace' ),
			(int) $count,
			$name
		);
		self::push_user(
			(int) $user_id,
			__( 'Saved search matches', 'lnkup-marketplace' ),
			$push_body,
			array(
				'type'     => 'discovery_saved_search',
				'searchId' => (string) $search_id,
				'href'     => '/(vendor)/saved-searches',
			)
		);
	}

	/**
	 * Favorite opportunity deadline reminder.
	 *
	 * @param int    $user_id  Vendor user ID.
	 * @param string $title    Event title.
	 * @param string $deadline Deadline date.
	 * @param string $window   Reminder window label.
	 */
	public static function send_favorite_deadline_reminder( $user_id, $title, $deadline, $window = '48h' ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) || empty( $settings['deadline_reminder_favorites'] ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$label = '24h' === $window
			? __( '24 hours', 'lnkup-marketplace' )
			: ( '48h' === $window ? __( '48 hours', 'lnkup-marketplace' ) : __( '3 days', 'lnkup-marketplace' ) );
		$find_url = lnkup_page_url_helper( 'opportunities' );
		$fav_url  = lnkup_page_url_helper( 'favorites' );

		$body  = self::email_paragraphs(
			sprintf(
				/* translators: 1: event title, 2: window, 3: deadline */
				__( '%1$s closes in about %2$s (deadline %3$s). Apply before spots fill up.', 'lnkup-marketplace' ),
				$title,
				$label,
				$deadline
			)
		);
		$body .= self::email_cta_block( $find_url, __( 'Apply now', 'lnkup-marketplace' ) );
		$body .= '<p style="margin:16px 0 0;font-size:13px;color:#666;"><a href="' . esc_url( $fav_url ) . '">' . esc_html__( 'View favorites', 'lnkup-marketplace' ) . '</a></p>';

		self::send( $user->user_email, __( 'Favorite event deadline approaching — LNKup', 'lnkup-marketplace' ), $body );

		$push_body = sprintf(
			/* translators: 1: event title, 2: window */
			__( '%1$s closes in about %2$s.', 'lnkup-marketplace' ),
			$title,
			$label
		);
		self::push_user(
			(int) $user_id,
			__( 'Favorite deadline soon', 'lnkup-marketplace' ),
			$push_body,
			array(
				'type' => 'discovery_favorite_deadline',
				'href' => '/(vendor)/favorites',
			)
		);
	}

	/**
	 * Favorite organizer posted new event.
	 *
	 * @param int $organizer_id Organizer user ID.
	 * @param int $opp_id       Opportunity ID.
	 */
	public static function send_favorite_organizer_new_event( $organizer_id, $opp_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) ) {
			return;
		}
		$row = LNKup_Marketplace_Index::get_row( $opp_id );
		if ( ! $row ) {
			return;
		}
		$event_title = (string) ( $row['title'] ?? '' );
		$view_url    = add_query_arg( 'apply', absint( $opp_id ), lnkup_page_url_helper( 'opportunities' ) );
		$vendors     = get_users( array( 'role' => 'lnkup_vendor' ) );
		foreach ( $vendors as $user ) {
			if ( ! LNKup_Vendor_Personalization::is_favorite( $user->ID, 'organizers', $organizer_id ) ) {
				continue;
			}
			$body  = self::email_paragraphs(
				sprintf(
					/* translators: %s: event title */
					__( 'An organizer you follow posted: %s', 'lnkup-marketplace' ),
					$event_title
				)
			);
			$body .= self::email_cta_block( $view_url, __( 'View opportunity', 'lnkup-marketplace' ) );
			self::send( $user->user_email, __( 'Favorite organizer posted a new event — LNKup', 'lnkup-marketplace' ), $body );

			$push_body = sprintf(
				/* translators: %s: event title */
				__( 'New event: %s', 'lnkup-marketplace' ),
				$event_title
			);
			self::push_user(
				(int) $user->ID,
				__( 'Favorite organizer posted', 'lnkup-marketplace' ),
				$push_body,
				array(
					'type'          => 'discovery_favorite_organizer',
					'opportunityId' => (string) absint( $opp_id ),
					'href'          => '/(vendor)/find',
				)
			);
		}
	}

	/**
	 * Application deadline approaching.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $title    Event title.
	 * @param string $deadline Deadline.
	 */
	public static function send_deadline_approaching( $user_id, $title, $deadline ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$find_url = lnkup_page_url_helper( 'opportunities' );
		$body     = self::email_paragraphs(
			sprintf(
				/* translators: 1: event title, 2: deadline */
				__( 'The deadline for %1$s is %2$s.', 'lnkup-marketplace' ),
				$title,
				$deadline
			)
		);
		$body .= self::email_cta_block( $find_url, __( 'View opportunities', 'lnkup-marketplace' ) );
		self::send( $user->user_email, __( 'Application deadline approaching — LNKup', 'lnkup-marketplace' ), $body );

		$push_body = sprintf(
			/* translators: 1: event title, 2: deadline */
			__( '%1$s deadline: %2$s', 'lnkup-marketplace' ),
			$title,
			$deadline
		);
		self::push_user(
			(int) $user_id,
			__( 'Deadline approaching', 'lnkup-marketplace' ),
			$push_body,
			array(
				'type' => 'discovery_deadline',
				'href' => '/(vendor)/find',
			)
		);
	}

	/**
	 * Opportunity almost full.
	 *
	 * @param int    $user_id User ID.
	 * @param string $title   Event title.
	 * @param int    $spots   Spots remaining.
	 */
	public static function send_almost_full( $user_id, $title, $spots ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$find_url = lnkup_page_url_helper( 'opportunities' );
		$body     = self::email_paragraphs(
			sprintf(
				/* translators: 1: event title, 2: spots remaining */
				__( '%1$s has only %2$d spots remaining.', 'lnkup-marketplace' ),
				$title,
				(int) $spots
			)
		);
		$body .= self::email_cta_block( $find_url, __( 'Apply before it fills', 'lnkup-marketplace' ) );
		self::send( $user->user_email, __( 'Opportunity almost full — LNKup', 'lnkup-marketplace' ), $body );

		$push_body = sprintf(
			/* translators: 1: event title, 2: spots */
			__( '%1$s — %2$d spots left', 'lnkup-marketplace' ),
			$title,
			(int) $spots
		);
		self::push_user(
			(int) $user_id,
			__( 'Almost full', 'lnkup-marketplace' ),
			$push_body,
			array(
				'type' => 'discovery_almost_full',
				'href' => '/(vendor)/find',
			)
		);
	}

	/**
	 * New event matches vendor profile.
	 *
	 * @param int    $user_id User ID.
	 * @param string $title   Event title.
	 */
	public static function send_profile_match( $user_id, $title ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$find_url = lnkup_page_url_helper( 'opportunities' );
		$body     = self::email_paragraphs(
			sprintf(
				/* translators: %s: event title */
				__( 'A new opportunity matches your vendor profile: %s', 'lnkup-marketplace' ),
				$title
			)
		);
		$body .= self::email_cta_block( $find_url, __( 'View match', 'lnkup-marketplace' ) );
		self::send( $user->user_email, __( 'New opportunity matches your profile — LNKup', 'lnkup-marketplace' ), $body );

		$push_body = sprintf(
			/* translators: %s: event title */
			__( 'Profile match: %s', 'lnkup-marketplace' ),
			$title
		);
		self::push_user(
			(int) $user_id,
			__( 'New profile match', 'lnkup-marketplace' ),
			$push_body,
			array(
				'type' => 'discovery_profile_match',
				'href' => '/(vendor)/find',
			)
		);
	}

	/**
	 * Vendor invite to apply/rebook.
	 *
	 * @param int    $invite_id Invite post ID.
	 * @param string $link      Invite URL.
	 */
	public static function send_vendor_invite( $invite_id, $link ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) ) {
			return;
		}
		$vendor_id = (int) get_post_meta( $invite_id, '_lnkup_vendor_user_id', true );
		$user      = get_userdata( $vendor_id );
		if ( ! $user ) {
			return;
		}
		$message = get_post_meta( $invite_id, '_lnkup_invite_message', true );
		$body    = self::email_paragraphs(
			__( 'An organizer invited you to apply for an event on LNKup.', 'lnkup-marketplace' )
		);
		if ( $message ) {
			$body .= self::email_paragraphs( (string) $message );
		}
		$body .= self::email_cta_block( $link, __( 'View invitation', 'lnkup-marketplace' ) );
		self::send( $user->user_email, __( 'You are invited to apply — LNKup', 'lnkup-marketplace' ), $body );

		self::push_user(
			$vendor_id,
			__( 'You are invited', 'lnkup-marketplace' ),
			__( 'An organizer invited you to apply on LNKup.', 'lnkup-marketplace' ),
			array(
				'type'     => 'discovery_invite',
				'inviteId' => (string) absint( $invite_id ),
				'href'     => '/(vendor)/find',
			)
		);
	}

	/**
	 * Prompt organizer to review vendors after event.
	 *
	 * @param int $organizer_id   Organizer user ID.
	 * @param int $application_id Application ID.
	 */
	public static function send_post_event_review_prompt( $organizer_id, $application_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_organizer'] ) ) {
			return;
		}
		$user = get_userdata( $organizer_id );
		if ( ! $user ) {
			return;
		}
		$ctx = self::application_context( $application_id );

		$body  = self::email_paragraphs(
			__( 'Your event has ended. Please leave a quick review for your vendors on LNKup.', 'lnkup-marketplace' )
		);
		$body .= self::email_event_meta( $ctx );
		if ( ! empty( $ctx['vendor_business'] ) ) {
			$body .= '<p style="margin:0 0 16px;font-size:14px;color:#555;">' . sprintf(
				/* translators: %s: vendor business name */
				esc_html__( 'Vendor: %s', 'lnkup-marketplace' ),
				esc_html( $ctx['vendor_business'] )
			) . '</p>';
		}
		$body .= self::email_cta_block( $ctx['organizer_roster_url'], __( 'Review vendors', 'lnkup-marketplace' ) );
		self::send( $user->user_email, __( 'Review your vendors — LNKup', 'lnkup-marketplace' ), $body );

		$push_body = sprintf(
			/* translators: %s: event title */
			__( 'Leave a review for vendors from %s.', 'lnkup-marketplace' ),
			$ctx['display_title']
		);
		self::push_user(
			(int) $organizer_id,
			__( 'Review your vendors', 'lnkup-marketplace' ),
			$push_body,
			array(
				'type'          => 'review_requested',
				'applicationId' => (string) absint( $application_id ),
				'href'          => '/(organizer)/vendors',
			)
		);
	}

	/**
	 * Platform outreach invite (mass campaign sign-up link).
	 *
	 * @param array  $recipient_row Recipient SQL row (email, name, token).
	 * @param string $signup_url    Tracked sign-up URL.
	 * @param array  $context       campaign, event_post.
	 * @return bool
	 */
	public static function send_platform_outreach( $recipient_row, $signup_url, $context = array() ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['outreach_emails_enabled'] ) ) {
			return false;
		}

		$campaign   = $context['campaign'] ?? array();
		$role       = $campaign['target_role'] ?? 'lnkup_vendor';
		$is_vendor  = 'lnkup_organizer' !== $role;
		$event_post = $context['event_post'] ?? null;
		$custom     = trim( (string) ( $campaign['message'] ?? '' ) );

		if ( $is_vendor ) {
			$subject = __( 'You\'re invited to join LNKup', 'lnkup-marketplace' );
			$intro   = __( 'You\'re invited to join LNKup — find vendor booth opportunities at festivals, markets, and live events.', 'lnkup-marketplace' );
		} else {
			$subject = __( 'You\'re invited to list events on LNKup', 'lnkup-marketplace' );
			$intro   = __( 'You\'re invited to list vendor opportunities on LNKup — the vendor layer for your events.', 'lnkup-marketplace' );
		}

		$greeting = ! empty( $recipient_row['name'] )
			? sprintf(
				/* translators: %s: recipient name */
				__( 'Hi %s,', 'lnkup-marketplace' ),
				$recipient_row['name']
			)
			: __( 'Hi there,', 'lnkup-marketplace' );

		$body  = '<p>' . esc_html( $greeting ) . '</p>';
		$body .= '<p>' . esc_html( $intro ) . '</p>';

		if ( $event_post instanceof WP_Post ) {
			$body .= '<p>' . sprintf(
				/* translators: %s: event title */
				esc_html__( 'An organizer invited you regarding: %s', 'lnkup-marketplace' ),
				esc_html( get_the_title( $event_post ) )
			) . '</p>';
		}

		if ( $custom ) {
			$body .= '<p>' . esc_html( $custom ) . '</p>';
		}

		$body .= '<p><a href="' . esc_url( $signup_url ) . '" style="display:inline-block;padding:12px 24px;background:#c8ff00;color:#000;text-decoration:none;border-radius:8px;font-weight:600;">'
			. esc_html__( 'Create your free account', 'lnkup-marketplace' ) . '</a></p>';
		$body .= '<p class="lnkup-muted" style="color:#666;font-size:13px;">'
			. esc_html__( 'This invitation link expires in 30 days.', 'lnkup-marketplace' ) . '</p>';

		return (bool) self::send( $recipient_row['email'], $subject, $body );
	}

	/**
	 * Notify external event organizer when a vendor requests an opportunity.
	 *
	 * @param array<string, mixed> $event     External event row.
	 * @param int                  $vendor_id Vendor user ID.
	 * @param array<string, mixed> $payload   Interest form payload.
	 * @param string               $claim_url Organizer claim URL.
	 * @param int                  $pending_count Total pending vendor interests included in this outreach.
	 * @return bool
	 */
	public static function send_external_event_vendor_interest( $event, $vendor_id, $payload, $claim_url, $pending_count = 1 ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_organizer'] ) && empty( $settings['notify_admin'] ) ) {
			return false;
		}

		$email = sanitize_email( (string) ( $event['organizer_email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return false;
		}

		$vendor = get_userdata( $vendor_id );
		$profiles = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_profile',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_vendor_user_id',
				'meta_value'     => $vendor_id,
				'fields'         => 'ids',
			)
		);
		$business = ! empty( $profiles[0] ) ? get_the_title( (int) $profiles[0] ) : ( $vendor ? $vendor->display_name : '' );

		$subject = sprintf(
			/* translators: %s: event title */
			__( 'Vendor interest for your event: %s', 'lnkup-marketplace' ),
			$event['title'] ?? __( 'External event', 'lnkup-marketplace' )
		);

		$body  = '<p>' . esc_html__( 'A vendor requested a booth opportunity for your event on LNKup.', 'lnkup-marketplace' ) . '</p>';
		if ( (int) $pending_count > 1 ) {
			$body .= '<p>' . sprintf(
				/* translators: %d: vendor count */
				esc_html__( '%d vendors have requested opportunities for your event.', 'lnkup-marketplace' ),
				(int) $pending_count
			) . '</p>';
		}
		$body .= '<p><strong>' . esc_html( $event['title'] ?? '' ) . '</strong></p>';
		if ( $business ) {
			$body .= '<p>' . sprintf(
				/* translators: %s: vendor business name */
				esc_html__( 'Vendor: %s', 'lnkup-marketplace' ),
				esc_html( $business )
			) . '</p>';
		}
		if ( ! empty( $payload['vendor_category'] ) ) {
			$body .= '<p>' . sprintf(
				/* translators: %s: vendor category */
				esc_html__( 'Category: %s', 'lnkup-marketplace' ),
				esc_html( (string) $payload['vendor_category'] )
			) . '</p>';
		}
		if ( ! empty( $payload['message'] ) ) {
			$body .= '<p>' . esc_html( (string) $payload['message'] ) . '</p>';
		}
		$body .= '<p><a href="' . esc_url( $claim_url ) . '" style="display:inline-block;padding:12px 24px;background:#c8ff00;color:#000;text-decoration:none;border-radius:8px;font-weight:600;">'
			. esc_html( lnkup_copy( 'external_event_claim_cta' ) ) . '</a></p>';
		$body .= '<p class="lnkup-muted" style="color:#666;font-size:13px;">'
			. esc_html__( 'This claim link expires in 30 days.', 'lnkup-marketplace' ) . '</p>';

		return (bool) self::send( $email, $subject, $body );
	}

	/**
	 * Follow-up email at vendor interest threshold.
	 *
	 * @param array<string, mixed> $event     External event row.
	 * @param int                  $stage     Stage 1-4.
	 * @param string               $claim_url Claim landing URL.
	 * @param array<string, mixed> $metrics   Demand metrics DTO.
	 * @return bool
	 */
	public static function send_external_event_followup( $event, $stage, $claim_url, $metrics ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_organizer'] ) && empty( $settings['notify_admin'] ) ) {
			return false;
		}

		$email = sanitize_email( (string) ( $event['organizer_email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return false;
		}

		$stage     = max( 1, min( 4, (int) $stage ) );
		$subject   = lnkup_copy( 'external_followup_subject_' . $stage );
		$body_text = lnkup_copy( 'external_followup_body_' . $stage );
		$total     = (int) ( $metrics['total'] ?? $event['vendor_interest_count'] ?? 0 );
		$score     = (int) ( $metrics['demand_score'] ?? $event['demand_score'] ?? 0 );

		$body  = '<p>' . esc_html( $body_text ) . '</p>';
		$body .= '<p><strong>' . esc_html( (string) ( $event['title'] ?? '' ) ) . '</strong></p>';
		$body .= '<p>' . sprintf(
			/* translators: 1: vendor count, 2: demand score */
			esc_html__( '%1$d vendors interested · Demand score %2$d', 'lnkup-marketplace' ),
			$total,
			$score
		) . '</p>';

		$token = wp_parse_url( $claim_url, PHP_URL_QUERY );
		parse_str( (string) $token, $query );
		$raw_token = (string) ( $query['token'] ?? '' );
		$track_url = $raw_token
			? LNKup_External_Events::build_tracked_claim_url( $raw_token, 'click' )
			: $claim_url;
		$open_pixel = $raw_token
			? LNKup_External_Events::build_tracked_claim_url( $raw_token, 'open' )
			: '';

		$body .= '<p><a href="' . esc_url( $track_url ) . '" style="display:inline-block;padding:12px 24px;background:#c8ff00;color:#000;text-decoration:none;border-radius:8px;font-weight:600;">'
			. esc_html( lnkup_copy( 'external_event_claim_cta' ) ) . '</a></p>';
		if ( $open_pixel ) {
			$body .= '<img src="' . esc_url( $open_pixel ) . '" alt="" width="1" height="1" style="display:block;border:0;" />';
		}

		return (bool) self::send( $email, $subject, $body );
	}

	/**
	 * Vendor welcome (activation drip).
	 *
	 * @param int $user_id Vendor user ID.
	 * @return bool
	 */
	public static function send_vendor_welcome( $user_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) || empty( $settings['activation_emails_enabled'] ) ) {
			return false;
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}
		$profile_url = lnkup_page_url_helper( 'vendor-profile' );
		$dash_url    = lnkup_page_url_helper( 'vendor-dashboard' );

		$body  = self::email_paragraphs(
			__( 'Welcome to LNKup — the opportunity network for live-event vendors.', 'lnkup-marketplace' ),
			__( 'Finish your profile so organizers can approve you faster, then browse booth opportunities near you.', 'lnkup-marketplace' )
		);
		$body .= self::email_cta_block( $profile_url, __( 'Complete your profile', 'lnkup-marketplace' ) );
		$body .= '<p style="margin:16px 0 0;font-size:13px;color:#666;"><a href="' . esc_url( $dash_url ) . '">' . esc_html__( 'Open vendor dashboard', 'lnkup-marketplace' ) . '</a></p>';

		$sent = (bool) self::send( $user->user_email, __( 'Welcome to LNKup', 'lnkup-marketplace' ), $body );
		if ( $sent ) {
			self::push_user(
				(int) $user_id,
				__( 'Welcome to LNKup', 'lnkup-marketplace' ),
				__( 'Complete your profile to get ready for events.', 'lnkup-marketplace' ),
				array(
					'type' => 'activation_profile',
					'href' => '/(vendor)/profile',
				)
			);
		}
		return $sent;
	}

	/**
	 * Vendor: finish profile nudge.
	 *
	 * @param int $user_id Vendor user ID.
	 * @return bool
	 */
	public static function send_vendor_complete_profile( $user_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) || empty( $settings['activation_emails_enabled'] ) ) {
			return false;
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}
		$profile_url = lnkup_page_url_helper( 'vendor-profile' );

		$body  = self::email_paragraphs(
			__( 'Your LNKup profile still needs a few details.', 'lnkup-marketplace' ),
			__( 'Add business info, photos, and documents so organizers can review and approve you with confidence.', 'lnkup-marketplace' )
		);
		$body .= self::email_cta_block( $profile_url, __( 'Finish your profile', 'lnkup-marketplace' ) );

		$sent = (bool) self::send( $user->user_email, __( 'Finish your LNKup profile', 'lnkup-marketplace' ), $body );
		if ( $sent ) {
			self::push_user(
				(int) $user_id,
				__( 'Finish your profile', 'lnkup-marketplace' ),
				__( 'Complete your vendor profile to get booking-ready.', 'lnkup-marketplace' ),
				array(
					'type' => 'activation_profile',
					'href' => '/(vendor)/profile',
				)
			);
		}
		return $sent;
	}

	/**
	 * Vendor: find events nudge.
	 *
	 * @param int $user_id Vendor user ID.
	 * @return bool
	 */
	public static function send_vendor_find_events( $user_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) || empty( $settings['activation_emails_enabled'] ) ) {
			return false;
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}
		$find_url = lnkup_page_url_helper( 'opportunities' );

		$body  = self::email_paragraphs(
			__( 'Ready to book your next booth?', 'lnkup-marketplace' ),
			__( 'Browse vendor opportunities on LNKup, save events you like, and apply when you find a fit.', 'lnkup-marketplace' )
		);
		$body .= self::email_cta_block( $find_url, __( 'Find events', 'lnkup-marketplace' ) );

		$sent = (bool) self::send( $user->user_email, __( 'Find vendor opportunities on LNKup', 'lnkup-marketplace' ), $body );
		if ( $sent ) {
			self::push_user(
				(int) $user_id,
				__( 'Find events', 'lnkup-marketplace' ),
				__( 'Browse booth opportunities near you.', 'lnkup-marketplace' ),
				array(
					'type' => 'activation_find',
					'href' => '/(vendor)/find',
				)
			);
		}
		return $sent;
	}

	/**
	 * Vendor: apply nudge.
	 *
	 * @param int $user_id Vendor user ID.
	 * @return bool
	 */
	public static function send_vendor_apply_nudge( $user_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) || empty( $settings['activation_emails_enabled'] ) ) {
			return false;
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}
		$find_url = lnkup_page_url_helper( 'opportunities' );

		$body  = self::email_paragraphs(
			__( 'You have not applied to an event yet.', 'lnkup-marketplace' ),
			__( 'Submit your first application so organizers can review your fit and get you booked.', 'lnkup-marketplace' )
		);
		$body .= self::email_cta_block( $find_url, __( 'Apply to an event', 'lnkup-marketplace' ) );

		$sent = (bool) self::send( $user->user_email, __( 'Apply to your first LNKup event', 'lnkup-marketplace' ), $body );
		if ( $sent ) {
			self::push_user(
				(int) $user_id,
				__( 'Apply to an event', 'lnkup-marketplace' ),
				__( 'Submit your first vendor application on LNKup.', 'lnkup-marketplace' ),
				array(
					'type' => 'activation_apply',
					'href' => '/(vendor)/find',
				)
			);
		}
		return $sent;
	}

	/**
	 * Organizer welcome (activation drip).
	 *
	 * @param int $user_id Organizer user ID.
	 * @return bool
	 */
	public static function send_organizer_welcome( $user_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_organizer'] ) || empty( $settings['activation_emails_enabled'] ) ) {
			return false;
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}
		$dash_url   = lnkup_page_url_helper( 'organizer-dashboard' );
		$create_url = $dash_url . '#create';

		$body  = self::email_paragraphs(
			__( 'Welcome to LNKup — list vendor opportunities for your events.', 'lnkup-marketplace' ),
			__( 'Create your first event, set booth details, and start collecting vendor applications.', 'lnkup-marketplace' )
		);
		$body .= self::email_cta_block( $create_url, __( 'Create your first event', 'lnkup-marketplace' ) );

		$sent = (bool) self::send( $user->user_email, __( 'Welcome to LNKup for organizers', 'lnkup-marketplace' ), $body );
		if ( $sent ) {
			self::push_user(
				(int) $user_id,
				__( 'Welcome to LNKup', 'lnkup-marketplace' ),
				__( 'Create your first event to start booking vendors.', 'lnkup-marketplace' ),
				array(
					'type' => 'activation_create_event',
					'href' => '/(organizer)/launch',
				)
			);
		}
		return $sent;
	}

	/**
	 * Organizer: create first event nudge.
	 *
	 * @param int $user_id Organizer user ID.
	 * @return bool
	 */
	public static function send_organizer_create_event( $user_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_organizer'] ) || empty( $settings['activation_emails_enabled'] ) ) {
			return false;
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}
		$create_url = lnkup_page_url_helper( 'organizer-dashboard' ) . '#create';

		$body  = self::email_paragraphs(
			__( 'You have not published an event on LNKup yet.', 'lnkup-marketplace' ),
			__( 'Create an event and opportunity so vendors can discover and apply to your booths.', 'lnkup-marketplace' )
		);
		$body .= self::email_cta_block( $create_url, __( 'Create event', 'lnkup-marketplace' ) );

		$sent = (bool) self::send( $user->user_email, __( 'Create your first LNKup event', 'lnkup-marketplace' ), $body );
		if ( $sent ) {
			self::push_user(
				(int) $user_id,
				__( 'Create your first event', 'lnkup-marketplace' ),
				__( 'Publish an event so vendors can apply.', 'lnkup-marketplace' ),
				array(
					'type' => 'activation_create_event',
					'href' => '/(organizer)/launch',
				)
			);
		}
		return $sent;
	}

	/**
	 * Organizer: review / manage vendors nudge.
	 *
	 * @param int $user_id Organizer user ID.
	 * @return bool
	 */
	public static function send_organizer_review_vendors( $user_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_organizer'] ) || empty( $settings['activation_emails_enabled'] ) ) {
			return false;
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}
		$apps_url = add_query_arg( 'view', 'applications', lnkup_page_url_helper( 'organizer-dashboard' ) );

		$body  = self::email_paragraphs(
			__( 'Keep your vendor pipeline moving.', 'lnkup-marketplace' ),
			__( 'Review pending applications, invite vendors you want at your event, and approve ready applicants.', 'lnkup-marketplace' )
		);
		$body .= self::email_cta_block( $apps_url, __( 'Review vendors', 'lnkup-marketplace' ) );

		$sent = (bool) self::send( $user->user_email, __( 'Review vendors on LNKup', 'lnkup-marketplace' ), $body );
		if ( $sent ) {
			self::push_user(
				(int) $user_id,
				__( 'Review vendors', 'lnkup-marketplace' ),
				__( 'Check applications and grow your vendor roster.', 'lnkup-marketplace' ),
				array(
					'type' => 'activation_org_review',
					'href' => '/(organizer)/vendors',
				)
			);
		}
		return $sent;
	}

	/**
	 * Notify vendors of operations broadcast.
	 *
	 * @param int    $event_id   Event ID.
	 * @param array  $vendor_ids Vendor user IDs.
	 * @param string $message    Message body.
	 */
	public static function send_ops_broadcast( $event_id, $vendor_ids, $message ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) ) {
			return;
		}
		$event_id   = absint( $event_id );
		$title      = get_the_title( $event_id );
		$ops_url    = add_query_arg( 'view', 'operations', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$body       = self::email_paragraphs( (string) $message );
		$body      .= '<p style="margin:0 0 16px;font-weight:700;font-size:16px;">' . esc_html( $title ) . '</p>';
		$body      .= self::email_cta_block( $ops_url, __( 'View in dashboard', 'lnkup-marketplace' ) );
		$push_title = sprintf(
			/* translators: %s: event title */
			__( 'Event update: %s', 'lnkup-marketplace' ),
			$title
		);
		$push_body = wp_strip_all_tags( (string) $message );
		if ( strlen( $push_body ) > 140 ) {
			$push_body = substr( $push_body, 0, 137 ) . '...';
		}

		foreach ( $vendor_ids as $vendor_id ) {
			$user = get_userdata( $vendor_id );
			if ( ! $user ) {
				continue;
			}
			self::send( $user->user_email, $push_title, $body );
			self::push_user(
				(int) $vendor_id,
				$push_title,
				$push_body,
				array(
					'type'    => 'system',
					'eventId' => (string) $event_id,
					'href'    => '/(vendor)/operations',
				)
			);
		}
	}
}
