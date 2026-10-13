/**
 * Listemann Toolkit – settings page.
 *
 * - Repeaters for contact sections and their lines (vanilla JS, <template>
 *   based): add, remove, move up / move down. Field names are re-indexed after
 *   every change; the server re-indexes again when saving.
 * - WordPress color pickers.
 * - Confirmation for the "Reset to defaults" form.
 */
( function () {
	'use strict';

	const config = window.listemannToolkitAdmin || {};
	const i18n = config.i18n || {};

	const speak = ( message ) => {
		if ( message && window.wp && window.wp.a11y && 'function' === typeof window.wp.a11y.speak ) {
			window.wp.a11y.speak( message );
		}
	};

	const childItems = ( list, type ) =>
		list ? Array.from( list.children ).filter( ( el ) => el.matches( '[data-lst-item="' + type + '"]' ) ) : [];

	const fromTemplate = ( template ) => {
		const element = template.content.firstElementChild;
		return element ? element.cloneNode( true ) : null;
	};

	function initRepeater( root ) {
		const base = root.getAttribute( 'data-lst-base' );
		const sectionList = root.querySelector( '[data-lst-list="sections"]' );
		const sectionTemplate = root.querySelector( 'template[data-lst-template="section"]' );
		const lineTemplate = root.querySelector( 'template[data-lst-template="line"]' );

		if ( ! base || ! sectionList || ! sectionTemplate || ! lineTemplate ) {
			return;
		}

		const linesList = ( section ) => section.querySelector( '[data-lst-list="lines"]' );

		const setMoveState = ( item, type, index, count ) => {
			const up = item.querySelector( '[data-lst-action="' + type + '-up"]' );
			const down = item.querySelector( '[data-lst-action="' + type + '-down"]' );

			if ( up ) {
				up.disabled = 0 === index;
			}
			if ( down ) {
				down.disabled = index === count - 1;
			}
		};

		const reindex = () => {
			const sections = childItems( sectionList, 'section' );

			sections.forEach( ( section, sectionIndex ) => {
				const prefix = base + '[' + sectionIndex + ']';

				section.querySelectorAll( '[data-lst-field]' ).forEach( ( field ) => {
					field.name = prefix + '[' + field.getAttribute( 'data-lst-field' ) + ']';
				} );

				const number = section.querySelector( '[data-lst-number]' );
				if ( number ) {
					number.textContent = String( sectionIndex + 1 );
				}

				setMoveState( section, 'section', sectionIndex, sections.length );

				const lines = childItems( linesList( section ), 'line' );

				lines.forEach( ( line, lineIndex ) => {
					line.querySelectorAll( '[data-lst-line-field]' ).forEach( ( field ) => {
						field.name = prefix + '[lines][' + lineIndex + '][' + field.getAttribute( 'data-lst-line-field' ) + ']';
					} );
					setMoveState( line, 'line', lineIndex, lines.length );
				} );

				const emptyLines = section.querySelector( '[data-lst-empty="lines"]' );
				if ( emptyLines ) {
					emptyLines.hidden = lines.length > 0;
				}
			} );

			const emptySections = root.querySelector( '[data-lst-empty="sections"]' );
			if ( emptySections ) {
				emptySections.hidden = sections.length > 0;
			}
		};

		const updateHeading = ( section ) => {
			const heading = section.querySelector( '[data-lst-heading]' );
			const title = section.querySelector( '[data-lst-field="title"]' );

			if ( heading && title ) {
				heading.textContent = title.value.trim() || i18n.untitled || '';
			}
		};

		const addLine = ( section, announce ) => {
			const list = linesList( section );
			const line = fromTemplate( lineTemplate );

			if ( ! list || ! line ) {
				return null;
			}

			list.appendChild( line );

			if ( announce ) {
				reindex();
				const text = line.querySelector( '[data-lst-line-field="text"]' );
				if ( text ) {
					text.focus();
				}
				speak( i18n.lineAdded );
			}

			return line;
		};

		const addSection = () => {
			const section = fromTemplate( sectionTemplate );

			if ( ! section ) {
				return;
			}

			sectionList.appendChild( section );
			addLine( section, false );
			reindex();

			const title = section.querySelector( '[data-lst-field="title"]' );
			if ( title ) {
				title.focus();
			}
			speak( i18n.sectionAdded );
		};

		const removeItem = ( item, type ) => {
			const neighbour = item.nextElementSibling || item.previousElementSibling;
			const fallback =
				'line' === type
					? item.closest( '[data-lst-item="section"]' ).querySelector( '[data-lst-action="line-add"]' )
					: root.querySelector( '[data-lst-action="section-add"]' );

			item.remove();
			reindex();

			const target = neighbour ? neighbour.querySelector( '[data-lst-action="' + type + '-remove"]' ) : fallback;
			if ( target ) {
				target.focus();
			}
			speak( 'line' === type ? i18n.lineRemoved : i18n.sectionRemoved );
		};

		const moveItem = ( item, type, direction, button ) => {
			const sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling;

			if ( ! sibling ) {
				return;
			}

			if ( direction < 0 ) {
				item.parentNode.insertBefore( item, sibling );
			} else {
				item.parentNode.insertBefore( sibling, item );
			}

			reindex();

			const other = item.querySelector( '[data-lst-action="' + type + '-' + ( direction < 0 ? 'down' : 'up' ) + '"]' );
			const target = button.disabled ? other : button;
			if ( target ) {
				target.focus();
			}
			speak( direction < 0 ? i18n.movedUp : i18n.movedDown );
		};

		root.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( '[data-lst-action]' );

			if ( ! button || ! root.contains( button ) ) {
				return;
			}

			const action = button.getAttribute( 'data-lst-action' );
			const section = button.closest( '[data-lst-item="section"]' );
			const line = button.closest( '[data-lst-item="line"]' );

			event.preventDefault();

			switch ( action ) {
				case 'section-add':
					addSection();
					break;
				case 'section-remove':
					if ( section ) {
						removeItem( section, 'section' );
					}
					break;
				case 'section-up':
				case 'section-down':
					if ( section ) {
						moveItem( section, 'section', 'section-up' === action ? -1 : 1, button );
					}
					break;
				case 'line-add':
					if ( section ) {
						addLine( section, true );
					}
					break;
				case 'line-remove':
					if ( line ) {
						removeItem( line, 'line' );
					}
					break;
				case 'line-up':
				case 'line-down':
					if ( line ) {
						moveItem( line, 'line', 'line-up' === action ? -1 : 1, button );
					}
					break;
			}
		} );

		root.addEventListener( 'input', ( event ) => {
			if ( event.target.matches( '[data-lst-field="title"]' ) ) {
				const section = event.target.closest( '[data-lst-item="section"]' );
				if ( section ) {
					updateHeading( section );
				}
			}
		} );

		reindex();
	}

	document.querySelectorAll( '[data-lst-repeater]' ).forEach( initRepeater );

	// Color pickers (wp-color-picker is a jQuery UI widget).
	if ( window.jQuery && window.jQuery.fn && window.jQuery.fn.wpColorPicker ) {
		window.jQuery( '.lst-settings .lst-color-picker' ).wpColorPicker();
	}

	// Confirmation before resetting all settings.
	document.querySelectorAll( 'form[data-lst-confirm]' ).forEach( ( form ) => {
		form.addEventListener( 'submit', ( event ) => {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( form.getAttribute( 'data-lst-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );
}() );
