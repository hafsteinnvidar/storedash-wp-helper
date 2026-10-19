<?php
/**
 * `POST /storedash/v1/seo/migrate` — copy Yoast / RankMath SEO meta into the
 * §4.9 `_storedash_seo_*` keys, one page at a time.
 *
 * Never writes, deletes or renames Yoast / RankMath data (plan §6.4). Objects
 * are walked as one sequence — products, terms, posts, pages — each by ID asc,
 * so `offset` pagination is stable while pages are written. Site-level data
 * (title templates, redirects) comes back on the `offset: 0` page only.
 *
 * @package StoreDash\Seo\Controllers
 * @since   1.26.0
 */

namespace StoreDash\Seo\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Seo\Engine\Meta_Reader;
use StoreDash\Seo\Engine\Meta_Writer;
use StoreDash\Seo\Engine\Migration_Map;
use WP_Query;

/**
 * Migration from Yoast / RankMath.
 *
 * @since 1.26.0
 */
class Seo_Migrate_Controller extends Abstract_Seo_Controller {

	const MAX_LIMIT       = 200;
	const DEFAULT_LIMIT   = 100;
	const SAMPLE_SIZE     = 20;
	const MAX_REDIRECTS   = 5000;
	const OBJECTS         = array( 'product', 'term', 'post', 'page' );
	const POST_STATUSES   = array( 'publish', 'draft', 'private' );
	const TERM_TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand', 'pwb-brand' );

	/**
	 * Writer.
	 *
	 * @var Meta_Writer
	 */
	protected $writer;

	/**
	 * Yoast `wpseo_taxonomy_meta` (read once per request).
	 *
	 * @var array|null
	 */
	private $yoast_term_meta = null;

	/**
	 * Constructor.
	 *
	 * @param Meta_Writer|null $writer Writer (injectable for tests).
	 */
	public function __construct( $writer = null ) {
		$this->writer = $writer instanceof Meta_Writer ? $writer : new Meta_Writer();
	}

	/**
	 * POST /seo/migrate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function migrate( $request ) {
		$args = self::normalize_args( $this->body( $request ) );
		if ( isset( $args['error'] ) ) {
			return $this->error( 'storedash_seo_invalid_migrate', $args['error'], 400 );
		}
		return $this->success( $this->run( $args ) );
	}

	/**
	 * Validate the body.
	 *
	 * Pure.
	 *
	 * @param array $body Body.
	 * @return array Normalized args, or { error }.
	 */
	public static function normalize_args( array $body ): array {
		$from = $body['from'] ?? null;
		if ( ! in_array( $from, array( 'yoast', 'rankmath' ), true ) ) {
			return array( 'error' => 'from must be yoast or rankmath' );
		}

		foreach ( array( 'dry_run', 'overwrite' ) as $flag ) {
			if ( array_key_exists( $flag, $body ) && ! is_bool( $body[ $flag ] ) ) {
				return array( 'error' => $flag . ' must be a boolean' );
			}
		}

		$limit = $body['limit'] ?? self::DEFAULT_LIMIT;
		if ( ! is_int( $limit ) || $limit < 1 || $limit > self::MAX_LIMIT ) {
			return array( 'error' => 'limit must be an integer between 1 and ' . self::MAX_LIMIT );
		}
		$offset = $body['offset'] ?? 0;
		if ( ! is_int( $offset ) || $offset < 0 ) {
			return array( 'error' => 'offset must be a non-negative integer' );
		}

		$objects = $body['objects'] ?? self::OBJECTS;
		if ( ! is_array( $objects ) || array() === $objects ) {
			return array( 'error' => 'objects must be a non-empty subset of ' . implode( ', ', self::OBJECTS ) );
		}
		foreach ( $objects as $object ) {
			if ( ! is_string( $object ) ) {
				return array( 'error' => 'objects must contain strings only' );
			}
		}
		if ( array_diff( $objects, self::OBJECTS ) ) {
			return array( 'error' => 'objects must be a non-empty subset of ' . implode( ', ', self::OBJECTS ) );
		}

		return array(
			'from'      => $from,
			'dry_run'   => $body['dry_run'] ?? true,
			'limit'     => $limit,
			'offset'    => $offset,
			// Canonical order regardless of request order (stable pagination).
			'objects'   => array_values( array_intersect( self::OBJECTS, $objects ) ),
			'overwrite' => $body['overwrite'] ?? false,
		);
	}

	/**
	 * Run one page.
	 *
	 * @param array $args Normalized args.
	 * @return array
	 */
	public function run( array $args ): array {
		$from = $args['from'];
		$out  = array(
			'dry_run'             => $args['dry_run'],
			'from'                => $from,
			'processed'           => 0,
			'written'             => 0,
			'skipped'             => 0,
			'remaining'           => 0,
			'next_offset'         => null,
			'sample'              => array(),
			'templates'           => null,
			'redirects'           => array(),
			'redirects_truncated' => false,
			'primary_categories'  => array(),
			'warnings'            => array(),
		);

		$total = 0;
		$todo  = $args['limit'];
		foreach ( $args['objects'] as $object ) {
			$count  = $this->count_objects( $object );
			$start  = max( 0, $args['offset'] - $total );
			$total += $count;
			if ( $todo <= 0 || $start >= $count ) {
				continue;
			}

			$batch = $this->fetch_objects( $object, $start, min( $todo, $count - $start ) );
			$todo -= count( $batch );
			foreach ( $batch as $target ) {
				$this->migrate_one( $from, $object, $target, $args, $out );
			}
		}

		// Counts (wp_count_posts / wp_count_terms) and the fetched page can
		// disagree (caps, private posts). A page that processed nothing must
		// end the walk, or the client loops on the same offset forever.
		$out['remaining']   = $out['processed'] > 0 ? max( 0, $total - $args['offset'] - $out['processed'] ) : 0;
		$out['next_offset'] = $out['remaining'] > 0 ? $args['offset'] + $out['processed'] : null;

		if ( 0 === $args['offset'] ) {
			$this->site_level( $from, $out );
		}

		$out['warnings'] = array_values( array_unique( $out['warnings'] ) );
		return $out;
	}

	/**
	 * Migrate one object into $out.
	 *
	 * @param string $from   Source plugin.
	 * @param string $object product|term|post|page.
	 * @param array  $target { id, taxonomy? }.
	 * @param array  $args   Args.
	 * @param array  $out    Response (by reference).
	 */
	protected function migrate_one( string $from, string $object, array $target, array $args, array &$out ): void {
		$id   = (int) $target['id'];
		$type = 'term' === $object ? 'term' : 'post';
		$raw  = 'term' === $type ? $this->term_meta( $id ) : $this->post_meta( $id );

		if ( 'term' === $type && 'yoast' === $from ) {
			$entry     = $this->yoast_term_entry( (string) $target['taxonomy'], $id );
			$extracted = Migration_Map::extract_yoast_term( $entry );
		} else {
			$extracted = Migration_Map::extract( $from, $raw );
		}

		$kind = 'product' === $object ? 'product' : ( 'term' === $object ? 'category' : 'page' );
		$plan = self::plan_fields( $from, $extracted['fields'], Meta_Reader::normalize( $raw ), $args['overwrite'], $kind );

		++$out['processed'];
		if ( $plan['prepared']['set'] ) {
			++$out['written'];
			if ( ! $args['dry_run'] ) {
				$this->writer->apply( $type, $id, $plan['prepared'] );
			}
		} else {
			++$out['skipped'];
		}

		if ( 'product' === $object && null !== ( $extracted['primary_category_id'] ?? null ) ) {
			$out['primary_categories'][] = array(
				'product_id' => $id,
				'term_id'    => $extracted['primary_category_id'],
			);
		}

		if ( count( $out['sample'] ) < self::SAMPLE_SIZE && ( $plan['written'] || $plan['skipped'] ) ) {
			$item = array(
				'object'         => $object,
				'id'             => $id,
				'fields_written' => $plan['written'],
				'fields_skipped' => $plan['skipped'],
				'values'         => $plan['values'],
			);
			if ( 'term' === $object ) {
				$item['taxonomy'] = (string) $target['taxonomy'];
			}
			$out['sample'][] = $item;
		}
	}

	/**
	 * Decide per field: write, or skip with a reason.
	 *
	 * Pure. `seo_title` with plugin variables (`%%title%% %%sep%% %%sitename%%`)
	 * is translated to the Storedash vocabulary (`{product} {sep} {store}`),
	 * which Head_Renderer renders. Reasons: `exists` (our key already set and
	 * overwrite=false), `template_variables` (any other field with variables —
	 * printed verbatim — or a title using variables Storedash lacks; the store
	 * template applies instead), `invalid` (fails
	 * Meta_Writer validation, e.g. a relative canonical URL), `default` (robots
	 * all true: nothing to store).
	 *
	 * @param string $from      Source plugin.
	 * @param array  $fields    Extracted API fields.
	 * @param array  $ours      Meta_Reader::normalize() of the object's meta.
	 * @param bool   $overwrite Overwrite existing values.
	 * @param string $kind      Title template kind: product|category|page.
	 * @return array { prepared, written: string[], skipped: {field,reason}[], values }
	 */
	public static function plan_fields( string $from, array $fields, array $ours, bool $overwrite, string $kind = 'product' ): array {
		$prepared = array(
			'set'    => array(),
			'delete' => array(),
			'error'  => null,
		);
		$written  = array();
		$skipped  = array();
		$values   = array();
		$reader   = array_flip( Meta_Reader::KEYS );

		foreach ( $fields as $field => $value ) {
			$key     = Meta_Writer::FIELDS[ $field ];
			$current = $ours[ $reader[ $key ] ] ?? '';
			$exists  = 'robots' === $field ? Migration_Map::all_true() !== $current : '' !== $current;

			$reason = null;
			if ( $exists && ! $overwrite ) {
				$reason = 'exists';
			} elseif ( is_string( $value ) && Migration_Map::has_variables( $from, $value ) && ! self::translatable( $from, $field, $value, $kind ) ) {
				$reason = 'template_variables';
			} else {
				if ( is_string( $value ) && 'seo_title' === $field && Migration_Map::has_variables( $from, $value ) ) {
					$value = Migration_Map::translate_template( $from, $value, $kind )['template'];
				}
				$one = Meta_Writer::prepare( array( $field => $value ) );
				if ( null !== $one['error'] ) {
					$reason = 'invalid';
				} elseif ( ! $one['set'] ) {
					$reason = 'default';
				} else {
					$prepared['set'] += $one['set'];
				}
			}

			if ( null === $reason ) {
				$written[]        = $field;
				$values[ $field ] = $value;
			} elseif ( 'default' !== $reason ) {
				$skipped[] = array(
					'field'  => $field,
					'reason' => $reason,
				);
			}
		}

		return array(
			'prepared' => $prepared,
			'written'  => $written,
			'skipped'  => $skipped,
			'values'   => $values,
		);
	}

	/**
	 * A title whose every variable has a Storedash equivalent.
	 *
	 * @param string $from  Source plugin.
	 * @param string $field API field.
	 * @param string $value Value.
	 * @param string $kind  Template kind.
	 * @return bool
	 */
	private static function translatable( string $from, string $field, string $value, string $kind ): bool {
		if ( 'seo_title' !== $field ) {
			return false;
		}
		$translated = Migration_Map::translate_template( $from, $value, $kind );
		// Pagination ("Page 2 of 3") is empty on single objects; safe to drop.
		$dropped = array_diff( $translated['dropped'], array( '%%page%%', '%page%' ) );
		return ! $dropped && '' !== $translated['template'];
	}

	/**
	 * Templates + redirects (offset 0 only).
	 *
	 * @param string $from Source plugin.
	 * @param array  $out  Response (by reference).
	 */
	protected function site_level( string $from, array &$out ): void {
		$titles           = Migration_Map::site_templates( $from, $this->option( 'yoast' === $from ? 'wpseo_titles' : 'rank-math-options-titles' ) );
		$out['templates'] = $titles['templates'];
		$out['warnings']  = array_merge( $out['warnings'], $titles['warnings'] );
		if ( null === $titles['templates'] ) {
			$out['warnings'][] = 'No ' . $from . ' title settings found';
		}

		if ( 'yoast' === $from ) {
			$option = $this->option( 'wpseo-premium-redirects-base' );
			$result = Migration_Map::yoast_redirects( $option, self::MAX_REDIRECTS );
		} else {
			$rows = $this->rankmath_redirect_rows( self::MAX_REDIRECTS + 1 );
			if ( null === $rows ) {
				$out['warnings'][] = 'RankMath redirections table not found';
				$rows              = array();
			}
			$result = Migration_Map::rankmath_redirects( $rows, self::MAX_REDIRECTS );
		}

		$out['redirects']           = $result['redirects'];
		$out['redirects_truncated'] = $result['truncated'];
		$out['warnings']            = array_merge( $out['warnings'], $result['warnings'] );
	}

	/**
	 * Yoast term entry from `wpseo_taxonomy_meta`.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $id       Term ID.
	 * @return array
	 */
	private function yoast_term_entry( string $taxonomy, int $id ): array {
		if ( null === $this->yoast_term_meta ) {
			$value                 = $this->option( 'wpseo_taxonomy_meta' );
			$this->yoast_term_meta = is_array( $value ) ? $value : array();
		}
		$entry = $this->yoast_term_meta[ $taxonomy ][ $id ] ?? array();
		return is_array( $entry ) ? $entry : array();
	}

	// ── WordPress access (overridden in tests) ─────────────────────────────

	/**
	 * Term taxonomies in scope.
	 *
	 * @return string[]
	 */
	protected function taxonomies(): array {
		return array_values( array_filter( self::TERM_TAXONOMIES, 'taxonomy_exists' ) );
	}

	/**
	 * Number of objects of a type in scope.
	 *
	 * @param string $object product|term|post|page.
	 * @return int
	 */
	protected function count_objects( string $object ): int {
		if ( 'term' === $object ) {
			$taxonomies = $this->taxonomies();
			if ( ! $taxonomies ) {
				return 0;
			}
			$count = wp_count_terms(
				array(
					'taxonomy'   => $taxonomies,
					'hide_empty' => false,
				)
			);
			return is_numeric( $count ) ? (int) $count : 0;
		}

		$counts = wp_count_posts( $object );
		$total  = 0;
		foreach ( self::POST_STATUSES as $status ) {
			$total += isset( $counts->$status ) ? (int) $counts->$status : 0;
		}
		return $total;
	}

	/**
	 * One page of objects (ID asc), meta cache primed in one query.
	 *
	 * @param string $object product|term|post|page.
	 * @param int    $offset Offset within this type.
	 * @param int    $limit  Count.
	 * @return array[] { id, taxonomy? }
	 */
	protected function fetch_objects( string $object, int $offset, int $limit ): array {
		if ( 'term' === $object ) {
			$terms = get_terms(
				array(
					'taxonomy'               => $this->taxonomies(),
					'hide_empty'             => false,
					'orderby'                => 'term_id',
					'order'                  => 'ASC',
					'number'                 => $limit,
					'offset'                 => $offset,
					'update_term_meta_cache' => true,
				)
			);
			if ( ! is_array( $terms ) ) {
				return array();
			}
			return array_map(
				static function ( $term ) {
					return array(
						'id'       => (int) $term->term_id,
						'taxonomy' => (string) $term->taxonomy,
					);
				},
				$terms
			);
		}

		$query = new WP_Query(
			array(
				'post_type'           => $object,
				'post_status'         => self::POST_STATUSES,
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'posts_per_page'      => $limit,
				'offset'              => $offset,
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'suppress_filters'    => true,
			)
		);
		$ids   = array_map( 'intval', $query->posts );
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
		}
		return array_map(
			static function ( $id ) {
				return array( 'id' => $id );
			},
			$ids
		);
	}

	/**
	 * All post meta (cache hit after fetch_objects()).
	 *
	 * @param int $id Post ID.
	 * @return array
	 */
	protected function post_meta( int $id ): array {
		$meta = get_post_meta( $id );
		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * All term meta (cache hit after fetch_objects()).
	 *
	 * @param int $id Term ID.
	 * @return array
	 */
	protected function term_meta( int $id ): array {
		$meta = get_term_meta( $id );
		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Read an option (never written here).
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	protected function option( string $name ) {
		return get_option( $name, null );
	}

	/**
	 * Active RankMath redirections, or null when the table does not exist.
	 *
	 * @param int $limit Max rows.
	 * @return array|null
	 */
	protected function rankmath_redirect_rows( int $limit ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off read of another plugin's table.
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, sources, url_to, header_code FROM {$table} WHERE status = 'active' ORDER BY id ASC LIMIT %d", $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}
