<?php
/**
 * Tests for StoreDash_Updater::normalize_manifest() — the pure validation step
 * between the downloaded release manifest and what is handed to WordPress core.
 */

require_once dirname( __DIR__, 2 ) . '/inc/core/class-storedash-updater.php';

use PHPUnit\Framework\TestCase;

class StoreDash_UpdaterTest extends TestCase {

	private function valid_manifest(): array {
		return array(
			'name'         => 'Storedash Helper',
			'version'      => '1.19.0',
			'package'      => 'https://github.com/hafsteinnvidar/storedash-wp-helper/releases/download/v1.19.0/storedash.zip',
			'sha256'       => str_repeat( 'AB', 32 ),
			'homepage'     => 'https://storedash.app',
			'requires'     => '5.8',
			'tested'       => '7.0',
			'requires_php' => '7.4',
			'last_updated' => '2026-09-22 10:00:00',
			'sections'     => array(
				'description' => '<p>Hello</p>',
				'changelog'   => '<h4>1.19.0</h4>',
			),
			'icons'        => array( '1x' => 'https://cdn.example/icon.png' ),
		);
	}

	public function test_valid_manifest_is_normalized(): void {
		$m = StoreDash_Updater::normalize_manifest( $this->valid_manifest() );

		$this->assertSame( '1.19.0', $m['version'] );
		$this->assertSame( str_repeat( 'ab', 32 ), $m['sha256'], 'sha256 is lowercased for hash_equals' );
		$this->assertSame( '7.0', $m['tested'] );
		$this->assertSame( array( 'description' => '<p>Hello</p>', 'changelog' => '<h4>1.19.0</h4>' ), $m['sections'] );
		$this->assertSame( array( '1x' => 'https://cdn.example/icon.png' ), $m['icons'] );
		$this->assertSame( array(), $m['banners'] );
	}

	public function test_missing_or_malformed_version_is_rejected(): void {
		$this->assertNull( StoreDash_Updater::normalize_manifest( array( 'package' => 'https://x/y.zip' ) ) );
		$this->assertNull( StoreDash_Updater::normalize_manifest( array( 'version' => 'latest', 'package' => 'https://x/y.zip' ) ) );
		$this->assertNull( StoreDash_Updater::normalize_manifest( array( 'version' => '', 'package' => 'https://x/y.zip' ) ) );
	}

	public function test_prerelease_version_is_accepted(): void {
		$m = StoreDash_Updater::normalize_manifest( array( 'version' => '1.20.0-beta.1', 'package' => 'https://x/y.zip' ) );
		$this->assertSame( '1.20.0-beta.1', $m['version'] );
	}

	public function test_non_https_package_is_rejected(): void {
		$this->assertNull( StoreDash_Updater::normalize_manifest( array( 'version' => '1.0.0', 'package' => 'http://x/y.zip' ) ) );
		$this->assertNull( StoreDash_Updater::normalize_manifest( array( 'version' => '1.0.0' ) ) );
	}

	public function test_invalid_sha256_is_dropped_not_fatal(): void {
		$m = StoreDash_Updater::normalize_manifest( array( 'version' => '1.0.0', 'package' => 'https://x/y.zip', 'sha256' => 'nope' ) );
		$this->assertSame( '', $m['sha256'] );
	}

	public function test_defaults_fill_optional_fields(): void {
		$m = StoreDash_Updater::normalize_manifest( array( 'version' => '1.0.0', 'package' => 'https://x/y.zip' ) );
		$this->assertSame( 'Storedash Helper', $m['name'] );
		$this->assertSame( 'https://storedash.app', $m['homepage'] );
		$this->assertSame( '5.8', $m['requires'] );
		$this->assertSame( '7.4', $m['requires_php'] );
		$this->assertSame( '', $m['tested'] );
		$this->assertSame( array(), $m['sections'] );
	}

	public function test_non_https_icon_urls_and_non_string_sections_are_dropped(): void {
		$m = StoreDash_Updater::normalize_manifest(
			array(
				'version'  => '1.0.0',
				'package'  => 'https://x/y.zip',
				'icons'    => array( '1x' => 'http://insecure/icon.png', '2x' => 'https://ok/icon.png' ),
				'sections' => array( 'changelog' => array( 'not', 'a', 'string' ), 'description' => '' ),
			)
		);
		$this->assertSame( array( '2x' => 'https://ok/icon.png' ), $m['icons'] );
		$this->assertSame( array(), $m['sections'] );
	}
}
