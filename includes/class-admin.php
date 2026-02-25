<?php
/**
 * Admin functionality for Custom Portfolio Ordering.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPO_Admin {

	const POST_TYPE = 'featured_item';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'wp_ajax_cpo_save_order', array( $this, 'ajax_save_order' ) );
		add_action( 'wp_ajax_cpo_get_items', array( $this, 'ajax_get_items' ) );
	}

	/**
	 * Get all taxonomies attached to the portfolio post type.
	 *
	 * @return array
	 */
	public function get_taxonomies() {
		$taxonomies = get_object_taxonomies( self::POST_TYPE, 'objects' );

		// Filter to hierarchical taxonomies (categories) only.
		$filtered = array();
		foreach ( $taxonomies as $slug => $tax ) {
			if ( $tax->hierarchical ) {
				$filtered[ $slug ] = $tax;
			}
		}

		return $filtered;
	}

	/**
	 * Register the admin menu page.
	 */
	public function add_menu_page() {
		add_menu_page(
			__( 'Portfolio Ordering', 'custom-portfolio-ordering' ),
			__( 'Portfolio Order', 'custom-portfolio-ordering' ),
			'edit_posts',
			'custom-portfolio-ordering',
			array( $this, 'render_admin_page' ),
			'dashicons-sort',
			26
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		wp_enqueue_script( 'jquery-ui-sortable' );

		wp_enqueue_script(
			'cpo-admin-sort',
			CPO_PLUGIN_URL . 'assets/js/admin-sort.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			CPO_VERSION,
			true
		);

		wp_localize_script( 'cpo-admin-sort', 'cpoData', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cpo_sort_nonce' ),
		) );

		wp_enqueue_style(
			'cpo-admin-style',
			CPO_PLUGIN_URL . 'assets/css/admin-style.css',
			array(),
			CPO_VERSION
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_admin_page() {
		// Enqueue assets only on our page.
		$this->enqueue_assets( '' );

		$taxonomies = $this->get_taxonomies();
		?>
		<div class="wrap cpo-wrap">
			<h1><?php esc_html_e( 'Portfolio Ordering', 'custom-portfolio-ordering' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Select a category below, then drag and drop items to set a custom display order. The order is applied across the entire site.', 'custom-portfolio-ordering' ); ?></p>

			<div class="cpo-controls">
				<label for="cpo-taxonomy"><?php esc_html_e( 'Taxonomy:', 'custom-portfolio-ordering' ); ?></label>
				<select id="cpo-taxonomy">
					<?php foreach ( $taxonomies as $slug => $tax ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $tax->labels->name ); ?></option>
					<?php endforeach; ?>
				</select>

				<label for="cpo-term"><?php esc_html_e( 'Category:', 'custom-portfolio-ordering' ); ?></label>
				<select id="cpo-term">
					<option value=""><?php esc_html_e( '— Select a category —', 'custom-portfolio-ordering' ); ?></option>
				</select>

				<span id="cpo-loading" class="spinner" style="float:none;"></span>
			</div>

			<div id="cpo-status" class="cpo-status"></div>

			<div id="cpo-list-wrapper">
				<p class="cpo-placeholder"><?php esc_html_e( 'Select a taxonomy and category above to load items.', 'custom-portfolio-ordering' ); ?></p>
			</div>
		</div>

		<script>
			// Pass taxonomy terms data to JS to avoid extra AJAX calls.
			var cpoTerms = {};
			<?php
			foreach ( $taxonomies as $slug => $tax ) {
				$terms = get_terms( array(
					'taxonomy'   => $slug,
					'hide_empty' => false,
					'orderby'    => 'name',
					'order'      => 'ASC',
				) );

				$term_data = array();
				if ( ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						$term_data[] = array(
							'id'     => $term->term_id,
							'name'   => $term->name,
							'parent' => $term->parent,
							'count'  => $term->count,
						);
					}
				}

				echo 'cpoTerms[' . wp_json_encode( $slug ) . '] = ' . wp_json_encode( $term_data ) . ";\n";
			}
			?>
		</script>
		<?php
	}

	/**
	 * AJAX: Get portfolio items for a specific term.
	 */
	public function ajax_get_items() {
		check_ajax_referer( 'cpo_sort_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$taxonomy = sanitize_text_field( wp_unslash( $_POST['taxonomy'] ?? '' ) );
		$term_id  = absint( $_POST['term_id'] ?? 0 );

		if ( empty( $taxonomy ) || empty( $term_id ) ) {
			wp_send_json_error( 'Missing parameters' );
		}

		$meta_key = '_cpo_order_' . $term_id;

		// Get all posts in this term.
		$args = array(
			'post_type'      => self::POST_TYPE,
			'posts_per_page' => -1,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'tax_query'      => array(
				array(
					'taxonomy' => $taxonomy,
					'terms'    => $term_id,
				),
			),
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => $meta_key,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => $meta_key,
					'compare' => 'NOT EXISTS',
				),
			),
			'orderby'        => array(
				'meta_value_num' => 'ASC',
				'title'          => 'ASC',
			),
		);

		$query = new WP_Query( $args );
		$items = array();

		// Separate items with and without order, sort properly.
		$ordered   = array();
		$unordered = array();

		foreach ( $query->posts as $post ) {
			$order = get_post_meta( $post->ID, $meta_key, true );
			$item  = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'status'    => $post->post_status,
				'thumbnail' => get_the_post_thumbnail_url( $post->ID, 'medium' ) ?: get_the_post_thumbnail_url( $post->ID, 'thumbnail' ),
				'order'     => $order !== '' ? (int) $order : null,
			);

			if ( $order !== '' && $order !== false ) {
				$ordered[] = $item;
			} else {
				$unordered[] = $item;
			}
		}

		// Sort ordered items by their order value.
		usort( $ordered, function ( $a, $b ) {
			return $a['order'] - $b['order'];
		} );

		// Append unordered items at the end (sorted by title).
		usort( $unordered, function ( $a, $b ) {
			return strcasecmp( $a['title'], $b['title'] );
		} );

		$items = array_merge( $ordered, $unordered );

		wp_send_json_success( array(
			'items'    => $items,
			'term_id'  => $term_id,
			'taxonomy' => $taxonomy,
		) );
	}

	/**
	 * AJAX: Save the custom order for a term.
	 */
	public function ajax_save_order() {
		check_ajax_referer( 'cpo_sort_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$term_id  = absint( $_POST['term_id'] ?? 0 );
		$taxonomy = sanitize_text_field( wp_unslash( $_POST['taxonomy'] ?? '' ) );
		$order    = isset( $_POST['order'] ) ? array_map( 'absint', $_POST['order'] ) : array();

		if ( empty( $term_id ) || empty( $order ) ) {
			wp_send_json_error( 'Missing parameters' );
		}

		$meta_key = '_cpo_order_' . $term_id;

		foreach ( $order as $position => $post_id ) {
			update_post_meta( $post_id, $meta_key, $position );
		}

		// Also store a global flag so we know this term has custom ordering.
		update_option( 'cpo_ordered_term_' . $term_id, true );

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of items reordered */
				__( 'Order saved for %d items.', 'custom-portfolio-ordering' ),
				count( $order )
			),
		) );
	}
}
