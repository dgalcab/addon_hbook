/**
 * Addon Filtros HBook — lógica pública.
 *
 * - Escucha el evento "change" de cada checkbox de filtro.
 * - Envía la selección al endpoint AJAX de WordPress mediante fetch().
 * - Aplica un estado de carga (opacidad + skeleton loader) mientras espera respuesta.
 * - Conecta el botón de cada tarjeta con el flujo de reserva de HBook.
 */
( function () {
	'use strict';

	var SKELETON_CARDS = 6;

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrapper = document.getElementById( 'addon-filtros-wrapper' );
		if ( ! wrapper || typeof window.AddonFiltrosHbook === 'undefined' ) {
			return;
		}

		var form = document.getElementById( 'addon-filtros-form' );
		var grid = document.getElementById( 'addon-filtros-grid' );
		var panel = document.getElementById( 'addon-filtros-panel' );
		var overlay = document.getElementById( 'addon-filtros-panel-overlay' );
		var toggleBtn = document.getElementById( 'addon-filtros-toggle' );
		var closeBtn = document.getElementById( 'addon-filtros-panel-close' );
		var checkboxes = form ? Array.prototype.slice.call( form.querySelectorAll( 'input[type="checkbox"]' ) ) : [];
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

		checkboxes.forEach( function ( checkbox ) {
			checkbox.addEventListener( 'change', function () {
				fetchResults();
			} );
		} );

		function buildSkeletonMarkup() {
			var card =
				'<div class="addon-filtros-skeleton-card">' +
				'<div class="addon-filtros-skeleton-img"></div>' +
				'<div class="addon-filtros-skeleton-body">' +
				'<div class="addon-filtros-skeleton-line addon-filtros-skeleton-line--title"></div>' +
				'<div class="addon-filtros-skeleton-line"></div>' +
				'<div class="addon-filtros-skeleton-line addon-filtros-skeleton-line--btn"></div>' +
				'</div>' +
				'</div>';
			return new Array( SKELETON_CARDS + 1 ).join( card );
		}

		function setLoadingState( isLoading ) {
			if ( ! grid ) {
				return;
			}
			grid.classList.toggle( 'is-loading', isLoading );
			grid.setAttribute( 'aria-busy', isLoading ? 'true' : 'false' );
			if ( isLoading ) {
				grid.innerHTML = buildSkeletonMarkup();
			}
		}

		function buildRequestBody() {
			var params = new URLSearchParams();
			params.append( 'action', 'addon_filtros_hbook_filter' );
			params.append( 'nonce', window.AddonFiltrosHbook.nonce );

			checkboxes.forEach( function ( checkbox ) {
				if ( checkbox.checked ) {
					params.append( 'filtros[' + checkbox.dataset.taxonomy + '][]', checkbox.value );
				}
			} );

			return params;
		}

		function fetchResults() {
			if ( ! grid ) {
				return;
			}

			if ( currentRequest ) {
				currentRequest.abort();
			}

			var controller = new AbortController();
			currentRequest = controller;

			setLoadingState( true );

			fetch( window.AddonFiltrosHbook.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: buildRequestBody().toString(),
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
						grid.innerHTML = response.data.html;
						bindBookingButtons();
					} else {
						grid.innerHTML = '<p class="addon-filtros-empty">' + window.AddonFiltrosHbook.i18n.error + '</p>';
					}
				} )
				.catch( function ( error ) {
					if ( error && error.name === 'AbortError' ) {
						return;
					}
					grid.innerHTML = '<p class="addon-filtros-empty">' + window.AddonFiltrosHbook.i18n.error + '</p>';
				} )
				.finally( function () {
					if ( currentRequest === controller ) {
						grid.classList.remove( 'is-loading' );
						grid.setAttribute( 'aria-busy', 'false' );
						currentRequest = null;
					}
				} );
		}

		/**
		 * Conecta cada botón "Reservar" con el flujo de reserva de HBook.
		 *
		 * Por defecto redirige a la URL de la tarjeta, que ya incluye
		 * ?reserva_directa={ID} (calculada en PHP), abriendo directamente
		 * la confirmación de fechas/pasarela de pago del alojamiento sin
		 * mostrar un segundo buscador.
		 *
		 * Si el core de HBook expone un modal nativo de reserva mediante
		 * window.HBook.openBookingModal(id), se usa ese flujo en su lugar.
		 */
		function bindBookingButtons() {
			if ( ! grid ) {
				return;
			}
			var buttons = grid.querySelectorAll( '.addon-filtros-book-btn' );
			buttons.forEach( function ( button ) {
				button.addEventListener( 'click', function ( event ) {
					var accommodationId = button.getAttribute( 'data-accommodation-id' );

					if ( window.HBook && typeof window.HBook.openBookingModal === 'function' ) {
						event.preventDefault();
						window.HBook.openBookingModal( accommodationId );
						return;
					}

					window.location.href = button.getAttribute( 'href' );
				} );
			} );
		}

		bindBookingButtons();
	} );
} )();
