<?php
/**
 * Plugin Name:       Addon Filtros HBook
 * Plugin URI:        https://github.com/dgalcab/addon_hbook
 * Description:       Addon independiente que añade un buscador de alojamientos con filtros combinables por AJAX para el Custom Post Type "hb_accommodation" de HBook. Shortcode: [addon_filtros_hbook].
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            La Casa del Torero
 * Text Domain:       addon-filtros-hbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ══════════════════════════════════════════════
   CONSTANTES DEL ADDON
══════════════════════════════════════════════ */

define( 'ADDON_FILTROS_HBOOK_VERSION', '1.0.0' );
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
			esc_html__( 'Addon Filtros HBook: HBook no tiene ninguna taxonomía registrada para "hb_accommodation" todavía. El shortcode [addon_filtros_hbook] funcionará como listado sin filtros hasta que registres una taxonomía de características y la añadas con el filtro hb_accommodation_taxonomies de HBook.', 'addon-filtros-hbook' )
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

	wp_localize_script(
		'addon-filtros-hbook-public',
		'AddonFiltrosHbook',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'addon_filtros_hbook_nonce' ),
			'i18n'    => array(
				'noResults' => __( 'No se han encontrado alojamientos con esas características.', 'addon-filtros-hbook' ),
				'error'     => __( 'Ha ocurrido un error al cargar los resultados. Inténtalo de nuevo.', 'addon-filtros-hbook' ),
			),
		)
	);

	global $post;
	$should_enqueue = ( $post instanceof WP_Post ) && has_shortcode( $post->post_content, 'addon_filtros_hbook' );

	/**
	 * Permite forzar la carga de los assets del addon en contextos donde
	 * el shortcode no vive en post_content (widgets, plantillas PHP, etc.).
	 */
	$should_enqueue = apply_filters( 'addon_filtros_hbook_should_enqueue', $should_enqueue );

	if ( $should_enqueue ) {
		wp_enqueue_style( 'addon-filtros-hbook-public' );
		wp_enqueue_script( 'addon-filtros-hbook-public' );
	}
}
add_action( 'wp_enqueue_scripts', 'addon_filtros_hbook_enqueue_assets' );

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
