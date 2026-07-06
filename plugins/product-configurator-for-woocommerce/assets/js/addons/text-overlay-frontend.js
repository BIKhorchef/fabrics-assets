/**
 * Text Overlay Frontend JS
 *
 * Customer-facing sidebar form for text-overlay layers:
 *  - text input
 *  - font selector
 *  - color selector
 *  - position selector (admin-defined list with optional miniatures)
 *
 * Canvas rendering: a text-overlay choice can carry a per-angle Main Image
 * (set in the admin's Content tab). When present, the image is rendered on
 * the product preview canvas at its target angle — the layer auto-switches
 * to that angle on activation. Without an image, nothing is drawn on the
 * canvas and the layer only powers the sidebar form. Selections are pushed
 * into save_data so the step Summary and cart show text + font + color +
 * position.
 */
(function($, Backbone, _) {
	'use strict';

	if (typeof PC === 'undefined') return;

	// ------------------------------------------------------------------------
	// Parse the `to_position_options` layer attribute into a real array.
	// Stored as a JSON string in the DB, sometimes HTML-entity-escaped.
	// ------------------------------------------------------------------------
	function parsePositionOptions(raw) {
		if (!raw) return [];
		if (_.isArray(raw)) return raw;
		if (typeof raw === 'string') {
			if (raw.indexOf('&quot;') !== -1 || raw.indexOf('&amp;') !== -1) {
				var el = document.createElement('textarea');
				el.innerHTML = raw;
				raw = el.value;
			}
			try {
				var decoded = JSON.parse(raw);
				return _.isArray(decoded) ? decoded : [];
			} catch (e) { return []; }
		}
		return [];
	}

	// ========================================================================
	// TEXT OVERLAY CHOICES VIEW (sidebar form)
	// ========================================================================
	PC.fe.views.text_overlay_choices = Backbone.View.extend({
		tagName: 'ul',
		className: 'layer_choices text-overlay-choices',
		headerTemplate: wp.template('mkl-pc-configurator-choices'),

		initialize: function(options) {
			this.model = options.model;
			this.content = options.content;
			this.render();
		},

		events: {
			'click .choices-close': 'close_choices'
		},

		render: function() {
			var layerData = this.model.attributes;

			this.$el.append(this.headerTemplate(
				wp.hooks.applyFilters('PC.fe.configurator.layer_data', layerData)
			));
			this.$el.addClass(this.model.get('type'));
			if (this.model.get('class_name')) this.$el.addClass(this.model.get('class_name'));

			var colors          = _.isArray(layerData.to_colors) ? layerData.to_colors : [];
			var fonts           = _.isArray(layerData.to_fonts)  ? layerData.to_fonts  : [];
			var positionOptions = parsePositionOptions(layerData.to_position_options);

			var choicesData = [];
			this.content.each(function(choice) {
				choicesData.push(choice.attributes);
			});

			var template = wp.template('mkl-pc-text-overlay-choices');
			var $formContent = $('<li class="to-choices-wrapper"></li>').html(template({
				layer: layerData,
				choices: choicesData,
				colors: colors,
				fonts: fonts,
				positionOptions: positionOptions,
				hideColors: colors.length <= 1,
				hideFonts: fonts.length <= 1,
				hidePositions: positionOptions.length === 0
			}));
			this.$el.find('.choices-list ul').append($formContent);

			// Seed user-selection attributes so save_data + summary include
			// them even if the user never opens this form.
			this.content.each(function(choice) {
				var defaultText = choice.get('to_default_text') || '';
				if (defaultText) choice.set('_to_user_text', defaultText, { silent: true });

				if (fonts.length) {
					choice.set('_to_user_font', fonts[0].family || fonts[0].name || '', { silent: true });
				}
				if (colors.length) {
					choice.set('_to_user_color', colors[0].value || '', { silent: true });
					choice.set('_to_user_color_name', colors[0].label || '', { silent: true });
				}
				if (positionOptions.length) {
					choice.set('_to_user_position', positionOptions[0].id || '', { silent: true });
					choice.set('_to_user_position_name', positionOptions[0].label || '', { silent: true });
				}
			});

			this.$el.on('input change', '.to-text-input', this.onTextChange.bind(this));
			this.$el.on('change', '.to-font-select', this.onFontChange.bind(this));
			this.$el.on('change', '.to-color-select', this.onColorChange.bind(this));
			this.$el.on('change', '.to-position-select', this.onPositionChange.bind(this));

			return this.$el;
		},

		close_choices: function(e) {
			e.preventDefault();
			this.model.set('active', false);
		},

		onTextChange: function(e) {
			var choiceId = $(e.target).data('choice-id');
			var text = $(e.target).val();
			var choice = this.content.get(choiceId);
			if (!choice) return;
			choice.set('_to_user_text', text);
			choice.trigger('text-overlay:update');
			wp.hooks.doAction('PC.fe.text_overlay.item.change', choice);
			choice.trigger('change:active', choice, choice.get('active'));
		},

		onFontChange: function(e) {
			var choiceId = $(e.target).data('choice-id');
			var font = $(e.target).val();
			var choice = this.content.get(choiceId);
			if (!choice) return;
			choice.set('_to_user_font', font);
			choice.trigger('text-overlay:update');
			wp.hooks.doAction('PC.fe.text_overlay.item.change', choice);
		},

		onColorChange: function(e) {
			var choiceId = $(e.target).data('choice-id');
			var color = $(e.target).val();
			var colorName = $(e.target).data('color-name') || '';
			var choice = this.content.get(choiceId);
			if (!choice) return;
			choice.set('_to_user_color', color);
			choice.set('_to_user_color_name', colorName);
			choice.trigger('text-overlay:update');
			wp.hooks.doAction('PC.fe.text_overlay.item.change', choice);

			$(e.target).closest('.to-color-swatches').find('.to-color-option').removeClass('active');
			$(e.target).closest('.to-color-option').addClass('active');
		},

		onPositionChange: function(e) {
			var choiceId     = $(e.target).data('choice-id');
			var positionId   = $(e.target).val();
			var positionName = $(e.target).data('position-name') || '';
			var choice = this.content.get(choiceId);
			if (!choice) return;
			choice.set('_to_user_position', positionId);
			choice.set('_to_user_position_name', positionName);
			choice.trigger('text-overlay:update');
			wp.hooks.doAction('PC.fe.text_overlay.item.change', choice);

			$(e.target).closest('.to-position-options').find('.to-position-option').removeClass('active');
			$(e.target).closest('.to-position-option').addClass('active');
		}
	});

	// ========================================================================
	// HOOK: Seed default user selections BEFORE the viewer tries to render
	// text-overlay choices. We still set them active so save_data collects
	// them even without a customer interaction.
	// ========================================================================
	wp.hooks.addAction('PC.fe.viewer.render.before', 'mkl-pc-text-overlay', function() {
		PC.fe.layers.each(function(layer) {
			if ('text-overlay' !== layer.get('type')) return;
			var choices = PC.fe.getLayerContent(layer.id);
			if (!choices) return;

			var layerData       = layer.attributes;
			var fonts           = _.isArray(layerData.to_fonts)  ? layerData.to_fonts  : [];
			var colors          = _.isArray(layerData.to_colors) ? layerData.to_colors : [];
			var positionOptions = parsePositionOptions(layerData.to_position_options);

			choices.each(function(choice) {
				choice.set('active', true, { silent: true });

				var defaultText = choice.get('to_default_text') || '';
				if (defaultText && !choice.get('_to_user_text')) {
					choice.set('_to_user_text', defaultText, { silent: true });
				}
				if (fonts.length && !choice.get('_to_user_font')) {
					choice.set('_to_user_font', fonts[0].family || fonts[0].name || '', { silent: true });
				}
				if (colors.length && !choice.get('_to_user_color')) {
					choice.set('_to_user_color', colors[0].value || '', { silent: true });
					choice.set('_to_user_color_name', colors[0].label || '', { silent: true });
				}
				if (positionOptions.length && !choice.get('_to_user_position')) {
					choice.set('_to_user_position', positionOptions[0].id || '', { silent: true });
					choice.set('_to_user_position_name', positionOptions[0].label || '', { silent: true });
				}
			});
		});
	});

	// ========================================================================
	// HOOK: Inject the custom choices view into the sidebar.
	// ========================================================================
	wp.hooks.addAction('PC.fe.layer.beforeRenderChoices', 'mkl-pc-text-overlay', function(layerView) {
		if ('text-overlay' !== layerView.layer_type) return;
		var content = PC.fe.getLayerContent(layerView.model.id);
		if (!content) return;

		layerView.choices = new PC.fe.views.text_overlay_choices({
			model: layerView.model,
			content: content
		});
	});

	// ========================================================================
	// FILTER: Suppress viewer_layers for text-overlay choices.
	// The empty-images filter would otherwise force a render with no images,
	// producing a blank wrapper that could still intercept clicks or
	// confuse the viewer size calculations.
	// ========================================================================
	wp.hooks.addFilter('PC.fe.viewer.item.render.empty.images', 'mkl-pc-text-overlay', function(shouldRender, model) {
		if (!model) return shouldRender;
		var layer = PC.fe.layers.get(model.get('layerId'));
		if (layer && 'text-overlay' === layer.get('type')) {
			return false;
		}
		return shouldRender;
	});

	// ========================================================================
	// Helper: collect the angleIds that a text-overlay layer's choices carry a
	// real per-angle Main Image on. Used by the auto-angle switch and the
	// post-init repaint below to keep the canvas in sync with the data.
	// ========================================================================
	function getTextOverlayImageAngles(layer) {
		var angles = [];
		if (!layer) return angles;
		var content = PC.fe.getLayerContent(layer.id);
		if (!content) return angles;
		content.each(function(choice) {
			var images = choice.get('images');
			if (!images || !images.each) return;
			images.each(function(picture) {
				var img = picture.get('image');
				if (!img || !img.url) return;
				var angleId = picture.get('angleId') || picture.id;
				if (angleId && PC.fe.angles.get(angleId)) {
					angles.push(angleId);
				}
			});
		});
		return angles;
	}

	// ========================================================================
	// HOOK: When a text-overlay layer is activated, jump to the angle that
	// actually holds its per-angle Main Image. Runs at priority 21 so it
	// fires AFTER the core auto_angle_switch (priority 20) — that lets us
	// override its choice when the layer's `angle_switch` doesn't match the
	// angle where the image was uploaded (a common mismatch when the admin
	// sets the image in the Content tab without updating the layer's
	// auto-switch dropdown).
	// ========================================================================
	function autoSwitchToImageAngle(view) {
		if (!view || !view.model) return;
		if ('text-overlay' !== view.model.get('type')) return;
		if (false === view.model.get('cshow')) return;

		var imageAngles = getTextOverlayImageAngles(view.model);
		if (!imageAngles.length) return;

		var current = PC.fe.angles.findWhere({ active: true });
		if (current && imageAngles.indexOf(current.id) !== -1) return;

		var target = PC.fe.angles.get(imageAngles[0]);
		if (!target || target.get('active')) return;

		target.collection.each(function(angle) {
			if (angle.id !== target.id && angle.get('active')) {
				angle.set('active', false);
			}
		});
		target.set('active', true);
	}

	wp.hooks.addAction('PC.fe.layer.activate', 'mkl-pc-text-overlay/auto-angle', autoSwitchToImageAngle, 21);
	wp.hooks.addAction('PC.fe.choice.activate', 'mkl-pc-text-overlay/auto-angle', autoSwitchToImageAngle, 21);

	// ========================================================================
	// HOOK: Force a `change:active` event after the silent activation in
	// `viewer.render.before`. Without this, viewer_layer's change_layer
	// listener never repaints the canvas after the initial render — and the
	// initial render happens before the first angle is even activated by
	// the angles selector, so the choice's per-angle Main Image (resolved
	// against the active angle) would not appear until something else
	// triggered a render. Toggling false→true mirrors the same pattern
	// conditional-logic.js uses for simple/attribute layers.
	// ========================================================================
	wp.hooks.addAction('PC.fe.viewer.render', 'mkl-pc-text-overlay/repaint', function(viewer) {
		PC.fe.layers.each(function(layer) {
			if ('text-overlay' !== layer.get('type')) return;
			var choices = PC.fe.getLayerContent(layer.id);
			if (!choices) return;
			choices.each(function(choice) {
				if (true !== choice.get('active')) return;
				choice.set('active', false, { silent: true });
				choice.set('active', true);
			});
		});
		// Belt-and-suspenders: if a text-overlay layer's per-angle Main Image
		// belongs to the currently active angle, force the corresponding
		// viewer_layer to repaint its <img> src. The choice-toggle above only
		// works if viewer_layer's change:active listener got bound in time —
		// this path goes directly through the viewer's layers map to guarantee
		// the canvas is up to date.
		_repaintActiveAngleTextOverlays(viewer || PC.fe.modal && PC.fe.modal.viewer);
	});

	// ========================================================================
	// HOOK: Whenever the active angle changes, repaint every text-overlay
	// viewer_layer for the new angle. Bound inside PC.fe.start because
	// PC.fe.angles is created lazily by PC.fe.init — registering this at
	// script-load time would attach to a not-yet-created collection.
	// ========================================================================
	wp.hooks.addAction('PC.fe.start', 'mkl-pc-text-overlay/angle-repaint', function(configurator) {
		if (!PC.fe.angles || !PC.fe.angles.on) return;
		PC.fe.angles.on('change:active', function(angle) {
			if (!angle || !angle.get('active')) return;
			_repaintActiveAngleTextOverlays(configurator && configurator.viewer);
		});
	}, 70);

	function _repaintActiveAngleTextOverlays(viewer) {
		if (!viewer || !viewer.layers) return;
		var activeAngle = PC.fe.angles.findWhere({ active: true });
		if (!activeAngle) return;
		PC.fe.layers.each(function(layer) {
			if ('text-overlay' !== layer.get('type')) return;
			var choices = PC.fe.getLayerContent(layer.id);
			if (!choices) return;
			choices.each(function(choice) {
				var view = viewer.layers[choice.id];
				if (!view || !view.render) return;
				// Resolve the URL for the active angle directly — if there's no
				// picture for that angle, skip (we don't want to wipe a frame
				// that another addon might have populated).
				var url = choice.get_image('image', 'url', activeAngle.id);
				if (!url) return;
				if (true !== choice.get('active')) choice.set('active', true, { silent: true });
				view.is_loaded = false;
				view.render(true);
			});
		});
	}

	// ========================================================================
	// FILTER: Show the user's typed text in the layer-list selection subtitle
	// ========================================================================
	wp.hooks.addFilter('PC.fe.selected_choice.name', 'mkl-pc-text-overlay', function(name, choice) {
		if (!choice) return name;
		var layer = PC.fe.layers.get(choice.get('layerId'));
		if (!layer || 'text-overlay' !== layer.get('type')) return name;
		var userText = choice.get('_to_user_text') || choice.get('to_default_text') || '';
		return userText || name;
	});

	// ========================================================================
	// Subtitle helper — keep the layer-list item preview in sync with typing.
	// ========================================================================
	function updateTextOverlaySubtitle(layer) {
		if (!layer) return;
		var choices = PC.fe.getLayerContent(layer.id);
		if (!choices) return;

		var text = '';
		choices.each(function(choice) {
			var t = choice.get('_to_user_text') || choice.get('to_default_text') || '';
			if (t) text = t;
		});

		var $layerItem = $('.layers-list-item[data-layer="' + layer.id + '"]');
		if (!$layerItem.length) return;

		var $selectedChoice = $layerItem.find('.selected-choice');
		if ($selectedChoice.length) $selectedChoice.text(text);
	}

	wp.hooks.addAction('PC.fe.text_overlay.item.change', 'mkl-pc-text-overlay/subtitle', function(choice) {
		if (!choice) return;
		var layer = PC.fe.layers.get(choice.get('layerId'));
		if (!layer || 'text-overlay' !== layer.get('type')) return;
		updateTextOverlaySubtitle(layer);
	});

	wp.hooks.addAction('PC.fe.start', 'mkl-pc-text-overlay/subtitle', function() {
		setTimeout(function() {
			if (!PC.fe || !PC.fe.layers) return;
			PC.fe.layers.each(function(layer) {
				if ('text-overlay' !== layer.get('type')) return;
				updateTextOverlaySubtitle(layer);
			});
		}, 300);
	}, 30);

	// ========================================================================
	// Save-data integration
	// ========================================================================
	wp.hooks.addFilter('PC.fe.save_data.parse_choices.add_choice', 'mkl-pc-text-overlay', function(shouldAdd, choice) {
		if (!choice) return shouldAdd;
		var layer = PC.fe.layers.get(choice.get('layerId'));
		if (layer && 'text-overlay' === layer.get('type')) {
			return false;
		}
		return shouldAdd;
	});

	wp.hooks.addAction('PC.fe.save_data.parse_choices.after', 'mkl-pc-text-overlay', function(layerModel, saveData) {
		if ('text-overlay' !== layerModel.get('type')) return;
		if (false === layerModel.get('cshow')) return;

		var choices = PC.fe.getLayerContent(layerModel.id);
		if (!choices) return;

		var angle = PC.fe.angles.findWhere({ active: true }) || PC.fe.angles.first();
		if (!angle) return;

		var layerName = layerModel.get('name') || '';

		choices.each(function(choice) {
			if (choice.get('is_group')) return;
			if (false === choice.get('cshow')) return;

			var text = choice.get('_to_user_text') || choice.get('to_default_text') || '';

			if (!text && choice.get('to_required')) {
				PC.fe.errors.push({
					choice: choice,
					layer: layerModel,
					message: (PC_config.lang.required_error_message || '%s is required').replace('%s', layerName)
				});
				return;
			}

			if (text) {
				saveData.choices.push({
					is_choice: true,
					layer_id: layerModel.id,
					choice_id: choice.id,
					angle_id: angle.id,
					layer_name: layerName,
					name: choice.get('name') || '',
					text_overlay: {
						text:          text,
						font:          choice.get('_to_user_font') || '',
						color:         choice.get('_to_user_color') || '',
						color_name:    choice.get('_to_user_color_name') || '',
						position:      choice.get('_to_user_position') || '',
						position_name: choice.get('_to_user_position_name') || ''
					}
				});
			}
		});
	});

	// ========================================================================
	// Summary: show text + font + color + position in the summary step.
	// The default summary template prints `data.name` — rewrite that field
	// for text-overlay choices so all the customer's selections appear.
	// ========================================================================
	function escapeHtml(str) {
		return String(str || '').replace(/[&<>"']/g, function(c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	wp.hooks.addFilter('PC.fe.summary_item.attributes', 'mkl-pc-text-overlay', function(attrs, model) {
		if (!model || !model.get) return attrs;
		var layer = PC.fe.layers.get(model.get('layerId'));
		if (!layer || 'text-overlay' !== layer.get('type')) return attrs;

		var text         = model.get('_to_user_text') || model.get('to_default_text') || '';
		var font         = model.get('_to_user_font') || '';
		var color        = model.get('_to_user_color') || '';
		var colorName    = model.get('_to_user_color_name') || '';
		var positionName = model.get('_to_user_position_name') || '';

		if (!text) return attrs;

		var parts = ['<span class="to-summary-text">' + escapeHtml(text) + '</span>'];

		if (font) {
			parts.push('<em class="to-summary-font" style="font-family:\'' + escapeHtml(font) + '\'">' + escapeHtml(font) + '</em>');
		}
		if (color) {
			var colorLabel = colorName ? escapeHtml(colorName) : escapeHtml(color);
			parts.push(
				'<span class="to-summary-color">' +
					'<span class="to-summary-swatch" style="background:' + escapeHtml(color) + '"></span>' +
					colorLabel +
				'</span>'
			);
		}
		if (positionName) {
			parts.push('<span class="to-summary-position">@ ' + escapeHtml(positionName) + '</span>');
		}

		attrs.name = parts.join(' ');
		return attrs;
	});

})(jQuery, Backbone, _);
