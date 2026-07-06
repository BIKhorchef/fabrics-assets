/**
 * Ben Theme Hockerty UX - JavaScript
 * Simplified theme based on plugin native layers.
 */

(function($) {
	'use strict';

	if (!wp || !wp.hooks) return;

	var BenHockerty = {
		config: typeof pc_ben_hockerty_config !== 'undefined' ? pc_ben_hockerty_config : {},
		isMobile: false,

		init: function(view) {
			this.view = view;
			this.$el = view.$el;
			this.$el.addClass('ben-theme-hockerty-ux');
			
			if (this.config.color_mode) {
				this.$el.addClass('color-mode-' + this.config.color_mode);
			}
			
			this.checkViewport();
			$(window).on('resize.benHockerty', this.checkViewport.bind(this));
		},

		checkViewport: function() {
			this.isMobile = window.innerWidth <= 768;
			this.$el.toggleClass('is-mobile', this.isMobile);
			this.$el.toggleClass('is-desktop', !this.isMobile);
		}
	};

	// Initialize when configurator starts
	wp.hooks.addAction('PC.fe.start', 'MKL/PC/Themes/BenHockerty', function(view) {
		BenHockerty.init(view);
		PC.fe.config.show_layer_description_in_title = true;
	}, 20);

	// Handle layers list open
	wp.hooks.addAction('PC.fe.layers_list.open', 'MKL/PC/Themes/BenHockerty', function(view, model) {
		PC.fe.modal.$el.addClass('showing-choices');
	});

	// Handle layers list close
	wp.hooks.addAction('PC.fe.layers_list.close', 'MKL/PC/Themes/BenHockerty', function(view, model) {
		PC.fe.modal.$el.removeClass('showing-choices');
	});

	// Where to render choices
	wp.hooks.addFilter('PC.fe.choices.where', 'MKL/PC/Themes/BenHockerty', function(where, originalView) {
		if (originalView && originalView.model) {
			if (originalView.model.get('display_mode') === 'dropdown' && !PC.utils._isMobile()) {
				return 'in';
			}
			if (originalView.model.get('is_step')) {
				return 'in';
			}
		}
		return PC.fe.modal.toolbar.el;
	});

	// Tooltip options
	wp.hooks.addFilter('PC.fe.tooltip.options', 'MKL/PC/Themes/BenHockerty', function(options) {
		options.appendTo = function() { return document.body; };
		return options;
	});

})(jQuery);
