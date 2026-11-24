<?php
/**
 * Live opportunities: upcoming / this weekend / closing soon. Tabs with no events are dropped.
 *
 * @package LNKup_Theme
 */

$sets = array(
	'upcoming' => array( 'label' => __( 'Coming up', 'lnkup-theme' ), 'url' => add_query_arg( 'sort', 'soonest', lnkup_theme_opportunities_url() ) ),
	'weekend'  => array( 'label' => __( 'This weekend', 'lnkup-theme' ), 'url' => add_query_arg( 'date_preset', 'weekend', lnkup_theme_opportunities_url() ) ),
	'closing'  => array( 'label' => __( 'Closing soon', 'lnkup-theme' ), 'url' => add_query_arg( 'closing_soon', '1', lnkup_theme_opportunities_url() ) ),
);
$panels = array();
foreach ( $sets as $key => $set ) {
	$cards = lnkup_public_event_cards( $key, 6 );
	if ( $cards ) {
		$panels[ $key ] = array_merge( $set, array( 'cards' => $cards ) );
	}
}
?>
<section class="lp-section" id="opportunities" aria-labelledby="lp-live-title" data-testid="lp-live-events">
	<div class="lp-container">
		<?php
		lnkup_public_section_head(
			array(
				'eyebrow'      => __( 'Live on LNKup', 'lnkup-theme' ),
				'title_html'   => __( 'Events booking vendors <em>right now</em>', 'lnkup-theme' ),
				'id'           => 'lp-live-title',
				'lead'         => __( 'Discover upcoming festivals, markets, and events actively accepting vendors.', 'lnkup-theme' ),
				'action_label' => $panels ? __( 'Browse all events', 'lnkup-theme' ) : '',
				'action_url'   => lnkup_theme_opportunities_url(),
			)
		);
		?>
		<?php if ( ! $panels ) : ?>
			<div class="lp-empty" data-testid="lp-live-empty">
				<h3><?php esc_html_e( 'New events are added every week', 'lnkup-theme' ); ?></h3>
				<p><?php esc_html_e( 'No events are taking vendor applications at this moment. Create a free profile now so you can apply as soon as events in your city open.', 'lnkup-theme' ); ?></p>
				<a class="lp-btn lp-btn--primary" href="<?php echo esc_url( lnkup_public_signup_url( 'vendor' ) ); ?>"><?php esc_html_e( 'Create a free vendor profile', 'lnkup-theme' ); ?></a>
			</div>
		<?php else : ?>
			<?php if ( count( $panels ) > 1 ) : ?>
				<div class="lp-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Event lists', 'lnkup-theme' ); ?>" data-lp-tabs style="margin-bottom:var(--lp-space-5)">
					<?php $first = true; ?>
					<?php foreach ( $panels as $key => $panel ) : ?>
						<button type="button" class="lp-tabs__tab" role="tab" id="lp-live-tab-<?php echo esc_attr( $key ); ?>" aria-controls="lp-live-<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo $first ? 'true' : 'false'; ?>" tabindex="<?php echo $first ? '0' : '-1'; ?>"><?php echo esc_html( $panel['label'] ); ?></button>
						<?php $first = false; ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php $first = true; ?>
			<?php foreach ( $panels as $key => $panel ) : ?>
				<div class="lp-tabpanel" id="lp-live-<?php echo esc_attr( $key ); ?>" <?php echo count( $panels ) > 1 ? 'role="tabpanel" aria-labelledby="lp-live-tab-' . esc_attr( $key ) . '" tabindex="0"' : ''; ?><?php echo $first ? '' : ' hidden'; ?>>
					<div class="lp-rail" style="--lp-rail-cols:3">
						<?php foreach ( $panel['cards'] as $i => $card ) : ?>
							<?php get_template_part( 'template-parts/public/event-card', null, array( 'card' => $card, 'testid' => 'lnkup-featured-card' ) ); ?>
						<?php endforeach; ?>
					</div>
					<p style="margin-top:var(--lp-space-5)"><a class="lp-link" href="<?php echo esc_url( $panel['url'] ); ?>"><?php echo esc_html( sprintf( /* translators: %s: list name */ __( 'See all: %s', 'lnkup-theme' ), $panel['label'] ) ); ?> <?php echo lnkup_public_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></p>
				</div>
				<?php $first = false; ?>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</section>
