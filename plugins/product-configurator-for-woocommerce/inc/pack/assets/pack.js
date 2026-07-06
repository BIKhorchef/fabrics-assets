/**
 * MKL PC Pack — front-end glue (chip-based variant selector).
 *
 * Loaded ONLY on a pack product page.
 *
 * Design:
 *   - Each slot renders as ONE card showing the currently-selected option's
 *     image, name, configured-status, and Configure button.
 *   - When a slot has multiple options, a row of "+ Label" chips appears
 *     above the card. Clicking a chip switches which product the card
 *     represents.
 *   - Saved configurations are kept per-variant in memory. Switching variants
 *     does NOT destroy the previous variant's config — switching back
 *     restores it.
 *
 * State model:
 *   state.slots[i] = {
 *     selectedProductId: <int>,        // currently picked variant
 *     configBySelection: { <pid>: <json> },
 *     activeProductId: <int|null>      // product currently open in modal
 *   }
 *   state.activeSlotIndex = <int|null> // slot whose configurator is open
 */
(function ($) {
	'use strict';

	if (typeof window.MKL_PC_PACK === 'undefined') {
		return;
	}

	var state = {
		slots: {},
		activeSlotIndex: null
	};

	function initState() {
		$('.mkl-pc-pack-slot-card').each(function () {
			var $card = $(this);
			var idx   = parseInt($card.attr('data-slot-index'), 10);
			var pid   = parseInt($card.attr('data-selected-product-id'), 10) || 0;
			state.slots[idx] = {
				selectedProductId: pid,
				configBySelection: {},
				activeProductId: null
			};
		});
	}

	// ─── Currency formatting ───────────────────────────────────────────────────
	function formatMoney(value) {
		var fmt   = MKL_PC_PACK.currency_format || {};
		var prec  = typeof fmt.precision === 'number' ? fmt.precision : 2;
		var num   = Number(value || 0).toFixed(prec);
		var parts = num.split('.');
		parts[0]  = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, fmt.thousands || ' ');
		var formatted = parts.join(fmt.decimals || ',');
		var sym = fmt.symbol || '';
		switch (fmt.position) {
			case 'left':         return sym + formatted;
			case 'right':        return formatted + sym;
			case 'left_space':   return sym + ' ' + formatted;
			case 'right_space':  return formatted + ' ' + sym;
			default:             return formatted + ' ' + sym;
		}
	}

	function computeTotal() {
		if (!MKL_PC_PACK.summed_pricing) {
			return MKL_PC_PACK.fallback_price || 0;
		}
		var total = 0;
		Object.keys(MKL_PC_PACK.slots || {}).forEach(function (idx) {
			var slotConfig = MKL_PC_PACK.slots[idx];
			var picked     = state.slots[idx] ? state.slots[idx].selectedProductId : null;
			if (!picked) return;
			var opt = (slotConfig.options || []).find(function (o) { return o.product_id === picked; });
			if (opt && typeof opt.price === 'number') {
				total += opt.price;
			}
		});
		return total;
	}

	// ─── Theme price sync ──────────────────────────────────────────────────────
	// The pack's own "regular_price" is often left at 0 so the customer sees the
	// summed total instead. Themes render that 0 in their own price area (top
	// of the product page). We update those elements when chips change so the
	// top price tracks the bottom total.

	// Build HTML that matches WooCommerce's wc_price() output exactly so theme
	// styling on .woocommerce-Price-currencySymbol etc. keeps working.
	function buildPriceHtml(totalNumber) {
		var fmt  = MKL_PC_PACK.currency_format || {};
		var prec = typeof fmt.precision === 'number' ? fmt.precision : 2;
		var num  = Number(totalNumber || 0).toFixed(prec);
		var parts = num.split('.');
		parts[0]  = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, fmt.thousands || ' ');
		var numFormatted = parts.join(fmt.decimals || ',');
		var sym = fmt.symbol || '';
		var symHtml = '<span class="woocommerce-Price-currencySymbol">' + sym + '</span>';

		var inner;
		switch (fmt.position) {
			case 'left':         inner = symHtml + numFormatted; break;
			case 'right':        inner = numFormatted + symHtml; break;
			case 'left_space':   inner = symHtml + '&nbsp;' + numFormatted; break;
			case 'right_space':  inner = numFormatted + '&nbsp;' + symHtml; break;
			default:             inner = numFormatted + '&nbsp;' + symHtml;
		}
		return '<span class="woocommerce-Price-amount amount"><bdi>' + inner + '</bdi></span>';
	}

	function updateThemePrice(totalNumber) {
		var priceHtml = buildPriceHtml(totalNumber);

		// Common WC + ecomus + Storefront + Astra + Botiga selectors. We skip
		// anything inside our own pack UI to avoid double-updating the bottom
		// total bar.
		var selectors = [
			'.ecomus-product-price .price',
			'.ecomus-product-price p.price',
			'.ecomus-product-price',
			'.summary .price',
			'.product-info .price',
			'.product-info p.price',
			'.product-info-price',
			'.tf-product-info-price',
			'.tf-product-info-price-wrap .price',
			'.product_meta + .price',
			'.entry-summary > .price'
		].join(',');

		$(selectors).each(function () {
			var $el = $(this);
			if ($el.closest('.mkl-pc-pack').length) return;
			// If the element itself isn't a <p class="price"> but contains one,
			// update the inner <p class="price"> rather than nuking the wrapper.
			var $inner = $el.is('p.price, .price') ? $el : $el.find('p.price, .price').first();
			if (!$inner.length) $inner = $el;
			$inner.html(priceHtml);
		});
	}

	function refreshTotal() {
		var total = computeTotal();
		$('.mkl-pc-pack-total-value').html(formatMoney(total));
		if (MKL_PC_PACK.summed_pricing) {
			updateThemePrice(total);
		}
	}

	// ─── Slot card visual state ────────────────────────────────────────────────
	function refreshSlotStatus(slotIndex) {
		if (slotIndex === null || typeof slotIndex === 'undefined') return;

		var $card = $('.mkl-pc-pack-slot-card[data-slot-index="' + slotIndex + '"]');
		if (!$card.length) return;

		var slot = state.slots[slotIndex];
		if (!slot) return;

		var configured = !!slot.configBySelection[slot.selectedProductId];

		$card.attr('data-status', configured ? 'done' : 'pending');
		$card.find('.mkl-pc-pack-card-status-pending').toggle(!configured);
		$card.find('.mkl-pc-pack-card-status-done').toggle(configured);

		var $btn = $card.find('.mkl-pc-pack-configure-btn');
		$btn.text(
			configured
				? (MKL_PC_PACK.i18n.reconfigure_button || 'Modify')
				: (MKL_PC_PACK.i18n.configure_button || 'Configure')
		);

		$card.find('.mkl-pc-pack-slot-pick').val(slot.selectedProductId || '');
		$card.find('.mkl-pc-pack-slot-config').val(
			configured ? slot.configBySelection[slot.selectedProductId] : ''
		);
	}

	function isEverythingConfigured() {
		var keys = Object.keys(state.slots);
		if (keys.length === 0) return false;
		for (var i = 0; i < keys.length; i++) {
			var slot = state.slots[keys[i]];
			if (!slot || !slot.selectedProductId) return false;
			if (!slot.configBySelection[slot.selectedProductId]) return false;
		}
		return true;
	}

	function refreshSubmit() {
		var allDone = isEverythingConfigured();
		var $submit = $('.mkl-pc-pack-form .mkl-pc-pack-submit');
		$submit.prop('disabled', !allDone);
		// Belt-and-braces: many themes strip [disabled] or override its styling.
		// A dedicated class + inline fallback styles guarantee the button looks
		// disabled even when the attribute is bypassed.
		$submit.toggleClass('is-disabled', !allDone);
		if (allDone) {
			$submit.removeAttr('title');
			$submit.css({
				'background-color': '',
				'color': '',
				'cursor': '',
				'pointer-events': ''
			});
		} else {
			$submit.attr('title', MKL_PC_PACK.i18n.add_to_cart_disabled_title || '');
			$submit.css({
				'background-color': '#cfcfcf',
				'color': '#6e6e6e',
				'cursor': 'not-allowed',
				'pointer-events': 'none'
			});
		}
	}

	function refreshAll() {
		Object.keys(state.slots).forEach(refreshSlotStatus);
		refreshTotal();
		refreshSubmit();
	}

	// ─── Defensive: clear any partial in-flight state ──────────────────────────
	function clearActive() {
		if (state.activeSlotIndex !== null && state.slots[state.activeSlotIndex]) {
			state.slots[state.activeSlotIndex].activeProductId = null;
		}
		state.activeSlotIndex = null;
		$('body').removeClass('mkl-pc-pack-modal-active');
	}

	// ─── Variant chip click ────────────────────────────────────────────────────
	$(document).on('click', '.mkl-pc-pack-chip', function (e) {
		e.preventDefault();
		var $chip     = $(this);
		var $card     = $chip.closest('.mkl-pc-pack-slot-card');
		var slotIndex = parseInt($card.attr('data-slot-index'), 10);
		var pickedPid = parseInt($chip.attr('data-product-id'), 10);

		if (!state.slots[slotIndex]) return;

		var prev = state.slots[slotIndex].selectedProductId;
		if (prev === pickedPid) return;

		// We keep configs per-variant in memory — no destructive prompt needed.
		// Switching back to a variant restores its saved configuration.
		state.slots[slotIndex].selectedProductId = pickedPid;
		$card.attr('data-selected-product-id', pickedPid);

		// Toggle chip selected state.
		$card.find('.mkl-pc-pack-chip').each(function () {
			var $c       = $(this);
			var matches  = parseInt($c.attr('data-product-id'), 10) === pickedPid;
			$c.toggleClass('is-selected', matches);
			$c.attr('aria-pressed', matches ? 'true' : 'false');
		});

		// Swap the card image + name to the new variant.
		var imgUrl = $chip.attr('data-image-url') || '';
		var name   = $chip.attr('data-name') || '';
		var $img   = $card.find('.mkl-pc-pack-card-img');
		if (imgUrl) {
			if ($img.is('img')) {
				$img.attr('src', imgUrl);
			} else {
				$img.replaceWith('<img class="mkl-pc-pack-card-img" src="' + imgUrl + '" alt="" />');
			}
		}
		$card.find('.mkl-pc-pack-card-name').text(name);

		refreshSlotStatus(slotIndex);
		refreshTotal();
		refreshSubmit();
	});

	// ─── Configure → open MKL modal ────────────────────────────────────────────
	function findHiddenTrigger(productId) {
		return $('.mkl-pc-pack-hidden-triggers .mkl-pc-pack-trigger-' + productId).first();
	}

	$(document).on('click', '.mkl-pc-pack-configure-btn', function (e) {
		e.preventDefault();
		var slotIndex = parseInt($(this).attr('data-slot-index'), 10);
		var slot = state.slots[slotIndex];
		if (!slot || !slot.selectedProductId) return;

		var $trigger = findHiddenTrigger(slot.selectedProductId);
		if (!$trigger.length) {
			console.warn('[mkl-pc-pack] No hidden trigger for product', slot.selectedProductId);
			return;
		}

		// Reset any stale in-flight state from a prior modal that didn't close cleanly.
		clearActive();

		state.activeSlotIndex = slotIndex;
		slot.activeProductId  = slot.selectedProductId;
		$('body').addClass('mkl-pc-pack-modal-active');

		$trigger.trigger('click');
	});

	// ─── Capture configuration on save (FIXED ORDER) ───────────────────────────
	function captureForActiveSlot() {
		if (state.activeSlotIndex === null) return false;
		var slot = state.slots[state.activeSlotIndex];
		if (!slot || !slot.activeProductId) return false;

		var serialized = '';
		if (typeof PC !== 'undefined' && PC.fe && PC.fe.save_data && typeof PC.fe.save_data.save === 'function') {
			serialized = PC.fe.save_data.save();
		}
		if (!serialized) {
			serialized = $('input[name="pc_configurator_data"]').val() || '';
		}
		if (!serialized) {
			return false;
		}

		// IMPORTANT: capture the index BEFORE calling modal.close() — closing the
		// modal fires PC.fe.close which our hook uses to reset state.activeSlotIndex.
		// If we read state.activeSlotIndex after close(), it would already be null
		// and the slot card would never be refreshed (the original bug).
		var capturedSlot = state.activeSlotIndex;
		var capturedPid  = slot.activeProductId;

		// Persist the config for this slot+variant.
		slot.configBySelection[capturedPid] = serialized;

		// Close the modal (close handler will null out active state).
		if (typeof PC !== 'undefined' && PC.fe && PC.fe.modal && typeof PC.fe.modal.close === 'function') {
			try { PC.fe.modal.close(); } catch (err) { /* noop */ }
		}

		// Defensive cleanup in case PC.fe.close didn't fire for any reason.
		clearActive();

		refreshSlotStatus(capturedSlot);
		refreshTotal();
		refreshSubmit();
		return true;
	}

	// ─── Preload saved config on modal open when Modify-ing ────────────────────
	function maybePreloadSavedConfig() {
		if (state.activeSlotIndex === null) return;
		var slot = state.slots[state.activeSlotIndex];
		if (!slot || !slot.activeProductId) return;

		var saved = slot.configBySelection[slot.activeProductId];
		if (!saved) return;

		try {
			var parsed = JSON.parse(saved);
			if (!Array.isArray(parsed)) return;
			// Defer to next tick so the configurator's own start handlers run first.
			setTimeout(function () {
				if (typeof PC !== 'undefined' && PC.fe && typeof PC.fe.setConfig === 'function') {
					try { PC.fe.setConfig(parsed); } catch (err) { /* noop */ }
				}
			}, 50);
		} catch (e) {
			/* malformed saved config — fall back to fresh modal */
		}
	}

	// ─── Suppress theme's stand-alone add-to-cart ──────────────────────────────
	function removeThemeAddToCartForms() {
		$('form.cart').not('.mkl-pc-pack-form').each(function () { $(this).remove(); });
		$('button[name="add-to-cart"], .single_add_to_cart_button')
			.not('.mkl-pc-pack-submit')
			.each(function () {
				var $btn = $(this);
				var $wrap = $btn.closest('.product-form, .product-quantity-action, .tf-product-info-buy-button');
				if ($wrap.length) $wrap.remove(); else $btn.remove();
			});
	}

	// Safety net: even if the disabled attribute is bypassed somehow, the form
	// will not submit unless every slot has a saved configuration.
	$(document).on('submit', '.mkl-pc-pack-form', function (e) {
		if (!isEverythingConfigured()) {
			e.preventDefault();
			e.stopImmediatePropagation();
			alert(MKL_PC_PACK.i18n.add_to_cart_disabled_title || 'Please configure all slots first.');
			return false;
		}
	});

	// Robust cleanup on page hide / navigate away (best-effort).
	$(window).on('beforeunload pagehide', clearActive);

	function init() {
		initState();
		refreshTotal();
		removeThemeAddToCartForms();
		setTimeout(removeThemeAddToCartForms, 250);
		setTimeout(removeThemeAddToCartForms, 1000);

		if (typeof wp === 'undefined' || !wp.hooks) {
			console.warn('[mkl-pc-pack] wp.hooks not available — pack intercept disabled');
			return;
		}

		// Fires once the configurator modal has rendered for the picked product.
		// Used to pre-populate previously-saved choices when the user clicks "Modify".
		wp.hooks.addAction('PC.fe.open', 'mkl/pc/pack/preload', function () {
			if (state.activeSlotIndex !== null) {
				maybePreloadSavedConfig();
			}
		});

		// Fires after MKL has validated the form and populated pc_configurator_data.
		wp.hooks.addAction('PC.fe.add_to_cart.before', 'mkl/pc/pack', function () {
			if (state.activeSlotIndex !== null) {
				captureForActiveSlot();
			}
		});

		// Block MKL's own form submit on the pack page — pack.js owns add-to-cart here.
		wp.hooks.addFilter('PC.fe.trigger_add_to_cart', 'mkl/pc/pack', function () {
			return false;
		});

		// Reset state on modal close (X button, programmatic close, etc.).
		wp.hooks.addAction('PC.fe.close', 'mkl/pc/pack', clearActive);

		refreshAll();
	}

	$(init);
})(jQuery);
