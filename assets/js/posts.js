/**
 * Listemann Posts widget: category filter and "load more" without page loads.
 *
 * The server renders a working page without JavaScript: the filter is a GET
 * form (`?lst_cat[]=…`) and "load more" a link to the next page
 * (`?lst_page=2`). This script replaces both with requests to the REST route
 * `listemann-toolkit/v1/posts`, which renders the cards from the widget
 * settings saved in the page. It announces the results in the widget's
 * status region, moves the focus to the first new card after "load more"
 * and keeps the filter in the URL (history.replaceState).
 *
 * Works without Elementor's frontend script: every `.lst-posts` is set up on
 * DOMContentLoaded. When Elementor's frontend runs (front end and editor
 * preview), a handler for `listemann-posts` additionally destroys the
 * instance when the editor re-renders or deletes the widget. Setup is
 * idempotent (one instance per root element).
 *
 * Public API: window.ListemannPosts.init( root ), .destroy( root ),
 * .getInstance( root ), .initAll( scope ).
 */
( function () {
	'use strict';

	const ROOT_SELECTOR = '.lst-posts';
	const ARG_PAGE = 'lst_page';
	const ARG_CATS = 'lst_cat[]';
	const ARG_ELEMENT = 'lst_posts';
	const FILTER_DELAY = 250;

	const instances = new Set();
	const byRoot = new WeakMap();

	function parseConfig( root ) {
		try {
			return JSON.parse( root.getAttribute( 'data-lst-posts' ) || '{}' ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function isEditor() {
		const frontend = window.elementorFrontend;

		return !! ( frontend && 'function' === typeof frontend.isEditMode && frontend.isEditMode() );
	}

	/** Error carrying the HTTP status and the REST error code. */
	class RequestError extends Error {
		constructor( message, status, code ) {
			super( message );
			this.status = status || 0;
			this.code = code || '';
		}
	}

	class Posts {
		constructor( root ) {
			this.root = root;
			this.config = parseConfig( root );
			this.grid = root.querySelector( '.lst-posts__grid' );
			this.status = root.querySelector( '.lst-posts__status' );
			this.form = root.querySelector( '.lst-posts__filter' );
			this.empty = root.querySelector( '.lst-posts__empty' );
			this.page = parseInt( this.config.page, 10 ) || 1;
			this.maxPages = parseInt( this.config.maxPages, 10 ) || 0;
			this.listeners = [];
			this.controller = null;
			this.timer = 0;
			this.announceTimer = 0;
			this.busy = false;
			this.destroyed = false;
			this.nonce = this.config.nonce || '';

			this.on( root, 'click', ( event ) => this.onClick( event ) );
			this.on( root, 'keydown', ( event ) => this.onKeydown( event ) );

			if ( this.form ) {
				this.on( this.form, 'change', ( event ) => this.onChange( event ) );
				this.on( this.form, 'submit', ( event ) => this.onSubmit( event ) );
			}

			this.enhanceMore();
			root.classList.add( 'is-enhanced' );
		}

		on( target, type, handler, options ) {
			target.addEventListener( type, handler, options );
			this.listeners.push( [ target, type, handler, options ] );
		}

		/* State ------------------------------------------------------------ */

		getDocumentId() {
			const wrapper = this.root.closest( '[data-elementor-id]' );
			const id = wrapper ? parseInt( wrapper.getAttribute( 'data-elementor-id' ), 10 ) : 0;

			return id || parseInt( this.config.document, 10 ) || 0;
		}

		getActiveCats() {
			if ( ! this.form ) {
				return [];
			}

			return Array.from( this.form.querySelectorAll( '.lst-posts__check:checked' ) )
				.map( ( input ) => parseInt( input.value, 10 ) )
				.filter( ( id ) => id > 0 );
		}

		getMoreLink() {
			return this.root.querySelector( '.lst-posts__more' );
		}

		/** The "load more" link acts as a button once the script handles it. */
		enhanceMore() {
			const link = this.getMoreLink();

			if ( link && 'current' !== this.config.source ) {
				link.setAttribute( 'role', 'button' );
			}
		}

		/* Events ----------------------------------------------------------- */

		onClick( event ) {
			const link = event.target.closest && event.target.closest( '.lst-posts__more' );

			if ( ! link || ! this.root.contains( link ) ) {
				return;
			}

			event.preventDefault();
			this.loadMore( link );
		}

		onKeydown( event ) {
			const link = event.target.closest && event.target.closest( '.lst-posts__more' );

			// Links react to Enter only; as role="button" it also takes Space.
			if ( link && ( ' ' === event.key || 'Spacebar' === event.key ) ) {
				event.preventDefault();
				this.loadMore( link );
			}
		}

		onChange( event ) {
			if ( ! event.target.classList || ! event.target.classList.contains( 'lst-posts__check' ) ) {
				return;
			}

			window.clearTimeout( this.timer );
			this.timer = window.setTimeout( () => this.applyFilter(), FILTER_DELAY );
		}

		onSubmit( event ) {
			event.preventDefault();
			window.clearTimeout( this.timer );
			this.applyFilter();
		}

		/* Requests --------------------------------------------------------- */

		buildUrl( page, cats ) {
			const url = new URL( this.config.rest, window.location.href );
			const base = new URL( window.location.href );

			url.searchParams.set( 'document', String( this.getDocumentId() ) );
			url.searchParams.set( 'element', String( this.config.element || '' ) );
			url.searchParams.set( 'page', String( page ) );
			cats.forEach( ( id ) => url.searchParams.append( 'cats[]', String( id ) ) );

			if ( this.config.exclude ) {
				url.searchParams.set( 'exclude', String( this.config.exclude ) );
			}

			if ( this.config.seed ) {
				url.searchParams.set( 'seed', String( this.config.seed ) );
			}

			base.hash = '';
			url.searchParams.set( 'base', base.toString() );

			return url.toString();
		}

		request( page, cats ) {
			if ( this.controller ) {
				this.controller.abort();
			}

			const controller = 'function' === typeof window.AbortController ? new window.AbortController() : null;
			const url = this.buildUrl( page, cats );

			this.controller = controller;

			const send = ( withNonce ) => {
				const headers = { Accept: 'application/json' };

				if ( withNonce && this.nonce ) {
					headers[ 'X-WP-Nonce' ] = this.nonce;
				}

				return window.fetch( url, {
					credentials: 'same-origin',
					headers,
					signal: controller ? controller.signal : undefined,
				} ).then( ( response ) => response.json().catch( () => ( {} ) ).then( ( data ) => {
					if ( ! response.ok ) {
						throw new RequestError( ( data && data.message ) || response.statusText, response.status, data && data.code );
					}

					return data;
				} ) );
			};

			return send( true ).catch( ( error ) => {
				// An expired nonce (e.g. a page cached while logged in): retry as a visitor.
				if ( error instanceof RequestError && 'rest_cookie_invalid_nonce' === error.code ) {
					this.nonce = '';
					return send( false );
				}

				throw error;
			} ).finally( () => {
				if ( this.controller === controller ) {
					this.controller = null;
				}
			} );
		}

		setBusy( busy ) {
			this.busy = busy;

			if ( this.grid ) {
				if ( busy ) {
					this.grid.setAttribute( 'aria-busy', 'true' );
				} else {
					this.grid.removeAttribute( 'aria-busy' );
				}
			}
		}

		/** Handles a failed request; returns true when the page navigates away. */
		handleError( error, fallbackUrl ) {
			if ( this.destroyed || ( error && 'AbortError' === error.name ) ) {
				return false;
			}

			if ( isEditor() ) {
				this.showNotice( error instanceof RequestError && 404 === error.status ? this.config.saveHint : this.config.error );
				return false;
			}

			// The saved page does not match this markup (e.g. a cached page): use the plain link / form.
			if ( fallbackUrl && error instanceof RequestError && 404 === error.status ) {
				window.location.assign( fallbackUrl );
				return true;
			}

			this.announce( this.config.error || '' );
			return false;
		}

		showNotice( text ) {
			let notice = this.root.querySelector( '.lst-posts__notice' );

			if ( ! notice ) {
				notice = document.createElement( 'p' );
				notice.className = 'lst-posts__notice';
				this.root.appendChild( notice );
			}

			notice.textContent = text || '';
		}

		announce( text ) {
			if ( ! this.status ) {
				return;
			}

			// Cleared first, so the same message is announced again.
			this.status.textContent = '';
			window.clearTimeout( this.announceTimer );
			this.announceTimer = window.setTimeout( () => {
				if ( ! this.destroyed ) {
					this.status.textContent = text || '';
				}
			}, 100 );
		}

		/* Load more -------------------------------------------------------- */

		loadMore( link ) {
			if ( this.busy || 'true' === link.getAttribute( 'aria-disabled' ) ) {
				return;
			}

			if ( 'current' === this.config.source ) {
				this.loadMoreFromPage( link );
				return;
			}

			const label = link.textContent;
			const loading = link.getAttribute( 'data-lst-loading' );
			const restore = () => {
				link.textContent = label;
				link.removeAttribute( 'aria-disabled' );
			};

			this.setBusy( true );
			link.setAttribute( 'aria-disabled', 'true' );

			if ( loading ) {
				link.textContent = loading;
			}

			this.request( this.page + 1, this.getActiveCats() ).then( ( data ) => {
				if ( this.destroyed ) {
					return;
				}

				const added = this.appendItems( data.html || '' );

				this.page = parseInt( data.page, 10 ) || this.page + 1;
				this.maxPages = parseInt( data.maxPages, 10 ) || 0;
				this.replacePagination( data.pagination );
				this.announce( data.message );
				this.focusItem( added[ 0 ] );
			} ).catch( ( error ) => {
				if ( ! this.handleError( error, link.href ) && link.isConnected ) {
					restore();
				}
			} ).finally( () => {
				this.setBusy( false );
			} );
		}

		/** "Current query" source: the next page is a regular archive page; take its cards. */
		loadMoreFromPage( link ) {
			const label = link.textContent;
			const loading = link.getAttribute( 'data-lst-loading' );

			this.setBusy( true );
			link.setAttribute( 'aria-disabled', 'true' );

			if ( loading ) {
				link.textContent = loading;
			}

			window.fetch( link.href, { credentials: 'same-origin' } )
				.then( ( response ) => {
					if ( ! response.ok ) {
						throw new RequestError( response.statusText, response.status );
					}

					return response.text();
				} )
				.then( ( html ) => {
					const doc = new window.DOMParser().parseFromString( html, 'text/html' );
					const other = doc.querySelector( ROOT_SELECTOR + '[data-lst-element="' + String( this.config.element ).replace( /[^A-Za-z0-9]/g, '' ) + '"]' );

					if ( ! other ) {
						throw new RequestError( 'Not found', 404 );
					}

					const grid = other.querySelector( '.lst-posts__grid' );
					const pagination = other.querySelector( '.lst-posts__pagination' );
					const added = this.appendItems( grid ? grid.innerHTML : '' );

					this.page++;
					this.replacePagination( pagination ? pagination.outerHTML : '' );
					this.focusItem( added[ 0 ] );
				} )
				.catch( ( error ) => {
					if ( ! this.handleError( error, link.href ) && link.isConnected ) {
						link.textContent = label;
						link.removeAttribute( 'aria-disabled' );
					}
				} )
				.finally( () => this.setBusy( false ) );
		}

		/** Appends cards (skipping posts already shown); returns the new cards. */
		appendItems( html ) {
			if ( ! this.grid ) {
				return [];
			}

			const template = document.createElement( 'template' );
			const shown = new Set( Array.from( this.grid.querySelectorAll( '[data-post-id]' ) ).map( ( item ) => item.getAttribute( 'data-post-id' ) ) );
			const added = [];

			template.innerHTML = html;

			Array.from( template.content.children ).forEach( ( item ) => {
				if ( item.hasAttribute( 'data-post-id' ) && shown.has( item.getAttribute( 'data-post-id' ) ) ) {
					return;
				}

				item.classList.add( 'is-new' );
				this.grid.appendChild( item );
				added.push( item );
			} );

			return added;
		}

		/** Focus for the first new card; without one (nothing added), the new button or the grid keeps it in the widget. */
		focusItem( item ) {
			const link = item && item.querySelector( '.lst-posts__link' );

			if ( link ) {
				link.focus();
				return;
			}

			const more = this.getMoreLink();

			if ( more ) {
				more.focus();
			} else if ( this.grid ) {
				this.grid.setAttribute( 'tabindex', '-1' );
				this.grid.focus();
			}
		}

		replacePagination( html ) {
			const current = this.root.querySelector( '.lst-posts__pagination' );

			if ( ! current || 'string' !== typeof html ) {
				return;
			}

			const template = document.createElement( 'template' );

			template.innerHTML = html.trim();

			const next = template.content.firstElementChild;

			if ( next ) {
				current.replaceWith( next );
			} else {
				current.hidden = true;
				current.innerHTML = '';
			}

			this.enhanceMore();
		}

		/* Filter ----------------------------------------------------------- */

		applyFilter() {
			if ( this.destroyed ) {
				return;
			}

			const cats = this.getActiveCats();

			this.setBusy( true );

			this.request( 1, cats ).then( ( data ) => {
				if ( this.destroyed ) {
					return;
				}

				if ( this.grid ) {
					this.grid.innerHTML = data.html || '';
				}

				if ( this.empty ) {
					this.empty.hidden = !! data.count;
				}

				this.page = 1;
				this.maxPages = parseInt( data.maxPages, 10 ) || 0;
				this.replacePagination( data.pagination );
				this.updateUrl( Array.isArray( data.cats ) ? data.cats : cats );
				this.announce( data.message );
				this.setBusy( false );
			} ).catch( ( error ) => {
				if ( error && 'AbortError' === error.name ) {
					return; // A newer request is running.
				}

				this.setBusy( false );

				if ( error instanceof RequestError && 404 === error.status && ! isEditor() && this.form ) {
					HTMLFormElement.prototype.submit.call( this.form );
					return;
				}

				this.handleError( error, '' );
			} );
		}

		updateUrl( cats ) {
			if ( isEditor() || ! window.history || 'function' !== typeof window.history.replaceState ) {
				return;
			}

			const url = new URL( window.location.href );

			url.searchParams.delete( ARG_CATS );
			url.searchParams.delete( ARG_PAGE );
			url.searchParams.delete( ARG_ELEMENT );

			if ( cats.length ) {
				cats.forEach( ( id ) => url.searchParams.append( ARG_CATS, String( id ) ) );
				url.searchParams.set( ARG_ELEMENT, String( this.config.element || '' ) );
			}

			window.history.replaceState( window.history.state, '', url.toString() );
		}

		/* Teardown --------------------------------------------------------- */

		destroy() {
			if ( this.destroyed ) {
				return;
			}

			this.destroyed = true;
			window.clearTimeout( this.timer );
			window.clearTimeout( this.announceTimer );

			if ( this.controller ) {
				this.controller.abort();
				this.controller = null;
			}

			this.listeners.forEach( ( [ target, type, handler, options ] ) => target.removeEventListener( type, handler, options ) );
			this.listeners = [];
			this.root.classList.remove( 'is-enhanced' );

			const link = this.getMoreLink();

			if ( link ) {
				link.removeAttribute( 'role' );
			}

			instances.delete( this );
			byRoot.delete( this.root );
		}
	}

	/* Registry ----------------------------------------------------------------- */

	function sweep() {
		instances.forEach( ( instance ) => {
			if ( ! instance.root.isConnected ) {
				instance.destroy();
			}
		} );
	}

	function init( root ) {
		if ( ! root || ! root.classList || ! root.classList.contains( 'lst-posts' ) || ! root.hasAttribute( 'data-lst-posts' ) ) {
			return null;
		}

		sweep();

		if ( byRoot.has( root ) ) {
			return byRoot.get( root );
		}

		const instance = new Posts( root );

		instances.add( instance );
		byRoot.set( root, instance );

		return instance;
	}

	function destroy( root ) {
		const instance = byRoot.get( root );

		if ( instance ) {
			instance.destroy();
		}
	}

	function initAll( scope ) {
		( scope || document ).querySelectorAll( ROOT_SELECTOR ).forEach( init );
	}

	window.ListemannPosts = {
		init,
		destroy,
		initAll,
		getInstance: ( root ) => byRoot.get( root ) || null,
	};

	/* Boot ------------------------------------------------------------------------ */

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', () => initAll( document ) );
	} else {
		initAll( document );
	}

	function registerElementorHandler() {
		const frontend = window.elementorFrontend;

		if ( ! frontend ) {
			return;
		}

		const Base = window.elementorModules && window.elementorModules.frontend && window.elementorModules.frontend.handlers && window.elementorModules.frontend.handlers.Base;

		if ( Base && frontend.elementsHandler && frontend.elementsHandler.attachHandler ) {
			// No class fields: Elementor's Module constructor calls onInit() inside super().
			class ListemannPostsHandler extends Base {
				onInit() {
					super.onInit();
					this.lstPosts = init( this.$element[ 0 ].querySelector( ROOT_SELECTOR ) );
				}

				onDestroy() {
					if ( this.lstPosts ) {
						this.lstPosts.destroy();
						this.lstPosts = null;
					}

					super.onDestroy();
				}
			}

			frontend.elementsHandler.attachHandler( 'listemann-posts', ListemannPostsHandler );
			return;
		}

		if ( frontend.hooks ) {
			frontend.hooks.addAction( 'frontend/element_ready/listemann-posts.default', ( $scope ) => {
				const element = $scope && $scope[ 0 ] ? $scope[ 0 ] : $scope;

				if ( element && element.querySelector ) {
					init( element.querySelector( ROOT_SELECTOR ) );
				}
			} );
		}
	}

	if ( window.elementorFrontend && window.elementorFrontend.hooks ) {
		registerElementorHandler();
	} else {
		window.addEventListener( 'elementor/frontend/init', registerElementorHandler );
	}
}() );
