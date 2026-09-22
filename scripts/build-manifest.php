#!/usr/bin/env php
<?php
/**
 * Write the release manifest (dist/storedash.json) that installed plugins poll
 * for updates (see inc/core/class-storedash-updater.php).
 *
 * Everything is derived from files in the repo so the manifest cannot drift
 * from the zip it describes:
 *   - version / requires / requires_php  ← storedash.php headers
 *   - tested                              ← readme.txt "Tested up to"
 *   - sections.description / changelog    ← readme.txt
 *   - sha256                              ← the built zip
 *   - package                             ← STOREDASH_RELEASE_BASE_URL (set by
 *     the release workflow) + "/storedash.zip"; defaults to the GitHub release
 *     URL for this version so a local build points at the same place CI would.
 *
 * Usage: php scripts/build-manifest.php <path/to/storedash.zip> <out.json>
 *
 * Standalone CLI script — runs outside WordPress, so no ABSPATH guard.
 *
 * @package Storedash
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "build-manifest.php must be run from the command line.\n" );
	exit( 1 );
}

$zip_path = $argv[1] ?? '';
$out_path = $argv[2] ?? '';
if ( '' === $zip_path || '' === $out_path || ! is_file( $zip_path ) ) {
	fwrite( STDERR, "Usage: build-manifest.php <storedash.zip> <out.json>\n" );
	exit( 1 );
}

$root   = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/storedash.php' );
$readme = file_get_contents( $root . '/readme.txt' );

/**
 * Read a "Key: value" header from a file header block.
 */
$header = static function ( string $haystack, string $key ): string {
	if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $key, '/' ) . ':\s*(.+?)\s*$/mi', $haystack, $m ) ) {
		return trim( $m[1] );
	}
	return '';
};

$version      = $header( $plugin, 'Version' );
$requires     = $header( $plugin, 'Requires at least' );
$requires_php = $header( $plugin, 'Requires PHP' );
$name         = $header( $plugin, 'Plugin Name' );
$author       = $header( $plugin, 'Author' );
$homepage     = $header( $plugin, 'Plugin URI' );
$tested       = $header( $readme, 'Tested up to' );
$stable_tag   = $header( $readme, 'Stable tag' );

if ( '' === $version ) {
	fwrite( STDERR, "Could not read Version from storedash.php\n" );
	exit( 1 );
}
if ( $stable_tag !== $version ) {
	fwrite( STDERR, "readme.txt Stable tag ($stable_tag) does not match storedash.php Version ($version)\n" );
	exit( 1 );
}

/**
 * Extract a "== Section ==" block from readme.txt.
 */
$section = static function ( string $readme, string $title ): string {
	if ( preg_match( '/^== ' . preg_quote( $title, '/' ) . ' ==\s*\n(.*?)(?=^== |\z)/ms', $readme, $m ) ) {
		return trim( $m[1] );
	}
	return '';
};

/**
 * Convert the readme's markdown-ish subset to HTML: "= x =" → <h4>, "* item" → <ul>,
 * `code` → <code>, blank-line separated paragraphs.
 */
$to_html = static function ( string $text ): string {
	$html    = '';
	$in_list = false;
	$para    = array();
	$esc     = static function ( string $s ): string {
		$s = htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
		$s = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $s );
		return preg_replace( '/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '<a href="$2">$1</a>', $s );
	};
	$flush   = static function () use ( &$html, &$para, &$in_list, $esc ) {
		if ( $in_list ) {
			$html   .= "</ul>\n";
			$in_list = false;
		}
		if ( $para ) {
			$html .= '<p>' . $esc( implode( ' ', $para ) ) . "</p>\n";
			$para  = array();
		}
	};

	foreach ( explode( "\n", $text ) as $line ) {
		$line = rtrim( $line );
		if ( preg_match( '/^= (.+) =$/', $line, $m ) ) {
			$flush();
			$html .= '<h4>' . $esc( $m[1] ) . "</h4>\n";
		} elseif ( preg_match( '/^\* (.+)$/', $line, $m ) ) {
			if ( $para ) {
				$flush();
			}
			if ( ! $in_list ) {
				$html   .= "<ul>\n";
				$in_list = true;
			}
			$html .= '<li>' . $esc( $m[1] ) . "</li>\n";
		} elseif ( '' === $line ) {
			$flush();
		} elseif ( $in_list && preg_match( '/^\s+\S/', $line ) ) {
			// Continuation of the previous list item.
			$html = preg_replace( '/<\/li>\n$/', ' ' . $esc( trim( $line ) ) . "</li>\n", $html );
		} else {
			if ( $in_list ) {
				$flush();
			}
			$para[] = trim( $line );
		}
	}
	$flush();
	return trim( $html );
};

// Keep the changelog short: the newest 8 releases.
$changelog = $section( $readme, 'Changelog' );
$entries   = preg_split( '/^(?== )/m', $changelog, -1, PREG_SPLIT_NO_EMPTY );
$changelog = implode( "\n", array_slice( $entries, 0, 8 ) );

$base_url = rtrim( getenv( 'STOREDASH_RELEASE_BASE_URL' ) ?: "https://github.com/hafsteinnvidar/storedash-wp-helper/releases/download/v{$version}", '/' );

$manifest = array(
	'name'         => $name,
	'slug'         => 'storedash',
	'version'      => $version,
	'package'      => $base_url . '/storedash.zip',
	'sha256'       => hash_file( 'sha256', $zip_path ),
	'homepage'     => $homepage,
	'author'       => $author,
	'requires'     => $requires,
	'tested'       => $tested,
	'requires_php' => $requires_php,
	'last_updated' => gmdate( 'Y-m-d H:i:s' ),
	'sections'     => array(
		'description' => $to_html( $section( $readme, 'Description' ) ),
		'changelog'   => $to_html( $changelog ),
	),
);

$json = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( false === $json || false === file_put_contents( $out_path, $json . "\n" ) ) {
	fwrite( STDERR, "Failed to write $out_path\n" );
	exit( 1 );
}

echo "[build-manifest] Wrote $out_path (version $version, package {$manifest['package']})\n";
