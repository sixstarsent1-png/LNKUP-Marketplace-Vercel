<?php
/**
 * User roles and capabilities.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Roles {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'user_register', array( __CLASS__, 'maybe_assign_role_from_registration' ) );
		add_action( 'register_form', array( __CLASS__, 'render_registration_role_field' ) );
	}

	/**
	 * Render role selector on registration form.
	 */
	public static function render_registration_role_field() {
		?>
		<p>
			<label for="lnkup_role"><?php esc_html_e( 'I am a', 'lnkup-marketplace' ); ?></label>
			<select name="lnkup_role" id="lnkup_role">
				<option value="lnkup_vendor"><?php esc_html_e( 'Vendor', 'lnkup-marketplace' ); ?></option>
				<option value="lnkup_organizer"><?php esc_html_e( 'Organizer', 'lnkup-marketplace' ); ?></option>
			</select>
		</p>
		<?php
	}

	/**
	 * Register roles on activation.
	 */
	public static function register_roles() {
		$vendor_caps = self::vendor_capabilities();
		$organizer_caps = self::organizer_capabilities();
		$admin_caps = self::admin_capabilities();

		add_role( 'lnkup_vendor', __( 'Vendor', 'lnkup-marketplace' ), $vendor_caps );
		add_role( 'lnkup_organizer', __( 'Organizer', 'lnkup-marketplace' ), $organizer_caps );
		add_role( 'lnkup_connector', __( 'City Connector', 'lnkup-marketplace' ), self::connector_capabilities() );
		add_role( 'lnkup_admin', __( 'LNKup Admin', 'lnkup-marketplace' ), $admin_caps );

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( $admin_caps as $cap => $grant ) {
				if ( $grant ) {
					$admin->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Vendor capabilities.
	 */
	public static function vendor_capabilities() {
		return array(
			'read'                      => true,
			'lnkup_manage_own_profile'    => true,
			'lnkup_view_opportunities'    => true,
			'lnkup_submit_applications'   => true,
			'lnkup_manage_own_documents'  => true,
			'lnkup_view_own_payments'     => true,
			'lnkup_view_own_financials'   => true,
			'lnkup_request_refunds'       => true,
			'lnkup_view_own_growth'       => true,
		);
	}

	/**
	 * Organizer capabilities.
	 */
	public static function organizer_capabilities() {
		return array(
			'read'                         => true,
			'lnkup_manage_own_events'      => true,
			'lnkup_manage_own_opportunities' => true,
			'lnkup_review_applications'    => true,
			'lnkup_manage_own_packets'     => true,
			'lnkup_manage_event_ops'       => true,
			'lnkup_view_own_payments'      => true,
			'lnkup_view_own_financials'      => true,
			'lnkup_manage_own_invoices'      => true,
			'lnkup_approve_refunds'          => true,
			'lnkup_manage_payouts'           => true,
			'lnkup_manage_promo_codes'       => true,
		);
	}

	/**
	 * City Connector capabilities.
	 */
	public static function connector_capabilities() {
		return array(
			'read'                              => true,
			'lnkup_view_own_connector_dashboard' => true,
			'lnkup_manage_own_connector_links'   => true,
		);
	}

	/**
	 * Admin capabilities.
	 */
	public static function admin_capabilities() {
		return array(
			'read'                    => true,
			'lnkup_manage_all'        => true,
			'lnkup_verify_documents'  => true,
			'lnkup_manage_settings'   => true,
			'lnkup_manage_own_profile' => true,
			'lnkup_view_opportunities' => true,
			'lnkup_submit_applications' => true,
			'lnkup_manage_own_documents' => true,
			'lnkup_view_own_payments' => true,
			'lnkup_view_own_financials' => true,
			'lnkup_manage_marketplace_financials' => true,
			'lnkup_manage_marketplace_growth' => true,
			'lnkup_manage_promo_codes' => true,
			'lnkup_manage_own_events' => true,
			'lnkup_manage_own_opportunities' => true,
			'lnkup_review_applications' => true,
			'lnkup_manage_own_packets' => true,
			'lnkup_manage_event_ops' => true,
		);
	}

	/**
	 * Merge vendor/organizer capabilities into existing roles (idempotent upgrade).
	 */
	public static function sync_role_capabilities() {
		$map = array(
			'lnkup_vendor'    => self::vendor_capabilities(),
			'lnkup_organizer' => self::organizer_capabilities(),
			'lnkup_connector' => self::connector_capabilities(),
			'lnkup_admin'     => self::admin_capabilities(),
		);
		foreach ( $map as $role_slug => $caps ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap => $grant ) {
				if ( $grant ) {
					$role->add_cap( $cap );
				}
			}
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::admin_capabilities() as $cap => $grant ) {
				if ( $grant ) {
					$admin->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Assign role from registration form.
	 *
	 * @param int $user_id User ID.
	 */
	public static function maybe_assign_role_from_registration( $user_id ) {
		if ( empty( $_POST['lnkup_role'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$role = sanitize_text_field( wp_unslash( $_POST['lnkup_role'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( in_array( $role, array( 'lnkup_vendor', 'lnkup_organizer' ), true ) ) {
			$user = new WP_User( $user_id );
			$user->set_role( $role );
		}
	}

	/**
	 * Check if user is vendor.
	 *
	 * @param int $user_id User ID.
	 */
	public static function is_vendor( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		$user = get_userdata( $user_id );
		return $user && ( in_array( 'lnkup_vendor', (array) $user->roles, true ) || LNKup_Capabilities::user_has_manage_all( $user_id ) );
	}

	/**
	 * Check if user is organizer.
	 *
	 * @param int $user_id User ID.
	 */
	public static function is_organizer( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		$user = get_userdata( $user_id );
		return $user && ( in_array( 'lnkup_organizer', (array) $user->roles, true ) || LNKup_Capabilities::user_has_manage_all( $user_id ) );
	}

	/**
	 * Check if user is an active City Connector.
	 *
	 * @param int $user_id User ID.
	 */
	public static function is_connector( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return true;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'lnkup_connector', (array) $user->roles, true ) ) {
			return false;
		}
		if ( class_exists( 'LNKup_Connector' ) ) {
			$profile = LNKup_Connector::get_by_user_id( $user_id );
			return $profile && 'active' === ( $profile['status'] ?? '' );
		}
		return true;
	}
}
