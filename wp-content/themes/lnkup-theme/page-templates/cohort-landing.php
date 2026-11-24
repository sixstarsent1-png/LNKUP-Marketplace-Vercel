<?php
/**
 * Organizer cohort landing (/cohort/{slug}/), content from LNKup_Cohort_Landings.
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

$page = class_exists( 'LNKup_Cohort_Landings' ) ? LNKup_Cohort_Landings::current() : null;
if ( ! $page ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$joined = ! empty( $_GET['joined'] );
$error  = isset( $_GET['cohort_error'] ) ? sanitize_key( wp_unslash( (string) $_GET['cohort_error'] ) ) : '';
// phpcs:enable
$errors = array(
	'lnkup_cohort_invalid' => __( 'Add your name, organization, a valid email and confirm we may contact you.', 'lnkup-theme' ),
	'lnkup_rate_limited'   => __( 'Too many requests. Please try again tomorrow.', 'lnkup-theme' ),
	'expired'              => __( 'The form expired. Please send it again.', 'lnkup-theme' ),
);

get_header();
?>
<div class="lnkup-site lp-site" data-testid="lnkup-cohort-landing">
	<?php get_template_part( 'template-parts/landing/site-header' ); ?>
	<main id="lp-main" class="lp-main">
		<?php
		get_template_part(
			'template-parts/public/landing-hero',
			null,
			array(
				'eyebrow' => __( 'Organizer cohort', 'lnkup-theme' ),
				'title'   => (string) $page['title'],
				'lead'    => (string) $page['intro'],
				'image'   => 'images/marketing/hero-market.jpg',
				'ctas'    => array( array( __( 'Request a spot', 'lnkup-theme' ), '#interest', 'lp-btn--lime', 'lnkup-cohort-cta' ) ),
				'testid'  => 'lnkup-cohort-hero',
			)
		);
		?>
		<?php if ( '' !== trim( (string) $page['schedule'] ) ) : ?>
			<section class="lp-section" aria-labelledby="lnkup-cohort-schedule-title">
				<div class="lp-container" style="max-width:820px">
					<p class="lp-eyebrow"><?php esc_html_e( 'Schedule', 'lnkup-theme' ); ?></p>
					<h2 class="lp-h2" id="lnkup-cohort-schedule-title"><?php esc_html_e( 'How it runs', 'lnkup-theme' ); ?></h2>
					<div class="lp-lead"><?php echo wp_kses_post( wpautop( esc_html( (string) $page['schedule'] ) ) ); ?></div>
				</div>
			</section>
		<?php endif; ?>
		<?php if ( ! empty( $page['speakers'] ) ) : ?>
			<section class="lp-section lp-section--mist" aria-labelledby="lnkup-cohort-speakers-title" data-testid="lnkup-cohort-speakers">
				<div class="lp-container">
					<?php lnkup_public_section_head( array( 'eyebrow' => __( 'Speakers', 'lnkup-theme' ), 'title' => __( 'Who you will learn from', 'lnkup-theme' ), 'id' => 'lnkup-cohort-speakers-title' ) ); ?>
					<div class="lp-grid lp-grid--3">
						<?php foreach ( (array) $page['speakers'] as $speaker ) : ?>
							<article class="lp-feature">
								<h3><?php echo esc_html( (string) $speaker['name'] ); ?></h3>
								<?php if ( '' !== (string) $speaker['role'] ) : ?><p class="lp-muted"><?php echo esc_html( (string) $speaker['role'] ); ?></p><?php endif; ?>
								<?php if ( '' !== (string) $speaker['bio'] ) : ?><p><?php echo esc_html( (string) $speaker['bio'] ); ?></p><?php endif; ?>
							</article>
						<?php endforeach; ?>
					</div>
				</div>
			</section>
		<?php endif; ?>
		<section class="lp-section" id="interest" aria-labelledby="lnkup-cohort-form-title" data-testid="lnkup-cohort-interest">
			<div class="lp-container" style="max-width:720px">
				<h2 class="lp-h2" id="lnkup-cohort-form-title"><?php esc_html_e( 'Request a spot', 'lnkup-theme' ); ?></h2>
				<?php if ( $joined ) : ?>
					<p class="lp-notice" role="status"><?php esc_html_e( 'Thanks. The LNKup team will email you about next steps.', 'lnkup-theme' ); ?></p>
				<?php elseif ( ! LNKup_Cohort_Landings::form_open() ) : ?>
					<div class="lp-empty"><h3><?php esc_html_e( 'Requests open soon', 'lnkup-theme' ); ?></h3><p><?php esc_html_e( 'Check back shortly or create a free organizer account in the meantime.', 'lnkup-theme' ); ?></p><a class="lp-btn lp-btn--primary" href="<?php echo esc_url( lnkup_public_signup_url( 'organizer' ) ); ?>"><?php esc_html_e( 'Create an organizer account', 'lnkup-theme' ); ?></a></div>
				<?php else : ?>
					<?php if ( '' !== $error ) : ?>
						<p class="lp-notice" role="alert"><?php echo esc_html( $errors[ $error ] ?? __( 'Your request could not be sent. Please try again.', 'lnkup-theme' ) ); ?></p>
					<?php endif; ?>
					<form class="lp-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:grid;gap:14px">
						<input type="hidden" name="action" value="lnkup_cohort_interest" />
						<input type="hidden" name="cohort" value="<?php echo esc_attr( (string) $page['slug'] ); ?>" />
						<?php wp_nonce_field( 'lnkup_cohort_' . $page['slug'], 'lnkup_cohort_nonce' ); ?>
						<label><span><?php esc_html_e( 'Your name', 'lnkup-theme' ); ?></span><input type="text" name="name" required maxlength="120" autocomplete="name" /></label>
						<label><span><?php esc_html_e( 'Organization or event name', 'lnkup-theme' ); ?></span><input type="text" name="organization" required maxlength="191" autocomplete="organization" /></label>
						<label><span><?php esc_html_e( 'Email', 'lnkup-theme' ); ?></span><input type="email" name="email" required autocomplete="email" /></label>
						<div style="display:grid;grid-template-columns:2fr 1fr;gap:10px">
							<label><span><?php esc_html_e( 'City', 'lnkup-theme' ); ?></span><input type="text" name="city" maxlength="100" autocomplete="address-level2" /></label>
							<label><span><?php esc_html_e( 'State', 'lnkup-theme' ); ?></span><input type="text" name="state" maxlength="2" autocomplete="address-level1" /></label>
						</div>
						<label><span><?php esc_html_e( 'How often do you run events?', 'lnkup-theme' ); ?></span>
							<select name="event_frequency">
								<option value=""><?php esc_html_e( 'Choose one', 'lnkup-theme' ); ?></option>
								<option value="one_off"><?php esc_html_e( 'One event', 'lnkup-theme' ); ?></option>
								<option value="annual"><?php esc_html_e( 'Once a year', 'lnkup-theme' ); ?></option>
								<option value="seasonal"><?php esc_html_e( 'Seasonally', 'lnkup-theme' ); ?></option>
								<option value="monthly"><?php esc_html_e( 'Monthly', 'lnkup-theme' ); ?></option>
								<option value="weekly"><?php esc_html_e( 'Weekly', 'lnkup-theme' ); ?></option>
							</select>
						</label>
						<div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden"><label><?php esc_html_e( 'Leave this empty', 'lnkup-theme' ); ?><input type="text" name="company_website" tabindex="-1" autocomplete="off" /></label></div>
						<label style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="consent" value="1" required /> <span><?php esc_html_e( 'LNKup may email me about this cohort. I can opt out any time.', 'lnkup-theme' ); ?></span></label>
						<button type="submit" class="lp-btn lp-btn--primary lp-btn--lg"><?php esc_html_e( 'Request a spot', 'lnkup-theme' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</section>
	</main>
	<?php get_template_part( 'template-parts/landing/site-footer' ); ?>
</div>
<?php
get_footer();
