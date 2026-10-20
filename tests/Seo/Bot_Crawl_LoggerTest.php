<?php
/**
 * Bot_Crawl_Logger: UA matcher, transient layout + cap, flush payload + signature.
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Engine\Bot_Crawl_Logger;

require_once __DIR__ . '/seo-stubs.php';

/**
 * @covers \StoreDash\Seo\Engine\Bot_Crawl_Logger
 */
class Bot_Crawl_LoggerTest extends TestCase {

	/** @var array */
	private $store_value = false;
	/** @var int */
	private $store_gets = 0;
	/** @var int */
	private $store_sets = 0;
	/** @var int */
	private $store_deletes = 0;
	/** @var array */
	private $sent = array();
	/** @var bool Sender result. */
	private $send_ok = true;

	protected function setUp(): void {
		$this->send_ok               = true;
		$GLOBALS['__seo_transients'] = array();
		$GLOBALS['__seo_is_404']     = false;
		unset( $GLOBALS['__seo_options'][ Bot_Crawl_Logger::STATS_OPTION ], $_SERVER['HTTP_USER_AGENT'], $_SERVER['REQUEST_URI'] );
		$this->store_value   = false;
		$this->store_gets    = 0;
		$this->store_sets    = 0;
		$this->store_deletes = 0;
		$this->sent          = array();
		$GLOBALS['__seo_options']['woodash_store_id']       = '1105';
		$GLOBALS['__seo_options']['woodash_webhook_secret'] = 'top-secret';
	}

	private function logger( $url = 'https://webhooks.storedash.io/seo', $drainer = null ): Bot_Crawl_Logger {
		$self  = $this;
		$store = function ( $op, $value = null ) use ( $self ) {
			if ( 'get' === $op ) {
				++$self->store_gets;
				return $self->store_value;
			}
			if ( 'set' === $op ) {
				++$self->store_sets;
				$self->store_value = $value;
				return true;
			}
			++$self->store_deletes;
			$self->store_value = false;
			return true;
		};
		$sender = function ( $to, $body, $headers ) use ( $self ) {
			$self->sent[] = compact( 'to', 'body', 'headers' );
			return $self->send_ok;
		};
		return new Bot_Crawl_Logger(
			$store,
			$sender,
			static function () use ( $url ) {
				return $url;
			},
			$drainer
		);
	}

	/**
	 * @dataProvider ua_cases
	 */
	public function test_matches_vendor_user_agents( string $ua, $expected ) {
		$this->assertSame( $expected, Bot_Crawl_Logger::match( $ua ) );
	}

	public function ua_cases(): array {
		return array(
			'gptbot'             => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.4; +https://openai.com/gptbot', 'gptbot' ),
			'oai-searchbot'      => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.4; +https://openai.com/searchbot', 'oai-searchbot' ),
			'chatgpt-user'       => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot', 'chatgpt-user' ),
			'claudebot'          => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 'claudebot' ),
			'claude-user'        => array( 'Mozilla/5.0 (compatible; Claude-User/1.0; +Claude-User@anthropic.com)', 'claude-user' ),
			'perplexitybot'      => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)', 'perplexitybot' ),
			'perplexity-user'    => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)', 'perplexity-user' ),
			'google-extended'    => array( 'Google-Extended', 'google-extended' ),
			'googlebot desktop'  => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Googlebot/2.1; +http://www.google.com/bot.html) Chrome/120.0 Safari/537.36', 'googlebot' ),
			'googlebot mobile'   => array( 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'googlebot' ),
			'bingbot'            => array( 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', 'bingbot' ),
			'applebot'           => array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)', 'applebot' ),
			'amazonbot'          => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Amazonbot/0.1) Chrome/120.0 Safari/537.36', 'amazonbot' ),
			'bytespider'         => array( 'Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; Bytespider; spider-feedback@bytedance.com)', 'bytespider' ),
			'ccbot'              => array( 'CCBot/2.0 (https://commoncrawl.org/faq/)', 'ccbot' ),
			'meta-externalagent' => array( 'meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)', 'meta-externalagent' ),
			'case-insensitive'   => array( 'gptbot/1.0', 'gptbot' ),
			'chrome'             => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', null ),
			'safari'             => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1', null ),
			'facebook preview'   => array( 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', null ),
			'empty'              => array( '', null ),
		);
	}

	public function test_regex_is_one_memoized_alternation_longest_token_first() {
		$regex = Bot_Crawl_Logger::regex();
		$this->assertSame( $regex, Bot_Crawl_Logger::regex(), 'compiled once' );
		$this->assertSame( 1, preg_match( '/^\/\((.+)\)\/i$/', $regex, $m ), 'single /(...)/i alternation' );
		$tokens = explode( '|', $m[1] );
		$this->assertCount( count( Bot_Crawl_Logger::BOTS ), $tokens );
		$this->assertLessThan( array_search( 'Googlebot', $tokens, true ), array_search( 'Google\-Extended', $tokens, true ), 'google-extended before googlebot' );
		$this->assertLessThan( array_search( 'PerplexityBot', $tokens, true ), array_search( 'Perplexity\-User', $tokens, true ) );
		$this->assertSame( 0, preg_match( $regex, 'Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0' ), 'non-bot: the one preg_match returns 0' );
	}

	/**
	 * @dataProvider path_cases
	 */
	public function test_path_from_uri( string $uri, string $expected ) {
		$this->assertSame( $expected, Bot_Crawl_Logger::path_from_uri( $uri ) );
	}

	public function path_cases(): array {
		return array(
			array( '/product/x/?utm=1&b=2', '/product/x/' ),
			array( '/product/x/#frag', '/product/x/' ),
			array( '', '/' ),
			array( 'product/x', '/product/x' ),
			array( '/' . str_repeat( 'a', 2500 ), '/' . str_repeat( 'a', Bot_Crawl_Logger::MAX_PATH - 1 ) ),
		);
	}

	public function test_add_hit_increments_and_keeps_product_id() {
		$key  = Bot_Crawl_Logger::key( '2026-10-19', 'gptbot', '/product/x/' );
		$hits = Bot_Crawl_Logger::add_hit( array(), $key, 123 );
		$this->assertSame( array( $key => array( 1, 123 ) ), $hits );
		$hits = Bot_Crawl_Logger::add_hit( $hits, $key, 0 );
		$this->assertSame( array( 2, 123 ), $hits[ $key ], 'product id survives a hit without one' );
		$hits = Bot_Crawl_Logger::add_hit( $hits, Bot_Crawl_Logger::key( '2026-10-19', 'claudebot', '/' ), 0 );
		$this->assertSame( array( 1, 0 ), $hits['2026-10-19|claudebot|/'] );
		$this->assertCount( 2, $hits );
	}

	public function test_cap_drops_oldest_date_keys_first() {
		$hits = array();
		for ( $i = 0; $i < 2500; $i++ ) {
			$hits[ Bot_Crawl_Logger::key( '2026-10-18', 'gptbot', '/old/' . $i ) ] = array( 1, 0 );
		}
		for ( $i = 0; $i < 2500; $i++ ) {
			$hits[ Bot_Crawl_Logger::key( '2026-10-19', 'gptbot', '/new/' . $i ) ] = array( 1, 0 );
		}
		$this->assertCount( Bot_Crawl_Logger::MAX_KEYS, $hits );

		$hits = Bot_Crawl_Logger::add_hit( $hits, Bot_Crawl_Logger::key( '2026-10-19', 'claudebot', '/fresh/' ), 7 );
		$this->assertCount( Bot_Crawl_Logger::MAX_KEYS, $hits );
		$this->assertArrayHasKey( '2026-10-19|claudebot|/fresh/', $hits );
		$this->assertArrayNotHasKey( '2026-10-18|gptbot|/old/0', $hits, 'the oldest date key went first' );
		$this->assertArrayHasKey( '2026-10-18|gptbot|/old/999', $hits, 'only one key was dropped' );
		$this->assertArrayHasKey( '2026-10-19|gptbot|/new/0', $hits );

		// An existing key never triggers the cap.
		$again = Bot_Crawl_Logger::add_hit( $hits, '2026-10-19|gptbot|/new/0', 0 );
		$this->assertCount( Bot_Crawl_Logger::MAX_KEYS, $again );
		$this->assertSame( array( 2, 0 ), $again['2026-10-19|gptbot|/new/0'] );
	}

	public function test_record_reads_and_writes_the_store_once() {
		$logger = $this->logger();
		$logger->record( 'gptbot', '/product/x/?ref=1', 42, '2026-10-19' );
		$logger->record( 'gptbot', '/product/x/', 0, '2026-10-19' );
		$this->assertSame( 2, $this->store_gets );
		$this->assertSame( 2, $this->store_sets );
		$this->assertSame( array( '2026-10-19|gptbot|/product/x/' => array( 2, 42 ) ), $this->store_value );
	}

	public function test_rows_sorted_with_null_product_id() {
		$rows = Bot_Crawl_Logger::rows(
			array(
				'2026-10-19|gptbot|/b/'   => array( 3, 0 ),
				'2026-10-18|claudebot|/a/' => array( 1, 55 ),
				'garbage'                  => array( 9, 0 ),
				'2026-10-19|gptbot|/zero/' => array( 0, 0 ),
				'2026-10-19|gptbot|/legacy/' => 2,
			)
		);
		$this->assertSame(
			array(
				array( 'date' => '2026-10-18', 'bot' => 'claudebot', 'path' => '/a/', 'product_id' => 55, 'hits' => 1 ),
				array( 'date' => '2026-10-19', 'bot' => 'gptbot', 'path' => '/b/', 'product_id' => null, 'hits' => 3 ),
				array( 'date' => '2026-10-19', 'bot' => 'gptbot', 'path' => '/legacy/', 'product_id' => null, 'hits' => 2 ),
			),
			$rows
		);
	}

	public function test_build_payloads_chunks_and_puts_redirects_on_the_first() {
		$hits = array();
		for ( $i = 0; $i < 4500; $i++ ) {
			$hits[ Bot_Crawl_Logger::key( '2026-10-19', 'gptbot', '/p/' . $i ) ] = array( 1, 0 );
		}
		$redirects = array( array( 'source_path' => '/old/', 'hits' => 2 ), array( 'source_path' => '/none/', 'hits' => 0 ), 'junk' );
		$payloads  = Bot_Crawl_Logger::build_payloads( $hits, $redirects, '1105', 'https://shop.test', '2026-10-19 12:00:00' );

		$this->assertCount( 3, $payloads );
		$this->assertSame( array( 2000, 2000, 500 ), array_map( 'count', array_column( $payloads, 'crawls' ) ) );
		$this->assertSame( array( array( 'source_path' => '/old/', 'hits' => 2 ) ), $payloads[0]['redirect_hits'] );
		$this->assertSame( array(), $payloads[1]['redirect_hits'] );
		$this->assertSame( array( 'action', 'store_id', 'store_url', 'timestamp', 'plugin_version', 'crawls', 'redirect_hits' ), array_keys( $payloads[2] ) );
		$this->assertSame( 'seo.crawl_batch', $payloads[2]['action'] );
		$this->assertSame( '1105', $payloads[2]['store_id'] );

		$this->assertSame( array(), Bot_Crawl_Logger::build_payloads( array(), array(), '1', 'u', 't' ), 'both empty → nothing' );
		$only_redirects = Bot_Crawl_Logger::build_payloads( array(), $redirects, '1', 'u', 't' );
		$this->assertCount( 1, $only_redirects );
		$this->assertSame( array(), $only_redirects[0]['crawls'] );
	}

	public function test_sign_is_hmac_sha256_hex_of_exact_body() {
		$body = '{"action":"seo.crawl_batch","store_id":"1105"}';
		$this->assertSame( hash_hmac( 'sha256', $body, 'top-secret' ), Bot_Crawl_Logger::sign( $body, 'top-secret' ) );
		$this->assertSame( 64, strlen( Bot_Crawl_Logger::sign( $body, 'top-secret' ) ) );
		$this->assertNotSame( Bot_Crawl_Logger::sign( $body, 'top-secret' ), Bot_Crawl_Logger::sign( $body . ' ', 'top-secret' ) );
	}

	public function test_flush_with_empty_url_sends_nothing_and_keeps_the_transient() {
		$this->store_value = array( '2026-10-19|gptbot|/x/' => array( 1, 0 ) );
		$logger            = $this->logger( '' );
		$this->assertSame( 0, $logger->flush() );
		$this->assertSame( array(), $this->sent );
		$this->assertSame( 0, $this->store_gets );
		$this->assertSame( 0, $this->store_deletes );
		$this->assertSame( array( '2026-10-19|gptbot|/x/' => array( 1, 0 ) ), $this->store_value );
	}

	public function test_flush_posts_signed_payload_and_clears_the_transient() {
		$this->store_value = array(
			'2026-10-19|gptbot|/product/x/' => array( 4, 123 ),
			'2026-10-19|claudebot|/'        => array( 1, 0 ),
		);
		$logger            = $this->logger();
		$this->assertSame( 1, $logger->flush() );
		$this->assertSame( 1, $this->store_deletes );
		$this->assertFalse( $this->store_value );
		$this->assertCount( 1, $this->sent );

		$req = $this->sent[0];
		$this->assertSame( 'https://webhooks.storedash.io/seo', $req['to'] );
		$this->assertSame( 'application/json', $req['headers']['Content-Type'] );
		$this->assertSame( hash_hmac( 'sha256', $req['body'], 'top-secret' ), $req['headers']['X-WooDash-Signature'], 'signature over the exact bytes' );

		$payload = json_decode( $req['body'], true );
		$this->assertSame(
			array(
				'action'        => 'seo.crawl_batch',
				'store_id'      => '1105',
				'store_url'     => 'https://shop.test',
				'timestamp'     => '2026-10-19 12:00:00',
				'plugin_version' => defined( 'STOREDASH_VERSION' ) ? STOREDASH_VERSION : '',
				'crawls'        => array(
					array( 'date' => '2026-10-19', 'bot' => 'claudebot', 'path' => '/', 'product_id' => null, 'hits' => 1 ),
					array( 'date' => '2026-10-19', 'bot' => 'gptbot', 'path' => '/product/x/', 'product_id' => 123, 'hits' => 4 ),
				),
				'redirect_hits' => array(),
			),
			$payload,
			'B2 class absent → redirect_hits is [] and nothing fails'
		);
	}

	public function test_flush_drains_redirect_hits_and_skips_when_everything_is_empty() {
		$drained = 0;
		$logger  = $this->logger(
			'https://webhooks.storedash.io/seo',
			static function () use ( &$drained ) {
				++$drained;
				return array( array( 'source_path' => '/old/', 'hits' => 2 ) );
			}
		);
		$this->assertSame( 1, $logger->flush() );
		$this->assertSame( 1, $drained );
		$this->assertSame( array( array( 'source_path' => '/old/', 'hits' => 2 ) ), json_decode( $this->sent[0]['body'], true )['redirect_hits'] );
		$this->assertSame( 0, $this->store_deletes, 'empty transient is not deleted' );

		$this->sent = array();
		$quiet      = $this->logger(
			'https://webhooks.storedash.io/seo',
			static function () {
				return array();
			}
		);
		$this->assertSame( 0, $quiet->flush() );
		$this->assertSame( array(), $this->sent );
	}

	public function test_normalize_redirect_hits_accepts_b2_map_and_list() {
		$b2 = array( 'hits' => array( '/old' => 2, '/gone' => 1, '/zero' => 0, '' => 5 ), 'since' => '2026-10-19T14:02:11+00:00' );
		$this->assertSame(
			array( array( 'source_path' => '/old', 'hits' => 2 ), array( 'source_path' => '/gone', 'hits' => 1 ) ),
			Bot_Crawl_Logger::normalize_redirect_hits( $b2 )
		);
		$this->assertSame( array( array( 'source_path' => '/a', 'hits' => 3 ) ), Bot_Crawl_Logger::normalize_redirect_hits( array( array( 'source_path' => '/a', 'hits' => 3 ), 'junk' ) ) );
		$this->assertSame( array(), Bot_Crawl_Logger::normalize_redirect_hits( array( 'hits' => array(), 'since' => null ) ) );
		$this->assertSame( array(), Bot_Crawl_Logger::normalize_redirect_hits( null ) );

		$logger = $this->logger(
			'https://webhooks.storedash.io/seo',
			static function () use ( $b2 ) {
				return $b2;
			}
		);
		$logger->flush();
		$this->assertSame( array( array( 'source_path' => '/old', 'hits' => 2 ), array( 'source_path' => '/gone', 'hits' => 1 ) ), json_decode( $this->sent[0]['body'], true )['redirect_hits'] );
	}

	public function test_default_redirect_drainer_reads_and_clears_b2_hits() {
		$this->assertTrue( class_exists( 'StoreDash\Seo\Engine\Redirect_Manager' ) );
		$this->assertSame( array(), Bot_Crawl_Logger::drain_redirect_hits() );

		set_transient(
			\StoreDash\Seo\Engine\Redirect_Manager::HITS_TRANSIENT,
			array(
				'since' => '2026-10-19T10:00:00+00:00',
				'hits'  => array(
					'/old/'  => 2,
					'/zero/' => 0,
				),
			)
		);
		$this->assertSame(
			array(
				array(
					'source_path' => '/old/',
					'hits'        => 2,
				),
			),
			Bot_Crawl_Logger::drain_redirect_hits()
		);
		// Drained: the transient is gone and a second drain is empty.
		$this->assertFalse( get_transient( \StoreDash\Seo\Engine\Redirect_Manager::HITS_TRANSIENT ) );
		$this->assertSame( array(), Bot_Crawl_Logger::drain_redirect_hits() );
	}

	public function test_flush_without_secret_sends_unsigned() {
		$GLOBALS['__seo_options']['woodash_webhook_secret'] = '';
		$this->store_value                                   = array( '2026-10-19|gptbot|/' => array( 1, 0 ) );
		$this->logger()->flush();
		$this->assertArrayNotHasKey( 'X-WooDash-Signature', $this->sent[0]['headers'] );
	}

	/* ---------------- review fixes 2026-10-19 ---------------- */

	public function test_build_payloads_keeps_every_body_under_the_256kb_cap() {
		$hits = array();
		for ( $i = 0; $i < 3000; $i++ ) {
			$hits[ Bot_Crawl_Logger::key( '2026-10-19', 'gptbot', '/' . str_pad( (string) $i, Bot_Crawl_Logger::MAX_PATH - 1, 'x' ) ) ] = array( 3, $i );
		}
		$redirects = array( array( 'source_path' => '/old', 'hits' => 2 ) );
		$payloads  = Bot_Crawl_Logger::build_payloads( $hits, $redirects, '1105', 'https://shop.test', '2026-10-19 12:00:00', '1.27.0' );

		$this->assertGreaterThan( 2, count( $payloads ), '3,000 × 500-char paths cannot fit in two 256 KB bodies' );
		$total = 0;
		foreach ( $payloads as $i => $payload ) {
			$this->assertLessThanOrEqual( Bot_Crawl_Logger::MAX_BODY_BYTES, strlen( (string) wp_json_encode( $payload ) ), 'payload ' . $i );
			$this->assertLessThanOrEqual( Bot_Crawl_Logger::CHUNK, count( $payload['crawls'] ) );
			$this->assertSame( 0 === $i ? $redirects : array(), $payload['redirect_hits'] );
			$this->assertSame( '1.27.0', $payload['plugin_version'] );
			$total += count( $payload['crawls'] );
		}
		$this->assertSame( 3000, $total, 'no row lost or duplicated' );
	}

	public function test_flush_is_locked_and_releases_the_lock() {
		$this->store_value = array( '2026-10-19|gptbot|/x/' => array( 1, 0 ) );
		set_transient( Bot_Crawl_Logger::LOCK_TRANSIENT, 1 );
		$logger = $this->logger();
		$this->assertSame( 0, $logger->flush(), 'another flush holds the lock' );
		$this->assertSame( array(), $this->sent );
		$this->assertSame( 0, $this->store_gets );

		delete_transient( Bot_Crawl_Logger::LOCK_TRANSIENT );
		$this->assertSame( 1, $logger->flush() );
		$this->assertFalse( get_transient( Bot_Crawl_Logger::LOCK_TRANSIENT ), 'lock released' );
		$this->assertNotFalse( get_transient( Bot_Crawl_Logger::LAST_FLUSH_TRANSIENT ), 'flush start recorded' );
		$stats = Bot_Crawl_Logger::stats_option();
		$this->assertNotNull( $stats['last_flush_at'] );
		$this->assertSame( 0, $stats['consecutive_failures'] );
	}

	public function test_failed_dispatch_merges_rows_back_until_three_failures() {
		$this->send_ok = false;
		$rows          = array( '2026-10-19|gptbot|/x/' => array( 4, 12 ) );
		$logger        = $this->logger();

		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$this->store_value = $rows;
			$this->assertSame( 0, $logger->flush() );
			$this->assertSame( $rows, $this->store_value, 'attempt ' . $attempt . ': counts kept for the next flush' );
			$this->assertSame( $attempt, Bot_Crawl_Logger::stats_option()['consecutive_failures'] );
		}
		$this->assertSame( 0, $logger->flush() );
		$this->assertFalse( $this->store_value, 'third consecutive failure drops the rows' );

		$this->send_ok     = true;
		$this->store_value = $rows;
		$this->assertSame( 1, $logger->flush() );
		$this->assertSame( 0, Bot_Crawl_Logger::stats_option()['consecutive_failures'], 'success resets the counter' );
	}

	public function test_merge_rows_adds_to_existing_counts_and_keeps_product_ids() {
		$hits = array( '2026-10-19|gptbot|/x/' => array( 2, 0 ) );
		$out  = Bot_Crawl_Logger::merge_rows(
			$hits,
			array(
				array( 'date' => '2026-10-19', 'bot' => 'gptbot', 'path' => '/x/', 'product_id' => 7, 'hits' => 3 ),
				array( 'date' => '2026-10-18', 'bot' => 'ccbot', 'path' => '/', 'product_id' => null, 'hits' => 1 ),
				'junk',
			)
		);
		$this->assertSame( array( 5, 7 ), $out['2026-10-19|gptbot|/x/'] );
		$this->assertSame( array( 1, 0 ), $out['2026-10-18|ccbot|/'] );
		$this->assertCount( 2, $out );
	}

	public function test_opportunistic_flush_is_scheduled_once_per_window() {
		$logger = $this->logger();
		$this->assertTrue( $logger->maybe_flush_on_shutdown(), 'no flush yet → schedule one on shutdown' );
		$this->assertFalse( $logger->maybe_flush_on_shutdown(), 'window marker set' );

		$GLOBALS['__seo_transients'] = array();
		set_transient( Bot_Crawl_Logger::LOCK_TRANSIENT, 1 );
		$this->assertFalse( $logger->maybe_flush_on_shutdown(), 'running flush → no second one' );
	}

	public function test_404_bot_hits_count_only_when_a_redirect_or_llms_claims_them() {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)';
		$_SERVER['REQUEST_URI']     = '/random-' . 'garbage/?x=1';
		$GLOBALS['__seo_is_404']    = true;
		$logger                     = $this->logger();

		$logger->handle_request();
		$this->assertSame( 0, $this->store_sets, 'spoofable UA + 404 path: nothing stored yet' );

		$logger->record_pending();
		$this->assertSame( 1, $this->store_sets, 'redirect matched / llms.txt served → counted' );
		$this->assertSame( array( gmdate( 'Y-m-d' ) . '|gptbot|/random-garbage/' ), array_keys( $this->store_value ) );

		$logger->record_pending();
		$this->assertSame( 1, $this->store_sets, 'pending is consumed once' );

		$GLOBALS['__seo_is_404'] = false;
		$_SERVER['REQUEST_URI']  = "/it\\'s/";
		$logger->handle_request();
		$this->assertSame( 2, $this->store_sets, 'non-404 counted immediately' );
		$this->assertArrayHasKey( gmdate( 'Y-m-d' ) . '|gptbot|' . wp_unslash( "/it\\'s/" ), $this->store_value, 'REQUEST_URI goes through wp_unslash()' );
	}

	public function test_stats_shape() {
		$this->assertSame(
			array( 'pending_keys', 'last_flush_at', 'consecutive_failures', 'webhook_url_configured', 'next_scheduled_at' ),
			array_keys( Bot_Crawl_Logger::stats( '' ) )
		);
		set_transient( Bot_Crawl_Logger::TRANSIENT, array( 'a|b|/c' => array( 1, 0 ), 'a|b|/d' => array( 1, 0 ) ) );
		$stats = Bot_Crawl_Logger::stats( 'https://hook.example' );
		$this->assertSame( 2, $stats['pending_keys'] );
		$this->assertTrue( $stats['webhook_url_configured'] );
		$this->assertNull( $stats['last_flush_at'] );
	}

	public function test_delivered_requires_2xx_when_blocking() {
		$this->assertTrue( Bot_Crawl_Logger::delivered( array(), true, 200 ) );
		$this->assertTrue( Bot_Crawl_Logger::delivered( array(), true, 204 ) );
		$this->assertFalse( Bot_Crawl_Logger::delivered( array(), true, 401 ), 'signature rejected → keep the rows' );
		$this->assertFalse( Bot_Crawl_Logger::delivered( array(), true, 500 ) );
		$this->assertFalse( Bot_Crawl_Logger::delivered( array(), true, 0 ) );
		$this->assertTrue( Bot_Crawl_Logger::delivered( array(), false, 0 ), 'non-blocking: dispatched is all we know' );
		$this->assertFalse( Bot_Crawl_Logger::delivered( new WP_Error( 'http_request_failed', 'x' ), false, 0 ) );
	}
}
