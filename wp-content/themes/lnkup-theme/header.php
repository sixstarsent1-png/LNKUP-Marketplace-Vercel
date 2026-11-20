<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#0c0d10">
	<script>
	(function () {
		var ua = navigator.userAgent || '';
		var iOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
		var android = /Android/i.test(ua);
		document.documentElement.classList.add(iOS ? 'lnkup-ios' : (android ? 'lnkup-android' : 'lnkup-desktop'));
	})();
	</script>
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
