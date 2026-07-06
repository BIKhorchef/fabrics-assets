/**
 * Conditional Logic - Frontend Evaluation Engine
 *
 * Evaluates conditions when users interact with the configurator.
 * Sets/unsets `cshow` attribute on Backbone models to show/hide elements.
 *
 * Expected API used by configurator.js:
 *   PC.conditionalLogic.item_is_hidden( model )
 *   PC.conditionalLogic.parent_is_hidden( model )
 */
var PC = window.PC || {};

( function( $, _ ) {

	PC.conditionalLogic = {

		conditions: [],
		condition_results: {},
		initialized: false,

		/**
		 * Initialize: parse conditions and bind to selection events
		 */
		init: function() {
			// Conditions live on the per-product config:
			//   PC.productData.prod_<active_product>.conditions
			// (NOT PC.fe.config.conditions — that's the global PC_config.config).
			// Try every plausible location.
			var raw = [];
			if ( PC.fe && PC.fe.currentProductData && PC.fe.currentProductData.conditions ) {
				raw = PC.fe.currentProductData.conditions;
			} else if ( PC.fe && PC.fe.active_product && PC.productData && PC.productData[ 'prod_' + PC.fe.active_product ] && PC.productData[ 'prod_' + PC.fe.active_product ].conditions ) {
				raw = PC.productData[ 'prod_' + PC.fe.active_product ].conditions;
			} else if ( PC.fe && PC.fe.config && PC.fe.config.conditions ) {
				raw = PC.fe.config.conditions;
			}

			// (Re)load conditions if they became available since last init call.
			if ( raw && raw.length ) {
				this.conditions = _.sortBy( raw, function( c ) {
					return parseInt( c.sort_order, 10 ) || 0;
				} );
			}

			if ( this.initialized ) return;
			this.initialized = true;

			// Listen for choice selection changes
			wp.hooks.addAction( 'PC.fe.choice.set_choice', 'mkl/pc/conditional-logic', this.on_choice_change.bind( this ) );

			// Listen for choice selection for Sync ID feature
			wp.hooks.addAction( 'PC.fe.choice.set_choice', 'mkl/pc/conditional-logic/sync', this.sync_choice.bind( this ) );

			// Run once now in case data is already loaded
			_.defer( this.run_all.bind( this ) );
		},

		/**
		 * Called when a choice is clicked/selected
		 */
		on_choice_change: function( model ) {
			var layer_id = model.get( 'layerId' );
			this.run( layer_id, model.id );
		},

		/**
		 * Sync ID: When a choice is selected, find other layers with the same sync_id
		 * and select the choice at the same position.
		 */
		sync_choice: function( model ) {
			if ( ! PC.fe || ! PC.fe.layers || ! PC.fe.getLayerContent ) return;

			var source_layer_id = model.get( 'layerId' );
			var source_layer = PC.fe.layers.get( source_layer_id );
			if ( ! source_layer ) return;

			var sync_id = source_layer.get( 'sync_id' );
			if ( ! sync_id ) return;

			// Find the position of the selected choice in the source layer
			var source_choices = PC.fe.getLayerContent( source_layer_id );
			if ( ! source_choices ) return;

			var choice_index = -1;
			source_choices.each( function( c, i ) {
				if ( c.id === model.id ) {
					choice_index = i;
				}
			} );

			if ( choice_index < 0 ) return;

			// Find all other layers with the same sync_id
			PC.fe.layers.each( function( layer ) {
				if ( layer.id === source_layer_id ) return;
				if ( layer.get( 'sync_id' ) !== sync_id ) return;

				var choices = PC.fe.getLayerContent( layer.id );
				if ( ! choices ) return;

				// Select the choice at the same position
				var target_choice = choices.at( choice_index );
				if ( target_choice && choices.selectChoice ) {
					choices.selectChoice( target_choice.id );
				}
			} );
		},

		/**
		 * Run all conditions (on init or when needed)
		 */
		run_all: function() {
			this.condition_results = {};
			for ( var i = 0; i < this.conditions.length; i++ ) {
				var condition = this.conditions[ i ];
				if ( ! condition.enabled ) continue;
				this.evaluate_and_execute( condition );
			}
			wp.hooks.doAction( 'mkl_checked_conditions' );
		},

		/**
		 * Run conditions relevant to a specific change
		 */
		run: function( changed_layer_id, changed_choice_id ) {
			this.condition_results = {};
			var something_changed = false;

			for ( var i = 0; i < this.conditions.length; i++ ) {
				var condition = this.conditions[ i ];
				if ( ! condition.enabled ) continue;

				// Check if we should evaluate this condition
				if ( condition.always_check ) {
					this.evaluate_and_execute( condition );
					something_changed = true;
				} else if ( this.condition_references_element( condition, changed_layer_id, changed_choice_id ) ) {
					this.evaluate_and_execute( condition );
					something_changed = true;
				}
			}

			// If any conditions depend on other conditions, do a second pass
			if ( something_changed ) {
				for ( var j = 0; j < this.conditions.length; j++ ) {
					var c = this.conditions[ j ];
					if ( ! c.enabled ) continue;
					if ( c.always_check || this.condition_references_conditions( c ) ) {
						this.evaluate_and_execute( c );
					}
				}
			}

			wp.hooks.doAction( 'mkl_checked_conditions' );
		},

		/**
		 * Check if a condition's rules reference a specific layer/choice
		 */
		condition_references_element: function( condition, layer_id, choice_id ) {
			var rules = condition.rules || [];
			for ( var i = 0; i < rules.length; i++ ) {
				var rule = rules[ i ];
				if ( rule.trigger_type === 'layer' && rule.trigger_parent_id == layer_id ) {
					return true;
				}
			}
			return false;
		},

		/**
		 * Check if a condition's rules reference other conditions
		 */
		condition_references_conditions: function( condition ) {
			var rules = condition.rules || [];
			for ( var i = 0; i < rules.length; i++ ) {
				if ( rules[ i ].trigger_type === 'condition' ) return true;
			}
			return false;
		},

		/**
		 * Evaluate a condition and execute its actions
		 */
		evaluate_and_execute: function( condition ) {
			var result = this.evaluate_condition( condition );
			this.condition_results[ condition._id ] = result;

			if ( result ) {
				this.execute_actions( condition.actions || [], false );
			} else if ( condition.reversible ) {
				this.execute_actions( condition.actions || [], true );
			}
		},

		/**
		 * Evaluate all rules in a condition
		 * Returns true if condition is met
		 */
		evaluate_condition: function( condition ) {
			var rules = condition.rules || [];
			if ( ! rules.length ) return false;

			var comparison = condition.comparison || 'all';
			var results = [];

			for ( var i = 0; i < rules.length; i++ ) {
				results.push( this.evaluate_rule( rules[ i ] ) );
			}

			if ( comparison === 'all' ) {
				return _.every( results );
			} else {
				return _.some( results );
			}
		},

		/**
		 * Evaluate a single rule
		 */
		evaluate_rule: function( rule ) {
			var trigger_type = rule.trigger_type;
			var parent_id = parseInt( rule.trigger_parent_id, 10 );
			var element = rule.trigger_element;
			var state = rule.element_state || 'selected';

			if ( trigger_type === 'layer' ) {
				return this.evaluate_layer_rule( parent_id, element, state );
			} else if ( trigger_type === 'condition' ) {
				return this.evaluate_condition_rule( parent_id, state );
			}

			return false;
		},

		/**
		 * Evaluate a rule that checks a layer/choice state
		 */
		evaluate_layer_rule: function( layer_id, element, state ) {
			if ( ! PC.fe || ! PC.fe.getLayerContent ) return false;

			var choices = PC.fe.getLayerContent( layer_id );
			if ( ! choices ) return false;

			var is_match = false;

			if ( element === 'any' ) {
				// "Any choice" — true if any choice is active
				is_match = choices.some( function( c ) {
					return c.get( 'active' ) === true;
				} );
			} else if ( element === 'none' ) {
				// "No choice" — true if no choice is active
				is_match = ! choices.some( function( c ) {
					return c.get( 'active' ) === true;
				} );
			} else {
				// Specific choice
				var choice_id = parseInt( element, 10 );
				var choice = choices.get( choice_id );
				if ( choice ) {
					is_match = choice.get( 'active' ) === true;
				}
			}

			// Apply state modifier
			if ( state === 'selected' ) {
				return is_match;
			} else if ( state === 'not_selected' ) {
				return ! is_match;
			} else if ( state === 'clicked' ) {
				// "clicked" behaves like "selected" for evaluation purposes
				// (the real distinction is that it doesn't persist on reload)
				return is_match;
			}

			return false;
		},

		/**
		 * Evaluate a rule that checks another condition's result
		 */
		evaluate_condition_rule: function( condition_id, state ) {
			var result = this.condition_results[ condition_id ];
			if ( typeof result === 'undefined' ) result = false;

			if ( state === 'selected' ) {
				return result === true;
			} else if ( state === 'not_selected' ) {
				return result !== true;
			}
			return result === true;
		},

		/**
		 * Execute actions (or their opposites if reversed)
		 */
		execute_actions: function( actions, reversed ) {
			for ( var i = 0; i < actions.length; i++ ) {
				var action = actions[ i ];
				var type = reversed ? this.get_opposite_action( action.action_type ) : action.action_type;
				this.execute_action( type, action.target_type, action.target_element_id );
			}
		},

		/**
		 * Get the opposite action type (for reversible conditions)
		 */
		get_opposite_action: function( action_type ) {
			var opposites = {
				'show': 'hide',
				'hide': 'show',
				'select': 'deselect',
				'deselect': 'select',
				'disable': 'enable',
				'enable': 'disable',
				'show_in_menu': 'hide_in_menu',
				'hide_in_menu': 'show_in_menu',
			};
			return opposites[ action_type ] || action_type;
		},

		/**
		 * Execute a single action on a target.
		 *
		 * Supports three target_type values:
		 *   - "layer"  : a layer model (PC.fe.layers)
		 *   - "choice" : an individual choice in a layer's content
		 *   - "group"  : a choice with is_group=true; the action cascades
		 *                over the group header + every direct child choice
		 *                whose parent === the group's id (same layer).
		 */
		execute_action: function( action_type, target_type, target_id ) {
			target_id = parseInt( target_id, 10 );
			if ( ! target_id ) return;

			if ( target_type === 'group' ) {
				var members = this.get_group_members( target_id );
				if ( ! members.length ) return;
				for ( var i = 0; i < members.length; i++ ) {
					this.apply_action_to_model( action_type, 'choice', members[ i ] );
				}
				// When hiding/disabling a whole group, also drop any active
				// child so the canvas stops rendering its image and the
				// layer state stays consistent with the visible options.
				if ( action_type === 'hide' || action_type === 'disable' ) {
					for ( var j = 1; j < members.length; j++ ) {     // skip header at index 0
						if ( members[ j ].get( 'active' ) ) {
							members[ j ].set( 'active', false );
						}
					}
				}
				return;
			}

			var model = this.get_target_model( target_type, target_id );
			if ( ! model ) return;
			this.apply_action_to_model( action_type, target_type, model );

			// For non-group hides, also auto-deselect a now-hidden choice
			// so its image disappears from the viewer.
			if ( target_type === 'choice' && action_type === 'hide' && model.get( 'active' ) ) {
				model.set( 'active', false );
			}
		},

		/**
		 * Apply a single action to one already-resolved model.
		 * Shared by both single-target and group cascade paths.
		 */
		apply_action_to_model: function( action_type, target_type, model ) {
			switch ( action_type ) {
				case 'show':
					if ( model.get( 'cshow' ) === false ) {
						model.set( 'cshow', true );
					}
					if ( target_type === 'layer' ) {
						this.cascade_layer_visibility( model, true );
					}
					break;

				case 'hide':
					model.set( 'cshow', false );
					if ( target_type === 'layer' ) {
						this.cascade_layer_visibility( model, false );
					}
					break;

				case 'select':
					if ( target_type === 'choice' && model.collection ) {
						model.collection.selectChoice( model.id );
					} else if ( target_type === 'layer' ) {
						model.set( 'active', true );
					}
					break;

				case 'deselect':
					if ( target_type === 'choice' ) {
						model.set( 'active', false );
					}
					break;

				case 'disable':
					model.set( 'disabled', true );
					break;

				case 'enable':
					model.set( 'disabled', false );
					break;

				case 'reset_layer':
					if ( target_type === 'layer' ) {
						this.reset_layer( model.id );
					}
					break;

				case 'show_in_menu':
					if ( target_type === 'layer' ) {
						model.set( 'hide_in_configurator', false );
					}
					break;

				case 'hide_in_menu':
					if ( target_type === 'layer' ) {
						model.set( 'hide_in_configurator', true );
					}
					break;
			}
		},

		/**
		 * Cascade a layer-level show/hide to every choice in that layer.
		 * Without this, `target_type: "layer"` only toggles the layer's
		 * menu button — the choices container (and the layer header in
		 * the toolbar) stays visible because the choices view only
		 * listens to its own models' `cshow`.
		 */
		cascade_layer_visibility: function( layer_model, visible ) {
			if ( ! PC.fe || ! PC.fe.getLayerContent ) return;
			var choices = PC.fe.getLayerContent( layer_model.id );
			if ( ! choices ) return;
			choices.each( function( c ) {
				c.set( 'cshow', visible );
				if ( ! visible && c.get( 'active' ) ) {
					c.set( 'active', false );
				}
			} );
		},

		/**
		 * Auto-select the default (or first available) choice for every
		 * `simple` and `attribute` layer that doesn't already have one
		 * active. Called once on initial mount so steps don't open with
		 * no selection — and so default-driven conditions fire on load.
		 *
		 * For attribute layers, just calling reset_layer() flips
		 * `active: true` on the model but never fires the standard
		 * selection hooks — so the viewer doesn't paint the fabric and
		 * other addons (option-selector, attribute selection tracker)
		 * don't see the choice. We replicate the full selection sequence
		 * from attribute-layer.js's mousedown handler instead.
		 */
		auto_select_defaults: function() {
			if ( ! PC.fe || ! PC.fe.layers || ! PC.fe.getLayerContent ) return;
			PC.fe.layers.each( function( layer ) {
				var type = layer.get( 'type' );
				if ( type !== 'simple' && type !== 'attribute' ) return;
				if ( layer.get( 'default_selection' ) === 'select_nothing' ) return;
				var choices = PC.fe.getLayerContent( layer.id );
				if ( ! choices || ! choices.length ) return;

				// Pick the target: the explicit default (is_default flag) if any,
				// otherwise the active non-group choice already chosen by core,
				// otherwise the first available non-group choice. This also
				// override the case where core's choices-view auto-activated a
				// group header instead of a real swatch.
				var target = choices.find( function( c ) {
					return c.get( 'is_default' ) && ! c.get( 'is_group' ) && c.get( 'available' ) !== false;
				} );
				if ( ! target ) {
					target = choices.find( function( c ) {
						return c.get( 'active' ) === true && ! c.get( 'is_group' ) && c.get( 'available' ) !== false;
					} );
				}
				if ( ! target ) {
					target = choices.find( function( c ) {
						return ! c.get( 'is_group' ) && c.get( 'available' ) !== false;
					} );
				}
				if ( ! target ) return;

				// Deactivate every other choice — mirrors the user-click path
				// in attribute-layer.js. Always run this even if `target` is
				// already active, so group headers the core view auto-picked
				// get cleared.
				choices.each( function( c ) {
					if ( c.id !== target.id && c.get( 'active' ) ) {
						c.set( 'active', false );
					}
				} );

				// Force a change cycle on the target. The viewer's
				// viewer_layer listens to `change:active` to repaint the
				// canvas — if `active` was already true (e.g. set by PHP
				// in initial data), no event ever fires and the canvas
				// never knows to render. Toggle false→true to guarantee it.
				if ( target.get( 'active' ) === true ) {
					target.set( 'active', false, { silent: true } );
				}
				target.set( 'active', true );
				layer.set( 'selectedChoice', target.id );

				// Fire the same hooks the user-click path fires, so the canvas
				// re-renders and other addons react.
				if ( wp && wp.hooks ) {
					wp.hooks.doAction( 'PC.fe.choice.set_choice', target, null );
					wp.hooks.doAction( 'PC.fe.choice.change', target );
				}

				// Also fire preload-image so the viewer_layer pulls the full-res
				// image into the canvas; this matches the mouseenter/focus path
				// on the choice view (configurator.js:310-312).
				if ( target.trigger ) {
					target.trigger( 'preload-image' );
				}
			} );
		},

		/**
		 * Resolve a group choice id to its full member set:
		 *   [ headerModel, ...childModels ] in the same layer.
		 *
		 * The group is identified by a choice with is_group=true. Children
		 * are direct children only (parent === group_id), matching the
		 * data shape produced by the configurator (e.g. layer 1 in product
		 * 6349 where group "Anglais" id=6 has children with parent=6).
		 */
		get_group_members: function( group_choice_id ) {
			var found = [];
			if ( ! PC.fe || ! PC.fe.layers || ! PC.fe.getLayerContent ) return found;
			PC.fe.layers.each( function( layer ) {
				var choices = PC.fe.getLayerContent( layer.id );
				if ( ! choices ) return;
				var header = choices.get( group_choice_id );
				if ( ! header || ! header.get( 'is_group' ) ) return;
				found.push( header );
				choices.each( function( c ) {
					if ( c.get( 'parent' ) == group_choice_id ) found.push( c );
				} );
			} );
			return found;
		},

		/**
		 * Get a Backbone model for a target (layer or choice)
		 */
		get_target_model: function( target_type, target_id ) {
			if ( ! PC.fe ) return null;

			if ( target_type === 'layer' ) {
				return PC.fe.layers ? PC.fe.layers.get( target_id ) : null;
			}

			if ( target_type === 'choice' ) {
				// Search through every layer's content for this choice
				if ( ! PC.fe.layers || ! PC.fe.getLayerContent ) return null;
				var found = null;
				PC.fe.layers.each( function( layer ) {
					if ( found ) return;
					var choices = PC.fe.getLayerContent( layer.id );
					if ( choices ) {
						var choice = choices.get( target_id );
						if ( choice ) found = choice;
					}
				} );
				return found;
			}

			return null;
		},

		/**
		 * Reset a layer to its default choice or no selection
		 */
		reset_layer: function( layer_id ) {
			if ( ! PC.fe || ! PC.fe.getLayerContent ) return;
			var layer = PC.fe.layers ? PC.fe.layers.get( layer_id ) : null;
			if ( ! layer ) return;

			var choices = PC.fe.getLayerContent( layer_id );
			if ( ! choices ) return;

			// Deselect all
			choices.each( function( c ) {
				c.set( 'active', false, { silent: true } );
			} );

			// Select default if layer setting requires it
			var default_selection = layer.get( 'default_selection' );
			if ( ! default_selection || default_selection === 'select_first' ) {
				var default_choice = choices.findWhere( { is_default: true } );
				if ( ! default_choice ) {
					default_choice = choices.findWhere( { available: true } );
				}
				if ( default_choice ) {
					default_choice.set( 'active', true );
				}
			}
		},

		// ─── API methods expected by configurator.js ──────────

		/**
		 * Check if a specific item (choice or layer) is hidden by conditional logic
		 */
		item_is_hidden: function( model ) {
			return model.get( 'cshow' ) === false;
		},

		/**
		 * Check if a model's parent layer is hidden
		 */
		parent_is_hidden: function( model ) {
			var layer_id = model.get( 'layerId' );
			if ( ! layer_id ) return false;

			var layer = PC.fe && PC.fe.layers ? PC.fe.layers.get( layer_id ) : null;
			if ( ! layer ) return false;

			return layer.get( 'cshow' ) === false;
		},
	};

	// Auto-initialize when the configurator is fully started.
	// 'PC.fe.start' is the real event fired by configurator.js after the
	// modal, viewer, layers, and content are all rendered. Other addons
	// (note, option-selector, text-overlay) all hook into the same event.
	if ( wp && wp.hooks ) {
		wp.hooks.addAction( 'PC.fe.start', 'mkl/pc/conditional-logic', function() {
			PC.conditionalLogic.init();
			// Defer to the next tick so every layer's choices view has finished
			// its initial render — otherwise the core choices view at
			// configurator.js:368 can race us and leave a group header
			// pre-activated, making our skip-if-active check bail out.
			_.defer( function() {
				PC.conditionalLogic.auto_select_defaults();
				PC.conditionalLogic.run_all();
			} );
		} );
	}

	// Backwards-compat / belt-and-braces: also try on the legacy DOM event
	// in case a theme triggers it.
	$( document ).on( 'mkl-pc-configurator-loaded', function() {
		PC.conditionalLogic.init();
		PC.conditionalLogic.run_all();
	} );

} )( jQuery, PC._us || window._ );
