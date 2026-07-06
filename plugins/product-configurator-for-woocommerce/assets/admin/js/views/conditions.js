/**
 * Conditional Logic Admin Views
 *
 * Provides the admin UI for creating and managing conditions.
 * Conditions are stored as a Backbone collection on the product model
 * and saved as _mkl_product_configurator_conditions meta.
 */
var PC = window.PC || {};
PC.views = PC.views || {};

( function( $, _ ) {

	// ─── Backbone Model & Collection ────────────────────────────────────

	PC.conditionModel = Backbone.Model.extend( {
		idAttribute: '_id',
		defaults: {
			name: '',
			enabled: true,
			reversible: false,
			always_check: false,
			comparison: 'all',
			sort_order: 0,
			rules: [],
			actions: [],
		},
	} );

	PC.conditionsCollection = Backbone.Collection.extend( {
		model: PC.conditionModel,
		comparator: 'sort_order',
		url: function() {
			return ajaxurl + '?action=' + PC.actionParameter + '&data=conditions';
		},
		parse: function( response ) {
			if ( response && response.data ) return response.data;
			if ( _.isArray( response ) ) return response;
			return [];
		},
	} );

	// Flag for import.js backward compat (checks PC.views.conditional)
	PC.views.conditional = true;

	// ─── Main Conditions Tab View ───────────────────────────────────────

	PC.views.conditions = Backbone.View.extend( {
		tagName: 'div',
		className: 'state conditions-state',
		collectionName: 'conditions',
		template: wp.template( 'mkl-pc-conditions' ),

		events: {
			'click .add-condition':     'create_condition',
			'keypress .condition-name-input': 'maybe_create_condition',
		},

		initialize: function( options ) {
			this.options = options || {};
			var product = PC.app.get_product();

			// Initialize and populate the collection
			if ( ! product.get( 'conditions' ) ) {
				product.set( 'conditions', new PC.conditionsCollection() );
			}

			this.col = product.get( 'conditions' );

			// If the collection is a plain array (from init data), wrap it
			if ( ! ( this.col instanceof Backbone.Collection ) ) {
				this.col = new PC.conditionsCollection( this.col );
				product.set( 'conditions', this.col );
			}

			// Fetch conditions if empty and product was already fetched
			if ( ! this.col.length ) {
				var init_conditions = PC.app.admin_data.get( 'conditions' );
				if ( init_conditions && init_conditions.length ) {
					this.col.reset( init_conditions );
				}
			}

			this.listenTo( this.col, 'add remove reset change', this.mark_modified );

			this.render();
		},

		render: function() {
			this.$el.html( this.template( { collectionName: this.collectionName } ) );
			this.$list = this.$( '.conditions-list' );
			this.$detail = this.$( '.conditions-detail' );
			this.$input = this.$( '.condition-name-input' );

			// Render existing conditions
			this.col.each( this.add_one, this );

			// Make list sortable
			this.$list.sortable( {
				handle: '.sort',
				axis: 'y',
				update: this.update_sort_order.bind( this ),
			} );

			return this;
		},

		add_one: function( model ) {
			var item = new PC.views.condition_list_item( { model: model, parent: this } );
			this.$list.append( item.el );
		},

		create_condition: function() {
			var name = this.$input.val().trim();
			if ( ! name ) {
				name = 'New Condition';
			}
			var new_id = PC.app.get_new_id( this.col );
			var model = new PC.conditionModel( {
				_id: new_id,
				name: name,
				enabled: true,
				reversible: false,
				always_check: false,
				comparison: 'all',
				sort_order: this.col.length,
				rules: [],
				actions: [],
			} );
			this.col.add( model );
			this.add_one( model );
			this.$input.val( '' );
			this.mark_modified();

			// Auto-select the new condition
			this.show_detail( model );
		},

		maybe_create_condition: function( e ) {
			if ( e.which === 13 ) {
				e.preventDefault();
				this.create_condition();
			}
		},

		show_detail: function( model ) {
			// Highlight in list
			this.$list.find( '.condition-item' ).removeClass( 'active' );
			this.$list.find( '[data-id="' + model.id + '"]' ).addClass( 'active' );

			// Show detail panel
			var $sidebar = this.$( '.pc-sidebar' );
			$sidebar.find( '.conditions-detail-empty' ).hide();

			// Remove old detail view if any
			if ( this.detailView ) {
				this.detailView.remove();
			}

			this.detailView = new PC.views.condition_detail( {
				model: model,
				parent: this,
			} );

			$sidebar.append( this.detailView.el );
		},

		update_sort_order: function() {
			var self = this;
			this.$list.find( '.condition-item' ).each( function( index ) {
				var id = parseInt( $( this ).data( 'id' ), 10 );
				var model = self.col.get( id );
				if ( model ) {
					model.set( 'sort_order', index, { silent: true } );
				}
			} );
			this.col.sort();
			this.mark_modified();
		},

		mark_modified: function() {
			PC.app.is_modified.conditions = true;
		},

		delete_condition: function( model ) {
			this.col.remove( model );
			if ( this.detailView ) {
				this.detailView.remove();
				this.detailView = null;
			}
			this.$( '.conditions-detail-empty' ).show();
			this.mark_modified();
		},

		duplicate_condition: function( model ) {
			var attrs = _.clone( model.attributes );
			attrs._id = PC.app.get_new_id( this.col );
			attrs.name = attrs.name + ' (copy)';
			attrs.sort_order = this.col.length;
			// Deep clone rules and actions
			attrs.rules = JSON.parse( JSON.stringify( attrs.rules || [] ) );
			attrs.actions = JSON.parse( JSON.stringify( attrs.actions || [] ) );

			var dup = new PC.conditionModel( attrs );
			this.col.add( dup );
			this.add_one( dup );
			this.mark_modified();
			this.show_detail( dup );
		},
	} );

	// ─── Condition List Item View ───────────────────────────────────────

	PC.views.condition_list_item = Backbone.View.extend( {
		tagName: 'div',
		className: 'condition-item mkl-list-item',
		template: wp.template( 'mkl-pc-condition-list-item' ),

		events: {
			'click .condition-select-btn': 'select_condition',
		},

		initialize: function( options ) {
			this.parent = options.parent;
			this.$el.attr( 'data-id', this.model.id );
			this.listenTo( this.model, 'change:name change:enabled', this.render );
			this.listenTo( this.model, 'remove', this.remove );
			this.render();
		},

		render: function() {
			this.$el.html( this.template( this.model.attributes ) );
			return this;
		},

		select_condition: function( e ) {
			e.preventDefault();
			this.parent.show_detail( this.model );
		},
	} );

	// ─── Condition Detail View ──────────────────────────────────────────

	PC.views.condition_detail = Backbone.View.extend( {
		tagName: 'div',
		className: 'condition-detail-panel',
		template: wp.template( 'mkl-pc-condition-detail' ),

		events: {
			// Toolbar
			'keyup [data-setting="name"]':       'on_field_change',
			'change [data-setting="enabled"]':    'on_checkbox_change',
			'change [data-setting="reversible"]': 'on_checkbox_change',
			'change [data-setting="always_check"]': 'on_checkbox_change',
			'change [data-setting="comparison"]': 'on_field_change',
			// Buttons
			'click .delete-condition':            'delete_condition',
			'click .duplicate-condition':          'duplicate_condition',
			// Rules
			'click .add-rule':                    'add_rule',
			// Actions
			'click .add-action-row':              'add_action_row',
		},

		initialize: function( options ) {
			this.parent = options.parent;
			this.render();
		},

		render: function() {
			this.$el.html( this.template( this.model.attributes ) );
			this.$rulesList = this.$( '.condition-rules-list' );
			this.$actionsList = this.$( '.condition-actions-list' );

			// Render existing rules
			var rules = this.model.get( 'rules' ) || [];
			_.each( rules, function( rule, index ) {
				this.render_rule_row( rule, index );
			}, this );

			// Render existing actions
			var actions = this.model.get( 'actions' ) || [];
			_.each( actions, function( action, index ) {
				this.render_action_row( action, index );
			}, this );

			return this;
		},

		on_field_change: function( e ) {
			var $input = $( e.currentTarget );
			var setting = $input.data( 'setting' );
			this.model.set( setting, $input.val() );
			this.parent.mark_modified();
		},

		on_checkbox_change: function( e ) {
			var $input = $( e.currentTarget );
			var setting = $input.data( 'setting' );
			this.model.set( setting, $input.is( ':checked' ) );
			this.parent.mark_modified();
		},

		delete_condition: function() {
			if ( confirm( 'Are you sure you want to delete this condition?' ) ) {
				this.parent.delete_condition( this.model );
			}
		},

		duplicate_condition: function() {
			this.parent.duplicate_condition( this.model );
		},

		// ── Rules ────────────────────────────────────────────────

		add_rule: function() {
			var rules = this.model.get( 'rules' ) || [];
			var new_rule = {
				trigger_type: 'layer',
				trigger_parent_id: 0,
				trigger_element: '',
				element_state: 'selected',
			};
			rules = rules.slice(); // clone
			rules.push( new_rule );
			this.model.set( 'rules', rules );

			this.render_rule_row( new_rule, rules.length - 1 );
			this.parent.mark_modified();
		},

		render_rule_row: function( rule, index ) {
			var row_view = new PC.views.condition_rule_row( {
				rule: rule,
				index: index,
				detail: this,
			} );
			this.$rulesList.append( row_view.el );
		},

		remove_rule: function( index ) {
			var rules = ( this.model.get( 'rules' ) || [] ).slice();
			rules.splice( index, 1 );
			this.model.set( 'rules', rules );
			// Re-render rules
			this.$rulesList.empty();
			_.each( rules, function( rule, i ) {
				this.render_rule_row( rule, i );
			}, this );
			this.parent.mark_modified();
		},

		update_rule: function( index, key, value ) {
			var rules = ( this.model.get( 'rules' ) || [] ).slice();
			if ( rules[ index ] ) {
				rules[ index ] = _.extend( {}, rules[ index ] );
				rules[ index ][ key ] = value;
				this.model.set( 'rules', rules );
				this.parent.mark_modified();
			}
		},

		// ── Actions ──────────────────────────────────────────────

		add_action_row: function() {
			var actions = this.model.get( 'actions' ) || [];
			var new_action = {
				action_type: 'show',
				target_type: 'layer',
				target_element_id: 0,
			};
			actions = actions.slice();
			actions.push( new_action );
			this.model.set( 'actions', actions );

			this.render_action_row( new_action, actions.length - 1 );
			this.parent.mark_modified();
		},

		render_action_row: function( action, index ) {
			var row_view = new PC.views.condition_action_row( {
				action: action,
				index: index,
				detail: this,
			} );
			this.$actionsList.append( row_view.el );
		},

		remove_action: function( index ) {
			var actions = ( this.model.get( 'actions' ) || [] ).slice();
			actions.splice( index, 1 );
			this.model.set( 'actions', actions );
			this.$actionsList.empty();
			_.each( actions, function( action, i ) {
				this.render_action_row( action, i );
			}, this );
			this.parent.mark_modified();
		},

		update_action: function( index, key, value ) {
			var actions = ( this.model.get( 'actions' ) || [] ).slice();
			if ( actions[ index ] ) {
				actions[ index ] = _.extend( {}, actions[ index ] );
				actions[ index ][ key ] = value;
				this.model.set( 'actions', actions );
				this.parent.mark_modified();
			}
		},
	} );

	// ─── Rule Row View ──────────────────────────────────────────────────

	PC.views.condition_rule_row = Backbone.View.extend( {
		tagName: 'div',
		className: 'condition-rule-row-wrap',
		template: wp.template( 'mkl-pc-condition-rule-row' ),

		events: {
			'change .rule-trigger-parent':  'on_trigger_parent_change',
			'change .rule-trigger-element': 'on_trigger_element_change',
			'change .rule-element-state':   'on_state_change',
			'click .remove-rule':           'on_remove',
		},

		initialize: function( options ) {
			this.rule = options.rule;
			this.index = options.index;
			this.detail = options.detail;
			this.render();
		},

		render: function() {
			this.$el.html( this.template( this.rule ) );
			this.populate_trigger_parent();
			this.populate_trigger_element();
			return this;
		},

		/**
		 * Populate the first dropdown with layers + conditions
		 */
		populate_trigger_parent: function() {
			var $select = this.$( '.rule-trigger-parent' );
			$select.empty();
			$select.append( '<option value="">' + '--- Select an item ---' + '</option>' );

			// Add layers
			var layers = PC.app.admin.layers;
			if ( layers ) {
				layers.each( function( layer ) {
					var label = layer.get( 'admin_label' ) || layer.get( 'name' ) || 'Layer ' + layer.id;
					var val = 'layer_' + layer.id;
					var selected = ( this.rule.trigger_type === 'layer' && this.rule.trigger_parent_id == layer.id ) ? ' selected' : '';
					$select.append( '<option value="' + val + '"' + selected + '>' + _.escape( label ) + '</option>' );
				}, this );
			}

			// Add conditions (other conditions, not self)
			var conditions = PC.app.get_product().get( 'conditions' );
			if ( conditions && conditions.length ) {
				$select.append( '<optgroup label="Conditions">' );
				conditions.each( function( cond ) {
					// Don't include ourselves
					if ( this.detail && this.detail.model && cond.id === this.detail.model.id ) return;
					var label = cond.get( 'name' ) || 'Condition ' + cond.id;
					var val = 'condition_' + cond.id;
					var selected = ( this.rule.trigger_type === 'condition' && this.rule.trigger_parent_id == cond.id ) ? ' selected' : '';
					$select.append( '<option value="' + val + '"' + selected + '>' + _.escape( label ) + '</option>' );
				}, this );
				$select.append( '</optgroup>' );
			}
		},

		/**
		 * Populate the second dropdown based on the selected trigger parent
		 */
		populate_trigger_element: function() {
			var $select = this.$( '.rule-trigger-element' );
			$select.empty();

			if ( this.rule.trigger_type === 'layer' ) {
				$select.append( '<option value="">' + '--- Select ---' + '</option>' );
				$select.append( '<option value="any"' + ( this.rule.trigger_element === 'any' ? ' selected' : '' ) + '>[ Any choice ]</option>' );
				$select.append( '<option value="none"' + ( this.rule.trigger_element === 'none' ? ' selected' : '' ) + '>[ No choice ]</option>' );

				// Get choices for this layer
				var layer_id = this.rule.trigger_parent_id;
				if ( layer_id ) {
					var choices = PC.app.get_layer_content( layer_id );
					if ( choices ) {
						choices.each( function( choice ) {
							if ( choice.get( 'is_group' ) ) return;
							var label = choice.get( 'admin_label' ) || choice.get( 'name' ) || 'Choice ' + choice.id;
							var selected = ( this.rule.trigger_element == choice.id ) ? ' selected' : '';
							$select.append( '<option value="' + choice.id + '"' + selected + '>' + _.escape( label ) + '</option>' );
						}, this );
					}
				}
			} else if ( this.rule.trigger_type === 'condition' ) {
				$select.append( '<option value="result"' + ( this.rule.trigger_element === 'result' ? ' selected' : '' ) + '>Result</option>' );
			} else {
				$select.append( '<option value="">' + '--- Select ---' + '</option>' );
			}
		},

		on_trigger_parent_change: function( e ) {
			var val = $( e.currentTarget ).val();
			if ( ! val ) return;

			var parts = val.split( '_' );
			var type = parts[0]; // 'layer' or 'condition'
			var id = parseInt( parts.slice( 1 ).join( '_' ), 10 );

			this.rule.trigger_type = type;
			this.rule.trigger_parent_id = id;
			this.rule.trigger_element = '';

			this.detail.update_rule( this.index, 'trigger_type', type );
			this.detail.update_rule( this.index, 'trigger_parent_id', id );
			this.detail.update_rule( this.index, 'trigger_element', '' );

			this.populate_trigger_element();
		},

		on_trigger_element_change: function( e ) {
			var val = $( e.currentTarget ).val();
			this.rule.trigger_element = val;
			this.detail.update_rule( this.index, 'trigger_element', val );
		},

		on_state_change: function( e ) {
			var val = $( e.currentTarget ).val();
			this.rule.element_state = val;
			this.detail.update_rule( this.index, 'element_state', val );
		},

		on_remove: function() {
			this.detail.remove_rule( this.index );
			this.remove();
		},
	} );

	// ─── Action Row View ────────────────────────────────────────────────

	PC.views.condition_action_row = Backbone.View.extend( {
		tagName: 'div',
		className: 'condition-action-row-wrap',
		template: wp.template( 'mkl-pc-condition-action-row' ),

		events: {
			'change .action-type':           'on_action_type_change',
			'change .action-target-type':    'on_target_type_change',
			'change .action-target-element': 'on_target_element_change',
			'click .remove-action-row':      'on_remove',
		},

		initialize: function( options ) {
			this.action = options.action;
			this.index = options.index;
			this.detail = options.detail;
			this.render();
		},

		render: function() {
			this.$el.html( this.template( this.action ) );
			this.populate_target_elements();
			return this;
		},

		populate_target_elements: function() {
			var $select = this.$( '.action-target-element' );
			$select.empty();
			$select.append( '<option value="">' + '--- Select ---' + '</option>' );

			var layers = PC.app.admin.layers;

			if ( this.action.target_type === 'layer' ) {
				if ( layers ) {
					layers.each( function( layer ) {
						var label = layer.get( 'admin_label' ) || layer.get( 'name' ) || 'Layer ' + layer.id;
						var selected = ( this.action.target_element_id == layer.id ) ? ' selected' : '';
						$select.append( '<option value="' + layer.id + '"' + selected + '>' + _.escape( label ) + '</option>' );
					}, this );
				}
			} else if ( this.action.target_type === 'choice' ) {
				// Show all (non-group) choices grouped by layer
				if ( layers ) {
					layers.each( function( layer ) {
						var layer_label = layer.get( 'admin_label' ) || layer.get( 'name' ) || 'Layer ' + layer.id;
						var choices = PC.app.get_layer_content( layer.id );
						if ( choices && choices.length ) {
							$select.append( '<optgroup label="' + _.escape( layer_label ) + '">' );
							choices.each( function( choice ) {
								if ( choice.get( 'is_group' ) ) return;
								var label = choice.get( 'admin_label' ) || choice.get( 'name' ) || 'Choice ' + choice.id;
								var selected = ( this.action.target_element_id == choice.id ) ? ' selected' : '';
								$select.append( '<option value="' + choice.id + '"' + selected + '>' + _.escape( label ) + '</option>' );
							}, this );
							$select.append( '</optgroup>' );
						}
					}, this );
				}
			} else if ( this.action.target_type === 'group' ) {
				// Show only group headers (is_group=true) grouped by layer
				if ( layers ) {
					layers.each( function( layer ) {
						var layer_label = layer.get( 'admin_label' ) || layer.get( 'name' ) || 'Layer ' + layer.id;
						var choices = PC.app.get_layer_content( layer.id );
						if ( ! choices || ! choices.length ) return;
						var groups = choices.filter( function( c ) { return c.get( 'is_group' ); } );
						if ( ! groups.length ) return;
						$select.append( '<optgroup label="' + _.escape( layer_label ) + '">' );
						_.each( groups, function( g ) {
							var label = g.get( 'admin_label' ) || g.get( 'name' ) || 'Group ' + g.id;
							var selected = ( this.action.target_element_id == g.id ) ? ' selected' : '';
							$select.append( '<option value="' + g.id + '"' + selected + '>' + _.escape( label ) + '</option>' );
						}, this );
						$select.append( '</optgroup>' );
					}, this );
				}
			}
		},

		on_action_type_change: function( e ) {
			var val = $( e.currentTarget ).val();
			this.action.action_type = val;
			this.detail.update_action( this.index, 'action_type', val );
		},

		on_target_type_change: function( e ) {
			var val = $( e.currentTarget ).val();
			this.action.target_type = val;
			this.action.target_element_id = 0;
			this.detail.update_action( this.index, 'target_type', val );
			this.detail.update_action( this.index, 'target_element_id', 0 );
			this.populate_target_elements();
		},

		on_target_element_change: function( e ) {
			var val = parseInt( $( e.currentTarget ).val(), 10 ) || 0;
			this.action.target_element_id = val;
			this.detail.update_action( this.index, 'target_element_id', val );
		},

		on_remove: function() {
			this.detail.remove_action( this.index );
			this.remove();
		},
	} );

} )( jQuery, PC._us || window._ );
