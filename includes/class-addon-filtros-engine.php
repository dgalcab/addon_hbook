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
			<div class="addon-filtros-search-card">
				<?php echo self::render_pills_row(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="addon-filtros-hbook-embed">
					<?php echo do_shortcode( $booking_form_shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Fila de "pills" (chips) de características, pensada para fundirse
	 * visualmente con los campos de fecha/personas de HBook en un único
	 * bloque de búsqueda. Un grupo de pills por cada taxonomía realmente
	 * registrada contra hb_accommodation (ver addon_filtros_hbook_get_taxonomies()).
	 */
	private static function render_pills_row() {
		$taxonomies = addon_filtros_hbook_get_taxonomies();

		if ( empty( $taxonomies ) ) {
			return '';
		}

		$has_terms = false;
		ob_start();
		?>
		<form id="addon-filtros-form" class="addon-filtros-pills-form">
			<?php
			foreach ( $taxonomies as $taxonomy ) {
				$group_markup = self::render_taxonomy_group( $taxonomy );
				if ( $group_markup ) {
					$has_terms = true;
					echo $group_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
			?>
		</form>
		<?php
		$markup = ob_get_clean();

		return $has_terms ? $markup : '';
	}

	/**
	 * Renderiza un grupo de pills (checkboxes estilizados como chips) para
	 * una taxonomía concreta.
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
		<div class="addon-filtros-pill-group" data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>">
			<span class="addon-filtros-pill-group-label"><?php echo esc_html( $label ); ?>:</span>
			<?php foreach ( $terms as $term ) : ?>
				<label class="addon-filtros-pill">
					<input
						type="checkbox"
						class="addon-filtros-pill-input"
						name="addon_filtros[<?php echo esc_attr( $taxonomy ); ?>][]"
						value="<?php echo esc_attr( $term->slug ); ?>"
						data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
					>
					<span class="addon-filtros-pill-label"><?php echo esc_html( $term->name ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>
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

		/*
		 * Cada característica marcada debe ser obligatoria (AND), no
		 * "cualquiera de las marcadas" (OR): un alojamiento con "Piscina" +
		 * "Admite mascotas" marcados debe tener AMBAS, no una de las dos.
		 * Por eso cada término va en su PROPIO bloque de tax_query, aunque
		 * varios pertenezcan a la misma taxonomía — si se agruparan varios
		 * términos en un mismo bloque con 'operator' => 'IN', WordPress los
		 * trataría como "tiene este término O este otro" dentro de ese
		 * bloque, que es precisamente el comportamiento que no queremos.
		 */
		$tax_query = array( 'relation' => 'AND' );
		foreach ( $selected_terms as $taxonomy => $slugs ) {
			foreach ( $slugs as $slug ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => array( $slug ),
					'operator' => 'IN',
				);
			}
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
