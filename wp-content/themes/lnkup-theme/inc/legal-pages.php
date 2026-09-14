<?php
/**
 * Ensure Privacy, Terms, and Contact pages exist for App Store URLs.
 *
 * @package LNKup_Theme
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Seeds / refreshes legal + support pages used by the native apps.
 */
class LNKup_Theme_Legal_Pages {

	const OPTION_SEED_VERSION = 'lnkup_theme_legal_pages_v2';

	/**
	 * Hook seeding.
	 */
	public static function init(): void {
		add_action( 'after_switch_theme', array( __CLASS__, 'force_seed' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed' ), 5 );
	}

	/**
	 * Force reseed (theme switch).
	 */
	public static function force_seed(): void {
		delete_option( self::OPTION_SEED_VERSION );
		self::maybe_seed( true );
	}

	/**
	 * Create or update privacy / terms / contact once per seed version.
	 *
	 * @param bool $force Ignore capability gate (theme switch only).
	 */
	public static function maybe_seed( bool $force = false ): void {
		if ( ! $force && get_option( self::OPTION_SEED_VERSION ) === '2' ) {
			return;
		}
		if ( ! $force && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::ensure_page(
			'privacy',
			__( 'Privacy Policy', 'lnkup-theme' ),
			self::privacy_html(),
			true
		);
		self::ensure_page(
			'terms',
			__( 'Terms of Service', 'lnkup-theme' ),
			self::terms_html(),
			true
		);
		self::ensure_page(
			'contact',
			__( 'Contact', 'lnkup-theme' ),
			self::contact_html(),
			true
		);

		update_option( self::OPTION_SEED_VERSION, '2', false );
	}

	/**
	 * Create page if missing; optionally refresh contact placeholder content.
	 *
	 * @param string $slug    Page slug.
	 * @param string $title   Title.
	 * @param string $content HTML content.
	 * @param bool   $force_refresh Always update content when true.
	 */
	private static function ensure_page( string $slug, string $title, string $content, bool $force_refresh = false ): void {
		$existing = get_page_by_path( $slug );
		if ( $existing instanceof WP_Post ) {
			$update = array(
				'ID'          => $existing->ID,
				'post_status' => 'publish',
			);
			if ( $force_refresh || 'publish' !== $existing->post_status ) {
				$update['post_content'] = $content;
				$update['post_title']   = $title;
			}
			wp_update_post( wp_slash( $update ) );
			return;
		}

		wp_insert_post(
			wp_slash(
				array(
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => $content,
					'post_author'  => 1,
				)
			),
			true
		);
	}

	/**
	 * Privacy policy HTML.
	 */
	private static function privacy_html(): string {
		$site = esc_html( home_url( '/' ) );
		return <<<HTML
<p><strong>Effective date:</strong> August 2, 2026</p>
<p>LNKup (“we”, “us”) operates the LNKup marketplace website and mobile apps at {$site}. This Privacy Policy explains what information we collect, how we use it, and the choices you have.</p>
<h2>Information we collect</h2>
<ul>
<li><strong>Account data</strong> — name, username, email, password (hashed), role (vendor/organizer), and profile details you provide.</li>
<li><strong>Marketplace data</strong> — events, opportunities, applications, documents, messages related to bookings, and payment status records.</li>
<li><strong>Device data (mobile)</strong> — optional location when you use Near Me search; push notification tokens when you enable alerts; app credentials stored securely on your device.</li>
<li><strong>Payment data</strong> — payment processing is handled by Stripe. We store payment references and amounts needed to operate the marketplace; we do not store full card numbers in LNKup.</li>
</ul>
<h2>How we use information</h2>
<ul>
<li>Provide vendor discovery, applications, event operations, and organizer tools.</li>
<li>Process booth fees and payouts through our Financial OS and Stripe.</li>
<li>Send transactional notifications (application status, payment due, push alerts you enable).</li>
<li>Improve reliability, prevent fraud, and meet legal or tax recordkeeping requirements.</li>
</ul>
<h2>Sharing</h2>
<p>We share data with organizers or vendors as needed to fulfill marketplace bookings, with Stripe for payments, and with infrastructure providers that host LNKup. We do not sell your personal information.</p>
<h2>Retention</h2>
<p>We keep account and marketplace records while your account is active. After you request account deletion, we remove or anonymize personal data that is not legally required to retain (for example certain payment/tax records) within 30 days.</p>
<h2>Your choices</h2>
<ul>
<li>Update profile information in the app or dashboard.</li>
<li>Disable push notifications in Profile / More.</li>
<li>Deny location permission; Near Me is optional.</li>
<li><strong>Delete your account</strong> from More → Delete account in the LNKup mobile app (or contact support below).</li>
</ul>
<h2>Contact</h2>
<p>Privacy questions: <a href="mailto:support@thelnkup.com">support@thelnkup.com</a> · <a href="{$site}contact/">Contact page</a></p>
HTML;
	}

	/**
	 * Terms of service HTML.
	 */
	private static function terms_html(): string {
		$site = esc_html( home_url( '/' ) );
		return <<<HTML
<p><strong>Effective date:</strong> August 2, 2026</p>
<p>These Terms govern your use of LNKup’s website and mobile applications. By creating an account or using LNKup, you agree to these Terms.</p>
<h2>The service</h2>
<p>LNKup is a vendor opportunity marketplace for the event economy. Organizers list opportunities; vendors apply, pay booth fees when accepted, and receive event logistics. LNKup is not an attendee ticketing platform.</p>
<h2>Accounts</h2>
<ul>
<li>You must provide accurate information and keep credentials secure.</li>
<li>You are responsible for activity under your account.</li>
<li>You may delete your account from within the LNKup mobile app (More → Delete account).</li>
</ul>
<h2>Fees and payments</h2>
<p>Booth fees, platform fees, and payouts are processed through LNKup’s Financial OS and Stripe. Fee amounts are shown before checkout. Refunds follow the event’s posted policy and applicable law.</p>
<h2>Acceptable use</h2>
<p>Do not misuse the marketplace, upload unlawful content, attempt to circumvent payments or security, or harass other users.</p>
<h2>Disclaimers</h2>
<p>LNKup provides the marketplace “as is.” Events are run by organizers; LNKup is not responsible for event cancellation, venue issues, or vendor–organizer disputes beyond tools we provide.</p>
<h2>Limitation of liability</h2>
<p>To the fullest extent permitted by law, LNKup’s liability is limited to fees you paid to LNKup in the three months before a claim.</p>
<h2>Contact</h2>
<p><a href="mailto:support@thelnkup.com">support@thelnkup.com</a> · <a href="{$site}contact/">Contact</a> · <a href="{$site}privacy/">Privacy Policy</a></p>
HTML;
	}

	/**
	 * Contact page HTML (no lorem placeholder).
	 */
	private static function contact_html(): string {
		$site = esc_html( home_url( '/' ) );
		return <<<HTML
<p>We’re here to help vendors and organizers use LNKup.</p>
<h2>Support</h2>
<p>Email <a href="mailto:support@thelnkup.com">support@thelnkup.com</a> for account, payment, application, or app issues. We typically respond within 1–2 business days.</p>
<h2>App Store / account deletion</h2>
<p>You can delete your LNKup account from the mobile app: open <strong>More</strong>, then <strong>Delete account</strong>. Deletion is completed within 30 days. This page does not replace the in-app deletion flow.</p>
<h2>Legal</h2>
<ul>
<li><a href="{$site}privacy/">Privacy Policy</a></li>
<li><a href="{$site}terms/">Terms of Service</a></li>
</ul>
<p>LNKup Marketplace · <a href="{$site}">{$site}</a></p>
HTML;
	}
}
