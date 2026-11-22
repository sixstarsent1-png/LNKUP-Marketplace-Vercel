<?php
/**
 * Public partner program content: published terms and application forms.
 *
 * Terms come from the published commission rule. When no rule is published the page says so
 * instead of showing a rate, and nothing here ever promises acceptance or earnings.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Partner_Public {

	public static function init(): void {
		add_shortcode( 'lnkup_partner_program_terms', array( __CLASS__, 'render_terms' ) );
		add_shortcode( 'lnkup_partner_application', array( __CLASS__, 'render_application' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets(): void {
		wp_register_script( 'lnkup-partner-apply', LNKUP_PLUGIN_URL . 'public/js/partner-apply.js', array(), LNKUP_VERSION, true );
	}

	/**
	 * Terms copy for a program from its published rule.
	 *
	 * @return array{published: bool, lines: string[]}
	 */
	public static function terms_copy( string $program ): array {
		$program = sanitize_key( $program );
		$rule    = class_exists( 'LNKup_Commission_Rules' ) && LNKup_Partner_Tables::ready() ? LNKup_Commission_Rules::active_rule( $program ) : null;
		$valued  = $rule && ( 'fixed' === $rule['basis'] ? (float) $rule['fixed_amount'] > 0 : (float) $rule['rate'] > 0 );
		if ( ! $valued ) {
			return array(
				'published' => false,
				'lines'     => array(
					__( 'Commission terms are shared during approval. Referrals are tracked from day one, and commissions are earned only under approved, published terms.', 'lnkup-marketplace' ),
				),
			);
		}
		$lines   = array();
		$months  = (int) $rule['term_months'];
		$basis   = LNKup_Commission_Rules::basis_label( $rule );
		$anchor  = LNKup_Partner_Programs::CITY_CONNECTOR === $program
			? __( 'from each referred organizer’s first paid booking', 'lnkup-marketplace' )
			: __( 'after each referred account’s attribution', 'lnkup-marketplace' );
		$lines[] = $months > 0
			/* translators: 1: basis label, 2: months, 3: anchor */
			? sprintf( __( 'You earn %1$s for %2$d months %3$s.', 'lnkup-marketplace' ), $basis, $months, $anchor )
			/* translators: %s: basis label */
			: sprintf( __( 'You earn %s.', 'lnkup-marketplace' ), $basis );
		if ( (float) $rule['cap'] > 0 ) {
			/* translators: %s: cap */
			$lines[] = sprintf( __( 'Capped at %s per booking.', 'lnkup-marketplace' ), '$' . number_format( (float) $rule['cap'], 2 ) );
		}
		if ( (int) $rule['hold_days'] > 0 ) {
			/* translators: %d: days */
			$lines[] = sprintf( __( 'Each commission is held for %d days for refunds and disputes, then reviewed by LNKup before payout.', 'lnkup-marketplace' ), (int) $rule['hold_days'] );
		}
		$lines[] = __( 'There is no base pay. Earnings depend on verified bookings and are not guaranteed.', 'lnkup-marketplace' );
		return array( 'published' => true, 'lines' => $lines );
	}

	/**
	 * [lnkup_partner_program_terms program="affiliate"]
	 *
	 * @param array|string $atts Attributes.
	 */
	public static function render_terms( $atts = array() ): string {
		$atts  = shortcode_atts( array( 'program' => 'affiliate' ), (array) $atts, 'lnkup_partner_program_terms' );
		$copy  = self::terms_copy( (string) $atts['program'] );
		$html  = '<div class="lnkup-partner-terms" data-testid="lnkup-partner-terms" data-published="' . ( $copy['published'] ? '1' : '0' ) . '">';
		foreach ( $copy['lines'] as $line ) {
			$html .= '<p class="lnkup-muted">' . esc_html( $line ) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * [lnkup_partner_application program="affiliate|strategic_partner"]
	 *
	 * @param array|string $atts Attributes.
	 */
	public static function render_application( $atts = array() ): string {
		$atts    = shortcode_atts( array( 'program' => 'affiliate' ), (array) $atts, 'lnkup_partner_application' );
		$program = sanitize_key( (string) $atts['program'] );
		if ( ! LNKup_Partner_Programs::accepts_applications( $program ) ) {
			return '<div class="lnkup-empty-state lnkup-empty-state--compact"><p>' . esc_html__( 'Applications for this program are closed right now. Check back soon.', 'lnkup-marketplace' ) . '</p></div>';
		}
		wp_enqueue_script( 'lnkup-partner-apply' );
		wp_localize_script( 'lnkup-partner-apply', 'lnkupPartnerApply', array( 'endpoint' => esc_url_raw( rest_url( 'lnkup/v1/partners/apply' ) ) ) );
		$strategic = LNKup_Partner_Programs::STRATEGIC_PARTNER === $program;
		$id        = 'lnkup-apply-' . $program;
		ob_start();
		?>
		<form class="lnkup-connector-interest lnkup-partner-apply" data-testid="lnkup-partner-apply-form" data-program="<?php echo esc_attr( $program ); ?>" novalidate>
			<input type="hidden" name="program" value="<?php echo esc_attr( $program ); ?>" />
			<div class="lnkup-partner-apply__hp" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">
				<label for="<?php echo esc_attr( $id ); ?>-hp"><?php esc_html_e( 'Leave this field empty', 'lnkup-marketplace' ); ?></label>
				<input id="<?php echo esc_attr( $id ); ?>-hp" name="<?php echo esc_attr( LNKup_Partner_Applications::HONEYPOT ); ?>" tabindex="-1" autocomplete="off" />
			</div>
			<label><?php esc_html_e( 'Your name', 'lnkup-marketplace' ); ?><input name="name" required maxlength="191" autocomplete="name" /></label>
			<label><?php echo esc_html( $strategic ? __( 'Organization', 'lnkup-marketplace' ) : __( 'Business or organization (optional)', 'lnkup-marketplace' ) ); ?><input name="organization"<?php echo $strategic ? ' required' : ''; ?> maxlength="191" autocomplete="organization" /></label>
			<label><?php esc_html_e( 'Business email', 'lnkup-marketplace' ); ?><input name="email" type="email" required autocomplete="email" /></label>
			<label><?php esc_html_e( 'Phone (optional)', 'lnkup-marketplace' ); ?><input name="phone" type="tel" autocomplete="tel" /></label>
			<label><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?><input name="city" required autocomplete="address-level2" /></label>
			<label><?php esc_html_e( 'State', 'lnkup-marketplace' ); ?><input name="state" maxlength="2" autocomplete="address-level1" /></label>
			<label><?php esc_html_e( 'Website or public profile', 'lnkup-marketplace' ); ?><input name="website" type="url" placeholder="https://" /></label>
			<?php if ( $strategic ) : ?>
				<label><?php esc_html_e( 'Organization type', 'lnkup-marketplace' ); ?>
					<select name="category">
						<?php foreach ( LNKup_Partner_Organizations::CATEGORIES as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Cities or regions you serve', 'lnkup-marketplace' ); ?><input name="cities_served" /></label>
			<?php endif; ?>
			<label><?php esc_html_e( 'Your partnership or event-industry experience', 'lnkup-marketplace' ); ?><textarea name="experience" rows="3"></textarea></label>
			<label><?php esc_html_e( 'Which organizers would you introduce?', 'lnkup-marketplace' ); ?><textarea name="audience" rows="3" placeholder="<?php esc_attr_e( 'Markets, festivals, pop-ups, tours…', 'lnkup-marketplace' ); ?>"></textarea></label>
			<label class="lnkup-connector-check"><input type="checkbox" name="disclosures" value="1" required /> <?php esc_html_e( 'I agree to the LNKup partner program terms, will disclose my LNKup relationship when I share referral links, and understand that approval and earnings are not guaranteed.', 'lnkup-marketplace' ); ?></label>
			<button type="submit" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Submit application', 'lnkup-marketplace' ); ?></button>
			<p class="lnkup-connector-interest-status" data-apply-status role="status" aria-live="polite"></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}
}
