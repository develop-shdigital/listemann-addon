/**
 * Listemann Toolkit – side dock behaviour (no dependencies).
 *
 * - Contact box: disclosure button (aria-expanded / aria-controls). Click
 *   toggles; Escape, a click outside or moving focus out of the box closes.
 *   The closed panel is `inert` (and `visibility: hidden` via CSS).
 * - Back to top: scrolls to the top (smooth unless the user prefers reduced
 *   motion), then moves focus to the top of the document for keyboard users.
 *   Optionally only shown after scrolling past `data-lst-offset` pixels.
 */
( function () {
	'use strict';

	const dock = document.querySelector( '[data-lst-dock]' );

	if ( ! dock || dock.classList.contains( 'is-ready' ) ) {
		return;
	}

	const motionQuery = window.matchMedia ? window.matchMedia( '(prefers-reduced-motion: reduce)' ) : null;
	const prefersReducedMotion = () => !! ( motionQuery && motionQuery.matches );

	/* Contact box --------------------------------------------------------- */

	const box = dock.querySelector( '[data-lst-contact]' );
	const toggle = box ? box.querySelector( '[data-lst-toggle]' ) : null;
	const panel = box ? box.querySelector( '[data-lst-panel]' ) : null;

	const isOpen = () => !! box && box.classList.contains( 'is-open' );

	const setOpen = ( open, options = {} ) => {
		if ( ! box || ! toggle || ! panel ) {
			return;
		}

		const focusWasInside = panel.contains( document.activeElement );

		box.classList.toggle( 'is-open', open );
		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		panel.inert = ! open;

		if ( ! open && ( options.returnFocus || focusWasInside ) ) {
			toggle.focus( { preventScroll: true } );
		}
	};

	if ( box && toggle && panel ) {
		panel.inert = true;

		toggle.addEventListener( 'click', () => {
			setOpen( ! isOpen() );
		} );

		document.addEventListener( 'keydown', ( event ) => {
			if ( 'Escape' !== event.key || ! isOpen() ) {
				return;
			}

			setOpen( false, { returnFocus: box.contains( document.activeElement ) } );
		} );

		// Pointer press outside closes (pointerdown also fires on iOS, where
		// delegated "click" events on non-interactive elements do not).
		document.addEventListener( 'pointerdown', ( event ) => {
			if ( isOpen() && ! box.contains( event.target ) ) {
				setOpen( false );
			}
		} );

		box.addEventListener( 'focusout', ( event ) => {
			const next = event.relatedTarget;

			if ( isOpen() && next && ! box.contains( next ) ) {
				setOpen( false );
			}
		} );
	}

	/* Back to top -------------------------------------------------------- */

	const topWrap = dock.querySelector( '[data-lst-top]' );
	const topButton = topWrap ? topWrap.querySelector( '[data-lst-top-button]' ) : null;
	const offset = Math.max( 0, parseInt( dock.getAttribute( 'data-lst-offset' ) || '0', 10 ) || 0 );
	let activeTimer = 0;

	const scrollToTop = () => {
		const behavior = prefersReducedMotion() ? 'instant' : 'smooth';

		try {
			window.scrollTo( { top: 0, left: 0, behavior } );
		} catch ( error ) {
			window.scrollTo( 0, 0 );
		}
	};

	// Move focus to the start of the document so the next Tab starts at the top.
	const focusDocumentStart = () => {
		const body = document.body;
		const hadTabindex = body.hasAttribute( 'tabindex' );

		if ( ! hadTabindex ) {
			body.setAttribute( 'tabindex', '-1' );
		}

		body.setAttribute( 'data-lst-dock-focus', '' );
		body.focus( { preventScroll: true } );

		body.addEventListener(
			'blur',
			() => {
				body.removeAttribute( 'data-lst-dock-focus' );

				if ( ! hadTabindex ) {
					body.removeAttribute( 'tabindex' );
				}
			},
			{ once: true }
		);
	};

	if ( topWrap && topButton ) {
		topButton.addEventListener( 'click', () => {
			// Briefly show the label (feedback on touch devices without hover).
			topWrap.classList.add( 'is-active' );
			window.clearTimeout( activeTimer );
			activeTimer = window.setTimeout( () => topWrap.classList.remove( 'is-active' ), 900 );

			if ( isOpen() ) {
				setOpen( false );
			}

			scrollToTop();
			focusDocumentStart();
		} );

		if ( offset > 0 ) {
			let ticking = false;

			const update = () => {
				ticking = false;
				topWrap.classList.toggle( 'is-hidden', ( window.scrollY || window.pageYOffset || 0 ) <= offset );
			};

			window.addEventListener(
				'scroll',
				() => {
					if ( ! ticking ) {
						ticking = true;
						window.requestAnimationFrame( update );
					}
				},
				{ passive: true }
			);

			update();
		} else {
			topWrap.classList.remove( 'is-hidden' );
		}
	}

	dock.classList.add( 'is-ready' );
}() );
