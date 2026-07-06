/**
 * Attribute Layer - Frontend JavaScript
 * Handles rendering and selection of WooCommerce attribute swatches
 * Works with attribute terms auto-converted to choices by PHP
 */
(function($, _) {
    'use strict';

    if (typeof PC === 'undefined') {
        return;
    }

    // Store for attribute selections
    PC.fe = PC.fe || {};
    PC.fe.attributeSelections = PC.fe.attributeSelections || {};

    /**
     * Escape HTML helper
     */
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Get thumbnail image URL from choice model
     */
    function getThumbnailUrl(model) {
        try {
            if (!model || typeof model.get !== 'function') return null;
            var images = model.get('images');
            if (!images || !Array.isArray(images) || images.length === 0) return null;
            var firstImage = images[0];
            if (!firstImage) return null;
            // Handle different image data structures - prefer thumbnail
            if (firstImage.thumbnail && firstImage.thumbnail.url) {
                return firstImage.thumbnail.url;
            }
            if (firstImage.image && firstImage.image.url) {
                return firstImage.image.url;
            }
            if (firstImage.url) {
                return firstImage.url;
            }
            if (typeof firstImage === 'string') {
                return firstImage;
            }
            return null;
        } catch (e) {
            return null;
        }
    }

    /**
     * Update the layer header to show selected attribute choice
     */
    function updateLayerHeader(layer, model) {
        try {
            var layerView = layer._layerView;
            if (!layerView || !layerView.$el) return;
            
            var $header = layerView.$el.find('.layer-content-header, .layer--header');
            if (!$header.length) return;
            
            // Find or create the selected preview element in header
            var $selectedPreview = $header.find('.mkl-pc-attr-selected-preview');
            if (!$selectedPreview.length) {
                $selectedPreview = $('<div class="mkl-pc-attr-selected-preview">' +
                    '<img class="mkl-pc-attr-selected-thumb" src="" alt="">' +
                    '<span class="mkl-pc-attr-selected-name"></span>' +
                '</div>');
                $header.append($selectedPreview);
            }
            
            if (model && typeof model.get === 'function') {
                var thumbUrl = getThumbnailUrl(model);
                
                // Get the choice name - use raw attributes to avoid filtering
                var name = model.attributes.name || model.get('name') || model.get('term_name') || '';
                
                if (thumbUrl) {
                    $selectedPreview.find('.mkl-pc-attr-selected-thumb').attr('src', thumbUrl).show();
                } else {
                    $selectedPreview.find('.mkl-pc-attr-selected-thumb').hide();
                }
                $selectedPreview.find('.mkl-pc-attr-selected-name').text(name);
                $selectedPreview.addClass('has-selection');
            } else {
                $selectedPreview.removeClass('has-selection');
            }
        } catch (e) {
            console.log('updateLayerHeader error:', e);
        }
    }

    /**
     * Close the layer panel (collapse it)
     */
    function closeLayerPanel(layer) {
        try {
            var layerView = layer._layerView;
            if (!layerView) return;
            
            // Trigger the layer close/collapse action
            if (layerView.$el) {
                layerView.$el.removeClass('is-open active');
            }
            
            // Try using the configurator's method to close layers
            if (PC.fe && PC.fe.configurator && typeof PC.fe.configurator.close_layer === 'function') {
                PC.fe.configurator.close_layer();
            } else if (PC.fe && PC.fe.views && PC.fe.views.configurator && PC.fe.views.configurator.close_layer) {
                PC.fe.views.configurator.close_layer();
            }
            
            // Alternative: trigger click on close button or layer header
            var $closeBtn = layerView.$el.find('.layer-close, .close-layer');
            if ($closeBtn.length) {
                $closeBtn.trigger('click');
            }
        } catch (e) {
            console.log('closeLayerPanel error:', e);
        }
    }

    /**
     * Update the inline preview (shown next to choices)
     */
    function updateInlinePreview(layerView, model) {
        try {
            if (!layerView || !layerView.choices) return;
            
            var $choicesContainer = layerView.choices.$el;
            var $preview = $choicesContainer.find('.mkl-pc-attribute-inline-preview');
            
            if (!$preview.length) {
                $preview = $('<div class="mkl-pc-attribute-inline-preview">' +
                    '<div class="mkl-pc-attribute-inline-preview-image-wrap">' +
                        '<img class="mkl-pc-attribute-inline-preview-image" src="" alt="">' +
                    '</div>' +
                    '<span class="mkl-pc-attribute-inline-preview-name"></span>' +
                '</div>');
                $choicesContainer.find('.choices-list').after($preview);
            }
            
            if (model && typeof model.get === 'function') {
                var imageUrl = getThumbnailUrl(model);
                
                // Get the choice name - use raw attributes to avoid filtering
                var name = model.attributes.name || model.get('name') || model.get('term_name') || '';
                
                if (imageUrl) {
                    $preview.find('.mkl-pc-attribute-inline-preview-image').attr('src', imageUrl).attr('alt', name);
                    $preview.find('.mkl-pc-attribute-inline-preview-name').text(name);
                    $preview.addClass('has-selection');
                } else {
                    $preview.removeClass('has-selection');
                }
            } else {
                $preview.removeClass('has-selection');
            }
        } catch (e) {
            console.log('updateInlinePreview error:', e);
        }
    }

    /**
     * CRITICAL: Hook into layer rendering to create choices for attribute layers
     * The main configurator only creates choices for 'simple' and 'group' types
     * We need to create them for 'attribute' type as well
     */
    wp.hooks.addAction('PC.fe.layer.beforeRenderChoices', 'mkl/attribute-layer', function(view) {
        if (!view || !view.model) return;
        
        var layer = view.model;
        var layerType = layer.get('type');
        
        // Only handle attribute layers
        if (layerType !== 'attribute') return;
        
        // Get the content for this layer
        var content = PC.fe.getLayerContent ? PC.fe.getLayerContent(layer.id) : null;
        
        if (!content || !content.length) {
            console.log('Attribute layer has no content:', layer.get('name'), layer.id);
            return;
        }
        
        // Create choices view (same as 'simple' type does)
        view.choices = new PC.fe.views.choices({ 
            content: content, 
            model: layer 
        });
        
        // Add attribute-specific classes
        if (view.choices && view.choices.$el) {
            view.choices.$el.addClass('pc-choices--attribute');
            view.choices.$el.addClass('type-attribute');
            
            var displayStyle = layer.get('attribute_display_style') || 'image';
            var swatchSize = layer.get('attribute_swatch_size') || 'medium';
            
            view.choices.$el.addClass('display-style--' + displayStyle);
            view.choices.$el.addClass('swatch-size--' + swatchSize);
        }
        
        // Store reference for later preview updates
        view.model._layerView = view;
        
    }, 5);

    /**
     * Hook into choice item rendering to add swatch styling for attribute terms
     */
    wp.hooks.addAction('PC.fe.configurator.choice-item.render', 'mkl/attribute-layer', function(view) {
        if (!view || !view.model) return;
        
        var model = view.model;
        var layer = model.collection && model.collection.layer;
        
        // Check if this is an attribute layer
        if (!layer || layer.get('type') !== 'attribute') return;
        
        // Add attribute-specific classes
        view.$el.addClass('mkl-pc-attribute-choice');
        
        // Get display settings from layer
        var displayStyle = layer.get('attribute_display_style') || 'image';
        var swatchSize = layer.get('attribute_swatch_size') || 'medium';
        var showLabel = layer.get('attribute_show_label');
        
        // Check if we have the data for the display style, fallback to text if not
        var color = model.get('color');
        var hasImage = model.get('images') && model.get('images').length > 0;
        
        if (displayStyle === 'color' && !color) {
            displayStyle = 'text'; // Fallback to text if no color data
        }
        if (displayStyle === 'image' && !hasImage) {
            displayStyle = 'text'; // Fallback to text if no image
        }
        
        view.$el.addClass('swatch-style--' + displayStyle);
        view.$el.addClass('swatch-size--' + swatchSize);
        
        // Restructure the choice item for inline layout with view button
        var $choiceItem = view.$('.choice-item');
        if ($choiceItem.length && !view.$el.hasClass('mkl-pc-attr-restructured')) {
            view.$el.addClass('mkl-pc-attr-restructured');
            
            var $thumbnail = $choiceItem.find('.mkl-pc-thumbnail');
            var $name = $choiceItem.find('.choice-name');
            
            // Add size class to images
            $thumbnail.find('img').addClass('mkl-pc-swatch--' + swatchSize);
            
            // If it's a color swatch and has color data
            if (displayStyle === 'color' && color) {
                if (!$thumbnail.find('.mkl-pc-swatch-color').length) {
                    var colorEl = $('<span class="mkl-pc-swatch-color mkl-pc-swatch--' + swatchSize + '"></span>');
                    colorEl.css('background-color', color);
                    $thumbnail.prepend(colorEl);
                }
            }
            
            // For text style, make sure name is visible
            if (displayStyle === 'text') {
                view.$el.addClass('mkl-pc-attribute-swatch--text');
            }
            
            // Add label if needed
            if (showLabel && displayStyle !== 'text') {
                if (!view.$('.mkl-pc-swatch-label').length) {
                    var name = model.get('name') || '';
                    $choiceItem.append('<span class="mkl-pc-swatch-label">' + escapeHtml(name) + '</span>');
                }
            }
        }
        
        // Ensure active state is visually reflected
        if (model.get('active')) {
            view.$el.addClass('active');
        }
        
        // Listen to model changes for active state
        model.on('change:active', function() {
            if (model.get('active')) {
                view.$el.addClass('active');
            } else {
                view.$el.removeClass('active');
            }
        });
    });

    /**
     * Direct mousedown handler for attribute choices - ensures selection works
     * Core selectChoice only handles 'simple' and 'multiple' types, not 'attribute'
     */
    $(document).on('mousedown', '.pc-layer--attribute .choice .choice-item, .pc-choices--attribute .choice .choice-item', function(e) {
        // Only respond to left mouse button
        if (e.button !== 0) return;
        
        var $choice = $(this).closest('.choice');
        var view = $choice.data('view');
        
        if (!view || !view.model) return;
        
        var model = view.model;
        var layer = model.collection && model.collection.layer;
        
        if (!layer || layer.get('type') !== 'attribute') return;
        
        // Group headers are not selectable
        if (model.get('is_group')) return;
        
        // Prevent default to stop the core handler from also running (which does nothing for attribute type)
        e.stopImmediatePropagation();
        
        // Deactivate ALL choices in the layer (only one selection allowed across all groups)
        model.collection.each(function(choice) {
            if (choice.get('active')) {
                choice.set('active', false);
            }
        });
        
        // Activate this choice
        model.set('active', true);
        
        // Update the layer's selected choice
        layer.set('selectedChoice', model.id);
        
        // Fire the standard hook
        wp.hooks.doAction('PC.fe.choice.set_choice', model, view);
        wp.hooks.doAction('PC.fe.choice.change', model);
    });

    /**
     * Hook into layer rendering to add attribute layer classes and inline preview
     */
    wp.hooks.addAction('PC.fe.layer.render', 'mkl/attribute-layer', function(view) {
        if (!view || !view.model) return;
        
        var layer = view.model;
        if (layer.get('type') !== 'attribute' && !layer.get('is_attribute_layer')) return;
        
        // Add attribute layer class
        view.$el.addClass('pc-layer--attribute');
        view.$el.attr('data-layer-type', 'attribute');
        
        // Store view reference 
        layer._layerView = view;
        
        // Add classes to choices container
        if (view.choices && view.choices.$el) {
            view.choices.$el.addClass('pc-choices--attribute');
            
            var displayStyle = layer.get('attribute_display_style') || 'image';
            var swatchSize = layer.get('attribute_swatch_size') || 'medium';
            
            view.choices.$el.addClass('display-style--' + displayStyle);
            view.choices.$el.addClass('swatch-size--' + swatchSize);
            
            // Insert group headers for multi-attribute layers (handled natively via is_group + parent)
            setTimeout(function() {
                try {
                    var content = PC.fe.getLayerContent ? PC.fe.getLayerContent(layer.id) : null;
                    if (!content) return;
                    
                    // Update inline preview for active choices
                    if (typeof content.findWhere === 'function') {
                        var activeChoice = content.findWhere({ active: true });
                        if (activeChoice && typeof activeChoice.get === 'function') {
                            updateInlinePreview(view, activeChoice);
                        }
                    }
                } catch (e) {
                    console.log('Attribute layer render error:', e);
                }
            }, 100);
        }
    });

    /**
     * Update inline preview when choice is activated
     */
    wp.hooks.addAction('PC.fe.choice.activate', 'mkl/attribute-layer', function(view) {
        if (!view || !view.model) return;
        
        var model = view.model;
        var layer = model.collection && model.collection.layer;
        
        if (!layer || layer.get('type') !== 'attribute') return;
        
        // Ensure the active class is added to the view element
        if (view.$el) {
            view.$el.addClass('active');
        }
        
        // Update the inline preview with the selected choice
        var layerView = layer._layerView;
        if (layerView) {
            updateInlinePreview(layerView, model);
        }
        
        // Update the layer header with selected choice
        updateLayerHeader(layer, model);
        
        // Close the layer panel after selection (with small delay for visual feedback)
        setTimeout(function() {
            closeLayerPanel(layer);
        }, 150);
    });
    
    /**
     * Ensure deactivated choices lose the active class
     */
    wp.hooks.addAction('PC.fe.choice.deactivate', 'mkl/attribute-layer', function(view) {
        if (!view || !view.model) return;
        
        var model = view.model;
        var layer = model.collection && model.collection.layer;
        
        if (!layer || layer.get('type') !== 'attribute') return;
        
        // Remove the active class from the view element
        if (view.$el) {
            view.$el.removeClass('active');
        }
    });

    /**
     * Hide attribute layer images in the viewer when switching to another layer type
     * This prevents visual conflicts between attribute swatches and other layer content
     */
    wp.hooks.addAction('PC.fe.layer.activate', 'mkl/attribute-layer', function(view) {
        if (!view || !view.model) return;
        
        var activeLayer = view.model;
        var activeLayerType = activeLayer.get('type');
        
        // Get the viewer layers container
        var $viewerLayers = $('.mkl_pc_layers');
        if (!$viewerLayers.length) return;
        
        // Go through all layers
        if (PC.fe && PC.fe.layers) {
            PC.fe.layers.each(function(layer) {
                var layerId = layer.id;
                var layerType = layer.get('type');
                
                // Only handle attribute layers
                if (layerType !== 'attribute') return;
                
                // Find all viewer images for this attribute layer
                var $layerImages = $viewerLayers.find('[data-layer_id="' + layerId + '"]');
                
                if (activeLayerType === 'attribute' && activeLayer.id === layerId) {
                    // This attribute layer is active - show its images
                    $layerImages.css('visibility', 'visible');
                    
                    // Also show the inline preview
                    var layerView = layer._layerView;
                    if (layerView && layerView.choices && layerView.choices.$el) {
                        layerView.choices.$el.find('.mkl-pc-attribute-inline-preview').show();
                    }
                } else {
                    // This attribute layer is NOT active - hide its images
                    $layerImages.css('visibility', 'hidden');
                    
                    // Also hide the inline preview
                    var layerView = layer._layerView;
                    if (layerView && layerView.choices && layerView.choices.$el) {
                        layerView.choices.$el.find('.mkl-pc-attribute-inline-preview').hide();
                    }
                }
            });
        }
    });

    /**
     * Hide attribute layer images when a layer panel is closed/deactivated
     * This reveals the base product image (e.g. shirt) when no attribute layer is open
     */
    wp.hooks.addAction('PC.fe.layer.deactivate', 'mkl/attribute-layer', function(view) {
        if (!view || !view.model) return;
        
        var deactivatedLayer = view.model;
        
        // Only care about attribute layers being deactivated
        if (deactivatedLayer.get('type') !== 'attribute') return;
        
        var layerId = deactivatedLayer.id;
        
        // Get the viewer layers container
        var $viewerLayers = $('.mkl_pc_layers');
        if (!$viewerLayers.length) return;
        
        // Hide this attribute layer's images in the viewer
        var $layerImages = $viewerLayers.find('[data-layer_id="' + layerId + '"]');
        $layerImages.css('visibility', 'hidden');
        
        // Also hide the inline preview
        var layerView = deactivatedLayer._layerView;
        if (layerView && layerView.choices && layerView.choices.$el) {
            layerView.choices.$el.find('.mkl-pc-attribute-inline-preview').hide();
        }
    });

    /**
     * Track attribute selections when a choice is selected
     */
    wp.hooks.addAction('PC.fe.choice.set_choice', 'mkl/attribute-layer', function(model, view) {
        if (!model) return;
        
        // Check if this choice is from an attribute layer
        var layer = model.collection && model.collection.layer;
        if (!layer || layer.get('type') !== 'attribute') return;
        
        var layerId = layer.id || layer.get('_id');
        
        // Get thumbnail URL for cart display
        var thumbUrl = getThumbnailUrl(model);
        
        // Get the choice name - use raw attributes to avoid filtering
        var choiceName = model.attributes.name || model.get('name') || model.get('term_name') || '';
        
        // One selection per layer (not per group)
        PC.fe.attributeSelections[layerId] = {
            layer_id: layerId,
            layer_name: layer.get('name'),
            group: model.get('group') || '',
            group_label: model.get('group_label') || '',
            term_id: model.get('term_id') || model.id,
            term_slug: model.get('term_slug') || model.get('slug') || '',
            term_name: choiceName,
            taxonomy: model.get('taxonomy') || '',
            image_url: thumbUrl || ''
        };
        
        // Update layer header
        updateLayerHeader(layer, model);
        
        // Trigger custom event
        wp.hooks.doAction('PC.fe.attribute.selected', model, layer);
    }, 5);

    /**
     * Initialize attribute selections from default/active choices on configurator start
     */
    wp.hooks.addAction('PC.fe.start', 'mkl/attribute-layer', function(configurator) {
        // Reset selections
        PC.fe.attributeSelections = {};
        
        // Find all attribute layers and their single active choice
        if (PC.fe && PC.fe.layers) {
            PC.fe.layers.each(function(layer) {
                if (layer.get('type') !== 'attribute' && !layer.get('is_attribute_layer')) return;
                
                var choices = PC.fe.getLayerContent ? PC.fe.getLayerContent(layer.id) : null;
                if (!choices) return;
                
                var layerId = layer.id || layer.get('_id');
                
                // Find the single active choice (only one allowed per layer)
                var activeChoice = choices.findWhere({ active: true });
                if (activeChoice) {
                    var thumbUrl = getThumbnailUrl(activeChoice);
                    var choiceName = activeChoice.attributes.name || activeChoice.get('name') || activeChoice.get('term_name') || '';
                    
                    PC.fe.attributeSelections[layerId] = {
                        layer_id: layerId,
                        layer_name: layer.get('name'),
                        group: activeChoice.get('group') || '',
                        group_label: activeChoice.get('group_label') || '',
                        term_id: activeChoice.get('term_id') || activeChoice.id,
                        term_slug: activeChoice.get('term_slug') || activeChoice.get('slug') || '',
                        term_name: choiceName,
                        taxonomy: activeChoice.get('taxonomy') || '',
                        image_url: thumbUrl || ''
                    };
                }
                
                // Update layer header with initial selection (with delay for view to be ready)
                setTimeout(function() {
                    if (activeChoice) {
                        updateLayerHeader(layer, activeChoice);
                    }
                    
                    // Hide attribute layer images in the viewer on start
                    // so the base product (shirt) is visible when no layer panel is open
                    var $viewerLayers = $('.mkl_pc_layers');
                    if ($viewerLayers.length) {
                        var $layerImages = $viewerLayers.find('[data-layer_id="' + layerId + '"]');
                        $layerImages.css('visibility', 'hidden');
                    }
                }, 200);
            });
        }
    }, 25);

    /**
     * Reset attribute selections when configurator resets
     */
    wp.hooks.addAction('PC.fe.reset_configurator', 'mkl/attribute-layer', function() {
        PC.fe.attributeSelections = {};
    });

    /**
     * Add hidden input with attribute selections before form submission
     */
    $(document).on('submit', 'form.cart', function() {
        if (PC.fe && PC.fe.attributeSelections && Object.keys(PC.fe.attributeSelections).length) {
            var $form = $(this);
            var selections = Object.values(PC.fe.attributeSelections);
            var selectionsJson = JSON.stringify(selections);
            
            // Remove existing input
            $form.find('input[name="pc_attribute_selections"]').remove();
            
            // Add new hidden input
            $('<input>').attr({
                type: 'hidden',
                name: 'pc_attribute_selections',
                value: selectionsJson
            }).appendTo($form);
        }
    });

    /**
     * Hook into add_to_cart.before to add hidden input before AJAX serialization
     */
    wp.hooks.addAction('PC.fe.add_to_cart.before', 'mkl/attribute-layer', function(formView) {
        if (PC.fe && PC.fe.attributeSelections && Object.keys(PC.fe.attributeSelections).length) {
            var $form = formView.$cart;
            if (!$form || !$form.length) return;
            
            var selections = Object.values(PC.fe.attributeSelections);
            var selectionsJson = JSON.stringify(selections);
            
            // Remove existing input
            $form.find('input[name="pc_attribute_selections"]').remove();
            
            // Add new hidden input
            $('<input>').attr({
                type: 'hidden',
                name: 'pc_attribute_selections',
                value: selectionsJson
            }).appendTo($form);
        }
    });

    /**
     * Also hook into adding_to_cart event to append to AJAX data
     */
    $(document.body).on('adding_to_cart', function(e, btn, data) {
        if (PC.fe && PC.fe.attributeSelections && Object.keys(PC.fe.attributeSelections).length) {
            data.pc_attribute_selections = JSON.stringify(Object.values(PC.fe.attributeSelections));
        }
    });

    /**
     * Hook to add attribute selections to extra save data
     */
    wp.hooks.addFilter('PC.fe.save_data.extra_data', 'mkl/attribute-layer', function(data) {
        if (PC.fe && PC.fe.attributeSelections && Object.keys(PC.fe.attributeSelections).length) {
            data.attribute_selections = Object.values(PC.fe.attributeSelections);
        }
        return data;
    });

})(jQuery, PC._us || window._);
