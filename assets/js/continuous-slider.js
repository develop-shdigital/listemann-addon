/**
 * Listemann Addon: Continuous Slider.
 *
 * `ListemannContinuousSlider` is a dependency-free engine that turns the
 * `.lst-cslider` markup into an endless strip. The server renders every slide
 * once; the engine clones the set as often as the viewport needs and moves the
 * track with translate3d(), wrapping the offset modulo one set width.
 *
 * Modes (read from the `--lst-mode` custom property, so they follow
 * Elementor's breakpoints):
 * - continuous: linear glide driven by requestAnimationFrame,
 * - step:       advances one slide per interval with an eased transition,
 * - none:       static strip.
 *
 * A thin Elementor frontend handler at the bottom creates and destroys engine
 * instances for the `listemann-continuous-slider` widget.
 */
( function () {
	'use strict';

	var SELECTOR = {
		root: '.lst-cslider',
		viewport: '.lst-cslider__viewport',
		track: '.lst-cslider__track',
		slide: '.lst-cslider__slide',
	};

	var CLASS = {
		ready: 'is-ready',
		clone: 'is-clone',
		draggable: 'is-draggable',
		dragging: 'is-dragging',
	};

	var MODES = [ 'continuous', 'step', 'none' ];

	var DEFAULTS = {
		direction: 'left',
		continuous: { speed: 6, pauseOnHover: false, draggable: false },
		step: { interval: 3500, duration: 300, pauseOnHover: true, draggable: true },
		none: { draggable: false },
	};

	var FOCUSABLE = 'a[href], area[href], button, input, select, textarea, iframe, summary, [tabindex], [contenteditable]';

	var DRAG_THRESHOLD = 6; // px a press has to travel before it becomes a drag.
	var FLICK_VELOCITY = 0.3; // px/ms; a faster release moves on to the next slide.
	var RAMP_DURATION = 600; // ms to ease the glide to a stop and back to full speed.
	var MAX_FRAME_DELTA = 100; // ms; caps the time step after a stalled frame.
	var FOCUS_DURATION = 300; // ms to bring a keyboard-focused slide into view.

	var instances = typeof WeakMap === 'function' ? new WeakMap() : null;

	var raf = window.requestAnimationFrame
		? window.requestAnimationFrame.bind( window )
		: function ( callback ) {
			return window.setTimeout( function () {
				callback( now() );
			}, 16 );
		};

	var caf = window.cancelAnimationFrame ? window.cancelAnimationFrame.bind( window ) : window.clearTimeout.bind( window );

	function now() {
		return window.performance && window.performance.now ? window.performance.now() : Date.now();
	}

	function mod( value, length ) {
		return ( ( value % length ) + length ) % length;
	}

	function clamp( value, min, max ) {
		return Math.min( Math.max( value, min ), max );
	}

	function smoothstep( t ) {
		return t * t * ( 3 - 2 * t );
	}

	/**
	 * Returns an easing function equivalent to CSS cubic-bezier().
	 *
	 * @param {number} x1
	 * @param {number} y1
	 * @param {number} x2
	 * @param {number} y2
	 * @return {Function} t (0..1) => progress (0..1).
	 */
	function cubicBezier( x1, y1, x2, y2 ) {
		var cx = 3 * x1;
		var bx = 3 * ( x2 - x1 ) - cx;
		var ax = 1 - cx - bx;
		var cy = 3 * y1;
		var by = 3 * ( y2 - y1 ) - cy;
		var ay = 1 - cy - by;

		function sampleX( t ) {
			return ( ( ax * t + bx ) * t + cx ) * t;
		}

		function sampleY( t ) {
			return ( ( ay * t + by ) * t + cy ) * t;
		}

		function solveX( x ) {
			var t = x;
			var i;
			var lo = 0;
			var hi = 1;

			// Newton-Raphson, then bisection as a fallback.
			for ( i = 0; i < 8; i++ ) {
				var error = sampleX( t ) - x;
				var slope = ( 3 * ax * t + 2 * bx ) * t + cx;

				if ( Math.abs( error ) < 1e-6 ) {
					return t;
				}

				if ( Math.abs( slope ) < 1e-6 ) {
					break;
				}

				t -= error / slope;
			}

			t = x;

			for ( i = 0; i < 30 && hi - lo > 1e-7; i++ ) {
				if ( sampleX( t ) < x ) {
					lo = t;
				} else {
					hi = t;
				}

				t = ( lo + hi ) / 2;
			}

			return t;
		}

		return function ( t ) {
			if ( t <= 0 ) {
				return 0;
			}

			return t >= 1 ? 1 : sampleY( solveX( t ) );
		};
	}

	var ease = cubicBezier( 0.25, 0.1, 0.25, 1 ); // CSS "ease".

	function toNumber( value, fallback, min ) {
		var number = parseFloat( value );

		return isFinite( number ) ? Math.max( number, min ) : fallback;
	}

	/**
	 * Merges option objects one level deep (enough for DEFAULTS).
	 *
	 * @return {Object} Merged options.
	 */
	function mergeOptions() {
		var result = {};

		Array.prototype.forEach.call( arguments, function ( source ) {
			if ( ! source || 'object' !== typeof source ) {
				return;
			}

			Object.keys( source ).forEach( function ( key ) {
				var value = source[ key ];

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
			return true;
		}
	}

	/**
	 * Hides a cloned slide from assistive technology and the tab order.
	 *
	 * Clones are deliberately not made `inert`: an inert element is skipped by
	 * hit-testing, so clicks and hover on the (mostly visible) clones would not
	 * work.
	 *
	 * @param {HTMLElement} clone Cloned slide.
	 */
	function prepareClone( clone ) {
		clone.classList.add( CLASS.clone );
		clone.setAttribute( 'aria-hidden', 'true' );
		[ 'id', 'role', 'aria-roledescription', 'aria-label' ].forEach( function ( attribute ) {
			clone.removeAttribute( attribute );
		} );
		Array.prototype.forEach.call( clone.querySelectorAll( '[id]' ), function ( element ) {
			element.removeAttribute( 'id' );
		} );
		Array.prototype.forEach.call( clone.querySelectorAll( FOCUSABLE ), function ( element ) {
			element.setAttribute( 'tabindex', '-1' );
		} );
	}

	/**
	 * Endless slider engine.
	 *
	 * @param {HTMLElement} root    The `.lst-cslider` element.
	 * @param {Object}      options Optional overrides of the `data-lst-options` values.
	 */
	function ListemannContinuousSlider( root, options ) {
		if ( ! ( this instanceof ListemannContinuousSlider ) ) {
			return new ListemannContinuousSlider( root, options );
		}

		this.root = root;
		this.viewport = root && root.querySelector( SELECTOR.viewport );
		this.track = root && root.querySelector( SELECTOR.track );
		this.destroyed = ! this.viewport || ! this.track;

		if ( this.destroyed ) {
			return;
		}

		var previous = ListemannContinuousSlider.getInstance( root );

		if ( previous ) {
			previous.destroy();
		}

		if ( instances ) {
			instances.set( root, this );
		}

		this.options = mergeOptions( DEFAULTS, readDataOptions( root ), options );

		// Stale clones (e.g. markup copied from a live instance) are discarded.
		Array.prototype.slice.call( this.track.children ).forEach( function ( child ) {
			if ( child.classList.contains( CLASS.clone ) ) {
				child.remove();
			}
		} );

		this.slides = Array.prototype.filter.call( this.track.children, function ( child ) {
			return child.matches( SELECTOR.slide );
		} );
		this.clones = [];
		this.cloneSets = 0;

		this.mode = '';
		this.pos = 0; // Track offset in px. Rendered modulo setWidth.
		this.setWidth = 0; // Distance between a slide and its first clone.
		this.stepWidth = 0; // Slide width + gap.
		this.slideWidth = 0;
		this.viewportWidth = 0;
		this.rendered = null;

		this.glide = 1; // Continuous speed factor (0..1), eased on pause/resume.
		this.hovered = false;
		this.pauses = {}; // focus | offscreen | hidden.
		this.reducedMotion = false;

		this.frame = 0;
		this.measureFrame = 0;
		this.lastTime = 0;
		this.tween = null;
		this.timer = 0;
		this.drag = null;
		this.suppressClick = false;
		this.listeners = [];

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
			'onPointerEnter',
			'onPointerLeave',
			'onClick',
			'onDragStart',
			'onFocusIn',
			'onFocusOut',
			'onScroll',
		].forEach( function ( method ) {
			this[ method ] = this[ method ].bind( this );
		}, this );

		if ( this.slides.length ) {
			this.init();
		}
	}

	/**
	 * Returns the engine attached to a root element, if any.
	 *
	 * @param {HTMLElement} root
	 * @return {ListemannContinuousSlider|null} Instance.
	 */
	ListemannContinuousSlider.getInstance = function ( root ) {
		return ( instances && root && instances.get( root ) ) || null;
	};

	/**
	 * Creates engines for all sliders in a context that do not have one yet.
	 *
	 * @param {ParentNode} context Defaults to the document.
	 * @return {ListemannContinuousSlider[]} Created instances.
	 */
	ListemannContinuousSlider.initAll = function ( context ) {
		var created = [];

		Array.prototype.forEach.call( ( context || document ).querySelectorAll( SELECTOR.root ), function ( root ) {
			if ( ! ListemannContinuousSlider.getInstance( root ) ) {
				created.push( new ListemannContinuousSlider( root ) );
			}
		} );

		return created;
	};

	ListemannContinuousSlider.prototype = {
		constructor: ListemannContinuousSlider,

		init: function () {
			var mediaQuery = window.matchMedia ? window.matchMedia( '(prefers-reduced-motion: reduce)' ) : null;

			this.reducedMotion = !! ( mediaQuery && mediaQuery.matches );

			if ( mediaQuery ) {
				if ( mediaQuery.addEventListener ) {
					this.listen( mediaQuery, 'change', this.onReducedMotionChange );
				} else if ( mediaQuery.addListener ) {
					mediaQuery.addListener( this.onReducedMotionChange );
					this.legacyMediaQuery = mediaQuery;
				}
			}

			this.listen( this.viewport, 'pointerdown', this.onPointerDown );
			this.listen( this.viewport, 'pointermove', this.onPointerMove );
			this.listen( this.viewport, 'pointerup', this.onPointerUp );
			this.listen( this.viewport, 'pointercancel', this.onPointerUp );
			this.listen( this.viewport, 'lostpointercapture', this.onPointerUp );
			this.listen( this.viewport, 'scroll', this.onScroll );
			this.listen( this.root, 'pointerenter', this.onPointerEnter );
			this.listen( this.root, 'pointerleave', this.onPointerLeave );
			this.listen( this.root, 'click', this.onClick, true );
			this.listen( this.root, 'dragstart', this.onDragStart );
			this.listen( this.root, 'focusin', this.onFocusIn );
			this.listen( this.root, 'focusout', this.onFocusOut );
			this.listen( document, 'visibilitychange', this.onVisibilityChange );

			if ( 'ResizeObserver' in window ) {
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
		},

		listen: function ( target, type, handler, options ) {
			target.addEventListener( type, handler, options );
			this.listeners.push( [ target, type, handler, options ] );
		},

		/**
		 * Re-reads the layout (CSS custom properties, sizes) and keeps the
		 * current progress. Call it after changing the slider's CSS.
		 */
		refresh: function () {
			if ( this.destroyed || ! this.slides.length ) {
				return;
			}

			var oldSetWidth = this.setWidth;
			var tweenTarget = this.tween ? this.tween.to : null;

			this.measure();

			if ( ! this.setWidth ) {
				return; // Not rendered (e.g. inside a hidden tab); the observer fires again once visible.
			}

			if ( oldSetWidth ) {
				var ratio = this.setWidth / oldSetWidth;

				this.pos = mod( this.pos, oldSetWidth ) * ratio;

				if ( null !== tweenTarget ) {
					this.tween = null;
					this.pos = tweenTarget * ratio;
				}

				if ( this.drag && this.drag.active ) {
					this.drag.originPos *= ratio;
				}
			}

			var mode = this.readMode();

			if ( mode !== this.mode ) {
				this.setMode( mode );
			} else {
				if ( this.isSnapping() && ! this.drag ) {
					this.pos = this.snap( this.pos );
				}

				this.update();
			}

			this.render( true );
		},

		measure: function () {
			var count = this.slides.length;
			var viewportWidth = this.viewport.getBoundingClientRect().width;
			var slideWidth = this.slides[ 0 ].getBoundingClientRect().width;

			if ( ! viewportWidth || ! slideWidth ) {
				this.setWidth = 0;
				return;
			}

			var gap = parseFloat( window.getComputedStyle( this.track ).columnGap ) || 0;
			var stepWidth = slideWidth + gap;

			// The strip must cover one full set plus the viewport at any offset.
			var sets = Math.max( 1, Math.ceil( ( viewportWidth + stepWidth ) / ( stepWidth * count ) ) );

			if ( sets !== this.cloneSets ) {
				this.buildClones( sets );
			}

			// Exact (sub-pixel) distance between a slide and its first clone, so the
			// wrap point lines up with the layout engine's own positions.
			var setWidth = this.clones[ 0 ].getBoundingClientRect().left - this.slides[ 0 ].getBoundingClientRect().left;

			this.setWidth = setWidth > 0 ? setWidth : stepWidth * count;
			this.stepWidth = this.setWidth / count;
			this.slideWidth = slideWidth;
			this.viewportWidth = viewportWidth;
		},

		buildClones: function ( sets ) {
			var fragment = document.createDocumentFragment();
			var set;

			this.removeClones();

			for ( set = 0; set < sets; set++ ) {
				this.slides.forEach( function ( slide ) {
					var clone = slide.cloneNode( true );

					prepareClone( clone );
					this.clones.push( clone );
					fragment.appendChild( clone );
				}, this );
			}

			this.track.appendChild( fragment );
			this.cloneSets = sets;
		},

		removeClones: function () {
			this.clones.forEach( function ( clone ) {
				if ( clone.parentNode ) {
					clone.parentNode.removeChild( clone );
				}
			} );
			this.clones = [];
			this.cloneSets = 0;
		},

		readMode: function () {
			var style = window.getComputedStyle( this.root );
			var value = ( style.getPropertyValue( '--_mode' ) || style.getPropertyValue( '--lst-mode' ) ).trim().toLowerCase();

			return MODES.indexOf( value ) > -1 ? value : 'continuous';
		},

		setMode: function ( mode ) {
			this.mode = mode;
			this.tween = null;
			this.clearTimer();

			MODES.forEach( function ( name ) {
				this.root.classList.toggle( 'is-mode-' + name, name === mode );
			}, this );

			if ( this.isSnapping() ) {
				this.pos = this.snap( this.pos );
			}

			this.glide = 0; // Ease in when switching to the continuous glide.
			this.update();
		},

		modeOptions: function () {
			return this.options[ this.mode ] || {};
		},

		direction: function () {
			return 'right' === this.options.direction ? -1 : 1;
		},

		isSnapping: function () {
			return 'continuous' !== this.mode;
		},

		isDraggable: function () {
			if ( ! this.mode ) {
				return false;
			}

			// With reduced motion nothing moves on its own, so dragging keeps all slides reachable.
			return !! this.modeOptions().draggable || ( this.reducedMotion && 'none' !== this.mode );
		},

		canAutoplay: function () {
			return 'none' !== this.mode && ! this.reducedMotion && ! this.pauses.offscreen && ! this.pauses.hidden;
		},

		isHeld: function () {
			return !! ( ( this.hovered && this.modeOptions().pauseOnHover ) || this.pauses.focus || ( this.drag && this.drag.active ) );
		},

		snap: function ( pos ) {
			return this.stepWidth ? Math.round( pos / this.stepWidth ) * this.stepWidth : pos;
		},

		/**
		 * Synchronises classes, the step timer and the animation loop with the
		 * current state.
		 */
		update: function () {
			if ( this.destroyed ) {
				return;
			}

			this.root.classList.toggle( CLASS.draggable, this.isDraggable() );

			if ( 'step' === this.mode && this.canAutoplay() && ! this.isHeld() && ! this.tween && ! this.drag ) {
				if ( ! this.timer ) {
					this.timer = window.setTimeout( this.onStepTimer, toNumber( this.options.step.interval, DEFAULTS.step.interval, 100 ) );
				}
			} else {
				this.clearTimer();
			}

			this.requestTick();
		},

		requestTick: function () {
			if ( ! this.frame && ! this.destroyed ) {
				this.frame = raf( this.tick );
			}
		},

		tick: function ( time ) {
			var delta = this.lastTime ? Math.min( time - this.lastTime, MAX_FRAME_DELTA ) : 0;
			var active = false;

			this.frame = 0;
			this.lastTime = time;

			if ( this.tween ) {
				active = this.advanceTween( time );
			} else if ( 'continuous' === this.mode && this.canAutoplay() && ! ( this.drag && this.drag.active ) && this.setWidth ) {
				var target = this.isHeld() ? 0 : 1;

				if ( this.glide !== target ) {
					this.glide = target > this.glide
						? Math.min( target, this.glide + delta / RAMP_DURATION )
						: Math.max( target, this.glide - delta / RAMP_DURATION );
				}

				var speed = this.stepWidth / toNumber( this.options.continuous.speed, DEFAULTS.continuous.speed, 0.1 );

				this.pos = mod( this.pos + this.direction() * speed * smoothstep( this.glide ) * ( delta / 1000 ), this.setWidth );
				active = this.glide > 0 || target > 0;
			}

			this.render();

			if ( active ) {
				this.requestTick();
			} else if ( ! this.frame ) {
				this.lastTime = 0;
			}
		},

		render: function ( force ) {
			if ( ! this.setWidth ) {
				return;
			}

			var offset = mod( this.pos, this.setWidth );

			if ( ! force && offset === this.rendered ) {
				return;
			}

			this.rendered = offset;
			// toFixed() avoids exponent notation (e.g. "1e-7px"), which CSS rejects.
			this.track.style.transform = 'translate3d(' + ( -offset ).toFixed( 3 ) + 'px, 0, 0)';
		},

		animateTo: function ( target, duration ) {
			this.clearTimer();

			if ( ! ( duration > 0 ) ) {
				this.tween = null;
				this.pos = mod( target, this.setWidth );
				this.render();
				this.update();
				return;
			}

			this.tween = { from: this.pos, to: target, start: now(), duration: duration };
			this.requestTick();
		},

		advanceTween: function ( time ) {
			var tween = this.tween;
			var progress = clamp( ( time - tween.start ) / tween.duration, 0, 1 );

			this.pos = tween.from + ( tween.to - tween.from ) * ease( progress );

			if ( progress < 1 ) {
				return true;
			}

			this.tween = null;
			this.pos = mod( tween.to, this.setWidth );
			this.update();

			return false;
		},

		clearTimer: function () {
			if ( this.timer ) {
				window.clearTimeout( this.timer );
				this.timer = 0;
			}
		},

		onStepTimer: function () {
			this.timer = 0;

			if ( 'step' !== this.mode || ! this.canAutoplay() || this.isHeld() || this.drag || ! this.setWidth ) {
				this.update();
				return;
			}

			this.animateTo( this.snap( this.pos ) + this.direction() * this.stepWidth, this.snapDuration() );
		},

		snapDuration: function () {
			return toNumber( this.options.step.duration, DEFAULTS.step.duration, 0 );
		},

		/* Pointer: drag, swipe and hover ------------------------------------ */

		onPointerDown: function ( event ) {
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
		},

		onPointerMove: function ( event ) {
			var drag = this.drag;

			if ( ! drag || event.pointerId !== drag.id ) {
				return;
			}

			if ( ! drag.active ) {
				var dx = event.clientX - drag.startX;
				var dy = event.clientY - drag.startY;

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

			var elapsed = event.timeStamp - drag.lastTime;

			if ( elapsed > 0 ) {
				drag.velocity = 0.8 * ( ( event.clientX - drag.lastX ) / elapsed ) + 0.2 * drag.velocity;
			}

			drag.lastX = event.clientX;
			drag.lastTime = event.timeStamp;

			this.pos = drag.originPos - ( event.clientX - drag.originX );
			this.requestTick();
		},

		startDrag: function ( event ) {
			var drag = this.drag;

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
				// Pointer already released; pointerup ends the drag.
			}

			this.update();
		},

		onPointerUp: function ( event ) {
			var drag = this.drag;

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
				// Capture already gone.
			}

			if ( this.isSnapping() ) {
				var velocity = event.timeStamp - drag.lastTime > 100 ? 0 : drag.velocity;
				var index = this.pos / this.stepWidth;

				if ( velocity < -FLICK_VELOCITY ) {
					index = Math.ceil( index );
				} else if ( velocity > FLICK_VELOCITY ) {
					index = Math.floor( index );
				} else {
					index = Math.round( index );
				}

				this.animateTo( index * this.stepWidth, this.snapDuration() );
			} else {
				this.pos = mod( this.pos, this.setWidth );
			}

			this.update();
		},

		onClick: function ( event ) {
			if ( this.suppressClick ) {
				this.suppressClick = false;
				event.preventDefault();
				event.stopPropagation();
			}
		},

		onDragStart: function ( event ) {
			event.preventDefault(); // No native image/link drag ghost.
		},

		onPointerEnter: function ( event ) {
			if ( 'touch' !== event.pointerType ) {
				this.hovered = true;
				this.update();
			}
		},

		onPointerLeave: function ( event ) {
			if ( 'touch' !== event.pointerType ) {
				this.hovered = false;
				this.update();
			}
		},

		/* Keyboard focus ---------------------------------------------------- */

		onFocusIn: function ( event ) {
			var slide = event.target.closest ? event.target.closest( SELECTOR.slide ) : null;

			this.resetScroll();

			if ( ! slide || slide.classList.contains( CLASS.clone ) || ! isFocusVisible( event.target ) ) {
				return;
			}

			this.pauses.focus = true;
			this.glide = 0;
			this.reveal( slide );
			this.update();
		},

		onFocusOut: function ( event ) {
			if ( event.relatedTarget && this.root.contains( event.relatedTarget ) ) {
				return;
			}

			if ( this.pauses.focus ) {
				this.pauses.focus = false;
				this.update();
			}
		},

		/**
		 * Moves the strip so that an original slide is fully visible.
		 *
		 * @param {HTMLElement} slide Original slide.
		 */
		reveal: function ( slide ) {
			var index = this.slides.indexOf( slide );

			if ( index < 0 || ! this.setWidth ) {
				return;
			}

			var current = mod( this.pos, this.setWidth );
			var start = index * this.stepWidth;
			var end = start + this.slideWidth;
			var target;

			if ( start >= current - 0.5 && end <= current + this.viewportWidth + 0.5 ) {
				this.tween = null;
				this.pos = current;
				return;
			}

			if ( start < current ) {
				target = start;
			} else {
				target = Math.max( 0, end - this.viewportWidth );

				if ( this.isSnapping() ) {
					target = Math.ceil( ( target - 0.5 ) / this.stepWidth ) * this.stepWidth;
				}
			}

			this.pos = current;
			this.animateTo( target, this.reducedMotion ? 0 : FOCUS_DURATION );
		},

		onScroll: function () {
			this.resetScroll();
		},

		resetScroll: function () {
			if ( this.viewport.scrollLeft ) {
				this.viewport.scrollLeft = 0;
			}
		},

		/* Environment ------------------------------------------------------- */

		onResize: function () {
			if ( ! this.measureFrame && ! this.destroyed ) {
				this.measureFrame = raf( function () {
					this.measureFrame = 0;
					this.refresh();
				}.bind( this ) );
			}
		},

		onIntersect: function ( entries ) {
			var entry = entries[ entries.length - 1 ];

			this.pauses.offscreen = ! entry.isIntersecting;
			this.update();
		},

		onVisibilityChange: function () {
			this.pauses.hidden = !! document.hidden;
			this.update();
		},

		onReducedMotionChange: function ( event ) {
			this.reducedMotion = !! event.matches;

			if ( this.reducedMotion && this.isSnapping() && ! this.tween ) {
				this.pos = this.snap( this.pos );
				this.render();
			}

			this.update();
		},

		/**
		 * Current position as a fraction (0..1) of one slide set.
		 *
		 * @return {number} Progress.
		 */
		getProgress: function () {
			return this.setWidth ? mod( this.pos, this.setWidth ) / this.setWidth : 0;
		},

		/**
		 * Jumps to a position given as a fraction (0..1) of one slide set.
		 *
		 * @param {number} progress Progress.
		 */
		setProgress: function ( progress ) {
			if ( ! this.setWidth || ! isFinite( progress ) ) {
				return;
			}

			this.tween = null;
			this.pos = mod( progress, 1 ) * this.setWidth;

			if ( this.isSnapping() ) {
				this.pos = this.snap( this.pos );
			}

			this.render();
		},

		/**
		 * Stops all activity and restores the server-rendered markup.
		 */
		destroy: function () {
			if ( this.destroyed ) {
				return;
			}

			this.destroyed = true;

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

			this.listeners.forEach( function ( listener ) {
				listener[ 0 ].removeEventListener( listener[ 1 ], listener[ 2 ], listener[ 3 ] );
			} );
			this.listeners = [];

			this.removeClones();
			this.track.style.removeProperty( 'transform' );
			this.root.classList.remove( CLASS.ready, CLASS.draggable, CLASS.dragging, 'is-mode-continuous', 'is-mode-step', 'is-mode-none' );

			if ( instances && instances.get( this.root ) === this ) {
				instances.delete( this.root );
			}
		},
	};

	window.ListemannContinuousSlider = ListemannContinuousSlider;

	/* Elementor integration ------------------------------------------------- */

	var WIDGET = 'listemann-continuous-slider';

	// Settings that only change CSS custom properties: re-measure instead of re-render.
	var LAYOUT_SETTING = /^(slides_per_view|gap|height|motion)(_|$)/;

	// Keeps the position across editor re-renders (keyed by element model cid).
	var editorProgress = {};

	function createElementorHandler() {
		var modules = window.elementorModules;
		var Base = modules && modules.frontend && modules.frontend.handlers && modules.frontend.handlers.Base;

		if ( ! Base ) {
			return null;
		}

		// Elementor runs onInit() from inside the Base constructor, so state is
		// set there (no class fields, which would be initialised afterwards).
		class ListemannContinuousSliderHandler extends Base {
			onInit( ...args ) {
				super.onInit( ...args );

				var root = this.$element[ 0 ].querySelector( SELECTOR.root );

				this.slider = root ? new ListemannContinuousSlider( root ) : null;

				if ( this.slider && this.isEdit && editorProgress[ this.getModelCID() ] ) {
					this.slider.setProgress( editorProgress[ this.getModelCID() ] );
				}
			}

			onElementChange( setting ) {
				if ( ! LAYOUT_SETTING.test( setting ) ) {
					return;
				}

				// Elementor updates the element CSS in the same tick; read it on the next frame.
				raf( () => {
					if ( this.slider ) {
						this.slider.refresh();
					}
				} );
			}

			onDestroy( ...args ) {
				if ( this.slider ) {
					if ( this.isEdit ) {
						editorProgress[ this.getModelCID() ] = this.slider.getProgress();
					}

					this.slider.destroy();
					this.slider = null;
				}

				super.onDestroy( ...args );
			}
		}

		return ListemannContinuousSliderHandler;
	}

	function registerWithElementor() {
		var frontend = window.elementorFrontend;
		var Handler = createElementorHandler();

		if ( ! Handler || ! frontend || ! frontend.elementsHandler ) {
			return false;
		}

		frontend.elementsHandler.attachHandler( WIDGET, Handler );

		return Handler;
	}

	if ( window.elementorFrontend && window.elementorFrontend.elementsHandler ) {
		// This script ran after Elementor's frontend initialised (e.g. it was
		// delayed by an optimisation plugin): attach and start existing widgets.
		var LateHandler = registerWithElementor();

		if ( LateHandler && window.jQuery ) {
			Array.prototype.forEach.call( document.querySelectorAll( '.elementor-widget-' + WIDGET ), function ( element ) {
				window.elementorFrontend.elementsHandler.addHandler( LateHandler, {
					$element: window.jQuery( element ),
					elementName: WIDGET + '.default',
				} );
			} );
		}
	} else {
		window.addEventListener( 'elementor/frontend/init', registerWithElementor );
	}

	// Without Elementor's frontend (or if it failed to start), run standalone.
	window.addEventListener( 'load', function () {
		var frontend = window.elementorFrontend;

		try {
			if ( frontend && frontend.isEditMode && frontend.isEditMode() ) {
				return;
			}
		} catch ( error ) {
			// Ignore and fall through.
		}

		if ( ! frontend || ! frontend.elementsHandler ) {
			ListemannContinuousSlider.initAll();
		}
	} );
}() );
