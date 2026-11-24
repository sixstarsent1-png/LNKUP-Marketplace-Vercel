<?php
/**
 * Closing call to action. $args: audience (all|organizer|vendor), return_url (validated same-site URL).
 *
 * @package LNKup_Theme
 */

$audience = isset( $args['audience'] ) ? (string) $args['audience'] : 'all';
$return   = isset( $args['return_url'] ) ? (string) $args['return_url'] : '';
$account  = lnkup_public_account();
$copy     = array(
	'all'       => array( (string) lnkup_public_setting( 'cta_title', __( 'Your next event starts here', 'lnkup-theme' ) ), __( 'Free to join for vendors and organizers. Set up in minutes.', 'lnkup-theme' ) ),
	'organizer' => array( __( 'Fill your vendor booths with less back-and-forth', 'lnkup-theme' ), __( 'List your event free. You only pay when vendors pay you.', 'lnkup-theme' ) ),
	'vendor'    => array( __( 'Find your next event this week', 'lnkup-theme' ), __( 'Create your free profile, apply in minutes and pay only when you are approved.', 'lnkup-theme' ) ),
);
$text = $copy[ $audience ] ?? $copy['all'];
?>
<section class="lp-section lp-section--tight" aria-labelledby="lp-final-title" data-testid="lp-final-cta">
	<div class="lp-container">
		<div class="lp-cta">
			<div>
				<h2 class="lp-h2" id="lp-final-title"><?php echo esc_html( $text[0] ); ?></h2>
				<p class="lp-lead"><?php echo esc_html( $text[1] ); ?></p>
			</div>
			<div class="lp-actions">
				<?php if ( $account['url'] ) : ?>
					<a class="lp-btn lp-btn--lime lp-btn--lg" href="<?php echo esc_url( $account['url'] ); ?>"><?php esc_html_e( 'Go to your dashboard', 'lnkup-theme' ); ?></a>
				<?php else : ?>
					<?php if ( 'organizer' !== $audience ) : ?>
						<a class="lp-btn lp-btn--lime lp-btn--lg" href="<?php echo esc_url( lnkup_public_signup_url( 'vendor', $return ) ); ?>"><?php esc_html_e( 'Join as a vendor', 'lnkup-theme' ); ?></a>
					<?php endif; ?>
					<?php if ( 'vendor' !== $audience ) : ?>
						<a class="lp-btn <?php echo 'organizer' === $audience ? 'lp-btn--lime' : 'lp-btn--on-dark'; ?> lp-btn--lg" href="<?php echo esc_url( lnkup_public_signup_url( 'organizer' ) ); ?>"><?php esc_html_e( 'List your event', 'lnkup-theme' ); ?></a>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>
