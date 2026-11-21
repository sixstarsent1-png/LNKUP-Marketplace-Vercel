<?php
/**
 * Shared helpers for financial integration tests run via `wp eval-file` on wp-env.
 *
 * Every test builds its own fixtures with a unique prefix and removes them on exit,
 * so tests never touch seeded marketplace data.
 *
 * @package LNKup_Marketplace
 */

$GLOBALS['lnkup_test_failures'] = 0;
$GLOBALS['lnkup_test_cleanup']  = array();

/**
 * Record one assertion result and print it.
 *
 * @param bool   $condition Assertion.
 * @param string $label     Description.
 */
function lnkup_t_assert( $condition, string $label ): void {
	if ( $condition ) {
		echo "  ok   {$label}\n";
		return;
	}
	$GLOBALS['lnkup_test_failures']++;
	echo "  FAIL {$label}\n";
}

/**
 * Assert two values are identical, printing both on failure.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $label    Description.
 */
function lnkup_t_same( $expected, $actual, string $label ): void {
	$ok = $expected === $actual;
	lnkup_t_assert( $ok, $label . ( $ok ? '' : ' (expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual ) . ')' ) );
}

/**
 * Create a throwaway user with a role; removed at the end of the run.
 *
 * @param string $role Role slug.
 */
function lnkup_t_user( string $role ): int {
	$login = 'fin_t_' . $role . '_' . wp_generate_password( 6, false );
	$id    = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $login . '@example.test',
			'user_pass'  => wp_generate_password(),
			'role'       => $role,
		)
	);
	$GLOBALS['lnkup_test_cleanup'][] = array( 'user', (int) $id );
	return (int) $id;
}

/**
 * Create a lnkup_payment post with meta; removed at the end of the run.
 *
 * @param array $meta Meta key (without _lnkup_ prefix) => value.
 */
function lnkup_t_payment( array $meta ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => 'lnkup_payment',
			'post_status' => 'publish',
			'post_title'  => 'Financial test payment',
		)
	);
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, '_lnkup_' . $key, $value );
	}
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $id );
	return (int) $id;
}

/**
 * Insert a raw organizer wallet ledger row (bypasses wallet logic on purpose).
 *
 * @param int   $user_id Organizer.
 * @param array $row     Column overrides.
 */
function lnkup_t_ledger_row( int $user_id, array $row ): int {
	global $wpdb;
	$wallet = LNKup_Organizer_Wallet::get_or_create( $user_id );
	$wpdb->insert(
		LNKup_Treasury_Tables::organizer_wallet_entries_table(),
		array_merge(
			array(
				'wallet_id'       => (int) $wallet['id'],
				'user_id'         => $user_id,
				'entry_type'      => 'credit',
				'amount'          => 0,
				'balance_after'   => 0,
				'source_type'     => 'payment',
				'source_id'       => 0,
				'payment_post_id' => 0,
				'note'            => 'test',
				'created_at'      => current_time( 'mysql' ),
			),
			$row
		)
	);
	return (int) $wpdb->insert_id;
}

/**
 * Checksum of every financial row belonging to an organizer, for read-only assertions.
 *
 * @param int   $user_id     Organizer.
 * @param int[] $payment_ids Payments whose meta must not change.
 */
function lnkup_t_financial_fingerprint( int $user_id, array $payment_ids ): string {
	global $wpdb;
	$parts   = array();
	$parts[] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Treasury_Tables::organizer_wallets_table() . ' WHERE user_id = %d', $user_id ), ARRAY_A );
	$parts[] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Treasury_Tables::organizer_wallet_entries_table() . ' WHERE user_id = %d ORDER BY id', $user_id ), ARRAY_A );
	foreach ( $payment_ids as $pid ) {
		$parts[] = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $pid ), ARRAY_A );
	}
	return md5( (string) wp_json_encode( $parts ) );
}

/**
 * Remove fixtures and exit non-zero on failure.
 */
function lnkup_t_finish(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( array_reverse( $GLOBALS['lnkup_test_cleanup'] ) as $item ) {
		list( $type, $id ) = $item;
		if ( 'post' === $type ) {
			wp_delete_post( $id, true );
		} elseif ( 'user' === $type ) {
			$wpdb->delete( LNKup_Financial_Tables::audit_table(), array( 'entity_type' => 'wallet', 'entity_id' => $id ) );
			$wpdb->delete( LNKup_Treasury_Tables::organizer_wallet_entries_table(), array( 'user_id' => $id ) );
			$wpdb->delete( LNKup_Treasury_Tables::organizer_wallets_table(), array( 'user_id' => $id ) );
			wp_delete_user( $id );
		}
	}
	$failures = (int) $GLOBALS['lnkup_test_failures'];
	echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASSED\n";
	if ( $failures ) {
		exit( 1 );
	}
}
