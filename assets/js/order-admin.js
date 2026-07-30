(function($) {
	'use strict';

	function initYoOhwOrderTooltips() {
		var $tips = $('.yoohw-cos-order-customer-field .woocommerce-help-tip, .yoohw-cos-customer-history .woocommerce-help-tip');

		if (!$tips.length || !$.fn.tipTip) {
			return;
		}

		$('#tiptip_holder').removeAttr('style');
		$('#tiptip_arrow').removeAttr('style');
		$tips.tipTip({
			attribute: 'data-tip',
			fadeIn: 50,
			fadeOut: 50,
			delay: 200,
			keepAlive: true
		});
	}

	function initYoOhwCustomerField() {
		var params = window.yoohwCosOrderAdmin || {};
		var $field = $('#yoohw_cos_customer_id');

		if (!$field.length) {
			return;
		}

		$('.wc-customer-user').addClass('yoohw-cos-native-customer-hidden').hide();

		if (!$field.hasClass('enhanced') && $.fn.selectWoo) {
			$field.selectWoo({
				allowClear: true,
				placeholder: params.placeholderText || $field.data('placeholder') || 'Search YoOhw customer profile',
				minimumInputLength: 1,
				dropdownCssClass: 'yoohw-cos-selectwoo-dropdown',
				language: {
					noResults: function() {
						return params.noResultsText || 'No customer profiles found';
					}
				},
				ajax: {
					url: params.ajaxUrl || window.ajaxurl,
					dataType: 'json',
					delay: 250,
					data: function(request) {
						return {
							action: 'yoohw_cos_json_search_customers',
							security: params.searchNonce,
							term: request.term
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
	}

	function disableRegisteredCustomerOrderFilter() {
		var $filters = $('select.wc-customer-search[name="_customer_user"]');

		if (!$filters.length || !$('.yoohw-cos-order-list-customer-search').length) {
			return;
		}

		$filters.each(function() {
			var $field = $(this);
			var $container = $field.next('.select2-container');

			$field.prop('disabled', true).addClass('yoohw-cos-registered-customer-filter-hidden').hide();
			$container.addClass('yoohw-cos-registered-customer-filter-hidden').hide();
		});
	}

	function initYoOhwOrderListCustomerFilter() {
		var params = window.yoohwCosOrderAdmin || {};
		var $fields = $('.yoohw-cos-order-list-customer-search');

		if (!$fields.length) {
			return;
		}

		disableRegisteredCustomerOrderFilter();

		if (!$.fn.selectWoo) {
			return;
		}

		$fields.each(function() {
			var $field = $(this);

			if ($field.hasClass('enhanced')) {
				return;
			}

			$field.selectWoo({
				allowClear: true,
				placeholder: params.orderListPlaceholderText || $field.data('placeholder') || 'Filter by customer',
				minimumInputLength: 1,
				dropdownCssClass: 'yoohw-cos-selectwoo-dropdown',
				width: 'resolve',
				language: {
					noResults: function() {
						return params.noResultsText || 'No customer profiles found';
					}
				},
				ajax: {
					url: params.ajaxUrl || window.ajaxurl,
					dataType: 'json',
					delay: 250,
					data: function(request) {
						return {
							action: 'yoohw_cos_json_search_customers',
							security: params.searchNonce,
							term: request.term || '',
							include_archived: '1'
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
		});
	}

	function initYoOhwOrderTaskAssigneeField() {
		var params = window.yoohwCosOrderAdmin || {};
		var $fields = $('.yoohw-cos-task-assignee-search');

		if (!$fields.length || !$.fn.selectWoo) {
			return;
		}

		$fields.each(function() {
			var $field = $(this);

			if ($field.hasClass('enhanced')) {
				return;
			}

			$field.selectWoo({
				placeholder: params.assigneePlaceholderText || $field.data('placeholder') || 'Search assignee',
				minimumInputLength: 0,
				dropdownCssClass: 'yoohw-cos-selectwoo-dropdown',
				width: 'resolve',
				language: {
					noResults: function() {
						return params.assigneeNoResultsText || 'No assignable users found';
					}
				},
				ajax: {
					url: params.ajaxUrl || window.ajaxurl,
					dataType: 'json',
					delay: 250,
					data: function(request) {
						return {
							action: 'yoohw_cos_json_search_assignable_users',
							security: params.assigneeSearchNonce,
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
		});
	}
	$(function() {
		initYoOhwCustomerField();
		initYoOhwOrderListCustomerFilter();
		initYoOhwOrderTaskAssigneeField();
		initYoOhwOrderTooltips();
	});
	$(document.body).on('wc-enhanced-select-init', function() {
		initYoOhwCustomerField();
		initYoOhwOrderListCustomerFilter();
		initYoOhwOrderTaskAssigneeField();
		initYoOhwOrderTooltips();
	});
})(jQuery);
