<?php
/**
 * Vendor Event Pass view renderer.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Event_Pass_View {

	/**
	 * Render vendor event pass HTML.
	 *
	 * @param int $application_id Application ID.
	 */
	public static function render( int $application_id ): string {
		$pass = LNKup_Packets::get_vendor_event_pass( $application_id, get_current_user_id() );
		if ( is_wp_error( $pass ) ) {
			return '<div class="lnkup-alert lnkup-alert--error">' . esc_html( $pass->get_error_message() ) . '</div>';
		}

		ob_start();
		if ( empty( $pass['is_unlocked'] ) ) {
			self::render_locked( $pass );
		} else {
			self::render_unlocked( $pass );
		}
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $pass Pass DTO.
	 */
	private static function render_locked( array $pass ): void {
		$event = $pass['event'] ?? array();
		?>
		<div class="lnkup-vendor-pass lnkup-vendor-pass--locked" data-testid="lnkup-vendor-event-pass" data-app-id="<?php echo esc_attr( (string) ( $pass['application_id'] ?? 0 ) ); ?>">
			<div class="lnkup-vendor-pass__locked-card" data-testid="lnkup-pass-locked">
				<div class="lnkup-vendor-pass__locked-badge"><?php esc_html_e( 'Locked', 'lnkup-marketplace' ); ?></div>
				<h2 class="lnkup-vendor-pass__title"><?php echo esc_html( $pass['headline'] ?? lnkup_copy( 'pass_locked_title' ) ); ?></h2>
				<p class="lnkup-muted"><?php echo esc_html( $pass['message'] ?? lnkup_copy( 'pass_locked_message' ) ); ?></p>
				<?php if ( ! empty( $event['title'] ) ) : ?>
					<p class="lnkup-vendor-pass__event-name"><strong><?php echo esc_html( $event['title'] ); ?></strong></p>
					<?php if ( ! empty( $event['date'] ) || ! empty( $event['city'] ) ) : ?>
						<p class="lnkup-muted">
							<?php
							$parts = array();
							if ( ! empty( $event['date'] ) ) {
								$parts[] = date_i18n( 'M j, Y', strtotime( (string) $event['date'] ) );
							}
							$loc = trim( ( $event['city'] ?? '' ) . ( ! empty( $event['state'] ) ? ', ' . $event['state'] : '' ) );
							if ( $loc ) {
								$parts[] = $loc;
							}
							echo esc_html( implode( ' · ', $parts ) );
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( ! empty( $pass['application']['label'] ) ) : ?>
					<p class="lnkup-vendor-pass__status"><?php echo esc_html( $pass['application']['label'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $pass['payment']['formatted'] ) ) : ?>
					<p class="lnkup-vendor-pass__amount"><?php echo esc_html( sprintf( __( 'Amount due: %s', 'lnkup-marketplace' ), $pass['payment']['formatted'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $pass['cta']['pay_url'] ) ) : ?>
					<a href="<?php echo esc_url( (string) $pass['cta']['pay_url'] ); ?>" class="lnkup-btn lnkup-btn--primary"><?php echo esc_html( $pass['cta']['label'] ?? lnkup_copy( 'pass_locked_pay_cta' ) ); ?></a>
				<?php endif; ?>
				<?php if ( ! empty( $pass['unlocks_after_payment'] ) ) : ?>
					<div class="lnkup-vendor-pass__unlocks">
						<h3><?php echo esc_html( lnkup_copy( 'pass_unlocks_title' ) ); ?></h3>
						<ul>
							<?php foreach ( $pass['unlocks_after_payment'] as $item ) : ?>
								<li><?php echo esc_html( (string) $item ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $pass Pass DTO.
	 */
	private static function render_unlocked( array $pass ): void {
		$event     = $pass['event'] ?? array();
		$vendor    = $pass['vendor'] ?? array();
		$booth     = $pass['booth'] ?? null;
		$loadin    = $pass['load_in'] ?? array();
		$loadout   = $pass['load_out'] ?? array();
		$parking   = $pass['parking'] ?? null;
		$map       = $pass['map'] ?? array();
		$contacts  = $pass['contacts'] ?? array();
		$rules     = $pass['rules'] ?? array();
		$documents = $pass['documents'] ?? array();
		$weather   = $pass['weather'] ?? array();
		$qr        = $pass['qr_pass'] ?? array();
		$readiness = $pass['readiness'] ?? array();
		$app_id    = (int) ( $pass['application_id'] ?? 0 );
		?>
		<div class="lnkup-vendor-pass" data-testid="lnkup-vendor-event-pass" data-app-id="<?php echo esc_attr( (string) $app_id ); ?>">
			<?php if ( empty( $pass['has_details'] ) && ! empty( $pass['empty_message'] ) ) : ?>
				<div class="lnkup-vendor-pass__empty lnkup-alert lnkup-alert--info"><?php echo esc_html( (string) $pass['empty_message'] ); ?></div>
			<?php endif; ?>

			<section class="lnkup-vendor-pass__hero" data-testid="lnkup-pass-hero">
				<div class="lnkup-vendor-pass__hero-main">
					<span class="lnkup-vendor-pass__badge"><?php echo esc_html( lnkup_copy( 'pass_unlocked_badge' ) ); ?></span>
					<h2 class="lnkup-vendor-pass__title"><?php echo esc_html( $event['title'] ?? '' ); ?></h2>
					<?php if ( ! empty( $event['date'] ) ) : ?>
						<p class="lnkup-muted"><?php echo esc_html( date_i18n( 'l, F j, Y', strtotime( (string) $event['date'] ) ) ); ?></p>
					<?php endif; ?>
					<?php
					$loc = trim( ( $event['city'] ?? '' ) . ( ! empty( $event['state'] ) ? ', ' . $event['state'] : '' ) );
					if ( $loc ) :
						?>
						<p class="lnkup-muted"><?php echo esc_html( $loc ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $vendor['business_name'] ) ) : ?>
						<p class="lnkup-vendor-pass__vendor"><?php echo esc_html( (string) $vendor['business_name'] ); ?></p>
					<?php endif; ?>
					<p class="lnkup-vendor-pass__headline"><?php echo esc_html( $pass['headline'] ?? lnkup_copy( 'pass_unlocked_headline' ) ); ?></p>
				</div>
				<?php if ( ! empty( $qr['enabled'] ) && ! empty( $qr['code'] ) ) : ?>
					<div class="lnkup-vendor-pass__qr" data-testid="lnkup-pass-qr">
						<div class="lnkup-ops-qr" data-qr="<?php echo esc_attr( (string) $qr['code'] ); ?>"></div>
						<p class="lnkup-muted"><?php echo esc_html( $qr['label'] ?? __( 'Vendor Check-In Pass', 'lnkup-marketplace' ) ); ?></p>
						<p class="lnkup-vendor-pass__qr-id"><code><?php echo esc_html( (string) $qr['code'] ); ?></code></p>
					</div>
				<?php endif; ?>
				<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-feature="pass-pdf" hidden disabled><?php echo esc_html( lnkup_copy( 'pass_download_cta' ) ); ?></button>
			</section>

			<div class="lnkup-vendor-pass__grid">
				<section class="lnkup-vendor-pass__section">
					<h3><?php echo esc_html( lnkup_copy( 'pass_section_arrival' ) ); ?></h3>
					<?php if ( ! empty( $event['venue_name'] ) ) : ?>
						<p><strong><?php echo esc_html( (string) $event['venue_name'] ); ?></strong></p>
					<?php endif; ?>
					<?php if ( ! empty( $event['address'] ) ) : ?>
						<p><?php echo esc_html( (string) $event['address'] ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $loadin['window'] ) ) : ?>
						<p><?php echo esc_html( sprintf( __( 'Load-in: %s', 'lnkup-marketplace' ), $loadin['window'] ) ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $loadout['time'] ) ) : ?>
						<p><?php echo esc_html( sprintf( __( 'Load-out: %s', 'lnkup-marketplace' ), $loadout['time'] ) ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $loadin['gate'] ) ) : ?>
						<p><?php echo esc_html( sprintf( __( 'Gate: %s', 'lnkup-marketplace' ), $loadin['gate'] ) ); ?></p>
					<?php endif; ?>
					<?php if ( $parking && ! empty( $parking['instructions'] ) ) : ?>
						<p><?php echo esc_html( (string) $parking['instructions'] ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $contacts['day_of']['name'] ) ) : ?>
						<p><?php echo esc_html( sprintf( __( 'Day-of contact: %1$s %2$s', 'lnkup-marketplace' ), $contacts['day_of']['name'], $contacts['day_of']['phone'] ?? '' ) ); ?></p>
					<?php endif; ?>
				</section>

				<section class="lnkup-vendor-pass__section">
					<h3><?php echo esc_html( lnkup_copy( 'pass_section_booth' ) ); ?></h3>
					<?php if ( $booth && ! empty( $booth['assignment'] ) ) : ?>
						<p><strong><?php echo esc_html( (string) $booth['assignment'] ); ?></strong></p>
						<?php if ( ! empty( $booth['zone'] ) ) : ?>
							<p class="lnkup-muted"><?php echo esc_html( (string) $booth['zone'] ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $booth['dimensions'] ) ) : ?>
							<p class="lnkup-muted"><?php echo esc_html( (string) $booth['dimensions'] ); ?></p>
						<?php endif; ?>
					<?php else : ?>
						<p class="lnkup-muted"><?php esc_html_e( 'Booth assignment pending.', 'lnkup-marketplace' ); ?></p>
					<?php endif; ?>
				</section>

				<section class="lnkup-vendor-pass__section">
					<h3><?php echo esc_html( lnkup_copy( 'pass_section_map' ) ); ?></h3>
					<?php if ( ! empty( $map['attachment_url'] ) ) : ?>
						<img src="<?php echo esc_url( (string) $map['attachment_url'] ); ?>" alt="" class="lnkup-vendor-pass__map-img" loading="lazy" />
					<?php endif; ?>
					<div class="lnkup-map-packet-wrap" data-app-id="<?php echo esc_attr( (string) $app_id ); ?>"></div>
					<?php if ( ! empty( $map['map_url'] ) ) : ?>
						<p><a href="<?php echo esc_url( (string) $map['map_url'] ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php echo esc_html( lnkup_copy( 'pass_open_map' ) ); ?></a></p>
					<?php endif; ?>
				</section>

				<section class="lnkup-vendor-pass__section">
					<h3><?php echo esc_html( lnkup_copy( 'pass_section_rules' ) ); ?></h3>
					<?php if ( ! empty( $rules['vendor_rules'] ) ) : ?>
						<div class="lnkup-vendor-pass__prose"><?php echo wp_kses_post( nl2br( esc_html( (string) $rules['vendor_rules'] ) ) ); ?></div>
					<?php endif; ?>
					<?php if ( ! empty( $rules['prohibited_items'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Prohibited items', 'lnkup-marketplace' ); ?></strong></p>
						<div class="lnkup-vendor-pass__prose"><?php echo wp_kses_post( nl2br( esc_html( (string) $rules['prohibited_items'] ) ) ); ?></div>
					<?php endif; ?>
					<?php if ( ! empty( $pass['refund_policy'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Refund policy', 'lnkup-marketplace' ); ?></strong></p>
						<div class="lnkup-vendor-pass__prose"><?php echo wp_kses_post( nl2br( esc_html( (string) $pass['refund_policy'] ) ) ); ?></div>
					<?php endif; ?>
					<?php if ( $documents ) : ?>
						<ul class="lnkup-vendor-pass__docs">
							<?php foreach ( $documents as $doc ) : ?>
								<li><span class="lnkup-badge"><?php echo esc_html( ucfirst( (string) ( $doc['status'] ?? '' ) ) ); ?></span> <?php echo esc_html( (string) ( $doc['label'] ?? '' ) ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</section>

				<section class="lnkup-vendor-pass__section">
					<h3><?php echo esc_html( lnkup_copy( 'pass_section_weather' ) ); ?></h3>
					<?php
					if ( is_array( $weather ) && class_exists( 'LNKup_Weather_Render' ) && ! empty( $weather['risk'] ) ) {
						echo LNKup_Weather_Render::vendor_packet_note( $weather ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					} elseif ( ! empty( $weather['summary'] ) ) {
						echo '<p>' . esc_html( (string) $weather['summary'] ) . '</p>';
					}
					if ( ! empty( $weather['rain_plan'] ) ) {
						echo '<p><strong>' . esc_html__( 'Rain plan', 'lnkup-marketplace' ) . '</strong></p><p>' . esc_html( (string) $weather['rain_plan'] ) . '</p>';
					}
					if ( ! empty( $weather['heat_plan'] ) ) {
						echo '<p><strong>' . esc_html__( 'Heat plan', 'lnkup-marketplace' ) . '</strong></p><p>' . esc_html( (string) $weather['heat_plan'] ) . '</p>';
					}
					?>
				</section>

				<section class="lnkup-vendor-pass__section" data-testid="lnkup-pass-readiness">
					<h3><?php echo esc_html( lnkup_copy( 'pass_section_readiness' ) ); ?></h3>
					<div class="lnkup-vendor-pass__readiness-ring" data-percent="<?php echo esc_attr( (string) (int) ( $readiness['percent'] ?? 0 ) ); ?>">
						<span><?php echo esc_html( (string) (int) ( $readiness['percent'] ?? 0 ) ); ?>%</span>
					</div>
					<?php if ( ! empty( $readiness['label'] ) ) : ?>
						<p><?php echo esc_html( (string) $readiness['label'] ); ?></p>
					<?php endif; ?>
				</section>

				<section class="lnkup-vendor-pass__section">
					<h3><?php echo esc_html( lnkup_copy( 'pass_section_support' ) ); ?></h3>
					<?php if ( ! empty( $contacts['organizer']['name'] ) ) : ?>
						<p><?php echo esc_html( (string) $contacts['organizer']['name'] ); ?><?php echo ! empty( $contacts['organizer']['email'] ) ? ' · ' . esc_html( (string) $contacts['organizer']['email'] ) : ''; ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $pass['support_url'] ) ) : ?>
						<p><a href="<?php echo esc_url( (string) $pass['support_url'] ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php echo esc_html( lnkup_copy( 'pass_support_cta' ) ); ?></a></p>
					<?php endif; ?>
				</section>
			</div>
		</div>
		<?php
	}
}
