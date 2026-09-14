<?php
/**
 * City × keyword query combinator for Google Search ingest.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Search_Query_Combinator {

	/**
	 * Default vendor-opportunity query templates.
	 *
	 * @return array<int, string>
	 */
	public static function default_templates(): array {
		return array(
			'{city} "vendors wanted" festival',
			'{city} "vendor application" {year}',
			'{city} "food vendors wanted"',
			'{state} "vendor registration" festival',
			'{state} "become a vendor"',
			'"vendor applications open" festival {city}',
			'{city} "booth applications" {year}',
			'{city} "craft vendors wanted"',
		);
	}

	/**
	 * Parse admin textarea (one template per line).
	 *
	 * @param string $raw Raw templates.
	 * @return array<int, string>
	 */
	public static function parse_templates( string $raw ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$out[] = $line;
		}
		return $out ?: self::default_templates();
	}

	/**
	 * US state code → search display name.
	 *
	 * @return array<string, string>
	 */
	public static function state_display_names(): array {
		return array(
			'NC' => 'North Carolina',
			'GA' => 'Georgia',
			'TX' => 'Texas',
			'FL' => 'Florida',
			'DC' => 'District of Columbia',
			'NY' => 'New York',
			'CA' => 'California',
		);
	}

	/**
	 * Launch market contexts (slug, city, state, state_name).
	 *
	 * @param array<int, string> $market_slugs Optional slug filter.
	 * @return array<int, array{slug: string, city: string, state: string, state_name: string}>
	 */
	public static function market_contexts( array $market_slugs = array() ): array {
		$markets = array();
		if ( class_exists( 'LNKup_Market_Pages' ) ) {
			$all = LNKup_Market_Pages::default_markets();
			foreach ( $all as $slug => $row ) {
				$markets[] = array(
					'slug'       => (string) $slug,
					'city'       => (string) ( $row['city'] ?? '' ),
					'state'      => strtoupper( (string) ( $row['state'] ?? '' ) ),
					'state_name' => self::state_display_name( (string) ( $row['state'] ?? '' ) ),
				);
			}
		} elseif ( class_exists( 'LNKup_External_Source_Eventbrite' ) ) {
			foreach ( array_keys( LNKup_External_Source_Eventbrite::market_browse_url_map() ) as $slug ) {
				$city = ucwords( str_replace( '-', ' ', (string) $slug ) );
				$markets[] = array(
					'slug'       => (string) $slug,
					'city'       => $city,
					'state'      => '',
					'state_name' => '',
				);
			}
		}

		if ( $market_slugs ) {
			$allow   = array_map( 'sanitize_key', $market_slugs );
			$markets = array_values(
				array_filter(
					$markets,
					static function ( $row ) use ( $allow ) {
						return in_array( sanitize_key( (string) ( $row['slug'] ?? '' ) ), $allow, true );
					}
				)
			);
		}

		return $markets;
	}

	/**
	 * Expand templates across markets and years.
	 *
	 * @param array<int, array<string, string>> $markets   Market contexts.
	 * @param array<int, string>                $templates Query templates.
	 * @param int                               $year      Current year (next year also expanded).
	 * @return array<int, array<string, string>>
	 */
	public static function expand( array $markets, array $templates, int $year = 0 ): array {
		if ( $year < 1 ) {
			$year = (int) gmdate( 'Y' );
		}
		$years = array( (string) $year, (string) ( $year + 1 ) );

		$out          = array();
		$seen         = array();
		$state_done   = array();

		foreach ( $templates as $template ) {
			$template = trim( (string) $template );
			if ( '' === $template ) {
				continue;
			}

			$needs_city  = false !== strpos( $template, '{city}' );
			$needs_state = false !== strpos( $template, '{state}' );
			$needs_year  = false !== strpos( $template, '{year}' );
			$year_list   = $needs_year ? $years : array( '' );

			if ( ! $needs_city && $needs_state ) {
				foreach ( $markets as $market ) {
					$state_key = strtoupper( (string) ( $market['state'] ?? '' ) );
					if ( '' === $state_key ) {
						$state_key = sanitize_title( (string) ( $market['state_name'] ?? '' ) );
					}
					if ( '' === $state_key || isset( $state_done[ $template . '|' . $state_key ] ) ) {
						continue;
					}
					$state_done[ $template . '|' . $state_key ] = true;
					foreach ( $year_list as $yr ) {
						self::push_combo( $out, $seen, $template, $market, $yr );
					}
				}
				continue;
			}

			if ( ! $needs_city && ! $needs_state ) {
				$blank = array(
					'slug'       => '',
					'city'       => '',
					'state'      => '',
					'state_name' => '',
				);
				foreach ( $year_list as $yr ) {
					self::push_combo( $out, $seen, $template, $blank, $yr );
				}
				continue;
			}

			foreach ( $markets as $market ) {
				foreach ( $year_list as $yr ) {
					self::push_combo( $out, $seen, $template, $market, $yr );
				}
			}
		}

		return $out;
	}

	/**
	 * Full state name for a two-letter code.
	 *
	 * @param string $code State code.
	 */
	public static function state_display_name( string $code ): string {
		$code = strtoupper( trim( $code ) );
		$map  = self::state_display_names();
		return $map[ $code ] ?? $code;
	}

	/**
	 * @param array<int, array<string, string>> $out      Accumulator.
	 * @param array<string, bool>               $seen     Dedup map.
	 * @param string                            $template Template.
	 * @param array<string, string>             $market   Market context.
	 * @param string                            $year     Year or empty.
	 */
	protected static function push_combo( array &$out, array &$seen, string $template, array $market, string $year ): void {
		$query = strtr(
			$template,
			array(
				'{city}'  => (string) ( $market['city'] ?? '' ),
				'{state}' => (string) ( $market['state_name'] ?? $market['state'] ?? '' ),
				'{year}'  => $year,
			)
		);
		$query = trim( preg_replace( '/\s+/', ' ', $query ) );
		if ( '' === $query || isset( $seen[ $query ] ) ) {
			return;
		}
		$seen[ $query ] = true;
		$out[]          = array(
			'query_text'   => $query,
			'market_slug'  => (string) ( $market['slug'] ?? '' ),
			'city'         => (string) ( $market['city'] ?? '' ),
			'state'        => (string) ( $market['state'] ?? '' ),
			'template_key' => substr( md5( $template . '|' . $year ), 0, 12 ),
		);
	}
}
