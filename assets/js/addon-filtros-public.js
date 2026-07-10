/**
 * Addon Filtros HBook — lógica pública.
 *
 * Importante: este script NUNCA intercepta ni modifica las peticiones
 * AJAX de HBook (`hb_get_available_accom`, `hb_create_resa`, etc.). El
 * buscador de fechas/personas y la creación de la reserva son 100% de
 * HBook, sin tocar.
 *
 * Lo único que hace este script:
 * 1. Cuando el usuario marca/desmarca una característica, pide al
 *    endpoint propio del addon la lista de IDs de alojamiento que la
 *    cumplen (sin fechas).
 * 2. Observa el contenedor `.hb-accom-list` que HBook rellena tras su
 *    propia búsqueda (ver HBook: `$booking_wrapper.find('.hb-accom-list').html(response.mark_up)`)
 *    y oculta (display:none, de forma reversible) las tarjetas
 *    (`[data-accom-id]`) que no estén en esa lista.
 * 3. Vuelve a aplicar el filtro cada vez que HBook renderiza resultados
 *    nuevos (nueva búsqueda de fechas), gracias a un MutationObserver.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrapper = document.getElementById( 'addon-filtros-wrapper' );
		if ( ! wrapper || typeof window.AddonFiltrosHbook === 'undefined' ) {
			return;
		}

		var form = document.getElementById( 'addon-filtros-form' );
		var resultsList = wrapper.querySelector( '.hb-accom-list' );
		var panel = document.getElementById( 'addon-filtros-panel' );
		var overlay = document.getElementById( 'addon-filtros-panel-overlay' );
		var toggleBtn = document.getElementById( 'addon-filtros-toggle' );
		var closeBtn = document.getElementById( 'addon-filtros-panel-close' );
		var checkboxes = form ? Array.prototype.slice.call( form.querySelectorAll( 'input[type="checkbox"]' ) ) : [];

		// null = sin filtro de características activo (se muestra todo lo que devuelva HBook).
		var allowedIds = null;
		var noMatchMessage = null;
		var currentRequest = null;

		function openPanel() {
			if ( ! panel ) {
				return;
			}
			panel.classList.add( 'is-open' );
			if ( overlay ) {
				overlay.classList.add( 'is-open' );
			}
			if ( toggleBtn ) {
				toggleBtn.setAttribute( 'aria-expanded', 'true' );
			}
		}

		function closePanel() {
			if ( ! panel ) {
				return;
			}
			panel.classList.remove( 'is-open' );
			if ( overlay ) {
				overlay.classList.remove( 'is-open' );
			}
			if ( toggleBtn ) {
				toggleBtn.setAttribute( 'aria-expanded', 'false' );
			}
		}

		if ( toggleBtn ) {
			toggleBtn.addEventListener( 'click', openPanel );
		}
		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', closePanel );
		}
		if ( overlay ) {
			overlay.addEventListener( 'click', closePanel );
		}

		function toggleNoMatchMessage( show ) {
			if ( ! resultsList ) {
				return;
			}
			if ( ! noMatchMessage ) {
				noMatchMessage = document.createElement( 'p' );
				noMatchMessage.className = 'addon-filtros-empty';
				noMatchMessage.textContent = window.AddonFiltrosHbook.i18n.noResults;
			}
			if ( show && ! noMatchMessage.isConnected ) {
				resultsList.appendChild( noMatchMessage );
			} else if ( ! show && noMatchMessage.isConnected ) {
				noMatchMessage.remove();
			}
		}

		/**
		 * Muestra/oculta las tarjetas que HBook ya tiene renderizadas en
		 * .hb-accom-list según la lista actual de IDs permitidos.
		 */
		function applyCharacteristicsFilter() {
			if ( ! resultsList ) {
				return;
			}
			var cards = resultsList.querySelectorAll( '[data-accom-id]' );
			if ( ! cards.length ) {
				toggleNoMatchMessage( false );
				return;
			}
			var visibleCount = 0;
			cards.forEach( function ( card ) {
				var id = parseInt( card.getAttribute( 'data-accom-id' ), 10 );
				var matches = ( allowedIds === null ) || ( allowedIds.indexOf( id ) !== -1 );
				card.style.display = matches ? '' : 'none';
				if ( matches ) {
					visibleCount++;
				}
			} );
			toggleNoMatchMessage( visibleCount === 0 );
		}

		function fetchAllowedIds() {
			var params = new URLSearchParams();
			params.append( 'action', 'addon_filtros_hbook_get_allowed_ids' );
			params.append( 'nonce', window.AddonFiltrosHbook.nonce );

			checkboxes.forEach( function ( checkbox ) {
				if ( checkbox.checked ) {
					params.append( 'filtros[' + checkbox.dataset.taxonomy + '][]', checkbox.value );
				}
			} );

			if ( currentRequest ) {
				currentRequest.abort();
			}
			var controller = new AbortController();
			currentRequest = controller;

			if ( resultsList ) {
				resultsList.style.opacity = '0.5';
			}

			fetch( window.AddonFiltrosHbook.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: params.toString(),
				signal: controller.signal,
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( response ) {
					if ( currentRequest !== controller ) {
						return;
					}
					if ( response && response.success ) {
						allowedIds = response.data.active ? response.data.ids : null;
						applyCharacteristicsFilter();
					}
				} )
				.catch( function ( error ) {
					if ( error && error.name === 'AbortError' ) {
						return;
					}
				} )
				.finally( function () {
					if ( currentRequest === controller ) {
						if ( resultsList ) {
							resultsList.style.opacity = '';
						}
						currentRequest = null;
					}
				} );
		}

		checkboxes.forEach( function ( checkbox ) {
			checkbox.addEventListener( 'change', fetchAllowedIds );
		} );

		// HBook sustituye por completo el contenido de .hb-accom-list en
		// cada búsqueda de fechas (ver accommodation-list.js/booking-form.js
		// de HBook: `$booking_wrapper.find('.hb-accom-list').html(...)`).
		// Reaplicamos el filtro de características cada vez que eso ocurre.
		if ( resultsList && window.MutationObserver ) {
			var observer = new MutationObserver( function () {
				applyCharacteristicsFilter();
			} );
			observer.observe( resultsList, { childList: true } );
		}
	} );
} )();
