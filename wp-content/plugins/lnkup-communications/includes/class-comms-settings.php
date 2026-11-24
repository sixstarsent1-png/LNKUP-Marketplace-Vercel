<?php
/**
 * Communications settings and feature flags.
 *
 * Every flow is OFF by default. While a flow is off, lnkup-marketplace keeps
 * sending its own messages for it (the delegation filter does not claim them).
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Settings {

	const OPTION = 'lnkup_comms_settings';

	/**
	 * Feature flags with admin labels. Order is the recommended activation order.
	 */
	public static function flags(): array {
		return array(
			'flow_applications'        => __( 'Application lifecycle (submitted, approved, declined, waitlisted, info requests, withdrawn)', 'lnkup-communications' ),
			'flow_payments'            => __( 'Payments & bookings (payment due, reminders, failed, confirmed, refunds, disputes)', 'lnkup-communications' ),
			'flow_documents'           => __( 'Documents & compliance', 'lnkup-communications' ),
			'flow_operations'          => __( 'Event operations (logistics changes, pass/booth updates, check-in, cancellations, ops broadcasts)', 'lnkup-communications' ),
			'flow_onboarding'          => __( 'Onboarding sequences for new vendors and organizers', 'lnkup-communications' ),
			'flow_countdown'           => __( 'Event countdown reminders (vendor and organizer)', 'lnkup-communications' ),
			'flow_digests'             => __( 'Daily / weekly digests and organizer operations summaries', 'lnkup-communications' ),
			'flow_post_event'          => __( 'Post-event follow-ups', 'lnkup-communications' ),
			'flow_discovery'           => __( 'Vendor opportunity matching (promotional)', 'lnkup-communications' ),
			'flow_discovery_tours'     => __( 'Tour matching: one grouped alert per tour with the vendor\'s matching stops (promotional)', 'lnkup-communications' ),
			'flow_discovery_weekly'    => __( 'Weekly top matches for vendors who received no opportunity messages that week (promotional)', 'lnkup-communications' ),
			'flow_recruitment'         => __( 'Organizer vendor recruitment recommendations', 'lnkup-communications' ),
			'flow_recruitment_updates' => __( 'Organizer open-spaces reminders and new matching vendor counts', 'lnkup-communications' ),
			'flow_retention'           => __( 'Organizer retention and reactivation', 'lnkup-communications' ),
			'flow_activation'          => __( 'Organizer activation follow-ups (profile, payouts, unpublished drafts, first-booking celebration)', 'lnkup-communications' ),
			'flow_passport'            => __( 'Vendor Passport: verification decisions, review notices, responses, moderation outcomes, shared documents and tour invites', 'lnkup-communications' ),
			'flow_partners'            => __( 'Partner programs: application decisions, referral progress, commissions and payout records', 'lnkup-communications' ),
			'organizer_broadcasts'     => __( 'Organizer event communications (announcements to their own vendors)', 'lnkup-communications' ),
			'campaigns_enabled'        => __( 'Admin push/email campaigns', 'lnkup-communications' ),
			'fin_payout_notifications' => __( 'Payout and payout-setup notifications (enable only after financial reconciliation sign-off)', 'lnkup-communications' ),
		);
	}

	public static function defaults(): array {
		$defaults = array_fill_keys( array_keys( self::flags() ), false );
		return array_merge(
			$defaults,
			array(
				'delivery_paused'                  => false,
				'email_channel'                    => true,
				'push_channel'                     => true,
				'android_channels'                 => false,
				'cap_promo_push_daily'             => 1,
				'cap_promo_push_weekly'            => 3,
				'cap_promo_email_weekly'           => 2,
				'cap_category_push_weekly'         => 0,
				'cap_category_email_weekly'        => 0,
				'cooldown_hours'                   => 4,
				'holdout_percent'                  => 0,
				'holdout_triggers'                 => array(),
				'burst_window_minutes'             => 30,
				'pending_review_hours'             => 48,
				'digest_hour'                      => 9,
				'campaign_confirm_threshold'       => 500,
				'campaign_batch_size'              => 100,
				'organizer_broadcast_daily_limit'  => 5,
				'organizer_broadcast_min_interval' => 10,
				'retention_days'                   => 180,
				'attribution_days'                 => 7,
				'activation_cooldown_days'         => 3,
				'draft_unpublished_days'           => 3,
				'no_applications_days'             => 5,
				'test_user_ids'                    => '',
				'email_provider'                   => '',
				'email_webhook_secret'             => '',
				'company_address'                  => '',
				'paused_automations'               => array(),
			)
		);
	}

	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function enabled( string $flag ): bool {
		return '' !== $flag && ! empty( self::get( $flag ) );
	}

	/**
	 * Flow on AND the recipient is inside its staged rollout audience (lnkup-marketplace LNKup_Rollout).
	 * Flows without a staged audience behave like enabled().
	 *
	 * @param string $flag      Flow flag.
	 * @param int    $recipient Recipient user ID.
	 */
	public static function enabled_for( string $flag, int $recipient ): bool {
		if ( ! self::enabled( $flag ) ) {
			return false;
		}
		return ! class_exists( 'LNKup_Rollout' ) || LNKup_Rollout::in_audience( $flag, $recipient );
	}

	public static function int( string $key ): int {
		return (int) self::get( $key );
	}

	/**
	 * Sanitize and persist a partial settings array.
	 *
	 * @param array $input Raw input.
	 */
	public static function update( array $input ): array {
		$current  = self::all();
		$defaults = self::defaults();
		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = $input[ $key ];
			if ( is_bool( $default ) ) {
				$current[ $key ] = ! empty( $value ) && 'false' !== $value && '0' !== $value;
			} elseif ( is_int( $default ) ) {
				$current[ $key ] = max( 0, (int) $value );
			} elseif ( is_array( $default ) ) {
				$current[ $key ] = array_values( array_filter( array_map( 'sanitize_key', (array) $value ) ) );
			} elseif ( 'email_webhook_secret' === $key ) {
				$current[ $key ] = sanitize_text_field( (string) $value );
			} elseif ( 'email_provider' === $key ) {
				$provider        = sanitize_key( (string) $value );
				$current[ $key ] = in_array( $provider, array( '', 'postmark', 'sendgrid', 'mailgun', 'ses', 'generic' ), true ) ? $provider : '';
			} elseif ( 'test_user_ids' === $key ) {
				$ids             = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) $value ) ?: array() ) );
				$current[ $key ] = implode( ',', $ids );
			} else {
				$current[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		update_option( self::OPTION, $current, false );
		return $current;
	}

	/**
	 * Internal test accounts allowed to receive campaign test sends.
	 *
	 * @return int[]
	 */
	public static function test_user_ids(): array {
		$ids = array_filter( array_map( 'absint', explode( ',', (string) self::get( 'test_user_ids' ) ) ) );
		return array_values( array_unique( $ids ) );
	}

	public static function automation_paused( string $key ): bool {
		return in_array( $key, (array) self::get( 'paused_automations' ), true );
	}

	/**
	 * Categories whose triggers may be held out for measurement. Transactional, payment,
	 * document and operational messages are never held out.
	 */
	const HOLDOUT_CATEGORIES = array( 'opportunities', 'recruitment', 'onboarding', 'digests', 'product_news' );

	/**
	 * Recipient falls in the stable measurement holdout for this trigger.
	 *
	 * @param string $trigger  Trigger key.
	 * @param int    $user_id  Recipient.
	 * @param string $priority Message priority.
	 * @param string $category Message category.
	 */
	public static function in_holdout( string $trigger, int $user_id, string $priority, string $category ): bool {
		$percent = min( 50, self::int( 'holdout_percent' ) );
		if ( $percent < 1 || $user_id < 1 || ! in_array( $priority, array( 'normal', 'promotional' ), true ) || ! in_array( $category, self::HOLDOUT_CATEGORIES, true ) ) {
			return false;
		}
		if ( ! in_array( $trigger, (array) self::get( 'holdout_triggers' ), true ) ) {
			return false;
		}
		return self::holdout_bucket( $user_id ) < $percent;
	}

	/**
	 * Stable 0–99 bucket, independent of staged-rollout buckets so holdouts never correlate with rollout stages.
	 */
	public static function holdout_bucket( int $user_id ): int {
		return (int) ( sprintf( '%u', crc32( 'comms_holdout:' . $user_id ) ) % 100 );
	}
}
