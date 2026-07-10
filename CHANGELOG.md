# Changelog

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
