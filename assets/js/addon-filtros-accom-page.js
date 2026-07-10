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
 * 6. Espera a que aparezca el resultado (la búsqueda es AJAX) y, como en
 *    una página de un único alojamiento solo hay un resultado posible,
 *    HBook lo autoselecciona solo (ver booking-form.js de HBook,
 *    search_show_response(): cuando .hb-multi-accom-choices tiene un
 *    único .hb-accom, llama a set_selected_accom() automáticamente).
 *    Lo único que falta es pulsar el botón "Siguiente" (.hb-next-step-1)
 *    para pasar al paso de servicios adicionales — este script lo hace
 *    por el visitante, tal cual haría él.
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

		/**
		 * Oculta directamente el calendario emergente de HBook y le quita
		 * la marca de "campos activos" (.hb-datepick-active-inputs), sin
		 * depender de su lógica interna de cierre por clic (que además
		 * ignora clics durante el primer segundo tras abrirse, ver
		 * hb-datepick.js: "opening_time" — este script rellena ambos
		 * campos en milisegundos, así que ese clic nunca llegaría a
		 * tiempo de cerrarlo por sí solo).
		 */
		function forceCloseDatepickPopup() {
			var popup = document.querySelector( '.hb-datepick-popup-wrapper' );
			if ( popup ) {
				popup.style.display = 'none';
			}
			var activeInputs = document.querySelectorAll( '.hb-datepick-active-inputs' );
			activeInputs.forEach( function ( el ) {
				el.classList.remove( 'hb-datepick-active-inputs' );
			} );
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

			// Se enfoca cada campo antes de rellenarlo, igual que haría un
			// visitante pasando de un campo a otro con el teclado: HBook
			// escucha el evento "focus" (ver hb-datepick.js,
			// activate_check_in_choice/activate_check_out_choice) para saber
			// si se está eligiendo la fecha de entrada o la de salida. Sin
			// este enfoque, HBook se queda pensando que se sigue eligiendo
			// la fecha de entrada al rellenar la de salida, y su estado
			// interno (aunque la búsqueda en sí salga bien) queda
			// desincronizado.
			if ( checkIn ) {
				checkInField.focus();
				checkInField.value = fromIsoDate( checkIn );
				checkInField.dispatchEvent( new Event( 'keyup', { bubbles: true } ) );
			}
			if ( checkOut ) {
				checkOutField.focus();
				checkOutField.value = fromIsoDate( checkOut );
				checkOutField.dispatchEvent( new Event( 'keyup', { bubbles: true } ) );
			}

			// Enfocar los campos abre el calendario emergente de HBook (su
			// comportamiento normal al elegir fechas a mano). Como aquí no
			// hay ningún clic real fuera del calendario que lo cierre, se
			// fuerza a que se oculte antes de continuar — si no, se queda
			// visible tapando el resultado de la búsqueda y el botón
			// "Siguiente", dando la sensación de que la página se ha quedado
			// parada/en bucle.
			forceCloseDatepickPopup();

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
			advanceToNextStep();
		}

		/**
		 * Tras pulsar "Buscar", la búsqueda es AJAX: espera (con reintentos)
		 * a que aparezca y esté visible el botón "Siguiente" del paso 1
		 * (.hb-next-step-1), y lo pulsa — igual que haría el visitante tras
		 * ver que su único alojamiento ya está seleccionado. Si tras el
		 * margen de espera no aparece visible (p. ej. porque la búsqueda no
		 * ha encontrado disponibilidad, o hay más de un resultado y hace
		 * falta elegir a mano), no se fuerza nada: el visitante se queda en
		 * el paso 1 con la búsqueda ya hecha, que sigue siendo mejor que
		 * tener que repetirla desde cero.
		 */
		function advanceToNextStep( attempt ) {
			attempt = attempt || 0;

			var nextStepButton = document.querySelector( '.hb-next-step-1 input[type="submit"]' );
			var isVisible = nextStepButton && nextStepButton.offsetParent !== null;

			if ( ! isVisible ) {
				if ( attempt < MAX_ATTEMPTS ) {
					setTimeout( function () {
						advanceToNextStep( attempt + 1 );
					}, RETRY_DELAY_MS );
				}
				return;
			}

			nextStepButton.click();
		}

		fillAndSearch();
	} );
} )();
