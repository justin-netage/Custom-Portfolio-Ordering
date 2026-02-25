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
				return;
			}

			var items = response.data.items;

			if (!items.length) {
				$wrapper.html('<p class="cpo-placeholder">No portfolio items found in this category.</p>');
				return;
			}

			currentItems = items;
			renderList(items);
		}).fail(function () {
			$spinner.removeClass('is-active');
			$wrapper.html('<p class="cpo-error">Request failed. Please try again.</p>');
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
		html += '<button type="button" id="cpo-preview-grid" class="button cpo-btn-preview"><span class="dashicons dashicons-screenoptions"></span> Grid Preview</button>';
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

		// Grid preview button handler.
		$('#cpo-preview-grid').off('click').on('click', openPreviewModal);
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

		var html = '<div id="cpo-preview-overlay" class="cpo-preview-overlay" role="dialog" aria-modal="true" aria-label="Grid Preview">';
		html += '<div class="cpo-preview-modal">';

		// Header.
		html += '<div class="cpo-preview-header">';
		html += '<h2 class="cpo-preview-title">Grid Preview &mdash; ' + escHtml(termName) + '</h2>';
		html += '<button type="button" class="cpo-preview-close" aria-label="Close">&times;</button>';
		html += '</div>';

		// Description.
		html += '<p class="cpo-preview-desc">Drag cards to reorder, then click <strong>Save Order</strong> to apply. This mimics the front-end grid layout.</p>';

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

	// ─── Event bindings ───────────────────────────────────────────────────────

	$taxonomy.on('change', function () {
		loadTerms();
		$wrapper.html('<p class="cpo-placeholder">Select a category to load items.</p>');
	});

	$term.on('change', loadItems);

	// Initialize on page load.
	loadTerms();

})(jQuery);
