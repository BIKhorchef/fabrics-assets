/**
 * Text Overlay Admin JS
 *
 * Provides:
 *  1. PC.views.fonts_library  - Fonts Library sidebar view (upload, list, edit, delete)
 *  2. Font Selector Modal     - Two-column picker for layer font assignment
 *  3. Per-View Text Positions - Renders position buttons per angle inside choice settings
 *  4. Position Modal          - Per-single-view reference-image positioning editor
 */
(function($, Backbone, _) {
	'use strict';

	var PC     = window.PC || {};
	var config = window.MKL_PC_TextOverlay || {};
	var i18n   = config.i18n || {};

	PC.views = PC.views || {};

	// =========================================================================
	//  Utility helpers
	// =========================================================================

	function escHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str || ''));
		return div.innerHTML;
	}

	function escAttr(str) {
		return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	/**
	 * Inject a @font-face rule for a single font object so previews work
	 * immediately after upload (without a page reload).
	 */
	function injectFontFace(font) {
		if (!font || !font.file_url || !font.family) return;
		var style = document.createElement('style');
		style.textContent = "@font-face { font-family: '" + font.family + "'; src: url('" + font.file_url + "') format('" + (font.format || 'woff2') + "'); font-display: swap; }";
		document.head.appendChild(style);
	}

	/**
	 * Collect unique category strings from the global fonts array.
	 */
	function getCategories() {
		var cats = [];
		_.each(config.fonts || [], function(f) {
			if (f.category && cats.indexOf(f.category) === -1) {
				cats.push(f.category);
			}
		});
		return cats.sort();
	}

	// =========================================================================
	//  1.  FONTS LIBRARY SIDEBAR VIEW
	//      Registered as PC.views.fonts_library so the state system can find it.
	// =========================================================================

	PC.views.fonts_library = Backbone.View.extend({
		tagName: 'div',
		className: 'state fonts-library-state',
		template: wp.template('mkl-pc-to-fonts-library'),
		collectionName: 'fonts_library',

		initialize: function(options) {
			this.options = options || {};
			this.col = null;
			this.render();
		},

		events: {
			'click .to-font-browse':            'openFilePicker',
			'change .to-font-file-input':       'onFileSelected',
			'click .to-delete-font':            'deleteFont',
			'change .to-font-category-filter':  'filterByCategory',
			'dragover .to-font-dropzone':       'onDragOver',
			'dragleave .to-font-dropzone':      'onDragLeave',
			'drop .to-font-dropzone':           'onDrop',
			'input .to-font-name-input':        'markModified',
			'input .to-font-category-input':    'markModified'
		},

		render: function() {
			this.$el.html(this.template({}));
			this.$list       = this.$('.to-font-list');
			this.$dropzone   = this.$('.to-font-dropzone');
			this.$fileInput  = this.$('.to-font-file-input');
			this.$filter     = this.$('.to-font-category-filter');
			this.renderFontList();
			this.populateCategoryFilter();
			this.bindToolbarSave();
			return this;
		},

		// ----- Toolbar "Save" button (lives in the bottom toolbar) ----------

		bindToolbarSave: function() {
			var self = this;
			// The toolbar is appended after this view by the state wrapper.
			// We use a delegated listener on the document for the toolbar button.
			$(document).off('click.to-save-fonts').on('click.to-save-fonts', '.to-save-fonts-library', function() {
				self.saveFontsLibrary();
			});
		},

		// ----- Rendering ----------------------------------------------------

		renderFontList: function(filter) {
			var fonts = config.fonts || [];
			this.$list.empty();

			if (filter) {
				fonts = _.filter(fonts, function(f) {
					return f.category === filter;
				});
			}

			if (!fonts.length) {
				this.$list.html('<p class="description">' + escHtml(i18n.no_fonts || 'No fonts uploaded yet.') + '</p>');
				return;
			}

			var rowTpl = wp.template('mkl-pc-to-font-row');
			_.each(fonts, function(font) {
				this.$list.append(rowTpl(font));
			}, this);
		},

		populateCategoryFilter: function() {
			var cats = getCategories();
			// Keep the "all" option, rebuild the rest.
			this.$filter.find('option:not(:first)').remove();
			_.each(cats, function(cat) {
				this.$filter.append('<option value="' + escAttr(cat) + '">' + escHtml(cat) + '</option>');
			}, this);
		},

		filterByCategory: function() {
			var val = this.$filter.val();
			this.renderFontList(val || null);
		},

		// ----- Upload (click) -----------------------------------------------

		openFilePicker: function(e) {
			e.preventDefault();
			// Use native .click() – jQuery .trigger('click') on a
			// display:none file input is blocked by some browsers.
			this.$fileInput[0].click();
		},

		onFileSelected: function(e) {
			var files = e.target.files;
			if (files && files.length) {
				this.uploadFiles(files);
			}
			// Reset so the same file can be re-selected.
			this.$fileInput.val('');
		},

		// ----- Drag & Drop --------------------------------------------------

		onDragOver: function(e) {
			e.preventDefault();
			e.stopPropagation();
			this.$dropzone.addClass('drag-over');
		},

		onDragLeave: function(e) {
			e.preventDefault();
			e.stopPropagation();
			this.$dropzone.removeClass('drag-over');
		},

		onDrop: function(e) {
			e.preventDefault();
			e.stopPropagation();
			this.$dropzone.removeClass('drag-over');
			var files = e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files;
			if (files && files.length) {
				this.uploadFiles(files);
			}
		},

		// ----- Upload logic -------------------------------------------------

		uploadFiles: function(files) {
			var self   = this;
			var queue  = [];

			_.each(files, function(file) {
				var ext = (file.name.split('.').pop() || '').toLowerCase();
				if (['woff2', 'ttf', 'otf'].indexOf(ext) !== -1) {
					queue.push(file);
				}
			});

			if (!queue.length) return;

			_.each(queue, function(file) {
				var formData = new FormData();
				formData.append('action', 'mkl_pc_upload_font');
				formData.append('nonce', config.nonce);
				formData.append('font_file', file);

				$.ajax({
					url: config.ajax_url,
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					success: function(response) {
						if (response.success && response.data && response.data.font) {
							var font = response.data.font;
							config.fonts = config.fonts || [];
							config.fonts.push(font);
							injectFontFace(font);
							self.renderFontList();
							self.populateCategoryFilter();
						}
					}
				});
			});
		},

		// ----- Delete -------------------------------------------------------

		deleteFont: function(e) {
			var $row   = $(e.currentTarget).closest('.to-font-row');
			var fontId = $row.data('font-id');
			var self   = this;

			$.ajax({
				url: config.ajax_url,
				type: 'POST',
				data: {
					action: 'mkl_pc_delete_font',
					nonce: config.nonce,
					font_id: fontId
				},
				success: function(response) {
					if (response.success) {
						config.fonts = response.data.fonts || [];
						self.renderFontList();
						self.populateCategoryFilter();
					}
				}
			});
		},

		// ----- Save all name/category edits --------------------------------

		markModified: function() {
			// Light visual cue could be placed here.
		},

		saveFontsLibrary: function() {
			var fontsPayload = [];
			this.$('.to-font-row').each(function() {
				var $row = $(this);
				fontsPayload.push({
					id:       $row.data('font-id'),
					name:     $row.find('.to-font-name-input').val(),
					category: $row.find('.to-font-category-input').val()
				});
			});

			var self = this;
			$.ajax({
				url: config.ajax_url,
				type: 'POST',
				data: {
					action: 'mkl_pc_save_fonts',
					nonce:  config.nonce,
					fonts:  fontsPayload
				},
				success: function(response) {
					if (response.success && response.data && response.data.fonts) {
						config.fonts = response.data.fonts;
						self.renderFontList();
						self.populateCategoryFilter();
					}
				}
			});
		}
	});

	// =========================================================================
	//  2.  FONT SELECTOR MODAL
	//      Opened from layer settings via ".to-open-font-selector" button.
	// =========================================================================

	function FontSelectorModal(options) {
		this.layerView     = options.layerView;
		this.model         = options.layerView.model;
		this.selected      = [];
		this.$el           = null;

		this.init();
	}

	FontSelectorModal.prototype = {

		init: function() {
			// Read current to_fonts from the model.
			var raw = this.model.get('to_fonts');
			if (_.isArray(raw)) {
				this.selected = _.map(raw, function(f) {
					return _.isObject(f) ? _.clone(f) : { name: f, family: f };
				});
			} else {
				this.selected = [];
			}

			var template = wp.template('mkl-pc-to-font-selector');
			this.$el = $(template({}));
			$('body').append(this.$el);

			this.renderLibraryList();
			this.renderSelectedList();
			this.bindEvents();
		},

		// ----- Rendering ----------------------------------------------------

		renderLibraryList: function(searchTerm) {
			var $list     = this.$el.find('.to-font-library-list');
			var allFonts  = config.fonts || [];
			var self      = this;

			$list.empty();

			if (searchTerm) {
				var lc = searchTerm.toLowerCase();
				allFonts = _.filter(allFonts, function(f) {
					return (f.name || '').toLowerCase().indexOf(lc) !== -1 ||
					       (f.family || '').toLowerCase().indexOf(lc) !== -1;
				});
			}

			if (!allFonts.length) {
				$list.html('<p class="description">' + escHtml(i18n.no_fonts || 'No fonts available.') + '</p>');
				return;
			}

			_.each(allFonts, function(font) {
				var $item = $('<div class="to-font-item">' +
					'<span class="to-font-item-name" style="font-family:\'' + escAttr(font.family) + '\'">' + escHtml(font.name) + '</span>' +
					'<button type="button" class="button-link to-add-font" data-font-id="' + escAttr(font.id) + '">' + escHtml(i18n.add_to_selection || 'Add to selection') + ' &gt;</button>' +
					'</div>');
				$list.append($item);
			});
		},

		renderSelectedList: function(searchTerm) {
			var $list = this.$el.find('.to-font-selected-list');
			var self  = this;
			var list  = this.selected;

			$list.empty();

			if (searchTerm) {
				var lc = searchTerm.toLowerCase();
				list = _.filter(list, function(f) {
					return (f.name || '').toLowerCase().indexOf(lc) !== -1 ||
					       (f.family || '').toLowerCase().indexOf(lc) !== -1;
				});
			}

			if (!list.length) {
				$list.html('<p class="description">' + escHtml(i18n.no_fonts_selected || 'No fonts selected') + '</p>');
				return;
			}

			_.each(list, function(font, idx) {
				var realIdx = _.indexOf(self.selected, font);
				var $item = $('<div class="to-font-item" data-index="' + realIdx + '">' +
					'<button type="button" class="button-link to-remove-font">&lt; ' + escHtml(i18n.remove_from_list || 'Remove from list') + '</button>' +
					'<span class="to-font-item-name" style="font-family:\'' + escAttr(font.family) + '\'">' + escHtml(font.name) + '</span>' +
					'<span class="to-font-sort-arrows">' +
						'<button type="button" class="button-link to-sort-up" title="Up">&uarr;</button>' +
						'<button type="button" class="button-link to-sort-down" title="Down">&darr;</button>' +
					'</span>' +
					'</div>');
				$list.append($item);
			});
		},

		// ----- Events -------------------------------------------------------

		bindEvents: function() {
			var self = this;

			// Add font to selection.
			this.$el.on('click', '.to-add-font', function() {
				var fontId = $(this).data('font-id');
				var font   = _.findWhere(config.fonts || [], { id: String(fontId) });
				if (!font) {
					font = _.findWhere(config.fonts || [], { id: fontId });
				}
				if (!font) return;
				// Prevent duplicates.
				var exists = _.findWhere(self.selected, { family: font.family });
				if (exists) return;
				self.selected.push({ name: font.name, family: font.family });
				self.renderSelectedList();
			});

			// Remove font from selection.
			this.$el.on('click', '.to-remove-font', function() {
				var idx = $(this).closest('.to-font-item').data('index');
				if (idx !== undefined && self.selected[idx]) {
					self.selected.splice(idx, 1);
					self.renderSelectedList();
				}
			});

			// Sort up.
			this.$el.on('click', '.to-sort-up', function() {
				var idx = $(this).closest('.to-font-item').data('index');
				if (idx > 0) {
					var tmp = self.selected[idx];
					self.selected[idx]     = self.selected[idx - 1];
					self.selected[idx - 1] = tmp;
					self.renderSelectedList();
				}
			});

			// Sort down.
			this.$el.on('click', '.to-sort-down', function() {
				var idx = $(this).closest('.to-font-item').data('index');
				if (idx < self.selected.length - 1) {
					var tmp = self.selected[idx];
					self.selected[idx]     = self.selected[idx + 1];
					self.selected[idx + 1] = tmp;
					self.renderSelectedList();
				}
			});

			// Search: library column.
			this.$el.on('input', '.to-font-search-library', function() {
				self.renderLibraryList($(this).val());
			});

			// Search: selected column.
			this.$el.on('input', '.to-font-search-selected', function() {
				self.renderSelectedList($(this).val());
			});

			// Save.
			this.$el.on('click', '.to-save-font-selection', function() {
				self.model.set('to_fonts', _.map(self.selected, function(f) {
					return { name: f.name, family: f.family };
				}));
				// Mark as modified so Save All picks it up.
				if (PC.app && PC.app.is_modified) {
					PC.app.is_modified['layers'] = true;
				}
				self.close();

				// Refresh the preview in the layer form.
				self.updatePreview();
			});

			// Cancel / close.
			this.$el.on('click', '.to-cancel, .to-modal-close', function() {
				self.close();
			});

			// ESC.
			$(document).on('keydown.to-font-selector', function(e) {
				if (e.keyCode === 27) self.close();
			});
		},

		updatePreview: function() {
			var $container = this.layerView.$('.to-selected-fonts-preview');
			if (!$container.length) return;
			var fonts = this.model.get('to_fonts') || [];
			if (!fonts.length) {
				$container.html('<em>' + escHtml(i18n.no_fonts_selected || 'No fonts selected') + '</em>');
			} else {
				var names = _.map(fonts, function(f) { return escHtml(f.name || f.family); });
				$container.html(names.join(', '));
			}
		},

		close: function() {
			$(document).off('keydown.to-font-selector');
			if (this.$el) this.$el.remove();
		}
	};

	// =========================================================================
	//  3.  POSITION OPTIONS EDITOR (Layer level)
	//      Renders the list of admin-defined position options (label + miniature)
	//      inside the layer settings form. Customers pick from these at runtime.
	// =========================================================================

	function parsePositionOptions(raw) {
		if (!raw) return [];
		if (_.isArray(raw)) return _.clone(raw);
		if (typeof raw === 'string') {
			// Fix data previously corrupted by esc_attr (&quot; instead of ").
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

	function newPositionOptionId() {
		return 'pos_' + Math.random().toString(36).slice(2, 9) + Date.now().toString(36).slice(-4);
	}

	function PositionOptionsEditor(layerFormView) {
		this.layerFormView = layerFormView;
		this.model         = layerFormView.model;
		this.$container    = layerFormView.$('.to-position-options-container');
		this.mediaFrame    = null;
		this.editingId     = null;

		if (!this.$container.length) return;

		this.options = parsePositionOptions(this.model.get('to_position_options'));
		this.render();
		this.bindEvents();
	}

	PositionOptionsEditor.prototype = {

		render: function() {
			var $list = this.$container.find('.to-position-options-list');
			$list.empty();

			if (!this.options.length) {
				$list.append('<p class="description to-position-options-empty">' +
					escHtml(i18n.no_position_options || 'No position options defined yet.') +
					'</p>');
				return;
			}

			var tpl = wp.template('mkl-pc-to-position-option-row');
			_.each(this.options, function(opt) {
				$list.append(tpl(opt));
			});
		},

		persist: function() {
			this.model.set('to_position_options', JSON.stringify(this.options));
			if (PC.app && PC.app.is_modified) {
				PC.app.is_modified['layers'] = true;
			}
		},

		bindEvents: function() {
			var self = this;

			// Add a new position option row.
			this.$container.off('click.to-pos').on('click.to-pos', '.to-add-position-option', function(e) {
				e.preventDefault();
				self.options.push({
					id: newPositionOptionId(),
					label: '',
					image_id: 0,
					image_url: ''
				});
				self.persist();
				self.render();
			});

			// Label input: store on blur/input.
			this.$container.on('input.to-pos', '.to-position-option-label', function() {
				var id  = $(this).closest('.to-position-option-row').data('position-id');
				var opt = _.findWhere(self.options, { id: String(id) });
				if (!opt) opt = _.findWhere(self.options, { id: id });
				if (!opt) return;
				opt.label = $(this).val();
				self.persist();
			});

			// Remove a position option.
			this.$container.on('click.to-pos', '.to-remove-position-option', function(e) {
				e.preventDefault();
				var id = $(this).closest('.to-position-option-row').data('position-id');
				self.options = _.reject(self.options, function(o) {
					return o.id === id || o.id === String(id);
				});
				self.persist();
				self.render();
			});

			// Pick / change miniature image.
			this.$container.on('click.to-pos', '.to-pick-miniature', function(e) {
				e.preventDefault();
				var id = $(this).closest('.to-position-option-row').data('position-id');
				self.editingId = id;
				self.openMediaPicker();
			});

			// Remove the miniature image.
			this.$container.on('click.to-pos', '.to-remove-miniature', function(e) {
				e.preventDefault();
				var id  = $(this).closest('.to-position-option-row').data('position-id');
				var opt = _.findWhere(self.options, { id: String(id) }) || _.findWhere(self.options, { id: id });
				if (!opt) return;
				opt.image_id  = 0;
				opt.image_url = '';
				self.persist();
				self.render();
			});
		},

		openMediaPicker: function() {
			var self = this;
			if (!this.mediaFrame) {
				this.mediaFrame = wp.media({
					title: i18n.choose_miniature || 'Choose miniature',
					button: { text: i18n.choose_miniature || 'Choose miniature' },
					multiple: false,
					library: { type: 'image' }
				});
				this.mediaFrame.on('select', function() {
					var attachment = self.mediaFrame.state().get('selection').first().toJSON();
					var opt = _.findWhere(self.options, { id: String(self.editingId) }) ||
					          _.findWhere(self.options, { id: self.editingId });
					if (!opt) return;
					opt.image_id  = attachment.id;
					// Prefer a medium-sized thumbnail for the miniature preview.
					var sizes = attachment.sizes || {};
					opt.image_url = (sizes.thumbnail && sizes.thumbnail.url) ||
					                (sizes.medium && sizes.medium.url) ||
					                attachment.url;
					self.persist();
					self.render();
				});
			}
			this.mediaFrame.open();
		}
	};

	// =========================================================================
	//  4.  (Removed) Per-angle text position modal.
	//      Positioning text on a reference image has been replaced by a simple
	//      list of position options the customer picks from at runtime — the
	//      product preview no longer shows any text overlay.
	// =========================================================================


	// =========================================================================
	//  HOOKS: integrate into PC admin events
	// =========================================================================

	if (wp.hooks && wp.hooks.addAction) {
		/**
		 * Hook: PC.admin.layer_form.render
		 * When a layer form is rendered and the layer type is text-overlay,
		 * initialize the font selector button, font preview, and position
		 * options editor.
		 */
		wp.hooks.addAction('PC.admin.layer_form.render', 'text-overlay', function(layerFormView) {
			if (!layerFormView || !layerFormView.model) return;

			var layerType = layerFormView.model.get('type');
			if (layerType !== 'text-overlay') return;

			// Initialize the font selector button handler.
			initFontSelectorButton(layerFormView);

			// Populate the fonts preview.
			updateFontPreviewDisplay(layerFormView);

			// Set up the position-options editor.
			new PositionOptionsEditor(layerFormView);
		});
	}

	/**
	 * Set up the .to-open-font-selector click handler on the layer form.
	 */
	function initFontSelectorButton(layerFormView) {
		layerFormView.$el.off('click.to-font-selector').on('click.to-font-selector', '.to-open-font-selector', function(e) {
			e.preventDefault();
			new FontSelectorModal({ layerView: layerFormView });
		});
	}

	/**
	 * Show the list of currently selected font names in the layer form preview area.
	 */
	function updateFontPreviewDisplay(layerFormView) {
		var $container = layerFormView.$('.to-selected-fonts-preview');
		if (!$container.length) return;

		var fonts = layerFormView.model.get('to_fonts') || [];
		if (!fonts.length) {
			$container.html('<em>' + escHtml(i18n.no_fonts_selected || 'No fonts selected') + '</em>');
		} else {
			var names = _.map(fonts, function(f) {
				return escHtml(_.isObject(f) ? (f.name || f.family) : f);
			});
			$container.html(names.join(', '));
		}
	}

})(jQuery, Backbone, PC._us || window._);
