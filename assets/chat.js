/**
 * Content Builder chat — prototype UI.
 * History lives in this tab; the server is stateless (full message
 * history is sent with each request and returned updated).
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

	let history = []; // Claude message arrays, round-tripped via the server.
	let busy = false;

	input.placeholder = cfg.i18n.placeholder;
	sendBtn.textContent = cfg.i18n.send;

	if ( ! cfg.hasKey ) {
		addNotice( cfg.i18n.noKey + ' ', settingsLink() );
		input.disabled = true;
		sendBtn.disabled = true;
	}

	form.addEventListener( 'submit', async function ( e ) {
		e.preventDefault();
		if ( busy ) {
			return;
		}
		const text = input.value.trim();
		if ( ! text ) {
			return;
		}

		addBubble( 'user', text );
		history.push( { role: 'user', content: text } );
		input.value = '';
		setBusy( true );

		const spinner = addBubble( 'assistant dcb-thinking', cfg.i18n.thinking );

		try {
			const res = await fetch( cfg.restUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce,
				},
				body: JSON.stringify( { messages: history } ),
			} );

			const data = await res.json();
			spinner.remove();

			if ( ! res.ok ) {
				addNotice( cfg.i18n.error + ' ' + ( data.message || res.status ) );
				// Drop the failed turn so a retry starts clean.
				history.pop();
				return;
			}

			history = data.messages;

			if ( data.reply ) {
				addBubble( 'assistant', data.reply );
			}

			( data.actions || [] ).forEach( addActionCard );
		} catch ( err ) {
			spinner.remove();
			addNotice( cfg.i18n.error + ' ' + err.message );
			history.pop();
		} finally {
			setBusy( false );
			input.focus();
		}
	} );

	// Enter sends, Shift+Enter adds a newline.
	input.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' === e.key && ! e.shiftKey ) {
			e.preventDefault();
			form.requestSubmit();
		}
	} );

	function setBusy( state ) {
		busy = state;
		sendBtn.disabled = state;
		input.disabled = state;
	}

	function addBubble( role, text ) {
		const el = document.createElement( 'div' );
		el.className = 'dcb-msg ' + role.split( ' ' ).map( ( c ) => ( c.startsWith( 'dcb-' ) ? c : 'dcb-' + c ) ).join( ' ' );
		el.textContent = text;
		messagesEl.appendChild( el );
		messagesEl.scrollTop = messagesEl.scrollHeight;
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
		messagesEl.scrollTop = messagesEl.scrollHeight;
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

		if ( action.edit ) {
			const edit = document.createElement( 'a' );
			edit.href = action.edit;
			edit.target = '_blank';
			edit.className = 'button button-small';
			edit.textContent = cfg.i18n.editDraft;
			links.appendChild( edit );
		}

		if ( action.preview ) {
			const prev = document.createElement( 'a' );
			prev.href = action.preview;
			prev.target = '_blank';
			prev.className = 'button button-small';
			prev.textContent = cfg.i18n.preview;
			links.appendChild( prev );
		}

		card.appendChild( links );
		messagesEl.appendChild( card );
		messagesEl.scrollTop = messagesEl.scrollHeight;
	}
} )();
