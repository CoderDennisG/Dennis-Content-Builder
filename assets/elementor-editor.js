/**
 * Denise ↔ Elementor editor bridge. Loaded only inside the Elementor editor
 * (see Admin\ElementorEditor), before bubble.js, which picks it up as
 * window.DeniseEditorBridge.
 *
 * Two jobs:
 *  - context(postId): the live document as Denise should see it (ids, types,
 *    text settings, current selection). Sent with every message, because the
 *    saved copy is stale while the editor is open.
 *  - apply(action): run one of Denise's edits through the editor's own
 *    command bus ($e.run), exactly like a manual edit: instant in the
 *    preview, in the undo history, saved only when the person clicks Update.
 *    Saved templates go through Elementor's own library flow
 *    (library/open + library/insert-template), the same as clicking Insert.
 *
 * Elementor's $e commands are its internal but long-stable API (3.x+); the
 * v4 editor packages use the same commands for their own panels.
 */
( function () {
	'use strict';

	var MAX_TEXT  = 600;
	var MAX_DEPTH = 12;

	// Content-bearing keys of legacy (V3) widgets; V4 atomic widgets have a
	// clean typed schema and are read whole.
	var V3_KEYS = [
		'title', 'editor', 'text', 'html', 'button_text', 'title_text', 'description_text',
		'heading', 'sub_heading', 'caption', 'alt', 'custom_text', 'label', 'placeholder',
		'tab_title', 'tab_content', 'content', 'header_size', 'size', 'link', 'url', 'shortcode',
	];

	function ready() {
		return !! ( window.elementor && window.$e && window.elementor.documents && window.elementor.getContainer );
	}

	function rootContainer() {
		var doc = window.elementor.documents.getCurrent();
		if ( doc && doc.container ) {
			return doc.container;
		}
		return window.elementor.getContainer( 'document' );
	}

	function isAtomic( type ) {
		var cache = window.elementor.widgetsCache || {};
		return !! ( cache[ type ] && cache[ type ].atomic_props_schema );
	}

	// Unwrap a typed prop ({ $$type, value }) or a plain scalar into a plain scalar, or undefined.
	function plain( value, depth ) {
		depth = depth || 0;
		if ( depth > 4 || value === null || value === undefined ) {
			return undefined;
		}
		var t = typeof value;
		if ( 'string' === t || 'number' === t || 'boolean' === t ) {
			return value;
		}
		if ( 'object' === t && 'string' === typeof value.$$type ) {
			var kind = value.$$type;
			var v = value.value;
			// Scalar prop types (headings/paragraphs/buttons are escaped-html).
			if ( [ 'string', 'escaped-html', 'html', 'number', 'boolean', 'url', 'email', 'color', 'date-string', 'time-string', 'font-family' ].indexOf( kind ) !== -1 ) {
				return plain( v, depth + 1 );
			}
			if ( 'link' === kind && v && v.destination !== undefined ) {
				var d = plain( v.destination, depth + 1 );
				return 'string' === typeof d ? d : undefined;
			}
			return undefined;
		}
		if ( 'object' === t && 'string' === typeof value.url ) {
			// V3 link control.
			return value.url;
		}
		return undefined;
	}

	function clip( value ) {
		if ( 'string' === typeof value && value.length > MAX_TEXT ) {
			return value.slice( 0, MAX_TEXT ) + '…';
		}
		return value;
	}

	function settingsOf( container, type, atomic ) {
		var raw = container.settings && container.settings.toJSON ? container.settings.toJSON() : {};
		var out = {};
		var keys = atomic ? Object.keys( raw ) : V3_KEYS;
		for ( var i = 0; i < keys.length; i++ ) {
			var key = keys[ i ];
			if ( '_' === key.charAt( 0 ) || 'classes' === key || 'attributes' === key || ! ( key in raw ) ) {
				continue;
			}
			var v = plain( raw[ key ] );
			if ( v === undefined || '' === v ) {
				continue;
			}
			out[ key ] = clip( v );
		}
		return out;
	}

	function walk( children, depth ) {
		var list = [];
		if ( ! children || depth > MAX_DEPTH ) {
			return list;
		}
		var arr = Array.isArray( children ) ? children : Array.prototype.slice.call( children );
		for ( var i = 0; i < arr.length; i++ ) {
			var c = arr[ i ];
			if ( ! c || ! c.model ) {
				continue;
			}
			var elType = c.model.get( 'elType' );
			var widgetType = c.model.get( 'widgetType' );
			var type = widgetType || elType;
			var atomic = isAtomic( type );
			var node = {
				id: c.id,
				elType: elType,
				version: atomic ? 4 : 3,
			};
			if ( widgetType ) {
				node.widgetType = widgetType;
			}
			try {
				var title = c.model.getTitle ? c.model.getTitle() : '';
				if ( title ) {
					node.title = String( title );
				}
			} catch ( e ) {
				// Title is cosmetic.
			}
			var settings = settingsOf( c, type, atomic );
			if ( Object.keys( settings ).length ) {
				node.settings = settings;
			}
			var kids = walk( c.children, depth + 1 );
			if ( kids.length ) {
				node.elements = kids;
			}
			list.push( node );
		}
		return list;
	}

	function selectedIds() {
		try {
			var sel = window.elementor.selection;
			if ( sel && sel.getElements ) {
				return sel.getElements().map( function ( c ) { return c.id; } ).filter( Boolean );
			}
		} catch ( e ) {
			// No selection API: fine.
		}
		return [];
	}

	function get( id ) {
		var c = window.elementor.getContainer( id );
		if ( ! c ) {
			throw new Error( 'Element ' + id + ' is not on this page any more.' );
		}
		return c;
	}

	// $e.run never throws: the command bus logs the exception with
	// console.error and returns null. Capture that log for the duration of
	// the synchronous call so a failure becomes an error we can report.
	function run( command, args ) {
		var original = window.console.error;
		var captured = null;
		window.console.error = function ( e ) {
			// The bus passes the thrown Error itself; plain-string logs from
			// unrelated code during the call are not failures.
			if ( null === captured && e instanceof Error ) {
				captured = e.message || String( e );
			}
			return original.apply( window.console, arguments );
		};
		var result;
		try {
			result = window.$e.run( command, args );
		} finally {
			window.console.error = original;
		}
		if ( null !== captured ) {
			throw new Error( command + ': ' + captured );
		}
		if ( null === result ) {
			throw new Error( command + ' did not run.' );
		}
		return result;
	}

	// Failures since the last message, sent with the next one so Denise
	// knows an edit she reported as applied did not land.
	var failures = [];

	function remember( action, error ) {
		failures.push( { op: action.op || '?', error: String( error ).slice( 0, 300 ) } );
		if ( failures.length > 5 ) {
			failures.shift();
		}
		if ( window.console && window.console.warn ) {
			window.console.warn( '[Denise] editor edit failed:', action, error );
		}
	}

	// Legacy (V3) widgets keep list content in "repeater" settings whose rows
	// are their own containers (container.repeaters[name].children). A text
	// edit must go through those rows, exactly as the panel does: the row keeps
	// its icon, id and link, and the widget re-renders. Keys here are the
	// repeaters Denise may touch; everything else in a row is left alone.
	var REPEATERS = { icon_list: true, tabs: true, slides: true };
	var ROW_KEEP = { _id: true, selected_icon: true, icon: true, image: true };

	function textOnly( row ) {
		var out = {};
		Object.keys( row || {} ).forEach( function ( key ) {
			var v = row[ key ];
			if ( ROW_KEEP[ key ] ) {
				return;
			}
			if ( 'link' === key ) {
				var url = v && 'object' === typeof v ? ( v.url || v.destination || '' ) : String( v || '' );
				out.link = { url: url };
				return;
			}
			if ( 'string' === typeof v || 'number' === typeof v || 'boolean' === typeof v ) {
				out[ key ] = v;
			}
		} );
		return out;
	}

	function updateRows( container, name, rows ) {
		var repeater = container.repeaters && container.repeaters[ name ];
		var existing = repeater && repeater.children ? repeater.children : [];
		var i;
		for ( i = 0; i < rows.length && i < existing.length; i++ ) {
			var change = textOnly( rows[ i ] );
			if ( Object.keys( change ).length ) {
				run( 'document/elements/settings', { container: existing[ i ], settings: change } );
			}
		}
		// Extra rows: clone the last existing row (icon and all), then set the text.
		var last = existing.length ? existing[ existing.length - 1 ].settings.toJSON() : {};
		for ( i = existing.length; i < rows.length; i++ ) {
			var model = Object.assign( {}, last, textOnly( rows[ i ] ) );
			delete model._id;
			run( 'document/repeater/insert', { container: container, name: name, model: model } );
		}
		// Surplus rows: remove from the end.
		for ( i = existing.length - 1; i >= rows.length; i-- ) {
			run( 'document/repeater/remove', { container: container, name: name, index: i } );
		}
	}

	function updateElement( container, settings ) {
		var type = container.model.get( 'widgetType' ) || container.model.get( 'elType' );
		var legacy = ! isAtomic( type );
		var scalars = {};
		Object.keys( settings ).forEach( function ( key ) {
			if ( legacy && REPEATERS[ key ] && Array.isArray( settings[ key ] ) ) {
				updateRows( container, key, settings[ key ] );
				return;
			}
			scalars[ key ] = settings[ key ];
		} );
		if ( Object.keys( scalars ).length ) {
			run( 'document/elements/settings', { container: container, settings: scalars } );
		}
		// Legacy views do not always redraw on a programmatic change (inline
		// editing bindings, lazily loaded controls): ask for a full render.
		if ( legacy && container.view && 'function' === typeof container.view.renderHTML ) {
			try {
				container.view.renderHTML();
			} catch ( e ) {
				// Rendering is cosmetic; the model holds the change.
			}
		}
	}

	// Insert a saved template the way the library does it: open the library,
	// run its insert command for the template, and let Elementor fetch the
	// content, show its styles dialog if the template carries global styles,
	// close the modal and import. The outcome is observed on the command bus:
	// document/elements/import for this template = success, library/close
	// without it = cancelled.
	var IMPORT_WAIT = 5 * 60 * 1000;

	function importTemplate( action, done ) {
		var templateId = parseInt( action.template_id, 10 );
		if ( ! templateId || ! window.Backbone || ! window.$e.commands || ! window.$e.commands.on ) {
			var bad = { ok: false, error: 'The template library is not available in this editor.' };
			remember( action, bad.error );
			return done( bad );
		}
		var title = action.title || 'Template';
		var model = new window.Backbone.Model( {
			source: 'local',
			template_id: templateId,
			title: title,
			type: action.type || '',
			hasPageSettings: false,
		} );
		var settled = false;
		var timer = null;
		var closeTimer = null;

		function finish( result ) {
			if ( settled ) {
				return;
			}
			settled = true;
			window.$e.commands.off( 'run:after', after );
			clearTimeout( timer );
			clearTimeout( closeTimer );
			if ( ! result.ok ) {
				remember( action, result.error );
			}
			done( result );
		}

		function after( component, command, args, results ) {
			if ( 'document/elements/import' === command && args && args.model === model ) {
				var added = Array.isArray( results ) ? results.length > 0 : !! results;
				finish( added ? { ok: true } : { ok: false, error: 'The editor did not add "' + title + '".' } );
			} else if ( 'library/close' === command && ! closeTimer ) {
				// The library hides itself right before importing: give the import a moment.
				closeTimer = setTimeout( function () {
					finish( { ok: false, error: 'The template library was closed without inserting "' + title + '".' } );
				}, 2000 );
			}
		}

		window.$e.commands.on( 'run:after', after );
		timer = setTimeout( function () {
			finish( { ok: false, error: 'Gave up waiting for "' + title + '" to be inserted.' } );
		}, IMPORT_WAIT );

		try {
			var importOptions = {};
			if ( 'number' === typeof action.index ) {
				importOptions.at = action.index;
			}
			run( 'library/open', { toDefault: true, importOptions: importOptions } );
			run( 'library/insert-template', { model: model } );
		} catch ( e ) {
			finish( { ok: false, error: e && e.message ? e.message : String( e ) } );
		}
		return { ok: true, pending: true };
	}

	var bridge = {
		kind: 'elementor',

		ready: ready,

		context: function ( postId ) {
			if ( ! ready() ) {
				return {};
			}
			try {
				var root = rootContainer();
				var ctx = {
					editor: {
						kind: 'elementor',
						post_id: postId,
						selected: selectedIds(),
						elements: walk( root ? root.children : [], 0 ),
					},
				};
				if ( failures.length ) {
					ctx.editor.failures = failures.slice();
					failures = [];
				}
				return ctx;
			} catch ( e ) {
				return {};
			}
		},

		// Applies one edit. done( { ok: true } | { ok: false, error } ) is
		// called exactly once: synchronously for element edits, later for a
		// template insert (Elementor's library fetches and may ask about
		// styles first). Never throws.
		apply: function ( action, done ) {
			var cb = 'function' === typeof done ? done : function () {};
			if ( ! ready() ) {
				return cb( { ok: false, error: 'The editor is not ready.' } );
			}
			if ( 'import' === action.op ) {
				return importTemplate( action, cb );
			}
			try {
				switch ( action.op ) {
					case 'update':
						updateElement( get( action.element_id ), action.settings || {} );
						break;

					case 'create': {
						var parent = action.parent_id ? get( action.parent_id ) : rootContainer();
						var options = { edit: false };
						if ( 'number' === typeof action.index ) {
							options.at = action.index;
						}
						run( 'document/elements/create', {
							container: parent,
							model: action.model,
							options: options,
						} );
						break;
					}

					case 'move': {
						var target = get( action.new_parent_id );
						var opts = {};
						if ( 'number' === typeof action.index ) {
							opts.at = action.index;
						}
						run( 'document/elements/move', {
							container: get( action.element_id ),
							target: target,
							options: opts,
						} );
						break;
					}

					case 'duplicate':
						run( 'document/elements/duplicate', { container: get( action.element_id ) } );
						break;

					case 'delete':
						run( 'document/elements/delete', { container: get( action.element_id ) } );
						break;

					default:
						return cb( { ok: false, error: 'Unknown edit "' + action.op + '".' } );
				}
				return cb( { ok: true } );
			} catch ( e ) {
				var message = e && e.message ? e.message : String( e );
				remember( action, message );
				return cb( { ok: false, error: message } );
			}
		},
	};

	window.DeniseEditorBridge = bridge;
} )();
