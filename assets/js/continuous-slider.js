/**
 * Listemann Toolkit Addon: Continuous Slider.
 *
 * `ListemannContinuousSlider` is a dependency-free engine that turns the
 * `.lst-cslider` markup into an endless strip. The server renders every slide
 * once; the engine clones the set as often as the viewport needs and moves the
 * track with translate3d(), wrapping the offset modulo one set width.
 *
 * Clones are hidden from assistive technology (`aria-hidden`) and their content
 * is `inert`. The clone's slide element itself stays hit-testable, so hover
 * styles still apply to it and clicks on it are replayed on the original card.
 *
 * Modes, read from the `--lst-mode` custom property so they follow Elementor's
 * breakpoints:
 * - continuous: linear glide driven by requestAnimationFrame,
 * - step:       advances one slide per interval with an eased transition,
 * - none:       static strip.
 *
 * A thin Elementor frontend handler at the bottom creates and destroys engine
 * instances for the `listemann-continuous-slider` widget.
 */
( function () {
	'use strict';

	const SELECTOR = {
		root: '.lst-cslider',
		viewport: '.lst-cslider__viewport',
		track: '.lst-cslider__track',
		slide: '.lst-cslider__slide',
		card: '.lst-cslider__card',
	};

	const CLASS = {
		ready: 'is-ready',
		clone: 'is-clone',
		link: 'is-link',
		draggable: 'is-draggable',
		dragging: 'is-dragging',
	};

	const MODES = [ 'continuous', 'step', 'none' ];

	const DEFAULTS = {
		direction: 'left',
		forwardClicks: true, // Replay clicks on clones on the original card.
		continuous: { speed: 6, pauseOnHover: false, draggable: false },
		step: { interval: 3500, duration: 300, pauseOnHover: true, draggable: true },
		none: { draggable: false },
	};

	const FOCUSABLE = 'a[href], area[href], button, input, select, textarea, iframe, summary, [tabindex], [contenteditable]';

	const DRAG_THRESHOLD = 6; // px a press has to travel before it becomes a drag.
	const FLICK_VELOCITY = 0.3; // px/ms; a faster release moves on to the next slide.
	const RAMP_DURATION = 600; // ms to ease the glide to a stop and back to full speed.
	const MAX_FRAME_DELTA = 100; // ms; caps the time step after a stalled frame.
	const FOCUS_DURATION = 300; // ms to bring a keyboard-focused slide into view.

	const instances = new WeakMap();

	let activeCount = 0; // Running engines (for diagnostics and tests).

	const now = () => ( window.performance && performance.now ? performance.now() : Date.now() );

	const raf = window.requestAnimationFrame
		? ( callback ) => window.requestAnimationFrame( callback )
		: ( callback ) => window.setTimeout( () => callback( now() ), 16 );

	const caf = window.cancelAnimationFrame
		? ( id ) => window.cancelAnimationFrame( id )
		: ( id ) => window.clearTimeout( id );

	const mod = ( value, length ) => ( ( value % length ) + length ) % length;

	const clamp = ( value, min, max ) => Math.min( Math.max( value, min ), max );

	const smoothstep = ( t ) => t * t * ( 3 - 2 * t );

	const toNumber = ( value, fallback, min ) => {
		const number = parseFloat( value );

		return Number.isFinite( number ) ? Math.max( number, min ) : fallback;
	};

	/**
	 * Returns an easing function equivalent to CSS cubic-bezier().
	 *
	 * @param {number} x1 First control point x.
	 * @param {number} y1 First control point y.
	 * @param {number} x2 Second control point x.
	 * @param {number} y2 Second control point y.
	 * @return {Function} t (0..1) => eased progress (0..1).
	 */
	function cubicBezier( x1, y1, x2, y2 ) {
		const cx = 3 * x1;
		const bx = 3 * ( x2 - x1 ) - cx;
		const ax = 1 - cx - bx;
		const cy = 3 * y1;
		const by = 3 * ( y2 - y1 ) - cy;
		const ay = 1 - cy - by;
		const sampleX = ( t ) => ( ( ax * t + bx ) * t + cx ) * t;
		const sampleY = ( t ) => ( ( ay * t + by ) * t + cy ) * t;

		const solveX = ( x ) => {
			let t = x;

			// Newton-Raphson; bisection when the slope is too flat.
			for ( let i = 0; i < 8; i++ ) {
				const error = sampleX( t ) - x;
				const slope = ( 3 * ax * t + 2 * bx ) * t + cx;

				if ( Math.abs( error ) < 1e-6 ) {
					return t;
				}

				if ( Math.abs( slope ) < 1e-6 ) {
					break;
				}

				t -= error / slope;
			}

			let lo = 0;
			let hi = 1;

			t = x;

			for ( let i = 0; i < 30 && hi - lo > 1e-7; i++ ) {
				if ( sampleX( t ) < x ) {
					lo = t;
				} else {
					hi = t;
				}

				t = ( lo + hi ) / 2;
			}

			return t;
		};

		return ( t ) => {
			if ( t <= 0 ) {
				return 0;
			}

			return t >= 1 ? 1 : sampleY( solveX( t ) );
		};
	}

	const ease = cubicBezier( 0.25, 0.1, 0.25, 1 ); // CSS "ease".

	/**
	 * Merges option objects one level deep (enough for DEFAULTS).
	 *
	 * @param {...Object} sources Option objects, later ones win.
	 * @return {Object} Merged options.
	 */
	function mergeOptions( ...sources ) {
		const result = {};

		sources.forEach( ( source ) => {
			if ( ! source || 'object' !== typeof source ) {
				return;
			}

			Object.keys( source ).forEach( ( key ) => {
				const value = source[ key ];

				if ( value && 'object' === typeof value && ! Array.isArray( value ) ) {
					result[ key ] = mergeOptions( result[ key ], value );
				} else if ( undefined !== value ) {
					result[ key ] = value;
				}
			} );
		} );

		return result;
	}

	function readDataOptions( root ) {
		try {
			return JSON.parse( root.getAttribute( 'data-lst-options' ) || '{}' );
		} catch ( error ) {
			return {};
		}
	}

	function isFocusVisible( element ) {
		try {
			return element.matches( ':focus-visible' );
		} catch ( error ) {
			return true; // Browsers without :focus-visible: treat every focus as keyboard focus.
		}
	}

	/**
	 * Turns a copy of a slide into a decorative clone.
	 *
	 * The clone is hidden from assistive technology and its content is made
	 * `inert` (not focusable, not selectable, skipped by find-in-page);
	 * focusables also get `tabindex="-1"` for browsers without `inert`. Inert
	 * content is skipped by hit-testing, so the pointer lands on the clone's
	 * slide element instead: `:hover` styles on the slide still apply, and the
	 * engine replays clicks on the original card (see forwardClick()).
	 *
	 * @param {HTMLElement} clone Cloned slide.
	 */
	function prepareClone( clone ) {
		clone.classList.add( CLASS.clone );
		clone.setAttribute( 'aria-hidden', 'true' );
		[ 'id', 'role', 'aria-roledescription', 'aria-label' ].forEach( ( attribute ) => clone.removeAttribute( attribute ) );
		clone.querySelectorAll( '[id]' ).forEach( ( element ) => element.removeAttribute( 'id' ) );
		clone.querySelectorAll( FOCUSABLE ).forEach( ( element ) => element.setAttribute( 'tabindex', '-1' ) );
		clone.querySelectorAll( '[fetchpriority]' ).forEach( ( element ) => element.removeAttribute( 'fetchpriority' ) );
		Array.from( clone.children ).forEach( ( child ) => child.setAttribute( 'inert', '' ) );
		clone.classList.toggle( CLASS.link, !! clone.querySelector( 'a[href]' ) );
	}

	/**
	 * Endless slider engine.
	 */
	class ListemannContinuousSlider {
		/**
		 * @param {HTMLElement} root    The `.lst-cslider` element.
		 * @param {Object}      options Optional overrides of the `data-lst-options` values.
		 */
		constructor( root, options ) {
			this.root = root;
			this.viewport = root ? root.querySelector( SELECTOR.viewport ) : null;
			this.track = root ? root.querySelector( SELECTOR.track ) : null;
			this.destroyed = ! this.viewport || ! this.track;

			if ( this.destroyed ) {
				return;
			}

			const previous = ListemannContinuousSlider.getInstance( root );

			if ( previous ) {
				previous.destroy();
			}

			instances.set( root, this );
			activeCount++;

			this.options = mergeOptions( DEFAULTS, readDataOptions( root ), options );

			// Discard stale clones, e.g. from markup copied off a running slider.
			Array.from( this.track.children ).forEach( ( child ) => {
				if ( child.classList.contains( CLASS.clone ) ) {
					child.remove();
				}
			} );

			this.slides = Array.from( this.track.children ).filter( ( child ) => child.matches( SELECTOR.slide ) );
			this.clones = [];
			this.cloneSets = 0;

			this.mode = '';
			this.pos = 0; // Track offset in px, rendered modulo setWidth.
			this.setWidth = 0; // Distance between a slide and its first clone.
			this.stepWidth = 0; // Slide width + gap.
			this.slideWidth = 0;
			this.viewportWidth = 0;
			this.rendered = null;

			this.glide = 1; // Continuous speed factor (0..1), eased on pause and resume.
			this.hovered = false;
			this.pauses = { focus: false, offscreen: false, hidden: false };
			this.reducedMotion = false;

			this.frame = 0;
			this.measureFrame = 0;
			this.lastTime = 0;
			this.tween = null;
			this.timer = 0;
			this.stepStart = 0; // Start time of the running autoplay step.
			this.drag = null;
			this.suppressClick = false;
			this.listeners = [];
			this.legacyMediaQuery = null;
			this.resizeObserver = null;
			this.intersectionObserver = null;

			[
				'tick',
				'onStepTimer',
				'onResize',
				'onIntersect',
				'onVisibilityChange',
				'onReducedMotionChange',
				'onPointerDown',
				'onPointerMove',
				'onPointerUp',
				'onLostPointerCapture',
				'onPointerEnter',
				'onPointerLeave',
				'onClick',
				'onDragStart',
				'onFocusIn',
				'onFocusOut',
				'onScroll',
			].forEach( ( method ) => {
				this[ method ] = this[ method ].bind( this );
			} );

			if ( this.slides.length ) {
				this.init();
			}
		}

		/**
		 * Returns the engine attached to a root element, if any.
		 *
		 * @param {HTMLElement} root Slider root.
		 * @return {ListemannContinuousSlider|null} Instance.
		 */
		static getInstance( root ) {
			return ( root && instances.get( root ) ) || null;
		}

		/**
		 * Number of running engines (created and not destroyed).
		 *
		 * @return {number} Count.
		 */
		static get activeCount() {
			return activeCount;
		}

		/**
		 * Creates engines for all sliders in a context that do not have one yet.
		 *
		 * @param {ParentNode} context Defaults to the document.
		 * @return {ListemannContinuousSlider[]} Created instances.
		 */
		static initAll( context = document ) {
			return Array.from( context.querySelectorAll( SELECTOR.root ) )
				.filter( ( root ) => ! ListemannContinuousSlider.getInstance( root ) )
				.map( ( root ) => new ListemannContinuousSlider( root ) );
		}

		init() {
			const mediaQuery = window.matchMedia ? window.matchMedia( '(prefers-reduced-motion: reduce)' ) : null;

			this.reducedMotion = !! ( mediaQuery && mediaQuery.matches );

			if ( mediaQuery && mediaQuery.addEventListener ) {
				this.listen( mediaQuery, 'change', this.onReducedMotionChange );
			} else if ( mediaQuery && mediaQuery.addListener ) {
				mediaQuery.addListener( this.onReducedMotionChange ); // Safari < 14.
				this.legacyMediaQuery = mediaQuery;
			}

			this.listen( this.viewport, 'pointerdown', this.onPointerDown );
			this.listen( this.viewport, 'pointermove', this.onPointerMove );
			this.listen( this.viewport, 'pointerup', this.onPointerUp );
			this.listen( this.viewport, 'pointercancel', this.onPointerUp );
			this.listen( this.viewport, 'lostpointercapture', this.onLostPointerCapture );
			this.listen( this.viewport, 'scroll', this.onScroll );
			this.listen( this.root, 'pointerenter', this.onPointerEnter );
			this.listen( this.root, 'pointerleave', this.onPointerLeave );
			this.listen( this.root, 'click', this.onClick, true );
			this.listen( this.root, 'auxclick', this.onClick, true );
			this.listen( this.root, 'dragstart', this.onDragStart );
			this.listen( this.root, 'focusin', this.onFocusIn );
			this.listen( this.root, 'focusout', this.onFocusOut );
			this.listen( document, 'visibilitychange', this.onVisibilityChange );

			if ( 'ResizeObserver' in window ) {
				// The root covers container resizes, the first slide covers CSS changes
				// (slides per view, gap) that alter the slide width.
				this.resizeObserver = new window.ResizeObserver( this.onResize );
				this.resizeObserver.observe( this.root );
				this.resizeObserver.observe( this.slides[ 0 ] );
			} else {
				this.listen( window, 'resize', this.onResize );
			}

			if ( 'IntersectionObserver' in window ) {
				this.intersectionObserver = new window.IntersectionObserver( this.onIntersect );
				this.intersectionObserver.observe( this.root );
			}

			this.pauses.hidden = !! document.hidden;
			this.refresh();
			this.root.classList.add( CLASS.ready );
		}

		listen( target, type, handler, options ) {
			target.addEventListener( type, handler, options );
			this.listeners.push( [ target, type, handler, options ] );
		}

		/**
		 * Re-reads the layout (CSS custom properties and sizes) while keeping the
		 * current progress. Call it after changing the slider's CSS.
		 */
		refresh() {
			if ( this.destroyed || ! this.slides.length ) {
				return;
			}

			const oldSetWidth = this.setWidth;
			const tweenTarget = this.tween ? this.tween.to : null;

			this.measure();

			if ( ! this.setWidth ) {
				return; // Not rendered (e.g. in a hidden tab); the observer fires again once visible.
			}

			if ( oldSetWidth ) {
				const ratio = this.setWidth / oldSetWidth;

				this.pos = mod( this.pos, oldSetWidth ) * ratio;

				if ( null !== tweenTarget ) {
					this.tween = null;
					this.pos = mod( tweenTarget * ratio, this.setWidth );
				}

				if ( this.drag && this.drag.active ) {
					this.drag.originPos *= ratio;
				}
			}

			const mode = this.readMode();

			if ( mode !== this.mode ) {
				this.setMode( mode );
			} else {
				if ( this.isSnapping() && ! this.drag ) {
					this.pos = this.snap( this.pos );
				}

				this.update();
			}

			this.render( true );
		}

		measure() {
			const count = this.slides.length;
			const rect = this.viewport.getBoundingClientRect();
			const layoutWidth = parseFloat( window.getComputedStyle( this.viewport ).width );

			// Bounding rects include ancestor transforms (e.g. a "zoom in" entrance
			// animation running while the slider initialises), the track offset
			// does not: convert measurements back to layout pixels.
			const scale = layoutWidth > 0 && Math.abs( rect.width / layoutWidth - 1 ) > 0.001 ? rect.width / layoutWidth : 1;
			const viewportWidth = rect.width / scale;
			const slideWidth = this.slides[ 0 ].getBoundingClientRect().width / scale;

			if ( ! viewportWidth || ! slideWidth ) {
				this.setWidth = 0;
				return;
			}

			const gap = parseFloat( window.getComputedStyle( this.track ).columnGap ) || 0;
			const estimate = slideWidth + gap;

			// Originals + clones must cover one full set plus the viewport at any offset.
			const sets = Math.max( 1, Math.ceil( ( viewportWidth + estimate + 1 ) / ( estimate * count ) ) );

			if ( sets !== this.cloneSets ) {
				this.buildClones( sets );
			} else {
				this.setSetPadding( 0 );
			}

			// Natural set width, measured from the layout rather than computed so it
			// matches the browser's own (sub-pixel) slide positions.
			const first = this.slides[ 0 ].getBoundingClientRect().left;
			const natural = ( this.clones[ 0 ].getBoundingClientRect().left - first ) / scale;

			if ( ! ( natural > 0 ) ) {
				this.setWidth = 0;
				return;
			}

			// Each clone set starts up to one device pixel later, so that a set spans
			// a whole number of device pixels: originals and clones then rasterise
			// identically and the wrap point is pixel-perfect.
			const density = window.devicePixelRatio || 1;
			const aligned = Math.ceil( natural * density - 0.001 ) / density;

			if ( aligned - natural > 0.001 ) {
				this.setSetPadding( aligned - natural );
			}

			this.setWidth = ( this.clones[ 0 ].getBoundingClientRect().left - first ) / scale;
			this.stepWidth = natural / count;
			this.slideWidth = slideWidth;
			this.viewportWidth = viewportWidth;
		}

		/**
		 * Adds space in front of the first slide of every clone set.
		 *
		 * @param {number} padding Extra space in px (less than one device pixel).
		 */
		setSetPadding( padding ) {
			const count = this.slides.length;

			this.clones.forEach( ( clone, index ) => {
				if ( 0 === index % count ) {
					clone.style.marginLeft = padding ? padding.toFixed( 4 ) + 'px' : '';
				}
			} );
		}

		/**
		 * Track offset of a slide index; index n is the first slide of the first
		 * clone set, and so on.
		 *
		 * @param {number} index Slide index (may be negative).
		 * @return {number} Offset in px.
		 */
		positionOf( index ) {
			const count = this.slides.length;
			const set = Math.floor( index / count );

			return set * this.setWidth + ( index - set * count ) * this.stepWidth;
		}

		/**
		 * Slide index at a track offset.
		 *
		 * @param {number}   pos      Offset in px.
		 * @param {Function} rounding Math.round, Math.floor or Math.ceil.
		 * @return {number} Slide index.
		 */
		indexAt( pos, rounding = Math.round ) {
			const count = this.slides.length;
			const set = Math.floor( pos / this.setWidth );
			const local = ( pos - set * this.setWidth ) / this.stepWidth;

			return set * count + Math.min( rounding( local ), count );
		}

		buildClones( sets ) {
			const fragment = document.createDocumentFragment();

			this.removeClones();

			for ( let set = 0; set < sets; set++ ) {
				this.slides.forEach( ( slide ) => {
					const clone = slide.cloneNode( true );

					prepareClone( clone );
					this.clones.push( clone );
					fragment.appendChild( clone );
				} );
			}

			this.track.appendChild( fragment );
			this.cloneSets = sets;
		}

		removeClones() {
			this.clones.forEach( ( clone ) => clone.remove() );
			this.clones = [];
			this.cloneSets = 0;
		}

		readMode() {
			const style = window.getComputedStyle( this.root );
			const value = ( style.getPropertyValue( '--_mode' ) || style.getPropertyValue( '--lst-mode' ) ).trim().toLowerCase();

			return MODES.includes( value ) ? value : 'continuous';
		}

		setMode( mode ) {
			this.mode = mode;
			this.tween = null;
			this.stepStart = 0;
			this.clearTimer();

			MODES.forEach( ( name ) => this.root.classList.toggle( 'is-mode-' + name, name === mode ) );

			if ( this.isSnapping() ) {
				this.pos = this.snap( this.pos );
			}

			this.glide = 0; // Ease in when the glide starts.
			this.update();
		}

		modeOptions() {
			return this.options[ this.mode ] || {};
		}

		direction() {
			return 'right' === this.options.direction ? -1 : 1;
		}

		isSnapping() {
			return 'continuous' !== this.mode;
		}

		isDraggable() {
			if ( ! this.mode ) {
				return false;
			}

			// With reduced motion nothing moves on its own, so dragging keeps every slide reachable.
			return !! this.modeOptions().draggable || ( this.reducedMotion && 'none' !== this.mode );
		}

		canAutoplay() {
			return 'none' !== this.mode && ! this.reducedMotion && ! this.pauses.offscreen && ! this.pauses.hidden;
		}

		isHeld() {
			return !! ( ( this.hovered && this.modeOptions().pauseOnHover ) || this.pauses.focus || ( this.drag && this.drag.active ) );
		}

		snap( pos ) {
			return this.setWidth ? this.positionOf( this.indexAt( pos ) ) : pos;
		}

		/**
		 * Synchronises classes, the step timer and the animation loop with the
		 * current state.
		 */
		update() {
			if ( this.destroyed ) {
				return;
			}

			this.root.classList.toggle( CLASS.draggable, this.isDraggable() );

			const autoplay = 'step' === this.mode && this.canAutoplay() && ! this.isHeld() && ! this.drag;

			if ( autoplay && ! this.tween ) {
				if ( ! this.timer ) {
					// Steps start one interval apart (the transition is part of the
					// interval, as with slick's autoplaySpeed); after a pause the
					// next step waits a full interval.
					const interval = toNumber( this.options.step.interval, DEFAULTS.step.interval, 100 );
					const elapsed = this.stepStart ? now() - this.stepStart : 0;

					this.timer = window.setTimeout( this.onStepTimer, clamp( interval - elapsed, 0, interval ) );
				}
			} else {
				this.clearTimer();

				if ( ! autoplay ) {
					this.stepStart = 0;
				}
			}

			this.requestTick();
		}

		requestTick() {
			if ( ! this.frame && ! this.destroyed ) {
				this.frame = raf( this.tick );
			}
		}

		tick( time ) {
			const delta = this.lastTime ? Math.min( time - this.lastTime, MAX_FRAME_DELTA ) : 0;
			let active = false;

			this.frame = 0;
			this.lastTime = time;

			if ( this.tween ) {
				active = this.advanceTween( time );
			} else if ( 'continuous' === this.mode && this.setWidth && this.canAutoplay() && ! ( this.drag && this.drag.active ) ) {
				const target = this.isHeld() ? 0 : 1;

				if ( this.glide < target ) {
					this.glide = Math.min( target, this.glide + delta / RAMP_DURATION );
				} else if ( this.glide > target ) {
					this.glide = Math.max( target, this.glide - delta / RAMP_DURATION );
				}

				// One step (slide + gap) per `speed` seconds.
				const speed = this.stepWidth / toNumber( this.options.continuous.speed, DEFAULTS.continuous.speed, 0.1 );

				this.pos = mod( this.pos + this.direction() * speed * smoothstep( this.glide ) * ( delta / 1000 ), this.setWidth );
				active = this.glide > 0 || target > 0;
			}

			this.render();

			if ( active ) {
				this.requestTick();
			} else if ( ! this.frame ) {
				this.lastTime = 0;
			}
		}

		render( force ) {
			if ( ! this.setWidth ) {
				return;
			}

			const offset = mod( this.pos, this.setWidth );

			if ( ! force && offset === this.rendered ) {
				return;
			}

			this.rendered = offset;
			// Sub-pixel offsets are kept (smooth motion); toFixed() avoids exponent
			// notation such as "1e-7px", which CSS would reject.
			this.track.style.transform = 'translate3d(' + ( -offset ).toFixed( 3 ) + 'px, 0, 0)';
		}

		animateTo( target, duration ) {
			this.clearTimer();

			if ( ! ( duration > 0 ) ) {
				this.tween = null;
				this.pos = mod( target, this.setWidth );
				this.render();
				this.update();
				return;
			}

			this.tween = { from: this.pos, to: target, start: now(), duration };
			this.requestTick();
		}

		advanceTween( time ) {
			const tween = this.tween;
			const progress = clamp( ( time - tween.start ) / tween.duration, 0, 1 );

			this.pos = tween.from + ( tween.to - tween.from ) * ease( progress );

			if ( progress < 1 ) {
				return true;
			}

			this.tween = null;
			this.pos = mod( tween.to, this.setWidth );
			this.update();

			return false;
		}

		clearTimer() {
			if ( this.timer ) {
				window.clearTimeout( this.timer );
				this.timer = 0;
			}
		}

		onStepTimer() {
			this.timer = 0;

			if ( 'step' !== this.mode || ! this.setWidth || ! this.canAutoplay() || this.isHeld() || this.drag ) {
				this.update();
				return;
			}

			this.stepStart = now();
			this.animateTo( this.positionOf( this.indexAt( this.pos ) + this.direction() ), this.snapDuration() );
		}

		snapDuration() {
			return toNumber( this.options.step.duration, DEFAULTS.step.duration, 0 );
		}

		/* Pointer: drag, swipe and hover ---------------------------------- */

		onPointerDown( event ) {
			this.suppressClick = false;

			if ( ! this.isDraggable() || ! this.setWidth || false === event.isPrimary ) {
				return;
			}

			if ( 'mouse' === event.pointerType && 0 !== event.button ) {
				return;
			}

			this.drag = {
				id: event.pointerId,
				startX: event.clientX,
				startY: event.clientY,
				active: false,
				originX: 0,
				originPos: 0,
				lastX: event.clientX,
				lastTime: event.timeStamp,
				velocity: 0,
			};
		}

		onPointerMove( event ) {
			const drag = this.drag;

			if ( ! drag || event.pointerId !== drag.id ) {
				return;
			}

			if ( ! drag.active ) {
				const dx = event.clientX - drag.startX;
				const dy = event.clientY - drag.startY;

				if ( Math.abs( dx ) < DRAG_THRESHOLD ) {
					if ( Math.abs( dy ) >= DRAG_THRESHOLD ) {
						this.drag = null; // Vertical intent: leave the gesture to the page.
					}

					return;
				}

				if ( Math.abs( dy ) > Math.abs( dx ) ) {
					this.drag = null;
					return;
				}

				this.startDrag( event );
			}

			const elapsed = event.timeStamp - drag.lastTime;

			if ( elapsed > 0 ) {
				drag.velocity = 0.8 * ( ( event.clientX - drag.lastX ) / elapsed ) + 0.2 * drag.velocity;
			}

			drag.lastX = event.clientX;
			drag.lastTime = event.timeStamp;

			this.pos = drag.originPos - ( event.clientX - drag.originX );
			this.requestTick();
		}

		startDrag( event ) {
			const drag = this.drag;

			drag.active = true;
			drag.originX = event.clientX;
			drag.originPos = this.pos;
			drag.lastX = event.clientX;
			drag.lastTime = event.timeStamp;

			this.tween = null;
			this.glide = 0;
			this.suppressClick = true;
			this.root.classList.add( CLASS.dragging );

			try {
				this.viewport.setPointerCapture( drag.id );
			} catch ( error ) {
				// The pointer is already gone; pointerup/pointercancel ends the drag.
			}

			this.update();
		}

		onPointerUp( event ) {
			const drag = this.drag;

			if ( ! drag || event.pointerId !== drag.id ) {
				return;
			}

			this.drag = null;

			if ( ! drag.active ) {
				return;
			}

			this.root.classList.remove( CLASS.dragging );

			try {
				if ( this.viewport.hasPointerCapture( drag.id ) ) {
					this.viewport.releasePointerCapture( drag.id );
				}
			} catch ( error ) {
				// Capture already released.
			}

			if ( this.isSnapping() ) {
				const velocity = event.timeStamp - drag.lastTime > 100 ? 0 : drag.velocity;
				let rounding = Math.round;

				if ( velocity < -FLICK_VELOCITY ) {
					rounding = Math.ceil; // Flicked left: next slide.
				} else if ( velocity > FLICK_VELOCITY ) {
					rounding = Math.floor; // Flicked right: previous slide.
				}

				this.animateTo( this.positionOf( this.indexAt( this.pos, rounding ) ), this.snapDuration() );
			} else {
				this.pos = mod( this.pos, this.setWidth );
			}

			this.update();
		}

		onLostPointerCapture( event ) {
			// Ignore the implicit (touch) capture of a child moving to the viewport.
			if ( event.target === this.viewport ) {
				this.onPointerUp( event );
			}
		}

		onClick( event ) {
			if ( this.suppressClick && 'click' === event.type ) {
				this.suppressClick = false;
				event.preventDefault();
				event.stopPropagation();
				return;
			}

			this.forwardClick( event );
		}

		/**
		 * Replays a click on a clone on the corresponding original card, so the
		 * link opens (and listeners on the original run) as if the original had
		 * been clicked. Clicks that ask for a new tab or window (middle button,
		 * Ctrl, Cmd or Shift) open the link with window.open(), because browsers
		 * apply those modifiers only to trusted events.
		 *
		 * @param {MouseEvent} event `click` or `auxclick` event.
		 */
		forwardClick( event ) {
			if ( ! this.options.forwardClicks || event.defaultPrevented || ! event.target || ! event.target.closest ) {
				return;
			}

			const index = this.clones.indexOf( event.target.closest( SELECTOR.slide ) );
			const card = index < 0 ? null : this.slides[ index % this.slides.length ].querySelector( SELECTOR.card );

			if ( ! card ) {
				return;
			}

			const middle = 'auxclick' === event.type;

			if ( middle && 1 !== event.button ) {
				return; // Right button and others: nothing to replay.
			}

			if ( middle || event.ctrlKey || event.metaKey || event.shiftKey ) {
				const href = card.matches( 'a[href]' ) ? card.href : '';

				if ( /^https?:/i.test( href ) ) {
					event.preventDefault();
					window.open( href, '_blank', 'noopener' );
				}

				return;
			}

			event.preventDefault();
			card.dispatchEvent(
				new window.MouseEvent( 'click', {
					bubbles: true,
					cancelable: true,
					view: window,
					detail: event.detail,
					screenX: event.screenX,
					screenY: event.screenY,
					clientX: event.clientX,
					clientY: event.clientY,
					altKey: event.altKey,
					button: 0,
				} )
			);
		}

		onDragStart( event ) {
			event.preventDefault(); // No native image or link drag ghost.
		}

		onPointerEnter( event ) {
			if ( 'touch' !== event.pointerType ) {
				this.hovered = true;
				this.update();
			}
		}

		onPointerLeave( event ) {
			if ( 'touch' !== event.pointerType ) {
				this.hovered = false;
				this.update();
			}
		}

		/* Keyboard focus -------------------------------------------------- */

		onFocusIn( event ) {
			const slide = event.target.closest ? event.target.closest( SELECTOR.slide ) : null;

			this.resetScroll();

			if ( ! slide || slide.classList.contains( CLASS.clone ) || ! isFocusVisible( event.target ) ) {
				return;
			}

			this.pauses.focus = true;
			this.glide = 0;
			this.reveal( slide );
			this.update();
		}

		onFocusOut( event ) {
			if ( event.relatedTarget && this.root.contains( event.relatedTarget ) ) {
				return;
			}

			if ( this.pauses.focus ) {
				this.pauses.focus = false;
				this.update();
			}
		}

		/**
		 * Moves the strip so that an original slide is fully visible.
		 *
		 * @param {HTMLElement} slide Original (non-cloned) slide.
		 */
		reveal( slide ) {
			const index = this.slides.indexOf( slide );

			if ( index < 0 || ! this.setWidth ) {
				return;
			}

			// Where the strip comes to rest (a running step transition finishes first).
			const settled = mod( this.tween ? this.tween.to : this.pos, this.setWidth );
			const start = index * this.stepWidth;
			const end = start + this.slideWidth;

			if ( start >= settled - 0.5 && end <= settled + this.viewportWidth + 0.5 ) {
				return;
			}

			let target = start;

			if ( start > settled ) {
				target = Math.max( 0, end - this.viewportWidth );

				if ( this.isSnapping() ) {
					target = this.positionOf( this.indexAt( target - 0.5, Math.ceil ) );
				}

				target = Math.min( start, target ); // A slide wider than the viewport aligns left.
			}

			this.pos = mod( this.pos, this.setWidth );
			this.animateTo( target, this.reducedMotion ? 0 : FOCUS_DURATION );
		}

		onScroll() {
			this.resetScroll();
		}

		/**
		 * Undoes scrolling of the viewport by the browser (focus, find-in-page)
		 * where `overflow: clip` is not supported.
		 */
		resetScroll() {
			if ( this.viewport.scrollLeft ) {
				this.viewport.scrollLeft = 0;
			}
		}

		/* Environment ----------------------------------------------------- */

		onResize() {
			if ( ! this.measureFrame && ! this.destroyed ) {
				this.measureFrame = raf( () => {
					this.measureFrame = 0;
					this.refresh();
				} );
			}
		}

		onIntersect( entries ) {
			this.pauses.offscreen = ! entries[ entries.length - 1 ].isIntersecting;
			this.update();
		}

		onVisibilityChange() {
			this.pauses.hidden = !! document.hidden;
			this.update();
		}

		onReducedMotionChange( event ) {
			this.reducedMotion = !! event.matches;

			if ( this.reducedMotion && this.isSnapping() && ! this.tween && ! this.drag ) {
				this.pos = this.snap( this.pos );
				this.render();
			}

			this.update();
		}

		/**
		 * Current position as a fraction (0..1) of one slide set.
		 *
		 * @return {number} Progress.
		 */
		getProgress() {
			return this.setWidth ? mod( this.pos, this.setWidth ) / this.setWidth : 0;
		}

		/**
		 * Jumps to a position given as a fraction (0..1) of one slide set.
		 *
		 * @param {number} progress Progress.
		 */
		setProgress( progress ) {
			if ( ! this.setWidth || ! Number.isFinite( progress ) ) {
				return;
			}

			this.tween = null;
			this.pos = mod( progress, 1 ) * this.setWidth;

			if ( this.isSnapping() ) {
				this.pos = this.snap( this.pos );
			}

			this.render();
		}

		/**
		 * Stops all activity and restores the server-rendered markup.
		 */
		destroy() {
			if ( this.destroyed ) {
				return;
			}

			this.destroyed = true;
			activeCount--;

			if ( this.frame ) {
				caf( this.frame );
			}

			if ( this.measureFrame ) {
				caf( this.measureFrame );
			}

			this.clearTimer();
			this.frame = 0;
			this.measureFrame = 0;
			this.tween = null;
			this.drag = null;

			if ( this.resizeObserver ) {
				this.resizeObserver.disconnect();
			}

			if ( this.intersectionObserver ) {
				this.intersectionObserver.disconnect();
			}

			if ( this.legacyMediaQuery ) {
				this.legacyMediaQuery.removeListener( this.onReducedMotionChange );
			}

			this.listeners.forEach( ( [ target, type, handler, options ] ) => target.removeEventListener( type, handler, options ) );
			this.listeners = [];

			this.removeClones();
			this.track.style.removeProperty( 'transform' );
			this.root.classList.remove( CLASS.ready, CLASS.draggable, CLASS.dragging, ...MODES.map( ( name ) => 'is-mode-' + name ) );

			if ( instances.get( this.root ) === this ) {
				instances.delete( this.root );
			}
		}
	}

	window.ListemannContinuousSlider = ListemannContinuousSlider;

	/* Elementor integration --------------------------------------------- */

	const WIDGET = 'listemann-continuous-slider';

	// Settings that only change CSS custom properties: re-measure instead of re-render.
	const LAYOUT_SETTING = /^(slides_per_view|gap|height|motion)(_|$)/;

	// Keeps the position across editor re-renders, keyed by element model cid.
	const editorProgress = new Map();

	function createElementorHandler() {
		const handlers = window.elementorModules && elementorModules.frontend && elementorModules.frontend.handlers;

		if ( ! handlers || ! handlers.Base ) {
			return null;
		}

		// Elementor calls onInit() from inside the Base constructor, so instance
		// state is set there; class fields would be initialised too late.
		return class ListemannContinuousSliderHandler extends handlers.Base {
			onInit( ...args ) {
				super.onInit( ...args );

				const root = this.$element[ 0 ].querySelector( SELECTOR.root );

				// Links must not open from the editor preview.
				this.slider = root ? new ListemannContinuousSlider( root, { forwardClicks: ! this.isEdit } ) : null;
				this.settingsModel = null;
				this.refreshFrame = 0;

				if ( ! this.slider || ! this.isEdit ) {
					return;
				}

				const cid = this.getModelCID();

				if ( editorProgress.has( cid ) ) {
					this.slider.setProgress( editorProgress.get( cid ) );
				}

				// Settings that render through CSS custom properties do not re-render
				// the widget. Listening to the settings model (rather than only to
				// panel changes) also covers undo/redo and changes made via $e.run().
				const model = elementorFrontend.config.elements.data[ cid ];

				if ( model && 'function' === typeof model.on ) {
					this.settingsModel = model;
					this.onSettingsChange = this.onSettingsChange.bind( this );
					model.on( 'change', this.onSettingsChange );
				}
			}

			onSettingsChange( model ) {
				const changed = Object.keys( ( model && model.changed ) || {} );

				if ( ! this.slider || this.refreshFrame || ! changed.some( ( key ) => LAYOUT_SETTING.test( key ) ) ) {
					return;
				}

				// The editor injects the regenerated CSS in this tick; measure on the next frame.
				this.refreshFrame = raf( () => {
					this.refreshFrame = 0;

					if ( this.slider ) {
						this.slider.refresh();
					}
				} );
			}

			onDestroy( ...args ) {
				if ( this.settingsModel ) {
					this.settingsModel.off( 'change', this.onSettingsChange );
					this.settingsModel = null;
				}

				if ( this.refreshFrame ) {
					caf( this.refreshFrame );
					this.refreshFrame = 0;
				}

				if ( this.slider ) {
					if ( this.isEdit ) {
						editorProgress.set( this.getModelCID(), this.slider.getProgress() );
					}

					this.slider.destroy();
					this.slider = null;
				}

				super.onDestroy( ...args );
			}
		};
	}

	function registerWithElementor() {
		const frontend = window.elementorFrontend;
		const Handler = createElementorHandler();

		if ( ! Handler || ! frontend || ! frontend.elementsHandler ) {
			return null;
		}

		frontend.elementsHandler.attachHandler( WIDGET, Handler );

		return Handler;
	}

	if ( window.elementorFrontend && window.elementorFrontend.elementsHandler ) {
		// Loaded after Elementor's frontend initialised (e.g. delayed by an
		// optimisation plugin): attach the handler and start existing widgets.
		const Handler = registerWithElementor();

		if ( Handler && window.jQuery ) {
			document.querySelectorAll( '.elementor-widget-' + WIDGET ).forEach( ( element ) => {
				window.elementorFrontend.elementsHandler.addHandler( Handler, {
					$element: window.jQuery( element ),
					elementName: WIDGET + '.default',
				} );
			} );
		}
	} else {
		window.addEventListener( 'elementor/frontend/init', registerWithElementor );
	}

	// Without Elementor's frontend (or when it failed to start) run standalone.
	window.addEventListener( 'load', () => {
		const frontend = window.elementorFrontend;

		try {
			if ( frontend && frontend.isEditMode() ) {
				return;
			}
		} catch ( error ) {
			// Not Elementor's frontend object; fall through.
		}

		if ( ! frontend || ! frontend.elementsHandler ) {
			ListemannContinuousSlider.initAll();
		}
	} );
}() );
