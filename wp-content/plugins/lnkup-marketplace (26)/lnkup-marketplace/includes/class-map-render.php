<?php
/**
 * Map UI render helpers.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Map_Render {

	/**
	 * Map shell container.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $mode     Mode: builder|live|packet|view.
	 */
	public static function shell( $event_id, $mode = 'view' ) {
		$map = class_exists( 'LNKup_Maps' ) ? LNKup_Maps::get_for_event( $event_id ) : null;
		$html = '<div class="lnkup-map-shell" id="lnkup-map-shell" data-event-id="' . esc_attr( (string) $event_id ) . '" data-mode="' . esc_attr( $mode ) . '">';
		$html .= self::toolbar( $mode );
		$html .= '<div class="lnkup-map-shell__body">';
		$html .= '<div class="lnkup-map-canvas-wrap" id="lnkup-map-canvas-wrap">';
		if ( $map && ! empty( $map['image_url'] ) ) {
			$html .= '<img src="' . esc_url( $map['image_url'] ) . '" alt="" class="lnkup-map-canvas__img" id="lnkup-map-canvas-img" />';
		} else {
			$html .= '<div class="lnkup-map-canvas__grid" id="lnkup-map-canvas-grid"></div>';
		}
		$html .= '<div class="lnkup-map-elements" id="lnkup-map-elements"></div>';
		$html .= '<svg class="lnkup-map-routes" id="lnkup-map-routes" aria-hidden="true"></svg>';
		$html .= '</div>';
		$html .= '<aside class="lnkup-map-drawer" id="lnkup-map-drawer" hidden></aside>';
		$html .= '</div>';
		$html .= self::legend();
		$html .= '</div>';
		return $html;
	}

	public static function toolbar( $mode ) {
		$html = '<div class="lnkup-map-toolbar" role="toolbar">';
		if ( 'builder' === $mode ) {
			$html .= '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" data-map-action="add-booth">' . esc_html__( 'Add Booth', 'lnkup-marketplace' ) . '</button>';
			$html .= '<button type="button" class="lnkup-btn lnkup-btn--sm" data-map-action="add-element">' . esc_html__( 'Add Element', 'lnkup-marketplace' ) . '</button>';
			$html .= '<button type="button" class="lnkup-btn lnkup-btn--sm" data-map-action="layers">' . esc_html__( 'Layers', 'lnkup-marketplace' ) . '</button>';
			$html .= '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--accent" data-map-action="save">' . esc_html__( 'Save Draft', 'lnkup-marketplace' ) . '</button>';
			$html .= '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" data-map-action="publish">' . esc_html__( 'Publish', 'lnkup-marketplace' ) . '</button>';
		}
		if ( 'live' === $mode ) {
			$html .= '<input type="search" class="lnkup-map-search" id="lnkup-map-search" placeholder="' . esc_attr__( 'Search booth, vendor, gate…', 'lnkup-marketplace' ) . '" />';
			$html .= '<button type="button" class="lnkup-btn lnkup-btn--sm" data-map-action="layers">' . esc_html__( 'Layers', 'lnkup-marketplace' ) . '</button>';
		}
		$html .= '</div>';
		return $html;
	}

	public static function legend() {
		return '<div class="lnkup-map-legend" id="lnkup-map-legend" aria-label="' . esc_attr__( 'Map legend', 'lnkup-marketplace' ) . '"></div>';
	}

	public static function empty_state( $message = '' ) {
		$message = $message ?: __( 'No map data yet. Build your venue layout to get started.', 'lnkup-marketplace' );
		return '<div class="lnkup-map-empty"><p class="lnkup-muted">' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Legacy venue map with booth pins (fixed coords).
	 */
	public static function venue_map( $event_id, $booths = null ) {
		$map_id = (int) get_post_meta( absint( $event_id ), '_lnkup_venue_map_attachment_id', true );
		if ( ! $map_id ) {
			return self::empty_state( __( 'Upload a venue map or use the Map Builder to create a schematic layout.', 'lnkup-marketplace' ) );
		}
		$url = wp_get_attachment_url( $map_id );
		if ( ! $url ) {
			return '';
		}
		if ( null === $booths && class_exists( 'LNKup_Booths' ) ) {
			$booths = LNKup_Booths::get_for_event( absint( $event_id ) );
		}
		$html = '<div class="lnkup-ops-map" id="lnkup-ops-venue-map">';
		$html .= '<div class="lnkup-ops-map__search"><input type="search" class="lnkup-ops-map-search" placeholder="' . esc_attr__( 'Search booth number…', 'lnkup-marketplace' ) . '" /></div>';
		$html .= '<div class="lnkup-ops-map__canvas"><img src="' . esc_url( $url ) . '" alt="" class="lnkup-ops-map-img" />';
		if ( $booths ) {
			foreach ( $booths as $booth ) {
				$x = (float) ( $booth['pos_x'] ?? 0 );
				$y = (float) ( $booth['pos_y'] ?? 0 );
				if ( ! $x && ! $y ) {
					continue;
				}
				$status = '';
				if ( ! empty( $booth['application_id'] ) && class_exists( 'LNKup_Event_Operations' ) ) {
					$status = LNKup_Event_Operations::resolve_ops_status( (int) $booth['application_id'] );
				}
				$html .= '<button type="button" class="lnkup-ops-map__pin lnkup-map-status--' . esc_attr( $status ) . '" style="left:' . esc_attr( (string) $x ) . '%;top:' . esc_attr( (string) $y ) . '%" data-booth-id="' . esc_attr( (string) ( $booth['id'] ?? 0 ) ) . '" data-booth-number="' . esc_attr( $booth['booth_number'] ?? '' ) . '" data-app-id="' . esc_attr( (string) ( $booth['application_id'] ?? 0 ) ) . '" data-vendor="' . esc_attr( $booth['vendor_name'] ?? '' ) . '" title="' . esc_attr( $booth['booth_number'] ?? '' ) . '"></button>';
			}
		}
		$html .= '</div><aside class="lnkup-ops-map__detail" id="lnkup-ops-map-detail" hidden></aside></div>';
		return $html;
	}
}
