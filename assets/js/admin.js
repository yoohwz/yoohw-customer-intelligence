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

	function saveScrollPosition() {
		try {
			window.sessionStorage.setItem('yoohwCosProfileScrollY', String(window.scrollY || window.pageYOffset || 0));
		} catch (error) {
			// Ignore browsers that block sessionStorage.
		}
	}

	function restoreScrollPosition() {
		try {
			var scrollY = window.sessionStorage.getItem('yoohwCosProfileScrollY');

			if (null === scrollY) {
				return;
			}

			window.sessionStorage.removeItem('yoohwCosProfileScrollY');

			setTimeout(function() {
				window.scrollTo(window.scrollX || window.pageXOffset || 0, parseInt(scrollY, 10) || 0);
			}, 0);
		} catch (error) {
			// Ignore browsers that block sessionStorage.
		}
	}

	function isInteractiveRowTarget(target) {
		return !!target.closest('a, button, input, select, textarea, label, summary, .row-actions, [data-yoohw-cos-row-ignore]');
	}

	function shouldIgnoreRowClick(event) {
		var selection = window.getSelection ? window.getSelection() : null;

		return event.defaultPrevented
			|| (event.button && 0 !== event.button)
			|| isInteractiveRowTarget(event.target)
			|| !!(selection && selection.toString());
	}

	function openClickableRow(row, event) {
		var url = row ? row.getAttribute('data-yoohw-cos-row-url') : '';

		if (!url) {
			return;
		}

		if (event && (event.metaKey || event.ctrlKey)) {
			window.open(url, '_blank', 'noopener');
			return;
		}

		window.location.href = url;
	}

	var emailComposerLastFocus = null;

	function getEmailComposer() {
		return document.getElementById('yoohw-cos-email-composer');
	}

	function setEmailComposerStatus(modal, type, message) {
		var status = modal ? modal.querySelector('[data-yoohw-cos-email-status]') : null;

		if (!status) {
			return;
		}

		status.className = 'yoohw-cos-email-composer__status';
		status.textContent = message || '';

		if (type) {
			status.classList.add('is-' + type);
		}
	}

	function openEmailComposer(trigger) {
		var modal = getEmailComposer();

		if (!modal) {
			return;
		}

		emailComposerLastFocus = trigger || document.activeElement;
		setEmailComposerStatus(modal, '', '');
		modal.hidden = false;
		document.body.classList.add('yoohw-cos-email-composer-open');

		var subject = modal.querySelector('#yoohw-cos-email-subject');

		if (subject) {
			setTimeout(function() {
				subject.focus();
				subject.select();
			}, 0);
		}
	}

	function closeEmailComposer() {
		var modal = getEmailComposer();

		if (!modal || modal.hidden) {
			return;
		}

		var form = modal.querySelector('[data-yoohw-cos-email-form]');

		if (form && 'true' === form.getAttribute('aria-busy')) {
			return;
		}

		modal.hidden = true;
		document.body.classList.remove('yoohw-cos-email-composer-open');

		if (emailComposerLastFocus && typeof emailComposerLastFocus.focus === 'function') {
			emailComposerLastFocus.focus();
		}
	}

	function keepFocusInEmailComposer(event) {
		var modal = getEmailComposer();

		if (!modal || modal.hidden || 'Tab' !== event.key) {
			return;
		}

		var focusable = Array.prototype.slice.call(
			modal.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')
		).filter(function(element) {
			return !element.hidden && null !== element.offsetParent;
		});

		if (!focusable.length) {
			return;
		}

		var first = focusable[0];
		var last = focusable[focusable.length - 1];

		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	}

	function sendCustomerEmail(form) {
		var admin = window.yoohwCosAdmin || {};
		var modal = form.closest('.yoohw-cos-email-composer');
		var submit = form.querySelector('[data-yoohw-cos-email-submit]');
		var messageField = form.querySelector('[name="email_message"]');
		var errorText = admin.emailSendErrorText || 'The email could not be sent. Please try again.';

		if ('true' === form.getAttribute('aria-busy')) {
			return;
		}

		if (!window.fetch || !window.FormData || !admin.ajaxUrl || !submit) {
			setEmailComposerStatus(modal, 'error', errorText);
			return;
		}

		var originalText = submit.textContent;
		submit.disabled = true;
		submit.textContent = submit.getAttribute('data-sending-text') || originalText;
		form.setAttribute('aria-busy', 'true');
		setEmailComposerStatus(modal, '', '');

		window.fetch(admin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: new window.FormData(form)
		})
		.then(function(response) {
			return response.json();
		})
		.then(function(response) {
			var message = response && response.data && response.data.message ? response.data.message : errorText;

			if (!response || !response.success) {
				throw new Error(message);
			}

			if (messageField) {
				messageField.value = '';
			}

			setEmailComposerStatus(modal, 'success', message);
		})
		.catch(function(error) {
			setEmailComposerStatus(modal, 'error', error && error.message ? error.message : errorText);
		})
		.then(function() {
			submit.disabled = false;
			submit.textContent = originalText;
			form.removeAttribute('aria-busy');
		});
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
		if (!container) {
			return;
		}

		var targets = container.querySelectorAll(selector);

		if (!targets.length) {
			return;
		}

		targets.forEach(function(target) {
			target.textContent = value;
		});
	}

	function setSyncProgress(container, percent) {
		if (!container) {
			return;
		}

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
		if (!container) {
			return;
		}

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
		setSyncText(container, '.yoohw-cos-sync-total-skipped', formatNumber(state.totalSkipped));
		setSyncText(container, '.yoohw-cos-sync-total-orders', state.totalOrders > 0 ? formatNumber(state.totalOrders) : '—');
		setSyncText(container, '.yoohw-cos-sync-total-items', state.totalItems > 0 ? formatNumber(state.totalItems) : '—');
		setSyncText(container, '.yoohw-cos-sync-resume-page', state.hasMore ? formatNumber(state.nextPage) : '—');
		setSyncText(container, '.yoohw-cos-sync-stage', state.stageLabel || state.stage || '—');
	}

	function runAjaxSync(form) {
		var admin = window.yoohwCosAdmin || {};

		if (!window.fetch || !window.FormData || !admin.ajaxUrl) {
			return false;
		}

		if ('1' === form.getAttribute('data-yoohw-cos-syncing')) {
			return true;
		}

		var container = form.closest('[data-yoohw-cos-sync-container]') || form.closest('[data-yoohw-cos-sync-center]') || form.closest('.yoohw-cos-operation-row');
		var ajaxAction = form.getAttribute('data-yoohw-cos-ajax-action') || 'yoohw_cos_ajax_sync_customers';
		var pageField = form.getAttribute('data-yoohw-cos-sync-page-field') || 'sync_page';
		var stageField = form.getAttribute('data-yoohw-cos-sync-stage-field') || '';
		var pageInput = form.querySelector('input[name="' + pageField + '"]');
		var stageInput = stageField ? form.querySelector('input[name="' + stageField + '"]') : null;
		var nonceInput = form.querySelector('input[name="_wpnonce"]');
		var submit = form.querySelector('input[type="submit"], button[type="submit"]');
		var spinner = form.querySelector('[data-yoohw-cos-sync-spinner]');
		var progress = container ? container.querySelector('[data-yoohw-cos-sync-progress]') : null;
		var startPage = pageInput ? parseInt(pageInput.value, 10) || 1 : 1;
		var runningText = form.getAttribute('data-yoohw-cos-sync-running-text') || admin.syncRunningText || 'Syncing...';
		var completeText = form.getAttribute('data-yoohw-cos-sync-complete-text') || admin.syncCompleteText || 'Sync complete.';
		var errorText = form.getAttribute('data-yoohw-cos-sync-error-text') || admin.syncErrorText || 'Sync could not be completed.';
		var nonce = nonceInput ? nonceInput.value : admin.syncNonce;

		form.setAttribute('data-yoohw-cos-syncing', '1');

		if (submit) {
			submit.disabled = true;
		}

		if (spinner) {
			spinner.classList.add('is-active');
		}

		if (progress) {
			progress.hidden = false;
			progress.classList.add('is-active');
		}

		setSyncText(container, '[data-yoohw-cos-sync-message]', runningText);

		function runPage(page) {
			var data = new FormData(form);
			data.set('action', ajaxAction);
			data.set('nonce', nonce);
			data.set(pageField, page);

			return fetch(admin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			})
				.then(function(response) {
					return response.json()
						.catch(function() {
							throw new Error(errorText);
						});
				})
				.then(function(response) {
					if (!response || !response.success || !response.data) {
						throw new Error(response && response.data && response.data.message ? response.data.message : errorText);
					}

					var result = response.data;
					var state = result.state || {};
					var nextPage = parseInt(result.nextPage, 10) || page + 1;
					var message = result.hasMore ? runningText : completeText;

					if (pageInput) {
						pageInput.value = result.hasMore ? nextPage : 1;
					}

					if (stageInput) {
						stageInput.value = result.hasMore ? (result.nextStage || state.stage || stageInput.value) : 'core';
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
				setSyncText(container, '[data-yoohw-cos-sync-message]', error.message || errorText);
			})
			.finally(function() {
				form.removeAttribute('data-yoohw-cos-syncing');

				if (submit) {
					submit.disabled = false;
				}

				if (spinner) {
					spinner.classList.remove('is-active');
				}

				if (progress) {
					progress.classList.remove('is-active');
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

	function splitTermNames(value) {
		return value.split(',').map(function(term) {
			return term.trim();
		});
	}

	function getCurrentTermFragment(value) {
		var names = splitTermNames(value);

		return names.length ? names[names.length - 1] : '';
	}

	function setTermInputValue(input, names) {
		input.value = names.length ? names.join(', ') + ', ' : '';
		input.focus();

		if ('function' === typeof input.setSelectionRange) {
			input.setSelectionRange(input.value.length, input.value.length);
		}
	}

	function appendTermName(input, name) {
		var currentNames = splitTermNames(input.value).filter(Boolean);
		var lowerName = name.toLowerCase();
		var exists = currentNames.some(function(value) {
			return value.toLowerCase() === lowerName;
		});

		if (!exists) {
			currentNames.push(name);
		}

		setTermInputValue(input, currentNames);
	}

	function replaceCurrentTermName(input, name) {
		var currentNames = splitTermNames(input.value);
		var lowerName = name.toLowerCase();

		currentNames.pop();

		currentNames = currentNames.filter(Boolean);

		if (!currentNames.some(function(value) {
			return value.toLowerCase() === lowerName;
		})) {
			currentNames.push(name);
		}

		setTermInputValue(input, currentNames);
	}

	function getTermSuggestionSource(input) {
		var raw = input.getAttribute('data-yoohw-cos-term-source') || '[]';

		try {
			var source = JSON.parse(raw);

			return Array.isArray(source) ? source.filter(Boolean) : [];
		} catch (error) {
			return [];
		}
	}

	function initTermSuggestions($) {
		if (!$ || !$.fn || !$.fn.autocomplete) {
			return;
		}

		$('[data-yoohw-cos-term-suggest="1"]').each(function() {
			var input = this;
			var $input = $(input);

			if ($input.data('yoohwCosTermSuggestReady')) {
				return;
			}

			var source = getTermSuggestionSource(input);

			if (!source.length) {
				return;
			}

			$input.data('yoohwCosTermSuggestReady', true);
			$input.autocomplete({
				source: function(request, response) {
					var query = getCurrentTermFragment(request.term).toLowerCase();
					var selectedNames = splitTermNames(request.term).filter(Boolean).map(function(value) {
						return value.toLowerCase();
					});

					if (!query) {
						response([]);
						return;
					}

					response(source.filter(function(name) {
						var lowerName = name.toLowerCase();

						return selectedNames.indexOf(lowerName) === -1 && lowerName.indexOf(query) !== -1;
					}).slice(0, 20).map(function(name, index) {
						return {
							id: input.id + '-' + index,
							label: name,
							name: name,
							value: name
						};
					}));
				},
				focus: function(event, ui) {
					$input.attr('aria-activedescendant', 'wp-tags-autocomplete-' + ui.item.id);
					event.preventDefault();
				},
				select: function(event, ui) {
					replaceCurrentTermName(input, ui.item.name);
					event.preventDefault();
					return false;
				},
				open: function() {
					$input.attr('aria-expanded', 'true');
				},
				close: function() {
					$input.attr('aria-expanded', 'false');
				},
				minLength: 1,
				position: {
					my: 'left top+2',
					at: 'left bottom',
					collision: 'none'
				}
			});

			if (!$input.autocomplete('instance')) {
				return;
			}

			$input.autocomplete('instance')._renderItem = function(ul, item) {
				return $('<li role="option" id="wp-tags-autocomplete-' + item.id + '">')
					.text(item.name)
					.appendTo(ul);
			};

			$input.attr({
				'role': 'combobox',
				'aria-autocomplete': 'list',
				'aria-expanded': 'false',
				'aria-owns': $input.autocomplete('widget').attr('id')
			})
			.on('keydown', function() {
				$input.removeAttr('aria-activedescendant');
			})
			.on('focus', function() {
				if (getCurrentTermFragment(input.value)) {
					$input.autocomplete('search');
				}
			});

			$input.autocomplete('widget')
				.addClass('wp-tags-autocomplete')
				.attr('role', 'listbox')
				.removeAttr('tabindex')
				.on('menufocus', function(event, ui) {
					ui.item.attr('aria-selected', 'true');
				})
				.on('menublur', function() {
					$(this).find('[aria-selected="true"]').removeAttr('aria-selected');
				});
		});
	}

	ready(function() {
		updateBulkTargets();
		initTaskSearchableSelects();
		initTermSuggestions(window.jQuery);
		restoreScrollPosition();

		document.addEventListener('change', function(event) {
			if (event.target.matches('select[name="action"], select[name="action2"]')) {
				clearOtherBulkActions(event.target);
				updateBulkTargets(event.target);
			}
		});

		document.querySelectorAll('[data-yoohw-cos-auto-submit="1"]').forEach(function(form) {
			setTimeout(function() {
				if (typeof form.requestSubmit === 'function') {
					form.requestSubmit();
					return;
				}

				var event = new Event('submit', {
					bubbles: true,
					cancelable: true
				});

				if (form.dispatchEvent(event)) {
					form.submit();
				}
			}, 800);
		});

		document.addEventListener('submit', function(event) {
			var emailForm = event.target.closest('[data-yoohw-cos-email-form]');

			if (emailForm) {
				event.preventDefault();
				sendCustomerEmail(emailForm);
				return;
			}

			var syncForm = event.target.closest('form[data-yoohw-cos-ajax-sync="1"]');

			if (syncForm && runAjaxSync(syncForm)) {
				event.preventDefault();
			}
		});

		document.addEventListener('submit', function(event) {
			if (event.target.closest('.yoohw-cos-term-box-form')) {
				saveScrollPosition();
			}
		});

		document.addEventListener('submit', function(event) {
			var target = event.target.closest('[data-yoohw-cos-confirm]');

			if (target && !window.confirm(target.getAttribute('data-yoohw-cos-confirm'))) {
				event.preventDefault();
			}
		});

		document.addEventListener('click', function(event) {
			var emailOpen = event.target.closest('[data-yoohw-cos-email-open]');

			if (emailOpen) {
				event.preventDefault();
				openEmailComposer(emailOpen);
				return;
			}

			var emailClose = event.target.closest('[data-yoohw-cos-email-close]');

			if (emailClose) {
				event.preventDefault();
				closeEmailComposer();
				return;
			}

			var confirmTarget = event.target.closest('[data-yoohw-cos-confirm]');

			var clickableRow = event.target.closest('[data-yoohw-cos-row-url]');

			if (clickableRow && !shouldIgnoreRowClick(event)) {
				openClickableRow(clickableRow, event);
				return;
			}

			if (
				confirmTarget
				&& 'FORM' !== confirmTarget.tagName
				&& !window.confirm(confirmTarget.getAttribute('data-yoohw-cos-confirm'))
			) {
				event.preventDefault();
				return;
			}

			if (confirmTarget && confirmTarget.matches('.yoohw-cos-term-add-form .ntdelbutton')) {
				saveScrollPosition();
			}

			var termCloudToggle = event.target.closest('[data-yoohw-cos-term-cloud-toggle]');

			if (termCloudToggle) {
				event.preventDefault();

				var targetId = termCloudToggle.getAttribute('data-yoohw-cos-term-cloud-target');
				var cloud = targetId ? document.getElementById(targetId) : null;

				if (!cloud) {
					return;
				}

				var isExpanded = 'true' === termCloudToggle.getAttribute('aria-expanded');
				termCloudToggle.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
				cloud.hidden = isExpanded;
				return;
			}

			var termCloudLink = event.target.closest('[data-yoohw-cos-term-name]');

			if (termCloudLink) {
				event.preventDefault();

				var termName = termCloudLink.getAttribute('data-yoohw-cos-term-name');
				var inputId = termCloudLink.getAttribute('data-yoohw-cos-term-input');
				var input = inputId ? document.getElementById(inputId) : null;

				if (termName && input) {
					appendTermName(input, termName);
				}

				return;
			}

			var copyButton = event.target.closest('.yoohw-cos-copy');

			if (copyButton) {
				var value = copyButton.getAttribute('data-copy');

				if (!value || !navigator.clipboard) {
					return;
				}

				navigator.clipboard.writeText(value).then(function() {
					var copiedText = window.yoohwCosAdmin && window.yoohwCosAdmin.copiedText ? window.yoohwCosAdmin.copiedText : 'Copied!';
					var icon = copyButton.querySelector('.dashicons');

					if (copyButton.classList.contains('yoohw-cos-copy-icon') && icon) {
						var originalClassName = icon.className;
						var originalLabel = copyButton.getAttribute('aria-label');

						copyButton.classList.add('is-copied');
						copyButton.setAttribute('aria-label', copiedText);
						icon.className = 'dashicons dashicons-yes';

						setTimeout(function() {
							icon.className = originalClassName;
							copyButton.classList.remove('is-copied');

							if (originalLabel) {
								copyButton.setAttribute('aria-label', originalLabel);
							}
						}, 1200);

						return;
					}

					var original = copyButton.textContent;

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

		document.addEventListener('keydown', function(event) {
			var modal = getEmailComposer();

			if (modal && !modal.hidden) {
				if ('Escape' === event.key) {
					event.preventDefault();
					closeEmailComposer();
					return;
				}

				keepFocusInEmailComposer(event);
			}

			var row = event.target.closest('[data-yoohw-cos-row-url]');

			if (!row || isInteractiveRowTarget(event.target) || ('Enter' !== event.key && ' ' !== event.key)) {
				return;
			}

			event.preventDefault();
			openClickableRow(row, event);
		});
	});
})();
