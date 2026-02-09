(function ($) {
	'use strict';

	var $taxonomy   = $('#cpo-taxonomy');
	var $term       = $('#cpo-term');
	var $wrapper    = $('#cpo-list-wrapper');
	var $status     = $('#cpo-status');
	var $spinner    = $('#cpo-loading');
	var currentTerm = null;
	var currentTax  = null;

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

	// Event bindings.
	$taxonomy.on('change', function () {
		loadTerms();
		$wrapper.html('<p class="cpo-placeholder">Select a category to load items.</p>');
	});

	$term.on('change', loadItems);

	// Initialize on page load.
	loadTerms();

})(jQuery);
