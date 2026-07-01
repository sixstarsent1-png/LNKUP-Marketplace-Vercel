<?php
/**
 * Vendor business goals.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Growth_Goals {

	public static function list_for_vendor( $user_id = 0 ) {
		global $wpdb;
		$user_id = $user_id ?: get_current_user_id();
		$table   = LNKup_Growth_Tables::goals_table();
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE vendor_user_id = %d ORDER BY status ASC, deadline ASC, id DESC",
				$user_id
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format' ), (array) $rows );
	}

	public static function create( $user_id, $data ) {
		global $wpdb;
		$table = LNKup_Growth_Tables::goals_table();
		$type  = sanitize_key( $data['goal_type'] ?? 'custom' );
		$title = sanitize_text_field( $data['title'] ?? self::default_title( $type ) );
		$wpdb->insert(
			$table,
			array(
				'vendor_user_id' => $user_id,
				'goal_type'      => $type,
				'title'          => $title,
				'target_value'   => (float) ( $data['target_value'] ?? 0 ),
				'current_value'  => (float) ( $data['current_value'] ?? 0 ),
				'unit'           => sanitize_text_field( $data['unit'] ?? '' ),
				'deadline'       => ! empty( $data['deadline'] ) ? sanitize_text_field( $data['deadline'] ) : null,
				'status'         => 'active',
				'created_at'     => current_time( 'mysql' ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s' )
		);
		$id = (int) $wpdb->insert_id;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return self::format( $row ?: array() );
	}

	public static function update( $goal_id, $user_id, $data ) {
		if ( ! self::owns_goal( $goal_id, $user_id ) ) {
			return false;
		}
		global $wpdb;
		$table  = LNKup_Growth_Tables::goals_table();
		$update = array( 'updated_at' => current_time( 'mysql' ) );
		if ( isset( $data['current_value'] ) ) {
			$update['current_value'] = (float) $data['current_value'];
		}
		if ( isset( $data['target_value'] ) ) {
			$update['target_value'] = (float) $data['target_value'];
		}
		if ( isset( $data['status'] ) ) {
			$update['status'] = sanitize_key( $data['status'] );
		}
		$wpdb->update( $table, $update, array( 'id' => $goal_id ), null, array( '%d' ) );
		self::sync_progress_from_metrics( $user_id );
		return self::get( $goal_id, $user_id );
	}

	public static function get( $goal_id, $user_id ) {
		if ( ! self::owns_goal( $goal_id, $user_id ) ) {
			return null;
		}
		global $wpdb;
		$table = LNKup_Growth_Tables::goals_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $goal_id ), ARRAY_A );
		return $row ? self::format( $row ) : null;
	}

	public static function owns_goal( $goal_id, $user_id ) {
		global $wpdb;
		$table = LNKup_Growth_Tables::goals_table();
		$owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT vendor_user_id FROM {$table} WHERE id = %d", $goal_id ) );
		return $owner === (int) $user_id || LNKup_Capabilities::user_has_manage_all( $user_id );
	}

	public static function sync_progress_from_metrics( $user_id ) {
		$kpis = LNKup_Growth_Kpis::for_vendor( $user_id );
		global $wpdb;
		$table = LNKup_Growth_Tables::goals_table();
		$goals = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE vendor_user_id = %d AND status = 'active'", $user_id ), ARRAY_A );
		foreach ( (array) $goals as $goal ) {
			$current = self::metric_for_goal_type( $goal['goal_type'], $kpis );
			$status  = 'active';
			if ( $current >= (float) $goal['target_value'] && (float) $goal['target_value'] > 0 ) {
				$status = 'completed';
			}
			$wpdb->update(
				$table,
				array(
					'current_value' => $current,
					'status'        => $status,
					'updated_at'    => current_time( 'mysql' ),
				),
				array( 'id' => (int) $goal['id'] )
			);
		}
	}

	private static function metric_for_goal_type( $type, $kpis ) {
		$map = array(
			'events'           => $kpis['completed_events'] ?? 0,
			'revenue'          => $kpis['lifetime_revenue'] ?? 0,
			'cities'           => $kpis['cities_served'] ?? 0,
			'festivals'        => $kpis['completed_events'] ?? 0,
			'vendor_score'     => $kpis['vendor_score'] ?? 0,
			'profile_strength' => $kpis['profile_strength'] ?? 0,
		);
		return (float) ( $map[ $type ] ?? 0 );
	}

	private static function default_title( $type ) {
		$titles = array(
			'events'           => __( 'Complete events', 'lnkup-marketplace' ),
			'revenue'          => __( 'Reach revenue target', 'lnkup-marketplace' ),
			'cities'           => __( 'Expand into new cities', 'lnkup-marketplace' ),
			'festivals'        => __( 'Book festivals', 'lnkup-marketplace' ),
			'vendor_score'     => __( 'Improve vendor score', 'lnkup-marketplace' ),
			'profile_strength' => __( 'Complete profile', 'lnkup-marketplace' ),
		);
		return $titles[ $type ] ?? __( 'Business goal', 'lnkup-marketplace' );
	}

	public static function format( $row ) {
		if ( empty( $row['id'] ) ) {
			return array();
		}
		$target  = (float) ( $row['target_value'] ?? 0 );
		$current = (float) ( $row['current_value'] ?? 0 );
		$pct     = $target > 0 ? min( 100, round( ( $current / $target ) * 100 ) ) : 0;
		return array(
			'id'             => (int) $row['id'],
			'goal_type'      => $row['goal_type'],
			'title'          => $row['title'],
			'target_value'   => $target,
			'current_value'  => $current,
			'progress_pct'   => $pct,
			'unit'           => $row['unit'],
			'deadline'       => $row['deadline'],
			'status'         => $row['status'],
		);
	}

	public static function seed_defaults( $user_id ) {
		$existing = self::list_for_vendor( $user_id );
		if ( ! empty( $existing ) ) {
			return;
		}
		self::create( $user_id, array( 'goal_type' => 'events', 'target_value' => 10, 'unit' => 'events' ) );
		self::create( $user_id, array( 'goal_type' => 'profile_strength', 'target_value' => 100, 'unit' => '%' ) );
	}
}
