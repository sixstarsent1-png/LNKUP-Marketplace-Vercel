<?php
/**
 * Leaflet / OSM map provider (default MVP).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Map_Provider_Leaflet implements LNKup_Map_Provider_Interface {

	private $variant;
	private $api_key;

	public function __construct( $variant = 'osm', $api_key = '' ) {
		$this->variant = $variant;
		$this->api_key = $api_key;
	}

	public function slug() {
		return 'leaflet';
	}

	public function is_available() {
		return true;
	}

	public function config() {
		$tiles = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
		$attribution = '&copy; OpenStreetMap';

		if ( 'mapbox' === $this->variant && $this->api_key ) {
			$tiles = 'https://api.mapbox.com/styles/v1/mapbox/streets-v11/tiles/{z}/{x}/{y}?access_token=' . rawurlencode( $this->api_key );
			$attribution = '&copy; Mapbox';
		}

		return array(
			'tileUrl'       => $tiles,
			'attribution'   => $attribution,
			'defaultCenter' => array( 35.2271, -80.8431 ),
			'defaultZoom'   => 5,
			'leafletVersion'=> '1.9.4',
		);
	}
}
