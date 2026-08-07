<?php
/**
 * Bump the plugin version everywhere it is recorded, in one step.
 *
 * The version appears in four places and they must agree. In particular
 * WordPress.org compares readme.txt's "Stable tag" against the plugin header's
 * "Version": if they drift, the wrong build gets served to users.
 *
 * Usage:
 *   php tools/bump-version.php patch     1.2.0 -> 1.2.1
 *   php tools/bump-version.php minor     1.2.0 -> 1.3.0
 *   php tools/bump-version.php major     1.2.0 -> 2.0.0
 *   php tools/bump-version.php 1.4.2     set explicitly
 *   php tools/bump-version.php --check   verify all four agree, change nothing
 *
 * This file is development tooling and is excluded from the distributed plugin.
 *
 * @package Open_Migration
 */

// Development tooling: this script rewrites files, so it must never be reachable
// over HTTP. It is excluded from the distributed plugin, but guard it anyway in
// case the repository itself is ever served.
if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'This script can only be run from the command line.' );
}

$root = dirname( __DIR__ );
$arg  = isset( $argv[1] ) ? trim( $argv[1] ) : '--check';

/**
 * Locate the main plugin file by its header, so a rename does not break this.
 */
function owpm_find_main_file( $root ) {
	foreach ( glob( $root . '/*.php' ) as $file ) {
		$head = file_get_contents( $file, false, null, 0, 2048 );
		if ( false !== strpos( $head, 'Plugin Name:' ) ) {
			return $file;
		}
	}
	return null;
}

$main = owpm_find_main_file( $root );

if ( ! $main ) {
	fwrite( STDERR, "Could not find the main plugin file (no 'Plugin Name:' header).\n" );
	exit( 1 );
}

$readme    = $root . '/readme.txt';
$readme_md = $root . '/README.md';

// Each entry: file, regex with the version in group 1, human label.
$targets = array(
	array( $main, '/^(\s*\*\s*Version:\s*)(\d+\.\d+\.\d+)/m', 'plugin header Version' ),
	array( $main, "/(define\(\s*'OWPM_VERSION',\s*')(\d+\.\d+\.\d+)(')/", 'OWPM_VERSION constant' ),
	array( $readme, '/^(Stable tag:\s*)(\d+\.\d+\.\d+)/m', 'readme.txt Stable tag' ),
	array( $readme_md, '/(Version-)(\d+\.\d+\.\d+)(-orange)/', 'README.md badge' ),
);

/**
 * Read the current value of each target.
 */
function owpm_read_versions( $targets ) {
	$found = array();

	foreach ( $targets as $i => $t ) {
		list( $file, $pattern, $label ) = $t;

		if ( ! file_exists( $file ) ) {
			$found[ $i ] = array( 'label' => $label, 'file' => $file, 'version' => null, 'missing' => true );
			continue;
		}

		$body = file_get_contents( $file );
		$hit  = preg_match( $pattern, $body, $m );

		$found[ $i ] = array(
			'label'   => $label,
			'file'    => $file,
			'version' => $hit ? $m[2] : null,
			'missing' => ! $hit,
		);
	}

	return $found;
}

$current = owpm_read_versions( $targets );

// The plugin header is the source of truth.
$base = $current[0]['version'];

if ( ! $base ) {
	fwrite( STDERR, "Could not read the current version from the plugin header.\n" );
	exit( 1 );
}

/* ------------------------------------------------------------------ */
/* --check: report agreement and stop                                  */
/* ------------------------------------------------------------------ */

if ( '--check' === $arg ) {
	$ok = true;

	echo "Current version: $base\n\n";

	foreach ( $current as $c ) {
		$state = '  ok  ';

		if ( $c['missing'] ) {
			$state = ' MISS ';
			$ok    = false;
		} elseif ( $c['version'] !== $base ) {
			$state = 'MISMATCH';
			$ok    = false;
		}

		printf( "[%s] %-24s %s\n", $state, $c['label'], $c['version'] ? $c['version'] : '-' );
	}

	// A changelog entry is required for the release to be meaningful.
	if ( file_exists( $readme ) ) {
		$rd = file_get_contents( $readme );
		if ( false === strpos( $rd, '= ' . $base . ' =' ) ) {
			echo "\nWARNING: readme.txt has no changelog section '= $base ='\n";
			$ok = false;
		}
	}

	echo $ok ? "\nAll version references agree.\n" : "\nVersion references need attention.\n";
	exit( $ok ? 0 : 1 );
}

/* ------------------------------------------------------------------ */
/* Work out the new version                                            */
/* ------------------------------------------------------------------ */

list( $major, $minor, $patch ) = array_map( 'intval', explode( '.', $base ) );

if ( 'patch' === $arg ) {
	$patch++;
} elseif ( 'minor' === $arg ) {
	$minor++;
	$patch = 0;
} elseif ( 'major' === $arg ) {
	$major++;
	$minor = 0;
	$patch = 0;
} elseif ( preg_match( '/^\d+\.\d+\.\d+$/', $arg ) ) {
	list( $major, $minor, $patch ) = array_map( 'intval', explode( '.', $arg ) );
} else {
	fwrite( STDERR, "Unknown argument '$arg'. Use patch, minor, major, an explicit x.y.z, or --check.\n" );
	exit( 1 );
}

$next = "$major.$minor.$patch";

if ( version_compare( $next, $base, '<=' ) && $next !== $base ) {
	fwrite( STDERR, "Refusing to move backwards: $base -> $next\n" );
	exit( 1 );
}

/* ------------------------------------------------------------------ */
/* Apply                                                               */
/* ------------------------------------------------------------------ */

foreach ( $targets as $t ) {
	list( $file, $pattern, $label ) = $t;

	if ( ! file_exists( $file ) ) {
		printf( "  skipped  %-24s (file not present)\n", $label );
		continue;
	}

	$body = file_get_contents( $file );

	$updated = preg_replace_callback(
		$pattern,
		function ( $m ) use ( $next ) {
			// Group 1 is the prefix, 2 the version, 3 the suffix when present.
			return $m[1] . $next . ( isset( $m[3] ) ? $m[3] : '' );
		},
		$body,
		1,
		$count
	);

	if ( $count ) {
		file_put_contents( $file, $updated );
		printf( "  updated  %-24s %s -> %s\n", $label, $base, $next );
	} else {
		printf( "  NOT FOUND %-23s in %s\n", $label, basename( $file ) );
	}
}

// Remind about the changelog rather than inventing an entry.
if ( file_exists( $readme ) ) {
	$rd = file_get_contents( $readme );
	if ( false === strpos( $rd, '= ' . $next . ' =' ) ) {
		echo "\nNEXT: add a '= $next =' section under == Changelog == in readme.txt\n";
	}
}

echo "\nNow at $next\n";
