<?php
/**
 * Motor de renderizado y consultas del addon.
 *
 * Arquitectura verificada contra el código fuente real de HBook:
 *
 * - El buscador real (fechas de entrada/salida, adultos/niños,
 *   disponibilidad, y la creación de la reserva) lo sigue haciendo
 *   ÍNTEGRAMENTE el propio [hb_booking_form all_accom="yes"] de HBook,
 *   incrustado sin modificar. Este addon no reimplementa ni intercepta
 *   ese motor: sería arriesgado (rompería la reserva) y redundante.
 * - El panel de "Características" de este addon es un filtro ADICIONAL
 *   que se superpone client-side sobre los resultados que HBook ya ha
 *   renderizado: HBook siempre inyecta sus resultados dentro de un
 *   contenedor `.hb-accom-list`, y cada alojamiento resultante lleva
 *   `data-accom-id="ID"` (ver front-end/booking-form/search-form.php y
 *   front-end/booking-form/available-accom.php de HBook). El JS de este
 *   addon observa ese contenedor y oculta las tarjetas cuyo ID no esté
 *   entre los alojamientos que cumplen las características marcadas.
 * - Este endpoint AJAX solo calcula ESA lista de IDs (tax_query puro,
 *   sin fechas ni disponibilidad); nunca toca el AJAX ni el HTML de
 *   HBook.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Addon_Filtros_Hbook_Engine {

	/**
	 * Acción usada tanto para crear como para verificar el nonce del AJAX.
	 */
	const NONCE_ACTION = 'addon_filtros_hbook_nonce';

	/**
	 * Callback del shortcode [addon_filtros_hbook].
	 *
	 * @param array $atts Atributos del shortcode. 'redirection_url' y
	 *                     'thank_you_page_url' se pasan tal cual al
	 *                     [hb_booking_form] embebido (mismos atributos
	 *                     que admite ese shortcode de HBook).
	 * @return string HTML del wrapper #addon-filtros-wrapper.
	 */
	public static function render_shortcode( $atts = array() ) {
		if ( ! post_type_exists( ADDON_FILTROS_HBOOK_CPT ) ) {
			return '<p class="addon-filtros-hbook-error">' . esc_html__( 'El buscador de alojamientos no está disponible en este momento.', 'addon-filtros-hbook' ) . '</p>';
		}

		if ( ! shortcode_exists( 'hb_booking_form' ) ) {
			return '<p class="addon-filtros-hbook-error">' . esc_html__( 'HBook no está activo: el formulario de reserva no está disponible.', 'addon-filtros-hbook' ) . '</p>';
		}

		wp_enqueue_style( 'addon-filtros-hbook-public' );
		wp_enqueue_script( 'addon-filtros-hbook-public' );

		$atts = shortcode_atts(
			array(
				'redirection_url'    => '',
				'thank_you_page_url' => '',
			),
			$atts,
			'addon_filtros_hbook'
		);

		$booking_form_shortcode = '[hb_booking_form all_accom="yes"';
		if ( $atts['redirection_url'] ) {
			$booking_form_shortcode .= ' redirection_url="' . esc_attr( $atts['redirection_url'] ) . '"';
		} elseif ( $atts['thank_you_page_url'] ) {
			$booking_form_shortcode .= ' thank_you_page_url="' . esc_attr( $atts['thank_you_page_url'] ) . '"';
		}
		$booking_form_shortcode .= ']';

		ob_start();
		?>
		<div id="addon-filtros-wrapper" class="addon-filtros-wrapper">
			<?php echo self::render_filters_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div class="addon-filtros-results">
				<?php echo do_shortcode( $booking_form_shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
		<div class="addon-filtros-panel-overlay" id="addon-filtros-panel-overlay"></div>
		<button type="button" class="addon-filtros-toggle" id="addon-filtros-toggle" aria-controls="addon-filtros-panel" aria-expanded="false">
			<?php esc_html_e( 'Filtrar Características', 'addon-filtros-hbook' ); ?>
		</button>
		<?php
		return ob_get_clean();
	}

	/**
	 * Bloque estructural izquierdo: formulario de filtros, con un grupo de
	 * checkboxes por cada taxonomía realmente registrada contra
	 * hb_accommodation (ver addon_filtros_hbook_get_taxonomies()).
	 */
	private static function render_filters_panel() {
		$taxonomies = addon_filtros_hbook_get_taxonomies();

		ob_start();
		?>
		<div class="addon-filtros-panel" id="addon-filtros-panel">
			<div class="addon-filtros-panel-header">
				<h3 class="addon-filtros-panel-title"><?php esc_html_e( 'Características', 'addon-filtros-hbook' ); ?></h3>
				<button type="button" class="addon-filtros-panel-close" id="addon-filtros-panel-close" aria-label="<?php esc_attr_e( 'Cerrar filtros', 'addon-filtros-hbook' ); ?>">&times;</button>
			</div>
			<?php if ( empty( $taxonomies ) ) : ?>
				<p class="addon-filtros-no-taxonomies">
					<?php esc_html_e( 'Todavía no hay características configuradas para los alojamientos.', 'addon-filtros-hbook' ); ?>
				</p>
			<?php else : ?>
				<p class="addon-filtros-panel-hint">
					<?php esc_html_e( 'Marca las características que buscas. Se aplican sobre los resultados de fechas de abajo.', 'addon-filtros-hbook' ); ?>
				</p>
				<form id="addon-filtros-form" class="addon-filtros-form">
					<?php
					foreach ( $taxonomies as $taxonomy ) {
						echo self::render_taxonomy_group( $taxonomy ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					?>
				</form>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renderiza un grupo de checkboxes para una taxonomía concreta.
	 *
	 * @param string $taxonomy Slug de la taxonomía, ya verificado como registrado.
	 */
	private static function render_taxonomy_group( $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		$taxonomy_object = get_taxonomy( $taxonomy );
		$label           = $taxonomy_object ? $taxonomy_object->labels->name : $taxonomy;

		ob_start();
		?>
		<fieldset class="addon-filtros-group" data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>">
			<legend class="addon-filtros-group-title"><?php echo esc_html( $label ); ?></legend>
			<?php foreach ( $terms as $term ) : ?>
				<label class="addon-filtros-checkbox">
					<input
						type="checkbox"
						class="addon-filtros-checkbox-input"
						name="addon_filtros[<?php echo esc_attr( $taxonomy ); ?>][]"
						value="<?php echo esc_attr( $term->slug ); ?>"
						data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
					>
					<span class="addon-filtros-checkbox-label"><?php echo esc_html( $term->name ); ?></span>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<?php
		return ob_get_clean();
	}

	/**
	 * Endpoint AJAX: wp_ajax_addon_filtros_hbook_get_allowed_ids /
	 * wp_ajax_nopriv_addon_filtros_hbook_get_allowed_ids.
	 *
	 * Devuelve únicamente los IDs de hb_accommodation que cumplen TODAS
	 * las características seleccionadas (tax_query puro). No calcula
	 * fechas ni disponibilidad: eso es responsabilidad exclusiva del
	 * [hb_booking_form] de HBook, incrustado sin modificar.
	 */
	public static function ajax_get_allowed_ids() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$raw_filters    = isset( $_POST['filtros'] ) && is_array( $_POST['filtros'] ) ? wp_unslash( $_POST['filtros'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$selected_terms = array();

		foreach ( $raw_filters as $taxonomy => $slugs ) {
			$taxonomy = sanitize_key( $taxonomy );

			if ( ! taxonomy_exists( $taxonomy ) || ! is_array( $slugs ) ) {
				continue;
			}

			$clean_slugs = array();
			foreach ( $slugs as $slug ) {
				$clean_slug = sanitize_text_field( $slug );
				if ( '' !== $clean_slug ) {
					$clean_slugs[] = $clean_slug;
				}
			}

			if ( ! empty( $clean_slugs ) ) {
				$selected_terms[ $taxonomy ] = $clean_slugs;
			}
		}

		if ( empty( $selected_terms ) ) {
			wp_send_json_success(
				array(
					'active' => false,
					'ids'    => array(),
				)
			);
		}

		$tax_query = array( 'relation' => 'AND' );
		foreach ( $selected_terms as $taxonomy => $slugs ) {
			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => $slugs,
				'operator' => 'IN',
			);
		}

		$args = array(
			'post_type'      => ADDON_FILTROS_HBOOK_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		);

		$args = apply_filters( 'addon_filtros_hbook_query_args', $args, $selected_terms );

		$ids = get_posts( $args );

		wp_send_json_success(
			array(
				'active' => true,
				'ids'    => array_map( 'intval', $ids ),
			)
		);
	}
}
