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
		Modal,
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
		const [ editing, setEditing ] = useState( null );
		const [ running, setRunning ] = useState( false );
		const [ runResult, setRunResult ] = useState( null );
		const [ fieldDefs, setFieldDefs ] = useState( {} );

		// Lazy-load the discovered field list when a type's popup opens.
		useEffect( function () {
			if ( ! editing || fieldDefs[ editing ] !== undefined ) {
				return;
			}
			apiFetch( { path: '/dcb/v1/fields?post_type=' + encodeURIComponent( editing ) } )
				.then( function ( res ) {
					setFieldDefs( function ( prev ) {
						const next = Object.assign( {}, prev );
						next[ editing ] = res.fields || [];
						return next;
					} );
				} )
				.catch( function () {
					setFieldDefs( function ( prev ) {
						const next = Object.assign( {}, prev );
						next[ editing ] = [];
						return next;
					} );
				} );
		}, [ editing ] );

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
				const base = prev[ slug ] || { enabled: false, instructions: '', schedule: defaultSchedule() };
				const next = Object.assign( {}, prev );
				next[ slug ] = Object.assign( {}, base, patch );
				return next;
			} );
		}

		function defaultSchedule() {
			return { enabled: false, days: [], time: '09:00', auto_publish: false, brief: '' };
		}

		function patchSchedule( slug, patch ) {
			const base = profiles[ slug ] || { enabled: false, instructions: '', schedule: defaultSchedule() };
			const schedule = Object.assign( {}, defaultSchedule(), base.schedule || {}, patch );
			patchProfile( slug, { schedule: schedule } );
		}

		function runNow( slug ) {
			setRunning( true );
			setRunResult( null );
			apiFetch( { path: '/dcb/v1/run-schedule', method: 'POST', data: { post_type: slug } } )
				.then( function ( res ) {
					setRunResult( { ok: true, edit: res.edit, published: res.published } );
				} )
				.catch( function ( err ) {
					setRunResult( { ok: false, message: err.message } );
				} )
				.finally( function () {
					setRunning( false );
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
		function typeRow( pt ) {
			const profile = profiles[ pt.slug ] || { enabled: false, instructions: '' };
			const hasGuidance = !! ( profile.instructions && profile.instructions.trim() );

			return e(
				'div',
				{ key: pt.slug, className: 'dcb-type-row' },
				e( 'div', { className: 'dcb-type-main' },
					e( CheckboxControl, Object.assign( {}, noMargin, {
						label: pt.label,
						checked: !! profile.enabled,
						onChange: function ( checked ) {
							patchProfile( pt.slug, { enabled: checked } );
						},
					} ) ),
					e( 'span', { className: 'dcb-pill' }, pt.block_based ? __( 'Blocks', 'dennis-content-builder' ) : __( 'Fields', 'dennis-content-builder' ) )
				),
				e( Button, {
					className: 'dcb-guidance-btn' + ( hasGuidance ? ' dcb-has-guidance' : '' ),
					icon: 'edit',
					label: hasGuidance
						? __( 'Edit writing guidance (set)', 'dennis-content-builder' )
						: __( 'Add writing guidance', 'dennis-content-builder' ),
					showTooltip: true,
					onClick: function () {
						setEditing( pt.slug );
					},
				} )
			);
		}

		function typesTab() {
			return e(
				'div',
				null,
				e( 'p', { className: 'dcb-muted dcb-intro' }, __( 'Check the types the assistant may manage. Use the pencil to set how it should write each one.', 'dennis-content-builder' ) ),
				e( Card, null, e( CardBody, { className: 'dcb-type-list' }, ( data.post_types || [] ).map( typeRow ) ) )
			);
		}

		function closeModal() {
			setEditing( null );
			setRunResult( null );
		}

		function toggleField( slug, name, on ) {
			const cur = ( profiles[ slug ] && profiles[ slug ].fields ) || [];
			const next = on
				? ( cur.indexOf( name ) === -1 ? cur.concat( name ) : cur )
				: cur.filter( function ( x ) {
					return x !== name;
				} );
			patchProfile( slug, { fields: next } );
		}

		function fieldsSection( pt, profile ) {
			const defs = fieldDefs[ pt.slug ];
			if ( defs === undefined ) {
				return e( Spinner );
			}
			if ( ! defs.length ) {
				return e( 'p', { className: 'dcb-muted' }, __( 'No custom fields found for this type.', 'dennis-content-builder' ) );
			}

			const allowed = ( profile.fields ) || [];

			return e( 'div', { className: 'dcb-field-list' },
				defs.map( function ( f, idx ) {
					const ro = ! f.writable;
					if ( f.depth > 0 ) {
						// Nested sub-field: shown for context, governed by its parent.
						return e( 'div', {
							key: idx,
							className: 'dcb-field-sub',
							style: { marginLeft: ( f.depth * 16 ) + 'px' },
						}, '↳ ' + f.label + ' ' + typeTag( f.type, ro ) );
					}
					return e( 'div', { key: idx, className: 'dcb-field-top' },
						e( CheckboxControl, Object.assign( {}, noMargin, {
							label: f.label,
							checked: allowed.indexOf( f.name ) !== -1,
							onChange: function ( checked ) {
								toggleField( pt.slug, f.name, checked );
							},
						} ) ),
						e( 'span', { className: 'dcb-field-tag' }, typeTag( f.type, ro ) )
					);
				} )
			);
		}

		function typeTag( type, readOnly ) {
			return readOnly ? type + ' · read-only' : type;
		}

		function fmt( ts ) {
			return ts ? new Date( ts * 1000 ).toLocaleString() : '—';
		}

		function guidanceModal() {
			const pt = ( data.post_types || [] ).find( function ( p ) {
				return p.slug === editing;
			} );
			if ( ! pt ) {
				return null;
			}
			const profile = profiles[ editing ] || { instructions: '', schedule: defaultSchedule() };
			const schedule = Object.assign( {}, defaultSchedule(), profile.schedule || {} );
			const dayLabels = { mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat', sun: 'Sun' };
			const state = ( data.schedule_state || {} )[ editing ] || {};

			return e(
				Modal,
				{ title: pt.label, onRequestClose: closeModal, className: 'dcb-guidance-modal' },

				// Writing guidance
				e( TextareaControl, Object.assign( {}, noMargin, {
					label: __( 'Writing guidance', 'dennis-content-builder' ),
					help: __( 'How should the assistant write this type? Tone, structure, what to include. Optional. Remember to Save settings after closing.', 'dennis-content-builder' ),
					rows: 6,
					value: profile.instructions || '',
					onChange: function ( value ) {
						patchProfile( editing, { instructions: value } );
					},
				} ) ),
				e( 'hr', { className: 'dcb-sep' } ),

				// Custom fields
				e( 'p', { className: 'dcb-subhead' }, __( 'Custom fields', 'dennis-content-builder' ) ),
				e( 'p', { className: 'dcb-muted' }, __( 'Tick the fields the assistant may read. Text, number, choice, true/false and date fields can also be written; complex fields (repeater, group, flexible content, image, relationship) are read-only for now.', 'dennis-content-builder' ) ),
				fieldsSection( pt, profile ),

				e( 'hr', { className: 'dcb-sep' } ),

				// Automatic creation
				e( 'p', { className: 'dcb-subhead' }, __( 'Automatic creation', 'dennis-content-builder' ) ),
				e( CheckboxControl, Object.assign( {}, noMargin, {
					label: __( 'Create new content automatically on a schedule', 'dennis-content-builder' ),
					checked: !! schedule.enabled,
					onChange: function ( checked ) {
						patchSchedule( editing, { enabled: checked } );
					},
				} ) ),

				schedule.enabled && e( 'div', { className: 'dcb-sched' },
					e( 'p', { className: 'dcb-sched-label' }, __( 'Days', 'dennis-content-builder' ) ),
					e( 'div', { className: 'dcb-day-row' },
						( data.weekdays || [] ).map( function ( d ) {
							const on = schedule.days.indexOf( d ) !== -1;
							return e( Button, {
								key: d,
								variant: on ? 'primary' : 'secondary',
								className: 'dcb-day',
								onClick: function () {
									patchSchedule( editing, {
										days: on ? schedule.days.filter( function ( x ) {
											return x !== d;
										} ) : schedule.days.concat( d ),
									} );
								},
							}, dayLabels[ d ] || d );
						} )
					),
					e( 'div', { className: 'dcb-field' },
						e( TextControl, Object.assign( {}, noMargin, {
							type: 'time',
							label: __( 'Time', 'dennis-content-builder' ),
							value: schedule.time,
							onChange: function ( v ) {
								patchSchedule( editing, { time: v } );
							},
						} ) ),
						e( 'p', { className: 'dcb-muted' },
							/* translators: %s: site timezone */
							( __( 'Site timezone: %s', 'dennis-content-builder' ) ).replace( '%s', data.timezone || 'UTC' )
						)
					),
					e( 'div', { className: 'dcb-field' },
						e( TextareaControl, Object.assign( {}, noMargin, {
							label: __( 'What should each run create?', 'dennis-content-builder' ),
							help: __( 'A standing brief used every run, e.g. "Write a fresh beginner WordPress tip with a short intro and 3 steps."', 'dennis-content-builder' ),
							rows: 3,
							value: schedule.brief,
							onChange: function ( v ) {
								patchSchedule( editing, { brief: v } );
							},
						} ) )
					),
					e( CheckboxControl, Object.assign( {}, noMargin, {
						className: 'dcb-field',
						label: __( 'Publish automatically (skip review)', 'dennis-content-builder' ),
						checked: !! schedule.auto_publish,
						onChange: function ( checked ) {
							patchSchedule( editing, { auto_publish: checked } );
						},
					} ) ),
					schedule.auto_publish && e( 'p', { className: 'dcb-warn' },
						__( '⚠ Generated content goes live with no human review. Leave off to create drafts you approve first.', 'dennis-content-builder' )
					),
					e( 'p', { className: 'dcb-muted' },
						__( 'Last run: ', 'dennis-content-builder' ) + fmt( state.last_run ) +
						__( ' · Next: ', 'dennis-content-builder' ) + fmt( state.next_run ) +
						__( ' (updates after Save)', 'dennis-content-builder' )
					)
				),

				e( 'hr', { className: 'dcb-sep' } ),
				e( 'p', { className: 'dcb-muted' }, __( 'Run now uses your last saved settings — Save first if you just made changes.', 'dennis-content-builder' ) ),
				e( 'div', { className: 'dcb-actions' },
					e( Button, {
						variant: 'secondary',
						onClick: function () {
							runNow( editing );
						},
						isBusy: running,
						disabled: running,
					}, __( 'Run now', 'dennis-content-builder' ) ),
					runResult && runResult.ok && e( 'a', { href: runResult.edit, target: '_blank', rel: 'noreferrer' },
						runResult.published ? __( 'Published — open', 'dennis-content-builder' ) : __( 'Draft created — open', 'dennis-content-builder' )
					),
					runResult && ! runResult.ok && e( 'span', { className: 'dcb-test-fail' }, runResult.message ),
					e( Button, { variant: 'primary', onClick: closeModal }, __( 'Done', 'dennis-content-builder' ) )
				)
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
			),
			editing && guidanceModal()
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
