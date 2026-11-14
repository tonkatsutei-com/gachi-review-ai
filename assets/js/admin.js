
( function () {
	'use strict';
	var config = window.gachireaiAdmin || {};
	var dashboard = config.dashboardActions || {};
	var busy      = '';
	var PROGRESS_FIRST_DELAY = 600;
	var PROGRESS_INTERVAL    = 800;
	function sync_dependencies( scope, dimension, current ) {
		if ( ! scope.querySelectorAll ) {
			return;
		}
		var items = scope.querySelectorAll( '[data-gachireai-value]' );
		for ( var i = 0; i < items.length; i++ ) {
			var item  = items[ i ];
			var owner = item.closest ? item.closest( '[data-gachireai-depends]' ) : null;
			if ( ! owner || owner.getAttribute( 'data-gachireai-depends' ) !== dimension ) {
				continue;
			}
			var values = ( item.getAttribute( 'data-gachireai-value' ) || '' ).split( /\s+/ );
			item.hidden = values.indexOf( current ) === -1;
		}
		sync_hidden_radios( scope, dimension );
	}
	function sync_hidden_radios( scope, dimension ) {
		if ( ! scope.querySelectorAll ) {
			return;
		}
		var groups = scope.querySelectorAll( '.gachireai-choices[data-gachireai-depends="' + dimension + '"]' );
		for ( var i = 0; i < groups.length; i++ ) {
			var radios  = groups[ i ].querySelectorAll( 'input[type="radio"]' );
			var checked = null;
			var first   = null;
			for ( var j = 0; j < radios.length; j++ ) {
				var label = radios[ j ].closest ? radios[ j ].closest( 'label' ) : null;
				if ( ! label || label.hidden ) {
					continue;
				}
				if ( null === first ) {
					first = radios[ j ];
				}
				if ( radios[ j ].checked ) {
					checked = radios[ j ];
				}
			}
			if ( null === checked && null !== first ) {
				first.checked = true;
			}
		}
	}
	function sync_from_driver( driver ) {
		var dimension = driver.getAttribute ? driver.getAttribute( 'data-gachireai-driver' ) : '';
		if ( ! dimension ) {
			return;
		}
		sync_dependencies( driver.form || document, dimension, driver.value );
	}
	function sync_all_dependencies() {
		var drivers = document.querySelectorAll( '[data-gachireai-driver]' );
		var handled = {};
		for ( var i = 0; i < drivers.length; i++ ) {
			var driver    = drivers[ i ];
			var dimension = driver.getAttribute( 'data-gachireai-driver' );
			if ( ! dimension || handled[ dimension ] ) {
				continue;
			}
			handled[ dimension ] = true;
			if ( 'radio' === driver.type ) {
				var group   = document.querySelectorAll( 'input[type="radio"][data-gachireai-driver="' + dimension + '"]' );
				var current = '';
				for ( var j = 0; j < group.length; j++ ) {
					if ( group[ j ].checked ) {
						current = group[ j ].value;
						break;
					}
				}
				if ( '' === current ) {
					for ( var k = 0; k < group.length; k++ ) {
						if ( ! group[ k ].disabled ) {
							current = group[ k ].value;
							break;
						}
					}
				}
				sync_dependencies( document, dimension, current );
				continue;
			}
			sync_from_driver( driver );
		}
	}
	document.addEventListener( 'change', function ( event ) {
		var input = event.target;
		if ( ! input ) {
			return;
		}
		if ( input.hasAttribute && input.hasAttribute( 'data-gachireai-driver' ) ) {
			sync_from_driver( input );
		}
		if ( 'radio' !== input.type && 'select-one' !== input.type ) {
			return;
		}
		if ( ! input.closest || ! input.closest( '.gachireai-candidate' ) ) {
			return;
		}
		var target = input.getAttribute( 'data-target' );
		if ( ! target ) {
			return;
		}
		var field = document.querySelector( '[name="' + target + '"]' );
		if ( ! field ) {
			return;
		}
		field.value = input.value;
	} );
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', sync_all_dependencies );
	} else {
		sync_all_dependencies();
	}
	function sync_master_disable( master ) {
		var group = master.getAttribute ? master.getAttribute( 'data-gachireai-master' ) : '';
		if ( ! group ) {
			return;
		}
		var is_on = 'checkbox' === master.type ? !! master.checked : '' !== master.value;
		var rows  = ( master.form || document ).querySelectorAll( '[data-gachireai-disabled-by="' + group + '"]' );
		for ( var i = 0; i < rows.length; i++ ) {
			var row    = rows[ i ];
			var locked = '1' === row.getAttribute( 'data-gachireai-locked' );
			var off    = ! is_on || locked;
			var items  = row.querySelectorAll( 'input, select, textarea, button' );
			for ( var j = 0; j < items.length; j++ ) {
				items[ j ].disabled = off;
			}
			if ( off ) {
				row.classList.add( 'gachireai-field--disabled' );
			} else {
				row.classList.remove( 'gachireai-field--disabled' );
			}
		}
	}
	function sync_all_masters() {
		var masters = document.querySelectorAll( '[data-gachireai-master]' );
		for ( var i = 0; i < masters.length; i++ ) {
			sync_master_disable( masters[ i ] );
		}
	}
	document.addEventListener( 'change', function ( event ) {
		var input = event.target;
		if ( input && input.hasAttribute && input.hasAttribute( 'data-gachireai-master' ) ) {
			sync_master_disable( input );
		}
	} );
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', sync_all_masters );
	} else {
		sync_all_masters();
	}
	function sync_common_switch( checkbox ) {
		var group = checkbox.getAttribute ? checkbox.getAttribute( 'data-gachireai-common-switch' ) : '';
		if ( ! group ) {
			return;
		}
		var use_common = !! checkbox.checked;
		var rows       = document.querySelectorAll( '[data-gachireai-common-target="' + group + '"]' );
		for ( var i = 0; i < rows.length; i++ ) {
			var row    = rows[ i ];
			var locked = '1' === row.getAttribute( 'data-gachireai-locked' );
			var off    = use_common || locked;
			var items  = row.querySelectorAll( 'input, select, textarea, button' );
			for ( var j = 0; j < items.length; j++ ) {
				items[ j ].disabled = off;
			}
			if ( off ) {
				row.classList.add( 'gachireai-field--disabled' );
			} else {
				row.classList.remove( 'gachireai-field--disabled' );
			}
		}
		var copy_buttons = document.querySelectorAll( '[data-gachireai-copy-switch="' + group + '"]' );
		for ( var k = 0; k < copy_buttons.length; k++ ) {
			copy_buttons[ k ].disabled = use_common;
		}
	}
	function sync_all_common_switches() {
		var switches = document.querySelectorAll( '[data-gachireai-common-switch]' );
		for ( var i = 0; i < switches.length; i++ ) {
			sync_common_switch( switches[ i ] );
		}
	}
	document.addEventListener( 'change', function ( event ) {
		var input = event.target;
		if ( input && input.hasAttribute && input.hasAttribute( 'data-gachireai-common-switch' ) ) {
			sync_common_switch( input );
		}
	} );
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', sync_all_common_switches );
	} else {
		sync_all_common_switches();
	}
	document.addEventListener( 'change', function ( event ) {
		var input = event.target;
		if ( ! input || 'checkbox' !== input.type ) {
			return;
		}
		var group = input.getAttribute( 'data-gachireai-exclusive' );
		if ( ! group ) {
			return;
		}
		if ( ! input.checked ) {
			input.checked = true;
			return;
		}
		var inputs = document.querySelectorAll( 'input[type="checkbox"][data-gachireai-exclusive]' );
		for ( var i = 0; i < inputs.length; i++ ) {
			if ( inputs[ i ] !== input && inputs[ i ].getAttribute( 'data-gachireai-exclusive' ) === group ) {
				inputs[ i ].checked = false;
			}
		}
	} );
	document.addEventListener(
		'toggle',
		function ( event ) {
			var details = event.target;
			if ( ! details || 'DETAILS' !== details.tagName || ! details.open ) {
				return;
			}
			if ( ! details.classList || ! details.classList.contains( 'gachireai-cardgroup' ) ) {
				return;
			}
			var groups = document.querySelectorAll( 'details.gachireai-cardgroup' );
			for ( var i = 0; i < groups.length; i++ ) {
				if ( groups[ i ] !== details ) {
					groups[ i ].open = false;
				}
			}
		},
		true
	);
	function color_hex( value ) {
		var match = /^#([0-9a-fA-F]{3})$/.exec( value );
		if ( match ) {
			var c = match[ 1 ];
			return ( '#' + c[ 0 ] + c[ 0 ] + c[ 1 ] + c[ 1 ] + c[ 2 ] + c[ 2 ] ).toLowerCase();
		}
		return /^#[0-9a-fA-F]{6}$/.test( value ) ? value.toLowerCase() : '';
	}
	function find_color_option( field, value ) {
		var options = field.querySelectorAll( '.gachireai-color__option' );
		for ( var i = 0; i < options.length; i++ ) {
			if ( ( options[ i ].getAttribute( 'data-gachireai-color-value' ) || '' ) === value ) {
				return options[ i ];
			}
		}
		return null;
	}
	function sync_color_picker( field, value ) {
		var picker = field.querySelector( '.gachireai-color__picker' );
		if ( ! picker ) {
			return;
		}
		var hex = color_hex( value );
		if ( ! hex ) {
			var option = find_color_option( field, value );
			if ( option ) {
				hex = option.getAttribute( 'data-gachireai-color-hex' ) || '';
			}
		}
		if ( hex ) {
			picker.value = hex;
		}
	}
	function close_color_lists( except ) {
		var lists = document.querySelectorAll( '.gachireai-color__list' );
		for ( var i = 0; i < lists.length; i++ ) {
			if ( lists[ i ] === except ) {
				continue;
			}
			lists[ i ].hidden = true;
			var field  = lists[ i ].closest ? lists[ i ].closest( '.gachireai-color' ) : null;
			var toggle = field ? field.querySelector( '.gachireai-color__toggle' ) : null;
			if ( toggle ) {
				toggle.setAttribute( 'aria-expanded', 'false' );
			}
		}
	}
	document.addEventListener( 'click', function ( event ) {
		var toggle = event.target && event.target.closest
			? event.target.closest( '.gachireai-color__toggle' )
			: null;
		if ( ! toggle ) {
			return;
		}
		var field = toggle.closest( '.gachireai-color' );
		var list  = field ? field.querySelector( '.gachireai-color__list' ) : null;
		if ( ! list ) {
			return;
		}
		event.preventDefault();
		var open = list.hidden;
		close_color_lists( list );
		list.hidden = ! open;
		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
	} );
	document.addEventListener( 'click', function ( event ) {
		var option = event.target && event.target.closest
			? event.target.closest( '.gachireai-color__option' )
			: null;
		if ( ! option ) {
			return;
		}
		var field = option.closest( '.gachireai-color' );
		var text  = field ? field.querySelector( '.gachireai-color__text' ) : null;
		if ( ! field || ! text ) {
			return;
		}
		event.preventDefault();
		text.value = option.getAttribute( 'data-gachireai-color-value' ) || '';
		sync_color_picker( field, text.value );
		close_color_lists( null );
		sync_card_colors( field.closest ? field.closest( 'form[data-gachireai-card-form]' ) : null );
	} );
	document.addEventListener( 'click', function ( event ) {
		var inside = event.target && event.target.closest
			? ( event.target.closest( '.gachireai-color__toggle' ) || event.target.closest( '.gachireai-color__list' ) )
			: null;
		if ( ! inside ) {
			close_color_lists( null );
		}
	} );
	document.addEventListener( 'input', function ( event ) {
		var input = event.target;
		if ( ! input || ! input.classList || ! input.closest ) {
			return;
		}
		var field = input.closest( '.gachireai-color' );
		if ( ! field ) {
			return;
		}
		if ( input.classList.contains( 'gachireai-color__picker' ) ) {
			var text = field.querySelector( '.gachireai-color__text' );
			if ( text ) {
				text.value = input.value;
			}
		} else if ( input.classList.contains( 'gachireai-color__text' ) ) {
			sync_color_picker( field, input.value );
		}
		sync_card_colors( input.form || ( input.closest ? input.closest( 'form[data-gachireai-card-form]' ) : null ) );
	} );
	function report_error( message ) {
		var fallback = config.i18n && config.i18n.requestFailed ? config.i18n.requestFailed : '';
		window.alert( message || fallback );
	}
	function replace_ec_form( html ) {
		if ( ! html ) {
			return;
		}
		var current = document.getElementById( 'gachireai-form-ec' );
		if ( current ) {
			current.outerHTML = html;
		}
	}
	function sync_exclusive( exclusive ) {
		if ( ! exclusive || ! exclusive.group ) {
			return;
		}
		var inputs = document.querySelectorAll( 'input[type="checkbox"][data-gachireai-exclusive]' );
		for ( var i = 0; i < inputs.length; i++ ) {
			if ( inputs[ i ].getAttribute( 'data-gachireai-exclusive' ) !== exclusive.group ) {
				continue;
			}
			inputs[ i ].checked = ( inputs[ i ].value === exclusive.value );
		}
	}
	function progress_label( text ) {
		var raw = text ? String( text ).replace( /\s+$/, '' ) : '';
		if ( '' === raw ) {
			return '';
		}
		try {
			var parsed = JSON.parse( raw );
			if ( parsed && typeof parsed.label === 'string' && '' !== parsed.label ) {
				return parsed.label;
			}
		} catch ( e ) {
		}
		return raw;
	}
	function start_progress( element ) {
		var base  = config.progressBaseUrl || '';
		var token = config.progressToken || '';
		if ( ! element || ! base || ! token ) {
			return function () {};
		}
		var timer  = null;
		var active = true;
		function stop() {
			active = false;
			if ( timer ) {
				window.clearTimeout( timer );
				timer = null;
			}
		}
		function poll() {
			if ( ! active ) {
				return;
			}
			var url = base + '/' + encodeURIComponent( token ) + '.txt?_=' + Date.now();
			window.fetch( url, { credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.ok ? response.text() : '';
				} )
				.then( function ( text ) {
					if ( ! active ) {
						return;
					}
					var progress = progress_label( text );
					if ( progress ) {
						element.textContent = progress;
					}
					timer = window.setTimeout( poll, PROGRESS_INTERVAL );
				} )
				.catch( function () {
					if ( active ) {
						timer = window.setTimeout( poll, PROGRESS_INTERVAL );
					}
				} );
		}
		timer = window.setTimeout( poll, PROGRESS_FIRST_DELAY );
		return stop;
	}
	function replace_logs( html ) {
		if ( ! html ) {
			return;
		}
		var logs = document.getElementById( 'gachireai-logs' );
		if ( logs ) {
			logs.outerHTML = html;
		}
	}
	function progress_slot( button, action ) {
		if ( action !== dashboard.reviews || ! button || ! button.closest ) {
			return null;
		}
		var block = button.closest( '.gachireai-block' );
		return block ? block.querySelector( '.gachireai-block__messages' ) : null;
	}
	function lock_actions( form ) {
		var locked  = [];
		var buttons = form && form.querySelectorAll ? form.querySelectorAll( 'button[data-gachireai-ajax]' ) : [];
		for ( var i = 0; i < buttons.length; i++ ) {
			if ( buttons[ i ].disabled ) {
				continue;
			}
			buttons[ i ].disabled = true;
			locked.push( buttons[ i ] );
		}
		return locked;
	}
	function unlock_actions( buttons ) {
		for ( var i = 0; i < buttons.length; i++ ) {
			buttons[ i ].disabled = false;
		}
	}
	function submit_form( form, action, button ) {
		var is_dashboard = ( action === dashboard.select || action === dashboard.reviews || action === dashboard.generate || action === dashboard.eyecatch || action === dashboard.bulk );
		if ( is_dashboard ) {
			busy = action;
		}
		var data = new FormData( form );
		data.append( 'action', action );
		if ( button && button.name ) {
			data.append( button.name, button.value );
		}
		if ( form.querySelector( '[name="site_images[top_eyecatch]"]' ) ) {
			var site_name_input = document.querySelector( '[name="site_elements[site_name]"]' );
			var site_desc_input = document.querySelector( '[name="site_elements[description]"]' );
			if ( site_name_input ) {
				data.append( 'site_name', site_name_input.value );
			}
			if ( site_desc_input ) {
				data.append( 'site_description', site_desc_input.value );
			}
		}
		if ( button ) {
			button.disabled = true;
			button.classList.add( 'gachireai-is-busy' );
		}
		var locked = is_dashboard ? lock_actions( form ) : [];
		var testing = null;
		if ( button && button.hasAttribute( 'data-gachireai-testing' ) && config.i18n && config.i18n.testing ) {
			testing = document.createElement( 'span' );
			testing.className = 'gachireai-testing';
			testing.textContent = config.i18n.testing;
			button.insertAdjacentElement( 'afterend', testing );
		}
		var progress_label = config.progressLabels && config.progressLabels[ action ]
			? config.progressLabels[ action ]
			: '';
		var progress      = null;
		var stop_progress = function () {};
		if ( button && progress_label ) {
			progress = document.createElement( 'span' );
			progress.className = 'gachireai-progress';
			progress.textContent = progress_label;
			var slot = progress_slot( button, action );
			if ( slot ) {
				slot.appendChild( progress );
			} else {
				button.insertAdjacentElement( 'afterend', progress );
			}
			stop_progress = start_progress( progress );
		}
		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success || ! payload.data || ! payload.data.html ) {
					throw new Error( payload && payload.data && payload.data.message ? payload.data.message : '' );
				}
				stop_progress();
				if ( progress ) {
					progress.remove();
				}
				form.outerHTML = payload.data.html;
				replace_ec_form( payload.data.ec_form_html );
				sync_all_dependencies();
				sync_all_font_previews();
				sync_card_form();
				replace_logs( payload.data.logs_html );
				sync_exclusive( payload.data.exclusive );
				if ( is_dashboard ) {
					busy = '';
				}
			} )
			.catch( function ( error ) {
				stop_progress();
				if ( progress ) {
					progress.remove();
				}
				if ( button ) {
					button.disabled = false;
					button.classList.remove( 'gachireai-is-busy' );
				}
				if ( testing ) {
					testing.remove();
				}
				report_error( error && error.message ? error.message : '' );
				if ( is_dashboard ) {
					busy = '';
					unlock_actions( locked );
				}
			} );
	}
	var category_form = null;
	function category_modal( element ) {
		var target = element.getAttribute( 'data-gachireai-category-target' );
		if ( target ) {
			return document.querySelector( target );
		}
		return element.closest ? element.closest( '.gachireai-modal' ) : null;
	}
	function category_request( button ) {
		var modal = category_modal( button );
		if ( ! modal ) {
			return;
		}
		if ( button.hasAttribute( 'data-gachireai-category-close' ) ) {
			modal.hidden = true;
			return;
		}
		var form = button.form || ( button.closest ? button.closest( 'form' ) : null ) || category_form;
		var body = modal.querySelector( '.gachireai-modal__body' );
		if ( ! form || ! body || ! config.ajaxUrl ) {
			return;
		}
		category_form = form;
		var is_pick         = button.hasAttribute( 'data-gachireai-category-pick' );
		var is_pick_current = button.hasAttribute( 'data-gachireai-category-pick-current' );
		var action          = button.getAttribute( 'data-gachireai-category-pick-current' )
			|| button.getAttribute( 'data-gachireai-category-pick' )
			|| button.getAttribute( 'data-gachireai-category-open' );
		if ( ! action ) {
			return;
		}
		var data = new FormData( form );
		data.append( 'action', action );
		data.append( 'gachireai_category_parent', button.getAttribute( 'data-gachireai-category-parent' ) || '' );
		if ( is_pick ) {
			var checked = body.querySelector( 'input[name="gachireai_category_node"]:checked' );
			if ( ! checked ) {
				report_error( config.i18n && config.i18n.categoryPickRequired ? config.i18n.categoryPickRequired : '' );
				return;
			}
			data.append( 'gachireai_category_id', checked.value );
		} else if ( is_pick_current ) {
			data.append( 'gachireai_category_id', button.getAttribute( 'data-gachireai-category-current-id' ) || '' );
		}
		button.disabled = true;
		button.classList.add( 'gachireai-is-busy' );
		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success || ! payload.data || ! payload.data.html ) {
					throw new Error( payload && payload.data && payload.data.message ? payload.data.message : '' );
				}
				if ( is_pick || is_pick_current ) {
					form.outerHTML = payload.data.html;
					modal.hidden = true;
					sync_all_dependencies();
					return;
				}
				body.outerHTML = payload.data.html;
				modal.hidden = false;
			} )
			.catch( function ( error ) {
				report_error( error && error.message ? error.message : '' );
			} )
			.then( function () {
				button.disabled = false;
				button.classList.remove( 'gachireai-is-busy' );
			} );
	}
	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest
			? event.target.closest( '[data-gachireai-category-open], [data-gachireai-category-pick], [data-gachireai-category-pick-current], [data-gachireai-category-close]' )
			: null;
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		category_request( button );
	} );
	document.addEventListener( 'click', function ( event ) {
		var clear = event.target && event.target.closest
			? event.target.closest( '[data-gachireai-category-clear]' )
			: null;
		if ( ! clear ) {
			return;
		}
		event.preventDefault();
		var field = document.getElementById( clear.getAttribute( 'data-gachireai-category-clear' ) );
		if ( field ) {
			field.value = '';
			field.focus();
		}
	} );
	document.addEventListener( 'click', function ( event ) {
		if ( event.target && event.target.classList && event.target.classList.contains( 'gachireai-modal' ) ) {
			event.target.hidden = true;
		}
	} );
	function clear_step_result( button ) {
		var block = button.closest ? button.closest( '.gachireai-block' ) : null;
		if ( ! block ) {
			return;
		}
		var messages = block.querySelector( '.gachireai-block__messages' );
		if ( ! messages ) {
			return;
		}
		while ( messages.firstChild ) {
			messages.removeChild( messages.firstChild );
		}
	}
	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest
			? event.target.closest( 'button[data-gachireai-ajax]' )
			: null;
		if ( ! button || button.disabled ) {
			return;
		}
		var action = button.getAttribute( 'data-gachireai-ajax' );
		var form   = button.form || ( button.closest ? button.closest( 'form' ) : null );
		if ( ! action || ! form || ! config.ajaxUrl ) {
			return;
		}
		event.preventDefault();
		var is_run = ( action === dashboard.select || action === dashboard.reviews || action === dashboard.generate || action === dashboard.eyecatch || action === dashboard.bulk );
		if ( busy ) {
			return;
		}
		if ( is_run ) {
			clear_step_result( button );
		}
		submit_form( form, action, button );
	} );
	document.addEventListener( 'change', function ( event ) {
		var input = event.target;
		if ( ! input || 'image[style_family]' !== input.name ) {
			return;
		}
		if ( event.isTrusted === false ) {
			return;
		}
		if ( 'custom' === input.value || busy ) {
			return;
		}
		var form = input.form || ( input.closest ? input.closest( 'form' ) : null );
		if ( ! form ) {
			return;
		}
		var save   = form.querySelector( '[data-gachireai-save-images]' );
		var action = save ? save.getAttribute( 'data-gachireai-ajax' ) : '';
		if ( ! save || ! action || ! config.ajaxUrl ) {
			return;
		}
		submit_form( form, action, save );
	} );
	function render_image_preview( box, url ) {
		var holder = box.querySelector( '[data-gachireai-image-preview-box]' );
		if ( ! holder ) {
			return;
		}
		while ( holder.firstChild ) {
			holder.removeChild( holder.firstChild );
		}
		if ( url ) {
			var img = document.createElement( 'img' );
			img.setAttribute( 'src', url );
			img.setAttribute( 'alt', '' );
			img.setAttribute( 'data-gachireai-image-preview', '' );
			holder.appendChild( img );
		}
	}
	function set_image_field( box, id, url ) {
		var input = box.querySelector( '[data-gachireai-image-input]' );
		if ( input ) {
			input.value = id ? String( id ) : '';
		}
		render_image_preview( box, url );
	}
	function is_top_eyecatch_box( box ) {
		var input = box.querySelector( '[data-gachireai-image-input]' );
		return !!( input && 'site_images[top_eyecatch]' === input.getAttribute( 'name' ) );
	}
	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.closest ) {
			return;
		}
		var picker = target.closest( '[data-gachireai-image-picker]' );
		if ( picker ) {
			event.preventDefault();
			var box = picker.closest( '[data-gachireai-image]' );
			if ( ! box || ! window.wp || ! window.wp.media ) {
				return;
			}
			var frame = window.wp.media( {
				title: config.i18n && config.i18n.imagePickTitle ? config.i18n.imagePickTitle : '',
				button: { text: config.i18n && config.i18n.imageUse ? config.i18n.imageUse : '' },
				library: { type: 'image' },
				multiple: false,
			} );
			frame.on( 'select', function () {
				var selection  = frame.state().get( 'selection' );
				var attachment = selection && selection.first ? selection.first().toJSON() : null;
				if ( ! attachment ) {
					return;
				}
				var sizes = attachment.sizes || {};
				var url   = sizes.medium && sizes.medium.url ? sizes.medium.url : attachment.url;
				set_image_field( box, attachment.id, url );
				if ( is_top_eyecatch_box( box ) ) {
					sync_all_font_previews();
				}
			} );
			frame.open();
			return;
		}
		var clear = target.closest( '[data-gachireai-image-clear]' );
		if ( clear ) {
			event.preventDefault();
			var clear_box = clear.closest( '[data-gachireai-image]' );
			if ( clear_box ) {
				set_image_field( clear_box, 0, '' );
				if ( is_top_eyecatch_box( clear_box ) ) {
					sync_all_font_previews();
				}
			}
		}
	} );
	var font_preview_timer = null;
	function request_font_preview( row, font, weight ) {
		var form  = row.closest ? row.closest( 'form' ) : null;
		var box   = form ? form.querySelector( '[data-gachireai-font-preview-box]' ) : null;
		var image = box ? box.querySelector( '[data-gachireai-font-preview-image]' ) : null;
		if ( ! image || ! form || ! config.ajaxUrl || ! config.fontPreviewAction ) {
			return;
		}
		var nonce = form.querySelector( 'input[name="_wpnonce"]' );
		var data  = new FormData();
		data.append( 'action', config.fontPreviewAction );
		data.append( '_wpnonce', nonce ? nonce.value : '' );
		data.append( 'font', font );
		data.append( 'font_weight', weight );
		var top_eyecatch = form.querySelector( 'input[name="site_images[top_eyecatch]"]' );
		data.append( 'top_eyecatch', top_eyecatch ? top_eyecatch.value : '' );
		var site_name_input = document.querySelector( '[name="site_elements[site_name]"]' );
		var site_desc_input = document.querySelector( '[name="site_elements[description]"]' );
		data.append( 'site_name', site_name_input ? site_name_input.value : '' );
		data.append( 'site_description', site_desc_input ? site_desc_input.value : '' );
		if ( font_preview_timer ) {
			window.clearTimeout( font_preview_timer );
		}
		font_preview_timer = window.setTimeout( function () {
			if ( box ) {
				box.classList.add( 'gachireai-font__preview--loading' );
			}
			window.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: data,
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					if ( ! payload || ! payload.success || ! payload.data || ! payload.data.image ) {
						throw new Error( payload && payload.data && payload.data.message ? payload.data.message : '' );
					}
					image.setAttribute( 'src', payload.data.image );
					if ( box ) {
						box.classList.remove( 'gachireai-font__preview--loading' );
					}
				} )
				.catch( function () {
					if ( box ) {
						box.classList.remove( 'gachireai-font__preview--loading' );
					}
				} );
		}, 250 );
	}
	function sync_font_preview( row ) {
		var select = row.querySelector( '[data-gachireai-font]' );
		if ( ! select || select.selectedIndex < 0 ) {
			return;
		}
		var option  = select.options[ select.selectedIndex ];
		var weights = ( option.getAttribute( 'data-gachireai-font-weights' ) || '' ).split( /\s+/ );
		var weight  = row.querySelector( '[data-gachireai-font-weight]' );
		var current = weight ? weight.value : '';
		var valid   = weights.indexOf( current ) !== -1;
		if ( weight ) {
			for ( var i = 0; i < weight.options.length; i++ ) {
				var supported = weights.indexOf( weight.options[ i ].value ) !== -1;
				weight.options[ i ].hidden   = ! supported;
				weight.options[ i ].disabled = ! supported;
			}
			if ( ! valid ) {
				weight.value = option.getAttribute( 'data-gachireai-font-default-weight' ) || ( weights[ 0 ] || '' );
			}
		}
		request_font_preview( row, select.value, weight ? weight.value : '' );
	}
	function sync_all_font_previews() {
		var rows = document.querySelectorAll( '[data-gachireai-font-row]' );
		for ( var i = 0; i < rows.length; i++ ) {
			sync_font_preview( rows[ i ] );
		}
	}
	document.addEventListener( 'change', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.closest || ! target.matches ) {
			return;
		}
		if ( target.matches( '[data-gachireai-font], [data-gachireai-font-weight]' ) ) {
			var row = target.closest( '[data-gachireai-font-row]' );
			if ( row ) {
				sync_font_preview( row );
			}
		}
	} );
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', sync_all_font_previews );
	} else {
		sync_all_font_previews();
	}
	function sync_card_preview( form ) {
		var map = [
			[ 'show_price', 'gachireai-preview-hide-price' ],
			[ 'show_name', 'gachireai-preview-hide-name' ],
			[ 'show_disclosure', 'gachireai-preview-hide-disclosure' ],
			[ 'show_rakuten', 'gachireai-preview-hide-rakuten' ],
			[ 'show_yahoo', 'gachireai-preview-hide-yahoo' ],
			[ 'show_amazon', 'gachireai-preview-hide-amazon' ]
		];
		for ( var i = 0; i < map.length; i++ ) {
			var input = form.querySelector( 'input[name="card[' + map[ i ][ 0 ] + ']"]' );
			var hide  = !! input && ! input.checked;
			if ( hide ) {
				form.classList.add( map[ i ][ 1 ] );
			} else {
				form.classList.remove( map[ i ][ 1 ] );
			}
		}
	}
	function is_card_color( value ) {
		return /^var\(--[A-Za-z0-9_-]+\)$/.test( value )
			|| /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test( value );
	}
	function card_color_rgb( value ) {
		var probe = document.createElement( 'span' );
		probe.style.display = 'none';
		probe.style.color = value;
		document.body.appendChild( probe );
		var computed = window.getComputedStyle( probe ).color;
		document.body.removeChild( probe );
		var match = /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/.exec( computed || '' );
		return match ? [ parseInt( match[1], 10 ), parseInt( match[2], 10 ), parseInt( match[3], 10 ) ] : null;
	}
	function card_contrast_text( value ) {
		var rgb = card_color_rgb( value );
		if ( ! rgb ) {
			return '#ffffff';
		}
		var yiq = ( rgb[0] * 299 + rgb[1] * 587 + rgb[2] * 114 ) / 1000;
		return yiq >= 128 ? '#000000' : '#ffffff';
	}
	function sync_card_colors( form ) {
		if ( ! form || ! form.querySelectorAll ) {
			return;
		}
		var map = [
			[ 'name_color', '--gachireai-name' ],
			[ 'frame_color', '--gachireai-border' ],
			[ 'button_color', '--gachireai-button' ],
			[ 'decoration_color', '--gachireai-accent' ]
		];
		var cards        = form.querySelectorAll( '.gachireai-affiliate' );
		var button_value = '';
		for ( var i = 0; i < map.length; i++ ) {
			var field = form.querySelector( 'input[name="card[' + map[ i ][ 0 ] + ']"]' );
			var value = field && 'value' in field ? ( field.value || '' ).trim() : '';
			var use   = is_card_color( value );
			if ( 'button_color' === map[ i ][ 0 ] && use ) {
				button_value = value;
			}
			for ( var j = 0; j < cards.length; j++ ) {
				if ( use ) {
					cards[ j ].style.setProperty( map[ i ][ 1 ], value );
				} else {
					cards[ j ].style.removeProperty( map[ i ][ 1 ] );
				}
			}
		}
		var button_text = '' !== button_value ? card_contrast_text( button_value ) : '';
		for ( var k = 0; k < cards.length; k++ ) {
			if ( '' !== button_text ) {
				cards[ k ].style.setProperty( '--gachireai-button-text', button_text );
			} else {
				cards[ k ].style.removeProperty( '--gachireai-button-text' );
			}
		}
	}
	function sync_card_form( form ) {
		form = form || document.querySelector( 'form[data-gachireai-card-form]' );
		if ( ! form ) {
			return;
		}
		sync_card_preview( form );
		sync_card_colors( form );
	}
	document.addEventListener( 'change', function ( event ) {
		var input = event.target;
		if ( ! input || ! input.name ) {
			return;
		}
		var form = input.form || ( input.closest ? input.closest( 'form[data-gachireai-card-form]' ) : null );
		if ( ! form || ! form.hasAttribute || ! form.hasAttribute( 'data-gachireai-card-form' ) ) {
			return;
		}
		if ( 'checkbox' === input.type && 0 === input.name.indexOf( 'card[show_' ) ) {
			sync_card_preview( form );
			return;
		}
		if ( /^card\[[a-z_]*_color\]$/.test( input.name ) ) {
			sync_card_colors( form );
		}
	} );
	sync_card_form();
	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest
			? event.target.closest( '[data-gachireai-site-toggle]' )
			: null;
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		var form = button.closest ? button.closest( 'form' ) : null;
		if ( ! form || ! form.querySelectorAll ) {
			return;
		}
		var check = 'check' === button.getAttribute( 'data-gachireai-site-toggle' );
		var boxes = form.querySelectorAll( 'input[name="gachireai_backup_sites[]"]' );
		for ( var i = 0; i < boxes.length; i++ ) {
			boxes[ i ].checked = check;
		}
		var common = form.querySelector( 'input[name="gachireai_backup_common"]' );
		if ( common ) {
			common.checked = check;
		}
	} );
	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.closest ) {
			return;
		}
		var open = target.closest( '[data-gachireai-reset-open]' );
		if ( open ) {
			event.preventDefault();
			var form   = open.closest( 'form' );
			var dialog = form ? form.querySelector( '[data-gachireai-reset-dialog]' ) : null;
			if ( dialog ) {
				dialog.hidden = false;
			}
			return;
		}
		var cancel = target.closest( '[data-gachireai-reset-cancel]' );
		if ( cancel ) {
			event.preventDefault();
			var panel = cancel.closest( '[data-gachireai-reset-dialog]' );
			if ( panel ) {
				panel.hidden = true;
			}
		}
	} );
	document.addEventListener( 'change', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.matches || ! target.matches( '[data-gachireai-reset-agree]' ) ) {
			return;
		}
		var dialog = target.closest ? target.closest( '[data-gachireai-reset-dialog]' ) : null;
		var submit = dialog ? dialog.querySelector( '[data-gachireai-reset-submit]' ) : null;
		if ( submit ) {
			submit.disabled = ! target.checked;
		}
	} );
	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.closest ) {
			return;
		}
		var cancel = target.closest( '[data-gachireai-scope-cancel]' );
		if ( cancel ) {
			event.preventDefault();
			var dialog = cancel.closest( '[data-gachireai-scope-dialog]' );
			if ( dialog ) {
				dialog.hidden = true;
			}
		}
	} );
	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.closest ) {
			return;
		}
		var open = target.closest( '[data-gachireai-network-open]' );
		if ( open ) {
			event.preventDefault();
			var form   = open.closest( 'form' );
			var dialog = form ? form.querySelector( '[data-gachireai-network-dialog]' ) : null;
			if ( dialog ) {
				dialog.hidden = false;
			}
			return;
		}
		var cancel = target.closest( '[data-gachireai-network-cancel]' );
		if ( cancel ) {
			event.preventDefault();
			var panel = cancel.closest( '[data-gachireai-network-dialog]' );
			if ( panel ) {
				panel.hidden = true;
			}
		}
	} );
	document.addEventListener( 'change', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.matches || ! target.matches( '[data-gachireai-network-agree]' ) ) {
			return;
		}
		var dialog = target.closest ? target.closest( '[data-gachireai-network-dialog]' ) : null;
		var submit = dialog ? dialog.querySelector( '[data-gachireai-network-submit]' ) : null;
		if ( submit ) {
			submit.disabled = ! target.checked;
		}
	} );
	function site_overlay_parts( form ) {
		var overlay = form ? form.querySelector( '[data-gachireai-site-overlay]' ) : null;
		if ( ! overlay ) {
			return null;
		}
		return {
			overlay: overlay,
			fraction: overlay.querySelector( '[data-gachireai-site-overlay-fraction]' ),
			num: overlay.querySelector( '[data-gachireai-site-overlay-num]' ),
			total: overlay.querySelector( '[data-gachireai-site-overlay-total]' ),
			label: overlay.querySelector( '[data-gachireai-site-overlay-label]' ),
			retry: overlay.querySelector( '[data-gachireai-site-overlay-retry]' ),
			dots: overlay.querySelectorAll( '[data-gachireai-site-overlay-dot]' )
		};
	}
	function site_overlay_total( parts ) {
		var total = parts && parts.overlay
			? parseInt( parts.overlay.getAttribute( 'data-gachireai-site-overlay-total' ), 10 )
			: 0;
		return total > 0 ? total : 8;
	}
	function site_parse_progress( text ) {
		var raw = text ? String( text ).replace( /\s+$/, '' ) : '';
		if ( '' === raw ) {
			return null;
		}
		try {
			var parsed = JSON.parse( raw );
			if ( parsed && typeof parsed === 'object' ) {
				return {
					label: typeof parsed.label === 'string' ? parsed.label : '',
					phase: parseInt( parsed.phase, 10 ) || 0,
					total: parseInt( parsed.total, 10 ) || 0,
					retry: parsed.retry && typeof parsed.retry === 'object' ? parsed.retry : null
				};
			}
		} catch ( e ) {
		}
		return { label: raw, phase: 0, total: 0, retry: null };
	}
	function site_update_overlay( parts, state, fallback_total, retry_label ) {
		if ( ! parts || ! state ) {
			return;
		}
		var denominator   = state.total > 0 ? state.total : fallback_total;
		var phase         = state.phase > 0 ? state.phase : 1;
		var is_retry      = !! state.retry;
		var display_phase = is_retry && phase > 1 ? phase - 1 : phase;
		if ( parts.num ) {
			var next = String( display_phase );
			if ( parts.num.textContent !== next ) {
				parts.num.textContent = next;
				if ( parts.fraction ) {
					parts.fraction.classList.remove( 'is-bump' );
					void parts.fraction.offsetWidth;
					parts.fraction.classList.add( 'is-bump' );
				}
			}
		}
		if ( parts.total ) {
			parts.total.textContent = String( denominator );
		}
		if ( parts.overlay ) {
			parts.overlay.classList.toggle( 'is-retrying', is_retry );
		}
		for ( var i = 0; i < parts.dots.length; i++ ) {
			var index = i + 1;
			var dot   = parts.dots[ i ];
			dot.classList.toggle( 'is-done', index < display_phase );
			dot.classList.toggle( 'is-current', index === display_phase );
		}
		if ( parts.label && state.label ) {
			parts.label.textContent = state.label;
		}
		if ( parts.retry ) {
			parts.retry.hidden = ! is_retry;
			if ( is_retry ) {
				parts.retry.textContent = retry_label;
			}
		}
	}
	function site_start_progress( form, retry_label ) {
		var base  = config.progressBaseUrl || '';
		var token = config.progressToken || '';
		var parts = site_overlay_parts( form );
		var total = site_overlay_total( parts );
		if ( ! parts || ! base || ! token ) {
			return function () {};
		}
		var timer  = null;
		var active = true;
		function stop() {
			active = false;
			if ( timer ) {
				window.clearTimeout( timer );
				timer = null;
			}
		}
		function poll() {
			if ( ! active ) {
				return;
			}
			var url = base + '/' + encodeURIComponent( token ) + '.txt?_=' + Date.now();
			window.fetch( url, { credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.ok ? response.text() : '';
				} )
				.then( function ( text ) {
					if ( ! active ) {
						return;
					}
					var state = site_parse_progress( text );
					if ( state ) {
						site_update_overlay( parts, state, total, retry_label );
					}
					timer = window.setTimeout( poll, PROGRESS_INTERVAL );
				} )
				.catch( function () {
					if ( active ) {
						timer = window.setTimeout( poll, PROGRESS_INTERVAL );
					}
				} );
		}
		timer = window.setTimeout( poll, PROGRESS_FIRST_DELAY );
		return stop;
	}
	function site_show_overlay( form ) {
		var parts = site_overlay_parts( form );
		if ( ! parts ) {
			return;
		}
		if ( ! parts.overlay.dataset.gachireaiCancelBound ) {
			parts.overlay.addEventListener( 'cancel', function ( event ) {
				event.preventDefault();
			} );
			parts.overlay.dataset.gachireaiCancelBound = '1';
		}
		if ( typeof parts.overlay.showModal === 'function' ) {
			if ( ! parts.overlay.open ) {
				parts.overlay.showModal();
			}
			return;
		}
		parts.overlay.removeAttribute( 'hidden' );
		parts.overlay.setAttribute( 'open', 'open' );
	}
	function site_hide_overlay( form ) {
		var parts = site_overlay_parts( form );
		if ( ! parts ) {
			return;
		}
		if ( typeof parts.overlay.close === 'function' ) {
			if ( parts.overlay.open ) {
				parts.overlay.close();
			}
		} else {
			parts.overlay.removeAttribute( 'open' );
			parts.overlay.setAttribute( 'hidden', 'hidden' );
		}
		parts.overlay.classList.remove( 'is-retrying' );
	}
	document.addEventListener( 'change', function ( event ) {
		var ec = event.target && event.target.closest
			? event.target.closest( '[data-gachireai-site-ec]' )
			: null;
		if ( ! ec ) {
			return;
		}
		var form = ec.closest ? ec.closest( 'form' ) : null;
		if ( ! form ) {
			return;
		}
		var mode         = ec.getAttribute( 'data-gachireai-site-ec' ) || 'category';
		var options      = form.querySelectorAll( '[data-gachireai-type-option]' );
		var needs_switch = false;
		Array.prototype.forEach.call( options, function ( option ) {
			var option_mode = option.getAttribute( 'data-gachireai-type-option' );
			var visible     = option_mode === 'all' || option_mode === mode;
			option.hidden = ! visible;
			var input = option.querySelector( 'input[type="radio"]' );
			if ( input && input.checked && ! visible ) {
				needs_switch = true;
			}
		} );
		if ( needs_switch ) {
			var general = form.querySelector( '[data-gachireai-type-option="all"] input[type="radio"]' );
			if ( general ) {
				general.checked = true;
			}
		}
	} );
	var site_run = null;
	document.addEventListener( 'click', function ( event ) {
		var abort = event.target && event.target.closest
			? event.target.closest( '[data-gachireai-site-overlay-abort]' )
			: null;
		if ( ! abort || ! site_run || site_run.aborted ) {
			return;
		}
		event.preventDefault();
		var run = site_run;
		run.aborted = true;
		if ( run.controller ) {
			run.controller.abort();
		}
		run.stop_progress();
		site_hide_overlay( run.form );
		run.button.disabled = false;
		site_run = null;
	} );
	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest
			? event.target.closest( '[data-gachireai-site-generate]' )
			: null;
		if ( ! button || button.disabled ) {
			return;
		}
		var action = button.getAttribute( 'data-gachireai-site-generate' );
		var form   = button.form || ( button.closest ? button.closest( 'form' ) : null );
		if ( ! action || ! form || ! config.ajaxUrl ) {
			return;
		}
		event.preventDefault();
		var data = new FormData( form );
		data.append( 'action', action );
		if ( button.name ) {
			data.append( button.name, button.value );
		}
		button.disabled = true;
		var parts       = site_overlay_parts( form );
		var retry_label = parts && parts.retry ? parts.retry.textContent : 'リトライ中';
		site_show_overlay( form );
		var controller    = typeof window.AbortController === 'function' ? new window.AbortController() : null;
		var stop_progress = site_start_progress( form, retry_label );
		var run           = {
			aborted: false,
			controller: controller,
			stop_progress: stop_progress,
			form: form,
			button: button
		};
		site_run = run;
		var options = {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		};
		if ( controller ) {
			options.signal = controller.signal;
		}
		window.fetch( config.ajaxUrl, options )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success || ! payload.data || ! payload.data.html ) {
					throw new Error( payload && payload.data && payload.data.message ? payload.data.message : '' );
				}
				stop_progress();
				if ( run.aborted ) {
					return;
				}
				site_run = null;
				site_hide_overlay( form );
				form.outerHTML = payload.data.html;
			} )
			.catch( function ( err ) {
				var was_aborted = run.aborted;
				stop_progress();
				site_run = null;
				if ( was_aborted ) {
					return;
				}
				site_hide_overlay( form );
				button.disabled = false;
				window.alert(
					err && err.message
						? err.message
						: ( config.i18n && config.i18n.requestFailed ? config.i18n.requestFailed : '' )
				);
			} );
	} );
	function hide_loading() {
		var loading = document.querySelector( '.gachireai-loading' );
		if ( loading ) {
			loading.classList.add( 'gachireai-loading--hidden' );
		}
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', hide_loading );
	} else {
		hide_loading();
	}
	document.addEventListener( 'click', function ( event ) {
		if ( event.defaultPrevented ) {
			return;
		}
		if ( event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}
		var link = event.target && event.target.closest ? event.target.closest( '.gachireai-tabs .nav-tab' ) : null;
		if ( ! link || link.classList.contains( 'nav-tab-active' ) ) {
			return;
		}
		var href = link.getAttribute( 'href' );
		if ( ! href || '#' === href.charAt( 0 ) ) {
			return;
		}
		var loading = document.querySelector( '.gachireai-loading' );
		if ( ! loading ) {
			return;
		}
		event.preventDefault();
		loading.classList.remove( 'gachireai-loading--hidden' );
		window.setTimeout( function () {
			window.location.href = href;
		}, 300 );
	} );
}() );
