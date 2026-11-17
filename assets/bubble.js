/**
 * Front-end Denise panel, opened from the "Denise" admin-bar item. Mounts the
 * shared chat (deniseCreateChat) scoped to the post being viewed. No build
 * step — plain DOM.
 *
 * Only enqueued for logged-in users who can edit the current post, on post
 * types activated in Settings (see Frontend\Launcher), so there is no public
 * exposure here.
 *
 * Desktop (>= 783px): the panel opens in the lower-left corner and can be
 * dragged by its header; the position is kept in localStorage (per browser,
 * per site origin) and restored, clamped to the viewport. On phones the
 * panel is full width at the bottom and is not draggable.
 */
( function () {
	'use strict';

	if ( ! window.deniseCreateChat || ! window.deniseChat || ! window.deniseBubble ) {
		return;
	}

	// Footer scripts print before WordPress renders the admin bar (wp_footer
	// priority 20 vs 1000), so the toolbar launcher does not exist yet when
	// this file runs: boot once the document is parsed, or the panel falls
	// back to the corner button meant for builder editors.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	function boot() {
		var root = document.getElementById( 'denise-bubble-root' );
		if ( ! root ) {
			return;
		}

		var i18n     = window.deniseBubble.i18n || {};
		var postId   = parseInt( window.deniseBubble.postId, 10 ) || 0;
		var mode     = window.deniseBubble.mode || 'front';
		var node     = window.deniseBubble.nodeId ? document.getElementById( window.deniseBubble.nodeId ) : null;
		// Inside a builder's editor a bridge script supplies the live document and applies edits.
		var bridge   = 'elementor' === mode && window.DeniseEditorBridge ? window.DeniseEditorBridge : null;
		var STORE    = 'denise.panel.pos' + ( 'front' === mode ? '' : '.' + mode );
		var MARGIN   = 8;
		var desktop  = window.matchMedia( '(min-width: 783px)' );

		// Panel.
		var panel = document.createElement( 'div' );
		panel.className = 'denise-bubble-panel' + ( 'front' === mode ? '' : ' is-editor' );
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-label', i18n.title || 'Denise' );
		panel.hidden = true;

		var head = document.createElement( 'div' );
		head.className = 'denise-bubble-head';

		var title = document.createElement( 'span' );
		title.className = 'denise-bubble-title';
		var mark = document.createElement( 'span' );
		mark.className = 'denise-bubble-title__mark';
		mark.setAttribute( 'aria-hidden', 'true' );
		mark.textContent = 'D';
		title.appendChild( mark );
		title.appendChild( document.createTextNode( i18n.title || 'Denise' ) );

		// The chat's history / new-chat buttons live here, in the panel header,
		// instead of a toolbar row of their own (see actionsHost in chat.js).
		var tools = document.createElement( 'span' );
		tools.className = 'denise-bubble-tools';

		var close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'denise-bubble-close';
		close.setAttribute( 'aria-label', i18n.close || 'Close' );
		close.innerHTML = '&times;';

		var right = document.createElement( 'span' );
		right.className = 'denise-bubble-right';
		right.appendChild( tools );
		right.appendChild( close );

		head.appendChild( title );
		head.appendChild( right );

		// Notice shown after Denise edits this page: pending draft (published page)
		// or reload hint (draft page). Non-destructive: keeps the conversation open.
		var notice = document.createElement( 'div' );
		notice.className = 'denise-bubble-notice';
		notice.hidden = true;

		var mount = document.createElement( 'div' );
		mount.className = 'denise-bubble-mount';

		panel.appendChild( head );
		panel.appendChild( notice );
		panel.appendChild( mount );
		root.appendChild( panel );

		var api  = null;
		var open = false;

		/* ---- Position: remember where the user dragged the panel ------------ */

		function readPos() {
			try {
				var v = JSON.parse( window.localStorage.getItem( STORE ) );
				if ( v && isFinite( v.x ) && isFinite( v.y ) ) {
					return { x: Number( v.x ), y: Number( v.y ) };
				}
			} catch ( e ) {
				// Storage blocked or unreadable: fall back to the CSS default.
			}
			return null;
		}

		function savePos( x, y ) {
			try {
				window.localStorage.setItem( STORE, JSON.stringify( { x: Math.round( x ), y: Math.round( y ) } ) );
			} catch ( e ) {
				// Private mode etc.: position simply is not remembered.
			}
		}

		function topLimit() {
			var bar = document.getElementById( 'wpadminbar' );
			// The toolbar sits above the panel (higher z-index); keep clear of it when it is visible.
			return bar && bar.offsetHeight ? bar.offsetHeight + MARGIN : MARGIN;
		}

		function clamp( x, y ) {
			var w    = panel.offsetWidth;
			var h    = panel.offsetHeight;
			var maxX = Math.max( MARGIN, window.innerWidth - w - MARGIN );
			var minY = topLimit();
			var maxY = Math.max( minY, window.innerHeight - h - MARGIN );
			return {
				x: Math.min( Math.max( MARGIN, x ), maxX ),
				y: Math.min( Math.max( minY, y ), maxY ),
			};
		}

		function place( x, y ) {
			var p = clamp( x, y );
			panel.style.left = p.x + 'px';
			panel.style.top  = p.y + 'px';
			panel.classList.add( 'is-placed' );
			return p;
		}

		function unplace() {
			panel.classList.remove( 'is-placed' );
			panel.style.left = '';
			panel.style.top  = '';
		}

		// Apply the stored position (desktop only; phones keep the CSS layout).
		function restore() {
			if ( panel.hidden ) {
				return;
			}
			if ( ! desktop.matches ) {
				unplace();
				return;
			}
			var s = readPos();
			if ( s ) {
				place( s.x, s.y );
			} else {
				unplace();
			}
		}

		// Drag by the header (not the close button).
		var drag = null;

		head.addEventListener( 'pointerdown', function ( e ) {
			if ( ! desktop.matches || e.button !== 0 || right.contains( e.target ) ) {
				return;
			}
			var rect = panel.getBoundingClientRect();
			drag = { px: e.clientX, py: e.clientY, x: rect.left, y: rect.top, moved: false };
			panel.classList.add( 'is-dragging' );
			try {
				head.setPointerCapture( e.pointerId );
			} catch ( err ) {
				// Older browsers: moves are still tracked while the pointer stays on the header.
			}
			e.preventDefault();
		} );

		head.addEventListener( 'pointermove', function ( e ) {
			if ( ! drag ) {
				return;
			}
			var dx = e.clientX - drag.px;
			var dy = e.clientY - drag.py;
			if ( ! drag.moved && Math.abs( dx ) < 3 && Math.abs( dy ) < 3 ) {
				return;
			}
			drag.moved = true;
			place( drag.x + dx, drag.y + dy );
		} );

		function endDrag( e ) {
			if ( ! drag ) {
				return;
			}
			panel.classList.remove( 'is-dragging' );
			try {
				head.releasePointerCapture( e.pointerId );
			} catch ( err ) {
				// Nothing captured.
			}
			if ( drag.moved ) {
				var rect = panel.getBoundingClientRect();
				savePos( rect.left, rect.top );
			}
			drag = null;
		}

		head.addEventListener( 'pointerup', endDrag );
		head.addEventListener( 'pointercancel', endDrag );

		// Keep the panel on screen when the window changes size or the breakpoint flips.
		var resizeTimer = null;
		window.addEventListener( 'resize', function () {
			window.clearTimeout( resizeTimer );
			resizeTimer = window.setTimeout( function () {
				if ( panel.hidden ) {
					return;
				}
				if ( ! desktop.matches ) {
					unplace();
				} else if ( panel.classList.contains( 'is-placed' ) ) {
					var rect = panel.getBoundingClientRect();
					place( rect.left, rect.top );
				}
			}, 100 );
		} );

		/* ---- Chat ------------------------------------------------------------ */

		function showUpdated( action ) {
			notice.innerHTML = '';
			var span = document.createElement( 'span' );
			var pending = action && action.pending_publish;
			span.textContent = pending ? ( i18n.pending || 'Saved as a draft.' ) : ( i18n.updated || 'This page was updated.' );
			notice.appendChild( span );
			if ( pending && action.edit ) {
				var openLink = document.createElement( 'a' );
				openLink.className = 'denise-bubble-reload';
				openLink.href = action.edit;
				openLink.textContent = i18n.open || 'Open in Elementor';
				notice.appendChild( openLink );
			} else {
				var reload = document.createElement( 'button' );
				reload.type = 'button';
				reload.className = 'denise-bubble-reload';
				reload.textContent = i18n.reload || 'Reload';
				reload.addEventListener( 'click', function () {
					window.location.reload();
				} );
				notice.appendChild( reload );
			}
			notice.hidden = false;
		}

		// Editor mode: the edit was applied in the open editor (or failed there).
		var appliedShown = false;
		var lastEdits    = [];

		function showApplied( result ) {
			if ( result && false === result.ok ) {
				notice.innerHTML = '';
				notice.appendChild( document.createTextNode( ( i18n.failed || 'The editor could not apply that change:' ) + ' ' + ( result.error || '' ) ) );
				notice.hidden = false;
				appliedShown = false;
				return;
			}
			if ( appliedShown ) {
				return;
			}
			appliedShown = true;
			notice.innerHTML = '';
			notice.appendChild( document.createTextNode( i18n.applied || 'Changed in the editor.' ) );
			var ok = document.createElement( 'button' );
			ok.type = 'button';
			ok.className = 'denise-bubble-reload';
			ok.textContent = i18n.dismiss || 'OK';
			ok.addEventListener( 'click', function () {
				notice.hidden = true;
			} );
			notice.appendChild( ok );
			notice.hidden = false;
		}

		function ensureChat() {
			if ( api ) {
				return;
			}
			api = window.deniseCreateChat( mount, {
				postId: postId,
				compact: true,
				header: false,
				actionsHost: tools,
				greeting: i18n.greeting || '',
				intro: i18n.intro || '',
				// Live editor state travels with every message in editor mode.
				context: bridge ? function () { return bridge.context( postId ); } : null,
				onAction: function ( action ) {
					if ( ! action || action.post_id !== postId ) {
						return;
					}
					if ( 'editor' === action.action ) {
						if ( bridge ) {
							bridge.apply( action, function ( outcome ) {
								// Always visible in the console: what arrived and what the editor said.
								if ( window.console && window.console.info ) {
									window.console.info( '[Denise] editor edit', action.op, outcome, action );
								}
								lastEdits.push( { action: action, result: outcome } );
								if ( lastEdits.length > 10 ) {
									lastEdits.shift();
								}
								showApplied( outcome );
							} );
						}
						return;
					}
					if ( 'updated' === action.action ) {
						showUpdated( action );
					}
				},
			} );
		}

		// No admin bar in a builder's editor: a small corner button opens the panel.
		var fab = null;
		if ( ! node ) {
			fab = document.createElement( 'button' );
			fab.type = 'button';
			fab.className = 'denise-bubble-fab';
			fab.setAttribute( 'aria-label', i18n.launcher || 'Open Denise' );
			fab.title = i18n.launcher || 'Open Denise';
			fab.textContent = 'D';
			fab.addEventListener( 'click', function () {
				setOpen( ! open );
			} );
			root.appendChild( fab );
		}

		function setOpen( next ) {
			open = next;
			panel.hidden = ! open;
			if ( fab ) {
				fab.hidden = open;
			}
			if ( node ) {
				node.classList.toggle( 'is-open', open );
				var link = node.querySelector( 'a' );
				if ( link ) {
					link.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				}
			}
			if ( open ) {
				restore();
				ensureChat();
				if ( api ) {
					api.focus();
				}
			}
		}

		// The admin-bar node is the launcher.
		if ( node ) {
			node.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				setOpen( ! open );
			} );
		}

		// Any other link to #denise-chat opens it too (editor-placed buttons).
		document.addEventListener( 'click', function ( e ) {
			var a = e.target && e.target.closest ? e.target.closest( 'a[href="#denise-chat"]' ) : null;
			if ( a && ( ! node || ! node.contains( a ) ) ) {
				e.preventDefault();
				setOpen( true );
			}
		} );

		close.addEventListener( 'click', function () {
			setOpen( false );
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && open ) {
				setOpen( false );
			}
		} );

		window.Denise = window.Denise || {};
		window.Denise.open = function () { setOpen( true ); };
		window.Denise.close = function () { setOpen( false ); };
		window.Denise.lastEdits = function () { return lastEdits.slice(); };
		window.Denise.toggle = function () { setOpen( ! open ); };
		window.Denise.resetPosition = function () {
			try {
				window.localStorage.removeItem( STORE );
			} catch ( e ) {
				// Nothing stored.
			}
			unplace();
		};
	}
} )();
