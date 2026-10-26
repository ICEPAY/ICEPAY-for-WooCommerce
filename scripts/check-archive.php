<?php

declare( strict_types=1 );

$archive = new ZipArchive();
if ( $archive->open( $argv[1] ?? '' ) !== true ) {
	fwrite( STDERR, "Unable to open plugin archive.\n" );
	exit( 1 );
}
$required = [ 'ICEPAY-for-WooCommerce.php', 'vendor/autoload.php', 'build/index.js', 'build/index.asset.php',
	'languages/icepay-for-woocommerce-de_DE.mo', 'languages/icepay-for-woocommerce-nl_NL.mo' ];
foreach ( $required as $file ) {
	if ( $archive->locateName( $file ) === false ) {
		throw new RuntimeException( 'Missing release file: ' . $file );
	}
}
for ( $i = 0; $i < $archive->numFiles; ++$i ) {
	$name = $archive->getNameIndex( $i );
	if ( preg_match( '~^(tests/|scripts/|wordpress/|node_modules/|docker/|blocks/|dist/|\.git/|\.github/|\.idea/|\.env|phpcs\.xml|phpstan\.neon)|\.zip$~', $name ) ) {
		throw new RuntimeException( 'Unexpected development file in release: ' . $name );
	}
}
fwrite( STDOUT, "Plugin archive contains runtime files and excludes development files.\n" );
