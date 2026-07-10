# Addon Filtros HBook

Plugin de WordPress independiente que añade un buscador de alojamientos con filtros combinables por AJAX sobre el Custom Post Type `hb_accommodation` del plugin [HBook](https://maestrel.com/hbook/) (Maestrel).

Este repositorio es autocontenido: la raíz del repositorio **es** la carpeta del plugin (no depende ni comparte código con ningún tema u otro proyecto).

## Instalación

1. Copia el contenido de este repositorio a `wp-content/plugins/addon-filtros-hbook/` de tu instalación WordPress, junto a HBook (debe estar instalado y activo).
2. Activa **Addon Filtros HBook** en **Plugins**.
3. Inserta el shortcode `[addon_filtros_hbook]` en cualquier página o plantilla.

Si el CPT `hb_accommodation` no está disponible al activar, el addon muestra un aviso no bloqueante en el panel de administración y el shortcode devuelve un mensaje de cortesía en lugar de romper la página.

## Estructura

```
addon-filtros-hbook.php                    Punto de entrada, seguridad, hooks y registro
includes/class-addon-filtros-engine.php    WP_Query, shortcode y endpoint AJAX
assets/css/addon-filtros-public.css        Estilos encapsulados, responsive
assets/js/addon-filtros-public.js          Fetch API, estados de carga, toggle de reserva
```

## Importante: HBook no trae taxonomías de características por defecto

Se ha verificado directamente en el código fuente de HBook (`accom-post-type/accom-post-type.php`) que el CPT se registra así:

```php
register_post_type( 'hb_accommodation', array(
    // ...
    'taxonomies' => apply_filters( 'hb_accommodation_taxonomies', array() ),
) );
```

Es decir: **por defecto `hb_accommodation` no tiene ninguna taxonomía asociada** ("categorías", "etiquetas", "comodidades"... no existen hasta que el propio sitio las crea). `hb_accommodation_taxonomies` es el punto de extensión oficial que HBook ofrece para esto.

Este addon **no adivina** slugs de taxonomía: en cada render enumera dinámicamente (`get_object_taxonomies('hb_accommodation')`) las taxonomías que estén realmente registradas en ese momento y genera un grupo de checkboxes por cada una. Si no hay ninguna, el panel de filtros muestra un aviso informativo en vez de una UI vacía o inventada.

### Cómo registrar tu propia taxonomía de características

En el `functions.php` del tema (o en un plugin propio), por ejemplo:

```php
add_action( 'init', function () {
    register_taxonomy( 'accommodation_amenity', 'hb_accommodation', array(
        'label'        => 'Comodidades',
        'hierarchical' => false,
        'show_in_rest' => true,
        'rewrite'      => array( 'slug' => 'comodidad' ),
    ) );
}, 5 ); // antes de que HBook registre el CPT (prioridad por defecto 10)

add_filter( 'hb_accommodation_taxonomies', function ( $taxonomies ) {
    $taxonomies[] = 'accommodation_amenity';
    return $taxonomies;
} );
```

En cuanto la taxonomía y el filtro existan, `[addon_filtros_hbook]` la detecta sola, sin tocar el código del addon.

## Integración con el flujo de reserva de HBook

Se ha verificado en `front-end/renders/accom-list-render.php` y `front-end/js/accommodation-list.js` de HBook que su propio listado (`[hb_accommodation_list book_button="yes"]`) **no usa modales ni parámetros de URL**: incrusta, oculto, el shortcode `[hb_booking_form accom_id="ID"]` junto a cada alojamiento y lo despliega al pulsar "Reservar".

Este addon reproduce exactamente ese mecanismo, en vez de inventar uno nuevo: cada tarjeta incluye su propio `[hb_booking_form accom_id="ID"]` renderizado vía `do_shortcode()`, oculto con CSS y desplegado con un botón "Reservar" que solo hace *toggle* de visibilidad. Al estar scopeado con `accom_id`, el widget de HBook arranca directamente en el calendario/formulario de ese alojamiento — sin mostrar un segundo buscador general.

El shortcode acepta los mismos atributos opcionales que `[hb_accommodation_list]` para personalizar ese formulario embebido:

```
[addon_filtros_hbook redirection_url="https://tusitio.com/gracias/"]
[addon_filtros_hbook thank_you_page_url="https://tusitio.com/gracias/"]
```

## Reutilización de las utilidades de HBook

Para título, enlace, descripción de listado, miniatura y el texto del botón "Reservar", el addon usa las mismas funciones que usa el propio HBook (`$hbook->utils->get_accom_title()`, `get_accom_link()`, `get_accom_list_desc()`, `get_thumb_mark_up()`, `get_strings()`), accesibles vía la instancia pública `$hbook->utils` (HBook la instancia en el ámbito global de `hbook.php`). Esto respeta páginas enlazadas (`accom_default_page`/`accom_linked_page`), permalinks traducidos y el texto de botón configurado por el administrador en HBook → Textos. Si por algún motivo `$hbook` no está disponible, cae a funciones nativas de WordPress.

## Nota sobre multi-idioma

La consulta de alojamientos usa `WP_Query` directo (`post_type => hb_accommodation`). HBook internamente filtra duplicados por idioma en instalaciones con Polylang/WPML mediante un método privado (`HbDataBaseActions::get_all_accom_ids()`) al que este addon, al ser independiente, no tiene acceso. En sitios sin Polylang/WPML (p. ej. con TranslatePress, que no duplica posts) esto no supone ninguna diferencia. Si tu sitio usa Polylang o WPML con posts duplicados por idioma, filtra la consulta con:

```php
add_filter( 'addon_filtros_hbook_query_args', function ( $args, $selected_terms ) {
    // Ajusta $args según tu configuración de idiomas.
    return $args;
}, 10, 2 );
```

## Hooks disponibles

- `addon_filtros_hbook_should_enqueue` (filter): fuerza la carga de CSS/JS cuando el shortcode se imprime fuera de `post_content`.
- `addon_filtros_hbook_taxonomies` (filter): restringe o reordena qué taxonomías de `hb_accommodation` se muestran como filtros.
- `addon_filtros_hbook_query_args` (filter): modifica los argumentos del `WP_Query` (por ejemplo, para multi-idioma).
