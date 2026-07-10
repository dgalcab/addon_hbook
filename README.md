# Addon Filtros HBook

Plugin de WordPress independiente que añade un buscador de alojamientos con filtros combinables por AJAX sobre el Custom Post Type `hb_accommodation` de HBook.

Este repositorio es autocontenido: la raíz del repositorio **es** la carpeta del plugin (no depende ni comparte código con ningún tema u otro proyecto).

## Instalación

1. Copia el contenido de este repositorio a `wp-content/plugins/addon-filtros-hbook/` de tu instalación WordPress (junto a HBook, ya activo).
2. Activa **Addon Filtros HBook** en **Plugins**.
3. Inserta el shortcode `[addon_filtros_hbook]` en cualquier página o plantilla.

Si el CPT `hb_accommodation` no está disponible al activar, el addon muestra un aviso no bloqueante en el panel de administración y el shortcode devuelve un mensaje de cortesía en lugar de romper la página.

## Estructura

```
addon-filtros-hbook.php                    Punto de entrada, seguridad, hooks y registro
includes/class-addon-filtros-engine.php    WP_Query, shortcode y endpoint AJAX
assets/css/addon-filtros-public.css        Estilos encapsulados, responsive
assets/js/addon-filtros-public.js          Fetch API, estados de carga, envío de filtros
```

## Detección dinámica de taxonomías

El addon busca, en este orden, la taxonomía real registrada contra `hb_accommodation`:

- Categorías: `accommodation_cat` → `category`
- Etiquetas: `accommodation_tag` → `post_tag`

El resultado se expone en las constantes `ADDON_FILTROS_HBOOK_TAX_CAT` y `ADDON_FILTROS_HBOOK_TAX_TAG`, definidas en `init` (prioridad 20) una vez que HBook ha registrado sus taxonomías.

## Integración con el flujo de reserva de HBook

Cada tarjeta enlaza a `{permalink}?reserva_directa={ID}` para evitar un segundo buscador redundante. Si el tema/HBook expone `window.HBook.openBookingModal(id)` en el frontend, el addon usa el modal nativo en su lugar.

La URL base de reserva es filtrable:

```php
add_filter( 'addon_filtros_hbook_booking_page_url', function ( $url, $accommodation_id ) {
    return $url; // Devuelve una URL distinta si la página de reservas no es el permalink del alojamiento.
}, 10, 2 );
```

## Hooks disponibles

- `addon_filtros_hbook_should_enqueue` (filter): fuerza la carga de CSS/JS cuando el shortcode se imprime fuera de `post_content`.
- `addon_filtros_hbook_booking_page_url` (filter): personaliza la URL base de reserva por alojamiento.
