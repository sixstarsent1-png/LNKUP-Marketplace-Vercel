<?php
/**
 * Organizer Outreach Studio — lead campaigns on top of LNKup_Outreach.
 *
 * Campaigns (source "growth") send one reviewed template per lead. Before a recipient is
 * queued and again right before sending: suppression, do-not-contact, stale contact data,
 * already-registered accounts and a per-lead duplicate-outreach window are checked. Large
 * campaigns need a second admin's approval. Every email carries a one-click unsubscribe link,
 * List-Unsubscribe headers and the sender's postal address. Only states we can observe are
 * recorded: queued, accepted by wp_mail, failed, skipped (with reason), unsubscribed.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Outreach {

	const SOURCE        = 'growth';
	const UNSUB_VAR     = 'lnkup_org_unsub';
	const REC_SKIPPED   = 'skipped_rule';
	const REC_SUPPRESSED = 'suppressed';
	const REC_UNSUB     = 'unsubscribed';

	/** Outcomes that end outreach to a lead. */
	const CLOSED_OUTCOMES = array( 'do_not_contact', 'not_interested', 'unqualified', 'duplicate', 'archived' );

	const MERGE_FIELDS = array( 'company_name', 'city', 'event_name', 'event_category', 'application_process', 'lnkup_features', 'sender_name', 'signup_url', 'unsubscribe_url' );

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_handle_unsubscribe' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_seed_templates' ), 20 );
	}

	public static function templates_table(): string {
		return LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::TEMPLATES );
	}

	// ------------------------------------------------------------------ Templates.

	/**
	 * Seed templates. Bodies are plain text with {{merge_fields}}; each declares the facts it
	 * needs so a lead missing them falls back to a simpler template instead of invented copy.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function default_templates(): array {
		$sig = "\n\n— {{sender_name}}, LNKup\n";
		return array(
			'first_intro'           => array(
				'name'     => 'First organizer introduction',
				'subject'  => 'Vendor management for {{company_name}}',
				'required' => 'company_name',
				'fallback' => '',
				'body'     => "Hi {{company_name}} team,\n\nI'm reaching out from LNKup, a marketplace that connects event organizers with vetted vendors. Organizers use LNKup to publish vendor opportunities, take applications, collect booth payments and send vendor packets in one place.\n\nIf vendor applications are part of your events, I'd be glad to show you how it works. You can also set up your first opportunity yourself here: {{signup_url}}" . $sig,
			),
			'active_applications'   => array(
				'name'     => 'Organizer with active vendor applications',
				'subject'  => 'Vendor applications for {{event_name}}',
				'required' => 'company_name,event_name,application_process',
				'fallback' => 'first_intro',
				'body'     => "Hi {{company_name}} team,\n\nI saw that {{event_name}} is accepting vendors ({{application_process}}). LNKup can take those applications for you, collect booth fees and keep documents and packets organized — and vendors already on LNKup can find your event.\n\nYou can publish the opportunity in a few minutes: {{signup_url}}" . $sig,
			),
			'popup_market'          => array(
				'name'     => 'Recurring pop-up market partnership',
				'subject'  => 'Recurring vendors for {{company_name}} markets',
				'required' => 'company_name,city',
				'fallback' => 'first_intro',
				'body'     => "Hi {{company_name}} team,\n\nRecurring markets in {{city}} are a great fit for LNKup. You can reuse the same booth setup and application for every market date, see returning vendors, and collect booth fees online.\n\nHappy to walk you through it, or start here: {{signup_url}}" . $sig,
			),
			'festival_producer'     => array(
				'name'     => 'Festival producer partnership',
				'subject'  => 'Vendor operations for {{event_name}}',
				'required' => 'company_name,event_name',
				'fallback' => 'first_intro',
				'body'     => "Hi {{company_name}} team,\n\nFestivals like {{event_name}} juggle a lot of vendors. LNKup handles vendor applications, booth payments with payouts to your account, documents, booth assignments and day-of check-in.\n\nWould a short walkthrough be useful? You can also get started here: {{signup_url}}" . $sig,
			),
			'expo_convention'       => array(
				'name'     => 'Expo / convention organizer',
				'subject'  => 'Exhibitor applications for {{company_name}}',
				'required' => 'company_name,event_category',
				'fallback' => 'first_intro',
				'body'     => "Hi {{company_name}} team,\n\nFor {{event_category}} events, LNKup lets you offer different booth types and prices, review exhibitor applications, and collect payments without spreadsheets.\n\nStart your first listing here: {{signup_url}}" . $sig,
			),
			'needs_software'        => array(
				'name'     => 'Organizer needing vendor-management software',
				'subject'  => 'A simpler vendor application process',
				'required' => 'company_name,application_process',
				'fallback' => 'first_intro',
				'body'     => "Hi {{company_name}} team,\n\nRight now vendors apply through {{application_process}}. LNKup replaces that with one application link, automatic payment collection and a dashboard for every vendor.\n\nSet it up here: {{signup_url}}" . $sig,
			),
			'multi_city'            => array(
				'name'     => 'Multi-city event production company',
				'subject'  => 'One vendor system across your cities',
				'required' => 'company_name',
				'fallback' => 'first_intro',
				'body'     => "Hi {{company_name}} team,\n\nIf you run events in several cities, LNKup keeps every event's vendors, payments and packets under one organization, so you can duplicate a setup for the next stop instead of starting over.\n\nTake a look: {{signup_url}}" . $sig,
			),
			'demo_invite'           => array(
				'name'     => 'Demo invitation',
				'subject'  => '15 minutes to see LNKup?',
				'required' => 'company_name',
				'fallback' => '',
				'body'     => "Hi {{company_name}} team,\n\nWould you have 15 minutes for a quick walkthrough of LNKup's vendor tools? Reply with a time that works and I'll send an invite.\n\nIf you'd rather explore on your own: {{signup_url}}" . $sig,
			),
			'follow_up_unanswered'  => array(
				'name'     => 'Follow-up after an unanswered message',
				'subject'  => 'Following up — LNKup for {{company_name}}',
				'required' => 'company_name',
				'fallback' => '',
				'body'     => "Hi {{company_name}} team,\n\nJust following up on my earlier note about LNKup for vendor applications and booth payments. If now isn't the right time, no problem — you can use the link below to unsubscribe and I won't follow up again.\n\n{{signup_url}}" . $sig,
			),
			'follow_up_demo'        => array(
				'name'     => 'Follow-up after a demo',
				'subject'  => 'Thanks for your time — next steps',
				'required' => 'company_name',
				'fallback' => '',
				'body'     => "Hi {{company_name}} team,\n\nThanks for taking the time to look at LNKup. When you're ready, create your organizer account and publish your first vendor opportunity here: {{signup_url}}\n\nReply with any questions." . $sig,
			),
			'welcome_registered'    => array(
				'name'     => 'Welcome after registration',
				'subject'  => 'Welcome to LNKup, {{company_name}}',
				'required' => 'company_name',
				'fallback' => '',
				'body'     => "Hi {{company_name}} team,\n\nWelcome to LNKup! Your next step is to finish your organizer profile and payout setup, then publish your first vendor opportunity.\n\nReply to this email if you'd like help." . $sig,
			),
			'publish_first_event'   => array(
				'name'     => 'Publish-your-first-event encouragement',
				'subject'  => 'Ready to publish your first vendor opportunity?',
				'required' => 'company_name',
				'fallback' => '',
				'body'     => "Hi {{company_name}} team,\n\nYour LNKup account is ready. Publishing an opportunity takes a few minutes: add the event, the booth type and price, and share the application link with vendors.\n\nReply if you'd like us to help set it up." . $sig,
			),
		);
	}

	public static function maybe_seed_templates(): void {
		global $wpdb;
		if ( get_option( 'lnkup_org_outreach_templates_seeded' ) === LNKup_Org_Growth_Tables::SCHEMA_VERSION || ! LNKup_Org_Growth_Tables::exists( LNKup_Org_Growth_Tables::TEMPLATES ) ) {
			return;
		}
		$now = LNKup_Org_Growth::now();
		foreach ( self::default_templates() as $key => $tpl ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO ' . self::templates_table() . ' (template_key, name, subject, body, required_fields, fallback_key, is_active, is_seeded, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s, 1, 1, %s, %s)',
					$key,
					$tpl['name'],
					$tpl['subject'],
					$tpl['body'],
					$tpl['required'],
					$tpl['fallback'],
					$now,
					$now
				)
			);
		}
		update_option( 'lnkup_org_outreach_templates_seeded', LNKup_Org_Growth_Tables::SCHEMA_VERSION, false );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_templates(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::templates_table() . ' ORDER BY id ASC', ARRAY_A );
		return array_map( array( __CLASS__, 'format_template' ), (array) $rows );
	}

	/**
	 * @param array<string, mixed> $row Template row.
	 * @return array<string, mixed>
	 */
	public static function format_template( array $row ): array {
		return array(
			'key'             => (string) $row['template_key'],
			'name'            => (string) $row['name'],
			'subject'         => (string) $row['subject'],
			'body'            => (string) $row['body'],
			'required_fields' => array_values( array_filter( explode( ',', (string) $row['required_fields'] ) ) ),
			'fallback_key'    => (string) $row['fallback_key'],
			'is_active'       => (bool) (int) $row['is_active'],
			'updated_at'      => (string) $row['updated_at'],
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get_template( string $key ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::templates_table() . ' WHERE template_key = %s', sanitize_key( $key ) ), ARRAY_A );
		return $row ? self::format_template( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $input name, subject, body, required_fields, fallback_key, is_active.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save_template( string $key, array $input, int $actor ) {
		global $wpdb;
		$key = sanitize_key( $key );
		if ( '' === $key ) {
			return new WP_Error( 'lnkup_template_key', __( 'Template key is required.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$existing = self::get_template( $key );
		$subject  = sanitize_text_field( (string) ( $input['subject'] ?? ( $existing['subject'] ?? '' ) ) );
		$body     = sanitize_textarea_field( (string) ( $input['body'] ?? ( $existing['body'] ?? '' ) ) );
		if ( '' === $subject || '' === $body ) {
			return new WP_Error( 'lnkup_template_incomplete', __( 'A template needs a subject and a message.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$required = $input['required_fields'] ?? ( $existing['required_fields'] ?? array( 'company_name' ) );
		$required = is_array( $required ) ? $required : explode( ',', (string) $required );
		$required = array_values( array_intersect( array_map( 'sanitize_key', $required ), self::MERGE_FIELDS ) );
		// Every field the body uses must be declared, so missing facts can never render as blanks.
		preg_match_all( '/\{\{([a-z_]+)\}\}/', $subject . ' ' . $body, $m );
		foreach ( array_unique( $m[1] ) as $field ) {
			if ( ! in_array( $field, self::MERGE_FIELDS, true ) ) {
				/* translators: %s: merge field */
				return new WP_Error( 'lnkup_template_field', sprintf( __( 'Unknown merge field {{%s}}.', 'lnkup-marketplace' ), $field ), array( 'status' => 400 ) );
			}
			if ( ! in_array( $field, array( 'sender_name', 'signup_url', 'unsubscribe_url', 'lnkup_features' ), true ) && ! in_array( $field, $required, true ) ) {
				$required[] = $field;
			}
		}
		$fallback = sanitize_key( (string) ( $input['fallback_key'] ?? ( $existing['fallback_key'] ?? '' ) ) );
		if ( $fallback === $key ) {
			$fallback = '';
		}
		$row = array(
			'name'            => substr( sanitize_text_field( (string) ( $input['name'] ?? ( $existing['name'] ?? $key ) ) ), 0, 191 ),
			'subject'         => substr( $subject, 0, 191 ),
			'body'            => $body,
			'required_fields' => implode( ',', $required ),
			'fallback_key'    => $fallback,
			'is_active'       => array_key_exists( 'is_active', $input ) ? (int) rest_sanitize_boolean( $input['is_active'] ) : (int) ( $existing['is_active'] ?? 1 ),
			'updated_by'      => $actor,
			'updated_at'      => LNKup_Org_Growth::now(),
		);
		if ( $existing ) {
			$wpdb->update( self::templates_table(), $row, array( 'template_key' => $key ) );
		} else {
			$wpdb->insert( self::templates_table(), $row + array( 'template_key' => $key, 'created_at' => LNKup_Org_Growth::now() ) );
		}
		return (array) self::get_template( $key );
	}

	/**
	 * Facts available for a lead. Only recorded values — never inferred.
	 *
	 * @param array<string, mixed> $lead Lead row.
	 * @return array<string, string>
	 */
	public static function merge_values( array $lead, array $extra = array() ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$event = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::LEAD_EVENTS ) . ' WHERE lead_id = %d AND verified_at IS NOT NULL ORDER BY event_date IS NULL, event_date ASC, id ASC LIMIT 1', (int) $lead['id'] ) );
		$cats  = LNKup_Org_Lead_Scoring::split_list( (string) ( $lead['event_categories'] ?? '' ) );
		$settings = LNKup_Org_Growth::settings();
		$sender   = trim( (string) ( $settings['org_growth_sender_name'] ?? '' ) );
		if ( '' === $sender && ! empty( $extra['sender_user_id'] ) ) {
			$u      = get_userdata( (int) $extra['sender_user_id'] );
			$sender = $u ? (string) $u->display_name : '';
		}
		return array(
			'company_name'        => trim( (string) $lead['name'] ),
			'city'                => trim( (string) $lead['city'] ),
			'event_name'          => trim( $event ),
			'event_category'      => (string) ( $cats[0] ?? '' ),
			'application_process' => trim( (string) $lead['application_process'] ),
			'lnkup_features'      => __( 'vendor applications, booth payments, documents and vendor packets', 'lnkup-marketplace' ),
			'sender_name'         => '' !== $sender ? $sender : __( 'The LNKup team', 'lnkup-marketplace' ),
			'signup_url'          => (string) ( $extra['signup_url'] ?? '' ),
			'unsubscribe_url'     => (string) ( $extra['unsubscribe_url'] ?? '' ),
		);
	}

	/**
	 * Resolve the template for a lead, following fallbacks when required facts are missing.
	 *
	 * @param array<string, string> $values Merge values.
	 * @return array{template:array<string,mixed>,missing:string[]}|WP_Error
	 */
	public static function resolve_template( string $key, array $values ) {
		$seen    = array();
		$missing = array();
		while ( '' !== $key && ! isset( $seen[ $key ] ) ) {
			$seen[ $key ] = true;
			$tpl          = self::get_template( $key );
			if ( ! $tpl || ! $tpl['is_active'] ) {
				break;
			}
			$gaps = array_values( array_filter( $tpl['required_fields'], static fn( $f ) => '' === trim( (string) ( $values[ $f ] ?? '' ) ) ) );
			if ( ! $gaps ) {
				return array(
					'template' => $tpl,
					'missing'  => $missing,
				);
			}
			$missing = array_merge( $missing, $gaps );
			$key     = (string) $tpl['fallback_key'];
		}
		return new WP_Error( 'lnkup_template_missing_facts', sprintf( /* translators: %s: fields */ __( 'Missing verified details: %s', 'lnkup-marketplace' ), implode( ', ', array_unique( $missing ) ) ), array( 'status' => 422, 'missing' => array_values( array_unique( $missing ) ) ) );
	}

	/**
	 * Render subject + HTML for a lead.
	 *
	 * @param array<string, mixed> $lead  Lead row.
	 * @param array<string, mixed> $extra signup_url, unsubscribe_url, sender_user_id.
	 * @return array{subject:string,html:string,text:string,template_key:string,fallback_used:bool}|WP_Error
	 */
	public static function render( string $template_key, array $lead, array $extra = array() ) {
		$values   = self::merge_values( $lead, $extra );
		$resolved = self::resolve_template( $template_key, $values );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$tpl     = $resolved['template'];
		$replace = static function ( string $text ) use ( $values ): string {
			return (string) preg_replace_callback( '/\{\{([a-z_]+)\}\}/', static fn( $m ) => (string) ( $values[ $m[1] ] ?? '' ), $text );
		};
		$subject = str_replace( array( "\r", "\n" ), ' ', $replace( (string) $tpl['subject'] ) );
		$text    = $replace( (string) $tpl['body'] );
		$html    = '';
		foreach ( preg_split( "/\n{2,}/", $text ) ?: array() as $para ) {
			$para = trim( $para );
			if ( '' === $para ) {
				continue;
			}
			$escaped = esc_html( $para );
			if ( '' !== $values['signup_url'] ) {
				$escaped = str_replace( esc_html( $values['signup_url'] ), '<a href="' . esc_url( $values['signup_url'] ) . '">' . esc_html__( 'Get started on LNKup', 'lnkup-marketplace' ) . '</a>', $escaped );
			}
			$html .= '<p style="margin:0 0 16px;">' . nl2br( $escaped ) . '</p>';
		}
		$html .= self::footer_html( $values['unsubscribe_url'] );
		return array(
			'subject'       => $subject,
			'html'          => $html,
			'text'          => $text,
			'template_key'  => (string) $tpl['key'],
			'fallback_used' => $tpl['key'] !== $template_key,
		);
	}

	private static function footer_html( string $unsubscribe_url ): string {
		$address = trim( (string) LNKup_Org_Growth::setting( 'org_growth_sender_postal_address' ) );
		$out     = '<p style="margin:24px 0 0;font-size:12px;color:#6b6c72;">';
		$out    .= esc_html__( 'You are receiving this because your organization publicly lists event or vendor contact information.', 'lnkup-marketplace' );
		if ( '' !== $unsubscribe_url ) {
			$out .= ' <a href="' . esc_url( $unsubscribe_url ) . '" style="color:#6b6c72;">' . esc_html__( 'Unsubscribe', 'lnkup-marketplace' ) . '</a>';
		}
		if ( '' !== $address ) {
			$out .= '<br>' . nl2br( esc_html( $address ) );
		}
		return $out . '</p>';
	}

	// ------------------------------------------------------------------ Eligibility.

	/**
	 * Why a lead cannot receive outreach right now ('' = eligible).
	 *
	 * @param array<string, mixed> $lead Lead row.
	 */
	public static function ineligible_reason( array $lead, int $ignore_campaign = 0 ): string {
		global $wpdb;
		if ( (int) $lead['merged_into_id'] ) {
			return 'merged';
		}
		if ( in_array( (string) $lead['outcome'], self::CLOSED_OUTCOMES, true ) ) {
			return 'outcome_' . (string) $lead['outcome'];
		}
		$email = (string) $lead['email'];
		if ( '' === $email ) {
			return 'no_email';
		}
		$suppressed = LNKup_Contact_Suppression::check_email( $email );
		if ( '' !== $suppressed ) {
			return 'suppressed_' . $suppressed;
		}
		$stale_days = (int) LNKup_Org_Growth::setting( 'org_growth_contact_stale_days' );
		$verified   = (string) ( $lead['source_verified_at'] ?? '' );
		if ( '' === $verified || ( $stale_days > 0 && strtotime( $verified ) < time() - $stale_days * DAY_IN_SECONDS ) ) {
			return 'contact_not_verified';
		}
		if ( LNKup_Org_Leads::linked_user_ids( (int) $lead['id'] ) || get_user_by( 'email', $email ) ) {
			return 'already_registered';
		}
		$window = (int) LNKup_Org_Growth::setting( 'org_growth_outreach_dedupe_days' );
		if ( $window > 0 && ! empty( $lead['last_contacted_at'] ) && strtotime( (string) $lead['last_contacted_at'] ) > (int) current_time( 'timestamp' ) - $window * DAY_IN_SECONDS ) {
			return 'contacted_recently';
		}
		$rec = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		$cam = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::CAMPAIGNS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$queued = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rec} r INNER JOIN {$cam} c ON c.id = r.campaign_id WHERE r.lead_id = %d AND r.status = 'pending' AND c.id <> %d AND c.status IN ('draft','queued','processing')", (int) $lead['id'], $ignore_campaign ) );
		if ( $queued ) {
			return 'queued_elsewhere';
		}
		return '';
	}

	// ------------------------------------------------------------------ Campaigns.

	/**
	 * Create a lead campaign from a filter or explicit lead IDs. Ineligible leads are recorded
	 * as skipped with their reason so the review screen shows exactly who will not be emailed.
	 *
	 * @param array<string, mixed> $args name, template_key, filters{}, lead_ids[], daily_cap.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_campaign( array $args, int $actor ) {
		global $wpdb;
		if ( ! LNKup_Org_Growth::outreach_enabled() ) {
			return new WP_Error( 'lnkup_outreach_disabled', __( 'Organizer outreach is turned off in Organizer Growth settings.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$template = self::get_template( (string) ( $args['template_key'] ?? '' ) );
		if ( ! $template || ! $template['is_active'] ) {
			return new WP_Error( 'lnkup_template_not_found', __( 'Choose an active email template.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$filters = is_array( $args['filters'] ?? null ) ? $args['filters'] : array();
		if ( ! empty( $args['lead_ids'] ) ) {
			$filters = array( 'ids' => array_map( 'absint', (array) $args['lead_ids'] ), 'outcome' => 'any' );
		}
		if ( ! $filters ) {
			return new WP_Error( 'lnkup_campaign_audience', __( 'Choose which leads to contact.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$campaign_id = LNKup_Outreach::create_campaign(
			array(
				'name'        => (string) ( $args['name'] ?? $template['name'] ),
				'target_role' => 'lnkup_organizer',
				'message'     => '',
				'source'      => self::SOURCE,
				'created_by'  => $actor,
			)
		);
		if ( is_wp_error( $campaign_id ) ) {
			return $campaign_id;
		}
		$cap = max( 0, (int) ( $args['daily_cap'] ?? LNKup_Org_Growth::setting( 'org_growth_campaign_daily_cap' ) ) );
		LNKup_Outreach::update_campaign( (int) $campaign_id, array( 'template_key' => $template['key'], 'daily_cap' => $cap ) );

		$rec     = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		$now     = LNKup_Org_Growth::now();
		$skipped = array();
		$added   = 0;
		$page    = 1;
		do {
			$res = LNKup_Org_Leads::query( array_merge( $filters, array( 'per_page' => 200, 'page' => $page ) ) );
			foreach ( $res['items'] as $item ) {
				$lead   = LNKup_Org_Leads::get_row( (int) $item['id'] );
				$reason = $lead ? self::ineligible_reason( $lead, (int) $campaign_id ) : 'not_found';
				if ( '' === $reason ) {
					$render = self::render( $template['key'], $lead, array( 'signup_url' => 'x', 'unsubscribe_url' => 'x' ) );
					if ( is_wp_error( $render ) ) {
						$reason = 'missing_facts';
					}
				}
				if ( '' !== $reason ) {
					$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + 1;
				}
				$wpdb->insert(
					$rec,
					array(
						'campaign_id'  => (int) $campaign_id,
						'lead_id'      => (int) $item['id'],
						'template_key' => $template['key'],
						'email'        => (string) $item['email'],
						'name'         => substr( (string) $item['name'], 0, 191 ),
						'token'        => wp_generate_password( 32, false, false ),
						'status'       => '' === $reason ? LNKup_Outreach::REC_PENDING : ( 0 === strpos( $reason, 'suppressed' ) ? self::REC_SUPPRESSED : self::REC_SKIPPED ),
						'skip_reason'  => $reason,
						'created_at'   => $now,
					)
				);
				if ( '' === $reason ) {
					++$added;
				}
			}
			++$page;
		} while ( $page <= $res['pages'] && $page <= 25 );

		$threshold = max( 1, (int) LNKup_Org_Growth::setting( 'org_growth_approval_threshold' ) );
		LNKup_Outreach::update_campaign( (int) $campaign_id, array( 'approval_status' => $added > $threshold ? 'pending' : 'not_required' ) );
		LNKup_Outreach::refresh_campaign_totals( (int) $campaign_id );
		return array(
			'campaign' => self::format_campaign( (array) LNKup_Outreach::get_campaign( (int) $campaign_id ) ),
			'eligible' => $added,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Second-admin approval for campaigns above the threshold.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function approve( int $campaign_id, int $actor ) {
		$campaign = LNKup_Outreach::get_campaign( $campaign_id );
		if ( ! $campaign || self::SOURCE !== $campaign['source'] ) {
			return new WP_Error( 'lnkup_campaign_not_found', __( 'Campaign not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( 'pending' !== (string) $campaign['approval_status'] ) {
			return new WP_Error( 'lnkup_campaign_no_approval', __( 'This campaign does not need approval.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		if ( (int) $campaign['created_by'] === $actor ) {
			return new WP_Error( 'lnkup_campaign_self_approve', __( 'Another admin must approve a campaign this large.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		LNKup_Outreach::update_campaign( $campaign_id, array( 'approval_status' => 'approved', 'approved_by' => $actor ) );
		LNKup_Financial_Audit::log( 'org_outreach_campaign_approved', 'outreach_campaign', $campaign_id, array( 'actor' => $actor ) );
		return self::format_campaign( (array) LNKup_Outreach::get_campaign( $campaign_id ) );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function can_queue( array $campaign ) {
		if ( ! LNKup_Org_Growth::outreach_enabled() ) {
			return new WP_Error( 'lnkup_outreach_disabled', __( 'Organizer outreach is turned off in Organizer Growth settings.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		if ( 'pending' === (string) ( $campaign['approval_status'] ?? '' ) ) {
			return new WP_Error( 'lnkup_campaign_needs_approval', __( 'Another admin needs to approve this campaign before it can send.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		if ( '' === trim( (string) LNKup_Org_Growth::setting( 'org_growth_sender_postal_address' ) ) ) {
			return new WP_Error( 'lnkup_outreach_address', __( 'Add the sender postal address in Organizer Growth settings. Commercial email must include it.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/**
	 * Whether the campaign hit its per-day cap.
	 */
	public static function cap_reached( array $campaign ): bool {
		global $wpdb;
		$cap = (int) ( $campaign['daily_cap'] ?? 0 );
		if ( $cap <= 0 ) {
			return false;
		}
		$rec   = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		$today = substr( LNKup_Org_Growth::now(), 0, 10 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rec} WHERE campaign_id = %d AND status IN ('sent','opened','registered') AND sent_at >= %s", (int) $campaign['id'], $today . ' 00:00:00' ) );
		return $sent >= $cap;
	}

	/**
	 * Send one recipient of a growth campaign (called from LNKup_Outreach::process_recipient).
	 *
	 * @param array<string, mixed> $row      Recipient row.
	 * @param array<string, mixed> $campaign Campaign row.
	 * @return bool True when wp_mail accepted the message.
	 */
	public static function send_recipient( array $row, array $campaign ): bool {
		global $wpdb;
		$rec  = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		$lead = LNKup_Org_Leads::get_row( (int) $row['lead_id'] );
		$skip = static function ( string $reason ) use ( $wpdb, $rec, $row ): bool {
			$wpdb->update(
				$rec,
				array(
					'status'      => 0 === strpos( $reason, 'suppressed' ) ? self::REC_SUPPRESSED : self::REC_SKIPPED,
					'skip_reason' => $reason,
				),
				array( 'id' => (int) $row['id'] )
			);
			return false;
		};
		if ( ! $lead ) {
			return $skip( 'not_found' );
		}
		$reason = self::ineligible_reason( $lead, (int) $campaign['id'] );
		if ( '' !== $reason ) {
			return $skip( $reason );
		}
		$signup = LNKup_Outreach::build_signup_url( (string) $row['token'], 'lnkup_organizer' );
		$unsub  = self::unsubscribe_url( (int) $row['id'] );
		$render = self::render( (string) ( $row['template_key'] ?: $campaign['template_key'] ), $lead, array( 'signup_url' => $signup, 'unsubscribe_url' => $unsub, 'sender_user_id' => (int) $campaign['created_by'] ) );
		if ( is_wp_error( $render ) ) {
			return $skip( 'missing_facts' );
		}
		$headers = array(
			'List-Unsubscribe: <' . esc_url_raw( $unsub ) . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		);
		$sender = get_userdata( (int) $campaign['created_by'] );
		if ( $sender && is_email( $sender->user_email ) ) {
			$headers[] = 'Reply-To: ' . str_replace( array( "\r", "\n", '<', '>' ), '', (string) $sender->display_name ) . ' <' . $sender->user_email . '>';
		}
		$sent = (bool) LNKup_Notifications::send( (string) $lead['email'], $render['subject'], $render['html'], $headers );
		$now  = LNKup_Org_Growth::now();
		if ( ! $sent ) {
			$wpdb->update( $rec, array( 'status' => LNKup_Outreach::REC_FAILED, 'error_message' => __( 'The mail server did not accept the message.', 'lnkup-marketplace' ) ), array( 'id' => (int) $row['id'] ) );
			LNKup_Org_Leads::log_activity( (int) $lead['id'], 'email_failed', array( 'channel' => 'email', 'summary' => $render['subject'], 'payload' => array( 'campaign_id' => (int) $campaign['id'] ) ) );
			return false;
		}
		$wpdb->update( $rec, array( 'status' => LNKup_Outreach::REC_SENT, 'sent_at' => $now, 'template_key' => $render['template_key'] ), array( 'id' => (int) $row['id'] ) );
		LNKup_Org_Leads::log_activity(
			(int) $lead['id'],
			'contact',
			array(
				'actor'   => (int) $campaign['created_by'],
				'channel' => 'email',
				'outcome' => 'sent',
				'summary' => $render['subject'],
				'payload' => array(
					'campaign_id'   => (int) $campaign['id'],
					'template_key'  => $render['template_key'],
					'fallback_used' => $render['fallback_used'],
					'delivery'      => 'accepted_by_mail_server',
				),
			)
		);
		$wpdb->update( LNKup_Org_Leads::table(), array( 'last_contacted_at' => $now, 'updated_at' => $now ), array( 'id' => (int) $lead['id'] ) );
		if ( LNKup_Org_Lead_Pipeline::rank( 'contacted' ) > LNKup_Org_Lead_Pipeline::rank( (string) $lead['stage'] ) ) {
			LNKup_Org_Leads::advance_to( (int) $lead['id'], 'contacted', 'email_sent' );
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $row Campaign row.
	 * @return array<string, mixed>
	 */
	public static function format_campaign( array $row ): array {
		if ( ! $row ) {
			return array();
		}
		global $wpdb;
		$rec = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$rec} WHERE campaign_id = %d GROUP BY status", (int) $row['id'] ), ARRAY_A );
		$by     = array();
		foreach ( (array) $counts as $c ) {
			$by[ (string) $c['status'] ] = (int) $c['n'];
		}
		$creator = get_userdata( (int) $row['created_by'] );
		return array(
			'id'              => (int) $row['id'],
			'name'            => (string) $row['name'],
			'template_key'    => (string) ( $row['template_key'] ?? '' ),
			'status'          => (string) $row['status'],
			'approval_status' => (string) ( $row['approval_status'] ?? '' ),
			'daily_cap'       => (int) ( $row['daily_cap'] ?? 0 ),
			'created_by'      => (int) $row['created_by'],
			'created_by_name' => $creator ? (string) $creator->display_name : '',
			'counts'          => array(
				'pending'      => $by['pending'] ?? 0,
				'sent'         => ( $by['sent'] ?? 0 ) + ( $by['opened'] ?? 0 ) + ( $by['registered'] ?? 0 ),
				'registered'   => $by['registered'] ?? 0,
				'failed'       => $by['failed'] ?? 0,
				'skipped'      => $by[ self::REC_SKIPPED ] ?? 0,
				'suppressed'   => $by[ self::REC_SUPPRESSED ] ?? 0,
				'unsubscribed' => $by[ self::REC_UNSUB ] ?? 0,
			),
			'created_at'      => (string) $row['created_at'],
			'updated_at'      => (string) $row['updated_at'],
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_campaigns(): array {
		return array_map( array( __CLASS__, 'format_campaign' ), LNKup_Outreach::list_campaigns( array( 'source' => self::SOURCE, 'limit' => 100 ) ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function campaign_recipients( int $campaign_id ): array {
		global $wpdb;
		$rec = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, lead_id, name, email, status, skip_reason, template_key, sent_at, registered_at, error_message FROM {$rec} WHERE campaign_id = %d ORDER BY FIELD(status,'pending') DESC, id ASC LIMIT 1000", $campaign_id ), ARRAY_A );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function history_for_lead( int $lead_id ): array {
		global $wpdb;
		if ( ! class_exists( 'LNKup_Outreach_Tables' ) ) {
			return array();
		}
		$rec = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		$cam = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::CAMPAIGNS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT r.campaign_id, c.name AS campaign, r.status, r.skip_reason, r.template_key, r.sent_at, r.registered_at FROM {$rec} r INNER JOIN {$cam} c ON c.id = r.campaign_id WHERE r.lead_id = %d ORDER BY r.id DESC LIMIT 50", $lead_id ), ARRAY_A );
	}

	// ------------------------------------------------------------------ Unsubscribe.

	private static function sign( string $payload ): string {
		return substr( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . 'lnkup_org_unsub' ), 0, 32 );
	}

	public static function unsubscribe_token( int $recipient_id ): string {
		$payload = 'r' . $recipient_id;
		return $payload . '.' . self::sign( $payload );
	}

	public static function parse_unsubscribe_token( string $token ): int {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || ! preg_match( '/^r(\d+)$/', $parts[0], $m ) || ! hash_equals( self::sign( $parts[0] ), $parts[1] ) ) {
			return 0;
		}
		return (int) $m[1];
	}

	public static function unsubscribe_url( int $recipient_id ): string {
		return add_query_arg( self::UNSUB_VAR, self::unsubscribe_token( $recipient_id ), home_url( '/' ) );
	}

	/**
	 * Apply an unsubscribe for a recipient token. Idempotent.
	 *
	 * @return array{ok:bool,email_hint:string}
	 */
	public static function apply_unsubscribe( string $token ): array {
		global $wpdb;
		$recipient_id = self::parse_unsubscribe_token( $token );
		if ( ! $recipient_id ) {
			return array( 'ok' => false, 'email_hint' => '' );
		}
		$rec = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, lead_id, email, status FROM {$rec} WHERE id = %d", $recipient_id ), ARRAY_A );
		if ( ! $row ) {
			return array( 'ok' => false, 'email_hint' => '' );
		}
		$email = (string) $row['email'];
		LNKup_Contact_Suppression::add_email( $email, 'unsubscribe', 'email_link', (int) $row['lead_id'] );
		if ( self::REC_UNSUB !== $row['status'] ) {
			$wpdb->update( $rec, array( 'status' => self::REC_UNSUB ), array( 'id' => $recipient_id ) );
			if ( (int) $row['lead_id'] ) {
				LNKup_Org_Leads::log_activity( (int) $row['lead_id'], 'unsubscribed', array( 'channel' => 'email', 'summary' => __( 'Unsubscribed from organizer outreach', 'lnkup-marketplace' ) ) );
				// Pending sends to the same address in other campaigns stop too.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "UPDATE {$rec} SET status = %s, skip_reason = 'suppressed_unsubscribe' WHERE email = %s AND status = 'pending'", self::REC_SUPPRESSED, $email ) );
				LNKup_Org_Growth_Metrics::flush_cache();
			}
		}
		$at = strpos( $email, '@' );
		return array(
			'ok'         => true,
			'email_hint' => false !== $at ? substr( $email, 0, 1 ) . '•••' . substr( $email, (int) $at ) : '',
		);
	}

	private static function rate_limited(): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'lnkup_org_unsub_rl_' . substr( hash( 'sha256', $ip . wp_salt( 'nonce' ) ), 0, 20 );
		$hits = (int) get_transient( $key );
		if ( $hits >= 30 ) {
			return true;
		}
		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );
		return false;
	}

	public static function maybe_handle_unsubscribe(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- HMAC-signed token; nonces cannot work from email clients.
		if ( empty( $_GET[ self::UNSUB_VAR ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token  = sanitize_text_field( wp_unslash( (string) $_GET[ self::UNSUB_VAR ] ) );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( self::rate_limited() ) {
			self::render_page( __( 'Too many requests. Please try again later.', 'lnkup-marketplace' ), '', 429 );
		}
		if ( ! self::parse_unsubscribe_token( $token ) ) {
			self::render_page( __( 'This unsubscribe link is not valid.', 'lnkup-marketplace' ), '', 400 );
		}
		// RFC 8058 one-click: mail clients POST with no user interaction.
		if ( 'POST' === $method ) {
			self::apply_unsubscribe( $token );
			status_header( 200 );
			exit;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['confirm'] ) ) {
			$result = self::apply_unsubscribe( $token );
			self::render_page( $result['ok'] ? __( 'You are unsubscribed. LNKup will not email you about organizer partnerships again.', 'lnkup-marketplace' ) : __( 'This unsubscribe link is not valid.', 'lnkup-marketplace' ), '' );
		}
		self::render_page( __( 'Stop receiving LNKup organizer partnership emails?', 'lnkup-marketplace' ), add_query_arg( array( self::UNSUB_VAR => $token, 'confirm' => 1 ), home_url( '/' ) ) );
	}

	private static function render_page( string $message, string $confirm_url, int $status = 200 ): void {
		nocache_headers();
		status_header( $status );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html__( 'Email preferences — LNKup', 'lnkup-marketplace' ) . '</title></head>';
		echo '<body style="margin:0;background:#f4f4f2;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1a1b1f;"><main style="max-width:480px;margin:64px auto;padding:32px;background:#fff;border-radius:16px;text-align:center;">';
		echo '<p style="font-size:20px;font-weight:800;margin:0 0 16px;">LNK<span style="color:#8bb81f;">up</span></p>';
		echo '<h1 style="font-size:20px;margin:0 0 20px;">' . esc_html( $message ) . '</h1>';
		if ( '' !== $confirm_url ) {
			echo '<form method="get" action="' . esc_url( home_url( '/' ) ) . '">';
			$query = wp_parse_url( $confirm_url, PHP_URL_QUERY );
			parse_str( (string) $query, $params );
			foreach ( $params as $k => $v ) {
				echo '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '">';
			}
			echo '<button type="submit" style="padding:12px 24px;background:#c8f542;color:#0b0b0c;border:0;border-radius:10px;font-weight:700;font-size:15px;cursor:pointer;">' . esc_html__( 'Unsubscribe', 'lnkup-marketplace' ) . '</button></form>';
		}
		echo '</main></body></html>';
		exit;
	}
}
