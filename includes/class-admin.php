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
		add_action( 'wp_ajax_cpo_import', array( $this, 'ajax_import' ) );
		add_action( 'wp_ajax_cpo_delete_item', array( $this, 'ajax_delete_item' ) );
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

		add_submenu_page(
			'custom-portfolio-ordering',
			__( 'Import Portfolio Items', 'custom-portfolio-ordering' ),
			__( 'Import Items', 'custom-portfolio-ordering' ),
			'edit_posts',
			'cpo-import',
			array( $this, 'render_import_page' )
		);
	}

	/**
	 * Render the Import Items admin page.
	 */
	public function render_import_page() {
		wp_enqueue_style(
			'cpo-admin-style',
			CPO_PLUGIN_URL . 'assets/css/admin-style.css',
			array(),
			CPO_VERSION
		);

		$taxonomies  = $this->get_taxonomies();
		$example_tax = ! empty( $taxonomies ) ? array_key_first( $taxonomies ) : 'your_taxonomy';
		?>
		<div class="wrap cpo-wrap">
			<h1><?php esc_html_e( 'Import Portfolio Items', 'custom-portfolio-ordering' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Upload a CSV file to create or update portfolio items. Items are matched by title — existing items will be updated, new ones will be created.', 'custom-portfolio-ordering' ); ?></p>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=custom-portfolio-ordering' ) ); ?>" class="nav-tab"><?php esc_html_e( 'Ordering', 'custom-portfolio-ordering' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cpo-import' ) ); ?>" class="nav-tab nav-tab-active"><?php esc_html_e( 'Import Items', 'custom-portfolio-ordering' ); ?></a>
			</nav>

			<div class="cpo-import-page">
				<div class="cpo-import-format">
					<strong><?php esc_html_e( 'Required column:', 'custom-portfolio-ordering' ); ?></strong> <code>title</code><br>
					<strong><?php esc_html_e( 'Optional columns:', 'custom-portfolio-ordering' ); ?></strong>
					<code>status</code>, <code>content</code>,
					<code><?php echo esc_html( $example_tax ); ?></code>
					<em><?php esc_html_e( '(any registered taxonomy slug)', 'custom-portfolio-ordering' ); ?></em>
					<pre class="cpo-import-example">title,status,<?php echo esc_html( $example_tax ); ?>
1,publish,my-category
2,draft,another-category
3,publish,</pre>
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

				<div id="cpo-import-results" class="cpo-import-results" style="display:none;"></div>
			</div>
		</div>

		<script>
		(function ($) {
			var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'cpo_sort_nonce' ) ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			function escHtml( str ) {
				return $( '<div>' ).text( String( str ) ).html();
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

				var formData = new FormData();
				formData.append( 'action', 'cpo_import' );
				formData.append( 'nonce', nonce );
				formData.append( 'csv_file', fileInput.files[0] );

				$( '#cpo-import-submit' ).prop( 'disabled', true );
				$( '#cpo-import-spinner' ).addClass( 'is-active' );
				$( '#cpo-import-results' ).hide();

				$.ajax( {
					url: ajaxUrl,
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					success: function ( response ) {
						$( '#cpo-import-spinner' ).removeClass( 'is-active' );

						if ( ! response.success ) {
							var msg = ( response.data && response.data.message ) ? response.data.message : 'Import failed.';
							$( '#cpo-import-results' ).html( '<p class="cpo-import-error">' + escHtml( msg ) + '</p>' ).show();
							$( '#cpo-import-submit' ).prop( 'disabled', false );
							return;
						}

						var d    = response.data;
						var html = '<p class="cpo-import-success">Import complete!</p>';
						html += '<ul class="cpo-import-summary">';
						html += '<li><span class="dashicons dashicons-yes-alt"></span> ' + d.created + ' item' + ( d.created !== 1 ? 's' : '' ) + ' created</li>';
						html += '<li><span class="dashicons dashicons-update-alt"></span> ' + d.updated + ' item' + ( d.updated !== 1 ? 's' : '' ) + ' updated</li>';
						if ( d.skipped ) {
							html += '<li><span class="dashicons dashicons-minus"></span> ' + d.skipped + ' row' + ( d.skipped !== 1 ? 's' : '' ) + ' skipped</li>';
						}
						html += '</ul>';

						if ( d.errors && d.errors.length ) {
							html += '<div class="cpo-import-errors"><strong>Errors:</strong><ul>';
							$.each( d.errors, function ( i, err ) {
								html += '<li>' + escHtml( err ) + '</li>';
							} );
							html += '</ul></div>';
						}

						$( '#cpo-import-results' ).html( html ).show();
						// Reset for another import.
						$( '#cpo-import-submit' ).prop( 'disabled', true );
						$( '#cpo-csv-file' ).val( '' );
						$( '#cpo-file-name' ).text( 'No file chosen' );
					},
					error: function () {
						$( '#cpo-import-spinner' ).removeClass( 'is-active' );
						$( '#cpo-import-results' ).html( '<p class="cpo-import-error">A server error occurred. Please try again.</p>' ).show();
						$( '#cpo-import-submit' ).prop( 'disabled', false );
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

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=custom-portfolio-ordering' ) ); ?>" class="nav-tab nav-tab-active"><?php esc_html_e( 'Ordering', 'custom-portfolio-ordering' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cpo-import' ) ); ?>" class="nav-tab"><?php esc_html_e( 'Import Items', 'custom-portfolio-ordering' ); ?></a>
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
	 * AJAX: Import portfolio items from a CSV file.
	 *
	 * Matches rows to existing posts by title. Existing posts are updated;
	 * unmatched rows create new posts. Any column whose name matches a
	 * registered taxonomy is treated as a term slug assignment for that taxonomy.
	 *
	 * Required CSV column : title
	 * Optional CSV columns: status, content, <taxonomy_slug>
	 */
	public function ajax_import() {
		check_ajax_referer( 'cpo_sort_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		if ( empty( $_FILES['csv_file'] ) || (int) $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
			wp_send_json_error( array( 'message' => 'No valid file uploaded.' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$tmp_path = $_FILES['csv_file']['tmp_name'];
		$handle   = fopen( $tmp_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			wp_send_json_error( array( 'message' => 'Could not read the uploaded file.' ) );
		}

		$headers = fgetcsv( $handle );
		if ( ! $headers ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_send_json_error( array( 'message' => 'The CSV file appears to be empty.' ) );
		}
		$headers = array_map( 'trim', $headers );

		if ( ! in_array( 'title', $headers, true ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_send_json_error( array( 'message' => 'CSV must include a "title" column.' ) );
		}

		global $wpdb;

		$created = 0;
		$updated = 0;
		$skipped = 0;
		$errors  = array();

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			// Skip blank rows.
			if ( count( $row ) === 1 && trim( $row[0] ) === '' ) {
				continue;
			}

			// Pad short rows to match header count.
			while ( count( $row ) < count( $headers ) ) {
				$row[] = '';
			}

			$data  = array_combine( $headers, array_slice( $row, 0, count( $headers ) ) );
			$title = sanitize_text_field( trim( $data['title'] ) );

			if ( $title === '' ) {
				$skipped++;
				continue;
			}

			$status  = isset( $data['status'] ) && $data['status'] !== '' ? sanitize_text_field( trim( $data['status'] ) ) : 'publish';
			$content = isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : '';

			// Find existing post by exact title (any non-trash status).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = %s AND post_status != 'trash' LIMIT 1",
				$title,
				self::POST_TYPE
			) );

			$post_args = array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => $status,
				'post_type'    => self::POST_TYPE,
			);

			if ( $existing_id ) {
				$post_args['ID'] = $existing_id;
				$result          = wp_update_post( $post_args, true );
			} else {
				$result = wp_insert_post( $post_args, true );
			}

			if ( is_wp_error( $result ) ) {
				$errors[] = '"' . $title . '": ' . $result->get_error_message();
				continue;
			}

			$post_id = (int) $result;

			// Assign taxonomy terms from any column matching a registered taxonomy slug.
			foreach ( $headers as $col ) {
				if ( in_array( $col, array( 'title', 'status', 'content' ), true ) ) {
					continue;
				}
				$val = trim( $data[ $col ] ?? '' );
				if ( $val !== '' && taxonomy_exists( $col ) ) {
					$term = get_term_by( 'slug', $val, $col );
					if ( $term ) {
						wp_set_object_terms( $post_id, $term->term_id, $col );
					}
				}
			}

			if ( $existing_id ) {
				$updated++;
			} else {
				$created++;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		wp_send_json_success( array(
			'created' => $created,
			'updated' => $updated,
			'skipped' => $skipped,
			'errors'  => $errors,
		) );
	}
}
