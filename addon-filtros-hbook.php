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
   Detecta qué taxonomías reales están registradas contra
   el CPT hb_accommodation y las expone como constantes
   globales para que el resto del addon nunca tenga que
   adivinar el slug.
══════════════════════════════════════════════ */

/**
 * Recorre una lista de slugs candidatos y devuelve el primero que
 * exista como taxonomía y esté realmente asociado al CPT del addon.
 *
 * @param string[] $candidates Slugs candidatos, en orden de prioridad.
 * @return string Slug de la taxonomía detectada, o cadena vacía si ninguna aplica.
 */
function addon_filtros_hbook_detect_taxonomy( array $candidates ) {
	$registered = get_object_taxonomies( ADDON_FILTROS_HBOOK_CPT );

	foreach ( $candidates as $candidate ) {
		if ( taxonomy_exists( $candidate ) && in_array( $candidate, $registered, true ) ) {
			return $candidate;
		}
	}

	return '';
}

/**
 * Define las constantes de taxonomía una vez que todos los CPT y
 * taxonomías (HBook, tema, etc.) ya están registrados en 'init'.
 */
function addon_filtros_hbook_bootstrap_taxonomies() {
	if ( ! defined( 'ADDON_FILTROS_HBOOK_TAX_CAT' ) ) {
		define( 'ADDON_FILTROS_HBOOK_TAX_CAT', addon_filtros_hbook_detect_taxonomy( array( 'accommodation_cat', 'category' ) ) );
	}

	if ( ! defined( 'ADDON_FILTROS_HBOOK_TAX_TAG' ) ) {
		define( 'ADDON_FILTROS_HBOOK_TAX_TAG', addon_filtros_hbook_detect_taxonomy( array( 'accommodation_tag', 'post_tag' ) ) );
	}
}
add_action( 'init', 'addon_filtros_hbook_bootstrap_taxonomies', 20 );

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
══════════════════════════════════════════════ */

add_action( 'wp_ajax_addon_filtros_hbook_filter', array( 'Addon_Filtros_Hbook_Engine', 'ajax_filter' ) );
add_action( 'wp_ajax_nopriv_addon_filtros_hbook_filter', array( 'Addon_Filtros_Hbook_Engine', 'ajax_filter' ) );
