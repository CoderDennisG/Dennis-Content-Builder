/**
 * Denise chat — the one chat component every surface mounts.
 *
 * window.deniseCreateChat( rootEl, opts ) builds the UI inside rootEl and
 * returns { destroy, focus, setDisabled }. Mounted by:
 *   - the Ask Denise and Content Builder admin pages (auto-boot below, via
 *     a `data-denise-boot` JSON attribute on the mount element),
 *   - the Gutenberg sidebar (editor.js),
 *   - the front-end bubble (bubble.js).
 *
 * opts:
 *   postId       {number}   scope the conversation to a post (0 = global)
 *   compact      {bool}     narrow layout (sidebar / bubble)
 *   header       {bool}     false = no identity header, only the slim toolbar
 *   actionsHost  {Element}  put the history / new-chat buttons (and the
 *                           history popover) in this element instead of the
 *                           chat's own head; the head is then omitted entirely
 *   title        {string}   header title (default: "Denise")
 *   subtitle     {string}   header subtitle
 *   greeting     {string}   empty-state headline
 *   intro        {string}   empty-state sentence
 *   suggestions  {string[]} empty-state chips; click fills the composer
 *   placeholder  {string}   composer placeholder
 *   disabled     {bool}     composer locked (with disabledHint shown)
 *   disabledHint {string}
 *   chatUrl      {string}   endpoint override (default: deniseChat.chatUrl)
 *   resume       {number}   conversation id to reopen on mount (0 = start blank)
 *   onAction     {function} called with each created/updated/editor action
 *   context      {function} returns extra request fields sent with every
 *                           message (e.g. the live editor state)
 *   beforeSend   {function} returns a warning string to show, or null
 *
 * No build step: plain DOM, ES2017.
 */
( function () {
	'use strict';

	const SVG_NS = 'http://www.w3.org/2000/svg';

	const ICONS = {
		send: 'M3 10.5 17 3l-4.5 14-3.2-5.3L3 10.5z',
		plus: 'M10 4v12M4 10h12',
		history: 'M10 5v5l3 2M17 10a7 7 0 1 1-2-4.9M17 4v3h-3',
		copy: 'M7 7h9v9H7zM4 13V4h9',
		check: 'M4 10l4 4 8-8',
		trash: 'M4 6h12M8 6V4h4v2M6 6l1 10h6l1-10M9 9v4M11 9v4',
	};

	function icon( name, stroke ) {
		const svg = document.createElementNS( SVG_NS, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 20 20' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		const path = document.createElementNS( SVG_NS, 'path' );
		path.setAttribute( 'd', ICONS[ name ] );
		if ( stroke ) {
			path.setAttribute( 'fill', 'none' );
			path.setAttribute( 'stroke', 'currentColor' );
			path.setAttribute( 'stroke-width', '1.8' );
			path.setAttribute( 'stroke-linecap', 'round' );
			path.setAttribute( 'stroke-linejoin', 'round' );
		} else {
			path.setAttribute( 'fill', 'currentColor' );
		}
		svg.appendChild( path );
		return svg;
	}

	function el( tag, className, text ) {
		const node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text && null !== text ) {
			node.textContent = text;
		}
		return node;
	}

	function iconButton( name, label, extraClass ) {
		const btn = el( 'button', 'denise-iconbtn' + ( extraClass ? ' ' + extraClass : '' ) );
		btn.type = 'button';
		btn.setAttribute( 'aria-label', label );
		btn.title = label;
		btn.appendChild( icon( name, true ) );
		return btn;
	}

	function avatar( className ) {
		const node = el( 'span', 'denise-avatar ' + ( className || '' ), 'D' );
		node.setAttribute( 'aria-hidden', 'true' );
		return node;
	}

	// ---- Markdown (safe: builds DOM nodes, never innerHTML) -------------

	const RE_FENCE = /^```/;
	const RE_HEAD = /^(#{1,4})\s+(.*)$/;
	const RE_UL = /^\s*[-*+]\s+/;
	const RE_OL = /^\s*\d+[.)]\s+/;
	const RE_BLANK = /^\s*$/;

	function isBlockStart( line ) {
		return RE_FENCE.test( line ) || RE_HEAD.test( line ) || RE_UL.test( line ) || RE_OL.test( line );
	}

	function renderMarkdown( src, target, i18n ) {
		target.replaceChildren();
		const lines = String( src ).replace( /\r\n/g, '\n' ).split( '\n' );
		let i = 0;

		while ( i < lines.length ) {
			const line = lines[ i ];

			if ( RE_FENCE.test( line ) ) {
				const lang = line.slice( 3 ).trim();
				const buf = [];
				i++;
				while ( i < lines.length && ! RE_FENCE.test( lines[ i ] ) ) {
					buf.push( lines[ i ] );
					i++;
				}
				i++;
				target.appendChild( codeBlock( buf.join( '\n' ), lang, i18n ) );
				continue;
			}

			const h = RE_HEAD.exec( line );
			if ( h ) {
				const level = Math.min( 6, h[ 1 ].length + 2 );
				const node = el( 'h' + level );
				renderInline( h[ 2 ], node );
				target.appendChild( node );
				i++;
				continue;
			}

			if ( RE_UL.test( line ) || RE_OL.test( line ) ) {
				const ordered = RE_OL.test( line );
				const re = ordered ? RE_OL : RE_UL;
				const list = el( ordered ? 'ol' : 'ul' );
				while ( i < lines.length && re.test( lines[ i ] ) ) {
					const li = el( 'li' );
					renderInline( lines[ i ].replace( re, '' ), li );
					list.appendChild( li );
					i++;
				}
				target.appendChild( list );
				continue;
			}

			if ( RE_BLANK.test( line ) ) {
				i++;
				continue;
			}

			const buf = [ line ];
			i++;
			while ( i < lines.length && ! RE_BLANK.test( lines[ i ] ) && ! isBlockStart( lines[ i ] ) ) {
				buf.push( lines[ i ] );
				i++;
			}
			const p = el( 'p' );
			renderInline( buf.join( '\n' ), p );
			target.appendChild( p );
		}
	}

	const RE_INLINE = /(`[^`\n]+`)|(\*\*[^*\n]+\*\*)|(\*[^*\n]+\*)|(\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\))/g;

	function renderInline( text, target ) {
		// A fresh regex per call: renderInline recurses for bold/italic, and a
		// shared global regex would have its position reset by the inner call
		// (that looped forever on any reply containing bold text).
		const re = new RegExp( RE_INLINE.source, 'g' );
		let last = 0;
		let m;
		while ( ( m = re.exec( text ) ) ) {
			if ( m.index > last ) {
				target.appendChild( document.createTextNode( text.slice( last, m.index ) ) );
			}
			if ( m[ 1 ] ) {
				target.appendChild( el( 'code', null, m[ 1 ].slice( 1, -1 ) ) );
			} else if ( m[ 2 ] ) {
				const b = el( 'strong' );
				renderInline( m[ 2 ].slice( 2, -2 ), b );
				target.appendChild( b );
			} else if ( m[ 3 ] ) {
				const em = el( 'em' );
				renderInline( m[ 3 ].slice( 1, -1 ), em );
				target.appendChild( em );
			} else if ( m[ 4 ] ) {
				const a = el( 'a', null, m[ 5 ] );
				a.href = m[ 6 ];
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
				target.appendChild( a );
			}
			last = re.lastIndex;
		}
		if ( last < text.length ) {
			target.appendChild( document.createTextNode( text.slice( last ) ) );
		}
	}

	function codeBlock( code, lang, i18n ) {
		const wrap = el( 'div', 'denise-code' );
		const bar = el( 'div', 'denise-code-bar' );
		bar.appendChild( el( 'span', 'denise-code-lang', lang || '' ) );
		const copy = el( 'button', 'denise-code-copy' );
		copy.type = 'button';
		copy.appendChild( icon( 'copy', true ) );
		copy.appendChild( el( 'span', null, i18n.copy ) );
		copy.addEventListener( 'click', function () {
			const done = function () {
				copy.replaceChildren( icon( 'check', true ), el( 'span', null, i18n.copied ) );
				copy.classList.add( 'is-done' );
				setTimeout( function () {
					copy.replaceChildren( icon( 'copy', true ), el( 'span', null, i18n.copy ) );
					copy.classList.remove( 'is-done' );
				}, 1600 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( code ).then( done, function () {} );
			} else {
				const ta = document.createElement( 'textarea' );
				ta.value = code;
				document.body.appendChild( ta );
				ta.select();
				try {
					document.execCommand( 'copy' );
					done();
				} catch ( e ) {}
				ta.remove();
			}
		} );
		bar.appendChild( copy );
		const pre = el( 'pre' );
		pre.appendChild( el( 'code', null, code ) );
		wrap.appendChild( bar );
		wrap.appendChild( pre );
		return wrap;
	}

	// ---- Component ------------------------------------------------------

	window.deniseCreateChat = function ( root, opts ) {
		const cfg = window.deniseChat;
		if ( ! cfg || ! root ) {
			return null;
		}

		opts = opts || {};
		const i18n = Object.assign( {}, cfg.i18n || {}, opts.i18n || {} );
		const postId = parseInt( opts.postId, 10 ) || 0;
		const chatUrl = opts.chatUrl || cfg.chatUrl;
		const showHeader = false !== opts.header;

		// Extra request fields from the host (e.g. live editor state); never fatal.
		function extraContext() {
			if ( 'function' !== typeof opts.context ) {
				return {};
			}
			try {
				return opts.context() || {};
			} catch ( e ) {
				return {};
			}
		}

		let conversationId = 0;
		let busy = false;
		let disabled = !! opts.disabled;
		let stickToBottom = true;
		let historyOpen = false;

		// ---- Build DOM -------------------------------------------------
		root.replaceChildren();
		root.classList.add( 'denise-chat' );
		root.classList.toggle( 'denise-compact', !! opts.compact );
		root.classList.toggle( 'denise-headless', ! showHeader );

		// Header: identity on the left, actions on the right.
		const head = el( 'div', 'denise-head' );
		if ( showHeader ) {
			const id = el( 'div', 'denise-head-id' );
			id.appendChild( avatar( 'denise-avatar-lg' ) );
			const titles = el( 'div', 'denise-head-text' );
			titles.appendChild( el( 'div', 'denise-head-title', opts.title || i18n.name ) );
			if ( opts.subtitle ) {
				titles.appendChild( el( 'div', 'denise-head-sub', opts.subtitle ) );
			}
			id.appendChild( titles );
			head.appendChild( id );
		}

		const actions = el( 'div', 'denise-head-actions' );
		const historyBtn = iconButton( 'history', i18n.history );
		historyBtn.setAttribute( 'aria-haspopup', 'true' );
		historyBtn.setAttribute( 'aria-expanded', 'false' );
		const newBtn = iconButton( 'plus', i18n.newChat );
		const popover = el( 'div', 'denise-popover' );
		popover.hidden = true;
		popover.appendChild( el( 'div', 'denise-popover-title', i18n.history ) );
		const historyList = el( 'ul', 'denise-history-list' );
		popover.appendChild( historyList );
		actions.appendChild( historyBtn );
		actions.appendChild( newBtn );
		actions.appendChild( popover );
		// A host (e.g. the front-end panel's own header) can adopt the actions;
		// the chat then has no head of its own, which saves a row.
		const actionsHost = opts.actionsHost && opts.actionsHost.nodeType === 1 ? opts.actionsHost : null;
		if ( actionsHost ) {
			actionsHost.replaceChildren( actions );
		} else {
			head.appendChild( actions );
		}

		// Scrollable body: empty state, then the thread.
		const body = el( 'div', 'denise-body' );
		const empty = el( 'div', 'denise-empty' );
		empty.appendChild( avatar( 'denise-avatar-xl' ) );
		empty.appendChild( el( 'h2', 'denise-empty-title', opts.greeting || i18n.greeting ) );
		if ( opts.intro ) {
			empty.appendChild( el( 'p', 'denise-empty-intro', opts.intro ) );
		}
		if ( Array.isArray( opts.suggestions ) && opts.suggestions.length ) {
			const chips = el( 'div', 'denise-chips' );
			opts.suggestions.forEach( function ( text ) {
				const chip = el( 'button', 'denise-chip', text );
				chip.type = 'button';
				chip.addEventListener( 'click', function () {
					if ( disabled ) {
						return;
					}
					input.value = text;
					autosize();
					input.focus();
				} );
				chips.appendChild( chip );
			} );
			empty.appendChild( chips );
		}
		const thread = el( 'div', 'denise-thread' );
		thread.setAttribute( 'aria-live', 'polite' );
		body.appendChild( empty );
		body.appendChild( thread );

		// Composer.
		const composer = el( 'div', 'denise-composer' );
		const form = el( 'form', 'denise-form' );
		const input = el( 'textarea', 'denise-input' );
		input.rows = 1;
		input.placeholder = opts.placeholder || i18n.placeholder;
		input.setAttribute( 'aria-label', opts.placeholder || i18n.placeholder );
		const sendBtn = el( 'button', 'denise-send' );
		sendBtn.type = 'submit';
		sendBtn.setAttribute( 'aria-label', i18n.send );
		sendBtn.title = i18n.send;
		sendBtn.appendChild( icon( 'send', false ) );
		form.appendChild( input );
		form.appendChild( sendBtn );
		const hint = el( 'div', 'denise-hint', i18n.hint );
		composer.appendChild( form );
		composer.appendChild( hint );

		if ( ! actionsHost ) {
			root.appendChild( head );
		}
		root.appendChild( body );
		root.appendChild( composer );

		if ( ! cfg.hasKey && ! disabled ) {
			addNotice( i18n.noKey + ' ', settingsLink() );
			setDisabled( true, '' );
		} else {
			setDisabled( disabled, opts.disabledHint || '' );
		}

		// ---- Events ----------------------------------------------------
		newBtn.addEventListener( 'click', function () {
			conversationId = 0;
			thread.replaceChildren();
			empty.hidden = false;
			closeHistory();
			input.focus();
		} );

		historyBtn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			if ( historyOpen ) {
				closeHistory();
			} else {
				openHistory();
			}
		} );

		function onDocClick( e ) {
			if ( historyOpen && ! actions.contains( e.target ) ) {
				closeHistory();
			}
		}
		function onDocKey( e ) {
			if ( 'Escape' === e.key && historyOpen ) {
				closeHistory();
				historyBtn.focus();
			}
		}
		document.addEventListener( 'click', onDocClick );
		document.addEventListener( 'keydown', onDocKey );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( busy || disabled ) {
				return;
			}
			const text = input.value.trim();
			if ( ! text ) {
				return;
			}
			sendMessage( text );
		} );

		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key && ! e.shiftKey ) {
				e.preventDefault();
				form.requestSubmit();
			}
		} );
		input.addEventListener( 'input', autosize );

		body.addEventListener( 'scroll', function () {
			stickToBottom = body.scrollHeight - body.scrollTop - body.clientHeight < 80;
		} );

		if ( parseInt( opts.resume, 10 ) > 0 ) {
			loadConversation( parseInt( opts.resume, 10 ) );
		}

		// ---- History ---------------------------------------------------
		async function openHistory() {
			historyOpen = true;
			popover.hidden = false;
			historyBtn.setAttribute( 'aria-expanded', 'true' );
			historyList.replaceChildren( el( 'li', 'denise-history-empty', i18n.loading ) );
			await loadHistoryList();
		}

		function closeHistory() {
			historyOpen = false;
			popover.hidden = true;
			historyBtn.setAttribute( 'aria-expanded', 'false' );
		}

		async function loadHistoryList() {
			try {
				const params = new URLSearchParams();
				if ( postId ) {
					params.set( 'post_id', String( postId ) );
				}
				const qs = params.toString();
				const url = cfg.conversationsUrl + ( qs ? '?' + qs : '' );
				const res = await fetch( url, { headers: { 'X-WP-Nonce': cfg.nonce } } );
				const data = await res.json();
				const items = data.conversations || [];
				historyList.replaceChildren();
				if ( ! items.length ) {
					historyList.appendChild( el( 'li', 'denise-history-empty', i18n.noHistory ) );
					return;
				}
				items.forEach( function ( c ) {
					const li = el( 'li', 'denise-history-row' );
					const btn = el( 'button', 'denise-history-item' );
					btn.type = 'button';
					btn.appendChild( el( 'span', 'denise-history-item-title', c.title || '#' + c.id ) );
					const when = c.updated_at || c.created_at || c.date;
					if ( when ) {
						btn.appendChild( el( 'span', 'denise-history-item-date', formatDate( when ) ) );
					}
					if ( parseInt( c.id, 10 ) === conversationId ) {
						btn.classList.add( 'is-current' );
						btn.setAttribute( 'aria-current', 'true' );
					}
					btn.addEventListener( 'click', function () {
						closeHistory();
						loadConversation( parseInt( c.id, 10 ) );
					} );
					const del = iconButton( 'trash', i18n.delete, 'denise-history-delete' );
					del.addEventListener( 'click', function ( e ) {
						e.stopPropagation();
						deleteConversation( parseInt( c.id, 10 ), li );
					} );
					li.appendChild( btn );
					li.appendChild( del );
					historyList.appendChild( li );
				} );
			} catch ( err ) {
				historyList.replaceChildren( el( 'li', 'denise-history-empty', i18n.error + ' ' + err.message ) );
			}
		}

		async function deleteConversation( id, row ) {
			if ( ! id || ! window.confirm( i18n.deleteConfirm ) ) {
				return;
			}
			row.classList.add( 'is-deleting' );
			try {
				const res = await fetch( cfg.conversationsUrl + '/' + id, {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': cfg.nonce },
				} );
				if ( ! res.ok ) {
					const data = await res.json().catch( () => ( {} ) );
					throw new Error( data.message || 'HTTP ' + res.status );
				}
				row.remove();
				if ( ! historyList.children.length ) {
					historyList.appendChild( el( 'li', 'denise-history-empty', i18n.noHistory ) );
				}
				if ( id === conversationId ) {
					conversationId = 0;
					thread.replaceChildren();
					empty.hidden = false;
				}
			} catch ( err ) {
				row.classList.remove( 'is-deleting' );
				addNotice( i18n.error + ' ' + err.message );
			}
		}

		async function loadConversation( id ) {
			if ( ! id ) {
				return;
			}
			conversationId = id;
			thread.replaceChildren();
			empty.hidden = true;
			try {
				const res = await fetch( cfg.conversationsUrl + '/' + id, { headers: { 'X-WP-Nonce': cfg.nonce } } );
				const data = await res.json();
				( data.messages || [] ).forEach( function ( m ) {
					addMessage( m.role, m.text );
				} );
				( data.actions || [] ).forEach( addActionCard );
				stickToBottom = true;
				scroll();
			} catch ( err ) {
				addNotice( i18n.error + ' ' + err.message );
			}
		}

		// ---- Chat ------------------------------------------------------
		async function sendMessage( text ) {
			if ( opts.beforeSend ) {
				const warning = opts.beforeSend();
				if ( warning ) {
					addNotice( warning, null, 'info' );
				}
			}

			empty.hidden = true;
			addMessage( 'user', text );
			input.value = '';
			autosize();
			setBusy( true );
			stickToBottom = true;

			const status = addStatus( i18n.thinking );
			let reply = null;

			try {
				const res = await fetch( chatUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
					body: JSON.stringify( Object.assign( { conversation_id: conversationId, message: text, post_id: postId }, extraContext() ) ),
				} );

				if ( ! res.ok || ! res.body ) {
					const data = await res.json().catch( () => ( {} ) );
					throw new Error( data.message || 'HTTP ' + res.status );
				}

				const reader = res.body.getReader();
				const decoder = new TextDecoder();
				let buffer = '';

				for ( ;; ) {
					const { done, value } = await reader.read();
					if ( done ) {
						break;
					}
					buffer += decoder.decode( value, { stream: true } );
					const parts = buffer.split( '\n\n' );
					buffer = parts.pop();
					for ( const part of parts ) {
						const line = part.trim();
						if ( line.startsWith( 'data: ' ) ) {
							handleEvent( JSON.parse( line.slice( 6 ) ) );
						}
					}
				}
			} catch ( err ) {
				addNotice( i18n.error + ' ' + err.message );
			} finally {
				status.node.remove();
				setBusy( false );
				input.focus();
			}

			function handleEvent( ev ) {
				switch ( ev.type ) {
					case 'start':
						conversationId = ev.conversation_id;
						break;

					case 'text':
						if ( ! reply ) {
							reply = addMessage( 'assistant', '' );
						}
						reply.raw += ev.delta;
						renderMarkdown( reply.raw, reply.content, i18n );
						scroll();
						break;

					case 'tool_start':
						status.setLabel( ev.label || i18n.thinking );
						thread.appendChild( status.node );
						scroll();
						break;

					case 'tool_progress':
						status.setLabel( status.base + ' · ' + i18n.composing.replace( '%s', String( ev.chars ) ), true );
						break;

					case 'tool_done':
						status.setLabel( i18n.thinking );
						reply = null;
						break;

					case 'action':
						// Editor relays are applied by the host, no card per edit.
						if ( 'editor' !== ev.action ) {
							addActionCard( ev );
						}
						if ( opts.onAction ) {
							opts.onAction( ev );
						}
						break;

					case 'title':
						// Generated after the answer; the history popover reads it on open.
						break;

					case 'error':
						addNotice( i18n.error + ' ' + ev.message );
						break;
				}
			}
		}

		// ---- Rendering helpers ----------------------------------------
		function addMessage( role, text ) {
			const isUser = 'user' === role;
			const node = el( 'div', 'denise-msg ' + ( isUser ? 'denise-user' : 'denise-assistant' ) );
			if ( ! isUser ) {
				node.appendChild( avatar( 'denise-avatar-sm' ) );
			}
			const content = el( 'div', 'denise-msg-content' );
			if ( isUser ) {
				content.textContent = text;
			} else {
				renderMarkdown( text, content, i18n );
			}
			node.appendChild( content );
			thread.appendChild( node );
			scroll();
			return { node: node, content: content, raw: text };
		}

		function addStatus( label ) {
			const node = el( 'div', 'denise-msg denise-status' );
			node.appendChild( avatar( 'denise-avatar-sm' ) );
			const content = el( 'div', 'denise-msg-content' );
			const dots = el( 'span', 'denise-dots' );
			dots.appendChild( el( 'i' ) );
			dots.appendChild( el( 'i' ) );
			dots.appendChild( el( 'i' ) );
			const text = el( 'span', 'denise-status-label', label );
			content.appendChild( dots );
			content.appendChild( text );
			node.appendChild( content );
			thread.appendChild( node );
			scroll();
			const api = {
				node: node,
				base: label,
				setLabel: function ( value, keepBase ) {
					if ( ! keepBase ) {
						api.base = value;
					}
					text.textContent = value;
				},
			};
			return api;
		}

		function addNotice( text, extraNode, kind ) {
			const node = el( 'div', 'denise-msg denise-notice' + ( 'info' === kind ? ' denise-notice-info' : '' ) );
			node.setAttribute( 'role', 'info' === kind ? 'status' : 'alert' );
			node.appendChild( document.createTextNode( text ) );
			if ( extraNode ) {
				node.appendChild( extraNode );
			}
			empty.hidden = true;
			thread.appendChild( node );
			scroll();
			return node;
		}

		function settingsLink() {
			const a = el( 'a', null, i18n.openSettings );
			a.href = cfg.settings;
			return a;
		}

		function addActionCard( action ) {
			const card = el( 'div', 'denise-card' );
			const label = el( 'div', 'denise-card-label', 'created' === action.action ? i18n.created : ( action.pending_publish ? ( i18n.pendingDraft || i18n.updated ) : i18n.updated ) );
			const title = el( 'div', 'denise-card-title', action.title || '#' + action.post_id );
			card.appendChild( label );
			card.appendChild( title );
			const links = el( 'div', 'denise-card-links' );
			[ [ 'edit', i18n.editDraft, 'is-primary' ], [ 'preview', i18n.preview, '' ] ].forEach( function ( pair ) {
				if ( action[ pair[ 0 ] ] ) {
					const a = el( 'a', 'denise-card-link ' + pair[ 2 ], pair[ 1 ] );
					a.href = action[ pair[ 0 ] ];
					a.target = '_blank';
					a.rel = 'noopener';
					links.appendChild( a );
				}
			} );
			card.appendChild( links );
			empty.hidden = true;
			thread.appendChild( card );
			scroll();
		}

		function formatDate( value ) {
			const d = new Date( String( value ).replace( ' ', 'T' ) );
			if ( isNaN( d.getTime() ) ) {
				return String( value );
			}
			return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
		}

		function autosize() {
			input.style.height = 'auto';
			const max = opts.compact ? 120 : 180;
			input.style.height = Math.min( input.scrollHeight, max ) + 'px';
		}

		function setBusy( state ) {
			busy = state;
			root.classList.toggle( 'is-busy', state );
			sendBtn.disabled = state || disabled;
		}

		function setDisabled( state, hintText ) {
			disabled = state;
			root.classList.toggle( 'is-disabled', state );
			input.disabled = state;
			sendBtn.disabled = state || busy;
			hint.textContent = state && hintText ? hintText : ( state ? '' : i18n.hint );
			hint.classList.toggle( 'denise-hint-warn', state && !! hintText );
		}

		function scroll() {
			if ( stickToBottom ) {
				body.scrollTop = body.scrollHeight;
			}
		}

		return {
			focus: function () {
				input.focus();
			},
			setDisabled: setDisabled,
			destroy: function () {
				document.removeEventListener( 'click', onDocClick );
				document.removeEventListener( 'keydown', onDocKey );
				root.replaceChildren();
				root.classList.remove( 'denise-chat', 'denise-compact', 'denise-headless', 'is-busy', 'is-disabled' );
			},
		};
	};

	// Exposed for tests and other scripts.
	window.deniseRenderMarkdown = renderMarkdown;

	// Auto-boot: any element carrying a `data-denise-boot` JSON attribute.
	document.querySelectorAll( '[data-denise-boot]' ).forEach( function ( node ) {
		let opts = {};
		try {
			opts = JSON.parse( node.getAttribute( 'data-denise-boot' ) || '{}' );
		} catch ( e ) {}
		window.deniseCreateChat( node, opts );
	} );
} )();
