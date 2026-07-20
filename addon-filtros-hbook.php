<?php
/**
 * Plugin Name:       Addon Filtros HBook
 * Plugin URI:        https://github.com/dgalcab/addon_hbook
 * Description:       Addon independiente que añade un buscador de alojamientos con filtros combinables por AJAX para el Custom Post Type "hb_accommodation" de HBook. Shortcode: [addon_filtros_hbook].
 * Version:           2.0.10
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            dgalcab
 * Text Domain:       addon-filtros-hbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ══════════════════════════════════════════════
   CONSTANTES DEL ADDON
══════════════════════════════════════════════ */

define( 'ADDON_FILTROS_HBOOK_VERSION', '2.0.10' );
define( 'ADDON_FILTROS_HBOOK_FILE', __FILE__ );
define( 'ADDON_FILTROS_HBOOK_PATH', plugin_dir_path( __FILE__ ) );
define( 'ADDON_FILTROS_HBOOK_URL', plugin_dir_url( __FILE__ ) );

/** Custom Post Type sobre el que trabaja el addon. */
define( 'ADDON_FILTROS_HBOOK_CPT', 'hb_accommodation' );

/* ══════════════════════════════════════════════
   CARGA DE CLASES
══════════════════════════════════════════════ */

require_once ADDON_FILTROS_HBOOK_PATH . 'includes/class-addon-filtros-engine.php';

/* ══════════════════════════════════════════════
   ABSTRACCIÓN DINÁMICA DE TAXONOMÍAS

   HBook, por defecto, registra el CPT hb_accommodation SIN ninguna
   taxonomía asociada (ver accom-post-type.php: 'taxonomies' =>
   apply_filters( 'hb_accommodation_taxonomies', array() ) ). Ese es
   precisamente el punto de extensión oficial que HBook ofrece: el
   propio sitio (tema, functions.php o un mu-plugin) debe registrar
   sus taxonomías reales (p.ej. "comodidades", "vistas", etc.) y
   añadirlas mediante ese filtro.

   Por tanto el addon NUNCA debe adivinar un slug de taxonomía: en su
   lugar enumera, en tiempo real, todas las taxonomías que estén
   efectivamente asociadas al CPT en ese momento y genera un grupo de
   filtro por cada una.
══════════════════════════════════════════════ */

/**
 * Devuelve los slugs de todas las taxonomías públicas realmente
 * registradas contra hb_accommodation en el sitio actual.
 *
 * @return string[]
 */
function addon_filtros_hbook_get_taxonomies() {
	$taxonomies = get_object_taxonomies( ADDON_FILTROS_HBOOK_CPT, 'names' );

	/**
	 * Permite restringir o reordenar qué taxonomías se muestran como
	 * filtros, sin tener que tocar el código del addon.
	 */
	return apply_filters( 'addon_filtros_hbook_taxonomies', $taxonomies );
}

/* ══════════════════════════════════════════════
   TAXONOMÍA "CARACTERÍSTICAS" DE SERIE

   Para que el addon funcione nada más activarlo, sin necesidad de
   escribir código, se registra aquí mismo una taxonomía propia
   ("accommodation_amenity") asociada a hb_accommodation, usando el
   mecanismo estándar de WordPress (register_taxonomy con el CPT como
   object_type), que funciona con independencia del orden de carga
   entre plugins. El administrador solo tiene que ir a
   WordPress Admin → Alojamiento → Características para crear términos
   (Piscina, Admite mascotas, etc.) y asignarlos a cada alojamiento,
   exactamente igual que con las etiquetas de una entrada.

   Si el sitio ya gestiona sus propias taxonomías de características
   (una o varias) y no quiere esta de serie, se puede desactivar con:
   add_filter( 'addon_filtros_hbook_enable_default_taxonomy', '__return_false' );
══════════════════════════════════════════════ */

define( 'ADDON_FILTROS_HBOOK_DEFAULT_TAXONOMY', 'accommodation_amenity' );

function addon_filtros_hbook_register_default_taxonomy() {
	if ( ! apply_filters( 'addon_filtros_hbook_enable_default_taxonomy', true ) ) {
		return;
	}

	// Si el sitio ya define una taxonomía con este slug, se respeta y no se sobrescribe.
	if ( taxonomy_exists( ADDON_FILTROS_HBOOK_DEFAULT_TAXONOMY ) ) {
		return;
	}

	register_taxonomy(
		ADDON_FILTROS_HBOOK_DEFAULT_TAXONOMY,
		ADDON_FILTROS_HBOOK_CPT,
		array(
			'labels'            => array(
				'name'               => __( 'Características', 'addon-filtros-hbook' ),
				'singular_name'      => __( 'Característica', 'addon-filtros-hbook' ),
				'menu_name'          => __( 'Características', 'addon-filtros-hbook' ),
				'search_items'       => __( 'Buscar características', 'addon-filtros-hbook' ),
				'all_items'          => __( 'Todas las características', 'addon-filtros-hbook' ),
				'edit_item'          => __( 'Editar característica', 'addon-filtros-hbook' ),
				'add_new_item'       => __( 'Añadir nueva característica', 'addon-filtros-hbook' ),
				'new_item_name'      => __( 'Nombre de la nueva característica', 'addon-filtros-hbook' ),
				'parent_item'        => __( 'Característica padre', 'addon-filtros-hbook' ),
				'parent_item_colon'  => __( 'Característica padre:', 'addon-filtros-hbook' ),
			),
			/*
			 * true (jerárquica) es intencional: es lo que hace que WordPress
			 * muestre CHECKBOXES de los términos ya creados en el editor de
			 * cada alojamiento (como "Categorías"), en vez del cuadro de
			 * texto libre tipo "tags" que se usa con hierarchical => false
			 * (como "Etiquetas"). No es necesario crear ninguna jerarquía
			 * real: se puede usar como una simple lista plana de checkboxes,
			 * dejando el campo "característica padre" vacío en todos los términos.
			 */
			'hierarchical'       => true,
			'public'             => true,
			'show_ui'            => true,
			'show_admin_column'  => true,
			'show_in_quick_edit' => true,
			'show_in_rest'       => true,
			'rewrite'            => array( 'slug' => 'caracteristica' ),
		)
	);
}
add_action( 'init', 'addon_filtros_hbook_register_default_taxonomy', 5 );

/* ══════════════════════════════════════════════
   ACTIVACIÓN: aviso no destructivo si falta HBook
══════════════════════════════════════════════ */

function addon_filtros_hbook_activate() {
	set_transient( 'addon_filtros_hbook_activation_notice', true, 30 );
}
register_activation_hook( __FILE__, 'addon_filtros_hbook_activate' );

function addon_filtros_hbook_admin_notices() {
	if ( ! get_transient( 'addon_filtros_hbook_activation_notice' ) ) {
		return;
	}

	delete_transient( 'addon_filtros_hbook_activation_notice' );

	if ( ! post_type_exists( ADDON_FILTROS_HBOOK_CPT ) ) {
		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html__( 'Addon Filtros HBook: no se ha detectado el Custom Post Type "hb_accommodation". Instala y activa HBook antes de usar el shortcode [addon_filtros_hbook]. El addon permanecerá inactivo hasta entonces.', 'addon-filtros-hbook' )
		);
		return;
	}

	if ( empty( addon_filtros_hbook_get_taxonomies() ) ) {
		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html__( 'Addon Filtros HBook: todavía no hay ninguna característica creada. Ve a Alojamiento → Características para crear las tuyas (Piscina, Admite mascotas...) y asígnalas a cada alojamiento. Mientras tanto, el shortcode [addon_filtros_hbook] mostrará el buscador de HBook sin panel de filtros.', 'addon-filtros-hbook' )
		);
	}
}
add_action( 'admin_notices', 'addon_filtros_hbook_admin_notices' );

/* ══════════════════════════════════════════════
   ASSETS PÚBLICOS
   Solo se encolan en páginas donde realmente se
   use el shortcode, para no penalizar el rendimiento
   del resto del sitio.
══════════════════════════════════════════════ */

/**
 * Replica la lógica de HBook (HbUtils::get_accom_link(), en utils/utils.php)
 * para resolver la página real de un alojamiento: HBook permite configurar,
 * por cada hb_accommodation, "usar este post para mostrar el alojamiento"
 * o "usar otra página" (meta 'accom_default_page' / 'accom_linked_page',
 * editable en el propio metabox de HBook en el editor del alojamiento).
 * Esto es exactamente lo que hace falta cuando el sitio tiene, además del
 * CPT de HBook, otro tipo de contenido (p.ej. un CPT "Casas") con la
 * página pública real de cada alojamiento: el permalink del propio
 * hb_accommodation puede no ser la página que el visitante debe ver.
 *
 * @param int $accom_id
 * @return string
 */
function addon_filtros_hbook_get_accom_link( $accom_id ) {
	$target_id = $accom_id;

	if ( get_post_meta( $accom_id, 'accom_default_page', true ) === 'no' ) {
		$linked_page_id = (int) get_post_meta( $accom_id, 'accom_linked_page', true );
		if ( $linked_page_id ) {
			$target_id = $linked_page_id;
		}
	}

	return get_permalink( $target_id );
}

/**
 * Datos de cada alojamiento que el JS necesita para enriquecer las
 * tarjetas sin inventar nada: sus características ya asignadas
 * (badges) y el enlace real a su página pública (para el botón
 * "Reservar" — ver addon_filtros_hbook_get_accom_link()). Una sola
 * consulta, calculada solo cuando el shortcode realmente se va a usar.
 *
 * @return array{ badges: array<int, string[]>, links: array<int, string> }
 */
function addon_filtros_hbook_get_accom_data() {
	$taxonomies = addon_filtros_hbook_get_taxonomies();

	$post_ids = get_posts(
		array(
			'post_type'      => ADDON_FILTROS_HBOOK_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	$badges = array();
	$links  = array();

	foreach ( $post_ids as $post_id ) {
		$links[ $post_id ] = addon_filtros_hbook_get_accom_link( $post_id );

		$names = array();
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$names[] = $term->name;
				}
			}
		}
		if ( $names ) {
			$badges[ $post_id ] = $names;
		}
	}

	return array(
		'badges' => $badges,
		'links'  => $links,
	);
}

function addon_filtros_hbook_enqueue_assets() {
	wp_register_style(
		'addon-filtros-hbook-public',
		ADDON_FILTROS_HBOOK_URL . 'assets/css/addon-filtros-public.css',
		array(),
		ADDON_FILTROS_HBOOK_VERSION
	);

	wp_register_script(
		'addon-filtros-hbook-public',
		ADDON_FILTROS_HBOOK_URL . 'assets/js/addon-filtros-public.js',
		array(),
		ADDON_FILTROS_HBOOK_VERSION,
		true
	);

	global $post;
	$should_enqueue = ( $post instanceof WP_Post ) && has_shortcode( $post->post_content, 'addon_filtros_hbook' );

	/**
	 * Permite forzar la carga de los assets del addon en contextos donde
	 * el shortcode no vive en post_content (widgets, plantillas PHP, etc.).
	 */
	$should_enqueue = apply_filters( 'addon_filtros_hbook_should_enqueue', $should_enqueue );

	if ( ! $should_enqueue ) {
		return;
	}

	$accom_data = addon_filtros_hbook_get_accom_data();

	wp_localize_script(
		'addon-filtros-hbook-public',
		'AddonFiltrosHbook',
		array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'addon_filtros_hbook_nonce' ),
			'badgesMap' => $accom_data['badges'],
			'linksMap'  => $accom_data['links'],
			'i18n'      => array(
				'noResults' => __( 'No se han encontrado alojamientos con esas características.', 'addon-filtros-hbook' ),
				'error'     => __( 'Ha ocurrido un error al cargar los resultados. Inténtalo de nuevo.', 'addon-filtros-hbook' ),
				'reservar'  => __( 'Reservar', 'addon-filtros-hbook' ),
			),
		)
	);

	wp_enqueue_style( 'addon-filtros-hbook-public' );
	wp_enqueue_script( 'addon-filtros-hbook-public' );
}
add_action( 'wp_enqueue_scripts', 'addon_filtros_hbook_enqueue_assets' );

/* ══════════════════════════════════════════════
   PÁGINA PROPIA DE CADA ALOJAMIENTO

   El botón "Reservar" de cada tarjeta enlaza a la página pública real
   del alojamiento (ver addon_filtros_hbook_get_accom_link(): respeta si
   HBook está configurado para mostrarlo en OTRO tipo de contenido, p.ej.
   un CPT "Casas" distinto de "Alojamiento"/hb_accommodation), añadiendo
   las fechas/personas elegidas como parámetros de URL. Este script
   rellena esos mismos campos del formulario real de HBook (tal y como lo
   haría el visitante) y lanza la búsqueda automáticamente.

   Como esa página de destino puede ser de CUALQUIER tipo de contenido
   (no necesariamente hb_accommodation), no se puede usar is_singular()
   para decidir cuándo cargar el script. En su lugar se comprueba
   directamente la señal real: que la URL traiga los parámetros que solo
   añade el propio botón "Reservar" de este addon.
══════════════════════════════════════════════ */

function addon_filtros_hbook_enqueue_accom_page_assets() {
	if ( ! isset( $_GET['addon_checkin'] ) && ! isset( $_GET['addon_checkout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	wp_enqueue_script(
		'addon-filtros-hbook-accom-page',
		ADDON_FILTROS_HBOOK_URL . 'assets/js/addon-filtros-accom-page.js',
		array(),
		ADDON_FILTROS_HBOOK_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'addon_filtros_hbook_enqueue_accom_page_assets' );

/* ══════════════════════════════════════════════
   SHORTCODE
══════════════════════════════════════════════ */

add_shortcode( 'addon_filtros_hbook', array( 'Addon_Filtros_Hbook_Engine', 'render_shortcode' ) );

/* ══════════════════════════════════════════════
   ENDPOINT AJAX (usuarios logueados y visitantes)

   Endpoint deliberadamente ligero: NO calcula disponibilidad ni
   fechas (eso lo sigue haciendo, íntegro y sin tocar, el propio
   [hb_booking_form] de HBook). Solo devuelve qué IDs de alojamiento
   cumplen las características marcadas, para que el JS oculte/muestre
   las tarjetas que HBook ya ha renderizado tras su propia búsqueda.
══════════════════════════════════════════════ */

add_action( 'wp_ajax_addon_filtros_hbook_get_allowed_ids', array( 'Addon_Filtros_Hbook_Engine', 'ajax_get_allowed_ids' ) );
add_action( 'wp_ajax_nopriv_addon_filtros_hbook_get_allowed_ids', array( 'Addon_Filtros_Hbook_Engine', 'ajax_get_allowed_ids' ) );
