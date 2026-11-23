<?php
/**
 * Run `php -l` on every plugin and theme PHP file inside the wp-env cli container.
 *
 * Usage: powershell -File scripts/e2e/wpe.ps1 php /var/www/html/wp-content/lnkup-e2e/lint.php
 */

$roots = array(
	'/var/www/html/wp-content/plugins/lnkup-marketplace',
	'/var/www/html/wp-content/plugins/lnkup-communications',
	'/var/www/html/wp-content/themes/lnkup-theme',
);
$count = 0;
$fails = 0;
foreach ( $roots as $root ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$path = $file->getPathname();
		if ( '.php' !== substr( $path, -4 ) || false !== strpos( $path, '/vendor/' ) || false !== strpos( $path, '/node_modules/' ) ) {
			continue;
		}
		++$count;
		$out = shell_exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1' );
		if ( false === strpos( (string) $out, 'No syntax errors' ) ) {
			++$fails;
			echo trim( (string) $out ) . PHP_EOL;
		}
	}
}
echo "php -l: {$count} files, {$fails} failures" . PHP_EOL;
exit( $fails ? 1 : 0 );
