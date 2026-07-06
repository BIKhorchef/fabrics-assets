/**
 * Option Selector — Frontend
 *
 * Mirrors the attribute-layer pattern: choices for an option-selector layer
 * are produced server-side and live in the configurator's content collection.
 * This file:
 *   - Wires up click selection (the core only handles 'simple'/'multiple').
 *   - Emits `mkl_pc.option_selector.changed` on selection so downstream
 *     attribute layers can filter their visible terms.
 *   - Tracks selections in PC.fe.optionSelections and posts them to the
 *     server as `pc_option_selections` on add-to-cart.
 *   - Subscribes to the same event itself to filter attribute-layer choices
 *     against the visibility map provided by PHP (mkl_pc_data.option_selector_visibility_map).
 */
(function ($, _) {
	'use strict';

	if (typeof PC === 'undefined') return;

	PC.fe = PC.fe || {};
	PC.fe.optionSelections = PC.fe.optionSelections || {};

	var LAYER_TYPE = 'option-selector';

	/**
	 * Currently active visibility rules keyed by target attribute layer id.
	 * Updated each time an option_selector layer fires a 'changed' event.
	 * Read by `applyDomFilterToLayer()` whenever an attribute layer is rendered
	 * (so freshly built choice DOM gets the right hidden classes).
	 */
	var ACTIVE_RULES = {}; // { '<attribute_layer_id>': rule }

	function getVisibilityMap() {
		// Configurator data is stored at PC.productData['prod_' + id] by the
		// cached config file (see inc/cache.php save_config_file()).
		try {
			if (PC.fe && PC.fe.currentProductData && PC.fe.currentProductData.option_selector_visibility_map) {
				return PC.fe.currentProductData.option_selector_visibility_map;
			}
			if (PC.productData) {
				var keys = Object.keys(PC.productData);
				for (var i = 0; i < keys.length; i++) {
					var d = PC.productData[keys[i]];
					if (d && d.option_selector_visibility_map) return d.option_selector_visibility_map;
				}
			}
			// Legacy fallback paths kept for safety.
			if (window.mkl_pc_data && window.mkl_pc_data.option_selector_visibility_map) {
				return window.mkl_pc_data.option_selector_visibility_map;
			}
		} catch (e) {}
		return {};
	}

	function isOptionSelectorLayer(layer) {
		if (!layer || typeof layer.get !== 'function') return false;
		return layer.get('type') === LAYER_TYPE || !!layer.get('is_option_selector_layer');
	}

	function compoundKeyFromChoice(model) {
		if (!model) return '';
		var ck = model.get('os_compound_key');
		if (ck) return ck;
		var opt = model.get('os_option_id');
		var sub = model.get('os_sub_option_id');
		if (!opt) return '';
		return sub ? (opt + '.' + sub) : opt;
	}

	/**
	 * Decorate the layer DOM + choices container with our identifiers and
	 * give group headers a distinct class so CSS can render them differently.
	 */
	wp.hooks.addAction('PC.fe.layer.beforeRenderChoices', 'mkl/option-selector', function (view) {
		if (!view || !view.model) return;
		if (!isOptionSelectorLayer(view.model)) return;

		var layer = view.model;
		var content = (PC.fe.getLayerContent ? PC.fe.getLayerContent(layer.id) : null);
		if (!content || !content.length) return;

		// Mirror attribute-layer's pattern: the core configurator only builds
		// the choices view for 'simple' types. Build it manually here.
		view.choices = new PC.fe.views.choices({ content: content, model: layer });
		if (view.choices && view.choices.$el) {
			view.choices.$el.addClass('pc-choices--option-selector type-' + LAYER_TYPE);
			view.choices.$el.addClass('display-style--' + (layer.get('os_display_style') || 'buttons'));
		}
		layer._layerView = view;
	}, 5);

	wp.hooks.addAction('PC.fe.layer.render', 'mkl/option-selector', function (view) {
		if (!view || !view.model) return;
		if (!isOptionSelectorLayer(view.model)) return;
		view.$el.addClass('pc-layer--option-selector');
		view.$el.attr('data-layer-type', LAYER_TYPE);
		view.model._layerView = view;
	});

	wp.hooks.addAction('PC.fe.configurator.choice-item.render', 'mkl/option-selector', function (view) {
		if (!view || !view.model) return;
		var model = view.model;
		var layer = model.collection && model.collection.layer;
		if (!layer || !isOptionSelectorLayer(layer)) return;

		view.$el.addClass('mkl-pc-os-choice');
		if (model.get('is_group')) {
			view.$el.addClass('is-os-group');
		}

		// Replace the rendered inner content with our compact card UI.
		var $item = view.$('.choice-item');
		if (!$item.length || view.$el.hasClass('mkl-pc-os-restructured')) return;

		view.$el.addClass('mkl-pc-os-restructured');

		var name  = model.attributes.name || model.get('name') || '';
		var img   = model.get('os_image') || {};
		var price = parseFloat(model.get('os_price') || 0);

		var html = '';
		if (img && img.url) {
			html += '<img class="mkl-pc-os-thumb" src="' + img.url + '" alt="" />';
		}
		html += '<span class="mkl-pc-os-meta">';
		html += '<span class="mkl-pc-os-name"></span>';
		if (price > 0) {
			html += '<span class="mkl-pc-os-price">+ ' + price.toFixed(2) + '</span>';
		}
		html += '</span>';
		$item.empty().append(html);
		$item.find('.mkl-pc-os-name').text(name);

		if (model.get('active')) view.$el.addClass('active');
		model.on('change:active', function () {
			view.$el.toggleClass('active', !!model.get('active'));
		});
	});

	/**
	 * Click selection — core only handles 'simple'/'multiple', so wire it up
	 * the same way attribute-layer does.
	 */
	$(document).on('mousedown', '.pc-layer--option-selector .choice .choice-item, .pc-choices--option-selector .choice .choice-item', function (e) {
		if (e.button !== 0) return;
		var $choice = $(this).closest('.choice');
		var view = $choice.data('view');
		if (!view || !view.model) return;
		var model = view.model;
		var layer = model.collection && model.collection.layer;
		if (!layer || !isOptionSelectorLayer(layer)) return;
		if (model.get('is_group')) return; // headers aren't selectable

		e.stopImmediatePropagation();

		// Single-selection inside the layer: deactivate siblings.
		model.collection.each(function (c) {
			if (c.get('active')) c.set('active', false);
		});
		model.set('active', true);
		layer.set('selectedChoice', model.id);

		wp.hooks.doAction('PC.fe.choice.set_choice', model, view);
		wp.hooks.doAction('PC.fe.choice.change', model);
	});

	/**
	 * Track selections for cart submission AND fire the visibility filter event.
	 */
	wp.hooks.addAction('PC.fe.choice.set_choice', 'mkl/option-selector', function (model, view) {
		if (!model) return;
		var layer = model.collection && model.collection.layer;
		if (!layer || !isOptionSelectorLayer(layer)) return;

		var layerId = layer.id || layer.get('_id');
		var key     = compoundKeyFromChoice(model);
		var optId   = model.get('os_option_id') || '';
		var subId   = model.get('os_sub_option_id') || '';
		var optLbl  = optId;
		var subLbl  = '';
		var name    = model.attributes.name || model.get('name') || '';

		if (subId) {
			subLbl = name;
			// Try to recover the option label from the parent group header
			var parentId = model.get('parent');
			if (parentId && model.collection) {
				var parent = model.collection.get(parentId);
				if (parent) optLbl = parent.attributes.name || parent.get('name') || optLbl;
			}
		} else {
			optLbl = name;
		}

		PC.fe.optionSelections[layerId] = {
			layer_id:         layerId,
			layer_label:      layer.get('name') || '',
			option_id:        optId,
			option_label:     optLbl,
			sub_option_id:    subId,
			sub_option_label: subLbl,
			price:            parseFloat(model.get('os_price') || 0)
		};

		var rules = {};
		var map   = getVisibilityMap();
		if (map && map[layerId] && map[layerId][key]) {
			rules = map[layerId][key];
		}

		wp.hooks.doAction('mkl_pc.option_selector.changed', {
			layerId:   layerId,
			optionKey: key,
			rules:     rules
		});
	}, 5);

	/**
	 * Initialise from default-active choices on configurator start.
	 */
	wp.hooks.addAction('PC.fe.start', 'mkl/option-selector', function () {
		PC.fe.optionSelections = {};
		if (!PC.fe || !PC.fe.layers) return;

		PC.fe.layers.each(function (layer) {
			if (!isOptionSelectorLayer(layer)) return;
			var content = PC.fe.getLayerContent ? PC.fe.getLayerContent(layer.id) : null;
			if (!content) return;
			var active = content.findWhere ? content.findWhere({ active: true }) : null;
			if (!active) return;
			// Trigger the same code-path as a click would, just without DOM events.
			wp.hooks.doAction('PC.fe.choice.set_choice', active, null);
		});
	}, 25);

	wp.hooks.addAction('PC.fe.reset_configurator', 'mkl/option-selector', function () {
		PC.fe.optionSelections = {};
		ACTIVE_RULES = {};
	});

	/**
	 * Visibility filter: subscribe to our own event and update sibling
	 * attribute-layer views so disallowed terms become hidden / disabled.
	 */
	function applyAttributeFilter(payload) {
		var rules = payload && payload.rules ? payload.rules : {};

		// Persist these rules so freshly-rendered attribute layers can pick them up.
		// Rules are keyed by the option_selector layer id, so multiple option_selector
		// layers don't overwrite each other.
		var sourceLayerId = payload && payload.layerId ? String(payload.layerId) : '_';
		ACTIVE_RULES[sourceLayerId] = rules;

		if (!PC.fe || !PC.fe.layers) return;

		PC.fe.layers.each(function (layer) {
			if (!isAttributeLayer(layer)) return;
			applyRulesToLayer(layer);
		});
	}

	function isAttributeLayer(layer) {
		if (!layer || typeof layer.get !== 'function') return false;
		return layer.get('type') === 'attribute' || !!layer.get('is_attribute_layer');
	}

	/**
	 * Combine the rules of every active option_selector layer for a given target.
	 * Multiple selectors targeting the same attribute layer = intersection
	 * (i.e. a term must be allowed by ALL active rules).
	 */
	function effectiveRuleFor(targetLayerId) {
		var lid = String(targetLayerId);
		var alt = parseInt(lid, 10);
		var matched = [];
		var keys = Object.keys(ACTIVE_RULES);
		for (var i = 0; i < keys.length; i++) {
			var src = ACTIVE_RULES[keys[i]] || {};
			var r = src[lid] || src[alt];
			if (r) matched.push(r);
		}
		return matched;
	}

	function applyRulesToLayer(layer) {
		var content = PC.fe.getLayerContent ? PC.fe.getLayerContent(layer.id) : null;
		if (!content) return;
		var rules = effectiveRuleFor(layer.id || layer.get('_id'));

		var hadSelection = false;
		var firstAllowed = null;

		// Pass 1: decide each choice (group headers + terms) by direct rule application.
		content.each(function (choice) {
			var allowed = true;
			for (var i = 0; i < rules.length; i++) {
				if (!choiceAllowedByRule(choice, rules[i])) { allowed = false; break; }
			}
			choice.set('hidden_by_option_selector', !allowed);
			if (allowed && firstAllowed === null && !choice.get('is_group')) {
				firstAllowed = choice;
			}
			if (choice.get('active') && !allowed) hadSelection = true;
		});

		// Pass 2: hide group headers whose children are all hidden after pass 1.
		// Term-scope rules don't filter the header directly (no term_id on the header)
		// — so under whitelist rules they'd remain visible with an empty list. Detect
		// that here and collapse the header too.
		if (rules.length) {
			content.each(function (header) {
				if (!header.get('is_group')) return;
				if (header.get('hidden_by_option_selector')) return;
				var headerId = header.id;
				var hasVisibleChild = false;
				content.each(function (c) {
					if (c.get('is_group')) return;
					if (c.get('parent') !== headerId) return;
					if (!c.get('hidden_by_option_selector')) hasVisibleChild = true;
				});
				if (!hasVisibleChild) header.set('hidden_by_option_selector', true);
			});
		}

		// Reflect in DOM for choices whose view has been rendered.
		var $list = layer._layerView && layer._layerView.choices && layer._layerView.choices.$el;
		if ($list && $list.length) {
			$list.find('.choice').each(function () {
				var v = $(this).data('view');
				if (!v || !v.model) return;
				var hidden = !!v.model.get('hidden_by_option_selector');
				$(this).toggleClass('is-hidden-by-option-selector', hidden);
				$(this).toggleClass('is-disabled-by-option-selector', hidden);
				$(this).attr('aria-disabled', hidden ? 'true' : 'false');
			});
		}

		// If the active choice just became disallowed, pick the first allowed one.
		if (hadSelection && firstAllowed) {
			content.each(function (c) { if (c.get('active')) c.set('active', false); });
			firstAllowed.set('active', true);
			if (typeof layer.set === 'function') layer.set('selectedChoice', firstAllowed.id);
			wp.hooks.doAction('PC.fe.choice.set_choice', firstAllowed, null);
			wp.hooks.doAction('PC.fe.choice.change', firstAllowed);
		}
	}

	function choiceAllowedByRule(choice, rule) {
		if (rule.scope === 'group') {
			// Both group headers and terms expose `group` (taxonomy slug) — apply
			// the membership check uniformly so a non-whitelisted group's header
			// is hidden along with its children.
			var group = choice.get('group') || '';
			if (!group) return true; // not part of any group
			var inSet = (rule.groups || []).indexOf(group) !== -1;
			return rule.mode === 'whitelist' ? inSet : !inSet;
		}
		// Term scope. Group headers don't have term_id; pass 2 (in applyRulesToLayer)
		// hides empty headers based on their children.
		if (choice.get('is_group')) return true;
		if (typeof choice.get('term_id') === 'undefined' || choice.get('term_id') === null) return true;
		var tid = parseInt(choice.get('term_id'), 10);
		var inList = (rule.term_ids || []).indexOf(tid) !== -1;
		return rule.mode === 'whitelist' ? inList : !inList;
	}

	wp.hooks.addAction('mkl_pc.option_selector.changed', 'mkl/option-selector/filter', applyAttributeFilter);

	/**
	 * Patch save_data.count_selected_choices_in_group so the upstream summary
	 * + cart pipeline counts selections inside attribute / text-overlay /
	 * option-selector children. Without this, the parent group ("Step N")
	 * is omitted whenever the only selectable child is one of these addon
	 * layer types — the summary skips the "Step N" header and the cart
	 * loses its "Step N:" prefix row.
	 */
	function patchGroupCounter() {
		if (!PC.fe || !PC.fe.save_data) return;
		var orig = PC.fe.save_data.count_selected_choices_in_group;
		if (!orig || orig.__mkl_pc_os_patched) return;

		var EXTRA_TYPES = ['attribute', 'text-overlay', LAYER_TYPE];

		function isFilledChoice(choice, type) {
			if (!choice) return false;
			if (choice.get('cshow') === false) return false;
			if (choice.get('is_group')) return false;

			// Active flag (set by attribute-layer.js, option-selector.js, and
			// text-overlay-frontend.js' viewer.render.before hook).
			if (choice.get('active')) return true;

			// Text-overlay sometimes sets active silently or hasn't yet (the
			// viewer.render.before hook can fire AFTER an early summary render).
			// Treat any user-filled or default-filled text-overlay row as selected,
			// and even an empty one — text-overlay layers always present UI, so
			// the parent step should always carry a header.
			if (type === 'text-overlay') return true;

			return false;
		}

		var patched = function (group_id) {
			var n = orig.apply(this, arguments) || 0;
			if (!PC.fe.layers) return n;

			PC.fe.layers.each(function (child) {
				if (parseInt(child.get('parent'), 10) !== parseInt(group_id, 10)) return;
				if (child.get('cshow') === false) return;
				var type = child.get('type');
				if (EXTRA_TYPES.indexOf(type) === -1) return;
				var content = PC.fe.getLayerContent ? PC.fe.getLayerContent(child.id) : null;
				if (!content) return;
				var sel = content.filter(function (c) { return isFilledChoice(c, type); });
				n += sel.length;
			});
			return n;
		};
		patched.__mkl_pc_os_patched = true;
		PC.fe.save_data.count_selected_choices_in_group = patched;
	}

	// Apply the patch as soon as save_data is around. Run early (priority 1)
	// so it lands before the summary's render hooks (priority 1000) on the very
	// first PC.fe.start invocation.
	wp.hooks.addAction('PC.fe.start', 'mkl/option-selector/patch-counter', patchGroupCounter, 1);
	// Also try at script load — if save_data is already initialised, no-op otherwise.
	patchGroupCounter();

	/**
	 * Re-apply the active filter whenever an attribute layer renders. The
	 * `_layerView` on the layer model is set by attribute-layer.js inside
	 * its own `PC.fe.layer.render` handler; running at priority 20 (after
	 * attribute-layer's default 10) guarantees we see the freshly built
	 * choices container.
	 */
	wp.hooks.addAction('PC.fe.layer.render', 'mkl/option-selector/reapply', function (view) {
		if (!view || !view.model) return;
		if (!isAttributeLayer(view.model)) return;
		// Defer one tick so the choices view's DOM is fully rendered.
		setTimeout(function () { applyRulesToLayer(view.model); }, 0);
	}, 20);

	/**
	 * Same idea: when the choices for an attribute layer are (re)built, mark
	 * disallowed ones in the DOM. This handles the case where the layer is
	 * rendered for the first time AFTER the option-selector has emitted its
	 * 'changed' event (typical step-themes where downstream steps are lazy).
	 */
	wp.hooks.addAction('PC.fe.layer.beforeRenderChoices', 'mkl/option-selector/reapply-choices', function (view) {
		if (!view || !view.model) return;
		if (!isAttributeLayer(view.model)) return;
		setTimeout(function () { applyRulesToLayer(view.model); }, 0);
	}, 20);

	/**
	 * Cart submission — inject pc_option_selections into the form
	 * and into the AJAX add-to-cart payload.
	 */
	function postSelections() {
		if (!PC.fe || !PC.fe.optionSelections) return null;
		var keys = Object.keys(PC.fe.optionSelections);
		if (!keys.length) return null;
		var arr = [];
		for (var i = 0; i < keys.length; i++) arr.push(PC.fe.optionSelections[keys[i]]);
		return JSON.stringify(arr);
	}

	$(document).on('submit', 'form.cart', function () {
		var json = postSelections();
		if (!json) return;
		var $form = $(this);
		$form.find('input[name="pc_option_selections"]').remove();
		$('<input>').attr({ type: 'hidden', name: 'pc_option_selections', value: json }).appendTo($form);
	});

	wp.hooks.addAction('PC.fe.add_to_cart.before', 'mkl/option-selector', function (formView) {
		var json = postSelections();
		if (!json || !formView || !formView.$cart) return;
		formView.$cart.find('input[name="pc_option_selections"]').remove();
		$('<input>').attr({ type: 'hidden', name: 'pc_option_selections', value: json }).appendTo(formView.$cart);
	});

	$(document.body).on('adding_to_cart', function (e, btn, data) {
		var json = postSelections();
		if (json) data.pc_option_selections = json;
	});

	wp.hooks.addFilter('PC.fe.save_data.extra_data', 'mkl/option-selector', function (data) {
		var json = postSelections();
		if (json) {
			try { data.option_selections = JSON.parse(json); } catch (e) {}
		}
		return data;
	});

})(jQuery, (typeof PC !== 'undefined' && PC._us) || window._);
