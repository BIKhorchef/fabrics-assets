/**
 * Note Layer — Frontend
 *
 * Per-choice textarea (one textarea per Content-tab choice). Architecture
 * mirrors Form Builder: each choice carries its own settings (label,
 * placeholder, max chars, required) so admins manage notes from the Content
 * tab, not the Layer settings sidebar.
 *
 * Render strategy (option-selector pattern):
 *   1. PC.fe.layer.beforeRenderChoices builds a standard PC.fe.views.choices
 *      view for the layer.
 *   2. The standard view emits one <li class="choice"> per choice and fires
 *      PC.fe.configurator.choice-item.render for each one.
 *   3. We hook that per-choice render and replace the <li>'s inner content
 *      with our textarea UI — keeping the surrounding DOM (correct nesting,
 *      step-theme positioning, etc.) intact.
 *
 * Other lessons baked in:
 *   - Hook names use dots, not slashes (wp.hooks rejects slashes silently).
 *   - Empty notes are filtered out of save_data so the summary + cart skip
 *     them and the parent step header isn't forced.
 *   - Group counter patch only counts a note as 1 when its text is filled.
 *   - Auto-active each note choice in PC.fe.viewer.render.before so the
 *     save_data pipeline picks them up (matches text-overlay's pattern).
 */
(function ($, _) {
	'use strict';
	if (typeof PC === 'undefined') return;

	PC.fe = PC.fe || {};
	PC.fe.noteSelections = PC.fe.noteSelections || {};

	var LAYER_TYPE = 'note';

	function isNoteLayer(layer) {
		if (!layer || typeof layer.get !== 'function') return false;
		return layer.get('type') === LAYER_TYPE || !!layer.get('is_note_layer');
	}

	function escapeHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function escapeAttr(s) { return escapeHtml(s); }

	function trim(s) { return (s == null ? '' : String(s)).replace(/^\s+|\s+$/g, ''); }

	// =========================================================================
	// Layer rendering — build the standard choices view, then mutate per-choice
	// =========================================================================

	wp.hooks.addAction('PC.fe.layer.beforeRenderChoices', 'mkl/note', function (view) {
		if (!view || !view.model) return;
		if (!isNoteLayer(view.model)) return;

		var content = PC.fe.getLayerContent ? PC.fe.getLayerContent(view.model.id) : null;
		if (!content) return;

		view.choices = new PC.fe.views.choices({ content: content, model: view.model });
		if (view.choices && view.choices.$el) {
			view.choices.$el.addClass('pc-choices--note type-' + LAYER_TYPE);
		}
		view.model._layerView = view;
	}, 5);

	wp.hooks.addAction('PC.fe.layer.render', 'mkl/note', function (view) {
		if (!view || !view.model) return;
		if (!isNoteLayer(view.model)) return;
		view.$el.addClass('pc-layer--note');
		view.$el.attr('data-layer-type', LAYER_TYPE);
		view.model._layerView = view;
	}, 10);

	/**
	 * Per-choice render hook — replace the choice's inner content with a
	 * textarea + label + character counter. Keeps the standard <li class="choice">
	 * wrapper so step-theme positioning, conditional logic, etc. all still apply.
	 */
	wp.hooks.addAction('PC.fe.configurator.choice-item.render', 'mkl/note', function (choiceView) {
		if (!choiceView || !choiceView.model) return;
		var model = choiceView.model;
		var layer = model.collection && model.collection.layer;
		if (!layer || !isNoteLayer(layer)) return;
		if (model.get('is_group')) return;
		if (choiceView.$el.find('.pc-note-textarea').length) return; // idempotent

		var fieldLabel  = model.get('note_field_label') || '';
		var placeholder = model.get('note_placeholder') || '';
		var maxChars    = parseInt(model.get('note_max_chars') || 0, 10);
		var required    = !!model.get('note_required');
		var initial     = model.get('_note_user_text') || '';

		// Defensive truncate (max may have been lowered after a previous save).
		if (maxChars > 0 && initial.length > maxChars) {
			initial = initial.substr(0, maxChars);
			model.set('_note_user_text', initial, { silent: true });
		}

		var html = '<div class="pc-note-wrap">';
		if (fieldLabel) {
			html += '<label class="pc-note-field-label">' + escapeHtml(fieldLabel);
			if (required) html += '<span class="pc-note-required-mark" aria-hidden="true">*</span>';
			html += '</label>';
		}
		html += '<textarea class="pc-note-textarea"';
		if (placeholder) html += ' placeholder="' + escapeAttr(placeholder) + '"';
		if (maxChars > 0) html += ' maxlength="' + maxChars + '"';
		if (required) html += ' aria-required="true"';
		html += '>' + escapeHtml(initial) + '</textarea>';
		if (maxChars > 0) {
			html += '<span class="pc-note-counter"><span class="pc-note-count">' + initial.length + '</span> / ' + maxChars + '</span>';
		}
		html += '</div>';

		// The standard choice template puts content inside `.choice-item`.
		// Replace its contents (preserves the <li> wrapper) so the rest of
		// the configurator's DOM logic still works.
		var $item = choiceView.$('.choice-item');
		if ($item.length) {
			$item.empty().append(html);
		} else {
			choiceView.$el.empty().append(html);
		}

		var $textarea = choiceView.$el.find('.pc-note-textarea');
		var $counter  = choiceView.$el.find('.pc-note-counter');

		// Seed selection store from any pre-loaded text.
		if (initial) updateSelection(layer, model, initial);

		$textarea.on('input', function () {
			// Defensive truncate — Safari sometimes lets paste exceed maxlength.
			if (maxChars > 0 && this.value.length > maxChars) {
				this.value = this.value.substr(0, maxChars);
			}
			var v = this.value;

			model.set('_note_user_text', v, { silent: true });
			// Use the user's text as the choice's display name so the in-layer
			// "active choice" indicator and summary item show what was typed.
			model.set('name', v || (fieldLabel || ''), { silent: true });

			updateCounter($counter, v.length, maxChars);
			updateSelection(layer, model, v);

			// Live-refresh the summary view (it subscribes to this hook channel).
			wp.hooks.doAction('PC.fe.text_overlay.item.change', model);
			wp.hooks.doAction('mkl_pc.note.changed', { layerId: layer.id, choiceId: model.id, text: v });
		});

		// Don't let clicks on the textarea bubble to the choice's selection
		// handler (which would toggle 'active' on/off and trigger reflows).
		$textarea.on('mousedown click', function (e) { e.stopPropagation(); });
	});

	function updateCounter($counter, len, max) {
		if (!$counter || !$counter.length || !max) return;
		$counter.find('.pc-note-count').text(len);
		$counter.toggleClass('is-near', len >= max * 0.9 && len < max);
		$counter.toggleClass('is-over', len >= max);
	}

	function updateSelection(layer, choice, text) {
		var key = choice.id;
		if (trim(text) === '') {
			delete PC.fe.noteSelections[key];
			return;
		}
		PC.fe.noteSelections[key] = {
			layer_id:     layer.id,
			layer_label:  layer.get('name') || '',
			choice_id:    choice.id,
			choice_label: choice.get('note_field_label') || '',
			text:         text,
		};
	}

	// =========================================================================
	// Auto-active + saved-config restoration
	// =========================================================================

	wp.hooks.addAction('PC.fe.viewer.render.before', 'mkl/note', function () {
		if (!PC.fe || !PC.fe.layers) return;
		PC.fe.layers.each(function (layer) {
			if (!isNoteLayer(layer)) return;
			var content = PC.fe.getLayerContent ? PC.fe.getLayerContent(layer.id) : null;
			if (!content) return;
			content.each(function (choice) {
				if (!choice.get('is_group')) choice.set('active', true, { silent: true });
			});
		});
	});

	wp.hooks.addAction('PC.fe.start', 'mkl/note', function () {
		PC.fe.noteSelections = {};
		if (!PC.fe || !PC.fe.layers) return;
		PC.fe.layers.each(function (layer) {
			if (!isNoteLayer(layer)) return;
			var content = PC.fe.getLayerContent ? PC.fe.getLayerContent(layer.id) : null;
			if (!content) return;
			content.each(function (choice) {
				if (choice.get('is_group')) return;
				var text = choice.get('_note_user_text') || '';
				if (text) updateSelection(layer, choice, text);
			});
		});
	}, 25);

	wp.hooks.addAction('PC.fe.reset_configurator', 'mkl/note', function () {
		PC.fe.noteSelections = {};
	});

	// =========================================================================
	// Skip empty notes in save_data so summary + cart don't render them
	// =========================================================================

	wp.hooks.addFilter('PC.fe.save_data.parse_choices.add_choice', 'mkl/note', function (add, choice) {
		if (!add) return add;
		if (!choice || !choice.collection) return add;
		var layer = choice.collection.layer;
		if (!layer || !isNoteLayer(layer)) return add;
		var text = trim(choice.get('_note_user_text') || '');
		return text !== '';
	});

	// =========================================================================
	// Summary item — show user's text as the row name
	// =========================================================================

	wp.hooks.addFilter('PC.fe.summary_item.attributes', 'mkl/note', function (attrs, model) {
		if (!model || !model.collection) return attrs;
		var layer = model.collection.layer;
		if (!layer || !isNoteLayer(layer)) return attrs;
		var t = model.get('_note_user_text') || '';
		if (t) attrs.name = t;
		return attrs;
	});

	// =========================================================================
	// Cart submission
	// =========================================================================

	function postSelections() {
		var keys = Object.keys(PC.fe.noteSelections || {});
		if (!keys.length) return null;
		var arr = [];
		for (var i = 0; i < keys.length; i++) {
			var s = PC.fe.noteSelections[keys[i]];
			if (s && trim(s.text) !== '') arr.push(s);
		}
		return arr.length ? JSON.stringify(arr) : null;
	}

	$(document).on('submit', 'form.cart', function () {
		var json = postSelections();
		if (!json) return;
		var $form = $(this);
		$form.find('input[name="pc_note_selections"]').remove();
		$('<input>').attr({ type: 'hidden', name: 'pc_note_selections', value: json }).appendTo($form);
	});

	wp.hooks.addAction('PC.fe.add_to_cart.before', 'mkl/note', function (formView) {
		var json = postSelections();
		if (!json || !formView || !formView.$cart) return;
		formView.$cart.find('input[name="pc_note_selections"]').remove();
		$('<input>').attr({ type: 'hidden', name: 'pc_note_selections', value: json }).appendTo(formView.$cart);
	});

	$(document.body).on('adding_to_cart', function (e, btn, data) {
		var json = postSelections();
		if (json) data.pc_note_selections = json;
	});

	wp.hooks.addFilter('PC.fe.save_data.extra_data', 'mkl/note', function (data) {
		var json = postSelections();
		if (json) {
			try { data.note_selections = JSON.parse(json); } catch (e) {}
		}
		return data;
	});

	// =========================================================================
	// Group counter patch — count a note ONLY when its text is filled.
	// Empty notes don't trigger a "Step N" header in summary or cart.
	// =========================================================================

	function patchGroupCounter() {
		if (!PC.fe || !PC.fe.save_data) return;
		var orig = PC.fe.save_data.count_selected_choices_in_group;
		if (!orig || orig.__mkl_pc_note_patched) return;

		var patched = function (group_id) {
			var n = orig.apply(this, arguments) || 0;
			if (!PC.fe.layers) return n;
			PC.fe.layers.each(function (child) {
				if (parseInt(child.get('parent'), 10) !== parseInt(group_id, 10)) return;
				if (child.get('cshow') === false) return;
				if (child.get('type') !== LAYER_TYPE) return;
				var content = PC.fe.getLayerContent ? PC.fe.getLayerContent(child.id) : null;
				if (!content) return;
				content.each(function (c) {
					if (c.get('is_group')) return;
					var text = trim(c.get('_note_user_text') || '');
					if (text) n += 1;
				});
			});
			return n;
		};
		patched.__mkl_pc_note_patched = true;
		PC.fe.save_data.count_selected_choices_in_group = patched;
	}

	wp.hooks.addAction('PC.fe.start', 'mkl/note/patch-counter', patchGroupCounter, 1);
	patchGroupCounter();

})(jQuery, (typeof PC !== 'undefined' && PC._us) || window._);
