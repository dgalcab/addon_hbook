/**
 * Addon Filtros HBook — lógica pública.
 *
 * Importante: este script NUNCA intercepta ni modifica las peticiones
 * AJAX de HBook (`hb_get_available_accom`, `hb_create_resa`, etc.). El
 * buscador de fechas/personas y la creación de la reserva son 100% de
 * HBook, sin tocar.
 *
 * Lo único que hace este script:
 * 1. Cuando el usuario marca/desmarca una característica (pill), pide al
 *    endpoint propio del addon la lista de IDs de alojamiento que la
 *    cumplen (sin fechas).
 * 2. Observa el contenedor `.hb-accom-list` que HBook rellena tras su
 *    propia búsqueda (ver HBook: `$booking_wrapper.find('.hb-accom-list').html(response.mark_up)`)
 *    y oculta (display:none, de forma reversible) las tarjetas
 *    (`.hb-accom[data-accom-id]`) que no estén en esa lista. Importante:
 *    el selector va con `.hb-accom` y no solo `[data-accom-id]`, porque
 *    HBook también pone ese mismo atributo en un `.hb-accom-quantity`
 *    oculto (uno por alojamiento), que un selector genérico contaría
 *    igualmente y desajustaría cualquier recuento de tarjetas visibles.
 * 3. Inyecta badges informativos (las características de cada alojamiento)
 *    en cada tarjeta, a partir de datos ya asignados en WordPress
 *    (AddonFiltrosHbook.badgesMap), sin inventar nada.
 * 4. Añade a cada tarjeta un botón "Reservar" propio (independiente de los
 *    botones nativos de HBook, que este sitio ya oculta con su propio CSS)
 *    que enlaza a la página real del alojamiento con las fechas/personas
 *    de la búsqueda actual como parámetros de URL, para que
 *    addon-filtros-accom-page.js las recoja allí y continúe sin repetir
 *    la búsqueda.
 * 5. Vuelve a aplicar todo lo anterior cada vez que HBook renderiza
 *    resultados nuevos (nueva búsqueda de fechas), gracias a un
 *    MutationObserver.
 * 6. Mantiene el bloque de características OCULTO hasta que, tras pulsar
 *    "Buscar", HBook renderiza al menos un alojamiento: no tiene sentido
 *    dejar elegir características antes de tener ningún resultado sobre
 *    el que aplicarlas. En cuanto hay tarjetas, el bloque aparece justo
 *    encima de ellas (se reubica una única vez, al cargar, como hermano
 *    inmediato anterior a `.hb-accom-list`).
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
		var clearBtn = document.getElementById( 'addon-filtros-clear-btn' );
		var checkboxes = form ? Array.prototype.slice.call( form.querySelectorAll( 'input[type="checkbox"]' ) ) : [];
		var badgesMap = window.AddonFiltrosHbook.badgesMap || {};
		var linksMap = window.AddonFiltrosHbook.linksMap || {};

		/**
		 * Mueve el bloque de características para que sea el hermano
		 * inmediatamente anterior a `.hb-accom-list` (donde HBook renderiza
		 * los resultados de cada búsqueda), en vez de quedarse arriba, junto
		 * a los campos de fecha/personas. Es un MOVIMIENTO real del nodo (no
		 * una copia), así que los checkboxes conservan sus eventos ya
		 * enlazados. `.hb-accom-list` en sí no se sustituye nunca (HBook solo
		 * reemplaza su contenido interno en cada búsqueda), así que basta con
		 * colocarlo una vez al cargar la página.
		 */
		function positionFormBeforeResultsList() {
			if ( ! form || ! resultsList || ! resultsList.parentNode ) {
				return;
			}
			if ( form.nextSibling !== resultsList ) {
				resultsList.parentNode.insertBefore( form, resultsList );
			}
		}
		positionFormBeforeResultsList();

		/**
		 * El bloque de características solo tiene sentido si ya hay
		 * alojamientos sobre los que aplicarlo: permanece oculto hasta que,
		 * tras pulsar "Buscar", HBook renderiza al menos una tarjeta.
		 */
		function updateFormVisibility() {
			if ( ! form || ! resultsList ) {
				return;
			}
			var hasCards = resultsList.querySelectorAll( '.hb-accom[data-accom-id]' ).length > 0;
			form.classList.toggle( 'is-visible', hasCards );
		}
		updateFormVisibility();

		/**
		 * Red de seguridad: además del MutationObserver de más abajo (que
		 * reacciona al HTML que HBook inyecta en .hb-accom-list), al pulsar
		 * "Buscar" se lanza un sondeo corto que vuelve a comprobar la
		 * visibilidad varias veces mientras dura la búsqueda AJAX. No
		 * debería hacer falta (el observer ya se dispara solo), pero como
		 * es la única pieza de este addon que decide si el bloque de
		 * características se ve o no, conviene no depender de un único
		 * mecanismo para algo tan visible.
		 */
		function pollFormVisibilityAfterSearch() {
			var attempts = 0;
			var maxAttempts = 30;
			var poll = setInterval( function () {
				attempts++;
				updateFormVisibility();
				if ( ( form && form.classList.contains( 'is-visible' ) ) || attempts >= maxAttempts ) {
					clearInterval( poll );
				}
			}, 400 );
		}
		wrapper.addEventListener(
			'submit',
			function ( event ) {
				if ( event.target && event.target.closest && event.target.closest( '.hb-accom-list' ) ) {
					return;
				}
				pollFormVisibilityAfterSearch();
			},
			true
		);

		// null = sin filtro de características activo (se muestra todo lo que devuelva HBook).
		var allowedIds = null;
		var noMatchMessage = null;
		var currentRequest = null;

		// Frase de HBook tipo "Hemos encontrado 20 tipos de alojamiento...":
		// se guarda el texto ORIGINAL (con el conteo real de HBook, antes de
		// aplicar el filtro de características) cada vez que HBook renderiza
		// resultados nuevos, para poder restaurarlo tal cual si se quitan
		// todos los filtros.
		var introElement = null;
		var introOriginalText = null;

		function captureIntroText() {
			if ( ! resultsList ) {
				return;
			}
			// Solo se recaptura si el nodo ha cambiado de verdad (una
			// búsqueda nueva de HBook sustituye por completo el HTML, así
			// que crea un <p> nuevo). Esto evita recapturar un texto que
			// este propio script ya haya modificado, en las veces que el
			// MutationObserver se dispara por sus propios cambios (p.ej. al
			// añadir/quitar el mensaje de "sin resultados").
			var el = resultsList.querySelector( '.hb-search-result-title-section p' );
			if ( el !== introElement ) {
				introElement = el;
				introOriginalText = el ? el.textContent : null;
			}
		}

		/**
		 * Sustituye el número dentro de esa frase por la cantidad de
		 * tarjetas que quedan visibles tras aplicar el filtro de
		 * características, sin tocar el resto del texto (ni inventar
		 * traducciones): si no hay filtro activo, se restaura el texto
		 * original de HBook tal cual.
		 */
		function updateIntroCount( visibleCount ) {
			if ( ! introElement || introOriginalText === null ) {
				return;
			}
			if ( allowedIds === null ) {
				introElement.textContent = introOriginalText;
				return;
			}
			introElement.textContent = introOriginalText.replace( /\d+/, String( visibleCount ) );
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
			var cards = resultsList.querySelectorAll( '.hb-accom[data-accom-id]' );
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
			updateIntroCount( visibleCount );
		}

		/**
		 * Añade, dentro de cada tarjeta que HBook ya renderizó, la lista de
		 * características de ese alojamiento (las que el admin le haya
		 * asignado en WordPress). Idempotente: no duplica si ya se insertó.
		 */
		function injectBadges() {
			if ( ! resultsList ) {
				return;
			}
			var cards = resultsList.querySelectorAll( '.hb-accom[data-accom-id]' );
			cards.forEach( function ( card ) {
				if ( card.querySelector( '.addon-filtros-card-badges' ) ) {
					return;
				}
				var id = card.getAttribute( 'data-accom-id' );
				var names = badgesMap[ id ];
				if ( ! names || ! names.length ) {
					return;
				}
				var list = document.createElement( 'ul' );
				list.className = 'addon-filtros-card-badges';
				names.forEach( function ( name ) {
					var item = document.createElement( 'li' );
					item.className = 'addon-filtros-badge';
					item.textContent = name;
					list.appendChild( item );
				} );
				var anchor = card.querySelector( '.hb-accom-desc' ) || card.querySelector( '.hb-accom-title' );
				if ( anchor && anchor.parentNode ) {
					anchor.parentNode.insertBefore( list, anchor.nextSibling );
				} else {
					card.appendChild( list );
				}
			} );
		}

		/**
		 * Convierte una fecha en el formato local de HBook (p.ej. "12/10/2026")
		 * a ISO "aaaa-mm-dd", usando la propia utilidad de HBook
		 * ($.datepick.parseDate, ya cargada en la página junto al buscador),
		 * para no tener que adivinar el separador de fecha del sitio.
		 *
		 * Importante: el ISO no lleva barras ("/"). Muchos hostings/firewalls
		 * de WordPress bloquean o redirigen a la home las peticiones cuyas
		 * URL contienen barras codificadas (%2F) repetidas en la query string
		 * — justo lo que pasaba al mandar la fecha tal cual con su formato
		 * local (12%2F10%2F2026). El ISO evita ese problema de raíz.
		 */
		function toIsoDate( value ) {
			if ( ! value || typeof window.jQuery === 'undefined' || ! window.jQuery.datepick || typeof window.hb_date_format === 'undefined' ) {
				return value;
			}
			try {
				var parsed = window.jQuery.datepick.parseDate( window.hb_date_format, value );
				var y = parsed.getFullYear();
				var m = ( '0' + ( parsed.getMonth() + 1 ) ).slice( -2 );
				var d = ( '0' + parsed.getDate() ).slice( -2 );
				return y + '-' + m + '-' + d;
			} catch ( e ) {
				return value;
			}
		}

		/**
		 * Añade a cada tarjeta un botón "Reservar" propio que enlaza a la
		 * página real del alojamiento (AddonFiltrosHbook.linksMap), con las
		 * fechas/personas de la búsqueda ACTUAL (leídas de los campos reales
		 * de HBook en este mismo bloque) como parámetros de URL propios
		 * (addon_checkin, addon_checkout, addon_adults, addon_children).
		 * Idempotente: si el botón ya existe, solo se actualiza su enlace.
		 */
		function buildReservarButtons() {
			if ( ! resultsList ) {
				return;
			}
			var checkInField = wrapper.querySelector( '.hb-check-in-date' );
			var checkOutField = wrapper.querySelector( '.hb-check-out-date' );
			var adultsField = wrapper.querySelector( 'select#adults, select.hb-adults' );
			var childrenField = wrapper.querySelector( 'select#children, select.hb-children' );

			var cards = resultsList.querySelectorAll( '.hb-accom[data-accom-id]' );
			cards.forEach( function ( card ) {
				var id = card.getAttribute( 'data-accom-id' );
				var link = linksMap[ id ];
				if ( ! link ) {
					return;
				}

				var url;
				try {
					url = new URL( link );
				} catch ( e ) {
					return;
				}
				if ( checkInField && checkInField.value ) {
					url.searchParams.set( 'addon_checkin', toIsoDate( checkInField.value ) );
				}
				if ( checkOutField && checkOutField.value ) {
					url.searchParams.set( 'addon_checkout', toIsoDate( checkOutField.value ) );
				}
				if ( adultsField && adultsField.value ) {
					url.searchParams.set( 'addon_adults', adultsField.value );
				}
				if ( childrenField && childrenField.value ) {
					url.searchParams.set( 'addon_children', childrenField.value );
				}

				var button = card.querySelector( '.addon-filtros-reservar-btn' );
				if ( ! button ) {
					button = document.createElement( 'a' );
					button.className = 'addon-filtros-reservar-btn';
					button.textContent = window.AddonFiltrosHbook.i18n.reservar;
					var host = card.querySelector( '.hb-select-accom-wrapper' ) || card;
					host.appendChild( button );
				}
				button.setAttribute( 'href', url.toString() );
			} );
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

		function updatePillState( checkbox ) {
			var pill = checkbox.closest( '.addon-filtros-pill' );
			if ( pill ) {
				pill.classList.toggle( 'is-checked', checkbox.checked );
			}
		}

		function syncAllPillStates() {
			checkboxes.forEach( updatePillState );
		}

		/**
		 * El botón "Limpiar" solo se muestra si hay alguna característica
		 * marcada (si no hay nada que limpiar, no aporta y solo ocupa sitio).
		 */
		function updateClearButtonVisibility() {
			if ( ! clearBtn ) {
				return;
			}
			var anyChecked = checkboxes.some( function ( checkbox ) {
				return checkbox.checked;
			} );
			clearBtn.classList.toggle( 'is-visible', anyChecked );
		}

		/*
		 * IMPORTANTE — por qué DELEGACIÓN de eventos y no un listener por
		 * checkbox:
		 *
		 * El bloque de características (#addon-filtros-form) se MUEVE por el
		 * DOM (positionFormBeforeResultsList) y convive dentro del wrapper de
		 * HBook, cuyo JS y el propio tema pueden reordenar/reenvolver nodos.
		 * Enganchar un listener a cada <input> concreto es frágil: si por
		 * cualquier motivo (caché sirviendo un HTML distinto, un re-render del
		 * tema, un move que rehace nodos) el checkbox sobre el que se pulsa no
		 * es EXACTAMENTE el nodo al que se enganchó el listener, el clic "no
		 * hace nada" — que es justo el síntoma que se estaba dando.
		 *
		 * Con delegación, el listener vive en #addon-filtros-wrapper (un
		 * ancestro estable que nunca se sustituye) y captura el evento
		 * "change" de CUALQUIER .addon-filtros-pill-input, exista ya o se
		 * inserte después. Es inmune a todo lo anterior.
		 */
		wrapper.addEventListener( 'change', function ( event ) {
			var target = event.target;
			if ( ! target || ! target.classList || ! target.classList.contains( 'addon-filtros-pill-input' ) ) {
				return;
			}
			updatePillState( target );
			updateClearButtonVisibility();
			fetchAllowedIds();
		} );

		syncAllPillStates();
		updateClearButtonVisibility();

		/*
		 * "Limpiar": también por delegación, por el mismo motivo de robustez.
		 * Desmarca todo sin lanzar AJAX (allowedIds vuelve a null = sin filtro)
		 * y reaplica al instante.
		 */
		wrapper.addEventListener( 'click', function ( event ) {
			var target = event.target;
			if ( ! target || ! target.closest || ! target.closest( '#addon-filtros-clear-btn' ) ) {
				return;
			}
			event.preventDefault();
			checkboxes.forEach( function ( checkbox ) {
				checkbox.checked = false;
			} );
			syncAllPillStates();
			updateClearButtonVisibility();
			allowedIds = null;
			applyCharacteristicsFilter();
		} );

		// HBook sustituye por completo el contenido de .hb-accom-list en
		// cada búsqueda de fechas (ver accommodation-list.js/booking-form.js
		// de HBook: `$booking_wrapper.find('.hb-accom-list').html(...)`).
		// Reaplicamos filtro + badges, y reevaluamos si el bloque de
		// características debe mostrarse, cada vez que eso ocurre.
		if ( resultsList && window.MutationObserver ) {
			var observer = new MutationObserver( function () {
				captureIntroText();
				injectBadges();
				buildReservarButtons();
				applyCharacteristicsFilter();
				updateFormVisibility();
			} );
			observer.observe( resultsList, { childList: true } );
		}
	} );
} )();
