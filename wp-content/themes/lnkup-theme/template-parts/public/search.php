<?php
/**
 * Role-aware search. Plain GET forms, so every search is a shareable URL that the Find Events
 * feed (/opportunities/) and the vendor directory (/vendors/) already understand.
 *
 * $args: mode (events|vendors), id (unique prefix), city (prefill), category (prefill label).
 *
 * @package LNKup_Theme
 */

$open      = lnkup_theme_marketplace_open();
$mode      = isset( $args['mode'] ) && 'vendors' === $args['mode'] ? 'vendors' : 'events';
$mode      = $open ? $mode : 'vendors';
$prefix    = isset( $args['id'] ) ? sanitize_key( (string) $args['id'] ) : 'lp-search';
$prefill   = isset( $args['city'] ) ? (string) $args['city'] : '';
$pre_state = isset( $args['state'] ) ? (string) $args['state'] : '';
$pre_cat   = isset( $args['category'] ) ? (string) $args['category'] : '';
$discovery = lnkup_public_discovery();
$cities    = array();
foreach ( (array) ( $discovery['city_index'] ?? array() ) as $row ) {
	$cities[] = array( 'label' => (string) $row['label'], 'city' => (string) $row['city'], 'state' => (string) $row['state'] );
}
$categories = (array) ( $discovery['categories'] ?? array() );
?>
<div class="lp-search" data-testid="lnkup-hero-search">
	<?php if ( $open ) : ?>
		<div class="lp-search__tabs" role="tablist" aria-label="<?php esc_attr_e( 'What are you looking for?', 'lnkup-theme' ); ?>" data-lp-tabs>
			<button type="button" class="lp-search__tab" role="tab" id="<?php echo esc_attr( $prefix ); ?>-tab-events" aria-controls="<?php echo esc_attr( $prefix ); ?>-events" aria-selected="<?php echo 'events' === $mode ? 'true' : 'false'; ?>" tabindex="<?php echo 'events' === $mode ? '0' : '-1'; ?>"><?php esc_html_e( 'Events for vendors', 'lnkup-theme' ); ?></button>
			<button type="button" class="lp-search__tab" role="tab" id="<?php echo esc_attr( $prefix ); ?>-tab-vendors" aria-controls="<?php echo esc_attr( $prefix ); ?>-vendors" aria-selected="<?php echo 'vendors' === $mode ? 'true' : 'false'; ?>" tabindex="<?php echo 'vendors' === $mode ? '0' : '-1'; ?>"><?php esc_html_e( 'Vendors for events', 'lnkup-theme' ); ?></button>
		</div>

		<form class="lp-search__form" id="<?php echo esc_attr( $prefix ); ?>-events" role="tabpanel" aria-labelledby="<?php echo esc_attr( $prefix ); ?>-tab-events" action="<?php echo esc_url( lnkup_theme_opportunities_url() ); ?>" method="get" data-lp-search-form<?php echo 'events' === $mode ? '' : ' hidden'; ?>>
			<div class="lp-search__field">
				<label for="<?php echo esc_attr( $prefix ); ?>-q"><?php esc_html_e( 'Event or keyword', 'lnkup-theme' ); ?></label>
				<input type="search" id="<?php echo esc_attr( $prefix ); ?>-q" name="q" maxlength="80" placeholder="<?php esc_attr_e( 'Festival, market, food truck…', 'lnkup-theme' ); ?>" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $prefix ); ?>-q-list" data-lp-suggest="events" data-lp-cities="<?php echo esc_attr( wp_json_encode( $cities ) ); ?>" data-lp-live="<?php echo esc_attr( $prefix ); ?>-live">
				<ul class="lp-suggest" id="<?php echo esc_attr( $prefix ); ?>-q-list" role="listbox" hidden></ul>
			</div>
			<div class="lp-search__field">
				<label for="<?php echo esc_attr( $prefix ); ?>-city"><?php esc_html_e( 'City', 'lnkup-theme' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $prefix ); ?>-city" name="city" maxlength="80" value="<?php echo esc_attr( $prefill ); ?>" placeholder="<?php esc_attr_e( 'Any city', 'lnkup-theme' ); ?>" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $prefix ); ?>-city-list" data-lp-suggest="city" data-lp-cities="<?php echo esc_attr( wp_json_encode( $cities ) ); ?>" data-lp-live="<?php echo esc_attr( $prefix ); ?>-live">
				<input type="hidden" name="state" value="<?php echo esc_attr( $pre_state ); ?>">
				<ul class="lp-suggest" id="<?php echo esc_attr( $prefix ); ?>-city-list" role="listbox" hidden></ul>
			</div>
			<div class="lp-search__field">
				<label for="<?php echo esc_attr( $prefix ); ?>-when"><?php esc_html_e( 'When', 'lnkup-theme' ); ?></label>
				<select id="<?php echo esc_attr( $prefix ); ?>-when" name="date_preset">
					<option value=""><?php esc_html_e( 'Any date', 'lnkup-theme' ); ?></option>
					<option value="weekend"><?php esc_html_e( 'This weekend', 'lnkup-theme' ); ?></option>
					<option value="this_month"><?php esc_html_e( 'This month', 'lnkup-theme' ); ?></option>
					<option value="next_30"><?php esc_html_e( 'Next 30 days', 'lnkup-theme' ); ?></option>
				</select>
			</div>
			<?php if ( '' !== $pre_cat ) : ?>
				<input type="hidden" name="categories" value="<?php echo esc_attr( $pre_cat ); ?>">
			<?php endif; ?>
			<button type="submit" class="lp-btn lp-btn--lime lp-search__submit"><?php echo lnkup_public_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Search events', 'lnkup-theme' ); ?></button>
		</form>
	<?php endif; ?>

	<form class="lp-search__form lp-search__form--vendors" id="<?php echo esc_attr( $prefix ); ?>-vendors" <?php echo $open ? 'role="tabpanel" aria-labelledby="' . esc_attr( $prefix ) . '-tab-vendors"' : ''; ?> action="<?php echo esc_url( lnkup_page_url( 'vendors' ) ); ?>" method="get" data-lp-search-form<?php echo 'vendors' === $mode ? '' : ' hidden'; ?>>
		<div class="lp-search__field">
			<label for="<?php echo esc_attr( $prefix ); ?>-vq"><?php esc_html_e( 'Vendor or business', 'lnkup-theme' ); ?></label>
			<input type="search" id="<?php echo esc_attr( $prefix ); ?>-vq" name="q" maxlength="80" placeholder="<?php esc_attr_e( 'Tacos, jewelry, photo booth…', 'lnkup-theme' ); ?>">
		</div>
		<div class="lp-search__field">
			<label for="<?php echo esc_attr( $prefix ); ?>-vcity"><?php esc_html_e( 'City', 'lnkup-theme' ); ?></label>
			<input type="text" id="<?php echo esc_attr( $prefix ); ?>-vcity" name="city" maxlength="80" value="<?php echo esc_attr( $prefill ); ?>" placeholder="<?php esc_attr_e( 'Any city', 'lnkup-theme' ); ?>">
		</div>
		<div class="lp-search__field">
			<label for="<?php echo esc_attr( $prefix ); ?>-vcat"><?php esc_html_e( 'Category', 'lnkup-theme' ); ?></label>
			<select id="<?php echo esc_attr( $prefix ); ?>-vcat" name="category">
				<option value=""><?php esc_html_e( 'Any category', 'lnkup-theme' ); ?></option>
				<?php foreach ( $categories as $cat ) : ?>
					<?php $term = ! empty( $cat['terms'][0] ) ? (string) $cat['terms'][0] : (string) $cat['label']; ?>
					<option value="<?php echo esc_attr( $term ); ?>" <?php selected( $pre_cat, (string) $cat['label'] ); ?>><?php echo esc_html( (string) $cat['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<button type="submit" class="lp-btn lp-btn--lime lp-search__submit"><?php echo lnkup_public_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Find vendors', 'lnkup-theme' ); ?></button>
	</form>
	<p class="lp-visually-hidden" id="<?php echo esc_attr( $prefix ); ?>-live" aria-live="polite"></p>
</div>
