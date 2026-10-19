<?php
/**
 * Yoast / RankMath → Storedash SEO mapping (pure, no WordPress calls).
 *
 * Field names and source keys mirror woo-dash
 * `src/features/products/utils/seo-meta.ts`; `inc/Seo/fixtures/migration-map.json`
 * pins both sides (Migration_MapTest here, a matching vitest in woo-dash):
 * `shared` = the 9 fields seo-meta.ts reads, `extra` = the 3 read-only ones
 * (scores, primary category) only this class maps.
 * Source keys verified against Yoast `inc/class-wpseo-meta.php`,
 * `inc/options/class-wpseo-taxonomy-meta.php`, `class-wpseo-option-titles.php`,
 * Yoast Premium `classes/redirect/redirect-option.php`, and RankMath
 * `includes/class-installer.php`, `includes/admin/importers/class-yoast.php`
 * (2026-10-17).
 *
 * @package StoreDash\Seo
 * @since   1.26.0
 */

namespace StoreDash\Seo\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration mapping.
 *
 * @since 1.26.0
 */
class Migration_Map {

	/**
	 * Yoast post meta (= seo-meta.ts YOAST_FIELD_KEY_MAP).
	 */
	const YOAST = array(
		'focusKeyword'       => '_yoast_wpseo_focuskw',
		'seoTitle'           => '_yoast_wpseo_title',
		'metaDescription'    => '_yoast_wpseo_metadesc',
		'canonicalUrl'       => '_yoast_wpseo_canonical',
		'breadcrumbTitle'    => '_yoast_wpseo_bctitle',
		'contentScore'       => '_yoast_wpseo_content_score',
		'readabilityScore'   => '_yoast_wpseo_readability_score',
		'primaryCategory'    => '_yoast_wpseo_primary_product_cat',
		'ogTitle'            => '_yoast_wpseo_opengraph-title',
		'ogDescription'      => '_yoast_wpseo_opengraph-description',
		'twitterTitle'       => '_yoast_wpseo_twitter-title',
		'twitterDescription' => '_yoast_wpseo_twitter-description',
	);

	/**
	 * RankMath post + term meta (= seo-meta.ts RANK_MATH_FIELD_KEY_MAP).
	 */
	const RANKMATH = array(
		'focusKeyword'       => 'rank_math_focus_keyword',
		'seoTitle'           => 'rank_math_title',
		'metaDescription'    => 'rank_math_description',
		'canonicalUrl'       => 'rank_math_canonical_url',
		'breadcrumbTitle'    => 'rank_math_breadcrumb_title',
		'contentScore'       => 'rank_math_seo_score',
		'readabilityScore'   => 'rank_math_readability_score',
		'primaryCategory'    => 'rank_math_primary_product_cat',
		'ogTitle'            => 'rank_math_facebook_title',
		'ogDescription'      => 'rank_math_facebook_description',
		'twitterTitle'       => 'rank_math_twitter_title',
		'twitterDescription' => 'rank_math_twitter_description',
	);

	/**
	 * Source keys seo-meta.ts does not read but migrate copies.
	 */
	const YOAST_EXTRA    = array( 'ogImage' => '_yoast_wpseo_opengraph-image' );
	const RANKMATH_EXTRA = array( 'ogImage' => 'rank_math_facebook_image' );

	/**
	 * Yoast term meta: `wpseo_taxonomy_meta[ taxonomy ][ term_id ][ key ]`.
	 */
	const YOAST_TERM = array(
		'focusKeyword'       => 'wpseo_focuskw',
		'seoTitle'           => 'wpseo_title',
		'metaDescription'    => 'wpseo_desc',
		'canonicalUrl'       => 'wpseo_canonical',
		'breadcrumbTitle'    => 'wpseo_bctitle',
		'ogTitle'            => 'wpseo_opengraph-title',
		'ogDescription'      => 'wpseo_opengraph-description',
		'ogImage'            => 'wpseo_opengraph-image',
		'twitterTitle'       => 'wpseo_twitter-title',
		'twitterDescription' => 'wpseo_twitter-description',
	);

	/**
	 * Yoast robots keys (= seo-meta.ts YOAST_ROBOTS_KEYS). `legacy` is read-only.
	 */
	const YOAST_ROBOTS_KEYS = array(
		'noindex'  => '_yoast_wpseo_meta-robots-noindex',
		'nofollow' => '_yoast_wpseo_meta-robots-nofollow',
		'adv'      => '_yoast_wpseo_meta-robots-adv',
		'legacy'   => '_yoast_wpseo_meta-robots',
	);

	const RANKMATH_ROBOTS_KEY = 'rank_math_robots';

	/**
	 * Storedash field (seo-meta.ts camelCase) => §4.9 key.
	 */
	const STOREDASH = array(
		'focusKeyword'       => '_storedash_seo_focus_keyword',
		'seoTitle'           => '_storedash_seo_title',
		'metaDescription'    => '_storedash_seo_description',
		'canonicalUrl'       => '_storedash_seo_canonical',
		'breadcrumbTitle'    => '_storedash_seo_breadcrumb_title',
		'ogTitle'            => '_storedash_seo_og_title',
		'ogDescription'      => '_storedash_seo_og_description',
		'ogImage'            => '_storedash_seo_og_image',
		'twitterTitle'       => '_storedash_seo_twitter_title',
		'twitterDescription' => '_storedash_seo_twitter_description',
		'robots'             => '_storedash_seo_robots',
	);

	/**
	 * camelCase field => Meta_Writer / bulk-meta field.
	 */
	const API_FIELDS = array(
		'focusKeyword'       => 'focus_keyword',
		'seoTitle'           => 'seo_title',
		'metaDescription'    => 'seo_description',
		'canonicalUrl'       => 'canonical',
		'breadcrumbTitle'    => 'breadcrumb_title',
		'ogTitle'            => 'og_title',
		'ogDescription'      => 'og_description',
		'ogImage'            => 'og_image',
		'twitterTitle'       => 'twitter_title',
		'twitterDescription' => 'twitter_description',
		'robots'             => 'robots',
	);

	/**
	 * Yoast `wpseo_titles.separator` choices (Yoast's own map, incl. its
	 * swapped sc-lt / sc-gt: that is what Yoast renders).
	 */
	const YOAST_SEPARATORS = array(
		'sc-dash'   => '-',
		'sc-ndash'  => '–',
		'sc-mdash'  => '—',
		'sc-colon'  => ':',
		'sc-middot' => '·',
		'sc-bull'   => '•',
		'sc-star'   => '*',
		'sc-smstar' => '⋆',
		'sc-pipe'   => '|',
		'sc-tilde'  => '~',
		'sc-laquo'  => '«',
		'sc-raquo'  => '»',
		'sc-lt'     => '>',
		'sc-gt'     => '<',
	);

	/**
	 * Separators `Settings::normalize()` would strip (`wp_strip_all_tags`
	 * turns a lone `<` into '') => look-alike replacement.
	 */
	const UNSAFE_SEPARATORS = array(
		'<' => '‹',
		'>' => '›',
	);

	/**
	 * Template variable (without delimiters) => Storedash variable.
	 * `title` depends on the template kind.
	 */
	const TEMPLATE_VARS = array(
		'sitename'               => 'store',
		'sep'                    => 'sep',
		'term_title'             => 'category',
		'term'                   => 'category',
		'category'               => 'category',
		'primary_category'       => 'category',
		'primary_taxonomy_terms' => 'category',
		'wc_sku'                 => 'sku',
		'wc_price'               => 'price',
		'wc_brand'               => 'brand',
	);

	/**
	 * Redirect status codes `seo_redirects` accepts.
	 */
	const REDIRECT_CODES = array( 301, 302, 307, 308, 410 );

	/**
	 * Source key map for a plugin (seo-meta.ts map + extras).
	 *
	 * @param string $from 'yoast' | 'rankmath'.
	 * @return array<string,string>
	 */
	public static function source_keys( string $from ): array {
		return 'yoast' === $from ? array_merge( self::YOAST, self::YOAST_EXTRA ) : array_merge( self::RANKMATH, self::RANKMATH_EXTRA );
	}

	/**
	 * Extract migratable values from a post's (or RankMath term's) raw meta.
	 *
	 * @param string $from 'yoast' | 'rankmath'.
	 * @param array  $raw  get_post_meta( $id ) / get_term_meta( $id ) shape.
	 * @return array { fields: array<api_field,string|array>, primary_category_id: int|null }
	 */
	public static function extract( string $from, array $raw ): array {
		$fields = array();
		foreach ( self::source_keys( $from ) as $field => $key ) {
			if ( ! isset( self::API_FIELDS[ $field ] ) ) {
				continue;
			}
			$value = self::text( Meta_Reader::first( $raw, $key ) );
			if ( '' !== $value ) {
				$fields[ self::API_FIELDS[ $field ] ] = $value;
			}
		}

		$robots = 'yoast' === $from ? self::yoast_robots( $raw ) : self::rankmath_robots( Meta_Reader::first( $raw, self::RANKMATH_ROBOTS_KEY ) );
		if ( null !== $robots ) {
			$fields['robots'] = $robots;
		}

		$primary = self::text( Meta_Reader::first( $raw, 'yoast' === $from ? self::YOAST['primaryCategory'] : self::RANKMATH['primaryCategory'] ) );

		return array(
			'fields'              => $fields,
			'primary_category_id' => ctype_digit( $primary ) && (int) $primary > 0 ? (int) $primary : null,
		);
	}

	/**
	 * Extract values from one Yoast `wpseo_taxonomy_meta[ tax ][ id ]` entry.
	 *
	 * @param array $entry Entry.
	 * @return array { fields }
	 */
	public static function extract_yoast_term( array $entry ): array {
		$fields = array();
		foreach ( self::YOAST_TERM as $field => $key ) {
			$value = isset( $entry[ $key ] ) ? self::text( $entry[ $key ] ) : '';
			if ( '' !== $value ) {
				$fields[ self::API_FIELDS[ $field ] ] = $value;
			}
		}
		if ( isset( $entry['wpseo_noindex'] ) && 'noindex' === $entry['wpseo_noindex'] ) {
			$fields['robots'] = array_merge( self::all_true(), array( 'index' => false ) );
		}
		return array( 'fields' => $fields );
	}

	/**
	 * Yoast three-key robots → five booleans, or null when nothing is set.
	 *
	 * `-noindex` '1' = noindex ('0' default / '2' force index = index);
	 * `-nofollow` '1' = nofollow; `-adv` comma list of noimageindex /
	 * noarchive / nosnippet. The legacy single key is a fallback only.
	 *
	 * @param array $raw Raw meta.
	 * @return array|null
	 */
	public static function yoast_robots( array $raw ): ?array {
		$noindex  = self::text( Meta_Reader::first( $raw, self::YOAST_ROBOTS_KEYS['noindex'] ) );
		$nofollow = self::text( Meta_Reader::first( $raw, self::YOAST_ROBOTS_KEYS['nofollow'] ) );
		$adv      = self::text( Meta_Reader::first( $raw, self::YOAST_ROBOTS_KEYS['adv'] ) );

		if ( '' === $noindex && '' === $nofollow && '' === $adv ) {
			$legacy = self::text( Meta_Reader::first( $raw, self::YOAST_ROBOTS_KEYS['legacy'] ) );
			return '' === $legacy ? null : self::from_tokens( explode( ',', $legacy ) );
		}

		$robots           = self::from_tokens( explode( ',', $adv ) );
		$robots['index']  = '1' !== $noindex;
		$robots['follow'] = '1' !== $nofollow;
		return $robots;
	}

	/**
	 * RankMath `rank_math_robots` token array → five booleans, or null.
	 *
	 * @param mixed $value Unserialized value.
	 * @return array|null
	 */
	public static function rankmath_robots( $value ): ?array {
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$value = explode( ',', $value );
		}
		if ( ! is_array( $value ) || array() === $value ) {
			return null;
		}
		return self::from_tokens( $value );
	}

	/**
	 * Negative tokens → five booleans (absent = true).
	 *
	 * @param array $tokens Tokens.
	 * @return array<string,bool>
	 */
	private static function from_tokens( array $tokens ): array {
		$tokens = array_map(
			static function ( $t ) {
				return strtolower( trim( (string) $t ) );
			},
			$tokens
		);
		$robots = self::all_true();
		foreach ( Meta_Reader::ROBOTS as $directive ) {
			if ( in_array( 'no' . $directive, $tokens, true ) ) {
				$robots[ $directive ] = false;
			}
		}
		return $robots;
	}

	/**
	 * All five directives true.
	 *
	 * @return array<string,bool>
	 */
	public static function all_true(): array {
		return array_fill_keys( Meta_Reader::ROBOTS, true );
	}

	/**
	 * Does a per-item value use the plugin's template variables?
	 *
	 * Storedash renders per-item values verbatim, so such values are skipped
	 * (the store template applies instead).
	 *
	 * @param string $from  'yoast' | 'rankmath'.
	 * @param string $value Value.
	 * @return bool
	 */
	public static function has_variables( string $from, string $value ): bool {
		$pattern = 'yoast' === $from ? '/%%[a-z0-9_\-]+%%/i' : '/%[a-z][a-z0-9_]*(?:\([^)]*\))?%/i';
		return (bool) preg_match( $pattern, $value );
	}

	/**
	 * Translate a site title template to the Storedash vocabulary.
	 *
	 * @param string $from     'yoast' | 'rankmath'.
	 * @param string $template Source template.
	 * @param string $kind     'product' | 'category' | 'page'.
	 * @return array { template: string, dropped: string[] }
	 */
	public static function translate_template( string $from, string $template, string $kind ): array {
		$dropped = array();
		$pattern = 'yoast' === $from ? '/%%([a-z0-9_\-]+)%%/i' : '/%([a-z][a-z0-9_]*)(?:\([^)]*\))?%/i';

		$out = preg_replace_callback(
			$pattern,
			static function ( $m ) use ( $kind, &$dropped ) {
				$name = strtolower( $m[1] );
				if ( 'title' === $name ) {
					return '{' . $kind . '}';
				}
				if ( isset( self::TEMPLATE_VARS[ $name ] ) ) {
					return '{' . self::TEMPLATE_VARS[ $name ] . '}';
				}
				$dropped[] = $m[0];
				return '';
			},
			$template
		);

		return array(
			'template' => trim( (string) preg_replace( '/\s+/u', ' ', (string) $out ) ),
			'dropped'  => array_values( array_unique( $dropped ) ),
		);
	}

	/**
	 * Site templates + separator from the plugin's titles option.
	 *
	 * @param string $from   'yoast' | 'rankmath'.
	 * @param mixed  $option `wpseo_titles` / `rank-math-options-titles` value.
	 * @return array { templates: array|null, warnings: string[] }
	 */
	public static function site_templates( string $from, $option ): array {
		if ( ! is_array( $option ) || array() === $option ) {
			return array(
				'templates' => null,
				'warnings'  => array(),
			);
		}

		$keys = 'yoast' === $from
			? array(
				'product'  => 'title-product',
				'category' => 'title-tax-product_cat',
				'page'     => 'title-page',
			)
			: array(
				'product'  => 'pt_product_title',
				'category' => 'tax_product_cat_title',
				'page'     => 'pt_page_title',
			);

		$templates = array();
		$warnings  = array();
		foreach ( $keys as $kind => $key ) {
			$source = isset( $option[ $key ] ) && is_string( $option[ $key ] ) ? $option[ $key ] : '';
			if ( '' === trim( $source ) ) {
				$templates[ 'title_template_' . $kind ] = null;
				continue;
			}
			$translated                             = self::translate_template( $from, $source, $kind );
			$templates[ 'title_template_' . $kind ] = $translated['template'];
			if ( $translated['dropped'] ) {
				$warnings[] = sprintf( '%s title template: dropped unsupported variables %s', $kind, implode( ' ', $translated['dropped'] ) );
			}
		}

		$raw                          = self::raw_separator( $from, 'yoast' === $from ? ( $option['separator'] ?? null ) : ( $option['title_separator'] ?? null ) );
		$templates['title_separator'] = self::safe_separator( $raw );
		if ( null !== $raw && isset( self::UNSAFE_SEPARATORS[ $raw ] ) ) {
			$warnings[] = sprintf( "separator '%s' replaced with '%s'", $raw, self::UNSAFE_SEPARATORS[ $raw ] );
		}

		return array(
			'templates' => $templates,
			'warnings'  => $warnings,
		);
	}

	/**
	 * Separator character (Yoast stores an `sc-*` key, RankMath an entity / char).
	 *
	 * A lone `<` / `>` (Yoast `sc-gt` / `sc-lt`, RankMath `&lt;` / `&gt;`) would
	 * be stripped to '' by `Settings::normalize()`, so it comes back as `‹` / `›`
	 * (see UNSAFE_SEPARATORS; site_templates() adds the warning).
	 *
	 * @param string $from  'yoast' | 'rankmath'.
	 * @param mixed  $value Stored value.
	 * @return string|null
	 */
	public static function separator( string $from, $value ): ?string {
		return self::safe_separator( self::raw_separator( $from, $value ) );
	}

	/**
	 * Separator exactly as the plugin renders it (may be `<` / `>`).
	 *
	 * @param string $from  'yoast' | 'rankmath'.
	 * @param mixed  $value Stored value.
	 * @return string|null
	 */
	private static function raw_separator( string $from, $value ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		if ( 'yoast' === $from ) {
			return self::YOAST_SEPARATORS[ $value ] ?? null;
		}
		return trim( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Replace a separator `wp_strip_all_tags` would eat.
	 *
	 * @param string|null $separator Raw separator.
	 * @return string|null
	 */
	private static function safe_separator( ?string $separator ): ?string {
		if ( null === $separator ) {
			return null;
		}
		return self::UNSAFE_SEPARATORS[ $separator ] ?? $separator;
	}

	/**
	 * RankMath `rank_math_redirections` rows (active only) → seo_redirects shape.
	 *
	 * @param array $rows Rows with `sources` (serialized list of
	 *                    { pattern, comparison, ignore }), `url_to`, `header_code`.
	 * @param int   $cap  Max redirects.
	 * @return array { redirects, truncated, warnings }
	 */
	public static function rankmath_redirects( array $rows, int $cap ): array {
		$entries = array();
		$skipped = array();
		foreach ( $rows as $row ) {
			$sources = is_array( $row ) && isset( $row['sources'] ) ? $row['sources'] : null;
			if ( is_string( $sources ) ) {
				$sources = Meta_Reader::first( array( 'k' => $sources ), 'k' );
			}
			if ( ! is_array( $sources ) ) {
				continue;
			}
			foreach ( $sources as $source ) {
				if ( ! is_array( $source ) || ! isset( $source['pattern'] ) ) {
					continue;
				}
				$comparison = isset( $source['comparison'] ) ? (string) $source['comparison'] : 'exact';
				if ( 'exact' !== $comparison ) {
					$skipped[ 'comparison "' . $comparison . '"' ] = true;
					continue;
				}
				$entries[] = array( (string) $source['pattern'], (string) ( $row['url_to'] ?? '' ), (int) ( $row['header_code'] ?? 301 ) );
			}
		}
		return self::redirects( $entries, $cap, 'RankMath', $skipped );
	}

	/**
	 * Yoast Premium `wpseo-premium-redirects-base` → seo_redirects shape.
	 *
	 * Accepts both shapes Yoast has used: a list of `{ origin, url, type,
	 * format }`, or entries keyed by origin (`[ origin => { url, type, format } ]`).
	 *
	 * @param mixed $option Redirect entries.
	 * @param int   $cap    Max redirects.
	 * @return array { redirects, truncated, warnings }
	 */
	public static function yoast_redirects( $option, int $cap ): array {
		$entries = array();
		$skipped = array();
		foreach ( is_array( $option ) ? $option : array() as $key => $redirect ) {
			if ( ! is_array( $redirect ) ) {
				continue;
			}
			$origin = $redirect['origin'] ?? ( is_string( $key ) ? $key : null );
			if ( null === $origin ) {
				continue;
			}
			if ( isset( $redirect['format'] ) && 'plain' !== $redirect['format'] ) {
				$skipped[ 'format "' . $redirect['format'] . '"' ] = true;
				continue;
			}
			$entries[] = array( (string) $origin, (string) ( $redirect['url'] ?? '' ), (int) ( $redirect['type'] ?? 301 ) );
		}
		return self::redirects( $entries, $cap, 'Yoast', $skipped );
	}

	/**
	 * Normalize [ source, target, code ] entries.
	 *
	 * @param array  $entries Entries.
	 * @param int    $cap     Max redirects.
	 * @param string $label   Plugin label for warnings.
	 * @param array  $skipped Skip reasons collected by the caller (as keys).
	 * @return array { redirects, truncated, warnings }
	 */
	private static function redirects( array $entries, int $cap, string $label, array $skipped ): array {
		$out      = array();
		$seen     = array();
		$warnings = array();
		$codes    = array();
		$skip_n   = 0;

		foreach ( $entries as $entry ) {
			list( $source, $target, $code ) = $entry;
			$path                           = self::redirect_path( $source );
			if ( '' === $path ) {
				continue;
			}
			if ( ! in_array( $code, self::REDIRECT_CODES, true ) ) {
				$codes[ $code ] = true;
				continue;
			}
			$target = trim( $target );
			if ( 410 !== $code && '' === $target ) {
				++$skip_n;
				continue;
			}
			if ( 410 !== $code && ! preg_match( '#^https?://#i', $target ) ) {
				$target = '/' . ltrim( $target, '/' );
			}
			if ( isset( $seen[ $path ] ) ) {
				continue;
			}
			$seen[ $path ] = true;
			$out[]         = array(
				'source_path' => $path,
				'target_url'  => 410 === $code ? '' : $target,
				'status_code' => $code,
			);
		}

		foreach ( array_keys( $skipped ) as $reason ) {
			$warnings[] = sprintf( '%s redirects with %s skipped (only exact paths are imported)', $label, $reason );
		}
		if ( $codes ) {
			$warnings[] = sprintf( '%s redirects with status %s dropped (supported: %s)', $label, implode( ', ', array_keys( $codes ) ), implode( ', ', self::REDIRECT_CODES ) );
		}
		if ( $skip_n ) {
			$warnings[] = sprintf( '%s redirects without a target skipped: %d', $label, $skip_n );
		}

		$truncated = count( $out ) > $cap;
		return array(
			'redirects' => $truncated ? array_slice( $out, 0, $cap ) : $out,
			'truncated' => $truncated,
			'warnings'  => $warnings,
		);
	}

	/**
	 * Redirect source → '/path' (query kept; host dropped).
	 *
	 * @param string $source Source pattern / origin.
	 * @return string
	 */
	public static function redirect_path( string $source ): string {
		$source = trim( $source );
		if ( preg_match( '#^https?://#i', $source ) ) {
			$path   = (string) parse_url( $source, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class, no WP calls.
			$query  = (string) parse_url( $source, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			$source = $path . ( '' !== $query ? '?' . $query : '' );
		}
		$source = ltrim( $source, '/' );
		return '' === $source ? '' : '/' . $source;
	}

	/**
	 * Scalar → trimmed string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
