<?php
/**
 * Anonymous, cacheable discovery endpoints for the public website and apps.
 *
 * GET lnkup/v1/public/discovery         categories, cities, tours, live counts, live pricing
 * GET lnkup/v1/public/discovery/cards   event cards for a named set (upcoming, weekend, closing, newest)
 * GET lnkup/v1/public/vendors/featured  public Passport cards (no contact details)
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Public_Discovery_REST {

	const NS        = 'lnkup/v1';
	const MAX_AGE   = 300;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/public/discovery',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'discovery' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/public/discovery/cards',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'cards' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'set'      => array(
						'type'    => 'string',
						'enum'    => array_keys( LNKup_Public_Discovery::CARD_SETS ),
						'default' => 'upcoming',
					),
					'limit'    => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 12,
						'default' => 6,
					),
					'city'     => array(
						'type'              => 'string',
						'maxLength'         => 80,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'state'    => array(
						'type'              => 'string',
						'pattern'           => '^[A-Za-z]{0,2}$',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'category' => array(
						'type'              => 'string',
						'maxLength'         => 60,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/public/vendors/featured',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'featured_vendors' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => LNKup_Public_Discovery::MAX_VENDORS,
						'default' => 8,
					),
				),
			)
		);
	}

	private static function cached( array $data ): WP_REST_Response {
		$response = rest_ensure_response( $data );
		if ( ! is_user_logged_in() ) {
			$response->header( 'Cache-Control', 'public, max-age=' . self::MAX_AGE );
		}
		return $response;
	}

	public static function discovery(): WP_REST_Response {
		$snapshot            = LNKup_Public_Discovery::snapshot();
		$snapshot['pricing'] = LNKup_Public_Discovery::pricing();
		return self::cached( $snapshot );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function cards( WP_REST_Request $request ): WP_REST_Response {
		$context  = self::validated_context(
			(string) $request->get_param( 'city' ),
			(string) $request->get_param( 'state' ),
			(string) $request->get_param( 'category' )
		);
		$cards = LNKup_Public_Discovery::event_cards( (string) $request->get_param( 'set' ), (int) $request->get_param( 'limit' ), $context );
		$out   = array();
		foreach ( $cards as $card ) {
			$media = is_array( $card['media'] ) && $card['media'] && class_exists( 'LNKup_Event_Media' ) ? LNKup_Event_Media::to_rest( LNKup_Event_Media::shape( $card['media'] ) ) : null;
			unset( $card['media'], $card['media_fallback'] );
			$card['media'] = $media;
			$out[]         = $card;
		}
		return self::cached( array( 'set' => (string) $request->get_param( 'set' ), 'context' => $context, 'items' => $out ) );
	}

	public static function featured_vendors( WP_REST_Request $request ): WP_REST_Response {
		$items = LNKup_Public_Discovery::featured_vendors( (int) $request->get_param( 'limit' ) );
		foreach ( $items as &$item ) {
			unset( $item['cover_id'] );
		}
		unset( $item );
		return self::cached( array( 'items' => $items ) );
	}

	/**
	 * Only values that exist in live listings survive (an unknown city or category is dropped,
	 * never echoed back into copy).
	 *
	 * @return array<string,mixed>
	 */
	public static function validated_context( string $city, string $state, string $category ): array {
		$snapshot = LNKup_Public_Discovery::snapshot();
		$out      = array();
		$city     = trim( $city );
		$state    = strtoupper( trim( $state ) );
		if ( '' !== $city ) {
			foreach ( LNKup_Public_Discovery::known_cities() as $known ) {
				if ( 0 === strcasecmp( $known['city'], $city ) && ( '' === $state || 0 === strcasecmp( $known['state'], $state ) ) ) {
					$out['city']  = $known['city'];
					$out['state'] = $known['state'];
					$out['label'] = $known['label'];
					break;
				}
			}
		}
		$category = trim( $category );
		if ( '' !== $category ) {
			foreach ( $snapshot['categories'] as $known ) {
				if ( 0 === strcasecmp( (string) $known['label'], $category ) || 0 === strcasecmp( (string) $known['slug'], sanitize_title( $category ) ) ) {
					$out['categories']     = (string) $known['label'];
					$out['category_label'] = (string) $known['label'];
					break;
				}
			}
		}
		return $out;
	}
}
