<?php
/**
 * Motor de renderizado y consultas del addon.
 *
 * Se encarga de:
 * - Renderizar el shortcode [addon_filtros_hbook] (panel de filtros + grid de resultados).
 * - Construir el WP_Query combinable a partir de los términos de taxonomía seleccionados.
 * - Atender el endpoint AJAX que recalcula el grid sin recargar la página.
 * - Incrustar, por tarjeta, el propio formulario de reserva de HBook
 *   ([hb_booking_form accom_id="ID"]) tal y como hace el shortcode nativo
 *   [hb_accommodation_list book_button="yes"] (ver accom-list-render.php
 *   de HBook), para no duplicar ni inventar un flujo de reserva paralelo.
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
	 * @param array $atts Atributos del shortcode. Soporta 'redirection_url'
	 *                     y 'thank_you_page_url', pasados tal cual al
	 *                     [hb_booking_form] embebido en cada tarjeta
	 *                     (mismos atributos que admite [hb_accommodation_list]).
	 * @return string HTML del wrapper #addon-filtros-wrapper.
	 */
	public static function render_shortcode( $atts = array() ) {
		if ( ! post_type_exists( ADDON_FILTROS_HBOOK_CPT ) ) {
			return '<p class="addon-filtros-hbook-error">' . esc_html__( 'El buscador de alojamientos no está disponible en este momento.', 'addon-filtros-hbook' ) . '</p>';
		}

		// Garantiza los assets aunque el shortcode se ejecute fuera de post_content (do_shortcode en plantillas).
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

		ob_start();
		?>
		<div
			id="addon-filtros-wrapper"
			class="addon-filtros-wrapper"
			data-redirection-url="<?php echo esc_attr( $atts['redirection_url'] ); ?>"
			data-thank-you-page-url="<?php echo esc_attr( $atts['thank_you_page_url'] ); ?>"
		>
			<?php echo self::render_filters_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo self::render_results_panel( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
	 * Bloque estructural derecho: grid de resultados renderizado en servidor
	 * con la consulta inicial (sin filtros aplicados).
	 *
	 * @param array $atts Atributos del shortcode (redirection_url, thank_you_page_url).
	 */
	private static function render_results_panel( array $atts ) {
		$query = self::build_query( array() );
		ob_start();
		?>
		<div class="addon-filtros-results">
			<div class="addon-filtros-grid" id="addon-filtros-grid" aria-busy="false">
				<?php echo self::render_cards( $query, $atts['redirection_url'], $atts['thank_you_page_url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
		<?php
		wp_reset_postdata();
		return ob_get_clean();
	}

	/**
	 * Construye el WP_Query combinable a partir de los términos seleccionados.
	 *
	 * Nota: hb_accommodation no gestiona duplicados multi-idioma vía
	 * Polylang/WPML aquí (a diferencia de HbDataBaseActions::get_all_accom_ids(),
	 * que es privada y no accesible desde fuera de HBook). En sitios que usen
	 * TranslatePress esto no supone diferencia (no duplica posts); en sitios
	 * con Polylang/WPML con posts duplicados por idioma, usa el filtro
	 * 'addon_filtros_hbook_query_args' para restringir por idioma si hace falta.
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
			'orderby'        => 'date',
			'order'          => 'ASC',
		);

		if ( count( $tax_query ) > 1 ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$args = apply_filters( 'addon_filtros_hbook_query_args', $args, $selected_terms );

		return new WP_Query( $args );
	}

	/**
	 * Renderiza el HTML de las tarjetas para un WP_Query ya ejecutado.
	 *
	 * @param WP_Query $query
	 * @param string   $redirection_url
	 * @param string   $thank_you_page_url
	 * @return string
	 */
	private static function render_cards( WP_Query $query, $redirection_url = '', $thank_you_page_url = '' ) {
		if ( ! $query->have_posts() ) {
			return '<p class="addon-filtros-empty">' . esc_html__( 'No se han encontrado alojamientos con esas características.', 'addon-filtros-hbook' ) . '</p>';
		}

		ob_start();
		while ( $query->have_posts() ) {
			$query->the_post();
			self::render_card( get_the_ID(), $redirection_url, $thank_you_page_url );
		}
		wp_reset_postdata();
		return ob_get_clean();
	}

	/**
	 * Renderiza una única tarjeta de alojamiento, incluyendo (oculto hasta que
	 * se pulse "Reservar") el formulario de reserva real de HBook, scopeado a
	 * ese alojamiento mediante [hb_booking_form accom_id="ID"] — el mismo
	 * mecanismo que usa el propio [hb_accommodation_list book_button="yes"]
	 * de HBook para evitar un segundo buscador redundante.
	 *
	 * @param int    $post_id
	 * @param string $redirection_url
	 * @param string $thank_you_page_url
	 */
	private static function render_card( $post_id, $redirection_url = '', $thank_you_page_url = '' ) {
		$hb_utils = addon_filtros_hbook_get_hbook_utils();

		if ( $hb_utils ) {
			$title        = $hb_utils->get_accom_title( $post_id );
			$permalink    = $hb_utils->get_accom_link( $post_id );
			$list_desc    = $hb_utils->get_accom_list_desc( $post_id );
			$thumb_markup = $hb_utils->get_thumb_mark_up( $post_id, 400, 300, 'addon-filtros-card-img' );
			$strings      = $hb_utils->get_strings();
			$book_label   = ! empty( $strings['accom_book_now_button'] ) ? $strings['accom_book_now_button'] : __( 'Reservar', 'addon-filtros-hbook' );
		} else {
			// HBook no debería estar inactivo llegados a este punto (post_type_exists ya lo comprueba),
			// pero se mantiene un fallback defensivo con funciones nativas de WP.
			$title        = get_the_title( $post_id );
			$permalink    = get_permalink( $post_id );
			$list_desc    = '';
			$thumb_markup = get_the_post_thumbnail( $post_id, 'medium_large', array( 'class' => 'addon-filtros-card-img', 'loading' => 'lazy' ) );
			$book_label   = __( 'Reservar', 'addon-filtros-hbook' );
		}

		$badges = self::get_card_badges( $post_id );

		$booking_shortcode = '[hb_booking_form accom_id="' . intval( $post_id ) . '"';
		if ( $redirection_url ) {
			$booking_shortcode .= ' redirection_url="' . esc_attr( $redirection_url ) . '"';
		} elseif ( $thank_you_page_url ) {
			$booking_shortcode .= ' thank_you_page_url="' . esc_attr( $thank_you_page_url ) . '"';
		}
		$booking_shortcode .= ']';
		?>
		<div class="addon-filtros-card" data-accommodation-id="<?php echo esc_attr( $post_id ); ?>">
			<div class="addon-filtros-card-media">
				<a href="<?php echo esc_url( $permalink ); ?>" tabindex="-1">
					<?php
					if ( $thumb_markup ) {
						echo $thumb_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
				<?php if ( $list_desc ) : ?>
					<div class="addon-filtros-card-desc"><?php echo wp_kses_post( $list_desc ); ?></div>
				<?php endif; ?>
				<button
					type="button"
					class="addon-filtros-book-btn"
					data-accommodation-id="<?php echo esc_attr( $post_id ); ?>"
					aria-expanded="false"
				>
					<?php echo esc_html( $book_label ); ?>
				</button>
				<div class="addon-filtros-booking-form">
					<?php echo do_shortcode( $booking_shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Recopila los nombres de términos, de todas las taxonomías registradas,
	 * de un alojamiento para mostrarlos como badges dentro de la tarjeta.
	 *
	 * @param int $post_id
	 * @return string[]
	 */
	private static function get_card_badges( $post_id ) {
		$badges = array();

		foreach ( addon_filtros_hbook_get_taxonomies() as $taxonomy ) {
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

		$redirection_url    = isset( $_POST['redirection_url'] ) ? esc_url_raw( wp_unslash( $_POST['redirection_url'] ) ) : '';
		$thank_you_page_url = isset( $_POST['thank_you_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['thank_you_page_url'] ) ) : '';

		$query = self::build_query( $selected_terms );

		wp_send_json_success(
			array(
				'html'  => self::render_cards( $query, $redirection_url, $thank_you_page_url ),
				'count' => (int) $query->found_posts,
			)
		);
	}
}
