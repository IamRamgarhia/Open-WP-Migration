<?php
/**
 * Build the distributable plugin zip.
 *
 * Ships only what a user needs at runtime. Development files, previous build
 * artefacts and the documentation source are excluded: hidden files are rejected
 * outright by the directory's checks, and the rest is dead weight in an
 * installer.
 *
 * Usage, from the plugin root:
 *   php -d extension=zip tools/build-zip.php
 *   php -d extension=zip tools/build-zip.php ../open-migration-1.3.8.zip
 *
 * The output path defaults to a sibling of the plugin folder, never inside it,
 * so a build can never end up nested in the next one.
 *
 * @package Open_Migration
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'This script can only be run from the command line.' );
}

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The zip extension is required. Try: php -d extension=zip tools/build-zip.php\n" );
	exit( 1 );
}

$root = str_replace( '\\', '/', dirname( __DIR__ ) );
$slug = basename( $root );

// Read the version and slug from the plugin header, so neither can drift.
//
// The folder inside the zip must be the plugin slug. Take it from the Text Domain
// header, which the directory requires to equal the slug, rather than from the
// working directory name -- those are allowed to differ.
$version = '';
foreach ( glob( $root . '/*.php' ) as $file ) {
	$head = file_get_contents( $file, false, null, 0, 2048 );

	if ( false === strpos( $head, 'Plugin Name:' ) ) {
		continue;
	}

	if ( preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $head, $m ) ) {
		$version = $m[1];
	}

	if ( preg_match( '/^\s*\*\s*Text Domain:\s*(\S+)/m', $head, $td ) ) {
		$slug = $td[1];
	}

	break;
}

$output = isset( $argv[1] )
	? str_replace( '\\', '/', $argv[1] )
	: dirname( $root ) . '/' . $slug . ( $version ? '-' . $version : '' ) . '.zip';

$skip_dirs  = array( '.git', '.github', 'node_modules', 'vendor', 'tests', 'tools', 'docs', 'assets/images' );
$skip_files = array( 'README.md', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', '.distignore' );

/**
 * Decide whether a path belongs in the distributed plugin.
 *
 * @return string|false Reason for skipping, or false to include.
 */
function owpm_skip_reason( $rel, $skip_dirs, $skip_files ) {
	foreach ( explode( '/', $rel ) as $segment ) {
		if ( '' !== $segment && '.' === $segment[0] ) {
			return 'hidden file';
		}
	}

	foreach ( $skip_dirs as $dir ) {
		if ( $rel === $dir || 0 === strpos( $rel, $dir . '/' ) ) {
			return 'dev directory';
		}
	}

	if ( in_array( $rel, $skip_files, true ) ) {
		return 'dev file';
	}

	if ( '.zip' === strtolower( substr( $rel, -4 ) ) ) {
		return 'previous build';
	}

	return false;
}

if ( file_exists( $output ) ) {
	unlink( $output );
}

$zip = new ZipArchive();

if ( true !== $zip->open( $output, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Could not create $output\n" );
	exit( 1 );
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

$included = array();
$excluded = array();

foreach ( $iterator as $item ) {
	$path = str_replace( '\\', '/', $item->getPathname() );
	$rel  = ltrim( substr( $path, strlen( $root ) ), '/' );

	$reason = owpm_skip_reason( $rel, $skip_dirs, $skip_files );

	if ( $reason ) {
		if ( ! $item->isDir() ) {
			$excluded[] = $rel . '  (' . $reason . ')';
		}
		continue;
	}

	if ( $item->isDir() ) {
		continue;
	}

	// WordPress expects the plugin inside a folder named for its slug.
	$zip->addFile( $path, $slug . '/' . $rel );
	$included[] = $rel;
}

$zip->close();

sort( $included );

echo "SHIPPED (" . count( $included ) . " files)\n";
foreach ( $included as $f ) {
	echo "  + $f\n";
}

echo "\nEXCLUDED: " . count( $excluded ) . " files\n";
echo "\nBUILT  $output  (" . round( filesize( $output ) / 1024, 1 ) . " KB)\n";
