/**
 * Addon Filtros HBook — script de la página propia de cada alojamiento.
 *
 * Cuando el visitante llega desde el botón "Reservar" de una tarjeta del
 * buscador (ver addon-filtros-public.js), la URL incluye ?addon_checkin=...
 * &addon_checkout=...&addon_adults=...&addon_children=.... Este script:
 *
 * 1. Localiza los campos REALES del [hb_booking_form accom_id="X"] que ya
 *    esté incrustado en esta página (no crea ningún formulario nuevo).
 * 2. Rellena la fecha de entrada/salida exactamente como lo haría un
 *    visitante escribiendo: pone el valor y dispara un evento "keyup",
 *    que es el mismo evento que el propio datepicker de HBook escucha
 *    para sincronizar su estado interno (ver utils/jq-datepick/js/hb-datepick.js
 *    de HBook, líneas 651-684).
 * 3. Rellena adultos/niños si se han indicado.
 * 4. Pulsa el botón "Buscar" real de HBook.
 *
 * No se reimplementa ni se sustituye ninguna lógica de HBook: solo se
 * simulan las mismas acciones que haría un visitante con el teclado y el
 * ratón, sobre los campos reales.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var params = new URLSearchParams( window.location.search );
		var checkIn = params.get( 'addon_checkin' );
		var checkOut = params.get( 'addon_checkout' );
		var adults = params.get( 'addon_adults' );
		var children = params.get( 'addon_children' );

		if ( ! checkIn && ! checkOut ) {
			return;
		}

		var MAX_ATTEMPTS = 40;
		var RETRY_DELAY_MS = 100;

		function fillAndSearch( attempt ) {
			attempt = attempt || 0;

			var checkInField = document.querySelector( '.hb-check-in-date' );
			var checkOutField = document.querySelector( '.hb-check-out-date' );
			var submitButton = document.querySelector( '.hb-search-submit-wrapper input[type="submit"]' );

			// El formulario de HBook puede tardar un instante en montarse
			// (sus propios scripts se cargan y ejecutan tras el DOM). Se
			// reintenta con un pequeño margen en vez de asumir que ya existe.
			if ( ! checkInField || ! checkOutField || ! submitButton ) {
				if ( attempt < MAX_ATTEMPTS ) {
					setTimeout( function () {
						fillAndSearch( attempt + 1 );
					}, RETRY_DELAY_MS );
				}
				return;
			}

			if ( checkIn ) {
				checkInField.value = checkIn;
				checkInField.dispatchEvent( new Event( 'keyup', { bubbles: true } ) );
			}
			if ( checkOut ) {
				checkOutField.value = checkOut;
				checkOutField.dispatchEvent( new Event( 'keyup', { bubbles: true } ) );
			}

			var adultsField = document.querySelector( 'select#adults, select.hb-adults' );
			if ( adultsField && adults ) {
				adultsField.value = adults;
				adultsField.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}

			var childrenField = document.querySelector( 'select#children, select.hb-children' );
			if ( childrenField && children ) {
				childrenField.value = children;
				childrenField.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}

			submitButton.click();
		}

		fillAndSearch();
	} );
} )();
