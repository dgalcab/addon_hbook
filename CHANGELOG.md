# Changelog

## 2.2.0

Rediseño del bloque de características, con un cambio de comportamiento explícitamente pedido:

- Las características ya NO se muestran junto a los campos de fecha/personas desde el principio: permanecen ocultas hasta que se pulsa "Buscar" y HBook renderiza al menos un alojamiento para esas fechas. No tenía sentido dejar elegir características antes de tener ningún resultado sobre el que aplicarlas.
  - El JS ya no reubica el bloque dentro de `.hb-search-fields` (enfoque de la 2.1.0): ahora lo coloca, una única vez al cargar la página, como hermano inmediatamente anterior a `.hb-accom-list` (donde HBook renderiza los resultados), y alterna una clase (`.is-visible`) cada vez que esa lista pasa de vacía a tener tarjetas o al revés (incluida una nueva búsqueda de fechas sin resultados, que también lo vuelve a ocultar).
- Nueva cabecera del bloque ("Filtrar por características") con un botón "Limpiar" que solo aparece si hay alguna característica marcada, para quitarlas todas de un vistazo sin tener que ir chip a chip.
- En móvil, las características pasan a mostrarse como una tira horizontal deslizable con scroll-snap (en vez de envolver en varias filas, que ocupaba mucho alto), con la etiqueta de cada grupo fija a la izquierda mientras se desliza — más compacto y más fácil de recorrer con el pulgar.
- El filtrado sigue siendo instantáneo (se sigue pidiendo la lista de IDs al marcar/desmarcar, sin esperar a ningún envío de formulario) y el botón "Reservar" de cada tarjeta sigue funcionando exactamente igual que antes (mismos parámetros de URL, mismo destino).

## 2.1.1

- Más aire en la caja de búsqueda en móvil: más padding interior de la tarjeta, más separación entre los campos apilados, entre el bloque de características y el botón "Buscar", y el propio botón "Buscar" pasa a ocupar todo el ancho (más cómodo de pulsar y más acorde con el resto del diseño).

## 2.1.0

- En móvil, las características ahora aparecen **después** de los campos de fecha/personas y **antes** del botón "Buscar" (antes iban siempre delante de todo, igual que en escritorio). En escritorio se mantiene el diseño actual (fila propia encima de los campos).
  - El bloque de características ya no es un `<form>` sino un `<div>`: el JS lo reubica dentro del propio `<form>` de HBook para poder reordenarlo por CSS (`order`) según el tamaño de pantalla, y un `<form>` anidado dentro de otro es HTML inválido.
  - Se sustituye el maquetado por floats que usa HBook para esos campos por flexbox (mismo HTML, solo CSS), lo que permite controlar el orden visual sin duplicar nada ni tocar la lógica de HBook.
- Las pills de características se ven más compactas en móvil (menos relleno, texto más pequeño), para que ocupen menos espacio vertical.

## 2.0.11

- Tras arreglar la caché de Themify, el bucle pasó a ocurrir solo una vez (la primera carga real seguía adelantándose a HBook), y la segunda vez el propio freno de seguridad (añadido en la 2.0.10 para evitar bucles infinitos) bloqueaba el reintento, dejando los campos en blanco — parecía "no coger los datos" pero en realidad eran dos problemas distintos ya diagnosticados. La causa raíz seguía siendo la comprobación de "¿está listo HBook?": comprobar que la librería `jQuery.datepick` existe no basta, porque esa librería queda definida en cuanto se carga el archivo, antes de que HBook ejecute su propia inicialización (la que realmente engancha los eventos sobre los campos de fecha). Ahora se espera a una señal fiable de que esa inicialización ya ha corrido de verdad: el propio calendario emergente de HBook (`.hb-datepick-popup-wrapper`), que HBook añade al `<body>` nada más entrar en su `jQuery(document).ready()`.

## 2.0.10

- En el sitio real (con más plugins/optimización de carga que en las pruebas anteriores) volvió a aparecer el "bucle": la consola mostraba errores de `wp is not defined` en otros scripts del sitio, señal de que la carga de scripts va desordenada/diferida ahí. El script de autorrelleno encontraba los campos de fecha en el HTML antes de que el propio JS de HBook (jQuery.datepick, el manejador del envío del formulario) hubiera terminado de cargar; al pulsar "Buscar" en ese momento, el navegador caía en el envío nativo del formulario — una recarga real de la página, que volvía a ejecutar el script desde cero y repetía el problema.
  - Ahora se espera explícitamente (hasta 15s) a que `jQuery`, `jQuery.datepick` y `hb_date_format` estén realmente disponibles antes de tocar ningún campo.
  - Además, como red de seguridad ante cualquier otra causa de recarga en bucle, el script ahora se autolimita a un único intento por URL exacta y por sesión de navegador (sessionStorage): si la misma URL se cargara dos veces, la segunda vez no se reintenta nada.

## 2.0.9

- Encontrado un fallo real de conteo: HBook pone `data-accom-id` no solo en cada tarjeta (`.hb-accom`), sino también en un `.hb-accom-quantity` oculto que genera por cada alojamiento (y, en algunos casos, en un `<select>` interno). El selector genérico `[data-accom-id]` que usaba el addon para contar/filtrar tarjetas contaba también esos elementos ocultos, desajustando el recuento (incluido el de la frase "Hemos encontrado X..."). Corregido restringiendo el selector a `.hb-accom[data-accom-id]` en todo el script (filtro de visibilidad, badges, botón "Reservar" y conteo).

## 2.0.8

- La frase de HBook "Hemos encontrado X tipos de alojamiento..." mostraba siempre el conteo de la búsqueda real (antes del filtro de características), no el número de tarjetas que quedaban visibles tras filtrar. Ahora, al aplicar el filtro, se sustituye el número dentro de esa misma frase por el conteo visible real (sin tocar el resto del texto ni inventar traducción), y se restaura el texto original de HBook tal cual si se quitan todos los filtros.

## 2.0.7

- Marcar dos o más características (p. ej. "Admite mascotas" + "Piscina") funcionaba como OR (mostraba alojamientos con cualquiera de las dos) en vez de AND (solo los que tienen ambas). Al vivir todas las características en la misma taxonomía, se agrupaban en un único bloque `tax_query` con `operator => IN`, que WordPress interpreta como "tiene este término O este otro". Ahora cada característica marcada va en su propio bloque, todos combinados con AND, así que cada una pasa a ser obligatoria — sumen las que sumen.

## 2.0.6

- Las fechas y personas se rellenaban bien, pero era "como si no se pulsara Buscar": el cierre forzado del calendario emergente (añadido en la 2.0.5) podía fallar a mitad de camino, y al ser código síncrono, un error ahí cortaba la ejecución antes de llegar al clic en "Buscar". Ahora esa parte va protegida en un try/catch (si falla, se avisa por consola pero se continúa igualmente) y el clic en "Buscar" se lanza en una tarea aparte, para no depender de que termine ninguna animación pendiente del calendario.

## 2.0.5

- El calendario emergente de HBook se quedaba visible tapando la pantalla tras el autorrelleno (parecía que la página se "quedaba en bucle"): el script ponía el valor de las fechas sin enfocar antes los campos, así que HBook nunca distinguía si se estaba eligiendo la fecha de entrada o la de salida, y el calendario no llegaba a cerrarse porque su propio cierre-al-hacer-clic-fuera ignora los clics durante el primer segundo tras abrirse (y este script rellena ambos campos en milisegundos). Ahora se enfoca cada campo antes de rellenarlo (como haría un visitante) y se fuerza el cierre del calendario justo después, antes de lanzar la búsqueda.

## 2.0.4

- Tras aterrizar en la página del alojamiento con las fechas ya rellenadas y la búsqueda lanzada, el script se paraba en el paso 1 ("alojamiento seleccionado") sin avanzar al paso 2 ("servicios adicionales"). En una página de un único alojamiento, HBook autoselecciona ese resultado solo, pero sigue haciendo falta pulsar su botón "Siguiente" (`.hb-next-step-1`) para continuar — ahora el script espera (la búsqueda es AJAX) a que ese botón esté visible y lo pulsa automáticamente, igual que haría el visitante.

## 2.0.3

- El botón "Reservar" enlazaba siempre al permalink del propio `hb_accommodation`, pero HBook permite configurar, por alojamiento, que la página pública real sea OTRA (meta `accom_default_page`/`accom_linked_page`, editable en el propio metabox de HBook) — típico en sitios que tienen un CPT aparte (p.ej. "Casas") para el contenido público, separado del alojamiento técnico de HBook. Ahora se replica esa misma lógica (`addon_filtros_hbook_get_accom_link()`) para enlazar siempre a la página correcta.
- El script de autorrelleno en la página de destino ya no depende de que esa página sea de tipo `hb_accommodation` (podía no cargarse si el CPT real de destino era otro, como "Casas"): ahora se activa simplemente cuando la URL trae los parámetros `addon_checkin`/`addon_checkout`, sea cual sea el tipo de contenido de la página.

## 2.0.2

- El botón "Reservar" navegaba a la home en vez de a la casa: la URL llevaba las fechas en formato local con barras (`12%2F10%2F2026`), y muchos hostings/firewalls bloquean o redirigen peticiones con barras codificadas repetidas en la query string. Ahora las fechas viajan en formato ISO (`2026-10-12`, sin barras) y se reconvierten al formato local del sitio al aterrizar en la página del alojamiento, usando la propia utilidad de fechas de HBook (`$.datepick.parseDate`/`formatDate`) en ambos lados — sin necesidad de adivinar el formato configurado.

## 2.0.1

- Corrige el grid de tarjetas de resultado: el CSS apuntaba a `.hb-accom-list`, pero cada `.hb-accom` en realidad cuelga de `.hb-multi-accom-choices` (dos niveles más adentro), así que el grid no tenía ningún efecto y las tarjetas seguían apilándose en una columna. Corregido apuntando al contenedor correcto, con más especificidad que el CSS personalizado que ya hubiera en el sitio.

## 2.0.0

Rediseño completo de la arquitectura de búsqueda (no solo mejoras incrementales sobre la 1.0):

- El buscador ya no genera su propio listado con `WP_Query`: incrusta y usa el `[hb_booking_form all_accom="yes"]` real de HBook (fechas, personas, disponibilidad, precio, reserva — todo nativo, sin tocar).
- Las características pasan de checkboxes en un panel lateral a "pills" (chips) fundidas en un único bloque de búsqueda junto a los campos de HBook.
- Se elimina el panel off-canvas / botón flotante de móvil (ya no hace falta con el nuevo layout).
- Las tarjetas de resultado (generadas por HBook, solo restyladas) ahora muestran badges con las características reales de cada alojamiento.
- Nuevo botón "Reservar" propio por tarjeta: enlaza a la página del alojamiento con las fechas/personas de la búsqueda actual como parámetros de URL.
- Nuevo script (`addon-filtros-accom-page.js`), cargado en las páginas individuales de cada alojamiento, que rellena el buscador real de HBook con esos parámetros y lanza la búsqueda automáticamente.
- Corrección de layout: `.hb-accom-list` se muestra en grid superando el `display:block` inline que jQuery `.slideDown()` de HBook deja tras la animación.
- La taxonomía "Características" pasa a `hierarchical => true` para que WordPress la muestre como checkboxes en el editor (no como el cuadro de texto libre de las taxonomías tipo "tags").
- Endpoint AJAX renombrado (`addon_filtros_hbook_get_allowed_ids`) y reducido a devolver solo IDs (sin fechas ni HTML), ya que el listado en sí lo genera HBook.

## 1.0.0

Primera versión: shortcode `[addon_filtros_hbook]` con panel de filtros por características (taxonomía propia registrada de serie) y grid de tarjetas propio generado con `WP_Query`, con botón de reserva embebiendo `[hb_booking_form accom_id="ID"]` por tarjeta.
