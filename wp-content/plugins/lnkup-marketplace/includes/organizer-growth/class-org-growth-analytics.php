<?php
/**
 * Organizer acquisition analytics: cohorts, time to activation, conversion by dimension,
 * per-organizer productivity, cost per acquisition and at-risk organizers.
 *
 * Built only from recorded milestones, leads, activity, payments, platform-fee rows, spend
 * entries and earned commissions. Cost per acquisition is "unavailable" when a channel has no
 * cost records at all, and labelled "commissions only" when no marketing spend was entered.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Growth_Analytics {

	const MILESTONES  = array( 'first_event_published', 'first_application', 'first_paid_booking', 'activated', 'repeat_organizer' );
	const DIMENSIONS  = array( 'source', 'source_campaign', 'city', 'state', 'category', 'assigned_connector_id', 'assigned_user_id', 'event_frequency', 'affiliation' );
	const WINDOWS     = array( 30, 60, 90 );
	const AT_RISK_DAYS = 60;
	const CACHE_TTL   = 900;

	/**
	 * @param array<string, mixed> $args Raw filters.
	 * @return array{from:string,to:string,affiliation:string}
	 */
	public static function normalize( array $args ): array {
		$date = static fn( $v ): string => ( is_string( $v ) && '' !== $v && strtotime( $v ) ) ? gmdate( 'Y-m-d', (int) strtotime( $v ) ) : '';
		$aff  = (string) ( $args['affiliation'] ?? '' );
		return array(
			'from'        => $date( $args['from'] ?? '' ),
			'to'          => $date( $args['to'] ?? '' ),
			'affiliation' => in_array( $aff, array( 'house', 'independent' ), true ) ? $aff : '',
		);
	}

	private static function cached( string $name, array $args, callable $build ) {
		$key    = 'lnkup_org_an_' . md5( $name . wp_json_encode( $args ) . (int) get_option( LNKup_Org_Growth_Metrics::CACHE_VERSION_OPTION, 0 ) );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}
		$value = $build();
		set_transient( $key, $value, self::CACHE_TTL );
		return $value;
	}

	private static function aff_sql( string $affiliation ): string {
		return 'house' === $affiliation ? ' AND l.is_house_affiliated = 1' : ( 'independent' === $affiliation ? ' AND l.is_house_affiliated = 0' : '' );
	}

	private static function days( string $from, string $to ): int {
		return (int) max( 0, floor( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) );
	}

	/**
	 * @param int[] $values Values.
	 */
	public static function percentile( array $values, float $p ): ?float {
		if ( ! $values ) {
			return null;
		}
		sort( $values );
		$idx  = ( count( $values ) - 1 ) * $p;
		$low  = (int) floor( $idx );
		$high = (int) ceil( $idx );
		return round( $values[ $low ] + ( $values[ $high ] - $values[ $low ] ) * ( $idx - $low ), 1 );
	}

	// ------------------------------------------------------------------ Registered organizations.

	/**
	 * Registered (linked) organizations with their milestone dates.
	 *
	 * @param array<string, mixed> $f Normalized filters (registration date window).
	 * @return array<int, array<string, mixed>>
	 */
	public static function registrations( array $f ): array {
		global $wpdb;
		$leads = LNKup_Org_Leads::table();
		$ms    = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::MILESTONES );
		$cols  = array( "MAX(CASE WHEN m.milestone = 'registered' THEN m.achieved_at END) AS registered" );
		foreach ( self::MILESTONES as $key ) {
			$cols[] = "MAX(CASE WHEN m.milestone = '{$key}' AND m.status = 'active' THEN m.achieved_at END) AS {$key}";
		}
		$having = array( 'registered IS NOT NULL' );
		$values = array();
		if ( '' !== $f['from'] ) {
			$having[] = 'registered >= %s';
			$values[] = $f['from'] . ' 00:00:00';
		}
		if ( '' !== $f['to'] ) {
			$having[] = 'registered <= %s';
			$values[] = $f['to'] . ' 23:59:59';
		}
		$sql = 'SELECT l.id, l.name, l.source, l.city, l.state, l.is_house_affiliated, ' . implode( ', ', $cols ) . "
			FROM {$leads} l INNER JOIN {$ms} m ON m.lead_id = l.id
			WHERE l.merged_into_id = 0" . self::aff_sql( $f['affiliation'] ) . '
			GROUP BY l.id HAVING ' . implode( ' AND ', $having ) . ' LIMIT 20000';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (array) ( $values ? $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Monthly registration cohorts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function cohorts( array $args ): array {
		$f = self::normalize( $args );
		return self::cached(
			'cohorts',
			$f,
			static function () use ( $f ): array {
				$groups = array();
				foreach ( self::registrations( $f ) as $row ) {
					$groups[ substr( (string) $row['registered'], 0, 7 ) ][] = $row;
				}
				krsort( $groups );
				$out = array();
				foreach ( $groups as $month => $rows ) {
					$n    = count( $rows );
					$item = array(
						'cohort'     => $month,
						'registered' => $n,
						'milestones' => array(),
					);
					foreach ( self::MILESTONES as $key ) {
						$cell = array( 'overall' => 0 );
						foreach ( self::WINDOWS as $w ) {
							$cell[ 'd' . $w ] = 0;
						}
						foreach ( $rows as $row ) {
							if ( empty( $row[ $key ] ) ) {
								continue;
							}
							++$cell['overall'];
							$d = self::days( (string) $row['registered'], (string) $row[ $key ] );
							foreach ( self::WINDOWS as $w ) {
								if ( $d <= $w ) {
									++$cell[ 'd' . $w ];
								}
							}
						}
						$pct = array();
						foreach ( $cell as $k => $count ) {
							$pct[ $k ] = array(
								'count'   => $count,
								'percent' => round( $count / $n * 100, 1 ),
							);
						}
						$item['milestones'][ $key ] = $pct;
					}
					$out[] = $item;
				}
				return $out;
			}
		);
	}

	/**
	 * Median / p75 days from registration to each milestone.
	 *
	 * @return array<string, array{median:?float,p75:?float,sample:int}>
	 */
	public static function time_to_milestones( array $args ): array {
		$f = self::normalize( $args );
		return self::cached(
			'time_to',
			$f,
			static function () use ( $f ): array {
				$days = array_fill_keys( array( 'first_event_published', 'first_paid_booking', 'activated' ), array() );
				foreach ( self::registrations( $f ) as $row ) {
					foreach ( array_keys( $days ) as $key ) {
						if ( ! empty( $row[ $key ] ) ) {
							$days[ $key ][] = self::days( (string) $row['registered'], (string) $row[ $key ] );
						}
					}
				}
				$out = array();
				foreach ( $days as $key => $list ) {
					$out[ $key ] = array(
						'median' => self::percentile( $list, 0.5 ),
						'p75'    => self::percentile( $list, 0.75 ),
						'sample' => count( $list ),
					);
				}
				return $out;
			}
		);
	}

	// ------------------------------------------------------------------ Conversion by dimension.

	/**
	 * Funnel per value of a dimension (leads created in the window).
	 *
	 * @return array{dimension:string,rows:array<int,array<string,mixed>>}
	 */
	public static function by_dimension( string $dimension, array $args ): array {
		$dimension = in_array( $dimension, self::DIMENSIONS, true ) ? $dimension : 'source';
		$f         = self::normalize( $args );
		return self::cached(
			'dim_' . $dimension,
			$f,
			static function () use ( $dimension, $f ): array {
				global $wpdb;
				$leads    = LNKup_Org_Leads::table();
				$ms       = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::MILESTONES );
				$activity = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACTIVITY );
				$contacted_stages = "'" . implode( "','", array_slice( array_keys( LNKup_Org_Lead_Pipeline::STAGES ), LNKup_Org_Lead_Pipeline::rank( 'contacted' ) ) ) . "'";
				$col      = 'affiliation' === $dimension ? 'IF(l.is_house_affiliated = 1, \'house\', \'independent\')' : 'l.' . $dimension;
				$where    = array( 'l.merged_into_id = 0' );
				$values   = array();
				if ( '' !== $f['from'] ) {
					$where[]  = 'l.created_at >= %s';
					$values[] = $f['from'] . ' 00:00:00';
				}
				if ( '' !== $f['to'] ) {
					$where[]  = 'l.created_at <= %s';
					$values[] = $f['to'] . ' 23:59:59';
				}
				$flag = static fn( string $m ): string => "SUM(EXISTS (SELECT 1 FROM {$ms} m WHERE m.lead_id = l.id AND m.milestone = '{$m}' AND m.status = 'active'))";
				$sql  = "SELECT {$col} AS dim, COUNT(*) AS leads,
					SUM(l.stage IN ({$contacted_stages}) OR EXISTS (SELECT 1 FROM {$activity} a WHERE a.lead_id = l.id AND a.type = 'contact')) AS contacted,
					" . $flag( 'registered' ) . ' AS registered, ' . $flag( 'first_event_published' ) . ' AS published, ' . $flag( 'first_paid_booking' ) . ' AS paid, ' . $flag( 'activated' ) . ' AS activated, ' . $flag( 'repeat_organizer' ) . " AS repeat_org
					FROM {$leads} l WHERE " . implode( ' AND ', $where ) . self::aff_sql( $f['affiliation'] ) . ' GROUP BY dim ORDER BY leads DESC LIMIT 200';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$rows   = (array) ( $values ? $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ) );
				$labels = self::dimension_labels( $dimension );
				$out    = array();
				foreach ( $rows as $row ) {
					$value  = (string) $row['dim'];
					$counts = array_map( 'intval', array_intersect_key( $row, array_flip( array( 'leads', 'contacted', 'registered', 'published', 'paid', 'activated', 'repeat_org' ) ) ) );
					$rate   = static fn( int $a, int $b ): ?float => $b > 0 ? round( $a / $b * 100, 1 ) : null;
					$out[]  = array(
						'value'               => $value,
						'label'               => $labels[ $value ] ?? ( '' === $value || '0' === $value ? __( '(none)', 'lnkup-marketplace' ) : $value ),
						'leads'               => $counts['leads'],
						'contacted'           => $counts['contacted'],
						'registered'          => $counts['registered'],
						'published'           => $counts['published'],
						'paid'                => $counts['paid'],
						'activated'           => $counts['activated'],
						'repeat'              => $counts['repeat_org'],
						'lead_to_registered'  => $rate( $counts['registered'], $counts['leads'] ),
						'registered_to_paid'  => $rate( $counts['paid'], $counts['registered'] ),
						'registered_to_activated' => $rate( $counts['activated'], $counts['registered'] ),
					);
				}
				return array(
					'dimension' => $dimension,
					'rows'      => $out,
				);
			}
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function dimension_labels( string $dimension ): array {
		$out = array();
		if ( 'assigned_connector_id' === $dimension ) {
			foreach ( LNKup_Org_Growth::connectors() as $c ) {
				$out[ (string) $c['id'] ] = $c['name'];
			}
		} elseif ( 'assigned_user_id' === $dimension ) {
			foreach ( LNKup_Org_Growth::team_members() as $u ) {
				$out[ (string) $u['id'] ] = $u['name'];
			}
		} elseif ( 'affiliation' === $dimension ) {
			$out = array(
				'house'       => __( 'House-affiliated (Six Stars)', 'lnkup-marketplace' ),
				'independent' => __( 'Independent', 'lnkup-marketplace' ),
			);
		}
		return $out;
	}

	// ------------------------------------------------------------------ Productivity.

	/**
	 * Per-organizer output for organizations with at least one paid booking (capped at 300).
	 *
	 * @return array<string, mixed>
	 */
	public static function productivity( array $args ): array {
		$f = self::normalize( $args );
		return self::cached(
			'productivity',
			$f,
			static function () use ( $f ): array {
				global $wpdb;
				$per = array(
					'events'       => array(),
					'applications' => array(),
					'paid'         => array(),
					'net_fees'     => array(),
					'gross'        => array(),
				);
				$rows = array_slice( array_filter( self::registrations( $f ), static fn( $r ) => ! empty( $r['first_paid_booking'] ) ), 0, 300 );
				foreach ( $rows as $row ) {
					$users = LNKup_Org_Leads::linked_user_ids( (int) $row['id'] );
					if ( ! $users ) {
						continue;
					}
					$in       = implode( ',', array_map( 'intval', $users ) );
					$paid     = LNKup_Org_Lead_Lifecycle::paid_payments( $users );
					$pids     = array_column( $paid, 'id' );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$apps     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_organizer_user_id' AND o.meta_value IN ({$in}) WHERE p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash','auto-draft')" );
					$gross    = 0.0;
					foreach ( $pids as $pid ) {
						$gross += (float) get_post_meta( (int) $pid, '_lnkup_total_amount', true );
					}
					$per['events'][]       = count( LNKup_Org_Lead_Lifecycle::live_events( $users ) );
					$per['applications'][] = $apps;
					$per['paid'][]         = count( $paid );
					$per['net_fees'][]     = $pids && class_exists( 'LNKup_Platform_Fees' ) ? LNKup_Platform_Fees::sum_net_platform_fees( '', $pids ) : 0.0;
					$per['gross'][]        = round( $gross, 2 );
				}
				$out = array( 'organizations' => count( $per['events'] ) );
				foreach ( $per as $key => $list ) {
					$out[ $key ] = array(
						'average' => $list ? round( array_sum( $list ) / count( $list ), 2 ) : null,
						'median'  => self::percentile( $list, 0.5 ),
						'total'   => round( (float) array_sum( $list ), 2 ),
					);
				}
				return $out;
			}
		);
	}

	// ------------------------------------------------------------------ Cost per acquisition.

	/**
	 * @return array{channels:array<int,array<string,mixed>>,total:array<string,mixed>}
	 */
	public static function cac( array $args ): array {
		$f = self::normalize( $args );
		return self::cached(
			'cac',
			$f,
			static function () use ( $f ): array {
				$spend       = LNKup_Org_Growth_Spend::spend_by_channel( $f );
				$commissions = LNKup_Org_Growth_Spend::commission_costs( $f );
				$registered  = array();
				$activated   = array();
				foreach ( self::registrations( $f ) as $row ) {
					$ch                = '' === (string) $row['source'] ? 'unknown' : (string) $row['source'];
					$registered[ $ch ] = ( $registered[ $ch ] ?? 0 ) + 1;
					if ( ! empty( $row['activated'] ) ) {
						$activated[ $ch ] = ( $activated[ $ch ] ?? 0 ) + 1;
					}
				}
				$channels = array_unique( array_merge( array_keys( $spend ), array_keys( $commissions['by_channel'] ), array_keys( $registered ) ) );
				sort( $channels );
				$rows = array();
				$tot  = array( 'spend' => 0, 'commissions' => 0, 'registered' => 0, 'activated' => 0 );
				foreach ( $channels as $ch ) {
					$row = self::cac_row( $ch, (int) ( $spend[ $ch ] ?? 0 ), (int) ( $commissions['by_channel'][ $ch ] ?? 0 ), (int) ( $registered[ $ch ] ?? 0 ), (int) ( $activated[ $ch ] ?? 0 ) );
					$rows[] = $row;
					$tot['spend']       += $row['spend_cents'];
					$tot['commissions'] += $row['commission_cents'];
					$tot['registered']  += $row['registered'];
					$tot['activated']   += $row['activated'];
				}
				return array(
					'channels' => $rows,
					'total'    => self::cac_row( 'all', $tot['spend'], $tot['commissions'], $tot['registered'], $tot['activated'] ),
				);
			}
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function cac_row( string $channel, int $spend, int $commissions, int $registered, int $activated ): array {
		$cost   = $spend + $commissions;
		$status = 0 === $cost ? 'unavailable' : ( 0 === $spend ? 'commissions_only' : 'complete' );
		$per    = static fn( int $n ): ?int => ( 'unavailable' === $status || $n <= 0 ) ? null : (int) round( $cost / $n );
		return array(
			'channel'                  => $channel,
			'spend_cents'              => $spend,
			'commission_cents'         => $commissions,
			'cost_cents'               => $cost,
			'registered'               => $registered,
			'activated'                => $activated,
			'cost_per_registered_cents' => $per( $registered ),
			'cost_per_activated_cents' => $per( $activated ),
			'status'                   => $status,
			'note'                     => 'unavailable' === $status
				? __( 'No spend or commission records for this channel.', 'lnkup-marketplace' )
				: ( 'commissions_only' === $status ? __( 'Commissions only — no marketing spend entered.', 'lnkup-marketplace' ) : '' ),
		);
	}

	// ------------------------------------------------------------------ At-risk organizers.

	/**
	 * Activated organizations with nothing upcoming and no recent paid booking.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function at_risk( array $args ): array {
		$f = self::normalize( $args );
		return self::cached(
			'at_risk',
			$f,
			static function () use ( $f ): array {
				global $wpdb;
				$cutoff = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - self::AT_RISK_DAYS * DAY_IN_SECONDS );
				$today  = substr( LNKup_Org_Growth::now(), 0, 10 );
				$out    = array();
				foreach ( array_slice( self::registrations( array( 'from' => '', 'to' => '', 'affiliation' => $f['affiliation'] ) ), 0, 2000 ) as $row ) {
					if ( empty( $row['activated'] ) ) {
						continue;
					}
					$users = LNKup_Org_Leads::linked_user_ids( (int) $row['id'] );
					if ( ! $users ) {
						continue;
					}
					$in = implode( ',', array_map( 'intval', $users ) );
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$upcoming = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->posts} p
							INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_organizer_user_id' AND o.meta_value IN ({$in})
							LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_event_status'
							LEFT JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = '_lnkup_event_date'
							WHERE p.post_type = 'lnkup_event' AND p.post_status = 'publish' AND ( s.meta_value = 'live' OR d.meta_value >= %s )",
							$today
						)
					);
					$paid = LNKup_Org_Lead_Lifecycle::paid_payments( $users );
					$last_paid = $paid ? (string) end( $paid )['date'] : '';
					$last_event = (string) $wpdb->get_var( "SELECT MAX(d.meta_value) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_organizer_user_id' AND o.meta_value IN ({$in}) INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = '_lnkup_event_date' WHERE p.post_type = 'lnkup_event'" );
					$open_task = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Org_Lead_Tasks::table() . " WHERE lead_id = %d AND status = 'open'", (int) $row['id'] ) );
					// phpcs:enable
					if ( $upcoming > 0 || ( '' !== $last_paid && $last_paid >= $cutoff ) ) {
						continue;
					}
					$lead  = LNKup_Org_Leads::get_row( (int) $row['id'] );
					$owner = $lead && (int) $lead['assigned_user_id'] ? get_userdata( (int) $lead['assigned_user_id'] ) : false;
					$out[] = array(
						'lead_id'         => (int) $row['id'],
						'name'            => (string) $row['name'],
						'city'            => trim( (string) $row['city'] . ( '' !== (string) $row['state'] ? ', ' . $row['state'] : '' ), ', ' ),
						'last_paid_at'    => $last_paid,
						'last_event_date' => $last_event,
						'owner'           => $owner ? (string) $owner->display_name : '',
						'has_open_task'   => $open_task > 0,
					);
				}
				return $out;
			}
		);
	}

	// ------------------------------------------------------------------ Summary + export.

	/**
	 * @return array<string, mixed>
	 */
	public static function summary( array $args, string $dimension = 'source' ): array {
		return array(
			'filters'      => self::normalize( $args ),
			'time_to'      => self::time_to_milestones( $args ),
			'cohorts'      => self::cohorts( $args ),
			'conversion'   => self::by_dimension( $dimension, $args ),
			'productivity' => self::productivity( $args ),
			'cac'          => self::cac( $args ),
			'dimensions'   => self::DIMENSIONS,
		);
	}

	public static function export_csv( string $section, array $args, string $dimension = 'source' ): string {
		$handle = fopen( 'php://temp', 'r+' );
		if ( ! $handle ) {
			return '';
		}
		$put = static function ( array $cells ) use ( $handle ): void {
			fputcsv( $handle, array_map( static fn( $v ) => preg_match( '/^[=+\-@\t\r]/', (string) $v ) ? "'" . $v : (string) $v, $cells ) );
		};
		if ( 'cohorts' === $section ) {
			$put( array( 'cohort', 'registered', 'milestone', 'within_30d', 'within_60d', 'within_90d', 'overall' ) );
			foreach ( self::cohorts( $args ) as $c ) {
				foreach ( $c['milestones'] as $key => $m ) {
					$put( array( $c['cohort'], $c['registered'], $key, $m['d30']['percent'], $m['d60']['percent'], $m['d90']['percent'], $m['overall']['percent'] ) );
				}
			}
		} elseif ( 'cac' === $section ) {
			$put( array( 'channel', 'spend_usd', 'commissions_usd', 'registered', 'activated', 'cost_per_registered_usd', 'cost_per_activated_usd', 'status' ) );
			$cac = self::cac( $args );
			foreach ( array_merge( $cac['channels'], array( $cac['total'] ) ) as $r ) {
				$money = static fn( $c ) => null === $c ? '' : number_format( $c / 100, 2, '.', '' );
				$put( array( $r['channel'], $money( $r['spend_cents'] ), $money( $r['commission_cents'] ), $r['registered'], $r['activated'], $money( $r['cost_per_registered_cents'] ), $money( $r['cost_per_activated_cents'] ), $r['status'] ) );
			}
		} elseif ( 'at_risk' === $section ) {
			$put( array( 'lead_id', 'name', 'city', 'last_paid_at', 'last_event_date', 'owner', 'has_open_task' ) );
			foreach ( self::at_risk( $args ) as $r ) {
				$put( array( $r['lead_id'], $r['name'], $r['city'], $r['last_paid_at'], $r['last_event_date'], $r['owner'], $r['has_open_task'] ? 'yes' : 'no' ) );
			}
		} else {
			$put( array( 'dimension', 'value', 'leads', 'contacted', 'registered', 'published', 'paid', 'activated', 'repeat', 'lead_to_registered_pct', 'registered_to_paid_pct', 'registered_to_activated_pct' ) );
			$d = self::by_dimension( $dimension, $args );
			foreach ( $d['rows'] as $r ) {
				$put( array( $d['dimension'], $r['label'], $r['leads'], $r['contacted'], $r['registered'], $r['published'], $r['paid'], $r['activated'], $r['repeat'], $r['lead_to_registered'], $r['registered_to_paid'], $r['registered_to_activated'] ) );
			}
		}
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );
		return $csv;
	}
}
