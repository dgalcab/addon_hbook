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
assets/js/addon-filtros-public.js          Filtro por visibilidad + badges + botón "Reservar" en el buscador
assets/js/addon-filtros-accom-page.js      Autorrelleno + búsqueda automática en la página de cada alojamiento
```

## Arquitectura: quién hace qué

Esto es importante y está verificado directamente contra el código fuente de HBook, no supuesto:

**El buscador real (fechas, personas, disponibilidad, reserva) lo hace HBook al 100%, sin tocar.** `[addon_filtros_hbook]` incrusta el propio `[hb_booking_form all_accom="yes"]` de HBook: el mismo selector de fechas, adultos/niños, cálculo de disponibilidad y creación de la reserva que usarías si pusieras ese shortcode directamente. Este addon no reimplementa ni intercepta nada de eso — sería el punto más fácil de romper el sitio.

**Las "pills" de características de este addon son un filtro añadido por encima, no un buscador aparte.** Visualmente se funden en un único bloque de búsqueda junto a los campos de fecha/personas de HBook (mismo `<div class="addon-filtros-search-card">`), pero técnicamente siguen siendo dos cosas independientes que se combinan solas:

1. HBook, al buscar, siempre mete sus resultados dentro de un contenedor `<div class="hb-accom-list">`, y cada alojamiento resultante lleva `data-accom-id="ID"` (verificado en `front-end/booking-form/search-form.php` y `front-end/booking-form/available-accom.php` de HBook).
2. Cuando marcas una pill, el addon llama a su propio endpoint AJAX (`addon_filtros_hbook_get_allowed_ids`), que **no calcula fechas ni disponibilidad** — solo hace un `tax_query` puro y devuelve qué IDs de alojamiento tienen esa característica.
3. El JS del addon oculta (con `display:none`, de forma reversible, sin tocar el HTML de HBook) las tarjetas de `.hb-accom-list` cuyo ID no esté en esa lista, y les inyecta badges con sus características (a partir de `AddonFiltrosHbook.badgesMap`, calculado en PHP con los términos ya asignados a cada alojamiento).
4. Un `MutationObserver` reaplica filtro + badges automáticamente cada vez que HBook vuelve a renderizar resultados (nueva búsqueda de fechas), así que da igual el orden en que el usuario marque pills o busque fechas — no hace falta un segundo "Buscar": con marcar las pills y pulsar el único botón "Buscar" de HBook (en cualquier orden) ya sale el resultado combinado.

En ningún momento se interceptan las peticiones AJAX de HBook (`hb_get_available_accom`, `hb_create_resa`...) ni se modifica el HTML que devuelven: solo se restyla vía CSS scopeado (incluida la propia tarjeta de resultado: imagen, precio, botón "Seleccionar esta opción") y se oculta/muestra/enriquece vía JS.

### El botón "Reservar" de cada tarjeta

Es un botón **propio del addon** (`.addon-filtros-reservar-btn`), no los nativos `hb-select-accom`/`hb-view-accom` de HBook — muchos sitios (incluido el que motivó esto) ya traen esos dos ocultos con CSS propio (`display:none !important`), así que en vez de pelear contra ese `!important`, el addon construye el suyo desde cero.

Funciona así:

1. El botón enlaza a la página real del alojamiento (`get_permalink()`, vía `AddonFiltrosHbook.linksMap`), añadiendo la fecha de entrada/salida y adultos/niños de la búsqueda actual como parámetros de URL propios: `?addon_checkin=...&addon_checkout=...&addon_adults=...&addon_children=...`.
2. En esa página (que debe tener su propio `[hb_booking_form accom_id="X"]` incrustado — lo gestiona el propio sitio, no este addon), se carga automáticamente `assets/js/addon-filtros-accom-page.js`, que lee esos parámetros y rellena los campos REALES de HBook exactamente como lo haría un visitante: pone el valor en `.hb-check-in-date`/`.hb-check-out-date` y dispara un evento `keyup` — el mismo evento que el propio datepicker de HBook escucha para sincronizar su estado interno (verificado en `utils/jq-datepick/js/hb-datepick.js` de HBook) — y por último pulsa el botón "Buscar" real.
3. No se reimplementa nada de HBook: solo se simulan, sobre los campos reales, las mismas acciones que haría un visitante con teclado/ratón. La disponibilidad, el precio y la reserva las sigue calculando y creando HBook íntegramente.

Este segundo script (`addon-filtros-hbook-accom-page`) se carga automáticamente en cualquier página individual de `hb_accommodation`, no solo donde esté el shortcode `[addon_filtros_hbook]`.

## Taxonomía "Características" — funciona de serie, sin escribir código

HBook, por defecto, no trae ninguna taxonomía asociada a `hb_accommodation` (verificado en `accom-post-type/accom-post-type.php`: `'taxonomies' => apply_filters('hb_accommodation_taxonomies', array())`, vacío). Para que el addon funcione nada más activarlo, él mismo registra su propia taxonomía **"Características"** (`accommodation_amenity`) asociada a `hb_accommodation`.

No hay que tocar ningún código: en **WordPress Admin → Alojamiento → Características** creas los términos que quieras (Piscina, Admite mascotas, Wifi, Jacuzzi...), y en la pantalla de edición de cada alojamiento los marcas con checkboxes, igual que las **categorías** de una entrada del blog. El addon los detecta automáticamente (`get_object_taxonomies('hb_accommodation')`) y genera el panel de filtros solo.

La taxonomía se registra con `'hierarchical' => true`: es lo que hace que WordPress muestre checkboxes de los términos ya creados en el editor, en vez del cuadro de texto libre tipo "tags" que usan las taxonomías no jerárquicas (como las Etiquetas de un post). No hace falta crear ninguna jerarquía real: se puede dejar como una simple lista plana, sin asignar "característica padre" a ningún término.

Si ya tienes tu propia taxonomía de características (o varias) y no quieres la de serie, desactívala con:

```php
add_filter( 'addon_filtros_hbook_enable_default_taxonomy', '__return_false' );
```

y registra la tuya como cualquier taxonomía de WordPress, asociándola al post type `hb_accommodation` (usa `'hierarchical' => true` si quieres checkboxes en el editor en vez del cuadro de texto libre):

```php
add_action( 'init', function () {
    register_taxonomy( 'mi_taxonomia', 'hb_accommodation', array(
        'label'        => 'Mis características',
        'hierarchical' => true,
        'show_in_rest' => true,
    ) );
} );
```

El addon soporta cualquier número de taxonomías asociadas al CPT: genera un grupo de filtro por cada una que tenga términos.

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
