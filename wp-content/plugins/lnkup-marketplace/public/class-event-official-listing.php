<?php
/**
 * Public event page → "Official event listing": where attendees buy tickets (the organizer's
 * POSH / Eventbrite / website listing), clearly separated from LNKup's vendor applications.
 *
 * Outbound clicks go through ?lnkup_listing={source_id} so LNKup can attribute them
 * (official_listing_click). Only listings stored on a published event redirect; the URL is never
 * taken from the request, so this is not an open redirect.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Official_Listing {

	const PARAM = 'lnkup_listing';

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
	}

	/**
	 * @return array<int,array{id:int,url:string,label:string,primary:bool}>
	 */
	public static function listings( int $event_id, string $fallback = '' ): array {
		$out = array();
		if ( class_exists( 'LNKup_Event_Sources' ) ) {
			foreach ( LNKup_Event_Sources::for_event( $event_id ) as $s ) {
				if ( 'removed' === $s['availability'] ) {
					continue;
				}
				$out[] = array( 'id' => (int) $s['id'], 'url' => (string) $s['url'], 'label' => (string) $s['label'], 'primary' => (bool) $s['is_primary'] );
			}
		}
		if ( ! $out && '' !== $fallback ) {
			$out[] = array( 'id' => 0, 'url' => $fallback, 'label' => class_exists( 'LNKup_Import_Provider_Registry' ) ? LNKup_Import_Provider_Registry::label_for_url( $fallback ) : '', 'primary' => true );
		}
		return $out;
	}

	public static function render( int $event_id, string $fallback = '' ): string {
		$listings = self::listings( $event_id, $fallback );
		if ( ! $listings ) {
			return '';
		}
		$base  = get_permalink( $event_id );
		$links = '';
		foreach ( $listings as $l ) {
			$href   = $l['id'] && $base ? add_query_arg( self::PARAM, $l['id'], $base ) : $l['url'];
			$label  = '' !== $l['label']
				/* translators: %s: platform name, e.g. POSH */
				? sprintf( __( 'Tickets on %s', 'lnkup-marketplace' ), $l['label'] )
				: __( 'Event tickets', 'lnkup-marketplace' );
			$links .= sprintf(
				'<a class="lnkup-btn %1$s lnkup-btn--sm" href="%2$s" target="_blank" rel="nofollow noopener noreferrer">%3$s</a>',
				$l['primary'] ? 'lnkup-btn--dark' : 'lnkup-btn--ghost',
				esc_url( $href ),
				esc_html( $label )
			);
		}
		return '<div class="lnkup-official" data-testid="lnkup-official-listing">'
			. '<p class="lnkup-official__title">' . esc_html__( 'Official event listing', 'lnkup-marketplace' ) . '</p>'
			. '<p class="lnkup-official__note">' . esc_html__( 'Attendee tickets are sold on the official listing. Vendor applications and booth payments are handled on LNKup.', 'lnkup-marketplace' ) . '</p>'
			. '<div class="lnkup-official__links">' . $links . '</div>'
			. '</div>';
	}

	public static function maybe_redirect(): void {
		if ( empty( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$id  = absint( $_GET[ self::PARAM ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$src = class_exists( 'LNKup_Event_Sources' ) ? LNKup_Event_Sources::get( $id ) : null;
		if ( ! $src || ! $src['event_id'] || 'publish' !== get_post_status( $src['event_id'] ) || ! preg_match( '#^https?://#i', $src['url'] ) ) {
			return;
		}
		if ( class_exists( 'LNKup_Analytics_Taxonomy' ) ) {
			LNKup_Analytics_Taxonomy::emit( 'official_listing_click', array( 'event_id' => (int) $src['event_id'], 'provider' => (string) $src['provider'], 'mode' => (string) $src['mode'] ), get_current_user_id(), 0, ! is_user_logged_in() );
		}
		header( 'X-Robots-Tag: noindex, nofollow' );
		wp_redirect( $src['url'], 302, 'LNKup' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- URL comes from the stored source row, never from the request.
		exit;
	}
}
