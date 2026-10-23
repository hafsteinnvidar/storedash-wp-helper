<?php
/**
 * Stubs + class loading for the standalone Checkout suites.
 *
 * Options use the shared store in tests/bootstrap.php (`$GLOBALS['__seo_options']`).
 * Funnel_Counter takes every WP/WC touch point as an injected callable, so no
 * transient / session stubs are needed here; Bot_Crawl_Logger is loaded for
 * the bot-UA skip (its matcher is pure).
 *
 * @package StoreDash\Tests
 */

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}

require_once __DIR__ . '/../../inc/Checkout/Config.php';
require_once __DIR__ . '/../../inc/Seo/Engine/Bot_Crawl_Logger.php';
require_once __DIR__ . '/../../inc/Checkout/Funnel/Funnel_Counter.php';
require_once __DIR__ . '/../../inc/Checkout/FreeShipping/Threshold_Calculator.php';
