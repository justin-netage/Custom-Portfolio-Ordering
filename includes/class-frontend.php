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

	/**
	 * Supported post types (same list as admin).
	 *
	 * @var array
	 */
	private $supported_post_types = array( 'portfolio', 'developer_portfolio', 'developer-portfolio', 'project' );

	/**
	 * Resolved post type.
	 *
	 * @var string|null
	 */
	private $post_type = null;

	public function __construct() {
		add_action( 'pre_get_posts', array( $this, 'modify_query' ), 99 );
		add_filter( 'posts_clauses', array( $this, 'modify_query_clauses' ), 99, 2 );
	}

	/**
	 * Get the portfolio post type.
	 *
	 * @return string
	 */
	private function get_post_type() {
		if ( $this->post_type ) {
			return $this->post_type;
		}

		foreach ( $this->supported_post_types as $pt ) {
			if ( post_type_exists( $pt ) ) {
				$this->post_type = $pt;
				return $this->post_type;
			}
		}

		$this->post_type = apply_filters( 'cpo_portfolio_post_type', 'portfolio' );
		return $this->post_type;
	}

	/**
	 * Check if a query is for our portfolio post type.
	 *
	 * @param WP_Query $query The query to check.
	 * @return bool
	 */
	private function is_portfolio_query( $query ) {
		$post_type = $this->get_post_type();
		$qpt       = $query->get( 'post_type' );

		if ( is_array( $qpt ) ) {
			return in_array( $post_type, $qpt, true );
		}

		return $qpt === $post_type;
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
		$post_type  = $this->get_post_type();
		$taxonomies = get_object_taxonomies( $post_type );

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

		// Check if this term has been ordered.
		if ( ! get_option( 'cpo_ordered_term_' . $term_id ) ) {
			return;
		}

		// Store the term_id on the query so the clauses filter can use it.
		$query->set( 'cpo_term_id', $term_id );
	}

	/**
	 * Modify SQL clauses to order by the custom meta value.
	 *
	 * Uses a LEFT JOIN so items without a saved order appear at the end.
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

		$meta_key = '_cpo_order_' . intval( $term_id );

		// LEFT JOIN to get the order meta, with NULL (unordered) items sorted to the end.
		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} AS cpo_meta ON ({$wpdb->posts}.ID = cpo_meta.post_id AND cpo_meta.meta_key = %s)",
			$meta_key
		);

		$clauses['orderby'] = "CASE WHEN cpo_meta.meta_value IS NULL THEN 1 ELSE 0 END ASC, CAST(cpo_meta.meta_value AS UNSIGNED) ASC, {$wpdb->posts}.post_title ASC";

		return $clauses;
	}
}
