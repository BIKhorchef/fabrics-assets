/**
 * Option Selector — Admin Editor
 *
 * Mounted into the layer settings form whenever an "option-selector" layer
 * is being edited. Renders a nested editor for options + sub-options +
 * visibility rules, and serialises the tree back into the layer's
 * `os_options` field as a JSON string (mirroring the text-overlay pattern).
 */
(function ($) {
	'use strict';

	if (typeof window.MKL_PC_OptionSelector === 'undefined') return;
	var i18n = window.MKL_PC_OptionSelector.i18n || {};
	var nonce = window.MKL_PC_OptionSelector.nonce;

	var TermsCache = {}; // layerId -> { taxonomies, choices }

	function uid(prefix) {
		return prefix + '_' + Math.random().toString(36).slice(2, 8);
	}

	function safeParse(json) {
		if (!json) return [];
		if (typeof json !== 'string') return Array.isArray(json) ? json : [];
		try {
			var v = JSON.parse(json);
			return Array.isArray(v) ? v : [];
		} catch (e) { return []; }
	}

	function getProductLayers() {
		try {
			if (typeof PC !== 'undefined' && PC.app && typeof PC.app.get_admin === 'function') {
				var admin = PC.app.get_admin();
				if (admin && admin.layers && typeof admin.layers.toJSON === 'function') {
					return admin.layers.toJSON();
				}
			}
		} catch (e) {}
		return [];
	}

	function attributeLayers(currentLayerId) {
		return getProductLayers().filter(function (l) {
			return l && l.type === 'attribute' && (!currentLayerId || l._id !== currentLayerId);
		});
	}

	/**
	 * Fetch the choice list (groups + terms) for an attribute layer via the
	 * existing AJAX endpoint exposed by the attribute-layer addon.
	 */
	function fetchAttributeChoices(layer, done) {
		var key = layer._id;
		if (TermsCache[key]) { done(TermsCache[key]); return; }

		var taxonomies = layer.attribute_taxonomies || (layer.attribute_taxonomy ? [layer.attribute_taxonomy] : []);
		if (!taxonomies.length) { done({ choices: [] }); return; }

		$.post(window.MKL_PC_OptionSelector.ajax_url, {
			action: 'mkl_pc_get_attribute_layer_choices',
			security: nonce,
			taxonomies: taxonomies,
			layer_id: layer._id,
			angle_id: 1
		}).done(function (resp) {
			var choices = (resp && resp.success) ? (resp.data || []) : [];
			TermsCache[key] = { choices: choices };
			done(TermsCache[key]);
		}).fail(function () {
			done({ choices: [] });
		});
	}

	// =========================================================================
	// Rendering
	// =========================================================================

	function renderRoot($container, options, ctx) {
		var $list = $container.find('.os-editor-list').empty();
		if (!options.length) {
			$list.append('<div class="os-empty">' + escapeHtml(i18n.no_options || 'No options yet.') + '</div>');
			return;
		}
		options.forEach(function (opt, idx) {
			$list.append(renderOption(opt, idx, false, ctx));
		});
	}

	function renderOption(opt, idx, isSub, ctx) {
		var $row = $('<div class="' + (isSub ? 'os-sub-row' : 'os-option-row') + '" data-idx="' + idx + '"></div>');

		var $head = $('<div class="os-row-head"></div>');
		$head.append(field('text', 'os-label', i18n.option_label, opt.label || '', 'label'));
		$head.append(field('text', 'os-id',    i18n.option_id,    opt.id    || '', 'id'));
		$head.append(field('number','os-price',i18n.price,        opt.price || 0, 'price'));
		$head.append(renderImageField(opt));

		var $actions = $('<div class="os-row-actions"></div>');
		if (!isSub) {
			$actions.append('<button type="button" class="button button-small os-add-sub">+ ' + escapeHtml(i18n.add_sub_option || 'Add sub-option') + '</button>');
		}
		$actions.append('<button type="button" class="button button-small os-add-rule">+ ' + escapeHtml(i18n.add_rule || 'Add rule') + '</button>');
		$actions.append('<button type="button" class="button button-small button-link-delete os-remove">' + escapeHtml(i18n.remove || 'Remove') + '</button>');
		$head.append($actions);

		$row.append($head);

		// Description
		$row.append('<div class="os-desc-cell"><label class="os-field-label">' + escapeHtml(i18n.description || 'Description') + '</label><input type="text" class="os-field" data-field="description" value="' + escapeAttr(opt.description || '') + '" /></div>');

		// Sub-options (top-level only)
		if (!isSub) {
			var $subList = $('<div class="os-sub-list"></div>');
			(opt.sub_options || []).forEach(function (sub, sidx) {
				$subList.append(renderOption(sub, sidx, true, ctx));
			});
			$row.append($subList);
		}

		// Visibility rules
		var $ruleList = $('<div class="os-rule-list"></div>');
		(opt.visibility_rules || []).forEach(function (rule, ridx) {
			$ruleList.append(renderRule(rule, ridx, ctx));
		});
		$row.append($ruleList);

		return $row;
	}

	function field(type, cls, label, value, dataField) {
		return $('<label class="' + cls + '"><span class="os-field-label">' + escapeHtml(label) + '</span><input type="' + type + '" class="os-field" data-field="' + dataField + '" value="' + escapeAttr(value) + '" /></label>');
	}

	function renderImageField(opt) {
		var image = opt.image || {};
		var $cell = $('<div class="os-image-cell"></div>');
		var $thumb = $('<img class="os-image-thumb" alt="" />');
		if (image.url) $thumb.attr('src', image.url); else $thumb.css('display', 'none');
		$cell.append($thumb);
		$cell.append('<button type="button" class="button button-small os-pick-image">' + escapeHtml(image.url ? (i18n.image || 'Image') : (i18n.choose_image || 'Choose image')) + '</button>');
		if (image.url) {
			$cell.append('<button type="button" class="button-link-delete os-clear-image">×</button>');
		}
		// Hidden inputs to persist the image
		$cell.append('<input type="hidden" class="os-field" data-field="image.id"  value="' + escapeAttr(image.id  || '') + '" />');
		$cell.append('<input type="hidden" class="os-field" data-field="image.url" value="' + escapeAttr(image.url || '') + '" />');
		return $cell;
	}

	function renderRule(rule, idx, ctx) {
		var $row = $('<div class="os-rule-row" data-rule-idx="' + idx + '"></div>');
		var attrLayers = attributeLayers(ctx.currentLayerId);

		var $target = $('<select class="os-rule-target" data-field="target_layer_id"></select>');
		if (!attrLayers.length) {
			$target.append('<option value="">' + escapeHtml(i18n.no_target || 'No target available') + '</option>');
		}
		attrLayers.forEach(function (l) {
			$target.append('<option value="' + l._id + '"' + (parseInt(rule.target_layer_id, 10) === parseInt(l._id, 10) ? ' selected' : '') + '>' + escapeHtml(l.name || ('Layer ' + l._id)) + '</option>');
		});

		var $mode = $('<select data-field="mode"></select>')
			.append('<option value="whitelist"' + (rule.mode !== 'blacklist' ? ' selected' : '') + '>' + escapeHtml(i18n.whitelist || 'Show only these') + '</option>')
			.append('<option value="blacklist"' + (rule.mode === 'blacklist' ? ' selected' : '') + '>' + escapeHtml(i18n.blacklist || 'Hide these') + '</option>');

		var $scope = $('<select data-field="scope"></select>')
			.append('<option value="term"'  + (rule.scope !== 'group' ? ' selected' : '') + '>' + escapeHtml(i18n.scope_term  || 'Specific terms') + '</option>')
			.append('<option value="group"' + (rule.scope === 'group' ? ' selected' : '') + '>' + escapeHtml(i18n.scope_group || 'Whole groups') + '</option>');

		var $head = $('<div class="os-rule-head"></div>');
		$head.append(label(i18n.target_layer || 'Target layer', $target));
		$head.append(label(i18n.mode || 'Mode', $mode));
		$head.append(label(i18n.scope || 'Scope', $scope));
		$head.append('<button type="button" class="button button-small button-link-delete os-remove-rule">' + escapeHtml(i18n.remove || 'Remove') + '</button>');
		$row.append($head);

		// Term picker (populated async)
		var $picker = $('<div class="os-term-picker"><em>' + escapeHtml(i18n.select_terms || 'Select terms') + '…</em></div>');
		$row.append($picker);
		populateTermPicker($picker, rule, attrLayers);

		return $row;
	}

	function label(text, $input) {
		return $('<label class="os-rule-field"><span class="os-field-label">' + escapeHtml(text) + '</span></label>').append($input);
	}

	function populateTermPicker($picker, rule, attrLayers) {
		var targetId = parseInt(rule.target_layer_id, 10);
		var layer = attrLayers.find(function (l) { return parseInt(l._id, 10) === targetId; });
		if (!layer) {
			$picker.html('<em>' + escapeHtml(i18n.no_target || 'No target available') + '</em>');
			return;
		}
		fetchAttributeChoices(layer, function (data) {
			var choices = (data && data.choices) || [];
			if (!choices.length) {
				$picker.html('<em>' + escapeHtml(i18n.no_target || 'No terms available') + '</em>');
				return;
			}
			$picker.empty();
			var scope = rule.scope || 'term';
			if (scope === 'group') {
				// Build a unique list of taxonomies/groups with labels
				var seen = {};
				choices.forEach(function (c) {
					if (!c.group) return;
					if (seen[c.group]) return;
					seen[c.group] = c.group_label || c.group;
				});
				Object.keys(seen).forEach(function (slug) {
					var checked = (rule.groups || []).indexOf(slug) !== -1 ? ' checked' : '';
					$picker.append('<label><input type="checkbox" class="os-rule-group-input" value="' + escapeAttr(slug) + '"' + checked + ' /> ' + escapeHtml(seen[slug]) + '</label>');
				});
			} else {
				var lastGroup = null;
				var termIds = (rule.term_ids || []).map(function (t) { return parseInt(t, 10); });
				choices.forEach(function (c) {
					if (c.is_group) {
						if (lastGroup !== c.group_label) {
							$picker.append('<div class="os-term-group-header">' + escapeHtml(c.group_label || c.name) + '</div>');
							lastGroup = c.group_label;
						}
						return;
					}
					if (!c.term_id) return;
					if (c.group_label && lastGroup !== c.group_label) {
						$picker.append('<div class="os-term-group-header">' + escapeHtml(c.group_label) + '</div>');
						lastGroup = c.group_label;
					}
					var checked = termIds.indexOf(parseInt(c.term_id, 10)) !== -1 ? ' checked' : '';
					$picker.append('<label><input type="checkbox" class="os-rule-term-input" value="' + escapeAttr(c.term_id) + '"' + checked + ' /> ' + escapeHtml(c.name || '') + '</label>');
				});
			}
		});
	}

	// =========================================================================
	// Serialisation
	// =========================================================================

	function serialise($container) {
		var options = [];
		$container.find('.os-editor-list > .os-option-row').each(function () {
			options.push(serialiseRow($(this), false));
		});
		return options;
	}

	function serialiseRow($row, isSub) {
		var data = {
			id:               readField($row, 'id') || sanitiseId(readField($row, 'label')) || uid('opt'),
			label:            readField($row, 'label'),
			description:      readField($row, 'description'),
			price:            parseFloat(readField($row, 'price') || '0') || 0,
			image:            { id: parseInt(readField($row, 'image.id'), 10) || 0, url: readField($row, 'image.url') || '' },
			visibility_rules: []
		};

		if (!isSub) {
			data.sub_options = [];
			$row.find('> .os-sub-list > .os-sub-row').each(function () {
				data.sub_options.push(serialiseRow($(this), true));
			});
		}

		$row.find('> .os-rule-list > .os-rule-row').each(function () {
			var $r = $(this);
			data.visibility_rules.push({
				target_layer_id: parseInt($r.find('[data-field="target_layer_id"]').val(), 10) || 0,
				mode:            $r.find('[data-field="mode"]').val()  || 'whitelist',
				scope:           $r.find('[data-field="scope"]').val() || 'term',
				term_ids:        $r.find('.os-rule-term-input:checked').map(function () { return parseInt(this.value, 10); }).get(),
				groups:          $r.find('.os-rule-group-input:checked').map(function () { return this.value; }).get()
			});
		});

		return data;
	}

	function readField($row, name) {
		// Read direct-child fields only — don't pick up sub-row inputs.
		var $f = $row.find('> .os-row-head .os-field[data-field="' + name + '"], > .os-row-head .os-image-cell .os-field[data-field="' + name + '"], > .os-desc-cell .os-field[data-field="' + name + '"]');
		if (!$f.length) return '';
		return $f.val();
	}

	function sanitiseId(label) {
		return (label || '').toString().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40);
	}

	// =========================================================================
	// Mounting + event wiring
	// =========================================================================

	function mount(view) {
		var $form = view.$el;
		var $container = $form.find('.os-editor-container').first();
		if (!$container.length) return;
		if ($container.data('os-mounted')) return;
		$container.data('os-mounted', true);

		var ctx = { currentLayerId: parseInt(view.model.id, 10) };
		var initial = safeParse(view.model.get('os_options'));
		renderRoot($container, initial, ctx);

		// --- Add option ----------------------------------------------------
		$container.on('click', '.os-add-option', function (e) {
			e.preventDefault();
			var data = serialise($container);
			data.push({ id: uid('opt'), label: '', description: '', price: 0, image: {}, sub_options: [], visibility_rules: [] });
			renderRoot($container, data, ctx);
			pushBack(view, data);
		});

		// --- Add sub-option ------------------------------------------------
		$container.on('click', '.os-add-sub', function (e) {
			e.preventDefault();
			var $row  = $(this).closest('.os-option-row');
			var idx   = $row.parent().children().index($row);
			var data  = serialise($container);
			if (!data[idx].sub_options) data[idx].sub_options = [];
			data[idx].sub_options.push({ id: uid('sub'), label: '', description: '', price: 0, image: {}, visibility_rules: [] });
			renderRoot($container, data, ctx);
			pushBack(view, data);
		});

		// --- Add rule ------------------------------------------------------
		$container.on('click', '.os-add-rule', function (e) {
			e.preventDefault();
			var $row = $(this).closest('.os-option-row, .os-sub-row');
			var data = serialise($container);
			var path = locateRow($container, $row);
			if (!path) return;
			var node = path.isSub ? data[path.parentIdx].sub_options[path.idx] : data[path.idx];
			node.visibility_rules = node.visibility_rules || [];
			node.visibility_rules.push({ target_layer_id: 0, mode: 'whitelist', scope: 'term', term_ids: [], groups: [] });
			renderRoot($container, data, ctx);
			pushBack(view, data);
		});

		// --- Remove rule ---------------------------------------------------
		$container.on('click', '.os-remove-rule', function (e) {
			e.preventDefault();
			$(this).closest('.os-rule-row').remove();
			pushBack(view, serialise($container));
		});

		// --- Remove option / sub-option ------------------------------------
		$container.on('click', '.os-remove', function (e) {
			e.preventDefault();
			$(this).closest('.os-option-row, .os-sub-row').remove();
			pushBack(view, serialise($container));
		});

		// --- Live serialise on field changes -------------------------------
		$container.on('change input', '.os-field, .os-rule-target, [data-field], .os-rule-term-input, .os-rule-group-input', _.debounce(function () {
			pushBack(view, serialise($container));
		}, 200));

		// --- Re-render term picker when target layer / mode / scope changes
		$container.on('change', '.os-rule-target, [data-field="mode"], [data-field="scope"]', function () {
			var $r = $(this).closest('.os-rule-row');
			var rule = {
				target_layer_id: parseInt($r.find('[data-field="target_layer_id"]').val(), 10) || 0,
				mode:            $r.find('[data-field="mode"]').val(),
				scope:           $r.find('[data-field="scope"]').val(),
				term_ids:        $r.find('.os-rule-term-input:checked').map(function () { return parseInt(this.value, 10); }).get(),
				groups:          $r.find('.os-rule-group-input:checked').map(function () { return this.value; }).get()
			};
			populateTermPicker($r.find('.os-term-picker'), rule, attributeLayers(ctx.currentLayerId));
		});

		// --- Image picker (uses the WP media library) ----------------------
		$container.on('click', '.os-pick-image', function (e) {
			e.preventDefault();
			if (!wp || !wp.media) return;
			var $btn = $(this);
			var $cell = $btn.closest('.os-image-cell');
			var frame = wp.media({ title: i18n.choose_image || 'Choose image', multiple: false });
			frame.on('select', function () {
				var att = frame.state().get('selection').first().toJSON();
				$cell.find('[data-field="image.id"]').val(att.id);
				$cell.find('[data-field="image.url"]').val(att.url).trigger('change');
				var $thumb = $cell.find('.os-image-thumb');
				$thumb.attr('src', att.url).show();
				if (!$cell.find('.os-clear-image').length) {
					$cell.append('<button type="button" class="button-link-delete os-clear-image">×</button>');
				}
				pushBack(view, serialise($container));
			});
			frame.open();
		});

		$container.on('click', '.os-clear-image', function (e) {
			e.preventDefault();
			var $cell = $(this).closest('.os-image-cell');
			$cell.find('[data-field="image.id"]').val('');
			$cell.find('[data-field="image.url"]').val('').trigger('change');
			$cell.find('.os-image-thumb').hide();
			$(this).remove();
			pushBack(view, serialise($container));
		});
	}

	function pushBack(view, data) {
		var json = JSON.stringify(data);
		// `silent: true` prevents the layer form from re-rendering on every keystroke.
		view.model.set('os_options', json, { silent: true });
		try {
			if (typeof view.model.trigger === 'function') view.model.trigger('mkl_pc:dirty');
		} catch (e) {}
	}

	function locateRow($container, $row) {
		if ($row.hasClass('os-option-row')) {
			return { isSub: false, idx: $row.parent().children('.os-option-row').index($row) };
		}
		if ($row.hasClass('os-sub-row')) {
			var $parent = $row.closest('.os-option-row');
			var pIdx = $parent.parent().children('.os-option-row').index($parent);
			return { isSub: true, parentIdx: pIdx, idx: $row.parent().children('.os-sub-row').index($row) };
		}
		return null;
	}

	function escapeHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function escapeAttr(s) { return escapeHtml(s); }

	window.MklPcOptionSelectorAdmin = { mount: mount };

})(jQuery);
