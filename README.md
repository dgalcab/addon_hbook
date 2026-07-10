# Addon Filtros HBook

Plugin de WordPress independiente que añade filtros por características (piscina, admite mascotas, etc.) al buscador de alojamientos de [HBook](https://maestrel.com/hbook/) (Maestrel), CPT `hb_accommodation`.

Este repositorio es autocontenido: la raíz del repositorio **es** la carpeta del plugin (no depende ni comparte código con ningún tema u otro proyecto).

## Instalación

1. Copia el contenido de este repositorio a `wp-content/plugins/addon-filtros-hbook/` de tu instalación WordPress, junto a HBook (debe estar instalado y activo).
2. Activa **Addon Filtros HBook** en **Plugins**.
3. Inserta el shortcode `[addon_filtros_hbook]` en cualquier página o plantilla.

## Estructura

```
addon-filtros-hbook.php                    Punto de entrada, seguridad, hooks y registro
includes/class-addon-filtros-engine.php    Shortcode y endpoint AJAX (solo IDs por características)
assets/css/addon-filtros-public.css        Estilos encapsulados, responsive
assets/js/addon-filtros-public.js          Filtro por visibilidad sobre los resultados de HBook
```

## Arquitectura: quién hace qué

Esto es importante y está verificado directamente contra el código fuente de HBook, no supuesto:

**El buscador real (fechas, personas, disponibilidad, reserva) lo hace HBook al 100%, sin tocar.** `[addon_filtros_hbook]` incrusta el propio `[hb_booking_form all_accom="yes"]` de HBook: el mismo selector de fechas, adultos/niños, cálculo de disponibilidad y creación de la reserva que usarías si pusieras ese shortcode directamente. Este addon no reimplementa ni intercepta nada de eso — sería el punto más fácil de romper el sitio.

**El panel de "Características" de este addon es un filtro añadido por encima, no un buscador aparte.** Funciona así:

1. HBook, al buscar, siempre mete sus resultados dentro de un contenedor `<div class="hb-accom-list">`, y cada alojamiento resultante lleva `data-accom-id="ID"` (verificado en `front-end/booking-form/search-form.php` y `front-end/booking-form/available-accom.php` de HBook).
2. Cuando marcas una característica, el addon llama a su propio endpoint AJAX (`addon_filtros_hbook_get_allowed_ids`), que **no calcula fechas ni disponibilidad** — solo hace un `tax_query` puro y devuelve qué IDs de alojamiento tienen esa característica.
3. El JS del addon oculta (con `display:none`, de forma reversible, sin tocar el HTML de HBook) las tarjetas de `.hb-accom-list` cuyo ID no esté en esa lista.
4. Un `MutationObserver` reaplica el filtro automáticamente cada vez que HBook vuelve a renderizar resultados (nueva búsqueda de fechas), así que da igual el orden en que el usuario marque características o busque fechas — el resultado final siempre combina ambas cosas.

En ningún momento se interceptan las peticiones AJAX de HBook (`hb_get_available_accom`, `hb_create_resa`...) ni se modifica el HTML que devuelven: solo se restyla vía CSS scopeado y se oculta/muestra vía JS.

## Importante: HBook no trae taxonomías de características por defecto

Verificado en `accom-post-type/accom-post-type.php` de HBook:

```php
register_post_type( 'hb_accommodation', array(
    // ...
    'taxonomies' => apply_filters( 'hb_accommodation_taxonomies', array() ),
) );
```

Por defecto `hb_accommodation` no tiene ninguna taxonomía asociada. Este addon **no adivina** slugs: en cada render enumera dinámicamente (`get_object_taxonomies('hb_accommodation')`) las taxonomías realmente registradas y genera un grupo de checkboxes por cada una. Si no hay ninguna, el panel muestra un aviso en vez de una UI vacía.

### Cómo dar de alta "Piscina", "Admite mascotas", etc.

En el `functions.php` del tema (o en un mu-plugin):

```php
add_action( 'init', function () {
    register_taxonomy( 'accommodation_amenity', 'hb_accommodation', array(
        'label'        => 'Características',
        'hierarchical' => false,
        'show_in_rest' => true,
        'rewrite'      => array( 'slug' => 'caracteristica' ),
    ) );
}, 5 ); // antes de que HBook registre el CPT (prioridad por defecto 10)

add_filter( 'hb_accommodation_taxonomies', function ( $taxonomies ) {
    $taxonomies[] = 'accommodation_amenity';
    return $taxonomies;
} );
```

Después, en **WordPress Admin → Alojamiento**, edita cada `hb_accommodation` y marca sus características desde el nuevo cuadro que aparece en el editor (igual que las etiquetas de una entrada). El addon las detecta solo, sin tocar su código.

## Shortcode

```
[addon_filtros_hbook]
[addon_filtros_hbook redirection_url="https://tusitio.com/gracias/"]
[addon_filtros_hbook thank_you_page_url="https://tusitio.com/gracias/"]
```

`redirection_url`/`thank_you_page_url` se pasan tal cual al `[hb_booking_form]` embebido (mismos atributos que admite ese shortcode de HBook).

## Hooks disponibles

- `addon_filtros_hbook_should_enqueue` (filter): fuerza la carga de CSS/JS cuando el shortcode se imprime fuera de `post_content`.
- `addon_filtros_hbook_taxonomies` (filter): restringe o reordena qué taxonomías de `hb_accommodation` se muestran como filtros.
- `addon_filtros_hbook_query_args` (filter): modifica los argumentos de la consulta que calcula qué IDs cumplen las características marcadas.
