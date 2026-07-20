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
 * 0. Espera a que jQuery y el propio JS de HBook (jQuery.datepick,
 *    hb_date_format) estén realmente cargados antes de tocar nada. En
 *    sitios donde algún plugin de caché/optimización difiere o reordena
 *    la carga de scripts, los campos del formulario pueden existir en el
 *    HTML antes de que HBook haya enlazado sus propios eventos sobre
 *    ellos — si se pulsa "Buscar" en ese momento, el navegador cae en el
 *    envío nativo del formulario (recarga real de la página), que vuelve
 *    a ejecutar este script desde cero: eso es lo que se percibía como
 *    "se queda en bucle".
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
 * Además, como red de seguridad ante cualquier causa de recarga en bucle
 * (conocida o no), el script se autolimita a un único intento por URL
 * exacta durante la sesión del navegador (sessionStorage): si esta misma
 * URL con los mismos parámetros ya se procesó una vez, no se vuelve a
 * intentar el autorrelleno.
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

		// Freno de seguridad: como mucho un intento de autorrelleno por
		// cada URL exacta, por sesión de navegador. Si por cualquier
		// motivo la página se recargara con los mismos parámetros (bucle),
		// esta segunda ejecución no vuelve a intentar nada — se deja el
		// buscador tal cual para que el visitante pueda usarlo a mano.
		var storageKey = 'addonFiltrosHbookAutofill:' + window.location.href;
		if ( window.sessionStorage ) {
			try {
				if ( window.sessionStorage.getItem( storageKey ) ) {
					return;
				}
				window.sessionStorage.setItem( storageKey, '1' );
			} catch ( e ) {
				// Si sessionStorage no está disponible (p.ej. incógnito
				// estricto), simplemente se sigue sin este freno extra.
			}
		}

		var FIELD_MAX_ATTEMPTS = 40;
		var FIELD_RETRY_DELAY_MS = 100;
		var READY_MAX_ATTEMPTS = 100;
		var READY_RETRY_DELAY_MS = 150;

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

			// Los campos ya deberían existir a estas alturas (se ha
			// esperado a que HBook esté listo antes de llamar a esta
			// función), pero se mantiene el reintento como margen extra.
			if ( ! checkInField || ! checkOutField || ! submitButton ) {
				if ( attempt < FIELD_MAX_ATTEMPTS ) {
					setTimeout( function () {
						fillAndSearch( attempt + 1 );
					}, FIELD_RETRY_DELAY_MS );
				}
				return;
			}

			// Todo lo relacionado con simular el calendario (enfocar campos,
			// cerrarlo a la fuerza) se protege en un try/catch: si algo de
			// eso fallara, el clic en "Buscar" de más abajo debe ejecutarse
			// igualmente — las fechas ya estarían puestas en los campos, así
			// que la búsqueda seguiría siendo válida. Sin este blindaje, un
			// error aquí detendría el resto del script (síncrono) y daría
			// la sensación de que "no se pulsa Buscar".
			try {
				// Se enfoca cada campo antes de rellenarlo, igual que haría
				// un visitante pasando de un campo a otro con el teclado:
				// HBook escucha el evento "focus" (ver hb-datepick.js,
				// activate_check_in_choice/activate_check_out_choice) para
				// saber si se está eligiendo la fecha de entrada o la de
				// salida.
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

				// Enfocar los campos abre el calendario emergente de HBook
				// (su comportamiento normal al elegir fechas a mano). Como
				// aquí no hay ningún clic real fuera del calendario que lo
				// cierre, se fuerza a que se oculte antes de continuar — si
				// no, se queda visible tapando el resultado de la búsqueda.
				forceCloseDatepickPopup();
			} catch ( e ) {
				if ( window.console && window.console.warn ) {
					window.console.warn( 'Addon Filtros HBook: no se pudo simular el calendario, se continúa igualmente.', e );
				}
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

			// En una tarea aparte (macrotask), para no depender de que
			// termine ninguna animación/cola pendiente del calendario de
			// HBook antes de pulsar "Buscar".
			setTimeout( function () {
				submitButton.click();
				advanceToNextStep();
			}, 0 );
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
				if ( attempt < FIELD_MAX_ATTEMPTS ) {
					setTimeout( function () {
						advanceToNextStep( attempt + 1 );
					}, FIELD_RETRY_DELAY_MS );
				}
				return;
			}

			nextStepButton.click();
		}

		/**
		 * No basta con comprobar que jQuery y la librería jQuery.datepick
		 * existen: esa librería se define nada más cargarse el archivo, ANTES
		 * de que HBook ejecute su propia inicialización (hb-datepick.js,
		 * dentro de un jQuery(document).ready) — que es lo que realmente
		 * engancha los eventos "focus"/"keyup" sobre .hb-check-in-date y
		 * .hb-check-out-date. Comprobar solo la librería puede dar un falso
		 * positivo si esa inicialización todavía no ha corrido.
		 *
		 * La señal fiable es otra: nada más entrar a ese
		 * jQuery(document).ready(), hb-datepick.js añade al final de <body>
		 * el propio calendario emergente (.hb-datepick-popup-wrapper) — ver
		 * utils/jq-datepick/js/hb-datepick.js de HBook, líneas 57-72. Si ese
		 * elemento existe, la inicialización de HBook ya ha corrido de
		 * verdad y es seguro tocar los campos.
		 */
		function waitForHbookReady( attempt ) {
			attempt = attempt || 0;

			var isReady =
				typeof window.jQuery !== 'undefined' &&
				window.jQuery.datepick &&
				typeof window.hb_date_format !== 'undefined' &&
				document.querySelector( '.hb-datepick-popup-wrapper' ) !== null;

			if ( isReady ) {
				fillAndSearch();
				return;
			}

			if ( attempt < READY_MAX_ATTEMPTS ) {
				setTimeout( function () {
					waitForHbookReady( attempt + 1 );
				}, READY_RETRY_DELAY_MS );
				return;
			}

			if ( window.console && window.console.warn ) {
				window.console.warn( 'Addon Filtros HBook: el buscador de HBook no llegó a cargar a tiempo; no se autorrellena para evitar una recarga en bucle.' );
			}
		}

		waitForHbookReady();
	} );
} )();
