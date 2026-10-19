<?php
/**
 * `POST /storedash/v1/seo/bulk-meta` — write §4.9 SEO meta for up to 200
 * products / pages / posts / terms in one call. Partial success: one bad item
 * never aborts the batch.
 *
 * @package StoreDash\Seo\Controllers
 * @since   1.26.0
 */

namespace StoreDash\Seo\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Seo\Engine\Head_Renderer;
use StoreDash\Seo\Engine\Meta_Writer;

/**
 * Bulk meta writes.
 *
 * @since 1.26.0
 */
class Seo_Bulk_Controller extends Abstract_Seo_Controller {

	/**
	 * Max items per call.
	 */
	const MAX_ITEMS = 200;

	/**
	 * Object type => required post type (terms are checked separately).
	 */
	const POST_OBJECTS = array(
		'product' => 'product',
		'post'    => 'post',
		'page'    => 'page',
	);

	/**
	 * Writer.
	 *
	 * @var Meta_Writer
	 */
	protected $writer;

	/**
	 * Constructor.
	 *
	 * @param Meta_Writer|null $writer Writer (injectable for tests).
	 */
	public function __construct( $writer = null ) {
		$this->writer = $writer instanceof Meta_Writer ? $writer : new Meta_Writer();
	}

	/**
	 * POST /seo/bulk-meta.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function bulk_meta( $request ) {
		$body  = $this->body( $request );
		$items = isset( $body['items'] ) ? $body['items'] : null;

		if ( ! is_array( $items ) || array() === $items ) {
			return $this->error( 'storedash_seo_empty_items', 'items must be a non-empty array' );
		}
		if ( count( $items ) > self::MAX_ITEMS ) {
			return $this->error( 'storedash_seo_too_many_items', 'At most ' . self::MAX_ITEMS . ' items per call' );
		}

		return $this->success( $this->process( array_values( $items ) ) );
	}

	/**
	 * Process every item.
	 *
	 * @param array $items Raw items.
	 * @return array { results, ok_count, error_count }
	 */
	public function process( array $items ): array {
		$results = array();
		$ok      = 0;
		foreach ( $items as $item ) {
			$result    = $this->process_item( $item );
			$results[] = $result;
			if ( $result['ok'] ) {
				++$ok;
			}
		}

		return array(
			'results'     => $results,
			'ok_count'    => $ok,
			'error_count' => count( $results ) - $ok,
		);
	}

	/**
	 * One item.
	 *
	 * @param mixed $item Raw item.
	 * @return array
	 */
	protected function process_item( $item ): array {
		$id     = is_array( $item ) && isset( $item['id'] ) ? self::positive_int( $item['id'] ) : 0;
		$object = is_array( $item ) && isset( $item['object'] ) && is_string( $item['object'] ) ? $item['object'] : '';
		$result = array(
			'id'      => $id,
			'object'  => $object,
			'ok'      => false,
			'written' => array(),
			'deleted' => array(),
		);

		if ( $id <= 0 ) {
			return $this->fail( $result, 400, 'id must be a positive integer' );
		}

		if ( 'term' === $object ) {
			$taxonomy           = isset( $item['taxonomy'] ) && is_string( $item['taxonomy'] ) ? $item['taxonomy'] : '';
			$result['taxonomy'] = $taxonomy;
			if ( ! self::is_supported_taxonomy( $taxonomy ) ) {
				return $this->fail( $result, 422, 'unsupported taxonomy' );
			}
			if ( ! $this->term_exists_in( $id, $taxonomy ) ) {
				return $this->fail( $result, 404, 'term not found in taxonomy' );
			}
			$type = 'term';
		} elseif ( isset( self::POST_OBJECTS[ $object ] ) ) {
			$post_type = $this->post_type( $id );
			if ( null === $post_type ) {
				return $this->fail( $result, 404, 'post not found' );
			}
			if ( self::POST_OBJECTS[ $object ] !== $post_type ) {
				return $this->fail( $result, 422, 'object is a ' . $post_type . ', not a ' . $object );
			}
			$type = 'post';
		} else {
			return $this->fail( $result, 400, 'object must be product, term, post or page' );
		}

		$prepared = Meta_Writer::prepare( isset( $item['fields'] ) ? $item['fields'] : null );
		if ( null !== $prepared['error'] ) {
			return $this->fail( $result, 422, $prepared['error'] );
		}

		$applied           = $this->writer->apply( $type, $id, $prepared );
		$result['ok']      = true;
		$result['written'] = $applied['written'];
		$result['deleted'] = $applied['deleted'];
		return $result;
	}

	/**
	 * Mark a result failed.
	 *
	 * @param array  $result Result.
	 * @param int    $status HTTP-like status for the item (400 / 404 / 422).
	 * @param string $error  Message.
	 * @return array
	 */
	private function fail( array $result, int $status, string $error ): array {
		$result['status'] = $status;
		$result['error']  = $error;
		return $result;
	}

	/**
	 * Positive integer ID (int, or digit-only string), else 0.
	 *
	 * `is_numeric` would also accept `1e3`, `1.5` or `' 7'`.
	 *
	 * @param mixed $value Raw id.
	 * @return int
	 */
	public static function positive_int( $value ): int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}
		if ( is_string( $value ) && ctype_digit( $value ) ) {
			return (int) $value > 0 ? (int) $value : 0;
		}
		return 0;
	}

	/**
	 * Taxonomies the head renderer reads term meta for.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	public static function is_supported_taxonomy( string $taxonomy ): bool {
		return in_array( $taxonomy, Head_Renderer::TAXONOMIES, true ) || 0 === strpos( $taxonomy, 'pa_' );
	}

	/**
	 * Post type of a post, or null when missing.
	 *
	 * @param int $id Post ID.
	 * @return string|null
	 */
	protected function post_type( int $id ): ?string {
		$type = get_post_type( $id );
		return is_string( $type ) ? $type : null;
	}

	/**
	 * Whether the term exists in the taxonomy.
	 *
	 * @param int    $id       Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	protected function term_exists_in( int $id, string $taxonomy ): bool {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		$term = get_term( $id, $taxonomy );
		return is_object( $term ) && ! is_wp_error( $term );
	}
}
