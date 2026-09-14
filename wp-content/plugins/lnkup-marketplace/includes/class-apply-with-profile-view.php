<?php
/**
 * Apply with Profile modal view renderer.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Apply_With_Profile_View {

	/**
	 * Render apply modal body HTML.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 */
	public static function render( int $opportunity_id ): string {
		$context = LNKup_Applications::build_apply_context( $opportunity_id );
		if ( is_wp_error( $context ) ) {
			return '';
		}

		$eligibility = $context['eligibility'];
		$opp         = $context['opportunity'];
		$profile     = $context['profile'];
		$documents   = $context['documents'];
		$warnings    = $context['warnings'];
		$categories  = $context['categories'];
		$default_cat = $context['default_category'];

		$docs_url     = add_query_arg( 'view', 'documents', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$profile_url  = lnkup_page_url_helper( 'vendor-profile' );
		$apps_url     = add_query_arg( 'view', 'applications', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$apply_return = add_query_arg( 'apply', $opportunity_id, lnkup_page_url_helper( 'opportunities' ) );
		$login_url    = wp_login_url( $apply_return );
		$register_url = add_query_arg(
			array(
				'role'     => 'vendor',
				'apply'    => $opportunity_id,
				'redirect' => $apply_return,
			),
			lnkup_registration_url()
		);

		ob_start();
		?>
		<div class="lnkup-apply-profile" data-testid="lnkup-apply-modal">
			<header class="lnkup-apply-profile__header">
				<h2 class="lnkup-apply-profile__title" id="lnkup-apply-modal-title"><?php echo esc_html( lnkup_copy( 'apply_modal_title' ) ); ?></h2>
				<p class="lnkup-muted lnkup-apply-profile__lede"><?php echo esc_html( lnkup_copy( 'apply_modal_description' ) ); ?></p>
			</header>

			<?php self::render_opportunity_summary( $opp ); ?>

			<?php if ( 'logged_out' === $eligibility['block_reason'] ) : ?>
				<div class="lnkup-apply-profile__state lnkup-apply-profile__state--login">
					<p><?php echo esc_html( lnkup_copy( 'apply_logged_out' ) ); ?></p>
					<div class="lnkup-apply-profile__actions">
						<a href="<?php echo esc_url( $login_url ); ?>" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Log in', 'lnkup-marketplace' ); ?></a>
						<?php if ( get_option( 'users_can_register' ) ) : ?>
							<a href="<?php echo esc_url( $register_url ); ?>" class="lnkup-btn lnkup-btn--outline"><?php esc_html_e( 'Sign up', 'lnkup-marketplace' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			<?php elseif ( 'no_profile' === $eligibility['block_reason'] ) : ?>
				<div class="lnkup-apply-profile__state lnkup-apply-profile__state--profile">
					<p><?php echo esc_html( lnkup_copy( 'apply_no_profile' ) ); ?></p>
					<a href="<?php echo esc_url( $profile_url ); ?>" class="lnkup-btn lnkup-btn--primary"><?php echo esc_html( lnkup_copy( 'apply_create_profile_cta' ) ); ?></a>
				</div>
			<?php elseif ( 'duplicate' === $eligibility['block_reason'] ) : ?>
				<div class="lnkup-apply-profile__state lnkup-apply-profile__state--applied">
					<p><?php echo esc_html( lnkup_copy( 'apply_already_applied' ) ); ?></p>
					<?php if ( ! empty( $eligibility['existing_application_status'] ) ) : ?>
						<span class="lnkup-status lnkup-status--<?php echo esc_attr( $eligibility['existing_application_status'] ); ?>">
							<?php echo esc_html( LNKup_Vendor_Dashboard_Data::application_status_label( $eligibility['existing_application_status'] ) ); ?>
						</span>
					<?php endif; ?>
					<a href="<?php echo esc_url( $apps_url ); ?>" class="lnkup-btn lnkup-btn--outline"><?php echo esc_html( lnkup_copy( 'apply_view_applications' ) ); ?></a>
				</div>
			<?php elseif ( ! $eligibility['can_apply'] ) : ?>
				<div class="lnkup-apply-profile__state lnkup-apply-profile__state--blocked">
					<?php
					if ( 'deadline' === $eligibility['block_reason'] ) {
						$block_msg = __( 'The application deadline for this opportunity has passed.', 'lnkup-marketplace' );
					} elseif ( 'full' === $eligibility['block_reason'] ) {
						$block_msg = __( 'No spots remaining for this opportunity.', 'lnkup-marketplace' );
					} else {
						$block_msg = __( 'This opportunity is not open for applications.', 'lnkup-marketplace' );
					}
					?>
					<p class="lnkup-alert lnkup-alert--error"><?php echo esc_html( $block_msg ); ?></p>
				</div>
			<?php else : ?>
				<?php if ( $warnings ) : ?>
					<div class="lnkup-apply-warnings" role="status">
						<?php foreach ( $warnings as $warning ) : ?>
							<div class="lnkup-apply-warnings__item">
								<p><?php echo esc_html( $warning['message'] ?? '' ); ?></p>
								<?php
								$code = $warning['code'] ?? '';
								$action_url = $warning['action_url'] ?? '';
								if ( ! $action_url && 'category_mismatch' === $code ) {
									$action_url = $profile_url . '#categories';
								} elseif ( ! $action_url && 'profile_incomplete' === $code ) {
									$action_url = $profile_url;
								}
								$action_label = $warning['action_label'] ?? ( 'category_mismatch' === $code ? __( 'Update profile categories', 'lnkup-marketplace' ) : __( 'Update profile', 'lnkup-marketplace' ) );
								if ( 'category_mismatch' === $code ) :
									$event_cats   = array_filter( (array) ( $warning['event_categories'] ?? array() ) );
									$profile_cats = array_filter( (array) ( $warning['profile_categories'] ?? array() ) );
									if ( $event_cats ) :
										?>
										<p class="lnkup-muted"><?php echo esc_html( sprintf( lnkup_copy( 'apply_category_event_seeks' ), implode( ', ', $event_cats ) ) ); ?></p>
									<?php endif; ?>
									<?php if ( $profile_cats ) : ?>
										<p class="lnkup-muted"><?php echo esc_html( sprintf( lnkup_copy( 'apply_category_your_profile' ), implode( ', ', $profile_cats ) ) ); ?></p>
									<?php endif; ?>
								<?php endif; ?>
								<?php if ( $action_url && in_array( $code, array( 'category_mismatch', 'profile_incomplete' ), true ) ) : ?>
									<p><a href="<?php echo esc_url( $action_url ); ?>" class="lnkup-apply-warnings__fix"><?php echo esc_html( $action_label ); ?></a></p>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php self::render_profile_snapshot( $profile, $context['strength'] ?? array() ); ?>
				<?php self::render_documents( $documents, $docs_url ); ?>

				<form class="lnkup-form lnkup-apply-form lnkup-apply-profile__form" data-opportunity-id="<?php echo esc_attr( (string) $opportunity_id ); ?>" data-testid="lnkup-apply-form">
					<?php wp_nonce_field( 'lnkup_apply', 'lnkup_nonce' ); ?>
					<input type="hidden" name="action" value="lnkup_apply" />
					<input type="hidden" name="opportunity_id" value="<?php echo esc_attr( (string) $opportunity_id ); ?>" />

					<?php if ( $categories ) : ?>
						<div class="lnkup-form-row">
							<label for="lnkup-apply-category"><?php echo esc_html( lnkup_copy( 'apply_category_label' ) ); ?></label>
							<select id="lnkup-apply-category" name="selected_category" required>
								<?php foreach ( $categories as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $default_cat, $cat ); ?>><?php echo esc_html( $cat ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					<?php else : ?>
						<input type="hidden" name="selected_category" value="<?php echo esc_attr( $default_cat ?: 'General' ); ?>" />
					<?php endif; ?>

					<div class="lnkup-form-row">
						<label for="lnkup-apply-message"><?php echo esc_html( lnkup_copy( 'apply_message_label' ) ); ?></label>
						<textarea id="lnkup-apply-message" name="message" rows="4" placeholder="<?php esc_attr_e( 'Tell the organizer why you are a great fit…', 'lnkup-marketplace' ); ?>"></textarea>
					</div>

					<div class="lnkup-form-row">
						<label for="lnkup-apply-setup-notes"><?php echo esc_html( lnkup_copy( 'apply_setup_notes_label' ) ); ?></label>
						<textarea id="lnkup-apply-setup-notes" name="setup_notes" rows="2" placeholder="<?php esc_attr_e( 'Any setup details for this event…', 'lnkup-marketplace' ); ?>"></textarea>
					</div>

					<div class="lnkup-form-row lnkup-form-row--checkbox">
						<label class="lnkup-checkbox">
							<input type="checkbox" name="accepted_terms" value="1" required />
							<span><?php echo esc_html( lnkup_copy( 'apply_terms_label' ) ); ?></span>
						</label>
					</div>

					<button type="submit" class="lnkup-btn lnkup-btn--primary" data-testid="lnkup-apply-submit"><?php echo esc_html( lnkup_copy( 'apply_submit' ) ); ?></button>
				</form>
			<?php endif; ?>

			<div class="lnkup-apply-success" id="lnkup-apply-success" hidden>
				<p class="lnkup-alert lnkup-alert--success"><?php echo esc_html( lnkup_copy( 'apply_success' ) ); ?></p>
				<a href="<?php echo esc_url( $apps_url ); ?>" class="lnkup-btn lnkup-btn--outline"><?php echo esc_html( lnkup_copy( 'apply_view_applications' ) ); ?></a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Opportunity summary card.
	 *
	 * @param array<string, mixed> $opp Opportunity card data.
	 */
	private static function render_opportunity_summary( array $opp ): void {
		$event_name = $opp['event_name'] ?? $opp['title'] ?? '';
		?>
		<section class="lnkup-apply-profile__opp lnkup-card">
			<h3 class="lnkup-apply-profile__opp-title"><?php echo esc_html( $event_name ); ?></h3>
			<ul class="lnkup-apply-profile__opp-meta lnkup-muted">
				<?php if ( ! empty( $opp['event_date'] ) ) : ?>
					<li><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $opp['event_date'] ) ) ); ?></li>
				<?php endif; ?>
				<?php if ( ! empty( $opp['location_label'] ) ) : ?>
					<li><?php echo esc_html( $opp['location_label'] ); ?></li>
				<?php endif; ?>
				<?php if ( isset( $opp['vendor_fee'] ) ) : ?>
					<li><?php echo esc_html( $opp['fee_label'] ?? lnkup_currency( $opp['vendor_fee'] ) ); ?></li>
				<?php endif; ?>
				<?php if ( ! empty( $opp['deadline_label'] ) ) : ?>
					<li><?php printf( esc_html__( 'Deadline: %s', 'lnkup-marketplace' ), esc_html( $opp['deadline_label'] ) ); ?></li>
				<?php endif; ?>
				<?php if ( isset( $opp['spots_remaining'] ) ) : ?>
					<li><?php printf( esc_html__( '%s spots remaining', 'lnkup-marketplace' ), esc_html( (string) (int) $opp['spots_remaining'] ) ); ?></li>
				<?php endif; ?>
			</ul>
			<?php if ( ! empty( $opp['categories_needed'] ) ) : ?>
				<p class="lnkup-apply-profile__opp-cats">
					<strong><?php esc_html_e( 'Categories needed', 'lnkup-marketplace' ); ?>:</strong>
					<?php echo esc_html( implode( ', ', $opp['categories_needed'] ) ); ?>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $opp['document_badges'] ) ) : ?>
				<div class="lnkup-apply-profile__opp-docs">
					<?php foreach ( $opp['document_badges'] as $badge ) : ?>
						<span class="lnkup-badge"><?php echo esc_html( $badge ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php
			$gallery = ! empty( $opp['gallery'] ) && is_array( $opp['gallery'] )
				? $opp['gallery']
				: ( ! empty( $opp['opportunity_id'] )
					? lnkup_get_opportunity_gallery( (int) $opp['opportunity_id'], (int) ( $opp['event_id'] ?? 0 ) )
					: array() );
			?>
			<?php if ( $gallery ) : ?>
				<div class="lnkup-apply-profile__gallery" data-testid="lnkup-event-gallery-detail">
					<?php foreach ( array_slice( $gallery, 0, 8 ) as $media ) : ?>
						<figure class="lnkup-apply-profile__gallery-item lnkup-apply-profile__gallery-item--<?php echo esc_attr( $media['type'] ?? 'image' ); ?>">
							<?php if ( 'video' === ( $media['type'] ?? '' ) ) : ?>
								<video src="<?php echo esc_url( $media['url'] ); ?>" controls preload="metadata"></video>
							<?php else : ?>
								<img src="<?php echo esc_url( $media['thumb'] ?? $media['url'] ); ?>" alt="" loading="lazy" />
							<?php endif; ?>
						</figure>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Vendor profile snapshot card.
	 *
	 * @param array<string, mixed>|null $profile  Snapshot.
	 * @param array<string, mixed>      $strength Strength payload.
	 */
	private static function render_profile_snapshot( ?array $profile, array $strength ): void {
		if ( ! $profile ) {
			return;
		}
		$tier  = $profile['strength_tier'] ?? ( $strength['tier'] ?? '' );
		$score = (int) ( $profile['strength_score'] ?? ( $strength['score'] ?? 0 ) );
		$cats  = is_array( $profile['category'] ?? null ) ? implode( ', ', $profile['category'] ) : '';
		$cities = is_array( $profile['cities_served'] ?? null ) ? implode( ', ', array_slice( $profile['cities_served'], 0, 5 ) ) : '';
		?>
		<section class="lnkup-apply-profile__snapshot lnkup-card" data-testid="lnkup-apply-profile-snapshot">
			<h3 class="lnkup-apply-profile__snapshot-title"><?php esc_html_e( 'Your profile', 'lnkup-marketplace' ); ?></h3>
			<div class="lnkup-apply-profile__snapshot-body">
				<?php if ( ! empty( $profile['logo_url'] ) ) : ?>
					<img src="<?php echo esc_url( $profile['logo_url'] ); ?>" alt="" class="lnkup-apply-profile__logo" />
				<?php endif; ?>
				<div>
					<p class="lnkup-apply-profile__business"><?php echo esc_html( $profile['business_name'] ?? '' ); ?></p>
					<?php if ( $cats ) : ?>
						<p class="lnkup-muted"><?php echo esc_html( $cats ); ?></p>
					<?php endif; ?>
					<?php if ( $tier ) : ?>
						<span class="lnkup-badge lnkup-badge--strength"><?php echo esc_html( $tier . ( $score ? ' · ' . $score . '%' : '' ) ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $profile['verified'] ) ) : ?>
						<span class="lnkup-badge lnkup-badge--verified"><?php esc_html_e( 'Verified', 'lnkup-marketplace' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( ! empty( $profile['products'] ) ) : ?>
				<p class="lnkup-apply-profile__line"><strong><?php esc_html_e( 'Products / services', 'lnkup-marketplace' ); ?>:</strong> <?php echo esc_html( wp_trim_words( $profile['products'], 30 ) ); ?></p>
			<?php endif; ?>
			<?php if ( $cities ) : ?>
				<p class="lnkup-apply-profile__line"><strong><?php esc_html_e( 'Cities served', 'lnkup-marketplace' ); ?>:</strong> <?php echo esc_html( $cities ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $profile['setup_needs'] ) ) : ?>
				<p class="lnkup-apply-profile__line"><strong><?php esc_html_e( 'Setup needs', 'lnkup-marketplace' ); ?>:</strong> <?php echo esc_html( $profile['setup_needs'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $profile['contact_email'] ) || ! empty( $profile['contact_phone'] ) ) : ?>
				<p class="lnkup-muted lnkup-apply-profile__contact">
					<?php echo esc_html( trim( ( $profile['contact_email'] ?? '' ) . ( $profile['contact_phone'] ? ' · ' . $profile['contact_phone'] : '' ) ) ); ?>
				</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Required documents checklist.
	 *
	 * @param array<int, array<string, mixed>> $documents Document rows.
	 * @param string                           $docs_url  Upload URL.
	 */
	private static function render_documents( array $documents, string $docs_url ): void {
		if ( ! $documents ) {
			return;
		}

		$status_labels = array(
			'approved' => __( 'Approved', 'lnkup-marketplace' ),
			'pending'  => __( 'Pending', 'lnkup-marketplace' ),
			'uploaded' => __( 'Uploaded', 'lnkup-marketplace' ),
			'missing'  => __( 'Missing', 'lnkup-marketplace' ),
			'expired'  => __( 'Expired', 'lnkup-marketplace' ),
		);
		?>
		<section class="lnkup-apply-docs lnkup-card" data-testid="lnkup-apply-docs">
			<h3 class="lnkup-apply-docs__title"><?php esc_html_e( 'Required documents', 'lnkup-marketplace' ); ?></h3>
			<ul class="lnkup-apply-docs__list">
				<?php foreach ( $documents as $row ) : ?>
					<?php
					$status = $row['status'] ?? 'missing';
					$label  = $status_labels[ $status ] ?? $status;
					?>
					<li class="lnkup-apply-docs__item lnkup-apply-docs__item--<?php echo esc_attr( $status ); ?>">
						<span class="lnkup-apply-docs__name"><?php echo esc_html( $row['label'] ?? '' ); ?></span>
						<span class="lnkup-apply-docs__status"><?php echo esc_html( $label ); ?></span>
						<?php if ( in_array( $status, array( 'missing', 'expired' ), true ) ) : ?>
							<a href="<?php echo esc_url( $docs_url ); ?>" class="lnkup-apply-docs__upload"><?php esc_html_e( 'Upload', 'lnkup-marketplace' ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}
}
