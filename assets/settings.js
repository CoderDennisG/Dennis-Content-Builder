/**
 * Settings page UI — built on WordPress's bundled @wordpress/components
 * (no build step; raw wp.element.createElement). Reads and writes the
 * dcb/v1/settings REST endpoint. Scope: settings page only.
 */
( function () {
	'use strict';

	const wp = window.wp;
	if ( ! wp || ! wp.element || ! wp.components ) {
		return;
	}

	const { createElement: e, createRoot, useState, useEffect, Fragment } = wp.element;
	const {
		Card,
		CardHeader,
		CardBody,
		TextControl,
		SelectControl,
		CheckboxControl,
		Button,
		Notice,
		Spinner,
		Flex,
	} = wp.components;
	const apiFetch = wp.apiFetch;
	const __ = wp.i18n.__;

	const noMargin = { __nextHasNoMarginBottom: true };

	function App() {
		const [ data, setData ] = useState( null );
		const [ apiKey, setApiKey ] = useState( '' );
		const [ model, setModel ] = useState( '' );
		const [ roles, setRoles ] = useState( [] );
		const [ saving, setSaving ] = useState( false );
		const [ testing, setTesting ] = useState( false );
		const [ notice, setNotice ] = useState( null );
		const [ testResult, setTestResult ] = useState( null );

		useEffect( function () {
			apiFetch( { path: '/dcb/v1/settings' } )
				.then( function ( payload ) {
					setData( payload );
					setModel( payload.model );
					setRoles( payload.roles || [] );
				} )
				.catch( function ( err ) {
					setNotice( { status: 'error', text: err.message } );
				} );
		}, [] );

		if ( ! data ) {
			return e( Spinner );
		}

		function save() {
			setSaving( true );
			setNotice( null );
			apiFetch( {
				path: '/dcb/v1/settings',
				method: 'POST',
				data: { model: model, api_key: apiKey, roles: roles },
			} )
				.then( function ( payload ) {
					setData( payload );
					setModel( payload.model );
					setRoles( payload.roles || [] );
					setApiKey( '' );
					setTestResult( null );
					setNotice( { status: 'success', text: __( 'Settings saved.', 'dennis-content-builder' ) } );
				} )
				.catch( function ( err ) {
					setNotice( { status: 'error', text: err.message } );
				} )
				.finally( function () {
					setSaving( false );
				} );
		}

		function test() {
			setTesting( true );
			setTestResult( null );
			apiFetch( { path: '/dcb/v1/test', method: 'POST' } )
				.then( function ( res ) {
					setTestResult( res );
				} )
				.catch( function ( err ) {
					setTestResult( { ok: false, message: err.message } );
				} )
				.finally( function () {
					setTesting( false );
				} );
		}

		const modelOptions = Object.keys( data.models ).map( function ( id ) {
			return { value: id, label: data.models[ id ] };
		} );

		const keyHelp = data.has_key
			? __( 'A key is saved. Leave blank to keep it, or paste a new one to replace it. The key never leaves the server.', 'dennis-content-builder' )
			: __( 'Paste your Anthropic API key. It is stored server-side and never sent to the browser.', 'dennis-content-builder' );

		return e(
			'div',
			{ className: 'dcb-settings-app' },

			notice &&
				e(
					Notice,
					{ status: notice.status, isDismissible: true, onRemove: function () {
						setNotice( null );
					} },
					notice.text
				),

			// --- API connection ---
			e(
				Card,
				null,
				e( CardHeader, null, e( 'h2', { className: 'dcb-card-title' }, __( 'API connection', 'dennis-content-builder' ) ) ),
				e(
					CardBody,
					null,
					e( TextControl, Object.assign( {}, noMargin, {
						type: 'password',
						autoComplete: 'off',
						label: __( 'Anthropic API key', 'dennis-content-builder' ),
						value: apiKey,
						placeholder: data.has_key ? data.key_hint : 'sk-ant-…',
						help: keyHelp,
						onChange: setApiKey,
					} ) ),
					e( 'div', { className: 'dcb-field' },
						e( SelectControl, Object.assign( {}, noMargin, {
							label: __( 'Model', 'dennis-content-builder' ),
							value: model,
							options: modelOptions,
							onChange: setModel,
						} ) )
					),
					e(
						Flex,
						{ justify: 'flex-start', gap: 3, className: 'dcb-field' },
						e(
							Button,
							{
								variant: 'secondary',
								onClick: test,
								isBusy: testing,
								disabled: testing || ! data.has_key,
							},
							__( 'Test connection', 'dennis-content-builder' )
						),
						testResult &&
							e(
								'span',
								{ className: testResult.ok ? 'dcb-test-ok' : 'dcb-test-fail' },
								testResult.message
							),
						! data.has_key &&
							e( 'span', { className: 'dcb-test-hint' }, __( 'Save a key first.', 'dennis-content-builder' ) )
					)
				)
			),

			// --- Access ---
			e(
				Card,
				{ className: 'dcb-card-spaced' },
				e( CardHeader, null, e( 'h2', { className: 'dcb-card-title' }, __( 'Who can use the chat', 'dennis-content-builder' ) ) ),
				e(
					CardBody,
					null,
					Object.keys( data.all_roles ).map( function ( slug ) {
						const isAdmin = slug === data.admin_role;
						return e( CheckboxControl, Object.assign( {}, noMargin, {
							key: slug,
							label: data.all_roles[ slug ] + ( isAdmin ? __( ' (always)', 'dennis-content-builder' ) : '' ),
							checked: isAdmin || roles.indexOf( slug ) !== -1,
							disabled: isAdmin,
							onChange: function ( checked ) {
								setRoles( function ( prev ) {
									if ( checked ) {
										return prev.indexOf( slug ) === -1 ? prev.concat( slug ) : prev;
									}
									return prev.filter( function ( r ) {
										return r !== slug;
									} );
								} );
							},
						} ) );
					} ),
					e(
						'p',
						{ className: 'dcb-muted' },
						__( 'Checked roles can open the Content Builder chat. Editing rights are still enforced per page on top of this.', 'dennis-content-builder' )
					)
				)
			),

			// --- Save ---
			e(
				'div',
				{ className: 'dcb-actions' },
				e(
					Button,
					{ variant: 'primary', onClick: save, isBusy: saving, disabled: saving },
					__( 'Save settings', 'dennis-content-builder' )
				)
			)
		);
	}

	const mount = document.getElementById( 'dcb-settings-root' );
	if ( mount ) {
		if ( createRoot ) {
			createRoot( mount ).render( e( App ) );
		} else if ( wp.element.render ) {
			wp.element.render( e( App ), mount );
		}
	}
} )();
