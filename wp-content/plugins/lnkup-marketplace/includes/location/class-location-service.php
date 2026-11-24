<?php
/**
 * Authoritative location normalization, validation and market resolution.
 *
 * Every location save path (events, tour stops, profiles, registration, imports, admin) and every
 * client (WordPress JS, Next.js, Expo via /locations/*) uses this class, so there is one rule set:
 *
 * - US states are validated against the full USPS list (50 states, DC, territories). "GE" is not a
 *   US state; it is never rewritten globally, only suggested when the city establishes the intent.
 * - Cities are checked against a bundled Census gazetteer (data/us-places.php, public domain).
 * - Results carry a confidence status: verified, normalized, suggested, ambiguous, unresolved.
 *   Only verified/normalized values save silently; the rest need confirmation or are flagged.
 * - City-centroid coordinates are city precision only and are never presented as a venue.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Location_Service {

	const VERIFIED   = 'verified';
	const NORMALIZED = 'normalized';
	const SUGGESTED  = 'suggested';
	const AMBIGUOUS  = 'ambiguous';
	const UNRESOLVED = 'unresolved';

	const SOURCE = 'census_gazetteer_2023';

	/** USPS codes => names: 50 states, DC and inhabited territories. */
	const US_STATES = array(
		'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
		'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia',
		'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois',
		'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana',
		'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
		'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
		'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
		'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon',
		'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
		'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia',
		'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
		'PR' => 'Puerto Rico', 'GU' => 'Guam', 'VI' => 'U.S. Virgin Islands', 'AS' => 'American Samoa',
		'MP' => 'Northern Mariana Islands',
	);

	/** Common written forms that are not USPS codes or full names. */
	const STATE_ALIASES = array(
		'd c'                  => 'DC',
		'washington dc'        => 'DC',
		'washington d c'       => 'DC',
		'district of columbia' => 'DC',
		'calif'                => 'CA',
		'cali'                 => 'CA',
		'fla'                  => 'FL',
		'tenn'                 => 'TN',
		'penn'                 => 'PA',
		'penna'                => 'PA',
		'mass'                 => 'MA',
		'wash'                 => 'WA',
		'n carolina'           => 'NC',
		's carolina'           => 'SC',
		'virgin islands'       => 'VI',
		'us virgin islands'    => 'VI',
	);

	/** Explicit city nicknames => [city, state]. Only unambiguous, widely used forms. */
	const CITY_ALIASES = array(
		'atl'           => array( 'Atlanta', 'GA' ),
		'nyc'           => array( 'New York', 'NY' ),
		'new york city' => array( 'New York', 'NY' ),
		'philly'        => array( 'Philadelphia', 'PA' ),
		'nola'          => array( 'New Orleans', 'LA' ),
		'clt'           => array( 'Charlotte', 'NC' ),
		'htx'           => array( 'Houston', 'TX' ),
		'vegas'         => array( 'Las Vegas', 'NV' ),
		'dmv'           => array( 'Washington', 'DC' ),
		'washington dc' => array( 'Washington', 'DC' ),
		'dc'            => array( 'Washington', 'DC' ),
	);

	/**
	 * Default metro membership per market slug ("City, ST"). Merged with a market's own
	 * metro_cities. Used for marketing-market assignment and opt-in metro search only — the
	 * physical city of an event is never rewritten.
	 */
	const METRO_CITIES = array(
		'atlanta'       => array( 'Decatur, GA', 'Marietta, GA', 'Sandy Springs, GA', 'Smyrna, GA', 'Alpharetta, GA', 'Roswell, GA', 'East Point, GA', 'College Park, GA', 'Dunwoody, GA', 'Brookhaven, GA', 'Kennesaw, GA', 'Lawrenceville, GA', 'Duluth, GA', 'Stone Mountain, GA', 'Stockbridge, GA', 'Douglasville, GA', 'Peachtree City, GA', 'Johns Creek, GA', 'Tucker, GA', 'Union City, GA' ),
		'charlotte'     => array( 'Concord, NC', 'Gastonia, NC', 'Huntersville, NC', 'Matthews, NC', 'Mint Hill, NC', 'Cornelius, NC', 'Mooresville, NC', 'Indian Trail, NC', 'Rock Hill, SC', 'Fort Mill, SC' ),
		'raleigh'       => array( 'Durham, NC', 'Cary, NC', 'Chapel Hill, NC', 'Apex, NC', 'Wake Forest, NC', 'Garner, NC', 'Holly Springs, NC', 'Morrisville, NC', 'Fuquay-Varina, NC', 'Knightdale, NC' ),
		'greensboro'    => array( 'High Point, NC', 'Winston-Salem, NC', 'Burlington, NC', 'Jamestown, NC' ),
		'dallas'        => array( 'Fort Worth, TX', 'Arlington, TX', 'Plano, TX', 'Irving, TX', 'Garland, TX', 'Frisco, TX', 'McKinney, TX', 'Grand Prairie, TX', 'Mesquite, TX', 'Denton, TX', 'Richardson, TX', 'Carrollton, TX', 'Lewisville, TX' ),
		'houston'       => array( 'Pasadena, TX', 'Pearland, TX', 'Sugar Land, TX', 'League City, TX', 'Baytown, TX', 'Missouri City, TX', 'Conroe, TX', 'Katy, TX', 'Spring, TX', 'The Woodlands, TX', 'Humble, TX', 'Cypress, TX' ),
		'miami'         => array( 'Miami Beach, FL', 'Hialeah, FL', 'Fort Lauderdale, FL', 'Hollywood, FL', 'Miami Gardens, FL', 'Coral Gables, FL', 'Homestead, FL', 'Doral, FL', 'North Miami, FL', 'Pembroke Pines, FL' ),
		'washington-dc' => array( 'Arlington, VA', 'Alexandria, VA', 'Silver Spring, MD', 'Bethesda, MD', 'Hyattsville, MD', 'College Park, MD', 'Rockville, MD', 'Fairfax, VA', 'Largo, MD', 'Bowie, MD', 'Upper Marlboro, MD', 'National Harbor, MD' ),
		'new-york'      => array( 'Brooklyn, NY', 'Bronx, NY', 'Queens, NY', 'Staten Island, NY', 'Jersey City, NJ', 'Newark, NJ', 'Yonkers, NY', 'Hoboken, NJ' ),
		'los-angeles'   => array( 'Long Beach, CA', 'Inglewood, CA', 'Pasadena, CA', 'Santa Monica, CA', 'Glendale, CA', 'Burbank, CA', 'Torrance, CA', 'Compton, CA', 'Carson, CA', 'Culver City, CA' ),
	);

	/** Places above this distance from the city centroid are not evidence for that city. */
	const EVIDENCE_RADIUS_MILES = 40;

	/** @var array<string, array<int, array{0:string,1:string,2:float,3:float,4:int}>>|null */
	private static $places = null;

	/** @return array<string, array<int, array{0:string,1:string,2:float,3:float,4:int}>> */
	public static function places(): array {
		if ( null === self::$places ) {
			$file         = __DIR__ . '/data/us-places.php';
			$data         = file_exists( $file ) ? require $file : array();
			self::$places = is_array( $data ) ? $data : array();
		}
		return self::$places;
	}

	/**
	 * Lookup key shared with scripts/build-us-places.php.
	 *
	 * @param string $name City name.
	 */
	public static function normalize_key( string $name ): string {
		$name = strtolower( trim( $name ) );
		$name = str_replace( array( '.', "'", '’' ), '', $name );
		$name = (string) preg_replace( '/[\s\-\/]+/', ' ', $name );
		$name = (string) preg_replace( '/^saint /', 'st ', $name );
		$name = (string) preg_replace( '/^sainte /', 'ste ', $name );
		$name = (string) preg_replace( '/^fort /', 'ft ', $name );
		$name = (string) preg_replace( '/^mount /', 'mt ', $name );
		return trim( $name );
	}

	/** Whitespace, punctuation and capitalization cleanup for display. */
	public static function clean_text( string $value ): string {
		$value = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) );
		return trim( $value, " ,;" );
	}

	/**
	 * Normalize a country to an ISO alpha-2 code; '' and US spellings => US.
	 *
	 * @param string $country Raw country.
	 */
	public static function country_code( string $country ): string {
		$key = strtolower( str_replace( array( '.', ' ' ), '', trim( $country ) ) );
		if ( in_array( $key, array( '', 'us', 'usa', 'unitedstates', 'unitedstatesofamerica', 'america' ), true ) ) {
			return 'US';
		}
		return 2 === strlen( $key ) && ctype_alpha( $key ) ? strtoupper( $key ) : strtoupper( substr( self::clean_text( $country ), 0, 56 ) );
	}

	/**
	 * USPS code for a US state code, full name or common alias; null when not a US state.
	 *
	 * @param string $input Raw state.
	 */
	public static function state_code( string $input ): ?string {
		$raw = strtolower( trim( (string) preg_replace( '/[^a-z ]+/i', ' ', $input ) ) );
		$raw = trim( (string) preg_replace( '/\s+/', ' ', $raw ) );
		if ( '' === $raw ) {
			return null;
		}
		$upper = strtoupper( str_replace( ' ', '', $raw ) );
		if ( 2 === strlen( $upper ) && isset( self::US_STATES[ $upper ] ) ) {
			return $upper;
		}
		if ( isset( self::STATE_ALIASES[ $raw ] ) ) {
			return self::STATE_ALIASES[ $raw ];
		}
		foreach ( self::US_STATES as $code => $name ) {
			if ( strtolower( str_replace( '.', '', $name ) ) === $raw ) {
				return $code;
			}
		}
		return null;
	}

	/** @param string $code USPS code. */
	public static function state_name( string $code ): string {
		return self::US_STATES[ strtoupper( $code ) ] ?? '';
	}

	/**
	 * Split free text ("charlotte nc", "Houston Texas", "Washington D.C.") into city + state.
	 *
	 * @param string $query Raw text.
	 * @return array{city: string, state: string}
	 */
	public static function parse_freeform( string $query ): array {
		$query = self::clean_text( $query );
		if ( '' === $query ) {
			return array( 'city' => '', 'state' => '' );
		}
		$query = (string) preg_replace( '/(?:,\s*|\s+)(usa|united states( of america)?|us)$/i', '', $query );
		if ( false !== strpos( $query, ',' ) ) {
			$parts = array_map( 'trim', explode( ',', $query ) );
			return array( 'city' => (string) $parts[0], 'state' => (string) ( $parts[1] ?? '' ) );
		}
		if ( preg_match( '/^(.*?)\s*\bd\.?\s?c\.?$/i', $query, $m ) && '' !== trim( $m[1] ) ) {
			return array( 'city' => trim( $m[1] ), 'state' => 'DC' );
		}
		$words = preg_split( '/\s+/', $query );
		$count = count( $words );
		for ( $take = min( 3, $count - 1 ); $take >= 1; $take-- ) {
			$tail = implode( ' ', array_slice( $words, -$take ) );
			$head = implode( ' ', array_slice( $words, 0, $count - $take ) );
			if ( null !== self::state_code( $tail ) && '' !== $head ) {
				return array( 'city' => $head, 'state' => $tail );
			}
		}
		return array( 'city' => $query, 'state' => '' );
	}

	/**
	 * Resolve a location to its canonical form with a confidence status.
	 *
	 * @param string               $city    City.
	 * @param string               $state   State / region.
	 * @param string               $country Country (default US).
	 * @param array<string, mixed> $context Optional evidence: lat, lng.
	 * @return array<string, mixed>
	 */
	public static function resolve( string $city, string $state = '', string $country = '', array $context = array() ): array {
		$input   = array( 'city' => $city, 'state' => $state, 'country' => $country );
		$city    = self::clean_text( $city );
		$state   = self::clean_text( $state );
		$country = self::country_code( $country );

		if ( '' === $state && false !== strpos( $city, ',' ) || ( '' === $state && 'US' === $country && preg_match( '/\s/', $city ) ) ) {
			$parsed = self::parse_freeform( $city );
			if ( '' !== $parsed['state'] ) {
				$city  = $parsed['city'];
				$state = $parsed['state'];
			}
		}

		if ( 'US' !== $country ) {
			return self::result( self::NORMALIZED, $input, self::title_case( $city ), strtoupper( $state ), '', $country, null, 'international', '', array() );
		}
		if ( '' === $city ) {
			return self::result( self::UNRESOLVED, $input, '', (string) self::state_code( $state ), '', 'US', null, 'input', 'city_required', array() );
		}

		$alias = self::CITY_ALIASES[ self::normalize_key( $city ) ] ?? null;
		if ( $alias && ( '' === $state || self::state_code( $state ) === $alias[1] ) ) {
			$city  = $alias[0];
			$state = $alias[1];
		}

		$code = self::state_code( $state );
		if ( null !== $code ) {
			// "Alvin tx, TX" / "Birmingham Alabama, AL": the state was typed into the city too.
			$words = preg_split( '/\s+/', $city );
			for ( $take = min( 3, count( $words ) - 1 ); $take >= 1; $take-- ) {
				if ( self::state_code( implode( ' ', array_slice( $words, -$take ) ) ) === $code ) {
					$city = implode( ' ', array_slice( $words, 0, count( $words ) - $take ) );
					break;
				}
			}
		}
		$candidates = self::places()[ self::normalize_key( $city ) ] ?? array();

		if ( null !== $code ) {
			foreach ( $candidates as $place ) {
				if ( $place[0] === $code ) {
					$changed = $place[1] !== self::clean_text( (string) $input['city'] ) || $code !== self::clean_text( (string) $input['state'] );
					return self::result( $changed ? self::NORMALIZED : self::VERIFIED, $input, $place[1], $code, '', 'US', $place, self::SOURCE, $changed ? 'formatting' : '', array() );
				}
			}
			$near = self::fuzzy_in_state( $city, $code );
			if ( $near ) {
				return self::result( self::SUGGESTED, $input, $near[0][1], $code, '', 'US', $near[0], self::SOURCE, 'city_spelling', $near );
			}
			// Valid state, city not in the reference list (small or unincorporated place): keep as
			// entered. Large same-name cities elsewhere are returned so audits can flag a likely
			// city/state mismatch ("Atlanta, NC") without blocking real small towns at save time.
			$elsewhere = array_values(
				array_filter(
					$candidates,
					static function ( $place ) {
						return (int) $place[4] >= 100000;
					}
				)
			);
			return self::result( self::NORMALIZED, $input, self::title_case( $city ), $code, '', 'US', null, 'input', 'city_unverified', array_slice( $elsewhere, 0, 3 ) );
		}

		if ( '' === $state ) {
			if ( ! $candidates ) {
				return self::result( self::UNRESOLVED, $input, self::title_case( $city ), '', '', 'US', null, 'input', 'state_required', array() );
			}
			$dominant = 1 === count( $candidates ) || $candidates[0][4] >= 50 * max( 1, $candidates[1][4] );
			$status   = $dominant ? self::SUGGESTED : self::AMBIGUOUS;
			return self::result( $status, $input, $candidates[0][1], $candidates[0][0], '', 'US', $dominant ? $candidates[0] : null, self::SOURCE, $dominant ? 'state_missing' : 'multiple_cities', array_slice( $candidates, 0, 6 ) );
		}

		// State present but not a US state (e.g. "GE"). Suggest only when the city establishes intent.
		if ( ! $candidates ) {
			return self::result( self::UNRESOLVED, $input, self::title_case( $city ), strtoupper( $state ), '', 'US', null, 'input', 'invalid_state', array() );
		}
		$typed = strtoupper( (string) preg_replace( '/[^a-z]/i', '', $state ) );
		usort(
			$candidates,
			static function ( $a, $b ) use ( $typed ) {
				$da = 2 === strlen( $typed ) ? levenshtein( $typed, $a[0] ) : 9;
				$db = 2 === strlen( $typed ) ? levenshtein( $typed, $b[0] ) : 9;
				return array( min( $da, 2 ), -$a[4] ) <=> array( min( $db, 2 ), -$b[4] );
			}
		);
		$best     = $candidates[0];
		$evidence = self::coordinates_support( $best, $context );
		return self::result( self::SUGGESTED, $input, $best[1], $best[0], '', 'US', $best, self::SOURCE, 'invalid_state', array_slice( $candidates, 0, 6 ), $evidence );
	}

	/**
	 * Whether stored coordinates place the record within the candidate city.
	 *
	 * @param array<int, mixed>    $place   Gazetteer entry.
	 * @param array<string, mixed> $context lat/lng evidence.
	 */
	private static function coordinates_support( array $place, array $context ): bool {
		$lat = isset( $context['lat'] ) && is_numeric( $context['lat'] ) ? (float) $context['lat'] : null;
		$lng = isset( $context['lng'] ) && is_numeric( $context['lng'] ) ? (float) $context['lng'] : null;
		if ( null === $lat || null === $lng || ( 0.0 === $lat && 0.0 === $lng ) ) {
			return false;
		}
		return self::distance_miles( $lat, $lng, (float) $place[2], (float) $place[3] ) <= self::EVIDENCE_RADIUS_MILES;
	}

	/**
	 * Close spellings of a city inside one state ("Charolette, NC").
	 *
	 * @param string $city City.
	 * @param string $code USPS code.
	 * @return array<int, array<int, mixed>>
	 */
	private static function fuzzy_in_state( string $city, string $code ): array {
		$key = self::normalize_key( $city );
		if ( strlen( $key ) < 4 ) {
			return array();
		}
		$max     = strlen( $key ) >= 8 ? 2 : 1;
		$matches = array();
		foreach ( self::places() as $place_key => $list ) {
			if ( abs( strlen( (string) $place_key ) - strlen( $key ) ) > $max || $place_key[0] !== $key[0] ) {
				continue;
			}
			if ( levenshtein( $key, (string) $place_key ) > $max ) {
				// Transposed letters ("Pheonix") cost 2 edits but stay very similar.
				similar_text( $key, (string) $place_key, $percent );
				if ( $percent < 85 || strlen( $key ) < 6 ) {
					continue;
				}
			}
			foreach ( $list as $place ) {
				if ( $place[0] === $code ) {
					$matches[] = $place;
				}
			}
		}
		usort(
			$matches,
			static function ( $a, $b ) {
				return $b[4] <=> $a[4];
			}
		);
		return array_slice( $matches, 0, 3 );
	}

	/**
	 * Autocomplete: "Atlanta", "atlanta ga", "Charlotte North Carolina", "Columbus".
	 *
	 * @param string $query Text.
	 * @param int    $limit Max results.
	 * @return array<int, array<string, mixed>>
	 */
	public static function suggest( string $query, int $limit = 8 ): array {
		$parsed = self::parse_freeform( $query );
		$key    = self::normalize_key( $parsed['city'] );
		$code   = '' !== $parsed['state'] ? self::state_code( $parsed['state'] ) : null;
		if ( strlen( $key ) < 2 ) {
			return array();
		}
		$alias = self::CITY_ALIASES[ $key ] ?? null;
		$found = array();
		foreach ( self::places() as $place_key => $list ) {
			if ( 0 !== strpos( (string) $place_key, $key ) ) {
				continue;
			}
			foreach ( $list as $place ) {
				if ( null !== $code && $place[0] !== $code ) {
					continue;
				}
				$found[] = $place;
			}
		}
		if ( $alias ) {
			foreach ( self::places()[ self::normalize_key( $alias[0] ) ] ?? array() as $place ) {
				if ( $place[0] === $alias[1] ) {
					array_unshift( $found, $place );
				}
			}
		}
		usort(
			$found,
			static function ( $a, $b ) use ( $key ) {
				$exact_a = self::normalize_key( $a[1] ) === $key ? 0 : 1;
				$exact_b = self::normalize_key( $b[1] ) === $key ? 0 : 1;
				return array( $exact_a, -$a[4] ) <=> array( $exact_b, -$b[4] );
			}
		);
		$out  = array();
		$seen = array();
		foreach ( $found as $place ) {
			$id = $place[1] . '|' . $place[0];
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = self::place_payload( $place );
			if ( count( $out ) >= max( 1, min( 15, $limit ) ) ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Validate a location before it is saved.
	 *
	 * - strict (REST and confirmed client forms): suggested/ambiguous/unresolved => WP_Error 422 with
	 *   suggestions, unless the request confirms one of the offered suggestions exactly.
	 * - lenient (legacy forms, imports, sync jobs): never blocks; uncertain values are saved as
	 *   entered (cleaned) and flagged for Location Health review.
	 *
	 * @param string               $city    City.
	 * @param string               $state   State.
	 * @param string               $country Country.
	 * @param array<string, mixed> $args    mode (strict|lenient), confirmed (bool), context (lat/lng).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function validate_for_save( string $city, string $state, string $country = '', array $args = array() ) {
		$mode      = 'strict' === ( $args['mode'] ?? 'lenient' ) ? 'strict' : 'lenient';
		$confirmed = ! empty( $args['confirmed'] );
		if ( '' === trim( $city ) && '' === trim( $state ) ) {
			return array( 'city' => '', 'state' => '', 'country' => self::country_code( $country ), 'status' => '', 'display' => '', 'lat' => null, 'lng' => null, 'precision' => '', 'source' => '', 'reason' => '', 'suggestions' => array() );
		}
		$result = self::resolve( $city, $state, $country, (array) ( $args['context'] ?? array() ) );
		if ( in_array( $result['status'], array( self::VERIFIED, self::NORMALIZED ), true ) ) {
			return $result;
		}
		if ( $confirmed ) {
			foreach ( (array) $result['suggestions'] as $suggestion ) {
				if ( 0 === strcasecmp( (string) $suggestion['city'], self::clean_text( $city ) ) && (string) $suggestion['state_code'] === strtoupper( self::clean_text( $state ) ) ) {
					return array_merge( $result, $suggestion, array( 'status' => self::VERIFIED, 'reason' => 'confirmed', 'suggestions' => array() ) );
				}
			}
			// The submitted pair itself may now be valid (the user picked "Atlanta, GA").
			$recheck = self::resolve( $city, $state, $country );
			if ( in_array( $recheck['status'], array( self::VERIFIED, self::NORMALIZED ), true ) ) {
				return $recheck;
			}
		}
		if ( 'strict' === $mode ) {
			return new WP_Error(
				'lnkup_location_needs_confirmation',
				self::confirmation_message( $result ),
				array( 'status' => 422, 'location' => $result )
			);
		}
		// Lenient: keep what the user typed (cleaned), flagged for review, never a guessed rewrite.
		$result['city']      = self::title_case( self::clean_text( (string) $result['input']['city'] ) );
		$result['state_code'] = strtoupper( self::clean_text( (string) $result['input']['state'] ) );
		$result['display']   = trim( $result['city'] . ( $result['state_code'] ? ', ' . $result['state_code'] : '' ) );
		$result['lat']       = null;
		$result['lng']       = null;
		$result['precision'] = '';
		return $result;
	}

	/**
	 * Canonical city/state pair for a profile (organizer home base, vendor home city).
	 * Empty input passes through. Strict mode returns the same 422 WP_Error as events.
	 *
	 * @param string $city    City.
	 * @param string $state   State.
	 * @param string $country Country.
	 * @param string $mode    strict|lenient.
	 * @return array{city: string, state: string, status: string}|WP_Error
	 */
	public static function canonical_profile_location( string $city, string $state, string $country = '', string $mode = 'strict' ) {
		$result = self::validate_for_save( $city, $state, $country, array( 'mode' => $mode ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'city'   => (string) $result['city'],
			'state'  => (string) $result['state_code'],
			'status' => (string) $result['status'],
		);
	}

	/**
	 * Friendly message for a location that needs confirmation.
	 *
	 * @param array<string, mixed> $result resolve() output.
	 */
	public static function confirmation_message( array $result ): string {
		$first = $result['suggestions'][0]['display'] ?? '';
		switch ( (string) $result['status'] ) {
			case self::SUGGESTED:
				/* translators: %s: suggested location, e.g. "Atlanta, GA" */
				return $first ? sprintf( __( 'Did you mean %s? Confirm the location to continue.', 'lnkup-marketplace' ), $first ) : __( 'Please confirm the location.', 'lnkup-marketplace' );
			case self::AMBIGUOUS:
				return __( 'More than one city matches. Choose the state to continue.', 'lnkup-marketplace' );
			default:
				return 'invalid_state' === ( $result['reason'] ?? '' )
					? __( 'That state is not a valid US state. Choose a city and state from the list.', 'lnkup-marketplace' )
					: __( 'We could not find that city. Choose a city and state from the list.', 'lnkup-marketplace' );
		}
	}

	/**
	 * Marketing market slug for a canonical city: exact market, alias, then metro membership.
	 *
	 * @param string $city  Canonical city.
	 * @param string $state USPS code.
	 */
	public static function market_for( string $city, string $state ): string {
		if ( '' === $city || ! class_exists( 'LNKup_Market_Pages' ) ) {
			return '';
		}
		$key   = self::normalize_key( $city ) . '|' . strtoupper( $state );
		$metro = '';
		foreach ( LNKup_Market_Pages::get_all_markets() as $slug => $market ) {
			$slug = sanitize_title( (string) ( $market['slug'] ?? $slug ) );
			if ( self::normalize_key( (string) ( $market['city'] ?? '' ) ) . '|' . strtoupper( (string) ( $market['state'] ?? '' ) ) === $key ) {
				return $slug;
			}
			foreach ( array_merge( (array) ( $market['aliases'] ?? array() ), self::METRO_CITIES[ $slug ] ?? array(), (array) ( $market['metro_cities'] ?? array() ) ) as $member ) {
				$parts = array_map( 'trim', explode( ',', (string) $member ) );
				if ( '' === $metro && self::normalize_key( (string) $parts[0] ) . '|' . strtoupper( (string) ( $parts[1] ?? '' ) ) === $key ) {
					$metro = $slug;
				}
			}
		}
		return $metro;
	}

	/**
	 * Canonical cities that belong to a market (city itself first), for metro-wide search.
	 *
	 * @param string $slug Market slug.
	 * @return array<int, array{city: string, state: string}>
	 */
	public static function market_cities( string $slug ): array {
		if ( ! class_exists( 'LNKup_Market_Pages' ) ) {
			return array();
		}
		$markets = LNKup_Market_Pages::get_all_markets();
		$market  = $markets[ $slug ] ?? null;
		if ( ! is_array( $market ) ) {
			return array();
		}
		$out = array( array( 'city' => (string) $market['city'], 'state' => strtoupper( (string) $market['state'] ) ) );
		foreach ( array_merge( self::METRO_CITIES[ $slug ] ?? array(), (array) ( $market['metro_cities'] ?? array() ) ) as $member ) {
			$parts = array_map( 'trim', explode( ',', (string) $member ) );
			if ( '' !== $parts[0] ) {
				$out[] = array( 'city' => $parts[0], 'state' => strtoupper( (string) ( $parts[1] ?? '' ) ) );
			}
		}
		return $out;
	}

	/**
	 * Store a validated location on an event (or tour stop). Original input is kept only when
	 * the value changed. Never stores city-centroid coordinates as venue coordinates.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $result   validate_for_save() output.
	 */
	public static function apply_to_event( int $event_id, array $result ): void {
		if ( $event_id < 1 || '' === (string) ( $result['status'] ?? '' ) ) {
			return;
		}
		$old_city  = (string) get_post_meta( $event_id, '_lnkup_city', true );
		$old_state = (string) get_post_meta( $event_id, '_lnkup_state', true );
		update_post_meta( $event_id, '_lnkup_city', (string) $result['city'] );
		update_post_meta( $event_id, '_lnkup_state', (string) $result['state_code'] );
		update_post_meta( $event_id, '_lnkup_country', (string) $result['country_code'] );
		update_post_meta( $event_id, '_lnkup_location_status', (string) $result['status'] );
		update_post_meta( $event_id, '_lnkup_location_source', (string) $result['source'] );
		update_post_meta( $event_id, '_lnkup_location_verified_at', gmdate( 'c' ) );
		$market = self::market_for( (string) $result['city'], (string) $result['state_code'] );
		update_post_meta( $event_id, '_lnkup_market_slug', $market );
		$hidden_or_tba = '' === trim( (string) get_post_meta( $event_id, '_lnkup_venue_address', true ) . (string) get_post_meta( $event_id, '_lnkup_address_line1', true ) );
		update_post_meta( $event_id, '_lnkup_location_precision', $hidden_or_tba ? 'city' : 'venue' );
		$moved = ( '' !== $old_city || '' !== $old_state ) && ( 0 !== strcasecmp( $old_city, (string) $result['city'] ) || 0 !== strcasecmp( $old_state, (string) $result['state_code'] ) );
		if ( $moved && ! get_post_meta( $event_id, '_lnkup_geocode_manual_override', true ) ) {
			// Coordinates from the old location are no longer trustworthy; the geocoder refreshes them.
			delete_post_meta( $event_id, '_lnkup_lat' );
			delete_post_meta( $event_id, '_lnkup_lng' );
			update_post_meta( $event_id, '_lnkup_geocode_status', 'pending' );
		}
	}

	/**
	 * Great-circle distance in miles.
	 */
	public static function distance_miles( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$r    = 3958.8;
		$dlat = deg2rad( $lat2 - $lat1 );
		$dlng = deg2rad( $lng2 - $lng1 );
		$a    = sin( $dlat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dlng / 2 ) ** 2;
		return $r * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	/** Title case that keeps "McKinney", "St. Louis", "Winston-Salem" readable. */
	public static function title_case( string $value ): string {
		$value = self::clean_text( $value );
		if ( '' === $value || $value !== strtolower( $value ) && $value !== strtoupper( $value ) ) {
			return $value;
		}
		if ( $value === strtoupper( $value ) && strlen( $value ) <= 3 && ctype_alpha( $value ) ) {
			// A lone all-caps token ("RDU", "DMV") is an acronym, not a shouted city name.
			return $value;
		}
		return (string) preg_replace_callback(
			'/([a-z])([a-z\']*)/i',
			static function ( $m ) {
				return strtoupper( $m[1] ) . strtolower( $m[2] );
			},
			strtolower( $value )
		);
	}

	/**
	 * @param array<int, mixed> $place Gazetteer entry.
	 * @return array<string, mixed>
	 */
	private static function place_payload( array $place ): array {
		return array(
			'city'         => (string) $place[1],
			'state_code'   => (string) $place[0],
			'state_name'   => self::state_name( (string) $place[0] ),
			'country_code' => 'US',
			'display'      => $place[1] . ', ' . $place[0],
			'label'        => $place[1] . ', ' . $place[0] . ', United States',
			'lat'          => (float) $place[2],
			'lng'          => (float) $place[3],
			'market_slug'  => self::market_for( (string) $place[1], (string) $place[0] ),
		);
	}

	/**
	 * @param string                        $status      Status.
	 * @param array<string, string>         $input       Raw input.
	 * @param string                        $city        Canonical city.
	 * @param string                        $state       State code.
	 * @param string                        $unused      Reserved.
	 * @param string                        $country     Country code.
	 * @param array<int, mixed>|null        $place       Matched gazetteer entry.
	 * @param string                        $source      Verification source.
	 * @param string                        $reason      Reason code.
	 * @param array<int, array<int, mixed>> $suggestions Candidate entries.
	 * @param bool                          $evidence    Stored coordinates support the suggestion.
	 * @return array<string, mixed>
	 */
	private static function result( string $status, array $input, string $city, string $state, string $unused, string $country, ?array $place, string $source, string $reason, array $suggestions, bool $evidence = false ): array {
		unset( $unused );
		$display = trim( $city . ( '' !== $state ? ', ' . $state : '' ) );
		return array(
			'status'       => $status,
			'input'        => $input,
			'city'         => $city,
			'state_code'   => $state,
			'state_name'   => 'US' === $country ? self::state_name( $state ) : '',
			'country_code' => $country,
			'display'      => $display,
			'lat'          => $place ? (float) $place[2] : null,
			'lng'          => $place ? (float) $place[3] : null,
			'precision'    => $place ? 'city' : '',
			'source'       => $source,
			'reason'       => $reason,
			'evidence'     => $evidence,
			'market_slug'  => in_array( $status, array( self::VERIFIED, self::NORMALIZED ), true ) && 'US' === $country ? self::market_for( $city, $state ) : '',
			'suggestions'  => array_map( array( __CLASS__, 'place_payload' ), $suggestions ),
		);
	}
}
