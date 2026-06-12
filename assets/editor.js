/**
 * Gutenberg sidebar: the same chat, scoped to the open post.
 * No build step — raw wp.element.createElement instead of JSX.
 *
 * When the AI updates the open post, the editor canvas is refreshed
 * in place (resetEditorBlocks), so changes appear without a reload.
 */
( function () {
	'use strict';

	if ( ! window.wp || ! wp.plugins || ! window.dcbChat || ! window.dcbCreateChat ) {
		return;
	}

	// PluginSidebar lives in wp.editor since WP 6.6; fall back for safety.
	const host = wp.editor && wp.editor.PluginSidebar ? wp.editor : wp.editPost || {};
	const PluginSidebar = host.PluginSidebar;
	const PluginSidebarMoreMenuItem = host.PluginSidebarMoreMenuItem;

	if ( ! PluginSidebar ) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const TITLE = __( 'Content Builder', 'dennis-content-builder' );

	function refreshEditor( postId ) {
		const postType = wp.data.select( 'core/editor' ).getCurrentPostType();
		const typeObj = wp.data.select( 'core' ).getPostType( postType );
		const restBase = typeObj ? typeObj.rest_base : ( 'page' === postType ? 'pages' : 'posts' );

		wp.apiFetch( { path: '/wp/v2/' + restBase + '/' + postId + '?context=edit' } ).then( function ( post ) {
			wp.data
				.dispatch( 'core/editor' )
				.resetEditorBlocks( wp.blocks.parse( post.content.raw ) );

			if ( post.title && post.title.raw ) {
				wp.data.dispatch( 'core/editor' ).editPost( { title: post.title.raw } );
			}

			if ( wp.data.dispatch( 'core/notices' ) ) {
				wp.data.dispatch( 'core/notices' ).createInfoNotice(
					__( 'The assistant updated this page. Review and save.', 'dennis-content-builder' ),
					{ type: 'snackbar' }
				);
			}
		} );
	}

	function ChatMount() {
		const ref = wp.element.useRef( null );

		wp.element.useEffect( function () {
			const postId = wp.data.select( 'core/editor' ).getCurrentPostId();

			const api = window.dcbCreateChat( ref.current, {
				postId: postId,
				compact: true,
				onAction: function ( action ) {
					if ( action.post_id === postId && 'updated' === action.action ) {
						refreshEditor( postId );
					}
				},
				beforeSend: function () {
					return wp.data.select( 'core/editor' ).isEditedPostDirty()
						? __( 'Heads up: you have unsaved changes. The assistant works from the last saved version — save first if your edits matter.', 'dennis-content-builder' )
						: null;
				},
			} );

			return function () {
				if ( api ) {
					api.destroy();
				}
			};
		}, [] );

		return el( 'div', { ref: ref, className: 'dcb-sidebar-root' } );
	}

	wp.plugins.registerPlugin( 'dcb-assistant', {
		render: function () {
			return el(
				wp.element.Fragment,
				null,
				PluginSidebarMoreMenuItem
					? el( PluginSidebarMoreMenuItem, { target: 'dcb-sidebar', icon: 'format-chat' }, TITLE )
					: null,
				el(
					PluginSidebar,
					{ name: 'dcb-sidebar', title: TITLE, icon: 'format-chat' },
					el( ChatMount )
				)
			);
		},
	} );
} )();
