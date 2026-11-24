<?php
/**
 * Map sharing tokens and QR links.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Map_Sharing {

	public static function create_token( $event_id, $map_id, $scope = 'public', $expires_days = 30 ) {
		global $wpdb;
		$table = LNKup_Map_Tables::share_table();
		$token = wp_generate_password( 32, false );
		$expires = $expires_days ? gmdate( 'Y-m-d H:i:s', strtotime( '+' . absint( $expires_days ) . ' days' ) ) : null;
		$wpdb->insert(
			$table,
			array(
				'event_id'   => absint( $event_id ),
				'map_id'     => absint( $map_id ),
				'token'      => $token,
				'scope'      => sanitize_key( $scope ),
				'expires_at' => $expires,
				'created_at' => current_time( 'mysql' ),
			)
		);
		return self::format_token( $token, $scope, $expires );
	}

	public static function get_by_token( $token ) {
		global $wpdb;
		$table = LNKup_Map_Tables::share_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s LIMIT 1", sanitize_text_field( $token ) ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		if ( ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] ) < time() ) {
			return null;
		}
		return $row;
	}

	public static function share_url( $token ) {
		return add_query_arg(
			array(
				'lnkup_map' => $token,
			),
			lnkup_page_url_helper( 'opportunities' )
		);
	}

	/**
	 * Locally generated QR (data: URI); the share token never goes to a QR service.
	 */
	public static function qr_url( $token ) {
		return class_exists( 'LNKup_QR' ) ? LNKup_QR::data_uri( self::share_url( $token ), 200 ) : '';
	}

	public static function format_token( $token, $scope, $expires ) {
		return array(
			'token'      => $token,
			'scope'      => $scope,
			'url'        => self::share_url( $token ),
			'qr_url'     => self::qr_url( $token ),
			'expires_at' => $expires,
		);
	}

	/**
	 * Visibility scope for shared map.
	 */
	public static function visibility_for_scope( $scope ) {
		switch ( $scope ) {
			case 'staff':
				return 'staff';
			case 'vendor':
				return 'vendor';
			case 'public':
			default:
				return 'public';
		}
	}
}
