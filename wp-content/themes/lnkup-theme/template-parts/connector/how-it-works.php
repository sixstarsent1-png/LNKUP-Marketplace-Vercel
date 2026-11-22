<?php
/**
 * City Connector how it works.
 *
 * @package LNKup_Theme
 */

$lnkup_terms = class_exists( 'LNKup_Partner_Public' ) ? LNKup_Partner_Public::terms_copy( 'city_connector' ) : array( 'lines' => array() );
$lnkup_earn  = $lnkup_terms['lines'][0] ?? __( 'Commission is earned on verified bookings under the published program terms.', 'lnkup-theme' );

$steps = array(
	array(
		'num'   => '01',
		'title' => __( 'Refer people you already know', 'lnkup-theme' ),
		'body'  => __( 'Share LNKup with vendors and organizers in your city — the people you already see at markets and events.', 'lnkup-theme' ),
	),
	array(
		'num'   => '02',
		'title' => __( 'They join LNKup', 'lnkup-theme' ),
		'body'  => __( 'Vendors create profiles and apply. Organizers post booth opportunities. You stay the local introduction.', 'lnkup-theme' ),
	),
	array(
		'num'   => '03',
		'title' => __( 'You grow the market', 'lnkup-theme' ),
		'body'  => __( 'Every connection makes your city denser — more events, more vendors, more reason for the next organizer to list.', 'lnkup-theme' ),
	),
	array(
		'num'   => '04',
		'title' => __( 'You earn on the booking', 'lnkup-theme' ),
		'body'  => $lnkup_earn,
	),
);
?>
<section id="how-it-works" class="lnkup-section lnkup-band lnkup-band--dark lnkup-reveal">
	<div class="lnkup-container">
		<?php lnkup_theme_section_kicker( '01 / CONNECTOR', __( 'How it works', 'lnkup-theme' ) ); ?>
		<h2 class="lnkup-heading lnkup-heading--section"><?php esc_html_e( 'From introduction to a living market', 'lnkup-theme' ); ?></h2>
		<ol class="lnkup-process">
			<?php foreach ( $steps as $step ) : ?>
				<li class="lnkup-process__row">
					<span class="lnkup-process__num"><?php echo esc_html( $step['num'] ); ?></span>
					<div>
						<h3 class="lnkup-process__title"><?php echo esc_html( $step['title'] ); ?></h3>
						<p class="lnkup-process__body"><?php echo esc_html( $step['body'] ); ?></p>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>
	</div>
</section>
