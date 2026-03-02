(function ($) {
	'use strict';

	var $taxonomy   = $('#cpo-taxonomy');
	var $term       = $('#cpo-term');
	var $wrapper    = $('#cpo-list-wrapper');
	var $status     = $('#cpo-status');
	var $spinner    = $('#cpo-loading');
	var currentTerm = null;
	var currentTax  = null;
	var currentItems = [];

	/**
	 * Show a status message.
	 */
	function showStatus(message, type) {
		$status
			.removeClass('cpo-status-success cpo-status-error')
			.addClass('cpo-status-' + (type || 'success'))
			.text(message)
			.fadeIn();

		if (type !== 'error') {
			setTimeout(function () {
				$status.fadeOut();
			}, 3000);
		}
	}

	/**
	 * Build an indented term label for hierarchical display.
	 */
	function buildTermOptions(terms) {
		// Build a tree structure.
		var termMap  = {};
		var roots    = [];

		terms.forEach(function (t) {
			termMap[t.id] = $.extend({}, t, { children: [] });
		});

		terms.forEach(function (t) {
			if (t.parent && termMap[t.parent]) {
				termMap[t.parent].children.push(termMap[t.id]);
			} else {
				roots.push(termMap[t.id]);
			}
		});

		var options = [];

		function walk(items, depth) {
			items.forEach(function (item) {
				var prefix = '';
				for (var i = 0; i < depth; i++) {
					prefix += '\u00A0\u00A0\u00A0';
				}
				options.push({
					id:   item.id,
					name: prefix + item.name,
					count: item.count
				});
				if (item.children.length) {
					walk(item.children, depth + 1);
				}
			});
		}

		walk(roots, 0);
		return options;
	}

	/**
	 * Populate term dropdown when taxonomy changes.
	 */
	function loadTerms() {
		var tax = $taxonomy.val();
		$term.empty().append('<option value="">— Select a category —</option>');

		if (!tax || !window.cpoTerms || !window.cpoTerms[tax]) {
			return;
		}

		var options = buildTermOptions(window.cpoTerms[tax]);
		options.forEach(function (opt) {
			$term.append(
				$('<option></option>')
					.val(opt.id)
					.text(opt.name + ' (' + opt.count + ')')
			);
		});
	}

	/**
	 * Load items for the selected term.
	 */
	function loadItems() {
		var tax    = $taxonomy.val();
		var termId = $term.val();

		if (!tax || !termId) {
			$wrapper.html('<p class="cpo-placeholder">Select a taxonomy and category above to load items.</p>');
			$('#cpo-preview-grid').prop('disabled', true);
			return;
		}

		currentTax  = tax;
		currentTerm = termId;

		$spinner.addClass('is-active');
		$wrapper.html('<p class="cpo-placeholder">Loading items...</p>');

		$.post(cpoData.ajaxUrl, {
			action:   'cpo_get_items',
			nonce:    cpoData.nonce,
			taxonomy: tax,
			term_id:  termId
		}, function (response) {
			$spinner.removeClass('is-active');

			if (!response.success) {
				$wrapper.html('<p class="cpo-error">Error loading items.</p>');
				$('#cpo-preview-grid').prop('disabled', true);
				return;
			}

			var items = response.data.items;

			if (!items.length) {
				$wrapper.html('<p class="cpo-placeholder">No portfolio items found in this category.</p>');
				$('#cpo-preview-grid').prop('disabled', true);
				return;
			}

			currentItems = items;
			renderList(items);
			$('#cpo-preview-grid').prop('disabled', false);
		}).fail(function () {
			$spinner.removeClass('is-active');
			$wrapper.html('<p class="cpo-error">Request failed. Please try again.</p>');
			$('#cpo-preview-grid').prop('disabled', true);
		});
	}

	/**
	 * Render the sortable list.
	 */
	function renderList(items) {
		var html = '<div class="cpo-list-header">';
		html += '<span class="cpo-col-order">#</span>';
		html += '<span class="cpo-col-thumb"></span>';
		html += '<span class="cpo-col-title">Title</span>';
		html += '<span class="cpo-col-status">Status</span>';
		html += '</div>';
		html += '<ul id="cpo-sortable" class="cpo-sortable">';

		items.forEach(function (item, index) {
			var thumb = item.thumbnail
				? '<img src="' + item.thumbnail + '" alt="" />'
				: '<span class="cpo-no-thumb dashicons dashicons-format-image"></span>';

			html += '<li class="cpo-item" data-id="' + item.id + '">';
			html += '<span class="cpo-col-order cpo-handle"><span class="cpo-order-num">' + (index + 1) + '</span><span class="dashicons dashicons-menu cpo-drag-icon"></span></span>';
			html += '<span class="cpo-col-thumb">' + thumb + '</span>';
			html += '<span class="cpo-col-title">' + escHtml(item.title) + '</span>';
			html += '<span class="cpo-col-status"><span class="cpo-status-badge cpo-status-' + item.status + '">' + item.status + '</span></span>';
			html += '</li>';
		});

		html += '</ul>';
		html += '<div class="cpo-actions">';
		html += '<button type="button" id="cpo-save-order" class="button button-primary">Save Order</button>';
		html += '<span id="cpo-save-spinner" class="spinner" style="float:none;"></span>';
		html += '</div>';

		$wrapper.html(html);
		initSortable();
	}

	/**
	 * Escape HTML entities.
	 */
	function escHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	/**
	 * Initialize jQuery UI Sortable.
	 */
	function initSortable() {
		$('#cpo-sortable').sortable({
			handle: '.cpo-handle',
			placeholder: 'cpo-sortable-placeholder',
			cursor: 'grabbing',
			opacity: 0.8,
			update: function () {
				updateOrderNumbers();
			}
		});

		// Save button handler.
		$('#cpo-save-order').off('click').on('click', saveOrder);
	}

	/**
	 * Update the displayed order numbers after sorting.
	 */
	function updateOrderNumbers() {
		$('#cpo-sortable .cpo-item').each(function (index) {
			$(this).find('.cpo-order-num').text(index + 1);
		});
	}

	/**
	 * Save the current order via AJAX.
	 */
	function saveOrder() {
		var $btn     = $('#cpo-save-order');
		var $spinner = $('#cpo-save-spinner');
		var order    = [];

		$('#cpo-sortable .cpo-item').each(function () {
			order.push($(this).data('id'));
		});

		if (!order.length || !currentTerm) {
			return;
		}

		$btn.prop('disabled', true);
		$spinner.addClass('is-active');

		$.post(cpoData.ajaxUrl, {
			action:   'cpo_save_order',
			nonce:    cpoData.nonce,
			taxonomy: currentTax,
			term_id:  currentTerm,
			order:    order
		}, function (response) {
			$btn.prop('disabled', false);
			$spinner.removeClass('is-active');

			if (response.success) {
				showStatus(response.data.message, 'success');
			} else {
				showStatus('Error saving order.', 'error');
			}
		}).fail(function () {
			$btn.prop('disabled', false);
			$spinner.removeClass('is-active');
			showStatus('Request failed. Please try again.', 'error');
		});
	}

	// ─── Grid Preview Modal ──────────────────────────────────────────────────

	/**
	 * Open the grid preview modal using the current table order.
	 */
	function openPreviewModal() {
		if (!currentItems.length) {
			return;
		}

		// Avoid duplicate modals.
		if ($('#cpo-preview-overlay').length) {
			return;
		}

		// Build ordered list from the current table DOM (respects unsaved drags).
		var orderedItems = [];
		$('#cpo-sortable .cpo-item').each(function () {
			var id = parseInt($(this).data('id'), 10);
			for (var i = 0; i < currentItems.length; i++) {
				if (currentItems[i].id === id) {
					orderedItems.push(currentItems[i]);
					break;
				}
			}
		});

		renderGridModal(orderedItems);
	}

	/**
	 * Build and inject the grid preview modal.
	 */
	function renderGridModal(items) {
		var termName = $term.find('option:selected').text();

		// Build taxonomy options (mirrors main select).
		var taxOptionsHtml = '';
		$('#cpo-taxonomy option').each(function () {
			var sel = ($(this).val() === currentTax) ? ' selected' : '';
			taxOptionsHtml += '<option value="' + escHtml($(this).val()) + '"' + sel + '>' + escHtml($(this).text()) + '</option>';
		});

		// Build term options for the current taxonomy.
		var termOptionsHtml = '<option value="">— Select a category —</option>';
		if (window.cpoTerms && window.cpoTerms[currentTax]) {
			buildTermOptions(window.cpoTerms[currentTax]).forEach(function (opt) {
				var sel = (String(opt.id) === String(currentTerm)) ? ' selected' : '';
				termOptionsHtml += '<option value="' + opt.id + '"' + sel + '>' + escHtml(opt.name + ' (' + opt.count + ')') + '</option>';
			});
		}

		var html = '<div id="cpo-preview-overlay" class="cpo-preview-overlay" role="dialog" aria-modal="true" aria-label="Grid Preview">';
		html += '<div class="cpo-preview-modal">';

		// Header.
		html += '<div class="cpo-preview-header">';
		html += '<h2 class="cpo-preview-title">Grid Preview &mdash; ' + escHtml(termName) + '</h2>';
		html += '<button type="button" class="cpo-preview-close" aria-label="Close">&times;</button>';
		html += '</div>';

		// Description.
		html += '<p class="cpo-preview-desc">Drag cards to reorder, then click <strong>Save Order</strong> to apply. This mimics the front-end grid layout.</p>';

		// Filter bar.
		html += '<div class="cpo-modal-filter">';
		html += '<label for="cpo-modal-taxonomy">Taxonomy:</label>';
		html += '<select id="cpo-modal-taxonomy">' + taxOptionsHtml + '</select>';
		html += '<label for="cpo-modal-term">Category:</label>';
		html += '<select id="cpo-modal-term">' + termOptionsHtml + '</select>';
		html += '<span id="cpo-modal-loading" class="spinner" style="float:none;"></span>';
		html += '</div>';

		// Scrollable grid area.
		html += '<div class="cpo-preview-grid-wrapper">';
		html += '<ul id="cpo-grid-sortable" class="cpo-grid-sortable">';

		items.forEach(function (item, index) {
			var thumb = item.thumbnail
				? '<img src="' + item.thumbnail + '" alt="" />'
				: '<span class="cpo-grid-no-thumb dashicons dashicons-format-image"></span>';

			html += '<li class="cpo-grid-item" data-id="' + item.id + '">';
			html += '<div class="cpo-grid-card">';
			html += '<div class="cpo-grid-img-wrap">' + thumb + '</div>';
			html += '<div class="cpo-grid-meta">';
			html += '<span class="cpo-grid-order-num">' + (index + 1) + '</span>';
			html += '<span class="cpo-grid-title">' + escHtml(item.title) + '</span>';
			html += '</div>';
			html += '</div>';
			html += '</li>';
		});

		html += '</ul>';
		html += '</div>'; // .cpo-preview-grid-wrapper

		// Footer actions.
		html += '<div class="cpo-preview-actions">';
		html += '<button type="button" id="cpo-grid-save-order" class="button button-primary">Save Order</button>';
		html += '<span id="cpo-grid-save-spinner" class="spinner" style="float:none;"></span>';
		html += '<button type="button" id="cpo-grid-cancel" class="button">Cancel</button>';
		html += '</div>';

		html += '</div>'; // .cpo-preview-modal
		html += '</div>'; // #cpo-preview-overlay

		$('body').append(html);
		$('body').addClass('cpo-modal-open');

		initGridSortable();

		// Close on overlay click.
		$('#cpo-preview-overlay').on('click', function (e) {
			if ($(e.target).is('#cpo-preview-overlay')) {
				closePreviewModal();
			}
		});

		// Close/cancel buttons.
		$('#cpo-preview-overlay').find('.cpo-preview-close').on('click', closePreviewModal);
		$('#cpo-grid-cancel').on('click', closePreviewModal);

		// Save from grid.
		$('#cpo-grid-save-order').on('click', saveOrderFromGrid);

		// Close on Escape key.
		$(document).on('keydown.cpomodal', function (e) {
			if (e.key === 'Escape') {
				closePreviewModal();
			}
		});

		// Modal filter: repopulate terms when taxonomy changes.
		$('#cpo-modal-taxonomy').on('change', function () {
			var newTax = $(this).val();
			var $modalTerm = $('#cpo-modal-term');
			$modalTerm.empty().append('<option value="">— Select a category —</option>');
			if (window.cpoTerms && window.cpoTerms[newTax]) {
				buildTermOptions(window.cpoTerms[newTax]).forEach(function (opt) {
					$modalTerm.append(
						$('<option></option>').val(opt.id).text(opt.name + ' (' + opt.count + ')')
					);
				});
			}
		});

		// Modal filter: reload grid when category changes.
		$('#cpo-modal-term').on('change', reloadGridItems);
	}

	/**
	 * Reload the grid inside the modal for a newly selected taxonomy/term.
	 */
	function reloadGridItems() {
		var newTax    = $('#cpo-modal-taxonomy').val();
		var newTermId = $('#cpo-modal-term').val();
		var $grid     = $('#cpo-grid-sortable');
		var $spin     = $('#cpo-modal-loading');

		if (!newTax || !newTermId) {
			try { $grid.sortable('destroy'); } catch (e) {}
			$grid.html('<li class="cpo-grid-empty">Select a category to load items.</li>');
			$('#cpo-grid-save-order').prop('disabled', true);
			return;
		}

		$spin.addClass('is-active');
		$('#cpo-grid-save-order').prop('disabled', true);

		$.post(cpoData.ajaxUrl, {
			action:   'cpo_get_items',
			nonce:    cpoData.nonce,
			taxonomy: newTax,
			term_id:  newTermId
		}, function (response) {
			$spin.removeClass('is-active');

			if (!response.success) {
				$grid.html('<li class="cpo-grid-error">Error loading items.</li>');
				return;
			}

			var items = response.data.items;

			// Update shared state.
			currentTax   = newTax;
			currentTerm  = newTermId;
			currentItems = items;

			// Sync the main page dropdowns.
			$taxonomy.val(newTax);
			loadTerms();
			$term.val(newTermId);

			// Update modal title.
			var termName = $('#cpo-modal-term option:selected').text();
			$('.cpo-preview-title').text('Grid Preview \u2014 ' + termName);

			// Rebuild the grid list.
			try { $grid.sortable('destroy'); } catch (e) {}

			if (!items.length) {
				$grid.html('<li class="cpo-grid-empty">No portfolio items found in this category.</li>');
				return;
			}

			var html = '';
			items.forEach(function (item, index) {
				var thumb = item.thumbnail
					? '<img src="' + item.thumbnail + '" alt="" />'
					: '<span class="cpo-grid-no-thumb dashicons dashicons-format-image"></span>';

				html += '<li class="cpo-grid-item" data-id="' + item.id + '">';
				html += '<div class="cpo-grid-card">';
				html += '<div class="cpo-grid-img-wrap">' + thumb + '</div>';
				html += '<div class="cpo-grid-meta">';
				html += '<span class="cpo-grid-order-num">' + (index + 1) + '</span>';
				html += '<span class="cpo-grid-title">' + escHtml(item.title) + '</span>';
				html += '</div>';
				html += '</div>';
				html += '</li>';
			});

			$grid.html(html);
			initGridSortable();
			$('#cpo-grid-save-order').prop('disabled', false);

			// Also sync the main list.
			renderList(items);
			$('#cpo-preview-grid').prop('disabled', false);
		}).fail(function () {
			$spin.removeClass('is-active');
			$grid.html('<li class="cpo-grid-error">Request failed. Please try again.</li>');
		});
	}

	/**
	 * Close and remove the grid preview modal.
	 */
	function closePreviewModal() {
		$('#cpo-preview-overlay').remove();
		$('body').removeClass('cpo-modal-open');
		$(document).off('keydown.cpomodal');
	}

	/**
	 * Initialize jQuery UI Sortable on the grid.
	 */
	function initGridSortable() {
		$('#cpo-grid-sortable').sortable({
			placeholder: 'cpo-grid-placeholder',
			forcePlaceholderSize: true,
			cursor: 'grabbing',
			opacity: 0.75,
			tolerance: 'pointer',
			start: function (e, ui) {
				// Match placeholder height to the dragged card.
				$('.cpo-grid-placeholder').height(ui.item.outerHeight());
			},
			update: function () {
				updateGridOrderNumbers();
			}
		});
	}

	/**
	 * Refresh the order-number badge on each grid card.
	 */
	function updateGridOrderNumbers() {
		$('#cpo-grid-sortable .cpo-grid-item').each(function (index) {
			$(this).find('.cpo-grid-order-num').text(index + 1);
		});
	}

	/**
	 * Save the grid order via AJAX and sync back to the table.
	 */
	function saveOrderFromGrid() {
		var $btn     = $('#cpo-grid-save-order');
		var $spin    = $('#cpo-grid-save-spinner');
		var order    = [];

		$('#cpo-grid-sortable .cpo-grid-item').each(function () {
			order.push($(this).data('id'));
		});

		if (!order.length || !currentTerm) {
			return;
		}

		$btn.prop('disabled', true);
		$spin.addClass('is-active');

		$.post(cpoData.ajaxUrl, {
			action:   'cpo_save_order',
			nonce:    cpoData.nonce,
			taxonomy: currentTax,
			term_id:  currentTerm,
			order:    order
		}, function (response) {
			$btn.prop('disabled', false);
			$spin.removeClass('is-active');

			if (response.success) {
				syncTableToGridOrder(order);
				showStatus(response.data.message, 'success');
				closePreviewModal();
			} else {
				showStatus('Error saving order.', 'error');
				closePreviewModal();
			}
		}).fail(function () {
			$btn.prop('disabled', false);
			$spin.removeClass('is-active');
			showStatus('Request failed. Please try again.', 'error');
			closePreviewModal();
		});
	}

	/**
	 * Re-order the table rows to match the saved grid order.
	 */
	function syncTableToGridOrder(order) {
		var $list   = $('#cpo-sortable');
		var itemMap = {};

		$list.find('.cpo-item').each(function () {
			itemMap[$(this).data('id')] = $(this);
		});

		order.forEach(function (id) {
			if (itemMap[id]) {
				$list.append(itemMap[id]);
			}
		});

		updateOrderNumbers();
	}

	// ─── Import modal ─────────────────────────────────────────────────────────

	/**
	 * Open the CSV import modal.
	 */
	function openImportModal() {
		if ( $( '#cpo-import-overlay' ).length ) return;

		// Build a representative example using the first known taxonomy.
		var exampleTax = 'your_taxonomy';
		if ( window.cpoTerms ) {
			var taxKeys = Object.keys( window.cpoTerms );
			if ( taxKeys.length ) exampleTax = taxKeys[0];
		}

		var html = '<div id="cpo-import-overlay" class="cpo-preview-overlay" role="dialog" aria-modal="true" aria-label="Import Portfolio Items">';
		html += '<div class="cpo-preview-modal cpo-import-modal">';

		html += '<div class="cpo-preview-header">';
		html += '<h2 class="cpo-preview-title">Import Portfolio Items</h2>';
		html += '<button type="button" class="cpo-preview-close" aria-label="Close">&times;</button>';
		html += '</div>';

		html += '<div class="cpo-import-body">';
		html += '<p>Upload a CSV file to create or update portfolio items. Items are matched by <strong>title</strong> — existing items will be updated, new ones will be created.</p>';

		html += '<div class="cpo-import-format">';
		html += '<strong>Required column:</strong> <code>title</code><br>';
		html += '<strong>Optional columns:</strong> <code>status</code>, <code>content</code>, <code>' + escHtml( exampleTax ) + '</code> <em>(any registered taxonomy slug)</em>';
		html += '<pre class="cpo-import-example">title,status,' + escHtml( exampleTax ) + '\n1,publish,my-category\n2,draft,another-category\n3,publish,</pre>';
		html += '</div>';

		html += '<div class="cpo-import-file-area">';
		html += '<label class="cpo-file-label"><span class="dashicons dashicons-upload"></span> Choose CSV File';
		html += '<input type="file" id="cpo-csv-file" accept=".csv,text/csv" style="position:absolute;opacity:0;width:0;height:0;">';
		html += '</label>';
		html += '<span id="cpo-file-name" class="cpo-file-name">No file chosen</span>';
		html += '</div>';

		html += '<div id="cpo-import-results" class="cpo-import-results" style="display:none;"></div>';
		html += '</div>';

		html += '<div class="cpo-preview-actions">';
		html += '<button type="button" id="cpo-import-submit" class="button button-primary" disabled>Import</button>';
		html += '<span id="cpo-import-spinner" class="spinner" style="float:none;"></span>';
		html += '<button type="button" id="cpo-import-cancel" class="button">Cancel</button>';
		html += '</div>';

		html += '</div></div>';

		$( 'body' ).append( html );
		$( 'body' ).addClass( 'cpo-modal-open' );

		// File input change.
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

		$( '#cpo-import-submit' ).on( 'click', submitImport );

		$( '#cpo-import-overlay' ).find( '.cpo-preview-close' ).on( 'click', closeImportModal );
		$( '#cpo-import-cancel' ).on( 'click', closeImportModal );
		$( '#cpo-import-overlay' ).on( 'click', function ( e ) {
			if ( $( e.target ).is( '#cpo-import-overlay' ) ) closeImportModal();
		} );
		$( document ).on( 'keydown.cpoimport', function ( e ) {
			if ( e.key === 'Escape' ) closeImportModal();
		} );
	}

	/**
	 * Submit the CSV file to the import AJAX endpoint.
	 */
	function submitImport() {
		var fileInput = $( '#cpo-csv-file' )[0];
		if ( ! fileInput.files.length ) return;

		var formData = new FormData();
		formData.append( 'action', 'cpo_import' );
		formData.append( 'nonce', cpoData.nonce );
		formData.append( 'csv_file', fileInput.files[0] );

		$( '#cpo-import-submit' ).prop( 'disabled', true );
		$( '#cpo-import-spinner' ).addClass( 'is-active' );
		$( '#cpo-import-results' ).hide();

		$.ajax( {
			url: cpoData.ajaxUrl,
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
				$( '#cpo-import-submit' ).hide();
				$( '#cpo-import-cancel' ).text( 'Close' );

				// Refresh the list if items changed and a term is currently selected.
				if ( ( d.created || d.updated ) && currentTerm ) {
					loadItems();
				}
			},
			error: function () {
				$( '#cpo-import-spinner' ).removeClass( 'is-active' );
				$( '#cpo-import-results' ).html( '<p class="cpo-import-error">A server error occurred. Please try again.</p>' ).show();
				$( '#cpo-import-submit' ).prop( 'disabled', false );
			}
		} );
	}

	/**
	 * Close and remove the import modal.
	 */
	function closeImportModal() {
		$( '#cpo-import-overlay' ).remove();
		$( 'body' ).removeClass( 'cpo-modal-open' );
		$( document ).off( 'keydown.cpoimport' );
	}

	// ─── Event bindings ───────────────────────────────────────────────────────

	$taxonomy.on('change', function () {
		loadTerms();
		$wrapper.html('<p class="cpo-placeholder">Select a category to load items.</p>');
		$('#cpo-preview-grid').prop('disabled', true);
	});

	$term.on('change', loadItems);

	// Grid Preview button (lives in controls bar).
	$('#cpo-preview-grid').on('click', openPreviewModal);

	// Import button.
	$( '#cpo-import-btn' ).on( 'click', openImportModal );

	// Initialize on page load.
	loadTerms();

})(jQuery);
