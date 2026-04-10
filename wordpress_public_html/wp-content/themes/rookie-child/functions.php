<?php
add_action( 'wp_enqueue_scripts', 'rookie_child_enqueue_styles' );
function rookie_child_enqueue_styles() {
    wp_enqueue_style(
        'rookie-framework-style',
        get_template_directory_uri() . '/framework.css'
    );
    wp_enqueue_style(
        'rookie-parent-style',
        get_template_directory_uri() . '/style.css',
        array( 'rookie-framework-style' )
    );

    wp_enqueue_style(
        'cromo-cancha',
        get_stylesheet_directory_uri() . '/cromo-cancha.css',
        array( 'rookie-parent-style' ),
        '1.0'
    );
    }

add_filter( 'acf/load_value/name=cromo_slot_2', 'cromo_allow_iframe', 10, 3 );
function cromo_allow_iframe( $value, $post_id, $field ) {
    remove_filter( 'acf/load_value/name=cromo_slot_2', 'cromo_allow_iframe' );
    return $value;
}

add_filter( 'acf/update_value/name=cromo_slot_2', function( $value ) {
    return wp_kses( $value, array(
        'iframe' => array(
            'src'             => true,
            'width'           => true,
            'height'          => true,
            'frameborder'     => true,
            'allowfullscreen' => true,
            'loading'         => true,
            'referrerpolicy'  => true,
            'style'           => true,
        ),
    ));
});

add_shortcode( 'cromo_cancha', 'cromo_cancha_shortcode' );
function cromo_cancha_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'titulo'       => '',
        'direccion'    => '',
        'ciudad'       => '',
        'imagen'       => '',
        'slot2'        => '',
        'venue_id'     => '',
        'temporada_id' => '',
        'cantidad'     => '8',
        'maps_url'     => '',
        'mostrar_body'     => 'si',
        'mostrar_resultado'=> 'si',
        'mostrar_cancha'   => 'no',
        'estado'           => 'publish,future',
        'fecha'            => '',
    ), $atts );

    $venue_ids    = array_filter( array_map( 'intval', explode( ',', $atts['venue_id'] ) ) );
    $temporada_id = intval( $atts['temporada_id'] );

    // Partidos
    $lista = array();
    if ( ! empty( $venue_ids ) && $temporada_id ) {
        $query_args = array(
            'post_type'      => 'sp_event',
            'posts_per_page' => intval( $atts['cantidad'] ),
            'post_status'    => array_map( 'trim', explode( ',', $atts['estado'] ) ),
            'orderby'        => 'date',
            'order'          => 'ASC',
            'tax_query'      => array(
                'relation' => 'AND',
                array(
                    'taxonomy' => 'sp_venue',
                    'field'    => 'term_id',
                    'terms'    => $venue_ids,
                ),
                array(
                    'taxonomy' => 'sp_season',
                    'field'    => 'term_id',
                    'terms'    => $temporada_id,
                ),
            ),
        );

        // Filtro por fecha opcional: acepta DD/MM/YYYY, YYYY-MM-DD o "proximo_sabado"
        if ( ! empty( $atts['fecha'] ) ) {
            $fecha_raw = trim( $atts['fecha'] );

            if ( $fecha_raw === 'proximo_sabado' ) {
                // Calcula el próximo sábado. Si hoy ES sábado, calcula el siguiente.
                // date('N'): 1=Lunes ... 6=Sábado ... 7=Domingo
                $dias_hasta_sabado = ( 6 - (int) current_time( 'N' ) + 7 ) % 7;
                if ( $dias_hasta_sabado === 0 ) {
                    $dias_hasta_sabado = 7; // Hoy es sábado → mostrar el próximo
                }
                $timestamp = strtotime( "+{$dias_hasta_sabado} days", current_time( 'timestamp' ) );
                $anio = (int) date( 'Y', $timestamp );
                $mes  = (int) date( 'm', $timestamp );
                $dia  = (int) date( 'd', $timestamp );
            } elseif ( preg_match( '/^(\d{2})\/(\d{2})\/(\d{4})$/', $fecha_raw, $m ) ) {
                $anio = (int) $m[3]; $mes = (int) $m[2]; $dia = (int) $m[1];
            } elseif ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $fecha_raw, $m ) ) {
                $anio = (int) $m[1]; $mes = (int) $m[2]; $dia = (int) $m[3];
            } else {
                $anio = $mes = $dia = null;
            }

            if ( ! empty( $anio ) && ! empty( $mes ) && ! empty( $dia ) ) {
                $query_args['date_query'] = array(
                    array(
                        'year'  => $anio,
                        'month' => $mes,
                        'day'   => $dia,
                    ),
                );
            }
        }

        $query = new WP_Query( $query_args );

        while ( $query->have_posts() ) :
            $query->the_post();

            $results   = get_post_meta( get_the_ID(), 'sp_results', true );
            $resultado = '—';
            if ( is_array( $results ) ) {
                $goals = array();
                foreach ( $results as $team_data ) {
                    if ( isset( $team_data['goals'] ) && $team_data['goals'] !== '' ) {
                        $goals[] = $team_data['goals'];
                    }
                }
                if ( count( $goals ) === 2 ) {
                    $resultado = $goals[0] . ' - ' . $goals[1];
                }
            }

            $ligas        = get_the_terms( get_the_ID(), 'sp_league' );
            $liga_nombre  = ( $ligas && ! is_wp_error( $ligas ) ) ? $ligas[0]->name : '';

            $venues_partido = get_the_terms( get_the_ID(), 'sp_venue' );
            $cancha_nombre  = ( $venues_partido && ! is_wp_error( $venues_partido ) ) ? $venues_partido[0]->name : '';

            $specs      = get_post_meta( get_the_ID(), 'sp_specs', true );
            $mesa_nombre = ( is_array( $specs ) && ! empty( $specs['mesa'] ) ) ? $specs['mesa'] : '';

            $lista[] = array(
                'fecha'     => get_the_date( 'd/m/Y' ),
                'hora'      => get_the_time( 'H:i' ),
                'liga'      => $liga_nombre,
                'titulo'    => get_the_title(),
                'resultado' => $resultado,
                'cancha'    => $cancha_nombre,
                'mesa'      => $mesa_nombre,
            );
        endwhile;
        wp_reset_postdata();
    }

    $mitad    = ceil( count( $lista ) / 2 );
    $columna1 = array_slice( $lista, 0, $mitad );
    $columna2 = array_slice( $lista, $mitad );

    ob_start(); ?>

    <div class="cromo-wrap">
      <div class="cromo">

        <div class="cromo-header">
          <div class="header-title"><?php echo esc_html( $atts['titulo'] ); ?></div>
          <div class="header-divider"></div>
          <div class="header-right">
            <div class="header-subtitle">
              <?php if ( $atts['maps_url'] ) : ?>
                <a href="<?php echo esc_url( $atts['maps_url'] ); ?>" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:none;border-bottom:1px solid rgba(255,255,255,0.4);"><?php echo esc_html( $atts['direccion'] ); ?></a>
              <?php else : ?>
                <?php echo esc_html( $atts['direccion'] ); ?>
              <?php endif; ?>
            </div>
            <div class="header-address"><?php echo esc_html( $atts['ciudad'] ); ?></div>
          </div>
        </div>
        <?php if ( $atts['mostrar_body'] === 'si' ) : ?>
        <div class="cromo-body">
          <div class="cromo-slot">
            <?php if ( $atts['imagen'] ) : ?>
              <img src="<?php echo esc_url( wp_get_attachment_url( intval( $atts['imagen'] ) ) ); ?>" alt="">
            <?php else : ?>
              <div class="slot-inner"><div class="slot-label">Insertar imagen</div></div>
            <?php endif; ?>
          </div>
            <div class="cromo-slot">
              <?php if ( $atts['maps_url'] ) : ?>
                <iframe
                  src="<?php echo esc_url( $atts['maps_url'] ); ?>"
                  width="100%"
                  height="100%"
                  style="border:0;position:absolute;top:0;left:0;width:100%;height:100%;"
                  allowfullscreen=""
                  loading="lazy"
                  referrerpolicy="no-referrer-when-downgrade">
                </iframe>
              <?php elseif ( $atts['slot2'] ) : ?>
                <div class="slot-inner"><?php echo $atts['slot2']; ?></div>
              <?php else : ?>
                <div class="slot-inner"><div class="slot-label">Insertar contenido</div></div>
              <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="cromo-footer">
          <div class="footer-grid">
            <?php foreach ( array( $columna1, $columna2 ) as $columna ) : ?>
              <div>
                <?php foreach ( $columna as $partido ) : ?>
                  <div class="match-row">
                    <span class="match-date">
                      <?php echo esc_html( $partido['fecha'] ); ?>
                      <br><small><?php echo esc_html( $partido['hora'] ); ?></small>
                    </span>
                    <span class="match-teams">
                      <?php echo esc_html( $partido['titulo'] ); ?>
                      <br><small><?php echo esc_html( $partido['liga'] ); ?></small>
                    </span>
                    <span class="match-dots"></span>
                    <?php if ( $atts['mostrar_resultado'] === 'si' ) : ?>
                    <span class="match-result"><?php echo esc_html( $partido['resultado'] ); ?></span>
                    <?php endif; ?>
                    <?php if ( $atts['mostrar_cancha'] === 'si' ) : ?>
                    <span class="match-result">
                      <?php echo esc_html( $partido['cancha'] ); ?>
                      <?php if ( ! empty( $partido['mesa'] ) ) : ?>
                        <br><small>Mesa: <?php echo esc_html( $partido['mesa'] ); ?></small>
                      <?php endif; ?>
                    </span>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

      </div>
    </div>

    <?php
    return ob_get_clean();
}

// ============================================================
// ADPLUGG
// ============================================================
add_action( 'rookie_before_template', 'adplugg_header_zone' );
function adplugg_header_zone() { ?>
<div class="adplugg-tag" data-adplugg-zone="header"></div>
<?php }

add_action( 'wp_head', 'adplugg_script' );
function adplugg_script() { ?>
<script>
    (function(ac) {
      var d = document, s = 'script', id = 'adplugg-adjs';
      var js, fjs = d.getElementsByTagName(s)[0];
      if (d.getElementById(id)) return;
      js = d.createElement(s); js.id = id; js.async = 1;
      js.src = '//www.adplugg.com/serve/' + ac + '/js/1.1/ad.js';
      fjs.parentNode.insertBefore(js, fjs);
    }('A48227134'));
</script>
<?php }

