<?php
/**
 * Focused organizer dashboard pages.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * One server-rendered organizer section at a time.
 */
class LNKup_Organizer_Dashboard_Views {

	const PER_PAGE = 20;

	/**
	 * Pending request count for the shell badge.
	 *
	 * @param int $user_id Organizer user ID.
	 * @return array{applications:int}
	 */
	public static function nav_badges( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$count   = function_exists( 'lnkup_count_posts_for_organizer' )
			? lnkup_count_posts_for_organizer(
				'lnkup_application',
				$user_id,
				array(
					array(
						'key'     => '_lnkup_application_status',
						'value'   => array( 'submitted', 'under_review' ),
						'compare' => 'IN',
					),
				)
			)
			: 0;
		return array( 'applications' => $count );
	}

	/**
	 * Render the selected organizer section.
	 *
	 * @param string $view Allowlisted view slug.
	 * @return string
	 */
	public static function render( $view ) {
		$view = function_exists( 'lnkup_organizer_dashboard_view' ) ? lnkup_organizer_dashboard_view( $view ) : 'home';
		ob_start();
		?>
		<div class="lnkup-mp lnkup-organizer-dash" id="lnkup-organizer-crm" data-rest="<?php echo esc_url( rest_url( 'lnkup/v1/crm' ) ); ?>" data-active-section="<?php echo esc_attr( 'home' === $view ? 'overview' : $view ); ?>">
			<?php
			switch ( $view ) {
				case 'start':
					echo self::render_start(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'events':
					echo self::render_events(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'applications':
					echo self::render_applications(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'financial':
					echo self::render_financial(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'vendors':
					echo self::render_vendors(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'lists':
					echo self::render_lists(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'invites':
					echo self::render_invites(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'referrals':
					echo self::render_referrals(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'operations':
					echo self::render_operations(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'intelligence':
					echo self::render_intelligence(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'create':
					echo self::render_create(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'help':
					echo self::render_help(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'profile':
					echo self::render_profile(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'team':
					echo self::render_team(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'settings':
					echo self::render_settings(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'tasks':
					echo self::render_my_tasks(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'tours':
					echo self::render_portfolio(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'marketing':
					echo class_exists( 'LNKup_Organizer_Marketing' ) ? LNKup_Organizer_Marketing::render() : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'home':
				default:
					echo self::render_home(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
			}
			if ( in_array( $view, array( 'vendors', 'lists', 'invites', 'applications', 'intelligence' ), true ) ) {
				echo self::render_overlays(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Get started: six-stage activation stepper with inline organization setup, payout CTA,
	 * first-event links, share link + embed snippet, saved setups and vendor import.
	 *
	 * Stages render on the server (works without JS); organizer-start.js saves the profile,
	 * copies links, refreshes progress and manages saved setups.
	 *
	 * @return string
	 */
	private static function render_start() {
		$user_id = get_current_user_id();
		if ( ! class_exists( 'LNKup_Organizer_Activation' ) ) {
			return '<p class="lnkup-empty-state">' . esc_html__( 'Setup progress is unavailable right now.', 'lnkup-marketplace' ) . '</p>';
		}
		$data    = LNKup_Organizer_Activation::for_user( $user_id, true );
		$stages  = array();
		foreach ( $data['stages'] as $stage ) {
			$stages[ $stage['key'] ] = $stage;
		}
		$get     = static fn( string $k ): string => (string) get_user_meta( $user_id, '_lnkup_organizer_' . $k, true );
		$share   = $data['share'];
		$labels  = array(
			'complete'    => __( 'Done', 'lnkup-marketplace' ),
			'in_progress' => __( 'In progress', 'lnkup-marketplace' ),
			'not_started' => __( 'To do', 'lnkup-marketplace' ),
		);
		$v2        = class_exists( 'LNKup_Organizer_Onboarding_Flow' ) && LNKup_Organizer_Onboarding_Flow::enabled();
		$flow      = $v2 ? LNKup_Organizer_Onboarding_Flow::for_user( $user_id ) : null;
		$focus     = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( (string) $_GET['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$num       = 0;
		if ( $flow ) {
			foreach ( $flow['steps'] as $flow_step ) {
				if ( 'first_event' === $flow_step['key'] && isset( $stages['first_event'] ) && empty( $stages['first_event']['items'][0]['done'] ) ) {
					$stages['first_event']['title']   = $flow_step['title'];
					$stages['first_event']['cta_url'] = $flow_step['cta_url'];
				}
			}
			foreach ( $data['stages'] as $k => $stage ) {
				$data['stages'][ $k ] = $stages[ $stage['key'] ];
			}
		}
		ob_start();
		?>
		<section class="lnkup-organizer-section lnkup-start" id="start" data-testid="lnkup-organizer-start" data-activation-percent="<?php echo esc_attr( (string) $data['percent'] ); ?>"<?php echo $v2 ? ' data-onboarding-v2="1" data-focus-step="' . esc_attr( $focus ) . '"' : ''; ?>>
			<header class="lnkup-start__head">
				<div>
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'Get your first vendor booking', 'lnkup-marketplace' ); ?></h2>
					<p class="lnkup-muted"><?php esc_html_e( 'Six steps from a new account to a paid vendor booking. Progress updates automatically as you go.', 'lnkup-marketplace' ); ?></p>
				</div>
				<div class="lnkup-start__meter" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: percent */ __( '%d percent complete', 'lnkup-marketplace' ), (int) $data['percent'] ) ); ?>">
					<strong data-start-percent><?php echo esc_html( (string) $data['percent'] ); ?>%</strong>
					<span><?php echo esc_html( sprintf( /* translators: 1: done, 2: total */ __( '%1$d of %2$d steps', 'lnkup-marketplace' ), (int) $data['completed'], (int) $data['total'] ) ); ?></span>
				</div>
			</header>
			<div class="lnkup-start__progress">
				<div><span><?php esc_html_e( 'Toward your first published event', 'lnkup-marketplace' ); ?></span><progress max="100" value="<?php echo esc_attr( (string) $data['progress_to_first_event'] ); ?>"></progress> <?php echo esc_html( (string) $data['progress_to_first_event'] ); ?>%</div>
				<div><span><?php esc_html_e( 'Toward your first paid booking', 'lnkup-marketplace' ); ?></span><progress max="100" value="<?php echo esc_attr( (string) $data['progress_to_first_paid_booking'] ); ?>"></progress> <?php echo esc_html( (string) $data['progress_to_first_paid_booking'] ); ?>%</div>
			</div>

			<?php if ( $data['next_action'] ) : ?>
				<div class="lnkup-start__next" data-testid="lnkup-start-next">
					<span class="lnkup-muted"><?php esc_html_e( 'Next step', 'lnkup-marketplace' ); ?></span>
					<strong><?php echo esc_html( $data['next_action']['title'] ); ?></strong>
					<?php if ( $data['next_action']['missing'] ) : ?>
						<span class="lnkup-muted"><?php echo esc_html( sprintf( /* translators: %s: list */ __( 'Still needed: %s', 'lnkup-marketplace' ), implode( ', ', $data['next_action']['missing'] ) ) ); ?></span>
					<?php endif; ?>
					<a class="lnkup-btn lnkup-btn--primary" href="<?php echo esc_url( $data['next_action']['url'] ); ?>"><?php echo esc_html( $data['next_action']['label'] ); ?></a>
				</div>
			<?php else : ?>
				<div class="lnkup-start__next lnkup-start__next--done" data-testid="lnkup-start-next">
					<strong><?php echo $v2 ? esc_html__( 'Setup complete — vendors are booking and paying through LNKup.', 'lnkup-marketplace' ) : esc_html__( 'You are fully set up — vendors are booking and paying through LNKup.', 'lnkup-marketplace' ); ?></strong>
				</div>
			<?php endif; ?>

			<?php if ( $flow && $flow['drafts'] ) : ?>
				<div class="lnkup-start__resume" data-testid="lnkup-start-resume">
					<h3><?php esc_html_e( 'Continue where you left off', 'lnkup-marketplace' ); ?></h3>
					<ul>
						<?php foreach ( $flow['drafts'] as $draft ) : ?>
							<li>
								<span><strong><?php echo esc_html( $draft['title'] ); ?></strong> <span class="lnkup-muted"><?php echo esc_html( 'event' === $draft['type'] ? __( 'Draft event', 'lnkup-marketplace' ) : __( 'Draft tour or series', 'lnkup-marketplace' ) ); ?></span></span>
								<a class="lnkup-btn lnkup-btn--sm" href="<?php echo esc_url( $draft['url'] ); ?>"><?php esc_html_e( 'Continue', 'lnkup-marketplace' ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<ol class="lnkup-start__stages">
				<?php foreach ( $data['stages'] as $i => $stage ) : ?>
					<li class="lnkup-start__stage is-<?php echo esc_attr( $stage['status'] ); ?>" data-stage="<?php echo esc_attr( $stage['key'] ); ?>" data-testid="lnkup-start-stage-<?php echo esc_attr( $stage['key'] ); ?>">
						<div class="lnkup-start__stage-head">
							<span class="lnkup-start__num" aria-hidden="true"><?php echo esc_html( (string) ( ++$num ) ); ?></span>
							<div>
								<h3><?php echo esc_html( $stage['title'] ); ?></h3>
								<p class="lnkup-muted"><?php echo esc_html( $stage['description'] ); ?></p>
							</div>
							<span class="lnkup-badge lnkup-start__status"><?php echo esc_html( $labels[ $stage['status'] ] ?? $stage['status'] ); ?></span>
						</div>
						<ul class="lnkup-start__items">
							<?php foreach ( $stage['items'] as $item ) : ?>
								<li class="<?php echo $item['done'] ? 'is-done' : ''; ?>"><span aria-hidden="true"><?php echo $item['done'] ? '✓' : '○'; ?></span> <?php echo esc_html( $item['label'] ); ?><?php echo $item['required'] ? '' : ' <span class="lnkup-muted">(' . esc_html__( 'recommended', 'lnkup-marketplace' ) . ')</span>'; ?></li>
							<?php endforeach; ?>
						</ul>

						<?php if ( 'organization' === $stage['key'] ) : ?>
							<form class="lnkup-start__form" data-start-profile data-testid="lnkup-start-profile">
								<label><?php esc_html_e( 'Organization name', 'lnkup-marketplace' ); ?><input type="text" name="public_name" value="<?php echo esc_attr( $get( 'public_name' ) ); ?>" required></label>
								<label><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?><input type="text" name="city" data-lnkup-location-city value="<?php echo esc_attr( $get( 'city' ) ); ?>" required></label>
								<label><?php esc_html_e( 'State', 'lnkup-marketplace' ); ?><input type="text" name="state" data-lnkup-location-state value="<?php echo esc_attr( $get( 'state' ) ); ?>" maxlength="32" required></label>
								<label><?php esc_html_e( 'Website', 'lnkup-marketplace' ); ?><input type="url" name="website" value="<?php echo esc_attr( $get( 'website' ) ); ?>" placeholder="https://"></label>
								<label class="lnkup-start__wide"><?php esc_html_e( 'Short description', 'lnkup-marketplace' ); ?><textarea name="bio" rows="3"><?php echo esc_textarea( $get( 'bio' ) ); ?></textarea></label>
								<label><?php esc_html_e( 'Logo', 'lnkup-marketplace' ); ?><input type="file" name="logo" accept="image/*"></label>
								<?php if ( $v2 ) : ?>
									<label><?php esc_html_e( 'Cover image', 'lnkup-marketplace' ); ?><input type="file" name="cover" accept="image/*"></label>
									<label><?php esc_html_e( 'Instagram', 'lnkup-marketplace' ); ?><input type="text" name="instagram" value="<?php echo esc_attr( $get( 'instagram' ) ); ?>" placeholder="@yourbrand"></label>
									<label><?php esc_html_e( 'TikTok', 'lnkup-marketplace' ); ?><input type="text" name="tiktok" value="<?php echo esc_attr( $get( 'tiktok' ) ); ?>" placeholder="@yourbrand"></label>
									<label class="lnkup-start__wide"><?php esc_html_e( 'Cities you serve', 'lnkup-marketplace' ); ?><input type="text" name="service_cities" value="<?php echo esc_attr( $get( 'service_cities' ) ); ?>" placeholder="<?php esc_attr_e( 'Houston, Atlanta, Charlotte', 'lnkup-marketplace' ); ?>"></label>
									<label><?php esc_html_e( 'Event category', 'lnkup-marketplace' ); ?>
										<select name="category">
											<option value=""><?php esc_html_e( 'Choose a category', 'lnkup-marketplace' ); ?></option>
											<?php foreach ( LNKup_Organizer_Onboarding_Flow::category_options() as $cat_slug => $cat_name ) : ?>
												<option value="<?php echo esc_attr( $cat_slug ); ?>"<?php selected( $get( 'category' ), $cat_slug ); ?>><?php echo esc_html( $cat_name ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
								<?php endif; ?>
								<div class="lnkup-start__actions"><button type="submit" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Save organization', 'lnkup-marketplace' ); ?></button><span class="lnkup-start__msg" role="status" aria-live="polite"></span></div>
							</form>
						<?php elseif ( 'payouts' === $stage['key'] && 'complete' !== $stage['status'] && $flow ) : ?>
							<?php
							$payout_copy = array(
								'not_connected'    => __( 'Connect a payout account so vendor booth fees reach you. Vendors can still apply while you finish.', 'lnkup-marketplace' ),
								'setup_incomplete' => __( 'Finish payout setup to cash out vendor revenue.', 'lnkup-marketplace' ),
								'verifying'        => __( 'Stripe is reviewing your payout setup. This step completes when payouts are active.', 'lnkup-marketplace' ),
								'action_required'  => __( 'Stripe needs more information before payouts can be enabled.', 'lnkup-marketplace' ),
								'paused'           => __( 'Payouts are paused. Contact support so we can help.', 'lnkup-marketplace' ),
							);
							$payout_state = (string) $flow['payout']['state'];
							?>
							<div class="lnkup-start__payout" data-testid="lnkup-start-payout" data-payout-state="<?php echo esc_attr( $payout_state ); ?>">
								<span class="lnkup-badge"><?php echo esc_html( $flow['payout']['label'] ); ?></span>
								<p class="lnkup-muted"><?php echo esc_html( $payout_copy[ $payout_state ] ?? '' ); ?></p>
								<div class="lnkup-start__actions">
									<?php if ( 'verifying' !== $payout_state && 'paused' !== $payout_state ) : ?>
										<button type="button" class="lnkup-btn lnkup-btn--primary" data-start-payout><?php echo esc_html( 'not_connected' === $payout_state ? __( 'Set up payouts', 'lnkup-marketplace' ) : __( 'Finish payout setup', 'lnkup-marketplace' ) ); ?></button>
									<?php endif; ?>
									<a class="lnkup-btn" href="<?php echo esc_url( $stage['cta_url'] ); ?>"><?php esc_html_e( 'Open Money', 'lnkup-marketplace' ); ?></a>
									<button type="button" class="lnkup-btn lnkup-btn--ghost" data-start-skip="payouts"><?php esc_html_e( 'Do this later', 'lnkup-marketplace' ); ?></button>
									<span class="lnkup-start__msg" role="status" aria-live="polite"></span>
								</div>
							</div>
						<?php elseif ( 'payouts' === $stage['key'] && 'complete' !== $stage['status'] ) : ?>
							<a class="lnkup-btn" href="<?php echo esc_url( $stage['cta_url'] ); ?>"><?php echo esc_html( $stage['cta_label'] ); ?></a>
						<?php elseif ( 'first_event' === $stage['key'] ) : ?>
							<div class="lnkup-start__actions">
								<a class="lnkup-btn<?php echo 'complete' === $stage['status'] ? '' : ' lnkup-btn--primary'; ?>" href="<?php echo esc_url( $stage['cta_url'] ); ?>"><?php echo esc_html( $stage['cta_label'] ); ?></a>
								<a class="lnkup-btn" href="<?php echo esc_url( lnkup_organizer_view_url( 'events' ) ); ?>"><?php esc_html_e( 'Duplicate a past event', 'lnkup-marketplace' ); ?></a>
							</div>
							<?php
							$primary_format = $flow && $flow['state']['formats'] ? $flow['state']['formats'][0] : '';
							$route_note     = $primary_format ? (string) ( $flow['formats_routing'][ $primary_format ]['note'] ?? '' ) : '';
							?>
							<?php if ( '' !== $route_note && 'complete' !== $stage['status'] ) : ?>
								<p class="lnkup-muted" data-testid="lnkup-start-route-note"><?php echo esc_html( $route_note ); ?></p>
							<?php endif; ?>
							<div class="lnkup-start__setups" data-start-setups data-testid="lnkup-start-setups" aria-live="polite"></div>
						<?php elseif ( 'publication' === $stage['key'] ) : ?>
							<?php if ( $share && '' !== (string) $share['application_url'] ) : ?>
								<div class="lnkup-start__share" data-testid="lnkup-start-share">
									<label><?php esc_html_e( 'Your vendor apply link', 'lnkup-marketplace' ); ?>
										<span class="lnkup-start__copy"><input type="text" readonly value="<?php echo esc_attr( $share['application_url'] ); ?>" data-copy-source><button type="button" class="lnkup-btn" data-copy><?php esc_html_e( 'Copy link', 'lnkup-marketplace' ); ?></button></span>
									</label>
									<p class="lnkup-muted" data-testid="lnkup-start-embed-link">
										<?php
										$embed_href = class_exists( 'LNKup_Organizer_Marketing' ) && LNKup_Organizer_Marketing::enabled()
											? lnkup_organizer_view_url( 'marketing', array( 'om_tab' => 'embed' ) )
											: add_query_arg( array( 'event' => (int) get_post_meta( (int) $share['opportunity_id'], '_lnkup_event_id', true ), 'tab' => 'marketing' ), lnkup_page_url_helper( 'organizer-dashboard' ) );
										?>
										<a class="vos-link" href="<?php echo esc_url( $embed_href ); ?>"><?php esc_html_e( 'Get an Apply button for your website', 'lnkup-marketplace' ); ?></a> — <?php esc_html_e( 'embed codes, QR codes and tracked links live in Marketing.', 'lnkup-marketplace' ); ?>
									</p>
									<p class="lnkup-muted"><?php esc_html_e( 'Your organizer page:', 'lnkup-marketplace' ); ?> <a href="<?php echo esc_url( $share['organizer_url'] ); ?>"><?php echo esc_html( $share['organizer_url'] ); ?></a></p>
									<?php if ( $v2 ) : ?>
										<?php foreach ( self::published_tour_links( $user_id ) as $tour_link ) : ?>
											<p class="lnkup-muted"><?php echo esc_html( sprintf( /* translators: %s: tour name */ __( '%s public page:', 'lnkup-marketplace' ), $tour_link['title'] ) ); ?> <a href="<?php echo esc_url( $tour_link['url'] ); ?>"><?php echo esc_html( $tour_link['url'] ); ?></a></p>
										<?php endforeach; ?>
										<div class="lnkup-start__actions">
											<a class="lnkup-btn lnkup-btn--primary" href="<?php echo esc_url( lnkup_organizer_view_url( 'invites' ) ); ?>" data-testid="lnkup-start-invite"><?php esc_html_e( 'Invite vendors', 'lnkup-marketplace' ); ?></a>
											<a class="lnkup-btn" href="<?php echo esc_url( lnkup_organizer_view_url( 'vendors' ) ); ?>"><?php esc_html_e( 'Import your vendor list', 'lnkup-marketplace' ); ?></a>
										</div>
									<?php endif; ?>
								</div>
							<?php elseif ( $share ) : ?>
								<p class="lnkup-muted"><?php esc_html_e( 'Your event was published, but no vendor slot is open right now. Reopen a slot to get a shareable apply link.', 'lnkup-marketplace' ); ?></p>
							<?php else : ?>
								<a class="lnkup-btn" href="<?php echo esc_url( $stage['cta_url'] ); ?>"><?php echo esc_html( $stage['cta_label'] ); ?></a>
							<?php endif; ?>
						<?php elseif ( 'first_applications' === $stage['key'] ) : ?>
							<div class="lnkup-start__actions">
								<a class="lnkup-btn" href="<?php echo esc_url( lnkup_organizer_view_url( 'applications' ) ); ?>"><?php esc_html_e( 'Open Requests', 'lnkup-marketplace' ); ?></a>
								<a class="lnkup-btn" href="<?php echo esc_url( lnkup_organizer_view_url( 'vendors' ) ); ?>" data-testid="lnkup-start-import"><?php esc_html_e( 'Bring your vendors (CSV import)', 'lnkup-marketplace' ); ?></a>
								<a class="lnkup-btn" href="<?php echo esc_url( lnkup_organizer_view_url( 'invites' ) ); ?>"><?php esc_html_e( 'Invite vendors', 'lnkup-marketplace' ); ?></a>
							</div>
						<?php elseif ( 'first_paid_booking' === $stage['key'] ) : ?>
							<a class="lnkup-btn" href="<?php echo esc_url( lnkup_organizer_view_url( 'financial' ) ); ?>"><?php esc_html_e( 'Open Money', 'lnkup-marketplace' ); ?></a>
						<?php endif; ?>
					</li>
					<?php if ( $flow && 'organization' === $stage['key'] ) : ?>
						<?php echo self::render_start_format_step( $flow, ++$num ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
				<?php endforeach; ?>
			</ol>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * "What do you organize?" step: five format cards (multi-select). Saving stores the choice
	 * through PATCH /organizer/onboarding and routes the first-event step to Create Event or the
	 * Tours & Series wizard.
	 *
	 * @param array $flow LNKup_Organizer_Onboarding_Flow::for_user().
	 * @param int   $num  Step number.
	 */
	private static function render_start_format_step( array $flow, int $num ): string {
		$step = null;
		foreach ( $flow['steps'] as $s ) {
			if ( 'format' === $s['key'] ) {
				$step = $s;
			}
		}
		$status   = $step && $step['skipped'] ? 'skipped' : ( $step ? $step['status'] : 'not_started' );
		$labels   = array(
			'complete'    => __( 'Done', 'lnkup-marketplace' ),
			'not_started' => __( 'To do', 'lnkup-marketplace' ),
			'skipped'     => __( 'Skipped', 'lnkup-marketplace' ),
		);
		$selected = $flow['state']['formats'];
		ob_start();
		?>
		<li class="lnkup-start__stage is-<?php echo esc_attr( 'skipped' === $status ? 'not_started' : $status ); ?>" data-stage="format" data-testid="lnkup-start-stage-format">
			<div class="lnkup-start__stage-head">
				<span class="lnkup-start__num" aria-hidden="true"><?php echo esc_html( (string) $num ); ?></span>
				<div>
					<h3><?php esc_html_e( 'What do you organize?', 'lnkup-marketplace' ); ?></h3>
					<p class="lnkup-muted"><?php esc_html_e( 'Pick everything that applies. We set up the right tools — you can change this anytime.', 'lnkup-marketplace' ); ?></p>
				</div>
				<span class="lnkup-badge lnkup-start__status"><?php echo esc_html( $labels[ $status ] ?? $status ); ?></span>
			</div>
			<form class="lnkup-start__formats" data-start-formats>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Event formats', 'lnkup-marketplace' ); ?></legend>
					<?php foreach ( LNKup_Organizer_Onboarding_Flow::format_labels() as $key => $info ) : ?>
						<label class="lnkup-start__format<?php echo in_array( $key, $selected, true ) ? ' is-selected' : ''; ?>">
							<input type="checkbox" name="formats" value="<?php echo esc_attr( $key ); ?>"<?php checked( in_array( $key, $selected, true ) ); ?> data-testid="lnkup-start-format-<?php echo esc_attr( $key ); ?>">
							<strong><?php echo esc_html( $info['label'] ); ?></strong>
							<span class="lnkup-muted"><?php echo esc_html( $info['description'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<div class="lnkup-start__actions">
					<button type="submit" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Save and continue', 'lnkup-marketplace' ); ?></button>
					<?php if ( ! $selected ) : ?>
						<button type="button" class="lnkup-btn lnkup-btn--ghost" data-start-skip="format"><?php esc_html_e( 'Skip for now', 'lnkup-marketplace' ); ?></button>
					<?php endif; ?>
					<span class="lnkup-start__msg" role="status" aria-live="polite"></span>
				</div>
			</form>
		</li>
		<?php
		return (string) ob_get_clean();
	}

	private static function onboarding_v2(): bool {
		return class_exists( 'LNKup_Organizer_Onboarding_Flow' ) && LNKup_Organizer_Onboarding_Flow::enabled();
	}

	/**
	 * Compact Get started prompt for the Overview while guided setup is unfinished.
	 *
	 * @param int $user_id Organizer.
	 */
	private static function render_setup_banner( int $user_id ): string {
		$flow = LNKup_Organizer_Onboarding_Flow::for_user( $user_id );
		if ( $flow['is_complete'] || $flow['state']['dismissed'] || ! $flow['next_step'] ) {
			return '';
		}
		ob_start();
		?>
		<a class="lnkup-home-next lnkup-start-banner" href="<?php echo esc_url( $flow['resume_url'] ); ?>" data-testid="lnkup-start-banner">
			<span><strong><?php echo esc_html( sprintf( /* translators: %d: percent */ __( 'Setup %d%% complete', 'lnkup-marketplace' ), (int) $flow['percent'] ) ); ?></strong>
			<span class="lnkup-muted"><?php echo esc_html( sprintf( /* translators: %s: step */ __( 'Next: %s', 'lnkup-marketplace' ), $flow['next_step']['title'] ) ); ?></span></span>
			<span aria-hidden="true">→</span>
		</a>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Public pages of the organizer's published tours / series (for the share step).
	 *
	 * @param int $user_id Organizer.
	 * @return array<int, array{title:string,url:string}>
	 */
	private static function published_tour_links( int $user_id ): array {
		if ( ! class_exists( 'LNKup_Portfolio_Public' ) || ! class_exists( 'LNKup_Feature_Flags' ) || ! LNKup_Feature_Flags::portfolio( $user_id ) ) {
			return array();
		}
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'lnkup_tour',
				'post_status'    => 'publish',
				'posts_per_page' => 3,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_organizer_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		) as $tour_id ) {
			$url = LNKup_Portfolio_Public::tour_url( (int) $tour_id );
			if ( '' !== $url ) {
				$out[] = array( 'title' => get_the_title( $tour_id ), 'url' => $url );
			}
		}
		return $out;
	}

	/**
	 * Overview: next event, pending review, four totals, two recent requests.
	 *
	 * @return string
	 */
	private static function render_home() {
		if ( class_exists( 'LNKup_Organizer_Presentation' ) && class_exists( 'LNKup_OS_UI' ) ) {
			return self::render_home_os();
		}
		if ( LNKup_Feature_Flags::organizer_ops() && LNKup_Organizer_OS_Tables::ready( '2' ) ) {
			return self::render_home_v2();
		}
		$user_id = get_current_user_id();
		$events  = lnkup_count_posts_for_organizer( 'lnkup_event', $user_id );
		$pending = lnkup_count_posts_for_organizer(
			'lnkup_application',
			$user_id,
			array(
				array(
					'key'     => '_lnkup_application_status',
					'value'   => array( 'submitted', 'under_review' ),
					'compare' => 'IN',
				),
			)
		);
		$approved  = count( self::unpaid_ids( $user_id ) );
		$collected = lnkup_organizer_collected_booth_total( $user_id );
		$recent    = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 2,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_lnkup_organizer_user_id',
						'value' => (string) $user_id,
					),
					array(
						'key'     => '_lnkup_application_status',
						'value'   => array( 'submitted', 'under_review' ),
						'compare' => 'IN',
					),
				),
			)
		);
		$dash_url       = lnkup_page_url_helper( 'organizer-dashboard' );
		$checklist      = class_exists( 'LNKup_Organizer_Launch_Checklist' ) ? LNKup_Organizer_Launch_Checklist::get_checklist( $user_id ) : array();
		$show_checklist = $checklist && empty( $checklist['all_complete'] ) && empty( $checklist['dismissed'] );
		$payout_pending = self::checklist_item_incomplete( $checklist, 'payout_account' );
		$upcoming       = self::upcoming_events( $user_id, 10 );
		$next_event     = $upcoming ? $upcoming[0] : null;
		$missing_docs   = self::apps_missing_documents( $user_id, 25 );
		$unpublished    = class_exists( 'LNKup_Organizer_Onboarding' )
			? array_values( array_filter( $upcoming, static function ( $event ) {
				return ! LNKup_Organizer_Onboarding::event_has_open_slot( (int) $event->ID );
			} ) )
			: array();
		ob_start();
		?>
		<section class="lnkup-organizer-overview lnkup-organizer-section" id="overview">
			<?php echo self::render_home_welcome( $user_id, $next_event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo self::render_recruit_prompt( $upcoming ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo self::render_fill_slots_card( array_map( 'intval', wp_list_pluck( (array) $upcoming, 'ID' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php
			$activation = class_exists( 'LNKup_Organizer_Activation' ) ? LNKup_Organizer_Activation::for_user( $user_id ) : null;
			if ( $activation && ! $activation['is_activated'] ) :
				?>
				<a class="lnkup-home-next lnkup-start-banner<?php echo 0 === (int) $events ? ' lnkup-start-banner--new' : ''; ?>" href="<?php echo esc_url( lnkup_organizer_view_url( 'start' ) ); ?>" data-testid="lnkup-start-banner">
					<span><strong><?php echo 0 === (int) $events ? esc_html__( 'Get started: publish your first vendor opportunity', 'lnkup-marketplace' ) : esc_html( sprintf( /* translators: %d: percent */ __( 'Setup %d%% complete', 'lnkup-marketplace' ), (int) $activation['percent'] ) ); ?></strong>
					<?php if ( $activation['next_action'] ) : ?>
						<span class="lnkup-muted"><?php echo esc_html( sprintf( /* translators: %s: step */ __( 'Next: %s', 'lnkup-marketplace' ), $activation['next_action']['title'] ) ); ?></span>
					<?php endif; ?></span>
					<span aria-hidden="true">→</span>
				</a>
				<?php
			endif;
			// With guided setup v2 the Get started banner above is the only setup prompt.
			if ( $show_checklist && ! self::onboarding_v2() ) {
				echo LNKup_Shortcodes::render_organizer_launch_checklist( $checklist, $dash_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
			<?php if ( $pending > 0 || $approved > 0 || $missing_docs || $unpublished || ( $payout_pending && $events > 0 ) ) : ?>
				<div class="lnkup-home-block lnkup-organizer-attention" data-testid="lnkup-organizer-attention">
					<div class="lnkup-home-block__head">
						<h2 class="lnkup-panel__title"><?php esc_html_e( 'Action center', 'lnkup-marketplace' ); ?></h2>
					</div>
					<?php if ( $pending > 0 ) : ?>
						<a class="lnkup-home-next lnkup-home-next--row" href="<?php echo esc_url( lnkup_organizer_view_url( 'applications', array( 'filter' => 'pending', 'group' => 'pending' ) ) ); ?>">
							<span class="lnkup-status-dot lnkup-status-dot--warning" aria-hidden="true"></span>
							<span><?php echo esc_html( sprintf( _n( '%d vendor is waiting for review', '%d vendors are waiting for review', $pending, 'lnkup-marketplace' ), $pending ) ); ?></span>
							<span class="lnkup-home-next__go"><?php esc_html_e( 'Review', 'lnkup-marketplace' ); ?></span>
						</a>
					<?php endif; ?>
					<?php if ( $approved > 0 ) : ?>
						<div class="lnkup-home-next lnkup-home-next--row">
							<span class="lnkup-status-dot lnkup-status-dot--warning" aria-hidden="true"></span>
							<span><?php echo esc_html( sprintf( _n( '%d approved vendor has not paid yet', '%d approved vendors have not paid yet', $approved, 'lnkup-marketplace' ), $approved ) ); ?></span>
							<span class="lnkup-home-next__go">
								<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( lnkup_organizer_view_url( 'financial', array( 'fin_tab' => 'vendor-revenue' ) ) ); ?>"><?php esc_html_e( 'Payments', 'lnkup-marketplace' ); ?></a>
								<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="<?php echo esc_url( lnkup_organizer_view_url( 'applications', array( 'filter' => 'unpaid' ) ) ); ?>"><?php esc_html_e( 'View vendors', 'lnkup-marketplace' ); ?></a>
							</span>
						</div>
					<?php endif; ?>
					<?php if ( $missing_docs ) : ?>
						<a class="lnkup-home-next lnkup-home-next--row" href="<?php echo esc_url( lnkup_organizer_view_url( 'applications', array( 'filter' => 'missing_docs' ) ) ); ?>">
							<span class="lnkup-status-dot lnkup-status-dot--warning" aria-hidden="true"></span>
							<span><?php echo esc_html( sprintf( _n( '%d booked vendor is missing documents', '%d booked vendors are missing documents', count( $missing_docs ), 'lnkup-marketplace' ), count( $missing_docs ) ) ); ?></span>
							<span class="lnkup-home-next__go"><?php esc_html_e( 'Review docs', 'lnkup-marketplace' ); ?></span>
						</a>
					<?php endif; ?>
					<?php if ( $unpublished ) : ?>
						<a class="lnkup-home-next lnkup-home-next--row" href="<?php echo esc_url( add_query_arg( 'event', (int) $unpublished[0]->ID, $dash_url ) ); ?>">
							<span class="lnkup-status-dot lnkup-status-dot--warning" aria-hidden="true"></span>
							<span>
								<?php
								echo esc_html(
									1 === count( $unpublished )
										/* translators: %s: event title */
										? sprintf( __( '%s has no open vendor spots — vendors cannot apply yet', 'lnkup-marketplace' ), $unpublished[0]->post_title )
										/* translators: %d: number of events */
										: sprintf( __( '%d upcoming events have no open vendor spots', 'lnkup-marketplace' ), count( $unpublished ) )
								);
								?>
							</span>
							<span class="lnkup-home-next__go"><?php esc_html_e( 'Open spots', 'lnkup-marketplace' ); ?></span>
						</a>
					<?php endif; ?>
					<?php if ( $payout_pending && $events > 0 ) : ?>
						<a class="lnkup-home-next lnkup-home-next--row" href="<?php echo esc_url( lnkup_organizer_view_url( 'financial' ) ); ?>">
							<span class="lnkup-status-dot lnkup-status-dot--warning" aria-hidden="true"></span>
							<span><?php esc_html_e( 'Finish payout setup to cash out vendor revenue.', 'lnkup-marketplace' ); ?></span>
							<span class="lnkup-home-next__go"><?php esc_html_e( 'Set up', 'lnkup-marketplace' ); ?></span>
						</a>
					<?php endif; ?>
					<?php echo LNKup_Shortcodes::render_organizer_priority_module( array_slice( $missing_docs, 0, 5 ), $dash_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php endif; ?>
			<?php if ( 0 === $events ) : ?>
				<?php
				echo lnkup_empty_state(
					array(
						'title'     => __( 'No events yet', 'lnkup-marketplace' ),
						'desc'      => __( 'Launch your first event and LNKup creates the first vendor slot with it.', 'lnkup-marketplace' ),
						'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'launch_event' ) : __( 'Launch event', 'lnkup-marketplace' ),
						'cta_href'  => lnkup_organizer_view_url( 'create' ),
					)
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			<?php else : ?>
				<div class="lnkup-home-block lnkup-organizer-upcoming">
					<div class="lnkup-home-block__head">
						<h2 class="lnkup-panel__title"><?php esc_html_e( 'Upcoming', 'lnkup-marketplace' ); ?></h2>
						<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( lnkup_organizer_view_url( 'events' ) ); ?>"><?php esc_html_e( 'All events', 'lnkup-marketplace' ); ?></a>
					</div>
					<?php echo LNKup_Shortcodes::render_organizer_events( 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo LNKup_Shortcodes::render_organizer_readiness_row( $upcoming, $dash_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php endif; ?>
			<?php
			echo lnkup_stat_strip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					array( 'label' => __( 'Events', 'lnkup-marketplace' ), 'value' => (string) $events ),
					array( 'label' => __( 'Pending requests', 'lnkup-marketplace' ), 'value' => (string) $pending ),
					array( 'label' => __( 'Approved unpaid', 'lnkup-marketplace' ), 'value' => (string) $approved ),
					array( 'label' => __( 'Collected booth fees', 'lnkup-marketplace' ), 'value' => function_exists( 'lnkup_currency' ) ? lnkup_currency( $collected ) : (string) $collected ),
				),
				array(
					'id'     => 'lnkup-crm-stats',
					'testid' => 'lnkup-crm-stats',
				)
			);
			?>
			<?php echo self::render_quick_actions( $next_event, $dash_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo LNKup_Shortcodes::render_organizer_apps_snapshot( $recent, $dash_url, 2 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo self::render_recent_activity( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( $events > 0 ) : ?>
				<div class="lnkup-grid lnkup-grid--3" id="lnkup-crm-widgets-grid" aria-live="polite"></div>
				<?php
				if ( class_exists( 'LNKup_Weather_Render' ) ) {
					echo LNKup_Weather_Render::readiness_card_compact( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			<?php endif; ?>
			<?php
			if ( class_exists( 'LNKup_App_Promo' ) ) {
				echo LNKup_App_Promo::render_card( 'organizer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Organizer Home command center (Organizer Experience OS 2.0).
	 *
	 * Every count comes from LNKup_Organizer_Presentation and links to the filtered screen
	 * that shows the same number.
	 */
	private static function render_home_os(): string {
		$user_id   = get_current_user_id();
		$ui        = 'LNKup_OS_UI';
		$rows      = LNKup_Organizer_Presentation::portfolio_rows( $user_id );
		$active    = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return 'completed' !== $row['chronology'];
				}
			)
		);
		$pending   = LNKup_Organizer_Presentation::count_applications( $user_id, LNKup_Organizer_Presentation::PENDING_STATUSES );
		$unpaid    = LNKup_Organizer_Presentation::count_applications( $user_id, LNKup_Organizer_Presentation::HELD_STATUSES );
		$confirmed = LNKup_Organizer_Presentation::count_applications( $user_id, LNKup_Organizer_Presentation::CONFIRMED_STATUSES );
		$open      = 0;
		foreach ( $active as $row ) {
			$open += (int) $row['capacity']['open'];
		}
		$money   = LNKup_Organizer_Presentation::financial_summary( $user_id );
		$queue   = LNKup_Organizer_Presentation::action_queue( $user_id );
		$next    = $active ? $active[0] : null;
		$metrics = array(
			array( 'label' => __( 'Upcoming events', 'lnkup-marketplace' ), 'value' => (string) count( $active ), 'href' => lnkup_organizer_view_url( 'events', array( 'chronology' => 'active' ) ), 'key' => 'upcoming' ),
			array( 'label' => __( 'Applications to review', 'lnkup-marketplace' ), 'value' => (string) $pending, 'href' => lnkup_organizer_view_url( 'applications', array( 'filter' => 'pending' ) ), 'key' => 'pending', 'tone' => $pending ? 'warning' : '' ),
			array( 'label' => __( 'Approved, unpaid', 'lnkup-marketplace' ), 'value' => (string) $unpaid, 'href' => lnkup_organizer_view_url( 'applications', array( 'filter' => 'approved', 'group' => 'booking' ) ), 'key' => 'unpaid' ),
			array( 'label' => __( 'Confirmed vendors', 'lnkup-marketplace' ), 'value' => (string) $confirmed, 'hint' => __( 'Paid, all events', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'applications', array( 'filter' => 'paid', 'group' => 'confirmed' ) ), 'key' => 'confirmed' ),
			array( 'label' => __( 'Open spaces', 'lnkup-marketplace' ), 'value' => (string) $open, 'hint' => __( 'Across upcoming events', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'events', array( 'chronology' => 'active' ) ), 'key' => 'open' ),
			array( 'label' => __( 'Collected', 'lnkup-marketplace' ), 'value' => $ui::money( (float) $money['collected'] ), 'hint' => $money['label'], 'href' => lnkup_organizer_view_url( 'financial' ), 'key' => 'collected' ),
		);
		foreach ( $metrics as $i => $m ) {
			if ( empty( $m['tone'] ) ) {
				unset( $metrics[ $i ]['tone'] );
			}
		}
		ob_start();
		?>
		<section class="oos-home lnkup-organizer-overview lnkup-organizer-section" id="overview" data-testid="lnkup-overview-v2">
			<?php echo self::render_home_org_head( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo self::render_home_onboarding( $user_id, count( $rows ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( ! $rows ) : ?>
				<?php echo $ui::empty_state( __( 'Create your first event', 'lnkup-marketplace' ), __( 'Add the event and its booth types. Vendors apply, you approve, and they pay through LNKup.', 'lnkup-marketplace' ), __( 'Create event', 'lnkup-marketplace' ), lnkup_organizer_view_url( 'create' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php else : ?>
				<?php echo $ui::metrics( $metrics, 'lnkup-organizer-metrics' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
			<div class="vos-layout">
				<div class="vos-main">
					<?php if ( $next ) : ?>
						<?php echo self::render_home_spotlight( $next ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
					<?php echo self::render_home_action_center( $queue ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php if ( $rows ) : ?>
						<?php echo self::render_home_portfolio( $active ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo self::render_home_recent_applications( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
				</div>
				<aside class="vos-rail" aria-label="<?php esc_attr_e( 'Summary', 'lnkup-marketplace' ); ?>">
					<?php if ( $rows ) : ?>
						<?php echo self::render_home_money( $money ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
					<?php echo $next ? self::render_home_recruitment( (int) $next['id'] ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo self::render_home_activity( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo self::render_home_quick_actions( $next ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php
					if ( class_exists( 'LNKup_App_Promo' ) ) {
						echo LNKup_App_Promo::render_card( 'organizer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					?>
				</aside>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Organization header: logo, name, verification, Create event.
	 *
	 * @param int $user_id Organizer.
	 */
	private static function render_home_org_head( int $user_id ): string {
		$card     = function_exists( 'lnkup_dashboard_organizer_user_card' ) ? lnkup_dashboard_organizer_user_card() : array();
		$name     = (string) get_user_meta( $user_id, '_lnkup_organizer_public_name', true );
		$name     = '' !== $name ? $name : (string) wp_get_current_user()->display_name;
		$name     = function_exists( 'lnkup_plain_text' ) ? lnkup_plain_text( $name ) : $name;
		$logo     = (string) ( $card['logo'] ?? '' );
		$verified = class_exists( 'LNKup_Organizer_Verification' ) ? (bool) LNKup_Organizer_Verification::is_verified( $user_id ) : false;
		$public   = class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::organizer_public_url( $user_id ) : '';
		$actions  = LNKup_OS_UI::menu(
			__( 'New', 'lnkup-marketplace' ),
			self::new_menu_items(),
			array( 'class' => 'lnkup-btn lnkup-btn--outline', 'testid' => 'lnkup-home-new-menu' )
		);
		$actions .= '<a class="lnkup-btn lnkup-btn--primary" href="' . esc_url( lnkup_organizer_view_url( 'create' ) ) . '">' . esc_html__( 'Create event', 'lnkup-marketplace' ) . '</a>';
		ob_start();
		?>
		<header class="oos-org-head" data-testid="lnkup-organizer-home-welcome">
			<div class="oos-org-head__id">
				<span class="oos-org-head__logo" aria-hidden="true"><?php echo $logo ? '<img src="' . esc_url( $logo ) . '" alt="" width="52" height="52" decoding="async" />' : esc_html( LNKup_OS_UI::initials( $name ) ); ?></span>
				<div>
					<p class="vos-eyebrow"><?php esc_html_e( 'Welcome back', 'lnkup-marketplace' ); ?></p>
					<h1 class="oos-org-head__name"><?php echo esc_html( $name ); ?></h1>
					<p class="oos-org-head__meta">
						<?php echo $verified ? LNKup_OS_UI::status( __( 'Verified organizer', 'lnkup-marketplace' ), 'success' ) : '<a class="vos-link" href="' . esc_url( lnkup_organizer_view_url( 'team' ) ) . '">' . esc_html__( 'Get verified', 'lnkup-marketplace' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( $public ) : ?>
							<a class="vos-link" href="<?php echo esc_url( $public ); ?>"><?php esc_html_e( 'Public page', 'lnkup-marketplace' ); ?></a>
						<?php endif; ?>
					</p>
				</div>
			</div>
			<div class="oos-org-head__actions"><?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		</header>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Items for the unified "New" menu.
	 *
	 * @return array<int,array{label:string,href?:string,hint?:string,attrs?:string}>
	 */
	private static function new_menu_items(): array {
		$items = array(
			array( 'label' => __( 'Single event', 'lnkup-marketplace' ), 'hint' => __( 'Event and its booth types', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'create' ) ),
			array( 'label' => __( 'Duplicate an event', 'lnkup-marketplace' ), 'hint' => __( 'New date and location required', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'events', array( 'duplicate' => 1 ) ) ),
			array( 'label' => __( 'From a saved setup', 'lnkup-marketplace' ), 'hint' => __( 'Reuse booth types and questions', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'start', array( 'step' => 'first_event' ) ) . '#lnkup-start-setups' ),
		);
		if ( class_exists( 'LNKup_Feature_Flags' ) && LNKup_Feature_Flags::portfolio() ) {
			$items[] = array( 'label' => __( 'Tour', 'lnkup-marketplace' ), 'hint' => __( 'Several cities, one brand', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'tours', array( 'new' => 'tour' ) ) );
			$items[] = array( 'label' => __( 'Recurring market', 'lnkup-marketplace' ), 'hint' => __( 'Same venue on a schedule', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'tours', array( 'new' => 'series' ) ) );
		}
		return $items;
	}

	/**
	 * Setup progress shared by Home and Settings: guided flow when onboarding v2 is on, else activation.
	 *
	 * @param int $user_id Organizer.
	 * @return array{percent:int,title:string,url:string}|null Null when setup is complete or dismissed.
	 */
	private static function setup_progress( int $user_id ): ?array {
		if ( self::onboarding_v2() ) {
			$flow = LNKup_Organizer_Onboarding_Flow::for_user( $user_id );
			if ( $flow['is_complete'] || $flow['state']['dismissed'] || ! $flow['next_step'] ) {
				return null;
			}
			return array(
				'percent' => (int) $flow['percent'],
				'title'   => (string) $flow['next_step']['title'],
				'url'     => (string) $flow['resume_url'],
			);
		}
		if ( ! class_exists( 'LNKup_Organizer_Activation' ) ) {
			return null;
		}
		$activation = LNKup_Organizer_Activation::for_user( $user_id );
		if ( $activation['is_activated'] ) {
			return null;
		}
		return array(
			'percent' => (int) $activation['percent'],
			'title'   => $activation['next_action'] ? (string) $activation['next_action']['title'] : '',
			'url'     => lnkup_organizer_view_url( 'start' ),
		);
	}

	/**
	 * Compact Getting Started module: the next unfinished step only. Hidden when complete.
	 *
	 * @param int $user_id     Organizer.
	 * @param int $event_count Owned events.
	 */
	private static function render_home_onboarding( int $user_id, int $event_count ): string {
		$setup = self::setup_progress( $user_id );
		if ( ! $setup ) {
			return '';
		}
		$percent = $setup['percent'];
		$title   = $setup['title'];
		$url     = $setup['url'];
		ob_start();
		?>
		<div class="oos-onboarding lnkup-start-banner" data-testid="lnkup-start-banner">
			<div>
				<p><strong><?php echo esc_html( 0 === $event_count ? __( 'Get set up to book vendors', 'lnkup-marketplace' ) : sprintf( /* translators: %d: percent */ __( 'Setup %d%% complete', 'lnkup-marketplace' ), $percent ) ); ?></strong></p>
				<?php if ( '' !== $title ) : ?>
					<p class="lnkup-muted"><?php echo esc_html( sprintf( /* translators: %s: step */ __( 'Next: %s', 'lnkup-marketplace' ), $title ) ); ?></p>
				<?php endif; ?>
			</div>
			<?php echo LNKup_OS_UI::progress( $percent, __( 'Setup progress', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Continue setup', 'lnkup-marketplace' ); ?></a>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Lifecycle-aware primary action for one event.
	 *
	 * @param array $event Event summary.
	 * @return array{label:string,href:string}
	 */
	public static function event_primary_action( array $event ): array {
		$dash = lnkup_page_url_helper( 'organizer-dashboard' );
		$base = add_query_arg( 'event', (int) $event['id'], $dash );
		if ( 'completed' === $event['chronology'] ) {
			return array( 'label' => __( 'View results', 'lnkup-marketplace' ), 'href' => add_query_arg( 'tab', 'money', $base ) );
		}
		if ( (int) $event['capacity']['booth_types'] < 1 ) {
			return array( 'label' => __( 'Add booth types', 'lnkup-marketplace' ), 'href' => add_query_arg( 'tab', 'inventory', $base ) );
		}
		if ( 'live' !== $event['publication'] ) {
			return array( 'label' => __( 'Publish event', 'lnkup-marketplace' ), 'href' => add_query_arg( 'edit', 1, $base ) );
		}
		if ( (int) $event['applications']['pending'] > 0 ) {
			return array(
				/* translators: %d: applications */
				'label' => sprintf( _n( 'Review %d application', 'Review %d applications', (int) $event['applications']['pending'], 'lnkup-marketplace' ), (int) $event['applications']['pending'] ),
				'href'  => add_query_arg( 'tab', 'applications', $base ),
			);
		}
		if ( 'today' === $event['chronology'] || ( null !== $event['days_until'] && (int) $event['days_until'] <= 1 ) ) {
			return array( 'label' => __( 'Open check-in', 'lnkup-marketplace' ), 'href' => add_query_arg( 'scan', 1, $base ) );
		}
		if ( (int) $event['capacity']['held'] > 0 ) {
			return array( 'label' => __( 'Follow up on unpaid vendors', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'applications', array( 'filter' => 'unpaid', 'event_filter' => (int) $event['id'] ) ) );
		}
		return array( 'label' => __( 'Invite vendors', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'vendors', array( 'recruit' => (int) $event['id'] ) ) );
	}

	/**
	 * Next-event spotlight.
	 *
	 * @param array $event Event summary.
	 */
	private static function render_home_spotlight( array $event ): string {
		$readiness = class_exists( 'LNKup_Vendor_Readiness' ) ? LNKup_Vendor_Readiness::event_readiness_summary( (int) $event['id'] ) : array();
		$action    = self::event_primary_action( $event );
		$days      = $event['days_until'];
		ob_start();
		?>
		<section class="vos-card oos-spotlight" data-testid="lnkup-home-spotlight" aria-labelledby="lnkup-home-spotlight-title">
			<h2 class="vos-sr" id="lnkup-home-spotlight-title"><?php esc_html_e( 'Next event', 'lnkup-marketplace' ); ?></h2>
			<div class="oos-spotlight__top">
				<?php echo LNKup_OS_UI::event_identity( $event, array( 'size' => 'lg', 'href' => $event['url'], 'heading' => 'h3' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( null !== $days ) : ?>
					<div class="oos-spotlight__countdown">
						<span class="oos-spotlight__days"><?php echo esc_html( 0 === (int) $days ? __( 'Today', 'lnkup-marketplace' ) : (string) (int) $days ); ?></span>
						<?php if ( 0 !== (int) $days ) : ?>
							<span class="lnkup-muted"><?php echo esc_html( _n( 'day to go', 'days to go', (int) $days, 'lnkup-marketplace' ) ); ?></span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
			<?php echo LNKup_OS_UI::capacity_bar( $event['capacity'], __( 'Vendor spaces', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<dl class="oos-spotlight__facts">
				<div>
					<dt><?php esc_html_e( 'Vendor readiness', 'lnkup-marketplace' ); ?></dt>
					<dd><?php echo empty( $readiness['vendor_count'] ) ? esc_html__( 'No booked vendors yet', 'lnkup-marketplace' ) : esc_html( sprintf( /* translators: 1: ready, 2: booked vendors */ __( '%1$d of %2$d booked ready', 'lnkup-marketplace' ), (int) $readiness['ready_count'], (int) $readiness['vendor_count'] ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Applications to review', 'lnkup-marketplace' ); ?></dt>
					<dd><?php echo esc_html( (string) (int) $event['applications']['pending'] ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Outstanding fees', 'lnkup-marketplace' ); ?></dt>
					<dd><?php echo esc_html( LNKup_OS_UI::money( (float) ( $event['money']['outstanding'] ?? 0 ) ) ); ?></dd>
				</div>
			</dl>
			<div class="oos-spotlight__actions">
				<a class="lnkup-btn lnkup-btn--primary" href="<?php echo esc_url( $action['href'] ); ?>" data-testid="lnkup-home-spotlight-action"><?php echo esc_html( $action['label'] ); ?></a>
				<a class="lnkup-btn lnkup-btn--outline" href="<?php echo esc_url( $event['url'] ); ?>"><?php esc_html_e( 'Open event', 'lnkup-marketplace' ); ?></a>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Action Center grouped by reason.
	 *
	 * @param array $queue LNKup_Organizer_Presentation::action_queue().
	 */
	private static function render_home_action_center( array $queue ): string {
		$groups = array_filter(
			$queue,
			static function ( $group ) {
				return $group['count'] > 0 && $group['rows'];
			}
		);
		ob_start();
		?>
		<section class="vos-card lnkup-organizer-attention" data-testid="lnkup-organizer-attention" aria-labelledby="lnkup-home-attention-title">
			<?php echo LNKup_OS_UI::section_head( __( 'Needs attention', 'lnkup-marketplace' ), '', '', 'lnkup-home-attention-title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p class="vos-sr"><?php esc_html_e( 'Action center', 'lnkup-marketplace' ); ?></p>
			<?php if ( ! $groups ) : ?>
				<p class="lnkup-muted"><?php esc_html_e( 'Nothing needs your attention right now.', 'lnkup-marketplace' ); ?></p>
			<?php else : ?>
				<div class="oos-actions">
					<?php foreach ( $groups as $key => $group ) : ?>
						<div class="oos-actions__group" data-attention="<?php echo esc_attr( $key ); ?>">
							<div class="oos-actions__head">
								<span><?php echo esc_html( $group['label'] ); ?></span>
								<span class="vos-count"><?php echo esc_html( (string) $group['count'] ); ?></span>
							</div>
							<?php foreach ( array_slice( $group['rows'], 0, 4 ) as $row ) : ?>
								<div class="oos-actions__row">
									<p class="oos-actions__row-title"><?php echo esc_html( $row['title'] ); ?></p>
									<p class="oos-actions__row-meta"><?php echo esc_html( $row['meta'] ); ?></p>
									<span class="oos-actions__row-cta">
										<?php foreach ( $row['actions'] as $i => $act ) : ?>
											<a class="lnkup-btn lnkup-btn--sm <?php echo 0 === $i ? 'lnkup-btn--outline' : 'lnkup-btn--ghost'; ?>" href="<?php echo esc_url( $act['href'] ); ?>"><?php echo esc_html( $act['label'] ); ?></a>
										<?php endforeach; ?>
									</span>
								</div>
							<?php endforeach; ?>
							<?php if ( $group['count'] > 4 ) : ?>
								<p class="oos-actions__more"><a class="vos-link" href="<?php echo esc_url( $group['href'] ); ?>"><?php echo esc_html( sprintf( /* translators: %d: total */ __( 'See all %d', 'lnkup-marketplace' ), (int) $group['count'] ) ); ?></a></p>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Portfolio snapshot table (upcoming events).
	 *
	 * @param array<int,array> $events Active event summaries.
	 */
	private static function render_home_portfolio( array $events ): string {
		$columns = array(
			'event'     => array( 'label' => __( 'Event', 'lnkup-marketplace' ), 'lead' => true ),
			'apps'      => array( 'label' => __( 'To review', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
			'confirmed' => array( 'label' => __( 'Confirmed', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
			'open'      => array( 'label' => __( 'Open spaces', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
			'collected' => array( 'label' => __( 'Gross booth fees', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
		);
		$rows    = array();
		foreach ( array_slice( $events, 0, 5 ) as $event ) {
			$rows[] = array(
				'id'    => $event['id'],
				'cells' => array(
					'event'     => LNKup_OS_UI::event_identity( $event, array( 'href' => $event['url'], 'status' => false ) ),
					'apps'      => esc_html( (string) $event['applications']['pending'] ),
					'confirmed' => esc_html( (string) $event['capacity']['confirmed'] ),
					'open'      => esc_html( (string) $event['capacity']['open'] ),
					'collected' => esc_html( LNKup_OS_UI::money( (float) ( $event['money']['collected_gross'] ?? 0 ) ) ),
				),
			);
		}
		ob_start();
		?>
		<section class="vos-section" data-testid="lnkup-overview-upcoming" aria-labelledby="lnkup-home-upcoming-title">
			<?php echo LNKup_OS_UI::section_head( __( 'Upcoming events', 'lnkup-marketplace' ), __( 'All events', 'lnkup-marketplace' ), lnkup_organizer_view_url( 'events' ), 'lnkup-home-upcoming-title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php
			echo LNKup_OS_UI::data_table( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$columns,
				$rows,
				array(
					'caption' => __( 'Upcoming events', 'lnkup-marketplace' ),
					'empty'   => LNKup_OS_UI::empty_state( __( 'No upcoming events', 'lnkup-marketplace' ), __( 'Past events are on the Events page.', 'lnkup-marketplace' ), __( 'Create event', 'lnkup-marketplace' ), lnkup_organizer_view_url( 'create' ) ),
				)
			);
			?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Five most recent applications across events.
	 *
	 * @param int $user_id Organizer.
	 */
	private static function render_home_recent_applications( int $user_id ): string {
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 5,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_key'       => '_lnkup_organizer_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( ! $apps ) {
			return '';
		}
		$labels = array(
			'submitted'        => array( __( 'New', 'lnkup-marketplace' ), 'info' ),
			'under_review'     => array( __( 'In review', 'lnkup-marketplace' ), 'info' ),
			'info_requested'   => array( __( 'Info requested', 'lnkup-marketplace' ), 'warning' ),
			'approved'         => array( __( 'Approved, unpaid', 'lnkup-marketplace' ), 'warning' ),
			'payment_required' => array( __( 'Approved, unpaid', 'lnkup-marketplace' ), 'warning' ),
			'paid'             => array( __( 'Confirmed', 'lnkup-marketplace' ), 'success' ),
			'completed'        => array( __( 'Completed', 'lnkup-marketplace' ), 'success' ),
			'waitlisted'       => array( __( 'Waitlisted', 'lnkup-marketplace' ), 'neutral' ),
			'rejected'         => array( __( 'Declined', 'lnkup-marketplace' ), 'danger' ),
			'cancelled'        => array( __( 'Cancelled', 'lnkup-marketplace' ), 'danger' ),
		);
		$rows   = array();
		foreach ( $apps as $app ) {
			$status   = (string) get_post_meta( $app->ID, '_lnkup_application_status', true );
			$event_id = (int) get_post_meta( $app->ID, '_lnkup_event_id', true );
			$label    = $labels[ $status ] ?? array( ucwords( str_replace( '_', ' ', $status ) ), 'neutral' );
			$rows[]   = array(
				'id'    => $app->ID,
				'cells' => array(
					'vendor' => '<a class="vos-table__primary" href="' . esc_url( lnkup_organizer_view_url( 'applications', array( 'event_filter' => $event_id, 'app' => $app->ID ) ) ) . '">' . esc_html( LNKup_Organizer_Presentation::vendor_name( (int) $app->ID ) ) . '</a>',
					'event'  => esc_html( $event_id ? LNKup_Organizer_Presentation::title( $event_id ) : '' ),
					'status' => LNKup_OS_UI::status( $label[0], $label[1] ),
					'when'   => LNKup_OS_UI::relative_time( (int) get_post_time( 'U', false, $app ) ),
				),
			);
		}
		ob_start();
		?>
		<section class="vos-section" data-testid="lnkup-home-applications" aria-labelledby="lnkup-home-apps-title">
			<?php echo LNKup_OS_UI::section_head( __( 'Recent applications', 'lnkup-marketplace' ), __( 'All applications', 'lnkup-marketplace' ), lnkup_organizer_view_url( 'applications' ), 'lnkup-home-apps-title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php
			echo LNKup_OS_UI::data_table( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'vendor' => array( 'label' => __( 'Vendor', 'lnkup-marketplace' ), 'lead' => true ),
					'event'  => array( 'label' => __( 'Event', 'lnkup-marketplace' ) ),
					'status' => array( 'label' => __( 'Status', 'lnkup-marketplace' ) ),
					'when'   => array( 'label' => __( 'Applied', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
				),
				$rows,
				array( 'caption' => __( 'Recent applications', 'lnkup-marketplace' ) )
			);
			?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Financial snapshot: the same figures and labels as Money.
	 *
	 * @param array $money LNKup_Organizer_Presentation::financial_summary().
	 */
	private static function render_home_money( array $money ): string {
		ob_start();
		?>
		<section class="vos-card" data-testid="lnkup-home-money" aria-labelledby="lnkup-home-money-title">
			<?php echo LNKup_OS_UI::section_head( __( 'Money', 'lnkup-marketplace' ), __( 'Open Money', 'lnkup-marketplace' ), lnkup_organizer_view_url( 'financial' ), 'lnkup-home-money-title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<dl class="vos-kv">
				<dt><?php esc_html_e( 'Collected', 'lnkup-marketplace' ); ?></dt>
				<dd data-money="collected"><?php echo esc_html( LNKup_OS_UI::money( (float) $money['collected'] ) ); ?></dd>
				<dt><?php esc_html_e( 'Refunds', 'lnkup-marketplace' ); ?></dt>
				<dd><?php echo esc_html( LNKup_OS_UI::money( (float) $money['refunds'] ) ); ?></dd>
				<dt><?php esc_html_e( 'Available to cash out', 'lnkup-marketplace' ); ?></dt>
				<dd><?php echo null === $money['available'] ? esc_html__( 'Not synced yet', 'lnkup-marketplace' ) : esc_html( LNKup_OS_UI::money( (float) $money['available'] ) ); ?></dd>
				<dt><?php esc_html_e( 'Pending', 'lnkup-marketplace' ); ?></dt>
				<dd><?php echo null === $money['pending'] ? esc_html__( 'Not synced yet', 'lnkup-marketplace' ) : esc_html( LNKup_OS_UI::money( (float) $money['pending'] ) ); ?></dd>
			</dl>
			<p class="vos-footnote"><?php echo esc_html( $money['label'] . '. ' . __( 'Same figures as Money → Overview.', 'lnkup-marketplace' ) ); ?></p>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Open spaces per category for the spotlight event, each linking to Find Vendors.
	 *
	 * @param int $event_id Event ID.
	 */
	private static function render_home_recruitment( int $event_id ): string {
		if ( ! class_exists( 'LNKup_Event_Fulfillment' ) ) {
			return '';
		}
		$fill = LNKup_Event_Fulfillment::for_event( $event_id );
		if ( is_wp_error( $fill ) || ! is_array( $fill ) ) {
			return '';
		}
		$cats = array_values(
			array_filter(
				(array) ( $fill['categories'] ?? array() ),
				static function ( $cat ) {
					return ! empty( $cat['open'] ) && ( (int) $cat['remaining'] > 0 || ! empty( $cat['unlimited'] ) );
				}
			)
		);
		if ( ! $cats ) {
			return '';
		}
		ob_start();
		?>
		<section class="vos-card" data-testid="lnkup-home-recruit" aria-labelledby="lnkup-home-recruit-title">
			<?php echo LNKup_OS_UI::section_head( __( 'Spaces to fill', 'lnkup-marketplace' ), __( 'Find vendors', 'lnkup-marketplace' ), lnkup_organizer_view_url( 'vendors', array( 'recruit' => $event_id ) ), 'lnkup-home-recruit-title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p class="lnkup-muted"><?php echo esc_html( LNKup_Organizer_Presentation::title( $event_id ) ); ?></p>
			<ul class="oos-recruit">
				<?php foreach ( array_slice( $cats, 0, 6 ) as $cat ) : ?>
					<li>
						<span><?php echo esc_html( wp_specialchars_decode( (string) $cat['label'], ENT_QUOTES ) ); ?></span>
						<span class="oos-recruit__open"><?php echo esc_html( ! empty( $cat['unlimited'] ) ? __( 'Open', 'lnkup-marketplace' ) : sprintf( /* translators: %d: open spaces */ _n( '%d open', '%d open', (int) $cat['remaining'], 'lnkup-marketplace' ), (int) $cat['remaining'] ) ); ?></span>
						<a class="vos-link" href="<?php echo esc_url( lnkup_organizer_view_url( 'vendors', array( 'recruit' => $event_id, 'category' => wp_specialchars_decode( (string) $cat['label'], ENT_QUOTES ) ) ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: category */ __( 'Find %s vendors', 'lnkup-marketplace' ), wp_specialchars_decode( (string) $cat['label'], ENT_QUOTES ) ) ); ?>"><?php esc_html_e( 'Find', 'lnkup-marketplace' ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Activity timeline with relative times and the exact time in a title attribute.
	 *
	 * @param int $user_id Organizer.
	 */
	private static function render_home_activity( int $user_id ): string {
		$items = self::recent_activity_items( $user_id, 6 );
		if ( ! $items ) {
			return '';
		}
		ob_start();
		?>
		<section class="vos-card" data-testid="lnkup-organizer-recent-activity" aria-labelledby="lnkup-home-activity-title">
			<?php echo LNKup_OS_UI::section_head( __( 'Recent activity', 'lnkup-marketplace' ), '', '', 'lnkup-home-activity-title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<ol class="vos-timeline">
				<?php foreach ( $items as $item ) : ?>
					<li>
						<?php if ( $item['href'] ) : ?>
							<a href="<?php echo esc_url( $item['href'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
						<?php else : ?>
							<span><?php echo esc_html( $item['title'] ); ?></span>
						<?php endif; ?>
						<?php echo LNKup_OS_UI::relative_time( (int) $item['ts'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( $item['meta'] ) : ?>
							<span class="vos-timeline__meta"><?php echo esc_html( $item['meta'] ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Shortcuts for common jobs.
	 *
	 * @param array|null $next Next event summary.
	 */
	private static function render_home_quick_actions( ?array $next ): string {
		$dash  = lnkup_page_url_helper( 'organizer-dashboard' );
		$quick = array(
			array( 'label' => __( 'Review applications', 'lnkup-marketplace' ), 'url' => lnkup_organizer_view_url( 'applications', array( 'filter' => 'pending', 'group' => 'pending' ) ) ),
			array( 'label' => __( 'Invite vendors', 'lnkup-marketplace' ), 'url' => lnkup_organizer_view_url( 'invites' ) ),
		);
		if ( $next ) {
			$quick[] = array( 'label' => __( 'Open operations', 'lnkup-marketplace' ), 'url' => add_query_arg( array( 'event' => (int) $next['id'], 'ops' => 1 ), $dash ) );
			$quick[] = array( 'label' => __( 'Export contacts', 'lnkup-marketplace' ), 'url' => LNKup_Shortcodes::organizer_vendor_export_url( (int) $next['id'] ) );
		}
		ob_start();
		?>
		<nav class="vos-card" aria-label="<?php esc_attr_e( 'Quick actions', 'lnkup-marketplace' ); ?>" data-testid="lnkup-organizer-quick-actions">
			<?php echo LNKup_OS_UI::section_head( __( 'Shortcuts', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div class="vos-chips">
				<?php foreach ( $quick as $action ) : ?>
					<a class="vos-chip" href="<?php echo esc_url( $action['url'] ); ?>"><?php echo esc_html( $action['label'] ); ?></a>
				<?php endforeach; ?>
			</div>
		</nav>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Welcome header with the organizer's public name and logo.
	 *
	 * @param int          $user_id    Organizer user ID.
	 * @param WP_Post|null $next_event Next upcoming event.
	 * @return string
	 */
	private static function render_home_welcome( $user_id, $next_event ) {
		$name     = (string) get_user_meta( $user_id, '_lnkup_organizer_public_name', true );
		$name     = '' !== $name ? $name : (string) wp_get_current_user()->display_name;
		$logo_id  = (int) get_user_meta( $user_id, '_lnkup_organizer_logo_id', true );
		$logo_url = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '';
		$initial  = strtoupper( substr( trim( $name ), 0, 1 ) );
		if ( $next_event ) {
			$date = (string) get_post_meta( $next_event->ID, '_lnkup_event_date', true );
			/* translators: 1: event title, 2: date */
			$subtitle = sprintf( __( 'Next up: %1$s · %2$s', 'lnkup-marketplace' ), $next_event->post_title, $date ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : __( 'date TBD', 'lnkup-marketplace' ) );
		} else {
			$subtitle = __( 'What needs your attention across your events today.', 'lnkup-marketplace' );
		}
		ob_start();
		?>
		<header class="lnkup-page-header lnkup-home-welcome" data-testid="lnkup-organizer-home-welcome">
			<div class="lnkup-home-welcome__identity">
				<?php if ( $logo_url ) : ?>
					<img class="lnkup-home-welcome__logo" src="<?php echo esc_url( $logo_url ); ?>" alt="" width="48" height="48" decoding="async" />
				<?php else : ?>
					<span class="lnkup-home-welcome__logo lnkup-home-welcome__logo--initial" aria-hidden="true"><?php echo esc_html( $initial ?: 'L' ); ?></span>
				<?php endif; ?>
				<div class="lnkup-home-welcome__copy">
					<h1 class="lnkup-page-header__title lnkup-home-welcome__title">
						<?php
						/* translators: %s: organization name */
						echo esc_html( sprintf( __( 'Welcome back, %s', 'lnkup-marketplace' ), $name ) );
						?>
					</h1>
					<p class="lnkup-home-welcome__meta"><?php echo esc_html( $subtitle ); ?></p>
				</div>
			</div>
			<?php echo self::launch_event_button(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</header>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Latest vendor and money activity across the organizer's events (server-rendered).
	 *
	 * @param int $user_id Organizer user ID.
	 * @return string
	 */
	private static function render_recent_activity( $user_id ) {
		$items = self::recent_activity_items( $user_id, 6 );
		if ( ! $items ) {
			return '';
		}
		ob_start();
		?>
		<section class="lnkup-home-block" data-testid="lnkup-organizer-recent-activity">
			<div class="lnkup-home-block__head">
				<h2 class="lnkup-panel__title"><?php esc_html_e( 'Recent activity', 'lnkup-marketplace' ); ?></h2>
			</div>
			<ol class="lnkup-activity-feed">
				<?php foreach ( $items as $item ) : ?>
					<li class="lnkup-activity-feed__item">
						<span class="lnkup-priority-item__dot lnkup-priority-item__dot--info" aria-hidden="true"></span>
						<div class="lnkup-activity-feed__body">
							<?php if ( $item['href'] ) : ?>
								<a class="lnkup-activity-feed__title" href="<?php echo esc_url( $item['href'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
							<?php else : ?>
								<p class="lnkup-activity-feed__title"><?php echo esc_html( $item['title'] ); ?></p>
							<?php endif; ?>
							<?php if ( $item['meta'] ) : ?>
								<p class="lnkup-activity-feed__meta"><?php echo esc_html( $item['meta'] ); ?></p>
							<?php endif; ?>
						</div>
						<time class="lnkup-activity-feed__time" datetime="<?php echo esc_attr( gmdate( 'c', $item['ts'] ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: relative time */ __( '%s ago', 'lnkup-marketplace' ), human_time_diff( $item['ts'], current_time( 'timestamp' ) ) ) ); ?></time>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Merge CRM activity (vendor actions) and organizer financial activity, newest first.
	 *
	 * @param int $user_id Organizer user ID.
	 * @param int $limit   Max items.
	 * @return array<int, array{title:string,meta:string,href:string,ts:int}>
	 */
	private static function recent_activity_items( $user_id, $limit ) {
		global $wpdb;
		$labels = array(
			/* translators: %s: vendor name */
			'applied'         => __( '%s applied', 'lnkup-marketplace' ),
			/* translators: %s: vendor name */
			'approved'        => __( '%s was approved', 'lnkup-marketplace' ),
			/* translators: %s: vendor name */
			'rejected'        => __( '%s was declined', 'lnkup-marketplace' ),
			/* translators: %s: vendor name */
			'waitlisted'      => __( '%s was waitlisted', 'lnkup-marketplace' ),
			/* translators: %s: vendor name */
			'paid'            => __( '%s paid their booth fee', 'lnkup-marketplace' ),
			/* translators: %s: vendor name */
			'packet_unlocked' => __( 'Packet unlocked for %s', 'lnkup-marketplace' ),
			/* translators: %s: vendor name */
			'invite_sent'     => __( 'Invite sent to %s', 'lnkup-marketplace' ),
			/* translators: %s: vendor name */
			'rebooked'        => __( '%s accepted your invite', 'lnkup-marketplace' ),
			/* translators: %s: vendor name */
			'document_reviewed' => __( 'Document reviewed for %s', 'lnkup-marketplace' ),
			/* translators: %s: vendor name */
			'dispute_opened'  => __( '%s opened a payment dispute', 'lnkup-marketplace' ),
			/* translators: %s: vendor name */
			'review_submitted' => __( '%s left a review', 'lnkup-marketplace' ),
			/* translators: %s: vendor name */
			'info_requested'  => __( 'You asked %s for more info', 'lnkup-marketplace' ),
			/* translators: %s: vendor name */
			'info_responded'  => __( '%s replied to your question', 'lnkup-marketplace' ),
		);
		$items = array();
		if ( class_exists( 'LNKup_Organizer_CRM_Tables' ) ) {
			$table = LNKup_Organizer_CRM_Tables::activity_table();
			$types = array_keys( $labels );
			$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE organizer_user_id = %d AND activity_type IN ({$in}) ORDER BY created_at DESC LIMIT %d", array_merge( array( $user_id ), $types, array( $limit ) ) ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$vendor   = get_userdata( (int) $row['vendor_user_id'] );
				$event_id = (int) $row['event_id'];
				$name     = $vendor ? $vendor->display_name : __( 'A vendor', 'lnkup-marketplace' );
				$filter   = array( 'q' => $name );
				if ( $event_id ) {
					$filter['event_filter'] = $event_id;
				}
				$items[] = array(
					'title' => sprintf( $labels[ $row['activity_type'] ], $name ),
					'meta'  => $event_id ? get_the_title( $event_id ) : '',
					'href'  => lnkup_organizer_view_url( 'applications', $filter ),
					'ts'    => (int) strtotime( (string) $row['created_at'] ),
				);
			}
		}
		if ( class_exists( 'LNKup_Financial_Activity' ) ) {
			foreach ( LNKup_Financial_Activity::list_for_user( $user_id, 'organizer', $limit ) as $row ) {
				$amount  = isset( $row['amount'] ) && null !== $row['amount'] && function_exists( 'lnkup_currency' ) ? lnkup_currency( (float) $row['amount'] ) : '';
				$items[] = array(
					'title' => (string) ( $row['label'] ?? '' ),
					'meta'  => trim( implode( ' · ', array_filter( array( $amount, (string) ( $row['event_name'] ?? '' ) ) ) ) ),
					'href'  => lnkup_organizer_view_url( 'financial' ),
					'ts'    => (int) strtotime( (string) ( $row['created_at'] ?? '' ) ),
				);
			}
		}
		$items = array_filter(
			$items,
			static function ( $item ) {
				return '' !== $item['title'] && $item['ts'] > 0;
			}
		);
		usort(
			$items,
			static function ( $a, $b ) {
				return $b['ts'] <=> $a['ts'];
			}
		);
		return array_slice( array_values( $items ), 0, $limit );
	}

	/**
	 * Shortcuts for the most common organizer jobs.
	 *
	 * @param WP_Post|null $next_event Next upcoming event, used for export and check-in.
	 * @param string       $dash_url   Dashboard URL.
	 * @return string
	 */
	/**
	 * Overview driven by LNKup_Organizer_Overview (organizer_ops_v2_enabled): what needs attention,
	 * how each upcoming event is tracking, recent vendor activity and quick actions.
	 *
	 * @return string
	 */
	private static function render_home_v2() {
		$user_id        = get_current_user_id();
		$dash_url       = lnkup_page_url_helper( 'organizer-dashboard' );
		$data           = LNKup_Organizer_Overview::for_user( $user_id );
		$checklist      = class_exists( 'LNKup_Organizer_Launch_Checklist' ) ? LNKup_Organizer_Launch_Checklist::get_checklist( $user_id ) : array();
		$show_checklist = $checklist && empty( $checklist['all_complete'] ) && empty( $checklist['dismissed'] );
		$upcoming_posts = self::upcoming_events( $user_id, 1 );
		$next_event     = $upcoming_posts ? $upcoming_posts[0] : null;
		$next_id        = $data['upcoming'] ? (int) $data['upcoming'][0]['event_id'] : 0;
		$quick          = array(
			array( 'label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'launch_event' ) : __( 'Create event', 'lnkup-marketplace' ), 'hint' => __( 'Event and first vendor slot', 'lnkup-marketplace' ), 'url' => lnkup_organizer_view_url( 'create' ) ),
			array( 'label' => __( 'Review applications', 'lnkup-marketplace' ), 'hint' => __( 'Every event in one queue', 'lnkup-marketplace' ), 'url' => add_query_arg( array( 'group' => 'pending', 'filter' => 'pending' ), lnkup_organizer_view_url( 'applications' ) ) ),
			array( 'label' => __( 'Invite vendors', 'lnkup-marketplace' ), 'hint' => __( 'From Discover or by email', 'lnkup-marketplace' ), 'url' => lnkup_organizer_view_url( 'invites' ) ),
		);
		if ( $next_id ) {
			$quick[] = array( 'label' => __( 'Send announcement', 'lnkup-marketplace' ), 'hint' => wp_trim_words( get_the_title( $next_id ), 5, '…' ), 'url' => add_query_arg( array( 'event' => $next_id, 'ops' => 1 ), $dash_url ) . '#broadcasts' );
			$quick[] = array( 'label' => __( 'Open operations', 'lnkup-marketplace' ), 'hint' => __( 'Booths, load-in, check-in', 'lnkup-marketplace' ), 'url' => add_query_arg( array( 'event' => $next_id, 'ops' => 1 ), $dash_url ) );
			$quick[] = array( 'label' => __( 'Add a task', 'lnkup-marketplace' ), 'hint' => __( 'Assign event prep to your team', 'lnkup-marketplace' ), 'url' => add_query_arg( array( 'event' => $next_id, 'tab' => 'tasks' ), $dash_url ) );
		}
		ob_start();
		?>
		<section class="lnkup-organizer-overview lnkup-organizer-section lnkup-overview-v2" id="overview" data-testid="lnkup-overview-v2">
			<?php echo self::render_home_welcome( $user_id, $next_event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php
			if ( self::onboarding_v2() ) {
				echo self::render_setup_banner( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( $show_checklist ) {
				echo LNKup_Shortcodes::render_organizer_launch_checklist( $checklist, $dash_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
			<div class="lnkup-home-block lnkup-overview-v2__attention" data-testid="lnkup-overview-attention">
				<div class="lnkup-home-block__head"><h2 class="lnkup-panel__title"><?php esc_html_e( 'Needs attention', 'lnkup-marketplace' ); ?></h2></div>
				<?php if ( ! $data['attention'] ) : ?>
					<p class="lnkup-muted"><?php esc_html_e( 'Nothing needs your attention right now.', 'lnkup-marketplace' ); ?></p>
				<?php else : ?>
					<?php foreach ( $data['attention'] as $row ) : ?>
						<a class="lnkup-home-next lnkup-home-next--row" href="<?php echo esc_url( $row['url'] ); ?>" data-attention="<?php echo esc_attr( $row['key'] ); ?>">
							<span class="lnkup-status-dot lnkup-status-dot--warning" aria-hidden="true"></span>
							<span><?php echo esc_html( $row['label'] ); ?></span>
							<span class="lnkup-home-next__go"><?php esc_html_e( 'Open', 'lnkup-marketplace' ); ?></span>
						</a>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<?php echo self::render_fill_slots_card( array_map( 'intval', wp_list_pluck( $data['upcoming'], 'event_id' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<div class="lnkup-home-block" data-testid="lnkup-overview-upcoming">
				<div class="lnkup-home-block__head"><h2 class="lnkup-panel__title"><?php esc_html_e( 'Upcoming events', 'lnkup-marketplace' ); ?></h2></div>
				<?php if ( ! $data['upcoming'] ) : ?>
					<?php
					echo lnkup_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						array(
							'title'     => __( 'No upcoming events', 'lnkup-marketplace' ),
							'desc'      => __( 'Create an event to start collecting vendor applications.', 'lnkup-marketplace' ),
							'cta_label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'launch_event' ) : __( 'Create event', 'lnkup-marketplace' ),
							'cta_href'  => lnkup_organizer_view_url( 'create' ),
						)
					);
					?>
				<?php else : ?>
					<div class="lnkup-overview-v2__events">
						<?php foreach ( $data['upcoming'] as $event ) : ?>
							<article class="lnkup-overview-v2__event">
								<header>
									<h3><a href="<?php echo esc_url( add_query_arg( 'event', $event['event_id'], $dash_url ) ); ?>"><?php echo esc_html( $event['title'] ); ?></a></h3>
									<span class="lnkup-muted">
										<?php
										echo esc_html(
											null === $event['days_until'] ? '' : ( 0 === (int) $event['days_until']
												? __( 'Today', 'lnkup-marketplace' )
												/* translators: %d: days */
												: sprintf( _n( 'In %d day', 'In %d days', (int) $event['days_until'], 'lnkup-marketplace' ), (int) $event['days_until'] ) )
										);
										?>
									</span>
								</header>
								<div class="lnkup-overview-v2__meter" role="progressbar" aria-label="<?php esc_attr_e( 'Event readiness', 'lnkup-marketplace' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $event['readiness_percent'] ); ?>"><span style="width:<?php echo esc_attr( (string) $event['readiness_percent'] ); ?>%"></span></div>
								<p class="lnkup-overview-v2__stats">
									<?php
									/* translators: 1: percent, 2: paid vendors, 3: capacity */
									echo esc_html( sprintf( __( '%1$d%% ready · %2$d of %3$d vendors confirmed', 'lnkup-marketplace' ), $event['readiness_percent'], $event['paid'], $event['capacity'] ) );
									?>
								</p>
								<?php if ( $event['next_action'] ) : ?>
									<a class="lnkup-overview-v2__next" href="<?php echo esc_url( $event['next_action']['action_url'] ); ?>">
										<?php
										/* translators: %s: what still needs doing, e.g. Missing: venue. */
										echo esc_html( sprintf( __( 'Next: %s', 'lnkup-marketplace' ), $event['next_action']['evidence'] ) );
										?>
									</a>
								<?php endif; ?>
								<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( $event['url'] ); ?>"><?php esc_html_e( 'Readiness', 'lnkup-marketplace' ); ?></a>
							</article>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<?php echo self::render_recent_activity( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<nav class="lnkup-organizer-quick-actions" aria-label="<?php esc_attr_e( 'Quick actions', 'lnkup-marketplace' ); ?>" data-testid="lnkup-organizer-quick-actions">
				<?php foreach ( $quick as $action ) : ?>
					<a class="lnkup-organizer-quick-actions__item" href="<?php echo esc_url( $action['url'] ); ?>">
						<strong><?php echo esc_html( $action['label'] ); ?></strong>
						<span class="lnkup-muted"><?php echo esc_html( $action['hint'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_quick_actions( $next_event, $dash_url ) {
		$actions = array(
			array(
				'label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'launch_event' ) : __( 'Launch event', 'lnkup-marketplace' ),
				'hint'  => __( 'Event and first vendor slot', 'lnkup-marketplace' ),
				'url'   => lnkup_organizer_view_url( 'create' ),
			),
			array(
				'label' => __( 'Invite vendors', 'lnkup-marketplace' ),
				'hint'  => __( 'From Discover or by email', 'lnkup-marketplace' ),
				'url'   => lnkup_organizer_view_url( 'invites' ),
			),
		);
		if ( $next_event ) {
			$actions[] = array(
				'label' => __( 'Export contacts', 'lnkup-marketplace' ),
				'hint'  => wp_trim_words( $next_event->post_title, 5, '…' ),
				'url'   => LNKup_Shortcodes::organizer_vendor_export_url( (int) $next_event->ID ),
			);
			$actions[] = array(
				'label' => __( 'Open check-in', 'lnkup-marketplace' ),
				'hint'  => __( 'Scan vendor QR passes', 'lnkup-marketplace' ),
				'url'   => add_query_arg( array( 'event' => (int) $next_event->ID, 'scan' => 1 ), $dash_url ),
			);
		}
		ob_start();
		?>
		<nav class="lnkup-organizer-quick-actions" aria-label="<?php esc_attr_e( 'Quick actions', 'lnkup-marketplace' ); ?>" data-testid="lnkup-organizer-quick-actions">
			<?php foreach ( $actions as $action ) : ?>
				<a class="lnkup-organizer-quick-actions__item" href="<?php echo esc_url( $action['url'] ); ?>">
					<strong><?php echo esc_html( $action['label'] ); ?></strong>
					<span class="lnkup-muted"><?php echo esc_html( $action['hint'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Whether a launch checklist item is still open.
	 *
	 * @param array  $checklist Checklist payload.
	 * @param string $key       Item key.
	 * @return bool
	 */
	private static function checklist_item_incomplete( $checklist, $key ) {
		foreach ( (array) ( $checklist['items'] ?? array() ) as $item ) {
			if ( ( $item['key'] ?? '' ) === $key ) {
				return LNKup_Organizer_Launch_Checklist::STATUS_COMPLETE !== ( $item['status'] ?? '' );
			}
		}
		return false;
	}

	/**
	 * Organizer events dated today or later, soonest first.
	 *
	 * @param int $user_id Organizer user ID.
	 * @param int $limit   Max events.
	 * @return WP_Post[]
	 */
	private static function upcoming_events( $user_id, $limit ) {
		return get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => (int) $limit,
				'meta_key'       => '_lnkup_event_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_lnkup_organizer_user_id',
						'value' => (string) $user_id,
					),
					array(
						'key'     => '_lnkup_event_date',
						'value'   => current_time( 'Y-m-d' ),
						'compare' => '>=',
						'type'    => 'DATE',
					),
				),
			)
		);
	}

	/**
	 * Booked applications whose documents are pending or missing.
	 *
	 * @param int $user_id Organizer user ID.
	 * @param int $scan    How many recent booked applications to check.
	 * @return WP_Post[]
	 */
	private static function apps_missing_documents( $user_id, $scan ) {
		if ( ! class_exists( 'LNKup_Verification' ) ) {
			return array();
		}
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => (int) $scan,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_lnkup_organizer_user_id',
						'value' => (string) $user_id,
					),
					array(
						'key'     => '_lnkup_application_status',
						'value'   => array( 'approved', 'payment_required', 'paid' ),
						'compare' => 'IN',
					),
				),
			)
		);
		return array_values(
			array_filter(
				$apps,
				static function ( $app ) {
					$context = LNKup_Verification::get_application_document_context( (int) $app->ID );
					return in_array( (string) ( $context['code'] ?? '' ), array( 'pending', 'missing' ), true );
				}
			)
		);
	}

	/**
	 * Page header for an organizer tab.
	 *
	 * @param string $title    Page title.
	 * @param string $subtitle One-line description.
	 * @param string $actions  Escaped action buttons HTML.
	 * @return string
	 */
	private static function page_header( $title, $subtitle = '', $actions = '' ) {
		return lnkup_page_header(
			array(
				'title'    => $title,
				'subtitle' => $subtitle,
				'actions'  => $actions,
			)
		);
	}

	/**
	 * Primary "Launch event" button.
	 *
	 * @return string
	 */
	private static function launch_event_button() {
		$label = function_exists( 'lnkup_copy' ) ? lnkup_copy( 'launch_event' ) : __( 'Launch event', 'lnkup-marketplace' );
		return '<a class="lnkup-btn lnkup-btn--primary" href="' . esc_url( lnkup_organizer_view_url( 'create' ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Events list plus public profile editor.
	 *
	 * @return string
	 */
	private static function render_events() {
		if ( class_exists( 'LNKup_Organizer_Presentation' ) && class_exists( 'LNKup_OS_UI' ) ) {
			return self::render_events_os();
		}
		$actions  = '<a class="lnkup-btn lnkup-btn--outline" href="' . esc_url( lnkup_organizer_view_url( 'profile' ) ) . '">' . esc_html__( 'Edit profile', 'lnkup-marketplace' ) . '</a>';
		$actions .= self::launch_event_button();
		ob_start();
		echo self::page_header( __( 'Events', 'lnkup-marketplace' ), __( 'Your live events, drafts, and past events.', 'lnkup-marketplace' ), $actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo LNKup_Shortcodes::organizer_section_card_open( 'events', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo LNKup_Shortcodes::render_organizer_events(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo LNKup_Shortcodes::organizer_section_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::render_tours(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return (string) ob_get_clean();
	}

	/**
	 * Events portfolio: filters, table (default above 6 events) or image grid, New menu.
	 * Rows come from LNKup_Organizer_Presentation::portfolio_rows().
	 */
	private static function render_events_os(): string {
		$user_id = get_current_user_id();
		$get     = static function ( string $key ): string {
			return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		};
		$filters = array(
			'publication'  => in_array( $get( 'publication' ), array( 'live', 'draft' ), true ) ? $get( 'publication' ) : '',
			'chronology'   => in_array( $get( 'chronology' ), array( 'active', 'upcoming', 'in_progress', 'completed' ), true ) ? $get( 'chronology' ) : '',
			'applications' => in_array( $get( 'applications' ), array( 'open', 'closed' ), true ) ? $get( 'applications' ) : '',
			'tour'         => (string) absint( $get( 'tour' ) ),
			'market'       => $get( 'market' ),
			'from'         => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $get( 'from' ) ) ? $get( 'from' ) : '',
			'to'           => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $get( 'to' ) ) ? $get( 'to' ) : '',
			'q'            => $get( 'q' ),
		);
		$filters['tour'] = '0' === $filters['tour'] ? '' : $filters['tour'];
		$all             = LNKup_Organizer_Presentation::portfolio_rows( $user_id );
		$args            = $filters;
		if ( 'active' === $args['chronology'] ) {
			$args['chronology'] = '';
		}
		$rows = LNKup_Organizer_Presentation::portfolio_rows( $user_id, $args );
		if ( 'active' === $filters['chronology'] ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return 'completed' !== $row['chronology'];
					}
				)
			);
		}
		$tours  = array();
		$places = array();
		foreach ( $all as $row ) {
			if ( $row['tour_id'] ) {
				$tours[ (int) $row['tour_id'] ] = $row['tour_title'];
			}
			if ( '' !== $row['place'] ) {
				$places[ $row['place'] ] = $row['place'];
			}
		}
		ksort( $places );
		$layout_default = count( $all ) > 6 ? 'table' : 'grid';
		$base           = lnkup_organizer_view_url( 'events' );
		$active_filters = array_filter( $filters );
		$actions        = LNKup_OS_UI::menu( __( 'New', 'lnkup-marketplace' ), self::new_menu_items(), array( 'class' => 'lnkup-btn lnkup-btn--outline', 'testid' => 'lnkup-events-new-menu' ) );
		$actions       .= '<a class="lnkup-btn lnkup-btn--primary" href="' . esc_url( lnkup_organizer_view_url( 'create' ) ) . '">' . esc_html__( 'Create event', 'lnkup-marketplace' ) . '</a>';
		$chron_tabs     = array(
			''            => __( 'All', 'lnkup-marketplace' ),
			'active'      => __( 'Upcoming & live', 'lnkup-marketplace' ),
			'in_progress' => __( 'Today', 'lnkup-marketplace' ),
			'completed'   => __( 'Completed', 'lnkup-marketplace' ),
		);
		$chron_counts   = array(
			''            => count( $all ),
			'active'      => 0,
			'in_progress' => 0,
			'completed'   => 0,
		);
		foreach ( $all as $row ) {
			if ( 'completed' === $row['chronology'] ) {
				$chron_counts['completed']++;
			} else {
				$chron_counts['active']++;
			}
			if ( 'today' === $row['chronology'] ) {
				$chron_counts['in_progress']++;
			}
		}
		ob_start();
		echo LNKup_OS_UI::page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'title'    => __( 'Events', 'lnkup-marketplace' ),
				'subtitle' => __( 'Every event you run, with applications, confirmed vendors, open spaces and fees in one place.', 'lnkup-marketplace' ),
				'actions'  => $actions,
				'testid'   => 'lnkup-events-header',
			)
		);
		if ( ! $all ) {
			echo LNKup_OS_UI::empty_state( __( 'Create your first event', 'lnkup-marketplace' ), __( 'Add the event and its booth types, then share the application link with vendors.', 'lnkup-marketplace' ), __( 'Create event', 'lnkup-marketplace' ), lnkup_organizer_view_url( 'create' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::render_tours(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return (string) ob_get_clean();
		}
		?>
		<section class="oos-filters" data-testid="lnkup-events-filters" aria-label="<?php esc_attr_e( 'Filter events', 'lnkup-marketplace' ); ?>">
			<nav class="vos-chips" aria-label="<?php esc_attr_e( 'When', 'lnkup-marketplace' ); ?>">
				<?php foreach ( $chron_tabs as $key => $label ) : ?>
					<?php $href = add_query_arg( array_filter( array_merge( $filters, array( 'chronology' => $key ) ) ), $base ); ?>
					<a class="vos-chip" href="<?php echo esc_url( $href ); ?>"<?php echo $filters['chronology'] === $key ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?> <span class="vos-chip__count"><?php echo esc_html( (string) $chron_counts[ $key ] ); ?></span></a>
				<?php endforeach; ?>
			</nav>
			<form class="vos-toolbar" method="get" action="<?php echo esc_url( lnkup_page_url_helper( 'organizer-dashboard' ) ); ?>" data-testid="lnkup-events-filter-form">
				<input type="hidden" name="view" value="events" />
				<?php if ( $filters['chronology'] ) : ?>
					<input type="hidden" name="chronology" value="<?php echo esc_attr( $filters['chronology'] ); ?>" />
				<?php endif; ?>
				<label class="vos-sr" for="lnkup-events-q"><?php esc_html_e( 'Search events', 'lnkup-marketplace' ); ?></label>
				<input type="search" id="lnkup-events-q" name="q" value="<?php echo esc_attr( $filters['q'] ); ?>" placeholder="<?php esc_attr_e( 'Search name, city or venue', 'lnkup-marketplace' ); ?>" />
				<label class="vos-sr" for="lnkup-events-publication"><?php esc_html_e( 'Publication', 'lnkup-marketplace' ); ?></label>
				<select id="lnkup-events-publication" name="publication">
					<option value=""><?php esc_html_e( 'Published and drafts', 'lnkup-marketplace' ); ?></option>
					<option value="live"<?php selected( $filters['publication'], 'live' ); ?>><?php esc_html_e( 'Published', 'lnkup-marketplace' ); ?></option>
					<option value="draft"<?php selected( $filters['publication'], 'draft' ); ?>><?php esc_html_e( 'Drafts', 'lnkup-marketplace' ); ?></option>
				</select>
				<label class="vos-sr" for="lnkup-events-applications"><?php esc_html_e( 'Applications', 'lnkup-marketplace' ); ?></label>
				<select id="lnkup-events-applications" name="applications">
					<option value=""><?php esc_html_e( 'Applications open or closed', 'lnkup-marketplace' ); ?></option>
					<option value="open"<?php selected( $filters['applications'], 'open' ); ?>><?php esc_html_e( 'Accepting applications', 'lnkup-marketplace' ); ?></option>
					<option value="closed"<?php selected( $filters['applications'], 'closed' ); ?>><?php esc_html_e( 'Not accepting applications', 'lnkup-marketplace' ); ?></option>
				</select>
				<?php if ( $tours ) : ?>
					<label class="vos-sr" for="lnkup-events-tour"><?php esc_html_e( 'Tour or series', 'lnkup-marketplace' ); ?></label>
					<select id="lnkup-events-tour" name="tour">
						<option value=""><?php esc_html_e( 'Any tour or series', 'lnkup-marketplace' ); ?></option>
						<?php foreach ( $tours as $tour_id => $tour_title ) : ?>
							<option value="<?php echo esc_attr( (string) $tour_id ); ?>"<?php selected( $filters['tour'], (string) $tour_id ); ?>><?php echo esc_html( $tour_title ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<label class="vos-sr" for="lnkup-events-market"><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?></label>
				<select id="lnkup-events-market" name="market">
					<option value=""><?php esc_html_e( 'Any city', 'lnkup-marketplace' ); ?></option>
					<?php foreach ( $places as $place ) : ?>
						<option value="<?php echo esc_attr( $place ); ?>"<?php selected( $filters['market'], $place ); ?>><?php echo esc_html( $place ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="vos-sr" for="lnkup-events-from"><?php esc_html_e( 'From date', 'lnkup-marketplace' ); ?></label>
				<input type="date" id="lnkup-events-from" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>" aria-label="<?php esc_attr_e( 'From date', 'lnkup-marketplace' ); ?>" />
				<label class="vos-sr" for="lnkup-events-to"><?php esc_html_e( 'To date', 'lnkup-marketplace' ); ?></label>
				<input type="date" id="lnkup-events-to" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>" aria-label="<?php esc_attr_e( 'To date', 'lnkup-marketplace' ); ?>" />
				<button type="submit" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm"><?php esc_html_e( 'Apply', 'lnkup-marketplace' ); ?></button>
				<?php if ( $active_filters ) : ?>
					<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Clear', 'lnkup-marketplace' ); ?></a>
				<?php endif; ?>
				<span class="vos-toolbar__spacer"></span>
				<span class="vos-segmented" role="group" aria-label="<?php esc_attr_e( 'Layout', 'lnkup-marketplace' ); ?>" data-oos-layout-toggle data-default="<?php echo esc_attr( $layout_default ); ?>">
					<button type="button" data-layout="table" aria-pressed="<?php echo 'table' === $layout_default ? 'true' : 'false'; ?>"><?php esc_html_e( 'Table', 'lnkup-marketplace' ); ?></button>
					<button type="button" data-layout="grid" aria-pressed="<?php echo 'grid' === $layout_default ? 'true' : 'false'; ?>"><?php esc_html_e( 'Grid', 'lnkup-marketplace' ); ?></button>
				</span>
			</form>
			<p class="lnkup-muted" role="status" data-testid="lnkup-events-count"><?php echo esc_html( sprintf( /* translators: 1: shown, 2: total */ __( 'Showing %1$d of %2$d events', 'lnkup-marketplace' ), count( $rows ), count( $all ) ) ); ?></p>
		</section>
		<?php
		if ( ! $rows ) {
			echo LNKup_OS_UI::empty_state( __( 'No events match these filters', 'lnkup-marketplace' ), __( 'Change a filter or clear them to see every event.', 'lnkup-marketplace' ), __( 'Clear filters', 'lnkup-marketplace' ), $base ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<div data-oos-layout="table"' . ( 'table' === $layout_default ? '' : ' hidden' ) . '>' . self::render_events_table( $rows ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div data-oos-layout="grid"' . ( 'grid' === $layout_default ? '' : ' hidden' ) . '>' . self::render_events_grid( $rows ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo self::render_duplicate_picker( $all ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo LNKup_Shortcodes::render_duplicate_event_dialog(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! LNKup_Feature_Flags::portfolio() ) {
			echo self::render_tours(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return (string) ob_get_clean();
	}

	/**
	 * Attributes the existing duplicate dialog reads (marketplace.js).
	 *
	 * @param array $row Event summary.
	 */
	private static function duplicate_attrs( array $row ): string {
		$id = (int) $row['id'];
		return 'data-duplicate-event="' . esc_attr( (string) $id ) . '" data-event-name="' . esc_attr( $row['title'] ) . '" data-city="' . esc_attr( (string) get_post_meta( $id, '_lnkup_city', true ) ) . '" data-state="' . esc_attr( (string) get_post_meta( $id, '_lnkup_state', true ) ) . '" data-venue="' . esc_attr( (string) $row['venue'] ) . '"';
	}

	/**
	 * Row overflow menu for one event.
	 *
	 * @param array $row Event summary.
	 */
	private static function event_row_menu( array $row ): string {
		$dash = lnkup_page_url_helper( 'organizer-dashboard' );
		$id   = (int) $row['id'];
		return LNKup_OS_UI::menu(
			__( 'More', 'lnkup-marketplace' ),
			array(
				array( 'label' => __( 'Edit event', 'lnkup-marketplace' ), 'href' => add_query_arg( array( 'event' => $id, 'edit' => 1 ), $dash ) ),
				array( 'label' => __( 'Duplicate', 'lnkup-marketplace' ), 'attrs' => self::duplicate_attrs( $row ) ),
				array( 'label' => __( 'Roster', 'lnkup-marketplace' ), 'href' => add_query_arg( array( 'event' => $id, 'roster' => 1 ), $dash ) ),
				array( 'label' => __( 'Operations', 'lnkup-marketplace' ), 'href' => add_query_arg( array( 'event' => $id, 'ops' => 1 ), $dash ) ),
				array( 'label' => __( 'Export contacts', 'lnkup-marketplace' ), 'href' => LNKup_Shortcodes::organizer_vendor_export_url( $id ) ),
			),
			array( 'class' => 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', 'aria_label' => sprintf( /* translators: %s: event */ __( 'More actions for %s', 'lnkup-marketplace' ), $row['title'] ) )
		);
	}

	/**
	 * Portfolio table.
	 *
	 * @param array<int,array> $rows Event summaries.
	 */
	private static function render_events_table( array $rows ): string {
		$columns = array(
			'event'     => array( 'label' => __( 'Event', 'lnkup-marketplace' ), 'lead' => true ),
			'date'      => array( 'label' => __( 'Date', 'lnkup-marketplace' ), 'class' => 'vos-nowrap' ),
			'place'     => array( 'label' => __( 'Place', 'lnkup-marketplace' ), 'class' => 'vos-nowrap' ),
			'tour'      => array( 'label' => __( 'Tour', 'lnkup-marketplace' ) ),
			'status'    => array( 'label' => __( 'Status', 'lnkup-marketplace' ) ),
			'apps'      => array( 'label' => __( 'Applications', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
			'confirmed' => array( 'label' => __( 'Confirmed', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
			'open'      => array( 'label' => __( 'Open spaces', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
			'collected' => array( 'label' => __( 'Gross booth fees', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
			'actions'   => array( 'label' => __( 'Actions', 'lnkup-marketplace' ), 'class' => 'vos-actions' ),
		);
		$out     = array();
		foreach ( $rows as $row ) {
			$pending = (int) $row['applications']['pending'];
			$apps    = esc_html( (string) $row['applications']['total'] );
			if ( $pending ) {
				$apps .= ' · <a class="vos-link" href="' . esc_url( add_query_arg( 'tab', 'applications', $row['url'] ) ) . '">' . esc_html( sprintf( /* translators: %d: to review */ __( '%d to review', 'lnkup-marketplace' ), $pending ) ) . '</a>';
			}
			$out[] = array(
				'id'    => $row['id'],
				'attrs' => 'data-chronology="' . esc_attr( $row['chronology'] ) . '" data-publication="' . esc_attr( $row['publication'] ) . '"',
				'cells' => array(
					'event'     => '<a class="vos-table__primary" href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['title'] ) . '</a>' . ( $row['venue'] ? '<span class="vos-table__sub">' . esc_html( $row['venue'] ) . '</span>' : '' ),
					'date'      => esc_html( '' !== $row['date'] ? (string) date_i18n( 'M j, Y', (int) strtotime( $row['date'] ) ) : __( 'No date', 'lnkup-marketplace' ) ),
					'place'     => esc_html( '' !== $row['place'] ? $row['place'] : '—' ),
					'tour'      => esc_html( '' !== $row['tour_title'] ? $row['tour_title'] : '—' ),
					'status'    => LNKup_OS_UI::status( $row['publication_label'], $row['publication_tone'] ) . ' ' . LNKup_OS_UI::status( $row['chronology_label'], $row['chronology_tone'] ),
					'apps'      => $apps,
					'confirmed' => esc_html( (string) $row['capacity']['confirmed'] ),
					'open'      => esc_html( (string) $row['capacity']['open'] ),
					'collected' => esc_html( LNKup_OS_UI::money( (float) ( $row['money']['collected_gross'] ?? 0 ) ) ),
					'actions'   => '<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="' . esc_url( $row['url'] ) . '">' . esc_html__( 'Open', 'lnkup-marketplace' ) . '</a> ' . self::event_row_menu( $row ),
				),
			);
		}
		return LNKup_OS_UI::data_table(
			$columns,
			$out,
			array(
				'caption' => __( 'Your events', 'lnkup-marketplace' ),
				'testid'  => 'lnkup-events-table',
			)
		);
	}

	/**
	 * Portfolio image grid.
	 *
	 * @param array<int,array> $rows Event summaries.
	 */
	private static function render_events_grid( array $rows ): string {
		$html = '<div class="oos-portfolio-grid" data-testid="lnkup-events-grid">';
		foreach ( $rows as $row ) {
			$html .= '<article class="oos-event-card" data-chronology="' . esc_attr( $row['chronology'] ) . '">';
			$html .= '<div class="oos-event-card__media">' . LNKup_OS_UI::event_image( (int) $row['id'], $row['title'] ) . LNKup_OS_UI::status( $row['publication_label'], $row['publication_tone'] ) . '</div>';
			$html .= '<div class="oos-event-card__body">';
			$html .= '<h3 class="oos-event-card__title"><a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['title'] ) . '</a></h3>';
			$html .= '<p class="oos-event-card__meta">' . esc_html( implode( ' · ', array_filter( array( $row['date_label'], $row['place'] ) ) ) ) . '</p>';
			$html .= LNKup_OS_UI::capacity_bar( $row['capacity'] );
			$html .= '<dl class="oos-event-card__stats"><div><dt>' . esc_html__( 'To review', 'lnkup-marketplace' ) . '</dt><dd>' . esc_html( (string) $row['applications']['pending'] ) . '</dd></div>';
			$html .= '<div><dt>' . esc_html__( 'Confirmed', 'lnkup-marketplace' ) . '</dt><dd>' . esc_html( (string) $row['capacity']['confirmed'] ) . '</dd></div>';
			$html .= '<div><dt>' . esc_html__( 'Gross fees', 'lnkup-marketplace' ) . '</dt><dd>' . esc_html( LNKup_OS_UI::money( (float) ( $row['money']['collected_gross'] ?? 0 ) ) ) . '</dd></div></dl>';
			$action = self::event_primary_action( $row );
			$html  .= '<div class="oos-event-card__actions"><a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="' . esc_url( $action['href'] ) . '">' . esc_html( $action['label'] ) . '</a>' . self::event_row_menu( $row ) . '</div>';
			$html  .= '</div></article>';
		}
		return $html . '</div>';
	}

	/**
	 * "Duplicate an event" picker opened from the New menu. Picking an event opens the
	 * existing duplicate dialog, which requires a date and a location.
	 *
	 * @param array<int,array> $rows All event summaries.
	 */
	private static function render_duplicate_picker( array $rows ): string {
		$items = '';
		foreach ( array_slice( $rows, 0, 60 ) as $row ) {
			$items .= '<li><button type="button" class="vos-menu__item" data-vos-close ' . self::duplicate_attrs( $row ) . '>' . esc_html( $row['title'] ) . '<span class="vos-menu__hint">' . esc_html( implode( ' · ', array_filter( array( $row['date_label'], $row['place'] ) ) ) ) . '</span></button></li>';
		}
		$body = '<p class="lnkup-muted">' . esc_html__( 'Choose the event to copy. Booth types, fees, documents and questions are copied. Vendors, applications and payments are not.', 'lnkup-marketplace' ) . '</p><ul class="oos-picker" style="list-style:none;margin:0;padding:0;display:grid;gap:4px">' . $items . '</ul>';
		return LNKup_OS_UI::dialog_shell( 'lnkup-duplicate-picker', __( 'Duplicate an event', 'lnkup-marketplace' ), $body, '', array( 'testid' => 'lnkup-duplicate-picker' ) );
	}

	/**
	 * Tours & series: group existing events as stops/occurrences. Each stop keeps its own
	 * applications, inventory and payments.
	 *
	 * @return string
	 */
	private static function render_tours() {
		if ( class_exists( 'LNKup_Feature_Flags' ) && LNKup_Feature_Flags::portfolio() ) {
			ob_start();
			?>
			<section class="lnkup-card lnkup-tours-manager" data-testid="lnkup-tours-cta">
				<h2><?php esc_html_e( 'Tours & series', 'lnkup-marketplace' ); ?></h2>
				<p class="lnkup-muted"><?php esc_html_e( 'Run a multi-city tour or a recurring market from one place: set booths, prices and questions once, then add every date.', 'lnkup-marketplace' ); ?></p>
				<a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="<?php echo esc_url( lnkup_organizer_view_url( 'tours' ) ); ?>"><?php esc_html_e( 'Open Tours & Series', 'lnkup-marketplace' ); ?></a>
			</section>
			<?php
			return (string) ob_get_clean();
		}
		if ( ! class_exists( 'LNKup_Match_Settings' ) || ! LNKup_Match_Settings::enabled( 'matching_tours_enabled' ) ) {
			return '';
		}
		$user_id = get_current_user_id();
		$tours   = LNKup_Tours::list_for_organizer( $user_id );
		$events  = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 100,
				'meta_key'       => '_lnkup_organizer_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		ob_start();
		?>
		<section class="lnkup-card lnkup-find-vendors lnkup-tours-manager" id="lnkup-tours-manager" data-testid="lnkup-tours-manager" data-tours-rest="<?php echo esc_url( rest_url( 'lnkup/v1/tours' ) ); ?>" aria-labelledby="lnkup-tours-title">
			<header class="lnkup-find-vendors__header">
				<h2 id="lnkup-tours-title"><?php esc_html_e( 'Tours & series', 'lnkup-marketplace' ); ?></h2>
				<p class="lnkup-muted"><?php esc_html_e( 'Group events into a multi-city tour or a recurring market. Vendors only get matched to the stops in their markets, and each stop keeps its own applications, booths and payments.', 'lnkup-marketplace' ); ?></p>
			</header>
			<?php if ( $tours ) : ?>
				<ul class="lnkup-tours-manager__list">
					<?php foreach ( $tours as $tour ) : ?>
						<li data-tour-id="<?php echo esc_attr( (string) $tour['id'] ); ?>">
							<strong><?php echo esc_html( $tour['title'] ); ?></strong>
							<span class="lnkup-muted"><?php echo esc_html( sprintf( _n( '%1$s · %2$d stop', '%1$s · %2$d stops', $tour['stop_count'], 'lnkup-marketplace' ), 'series' === $tour['type'] ? __( 'Recurring market', 'lnkup-marketplace' ) : __( 'Tour', 'lnkup-marketplace' ), $tour['stop_count'] ) ); ?></span>
							<?php if ( 'publish' === $tour['status'] ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'tour_id', (int) $tour['id'], lnkup_page_url_helper( 'opportunities' ) ) ); ?>"><?php esc_html_e( 'View vendor page', 'lnkup-marketplace' ); ?></a>
							<?php else : ?>
								<span class="lnkup-badge lnkup-badge--muted"><?php esc_html_e( 'Draft', 'lnkup-marketplace' ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( count( $events ) < 2 ) : ?>
				<p class="lnkup-muted"><?php esc_html_e( 'Create at least two events to group them into a tour or series.', 'lnkup-marketplace' ); ?></p>
			<?php else : ?>
				<form class="lnkup-form" id="lnkup-tour-form" data-testid="lnkup-tour-form">
					<div class="lnkup-grid lnkup-grid--2">
						<div class="lnkup-form-row">
							<label for="lnkup-tour-title"><?php esc_html_e( 'Name', 'lnkup-marketplace' ); ?></label>
							<input type="text" id="lnkup-tour-title" name="title" required maxlength="200" />
						</div>
						<div class="lnkup-form-row">
							<label for="lnkup-tour-type"><?php esc_html_e( 'Type', 'lnkup-marketplace' ); ?></label>
							<select id="lnkup-tour-type" name="type">
								<option value="tour"><?php esc_html_e( 'Multi-city tour', 'lnkup-marketplace' ); ?></option>
								<option value="series"><?php esc_html_e( 'Recurring market / series', 'lnkup-marketplace' ); ?></option>
							</select>
						</div>
					</div>
					<fieldset class="lnkup-form-row">
						<legend><?php esc_html_e( 'Stops (in order)', 'lnkup-marketplace' ); ?></legend>
						<div class="lnkup-chip-select">
							<?php foreach ( $events as $event ) : ?>
								<?php $in_tour = (int) get_post_meta( $event->ID, '_lnkup_tour_id', true ); ?>
								<label>
									<input type="checkbox" name="event_ids[]" value="<?php echo esc_attr( (string) $event->ID ); ?>" <?php disabled( $in_tour > 0 ); ?> />
									<span><?php echo esc_html( $event->post_title . ' — ' . trim( get_post_meta( $event->ID, '_lnkup_city', true ) . ' ' . get_post_meta( $event->ID, '_lnkup_event_date', true ) ) ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</fieldset>
					<button type="submit" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'Create tour', 'lnkup-marketplace' ); ?></button>
					<p class="lnkup-alert lnkup-alert--error" data-tour-error hidden></p>
				</form>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Tours & Series workspace shell. portfolio.js renders the portfolio list, the setup wizard and
	 * the tour dashboard from lnkup/v1/portfolio; the server only checks access and provides the
	 * mount point with a no-JS fallback.
	 *
	 * @return string
	 */
	private static function render_portfolio() {
		if ( ! class_exists( 'LNKup_Feature_Flags' ) || ! LNKup_Feature_Flags::portfolio() ) {
			return '<p class="lnkup-empty-state">' . esc_html__( 'Tours & Series is not available on your account yet.', 'lnkup-marketplace' ) . '</p>';
		}
		ob_start();
		echo self::page_header( __( 'Tours & Series', 'lnkup-marketplace' ), __( 'Multi-city tours, recurring markets and event series. Each date keeps its own applications, booths and payments.', 'lnkup-marketplace' ), '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div id="lnkup-portfolio" class="lnkup-portfolio" data-testid="lnkup-portfolio" aria-live="polite">
			<div class="lnkup-portfolio__loading" role="status">
				<span class="lnkup-skeleton lnkup-skeleton--line"></span>
				<span class="lnkup-skeleton lnkup-skeleton--line"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Loading your tours…', 'lnkup-marketplace' ); ?></span>
			</div>
			<noscript><p class="lnkup-alert lnkup-alert--error"><?php esc_html_e( 'Tours & Series needs JavaScript. Turn it on and reload the page.', 'lnkup-marketplace' ); ?></p></noscript>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Public profile editor: name, intro, links, logo, and cover.
	 *
	 * @return string
	 */
	private static function render_profile() {
		$user_id     = get_current_user_id();
		$profile_url = class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::organizer_public_url( (int) $user_id ) : '';
		$actions     = '';
		if ( $profile_url ) {
			$actions .= '<a class="lnkup-btn lnkup-btn--outline" href="' . esc_url( $profile_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View public profile', 'lnkup-marketplace' ) . '</a>';
			$actions .= '<button type="button" class="lnkup-btn lnkup-btn--outline" data-copy-url="' . esc_url( $profile_url ) . '">' . esc_html__( 'Copy link', 'lnkup-marketplace' ) . '</button>';
		}
		ob_start();
		echo self::page_header( __( 'Profile', 'lnkup-marketplace' ), __( 'What vendors see on your public organizer page.', 'lnkup-marketplace' ), $actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo LNKup_Shortcodes::organizer_section_card_open( 'profile', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::render_profile_form( $user_id, $profile_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo LNKup_Shortcodes::organizer_section_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return (string) ob_get_clean();
	}

	/**
	 * Organizer public profile form.
	 *
	 * @param int    $user_id     Organizer user ID.
	 * @param string $profile_url Public profile URL.
	 * @return string
	 */
	private static function render_profile_form( $user_id, $profile_url ) {
		$public_name = (string) get_user_meta( $user_id, '_lnkup_organizer_public_name', true );
		$public_bio  = (string) get_user_meta( $user_id, '_lnkup_organizer_bio', true );
		$public_tagline = (string) get_user_meta( $user_id, '_lnkup_organizer_tagline', true );
		$public_city = (string) get_user_meta( $user_id, '_lnkup_organizer_city', true );
		$public_state = (string) get_user_meta( $user_id, '_lnkup_organizer_state', true );
		$public_service = (string) get_user_meta( $user_id, '_lnkup_organizer_service_cities', true );
		$public_website = (string) get_user_meta( $user_id, '_lnkup_organizer_website', true );
		$public_instagram = (string) get_user_meta( $user_id, '_lnkup_organizer_instagram', true );
		$public_tiktok = (string) get_user_meta( $user_id, '_lnkup_organizer_tiktok', true );
		$public_slug = class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::organizer_public_slug( (int) $user_id ) : '';
		$logo_id = (int) get_user_meta( $user_id, '_lnkup_organizer_logo_id', true );
		$cover_id = (int) get_user_meta( $user_id, '_lnkup_organizer_cover_id', true );
		$preview_name = '' !== $public_name ? $public_name : wp_get_current_user()->display_name;
		$logo_url     = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '';
		$cover_url    = $cover_id ? (string) wp_get_attachment_image_url( $cover_id, 'large' ) : '';
		ob_start();
		?>
		<div class="lnkup-org-profile-preview" data-org-profile-preview aria-hidden="true">
			<div class="lnkup-org-profile-preview__cover" data-preview-cover<?php echo $cover_url ? ' style="background-image:url(' . esc_url( $cover_url ) . ')"' : ''; ?>></div>
			<div class="lnkup-org-profile-preview__body">
				<span class="lnkup-org-profile-preview__logo" data-preview-logo><?php if ( $logo_url ) : ?><img src="<?php echo esc_url( $logo_url ); ?>" alt="" /><?php else : ?><?php echo esc_html( strtoupper( substr( $preview_name, 0, 1 ) ) ); ?><?php endif; ?></span>
				<div>
					<p class="lnkup-org-profile-preview__name" data-preview-name><?php echo esc_html( $preview_name ); ?></p>
					<p class="lnkup-muted lnkup-org-profile-preview__tagline" data-preview-tagline><?php echo esc_html( $public_tagline ); ?></p>
				</div>
			</div>
		</div>
		<form class="lnkup-form lnkup-organizer-profile-form" id="lnkup-organizer-profile-form">
			<?php wp_nonce_field( 'lnkup_event', 'lnkup_nonce' ); ?>
			<input type="hidden" name="action" value="lnkup_update_organizer_profile" />
			<h2 class="lnkup-panel__title"><?php esc_html_e( 'Profile details', 'lnkup-marketplace' ); ?></h2>
			<div class="lnkup-form-row">
				<label for="lnkup-organizer-public-name"><?php esc_html_e( 'Organization name', 'lnkup-marketplace' ); ?></label>
				<input type="text" id="lnkup-organizer-public-name" name="public_name" value="<?php echo esc_attr( $public_name ); ?>" />
			</div>
			<div class="lnkup-form-row">
				<label for="lnkup-organizer-tagline"><?php esc_html_e( 'Short intro', 'lnkup-marketplace' ); ?></label>
				<input type="text" id="lnkup-organizer-tagline" name="tagline" maxlength="160" value="<?php echo esc_attr( $public_tagline ); ?>" />
			</div>
			<div class="lnkup-form-row">
				<label for="lnkup-organizer-bio"><?php esc_html_e( 'About', 'lnkup-marketplace' ); ?></label>
				<textarea id="lnkup-organizer-bio" name="bio" rows="4"><?php echo esc_textarea( $public_bio ); ?></textarea>
			</div>
			<div class="lnkup-grid lnkup-grid--2">
				<div class="lnkup-form-row">
					<label for="lnkup-organizer-city"><?php esc_html_e( 'Base city', 'lnkup-marketplace' ); ?></label>
					<input type="text" id="lnkup-organizer-city" name="city" data-lnkup-location-city value="<?php echo esc_attr( $public_city ); ?>" />
				</div>
				<div class="lnkup-form-row">
					<label for="lnkup-organizer-state"><?php esc_html_e( 'State', 'lnkup-marketplace' ); ?></label>
					<input type="text" id="lnkup-organizer-state" name="state" data-lnkup-location-state value="<?php echo esc_attr( $public_state ); ?>" />
				</div>
			</div>
			<div class="lnkup-form-row">
				<label for="lnkup-organizer-service"><?php esc_html_e( 'Cities you host events in', 'lnkup-marketplace' ); ?></label>
				<input type="text" id="lnkup-organizer-service" name="service_cities" value="<?php echo esc_attr( $public_service ); ?>" />
			</div>
			<div class="lnkup-form-row">
				<label for="lnkup-organizer-website"><?php esc_html_e( 'Website', 'lnkup-marketplace' ); ?></label>
				<input type="url" id="lnkup-organizer-website" name="website" value="<?php echo esc_attr( $public_website ); ?>" />
			</div>
			<div class="lnkup-grid lnkup-grid--2">
				<div class="lnkup-form-row">
					<label for="lnkup-organizer-instagram"><?php esc_html_e( 'Instagram', 'lnkup-marketplace' ); ?></label>
					<input type="text" id="lnkup-organizer-instagram" name="instagram" value="<?php echo esc_attr( $public_instagram ); ?>" />
				</div>
				<div class="lnkup-form-row">
					<label for="lnkup-organizer-tiktok"><?php esc_html_e( 'TikTok', 'lnkup-marketplace' ); ?></label>
					<input type="text" id="lnkup-organizer-tiktok" name="tiktok" value="<?php echo esc_attr( $public_tiktok ); ?>" />
				</div>
			</div>
			<div class="lnkup-form-row">
				<label for="lnkup-organizer-slug"><?php esc_html_e( 'Public profile link', 'lnkup-marketplace' ); ?></label>
				<input type="text" id="lnkup-organizer-slug" name="public_slug" value="<?php echo esc_attr( $public_slug ); ?>" />
			</div>
			<?php
			echo lnkup_image_upload_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'label'        => __( 'Logo', 'lnkup-marketplace' ),
					'button_label' => __( 'Upload logo', 'lnkup-marketplace' ),
					'input_name'   => 'logo_id',
					'input_id'     => 'lnkup-organizer-logo-id',
					'ids'          => array( $logo_id ),
					'alt'          => __( 'Organizer logo', 'lnkup-marketplace' ),
					'testid'       => 'lnkup-organizer-logo-upload',
				)
			);
			echo lnkup_image_upload_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'label'        => __( 'Cover image', 'lnkup-marketplace' ),
					'button_label' => __( 'Upload cover', 'lnkup-marketplace' ),
					'input_name'   => 'cover_id',
					'input_id'     => 'lnkup-organizer-cover-id',
					'ids'          => array( $cover_id ),
					'alt'          => __( 'Organizer cover image', 'lnkup-marketplace' ),
					'testid'       => 'lnkup-organizer-cover-upload',
				)
			);
			?>
			<button type="submit" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'Save profile', 'lnkup-marketplace' ); ?></button>
			<?php if ( $profile_url ) : ?>
				<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="<?php echo esc_url( $profile_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Preview public profile', 'lnkup-marketplace' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Requests with event and status filters. Totals cover every owned application.
	 *
	 * @return string
	 */
	private static function render_applications() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$classic = isset( $_GET['mode'] ) && 'classic' === sanitize_key( wp_unslash( $_GET['mode'] ) );
		if ( LNKup_Feature_Flags::application_review() && ( ! $classic || LNKup_Capabilities::is_team_only_member() ) ) {
			return self::render_review_workspace();
		}
		$user_id = get_current_user_id();
		$filter  = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$allowed = array( 'all', 'pending', 'needs_info', 'approved', 'unpaid', 'paid', 'waitlisted', 'rejected', 'missing_docs' );
		if ( ! in_array( $filter, $allowed, true ) ) {
			$filter = 'all';
		}
		$event_filter = isset( $_GET['event_filter'] ) ? absint( wp_unslash( $_GET['event_filter'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $event_filter && class_exists( 'LNKup_Capabilities' ) && ! LNKup_Capabilities::organizer_owns_event( $event_filter ) && ! current_user_can( 'manage_options' ) ) {
			$event_filter = 0;
		}
		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $search ) ) : strtolower( trim( $search ) );
		$sort   = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'newest'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $sort, array( 'newest', 'oldest', 'fee' ), true ) ) {
			$sort = 'newest';
		}
		$counts = self::application_counts( $user_id, $event_filter );
		$page   = lnkup_organizer_dashboard_page();
		$result = self::application_page( $user_id, $filter, $event_filter, $page, $search, $sort );
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => (string) $user_id,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$args = array();
		if ( 'all' !== $filter ) {
			$args['filter'] = $filter;
		}
		if ( $event_filter ) {
			$args['event_filter'] = $event_filter;
		}
		if ( '' !== $search ) {
			$args['q'] = $search;
		}
		if ( 'newest' !== $sort ) {
			$args['sort'] = $sort;
		}
		ob_start();
		echo self::page_header( __( 'Requests', 'lnkup-marketplace' ), __( 'Review vendor applications and track who has paid.', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo LNKup_Shortcodes::organizer_section_card_open( 'applications', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<form class="lnkup-filters lnkup-request-filters" method="get" action="<?php echo esc_url( lnkup_organizer_view_url( 'applications' ) ); ?>" data-auto-submit>
			<input type="hidden" name="view" value="applications" />
			<?php if ( 'all' !== $filter ) : ?>
				<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>" />
			<?php endif; ?>
			<label class="lnkup-request-filters__search">
				<span class="screen-reader-text"><?php esc_html_e( 'Search requests', 'lnkup-marketplace' ); ?></span>
				<input type="search" name="q" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search vendor, slot, or event', 'lnkup-marketplace' ); ?>" />
			</label>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></span>
				<select id="lnkup-org-app-event" name="event_filter">
					<option value=""><?php esc_html_e( 'All events', 'lnkup-marketplace' ); ?></option>
					<?php foreach ( $events as $event ) : ?>
						<option value="<?php echo esc_attr( (string) $event->ID ); ?>"<?php selected( $event_filter, (int) $event->ID ); ?>><?php echo esc_html( $event->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Sort', 'lnkup-marketplace' ); ?></span>
				<select name="sort" data-auto-submit-field>
					<option value="newest"<?php selected( $sort, 'newest' ); ?>><?php esc_html_e( 'Newest first', 'lnkup-marketplace' ); ?></option>
					<option value="oldest"<?php selected( $sort, 'oldest' ); ?>><?php esc_html_e( 'Oldest first', 'lnkup-marketplace' ); ?></option>
					<option value="fee"<?php selected( $sort, 'fee' ); ?>><?php esc_html_e( 'Highest fee', 'lnkup-marketplace' ); ?></option>
				</select>
			</label>
			<button type="submit" class="lnkup-btn lnkup-btn--outline"><?php esc_html_e( 'Apply', 'lnkup-marketplace' ); ?></button>
			<?php if ( '' !== $search || $event_filter || 'newest' !== $sort ) : ?>
				<a class="lnkup-btn lnkup-btn--ghost" href="<?php echo esc_url( lnkup_organizer_view_url( 'applications', 'all' !== $filter ? array( 'filter' => $filter ) : array() ) ); ?>"><?php esc_html_e( 'Clear', 'lnkup-marketplace' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
		$tab_labels = array(
			'all'          => __( 'All', 'lnkup-marketplace' ),
			'pending'      => __( 'To review', 'lnkup-marketplace' ),
			'needs_info'   => __( 'Needs info', 'lnkup-marketplace' ),
			'approved'     => __( 'Approved', 'lnkup-marketplace' ),
			'unpaid'       => __( 'Awaiting payment', 'lnkup-marketplace' ),
			'paid'         => __( 'Confirmed', 'lnkup-marketplace' ),
			'waitlisted'   => __( 'Waitlisted', 'lnkup-marketplace' ),
			'rejected'     => __( 'Declined', 'lnkup-marketplace' ),
			'missing_docs' => __( 'Missing docs', 'lnkup-marketplace' ),
		);
		$request_tabs = array();
		foreach ( $tab_labels as $key => $label ) {
			$tab_args = array_diff_key( $args, array( 'filter' => true ) );
			if ( 'all' !== $key ) {
				$tab_args['filter'] = $key;
			}
			$request_tabs[] = array(
				'id'          => $key,
				'label'       => $label,
				'href'        => lnkup_organizer_view_url( 'applications', $tab_args ),
				'badge'       => (int) ( $counts[ $key ] ?? 0 ),
				'badge_class' => 'pending' === $key ? 'lnkup-dash-tabs__badge--pending' : '',
			);
		}
		echo lnkup_dash_tabs( $request_tabs, $filter, array( 'class' => 'lnkup-organizer-app-filters', 'label' => __( 'Application filters', 'lnkup-marketplace' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div id="lnkup-app-intel-grid">
			<?php
			if ( ! $result['posts'] && ( '' !== $search || 'all' !== $filter || $event_filter ) ) {
				echo lnkup_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					array(
						'title'     => __( 'No requests match', 'lnkup-marketplace' ),
						'desc'      => __( 'Try another filter or clear your search.', 'lnkup-marketplace' ),
						'cta_label' => __( 'Show all requests', 'lnkup-marketplace' ),
						'cta_href'  => lnkup_organizer_view_url( 'applications' ),
						'compact'   => true,
					)
				);
			} elseif ( class_exists( 'LNKup_OS_UI' ) ) {
				echo self::render_application_table( $result['posts'], (bool) $event_filter ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo LNKup_Shortcodes::render_applications_manager( $result['posts'], array( 'selectable' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
		<?php
		echo lnkup_organizer_pagination_nav( (int) $result['total'], self::PER_PAGE, 'applications', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo LNKup_Shortcodes::organizer_section_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return (string) ob_get_clean();
	}

	/**
	 * Shared application table (Organizations-wide Applications and the event Applications tab).
	 *
	 * Row checkboxes use the existing bulk flow (organizer-requests.js → CRM roster bulk). Each row
	 * opens one review drawer that shows the existing application card, so decisions keep their
	 * server-validated handlers; Previous/Next walk the rows on screen.
	 *
	 * @param WP_Post[] $apps        Applications on this page.
	 * @param bool      $event_scope Hide the Event column.
	 */
	public static function render_application_table( array $apps, bool $event_scope = false ): string {
		if ( ! $apps ) {
			return LNKup_OS_UI::empty_state( __( 'No applications here', 'lnkup-marketplace' ), __( 'Try another view or clear your search.', 'lnkup-marketplace' ) );
		}
		$status_tone = array(
			'submitted'        => array( __( 'New', 'lnkup-marketplace' ), 'info' ),
			'under_review'     => array( __( 'In review', 'lnkup-marketplace' ), 'info' ),
			'info_requested'   => array( __( 'Info requested', 'lnkup-marketplace' ), 'warning' ),
			'approved'         => array( __( 'Approved', 'lnkup-marketplace' ), 'warning' ),
			'payment_required' => array( __( 'Approved', 'lnkup-marketplace' ), 'warning' ),
			'paid'             => array( __( 'Confirmed', 'lnkup-marketplace' ), 'success' ),
			'completed'        => array( __( 'Completed', 'lnkup-marketplace' ), 'success' ),
			'waitlisted'       => array( __( 'Waitlisted', 'lnkup-marketplace' ), 'neutral' ),
			'rejected'         => array( __( 'Declined', 'lnkup-marketplace' ), 'danger' ),
			'cancelled'        => array( __( 'Cancelled', 'lnkup-marketplace' ), 'danger' ),
		);
		$doc_labels  = array(
			'none_required' => array( __( 'Not required', 'lnkup-marketplace' ), 'neutral' ),
			'ready'         => array( __( 'Approved', 'lnkup-marketplace' ), 'success' ),
			'pending'       => array( __( 'To review', 'lnkup-marketplace' ), 'warning' ),
			'missing'       => array( __( 'Missing', 'lnkup-marketplace' ), 'danger' ),
		);
		$columns     = array(
			'select' => array( 'label' => __( 'Select', 'lnkup-marketplace' ), 'class' => 'vos-check' ),
			'vendor' => array( 'label' => __( 'Vendor', 'lnkup-marketplace' ), 'lead' => true ),
		);
		if ( ! $event_scope ) {
			$columns['event'] = array( 'label' => __( 'Event', 'lnkup-marketplace' ) );
		}
		$columns += array(
			'booth'   => array( 'label' => __( 'Booth type', 'lnkup-marketplace' ) ),
			'fee'     => array( 'label' => __( 'Fee', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
			'status'  => array( 'label' => __( 'Application', 'lnkup-marketplace' ) ),
			'payment' => array( 'label' => __( 'Payment', 'lnkup-marketplace' ) ),
			'docs'    => array( 'label' => __( 'Documents', 'lnkup-marketplace' ) ),
			'applied' => array( 'label' => __( 'Applied', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
		);
		$open_cache = array();
		$rows       = array();
		foreach ( $apps as $app ) {
			$app_id   = (int) $app->ID;
			$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
			$opp_id   = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
			$status   = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
			$label    = $status_tone[ $status ] ?? array( ucwords( str_replace( '_', ' ', $status ) ), 'neutral' );
			$paid     = class_exists( 'LNKup_Vendor_Readiness' ) ? LNKup_Vendor_Readiness::application_payment_satisfied( $app_id, $status ) : in_array( $status, array( 'paid', 'completed' ), true );
			$doc_ctx  = class_exists( 'LNKup_Verification' ) ? LNKup_Verification::get_application_document_context( $app_id ) : array();
			$doc      = $doc_labels[ (string) ( $doc_ctx['code'] ?? 'none_required' ) ] ?? $doc_labels['none_required'];
			$name     = LNKup_Organizer_Presentation::vendor_name( $app_id );
			$is_open  = in_array( $status, array( 'submitted', 'under_review', 'info_requested' ), true );
			if ( $event_id && ! isset( $open_cache[ $event_id ] ) ) {
				$open_cache[ $event_id ] = LNKup_Organizer_Presentation::capacity( $event_id )['open'];
			}
			if ( in_array( $status, array( 'approved', 'payment_required' ), true ) ) {
				$payment = $paid ? LNKup_OS_UI::status( __( 'Paid', 'lnkup-marketplace' ), 'success' ) : LNKup_OS_UI::status( __( 'Awaiting payment', 'lnkup-marketplace' ), 'warning' );
			} elseif ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
				$payment = LNKup_OS_UI::status( __( 'Paid', 'lnkup-marketplace' ), 'success' );
			} else {
				$payment = '<span class="lnkup-muted">' . esc_html__( 'Not due yet', 'lnkup-marketplace' ) . '</span>';
			}
			$cells = array(
				'select'  => $is_open && $event_id ? '<input type="checkbox" data-request-select value="' . esc_attr( (string) $app_id ) . '" data-event-id="' . esc_attr( (string) $event_id ) . '" data-event-title="' . esc_attr( LNKup_Organizer_Presentation::title( $event_id ) ) . '" data-open-spaces="' . esc_attr( (string) (int) $open_cache[ $event_id ] ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: vendor */ __( 'Select %s', 'lnkup-marketplace' ), $name ) ) . '" />' : '',
				'vendor'  => '<button type="button" class="vos-table__primary oos-linkbtn" data-oos-app-open="' . esc_attr( (string) $app_id ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: vendor */ __( 'Review %s', 'lnkup-marketplace' ), $name ) ) . '">' . esc_html( $name ) . '</button>',
				'booth'   => esc_html( $opp_id ? lnkup_plain_title( $opp_id ) : '—' ),
				'fee'     => esc_html( LNKup_OS_UI::money( $opp_id ? (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true ) : 0.0 ) ),
				'status'  => LNKup_OS_UI::status( $label[0], $label[1] ),
				'payment' => $payment,
				'docs'    => LNKup_OS_UI::status( $doc[0], $doc[1] ),
				'applied' => LNKup_OS_UI::relative_time( (int) get_post_time( 'U', false, $app ) ),
			);
			if ( ! $event_scope ) {
				$cells['event'] = esc_html( $event_id ? LNKup_Organizer_Presentation::title( $event_id ) : '' );
			}
			$rows[] = array(
				'id'    => $app_id,
				'attrs' => 'data-app-row="' . esc_attr( (string) $app_id ) . '"',
				'cells' => $cells,
			);
		}
		$bulk  = '<div class="vos-bulk-bar" data-request-bulk hidden role="region" aria-label="' . esc_attr__( 'Bulk actions', 'lnkup-marketplace' ) . '">';
		$bulk .= '<span class="vos-bulk-bar__count" data-request-bulk-count aria-live="polite"></span>';
		$bulk .= '<span class="oos-bulk-check" data-oos-bulk-check aria-live="polite"></span>';
		$bulk .= '<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" data-request-bulk-action="approve" disabled>' . esc_html__( 'Approve', 'lnkup-marketplace' ) . '</button>';
		$bulk .= '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-request-bulk-action="waitlist" disabled>' . esc_html__( 'Waitlist', 'lnkup-marketplace' ) . '</button>';
		$bulk .= '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-request-bulk-action="reject" disabled>' . esc_html__( 'Decline', 'lnkup-marketplace' ) . '</button>';
		$bulk .= '<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-request-bulk-clear>' . esc_html__( 'Clear', 'lnkup-marketplace' ) . '</button></div>';

		$drawer_foot = '<span class="vos-drawer__foot-start"><button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-oos-app-nav="prev" data-testid="lnkup-app-prev">' . esc_html__( '← Previous', 'lnkup-marketplace' ) . '</button><span class="lnkup-muted" data-oos-app-pos></span><button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-oos-app-nav="next" data-testid="lnkup-app-next">' . esc_html__( 'Next →', 'lnkup-marketplace' ) . '</button></span><button type="button" class="lnkup-btn lnkup-btn--ghost" data-vos-close>' . esc_html__( 'Close', 'lnkup-marketplace' ) . '</button>';

		return '<div class="oos-app-table" data-testid="lnkup-application-table">'
			. LNKup_OS_UI::data_table( $columns, $rows, array( 'caption' => __( 'Applications', 'lnkup-marketplace' ) ) )
			. $bulk
			. '</div>'
			. '<div class="oos-app-cards" data-oos-app-cards hidden>' . LNKup_Shortcodes::render_applications_manager( $apps, array( 'selectable' => false ) ) . '</div>'
			. LNKup_OS_UI::drawer_shell( 'oos-app-drawer', __( 'Application', 'lnkup-marketplace' ), '<div data-oos-app-slot></div>', $drawer_foot, array( 'wide' => true, 'testid' => 'lnkup-application-drawer' ) );
	}

	/**
	 * Status totals for every application this organizer owns.
	 *
	 * @param int $user_id       Organizer user ID.
	 * @param int $event_filter  Optional event ID.
	 * @return array<string,int>
	 */
	private static function application_counts( $user_id, $event_filter ) {
		$base = array();
		if ( $event_filter ) {
			$base[] = array(
				'key'   => '_lnkup_event_id',
				'value' => (string) $event_filter,
			);
		}
		$status_map = self::status_map();
		unset( $status_map['unpaid'] );
		$counts = array( 'all' => lnkup_count_posts_for_organizer( 'lnkup_application', $user_id, $base ) );
		foreach ( $status_map as $key => $statuses ) {
			$counts[ $key ] = lnkup_count_posts_for_organizer(
				'lnkup_application',
				$user_id,
				array_merge(
					$base,
					array(
						array(
							'key'     => '_lnkup_application_status',
							'value'   => $statuses,
							'compare' => 'IN',
						),
					)
				)
			);
		}
		$counts['unpaid']       = count( self::unpaid_ids( $user_id, $event_filter ) );
		$counts['missing_docs'] = count( self::missing_doc_ids( $user_id, $event_filter ) );
		return $counts;
	}

	/**
	 * Application IDs whose documents are pending or missing.
	 *
	 * @param int $user_id      Organizer user ID.
	 * @param int $event_filter Optional event ID.
	 * @return int[]
	 */
	private static function missing_doc_ids( $user_id, $event_filter ) {
		$meta = array(
			array(
				'key'   => '_lnkup_organizer_user_id',
				'value' => (string) $user_id,
			),
		);
		if ( $event_filter ) {
			$meta[] = array(
				'key'   => '_lnkup_event_id',
				'value' => (string) $event_filter,
			);
		}
		$ids = get_posts(
			array(
				'post_type'              => 'lnkup_application',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		$missing = array();
		foreach ( $ids as $app_id ) {
			if ( ! class_exists( 'LNKup_Verification' ) ) {
				break;
			}
			$ctx  = LNKup_Verification::get_application_document_context( (int) $app_id );
			$code = (string) ( $ctx['code'] ?? '' );
			if ( in_array( $code, array( 'pending', 'missing' ), true ) ) {
				$missing[] = (int) $app_id;
			}
		}
		return $missing;
	}

	/**
	 * One page of applications for the active filter.
	 *
	 * @param int    $user_id      Organizer user ID.
	 * @param string $filter       Status filter.
	 * @param int    $event_filter Event ID.
	 * @param int    $page         Page number.
	 * @return array{posts:WP_Post[],total:int}
	 */
	private static function application_page( $user_id, $filter, $event_filter, $page, $search = '', $sort = 'newest' ) {
		$needs_ids = 'missing_docs' === $filter || 'unpaid' === $filter || '' !== $search || 'fee' === $sort;
		if ( $needs_ids ) {
			if ( 'missing_docs' === $filter ) {
				$ids = self::missing_doc_ids( $user_id, $event_filter );
			} elseif ( 'unpaid' === $filter ) {
				$ids = self::unpaid_ids( $user_id, $event_filter );
			} else {
				$ids = self::application_ids( $user_id, $event_filter, self::status_map()[ $filter ] ?? array() );
			}
			if ( '' !== $search ) {
				$ids = array_values( array_filter( $ids, static fn( $id ) => self::application_matches_search( (int) $id, $search ) ) );
			}
			$ids   = self::sort_application_ids( $ids, $sort );
			$total = count( $ids );
			$slice = array_slice( $ids, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
			$posts = $slice ? get_posts(
				array(
					'post_type'      => 'lnkup_application',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => count( $slice ),
					'post__in'       => $slice,
					'orderby'        => 'post__in',
				)
			) : array();
			return array( 'posts' => $posts, 'total' => $total );
		}
		$meta = array(
			array(
				'key'   => '_lnkup_organizer_user_id',
				'value' => (string) $user_id,
			),
		);
		if ( $event_filter ) {
			$meta[] = array(
				'key'   => '_lnkup_event_id',
				'value' => (string) $event_filter,
			);
		}
		$status_map = self::status_map();
		if ( isset( $status_map[ $filter ] ) ) {
			$meta[] = array(
				'key'     => '_lnkup_application_status',
				'value'   => $status_map[ $filter ],
				'compare' => 'IN',
			);
		}
		$query = new WP_Query(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => self::PER_PAGE,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'oldest' === $sort ? 'ASC' : 'DESC',
				'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		$posts = $query->posts;
		$total = (int) $query->found_posts;
		wp_reset_postdata();
		return array( 'posts' => is_array( $posts ) ? $posts : array(), 'total' => $total );
	}

	/**
	 * Request filter keys mapped to application statuses.
	 *
	 * @return array<string,string[]>
	 */
	private static function status_map() {
		return array(
			'pending'    => array( 'submitted', 'under_review' ),
			'needs_info' => array( 'info_requested' ),
			'approved'   => array( 'approved', 'payment_required' ),
			'unpaid'     => array( 'approved', 'payment_required' ),
			'paid'       => array( 'paid', 'completed' ),
			'waitlisted' => array( 'waitlisted' ),
			'rejected'   => array( 'rejected' ),
		);
	}

	/**
	 * Application IDs this organizer owns, newest first.
	 *
	 * @param int      $user_id      Organizer user ID.
	 * @param int      $event_filter Optional event ID.
	 * @param string[] $statuses     Optional status allowlist.
	 * @return int[]
	 */
	private static function application_ids( $user_id, $event_filter, $statuses = array() ) {
		$meta = array(
			array(
				'key'   => '_lnkup_organizer_user_id',
				'value' => (string) $user_id,
			),
		);
		if ( $event_filter ) {
			$meta[] = array(
				'key'   => '_lnkup_event_id',
				'value' => (string) $event_filter,
			);
		}
		if ( $statuses ) {
			$meta[] = array(
				'key'     => '_lnkup_application_status',
				'value'   => $statuses,
				'compare' => 'IN',
			);
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'              => 'lnkup_application',
					'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'meta_query'             => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			)
		);
	}

	/**
	 * Approved applications whose booth fee has not been paid. Uses payment records, not only status.
	 *
	 * @param int $user_id      Organizer user ID.
	 * @param int $event_filter Optional event ID.
	 * @return int[]
	 */
	public static function unpaid_ids( $user_id, $event_filter = 0 ) {
		$ids = self::application_ids( $user_id, $event_filter, array( 'approved', 'payment_required' ) );
		if ( ! class_exists( 'LNKup_Vendor_Readiness' ) ) {
			return $ids;
		}
		return array_values(
			array_filter(
				$ids,
				static function ( $id ) {
					$status = (string) get_post_meta( $id, '_lnkup_application_status', true );
					return ! LNKup_Vendor_Readiness::application_payment_satisfied( (int) $id, $status );
				}
			)
		);
	}

	/**
	 * Whether a request matches the search box (vendor, slot, or event name).
	 *
	 * @param int    $app_id Application ID.
	 * @param string $search Lowercase search text.
	 * @return bool
	 */
	private static function application_matches_search( $app_id, $search ) {
		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		$opp_id     = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		$event_id   = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$haystack   = array(
			get_the_title( $app_id ),
			$profile_id ? (string) get_post_meta( $profile_id, '_lnkup_business_name', true ) : '',
			$profile_id ? get_the_title( $profile_id ) : '',
			$opp_id ? get_the_title( $opp_id ) : '',
			$event_id ? get_the_title( $event_id ) : '',
		);
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( implode( ' ', $haystack ) ) : strtolower( implode( ' ', $haystack ) );
		return false !== strpos( $text, $search );
	}

	/**
	 * Sort application IDs.
	 *
	 * @param int[]  $ids  Application IDs, newest first.
	 * @param string $sort newest | oldest | fee.
	 * @return int[]
	 */
	private static function sort_application_ids( $ids, $sort ) {
		if ( 'oldest' === $sort ) {
			return array_reverse( $ids );
		}
		if ( 'fee' === $sort ) {
			$fees = array();
			foreach ( $ids as $id ) {
				$opp_id      = (int) get_post_meta( $id, '_lnkup_opportunity_id', true );
				$fees[ $id ] = $opp_id ? (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true ) : 0.0;
			}
			usort(
				$ids,
				static function ( $a, $b ) use ( $fees ) {
					return $fees[ $b ] <=> $fees[ $a ];
				}
			);
		}
		return $ids;
	}

	/**
	 * Vendor discovery. Tools are visible on this page.
	 *
	 * @return string
	 */
	/**
	 * Vendors workspace header and section tabs, shared by Vendors, Lists and Invitations.
	 *
	 * @param string $active discover|event|my|lists|imported|invitations.
	 */
	private static function vendors_workspace_header( string $active ): string {
		$matching = class_exists( 'LNKup_Match_Settings' ) && LNKup_Match_Settings::enabled( 'matching_organizer_discovery_enabled' );
		$tabs     = array(
			array( 'id' => 'discover', 'label' => __( 'Discover', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'vendors' ) ),
		);
		if ( $matching ) {
			$tabs[] = array( 'id' => 'event', 'label' => __( 'For this event', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'vendors', array( 'section' => 'event' ) ) );
		}
		$tabs[] = array( 'id' => 'my', 'label' => __( 'My vendors', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'vendors', array( 'scope' => 'my' ) ) );
		$tabs[] = array( 'id' => 'lists', 'label' => __( 'Lists', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'lists' ) );
		$tabs[] = array( 'id' => 'imported', 'label' => __( 'Imported', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'vendors', array( 'scope' => 'imported' ) ) );
		$tabs[] = array( 'id' => 'invitations', 'label' => __( 'Invitations', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'invites' ) );
		$actions = '<button type="button" class="lnkup-btn lnkup-btn--outline" data-oos-import>' . esc_html__( 'Import vendors', 'lnkup-marketplace' ) . '</button>'
			. '<a class="lnkup-btn lnkup-btn--primary" href="' . esc_url( $matching ? lnkup_organizer_view_url( 'vendors', array( 'section' => 'event' ) ) : lnkup_organizer_view_url( 'invites' ) ) . '">' . esc_html__( 'Invite vendors', 'lnkup-marketplace' ) . '</a>';
		return LNKup_OS_UI::page_header(
			array(
				'title'    => __( 'Vendors', 'lnkup-marketplace' ),
				'subtitle' => __( 'Find vendors, keep the ones you work with, organize lists and track invitations.', 'lnkup-marketplace' ),
				'actions'  => $actions,
				'testid'   => 'lnkup-vendors-header',
			)
		) . lnkup_dash_tabs(
			$tabs,
			$active,
			array(
				'variant' => 'underline',
				'label'   => __( 'Vendor sections', 'lnkup-marketplace' ),
				'testid'  => 'lnkup-vendors-tabs',
			)
		);
	}

	private static function render_vendors() {
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$scope   = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : 'discover'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$scope   = in_array( $scope, array( 'discover', 'my', 'imported' ), true ) ? $scope : 'discover';
		if ( ! empty( $_GET['recruit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$section = 'event';
		}
		ob_start();
		if ( class_exists( 'LNKup_OS_UI' ) ) {
			$find = 'event' === $section ? self::render_find_vendors() : '';
			echo self::vendors_workspace_header( '' !== $find ? 'event' : $scope ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( '' !== $find ) {
				echo $find; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				return (string) ob_get_clean();
			}
		} else {
			echo self::page_header( __( 'Vendors', 'lnkup-marketplace' ), __( 'Discover vendors, track the ones you work with, and import your own list.', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::render_find_vendors(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo LNKup_Shortcodes::organizer_module_card_open( 'vendors', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<label class="screen-reader-text" for="lnkup-crm-vendor-search"><?php esc_html_e( 'Search vendors', 'lnkup-marketplace' ); ?></label>
		<input type="search" id="lnkup-crm-vendor-search" class="lnkup-crm-search lnkup-organizer-module-card__search" placeholder="<?php esc_attr_e( 'Search vendors', 'lnkup-marketplace' ); ?>" />
		<?php
		echo LNKup_Shortcodes::organizer_expand_panel_open( 'vendors', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="lnkup-crm-toolbar">
			<div class="lnkup-crm-toolbar__row lnkup-crm-toolbar__row--primary">
				<?php
				echo lnkup_dash_tabs( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					array(
						array( 'id' => 'discover', 'label' => __( 'Discover', 'lnkup-marketplace' ), 'attrs' => array( 'data-crm-scope' => 'discover' ) ),
						array( 'id' => 'my', 'label' => __( 'My Vendors', 'lnkup-marketplace' ), 'attrs' => array( 'data-crm-scope' => 'my' ) ),
						array( 'id' => 'imported', 'label' => __( 'Imported', 'lnkup-marketplace' ), 'attrs' => array( 'data-crm-scope' => 'imported' ) ),
					),
					$scope,
					array(
						'class' => 'lnkup-crm-toolbar__tabs',
						'label' => __( 'Vendor scope', 'lnkup-marketplace' ),
					)
				);
				?>
				<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" id="lnkup-import-vendors-btn" data-testid="lnkup-import-vendors-btn"><?php esc_html_e( 'Import vendors', 'lnkup-marketplace' ); ?></button>
			</div>
			<div class="lnkup-crm-toolbar__row lnkup-crm-toolbar__row--filters">
				<label class="screen-reader-text" for="lnkup-crm-vendor-sort"><?php esc_html_e( 'Sort vendors', 'lnkup-marketplace' ); ?></label>
				<select id="lnkup-crm-vendor-sort" class="lnkup-crm-search">
					<option value="score"><?php esc_html_e( 'Highest score', 'lnkup-marketplace' ); ?></option>
					<option value="rating"><?php esc_html_e( 'Best rating', 'lnkup-marketplace' ); ?></option>
					<option value="completed"><?php esc_html_e( 'Most events', 'lnkup-marketplace' ); ?></option>
					<option value="reliable"><?php esc_html_e( 'Most reliable', 'lnkup-marketplace' ); ?></option>
					<option value="preferred"><?php esc_html_e( 'Preferred first', 'lnkup-marketplace' ); ?></option>
					<option value="new"><?php esc_html_e( 'New vendors', 'lnkup-marketplace' ); ?></option>
				</select>
				<label class="screen-reader-text" for="lnkup-crm-vendor-city"><?php esc_html_e( 'Filter by city', 'lnkup-marketplace' ); ?></label>
				<input type="text" id="lnkup-crm-vendor-city" class="lnkup-crm-search" placeholder="<?php esc_attr_e( 'City…', 'lnkup-marketplace' ); ?>" />
				<label class="lnkup-crm-toolbar__verified">
					<input type="checkbox" id="lnkup-crm-vendor-verified" value="1" />
					<?php esc_html_e( 'Verified only', 'lnkup-marketplace' ); ?>
				</label>
				<label class="screen-reader-text" for="lnkup-crm-vendor-category"><?php esc_html_e( 'Filter by category', 'lnkup-marketplace' ); ?></label>
				<select id="lnkup-crm-vendor-category" class="lnkup-crm-search">
					<option value=""><?php esc_html_e( 'All categories', 'lnkup-marketplace' ); ?></option>
					<?php
					$vendor_cats = get_terms( array( 'taxonomy' => 'lnkup_vendor_category', 'hide_empty' => false ) );
					if ( $vendor_cats && ! is_wp_error( $vendor_cats ) ) :
						foreach ( $vendor_cats as $cat ) :
							?>
							<option value="<?php echo esc_attr( $cat->name ); ?>"><?php echo esc_html( $cat->name ); ?></option>
							<?php
						endforeach;
					endif;
					?>
				</select>
			</div>
		</div>
		<?php
		echo LNKup_Shortcodes::organizer_expand_panel_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div id="lnkup-crm-directory" class="lnkup-crm-directory" aria-live="polite"><?php echo lnkup_loading_state( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" id="lnkup-crm-directory-more" hidden><?php esc_html_e( 'Show more', 'lnkup-marketplace' ); ?></button>
		<?php
		echo LNKup_Shortcodes::organizer_module_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return (string) ob_get_clean();
	}

	/**
	 * "Vendor slots to fill" for the nearest live event that still has open slots: remaining spots by
	 * category, the top fulfillment action and (when Find Vendors is on) three recommended vendors.
	 * Reads cached fulfillment and cached match results only; no marketplace scans on page load.
	 *
	 * @param int[] $event_ids Upcoming event IDs, soonest first.
	 * @return string
	 */
	private static function render_fill_slots_card( array $event_ids ) {
		$user_id = get_current_user_id();
		$fill    = null;
		foreach ( array_slice( $event_ids, 0, 5 ) as $event_id ) {
			if ( 'live' !== get_post_meta( (int) $event_id, '_lnkup_event_status', true ) ) {
				continue;
			}
			$candidate = LNKup_Event_Fulfillment::for_event( (int) $event_id );
			if ( $candidate['totals']['open_opportunities'] > 0 && ( $candidate['totals']['remaining'] > 0 || $candidate['totals']['unlimited'] > 0 ) ) {
				$fill = $candidate;
				break;
			}
		}
		if ( ! $fill ) {
			return '';
		}
		$event_id   = (int) $fill['event_id'];
		$find_url   = add_query_arg( 'recruit', $event_id, lnkup_organizer_view_url( 'vendors' ) );
		$action     = $fill['actions'][0] ?? null;
		$open_cats  = array_values(
			array_filter(
				$fill['categories'],
				static function ( $cat ) {
					return $cat['open'] && ( $cat['remaining'] > 0 || $cat['unlimited'] );
				}
			)
		);
		$vendors    = array();
		if ( class_exists( 'LNKup_Match_Settings' ) && LNKup_Match_Settings::enabled( 'matching_organizer_discovery_enabled' ) ) {
			$result  = LNKup_Match_Service::event_vendor_candidates( $user_id, $event_id, array( 'per_page' => 3, 'exclude_applied' => true ) );
			$vendors = is_wp_error( $result ) ? array() : (array) $result['items'];
		}
		ob_start();
		?>
		<div class="lnkup-home-block lnkup-fill-slots" data-testid="lnkup-fill-slots" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>">
			<div class="lnkup-home-block__head">
				<h2 class="lnkup-panel__title"><?php esc_html_e( 'Vendor slots to fill', 'lnkup-marketplace' ); ?></h2>
				<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( $find_url ); ?>"><?php esc_html_e( 'Find vendors', 'lnkup-marketplace' ); ?></a>
			</div>
			<p class="lnkup-muted">
				<?php
				echo esc_html(
					$fill['totals']['remaining'] > 0
						/* translators: 1: open spots, 2: event title */
						? sprintf( _n( '%1$d vendor spot is still open at %2$s.', '%1$d vendor spots are still open at %2$s.', $fill['totals']['remaining'], 'lnkup-marketplace' ), $fill['totals']['remaining'], $fill['event_title'] )
						/* translators: %s: event title */
						: sprintf( __( '%s is accepting vendor applications.', 'lnkup-marketplace' ), $fill['event_title'] )
				);
				?>
			</p>
			<?php if ( $open_cats ) : ?>
				<ul class="lnkup-fill-slots__cats">
					<?php foreach ( array_slice( $open_cats, 0, 6 ) as $cat ) : ?>
						<li class="lnkup-chip">
							<?php
							echo esc_html(
								$cat['unlimited']
									? $cat['label']
									/* translators: 1: open spots, 2: category */
									: sprintf( __( '%1$d %2$s', 'lnkup-marketplace' ), $cat['remaining'], $cat['label'] )
							);
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $action ) : ?>
				<p class="lnkup-fill-slots__action"><strong><?php echo esc_html( $action['label'] ); ?></strong></p>
			<?php endif; ?>
			<?php if ( $vendors ) : ?>
				<h3 class="lnkup-fill-slots__subtitle"><?php esc_html_e( 'Recommended vendors', 'lnkup-marketplace' ); ?></h3>
				<ul class="lnkup-fill-slots__vendors">
					<?php foreach ( $vendors as $vendor ) : ?>
						<li class="lnkup-fill-slots__vendor">
							<?php if ( '' !== $vendor['logo'] ) : ?>
								<img src="<?php echo esc_url( $vendor['logo'] ); ?>" alt="" width="40" height="40" loading="lazy">
							<?php endif; ?>
							<span>
								<strong><?php echo esc_html( $vendor['business_name'] ); ?></strong>
								<?php if ( ! empty( $vendor['verified'] ) ) : ?>
									<span class="lnkup-badge lnkup-badge--verified"><?php esc_html_e( 'Verified', 'lnkup-marketplace' ); ?></span>
								<?php endif; ?>
								<span class="lnkup-muted"><?php echo esc_html( implode( ' · ', array_filter( array( $vendor['primary_category'], trim( $vendor['city'] . ', ' . $vendor['state'], ', ' ), $vendor['match']['reasons'][0] ?? '' ) ) ) ); ?></span>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
				<a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="<?php echo esc_url( $find_url ); ?>"><?php esc_html_e( 'Review and invite', 'lnkup-marketplace' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * "Your event is published" recruitment prompt for the newest just-published event.
	 *
	 * @param WP_Post[] $upcoming Upcoming organizer events.
	 * @return string
	 */
	private static function render_recruit_prompt( $upcoming ) {
		if ( ! class_exists( 'LNKup_Match_Settings' ) || ! LNKup_Match_Settings::enabled( 'matching_organizer_discovery_enabled' ) ) {
			return '';
		}
		foreach ( (array) $upcoming as $event ) {
			if ( ! get_post_meta( $event->ID, '_lnkup_recruit_prompt', true ) || 'publish' !== $event->post_status ) {
				continue;
			}
			$assistant = LNKup_Recruitment::assistant( get_current_user_id(), (int) $event->ID );
			$find_url  = add_query_arg( 'recruit', (int) $event->ID, lnkup_organizer_view_url( 'vendors' ) );
			$share_url = add_query_arg( 'event', (int) $event->ID, lnkup_page_url_helper( 'opportunities' ) );
			$missing   = array_filter(
				$assistant['readiness'],
				static function ( $item ) {
					return ! $item['done'];
				}
			);
			ob_start();
			?>
			<div class="lnkup-home-block lnkup-recruit-prompt" data-testid="lnkup-recruit-prompt" data-event-id="<?php echo esc_attr( (string) $event->ID ); ?>">
				<h2 class="lnkup-panel__title"><?php echo esc_html( sprintf( __( '%s is published', 'lnkup-marketplace' ), $event->post_title ) ); ?></h2>
				<p class="lnkup-muted"><?php esc_html_e( 'LNKup can help you recruit vendors that match your event.', 'lnkup-marketplace' ); ?></p>
				<ul class="lnkup-recruit-prompt__actions">
					<li><a href="<?php echo esc_url( $find_url ); ?>">
						<?php
						echo esc_html(
							$assistant['matching_count'] > 0
								? sprintf( _n( 'View %d matching vendor', 'View %d matching vendors', $assistant['matching_count'], 'lnkup-marketplace' ), $assistant['matching_count'] )
								: __( 'View matching vendors', 'lnkup-marketplace' )
						);
						?>
					</a></li>
					<li><button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-copy-url="<?php echo esc_url( $share_url ); ?>"><?php esc_html_e( 'Copy your application link', 'lnkup-marketplace' ); ?></button></li>
					<?php if ( $missing ) : ?>
						<li><a href="<?php echo esc_url( add_query_arg( 'event', (int) $event->ID, lnkup_page_url_helper( 'organizer-dashboard' ) ) ); ?>"><?php echo esc_html( sprintf( __( 'Complete missing details: %s', 'lnkup-marketplace' ), implode( ', ', wp_list_pluck( $missing, 'label' ) ) ) ); ?></a></li>
					<?php endif; ?>
				</ul>
			</div>
			<?php
			return (string) ob_get_clean();
		}
		return '';
	}

	/**
	 * Find Vendors: matching discoverable vendors for one of the organizer's open events.
	 * Results, recruitment tips and batch invites load from lnkup/v1/match (find-vendors.js).
	 *
	 * @return string
	 */
	private static function render_find_vendors() {
		if ( ! class_exists( 'LNKup_Match_Settings' ) || ! LNKup_Match_Settings::enabled( 'matching_organizer_discovery_enabled' ) ) {
			return '';
		}
		$options = LNKup_Vendor_Invites::get_invite_options_for_organizer( get_current_user_id() );
		$events  = array_values(
			array_filter(
				(array) ( $options['items'] ?? array() ),
				static function ( $event ) {
					return 'publish' === get_post_status( (int) $event['event_id'] );
				}
			)
		);
		$selected = isset( $_GET['recruit'] ) ? absint( $_GET['recruit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		ob_start();
		?>
		<section class="lnkup-card lnkup-find-vendors" id="lnkup-find-vendors" data-testid="lnkup-find-vendors" data-match-rest="<?php echo esc_url( rest_url( 'lnkup/v1/match' ) ); ?>" aria-labelledby="lnkup-find-vendors-title">
			<header class="lnkup-find-vendors__header">
				<h2 id="lnkup-find-vendors-title"><?php esc_html_e( 'Find Vendors', 'lnkup-marketplace' ); ?></h2>
				<p class="lnkup-muted"><?php esc_html_e( 'Discoverable vendors that match your event. You review and choose who to invite — nothing is sent automatically.', 'lnkup-marketplace' ); ?></p>
			</header>
			<?php if ( ! $events ) : ?>
				<div class="lnkup-empty-state lnkup-empty-state--compact">
					<p class="lnkup-empty-state__title"><?php esc_html_e( 'Publish an event with an open vendor slot', 'lnkup-marketplace' ); ?></p>
					<p class="lnkup-empty-state__desc"><?php esc_html_e( 'Matching vendors appear here once your event is published and accepting vendors.', 'lnkup-marketplace' ); ?></p>
				</div>
			<?php else : ?>
				<div class="lnkup-crm-toolbar__row">
					<label class="screen-reader-text" for="lnkup-find-vendors-event"><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></label>
					<select id="lnkup-find-vendors-event" class="lnkup-crm-search" data-testid="lnkup-find-vendors-event">
						<?php foreach ( $events as $event ) : ?>
							<option value="<?php echo esc_attr( (string) $event['event_id'] ); ?>" <?php selected( $selected, (int) $event['event_id'] ); ?>><?php echo esc_html( $event['event_title'] . ( $event['date'] ? ' — ' . $event['date'] : '' ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<label class="screen-reader-text" for="lnkup-find-vendors-category"><?php esc_html_e( 'Category', 'lnkup-marketplace' ); ?></label>
					<select id="lnkup-find-vendors-category" class="lnkup-crm-search">
						<option value=""><?php esc_html_e( 'All matching categories', 'lnkup-marketplace' ); ?></option>
						<?php
						$cats      = get_terms( array( 'taxonomy' => 'lnkup_vendor_category', 'hide_empty' => false ) );
						$cat_param = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						foreach ( is_wp_error( $cats ) ? array() : $cats as $cat ) :
							$cat_name = wp_specialchars_decode( (string) $cat->name, ENT_QUOTES );
							$is_cat   = '' !== $cat_param && ( (string) $cat->term_id === $cat_param || 0 === strcasecmp( $cat_name, $cat_param ) );
							?>
							<option value="<?php echo esc_attr( (string) $cat->term_id ); ?>"<?php selected( $is_cat ); ?>><?php echo esc_html( $cat_name ); ?></option>
						<?php endforeach; ?>
					</select>
					<label class="screen-reader-text" for="lnkup-find-vendors-section"><?php esc_html_e( 'Show', 'lnkup-marketplace' ); ?></label>
					<select id="lnkup-find-vendors-section" class="lnkup-crm-search" data-testid="lnkup-find-vendors-section">
						<option value="recommended"><?php esc_html_e( 'Recommended for this event', 'lnkup-marketplace' ); ?></option>
						<option value="new_nearby"><?php esc_html_e( 'New vendors near you', 'lnkup-marketplace' ); ?></option>
						<option value="previously_booked"><?php esc_html_e( 'Previously booked', 'lnkup-marketplace' ); ?></option>
						<option value="saved"><?php esc_html_e( 'Saved vendors', 'lnkup-marketplace' ); ?></option>
						<option value="recently_viewed"><?php esc_html_e( 'Recently viewed', 'lnkup-marketplace' ); ?></option>
					</select>
					<label class="lnkup-crm-toolbar__verified"><input type="checkbox" id="lnkup-find-vendors-verified" value="1" /> <?php esc_html_e( 'Verified only', 'lnkup-marketplace' ); ?></label>
				</div>
				<div id="lnkup-find-vendors-assistant" class="lnkup-find-vendors__assistant" aria-live="polite"></div>
				<div id="lnkup-find-vendors-composer" class="lnkup-find-vendors__composer" hidden>
					<p><strong data-selected-count>0</strong> <?php esc_html_e( 'selected', 'lnkup-marketplace' ); ?></p>
					<label for="lnkup-find-vendors-message"><?php esc_html_e( 'Note to vendors (optional)', 'lnkup-marketplace' ); ?></label>
					<textarea id="lnkup-find-vendors-message" rows="2" maxlength="1000"></textarea>
					<div id="lnkup-find-vendors-preview" class="lnkup-find-vendors__preview" hidden></div>
					<div class="lnkup-find-vendors__actions">
						<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-fv-shortlist><?php esc_html_e( 'Add to shortlist', 'lnkup-marketplace' ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" data-fv-preview data-testid="lnkup-find-vendors-preview"><?php esc_html_e( 'Preview invites', 'lnkup-marketplace' ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" data-fv-send data-testid="lnkup-find-vendors-send" hidden><?php esc_html_e( 'Send invites', 'lnkup-marketplace' ); ?></button>
					</div>
					<p class="lnkup-muted"><?php esc_html_e( "Inviting a vendor doesn't book them. They still apply, get approved and pay.", 'lnkup-marketplace' ); ?></p>
				</div>
				<p id="lnkup-find-vendors-status" class="lnkup-muted" role="status"></p>
				<div id="lnkup-find-vendors-results" class="lnkup-crm-grid" aria-live="polite"><?php echo lnkup_loading_state( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Vendor lists.
	 *
	 * @return string
	 */
	private static function render_lists() {
		ob_start();
		if ( class_exists( 'LNKup_OS_UI' ) ) {
			echo self::vendors_workspace_header( 'lists' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo self::page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				__( 'Vendor Lists', 'lnkup-marketplace' ),
				__( 'Save vendors from Discover, then invite the list to an event.', 'lnkup-marketplace' ),
				'<a class="lnkup-btn lnkup-btn--outline" href="' . esc_url( lnkup_organizer_view_url( 'vendors' ) ) . '">' . esc_html__( 'Discover vendors', 'lnkup-marketplace' ) . '</a>'
			);
		}
		echo LNKup_Shortcodes::organizer_module_card_open( 'lists', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<p id="lnkup-organizer-lists-summary" class="lnkup-muted" aria-live="polite"></p>
		<?php echo LNKup_Shortcodes::organizer_expand_panel_open( 'lists', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<div class="lnkup-organizer-smart-lists" role="group" aria-label="<?php esc_attr_e( 'Smart lists', 'lnkup-marketplace' ); ?>">
			<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( lnkup_organizer_view_url( 'vendors' ) ); ?>"><?php esc_html_e( 'Preferred Vendors', 'lnkup-marketplace' ); ?></a>
			<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( lnkup_organizer_view_url( 'applications', array( 'filter' => 'missing_docs' ) ) ); ?>"><?php esc_html_e( 'Missing Docs', 'lnkup-marketplace' ); ?></a>
			<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( lnkup_organizer_view_url( 'applications', array( 'filter' => 'unpaid' ) ) ); ?>"><?php esc_html_e( 'Unpaid Vendors', 'lnkup-marketplace' ); ?></a>
		</div>
		<form id="lnkup-crm-new-list-form" class="lnkup-crm-new-list-form">
			<label class="screen-reader-text" for="lnkup-crm-new-list-name"><?php esc_html_e( 'New list name', 'lnkup-marketplace' ); ?></label>
			<input type="text" id="lnkup-crm-new-list-name" class="lnkup-crm-search" placeholder="<?php esc_attr_e( 'New list name…', 'lnkup-marketplace' ); ?>" required />
			<button type="submit" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'Create list', 'lnkup-marketplace' ); ?></button>
		</form>
		<div id="lnkup-crm-lists" aria-live="polite"><?php echo lnkup_loading_state( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<?php
		echo LNKup_Shortcodes::organizer_expand_panel_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo LNKup_Shortcodes::organizer_module_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return (string) ob_get_clean();
	}

	/**
	 * Tracked vendor invitations.
	 *
	 * @return string
	 */
	private static function render_invites() {
		ob_start();
		if ( class_exists( 'LNKup_OS_UI' ) ) {
			echo self::vendors_workspace_header( 'invitations' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo self::page_header( __( 'Invites', 'lnkup-marketplace' ), __( 'Invite vendors to a specific event. Each invite is tracked.', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo LNKup_Shortcodes::organizer_module_card_open( 'invites', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<p id="lnkup-organizer-invites-summary" class="lnkup-muted" aria-live="polite"></p>
		<?php echo LNKup_Shortcodes::organizer_expand_panel_open( 'invites', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<div class="lnkup-crm-invites-toolbar">
			<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" id="lnkup-crm-invite-from-panel"><?php esc_html_e( 'Invite a vendor', 'lnkup-marketplace' ); ?></button>
			<p class="lnkup-muted"><?php esc_html_e( 'Pick a vendor from Discover, then choose which event to invite them to.', 'lnkup-marketplace' ); ?></p>
		</div>
		<div id="lnkup-crm-invites" aria-live="polite"><?php echo lnkup_loading_state( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<?php
		echo LNKup_Shortcodes::organizer_expand_panel_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo LNKup_Shortcodes::organizer_module_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return (string) ob_get_clean();
	}

	/**
	 * Team & Trust: organization verification and team access. org-team.js loads both panels.
	 *
	 * @return string
	 */
	/**
	 * Review workspace shell; organizer-review.js loads the queue from lnkup/v1/applications/review/queue.
	 *
	 * @return string
	 */
	private static function render_review_workspace() {
		$actions = '';
		if ( ! LNKup_Capabilities::is_team_only_member() ) {
			$actions = '<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="' . esc_url( lnkup_organizer_view_url( 'applications', array( 'mode' => 'classic' ) ) ) . '">' . esc_html__( 'Classic view', 'lnkup-marketplace' ) . '</a>';
		}
		ob_start();
		echo self::page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Review applications', 'lnkup-marketplace' ),
			__( 'Every request across your events, with reviewer scores and decisions in one place.', 'lnkup-marketplace' ),
			$actions
		);
		?>
		<section class="lnkup-review" id="lnkup-review-app" data-testid="lnkup-review-app" aria-live="polite">
			<?php echo lnkup_loading_state( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * My tasks across every event the viewer works on (organizer_ops_v2_enabled).
	 *
	 * @return string
	 */
	private static function render_my_tasks() {
		if ( ! LNKup_Feature_Flags::organizer_ops() ) {
			return self::render_home();
		}
		ob_start();
		echo self::page_header( __( 'Tasks', 'lnkup-marketplace' ), __( 'Event preparation assigned to you and your team.', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo LNKup_Shortcodes::render_ops_panel( 0, 'mytasks' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return (string) ob_get_clean();
	}

	private static function render_team() {
		ob_start();
		echo self::page_header( __( 'Team & Trust', 'lnkup-marketplace' ), __( 'Verify your organization and give your team the access they need — nothing more.', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="lnkup-org-team" data-testid="lnkup-org-team">
			<section class="lnkup-panel lnkup-org-team__card" aria-labelledby="lnkup-org-verify-title">
				<h2 id="lnkup-org-verify-title" class="lnkup-panel__title"><?php esc_html_e( 'Organization verification', 'lnkup-marketplace' ); ?></h2>
				<p class="lnkup-muted"><?php esc_html_e( 'Payouts, website domain, and LNKup review are separate checks. Vendors see which ones you have completed.', 'lnkup-marketplace' ); ?></p>
				<div id="lnkup-org-verify" aria-live="polite"><?php echo lnkup_loading_state( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</section>
			<section class="lnkup-panel lnkup-org-team__card" aria-labelledby="lnkup-org-team-title">
				<h2 id="lnkup-org-team-title" class="lnkup-panel__title"><?php esc_html_e( 'Team access', 'lnkup-marketplace' ); ?></h2>
				<div id="lnkup-org-team-list" aria-live="polite"><?php echo lnkup_loading_state( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</section>
			<section class="lnkup-panel lnkup-org-team__card" aria-labelledby="lnkup-org-team-activity-title" hidden>
				<h2 id="lnkup-org-team-activity-title" class="lnkup-panel__title"><?php esc_html_e( 'Team activity', 'lnkup-marketplace' ); ?></h2>
				<div id="lnkup-org-team-activity" aria-live="polite"><?php echo lnkup_loading_state( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</section>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Organization settings hub. Read-only summaries; every edit happens on the page that already owns it.
	 *
	 * @return string
	 */
	private static function render_settings() {
		$user_id = get_current_user_id();
		$meta    = static fn( string $k ): string => (string) get_user_meta( $user_id, '_lnkup_organizer_' . $k, true );
		$name    = '' !== $meta( 'public_name' ) ? $meta( 'public_name' ) : wp_get_current_user()->display_name;
		$logo_id = (int) $meta( 'logo_id' );
		$fields  = array(
			'public_name' => __( 'Public name', 'lnkup-marketplace' ),
			'tagline'     => __( 'Tagline', 'lnkup-marketplace' ),
			'bio'         => __( 'About', 'lnkup-marketplace' ),
			'city'        => __( 'City', 'lnkup-marketplace' ),
			'logo_id'     => __( 'Logo', 'lnkup-marketplace' ),
			'website'     => __( 'Website', 'lnkup-marketplace' ),
		);
		$missing = array();
		foreach ( $fields as $key => $label ) {
			if ( '' === $meta( $key ) || '0' === $meta( $key ) ) {
				$missing[] = $label;
			}
		}
		$profile_pct = (int) round( ( count( $fields ) - count( $missing ) ) / count( $fields ) * 100 );
		$profile_url = class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::organizer_public_url( (int) $user_id ) : '';
		$place       = function_exists( 'lnkup_display_place' ) ? lnkup_display_place( $meta( 'city' ), $meta( 'state' ) ) : trim( $meta( 'city' ) . ', ' . $meta( 'state' ), ', ' );

		$setup = self::setup_progress( $user_id );

		$connect = array();
		if ( class_exists( 'LNKup_Stripe_Connect' ) ) {
			try {
				$connect = (array) LNKup_Stripe_Connect::onboarding_status( $user_id );
			} catch ( Throwable $e ) {
				$connect = array();
			}
		}
		if ( ! empty( $connect['payouts_enabled'] ) ) {
			$payout_state = array( __( 'Payouts active', 'lnkup-marketplace' ), 'success', __( 'Vendor payments reach your bank on your payout schedule.', 'lnkup-marketplace' ) );
		} elseif ( ! empty( $connect['code'] ) ) {
			$payout_state = array( __( 'Not available yet', 'lnkup-marketplace' ), 'neutral', (string) ( $connect['message'] ?? '' ) );
		} elseif ( 'not_started' === ( $connect['status'] ?? 'not_started' ) ) {
			$payout_state = array( __( 'Not set up', 'lnkup-marketplace' ), 'warning', __( 'Add your bank details so vendor payments can be paid out to you.', 'lnkup-marketplace' ) );
		} else {
			$payout_state = array( __( 'Finish payout setup', 'lnkup-marketplace' ), 'warning', __( 'A few details are still needed before payouts can start.', 'lnkup-marketplace' ) );
		}

		$roles  = class_exists( 'LNKup_Org_Teams' ) ? LNKup_Org_Teams::roles() : array();
		$matrix = class_exists( 'LNKup_Org_Teams' ) ? LNKup_Org_Teams::capability_matrix() : array();
		$areas  = array(
			__( 'Review applications', 'lnkup-marketplace' ) => array( 'review_applications' ),
			__( 'Approve & decline', 'lnkup-marketplace' )   => array( 'manage_applications' ),
			__( 'Operations', 'lnkup-marketplace' )          => array( 'edit_ops', 'check_in' ),
			__( 'Money', 'lnkup-marketplace' )               => array( 'view_financials' ),
			__( 'Manage team', 'lnkup-marketplace' )         => array( 'manage_team' ),
		);
		$notify_url = class_exists( 'LNKup_Comms_Suppression' ) ? (string) LNKup_Comms_Suppression::preferences_url() : '';

		ob_start();
		echo LNKup_OS_UI::page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'title'    => __( 'Settings', 'lnkup-marketplace' ),
				'subtitle' => __( 'Your organization at a glance. Each card opens the page where that setting lives.', 'lnkup-marketplace' ),
				'testid'   => 'lnkup-org-settings',
			)
		);
		?>
		<div class="oos-settings">
			<?php if ( $setup ) : ?>
				<section class="vos-card oos-settings__setup" aria-labelledby="oos-settings-setup" data-testid="lnkup-org-settings-setup">
					<div>
						<h2 id="oos-settings-setup" class="lnkup-panel__title"><?php esc_html_e( 'Getting started', 'lnkup-marketplace' ); ?></h2>
						<p class="lnkup-muted"><?php echo esc_html( sprintf( /* translators: %d: percent */ __( '%d%% of setup complete.', 'lnkup-marketplace' ), $setup['percent'] ) ); ?></p>
					</div>
					<?php echo LNKup_OS_UI::progress( $setup['percent'], __( 'Setup progress', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<a class="lnkup-btn lnkup-btn--outline" href="<?php echo esc_url( $setup['url'] ); ?>"><?php esc_html_e( 'Continue setup', 'lnkup-marketplace' ); ?></a>
				</section>
			<?php endif; ?>

			<section class="vos-card" aria-labelledby="oos-settings-profile" data-testid="lnkup-org-settings-profile">
				<?php echo LNKup_OS_UI::section_head( __( 'Public profile', 'lnkup-marketplace' ), __( 'Edit profile', 'lnkup-marketplace' ), lnkup_organizer_view_url( 'profile' ), 'oos-settings-profile' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="oos-settings__profile">
					<span class="oos-settings__logo" aria-hidden="true">
						<?php echo $logo_id ? wp_get_attachment_image( $logo_id, 'thumbnail', false, array( 'alt' => '' ) ) : esc_html( LNKup_OS_UI::initials( $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</span>
					<div>
						<strong><?php echo esc_html( $name ); ?></strong>
						<?php if ( '' !== $meta( 'tagline' ) ) : ?>
							<p class="lnkup-muted"><?php echo esc_html( $meta( 'tagline' ) ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $place ) : ?>
							<p class="lnkup-muted"><?php echo esc_html( $place ); ?></p>
						<?php endif; ?>
					</div>
				</div>
				<?php echo LNKup_OS_UI::progress( $profile_pct, __( 'Profile completeness', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="lnkup-muted">
					<?php
					echo esc_html(
						$missing
							/* translators: %s: comma-separated profile fields */
							? sprintf( __( 'Vendors trust complete profiles. Missing: %s.', 'lnkup-marketplace' ), implode( ', ', $missing ) )
							: __( 'Your profile is complete.', 'lnkup-marketplace' )
					);
					?>
				</p>
				<?php if ( $profile_url ) : ?>
					<a class="vos-link" href="<?php echo esc_url( $profile_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Preview as a vendor', 'lnkup-marketplace' ); ?></a>
				<?php endif; ?>
			</section>

			<section class="vos-card" aria-labelledby="oos-settings-payments" data-testid="lnkup-org-settings-payments">
				<?php echo LNKup_OS_UI::section_head( __( 'Payments & payouts', 'lnkup-marketplace' ), __( 'Open Money', 'lnkup-marketplace' ), lnkup_organizer_view_url( 'financial', array( 'fin_tab' => 'settings' ) ), 'oos-settings-payments' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p><?php echo LNKup_OS_UI::status( $payout_state[0], $payout_state[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
				<?php if ( '' !== $payout_state[2] ) : ?>
					<p class="lnkup-muted"><?php echo esc_html( $payout_state[2] ); ?></p>
				<?php endif; ?>
				<p class="lnkup-muted"><?php esc_html_e( 'Booth fees and LNKup fees are set per vendor slot when you create or edit an event.', 'lnkup-marketplace' ); ?></p>
			</section>

			<section class="vos-card" aria-labelledby="oos-settings-notify" data-testid="lnkup-org-settings-notifications">
				<?php echo LNKup_OS_UI::section_head( __( 'Notifications', 'lnkup-marketplace' ), $notify_url ? __( 'Manage', 'lnkup-marketplace' ) : '', $notify_url, 'oos-settings-notify' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="lnkup-muted"><?php esc_html_e( 'Choose which updates reach you by email, push, or only in your LNKup inbox — new applications, payments, documents, and event reminders.', 'lnkup-marketplace' ); ?></p>
				<?php if ( ! $notify_url ) : ?>
					<p class="lnkup-muted"><?php esc_html_e( 'Notification preferences are not available on this site yet.', 'lnkup-marketplace' ); ?></p>
				<?php endif; ?>
			</section>

			<section class="vos-card" aria-labelledby="oos-settings-defaults" data-testid="lnkup-org-settings-defaults">
				<?php
				$has_tours = class_exists( 'LNKup_Feature_Flags' ) && LNKup_Feature_Flags::portfolio();
				echo LNKup_OS_UI::section_head( __( 'Defaults & templates', 'lnkup-marketplace' ), $has_tours ? __( 'Open Tours & Series', 'lnkup-marketplace' ) : __( 'Open Events', 'lnkup-marketplace' ), lnkup_organizer_view_url( $has_tours ? 'tours' : 'events' ), 'oos-settings-defaults' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<p class="lnkup-muted"><?php esc_html_e( 'Start new events from an existing one: Duplicate copies booth types, prices and application questions, and asks for the new date and location. Applications, payments and packets are never copied.', 'lnkup-marketplace' ); ?></p>
				<?php if ( $has_tours ) : ?>
					<p class="lnkup-muted"><?php esc_html_e( 'Tour templates set the booth types and prices used for every stop in a series.', 'lnkup-marketplace' ); ?></p>
				<?php endif; ?>
			</section>

			<section class="vos-card" aria-labelledby="oos-settings-help" data-testid="lnkup-org-settings-help">
				<?php echo LNKup_OS_UI::section_head( __( 'Help', 'lnkup-marketplace' ), __( 'Open Help', 'lnkup-marketplace' ), lnkup_organizer_view_url( 'help' ), 'oos-settings-help' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="lnkup-muted"><?php esc_html_e( 'Guides for reviewing applications, booth maps, check-in and payouts, plus a way to reach the LNKup team.', 'lnkup-marketplace' ); ?></p>
			</section>

			<?php if ( $roles ) : ?>
				<section class="vos-card oos-settings__team" aria-labelledby="oos-settings-team" data-testid="lnkup-org-settings-team">
					<?php echo LNKup_OS_UI::section_head( __( 'Team roles', 'lnkup-marketplace' ), __( 'Manage team', 'lnkup-marketplace' ), lnkup_organizer_view_url( 'team' ), 'oos-settings-team' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<p class="lnkup-muted"><?php esc_html_e( 'What each role can do. Only you, the account owner, can change payout details.', 'lnkup-marketplace' ); ?></p>
					<div class="vos-table-wrap">
						<table class="vos-table">
							<caption class="screen-reader-text"><?php esc_html_e( 'Team role permissions', 'lnkup-marketplace' ); ?></caption>
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Role', 'lnkup-marketplace' ); ?></th>
									<?php foreach ( array_keys( $areas ) as $area ) : ?>
										<th scope="col"><?php echo esc_html( $area ); ?></th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $roles as $role_key => $role_label ) : ?>
									<tr>
										<th scope="row"><?php echo esc_html( $role_label ); ?></th>
										<?php foreach ( $areas as $area => $caps ) : ?>
											<?php $can = (bool) array_intersect( $caps, $matrix[ $role_key ] ?? array() ); ?>
											<td><?php echo $can ? '<span aria-hidden="true">✓</span><span class="screen-reader-text">' . esc_html__( 'Yes', 'lnkup-marketplace' ) . '</span>' : '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No', 'lnkup-marketplace' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Launch an event. The setup form is open on this page.
	 *
	 * @return string
	 */
	private static function render_create() {
		$user_id = get_current_user_id();
		$events  = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => (string) $user_id,
			)
		);
		ob_start();
		?>
		<?php
		echo self::page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			function_exists( 'lnkup_copy' ) ? lnkup_copy( 'launch_event' ) : __( 'Launch event', 'lnkup-marketplace' ),
			__( 'Add the event, then the vendor slots. You review applications before anyone pays.', 'lnkup-marketplace' )
		);
		?>
		<section id="create" class="lnkup-organizer-launch-card lnkup-panel lnkup-organizer-section" data-section="create">
			<div id="lnkup-launch-success-root" class="lnkup-launch-success" hidden></div>
			<div id="lnkup-create-panel-wrap" class="lnkup-organizer-expand-panel is-open">
				<?php echo LNKup_Shortcodes::render_create_panel( $events ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Money page. Balances come from the existing financial shell.
	 *
	 * @return string
	 */
	private static function render_financial() {
		ob_start();
		echo self::page_header( __( 'Money', 'lnkup-marketplace' ), __( 'Vendor payments, payouts, and reports for your events.', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<section id="financial" class="lnkup-organizer-section-card lnkup-panel" data-section="financial">
			<div class="lnkup-organizer-section-card__body">
				<?php
				if ( class_exists( 'LNKup_Financial_Render' ) ) {
					echo LNKup_Financial_Render::organizer_shell(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Upcoming events with links into the existing operations tools.
	 *
	 * @return string
	 */
	private static function render_operations() {
		$user_id = get_current_user_id();
		$page    = lnkup_organizer_dashboard_page();
		$query   = new WP_Query(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => self::PER_PAGE,
				'paged'          => $page,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_lnkup_organizer_user_id',
						'value' => (string) $user_id,
					),
					array(
						'key'     => '_lnkup_event_date',
						'value'   => current_time( 'Y-m-d' ),
						'compare' => '>=',
						'type'    => 'DATE',
					),
				),
				'meta_key'       => '_lnkup_event_date',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
			)
		);
		ob_start();
		echo self::page_header( __( 'Operations', 'lnkup-marketplace' ), __( 'Check-in, roster, and readiness for upcoming events.', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo LNKup_Shortcodes::organizer_section_card_open( 'operations', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! $query->posts ) {
			echo lnkup_empty_state(
				array(
					'title'     => __( 'No upcoming events', 'lnkup-marketplace' ),
					'desc'      => __( 'Event-day tools appear here for events dated today or later.', 'lnkup-marketplace' ),
					'cta_label' => __( 'View events', 'lnkup-marketplace' ),
					'cta_href'  => lnkup_organizer_view_url( 'events' ),
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( class_exists( 'LNKup_OS_UI' ) ) {
			$dash = lnkup_page_url_helper( 'organizer-dashboard' );
			$rows = array();
			foreach ( $query->posts as $event ) {
				$summary = LNKup_Organizer_Presentation::event_summary( (int) $event->ID, false );
				$facts   = self::operations_card_facts( (int) $event->ID );
				$booked  = (int) $facts['booked'];
				$phase   = 'today' === $summary['chronology'] ? LNKup_OS_UI::status( __( 'Event day', 'lnkup-marketplace' ), 'warning' ) : LNKup_OS_UI::status( __( 'Before the event', 'lnkup-marketplace' ), 'info' );
				$frac    = static function ( $n ) use ( $booked ) {
					return $booked ? esc_html( sprintf( /* translators: 1: count, 2: booked */ __( '%1$d of %2$d', 'lnkup-marketplace' ), (int) $n, $booked ) ) : '<span class="lnkup-muted">—</span>';
				};
				$rows[] = array(
					'id'    => (int) $event->ID,
					'cells' => array(
						'event'   => LNKup_OS_UI::event_identity( $summary, array( 'href' => add_query_arg( array( 'event' => (int) $event->ID, 'ops' => 1 ), $dash ), 'status' => false ) ),
						'phase'   => $phase,
						'booked'  => esc_html( (string) $booked ),
						'ready'   => $frac( $facts['ready'] ),
						'booths'  => $frac( $facts['booths_assigned'] ),
						'risk'    => esc_html( (string) (int) $facts['at_risk'] ),
						'loadin'  => esc_html( '' !== $facts['loadin'] ? $facts['loadin'] : '—' ),
						'actions' => '<a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="' . esc_url( add_query_arg( array( 'event' => (int) $event->ID, 'ops' => 1 ), $dash ) ) . '">' . esc_html__( 'Open', 'lnkup-marketplace' ) . '</a> <a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="' . esc_url( add_query_arg( array( 'event' => (int) $event->ID, 'scan' => 1 ), $dash ) ) . '">' . esc_html__( 'Check-in', 'lnkup-marketplace' ) . '</a>',
					),
				);
			}
			echo LNKup_OS_UI::data_table( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'event'   => array( 'label' => __( 'Event', 'lnkup-marketplace' ), 'lead' => true ),
					'phase'   => array( 'label' => __( 'Phase', 'lnkup-marketplace' ) ),
					'booked'  => array( 'label' => __( 'Booked', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
					'ready'   => array( 'label' => __( 'Ready', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
					'booths'  => array( 'label' => __( 'Booths assigned', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
					'risk'    => array( 'label' => __( 'At risk', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
					'loadin'  => array( 'label' => __( 'Next load-in', 'lnkup-marketplace' ) ),
					'actions' => array( 'label' => __( 'Actions', 'lnkup-marketplace' ), 'class' => 'vos-actions' ),
				),
				$rows,
				array(
					'caption' => __( 'Upcoming events', 'lnkup-marketplace' ),
					'testid'  => 'lnkup-ops-table',
				)
			);
			echo '<p class="vos-footnote">' . esc_html__( 'Booked = approved or paid vendors. Ready and Booths assigned are counted against booked vendors.', 'lnkup-marketplace' ) . '</p>';
			echo lnkup_organizer_pagination_nav( (int) $query->found_posts, self::PER_PAGE, 'operations' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<div class="lnkup-workflow-grid">';
			foreach ( $query->posts as $event ) {
				$date = (string) get_post_meta( $event->ID, '_lnkup_event_date', true );
				$ops  = add_query_arg( array( 'event' => $event->ID, 'ops' => '1' ), lnkup_page_url_helper( 'organizer-dashboard' ) );
				$scan = add_query_arg( array( 'event' => $event->ID, 'scan' => '1' ), lnkup_page_url_helper( 'organizer-dashboard' ) );
				$roster = add_query_arg( array( 'event' => $event->ID, 'roster' => '1' ), lnkup_page_url_helper( 'organizer-dashboard' ) );
				$facts  = self::operations_card_facts( (int) $event->ID );
				?>
				<article class="lnkup-workflow-card lnkup-ops-list-card" data-testid="lnkup-ops-list-card">
					<div class="lnkup-ops-list-card__head">
						<h3><?php echo esc_html( LNKup_Organizer_Presentation::title( (int) $event->ID ) ); ?></h3>
						<p class="lnkup-muted"><?php echo esc_html( implode( ' · ', array_filter( array( $date ? date_i18n( 'D, M j, Y', strtotime( $date ) ) : '', $facts['where'] ) ) ) ); ?></p>
					</div>
					<dl class="lnkup-organizer-event-card__stats">
						<div>
							<dt><?php esc_html_e( 'Booked', 'lnkup-marketplace' ); ?></dt>
							<dd><?php echo esc_html( (string) $facts['booked'] ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Ready', 'lnkup-marketplace' ); ?></dt>
							<dd><?php echo $facts['booked'] ? esc_html( sprintf( /* translators: 1: ready vendors, 2: booked vendors */ __( '%1$d of %2$d', 'lnkup-marketplace' ), $facts['ready'], $facts['booked'] ) ) : '—'; ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Booths assigned', 'lnkup-marketplace' ); ?></dt>
							<dd><?php echo $facts['booked'] ? esc_html( sprintf( /* translators: 1: assigned, 2: booked */ __( '%1$d of %2$d', 'lnkup-marketplace' ), $facts['booths_assigned'], $facts['booked'] ) ) : '—'; ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'At risk', 'lnkup-marketplace' ); ?></dt>
							<dd><?php echo esc_html( (string) $facts['at_risk'] ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Next load-in', 'lnkup-marketplace' ); ?></dt>
							<dd><?php echo esc_html( $facts['loadin'] ? $facts['loadin'] : '—' ); ?></dd>
						</div>
					</dl>
					<p class="lnkup-ops-list-card__actions">
						<a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="<?php echo esc_url( $ops ); ?>"><?php esc_html_e( 'Open operations', 'lnkup-marketplace' ); ?></a>
						<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="<?php echo esc_url( $roster ); ?>"><?php esc_html_e( 'Roster', 'lnkup-marketplace' ); ?></a>
						<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( $scan ); ?>"><?php esc_html_e( 'Check in', 'lnkup-marketplace' ); ?></a>
					</p>
				</article>
				<?php
			}
			echo '</div>';
			echo lnkup_organizer_pagination_nav( (int) $query->found_posts, self::PER_PAGE, 'operations' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		wp_reset_postdata();
		echo LNKup_Shortcodes::organizer_section_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return (string) ob_get_clean();
	}

	/**
	 * Booked vendors, readiness, and next load-in window for one Operations card.
	 * Avoids LNKup_Event_Operations::snapshot(), which also loads weather and the feed.
	 *
	 * @param int $event_id Event ID.
	 * @return array{booked:int,ready:int,booths_assigned:int,readiness:int,at_risk:int,loadin:string,where:string}
	 */
	private static function operations_card_facts( $event_id ) {
		$summary = class_exists( 'LNKup_Vendor_Readiness' ) ? LNKup_Vendor_Readiness::event_readiness_summary( $event_id ) : array();
		$loadin  = '';
		if ( class_exists( 'LNKup_Loadin_Schedule' ) ) {
			$now = current_time( 'timestamp' );
			foreach ( (array) LNKup_Loadin_Schedule::get_windows( $event_id ) as $window ) {
				$start = strtotime( (string) ( $window['start_at'] ?? '' ) );
				if ( $start && $start >= $now ) {
					$loadin = date_i18n( 'M j', $start ) . ' ' . (string) ( $window['label'] ?? '' );
					break;
				}
			}
		}
		$city     = LNKup_Organizer_Presentation::place( (int) $event_id );
		$venue    = (string) get_post_meta( $event_id, '_lnkup_venue_name', true );
		$assigned = 0;
		if ( class_exists( 'LNKup_Operations_Tables' ) ) {
			global $wpdb;
			$booths   = LNKup_Operations_Tables::booths_table();
			$assigned = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT application_id) FROM {$booths} WHERE event_id = %d AND application_id > 0", $event_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return array(
			'booked'          => (int) ( $summary['vendor_count'] ?? 0 ),
			'ready'           => (int) ( $summary['ready_count'] ?? 0 ),
			'booths_assigned' => $assigned,
			'readiness'       => (int) ( $summary['percent'] ?? 0 ),
			'at_risk'   => (int) ( $summary['at_risk_count'] ?? 0 ),
			'loadin'    => trim( $loadin ),
			'where'     => implode( ', ', array_filter( array( $venue, $city ) ) ),
		);
	}

	/**
	 * Funnel stages from canonical application status and check-in meta. Page views are not
	 * tracked, so the funnel starts at Applications.
	 *
	 * @param int $user_id Organizer user ID.
	 * @return array{totals:array<string,int>,events:array<int,array<string,mixed>>}
	 */
	public static function intel_funnel( $user_id ) {
		$stages = array( 'applications' => 0, 'approvals' => 0, 'payments' => 0, 'confirmed' => 0, 'checkins' => 0 );
		$apps   = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		update_meta_cache( 'post', $apps );
		$totals = $stages;
		$events = array();
		foreach ( $apps as $app_id ) {
			$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
			if ( ! $event_id ) {
				continue;
			}
			$status = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
			if ( 'cancelled' === $status && ! get_post_meta( $app_id, '_lnkup_approved_at', true ) ) {
				continue;
			}
			$row = array(
				'applications' => 1,
				'approvals'    => ( in_array( $status, array( 'approved', 'payment_required', 'paid', 'completed' ), true ) || get_post_meta( $app_id, '_lnkup_approved_at', true ) ) ? 1 : 0,
				'payments'     => '' !== (string) get_post_meta( $app_id, '_lnkup_paid_at', true ) || in_array( $status, array( 'paid', 'completed' ), true ) ? 1 : 0,
				'confirmed'    => in_array( $status, array( 'paid', 'completed' ), true ) ? 1 : 0,
				'checkins'     => '' !== (string) get_post_meta( $app_id, '_lnkup_checked_in_at', true ) ? 1 : 0,
			);
			if ( ! isset( $events[ $event_id ] ) ) {
				$events[ $event_id ] = array_merge( array( 'event_id' => $event_id, 'title' => get_the_title( $event_id ), 'date' => (string) get_post_meta( $event_id, '_lnkup_event_date', true ) ), $stages );
			}
			foreach ( $row as $key => $value ) {
				$totals[ $key ]              += $value;
				$events[ $event_id ][ $key ] += $value;
			}
		}
		usort(
			$events,
			static function ( $a, $b ) {
				return strcmp( (string) $b['date'], (string) $a['date'] );
			}
		);
		return array( 'totals' => $totals, 'events' => array_slice( $events, 0, 8 ) );
	}

	/**
	 * @param int $user_id Organizer user ID.
	 * @return string
	 */
	private static function render_intel_funnel( $user_id ) {
		$funnel = self::intel_funnel( $user_id );
		$labels = array(
			'applications' => __( 'Applications', 'lnkup-marketplace' ),
			'approvals'    => __( 'Approvals', 'lnkup-marketplace' ),
			'payments'     => __( 'Payments', 'lnkup-marketplace' ),
			'confirmed'    => __( 'Confirmed', 'lnkup-marketplace' ),
			'checkins'     => __( 'Check-ins', 'lnkup-marketplace' ),
		);
		$top = max( 1, (int) $funnel['totals']['applications'] );
		ob_start();
		?>
		<section class="lnkup-intel-funnel" data-testid="lnkup-intel-funnel" aria-labelledby="lnkup-intel-funnel-title">
			<h2 class="lnkup-panel__title" id="lnkup-intel-funnel-title"><?php esc_html_e( 'Vendor funnel', 'lnkup-marketplace' ); ?></h2>
			<?php if ( 0 === (int) $funnel['totals']['applications'] ) : ?>
				<p class="lnkup-muted"><?php esc_html_e( 'The funnel fills in as vendors apply to your events.', 'lnkup-marketplace' ); ?></p>
			<?php else : ?>
				<ol class="lnkup-intel-funnel__stages">
					<?php foreach ( $labels as $key => $label ) : ?>
						<?php $value = (int) $funnel['totals'][ $key ]; ?>
						<li class="lnkup-intel-funnel__stage">
							<span class="lnkup-intel-funnel__label"><?php echo esc_html( $label ); ?></span>
							<span class="lnkup-intel-funnel__bar" aria-hidden="true"><span style="width:<?php echo esc_attr( (string) round( ( $value / $top ) * 100 ) ); ?>%"></span></span>
							<strong class="lnkup-intel-funnel__value"><?php echo esc_html( (string) $value ); ?></strong>
						</li>
					<?php endforeach; ?>
				</ol>
				<?php if ( count( $funnel['events'] ) > 1 ) : ?>
					<h3 class="lnkup-intel-funnel__subtitle"><?php esc_html_e( 'Compare events', 'lnkup-marketplace' ); ?></h3>
					<div class="lnkup-table-wrap">
						<table class="lnkup-table" data-testid="lnkup-intel-compare">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></th>
									<?php foreach ( $labels as $label ) : ?>
										<th scope="col"><?php echo esc_html( $label ); ?></th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $funnel['events'] as $event ) : ?>
									<tr>
										<th scope="row"><a href="<?php echo esc_url( add_query_arg( 'event', (int) $event['event_id'], lnkup_page_url_helper( 'organizer-dashboard' ) ) ); ?>"><?php echo esc_html( (string) $event['title'] ); ?></a></th>
										<?php foreach ( array_keys( $labels ) as $key ) : ?>
											<td><?php echo esc_html( (string) (int) $event[ $key ] ); ?></td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Intelligence summaries from existing records.
	 *
	 * @return string
	 */
	private static function render_intelligence() {
		$user_id = get_current_user_id();
		ob_start();
		echo self::page_header( __( 'Intelligence', 'lnkup-marketplace' ), __( 'Event health, risks, and recommended next steps.', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo LNKup_Shortcodes::organizer_module_card_open( 'intelligence', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div id="lnkup-organizer-intel-summary" class="lnkup-organizer-intel-summary" data-loaded="1" data-ssr="1">
			<?php echo LNKup_Shortcodes::render_organizer_intel_summary_ssr( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php echo self::render_intel_funnel( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php echo LNKup_Shortcodes::organizer_expand_panel_open( 'intelligence', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<div id="lnkup-organizer-intelligence" class="lnkup-intel-shell" data-organizer-id="<?php echo esc_attr( (string) $user_id ); ?>" aria-live="polite">
			<div class="lnkup-crm-skeleton lnkup-crm-skeleton--tall" aria-hidden="true"></div>
		</div>
		<?php
		echo LNKup_Shortcodes::organizer_expand_panel_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo LNKup_Shortcodes::organizer_module_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return (string) ob_get_clean();
	}

	/**
	 * Organizer referral hub.
	 *
	 * @return string
	 */
	private static function render_referrals() {
		ob_start();
		echo self::page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Referral Invites', 'lnkup-marketplace' ),
			class_exists( 'LNKup_Organizer_Recruit_Render' ) ? LNKup_Organizer_Recruit_Render::headline() : ''
		);
		?>
		<section id="referrals" class="lnkup-organizer-section-card lnkup-panel" data-section="referrals">
			<?php
			LNKup_Shortcodes::enqueue_organizer_recruit_assets();
			if ( class_exists( 'LNKup_Organizer_Recruit_Render' ) ) {
				echo LNKup_Organizer_Recruit_Render::hub_shell( 'organizer', array( 'show_title' => false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	private static function render_help() {
		ob_start();
		echo self::page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_eyebrow' ) : __( 'Help', 'lnkup-marketplace' ),
			__( 'Guides for launching events, reviewing vendors, payouts, and event day.', 'lnkup-marketplace' )
		);
		?>
		<div id="help" data-section="help">
			<?php
			if ( class_exists( 'LNKup_Knowledge_Base' ) ) {
				echo LNKup_Knowledge_Base::render( 'organizer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( class_exists( 'LNKup_App_Promo' ) ) {
				echo LNKup_App_Promo::render_preference_toggle(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Dialogs used by vendor import and intelligence.
	 *
	 * @return string
	 */
	private static function render_overlays() {
		ob_start();
		echo LNKup_Shortcodes::intel_drawer_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div id="lnkup-vendor-import-wizard" class="lnkup-vendor-import" hidden data-testid="lnkup-vendor-import-wizard" aria-hidden="true">
			<div class="lnkup-vendor-import__overlay" data-import-close></div>
			<div class="lnkup-vendor-import__panel" role="dialog" aria-modal="true" aria-labelledby="lnkup-import-wizard-title">
				<header class="lnkup-vendor-import__header">
					<h2 id="lnkup-import-wizard-title"><?php esc_html_e( 'Import vendors', 'lnkup-marketplace' ); ?></h2>
					<button type="button" class="lnkup-vendor-import__close" data-import-close aria-label="<?php esc_attr_e( 'Close', 'lnkup-marketplace' ); ?>">&times;</button>
				</header>
				<div class="lnkup-vendor-import__body">
					<section class="lnkup-vendor-import__pane is-active" data-import-step="1">
						<p class="lnkup-muted"><?php esc_html_e( 'Upload a CSV. Nobody is emailed until you choose to invite them.', 'lnkup-marketplace' ); ?></p>
						<div class="lnkup-vendor-import__dropzone" id="lnkup-import-dropzone">
							<label class="lnkup-btn lnkup-btn--outline lnkup-btn--sm">
								<?php esc_html_e( 'Choose file', 'lnkup-marketplace' ); ?>
								<input type="file" id="lnkup-import-file" accept=".csv,text/csv" hidden />
							</label>
						</div>
						<p><a href="<?php echo esc_url( rest_url( 'lnkup/v1/crm/import/template' ) ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-testid="lnkup-import-template-link"><?php esc_html_e( 'Download CSV template', 'lnkup-marketplace' ); ?></a></p>
						<div id="lnkup-import-upload-status" class="lnkup-vendor-import__status" hidden></div>
					</section>
					<section class="lnkup-vendor-import__pane" data-import-step="2" hidden>
						<div id="lnkup-import-mapping" class="lnkup-vendor-import__mapping"></div>
					</section>
					<section class="lnkup-vendor-import__pane" data-import-step="3" hidden>
						<div id="lnkup-import-summary"></div>
						<select id="lnkup-import-duplicate-mode">
							<option value="skip"><?php esc_html_e( 'Skip duplicates', 'lnkup-marketplace' ); ?></option>
							<option value="update"><?php esc_html_e( 'Update existing records', 'lnkup-marketplace' ); ?></option>
							<option value="list_only"><?php esc_html_e( 'Add to list only', 'lnkup-marketplace' ); ?></option>
						</select>
						<select id="lnkup-import-list-select"><option value=""><?php esc_html_e( 'None', 'lnkup-marketplace' ); ?></option></select>
						<input type="text" id="lnkup-import-new-list" />
						<input type="text" id="lnkup-import-tags" />
						<select id="lnkup-import-event"><option value=""><?php esc_html_e( 'None', 'lnkup-marketplace' ); ?></option></select>
						<select id="lnkup-import-opportunity"><option value=""><?php esc_html_e( 'None', 'lnkup-marketplace' ); ?></option></select>
						<label><input type="checkbox" id="lnkup-import-send-invites" /> <?php esc_html_e( 'Send invites after import', 'lnkup-marketplace' ); ?></label>
						<label id="lnkup-import-invite-confirm-wrap" hidden><input type="checkbox" id="lnkup-import-confirm-invites" /> <?php esc_html_e( 'I understand invites will be queued.', 'lnkup-marketplace' ); ?></label>
						<label id="lnkup-import-consent-wrap" hidden><?php esc_html_e( 'How did these vendors agree to hear from you?', 'lnkup-marketplace' ); ?>
							<select id="lnkup-import-consent">
								<option value=""><?php esc_html_e( 'Choose one (required to send invites)', 'lnkup-marketplace' ); ?></option>
								<?php foreach ( LNKup_Vendor_Import::consent_bases() as $lnkup_basis => $lnkup_basis_label ) : ?>
									<option value="<?php echo esc_attr( $lnkup_basis ); ?>"><?php echo esc_html( $lnkup_basis_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label><?php esc_html_e( 'These contacts are', 'lnkup-marketplace' ); ?>
							<select id="lnkup-import-preset">
								<option value=""><?php esc_html_e( 'Vendor contacts', 'lnkup-marketplace' ); ?></option>
								<option value="past_applicants"><?php esc_html_e( 'Past applicants from another platform (tagged past-applicant)', 'lnkup-marketplace' ); ?></option>
							</select>
						</label>
						<p class="lnkup-muted"><?php esc_html_e( 'Imported contacts stay in your CRM as prospects. They only become LNKup vendors if they sign up themselves.', 'lnkup-marketplace' ); ?></p>
					</section>
					<section class="lnkup-vendor-import__pane" data-import-step="4" hidden>
						<div id="lnkup-import-results"></div>
						<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" id="lnkup-import-view-imported"><?php esc_html_e( 'View imported vendors', 'lnkup-marketplace' ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" id="lnkup-import-back-crm" data-import-close><?php esc_html_e( 'Back', 'lnkup-marketplace' ); ?></button>
					</section>
				</div>
				<footer class="lnkup-vendor-import__footer">
					<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" id="lnkup-import-back" hidden><?php esc_html_e( 'Back', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" id="lnkup-import-next" disabled><?php esc_html_e( 'Continue', 'lnkup-marketplace' ); ?></button>
				</footer>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
