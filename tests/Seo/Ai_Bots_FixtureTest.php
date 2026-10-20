<?php
/**
 * `inc/Seo/fixtures/ai-bots.json` is the cross-language bot contract (plan
 * §4.7). woo-dash copies it verbatim for its AI-bot settings form, so it must
 * match the PHP maps exactly: Bot_Crawl_Logger::BOTS (UA tokens),
 * Robots_Txt::ROBOTS_TOKENS (robots.txt lines), Settings::AI_BOT_SLUGS
 * (blockable slugs).
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Engine\Bot_Crawl_Logger;
use StoreDash\Seo\Engine\Robots_Txt;
use StoreDash\Seo\Settings;

require_once __DIR__ . '/seo-stubs.php';

/**
 * @coversNothing
 */
class Ai_Bots_FixtureTest extends TestCase {

	private function fixture(): array {
		$raw = file_get_contents( __DIR__ . '/../../inc/Seo/fixtures/ai-bots.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertIsString( $raw );
		$data = json_decode( $raw, true );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'bots', $data );
		return $data['bots'];
	}

	public function test_slugs_and_ua_tokens_match_the_logger_map() {
		$bots = $this->fixture();
		$this->assertSame( array_keys( Bot_Crawl_Logger::BOTS ), array_keys( $bots ), 'same slugs, same order' );
		foreach ( $bots as $slug => $bot ) {
			$this->assertSame( Bot_Crawl_Logger::BOTS[ $slug ], $bot['ua'], $slug );
		}
	}

	public function test_blockable_and_robots_tokens_match_settings_and_robots() {
		foreach ( $this->fixture() as $slug => $bot ) {
			$blockable = in_array( $slug, Settings::AI_BOT_SLUGS, true );
			$this->assertSame( $blockable, $bot['blockable'], $slug );
			$this->assertSame( $blockable ? Robots_Txt::ROBOTS_TOKENS[ $slug ] : null, $bot['robots_token'], $slug );
		}
		$this->assertSame( Settings::AI_BOT_SLUGS, array_keys( Robots_Txt::ROBOTS_TOKENS ) );
	}

	public function test_kinds_are_known_and_search_engines_are_never_blockable() {
		foreach ( $this->fixture() as $slug => $bot ) {
			$this->assertContains( $bot['kind'], array( 'ai_training', 'ai_search', 'ai_user', 'search' ), $slug );
			if ( in_array( $slug, array( 'googlebot', 'bingbot' ), true ) ) {
				$this->assertSame( 'search', $bot['kind'] );
				$this->assertFalse( $bot['blockable'] );
			}
		}
	}
}
