<?php
/**
 * Admin functionality for Custom Portfolio Ordering.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPO_Admin {

	const POST_TYPE         = 'featured_item';
	const IMPORT_CHUNK_SIZE = 5;

	private $page_hooks = array();

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_cpo_save_order', array( $this, 'ajax_save_order' ) );
		add_action( 'wp_ajax_cpo_get_items', array( $this, 'ajax_get_items' ) );
		add_action( 'wp_ajax_cpo_import_start', array( $this, 'ajax_import_start' ) );
		add_action( 'wp_ajax_cpo_import_chunk', array( $this, 'ajax_import_chunk' ) );
		add_action( 'wp_ajax_cpo_delete_item', array( $this, 'ajax_delete_item' ) );
		add_action( 'wp_ajax_cpo_get_sub_cats', array( $this, 'ajax_get_sub_cats' ) );
		add_action( 'wp_ajax_cpo_save_grid_order', array( $this, 'ajax_save_grid_order' ) );
		add_action( 'wp_ajax_cpo_get_term_images', array( $this, 'ajax_get_term_images' ) );
		add_action( 'wp_ajax_cpo_create_category', array( $this, 'ajax_create_category' ) );
		add_action( 'wp_ajax_cpo_create_item', array( $this, 'ajax_create_item' ) );
		add_action( 'wp_ajax_cpo_search_items', array( $this, 'ajax_search_items' ) );
		add_action( 'wp_ajax_cpo_update_item', array( $this, 'ajax_update_item' ) );
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
	 * Register the admin menu page and subpages.
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

		$this->page_hooks[] = add_submenu_page(
			'custom-portfolio-ordering',
			__( 'Portfolio Ordering', 'custom-portfolio-ordering' ),
			__( 'Portfolio Ordering', 'custom-portfolio-ordering' ),
			'edit_posts',
			'custom-portfolio-ordering',
			array( $this, 'render_admin_page' )
		);

		$this->page_hooks[] = add_submenu_page(
			'custom-portfolio-ordering',
			__( 'Import Portfolio Items', 'custom-portfolio-ordering' ),
			__( 'Import Items', 'custom-portfolio-ordering' ),
			'edit_posts',
			'cpo-import',
			array( $this, 'render_import_page' )
		);

		$this->page_hooks[] = add_submenu_page(
			'custom-portfolio-ordering',
			__( 'Manage Portfolio', 'custom-portfolio-ordering' ),
			__( 'Manage Items', 'custom-portfolio-ordering' ),
			'edit_posts',
			'cpo-manage',
			array( $this, 'render_manage_page' )
		);
	}

	/**
	 * Render the Import Items admin page.
	 */
	public function render_import_page() {
		?>
		<div class="wrap cpo-wrap">
			<h1><?php esc_html_e( 'Import Portfolio Items', 'custom-portfolio-ordering' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Upload a CSV file to create or update portfolio items. Items are matched by title — existing items will be updated, new ones will be created.', 'custom-portfolio-ordering' ); ?></p>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=custom-portfolio-ordering' ) ); ?>" class="nav-tab"><?php esc_html_e( 'Ordering', 'custom-portfolio-ordering' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cpo-import' ) ); ?>" class="nav-tab nav-tab-active"><?php esc_html_e( 'Import Items', 'custom-portfolio-ordering' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cpo-manage' ) ); ?>" class="nav-tab"><?php esc_html_e( 'Manage Items', 'custom-portfolio-ordering' ); ?></a>
			</nav>

			<div class="cpo-import-page">
				<div class="cpo-import-format">
					<strong><?php esc_html_e( 'Required column:', 'custom-portfolio-ordering' ); ?></strong> <code>id</code> <?php esc_html_e( '(WordPress post ID — used to match existing items)', 'custom-portfolio-ordering' ); ?><br>
					<strong><?php esc_html_e( 'Optional columns:', 'custom-portfolio-ordering' ); ?></strong>
					<code>title</code> <?php esc_html_e( '(fallback match / new item name),', 'custom-portfolio-ordering' ); ?>
					<code>thumbnail</code> <?php esc_html_e( '(image URL),', 'custom-portfolio-ordering' ); ?>
					<code>Parent Category</code>, <code>Sub Category</code><br>
					<?php esc_html_e( 'Any other columns in the sheet are ignored.', 'custom-portfolio-ordering' ); ?>
					<pre class="cpo-import-example">id,title,Parent Category,Sub Category
1,122,Venue,Wild Horse Resort
2,456,Venue,Sun Valley Lodge</pre>
				</div>

				<div class="cpo-import-file-area">
					<label class="cpo-file-label">
						<span class="dashicons dashicons-upload"></span>
						<?php esc_html_e( 'Choose CSV File', 'custom-portfolio-ordering' ); ?>
						<input type="file" id="cpo-csv-file" accept=".csv,text/csv" style="position:absolute;opacity:0;width:0;height:0;">
					</label>
					<span id="cpo-file-name" class="cpo-file-name"><?php esc_html_e( 'No file chosen', 'custom-portfolio-ordering' ); ?></span>
				</div>

				<p>
					<button type="button" id="cpo-import-submit" class="button button-primary" disabled>
						<?php esc_html_e( 'Import Items', 'custom-portfolio-ordering' ); ?>
					</button>
					<span id="cpo-import-spinner" class="spinner" style="float:none;vertical-align:middle;"></span>
				</p>

				<div id="cpo-import-progress" style="display:none;">
					<div class="cpo-progress-bar-container">
						<div id="cpo-progress-bar" class="cpo-progress-bar" style="width:0%;"></div>
					</div>
					<p id="cpo-progress-label" class="cpo-progress-label"></p>
					<div class="cpo-progress-stats">
						<span><span class="dashicons dashicons-yes-alt"></span> <strong id="cpo-stat-created">0</strong> created</span>
						<span><span class="dashicons dashicons-update-alt"></span> <strong id="cpo-stat-updated">0</strong> updated</span>
						<span><span class="dashicons dashicons-minus"></span> <strong id="cpo-stat-skipped">0</strong> skipped</span>
					</div>
				</div>

				<div id="cpo-import-results" class="cpo-import-results" style="display:none;"></div>
			</div>
		</div>

		<script>
		(function ($) {
			var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'cpo_sort_nonce' ) ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			var jobId = null, totalRows = 0, created = 0, updated = 0, skipped = 0, errors = [], diagData = null;

			function escHtml( str ) {
				return $( '<div>' ).text( String( str ) ).html();
			}

			function updateProgress( processed ) {
				var pct = totalRows > 0 ? Math.round( processed / totalRows * 100 ) : 0;
				$( '#cpo-progress-bar' ).css( 'width', pct + '%' );
				$( '#cpo-progress-label' ).text( 'Processing row ' + Math.min( processed, totalRows ) + ' of ' + totalRows + '\u2026' );
				$( '#cpo-stat-created' ).text( created );
				$( '#cpo-stat-updated' ).text( updated );
				$( '#cpo-stat-skipped' ).text( skipped );
			}

			function showError( msg ) {
				$( '#cpo-import-results' ).html( '<p class="cpo-import-error">' + escHtml( msg ) + '</p>' ).show();
				$( '#cpo-import-submit' ).prop( 'disabled', false );
				$( '#cpo-import-spinner' ).removeClass( 'is-active' );
			}

			function resetFileInput() {
				$( '#cpo-import-submit' ).prop( 'disabled', true );
				$( '#cpo-csv-file' ).val( '' );
				$( '#cpo-file-name' ).text( 'No file chosen' );
			}

			function showComplete() {
				$( '#cpo-progress-bar' ).css( 'width', '100%' );
				$( '#cpo-progress-label' ).text( 'Import complete!' );

				var html = '<p class="cpo-import-success">Import complete!</p>';
				html += '<ul class="cpo-import-summary">';
				html += '<li><span class="dashicons dashicons-yes-alt"></span> ' + created + ' item' + ( created !== 1 ? 's' : '' ) + ' created</li>';
				html += '<li><span class="dashicons dashicons-update-alt"></span> ' + updated + ' item' + ( updated !== 1 ? 's' : '' ) + ' updated</li>';
				if ( skipped ) {
					html += '<li><span class="dashicons dashicons-minus"></span> ' + skipped + ' row' + ( skipped !== 1 ? 's' : '' ) + ' skipped</li>';
				}
				html += '</ul>';
				if ( errors.length ) {
					html += '<div class="cpo-import-errors"><strong>Errors:</strong><ul>';
					$.each( errors, function ( i, err ) { html += '<li>' + escHtml( err ) + '</li>'; } );
					html += '</ul></div>';
				}
				if ( diagData ) {
					html += '<details class="cpo-import-diag"><summary>Diagnostics (taxonomy detection)</summary><ul>';
					html += '<li><strong>Detected headers:</strong> ' + escHtml( JSON.stringify( diagData.headers ) ) + '</li>';
					html += '<li><strong>has_parent_cat:</strong> ' + escHtml( String( diagData.has_parent_cat ) ) + '</li>';
					html += '<li><strong>has_sub_cat:</strong> ' + escHtml( String( diagData.has_sub_cat ) ) + '</li>';
					html += '<li><strong>primary_taxonomy:</strong> ' + escHtml( diagData.primary_taxonomy || '(none found)' ) + '</li>';
					html += '</ul></details>';
				}
				$( '#cpo-import-results' ).html( html ).show();
				resetFileInput();
			}

			function processNextChunk( offset ) {
				$.ajax( {
					url: ajaxUrl,
					type: 'POST',
					data: { action: 'cpo_import_chunk', nonce: nonce, job_id: jobId, offset: offset },
					success: function ( response ) {
						if ( ! response.success ) {
							showError( ( response.data && response.data.message ) ? response.data.message : 'Import failed.' );
							return;
						}
						var d = response.data;
						created += d.created;
						updated += d.updated;
						skipped += d.skipped;
						if ( d.errors && d.errors.length ) { errors = errors.concat( d.errors ); }
						if ( d.diag && Object.keys( d.diag ).length ) { diagData = d.diag; }
						updateProgress( d.next_offset );
						if ( d.done ) {
							showComplete();
						} else {
							processNextChunk( d.next_offset );
						}
					},
					error: function () { showError( 'A server error occurred during import.' ); }
				} );
			}

			$( '#cpo-csv-file' ).on( 'change', function () {
				var file = this.files[0];
				if ( file ) {
					$( '#cpo-file-name' ).text( file.name );
					$( '#cpo-import-submit' ).prop( 'disabled', false );
				} else {
					$( '#cpo-file-name' ).text( 'No file chosen' );
					$( '#cpo-import-submit' ).prop( 'disabled', true );
				}
			} );

			$( '#cpo-import-submit' ).on( 'click', function () {
				var fileInput = $( '#cpo-csv-file' )[0];
				if ( ! fileInput.files.length ) return;

				// Reset state.
				jobId = null; totalRows = 0; created = 0; updated = 0; skipped = 0; errors = []; diagData = null;
				$( '#cpo-import-submit' ).prop( 'disabled', true );
				$( '#cpo-import-spinner' ).addClass( 'is-active' );
				$( '#cpo-import-results' ).hide();
				$( '#cpo-import-progress' ).hide();

				// Step 1: upload file and get a job ID + total row count.
				var formData = new FormData();
				formData.append( 'action', 'cpo_import_start' );
				formData.append( 'nonce', nonce );
				formData.append( 'csv_file', fileInput.files[0] );

				$.ajax( {
					url: ajaxUrl,
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					success: function ( response ) {
						$( '#cpo-import-spinner' ).removeClass( 'is-active' );
						if ( ! response.success ) {
							showError( ( response.data && response.data.message ) ? response.data.message : 'Failed to start import.' );
							return;
						}
						jobId     = response.data.job_id;
						totalRows = response.data.total;

						// Step 2: process chunks, updating the progress bar after each.
						$( '#cpo-import-progress' ).show();
						updateProgress( 0 );
						processNextChunk( 0 );
					},
					error: function () {
						$( '#cpo-import-spinner' ).removeClass( 'is-active' );
						showError( 'A server error occurred. Please try again.' );
					}
				} );
			} );
		}( jQuery ));
		</script>
		<?php
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, $this->page_hooks, true ) ) {
			return;
		}

		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_media();

		wp_enqueue_script(
			'cpo-admin-sort',
			CPO_PLUGIN_URL . 'assets/js/admin-sort.js',
			array( 'jquery', 'jquery-ui-sortable', 'media-editor' ),
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
		$taxonomies = $this->get_taxonomies();
		?>
		<div class="wrap cpo-wrap">
			<h1><?php esc_html_e( 'Portfolio Ordering', 'custom-portfolio-ordering' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Select a category to set its display order. Selecting a parent category reorders the sub-category image boxes on its page; selecting a sub-category reorders the individual portfolio items within it.', 'custom-portfolio-ordering' ); ?></p>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=custom-portfolio-ordering' ) ); ?>" class="nav-tab nav-tab-active"><?php esc_html_e( 'Ordering', 'custom-portfolio-ordering' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cpo-import' ) ); ?>" class="nav-tab"><?php esc_html_e( 'Import Items', 'custom-portfolio-ordering' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cpo-manage' ) ); ?>" class="nav-tab"><?php esc_html_e( 'Manage Items', 'custom-portfolio-ordering' ); ?></a>
			</nav>

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

				<button type="button" id="cpo-preview-grid" class="button cpo-btn-preview" disabled>
					<span class="dashicons dashicons-screenoptions"></span> <?php esc_html_e( 'Grid Preview', 'custom-portfolio-ordering' ); ?>
				</button>
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
				'date'      => $post->post_date,
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

		// If this term has never been explicitly ordered, auto-save the current display
		// order so the frontend applies a consistent sequence from the very first load.
		if ( ! get_option( 'cpo_ordered_term_' . $term_id ) && ! empty( $items ) ) {
			foreach ( $items as $position => $item ) {
				update_post_meta( $item['id'], $meta_key, $position );
				$items[ $position ]['order'] = $position;
			}
			update_option( 'cpo_ordered_term_' . $term_id, true );
		}

		wp_send_json_success( array(
			'items'    => $items,
			'term_id'  => $term_id,
			'taxonomy' => $taxonomy,
		) );
	}

	/**
	 * AJAX: Move a portfolio item to trash.
	 */
	public function ajax_delete_item() {
		check_ajax_referer( 'cpo_sort_nonce', 'nonce' );

		if ( ! current_user_can( 'delete_posts' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );

		if ( empty( $post_id ) ) {
			wp_send_json_error( 'Missing parameters' );
		}

		$post = get_post( $post_id );

		if ( ! $post || $post->post_type !== self::POST_TYPE ) {
			wp_send_json_error( 'Invalid post' );
		}

		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$result = wp_trash_post( $post_id );

		if ( ! $result ) {
			wp_send_json_error( 'Could not move item to trash' );
		}

		wp_send_json_success( array(
			'message' => __( 'Item moved to trash.', 'custom-portfolio-ordering' ),
			'post_id' => $post_id,
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

	/**
	 * AJAX: Return sub-categories for a parent term, ordered to match the current page grid.
	 */
	public function ajax_get_sub_cats() {
		check_ajax_referer( 'cpo_sort_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$taxonomy  = sanitize_text_field( wp_unslash( $_POST['taxonomy'] ?? '' ) );
		$parent_id = absint( $_POST['term_id'] ?? 0 );

		if ( empty( $taxonomy ) || empty( $parent_id ) ) {
			wp_send_json_error( 'Missing parameters' );
		}

		$parent_term = get_term( $parent_id, $taxonomy );
		if ( is_wp_error( $parent_term ) || ! $parent_term ) {
			wp_send_json_error( 'Invalid term' );
		}

		$sub_terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'parent'     => $parent_id,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		) );

		if ( is_wp_error( $sub_terms ) ) {
			wp_send_json_error( 'Could not load sub-categories' );
		}

		// Find the parent page by slug.
		$parent_pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'name'           => $parent_term->slug,
			'post_parent'    => 0,
		) );

		$page_id        = 0;
		$ordered_slugs  = array();
		$slug_to_img_id = array();

		if ( ! empty( $parent_pages ) ) {
			$page_id = $parent_pages[0]->ID;
			$content = $parent_pages[0]->post_content;

			// Extract sub-category order and current img IDs from the grid row.
			if ( preg_match( '/\[row[^\]]*width="full-width"[^\]]*\](.*?)\[\/row\]/s', $content, $row_match ) ) {
				preg_match_all( '/link="[^"]*\/([^"\/]+)\/?"/', $row_match[1], $link_matches );
				$ordered_slugs = $link_matches[1] ?? array();

				preg_match_all( '/\[col[^\]]*\].*?\[\/col\]/s', $row_match[1], $col_matches );
				foreach ( $col_matches[0] ?? array() as $col ) {
					if ( preg_match( '/link="[^"]*\/([^"\/]+)\/?"/', $col, $lm )
						&& preg_match( '/\bimg="(\d+)"/', $col, $im )
					) {
						$slug_to_img_id[ $lm[1] ] = (int) $im[1];
					}
				}
			}
		}

		// Build items: prefer the img already set on the page, fall back to
		// the first portfolio item's featured image if the page doesn't exist yet.
		$slug_to_item = array();
		foreach ( $sub_terms as $term ) {
			$img_id    = $slug_to_img_id[ $term->slug ] ?? 0;
			$thumbnail = '';

			if ( $img_id ) {
				$src = wp_get_attachment_image_src( $img_id, 'medium' );
				if ( $src ) {
					$thumbnail = $src[0];
				}
			}

			if ( ! $thumbnail ) {
				$posts = get_posts( array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'tax_query'      => array( array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $term->term_id,
					) ),
				) );

				if ( ! empty( $posts ) ) {
					$thumbnail = get_the_post_thumbnail_url( $posts[0]->ID, 'medium' ) ?: '';
					if ( ! $img_id ) {
						$img_id = (int) get_post_thumbnail_id( $posts[0]->ID );
					}
				}
			}

			$slug_to_item[ $term->slug ] = array(
				'id'        => $term->term_id,
				'name'      => $term->name,
				'slug'      => $term->slug,
				'count'     => $term->count,
				'thumbnail' => $thumbnail,
				'img_id'    => $img_id,
			);
		}

		// Sort by current page grid order; handle stale slugs from renamed terms.
		$items          = array();
		$remaining_pool = $slug_to_item;

		foreach ( $ordered_slugs as $slug ) {
			if ( isset( $remaining_pool[ $slug ] ) ) {
				// Exact slug match.
				$items[] = $remaining_pool[ $slug ];
				unset( $remaining_pool[ $slug ] );
			} elseif ( ! empty( $remaining_pool ) ) {
				// Stale slug — pair with the next unmatched sub-term.
				$key  = array_key_first( $remaining_pool );
				$item = $remaining_pool[ $key ];
				unset( $remaining_pool[ $key ] );

				// Carry over the page image for this col position.
				if ( ! empty( $slug_to_img_id[ $slug ] ) ) {
					$img_id         = $slug_to_img_id[ $slug ];
					$item['img_id'] = $img_id;
					$src            = wp_get_attachment_image_src( $img_id, 'medium' );
					if ( $src ) {
						$item['thumbnail'] = $src[0];
					}
				}

				$items[] = $item;
			}
		}

		// Append any sub-terms not represented on the page.
		$items = array_merge( $items, array_values( $remaining_pool ) );

		wp_send_json_success( array(
			'items'    => $items,
			'term_id'  => $parent_id,
			'taxonomy' => $taxonomy,
			'page_id'  => $page_id,
			'has_page' => $page_id > 0,
		) );
	}

	/**
	 * AJAX: Reorder the [col] blocks inside [row width="full-width"] on the parent page.
	 */
	public function ajax_save_grid_order() {
		check_ajax_referer( 'cpo_sort_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$taxonomy  = sanitize_text_field( wp_unslash( $_POST['taxonomy'] ?? '' ) );
		$parent_id = absint( $_POST['term_id'] ?? 0 );
		$order     = isset( $_POST['order'] ) ? array_map( 'absint', $_POST['order'] ) : array();

		// Optional per-item image overrides: images[term_id] => attachment_id.
		$images = array();
		if ( ! empty( $_POST['images'] ) && is_array( $_POST['images'] ) ) {
			foreach ( $_POST['images'] as $tid => $aid ) {
				$images[ absint( $tid ) ] = absint( $aid );
			}
		}

		if ( empty( $taxonomy ) || empty( $parent_id ) || empty( $order ) ) {
			wp_send_json_error( 'Missing parameters' );
		}

		$parent_term = get_term( $parent_id, $taxonomy );
		if ( is_wp_error( $parent_term ) || ! $parent_term ) {
			wp_send_json_error( 'Invalid term' );
		}

		$parent_pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'name'           => $parent_term->slug,
			'post_parent'    => 0,
		) );

		if ( empty( $parent_pages ) ) {
			wp_send_json_error( array( 'message' => 'Parent page not found. Create the page first, then set the grid order here.' ) );
		}

		$page    = $parent_pages[0];
		$content = $page->post_content;

		if ( ! preg_match( '/(\[row[^\]]*width="full-width"[^\]]*\])(.*?)(\[\/row\])/s', $content, $row_match ) ) {
			wp_send_json_error( array( 'message' => 'Grid row not found in the page content.' ) );
		}

		// Extract individual [col]...[/col] blocks from inside the grid row.
		preg_match_all( '/\[col[^\]]*\].*?\[\/col\]/s', $row_match[2], $col_matches );
		$cols = $col_matches[0] ?? array();

		// Map each col to its link slug.
		$slug_to_col = array();
		$unmatched   = array();
		foreach ( $cols as $col ) {
			if ( preg_match( '/link="[^"]*\/([^"\/]+)\/?"/', $col, $lm ) ) {
				$slug_to_col[ $lm[1] ] = $col;
			} else {
				$unmatched[] = $col;
			}
		}

		// Build term_id → col mapping, handling renamed slugs.
		$termid_to_col = array();
		$claimed_slugs = array();

		// First pass: exact slug match.
		foreach ( $order as $term_id ) {
			$term = get_term( $term_id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) && isset( $slug_to_col[ $term->slug ] ) ) {
				$termid_to_col[ $term_id ] = $slug_to_col[ $term->slug ];
				$claimed_slugs[]           = $term->slug;
			}
		}

		// Second pass: pair unmatched terms with unclaimed cols in page order.
		$unclaimed_cols = array();
		foreach ( $slug_to_col as $slug => $col ) {
			if ( ! in_array( $slug, $claimed_slugs, true ) ) {
				$unclaimed_cols[] = $col;
			}
		}
		$uc_idx = 0;
		foreach ( $order as $term_id ) {
			if ( ! isset( $termid_to_col[ $term_id ] ) && $uc_idx < count( $unclaimed_cols ) ) {
				$termid_to_col[ $term_id ] = $unclaimed_cols[ $uc_idx ];
				$uc_idx++;
			}
		}

		// Build a col template from the first existing col so new cols match the page style.
		$col_template = '';
		if ( ! empty( $cols ) ) {
			$first_col = $cols[0];
			// Extract the [col ...] opening tag attributes.
			if ( preg_match( '/\[col([^\]]*)\]/', $first_col, $ct ) ) {
				$col_attrs = $ct[1];
			} else {
				$col_attrs = '';
			}
			// Extract the [ux_image_box ...] attributes (minus img and link which we set per-item).
			$ib_attrs = '';
			if ( preg_match( '/\[ux_image_box([^\]]*)\]/', $first_col, $ib ) ) {
				// Remove img="..." and link="..." so we can set our own.
				$ib_attrs = preg_replace( '/\s*\bimg="[^"]*"/', '', $ib[1] );
				$ib_attrs = preg_replace( '/\s*\blink="[^"]*"/', '', $ib_attrs );
			}
			$col_template = '[col' . $col_attrs . '][ux_image_box' . $ib_attrs . ' img="%IMG%" link="%LINK%"]%NAME%[/ux_image_box][/col]';
		}

		// Reorder cols, update link URLs to current slugs, and apply image changes.
		$new_cols       = array();
		$images_applied = 0;
		foreach ( $order as $term_id ) {
			$term = get_term( $term_id, $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			$new_link = '/' . $parent_term->slug . '/' . $term->slug;

			if ( isset( $termid_to_col[ $term_id ] ) ) {
				$col = $termid_to_col[ $term_id ];

				// Update the link to use current parent/term slugs.
				$col = preg_replace(
					'/link="[^"]*"/',
					'link="' . $new_link . '"',
					$col
				);

				// Apply image changes.
				if ( ! empty( $images[ $term_id ] ) ) {
					$new_img = $images[ $term_id ];
					if ( preg_match( '/\[ux_image_box[^\]]*\bimg="/', $col ) ) {
						$col = preg_replace( '/(\[ux_image_box[^\]]*\bimg=")[^"]*(")/s', '${1}' . $new_img . '${2}', $col );
						$images_applied++;
					} else {
						$col = preg_replace( '/(\[ux_image_box)([^\]]*\])/', '${1} img="' . $new_img . '"${2}', $col );
						$images_applied++;
					}
				}
			} elseif ( $col_template ) {
				// No existing col for this term — create one from the template.
				$img_id = ! empty( $images[ $term_id ] ) ? $images[ $term_id ] : '';
				$col    = str_replace(
					array( '%IMG%', '%LINK%', '%NAME%' ),
					array( $img_id, $new_link, $term->name ),
					$col_template
				);
				if ( ! empty( $img_id ) ) {
					$images_applied++;
				}
			} else {
				continue;
			}

			$new_cols[] = $col;
		}

		// Append anything not covered by the submitted order.
		$remaining_unclaimed = array_slice( $unclaimed_cols, $uc_idx );
		$new_cols = array_merge( $new_cols, $remaining_unclaimed, $unmatched );

		$new_row     = $row_match[1] . implode( '', $new_cols ) . $row_match[3];
		$new_content = str_replace( $row_match[0], $new_row, $content );

		if ( $new_content === $content ) {
			wp_send_json_success( array( 'message' => __( 'No changes needed.', 'custom-portfolio-ordering' ) ) );
			return;
		}

		$result = wp_update_post( array(
			'ID'           => $page->ID,
			'post_content' => $new_content,
		) );

		if ( is_wp_error( $result ) || 0 === $result ) {
			wp_send_json_error( array( 'message' => 'Failed to update the page.' ) );
		}

		// Clear all caches for this page so the frontend shows updated images.
		clean_post_cache( $page->ID );

		// Flatsome / UX Builder: delete any cached CSS or shortcode data.
		delete_post_meta( $page->ID, '_ux_builder_shortcodes' );
		delete_post_meta( $page->ID, 'ux_builder_css' );

		// Purge page caches for popular caching plugins.
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $page->ID ); // WP Super Cache.
		}
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $page->ID ); // W3 Total Cache.
		}
		if ( function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
			wpfc_clear_post_cache_by_id( $page->ID ); // WP Fastest Cache.
		}
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $page->ID ); // WP Rocket.
		}
		if ( class_exists( 'LiteSpeed_Cache_API' ) && method_exists( 'LiteSpeed_Cache_API', 'purge_post' ) ) {
			\LiteSpeed_Cache_API::purge_post( $page->ID ); // LiteSpeed Cache.
		}

		$msg = sprintf(
			/* translators: %d: number of categories reordered */
			__( 'Grid order saved for %d categories.', 'custom-portfolio-ordering' ),
			count( $new_cols )
		);

		if ( count( $images ) > 0 ) {
			$msg .= ' ' . sprintf(
				/* translators: %d: number of images updated */
				__( '%d image(s) updated on the page.', 'custom-portfolio-ordering' ),
				$images_applied
			);
		}

		wp_send_json_success( array(
			'message'         => $msg,
			'images_received' => count( $images ),
			'images_applied'  => $images_applied,
			'page_id'         => $page->ID,
			'cols_found'      => count( $cols ),
			'cols_matched'    => count( $termid_to_col ),
		) );
	}

	/**
	 * AJAX: Return featured-image attachment IDs for all posts in a given term.
	 * Used to pre-filter the media picker to only show images from that category.
	 */
	public function ajax_get_term_images() {
		check_ajax_referer( 'cpo_sort_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$taxonomy         = sanitize_text_field( wp_unslash( $_POST['taxonomy'] ?? '' ) );
		$term_id          = absint( $_POST['term_id'] ?? 0 );
		$include_children = ! empty( $_POST['include_children'] );

		if ( empty( $taxonomy ) || empty( $term_id ) ) {
			wp_send_json_error( 'Missing parameters' );
		}

		$posts = get_posts( array(
			'post_type'        => self::POST_TYPE,
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'tax_query'        => array( array(
				'taxonomy'         => $taxonomy,
				'field'            => 'term_id',
				'terms'            => $term_id,
				'include_children' => $include_children,
			) ),
		) );

		$attachment_ids = array();
		foreach ( $posts as $post_id ) {
			$thumb_id = (int) get_post_thumbnail_id( $post_id );
			if ( $thumb_id > 0 && ! in_array( $thumb_id, $attachment_ids, true ) ) {
				$attachment_ids[] = $thumb_id;
			}
		}

		wp_send_json_success( array( 'ids' => $attachment_ids ) );
	}

	/**
	 * AJAX: Receive the uploaded CSV, store it temporarily, and return a job ID + row count.
	 */
	public function ajax_import_start() {
		check_ajax_referer( 'cpo_sort_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		if ( empty( $_FILES['csv_file'] ) || (int) $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
			wp_send_json_error( array( 'message' => 'No valid file uploaded.' ) );
		}

		// Store the file in a protected uploads subdirectory so it survives across chunk requests.
		$upload_dir = wp_upload_dir();
		$import_dir = $upload_dir['basedir'] . '/cpo-imports';
		wp_mkdir_p( $import_dir );

		$htaccess = $import_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$job_id   = wp_generate_password( 16, false );
		$tmp_dest = $import_dir . '/import-' . $job_id . '.csv';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! move_uploaded_file( $_FILES['csv_file']['tmp_name'], $tmp_dest ) ) {
			wp_send_json_error( array( 'message' => 'Could not store the uploaded file.' ) );
		}

		$handle = fopen( $tmp_dest, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			unlink( $tmp_dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_send_json_error( array( 'message' => 'Could not read the uploaded file.' ) );
		}

		$headers = fgetcsv( $handle );
		if ( ! $headers ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			unlink( $tmp_dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_send_json_error( array( 'message' => 'The CSV file appears to be empty.' ) );
		}
		$headers = array_map( 'strtolower', array_map( 'trim', $headers ) );

		if ( ! in_array( 'id', $headers, true ) && ! in_array( 'title', $headers, true ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			unlink( $tmp_dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_send_json_error( array( 'message' => 'CSV must include an "id" or "title" column.' ) );
		}

		// Count total data rows (all rows after the header, including blank).
		$total = 0;
		while ( fgetcsv( $handle ) !== false ) {
			$total++;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( $total === 0 ) {
			unlink( $tmp_dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_send_json_error( array( 'message' => 'The CSV file contains no data rows.' ) );
		}

		set_transient( 'cpo_import_' . $job_id, array(
			'file'           => $tmp_dest,
			'headers'        => $headers,
			'total'          => $total,
			'term_positions' => array(),
		), HOUR_IN_SECONDS );

		wp_send_json_success( array(
			'job_id' => $job_id,
			'total'  => $total,
		) );
	}

	/**
	 * AJAX: Process one chunk of rows for an in-progress import job.
	 */
	public function ajax_import_chunk() {
		check_ajax_referer( 'cpo_sort_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$job_id = sanitize_key( $_POST['job_id'] ?? '' );
		$offset = absint( $_POST['offset'] ?? 0 );

		$job = get_transient( 'cpo_import_' . $job_id );
		if ( ! $job || empty( $job['file'] ) || ! file_exists( $job['file'] ) ) {
			wp_send_json_error( array( 'message' => 'Import job not found or expired.' ) );
		}

		$headers        = $job['headers'];
		$has_thumbnail  = in_array( 'thumbnail', $headers, true );
		$has_parent_cat = in_array( 'parent category', $headers, true );
		$has_sub_cat    = in_array( 'sub category', $headers, true );
		$term_positions = $job['term_positions'] ?? array();

		if ( $has_thumbnail ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$primary_taxonomy    = '';
		$tax_is_hierarchical = false;
		if ( $has_parent_cat || $has_sub_cat ) {
			// Use the first custom (non-built-in) taxonomy for this post type.
			// Skip built-ins like post_tag/category which may be registered first.
			$all_taxes = get_object_taxonomies( self::POST_TYPE, 'objects' );
			foreach ( $all_taxes as $tax_obj ) {
				if ( ! $tax_obj->_builtin ) {
					$primary_taxonomy    = $tax_obj->name;
					$tax_is_hierarchical = (bool) $tax_obj->hierarchical;
					break;
				}
			}
		}

		$handle = fopen( $job['file'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			wp_send_json_error( array( 'message' => 'Could not read import file.' ) );
		}

		fgetcsv( $handle ); // skip header row

		// Seek to the current offset.
		for ( $i = 0; $i < $offset; $i++ ) {
			fgetcsv( $handle );
		}

		global $wpdb;
		$created  = 0;
		$updated  = 0;
		$skipped  = 0;
		$errors   = array();
		$consumed = 0;

		while ( $consumed < self::IMPORT_CHUNK_SIZE && ( $row = fgetcsv( $handle ) ) !== false ) {
			$consumed++;

			// Skip blank rows (counted in offset but not processed).
			if ( count( $row ) === 1 && trim( $row[0] ) === '' ) {
				continue;
			}

			while ( count( $row ) < count( $headers ) ) {
				$row[] = '';
			}

			$data   = array_combine( $headers, array_slice( $row, 0, count( $headers ) ) );
			$row_id = sanitize_text_field( trim( $data['id'] ?? '' ) );
			$title  = sanitize_text_field( trim( $data['title'] ?? '' ) );

			if ( $row_id === '' && $title === '' ) {
				$skipped++;
				continue;
			}

			// Match by title first, then fall back to id (WordPress post ID).
			$existing_id = 0;
			if ( $title !== '' ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = %s AND post_status != 'trash' LIMIT 1",
					$title,
					self::POST_TYPE
				) );
			}
			if ( ! $existing_id && $row_id !== '' ) {
				$numeric_id = absint( $row_id );
				if ( $numeric_id ) {
					$found = get_post( $numeric_id );
					if ( $found && $found->post_type === self::POST_TYPE && $found->post_status !== 'trash' ) {
						$existing_id = $found->ID;
					}
				}
			}

			$post_args = array(
				'post_status' => 'publish',
				'post_type'   => self::POST_TYPE,
			);
			if ( $title !== '' ) {
				$post_args['post_title'] = $title;
			}

			if ( $existing_id ) {
				$post_args['ID'] = $existing_id;
				$result          = wp_update_post( $post_args, true );
			} else {
				if ( $title === '' ) {
					$skipped++; // Cannot create a post without a title.
					continue;
				}
				$result = wp_insert_post( $post_args, true );
			}

			$label = $row_id !== '' ? 'id:' . $row_id : $title;
			if ( is_wp_error( $result ) ) {
				$errors[] = '"' . $label . '": ' . $result->get_error_message();
				continue;
			}

			$post_id = (int) $result;

			// Thumbnail.
			if ( $has_thumbnail ) {
				$thumbnail_url = esc_url_raw( trim( $data['thumbnail'] ?? '' ) );
				if ( $thumbnail_url !== '' ) {
					$attachment_id = media_sideload_image( $thumbnail_url, $post_id, null, 'id' );
					if ( ! is_wp_error( $attachment_id ) ) {
						set_post_thumbnail( $post_id, $attachment_id );
					}
				}
			}

			// Parent Category + Sub Category.
			if ( $primary_taxonomy !== '' && ( $has_parent_cat || $has_sub_cat ) ) {
				$parent_cat_name = sanitize_text_field( trim( $data['parent category'] ?? '' ) );
				$sub_cat_name    = sanitize_text_field( trim( $data['sub category'] ?? '' ) );
				$term_ids        = array();
				$parent_term_id  = 0;

				if ( $parent_cat_name !== '' ) {
					// Find or create the parent term (root-level for hierarchical taxonomies).
					$lookup_args = array(
						'taxonomy'               => $primary_taxonomy,
						'name'                   => $parent_cat_name,
						'hide_empty'             => false,
						'fields'                 => 'ids',
						'update_term_meta_cache' => false,
					);
					if ( $tax_is_hierarchical ) {
						$lookup_args['parent'] = 0;
					}
					$existing = get_terms( $lookup_args );
					if ( ! empty( $existing ) && ! is_wp_error( $existing ) ) {
						$parent_term_id = (int) $existing[0];
					} else {
						$new_term = wp_insert_term( $parent_cat_name, $primary_taxonomy );
						if ( is_wp_error( $new_term ) && $new_term->get_error_code() === 'term_exists' ) {
							$parent_term_id = (int) $new_term->get_error_data();
						} elseif ( ! is_wp_error( $new_term ) ) {
							$parent_term_id = $new_term['term_id'];
						}
					}
					if ( $parent_term_id ) {
						$term_ids[] = $parent_term_id;
					}
				}

				if ( $sub_cat_name !== '' ) {
					// Find or create the sub-category, scoped under the parent when available.
					$sub_args = array(
						'taxonomy'               => $primary_taxonomy,
						'name'                   => $sub_cat_name,
						'hide_empty'             => false,
						'fields'                 => 'ids',
						'update_term_meta_cache' => false,
					);
					if ( $tax_is_hierarchical && $parent_term_id ) {
						$sub_args['parent'] = $parent_term_id;
					}
					$existing = get_terms( $sub_args );
					if ( ! empty( $existing ) && ! is_wp_error( $existing ) ) {
						$sub_term_id = (int) $existing[0];
					} else {
						$insert_args = ( $tax_is_hierarchical && $parent_term_id ) ? array( 'parent' => $parent_term_id ) : array();
						$new_term    = wp_insert_term( $sub_cat_name, $primary_taxonomy, $insert_args );
						if ( is_wp_error( $new_term ) && $new_term->get_error_code() === 'term_exists' ) {
							$sub_term_id = (int) $new_term->get_error_data();
						} elseif ( ! is_wp_error( $new_term ) ) {
							$sub_term_id = $new_term['term_id'];
						} else {
							$sub_term_id = 0;
						}
					}
					if ( $sub_term_id ) {
						$term_ids[] = $sub_term_id;
					}
				}

				if ( ! empty( $term_ids ) ) {
					// Append terms so existing category assignments are preserved.
					wp_set_object_terms( $post_id, $term_ids, $primary_taxonomy, true );
					foreach ( $term_ids as $assigned_term_id ) {
						$pos = $term_positions[ $assigned_term_id ] ?? 0;
						update_post_meta( $post_id, '_cpo_order_' . $assigned_term_id, $pos );
						update_option( 'cpo_ordered_term_' . $assigned_term_id, true );
						$term_positions[ $assigned_term_id ] = $pos + 1;
					}
				} else {
					$errors[] = '"' . $label . '": could not resolve taxonomy "' . $primary_taxonomy . '" — no terms assigned';
				}
			} elseif ( $has_parent_cat || $has_sub_cat ) {
				$errors[] = '"' . $label . '": no taxonomy found for post type "' . self::POST_TYPE . '" — categories skipped';
			}

			if ( $existing_id ) {
				$updated++;
			} else {
				$created++;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$next_offset = $offset + $consumed;
		$done        = ( $next_offset >= $job['total'] );

		if ( $done ) {
			unlink( $job['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			delete_transient( 'cpo_import_' . $job_id );
		} else {
			// Persist updated term_positions so the next chunk continues from the right offset.
			$job['term_positions'] = $term_positions;
			set_transient( 'cpo_import_' . $job_id, $job, HOUR_IN_SECONDS );
		}

		// First-chunk diagnostics to help surface taxonomy-detection issues.
		$diag = array();
		if ( $offset === 0 ) {
			$diag['headers']          = $headers;
			$diag['has_parent_cat']   = $has_parent_cat;
			$diag['has_sub_cat']      = $has_sub_cat;
			$diag['primary_taxonomy'] = $primary_taxonomy;
		}

		wp_send_json_success( array(
			'created'     => $created,
			'updated'     => $updated,
			'skipped'     => $skipped,
			'errors'      => $errors,
			'next_offset' => $next_offset,
			'done'        => $done,
			'diag'        => $diag,
		) );
	}

	/**
	 * Clear page caches after modifying page content.
	 *
	 * @param int $page_id The page ID to purge.
	 */
	private function purge_page_cache( $page_id ) {
		clean_post_cache( $page_id );
		delete_post_meta( $page_id, '_ux_builder_shortcodes' );
		delete_post_meta( $page_id, 'ux_builder_css' );

		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $page_id );
		}
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $page_id );
		}
		if ( function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
			wpfc_clear_post_cache_by_id( $page_id );
		}
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $page_id );
		}
		if ( class_exists( 'LiteSpeed_Cache_API' ) && method_exists( 'LiteSpeed_Cache_API', 'purge_post' ) ) {
			\LiteSpeed_Cache_API::purge_post( $page_id );
		}
	}

	/**
	 * AJAX: Create a taxonomy category and auto-generate its page.
	 */
	public function ajax_create_category() {
		check_ajax_referer( 'cpo_sort_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$taxonomy  = sanitize_text_field( wp_unslash( $_POST['taxonomy'] ?? '' ) );
		$parent_id = absint( $_POST['parent_id'] ?? 0 );
		$name      = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

		if ( empty( $taxonomy ) || empty( $name ) ) {
			wp_send_json_error( array( 'message' => 'Taxonomy and category name are required.' ) );
		}

		// Verify taxonomy exists and is attached to our post type.
		$tax_obj = get_taxonomy( $taxonomy );
		if ( ! $tax_obj || ! in_array( self::POST_TYPE, (array) $tax_obj->object_type, true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid taxonomy.' ) );
		}

		// Create the term.
		$insert_args = array();
		if ( $parent_id > 0 && $tax_obj->hierarchical ) {
			$insert_args['parent'] = $parent_id;
		}

		$result = wp_insert_term( $name, $taxonomy, $insert_args );
		if ( is_wp_error( $result ) ) {
			if ( $result->get_error_code() === 'term_exists' ) {
				wp_send_json_error( array( 'message' => 'A category with that name already exists.' ) );
			}
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$term_id = $result['term_id'];
		$term    = get_term( $term_id, $taxonomy );
		$messages = array();
		$page_id  = 0;

		if ( $parent_id === 0 ) {
			// Parent category: create a top-level page with an empty grid row.
			$existing_page = get_posts( array(
				'post_type'   => 'page',
				'post_status' => 'any',
				'name'        => $term->slug,
				'post_parent' => 0,
				'numberposts' => 1,
			) );

			if ( ! empty( $existing_page ) ) {
				$page_id    = $existing_page[0]->ID;
				$messages[] = sprintf( 'Page "%s" already exists (ID %d) — not creating a duplicate.', $term->name, $page_id );
			} else {
				$page_id = wp_insert_post( array(
					'post_title'   => $term->name,
					'post_name'    => $term->slug,
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_parent'  => 0,
					'post_content' => '[row width="full-width"][/row]',
				), true );

				if ( is_wp_error( $page_id ) ) {
					$messages[] = 'Category created but page generation failed: ' . $page_id->get_error_message();
					$page_id    = 0;
				} else {
					$messages[] = sprintf( 'Page "%s" created (ID %d).', $term->name, $page_id );
				}
			}
		} else {
			// Sub-category: create a child page and update the parent page grid.
			$parent_term = get_term( $parent_id, $taxonomy );
			if ( ! $parent_term || is_wp_error( $parent_term ) ) {
				$messages[] = 'Category created but parent term not found — page not generated.';
			} else {
				// Find the parent page.
				$parent_pages = get_posts( array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'name'        => $parent_term->slug,
					'post_parent' => 0,
					'numberposts' => 1,
				) );

				$parent_page_id = 0;
				if ( ! empty( $parent_pages ) ) {
					$parent_page_id = $parent_pages[0]->ID;
				}

				// Create the sub-category page.
				$existing_sub = get_posts( array(
					'post_type'   => 'page',
					'post_status' => 'any',
					'name'        => $term->slug,
					'post_parent' => $parent_page_id,
					'numberposts' => 1,
				) );

				if ( ! empty( $existing_sub ) ) {
					$page_id    = $existing_sub[0]->ID;
					$messages[] = sprintf( 'Sub-page "%s" already exists (ID %d).', $term->name, $page_id );
				} else {
					$page_id = wp_insert_post( array(
						'post_title'   => $term->name,
						'post_name'    => $term->slug,
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_parent'  => $parent_page_id,
						'post_content' => '[row width="full-width"][/row]',
					), true );

					if ( is_wp_error( $page_id ) ) {
						$messages[] = 'Category created but sub-page generation failed: ' . $page_id->get_error_message();
						$page_id    = 0;
					} else {
						$messages[] = sprintf( 'Sub-page "%s" created (ID %d).', $term->name, $page_id );
					}
				}

				// Append a [col] block to the parent page's grid row.
				if ( $parent_page_id ) {
					$parent_content = $parent_pages[0]->post_content;

					if ( preg_match( '/(\[row[^\]]*width="full-width"[^\]]*\])(.*?)(\[\/row\])/s', $parent_content, $row_match ) ) {
						// Extract col template from existing cols.
						$col_attrs = ' span="4" span__sm="12"';
						$ib_attrs  = '';
						preg_match_all( '/\[col[^\]]*\].*?\[\/col\]/s', $row_match[2], $col_matches );
						if ( ! empty( $col_matches[0] ) ) {
							$first_col = $col_matches[0][0];
							if ( preg_match( '/\[col([^\]]*)\]/', $first_col, $ct ) ) {
								$col_attrs = $ct[1];
							}
							if ( preg_match( '/\[ux_image_box([^\]]*)\]/', $first_col, $ib ) ) {
								$ib_attrs = preg_replace( '/\s*\bimg="[^"]*"/', '', $ib[1] );
								$ib_attrs = preg_replace( '/\s*\blink="[^"]*"/', '', $ib_attrs );
							}
						}

						$new_link = '/' . $parent_term->slug . '/' . $term->slug;
						$new_col  = '[col' . $col_attrs . '][ux_image_box' . $ib_attrs . ' img="" link="' . $new_link . '"]' . $term->name . '[/ux_image_box][/col]';

						$new_row     = $row_match[1] . $row_match[2] . $new_col . $row_match[3];
						$new_content = str_replace( $row_match[0], $new_row, $parent_content );

						wp_update_post( array(
							'ID'           => $parent_page_id,
							'post_content' => $new_content,
						) );

						$this->purge_page_cache( $parent_page_id );
						$messages[] = 'Parent page updated with new grid entry.';
					} else {
						$messages[] = 'Parent page found but no grid row detected — grid entry not added.';
					}
				} else {
					$messages[] = 'Parent page not found — grid entry not added. Create the parent category page first.';
				}
			}
		}

		wp_send_json_success( array(
			'message'  => sprintf( 'Category "%s" created successfully.', $name ),
			'details'  => $messages,
			'term_id'  => $term_id,
			'term'     => array(
				'id'     => $term_id,
				'name'   => $term->name,
				'slug'   => $term->slug,
				'parent' => $term->parent,
				'count'  => 0,
			),
			'page_id'  => $page_id,
		) );
	}

	/**
	 * AJAX: Create a new portfolio item.
	 */
	public function ajax_create_item() {
		check_ajax_referer( 'cpo_sort_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$title    = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$image_id = absint( $_POST['image_id'] ?? 0 );
		$taxonomy = sanitize_text_field( wp_unslash( $_POST['taxonomy'] ?? '' ) );
		$term_ids = isset( $_POST['term_ids'] ) ? array_map( 'absint', (array) $_POST['term_ids'] ) : array();
		$term_ids = array_filter( $term_ids );

		if ( empty( $title ) ) {
			wp_send_json_error( array( 'message' => 'Title is required.' ) );
		}

		$post_id = wp_insert_post( array(
			'post_title'  => $title,
			'post_type'   => self::POST_TYPE,
			'post_status' => 'publish',
		), true );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Failed to create item: ' . $post_id->get_error_message() ) );
		}

		// Set featured image.
		if ( $image_id > 0 ) {
			set_post_thumbnail( $post_id, $image_id );
		}

		// Assign taxonomy terms.
		if ( ! empty( $taxonomy ) && ! empty( $term_ids ) ) {
			wp_set_object_terms( $post_id, $term_ids, $taxonomy );

			// Set ordering meta — append to end of each term's list.
			global $wpdb;
			foreach ( $term_ids as $tid ) {
				$meta_key  = '_cpo_order_' . $tid;
				$max_order = $wpdb->get_var( $wpdb->prepare(
					"SELECT MAX(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = %s",
					$meta_key
				) );
				$new_order = ( $max_order !== null ) ? ( (int) $max_order + 1 ) : 0;
				update_post_meta( $post_id, $meta_key, $new_order );
				update_option( 'cpo_ordered_term_' . $tid, true );
			}
		}

		wp_send_json_success( array(
			'message' => sprintf( 'Portfolio item "%s" created successfully.', $title ),
			'post_id' => $post_id,
		) );
	}

	/**
	 * AJAX: Search portfolio items across all categories.
	 */
	public function ajax_search_items() {
		check_ajax_referer( 'cpo_sort_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$search   = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		$taxonomy = sanitize_text_field( wp_unslash( $_POST['taxonomy'] ?? '' ) );
		$term_id  = absint( $_POST['term_id'] ?? 0 );
		$paged    = max( 1, absint( $_POST['paged'] ?? 1 ) );
		$per_page = max( 1, min( 100, absint( $_POST['per_page'] ?? 20 ) ) );

		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( $search !== '' ) {
			$args['s'] = $search;
		}

		if ( ! empty( $taxonomy ) && $term_id > 0 ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => $taxonomy,
					'terms'    => $term_id,
				),
			);
		}

		$query = new WP_Query( $args );
		$items = array();
		$tax_slugs = array_keys( $this->get_taxonomies() );

		foreach ( $query->posts as $post ) {
			$terms_raw = wp_get_object_terms( $post->ID, $tax_slugs );
			$terms     = array();
			if ( ! is_wp_error( $terms_raw ) ) {
				foreach ( $terms_raw as $t ) {
					$terms[] = array(
						'id'       => $t->term_id,
						'name'     => $t->name,
						'taxonomy' => $t->taxonomy,
						'parent'   => $t->parent,
					);
				}
			}

			$items[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'status'    => $post->post_status,
				'thumbnail' => get_the_post_thumbnail_url( $post->ID, 'medium' ) ?: get_the_post_thumbnail_url( $post->ID, 'thumbnail' ),
				'thumb_id'  => (int) get_post_thumbnail_id( $post->ID ),
				'date'      => $post->post_date,
				'terms'     => $terms,
			);
		}

		wp_send_json_success( array(
			'items' => $items,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
			'page'  => $paged,
		) );
	}

	/**
	 * AJAX: Update an existing portfolio item.
	 */
	public function ajax_update_item() {
		check_ajax_referer( 'cpo_sort_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$post_id  = absint( $_POST['post_id'] ?? 0 );

		if ( empty( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Missing post ID.' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== self::POST_TYPE ) {
			wp_send_json_error( array( 'message' => 'Invalid portfolio item.' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied for this post.' ) );
		}

		// Update title.
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( $title !== '' ) {
			wp_update_post( array(
				'ID'         => $post_id,
				'post_title' => $title,
			) );
		}

		// Update featured image.
		if ( isset( $_POST['image_id'] ) ) {
			$image_id = absint( $_POST['image_id'] );
			if ( $image_id > 0 ) {
				set_post_thumbnail( $post_id, $image_id );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}

		// Update taxonomy terms.
		$taxonomy = sanitize_text_field( wp_unslash( $_POST['taxonomy'] ?? '' ) );
		if ( ! empty( $taxonomy ) && isset( $_POST['term_ids'] ) ) {
			$term_ids = array_map( 'absint', (array) $_POST['term_ids'] );
			$term_ids = array_filter( $term_ids );

			// Get old terms to detect changes.
			$old_terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $old_terms ) ) {
				$old_terms = array();
			}

			wp_set_object_terms( $post_id, $term_ids, $taxonomy );

			// Set ordering meta for newly assigned terms.
			global $wpdb;
			$new_terms = array_diff( $term_ids, $old_terms );
			foreach ( $new_terms as $tid ) {
				$meta_key  = '_cpo_order_' . $tid;
				$max_order = $wpdb->get_var( $wpdb->prepare(
					"SELECT MAX(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = %s",
					$meta_key
				) );
				$new_order = ( $max_order !== null ) ? ( (int) $max_order + 1 ) : 0;
				update_post_meta( $post_id, $meta_key, $new_order );
				update_option( 'cpo_ordered_term_' . $tid, true );
			}
		}

		// Return updated item data.
		$post      = get_post( $post_id );
		$tax_slugs = array_keys( $this->get_taxonomies() );
		$terms_raw = wp_get_object_terms( $post_id, $tax_slugs );
		$terms     = array();
		if ( ! is_wp_error( $terms_raw ) ) {
			foreach ( $terms_raw as $t ) {
				$terms[] = array(
					'id'       => $t->term_id,
					'name'     => $t->name,
					'taxonomy' => $t->taxonomy,
					'parent'   => $t->parent,
				);
			}
		}

		wp_send_json_success( array(
			'message' => sprintf( 'Item "%s" updated successfully.', $post->post_title ),
			'item'    => array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'status'    => $post->post_status,
				'thumbnail' => get_the_post_thumbnail_url( $post->ID, 'medium' ) ?: get_the_post_thumbnail_url( $post->ID, 'thumbnail' ),
				'thumb_id'  => (int) get_post_thumbnail_id( $post->ID ),
				'date'      => $post->post_date,
				'terms'     => $terms,
			),
		) );
	}

	/**
	 * Render the Manage Items admin page.
	 */
	public function render_manage_page() {
		$taxonomies = $this->get_taxonomies();
		?>
		<div class="wrap cpo-wrap">
			<h1><?php esc_html_e( 'Manage Portfolio', 'custom-portfolio-ordering' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Create new categories and portfolio items. Pages are auto-generated when categories are created.', 'custom-portfolio-ordering' ); ?></p>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=custom-portfolio-ordering' ) ); ?>" class="nav-tab"><?php esc_html_e( 'Ordering', 'custom-portfolio-ordering' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cpo-import' ) ); ?>" class="nav-tab"><?php esc_html_e( 'Import Items', 'custom-portfolio-ordering' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cpo-manage' ) ); ?>" class="nav-tab nav-tab-active"><?php esc_html_e( 'Manage Items', 'custom-portfolio-ordering' ); ?></a>
			</nav>

			<!-- Create Category Section -->
			<div class="cpo-manage-section">
				<h2><?php esc_html_e( 'Create Category', 'custom-portfolio-ordering' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Create a parent or sub-category. A WordPress page will be auto-generated for it.', 'custom-portfolio-ordering' ); ?></p>
				<table class="form-table cpo-manage-form-table">
					<tr>
						<th><label for="cpo-manage-cat-taxonomy"><?php esc_html_e( 'Taxonomy', 'custom-portfolio-ordering' ); ?></label></th>
						<td>
							<select id="cpo-manage-cat-taxonomy">
								<?php foreach ( $taxonomies as $slug => $tax ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $tax->labels->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="cpo-manage-cat-parent"><?php esc_html_e( 'Parent Category', 'custom-portfolio-ordering' ); ?></label></th>
						<td>
							<select id="cpo-manage-cat-parent">
								<option value="0"><?php esc_html_e( '— None (top-level) —', 'custom-portfolio-ordering' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Leave as "None" to create a parent category, or select a parent to create a sub-category.', 'custom-portfolio-ordering' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="cpo-manage-cat-name"><?php esc_html_e( 'Category Name', 'custom-portfolio-ordering' ); ?></label></th>
						<td><input type="text" id="cpo-manage-cat-name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Wedding, Venue', 'custom-portfolio-ordering' ); ?>"></td>
					</tr>
				</table>
				<p>
					<button type="button" id="cpo-create-cat-btn" class="button button-primary"><?php esc_html_e( 'Create Category', 'custom-portfolio-ordering' ); ?></button>
					<span id="cpo-create-cat-spinner" class="spinner" style="float:none;vertical-align:middle;"></span>
				</p>
				<div id="cpo-create-cat-result"></div>
			</div>

			<!-- Create Portfolio Item Section -->
			<div class="cpo-manage-section">
				<h2><?php esc_html_e( 'Create Portfolio Item', 'custom-portfolio-ordering' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Create a new portfolio item with a featured image and category assignment.', 'custom-portfolio-ordering' ); ?></p>
				<table class="form-table cpo-manage-form-table">
					<tr>
						<th><label for="cpo-manage-item-title"><?php esc_html_e( 'Title', 'custom-portfolio-ordering' ); ?></label></th>
						<td><input type="text" id="cpo-manage-item-title" class="regular-text" placeholder="<?php esc_attr_e( 'Portfolio item title', 'custom-portfolio-ordering' ); ?>"></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Featured Image', 'custom-portfolio-ordering' ); ?></th>
						<td>
							<div id="cpo-manage-img-preview" class="cpo-manage-img-preview"></div>
							<input type="hidden" id="cpo-manage-img-id" value="">
							<button type="button" class="button" id="cpo-manage-select-img">
								<span class="dashicons dashicons-format-image" style="vertical-align:middle;margin-right:4px;"></span><?php esc_html_e( 'Select Image', 'custom-portfolio-ordering' ); ?>
							</button>
							<button type="button" class="button" id="cpo-manage-remove-img" style="display:none;">
								<span class="dashicons dashicons-no" style="vertical-align:middle;margin-right:2px;"></span><?php esc_html_e( 'Remove', 'custom-portfolio-ordering' ); ?>
							</button>
						</td>
					</tr>
					<tr>
						<th><label for="cpo-manage-item-taxonomy"><?php esc_html_e( 'Taxonomy', 'custom-portfolio-ordering' ); ?></label></th>
						<td>
							<select id="cpo-manage-item-taxonomy">
								<?php foreach ( $taxonomies as $slug => $tax ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $tax->labels->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Categories', 'custom-portfolio-ordering' ); ?></th>
						<td>
							<div id="cpo-create-cat-tree"></div>
						</td>
					</tr>
				</table>
				<p>
					<button type="button" id="cpo-create-item-btn" class="button button-primary"><?php esc_html_e( 'Create Item', 'custom-portfolio-ordering' ); ?></button>
					<span id="cpo-create-item-spinner" class="spinner" style="float:none;vertical-align:middle;"></span>
				</p>
				<div id="cpo-create-item-result"></div>
			</div>

			<!-- Edit Portfolio Items Section -->
			<div class="cpo-manage-section cpo-manage-section-wide">
				<h2><?php esc_html_e( 'Edit Portfolio Items', 'custom-portfolio-ordering' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Search and edit existing portfolio items.', 'custom-portfolio-ordering' ); ?></p>

				<div class="cpo-edit-search-bar">
					<input type="text" id="cpo-edit-search" placeholder="<?php esc_attr_e( 'Search by title...', 'custom-portfolio-ordering' ); ?>">
					<select id="cpo-edit-tax-filter">
						<?php foreach ( $taxonomies as $slug => $tax ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $tax->labels->name ); ?></option>
						<?php endforeach; ?>
					</select>
					<select id="cpo-edit-cat-filter">
						<option value="0"><?php esc_html_e( '— All categories —', 'custom-portfolio-ordering' ); ?></option>
					</select>
					<button type="button" class="button" id="cpo-edit-search-btn">
						<span class="dashicons dashicons-search" style="vertical-align:middle;margin-right:2px;"></span><?php esc_html_e( 'Search', 'custom-portfolio-ordering' ); ?>
					</button>
					<span id="cpo-edit-search-spinner" class="spinner" style="float:none;vertical-align:middle;"></span>
				</div>

				<div id="cpo-edit-results">
					<p class="cpo-placeholder"><?php esc_html_e( 'Use the search bar above to find items, or click Search to list all.', 'custom-portfolio-ordering' ); ?></p>
				</div>

				<div id="cpo-edit-pagination" class="cpo-edit-pagination" style="display:none;">
					<button type="button" class="button" id="cpo-edit-prev">&laquo; <?php esc_html_e( 'Previous', 'custom-portfolio-ordering' ); ?></button>
					<span id="cpo-edit-page-info"></span>
					<button type="button" class="button" id="cpo-edit-next"><?php esc_html_e( 'Next', 'custom-portfolio-ordering' ); ?> &raquo;</button>
				</div>
			</div>
		</div>

		<script>
		(function($) {
			var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'cpo_sort_nonce' ) ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			// Embed term data so dropdowns can be populated client-side.
			var cpoManageTerms = {};
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
					foreach ( $terms as $t ) {
						$term_data[] = array(
							'id'     => $t->term_id,
							'name'   => $t->name,
							'parent' => $t->parent,
						);
					}
				}
				echo 'cpoManageTerms[' . wp_json_encode( $slug ) . '] = ' . wp_json_encode( $term_data ) . ";\n";
			}
			?>

			function getParentTerms(tax) {
				if (!cpoManageTerms[tax]) return [];
				return cpoManageTerms[tax].filter(function(t) { return t.parent === 0; });
			}

			function getChildTerms(tax, parentId) {
				if (!cpoManageTerms[tax]) return [];
				var pid = parseInt(parentId, 10);
				return cpoManageTerms[tax].filter(function(t) { return t.parent === pid; });
			}

			function populateParentDropdown($select, tax, emptyLabel) {
				$select.empty().append('<option value="0">' + emptyLabel + '</option>');
				getParentTerms(tax).forEach(function(t) {
					$select.append('<option value="' + t.id + '">' + t.name + '</option>');
				});
			}

			function populateChildDropdown($select, tax, parentId) {
				$select.empty();
				if (!parentId || parentId === '0') {
					$select.append('<option value="0">&mdash; Select parent first &mdash;</option>');
					return;
				}
				$select.append('<option value="0">&mdash; Select &mdash;</option>');
				getChildTerms(tax, parentId).forEach(function(t) {
					$select.append('<option value="' + t.id + '">' + t.name + '</option>');
				});
			}

			// --- Category form dropdowns ---
			function refreshCatParents() {
				populateParentDropdown($('#cpo-manage-cat-parent'), $('#cpo-manage-cat-taxonomy').val(), '— None (top-level) —');
			}
			$('#cpo-manage-cat-taxonomy').on('change', refreshCatParents);
			refreshCatParents();

			// --- Item form category checkbox tree ---
			function refreshCreateCatTree() {
				var tax = $('#cpo-manage-item-taxonomy').val();
				$('#cpo-create-cat-tree').html(buildCategoryCheckboxes(tax, []));
			}
			$('#cpo-manage-item-taxonomy').on('change', refreshCreateCatTree);
			refreshCreateCatTree();

			// --- Image picker ---
			$('#cpo-manage-select-img').on('click', function(e) {
				e.preventDefault();
				var frame = wp.media({
					title: 'Select Featured Image',
					button: { text: 'Use this image' },
					multiple: false,
					library: { type: 'image' }
				});
				frame.on('select', function() {
					var attachment = frame.state().get('selection').first().toJSON();
					var url = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
					$('#cpo-manage-img-id').val(attachment.id);
					$('#cpo-manage-img-preview').html('<img src="' + url + '" alt="">');
					$('#cpo-manage-remove-img').show();
				});
				frame.open();
			});

			$('#cpo-manage-remove-img').on('click', function() {
				$('#cpo-manage-img-id').val('');
				$('#cpo-manage-img-preview').empty();
				$(this).hide();
			});

			// --- Create Category ---
			$('#cpo-create-cat-btn').on('click', function() {
				var $btn     = $(this);
				var $spinner = $('#cpo-create-cat-spinner');
				var $result  = $('#cpo-create-cat-result');
				var tax      = $('#cpo-manage-cat-taxonomy').val();
				var parentId = $('#cpo-manage-cat-parent').val();
				var name     = $('#cpo-manage-cat-name').val().trim();

				if (!name) {
					$result.html('<p class="cpo-manage-error">Please enter a category name.</p>');
					return;
				}

				$btn.prop('disabled', true);
				$spinner.addClass('is-active');
				$result.html('');

				$.post(ajaxUrl, {
					action:    'cpo_create_category',
					nonce:     nonce,
					taxonomy:  tax,
					parent_id: parentId,
					name:      name
				}, function(response) {
					$btn.prop('disabled', false);
					$spinner.removeClass('is-active');

					if (response.success) {
						var html = '<p class="cpo-manage-success">' + response.data.message + '</p>';
						if (response.data.details && response.data.details.length) {
							html += '<ul class="cpo-manage-details">';
							response.data.details.forEach(function(d) { html += '<li>' + d + '</li>'; });
							html += '</ul>';
						}
						$result.html(html);

						// Add new term to local data so dropdowns update immediately.
						if (response.data.term && cpoManageTerms[tax]) {
							cpoManageTerms[tax].push(response.data.term);
							refreshCatParents();
							refreshCreateCatTree();
						}

						$('#cpo-manage-cat-name').val('');
					} else {
						$result.html('<p class="cpo-manage-error">' + (response.data.message || 'Failed.') + '</p>');
					}
				}).fail(function() {
					$btn.prop('disabled', false);
					$spinner.removeClass('is-active');
					$result.html('<p class="cpo-manage-error">Request failed. Please try again.</p>');
				});
			});

			// --- Create Item ---
			$('#cpo-create-item-btn').on('click', function() {
				var $btn     = $(this);
				var $spinner = $('#cpo-create-item-spinner');
				var $result  = $('#cpo-create-item-result');
				var title    = $('#cpo-manage-item-title').val().trim();
				var imageId  = $('#cpo-manage-img-id').val();
				var tax      = $('#cpo-manage-item-taxonomy').val();

				// Collect all checked categories.
				var termIds = [];
				$('#cpo-create-cat-tree input:checked').each(function() {
					var val = parseInt($(this).val(), 10);
					if (val && termIds.indexOf(val) === -1) termIds.push(val);
				});

				if (!title) {
					$result.html('<p class="cpo-manage-error">Please enter a title.</p>');
					return;
				}

				$btn.prop('disabled', true);
				$spinner.addClass('is-active');
				$result.html('');

				$.post(ajaxUrl, {
					action:   'cpo_create_item',
					nonce:    nonce,
					title:    title,
					image_id: imageId || 0,
					taxonomy: tax,
					term_ids: termIds
				}, function(response) {
					$btn.prop('disabled', false);
					$spinner.removeClass('is-active');

					if (response.success) {
						$result.html('<p class="cpo-manage-success">' + response.data.message + '</p>');
						// Reset form.
						$('#cpo-manage-item-title').val('');
						$('#cpo-manage-img-id').val('');
						$('#cpo-manage-img-preview').empty();
						$('#cpo-manage-remove-img').hide();
						$('#cpo-create-cat-tree input:checked').prop('checked', false);
					} else {
						$result.html('<p class="cpo-manage-error">' + (response.data.message || 'Failed.') + '</p>');
					}
				}).fail(function() {
					$btn.prop('disabled', false);
					$spinner.removeClass('is-active');
					$result.html('<p class="cpo-manage-error">Request failed. Please try again.</p>');
				});
			});
			// ── Edit Portfolio Items ──────────────────────────────────────────────

			var editItemsCache = {};
			var editCurrentPage = 1;
			var editTotalPages  = 1;

			function escHtml(str) {
				return $('<div>').text(String(str)).html();
			}

			// Populate the category filter dropdown (all terms, indented).
			function refreshEditCatFilter() {
				var tax = $('#cpo-edit-tax-filter').val();
				var $sel = $('#cpo-edit-cat-filter');
				$sel.empty().append('<option value="0">&mdash; All categories &mdash;</option>');
				if (!cpoManageTerms[tax]) return;

				// Build flat indented list.
				var terms = cpoManageTerms[tax];
				var parents = terms.filter(function(t) { return t.parent === 0; });
				parents.forEach(function(p) {
					$sel.append('<option value="' + p.id + '">' + p.name + '</option>');
					terms.filter(function(c) { return c.parent === p.id; }).forEach(function(c) {
						$sel.append('<option value="' + c.id + '">\u00A0\u00A0\u00A0' + c.name + '</option>');
					});
				});
			}

			$('#cpo-edit-tax-filter').on('change', refreshEditCatFilter);
			refreshEditCatFilter();

			// Auto-select first category and load items on page load.
			if ($('#cpo-edit-cat-filter option').length > 1) {
				$('#cpo-edit-cat-filter').val($('#cpo-edit-cat-filter option').eq(1).val());
			}
			cpoEditSearch(1);

			// Format terms for display: "Parent > Child".
			function formatTerms(terms) {
				if (!terms || !terms.length) return '<em>None</em>';
				var parentMap = {};
				var children  = [];
				terms.forEach(function(t) {
					if (t.parent === 0) {
						parentMap[t.id] = t.name;
					} else {
						children.push(t);
					}
				});
				// Pair children with parents.
				var parts = [];
				children.forEach(function(c) {
					var pName = parentMap[c.parent] || '';
					parts.push(pName ? (escHtml(pName) + ' &rsaquo; ' + escHtml(c.name)) : escHtml(c.name));
				});
				// Show orphan parents (no children matched).
				terms.forEach(function(t) {
					if (t.parent === 0 && !children.some(function(c) { return c.parent === t.id; })) {
						parts.push(escHtml(t.name));
					}
				});
				return parts.join(', ') || '<em>None</em>';
			}

			function cpoEditSearch(page) {
				var search  = $('#cpo-edit-search').val().trim();
				var tax     = $('#cpo-edit-tax-filter').val();
				var termId  = $('#cpo-edit-cat-filter').val();
				var $spin   = $('#cpo-edit-search-spinner');

				$spin.addClass('is-active');
				$('#cpo-edit-results').html('<p class="cpo-placeholder">Loading&hellip;</p>');
				$('#cpo-edit-pagination').hide();

				$.post(ajaxUrl, {
					action:   'cpo_search_items',
					nonce:    nonce,
					search:   search,
					taxonomy: tax,
					term_id:  termId || 0,
					paged:    page || 1
				}, function(response) {
					$spin.removeClass('is-active');
					if (!response.success) {
						$('#cpo-edit-results').html('<p class="cpo-manage-error">' + (response.data.message || 'Search failed.') + '</p>');
						return;
					}
					editCurrentPage = response.data.page;
					editTotalPages  = response.data.pages;
					renderEditTable(response.data);
				}).fail(function() {
					$spin.removeClass('is-active');
					$('#cpo-edit-results').html('<p class="cpo-manage-error">Request failed. Please try again.</p>');
				});
			}

			function renderEditTable(data) {
				if (!data.items.length) {
					$('#cpo-edit-results').html('<p class="cpo-placeholder">No items found.</p>');
					$('#cpo-edit-pagination').hide();
					return;
				}

				// Cache items for edit forms.
				editItemsCache = {};
				data.items.forEach(function(item) {
					editItemsCache[item.id] = item;
				});

				var html = '<div class="cpo-list-header">';
				html += '<span class="cpo-col-thumb"></span>';
				html += '<span class="cpo-col-title">Title</span>';
				html += '<span class="cpo-col-categories">Categories</span>';
				html += '<span class="cpo-col-status">Status</span>';
				html += '<span class="cpo-col-actions-wide">Actions</span>';
				html += '</div>';
				html += '<ul class="cpo-edit-list">';

				data.items.forEach(function(item) {
					var thumb = item.thumbnail
						? '<img src="' + item.thumbnail + '" alt="">'
						: '<span class="cpo-no-thumb dashicons dashicons-format-image"></span>';

					html += '<li class="cpo-item cpo-edit-item" data-id="' + item.id + '">';
					html += '<span class="cpo-col-thumb">' + thumb + '</span>';
					html += '<span class="cpo-col-title">' + escHtml(item.title) + '</span>';
					html += '<span class="cpo-col-categories">' + formatTerms(item.terms) + '</span>';
					html += '<span class="cpo-col-status"><span class="cpo-status-badge cpo-status-' + item.status + '">' + item.status + '</span></span>';
					html += '<span class="cpo-col-actions-wide">';
					html += '<button type="button" class="cpo-edit-btn" data-id="' + item.id + '" title="Edit"><span class="dashicons dashicons-edit"></span></button>';
					html += '<button type="button" class="cpo-delete-btn cpo-edit-delete-btn" data-id="' + item.id + '" title="Move to trash"><span class="dashicons dashicons-trash"></span></button>';
					html += '</span>';
					html += '</li>';
				});

				html += '</ul>';
				$('#cpo-edit-results').html(html);

				// Pagination.
				if (editTotalPages > 1) {
					$('#cpo-edit-page-info').text('Page ' + editCurrentPage + ' of ' + editTotalPages + ' (' + data.total + ' items)');
					$('#cpo-edit-prev').prop('disabled', editCurrentPage <= 1);
					$('#cpo-edit-next').prop('disabled', editCurrentPage >= editTotalPages);
					$('#cpo-edit-pagination').show();
				} else {
					$('#cpo-edit-pagination').hide();
				}
			}

			function buildCategoryCheckboxes(tax, checkedIds) {
				var html = '<div class="cpo-edit-cat-tree">';
				var parents = getParentTerms(tax);
				if (!parents.length) {
					html += '<em>No categories found.</em>';
					html += '</div>';
					return html;
				}
				parents.forEach(function(p) {
					var pChecked = checkedIds.indexOf(p.id) !== -1 ? ' checked' : '';
					html += '<div class="cpo-edit-cat-group">';
					html += '<label class="cpo-edit-cat-parent-label"><input type="checkbox" value="' + p.id + '"' + pChecked + '> <strong>' + escHtml(p.name) + '</strong></label>';
					var children = getChildTerms(tax, p.id);
					if (children.length) {
						html += '<div class="cpo-edit-cat-children">';
						children.forEach(function(c) {
							var cChecked = checkedIds.indexOf(c.id) !== -1 ? ' checked' : '';
							html += '<label><input type="checkbox" value="' + c.id + '"' + cChecked + '> ' + escHtml(c.name) + '</label>';
						});
						html += '</div>';
					}
					html += '</div>';
				});
				html += '</div>';
				return html;
			}

			function openEditForm(postId) {
				// Close any existing edit form.
				$('.cpo-edit-form-row').remove();
				$('.cpo-edit-item').removeClass('cpo-edit-item-active');

				var item = editItemsCache[postId];
				if (!item) return;

				var $row = $('.cpo-edit-item[data-id="' + postId + '"]');
				$row.addClass('cpo-edit-item-active');

				var tax = $('#cpo-edit-tax-filter').val();

				// Build set of currently assigned term IDs.
				var checkedIds = [];
				if (item.terms && item.terms.length) {
					item.terms.forEach(function(t) { checkedIds.push(t.id); });
				}

				var imgPreview = '';
				if (item.thumbnail) {
					imgPreview = '<img src="' + item.thumbnail + '" alt="">';
				}

				var formHtml = '<li class="cpo-edit-form-row" data-id="' + postId + '">';
				formHtml += '<div class="cpo-edit-inline">';
				formHtml += '<table class="form-table cpo-manage-form-table">';
				formHtml += '<tr><th>Title</th><td><input type="text" class="cpo-edit-field-title regular-text" value="' + escHtml(item.title) + '"></td></tr>';
				formHtml += '<tr><th>Featured Image</th><td>';
				formHtml += '<div class="cpo-manage-img-preview cpo-edit-img-preview">' + imgPreview + '</div>';
				formHtml += '<input type="hidden" class="cpo-edit-field-img-id" value="' + (item.thumb_id || '') + '">';
				formHtml += '<button type="button" class="button cpo-edit-select-img"><span class="dashicons dashicons-format-image" style="vertical-align:middle;margin-right:4px;"></span>Select Image</button> ';
				formHtml += '<button type="button" class="button cpo-edit-remove-img"' + (!item.thumb_id ? ' style="display:none;"' : '') + '><span class="dashicons dashicons-no" style="vertical-align:middle;margin-right:2px;"></span>Remove</button>';
				formHtml += '</td></tr>';
				formHtml += '<tr><th>Categories</th><td>' + buildCategoryCheckboxes(tax, checkedIds) + '</td></tr>';
				formHtml += '</table>';
				formHtml += '<div class="cpo-edit-actions">';
				formHtml += '<button type="button" class="button button-primary cpo-edit-save-btn" data-id="' + postId + '">Save Changes</button>';
				formHtml += '<button type="button" class="button cpo-edit-cancel-btn">Cancel</button>';
				formHtml += '<span class="cpo-edit-save-spinner spinner" style="float:none;vertical-align:middle;"></span>';
				formHtml += '</div>';
				formHtml += '<div class="cpo-edit-form-result"></div>';
				formHtml += '</div>';
				formHtml += '</li>';

				$row.after(formHtml);
			}

			function saveEditItem(postId) {
				var $formRow = $('.cpo-edit-form-row[data-id="' + postId + '"]');
				var $spinner = $formRow.find('.cpo-edit-save-spinner');
				var $result  = $formRow.find('.cpo-edit-form-result');
				var $saveBtn = $formRow.find('.cpo-edit-save-btn');

				var title    = $formRow.find('.cpo-edit-field-title').val().trim();
				var imageId  = $formRow.find('.cpo-edit-field-img-id').val();
				var tax      = $('#cpo-edit-tax-filter').val();

				if (!title) {
					$result.html('<p class="cpo-manage-error">Title cannot be empty.</p>');
					return;
				}

				// Collect all checked category checkboxes.
				var termIds = [];
				$formRow.find('.cpo-edit-cat-tree input:checked').each(function() {
					var val = parseInt($(this).val(), 10);
					if (val && termIds.indexOf(val) === -1) termIds.push(val);
				});

				$saveBtn.prop('disabled', true);
				$spinner.addClass('is-active');
				$result.html('');

				$.post(ajaxUrl, {
					action:   'cpo_update_item',
					nonce:    nonce,
					post_id:  postId,
					title:    title,
					image_id: imageId || 0,
					taxonomy: tax,
					term_ids: termIds
				}, function(response) {
					$saveBtn.prop('disabled', false);
					$spinner.removeClass('is-active');

					if (response.success) {
						// Update cache and table row.
						var updated = response.data.item;
						editItemsCache[postId] = updated;

						var $row = $('.cpo-edit-item[data-id="' + postId + '"]');
						var thumb = updated.thumbnail
							? '<img src="' + updated.thumbnail + '" alt="">'
							: '<span class="cpo-no-thumb dashicons dashicons-format-image"></span>';
						$row.find('.cpo-col-thumb').html(thumb);
						$row.find('.cpo-col-title').text(updated.title);
						$row.find('.cpo-col-categories').html(formatTerms(updated.terms));

						$result.html('<p class="cpo-manage-success">' + escHtml(response.data.message) + '</p>');
						setTimeout(function() {
							$('.cpo-edit-form-row').remove();
							$('.cpo-edit-item').removeClass('cpo-edit-item-active');
						}, 1000);
					} else {
						$result.html('<p class="cpo-manage-error">' + escHtml(response.data.message || 'Update failed.') + '</p>');
					}
				}).fail(function() {
					$saveBtn.prop('disabled', false);
					$spinner.removeClass('is-active');
					$result.html('<p class="cpo-manage-error">Request failed. Please try again.</p>');
				});
			}

			// Event bindings (delegated).
			$('#cpo-edit-search-btn').on('click', function() { cpoEditSearch(1); });
			$('#cpo-edit-search').on('keypress', function(e) { if (e.which === 13) { e.preventDefault(); cpoEditSearch(1); } });
			$('#cpo-edit-prev').on('click', function() { if (editCurrentPage > 1) cpoEditSearch(editCurrentPage - 1); });
			$('#cpo-edit-next').on('click', function() { if (editCurrentPage < editTotalPages) cpoEditSearch(editCurrentPage + 1); });

			// Edit button.
			$('#cpo-edit-results').on('click', '.cpo-edit-btn', function() {
				openEditForm($(this).data('id'));
			});

			// Cancel button.
			$('#cpo-edit-results').on('click', '.cpo-edit-cancel-btn', function() {
				$('.cpo-edit-form-row').remove();
				$('.cpo-edit-item').removeClass('cpo-edit-item-active');
			});

			// Save button.
			$('#cpo-edit-results').on('click', '.cpo-edit-save-btn', function() {
				saveEditItem($(this).data('id'));
			});

			// Image picker within edit form.
			$('#cpo-edit-results').on('click', '.cpo-edit-select-img', function() {
				var $formRow = $(this).closest('.cpo-edit-form-row');
				var frame = wp.media({
					title: 'Select Featured Image',
					button: { text: 'Use this image' },
					multiple: false,
					library: { type: 'image' }
				});
				frame.on('select', function() {
					var attachment = frame.state().get('selection').first().toJSON();
					var url = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
					$formRow.find('.cpo-edit-field-img-id').val(attachment.id);
					$formRow.find('.cpo-edit-img-preview').html('<img src="' + url + '" alt="">');
					$formRow.find('.cpo-edit-remove-img').show();
				});
				frame.open();
			});

			// Remove image within edit form.
			$('#cpo-edit-results').on('click', '.cpo-edit-remove-img', function() {
				var $formRow = $(this).closest('.cpo-edit-form-row');
				$formRow.find('.cpo-edit-field-img-id').val('0');
				$formRow.find('.cpo-edit-img-preview').empty();
				$(this).hide();
			});

			// Delete from edit table.
			$('#cpo-edit-results').on('click', '.cpo-edit-delete-btn', function() {
				var postId = $(this).data('id');
				var item   = editItemsCache[postId];
				if (!confirm('Move "' + (item ? item.title : '#' + postId) + '" to trash?')) return;

				$.post(ajaxUrl, {
					action:  'cpo_delete_item',
					nonce:   nonce,
					post_id: postId
				}, function(response) {
					if (response.success) {
						$('.cpo-edit-form-row[data-id="' + postId + '"]').remove();
						$('.cpo-edit-item[data-id="' + postId + '"]').fadeOut(300, function() { $(this).remove(); });
						delete editItemsCache[postId];
					} else {
						alert(response.data.message || 'Delete failed.');
					}
				});
			});

		}(jQuery));
		</script>
		<?php
	}
}
