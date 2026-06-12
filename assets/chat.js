/**
 * Content Builder chat — reusable factory.
 *
 * window.dcbCreateChat( rootEl, opts ) builds a complete chat UI inside
 * rootEl and returns { destroy }. Used by the admin page (auto-boot at
 * the bottom of this file) and by the Gutenberg sidebar (editor.js).
 *
 * opts:
 *   postId    {number}   scope the conversation to a post (0 = global)
 *   compact   {bool}     sidebar layout
 *   onAction  {function} called with each created/updated action
 *   beforeSend{function} returns a warning string to show, or null
 */
( function () {
	'use strict';

	window.dcbCreateChat = function ( root, opts ) {
		const cfg = window.dcbChat;
		if ( ! cfg || ! root ) {
			return null;
		}

		opts = opts || {};
		const postId = opts.postId || 0;

		let conversationId = 0;
		let busy = false;

		// ---- Build DOM -------------------------------------------------
		root.classList.add( 'dcb-chat' );
		if ( opts.compact ) {
			root.classList.add( 'dcb-compact' );
		}

		const toolbar = el( 'div', 'dcb-toolbar' );
		const newBtn = document.createElement( 'button' );
		newBtn.type = 'button';
		newBtn.className = 'button';
		newBtn.textContent = cfg.i18n.newChat;
		const historySel = document.createElement( 'select' );
		historySel.className = 'dcb-history';
		toolbar.appendChild( newBtn );
		toolbar.appendChild( historySel );

		const messagesEl = el( 'div', 'dcb-messages' );
		messagesEl.setAttribute( 'aria-live', 'polite' );

		const form = document.createElement( 'form' );
		form.className = 'dcb-form';
		const input = document.createElement( 'textarea' );
		input.rows = opts.compact ? 2 : 3;
		input.required = true;
		input.className = 'dcb-input';
		input.placeholder = cfg.i18n.placeholder;
		const sendBtn = document.createElement( 'button' );
		sendBtn.type = 'submit';
		sendBtn.className = 'button button-primary';
		sendBtn.textContent = cfg.i18n.send;
		form.appendChild( input );
		form.appendChild( sendBtn );

		root.appendChild( toolbar );
		root.appendChild( messagesEl );
		root.appendChild( form );

		if ( ! cfg.hasKey ) {
			addNotice( cfg.i18n.noKey + ' ', settingsLink() );
			input.disabled = true;
			sendBtn.disabled = true;
		}

		loadHistoryList();

		// ---- Events ----------------------------------------------------
		newBtn.addEventListener( 'click', function () {
			conversationId = 0;
			historySel.value = '0';
			messagesEl.innerHTML = '';
			input.focus();
		} );

		historySel.addEventListener( 'change', async function () {
			const id = parseInt( historySel.value, 10 ) || 0;
			if ( ! id ) {
				return;
			}
			conversationId = id;
			messagesEl.innerHTML = '';
			try {
				const res = await fetch( cfg.conversationsUrl + '/' + id, {
					headers: { 'X-WP-Nonce': cfg.nonce },
				} );
				const data = await res.json();
				( data.messages || [] ).forEach( function ( m ) {
					addBubble( m.role, m.text );
				} );
				( data.actions || [] ).forEach( addActionCard );
			} catch ( err ) {
				addNotice( cfg.i18n.error + ' ' + err.message );
			}
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( busy ) {
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

		// ---- Chat ------------------------------------------------------
		async function sendMessage( text ) {
			if ( opts.beforeSend ) {
				const warning = opts.beforeSend();
				if ( warning ) {
					addNotice( warning );
				}
			}

			addBubble( 'user', text );
			input.value = '';
			setBusy( true );

			const status = addBubble( 'assistant dcb-status', cfg.i18n.thinking );
			let replyEl = null;

			try {
				const res = await fetch( cfg.chatUrl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': cfg.nonce,
					},
					body: JSON.stringify( {
						conversation_id: conversationId,
						message: text,
						post_id: postId,
					} ),
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
				addNotice( cfg.i18n.error + ' ' + err.message );
			} finally {
				status.remove();
				setBusy( false );
				input.focus();
			}

			function handleEvent( ev ) {
				switch ( ev.type ) {
					case 'start':
						conversationId = ev.conversation_id;
						break;

					case 'text':
						if ( ! replyEl ) {
							replyEl = addBubble( 'assistant', '' );
						}
						replyEl.textContent += ev.delta;
						scroll();
						break;

					case 'tool_start':
						status.textContent = ev.label || cfg.i18n.thinking;
						messagesEl.appendChild( status );
						scroll();
						break;

					case 'tool_progress':
						status.textContent =
							status.textContent.split( '—' )[ 0 ].trim() +
							' — ' +
							cfg.i18n.composing.replace( '%s', String( ev.chars ) );
						break;

					case 'tool_done':
						status.textContent = cfg.i18n.thinking;
						replyEl = null;
						break;

					case 'action':
						addActionCard( ev );
						if ( opts.onAction ) {
							opts.onAction( ev );
						}
						break;

					case 'done':
						loadHistoryList();
						break;

					case 'error':
						addNotice( cfg.i18n.error + ' ' + ev.message );
						break;
				}
			}
		}

		async function loadHistoryList() {
			try {
				const url = postId
					? cfg.conversationsUrl + '?post_id=' + postId
					: cfg.conversationsUrl;
				const res = await fetch( url, {
					headers: { 'X-WP-Nonce': cfg.nonce },
				} );
				const data = await res.json();

				historySel.innerHTML = '';
				const blank = document.createElement( 'option' );
				blank.value = '0';
				blank.textContent = cfg.i18n.history;
				historySel.appendChild( blank );

				( data.conversations || [] ).forEach( function ( c ) {
					const opt = document.createElement( 'option' );
					opt.value = c.id;
					opt.textContent = c.title;
					if ( parseInt( c.id, 10 ) === conversationId ) {
						opt.selected = true;
					}
					historySel.appendChild( opt );
				} );
			} catch ( err ) {
				// History list is non-critical; fail silently.
			}
		}

		// ---- Helpers ---------------------------------------------------
		function el( tag, className ) {
			const node = document.createElement( tag );
			node.className = className;
			return node;
		}

		function setBusy( state ) {
			busy = state;
			sendBtn.disabled = state;
			input.disabled = state;
		}

		function addBubble( role, text ) {
			const node = document.createElement( 'div' );
			node.className =
				'dcb-msg ' +
				role
					.split( ' ' )
					.map( ( c ) => ( c.startsWith( 'dcb-' ) ? c : 'dcb-' + c ) )
					.join( ' ' );
			node.textContent = text;
			messagesEl.appendChild( node );
			scroll();
			return node;
		}

		function addNotice( text, extraNode ) {
			const node = document.createElement( 'div' );
			node.className = 'dcb-msg dcb-notice';
			node.textContent = text;
			if ( extraNode ) {
				node.appendChild( extraNode );
			}
			messagesEl.appendChild( node );
			scroll();
			return node;
		}

		function settingsLink() {
			const a = document.createElement( 'a' );
			a.href = cfg.settings;
			a.textContent = 'Settings →';
			return a;
		}

		function addActionCard( action ) {
			const card = document.createElement( 'div' );
			card.className = 'dcb-card';

			const label = document.createElement( 'strong' );
			label.textContent =
				( 'created' === action.action ? cfg.i18n.created : cfg.i18n.updated ) +
				': ' +
				( action.title || '#' + action.post_id );
			card.appendChild( label );

			const links = document.createElement( 'div' );
			links.className = 'dcb-card-links';

			[ [ 'edit', cfg.i18n.editDraft ], [ 'preview', cfg.i18n.preview ] ].forEach( function ( pair ) {
				if ( action[ pair[ 0 ] ] ) {
					const a = document.createElement( 'a' );
					a.href = action[ pair[ 0 ] ];
					a.target = '_blank';
					a.className = 'button button-small';
					a.textContent = pair[ 1 ];
					links.appendChild( a );
				}
			} );

			card.appendChild( links );
			messagesEl.appendChild( card );
			scroll();
		}

		function scroll() {
			messagesEl.scrollTop = messagesEl.scrollHeight;
		}

		return {
			destroy: function () {
				root.innerHTML = '';
				root.classList.remove( 'dcb-chat', 'dcb-compact' );
			},
		};
	};

	// Auto-boot on the admin page.
	const adminRoot = document.getElementById( 'dcb-chat-app' );
	if ( adminRoot ) {
		window.dcbCreateChat( adminRoot, {} );
	}
} )();
