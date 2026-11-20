<?php
/**
 * Print seeded application/payment state for quick runtime verification.
 *
 * Usage: npx wp-env run cli wp eval-file /var/www/html/wp-content/lnkup-e2e/inspect.php
 */

$apps = get_posts(
	array(
		'post_type'      => 'lnkup_application',
		'post_status'    => 'any',
		'posts_per_page' => 50,
	)
);
foreach ( $apps as $app ) {
	$payment = LNKup_Payments::get_for_application( $app->ID );
	$opp     = (int) get_post_meta( $app->ID, '_lnkup_opportunity_id', true );
	printf(
		"app %d opp %d status=%s payment=%s pay_status=%s total=%s spots_remaining=%s\n",
		$app->ID,
		$opp,
		get_post_meta( $app->ID, '_lnkup_application_status', true ),
		$payment ? $payment->ID : '-',
		$payment ? get_post_meta( $payment->ID, '_lnkup_payment_status', true ) : '-',
		$payment ? get_post_meta( $payment->ID, '_lnkup_total_amount', true ) : '-',
		get_post_meta( $opp, '_lnkup_spots_remaining', true )
	);
}
$errors = file_exists( WP_CONTENT_DIR . '/debug.log' ) ? array_slice( file( WP_CONTENT_DIR . '/debug.log' ), -15 ) : array();
echo "--- debug.log tail ---\n" . implode( '', $errors );
