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
		$invite_token = isset( $_GET['invite'] ) ? sanitize_text_field( wp_unslash( $_GET['invite'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$context      = LNKup_Applications::build_apply_context( $opportunity_id, 0, $invite_token );
		if ( is_wp_error( $context ) ) {
			if ( 'lnkup_invite_only' === $context->get_error_code() ) {
				return '<div class="lnkup-apply-profile__state lnkup-apply-profile__state--blocked" data-testid="lnkup-apply-invite-only"><p>' . esc_html( $context->get_error_message() ) . '</p></div>';
			}
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
		$invite       = isset( $_GET['invite'] ) ? sanitize_text_field( wp_unslash( $_GET['invite'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$return_args  = array( 'apply' => $opportunity_id );
		if ( '' !== $invite ) {
			$return_args['invite'] = $invite;
		}
		$apply_return = add_query_arg( $return_args, lnkup_page_url_helper( 'opportunities' ) );
		$login_url    = lnkup_login_url_for( $apply_return );
		$register_args = array(
			'role'     => 'vendor',
			'apply'    => $opportunity_id,
			'redirect' => rawurlencode( $apply_return ),
		);
		if ( '' !== $invite ) {
			$register_args['invite'] = $invite;
		}
		$register_url = add_query_arg( $register_args, lnkup_registration_url() );
		$event_label  = (string) ( $opp['event_name'] ?? $opp['title'] ?? '' );
		$slot_label   = (string) ( $opp['opportunity_title'] ?? $opp['name'] ?? $event_label );

		ob_start();
		?>
		<div class="lnkup-apply-profile" data-testid="lnkup-apply-modal">
			<header class="lnkup-apply-profile__header">
				<h2 class="lnkup-apply-profile__title" id="lnkup-apply-modal-title"><?php echo esc_html( lnkup_copy( 'apply_modal_title' ) ); ?></h2>
				<p class="lnkup-muted lnkup-apply-profile__lede"><?php echo esc_html( lnkup_copy( 'apply_modal_description' ) ); ?></p>
			</header>

			<?php self::render_opportunity_summary( $opp ); ?>
			<?php self::render_booth_choices( $opportunity_id, (int) ( $opp['event_id'] ?? 0 ) ); ?>

			<?php if ( 'not_vendor' === $eligibility['block_reason'] ) : ?>
				<div class="lnkup-apply-profile__state lnkup-apply-profile__state--blocked">
					<p><?php esc_html_e( 'Only vendor accounts can apply for booths. Switch to a vendor account to apply.', 'lnkup-marketplace' ); ?></p>
				</div>
			<?php elseif ( 'logged_out' === $eligibility['block_reason'] ) : ?>
				<div class="lnkup-apply-profile__state lnkup-apply-profile__state--login">
					<p><?php echo esc_html( lnkup_copy( 'apply_logged_out' ) ); ?></p>
					<div class="lnkup-apply-profile__actions">
						<a href="<?php echo esc_url( $login_url ); ?>" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Log in', 'lnkup-marketplace' ); ?></a>
						<a href="<?php echo esc_url( $register_url ); ?>" class="lnkup-btn lnkup-btn--outline"><?php esc_html_e( 'Sign up', 'lnkup-marketplace' ); ?></a>
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
					<a href="<?php echo esc_url( $apps_url ); ?>" class="lnkup-btn lnkup-btn--outline"><?php esc_html_e( 'View requests', 'lnkup-marketplace' ); ?></a>
				</div>
			<?php elseif ( 'previously_declined' === $eligibility['block_reason'] ) : ?>
				<div class="lnkup-apply-profile__state lnkup-apply-profile__state--declined" data-testid="lnkup-apply-declined">
					<p><?php esc_html_e( 'This organizer declined your earlier application. You can apply again if they invite you or change this opportunity.', 'lnkup-marketplace' ); ?></p>
					<a href="<?php echo esc_url( $apps_url ); ?>" class="lnkup-btn lnkup-btn--outline"><?php esc_html_e( 'View requests', 'lnkup-marketplace' ); ?></a>
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
			<?php elseif ( self::wizard_enabled() ) : ?>
				<?php self::render_reapply_note( $eligibility ); ?>
				<?php self::render_wizard( $opportunity_id, $context, $invite_token, $docs_url, $profile_url ); ?>
			<?php else : ?>
				<?php self::render_reapply_note( $eligibility ); ?>
				<?php self::render_progress( $documents ); ?>
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
									<p>
										<a href="<?php echo esc_url( $action_url ); ?>" class="lnkup-btn lnkup-btn--outline lnkup-apply-warnings__fix">
											<?php echo esc_html( $action_label ); ?>
										</a>
									</p>
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
					<?php if ( '' !== $invite_token ) : ?>
						<input type="hidden" name="invite_token" value="<?php echo esc_attr( $invite_token ); ?>" />
					<?php endif; ?>

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
				<div class="lnkup-alert lnkup-alert--success" role="status" aria-live="polite">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: vendor slot, 2: event name */
								__( 'Your request for %1$s at %2$s was submitted.', 'lnkup-marketplace' ),
								$slot_label,
								$event_label
							)
						);
						?>
					</p>
					<p><?php esc_html_e( 'The organizer reviews this request before any payment.', 'lnkup-marketplace' ); ?></p>
				</div>
				<a href="<?php echo esc_url( $apps_url ); ?>" class="lnkup-btn lnkup-btn--outline"><?php esc_html_e( 'View requests', 'lnkup-marketplace' ); ?></a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Short note when the vendor is applying again after an earlier application closed.
	 *
	 * @param array $eligibility Eligibility from LNKup_Applications::evaluate_eligibility().
	 */
	private static function render_reapply_note( array $eligibility ) {
		$previous = $eligibility['previous_application'] ?? null;
		if ( ! is_array( $previous ) || 'allowed' !== ( $previous['reapply'] ?? '' ) ) {
			return;
		}
		$messages = array(
			'organizer_allowed'   => __( 'The organizer re-opened this booth for you. You can apply again.', 'lnkup-marketplace' ),
			'invited_again'       => __( 'The organizer invited you again. You can apply again.', 'lnkup-marketplace' ),
			'opportunity_changed' => __( 'This booth changed since your last application. You can apply again.', 'lnkup-marketplace' ),
		);
		$text = $messages[ $previous['reason'] ?? '' ] ?? __( 'You withdrew an earlier application for this booth. You can apply again.', 'lnkup-marketplace' );
		echo '<p class="lnkup-alert lnkup-alert--info" data-testid="lnkup-apply-reapply-note">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Whether the step-based wizard (autosave, resume, questions, estimate) is on.
	 */
	public static function wizard_enabled(): bool {
		return LNKup_Rollout::enabled( 'apply_wizard_enabled' );
	}

	/**
	 * Step-based application form backed by a server-side draft.
	 *
	 * Steps: opportunity → business → questions → documents → review. Applications without
	 * organizer questions or required documents collapse to a single step.
	 *
	 * @param int                  $opportunity_id Opportunity.
	 * @param array<string, mixed> $context        Apply context.
	 * @param string               $invite_token   Invite token.
	 * @param string               $docs_url       Documents page.
	 * @param string               $profile_url    Profile page.
	 */
	private static function render_wizard( int $opportunity_id, array $context, string $invite_token, string $docs_url, string $profile_url ): void {
		$user_id    = get_current_user_id();
		$questions  = LNKup_Application_Questions::get_for_opportunity( $opportunity_id );
		$documents  = (array) $context['documents'];
		$profile    = (array) ( $context['profile'] ?? array() );
		$categories = (array) $context['categories'];
		$draft      = LNKup_Application_Drafts::get_for_opportunity( $user_id, $opportunity_id );
		$fields     = (array) ( $draft['fields'] ?? array() );
		$answers    = (array) ( $draft['answers'] ?? array() );
		$overrides  = (array) ( $draft['profile_overrides'] ?? array() );
		$attached   = array_map( 'intval', (array) ( $draft['document_ids'] ?? array() ) );
		$category   = (string) ( $fields['selected_category'] ?? '' ) ?: (string) $context['default_category'];
		$single     = ! $questions && ! $documents;
		$steps      = $single ? array( 'opportunity' ) : array_values( array_filter( array( 'opportunity', 'business', 'questions', $documents ? 'documents' : '', 'review' ) ) );
		$labels     = array(
			'opportunity' => __( 'Booth', 'lnkup-marketplace' ),
			'business'    => __( 'Your business', 'lnkup-marketplace' ),
			'questions'   => __( 'Questions', 'lnkup-marketplace' ),
			'documents'   => __( 'Documents', 'lnkup-marketplace' ),
			'review'      => __( 'Review', 'lnkup-marketplace' ),
		);
		$start      = $draft && in_array( $draft['step'], $steps, true ) ? $draft['step'] : 'opportunity';
		$vendor_docs = class_exists( 'LNKup_Documents' ) ? LNKup_Documents::get_for_vendor( $user_id ) : array();
		$doc_types   = class_exists( 'LNKup_Documents' ) ? LNKup_Documents::document_types() : array();
		$config      = array(
			'opportunityId' => $opportunity_id,
			'steps'         => $steps,
			'start'         => $start,
			'revision'      => $draft ? (int) $draft['revision'] : null,
			'hasDraft'      => (bool) $draft,
			'priceChanged'  => $draft ? (bool) $draft['price']['changed'] : false,
			'inviteToken'   => $invite_token,
		);
		$step_attr = static function ( string $step ) use ( $single, $start ): string {
			$hidden = ! $single && $step !== $start;
			return ' data-wizard-step="' . esc_attr( $step ) . '"' . ( $hidden ? ' hidden' : '' );
		};
		$field_id  = static function ( string $name ) use ( $opportunity_id ): string {
			return 'lnkup-aw-' . $opportunity_id . '-' . sanitize_html_class( $name );
		};
		?>
		<?php if ( $draft ) : ?>
			<div class="lnkup-alert lnkup-alert--info lnkup-apply-wizard__resume" data-testid="lnkup-apply-wizard-resume">
				<p><strong><?php esc_html_e( 'Continue your application', 'lnkup-marketplace' ); ?></strong>
				<?php
				$saved_ts = $draft['updated_at'] ? strtotime( $draft['updated_at'] ) : false;
				if ( $saved_ts ) {
					/* translators: %s: relative time */
					echo ' · ' . esc_html( sprintf( __( 'saved %s ago', 'lnkup-marketplace' ), human_time_diff( $saved_ts, current_time( 'timestamp' ) ) ) );
				}
				?>
				</p>
			</div>
		<?php endif; ?>
		<form class="lnkup-form lnkup-apply-wizard" novalidate
			data-opportunity-id="<?php echo esc_attr( (string) $opportunity_id ); ?>"
			data-wizard-config="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>"
			data-single-step="<?php echo $single ? '1' : '0'; ?>"
			data-testid="lnkup-apply-wizard">
			<p class="lnkup-apply-wizard__save" role="status" aria-live="polite" data-wizard-save-status data-testid="lnkup-apply-wizard-save-status"></p>

			<?php if ( ! $single ) : ?>
				<ol class="lnkup-apply-steps" aria-label="<?php esc_attr_e( 'Application progress', 'lnkup-marketplace' ); ?>" data-wizard-progress>
					<?php foreach ( $steps as $index => $step ) : ?>
						<li class="lnkup-apply-steps__item" data-wizard-progress-step="<?php echo esc_attr( $step ); ?>">
							<span class="lnkup-apply-steps__num" aria-hidden="true"><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
							<span class="lnkup-apply-steps__label"><?php echo esc_html( $labels[ $step ] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>

			<section class="lnkup-apply-wizard__step"<?php echo $step_attr( 'opportunity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<?php if ( $categories ) : ?>
					<div class="lnkup-form-row">
						<label for="<?php echo esc_attr( $field_id( 'category' ) ); ?>"><?php echo esc_html( lnkup_copy( 'apply_category_label' ) ); ?> <span aria-hidden="true">*</span></label>
						<select id="<?php echo esc_attr( $field_id( 'category' ) ); ?>" name="selected_category" required>
							<?php foreach ( $categories as $cat ) : ?>
								<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $category, $cat ); ?>><?php echo esc_html( $cat ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php else : ?>
					<input type="hidden" name="selected_category" value="<?php echo esc_attr( $category ?: 'General' ); ?>" />
				<?php endif; ?>
				<div class="lnkup-apply-wizard__quote lnkup-card" data-wizard-quote aria-live="polite" data-testid="lnkup-apply-wizard-quote">
					<p class="lnkup-muted"><?php esc_html_e( 'Loading price estimate…', 'lnkup-marketplace' ); ?></p>
				</div>
				<?php if ( $single ) : ?>
					<?php self::render_profile_snapshot( $profile ?: null, (array) ( $context['strength'] ?? array() ) ); ?>
					<?php self::render_text_fields( $fields, $field_id ); ?>
				<?php endif; ?>
			</section>

			<?php if ( ! $single ) : ?>
				<section class="lnkup-apply-wizard__step"<?php echo $step_attr( 'business' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php self::render_profile_snapshot( $profile ?: null, (array) ( $context['strength'] ?? array() ) ); ?>
					<details class="lnkup-apply-wizard__overrides"<?php echo $overrides ? ' open' : ''; ?>>
						<summary><?php esc_html_e( 'Change details for this application', 'lnkup-marketplace' ); ?></summary>
						<p class="lnkup-muted"><?php esc_html_e( 'Changes here apply to this application only unless you also update your profile.', 'lnkup-marketplace' ); ?></p>
						<?php
						$override_fields = array(
							'business_name' => array( __( 'Business name', 'lnkup-marketplace' ), 'text', true ),
							'contact_email' => array( __( 'Contact email', 'lnkup-marketplace' ), 'email', true ),
							'contact_phone' => array( __( 'Contact phone', 'lnkup-marketplace' ), 'tel', true ),
							'products'      => array( __( 'What you will sell or offer', 'lnkup-marketplace' ), 'textarea', false ),
							'setup_needs'   => array( __( 'Setup needs', 'lnkup-marketplace' ), 'textarea', false ),
						);
						foreach ( $override_fields as $key => $spec ) :
							$value = (string) ( $overrides[ $key ] ?? ( $profile[ $key ] ?? '' ) );
							$id    = $field_id( 'ov-' . $key );
							?>
							<div class="lnkup-form-row">
								<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $spec[0] ); ?></label>
								<?php if ( 'textarea' === $spec[1] ) : ?>
									<textarea id="<?php echo esc_attr( $id ); ?>" name="overrides[<?php echo esc_attr( $key ); ?>]" rows="2" data-profile-value="<?php echo esc_attr( (string) ( $profile[ $key ] ?? '' ) ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
								<?php else : ?>
									<input id="<?php echo esc_attr( $id ); ?>" type="<?php echo esc_attr( $spec[1] ); ?>" name="overrides[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" data-profile-value="<?php echo esc_attr( (string) ( $profile[ $key ] ?? '' ) ); ?>" autocomplete="<?php echo esc_attr( 'contact_email' === $key ? 'email' : ( 'contact_phone' === $key ? 'tel' : 'organization' ) ); ?>" />
								<?php endif; ?>
								<?php if ( $spec[2] ) : ?>
									<label class="lnkup-checkbox lnkup-apply-wizard__update-profile">
										<input type="checkbox" name="update_profile[<?php echo esc_attr( $key ); ?>]" value="1" />
										<span><?php esc_html_e( 'Also update my profile', 'lnkup-marketplace' ); ?></span>
									</label>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
						<p class="lnkup-muted"><a href="<?php echo esc_url( $profile_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Edit your full profile', 'lnkup-marketplace' ); ?></a></p>
					</details>
				</section>

				<section class="lnkup-apply-wizard__step"<?php echo $step_attr( 'questions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php self::render_questions( $questions, $answers, $field_id, $vendor_docs, $doc_types ); ?>
					<?php self::render_text_fields( $fields, $field_id ); ?>
				</section>

				<?php if ( $documents ) : ?>
					<section class="lnkup-apply-wizard__step"<?php echo $step_attr( 'documents' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php self::render_documents( $documents, $docs_url ); ?>
						<?php if ( LNKup_Application_Questions::documents_gate( $opportunity_id ) ) : ?>
							<p class="lnkup-alert lnkup-alert--warning"><?php esc_html_e( 'This organizer needs these documents before you can submit.', 'lnkup-marketplace' ); ?></p>
						<?php endif; ?>
						<?php if ( $vendor_docs ) : ?>
							<fieldset class="lnkup-apply-wizard__attach">
								<legend><?php esc_html_e( 'Share documents with this organizer', 'lnkup-marketplace' ); ?></legend>
								<?php foreach ( $vendor_docs as $doc ) : ?>
									<?php $type = (string) get_post_meta( $doc->ID, '_lnkup_document_type', true ); ?>
									<label class="lnkup-checkbox">
										<input type="checkbox" name="document_ids[]" value="<?php echo esc_attr( (string) $doc->ID ); ?>" <?php checked( in_array( (int) $doc->ID, $attached, true ) ); ?> />
										<span><?php echo esc_html( $doc_types[ $type ] ?? ( $doc->post_title ?: ucwords( str_replace( '_', ' ', $type ) ) ) ); ?></span>
									</label>
								<?php endforeach; ?>
							</fieldset>
						<?php endif; ?>
						<?php self::render_vault_reuse( (array) ( $context['reusable_documents'] ?? array() ), ! empty( $context['document_sharing'] ) ); ?>
					</section>
				<?php endif; ?>

				<section class="lnkup-apply-wizard__step"<?php echo $step_attr( 'review' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<h3 class="lnkup-apply-wizard__review-title"><?php esc_html_e( 'Review your application', 'lnkup-marketplace' ); ?></h3>
					<dl class="lnkup-apply-wizard__review" data-wizard-review data-testid="lnkup-apply-wizard-review"></dl>
				</section>
			<?php endif; ?>

			<div class="lnkup-apply-wizard__final"<?php echo ( $single || 'review' === $start ) ? '' : ' hidden'; ?> data-wizard-final>
				<div class="lnkup-form-row lnkup-form-row--checkbox">
					<label class="lnkup-checkbox">
						<input type="checkbox" name="accepted_terms" value="1" required />
						<span><?php echo esc_html( lnkup_copy( 'apply_terms_label' ) ); ?></span>
					</label>
				</div>
				<p class="lnkup-alert lnkup-alert--error" role="alert" data-wizard-error hidden></p>
				<button type="submit" class="lnkup-btn lnkup-btn--primary" data-wizard-submit data-testid="lnkup-apply-submit"><?php echo esc_html( lnkup_copy( 'apply_submit' ) ); ?></button>
			</div>

			<?php if ( ! $single ) : ?>
				<p class="lnkup-alert lnkup-alert--error" role="alert" data-wizard-step-error hidden></p>
				<div class="lnkup-apply-wizard__nav">
					<button type="button" class="lnkup-btn lnkup-btn--outline" data-wizard-back><?php esc_html_e( 'Back', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-btn lnkup-btn--primary" data-wizard-next data-testid="lnkup-apply-wizard-next"><?php esc_html_e( 'Next', 'lnkup-marketplace' ); ?></button>
				</div>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Message and setup notes.
	 *
	 * @param array    $fields   Saved draft fields.
	 * @param callable $field_id ID builder.
	 */
	private static function render_text_fields( array $fields, callable $field_id ): void {
		?>
		<div class="lnkup-form-row">
			<label for="<?php echo esc_attr( $field_id( 'message' ) ); ?>"><?php echo esc_html( lnkup_copy( 'apply_message_label' ) ); ?> <span class="lnkup-muted"><?php esc_html_e( '(optional)', 'lnkup-marketplace' ); ?></span></label>
			<textarea id="<?php echo esc_attr( $field_id( 'message' ) ); ?>" name="application_message" rows="4" maxlength="4000" placeholder="<?php esc_attr_e( 'Tell the organizer why you are a great fit…', 'lnkup-marketplace' ); ?>"><?php echo esc_textarea( (string) ( $fields['application_message'] ?? '' ) ); ?></textarea>
		</div>
		<div class="lnkup-form-row">
			<label for="<?php echo esc_attr( $field_id( 'setup' ) ); ?>"><?php echo esc_html( lnkup_copy( 'apply_setup_notes_label' ) ); ?> <span class="lnkup-muted"><?php esc_html_e( '(optional)', 'lnkup-marketplace' ); ?></span></label>
			<textarea id="<?php echo esc_attr( $field_id( 'setup' ) ); ?>" name="setup_notes" rows="2" maxlength="2000" placeholder="<?php esc_attr_e( 'Any setup details for this event…', 'lnkup-marketplace' ); ?>"><?php echo esc_textarea( (string) ( $fields['setup_notes'] ?? '' ) ); ?></textarea>
		</div>
		<?php
	}

	/**
	 * Organizer questions as form controls named answers[qid].
	 *
	 * @param array    $questions   Definitions.
	 * @param array    $answers     Saved answers.
	 * @param callable $field_id    ID builder.
	 * @param array    $vendor_docs Vendor document posts (for file questions).
	 * @param array    $doc_types   Document type labels.
	 */
	public static function render_questions( array $questions, array $answers, callable $field_id, array $vendor_docs = array(), array $doc_types = array() ): void {
		foreach ( $questions as $q ) {
			$id       = $field_id( 'q-' . $q['id'] );
			$name     = 'answers[' . $q['id'] . ']';
			$value    = $answers[ $q['id'] ] ?? '';
			$required = ! empty( $q['required'] );
			?>
			<div class="lnkup-form-row" data-question-id="<?php echo esc_attr( $q['id'] ); ?>">
				<?php if ( 'checkbox' === $q['type'] ) : ?>
					<label class="lnkup-checkbox">
						<input id="<?php echo esc_attr( $id ); ?>" type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $value ) ); ?> <?php echo $required ? 'required' : ''; ?> />
						<span><?php echo esc_html( $q['label'] ); ?><?php echo $required ? ' <span aria-hidden="true">*</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</label>
				<?php else : ?>
					<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $q['label'] ); ?><?php echo $required ? ' <span aria-hidden="true">*</span>' : ' <span class="lnkup-muted">' . esc_html__( '(optional)', 'lnkup-marketplace' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<?php if ( 'textarea' === $q['type'] ) : ?>
						<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="3" maxlength="4000" <?php echo $required ? 'required' : ''; ?>><?php echo esc_textarea( (string) $value ); ?></textarea>
					<?php elseif ( 'select' === $q['type'] ) : ?>
						<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo $required ? 'required' : ''; ?>>
							<option value=""><?php esc_html_e( 'Choose…', 'lnkup-marketplace' ); ?></option>
							<?php foreach ( $q['options'] as $option ) : ?>
								<option value="<?php echo esc_attr( $option ); ?>" <?php selected( (string) $value, $option ); ?>><?php echo esc_html( $option ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php elseif ( 'file' === $q['type'] ) : ?>
						<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo $required ? 'required' : ''; ?>>
							<option value=""><?php echo $vendor_docs ? esc_html__( 'Choose one of your documents…', 'lnkup-marketplace' ) : esc_html__( 'Upload a document first', 'lnkup-marketplace' ); ?></option>
							<?php foreach ( $vendor_docs as $doc ) : ?>
								<?php $type = (string) get_post_meta( $doc->ID, '_lnkup_document_type', true ); ?>
								<option value="<?php echo esc_attr( (string) $doc->ID ); ?>" <?php selected( (int) $value, (int) $doc->ID ); ?>><?php echo esc_html( $doc_types[ $type ] ?? $doc->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<input id="<?php echo esc_attr( $id ); ?>" type="text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" maxlength="500" <?php echo $required ? 'required' : ''; ?> />
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( '' !== $q['help'] ) : ?>
					<p class="lnkup-muted lnkup-form-help"><?php echo esc_html( $q['help'] ); ?></p>
				<?php endif; ?>
				<p class="lnkup-field-error" data-field-error hidden></p>
			</div>
			<?php
		}
	}

	/**
	 * Booth → Requirements → Confirm progress for an eligible vendor.
	 *
	 * @param array<int, array<string, mixed>> $documents Document rows.
	 */
	private static function render_progress( array $documents ): void {
		$docs_missing = false;
		foreach ( $documents as $row ) {
			if ( in_array( $row['status'] ?? 'missing', array( 'missing', 'expired' ), true ) ) {
				$docs_missing = true;
				break;
			}
		}
		$steps = array(
			array( 'label' => __( 'Booth', 'lnkup-marketplace' ), 'state' => 'done' ),
			array(
				'label' => __( 'Requirements', 'lnkup-marketplace' ),
				'state' => $docs_missing ? 'attention' : 'done',
			),
			array( 'label' => __( 'Confirm & submit', 'lnkup-marketplace' ), 'state' => 'current' ),
		);
		?>
		<ol class="lnkup-apply-steps" aria-label="<?php esc_attr_e( 'Application progress', 'lnkup-marketplace' ); ?>" data-testid="lnkup-apply-steps">
			<?php foreach ( $steps as $index => $step ) : ?>
				<li class="lnkup-apply-steps__item is-<?php echo esc_attr( $step['state'] ); ?>"<?php echo 'current' === $step['state'] ? ' aria-current="step"' : ''; ?>>
					<span class="lnkup-apply-steps__num" aria-hidden="true"><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
					<span class="lnkup-apply-steps__label"><?php echo esc_html( $step['label'] ); ?></span>
					<?php if ( 'attention' === $step['state'] ) : ?>
						<span class="screen-reader-text"><?php esc_html_e( '(documents needed)', 'lnkup-marketplace' ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Sibling vendor slots on the same event, so vendors can switch booth type before applying.
	 *
	 * @param int $opportunity_id Selected opportunity.
	 * @param int $event_id       Parent event.
	 */
	private static function render_booth_choices( int $opportunity_id, int $event_id ): void {
		if ( ! $event_id || ! class_exists( 'LNKup_Organizer_Onboarding' ) ) {
			return;
		}
		$today    = current_time( 'Y-m-d' );
		$statuses = function_exists( 'lnkup_current_vendor_application_statuses' ) ? lnkup_current_vendor_application_statuses() : array();
		$choices  = array();
		foreach ( LNKup_Organizer_Onboarding::event_slot_posts( $event_id ) as $slot ) {
			if ( 'publish' !== $slot->post_status || 'open' !== get_post_meta( $slot->ID, '_lnkup_opportunity_status', true ) ) {
				continue;
			}
			$deadline  = (string) get_post_meta( $slot->ID, '_lnkup_application_deadline', true );
			$unlimited = (int) get_post_meta( $slot->ID, '_lnkup_spots_total', true ) <= 0;
			if ( ( ! $unlimited && (int) get_post_meta( $slot->ID, '_lnkup_spots_remaining', true ) < 1 ) || ( $deadline && $deadline < $today ) ) {
				continue;
			}
			if ( get_post_meta( $slot->ID, '_lnkup_invite_only', true ) && ! lnkup_vendor_can_discover_opportunity( get_current_user_id(), (int) $slot->ID ) && (int) $slot->ID !== $opportunity_id ) {
				continue;
			}
			$fee       = (float) get_post_meta( $slot->ID, '_lnkup_vendor_fee', true );
			$choices[] = array(
				'id'     => (int) $slot->ID,
				'label'  => $slot->post_title,
				'fee'    => $fee > 0 ? lnkup_public_money( $fee ) : __( 'Free', 'lnkup-marketplace' ),
				'status' => isset( $statuses[ (int) $slot->ID ] ) ? lnkup_application_status_label( $statuses[ (int) $slot->ID ] ) : '',
			);
		}
		if ( count( $choices ) < 2 ) {
			return;
		}
		?>
		<fieldset class="lnkup-apply-booths" data-testid="lnkup-apply-booths">
			<legend class="lnkup-apply-booths__legend"><?php esc_html_e( 'Choose a booth type', 'lnkup-marketplace' ); ?></legend>
			<ul class="lnkup-apply-booths__list">
				<?php foreach ( $choices as $choice ) : ?>
					<?php $selected = $choice['id'] === $opportunity_id; ?>
					<li>
						<button type="button" class="lnkup-apply-booths__option<?php echo $selected ? ' is-selected' : ''; ?>" data-apply-switch="<?php echo esc_attr( (string) $choice['id'] ); ?>" aria-pressed="<?php echo $selected ? 'true' : 'false'; ?>">
							<span class="lnkup-apply-booths__name"><?php echo esc_html( $choice['label'] ); ?></span>
							<span class="lnkup-apply-booths__fee"><?php echo esc_html( $choice['status'] ? $choice['status'] : $choice['fee'] ); ?></span>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
		</fieldset>
		<?php
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
				<?php if ( ! empty( $opp['spots_unlimited'] ) ) : ?>
					<li><?php esc_html_e( 'Open spots', 'lnkup-marketplace' ); ?></li>
				<?php elseif ( isset( $opp['spots_remaining'] ) ) : ?>
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
			'pending'  => __( 'Pending review', 'lnkup-marketplace' ),
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

	/**
	 * Document Vault reuse: per-requirement suggestion + explicit consent to share with this organizer.
	 *
	 * @param array<string, mixed> $reusable LNKup_Document_Vault::find_reusable() payload.
	 * @param bool                 $sharing  Sharing flag on.
	 */
	private static function render_vault_reuse( array $reusable, bool $sharing ): void {
		$items = (array) ( $reusable['items'] ?? array() );
		if ( ! $items || ! $sharing ) {
			return;
		}
		$labels = array(
			'satisfies'    => __( 'Ready to share', 'lnkup-marketplace' ),
			'needs_review' => __( 'Organizer will confirm', 'lnkup-marketplace' ),
			'insufficient' => __( 'Not usable for this event', 'lnkup-marketplace' ),
			'missing'      => __( 'Not in your vault', 'lnkup-marketplace' ),
		);
		?>
		<fieldset class="lnkup-apply-wizard__attach lnkup-apply-vault" data-testid="lnkup-apply-vault">
			<legend><?php esc_html_e( 'Reuse documents from your Document Vault', 'lnkup-marketplace' ); ?></legend>
			<p class="lnkup-muted"><?php esc_html_e( 'These are suggestions. The organizer still reviews each document against this event’s requirements.', 'lnkup-marketplace' ); ?></p>
			<?php foreach ( $items as $item ) : ?>
				<?php $shareable = ! empty( $item['document_id'] ) && in_array( $item['result'], array( 'satisfies', 'needs_review' ), true ); ?>
				<div class="lnkup-apply-vault__row lnkup-apply-vault__row--<?php echo esc_attr( (string) $item['result'] ); ?>">
					<label class="lnkup-checkbox">
						<input type="checkbox" name="share_document_ids[]" value="<?php echo esc_attr( (string) (int) $item['document_id'] ); ?>" <?php disabled( ! $shareable ); ?> <?php checked( $shareable && ! empty( $item['already_shared'] ) ); ?> />
						<span>
							<strong><?php echo esc_html( (string) $item['label'] ); ?></strong>
							<?php if ( ! empty( $item['version'] ) ) : ?>
								<?php /* translators: %d: version number */ ?>
								· <?php echo esc_html( sprintf( __( 'version %d', 'lnkup-marketplace' ), (int) $item['version'] ) ); ?>
							<?php endif; ?>
							· <?php echo esc_html( $labels[ $item['result'] ] ?? (string) $item['result'] ); ?>
						</span>
					</label>
					<?php foreach ( (array) $item['reasons'] as $reason ) : ?>
						<p class="lnkup-muted"><?php echo esc_html( (string) $reason ); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
			<label class="lnkup-checkbox">
				<input type="checkbox" name="share_consent" value="1" data-testid="lnkup-apply-share-consent" />
				<span><?php esc_html_e( 'I authorize LNKup to share the selected documents with this organizer for this event only. I can stop sharing at any time from my Document Vault.', 'lnkup-marketplace' ); ?></span>
			</label>
		</fieldset>
		<?php
	}
}
