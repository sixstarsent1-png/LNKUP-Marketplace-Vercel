<?php
/**
 * Fallback public tour page when the active theme has no page-templates/tour-public.php.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="main" class="lnkup-mp">
	<?php echo LNKup_Portfolio_Public::render_current(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</main>
<?php
get_footer();
