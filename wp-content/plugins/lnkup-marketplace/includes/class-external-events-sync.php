<?php
/**
 * External events auto-sync orchestrator.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Events_Sync {

	const STATE_OPTION = 'lnkup_external_sync_state';

	const MODE_FULL      = 'full';
	const MODE_DISCOVERY = 'discovery';
	const MODE_REFRESH   = 'refresh';

	/**
	 * Default launch market slugs for sync.
	 *
	 * @return array<int, string>
	 */
	public static function default_market_slugs(): array {
		return array_keys( LNKup_External_Source_Eventbrite::market_browse_url_map() );
	}

	/**
	 * Sync configuration from settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_config(): array {
		$settings = lnkup_get_settings();
		$defaults = self::default_market_slugs();
		$slugs    = $settings['external_sync_market_slugs'] ?? $defaults;
		if ( ! is_array( $slugs ) || empty( $slugs ) ) {
			$slugs = $defaults;
		}
		$slugs = array_values(
			array_filter(
				array_map( 'sanitize_key', $slugs ),
				static function ( $slug ) {
					return isset( LNKup_External_Source_Eventbrite::market_browse_url_map()[ $slug ] );
				}
			)
		);

		$frequency = sanitize_key( (string) ( $settings['external_sync_frequency'] ?? 'daily' ) );
		if ( ! in_array( $frequency, array( 'daily', 'twice_daily' ), true ) ) {
			$frequency = 'daily';
		}

		$keywords = sanitize_text_field( (string) ( $settings['external_sync_keywords'] ?? '' ) );
		$keyword_list = array_values(
			array_filter(
				array_map( 'trim', explode( ',', $keywords ) ),
				static function ( $word ) {
					return '' !== $word;
				}
			)
		);

		$vendor_keywords = sanitize_text_field( (string) ( $settings['external_sync_vendor_keywords'] ?? '' ) );
		$vendor_list     = array_values(
			array_filter(
				array_map( 'trim', explode( ',', $vendor_keywords ) ),
				static function ( $word ) {
					return '' !== $word;
				}
			)
		);

		return array(
			'enabled'                     => ! empty( $settings['external_sync_enabled'] ),
			'frequency'                   => $frequency,
			'market_slugs'                => $slugs ?: $defaults,
			'max_new_per_run'             => max( 1, (int) ( $settings['external_sync_max_new_per_run'] ?? 25 ) ),
			'max_refresh_per_run'         => max( 1, (int) ( $settings['external_sync_max_refresh_per_run'] ?? 50 ) ),
			'max_candidates_per_market'   => max( 5, (int) ( $settings['external_sync_max_candidates_per_market'] ?? 35 ) ),
			'refresh_after_days'          => max( 1, (int) ( $settings['external_sync_refresh_after_days'] ?? 7 ) ),
			'keywords'                    => $keyword_list,
			'vendor_filter_enabled'       => ! isset( $settings['external_sync_vendor_filter_enabled'] ) || ! empty( $settings['external_sync_vendor_filter_enabled'] ),
			'vendor_keywords'             => $vendor_list ?: LNKup_External_Events::default_vendor_sync_terms(),
			'fetch_delay_seconds'         => 1,
			'max_fetch_seconds'           => max( 60, (int) ( $settings['external_sync_max_fetch_seconds'] ?? 300 ) ),
			'run_started_at'              => 0,
		);
	}

	/**
	 * Persist sync settings (admin Auto sync tab).
	 *
	 * @param array<string, mixed> $input Raw POST/settings input.
	 */
	public static function save_settings( array $input ): void {
		$settings = lnkup_get_settings();
		$settings['external_sync_enabled']             = ! empty( $input['external_sync_enabled'] );
		$frequency                                     = sanitize_key( (string) ( $input['external_sync_frequency'] ?? 'daily' ) );
		$settings['external_sync_frequency']           = in_array( $frequency, array( 'daily', 'twice_daily' ), true ) ? $frequency : 'daily';
		$settings['external_sync_max_new_per_run']     = max( 1, (int) ( $input['external_sync_max_new_per_run'] ?? 25 ) );
		$settings['external_sync_max_refresh_per_run'] = max( 1, (int) ( $input['external_sync_max_refresh_per_run'] ?? 50 ) );
		$settings['external_sync_refresh_after_days']  = max( 1, (int) ( $input['external_sync_refresh_after_days'] ?? 7 ) );
		$settings['external_sync_keywords']            = sanitize_text_field( (string) ( $input['external_sync_keywords'] ?? '' ) );
		$settings['external_sync_vendor_filter_enabled'] = ! empty( $input['external_sync_vendor_filter_enabled'] );
		$settings['external_sync_vendor_keywords']     = sanitize_text_field( (string) ( $input['external_sync_vendor_keywords'] ?? '' ) );
		$settings['external_sync_max_fetch_seconds']   = max( 60, (int) ( $input['external_sync_max_fetch_seconds'] ?? 300 ) );
		$settings['external_sync_max_candidates_per_market'] = max( 5, (int) ( $input['external_sync_max_candidates_per_market'] ?? 35 ) );

		$slugs = array();
		if ( ! empty( $input['external_sync_market_slugs'] ) && is_array( $input['external_sync_market_slugs'] ) ) {
			foreach ( $input['external_sync_market_slugs'] as $slug ) {
				$slug = sanitize_key( (string) $slug );
				if ( isset( LNKup_External_Source_Eventbrite::market_browse_url_map()[ $slug ] ) ) {
					$slugs[] = $slug;
				}
			}
		}
		$settings['external_sync_market_slugs'] = $slugs ?: self::default_market_slugs();

		update_option( 'lnkup_settings', $settings );

		if ( class_exists( 'LNKup_External_Events_Cron' ) ) {
			LNKup_External_Events_Cron::sync_schedule();
		}
	}

	/**
	 * Last sync run state.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param array<string, mixed> $summary Run summary.
	 */
	public static function record_run( array $summary ): void {
		$summary['finished_at'] = current_time( 'mysql' );
		update_option( self::STATE_OPTION, $summary, false );
	}

	/**
	 * Run sync job.
	 *
	 * @param string $mode     full|discovery|refresh.
	 * @param string $market   Optional single market slug.
	 * @return array<string, mixed>
	 */
	public static function run( string $mode = self::MODE_FULL, string $market = '' ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		$config = self::get_config();
		$config['run_started_at'] = time();
		$summary = array(
			'mode'                  => $mode,
			'started_at'            => current_time( 'mysql' ),
			'discovered'            => 0,
			'imported'              => 0,
			'refreshed'             => 0,
			'skipped'               => 0,
			'skipped_vendor_filter' => 0,
			'errors'                => 0,
			'partial'               => false,
			'messages'              => array(),
		);

		if ( self::MODE_REFRESH !== $mode ) {
			$discovery = self::run_discovery_batch( $config, $market );
			$summary   = self::merge_summary( $summary, $discovery );
		}

		if ( self::MODE_DISCOVERY !== $mode ) {
			$refresh = self::run_refresh_batch( $config );
			$summary = self::merge_summary( $summary, $refresh );
		}

		self::record_run( $summary );
		return $summary;
	}

	/**
	 * @param array<string, mixed> $base    Base summary.
	 * @param array<string, mixed> $partial Partial summary.
	 * @return array<string, mixed>
	 */
	protected static function merge_summary( array $base, array $partial ): array {
		foreach ( array( 'discovered', 'imported', 'refreshed', 'skipped', 'skipped_vendor_filter', 'errors' ) as $key ) {
			$base[ $key ] = (int) ( $base[ $key ] ?? 0 ) + (int) ( $partial[ $key ] ?? 0 );
		}
		if ( ! empty( $partial['partial'] ) ) {
			$base['partial'] = true;
		}
		$messages = (array) ( $partial['messages'] ?? array() );
		if ( $messages ) {
			$base['messages'] = array_merge( (array) ( $base['messages'] ?? array() ), $messages );
		}
		if ( ! empty( $partial['last_error'] ) ) {
			$base['last_error'] = (string) $partial['last_error'];
		}
		return $base;
	}

	/**
	 * Seconds elapsed since the current sync run started.
	 *
	 * @param array<string, mixed> $config Sync config.
	 */
	protected static function run_elapsed_seconds( array $config ): int {
		$started = (int) ( $config['run_started_at'] ?? 0 );
		if ( $started <= 0 ) {
			return 0;
		}
		return max( 0, time() - $started );
	}

	/**
	 * Whether the sync run has exceeded its fetch time budget.
	 *
	 * @param array<string, mixed> $config Sync config.
	 */
	protected static function run_time_budget_exceeded( array $config ): bool {
		$max_seconds = max( 60, (int) ( $config['max_fetch_seconds'] ?? 300 ) );
		return self::run_elapsed_seconds( $config ) >= $max_seconds;
	}

	/**
	 * Mark summary as partial when time budget is hit.
	 *
	 * @param array<string, mixed> $summary Summary (by reference).
	 */
	protected static function mark_partial_time_budget( array &$summary ): void {
		$summary['partial']    = true;
		$summary['last_error'] = __( 'Partial run — time budget reached. Fewer cities, lower max candidates per city, or increase max fetch seconds.', 'lnkup-marketplace' );
		$summary['messages'][] = $summary['last_error'];
	}

	/**
	 * Discover and import new events.
	 *
	 * @param array<string, mixed> $config Sync config.
	 * @param string               $market Optional single market slug filter.
	 * @return array<string, mixed>
	 */
	public static function run_discovery_batch( array $config, string $market = '' ): array {
		$summary = array(
			'discovered'            => 0,
			'imported'              => 0,
			'skipped'               => 0,
			'skipped_vendor_filter' => 0,
			'errors'                => 0,
			'partial'               => false,
			'messages'              => array(),
		);

		$provider = LNKup_External_Sources::get( 'eventbrite' );
		if ( ! $provider || ! $provider->supports_discovery() ) {
			$summary['last_error'] = __( 'Eventbrite discovery provider unavailable.', 'lnkup-marketplace' );
			++$summary['errors'];
			return $summary;
		}

		$markets = (array) ( $config['market_slugs'] ?? self::default_market_slugs() );
		if ( '' !== $market ) {
			$market = sanitize_key( $market );
			$markets = in_array( $market, $markets, true ) ? array( $market ) : array();
		}

		$max_new = (int) ( $config['max_new_per_run'] ?? 25 );
		$max_candidates = max( 5, (int) ( $config['max_candidates_per_market'] ?? 35 ) );
		$delay   = max( 1, (int) ( $config['fetch_delay_seconds'] ?? 1 ) );
		$imported = 0;

		foreach ( $markets as $market_slug ) {
			if ( $imported >= $max_new ) {
				break;
			}
			if ( self::run_time_budget_exceeded( $config ) ) {
				self::mark_partial_time_budget( $summary );
				break;
			}

			$urls = $provider->discover_event_urls( array( 'market_slug' => $market_slug ) );
			if ( is_wp_error( $urls ) ) {
				++$summary['errors'];
				$summary['messages'][] = $urls->get_error_message();
				$summary['last_error'] = $urls->get_error_message();
				continue;
			}

			$summary['discovered'] += count( $urls );

			$candidates_parsed = 0;

			foreach ( $urls as $url ) {
				if ( $imported >= $max_new ) {
					break;
				}
				if ( $candidates_parsed >= $max_candidates ) {
					break;
				}
				if ( self::run_time_budget_exceeded( $config ) ) {
					self::mark_partial_time_budget( $summary );
					break 2;
				}

				$dup = LNKup_External_Events::find_duplicate( $url );
				if ( $dup ) {
					++$summary['skipped'];
					continue;
				}

				sleep( $delay );
				$parsed = $provider->parse_url( $url );
				++$candidates_parsed;
				if ( is_wp_error( $parsed ) ) {
					++$summary['errors'];
					$summary['messages'][] = $parsed->get_error_message();
					$summary['last_error'] = $parsed->get_error_message();
					continue;
				}

				if ( ! self::passes_vendor_relevance_filter( $parsed, $config ) ) {
					++$summary['skipped_vendor_filter'];
					++$summary['skipped'];
					continue;
				}

				self::apply_vendor_fit_score( $parsed, $config );

				if ( ! self::passes_keyword_filter( $parsed, (array) ( $config['keywords'] ?? array() ) ) ) {
					++$summary['skipped'];
					continue;
				}

				if ( self::is_past_event( $parsed ) ) {
					++$summary['skipped'];
					continue;
				}

				unset( $parsed['_preview_confidence'] );
				$id = LNKup_External_Events::create( $parsed );
				if ( is_wp_error( $id ) ) {
					if ( 'duplicate' === $id->get_error_code() ) {
						++$summary['skipped'];
					} else {
						++$summary['errors'];
						$summary['messages'][] = $id->get_error_message();
						$summary['last_error'] = $id->get_error_message();
					}
					continue;
				}

				++$imported;
				++$summary['imported'];
				if ( class_exists( 'LNKup_External_Events_Timeline' ) ) {
					LNKup_External_Events_Timeline::log(
						(int) $id,
						'sync_discovered',
						0,
						array( 'market_slug' => $market_slug, 'source_url' => $url )
					);
				}
			}
		}

		return $summary;
	}

	/**
	 * Refresh stale imported events from source URLs.
	 *
	 * @param array<string, mixed> $config Sync config.
	 * @return array<string, mixed>
	 */
	public static function run_refresh_batch( array $config ): array {
		global $wpdb;

		$summary = array(
			'refreshed' => 0,
			'skipped'   => 0,
			'errors'    => 0,
			'partial'   => false,
			'messages'  => array(),
		);

		$table = LNKup_External_Events_Tables::events_table();
		$days  = max( 1, (int) ( $config['refresh_after_days'] ?? 7 ) );
		$limit = max( 1, (int) ( $config['max_refresh_per_run'] ?? 50 ) );
		$delay = max( 1, (int) ( $config['fetch_delay_seconds'] ?? 1 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_url, source_platform FROM {$table}
				WHERE claim_status = %s
				AND workflow_status IN (%s, %s)
				AND source_url != ''
				AND (last_synced_at IS NULL OR last_synced_at < %s)
				ORDER BY last_synced_at ASC
				LIMIT %d",
				LNKup_External_Events::CLAIM_UNCLAIMED,
				LNKup_External_Events::WORKFLOW_PUBLISHED,
				LNKup_External_Events::WORKFLOW_PENDING_REVIEW,
				$cutoff,
				$limit
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( self::run_time_budget_exceeded( $config ) ) {
				$summary['partial']    = true;
				$summary['last_error'] = __( 'Partial refresh — time budget reached.', 'lnkup-marketplace' );
				$summary['messages'][] = $summary['last_error'];
				break;
			}

			$url = (string) ( $row['source_url'] ?? '' );
			if ( '' === $url ) {
				++$summary['skipped'];
				continue;
			}

			$provider = LNKup_External_Sources::detect_from_url( $url );
			if ( ! $provider ) {
				++$summary['skipped'];
				continue;
			}

			sleep( $delay );
			$parsed = $provider->parse_url( $url );
			if ( is_wp_error( $parsed ) ) {
				++$summary['errors'];
				$summary['messages'][] = $parsed->get_error_message();
				$summary['last_error'] = $parsed->get_error_message();
				if ( class_exists( 'LNKup_External_Events_Timeline' ) ) {
					LNKup_External_Events_Timeline::log(
						(int) $row['id'],
						'sync_failed',
						0,
						array( 'message' => $parsed->get_error_message() )
					);
				}
				continue;
			}

			self::apply_vendor_fit_score( $parsed, $config );

			$result = LNKup_External_Events::merge_refetch_fields( (int) $row['id'], $parsed, true );
			if ( is_wp_error( $result ) ) {
				++$summary['errors'];
				$summary['messages'][] = $result->get_error_message();
				$summary['last_error'] = $result->get_error_message();
				continue;
			}

			++$summary['refreshed'];
			if ( class_exists( 'LNKup_External_Events_Timeline' ) ) {
				LNKup_External_Events_Timeline::log( (int) $row['id'], 'sync_refreshed', 0 );
			}
		}

		return $summary;
	}

	/**
	 * Optional keyword filter after parse.
	 *
	 * @param array<string, mixed> $parsed   Parsed event.
	 * @param array<int, string>   $keywords Keyword list.
	 */
	protected static function passes_keyword_filter( array $parsed, array $keywords ): bool {
		if ( empty( $keywords ) ) {
			return true;
		}
		$haystack = LNKup_External_Events::vendor_filter_haystack( $parsed );
		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $haystack, strtolower( $keyword ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Require at least one vendor-relevance term when filter enabled.
	 *
	 * @param array<string, mixed> $parsed Parsed event.
	 * @param array<string, mixed> $config Sync config.
	 */
	protected static function passes_vendor_relevance_filter( array $parsed, array $config ): bool {
		if ( empty( $config['vendor_filter_enabled'] ) ) {
			return true;
		}

		$terms  = (array) ( $config['vendor_keywords'] ?? LNKup_External_Events::default_vendor_sync_terms() );
		$result = LNKup_External_Events::score_vendor_relevance(
			(string) ( $parsed['title'] ?? '' ),
			(string) ( $parsed['description'] ?? $parsed['description_excerpt'] ?? '' ),
			(string) ( $parsed['event_type'] ?? '' ) . ' ' . (string) ( $parsed['_category_hint'] ?? '' ),
			$terms
		);

		return ! empty( $result['matched'] );
	}

	/**
	 * Set vendor_fit_score on parsed payload before import/refresh.
	 *
	 * @param array<string, mixed> $parsed Parsed event (by reference).
	 * @param array<string, mixed> $config Sync config.
	 */
	protected static function apply_vendor_fit_score( array &$parsed, array $config ): void {
		$terms  = (array) ( $config['vendor_keywords'] ?? LNKup_External_Events::default_vendor_sync_terms() );
		$result = LNKup_External_Events::score_vendor_relevance(
			(string) ( $parsed['title'] ?? '' ),
			(string) ( $parsed['description'] ?? $parsed['description_excerpt'] ?? '' ),
			(string) ( $parsed['event_type'] ?? '' ) . ' ' . (string) ( $parsed['_category_hint'] ?? '' ),
			$terms
		);
		$parsed['vendor_fit_score'] = (int) ( $result['score'] ?? 0 );
	}

	/**
	 * @param array<string, mixed> $parsed Parsed event fields.
	 */
	protected static function is_past_event( array $parsed ): bool {
		$start = (string) ( $parsed['start_datetime'] ?? '' );
		if ( '' === $start ) {
			return false;
		}
		$ts = strtotime( $start . ' UTC' );
		if ( ! $ts ) {
			return false;
		}
		return $ts < time();
	}
}
