<?php
/**
 * Shared cinematic hero canvas (photo + wash + optional orbs).
 *
 * @package LNKup_Theme
 *
 * @var array $args {
 *     @type string $photo     Image URL.
 *     @type string $stamp     Optional vertical mono stamp.
 *     @type bool   $show_orbs Whether to show lime orbs and grid.
 * }
 */

$photo     = isset( $args['photo'] ) ? (string) $args['photo'] : '';
$stamp     = isset( $args['stamp'] ) ? (string) $args['stamp'] : '';
$show_orbs = ! isset( $args['show_orbs'] ) || $args['show_orbs'];
?>
<div class="lnkup-hero__canvas" aria-hidden="true">
	<?php if ( $photo ) : ?>
		<img class="lnkup-hero__photo" src="<?php echo esc_url( $photo ); ?>" alt="" width="1920" height="1080" decoding="async" fetchpriority="high" />
	<?php endif; ?>
	<div class="lnkup-hero__wash"></div>
	<div class="lnkup-hero__vignette"></div>
	<?php if ( $show_orbs ) : ?>
		<div class="lnkup-hero__grid-pattern"></div>
		<div class="lnkup-hero__orb lnkup-hero__orb--primary"></div>
		<div class="lnkup-hero__orb lnkup-hero__orb--secondary"></div>
	<?php endif; ?>
</div>
<?php if ( $stamp ) : ?>
	<span class="lnkup-hero__stamp"><?php echo esc_html( $stamp ); ?></span>
<?php endif; ?>
