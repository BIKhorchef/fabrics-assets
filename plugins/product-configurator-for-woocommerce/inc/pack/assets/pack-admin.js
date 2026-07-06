(function ($) {
	'use strict';

	$(function () {
		var $checkbox  = $('#_mkl_pc_is_pack');
		var $wrap      = $('.mkl-pc-pack-slots-wrap');
		var $slotsList = $wrap.find('.mkl-pc-pack-slots-list');
		var slotTpl    = wp.template ? wp.template('mkl-pc-pack-slot') : null;
		var rowTpl     = wp.template ? wp.template('mkl-pc-pack-option-row') : null;

		$checkbox.on('change', function () {
			$wrap.toggle(this.checked);
		});

		// Parent pack post_id — drives the language scope on the server side so
		// Polylang returns options in the same language as the pack being edited.
		var packPostId = (function () {
			var m = window.location.search.match(/[?&]post=(\d+)/);
			return m ? parseInt(m[1], 10) : 0;
		})();

		// ── selectWoo on every product-search dropdown (existing + new ones) ──
		function initSelect2($select) {
			if (!$.fn.selectWoo) return;
			$select.selectWoo({
				ajax: {
					url: MKL_PC_PACK_ADMIN.ajax_url,
					dataType: 'json',
					delay: 250,
					data: function (params) {
						return {
							action: 'mkl_pc_pack_search_configurable_products',
							nonce: MKL_PC_PACK_ADMIN.nonce,
							q: params.term || '',
							post_id: packPostId
						};
					},
					processResults: function (data) {
						return { results: data.results || [] };
					},
					cache: true
				},
				minimumInputLength: 0,
				placeholder: $select.data('placeholder') || '',
				allowClear: true,
				escapeMarkup: function (m) { return m; },
				language: {
					noResults: function () { return MKL_PC_PACK_ADMIN.i18n.no_results; },
					searching: function () { return MKL_PC_PACK_ADMIN.i18n.searching; }
				}
			});
		}

		$wrap.find('.mkl-pc-pack-add-option-select').each(function () {
			initSelect2($(this));
		});

		// ── Slot-level sortable + reindexing ─────────────────────────────────
		// CRITICAL: each option has 3 fields (product_id, label, price). They
		// MUST share the same option index, otherwise PHP receives them as
		// three separate array entries and labels/prices are silently lost.
		function reindexSlots() {
			$slotsList.children('.mkl-pc-pack-slot').each(function (slotIdx) {
				var $slot = $(this);
				$slot.attr('data-slot-index', slotIdx);

				$slot.find('input.mkl-pc-pack-slot-label').attr('name', 'mkl_pc_pack_slots[' + slotIdx + '][label]');

				$slot.find('.mkl-pc-pack-options-list > tr').each(function (optIdx) {
					var $row = $(this);
					var base = 'mkl_pc_pack_slots[' + slotIdx + '][options][' + optIdx + ']';
					$row.find('.mkl-pc-pack-option-pid').attr('name',   base + '[product_id]');
					$row.find('.mkl-pc-pack-option-label').attr('name', base + '[label]');
					$row.find('.mkl-pc-pack-option-price').attr('name', base + '[price]');
				});
			});
		}

		if ($.fn.sortable) {
			$slotsList.sortable({
				handle: '.mkl-pc-pack-slot-handle',
				axis: 'y',
				items: '> .mkl-pc-pack-slot',
				update: reindexSlots
			});
		}

		// ── Add slot ─────────────────────────────────────────────────────────
		$wrap.on('click', '.mkl-pc-pack-add-slot', function (e) {
			e.preventDefault();
			if (!slotTpl) return;
			var nextIndex = $slotsList.children('.mkl-pc-pack-slot').length;
			var $newSlot  = $(slotTpl({ index: nextIndex }));
			$slotsList.append($newSlot);
			initSelect2($newSlot.find('.mkl-pc-pack-add-option-select'));
			initOptionSortable($newSlot.find('.mkl-pc-pack-options-list'));
			reindexSlots();
		});

		// ── Remove slot ──────────────────────────────────────────────────────
		$slotsList.on('click', '.mkl-pc-pack-slot-remove', function (e) {
			e.preventDefault();
			$(this).closest('.mkl-pc-pack-slot').remove();
			reindexSlots();
		});

		// ── Option-level sortable per slot ───────────────────────────────────
		function initOptionSortable($tbody) {
			if (!$.fn.sortable) return;
			$tbody.sortable({
				handle: '.mkl-pc-pack-option-handle',
				axis: 'y',
				items: '> tr',
				update: reindexSlots
			});
		}

		$slotsList.find('.mkl-pc-pack-options-list').each(function () {
			initOptionSortable($(this));
		});

		// ── Add option to a slot ─────────────────────────────────────────────
		$slotsList.on('click', '.mkl-pc-pack-add-option-btn', function (e) {
			e.preventDefault();
			if (!rowTpl) return;

			var $slot   = $(this).closest('.mkl-pc-pack-slot');
			var $select = $slot.find('.mkl-pc-pack-add-option-select');
			var data    = $select.select2('data');
			if (!data || !data.length || !data[0].id) {
				alert(MKL_PC_PACK_ADMIN.i18n.choose);
				return;
			}
			var picked    = data[0];
			var slotIndex = $slot.attr('data-slot-index') || 0;

			if ($slot.find('.mkl-pc-pack-options-list tr[data-product-id="' + picked.id + '"]').length) {
				alert(MKL_PC_PACK_ADMIN.i18n.already_in);
				return;
			}

			var optIndex = $slot.find('.mkl-pc-pack-options-list > tr').length;
			var $row = $(rowTpl({
				slot_index: slotIndex,
				opt_index: optIndex,
				product_id: picked.id,
				title: picked.text.replace(/\s\(#\d+\)$/, '')
			}));
			$slot.find('.mkl-pc-pack-options-list').append($row);
			$select.val(null).trigger('change');
			reindexSlots();
		});

		// ── Remove option ────────────────────────────────────────────────────
		$slotsList.on('click', '.mkl-pc-pack-option-remove', function (e) {
			e.preventDefault();
			$(this).closest('tr').remove();
			reindexSlots();
		});
	});
})(jQuery);
