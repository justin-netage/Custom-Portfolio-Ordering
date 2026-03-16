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
		add_action( 'wp_ajax_cpo_fix_links', array( $this, 'ajax_fix_links' ) );
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

			<div class="cpo-fix-links-bar" style="margin:12px 0;padding:10px 14px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #dba617;">
				<strong><?php esc_html_e( 'Renamed some URLs?', 'custom-portfolio-ordering' ); ?></strong>
				<?php esc_html_e( 'Enter old and new slugs below (one per line) to find and replace them across all page content.', 'custom-portfolio-ordering' ); ?>
				<br><br>
				<textarea id="cpo-fix-links-input" rows="5" cols="60" style="font-family:monospace;font-size:13px;width:100%;max-width:500px;" placeholder="old-slug = new-slug&#10;venue = venues&#10;the-biltmore = the-arizona-biltmore"></textarea>
				<br><br>
				<button type="button" id="cpo-fix-links" class="button button-secondary">
					<span class="dashicons dashicons-admin-links" style="vertical-align:middle;margin-top:-2px;"></span>
					<?php esc_html_e( 'Fix Links', 'custom-portfolio-ordering' ); ?>
				</button>
				<span id="cpo-fix-links-spinner" class="spinner" style="float:none;vertical-align:middle;"></span>
				<div id="cpo-fix-links-result" style="margin-top:8px;"></div>
			</div>

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

			// Fix Links button handler.
			jQuery(function($){
				$('#cpo-fix-links').on('click', function(){
					var $btn     = $(this);
					var $spinner = $('#cpo-fix-links-spinner');
					var $result  = $('#cpo-fix-links-result');
					var raw      = $('#cpo-fix-links-input').val().trim();

					if (!raw) {
						$result.css('color', '#d63638').html('Please enter at least one <code>old-slug = new-slug</code> pair.');
						return;
					}

					// Parse lines into replacements array.
					var replacements = [];
					raw.split('\n').forEach(function(line){
						line = line.trim();
						if (!line) return;
						var parts = line.split('=');
						if (parts.length >= 2) {
							replacements.push({
								old_slug: parts[0].trim().replace(/^\//, ''),
								new_slug: parts.slice(1).join('=').trim().replace(/^\//, '')
							});
						}
					});

					if (!replacements.length) {
						$result.css('color', '#d63638').html('No valid pairs found. Use the format: <code>old-slug = new-slug</code>');
						return;
					}

					$btn.prop('disabled', true);
					$spinner.addClass('is-active');
					$result.html('');

					$.post(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
						action:       'cpo_fix_links',
						nonce:        <?php echo wp_json_encode( wp_create_nonce( 'cpo_sort_nonce' ) ); ?>,
						replacements: replacements
					}, function(response){
						$btn.prop('disabled', false);
						$spinner.removeClass('is-active');

						if (response.success) {
							var html = '<strong style="color:#00a32a;">' + response.data.message + '</strong>';
							if (response.data.details && response.data.details.length) {
								html += '<ul style="margin:4px 0 0 16px;list-style:disc;">';
								response.data.details.forEach(function(d){ html += '<li>' + d + '</li>'; });
								html += '</ul>';
							}
							$result.html(html);
						} else {
							$result.css('color', '#d63638').text(response.data.message || 'Fix failed.');
						}
					}).fail(function(){
						$btn.prop('disabled', false);
						$spinner.removeClass('is-active');
						$result.css('color', '#d63638').text('Request failed. Please try again.');
					});
				});
			});
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
			if ( preg_match( '/\[row width="full-width"\](.*?)\[\/row\]/s', $content, $row_match ) ) {
				preg_match_all( '/link="[^"]*\/([^"\/]+)"/', $row_match[1], $link_matches );
				$ordered_slugs = $link_matches[1] ?? array();

				preg_match_all( '/\[col[^\]]*\].*?\[\/col\]/s', $row_match[1], $col_matches );
				foreach ( $col_matches[0] ?? array() as $col ) {
					if ( preg_match( '/link="[^"]*\/([^"\/]+)"/', $col, $lm )
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

		if ( ! preg_match( '/(\[row width="full-width"\])(.*?)(\[\/row\])/s', $content, $row_match ) ) {
			wp_send_json_error( array( 'message' => 'Grid row not found in the page content.' ) );
		}

		// Extract individual [col]...[/col] blocks from inside the grid row.
		preg_match_all( '/\[col[^\]]*\].*?\[\/col\]/s', $row_match[2], $col_matches );
		$cols = $col_matches[0] ?? array();

		// Map each col to its link slug.
		$slug_to_col = array();
		$unmatched   = array();
		foreach ( $cols as $col ) {
			if ( preg_match( '/link="[^"]*\/([^"\/]+)"/', $col, $lm ) ) {
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
		$new_cols = array();
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
					} else {
						$col = preg_replace( '/(\[ux_image_box)([^\]]*\])/', '${1} img="' . $new_img . '"${2}', $col );
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

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of categories reordered */
				__( 'Grid order saved for %d categories.', 'custom-portfolio-ordering' ),
				count( $new_cols )
			),
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
	 * AJAX: Replace old slugs with new slugs across ALL post/page content.
	 *
	 * Accepts an array of { old_slug, new_slug } pairs from the client.
	 * Replaces every occurrence of /old-slug (as a path segment) with /new-slug
	 * in any post_content that contains it.
	 */
	public function ajax_fix_links() {
		check_ajax_referer( 'cpo_sort_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$raw_replacements = isset( $_POST['replacements'] ) ? $_POST['replacements'] : array();

		if ( empty( $raw_replacements ) || ! is_array( $raw_replacements ) ) {
			wp_send_json_error( array( 'message' => 'No replacements provided.' ) );
		}

		// Sanitize and build the replacement pairs.
		$pairs = array();
		foreach ( $raw_replacements as $r ) {
			$old = isset( $r['old_slug'] ) ? sanitize_title( trim( $r['old_slug'] ) ) : '';
			$new = isset( $r['new_slug'] ) ? sanitize_title( trim( $r['new_slug'] ) ) : '';
			if ( $old !== '' && $new !== '' && $old !== $new ) {
				$pairs[] = array( 'old' => $old, 'new' => $new );
			}
		}

		if ( empty( $pairs ) ) {
			wp_send_json_error( array( 'message' => 'No valid replacement pairs found.' ) );
		}

		// Build a SQL WHERE clause to find posts containing any of the old slugs.
		global $wpdb;
		$like_clauses = array();
		foreach ( $pairs as $p ) {
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
			$like_clauses[] = $wpdb->prepare( 'post_content LIKE %s', '%/' . $wpdb->esc_like( $p['old'] ) . '%' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$posts = $wpdb->get_results(
			"SELECT ID, post_content FROM {$wpdb->posts}
			 WHERE post_status IN ('publish','draft','private')
			 AND (" . implode( ' OR ', $like_clauses ) . ')'
		);

		$fixed       = 0;
		$details     = array();
		$total_links = 0;

		foreach ( $posts as $post ) {
			$content     = $post->post_content;
			$new_content = $content;

			foreach ( $pairs as $p ) {
				// Replace /old-slug/ with /new-slug/ (mid-path).
				$new_content = str_replace( '/' . $p['old'] . '/', '/' . $p['new'] . '/', $new_content );
				// Replace /old-slug" with /new-slug" (end of quoted attribute).
				$new_content = str_replace( '/' . $p['old'] . '"', '/' . $p['new'] . '"', $new_content );
				// Replace /old-slug' with /new-slug' (single-quoted attribute).
				$new_content = str_replace( '/' . $p['old'] . "'", '/' . $p['new'] . "'", $new_content );
			}

			if ( $new_content !== $content ) {
				wp_update_post( array(
					'ID'           => $post->ID,
					'post_content' => $new_content,
				) );
				$fixed++;

				// Count individual replacements for reporting.
				foreach ( $pairs as $p ) {
					$count = substr_count( $content, '/' . $p['old'] . '/' )
					       + substr_count( $content, '/' . $p['old'] . '"' )
					       + substr_count( $content, '/' . $p['old'] . "'" );
					if ( $count > 0 ) {
						$details[] = sprintf(
							'Page #%d: /%s &rarr; /%s (%d occurrence%s)',
							$post->ID,
							esc_html( $p['old'] ),
							esc_html( $p['new'] ),
							$count,
							$count !== 1 ? 's' : ''
						);
						$total_links += $count;
					}
				}
			}
		}

		if ( $fixed > 0 ) {
			$message = sprintf( '%d post(s) updated, %d link(s) fixed.', $fixed, $total_links );
		} else {
			$message = 'No matches found — the old slugs were not found in any page content.';
		}

		wp_send_json_success( array(
			'message' => $message,
			'fixed'   => $fixed,
			'details' => $details,
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
}
