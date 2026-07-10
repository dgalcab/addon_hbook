<?php
/**
 * Motor de renderizado y consultas del addon.
 *
 * Se encarga de:
 * - Renderizar el shortcode [addon_filtros_hbook] (panel de filtros + grid de resultados).
 * - Construir el WP_Query combinable a partir de los términos de taxonomía seleccionados.
 * - Atender el endpoint AJAX que recalcula el grid sin recargar la página.
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
	 * @param array $atts Atributos del shortcode.
	 * @return string HTML del wrapper #addon-filtros-wrapper.
	 */
	public static function render_shortcode( $atts = array() ) {
		if ( ! post_type_exists( ADDON_FILTROS_HBOOK_CPT ) ) {
			return '<p class="addon-filtros-hbook-error">' . esc_html__( 'El buscador de alojamientos no está disponible en este momento.', 'addon-filtros-hbook' ) . '</p>';
		}

		// Garantiza los assets aunque el shortcode se ejecute fuera de post_content (do_shortcode en plantillas).
		wp_enqueue_style( 'addon-filtros-hbook-public' );
		wp_enqueue_script( 'addon-filtros-hbook-public' );

		shortcode_atts( array(), $atts, 'addon_filtros_hbook' );

		ob_start();
		?>
		<div id="addon-filtros-wrapper" class="addon-filtros-wrapper">
			<?php echo self::render_filters_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo self::render_results_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<div class="addon-filtros-panel-overlay" id="addon-filtros-panel-overlay"></div>
		<button type="button" class="addon-filtros-toggle" id="addon-filtros-toggle" aria-controls="addon-filtros-panel" aria-expanded="false">
			<?php esc_html_e( 'Filtrar Características', 'addon-filtros-hbook' ); ?>
		</button>
		<?php
		return ob_get_clean();
	}

	/**
	 * Bloque estructural izquierdo: formulario de filtros.
	 */
	private static function render_filters_panel() {
		ob_start();
		?>
		<div class="addon-filtros-panel" id="addon-filtros-panel">
			<div class="addon-filtros-panel-header">
				<h3 class="addon-filtros-panel-title"><?php esc_html_e( 'Características', 'addon-filtros-hbook' ); ?></h3>
				<button type="button" class="addon-filtros-panel-close" id="addon-filtros-panel-close" aria-label="<?php esc_attr_e( 'Cerrar filtros', 'addon-filtros-hbook' ); ?>">&times;</button>
			</div>
			<form id="addon-filtros-form" class="addon-filtros-form">
				<?php
				echo self::render_taxonomy_group( ADDON_FILTROS_HBOOK_TAX_CAT, __( 'Categorías', 'addon-filtros-hbook' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::render_taxonomy_group( ADDON_FILTROS_HBOOK_TAX_TAG, __( 'Etiquetas', 'addon-filtros-hbook' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renderiza un grupo de checkboxes para una taxonomía concreta.
	 *
	 * @param string $taxonomy Slug de la taxonomía (ya resuelto dinámicamente).
	 * @param string $label    Título visible del grupo.
	 */
	private static function render_taxonomy_group( $taxonomy, $label ) {
		if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

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
	 * Bloque estructural derecho: grid de resultados renderizado en servidor
	 * con la consulta inicial (sin filtros aplicados).
	 */
	private static function render_results_panel() {
		$query = self::build_query( array() );
		ob_start();
		?>
		<div class="addon-filtros-results">
			<div class="addon-filtros-grid" id="addon-filtros-grid" aria-busy="false">
				<?php echo self::render_cards( $query ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
		<?php
		wp_reset_postdata();
		return ob_get_clean();
	}

	/**
	 * Construye el WP_Query combinable a partir de los términos seleccionados.
	 *
	 * @param array $selected_terms Array asociativo [ taxonomy_slug => [ term_slug, ... ] ].
	 * @return WP_Query
	 */
	private static function build_query( array $selected_terms ) {
		$tax_query = array( 'relation' => 'AND' );

		foreach ( $selected_terms as $taxonomy => $slugs ) {
			if ( ! taxonomy_exists( $taxonomy ) || empty( $slugs ) ) {
				continue;
			}

			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => $slugs,
				'operator' => 'IN',
			);
		}

		$args = array(
			'post_type'      => ADDON_FILTROS_HBOOK_CPT,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
		);

		if ( count( $tax_query ) > 1 ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		return new WP_Query( $args );
	}

	/**
	 * Renderiza el HTML de las tarjetas para un WP_Query ya ejecutado.
	 *
	 * @param WP_Query $query
	 * @return string
	 */
	private static function render_cards( WP_Query $query ) {
		if ( ! $query->have_posts() ) {
			return '<p class="addon-filtros-empty">' . esc_html__( 'No se han encontrado alojamientos con esas características.', 'addon-filtros-hbook' ) . '</p>';
		}

		ob_start();
		while ( $query->have_posts() ) {
			$query->the_post();
			self::render_card( get_the_ID() );
		}
		wp_reset_postdata();
		return ob_get_clean();
	}

	/**
	 * Renderiza una única tarjeta de alojamiento.
	 *
	 * @param int $post_id
	 */
	private static function render_card( $post_id ) {
		$title       = get_the_title( $post_id );
		$permalink   = get_permalink( $post_id );
		$thumbnail   = get_the_post_thumbnail(
			$post_id,
			'medium_large',
			array(
				'class'   => 'addon-filtros-card-img',
				'loading' => 'lazy',
				'alt'     => esc_attr( $title ),
			)
		);
		$badges      = self::get_card_badges( $post_id );
		$booking_url = self::get_booking_url( $post_id, $permalink );
		?>
		<div class="addon-filtros-card" data-accommodation-id="<?php echo esc_attr( $post_id ); ?>">
			<div class="addon-filtros-card-media">
				<a href="<?php echo esc_url( $permalink ); ?>" tabindex="-1">
					<?php
					if ( $thumbnail ) {
						echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					} else {
						echo '<div class="addon-filtros-card-img addon-filtros-card-img--placeholder"></div>';
					}
					?>
				</a>
			</div>
			<div class="addon-filtros-card-body">
				<h4 class="addon-filtros-card-title">
					<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
				</h4>
				<?php if ( ! empty( $badges ) ) : ?>
					<ul class="addon-filtros-card-badges">
						<?php foreach ( $badges as $badge ) : ?>
							<li class="addon-filtros-badge"><?php echo esc_html( $badge ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<a
					href="<?php echo esc_url( $booking_url ); ?>"
					class="addon-filtros-book-btn"
					data-accommodation-id="<?php echo esc_attr( $post_id ); ?>"
				>
					<?php esc_html_e( 'Reservar', 'addon-filtros-hbook' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Recopila los nombres de términos (categoría + etiqueta) de un alojamiento
	 * para mostrarlos como badges dentro de la tarjeta.
	 *
	 * @param int $post_id
	 * @return string[]
	 */
	private static function get_card_badges( $post_id ) {
		$badges = array();

		foreach ( array( ADDON_FILTROS_HBOOK_TAX_CAT, ADDON_FILTROS_HBOOK_TAX_TAG ) as $taxonomy ) {
			if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_the_terms( $post_id, $taxonomy );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$badges[] = $term->name;
				}
			}
		}

		return $badges;
	}

	/**
	 * Calcula la URL de reserva directa para una tarjeta: la página del
	 * alojamiento con el parámetro ?reserva_directa=ID añadido, de forma
	 * que el flujo de HBook arranque directamente en la confirmación de
	 * fechas/pasarela de pago sin mostrar un segundo buscador.
	 *
	 * Filtrable con 'addon_filtros_hbook_booking_page_url' por si la
	 * página de reservas de HBook no es el propio permalink del alojamiento.
	 *
	 * @param int    $post_id
	 * @param string $permalink
	 * @return string
	 */
	private static function get_booking_url( $post_id, $permalink ) {
		$base_url = apply_filters( 'addon_filtros_hbook_booking_page_url', $permalink, $post_id );
		return add_query_arg( 'reserva_directa', $post_id, $base_url );
	}

	/**
	 * Endpoint AJAX: wp_ajax_addon_filtros_hbook_filter / wp_ajax_nopriv_addon_filtros_hbook_filter.
	 */
	public static function ajax_filter() {
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

		$query = self::build_query( $selected_terms );

		wp_send_json_success(
			array(
				'html'  => self::render_cards( $query ),
				'count' => (int) $query->found_posts,
			)
		);
	}
}
