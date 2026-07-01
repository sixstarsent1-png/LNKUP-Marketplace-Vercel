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
	 * Send application submitted.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function send_application_submitted( $app_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) ) {
			return;
		}
		$vendor_id = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$user      = get_userdata( $vendor_id );
		if ( ! $user ) {
			return;
		}
		self::send(
			$user->user_email,
			__( 'Application submitted — LNKup', 'lnkup-marketplace' ),
			'<p>' . esc_html__( 'Your application has been submitted. The organizer will review it soon.', 'lnkup-marketplace' ) . '</p>'
		);

		$organizer_id = (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true );
		$organizer    = get_userdata( $organizer_id );
		if ( $organizer && ! empty( $settings['notify_organizer'] ) ) {
			self::send(
				$organizer->user_email,
				__( 'New vendor application — LNKup', 'lnkup-marketplace' ),
				'<p>' . esc_html__( 'A vendor has applied to one of your opportunities.', 'lnkup-marketplace' ) . '</p>'
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
		$vendor_id = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$user      = get_userdata( $vendor_id );
		if ( ! $user ) {
			return;
		}
		self::send(
			$user->user_email,
			__( 'Application approved — payment required', 'lnkup-marketplace' ),
			'<p>' . esc_html__( 'Congratulations! You have been approved. Please complete payment to secure your spot.', 'lnkup-marketplace' ) . '</p>'
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
		$vendor_id = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$user      = get_userdata( $vendor_id );
		if ( ! $user ) {
			return;
		}
		self::send(
			$user->user_email,
			__( 'Payment confirmed — LNKup', 'lnkup-marketplace' ),
			'<p>' . esc_html__( 'Your payment has been received. Your vendor packet is now unlocked.', 'lnkup-marketplace' ) . '</p>'
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
		$vendor_id = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$user      = get_userdata( $vendor_id );
		if ( ! $user ) {
			return;
		}
		self::send(
			$user->user_email,
			__( 'Vendor packet unlocked — LNKup', 'lnkup-marketplace' ),
			'<p>' . esc_html__( 'Your load-in details and QR pass are now available in your dashboard.', 'lnkup-marketplace' ) . '</p>'
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
		$vendor_id = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$user      = get_userdata( $vendor_id );
		if ( ! $user ) {
			return;
		}
		$dashboard = lnkup_page_url_helper( 'vendor-dashboard' );
		$pass_url  = add_query_arg(
			array(
				'view' => 'operations',
				'app'  => absint( $app_id ),
				'tab'  => 'pass',
			),
			$dashboard
		);
		self::send(
			$user->user_email,
			__( 'Event pass updated — LNKup', 'lnkup-marketplace' ),
			'<p>' . esc_html__( 'Your organizer updated event-day details on your vendor pass.', 'lnkup-marketplace' ) . '</p>'
			. '<p><a href="' . esc_url( $pass_url ) . '">' . esc_html__( 'View your pass', 'lnkup-marketplace' ) . '</a></p>'
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
		$vendor_id = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$user      = get_userdata( $vendor_id );
		if ( ! $user ) {
			return;
		}
		$reason = get_post_meta( $app_id, '_lnkup_rejection_reason', true );
		$body   = '<p>' . esc_html__( 'Your application was not accepted for this event.', 'lnkup-marketplace' ) . '</p>';
		if ( $reason ) {
			$body .= '<p><strong>' . esc_html__( 'Reason:', 'lnkup-marketplace' ) . '</strong> ' . esc_html( $reason ) . '</p>';
		}
		self::send( $user->user_email, __( 'Application update — LNKup', 'lnkup-marketplace' ), $body );
	}

	/**
	 * Send waitlisted notification.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function send_application_waitlisted( $app_id ) {
		$vendor_id = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$user      = get_userdata( $vendor_id );
		if ( ! $user ) {
			return;
		}
		self::send(
			$user->user_email,
			__( 'Application waitlisted — LNKup', 'lnkup-marketplace' ),
			'<p>' . esc_html__( 'You have been waitlisted for this opportunity. The organizer may contact you if a spot opens.', 'lnkup-marketplace' ) . '</p>'
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
		$organizer_id = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$user         = get_userdata( $organizer_id );
		if ( ! $user ) {
			return;
		}
		self::send(
			$user->user_email,
			__( 'Vendor payment received — LNKup', 'lnkup-marketplace' ),
			'<p>' . esc_html__( 'A vendor has completed payment for your event.', 'lnkup-marketplace' ) . '</p>'
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

		$restore = base64_encode( wp_json_encode( $filters ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$view_all = add_query_arg( 'lnkup_restore', rawurlencode( $restore ), lnkup_page_url_helper( 'opportunities' ) );
		$manage   = lnkup_page_url_helper( 'favorites' ) . '#lnkup-saved-searches-panel';

		$body  = '<p>' . sprintf(
			/* translators: 1: count, 2: search name */
			esc_html__( '%1$d new opportunities match your saved search "%2$s".', 'lnkup-marketplace' ),
			(int) $count,
			esc_html( $name )
		) . '</p>';

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

		$body .= '<p><a href="' . esc_url( $view_all ) . '" style="display:inline-block;background:#c8ff00;color:#000;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:600;">' . esc_html__( 'View all matches', 'lnkup-marketplace' ) . '</a></p>';
		$body .= '<p style="font-size:13px;color:#666;margin-top:20px;"><a href="' . esc_url( $manage ) . '">' . esc_html__( 'Manage saved search alerts', 'lnkup-marketplace' ) . '</a></p>';

		self::send( $user->user_email, $subject, $body );
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
		self::send(
			$user->user_email,
			__( 'Favorite event deadline approaching — LNKup', 'lnkup-marketplace' ),
			'<p>' . sprintf(
				/* translators: 1: event title, 2: window, 3: deadline */
				esc_html__( '%1$s closes in about %2$s (deadline %3$s). Apply before spots fill up.', 'lnkup-marketplace' ),
				esc_html( $title ),
				esc_html( $label ),
				esc_html( $deadline )
			) . '</p>'
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
		$vendors = get_users( array( 'role' => 'lnkup_vendor' ) );
		foreach ( $vendors as $user ) {
			if ( ! LNKup_Vendor_Personalization::is_favorite( $user->ID, 'organizers', $organizer_id ) ) {
				continue;
			}
			self::send(
				$user->user_email,
				__( 'Favorite organizer posted a new event — LNKup', 'lnkup-marketplace' ),
				'<p>' . sprintf(
					/* translators: %s: event title */
					esc_html__( 'An organizer you follow posted: %s', 'lnkup-marketplace' ),
					esc_html( $row['title'] )
				) . '</p>'
			);
		}
	}

	/**
	 * Application deadline approaching.
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
		self::send(
			$user->user_email,
			__( 'Application deadline approaching — LNKup', 'lnkup-marketplace' ),
			'<p>' . sprintf(
				/* translators: 1: event title, 2: deadline */
				esc_html__( 'The deadline for %1$s is %2$s.', 'lnkup-marketplace' ),
				esc_html( $title ),
				esc_html( $deadline )
			) . '</p>'
		);
	}

	/**
	 * Opportunity almost full.
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
		self::send(
			$user->user_email,
			__( 'Opportunity almost full — LNKup', 'lnkup-marketplace' ),
			'<p>' . sprintf(
				/* translators: 1: event title, 2: spots remaining */
				esc_html__( '%1$s has only %2$d spots remaining.', 'lnkup-marketplace' ),
				esc_html( $title ),
				(int) $spots
			) . '</p>'
		);
	}

	/**
	 * New event matches vendor profile.
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
		self::send(
			$user->user_email,
			__( 'New opportunity matches your profile — LNKup', 'lnkup-marketplace' ),
			'<p>' . sprintf(
				/* translators: %s: event title */
				esc_html__( 'A new opportunity matches your vendor profile: %s', 'lnkup-marketplace' ),
				esc_html( $title )
			) . '</p>'
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
		$user = get_userdata( $vendor_id );
		if ( ! $user ) {
			return;
		}
		$message = get_post_meta( $invite_id, '_lnkup_invite_message', true );
		$body = '<p>' . esc_html__( 'An organizer invited you to apply for an event on LNKup.', 'lnkup-marketplace' ) . '</p>';
		if ( $message ) {
			$body .= '<p>' . esc_html( $message ) . '</p>';
		}
		$body .= '<p><a href="' . esc_url( $link ) . '">' . esc_html__( 'View invitation', 'lnkup-marketplace' ) . '</a></p>';
		self::send( $user->user_email, __( 'You are invited to apply — LNKup', 'lnkup-marketplace' ), $body );
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
		$event_id = (int) get_post_meta( $application_id, '_lnkup_event_id', true );
		$roster_url = add_query_arg(
			array( 'event' => $event_id, 'roster' => 1 ),
			lnkup_page_url_helper( 'organizer-dashboard' )
		);
		$body = '<p>' . esc_html__( 'Your event has ended. Please review your vendors on LNKup.', 'lnkup-marketplace' ) . '</p>';
		$body .= '<p><a href="' . esc_url( $roster_url ) . '">' . esc_html__( 'Review vendors', 'lnkup-marketplace' ) . '</a></p>';
		self::send( $user->user_email, __( 'Review your vendors — LNKup', 'lnkup-marketplace' ), $body );
	}

	/**
	 * Notify vendors of operations broadcast.
	 *
	 * @param int   $event_id   Event ID.
	 * @param array $vendor_ids Vendor user IDs.
	 * @param string $message   Message body.
	 */
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

	public static function send_ops_broadcast( $event_id, $vendor_ids, $message ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['notify_vendor'] ) ) {
			return;
		}
		$title = get_the_title( $event_id );
		$body  = '<p>' . esc_html( $message ) . '</p>';
		$body .= '<p><a href="' . esc_url( add_query_arg( 'view', 'operations', lnkup_page_url_helper( 'vendor-dashboard' ) ) ) . '">' . esc_html__( 'View in dashboard', 'lnkup-marketplace' ) . '</a></p>';
		foreach ( $vendor_ids as $vendor_id ) {
			$user = get_userdata( $vendor_id );
			if ( $user ) {
				self::send( $user->user_email, sprintf( __( 'Event update: %s', 'lnkup-marketplace' ), $title ), $body );
			}
		}
	}
}
