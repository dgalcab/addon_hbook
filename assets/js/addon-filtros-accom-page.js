/**
 * Addon Filtros HBook — script de la página propia de cada alojamiento.
 *
 * Cuando el visitante llega desde el botón "Reservar" de una tarjeta del
 * buscador (ver addon-filtros-public.js), la URL incluye ?addon_checkin=...
 * &addon_checkout=...&addon_adults=...&addon_children=..., con las fechas
 * en formato ISO "aaaa-mm-dd" (sin barras: ver por qué en el comentario de
 * toIsoDate() en addon-filtros-public.js — evita que las barras codificadas
 * (%2F) en la query string disparen un bloqueo/redirección del hosting).
 * Este script:
 *
 * 1. Localiza los campos REALES del [hb_booking_form accom_id="X"] que ya
 *    esté incrustado en esta página (no crea ningún formulario nuevo).
 * 2. Convierte el ISO de vuelta al formato local de HBook con su propia
 *    utilidad ($.datepick.formatDate), para no asumir ningún formato.
 * 3. Rellena la fecha de entrada/salida exactamente como lo haría un
 *    visitante escribiendo: pone el valor y dispara un evento "keyup",
 *    que es el mismo evento que el propio datepicker de HBook escucha
 *    para sincronizar su estado interno (ver utils/jq-datepick/js/hb-datepick.js
 *    de HBook, líneas 651-684).
 * 4. Rellena adultos/niños si se han indicado.
 * 5. Pulsa el botón "Buscar" real de HBook.
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

		/**
		 * Convierte una fecha ISO "aaaa-mm-dd" al formato local que use
		 * HBook en este sitio ($.datepick.formatDate + hb_date_format, ya
		 * disponibles aquí porque el propio [hb_booking_form] los carga).
		 */
		function fromIsoDate( isoValue ) {
			if ( ! isoValue || typeof window.jQuery === 'undefined' || ! window.jQuery.datepick || typeof window.hb_date_format === 'undefined' ) {
				return isoValue;
			}
			var parts = isoValue.split( '-' );
			if ( parts.length !== 3 ) {
				return isoValue;
			}
			try {
				var date = new Date( parseInt( parts[0], 10 ), parseInt( parts[1], 10 ) - 1, parseInt( parts[2], 10 ) );
				return window.jQuery.datepick.formatDate( window.hb_date_format, date );
			} catch ( e ) {
				return isoValue;
			}
		}

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
				checkInField.value = fromIsoDate( checkIn );
				checkInField.dispatchEvent( new Event( 'keyup', { bubbles: true } ) );
			}
			if ( checkOut ) {
				checkOutField.value = fromIsoDate( checkOut );
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
