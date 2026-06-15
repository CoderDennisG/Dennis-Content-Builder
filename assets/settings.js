/**
 * Settings page UI — WordPress's bundled @wordpress/components, no build
 * step. Two tabs: General (API + access) and Post Types (per-type
 * profiles). Reads/writes the dcb/v1/settings REST endpoint.
 */
( function () {
	'use strict';

	const wp = window.wp;
	if ( ! wp || ! wp.element || ! wp.components ) {
		return;
	}

	const { createElement: e, createRoot, useState, useEffect } = wp.element;
	const {
		Card,
		CardHeader,
		CardBody,
		TabPanel,
		TextControl,
		TextareaControl,
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
		const [ profiles, setProfiles ] = useState( {} );
		const [ allowedBlocks, setAllowedBlocks ] = useState( [] );
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
					setProfiles( payload.profiles || {} );
					setAllowedBlocks( payload.allowed_blocks || [] );
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
				data: { model: model, api_key: apiKey, roles: roles, profiles: profiles, allowed_blocks: allowedBlocks },
			} )
				.then( function ( payload ) {
					setData( payload );
					setModel( payload.model );
					setRoles( payload.roles || [] );
					setProfiles( payload.profiles || {} );
					setAllowedBlocks( payload.allowed_blocks || [] );
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

		function patchProfile( slug, patch ) {
			setProfiles( function ( prev ) {
				const base = prev[ slug ] || { enabled: false, instructions: '', allowed_blocks: [] };
				const next = Object.assign( {}, prev );
				next[ slug ] = Object.assign( {}, base, patch );
				return next;
			} );
		}

		// ---- General tab ----
		function generalTab() {
			const modelOptions = Object.keys( data.models ).map( function ( id ) {
				return { value: id, label: data.models[ id ] };
			} );

			const keyHelp = data.has_key
				? __( 'A key is saved. Leave blank to keep it, or paste a new one to replace it. The key never leaves the server.', 'dennis-content-builder' )
				: __( 'Paste your Anthropic API key. It is stored server-side and never sent to the browser.', 'dennis-content-builder' );

			return e(
				'div',
				null,
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
								{ variant: 'secondary', onClick: test, isBusy: testing, disabled: testing || ! data.has_key },
								__( 'Test connection', 'dennis-content-builder' )
							),
							testResult && e( 'span', { className: testResult.ok ? 'dcb-test-ok' : 'dcb-test-fail' }, testResult.message ),
							! data.has_key && e( 'span', { className: 'dcb-test-hint' }, __( 'Save a key first.', 'dennis-content-builder' ) )
						)
					)
				),
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
						e( 'p', { className: 'dcb-muted' }, __( 'Checked roles can open the Content Builder chat. Editing rights are still enforced per page on top of this.', 'dennis-content-builder' ) )
					)
				)
			);
		}

		// ---- Allowed Blocks tab (global) ----
		function blocksTab() {
			return e(
				'div',
				null,
				e( 'p', { className: 'dcb-muted dcb-intro' }, __( 'Choose which blocks the assistant may use across all content. Leave everything unchecked to allow every block.', 'dennis-content-builder' ) ),
				e(
					Card,
					null,
					e( CardBody, null,
						e( 'div', { className: 'dcb-block-grid' },
							Object.keys( data.block_catalog ).map( function ( block ) {
								return e( CheckboxControl, Object.assign( {}, noMargin, {
									key: block,
									label: data.block_catalog[ block ],
									checked: allowedBlocks.indexOf( block ) !== -1,
									onChange: function ( checked ) {
										setAllowedBlocks( function ( prev ) {
											return checked
												? prev.concat( block )
												: prev.filter( function ( b ) {
													return b !== block;
												} );
										} );
									},
								} ) );
							} )
						)
					)
				)
			);
		}

		// ---- Post Types tab ----
		function typeCard( pt ) {
			const profile = profiles[ pt.slug ] || { enabled: false, instructions: '', allowed_blocks: [] };

			const body = [
				e( CheckboxControl, Object.assign( {}, noMargin, {
					key: 'enabled',
					label: __( 'Let the assistant manage this type', 'dennis-content-builder' ),
					checked: !! profile.enabled,
					onChange: function ( checked ) {
						patchProfile( pt.slug, { enabled: checked } );
					},
				} ) ),
			];

			if ( profile.enabled ) {
				body.push(
					e( 'div', { key: 'instr', className: 'dcb-field' },
						e( TextareaControl, Object.assign( {}, noMargin, {
							label: __( 'Writing guidance', 'dennis-content-builder' ),
							help: __( 'How should the assistant write this type? Tone, structure, what to include. Optional.', 'dennis-content-builder' ),
							rows: 3,
							value: profile.instructions || '',
							onChange: function ( value ) {
								patchProfile( pt.slug, { instructions: value } );
							},
						} ) )
					)
				);

				if ( ! pt.block_based ) {
					body.push(
						e( 'p', { key: 'fieldnote', className: 'dcb-muted dcb-field' },
							__( 'This type stores custom fields. Field editing arrives in a later version; for now the assistant can still create and edit any block content it has.', 'dennis-content-builder' )
						)
					);
				}
			}

			return e(
				Card,
				{ key: pt.slug, className: 'dcb-card-spaced' },
				e( CardHeader, null,
					e( 'h2', { className: 'dcb-card-title' }, pt.label ),
					e( 'span', { className: 'dcb-pill' }, pt.block_based ? __( 'Blocks', 'dennis-content-builder' ) : __( 'Fields', 'dennis-content-builder' ) )
				),
				e( CardBody, null, body )
			);
		}

		function typesTab() {
			return e(
				'div',
				null,
				e( 'p', { className: 'dcb-muted dcb-intro' }, __( 'Give each content type its own voice and rules. Disabled types are hidden from the assistant entirely.', 'dennis-content-builder' ) ),
				( data.post_types || [] ).map( typeCard )
			);
		}

		return e(
			'div',
			{ className: 'dcb-settings-app' },
			notice && e( Notice, { status: notice.status, isDismissible: true, onRemove: function () {
				setNotice( null );
			} }, notice.text ),
			e(
				TabPanel,
				{
					className: 'dcb-tabs',
					tabs: [
						{ name: 'general', title: __( 'General', 'dennis-content-builder' ) },
						{ name: 'blocks', title: __( 'Allowed Blocks', 'dennis-content-builder' ) },
						{ name: 'types', title: __( 'Post Types', 'dennis-content-builder' ) },
					],
				},
				function ( tab ) {
					if ( 'blocks' === tab.name ) {
						return blocksTab();
					}
					if ( 'types' === tab.name ) {
						return typesTab();
					}
					return generalTab();
				}
			),
			e(
				'div',
				{ className: 'dcb-actions' },
				e( Button, { variant: 'primary', onClick: save, isBusy: saving, disabled: saving }, __( 'Save settings', 'dennis-content-builder' ) )
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
