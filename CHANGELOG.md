# Changelog

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
