
( function () {
	'use strict';
	var config = window.gachireaiAiGenerate || {};
	if ( ! config.ajaxUrl || ! config.action ) {
		return;
	}
	var PROGRESS_FIRST_DELAY = 600;
	var PROGRESS_INTERVAL    = 800;
	var active_run = null;
	function parse_progress( text ) {
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
	function overlay_parts( box ) {
		if ( ! box ) {
			return null;
		}
		var overlay = box.querySelector( '[data-gachireai-ai-overlay]' );
		if ( ! overlay ) {
			return null;
		}
		return {
			overlay: overlay,
			fraction: overlay.querySelector( '[data-gachireai-ai-overlay-fraction]' ),
			num: overlay.querySelector( '[data-gachireai-ai-overlay-num]' ),
			label: overlay.querySelector( '[data-gachireai-ai-overlay-label]' ),
			retry: overlay.querySelector( '[data-gachireai-ai-overlay-retry]' ),
			dots: overlay.querySelectorAll( '[data-gachireai-ai-overlay-dot]' )
		};
	}
	function overlay_total( parts ) {
		var total = parts && parts.overlay
			? parseInt( parts.overlay.getAttribute( 'data-gachireai-ai-overlay-total' ), 10 )
			: 0;
		return total > 0 ? total : 10;
	}
	function update_overlay( parts, state, total ) {
		if ( ! parts || ! state ) {
			return;
		}
		var denominator   = state.total > 0 ? state.total : total;
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
		if ( parts.overlay ) {
			var total_element = parts.overlay.querySelector( '[data-gachireai-ai-overlay-total]' );
			if ( total_element ) {
				total_element.textContent = String( denominator );
			}
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
			if ( is_retry ) {
				parts.retry.hidden = false;
				parts.retry.textContent = config.i18n && config.i18n.retrying ? config.i18n.retrying : 'リトライ中';
			} else {
				parts.retry.hidden = true;
			}
		}
	}
	function start_progress( box ) {
		var base  = config.progressBaseUrl || '';
		var token = config.progressToken || '';
		var parts = overlay_parts( box );
		var total = overlay_total( parts );
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
					var state = parse_progress( text );
					if ( state ) {
						update_overlay( parts, state, total );
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
	function show_overlay( box ) {
		var parts = overlay_parts( box );
		if ( ! parts ) {
			return;
		}
		var total = overlay_total( parts );
		update_overlay(
			parts,
			{
				label: config.i18n && config.i18n.generating ? config.i18n.generating : '',
				phase: 1,
				total: total,
				retry: null
			},
			total
		);
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
	function hide_overlay( box ) {
		var parts = overlay_parts( box );
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
	function report_error( message ) {
		window.alert( message || ( config.i18n && config.i18n.requestFailed ? config.i18n.requestFailed : '' ) );
	}
	function has_unsaved_content() {
		if ( window.wp && window.wp.data && window.wp.data.select ) {
			try {
				var editor = window.wp.data.select( 'core/editor' );
				if ( editor ) {
					if ( typeof editor.isEditedPostDirty === 'function' && editor.isEditedPostDirty() ) {
						return true;
					}
					if ( typeof editor.hasNonPostEntityChanges === 'function' && editor.hasNonPostEntityChanges() ) {
						return true;
					}
				}
			} catch ( e ) {
			}
		}
		if ( window.tinymce && typeof window.tinymce.get === 'function' ) {
			var tmce = window.tinymce.get( 'content' );
			if ( tmce && typeof tmce.isDirty === 'function' && tmce.isDirty() ) {
				return true;
			}
		}
		var titleInput = document.getElementById( 'title' );
		if ( titleInput && typeof titleInput.value === 'string' && titleInput.value !== titleInput.defaultValue ) {
			return true;
		}
		return false;
	}
	document.addEventListener( 'click', function ( event ) {
		var abort = event.target && event.target.closest
			? event.target.closest( '[data-gachireai-ai-overlay-abort]' )
			: null;
		if ( ! abort || ! active_run || active_run.aborted ) {
			return;
		}
		event.preventDefault();
		var run = active_run;
		run.aborted = true;
		if ( run.controller ) {
			run.controller.abort();
		}
		run.stop_progress();
		hide_overlay( run.box );
		run.button.disabled = false;
		active_run = null;
	} );
	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest
			? event.target.closest( '[data-gachireai-ai-generate]' )
			: null;
		if ( ! button || button.disabled ) {
			return;
		}
		var box = button.closest ? button.closest( '[data-gachireai-ai-generate-box]' ) : null;
		if ( ! box ) {
			return;
		}
		event.preventDefault();
		if ( has_unsaved_content() ) {
			window.alert(
				config.i18n && config.i18n.unsavedInterrupt
					? config.i18n.unsavedInterrupt
					: '保存していない内容があるため、AI生成を中断します。'
			);
			return;
		}
		var data = new FormData();
		data.append( 'action', config.action );
		if ( config.nonce ) {
			data.append( '_wpnonce', config.nonce );
		}
		button.disabled = true;
		show_overlay( box );
		var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
		var stop_progress = start_progress( box );
		var run = {
			aborted: false,
			controller: controller,
			stop_progress: stop_progress,
			box: box,
			button: button
		};
		active_run = run;
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
				active_run = null;
				if ( payload.data.edit_url ) {
					window.location.assign( payload.data.edit_url );
					return;
				}
				hide_overlay( box );
				box.outerHTML = payload.data.html;
				if ( payload.data.error ) {
					window.alert( payload.data.error );
				}
			} )
			.catch( function ( err ) {
				var was_aborted = run.aborted;
				stop_progress();
				active_run = null;
				if ( was_aborted ) {
					return;
				}
				hide_overlay( box );
				button.disabled = false;
				report_error( err && err.message ? err.message : '' );
			} );
	} );
} )();
