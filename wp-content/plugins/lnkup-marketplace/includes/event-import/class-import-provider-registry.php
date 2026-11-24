<?php
/**
 * Provider capability registry for event import.
 *
 * Levels: A authorized account API · B URL import from permitted structured metadata
 * (Schema.org JSON-LD / Open Graph) · C link attachment, metadata best-effort · D file/manual.
 * Capabilities reflect the providers' public documentation (docs/event-creation-os-v3/
 * PROVIDER_CAPABILITIES.md). A recognized domain never implies an official integration.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Import_Provider_Registry {

	const DISABLED_OPTION = 'lnkup_import_disabled_providers';

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$c = static fn( string $label, array $domains, string $level, array $extra = array() ) => array_merge(
			array(
				'label'       => $label,
				'domains'     => $domains,
				'level'       => $level,
				'auth'        => 'none',
				'url_import'  => in_array( $level, array( 'A', 'B', 'C' ), true ),
				'bulk'        => in_array( $level, array( 'A', 'B', 'C' ), true ),
				'recurring'   => false,
				'sync'        => 'manual_refresh',
				'media'       => 'og_image',
				'limitations' => '',
			),
			$extra
		);
		$providers = array(
			'eventbrite'    => $c( 'Eventbrite', array( 'eventbrite.com', 'eventbrite.co.uk', 'eventbrite.ca', 'eventbrite.com.au' ), 'A', array( 'auth' => 'oauth2', 'recurring' => true, 'sync' => 'api_polling', 'limitations' => 'Account import needs LNKup\'s Eventbrite app credentials. URL import reads the public page\'s structured data.' ) ),
			'posh'          => $c( 'POSH', array( 'posh.vip' ), 'B', array( 'limitations' => 'No public event API. Imports what the public page publishes; third-party-link listings may hold only what the organizer typed on POSH.' ) ),
			'eventeny'      => $c( 'Eventeny', array( 'eventeny.com' ), 'C', array( 'limitations' => 'Eventeny\'s API covers ticket data only. Event details come from the public page when available.' ) ),
			'ticketleap'    => $c( 'TicketLeap', array( 'ticketleap.com', 'ticketleap.events' ), 'C', array( 'limitations' => 'No supported integration. Public page metadata only.' ) ),
			'luma'          => $c( 'Luma', array( 'lu.ma', 'luma.com' ), 'B', array( 'limitations' => 'Account import would need the organizer\'s Luma Plus API key; not offered yet.' ) ),
			'marketspread'  => $c( 'Marketspread', array( 'marketspread.com' ), 'C' ),
			'tickettailor'  => $c( 'Ticket Tailor', array( 'tickettailor.com', 'buytickets.at' ), 'C' ),
			'humanitix'     => $c( 'Humanitix', array( 'humanitix.com', 'events.humanitix.com' ), 'C' ),
			'partiful'      => $c( 'Partiful', array( 'partiful.com' ), 'C', array( 'limitations' => 'Many Partiful events are private; link only.' ) ),
			'dice'          => $c( 'DICE', array( 'dice.fm' ), 'C' ),
			'tixr'          => $c( 'Tixr', array( 'tixr.com' ), 'C' ),
			'fever'         => $c( 'Fever', array( 'feverup.com' ), 'C' ),
			'shotgun'       => $c( 'Shotgun', array( 'shotgun.live' ), 'C' ),
			'ra'            => $c( 'Resident Advisor', array( 'ra.co' ), 'C' ),
			'ticketmaster'  => $c( 'Ticketmaster', array( 'ticketmaster.com', 'livenation.com' ), 'C', array( 'limitations' => 'Pages often block automated reads; link only.' ) ),
			'axs'           => $c( 'AXS', array( 'axs.com' ), 'C' ),
			'seatgeek'      => $c( 'SeatGeek', array( 'seatgeek.com' ), 'C' ),
			'eventzilla'    => $c( 'Eventzilla', array( 'eventzilla.net' ), 'C' ),
			'cvent'         => $c( 'Cvent', array( 'cvent.com' ), 'C' ),
			'whova'         => $c( 'Whova', array( 'whova.com' ), 'C' ),
			'facebook'      => $c( 'Facebook Events', array( 'facebook.com', 'fb.me' ), 'C', array( 'url_import' => false, 'media' => 'none', 'limitations' => 'Requires sign-in to read; attach as a link only.' ) ),
			'meetup'        => $c( 'Meetup', array( 'meetup.com' ), 'C' ),
			'website'       => $c( 'Event website', array(), 'B', array( 'limitations' => 'Works when the site publishes Schema.org Event data or Open Graph tags.' ) ),
			'ics'           => $c( 'Calendar file (ICS)', array(), 'D', array( 'url_import' => false, 'recurring' => true, 'media' => 'none', 'sync' => 'none' ) ),
			'csv'           => $c( 'Spreadsheet (CSV)', array(), 'D', array( 'url_import' => false, 'media' => 'none', 'sync' => 'none' ) ),
		);
		$disabled = self::disabled();
		foreach ( $providers as $key => &$p ) {
			$p['key']     = $key;
			$p['enabled'] = ! in_array( $key, $disabled, true );
			$p['connect'] = 'eventbrite' === $key && class_exists( 'LNKup_Eventbrite_Connection' ) && LNKup_Eventbrite_Connection::available();
		}
		unset( $p );
		return $providers;
	}

	/**
	 * @return string[]
	 */
	public static function disabled(): array {
		$v = get_option( self::DISABLED_OPTION, array() );
		return is_array( $v ) ? array_values( array_map( 'sanitize_key', $v ) ) : array();
	}

	/**
	 * @param string[] $keys Provider keys to disable.
	 */
	public static function set_disabled( array $keys ): void {
		update_option( self::DISABLED_OPTION, array_values( array_intersect( array_keys( self::all() ), array_map( 'sanitize_key', $keys ) ) ), false );
	}

	/**
	 * Provider for a URL ('website' for any other host).
	 *
	 * @return array<string,mixed>
	 */
	public static function detect( string $url ): array {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = preg_replace( '/^www\./', '', $host );
		$all  = self::all();
		foreach ( $all as $p ) {
			foreach ( $p['domains'] as $domain ) {
				if ( $host === $domain || substr( (string) $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
					return $p;
				}
			}
		}
		return $all['website'];
	}

	public static function label_for_url( string $url ): string {
		$p = self::detect( $url );
		return 'website' === $p['key'] ? (string) preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) ) : (string) $p['label'];
	}

	/**
	 * Public, non-secret view for clients.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function public_list(): array {
		$out = array();
		foreach ( self::all() as $p ) {
			$out[] = array_intersect_key( $p, array_flip( array( 'key', 'label', 'level', 'auth', 'url_import', 'bulk', 'recurring', 'connect', 'enabled', 'limitations' ) ) );
		}
		return $out;
	}
}
