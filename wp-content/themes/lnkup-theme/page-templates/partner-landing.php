<?php
/**
 * Partner landing page (/with/{slug}/), rendered from LNKup_Partner_Landings::current().
 *
 * Co-branded with an approved partner; shows live opportunities only.
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

$ctx = class_exists( 'LNKup_Partner_Landings' ) ? LNKup_Partner_Landings::current() : null;
if ( ! $ctx ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}
$page     = $ctx['page'];
$partner  = $ctx['partner'];
$cards    = (array) $ctx['cards'];
$vendor   = 'vendor' === $page['audience'];
$where    = $ctx['market'] ? (string) $ctx['market']['display_name'] : '';
$title    = '' !== $page['headline'] ? (string) $page['headline'] : ( $vendor
	/* translators: %s: partner */
	? sprintf( __( 'Vendor opportunities for the %s community', 'lnkup-theme' ), $partner['name'] )
	/* translators: %s: partner */
	: sprintf( __( 'List your event with LNKup, recommended by %s', 'lnkup-theme' ), $partner['name'] ) );
$lead     = '' !== $page['intro'] ? (string) $page['intro'] : ( $vendor
	? __( 'Find festivals, markets and pop-ups booking vendors. Applying is free and you only pay when an organizer approves you.', 'lnkup-theme' )
	: __( 'Publish vendor spots for free, review applications in one place and collect booth fees online.', 'lnkup-theme' ) );
$steps    = lnkup_public_steps();

get_header();
?>
<div class="lnkup-site lp-site" data-testid="lnkup-partner-landing" data-partner="<?php echo esc_attr( (string) $partner['id'] ); ?>">
	<?php get_template_part( 'template-parts/landing/site-header' ); ?>
	<main id="lp-main" class="lp-main">
		<?php
		get_template_part(
			'template-parts/public/landing-hero',
			null,
			array(
				/* translators: %s: partner */
				'eyebrow' => sprintf( __( '%s × LNKup', 'lnkup-theme' ), $partner['name'] ),
				'title'   => $title,
				'lead'    => $lead,
				'image'   => $vendor ? 'images/marketing/hero-connector.jpg' : 'images/marketing/hero-market.jpg',
				'ctas'    => array(
					array( $vendor ? __( 'Create a free vendor profile', 'lnkup-theme' ) : __( 'Create a free organizer account', 'lnkup-theme' ), (string) $ctx['signup_url'], 'lp-btn--lime', 'lnkup-partner-signup' ),
					array( __( 'Browse events', 'lnkup-theme' ), (string) $ctx['feed_url'], 'lp-btn--on-dark', 'lnkup-partner-browse' ),
				),
				'note'    => __( 'Free to join. No subscription.', 'lnkup-theme' ),
				'testid'  => 'lnkup-partner-hero',
			)
		);
		?>
		<?php if ( $vendor ) : ?>
			<section class="lp-section" aria-labelledby="lnkup-partner-live-title" data-testid="lnkup-partner-live">
				<div class="lp-container">
					<?php
					lnkup_public_section_head(
						array(
							'eyebrow'      => __( 'Live on LNKup', 'lnkup-theme' ),
							/* translators: %s: market */
							'title'        => '' !== $where ? sprintf( __( 'Events booking vendors in %s', 'lnkup-theme' ), $where ) : __( 'Events booking vendors now', 'lnkup-theme' ),
							'id'           => 'lnkup-partner-live-title',
							'action_label' => $cards ? __( 'See all events', 'lnkup-theme' ) : '',
							'action_url'   => (string) $ctx['feed_url'],
						)
					);
					?>
					<?php if ( $cards ) : ?>
						<div class="lp-rail" style="--lp-rail-cols:3">
							<?php foreach ( $cards as $card ) : ?>
								<?php get_template_part( 'template-parts/public/event-card', null, array( 'card' => $card, 'testid' => 'lnkup-partner-card' ) ); ?>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<div class="lp-empty">
							<h3><?php esc_html_e( 'New events open every week', 'lnkup-theme' ); ?></h3>
							<p><?php esc_html_e( 'Create your profile now so you can apply as soon as matching events open.', 'lnkup-theme' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
			</section>
		<?php endif; ?>
		<section class="lp-section lp-section--ink" aria-labelledby="lnkup-partner-steps-title">
			<div class="lp-container">
				<?php lnkup_public_section_head( array( 'eyebrow' => __( 'How it works', 'lnkup-theme' ), 'title' => $vendor ? __( 'From profile to paid booth', 'lnkup-theme' ) : __( 'From listing to full vendor lineup', 'lnkup-theme' ), 'id' => 'lnkup-partner-steps-title' ) ); ?>
				<ol class="lp-steps">
					<?php foreach ( $steps[ $vendor ? 'vendor' : 'organizer' ] as $step ) : ?>
						<li class="lp-step"><h3><?php echo esc_html( $step[0] ); ?></h3><p><?php echo esc_html( $step[1] ); ?></p></li>
					<?php endforeach; ?>
				</ol>
			</div>
		</section>
		<?php get_template_part( 'template-parts/public/final-cta', null, array( 'audience' => $vendor ? 'vendor' : 'organizer' ) ); ?>
	</main>
	<?php get_template_part( 'template-parts/landing/site-footer' ); ?>
</div>
<?php
get_footer();
