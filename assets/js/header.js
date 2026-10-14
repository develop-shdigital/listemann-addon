/**
 * Listemann Header widget: dropdown / mega menu and off-canvas dialog.
 *
 * Works without Elementor's frontend script: every `.lst-header` is set up
 * on DOMContentLoaded. When Elementor's frontend runs (front end and editor
 * preview), a handler for `listemann-header` additionally destroys the
 * instance when the editor re-renders or deletes the widget. Setup is
 * idempotent (one instance per root element).
 *
 * Public API: window.ListemannHeader.init( root ), .destroy( root ),
 * .getInstance( root ), .initAll( scope ).
 */
( function () {
	'use strict';

	const ROOT_SELECTOR = '.lst-header';
	const HOVER_CLOSE_DELAY = 150;
	const POINTER_FOCUS_WINDOW = 600;
	const FOCUSABLE = [
		'a[href]',
		'area[href]',
		'button:not([disabled])',
		'input:not([disabled]):not([type="hidden"])',
		'select:not([disabled])',
		'textarea:not([disabled])',
		'iframe',
		'[contenteditable=""]',
		'[contenteditable="true"]',
		'[tabindex]:not([tabindex="-1"])',
	].join( ',' );

	const instances = new Set();
	const byRoot = new WeakMap();

	/* Scroll lock (shared by all instances) ------------------------------ */

	let lockCount = 0;
	let savedPadding = '';

	function lockScroll() {
		lockCount++;

		if ( 1 !== lockCount ) {
			return;
		}

		const html = document.documentElement;
		const body = document.body;
		const scrollbar = window.innerWidth - html.clientWidth;

		savedPadding = body.style.paddingRight;

		if ( scrollbar > 0 ) {
			const current = parseFloat( window.getComputedStyle( body ).paddingRight ) || 0;
			body.style.paddingRight = current + scrollbar + 'px';
		}

		html.classList.add( 'lst-scroll-locked' );
	}

	function unlockScroll() {
		if ( lockCount < 1 ) {
			return;
		}

		lockCount--;

		if ( 0 !== lockCount ) {
			return;
		}

		document.documentElement.classList.remove( 'lst-scroll-locked' );
		document.body.style.paddingRight = savedPadding;
	}

	/* Helpers -------------------------------------------------------------- */

	function parseConfig( root ) {
		let config = {};

		try {
			config = JSON.parse( root.getAttribute( 'data-lst-header' ) || '{}' ) || {};
		} catch ( e ) {
			config = {};
		}

		return {
			openOn: 'click' === config.openOn ? 'click' : 'hover',
			breakpoint: Math.max( 0, parseInt( config.breakpoint, 10 ) || 0 ),
			sticky: !! config.sticky,
			offcanvas: 'string' === typeof config.offcanvas ? config.offcanvas : '',
		};
	}

	function isShown( el ) {
		return el.getClientRects().length > 0 && 'hidden' !== window.getComputedStyle( el ).visibility;
	}

	function adminBarOffset() {
		const bar = document.getElementById( 'wpadminbar' );

		if ( ! bar || 'fixed' !== window.getComputedStyle( bar ).position ) {
			return 0;
		}

		return bar.offsetHeight;
	}

	function isRtl( el ) {
		return 'rtl' === window.getComputedStyle( el ).direction;
	}

	/* Header instance ------------------------------------------------------- */

	class Header {
		constructor( root ) {
			this.root = root;
			this.config = parseConfig( root );
			this.bar = root.querySelector( ':scope > .lst-header__bar' );
			this.inner = this.bar ? this.bar.querySelector( ':scope > .lst-header__inner' ) : null;
			this.items = [];
			this.listeners = [];
			this.lastPointer = 'mouse';
			this.pointerDownAt = 0;
			this.offcanvas = null;
			this.ocHome = null;
			this.ocTrigger = null;
			this.ocOpen = false;
			this.stuck = false;
			this.frame = 0;
			this.mql = null;
			this.destroyed = false;

			this.setupMenu();
			this.setupOffcanvas();
			this.setupSticky();

			this.on( window, 'resize', () => this.schedule() );

			root.classList.add( 'is-ready' );
		}

		on( target, type, handler, options ) {
			target.addEventListener( type, handler, options );
			this.listeners.push( [ target, type, handler, options ] );
		}

		/* Dropdowns -------------------------------------------------------- */

		setupMenu() {
			// Only this header's items (a template in the off-canvas may contain another header).
			const lis = Array.from( this.root.querySelectorAll( '.lst-header__item--top.lst-header__item--has-sub' ) ).filter( ( li ) => li.closest( '.lst-header' ) === this.root );

			lis.forEach( ( li ) => {
				const dropdown = li.querySelector( ':scope > .lst-header__dropdown' );

				if ( ! dropdown ) {
					return;
				}

				const item = {
					li,
					dropdown,
					toggles: Array.from( li.querySelectorAll( ':scope > .lst-header__toggle, :scope > .lst-header__toggle-label' ) ),
					link: li.querySelector( ':scope > a.lst-header__link--top' ),
					timer: 0,
					hovered: false,
				};

				this.items.push( item );

				if ( 'hover' === this.config.openOn ) {
					this.on( li, 'pointerenter', ( e ) => this.onPointerEnter( item, e ) );
					this.on( li, 'pointerleave', ( e ) => this.onPointerLeave( item, e ) );
					this.on( li, 'focusin', () => this.onFocusIn( item ) );
				}

				this.on( li, 'focusout', ( e ) => this.onFocusOut( item, e ) );

				item.toggles.forEach( ( toggle ) => {
					this.on( toggle, 'click', ( e ) => this.onToggleClick( item, e ) );
				} );

				if ( item.link ) {
					this.on( item.link, 'click', ( e ) => this.onLinkClick( item, e ) );
				}
			} );

			if ( ! this.items.length ) {
				return;
			}

			this.on( this.root, 'pointerdown', ( e ) => {
				this.lastPointer = e.pointerType || 'mouse';
				this.pointerDownAt = Date.now();
			} );

			this.on( this.root, 'keydown', ( e ) => this.onKeyDown( e ) );

			this.on( document, 'pointerdown', ( e ) => {
				if ( ! this.root.contains( e.target ) ) {
					this.closeAll();
				}
			} );

			if ( this.config.breakpoint > 0 && window.matchMedia ) {
				this.mql = window.matchMedia( '(max-width: ' + ( this.config.breakpoint - 0.02 ) + 'px)' );
				this.onMediaChange = () => this.closeAll();

				if ( this.mql.addEventListener ) {
					this.mql.addEventListener( 'change', this.onMediaChange );
				} else if ( this.mql.addListener ) {
					this.mql.addListener( this.onMediaChange );
				}
			}
		}

		isOpen( item ) {
			return item.li.classList.contains( 'is-open' );
		}

		open( item ) {
			this.items.forEach( ( other ) => {
				if ( other !== item ) {
					this.close( other );
				}
			} );

			window.clearTimeout( item.timer );
			item.li.classList.remove( 'is-dismissed' );

			if ( this.isOpen( item ) ) {
				return;
			}

			item.li.classList.add( 'is-open' );
			item.toggles.forEach( ( toggle ) => toggle.setAttribute( 'aria-expanded', 'true' ) );
			this.position( item );
		}

		close( item ) {
			window.clearTimeout( item.timer );
			item.timer = 0;

			// Focus inside keeps :focus-within true; mark the item so CSS does not show it again.
			if ( item.li.contains( document.activeElement ) ) {
				item.li.classList.add( 'is-dismissed' );
			}

			if ( ! this.isOpen( item ) ) {
				return;
			}

			item.li.classList.remove( 'is-open' );
			item.toggles.forEach( ( toggle ) => toggle.setAttribute( 'aria-expanded', 'false' ) );
		}

		closeAll() {
			this.items.forEach( ( item ) => this.close( item ) );
		}

		onPointerEnter( item, e ) {
			if ( 'touch' === e.pointerType ) {
				return;
			}

			item.hovered = true;
			this.open( item );
		}

		onPointerLeave( item, e ) {
			if ( 'touch' === e.pointerType ) {
				return;
			}

			item.hovered = false;
			window.clearTimeout( item.timer );
			item.timer = window.setTimeout( () => this.close( item ), HOVER_CLOSE_DELAY );
		}

		onFocusIn( item ) {
			// Focus caused by a pointer is handled by the hover / click logic.
			if ( Date.now() - this.pointerDownAt < POINTER_FOCUS_WINDOW ) {
				return;
			}

			if ( ! item.li.classList.contains( 'is-dismissed' ) ) {
				this.open( item );
			}
		}

		onFocusOut( item, e ) {
			if ( e.relatedTarget && item.li.contains( e.relatedTarget ) ) {
				return;
			}

			item.li.classList.remove( 'is-dismissed' );

			if ( ! item.hovered ) {
				this.close( item );
			}
		}

		onToggleClick( item, e ) {
			e.preventDefault();

			if ( this.isOpen( item ) ) {
				this.close( item );
			} else {
				this.open( item );
			}
		}

		onLinkClick( item, e ) {
			// Touch (hover mode) and click mode: the first click opens, the second follows the link.
			const firstOpens = 'click' === this.config.openOn || 'touch' === this.lastPointer || 'pen' === this.lastPointer;

			if ( firstOpens && ! this.isOpen( item ) && ! this.isCollapsed() ) {
				e.preventDefault();
				this.open( item );
			}
		}

		onKeyDown( e ) {
			if ( 'Escape' !== e.key && 'Esc' !== e.key ) {
				return;
			}

			const item = this.items.find( ( candidate ) => candidate.li.contains( e.target ) && ( this.isOpen( candidate ) || candidate.li.matches( ':focus-within' ) ) );

			if ( ! item ) {
				return;
			}

			e.preventDefault();
			item.li.classList.add( 'is-dismissed' );
			this.close( item );

			const target = item.toggles.find( ( toggle ) => toggle.classList.contains( 'lst-header__toggle' ) ) || item.toggles[ 0 ] || item.link;

			if ( target ) {
				target.focus();
			}
		}

		isCollapsed() {
			return !! ( this.mql && this.mql.matches );
		}

		/**
		 * Full-width mega panels span the header content box and hang from the
		 * bottom of the bar; other panels flip to the left when they would
		 * overflow the viewport.
		 *
		 * @param {Object} item Dropdown item.
		 */
		position( item ) {
			const dd = item.dropdown;

			if ( this.root.classList.contains( 'lst-header--mega-full' ) && item.li.classList.contains( 'lst-header__item--mega' ) && this.inner ) {
				const style = window.getComputedStyle( this.inner );
				const box = this.inner.getBoundingClientRect();
				const li = item.li.getBoundingClientRect();
				const left = box.left + ( parseFloat( style.paddingLeft ) || 0 );
				const width = box.width - ( parseFloat( style.paddingLeft ) || 0 ) - ( parseFloat( style.paddingRight ) || 0 );
				const top = Math.max( li.height, box.bottom - li.top );

				dd.style.setProperty( '--lst-hdr-mega-left', Math.round( left - li.left ) + 'px' );
				dd.style.setProperty( '--lst-hdr-mega-w', Math.round( width ) + 'px' );
				dd.style.setProperty( '--lst-hdr-mega-top', Math.round( top ) + 'px' );

				// Invisible bridge between the item and the panel, so hover is not lost on the way down.
				dd.style.setProperty( '--lst-hdr-mega-bridge-left', Math.round( li.left - left ) + 'px' );
				dd.style.setProperty( '--lst-hdr-mega-bridge-w', Math.round( li.width ) + 'px' );
				dd.style.setProperty( '--lst-hdr-mega-bridge-h', Math.max( 0, Math.round( top - li.height ) ) + 'px' );
				return;
			}

			dd.classList.remove( 'is-flipped' );

			const rect = dd.getBoundingClientRect();
			const viewport = document.documentElement.clientWidth;

			if ( isRtl( this.root ) ? rect.left < 0 : rect.right > viewport ) {
				dd.classList.add( 'is-flipped' );
			}
		}

		/* Off-canvas ------------------------------------------------------- */

		setupOffcanvas() {
			const id = this.config.offcanvas;
			let oc = this.root.querySelector( ':scope > .lst-offcanvas' );

			if ( ! oc && id ) {
				oc = document.getElementById( id );
			}

			this.burgers = this.bar ? Array.from( this.bar.querySelectorAll( '.lst-header__burger' ) ) : [];

			if ( ! oc ) {
				return;
			}

			this.offcanvas = oc;
			this.closeButton = oc.querySelector( '.lst-offcanvas__close' );

			// Transformed ancestors (Elementor motion effects, sticky parents) would
			// trap the fixed dialog, so it lives in <body> while the header runs.
			if ( oc.parentNode !== document.body ) {
				this.ocHome = { parent: oc.parentNode, next: oc.nextSibling };
				document.body.appendChild( oc );
			}

			this.burgers.forEach( ( burger ) => {
				this.on( burger, 'click', ( e ) => {
					e.preventDefault();
					this.openOffcanvas( burger );
				} );
			} );

			if ( this.closeButton ) {
				this.on( this.closeButton, 'click', ( e ) => {
					e.preventDefault();
					this.closeOffcanvas();
				} );
			}

			this.on( oc, 'keydown', ( e ) => this.onOffcanvasKeyDown( e ) );
			this.on( oc, 'click', ( e ) => this.onOffcanvasClick( e ) );

			this.on( document, 'keydown', ( e ) => {
				if ( this.ocOpen && ( 'Escape' === e.key || 'Esc' === e.key ) && ! oc.contains( e.target ) ) {
					this.closeOffcanvas();
				}
			} );

			// Keep focus inside while open (e.g. focus moved by a script or a click outside).
			this.on( document, 'focusin', ( e ) => {
				if ( this.ocOpen && ! oc.contains( e.target ) ) {
					this.focusFirst();
				}
			} );
		}

		getFocusable() {
			if ( ! this.offcanvas ) {
				return [];
			}

			return Array.from( this.offcanvas.querySelectorAll( FOCUSABLE ) ).filter( ( el ) => ! el.closest( '[inert]' ) && isShown( el ) );
		}

		focusFirst( attempt = 0 ) {
			const target = this.closeButton && isShown( this.closeButton ) ? this.closeButton : this.getFocusable()[ 0 ];

			if ( target ) {
				target.focus( { preventScroll: true } );
			}

			if ( this.offcanvas.contains( document.activeElement ) ) {
				return;
			}

			// A theme transition on visibility can keep the content hidden for a moment: retry briefly.
			if ( attempt < 8 ) {
				window.setTimeout( () => {
					if ( this.ocOpen ) {
						this.focusFirst( attempt + 1 );
					}
				}, 50 );
				return;
			}

			this.offcanvas.setAttribute( 'tabindex', '-1' );
			this.offcanvas.focus( { preventScroll: true } );
		}

		openOffcanvas( trigger ) {
			if ( ! this.offcanvas || this.ocOpen ) {
				return;
			}

			this.closeAll();
			this.ocOpen = true;
			this.ocTrigger = trigger || document.activeElement;

			this.offcanvas.removeAttribute( 'inert' );
			this.offcanvas.classList.add( 'is-open' );
			this.burgers.forEach( ( burger ) => burger.setAttribute( 'aria-expanded', 'true' ) );
			lockScroll();
			this.focusFirst();
		}

		closeOffcanvas( returnFocus = true ) {
			if ( ! this.offcanvas || ! this.ocOpen ) {
				return;
			}

			this.ocOpen = false;
			this.offcanvas.classList.remove( 'is-open' );
			this.offcanvas.setAttribute( 'inert', '' );
			this.burgers.forEach( ( burger ) => burger.setAttribute( 'aria-expanded', 'false' ) );
			unlockScroll();

			const trigger = this.ocTrigger;
			this.ocTrigger = null;

			if ( returnFocus && trigger && trigger.isConnected && 'function' === typeof trigger.focus ) {
				trigger.focus( { preventScroll: true } );
			}
		}

		onOffcanvasKeyDown( e ) {
			if ( 'Escape' === e.key || 'Esc' === e.key ) {
				e.preventDefault();
				this.closeOffcanvas();
				return;
			}

			if ( 'Tab' !== e.key ) {
				return;
			}

			const focusable = this.getFocusable();

			if ( ! focusable.length ) {
				e.preventDefault();
				return;
			}

			const first = focusable[ 0 ];
			const last = focusable[ focusable.length - 1 ];
			const active = document.activeElement;

			if ( e.shiftKey && ( active === first || ! this.offcanvas.contains( active ) ) ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && ( active === last || ! this.offcanvas.contains( active ) ) ) {
				e.preventDefault();
				first.focus();
			}
		}

		onOffcanvasClick( e ) {
			const link = e.target.closest ? e.target.closest( 'a[href]' ) : null;

			if ( ! link || ! this.offcanvas.contains( link ) ) {
				return;
			}

			// Same-page anchors: close the dialog so the target becomes visible.
			const url = new URL( link.href, window.location.href );

			if ( url.hash && url.origin === window.location.origin && url.pathname === window.location.pathname && url.search === window.location.search ) {
				this.closeOffcanvas( false );
			}
		}

		/* Sticky ---------------------------------------------------------- */

		setupSticky() {
			if ( ! this.config.sticky || ! this.bar ) {
				return;
			}

			this.on( window, 'scroll', () => this.schedule(), { passive: true } );
			this.updateSticky();
		}

		updateSticky() {
			if ( ! this.config.sticky || ! this.bar ) {
				return;
			}

			const offset = adminBarOffset();
			const rect = this.root.getBoundingClientRect();
			const stick = rect.bottom <= offset;

			if ( stick ) {
				this.root.style.setProperty( '--lst-hdr-sticky-left', Math.round( rect.left ) + 'px' );
				this.root.style.setProperty( '--lst-hdr-sticky-width', Math.round( rect.width ) + 'px' );
			}

			if ( stick === this.stuck ) {
				return;
			}

			this.stuck = stick;
			this.closeAll();
			this.root.classList.toggle( 'is-sticky', stick );
		}

		/* Resize / scroll ------------------------------------------------- */

		schedule() {
			if ( this.frame ) {
				return;
			}

			this.frame = window.requestAnimationFrame( () => {
				this.frame = 0;

				if ( this.destroyed ) {
					return;
				}

				this.updateSticky();
				this.items.filter( ( item ) => this.isOpen( item ) ).forEach( ( item ) => this.position( item ) );
			} );
		}

		/* Teardown -------------------------------------------------------- */

		destroy() {
			if ( this.destroyed ) {
				return;
			}

			this.destroyed = true;
			this.closeOffcanvas( false );

			this.items.forEach( ( item ) => {
				window.clearTimeout( item.timer );
				item.li.classList.remove( 'is-open', 'is-dismissed' );
				item.toggles.forEach( ( toggle ) => toggle.setAttribute( 'aria-expanded', 'false' ) );
			} );

			this.listeners.forEach( ( [ target, type, handler, options ] ) => target.removeEventListener( type, handler, options ) );
			this.listeners = [];

			if ( this.mql && this.onMediaChange ) {
				if ( this.mql.removeEventListener ) {
					this.mql.removeEventListener( 'change', this.onMediaChange );
				} else if ( this.mql.removeListener ) {
					this.mql.removeListener( this.onMediaChange );
				}
			}

			if ( this.frame ) {
				window.cancelAnimationFrame( this.frame );
			}

			// Put the dialog back while the header is still in the page; otherwise
			// (editor re-render, element deleted) remove it from <body>.
			if ( this.offcanvas && this.ocHome ) {
				if ( this.root.isConnected && this.ocHome.parent && this.ocHome.parent.isConnected ) {
					this.ocHome.parent.insertBefore( this.offcanvas, this.ocHome.next && this.ocHome.next.parentNode === this.ocHome.parent ? this.ocHome.next : null );
				} else {
					this.offcanvas.remove();
				}
			}

			this.root.classList.remove( 'is-ready', 'is-sticky' );
			this.root.style.removeProperty( '--lst-hdr-sticky-left' );
			this.root.style.removeProperty( '--lst-hdr-sticky-width' );

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
		if ( ! root || ! root.classList || ! root.classList.contains( 'lst-header' ) ) {
			return null;
		}

		sweep();

		if ( byRoot.has( root ) ) {
			return byRoot.get( root );
		}

		const instance = new Header( root );

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

	window.ListemannHeader = {
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
			class ListemannHeaderHandler extends Base {
				onInit() {
					super.onInit();
					this.lstHeader = init( this.$element[ 0 ].querySelector( ROOT_SELECTOR ) );
				}

				onDestroy() {
					if ( this.lstHeader ) {
						this.lstHeader.destroy();
						this.lstHeader = null;
					}

					super.onDestroy();
				}
			}

			frontend.elementsHandler.attachHandler( 'listemann-header', ListemannHeaderHandler );
			return;
		}

		if ( frontend.hooks ) {
			frontend.hooks.addAction( 'frontend/element_ready/listemann-header.default', ( $scope ) => {
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
