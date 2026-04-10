<?php
/**
 * Template Name: Cromo Cancha
 */
add_action( 'wp_head', function() { ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Barlow+Condensed:wght@400;600;700;900&family=Barlow:wght@300;400;500&display=swap" rel="stylesheet">
<?php });
get_header(); ?>

<div class="cromo-wrap">
  <div class="cromo">

    <!-- CABECERA -->
    <div class="cromo-header">
      <div class="header-title"><?php the_title(); ?></div>
      <div class="header-divider"></div>
      <div class="header-right">
        <div class="header-subtitle"><?php echo get_post_meta( get_the_ID(), 'cromo_direccion', true ); ?></div>
        <div class="header-address"><?php echo get_post_meta( get_the_ID(), 'cromo_ciudad', true ); ?></div>
      </div>
    </div>

    <!-- CUERPO: DOS IMÁGENES -->
    <div class="cromo-body">
      <div class="cromo-slot">
        <?php $img1_id = get_field( 'cromo_imagen' ); ?>
        <?php if ( $img1_id ) : ?>
          <img src="<?php echo esc_url( wp_get_attachment_url( $img1_id ) ); ?>" alt="">
        <?php else : ?>
          <div class="slot-inner">
            <div class="slot-label">Insertar imagen</div>
          </div>
        <?php endif; ?>
      </div>
      <div class="cromo-slot">
        <?php $slot2 = get_field( 'cromo_slot_2' ); ?>
        <?php if ( $slot2 ) : ?>
          <div class="slot-inner"><?php echo $slot2; ?></div>
        <?php else : ?>
          <div class="slot-inner">
            <div class="slot-label">Insertar contenido</div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- PIE: PARTIDOS -->
    <div class="cromo-footer">
      <div class="footer-grid">
        <?php
        $venue_id     = get_field( 'cromo_venue_id' );
        $temporada_id = get_field( 'cromo_temporada_id' );

        if ( $venue_id ) :
            $partidos = new WP_Query( array(
                'post_type'      => 'sp_event',
                'posts_per_page' => 8,
                'post_status'    => array( 'publish', 'future' ),
                'orderby'        => 'date',
                'order'          => 'DESC',
                'tax_query'      => array(
                    'relation' => 'AND',
                    array(
                        'taxonomy' => 'sp_venue',
                        'field'    => 'term_id',
                        'terms'    => array_map( 'intval', (array) $venue_id ),
                    ),
                    array(
                        'taxonomy' => 'sp_season',
                        'field'    => 'term_id',
                        'terms'    => intval( $temporada_id ),
                    ),
                ),
            ) );

            $lista = array();
            while ( $partidos->have_posts() ) :
                $partidos->the_post();

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

                $lista[] = array(
                    'fecha'     => get_the_date( 'd/m/y' ),
                    'hora'      => get_the_time( 'H:i' ),
                    'titulo'    => get_the_title(),
                    'resultado' => $resultado,
                );
            endwhile;
            wp_reset_postdata();

            // Dividir en dos columnas
            $mitad    = ceil( count( $lista ) / 2 );
            $columna1 = array_slice( $lista, 0, $mitad );
            $columna2 = array_slice( $lista, $mitad );

            foreach ( array( $columna1, $columna2 ) as $columna ) :
                echo '<div>';
                foreach ( $columna as $partido ) :
                    echo '<div class="match-row">';
                    echo '<span class="match-date">' . esc_html( $partido['fecha'] ) . '<br><small>' . esc_html( $partido['hora'] ) . '</small></span>';
                    echo '<span class="match-teams">' . esc_html( $partido['titulo'] ) . '</span>';
                    echo '<span class="match-dots"></span>';
                    echo '<span class="match-result">' . esc_html( $partido['resultado'] ) . '</span>';
                    echo '</div>';
                endforeach;
                echo '</div>';
            endforeach;

        endif;
        ?>
      </div>
    </div>

  </div>
</div>

<?php get_footer(); ?>
