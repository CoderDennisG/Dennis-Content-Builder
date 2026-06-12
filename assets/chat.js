/**
 * Content Builder chat — v0.2.0.
 * Conversations persist server-side; /chat answers as an SSE stream
 * (text deltas + tool progress) read via fetch ReadableStream.
 */
( function () {
	'use strict';

	const cfg = window.dcbChat;
	if ( ! cfg ) {
		return;
	}

	const messagesEl = document.getElementById( 'dcb-messages' );
	const form = document.getElementById( 'dcb-form' );
	const input = document.getElementById( 'dcb-input' );
	const sendBtn = document.getElementById( 'dcb-send' );
	const newBtn = document.getElementById( 'dcb-new-chat' );
	const historySel = document.getElementById( 'dcb-history' );

	let conversationId = 0;
	let busy = false;

	input.placeholder = cfg.i18n.placeholder;
	sendBtn.textContent = cfg.i18n.send;
	newBtn.textContent = cfg.i18n.newChat;

	if ( ! cfg.hasKey ) {
		addNotice( cfg.i18n.noKey + ' ', settingsLink() );
		input.disabled = true;
		sendBtn.disabled = true;
	}

	loadHistoryList();

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

	async function sendMessage( text ) {
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
				buffer = parts.pop(); // Last part may be incomplete.

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
					moveToEnd( status );
					break;

				case 'tool_progress':
					status.textContent =
						( status.textContent.split( '—' )[ 0 ].trim() ) +
						' — ' +
						cfg.i18n.composing.replace( '%s', String( ev.chars ) );
					break;

				case 'tool_done':
					status.textContent = cfg.i18n.thinking;
					// The next assistant text belongs in a fresh bubble.
					replyEl = null;
					break;

				case 'action':
					addActionCard( ev );
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
			const res = await fetch( cfg.conversationsUrl, {
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

	function setBusy( state ) {
		busy = state;
		sendBtn.disabled = state;
		input.disabled = state;
	}

	function addBubble( role, text ) {
		const el = document.createElement( 'div' );
		el.className =
			'dcb-msg ' +
			role
				.split( ' ' )
				.map( ( c ) => ( c.startsWith( 'dcb-' ) ? c : 'dcb-' + c ) )
				.join( ' ' );
		el.textContent = text;
		messagesEl.appendChild( el );
		scroll();
		return el;
	}

	function addNotice( text, extraNode ) {
		const el = document.createElement( 'div' );
		el.className = 'dcb-msg dcb-notice';
		el.textContent = text;
		if ( extraNode ) {
			el.appendChild( extraNode );
		}
		messagesEl.appendChild( el );
		scroll();
		return el;
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

	function moveToEnd( el ) {
		messagesEl.appendChild( el );
		scroll();
	}

	function scroll() {
		messagesEl.scrollTop = messagesEl.scrollHeight;
	}
} )();
