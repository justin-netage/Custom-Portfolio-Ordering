<?php
/**
 * Frontend query modifications for Custom Portfolio Ordering.
 *
 * Intercepts portfolio queries that filter by a taxonomy term and
 * applies the saved custom order (stored in post meta) so the
 * drag-and-drop order is reflected everywhere on the site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPO_Frontend {

	const POST_TYPE = 'featured_item';

	public function __construct() {
		add_action( 'pre_get_posts', array( $this, 'modify_query' ), 99 );
		add_filter( 'posts_clauses', array( $this, 'modify_query_clauses' ), 99, 2 );
	}

	/**
	 * Check if a query is for our portfolio post type.
	 *
	 * @param WP_Query $query The query to check.
	 * @return bool
	 */
	private function is_portfolio_query( $query ) {
		$qpt = $query->get( 'post_type' );

		if ( is_array( $qpt ) ) {
			return in_array( self::POST_TYPE, $qpt, true );
		}

		return $qpt === self::POST_TYPE;
	}

	/**
	 * Extract the term ID from a query's tax_query.
	 *
	 * @param WP_Query $query The query.
	 * @return int|null The term ID, or null if not a single-term query.
	 */
	private function get_term_id_from_query( $query ) {
		// Check for taxonomy archive (is_tax).
		$queried = $query->get_queried_object();
		if ( $queried instanceof WP_Term ) {
			return $queried->term_id;
		}

		// Check tax_query parameter.
		$tax_query = $query->get( 'tax_query' );
		if ( ! empty( $tax_query ) && is_array( $tax_query ) ) {
			foreach ( $tax_query as $tq ) {
				if ( ! is_array( $tq ) || ! isset( $tq['terms'] ) ) {
					continue;
				}

				$terms = (array) $tq['terms'];
				if ( count( $terms ) === 1 ) {
					$term_value = reset( $terms );
					$taxonomy   = $tq['taxonomy'] ?? '';
					$field      = $tq['field'] ?? 'term_id';

					// Resolve to term_id if needed.
					if ( $field === 'term_id' || $field === 'id' ) {
						return (int) $term_value;
					}

					if ( $field === 'slug' && $taxonomy ) {
						$term = get_term_by( 'slug', $term_value, $taxonomy );
						return $term ? $term->term_id : null;
					}

					if ( $field === 'name' && $taxonomy ) {
						$term = get_term_by( 'name', $term_value, $taxonomy );
						return $term ? $term->term_id : null;
					}
				}
			}
		}

		// Check simple taxonomy query vars (e.g., ?portfolio_category=slug).
		$taxonomies = get_object_taxonomies( self::POST_TYPE );

		foreach ( $taxonomies as $tax ) {
			$tax_obj = get_taxonomy( $tax );
			if ( ! $tax_obj ) {
				continue;
			}

			$query_var = $tax_obj->query_var;
			if ( $query_var && $query->get( $query_var ) ) {
				$slug = $query->get( $query_var );
				$term = get_term_by( 'slug', $slug, $tax );
				if ( $term ) {
					return $term->term_id;
				}
			}
		}

		return null;
	}

	/**
	 * Resolve the effective ordering mode for a term.
	 *
	 * Returns 'latest' when the term (or the site-wide default) is set to
	 * order everything by publish date, otherwise 'custom'.
	 *
	 * @param int $term_id The term ID.
	 * @return string 'latest' or 'custom'.
	 */
	public static function get_order_mode( $term_id ) {
		$mode = get_option( 'cpo_order_mode_' . intval( $term_id ), '' );

		if ( 'latest' === $mode || 'custom' === $mode ) {
			return $mode;
		}

		// Fall back to the site-wide default.
		return 'latest' === get_option( 'cpo_global_order_mode', 'custom' ) ? 'latest' : 'custom';
	}

	/**
	 * Flag portfolio queries that have a custom order so we can
	 * modify the SQL clauses in the next filter.
	 *
	 * @param WP_Query $query The query.
	 */
	public function modify_query( $query ) {
		// Don't modify admin queries (our admin AJAX handles its own ordering).
		if ( is_admin() ) {
			return;
		}

		if ( ! $this->is_portfolio_query( $query ) ) {
			return;
		}

		// Skip if the query explicitly sets a different orderby that we shouldn't override.
		if ( $query->get( 'cpo_skip_ordering' ) ) {
			return;
		}

		$term_id = $this->get_term_id_from_query( $query );

		if ( ! $term_id ) {
			return;
		}

		$mode = self::get_order_mode( $term_id );

		// "Latest" mode orders purely by date and needs no saved custom order.
		if ( 'latest' === $mode ) {
			$query->set( 'cpo_term_id', $term_id );
			$query->set( 'cpo_order_mode', 'latest' );
			return;
		}

		// Custom mode: only apply once this term has been ordered.
		if ( ! get_option( 'cpo_ordered_term_' . $term_id ) ) {
			return;
		}

		// Store the term_id on the query so the clauses filter can use it.
		$query->set( 'cpo_term_id', $term_id );
		$query->set( 'cpo_order_mode', 'custom' );
	}

	/**
	 * Modify SQL clauses to apply the selected ordering.
	 *
	 * In "custom" mode, items are ordered by their saved order value, but any
	 * items without a saved order (newly added entries) float to the top,
	 * newest first, so the latest work always leads regardless of the custom
	 * order beneath it. In "latest" mode, every item is ordered by date.
	 *
	 * @param array    $clauses SQL clauses.
	 * @param WP_Query $query   The query.
	 * @return array Modified clauses.
	 */
	public function modify_query_clauses( $clauses, $query ) {
		if ( is_admin() ) {
			return $clauses;
		}

		$term_id = $query->get( 'cpo_term_id' );

		if ( ! $term_id ) {
			return $clauses;
		}

		global $wpdb;

		// Latest mode: order everything by publish date, newest first.
		if ( 'latest' === $query->get( 'cpo_order_mode' ) ) {
			$clauses['orderby'] = "{$wpdb->posts}.post_date DESC, {$wpdb->posts}.post_title ASC";
			return $clauses;
		}

		$meta_key = '_cpo_order_' . intval( $term_id );

		// LEFT JOIN to get the order meta. Items without a saved order (NULL)
		// are the latest additions and sort to the TOP, newest first; ordered
		// items follow in their saved sequence.
		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} AS cpo_meta ON ({$wpdb->posts}.ID = cpo_meta.post_id AND cpo_meta.meta_key = %s)",
			$meta_key
		);

		$clauses['orderby'] = "CASE WHEN cpo_meta.meta_value IS NULL THEN 0 ELSE 1 END ASC, CAST(cpo_meta.meta_value AS UNSIGNED) ASC, {$wpdb->posts}.post_date DESC, {$wpdb->posts}.post_title ASC";

		return $clauses;
	}
}
