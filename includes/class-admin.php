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

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'wp_ajax_cpo_save_order', array( $this, 'ajax_save_order' ) );
		add_action( 'wp_ajax_cpo_get_items', array( $this, 'ajax_get_items' ) );
		add_action( 'wp_ajax_cpo_import_start', array( $this, 'ajax_import_start' ) );
		add_action( 'wp_ajax_cpo_import_chunk', array( $this, 'ajax_import_chunk' ) );
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
			__( 'Portfolio Ordering', 'custom-portfolio-ordering' ),
			__( 'Portfolio Ordering', 'custom-portfolio-ordering' ),
			'edit_posts',
			'custom-portfolio-ordering',
			array( $this, 'render_admin_page' )
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

			var jobId = null, totalRows = 0, created = 0, updated = 0, skipped = 0, errors = [];

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
				jobId = null; totalRows = 0; created = 0; updated = 0; skipped = 0; errors = [];
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
		$headers = array_map( 'trim', $headers );

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
		$has_parent_cat = in_array( 'Parent Category', $headers, true );
		$has_sub_cat    = in_array( 'Sub Category', $headers, true );
		$term_positions = $job['term_positions'] ?? array();

		if ( $has_thumbnail ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$primary_taxonomy    = '';
		$tax_is_hierarchical = false;
		if ( $has_parent_cat || $has_sub_cat ) {
			// Use ALL registered taxonomies (not just hierarchical) so the importer
			// works regardless of how the post type's taxonomy was registered.
			$all_taxes = get_object_taxonomies( self::POST_TYPE, 'objects' );
			if ( ! empty( $all_taxes ) ) {
				$first_tax           = reset( $all_taxes );
				$primary_taxonomy    = $first_tax->name;
				$tax_is_hierarchical = (bool) $first_tax->hierarchical;
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

			// Match by id (WordPress post ID) first, then fall back to title.
			$existing_id = 0;
			if ( $row_id !== '' ) {
				$numeric_id = absint( $row_id );
				if ( $numeric_id ) {
					$found = get_post( $numeric_id );
					if ( $found && $found->post_type === self::POST_TYPE && $found->post_status !== 'trash' ) {
						$existing_id = $found->ID;
					}
				}
			}
			if ( ! $existing_id && $title !== '' ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = %s AND post_status != 'trash' LIMIT 1",
					$title,
					self::POST_TYPE
				) );
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
				$parent_cat_name = sanitize_text_field( trim( $data['Parent Category'] ?? '' ) );
				$sub_cat_name    = sanitize_text_field( trim( $data['Sub Category'] ?? '' ) );
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

		wp_send_json_success( array(
			'created'     => $created,
			'updated'     => $updated,
			'skipped'     => $skipped,
			'errors'      => $errors,
			'next_offset' => $next_offset,
			'done'        => $done,
		) );
	}
}
