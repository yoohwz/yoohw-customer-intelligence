(function() {
	'use strict';

	function ready(callback) {
		if ('loading' === document.readyState) {
			document.addEventListener('DOMContentLoaded', callback);
			return;
		}

		callback();
	}

	function toggleNote(noteId, isEditing) {
		var content = document.getElementById('yoohw-cos-note-content-' + noteId);
		var edit = document.getElementById('yoohw-cos-note-edit-' + noteId);

		if (!content || !edit) {
			return;
		}

		content.style.display = isEditing ? 'none' : 'block';
		edit.style.display = isEditing ? 'block' : 'none';
	}

	function getSelectedBulkAction(preferredSelect) {
		var action = '';

		if (preferredSelect && preferredSelect.value && '-1' !== preferredSelect.value) {
			return preferredSelect.value;
		}

		document.querySelectorAll('select[name="action"], select[name="action2"]').forEach(function(select) {
			if (!action && select.value && '-1' !== select.value) {
				action = select.value;
			}
		});

		return action;
	}

	function clearOtherBulkActions(activeSelect) {
		if (!activeSelect || !activeSelect.value || '-1' === activeSelect.value) {
			return;
		}

		document.querySelectorAll('select[name="action"], select[name="action2"]').forEach(function(select) {
			if (select !== activeSelect) {
				select.value = '-1';
			}
		});
	}

	function setBulkTargetVisibility(targetName, isVisible) {
		document.querySelectorAll('[data-yoohw-cos-bulk-target="' + targetName + '"]').forEach(function(target) {
			target.hidden = !isVisible;

			if (!isVisible) {
				target.querySelectorAll('select, input, textarea').forEach(function(field) {
					if ('checkbox' === field.type || 'radio' === field.type) {
						field.checked = false;
						return;
					}

					if (field.hasAttribute('data-yoohw-cos-reset-value')) {
						field.value = field.getAttribute('data-yoohw-cos-reset-value');
						return;
					}

					field.value = 'SELECT' === field.tagName ? '0' : '';
				});
			}
		});
	}

	function updateBulkTargets(preferredSelect) {
		var action = getSelectedBulkAction(preferredSelect);
		var showTag = 'bulk_assign_tag' === action || 'bulk_remove_tag' === action;
		var showSegment = 'bulk_assign_segment' === action || 'bulk_remove_segment' === action;
		var showTask = 'bulk_create_task' === action;

		document.querySelectorAll('[data-yoohw-cos-bulk-targets]').forEach(function(container) {
			container.hidden = !(showTag || showSegment || showTask);
		});

		setBulkTargetVisibility('tag', showTag);
		setBulkTargetVisibility('segment', showSegment);
		setBulkTargetVisibility('task', showTask);
	}

	function escapeHtml(value) {
		var div = document.createElement('div');
		div.textContent = value;
		return div.innerHTML;
	}

	function formatNumber(value) {
		var number = parseInt(value, 10);

		if (isNaN(number)) {
			number = 0;
		}

		return number.toLocaleString();
	}

	function setSyncText(container, selector, value) {
		var targets = container.querySelectorAll(selector);

		if (!targets.length) {
			return;
		}

		targets.forEach(function(target) {
			target.textContent = value;
		});
	}

	function setSyncProgress(container, percent) {
		var safePercent = Math.max(0, Math.min(100, parseInt(percent, 10) || 0));
		var bar = container.querySelector('[data-yoohw-cos-progress-bar]');
		var track = container.querySelector('[data-yoohw-cos-progress-track]');
		var label = container.querySelector('[data-yoohw-cos-sync-percent]');

		if (bar) {
			bar.style.width = safePercent + '%';
		}

		if (track) {
			track.setAttribute('aria-valuenow', safePercent);
		}

		if (label) {
			label.textContent = safePercent + '%';
		}
	}

	function setSyncStatus(container, state, message) {
		var statusValue = container.querySelector('.yoohw-cos-sync-status-value');
		var statusType = state && state.hasMore ? 'warning' : 'good';
		var statusText = message;

		if (statusValue && statusText) {
			statusValue.innerHTML = '<span class="yoohw-cos-status-pill yoohw-cos-status-pill--' + statusType + '">' + escapeHtml(statusText) + '</span>';
		}
	}

	function updateSyncCenter(container, state, message) {
		if (!container || !state) {
			return;
		}

		setSyncProgress(container, state.percent);
		setSyncStatus(container, state, message);
		setSyncText(container, '[data-yoohw-cos-sync-message]', message);
		setSyncText(container, '.yoohw-cos-sync-last-scanned', formatNumber(state.lastScanned));
		setSyncText(container, '.yoohw-cos-sync-total-scanned', formatNumber(state.totalScanned));
		setSyncText(container, '.yoohw-cos-sync-total-processed', formatNumber(state.totalProcessed));
		setSyncText(container, '.yoohw-cos-sync-total-orders', state.totalOrders > 0 ? formatNumber(state.totalOrders) : '—');
		setSyncText(container, '.yoohw-cos-sync-resume-page', state.hasMore ? formatNumber(state.nextPage) : '—');
	}

	function runAjaxSync(form) {
		var admin = window.yoohwCosAdmin || {};

		if (!window.fetch || !window.FormData || !admin.ajaxUrl || !admin.syncNonce) {
			return false;
		}

		if ('1' === form.getAttribute('data-yoohw-cos-syncing')) {
			return true;
		}

		var container = form.closest('[data-yoohw-cos-sync-center]');
		var pageInput = form.querySelector('input[name="sync_page"]');
		var submit = form.querySelector('input[type="submit"], button[type="submit"]');
		var spinner = form.querySelector('[data-yoohw-cos-sync-spinner]');
		var startPage = pageInput ? parseInt(pageInput.value, 10) || 1 : 1;

		form.setAttribute('data-yoohw-cos-syncing', '1');

		if (submit) {
			submit.disabled = true;
		}

		if (spinner) {
			spinner.classList.add('is-active');
		}

		setSyncText(container, '[data-yoohw-cos-sync-message]', admin.syncRunningText || 'Syncing orders...');

		function runPage(page) {
			var data = new FormData();
			data.append('action', 'yoohw_cos_ajax_sync_customers');
			data.append('nonce', admin.syncNonce);
			data.append('sync_page', page);

			return fetch(admin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			})
				.then(function(response) {
					return response.json();
				})
				.then(function(response) {
					if (!response || !response.success || !response.data) {
						throw new Error(admin.syncErrorText || 'Sync could not be completed.');
					}

					var result = response.data;
					var state = result.state || {};
					var nextPage = parseInt(result.nextPage, 10) || page + 1;
					var message = result.hasMore ? (admin.syncRunningText || 'Syncing orders...') : (admin.syncCompleteText || 'Sync complete.');

					if (pageInput) {
						pageInput.value = result.hasMore ? nextPage : 1;
					}

					updateSyncCenter(container, state, message);

					if (result.hasMore) {
						return runPage(nextPage);
					}

					return result;
				});
		}

		runPage(startPage)
			.catch(function(error) {
				setSyncText(container, '[data-yoohw-cos-sync-message]', error.message || admin.syncErrorText || 'Sync could not be completed.');
			})
			.finally(function() {
				form.removeAttribute('data-yoohw-cos-syncing');

				if (submit) {
					submit.disabled = false;
				}

				if (spinner) {
					spinner.classList.remove('is-active');
				}
			});

		return true;
	}

	function initAjaxSelect($, $field, options) {
		if (!$field.length || $field.hasClass('enhanced') || !$.fn.selectWoo) {
			return;
		}

		$field.selectWoo({
			allowClear: options.allowClear || false,
			placeholder: options.placeholder || $field.data('placeholder') || '',
			minimumInputLength: options.minimumInputLength || 0,
			dropdownCssClass: 'yoohw-cos-selectwoo-dropdown',
			width: '100%',
			language: {
				noResults: function() {
					return options.noResults || '';
				}
			},
			ajax: {
				url: options.ajaxUrl,
				dataType: 'json',
				delay: 250,
				data: function(request) {
					return {
						action: options.action,
						security: options.security,
						term: request.term || ''
					};
				},
				processResults: function(data) {
					var results = [];

					if (data) {
						$.each(data, function(id, text) {
							results.push({
								id: id,
								text: text
							});
						});
					}

					return {
						results: results
					};
				},
				cache: true
			}
		}).addClass('enhanced');
	}

	function initTaskSearchableSelects() {
		var $ = window.jQuery;
		var admin = window.yoohwCosAdmin || {};

		if (!$ || !admin.ajaxUrl) {
			return;
		}

		$('.yoohw-cos-task-customer-search').each(function() {
			initAjaxSelect($, $(this), {
				action: 'yoohw_cos_json_search_customers',
				ajaxUrl: admin.ajaxUrl,
				security: admin.customerSearchNonce,
				placeholder: admin.customerPlaceholderText || 'Search customer profile',
				noResults: admin.customerNoResultsText || 'No customer profiles found',
				minimumInputLength: 1
			});
		});

		$('.yoohw-cos-task-assignee-search').each(function() {
			initAjaxSelect($, $(this), {
				action: 'yoohw_cos_json_search_assignable_users',
				ajaxUrl: admin.ajaxUrl,
				security: admin.assigneeSearchNonce,
				placeholder: admin.assigneePlaceholderText || 'Search assignee',
				noResults: admin.assigneeNoResultsText || 'No assignable users found',
				minimumInputLength: 0
			});
		});
	}
	ready(function() {
		updateBulkTargets();
		initTaskSearchableSelects();

		document.addEventListener('change', function(event) {
			if (event.target.matches('select[name="action"], select[name="action2"]')) {
				clearOtherBulkActions(event.target);
				updateBulkTargets(event.target);
			}
		});

		document.querySelectorAll('[data-yoohw-cos-auto-submit="1"]').forEach(function(form) {
			setTimeout(function() {
				form.submit();
			}, 800);
		});

		document.addEventListener('submit', function(event) {
			var syncForm = event.target.closest('form[data-yoohw-cos-ajax-sync="1"]');

			if (syncForm && runAjaxSync(syncForm)) {
				event.preventDefault();
			}
		});

		document.addEventListener('submit', function(event) {
			var target = event.target.closest('[data-yoohw-cos-confirm]');

			if (target && !window.confirm(target.getAttribute('data-yoohw-cos-confirm'))) {
				event.preventDefault();
			}
		});

		document.addEventListener('click', function(event) {
			var confirmTarget = event.target.closest('[data-yoohw-cos-confirm]');

			if (
				confirmTarget
				&& 'FORM' !== confirmTarget.tagName
				&& !window.confirm(confirmTarget.getAttribute('data-yoohw-cos-confirm'))
			) {
				event.preventDefault();
				return;
			}

			var copyButton = event.target.closest('.yoohw-cos-copy');

			if (copyButton) {
				var value = copyButton.getAttribute('data-copy');

				if (!value || !navigator.clipboard) {
					return;
				}

				navigator.clipboard.writeText(value).then(function() {
					var original = copyButton.textContent;
					var copiedText = window.yoohwCosAdmin && window.yoohwCosAdmin.copiedText ? window.yoohwCosAdmin.copiedText : 'Copied!';

					copyButton.textContent = copiedText;

					setTimeout(function() {
						copyButton.textContent = original;
					}, 1200);
				});

				return;
			}

			var editButton = event.target.closest('.yoohw-cos-edit-note-toggle');

			if (editButton) {
				event.preventDefault();
				toggleNote(editButton.getAttribute('data-note-id'), true);
				return;
			}

			var cancelButton = event.target.closest('.yoohw-cos-cancel-note-edit');

			if (cancelButton) {
				event.preventDefault();
				toggleNote(cancelButton.getAttribute('data-note-id'), false);
			}
		});
	});
})();
